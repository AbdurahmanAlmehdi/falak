package cli

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/cli/api"
)

// netHarness serves the net-tools endpoints: a POST starts run r1, which settles as status/exit on the second poll.
func netHarness(t *testing.T, status string, exit int) (*harness, *[]map[string]any, *[]string) {
	var bodies []map[string]any
	var paths []string
	polls := 0
	reply := func(w http.ResponseWriter, code int, v any) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(code)
		_ = json.NewEncoder(w).Encode(v)
	}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case r.Method == http.MethodPost:
			var body map[string]any
			_ = json.NewDecoder(r.Body).Decode(&body)
			bodies = append(bodies, body)
			paths = append(paths, r.URL.Path)
			if body["target"] == "a;b" {
				reply(w, 422, map[string]any{"message": "Enter a hostname or an IP address.", "errors": map[string][]string{"target": {"Enter a hostname or an IP address."}}})
				return
			}
			c := "docker:falak-db-1"
			reply(w, 201, map[string]any{"data": api.NetToolRun{ID: "r1", Tool: "tcp", ServerName: "main-1", Container: &c, Status: "running"}})
		case r.URL.Path == api.Path(api.PathNetToolRun, "run", "r1"):
			polls++
			if polls == 1 {
				if r.URL.Query().Get("after") != "-1" {
					t.Errorf("first poll after=%s", r.URL.Query().Get("after"))
				}
				reply(w, 200, map[string]any{"data": map[string]any{"id": "r1", "status": "running", "lines": []api.OutputLine{{Seq: 0, Data: "attempt 1: connected\n"}}, "last_seq": 0}})
				return
			}
			if r.URL.Query().Get("after") != "0" {
				t.Errorf("second poll after=%s", r.URL.Query().Get("after"))
			}
			reply(w, 200, map[string]any{"data": map[string]any{"id": "r1", "status": status, "exit_code": exit, "lines": []api.OutputLine{{Seq: 1, Data: "1 of 1 connections succeeded\n"}}, "last_seq": 1}})
		default:
			reply(w, 404, map[string]string{"message": "Not Found"})
		}
	}))
	t.Cleanup(srv.Close)
	h := newHarness(t, nil)
	h.env["FALAK_URL"] = srv.URL
	h.env["FALAK_TOKEN"] = token
	return h, &bodies, &paths
}

func TestNetTCPInDatabase(t *testing.T) {
	h, bodies, paths := netHarness(t, "succeeded", 0)
	if code := h.run("net", "tcp", "db.internal", "5432", "--db", "main-db", "--count", "2"); code != 0 {
		t.Fatalf("code %d: %s %s", code, h.out.String(), h.err.String())
	}
	if (*paths)[0] != "/api/v1/databases/instances/main-db/net-tools" {
		t.Fatalf("path %s", (*paths)[0])
	}
	if b := (*bodies)[0]; b["tool"] != "tcp" || b["target"] != "db.internal" || b["port"] != float64(5432) || b["count"] != float64(2) || len(b) != 4 {
		t.Fatalf("body %v", b)
	}
	if h.out.String() != "attempt 1: connected\n1 of 1 connections succeeded\n" || !strings.Contains(h.err.String(), "in docker:falak-db-1 on main-1") {
		t.Fatalf("out %q err %q", h.out.String(), h.err.String())
	}
}

func TestStripControl(t *testing.T) {
	in := "ok\tline\n\x1b]0;pwned\x07\x1b[2Jdone\r\n\u009b31mx\x7f"
	if got := stripControl(in); got != "ok\tline\n]0;pwned[2Jdone\n31mx" {
		t.Fatalf("got %q", got)
	}
}

func TestNetExitCodes(t *testing.T) {
	h, _, _ := netHarness(t, "failed", 2)
	if code := h.run("net", "ping", "example.com", "--server", "main-1"); code != 2 {
		t.Fatalf("failed run: %d", code)
	}
	h, _, _ = netHarness(t, "timed_out", 124)
	if code := h.run("net", "http", "https://example.com/", "--site", "shop", "--on", "main-2", "--service", "web", "--insecure"); code != netExitTimeout {
		t.Fatalf("timeout: %d", code)
	}
}

func TestNetSiteBodyAndUsage(t *testing.T) {
	h, bodies, paths := netHarness(t, "succeeded", 0)
	if code := h.run("net", "dns", "example.com", "--site", "shop", "--service", "web", "--type", "MX"); code != 0 {
		t.Fatalf("code %d %s", code, h.err.String())
	}
	if (*paths)[0] != "/api/v1/sites/shop/net-tools" || (*bodies)[0]["service"] != "web" || (*bodies)[0]["record_type"] != "MX" {
		t.Fatalf("%v %v", *paths, *bodies)
	}
	for _, args := range [][]string{
		{"net", "ping", "a"}, // nowhere
		{"net", "ping", "a", "--server", "x", "--db", "y"},    // two places
		{"net", "ping", "a", "--server", "x", "--on", "main"}, // --on without --site
		{"net", "tcp", "a", "--server", "x"},                  // no port
		{"net", "tcp", "a", "http", "--server", "x"},          // port not a number
	} {
		if code := h.run(args...); code != 2 {
			t.Errorf("%v: code %d", args, code)
		}
	}
	if code := h.run("net", "ping", "a;b", "--server", "x"); code == 0 || !strings.Contains(h.err.String(), "hostname") {
		t.Fatalf("422: %d %s", code, h.err.String())
	}
}
