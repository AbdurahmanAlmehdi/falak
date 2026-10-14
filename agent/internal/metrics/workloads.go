package metrics

import (
	"context"
	"os"
	"path"
	"sort"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/cgroup"
	"github.com/OthmanHaba/falak/agent/internal/docker"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/otlp"
	"github.com/OthmanHaba/falak/agent/internal/runtime"
	commonpb "go.opentelemetry.io/proto/otlp/common/v1"
	metricspb "go.opentelemetry.io/proto/otlp/metrics/v1"
	resourcepb "go.opentelemetry.io/proto/otlp/resource/v1"
)

// ContainerEngine is the Engine API subset the workload collector needs (docker.Client). Each interval makes one
// ContainerList call; ContainerInspect runs once per container (and again after it restarted), ContainerStats only on
// cgroup v1 hosts.
type ContainerEngine interface {
	ContainerList(ctx context.Context, all bool, labels []string) ([]docker.ContainerSummary, error)
	ContainerInspect(ctx context.Context, nameOrID string) (*docker.Container, bool, error)
	ContainerStats(ctx context.Context, id string, oneShot bool) (docker.Stats, error)
}

// Labels and names the collector attributes workloads by.
const (
	labelSite       = "falak.site"
	labelService    = "falak.service"
	labelDBInstance = "falak.db.instance" // db.LabelInstance (the db package imports this one)
	dbService       = "falak-db"
	cgroupFSRoot    = "/sys/fs/cgroup"
)

// containerRuntimes are the SiteRuntime values measured through their containers, never per user.
var containerRuntimes = map[string]bool{"docker": true, "compose": true, "function": true}

// WorkloadCollector samples every workload on the server into falak.workload.* metrics (docs/plans/OBSERVABILITY.md,
// phase 1): site and database containers and falak slices from their cgroup v2 directories, native sites without a
// slice from their unix user's processes (a user several sites share: each process attributed to one of them). One
// ResourceMetrics per owner: service.name = the site slug (enriched to falak.site.id by the relay), "falak-db" with
// falak.db.instance.id, or "falak-agent" with falak.slice for a worker / daemon slice. Data points carry
// falak.workload.kind (container | slice | user | processes) and falak.workload.name.
type WorkloadCollector struct {
	// Socket, when set, is checked before each collection (Docker may be installed after the agent started).
	Socket string
	// SitesRoot is the host directory holding every native site's tree (/srv/falak/sites): a shared user's process
	// working under <SitesRoot>/<slug>/ belongs to that site.
	SitesRoot string
	fs        hostfs.FS
	engine    ContainerEngine // nil: no Docker on this server, only slices and users
	out       Emitter
	now       func() time.Time

	enabled  atomic.Bool
	interval atomic.Int64
	wake     chan struct{}

	mu      sync.Mutex
	sites   []otlp.Site
	cts     map[string]*containerInfo // by container id, dropped when it is no longer listed
	users   map[string]*userCounter   // by counter key: userKey (an isolated site's own user) or siteKey (a shared user's site)
	origins map[int]procOrigin        // by pid: where a measured user's process comes from, read once per process
	uids    map[string]int            // /etc/passwd as last read (kept when a read fails)
	// rootResolved is SitesRoot (as rootOf, cleaned) with its symlinks resolved; "" until that succeeds.
	rootOf, rootResolved string
}

// containerInfo is what the collector learns of a container once (one inspect): where its cgroup lives, its init pid
// (network namespace) and, for cgroup v1 hosts, its limits.
type containerInfo struct {
	dir      string // cgroup v2 directory; "" = none found: Docker stats (cgroup v1)
	pid      int
	pidStart uint64 // the init process's start time (/proc/<pid>/stat), 0 = unknown: tells a reused pid apart
	// Limits from HostConfig, used when there is no cgroup directory to read them from.
	cpuLimit            float64
	memLimit, pidsLimit int64
}

// NewWorkloadCollector creates an enabled collector with a 15 s interval. engine may be nil (no Docker).
func NewWorkloadCollector(fs hostfs.FS, engine ContainerEngine, out Emitter) *WorkloadCollector {
	c := &WorkloadCollector{fs: fs, engine: engine, out: out, now: time.Now, wake: make(chan struct{}, 1),
		cts: map[string]*containerInfo{}, users: map[string]*userCounter{}, origins: map[int]procOrigin{}}
	c.enabled.Store(true)
	c.interval.Store(int64(15 * time.Second))
	return c
}

// Configure hot-reloads enablement and interval (shared with host metrics).
func (c *WorkloadCollector) Configure(enabled bool, interval time.Duration) {
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

// SetSites hot-reloads the server's sites (telemetry.configure): they map site_<slug> slices back to slugs and name
// the unix users measured per user.
func (c *WorkloadCollector) SetSites(sites []otlp.Site) {
	c.mu.Lock()
	c.sites = append([]otlp.Site(nil), sites...)
	c.mu.Unlock()
}

// Sites returns the sites last set.
func (c *WorkloadCollector) Sites() []otlp.Site {
	c.mu.Lock()
	defer c.mu.Unlock()
	return append([]otlp.Site(nil), c.sites...)
}

// Run emits workload metrics every interval until ctx is done.
func (c *WorkloadCollector) Run(ctx context.Context) {
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
			for _, rm := range c.Collect(ctx) {
				c.out.EmitMetrics(rm)
			}
		}
	}
}

// Value presence flags of a usage.
const (
	hasCPU = 1 << iota
	hasThrottle
	hasMem
	hasCache
	hasOOM
	hasIO
	hasNet
	hasPids
)

// usage is one workload's sample, whatever it came from (cgroup, Docker stats, /proc per user). Limits are 0 when
// none is set (no series).
type usage struct {
	has                       int
	cpuSec, throttledSec      float64
	periods, throttledPeriods int64
	memUsed, memCache         int64
	oomKills                  int64
	read, write               int64
	rx, tx                    int64
	pids                      int64
	cpuLimit                  float64
	memLimit, pidsLimit       int64
}

func fromCgroup(s CgroupStats) usage {
	u := usage{cpuLimit: s.CPULimit, memLimit: s.MemLimit, pidsLimit: s.PidsLimit}
	if s.HasCPU {
		u.has |= hasCPU
		u.cpuSec = float64(s.CPUUsageUsec) / 1e6
	}
	if s.HasThrottle {
		u.has |= hasThrottle
		u.periods, u.throttledPeriods, u.throttledSec = int64(s.NrPeriods), int64(s.NrThrottled), float64(s.ThrottledUsec)/1e6
	}
	if s.HasMem {
		u.has |= hasMem | hasCache
		u.memUsed, u.memCache = int64(s.MemUsed()), int64(min(s.MemFile, s.MemCurrent))
	}
	if s.HasOOM {
		u.has |= hasOOM
		u.oomKills = int64(s.OOMKills)
	}
	if s.HasIO {
		u.has |= hasIO
		u.read, u.write = int64(s.ReadBytes), int64(s.WriteBytes)
	}
	if s.HasPids {
		u.has |= hasPids
		u.pids = int64(s.Pids)
	}
	return u
}

type point struct {
	attrs []*commonpb.KeyValue
	u     usage
}

// owner is one ResourceMetrics: a site, a database instance or a slice.
type owner struct {
	res    []*commonpb.KeyValue
	points []point
}

type owners map[string]*owner

func (o owners) add(key string, res []*commonpb.KeyValue, p point) {
	if o[key] == nil {
		o[key] = &owner{res: res}
	}
	o[key].points = append(o[key].points, p)
}

func workloadAttrs(kind, name string, extra ...*commonpb.KeyValue) []*commonpb.KeyValue {
	return append([]*commonpb.KeyValue{str("falak.workload.kind", kind), str("falak.workload.name", name)}, extra...)
}

// Collect samples every workload once.
func (c *WorkloadCollector) Collect(ctx context.Context) []*metricspb.ResourceMetrics {
	c.mu.Lock()
	sites := c.sites
	c.mu.Unlock()
	all := owners{}
	own, shared := nativeUsers(sites)
	withContainers := c.collectContainers(ctx, all)
	withSlice := c.collectSlices(sites, own, shared, withContainers, all)
	c.collectUsers(own, shared, withContainers, withSlice, all)
	return c.encode(all)
}

// nativeUsers sorts the native sites' unix users. own maps an isolated site whose user no other site claims to it
// (slug => user): all that user's processes are the site's. shared maps every other user to the sites that claim it
// (user => slugs): its processes are attributed one by one (collectUsers). A non-isolated site's user is always
// shared, even when it is the only site on the server: it is the user every such site runs as and the servers' login
// user (admin sessions, other sites' deploy hooks), never the site's alone.
func nativeUsers(sites []otlp.Site) (own map[string]string, shared map[string][]string) {
	claims := map[string][]otlp.Site{}
	for _, s := range sites {
		if s.UnixUser != "" && !containerRuntimes[s.Runtime] {
			claims[s.UnixUser] = append(claims[s.UnixUser], s)
		}
	}
	own, shared = map[string]string{}, map[string][]string{}
	for user, ss := range claims {
		if len(ss) == 1 && ss[0].Isolated {
			own[ss[0].Slug] = user
			continue
		}
		for _, s := range ss {
			shared[user] = append(shared[user], s.Slug)
		}
	}
	return own, shared
}

// sitesRoots is SitesRoot as configured and, when it differs, the host path its symlinks resolve to (a /srv linked to
// /data/srv: a process's cwd reads the resolved path). The resolution is kept, and tried again each pass until it
// succeeds. Either change clears the cached origins. Called with c.mu held.
func (c *WorkloadCollector) sitesRoots() []string {
	if c.SitesRoot == "" {
		return nil
	}
	clean := path.Clean(c.SitesRoot)
	if c.rootOf != clean {
		// Origins read against the old root are wrong now: read every process again.
		c.rootOf, c.rootResolved = clean, ""
		clear(c.origins)
	}
	if c.rootResolved == "" {
		if r, err := c.fs.EvalSymlinks(clean); err == nil {
			c.rootResolved = path.Clean(r)
			// A root resolved only now (the link appeared): misses cached against the configured path alone go too.
			clear(c.origins)
		}
	}
	if c.rootResolved == "" || c.rootResolved == clean {
		return []string{clean}
	}
	return []string{clean, c.rootResolved}
}

// Counter keys of the per-user pass.
func userKey(uid int) string     { return "user/" + strconv.Itoa(uid) }
func siteKey(slug string) string { return "site/" + slug }

// collectContainers samples site and database containers; returns the slugs that have containers.
func (c *WorkloadCollector) collectContainers(ctx context.Context, out owners) map[string]bool {
	slugs := map[string]bool{}
	if c.engine == nil {
		return slugs
	}
	if c.Socket != "" {
		if _, err := os.Stat(c.Socket); err != nil {
			return slugs
		}
	}
	// One unfiltered list: Engine label filters are ANDed, and site and database containers carry different labels.
	list, err := c.engine.ContainerList(ctx, false, nil)
	if err != nil {
		return slugs
	}
	seen := map[string]bool{}
	for _, ct := range list {
		site, inst := ct.Labels[labelSite], ct.Labels[labelDBInstance]
		if site == "" && inst == "" {
			continue
		}
		seen[ct.ID] = true
		c.mu.Lock()
		info := c.cts[ct.ID]
		c.mu.Unlock()
		if info == nil {
			if info = c.inspect(ctx, ct.ID); info == nil {
				continue
			}
		}
		u, ok := c.sampleContainer(ctx, ct.ID, info)
		if !ok {
			continue
		}
		name := ct.ID[:min(12, len(ct.ID))]
		if len(ct.Names) > 0 {
			name = strings.TrimPrefix(ct.Names[0], "/")
		}
		attrs := workloadAttrs("container", name, str("container.id", ct.ID))
		service := ct.Labels[labelService]
		if service == "" {
			service = ct.Labels[docker.LabelComposeService]
		}
		if service != "" {
			attrs = append(attrs, str("falak.compose.service", service))
		}
		p := point{attrs: attrs, u: u}
		if inst != "" {
			out.add("db/"+inst, []*commonpb.KeyValue{str("service.name", dbService), str("falak.db.instance.id", inst)}, p)
			continue
		}
		slugs[site] = true
		out.add("site/"+site, []*commonpb.KeyValue{str("service.name", site)}, p)
	}
	c.mu.Lock()
	for id := range c.cts {
		if !seen[id] {
			delete(c.cts, id)
		}
	}
	c.mu.Unlock()
	return slugs
}

// inspect learns a container's cgroup directory, init pid and limits (nil when it cannot be inspected).
func (c *WorkloadCollector) inspect(ctx context.Context, id string) *containerInfo {
	ictx, cancel := context.WithTimeout(ctx, 5*time.Second)
	ct, ok, err := c.engine.ContainerInspect(ictx, id)
	cancel()
	if err != nil || !ok {
		return nil
	}
	info := &containerInfo{dir: c.cgroupDir(id, ct.HostConfig.CgroupParent), pid: ct.State.Pid, memLimit: ct.HostConfig.Memory,
		cpuLimit: float64(ct.HostConfig.NanoCpus) / 1e9}
	info.pidStart, _ = c.procStart(info.pid)
	if p := ct.HostConfig.PidsLimit; p != nil && *p > 0 {
		info.pidsLimit = *p
	}
	c.mu.Lock()
	c.cts[id] = info
	c.mu.Unlock()
	return info
}

// procStart is the start time of a process (false when it is gone or unreadable).
func (c *WorkloadCollector) procStart(pid int) (uint64, bool) {
	if pid <= 0 {
		return 0, false
	}
	b, err := c.fs.ReadFile("/proc/" + strconv.Itoa(pid) + "/stat")
	if err != nil {
		return 0, false
	}
	_, start, ok := parseProcStat(b)
	return start, ok
}

// pidCurrent tells whether a container's cached init pid is still its init process: the pid exists and, when its
// start time was known, started at the same time (after an in-place restart the old pid may already belong to another
// process).
func (c *WorkloadCollector) pidCurrent(info *containerInfo) bool {
	if info.pid <= 0 {
		return false
	}
	if info.pidStart == 0 {
		return c.fs.Exists("/proc/" + strconv.Itoa(info.pid))
	}
	start, ok := c.procStart(info.pid)
	return ok && start == info.pidStart
}

// systemdSlicePath expands a systemd slice name to its place in the cgroup tree: "a-b.slice" lives at
// "a.slice/a-b.slice" (each dash is a level). Anything else is returned as is.
func systemdSlicePath(name string) string {
	base, ok := strings.CutSuffix(name, ".slice")
	if !ok || strings.Contains(name, "/") || base == "" {
		return name
	}
	parts := strings.Split(base, "-")
	dirs := make([]string, 0, len(parts))
	for i := range parts {
		dirs = append(dirs, strings.Join(parts[:i+1], "-")+".slice")
	}
	return path.Join(dirs...)
}

// cgroupDir finds a container's cgroup v2 directory: systemd driver (system.slice/docker-<id>.scope), cgroupfs driver
// (docker/<id>), either under a custom cgroup parent (a systemd slice name is expanded to its nested path). "" = not
// found (cgroup v1 host): Docker stats instead.
func (c *WorkloadCollector) cgroupDir(id, parent string) string {
	var cands []string
	if parent = strings.Trim(parent, "/"); parent != "" {
		parents := []string{parent}
		if nested := systemdSlicePath(parent); nested != parent {
			parents = append(parents, nested)
		}
		for _, p := range parents {
			cands = append(cands, path.Join(cgroupFSRoot, p, "docker-"+id+".scope"), path.Join(cgroupFSRoot, p, id))
		}
	}
	cands = append(cands, path.Join(cgroupFSRoot, "system.slice", "docker-"+id+".scope"), path.Join(cgroupFSRoot, "docker", id))
	for _, d := range cands {
		if c.fs.Exists(path.Join(d, "cpu.stat")) {
			return d
		}
	}
	return ""
}

// sampleContainer reads one container: its cgroup directory and its namespace's net/dev, or Docker stats on cgroup v1.
func (c *WorkloadCollector) sampleContainer(ctx context.Context, id string, info *containerInfo) (usage, bool) {
	if info.dir == "" {
		return c.sampleDockerStats(ctx, id, info)
	}
	s, ok := ReadCgroup(c.fs, info.dir)
	if !ok {
		return usage{}, false
	}
	u := fromCgroup(s)
	pid := info.pid
	if !c.pidCurrent(info) {
		// The cached pid is gone or reused: the container restarted (same id, new init process). Inspect again, once;
		// a pid that is current but whose net/dev cannot be read is not retried (that would be an Engine call per
		// sample).
		// A failed inspect leaves no pid: the stale one may now be a host process, whose net/dev is the host's.
		pid = 0
		if fresh := c.inspect(ctx, id); fresh != nil {
			pid = fresh.pid
		}
	}
	if pid <= 0 {
		return u, true
	}
	if devs, err := readNetDevAt(c.fs, "/proc/"+strconv.Itoa(pid)+"/net/dev"); err == nil {
		u.has |= hasNet
		for _, d := range devs {
			u.rx += int64(d.RxBytes)
			u.tx += int64(d.TxBytes)
		}
	}
	return u, true
}

// sampleDockerStats is the cgroup v1 path: CPU time, memory and network from one-shot Docker stats, limits from the
// inspect.
func (c *WorkloadCollector) sampleDockerStats(ctx context.Context, id string, info *containerInfo) (usage, bool) {
	sctx, cancel := context.WithTimeout(ctx, 5*time.Second)
	st, err := c.engine.ContainerStats(sctx, id, true)
	cancel()
	if err != nil {
		return usage{}, false
	}
	u := usage{has: hasCPU | hasMem | hasNet, cpuSec: float64(st.CPUStats.CPUUsage.TotalUsage) / 1e9,
		memUsed: int64(st.MemoryUsed()), cpuLimit: info.cpuLimit, memLimit: info.memLimit, pidsLimit: info.pidsLimit}
	for _, n := range st.Networks {
		u.rx += int64(n.RxBytes)
		u.tx += int64(n.TxBytes)
	}
	return u, true
}

// collectSlices samples falak.slice's children; returns the slugs that have a site slice. A site_<key> slice whose
// site is not (or no longer) configured is skipped: it has no slug to report under. An empty site slice (no process:
// the slice exists but the site's processes still run outside it, e.g. until its PHP-FPM master restarts) is skipped
// too when the site can be measured by its unix user's processes instead.
func (c *WorkloadCollector) collectSlices(sites []otlp.Site, own map[string]string, shared map[string][]string, withContainers map[string]bool, out owners) map[string]bool {
	withSlice := map[string]bool{}
	entries, err := os.ReadDir(c.fs.P(cgroup.CgroupRoot))
	if err != nil {
		return withSlice
	}
	byProcs := map[string]bool{}
	for slug := range own {
		byProcs[slug] = true
	}
	for _, slugs := range shared {
		for _, slug := range slugs {
			byProcs[slug] = true
		}
	}
	byKey := make(map[string]string, len(sites))
	for _, s := range sites {
		byKey[strings.ReplaceAll(s.Slug, "-", "_")] = s.Slug
	}
	for _, e := range entries {
		key, ok := strings.CutPrefix(e.Name(), "falak-")
		if !e.IsDir() || !ok {
			continue
		}
		if key, ok = strings.CutSuffix(key, ".slice"); !ok {
			continue
		}
		var ownerKey string
		var res []*commonpb.KeyValue
		slug := ""
		switch {
		case strings.HasPrefix(key, "site_"):
			if slug, ok = byKey[strings.TrimPrefix(key, "site_")]; !ok {
				continue
			}
			ownerKey, res = "site/"+slug, []*commonpb.KeyValue{str("service.name", slug)}
		case strings.HasPrefix(key, "worker_"), strings.HasPrefix(key, "daemon_"):
			ownerKey, res = "slice/"+key, []*commonpb.KeyValue{str("service.name", otlp.AgentService), str("falak.slice", key)}
		default:
			continue
		}
		s, ok := ReadCgroup(c.fs, path.Join(cgroup.CgroupRoot, e.Name()))
		if !ok {
			continue
		}
		if slug != "" {
			if s.HasPids && s.Pids == 0 && byProcs[slug] && !withContainers[slug] {
				continue
			}
			withSlice[slug] = true
			if withContainers[slug] {
				// The site's containers are measured already; a slice holding them (a custom cgroup parent) would count
				// them twice. Only its limits are reported.
				out.add(ownerKey, res, point{attrs: workloadAttrs("slice", key), u: usage{cpuLimit: s.CPULimit, memLimit: s.MemLimit, pidsLimit: s.PidsLimit}})
				continue
			}
		}
		out.add(ownerKey, res, point{attrs: workloadAttrs("slice", key), u: fromCgroup(s)})
	}
	return withSlice
}

// collectUsers measures native sites that have neither containers nor a slice by their unix user's processes: all of
// them for an isolated site's own user (kind "user"), those attributed to the site for a shared user (kind
// "processes": its PHP-FPM pool, else its working directory, see readOrigin). Processes running in a container are
// never counted, whatever their uid. One /proc pass for all of them, skipped entirely when there is none. No network
// (not attributable per process without eBPF) and no limits (an unlimited site has none).
func (c *WorkloadCollector) collectUsers(own map[string]string, shared map[string][]string, withContainers, withSlice map[string]bool, out owners) {
	measured := func(slug string) bool { return !withContainers[slug] && !withSlice[slug] }
	type want struct{ slug, user string }
	var wantOwn, wantShared []want
	for slug, user := range own {
		if measured(slug) {
			wantOwn = append(wantOwn, want{slug, user})
		}
	}
	for user, slugs := range shared {
		for _, slug := range slugs {
			if measured(slug) {
				wantShared = append(wantShared, want{slug, user})
			}
		}
	}
	if len(wantOwn)+len(wantShared) == 0 {
		c.mu.Lock()
		clear(c.users)
		clear(c.origins)
		c.mu.Unlock()
		return
	}
	// A failed read of /etc/passwd keeps the last map: dropping the users would reset their counters.
	ids := passwdUIDs(c.fs)
	c.mu.Lock()
	if ids == nil {
		ids = c.uids
	} else {
		c.uids = ids
	}
	c.mu.Unlock()
	uidOf := func(user string) (int, bool) {
		uid, ok := ids[user]
		return uid, ok && uid != 0
	}
	uids, wanted := map[int]bool{}, map[string]bool{}
	for _, w := range wantOwn {
		if uid, ok := uidOf(w.user); ok {
			uids[uid], wanted[userKey(uid)] = true, true
		}
	}
	// The sites a shared uid's processes can belong to (with a slice or not: a process of a site measured by its
	// slice is that site's, never another's), and the PHP-FPM pool of each.
	sharing := map[int]map[string]bool{}
	pools := map[string]string{}
	for user, slugs := range shared {
		uid, ok := uidOf(user)
		if !ok {
			continue
		}
		sharing[uid] = map[string]bool{}
		for _, slug := range slugs {
			sharing[uid][slug] = true
			pools[runtime.FPMPoolName(slug)] = slug
		}
	}
	for _, w := range wantShared {
		if uid, ok := uidOf(w.user); ok {
			uids[uid], wanted[siteKey(w.slug)] = true, true
		}
	}
	c.mu.Lock()
	roots := c.sitesRoots()
	seen := map[int]bool{}
	owner := func(uid, pid int, start uint64) string {
		seen[pid] = true
		o, ok := c.origins[pid]
		if !ok || o.start != start {
			if o = readOrigin(c.fs, roots, pid, start); o.retry {
				delete(c.origins, pid)
			} else {
				c.origins[pid] = o
			}
		}
		if o.container {
			return ""
		}
		slugs := sharing[uid]
		if slugs == nil {
			return userKey(uid)
		}
		slug := o.dir
		if o.pool != "" {
			slug = pools[o.pool]
		}
		if !slugs[slug] {
			return ""
		}
		return siteKey(slug)
	}
	totals := scanProcs(c.fs, uids, owner, wanted, c.users)
	for pid := range c.origins {
		if !seen[pid] {
			delete(c.origins, pid)
		}
	}
	c.mu.Unlock()
	emit := func(w want, key, kind, name string) {
		if !wanted[key] {
			return
		}
		t := totals[key]
		u := usage{has: hasCPU | hasMem | hasIO | hasPids, cpuSec: float64(t.cpuTicks) / userHZ, memUsed: t.rss,
			read: int64(t.read), write: int64(t.write), pids: t.pids}
		out.add("site/"+w.slug, []*commonpb.KeyValue{str("service.name", w.slug)}, point{attrs: workloadAttrs(kind, name), u: u})
	}
	for _, w := range wantOwn {
		if uid, ok := uidOf(w.user); ok {
			emit(w, userKey(uid), "user", w.user)
		}
	}
	for _, w := range wantShared {
		if _, ok := uidOf(w.user); ok {
			emit(w, siteKey(w.slug), "processes", w.slug)
		}
	}
}

func with(attrs []*commonpb.KeyValue, kv ...*commonpb.KeyValue) []*commonpb.KeyValue {
	return append(append(make([]*commonpb.KeyValue, 0, len(attrs)+len(kv)), attrs...), kv...)
}

// encode turns the owners into ResourceMetrics, sorted by owner for stable output.
func (c *WorkloadCollector) encode(all owners) []*metricspb.ResourceMetrics {
	keys := make([]string, 0, len(all))
	for k := range all {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	now := uint64(c.now().UnixNano())
	dbl := func(v float64, attrs []*commonpb.KeyValue) *metricspb.NumberDataPoint {
		return &metricspb.NumberDataPoint{TimeUnixNano: now, Value: &metricspb.NumberDataPoint_AsDouble{AsDouble: v}, Attributes: attrs}
	}
	in := func(v int64, attrs []*commonpb.KeyValue) *metricspb.NumberDataPoint {
		return &metricspb.NumberDataPoint{TimeUnixNano: now, Value: &metricspb.NumberDataPoint_AsInt{AsInt: v}, Attributes: attrs}
	}
	out := make([]*metricspb.ResourceMetrics, 0, len(keys))
	for _, k := range keys {
		o := all[k]
		var cpu, cpuLim, thrT, thrP, per, mem, memLim, oom, disk, netio, pids, pidsLim []*metricspb.NumberDataPoint
		for _, p := range o.points {
			u, a := p.u, p.attrs
			if u.has&hasCPU != 0 {
				cpu = append(cpu, dbl(u.cpuSec, a))
			}
			if u.cpuLimit > 0 {
				cpuLim = append(cpuLim, dbl(u.cpuLimit, a))
			}
			if u.has&hasThrottle != 0 {
				thrT = append(thrT, dbl(u.throttledSec, a))
				thrP = append(thrP, in(u.throttledPeriods, a))
				per = append(per, in(u.periods, a))
			}
			if u.has&hasMem != 0 {
				mem = append(mem, in(u.memUsed, with(a, str("falak.memory.type", "used"))))
			}
			if u.has&hasCache != 0 {
				mem = append(mem, in(u.memCache, with(a, str("falak.memory.type", "cache"))))
			}
			if u.memLimit > 0 {
				memLim = append(memLim, in(u.memLimit, a))
			}
			if u.has&hasOOM != 0 {
				oom = append(oom, in(u.oomKills, a))
			}
			if u.has&hasIO != 0 {
				disk = append(disk, in(u.read, with(a, str("disk.io.direction", "read"))), in(u.write, with(a, str("disk.io.direction", "write"))))
			}
			if u.has&hasNet != 0 {
				netio = append(netio, in(u.rx, with(a, str("network.io.direction", "receive"))), in(u.tx, with(a, str("network.io.direction", "transmit"))))
			}
			if u.has&hasPids != 0 {
				pids = append(pids, in(u.pids, a))
			}
			if u.pidsLimit > 0 {
				pidsLim = append(pidsLim, in(u.pidsLimit, a))
			}
		}
		var ms []*metricspb.Metric
		addM := func(m *metricspb.Metric, n int) {
			if n > 0 {
				ms = append(ms, m)
			}
		}
		addM(counter("falak.workload.cpu.time", "s", cpu...), len(cpu))
		addM(gauge("falak.workload.cpu.limit", "{cpu}", cpuLim...), len(cpuLim))
		addM(counter("falak.workload.cpu.throttled.time", "s", thrT...), len(thrT))
		addM(counter("falak.workload.cpu.throttled.periods", "{period}", thrP...), len(thrP))
		addM(counter("falak.workload.cpu.periods", "{period}", per...), len(per))
		addM(gauge("falak.workload.memory.usage", "By", mem...), len(mem))
		addM(gauge("falak.workload.memory.limit", "By", memLim...), len(memLim))
		addM(counter("falak.workload.memory.oom_kills", "{kill}", oom...), len(oom))
		addM(counter("falak.workload.disk.io", "By", disk...), len(disk))
		addM(counter("falak.workload.network.io", "By", netio...), len(netio))
		addM(gauge("falak.workload.pids", "{process}", pids...), len(pids))
		addM(gauge("falak.workload.pids.limit", "{process}", pidsLim...), len(pidsLim))
		if len(ms) == 0 {
			continue
		}
		out = append(out, &metricspb.ResourceMetrics{
			Resource:     &resourcepb.Resource{Attributes: o.res},
			ScopeMetrics: []*metricspb.ScopeMetrics{{Scope: &commonpb.InstrumentationScope{Name: otlp.WorkloadsScope}, Metrics: ms}},
		})
	}
	return out
}
