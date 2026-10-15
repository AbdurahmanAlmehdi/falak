package ebpfobs

import (
	"math"
	"sort"
	"strings"
	"time"

	commonpb "go.opentelemetry.io/proto/otlp/common/v1"
	metricspb "go.opentelemetry.io/proto/otlp/metrics/v1"
	resourcepb "go.opentelemetry.io/proto/otlp/resource/v1"
)

// bounds are the exported histograms' bucket bounds in seconds: 2^k µs for k = 2..24 (4 µs … 16.8 s); the kernel's
// slot i ([2^i, 2^(i+1)) µs) lands in bucket max(0, i-1), the last ones in the overflow bucket.
var bounds = func() []float64 {
	out := make([]float64, 23)
	for i := range out {
		out[i] = math.Ldexp(1, i+2) / 1e6
	}
	return out
}()

func bucketOf(slot int) int { return min(max(0, slot-1), len(bounds)) }

func str(k, v string) *commonpb.KeyValue {
	return &commonpb.KeyValue{Key: k, Value: &commonpb.AnyValue{Value: &commonpb.AnyValue_StringValue{StringValue: v}}}
}

func with(attrs []*commonpb.KeyValue, kv ...*commonpb.KeyValue) []*commonpb.KeyValue {
	return append(append(make([]*commonpb.KeyValue, 0, len(attrs)+len(kv)), attrs...), kv...)
}

func workloadAttrs(kind, name string, extra ...*commonpb.KeyValue) []*commonpb.KeyValue {
	return append([]*commonpb.KeyValue{str("falak.workload.kind", kind), str("falak.workload.name", name)}, extra...)
}

// counter is a cumulative series: what the kernel counted since the agent started, per workload and labels.
type counter struct {
	wl, owner, name string
	res, attrs      []*commonpb.KeyValue
	v               float64
	pending         float64 // counted in the pass that created the series, shipped from the next one
	seen            time.Time
	labelled        bool // a peer or name label: dropped once idle, the others live as long as their workload
}

type histogram struct {
	wl, owner, name string
	res, attrs      []*commonpb.KeyValue
	buckets         [24]uint64
	sumS            float64
	count           uint64
	seen            time.Time
}

type gaugePoint struct {
	w     *workload
	name  string
	v     float64
	attrs []*commonpb.KeyValue
}

func seriesKey(w *workload, metric string, kv []*commonpb.KeyValue) string {
	var b strings.Builder
	b.WriteString(w.id + "|" + metric)
	for _, a := range kv {
		b.WriteString("|" + a.Key + "=" + a.GetValue().GetStringValue())
	}
	return b.String()
}

// add increases a counter. A series is created at the first non-zero increase and shipped at 0 in that pass, its
// value from the next one: a counter's first sample is a baseline to rate() and increase(), so a series appearing
// with its first events already counted would lose them.
func (c *Collector) add(w *workload, metric string, v float64, now time.Time, labelled bool, kv ...*commonpb.KeyValue) {
	k := seriesKey(w, metric, kv)
	s := c.counters[k]
	if s == nil {
		if v == 0 {
			return
		}
		s = &counter{wl: w.id, owner: w.owner, name: metric, res: w.res, attrs: with(w.attrs, kv...), labelled: labelled, pending: v, seen: now}
		c.counters[k] = s
		return
	}
	s.v += v
	if v > 0 {
		s.seen = now
	}
}

// flushPending adds what new series counted in the previous pass (they were shipped at 0 then).
func (c *Collector) flushPending() {
	for _, s := range c.counters {
		s.v, s.pending = s.v+s.pending, 0
	}
}

func (c *Collector) addHist(w *workload, metric string, d histVal, now time.Time) {
	k := seriesKey(w, metric, nil)
	h := c.hists[k]
	if h == nil {
		h = &histogram{wl: w.id, owner: w.owner, name: metric, res: w.res, attrs: w.attrs}
		c.hists[k] = h
	}
	for i, n := range d.Slots {
		h.buckets[bucketOf(i)] += n
	}
	h.sumS += float64(d.SumUS) / 1e6
	h.count += d.Count
	h.seen = now
}

// expire drops label series idle for seriesExpiry and the series of workloads gone that long.
func (c *Collector) expire(now time.Time, present map[string]bool) {
	for k, s := range c.counters {
		if !s.labelled && present[s.wl] {
			s.seen = now
		}
		if now.Sub(s.seen) > seriesExpiry {
			delete(c.counters, k)
		}
	}
	for k, h := range c.hists {
		if present[h.wl] {
			h.seen = now
		}
		if now.Sub(h.seen) > seriesExpiry {
			delete(c.hists, k)
		}
	}
}

type metricDef struct {
	name, unit string
	kind       int // 0 gauge, 1 counter, 2 histogram
}

var defs = []metricDef{
	{"falak.net.tcp.retransmits", "{segment}", 1},
	{"falak.net.tcp.resets", "{reset}", 1},
	{"falak.net.tcp.connects", "{connection}", 1},
	{"falak.net.tcp.peer.rtt", "s", 0},
	{"falak.net.tcp.connect.duration", "s", 2},
	{"falak.net.tcp.rtt", "s", 2},
	{"falak.net.dns.queries", "{query}", 1},
	{"falak.net.dns.failures", "{query}", 1},
	{"falak.net.dns.duration", "s", 2},
	{"falak.net.dns.name.queries", "{query}", 1},
	{"falak.workload.runqueue.delay", "s", 2},
	{"falak.ebpf.cpu.time", "s", 1},
	// No unit: "1" would become falak_ebpf_active_ratio.
	{"falak.ebpf.active", "", 0},
}

// encode turns every live series into ResourceMetrics, one per owner (host, site, database instance), sorted.
func (c *Collector) encode(gauges []gaugePoint, now time.Time) []*metricspb.ResourceMetrics {
	ts := uint64(now.UnixNano())
	type res struct {
		attrs []*commonpb.KeyValue
		num   map[string][]*metricspb.NumberDataPoint
		hist  map[string][]*metricspb.HistogramDataPoint
	}
	all := map[string]*res{}
	get := func(owner string, attrs []*commonpb.KeyValue) *res {
		r := all[owner]
		if r == nil {
			r = &res{attrs: attrs, num: map[string][]*metricspb.NumberDataPoint{}, hist: map[string][]*metricspb.HistogramDataPoint{}}
			all[owner] = r
		}
		return r
	}
	keys := make([]string, 0, len(c.counters))
	for k := range c.counters {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, k := range keys {
		s := c.counters[k]
		r := get(s.owner, s.res)
		r.num[s.name] = append(r.num[s.name], &metricspb.NumberDataPoint{TimeUnixNano: ts, Attributes: s.attrs,
			Value: &metricspb.NumberDataPoint_AsDouble{AsDouble: s.v}})
	}
	for _, g := range gauges {
		r := get(g.w.owner, g.w.res)
		r.num[g.name] = append(r.num[g.name], &metricspb.NumberDataPoint{TimeUnixNano: ts, Attributes: g.attrs,
			Value: &metricspb.NumberDataPoint_AsDouble{AsDouble: g.v}})
	}
	keys = keys[:0]
	for k := range c.hists {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, k := range keys {
		h := c.hists[k]
		r := get(h.owner, h.res)
		sum := h.sumS
		r.hist[h.name] = append(r.hist[h.name], &metricspb.HistogramDataPoint{TimeUnixNano: ts, Attributes: h.attrs, Count: h.count,
			Sum: &sum, BucketCounts: append([]uint64(nil), h.buckets[:]...), ExplicitBounds: bounds})
	}
	owners := make([]string, 0, len(all))
	for o := range all {
		owners = append(owners, o)
	}
	sort.Strings(owners)
	out := make([]*metricspb.ResourceMetrics, 0, len(all))
	for _, o := range owners {
		r := all[o]
		var ms []*metricspb.Metric
		for _, d := range defs {
			m := &metricspb.Metric{Name: d.name, Unit: d.unit}
			switch d.kind {
			case 0:
				if len(r.num[d.name]) == 0 {
					continue
				}
				m.Data = &metricspb.Metric_Gauge{Gauge: &metricspb.Gauge{DataPoints: r.num[d.name]}}
			case 1:
				if len(r.num[d.name]) == 0 {
					continue
				}
				m.Data = &metricspb.Metric_Sum{Sum: &metricspb.Sum{DataPoints: r.num[d.name], IsMonotonic: true,
					AggregationTemporality: metricspb.AggregationTemporality_AGGREGATION_TEMPORALITY_CUMULATIVE}}
			default:
				if len(r.hist[d.name]) == 0 {
					continue
				}
				m.Data = &metricspb.Metric_Histogram{Histogram: &metricspb.Histogram{DataPoints: r.hist[d.name],
					AggregationTemporality: metricspb.AggregationTemporality_AGGREGATION_TEMPORALITY_CUMULATIVE}}
			}
			ms = append(ms, m)
		}
		out = append(out, &metricspb.ResourceMetrics{Resource: &resourcepb.Resource{Attributes: r.attrs},
			ScopeMetrics: []*metricspb.ScopeMetrics{{Scope: &commonpb.InstrumentationScope{Name: scope}, Metrics: ms}}})
	}
	return out
}
