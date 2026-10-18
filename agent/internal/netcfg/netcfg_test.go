package netcfg

import (
	"context"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

var st = commands.NewTestStream("c", &commands.Collector{})

func payload() FirewallPayload {
	return FirewallPayload{SSHPort: 2222, Rules: []Rule{
		{ID: "web", Ports: []string{"80", "443"}, Comment: `public "web"`},
		{ID: "pg", Ports: []string{"5432"}, Sources: []string{"10.0.0.0/8", "fd00::/8", "192.0.2.4"}},
		{ID: "dns", Protocol: "any", Ports: []string{"53"}, Interface: "wg-falak"},
		{ID: "block", Action: "drop", Protocol: "any", Sources: []string{"198.51.100.0/24"}},
		{ID: "range", Protocol: "udp", Ports: []string{"60000-61000"}},
	}}
}

func TestRenderRuleset(t *testing.T) {
	rs, err := RenderRuleset(payload())
	if err != nil {
		t.Fatal(err)
	}
	for _, w := range []string{
		"table inet falak {}\ndelete table inet falak\n",
		"type filter hook input priority filter; policy drop;",
		"ct state established,related accept",
		`iif "lo" accept`,
		"meta l4proto { icmp, ipv6-icmp } accept",
		`tcp dport 2222 accept comment "falak:ssh"`,
		`iifname "br-sbx" ip daddr 10.88.0.1 tcp dport 3128 accept comment "falak:sandbox-proxy"`,
		`tcp dport { 80, 443 } accept comment "falak:web public web"`,
		`ip saddr { 10.0.0.0/8, 192.0.2.4 } tcp dport 5432 accept comment "falak:pg"`,
		`ip6 saddr fd00::/8 tcp dport 5432 accept comment "falak:pg"`,
		`iifname "wg-falak" meta l4proto { tcp, udp } th dport 53 accept comment "falak:dns"`,
		`ip saddr 198.51.100.0/24 drop comment "falak:block"`,
		`udp dport 60000-61000 accept comment "falak:range"`,
	} {
		if !strings.Contains(rs, w) {
			t.Fatalf("missing %q in\n%s", w, rs)
		}
	}
	rs2, _ := RenderRuleset(payload())
	if rs != rs2 {
		t.Fatal("not deterministic")
	}
	for _, bad := range []Rule{{ID: "x", Ports: []string{"70000"}}, {ID: "x", Ports: []string{"9-3"}}, {ID: "x", Sources: []string{"nope"}}, {ID: "x;y"}, {ID: "x", Interface: `eth0" accept`}} {
		if _, err := RenderRuleset(FirewallPayload{Rules: []Rule{bad}}); !commands.IsPayloadError(err) {
			t.Fatalf("accepted %+v", bad)
		}
	}
}

func TestRenderRulesetCounters(t *testing.T) {
	plain, _ := RenderRuleset(payload())
	if strings.Contains(plain, "counter") {
		t.Fatal("counters without the flag (older control planes: byte-identical ruleset)")
	}
	p := payload()
	p.Counters = true
	rs, err := RenderRuleset(p)
	if err != nil {
		t.Fatal(err)
	}
	for _, w := range []string{
		`tcp dport 2222 counter accept comment "falak:ssh"`,
		`tcp dport { 80, 443 } counter accept comment "falak:web public web"`,
		`ip6 saddr fd00::/8 tcp dport 5432 counter accept comment "falak:pg"`,
		`ip saddr 198.51.100.0/24 counter drop comment "falak:block"`,
	} {
		if !strings.Contains(rs, w) {
			t.Fatalf("missing %q in\n%s", w, rs)
		}
	}
}

func TestFirewallLocalEdge(t *testing.T) {
	root := t.TempDir()
	n := New(Deps{Runner: &runnertest.Fake{}, FS: hostfs.FS{Root: root}})
	if _, err := n.FirewallApply(context.Background(), payload(), st); err != nil {
		t.Fatal(err)
	}
	plain, _ := os.ReadFile(filepath.Join(root, RulesetPath))
	if strings.Contains(string(plain), "local-edge") {
		t.Fatal("local edge rule without local-edge.env")
	}
	os.WriteFile(filepath.Join(root, "etc/falak/local-edge.env"), []byte("FALAK_LOCAL_EDGE=1\nFALAK_LOCAL_EDGE_ADDR=172.17.0.1\nFALAK_LOCAL_EDGE_TRUST=172.30.0.0/16\n"), 0o644)
	if _, err := n.FirewallApply(context.Background(), payload(), st); err != nil {
		t.Fatal(err)
	}
	rs, _ := os.ReadFile(filepath.Join(root, RulesetPath))
	w := `ip saddr 172.30.0.0/16 ip daddr 172.17.0.1 tcp dport { 8080, 8443 } accept comment "falak:local-edge"`
	if strings.Replace(string(rs), "\t\t"+w+"\n", "", 1) != string(plain) {
		t.Fatalf("want exactly one more rule %q:\n%s", w, rs)
	}
}

func TestFirewallApply(t *testing.T) {
	root := t.TempDir()
	applied := false
	f := &runnertest.Fake{}
	f.OnFunc("nft list table inet falak", func(runnertest.Call) (runner.Result, error) {
		if applied {
			return runner.Result{}, nil
		}
		return runner.Result{ExitCode: 1}, nil
	})
	f.OnFunc("nft -f", func(runnertest.Call) (runner.Result, error) { applied = true; return runner.Result{}, nil })
	n := New(Deps{Runner: f, FS: hostfs.FS{Root: root}})
	r, err := n.FirewallApply(context.Background(), payload(), st)
	if err != nil || !r.(FirewallResult).Changed {
		t.Fatal(r, err)
	}
	conf := filepath.Join(root, "etc/falak/nftables.conf")
	want := []string{"nft list table inet falak", "nft -c -f " + conf + ".new", "nft -f " + conf, "systemctl daemon-reload", "systemctl enable falak-firewall.service"}
	if strings.Join(f.Lines(), "|") != strings.Join(want, "|") {
		t.Fatalf("%v", f.Lines())
	}
	f.Reset()
	r, _ = n.FirewallApply(context.Background(), payload(), st)
	if r.(FirewallResult).Changed || len(f.Lines()) != 1 {
		t.Fatal("not idempotent", f.Lines())
	}
	// table flushed out-of-band (e.g. reboot without unit) → re-applied even though file matches
	applied = false
	r, _ = n.FirewallApply(context.Background(), payload(), st)
	if !r.(FirewallResult).Changed {
		t.Fatal("did not re-apply missing table")
	}
}

func TestFirewallValidationFailureKeepsOldFile(t *testing.T) {
	root := t.TempDir()
	f := (&runnertest.Fake{}).On("nft -c", runner.Result{ExitCode: 1, Stderr: []byte("Error: syntax error")})
	n := New(Deps{Runner: f, FS: hostfs.FS{Root: root}})
	os.MkdirAll(filepath.Join(root, "etc/falak"), 0o755)
	os.WriteFile(filepath.Join(root, "etc/falak/nftables.conf"), []byte("old"), 0o600)
	if _, err := n.FirewallApply(context.Background(), payload(), st); err == nil || !strings.Contains(err.Error(), "untouched") {
		t.Fatal(err)
	}
	if b, _ := os.ReadFile(filepath.Join(root, "etc/falak/nftables.conf")); string(b) != "old" {
		t.Fatal("old ruleset replaced")
	}
	if f.Ran("nft -f") {
		t.Fatal("applied invalid ruleset")
	}
	if _, err := os.Stat(filepath.Join(root, "etc/falak/nftables.conf.new")); err == nil {
		t.Fatal("tmp left")
	}
}

func TestWireGuard(t *testing.T) {
	root := t.TempDir()
	active := false
	f := &runnertest.Fake{}
	f.OnFunc("systemctl is-active", func(runnertest.Call) (runner.Result, error) {
		if active {
			return runner.Result{}, nil
		}
		return runner.Result{ExitCode: 3}, nil
	})
	f.OnFunc("systemctl enable --now", func(runnertest.Call) (runner.Result, error) { active = true; return runner.Result{}, nil })
	f.On("wg-quick strip", runner.Result{Stdout: []byte("[Interface]\nPrivateKey = x\n")})
	n := New(Deps{Runner: f, FS: hostfs.FS{Root: root}})
	os.MkdirAll(filepath.Join(root, "usr/bin"), 0o755)
	os.WriteFile(filepath.Join(root, "usr/bin/wg-quick"), []byte("#!/bin/bash\n"), 0o755)
	peerKey := base64.StdEncoding.EncodeToString(make([]byte, 32))
	p := WireGuardPayload{Address: "10.90.0.3/24", Peers: []Peer{{PublicKey: peerKey, Endpoint: "203.0.113.1:51820", AllowedIPs: []string{"10.90.0.1/32"}, PersistentKeepalive: 25}}}
	r, err := n.WireGuardApply(context.Background(), p, st)
	if err != nil {
		t.Fatal(err)
	}
	res := r.(WireGuardResult)
	if !res.Changed || len(res.PublicKey) != 44 || !f.Ran("systemctl enable --now wg-quick@wg-falak") {
		t.Fatal(res, f.Lines())
	}
	keyFile := filepath.Join(root, "etc/falak/wireguard/wg-falak.key")
	if fi, _ := os.Stat(keyFile); fi.Mode().Perm() != 0o600 {
		t.Fatal("key mode")
	}
	conf, _ := os.ReadFile(filepath.Join(root, "etc/wireguard/wg-falak.conf"))
	if !strings.Contains(string(conf), "Endpoint = 203.0.113.1:51820") || !strings.Contains(string(conf), "PersistentKeepalive = 25") {
		t.Fatal(string(conf))
	}
	// key persists and derives the same public key
	f.Reset()
	r, _ = n.WireGuardApply(context.Background(), p, st)
	if r.(WireGuardResult).Changed || r.(WireGuardResult).PublicKey != res.PublicKey || len(f.Lines()) != 1 {
		t.Fatal("not idempotent", r, f.Lines())
	}
	// peer change → live syncconf with stripped config on stdin
	p.Peers[0].PersistentKeepalive = 15
	f.Reset()
	r, _ = n.WireGuardApply(context.Background(), p, st)
	calls := f.Calls()
	last := calls[len(calls)-1]
	if !r.(WireGuardResult).Changed || last.Line != "wg syncconf wg-falak /dev/stdin" || !strings.Contains(last.Stdin, "[Interface]") {
		t.Fatal(f.Lines())
	}
	// address change → restart
	p.Address = "10.90.0.4/24"
	f.Reset()
	n.WireGuardApply(context.Background(), p, st)
	if !f.Ran("systemctl restart wg-quick@wg-falak") {
		t.Fatal(f.Lines())
	}
	p.State = "absent"
	r, _ = n.WireGuardApply(context.Background(), p, st)
	if !r.(WireGuardResult).Changed || !f.Ran("systemctl disable --now wg-quick@wg-falak") {
		t.Fatal("absent")
	}
	if _, err := os.Stat(keyFile); err != nil {
		t.Fatal("key should be kept for identity stability")
	}
}

// A fresh Ubuntu server has no wireguard-tools (provisioning doesn't install them): the first apply installs them
// (apt-get update first) before it enables wg-quick@<interface>; once installed nothing is installed again.
func TestWireGuardInstallsTheToolsWhenMissing(t *testing.T) {
	root := t.TempDir()
	f := &runnertest.Fake{}
	f.On("systemctl is-active", runner.Result{ExitCode: 3})
	f.OnFunc("apt-get install", func(runnertest.Call) (runner.Result, error) {
		os.MkdirAll(filepath.Join(root, "usr/bin"), 0o755)
		os.WriteFile(filepath.Join(root, "usr/bin/wg-quick"), []byte("#!/bin/bash\n"), 0o755)
		return runner.Result{}, nil
	})
	n := New(Deps{Runner: f, FS: hostfs.FS{Root: root}})
	p := WireGuardPayload{Interface: "wg-a1b2c3d4", Address: "10.90.0.3/24"}
	if _, err := n.WireGuardApply(context.Background(), p, st); err != nil {
		t.Fatal(err)
	}
	lines := f.Lines()
	idx := func(prefix string) int {
		return slices.IndexFunc(lines, func(l string) bool { return strings.HasPrefix(l, prefix) })
	}
	inst, upd, enable := idx("apt-get install"), idx("apt-get update"), idx("systemctl enable --now wg-quick@wg-a1b2c3d4")
	if upd < 0 || inst < upd || enable < inst || !strings.Contains(lines[inst], "wireguard-tools") {
		t.Fatal(lines)
	}
	f.Reset()
	if _, err := n.WireGuardApply(context.Background(), p, st); err != nil {
		t.Fatal(err)
	}
	if idx := slices.IndexFunc(f.Lines(), func(l string) bool { return strings.HasPrefix(l, "apt-get") || strings.HasPrefix(l, "dpkg-query") }); idx >= 0 {
		t.Fatal(f.Lines())
	}

	// The install failing fails the command before anything is written.
	root2 := t.TempDir()
	f2 := &runnertest.Fake{}
	f2.On("apt-get install", runner.Result{ExitCode: 100, Stderr: []byte("E: Unable to locate package wireguard-tools")})
	n2 := New(Deps{Runner: f2, FS: hostfs.FS{Root: root2}})
	if _, err := n2.WireGuardApply(context.Background(), p, st); err == nil || !strings.Contains(err.Error(), "install wireguard-tools") {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(root2, "etc/wireguard/wg-a1b2c3d4.conf")); err == nil {
		t.Fatal("config written although the tools are missing")
	}
}

func TestPublicKeyMatchesWG(t *testing.T) {
	// Known vector (RFC 7748 §6.1 Alice).
	priv, _ := base64.StdEncoding.DecodeString("dwdtCnMYpX08FsFyUbJmRd9ML4frwJkqsXf7pR25LCo=")
	pub, err := PublicKey(priv)
	if err != nil || pub != "hSDwCYkwp1R0i33ctD73Wg2/Og0mOBr066SpjqqbTmo=" {
		t.Fatal(pub, err)
	}
	k, _ := GenerateKey()
	if k[0]&7 != 0 || k[31]&128 != 0 || k[31]&64 == 0 {
		t.Fatal("not clamped")
	}
}

func TestTunnelApplyInstallsRunsAndRemoves(t *testing.T) {
	bin := []byte("#!/bin/sh\necho cloudflared\n")
	sum := sha256.Sum256(bin)
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) { _, _ = w.Write(bin) }))
	defer srv.Close()
	root := t.TempDir()
	f := &runnertest.Fake{}
	n := New(Deps{Runner: f, FS: hostfs.FS{Root: root}, HTTP: srv.Client()})
	p := TunnelPayload{State: "present", Version: "2026.9.3", URL: srv.URL + "/cloudflared-linux-amd64", SHA256: hex.EncodeToString(sum[:]), Token: "tok-123"}

	r, err := n.TunnelApply(context.Background(), p, st)
	if err != nil || !r.(TunnelResult).Changed || !r.(TunnelResult).Active {
		t.Fatal(r, err)
	}
	token, _ := os.ReadFile(filepath.Join(root, TunnelTokenPath))
	info, _ := os.Stat(filepath.Join(root, TunnelTokenPath))
	unit, _ := os.ReadFile(filepath.Join(root, TunnelUnitPath))
	if string(token) != "tok-123" || info.Mode().Perm() != 0o600 || !strings.Contains(string(unit), "LoadCredential=token:"+TunnelTokenPath) || !strings.Contains(string(unit), "DynamicUser=yes") {
		t.Fatalf("token %q mode %v unit %s", token, info.Mode(), unit)
	}
	if want := "systemctl daemon-reload|systemctl enable falak-cloudflared.service|systemctl restart falak-cloudflared.service|systemctl is-active --quiet falak-cloudflared.service"; strings.Join(f.Lines(), "|") != want {
		t.Fatalf("%v", f.Lines())
	}

	// Same payload: nothing downloaded or restarted.
	f.Reset()
	r, _ = n.TunnelApply(context.Background(), p, st)
	if r.(TunnelResult).Changed || strings.Contains(strings.Join(f.Lines(), "|"), "restart") {
		t.Fatal("not idempotent", f.Lines())
	}

	// A wrong checksum is refused before anything is installed.
	if _, err := New(Deps{Runner: f, FS: hostfs.FS{Root: t.TempDir()}, HTTP: srv.Client()}).TunnelApply(context.Background(), TunnelPayload{State: "present", Version: p.Version, URL: p.URL, SHA256: strings.Repeat("0", 64), Token: "t"}, st); err == nil {
		t.Fatal("checksum mismatch accepted")
	}

	r, _ = n.TunnelApply(context.Background(), TunnelPayload{State: "absent"}, st)
	if !r.(TunnelResult).Changed {
		t.Fatal("absent did not remove")
	}
	for _, p := range []string{TunnelUnitPath, TunnelTokenPath, CloudflaredBinary} {
		if _, err := os.Stat(filepath.Join(root, p)); err == nil {
			t.Errorf("%s left behind", p)
		}
	}
}

// Too little time left for the install: fail before apt starts (a killed install leaves dpkg interrupted).
func TestWireGuardDoesNotStartAnInstallThatCannotFinish(t *testing.T) {
	f := &runnertest.Fake{}
	n := New(Deps{Runner: f, FS: hostfs.FS{Root: t.TempDir()}})
	ctx, cancel := context.WithTimeout(context.Background(), time.Minute)
	defer cancel()
	if _, err := n.WireGuardApply(ctx, WireGuardPayload{Interface: "wg-a1b2c3d4", Address: "10.90.0.3/24"}, st); err == nil || !strings.Contains(err.Error(), "retried with more time") {
		t.Fatal(err)
	}
	if len(f.Lines()) != 0 {
		t.Fatal(f.Lines())
	}
}
