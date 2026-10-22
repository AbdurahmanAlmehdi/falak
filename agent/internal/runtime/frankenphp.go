package runtime

import (
	"context"
	"fmt"
	"sort"
	"strings"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/localedge"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/system"
)

// FrankenPHPPayload is runtime.frankenphp.configure.
type FrankenPHPPayload struct {
	Version string         `json:"version"`
	SHA256  string         `json:"sha256"`
	INI     map[string]any `json:"ini"`
	AsEdge  *bool          `json:"as_edge"`
	// Mirror replaces the release download base (default https://github.com/php/frankenphp/releases/download):
	// <mirror>/v<version>/frankenphp-linux-<arch>-gnu.
	Mirror string `json:"mirror"`
	// EdgeGroups are extra groups for the edge user (set by provisioning, not the wire payload): PHP runs
	// as the edge user under FrankenPHP and must read site .env files and write storage/.
	EdgeGroups []string `json:"-"`
}

// BinaryResult is {changed, binary}.
type BinaryResult struct {
	Changed bool   `json:"changed"`
	Binary  string `json:"binary"`
}

// Paths shared with the edge package.
const (
	FrankenPHPBinary = "/usr/local/bin/frankenphp"
	EdgeUnitPath     = "/etc/systemd/system/falak-edge.service"
	EdgeBootstrap    = "/etc/falak/caddy/bootstrap.json"
	frankenMarker    = "/etc/falak/frankenphp.version"
)

func frankenArch(a string) (string, error) {
	switch a {
	case "amd64":
		return "x86_64", nil
	case "arm64":
		return "aarch64", nil
	}
	return "", fmt.Errorf("unsupported arch %s", a)
}

// FrankenPHPConfigure installs the static FrankenPHP binary, php.ini and (optionally) falak-edge.service.
func (rt *Runtime) FrankenPHPConfigure(ctx context.Context, p FrankenPHPPayload, st commands.Stream) (any, error) {
	ver := strings.TrimPrefix(p.Version, "v")
	if !semver.MatchString(ver) {
		return nil, &commands.PayloadError{Err: fmt.Errorf("invalid version %q", p.Version)}
	}
	arch, err := frankenArch(rt.d.Arch)
	if err != nil {
		return nil, err
	}
	res := BinaryResult{Binary: FrankenPHPBinary}
	marker := []byte(ver + " " + p.SHA256 + "\n")
	cur, _ := rt.d.FS.ReadFile(frankenMarker)
	binOK := rt.d.FS.Exists(FrankenPHPBinary) && string(cur) == string(marker)
	if binOK && p.SHA256 != "" {
		if sum, err := system.FileSHA256(rt.d.FS.P(FrankenPHPBinary)); err != nil || sum != p.SHA256 {
			binOK = false
		}
	}
	binChanged := false
	if !binOK {
		base := p.Mirror
		if base == "" {
			base = rt.d.FrankenPHPBase
		}
		// The glibc build (needs glibc >= 2.28): 1.13.2's musl build dies with SIGILL on CPUs without SHA-NI
		// (php/frankenphp#2743).
		url := fmt.Sprintf("%s/v%s/frankenphp-linux-%s-gnu", strings.TrimRight(base, "/"), ver, arch)
		fmt.Fprintf(st.Stdout(), "downloading %s\n", url)
		if _, _, err := Download(ctx, rt.d.HTTP, url, p.SHA256, rt.d.FS.P(FrankenPHPBinary), 0o755, nil); err != nil {
			return nil, err
		}
		if _, err := rt.d.FS.WriteFile(frankenMarker, marker, 0o644); err != nil {
			return nil, err
		}
		binChanged = true
	}
	iniChanged, err := rt.d.FS.WriteFile("/etc/frankenphp/php.ini", RenderINI(p.INI), 0o644)
	if err != nil {
		return nil, err
	}
	res.Changed = binChanged || iniChanged
	if p.AsEdge == nil || *p.AsEdge {
		c, err := EnsureEdgeUnit(ctx, rt.d.Runner, rt.d.FS, st, EdgeUnit{Binary: FrankenPHPBinary, User: rt.d.EdgeUser, FrankenPHP: true, Groups: p.EdgeGroups}, binChanged || iniChanged)
		if err != nil {
			return nil, err
		}
		res.Changed = res.Changed || c
	}
	return res, nil
}

// EdgeUnit describes falak-edge.service.
type EdgeUnit struct {
	Binary     string // /usr/bin/caddy or /usr/local/bin/frankenphp
	User       string // default caddy
	FrankenPHP bool
	Groups     []string // SupplementaryGroups (site users' groups)
	// SkipValidate switches the binary without validating the config with it (a rollback to the binary that ran it).
	SkipValidate bool `json:"skip_validate,omitempty"`
	// Slice is falak.slice on the control-plane machine (set by EnsureEdgeUnit from local-edge.env): the edge serves
	// the sites' traffic (FrankenPHP runs their PHP), so it is capped with them, away from the panel.
	Slice string `json:"-"`
}

// RenderEdgeUnit renders the systemd unit.
func RenderEdgeUnit(u EdgeUnit) string {
	if u.User == "" {
		u.User = "caddy"
	}
	var env string
	if u.FrankenPHP {
		env = "Environment=PHPRC=/etc/frankenphp\n"
	}
	if len(u.Groups) > 0 {
		env += "SupplementaryGroups=" + strings.Join(u.Groups, " ") + "\n"
	}
	if u.Slice != "" {
		env += "Slice=" + u.Slice + "\n"
	}
	return fmt.Sprintf(`# Managed by Falak
[Unit]
Description=Falak edge (Caddy/FrankenPHP)
Documentation=https://caddyserver.com/docs/
After=network-online.target
Wants=network-online.target

[Service]
Type=notify
User=%[1]s
Group=%[1]s
Environment=XDG_DATA_HOME=/var/lib/caddy
Environment=XDG_CONFIG_HOME=/var/lib/caddy/.config
# DNS provider tokens (root only), referenced from the config as {env.FALAK_DNS_TOKEN_*}.
EnvironmentFile=-/etc/falak/caddy/edge.env
# The admin API's unix socket lives here (only the edge user and root can open it).
RuntimeDirectory=falak-edge
RuntimeDirectoryMode=0700
%[3]sExecStart=%[2]s run --environ --config %[4]s
ExecReload=%[2]s reload --config %[4]s --force
TimeoutStopSec=5s
LimitNOFILE=1048576
PrivateTmp=true
ProtectSystem=full
AmbientCapabilities=CAP_NET_ADMIN CAP_NET_BIND_SERVICE
Restart=on-failure
RestartSec=2s

[Install]
WantedBy=multi-user.target
`, u.User, u.Binary, env, EdgeBootstrap)
}

// mergeGroups unions the requested groups with those already on the unit, so a later call that does
// not know them (a standalone runtime.frankenphp.configure) never strips access a site relies on.
func mergeGroups(fs hostfs.FS, want []string) []string {
	set := map[string]bool{}
	for _, g := range want {
		set[g] = true
	}
	if b, err := fs.ReadFile(EdgeUnitPath); err == nil {
		for _, l := range strings.Split(string(b), "\n") {
			if v, ok := strings.CutPrefix(l, "SupplementaryGroups="); ok {
				for _, g := range strings.Fields(v) {
					set[g] = true
				}
			}
		}
	}
	out := make([]string, 0, len(set))
	for g := range set {
		out = append(out, g)
	}
	sort.Strings(out)
	return out
}

// BootstrapConfig is the admin-only Caddy config written when no config exists yet. The edge package
// overwrites the file with the full applied config on every edge.caddy.apply.
const BootstrapConfig = `{"admin":{"listen":"unix//run/falak-edge/admin.sock|0600"}}` + "\n"

// EnsureEdgeUnit converges falak-edge.service (unit, service user, bootstrap config, running state).
// restart forces a restart (e.g. new binary) when the unit itself is unchanged.
func EnsureEdgeUnit(ctx context.Context, r runner.Runner, fs hostfs.FS, st commands.Stream, u EdgeUnit, restart bool) (bool, error) {
	if u.User == "" {
		u.User = "caddy"
	}
	run := func(name string, args ...string) error {
		_, err := runner.Check(ctx, r, runner.Cmd{Name: name, Args: args, Stdout: st.Stdout(), Stderr: st.Stderr()})
		return err
	}
	changed := false
	ur, err := system.EnsureUser(ctx, r, fs, system.UserSpec{Name: u.User, System: true, Shell: "/usr/sbin/nologin", Home: "/var/lib/caddy"}, st)
	if err != nil {
		return false, err
	}
	changed = ur.Changed
	// Never overwrite: the edge package keeps the last applied full config here.
	if !fs.Exists(EdgeBootstrap) {
		if _, err := fs.WriteFile(EdgeBootstrap, []byte(BootstrapConfig), 0o644); err != nil {
			return false, err
		}
		changed = true
	}
	// The edge runs as its own user and reads /etc/falak/caddy: /etc/falak must be traversable (o+x), whatever
	// created it first (0700 on some installs).
	if fixed, err := hostfs.EnsureTraversable(fs, "/etc/falak"); err != nil {
		return false, err
	} else if fixed {
		changed = true
	}
	// A different binary must accept the config the edge loads at start, or the restart below would take every site
	// down (stock Caddy cannot load a config with rate_limit handlers, FrankenPHP's build lacks the module too).
	if cur, ok := CurrentEdgeUnit(fs); ok && cur.Binary != u.Binary && !u.SkipValidate {
		if err := ValidateEdgeConfig(ctx, r, fs, u); err != nil {
			return false, fmt.Errorf("%w from %s to %s: %v", errBinarySwitch, cur.Binary, u.Binary, err)
		}
	}
	u.Groups = mergeGroups(fs, u.Groups)
	local, err := localedge.Load(fs, localedge.Path)
	if err != nil {
		return false, err
	}
	u.Slice = local.Slice()
	// Only the unit file is written: drop-ins (falak-edge.service.d/, falak-ctl's) stay as they are.
	unitChanged, err := fs.WriteFile(EdgeUnitPath, []byte(RenderEdgeUnit(u)), 0o644)
	if err != nil {
		return false, err
	}
	if unitChanged {
		changed = true
		// The distro caddy.service (Caddyfile based) would fight for :80/:443.
		if res, err := r.Run(ctx, runner.Cmd{Name: "systemctl", Args: []string{"is-enabled", "--quiet", "caddy.service"}}); err == nil && res.ExitCode == 0 {
			if err := run("systemctl", "disable", "--now", "caddy.service"); err != nil {
				return false, err
			}
		}
		if err := run("systemctl", "daemon-reload"); err != nil {
			return false, err
		}
		if err := run("systemctl", "enable", "falak-edge.service"); err != nil {
			return false, err
		}
		return true, run("systemctl", "restart", "falak-edge.service")
	}
	res, err := r.Run(ctx, runner.Cmd{Name: "systemctl", Args: []string{"is-active", "--quiet", "falak-edge.service"}})
	if err != nil {
		return false, err
	}
	if res.ExitCode != 0 {
		return true, run("systemctl", "enable", "--now", "falak-edge.service")
	}
	if restart {
		return true, run("systemctl", "restart", "falak-edge.service")
	}
	return changed, nil
}
