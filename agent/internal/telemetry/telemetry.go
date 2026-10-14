// Package telemetry wires the OTLP relay, Insights tee, host metrics and log shipping together and
// implements the telemetry.configure command.
package telemetry

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"log/slog"
	"path/filepath"
	"regexp"
	"slices"
	"strings"
	"sync"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/docker"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/httpstats"
	"github.com/OthmanHaba/falak/agent/internal/insights"
	"github.com/OthmanHaba/falak/agent/internal/logs"
	"github.com/OthmanHaba/falak/agent/internal/metrics"
	"github.com/OthmanHaba/falak/agent/internal/obs"
	"github.com/OthmanHaba/falak/agent/internal/otlp"
)

// Payload mirrors contracts/agent-protocol/commands/telemetry.configure.schema.json.
type Payload struct {
	Endpoint       string            `json:"endpoint,omitempty"`
	Headers        map[string]string `json:"headers,omitempty"`
	Resource       *Resource         `json:"resource,omitempty"`
	Sampling       *Sampling         `json:"sampling,omitempty"`
	Sites          []otlp.Site       `json:"sites,omitempty"`
	LogSources     []logs.Source     `json:"log_sources,omitempty"`
	DockerLogs     *DockerLogs       `json:"docker_logs,omitempty"`
	Metrics        *MetricsCfg       `json:"metrics,omitempty"`
	Insights       *Toggle           `json:"insights,omitempty"`
	BufferMaxBytes *int64            `json:"buffer_max_bytes,omitempty"`
}

// Resource attributes set on every signal.
type Resource struct {
	OrgID       string `json:"org_id,omitempty"`
	ServerID    string `json:"server_id,omitempty"`
	Environment string `json:"environment,omitempty"`
}

// Sampling configuration.
type Sampling struct {
	TracesRatio *float64 `json:"traces_ratio,omitempty"`
}

// DockerLogs configuration.
type DockerLogs struct {
	Enabled       *bool             `json:"enabled,omitempty"`
	LabelSelector map[string]string `json:"label_selector,omitempty"`
}

// MetricsCfg configures host metrics.
type MetricsCfg struct {
	Enabled   *bool `json:"enabled,omitempty"`
	IntervalS int   `json:"interval_s,omitempty"`
}

// Toggle is an {enabled} object.
type Toggle struct {
	Enabled *bool `json:"enabled,omitempty"`
}

// Result of telemetry.configure.
type Result struct {
	Changed bool `json:"changed"`
}

// Options configure the Service. UnixSocket, HTTPAddr and DockerSocket are literal paths/addresses;
// EtcDir and StateDir are host paths resolved through FS.
type Options struct {
	FS           hostfs.FS
	EtcDir       string
	StateDir     string
	UnixSocket   string
	HTTPAddr     string
	Endpoint     string // OTLP endpoint from enrollment; telemetry.configure may override it
	ServerID     string
	HostName     string
	DockerSocket string
	SitesRoot    string // host directory of the native sites' trees: attributes a shared user's processes to sites
	Insights     interface {
		PostInsights(ctx context.Context, ndjson []byte) error
	}
	Logger *slog.Logger
	// Optional tuning (tests).
	FlushInterval time.Duration
}

// Service owns all telemetry subsystems.
type Service struct {
	opts      Options
	log       *slog.Logger
	relay     *otlp.Relay
	tee       *insights.Tee
	sampler   *metrics.Sampler
	collector *metrics.Collector
	tailer    *logs.Tailer
	docker    *logs.Docker
	workloads *metrics.WorkloadCollector
	followers []MetricsFollower
	http      *httpstats.Aggregator

	mu        sync.Mutex
	cur       Payload
	listeners otlp.Listeners
	loops     sync.WaitGroup // the loops Start launched
}

// New builds the service (nothing runs until Start).
func New(opts Options) (*Service, error) {
	if opts.Logger == nil {
		opts.Logger = slog.Default()
	}
	if opts.EtcDir == "" || opts.StateDir == "" {
		return nil, errors.New("telemetry: EtcDir and StateDir required")
	}
	s := &Service{opts: opts, log: opts.Logger}
	if opts.Insights != nil {
		s.tee = insights.New(opts.Insights, nil, opts.Logger)
	}
	ro := otlp.Options{BufferDir: opts.FS.P(filepath.Join(opts.StateDir, "otlp-buffer")), Logger: opts.Logger, FlushInterval: opts.FlushInterval}
	if s.tee != nil {
		ro.Observer = s.tee
	}
	relay, err := otlp.NewRelay(ro)
	if err != nil {
		return nil, err
	}
	s.relay = relay
	s.sampler = metrics.NewSampler(opts.FS)
	s.collector = metrics.NewCollector(s.sampler, relay)
	s.tailer = logs.NewTailer(opts.FS, opts.StateDir, relay, opts.Logger)
	// The edge's access records also become per-site HTTP metrics (falak.net.http.*, docs/plans/network/http.md).
	s.http = httpstats.New(relay)
	s.tailer.SetAccessObserver(s.http)
	// The socket may appear later (Docker installed by provisioning after the agent started): the log follower and
	// the workload collector's container pass check for it on every cycle. Without Docker the workload collector
	// still measures slices and native sites.
	var engine metrics.ContainerEngine
	if opts.DockerSocket != "" {
		s.docker = logs.NewDocker(opts.DockerSocket, relay, opts.Logger)
		client := docker.NewClient(opts.DockerSocket)
		engine = client
		// Container log sources: files inside a site's containers, read through /proc/<pid>/root.
		s.tailer.SetContainerEngine(client, opts.DockerSocket)
	}
	if s.tee != nil {
		// Laravel log entries with a stack trace become Insights exceptions (sites without the APM package).
		s.tailer.SetExceptionObserver(logExceptions{s})
	}
	s.workloads = metrics.NewWorkloadCollector(opts.FS, engine, relay)
	s.workloads.Socket = opts.DockerSocket
	s.workloads.SitesRoot = opts.SitesRoot
	// Persisted configuration from a previous telemetry.configure.
	if b, err := opts.FS.ReadFile(s.configPath()); err == nil {
		var p Payload
		if err := json.Unmarshal(b, &p); err != nil {
			s.log.Warn("ignoring corrupt telemetry.json", "err", err)
		} else {
			s.cur = p
		}
	} else if !errors.Is(err, fs.ErrNotExist) {
		return nil, err
	}
	s.apply(s.cur)
	return s, nil
}

func (s *Service) configPath() string { return filepath.Join(s.opts.EtcDir, "telemetry.json") }

// logExceptions hands the tailer's log-derived exceptions to the Insights tee, with the site's id for its slug (a
// site this server does not know of has no Issues to land in).
type logExceptions struct{ s *Service }

func (l logExceptions) ObserveLogException(e logs.LogException) {
	l.s.mu.Lock()
	siteID := ""
	for _, site := range l.s.cur.Sites {
		if site.Slug == e.Site {
			siteID = site.SiteID
			break
		}
	}
	l.s.mu.Unlock()
	if siteID == "" {
		return
	}
	l.s.tee.ObserveLogException(insights.LogException{SiteID: siteID, Type: e.Type, Message: e.Message,
		Stacktrace: e.Stacktrace, Name: e.File, At: e.At})
}

// Start launches the receiver and background loops; returns an error when a listener fails. The loops stop when ctx
// is cancelled; Wait returns once they have (some write state on the way out: log offsets, the OTLP disk buffer).
func (s *Service) Start(ctx context.Context) error {
	ls, err := s.relay.Serve(ctx, s.opts.UnixSocket, s.opts.HTTPAddr)
	if err != nil {
		return err
	}
	s.mu.Lock()
	s.listeners = ls
	s.mu.Unlock()
	run := func(f func(context.Context)) {
		s.loops.Add(1)
		go func() { defer s.loops.Done(); f(ctx) }()
	}
	run(s.relay.Run)
	run(s.collector.Run)
	run(s.tailer.Run)
	if s.docker != nil {
		run(s.docker.Run)
	}
	run(s.workloads.Run)
	run(s.http.Run)
	if s.tee != nil {
		run(s.tee.Run)
	}
	return nil
}

// Wait blocks until the loops Start launched have returned (after its ctx was cancelled).
func (s *Service) Wait() { s.loops.Wait() }

// Listeners reports the bound receiver addresses (after Start).
func (s *Service) Listeners() otlp.Listeners {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.listeners
}

// Sink returns the obs.Sink used by supervisor/cron to emit logs and spans.
func (s *Service) Sink() obs.Sink { return s.relay }

// Relay exposes the relay (stats / tests).
func (s *Service) Relay() *otlp.Relay { return s.relay }

// Workloads exposes the workload collector (its last pass feeds the network collector).
func (s *Service) Workloads() *metrics.WorkloadCollector { return s.workloads }

// MetricsFollower is a collector that follows the metrics settings (enabled, interval) of telemetry.configure.
type MetricsFollower interface {
	Configure(enabled bool, interval time.Duration)
}

// Follow makes f follow the metrics settings, starting with the current ones.
func (s *Service) Follow(f MetricsFollower) {
	s.mu.Lock()
	s.followers = append(s.followers, f)
	p := s.cur
	s.mu.Unlock()
	enabled, interval := metricsSettings(p)
	f.Configure(enabled, interval)
}

func metricsSettings(p Payload) (bool, time.Duration) {
	interval := 15 * time.Second
	enabled := true
	if p.Metrics != nil {
		enabled = boolOr(p.Metrics.Enabled, true)
		if p.Metrics.IntervalS > 0 {
			interval = time.Duration(p.Metrics.IntervalS) * time.Second
		}
	}
	return enabled, interval
}

// Summary returns the heartbeat metrics summary.
func (s *Service) Summary() metrics.Summary { return s.sampler.Summary() }

// SetEndpoint updates the enrollment endpoint (used when telemetry.configure has none).
func (s *Service) SetEndpoint(ep string) {
	s.mu.Lock()
	s.opts.Endpoint = ep
	p := s.cur
	s.mu.Unlock()
	s.apply(p)
}

// SetHostName updates host.name on every signal (the hostname changed, e.g. a cloud instance renamed after
// the agent started). Called with each facts collection; a no-op when unchanged.
func (s *Service) SetHostName(h string) {
	s.mu.Lock()
	if h == "" || h == s.opts.HostName {
		s.mu.Unlock()
		return
	}
	s.opts.HostName = h
	p := s.cur
	s.mu.Unlock()
	s.apply(p)
}

// Register adds telemetry.configure to the registry.
func (s *Service) Register(reg *commands.Registry) {
	reg.Register("telemetry.configure", commands.Typed(s.configure))
}

func (s *Service) configure(ctx context.Context, p Payload, _ commands.Stream) (any, error) {
	if err := validate(p); err != nil {
		return nil, &commands.PayloadError{Err: err}
	}
	b, err := json.MarshalIndent(p, "", "  ")
	if err != nil {
		return nil, err
	}
	b = append(b, '\n')
	cur, _ := s.opts.FS.ReadFile(s.configPath())
	changed := !bytes.Equal(cur, b)
	if changed {
		if err := s.opts.FS.MkdirAll(s.opts.EtcDir, 0o755); err != nil {
			return nil, err
		}
	}
	// 0600: headers may carry exporter credentials. WriteFile is a no-op when unchanged.
	if _, err := s.opts.FS.WriteFile(s.configPath(), b, 0o600); err != nil {
		return nil, err
	}
	s.mu.Lock()
	s.cur = p
	s.mu.Unlock()
	s.apply(p)
	return Result{Changed: changed}, nil
}

func validate(p Payload) error {
	if p.Sampling != nil && p.Sampling.TracesRatio != nil && (*p.Sampling.TracesRatio < 0 || *p.Sampling.TracesRatio > 1) {
		return fmt.Errorf("sampling.traces_ratio must be within [0,1]")
	}
	if p.Metrics != nil && p.Metrics.IntervalS != 0 && p.Metrics.IntervalS < 5 {
		return fmt.Errorf("metrics.interval_s must be ≥ 5")
	}
	for _, src := range p.LogSources {
		if !filepath.IsAbs(src.Path) {
			return fmt.Errorf("log source path %q must be absolute", src.Path)
		}
		if _, err := filepath.Match(src.Path, ""); err != nil {
			return fmt.Errorf("log source path %q: %w", src.Path, err)
		}
		if src.Format != "" && src.Format != "plain" && src.Format != "json" {
			return fmt.Errorf("log source format %q", src.Format)
		}
		if src.Kind != "" && src.Kind != "app" && src.Kind != "access" {
			return fmt.Errorf("log source kind %q", src.Kind)
		}
		if src.Multiline != "" && src.Multiline != "laravel" {
			return fmt.Errorf("log source multiline %q", src.Multiline)
		}
	}
	for _, site := range p.Sites {
		if site.Slug == "" || site.SiteID == "" {
			return fmt.Errorf("sites[] require slug and site_id")
		}
	}
	return nil
}

var (
	slugRE           = regexp.MustCompile(`^[a-z0-9][a-z0-9-]{0,62}$`)
	composeServiceRE = regexp.MustCompile(`^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}$`)
)

// validateSourceV2 checks the fields of feature logs.container_files: a container source belongs to a site and names
// a path inside its containers without "..", a multi-line pattern compiles. A source that fails is skipped (and
// logged) when the configuration is applied, never the whole payload: one site's pattern that RE2 reads differently
// from the control plane's check must not stop every other site's logs and the server's metrics settings.
func validateSourceV2(src logs.Source) error {
	switch src.Origin {
	case "", logs.OriginFile, logs.OriginPHPFPM, logs.OriginPHPFPMSlow:
	default:
		return fmt.Errorf("log source origin %q", src.Origin)
	}
	if src.MultilinePattern != "" {
		if len(src.MultilinePattern) > 256 {
			return fmt.Errorf("log source multiline_pattern is longer than 256 bytes")
		}
		if _, err := regexp.Compile(src.MultilinePattern); err != nil {
			return fmt.Errorf("log source multiline_pattern: %w", err)
		}
	}
	if src.ComposeService != "" && (!src.Container || !composeServiceRE.MatchString(src.ComposeService)) {
		return fmt.Errorf("log source compose_service %q needs container and a service name", src.ComposeService)
	}
	if !src.Container {
		return nil
	}
	if !slugRE.MatchString(src.Site) {
		return fmt.Errorf("container log source %q needs its site", src.Path)
	}
	if src.Kind == "access" || !strings.HasPrefix(src.Path, "/") || strings.ContainsRune(src.Path, 0) || slices.Contains(strings.Split(src.Path, "/"), "..") || logs.PseudoPath(src.Path) {
		return fmt.Errorf("container log source path %q", src.Path)
	}
	return nil
}

// usableSources leaves out the sources validateSourceV2 refuses.
func (s *Service) usableSources(srcs []logs.Source) []logs.Source {
	out := make([]logs.Source, 0, len(srcs)+1)
	for _, src := range srcs {
		if err := validateSourceV2(src); err != nil {
			s.log.Warn("log source skipped", "site", src.Site, "path", src.Path, "err", err)
			continue
		}
		out = append(out, src)
	}
	return out
}

func boolOr(b *bool, def bool) bool {
	if b == nil {
		return def
	}
	return *b
}

// apply hot-reloads every subsystem from p (defaults per the schema).
func (s *Service) apply(p Payload) {
	s.mu.Lock()
	endpoint, host := s.opts.Endpoint, s.opts.HostName
	s.mu.Unlock()
	cfg := otlp.Config{Endpoint: endpoint, Headers: p.Headers, ServerID: s.opts.ServerID, HostName: host,
		Environment: "production", Sites: p.Sites, TracesRatio: 1, BufferMax: otlp.DefaultBufferMax}
	if p.Endpoint != "" {
		cfg.Endpoint = p.Endpoint
	}
	if r := p.Resource; r != nil {
		cfg.OrgID = r.OrgID
		if r.ServerID != "" {
			cfg.ServerID = r.ServerID
		}
		if r.Environment != "" {
			cfg.Environment = r.Environment
		}
	}
	if p.Sampling != nil && p.Sampling.TracesRatio != nil {
		cfg.TracesRatio = *p.Sampling.TracesRatio
	}
	if p.BufferMaxBytes != nil && *p.BufferMaxBytes > 0 {
		cfg.BufferMax = *p.BufferMaxBytes
	}
	s.relay.Configure(cfg)

	enabled, interval := metricsSettings(p)
	s.collector.Configure(enabled, interval)
	s.mu.Lock()
	followers := append([]MetricsFollower(nil), s.followers...)
	s.mu.Unlock()
	for _, f := range followers {
		f.Configure(enabled, interval)
	}
	s.workloads.SetSites(p.Sites)
	s.workloads.Configure(enabled, interval)
	s.http.SetEnabled(enabled)
	// The edge's per-site access logs are always shipped (edge.caddy.apply writes them; the file name is the slug).
	s.tailer.SetSources(append(s.usableSources(p.LogSources), logs.AccessSource()))
	if s.docker != nil {
		on, labels := true, map[string]string(nil)
		if p.DockerLogs != nil {
			on = boolOr(p.DockerLogs.Enabled, true)
			labels = p.DockerLogs.LabelSelector
		}
		s.docker.Configure(on, labels)
	}
	if s.tee != nil {
		s.tee.SetEnabled(p.Insights == nil || boolOr(p.Insights.Enabled, true))
	}
}
