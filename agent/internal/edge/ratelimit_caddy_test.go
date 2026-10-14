package edge

import (
	"os"
	"os/exec"
	"path/filepath"
	"testing"
)

// With FALAK_CADDY_BIN pointing at a Caddy build with caddy-ratelimit (the control plane image's edge-caddy stage, or
// `xcaddy build --with github.com/mholt/caddy-ratelimit`), the rendered rate limits pass `caddy validate`.
func TestRateLimitConfigValidatesWithCaddy(t *testing.T) {
	bin := os.Getenv("FALAK_CADDY_BIN")
	if bin == "" {
		t.Skip("FALAK_CADDY_BIN not set")
	}
	p := Payload{Sites: []Site{
		{ID: "shop", Domains: []string{"shop.example.com"}, Kind: "static", Root: t.TempDir(), TLS: &TLS{Mode: "off"},
			DenyIPs: []string{"10.0.0.0/8"}, RateLimits: []RateLimit{{Path: "/login", Events: 10, WindowS: 60}, {Path: "/api/*", Events: 600, WindowS: 60}}},
		{ID: "shop-1", Domains: []string{"shop.example.org"}, Kind: "static", Root: t.TempDir(), TLS: &TLS{Mode: "off"},
			RateLimits: []RateLimit{{Path: "/login", Events: 10, WindowS: 60}, {Path: "/api/*", Events: 600, WindowS: 60}}},
	}}
	cfg, err := Render(p, t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	// Validation must not bind the admin socket of a real edge.
	cfg["admin"] = obj{"disabled": true}
	b, _ := canonical(cfg)
	f := filepath.Join(t.TempDir(), "caddy.json")
	if err := os.WriteFile(f, b, 0o600); err != nil {
		t.Fatal(err)
	}
	if out, err := exec.Command(bin, "validate", "--config", f).CombinedOutput(); err != nil {
		t.Fatalf("caddy validate: %v\n%s", err, out)
	}
}
