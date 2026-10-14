package metrics

import (
	"os"
	"path/filepath"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	metricspb "go.opentelemetry.io/proto/otlp/metrics/v1"
)

const fixStat1 = "cpu  100 0 100 700 100 0 0 0 0 0\ncpu0 100 0 100 700 100 0 0 0 0 0\n"
const fixStat2 = "cpu  200 0 200 1400 200 0 0 0 0 0\n"

const fixMeminfo = `MemTotal:        2048000 kB
MemFree:          512000 kB
MemAvailable:    1024000 kB
Buffers:           64000 kB
Cached:           256000 kB
SwapTotal:       1000000 kB
SwapFree:         900000 kB
`

const fixNetDev = `Inter-|   Receive                                                |  Transmit
 face |bytes    packets errs drop fifo frame compressed multicast|bytes    packets errs drop fifo colls carrier compressed
    lo: 99999     100    0    0    0     0          0         0    99999     100    0    0    0     0       0          0
  eth0: 1000       10    1    0    0     0          0         0     2000      20    2    0    0     0       0          0
`

const fixDiskstats = `   8       0 sda 100 0 2000 50 200 0 4000 60 0 70 110 0 0 0 0
   8       1 sda1 90 0 1800 40 190 0 3800 50 0 60 90 0 0 0 0
   7       0 loop0 1 0 2 0 0 0 0 0 0 0 0 0 0 0 0
 259       0 nvme0n1 10 0 20 1 30 0 40 2 0 3 3 0 0 0 0
 259       1 nvme0n1p1 10 0 20 1 30 0 40 2 0 3 3 0 0 0 0
`

func fixture(t *testing.T, stat string) hostfs.FS {
	root := t.TempDir()
	files := map[string]string{
		"proc/stat": stat, "proc/meminfo": fixMeminfo, "proc/loadavg": "0.50 0.25 0.10 1/200 1234\n",
		"proc/uptime": "3600.55 7000.00\n", "proc/net/dev": fixNetDev, "proc/diskstats": fixDiskstats,
	}
	for p, c := range files {
		full := filepath.Join(root, p)
		os.MkdirAll(filepath.Dir(full), 0o755)
		if err := os.WriteFile(full, []byte(c), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	return hostfs.FS{Root: root}
}

func TestReaders(t *testing.T) {
	fs := fixture(t, fixStat1)
	m, err := ReadMem(fs)
	if err != nil || m.Total != 2048000*1024 || m.Used() != 1024000*1024 {
		t.Fatalf("mem %+v %v", m, err)
	}
	nd, _ := ReadNetDev(fs)
	if len(nd) != 1 || nd[0].Name != "eth0" || nd[0].RxBytes != 1000 || nd[0].TxBytes != 2000 || nd[0].TxErrs != 2 {
		t.Fatalf("netdev %+v", nd)
	}
	ds, _ := ReadDiskStats(fs)
	if len(ds) != 2 || ds[0].Name != "sda" || ds[0].ReadBytes != 2000*512 || ds[0].WriteBytes != 4000*512 || ds[1].Name != "nvme0n1" {
		t.Fatalf("diskstats %+v", ds)
	}
	l, _ := ReadLoad(fs)
	if l != [3]float64{0.5, 0.25, 0.1} {
		t.Fatalf("load %v", l)
	}
}

func TestSummaryCPUDelta(t *testing.T) {
	fs := fixture(t, fixStat1)
	s := NewSampler(fs)
	first := s.Summary()
	// since boot: busy=200 of total=1000 → 20%
	if first.CPUPercent != 20 || first.UptimeS != 3600 || first.Load[0] != 0.5 || first.MemUsedBytes != 1024000*1024 || first.DiskUsedBytes <= 0 {
		t.Fatalf("summary %+v", first)
	}
	os.WriteFile(fs.P("/proc/stat"), []byte(fixStat2), 0o644)
	// delta busy=200 of total=1000 → 20%
	if got := s.Summary().CPUPercent; got != 20 {
		t.Fatalf("delta cpu %v", got)
	}
}

type captured struct{ rm []*metricspb.ResourceMetrics }

func (c *captured) EmitMetrics(rm *metricspb.ResourceMetrics) { c.rm = append(c.rm, rm) }

func TestCollectOTLP(t *testing.T) {
	fs := fixture(t, fixStat1)
	c := NewCollector(NewSampler(fs), &captured{})
	rm := c.Collect()
	if rm == nil {
		t.Fatal("nil metrics")
	}
	byName := map[string]*metricspb.Metric{}
	for _, m := range rm.ScopeMetrics[0].Metrics {
		byName[m.Name] = m
	}
	for _, n := range []string{"system.cpu.utilization", "system.cpu.time", "system.memory.usage", "system.memory.limit", "system.cpu.load_average.1m",
		"system.cpu.load_average.5m", "system.cpu.load_average.15m", "system.filesystem.usage", "system.network.io", "system.disk.io", "system.uptime", "system.paging.usage"} {
		if byName[n] == nil {
			t.Errorf("missing metric %s", n)
		}
	}
	if u := byName["system.cpu.utilization"].GetGauge().DataPoints[0].GetAsDouble(); u < 0.199 || u > 0.201 {
		t.Errorf("cpu util %v", u)
	}
	nio := byName["system.network.io"].GetSum()
	if !nio.IsMonotonic || len(nio.DataPoints) != 2 || nio.DataPoints[1].GetAsInt() != 2000 {
		t.Errorf("network io %v", nio)
	}
	if byName["system.disk.io"].GetSum().DataPoints[0].StartTimeUnixNano == 0 {
		t.Error("cumulative start time missing")
	}
}

func TestMissingProcIsTolerated(t *testing.T) {
	s := NewSampler(hostfs.FS{Root: t.TempDir()})
	sum := s.Summary()
	if sum.UptimeS != 0 || sum.CPUPercent != 0 {
		t.Fatalf("%+v", sum)
	}
}

func TestLogicalCPUCount(t *testing.T) {
	for _, tc := range []struct {
		name, stat string
		want       int
		ok         bool
	}{
		{"one", fixStat1, 1, true},
		{"four, other lines ignored", "cpu  1 2 3 4\ncpu0 1\ncpu1 1\ncpu2 1\ncpu3 1\nintr 5\nctxt 9\ncpufreq 1\n", 4, true},
		{"aggregate only", fixStat2, 0, false},
	} {
		t.Run(tc.name, func(t *testing.T) {
			n, err := ReadLogicalCPUs(fixture(t, tc.stat))
			if n != tc.want || (err == nil) != tc.ok {
				t.Fatalf("got %d %v, want %d", n, err, tc.want)
			}
		})
	}
	rm := NewCollector(NewSampler(fixture(t, "cpu  1 0 0 0\ncpu0 1 0 0 0\ncpu1 0 0 0 0\n")), &captured{}).Collect()
	for _, m := range rm.ScopeMetrics[0].Metrics {
		if m.Name == "system.cpu.logical.count" {
			if v := m.GetGauge().DataPoints[0].GetAsInt(); v != 2 || m.Unit != "{cpu}" {
				t.Fatalf("logical count %d %s", v, m.Unit)
			}
			return
		}
	}
	t.Fatal("system.cpu.logical.count missing")
}
