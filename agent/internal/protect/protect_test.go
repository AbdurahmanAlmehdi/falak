package protect

import (
	"context"
	"encoding/json"
	"fmt"
	"net/netip"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

var st = commands.NewTestStream("c", &commands.Collector{})

// fakeNft simulates the protection table: created by `nft -f`, listed as JSON, changed by add / delete element. It
// refuses overlapping elements the way an interval set does.
type fakeNft struct {
	mu          sync.Mutex
	table       bool
	noCounters  bool // `nft -f` fails when the definition has per-element counters
	elems       map[string]int64
	packets     map[string]uint64
	refuse      map[string]bool // elements whose add fails
	fwRules     string          // `nft -j list table inet falak` output
	createCalls int
}

func newFakeNft() *fakeNft {
	return &fakeNft{elems: map[string]int64{}, packets: map[string]uint64{}, refuse: map[string]bool{}}
}

func (n *fakeNft) runner(root string) *runnertest.Fake {
	f := &runnertest.Fake{}
	f.OnFunc("nft -j list table inet falak_protect", func(runnertest.Call) (runner.Result, error) {
		n.mu.Lock()
		defer n.mu.Unlock()
		if !n.table {
			return runner.Result{ExitCode: 1, Stderr: []byte("Error: No such file or directory")}, nil
		}
		return runner.Result{Stdout: n.listing()}, nil
	})
	f.OnFunc("nft -j list table inet falak", func(runnertest.Call) (runner.Result, error) {
		if n.fwRules == "" {
			return runner.Result{ExitCode: 1}, nil
		}
		return runner.Result{Stdout: []byte(n.fwRules)}, nil
	})
	f.OnFunc("nft -f", func(c runnertest.Call) (runner.Result, error) {
		n.mu.Lock()
		defer n.mu.Unlock()
		n.createCalls++
		def, _ := os.ReadFile(c.Args[1])
		if n.noCounters && strings.Contains(string(def), "\t\tcounter\n") {
			return runner.Result{ExitCode: 1, Stderr: []byte("Error: syntax error, unexpected counter")}, nil
		}
		n.table = true
		return runner.Result{}, nil
	})
	f.OnFunc("nft delete table", func(runnertest.Call) (runner.Result, error) {
		n.mu.Lock()
		defer n.mu.Unlock()
		n.table, n.elems = false, map[string]int64{}
		return runner.Result{}, nil
	})
	f.OnFunc("nft add element", func(c runnertest.Call) (runner.Result, error) { return n.change(c.Args, true) })
	f.OnFunc("nft delete element", func(c runnertest.Call) (runner.Result, error) { return n.change(c.Args, false) })
	f.OnFunc("journalctl", func(runnertest.Call) (runner.Result, error) { return runner.Result{}, nil })
	return f
}

// change applies `add|delete element inet falak_protect <set> { a [timeout Ns] , b }` atomically.
func (n *fakeNft) change(args []string, add bool) (runner.Result, error) {
	n.mu.Lock()
	defer n.mu.Unlock()
	if len(args) < 7 || args[2] != "inet" || args[3] != Table || args[5] != "{" || args[len(args)-1] != "}" {
		return runner.Result{ExitCode: 1, Stderr: []byte("bad args " + strings.Join(args, " "))}, nil
	}
	type item struct {
		k string
		t int64
	}
	var items []item
	inner := args[6 : len(args)-1]
	for i := 0; i < len(inner); i++ {
		if inner[i] == "," {
			continue
		}
		p, err := ParsePrefix(inner[i])
		if err != nil || strings.ContainsAny(inner[i], " ;{}\"") {
			return runner.Result{ExitCode: 1, Stderr: []byte("syntax error " + inner[i])}, nil
		}
		it := item{k: key(p), t: -1}
		if i+2 < len(inner) && inner[i+1] == "timeout" {
			s, err := strconv.ParseInt(strings.TrimSuffix(inner[i+2], "s"), 10, 64)
			if err != nil {
				return runner.Result{ExitCode: 1}, nil
			}
			it.t = s
			i += 2
		}
		items = append(items, it)
	}
	next := map[string]int64{}
	for k, v := range n.elems {
		next[k] = v
	}
	for _, it := range items {
		if !add {
			if _, ok := next[it.k]; !ok {
				return runner.Result{ExitCode: 1, Stderr: []byte("Error: Could not process rule: No such file or directory")}, nil
			}
			delete(next, it.k)
			continue
		}
		if n.refuse[it.k] {
			return runner.Result{ExitCode: 1, Stderr: []byte("Error: interval overlaps with an existing one")}, nil
		}
		np := netip.MustParsePrefix(it.k)
		for k := range next {
			if k != it.k && netip.MustParsePrefix(k).Overlaps(np) {
				return runner.Result{ExitCode: 1, Stderr: []byte("Error: interval overlaps with an existing one")}, nil
			}
		}
		next[it.k] = it.t
	}
	n.elems = next
	return runner.Result{}, nil
}

func (n *fakeNft) listing() []byte {
	type elem struct {
		Elem map[string]any `json:"elem"`
	}
	sets := map[string][]any{Set4: {}, Set6: {}}
	keys := make([]string, 0, len(n.elems))
	for k := range n.elems {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, k := range keys {
		p := netip.MustParsePrefix(k)
		var val any = p.Addr().String()
		if !p.IsSingleIP() {
			val = map[string]any{"prefix": map[string]any{"addr": p.Addr().String(), "len": p.Bits()}}
		}
		e := map[string]any{"val": val}
		if t := n.elems[k]; t >= 0 {
			e["timeout"], e["expires"] = t, t
		}
		if !n.noCounters {
			e["counter"] = map[string]any{"packets": n.packets[k], "bytes": n.packets[k] * 60}
		}
		sets[setFor(p)] = append(sets[setFor(p)], elem{Elem: e})
	}
	doc := map[string]any{"nftables": []any{
		map[string]any{"metainfo": map[string]any{"version": "1.0.9", "json_schema_version": 1}},
		map[string]any{"table": map[string]any{"family": "inet", "name": Table}},
		map[string]any{"set": map[string]any{"family": "inet", "name": Set4, "table": Table, "type": "ipv4_addr", "flags": []string{"interval", "timeout"}, "elem": sets[Set4]}},
		map[string]any{"set": map[string]any{"family": "inet", "name": Set6, "table": Table, "type": "ipv6_addr", "flags": []string{"interval", "timeout"}, "elem": sets[Set6]}},
		map[string]any{"chain": map[string]any{"family": "inet", "table": Table, "name": Chain, "type": "filter", "hook": "prerouting", "prio": -300, "policy": "accept"}},
		map[string]any{"rule": map[string]any{"chain": Chain, "comment": "falak:block4", "expr": []any{map[string]any{"match": map[string]any{}}, map[string]any{"counter": map[string]any{"packets": 42, "bytes": 2520}}, map[string]any{"drop": nil}}}},
		map[string]any{"rule": map[string]any{"chain": Chain, "comment": "falak:block6", "expr": []any{map[string]any{"counter": map[string]any{"packets": 3, "bytes": 180}}, map[string]any{"drop": nil}}}},
	}}
	b, _ := json.Marshal(doc)
	return b
}

func (n *fakeNft) has() []string {
	n.mu.Lock()
	defer n.mu.Unlock()
	var out []string
	for k, t := range n.elems {
		out = append(out, fmt.Sprintf("%s@%d", k, t))
	}
	sort.Strings(out)
	return out
}

var now0 = time.Date(2026, 10, 15, 10, 0, 0, 0, time.UTC)

func newProtection(t *testing.T, n *fakeNft) (*Protection, *runnertest.Fake, string, *time.Time) {
	t.Helper()
	root := t.TempDir()
	clock := now0
	f := n.runner(root)
	p := New(Deps{Runner: f, FS: hostfs.FS{Root: root}, StateDir: "/var/lib/falak", Now: func() time.Time { return clock },
		LocalAddrs: func() []netip.Addr {
			return []netip.Addr{netip.MustParseAddr("192.0.2.1"), netip.MustParseAddr("2001:db8::1")}
		},
		ControlPlane: func(context.Context) []netip.Addr { return []netip.Addr{netip.MustParseAddr("198.51.100.200")} }})
	return p, f, root, &clock
}

func ptime(t time.Time) *time.Time { return &t }

func TestParsePrefix(t *testing.T) {
	for in, want := range map[string]string{
		"203.0.113.7": "203.0.113.7/32", " 203.0.113.0/24 ": "203.0.113.0/24", "2001:db8::1": "2001:db8::1/128", "2001:DB8:1::/48": "2001:db8:1::/48",
	} {
		p, err := ParseBlock(in)
		if err != nil || p.String() != want {
			t.Errorf("%q: %v %v", in, p, err)
		}
	}
	for _, bad := range []string{"", "nope", "203.0.113.7/24", "0.0.0.0/0", "::/0", "10.0.0.0/7", "2001::/15", "::ffff:1.2.3.4", "fe80::1%eth0",
		"1.2.3.4; flush ruleset", "1.2.3.4 }", "1.2.3.4/33", "999.1.1.1"} {
		if _, err := ParseBlock(bad); err == nil {
			t.Errorf("accepted %q", bad)
		}
	}
	if Canonical(netip.MustParsePrefix("203.0.113.7/32")) != "203.0.113.7" || Canonical(netip.MustParsePrefix("10.0.0.0/8")) != "10.0.0.0/8" {
		t.Fatal("canonical")
	}
	if !IsPrivate(netip.MustParseAddr("10.1.2.3")) || !IsPrivate(netip.MustParseAddr("fd00::5")) || IsPrivate(netip.MustParseAddr("203.0.113.7")) {
		t.Fatal("private")
	}
}

func TestTableDefinition(t *testing.T) {
	def := TableDefinition(true)
	for _, w := range []string{"table inet falak_protect {", "set falak_block4 {\n\t\ttype ipv4_addr\n\t\tflags interval, timeout\n\t\tcounter\n\t}",
		"type filter hook prerouting priority raw; policy accept;", `ip saddr @falak_block4 counter drop comment "falak:block4"`,
		`ip6 saddr @falak_block6 counter drop comment "falak:block6"`} {
		if !strings.Contains(def, w) {
			t.Fatalf("missing %q in\n%s", w, def)
		}
	}
	if strings.Contains(def, "delete table") || strings.Contains(def, "flush") {
		t.Fatal("the definition must never replace an existing table")
	}
	if strings.Contains(TableDefinition(false), "\t\tcounter\n") {
		t.Fatal("counters without setCounters")
	}
}

func TestParseListing(t *testing.T) {
	raw := `{"nftables":[{"metainfo":{"version":"1.0.2"}},
{"set":{"family":"inet","name":"falak_block4","table":"falak_protect","type":"ipv4_addr","flags":["interval","timeout"],"elem":[
 "203.0.113.9",
 {"prefix":{"addr":"198.51.100.0","len":24}},
 {"range":["192.0.2.128","192.0.2.255"]},
 {"range":["192.0.2.3","192.0.2.9"]},
 {"elem":{"val":"203.0.113.7","timeout":3600,"expires":3500,"counter":{"packets":12,"bytes":720}}},
 {"elem":{"val":{"prefix":{"addr":"100.64.0.0","len":10}},"counter":{"packets":0,"bytes":0}}}]}},
{"set":{"family":"inet","name":"falak_block6","table":"falak_protect","type":"ipv6_addr","flags":["interval","timeout"]}},
{"chain":{"family":"inet","table":"falak_protect","name":"prerouting"}},
{"rule":{"chain":"input","comment":"falak:01jabc web","expr":[{"match":{}},{"counter":{"packets":5,"bytes":300}},{"accept":null}]}},
{"rule":{"chain":"input","comment":"falak:01jabc web","expr":[{"counter":{"packets":1,"bytes":80}},{"accept":null}]}},
{"rule":{"chain":"input","comment":"falak:ssh","expr":[{"counter":{"packets":7,"bytes":420}}]}},
{"rule":{"chain":"input","comment":"something else","expr":[{"counter":{"packets":9,"bytes":9}}]}},
{"rule":{"chain":"input","expr":[{"counter":{"packets":9,"bytes":9}}]}}]}`
	l, err := ParseListing([]byte(raw))
	if err != nil {
		t.Fatal(err)
	}
	var got []string
	for _, el := range l.Sets[Set4] {
		c := "-"
		if el.Counter != nil {
			c = strconv.FormatUint(el.Counter.Packets, 10)
		}
		got = append(got, fmt.Sprintf("%s %d %s", el.Prefix, el.ExpiresS, c))
	}
	want := []string{"203.0.113.9/32 -1 -", "198.51.100.0/24 -1 -", "192.0.2.128/25 -1 -", "203.0.113.7/32 3500 12", "100.64.0.0/10 -1 0"}
	if strings.Join(got, "|") != strings.Join(want, "|") {
		t.Fatalf("%v", got)
	}
	if s, ok := l.Sets[Set6]; !ok || len(s) != 0 || !l.HasChain {
		t.Fatal("empty set / chain", l)
	}
	if l.Rules["01jabc"] != (Counter{6, 380}) || l.Rules["ssh"] != (Counter{7, 420}) || len(l.Rules) != 2 {
		t.Fatalf("%v", l.Rules)
	}
	if _, err := ParseListing([]byte("not json")); err == nil {
		t.Fatal("garbage accepted")
	}
}

func TestApplyCreatesTableAndElements(t *testing.T) {
	n := newFakeNft()
	p, f, root, clock := newProtection(t, n)
	pl := ApplyPayload{Blocks: []Block{
		{CIDR: "203.0.113.7", ExpiresAt: ptime(now0.Add(time.Hour))},
		{CIDR: "198.51.100.0/25"},
		{CIDR: "2001:db8:1::/48", ExpiresAt: ptime(now0.Add(7 * 24 * time.Hour))},
		{CIDR: "203.0.113.50", ExpiresAt: ptime(now0.Add(-time.Minute))}, // expired: never added
	}, NeverBlock: []string{"10.90.0.0/24"}}
	r, err := p.Apply(context.Background(), pl, st)
	if err != nil {
		t.Fatal(err)
	}
	res := r.(ApplyResult)
	if !res.Changed || res.Added != 3 || res.Removed != 0 || len(res.Failed) != 0 || !res.SetCounters {
		t.Fatalf("%+v", res)
	}
	if got := n.has(); strings.Join(got, " ") != "198.51.100.0/25@-1 2001:db8:1::/48@604800 203.0.113.7/32@3600" {
		t.Fatal(got)
	}
	def, _ := os.ReadFile(filepath.Join(root, "etc/falak/protection.nft"))
	if string(def) != TableDefinition(true) {
		t.Fatal("definition not written")
	}
	// Arguments, never a shell string.
	for _, c := range f.Calls() {
		if c.Name != "nft" && c.Name != "journalctl" {
			t.Fatalf("unexpected %s", c.Line)
		}
	}
	if !f.Ran("nft add element inet falak_protect falak_block4 { 198.51.100.0/25 , 203.0.113.7 timeout 3600s }") {
		t.Fatal(f.Lines())
	}
	// State persisted with absolute expiry times.
	raw, _ := os.ReadFile(filepath.Join(root, "var/lib/falak/protection.json"))
	if !strings.Contains(string(raw), `"until": "2026-10-15T11:00:00Z"`) || !strings.Contains(string(raw), `"configured": true`) {
		t.Fatal(string(raw))
	}

	// Same state again: nothing changes, no table re-created, counters kept.
	f.Reset()
	r, _ = p.Apply(context.Background(), pl, st)
	if r.(ApplyResult).Changed || f.Ran("nft add") || f.Ran("nft delete") || f.Ran("nft -f") {
		t.Fatal("not idempotent", f.Lines())
	}

	// 10 minutes later the kernel's timeout shows 3000 s left; the desired expiry is the same: untouched.
	*clock = now0.Add(10 * time.Minute)
	n.elems["203.0.113.7/32"] = 3000
	n.elems["2001:db8:1::/48"] = 604800 - 600
	f.Reset()
	r, _ = p.Apply(context.Background(), pl, st)
	if r.(ApplyResult).Changed {
		t.Fatal("re-added an element whose expiry did not move", f.Lines())
	}

	// Unblock one, extend another: deleted, re-added with the new timeout.
	pl.Blocks = []Block{{CIDR: "203.0.113.7", ExpiresAt: ptime(now0.Add(24 * time.Hour))}, {CIDR: "2001:db8:1::/48", ExpiresAt: ptime(now0.Add(7 * 24 * time.Hour))}}
	r, _ = p.Apply(context.Background(), pl, st)
	res = r.(ApplyResult)
	if res.Removed != 2 || res.Added != 1 {
		t.Fatalf("%+v %v", res, f.Lines())
	}
	if got := n.has(); strings.Join(got, " ") != "2001:db8:1::/48@604200 203.0.113.7/32@85800" {
		t.Fatal(got)
	}
}

func TestApplyGuards(t *testing.T) {
	n := newFakeNft()
	p, _, _, _ := newProtection(t, n)
	refused := []string{
		"10.90.0.5",       // in never_block
		"10.90.0.0/16",    // contains a never_block range
		"10.90.0.128/25",  // inside a never_block range
		"192.0.2.0/24",    // contains the host's own address
		"192.0.2.1",       // the host itself
		"2001:db8::/32",   // contains the host's IPv6
		"2001:db8::1",     // the host itself (IPv6)
		"198.51.100.200",  // the control plane
		"198.51.100.0/24", // contains the control plane
		"127.0.0.0/8",     // loopback
		"169.254.0.0/16",  // link-local
		"fe80::/64",       // link-local (IPv6)
		"224.0.0.0/8",     // multicast
	}
	allowed := []string{
		"203.0.113.0/24", // fine
		"2001:db9::/32",  // fine
		"100.64.0.0/10",  // private, but a user confirmed it in the panel
		"fd00:1::/32",    // ULA, confirmed
		"11.0.0.0/8",     // broad, confirmed
	}
	var blocks []Block
	for _, c := range append(append([]string{}, refused...), allowed...) {
		blocks = append(blocks, Block{CIDR: c})
	}
	blocks = append(blocks, Block{CIDR: "203.0.113.0/24"}) // a duplicate is one element
	r, err := p.Apply(context.Background(), ApplyPayload{Blocks: blocks, NeverBlock: []string{"10.90.0.0/24"}}, st)
	if err != nil {
		t.Fatal(err)
	}
	res := r.(ApplyResult)
	var failed []string
	for _, f := range res.Failed {
		failed = append(failed, f.CIDR)
	}
	want := append([]string{}, refused...)
	sort.Strings(want)
	if strings.Join(failed, " ") != strings.Join(want, " ") {
		t.Fatalf("failed: %v", failed)
	}
	var added []string
	for _, e := range n.has() {
		added = append(added, Canonical(netip.MustParsePrefix(strings.Split(e, "@")[0])))
	}
	sort.Strings(added)
	sort.Strings(allowed)
	if res.Added != len(allowed) || strings.Join(added, " ") != strings.Join(allowed, " ") {
		t.Fatalf("added %d: %v", res.Added, added)
	}

	// A range that became protected after it was added (a new server) is removed.
	r, _ = p.Apply(context.Background(), ApplyPayload{Blocks: []Block{{CIDR: "203.0.113.0/24"}}, NeverBlock: []string{"10.90.0.0/24", "203.0.113.10"}}, st)
	if res := r.(ApplyResult); len(n.has()) != 0 || len(res.Failed) != 1 || res.Failed[0].CIDR != "203.0.113.0/24" {
		t.Fatalf("%+v %v", res, n.has())
	}

	// Payload errors: refused before anything runs.
	for _, bad := range []ApplyPayload{
		{Blocks: []Block{{CIDR: "0.0.0.0/0"}}}, {Blocks: []Block{{CIDR: "::/0"}}}, {Blocks: []Block{{CIDR: "1.2.3.4; flush ruleset"}}},
		{Blocks: []Block{{CIDR: "203.0.113.7/24"}}}, {NeverBlock: []string{"x"}}, {AutoBan: &AutoBan{Enabled: true, Failures: 1, WindowS: 60, BanS: 60}},
		{AutoBan: &AutoBan{Enabled: true, Failures: 5, WindowS: 60, BanS: 31 * 86400}},
	} {
		if _, err := p.Apply(context.Background(), bad, st); !commands.IsPayloadError(err) {
			t.Fatalf("accepted %+v: %v", bad, err)
		}
	}
}

func TestApplyBatchFallbackAndSetCounters(t *testing.T) {
	n := newFakeNft()
	n.noCounters = true
	n.refuse["203.0.113.8/32"] = true
	p, f, _, _ := newProtection(t, n)
	r, err := p.Apply(context.Background(), ApplyPayload{Blocks: []Block{{CIDR: "203.0.113.7"}, {CIDR: "203.0.113.8"}, {CIDR: "203.0.113.9"}}}, st)
	if err != nil {
		t.Fatal(err)
	}
	res := r.(ApplyResult)
	if res.Added != 2 || len(res.Failed) != 1 || res.Failed[0].CIDR != "203.0.113.8" || !strings.Contains(res.Failed[0].Error, "overlaps") || res.SetCounters {
		t.Fatalf("%+v", res)
	}
	if n.createCalls != 2 {
		t.Fatalf("create calls %d (with counters, then without)", n.createCalls)
	}
	// The host's answer is remembered: no second attempt with counters.
	n.mu.Lock()
	n.table = false
	n.mu.Unlock()
	f.Reset()
	p.Restore(context.Background())
	if n.createCalls != 3 || len(n.has()) != 2 {
		t.Fatal(n.createCalls, n.has())
	}
}

func TestRestoreAfterRebootUsesRemainingTime(t *testing.T) {
	n := newFakeNft()
	p, _, root, clock := newProtection(t, n)
	if _, err := p.Apply(context.Background(), ApplyPayload{Blocks: []Block{{CIDR: "203.0.113.7", ExpiresAt: ptime(now0.Add(time.Hour))}, {CIDR: "203.0.113.9", ExpiresAt: ptime(now0.Add(time.Minute))}}}, st); err != nil {
		t.Fatal(err)
	}
	// Reboot: the kernel forgot everything; a new agent process loads the state 30 minutes later.
	n2 := newFakeNft()
	*clock = now0.Add(30 * time.Minute)
	f2 := n2.runner(root)
	p2 := New(Deps{Runner: f2, FS: hostfs.FS{Root: root}, StateDir: "/var/lib/falak", Now: func() time.Time { return *clock },
		LocalAddrs: func() []netip.Addr { return nil }})
	p2.Restore(context.Background())
	if got := n2.has(); strings.Join(got, " ") != "203.0.113.7/32@1800" {
		t.Fatal(got)
	}
	// Never configured: nothing is created.
	n3 := newFakeNft()
	p3 := New(Deps{Runner: n3.runner(t.TempDir()), FS: hostfs.FS{Root: t.TempDir()}, StateDir: "/var/lib/falak"})
	p3.Restore(context.Background())
	if n3.table {
		t.Fatal("table created without a net.protection.apply")
	}
}

func TestApplyRebuildsIncompleteTable(t *testing.T) {
	n := newFakeNft()
	root := t.TempDir()
	base := n.runner(root)
	fr := &runnertest.Fake{}
	fr.OnFunc("nft -j list table inet falak_protect", func(c runnertest.Call) (runner.Result, error) {
		n.mu.Lock()
		created := n.createCalls > 0
		n.mu.Unlock()
		if !created {
			// An older layout: the sets without the chain.
			return runner.Result{Stdout: []byte(`{"nftables":[{"set":{"name":"falak_block4","elem":[]}},{"set":{"name":"falak_block6"}}]}`)}, nil
		}
		return base.Run(context.Background(), c.Cmd)
	})
	fr.OnFunc("", func(c runnertest.Call) (runner.Result, error) { return base.Run(context.Background(), c.Cmd) })
	p := New(Deps{Runner: fr, FS: hostfs.FS{Root: root}, StateDir: "/var/lib/falak", LocalAddrs: func() []netip.Addr { return nil }})
	if _, err := p.Apply(context.Background(), ApplyPayload{Blocks: []Block{{CIDR: "203.0.113.7"}}}, st); err != nil {
		t.Fatal(err)
	}
	// Completed in place (the definition only adds the missing chain): never deleted, elements and counters kept.
	if fr.Ran("nft delete table") || n.createCalls != 1 || len(n.has()) != 1 {
		t.Fatal(fr.Lines())
	}
}

func TestApplyNeverTouchesATableItCannotRead(t *testing.T) {
	for name, res := range map[string]runner.Result{
		"chain without sets": {Stdout: []byte(`{"nftables":[{"chain":{"name":"prerouting"}}]}`)},
		"listing refused":    {ExitCode: 1, Stderr: []byte("Error: Operation not permitted")},
	} {
		t.Run(name, func(t *testing.T) {
			fr := (&runnertest.Fake{}).On("nft -j list table inet falak_protect", res)
			p := New(Deps{Runner: fr, FS: hostfs.FS{Root: t.TempDir()}, StateDir: "/var/lib/falak", LocalAddrs: func() []netip.Addr { return nil }})
			if _, err := p.Apply(context.Background(), ApplyPayload{Blocks: []Block{{CIDR: "203.0.113.7"}}}, st); err == nil {
				t.Fatal("no error")
			}
			if fr.Ran("nft -f") || fr.Ran("nft delete") || fr.Ran("nft add") {
				t.Fatal(fr.Lines())
			}
		})
	}
}

func TestUpstreamsAreGuarded(t *testing.T) {
	root := t.TempDir()
	for f, body := range map[string]string{
		"etc/resolv.conf":                 "# stub\nnameserver 127.0.0.53\noptions edns0\n",
		"run/systemd/resolve/resolv.conf": "nameserver 203.0.113.53\nnameserver 2001:db8:53::1\n",
		"proc/net/route":                  "Iface\tDestination\tGateway \tFlags\nens3\t00000000\t017100CB\t0003\nens3\t007100CB\t00000000\t0001\n",
		"proc/net/ipv6_route":             "00000000000000000000000000000000 00 00000000000000000000000000000000 00 20010db8000000000000000000000001 00000400 00000001 00000000 00000003 ens3\n",
	} {
		os.MkdirAll(filepath.Join(root, filepath.Dir(f)), 0o755)
		os.WriteFile(filepath.Join(root, f), []byte(body), 0o644)
	}
	got := UpstreamAddrs(hostfs.FS{Root: root})
	var ss []string
	for _, a := range got {
		ss = append(ss, a.String())
	}
	if strings.Join(ss, " ") != "127.0.0.53 203.0.113.53 2001:db8:53::1 203.0.113.1 2001:db8::1" {
		t.Fatal(ss)
	}
	n := newFakeNft()
	f := n.runner(root)
	p := New(Deps{Runner: f, FS: hostfs.FS{Root: root}, StateDir: "/var/lib/falak", LocalAddrs: func() []netip.Addr { return nil }})
	r, err := p.Apply(context.Background(), ApplyPayload{Blocks: []Block{{CIDR: "203.0.113.0/24"}, {CIDR: "2001:db8::/48"}, {CIDR: "198.51.100.9"}}}, st)
	if err != nil {
		t.Fatal(err)
	}
	if res := r.(ApplyResult); len(res.Failed) != 2 || strings.Join(n.has(), " ") != "198.51.100.9/32@-1" {
		t.Fatalf("%+v %v", res, n.has())
	}
}
