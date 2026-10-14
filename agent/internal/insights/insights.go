// Package insights implements the Insights tee: it inspects spans relayed by the agent and forwards
// compact summaries to the control plane (POST /agent/v1/insights, NDJSON) per the telemetry contract:
// one `exception` line per exception span event and per-minute `aggregate` lines per
// (site_id, event_type, name).
package insights

import (
	"bytes"
	"context"
	"encoding/hex"
	"encoding/json"
	"log/slog"
	"math"
	"math/rand/v2"
	"net/url"
	"regexp"
	"sort"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/otlp"
	"github.com/OthmanHaba/falak/agent/internal/transport"
	tracepb "go.opentelemetry.io/proto/otlp/trace/v1"
)

// Poster delivers NDJSON to the control plane (implemented by the transport client).
type Poster interface {
	PostInsights(ctx context.Context, ndjson []byte) error
}

// Exception is one `exception` line.
type Exception struct {
	Kind        string `json:"kind"`
	TraceID     string `json:"trace_id"`
	SpanID      string `json:"span_id"`
	SiteID      string `json:"site_id"`
	Type        string `json:"type"`
	Message     string `json:"message"`
	Stacktrace  string `json:"stacktrace"`
	Handled     bool   `json:"handled"`
	UserID      string `json:"user_id"`
	EventType   string `json:"event_type"`
	RouteOrName string `json:"route_or_name"`
	At          string `json:"at"`
}

// Aggregate is one per-minute `aggregate` line.
type Aggregate struct {
	Kind      string  `json:"kind"`
	SiteID    string  `json:"site_id"`
	EventType string  `json:"event_type"`
	Name      string  `json:"name"`
	Count     int64   `json:"count"`
	P50Ms     float64 `json:"p50_ms"`
	P95Ms     float64 `json:"p95_ms"`
	MaxMs     float64 `json:"max_ms"`
	Errors    int64   `json:"errors"`
	Minute    string  `json:"minute"`
}

// Limits bound memory use.
const (
	MaxKeysPerMinute = 5000  // distinct (site, type, name) per minute; overflow is counted and dropped
	MaxSamples       = 1024  // latency reservoir per key
	MaxPending       = 10000 // queued, unsent lines
	MaxStackBytes    = 16 << 10
	// Grace delays flushing a minute so late spans still land in it.
	Grace = 10 * time.Second
)

type aggKey struct {
	minute          int64 // unix seconds of minute start
	site, typ, name string
}

type agg struct {
	count, errors int64
	max           float64
	samples       []float64
}

// Tee aggregates spans and posts insight lines.
type Tee struct {
	poster Poster
	now    func() time.Time
	log    *slog.Logger

	enabled atomic.Bool

	mu       sync.Mutex
	aggs     map[aggKey]*agg
	keysPer  map[int64]int
	pending  [][]byte
	overflow int64
	dropped  int64

	// Log-derived exceptions (logexceptions.go): held until LogHold is over, dropped when the site's APM reported them.
	held         []heldLog
	apmExc       map[string][]apmException // site → its recent APM exceptions' keys
	logPerMinute map[string]int            // site → log-derived exceptions this minute
	logMinute    int64
	deduplicated int64
}

// New creates a tee. now may be nil (time.Now).
func New(p Poster, now func() time.Time, log *slog.Logger) *Tee {
	if now == nil {
		now = time.Now
	}
	if log == nil {
		log = slog.Default()
	}
	t := &Tee{poster: p, now: now, log: log, aggs: map[aggKey]*agg{}, keysPer: map[int64]int{}}
	t.enabled.Store(true)
	return t
}

// SetEnabled toggles the tee (telemetry.configure insights.enabled).
func (t *Tee) SetEnabled(on bool) { t.enabled.Store(on) }

// ObserveSpans implements otlp.SpanObserver.
func (t *Tee) ObserveSpans(rs []*tracepb.ResourceSpans) {
	if !t.enabled.Load() {
		return
	}
	// enduser.id is set on the root span; carry it to exceptions from child spans of the same trace.
	users := map[string]string{}
	for _, r := range rs {
		for _, ss := range r.GetScopeSpans() {
			for _, sp := range ss.GetSpans() {
				if u, ok := otlp.Lookup(sp.Attributes, "enduser.id"); ok && u != "" {
					users[string(sp.TraceId)] = u
				}
			}
		}
	}
	t.mu.Lock()
	defer t.mu.Unlock()
	for _, r := range rs {
		site, _ := otlp.Lookup(r.GetResource().GetAttributes(), "falak.site.id")
		for _, ss := range r.GetScopeSpans() {
			for _, sp := range ss.GetSpans() {
				t.observeLocked(site, sp, users[string(sp.TraceId)])
			}
		}
	}
}

func (t *Tee) observeLocked(site string, sp *tracepb.Span, user string) {
	typ, _ := otlp.Lookup(sp.Attributes, "falak.event.type")
	name := NameFor(typ, sp)
	isErr := sp.GetStatus().GetCode() == tracepb.Status_STATUS_CODE_ERROR
	var apmExc []apmException
	defer func() { t.observeAPMLocked(site, apmExc) }()
	for _, ev := range sp.GetEvents() {
		if ev.GetName() != "exception" {
			continue
		}
		isErr = true
		handled := sp.GetStatus().GetCode() != tracepb.Status_STATUS_CODE_ERROR
		if v := otlp.LookupValue(ev.Attributes, "falak.exception.handled"); v != nil {
			handled = otlp.AttrString(v) == "true"
		} else if v := otlp.LookupValue(sp.Attributes, "falak.exception.handled"); v != nil {
			handled = otlp.AttrString(v) == "true"
		}
		get := func(k string) string { s, _ := otlp.Lookup(ev.Attributes, k); return s }
		stack := get("exception.stacktrace")
		if len(stack) > MaxStackBytes {
			stack = stack[:MaxStackBytes]
		}
		at := ev.GetTimeUnixNano()
		if at == 0 {
			at = sp.GetEndTimeUnixNano()
		}
		apmExc = append(apmExc, apmException{key: ExceptionKey(get("exception.type"), stack), at: time.Unix(0, int64(at))})
		t.enqueueLocked(Exception{
			Kind: "exception", TraceID: hex.EncodeToString(sp.TraceId), SpanID: hex.EncodeToString(sp.SpanId),
			SiteID: site, Type: get("exception.type"), Message: get("exception.message"), Stacktrace: stack,
			Handled: handled, UserID: user, EventType: typ, RouteOrName: name,
			At: time.Unix(0, int64(at)).UTC().Format(time.RFC3339Nano),
		})
	}
	if typ == "" {
		return // aggregates cover APM spans only
	}
	switch typ {
	case "request", "outgoing_request":
		if s, ok := otlp.Lookup(sp.Attributes, "http.response.status_code"); ok && s >= "500" && len(s) == 3 {
			isErr = true
		}
	case "job":
		if s, _ := otlp.Lookup(sp.Attributes, "falak.job.status"); s == "failed" {
			isErr = true
		}
	case "scheduled_task":
		if s, _ := otlp.Lookup(sp.Attributes, "falak.schedule.status"); s == "failed" {
			isErr = true
		}
	}
	end := int64(sp.GetEndTimeUnixNano())
	if end == 0 {
		end = t.now().UnixNano()
	}
	minute := time.Unix(0, end).UTC().Truncate(time.Minute).Unix()
	k := aggKey{minute, site, typ, name}
	a, ok := t.aggs[k]
	if !ok {
		if t.keysPer[minute] >= MaxKeysPerMinute {
			t.overflow++
			return
		}
		t.keysPer[minute]++
		a = &agg{}
		t.aggs[k] = a
	}
	ms := float64(int64(sp.GetEndTimeUnixNano())-int64(sp.GetStartTimeUnixNano())) / 1e6
	if ms < 0 {
		ms = 0
	}
	a.count++
	if isErr {
		a.errors++
	}
	if ms > a.max {
		a.max = ms
	}
	if len(a.samples) < MaxSamples {
		a.samples = append(a.samples, ms)
	} else if j := rand.Int64N(a.count); j < MaxSamples { // reservoir sampling
		a.samples[j] = ms
	}
}

func (t *Tee) enqueueLocked(v any) {
	b, err := json.Marshal(v)
	if err != nil {
		return
	}
	t.pending = append(t.pending, b)
	if over := len(t.pending) - MaxPending; over > 0 {
		t.pending = t.pending[over:]
		t.dropped += int64(over)
	}
}

// closeMinutesLocked converts aggregates of completed minutes (end + Grace ≤ now) into lines.
func (t *Tee) closeMinutesLocked() {
	cutoff := t.now().Add(-Grace).UTC().Truncate(time.Minute).Unix() // minutes strictly before this are closed
	var keys []aggKey
	for k := range t.aggs {
		if k.minute < cutoff {
			keys = append(keys, k)
		}
	}
	sort.Slice(keys, func(i, j int) bool {
		a, b := keys[i], keys[j]
		if a.minute != b.minute {
			return a.minute < b.minute
		}
		return a.site+a.typ+a.name < b.site+b.typ+b.name
	})
	for _, k := range keys {
		a := t.aggs[k]
		sort.Float64s(a.samples)
		t.enqueueLocked(Aggregate{
			Kind: "aggregate", SiteID: k.site, EventType: k.typ, Name: k.name, Count: a.count,
			P50Ms: round(quantile(a.samples, 0.50)), P95Ms: round(quantile(a.samples, 0.95)), MaxMs: round(a.max),
			Errors: a.errors, Minute: time.Unix(k.minute, 0).UTC().Format(time.RFC3339),
		})
		delete(t.aggs, k)
		if t.keysPer[k.minute]--; t.keysPer[k.minute] <= 0 {
			delete(t.keysPer, k.minute)
		}
	}
}

// quantile uses nearest-rank on sorted samples.
func quantile(sorted []float64, q float64) float64 {
	if len(sorted) == 0 {
		return 0
	}
	idx := int(math.Ceil(q*float64(len(sorted)))) - 1
	if idx < 0 {
		idx = 0
	}
	return sorted[idx]
}

func round(v float64) float64 { return math.Round(v*1000) / 1000 }

// Flush closes completed minutes and posts all pending lines. On failure lines stay queued.
func (t *Tee) Flush(ctx context.Context) error {
	t.mu.Lock()
	t.releaseHeldLocked()
	t.closeMinutesLocked()
	lines := t.pending
	t.pending = nil
	t.mu.Unlock()
	if len(lines) == 0 {
		return nil
	}
	var buf bytes.Buffer
	for _, l := range lines {
		buf.Write(l)
		buf.WriteByte('\n')
	}
	if err := t.poster.PostInsights(ctx, buf.Bytes()); err != nil {
		t.mu.Lock()
		t.pending = append(lines, t.pending...)
		if over := len(t.pending) - MaxPending; over > 0 {
			t.pending = t.pending[over:]
			t.dropped += int64(over)
		}
		t.mu.Unlock()
		return err
	}
	return nil
}

// Run flushes every second (backing off on failures) until ctx is done, then flushes once more.
func (t *Tee) Run(ctx context.Context) {
	delay := time.Second
	for {
		select {
		case <-ctx.Done():
			fctx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
			_ = t.Flush(fctx)
			cancel()
			return
		case <-time.After(delay):
		}
		if err := t.Flush(ctx); err != nil {
			if transport.IsRevoked(err) {
				delay = transport.RevokedRetry // reported once by the client
			} else {
				t.log.Debug("insights post failed", "err", err)
				delay = min(delay*2, time.Minute)
			}
		} else {
			delay = time.Second
		}
	}
}

// Stats returns (pending lines, dropped lines, overflowed aggregate keys).
func (t *Tee) Stats() (pending int, dropped, overflow int64) {
	t.mu.Lock()
	defer t.mu.Unlock()
	return len(t.pending), t.dropped, t.overflow
}

// NameFor returns the aggregate/route name of a span per event type.
func NameFor(typ string, sp *tracepb.Span) string {
	get := func(k string) string { s, _ := otlp.Lookup(sp.Attributes, k); return s }
	pick := func(keys ...string) string {
		for _, k := range keys {
			if v := get(k); v != "" {
				return v
			}
		}
		return sp.GetName()
	}
	switch typ {
	case "request":
		n := pick("http.route", "url.path")
		if m := get("http.request.method"); m != "" && n != sp.GetName() {
			return m + " " + n
		}
		return n
	case "job":
		return pick("falak.job.class")
	case "query":
		if q := get("db.query.text"); q != "" {
			return QueryShape(q)
		}
		return sp.GetName()
	case "outgoing_request":
		if u, err := url.Parse(get("url.full")); err == nil && u.Host != "" {
			return strings.TrimSpace(get("http.request.method") + " " + u.Host)
		}
		return sp.GetName()
	case "command":
		return pick("falak.command.name")
	case "scheduled_task":
		return pick("falak.schedule.name")
	case "mail":
		return pick("falak.mail.class")
	case "notification":
		return pick("falak.notification.class")
	case "cache":
		return strings.TrimSpace(get("falak.cache.store") + " " + get("falak.cache.op"))
	}
	return sp.GetName()
}

var (
	reStr    = regexp.MustCompile(`'(?:[^'\\]|\\.|'')*'`)
	reNum    = regexp.MustCompile(`\b\d+(?:\.\d+)?\b`)
	reList   = regexp.MustCompile(`\(\s*\?(?:\s*,\s*\?)+\s*\)`)
	reSpace  = regexp.MustCompile(`\s+`)
	reValues = regexp.MustCompile(`(?i)(values\s*\(\?\))(\s*,\s*\(\?\))+`)
)

// QueryShape normalises SQL so identical statements with different literals group together.
func QueryShape(q string) string {
	s := reStr.ReplaceAllString(q, "?")
	s = reNum.ReplaceAllString(s, "?")
	s = reList.ReplaceAllString(s, "(?)")
	s = reValues.ReplaceAllString(s, "$1")
	s = strings.TrimSpace(reSpace.ReplaceAllString(s, " "))
	if len(s) > 512 {
		s = s[:512]
	}
	return s
}
