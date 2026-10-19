package netcfg

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/netip"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"sync"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runtime"
	"github.com/OthmanHaba/falak/agent/internal/system"
)

// net.tailscale.apply — joins the server to the organization's tailnet, advertising its own public IPs as host routes
// (docs/plans/TAILSCALE.md). A pinned, checksum-verified static tailscale + tailscaled; the auth key only ever lives
// in a 0600 temp file for the duration of `tailscale up`.
const (
	TailscaleBinary    = "/usr/local/bin/tailscale"
	TailscaledBinary   = "/usr/local/bin/tailscaled"
	TailscaleUnitPath  = "/etc/systemd/system/falak-tailscaled.service"
	TailscaleSocket    = "/run/tailscale/tailscaled.sock"
	tailscaleState     = "/var/lib/tailscale"
	tailscaleCache     = "/var/cache/tailscale"
	tailscaleMarker    = "/etc/falak/tailscale.version"
	tailscaleUnit      = "falak-tailscaled.service"
	maxTailscaleRoutes = 4
)

var (
	tailscaleVersion  = regexp.MustCompile(`^\d{1,3}\.\d{1,4}\.\d{1,4}$`)
	tailscaleHostname = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$`)
)

// TailscalePayload is net.tailscale.apply.
type TailscalePayload struct {
	State           string   `json:"state"` // present | absent
	Version         string   `json:"version,omitempty"`
	URL             string   `json:"url,omitempty"`    // tailscale_<version>_<arch>.tgz of that release
	SHA256          string   `json:"sha256,omitempty"` // of the tarball at URL
	AuthKey         string   `json:"auth_key,omitempty"`
	Hostname        string   `json:"hostname,omitempty"`
	AdvertiseRoutes []string `json:"advertise_routes,omitempty"` // the server's own /32 and /128
}

// Secrets keeps the auth key out of output, errors and the result.
func (p TailscalePayload) Secrets() []string { return []string{p.AuthKey} }

// TailscaleResult is {changed, active, version, backend_state, hostname, ipv4, ipv6, routes_approved}.
type TailscaleResult struct {
	Changed        bool   `json:"changed"`
	Active         bool   `json:"active"`
	Version        string `json:"version,omitempty"`
	BackendState   string `json:"backend_state,omitempty"`
	Hostname       string `json:"hostname,omitempty"`
	IPv4           string `json:"ipv4,omitempty"`
	IPv6           string `json:"ipv6,omitempty"`
	RoutesApproved bool   `json:"routes_approved"`
}

// RenderTailscaleUnit is falak-tailscaled.service (upstream's tailscaled.service, on Falak's paths).
func RenderTailscaleUnit() string {
	return `[Unit]
Description=Falak: Tailscale (tailscaled)
After=network-online.target
Wants=network-online.target

[Service]
Type=notify
ExecStartPre=` + TailscaledBinary + ` --cleanup
ExecStart=` + TailscaledBinary + ` --state=` + tailscaleState + `/tailscaled.state --socket=` + TailscaleSocket + ` --port=41641
ExecStopPost=` + TailscaledBinary + ` --cleanup
Restart=on-failure
RuntimeDirectory=tailscale
RuntimeDirectoryMode=0755
StateDirectory=tailscale
StateDirectoryMode=0700
CacheDirectory=tailscale
CacheDirectoryMode=0750

[Install]
WantedBy=multi-user.target
`
}

// validTailscale checks a present payload and returns the normalized routes.
func validTailscale(p TailscalePayload) ([]string, error) {
	if !tailscaleVersion.MatchString(p.Version) || !sha256Hex.MatchString(p.SHA256) || !strings.HasPrefix(p.URL, "https://") {
		return nil, errors.New("version, https url and sha256 are required")
	}
	if !strings.HasPrefix(p.AuthKey, "tskey-") || strings.ContainsAny(p.AuthKey, " \t\r\n") {
		return nil, errors.New("auth_key must be a tskey-… auth key")
	}
	if !tailscaleHostname.MatchString(p.Hostname) {
		return nil, fmt.Errorf("hostname %q must be a DNS label (a-z, 0-9, -)", p.Hostname)
	}
	if len(p.AdvertiseRoutes) > maxTailscaleRoutes {
		return nil, fmt.Errorf("at most %d advertise_routes", maxTailscaleRoutes)
	}
	routes := make([]string, 0, len(p.AdvertiseRoutes))
	for _, r := range p.AdvertiseRoutes {
		pfx, err := netip.ParsePrefix(r)
		if err != nil || pfx.Bits() != pfx.Addr().BitLen() || pfx.Addr().Is4In6() {
			return nil, fmt.Errorf("advertise route %q must be a host route (/32 or /128)", r)
		}
		routes = append(routes, pfx.String())
	}
	return routes, nil
}

// tailscaleStatus is the part of `tailscale status --json` the result reports.
type tailscaleStatus struct {
	BackendState string
	Self         *struct {
		HostName      string
		DNSName       string
		TailscaleIPs  []string
		PrimaryRoutes []string
	}
}

// tailscaleResult fills the status fields of r from `tailscale status --json`.
func tailscaleResult(out []byte, routes []string, r *TailscaleResult) error {
	var s tailscaleStatus
	if err := json.Unmarshal(out, &s); err != nil {
		return fmt.Errorf("tailscale status: %w", err)
	}
	r.BackendState = s.BackendState
	r.Active = s.BackendState == "Running"
	r.RoutesApproved = true
	if s.Self == nil {
		r.RoutesApproved = len(routes) == 0
		return nil
	}
	// The tailnet's device name (deduplicated by Tailscale, e.g. falak-main-1-1), else the hostname sent.
	r.Hostname = s.Self.HostName
	if name, _, _ := strings.Cut(s.Self.DNSName, "."); name != "" {
		r.Hostname = name
	}
	for _, ip := range s.Self.TailscaleIPs {
		if a, err := netip.ParseAddr(ip); err == nil && a.Is4() && r.IPv4 == "" {
			r.IPv4 = a.String()
		} else if err == nil && a.Is6() && r.IPv6 == "" {
			r.IPv6 = a.String()
		}
	}
	approved := map[string]bool{}
	for _, pr := range s.Self.PrimaryRoutes {
		if pfx, err := netip.ParsePrefix(pr); err == nil {
			approved[pfx.String()] = true
		}
	}
	for _, rt := range routes {
		r.RoutesApproved = r.RoutesApproved && approved[rt]
	}
	return nil
}

// tailscaleMu serializes TailscaleApply (two applies at once would race on the binaries, unit and `tailscale up`).
var tailscaleMu sync.Mutex

// TailscaleApply installs / updates / removes tailscale and (re)joins the tailnet.
func (n *Net) TailscaleApply(ctx context.Context, p TailscalePayload, st commands.Stream) (any, error) {
	tailscaleMu.Lock()
	defer tailscaleMu.Unlock()
	run := func(args ...string) error {
		res, err := n.d.Runner.Run(ctx, runner.Cmd{Name: "systemctl", Args: args, Stdout: st.Stdout(), Stderr: st.Stderr()})
		if err == nil && res.ExitCode != 0 {
			err = fmt.Errorf("systemctl %s: exit %d", strings.Join(args, " "), res.ExitCode)
		}
		return err
	}
	ts := func(args ...string) (runner.Result, error) {
		return n.d.Runner.Run(ctx, runner.Cmd{Name: TailscaleBinary, Args: append([]string{"--socket=" + TailscaleSocket}, args...)})
	}

	switch p.State {
	case "absent":
		changed := false
		if n.d.FS.Exists(TailscaleUnitPath) {
			_, _ = ts("logout") // best effort: the daemon may not be running
			_ = run("disable", "--now", tailscaleUnit)
			changed = true
		}
		for _, f := range []string{TailscaleUnitPath, tailscaleMarker, TailscaleBinary, TailscaledBinary} {
			if removed, err := n.d.FS.Remove(f); err != nil {
				return nil, err
			} else if removed {
				changed = true
			}
		}
		for _, d := range []string{tailscaleState, tailscaleCache} {
			if n.d.FS.Exists(d) {
				if err := os.RemoveAll(n.d.FS.P(d)); err != nil {
					return nil, err
				}
				changed = true
			}
		}
		if changed {
			if err := run("daemon-reload"); err != nil {
				return nil, err
			}
		}
		return TailscaleResult{Changed: changed, RoutesApproved: true}, nil
	case "present":
	default:
		return nil, &commands.PayloadError{Err: fmt.Errorf("state must be present or absent")}
	}

	routes, err := validTailscale(p)
	if err != nil {
		return nil, &commands.PayloadError{Err: err}
	}
	// Key files a crash left behind (the defer below never ran).
	stale, _ := filepath.Glob(n.d.FS.P("/etc/falak/.tailscale-key-*"))
	for _, f := range stale {
		_ = os.Remove(f)
	}

	marker := []byte(p.Version + " " + p.SHA256 + "\n")
	cur, _ := n.d.FS.ReadFile(tailscaleMarker)
	changed := false
	if !n.d.FS.Exists(TailscaleBinary) || !n.d.FS.Exists(TailscaledBinary) || string(cur) != string(marker) {
		fmt.Fprintf(st.Stdout(), "downloading tailscale %s\n", p.Version)
		if err := n.installTailscale(ctx, p); err != nil {
			return nil, err
		}
		if _, err := n.d.FS.WriteFile(tailscaleMarker, marker, 0o644); err != nil {
			return nil, err
		}
		changed = true
	}
	unitChanged, err := n.d.FS.WriteFile(TailscaleUnitPath, []byte(RenderTailscaleUnit()), 0o644)
	if err != nil {
		return nil, err
	}
	changed = changed || unitChanged
	if unitChanged {
		if err := run("daemon-reload"); err != nil {
			return nil, err
		}
	}
	if err := run("enable", tailscaleUnit); err != nil {
		return nil, err
	}
	if changed {
		err = run("restart", tailscaleUnit)
	} else {
		err = run("start", tailscaleUnit)
	}
	if err != nil {
		return nil, err
	}

	// Not yet on the tailnet counts as a change (a refresh of a running node does not).
	var before TailscaleResult
	if res, err := ts("status", "--json"); err == nil {
		_ = tailscaleResult(res.Stdout, routes, &before)
	}
	changed = changed || !before.Active

	// The key goes to tailscale as a 0600 file, never on the command line; removed as soon as up returns.
	if err := n.d.FS.MkdirAll("/etc/falak", 0o755); err != nil {
		return nil, err
	}
	kf, err := os.CreateTemp(n.d.FS.P("/etc/falak"), ".tailscale-key-")
	if err != nil {
		return nil, err
	}
	defer os.Remove(kf.Name())
	_, err = kf.WriteString(p.AuthKey)
	if cerr := kf.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		return nil, err
	}
	up, err := n.d.Runner.Run(ctx, runner.Cmd{Name: TailscaleBinary, Args: []string{
		"--socket=" + TailscaleSocket, "up",
		"--auth-key=file:" + kf.Name(),
		"--hostname=" + p.Hostname,
		"--advertise-routes=" + strings.Join(routes, ","),
		"--accept-routes=false", "--accept-dns=false", "--reset",
		"--snat-subnet-routes=false", // keep the device's 100.x source, also for traffic DNATed to Docker-published ports
		"--timeout=60s",              // a bad or expired key fails instead of waiting for an interactive login
	}, Stdout: st.Stdout(), Stderr: st.Stderr()})
	if err == nil && up.ExitCode != 0 {
		err = fmt.Errorf("tailscale up: exit %d: %s", up.ExitCode, strings.TrimSpace(string(up.Stderr)))
	}
	if err != nil {
		return nil, err
	}

	out := TailscaleResult{Changed: changed, Version: p.Version}
	res, err := ts("status", "--json")
	if err == nil && res.ExitCode != 0 {
		err = fmt.Errorf("tailscale status: exit %d", res.ExitCode)
	}
	if err == nil {
		err = tailscaleResult(res.Stdout, routes, &out)
	}
	if err != nil {
		return nil, err
	}
	fmt.Fprintf(st.Stdout(), "tailscale %s: %s as %s %s\n", p.Version, out.BackendState, out.Hostname, out.IPv4)
	return out, nil
}

// installTailscale downloads the tarball and puts its tailscale and tailscaled into /usr/local/bin.
func (n *Net) installTailscale(ctx context.Context, p TailscalePayload) error {
	bin := filepath.Dir(TailscaleBinary)
	if err := n.d.FS.MkdirAll(bin, 0o755); err != nil {
		return err
	}
	tmp, err := os.MkdirTemp(n.d.FS.P(bin), ".falak-tailscale-")
	if err != nil {
		return err
	}
	defer os.RemoveAll(tmp)
	tgz := filepath.Join(tmp, "tailscale.tgz")
	if _, _, err := system.Download(ctx, n.d.HTTP, p.URL, p.SHA256, tgz, 0o644, nil); err != nil {
		return err
	}
	f, err := os.Open(tgz)
	if err != nil {
		return err
	}
	tree := filepath.Join(tmp, "tree")
	err = runtime.ExtractTarGz(f, tree, 1) // tailscale_<version>_<arch>/...
	f.Close()
	if err != nil {
		return err
	}
	for _, b := range []string{TailscaleBinary, TailscaledBinary} {
		src := filepath.Join(tree, filepath.Base(b))
		if fi, err := os.Stat(src); err != nil || !fi.Mode().IsRegular() {
			return fmt.Errorf("tailscale tarball has no %s", filepath.Base(b))
		}
		if err := os.Chmod(src, 0o755); err != nil {
			return err
		}
		if err := os.Rename(src, n.d.FS.P(b)); err != nil {
			return err
		}
	}
	return nil
}
