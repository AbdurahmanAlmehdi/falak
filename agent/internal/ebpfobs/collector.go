// Package ebpfobs is the eBPF collector (docs/plans/network/ebpf.md): an opt-in, in-kernel probe counting TCP health
// (retransmits, resets, connect latency, RTT), DNS queries and run-queue delay into bounded maps, read once per
// telemetry interval, attributed to workloads like the phase 1 and 4 collectors, and shipped as OTLP metrics. It never
// fails the agent: a kernel that cannot run it, or a probe that costs more than its budget, is reported and left off.
package ebpfobs

import (
	"context"
	"fmt"
	"log/slog"
	"net/netip"
	"os"
	"runtime"
	"sort"
	"strings"
	"sync"
	"sync/atomic"
	"syscall"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/metrics"
	commonpb "go.opentelemetry.io/proto/otlp/common/v1"
	metricspb "go.opentelemetry.io/proto/otlp/metrics/v1"
)

const (
	scope        = "falak-agent/ebpf"
	hostService  = "falak-host"
	dbService    = "falak-db"
	maxPeers     = 20 // per workload and direction, the rest is "other"
	maxNames     = 10 // DNS names per workload, the rest is "other"
	otherLabel   = "other"
	seriesExpiry = 10 * time.Minute
	dnsTimeout   = 5 * time.Second
	// A run-queue stamp this old belongs to a task that never ran again (missed exit): swept by the agent.
	runqStale = 10 * time.Second
	// The probe's budget: its in-kernel run time plus the agent's reads, as a share of the machine's CPU capacity
	// over budgetWindow.
	budgetRatio  = 0.01
	budgetWindow = 5 * time.Minute
	// A single pass above this many times the budget trips it at once (no five minutes at 5 %+ of the CPU).
	budgetBurst = 5

	maxDetail = 2000 // facts.schema.json ebpf.detail
	maxReason = 300  // facts.schema.json ebpf.reason
)

// State values of Status.State.
const (
	StateOff         = "off"
	StateRunning     = "running"
	StateDegraded    = "degraded"
	StateOverBudget  = "over_budget"
	StateFailed      = "failed"
	StateUnsupported = "unsupported"
)

// Status is facts.ebpf and the net.ebpf.configure result.
type Status struct {
	Supported       bool     `json:"supported"`
	Reason          string   `json:"reason,omitempty"`
	Enabled         bool     `json:"enabled"`
	State           string   `json:"state"`
	Detail          string   `json:"detail,omitempty"`
	Programs        []string `json:"programs"`
	OverheadPercent *float64 `json:"overhead_percent,omitempty"`
}

// Snapshotter is the workload collector's last pass (containers, slices, native sites' processes and users).
type Snapshotter interface {
	NetSnapshot() metrics.NetSnapshot
}

// Emitter receives OTLP metrics (the telemetry relay).
type Emitter interface {
	EmitMetrics(*metricspb.ResourceMetrics)
}

// PeerNamer names remote endpoints like falak.net.peer (netobs.Collector.Namer).
type PeerNamer func(snap metrics.NetSnapshot) func(inHost bool, dir string, a netip.Addr, port uint16) string

// Options configure a Collector. Workloads and Names may be nil (everything is the server's; peers are buckets).
type Options struct {
	FS        hostfs.FS
	EtcDir    string
	Workloads Snapshotter
	Names     PeerNamer
	Out       Emitter
	Logger    *slog.Logger
}

// Collector runs the probe while the setting is on and reads it every interval.
type Collector struct {
	opts  Options
	log   *slog.Logger
	now   func() time.Time
	open  func(hostfs.FS) (source, []string, error)
	check func() (bool, string)
	cpus  int

	metricsOn atomic.Bool
	interval  atomic.Int64
	wake      chan struct{}

	mu      sync.Mutex
	enabled bool
	// fullNames ships whole DNS names instead of their registrable domain (net.ebpf.configure full_dns_names).
	fullNames bool
	// autoDisabled is why the budget turned the probe off ("" = it did not): persisted, so neither a restart nor the
	// control plane's re-sends turn it back on; only an explicit re-enable (or turning it off) clears it.
	autoDisabled string
	names        *nameRanker
	tried        bool // a start was attempted for the current setting: failures are not retried in a loop
	detected     bool
	src          source
	status       Status
	lastPass     time.Time

	prevPeers map[peerKey]peerVal
	// connects are the outbound connections opened per workload and destination since the last DrainConnects
	// (netobs' talks-to: short-lived connections a socket snapshot misses), at most maxConnects keys.
	connects    map[Connect]uint64
	prevHists   map[histKey]histVal
	prevDNS     map[dnsKey]dnsVal
	prevRuntime map[string]time.Duration
	counters    map[string]*counter
	hists       map[string]*histogram
	costs       []cost
}

type cost struct{ spent, wall time.Duration }

// Connect is an outbound destination of a workload ("host", "ct/<id>", "native/<slug>": netobs' workload keys).
type Connect struct {
	Workload string
	Addr     netip.Addr
	Port     uint16
}

// maxConnects bounds the destinations kept between two drains (netobs keeps the top 20 per workload anyway).
const maxConnects = 4096

// DrainConnects returns the outbound connections opened per workload and destination since the last call (nil
// while the probe is off) and starts over.
func (c *Collector) DrainConnects() map[Connect]uint64 {
	c.mu.Lock()
	defer c.mu.Unlock()
	m := c.connects
	c.connects = nil
	return m
}

// New creates a collector (off until the setting says otherwise; the persisted setting is loaded).
func New(opts Options) *Collector {
	if opts.Logger == nil {
		opts.Logger = slog.Default()
	}
	c := &Collector{opts: opts, log: opts.Logger, now: time.Now, open: openProbe, cpus: runtime.NumCPU(),
		wake: make(chan struct{}, 1), status: Status{State: StateOff, Programs: []string{}}, names: newNameRanker()}
	c.check = func() (bool, string) {
		if !platformSupported {
			return false, "not linux/amd64 or linux/arm64"
		}
		return detect(opts.FS, bpfWorks)
	}
	c.metricsOn.Store(true)
	c.interval.Store(int64(15 * time.Second))
	c.reset()
	if opts.EtcDir != "" {
		c.loadConfig()
	}
	return c
}

func (c *Collector) reset() {
	c.prevPeers, c.prevHists, c.prevDNS = map[peerKey]peerVal{}, map[histKey]histVal{}, map[dnsKey]dnsVal{}
	c.prevRuntime = map[string]time.Duration{}
	c.costs = nil
	if c.counters == nil {
		c.counters, c.hists = map[string]*counter{}, map[string]*histogram{}
	}
}

// Configure follows telemetry.configure's metrics settings: no metrics, no probe.
func (c *Collector) Configure(enabled bool, interval time.Duration) {
	if interval < time.Second {
		interval = 15 * time.Second
	}
	c.metricsOn.Store(enabled)
	c.interval.Store(int64(interval))
	c.poke()
}

func (c *Collector) poke() {
	select {
	case c.wake <- struct{}{}:
	default:
	}
}

// Status is the probe's state for facts.ebpf.
func (c *Collector) Status() Status {
	c.mu.Lock()
	defer c.mu.Unlock()
	if !c.detected {
		c.detectLocked()
	}
	return c.statusLocked()
}

// statusLocked is a copy of the status, its texts cut to the schema's lengths.
func (c *Collector) statusLocked() Status {
	s := c.status
	s.Programs = append([]string{}, s.Programs...)
	s.Detail, s.Reason = truncate(s.Detail, maxDetail), truncate(s.Reason, maxReason)
	return s
}

func truncate(s string, n int) string {
	if r := []rune(s); len(r) > n {
		return string(r[:n-1]) + "…"
	}
	return s
}

func (c *Collector) detectLocked() {
	c.detected = true
	c.status.Supported, c.status.Reason = c.check()
}

// Run reads the probe every interval until ctx is done, then detaches it.
func (c *Collector) Run(ctx context.Context) {
	defer c.stop()
	for {
		c.Tick()
		t := time.NewTimer(time.Duration(c.interval.Load()))
		select {
		case <-ctx.Done():
			t.Stop()
			return
		case <-c.wake:
			t.Stop()
		case <-t.C:
		}
	}
}

func (c *Collector) stop() {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.closeLocked()
}

// Tick reconciles the probe with the setting and, while it runs, reads it and emits the metrics.
func (c *Collector) Tick() {
	rms := c.tick()
	if c.opts.Out != nil {
		for _, rm := range rms {
			c.opts.Out.EmitMetrics(rm)
		}
	}
}

// tick is one pass under the lock; a panic detaches the probe (state failed) instead of taking the agent down.
func (c *Collector) tick() (rms []*metricspb.ResourceMetrics) {
	c.mu.Lock()
	defer c.mu.Unlock()
	defer func() {
		if r := recover(); r != nil {
			c.closeLocked()
			c.status.State, c.status.Detail = StateFailed, fmt.Sprintf("probe read panicked: %v", r)
			c.log.Error("eBPF probe detached after a panic", "panic", r)
			rms = nil
		}
	}()
	c.reconcileLocked()
	if c.src != nil {
		rms = c.collectLocked()
	}
	return rms
}

// reconcileLocked starts or stops the probe. Called with c.mu held.
func (c *Collector) reconcileLocked() {
	want := c.enabled && c.metricsOn.Load()
	c.status.Enabled = c.enabled
	if !want {
		c.closeLocked()
		c.status.State, c.status.Detail = StateOff, ""
		c.tried = false
		return
	}
	if c.src != nil {
		return
	}
	if c.autoDisabled != "" {
		c.status.State, c.status.Detail = StateOverBudget, c.autoDisabled
		return
	}
	if c.tried {
		return
	}
	c.tried = true
	c.detectLocked()
	if !c.status.Supported {
		c.status.State, c.status.Detail = StateUnsupported, c.status.Reason
		return
	}
	src, problems, err := c.safeOpen()
	if err != nil {
		c.status.State, c.status.Detail = StateFailed, err.Error()
		c.log.Warn("eBPF probe not started", "err", err)
		return
	}
	c.src = src
	c.reset()
	c.lastPass = c.now()
	c.status.State, c.status.Detail, c.status.OverheadPercent = StateRunning, "", nil
	if len(problems) > 0 {
		c.status.State, c.status.Detail = StateDegraded, "not attached: "+strings.Join(problems, "; ")
	}
	c.status.Programs = src.Groups()
	c.log.Info("eBPF probe started", "programs", c.status.Programs, "problems", problems)
}

// safeOpen loads the probe; a panic in the loader is an error like any other.
func (c *Collector) safeOpen() (src source, problems []string, err error) {
	defer func() {
		if r := recover(); r != nil {
			src, err = nil, fmt.Errorf("probe load panicked: %v", r)
		}
	}()
	return c.open(c.opts.FS)
}

func (c *Collector) closeLocked() {
	if c.src == nil {
		return
	}
	_ = c.src.Close()
	c.src = nil
	c.status.Programs = []string{}
	c.status.OverheadPercent = nil
	c.reset()
}

// apply applies the setting now (the command's answer reflects it). Turning it off, or an explicit re-enable, clears
// an over-budget stop; a plain "on" (the control plane re-sending the setting) leaves it stopped.
func (c *Collector) apply(cfg Config) Status {
	c.mu.Lock()
	on := cfg.Enabled
	if !on || cfg.Reenable {
		c.autoDisabled = ""
	}
	if c.enabled != on || !on || c.src == nil {
		// A new "on" retries a failed probe; "off" always detaches.
		c.tried = false
	}
	c.enabled, c.fullNames = on, cfg.FullDNSNames
	c.reconcileLocked()
	if err := c.saveLocked(); err != nil {
		c.log.Warn("eBPF: could not persist the setting", "err", err)
	}
	s := c.statusLocked()
	c.mu.Unlock()
	c.poke()
	return s
}

// workload is who a key is attributed to.
type workload struct {
	id     string // unique: host, ct/<id>, native/<slug>
	owner  string // resource: host, site/<slug>, db/<id>
	res    []*commonpb.KeyValue
	attrs  []*commonpb.KeyValue
	inHost bool
}

type attributor struct {
	byCg  map[uint64]*workload
	byPid map[uint32]*workload
	byUID map[uint32]*workload
	host  *workload
	all   map[string]bool
}

func (a *attributor) of(o owner) *workload {
	if o.CgID != 0 {
		if w := a.byCg[o.CgID]; w != nil {
			return w
		}
	}
	if o.TGID != 0 {
		if w := a.byPid[o.TGID]; w != nil {
			return w
		}
	}
	if o.UID != 0 {
		if w := a.byUID[o.UID]; w != nil {
			return w
		}
	}
	return a.host
}

// cgroupID is a cgroup v2 directory's id (its inode); 0 when it cannot be read.
func cgroupID(fs hostfs.FS, dir string) uint64 {
	if dir == "" {
		return 0
	}
	fi, err := os.Stat(fs.P(dir))
	if err != nil {
		return 0
	}
	if st, ok := fi.Sys().(*syscall.Stat_t); ok {
		return uint64(st.Ino) //nolint:unconvert // Ino is uint32 on some platforms
	}
	return 0
}

// attribution builds the lookup tables from the workload collector's last pass (docs/plans/network/ebpf.md,
// "Attribution"): container cgroups, site slices, native sites' processes, isolated sites' users, else the server.
func attribution(fs hostfs.FS, snap metrics.NetSnapshot) *attributor {
	host := &workload{id: "host", owner: "host", res: []*commonpb.KeyValue{str("service.name", hostService)},
		attrs: workloadAttrs("host", "host"), inHost: true}
	a := &attributor{byCg: map[uint64]*workload{}, byPid: map[uint32]*workload{}, byUID: map[uint32]*workload{}, host: host,
		all: map[string]bool{"host": true}}
	natives := map[string]*workload{}
	native := func(slug string) *workload {
		w := natives[slug]
		if w == nil {
			w = &workload{id: "native/" + slug, owner: "site/" + slug, res: []*commonpb.KeyValue{str("service.name", slug)},
				attrs: workloadAttrs("processes", slug), inHost: true}
			natives[slug] = w
			a.all[w.id] = true
		}
		return w
	}
	for _, ct := range snap.Containers {
		attrs := workloadAttrs("container", ct.Name, str("container.id", ct.ID))
		if ct.Service != "" {
			attrs = append(attrs, str("falak.compose.service", ct.Service))
		}
		w := &workload{id: "ct/" + ct.ID, attrs: attrs}
		if ct.DBInstance != "" {
			w.owner, w.res = "db/"+ct.DBInstance, []*commonpb.KeyValue{str("service.name", dbService), str("falak.db.instance.id", ct.DBInstance)}
		} else {
			w.owner, w.res = "site/"+ct.Site, []*commonpb.KeyValue{str("service.name", ct.Site)}
		}
		a.all[w.id] = true
		if id := cgroupID(fs, ct.CgroupDir); id != 0 {
			a.byCg[id] = w
		}
	}
	for dir, slug := range snap.Slices {
		if id := cgroupID(fs, dir); id != 0 {
			a.byCg[id] = native(slug)
		}
	}
	for pid, slug := range snap.Native {
		a.byPid[uint32(pid)] = native(slug)
	}
	for uid, slug := range snap.UIDs {
		if uid > 0 {
			a.byUID[uint32(uid)] = native(slug)
		}
	}
	return a
}

func dirName(d uint8) string {
	if d == dirIn {
		return "inbound"
	}
	return "outbound"
}

func peerAddr(k peerKey) netip.Addr {
	if k.Family == afInet {
		return netip.AddrFrom4([4]byte(k.Addr[:4]))
	}
	return netip.AddrFrom16(k.Addr).Unmap()
}

// bucketOnly names a peer without the control plane's names (no netobs): its bucket.
func bucketOnly(_ bool, dir string, a netip.Addr, port uint16) string {
	kind := "external"
	switch {
	case a.IsLoopback():
		kind = "local"
	case a.IsPrivate() || a.IsLinkLocalUnicast() || netip.MustParsePrefix("100.64.0.0/10").Contains(a):
		kind = "private"
	}
	if dir == "outbound" {
		return fmt.Sprintf("%s:%d", kind, port)
	}
	return kind
}

// sub is the increase of a cumulative kernel counter: a counter that went down belongs to a new key (an LRU
// eviction and a new entry), whose whole value is new.
func sub(cur, prev uint64, reset bool) uint64 {
	if reset {
		return cur
	}
	return cur - prev
}

func peerDelta(prev, cur peerVal) peerVal {
	r := cur.Retrans < prev.Retrans || cur.RstSent < prev.RstSent || cur.RstRecv < prev.RstRecv || cur.Connects < prev.Connects ||
		cur.ConnectFail < prev.ConnectFail || cur.RTTSumUS < prev.RTTSumUS || cur.RTTCount < prev.RTTCount
	return peerVal{sub(cur.Retrans, prev.Retrans, r), sub(cur.RstSent, prev.RstSent, r), sub(cur.RstRecv, prev.RstRecv, r),
		sub(cur.Connects, prev.Connects, r), sub(cur.ConnectFail, prev.ConnectFail, r), sub(cur.RTTSumUS, prev.RTTSumUS, r),
		sub(cur.RTTCount, prev.RTTCount, r)}
}

func dnsDelta(prev, cur dnsVal) dnsVal {
	r := cur.Queries < prev.Queries || cur.Responses < prev.Responses || cur.NXDomain < prev.NXDomain || cur.ServFail < prev.ServFail ||
		cur.Refused < prev.Refused || cur.OtherErr < prev.OtherErr || cur.LatSumUS < prev.LatSumUS
	return dnsVal{sub(cur.Queries, prev.Queries, r), sub(cur.Responses, prev.Responses, r), sub(cur.NXDomain, prev.NXDomain, r),
		sub(cur.ServFail, prev.ServFail, r), sub(cur.Refused, prev.Refused, r), sub(cur.OtherErr, prev.OtherErr, r),
		sub(cur.LatSumUS, prev.LatSumUS, r)}
}

func histDelta(prev, cur histVal) histVal {
	r := cur.Count < prev.Count || cur.SumUS < prev.SumUS
	for i := range cur.Slots {
		r = r || cur.Slots[i] < prev.Slots[i]
	}
	var d histVal
	for i := range cur.Slots {
		d.Slots[i] = sub(cur.Slots[i], prev.Slots[i], r)
	}
	d.SumUS, d.Count = sub(cur.SumUS, prev.SumUS, r), sub(cur.Count, prev.Count, r)
	return d
}

type peerAgg struct {
	w          *workload
	dir, label string
	d          peerVal
}

func (p *peerAgg) activity() uint64 {
	return p.d.Retrans + p.d.RstSent + p.d.RstRecv + p.d.Connects + p.d.ConnectFail + p.d.RTTCount
}

// collectLocked reads the maps once: deltas, attribution, top-N folding, the budget. Called with c.mu held.
func (c *Collector) collectLocked() []*metricspb.ResourceMetrics {
	now := c.now()
	start := time.Now()
	c.flushPending()
	var snap metrics.NetSnapshot
	if c.opts.Workloads != nil {
		snap = c.opts.Workloads.NetSnapshot()
	}
	at := attribution(c.opts.FS, snap)
	name := bucketOnly
	if c.opts.Names != nil {
		name = c.opts.Names(snap)
	}
	var gauges []gaugePoint

	// TCP per peer.
	aggs := map[string]*peerAgg{}
	prevPeers := make(map[peerKey]peerVal, len(c.prevPeers))
	err := c.src.Peers(func(k peerKey, v peerVal) {
		prevPeers[k] = v
		d := peerDelta(c.prevPeers[k], v)
		if d == (peerVal{}) {
			return
		}
		w := at.of(k.O)
		dir := dirName(k.Dir)
		if k.Dir == dirOut && d.Connects > 0 {
			ck := Connect{Workload: w.id, Addr: peerAddr(k), Port: k.Port}
			if c.connects == nil {
				c.connects = map[Connect]uint64{}
			}
			if _, ok := c.connects[ck]; ok || len(c.connects) < maxConnects {
				c.connects[ck] += d.Connects
			}
		}
		label := name(w.inHost, dir, peerAddr(k), k.Port)
		key := w.id + "|" + dir + "|" + label
		a := aggs[key]
		if a == nil {
			a = &peerAgg{w: w, dir: dir, label: label}
			aggs[key] = a
		}
		a.d = addPeer(a.d, d)
	})
	if err != nil {
		c.log.Debug("eBPF peers read failed", "err", err)
	}
	c.prevPeers = prevPeers
	for _, a := range foldPeers(aggs) {
		kv := []*commonpb.KeyValue{str("falak.net.peer", a.label), str("falak.net.direction", a.dir)}
		c.add(a.w, "falak.net.tcp.retransmits", float64(a.d.Retrans), now, true, kv...)
		c.add(a.w, "falak.net.tcp.resets", float64(a.d.RstSent), now, true, append(kv, str("falak.net.reset.direction", "sent"))...)
		c.add(a.w, "falak.net.tcp.resets", float64(a.d.RstRecv), now, true, append(kv, str("falak.net.reset.direction", "received"))...)
		peer := str("falak.net.peer", a.label)
		c.add(a.w, "falak.net.tcp.connects", float64(a.d.Connects), now, true, peer, str("falak.net.outcome", "ok"))
		c.add(a.w, "falak.net.tcp.connects", float64(a.d.ConnectFail), now, true, peer, str("falak.net.outcome", "failed"))
		if a.d.RTTCount > 0 {
			gauges = append(gauges, gaugePoint{w: a.w, name: "falak.net.tcp.peer.rtt",
				v: float64(a.d.RTTSumUS) / float64(a.d.RTTCount) / 1e6, attrs: with(a.w.attrs, kv...)})
		}
	}

	// Histograms.
	prevHists := make(map[histKey]histVal, len(c.prevHists))
	if err := c.src.Hists(func(k histKey, v histVal) {
		prevHists[k] = v
		d := histDelta(c.prevHists[k], v)
		if d.Count == 0 {
			return
		}
		metric := histMetric(k.Kind)
		if metric == "" {
			return
		}
		c.addHist(at.of(k.O), metric, d, now)
	}); err != nil {
		c.log.Debug("eBPF histograms read failed", "err", err)
	}
	c.prevHists = prevHists

	// DNS.
	type nameAgg struct {
		w       *workload
		name    string
		queries uint64
	}
	names := map[string]*nameAgg{}
	prevDNS := make(map[dnsKey]dnsVal, len(c.prevDNS))
	if err := c.src.DNSNames(func(k dnsKey, v dnsVal) {
		prevDNS[k] = v
		d := dnsDelta(c.prevDNS[k], v)
		if d == (dnsVal{}) {
			return
		}
		w := at.of(owner{CgID: k.CgID, UID: k.UID})
		c.add(w, "falak.net.dns.queries", float64(d.Queries), now, false)
		for _, f := range []struct {
			kind string
			n    uint64
		}{{"nxdomain", d.NXDomain}, {"servfail", d.ServFail}, {"refused", d.Refused}, {"other", d.OtherErr}} {
			if f.n > 0 {
				c.add(w, "falak.net.dns.failures", float64(f.n), now, false, str("falak.net.dns.error", f.kind))
			}
		}
		if d.Queries > 0 {
			qn, _ := decodeQName(k.QName[:])
			if !c.fullNames {
				qn = registrable(qn)
			}
			key := w.id + "|" + qn
			if names[key] == nil {
				names[key] = &nameAgg{w: w, name: qn}
			}
			names[key].queries += d.Queries
		}
	}); err != nil {
		c.log.Debug("eBPF DNS read failed", "err", err)
	}
	c.prevDNS = prevDNS
	late, err := c.src.ExpirePending(dnsTimeout)
	if err != nil {
		c.log.Debug("eBPF DNS pending read failed", "err", err)
	}
	// Unanswered after dnsTimeout: a resolver that did not answer, or an answer the probe could not match (a reply from
	// another socket). Counted apart (error "timeout"), never part of the failure alert.
	for _, p := range late {
		c.add(at.of(owner{CgID: p.K.CgID, UID: p.K.UID}), "falak.net.dns.failures", 1, now, false, str("falak.net.dns.error", "timeout"))
	}
	byW := map[string]map[string]uint64{}
	wls := map[string]*workload{}
	for _, n := range names {
		if byW[n.w.id] == nil {
			byW[n.w.id] = map[string]uint64{}
		}
		byW[n.w.id][n.name] += n.queries
		wls[n.w.id] = n.w
	}
	c.names.update(byW, now.Sub(c.lastPass))
	for id, list := range byW {
		folded := map[string]uint64{}
		for name, q := range list {
			folded[c.names.label(id, name)] += q
		}
		for label, q := range folded {
			c.add(wls[id], "falak.net.dns.name.queries", float64(q), now, true, str("falak.net.dns.name", label))
		}
	}
	if n, err := c.src.SweepRunq(runqStale); err == nil && n > 0 {
		c.log.Debug("eBPF run-queue stamps swept", "count", n)
	}

	// The probe's own cost and the budget.
	rt := c.src.Runtime()
	spent := time.Since(start)
	c.add(at.host, "falak.ebpf.cpu.time", spent.Seconds(), now, false, str("falak.ebpf.component", "agent"))
	for g, d := range rt {
		prev := c.prevRuntime[g]
		if d < prev {
			prev = 0
		}
		c.add(at.host, "falak.ebpf.cpu.time", (d - prev).Seconds(), now, false, str("falak.ebpf.component", g))
		spent += d - prev
	}
	c.prevRuntime = rt
	active := map[string]bool{}
	for _, g := range c.src.Groups() {
		active[g] = true
	}
	for _, g := range []string{groupTCP, groupDNS, groupRunq} {
		v := 0.0
		if active[g] {
			v = 1
		}
		gauges = append(gauges, gaugePoint{w: at.host, name: "falak.ebpf.active", v: v, attrs: []*commonpb.KeyValue{str("falak.ebpf.component", g)}})
	}
	wall := now.Sub(c.lastPass)
	c.lastPass = now
	c.expire(now, at.all)
	out := c.encode(gauges, now)
	c.budget(spent, wall)
	return out
}

func addPeer(a, b peerVal) peerVal {
	return peerVal{a.Retrans + b.Retrans, a.RstSent + b.RstSent, a.RstRecv + b.RstRecv, a.Connects + b.Connects,
		a.ConnectFail + b.ConnectFail, a.RTTSumUS + b.RTTSumUS, a.RTTCount + b.RTTCount}
}

// foldPeers keeps the maxPeers busiest labels of each workload and direction and folds the rest into "other".
func foldPeers(aggs map[string]*peerAgg) []*peerAgg {
	groups := map[string][]*peerAgg{}
	for _, a := range aggs {
		k := a.w.id + "|" + a.dir
		groups[k] = append(groups[k], a)
	}
	keys := make([]string, 0, len(groups))
	for k := range groups {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	var out []*peerAgg
	for _, k := range keys {
		list := groups[k]
		sort.Slice(list, func(i, j int) bool {
			if list[i].activity() != list[j].activity() {
				return list[i].activity() > list[j].activity()
			}
			return list[i].label < list[j].label
		})
		var other *peerAgg
		for i, a := range list {
			if i < maxPeers && a.label != otherLabel {
				out = append(out, a)
				continue
			}
			if other == nil {
				other = &peerAgg{w: a.w, dir: a.dir, label: otherLabel}
			}
			other.d = addPeer(other.d, a.d)
		}
		if other != nil {
			out = append(out, other)
		}
	}
	return out
}

func histMetric(kind uint32) string {
	switch kind {
	case kindConnect:
		return "falak.net.tcp.connect.duration"
	case kindRTT:
		return "falak.net.tcp.rtt"
	case kindDNS:
		return "falak.net.dns.duration"
	case kindRunq:
		return "falak.workload.runqueue.delay"
	}
	return ""
}

// budget records the pass's cost and enforces the budget over budgetWindow: the run-queue programs go first, then
// the whole probe (until the setting is turned on again or the agent restarts).
func (c *Collector) budget(spent, wall time.Duration) {
	if wall < time.Second {
		// A sub-second wall means the probe just started: reconcileLocked set lastPass moments before
		// collectLocked ran in the same tick, so wall is only the overhead between those two calls.
		// BPF_STATS_RUN_TIME counters have not accumulated a real interval; the per-pass ratio is
		// meaningless and would trip the burst check on an innocent first read. Skip.
		return
	}
	c.costs = append(c.costs, cost{spent, wall})
	var s, w time.Duration
	for i := len(c.costs) - 1; i >= 0; i-- {
		s += c.costs[i].spent
		w += c.costs[i].wall
		if w >= budgetWindow {
			c.costs = c.costs[i:]
			break
		}
	}
	cpus := float64(max(1, c.cpus))
	ratio := s.Seconds() / (w.Seconds() * cpus)
	pct := float64(int(ratio*10000+0.5)) / 100
	c.status.OverheadPercent = &pct
	pass := spent.Seconds() / (wall.Seconds() * cpus)
	var detail string
	switch {
	case pass > budgetBurst*budgetRatio:
		detail = fmt.Sprintf("%.2f %% of CPU in one pass (budget %.0f %%)", pass*100, budgetRatio*100)
	case w >= budgetWindow && ratio > budgetRatio:
		detail = fmt.Sprintf("%.2f %% of CPU over %s (budget %.0f %%)", ratio*100, budgetWindow, budgetRatio*100)
	default:
		return
	}
	for _, g := range c.src.Groups() {
		if g == groupRunq {
			c.src.Shed(groupRunq)
			c.status.State, c.status.Detail, c.status.Programs = StateDegraded, "run-queue programs shed: "+detail, c.src.Groups()
			c.costs = nil
			c.log.Warn("eBPF probe over budget: run-queue programs detached", "overhead", detail)
			return
		}
	}
	c.closeLocked()
	c.autoDisabled = detail
	c.status.State, c.status.Detail = StateOverBudget, detail
	if err := c.saveLocked(); err != nil {
		c.log.Warn("eBPF: could not persist the over-budget stop", "err", err)
	}
	c.log.Warn("eBPF probe over budget: detached", "overhead", detail)
}

// decodeQName turns a wire-format name into text: lowercase, dot-separated, "." for the root; complete is false when
// the kernel's copy ended before the root label (a long name, marked with a trailing "…").
func decodeQName(b []byte) (name string, complete bool) {
	var labels []string
	i := 0
	for i < len(b) {
		l := int(b[i])
		if l == 0 {
			complete = true
			break
		}
		if l > 63 {
			break
		}
		end := min(i+1+l, len(b))
		labels = append(labels, cleanLabel(b[i+1:end]))
		if end < i+1+l {
			break
		}
		i = end
	}
	if len(labels) == 0 && complete {
		return ".", true
	}
	name = strings.Join(labels, ".")
	if !complete {
		name += "…"
	}
	return name, complete
}

func cleanLabel(b []byte) string {
	out := make([]byte, len(b))
	for i, ch := range b {
		switch {
		case ch >= 'A' && ch <= 'Z':
			out[i] = ch + 'a' - 'A'
		case ch > 0x20 && ch < 0x7f:
			out[i] = ch
		default:
			out[i] = '?'
		}
	}
	return string(out)
}
