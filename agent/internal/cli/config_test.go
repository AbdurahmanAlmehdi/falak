package cli

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/cli/api"
)

const projectID = "01jproj0000000000000000000"

// fakeConfig serves the config-as-code endpoints of the contract and the deployment endpoints `apply --wait`
// follows.
type fakeConfig struct {
	t   *testing.T
	mu  sync.Mutex
	srv *httptest.Server

	bodies    []map[string]any
	queries   []string
	requests  []string
	plan      map[string]any // data of POST plan
	invalid   bool           // answer plan / runs with 422
	notEnable bool           // 409 on plan
	validate  map[string]any // data of POST validate
	run       map[string]any // data of POST runs
	runPolls  []map[string]any
	deployOut []api.OutputLine
	export    map[string]any
}

func newFakeConfig(t *testing.T) *fakeConfig {
	f := &fakeConfig{t: t}
	f.srv = httptest.NewServer(http.HandlerFunc(f.serve))
	t.Cleanup(f.srv.Close)
	return f
}

func (f *fakeConfig) json(w http.ResponseWriter, code int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(code)
	_ = json.NewEncoder(w).Encode(v)
}

func (f *fakeConfig) serve(w http.ResponseWriter, r *http.Request) {
	f.mu.Lock()
	defer f.mu.Unlock()
	if r.Header.Get("Authorization") != "Bearer "+token {
		f.json(w, 401, map[string]string{"message": "Unauthenticated."})
		return
	}
	f.requests = append(f.requests, r.Method+" "+r.URL.Path)
	f.queries = append(f.queries, r.URL.RawQuery)
	var body map[string]any
	if b, _ := io.ReadAll(r.Body); len(b) > 0 {
		if err := json.Unmarshal(b, &body); err != nil {
			f.t.Errorf("body of %s: %v", r.URL.Path, err)
		}
		f.bodies = append(f.bodies, body)
	}
	invalid := func() {
		line, col := 7, 17
		f.json(w, 422, map[string]any{
			"message": "falak.yaml is invalid (1 error).",
			"errors":  map[string][]string{"file": {"/units/web/servers/0: no server named web-9"}},
			"config_errors": []map[string]any{{"code": "name.server", "pointer": "/units/web/servers/0", "source": nil,
				"line": line, "column": col, "message": "no server named web-9 is attached to the project", "severity": "error"}},
			"config_warnings": []any{},
		})
	}
	p := r.URL.Path
	switch {
	case p == api.PathServers:
		f.json(w, 200, map[string]any{"data": servers})
	case p == api.PathProjects:
		f.json(w, 200, map[string]any{"data": []map[string]any{{"id": projectID, "name": "Shop", "environments": []map[string]any{
			{"id": "01jenv0000000000000000000a", "name": "Production", "slug": "production", "is_production": true},
			{"id": "01jenv0000000000000000000b", "name": "Staging", "slug": "staging", "is_production": false},
		}}}})
	case p == api.Path(api.PathConfigValidate, "project", projectID):
		f.json(w, 200, map[string]any{"data": f.validate})
	case p == api.Path(api.PathConfigPlan, "project", projectID):
		switch {
		case f.notEnable:
			f.json(w, 409, map[string]string{"message": "Config as code is not enabled for this project."})
		case f.invalid:
			invalid()
		default:
			f.json(w, 200, map[string]any{"data": f.plan})
		}
	case p == api.Path(api.PathConfigRuns, "project", projectID) && r.Method == http.MethodPost:
		if f.invalid {
			invalid()
			return
		}
		f.json(w, 201, map[string]any{"data": f.run})
	case strings.HasPrefix(p, "/api/v1/config/runs/"):
		next := f.run
		if len(f.runPolls) > 0 {
			next, f.runPolls = f.runPolls[0], f.runPolls[1:]
			f.run = next
		}
		f.json(w, 200, map[string]any{"data": next})
	case strings.HasSuffix(p, "/output"):
		after := r.URL.Query().Get("after")
		var lines []api.OutputLine
		if after == "0" {
			lines = f.deployOut
		}
		f.json(w, 200, map[string]any{"data": lines, "meta": map[string]any{"next": 9}})
	case p == api.Path(api.PathConfigExport, "project", projectID):
		f.json(w, 200, map[string]any{"data": f.export})
	default:
		f.json(w, 404, map[string]string{"message": "Not Found"})
	}
}

func configHarness(t *testing.T) (*harness, *fakeConfig) {
	f := newFakeConfig(t)
	h := newHarness(t, nil)
	h.env["FALAK_URL"] = f.srv.URL
	h.env["FALAK_TOKEN"] = token
	h.wd = t.TempDir()
	h.branch = "main"
	return h, f
}

const validFile = `$schema: https://falak.sh/schema/v1.json
version: 1
units:
  web:
    type: app
    runtime: frankenphp
    php: "8.4"
    env:
      DATABASE_URL: ${{ db.DATABASE_URL }}
      STRIPE_SECRET: ${{ secrets.STRIPE_SECRET }}
  db:
    type: database
    engine: postgresql
environments:
  production:
    branch: main
    servers: [web-1]
  staging:
    branch: develop
    units:
      web:
        deploy: { strategy: in-place }
`

func writeFile(t *testing.T, dir, name, content string) string {
	t.Helper()
	p := filepath.Join(dir, name)
	if err := os.MkdirAll(filepath.Dir(p), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(p, []byte(content), 0o644); err != nil {
		t.Fatal(err)
	}
	return p
}

func TestValidateOffline(t *testing.T) {
	h := newHarness(t, nil)
	h.wd = t.TempDir()
	writeFile(t, h.wd, "falak.yaml", validFile)
	if code := h.run("validate"); code != 0 || !strings.Contains(h.out.String(), "falak.yaml is valid") {
		t.Fatalf("valid: %d %s %s", code, h.out.String(), h.err.String())
	}

	cases := []struct {
		name, file, want string
	}{
		{"schema", strings.Replace(validFile, "runtime: frankenphp", "runtime: frankenphp\n    deploy:\n      strategy: sideways", 1),
			"falak.yaml:8:17: schema: /units/web/deploy/strategy: "},
		{"unknown property points at the key", strings.Replace(validFile, `    php: "8.4"`, "    php: \"8.4\"\n    bogus: 1", 1),
			"falak.yaml:8:5: schema: /units/web: "},
		{"parse", "version: 1\nunits:\n  web: [\n", "falak.yaml:"},
		{"duplicate key", "version: 1\nversion: 1\nunits: {}\n", "falak.yaml:2: parse.duplicate_key: /version: "},
		{"reference in an override", strings.Replace(validFile, "deploy: { strategy: in-place }", "env:\n          X: ${{ db.NOPE }}", 1),
			"falak.yaml:23:14: reference.unknown_key: /environments/staging/units/web/env/X: "},
		{"offline semantic rule", strings.Replace(validFile, "    type: database\n    engine: postgresql", "    type: database\n    engine: postgresql\n    disk: 1500m", 1),
			"falak.yaml:14:11: value.range: /units/db/disk: disk must be a whole number of GiB"},
		{"laravel on node", strings.Replace(validFile, "runtime: frankenphp\n    php: \"8.4\"", "runtime: node\n    laravel: { horizon: true }", 1),
			"value.unsupported: /units/web/laravel:"},
	}
	for _, c := range cases {
		writeFile(t, h.wd, "falak.yaml", c.file)
		code := h.run("validate")
		if code != ExitInvalid || !strings.Contains(h.err.String(), c.want) {
			t.Errorf("%s: code %d, stderr:\n%s\nwant %q", c.name, code, h.err.String(), c.want)
		}
	}

	// A warning (external reference) leaves the file valid; --json prints {valid, errors, warnings}.
	writeFile(t, h.wd, "falak.json", `{"version": 1, "units": {"web": {"type": "app", "runtime": "node", "env": {"A": "${{ cache.REDIS_URL }}"}}}}`)
	if code := h.run("validate", "falak.json", "--json"); code != 0 {
		t.Fatalf("json: %d %s", code, h.err.String())
	}
	var v struct {
		Valid    bool
		Warnings []api.ConfigIssue
	}
	if err := json.Unmarshal(h.out.Bytes(), &v); err != nil || !v.Valid || len(v.Warnings) != 1 || v.Warnings[0].Code != "reference.external" || v.Warnings[0].Line == nil || *v.Warnings[0].Line != 1 {
		t.Fatalf("json: %v %s", err, h.out.String())
	}
	if code := h.run("validate", "-e", "nope", "falak.json"); code != ExitInvalid || !strings.Contains(h.err.String(), "environment.unknown") {
		t.Fatalf("unknown env: %d %s", code, h.err.String())
	}
	if code := h.run("validate", "missing.yaml"); code != ExitError {
		t.Fatalf("missing file: %d", code)
	}
	empty := t.TempDir()
	h.wd = empty
	if code := h.run("validate"); code != ExitUsage {
		t.Fatalf("no file: %d %s", code, h.err.String())
	}
}

func TestValidateAddsNamesFromTheControlPlane(t *testing.T) {
	h, f := configHarness(t)
	writeFile(t, h.wd, "falak.yaml", validFile)
	writeFile(t, h.wd, ".falak/project.json", `{"project": "`+projectID+`"}`)
	writeFile(t, h.wd, ".git/HEAD", "ref: refs/heads/main\n")
	f.validate = map[string]any{"valid": false, "environments": []string{"production"},
		"errors": []map[string]any{{"code": "name.secret", "pointer": "/units/web/env/STRIPE_SECRET", "source": "/units/web/env/STRIPE_SECRET",
			"line": 10, "column": 22, "message": "no secret STRIPE_SECRET", "severity": "error"}}, "warnings": []any{}}
	code := h.run("validate", "-e", "production")
	if code != ExitInvalid || !strings.Contains(h.err.String(), "falak.yaml:10:22: name.secret: /units/web/env/STRIPE_SECRET: no secret") {
		t.Fatalf("%d %s", code, h.err.String())
	}
	if f.bodies[0]["environment"] != "production" || f.bodies[0]["format"] != "yaml" || !strings.Contains(f.bodies[0]["file"].(string), "STRIPE_SECRET") {
		t.Fatalf("body %v", f.bodies[0])
	}
	// --offline skips the control plane.
	n := len(f.requests)
	if code := h.run("validate", "--offline"); code != 0 || len(f.requests) != n {
		t.Fatalf("offline: %d %v", code, f.requests)
	}
}

func samplePlan() map[string]any {
	return map[string]any{
		"environment": "production", "commit": nil, "resolved": map[string]any{"version": 1},
		"plan": map[string]any{
			"changes": []map[string]any{
				{"op": "create", "path": "/units/web/workers/queue", "before": nil, "after": map[string]any{"connection": "redis"}, "section": "units.*.workers", "unit": "web", "risky": false},
				{"op": "update", "path": "/units/web/deploy/strategy", "before": "zero-downtime", "after": "rolling", "unit": "web", "risky": true,
					"rule": "production.strategy", "reason": "Changing the deploy strategy of a production environment needs an approval."},
				{"op": "detach", "path": "/units/old-db", "before": map[string]any{"type": "database"}, "after": nil, "unit": "old-db", "risky": false},
				{"op": "noop", "path": "/units/web/php", "before": "8.4", "after": "8.4", "unit": "web", "risky": false},
			},
			"summary": map[string]int{"create": 1, "update": 1, "delete": 0, "detach": 1, "noop": 1},
			"risky":   true, "deploy": []string{"web"},
		},
		"warnings": []any{},
	}
}

func TestPlan(t *testing.T) {
	h, f := configHarness(t)
	writeFile(t, h.wd, "falak.yaml", validFile)
	f.plan = samplePlan()

	// No project yet: a usage error that says how to link one.
	if code := h.run("plan"); code != ExitUsage || !strings.Contains(h.err.String(), "-p <project>") {
		t.Fatalf("no project: %d %s", code, h.err.String())
	}
	// By name (resolved through GET /projects); environment from the git branch (main → production).
	if code := h.run("plan", "-p", "shop"); code != 0 {
		t.Fatalf("plan: %d %s", code, h.err.String())
	}
	out := h.out.String()
	for _, want := range []string{
		"Plan for production: 1 to create, 1 to update, 0 to delete, 1 to detach (1 unchanged)",
		`  + create /units/web/workers/queue = {"connection":"redis"}`,
		"  ! update /units/web/deploy/strategy: zero-downtime → rolling",
		"risky: Changing the deploy strategy of a production environment needs an approval. (production.strategy)",
		"  - detach /units/old-db (kept",
		"Deploys: web",
	} {
		if !strings.Contains(out, want) {
			t.Errorf("missing %q in\n%s", want, out)
		}
	}
	if strings.Contains(out, "/units/web/php") {
		t.Errorf("noop listed:\n%s", out)
	}
	body := f.bodies[len(f.bodies)-1]
	if body["environment"] != "production" || body["format"] != "yaml" || body["file"] != validFile {
		t.Fatalf("body %v", body)
	}

	// -e wins; FALAK_PROJECT; --json prints data as is.
	h.env["FALAK_PROJECT"] = projectID
	if code := h.run("plan", "-e", "staging", "--json"); code != 0 {
		t.Fatalf("json: %d %s", code, h.err.String())
	}
	var data map[string]any
	if err := json.Unmarshal(h.out.Bytes(), &data); err != nil || data["environment"] != "production" || data["plan"] == nil {
		t.Fatalf("json out: %v %s", err, h.out.String())
	}
	if f.bodies[len(f.bodies)-1]["environment"] != "staging" {
		t.Fatalf("env: %v", f.bodies[len(f.bodies)-1])
	}

	// A branch no environment has, with two environments: usage error.
	h.branch = "feature/x"
	if code := h.run("plan"); code != ExitUsage || !strings.Contains(h.err.String(), "-e") {
		t.Fatalf("branch: %d %s", code, h.err.String())
	}
	h.branch = "main"

	// 422 from the control plane: issues and exit 5.
	f.invalid = true
	if code := h.run("plan"); code != ExitInvalid || !strings.Contains(h.err.String(), "falak.yaml:7:17: name.server: /units/web/servers/0: no server named web-9") {
		t.Fatalf("422: %d %s", code, h.err.String())
	}
	f.invalid = false
	f.notEnable = true
	if code := h.run("plan"); code != ExitError || !strings.Contains(h.err.String(), "not enabled") {
		t.Fatalf("409: %d %s", code, h.err.String())
	}
	f.notEnable = false

	// An invalid file never reaches the control plane.
	n := len(f.requests)
	writeFile(t, h.wd, "falak.yaml", "version: 2\nunits: {}\n")
	if code := h.run("plan"); code != ExitInvalid || len(f.requests) != n {
		t.Fatalf("invalid: %d %v", code, f.requests)
	}

	// Empty plan.
	writeFile(t, h.wd, "falak.yaml", validFile)
	f.plan = map[string]any{"environment": "production", "plan": map[string]any{"changes": []any{}, "summary": map[string]int{"noop": 3}, "risky": false, "deploy": []any{}}}
	if code := h.run("plan"); code != 0 || !strings.Contains(h.out.String(), "No changes") {
		t.Fatalf("empty: %d %s", code, h.out.String())
	}
}

func runData(status string, deployments ...map[string]any) map[string]any {
	if deployments == nil {
		deployments = []map[string]any{}
	}
	return map[string]any{"id": "01jrun0000000000000000000a", "project_id": projectID, "environment": "production", "trigger": "cli",
		"status": status, "plan": samplePlan()["plan"], "errors": []any{}, "error": nil, "allow_destroy": false,
		"deployments": deployments, "url": "https://falak.test/projects/p/config/runs/r"}
}

func TestApply(t *testing.T) {
	h, f := configHarness(t)
	writeFile(t, h.wd, "falak.yaml", validFile)
	h.env["FALAK_PROJECT"] = projectID

	// Risky: waits for an approval → exit 6.
	f.run = runData("awaiting_approval")
	if code := h.run("apply", "--allow-destroy"); code != ExitApproval || !strings.Contains(h.err.String(), "waits for an approval") {
		t.Fatalf("approval: %d %s", code, h.err.String())
	}
	if b := f.bodies[len(f.bodies)-1]; b["allow_destroy"] != true || b["environment"] != "production" {
		t.Fatalf("body %v", b)
	}
	if !strings.Contains(h.out.String(), "Run 01jrun0000000000000000000a for production: awaiting_approval") {
		t.Fatalf("out %s", h.out.String())
	}

	// Without --wait an applying run is a success.
	f.run = runData("applying")
	if code := h.run("apply"); code != 0 {
		t.Fatalf("applying: %d %s", code, h.err.String())
	}
	if b := f.bodies[len(f.bodies)-1]; b["allow_destroy"] != false {
		t.Fatalf("allow_destroy %v", b)
	}

	// --wait follows the run (its status decides when it is over) and streams its deployments, with the entry
	// statuses of the control plane's RunEngine (pending, deploying, released, skipped, failed).
	dep := func(unit, id, status string) map[string]any {
		var depID any
		if id != "" {
			depID = id
		}
		return map[string]any{"unit": unit, "site_id": "01jsite000000000000000000a", "deployment_id": depID, "status": status, "error": nil}
	}
	const depID = "01jdep0000000000000000000a"
	failedRun := runData("failed", dep("web", depID, "failed"), dep("docs", "", "skipped"))
	failedRun["error"] = "web: the deployment failed."
	f.run = runData("applying", dep("web", "", "pending"))
	// A settled entry doesn't end the wait while the run is still applying.
	f.runPolls = []map[string]any{runData("applying", dep("web", depID, "deploying")), runData("applying", dep("web", depID, "failed")), failedRun}
	f.deployOut = []api.OutputLine{{Seq: 1, Phase: "build", Data: "npm ci\n"}}
	code := h.run("apply", "--wait")
	if code != ExitDeployFailed || !strings.Contains(h.err.String(), "deployment of web failed: web: the deployment failed.") {
		t.Fatalf("wait failed: %d %s %s", code, h.out.String(), h.err.String())
	}
	for _, want := range []string{"[web] deployment " + depID + " started", "[web] [build] npm ci", "[web] deployment " + depID + " failed", "Run 01jrun0000000000000000000a: failed"} {
		if !strings.Contains(h.out.String(), want) {
			t.Errorf("missing %q in\n%s", want, h.out.String())
		}
	}
	if len(f.runPolls) != 0 {
		t.Fatalf("stopped before the run finished: %d polls left", len(f.runPolls))
	}

	// A run that failed before deploying anything (phase A) → 1.
	phaseA := runData("failed")
	phaseA["error"] = "Phase A failed: no server named web-9. Nothing was deployed."
	f.run, f.runPolls = runData("applying"), []map[string]any{phaseA}
	if code := h.run("apply", "--wait"); code != ExitError || !strings.Contains(h.err.String(), "Phase A failed") {
		t.Fatalf("phase A: %d %s", code, h.err.String())
	}

	// Applied (released, deployment statuses as the deployments module reports them) → 0; --json prints the run.
	f.run = runData("applying", dep("web", depID, "building"))
	f.runPolls = []map[string]any{runData("applied", dep("web", depID, "succeeded"), dep("db", "", "skipped"))}
	if code := h.run("apply", "--wait", "--json"); code != 0 {
		t.Fatalf("wait ok: %d %s", code, h.err.String())
	}
	var final map[string]any
	if err := json.Unmarshal(h.out.Bytes(), &final); err != nil || final["status"] != "applied" {
		t.Fatalf("json: %v %s", err, h.out.String())
	}

	// --timeout: the run never finishes → 1, and it says the run goes on.
	f.run, f.runPolls = runData("applying", dep("web", depID, "deploying")), nil
	if code := h.run("apply", "--wait", "--timeout", "20ms"); code != ExitError || !strings.Contains(h.err.String(), "gave up waiting") {
		t.Fatalf("timeout: %d %s", code, h.err.String())
	}

	// --wait stopping at an approval → 6; refused / failed → 1.
	f.run = runData("planning")
	f.runPolls = []map[string]any{runData("awaiting_approval")}
	if code := h.run("apply", "--wait"); code != ExitApproval {
		t.Fatalf("wait approval: %d", code)
	}
	f.run, f.runPolls = runData("refused"), nil
	if code := h.run("apply"); code != ExitError || !strings.Contains(h.err.String(), "refused") {
		t.Fatalf("refused: %d %s", code, h.err.String())
	}
	f.invalid = true
	if code := h.run("apply"); code != ExitInvalid {
		t.Fatalf("422: %d", code)
	}
}

func TestConfigExport(t *testing.T) {
	h, f := configHarness(t)
	f.export = map[string]any{"environment": "staging", "format": "yaml", "file": "version: 1\nunits:\n  web: {type: app, runtime: node}\n"}
	if code := h.run("config", "export", "shop", "-e", "staging"); code != 0 || h.out.String() != f.export["file"] {
		t.Fatalf("stdout: %d %q %s", code, h.out.String(), h.err.String())
	}
	if q := f.queries[len(f.queries)-1]; q != "environment=staging&format=yaml" {
		t.Fatalf("query %q", q)
	}
	if code := h.run("config", "export", "-p", projectID, "-o", "out/falak.yaml"); code == 0 {
		t.Fatal("missing directory should fail")
	}
	if code := h.run("config", "export", "-p", projectID, "-o", "falak.yaml"); code != 0 {
		t.Fatalf("-o: %d %s", code, h.err.String())
	}
	b, _ := os.ReadFile(filepath.Join(h.wd, "falak.yaml"))
	if string(b) != f.export["file"] || !strings.Contains(h.err.String(), "Wrote falak.yaml (staging)") {
		t.Fatalf("file %q %s", b, h.err.String())
	}
	if code := h.run("config", "export", projectID, "--format", "json", "--json"); code != 0 || !strings.Contains(h.out.String(), `"environment": "staging"`) {
		t.Fatalf("json: %d %s", code, h.out.String())
	}
	if q := f.queries[len(f.queries)-1]; q != "format=json" {
		t.Fatalf("query %q", q)
	}
	if code := h.run("config", "export"); code != ExitUsage {
		t.Fatalf("no project: %d", code)
	}
}
