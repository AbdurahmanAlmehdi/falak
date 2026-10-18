package docker

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/localedge"
	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// asControlPlaneHost makes the test run as the control-plane machine (agent.Build sets Local from local-edge.env).
func asControlPlaneHost(t *testing.T, slice string) {
	old := Local
	Local = localedge.Config{Enabled: true, Addr: "172.17.0.1", HTTPPort: 8080, HTTPSPort: 8443, Trust: "172.30.0.2/32", WorkloadSlice: slice}
	t.Cleanup(func() { Local = old })
}

func TestContainersGoUnderTheWorkloadSlice(t *testing.T) {
	s, e, _, _, _ := newSvc(t)
	fin, _ := exec1(t, s, "docker.run", RunPayload{Name: "redis-a", Image: "redis:7", Network: "host"})
	if c := e.containers[fin.Result.(RunResult).ContainerID]; c.body.HostConfig.CgroupParent != "" || c.body.HostConfig.NetworkMode != "host" {
		t.Fatalf("regular server changed: %+v", c.body.HostConfig)
	}
	asControlPlaneHost(t, "falak.slice")
	fin, _ = exec1(t, s, "docker.run", RunPayload{Name: "redis-b", Image: "redis:7"})
	if c := e.containers[fin.Result.(RunResult).ContainerID]; c.body.HostConfig.CgroupParent != "falak.slice" {
		t.Fatalf("cgroup parent %q", c.body.HostConfig.CgroupParent)
	}
	// Host networking is refused before anything is replaced.
	n := len(e.containers)
	fin, _ = exec1(t, s, "docker.run", RunPayload{Name: "redis-a", Image: "redis:7", Network: "host"})
	if !strings.Contains(fin.Error, "not allowed on the control-plane machine") || len(e.containers) != n || e.byName("redis-a") == nil {
		t.Fatalf("host network: %+v", fin)
	}
	sw := SwapPayload{Site: "shop", Image: "nginx:1", ContainerPort: 80, EdgeRouteID: "shop", Network: "host"}
	sw.Ports.Blue, sw.Ports.Green = 9001, 9002
	fin, _ = exec1(t, s, "deploy.container.swap", sw)
	if !strings.Contains(fin.Error, "not allowed on the control-plane machine") {
		t.Fatalf("swap host network: %+v", fin)
	}
}

const resolved = `{"name":"shop","services":{"web":{"image":"nginx","network_mode":"bridge"},"worker":{"image":"busybox"},"inc":{"image":"redis"}}}`

func TestComposeHostOverride(t *testing.T) {
	s, _, fr, _, root := newSvc(t)
	files := []ComposeFile{{Name: "compose.yaml", Content: "include: [more.yaml]\nservices:\n  web:\n    image: nginx\n"}}
	fin, _ := exec1(t, s, "docker.compose.up", ComposeUpPayload{Project: "shop", Directory: "/srv/r", Files: files})
	if fin.Error != "" || strings.Join(fr.Lines(), "|") != "docker compose -p shop -f compose.yaml up -d --pull missing --remove-orphans" {
		t.Fatalf("regular server changed: %+v %v", fin, fr.Lines())
	}

	asControlPlaneHost(t, "falak.slice")
	fr.Reset()
	fr.On("docker compose -p shop -f compose.yaml config --format json", runner.Result{Stdout: []byte(resolved)})
	fin, _ = exec1(t, s, "docker.compose.up", ComposeUpPayload{Project: "shop", Directory: "/srv/r", Files: files, Env: map[string]string{"TAG": "1"}})
	override := filepath.Join(root, "run/falak/env/compose-shop.host.yaml")
	calls := fr.Calls()
	if fin.Error != "" || len(calls) != 2 || calls[1].Line != "docker compose -p shop -f compose.yaml -f "+override+" up -d --pull missing --remove-orphans" {
		t.Fatalf("%+v %v", fin, fr.Lines())
	}
	if calls[0].Stdout != nil || strings.Join(calls[0].Env, ",") != "TAG=1" {
		t.Fatalf("config must not stream (secrets) and must see the deploy's env: %+v", calls[0].Cmd)
	}
	// Services from include: (resolved by compose) are covered too.
	b, _ := os.ReadFile(override)
	want := "{\n  \"services\": {\n    \"inc\": {\n      \"cgroup_parent\": \"falak.slice\"\n    },\n    \"web\": {\n      \"cgroup_parent\": \"falak.slice\"\n    },\n" +
		"    \"worker\": {\n      \"cgroup_parent\": \"falak.slice\"\n    }\n  }\n}\n"
	if string(b) != want {
		t.Fatalf("override:\n%s", b)
	}
	if st, _ := os.Stat(override); st.Mode().Perm() != 0o600 {
		t.Fatalf("override mode %v", st.Mode())
	}
}

func TestComposeRefusedOnTheControlPlaneHost(t *testing.T) {
	asControlPlaneHost(t, "falak.slice")
	files := []ComposeFile{{Name: "compose.yaml", Content: "services: {}\n"}}
	for name, cfg := range map[string]string{
		"network_mode host": `{"services":{"web":{"image":"nginx","network_mode":"host"}}}`,
		"pid host":          `{"services":{"web":{"image":"nginx","pid":"host"}}}`,
		"privileged":        `{"services":{"web":{"image":"nginx","privileged":true}}}`,
		"exit":              ``,
	} {
		t.Run(name, func(t *testing.T) {
			s, _, fr, _, _ := newSvc(t)
			res := runner.Result{Stdout: []byte(cfg)}
			if cfg == "" {
				res = runner.Result{ExitCode: 1, Stderr: []byte("invalid compose file")}
			}
			fr.On("docker compose -p shop -f compose.yaml config", res)
			fin, _ := exec1(t, s, "docker.compose.up", ComposeUpPayload{Project: "shop", Directory: "/srv/r", Files: files})
			if fin.Error == "" || fr.Ran("docker compose -p shop -f compose.yaml up") {
				t.Fatalf("deployed: %+v %v", fin, fr.Lines())
			}
			if cfg != "" && !strings.Contains(fin.Error, name+" is not allowed on the control-plane machine") {
				t.Fatalf("error %q", fin.Error)
			}
		})
	}
}

func TestPruneSparesTheLocalEdgeProject(t *testing.T) {
	asControlPlaneHost(t, "")
	Local.ProjectName = "falakcp"
	t.Setenv("FALAK_PROJECT_NAME", "other")
	s, e, _, _, _ := newSvc(t)
	exec1(t, s, "docker.prune", map[string]any{"images": "none", "networks": false})
	if strings.Join(e.prunes, "|") != `containers {"label!":["com.docker.compose.project=falakcp"]}` {
		t.Fatalf("prunes %q", e.prunes)
	}
}
