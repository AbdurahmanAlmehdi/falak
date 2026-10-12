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
	runsList  []map[string]any
	approveAs []string // successive answers of POST approve: ok, changed, forbidden, not_waiting
	hashes    []any    // plan_hash of each POST approve
	drift     map[string]any
	unmanaged []map[string]any
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
	case p == api.Path(api.PathConfigRuns, "project", projectID) && r.Method == http.MethodGet:
		f.json(w, 200, map[string]any{"data": f.runsList, "links": map[string]any{}, "meta": map[string]any{}})
	case strings.HasSuffix(p, "/approve"):
		answer := "ok"
		if len(f.approveAs) > 0 {
			answer, f.approveAs = f.approveAs[0], f.approveAs[1:]
		}
		f.hashes = append(f.hashes, body["plan_hash"])
		stored, _ := f.run["approval"].(map[string]any)
		switch {
		case body["plan_hash"] == nil:
			f.json(w, 422, map[string]any{"message": "The plan hash field is required.", "errors": map[string][]string{"plan_hash": {"The plan hash field is required."}}})
			return
		case answer == "ok" && stored != nil && body["plan_hash"] != stored["plan_hash"]:
			answer = "changed"
		}
		switch answer {
		case "forbidden":
			f.json(w, 403, map[string]string{"message": "You requested this run: another approver must approve it."})
		case "not_waiting":
			f.json(w, 409, map[string]string{"message": "This run is not awaiting approval."})
		case "changed":
			changed := waitingRun(true)
			changed["approval"].(map[string]any)["risky"] = []map[string]any{{"op": "update", "path": "/units/db/version", "rule": "database.version", "reason": "A major upgrade."}}
			changed["approval"].(map[string]any)["plan_hash"] = "h2"
			f.run = changed
			f.json(w, 409, map[string]any{"message": "The plan changed since it was shown; review it again.", "data": changed})
		default:
			f.run = runData("applying")
			f.json(w, 200, map[string]any{"data": f.run})
		}
	case strings.HasSuffix(p, "/reject"):
		rejected := runData("cancelled")
		rejected["cancel_reason"] = "rejected"
		f.run = rejected
		f.json(w, 200, map[string]any{"data": rejected})
	case p == api.Path(api.PathConfigDrift, "project", projectID):
		f.json(w, 200, map[string]any{"data": f.drift})
	case p == api.Path(api.PathConfigUnmanaged, "project", projectID):
		f.json(w, 200, map[string]any{"data": f.unmanaged})
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
	// A file without environments deploys production only: -e is the control plane's to check.
	if code := h.run("validate", "-e", "production", "falak.json"); code != 0 {
		t.Fatalf("no environments: %d %s", code, h.err.String())
	}
	writeFile(t, h.wd, "falak.yaml", validFile)
	if code := h.run("validate", "-e", "nope"); code != ExitInvalid || !strings.Contains(h.err.String(), "environment.unknown") {
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

	// A file without environments plans the project's production environment (asked from GET /projects).
	writeFile(t, h.wd, "falak.yaml", "version: 1\nunits:\n  web: {type: app, runtime: node}\n")
	h.branch = "feature/x"
	if code := h.run("plan"); code != 0 || f.bodies[len(f.bodies)-1]["environment"] != "production" {
		t.Fatalf("no environments: %d %s %v", code, h.err.String(), f.bodies[len(f.bodies)-1])
	}
	h.branch = "main"

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

// waitingRun is a run awaiting an approval the caller may (or may not) give.
func waitingRun(canApprove bool) map[string]any {
	r := runData("awaiting_approval")
	var blocked any
	if !canApprove {
		blocked = "You requested this run: another approver must approve it."
	}
	r["approval"] = map[string]any{"required": true, "risky": []map[string]any{{"path": "/units/web/deploy/strategy", "rule": "production.strategy",
		"reason": "Changing the deploy strategy of a production environment needs an approval."}}, "expires_at": "2026-10-18T12:00:00Z",
		"approved_by": nil, "approved_at": nil, "rejected_by": nil, "rejected_at": nil, "comment": nil, "can_approve": canApprove, "blocked_reason": blocked,
		"plan_hash": "h1"}
	r["cancel_reason"] = nil
	return r
}

func TestRunsListAndShow(t *testing.T) {
	h, f := configHarness(t)
	h.env["FALAK_PROJECT"] = projectID
	cancelled := runData("cancelled")
	cancelled["cancel_reason"] = "superseded"
	f.runsList = []map[string]any{waitingRun(true), cancelled}
	if code := h.run("runs"); code != 0 {
		t.Fatalf("%d %s", code, h.err.String())
	}
	if out := h.out.String(); !strings.Contains(out, "awaiting_approval") || !strings.Contains(out, "cancelled (superseded)") {
		t.Fatalf("list:\n%s", out)
	}
	if code := h.run("runs", "list", "-e", "staging", "--limit", "1"); code != 0 || strings.Count(h.out.String(), "01jrun") != 1 {
		t.Fatalf("limit: %d %s", code, h.out.String())
	}
	if q := f.queries[len(f.queries)-1]; q != "environment=staging&per_page=1" {
		t.Fatalf("query %q", q)
	}
	f.run = waitingRun(false)
	if code := h.run("runs", "show", "01jrun0000000000000000000a"); code != 0 {
		t.Fatalf("%d %s", code, h.err.String())
	}
	for _, want := range []string{"waiting (expires 2026-10-18T12:00:00Z)", "another approver must approve it", "! update /units/web/deploy/strategy"} {
		if !strings.Contains(h.out.String(), want) {
			t.Errorf("show: missing %q in\n%s", want, h.out.String())
		}
	}
}

func TestApproveAndReject(t *testing.T) {
	h, f := configHarness(t)
	const run = "01jrun0000000000000000000a"

	// Not a terminal and no --yes: a usage error, nothing approved.
	f.run = waitingRun(true)
	if code := h.run("approve", run); code != ExitUsage || len(f.approveAs) != 0 {
		t.Fatalf("no --yes: %d %s", code, h.err.String())
	}
	n := len(f.requests)
	if code := h.run("approve", run, "--yes", "--comment", "ok by me"); code != 0 || !strings.Contains(h.out.String(), "Approved run "+run+": applying") {
		t.Fatalf("approve: %d %s %s", code, h.out.String(), h.err.String())
	}
	if f.requests[len(f.requests)-1] != "POST /api/v1/config/runs/"+run+"/approve" || f.bodies[len(f.bodies)-1]["comment"] != "ok by me" || len(f.requests) != n+2 {
		t.Fatalf("requests %v %v", f.requests[n:], f.bodies[len(f.bodies)-1])
	}
	if f.hashes[len(f.hashes)-1] != "h1" {
		t.Fatalf("plan_hash sent: %v", f.hashes)
	}
	if !strings.Contains(h.out.String(), "! update /units/web/deploy/strategy (production.strategy)") {
		t.Fatalf("risky items not shown:\n%s", h.out.String())
	}

	// Blocked (self-approval refused by the project): exit 6 with the reason, no POST.
	f.run = waitingRun(false)
	n = len(f.requests)
	if code := h.run("approve", run, "--yes"); code != ExitApproval || !strings.Contains(h.err.String(), "another approver") || len(f.requests) != n+1 {
		t.Fatalf("blocked: %d %s", code, h.err.String())
	}
	// The server refuses anyway (403) → 1.
	f.run, f.approveAs = waitingRun(true), []string{"forbidden"}
	if code := h.run("approve", run, "--yes"); code != ExitError || !strings.Contains(h.err.String(), "another approver") || strings.Contains(h.err.String(), "token lacks") {
		t.Fatalf("403: %d %s", code, h.err.String())
	}
	// The plan changed: never approved blindly, even with --yes (not a terminal → 6).
	f.run, f.approveAs = waitingRun(true), []string{"changed"}
	if code := h.run("approve", run, "--yes"); code != ExitApproval || !strings.Contains(h.err.String(), "plan changed") {
		t.Fatalf("changed: %d %s", code, h.err.String())
	}
	// Interactive: the new risky change is shown and confirmed again.
	f.run, f.approveAs = waitingRun(true), []string{"changed", "ok"}
	h.out.Reset()
	h.err.Reset()
	h.app = h.newApp("y\ny\n")
	h.app.Interactive = true
	f.hashes = nil
	if code := h.app.Run(t.Context(), []string{"approve", run}); code != 0 || !strings.Contains(h.out.String(), "! update /units/db/version (database.version)") {
		t.Fatalf("interactive: %d %s %s", code, h.out.String(), h.err.String())
	}
	// Each POST carries the hash of the run shown just before it: h1, then the 409's run (h2), no re-fetch.
	if len(f.hashes) != 2 || f.hashes[0] != "h1" || f.hashes[1] != "h2" {
		t.Fatalf("hashes %v", f.hashes)
	}
	// A run without a plan_hash (an older control plane) is not approved blindly.
	old := waitingRun(true)
	delete(old["approval"].(map[string]any), "plan_hash")
	f.run, f.hashes = old, nil
	if code := h.run("approve", run, "--yes"); code != ExitError || len(f.hashes) != 0 || !strings.Contains(h.err.String(), "no plan_hash") {
		t.Fatalf("no hash: %d %s %v", code, h.err.String(), f.hashes)
	}
	// Not waiting.
	f.run = runData("applied")
	if code := h.run("approve", run, "--yes"); code != ExitError {
		t.Fatalf("applied: %d", code)
	}

	// Reject.
	f.run = waitingRun(true)
	if code := h.run("reject", run, "--yes", "--comment", "not now", "--json"); code != 0 {
		t.Fatalf("reject: %d %s", code, h.err.String())
	}
	var got map[string]any
	if err := json.Unmarshal(h.out.Bytes(), &got); err != nil || got["cancel_reason"] != "rejected" || f.bodies[len(f.bodies)-1]["comment"] != "not now" {
		t.Fatalf("reject json: %v %s", err, h.out.String())
	}
}

func TestApplyApprove(t *testing.T) {
	h, f := configHarness(t)
	writeFile(t, h.wd, "falak.yaml", validFile)
	h.env["FALAK_PROJECT"] = projectID

	// --wait stops at the approval: exit 6 with how to approve.
	f.run, f.runPolls = runData("planning"), []map[string]any{waitingRun(true)}
	if code := h.run("apply", "--wait"); code != ExitApproval || !strings.Contains(h.err.String(), "falak approve 01jrun0000000000000000000a") || !strings.Contains(h.err.String(), "https://falak.test/") {
		t.Fatalf("wait: %d %s", code, h.err.String())
	}
	// --approve --yes approves and goes on waiting until the run is applied.
	f.run, f.runPolls = runData("planning"), []map[string]any{waitingRun(true), runData("applied")}
	if code := h.run("apply", "--wait", "--approve", "--yes"); code != 0 || !strings.Contains(h.out.String(), "Approved run") {
		t.Fatalf("approve: %d %s %s", code, h.out.String(), h.err.String())
	}
	// A caller who may not approve: 6, with the reason.
	f.run, f.runPolls = waitingRun(false), nil
	if code := h.run("apply", "--wait", "--approve", "--yes"); code != ExitApproval || !strings.Contains(h.err.String(), "another approver") {
		t.Fatalf("blocked: %d %s", code, h.err.String())
	}
	// Cancel reasons.
	rejected := runData("cancelled")
	rejected["cancel_reason"] = "expired"
	f.run = rejected
	if code := h.run("apply"); code != ExitError || !strings.Contains(h.err.String(), "nobody approved it in time") {
		t.Fatalf("expired: %d %s", code, h.err.String())
	}
}

func TestDriftAndUnmanaged(t *testing.T) {
	h, f := configHarness(t)
	h.env["FALAK_PROJECT"] = projectID
	f.drift = map[string]any{"environment": "production", "status": "in_sync", "checked_at": "2026-10-11T12:00:00Z", "changes": []any{}}
	if code := h.run("drift"); code != 0 || !strings.Contains(h.out.String(), "production matches") {
		t.Fatalf("in sync: %d %s %s", code, h.out.String(), h.err.String())
	}
	if f.requests[len(f.requests)-1] != "GET "+api.Path(api.PathConfigDrift, "project", projectID) || f.queries[len(f.queries)-1] != "environment=production" {
		t.Fatalf("request %v %v", f.requests, f.queries)
	}
	f.drift = map[string]any{"environment": "staging", "status": "drifted", "since": "2026-10-11T10:00:00Z", "baseline_commit": "abcdef1234567",
		"changes": []map[string]any{{"op": "update", "path": "/units/web/deploy/strategy", "before": "in-place", "after": "rolling", "risky": false}}}
	code := h.run("drift", "-e", "staging", "--check")
	if code != ExitDrift || !strings.Contains(h.out.String(), "staging drifted from falak.yaml @ abcdef1") || !strings.Contains(h.out.String(), "~ update /units/web/deploy/strategy: in-place → rolling") {
		t.Fatalf("drifted: %d %s %s", code, h.out.String(), h.err.String())
	}
	if f.requests[len(f.requests)-1] != "POST "+api.Path(api.PathConfigDrift, "project", projectID) || f.bodies[len(f.bodies)-1]["environment"] != "staging" {
		t.Fatalf("check: %v %v", f.requests, f.bodies)
	}
	if code := h.run("drift", "--json"); code != ExitDrift || !strings.Contains(h.out.String(), `"status": "drifted"`) {
		t.Fatalf("json: %d %s", code, h.out.String())
	}
	f.drift = map[string]any{"environment": "production", "status": "unknown", "changes": []any{}}
	if code := h.run("drift"); code != 0 || !strings.Contains(h.out.String(), "drift unknown") {
		t.Fatalf("unknown: %d %s", code, h.out.String())
	}

	f.unmanaged = []map[string]any{{"id": "01jx", "environment": "production", "unit": "old-db", "resource_type": "database_instance",
		"resource_id": "01jdb", "label": "old-db (PostgreSQL 17)", "reason": "removed_from_file", "run_id": "01jrun", "detached_at": "2026-10-11T12:00:00Z"}}
	if code := h.run("config", "unmanaged"); code != 0 || !strings.Contains(h.out.String(), "old-db (PostgreSQL 17)") || !strings.Contains(h.out.String(), "removed_from_file") {
		t.Fatalf("unmanaged: %d %s", code, h.out.String())
	}
	// Without -e: the project's production environment (the API requires one).
	if q := f.queries[len(f.queries)-1]; q != "environment=production" {
		t.Fatalf("unmanaged query %q", q)
	}
}

func TestPlanForANewEnvironment(t *testing.T) {
	h, f := configHarness(t)
	writeFile(t, h.wd, "falak.yaml", validFile)
	h.env["FALAK_PROJECT"] = projectID
	plan := samplePlan()
	plan["environment"], plan["environment_exists"], plan["preview_only"] = "staging", false, true
	f.plan = plan
	if code := h.run("plan", "-e", "staging"); code != 0 || !strings.Contains(h.out.String(), "staging doesn't exist yet: this plan is a preview") {
		t.Fatalf("%d %s %s", code, h.out.String(), h.err.String())
	}
}
