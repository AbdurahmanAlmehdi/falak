package runtime

import (
	"bufio"
	"bytes"
	"context"
	"errors"
	"fmt"
	"strings"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// Which Caddy binary falak-edge.service runs is decided here, for provisioning, the agent's unit refresh and the edge's
// rate-limit installer alike (docs/plans/network/protection.md): stock Caddy (/usr/bin/caddy, the apt package),
// FrankenPHP, or Falak's Caddy build (CaddyBuildDir, installed when a site gets a rate limit). A unit already running a
// Falak build keeps it: going back to stock Caddy would leave a config with rate_limit handlers it cannot load.

// Paths.
const (
	// CaddyBuildDir holds Falak's Caddy builds, one file per build named by its checksum.
	CaddyBuildDir = "/usr/local/lib/falak/caddy"
	// StockCaddy is the apt package's binary.
	StockCaddy = "/usr/bin/caddy"
	// EdgeEnvPath is the edge's EnvironmentFile (DNS provider tokens, root only).
	EdgeEnvPath = "/etc/falak/caddy/edge.env"
)

// IsFalakCaddy reports whether a binary path (as written in the unit) is one of Falak's Caddy builds.
func IsFalakCaddy(binary string) bool {
	return strings.Contains(binary, CaddyBuildDir+"/caddy-")
}

// CurrentEdgeUnit reads falak-edge.service: its binary, user and mode. ok=false when there is no such unit (or it has no
// ExecStart).
func CurrentEdgeUnit(fs hostfs.FS) (EdgeUnit, bool) {
	b, err := fs.ReadFile(EdgeUnitPath)
	if err != nil {
		return EdgeUnit{}, false
	}
	u := EdgeUnit{FrankenPHP: strings.Contains(string(b), "PHPRC=")}
	for _, l := range strings.Split(string(b), "\n") {
		if v, ok := strings.CutPrefix(l, "User="); ok {
			u.User = strings.TrimSpace(v)
		}
		if v, ok := strings.CutPrefix(l, "ExecStart="); ok {
			if f := strings.Fields(v); len(f) > 0 {
				u.Binary = f[0]
			}
		}
	}
	return u, u.Binary != ""
}

// CaddyBinary is the binary a stock-Caddy edge runs: the Falak build the unit already runs (when it is still there),
// else /usr/bin/caddy.
func CaddyBinary(fs hostfs.FS) string {
	if u, ok := CurrentEdgeUnit(fs); ok && !u.FrankenPHP && IsFalakCaddy(u.Binary) && fileExists(fs, u.Binary) {
		return u.Binary
	}
	return StockCaddy
}

// fileExists checks a path as written in the unit (a host path: under the FS root in tests).
func fileExists(fs hostfs.FS, p string) bool {
	if fs.Root != "" && fs.Root != "/" && strings.HasPrefix(p, fs.Root) {
		p = strings.TrimPrefix(p, fs.Root)
	}
	return fs.Exists(p)
}

// edgeEnv is the environment falak-edge.service runs with: the unit's fixed variables plus its EnvironmentFile.
func edgeEnv(fs hostfs.FS, frankenPHP bool) []string {
	env := []string{"PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin", "HOME=/var/lib/caddy",
		"XDG_DATA_HOME=/var/lib/caddy", "XDG_CONFIG_HOME=/var/lib/caddy/.config"}
	if frankenPHP {
		env = append(env, "PHPRC=/etc/frankenphp")
	}
	raw, err := fs.ReadFile(EdgeEnvPath)
	if err != nil {
		return env
	}
	sc := bufio.NewScanner(bytes.NewReader(raw))
	for sc.Scan() {
		l := strings.TrimSpace(sc.Text())
		if l == "" || strings.HasPrefix(l, "#") || !strings.Contains(l, "=") {
			continue
		}
		env = append(env, l)
	}
	return env
}

// ValidateEdgeConfig checks that a binary accepts the config the edge loads at start (the persisted bootstrap
// config), run the way the edge runs: as its user, with its environment (DNS provider tokens from the
// EnvironmentFile). Nothing to validate when there is no bootstrap config yet.
func ValidateEdgeConfig(ctx context.Context, r runner.Runner, fs hostfs.FS, u EdgeUnit) error {
	if !fs.Exists(EdgeBootstrap) {
		return nil
	}
	user := u.User
	if user == "" {
		user = "caddy"
	}
	res, err := r.Run(ctx, runner.Cmd{Name: u.Binary, Args: []string{"validate", "--config", fs.P(EdgeBootstrap)}, User: user,
		Env: edgeEnv(fs, u.FrankenPHP), ClearEnv: true})
	if err != nil {
		return err
	}
	if res.ExitCode != 0 {
		msg := strings.TrimSpace(string(res.Stderr))
		if len(msg) > 500 {
			msg = msg[len(msg)-500:]
		}
		return fmt.Errorf("%w: %s rejects the edge's config: %s", ErrConfigRejected, u.Binary, msg)
	}
	return nil
}

// ErrConfigRejected marks a validation the binary itself failed (a nonzero exit), as opposed to one that could not run.
var ErrConfigRejected = errors.New("config rejected")

// errBinarySwitch marks a refused change of the edge's binary.
var errBinarySwitch = errors.New("refusing to switch the edge's binary")
