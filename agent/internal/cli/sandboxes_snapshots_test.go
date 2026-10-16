package cli

import (
	"encoding/json"
	"strings"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/cli/api"
)

func lastBody(t *testing.T, f *sbxFake, key string) string {
	t.Helper()
	var v map[string]any
	f.body(key, &v)
	b, _ := json.Marshal(v)
	return string(b)
}

func TestSandboxSnapshotCommand(t *testing.T) {
	h, f := sbxHarness(t)
	key := "POST " + api.Path(api.PathSandboxSnapshots, "sandbox", sbxID)

	if code := h.run("sandbox", "snapshot", "brave-otter", "--name", "node-ready", "--stop"); code != 0 {
		t.Fatalf("snapshot: %d %s", code, h.err.String())
	}
	if got := lastBody(t, f, key); got != `{"name":"node-ready","stop":true}` {
		t.Fatalf("body %s", got)
	}
	if last := f.requests[len(f.requests)-1]; last != key+"?wait=1" {
		t.Fatalf("request %s", last)
	}
	if out := h.out.String(); !strings.Contains(out, "node-ready ("+sbxSnapID+"): ready, 30.0m on main-1") || !strings.Contains(out, "falak sandbox fork node-ready") {
		t.Fatalf("out %q", out)
	}

	h.out.Reset()
	if code := h.run("sandbox", "snapshot", sbxID, "--wait=false", "--json"); code != 0 || !strings.Contains(h.out.String(), `"name": "node-ready"`) {
		t.Fatalf("json: %d %s", code, h.out.String())
	}
	if last := f.requests[len(f.requests)-1]; last != key+"?" || string(f.bodies[key]) != "{}" {
		t.Fatalf("defaults %s %s", last, f.bodies[key])
	}
	if code := h.run("sandbox", "snapshot"); code != ExitUsage {
		t.Fatalf("no args: %d", code)
	}
}

func TestSandboxSnapshotsListAndRm(t *testing.T) {
	h, f := sbxHarness(t)
	if code := h.run("sandbox", "snapshots"); code != 0 {
		t.Fatalf("snapshots: %d %s", code, h.err.String())
	}
	lines := strings.Split(strings.TrimSpace(h.out.String()), "\n")
	if len(lines) != 2 || strings.Join(strings.Fields(lines[0]), " ") != "NAME STATUS SOURCE SERVER IMAGE SIZE CREATED" ||
		!strings.HasPrefix(strings.Join(strings.Fields(lines[1]), " "), "node-ready ready brave-otter main-1 node:22 30.0m 2026-10-1") {
		t.Fatalf("list:\n%s", h.out.String())
	}
	if last := f.requests[len(f.requests)-1]; last != "GET /api/v1/sandboxes/snapshots?" {
		t.Fatalf("request %s", last)
	}

	if code := h.run("sandbox", "snapshots", "ls", "--sandbox", "brave-otter"); code != 0 || f.requests[len(f.requests)-1] != "GET /api/v1/sandboxes/snapshots?sandbox="+sbxID {
		t.Fatalf("ls --sandbox: %d %v", code, f.requests)
	}

	h.out.Reset()
	if code := h.run("sandbox", "snapshots", "rm", "node-ready", sbxSnapID, "gone"); code != ExitError || !strings.Contains(h.out.String(), "deleted node-ready\ndeleted "+sbxSnapID) || !strings.Contains(h.err.String(), "gone:") {
		t.Fatalf("rm: %d %q %q", code, h.out.String(), h.err.String())
	}
	if code := h.run("sandbox", "snapshots", "rm"); code != ExitUsage {
		t.Fatalf("rm without args: %d", code)
	}
	if code := h.run("sandbox", "snapshots", "stray"); code != ExitUsage {
		t.Fatalf("stray arg: %d", code)
	}
}

func TestSandboxFork(t *testing.T) {
	h, f := sbxHarness(t)
	key := "POST " + api.Path(api.PathSnapshotFork, "snapshot", "node-ready")
	code := h.run("sandbox", "fork", "node-ready", "-n", "2", "--name", "try", "--ttl", "30m", "--env", "A=1", "--idle-pause", "10m")
	if code != 0 {
		t.Fatalf("fork: %d %s", code, h.err.String())
	}
	if got := lastBody(t, f, key); got != `{"count":2,"env":{"A":"1"},"idle_pause":600,"name":"try","ttl":1800}` {
		t.Fatalf("body %s", got)
	}
	if last := f.requests[len(f.requests)-1]; last != key+"?wait=1" {
		t.Fatalf("request %s", last)
	}
	out := h.out.String()
	if !strings.Contains(out, "node-ready-1") || !strings.Contains(out, "node-ready-2") || !strings.Contains(out, sbxID2) {
		t.Fatalf("out %s", out)
	}

	h.out.Reset()
	if code := h.run("sandbox", "fork", "node-ready", "--json", "--wait=false"); code != 0 || !strings.HasPrefix(strings.TrimSpace(h.out.String()), "[") {
		t.Fatalf("json: %d %s", code, h.out.String())
	}
	if f.requests[len(f.requests)-1] != key+"?" {
		t.Fatalf("request %s", f.requests[len(f.requests)-1])
	}
	for _, bad := range [][]string{{"node-ready", "-n", "11"}, {"node-ready", "-n", "0"}, {"node-ready", "--idle-pause", "30s"}, {"node-ready", "--ttl", "soon"}, {"node-ready", "--env", "X"}, {}} {
		if code := h.run(append([]string{"sandbox", "fork"}, bad...)...); code != ExitUsage {
			t.Errorf("%v: exit %d", bad, code)
		}
	}
}

func TestSandboxCreateFromSnapshotIdleAndDB(t *testing.T) {
	h, f := sbxHarness(t)
	code := h.run("sandbox", "create", "--from", "node-ready", "--name", "again", "--ttl", "2h", "--env", "A=1", "--idle-pause", "10m", "--db", "pg-main/shop")
	if code != 0 {
		t.Fatalf("create: %d %s", code, h.err.String())
	}
	if got := lastBody(t, f, "POST "+api.PathSandboxes); got != `{"db":{"database":"shop","instance":"pg-main"},"env":{"A":"1"},"idle_pause":600,"name":"again","snapshot":"node-ready","ttl":7200}` {
		t.Fatalf("body %s", got)
	}
	for _, bad := range [][]string{
		{"--from", "s", "--image", "node:22"}, {"--from", "s", "--cpus", "2"}, {"--from", "s", "--memory", "2g"}, {"--from", "s", "--disk", "5g"},
		{"--from", "s", "--network", "none"}, {"--from", "s", "--server", "main-1"}, {"--from", "s", "--repo", "https://github.com/a/b"},
		{"--idle-pause", "10s"}, {"--idle-pause", "soon"}, {"--db", "nodb"}, {"--db", "/x"},
	} {
		if code := h.run(append([]string{"sandbox", "create"}, bad...)...); code != ExitUsage {
			t.Errorf("%v: exit %d %s", bad, code, h.err.String())
		}
	}
	h.err.Reset()
	h.run("sandbox", "create", "--from", "s", "--image", "node:22", "--cpus", "2")
	if !strings.Contains(h.err.String(), "drop --cpus, --image") {
		t.Errorf("clash message: %s", h.err.String())
	}
}

func TestSandboxPauseResumeDB(t *testing.T) {
	h, f := sbxHarness(t)
	if code := h.run("sandbox", "pause", "brave-otter"); code != 0 || !strings.Contains(h.out.String(), "brave-otter: paused") {
		t.Fatalf("pause: %d %s %s", code, h.out.String(), h.err.String())
	}
	if last := f.requests[len(f.requests)-1]; last != "POST /api/v1/sandboxes/"+sbxID+"/pause?" {
		t.Fatalf("request %s", last)
	}
	h.out.Reset()
	if code := h.run("sandbox", "resume", sbxID, "ghost"); code != ExitError || !strings.Contains(h.out.String(), "brave-otter: running") || !strings.Contains(h.err.String(), `ghost: sandbox "ghost" not found`) {
		t.Fatalf("resume: %d %q %q", code, h.out.String(), h.err.String())
	}
	h.out.Reset()
	if code := h.run("sandbox", "pause", sbxID, "--json"); code != 0 || !strings.Contains(h.out.String(), `"status": "paused"`) {
		t.Fatalf("pause --json: %d %s", code, h.out.String())
	}

	h.out.Reset()
	if code := h.run("sandbox", "db", "brave-otter", "pg-main/shop"); code != 0 || !strings.Contains(h.out.String(), "pg-main/shop copied to brave-otter:/workspace/db/shop.dump (2.0m)") {
		t.Fatalf("db: %d %s %s", code, h.out.String(), h.err.String())
	}
	if got := lastBody(t, f, "POST "+api.Path(api.PathSandboxDB, "sandbox", sbxID)); got != `{"database":"shop","instance":"pg-main"}` {
		t.Fatalf("body %s", got)
	}
	for _, bad := range [][]string{{"brave-otter"}, {"brave-otter", "shop"}, {"brave-otter", "pg-main/"}} {
		if code := h.run(append([]string{"sandbox", "db"}, bad...)...); code != ExitUsage {
			t.Errorf("%v: exit %d", bad, code)
		}
	}
}

func TestSandboxShowV21AndPausedResolve(t *testing.T) {
	var s api.Sandbox
	if err := json.Unmarshal([]byte(`{"id":"x","from_snapshot":{"id":"y","name":"node-ready"},"idle_pause_seconds":600,"last_activity_at":"2026-10-17T10:30:00+00:00"}`), &s); err != nil {
		t.Fatal(err)
	}
	rows := v21Rows(s)
	if len(rows) != 3 || rows[0] != [2]string{"from snapshot", "node-ready"} || rows[1] != [2]string{"idle pause", "10m"} {
		t.Fatalf("rows %v", rows)
	}
	if len(v21Rows(api.Sandbox{})) != 0 {
		t.Fatal("rows for a plain sandbox")
	}
}
