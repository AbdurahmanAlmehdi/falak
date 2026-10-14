package netobs

import (
	"net/netip"
	"path/filepath"
	"sort"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	commonpb "go.opentelemetry.io/proto/otlp/common/v1"
	metricspb "go.opentelemetry.io/proto/otlp/metrics/v1"
	resourcepb "go.opentelemetry.io/proto/otlp/resource/v1"
)

func filepathGlob(fs hostfs.FS, pattern string) ([]string, error) {
	return filepath.Glob(fs.P(pattern))
}

func str(k, v string) *commonpb.KeyValue {
	return &commonpb.KeyValue{Key: k, Value: &commonpb.AnyValue{Value: &commonpb.AnyValue_StringValue{StringValue: v}}}
}

func with(attrs []*commonpb.KeyValue, kv ...*commonpb.KeyValue) []*commonpb.KeyValue {
	return append(append(make([]*commonpb.KeyValue, 0, len(attrs)+len(kv)), attrs...), kv...)
}

func workloadAttrs(kind, name string, extra ...*commonpb.KeyValue) []*commonpb.KeyValue {
	return append([]*commonpb.KeyValue{str("falak.workload.kind", kind), str("falak.workload.name", name)}, extra...)
}

func gauge(name, unit string, pts ...*metricspb.NumberDataPoint) *metricspb.Metric {
	return &metricspb.Metric{Name: name, Unit: unit, Data: &metricspb.Metric_Gauge{Gauge: &metricspb.Gauge{DataPoints: pts}}}
}

func counter(name, unit string, pts ...*metricspb.NumberDataPoint) *metricspb.Metric {
	return &metricspb.Metric{Name: name, Unit: unit, Data: &metricspb.Metric_Sum{Sum: &metricspb.Sum{
		DataPoints: pts, IsMonotonic: true, AggregationTemporality: metricspb.AggregationTemporality_AGGREGATION_TEMPORALITY_CUMULATIVE}}}
}

// series collects one resource's data points per metric.
type series struct {
	res    []*commonpb.KeyValue
	points map[string][]*metricspb.NumberDataPoint
}

// encode turns the pass into ResourceMetrics: one per owner (the host, a site, a database instance), sorted.
func (c *Collector) encode(wls []*workload, usage ConntrackUsage, haveCT bool, report *Report, now time.Time) []*metricspb.ResourceMetrics {
	ts := uint64(now.UnixNano())
	in := func(v int64, attrs []*commonpb.KeyValue) *metricspb.NumberDataPoint {
		return &metricspb.NumberDataPoint{TimeUnixNano: ts, Value: &metricspb.NumberDataPoint_AsInt{AsInt: v}, Attributes: attrs}
	}
	dbl := func(v float64, attrs []*commonpb.KeyValue) *metricspb.NumberDataPoint {
		return &metricspb.NumberDataPoint{TimeUnixNano: ts, Value: &metricspb.NumberDataPoint_AsDouble{AsDouble: v}, Attributes: attrs}
	}
	all := map[string]*series{}
	add := func(owner string, res []*commonpb.KeyValue, metric string, p *metricspb.NumberDataPoint) {
		s := all[owner]
		if s == nil {
			s = &series{res: res, points: map[string][]*metricspb.NumberDataPoint{}}
			all[owner] = s
		}
		s.points[metric] = append(s.points[metric], p)
	}
	hostRes := []*commonpb.KeyValue{str("service.name", hostService)}
	for _, w := range wls {
		for _, st := range states {
			for _, dir := range []string{dirIn, dirOut} {
				add(w.owner, w.res, "falak.net.connections", in(int64(w.directional(st, dir)), with(w.attrs, str("falak.net.state", st), str("falak.net.direction", dir))))
			}
		}
		add(w.owner, w.res, "falak.net.connections.long_lived", in(int64(w.longLived), w.attrs))
		keys := make([][2]string, 0, len(w.exposure))
		for k := range w.exposure {
			keys = append(keys, k)
		}
		sort.Slice(keys, func(i, j int) bool { return keys[i][0]+keys[i][1] < keys[j][0]+keys[j][1] })
		for _, k := range keys {
			add(w.owner, w.res, "falak.net.listeners", in(int64(w.exposure[k]), with(w.attrs, str("network.transport", k[0]), str("falak.net.exposure", k[1]))))
		}
		if report == nil {
			continue
		}
		conns := map[[2]string]int{}
		for _, p := range w.rep.Peers {
			label := p.Name
			if label == "" {
				a, _ := netip.ParseAddr(p.Address)
				label = bucket(p.Direction, a, uint16(p.Port))
			}
			conns[[2]string{label, p.Direction}] += p.Connections
		}
		for dir, o := range w.rep.Other {
			conns[[2]string{otherPeer, dir}] += o.Connections
		}
		labels := make([][2]string, 0, len(conns))
		for k := range conns {
			labels = append(labels, k)
		}
		sort.Slice(labels, func(i, j int) bool { return labels[i][0]+labels[i][1] < labels[j][0]+labels[j][1] })
		for _, k := range labels {
			add(w.owner, w.res, "falak.net.peer.connections", in(int64(conns[k]), with(w.attrs, str("falak.net.peer", k[0]), str("falak.net.direction", k[1]))))
		}
	}
	if report != nil {
		c.mu.Lock()
		keys := sortedKeys(c.peerIO)
		resOf := map[string][]*commonpb.KeyValue{}
		for _, w := range wls {
			resOf[w.owner] = w.res
		}
		for _, k := range keys {
			pc := c.peerIO[k]
			res := resOf[pc.owner]
			if res == nil {
				continue // the workload is gone this pass
			}
			add(pc.owner, res, "falak.net.peer.io", in(int64(pc.rx), with(pc.attrs, str("network.io.direction", "receive"))))
			add(pc.owner, res, "falak.net.peer.io", in(int64(pc.tx), with(pc.attrs, str("network.io.direction", "transmit"))))
		}
		c.mu.Unlock()
		for _, p := range report.Probes {
			attrs := []*commonpb.KeyValue{str("falak.net.probe.target", p.Name), str("falak.net.probe.kind", p.Kind)}
			up := int64(0)
			if p.OK {
				up = 1
			}
			add("host", hostRes, "falak.net.probe.up", in(up, attrs))
			if p.ConnectMS != nil {
				add("host", hostRes, "falak.net.probe.connect_time", dbl(*p.ConnectMS/1000, attrs))
			}
		}
		for _, r := range report.WireGuard {
			attrs := []*commonpb.KeyValue{str("falak.net.wireguard.peer", wgPeerLabel(r)), str("network.interface.name", r.Interface)}
			if r.HandshakeAgeS != nil {
				add("host", hostRes, "falak.net.wireguard.handshake.age", in(*r.HandshakeAgeS, attrs))
			}
			add("host", hostRes, "falak.net.wireguard.io", in(int64(r.RxBytes), with(attrs, str("network.io.direction", "receive"))))
			add("host", hostRes, "falak.net.wireguard.io", in(int64(r.TxBytes), with(attrs, str("network.io.direction", "transmit"))))
		}
	}
	if haveCT {
		add("host", hostRes, "falak.net.conntrack.entries", in(usage.Entries, nil))
		add("host", hostRes, "falak.net.conntrack.limit", in(usage.Limit, nil))
	}
	type def struct {
		name, unit string
		sum        bool
	}
	defs := []def{
		{"falak.net.connections", "{connection}", false},
		{"falak.net.connections.long_lived", "{connection}", false},
		{"falak.net.listeners", "{socket}", false},
		{"falak.net.peer.connections", "{connection}", false},
		{"falak.net.peer.io", "By", true},
		{"falak.net.conntrack.entries", "{entry}", false},
		{"falak.net.conntrack.limit", "{entry}", false},
		{"falak.net.probe.connect_time", "s", false},
		// No unit: "1" would become falak_net_probe_up_ratio.
		{"falak.net.probe.up", "", false},
		{"falak.net.wireguard.handshake.age", "s", false},
		{"falak.net.wireguard.io", "By", true},
	}
	out := make([]*metricspb.ResourceMetrics, 0, len(all))
	for _, owner := range sortedKeys(all) {
		s := all[owner]
		var ms []*metricspb.Metric
		for _, d := range defs {
			pts := s.points[d.name]
			if len(pts) == 0 {
				continue
			}
			if d.sum {
				ms = append(ms, counter(d.name, d.unit, pts...))
			} else {
				ms = append(ms, gauge(d.name, d.unit, pts...))
			}
		}
		out = append(out, &metricspb.ResourceMetrics{
			Resource:     &resourcepb.Resource{Attributes: s.res},
			ScopeMetrics: []*metricspb.ScopeMetrics{{Scope: &commonpb.InstrumentationScope{Name: scope}, Metrics: ms}},
		})
	}
	return out
}
