package sandbox

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/netip"
	"os"
	"path/filepath"
	"sort"
	"strings"

	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// Network modes.
const (
	NetNone      = "none"
	NetInternet  = "internet"
	NetAllowlist = "allowlist"
)

// The sandboxes' bridge: every sandbox with a network has a veth on it, as an isolated bridge port (sandboxes can't
// reach each other at L2), and reaches the outside only through the FALAK-SBX chain.
const (
	bridge    = "br-sbx"
	subnet    = "10.88.0.0/16"
	gatewayIP = "10.88.0.1"
	proxyPort = "3128"
	chain     = "FALAK-SBX"
	natChain  = "FALAK-SBX-NAT"
	rawChain  = "FALAK-SBX-PRE"
	nftTable  = "falak_sbx"
)

// blocked are the destinations no sandbox reaches (cloud metadata, private, CGNAT, "this network", IETF protocol
// assignments, benchmarking and multicast ranges). The server's own addresses are dropped by the addrtype LOCAL rule
// (all of them, whatever interfaces it has).
var blocked = []string{"169.254.0.0/16", "10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16", "100.64.0.0/10", "0.0.0.0/8", "192.0.0.0/24",
	"198.18.0.0/15", "224.0.0.0/4"}

// netnsName and vethName are derived from the id's random tail (a ULID's first 10 characters are its timestamp);
// interface names are limited to 15 characters.
func netnsName(id string) string { return "falak-sbx-" + id[len(id)-12:] }
func vethName(id string) string  { return "sbx" + id[len(id)-12:] }

// allocator hands out sandbox addresses from the bridge's subnet, persisted in a JSON file (address → sandbox id).
type allocator struct {
	path        string
	first, last netip.Addr
}

func newAllocator(path string) *allocator {
	return &allocator{path: path, first: netip.MustParseAddr("10.88.0.2"), last: netip.MustParseAddr("10.88.255.254")}
}

func (a *allocator) load() (map[string]string, error) {
	m := map[string]string{}
	b, err := os.ReadFile(a.path)
	if errors.Is(err, os.ErrNotExist) {
		return m, nil
	}
	if err != nil {
		return nil, err
	}
	return m, json.Unmarshal(b, &m)
}

func (a *allocator) save(m map[string]string) error {
	b, _ := json.Marshal(m)
	if err := os.MkdirAll(filepath.Dir(a.path), 0o700); err != nil {
		return err
	}
	tmp := a.path + ".tmp"
	if err := os.WriteFile(tmp, b, 0o600); err != nil {
		return err
	}
	return os.Rename(tmp, a.path)
}

// Allocate returns id's address, the lowest free one when it has none yet. Callers serialise (Service.netMu).
// ponytail: linear scan of a /16, fine for the tens of sandboxes a server runs.
func (a *allocator) Allocate(id string) (string, error) {
	m, err := a.load()
	if err != nil {
		return "", err
	}
	for ip, owner := range m {
		if owner == id {
			return ip, nil
		}
	}
	for ip := a.first; ip.Compare(a.last) <= 0; ip = ip.Next() {
		if _, used := m[ip.String()]; !used {
			m[ip.String()] = id
			return ip.String(), a.save(m)
		}
	}
	return "", errors.New("no free sandbox address left in " + subnet)
}

// Release frees id's address (none: nothing to do).
func (a *allocator) Release(id string) error {
	m, err := a.load()
	if err != nil {
		return err
	}
	changed := false
	for ip, owner := range m {
		if owner == id {
			delete(m, ip)
			changed = true
		}
	}
	if !changed {
		return nil
	}
	return a.save(m)
}

func (s *Service) ip(ctx context.Context, args ...string) error {
	_, err := runner.Check(ctx, s.d.Runner, runner.Cmd{Name: "ip", Args: args})
	return err
}

// ensureBridge creates br-sbx with the gateway address (idempotent), with IPv6 off so sandboxes can't reach the host
// over link-local addresses, and turns on IPv4 forwarding.
func (s *Service) ensureBridge(ctx context.Context) error {
	if res, err := s.d.Runner.Run(ctx, runner.Cmd{Name: "ip", Args: []string{"link", "show", bridge}}); err != nil || res.ExitCode != 0 {
		if err := s.ip(ctx, "link", "add", bridge, "type", "bridge"); err != nil {
			return err
		}
	}
	for _, c := range []runner.Cmd{
		{Name: "sysctl", Args: []string{"-qw", "net.ipv6.conf." + bridge + ".disable_ipv6=1"}},
		{Name: "ip", Args: []string{"addr", "replace", gatewayIP + "/16", "dev", bridge}},
		{Name: "ip", Args: []string{"link", "set", bridge, "up"}},
		{Name: "sysctl", Args: []string{"-qw", "net.ipv4.ip_forward=1"}},
	} {
		if _, err := runner.Check(ctx, s.d.Runner, c); err != nil {
			return err
		}
	}
	return nil
}

// setupNetns creates a fresh namespace with a veth whose sandbox end is eth0 (runsc takes the address over at start
// and strips it from the namespace, so a namespace is never reused).
func (s *Service) setupNetns(ctx context.Context, id, addr string) error {
	ns, veth := netnsName(id), vethName(id)
	s.teardownNetns(ctx, id)
	for _, args := range netnsArgs(ns, veth, addr) {
		if err := s.ip(ctx, args...); err != nil {
			return err
		}
	}
	return nil
}

func netnsArgs(ns, veth, addr string) [][]string {
	return [][]string{
		{"netns", "add", ns},
		{"link", "add", veth, "type", "veth", "peer", "name", "eth0", "netns", ns},
		{"link", "set", veth, "master", bridge},
		{"link", "set", "dev", veth, "type", "bridge_slave", "isolated", "on"},
		{"link", "set", veth, "up"},
		{"-n", ns, "addr", "add", addr + "/16", "dev", "eth0"},
		{"-n", ns, "link", "set", "eth0", "up"},
		{"-n", ns, "link", "set", "lo", "up"},
		{"-n", ns, "route", "add", "default", "via", gatewayIP},
	}
}

// teardownNetns removes the namespace and the host end of the veth (both may already be gone).
func (s *Service) teardownNetns(ctx context.Context, id string) {
	_, _ = s.d.Runner.Run(ctx, runner.Cmd{Name: "ip", Args: []string{"netns", "del", netnsName(id)}})
	_, _ = s.d.Runner.Run(ctx, runner.Cmd{Name: "ip", Args: []string{"link", "del", vethName(id)}})
}

// rules renders the FALAK-SBX chains for iptables-restore --noflush: declaring a chain flushes it, so the whole set is
// replaced atomically and re-applying it is a no-op. internet lists the addresses of the sandboxes that may go out
// (with NAT); everything else from the bridge is dropped, apart from allowlist sandboxes' egress proxy.
func rules(internet []string) string {
	var b strings.Builder
	w := func(format string, a ...any) { fmt.Fprintf(&b, format+"\n", a...) }
	w("*filter")
	w(":%s - [0:0]", chain)
	w("-A %s -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT", chain)
	w("-A %s -i %s -d %s/32 -p tcp --dport %s -j ACCEPT", chain, bridge, gatewayIP, proxyPort)
	w("-A %s -i %s -m addrtype --dst-type LOCAL -j DROP", chain, bridge)
	w("-A %s -i %s -o %s -j DROP", chain, bridge, bridge)
	for _, n := range blocked {
		w("-A %s -d %s -j DROP", chain, n)
	}
	for _, ip := range internet {
		w("-A %s -i %s -s %s/32 -j ACCEPT", chain, bridge, ip)
	}
	w("-A %s -j DROP", chain)
	w("COMMIT")
	w("*nat")
	w(":%s - [0:0]", natChain)
	for _, ip := range internet {
		w("-A %s -s %s/32 ! -o %s -j MASQUERADE", natChain, ip, bridge)
	}
	w("COMMIT")
	// The guard in raw PREROUTING runs before anything Docker (or a firewall) puts in nat or filter, so their ACCEPTs
	// and DNATs (published ports, user networks) never see a sandbox's packets to the host or a private address.
	w("*raw")
	w(":%s - [0:0]", rawChain)
	w("-A %s -d %s/32 -p tcp --dport %s -j RETURN", rawChain, gatewayIP, proxyPort)
	w("-A %s -m addrtype --dst-type LOCAL -j DROP", rawChain)
	for _, n := range blocked {
		w("-A %s -d %s -j DROP", rawChain, n)
	}
	w("COMMIT")
	return b.String()
}

// jumps are the only rules outside the agent's chains, kept at the top of their chains (in this order): Docker
// inserts its own jumps at the top when it starts or creates a network, so they are re-asserted on every reaper tick.
var jumps = []struct {
	table, chain string
	rules        [][]string
}{
	{"filter", "FORWARD", [][]string{{"-i", bridge, "-j", chain}, {"-o", bridge, "-j", chain}}},
	{"filter", "INPUT", [][]string{{"-i", bridge, "-j", chain}}},
	{"nat", "POSTROUTING", [][]string{{"-s", subnet, "-j", natChain}}},
	{"raw", "PREROUTING", [][]string{{"-i", bridge, "-j", rawChain}}},
}

// applyRules replaces the chains' contents and moves the jumps to the top of their chains when they aren't there.
func (s *Service) applyRules(ctx context.Context, internet []string) error {
	if _, err := runner.Check(ctx, s.d.Runner, runner.Cmd{Name: "iptables-restore", Args: []string{"-w", "--noflush"},
		Stdin: strings.NewReader(rules(internet))}); err != nil {
		return err
	}
	for _, j := range jumps {
		res, err := runner.Check(ctx, s.d.Runner, runner.Cmd{Name: "iptables", Args: []string{"-w", "-t", j.table, "-S", j.chain}})
		if err != nil {
			return err
		}
		if jumpsOnTop(string(res.Stdout), j.chain, j.rules) {
			continue
		}
		for _, r := range j.rules {
			// Every copy goes (-D removes one per call); a failing -D means none is left.
			for range 16 {
				del := append([]string{"-w", "-t", j.table, "-D", j.chain}, r...)
				if res, err := s.d.Runner.Run(ctx, runner.Cmd{Name: "iptables", Args: del}); err != nil || res.ExitCode != 0 {
					break
				}
			}
		}
		for i := len(j.rules) - 1; i >= 0; i-- {
			insert := append([]string{"-w", "-t", j.table, "-I", j.chain, "1"}, j.rules[i]...)
			if _, err := runner.Check(ctx, s.d.Runner, runner.Cmd{Name: "iptables", Args: insert}); err != nil {
				return err
			}
		}
	}
	return nil
}

// jumpsOnTop reports whether the first rules of `iptables -S chain` are want, in order.
func jumpsOnTop(listing, chain string, want [][]string) bool {
	var top []string
	for _, line := range strings.Split(listing, "\n") {
		if r, ok := strings.CutPrefix(strings.TrimSpace(line), "-A "+chain+" "); ok {
			top = append(top, r)
		}
	}
	if len(top) < len(want) {
		return false
	}
	for i, r := range want {
		if top[i] != strings.Join(r, " ") {
			return false
		}
	}
	return true
}

// antiSpoof renders the bridge table that drops a sandbox's frames carrying another address than its own (so the
// per-address accepts and NAT in FALAK-SBX can trust the source) and anything that isn't IPv4 or ARP. sandboxes maps
// veth → address.
func antiSpoof(sandboxes map[string]string) string {
	var b strings.Builder
	fmt.Fprintf(&b, "table bridge %s {}\ndelete table bridge %s\ntable bridge %s {\n\tchain prerouting {\n", nftTable, nftTable, nftTable)
	b.WriteString("\t\ttype filter hook prerouting priority -300; policy accept;\n")
	b.WriteString("\t\tiifname \"sbx*\" ether type != { ip, arp } drop\n")
	veths := make([]string, 0, len(sandboxes))
	for v := range sandboxes {
		veths = append(veths, v)
	}
	sort.Strings(veths)
	for _, v := range veths {
		fmt.Fprintf(&b, "\t\tiifname \"%s\" ether type ip ip saddr != %s drop\n", v, sandboxes[v])
		fmt.Fprintf(&b, "\t\tiifname \"%s\" ether type arp arp saddr ip != %s drop\n", v, sandboxes[v])
	}
	b.WriteString("\t}\n}\n")
	return b.String()
}

func (s *Service) applyAntiSpoof(ctx context.Context, sandboxes map[string]string) error {
	_, err := runner.Check(ctx, s.d.Runner, runner.Cmd{Name: "nft", Args: []string{"-f", "-"}, Stdin: strings.NewReader(antiSpoof(sandboxes))})
	return err
}

// resolvConf is a sandbox's /etc/resolv.conf: public resolvers when it reaches the internet; allowlisted sandboxes
// resolve through the proxy (CONNECT host:port), and none has nothing to resolve.
func resolvConf(network string) string {
	if network == NetInternet {
		return "nameserver 1.1.1.1\nnameserver 8.8.8.8\n"
	}
	return "# no DNS in this sandbox (network: " + network + ")\n"
}

const hostsFile = "127.0.0.1\tlocalhost\n::1\tlocalhost ip6-localhost ip6-loopback\n127.0.1.1\tfalak-sbx\n"

// proxyEnv points an allowlisted sandbox's tools at the egress proxy.
func proxyEnv() []string {
	u := "http://" + gatewayIP + ":" + proxyPort
	return []string{"HTTP_PROXY=" + u, "HTTPS_PROXY=" + u, "http_proxy=" + u, "https_proxy=" + u,
		"NO_PROXY=localhost,127.0.0.1", "no_proxy=localhost,127.0.0.1"}
}
