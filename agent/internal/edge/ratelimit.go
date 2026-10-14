package edge

import (
	"bufio"
	"bytes"
	"context"
	"errors"
	"fmt"
	"net/url"
	"path"
	"regexp"
	"sort"
	"strings"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runtime"
	"github.com/OthmanHaba/falak/agent/internal/system"
)

// Path rate limits at the edge (docs/plans/network/protection.md): github.com/mholt/caddy-ratelimit's
// http.handlers.rate_limit. Stock Caddy (the apt package servers run) has no such module, so the first config with a
// rule installs Falak's Caddy build (the same Caddy plus caddy-ratelimit and caddy-dns/cloudflare) next to it and points
// falak-edge.service at it. FrankenPHP edges are refused: their binary is FrankenPHP's own.

// RateLimitModule is the Caddy module rate limits need.
const RateLimitModule = "http.handlers.rate_limit"

// CaddyBuildDir is where Falak's Caddy builds are kept (one file per build, named by its checksum).
const CaddyBuildDir = "/usr/local/lib/falak/caddy"

// Limits of a rule (the schema has the same).
const (
	maxRateLimitsPerSite = 20
	maxRateLimitEvents   = 100000
	maxRateLimitWindowS  = 86400
)

// rateLimitPath is an absolute request path, optionally ending in "*" (a prefix). No placeholders, spaces or quotes.
var rateLimitPath = regexp.MustCompile(`^/[A-Za-z0-9._~!$&'()+,;=:@%/-]{0,198}\*?$`)

var sha256Hex = regexp.MustCompile(`^[a-f0-9]{64}$`)

func (p Payload) needsRateLimit() bool {
	for _, s := range p.Sites {
		if len(s.RateLimits) > 0 {
			return true
		}
	}
	return false
}

// rateLimitHandler renders the site's rules as one rate_limit handler: a zone per rule, keyed by the client IP Caddy
// resolved (behind trusted proxies, the visitor's).
func rateLimitHandler(s Site) (obj, error) {
	if len(s.RateLimits) > maxRateLimitsPerSite {
		return nil, fmt.Errorf("at most %d rate limits per site", maxRateLimitsPerSite)
	}
	zones := obj{}
	for i, r := range s.RateLimits {
		if !rateLimitPath.MatchString(r.Path) {
			return nil, fmt.Errorf("invalid rate limit path %q", r.Path)
		}
		if r.Events < 1 || r.Events > maxRateLimitEvents || r.WindowS < 1 || r.WindowS > maxRateLimitWindowS {
			return nil, fmt.Errorf("rate limit %s: events 1-%d and window_s 1-%d", r.Path, maxRateLimitEvents, maxRateLimitWindowS)
		}
		// Zones are global in Caddy, by name: every route of one site (its domain groups, its compose services) shares
		// them, so a client is counted once per site whichever of its hosts it uses.
		zones[fmt.Sprintf("falak_%s_%d", strings.SplitN(s.ID, "-", 2)[0], i)] = obj{
			"match":      []any{obj{"path": []any{r.Path}}},
			"key":        "{http.vars.client_ip}",
			"window":     fmt.Sprintf("%ds", r.WindowS),
			"max_events": r.Events,
		}
	}
	return obj{"handler": "rate_limit", "rate_limits": zones}, nil
}

// edgeUnit reads the binary and mode of falak-edge.service.
func (m *Manager) edgeUnit() (runtime.EdgeUnit, error) {
	b, err := m.o.FS.ReadFile(runtime.EdgeUnitPath)
	if err != nil {
		return runtime.EdgeUnit{}, fmt.Errorf("falak-edge.service: %w", err)
	}
	u := runtime.EdgeUnit{FrankenPHP: strings.Contains(string(b), "PHPRC=")}
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
	if u.Binary == "" {
		return u, errors.New("falak-edge.service has no ExecStart")
	}
	return u, nil
}

// modules lists the Caddy modules of a binary (`caddy list-modules`).
func (m *Manager) modules(ctx context.Context, binary string) (map[string]bool, error) {
	res, err := runner.Check(ctx, m.o.Runner, runner.Cmd{Name: binary, Args: []string{"list-modules"}})
	if err != nil {
		return nil, err
	}
	out := map[string]bool{}
	sc := bufio.NewScanner(bytes.NewReader(res.Stdout))
	for sc.Scan() {
		l := strings.TrimSpace(sc.Text())
		if l != "" && !strings.ContainsAny(l, " :") && strings.Contains(l, ".") {
			out[l] = true
		}
	}
	return out, nil
}

// ensureRateLimit makes sure the edge can run rate_limit handlers before a config with them is loaded: nothing to do
// when its Caddy has the module; else Falak's build is downloaded (checksum verified), checked to have every module the
// current one has plus rate_limit, and falak-edge.service is pointed at it and restarted.
func (m *Manager) ensureRateLimit(ctx context.Context, p Payload, s commands.Stream) error {
	if !p.needsRateLimit() {
		return nil
	}
	if m.o.Runner == nil {
		return errors.New("rate limits need the agent's runner")
	}
	u, err := m.edgeUnit()
	if err != nil {
		return err
	}
	if u.FrankenPHP {
		return errors.New("rate limits are not available on a FrankenPHP edge (its Caddy is FrankenPHP's own build, without the rate_limit module)")
	}
	current, err := m.modules(ctx, u.Binary)
	if err != nil {
		return fmt.Errorf("listing the edge's Caddy modules: %w", err)
	}
	if current[RateLimitModule] {
		return nil
	}
	b := p.CaddyBuild
	if b == nil {
		return fmt.Errorf("the edge's Caddy (%s) has no %s module and no Falak Caddy build was sent", u.Binary, RateLimitModule)
	}
	if !sha256Hex.MatchString(b.SHA256) {
		return &commands.PayloadError{Err: fmt.Errorf("caddy_build: invalid sha256")}
	}
	if pu, err := url.Parse(b.URL); err != nil || pu.Scheme != "https" || pu.Host == "" {
		return &commands.PayloadError{Err: fmt.Errorf("caddy_build: invalid url")}
	}
	dest := path.Join(CaddyBuildDir, "caddy-"+b.SHA256[:16])
	if !m.o.FS.Exists(dest) {
		if err := m.o.FS.MkdirAll(CaddyBuildDir, 0o755); err != nil {
			return err
		}
		fmt.Fprintf(s.Stdout(), "installing Falak's Caddy build (rate_limit module) %s\n", b.SHA256[:12])
		if _, _, err := system.Download(ctx, m.o.HTTP, b.URL, b.SHA256, m.o.FS.P(dest), 0o755, nil); err != nil {
			return fmt.Errorf("downloading Falak's Caddy build: %w", err)
		}
	}
	mods, err := m.modules(ctx, m.o.FS.P(dest))
	if err != nil {
		_, _ = m.o.FS.Remove(dest)
		return fmt.Errorf("Falak's Caddy build does not run here: %w", err)
	}
	var missing []string
	for mod := range current {
		if !mods[mod] {
			missing = append(missing, mod)
		}
	}
	if !mods[RateLimitModule] {
		missing = append(missing, RateLimitModule)
	}
	if len(missing) > 0 {
		sort.Strings(missing)
		return fmt.Errorf("Falak's Caddy build lacks modules the edge needs (%s): keeping the current Caddy", strings.Join(missing, ", "))
	}
	u.Binary = m.o.FS.P(dest)
	fmt.Fprintf(s.Stdout(), "falak-edge now runs %s\n", u.Binary)
	if _, err := runtime.EnsureEdgeUnit(ctx, m.o.Runner, m.o.FS, s, u, true); err != nil {
		return err
	}
	return m.waitAdmin(ctx)
}
