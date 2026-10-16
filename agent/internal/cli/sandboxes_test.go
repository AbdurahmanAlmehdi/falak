package cli

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/coder/websocket"

	"github.com/OthmanHaba/falak/agent/internal/cli/api"
)

const (
	sbxID       = "01jsbx00000000000000000000"
	sbxKilledID = "01jsbx0000000000000000000k"
)

// sbxFake serves the sandbox API and records what it was sent.
type sbxFake struct {
	mu       sync.Mutex
	requests []string          // "METHOD path?query"
	bodies   map[string][]byte // last body per "METHOD path"
	files    map[string][]byte // the sandbox's files
	exec     api.SandboxExecResult
}

func (f *sbxFake) body(key string, v any) {
	f.mu.Lock()
	defer f.mu.Unlock()
	if err := json.Unmarshal(f.bodies[key], v); err != nil {
		panic(key + ": " + err.Error())
	}
}

func sbxHarness(t *testing.T) (*harness, *sbxFake) {
	f := &sbxFake{bodies: map[string][]byte{}, files: map[string][]byte{"/etc/hostname": []byte("brave-otter\n"), "/bin/blob": {0xff, 0x00, 0xfe}, "/var/big.log": []byte("first 5 MB")},
		exec: api.SandboxExecResult{ExitCode: 3, Stdout: "out\n", Stderr: "err\n", DurationMS: 12}}
	reply := func(w http.ResponseWriter, code int, v any) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(code)
		_ = json.NewEncoder(w).Encode(v)
	}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		defer f.mu.Unlock()
		if r.Header.Get("Authorization") != "Bearer "+token {
			reply(w, 401, map[string]string{"message": "Unauthenticated."})
			return
		}
		b, _ := io.ReadAll(r.Body)
		key := r.Method + " " + r.URL.Path
		f.requests = append(f.requests, key+"?"+r.URL.RawQuery)
		f.bodies[key] = b
		running := api.Sandbox{ID: sbxID, Name: "brave-otter", Status: "running", Image: "ubuntu:24.04", Server: &api.SandboxServer{ID: "s1", Name: "main-1"},
			CPUs: 1, MemoryMB: 1024, DiskMB: 10240, Network: "internet", TTLSeconds: 3600,
			ExpiresAt: time.Now().Add(2*time.Hour + 30*time.Second).UTC().Format(time.RFC3339), CreatedBy: &api.SandboxCreator{Type: "token", Name: "ci"}}
		killed := api.Sandbox{ID: sbxKilledID, Name: "brave-otter", Status: "killed", Image: "node:22", MemoryMB: 512, CPUs: 0.5}
		one := api.Path(api.PathSandbox, "sandbox", sbxID)
		files := api.Path(api.PathSandboxFiles, "sandbox", sbxID)
		switch {
		case key == "GET "+api.PathSandboxes:
			reply(w, 200, map[string]any{"data": []api.Sandbox{killed, running}})
		case key == "POST "+api.PathSandboxes:
			reply(w, 201, map[string]any{"data": running}) // wrapped
		case key == "GET "+one:
			reply(w, 200, running) // bare: either shape decodes
		case key == "PATCH "+one:
			running.ExpiresAt = time.Now().Add(3*time.Hour + 30*time.Second).UTC().Format(time.RFC3339)
			reply(w, 200, map[string]any{"data": running})
		case key == "DELETE "+one:
			w.WriteHeader(204)
		case key == "POST "+api.Path(api.PathSandboxExec, "sandbox", sbxID):
			reply(w, 200, f.exec)
		case key == "GET "+files && r.URL.Query().Get("list") == "1":
			reply(w, 200, map[string]any{"entries": []map[string]any{{"name": "hostname", "type": "file", "size": 12}}})
		case key == "GET "+files:
			p := r.URL.Query().Get("path")
			if p == "/etc" {
				reply(w, 422, map[string]any{"message": "The path is a directory.", "errors": map[string][]string{"path": {"The path is a directory."}}})
				return
			}
			data, ok := f.files[p]
			if !ok {
				reply(w, 404, map[string]string{"message": "File not found."})
				return
			}
			w.Header().Set("Content-Type", "application/octet-stream")
			if p == "/var/big.log" {
				w.Header().Set("X-Falak-Truncated", "1")
			}
			_, _ = w.Write(data)
		case key == "PUT "+files:
			f.files[r.URL.Query().Get("path")] = b
			reply(w, 200, map[string]any{"data": map[string]any{"size": len(b)}})
		case strings.HasPrefix(r.URL.Path, api.PathSandboxes+"/"):
			reply(w, 404, map[string]string{"message": "Not Found"})
		default:
			reply(w, 404, map[string]string{"message": "Not Found"})
		}
	}))
	t.Cleanup(srv.Close)
	h := newHarness(t, nil)
	h.env["FALAK_URL"] = srv.URL
	h.env["FALAK_TOKEN"] = token
	h.wd = t.TempDir()
	return h, f
}

func TestSandboxParsers(t *testing.T) {
	for in, want := range map[string]int64{"30m": 1800, "2h": 7200, "1h30m": 5400, "90": 90, "45s": 45} {
		if got, err := parseSeconds(in); err != nil || got != want {
			t.Errorf("parseSeconds(%q) = %d, %v", in, got, err)
		}
	}
	for _, bad := range []string{"", "0", "-5", "soon", "1x"} {
		if _, err := parseSeconds(bad); err == nil {
			t.Errorf("parseSeconds(%q) should fail", bad)
		}
	}
	for in, want := range map[string]int{"512m": 512, "2g": 2048, "1.5GiB": 1536, "768": 768, "10G": 10240} {
		if got, err := parseMB(in); err != nil || got != want {
			t.Errorf("parseMB(%q) = %d, %v", in, got, err)
		}
	}
	for _, bad := range []string{"512k", "lots", "0"} {
		if _, err := parseMB(bad); err == nil {
			t.Errorf("parseMB(%q) should fail", bad)
		}
	}
	for in, want := range map[string][2]string{
		"https://github.com/acme/shop":              {"https://github.com/acme/shop", ""},
		"https://github.com/acme/shop@main":         {"https://github.com/acme/shop", "main"},
		"https://github.com/acme/shop@feature/x":    {"https://github.com/acme/shop", "feature/x"},
		"https://tok@github.com/acme/shop":          {"https://tok@github.com/acme/shop", ""},
		"https://tok@github.com/acme/shop@v1.2":     {"https://tok@github.com/acme/shop", "v1.2"},
		"git@github.com:acme/shop.git":              {"git@github.com:acme/shop.git", ""},
		"git@github.com:acme/shop.git@release/2026": {"git@github.com:acme/shop.git", "release/2026"},
	} {
		if u, ref := splitRepo(in); u != want[0] || ref != want[1] {
			t.Errorf("splitRepo(%q) = %q, %q", in, u, ref)
		}
	}
}

func TestSandboxCreate(t *testing.T) {
	h, f := sbxHarness(t)
	code := h.run("sandbox", "create", "--image", "node:22", "--name", "brave-otter", "--server", "main-1", "--cpus", "2",
		"--memory", "2g", "--disk", "20g", "--ttl", "2h", "--network", "allowlist", "--allow", "registry.npmjs.org", "--allow", "github.com",
		"--env", "A=1", "--env", "B=x=y", "--repo", "https://github.com/acme/shop@main", "--repo-secret", "GH_TOKEN")
	if code != 0 || !strings.Contains(h.out.String(), "brave-otter ("+sbxID+"): running on main-1") {
		t.Fatalf("create: %d %s %s", code, h.out.String(), h.err.String())
	}
	var body map[string]any
	f.body("POST "+api.PathSandboxes, &body)
	want := `{"allow_hosts":["registry.npmjs.org","github.com"],"cpus":2,"disk_mb":20480,"env":{"A":"1","B":"x=y"},"image":"node:22","memory_mb":2048,"name":"brave-otter","network":"allowlist","repo":{"ref":"main","secret":"GH_TOKEN","url":"https://github.com/acme/shop"},"server":"main-1","ttl":7200}`
	if got, _ := json.Marshal(body); string(got) != want {
		t.Fatalf("body\n got %s\nwant %s", got, want)
	}
	if last := f.requests[len(f.requests)-1]; last != "POST /api/v1/sandboxes?wait=1" {
		t.Fatalf("request %s", last)
	}

	// Defaults: an empty body; --wait=false drops ?wait=1.
	if code := h.run("sandbox", "create", "--wait=false", "--json"); code != 0 || !strings.Contains(h.out.String(), `"id": "`+sbxID+`"`) {
		t.Fatalf("create --json: %d %s %s", code, h.out.String(), h.err.String())
	}
	if last := f.requests[len(f.requests)-1]; last != "POST /api/v1/sandboxes?" || string(f.bodies["POST "+api.PathSandboxes]) != "{}" {
		t.Fatalf("defaults: %s %s", last, f.bodies["POST "+api.PathSandboxes])
	}

	for _, bad := range [][]string{{"--memory", "lots"}, {"--ttl", "soon"}, {"--network", "lan"}, {"--allow", "x.com"}, {"--env", "NOEQ"}, {"--repo-secret", "S"}} {
		if code := h.run(append([]string{"sandbox", "create"}, bad...)...); code != ExitUsage {
			t.Errorf("%v: exit %d %s", bad, code, h.err.String())
		}
	}
}

func TestSandboxListShowAndResolve(t *testing.T) {
	h, f := sbxHarness(t)
	if code := h.run("sandbox", "ls"); code != 0 {
		t.Fatalf("ls: %d %s", code, h.err.String())
	}
	lines := strings.Split(strings.TrimSpace(h.out.String()), "\n")
	if len(lines) != 3 || strings.Join(strings.Fields(lines[0]), " ") != "NAME STATUS IMAGE SERVER CPU MEM EXPIRES IN" ||
		strings.Join(strings.Fields(lines[1]), " ") != "brave-otter killed node:22 - 0.5 512m -" ||
		strings.Join(strings.Fields(lines[2]), " ") != "brave-otter running ubuntu:24.04 main-1 1 1g 2h" {
		t.Fatalf("ls:\n%s", h.out.String())
	}
	if code := h.run("sandbox", "ls", "--status", "running"); code != 0 || f.requests[len(f.requests)-1] != "GET /api/v1/sandboxes?status=running" {
		t.Fatalf("ls --status: %d %v", code, f.requests)
	}

	// By name: the running one wins over a killed one of the same name.
	if code := h.run("sandbox", "show", "brave-otter"); code != 0 || !strings.Contains(h.out.String(), "id:          "+sbxID) || !strings.Contains(h.out.String(), "created by:  ci (token)") {
		t.Fatalf("show: %d %s %s", code, h.out.String(), h.err.String())
	}
	// By id: no list request.
	n := len(f.requests)
	if code := h.run("sandbox", "show", sbxID, "--json"); code != 0 || len(f.requests) != n+1 || !strings.Contains(h.out.String(), `"name": "brave-otter"`) {
		t.Fatalf("show id: %d %v %s", code, f.requests[n:], h.out.String())
	}
	if code := h.run("sandbox", "show", "nope"); code != ExitError || !strings.Contains(h.err.String(), `sandbox "nope" not found`) {
		t.Fatalf("missing: %d %s", code, h.err.String())
	}

	if code := h.run("sandbox", "extend", "brave-otter", "1h"); code != 0 || !strings.Contains(h.out.String(), "brave-otter expires in 3h") {
		t.Fatalf("extend: %d %s %s", code, h.out.String(), h.err.String())
	}
	var ext map[string]any
	f.body("PATCH "+api.Path(api.PathSandbox, "sandbox", sbxID), &ext)
	if ext["extend"] != 3600.0 {
		t.Fatalf("extend body %v", ext)
	}

	// kill takes several; one failure doesn't stop the others.
	if code := h.run("sandbox", "kill", "nope", sbxID); code != ExitError || !strings.Contains(h.out.String(), "killed "+sbxID) || !strings.Contains(h.err.String(), "nope:") {
		t.Fatalf("kill: %d %s %s", code, h.out.String(), h.err.String())
	}
	if f.requests[len(f.requests)-1] != "DELETE "+api.Path(api.PathSandbox, "sandbox", sbxID)+"?" {
		t.Fatalf("kill requests %v", f.requests)
	}
}

func TestSandboxExec(t *testing.T) {
	h, f := sbxHarness(t)
	execKey := "POST " + api.Path(api.PathSandboxExec, "sandbox", sbxID)

	// Streams go to their own side, the remote exit code is ours, piped stdin is sent.
	code := h.runStdin("piped input", "sandbox", "exec", "brave-otter", "--timeout", "2m", "--cwd", "/srv", "-e", "K=V", "--", "ls", "-la", "/")
	if code != 3 || h.out.String() != "out\n" || h.err.String() != "err\n" {
		t.Fatalf("exec: %d %q %q", code, h.out.String(), h.err.String())
	}
	var body map[string]any
	f.body(execKey, &body)
	if got, _ := json.Marshal(body); string(got) != `{"cmd":["ls","-la","/"],"cwd":"/srv","env":{"K":"V"},"stdin":"piped input","timeout":120}` {
		t.Fatalf("exec body %s", got)
	}

	// One argument is a shell line; a terminal's stdin isn't read.
	h.out.Reset()
	h.err.Reset()
	h.app = h.newApp("not read")
	h.app.Interactive = true
	if code := h.app.Run(t.Context(), []string{"sandbox", "exec", sbxID, "--", "echo hi | wc -c"}); code != 3 {
		t.Fatalf("exec shell: %d %s", code, h.err.String())
	}
	body = nil
	f.body(execKey, &body)
	if got, _ := json.Marshal(body); string(got) != `{"cmd":"echo hi | wc -c"}` {
		t.Fatalf("exec shell body %s", got)
	}

	f.exec = api.SandboxExecResult{Stdout: "partial", TimedOut: true, Truncated: true}
	if code := h.run("sandbox", "exec", sbxID, "--", "sleep 999"); code != ExitTimeout || !strings.Contains(h.err.String(), "timed out") || !strings.Contains(h.err.String(), "truncated") {
		t.Fatalf("timeout: %d %s", code, h.err.String())
	}
	f.exec = api.SandboxExecResult{Stdout: "ok"}
	if code := h.run("sandbox", "exec", sbxID, "--json", "--", "true"); code != 0 || !strings.Contains(h.out.String(), `"exit_code": 0`) {
		t.Fatalf("json: %d %s", code, h.out.String())
	}

	for _, bad := range [][]string{{sbxID}, {sbxID, "--"}, {sbxID, "--timeout", "5m", "--", "x"}, {"--", "x"}} {
		if code := h.run(append([]string{"sandbox", "exec"}, bad...)...); code != ExitUsage {
			t.Errorf("%v: exit %d %s", bad, code, h.err.String())
		}
	}
}

func TestSandboxSSHAndConsoleFlag(t *testing.T) {
	f := newConsoleFixture(t)
	exit := func(c *websocket.Conn) {
		send(c, websocket.MessageText, `{"t":"ready"}`)
		send(c, websocket.MessageText, `{"t":"exit","reason":"exited","code":0}`)
		_, _, _ = c.Read(context.Background())
	}
	f.script(exit, exit)
	for _, args := range [][]string{{"sandbox", "ssh", sbxID}, {"console", "--sandbox", sbxID}} {
		a, _, errb := f.app(strings.NewReader(""), nil)
		if code := a.Run(context.Background(), args); code != 0 {
			t.Fatalf("%v: exit %d %s", args, code, errb.String())
		}
	}
	want := "POST " + api.Path(api.PathSandboxConsole, "sandbox", sbxID) + " {}"
	if len(f.opened) != 2 || f.opened[0] != want || f.opened[1] != want {
		t.Fatalf("opened %q", f.opened)
	}
	a, _, errb := f.app(strings.NewReader(""), nil)
	if code := a.Run(context.Background(), []string{"console", "acme", "--sandbox", sbxID}); code != ExitUsage {
		t.Fatalf("two targets: %d %s", code, errb.String())
	}
}

func TestSandboxCp(t *testing.T) {
	h, f := sbxHarness(t)
	local := filepath.Join(h.wd, "run.sh")
	if err := os.WriteFile(local, []byte("#!/bin/sh\necho hi\n"), 0o755); err != nil {
		t.Fatal(err)
	}
	// Upload (relative path, into a directory: the base name is kept, and the mode).
	if code := h.run("sandbox", "cp", "run.sh", "sbx:brave-otter:/root/"); code != 0 {
		t.Fatalf("upload: %d %s", code, h.err.String())
	}
	if string(f.files["/root/run.sh"]) != "#!/bin/sh\necho hi\n" || f.requests[len(f.requests)-1] != "PUT /api/v1/sandboxes/"+sbxID+"/files?mode=0755&path=%2Froot%2Frun.sh" {
		t.Fatalf("upload: %q %v", f.files["/root/run.sh"], f.requests)
	}
	if code := h.runStdin("from stdin", "sandbox", "cp", "-", "sbx:"+sbxID+":/tmp/in.txt"); code != 0 || string(f.files["/tmp/in.txt"]) != "from stdin" {
		t.Fatalf("upload stdin: %d %s", code, h.err.String())
	}

	// Download to a directory, a file and stdout; binary survives.
	if code := h.run("sandbox", "cp", "sbx:brave-otter:/etc/hostname", "."); code != 0 {
		t.Fatalf("download: %d %s", code, h.err.String())
	}
	if b, _ := os.ReadFile(filepath.Join(h.wd, "hostname")); string(b) != "brave-otter\n" {
		t.Fatalf("downloaded %q", b)
	}
	if code := h.run("sandbox", "cp", "sbx:"+sbxID+":/bin/blob", "blob.bin"); code != 0 {
		t.Fatalf("download binary: %d %s", code, h.err.String())
	}
	if b, _ := os.ReadFile(filepath.Join(h.wd, "blob.bin")); string(b) != "\xff\x00\xfe" {
		t.Fatalf("binary %q", b)
	}
	if code := h.run("sandbox", "cp", "sbx:"+sbxID+":/etc/hostname", "-"); code != 0 || h.out.String() != "brave-otter\n" {
		t.Fatalf("download stdout: %d %q", code, h.out.String())
	}

	// Larger than 5 MB: an error, nothing written.
	if code := h.run("sandbox", "cp", "sbx:"+sbxID+":/var/big.log", "big.log"); code != ExitError || !strings.Contains(h.err.String(), "larger than 5 MB") {
		t.Fatalf("truncated: %d %s", code, h.err.String())
	}
	if _, err := os.Stat(filepath.Join(h.wd, "big.log")); !os.IsNotExist(err) {
		t.Fatalf("truncated file written: %v", err)
	}

	// Directories: a clear error both ways.
	if code := h.run("sandbox", "cp", ".", "sbx:"+sbxID+":/root/"); code != ExitError || !strings.Contains(h.err.String(), "directories aren't supported") {
		t.Fatalf("upload dir: %d %s", code, h.err.String())
	}
	if code := h.run("sandbox", "cp", "sbx:"+sbxID+":/etc", "."); code != ExitError || !strings.Contains(h.err.String(), "directories aren't supported") {
		t.Fatalf("download dir: %d %s", code, h.err.String())
	}
	for _, bad := range [][]string{{"a", "b"}, {"sbx:x:/a", "sbx:y:/b"}, {"sbx:x:rel", "."}, {"sbx::/a", "."}} {
		if code := h.run(append([]string{"sandbox", "cp"}, bad...)...); code != ExitUsage {
			t.Errorf("%v: exit %d %s", bad, code, h.err.String())
		}
	}
}
