package netobs

import (
	"context"
	"errors"
	"log/slog"
	"net"
	"net/netip"
	"sort"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/metrics"
	"github.com/OthmanHaba/falak/agent/internal/netcfg"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/transport"
	commonpb "go.opentelemetry.io/proto/otlp/common/v1"
	metricspb "go.opentelemetry.io/proto/otlp/metrics/v1"
)

// Limits of one pass (docs/plans/network/connections.md, "Performance budget").
var (
	maxSocketsPerNS  = 20000
	maxNativeFDs     = 4096  // per process
	maxNativeBudget  = 50000 // per pass
	maxOwnerScan     = 200000
	ownerScanEvery   = 5 * time.Minute
	maxTrackedConns  = 50000 // per namespace (long-lived)
	maxFlows         = 65536
	maxPeers         = 20 // per workload and direction, the rest is "other"
	maxListeners     = 64
	maxWorkloads     = 200
	fullEvery        = time.Minute
	peerSeriesExpiry = 10 * time.Minute
)

const (
	scope       = "falak-agent/network"
	hostService = "falak-host"
	dbService   = "falak-db"
	dirIn       = "inbound"
	dirOut      = "outbound"
	otherPeer   = "other"
)

// states are the falak.net.state values, every one reported (0 included) so the series are continuous.
var states = []string{"established", "syn_sent", "syn_recv", "fin_wait", "time_wait", "close_wait", "last_ack", "closing"}

// Snapshotter is the workload collector's last pass (containers, native sites' processes).
type Snapshotter interface {
	NetSnapshot() metrics.NetSnapshot
}

// Requester is the control plane's request channel (*transport.Client).
type Requester interface {
	Request(ctx context.Context, typ string, in, out any) error
}

// Emitter receives OTLP metrics (the telemetry relay).
type Emitter interface {
	EmitMetrics(*metricspb.ResourceMetrics)
}

// Options configure a Collector. Workloads, CP and Runner may be nil (no containers / no report / no WireGuard).
type Options struct {
	FS        hostfs.FS
	EtcDir    string
	Workloads Snapshotter
	CP        Requester
	Out       Emitter
	Runner    runner.Runner
	Logger    *slog.Logger
}

// Collector is the network collector: every interval the connection states, listeners, long-lived connections and
// conntrack usage; every minute also the peers (with conntrack bytes), WireGuard, probes and the net.observed report.
type Collector struct {
	opts      Options
	log       *slog.Logger
	now       func() time.Time
	dial      dialFunc
	conntrack func(limit int) ([]Flow, bool, error)
	wireguard func(ctx context.Context) ([]netcfg.PeerStats, error)

	enabled  atomic.Bool
	interval atomic.Int64
	wake     chan struct{}

	mu            sync.Mutex
	cfg           Config
	lastFull      time.Time
	seen          map[string]map[string]time.Time // namespace => connection => first seen (long-lived)
	owners        map[uint64]procOwner            // host listener inode => its process
	ownersScanned time.Time
	flows         flowCounters
	peerIO        map[string]*peerCounter
	connects      func() map[Connect]uint64
	cpMissing     bool
}

// peerCounter is a monotonic per-peer byte counter (falak.net.peer.io).
type peerCounter struct {
	owner, workload string
	attrs           []*commonpb.KeyValue
	rx, tx          uint64
	seen            time.Time
}

// Connect is an outbound destination of a workload (its key: host, ct/<id>, native/<slug>), from the eBPF probe.
type Connect struct {
	Workload string
	Addr     netip.Addr
	Port     uint16
}

// SetConnects adds the eBPF probe's connects (outbound connections opened per workload and destination since the
// last call) to the peers of each full pass: short-lived connections (a database connection per request) that a socket
// snapshot misses. fn returns nil while the probe is off.
func (c *Collector) SetConnects(fn func() map[Connect]uint64) {
	c.mu.Lock()
	c.connects = fn
	c.mu.Unlock()
}

// New creates an enabled collector (15 s interval) and loads the persisted configuration.
func New(opts Options) *Collector {
	if opts.Logger == nil {
		opts.Logger = slog.Default()
	}
	d := &net.Dialer{}
	c := &Collector{opts: opts, log: opts.Logger, now: time.Now, dial: d.DialContext, conntrack: dumpConntrack,
		wake: make(chan struct{}, 1), seen: map[string]map[string]time.Time{}, owners: map[uint64]procOwner{},
		peerIO: map[string]*peerCounter{}}
	if opts.Runner != nil {
		c.wireguard = func(ctx context.Context) ([]netcfg.PeerStats, error) { return netcfg.WireGuardStats(ctx, opts.Runner) }
	}
	c.enabled.Store(true)
	c.interval.Store(int64(15 * time.Second))
	if opts.EtcDir != "" {
		c.loadConfig()
	}
	return c
}

// Configure hot-reloads enablement and interval (telemetry.configure metrics).
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

// SetConfig replaces the control plane's configuration (probes, names).
func (c *Collector) SetConfig(cfg Config) {
	c.mu.Lock()
	c.cfg = cfg
	c.mu.Unlock()
}

// Run collects every interval until ctx is done.
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
		if !c.enabled.Load() {
			continue
		}
		rms, report := c.Collect(ctx)
		for _, rm := range rms {
			c.opts.Out.EmitMetrics(rm)
		}
		if report != nil {
			c.send(ctx, report)
		}
	}
}

func (c *Collector) send(ctx context.Context, r *Report) {
	if c.opts.CP == nil {
		return
	}
	if err := c.opts.CP.Request(ctx, "net.observed", r, nil); err != nil {
		var se *transport.StatusError
		if errors.As(err, &se) && se.Code == 404 {
			c.mu.Lock()
			first := !c.cpMissing
			c.cpMissing = true
			c.mu.Unlock()
			if first {
				c.log.Info("the control plane does not take network reports yet (net.observed); metrics still flow")
			}
			return
		}
		c.log.Warn("net.observed failed", "err", err)
	}
}

// workload is one owner of sockets in this pass.
type workload struct {
	key       string // unique in the pass
	owner     string // resource key: host, site/<slug>, db/<id>
	res       []*commonpb.KeyValue
	attrs     []*commonpb.KeyValue
	ns        string // namespace key (long-lived tracking)
	inHost    bool
	socks     []Socket
	listening map[listenerKey][]netip.Addr
	rep       ReportWorkload
	exposure  map[[2]string]int // (transport, exposure) => listeners
	byState   map[[2]string]int // (state, direction) => connections
	longLived int
}

// directional is the number of connections in a state and direction.
func (w *workload) directional(state, dir string) int { return w.byState[[2]string{state, dir}] }

// Collect runs one pass; the report is non-nil on a full (per-minute) pass.
func (c *Collector) Collect(ctx context.Context) ([]*metricspb.ResourceMetrics, *Report) {
	now := c.now()
	c.mu.Lock()
	cfg := c.cfg
	full := c.lastFull.IsZero() || now.Sub(c.lastFull) >= fullEvery-time.Duration(c.interval.Load())/2
	window := 0
	if full && !c.lastFull.IsZero() {
		window = int(now.Sub(c.lastFull).Round(time.Second) / time.Second)
	}
	if full {
		c.lastFull = now
	}
	c.mu.Unlock()
	var snap metrics.NetSnapshot
	if c.opts.Workloads != nil {
		snap = c.opts.Workloads.NetSnapshot()
	}
	fs := c.opts.FS
	truncated := false
	var wls []*workload
	// Probes and WireGuard (execs, network round trips) run alongside the socket pass, not after it.
	var wg []ReportWireGuard
	var probes []ReportProbe
	slow := make(chan struct{})
	if full {
		go func() {
			defer close(slow)
			var both sync.WaitGroup
			both.Add(2)
			go func() { defer both.Done(); wg = c.wireGuard(ctx, cfg, now) }()
			go func() { defer both.Done(); probes = runProbes(ctx, c.dial, cfg.Probes, c.now) }()
			both.Wait()
		}()
	} else {
		close(slow)
	}

	// The host namespace: the host's processes, native sites (by socket inode) and docker-proxy listeners.
	hostNS := nsID(fs, 1)
	hostSocks, tr, ok := readSockets(fs, "/proc/1", maxSocketsPerNS)
	truncated = truncated || tr
	hostListening := listeningOf(hostSocks)
	host := &workload{key: "host", owner: "host", res: []*commonpb.KeyValue{str("service.name", hostService)},
		attrs: workloadAttrs("host", "host"), ns: "host", inHost: true, listening: hostListening,
		rep: ReportWorkload{Kind: "host", Name: "host"}}
	natives := map[string]*workload{}
	inodeSite := c.nativeInodes(snap.Native)
	if ok {
		for _, s := range hostSocks {
			slug := ""
			if s.Inode != 0 {
				slug = inodeSite[s.Inode]
			}
			if slug == "" {
				host.socks = append(host.socks, s)
				continue
			}
			w := natives[slug]
			if w == nil {
				w = &workload{key: "native/" + slug, owner: "site/" + slug, res: []*commonpb.KeyValue{str("service.name", slug)},
					attrs: workloadAttrs("processes", slug), ns: "host", inHost: true, listening: hostListening,
					rep: ReportWorkload{Kind: "processes", Name: slug, Site: slug}}
				natives[slug] = w
			}
			w.socks = append(w.socks, s)
		}
		wls = append(wls, host)
		for _, slug := range sortedKeys(natives) {
			if len(wls) >= maxWorkloads {
				// Past the cap a site's sockets count for the host (the report's schema holds maxWorkloads).
				host.socks = append(host.socks, natives[slug].socks...)
				truncated = true
				continue
			}
			wls = append(wls, natives[slug])
		}
	}

	// Container namespaces, deduplicated (a service sharing another's namespace, a container on the host network).
	ctByIP := map[netip.Addr]string{}
	ctNameByIP := map[netip.Addr]string{}
	nsDone := map[string]bool{hostNS: hostNS != ""}
	for _, ct := range snap.Containers {
		label := containerLabel(ct)
		for _, ip := range ct.IPs {
			if a, err := netip.ParseAddr(ip); err == nil {
				ctByIP[a.Unmap()], ctNameByIP[a.Unmap()] = label, ct.Name
			}
		}
		ns := nsID(fs, ct.Pid)
		if ns == "" || nsDone[ns] {
			continue
		}
		if len(wls) >= maxWorkloads {
			truncated = true
			continue
		}
		nsDone[ns] = true
		socks, tr, ok := readSockets(fs, "/proc/"+strconv.Itoa(ct.Pid), maxSocketsPerNS)
		truncated = truncated || tr
		if !ok {
			continue
		}
		attrs := workloadAttrs("container", ct.Name, str("container.id", ct.ID))
		if ct.Service != "" {
			attrs = append(attrs, str("falak.compose.service", ct.Service))
		}
		w := &workload{key: "ct/" + ct.ID, ns: ns, socks: socks, listening: listeningOf(socks), attrs: attrs,
			rep: ReportWorkload{Kind: "container", Name: ct.Name, ContainerID: ct.ID, ComposeService: ct.Service}}
		if ct.DBInstance != "" {
			w.owner, w.res = "db/"+ct.DBInstance, []*commonpb.KeyValue{str("service.name", dbService), str("falak.db.instance.id", ct.DBInstance)}
			w.rep.DBInstance = ct.DBInstance
		} else {
			w.owner, w.res = "site/"+ct.Site, []*commonpb.KeyValue{str("service.name", ct.Site)}
			w.rep.Site = ct.Site
		}
		wls = append(wls, w)
	}

	c.countConnections(wls, now, cfg)
	usage, haveCT := readConntrackUsage(fs)

	var report *Report
	if full {
		report = &Report{At: now.UTC(), WindowS: window, Truncated: truncated, Conntrack: ReportConntrack{Accounting: usage.Accounting},
			WireGuard: []ReportWireGuard{}, Probes: []ReportProbe{}}
		if haveCT {
			report.Conntrack.Entries, report.Conntrack.Limit = &usage.Entries, &usage.Limit
		}
		names := c.resolver(cfg, host, ctByIP, ctNameByIP, inodeSite, snap)
		c.listeners(wls, names)
		c.peers(wls, names, usage.Accounting == acctOn, now)
		<-slow
		report.WireGuard = wg
		if probes != nil {
			report.Probes = probes
		}
		for _, w := range wls {
			report.Workloads = append(report.Workloads, w.rep)
		}
		if report.Workloads == nil {
			report.Workloads = []ReportWorkload{}
		}
	}
	return c.encode(wls, usage, haveCT, report, now), report
}

func sortedKeys[V any](m map[string]V) []string {
	out := make([]string, 0, len(m))
	for k := range m {
		out = append(out, k)
	}
	sort.Strings(out)
	return out
}

// containerLabel is a container's peer name: db:<instance id>, site:<slug> or site:<slug>/<compose service>.
func containerLabel(ct metrics.NetContainer) string {
	if ct.DBInstance != "" {
		return "db:" + ct.DBInstance
	}
	if ct.Service != "" {
		return "site:" + ct.Site + "/" + ct.Service
	}
	return "site:" + ct.Site
}

func listeningOf(socks []Socket) map[listenerKey][]netip.Addr {
	out := map[listenerKey][]netip.Addr{}
	for _, s := range socks {
		if s.Listening() {
			k := listenerKey{s.Proto, s.Local.Port()}
			out[k] = append(out[k], s.Local.Addr())
		}
	}
	return out
}

// nativeInodes maps the socket inodes of the native sites' processes to their site (their /proc/<pid>/fd links;
// bounded per process and per pass).
func (c *Collector) nativeInodes(native map[int]string) map[uint64]string {
	out := map[uint64]string{}
	pids := make([]int, 0, len(native))
	for pid := range native {
		pids = append(pids, pid)
	}
	sort.Ints(pids)
	budget := maxNativeBudget
	for _, pid := range pids {
		if budget <= 0 {
			break
		}
		for _, inode := range socketInodes(c.opts.FS, pid, maxNativeFDs, &budget) {
			out[inode] = native[pid]
		}
	}
	return out
}

// countConnections fills every workload's states, directions and long-lived count (and remembers when each inbound
// connection was first seen).
func (c *Collector) countConnections(wls []*workload, now time.Time, cfg Config) {
	after := time.Duration(cfg.LongLivedAfterS) * time.Second
	if after <= 0 {
		after = defaultLongLivedS * time.Second
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	fresh := map[string]map[string]time.Time{}
	for _, w := range wls {
		w.rep.Connections.States = map[string]int{}
		for _, st := range states {
			w.rep.Connections.States[st] = 0
		}
		w.exposure = map[[2]string]int{}
		w.byState = map[[2]string]int{}
		old := c.seen[w.ns]
		cur := fresh[w.ns]
		if cur == nil {
			cur = map[string]time.Time{}
			fresh[w.ns] = cur
		}
		for _, s := range w.socks {
			if s.Listening() {
				w.exposure[[2]string{s.Proto, exposure(s.Local.Addr())}]++
				continue
			}
			if s.Proto != "tcp" {
				continue
			}
			name := stateName(s.State)
			if name == "" {
				continue
			}
			w.rep.Connections.States[name]++
			in := inbound(s, w.listening)
			if in {
				w.rep.Connections.Inbound++
				w.byState[[2]string{name, dirIn}]++
			} else {
				w.rep.Connections.Outbound++
				w.byState[[2]string{name, dirOut}]++
			}
			if !in || s.State != tcpEstablished || len(cur) >= maxTrackedConns {
				continue
			}
			k := tupleKey("tcp", s.Local, s.Remote)
			first, ok := old[k]
			if !ok {
				first = now
			}
			cur[k] = first
			if now.Sub(first) >= after {
				w.longLived++
			}
		}
		w.rep.Connections.LongLived = w.longLived
	}
	c.seen = fresh
}

// names resolves remote addresses to peer names (docs/plans/network/connections.md, "Peers").
type names struct {
	cfg        map[string]string // "addr|port" and "addr|0" => name
	ctByIP     map[netip.Addr]string
	ctNameByIP map[netip.Addr]string
	hostAddrs  map[netip.Addr]bool
	hostOwners map[listenerKey]string // host listener => owner name
}

func nameKey(a netip.Addr, port uint16) string { return a.String() + "|" + strconv.Itoa(int(port)) }

func (c *Collector) resolver(cfg Config, host *workload, ctByIP, ctNameByIP map[netip.Addr]string, inodeSite map[uint64]string, snap metrics.NetSnapshot) *names {
	n := &names{cfg: map[string]string{}, ctByIP: ctByIP, ctNameByIP: ctNameByIP, hostAddrs: map[netip.Addr]bool{}, hostOwners: map[listenerKey]string{}}
	for _, nm := range cfg.Names {
		if a, err := netip.ParseAddr(nm.Address); err == nil {
			n.cfg[nameKey(a.Unmap(), uint16(nm.Port))] = nm.Name
		}
	}
	all := host.socks
	for _, s := range all {
		if a := s.Local.Addr(); a.IsValid() && !a.IsUnspecified() {
			n.hostAddrs[a] = true
		}
	}
	// Host listeners' owners: a native site's by inode, else the process found by the fd scan.
	c.resolveOwners(host, inodeSite, snap)
	for _, s := range host.socks {
		if !s.Listening() {
			continue
		}
		k := listenerKey{s.Proto, s.Local.Port()}
		if slug := inodeSite[s.Inode]; slug != "" {
			n.hostOwners[k] = "site:" + slug
			continue
		}
		c.mu.Lock()
		o, ok := c.owners[s.Inode]
		c.mu.Unlock()
		if !ok {
			continue
		}
		if o.publishes != "" {
			if a, err := netip.ParseAddr(o.publishes); err == nil && ctByIP[a.Unmap()] != "" {
				n.hostOwners[k] = ctByIP[a.Unmap()]
				continue
			}
		}
		if o.comm != "" {
			n.hostOwners[k] = "host:" + o.comm
		}
	}
	return n
}

// name is the peer's name ("" when unnamed) as seen from w.
func (n *names) name(w *workload, dir string, a netip.Addr, port uint16) string {
	// The control plane's names are host addresses: a container's loopback is the container itself.
	if w.inHost || !a.IsLoopback() {
		if v := n.cfg[nameKey(a, port)]; v != "" {
			return v
		}
		if v := n.cfg[nameKey(a, 0)]; v != "" {
			return v
		}
	}
	if v := n.ctByIP[a]; v != "" {
		return v
	}
	// The host itself: its loopback (from the host namespace; a container's loopback is its own) or one of its addresses.
	hostAddr := n.hostAddrs[a] && !a.IsLoopback()
	local := (w.inHost && a.IsLoopback()) || hostAddr
	if local && dir == dirOut {
		if v := n.hostOwners[listenerKey{"tcp", port}]; v != "" {
			return v
		}
	}
	if hostAddr && !w.inHost {
		return "host"
	}
	return ""
}

// bucket is the metric label of an unnamed peer: never its address.
func bucket(dir string, a netip.Addr, port uint16) string {
	kind := "external"
	switch {
	case a.IsLoopback():
		kind = "local"
	case a.IsPrivate() || a.IsLinkLocalUnicast() || cgnat.Contains(a):
		kind = "private"
	}
	if dir == dirOut {
		return kind + ":" + strconv.Itoa(int(port))
	}
	return kind
}

// resolveOwners finds the processes of the host's listeners not owned by a native site: cached per inode (checked by
// start time), a scan of every process's fds only when one is unknown, at most every ownerScanEvery.
func (c *Collector) resolveOwners(host *workload, inodeSite map[uint64]string, _ metrics.NetSnapshot) {
	listening := map[uint64]bool{}
	for _, s := range host.socks {
		if s.Listening() && s.Inode != 0 && inodeSite[s.Inode] == "" {
			listening[s.Inode] = true
		}
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	unknown := map[uint64]bool{}
	for inode := range listening {
		o, ok := c.owners[inode]
		if ok && procStart(c.opts.FS, o.pid) != o.start {
			delete(c.owners, inode)
			ok = false
		}
		if !ok {
			unknown[inode] = true
		}
	}
	for inode := range c.owners {
		if !listening[inode] {
			delete(c.owners, inode)
		}
	}
	now := c.now()
	if len(unknown) == 0 || (!c.ownersScanned.IsZero() && now.Sub(c.ownersScanned) < ownerScanEvery) {
		return
	}
	c.ownersScanned = now
	for inode, o := range findOwners(c.opts.FS, unknown, maxOwnerScan) {
		c.owners[inode] = o
	}
}

// listeners fills every workload's listener table.
func (c *Collector) listeners(wls []*workload, n *names) {
	c.mu.Lock()
	owners := make(map[uint64]procOwner, len(c.owners))
	for k, v := range c.owners {
		owners[k] = v
	}
	c.mu.Unlock()
	for _, w := range wls {
		seen := map[string]bool{}
		w.rep.Listeners = []ReportListener{}
		for _, s := range w.socks {
			if !s.Listening() {
				continue
			}
			key := s.Proto + "|" + s.Local.String()
			if seen[key] {
				continue
			}
			seen[key] = true
			l := ReportListener{Proto: s.Proto, Address: s.Local.Addr().String(), Port: int(s.Local.Port()), Public: exposure(s.Local.Addr()) == "public"}
			if w.inHost {
				if o, ok := owners[s.Inode]; ok {
					l.Process = o.comm
					if a, err := netip.ParseAddr(o.publishes); err == nil {
						l.Container = n.ctNameByIP[a.Unmap()]
					}
				}
				if w.rep.Kind == "processes" {
					l.Owner = "site:" + w.rep.Site
				} else {
					l.Owner = n.hostOwners[listenerKey{s.Proto, s.Local.Port()}]
				}
			}
			w.rep.Listeners = append(w.rep.Listeners, l)
		}
		// Public listeners first, then by port: the cap keeps the same ones every pass (the exposed-ports audit must not
		// see one come and go), and says it cut some.
		sort.Slice(w.rep.Listeners, func(i, j int) bool {
			a, b := w.rep.Listeners[i], w.rep.Listeners[j]
			if a.Public != b.Public {
				return a.Public
			}
			if a.Port != b.Port {
				return a.Port < b.Port
			}
			if a.Proto != b.Proto {
				return a.Proto < b.Proto
			}
			return a.Address < b.Address
		})
		if len(w.rep.Listeners) > maxListeners {
			w.rep.Listeners, w.rep.ListenersTruncated = w.rep.Listeners[:maxListeners], true
		}
	}
}

type peerAgg struct {
	dir      string
	addr     netip.Addr
	port     uint16
	name     string
	conns    int
	connects uint64 // opened in the minute (eBPF)
	rx, tx   uint64
	hasBytes bool
}

// peers groups every workload's established connections by remote endpoint, adds the conntrack bytes of the minute,
// keeps the top maxPeers per direction and folds the rest into "other".
func (c *Collector) peers(wls []*workload, n *names, accounting bool, now time.Time) {
	var deltas map[string][2]uint64
	var idx map[string]flowMatch
	if accounting && c.conntrack != nil {
		flows, capped, err := c.conntrack(maxFlows)
		if err != nil {
			c.log.Debug("conntrack dump failed", "err", err)
			accounting = false
		} else {
			c.mu.Lock()
			deltas = c.flows.deltas(flows, capped)
			c.mu.Unlock()
			idx = flowIndex(flows)
		}
	}
	c.mu.Lock()
	drain := c.connects
	c.mu.Unlock()
	opened := map[string]map[Connect]uint64{}
	if drain != nil {
		for k, v := range drain() {
			if opened[k.Workload] == nil {
				opened[k.Workload] = map[Connect]uint64{}
			}
			opened[k.Workload][k] = v
		}
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	for _, w := range wls {
		aggs := map[string]*peerAgg{}
		for _, s := range w.socks {
			if s.Proto != "tcp" || s.State != tcpEstablished {
				continue
			}
			dir, port := dirOut, s.Remote.Port()
			if inbound(s, w.listening) {
				dir, port = dirIn, 0
			}
			a := s.Remote.Addr()
			k := dir + "|" + nameKey(a, port)
			p := aggs[k]
			if p == nil {
				p = &peerAgg{dir: dir, addr: a, port: port, name: n.name(w, dir, a, s.Remote.Port()), hasBytes: accounting}
				aggs[k] = p
			}
			p.conns++
			if m, ok := idx[tupleKey("tcp", s.Local, s.Remote)]; ok {
				d := deltas[m.key]
				if m.side == sideInitiator {
					p.tx, p.rx = p.tx+d[0], p.rx+d[1]
				} else {
					p.tx, p.rx = p.tx+d[1], p.rx+d[0]
				}
			}
		}
		for k, cnt := range opened[w.key] {
			a := k.Addr.Unmap()
			key := dirOut + "|" + nameKey(a, k.Port)
			p := aggs[key]
			if p == nil {
				p = &peerAgg{dir: dirOut, addr: a, port: k.Port, name: n.name(w, dirOut, a, k.Port), hasBytes: accounting}
				aggs[key] = p
			}
			p.connects += cnt
		}
		list := make([]*peerAgg, 0, len(aggs))
		for _, p := range aggs {
			list = append(list, p)
		}
		sort.Slice(list, func(i, j int) bool {
			a, b := list[i], list[j]
			if a.conns+int(a.connects) != b.conns+int(b.connects) {
				return a.conns+int(a.connects) > b.conns+int(b.connects)
			}
			if a.rx+a.tx != b.rx+b.tx {
				return a.rx+a.tx > b.rx+b.tx
			}
			if a.dir != b.dir {
				return a.dir < b.dir
			}
			return nameKey(a.addr, a.port) < nameKey(b.addr, b.port)
		})
		kept := map[string]int{}
		w.rep.Peers = []ReportPeer{}
		for _, p := range list {
			label := p.name
			if kept[p.dir] >= maxPeers {
				label = otherPeer
				if w.rep.Other == nil {
					w.rep.Other = map[string]PeerSums{}
				}
				o := w.rep.Other[p.dir]
				o.Peers++
				o.Connections += p.conns
				o.Connects += p.connects
				if p.hasBytes {
					o.RxBytes, o.TxBytes = addPtr(o.RxBytes, p.rx), addPtr(o.TxBytes, p.tx)
				}
				w.rep.Other[p.dir] = o
			} else {
				kept[p.dir]++
				rp := ReportPeer{Direction: p.dir, Address: p.addr.String(), Port: int(p.port), Name: p.name, Connections: p.conns, Connects: p.connects}
				if p.hasBytes {
					rx, tx := p.rx, p.tx
					rp.RxBytes, rp.TxBytes = &rx, &tx
				}
				w.rep.Peers = append(w.rep.Peers, rp)
				if label == "" {
					label = bucket(p.dir, p.addr, p.port)
				}
			}
			if p.hasBytes {
				pc := c.peerCounter(w, label, p.dir)
				pc.rx += p.rx
				pc.tx += p.tx
				pc.seen = now
			}
		}
	}
	for k, pc := range c.peerIO {
		if now.Sub(pc.seen) > peerSeriesExpiry {
			delete(c.peerIO, k)
		}
	}
}

func addPtr(p *uint64, v uint64) *uint64 {
	n := v
	if p != nil {
		n += *p
	}
	return &n
}

// peerCounter returns (creating) the byte counter of a workload's peer label. Called with c.mu held.
func (c *Collector) peerCounter(w *workload, label, dir string) *peerCounter {
	k := w.key + "|" + label + "|" + dir
	pc := c.peerIO[k]
	if pc == nil {
		pc = &peerCounter{owner: w.owner, workload: w.key, attrs: with(w.attrs, str("falak.net.peer", label), str("falak.net.direction", dir))}
		c.peerIO[k] = pc
	}
	return pc
}

// wireGuard reads the WireGuard peers when this server has a WireGuard configuration.
func (c *Collector) wireGuard(ctx context.Context, cfg Config, now time.Time) []ReportWireGuard {
	out := []ReportWireGuard{}
	if c.wireguard == nil {
		return out
	}
	if confs, _ := filepathGlob(c.opts.FS, "/etc/wireguard/*.conf"); len(confs) == 0 {
		return out
	}
	wctx, cancel := context.WithTimeout(ctx, 10*time.Second)
	defer cancel()
	peers, err := c.wireguard(wctx)
	if err != nil {
		c.log.Debug("wg show failed", "err", err)
		return out
	}
	byKey := map[string]string{}
	for _, w := range cfg.WireGuard {
		byKey[w.PublicKey] = w.Name
	}
	for _, p := range peers {
		r := ReportWireGuard{Interface: p.Interface, PublicKey: p.PublicKey, Name: byKey[p.PublicKey], AllowedIPs: p.AllowedIPs,
			RxBytes: p.RxBytes, TxBytes: p.TxBytes}
		if r.AllowedIPs == nil {
			r.AllowedIPs = []string{}
		}
		if !p.LatestHandshake.IsZero() {
			age := int64(now.Sub(p.LatestHandshake) / time.Second)
			r.HandshakeAgeS = &age
		}
		out = append(out, r)
	}
	return out
}

// wgPeerLabel is a WireGuard peer's metric label: its control-plane name, else the start of its key.
func wgPeerLabel(r ReportWireGuard) string {
	if r.Name != "" {
		return r.Name
	}
	return "key:" + strings.TrimRight(r.PublicKey[:min(8, len(r.PublicKey))], "=")
}
