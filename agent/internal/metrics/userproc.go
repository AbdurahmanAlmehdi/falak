package metrics

import (
	"bufio"
	"bytes"
	"os"
	"path"
	"strconv"
	"strings"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
)

// userHZ is the kernel's USER_HZ: the unit of utime/stime in /proc/<pid>/stat (100 on every Linux ABI the agent
// ships for).
const userHZ = 100

// procTimes is what the per-user pass keeps of one process between two passes: its start time (so a reused pid is
// told apart) and its cumulative counters.
type procTimes struct {
	start              uint64
	ticks, read, write uint64
}

// userTotals are the per-user pass's values for one unix user: counters as monotonic sums (see userCounter) and the
// current gauges.
type userTotals struct {
	cpuTicks, read, write uint64
	rss                   int64
	pids                  int64
}

// userCounter keeps a unix user's counters monotonic across process exits. The sum over live processes drops when one
// exits (its CPU time and I/O leave /proc with it); the last values seen of every process that went away are folded
// into a running offset, so the reported value = offset + live sum never goes backwards and rate() stays right. Only a
// process's own utime/stime count, never cutime/cstime: a reaped child's time already came in through its own pid.
//
// A counter starts at zero: the processes already running when it is created (agent start, a site entering the
// per-user pass) count only what they use from then on (base), so the series does not jump by their whole lifetime
// (rate() would read a long-running PHP-FPM master's hours of CPU time as one interval's). Processes born later count
// fully.
type userCounter struct {
	procs                     map[int]procTimes
	base                      map[int]procTimes // by pid: the values of the processes running at creation
	offTicks, offRead, offWrt uint64
}

// since is what p used after the counter's creation (all of it for a process born later).
func (c *userCounter) since(pid int, p procTimes) procTimes {
	b, ok := c.base[pid]
	if !ok || b.start != p.start {
		return p
	}
	sub := func(a, b uint64) uint64 {
		if a < b {
			return 0
		}
		return a - b
	}
	return procTimes{start: p.start, ticks: sub(p.ticks, b.ticks), read: sub(p.read, b.read), write: sub(p.write, b.write)}
}

// passwdUIDs maps user names to uids from /etc/passwd (under fs: the host's, also when the agent runs in a container).
func passwdUIDs(fs hostfs.FS) map[string]int {
	b, err := fs.ReadFile("/etc/passwd")
	if err != nil {
		return nil
	}
	out := map[string]int{}
	sc := bufio.NewScanner(bytes.NewReader(b))
	for sc.Scan() {
		f := strings.SplitN(sc.Text(), ":", 4)
		if len(f) < 3 || strings.HasPrefix(f[0], "#") {
			continue
		}
		if uid, err := strconv.Atoi(f[2]); err == nil {
			out[f[0]] = uid
		}
	}
	return out
}

// parseProcStatus returns the real uid and RssAnon (bytes; 0 for kernel threads) of /proc/<pid>/status. RssAnon, not
// VmRSS: VmRSS counts the shared pages (OPcache's shared memory, the PHP binary and its libraries) in every worker that
// touched them, so summing it over a pool counts them once per worker.
func parseProcStatus(b []byte) (uid int, rss int64, ok bool) {
	sc := bufio.NewScanner(bytes.NewReader(b))
	for sc.Scan() {
		k, v, found := strings.Cut(sc.Text(), ":")
		if !found {
			continue
		}
		f := strings.Fields(v)
		if len(f) == 0 {
			continue
		}
		switch k {
		case "Uid":
			n, err := strconv.Atoi(f[0])
			if err != nil {
				return 0, 0, false
			}
			uid, ok = n, true
		case "RssAnon":
			n, _ := strconv.ParseInt(f[0], 10, 64)
			rss = n * 1024
		}
	}
	return uid, rss, ok
}

// parseProcStat returns utime+stime (ticks) and starttime of /proc/<pid>/stat. The command name (field 2) may hold
// spaces and parentheses, so fields are counted from the last ')'.
func parseProcStat(b []byte) (ticks, start uint64, ok bool) {
	i := bytes.LastIndexByte(b, ')')
	if i < 0 {
		return 0, 0, false
	}
	f := strings.Fields(string(b[i+1:]))
	// f[0] is field 3 (state): utime = field 14, stime = 15, starttime = 22.
	if len(f) < 20 {
		return 0, 0, false
	}
	ut, err1 := strconv.ParseUint(f[11], 10, 64)
	st, err2 := strconv.ParseUint(f[12], 10, 64)
	start, err3 := strconv.ParseUint(f[19], 10, 64)
	if err1 != nil || err2 != nil || err3 != nil {
		return 0, 0, false
	}
	return ut + st, start, true
}

// parseProcIO returns read_bytes and write_bytes (storage I/O, not the page cache) of /proc/<pid>/io.
func parseProcIO(b []byte) (read, write uint64) {
	kv := map[string]uint64{}
	sc := bufio.NewScanner(bytes.NewReader(b))
	for sc.Scan() {
		k, v, ok := strings.Cut(sc.Text(), ":")
		if !ok {
			continue
		}
		n, _ := strconv.ParseUint(strings.TrimSpace(v), 10, 64)
		kv[k] = n
	}
	return kv["read_bytes"], kv["write_bytes"]
}

// scanProcs is one pass over /proc for every wanted uid: status of every process (the uid is only there), then stat
// and io of the matching ones. owner names the counter a process counts under ("" = none, e.g. in a container): a
// uid's own key for an isolated site's user, a site's key for a process of a shared user attributed to that site.
// counters carries each key's state
// between passes (created on demand for every wanted key, dropped for keys no longer wanted). Processes that vanish
// between listing and reading are skipped.
func scanProcs(fs hostfs.FS, uids map[int]bool, owner func(uid, pid int, start uint64) string, wanted map[string]bool, counters map[string]*userCounter) map[string]userTotals {
	live := map[string]map[int]procTimes{}
	out := map[string]userTotals{}
	for key := range wanted {
		live[key] = map[int]procTimes{}
	}
	entries, _ := os.ReadDir(fs.P("/proc"))
	for _, e := range entries {
		pid, err := strconv.Atoi(e.Name())
		if err != nil || pid <= 0 {
			continue
		}
		dir := "/proc/" + e.Name()
		b, err := fs.ReadFile(dir + "/status")
		if err != nil {
			continue
		}
		uid, rss, ok := parseProcStatus(b)
		if !ok || !uids[uid] {
			continue
		}
		b, err = fs.ReadFile(dir + "/stat")
		if err != nil {
			continue
		}
		ticks, start, ok := parseProcStat(b)
		if !ok {
			continue
		}
		key := owner(uid, pid, start)
		if !wanted[key] {
			continue
		}
		p := procTimes{start: start, ticks: ticks}
		if b, err := fs.ReadFile(dir + "/io"); err == nil {
			p.read, p.write = parseProcIO(b)
		}
		live[key][pid] = p
		t := out[key]
		t.rss += rss
		t.pids++
		out[key] = t
	}
	for key := range counters {
		if !wanted[key] {
			delete(counters, key)
		}
	}
	for key, procs := range live {
		c := counters[key]
		if c == nil {
			c = &userCounter{base: make(map[int]procTimes, len(procs))}
			for pid, p := range procs {
				c.base[pid] = p
			}
			counters[key] = c
		}
		for pid, old := range c.procs {
			if cur, ok := procs[pid]; ok && cur.start == old.start {
				continue
			}
			gone := c.since(pid, old)
			c.offTicks += gone.ticks
			c.offRead += gone.read
			c.offWrt += gone.write
			delete(c.base, pid)
		}
		c.procs = procs
		t := out[key]
		t.cpuTicks, t.read, t.write = c.offTicks, c.offRead, c.offWrt
		for pid, p := range procs {
			p = c.since(pid, p)
			t.cpuTicks += p.ticks
			t.read += p.read
			t.write += p.write
		}
		out[key] = t
	}
	return out
}

// fpmPoolPrefix starts the process title PHP-FPM gives a pool's workers ("php-fpm: pool <name>"); its master is
// "php-fpm: master process (...)", which a freshly forked child still carries until it titles itself.
const (
	fpmTitlePrefix = "php-fpm: "
	fpmPoolPrefix  = fpmTitlePrefix + "pool "
)

// procOrigin is what the per-user pass learns of a process once (pid + start time): whether it runs in a container
// (a container's processes can run as a site user's uid, they are never a native site's), and for a shared user's
// process the PHP-FPM pool it works for, else the site directory its working directory lies in ("" when neither).
type procOrigin struct {
	start     uint64
	container bool
	pool, dir string
	// retry: a PHP-FPM process not titled with its pool yet (a child forked from the master, before its
	// setproctitle): not cached, read again next pass.
	retry bool
}

// containerCgroups are what a process's cgroup path holds when a container runtime started it: Docker (cgroupfs
// "/docker/<id>", systemd "docker-<id>.scope"), containerd, Kubernetes and Podman.
var containerCgroups = []string{"/docker/", "docker-", "containerd", "kubepods", "libpod"}

// inContainer reports whether /proc/<pid>/cgroup places the process in a container; ok is false when it cannot be read
// (the process is exiting, or a transient error): unknown, not "on the host".
func inContainer(fs hostfs.FS, dir string) (in, ok bool) {
	b, err := fs.ReadFile(dir + "/cgroup")
	if err != nil {
		return false, false
	}
	for _, m := range containerCgroups {
		if bytes.Contains(b, []byte(m)) {
			return true, true
		}
	}
	return false, true
}

// readOrigin reads where a process comes from: its container, its PHP-FPM pool from its title (argv[0] of
// /proc/<pid>/cmdline), else the first path segment of its working directory under one of sitesRoots (/proc/<pid>/cwd:
// a host path, also when fs is re-rooted; the sites root as configured and as its symlinks resolve).
func readOrigin(fs hostfs.FS, sitesRoots []string, pid int, start uint64) procOrigin {
	o := procOrigin{start: start}
	dir := "/proc/" + strconv.Itoa(pid)
	in, ok := inContainer(fs, dir)
	if !ok {
		// Unknown: counted for no site this pass and read again next pass (never cached as a host process).
		o.container, o.retry = true, true
		return o
	}
	if o.container = in; o.container {
		return o
	}
	if b, err := fs.ReadFile(dir + "/cmdline"); err == nil {
		arg0, _, _ := bytes.Cut(b, []byte{0})
		title := strings.TrimSpace(string(arg0))
		if pool, ok := strings.CutPrefix(title, fpmPoolPrefix); ok {
			o.pool = strings.TrimSpace(pool)
			return o
		}
		if strings.HasPrefix(title, fpmTitlePrefix) {
			o.retry = true
			return o
		}
	}
	target, err := fs.Readlink(dir + "/cwd")
	if err != nil {
		return o
	}
	// A working directory removed since (a pruned release) reads "<path> (deleted)".
	target = path.Clean(strings.TrimSuffix(target, " (deleted)"))
	for _, root := range sitesRoots {
		if rel, ok := strings.CutPrefix(target, strings.TrimSuffix(root, "/")+"/"); ok {
			o.dir, _, _ = strings.Cut(rel, "/")
			return o
		}
	}
	return o
}
