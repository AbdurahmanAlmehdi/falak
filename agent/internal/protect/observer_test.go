package protect

import (
	"bytes"
	"context"
	"encoding/json"
	"net/netip"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/santhosh-tekuri/jsonschema/v6"
	metricspb "go.opentelemetry.io/proto/otlp/metrics/v1"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
	"github.com/OthmanHaba/falak/agent/internal/transport"
)

func TestParseAuthLog(t *testing.T) {
	raw, _ := os.ReadFile("testdata/auth.log")
	now := time.Date(2026, 10, 15, 10, 1, 0, 0, time.UTC)
	tr := newTracker()
	var evs []AuthEvent
	for _, l := range strings.Split(string(raw), "\n") {
		if ev, ok := ParseAuthLogLine(l, now); ok {
			evs = append(evs, ev)
		}
	}
	tr.add(evs, 10*time.Minute)
	got := map[string]SourceStat{}
	for _, s := range tr.report() {
		got[s.IP] = s
	}
	// 203.0.113.7: invalid user + root + 3 repeated (the "Failed password for invalid user" line is not counted twice).
	if s := got["203.0.113.7"]; s.Failures != 5 || s.InvalidUsers != 1 || strings.Join(s.Users, ",") != "admin,root" {
		t.Fatalf("%+v", s)
	}
	if s := got["2001:db8:dead::7"]; s.Failures != 2 || strings.Join(s.Users, ",") != "root,ubuntu" {
		t.Fatalf("%+v", s)
	}
	// IPv4-mapped IPv6 unmapped; classic syslog timestamps.
	if s := got["198.51.100.9"]; s.Failures != 2 || s.LastAt.Hour() != 10 || s.LastAt.Second() != 12 {
		t.Fatalf("%+v", s)
	}
	for _, ip := range []string{"198.51.100.4", "192.0.2.50", "203.0.113.99"} {
		if _, ok := got[ip]; ok {
			t.Fatalf("%s counted", ip)
		}
	}
	if !tr.isTrusted(netip.MustParseAddr("192.0.2.50"), now) || tr.total != 9 {
		t.Fatal("trusted / total", tr.total)
	}
	for _, bad := range []string{"Failed password for root from not-an-ip port 1 ssh2", "message repeated 0 times: [ Invalid user a from 1.2.3.4 ]", "Invalid user x from 1.2.3.4%eth0"} {
		if ev, ok := ParseAuthMessage(bad); ok && ev.Addr.Zone() != "" {
			t.Fatalf("parsed %q", bad)
		} else if ok && strings.Contains(bad, "not-an-ip") {
			t.Fatalf("parsed %q", bad)
		}
	}
}

func TestParseJournal(t *testing.T) {
	raw, _ := os.ReadFile("testdata/journal.json")
	evs, cursor := ParseJournal(raw, time.Now())
	if cursor != "s=a;i=5" || len(evs) != 3 {
		t.Fatalf("%q %+v", cursor, evs)
	}
	if evs[0].Addr.String() != "2001:db8::bad" || !evs[0].Invalid || evs[0].At.Unix() != 1760522400 {
		t.Fatalf("%+v", evs[0])
	}
	if evs[2].Addr.String() != "203.0.113.8" || !evs[2].Invalid {
		t.Fatalf("byte-array MESSAGE: %+v", evs[2])
	}
}

func TestAuthLogFileRotation(t *testing.T) {
	root := t.TempDir()
	path := filepath.Join(root, "var/log/auth.log")
	os.MkdirAll(filepath.Dir(path), 0o755)
	os.WriteFile(path, []byte("Oct 15 09:00:00 h sshd[1]: Invalid user old from 203.0.113.1 port 1\n"), 0o640)
	r := &authReader{runner: &runnertest.Fake{}, fs: hostfs.FS{Root: root}, now: time.Now, noJournal: true}
	if evs, src := r.Read(context.Background()); len(evs) != 0 || src != SourceAuthLog {
		t.Fatal("first read must start at the end", evs)
	}
	f, _ := os.OpenFile(path, os.O_APPEND|os.O_WRONLY, 0)
	f.WriteString("Oct 15 09:00:01 h sshd[1]: Invalid user a from 203.0.113.2 port 1\nOct 15 09:00:02 h sshd[1]: Invalid user b from 203.0.113.3")
	f.Close()
	if evs, _ := r.Read(context.Background()); len(evs) != 1 || evs[0].User != "a" {
		t.Fatalf("%+v", evs)
	}
	// The partial line is completed, then the file is rotated.
	f, _ = os.OpenFile(path, os.O_APPEND|os.O_WRONLY, 0)
	f.WriteString(" port 1\n")
	f.Close()
	if evs, _ := r.Read(context.Background()); len(evs) != 1 || evs[0].User != "b" {
		t.Fatalf("%+v", evs)
	}
	os.Rename(path, path+".1")
	os.WriteFile(path, []byte("Oct 15 09:00:03 h sshd[1]: Invalid user c from 203.0.113.4 port 1\n"), 0o640)
	if evs, _ := r.Read(context.Background()); len(evs) != 1 || evs[0].User != "c" {
		t.Fatalf("after rotation %+v", evs)
	}
}

type reqs struct{ got []Report }

func (r *reqs) Request(_ context.Context, typ string, in, _ any) error {
	if typ != "net.protection.observed" {
		return &transport.StatusError{Code: 404}
	}
	r.got = append(r.got, in.(Report))
	return nil
}

type sink struct{ rm []*metricspb.ResourceMetrics }

func (s *sink) EmitMetrics(rm *metricspb.ResourceMetrics) { s.rm = append(s.rm, rm) }

func reportSchema(t *testing.T) *jsonschema.Schema {
	t.Helper()
	c := jsonschema.NewCompiler()
	c.DefaultDraft(jsonschema.Draft2020)
	c.AssertFormat()
	f, err := os.Open("../../../contracts/agent-protocol/requests/net.protection.observed.schema.json")
	if err != nil {
		t.Fatal(err)
	}
	defer f.Close()
	doc, _ := jsonschema.UnmarshalJSON(f)
	const id = "https://falak.sh/agent-protocol/requests/net.protection.observed.schema.json"
	if err := c.AddResource(id, doc); err != nil {
		t.Fatal(err)
	}
	s, err := c.Compile(id)
	if err != nil {
		t.Fatal(err)
	}
	return s
}

func journalLines(lines ...string) []byte {
	var b bytes.Buffer
	for i, l := range lines {
		j, _ := json.Marshal(map[string]string{"__CURSOR": "c" + string(rune('a'+i)), "MESSAGE": l})
		b.Write(j)
		b.WriteByte('\n')
	}
	return b.Bytes()
}

func TestObserverAutoBan(t *testing.T) {
	n := newFakeNft()
	n.fwRules = `{"nftables":[{"rule":{"chain":"input","comment":"falak:ssh","expr":[{"counter":{"packets":7,"bytes":420}}]}},{"rule":{"chain":"input","comment":"falak:01jweb HTTP","expr":[{"counter":{"packets":9,"bytes":900}}]}}]}`
	root := t.TempDir()
	base := n.runner(root)
	var journal []byte
	f := &runnertest.Fake{}
	f.OnFunc("journalctl", func(runnertest.Call) (runner.Result, error) {
		out := journal
		journal = nil
		return runner.Result{Stdout: out}, nil
	})
	f.OnFunc("", func(c runnertest.Call) (runner.Result, error) { return base.Run(context.Background(), c.Cmd) })
	clock := now0
	p := New(Deps{Runner: f, FS: hostfs.FS{Root: root}, StateDir: "/var/lib/falak", Now: func() time.Time { return clock },
		LocalAddrs: func() []netip.Addr { return []netip.Addr{netip.MustParseAddr("192.0.2.1")} }})
	cp, m := &reqs{}, &sink{}
	o := p.NewObserver(cp, m)

	// Auto-ban off (never configured): failures are reported, nothing is banned, no table.
	var lines []string
	for range 12 {
		lines = append(lines, "Failed password for root from 203.0.113.7 port 1 ssh2")
	}
	journal = journalLines(lines...)
	r := o.Pass(context.Background())
	if len(r.Bans) != 0 || n.table || r.Auth == nil || r.Auth.Sources[0].Failures != 12 || r.Auth.Source != SourceJournal {
		t.Fatalf("%+v", r)
	}

	// On: 3 failures in 10 minutes → 1 hour.
	if _, err := p.Apply(context.Background(), ApplyPayload{Blocks: []Block{{CIDR: "198.51.100.0/24"}}, NeverBlock: []string{"203.0.113.50"},
		AutoBan: &AutoBan{Enabled: true, Failures: 3, WindowS: 600, BanS: 3600}}, st); err != nil {
		t.Fatal(err)
	}
	clock = clock.Add(time.Minute)
	lines = nil
	for _, ip := range []string{"203.0.113.9", "203.0.113.50", "10.0.0.5", "198.51.100.7", "192.0.2.77", "2001:db8:bad::1"} {
		for range 3 {
			lines = append(lines, "Invalid user x from "+ip+" port 1")
		}
	}
	lines = append(lines, "Accepted publickey for deploy from 192.0.2.77 port 2 ssh2", "Invalid user y from 203.0.113.10 port 1")
	journal = journalLines(lines...)
	r = o.Pass(context.Background())
	var banned []string
	for _, b := range r.Bans {
		banned = append(banned, b.CIDR)
		if b.Failures < 3 || !b.Until.Equal(clock.Add(time.Hour).UTC()) {
			t.Fatalf("%+v", b)
		}
	}
	// 203.0.113.7 still has 12 failures in the window (the threshold is crossed once it is on); the guarded, private,
	// covered and trusted ones are not banned.
	if strings.Join(banned, " ") != "203.0.113.7 203.0.113.9 2001:db8:bad::1" {
		t.Fatalf("banned %v", banned)
	}
	if got := strings.Join(n.has(), " "); got != "198.51.100.0/24@-1 2001:db8:bad::1/128@3600 203.0.113.7/32@3600 203.0.113.9/32@3600" {
		t.Fatal(got)
	}
	if len(r.Rules) != 2 || r.Rules[0].ID != "01jweb" || r.Rules[1] != (RuleCounter{ID: "ssh", Packets: 7, Bytes: 420}) || r.Blocked == nil || r.Blocked.Packets != 45 {
		t.Fatalf("%+v %+v", r.Rules, r.Blocked)
	}
	// The report is valid against the request schema.
	sch := reportSchema(t)
	for _, rep := range cp.got {
		b, _ := json.Marshal(rep)
		v, _ := jsonschema.UnmarshalJSON(bytes.NewReader(b))
		if err := sch.Validate(v); err != nil {
			t.Fatalf("%s: %v", b, err)
		}
	}
	if len(cp.got) != 2 {
		t.Fatal(len(cp.got))
	}
	// Metrics: rule ids and families only, never addresses.
	names := map[string]bool{}
	for _, rm := range m.rm {
		for _, sm := range rm.ScopeMetrics {
			for _, mt := range sm.Metrics {
				names[mt.Name] = true
				b, _ := json.Marshal(mt)
				if strings.Contains(string(b), "203.0.113") {
					t.Fatalf("address in a metric: %s", b)
				}
			}
		}
	}
	for _, w := range []string{"falak.net.firewall.packets", "falak.net.firewall.bytes", "falak.net.firewall.blocked.packets", "falak.net.protection.blocks", "falak.net.auth.failures"} {
		if !names[w] {
			t.Fatalf("missing %s in %v", w, names)
		}
	}

	// A user lifts a ban: the control plane releases it, the agent forgets it.
	if _, err := p.Apply(context.Background(), ApplyPayload{Blocks: []Block{{CIDR: "198.51.100.0/24"}, {CIDR: "203.0.113.9", ExpiresAt: ptime(clock.Add(time.Hour))}},
		Release: []string{"203.0.113.7", "2001:db8:bad::1"}, NeverBlock: []string{"203.0.113.50"}, AutoBan: &AutoBan{Enabled: true, Failures: 3, WindowS: 600, BanS: 3600}}, st); err != nil {
		t.Fatal(err)
	}
	if got := strings.Join(n.has(), " "); got != "198.51.100.0/24@-1 203.0.113.9/32@3600" {
		t.Fatal(got)
	}

	// The table is flushed behind the agent's back: the next pass puts it back.
	n.mu.Lock()
	n.table, n.elems = false, map[string]int64{}
	n.mu.Unlock()
	clock = clock.Add(time.Minute)
	o.Pass(context.Background())
	if got := strings.Join(n.has(), " "); got != "198.51.100.0/24@-1 203.0.113.9/32@3540" {
		t.Fatal(got)
	}
}

func TestAutoBanRateLimit(t *testing.T) {
	n := newFakeNft()
	p, _, _, _ := newProtection(t, n)
	if _, err := p.Apply(context.Background(), ApplyPayload{Blocks: []Block{}, AutoBan: &AutoBan{Enabled: true, Failures: 2, WindowS: 600, BanS: 600}}, st); err != nil {
		t.Fatal(err)
	}
	var evs []AuthEvent
	for i := range 100 {
		a := netip.AddrFrom4([4]byte{203, 0, 113, byte(i + 1)})
		evs = append(evs, AuthEvent{Kind: AuthFailure, Addr: a, Count: 2, At: now0})
	}
	p.mu.Lock()
	p.auth.add(evs, 10*time.Minute)
	bans := p.autoBanLocked(context.Background(), now0)
	p.mu.Unlock()
	if len(bans) != maxBansPerPass || len(n.has()) != maxBansPerPass {
		t.Fatal(len(bans))
	}
}

func TestResolveHostsAndHostOf(t *testing.T) {
	for in, want := range map[string]string{"https://cp.example.com:8443/agent/v1": "cp.example.com", "https://[2001:db8::5]:443": "2001:db8::5", "203.0.113.4": "203.0.113.4", "": ""} {
		if got := hostOf(in); got != want {
			t.Errorf("%q: %q", in, got)
		}
	}
	got := ResolveHosts("https://198.51.100.200:8443/x", "https://[2001:db8::5]/")(context.Background())
	if len(got) != 2 || got[0].String() != "198.51.100.200" {
		t.Fatal(got)
	}
}
