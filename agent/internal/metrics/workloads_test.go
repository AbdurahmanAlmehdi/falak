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
	// Every process has a cgroup; a host one unless the test gave it another (kept across updates).
	if _, err := os.Stat(filepath.Join(root, fmt.Sprintf("proc/%d/cgroup", pid))); err != nil {
		writeTree(t, root, map[string]string{fmt.Sprintf("proc/%d/cgroup", pid): "0::/system.slice/php8.4-fpm.service\n"})
	}
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
		{Slug: "blog", SiteID: "S1", UnixUser: "blog", Isolated: true, Runtime: "php-fpm"},
		{Slug: "api", SiteID: "S2", UnixUser: "api", Isolated: true, Runtime: "node"},
		{Slug: "dock", SiteID: "S3", UnixUser: "dock", Isolated: true, Runtime: "docker"},
		{Slug: "old", SiteID: "S4"}, // no unix_user (older control plane): skipped
		{Slug: "ghost", SiteID: "S5", UnixUser: "ghost", Isolated: true, Runtime: "static"}, // no such user: skipped
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
		if _, ok := c.users[userKey(1001)]; ok {
			t.Fatal("counter of a uid no longer measured is dropped")
		}
	})
}

// writeOrigin gives a fake process a command line (NUL-separated, as the kernel has it) and, unless cwd is "", a
// working directory: like /proc/<pid>/cwd, a symlink to a host path (dangling under the temp root).
func writeOrigin(t *testing.T, root string, pid int, cmdline, cwd string) {
	t.Helper()
	writeTree(t, root, map[string]string{fmt.Sprintf("proc/%d/cmdline", pid): cmdline})
	if cwd == "" {
		return
	}
	link := filepath.Join(root, fmt.Sprintf("proc/%d/cwd", pid))
	os.Remove(link)
	if err := os.Symlink(cwd, link); err != nil {
		t.Fatal(err)
	}
}

func TestWorkloadUsersSharedUser(t *testing.T) {
	root := t.TempDir()
	writeTree(t, root, map[string]string{"etc/passwd": "root:x:0:0::/root:/bin/bash\nfalak:x:1000:1000::/:/bin/bash\niso:x:1001:1001::/:/bin/bash\n"})
	files := cgroupFiles("sys/fs/cgroup/falak.slice/falak-site_limited.slice", "1", "max 100000\n", "max\n")
	files["sys/fs/cgroup/falak.slice/falak-site_limited.slice/pids.current"] = "0\n"
	writeTree(t, root, files)
	type proc struct {
		pid, uid     int
		rssKB        int64
		cmdline, cwd string
	}
	for _, p := range []proc{
		{1, 0, 9000, "php-fpm: master process (/etc/php/8.3/fpm/php-fpm.conf)\x00\x00\x00", "/"}, // shared master: root's
		{10, 1000, 1000, "php-fpm: pool falak-backend\x00\x00\x00\x00", "/"},                     // pool title wins over cwd
		{11, 1000, 2000, "php-fpm: pool falak-backend   ", ""},
		{12, 1000, 400, "php\x00artisan\x00horizon\x00", "/srv/falak/sites/backend/releases/01J"},
		{13, 1000, 300, "node\x00server.js\x00", "/srv/falak/sites/shop2/current"},     // shop2, not shop
		{14, 1000, 200, "node\x00server.js\x00", "/srv/falak/sites/shop"},              // the site directory itself
		{15, 1000, 50, "sshd: falak@pts/0\x00", "/home/falak"},                         // unattributable: ignored
		{16, 1000, 50, "/lib/systemd/systemd\x00--user\x00", "/"},                      // ignored
		{17, 1000, 50, "php\x00artisan\x00tinker\x00", "/srv/falak/sites/iso/current"}, // iso does not run as falak
		{18, 1000, 100, "php-fpm: pool falak-limited\x00", "/"},                        // limited: its slice is empty
		{19, 1000, 50, "bash\x00", "/srv/falak/sitesx/backend"},                        // not under the sites root
		{20, 1001, 700, "php-fpm: pool falak-iso\x00", "/"},                            // an isolated site's own user
		{21, 1000, 100, "php\x00artisan\x00queue:work\x00", "/srv/falak/sites/backend/releases/00X (deleted)"},
		{22, 1000, 100, "php-fpm: pool falak-gone\x00", "/"}, // a pool of no configured site
		// A container's processes running as falak's / iso's uid: never a native site's.
		{23, 1000, 100, "php-fpm: pool www\x00", "/srv/falak/sites/backend/current"},
		{24, 1000, 100, "node\x00server.js\x00", "/srv/falak/sites/shop/current"},
		{25, 1001, 100, "php-fpm: pool falak-iso\x00", "/"},
		// A worker the master forked that has not titled itself yet: retried, not cached as unattributable.
		{26, 1000, 100, "php-fpm: master process (/etc/php/8.3/fpm/php-fpm.conf)\x00", "/"},
	} {
		writeProc(t, root, p.pid, p.uid, p.rssKB, 100, 0, uint64(100+p.pid), 0, 0)
		writeOrigin(t, root, p.pid, p.cmdline, p.cwd)
		writeTree(t, root, map[string]string{fmt.Sprintf("proc/%d/cgroup", p.pid): "0::/user.slice/user-1000.slice/session-3.scope\n"})
	}
	writeTree(t, root, map[string]string{
		"proc/23/cgroup": "0::/system.slice/docker-4f1c2d.scope\n",
		"proc/24/cgroup": "12:pids:/docker/4f1c2d\n0::/docker/4f1c2d\n",
		"proc/25/cgroup": "0::/kubepods/besteffort/pod1/4f1c2d\n",
	})
	c := NewWorkloadCollector(hostfs.FS{Root: root}, nil, nil)
	c.SitesRoot = "/srv/falak/sites/"
	c.SetSites([]otlp.Site{
		{Slug: "backend", SiteID: "S1", UnixUser: "falak", Runtime: "php-fpm"},
		{Slug: "storefront", SiteID: "S2", UnixUser: "falak", Runtime: "static"},
		{Slug: "shop", SiteID: "S3", UnixUser: "falak", Runtime: "node"},
		{Slug: "shop2", SiteID: "S4", UnixUser: "falak", Runtime: "node"},
		{Slug: "limited", SiteID: "S5", UnixUser: "falak", Runtime: "php-fpm"},
		{Slug: "iso", SiteID: "S6", UnixUser: "iso", Isolated: true, Runtime: "php-fpm"},
		{Slug: "dock", SiteID: "S7", UnixUser: "falak", Runtime: "docker"}, // containers: never per process
	})
	procs := func(rms []*metricspb.ResourceMetrics, slug string) (cpu float64, rss, pids int64) {
		rm := resourceBy(rms, "service.name", slug)
		p := pointWith(rm, "falak.workload.cpu.time", "falak.workload.kind", "processes", "falak.workload.name", slug)
		if p == nil {
			t.Fatalf("%s: no processes workload", slug)
		}
		return p.GetAsDouble(), pointWith(rm, "falak.workload.memory.usage", "falak.memory.type", "used").GetAsInt(), pointWith(rm, "falak.workload.pids").GetAsInt()
	}

	rms := c.Collect(context.Background())
	if resourceBy(rms, "service.name", "dock") != nil {
		t.Fatal("a docker site is never measured per process")
	}
	for _, tc := range []struct {
		slug      string
		rssKB     int64
		pids      int64
		processes string
	}{
		{"backend", 1000 + 2000 + 400 + 100, 4, "pool workers by title, horizon and a worker in a removed release by cwd"},
		{"storefront", 0, 0, "a static site has no process: zeros"},
		{"shop", 200, 1, "cwd is the site directory"},
		{"shop2", 300, 1, "shop2's process is not shop's"},
		{"limited", 100, 1, "an empty slice leaves the site to its processes"},
	} {
		t.Run(tc.slug, func(t *testing.T) {
			cpu, rss, pids := procs(rms, tc.slug)
			// Counters start at zero (the processes' earlier 1 s each happened before the agent looked).
			if cpu != 0 || rss != tc.rssKB*1024 || pids != tc.pids {
				t.Fatalf("%s: cpu %v rss %d pids %d", tc.processes, cpu, rss, pids)
			}
			if pointWith(resourceBy(rms, "service.name", tc.slug), "falak.workload.cpu.time", "falak.workload.kind", "user") != nil {
				t.Fatal("a shared user is never measured whole")
			}
		})
	}
	if pointWith(resourceBy(rms, "service.name", "limited"), "falak.workload.cpu.time", "falak.workload.kind", "slice") != nil {
		t.Fatal("the empty slice is skipped")
	}
	iso := resourceBy(rms, "service.name", "iso")
	if pointWith(iso, "falak.workload.cpu.time", "falak.workload.kind", "user", "falak.workload.name", "iso") == nil || pointWith(iso, "falak.workload.pids").GetAsInt() != 1 {
		t.Fatal("an isolated site is measured by its whole user")
	}
	if _, ok := c.origins[1]; ok {
		t.Fatal("root's processes are not read")
	}
	if o, ok := c.origins[23]; !ok || !o.container || !c.origins[25].container || c.origins[20].container {
		t.Fatal("container processes are told apart once, by their cgroup")
	}
	if _, ok := c.origins[26]; ok {
		t.Fatal("an FPM process without its pool title yet is not cached")
	}

	steps := []struct {
		name    string
		change  func()
		backend float64
		shop2   float64
	}{
		{"processes advance", func() {
			writeProc(t, root, 10, 1000, 1000, 150, 0, 110, 0, 0)
			writeProc(t, root, 11, 1000, 2000, 120, 0, 111, 0, 0)
			writeProc(t, root, 13, 1000, 300, 130, 0, 113, 0, 0)
			writeProc(t, root, 15, 1000, 50, 900, 0, 115, 0, 0) // ssh: never counted
		}, 0.7, 0.3},
		{"an exited worker keeps only what it used since", func() {
			os.RemoveAll(filepath.Join(root, "proc/11"))
		}, 0.7, 0.3},
		{"a new worker counts fully", func() {
			writeProc(t, root, 30, 1000, 1000, 40, 0, 500, 0, 0)
			writeOrigin(t, root, 30, "php-fpm: pool falak-backend\x00", "/")
		}, 1.1, 0.3},
		{"the origin is read once per process", func() {
			writeOrigin(t, root, 10, "something else\x00", "/home/falak")
			writeProc(t, root, 10, 1000, 1000, 160, 0, 110, 0, 0)
		}, 1.2, 0.3},
		{"a reused pid is a new process, attributed again", func() {
			os.RemoveAll(filepath.Join(root, "proc/13"))
			writeProc(t, root, 13, 1000, 300, 10, 0, 600, 0, 0)
			writeOrigin(t, root, 13, "php\x00artisan\x00horizon\x00", "/srv/falak/sites/backend/current")
		}, 1.3, 0.3},
		{"a forked FPM worker is attributed once it has its pool title", func() {
			writeOrigin(t, root, 26, "php-fpm: pool falak-backend\x00", "/")
			writeProc(t, root, 26, 1000, 100, 150, 0, 126, 0, 0)
		}, 2.8, 0.3},
	}
	for _, s := range steps {
		t.Run(s.name, func(t *testing.T) {
			s.change()
			rms := c.Collect(context.Background())
			if got, _, _ := procs(rms, "backend"); got < s.backend-1e-9 || got > s.backend+1e-9 {
				t.Fatalf("backend cpu %v want %v", got, s.backend)
			}
			if got, _, _ := procs(rms, "shop2"); got < s.shop2-1e-9 || got > s.shop2+1e-9 {
				t.Fatalf("shop2 cpu %v want %v", got, s.shop2)
			}
		})
	}
	if _, ok := c.origins[11]; ok {
		t.Fatal("an exited process's origin is dropped")
	}

	t.Run("a site moved into a slice is measured by it, its processes no other site's", func(t *testing.T) {
		writeTree(t, root, cgroupFiles("sys/fs/cgroup/falak.slice/falak-site_backend.slice", "1", "max 100000\n", "max\n"))
		rms := c.Collect(context.Background())
		backend := resourceBy(rms, "service.name", "backend")
		if pointWith(backend, "falak.workload.cpu.time", "falak.workload.kind", "processes") != nil || pointWith(backend, "falak.workload.cpu.time", "falak.workload.kind", "slice") == nil {
			t.Fatal("the slice wins")
		}
		if _, _, pids := procs(rms, "storefront"); pids != 0 {
			t.Fatalf("storefront got backend's processes: %d", pids)
		}
		if _, ok := c.users[siteKey("backend")]; ok {
			t.Fatal("the counter of a site no longer measured by processes is dropped")
		}
	})
}

func TestWorkloadUsersLoneSharedSiteAndLinkedRoot(t *testing.T) {
	root := t.TempDir()
	writeTree(t, root, map[string]string{"etc/passwd": "root:x:0:0::/root:/bin/bash\nfalak:x:1000:1000::/home/falak:/bin/bash\n"})
	for _, p := range []struct {
		pid          int
		cmdline, cwd string
	}{
		{10, "php-fpm: pool falak-blog\x00", "/"},
		{11, "php\x00artisan\x00queue:work\x00", "/data/srv/falak/sites/blog/current"}, // cwd as the kernel resolves it
		{12, "-bash\x00", "/home/falak"},                                               // an admin's login session
		{13, "tmux\x00", "/"},
		{14, "npm\x00run\x00build\x00", "/data/srv/falak/sites/dock/releases/01J"}, // a docker site's deploy hook
	} {
		writeProc(t, root, p.pid, 1000, 100, 100, 0, uint64(100+p.pid), 0, 0)
		writeOrigin(t, root, p.pid, p.cmdline, p.cwd)
	}
	c := NewWorkloadCollector(hostfs.FS{Root: root}, nil, nil)
	c.SitesRoot = "/srv/falak/sites"
	// The only native site, not isolated: its user is the servers' shared login user, so only its own processes count.
	c.SetSites([]otlp.Site{
		{Slug: "blog", SiteID: "S1", UnixUser: "falak", Runtime: "php-fpm"},
		{Slug: "dock", SiteID: "S2", Runtime: "docker"},
	})
	pids := func() int64 {
		rm := resourceBy(c.Collect(context.Background()), "service.name", "blog")
		if pointWith(rm, "falak.workload.cpu.time", "falak.workload.kind", "user") != nil {
			t.Fatal("a non-isolated site is never measured by its whole user")
		}
		return pointWith(rm, "falak.workload.pids", "falak.workload.kind", "processes").GetAsInt()
	}
	// /srv is not a link yet: only the pool worker is the site's (the cwd under /data/srv matches no root).
	if got := pids(); got != 1 {
		t.Fatalf("pids %d", got)
	}
	// /srv -> /data/srv: the resolution failed before, so it is tried again. Resolving it clears the cached misses: the
	// queue worker (cwd under /data/srv) is read again and matches, as do new processes.
	if err := os.MkdirAll(filepath.Join(root, "data/srv/falak/sites"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink("/data/srv", filepath.Join(root, "srv")); err != nil {
		t.Fatal(err)
	}
	writeProc(t, root, 15, 1000, 100, 100, 0, 115, 0, 0)
	writeOrigin(t, root, 15, "php\x00artisan\x00schedule:work\x00", "/data/srv/falak/sites/blog/current")
	if got := pids(); got != 3 {
		t.Fatalf("pids %d (pool worker, queue worker, scheduler)", got)
	}
	if c.rootResolved != "/data/srv/falak/sites" {
		t.Fatalf("resolved %q", c.rootResolved)
	}
	// A process working under the configured (unresolved) path still matches.
	writeProc(t, root, 16, 1000, 100, 100, 0, 116, 0, 0)
	writeOrigin(t, root, 16, "node\x00x\x00", "/srv/falak/sites/blog/current")
	if got := pids(); got != 4 {
		t.Fatalf("pids %d", got)
	}
	// Another sites root: every origin is read again against it (none is under /opt/sites).
	c.mu.Lock()
	c.SitesRoot = "/opt/sites"
	c.mu.Unlock()
	if got := pids(); got != 1 {
		t.Fatalf("pids %d (only the pool worker, by its title)", got)
	}
}

func TestWorkloadUsersUnreadableCgroup(t *testing.T) {
	root := t.TempDir()
	writeTree(t, root, map[string]string{"etc/passwd": "falak:x:1000:1000::/home/falak:/bin/bash\n"})
	writeProc(t, root, 10, 1000, 100, 100, 0, 110, 0, 0)
	writeOrigin(t, root, 10, "php-fpm: pool falak-blog\x00", "/")
	os.Remove(filepath.Join(root, "proc/10/cgroup")) // the process is exiting, or the read failed
	c := NewWorkloadCollector(hostfs.FS{Root: root}, nil, nil)
	c.SitesRoot = "/srv/falak/sites"
	c.SetSites([]otlp.Site{{Slug: "blog", SiteID: "S1", UnixUser: "falak", Runtime: "php-fpm"}})
	pids := func() int64 {
		rm := resourceBy(c.Collect(context.Background()), "service.name", "blog")
		return pointWith(rm, "falak.workload.pids", "falak.workload.kind", "processes").GetAsInt()
	}
	if got := pids(); got != 0 {
		t.Fatalf("an unknown cgroup counts for no site; pids %d", got)
	}
	if _, cached := c.origins[10]; cached {
		t.Fatal("an unknown cgroup is not cached")
	}
	writeTree(t, root, map[string]string{"proc/10/cgroup": "0::/system.slice/php8.4-fpm.service\n"})
	if got := pids(); got != 1 {
		t.Fatalf("read again next pass; pids %d", got)
	}
}

func TestNativeUsersModes(t *testing.T) {
	own, shared := nativeUsers([]otlp.Site{
		{Slug: "iso", UnixUser: "iso", Isolated: true},
		{Slug: "lone", UnixUser: "falak"},                    // non-isolated, alone: still per process
		{Slug: "twin-a", UnixUser: "twin", Isolated: true},   // an isolated user claimed twice: per process
		{Slug: "twin-b", UnixUser: "twin", Isolated: true},   //
		{Slug: "fn", UnixUser: "falak", Runtime: "function"}, // containers: never per user
		{Slug: "legacy", UnixUser: "legacy"},                 // no isolated flag: per process
		{Slug: "none", Isolated: true, Runtime: "php-fpm"},   // no user
	})
	if len(own) != 1 || own["iso"] != "iso" {
		t.Fatalf("own %v", own)
	}
	if len(shared) != 3 || len(shared["falak"]) != 1 || len(shared["twin"]) != 2 || len(shared["legacy"]) != 1 {
		t.Fatalf("shared %v", shared)
	}
}
