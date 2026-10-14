package metrics

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/otlp"
	metricspb "go.opentelemetry.io/proto/otlp/metrics/v1"
)

func TestWorkloadSlices(t *testing.T) {
	root := t.TempDir()
	files := map[string]string{}
	for _, key := range []string{"site_my_shop", "worker_01hzyw", "daemon_01hzyd", "site_gone", "other"} {
		for k, v := range cgroupFiles("sys/fs/cgroup/falak.slice/falak-"+key+".slice", "1000000", "50000 100000\n", "268435456\n") {
			files[k] = v
		}
	}
	files["sys/fs/cgroup/falak.slice/cgroup.procs"] = ""
	writeTree(t, root, files)
	c := NewWorkloadCollector(hostfs.FS{Root: root}, nil, nil)
	c.SetSites([]otlp.Site{{Slug: "my-shop", SiteID: "S1", UnixUser: "myshop", Runtime: "php-fpm"}})
	rms := c.Collect(context.Background())
	if len(rms) != 3 {
		t.Fatalf("my-shop, worker, daemon; got %d", len(rms))
	}
	for _, tc := range []struct {
		name string
		res  []string
		key  string
	}{
		{"site slice maps back to the slug", []string{"service.name", "my-shop"}, "site_my_shop"},
		{"worker slice", []string{"service.name", "falak-agent", "falak.slice", "worker_01hzyw"}, "worker_01hzyw"},
		{"daemon slice", []string{"service.name", "falak-agent", "falak.slice", "daemon_01hzyd"}, "daemon_01hzyd"},
	} {
		t.Run(tc.name, func(t *testing.T) {
			rm := resourceBy(rms, tc.res...)
			if rm == nil || len(rm.Resource.Attributes) != len(tc.res)/2 {
				t.Fatalf("resource %v", rm)
			}
			p := pointWith(rm, "falak.workload.cpu.time", "falak.workload.kind", "slice", "falak.workload.name", tc.key)
			if p.GetAsDouble() != 1 || attr(p.Attributes, "container.id") != "" {
				t.Fatalf("cpu %v", p)
			}
			if pointWith(rm, "falak.workload.cpu.limit").GetAsDouble() != 0.5 || pointWith(rm, "falak.workload.memory.limit").GetAsInt() != 268435456 {
				t.Fatal("slice limits")
			}
			if metricByName(rm, "falak.workload.network.io") != nil {
				t.Fatal("slices have no network series")
			}
			if pointWith(rm, "falak.workload.cpu.time", "falak.workload.kind", "user") != nil {
				t.Fatal("a site with a slice is not measured per user too")
			}
		})
	}
}

func procStat(pid int, comm string, utime, stime, start uint64) string {
	return fmt.Sprintf("%d (%s) S 1 1 1 0 -1 4194560 10 0 0 0 %d %d 0 0 20 0 1 0 %d 1000000 100\n", pid, comm, utime, stime, start)
}

// procStatus has VmRSS = RssAnon + 64 MiB of shared pages (OPcache, libraries): only RssAnon counts.
func procStatus(uid int, rssKB int64) string {
	return fmt.Sprintf("Name:\tx\nState:\tS (sleeping)\nUid:\t%d\t%d\t%d\t%d\nGid:\t%d\t%d\t%d\t%d\nVmRSS:\t   %d kB\nRssAnon:\t   %d kB\nRssFile:\t   1024 kB\nRssShmem:\t   64512 kB\n",
		uid, uid, uid, uid, uid, uid, uid, uid, rssKB+65536, rssKB)
}

func writeProc(t *testing.T, root string, pid, uid int, rssKB int64, utime, stime, start, read, write uint64) {
	writeTree(t, root, map[string]string{
		fmt.Sprintf("proc/%d/status", pid): procStatus(uid, rssKB),
		fmt.Sprintf("proc/%d/stat", pid):   procStat(pid, "php-fpm: pool (x) y", utime, stime, start),
		fmt.Sprintf("proc/%d/io", pid):     fmt.Sprintf("rchar: 1\nwchar: 2\nread_bytes: %d\nwrite_bytes: %d\ncancelled_write_bytes: 0\n", read, write),
	})
}

func TestParseProcFiles(t *testing.T) {
	for _, tc := range []struct {
		name         string
		in           string
		ticks, start uint64
		ok           bool
	}{
		{"plain", procStat(10, "php-fpm", 30, 12, 555), 42, 555, true},
		{"comm with spaces and parens", procStat(10, "php-fpm: pool (blog) ) x", 1, 2, 3), 3, 3, true},
		{"truncated", "10 (x) S 1 2 3", 0, 0, false},
		{"no comm", "garbage", 0, 0, false},
	} {
		ticks, start, ok := parseProcStat([]byte(tc.in))
		if ticks != tc.ticks || start != tc.start || ok != tc.ok {
			t.Errorf("%s: %d %d %v", tc.name, ticks, start, ok)
		}
	}
	uid, rss, ok := parseProcStatus([]byte(procStatus(1001, 2048)))
	if uid != 1001 || rss != 2048*1024 || !ok {
		t.Fatalf("status %d %d %v", uid, rss, ok)
	}
	if _, rss, ok := parseProcStatus([]byte("Name:\tkthreadd\nUid:\t0\t0\t0\t0\n")); rss != 0 || !ok {
		t.Fatal("kernel thread: no RssAnon")
	}
	ids := passwdUIDs(fixtureFS(t, map[string]string{"etc/passwd": "root:x:0:0::/root:/bin/bash\n# comment\nblog:x:1001:1001::/srv/falak/sites/blog:/bin/bash\nbad:x:nan:0::/:/bin/false\n"}))
	if len(ids) != 2 || ids["blog"] != 1001 || ids["root"] != 0 {
		t.Fatalf("passwd %v", ids)
	}
}

func fixtureFS(t *testing.T, files map[string]string) hostfs.FS {
	root := t.TempDir()
	writeTree(t, root, files)
	return hostfs.FS{Root: root}
}

func TestWorkloadUsers(t *testing.T) {
	root := t.TempDir()
	writeTree(t, root, map[string]string{
		"etc/passwd":       "root:x:0:0::/root:/bin/bash\nblog:x:1001:1001::/:/bin/bash\napi:x:1002:1002::/:/bin/bash\ndock:x:1003:1003::/:/bin/bash\n",
		"proc/self/status": procStatus(0, 1), // non-numeric entries are skipped
	})
	writeProc(t, root, 1, 0, 9000, 5000, 5000, 1, 0, 0)          // root: not a site's
	writeProc(t, root, 10, 1001, 1000, 100, 50, 100, 4096, 8192) // blog master
	writeProc(t, root, 11, 1001, 2000, 200, 50, 200, 0, 4096)    // blog worker
	writeProc(t, root, 20, 1002, 500, 10, 10, 300, 0, 0)         // api (node)
	writeProc(t, root, 30, 1003, 500, 10, 10, 300, 0, 0)         // dock: a docker site, never per user
	c := NewWorkloadCollector(hostfs.FS{Root: root}, nil, nil)
	c.SetSites([]otlp.Site{
		{Slug: "blog", SiteID: "S1", UnixUser: "blog", Runtime: "php-fpm"},
		{Slug: "api", SiteID: "S2", UnixUser: "api", Runtime: "node"},
		{Slug: "dock", SiteID: "S3", UnixUser: "dock", Runtime: "docker"},
		{Slug: "old", SiteID: "S4"},                                         // no unix_user (older control plane): skipped
		{Slug: "ghost", SiteID: "S5", UnixUser: "ghost", Runtime: "static"}, // no such user: skipped
	})
	cpu := func(rms []*metricspb.ResourceMetrics, slug string) float64 {
		return pointWith(resourceBy(rms, "service.name", slug), "falak.workload.cpu.time", "falak.workload.kind", "user").GetAsDouble()
	}

	rms := c.Collect(context.Background())
	if len(rms) != 2 || resourceBy(rms, "service.name", "dock") != nil {
		t.Fatalf("blog and api only, got %d", len(rms))
	}
	blog := resourceBy(rms, "service.name", "blog")
	// The counters start at zero: what the processes already running used before (150 + 250 ticks, 12288 bytes
	// written) happened before the agent looked, and must not show as one interval's.
	if got := cpu(rms, "blog"); got != 0 {
		t.Fatalf("blog cpu %v", got)
	}
	p := pointWith(blog, "falak.workload.memory.usage", "falak.memory.type", "used")
	if p.GetAsInt() != 3000*1024 || attr(p.Attributes, "falak.workload.name") != "blog" {
		t.Fatalf("blog rss %v", p)
	}
	if pointWith(blog, "falak.workload.memory.usage", "falak.memory.type", "cache") != nil {
		t.Fatal("per-user memory has no cache series")
	}
	if pointWith(blog, "falak.workload.disk.io", "disk.io.direction", "write").GetAsInt() != 0 || pointWith(blog, "falak.workload.pids").GetAsInt() != 2 {
		t.Fatal("blog io / pids")
	}
	for _, m := range []string{"falak.workload.network.io", "falak.workload.cpu.limit", "falak.workload.memory.limit", "falak.workload.pids.limit"} {
		if metricByName(blog, m) != nil {
			t.Fatalf("user workloads have no %s", m)
		}
	}

	steps := []struct {
		name   string
		change func()
		cpu    float64
		write  int64
	}{
		{"processes advance", func() {
			writeProc(t, root, 10, 1001, 1000, 150, 50, 100, 4096, 8192)
			writeProc(t, root, 11, 1001, 2000, 300, 50, 200, 0, 8192)
		}, 1.5, 4096},
		{"a worker exits: what it used since stays in the counter", func() {
			os.RemoveAll(filepath.Join(root, "proc/11"))
		}, 1.5, 4096},
		{"a new worker counts fully", func() {
			writeProc(t, root, 12, 1001, 2000, 20, 0, 900, 0, 100)
		}, 1.7, 4196},
		{"a reused pid is a new process", func() {
			os.RemoveAll(filepath.Join(root, "proc/12"))
			writeProc(t, root, 12, 1001, 2000, 5, 0, 950, 0, 0)
		}, 1.75, 4196},
		{"an unreadable /etc/passwd keeps the users and their counters", func() {
			os.Remove(filepath.Join(root, "etc/passwd"))
			writeProc(t, root, 10, 1001, 1000, 160, 50, 100, 4096, 8192)
		}, 1.85, 4196},
	}
	for _, s := range steps {
		t.Run(s.name, func(t *testing.T) {
			s.change()
			rms := c.Collect(context.Background())
			if got := cpu(rms, "blog"); got < s.cpu-1e-9 || got > s.cpu+1e-9 {
				t.Fatalf("cpu %v want %v", got, s.cpu)
			}
			if got := pointWith(resourceBy(rms, "service.name", "blog"), "falak.workload.disk.io", "disk.io.direction", "write").GetAsInt(); got != s.write {
				t.Fatalf("write %d want %d", got, s.write)
			}
		})
	}

	t.Run("an empty site slice leaves the site to its user", func(t *testing.T) {
		files := cgroupFiles("sys/fs/cgroup/falak.slice/falak-site_blog.slice", "1", "max 100000\n", "max\n")
		files["sys/fs/cgroup/falak.slice/falak-site_blog.slice/pids.current"] = "0\n"
		writeTree(t, root, files)
		rms := c.Collect(context.Background())
		blog := resourceBy(rms, "service.name", "blog")
		if pointWith(blog, "falak.workload.cpu.time", "falak.workload.kind", "slice") != nil || pointWith(blog, "falak.workload.cpu.time", "falak.workload.kind", "user") == nil {
			t.Fatal("the user is measured while the slice is empty")
		}
	})

	t.Run("site moved into a slice stops the per-user pass", func(t *testing.T) {
		writeTree(t, root, cgroupFiles("sys/fs/cgroup/falak.slice/falak-site_blog.slice", "1", "max 100000\n", "max\n"))
		rms := c.Collect(context.Background())
		blog := resourceBy(rms, "service.name", "blog")
		if pointWith(blog, "falak.workload.cpu.time", "falak.workload.kind", "user") != nil || pointWith(blog, "falak.workload.cpu.time", "falak.workload.kind", "slice") == nil {
			t.Fatal("the slice wins")
		}
		if _, ok := c.users[1001]; ok {
			t.Fatal("counter of a uid no longer measured is dropped")
		}
	})
}

func TestWorkloadUsersSharedUser(t *testing.T) {
	root := t.TempDir()
	writeTree(t, root, map[string]string{"etc/passwd": "falak:x:1000:1000::/:/bin/bash\nshop:x:1001:1001::/:/bin/bash\n"})
	writeProc(t, root, 10, 1000, 1000, 100, 0, 100, 0, 0)
	writeProc(t, root, 11, 1001, 1000, 100, 0, 100, 0, 0)
	files := cgroupFiles("sys/fs/cgroup/falak.slice/falak-site_limited.slice", "1", "max 100000\n", "max\n")
	files["sys/fs/cgroup/falak.slice/falak-site_limited.slice/pids.current"] = "0\n"
	writeTree(t, root, files)
	c := NewWorkloadCollector(hostfs.FS{Root: root}, nil, nil)
	c.SetSites([]otlp.Site{
		{Slug: "blog", SiteID: "S1", UnixUser: "falak", Runtime: "php-fpm"},
		{Slug: "docs", SiteID: "S2", UnixUser: "falak", Runtime: "static"},
		{Slug: "limited", SiteID: "S3", UnixUser: "falak", Runtime: "php-fpm"},
		{Slug: "shop", SiteID: "S4", UnixUser: "shop", Runtime: "php-fpm"},
	})
	rms := c.Collect(context.Background())
	for _, slug := range []string{"blog", "docs"} {
		if resourceBy(rms, "service.name", slug) != nil {
			t.Fatalf("%s shares its user: it would report every sharing site's processes", slug)
		}
	}
	// An empty slice of a site without a user of its own is still its only measurement.
	if pointWith(resourceBy(rms, "service.name", "limited"), "falak.workload.cpu.time", "falak.workload.kind", "slice") == nil {
		t.Fatal("limited keeps its slice")
	}
	if pointWith(resourceBy(rms, "service.name", "shop"), "falak.workload.cpu.time", "falak.workload.kind", "user") == nil {
		t.Fatal("an isolated site is measured by its user")
	}
}
