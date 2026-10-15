package cli

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/cli/api"
)

func captureHarness(t *testing.T, final string) (*harness, *[]map[string]any, *[]string) {
	var bodies []map[string]any
	var paths []string
	polls := 0
	reply := func(w http.ResponseWriter, code int, v any) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(code)
		_ = json.NewEncoder(w).Encode(v)
	}
	capt := func(status string) map[string]any {
		return map[string]any{"data": map[string]any{"id": "c1", "server_name": "main-1", "status": status, "duration_s": 5, "packets_captured": 3, "bytes_captured": 100, "expires_at": "2026-10-16T10:00:00Z"}}
	}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		paths = append(paths, r.Method+" "+r.URL.Path)
		switch {
		case r.Method == http.MethodPost && strings.HasSuffix(r.URL.Path, "/net-captures"):
			var body map[string]any
			_ = json.NewDecoder(r.Body).Decode(&body)
			bodies = append(bodies, body)
			reply(w, 201, capt("queued"))
		case r.URL.Path == api.Path(api.PathNetCapture, "capture", "c1"):
			if polls++; polls == 1 {
				reply(w, 200, capt("capturing"))
				return
			}
			reply(w, 200, capt(final))
		case r.URL.Path == api.Path(api.PathNetCaptureDownload, "capture", "c1"):
			if r.Header.Get("Authorization") != "Bearer "+token {
				w.WriteHeader(401)
				return
			}
			_, _ = w.Write([]byte("PCAPDATA"))
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

func TestNetCaptureDownloads(t *testing.T) {
	h, bodies, paths := captureHarness(t, "ready")
	out := filepath.Join(t.TempDir(), "x.pcap")
	if code := h.run("net", "capture", "--site", "shop", "--service", "web", "--yes", "--duration", "5", "--port", "443", "--protocol", "tcp", "-o", out); code != 0 {
		t.Fatalf("code %d: %s", code, h.err.String())
	}
	if (*paths)[0] != "POST /api/v1/sites/shop/net-captures" {
		t.Fatalf("paths %v", *paths)
	}
	b := (*bodies)[0]
	if b["service"] != "web" || b["duration_s"] != float64(5) || b["acknowledged"] != true || b["filter"].(map[string]any)["port"] != float64(443) {
		t.Fatalf("body %v", b)
	}
	if got, _ := os.ReadFile(out); string(got) != "PCAPDATA" {
		t.Fatalf("file %q", got)
	}
	// An existing file is never overwritten.
	h, _, _ = captureHarness(t, "ready")
	if code := h.run("net", "capture", "--server", "main-1", "--yes", "-o", out); code == 0 {
		t.Fatal("overwrote the file")
	}
}

func TestNetCaptureNeedsConfirmationAndReportsFailure(t *testing.T) {
	h, bodies, _ := captureHarness(t, "failed")
	if code := h.run("net", "capture", "--server", "main-1"); code != 2 || !strings.Contains(h.err.String(), "sensitive data") || len(*bodies) != 0 {
		t.Fatalf("without --yes: %d %s", code, h.err.String())
	}
	if code := h.run("net", "capture", "--server", "main-1", "--db", "x", "--yes"); code != 2 {
		t.Fatalf("two targets: %d", code)
	}
	if code := h.run("net", "capture", "--db", "main-db", "--yes", "-o", filepath.Join(t.TempDir(), "y.pcap")); code != 1 || !strings.Contains(h.err.String(), "failed") {
		t.Fatalf("failed capture: %d %s", code, h.err.String())
	}
}
