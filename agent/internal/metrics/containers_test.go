package metrics

import (
	"context"
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/docker"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/otlp"
	commonpb "go.opentelemetry.io/proto/otlp/common/v1"
	metricspb "go.opentelemetry.io/proto/otlp/metrics/v1"
)

type fakeEngine struct {
	list     []docker.ContainerSummary
	inspect  map[string]*docker.Container
	stats    map[string]docker.Stats
	inspects map[string]int
	statsN   int
}

func (f *fakeEngine) ContainerList(_ context.Context, all bool, labels []string) ([]docker.ContainerSummary, error) {
	if all || len(labels) != 0 {
		panic("one unfiltered list of running containers expected")
	}
	return f.list, nil
}

func (f *fakeEngine) ContainerInspect(_ context.Context, id string) (*docker.Container, bool, error) {
	f.inspects[id]++
	ct, ok := f.inspect[id]
	return ct, ok, nil
}

func (f *fakeEngine) ContainerStats(_ context.Context, id string, oneShot bool) (docker.Stats, error) {
	if !oneShot {
		panic("collector must use one-shot stats")
	}
	f.statsN++
	return f.stats[id], nil
}

func inspected(pid int, memory, nanoCPUs int64) *docker.Container {
	c := &docker.Container{}
	c.State.Pid, c.HostConfig.Memory, c.HostConfig.NanoCpus = pid, memory, nanoCPUs
	return c
}

func metricByName(rm *metricspb.ResourceMetrics, name string) *metricspb.Metric {
	for _, m := range rm.ScopeMetrics[0].Metrics {
		if m.Name == name {
			return m
		}
	}
	return nil
}

func points(m *metricspb.Metric) []*metricspb.NumberDataPoint {
	if m == nil {
		return nil
	}
	if g := m.GetGauge(); g != nil {
		return g.DataPoints
	}
	return m.GetSum().DataPoints
}

// pointWith returns the first point of metric name in rm whose attributes include every kv pair.
func pointWith(rm *metricspb.ResourceMetrics, name string, kv ...string) *metricspb.NumberDataPoint {
	for _, p := range points(metricByName(rm, name)) {
		match := true
		for i := 0; i+1 < len(kv); i += 2 {
			if v, _ := otlp.Lookup(p.Attributes, kv[i]); v != kv[i+1] {
				match = false
			}
		}
		if match {
			return p
		}
	}
	return nil
}

func resourceBy(rms []*metricspb.ResourceMetrics, kv ...string) *metricspb.ResourceMetrics {
	for _, rm := range rms {
		match := true
		for i := 0; i+1 < len(kv); i += 2 {
			if v, _ := otlp.Lookup(rm.Resource.Attributes, kv[i]); v != kv[i+1] {
				match = false
			}
		}
		if match {
			return rm
		}
	}
	return nil
}

func attr(kvs []*commonpb.KeyValue, k string) string { v, _ := otlp.Lookup(kvs, k); return v }

const (
	idSystemd  = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
	idCgroupfs = "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"
	idV1       = "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc"
	idDB       = "dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd"
	idOther    = "eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee"
	dbInstance = "01hzy0000000000000000000db"
)

func cgroupFiles(dir, usageUsec, cpuMax, memMax string) map[string]string {
	return map[string]string{
		dir + "/cpu.stat":       "usage_usec " + usageUsec + "\nnr_periods 10\nnr_throttled 1\nthrottled_usec 5000\n",
		dir + "/cpu.max":        cpuMax,
		dir + "/memory.current": "3000\n",
		dir + "/memory.stat":    "anon 2000\nfile 1000\n",
		dir + "/memory.max":     memMax,
		dir + "/memory.events":  "oom_kill 1\n",
		dir + "/io.stat":        "8:0 rbytes=10 wbytes=20 rios=1 wios=1\n",
		dir + "/pids.current":   "3\n",
		dir + "/pids.max":       "max\n",
	}
}

const containerNetDev = `Inter-|   Receive                                                |  Transmit
 face |bytes    packets errs drop fifo frame compressed multicast|bytes    packets errs drop fifo colls carrier compressed
    lo:  5000       5    0    0    0     0          0         0     5000       5    0    0    0     0       0          0
  eth0:   100       1    0    0    0     0          0         0      200       2    0    0    0     0       0          0
  eth1:    10       1    0    0    0     0          0         0       20       2    0    0    0     0       0          0
`

func TestWorkloadContainers(t *testing.T) {
	root := t.TempDir()
	files := map[string]string{"proc/100/net/dev": containerNetDev, "proc/200/net/dev": containerNetDev, "proc/400/net/dev": containerNetDev}
	for k, v := range cgroupFiles("sys/fs/cgroup/system.slice/docker-"+idSystemd+".scope", "2500000", "150000 100000\n", "536870912\n") {
		files[k] = v
	}
	for k, v := range cgroupFiles("sys/fs/cgroup/docker/"+idCgroupfs, "1000000", "max 100000\n", "max\n") {
		files[k] = v
	}
	for k, v := range cgroupFiles("sys/fs/cgroup/system.slice/docker-"+idDB+".scope", "4000000", "200000 100000\n", "1073741824\n") {
		files[k] = v
	}
	writeTree(t, root, files)

	var v1 docker.Stats
	v1.CPUStats.CPUUsage.TotalUsage = 3_000_000_000
	v1.MemoryStats.Usage, v1.MemoryStats.Limit = 900, 1<<40
	v1.MemoryStats.Stats = map[string]uint64{"inactive_file": 100}
	v1.Networks = map[string]struct {
		RxBytes uint64 `json:"rx_bytes"`
		TxBytes uint64 `json:"tx_bytes"`
	}{"eth0": {RxBytes: 7, TxBytes: 8}}

	e := &fakeEngine{inspects: map[string]int{}, list: []docker.ContainerSummary{
		{ID: idSystemd, Names: []string{"/shop-app-1"}, Labels: map[string]string{"falak.site": "shop", "falak.service": "app"}},
		{ID: idCgroupfs, Names: []string{"/shop-redis-1"}, Labels: map[string]string{"falak.site": "shop", "com.docker.compose.service": "redis"}},
		{ID: idV1, Names: []string{"/blog-blue"}, Labels: map[string]string{"falak.site": "blog"}},
		{ID: idDB, Names: []string{"/falak-db-" + dbInstance}, Labels: map[string]string{"falak.db.instance": dbInstance, "falak.db.engine": "postgres"}},
		{ID: idOther, Names: []string{"/portainer"}, Labels: map[string]string{}},
	}, inspect: map[string]*docker.Container{
		idSystemd: inspected(100, 0, 0), idCgroupfs: inspected(200, 0, 0), idV1: inspected(300, 1<<30, 500_000_000), idDB: inspected(400, 0, 0),
	}, stats: map[string]docker.Stats{idV1: v1}}
	c := NewWorkloadCollector(hostfs.FS{Root: root}, e, nil)
	c.now = func() time.Time { return time.Unix(1700000000, 0) }

	rms := c.Collect(context.Background())
	if len(rms) != 3 {
		t.Fatalf("resources: blog, db, shop; got %d", len(rms))
	}

	t.Run("systemd driver", func(t *testing.T) {
		shop := resourceBy(rms, "service.name", "shop")
		if shop == nil || len(shop.Resource.Attributes) != 1 {
			t.Fatalf("shop resource %v", shop)
		}
		app := []string{"container.id", idSystemd}
		p := pointWith(shop, "falak.workload.cpu.time", app...)
		if p.GetAsDouble() != 2.5 || attr(p.Attributes, "falak.workload.kind") != "container" ||
			attr(p.Attributes, "falak.workload.name") != "shop-app-1" || attr(p.Attributes, "falak.compose.service") != "app" {
			t.Fatalf("cpu point %v", p)
		}
		if !metricByName(shop, "falak.workload.cpu.time").GetSum().IsMonotonic {
			t.Fatal("cpu time is a counter")
		}
		if pointWith(shop, "falak.workload.cpu.limit", app...).GetAsDouble() != 1.5 ||
			pointWith(shop, "falak.workload.memory.limit", app...).GetAsInt() != 536870912 {
			t.Fatal("limits")
		}
		if pointWith(shop, "falak.workload.memory.usage", append(app, "falak.memory.type", "used")...).GetAsInt() != 2000 ||
			pointWith(shop, "falak.workload.memory.usage", append(app, "falak.memory.type", "cache")...).GetAsInt() != 1000 {
			t.Fatal("memory used/cache")
		}
		if pointWith(shop, "falak.workload.network.io", append(app, "network.io.direction", "receive")...).GetAsInt() != 110 ||
			pointWith(shop, "falak.workload.network.io", append(app, "network.io.direction", "transmit")...).GetAsInt() != 220 {
			t.Fatal("network excludes lo, sums the rest")
		}
		if pointWith(shop, "falak.workload.cpu.throttled.time", app...).GetAsDouble() != 0.005 ||
			pointWith(shop, "falak.workload.cpu.throttled.periods", app...).GetAsInt() != 1 ||
			pointWith(shop, "falak.workload.cpu.periods", app...).GetAsInt() != 10 ||
			pointWith(shop, "falak.workload.memory.oom_kills", app...).GetAsInt() != 1 ||
			pointWith(shop, "falak.workload.disk.io", append(app, "disk.io.direction", "write")...).GetAsInt() != 20 ||
			pointWith(shop, "falak.workload.pids", app...).GetAsInt() != 3 {
			t.Fatal("throttling, oom, io, pids")
		}
		if metricByName(shop, "falak.workload.pids.limit") != nil {
			t.Fatal("pids.max max: no series")
		}
	})

	t.Run("cgroupfs driver", func(t *testing.T) {
		shop := resourceBy(rms, "service.name", "shop")
		redis := []string{"container.id", idCgroupfs}
		if pointWith(shop, "falak.workload.cpu.time", redis...).GetAsDouble() != 1 ||
			attr(pointWith(shop, "falak.workload.cpu.time", redis...).Attributes, "falak.compose.service") != "redis" {
			t.Fatal("cgroupfs container")
		}
		if pointWith(shop, "falak.workload.cpu.limit", redis...) != nil || pointWith(shop, "falak.workload.memory.limit", redis...) != nil {
			t.Fatal("unlimited container has no limit series")
		}
	})

	t.Run("cgroup v1 falls back to docker stats", func(t *testing.T) {
		blog := resourceBy(rms, "service.name", "blog")
		if e.statsN != 1 {
			t.Fatalf("stats only for the v1 container, %d calls", e.statsN)
		}
		if pointWith(blog, "falak.workload.cpu.time").GetAsDouble() != 3 ||
			pointWith(blog, "falak.workload.memory.usage", "falak.memory.type", "used").GetAsInt() != 800 ||
			pointWith(blog, "falak.workload.memory.limit").GetAsInt() != 1<<30 ||
			pointWith(blog, "falak.workload.cpu.limit").GetAsDouble() != 0.5 ||
			pointWith(blog, "falak.workload.network.io", "network.io.direction", "receive").GetAsInt() != 7 {
			t.Fatal("v1 values")
		}
		if metricByName(blog, "falak.workload.disk.io") != nil || pointWith(blog, "falak.workload.memory.usage", "falak.memory.type", "cache") != nil {
			t.Fatal("v1 reports cpu, memory and network only")
		}
	})

	t.Run("database container", func(t *testing.T) {
		db := resourceBy(rms, "service.name", "falak-db")
		if db == nil || attr(db.Resource.Attributes, "falak.db.instance.id") != dbInstance {
			t.Fatalf("db resource %v", db)
		}
		if pointWith(db, "falak.workload.cpu.limit").GetAsDouble() != 2 || pointWith(db, "falak.workload.memory.limit").GetAsInt() != 1<<30 {
			t.Fatal("db limits")
		}
	})

	t.Run("inspect once, again after a restart", func(t *testing.T) {
		c.Collect(context.Background())
		if e.inspects[idSystemd] != 1 || e.inspects[idDB] != 1 || e.inspects[idOther] != 0 {
			t.Fatalf("inspects %v", e.inspects)
		}
		// The app restarts: same id, new init pid; the old pid is gone from /proc.
		os.RemoveAll(filepath.Join(root, "proc/100"))
		writeTree(t, root, map[string]string{"proc/150/net/dev": containerNetDev})
		e.inspect[idSystemd] = inspected(150, 0, 0)
		rms := c.Collect(context.Background())
		if e.inspects[idSystemd] != 2 {
			t.Fatalf("re-inspect after restart: %v", e.inspects)
		}
		if pointWith(resourceBy(rms, "service.name", "shop"), "falak.workload.network.io", "container.id", idSystemd, "network.io.direction", "receive").GetAsInt() != 110 {
			t.Fatal("network from the new pid")
		}
		c.Collect(context.Background())
		if e.inspects[idSystemd] != 2 {
			t.Fatal("new pid cached")
		}
	})

	t.Run("a reused pid is told apart by its start time", func(t *testing.T) {
		// The app's init process (pid 160, started at tick 500) is known by its start time.
		writeTree(t, root, map[string]string{"proc/160/net/dev": containerNetDev, "proc/160/stat": procStat(160, "app", 1, 1, 500)})
		e.inspect[idSystemd] = inspected(160, 0, 0)
		os.RemoveAll(filepath.Join(root, "proc/150"))
		c.Collect(context.Background())
		if e.inspects[idSystemd] != 3 || c.cts[idSystemd].pidStart != 500 {
			t.Fatalf("inspect %v start %d", e.inspects, c.cts[idSystemd].pidStart)
		}
		c.Collect(context.Background())
		if e.inspects[idSystemd] != 3 {
			t.Fatal("same start time: the pid is still the container's")
		}
		// The container restarts in place and another process gets pid 160: same pid, another start time.
		writeTree(t, root, map[string]string{"proc/160/stat": procStat(160, "other", 1, 1, 900)})
		e.inspect[idSystemd] = inspected(170, 0, 0)
		writeTree(t, root, map[string]string{"proc/170/net/dev": containerNetDev, "proc/170/stat": procStat(170, "app", 1, 1, 950)})
		c.Collect(context.Background())
		if e.inspects[idSystemd] != 4 || c.cts[idSystemd].pid != 170 {
			t.Fatalf("re-inspect on a reused pid: %v pid %d", e.inspects, c.cts[idSystemd].pid)
		}
	})

	t.Run("gone containers leave the cache", func(t *testing.T) {
		e.list = e.list[:1]
		c.Collect(context.Background())
		if len(c.cts) != 1 {
			t.Fatalf("cache %d", len(c.cts))
		}
	})
}

func TestCgroupDirDiscovery(t *testing.T) {
	const id = "abc"
	for _, tc := range []struct {
		name, parent, create, want string
	}{
		{"systemd", "", "/sys/fs/cgroup/system.slice/docker-abc.scope", "/sys/fs/cgroup/system.slice/docker-abc.scope"},
		{"cgroupfs", "", "/sys/fs/cgroup/docker/abc", "/sys/fs/cgroup/docker/abc"},
		{"custom parent", "/falak.slice", "/sys/fs/cgroup/falak.slice/abc", "/sys/fs/cgroup/falak.slice/abc"},
		{"nested systemd slice", "falak-site_shop.slice", "/sys/fs/cgroup/falak.slice/falak-site_shop.slice/docker-abc.scope", "/sys/fs/cgroup/falak.slice/falak-site_shop.slice/docker-abc.scope"},
		{"v1 host", "", "/sys/fs/cgroup/cpu/docker/abc", ""},
	} {
		t.Run(tc.name, func(t *testing.T) {
			root := t.TempDir()
			writeTree(t, root, map[string]string{tc.create + "/cpu.stat": "usage_usec 1\n"})
			c := NewWorkloadCollector(hostfs.FS{Root: root}, nil, nil)
			if got := c.cgroupDir(id, tc.parent); got != tc.want {
				t.Fatalf("got %q want %q", got, tc.want)
			}
		})
	}
}

func TestSystemdSlicePath(t *testing.T) {
	for in, want := range map[string]string{
		"falak.slice":               "falak.slice",
		"falak-site_shop.slice":     "falak.slice/falak-site_shop.slice",
		"a-b-c.slice":               "a.slice/a-b.slice/a-b-c.slice",
		"falak.slice/falak-x.slice": "falak.slice/falak-x.slice",
		"docker":                    "docker",
	} {
		if got := systemdSlicePath(in); got != want {
			t.Errorf("%s: got %s want %s", in, got, want)
		}
	}
}

func TestWorkloadNoDockerSocket(t *testing.T) {
	e := &fakeEngine{inspects: map[string]int{}}
	c := NewWorkloadCollector(hostfs.FS{Root: t.TempDir()}, e, nil)
	c.Socket = filepath.Join(t.TempDir(), "docker.sock")
	if rms := c.Collect(context.Background()); len(rms) != 0 {
		t.Fatalf("%v", rms)
	}
}
