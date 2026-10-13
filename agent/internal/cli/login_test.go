package cli

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/cli/api"
)

// browserCP fakes the device flow: `pending` polls answer authorization_pending, then `final` (a token or an error).
func browserCP(t *testing.T, pending int, final string) (*httptest.Server, *[]string) {
	var mu sync.Mutex
	var calls []string
	reply := func(w http.ResponseWriter, code int, v any) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(code)
		_ = json.NewEncoder(w).Encode(v)
	}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		defer mu.Unlock()
		calls = append(calls, r.Method+" "+r.URL.Path)
		var body map[string]string
		_ = json.NewDecoder(r.Body).Decode(&body)
		switch {
		case r.URL.Path == api.PathCliLogin:
			if r.Header.Get("Authorization") != "" || body["client_name"] == "" {
				reply(w, 422, map[string]string{"message": "bad start"})
				return
			}
			reply(w, 201, map[string]any{"data": api.CliLoginStart{DeviceCode: "dev-secret", UserCode: "WDJB-MJHT",
				VerificationURIComplete: "https://panel.test/cli/authorize/WDJB-MJHT", ExpiresIn: 600}})
		case r.URL.Path == api.PathCliLoginToken:
			if body["device_code"] != "dev-secret" {
				reply(w, 400, map[string]string{"error": "expired_token"})
			} else if pending > 0 {
				pending--
				reply(w, 400, map[string]string{"error": "authorization_pending"})
			} else if strings.HasPrefix(final, "tok:") {
				reply(w, 200, map[string]any{"data": map[string]string{"token": strings.TrimPrefix(final, "tok:")}})
			} else {
				reply(w, 400, map[string]string{"error": final})
			}
		case r.URL.Path == api.PathMe:
			if r.Header.Get("Authorization") != "Bearer browser-token" {
				reply(w, 401, map[string]string{"message": "Unauthenticated."})
				return
			}
			reply(w, 200, map[string]any{"data": map[string]any{"user": map[string]string{"email": "ada@acme.test"},
				"organization": map[string]string{"name": "Acme"}, "token": map[string]any{"name": "CLI — ada-laptop", "abilities": []string{"*"}}}})
		case r.URL.Path == api.PathMeToken && r.Method == http.MethodDelete:
			w.WriteHeader(204)
		default:
			reply(w, 404, map[string]string{"message": "Not Found"})
		}
	}))
	t.Cleanup(srv.Close)
	return srv, &calls
}

func (h *harness) runTTY(args ...string) int {
	h.out.Reset()
	h.err.Reset()
	h.app = h.newApp("")
	h.app.Interactive = true
	return h.app.Run(context.Background(), args)
}

func TestBrowserLoginAndLogout(t *testing.T) {
	srv, calls := browserCP(t, 2, "tok:browser-token")
	h := newHarness(t, nil)
	if code := h.runTTY("login", "--url", srv.URL); code != 0 {
		t.Fatalf("login: %d %s", code, h.err.String())
	}
	if h.opened != "https://panel.test/cli/authorize/WDJB-MJHT" || !strings.Contains(h.err.String(), "Your code: WDJB-MJHT") {
		t.Fatalf("opened %q, stderr %s", h.opened, h.err.String())
	}
	if !strings.Contains(h.out.String(), "Logged in to "+srv.URL+" as ada@acme.test (organization Acme)") || strings.Contains(h.out.String()+h.err.String(), "browser-token") {
		t.Fatalf("out = %s", h.out.String())
	}
	if c, _ := LoadCredentials(h.dir); c.Token != "browser-token" {
		t.Fatalf("stored token %q", c.Token)
	}

	if code := h.run("logout"); code != 0 || !strings.Contains(h.out.String(), "the token was revoked") {
		t.Fatalf("logout: %d %s", code, h.out.String())
	}
	if (*calls)[len(*calls)-1] != "DELETE "+api.PathMeToken {
		t.Fatalf("calls = %v", *calls)
	}
}

func TestBrowserLoginNoBrowserAndDenied(t *testing.T) {
	srv, _ := browserCP(t, 0, "access_denied")
	h := newHarness(t, nil)
	if code := h.runTTY("login", "--url", srv.URL, "--no-browser"); code != ExitError || !strings.Contains(h.err.String(), "denied") {
		t.Fatalf("denied: %d %s", code, h.err.String())
	}
	if h.opened != "" || !strings.Contains(h.err.String(), "Open https://panel.test/cli/authorize/WDJB-MJHT on any device") {
		t.Fatalf("--no-browser opened %q: %s", h.opened, h.err.String())
	}

	srv2, _ := browserCP(t, 0, "expired_token")
	if code := h.runTTY("login", "--url", srv2.URL); code != ExitError || !strings.Contains(h.err.String(), "expired") {
		t.Fatalf("expired: %d %s", code, h.err.String())
	}
	if c, _ := LoadCredentials(h.dir); c.Token != "" {
		t.Fatal("credentials saved after a failed login")
	}
}
