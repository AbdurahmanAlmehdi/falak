package edge

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
	"github.com/OthmanHaba/falak/agent/internal/runtime"
)

func siteRoutesJSON(t *testing.T, cfg obj) map[string]string {
	t.Helper()
	out := map[string]string{}
	for _, srv := range cfg["apps"].(obj)["http"].(obj)["servers"].(obj) {
		for _, r := range srv.(obj)["routes"].([]any) {
			ro := r.(obj)
			id, _ := ro["@id"].(string)
			b, _ := json.Marshal(ro)
			out[id] = string(b)
		}
	}
	return out
}

// Sites without rules render byte for byte as before; only the site with rules gets the handler.
func TestRateLimitRenderLeavesOtherSitesUntouched(t *testing.T) {
	before, err := Render(payload, "/etc/falak/certs")
	if err != nil {
		t.Fatal(err)
	}
	bb, _ := canonical(before)
	if strings.Contains(string(bb), "rate_limit") {
		t.Fatal("rate_limit without rules")
	}
	p := payload
	p.Sites = append([]Site(nil), payload.Sites...)
	p.Sites[2].RateLimits = []RateLimit{{Path: "/login", Events: 10, WindowS: 60}, {Path: "/api/*", Events: 600, WindowS: 60}}
	after, err := Render(p, "/etc/falak/certs")
	if err != nil {
		t.Fatal(err)
	}
	a, b := siteRoutesJSON(t, after), siteRoutesJSON(t, before)
	for id, route := range b {
		if id == RouteID("api") {
			if a[id] == route {
				t.Fatal("api route unchanged")
			}
			continue
		}
		if a[id] != route {
			t.Fatalf("route %q changed:\n%s\n%s", id, route, a[id])
		}
	}
	api := a[RouteID("api")]
	z0, z1 := rateLimitZone("api", RateLimit{Path: "/login", Events: 10, WindowS: 60}), rateLimitZone("api", RateLimit{Path: "/api/*", Events: 600, WindowS: 60})
	for _, w := range []string{`"handler":"rate_limit"`, `"` + z0 + `":{"key":"{http.vars.client_ip}","match":[{"path":["/login"]}],"max_events":10,"window":"60s"}`,
		`"` + z1 + `":{"key":"{http.vars.client_ip}","match":[{"path":["/api/*"]}],"max_events":600,"window":"60s"}`} {
		if !strings.Contains(api, w) {
			t.Fatalf("missing %s in %s", w, api)
		}
	}
	// After the deny list (a denied client is not counted), before the proxy.
	if strings.Index(api, `"client_ip"`) > strings.Index(api, `"rate_limit"`) || strings.Index(api, `"rate_limit"`) > strings.Index(api, `"reverse_proxy"`) {
		t.Fatal("order", api)
	}
}

func TestRateLimitValidation(t *testing.T) {
	for _, r := range []RateLimit{
		{Path: "login", Events: 1, WindowS: 1}, {Path: "/a b", Events: 1, WindowS: 1}, {Path: "/{http.request.uri}", Events: 1, WindowS: 1},
		{Path: `/a"`, Events: 1, WindowS: 1}, {Path: "/*/x", Events: 1, WindowS: 1}, {Path: "/x", Events: 0, WindowS: 1},
		{Path: "/x", Events: 1, WindowS: 0}, {Path: "/x", Events: 1, WindowS: 86401}, {Path: "/x", Events: 100001, WindowS: 1},
	} {
		p := Payload{Sites: []Site{{ID: "s", Domains: []string{"s.example.com"}, Kind: "static", Root: "/srv", RateLimits: []RateLimit{r}}}}
		if _, err := Render(p, "/c"); err == nil {
			t.Errorf("accepted %+v", r)
		}
	}
	many := make([]RateLimit, 21)
	for i := range many {
		many[i] = RateLimit{Path: "/x", Events: 1, WindowS: 1}
	}
	if _, err := Render(Payload{Sites: []Site{{ID: "s", Domains: []string{"s.example.com"}, Kind: "static", Root: "/srv", RateLimits: many}}}, "/c"); err == nil {
		t.Error("21 rules accepted")
	}
	if _, err := Render(Payload{Sites: payload.Sites, CaddyBuild: &CaddyBuild{URL: "https://x", SHA256: strings.Repeat("a", 64)}}, "/c"); err == nil {
		t.Error("caddy_build without rate limits accepted")
	}
}

const stockModules = "admin.api.load\nhttp.handlers.reverse_proxy\nhttp.handlers.static_response\n\n  Standard modules: 3\n\n  Non-standard modules: 0\n"

func rateLimitSetup(t *testing.T, unit string, stock string) (*Manager, *runnertest.Fake, *fakeCaddy, string, *CaddyBuild) {
	m, fc, fs := setup(t)
	if unit != "" {
		os.MkdirAll(filepath.Dir(fs.P(runtime.EdgeUnitPath)), 0o755)
		os.WriteFile(fs.P(runtime.EdgeUnitPath), []byte(unit), 0o644)
	}
	build := []byte("#!falak-caddy")
	sum := sha256.Sum256(build)
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.Write(build) }))
	t.Cleanup(srv.Close)
	dest := fs.P("/usr/local/lib/falak/caddy/caddy-" + hex.EncodeToString(sum[:])[:16])
	f := &runnertest.Fake{}
	f.On("/usr/bin/caddy list-modules", runner.Result{Stdout: []byte(stock)})
	f.On(dest+" list-modules", runner.Result{Stdout: []byte(stockModules + "dns.providers.cloudflare\nhttp.handlers.rate_limit\n")})
	f.On("getent passwd caddy", runner.Result{Stdout: []byte("caddy:x:999:999::/var/lib/caddy:/usr/sbin/nologin\n")})
	m.o.Runner, m.o.HTTP = f, srv.Client()
	return m, f, fc, dest, &CaddyBuild{URL: srv.URL + "/install/edge/caddy-linux-amd64", SHA256: hex.EncodeToString(sum[:])}
}

func withRule(p Payload, b *CaddyBuild) Payload {
	p.Sites = append([]Site(nil), p.Sites...)
	p.Sites[0].RateLimits = []RateLimit{{Path: "/login", Events: 10, WindowS: 60}}
	p.CaddyBuild = b
	return p
}

func TestRateLimitInstallsFalakCaddyOnce(t *testing.T) {
	unit := runtime.RenderEdgeUnit(runtime.EdgeUnit{Binary: "/usr/bin/caddy"})
	m, f, fc, dest, build := rateLimitSetup(t, unit, stockModules)

	// No rules: the edge's binary is never looked at.
	if _, err := m.Apply(context.Background(), payload, stream()); err != nil || f.Ran("/usr/bin/caddy") {
		t.Fatal(err, f.Lines())
	}
	if _, err := m.Apply(context.Background(), withRule(payload, build), stream()); err != nil {
		t.Fatal(err)
	}
	if b, _ := os.ReadFile(dest); string(b) != "#!falak-caddy" {
		t.Fatal("build not installed")
	}
	u, _ := m.o.FS.ReadFile(runtime.EdgeUnitPath)
	if !strings.Contains(string(u), "ExecStart="+dest+" run") || !f.Ran("systemctl restart falak-edge.service") {
		t.Fatal(string(u), f.Lines())
	}
	if fc.loads != 2 {
		t.Fatal("rate-limited config not loaded", fc.loads)
	}

	// The unit now runs the build, which has the module: nothing is downloaded again.
	f.Reset()
	f.On(dest+" list-modules", runner.Result{Stdout: []byte(stockModules + "http.handlers.rate_limit\n")})
	if _, err := m.Apply(context.Background(), withRule(payload, build), stream()); err != nil || f.Ran("systemctl") {
		t.Fatal(err, f.Lines())
	}
}

func applied(t *testing.T, m *Manager, p Payload) ApplyResult {
	t.Helper()
	r, err := m.Apply(context.Background(), p, stream())
	if err != nil {
		t.Fatal(err)
	}
	return r.(ApplyResult)
}

func loadedRateLimit(fc *fakeCaddy) bool {
	fc.mu.Lock()
	defer fc.mu.Unlock()
	b, _ := json.Marshal(fc.cfg)
	return strings.Contains(string(b), `"rate_limit"`)
}

// Rate limits the edge cannot run never block the rest of the config: it is applied without them and the result says
// why (the panel shows it).
func TestRateLimitRefusalsApplyTheRestOfTheConfig(t *testing.T) {
	franken := runtime.RenderEdgeUnit(runtime.EdgeUnit{Binary: "/usr/local/bin/frankenphp", FrankenPHP: true})
	m, f, fc, _, build := rateLimitSetup(t, franken, stockModules)
	if r := applied(t, m, withRule(payload, build)); !strings.Contains(r.RateLimitsRefused, "FrankenPHP") || !r.Changed || loadedRateLimit(fc) || f.Ran("systemctl") {
		t.Fatalf("%+v %v", r, f.Lines())
	}

	unit := runtime.RenderEdgeUnit(runtime.EdgeUnit{Binary: "/usr/bin/caddy"})
	m, _, fc, _, build = rateLimitSetup(t, unit, stockModules)
	if r := applied(t, m, withRule(payload, nil)); !strings.Contains(r.RateLimitsRefused, "no Falak Caddy build") || loadedRateLimit(fc) || fc.loads != 1 {
		t.Fatalf("%+v", r)
	}
	// A download whose checksum differs (or that fails): refused this time, tried again next time.
	bad := *build
	bad.SHA256 = strings.Repeat("b", 64)
	if r := applied(t, m, withRule(payload, &bad)); !strings.Contains(r.RateLimitsRefused, "downloading") {
		t.Fatalf("%+v", r)
	}
	if _, failed := m.loadRLState().Failed[bad.SHA256]; failed {
		t.Fatal("a failed download is remembered")
	}
	plain := *build
	plain.URL = strings.Replace(build.URL, "https://", "http://", 1)
	if _, err := m.Apply(context.Background(), withRule(payload, &plain), stream()); err == nil {
		t.Fatal("http build URL accepted")
	}

	// A build lacking a module the current Caddy has: refused, remembered, never downloaded or checked again.
	m, f, fc, dest, build := rateLimitSetup(t, unit, stockModules+"dns.providers.route53\n")
	if r := applied(t, m, withRule(payload, build)); !strings.Contains(r.RateLimitsRefused, "dns.providers.route53") || f.Ran("systemctl") || loadedRateLimit(fc) {
		t.Fatalf("%+v", r)
	}
	if u, _ := m.o.FS.ReadFile(runtime.EdgeUnitPath); strings.Contains(string(u), dest) {
		t.Fatal("unit switched")
	}
	f.Reset()
	if r := applied(t, m, withRule(payload, build)); !strings.Contains(r.RateLimitsRefused, "failed on this server before") || f.Ran(dest) {
		t.Fatalf("%+v %v", r, f.Lines())
	}
}

// The build is checked against the edge's config before the swap, as the edge runs it (its user, its DNS provider
// tokens); an edge that does not come back is rolled back, and the build is not tried again.
func TestRateLimitSwapIsValidatedAndRolledBack(t *testing.T) {
	unit := runtime.RenderEdgeUnit(runtime.EdgeUnit{Binary: "/usr/bin/caddy"})

	// A DNS-01 server (its Caddy has the DNS provider): validated as the caddy user, with the tokens of edge.env.
	m, f, fc, dest, build := rateLimitSetup(t, unit, stockModules+"dns.providers.cloudflare\n")
	dns := payload
	dns.Sites = append([]Site(nil), payload.Sites...)
	dns.Sites[1] = Site{ID: "wild", Domains: []string{"w.example.com"}, Kind: "static", Root: "/srv/w", TLS: &TLS{DNS: &DNS{Provider: "cloudflare", APIToken: "tok"}}}
	applied(t, m, dns)
	var validate *runnertest.Call
	f.OnFunc(dest+" validate", func(c runnertest.Call) (runner.Result, error) { validate = &c; return runner.Result{}, nil })
	if r := applied(t, m, withRule(dns, build)); r.RateLimitsRefused != "" || !loadedRateLimit(fc) {
		t.Fatalf("%+v", r)
	}
	if validate == nil || validate.User != "caddy" || !validate.ClearEnv || !slices.Contains(validate.Env, TokenEnv("tok")+"=tok") || !slices.Contains(validate.Env, "XDG_DATA_HOME=/var/lib/caddy") {
		t.Fatalf("%+v", validate)
	}

	// The current config is rejected by the build: no swap, remembered.
	m, f, fc, dest, build = rateLimitSetup(t, unit, stockModules)
	applied(t, m, payload)
	f.On(dest+" validate", runner.Result{ExitCode: 1, Stderr: []byte("unknown module")})
	if r := applied(t, m, withRule(payload, build)); !strings.Contains(r.RateLimitsRefused, "rejects the edge's config") || f.Ran("systemctl restart") || loadedRateLimit(fc) {
		t.Fatalf("%+v %v", r, f.Lines())
	}

	// The edge does not restart with the build: back to /usr/bin/caddy, restarted; the next apply does not try again.
	m, f, fc, dest, build = rateLimitSetup(t, unit, stockModules)
	var restarts atomic.Int32
	f.OnFunc("systemctl restart falak-edge.service", func(runnertest.Call) (runner.Result, error) {
		if restarts.Add(1) == 1 {
			return runner.Result{ExitCode: 1, Stderr: []byte("Job for falak-edge.service failed")}, nil
		}
		return runner.Result{}, nil
	})
	if r := applied(t, m, withRule(payload, build)); !strings.Contains(r.RateLimitsRefused, "rolled back to /usr/bin/caddy") || restarts.Load() != 2 || loadedRateLimit(fc) {
		t.Fatalf("%+v %d", r, restarts.Load())
	}
	u, _ := m.o.FS.ReadFile(runtime.EdgeUnitPath)
	if !strings.Contains(string(u), "ExecStart=/usr/bin/caddy run") || strings.Contains(string(u), dest) || m.o.FS.Exists(m.swapMarkerPath()) {
		t.Fatal(string(u))
	}
	if r := applied(t, m, withRule(payload, build)); !strings.Contains(r.RateLimitsRefused, "failed on this server before") || restarts.Load() != 2 {
		t.Fatalf("%+v", r)
	}
}

// An edge already on a Falak build moves to the build the control plane ships now; older builds are pruned.
func TestRateLimitUpgradesFalakBuildAndPrunes(t *testing.T) {
	m, f, fc, dest, build := rateLimitSetup(t, "", stockModules)
	dir := m.o.FS.P(runtime.CaddyBuildDir)
	os.MkdirAll(dir, 0o755)
	old, older := filepath.Join(dir, "caddy-0000000000000001"), filepath.Join(dir, "caddy-0000000000000000")
	os.WriteFile(old, []byte("old"), 0o755)
	os.WriteFile(older, []byte("older"), 0o755)
	unit := runtime.RenderEdgeUnit(runtime.EdgeUnit{Binary: old})
	os.MkdirAll(filepath.Dir(m.o.FS.P(runtime.EdgeUnitPath)), 0o755)
	os.WriteFile(m.o.FS.P(runtime.EdgeUnitPath), []byte(unit), 0o644)
	f.On(old+" list-modules", runner.Result{Stdout: []byte(stockModules + "http.handlers.rate_limit\n")})

	if r := applied(t, m, withRule(payload, build)); r.RateLimitsRefused != "" || !loadedRateLimit(fc) {
		t.Fatalf("%+v", r)
	}
	u, _ := m.o.FS.ReadFile(runtime.EdgeUnitPath)
	if !strings.Contains(string(u), "ExecStart="+dest+" run") {
		t.Fatal(string(u))
	}
	if _, err := os.Stat(old); err != nil {
		t.Fatal("the previous build must be kept")
	}
	if _, err := os.Stat(older); err == nil {
		t.Fatal("an older build was not pruned")
	}

	// An upgrade that fails keeps the current build and still applies the rate limits.
	m, f, fc, _, build = rateLimitSetup(t, unit, stockModules)
	os.MkdirAll(dir, 0o755)
	os.WriteFile(m.o.FS.P(runtime.CaddyBuildDir+"/caddy-0000000000000001"), []byte("old"), 0o755)
	cur := m.o.FS.P(runtime.CaddyBuildDir + "/caddy-0000000000000001")
	os.WriteFile(m.o.FS.P(runtime.EdgeUnitPath), []byte(runtime.RenderEdgeUnit(runtime.EdgeUnit{Binary: cur})), 0o644)
	f.On(cur+" list-modules", runner.Result{Stdout: []byte(stockModules + "dns.providers.route53\nhttp.handlers.rate_limit\n")})
	if r := applied(t, m, withRule(payload, build)); r.RateLimitsRefused != "" || !loadedRateLimit(fc) || f.Ran("systemctl restart") {
		t.Fatalf("%+v", r)
	}
	if u, _ := m.o.FS.ReadFile(runtime.EdgeUnitPath); !strings.Contains(string(u), "ExecStart="+cur+" run") {
		t.Fatal(string(u))
	}
}

// A switch an agent restart interrupted: kept when the edge answers, rolled back (and remembered) when it does not.
func TestResolvePendingSwap(t *testing.T) {
	unit := runtime.RenderEdgeUnit(runtime.EdgeUnit{Binary: "/usr/bin/caddy"})
	m, f, _, dest, build := rateLimitSetup(t, unit, stockModules)
	mk, _ := json.Marshal(swapMarker{Previous: runtime.EdgeUnit{Binary: "/usr/bin/caddy", User: "caddy"}, Target: dest, SHA256: build.SHA256})
	m.o.FS.WriteFile(m.swapMarkerPath(), mk, 0o600)
	if err := m.ResolvePendingSwap(context.Background()); err != nil || m.o.FS.Exists(m.swapMarkerPath()) || f.Ran("systemctl") {
		t.Fatal(err, f.Lines())
	}

	m.o.FS.WriteFile(m.swapMarkerPath(), mk, 0o600)
	os.WriteFile(m.o.FS.P(runtime.EdgeUnitPath), []byte(runtime.RenderEdgeUnit(runtime.EdgeUnit{Binary: dest})), 0o644)
	dead := httptest.NewServer(http.NotFoundHandler())
	dead.Close()
	alive := m.o.Client
	m.o.Client, m.o.AdminWait = &Client{Base: dead.URL}, time.Millisecond
	f.OnFunc("systemctl restart falak-edge.service", func(runnertest.Call) (runner.Result, error) { m.o.Client = alive; return runner.Result{}, nil })
	if err := m.ResolvePendingSwap(context.Background()); err != nil {
		t.Fatal(err)
	}
	u, _ := m.o.FS.ReadFile(runtime.EdgeUnitPath)
	if !strings.Contains(string(u), "ExecStart=/usr/bin/caddy run") || m.o.FS.Exists(m.swapMarkerPath()) {
		t.Fatal(string(u))
	}
	if _, failed := m.loadRLState().Failed[build.SHA256]; !failed {
		t.Fatal("the build is not remembered as failed")
	}
}

// A build is only remembered as failed when it rejected a config the edge's own Caddy accepts.
func TestRateLimitValidationFailuresThatAreNotTheBuilds(t *testing.T) {
	unit := runtime.RenderEdgeUnit(runtime.EdgeUnit{Binary: "/usr/bin/caddy"})

	// The validation could not run (exec error): not remembered.
	m, f, _, dest, build := rateLimitSetup(t, unit, stockModules)
	applied(t, m, payload)
	f.OnFunc(dest+" validate", func(runnertest.Call) (runner.Result, error) {
		return runner.Result{ExitCode: -1}, errors.New("fork/exec: permission denied")
	})
	if r := applied(t, m, withRule(payload, build)); !strings.Contains(r.RateLimitsRefused, "permission denied") {
		t.Fatalf("%+v", r)
	}
	if _, failed := m.loadRLState().Failed[build.SHA256]; failed {
		t.Fatal("an exec error is remembered")
	}

	// The current Caddy rejects the config too: the config, not the build; not remembered.
	m, f, _, dest, build = rateLimitSetup(t, unit, stockModules)
	applied(t, m, payload)
	f.On(dest+" validate", runner.Result{ExitCode: 1, Stderr: []byte("bad config")})
	f.On("/usr/bin/caddy validate", runner.Result{ExitCode: 1, Stderr: []byte("bad config")})
	if r := applied(t, m, withRule(payload, build)); !strings.Contains(r.RateLimitsRefused, "rejected by its current Caddy too") {
		t.Fatalf("%+v", r)
	}
	if _, failed := m.loadRLState().Failed[build.SHA256]; failed {
		t.Fatal("a config the current Caddy rejects too is blamed on the build")
	}
}

// Zone names never collide across routes, whatever their ids share, and change with the rule.
func TestRateLimitZoneNamesAreUniquePerRoute(t *testing.T) {
	rule := RateLimit{Path: "/login", Events: 10, WindowS: 60}
	p := Payload{Sites: []Site{
		{ID: "shop-a", Domains: []string{"a.example.com"}, Kind: "static", Root: "/srv/a", RateLimits: []RateLimit{rule}},
		{ID: "shop-b", Domains: []string{"b.example.com"}, Kind: "static", Root: "/srv/b", RateLimits: []RateLimit{rule}},
		{ID: "shop", Domains: []string{"c.example.com"}, Kind: "static", Root: "/srv/c", RateLimits: []RateLimit{rule}},
	}}
	cfg, err := Render(p, "/c")
	if err != nil {
		t.Fatal(err)
	}
	b, _ := json.Marshal(cfg)
	names := map[string]bool{}
	for _, id := range []string{"shop-a", "shop-b", "shop"} {
		z := rateLimitZone(id, rule)
		if names[z] || !strings.Contains(string(b), `"`+z+`"`) {
			t.Fatalf("zone %s duplicated or missing", z)
		}
		names[z] = true
	}
	if rateLimitZone("shop", rule) == rateLimitZone("shop", RateLimit{Path: "/login", Events: 20, WindowS: 60}) {
		t.Fatal("a changed rule keeps its zone")
	}
}

func loadedDNSChallenge(fc *fakeCaddy) bool {
	fc.mu.Lock()
	defer fc.mu.Unlock()
	b, _ := json.Marshal(fc.cfg)
	return strings.Contains(string(b), `"dns":{"provider"`)
}

// v0.25: one DNS-01 domain on a server whose Caddy has no caddy-dns module made Caddy refuse the whole config, so no
// route of that server could change any more. Falak's build is installed for it now, and an edge without it obtains
// the certificate over HTTP instead of refusing every route.
func TestDNSChallengeInstallsFalakCaddyOrFallsBackToHTTP(t *testing.T) {
	unit := runtime.RenderEdgeUnit(runtime.EdgeUnit{Binary: "/usr/bin/caddy"})
	dns := payload
	dns.Sites = append([]Site(nil), payload.Sites...)
	dns.Sites[1] = Site{ID: "dns", Domains: []string{"w.example.com"}, Kind: "static", Root: "/srv/w", TLS: &TLS{DNS: &DNS{Provider: "cloudflare", APIToken: "tok"}}}

	// No build sent (an older control plane): applied over HTTP, the result says why.
	m, f, fc, _, build := rateLimitSetup(t, unit, stockModules)
	r := applied(t, m, dns)
	if !strings.Contains(r.RateLimitsRefused, "dns.providers.cloudflare") || loadedDNSChallenge(fc) || f.Ran("systemctl") {
		t.Fatalf("%+v", r)
	}
	if r.Routes == 0 {
		t.Fatal("no routes applied")
	}

	// With the build: installed, and the certificate keeps its DNS-01 challenge.
	dns.CaddyBuild = build
	if r := applied(t, m, dns); r.RateLimitsRefused != "" || !loadedDNSChallenge(fc) || !f.Ran("systemctl") {
		t.Fatalf("%+v %v", r, f.Lines())
	}

	// A Caddy that has the provider needs nothing.
	m, f, fc, _, _ = rateLimitSetup(t, unit, stockModules+"dns.providers.cloudflare\n")
	dns.CaddyBuild = nil
	if r := applied(t, m, dns); r.RateLimitsRefused != "" || !loadedDNSChallenge(fc) || f.Ran("systemctl") {
		t.Fatalf("%+v", r)
	}
}

func TestFalakModulesFallbackServesPreviewHostsOverHTTP(t *testing.T) {
	p := Payload{
		WildcardCertificates: []WildcardCertificate{{Subject: "*.prv.example.com", DNS: DNS{Provider: "cloudflare", APIToken: "t"}}},
		Sites:                []Site{{ID: "p", Domains: []string{"pr-1.prv.example.com"}, Kind: "static", Root: "/r", TLS: &TLS{Mode: "wildcard"}}},
	}
	if got := p.neededModules(); !slices.Equal(got, []string{"dns.providers.cloudflare"}) {
		t.Fatalf("needed %v", got)
	}
	plain := p.withoutFalakModules()
	if plain.WildcardCertificates != nil || plain.Sites[0].TLS.Mode != "acme" || p.Sites[0].TLS.Mode != "wildcard" {
		t.Fatalf("%+v (original %+v)", plain.Sites[0].TLS, p.Sites[0].TLS)
	}
	if _, err := Render(plain, ""); err != nil {
		t.Fatal(err)
	}
}
