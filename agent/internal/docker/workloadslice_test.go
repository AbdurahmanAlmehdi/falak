package docker

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func withWorkloadSlice(t *testing.T, s string) {
	old := WorkloadSlice
	WorkloadSlice = s
	t.Cleanup(func() { WorkloadSlice = old })
}

func TestContainersGoUnderTheWorkloadSlice(t *testing.T) {
	s, e, _, _, _ := newSvc(t)
	fin, _ := exec1(t, s, "docker.run", RunPayload{Name: "redis-a", Image: "redis:7"})
	if c := e.containers[fin.Result.(RunResult).ContainerID]; c.body.HostConfig.CgroupParent != "" {
		t.Fatalf("cgroup parent without local-edge.env: %q", c.body.HostConfig.CgroupParent)
	}
	withWorkloadSlice(t, "falak.slice")
	fin, _ = exec1(t, s, "docker.run", RunPayload{Name: "redis-b", Image: "redis:7"})
	if c := e.containers[fin.Result.(RunResult).ContainerID]; c.body.HostConfig.CgroupParent != "falak.slice" {
		t.Fatalf("cgroup parent %q", c.body.HostConfig.CgroupParent)
	}
}

func TestComposeHostOverride(t *testing.T) {
	s, _, fr, _, root := newSvc(t)
	files := []ComposeFile{
		{Name: "compose.yaml", Content: "services:\n  web:\n    image: nginx\n  worker:\n    image: busybox\n"},
		{Name: "compose.falak.yaml", Content: "services:\n  web:\n    networks: [falak-env]\n"},
	}
	fin, _ := exec1(t, s, "docker.compose.pull", ComposePullPayload{Project: "shop", Directory: "/srv/r", Files: files})
	if fin.Error != "" || strings.Contains(fr.Calls()[0].Line, HostOverrideFile) {
		t.Fatalf("override without local-edge.env: %+v %v", fin, fr.Lines())
	}
	withWorkloadSlice(t, "falak.slice")
	fr.Reset()
	fin, _ = exec1(t, s, "docker.compose.pull", ComposePullPayload{Project: "shop", Directory: "/srv/r", Files: files})
	if fin.Error != "" || fr.Calls()[0].Line != "docker compose -p shop -f compose.yaml -f compose.falak.yaml -f compose.falak-host.yaml pull --quiet" {
		t.Fatalf("%+v %v", fin, fr.Lines())
	}
	b, _ := os.ReadFile(filepath.Join(root, "srv/r", HostOverrideFile))
	want := "{\n  \"services\": {\n    \"web\": {\n      \"cgroup_parent\": \"falak.slice\"\n    },\n    \"worker\": {\n      \"cgroup_parent\": \"falak.slice\"\n    }\n  }\n}\n"
	if string(b) != want {
		t.Fatalf("override:\n%s", b)
	}
}
