package edge

import (
	"bytes"
	"encoding/json"
	"flag"
	"os"
	"path/filepath"
	"reflect"
	"regexp"
	"strings"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/logs"
)

var updateGolden = flag.Bool("update", false, "rewrite testdata/render-golden.json")

// richPayload exercises every part of the renderer a request id could touch.
func richPayload() Payload {
	enc := false
	return Payload{
		ACMEEmail:      "ops@example.com",
		TrustedProxies: []string{"173.245.48.0/20"},
		Sites: []Site{
			{ID: "api", Domains: []string{"api.test"}, RedirectDomains: []string{"www.api.test"}, Kind: "reverse_proxy",
				Upstreams: []Upstream{{Dial: "127.0.0.1:3000"}}, HealthURI: "/up", AccessLog: "api",
				Headers: map[string]string{"X-Frame-Options": "DENY"}, RequestHeaders: map[string]string{"X-Falak-Function": "f"},
				DenyIPs: []string{"10.0.0.0/8"}, Redirects: []Redirect{{From: "/old", To: "/new"}}},
			{ID: "shop", Domains: []string{"shop.test"}, Kind: "php_fpm", Root: "/srv/shop/current/public",
				PHPFPMSocket: "/run/php/shop.sock", AccessLog: "shop", BasicAuth: []BasicAuth{{Username: "u", PasswordHash: "$2y$x"}},
				AllowIPs: []string{"1.2.3.4/32"}, MaxBodyBytes: 1 << 20},
			{ID: "web", Domains: []string{"web.test"}, Kind: "static", Root: "/srv/web", TLS: &TLS{Mode: "off"}, Encode: &enc},
			{ID: "go", Domains: []string{"go.test"}, Kind: "redirect", RedirectTo: "https://api.test"},
		},
	}
}

func renderPayloadJSON(t *testing.T, p Payload) []byte {
	t.Helper()
	cfg, err := Render(p, "/etc/falak/certs")
	if err != nil {
		t.Fatal(err)
	}
	b, err := json.MarshalIndent(cfg, "", "  ")
	if err != nil {
		t.Fatal(err)
	}
	return append(b, '\n')
}

// Without request_id the render is byte-identical to the one before the feature (testdata/render-golden.json was
// written by the renderer of v0.16): servers whose agent lacks edge.request_id, or that never get the flag, keep the
// exact config they run, so applying it changes nothing and Caddy is not reloaded.
func TestRenderWithoutRequestIDIsUnchanged(t *testing.T) {
	golden := filepath.Join("testdata", "render-golden.json")
	got := renderPayloadJSON(t, richPayload())
	if *updateGolden {
		if err := os.WriteFile(golden, got, 0o644); err != nil {
			t.Fatal(err)
		}
	}
	want, err := os.ReadFile(golden)
	if err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(got, want) {
		t.Fatalf("render changed without request_id:\n%s", got)
	}
}

// Caddy matches vars_regexp with Go's regexp against the joined header values: two ids, an overlong or a malformed one
// all fail and are replaced.
func TestRequestIDPatternRejectsJoinedAndLongValues(t *testing.T) {
	re := regexp.MustCompile(logs.RequestIDPattern)
	for value, want := range map[string]bool{
		"01JABCDEF-edge-uuid-0001":             true,
		"abcdefgh,ijklmnop":                    false, // two X-Request-Id headers, joined
		"abcdefgh, ijklmnop":                   false,
		strings.Repeat("a", 128):               true,
		strings.Repeat("a", 129):               false,
		"short":                                false,
		"bad id with spaces":                   false,
		"<script>alert(1)</script>-0000000000": false,
	} {
		if got := re.MatchString(value); got != want {
			t.Errorf("%q: match = %v, want %v", value, got, want)
		}
	}
}

// With request_id every site's subroute starts with the two X-Request-Id routes; nothing else changes.
func TestRenderRequestIDStampsEverySite(t *testing.T) {
	p := richPayload()
	p.RequestID = true
	var with, without map[string]any
	if err := json.Unmarshal(renderPayloadJSON(t, p), &with); err != nil {
		t.Fatal(err)
	}
	if err := json.Unmarshal(renderPayloadJSON(t, richPayload()), &without); err != nil {
		t.Fatal(err)
	}
	stamped := 0
	for _, server := range with["apps"].(map[string]any)["http"].(map[string]any)["servers"].(map[string]any) {
		for _, r := range server.(map[string]any)["routes"].([]any) {
			route := r.(map[string]any)
			if route["@id"] == nil {
				continue // a redirect domain's route: answered before any handler
			}
			handle := route["handle"].([]any)[0].(map[string]any)
			sub := handle["routes"].([]any)
			first, second := sub[0].(map[string]any), sub[1].(map[string]any)
			// The joined header value (two headers: "a,b") must match, so a second id never slips through.
			valid := map[string]any{"vars_regexp": map[string]any{"{http.request.header.X-Request-Id}": map[string]any{"pattern": logs.RequestIDPattern}}}
			// The app's own response id is never overwritten.
			absent := map[string]any{"headers": map[string]any{"X-Request-Id": nil}}
			wantFirst := map[string]any{
				"match": []any{map[string]any{"not": []any{valid}}},
				"handle": []any{map[string]any{"handler": "headers",
					"request":  map[string]any{"set": map[string]any{"X-Request-Id": []any{"{http.request.uuid}"}}},
					"response": map[string]any{"set": map[string]any{"X-Request-Id": []any{"{http.request.uuid}"}}, "require": absent, "deferred": true}}},
			}
			if !reflect.DeepEqual(first, wantFirst) {
				t.Fatalf("first route of %v = %#v", route["@id"], first)
			}
			wantSecond := map[string]any{
				"match": []any{valid},
				"handle": []any{map[string]any{"handler": "headers",
					"response": map[string]any{"set": map[string]any{"X-Request-Id": []any{"{http.request.header.X-Request-Id}"}}, "require": absent, "deferred": true}}},
			}
			if !reflect.DeepEqual(second, wantSecond) {
				t.Fatalf("second route of %v = %#v", route["@id"], second)
			}
			handle["routes"] = sub[2:]
			stamped++
		}
	}
	if stamped != 4 {
		t.Fatalf("stamped %d sites, want 4", stamped)
	}
	if !reflect.DeepEqual(with, without) {
		t.Fatal("request_id changed more than the subroutes' first two routes")
	}
}
