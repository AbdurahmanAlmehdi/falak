package db

import (
	"github.com/OthmanHaba/falak/agent/internal/version"
	commonpb "go.opentelemetry.io/proto/otlp/common/v1"
	metricspb "go.opentelemetry.io/proto/otlp/metrics/v1"
	resourcepb "go.opentelemetry.io/proto/otlp/resource/v1"
)

// point is one data point: its value and attributes.
type point struct {
	v     float64
	attrs [][2]string
}

// metricBuilder collects one pass's metrics, every point at the same time.
type metricBuilder struct {
	ts  uint64
	out []*metricspb.Metric
}

func (m *metricBuilder) points(pts []point) []*metricspb.NumberDataPoint {
	out := make([]*metricspb.NumberDataPoint, 0, len(pts))
	for _, p := range pts {
		dp := &metricspb.NumberDataPoint{TimeUnixNano: m.ts, Value: &metricspb.NumberDataPoint_AsDouble{AsDouble: p.v}}
		for _, a := range p.attrs {
			dp.Attributes = append(dp.Attributes, kv(a[0], a[1]))
		}
		out = append(out, dp)
	}
	return out
}

func (m *metricBuilder) gauge(name, unit string, v float64) { m.gaugeN(name, unit, point{v: v}) }

func (m *metricBuilder) gaugeN(name, unit string, pts ...point) {
	if len(pts) == 0 {
		return
	}
	m.out = append(m.out, &metricspb.Metric{Name: name, Unit: unit, Data: &metricspb.Metric_Gauge{Gauge: &metricspb.Gauge{DataPoints: m.points(pts)}}})
}

func (m *metricBuilder) counter(name, unit string, v float64) { m.counterN(name, unit, point{v: v}) }

// counterN is a cumulative monotonic sum: the engine's own counter since it started (Prometheus' rate() handles the
// resets of a restart).
func (m *metricBuilder) counterN(name, unit string, pts ...point) {
	if len(pts) == 0 {
		return
	}
	m.out = append(m.out, &metricspb.Metric{Name: name, Unit: unit, Data: &metricspb.Metric_Sum{Sum: &metricspb.Sum{
		DataPoints: m.points(pts), IsMonotonic: true, AggregationTemporality: metricspb.AggregationTemporality_AGGREGATION_TEMPORALITY_CUMULATIVE}}})
}

func kv(k, v string) *commonpb.KeyValue {
	return &commonpb.KeyValue{Key: k, Value: &commonpb.AnyValue{Value: &commonpb.AnyValue_StringValue{StringValue: v}}}
}

// resource is an instance's: service.name falak-db and the instance id in lowercase, as the phase 1 container metrics
// (the relay adds the server, organization and host).
func resource(id, engine string) *resourcepb.Resource {
	return &resourcepb.Resource{Attributes: []*commonpb.KeyValue{
		kv("service.name", "falak-db"), kv("falak.db.instance.id", id), kv("db.system.name", systemName(engine)),
	}}
}

func scope() *commonpb.InstrumentationScope {
	return &commonpb.InstrumentationScope{Name: databasesScope, Version: version.Version}
}
