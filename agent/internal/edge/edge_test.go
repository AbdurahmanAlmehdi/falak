package edge

import (
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/json"
	"encoding/pem"
	"io"
	"math/big"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
)

// fakeCaddy implements /load, GET /config/, PATCH /id/<id>/<field>.
type fakeCaddy struct {
	mu           sync.Mutex
	cfg          any
	loads        int
	cacheControl string // of the last /load
}

func (f *fakeCaddy) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	f.mu.Lock()
	defer f.mu.Unlock()
	body, _ := io.ReadAll(r.Body)
	switch {
	case r.Method == "POST" && r.URL.Path == "/load":
		var v any
		if err := json.Unmarshal(body, &v); err != nil {
			http.Error(w, err.Error(), 400)
			return
		}
		f.cfg = v
		f.loads++
		f.cacheControl = r.Header.Get("Cache-Control")
	case r.Method == "GET" && r.URL.Path == "/config/":
		_ = json.NewEncoder(w).Encode(f.cfg)
	case r.Method == "PATCH" && strings.HasPrefix(r.URL.Path, "/id/"):
		parts := strings.SplitN(strings.TrimPrefix(r.URL.Path, "/id/"), "/", 2)
		node := findID(f.cfg, parts[0])
		if node == nil {
			http.Error(w, "unknown id", 404)
			return
		}
		var v any
		_ = json.Unmarshal(body, &v)
		node[parts[1]] = v
	default:
		http.NotFound(w, r)
	}
}

func findID(v any, id string) map[string]any {
	switch t := v.(type) {
	case map[string]any:
		if t["@id"] == id {
			return t
		}
		for _, c := range t {
			if r := findID(c, id); r != nil {
				return r
			}
		}
	case []any:
		for _, c := range t {
			if r := findID(c, id); r != nil {
				return r
			}
		}
	}
	return nil
}

func setup(t *testing.T) (*Manager, *fakeCaddy, hostfs.FS) {
	fc := &fakeCaddy{}
	srv := httptest.NewServer(fc)
	t.Cleanup(srv.Close)
	fs := hostfs.FS{Root: t.TempDir()}
	return New(Options{Client: &Client{Base: srv.URL}, FS: fs}), fc, fs
}

func stream() commands.Stream { return commands.NewTestStream("t", &commands.Collector{}) }

var payload = Payload{
	ACMEEmail: "ops@example.com",
	Sites: []Site{
		{ID: "shop", Domains: []string{"shop.example.com"}, RedirectDomains: []string{"www.shop.example.com"}, Kind: "frankenphp", Root: "/srv/falak/sites/shop/current/public",
			Headers: map[string]string{"X-Frame-Options": "DENY"}, BasicAuth: []BasicAuth{{Username: "a", PasswordHash: "$2a$14$x"}}},
		{ID: "legacy", Domains: []string{"legacy.example.com"}, Kind: "php_fpm", Root: "/srv/falak/sites/legacy/current/public", PHPFPMSocket: "/run/php/falak-legacy-8.3.sock", TLS: &TLS{Mode: "custom", CertName: "legacy"}},
		{ID: "api", Domains: []string{"api.example.com"}, Kind: "reverse_proxy", Upstreams: []Upstream{{Dial: "127.0.0.1:3000"}}, HealthURI: "/health", DenyIPs: []string{"10.0.0.0/8"}},
		{ID: "intranet", Domains: []string{"intranet.lan"}, Kind: "static", Root: "/srv/static", TLS: &TLS{Mode: "off"}},
	},
}

func TestApplyIdempotentAndPersisted(t *testing.T) {
	m, fc, fs := setup(t)
	r1, err := m.Apply(context.Background(), payload, stream())
	if err != nil {
		t.Fatal(err)
	}
	if !r1.(ApplyResult).Changed || fc.loads != 1 || r1.(ApplyResult).Routes != 4 {
		t.Fatalf("first apply %+v loads=%d", r1, fc.loads)
	}
	r2, err := m.Apply(context.Background(), payload, stream())
	if err != nil {
		t.Fatal(err)
	}
	if r2.(ApplyResult).Changed || fc.loads != 1 {
		t.Fatalf("second apply must be a no-op: %+v loads=%d", r2, fc.loads)
	}
	if r1.(ApplyResult).ConfigSHA256 != r2.(ApplyResult).ConfigSHA256 {
		t.Fatal("hash unstable")
	}
	b, err := fs.ReadFile("/etc/falak/caddy/bootstrap.json")
	if err != nil || !strings.Contains(string(b), `"listen": "unix//run/falak-edge/admin.sock|0600"`) {
		t.Fatalf("bootstrap config not persisted: %v", err)
	}
	s := string(b)
	for _, want := range []string{`"handler": "php"`, `"resolve_root_symlink": true`, `"dial": "unix//run/php/falak-legacy-8.3.sock"`,
		`"skip_certificates"`, `/etc/falak/certs/legacy.crt`, `"falak_http"`, `"@id": "falak-upstreams-api"`, `"email": "ops@example.com"`,
		`https://shop.example.com{http.request.uri}`, `"client_ip"`, `"X-Frame-Options"`, `"http_basic"`} {
		if !strings.Contains(s, want) {
			t.Errorf("rendered config missing %s", want)
		}
	}
	// Changing desired state triggers a new load.
	p2 := payload
	p2.Sites = payload.Sites[:1]
	if r, _ := m.Apply(context.Background(), p2, stream()); !r.(ApplyResult).Changed || fc.loads != 2 {
		t.Fatal("changed payload not loaded")
	}
}

func TestSetUpstreamsPatchesAndPersists(t *testing.T) {
	m, _, fs := setup(t)
	if _, err := m.Apply(context.Background(), payload, stream()); err != nil {
		t.Fatal(err)
	}
	if err := m.SetUpstreams(context.Background(), "api", []string{"127.0.0.1:3001"}); err != nil {
		t.Fatal(err)
	}
	b, _ := fs.ReadFile("/etc/falak/caddy/bootstrap.json")
	if !strings.Contains(string(b), "127.0.0.1:3001") || strings.Contains(string(b), "127.0.0.1:3000") {
		t.Fatal("patched upstream not persisted")
	}
	if err := m.SetUpstreams(context.Background(), "nope", []string{"x:1"}); err == nil {
		t.Fatal("unknown route must fail")
	}
}

func TestRenderValidation(t *testing.T) {
	bad := []Payload{
		{Sites: []Site{{ID: "a", Domains: []string{"x.com"}, Kind: "php_fpm", Root: "/r"}}},
		{Sites: []Site{{ID: "a", Domains: []string{"x.com"}, Kind: "static", Root: "/r"}, {ID: "b", Domains: []string{"x.com"}, Kind: "static", Root: "/r"}}},
		{Sites: []Site{{ID: "a", Domains: []string{"x.com"}, Kind: "reverse_proxy"}}},
		{Sites: []Site{{ID: "a", Domains: []string{"x.com"}, Kind: "static", Root: "/r", TLS: &TLS{Mode: "custom"}}}},
		{Sites: []Site{{ID: "a", Domains: []string{"x.com"}, Kind: "teleport"}}},
	}
	for i, p := range bad {
		if _, err := Render(p, "/c"); err == nil {
			t.Errorf("case %d: expected error", i)
		}
	}
	raw, err := Render(Payload{Raw: map[string]any{"admin": map[string]any{"listen": "0.0.0.0:2019"}, "apps": map[string]any{}}}, "/c")
	if err != nil || raw["admin"].(obj)["listen"] != AdminListen {
		t.Fatal("raw config must keep admin local")
	}
}

func selfSigned(t *testing.T, notAfter time.Time) (string, string) {
	k, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	tpl := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "legacy.example.com"}, NotBefore: time.Now().Add(-48 * time.Hour), NotAfter: notAfter, DNSNames: []string{"legacy.example.com"}}
	der, _ := x509.CreateCertificate(rand.Reader, tpl, tpl, &k.PublicKey, k)
	kd, _ := x509.MarshalECPrivateKey(k)
	return string(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der})), string(pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: kd}))
}

func TestInstallCert(t *testing.T) {
	m, _, fs := setup(t)
	c, k := selfSigned(t, time.Now().Add(30*24*time.Hour))
	r, err := m.InstallCert(context.Background(), CertPayload{Name: "legacy", CertPEM: c, KeyPEM: k}, stream())
	if err != nil {
		t.Fatal(err)
	}
	res := r.(CertResult)
	if !res.Changed || len(res.FingerprintSHA256) != 64 {
		t.Fatalf("%+v", res)
	}
	st, _ := os.Stat(fs.P("/etc/falak/certs/legacy.key"))
	if st.Mode().Perm() != 0o600 {
		t.Fatalf("key mode %v", st.Mode().Perm())
	}
	r2, _ := m.InstallCert(context.Background(), CertPayload{Name: "legacy", CertPEM: c, KeyPEM: k}, stream())
	if r2.(CertResult).Changed {
		t.Fatal("reinstall should be unchanged")
	}
	_, otherKey := selfSigned(t, time.Now().Add(time.Hour))
	if _, err := m.InstallCert(context.Background(), CertPayload{Name: "legacy", CertPEM: c, KeyPEM: otherKey}, stream()); err == nil {
		t.Fatal("mismatched key must be rejected")
	}
	exp, ek := selfSigned(t, time.Now().Add(-time.Hour))
	if _, err := m.InstallCert(context.Background(), CertPayload{Name: "old", CertPEM: exp, KeyPEM: ek}, stream()); err == nil {
		t.Fatal("expired cert must be rejected")
	}
	r3, _ := m.InstallCert(context.Background(), CertPayload{Name: "legacy", State: "absent"}, stream())
	if !r3.(CertResult).Changed || fs.Exists("/etc/falak/certs/legacy.crt") {
		t.Fatal("absent did not remove")
	}
}

func TestRenderPathScopedBasicAuth(t *testing.T) {
	cfg, err := Render(Payload{Sites: []Site{{ID: "a", Domains: []string{"a.test"}, Kind: "static", Root: "/srv/a",
		BasicAuth: []BasicAuth{
			{Username: "admin", PasswordHash: "$2y$h1", Path: "/admin/*"},
			{Username: "all", PasswordHash: "$2y$h2"},
			{Username: "ops", PasswordHash: "$2y$h3", Path: "/admin/*"},
		}}}}, "/etc/falak/certs")
	if err != nil {
		t.Fatal(err)
	}
	b, _ := json.Marshal(cfg)
	var got struct {
		Apps struct {
			HTTP struct {
				Servers map[string]struct {
					Routes []struct {
						Handle []struct {
							Routes []map[string]any `json:"routes"`
						} `json:"handle"`
					} `json:"routes"`
				} `json:"servers"`
			} `json:"http"`
		} `json:"apps"`
	}
	if err := json.Unmarshal(b, &got); err != nil {
		t.Fatal(err)
	}
	sub := got.Apps.HTTP.Servers["falak"].Routes[0].Handle[0].Routes
	var auth []map[string]any
	for _, r := range sub {
		if strings.Contains(mustJSON(r), `"authentication"`) {
			auth = append(auth, r)
		}
	}
	if len(auth) != 2 {
		t.Fatalf("want 2 auth routes, got %d: %v", len(auth), auth)
	}
	if _, ok := auth[0]["match"]; ok || !strings.Contains(mustJSON(auth[0]), `"all"`) {
		t.Fatalf("site-wide auth first without matcher: %v", auth[0])
	}
	if !strings.Contains(mustJSON(auth[1]["match"]), `"/admin/*"`) || !strings.Contains(mustJSON(auth[1]), `"ops"`) {
		t.Fatalf("path auth: %v", auth[1])
	}
}

func TestRenderDNSChallenge(t *testing.T) {
	cfg, err := Render(Payload{ACMEEmail: "ops@example.com", Sites: []Site{
		{ID: "w", Domains: []string{"*.example.com"}, Kind: "static", Root: "/srv/w", TLS: &TLS{Mode: "acme", DNS: &DNS{Provider: "cloudflare", APIToken: "tok"}}},
		{ID: "x", Domains: []string{"x.test"}, Kind: "static", Root: "/srv/x"},
	}}, "/etc/falak/certs")
	if err != nil {
		t.Fatal(err)
	}
	s := mustJSON(cfg)
	for _, want := range []string{`"challenges":{"dns":{"provider":{"api_token":"{env.` + TokenEnv("tok") + `}","name":"cloudflare"}}}`, `"subjects":["*.example.com"]`, `"subjects":["x.test"]`, `"email":"ops@example.com"`} {
		if !strings.Contains(s, want) {
			t.Fatalf("missing %s in %s", want, s)
		}
	}
	if _, err := Render(Payload{Sites: []Site{{ID: "w", Domains: []string{"*.e.com"}, Kind: "static", Root: "/r", TLS: &TLS{DNS: &DNS{Provider: "cloudflare"}}}}}, ""); err == nil {
		t.Fatal("want error for missing api token")
	}
}

func TestRenderWildcardCertificateServesPreviewHosts(t *testing.T) {
	wild := []WildcardCertificate{{Subject: "*.prv.example.com", DNS: DNS{Provider: "cloudflare", APIToken: "tok"}}}
	cfg, err := Render(Payload{ACMEEmail: "ops@example.com", WildcardCertificates: wild, Sites: []Site{
		{ID: "p", Domains: []string{"pr-7-web.prv.example.com"}, Kind: "static", Root: "/srv/p", TLS: &TLS{Mode: "wildcard"}},
	}}, "")
	if err != nil {
		t.Fatal(err)
	}
	s := mustJSON(cfg)
	for _, want := range []string{
		`"automate":["*.prv.example.com"]`,
		`"subjects":["*.prv.example.com"]`,
		`"challenges":{"dns":{"provider":{"api_token":"{env.` + TokenEnv("tok") + `}","name":"cloudflare"}}}`,
		`"skip_certificates":["pr-7-web.prv.example.com"]`,
	} {
		if !strings.Contains(s, want) {
			t.Fatalf("missing %s in %s", want, s)
		}
	}
	if strings.Contains(s, `"subjects":["pr-7-web.prv.example.com"]`) {
		t.Fatalf("a preview host got its own certificate: %s", s)
	}
	for name, p := range map[string]Payload{
		"not covered": {WildcardCertificates: wild, Sites: []Site{{ID: "p", Domains: []string{"shop.example.com"}, Kind: "static", Root: "/r", TLS: &TLS{Mode: "wildcard"}}}},
		"two labels":  {WildcardCertificates: wild, Sites: []Site{{ID: "p", Domains: []string{"a.b.prv.example.com"}, Kind: "static", Root: "/r", TLS: &TLS{Mode: "wildcard"}}}},
		"no token":    {WildcardCertificates: []WildcardCertificate{{Subject: "*.prv.example.com", DNS: DNS{Provider: "cloudflare"}}}},
		"bad subject": {WildcardCertificates: []WildcardCertificate{{Subject: "prv.example.com", DNS: DNS{Provider: "cloudflare", APIToken: "t"}}}},
	} {
		if _, err := Render(p, ""); err == nil {
			t.Fatalf("%s: want an error", name)
		}
	}
}

func TestDNSTokensNeverReachTheConfigAndRestartTheEdgeWhenTheyChange(t *testing.T) {
	m, _, fs := setup(t)
	restarts := 0
	m.o.RestartEdge = func(context.Context) error { restarts++; return nil }
	const token = "cf-secret-token-0123456789"
	p := Payload{ACMEEmail: "ops@example.com",
		WildcardCertificates: []WildcardCertificate{{Subject: "*.prv.example.com", DNS: DNS{Provider: "cloudflare", APIToken: token}}},
		Sites:                []Site{{ID: "p", Domains: []string{"pr-1-web.prv.example.com"}, Kind: "static", Root: "/srv/p", TLS: &TLS{Mode: "wildcard"}}}}

	if _, err := m.Apply(context.Background(), p, stream()); err != nil {
		t.Fatal(err)
	}
	cfg, _ := m.o.Client.Config(context.Background())
	persisted, _ := fs.ReadFile("/etc/falak/caddy/bootstrap.json")
	for name, b := range map[string][]byte{"running config": cfg, "persisted config": persisted} {
		if strings.Contains(string(b), token) {
			t.Fatalf("%s holds the token", name)
		}
	}
	env, err := fs.ReadFile(m.EnvPath())
	if err != nil || !strings.Contains(string(env), TokenEnv(token)+"="+token) {
		t.Fatalf("env file: %v %q", err, env)
	}
	if info, _ := os.Stat(fs.P(m.EnvPath())); info.Mode().Perm() != 0o600 {
		t.Fatalf("env file mode %v", info.Mode().Perm())
	}
	if restarts != 1 {
		t.Fatalf("restarts = %d, want 1", restarts)
	}
	// Same tokens: no restart.
	if _, err := m.Apply(context.Background(), p, stream()); err != nil || restarts != 1 {
		t.Fatalf("second apply: %v restarts=%d", err, restarts)
	}
	if got := p.Secrets(); len(got) != 1 || got[0] != token {
		t.Fatalf("payload secrets %v", got)
	}
}

func TestAdminListensOnAUnixSocketAndTheClientDialsIt(t *testing.T) {
	cfg, err := Render(Payload{Sites: []Site{}}, "")
	if err != nil {
		t.Fatal(err)
	}
	if cfg["admin"].(obj)["listen"] != "unix//run/falak-edge/admin.sock|0600" {
		t.Fatalf("admin listen %v", cfg["admin"])
	}
	// Short path: unix socket paths are limited to ~100 bytes.
	dir, err := os.MkdirTemp("/tmp", "edge")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { os.RemoveAll(dir) })
	sock := filepath.Join(dir, "a.sock")
	l, err := net.Listen("unix", sock)
	if err != nil {
		t.Fatal(err)
	}
	var host string
	// As Caddy: on a unix socket listener no origin is allowed, and one is checked whenever the request carries it.
	srv := &http.Server{Handler: http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		host = r.Host
		if o := r.Header.Get("Origin"); o != "" {
			http.Error(w, `{"error":"client is not allowed to access from origin '`+o+`'"}`, http.StatusForbidden)
			return
		}
		_, _ = w.Write([]byte("null"))
	})}
	go func() { _ = srv.Serve(l) }()
	t.Cleanup(func() { _ = srv.Close() })

	c := &Client{Base: "unix://" + sock, Fallback: "http://127.0.0.1:1"}
	if _, err := c.Config(context.Background()); err != nil || host != "localhost" {
		t.Fatalf("config over the socket: %v host=%q", err, host)
	}
	if err := c.Load(context.Background(), []byte(`{}`)); err != nil {
		t.Fatalf("load over the socket: %v", err)
	}
	// No socket yet (a Caddy still on its old config): the TCP fallback, which wants a matching Origin.
	var origin string
	tcp := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { origin = r.Header.Get("Origin"); _, _ = w.Write([]byte("null")) }))
	t.Cleanup(tcp.Close)
	missing := &Client{Base: "unix://" + filepath.Join(dir, "missing.sock"), Fallback: tcp.URL}
	if _, err := missing.Config(context.Background()); err != nil || origin != tcp.URL {
		t.Fatalf("fallback: %v origin=%q", err, origin)
	}
}

func mustJSON(v any) string {
	b, _ := json.Marshal(v)
	return string(b)
}

func TestApplyServesPlaceholderUntilFirstDeployAndKeepsRealCurrent(t *testing.T) {
	m, fc, fs := setup(t)
	// "legacy" was already deployed: its current link must be left alone.
	if err := fs.MkdirAll("/srv/falak/sites/legacy/releases/01J9ZT8K3M4N5P6Q7R8S9T0V1W/public", 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink("releases/01J9ZT8K3M4N5P6Q7R8S9T0V1W", fs.P("/srv/falak/sites/legacy/current")); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Apply(context.Background(), payload, stream()); err != nil || fc.loads != 1 {
		t.Fatalf("apply: %v loads=%d", err, fc.loads)
	}

	link, err := os.Readlink(fs.P("/srv/falak/sites/shop/current"))
	if err != nil || link != filepath.Join("releases", PlaceholderRelease) {
		t.Fatalf("shop current -> %q (%v)", link, err)
	}
	page, err := fs.ReadFile("/srv/falak/sites/shop/current/public/index.php")
	if err != nil || !strings.Contains(string(page), "503") {
		t.Fatalf("placeholder page: %v %q", err, page)
	}
	if link, _ := os.Readlink(fs.P("/srv/falak/sites/legacy/current")); link != "releases/01J9ZT8K3M4N5P6Q7R8S9T0V1W" {
		t.Fatalf("deployed site's current was replaced: %q", link)
	}
	if _, err := os.Stat(fs.P("/srv/static")); !os.IsNotExist(err) {
		t.Fatal("static sites need no placeholder")
	}
}

func TestReloadFrankenPHPForceReloadsTheRunningConfig(t *testing.T) {
	m, fc, _ := setup(t)
	if _, err := m.Apply(context.Background(), payload, stream()); err != nil {
		t.Fatal(err)
	}
	if err := m.o.Client.ReloadFrankenPHP(context.Background()); err != nil {
		t.Fatal(err)
	}
	// Caddy ignores an identical /load unless asked to revalidate; that re-resolves `current`.
	if fc.loads != 2 || fc.cacheControl != "must-revalidate" {
		t.Fatalf("loads=%d cache-control=%q", fc.loads, fc.cacheControl)
	}
}

// Laravel Octane: reverse_proxy with a root serves existing assets directly (never PHP sources, dotfiles or
// directories) and proxies the rest, retrying the upstream for try_duration_s while Octane restarts.
func TestRenderOctaneProxyWithStaticPassthrough(t *testing.T) {
	root := "/srv/falak/sites/shop/current/public"
	cfg, err := Render(Payload{Sites: []Site{{
		ID: "shop", Domains: []string{"shop.test"}, Kind: "reverse_proxy", Root: root,
		Upstreams: []Upstream{{Dial: "127.0.0.1:8123"}}, TryDurationS: 30,
		Headers: map[string]string{"X-Frame-Options": "DENY"},
	}}}, "/etc/falak/certs")
	if err != nil {
		t.Fatal(err)
	}
	b, _ := json.Marshal(cfg)
	s := string(b)
	for _, want := range []string{
		`{"handler":"vars","root":"` + root + `"}`,
		`"file":{"root":"` + root + `","try_files":["{http.request.uri.path}"]}`,
		`"not":[{"path":["*.php","*/","/.*","*/.*"]}]`,
		`{"handler":"file_server","hide":[".env",".git"]}`,
		`"upstreams":[{"dial":"127.0.0.1:8123"}]`,
		`"try_duration":"30s"`,
		`"X-Frame-Options":["DENY"]`,
	} {
		if !strings.Contains(s, want) {
			t.Errorf("missing %s in %s", want, s)
		}
	}
	if strings.Contains(s, `"handler":"php"`) || strings.Contains(s, `"protocol":"fastcgi"`) {
		t.Errorf("octane route must not execute PHP itself: %s", s)
	}
	// Static route comes before the proxy, and the headers before both.
	if strings.Index(s, `"file_server"`) > strings.Index(s, `"reverse_proxy"`) || strings.Index(s, `"X-Frame-Options"`) > strings.Index(s, `"file_server"`) {
		t.Errorf("wrong route order: %s", s)
	}

	// Without a root (containers, Node): plain proxy with the default 5s retry window, no file server.
	cfg, err = Render(Payload{Sites: []Site{{ID: "api", Domains: []string{"api.test"}, Kind: "reverse_proxy", Upstreams: []Upstream{{Dial: "127.0.0.1:3000"}}}}}, "/etc/falak/certs")
	if err != nil {
		t.Fatal(err)
	}
	b, _ = json.Marshal(cfg)
	if s := string(b); strings.Contains(s, "file_server") || !strings.Contains(s, `"try_duration":"5s"`) {
		t.Errorf("plain proxy changed: %s", s)
	}
}

func TestRenderStaticSiteFallbacks(t *testing.T) {
	root := "/srv/falak/sites/web/current"
	cfg, err := Render(Payload{Sites: []Site{{ID: "web", Domains: []string{"web.test"}, Kind: "static", Root: root}}}, "/etc/falak/certs")
	if err != nil {
		t.Fatal(err)
	}
	b, _ := json.Marshal(cfg)
	s := string(b)
	// Route order: existing files, the 404 page, the SPA fallback (matchers print after their handlers).
	matchers := []string{
		`"file":{"root":"` + root + `","try_files":["{http.request.uri.path}","{http.request.uri.path}/"]}`,
		`"file":{"root":"` + root + `","try_files":["/404.html"]}`,
		`"file":{"root":"` + root + `","try_files":["/index.html"]},"not":[{"path_regexp":{"pattern":"\\.[A-Za-z0-9]+$"}}]`,
	}
	last := -1
	for _, want := range matchers {
		i := strings.Index(s, want)
		if i < 0 || i < last {
			t.Fatalf("missing or out of order: %s in %s", want, s)
		}
		last = i
	}
	for _, want := range []string{
		`{"handler":"rewrite","uri":"/404.html"},{"handler":"file_server","hide":[".env",".git"],"status_code":"404"}`,
		`{"handler":"rewrite","uri":"/index.html"},{"handler":"file_server","hide":[".env",".git"]}`,
	} {
		if !strings.Contains(s, want) {
			t.Fatalf("missing %s in %s", want, s)
		}
	}
}

func TestRenderPerSiteAccessLogs(t *testing.T) {
	cfg, err := Render(Payload{Sites: []Site{
		{ID: "shop", Domains: []string{"shop.test"}, RedirectDomains: []string{"www.shop.test"}, Kind: "frankenphp", Root: "/srv/falak/sites/shop/current/public", AccessLog: "shop"},
		{ID: "shop-1", Domains: []string{"shop.lan"}, Kind: "frankenphp", Root: "/srv/falak/sites/shop/current/public", AccessLog: "shop", TLS: &TLS{Mode: "off"}},
		{ID: "api", Domains: []string{"api.test"}, Kind: "reverse_proxy", Upstreams: []Upstream{{Dial: "127.0.0.1:3000"}}},
	}}, "/c")
	if err != nil {
		t.Fatal(err)
	}
	b, _ := json.Marshal(cfg)
	var got struct {
		Apps struct {
			HTTP struct {
				Servers map[string]struct {
					Logs struct {
						LoggerNames       map[string][]string `json:"logger_names"`
						SkipUnmappedHosts bool                `json:"skip_unmapped_hosts"`
					} `json:"logs"`
				} `json:"servers"`
			} `json:"http"`
		} `json:"apps"`
		Logging struct {
			Logs map[string]struct {
				Writer struct {
					Output   string `json:"output"`
					Filename string `json:"filename"`
				} `json:"writer"`
				Encoder struct {
					Format string `json:"format"`
				} `json:"encoder"`
				Include []string `json:"include"`
				Exclude []string `json:"exclude"`
			} `json:"logs"`
		} `json:"logging"`
	}
	if err := json.Unmarshal(b, &got); err != nil {
		t.Fatal(err)
	}
	tls := got.Apps.HTTP.Servers["falak"].Logs
	if !tls.SkipUnmappedHosts || len(tls.LoggerNames) != 2 || tls.LoggerNames["shop.test"][0] != "falak-access-shop" || tls.LoggerNames["www.shop.test"][0] != "falak-access-shop" {
		t.Fatalf("tls server logs = %+v", tls)
	}
	if _, ok := tls.LoggerNames["api.test"]; ok {
		t.Fatal("sites without access_log are not logged")
	}
	if plain := got.Apps.HTTP.Servers["falak_http"].Logs; plain.LoggerNames["shop.lan"][0] != "falak-access-shop" {
		t.Fatalf("plain server logs = %+v", plain)
	}
	l := got.Logging.Logs["falak-access-shop"]
	if l.Writer.Output != "file" || l.Writer.Filename != "/var/log/falak/access/shop.log" || l.Encoder.Format != "json" ||
		len(l.Include) != 1 || l.Include[0] != "http.log.access.falak-access-shop" {
		t.Fatalf("logger = %+v", l)
	}
	if d := got.Logging.Logs["default"]; len(d.Exclude) != 1 || d.Exclude[0] != "http.log.access" {
		t.Fatalf("default logger must not duplicate access entries: %+v", d)
	}
	if len(got.Logging.Logs) != 2 {
		t.Fatalf("loggers: %v", got.Logging.Logs)
	}

	// No access logs: no logging section at all.
	cfg, _ = Render(Payload{Sites: []Site{{ID: "api", Domains: []string{"api.test"}, Kind: "reverse_proxy", Upstreams: []Upstream{{Dial: "127.0.0.1:3000"}}}}}, "/c")
	if _, ok := cfg["logging"]; ok {
		t.Fatal("unexpected logging section")
	}
	if _, err := Render(Payload{Sites: []Site{{ID: "x", Domains: []string{"x.test"}, Kind: "static", Root: "/r", AccessLog: "../etc"}}}, "/c"); err == nil {
		t.Fatal("invalid access_log name accepted")
	}
}

func TestApplyCreatesAccessLogDir(t *testing.T) {
	m, _, fs := setup(t)
	p := Payload{Sites: []Site{{ID: "web", Domains: []string{"web.test"}, Kind: "static", Root: "/srv/web", AccessLog: "web"}}}
	if _, err := m.Apply(context.Background(), p, stream()); err != nil {
		t.Fatal(err)
	}
	fi, err := os.Stat(fs.P("/var/log/falak/access"))
	if err != nil || !fi.IsDir() || fi.Mode().Perm() != 0o750 {
		t.Fatalf("access log dir: %v %v", fi, err)
	}
}

func TestTrustedProxiesSetClientIPHeaders(t *testing.T) {
	p := Payload{Sites: []Site{{ID: "shop", Domains: []string{"shop.example.com"}, Kind: "static", Root: "/srv/shop", DenyIPs: []string{"203.0.113.9"}}},
		TrustedProxies: []string{"173.245.48.0/20", "2400:cb00::/32"}}
	cfg, err := Render(p, "/etc/falak/certs")
	if err != nil {
		t.Fatal(err)
	}
	b, _ := json.Marshal(cfg)
	s := string(b)
	for _, want := range []string{`"trusted_proxies":{"ranges":["173.245.48.0/20","2400:cb00::/32"],"source":"static"}`, `"client_ip_headers":["CF-Connecting-IP"]`, `"client_ip":{"ranges":["203.0.113.9"]}`} {
		if !strings.Contains(s, want) {
			t.Errorf("config missing %s", want)
		}
	}
	// Without trusted proxies nothing changes for the servers.
	cfg, _ = Render(Payload{Sites: p.Sites}, "/etc/falak/certs")
	b, _ = json.Marshal(cfg)
	if strings.Contains(string(b), "trusted_proxies") || strings.Contains(string(b), "client_ip_headers") {
		t.Error("trusted_proxies set without ranges")
	}
}

func TestHTTPChallengeOnlyDisablesTLSALPN(t *testing.T) {
	p := Payload{Sites: []Site{{ID: "shop", Domains: []string{"shop.example.com"}, Kind: "static", Root: "/srv/shop", TLS: &TLS{Mode: "acme", HTTPChallengeOnly: true}}}}
	cfg, err := Render(p, "/etc/falak/certs")
	if err != nil {
		t.Fatal(err)
	}
	b, _ := json.Marshal(cfg)
	if !strings.Contains(string(b), `"challenges":{"tls-alpn":{"disabled":true}}`) || !strings.Contains(string(b), `"subjects":["shop.example.com"]`) {
		t.Fatalf("no HTTP-01 only policy: %s", b)
	}
}

// Functions: Caddy proxies to the function gateway and names the function in a request header.
func TestRenderReverseProxyRequestHeaders(t *testing.T) {
	cfg, err := Render(Payload{Sites: []Site{{ID: "fn", Domains: []string{"fn.test"}, Kind: "reverse_proxy",
		Upstreams: []Upstream{{Dial: "127.0.0.1:7070"}}, RequestHeaders: map[string]string{"X-Falak-Function": "hello"}}}}, "/etc/falak/certs")
	if err != nil {
		t.Fatal(err)
	}
	b, _ := json.Marshal(cfg)
	if !strings.Contains(string(b), `"headers":{"request":{"set":{"X-Falak-Function":["hello"]}}}`) {
		t.Fatalf("request header not set on reverse_proxy: %s", b)
	}
}
