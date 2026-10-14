package metrics

import (
	"context"
	"sync"
	"sync/atomic"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	commonpb "go.opentelemetry.io/proto/otlp/common/v1"
	metricspb "go.opentelemetry.io/proto/otlp/metrics/v1"
	resourcepb "go.opentelemetry.io/proto/otlp/resource/v1"
)

// Summary is the heartbeat metrics summary.
type Summary struct {
	UptimeS       int64
	Load          [3]float64
	CPUPercent    float64
	MemUsedBytes  int64
	DiskUsedBytes int64
	// Disks are the host's data filesystems (ReadDisks).
	Disks []DiskUsage
}

// Sampler reads /proc under a (re-rootable) host fs.
type Sampler struct {
	fs hostfs.FS

	mu      sync.Mutex
	prevCPU *CPUTimes // for Summary
}

// NewSampler creates a sampler.
func NewSampler(fs hostfs.FS) *Sampler { return &Sampler{fs: fs} }

// Summary samples cheap host metrics. CPUPercent is utilisation since the previous Summary call
// (since boot on the first call). Missing sources leave fields at zero.
func (s *Sampler) Summary() Summary {
	var out Summary
	if up, err := ReadUptime(s.fs); err == nil {
		out.UptimeS = int64(up)
	}
	out.Load, _ = ReadLoad(s.fs)
	if m, err := ReadMem(s.fs); err == nil {
		out.MemUsedBytes = m.Used()
	}
	if fsu, err := ReadFS(s.fs, "/"); err == nil {
		out.DiskUsedBytes = fsu.Used
	}
	out.Disks = ReadDisks(s.fs)
	if cur, err := ReadCPU(s.fs); err == nil {
		s.mu.Lock()
		prev := CPUTimes{}
		if s.prevCPU != nil {
			prev = *s.prevCPU
		}
		s.prevCPU = &cur
		s.mu.Unlock()
		out.CPUPercent = float64(int64(Utilization(prev, cur)*10000)) / 100
	}
	return out
}

// Emitter receives OTLP metrics (implemented by the otlp relay).
type Emitter interface {
	EmitMetrics(*metricspb.ResourceMetrics)
}

// Collector periodically converts /proc samples into OTLP metrics.
type Collector struct {
	s   *Sampler
	out Emitter
	now func() time.Time

	enabled  atomic.Bool
	interval atomic.Int64
	wake     chan struct{}

	mu      sync.Mutex
	prevCPU *CPUTimes
}

// NewCollector creates an enabled collector with a 15 s interval.
func NewCollector(s *Sampler, out Emitter) *Collector {
	c := &Collector{s: s, out: out, now: time.Now, wake: make(chan struct{}, 1)}
	c.enabled.Store(true)
	c.interval.Store(int64(15 * time.Second))
	return c
}

// Configure hot-reloads enablement and interval.
func (c *Collector) Configure(enabled bool, interval time.Duration) {
	if interval < time.Second {
		interval = 15 * time.Second
	}
	c.enabled.Store(enabled)
	c.interval.Store(int64(interval))
	select {
	case c.wake <- struct{}{}:
	default:
	}
}

// Run emits metrics every interval until ctx is done.
func (c *Collector) Run(ctx context.Context) {
	for {
		t := time.NewTimer(time.Duration(c.interval.Load()))
		select {
		case <-ctx.Done():
			t.Stop()
			return
		case <-c.wake:
			t.Stop()
			continue
		case <-t.C:
		}
		if c.enabled.Load() {
			if rm := c.Collect(); rm != nil {
				c.out.EmitMetrics(rm)
			}
		}
	}
}

func gauge(name, unit string, pts ...*metricspb.NumberDataPoint) *metricspb.Metric {
	return &metricspb.Metric{Name: name, Unit: unit, Data: &metricspb.Metric_Gauge{Gauge: &metricspb.Gauge{DataPoints: pts}}}
}

func counter(name, unit string, pts ...*metricspb.NumberDataPoint) *metricspb.Metric {
	return &metricspb.Metric{Name: name, Unit: unit, Data: &metricspb.Metric_Sum{Sum: &metricspb.Sum{
		DataPoints: pts, IsMonotonic: true, AggregationTemporality: metricspb.AggregationTemporality_AGGREGATION_TEMPORALITY_CUMULATIVE}}}
}

func str(k, v string) *commonpb.KeyValue {
	return &commonpb.KeyValue{Key: k, Value: &commonpb.AnyValue{Value: &commonpb.AnyValue_StringValue{StringValue: v}}}
}

// Collect samples all sources once and returns an OTLP ResourceMetrics (nil if nothing readable).
func (c *Collector) Collect() *metricspb.ResourceMetrics {
	fs := c.s.fs
	now := uint64(c.now().UnixNano())
	var start uint64
	var ms []*metricspb.Metric
	dbl := func(v float64, attrs ...*commonpb.KeyValue) *metricspb.NumberDataPoint {
		return &metricspb.NumberDataPoint{TimeUnixNano: now, StartTimeUnixNano: start, Value: &metricspb.NumberDataPoint_AsDouble{AsDouble: v}, Attributes: attrs}
	}
	in := func(v int64, attrs ...*commonpb.KeyValue) *metricspb.NumberDataPoint {
		return &metricspb.NumberDataPoint{TimeUnixNano: now, StartTimeUnixNano: start, Value: &metricspb.NumberDataPoint_AsInt{AsInt: v}, Attributes: attrs}
	}
	if up, err := ReadUptime(fs); err == nil {
		start = now - uint64(up*1e9)
		ms = append(ms, gauge("system.uptime", "s", dbl(up)))
	}
	if cur, err := ReadCPU(fs); err == nil {
		c.mu.Lock()
		prev := CPUTimes{}
		if c.prevCPU != nil {
			prev = *c.prevCPU
		}
		c.prevCPU = &cur
		c.mu.Unlock()
		ms = append(ms, gauge("system.cpu.utilization", "1", dbl(Utilization(prev, cur))))
		const hz = 100.0 // USER_HZ
		st := func(state string, j uint64) *metricspb.NumberDataPoint {
			return dbl(float64(j)/hz, str("cpu.mode", state))
		}
		ms = append(ms, counter("system.cpu.time", "s", st("user", cur.User), st("nice", cur.Nice), st("system", cur.System),
			st("idle", cur.Idle), st("iowait", cur.IOWait), st("interrupt", cur.IRQ+cur.SoftIRQ), st("steal", cur.Steal)))
	}
	if n, err := ReadLogicalCPUs(fs); err == nil {
		ms = append(ms, gauge("system.cpu.logical.count", "{cpu}", in(int64(n))))
	}
	if l, err := ReadLoad(fs); err == nil {
		ms = append(ms, gauge("system.cpu.load_average.1m", "{thread}", dbl(l[0])),
			gauge("system.cpu.load_average.5m", "{thread}", dbl(l[1])),
			gauge("system.cpu.load_average.15m", "{thread}", dbl(l[2])))
	}
	if m, err := ReadMem(fs); err == nil {
		ms = append(ms, gauge("system.memory.usage", "By",
			in(m.Used(), str("system.memory.state", "used")), in(m.Free, str("system.memory.state", "free")),
			in(m.Cached, str("system.memory.state", "cached")), in(m.Buffers, str("system.memory.state", "buffers"))),
			gauge("system.memory.limit", "By", in(m.Total)),
			gauge("system.memory.utilization", "1", dbl(safeDiv(m.Used(), m.Total))))
		if m.SwapTotal > 0 {
			ms = append(ms, gauge("system.paging.usage", "By", in(m.SwapTotal-m.SwapFree, str("system.paging.state", "used")), in(m.SwapFree, str("system.paging.state", "free"))))
		}
	}
	if u, err := ReadFS(fs, "/"); err == nil {
		mp := str("system.filesystem.mountpoint", "/")
		ms = append(ms, gauge("system.filesystem.usage", "By", in(u.Used, mp, str("system.filesystem.state", "used")), in(u.Free, mp, str("system.filesystem.state", "free"))),
			gauge("system.filesystem.utilization", "1", dbl(safeDiv(u.Used, u.Total), mp)))
	}
	if devs, err := ReadNetDev(fs); err == nil && len(devs) > 0 {
		var io, pk, er []*metricspb.NumberDataPoint
		for _, d := range devs {
			dev := str("network.interface.name", d.Name)
			rx, tx := str("network.io.direction", "receive"), str("network.io.direction", "transmit")
			io = append(io, in(int64(d.RxBytes), dev, rx), in(int64(d.TxBytes), dev, tx))
			pk = append(pk, in(int64(d.RxPkts), dev, rx), in(int64(d.TxPkts), dev, tx))
			er = append(er, in(int64(d.RxErrs), dev, rx), in(int64(d.TxErrs), dev, tx))
		}
		ms = append(ms, counter("system.network.io", "By", io...), counter("system.network.packets", "{packet}", pk...), counter("system.network.errors", "{error}", er...))
	}
	if disks, err := ReadDiskStats(fs); err == nil && len(disks) > 0 {
		var io, ops []*metricspb.NumberDataPoint
		for _, d := range disks {
			dev := str("system.device", d.Name)
			r, w := str("disk.io.direction", "read"), str("disk.io.direction", "write")
			io = append(io, in(int64(d.ReadBytes), dev, r), in(int64(d.WriteBytes), dev, w))
			ops = append(ops, in(int64(d.ReadOps), dev, r), in(int64(d.WriteOps), dev, w))
		}
		ms = append(ms, counter("system.disk.io", "By", io...), counter("system.disk.operations", "{operation}", ops...))
	}
	if len(ms) == 0 {
		return nil
	}
	return &metricspb.ResourceMetrics{
		Resource: &resourcepb.Resource{Attributes: []*commonpb.KeyValue{str("service.name", "falak-host")}},
		ScopeMetrics: []*metricspb.ScopeMetrics{{
			Scope:   &commonpb.InstrumentationScope{Name: "falak-agent/metrics"},
			Metrics: ms,
		}},
	}
}

func safeDiv(a, b int64) float64 {
	if b == 0 {
		return 0
	}
	return float64(a) / float64(b)
}
