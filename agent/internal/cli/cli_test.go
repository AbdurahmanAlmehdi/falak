package cli

import (
	"bytes"
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/cli/api"
)

const token = "1|falak_testtoken"

// fakeCP is an in-memory control plane implementing the planned /api/v1 surface.
type fakeCP struct {
	t   *testing.T
	mu  sync.Mutex
	srv *httptest.Server

	orgsMissing  bool
	deployStatus []string // successive statuses returned by GET /deployments/{id}
	waiting      string   // when set, POST /deployments answers a waiting deployment with this reason
	deployPolls  int
	output       []api.OutputLine
	lastBody     map[string]any
	lastQuery    map[string]string
	env          string
	logPolls     int
	requests     []string
}

func newFakeCP(t *testing.T) *fakeCP {
	f := &fakeCP{t: t, env: "APP_ENV=production\nAPP_KEY=base64:x\n"}
	f.srv = httptest.NewServer(http.HandlerFunc(f.serve))
	t.Cleanup(f.srv.Close)
	return f
}

func (f *fakeCP) json(w http.ResponseWriter, code int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(code)
	_ = json.NewEncoder(w).Encode(v)
}

var servers = []api.Server{
	{ID: "01JSRV0000000000000000000A", Name: "web-1", Type: "app", Status: "active", Provider: "hetzner", IPv4: "203.0.113.10", PHP: "8.4", SSHUser: "falak"},
	{ID: "01JSRV0000000000000000000B", Name: "db-1", Type: "db", Status: "active", Provider: "custom", IPv4: "203.0.113.11", SSHPort: 2222},
}

var site = api.Site{ID: "01JSITE000000000000000000A", Slug: "acme", Domain: "acme.test", Runtime: "frankenphp", Status: "active", Branch: "main", ServerIDs: []string{servers[0].ID}}

func (f *fakeCP) serve(w http.ResponseWriter, r *http.Request) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.requests = append(f.requests, r.Method+" "+r.URL.Path)
	if r.Header.Get("Authorization") != "Bearer "+token {
		f.json(w, 401, map[string]string{"message": "Unauthenticated."})
		return
	}
	if r.Header.Get("Accept") != "application/json" {
		f.t.Errorf("missing Accept header on %s", r.URL.Path)
	}
	f.lastBody = nil
	if r.Body != nil {
		b, _ := io.ReadAll(r.Body)
		if len(b) > 0 {
			_ = json.Unmarshal(b, &f.lastBody)
		}
	}
	f.lastQuery = map[string]string{}
	for k := range r.URL.Query() {
		f.lastQuery[k] = r.URL.Query().Get(k)
	}
	p := r.URL.Path
	switch {
	case p == api.PathMe:
		f.json(w, 200, map[string]any{"data": map[string]any{
			"user":         map[string]any{"id": "u1", "name": "Ada", "email": "ada@acme.test"},
			"organization": map[string]any{"id": "o1", "name": "Acme", "role": "owner"},
			"token":        map[string]any{"name": "cli", "abilities": []string{"*"}},
		}})
	case p == api.PathOrganizations:
		if f.orgsMissing {
			f.json(w, 404, map[string]string{"message": "Not Found"})
			return
		}
		f.json(w, 200, map[string]any{"data": []api.Organization{{ID: "o1", Name: "Acme", Role: "owner"}, {ID: "o2", Name: "Beta"}}})
	case p == api.PathServers:
		f.json(w, 200, map[string]any{"data": servers})
	case strings.HasPrefix(p, "/api/v1/servers/"):
		id := strings.TrimPrefix(p, "/api/v1/servers/")
		for _, s := range servers {
			if s.ID == id {
				f.json(w, 200, map[string]any{"data": s})
				return
			}
		}
		f.json(w, 404, map[string]string{"message": "Server not found."})
	case p == api.PathSites:
		f.json(w, 200, map[string]any{"data": []api.Site{site}})
	case p == api.Path(api.PathSite, "site", "acme"):
		f.json(w, 200, map[string]any{"data": site})
	case p == api.Path(api.PathSiteDeployments, "site", "acme") && r.Method == http.MethodPost:
		if f.waiting != "" {
			f.json(w, 201, map[string]any{"data": api.Deployment{ID: "01JDEP", Status: "waiting", WaitingReason: f.waiting}})
			return
		}
		f.json(w, 201, map[string]any{"data": api.Deployment{ID: "01JDEP", Status: "queued", URL: f.srv.URL + "/deployments/01JDEP"}})
	case p == api.Path(api.PathSiteRollback, "site", "acme") && r.Method == http.MethodPost:
		f.json(w, 201, map[string]any{"data": api.Deployment{ID: "01JDEP", Status: "queued", Trigger: "rollback"}})
	case p == api.Path(api.PathDeployment, "deployment", "01JDEP"):
		st := f.deployStatus[min(f.deployPolls, len(f.deployStatus)-1)]
		f.deployPolls++
		d := api.Deployment{ID: "01JDEP", Status: st}
		if st == "failed" {
			d.Error = "healthcheck failed on web-1"
		}
		f.json(w, 200, map[string]any{"data": d})
	case p == api.Path(api.PathDeploymentOutput, "deployment", "01JDEP"):
		after, _ := strconv.ParseInt(r.URL.Query().Get("after"), 10, 64)
		// Reveal one more line per poll.
		var out []api.OutputLine
		for _, l := range f.output {
			if l.Seq > after && int(l.Seq) <= f.deployPolls+1 {
				out = append(out, l)
			}
		}
		f.json(w, 200, map[string]any{"data": out})
	case p == api.Path(api.PathSiteReleases, "site", "acme"):
		f.json(w, 200, map[string]any{"data": []api.Release{{ID: "01JREL2", Commit: "abcdef1234567", Active: true}, {ID: "01JREL1", Commit: "1234567abcdef"}}})
	case p == api.Path(api.PathSiteEnv, "site", "acme"):
		if r.Method == http.MethodPut {
			f.env, _ = f.lastBody["content"].(string)
			w.WriteHeader(204)
			return
		}
		f.json(w, 200, map[string]any{"data": map[string]string{"content": f.env}})
	case p == api.Path(api.PathSiteLogs, "site", "acme"):
		f.logPolls++
		n := strconv.Itoa(f.logPolls)
		f.json(w, 200, map[string]any{"data": []api.LogEntry{{At: "2026-09-26T10:00:0" + n + "Z", Level: "error", Source: "laravel", Server: "web-1", Message: "boom " + n}}, "meta": map[string]string{"cursor": "c" + n}})
	case p == api.Path(api.PathSite, "site", "invalid"):
		f.json(w, 422, map[string]any{"message": "The given data was invalid.", "errors": map[string][]string{"branch": {"The branch does not exist."}}})
	default:
		f.json(w, 404, map[string]string{"message": "Not Found"})
	}
}

type harness struct {
	app      *App
	out, err *bytes.Buffer
	dir      string
	env      map[string]string
	execed   []string
	opened   string
	wd       string // working directory of config commands ("" = the process's)
	branch   string // current git branch config commands see
}

func newHarness(t *testing.T, cp *fakeCP) *harness {
	h := &harness{out: &bytes.Buffer{}, err: &bytes.Buffer{}, dir: t.TempDir(), env: map[string]string{}}
	h.env["FALAK_CONFIG_DIR"] = h.dir
	if cp != nil {
		h.env["FALAK_URL"] = cp.srv.URL
		h.env["FALAK_TOKEN"] = token
	}
	h.app = h.newApp("")
	return h
}

func (h *harness) newApp(stdin string) *App {
	return &App{
		Stdout: h.out, Stderr: h.err, Stdin: strings.NewReader(stdin),
		Getenv:    func(k string) string { return h.env[k] },
		Exec:      func(argv []string) error { h.execed = argv; return nil },
		Open:      func(u string) error { h.opened = u; return nil },
		Poll:      time.Millisecond,
		Version:   "test",
		Dir:       h.wd,
		GitBranch: func(string) string { return h.branch },
	}
}

func (h *harness) run(args ...string) int {
	h.out.Reset()
	h.err.Reset()
	h.app = h.newApp("")
	return h.app.Run(context.Background(), args)
}

func (h *harness) runStdin(stdin string, args ...string) int {
	h.out.Reset()
	h.err.Reset()
	h.app = h.newApp(stdin)
	return h.app.Run(context.Background(), args)
}

func TestParsingAndUsage(t *testing.T) {
	cp := newFakeCP(t)
	h := newHarness(t, cp)
	cases := []struct {
		args []string
		code int
		in   string
	}{
		{[]string{}, ExitUsage, "usage: falak"},
		{[]string{"help"}, ExitOK, "servers list"},
		{[]string{"bogus"}, ExitUsage, `unknown command "bogus"`},
		{[]string{"servers", "bogus"}, ExitUsage, `unknown command "servers bogus"`},
		{[]string{"deploy"}, ExitUsage, "usage: falak deploy <site>"},
		{[]string{"deploy", "a", "b"}, ExitUsage, "usage: falak deploy"},
		{[]string{"deploy", "acme", "--nope"}, ExitUsage, "flag provided but not defined"},
		{[]string{"version"}, ExitOK, "test"},
		{[]string{"--version"}, ExitOK, "test"},
		{[]string{"deploy", "-h"}, ExitOK, "-branch"},
	}
	for _, tc := range cases {
		code := h.run(tc.args...)
		if code != tc.code || !strings.Contains(h.out.String()+h.err.String(), tc.in) {
			t.Errorf("%v: code %d, out %q err %q", tc.args, code, h.out.String(), h.err.String())
		}
	}
	// Group default: `falak servers` == `falak servers list`; global flags before or after.
	for _, args := range [][]string{{"servers"}, {"--json", "servers", "list"}, {"servers", "list", "--json"}} {
		if code := h.run(args...); code != 0 {
			t.Fatalf("%v: %d %s", args, code, h.err.String())
		}
	}
	var got []api.Server
	if err := json.Unmarshal(h.out.Bytes(), &got); err != nil || len(got) != 2 {
		t.Fatalf("json output: %v %s", err, h.out.String())
	}
}

func TestLoginStoresCredentials0600(t *testing.T) {
	cp := newFakeCP(t)
	h := newHarness(t, nil)
	if code := h.runStdin(token+"\n", "login", "--url", cp.srv.URL+"/", "--token-stdin"); code != 0 {
		t.Fatalf("code %d: %s", code, h.err.String())
	}
	if !strings.Contains(h.out.String(), "Logged in to "+cp.srv.URL+" as ada@acme.test (organization Acme)") {
		t.Fatalf("out = %s", h.out.String())
	}
	p := filepath.Join(h.dir, "credentials.json")
	st, err := os.Stat(p)
	if err != nil || st.Mode().Perm() != 0o600 {
		t.Fatalf("credentials perms: %v %v", st.Mode(), err)
	}
	c, _ := LoadCredentials(h.dir)
	if c.URL != cp.srv.URL || c.Token != token || c.User != "ada@acme.test" || c.Organization != "Acme" {
		t.Fatalf("creds = %+v", c)
	}
	// Stored creds are used without env.
	if code := h.run("whoami"); code != 0 || !strings.Contains(h.out.String(), "Ada <ada@acme.test>") {
		t.Fatalf("whoami: %d %s %s", code, h.out.String(), h.err.String())
	}
	// Env overrides the stored token (CI); a bad token yields a login hint.
	h.env["FALAK_TOKEN"] = "wrong"
	if code := h.run("sites", "list"); code != ExitError || !strings.Contains(h.err.String(), "HTTP 401") || !strings.Contains(h.err.String(), "falak login") {
		t.Fatalf("bad env token: %d %s", code, h.err.String())
	}
	// --token flag beats env.
	if code := h.run("sites", "list", "--token", token); code != 0 {
		t.Fatalf("flag token: %d %s", code, h.err.String())
	}
	delete(h.env, "FALAK_TOKEN")
	if code := h.run("logout"); code != 0 {
		t.Fatal(code)
	}
	if _, err := os.Stat(p); !os.IsNotExist(err) {
		t.Fatal("credentials not removed")
	}
	if code := h.run("sites", "list"); code != ExitError || !strings.Contains(h.err.String(), "no control-plane URL") {
		t.Fatalf("after logout: %d %s", code, h.err.String())
	}
}

func TestLoginErrors(t *testing.T) {
	cp := newFakeCP(t)
	h := newHarness(t, nil)
	if code := h.runStdin("", "login"); code != ExitUsage {
		t.Fatalf("no url: %d", code)
	}
	if code := h.runStdin("bad\n", "login", "--url", cp.srv.URL); code != ExitError || !strings.Contains(h.err.String(), "verify token") {
		t.Fatalf("bad token: %d %s", code, h.err.String())
	}
	if _, err := os.Stat(filepath.Join(h.dir, "credentials.json")); !os.IsNotExist(err) {
		t.Fatal("credentials saved for an invalid token")
	}
	if code := h.runStdin("", "login", "--url", "ftp://x"); code != ExitUsage {
		t.Fatalf("bad scheme: %d", code)
	}
}

func TestServersAndSites(t *testing.T) {
	cp := newFakeCP(t)
	h := newHarness(t, cp)
	if code := h.run("servers", "list"); code != 0 {
		t.Fatal(h.err.String())
	}
	lines := strings.Split(strings.TrimSpace(h.out.String()), "\n")
	if len(lines) != 3 || !strings.HasPrefix(lines[0], "ID") || !strings.Contains(lines[1], "web-1") || !strings.Contains(lines[1], "203.0.113.10") {
		t.Fatalf("table:\n%s", h.out.String())
	}
	// show by name falls back to the list.
	if code := h.run("servers", "show", "db-1"); code != 0 || !strings.Contains(h.out.String(), "203.0.113.11") {
		t.Fatalf("show: %d %s %s", code, h.out.String(), h.err.String())
	}
	if code := h.run("servers", "show", "nope"); code != ExitError || !strings.Contains(h.err.String(), `server "nope" not found`) {
		t.Fatalf("missing: %d %s", code, h.err.String())
	}
	if code := h.run("sites", "show", "acme", "--json"); code != 0 {
		t.Fatal(h.err.String())
	}
	var s api.Site
	if err := json.Unmarshal(h.out.Bytes(), &s); err != nil || s.Slug != "acme" {
		t.Fatalf("site json: %s", h.out.String())
	}
	if code := h.run("sites", "show", "acme"); code != 0 || !strings.Contains(h.out.String(), "https://acme.test") {
		t.Fatalf("site: %s", h.out.String())
	}
	if code := h.run("sites"); code != 0 || !strings.Contains(h.out.String(), "frankenphp") {
		t.Fatalf("sites: %s", h.out.String())
	}
}

func TestOrgs(t *testing.T) {
	cp := newFakeCP(t)
	h := newHarness(t, cp)
	if code := h.run("orgs"); code != 0 || !strings.Contains(h.out.String(), "Beta") {
		t.Fatalf("orgs: %s %s", h.out.String(), h.err.String())
	}
	cp.orgsMissing = true
	if code := h.run("orgs", "--json"); code != 0 {
		t.Fatal(h.err.String())
	}
	var orgs []api.Organization
	if err := json.Unmarshal(h.out.Bytes(), &orgs); err != nil || len(orgs) != 1 || orgs[0].Name != "Acme" || orgs[0].Role != "owner" {
		t.Fatalf("fallback: %s", h.out.String())
	}
}

func deployOutput() []api.OutputLine {
	return []api.OutputLine{
		{Seq: 1, Phase: "build", Data: "==> Detected laravel\n"},
		{Seq: 2, Server: "web-1", Phase: "fetch", Data: "release extracted\n"},
		{Seq: 3, Server: "web-1", Phase: "activate", Data: "current -> releases/01J\nreloaded\n"},
	}
}

func TestDeployWaitStreamsOutput(t *testing.T) {
	cp := newFakeCP(t)
	cp.deployStatus = []string{"building", "deploying", "succeeded"}
	cp.output = deployOutput()
	h := newHarness(t, cp)
	if code := h.run("deploy", "acme", "--wait", "--branch", "release/1.2"); code != 0 {
		t.Fatalf("code %d: %s %s", code, h.out.String(), h.err.String())
	}
	want := "Deployment 01JDEP started for acme\n[build] ==> Detected laravel\n[web-1 fetch] release extracted\n[web-1 activate] current -> releases/01J\n[web-1 activate] reloaded\nDeployment 01JDEP succeeded\n"
	if h.out.String() != want {
		t.Fatalf("output:\n%s\nwant:\n%s", h.out.String(), want)
	}
}

func TestDeployPostsBranch(t *testing.T) {
	cp := newFakeCP(t)
	h := newHarness(t, cp)
	if code := h.run("deploy", "--branch", "feature/x", "acme"); code != 0 {
		t.Fatal(h.err.String())
	}
	if cp.lastBody["branch"] != "feature/x" || !strings.Contains(h.out.String(), "Deployment 01JDEP queued for acme") {
		t.Fatalf("body %v out %s", cp.lastBody, h.out.String())
	}
	if cp.requests[len(cp.requests)-1] != "POST /api/v1/sites/acme/deployments" {
		t.Fatalf("requests %v", cp.requests)
	}
}

func TestDeployWhileServersPrepare(t *testing.T) {
	cp := newFakeCP(t)
	cp.waiting = "Waiting for 1 server to finish preparing: web-1"
	h := newHarness(t, cp)
	if code := h.run("deploy", "acme"); code != 0 {
		t.Fatal(h.err.String())
	}
	want := "Deployment 01JDEP waiting for acme\nWaiting for 1 server to finish preparing: web-1; it starts automatically once they are ready\n"
	if h.out.String() != want {
		t.Fatalf("output:\n%s\nwant:\n%s", h.out.String(), want)
	}
}

func TestDeployFailedExitCodeAndJSON(t *testing.T) {
	cp := newFakeCP(t)
	cp.deployStatus = []string{"deploying", "failed"}
	cp.output = deployOutput()
	h := newHarness(t, cp)
	code := h.run("deploy", "acme", "--wait", "--json")
	if code != ExitDeployFailed {
		t.Fatalf("code %d: %s", code, h.err.String())
	}
	var d api.Deployment
	if err := json.Unmarshal(h.out.Bytes(), &d); err != nil || d.Status != "failed" {
		t.Fatalf("stdout must be the final deployment JSON: %s", h.out.String())
	}
	if !strings.Contains(h.err.String(), "[web-1 fetch] release extracted") || !strings.Contains(h.err.String(), "healthcheck failed on web-1") {
		t.Fatalf("stderr: %s", h.err.String())
	}
}

func TestRollbackAndReleases(t *testing.T) {
	cp := newFakeCP(t)
	cp.deployStatus = []string{"succeeded"}
	h := newHarness(t, cp)
	if code := h.run("rollback", "acme", "--release", "01JREL1", "--json"); code != 0 {
		t.Fatal(h.err.String())
	}
	if cp.lastBody["release_id"] != "01JREL1" {
		t.Fatalf("body = %v", cp.lastBody)
	}
	if code := h.run("rollback", "acme", "--wait"); code != 0 || !strings.Contains(h.out.String(), "Rollback 01JDEP succeeded") {
		t.Fatalf("rollback wait: %d %s", code, h.out.String())
	}
	if code := h.run("releases", "acme"); code != 0 || !strings.Contains(h.out.String(), "*  01JREL2  abcdef1") {
		t.Fatalf("releases:\n%s", h.out.String())
	}
}

func TestEnvPullPush(t *testing.T) {
	cp := newFakeCP(t)
	h := newHarness(t, cp)
	if code := h.run("env", "pull", "acme"); code != 0 || h.out.String() != cp.env {
		t.Fatalf("pull: %q", h.out.String())
	}
	file := filepath.Join(t.TempDir(), ".env")
	if code := h.run("env", "pull", "acme", "--file", file); code != 0 {
		t.Fatal(h.err.String())
	}
	st, _ := os.Stat(file)
	if st.Mode().Perm() != 0o600 || !strings.Contains(h.err.String(), "2 variables") {
		t.Fatalf("file perms %v, err %s", st.Mode(), h.err.String())
	}
	if code := h.runStdin("A=1\n# c\nexport B=2\n", "env", "push", "acme"); code != 0 || cp.env != "A=1\n# c\nexport B=2\n" || !strings.Contains(h.out.String(), "Pushed 2 variables") {
		t.Fatalf("push: %d %q %s", code, cp.env, h.err.String())
	}
	if code := h.runStdin("  \n", "env", "push", "acme"); code != ExitUsage {
		t.Fatalf("empty push: %d", code)
	}
	_ = os.WriteFile(file, []byte("FROM_FILE=1\n"), 0o600)
	if code := h.run("env", "push", "acme", "--file", file); code != 0 || cp.env != "FROM_FILE=1\n" {
		t.Fatalf("push file: %q", cp.env)
	}
}

func TestLogsAndFollow(t *testing.T) {
	cp := newFakeCP(t)
	h := newHarness(t, cp)
	if code := h.run("logs", "acme", "--since", "30m", "--level", "error"); code != 0 {
		t.Fatal(h.err.String())
	}
	if h.out.String() != "2026-09-26T10:00:01Z ERROR [web-1/laravel] boom 1\n" || cp.lastQuery["since"] != "1800" || cp.lastQuery["level"] != "error" {
		t.Fatalf("logs %q query %v", h.out.String(), cp.lastQuery)
	}
	// --follow polls with the returned cursor until cancelled.
	h.out.Reset()
	app := h.newApp("")
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan int)
	go func() { done <- app.Run(ctx, []string{"logs", "acme", "-f", "--json"}) }()
	deadline := time.After(5 * time.Second)
	for {
		cp.mu.Lock()
		n := cp.logPolls
		cp.mu.Unlock()
		if n >= 4 {
			break
		}
		select {
		case <-deadline:
			t.Fatal("follow did not poll")
		case <-time.After(time.Millisecond):
		}
	}
	cancel()
	if code := <-done; code != 0 {
		t.Fatalf("follow exit %d", code)
	}
	cp.mu.Lock()
	cursor := cp.lastQuery["cursor"]
	cp.mu.Unlock()
	if !strings.HasPrefix(cursor, "c") {
		t.Fatalf("cursor not forwarded: %v", cp.lastQuery)
	}
	lines := strings.Split(strings.TrimSpace(h.out.String()), "\n")
	var e api.LogEntry
	if len(lines) < 3 || json.Unmarshal([]byte(lines[0]), &e) != nil || e.Message != "boom 2" {
		t.Fatalf("ndjson follow output: %s", h.out.String())
	}
}

func TestSSHAndOpen(t *testing.T) {
	cp := newFakeCP(t)
	h := newHarness(t, cp)
	if code := h.run("ssh", "web-1"); code != 0 || strings.Join(h.execed, " ") != "ssh falak@203.0.113.10" {
		t.Fatalf("ssh: %v %s", h.execed, h.err.String())
	}
	if code := h.run("ssh", "01JSRV0000000000000000000B", "--user", "root", "--", "-A", "uptime"); code != 0 || strings.Join(h.execed, " ") != "ssh -p 2222 root@203.0.113.11 -A uptime" {
		t.Fatalf("ssh args: %v", h.execed)
	}
	if code := h.run("open", "acme"); code != 0 || h.opened != "https://acme.test" {
		t.Fatalf("open: %q", h.opened)
	}
	if code := h.run("open", "acme", "--panel"); code != 0 || h.opened != cp.srv.URL+"/sites/"+site.ID {
		t.Fatalf("open panel: %q", h.opened)
	}
}

func TestAPIErrorDecoding(t *testing.T) {
	cp := newFakeCP(t)
	c := api.New(cp.srv.URL, token, "t")
	_, err := c.Site(context.Background(), "invalid")
	if err == nil || err.Error() != "GET /api/v1/sites/invalid: HTTP 422: The given data was invalid. (branch: The branch does not exist.)" {
		t.Fatalf("err = %v", err)
	}
	if _, err := c.Site(context.Background(), "missing"); !api.IsNotFound(err) {
		t.Fatalf("404 = %v", err)
	}
	if got := api.Path(api.PathSite, "site", "a b/c"); got != "/api/v1/sites/a%20b%2Fc" {
		t.Fatalf("path escape = %s", got)
	}
}
