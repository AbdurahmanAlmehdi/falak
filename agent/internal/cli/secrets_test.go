package cli

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/cli/api"
)

const (
	secProjectID = "01jpr000000000000000000000"
	secEnvID     = "01jen000000000000000000000"
)

// secretHarness serves projects and an in-memory secret store, recording every write.
func secretHarness(t *testing.T) (*harness, *[]string) {
	var writes []string
	store := map[string]api.Secret{} // id → secret
	reply := func(w http.ResponseWriter, code int, v any) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(code)
		_ = json.NewEncoder(w).Encode(v)
	}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var body map[string]string
		_ = json.NewDecoder(r.Body).Decode(&body)
		switch {
		case r.URL.Path == api.PathProjects:
			reply(w, 200, map[string]any{"data": []api.Project{{ID: secProjectID, Name: "shop", Environments: []api.ProjectEnvironment{
				{ID: secEnvID, Name: "Staging", Slug: "staging"},
			}}}})
		case r.URL.Path == api.PathSecrets && r.Method == http.MethodGet:
			list := []api.Secret{}
			for _, s := range store {
				if s.Scope == r.URL.Query().Get("scope") && s.ScopeID == r.URL.Query().Get("scope_id") {
					list = append(list, s)
				}
			}
			reply(w, 200, map[string]any{"data": list})
		case r.URL.Path == api.PathSecrets && r.Method == http.MethodPost:
			writes = append(writes, "create "+body["scope"]+" "+body["name"]+"="+body["value"])
			s := api.Secret{ID: "01jse00000000000000000000" + string(rune('a'+len(store))), Name: body["name"], Scope: body["scope"], ScopeID: body["scope_id"], CurrentVersion: 1}
			store[s.ID] = s
			reply(w, 201, map[string]any{"data": s})
		case strings.HasSuffix(r.URL.Path, "/value") && r.Method == http.MethodPut:
			id := strings.Split(r.URL.Path, "/")[4]
			s := store[id]
			s.CurrentVersion++
			store[id] = s
			writes = append(writes, "update "+s.Name+"="+body["value"])
			reply(w, 200, map[string]any{"data": s})
		case r.Method == http.MethodDelete:
			id := strings.Split(r.URL.Path, "/")[4]
			writes = append(writes, "delete "+store[id].Name)
			delete(store, id)
			w.WriteHeader(204)
		default:
			reply(w, 404, map[string]string{"message": "Not Found"})
		}
	}))
	t.Cleanup(srv.Close)
	h := newHarness(t, nil)
	h.env["FALAK_URL"] = srv.URL
	h.env["FALAK_TOKEN"] = token
	h.env["FALAK_PROJECT"] = "shop"
	return h, &writes
}

func (h *harness) runIn(stdin string, args ...string) int {
	h.out.Reset()
	h.err.Reset()
	h.app = h.newApp(stdin)
	return h.app.Run(context.Background(), args)
}

func TestSecretSetListDelete(t *testing.T) {
	h, writes := secretHarness(t)

	// stdin value, one trailing newline dropped; set again → a new version, not a duplicate
	if code := h.runIn("sk_live_1\n", "secret", "set", "STRIPE_KEY"); code != 0 || !strings.Contains(h.out.String(), "created in the project") {
		t.Fatalf("set: %d %s %s", code, h.out, h.err)
	}
	if code := h.runIn("sk_live_2", "secret", "set", "STRIPE_KEY"); code != 0 || !strings.Contains(h.out.String(), "updated in the project (version 2)") {
		t.Fatalf("set again: %d %s %s", code, h.out, h.err)
	}
	if strings.Contains(h.out.String(), "sk_live") {
		t.Fatal("a value was printed")
	}
	// -e scopes to the environment
	if code := h.runIn("x", "secret", "set", "STRIPE_KEY", "-e", "staging"); code != 0 || !strings.Contains(h.out.String(), "created in the environment Staging") {
		t.Fatalf("set -e: %d %s %s", code, h.out, h.err)
	}
	// empty value and a missing name are usage errors
	if code := h.runIn("", "secret", "set", "EMPTY"); code != ExitUsage {
		t.Fatalf("empty value: %d", code)
	}
	if code := h.runIn("", "secret", "set"); code != ExitUsage {
		t.Fatalf("no name: %d", code)
	}

	if code := h.run("secret", "list"); code != 0 || !strings.Contains(h.out.String(), "STRIPE_KEY") || strings.Contains(h.out.String(), "sk_live") {
		t.Fatalf("list: %d %s", code, h.out)
	}

	// delete needs --yes without a terminal
	if code := h.run("secret", "delete", "STRIPE_KEY"); code != ExitUsage {
		t.Fatalf("delete without --yes: %d", code)
	}
	if code := h.run("secret", "delete", "STRIPE_KEY", "--yes"); code != 0 {
		t.Fatalf("delete: %d %s", code, h.err)
	}
	if code := h.run("secret", "delete", "STRIPE_KEY", "--yes"); code == 0 {
		t.Fatal("deleting a missing secret succeeded")
	}

	want := []string{"create project STRIPE_KEY=sk_live_1", "update STRIPE_KEY=sk_live_2", "create environment STRIPE_KEY=x", "delete STRIPE_KEY"}
	if strings.Join(*writes, "|") != strings.Join(want, "|") {
		t.Fatalf("writes = %q", *writes)
	}
}

func TestSecretSetFromEnv(t *testing.T) {
	h, writes := secretHarness(t)
	f := filepath.Join(t.TempDir(), ".env")
	_ = os.WriteFile(f, []byte("# comment\nexport A_KEY=\"quoted value\"\n\nB_KEY='x=y'\nEMPTY=\nA_KEY=last wins\r\n"), 0o600)
	if code := h.run("secret", "set", "--from-env", f); code != 0 {
		t.Fatalf("from-env: %d %s", code, h.err)
	}
	want := "create project A_KEY=last wins|create project B_KEY=x=y"
	if strings.Join(*writes, "|") != want {
		t.Fatalf("writes = %q", *writes)
	}
	if code := h.run("secret", "set", "X", "--from-env", f); code != ExitUsage {
		t.Fatalf("name and --from-env: %d", code)
	}
}
