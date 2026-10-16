package cli

import (
	"strings"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/cli/api"
)

func TestMCPSnapshotsForkPause(t *testing.T) {
	h, f := sbxHarness(t)

	if r := callTool(t, h, "sandbox_snapshot", map[string]any{"id": "brave-otter", "name": "node-ready"}); r.IsError || !strings.Contains(r.Content[0].Text, `"name":"node-ready"`) {
		t.Fatalf("snapshot: %+v", r)
	}
	if got := string(f.bodies["POST "+api.Path(api.PathSandboxSnapshots, "sandbox", sbxID)]); got != `{"name":"node-ready"}` {
		t.Fatalf("snapshot body %s", got)
	}
	if last := f.requests[len(f.requests)-1]; !strings.HasSuffix(last, "/snapshots?wait=1") {
		t.Fatalf("snapshot not waited: %s", last)
	}

	if r := callTool(t, h, "sandbox_fork", map[string]any{"snapshot": "node-ready", "count": 2, "ttl": "30m"}); r.IsError || !strings.Contains(r.Content[0].Text, `"name":"node-ready-2"`) {
		t.Fatalf("fork: %+v", r)
	}
	if got := string(f.bodies["POST "+api.Path(api.PathSnapshotFork, "snapshot", "node-ready")]); got != `{"count":2,"ttl":1800}` {
		t.Fatalf("fork body %s", got)
	}
	if r := callTool(t, h, "sandbox_fork", map[string]any{"count": 2}); !r.IsError || r.Content[0].Text != "snapshot is required" {
		t.Fatalf("fork without snapshot: %+v", r)
	}

	if r := callTool(t, h, "sandbox_pause", map[string]any{"id": "brave-otter"}); r.IsError || !strings.Contains(r.Content[0].Text, `"status":"paused"`) {
		t.Fatalf("pause: %+v", r)
	}
	if r := callTool(t, h, "sandbox_resume", map[string]any{"id": sbxID}); r.IsError || !strings.Contains(r.Content[0].Text, `"status":"running"`) {
		t.Fatalf("resume: %+v", r)
	}
	if r := callTool(t, h, "sandbox_list_snapshots", map[string]any{}); r.IsError || !strings.HasPrefix(r.Content[0].Text, `[{"id":"`+sbxSnapID) {
		t.Fatalf("list snapshots: %+v", r)
	}

	if r := callTool(t, h, "sandbox_create", map[string]any{"from_snapshot": "node-ready", "idle_pause": "10m", "ttl": "1h"}); r.IsError {
		t.Fatalf("create from snapshot: %+v", r)
	}
	if got := lastBody(t, f, "POST "+api.PathSandboxes); got != `{"idle_pause":600,"snapshot":"node-ready","ttl":3600}` {
		t.Fatalf("create body %s", got)
	}
	if r := callTool(t, h, "sandbox_create", map[string]any{"from_snapshot": "node-ready", "image": "node:22"}); !r.IsError || !strings.Contains(r.Content[0].Text, "from_snapshot takes") {
		t.Fatalf("clash: %+v", r)
	}
	if r := callTool(t, h, "sandbox_create", map[string]any{"idle_pause": 30}); !r.IsError || !strings.Contains(r.Content[0].Text, "at least 1m") {
		t.Fatalf("short idle: %+v", r)
	}
}
