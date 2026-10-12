package cli

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/cli/api"
)

const dbID = "01jdb000000000000000000000"

// dbHarness serves the /api/v1/databases/instances endpoints and records delete bodies.
func dbHarness(t *testing.T) (*harness, *[]map[string]any) {
	var deletes []map[string]any
	reply := func(w http.ResponseWriter, code int, v any) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(code)
		_ = json.NewEncoder(w).Encode(v)
	}
	two := 2
	shop := api.DatabaseInstance{ID: dbID, Name: "shop", Engine: "postgresql", Version: "17", Status: "active", ServerName: "db-1", MemoryMB: 512, DatabasesCount: &two}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer "+token {
			reply(w, 401, map[string]string{"message": "Unauthenticated."})
			return
		}
		switch {
		case r.URL.Path == api.PathDatabaseInstances && r.Method == http.MethodGet:
			reply(w, 200, map[string]any{"data": []api.DatabaseInstance{shop}})
		case r.URL.Path == api.Path(api.PathDatabaseInstance, "instance", dbID) && r.Method == http.MethodGet:
			reply(w, 200, map[string]any{"data": shop})
		case r.URL.Path == api.Path(api.PathDatabaseInstance, "instance", dbID) && r.Method == http.MethodDelete:
			var body map[string]any
			_ = json.NewDecoder(r.Body).Decode(&body)
			deletes = append(deletes, body)
			if body["confirm"] != "shop" {
				reply(w, 422, map[string]any{"message": "Type the database server name to confirm.", "errors": map[string][]string{"confirm": {"Type the database server name to confirm."}}})
				return
			}
			deleting := shop
			deleting.Status = "deleting"
			reply(w, 202, map[string]any{"data": deleting})
		default:
			reply(w, 404, map[string]string{"message": "Not Found"})
		}
	}))
	t.Cleanup(srv.Close)
	h := newHarness(t, nil)
	h.env["FALAK_URL"] = srv.URL
	h.env["FALAK_TOKEN"] = token
	return h, &deletes
}

func TestDBList(t *testing.T) {
	h, _ := dbHarness(t)
	if code := h.run("db", "list"); code != 0 || !strings.Contains(h.out.String(), "shop") || !strings.Contains(h.out.String(), "postgresql 17") {
		t.Fatalf("db list: %d %s %s", code, h.out.String(), h.err.String())
	}
	if code := h.run("db", "list", "--json"); code != 0 || !strings.Contains(h.out.String(), `"id": "`+dbID+`"`) {
		t.Fatalf("db list --json: %d %s", code, h.out.String())
	}
}

func TestDBDelete(t *testing.T) {
	h, deletes := dbHarness(t)

	if code := h.run("db", "delete", "shop"); code != ExitUsage || len(*deletes) != 0 {
		t.Fatalf("no --confirm: %d %s", code, h.err.String())
	}
	if code := h.run("db", "delete", "shop", "--confirm", "nope"); code != ExitError || !strings.Contains(h.err.String(), "Type the database server name") {
		t.Fatalf("mismatch: %d %s", code, h.err.String())
	}
	// By name (resolved through the list), keeping the volume.
	if code := h.run("db", "delete", "shop", "--confirm", "shop"); code != 0 || !strings.Contains(h.out.String(), "deleting; data volume kept") {
		t.Fatalf("delete: %d %s %s", code, h.out.String(), h.err.String())
	}
	if code := h.run("db", "delete", dbID, "--confirm", "shop", "--delete-volume"); code != 0 || !strings.Contains(h.out.String(), "deleted with it") {
		t.Fatalf("delete --delete-volume: %d %s %s", code, h.out.String(), h.err.String())
	}
	got := *deletes
	if len(got) != 3 || got[1]["delete_volume"] != false || got[2]["delete_volume"] != true || got[2]["confirm"] != "shop" {
		t.Fatalf("bodies %+v", got)
	}
	if code := h.run("db", "delete", "missing", "--confirm", "missing"); code != ExitError || !strings.Contains(h.err.String(), `"missing" not found`) {
		t.Fatalf("missing: %d %s", code, h.err.String())
	}
}
