package netobs

import (
	"bytes"
	"context"
	"encoding/binary"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net"
	"net/netip"
	"os"
	"path/filepath"
	"strings"
	"syscall"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/metrics"
	"github.com/OthmanHaba/falak/agent/internal/netcfg"
	"github.com/santhosh-tekuri/jsonschema/v6"
	commonpb "go.opentelemetry.io/proto/otlp/common/v1"
	metricspb "go.opentelemetry.io/proto/otlp/metrics/v1"
)

// hexAddr encodes an address as /proc/net/tcp does (little-endian words).
func hexAddr(ap string) string {
	a := netip.MustParseAddrPort(ap)
	b := a.Addr().AsSlice()
	out := make([]byte, len(b))
	for i := 0; i < len(b); i += 4 {
		binary.LittleEndian.PutUint32(out[i:], binary.BigEndian.Uint32(b[i:i+4]))
	}
	return fmt.Sprintf("%s:%04X", strings.ToUpper(hex.EncodeToString(out)), a.Port())
}

type sock struct {
	local, remote string
	state         uint8
	inode         uint64
}

func table(socks ...sock) string {
	var b strings.Builder
	b.WriteString("  sl  local_address rem_address   st tx_queue rx_queue tr tm->when retrnsmt   uid  timeout inode\n")
	for i, s := range socks {
		fmt.Fprintf(&b, "%4d: %s %s %02X 00000000:00000000 00:00000000 00000000  1000        0 %d 1 0000000000000000 100 0 0 10 0\n",
			i, hexAddr(s.local), hexAddr(s.remote), s.state, s.inode)
	}
	return b.String()
}

func writeTree(t *testing.T, root string, files map[string]string) {
	t.Helper()
	for p, c := range files {
		full := filepath.Join(root, p)
		if err := os.MkdirAll(filepath.Dir(full), 0o755); err != nil {
			t.Fatal(err)
		}
		if strings.HasPrefix(c, "->") {
			_ = os.Remove(full)
			if err := os.Symlink(strings.TrimPrefix(c, "->"), full); err != nil {
				t.Fatal(err)
			}
			continue
		}
		if err := os.WriteFile(full, []byte(c), 0o644); err != nil {
			t.Fatal(err)
		}
	}
}

func TestParseHexAddr(t *testing.T) {
	for _, tc := range []struct{ in, want string }{
		{"0100007F:1F90", "127.0.0.1:8080"},
		{"00000000:0050", "0.0.0.0:80"},
		{"00000000000000000000000000000000:01BB", "[::]:443"},
		{"0000000000000000FFFF00000100007F:0016", "127.0.0.1:22"}, // v4-mapped: unmapped
		{"B80D0120000000000000000001000000:1538", "[2001:db8::1]:5432"},
	} {
		got, ok := parseHexAddr(tc.in)
		if !ok || got.String() != tc.want {
			t.Errorf("%s: got %v %v, want %s", tc.in, got, ok, tc.want)
		}
		if hexAddr(tc.want) != tc.in && !strings.Contains(tc.in, "FFFF") {
			t.Errorf("helper %s != %s", hexAddr(tc.want), tc.in)
		}
	}
	for _, bad := range []string{"", "0100007F", "zz:0050", "0100:0050", "0100007F:FFFFF"} {
		if _, ok := parseHexAddr(bad); ok {
			t.Errorf("%q accepted", bad)
		}
	}
}

func TestParseSocketsStatesListenersAndLimit(t *testing.T) {
	b := strings.NewReader(table(
		sock{"0.0.0.0:80", "0.0.0.0:0", tcpListen, 1},
		sock{"10.0.0.5:80", "203.0.113.9:51000", tcpEstablished, 2},
		sock{"10.0.0.5:44000", "10.90.0.2:5432", tcpTimeWait, 0},
		sock{"10.0.0.5:44001", "10.90.0.2:5432", tcpFinWait2, 3},
	))
	socks, tr := parseSockets(b, "tcp", 3)
	if len(socks) != 3 || !tr {
		t.Fatalf("got %d truncated %v", len(socks), tr)
	}
	if !socks[0].Listening() || socks[1].Listening() || socks[1].Inode != 2 || socks[1].UID != 1000 {
		t.Fatalf("%+v", socks)
	}
	if stateName(socks[2].State) != "time_wait" || stateName(tcpFinWait1) != "fin_wait" || stateName(tcpListen) != "" || stateName(tcpClose) != "" {
		t.Fatal("state names")
	}
	listening := listeningOf(socks)
	if !inbound(socks[1], listening) || inbound(socks[2], listening) {
		t.Fatal("direction")
	}
	// UDP: bound and unconnected listens; connected does not.
	u, _ := parseSockets(strings.NewReader(table(sock{"0.0.0.0:53", "0.0.0.0:0", tcpClose, 9}, sock{"10.0.0.5:40000", "1.1.1.1:53", tcpEstablished, 10})), "udp", 10)
	if !u[0].Listening() || u[1].Listening() {
		t.Fatal("udp listeners")
	}
}

func TestExposure(t *testing.T) {
	for addr, want := range map[string]string{"0.0.0.0": "public", "::": "public", "203.0.113.4": "public", "127.0.0.1": "loopback",
		"::1": "loopback", "10.1.2.3": "private", "172.17.0.1": "private", "100.64.1.1": "private", "fd00::1": "private", "fe80::1": "private"} {
		if got := exposure(netip.MustParseAddr(addr)); got != want {
			t.Errorf("%s: %s, want %s", addr, got, want)
		}
	}
}

type snap metrics.NetSnapshot

func (s snap) NetSnapshot() metrics.NetSnapshot { return metrics.NetSnapshot(s) }

type emitter struct{ rms []*metricspb.ResourceMetrics }

func (e *emitter) EmitMetrics(rm *metricspb.ResourceMetrics) { e.rms = append(e.rms, rm) }

type requester struct {
	typ  string
	body []byte
	err  error
}

func (r *requester) Request(_ context.Context, typ string, in, _ any) error {
	r.typ = typ
	r.body, _ = json.Marshal(in)
	return r.err
}

const ctID = "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"

// fixture is a host with: the edge on 0.0.0.0:80, Postgres on 0.0.0.0:5432 (a host process), a docker-proxy publishing
// a site container's 8080 on 0.0.0.0:8080, a native site "blog" (pid 50) with an outbound connection to a database on
// a private-network peer, a client of the edge, and one site container (pid 200) talking to a database container.
func fixture(t *testing.T) (string, *Collector, *emitter, *requester) {
	root := t.TempDir()
	host := table(
		sock{"0.0.0.0:80", "0.0.0.0:0", tcpListen, 100},
		sock{"0.0.0.0:5432", "0.0.0.0:0", tcpListen, 101},
		sock{"0.0.0.0:8080", "0.0.0.0:0", tcpListen, 102},
		sock{"203.0.113.10:80", "198.51.100.7:50000", tcpEstablished, 110},
		sock{"203.0.113.10:80", "198.51.100.7:50001", tcpEstablished, 111},
		sock{"10.90.0.1:40000", "10.90.0.2:15432", tcpEstablished, 120},
		sock{"10.90.0.1:40001", "10.90.0.2:15432", tcpTimeWait, 0},
	)
	ct := table(
		sock{"0.0.0.0:8080", "0.0.0.0:0", tcpListen, 200},
		sock{"172.18.0.2:8080", "172.18.0.1:33000", tcpEstablished, 201},
		sock{"172.18.0.2:41000", "172.18.0.3:5432", tcpEstablished, 202},
	)
	writeTree(t, root, map[string]string{
		"proc/1/net/tcp":         host,
		"proc/1/ns/net":          "->net:[1]",
		"proc/200/net/tcp":       ct,
		"proc/200/ns/net":        "->net:[2]",
		"proc/201/ns/net":        "->net:[2]", // a second container sharing that namespace
		"proc/50/fd/3":           "->socket:[120]",
		"proc/50/fd/4":           "->/dev/null",
		"proc/60/fd/7":           "->socket:[101]",
		"proc/60/comm":           "postgres\n",
		"proc/60/stat":           "60 (postgres) S 1 1 1 0 -1 0 0 0 0 0 1 1 0 0 20 0 1 0 777 1 1\n",
		"proc/70/fd/1":           "->socket:[102]",
		"proc/70/comm":           "docker-proxy\n",
		"proc/70/stat":           "70 (docker-proxy) S 1 1 1 0 -1 0 0 0 0 0 1 1 0 0 20 0 1 0 888 1 1\n",
		"proc/70/cmdline":        "/usr/bin/docker-proxy\x00-proto\x00tcp\x00-host-ip\x000.0.0.0\x00-host-port\x008080\x00-container-ip\x00172.18.0.2\x00-container-port\x008080\x00",
		"proc/80/fd/1":           "->socket:[100]",
		"proc/80/comm":           "caddy\n",
		"proc/80/stat":           "80 (caddy) S 1 1 1 0 -1 0 0 0 0 0 1 1 0 0 20 0 1 0 999 1 1\n",
		"etc/wireguard/wg0.conf": "x",
		"proc/sys/net/netfilter/nf_conntrack_count": "870\n",
		"proc/sys/net/netfilter/nf_conntrack_max":   "1000\n",
		"proc/sys/net/netfilter/nf_conntrack_acct":  "1\n",
	})
	s := snap{
		Containers: []metrics.NetContainer{
			{ID: ctID, Name: "shop-web", Site: "shop", Service: "web", Pid: 200, IPs: []string{"172.18.0.2"}},
			{ID: strings.Repeat("b", 64), Name: "shop-sidecar", Site: "shop", Service: "sidecar", Pid: 201, IPs: []string{"172.18.0.4"}},
			{ID: strings.Repeat("c", 64), Name: "falak-db-01k", DBInstance: "01k", Pid: 300, IPs: []string{"172.18.0.3"}},
		},
		Native: map[int]string{50: "blog"},
	}
	out, cp := &emitter{}, &requester{}
	c := New(Options{FS: hostfs.FS{Root: root}, Workloads: s, CP: cp, Out: out, Logger: slog.New(slog.NewTextHandler(io.Discard, nil))})
	c.SetConfig(Config{
		Names:     []PeerName{{Address: "10.90.0.2", Port: 15432, Name: "db:01peer"}},
		WireGuard: []WGName{{PublicKey: "xTIBA5rboUvnH4htodjb6e697QjLERt1NAB4mZqp8Dg=", Name: "server:01peer"}},
		Probes:    []ProbeTarget{{Name: "server:01peer", Kind: "server", Host: "10.90.0.2", Port: 22}},
	})
	return root, c, out, cp
}

func attrOf(kvs []*commonpb.KeyValue, k string) string {
	for _, kv := range kvs {
		if kv.Key == k {
			return kv.Value.GetStringValue()
		}
	}
	return ""
}

func resourceOf(rms []*metricspb.ResourceMetrics, service string) *metricspb.ResourceMetrics {
	for _, rm := range rms {
		if attrOf(rm.Resource.Attributes, "service.name") == service {
			return rm
		}
	}
	return nil
}

// value finds a data point of metric name whose attributes match kv pairs; ok false when none.
func value(rm *metricspb.ResourceMetrics, name string, kv ...string) (float64, bool) {
	if rm == nil {
		return 0, false
	}
	for _, m := range rm.ScopeMetrics[0].Metrics {
		if m.Name != name {
			continue
		}
		pts := m.GetGauge().GetDataPoints()
		if s := m.GetSum(); s != nil {
			pts = s.DataPoints
		}
	next:
		for _, p := range pts {
			for i := 0; i+1 < len(kv); i += 2 {
				if attrOf(p.Attributes, kv[i]) != kv[i+1] {
					continue next
				}
			}
			if _, ok := p.Value.(*metricspb.NumberDataPoint_AsDouble); ok {
				return p.GetAsDouble(), true
			}
			return float64(p.GetAsInt()), true
		}
	}
	return 0, false
}

func TestCollectAttributesSocketsPeersAndReports(t *testing.T) {
	_, c, _, _ := fixture(t)
	now := time.Date(2026, 10, 15, 12, 0, 0, 0, time.UTC)
	c.now = func() time.Time { return now }
	flows := []Flow{
		// The native site's connection, outbound: orig = the socket's tuple.
		{ID: 1, Proto: 6, OrigSrc: netip.MustParseAddrPort("10.90.0.1:40000"), OrigDst: netip.MustParseAddrPort("10.90.0.2:15432"),
			ReplySrc: netip.MustParseAddrPort("10.90.0.2:15432"), ReplyDst: netip.MustParseAddrPort("10.90.0.1:40000"), OrigBytes: 1000, ReplyBytes: 5000, Counters: true},
		// An edge client, inbound.
		{ID: 2, Proto: 6, OrigSrc: netip.MustParseAddrPort("198.51.100.7:50000"), OrigDst: netip.MustParseAddrPort("203.0.113.10:80"),
			ReplySrc: netip.MustParseAddrPort("203.0.113.10:80"), ReplyDst: netip.MustParseAddrPort("198.51.100.7:50000"), OrigBytes: 300, ReplyBytes: 9000, Counters: true},
	}
	c.conntrack = func(int) ([]Flow, bool, error) { return flows, false, nil }
	c.wireguard = func(context.Context) ([]netcfg.PeerStats, error) {
		return []netcfg.PeerStats{{Interface: "wg0", PublicKey: "xTIBA5rboUvnH4htodjb6e697QjLERt1NAB4mZqp8Dg=", AllowedIPs: []string{"10.90.0.2/32"},
			LatestHandshake: now.Add(-90 * time.Second), RxBytes: 10, TxBytes: 20}}, nil
	}
	c.dial = func(context.Context, string, string) (net.Conn, error) { return nil, syscall.ECONNREFUSED }

	rms, rep := c.Collect(context.Background())
	if rep == nil {
		t.Fatal("first pass is a full pass")
	}
	// Report shape.
	byName := map[string]ReportWorkload{}
	for _, w := range rep.Workloads {
		byName[w.Kind+"/"+w.Name] = w
	}
	if len(rep.Workloads) != 3 {
		t.Fatalf("host, blog, shop-web (the sidecar shares its namespace, the db has no /proc): %v", byName)
	}
	host, blog, web := byName["host/host"], byName["processes/blog"], byName["container/shop-web"]
	if host.Connections.Inbound != 2 || host.Connections.Outbound != 1 || host.Connections.States["time_wait"] != 1 {
		t.Fatalf("host connections %+v", host.Connections)
	}
	if blog.Connections.Outbound != 1 || blog.Site != "blog" || len(blog.Peers) != 1 || blog.Peers[0].Name != "db:01peer" {
		t.Fatalf("blog %+v", blog)
	}
	if *blog.Peers[0].TxBytes != 0 || *blog.Peers[0].RxBytes != 0 {
		t.Fatal("the first dump is a baseline")
	}
	owners := map[int]ReportListener{}
	for _, l := range host.Listeners {
		owners[l.Port] = l
	}
	if owners[80].Owner != "host:caddy" || owners[5432].Process != "postgres" || !owners[5432].Public || owners[8080].Owner != "site:shop/web" || owners[8080].Container != "shop-web" {
		t.Fatalf("listeners %+v", host.Listeners)
	}
	// The edge's client is inbound, by address only, unnamed.
	if host.Peers[0].Direction != dirIn || host.Peers[0].Port != 0 || host.Peers[0].Connections != 2 || host.Peers[0].Name != "" {
		t.Fatalf("host peers %+v", host.Peers)
	}
	// The container's peer is the database container, by IP; its inbound client is the host (the bridge gateway is not
	// a host address in this fixture: private).
	if web.ComposeService != "web" || web.Peers[0].Name != "db:01k" && web.Peers[1].Name != "db:01k" {
		t.Fatalf("web %+v", web)
	}
	if rep.Conntrack.Accounting != acctOn || *rep.Conntrack.Entries != 870 || len(rep.WireGuard) != 1 || *rep.WireGuard[0].HandshakeAgeS != 90 ||
		rep.WireGuard[0].Name != "server:01peer" || len(rep.Probes) != 1 || !rep.Probes[0].Refused {
		t.Fatalf("report %+v", rep)
	}
	validateReport(t, rep)

	// Metrics: bounded labels, no address.
	h := resourceOf(rms, hostService)
	if v, _ := value(h, "falak.net.connections", "falak.net.state", "established", "falak.net.direction", dirIn); v != 2 {
		t.Fatalf("host established inbound %v", v)
	}
	if v, _ := value(h, "falak.net.conntrack.entries"); v != 870 {
		t.Fatal("conntrack entries")
	}
	if v, _ := value(h, "falak.net.listeners", "network.transport", "tcp", "falak.net.exposure", "public"); v != 3 {
		t.Fatalf("public listeners %v", v)
	}
	if v, _ := value(h, "falak.net.peer.connections", "falak.net.peer", "external", "falak.net.direction", dirIn); v != 2 {
		t.Fatal("an unnamed client is the external bucket")
	}
	if v, _ := value(h, "falak.net.wireguard.handshake.age", "falak.net.wireguard.peer", "server:01peer"); v != 90 {
		t.Fatal("handshake age")
	}
	if v, ok := value(h, "falak.net.probe.up", "falak.net.probe.target", "server:01peer"); !ok || v != 1 {
		t.Fatal("a refused probe is up")
	}
	for _, rm := range rms {
		for _, m := range rm.ScopeMetrics[0].Metrics {
			for _, p := range append(m.GetGauge().GetDataPoints(), m.GetSum().GetDataPoints()...) {
				for _, kv := range p.Attributes {
					if _, err := netip.ParseAddr(kv.Value.GetStringValue()); err == nil {
						t.Fatalf("%s carries an address: %v", m.Name, kv)
					}
				}
			}
		}
	}
	b := resourceOf(rms, "blog")
	if v, _ := value(b, "falak.net.peer.connections", "falak.net.peer", "db:01peer", "falak.workload.kind", "processes"); v != 1 {
		t.Fatal("blog peer")
	}

	// A pass 15 s later is not full; one a minute later is, with byte deltas from conntrack.
	now = now.Add(15 * time.Second)
	if _, rep := c.Collect(context.Background()); rep != nil {
		t.Fatal("not a full pass")
	}
	now = now.Add(45 * time.Second)
	flows[0].OrigBytes, flows[0].ReplyBytes = 1500, 8000
	rms, rep = c.Collect(context.Background())
	if rep == nil || rep.WindowS != 60 {
		t.Fatalf("full pass %v", rep)
	}
	for _, w := range rep.Workloads {
		if w.Name == "blog" && (*w.Peers[0].TxBytes != 500 || *w.Peers[0].RxBytes != 3000) {
			t.Fatalf("blog bytes %+v", w.Peers[0])
		}
	}
	if v, _ := value(resourceOf(rms, "blog"), "falak.net.peer.io", "falak.net.peer", "db:01peer", "network.io.direction", "receive"); v != 3000 {
		t.Fatalf("peer io %v", v)
	}
	// The edge's clients were open for a minute: long-lived.
	if v, _ := value(resourceOf(rms, hostService), "falak.net.connections.long_lived"); v != 2 {
		t.Fatalf("long-lived %v", v)
	}
}

func validateReport(t *testing.T, rep *Report) {
	t.Helper()
	c := jsonschema.NewCompiler()
	c.AssertFormat()
	f, err := os.Open("../../../contracts/agent-protocol/requests/net.observed.schema.json")
	if err != nil {
		t.Fatal(err)
	}
	defer f.Close()
	doc, err := jsonschema.UnmarshalJSON(f)
	if err != nil {
		t.Fatal(err)
	}
	if err := c.AddResource("net.observed.json", doc); err != nil {
		t.Fatal(err)
	}
	sch, err := c.Compile("net.observed.json")
	if err != nil {
		t.Fatal(err)
	}
	b, _ := json.Marshal(rep)
	v, _ := jsonschema.UnmarshalJSON(bytes.NewReader(b))
	if err := sch.Validate(v); err != nil {
		t.Fatalf("report does not match its schema: %v\n%s", err, b)
	}
}

func TestAccountingOffReportsCountsOnly(t *testing.T) {
	root, c, _, cp := fixture(t)
	writeTree(t, root, map[string]string{"proc/sys/net/netfilter/nf_conntrack_acct": "0\n"})
	c.conntrack = func(int) ([]Flow, bool, error) { t.Fatal("no dump without accounting"); return nil, false, nil }
	c.dial = func(context.Context, string, string) (net.Conn, error) { return nil, context.DeadlineExceeded }
	_, rep := c.Collect(context.Background())
	if rep.Conntrack.Accounting != acctOff || rep.Probes[0].OK || rep.Probes[0].Error != "timeout" {
		t.Fatalf("%+v", rep)
	}
	for _, w := range rep.Workloads {
		for _, p := range w.Peers {
			if p.RxBytes != nil {
				t.Fatal("no bytes without accounting")
			}
		}
	}
	validateReport(t, rep)
	c.send(context.Background(), rep)
	if cp.typ != "net.observed" {
		t.Fatal("report sent")
	}
}

func TestPeersFoldIntoOther(t *testing.T) {
	root := t.TempDir()
	var socks []sock
	socks = append(socks, sock{"0.0.0.0:443", "0.0.0.0:0", tcpListen, 1})
	for i := range 30 {
		for j := 0; j <= i%3; j++ {
			socks = append(socks, sock{fmt.Sprintf("10.0.0.5:%d", 30000+i*3+j), fmt.Sprintf("203.0.113.%d:443", i+1), tcpEstablished, uint64(100 + i*3 + j)})
		}
	}
	writeTree(t, root, map[string]string{"proc/1/net/tcp": table(socks...), "proc/1/ns/net": "->net:[1]"})
	c := New(Options{FS: hostfs.FS{Root: root}, Out: &emitter{}})
	rms, rep := c.Collect(context.Background())
	w := rep.Workloads[0]
	if len(w.Peers) != maxPeers || w.Other[dirOut].Peers != 10 || w.Peers[0].Connections != 3 {
		t.Fatalf("peers %d other %+v first %+v", len(w.Peers), w.Other, w.Peers[0])
	}
	if v, _ := value(resourceOf(rms, hostService), "falak.net.peer.connections", "falak.net.peer", "other"); v == 0 {
		t.Fatal("other series")
	}
	if v, _ := value(resourceOf(rms, hostService), "falak.net.peer.connections", "falak.net.peer", "external:443"); v == 0 {
		t.Fatal("bucket series")
	}
}

// nlmsg builds a ctnetlink new-conntrack message.
func nlattr(typ uint16, val []byte) []byte {
	b := make([]byte, 4, 4+len(val)+3)
	binary.NativeEndian.PutUint16(b[0:2], uint16(4+len(val)))
	binary.NativeEndian.PutUint16(b[2:4], typ)
	b = append(b, val...)
	for len(b)%4 != 0 {
		b = append(b, 0)
	}
	return b
}

func nested(typ uint16, parts ...[]byte) []byte { return nlattr(typ|0x8000, bytes.Join(parts, nil)) }

func be16(v uint16) []byte { return binary.BigEndian.AppendUint16(nil, v) }
func be32(v uint32) []byte { return binary.BigEndian.AppendUint32(nil, v) }
func be64(v uint64) []byte { return binary.BigEndian.AppendUint64(nil, v) }

func tuple(typ uint16, src, dst string, sp, dp uint16, v6 bool) []byte {
	s, d := netip.MustParseAddr(src).AsSlice(), netip.MustParseAddr(dst).AsSlice()
	st, dt := uint16(ctaIPv4Src), uint16(ctaIPv4Dst)
	if v6 {
		st, dt = ctaIPv6Src, ctaIPv6Dst
	}
	return nested(typ, nested(ctaTupleIP, nlattr(st, s), nlattr(dt, d)),
		nested(ctaTupleProto, nlattr(ctaProtoNum, []byte{6}), nlattr(ctaProtoSrcPort, be16(sp)), nlattr(ctaProtoDstPort, be16(dp))))
}

func nlmsg(typ uint16, payload []byte) []byte {
	b := make([]byte, nlmsgHdrLen)
	binary.NativeEndian.PutUint32(b[0:4], uint32(nlmsgHdrLen+len(payload)))
	binary.NativeEndian.PutUint16(b[4:6], typ)
	return append(b, payload...)
}

func TestParseConntrackMessages(t *testing.T) {
	v4 := append([]byte{2, 0, 0, 0}, bytes.Join([][]byte{
		tuple(ctaTupleOrig, "10.0.0.5", "10.90.0.2", 40000, 5432, false),
		tuple(ctaTupleReply, "172.18.0.3", "10.0.0.5", 5432, 40000, false),
		nested(ctaCountersOrig, nlattr(1, be64(3)), nlattr(ctaCountersBytes, be64(1234))),
		nested(ctaCountersReply, nlattr(ctaCounters32Bytes, be32(99))),
		nlattr(ctaID, be32(77)),
	}, nil)...)
	v6 := append([]byte{10, 0, 0, 0}, tuple(ctaTupleOrig, "2001:db8::1", "2001:db8::2", 1, 2, true)...)
	buf := bytes.Join([][]byte{nlmsg(nfnlSubsysCT<<8, v4), nlmsg(nfnlSubsysCT<<8, v6), nlmsg(nlmsgDone, []byte{0, 0, 0, 0})}, nil)
	flows, done, capped, err := parseConntrackMessages(buf, nil, 10)
	if err != nil || !done || capped || len(flows) != 2 {
		t.Fatalf("flows %v done %v err %v", flows, done, err)
	}
	f := flows[0]
	if f.ID != 77 || f.Proto != 6 || f.OrigSrc.String() != "10.0.0.5:40000" || f.ReplySrc.String() != "172.18.0.3:5432" || f.OrigBytes != 1234 || f.ReplyBytes != 99 || !f.Counters {
		t.Fatalf("%+v", f)
	}
	if flows[1].OrigDst.String() != "[2001:db8::2]:2" || flows[1].Counters {
		t.Fatalf("%+v", flows[1])
	}
	// At the cap the rest is not read (capped, done), and a kernel error.
	if flows, done, capped, _ := parseConntrackMessages(buf, nil, 1); len(flows) != 1 || !done || !capped {
		t.Fatal("limit")
	}
	errMsg := nlmsg(nlmsgError, be32(0xffffffff))
	if _, done, _, err := parseConntrackMessages(errMsg, nil, 10); !done || !errors.Is(err, errNetlink) {
		t.Fatal("error message")
	}
	if _, _, _, err := parseConntrackMessages(nlmsg(nfnlSubsysCT<<8, v4)[:20], nil, 10); err == nil {
		t.Fatal("truncated")
	}
}

func TestFlowIndexAndDeltas(t *testing.T) {
	dnat := Flow{ID: 5, Proto: 6, OrigSrc: netip.MustParseAddrPort("198.51.100.7:5000"), OrigDst: netip.MustParseAddrPort("203.0.113.10:8080"),
		ReplySrc: netip.MustParseAddrPort("172.18.0.2:8080"), ReplyDst: netip.MustParseAddrPort("198.51.100.7:5000"), OrigBytes: 10, ReplyBytes: 20, Counters: true}
	idx := flowIndex([]Flow{dnat})
	// The container's socket: local 172.18.0.2:8080, remote the client: the reply tuple, responder side.
	m, ok := idx[tupleKey("tcp", netip.MustParseAddrPort("172.18.0.2:8080"), netip.MustParseAddrPort("198.51.100.7:5000"))]
	if !ok || m.side != sideResponder {
		t.Fatalf("%+v %v", m, ok)
	}
	var fc flowCounters
	if d := fc.deltas([]Flow{dnat}, false); len(d) != 0 {
		t.Fatal("baseline")
	}
	dnat.OrigBytes, dnat.ReplyBytes = 15, 50
	fresh := Flow{ID: 6, Proto: 6, OrigSrc: dnat.OrigSrc, OrigDst: dnat.OrigDst, OrigBytes: 7, Counters: true}
	d := fc.deltas([]Flow{dnat, fresh}, false)
	if d["id:5"] != [2]uint64{5, 30} || d["id:6"] != [2]uint64{7, 0} {
		t.Fatalf("%v", d)
	}
	dnat.OrigBytes = 1 // a reused id: counted from zero
	if d := fc.deltas([]Flow{dnat}, false); d["id:5"] != [2]uint64{1, 0} {
		t.Fatalf("%v", d)
	}
}

func TestFlowDeltasOverTruncatedDumps(t *testing.T) {
	flow := func(id uint32, orig uint64) Flow { return Flow{ID: id, Proto: 6, OrigBytes: orig, Counters: true} }
	var fc flowCounters
	fc.deltas([]Flow{flow(1, 100)}, false)
	// A truncated dump: a flow first seen in it may have been open (unseen) for long: baseline only.
	if d := fc.deltas([]Flow{flow(1, 150), flow(2, 1_000_000)}, true); d["id:1"] != [2]uint64{50, 0} || len(d) != 1 {
		t.Fatalf("%v", d)
	}
	// Flow 1 drops out of the next truncated dumps and comes back: only what it carried since counts.
	fc.deltas([]Flow{flow(2, 1_000_010)}, true)
	fc.deltas([]Flow{flow(2, 1_000_020)}, true)
	if d := fc.deltas([]Flow{flow(1, 180), flow(2, 1_000_030)}, true); d["id:1"] != [2]uint64{30, 0} || d["id:2"] != [2]uint64{10, 0} {
		t.Fatalf("%v", d)
	}
	// Missing for more than keepMissing dumps: forgotten (and a baseline again when it reappears in a truncated dump).
	for range keepMissing + 1 {
		fc.deltas([]Flow{flow(2, 1_000_030)}, true)
	}
	if _, ok := fc.last["id:1"]; ok {
		t.Fatal("kept too long")
	}
	if d := fc.deltas([]Flow{flow(1, 500)}, true); len(d) != 0 {
		t.Fatalf("%v", d)
	}
}

func TestConfigureValidatesAndPersists(t *testing.T) {
	root := t.TempDir()
	c := New(Options{FS: hostfs.FS{Root: root}, EtcDir: "/etc/falak", Out: &emitter{}})
	good := Config{Probes: []ProbeTarget{{Name: "server:01k", Kind: "server", Host: "10.90.0.2", Port: 1}},
		Names: []PeerName{{Address: "10.90.0.2", Name: "server:01a"}}, WireGuard: []WGName{}}
	res, err := c.configure(context.Background(), good, nil)
	if err != nil || !res.(Result).Changed {
		t.Fatalf("%v %v", res, err)
	}
	if res, _ := c.configure(context.Background(), good, nil); res.(Result).Changed {
		t.Fatal("unchanged")
	}
	fi, err := os.Stat(filepath.Join(root, "etc/falak/net.json"))
	if err != nil || fi.Mode().Perm() != 0o600 {
		t.Fatalf("%v %v", fi, err)
	}
	// A restarted agent loads it.
	c2 := New(Options{FS: hostfs.FS{Root: root}, EtcDir: "/etc/falak", Out: &emitter{}})
	if len(c2.cfg.Probes) != 1 || c2.cfg.Probes[0].Port != 1 {
		t.Fatalf("%+v", c2.cfg)
	}
	for _, bad := range []Config{
		{Probes: []ProbeTarget{{Name: "db:x", Kind: "nope", Host: "h", Port: 1}}},
		// Database ports are never probed (an aborted connect for MySQL / MariaDB).
		{Probes: []ProbeTarget{{Name: "db:x", Kind: "database", Host: "127.0.0.1", Port: 3306}}},
		{Probes: []ProbeTarget{{Name: "db:x", Kind: "server", Host: "h; rm -rf /", Port: 1}}},
		{Names: []PeerName{{Address: "not-an-ip", Name: "server:x"}}},
		{Names: []PeerName{{Address: "10.0.0.1", Name: "evil"}}},
		{WireGuard: []WGName{{PublicKey: "short", Name: "server:x"}}},
		{LongLivedAfterS: 5},
	} {
		if _, err := c.configure(context.Background(), bad, nil); err == nil {
			t.Errorf("%+v accepted", bad)
		}
	}
}

func TestProbeMeasuresAConnect(t *testing.T) {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer ln.Close()
	port := ln.Addr().(*net.TCPAddr).Port
	d := &net.Dialer{}
	out := runProbes(context.Background(), d.DialContext, []ProbeTarget{{Name: "server:01k", Kind: "server", Host: "127.0.0.1", Port: port}}, time.Now)
	if !out[0].OK || out[0].ConnectMS == nil || out[0].Refused {
		t.Fatalf("%+v", out[0])
	}
	failing := func(context.Context, string, string) (net.Conn, error) { return nil, syscall.EHOSTUNREACH }
	if r := runProbes(context.Background(), failing, []ProbeTarget{{Name: "server:a", Kind: "server", Host: "10.0.0.9", Port: 22}}, time.Now); r[0].OK || r[0].Error != "host unreachable" {
		t.Fatalf("%+v", r[0])
	}
}

func TestListenersKeepPublicOnesFirstAtTheCap(t *testing.T) {
	root := t.TempDir()
	var socks []sock
	for i := range maxListeners + 10 {
		socks = append(socks, sock{fmt.Sprintf("127.0.0.1:%d", 1000+i), "0.0.0.0:0", tcpListen, uint64(i + 1)})
	}
	socks = append(socks, sock{"0.0.0.0:60000", "0.0.0.0:0", tcpListen, 9999})
	writeTree(t, root, map[string]string{"proc/1/net/tcp": table(socks...), "proc/1/ns/net": "->net:[1]"})
	c := New(Options{FS: hostfs.FS{Root: root}, Out: &emitter{}})
	_, rep := c.Collect(context.Background())
	w := rep.Workloads[0]
	if len(w.Listeners) != maxListeners || !w.ListenersTruncated || w.Listeners[0].Port != 60000 || !w.Listeners[0].Public {
		t.Fatalf("%d truncated %v first %+v", len(w.Listeners), w.ListenersTruncated, w.Listeners[0])
	}
	validateReport(t, rep)
}

func TestNamesNeverMatchAContainersOwnLoopback(t *testing.T) {
	n := &names{cfg: map[string]string{"127.0.0.1|5432": "db:01k"}, hostAddrs: map[netip.Addr]bool{}, hostOwners: map[listenerKey]string{}}
	lo := netip.MustParseAddr("127.0.0.1")
	if got := n.name(&workload{inHost: true}, dirOut, lo, 5432); got != "db:01k" {
		t.Fatalf("host: %q", got)
	}
	if got := n.name(&workload{}, dirOut, lo, 5432); got != "" {
		t.Fatalf("container: %q", got)
	}
}

func TestNativeSitesPastTheCapCountForTheHost(t *testing.T) {
	root := t.TempDir()
	files := map[string]string{"proc/1/ns/net": "->net:[1]"}
	var socks []sock
	native := map[int]string{}
	for i := range maxWorkloads + 5 {
		pid, inode := 1000+i, uint64(5000+i)
		socks = append(socks, sock{fmt.Sprintf("10.0.0.5:%d", 20000+i), "203.0.113.1:443", tcpEstablished, inode})
		files[fmt.Sprintf("proc/%d/fd/3", pid)] = fmt.Sprintf("->socket:[%d]", inode)
		native[pid] = fmt.Sprintf("site%03d", i)
	}
	files["proc/1/net/tcp"] = table(socks...)
	writeTree(t, root, files)
	c := New(Options{FS: hostfs.FS{Root: root}, Workloads: snap{Native: native}, Out: &emitter{}})
	_, rep := c.Collect(context.Background())
	if len(rep.Workloads) != maxWorkloads || !rep.Truncated || rep.Workloads[0].Connections.Outbound != 6 {
		t.Fatalf("%d workloads, truncated %v, host outbound %d", len(rep.Workloads), rep.Truncated, rep.Workloads[0].Connections.Outbound)
	}
	validateReport(t, rep)
}
