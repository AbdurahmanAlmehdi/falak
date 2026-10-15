// Package provision implements provision.apply: a declarative host plan converged step by step.
package provision

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"strconv"
	"strings"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/provision/inspect"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runtime"
	"github.com/OthmanHaba/falak/agent/internal/system"
)

// Deps are the collaborators of provision.apply.
type Deps struct {
	Runner         runner.Runner
	FS             hostfs.FS
	Logger         *slog.Logger
	HTTP           *http.Client
	Arch           string
	FrankenPHPBase string // passed to runtime
	OndrejPPAURL   string // passed to runtime
	CaddyKeyURL    string // default https://dl.cloudsmith.io/public/caddy/stable/gpg.key
	CaddyRepoURL   string // default https://dl.cloudsmith.io/public/caddy/stable/deb/debian
	DockerRepoURL  string // default https://download.docker.com/linux (+ /<os id>)
}

// Provisioner runs provision.apply.
type Provisioner struct {
	d  Deps
	rt *runtime.Runtime
}

// New builds the provisioner.
func New(d Deps) *Provisioner {
	if d.Logger == nil {
		d.Logger = slog.Default()
	}
	if d.HTTP == nil {
		d.HTTP = http.DefaultClient
	}
	if d.CaddyKeyURL == "" {
		d.CaddyKeyURL = "https://dl.cloudsmith.io/public/caddy/stable/gpg.key"
	}
	if d.CaddyRepoURL == "" {
		d.CaddyRepoURL = "https://dl.cloudsmith.io/public/caddy/stable/deb/debian"
	}
	if d.DockerRepoURL == "" {
		d.DockerRepoURL = "https://download.docker.com/linux"
	}
	rt := runtime.New(runtime.Deps{Runner: d.Runner, FS: d.FS, Logger: d.Logger, HTTP: d.HTTP, Arch: d.Arch, FrankenPHPBase: d.FrankenPHPBase, OndrejPPAURL: d.OndrejPPAURL})
	return &Provisioner{d: d, rt: rt}
}

// Register adds provision.apply and provision.inspect.
func (p *Provisioner) Register(reg *commands.Registry) {
	reg.Register("provision.apply", commands.Typed(p.Apply))
	inspect.New(inspect.Deps{Runner: p.d.Runner, FS: p.d.FS, Logger: p.d.Logger}).Register(reg)
}

// Plan is the provision.apply payload.
type Plan struct {
	Hostname           string             `json:"hostname"`
	Timezone           string             `json:"timezone"`
	SwapMB             *int               `json:"swap_mb"`
	Apt                *AptPlan           `json:"apt"`
	Users              []system.UserSpec  `json:"users"`
	Runtimes           *Runtimes          `json:"runtimes"`
	Services           []Service          `json:"services"`
	UnattendedUpgrades *UnattendedUpgrade `json:"unattended_upgrades"`
	SSH                *SSH               `json:"ssh"`
	// Docker: daemon settings (every server runs Docker: database containers).
	Docker *DockerPlan `json:"docker"`
	// Components carries the control plane's machine-check decision per component (feature provision.v2).
	Components []Component `json:"components"`
}

// Component decisions (the control plane never sends "block": a blocked machine gets no plan).
const (
	DecisionInstall  = "install"
	DecisionAdopt    = "adopt"
	DecisionComplete = "complete"
)

// Component is one machine-check decision. An adopted component is already on the machine: its packages are never
// installed (they are verified instead) and its steps only check or enable what is there.
type Component struct {
	Name     string   `json:"name"`
	Decision string   `json:"decision"`
	Packages []string `json:"packages"`
	Service  string   `json:"service"`
}

// adopted returns the adopted components by name.
func (plan Plan) adopted() map[string]Component {
	out := map[string]Component{}
	for _, c := range plan.Components {
		if c.Decision == DecisionAdopt {
			out[c.Name] = c
		}
	}
	return out
}

// AptPlan lists packages.
type AptPlan struct {
	Packages []string `json:"packages"`
	Remove   []string `json:"remove"`
}

// Runtimes to install.
type Runtimes struct {
	PHP *struct {
		Versions   []string `json:"versions"`
		Default    string   `json:"default"`
		Extensions []string `json:"extensions"`
		FPM        *bool    `json:"fpm"`
	} `json:"php"`
	FrankenPHP *struct {
		Version string `json:"version"`
		SHA256  string `json:"sha256"`
		Mirror  string `json:"mirror"` // optional release download base (see runtime.frankenphp.configure)
	} `json:"frankenphp"`
	Node *struct {
		Versions []string `json:"versions"`
		Default  string   `json:"default"`
		Mirror   string   `json:"mirror"` // optional nodejs.org/dist mirror (see runtime.node.install)
	} `json:"node"`
	Caddy *struct {
		Enabled *bool  `json:"enabled"`
		Version string `json:"version"`
	} `json:"caddy"`
}

// Service desired state.
type Service struct {
	Name    string `json:"name"`
	Enabled *bool  `json:"enabled"`
	State   string `json:"state"`
}

// UnattendedUpgrade settings.
type UnattendedUpgrade struct {
	Enabled    *bool  `json:"enabled"`
	AutoReboot bool   `json:"auto_reboot"`
	RebootTime string `json:"reboot_time"`
}

// SSH hardening.
type SSH struct {
	Port                   int    `json:"port"`
	PermitRootLogin        string `json:"permit_root_login"`
	PasswordAuthentication bool   `json:"password_authentication"`
}

// Step is one step outcome.
type Step struct {
	Name    string `json:"name"`
	Changed bool   `json:"changed"`
	Error   string `json:"error,omitempty"`
}

// Result of provision.apply.
type Result struct {
	Changed bool   `json:"changed"`
	Steps   []Step `json:"steps"`
}

type step struct {
	name string
	fn   func(ctx context.Context, st commands.Stream) (bool, error)
}

func yes(b *bool) bool { return b == nil || *b }

// Apply converges the plan; failing steps are recorded and the remaining steps still run.
func (p *Provisioner) Apply(ctx context.Context, plan Plan, st commands.Stream) (any, error) {
	steps := p.steps(plan)
	res := Result{Steps: []Step{}}
	var failed []string
	for i, s := range steps {
		fmt.Fprintf(st.Stdout(), "==> %s\n", s.name)
		changed, err := s.fn(ctx, st)
		out := Step{Name: s.name, Changed: changed}
		if err != nil {
			out.Error = err.Error()
			failed = append(failed, s.name)
			fmt.Fprintf(st.Stderr(), "step %s failed: %v\n", s.name, err)
			if ctx.Err() != nil {
				res.Steps = append(res.Steps, out)
				return res, ctx.Err()
			}
		}
		res.Changed = res.Changed || changed
		res.Steps = append(res.Steps, out)
		st.Progress(float64(i+1) / float64(len(steps)))
	}
	if len(failed) > 0 {
		return res, fmt.Errorf("provisioning steps failed: %s", strings.Join(failed, ", "))
	}
	return res, nil
}

func (p *Provisioner) steps(plan Plan) []step {
	var s []step
	add := func(name string, fn func(context.Context, commands.Stream) (bool, error)) {
		s = append(s, step{name, fn})
	}
	adopted := plan.adopted()
	if _, keep := adopted["hostname"]; plan.Hostname != "" && !keep {
		add("hostname", func(ctx context.Context, st commands.Stream) (bool, error) { return p.hostname(ctx, st, plan.Hostname) })
	}
	if plan.Timezone != "" {
		add("timezone", func(ctx context.Context, st commands.Stream) (bool, error) { return p.timezone(ctx, st, plan.Timezone) })
	}
	if _, keep := adopted["swap"]; keep {
		add("swap", func(_ context.Context, st commands.Stream) (bool, error) {
			fmt.Fprintln(st.Stdout(), "keeping the machine's existing swap")
			return false, nil
		})
	} else if plan.SwapMB != nil && *plan.SwapMB > 0 {
		add("swap", func(ctx context.Context, st commands.Stream) (bool, error) { return p.swap(ctx, st, *plan.SwapMB) })
	}
	if plan.Apt != nil {
		add("apt", func(ctx context.Context, st commands.Stream) (bool, error) {
			a := system.AptFor(p.d.Runner, p.d.FS, st)
			inst, err := a.Ensure(ctx, withoutAdopted(plan.Apt.Packages, adopted), true)
			if err != nil {
				return len(inst) > 0, err
			}
			rm, err := a.Remove(ctx, withoutAdopted(plan.Apt.Remove, adopted))
			return len(inst)+len(rm) > 0, err
		})
	}
	if _, keep := adopted["docker"]; plan.Docker != nil && plan.Docker.MinVersion != "" && !keep {
		min := plan.Docker.MinVersion
		add("docker:engine", func(ctx context.Context, st commands.Stream) (bool, error) { return p.dockerEngine(ctx, st, min) })
	}
	for _, c := range plan.Components {
		if c.Decision != DecisionAdopt || len(c.Packages) == 0 {
			continue
		}
		c := c
		add("adopt:"+c.Name, func(ctx context.Context, st commands.Stream) (bool, error) { return false, p.verifyAdopted(ctx, st, c) })
	}
	for _, u := range plan.Users {
		u := u
		add("user:"+u.Name, func(ctx context.Context, st commands.Stream) (bool, error) {
			r, err := system.EnsureUser(ctx, p.d.Runner, p.d.FS, u, st)
			return r.Changed, err
		})
	}
	if rt := plan.Runtimes; rt != nil {
		if rt.PHP != nil {
			for _, v := range rt.PHP.Versions {
				v := v
				add("php:"+v, func(ctx context.Context, st commands.Stream) (bool, error) {
					r, err := p.rt.PHPInstall(ctx, runtime.PHPInstallPayload{Version: v, Extensions: rt.PHP.Extensions, FPM: rt.PHP.FPM, CLIDefault: v == rt.PHP.Default}, st)
					return changedOf(r), err
				})
			}
		}
		frankenEdge := rt.FrankenPHP != nil
		if rt.FrankenPHP != nil {
			add("frankenphp", func(ctx context.Context, st commands.Stream) (bool, error) {
				t := true
				r, err := p.rt.FrankenPHPConfigure(ctx, runtime.FrankenPHPPayload{Version: rt.FrankenPHP.Version, SHA256: rt.FrankenPHP.SHA256, Mirror: rt.FrankenPHP.Mirror, AsEdge: &t, EdgeGroups: siteGroups(plan.Users)}, st)
				return changedOf(r), err
			})
		}
		if rt.Node != nil {
			for _, v := range rt.Node.Versions {
				v := v
				add("node:"+v, func(ctx context.Context, st commands.Stream) (bool, error) {
					r, err := p.rt.NodeInstall(ctx, runtime.NodePayload{Version: v, Default: v == rt.Node.Default, Mirror: rt.Node.Mirror}, st)
					return changedOf(r), err
				})
			}
		}
		if rt.Caddy != nil && yes(rt.Caddy.Enabled) {
			add("caddy", func(ctx context.Context, st commands.Stream) (bool, error) {
				return p.caddy(ctx, st, rt.Caddy.Version, !frankenEdge)
			})
		}
	}
	for _, svc := range plan.Services {
		svc := svc
		add("service:"+svc.Name, func(ctx context.Context, st commands.Stream) (bool, error) { return p.service(ctx, st, svc) })
	}
	if plan.Docker != nil && plan.Docker.LiveRestore {
		add("docker:live-restore", func(ctx context.Context, st commands.Stream) (bool, error) { return p.dockerLiveRestore(ctx, st) })
	}
	if u := plan.UnattendedUpgrades; u != nil {
		if _, keep := adopted["unattended_upgrades"]; keep {
			add("unattended_upgrades", func(ctx context.Context, st commands.Stream) (bool, error) {
				return false, p.verifyAdopted(ctx, st, Component{Name: "unattended_upgrades", Packages: []string{"unattended-upgrades"}})
			})
		} else {
			add("unattended_upgrades", func(ctx context.Context, st commands.Stream) (bool, error) { return p.unattended(ctx, st, *u) })
		}
	}
	if plan.SSH != nil {
		add("ssh", func(ctx context.Context, st commands.Stream) (bool, error) { return p.ssh(ctx, st, *plan.SSH) })
	}
	return s
}

// withoutAdopted drops the packages of adopted components: the machine already has them from another source, and
// asking apt for them could pull a conflicting package family in.
func withoutAdopted(pkgs []string, adopted map[string]Component) []string {
	skip := map[string]bool{}
	for _, c := range adopted {
		for _, n := range c.Packages {
			name, _ := system.SplitPin(n)
			skip[name] = true
		}
	}
	var out []string
	for _, n := range pkgs {
		if name, _ := system.SplitPin(n); !skip[name] {
			out = append(out, n)
		}
	}
	return out
}

// verifyAdopted checks that an adopted component's packages are still installed; it never installs them.
func (p *Provisioner) verifyAdopted(ctx context.Context, st commands.Stream, c Component) error {
	miss, err := system.AptFor(p.d.Runner, p.d.FS, st).Missing(ctx, c.Packages)
	if err != nil {
		return err
	}
	if len(miss) > 0 {
		return fmt.Errorf("%s was adopted from the machine, but %s is no longer installed; run the machine check again", c.Name, strings.Join(miss, ", "))
	}
	fmt.Fprintf(st.Stdout(), "using the machine's %s (%s)\n", c.Name, strings.Join(c.Packages, ", "))
	return nil
}

func changedOf(r any) bool {
	switch v := r.(type) {
	case runtime.PHPInstallResult:
		return v.Changed
	case runtime.BinaryResult:
		return v.Changed
	case runtime.NodeResult:
		return v.Changed
	}
	return false
}

// siteGroups are the primary groups of the plan's regular (non-system) users: the edge user joins them
// so FrankenPHP can read their sites' .env and write storage/.
func siteGroups(users []system.UserSpec) []string {
	var gs []string
	for _, u := range users {
		if !u.System {
			gs = append(gs, u.Name)
		}
	}
	return gs
}

func (p *Provisioner) run(ctx context.Context, st commands.Stream, name string, args ...string) error {
	_, err := runner.Check(ctx, p.d.Runner, runner.Cmd{Name: name, Args: args, Stdout: st.Stdout(), Stderr: st.Stderr()})
	return err
}

func (p *Provisioner) status(ctx context.Context, args ...string) (bool, error) {
	r, err := p.d.Runner.Run(ctx, runner.Cmd{Name: "systemctl", Args: args})
	return err == nil && r.ExitCode == 0, err
}

// inContainer reports whether the host is a container (Docker, LXC, systemd-nspawn...), where the
// hostname is owned by the runtime and swap cannot be enabled.
func (p *Provisioner) inContainer(ctx context.Context) bool {
	r, err := p.d.Runner.Run(ctx, runner.Cmd{Name: "systemd-detect-virt", Args: []string{"--container", "--quiet"}})
	return err == nil && r.ExitCode == 0
}

func (p *Provisioner) hostname(ctx context.Context, st commands.Stream, h string) (bool, error) {
	if b, err := p.d.FS.ReadFile("/etc/hostname"); err == nil && strings.TrimSpace(string(b)) == h {
		return false, nil
	}
	if err := p.run(ctx, st, "hostnamectl", "set-hostname", h); err != nil {
		if p.inContainer(ctx) {
			fmt.Fprintf(st.Stdout(), "hostname is managed by the container runtime; skipping (%v)\n", err)
			return false, nil
		}
		return true, err
	}
	return true, nil
}

func (p *Provisioner) timezone(ctx context.Context, st commands.Stream, tz string) (bool, error) {
	if l, err := os.Readlink(p.d.FS.P("/etc/localtime")); err == nil && strings.HasSuffix(l, "/zoneinfo/"+tz) {
		return false, nil
	}
	return true, p.run(ctx, st, "timedatectl", "set-timezone", tz)
}

const swapFile = "/swapfile"

func (p *Provisioner) swap(ctx context.Context, st commands.Stream, mb int) (bool, error) {
	if p.inContainer(ctx) {
		fmt.Fprintln(st.Stdout(), "swap cannot be enabled inside a container; skipping")
		return false, nil
	}
	want := int64(mb) << 20
	active := false
	if b, err := p.d.FS.ReadFile("/proc/swaps"); err == nil {
		for _, l := range strings.Split(string(b), "\n") {
			if f := strings.Fields(l); len(f) > 0 && f[0] == swapFile {
				active = true
			}
		}
	}
	fi, err := os.Stat(p.d.FS.P(swapFile))
	sizeOK := err == nil && fi.Size() == want
	changed := false
	if !sizeOK {
		if active {
			if err := p.run(ctx, st, "swapoff", swapFile); err != nil {
				return false, err
			}
			active = false
		}
		p.d.FS.Remove(swapFile)
		for _, c := range [][]string{
			{"fallocate", "-l", strconv.Itoa(mb) + "M", swapFile},
			{"chmod", "600", swapFile},
			{"mkswap", swapFile},
		} {
			if err := p.run(ctx, st, c[0], c[1:]...); err != nil {
				return true, err
			}
		}
		changed = true
	}
	if !active {
		if err := p.run(ctx, st, "swapon", swapFile); err != nil {
			return true, err
		}
		changed = true
	}
	fstab, _ := p.d.FS.ReadFile("/etc/fstab")
	if !strings.Contains(string(fstab), swapFile+" ") {
		s := string(fstab)
		if s != "" && !strings.HasSuffix(s, "\n") {
			s += "\n"
		}
		s += swapFile + " none swap sw 0 0\n"
		if _, err := p.d.FS.WriteFile("/etc/fstab", []byte(s), 0o644); err != nil {
			return true, err
		}
		changed = true
	}
	return changed, nil
}

const (
	caddyKeyring = "/etc/apt/keyrings/caddy-stable.asc"
	caddyList    = "/etc/apt/sources.list.d/caddy-stable.list"
)

func (p *Provisioner) caddy(ctx context.Context, st commands.Stream, version string, asEdge bool) (bool, error) {
	changed := false
	if !p.d.FS.Exists(caddyKeyring) {
		if err := p.d.FS.MkdirAll("/etc/apt/keyrings", 0o755); err != nil {
			return false, err
		}
		if _, _, err := system.Download(ctx, p.d.HTTP, p.d.CaddyKeyURL, "", p.d.FS.P(caddyKeyring), 0o644, nil); err != nil {
			return false, err
		}
		changed = true
	}
	list := fmt.Sprintf("# Managed by Falak\ndeb [signed-by=%s] %s any-version main\n", caddyKeyring, p.d.CaddyRepoURL)
	c, err := p.d.FS.WriteFile(caddyList, []byte(list), 0o644)
	if err != nil {
		return changed, err
	}
	a := system.AptFor(p.d.Runner, p.d.FS, st)
	if c {
		changed = true
		if err := a.Update(ctx); err != nil {
			return changed, err
		}
	}
	pkg := "caddy"
	if version != "" {
		pkg += "=" + strings.TrimPrefix(version, "v")
	}
	inst, err := a.Ensure(ctx, []string{pkg}, !c)
	if err != nil {
		return changed, err
	}
	changed = changed || len(inst) > 0
	if asEdge {
		// A server whose edge runs Falak's Caddy build (rate limits) keeps it: stock Caddy cannot load that config.
		ec, err := runtime.EnsureEdgeUnit(ctx, p.d.Runner, p.d.FS, st, runtime.EdgeUnit{Binary: runtime.CaddyBinary(p.d.FS)}, len(inst) > 0)
		return changed || ec, err
	}
	return changed, nil
}

func (p *Provisioner) service(ctx context.Context, st commands.Stream, s Service) (bool, error) {
	changed := false
	enabled, err := p.status(ctx, "is-enabled", "--quiet", s.Name)
	if err != nil {
		return false, err
	}
	if yes(s.Enabled) != enabled {
		verb := "enable"
		if !yes(s.Enabled) {
			verb = "disable"
		}
		if err := p.run(ctx, st, "systemctl", verb, s.Name); err != nil {
			return false, err
		}
		changed = true
	}
	active, err := p.status(ctx, "is-active", "--quiet", s.Name)
	if err != nil {
		return changed, err
	}
	switch s.State {
	case "", "started":
		if !active {
			return true, p.run(ctx, st, "systemctl", "start", s.Name)
		}
	case "stopped":
		if active {
			return true, p.run(ctx, st, "systemctl", "stop", s.Name)
		}
	case "restarted":
		return true, p.run(ctx, st, "systemctl", "restart", s.Name)
	default:
		return changed, fmt.Errorf("unknown service state %q", s.State)
	}
	return changed, nil
}

func (p *Provisioner) unattended(ctx context.Context, st commands.Stream, u UnattendedUpgrade) (bool, error) {
	on := yes(u.Enabled)
	changed := false
	if on {
		inst, err := system.AptFor(p.d.Runner, p.d.FS, st).Ensure(ctx, []string{"unattended-upgrades"}, true)
		if err != nil {
			return false, err
		}
		changed = len(inst) > 0
	}
	flag := "0"
	if on {
		flag = "1"
	}
	periodic := fmt.Sprintf("// Managed by Falak\nAPT::Periodic::Update-Package-Lists \"%s\";\nAPT::Periodic::Unattended-Upgrade \"%s\";\n", flag, flag)
	rt := u.RebootTime
	if rt == "" {
		rt = "04:00"
	}
	cfg := fmt.Sprintf("// Managed by Falak\nUnattended-Upgrade::Automatic-Reboot \"%t\";\nUnattended-Upgrade::Automatic-Reboot-Time \"%s\";\nUnattended-Upgrade::Remove-Unused-Kernel-Packages \"true\";\n", u.AutoReboot, rt)
	for f, c := range map[string]string{"/etc/apt/apt.conf.d/20auto-upgrades": periodic, "/etc/apt/apt.conf.d/52falak-unattended": cfg} {
		ch, err := p.d.FS.WriteFile(f, []byte(c), 0o644)
		if err != nil {
			return changed, err
		}
		changed = changed || ch
	}
	return changed, nil
}

const sshdDropIn = "/etc/ssh/sshd_config.d/50-falak.conf"

// RenderSSHD renders the sshd drop-in.
func RenderSSHD(s SSH) string {
	port := s.Port
	if port == 0 {
		port = 22
	}
	prl := s.PermitRootLogin
	if prl == "" {
		prl = "prohibit-password"
	}
	pa := "no"
	if s.PasswordAuthentication {
		pa = "yes"
	}
	return fmt.Sprintf("# Managed by Falak\nPort %d\nPermitRootLogin %s\nPasswordAuthentication %s\nKbdInteractiveAuthentication no\nPubkeyAuthentication yes\nX11Forwarding no\nMaxAuthTries 4\n", port, prl, pa)
}

func (p *Provisioner) ssh(ctx context.Context, st commands.Stream, s SSH) (bool, error) {
	old, oldErr := p.d.FS.ReadFile(sshdDropIn)
	changed, err := p.d.FS.WriteFile(sshdDropIn, []byte(RenderSSHD(s)), 0o644)
	if err != nil {
		return changed, err
	}
	// sshd -t needs its privilege separation dir, which Ubuntu's socket-activated ssh only creates
	// once the service has run (fresh 24.04 hosts); Debian's init script pre-creates it the same way.
	if err := p.d.FS.MkdirAll("/run/sshd", 0o755); err != nil {
		return false, err
	}
	// Minimal images / LXC templates can ship without host keys; -A only creates the missing ones.
	if err := p.run(ctx, st, "ssh-keygen", "-A"); err != nil {
		return false, err
	}
	if changed {
		if err := p.run(ctx, st, "sshd", "-t"); err != nil {
			if oldErr != nil {
				p.d.FS.Remove(sshdDropIn)
			} else {
				p.d.FS.WriteFile(sshdDropIn, old, 0o644)
			}
			return false, errors.Join(errors.New("sshd -t rejected the config; previous config restored"), err)
		}
	}
	started, err := p.ensureSSH(ctx, st, changed)
	return changed || started, err
}

// ensureSSH keeps SSH reachable after every converge: the agent must never leave a server without
// SSH (package upgrades can stop sshd). A changed config is applied; a stopped sshd is started.
// Ubuntu ≥ 22.10 uses socket activation, where the listening port comes from ssh.socket via a
// generator and ssh.service must not be started alongside it.
func (p *Provisioner) ensureSSH(ctx context.Context, st commands.Stream, apply bool) (bool, error) {
	unit := "ssh.service"
	if sock, _ := p.status(ctx, "is-enabled", "--quiet", "ssh.socket"); sock {
		unit = "ssh.socket"
	}
	if apply {
		if unit == "ssh.socket" {
			if err := p.run(ctx, st, "systemctl", "daemon-reload"); err != nil {
				return true, err
			}
			return true, p.run(ctx, st, "systemctl", "restart", unit)
		}
		return true, p.run(ctx, st, "systemctl", "reload-or-restart", unit)
	}
	if active, _ := p.status(ctx, "is-active", "--quiet", unit); active {
		return false, nil
	}
	fmt.Fprintf(st.Stdout(), "%s was not running; starting it\n", unit)
	return true, p.run(ctx, st, "systemctl", "start", unit)
}
