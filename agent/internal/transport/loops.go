package transport

import (
	"context"
	"crypto/sha256"
	"encoding/json"
	"log/slog"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
)

// Poller long-polls for commands and submits them to the dispatcher.
type Poller struct {
	Client interface {
		Poll(ctx context.Context, wait int) ([]commands.Envelope, error)
	}
	Submit func(commands.Envelope) bool
	Wait   int
	Log    *slog.Logger
}

// Run polls until ctx is done. Cancelling ctx stops accepting commands: an in-flight long-poll is abandoned and
// commands in a response that arrives after cancellation are not submitted.
func (p *Poller) Run(ctx context.Context) {
	bo := Backoff{Min: time.Second, Max: 60 * time.Second}
	log := p.Log
	if log == nil {
		log = slog.Default()
	}
	for ctx.Err() == nil {
		envs, err := p.Client.Poll(ctx, p.Wait)
		if err != nil {
			if ctx.Err() != nil {
				return
			}
			d := bo.Next()
			if IsRevoked(err) { // the client reports that one itself, rate-limited
				d = RevokedRetry
			} else {
				if ra := RetryAfterDuration(err); ra > d {
					d = ra
				}
				log.Warn("command poll failed", "err", err, "retry_in", d)
			}
			sleep(ctx, d)
			continue
		}
		bo.Reset()
		if ctx.Err() != nil {
			// Stopping: do not start commands this process may not finish. The control plane delivers them
			// again (or fails them) when the next process reports a new session.
			if len(envs) > 0 {
				log.Warn("shutting down; leaving received commands for redelivery", "count", len(envs))
			}
			return
		}
		for _, e := range envs {
			if e.ID == "" || e.Type == "" {
				log.Warn("ignoring malformed envelope", "id", e.ID, "type", e.Type)
				continue
			}
			p.Submit(e)
		}
	}
}

// Heartbeat mirrors heartbeat.schema.json.
type Heartbeat struct {
	At              time.Time  `json:"at"`
	UptimeS         int64      `json:"uptime_s"`
	Load            [3]float64 `json:"load"`
	CPUPercent      float64    `json:"cpu_percent"`
	MemoryUsedBytes int64      `json:"memory_used_bytes"`
	DiskUsedBytes   int64      `json:"disk_used_bytes"`
	RunningCommands []string   `json:"running_commands"`
	// Disks are the host's data filesystems (metrics.ReadDisks): per-mount alerts and fill forecasts.
	Disks any `json:"disks,omitempty"`
	// MissingSecrets are sites whose env file or container secret files are gone (a reboot emptied /run): the
	// control plane answers with site.env.write.
	MissingSecrets []string `json:"missing_secrets,omitempty"`
	// Databases are the database containers' states and health (db.Report); nil when there are none.
	Databases any `json:"databases,omitempty"`
	// ServiceEvents are OOM kills and restarts seen since the last delivered heartbeat (resources.Queue).
	ServiceEvents any `json:"service_events,omitempty"`
	Facts         any `json:"facts,omitempty"`
}

// Heartbeater posts heartbeats every Interval. Facts are included on the first beat and whenever
// they changed since the last successfully delivered heartbeat.
type Heartbeater struct {
	Client interface {
		Heartbeat(ctx context.Context, hb any) error
	}
	Interval   time.Duration
	Summary    func() Heartbeat // fills metrics fields
	Facts      func(ctx context.Context) (any, error)
	FactsEvery time.Duration // how often facts are re-collected (default 5m)
	Running    func() []string
	// ServiceEvents returns what to send in `service_events` (nil: nothing) and the func that drops it once
	// delivered; a failed beat keeps it for the next one.
	ServiceEvents func() (any, func())
	Log           *slog.Logger
	lastFactsID   [32]byte
	facts         any
	factsAt       time.Time
	// pausedUntil: after an agent_revoked answer, Run skips beats for RevokedRetry.
	pausedUntil time.Time
}

// Run beats until ctx is done.
func (h *Heartbeater) Run(ctx context.Context) {
	if h.Interval <= 0 {
		h.Interval = 15 * time.Second
	}
	if h.FactsEvery <= 0 {
		h.FactsEvery = 5 * time.Minute
	}
	h.Beat(ctx)
	t := time.NewTicker(h.Interval)
	defer t.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-t.C:
			if time.Now().Before(h.pausedUntil) {
				continue
			}
			h.Beat(ctx)
		}
	}
}

// Beat sends one heartbeat.
func (h *Heartbeater) Beat(ctx context.Context) {
	log := h.Log
	if log == nil {
		log = slog.Default()
	}
	var hb Heartbeat
	if h.Summary != nil {
		hb = h.Summary()
	}
	hb.At = time.Now().UTC()
	hb.RunningCommands = []string{}
	if h.Running != nil {
		hb.RunningCommands = h.Running()
	}
	var delivered func()
	if h.ServiceEvents != nil {
		hb.ServiceEvents, delivered = h.ServiceEvents()
	}
	var sum [32]byte
	if h.Facts != nil && (h.facts == nil || time.Since(h.factsAt) > h.FactsEvery) {
		if f, err := h.Facts(ctx); err == nil {
			h.facts, h.factsAt = f, time.Now()
		} else {
			log.Warn("collect facts", "err", err)
		}
	}
	if h.facts != nil {
		b, _ := json.Marshal(h.facts)
		sum = sha256.Sum256(b)
		if sum != h.lastFactsID {
			hb.Facts = h.facts
		}
	}
	if err := h.Client.Heartbeat(ctx, hb); err != nil {
		if IsRevoked(err) {
			h.pausedUntil = time.Now().Add(RevokedRetry)
		} else {
			log.Warn("heartbeat failed", "err", err)
		}
		return
	}
	if hb.Facts != nil {
		h.lastFactsID = sum
	}
	if hb.ServiceEvents != nil && delivered != nil {
		delivered()
	}
}

// Renewer checks the certificate daily and renews when < 30 days remain.
type Renewer struct {
	NeedsRenewal func(time.Time) bool
	Renew        func(ctx context.Context) error
	Every        time.Duration
	Log          *slog.Logger
}

// Run checks immediately, then every Every (default 6h); failures retry hourly.
func (r *Renewer) Run(ctx context.Context) {
	every := r.Every
	if every <= 0 {
		every = 6 * time.Hour
	}
	for {
		next := every
		if r.NeedsRenewal(time.Now()) {
			if err := r.Renew(ctx); err != nil {
				if !IsRevoked(err) {
					r.Log.Error("certificate renewal failed", "err", err)
				}
				next = time.Hour
			} else {
				r.Log.Info("certificate renewed")
			}
		}
		if !sleep(ctx, next) {
			return
		}
	}
}
