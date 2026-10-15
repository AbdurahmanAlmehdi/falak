package edge

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync/atomic"
	"testing"

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
	for _, w := range []string{`"handler":"rate_limit"`, `"falak_api_0":{"key":"{http.vars.client_ip}","match":[{"path":["/login"]}],"max_events":10,"window":"60s"}`,
		`"falak_api_1":{"key":"{http.vars.client_ip}","match":[{"path":["/api/*"]}],"max_events":600,"window":"60s"}`} {
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

func TestRateLimitRefusals(t *testing.T) {
	franken := runtime.RenderEdgeUnit(runtime.EdgeUnit{Binary: "/usr/local/bin/frankenphp", FrankenPHP: true})
	m, f, _, _, build := rateLimitSetup(t, franken, stockModules)
	if _, err := m.Apply(context.Background(), withRule(payload, build), stream()); err == nil || !strings.Contains(err.Error(), "FrankenPHP") || f.Ran("systemctl") {
		t.Fatal(err)
	}

	unit := runtime.RenderEdgeUnit(runtime.EdgeUnit{Binary: "/usr/bin/caddy"})
	m, _, _, _, build = rateLimitSetup(t, unit, stockModules)
	if _, err := m.Apply(context.Background(), withRule(payload, nil), stream()); err == nil || !strings.Contains(err.Error(), "no Falak Caddy build") {
		t.Fatal(err)
	}
	bad := *build
	bad.SHA256 = strings.Repeat("b", 64)
	if _, err := m.Apply(context.Background(), withRule(payload, &bad), stream()); err == nil {
		t.Fatal("checksum mismatch accepted")
	}
	plain := *build
	plain.URL = strings.Replace(build.URL, "https://", "http://", 1)
	if _, err := m.Apply(context.Background(), withRule(payload, &plain), stream()); err == nil {
		t.Fatal("http build URL accepted")
	}

	// A build lacking a module the current Caddy has: the edge keeps its Caddy.
	m, f, _, dest, build := rateLimitSetup(t, unit, stockModules+"dns.providers.route53\n")
	if _, err := m.Apply(context.Background(), withRule(payload, build), stream()); err == nil || !strings.Contains(err.Error(), "dns.providers.route53") || f.Ran("systemctl") {
		t.Fatal(err)
	}
	if u, _ := m.o.FS.ReadFile(runtime.EdgeUnitPath); strings.Contains(string(u), dest) {
		t.Fatal("unit switched")
	}
}

// The build is checked against the edge's current config before the swap, and an edge that does not come back with it
// is rolled back to the Caddy it ran.
func TestRateLimitSwapIsValidatedAndRolledBack(t *testing.T) {
	unit := runtime.RenderEdgeUnit(runtime.EdgeUnit{Binary: "/usr/bin/caddy"})

	// The current config (persisted by an earlier apply) is rejected by the build: no swap.
	m, f, _, dest, build := rateLimitSetup(t, unit, stockModules)
	if _, err := m.Apply(context.Background(), payload, stream()); err != nil {
		t.Fatal(err)
	}
	f.On(dest+" validate", runner.Result{ExitCode: 1, Stderr: []byte("unknown module")})
	if _, err := m.Apply(context.Background(), withRule(payload, build), stream()); err == nil || !strings.Contains(err.Error(), "rejects the edge's current config") || f.Ran("systemctl restart") {
		t.Fatal(err, f.Lines())
	}

	// The edge does not restart with the build: back to /usr/bin/caddy, restarted, error reported.
	m, f, _, dest, build = rateLimitSetup(t, unit, stockModules)
	var restarts atomic.Int32
	f.OnFunc("systemctl restart falak-edge.service", func(runnertest.Call) (runner.Result, error) {
		if restarts.Add(1) == 1 {
			return runner.Result{ExitCode: 1, Stderr: []byte("Job for falak-edge.service failed")}, nil
		}
		return runner.Result{}, nil
	})
	_, err := m.Apply(context.Background(), withRule(payload, build), stream())
	if err == nil || !strings.Contains(err.Error(), "rolled back to /usr/bin/caddy") || restarts.Load() != 2 {
		t.Fatal(err, restarts.Load())
	}
	u, _ := m.o.FS.ReadFile(runtime.EdgeUnitPath)
	if !strings.Contains(string(u), "ExecStart=/usr/bin/caddy run") || strings.Contains(string(u), dest) {
		t.Fatal(string(u))
	}
}
