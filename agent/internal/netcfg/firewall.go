// Package netcfg implements net.firewall.apply (nftables table inet falak) and net.wireguard.apply.
package netcfg

import (
	"context"
	"fmt"
	"log/slog"
	"net"
	"net/http"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/localedge"
	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// Deps are the collaborators of the net executors.
type Deps struct {
	Runner runner.Runner
	FS     hostfs.FS
	Logger *slog.Logger
	HTTP   *http.Client // downloads (cloudflared); nil = a default client
}

// Net holds the executors.
type Net struct{ d Deps }

// New builds net executors.
func New(d Deps) *Net {
	if d.Logger == nil {
		d.Logger = slog.Default()
	}
	if d.HTTP == nil {
		d.HTTP = &http.Client{Timeout: 10 * time.Minute}
	}
	return &Net{d: d}
}

// Register adds net.* executors.
func (n *Net) Register(reg *commands.Registry) {
	reg.Register("net.firewall.apply", commands.Typed(n.FirewallApply))
	reg.Register("net.wireguard.apply", commands.Typed(n.WireGuardApply))
	reg.Register("net.tunnel.apply", commands.Typed(n.TunnelApply))
}

// Rule is one firewall rule.
type Rule struct {
	ID        string   `json:"id"`
	Action    string   `json:"action"`
	Protocol  string   `json:"protocol"`
	Ports     []string `json:"ports"`
	Sources   []string `json:"sources"`
	Interface string   `json:"interface"`
	Comment   string   `json:"comment"`
}

// FirewallPayload is net.firewall.apply.
type FirewallPayload struct {
	InputPolicy string `json:"input_policy"`
	SSHPort     int    `json:"ssh_port"`
	AllowICMP   *bool  `json:"allow_icmp"`
	Rules       []Rule `json:"rules"`
	// Counters puts an nftables counter on the SSH rule and every rule (feature net.protection): read back by the
	// protection observer as falak.net.firewall.* and shown next to each rule.
	Counters bool `json:"counters,omitempty"`
	// LocalEdge is this host's /etc/falak/local-edge.env (set by the agent, never sent): the control plane's edge
	// reaches this server's edge on its internal ports.
	LocalEdge localedge.Config `json:"-"`
}

// FirewallResult is its result.
type FirewallResult struct {
	Changed       bool   `json:"changed"`
	RulesetSHA256 string `json:"ruleset_sha256"`
}

// Paths.
const (
	RulesetPath = "/etc/falak/nftables.conf"
	UnitPath    = "/etc/systemd/system/falak-firewall.service"
)

var (
	idRe    = regexp.MustCompile(`^[A-Za-z0-9_-]+$`)
	portRe  = regexp.MustCompile(`^([0-9]{1,5})(?:-([0-9]{1,5}))?$`)
	ifaceRe = regexp.MustCompile(`^[A-Za-z0-9_.-]+$`)
)

func perr(format string, a ...any) error {
	return &commands.PayloadError{Err: fmt.Errorf(format, a...)}
}

// RenderRuleset renders the complete, deterministic nftables script for table inet falak.
// The "declare empty, delete, redefine" preamble makes `nft -f` an atomic full replacement that works
// whether or not the table exists, and never touches other tables (Docker's, distro defaults).
func RenderRuleset(p FirewallPayload) (string, error) {
	policy := p.InputPolicy
	if policy == "" {
		policy = "drop"
	}
	if policy != "drop" && policy != "accept" {
		return "", perr("invalid input_policy %q", policy)
	}
	ssh := p.SSHPort
	if ssh == 0 {
		ssh = 22
	}
	if ssh < 1 || ssh > 65535 {
		return "", perr("invalid ssh_port %d", ssh)
	}
	var b strings.Builder
	b.WriteString("#!/usr/sbin/nft -f\n# Managed by Falak (net.firewall.apply) — do not edit\n")
	b.WriteString("table inet falak {}\ndelete table inet falak\n\ntable inet falak {\n\tchain input {\n")
	fmt.Fprintf(&b, "\t\ttype filter hook input priority filter; policy %s;\n", policy)
	b.WriteString("\t\tct state established,related accept\n\t\tct state invalid drop\n\t\tiif \"lo\" accept\n")
	b.WriteString("\t\ticmpv6 type { nd-neighbor-solicit, nd-neighbor-advert, nd-router-solicit, nd-router-advert } accept\n")
	if p.AllowICMP == nil || *p.AllowICMP {
		b.WriteString("\t\tmeta l4proto { icmp, ipv6-icmp } accept\n")
	}
	counter := ""
	if p.Counters {
		counter = "counter "
	}
	fmt.Fprintf(&b, "\t\ttcp dport %d %saccept comment \"falak:ssh\"\n", ssh, counter)
	// Sandboxes with an allowlist reach the internet only through the agent's egress proxy on their bridge's gateway.
	fmt.Fprintf(&b, "\t\tiifname \"br-sbx\" ip daddr 10.88.0.1 tcp dport 3128 %saccept comment \"falak:sandbox-proxy\"\n", counter)
	if l := p.LocalEdge; l.Enabled {
		// Only the control plane's edge network may reach the edge's internal ports (it sends the PROXY header).
		fmt.Fprintf(&b, "\t\tip saddr %s ip daddr %s tcp dport { %d, %d } %saccept comment \"falak:local-edge\"\n", l.Trust, l.Addr, l.HTTPPort, l.HTTPSPort, counter)
	}
	seen := map[string]bool{}
	for _, r := range p.Rules {
		if !idRe.MatchString(r.ID) {
			return "", perr("invalid rule id %q", r.ID)
		}
		if seen[r.ID] {
			return "", perr("duplicate rule id %q", r.ID)
		}
		seen[r.ID] = true
		lines, err := renderRule(r, p.Counters)
		if err != nil {
			return "", err
		}
		for _, l := range lines {
			b.WriteString("\t\t" + l + "\n")
		}
	}
	b.WriteString("\t}\n")
	if l := p.LocalEdge; l.Enabled && (l.Panel != "" || len(l.Protect) > 0) {
		// The control-plane machine: host processes other than root (tenant sites run as their site users) reach the
		// panel through its edge only, never the control plane's own containers (database, Redis, the app). The agent
		// (root) is unaffected; containers are kept off those networks by Docker's bridge isolation (forward path).
		b.WriteString("\tchain output {\n\t\ttype filter hook output priority filter; policy accept;\n")
		if l.Panel != "" {
			fmt.Fprintf(&b, "\t\tmeta skuid != 0 ip daddr %s tcp dport { 80, 443 } counter accept comment \"falak:protect-cp\"\n", l.Panel)
		}
		if len(l.Protect) > 0 {
			fmt.Fprintf(&b, "\t\tmeta skuid != 0 ip daddr %s counter drop comment \"falak:protect-cp\"\n", set(l.Protect))
		}
		b.WriteString("\t}\n")
	}
	b.WriteString("}\n")
	return b.String(), nil
}

func renderRule(r Rule, counters bool) ([]string, error) {
	action := r.Action
	if action == "" {
		action = "accept"
	}
	if action != "accept" && action != "drop" && action != "reject" {
		return nil, perr("rule %s: invalid action %q", r.ID, action)
	}
	proto := r.Protocol
	if proto == "" {
		proto = "tcp"
	}
	var ports []string
	for _, p := range r.Ports {
		m := portRe.FindStringSubmatch(p)
		if m == nil {
			return nil, perr("rule %s: invalid port %q", r.ID, p)
		}
		lo, _ := strconv.Atoi(m[1])
		hi := lo
		if m[2] != "" {
			hi, _ = strconv.Atoi(m[2])
		}
		if lo < 1 || hi > 65535 || lo > hi {
			return nil, perr("rule %s: invalid port %q", r.ID, p)
		}
		ports = append(ports, p)
	}
	var l4 string
	switch proto {
	case "tcp", "udp":
		l4 = proto
		if len(ports) > 0 {
			l4 += " dport " + set(ports)
		} else {
			l4 = "meta l4proto " + proto
		}
	case "any":
		if len(ports) > 0 {
			l4 = "meta l4proto { tcp, udp } th dport " + set(ports)
		}
	default:
		return nil, perr("rule %s: invalid protocol %q", r.ID, proto)
	}
	var v4, v6 []string
	for _, s := range r.Sources {
		ip, _, err := net.ParseCIDR(s)
		if err != nil {
			ip = net.ParseIP(s)
			if ip == nil {
				return nil, perr("rule %s: invalid source %q", r.ID, s)
			}
		}
		if ip.To4() != nil {
			v4 = append(v4, s)
		} else {
			v6 = append(v6, s)
		}
	}
	var prefix string
	if r.Interface != "" {
		if !ifaceRe.MatchString(r.Interface) {
			return nil, perr("rule %s: invalid interface %q", r.ID, r.Interface)
		}
		prefix = "iifname \"" + r.Interface + "\" "
	}
	comment := "falak:" + r.ID
	if c := strings.Map(func(c rune) rune {
		if c == '"' || c == '\\' || c < 0x20 {
			return -1
		}
		return c
	}, r.Comment); c != "" {
		comment += " " + c
	}
	if len(comment) > 120 {
		comment = comment[:120]
	}
	if counters {
		action = "counter " + action
	}
	tail := strings.TrimSpace(l4+" "+action) + " comment \"" + comment + "\""
	var out []string
	if len(v4) == 0 && len(v6) == 0 {
		return []string{prefix + tail}, nil
	}
	if len(v4) > 0 {
		out = append(out, prefix+"ip saddr "+set(v4)+" "+tail)
	}
	if len(v6) > 0 {
		out = append(out, prefix+"ip6 saddr "+set(v6)+" "+tail)
	}
	return out, nil
}

func set(items []string) string {
	if len(items) == 1 {
		return items[0]
	}
	return "{ " + strings.Join(items, ", ") + " }"
}

// FirewallUnit persists the ruleset across reboots.
const FirewallUnit = `# Managed by Falak
[Unit]
Description=Falak firewall (nftables table inet falak)
DefaultDependencies=no
Wants=network-pre.target
Before=network-pre.target shutdown.target
After=local-fs.target
Conflicts=shutdown.target

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/usr/sbin/nft -f /etc/falak/nftables.conf
ExecReload=/usr/sbin/nft -f /etc/falak/nftables.conf

[Install]
WantedBy=sysinit.target
`

// FirewallApply validates, persists and atomically applies the ruleset.
func (n *Net) FirewallApply(ctx context.Context, p FirewallPayload, st commands.Stream) (any, error) {
	local, err := localedge.Load(n.d.FS, localedge.Path)
	if err != nil {
		return nil, err
	}
	p.LocalEdge = local
	rs, err := RenderRuleset(p)
	if err != nil {
		return nil, err
	}
	res := FirewallResult{RulesetSHA256: hostfs.SHA256([]byte(rs))}
	run := func(args ...string) error {
		_, err := runner.Check(ctx, n.d.Runner, runner.Cmd{Name: "nft", Args: args, Stdout: st.Stdout(), Stderr: st.Stderr()})
		return err
	}
	cur, _ := n.d.FS.ReadFile(RulesetPath)
	live, err := n.d.Runner.Run(ctx, runner.Cmd{Name: "nft", Args: []string{"list", "table", "inet", "falak"}})
	if err != nil {
		return nil, err
	}
	if string(cur) != rs || live.ExitCode != 0 {
		tmp := RulesetPath + ".new"
		if _, err := n.d.FS.WriteFile(tmp, []byte(rs), 0o600); err != nil {
			return nil, err
		}
		if err := run("-c", "-f", n.d.FS.P(tmp)); err != nil {
			n.d.FS.Remove(tmp)
			return nil, fmt.Errorf("nftables validation failed (existing ruleset untouched): %w", err)
		}
		if _, err := n.d.FS.WriteFile(RulesetPath, []byte(rs), 0o600); err != nil {
			return nil, err
		}
		n.d.FS.Remove(tmp)
		if err := run("-f", n.d.FS.P(RulesetPath)); err != nil {
			return nil, err
		}
		res.Changed = true
	}
	unitChanged, err := n.d.FS.WriteFile(UnitPath, []byte(FirewallUnit), 0o644)
	if err != nil {
		return nil, err
	}
	if unitChanged {
		for _, a := range [][]string{{"daemon-reload"}, {"enable", "falak-firewall.service"}} {
			if _, err := runner.Check(ctx, n.d.Runner, runner.Cmd{Name: "systemctl", Args: a, Stdout: st.Stdout(), Stderr: st.Stderr()}); err != nil {
				return nil, err
			}
		}
		res.Changed = true
	}
	return res, nil
}
