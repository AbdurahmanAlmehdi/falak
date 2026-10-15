package ebpfobs

import (
	"context"
	"encoding/binary"
	"encoding/json"
	"errors"
	"fmt"
	"net/netip"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"syscall"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/metrics"
	commonpb "go.opentelemetry.io/proto/otlp/common/v1"
	metricspb "go.opentelemetry.io/proto/otlp/metrics/v1"
)

// fakeSource is a probe whose maps the test fills.
type fakeSource struct {
	peers   map[peerKey]peerVal
	hists   map[histKey]histVal
	dns     map[dnsKey]dnsVal
	pending []dnsPending
	runtime map[string]time.Duration
	groups  []string
	closed  bool
	sweeps  int
}

func newFake() *fakeSource {
	return &fakeSource{peers: map[peerKey]peerVal{}, hists: map[histKey]histVal{}, dns: map[dnsKey]dnsVal{},
		runtime: map[string]time.Duration{}, groups: []string{groupDNS, groupRunq, groupTCP}}
}

func (f *fakeSource) Peers(fn func(peerKey, peerVal)) error {
	for k, v := range f.peers {
		fn(k, v)
	}
	return nil
}

func (f *fakeSource) Hists(fn func(histKey, histVal)) error {
	for k, v := range f.hists {
		fn(k, v)
	}
	return nil
}

func (f *fakeSource) DNSNames(fn func(dnsKey, dnsVal)) error {
	for k, v := range f.dns {
		fn(k, v)
	}
	return nil
}

func (f *fakeSource) ExpirePending(time.Duration) ([]dnsPending, error) {
	p := f.pending
	f.pending = nil
	return p, nil
}

func (f *fakeSource) Runtime() map[string]time.Duration {
	out := map[string]time.Duration{}
	for k, v := range f.runtime {
		out[k] = v
	}
	return out
}

func (f *fakeSource) SweepRunq(time.Duration) (int, error) { f.sweeps++; return 0, nil }

func (f *fakeSource) Groups() []string { return append([]string(nil), f.groups...) }

func (f *fakeSource) Shed(g string) {
	var keep []string
	for _, x := range f.groups {
		if x != g {
			keep = append(keep, x)
		}
	}
	f.groups = keep
}

func (f *fakeSource) Close() error { f.closed = true; return nil }

type snap metrics.NetSnapshot

func (s snap) NetSnapshot() metrics.NetSnapshot { return metrics.NetSnapshot(s) }

type sink struct{ rms []*metricspb.ResourceMetrics }

func (s *sink) EmitMetrics(rm *metricspb.ResourceMetrics) { s.rms = append(s.rms, rm) }

// testCollector is a collector on src, enabled, on a fixed clock that the test advances.
func testCollector(t *testing.T, src *fakeSource, opts Options) (*Collector, *sink, *time.Time) {
	t.Helper()
	out := &sink{}
	opts.Out = out
	if opts.EtcDir == "" {
		opts.EtcDir = "/etc/falak"
		opts.FS = hostfs.FS{Root: t.TempDir()}
	}
	c := New(opts)
	now := time.Date(2026, 10, 15, 12, 0, 0, 0, time.UTC)
	c.now = func() time.Time { return now }
	c.check = func() (bool, string) { return true, "" }
	c.open = func(hostfs.FS) (source, []string, error) { return src, nil, nil }
	c.cpus = 2
	c.apply(Config{Enabled: true})
	return c, out, &now
}

// pass runs one pass and returns its points: "metric{attr=value,...}" => value (histograms: _count and _sum).
func pass(c *Collector, out *sink, now *time.Time, step time.Duration) map[string]float64 {
	*now = now.Add(step)
	out.rms = nil
	c.Tick()
	return flatten(out.rms)
}

func flatten(rms []*metricspb.ResourceMetrics) map[string]float64 {
	pts := map[string]float64{}
	key := func(res, metric string, attrs map[string]string) string {
		keys := make([]string, 0, len(attrs))
		for k := range attrs {
			keys = append(keys, k)
		}
		sort.Strings(keys)
		parts := []string{"svc=" + res}
		for _, k := range keys {
			parts = append(parts, k+"="+attrs[k])
		}
		return metric + "{" + strings.Join(parts, ",") + "}"
	}
	for _, rm := range rms {
		svc := ""
		for _, a := range rm.Resource.Attributes {
			if a.Key == "service.name" {
				svc = a.Value.GetStringValue()
			}
		}
		for _, sm := range rm.ScopeMetrics {
			for _, m := range sm.Metrics {
				attrsOf := func(kv []*commonpb.KeyValue) map[string]string {
					out := map[string]string{}
					for _, a := range kv {
						out[a.Key] = a.Value.GetStringValue()
					}
					return out
				}
				switch d := m.Data.(type) {
				case *metricspb.Metric_Sum:
					for _, p := range d.Sum.DataPoints {
						pts[key(svc, m.Name, attrsOf(p.Attributes))] = p.GetAsDouble()
					}
				case *metricspb.Metric_Gauge:
					for _, p := range d.Gauge.DataPoints {
						pts[key(svc, m.Name, attrsOf(p.Attributes))] = p.GetAsDouble()
					}
				case *metricspb.Metric_Histogram:
					for _, p := range d.Histogram.DataPoints {
						a := attrsOf(p.Attributes)
						pts[key(svc, m.Name+"_count", a)] = float64(p.Count)
						pts[key(svc, m.Name+"_sum", a)] = p.GetSum()
						for i, n := range p.BucketCounts {
							if n > 0 {
								pts[key(svc, fmt.Sprintf("%s_bucket[%d]", m.Name, i), a)] = float64(n)
							}
						}
					}
				}
			}
		}
	}
	return pts
}

func v4(a string) [16]byte {
	var out [16]byte
	ip := netip.MustParseAddr(a).As4()
	copy(out[:], ip[:])
	return out
}

func qname(name string) [qnameLen]byte {
	var out [qnameLen]byte
	i := 0
	for _, l := range strings.Split(name, ".") {
		out[i] = byte(len(l))
		copy(out[i+1:], l)
		i += len(l) + 1
	}
	return out
}

func TestMapLayoutsMatchTheObject(t *testing.T) {
	spec, err := loadProbe()
	if err != nil {
		t.Fatal(err)
	}
	for name, kv := range map[string][2]any{
		"conns": {uint64(0), [32]byte{}}, "peers": {peerKey{}, peerVal{}}, "hists": {histKey{}, histVal{}},
		"dns_names": {dnsKey{}, dnsVal{}}, "dns_pending": {dnsPendingKey{}, dnsPending{}}, "runq_start": {uint32(0), uint64(0)},
	} {
		m := spec.Maps[name]
		if m == nil {
			t.Fatalf("map %s missing", name)
		}
		if k, v := binary.Size(kv[0]), binary.Size(kv[1]); uint32(k) != m.KeySize || uint32(v) != m.ValueSize {
			t.Errorf("%s: Go sizes %d/%d, object %d/%d", name, k, v, m.KeySize, m.ValueSize)
		}
	}
	for g, progs := range map[string][]string{groupTCP: {"on_set_state", "on_retransmit", "on_send_reset", "on_receive_reset"},
		groupDNS: {"dns_egress", "dns_ingress"}, groupRunq: {"on_wakeup", "on_wakeup_new", "on_switch", "on_exit"}} {
		for _, p := range progs {
			if spec.Programs[p] == nil {
				t.Errorf("%s: program %s missing from the object", g, p)
			}
		}
	}
}

func TestAttribution(t *testing.T) {
	root := t.TempDir()
	fs := hostfs.FS{Root: root}
	for _, d := range []string{"/sys/fs/cgroup/system.slice/docker-abc.scope", "/sys/fs/cgroup/falak.slice/falak-site_blog.slice"} {
		if err := os.MkdirAll(filepath.Join(root, d), 0o755); err != nil {
			t.Fatal(err)
		}
	}
	ino := func(d string) uint64 { return cgroupID(fs, d) }
	ct := ino("/sys/fs/cgroup/system.slice/docker-abc.scope")
	slice := ino("/sys/fs/cgroup/falak.slice/falak-site_blog.slice")
	if ct == 0 || slice == 0 || ct == slice {
		t.Fatalf("inodes %d %d", ct, slice)
	}
	a := attribution(fs, metrics.NetSnapshot{
		Containers: []metrics.NetContainer{{ID: "abc", Name: "shop-web", Site: "shop", Service: "web", CgroupDir: "/sys/fs/cgroup/system.slice/docker-abc.scope"}},
		Slices:     map[string]string{"/sys/fs/cgroup/falak.slice/falak-site_blog.slice": "blog"},
		Native:     map[int]string{4242: "api"},
		UIDs:       map[int]string{1005: "wiki"},
	})
	for _, tc := range []struct {
		o    owner
		want string
	}{
		{owner{CgID: ct, TGID: 4242, UID: 1005}, "ct/abc"}, // the cgroup wins
		{owner{CgID: slice}, "native/blog"},
		{owner{CgID: 99, TGID: 4242}, "native/api"},
		{owner{CgID: 99, UID: 1005}, "native/wiki"},
		{owner{CgID: 99, TGID: 7, UID: 33}, "host"},
		{owner{}, "host"},
	} {
		if got := a.of(tc.o).id; got != tc.want {
			t.Errorf("%+v => %s, want %s", tc.o, got, tc.want)
		}
	}
	if w := a.of(owner{CgID: ct}); w.owner != "site/shop" {
		t.Errorf("container owner %s", w.owner)
	}
}

func TestTCPCountersDeltasAndResets(t *testing.T) {
	src := newFake()
	k := peerKey{O: owner{CgID: 1}, Addr: v4("10.0.0.5"), Port: 5432, Family: afInet, Dir: dirOut}
	in := peerKey{O: owner{CgID: 1}, Addr: v4("203.0.113.9"), Family: afInet, Dir: dirIn}
	src.peers[k] = peerVal{Retrans: 3, Connects: 10, ConnectFail: 1, RTTSumUS: 4000, RTTCount: 2}
	src.peers[in] = peerVal{RstSent: 2}
	c, out, now := testCollector(t, src, Options{Names: func(metrics.NetSnapshot) func(bool, string, netip.Addr, uint16) string {
		return func(_ bool, dir string, a netip.Addr, port uint16) string {
			if a == netip.MustParseAddr("10.0.0.5") && port == 5432 {
				return "db:01kdb"
			}
			return bucketOnly(true, dir, a, port)
		}
	}})
	p := pass(c, out, now, 15*time.Second)
	retr := `falak.net.tcp.retransmits{svc=falak-host,falak.net.direction=outbound,falak.net.peer=db:01kdb,falak.workload.kind=host,falak.workload.name=host}`
	// A new series is shipped at 0 first (rate() takes a counter's first sample as its baseline), its count next pass.
	if v, ok := p[retr]; !ok || v != 0 || src.sweeps != 1 {
		t.Fatalf("first pass: retransmits %v (present %v), sweeps %d", v, ok, src.sweeps)
	}
	rtt := p[`falak.net.tcp.peer.rtt{svc=falak-host,falak.net.direction=outbound,falak.net.peer=db:01kdb,falak.workload.kind=host,falak.workload.name=host}`]
	p = pass(c, out, now, 15*time.Second)
	if p[retr] != 3 {
		t.Fatalf("retransmits = %v\n%v", p[retr], keys(p))
	}
	if v := p[`falak.net.tcp.connects{svc=falak-host,falak.net.outcome=failed,falak.net.peer=db:01kdb,falak.workload.kind=host,falak.workload.name=host}`]; v != 1 {
		t.Errorf("failed connects = %v", v)
	}
	if v := p[`falak.net.tcp.resets{svc=falak-host,falak.net.direction=inbound,falak.net.peer=external,falak.net.reset.direction=sent,falak.workload.kind=host,falak.workload.name=host}`]; v != 2 {
		t.Errorf("resets sent to an inbound external peer = %v", v)
	}
	if rtt != 0.002 {
		t.Errorf("mean RTT = %v", rtt)
	}

	// Next pass: +2 retransmits; the counter adds up.
	src.peers[k] = peerVal{Retrans: 5, Connects: 10, ConnectFail: 1, RTTSumUS: 4000, RTTCount: 2}
	p = pass(c, out, now, 15*time.Second)
	if p[retr] != 5 {
		t.Errorf("retransmits after +2 = %v", p[retr])
	}
	if _, ok := p[`falak.net.tcp.peer.rtt{svc=falak-host,falak.net.direction=outbound,falak.net.peer=db:01kdb,falak.workload.kind=host,falak.workload.name=host}`]; ok {
		t.Error("an RTT gauge without new samples")
	}
	// The kernel entry was evicted and recreated (its counter went down): its whole value is new.
	src.peers[k] = peerVal{Retrans: 1}
	p = pass(c, out, now, 15*time.Second)
	if p[retr] != 6 {
		t.Errorf("retransmits after a reset = %v", p[retr])
	}
}

func keys(m map[string]float64) string {
	out := make([]string, 0, len(m))
	for k := range m {
		out = append(out, k)
	}
	sort.Strings(out)
	return strings.Join(out, "\n")
}

func TestPeersFoldedToTopN(t *testing.T) {
	src := newFake()
	for i := 0; i < maxPeers+5; i++ {
		k := peerKey{O: owner{CgID: 1}, Addr: v4(fmt.Sprintf("198.51.100.%d", i+1)), Port: uint16(1000 + i), Family: afInet, Dir: dirOut}
		src.peers[k] = peerVal{Retrans: uint64(100 - i)}
	}
	c, out, now := testCollector(t, src, Options{})
	pass(c, out, now, 15*time.Second)
	p := pass(c, out, now, 15*time.Second)
	labels, other := 0, 0.0
	for k, v := range p {
		if !strings.HasPrefix(k, "falak.net.tcp.retransmits{") {
			continue
		}
		labels++
		if strings.Contains(k, "falak.net.peer=other") {
			other = v
		}
	}
	// The five quietest (retransmits 76..80, peers 21..25) are folded.
	if labels != maxPeers+1 || other != 76+77+78+79+80 {
		t.Errorf("labels %d, other %v", labels, other)
	}
}

func TestDNSNamesFailuresAndTimeouts(t *testing.T) {
	src := newFake()
	for i := 0; i < maxNames+2; i++ {
		src.dns[dnsKey{CgID: 1, QName: qname(fmt.Sprintf("api.N%02d.com", i))}] = dnsVal{Queries: uint64(50 - i), Responses: uint64(50 - i)}
	}
	// Folded to their registrable domain: 70 + 30 queries of stripe.com.
	src.dns[dnsKey{CgID: 1, QName: qname("api.stripe.com")}] = dnsVal{Queries: 70, Responses: 69, ServFail: 4, NXDomain: 2}
	src.dns[dnsKey{CgID: 1, QName: qname("files.eu.stripe.com")}] = dnsVal{Queries: 30, Responses: 30}
	src.pending = []dnsPending{{TS: 1, K: dnsKey{CgID: 1, QName: qname("slow.example")}}}
	src.hists[histKey{O: owner{CgID: 1}, Kind: kindDNS}] = histVal{Slots: [slots]uint64{10: 3, 26: 1}, SumUS: 5000, Count: 4}
	c, out, now := testCollector(t, src, Options{})
	pass(c, out, now, 15*time.Second)
	p := pass(c, out, now, 15*time.Second)
	host := "svc=falak-host,"
	want := map[string]float64{
		`falak.net.dns.queries{` + host + `falak.workload.kind=host,falak.workload.name=host}`:                                    100 + 50*12 - 66,
		`falak.net.dns.failures{` + host + `falak.net.dns.error=servfail,falak.workload.kind=host,falak.workload.name=host}`:      4,
		`falak.net.dns.failures{` + host + `falak.net.dns.error=nxdomain,falak.workload.kind=host,falak.workload.name=host}`:      2,
		`falak.net.dns.failures{` + host + `falak.net.dns.error=timeout,falak.workload.kind=host,falak.workload.name=host}`:       1,
		`falak.net.dns.name.queries{` + host + `falak.net.dns.name=stripe.com,falak.workload.kind=host,falak.workload.name=host}`: 100,
		`falak.net.dns.name.queries{` + host + `falak.net.dns.name=n00.com,falak.workload.kind=host,falak.workload.name=host}`:    50,
		`falak.net.dns.name.queries{` + host + `falak.net.dns.name=other,falak.workload.kind=host,falak.workload.name=host}`:      41 + 40 + 39,
		`falak.net.dns.duration_count{` + host + `falak.workload.kind=host,falak.workload.name=host}`:                             4,
		`falak.net.dns.duration_bucket[9]{` + host + `falak.workload.kind=host,falak.workload.name=host}`:                         3,
		`falak.net.dns.duration_bucket[23]{` + host + `falak.workload.kind=host,falak.workload.name=host}`:                        1,
		`falak.net.dns.duration_sum{` + host + `falak.workload.kind=host,falak.workload.name=host}`:                               0.005,
	}
	for k, v := range want {
		if p[k] != v {
			t.Errorf("%s = %v, want %v", k, p[k], v)
		}
	}
	names := 0
	for k := range p {
		if strings.HasPrefix(k, "falak.net.dns.name.queries{") {
			names++
		}
	}
	if names != maxNames+1 {
		t.Errorf("%d name series, want %d", names, maxNames+1)
	}
}

func TestBucketBounds(t *testing.T) {
	if len(bounds) != 23 || bounds[0] != 4e-6 || bounds[22] != 16.777216 {
		t.Fatalf("bounds %v", bounds)
	}
	// slot 0 [0,2) and 1 [2,4) µs ≤ 4 µs; slot i ≤ 2^(i+1) µs; from slot 24 (≥ 16.8 s) the overflow bucket.
	for slot, want := range map[int]int{0: 0, 1: 0, 2: 1, 10: 9, 23: 22, 24: 23, 26: 23} {
		if got := bucketOf(slot); got != want {
			t.Errorf("slot %d => bucket %d, want %d", slot, got, want)
		}
	}
}

func TestDecodeQName(t *testing.T) {
	long := [qnameLen]byte{}
	for i := 0; i < qnameLen; i += 10 {
		long[i] = 9
		copy(long[i+1:], "abcdefghi")
	}
	for _, tc := range []struct {
		in       []byte
		want     string
		complete bool
	}{
		{[]byte{3, 'W', 'w', 'W', 6, 'g', 'o', 'o', 'g', 'l', 'e', 3, 'c', 'o', 'm', 0}, "www.google.com", true},
		{[]byte{0}, ".", true},
		{[]byte{2, 'a', 0x01, 0}, "a?", true},
		{long[:], "abcdefghi.abcdefghi.abcdefghi.abcdefghi.abcdefghi.abcdefghi.abc…", false},
		{[]byte{0xc0, 0x0c}, "…", false},
	} {
		got, complete := decodeQName(tc.in)
		if got != tc.want || complete != tc.complete {
			t.Errorf("%v => %q %v, want %q %v", tc.in, got, complete, tc.want, tc.complete)
		}
	}
}

func TestBudgetShedsRunqThenDetaches(t *testing.T) {
	src := newFake()
	c, out, now := testCollector(t, src, Options{})
	// 2 CPUs × 15 s = 30 s of capacity per pass; 0.6 s in the kernel each pass = 2 %.
	for i := 0; i < 20; i++ {
		src.runtime[groupRunq] += 600 * time.Millisecond
		pass(c, out, now, 15*time.Second)
	}
	s := c.Status()
	if s.State != StateDegraded || strings.Join(s.Programs, ",") != "dns,tcp" || !strings.Contains(s.Detail, "run-queue programs shed") {
		t.Fatalf("after 5 minutes at 2 %%: %+v", s)
	}
	if s.OverheadPercent == nil || *s.OverheadPercent < 1.9 {
		t.Errorf("overhead %v", s.OverheadPercent)
	}
	for i := 0; i < 20; i++ {
		src.runtime[groupTCP] += 600 * time.Millisecond
		pass(c, out, now, 15*time.Second)
	}
	s = c.Status()
	if s.State != StateOverBudget || !src.closed || len(s.Programs) != 0 {
		t.Fatalf("still over budget: %+v (closed %v)", s, src.closed)
	}
	// Not retried by the loop, nor by the control plane re-sending the setting (an upgrade, the agent back online),
	// nor by a restart: only an explicit re-enable.
	opened := 0
	c.open = func(hostfs.FS) (source, []string, error) { opened++; return newFake(), nil, nil }
	pass(c, out, now, 15*time.Second)
	if s := c.apply(Config{Enabled: true}); opened != 0 || s.State != StateOverBudget {
		t.Fatalf("re-sent setting reopened it: opened %d, %+v", opened, s)
	}
	b, _ := c.opts.FS.ReadFile("/etc/falak/ebpf.json")
	if !strings.Contains(string(b), `"auto_disabled":"`) {
		t.Errorf("not persisted: %s", b)
	}
	restarted := New(Options{FS: c.opts.FS, EtcDir: "/etc/falak"})
	restarted.check = func() (bool, string) { return true, "" }
	restarted.open = c.open
	restarted.Tick()
	if s := restarted.Status(); opened != 0 || s.State != StateOverBudget || !s.Enabled {
		t.Fatalf("restart reopened it: opened %d, %+v", opened, s)
	}
	if s := c.apply(Config{Enabled: true, Reenable: true}); opened != 1 || s.State != StateRunning {
		t.Errorf("re-enable: opened %d, %+v", opened, s)
	}
	if b, _ := c.opts.FS.ReadFile("/etc/falak/ebpf.json"); strings.Contains(string(b), "auto_disabled") {
		t.Errorf("re-enable kept the stop: %s", b)
	}
}

func TestBudgetBurstTripsInOnePass(t *testing.T) {
	src := newFake()
	c, out, now := testCollector(t, src, Options{})
	pass(c, out, now, 15*time.Second)
	src.runtime[groupRunq] += 2 * time.Second // 6.7 % of 2 CPUs × 15 s
	pass(c, out, now, 15*time.Second)
	if s := c.Status(); s.State != StateDegraded || !strings.Contains(s.Detail, "in one pass") {
		t.Errorf("%+v", s)
	}
}

func TestStatusTextsFitTheSchema(t *testing.T) {
	c, _, _ := testCollector(t, newFake(), Options{})
	c.mu.Lock()
	c.status.Detail, c.status.Reason = strings.Repeat("é", 5000), strings.Repeat("x", 400)
	c.mu.Unlock()
	s := c.Status()
	if n := len([]rune(s.Detail)); n != maxDetail {
		t.Errorf("detail %d runes", n)
	}
	if n := len([]rune(s.Reason)); n != maxReason {
		t.Errorf("reason %d runes", n)
	}
}

func TestRegistrableDomain(t *testing.T) {
	for in, want := range map[string]string{
		"api.eu.stripe.com": "stripe.com", "stripe.com": "stripe.com", "a.b.example.co.uk": "example.co.uk",
		"x.example.com.au": "example.com.au", "redis": "redis", ".": ".", "4.3.2.1.in-addr.arpa": "in-addr.arpa",
		"abcdefghi.abcdefghi.abc…": longName, "www.example.de": "example.de",
	} {
		if got := registrable(in); got != want {
			t.Errorf("%s => %s, want %s", in, got, want)
		}
	}
}

func TestNameRankingIsSticky(t *testing.T) {
	r := newNameRanker()
	first := map[string]uint64{}
	for i := 0; i < maxNames; i++ {
		first[fmt.Sprintf("n%d.com", i)] = 100
	}
	r.update(map[string]map[string]uint64{"w": first}, 15*time.Second)
	// A newcomer with a burst slightly above the incumbents does not take a place; one far busier does.
	r.update(map[string]map[string]uint64{"w": {"new.com": 120}}, 15*time.Second)
	if r.label("w", "new.com") != otherLabel {
		t.Error("a small burst displaced an incumbent")
	}
	r.update(map[string]map[string]uint64{"w": {"big.com": 1000}}, 15*time.Second)
	if r.label("w", "big.com") != "big.com" || len(r.top["w"]) != maxNames {
		t.Errorf("top %v", r.top["w"])
	}
	// Ranked over about an hour: an hour later the old counts are a quarter.
	r.update(map[string]map[string]uint64{"w": {"later.com": 10}}, time.Hour)
	if s := r.scores["w"]["big.com"]; s < 249 || s > 251 {
		t.Errorf("decayed score %v", s)
	}
}

func TestBudgetBurstFirstPassSkipped(t *testing.T) {
	// Reproduce the live bug: the probe opens in reconcileLocked, which sets lastPass = now(), then
	// collectLocked runs immediately in the same tick. In production wall is near-zero (microseconds);
	// dividing spent by (wall * cpus) explodes and falsely trips the burst check.
	src := newFake()
	c, _, _ := testCollector(t, src, Options{})
	// Call budget() directly with a near-zero wall (1 ms) and a large spent (500 ms).
	// 500 ms / (1 ms * 2 CPUs) = 250, far above the 5× budget burst threshold.
	// The fixed code must return early; the old code would shed runq and report StateDegraded.
	c.mu.Lock()
	c.budget(500*time.Millisecond, 1*time.Millisecond)
	c.mu.Unlock()
	if s := c.Status(); s.State != StateRunning {
		t.Errorf("first-pass near-zero wall incorrectly tripped the budget: %+v", s)
	}
}

func TestStickyAutoDisabledClearedOnUpgrade(t *testing.T) {
	// A server stuck after the old buggy check set auto_disabled must be unblocked when the agent
	// upgrades: a persisted record with version < 2 has its auto_disabled cleared on load.
	fs := hostfs.FS{Root: t.TempDir()}
	old, _ := json.Marshal(persisted{Enabled: true, AutoDisabled: "9.84 % of CPU in one pass (budget 1 %)"})
	if err := fs.MkdirAll("/etc/falak", 0o755); err != nil {
		t.Fatal(err)
	}
	if _, err := fs.WriteFile("/etc/falak/ebpf.json", append(old, '\n'), 0o600); err != nil {
		t.Fatal(err)
	}
	c := New(Options{FS: fs, EtcDir: "/etc/falak"})
	if c.autoDisabled != "" {
		t.Errorf("old auto_disabled not cleared on upgrade: %q", c.autoDisabled)
	}
	if s := c.Status(); s.State == StateOverBudget {
		t.Errorf("probe stuck in over-budget state after upgrade: %+v", s)
	}
	if !c.enabled {
		t.Error("enabled flag must still be loaded from the file")
	}
}

func TestUnderBudgetKeepsRunning(t *testing.T) {
	src := newFake()
	c, out, now := testCollector(t, src, Options{})
	for i := 0; i < 25; i++ {
		src.runtime[groupRunq] += 30 * time.Millisecond // 0.1 %
		p := pass(c, out, now, 15*time.Second)
		if i == 24 && p[`falak.ebpf.active{svc=falak-host,falak.ebpf.component=runq}`] != 1 {
			t.Errorf("runq not reported active")
		}
	}
	if s := c.Status(); s.State != StateRunning || s.OverheadPercent == nil || *s.OverheadPercent > 0.2 {
		t.Errorf("%+v", s)
	}
}

func TestStatesAndConfigure(t *testing.T) {
	fs := hostfs.FS{Root: t.TempDir()}
	c := New(Options{FS: fs, EtcDir: "/etc/falak"})
	c.check = func() (bool, string) { return false, "kernel 5.4.0 is older than 5.8" }
	opened := 0
	c.open = func(hostfs.FS) (source, []string, error) { opened++; return nil, nil, errors.New("verifier said no") }
	if s := c.Status(); s.State != StateOff || s.Supported || s.Enabled {
		t.Fatalf("initial %+v", s)
	}
	res, err := c.configure(context.Background(), Config{Enabled: true}, nil)
	if err != nil {
		t.Fatal(err)
	}
	if s := res.(Status); s.State != StateUnsupported || s.Detail != "kernel 5.4.0 is older than 5.8" || !s.Enabled || opened != 0 {
		t.Errorf("unsupported: %+v, opened %d", s, opened)
	}
	b, _ := fs.ReadFile("/etc/falak/ebpf.json")
	var cfg Config
	if json.Unmarshal(b, &cfg) != nil || !cfg.Enabled {
		t.Errorf("persisted %q", b)
	}
	// A restarted agent keeps the setting.
	if again := New(Options{FS: fs, EtcDir: "/etc/falak"}); !again.enabled {
		t.Error("setting not loaded")
	}

	c.check = func() (bool, string) { return true, "" }
	res, _ = c.configure(context.Background(), Config{Enabled: true}, nil)
	if s := res.(Status); s.State != StateFailed || s.Detail != "verifier said no" || opened != 1 {
		t.Errorf("failed load: %+v", s)
	}
	c.Tick()
	if opened != 1 {
		t.Error("a failed load is retried by the loop")
	}
	// A panicking loader is a failure, not a crash.
	c.open = func(hostfs.FS) (source, []string, error) { panic("boom") }
	res, _ = c.configure(context.Background(), Config{Enabled: true}, nil)
	if s := res.(Status); s.State != StateFailed || !strings.Contains(s.Detail, "boom") {
		t.Errorf("panic: %+v", s)
	}
	// Partly attached: degraded with what is missing.
	src := newFake()
	src.groups = []string{groupTCP}
	c.open = func(hostfs.FS) (source, []string, error) { return src, []string{"dns: attach failed"}, nil }
	res, _ = c.configure(context.Background(), Config{Enabled: true}, nil)
	if s := res.(Status); s.State != StateDegraded || !strings.Contains(s.Detail, "dns: attach failed") || strings.Join(s.Programs, ",") != "tcp" {
		t.Errorf("partial: %+v", s)
	}
	res, _ = c.configure(context.Background(), Config{Enabled: false}, nil)
	if s := res.(Status); s.State != StateOff || !src.closed || s.Enabled {
		t.Errorf("off: %+v closed %v", s, src.closed)
	}
}

type panicky struct{ *fakeSource }

func (panicky) Peers(func(peerKey, peerVal)) error { panic("map gone") }

func TestAPanickingReadDetachesInsteadOfCrashing(t *testing.T) {
	src := newFake()
	c, out, now := testCollector(t, src, Options{})
	c.mu.Lock()
	c.src = panicky{src}
	c.mu.Unlock()
	if p := pass(c, out, now, 15*time.Second); len(p) != 0 {
		t.Errorf("points after a panic: %v", p)
	}
	if s := c.Status(); s.State != StateFailed || !strings.Contains(s.Detail, "map gone") || !src.closed {
		t.Errorf("%+v closed %v", s, src.closed)
	}
}

func TestMetricsOffDetaches(t *testing.T) {
	src := newFake()
	c, _, _ := testCollector(t, src, Options{})
	c.Configure(false, 15*time.Second)
	c.Tick()
	if !src.closed || c.Status().State != StateOff {
		t.Errorf("metrics off: closed %v, %+v", src.closed, c.Status())
	}
}

func TestDetect(t *testing.T) {
	ok := func() error { return nil }
	for _, tc := range []struct {
		release string
		files   []string
		bpf     func() error
		want    string
	}{
		{"6.8.0-45-generic", []string{"/sys/kernel/btf/vmlinux", "/sys/fs/cgroup/cgroup.controllers"}, ok, ""},
		{"5.4.0-200-generic", nil, ok, "kernel 5.4.0-200-generic is older than 5.8"},
		{"5.15.0", []string{"/sys/fs/cgroup/cgroup.controllers"}, ok, "no kernel BTF (/sys/kernel/btf/vmlinux)"},
		{"6.17.1", []string{"/sys/kernel/btf/vmlinux"}, ok, "cgroup v2 is not mounted at /sys/fs/cgroup"},
		{"6.17.1", []string{"/sys/kernel/btf/vmlinux", "/sys/fs/cgroup/cgroup.controllers"}, func() error { return syscall.EPERM },
			"bpf() not permitted (unprivileged container or seccomp?)"},
	} {
		root := t.TempDir()
		fs := hostfs.FS{Root: root}
		write := func(p, s string) {
			_ = os.MkdirAll(filepath.Dir(filepath.Join(root, p)), 0o755)
			_ = os.WriteFile(filepath.Join(root, p), []byte(s), 0o644)
		}
		write("/proc/sys/kernel/osrelease", tc.release+"\n")
		for _, f := range tc.files {
			write(f, "")
		}
		supported, reason := detect(fs, tc.bpf)
		if supported != (tc.want == "") || reason != tc.want {
			t.Errorf("%s %v: %v %q, want %q", tc.release, tc.files, supported, reason, tc.want)
		}
	}
}

func TestSeriesExpire(t *testing.T) {
	src := newFake()
	k := peerKey{O: owner{CgID: 1}, Addr: v4("198.51.100.1"), Port: 443, Family: afInet, Dir: dirOut}
	src.peers[k] = peerVal{Retrans: 1}
	c, out, now := testCollector(t, src, Options{})
	pass(c, out, now, 15*time.Second)
	p := pass(c, out, now, 9*time.Minute)
	if len(filter(p, "falak.net.tcp.retransmits")) != 1 {
		t.Fatal("series dropped too early")
	}
	p = pass(c, out, now, 2*time.Minute)
	if len(filter(p, "falak.net.tcp.retransmits")) != 0 {
		t.Error("an idle peer series is kept")
	}
}

func filter(p map[string]float64, prefix string) []string {
	var out []string
	for k := range p {
		if strings.HasPrefix(k, prefix+"{") {
			out = append(out, k)
		}
	}
	return out
}
