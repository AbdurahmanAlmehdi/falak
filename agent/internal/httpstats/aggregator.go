// Package httpstats aggregates the edge's access records into per-site HTTP metrics (falak.net.http.*,
// docs/plans/network/http.md): what the HTTP tab charts continuously. Drill-downs (paths, clients, user agents) stay
// in Loki, so every label here is a small fixed set.
package httpstats

import (
	"context"
	"math"
	"sort"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/logs"
	"github.com/OthmanHaba/falak/agent/internal/obs"
	"github.com/OthmanHaba/falak/agent/internal/otlp"
	commonpb "go.opentelemetry.io/proto/otlp/common/v1"
	metricspb "go.opentelemetry.io/proto/otlp/metrics/v1"
	resourcepb "go.opentelemetry.io/proto/otlp/resource/v1"
)

// ProbeAgent is the user agent prefix of the control plane's health checks: left out of every metric.
const ProbeAgent = "Falak-HealthCheck/"

// Interval is how often the metrics are emitted.
const Interval = 60 * time.Second

// IdleAfter drops a site's series once it served nothing for this long (a removed site stops reporting).
const IdleAfter = time.Hour

// Buckets are the upper bounds (ms) of falak.net.http.server.duration.
var Buckets = []float64{5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000}

var (
	statusClasses = []string{"1xx", "2xx", "3xx", "4xx", "5xx"}
	protocols     = []string{"1.0", "1.1", "2", "3", "other"}
	tlsVersions   = []string{"1.0", "1.1", "1.2", "1.3", "none", "other"}
)

// Emitter receives OTLP metrics (the relay).
type Emitter interface {
	EmitMetrics(*metricspb.ResourceMetrics)
}

type site struct {
	start     time.Time
	last      time.Time
	classes   map[string]int64
	rx, tx    int64
	buckets   []uint64 // len(Buckets)+1, the last is +Inf
	durSum    float64
	durCount  uint64
	protocols map[string]int64
	tls       map[string]int64
	threats   map[string]int64
}

// Aggregator counts access records per site (logs.AccessObserver) and emits cumulative counters.
type Aggregator struct {
	out Emitter
	now func() time.Time

	enabled atomic.Bool

	mu    sync.Mutex
	sites map[string]*site
}

// SetEnabled turns emission on or off (telemetry.configure metrics.enabled); counting goes on either way.
func (a *Aggregator) SetEnabled(on bool) { a.enabled.Store(on) }

// New returns an aggregator emitting to out.
func New(out Emitter) *Aggregator {
	a := &Aggregator{out: out, now: time.Now, sites: map[string]*site{}}
	a.enabled.Store(true)
	return a
}

// ObserveAccess counts one access record (logs.AccessObserver). Never blocks for long: a map update under a mutex.
func (a *Aggregator) ObserveAccess(rec obs.LogRecord) {
	attrs := rec.Attrs
	if rec.Site == "" || strings.HasPrefix(attrs["user_agent.original"], ProbeAgent) {
		return
	}
	status, err := strconv.Atoi(attrs["http.response.status_code"])
	if err != nil || status < 100 || status > 599 {
		return
	}
	a.mu.Lock()
	defer a.mu.Unlock()
	s := a.sites[rec.Site]
	if s == nil {
		s = &site{start: a.now(), classes: map[string]int64{}, buckets: make([]uint64, len(Buckets)+1),
			protocols: map[string]int64{}, tls: map[string]int64{}, threats: map[string]int64{}}
		a.sites[rec.Site] = s
	}
	s.last = a.now()
	s.classes[statusClasses[status/100-1]]++
	if n, err := strconv.ParseInt(attrs["http.request.body.size"], 10, 64); err == nil && n > 0 {
		s.rx += n
	}
	if n, err := strconv.ParseInt(attrs["http.response.body.size"], 10, 64); err == nil && n > 0 {
		s.tx += n
	}
	if ms, err := strconv.ParseFloat(attrs["http.server.duration_ms"], 64); err == nil && ms >= 0 && !math.IsInf(ms, 0) {
		i := sort.SearchFloat64s(Buckets, ms) // first bound >= ms: le semantics
		s.buckets[i]++
		s.durSum += ms
		s.durCount++
	}
	s.protocols[oneOf(attrs["network.protocol.version"], protocols, "other")]++
	tlsV := attrs["tls.protocol.version"]
	if tlsV == "" {
		tlsV = "none"
	}
	s.tls[oneOf(tlsV, tlsVersions, "other")]++
	if c := attrs["falak.threat.category"]; c != "" {
		s.threats[oneOf(c, logs.ThreatCategories, "")]++
	}
}

func oneOf(v string, set []string, fallback string) string {
	for _, s := range set {
		if s == v {
			return v
		}
	}
	return fallback
}

// Run emits every Interval until ctx is done.
func (a *Aggregator) Run(ctx context.Context) {
	t := time.NewTicker(Interval)
	defer t.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-t.C:
			if !a.enabled.Load() {
				continue
			}
			for _, rm := range a.Collect() {
				a.out.EmitMetrics(rm)
			}
		}
	}
}

// Collect returns one ResourceMetrics per site (sorted by slug) and forgets sites idle for IdleAfter.
func (a *Aggregator) Collect() []*metricspb.ResourceMetrics {
	a.mu.Lock()
	defer a.mu.Unlock()
	now := a.now()
	slugs := make([]string, 0, len(a.sites))
	for slug, s := range a.sites {
		if now.Sub(s.last) > IdleAfter {
			delete(a.sites, slug)
			continue
		}
		slugs = append(slugs, slug)
	}
	sort.Strings(slugs)
	out := make([]*metricspb.ResourceMetrics, 0, len(slugs))
	for _, slug := range slugs {
		out = append(out, a.encode(slug, a.sites[slug], now))
	}
	return out
}

func (a *Aggregator) encode(slug string, s *site, now time.Time) *metricspb.ResourceMetrics {
	start, ts := uint64(s.start.UnixNano()), uint64(now.UnixNano())
	point := func(v int64, kv ...*commonpb.KeyValue) *metricspb.NumberDataPoint {
		return &metricspb.NumberDataPoint{StartTimeUnixNano: start, TimeUnixNano: ts,
			Value: &metricspb.NumberDataPoint_AsInt{AsInt: v}, Attributes: kv}
	}
	byKey := func(m map[string]int64, attr string, order []string) []*metricspb.NumberDataPoint {
		var pts []*metricspb.NumberDataPoint
		for _, k := range order {
			if v, ok := m[k]; ok {
				pts = append(pts, point(v, otlp.Str(attr, k)))
			}
		}
		return pts
	}
	sum := s.durSum
	ms := []*metricspb.Metric{
		counter("falak.net.http.requests", "{request}", byKey(s.classes, "http.response.status_class", statusClasses)),
		counter("falak.net.http.io", "By", []*metricspb.NumberDataPoint{
			point(s.rx, otlp.Str("network.io.direction", "receive")),
			point(s.tx, otlp.Str("network.io.direction", "transmit")),
		}),
		{Name: "falak.net.http.server.duration", Unit: "ms", Data: &metricspb.Metric_Histogram{Histogram: &metricspb.Histogram{
			AggregationTemporality: metricspb.AggregationTemporality_AGGREGATION_TEMPORALITY_CUMULATIVE,
			DataPoints: []*metricspb.HistogramDataPoint{{StartTimeUnixNano: start, TimeUnixNano: ts, Count: s.durCount,
				Sum: &sum, BucketCounts: append([]uint64(nil), s.buckets...), ExplicitBounds: Buckets}},
		}}},
		counter("falak.net.http.protocol", "{request}", byKey(s.protocols, "network.protocol.version", protocols)),
		counter("falak.net.http.tls", "{request}", byKey(s.tls, "tls.protocol.version", tlsVersions)),
	}
	if len(s.threats) > 0 {
		ms = append(ms, counter("falak.net.http.threats", "{request}", byKey(s.threats, "falak.threat.category", logs.ThreatCategories)))
	}
	return &metricspb.ResourceMetrics{
		Resource:     &resourcepb.Resource{Attributes: []*commonpb.KeyValue{otlp.Str("service.name", slug)}},
		ScopeMetrics: []*metricspb.ScopeMetrics{{Scope: &commonpb.InstrumentationScope{Name: otlp.HTTPScope}, Metrics: ms}},
	}
}

func counter(name, unit string, pts []*metricspb.NumberDataPoint) *metricspb.Metric {
	return &metricspb.Metric{Name: name, Unit: unit, Data: &metricspb.Metric_Sum{Sum: &metricspb.Sum{
		DataPoints: pts, IsMonotonic: true, AggregationTemporality: metricspb.AggregationTemporality_AGGREGATION_TEMPORALITY_CUMULATIVE}}}
}
