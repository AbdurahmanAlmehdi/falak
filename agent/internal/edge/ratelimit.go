package edge

import (
	"bufio"
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/url"
	"os"
	"path"
	"regexp"
	"slices"
	"sort"
	"strings"
	"time"

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

// dnsModule is the Caddy module of a DNS-01 provider (caddy-dns/<provider>).
func dnsModule(provider string) string { return "dns.providers." + provider }

// neededModules are the modules outside stock Caddy the payload uses (Falak's build has them): rate_limit for path rate
// limits, the DNS provider of DNS-01 certificates (per domain or a preview wildcard). Sorted.
func (p Payload) neededModules() []string {
	set := map[string]bool{}
	if p.needsRateLimit() {
		set[RateLimitModule] = true
	}
	for _, s := range p.Sites {
		if s.TLS != nil && s.TLS.DNS != nil {
			set[dnsModule(s.TLS.DNS.Provider)] = true
		}
	}
	for _, w := range p.WildcardCertificates {
		set[dnsModule(w.DNS.Provider)] = true
	}
	out := make([]string, 0, len(set))
	for mod := range set {
		out = append(out, mod)
	}
	sort.Strings(out)
	return out
}

// missingModules are the modules of need a Caddy binary does not have.
func missingModules(have map[string]bool, need []string) []string {
	var out []string
	for _, mod := range need {
		if !have[mod] {
			out = append(out, mod)
		}
	}
	return out
}

// rateLimitHandler renders the site's rules as one rate_limit handler: a zone per rule, keyed by the client IP Caddy
// resolved (behind trusted proxies, the visitor's).
func rateLimitHandler(s Site) (obj, error) {
	if len(s.RateLimits) > maxRateLimitsPerSite {
		return nil, fmt.Errorf("at most %d rate limits per site", maxRateLimitsPerSite)
	}
	zones := obj{}
	for _, r := range s.RateLimits {
		if !rateLimitPath.MatchString(r.Path) {
			return nil, fmt.Errorf("invalid rate limit path %q", r.Path)
		}
		if r.Events < 1 || r.Events > maxRateLimitEvents || r.WindowS < 1 || r.WindowS > maxRateLimitWindowS {
			return nil, fmt.Errorf("rate limit %s: events 1-%d and window_s 1-%d", r.Path, maxRateLimitEvents, maxRateLimitWindowS)
		}
		zones[rateLimitZone(s.ID, r)] = obj{
			"match":      []any{obj{"path": []any{r.Path}}},
			"key":        "{http.vars.client_ip}",
			"window":     fmt.Sprintf("%ds", r.WindowS),
			"max_events": r.Events,
		}
	}
	return obj{"handler": "rate_limit", "rate_limits": zones}, nil
}

// rateLimitZone names a rule's zone. Zones are global in Caddy, by name, and a reloaded config reuses a zone's counters
// when the name is the same: the name holds the route's full id (two routes never share a zone, whatever their ids
// have in common) and a hash of the rule (a changed limit starts a fresh zone instead of reusing counters sized for
// the old one).
func rateLimitZone(routeID string, r RateLimit) string {
	sum := sha256.Sum256([]byte(fmt.Sprintf("%s|%d|%d", r.Path, r.Events, r.WindowS)))
	id := strings.Map(func(c rune) rune {
		if (c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z') || (c >= '0' && c <= '9') {
			return c
		}
		return '_'
	}, routeID)
	return "falak_" + id + "_" + hex.EncodeToString(sum[:4])
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

// rlState is what the edge remembers about Falak's Caddy builds (EtcDir/caddy/ratelimit.json): builds that failed here,
// never tried again (a later apply would otherwise restart the edge twice and fail every time).
type rlState struct {
	Failed map[string]string `json:"failed,omitempty"` // sha256 → why
}

const maxFailedBuilds = 20

func (m *Manager) rlStatePath() string { return path.Join(m.o.EtcDir, "caddy", "ratelimit.json") }

func (m *Manager) loadRLState() rlState {
	var st rlState
	if raw, err := m.o.FS.ReadFile(m.rlStatePath()); err == nil {
		_ = json.Unmarshal(raw, &st)
	}
	if st.Failed == nil {
		st.Failed = map[string]string{}
	}
	return st
}

func (m *Manager) markFailed(sha, why string) {
	st := m.loadRLState()
	if len(st.Failed) >= maxFailedBuilds {
		st.Failed = map[string]string{}
	}
	if len(why) > 300 {
		why = why[:300]
	}
	st.Failed[sha] = why
	b, _ := json.MarshalIndent(st, "", "  ")
	_, _ = m.o.FS.WriteFile(m.rlStatePath(), b, 0o600)
}

func buildPath(sha string) string { return path.Join(runtime.CaddyBuildDir, "caddy-"+sha[:16]) }

// ensureCaddyModules decides whether this apply can use the modules only Falak's Caddy build has (rate limits, DNS-01
// certificates): "" when it can (the edge's Caddy has them, or Falak's build was installed now), else why not, and the
// caller applies the config without them (rate limits left out, DNS-01 certificates obtained over HTTP instead; the
// other sites keep working). Without this a single DNS-01 domain made Caddy refuse every route of the server (v0.25).
// err is only for an edge left broken (a failed rollback).
//
// A Falak build the edge already runs is upgraded when the control plane ships a different one (same checks, same
// rollback); the edge keeps its current build when the new one fails.
func (m *Manager) ensureCaddyModules(ctx context.Context, p Payload, s commands.Stream) (string, error) {
	need := p.neededModules()
	if len(need) == 0 {
		return "", nil
	}
	// What the edge runs cannot be checked: DNS-01 alone is applied as before (rate limits are not, they need the
	// module for sure).
	unknown := func(why string) (string, error) {
		if !p.needsRateLimit() {
			return "", nil
		}
		return why, nil
	}
	if m.o.Runner == nil {
		return unknown("the agent cannot run commands")
	}
	u, ok := runtime.CurrentEdgeUnit(m.o.FS)
	if !ok {
		return unknown("no falak-edge.service on this server")
	}
	if u.FrankenPHP {
		return fmt.Sprintf("the edge is FrankenPHP (its Caddy has no %s module)", strings.Join(need, ", ")), nil
	}
	current, err := m.modules(ctx, u.Binary)
	if err != nil {
		return unknown("the edge's Caddy does not list its modules: " + shortErr(err))
	}
	b := p.CaddyBuild
	if b != nil && !sha256Hex.MatchString(b.SHA256) {
		return "", &commands.PayloadError{Err: fmt.Errorf("caddy_build: invalid sha256")}
	}
	if b != nil {
		if pu, err := url.Parse(b.URL); err != nil || pu.Scheme != "https" || pu.Host == "" {
			return "", &commands.PayloadError{Err: fmt.Errorf("caddy_build: invalid url")}
		}
	}
	missing := missingModules(current, need)
	if len(missing) == 0 {
		// Upgrade a Falak build to the one shipped now; an operator's own Caddy with the module is left alone.
		if b != nil && runtime.IsFalakCaddy(u.Binary) && path.Base(u.Binary) != path.Base(buildPath(b.SHA256)) {
			if why, failed := m.loadRLState().Failed[b.SHA256]; failed {
				fmt.Fprintf(s.Stdout(), "keeping the current Caddy build (%s failed here before: %s)\n", b.SHA256[:12], why)
				return "", nil
			}
			if why, err := m.installBuild(ctx, u, current, need, b, s); err != nil {
				return "", err
			} else if why != "" {
				fmt.Fprintf(s.Stderr(), "Caddy build upgrade failed, keeping the current one: %s\n", why)
			}
		}
		return "", nil
	}
	if b == nil {
		return fmt.Sprintf("the edge's Caddy (%s) has no %s module and no Falak Caddy build was sent for this server", u.Binary, strings.Join(missing, ", ")), nil
	}
	if why, failed := m.loadRLState().Failed[b.SHA256]; failed {
		return fmt.Sprintf("Falak's Caddy build %s failed on this server before: %s", b.SHA256[:12], why), nil
	}
	return m.installBuild(ctx, u, current, need, b, s)
}

// swapMarker is written before the edge restarts on a new binary and removed once it answers: an agent that dies in
// between finds it at start and rolls back unless the edge is healthy (ResolvePendingSwap).
type swapMarker struct {
	Previous runtime.EdgeUnit `json:"previous"`
	Target   string           `json:"target"`
	SHA256   string           `json:"sha256"`
	At       time.Time        `json:"at"`
}

func (m *Manager) swapMarkerPath() string { return path.Join(m.o.EtcDir, "caddy", "swap.json") }

// installBuild downloads, checks and switches the edge to a build: "" when it runs it now, else why not (the edge was
// kept or put back on its current Caddy). The build is remembered as failed in either case of refusal.
func (m *Manager) installBuild(ctx context.Context, u runtime.EdgeUnit, current map[string]bool, need []string, b *CaddyBuild, s commands.Stream) (string, error) {
	fail := func(why string) (string, error) {
		m.markFailed(b.SHA256, why)
		return why, nil
	}
	dest := buildPath(b.SHA256)
	if !m.o.FS.Exists(dest) {
		if err := m.o.FS.MkdirAll(runtime.CaddyBuildDir, 0o755); err != nil {
			return "", err
		}
		fmt.Fprintf(s.Stdout(), "installing Falak's Caddy build (%s) %s\n", strings.Join(need, ", "), b.SHA256[:12])
		if _, _, err := system.Download(ctx, m.o.HTTP, b.URL, b.SHA256, m.o.FS.P(dest), 0o755, nil); err != nil {
			// A download can fail transiently: not remembered, the next apply tries again.
			return "downloading Falak's Caddy build: " + shortErr(err), nil
		}
	}
	mods, err := m.modules(ctx, m.o.FS.P(dest))
	if err != nil {
		_, _ = m.o.FS.Remove(dest)
		return fail("Falak's Caddy build does not run here: " + shortErr(err))
	}
	var missing []string
	for mod := range current {
		if !mods[mod] {
			missing = append(missing, mod)
		}
	}
	missing = append(missing, missingModules(mods, need)...)
	if len(missing) > 0 {
		sort.Strings(missing)
		return fail("Falak's Caddy build lacks modules the edge needs: " + strings.Join(missing, ", "))
	}
	target := u
	target.Binary = m.o.FS.P(dest)
	// The config the edge loads at start, as the edge loads it (its user, its environment: DNS provider tokens). The
	// build is only remembered as failed when it is the build's fault: it ran and rejected the config while the edge's
	// own Caddy accepts it. A validation that could not run, or a config the current Caddy rejects too, is not.
	if err := runtime.ValidateEdgeConfig(ctx, m.o.Runner, m.o.FS, target); err != nil {
		if !errors.Is(err, runtime.ErrConfigRejected) {
			return "validating the edge's config with Falak's Caddy build: " + shortErr(err), nil
		}
		if cerr := runtime.ValidateEdgeConfig(ctx, m.o.Runner, m.o.FS, u); cerr != nil {
			return "the edge's config is rejected by its current Caddy too: " + shortErr(cerr), nil
		}
		return fail(shortErr(err))
	}
	marker, _ := json.Marshal(swapMarker{Previous: u, Target: target.Binary, SHA256: b.SHA256, At: time.Now().UTC()})
	if _, err := m.o.FS.WriteFile(m.swapMarkerPath(), marker, 0o600); err != nil {
		return "", err
	}
	fmt.Fprintf(s.Stdout(), "falak-edge now runs %s\n", target.Binary)
	_, err = runtime.EnsureEdgeUnit(ctx, m.o.Runner, m.o.FS, s, target, true)
	if err == nil {
		err = m.waitAdmin(ctx)
	}
	if err != nil {
		fmt.Fprintf(s.Stderr(), "falak-edge did not start with Falak's Caddy build (%v): rolling back to %s\n", err, u.Binary)
		if rerr := m.rollback(ctx, u, s); rerr != nil {
			return "", fmt.Errorf("Falak's Caddy build failed (%v) and rolling back failed: %w", err, rerr)
		}
		_, _ = m.o.FS.Remove(m.swapMarkerPath())
		return fail(fmt.Sprintf("the edge did not start with build %s (rolled back to %s): %s", b.SHA256[:12], u.Binary, shortErr(err)))
	}
	_, _ = m.o.FS.Remove(m.swapMarkerPath())
	m.pruneBuilds(path.Base(dest), path.Base(u.Binary))
	return "", nil
}

// rollback puts the edge back on the unit it ran and waits for it.
func (m *Manager) rollback(ctx context.Context, previous runtime.EdgeUnit, s commands.Stream) error {
	// Forced: the previous binary ran this server's edge until a moment ago.
	previous.SkipValidate = true
	if _, err := runtime.EnsureEdgeUnit(ctx, m.o.Runner, m.o.FS, s, previous, true); err != nil {
		return err
	}
	return m.waitAdmin(ctx)
}

// pruneBuilds keeps the build the edge runs and the one it ran before; older builds go.
func (m *Manager) pruneBuilds(keep ...string) {
	entries, err := os.ReadDir(m.o.FS.P(runtime.CaddyBuildDir))
	if err != nil {
		return
	}
	for _, e := range entries {
		if !strings.HasPrefix(e.Name(), "caddy-") || slices.Contains(keep, e.Name()) {
			continue
		}
		_, _ = m.o.FS.Remove(path.Join(runtime.CaddyBuildDir, e.Name()))
	}
}

// ResolvePendingSwap finishes a binary switch an agent restart interrupted (called at agent start): the marker is
// removed when the edge answers; otherwise the edge goes back to the Caddy it ran and the build is remembered as failed.
func (m *Manager) ResolvePendingSwap(ctx context.Context) error {
	raw, err := m.o.FS.ReadFile(m.swapMarkerPath())
	if err != nil {
		return nil
	}
	var mk swapMarker
	if json.Unmarshal(raw, &mk) != nil || mk.Previous.Binary == "" {
		_, _ = m.o.FS.Remove(m.swapMarkerPath())
		return nil
	}
	if err := m.waitAdmin(ctx); err == nil {
		_, _ = m.o.FS.Remove(m.swapMarkerPath())
		return nil
	}
	m.o.Logger.Warn("the edge is not answering after a Caddy build switch: rolling back", "build", mk.Target, "previous", mk.Previous.Binary)
	if err := m.rollback(ctx, mk.Previous, discard{}); err != nil {
		return fmt.Errorf("rolling back the edge to %s: %w", mk.Previous.Binary, err)
	}
	if sha256Hex.MatchString(mk.SHA256) {
		m.markFailed(mk.SHA256, "the edge did not answer after switching to it (rolled back at agent start)")
	}
	_, _ = m.o.FS.Remove(m.swapMarkerPath())
	return nil
}

type discard struct{}

func (discard) Stdout() io.Writer   { return io.Discard }
func (discard) Stderr() io.Writer   { return io.Discard }
func (discard) Progress(float64)    {}
func (discard) Emit(string, string) {}

func shortErr(err error) string {
	s := err.Error()
	if len(s) > 300 {
		s = s[:300]
	}
	return s
}

// withoutFalakModules is what an edge without Falak's Caddy modules applies: every site's rate limits removed, DNS-01
// certificates (per domain, the preview wildcard) obtained over HTTP like any other host, and no build.
func (p Payload) withoutFalakModules() Payload {
	p.Sites = append([]Site(nil), p.Sites...)
	for i := range p.Sites {
		p.Sites[i].RateLimits = nil
		if t := p.Sites[i].TLS; t != nil && (t.DNS != nil || t.Mode == "wildcard") {
			c := *t
			c.DNS = nil
			if c.Mode == "wildcard" {
				c.Mode = "acme"
			}
			p.Sites[i].TLS = &c
		}
	}
	p.WildcardCertificates = nil
	p.CaddyBuild = nil
	return p
}
