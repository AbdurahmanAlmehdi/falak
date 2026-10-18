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
	svc := func(s string) string { return `{"services":{"web":{"image":"nginx",` + s + `}}}` }
	for _, tc := range []struct{ name, cfg string }{
		{"network_mode host", svc(`"network_mode":"host"`)},
		{"pid host", svc(`"pid":"host"`)},
		{"ipc host", svc(`"ipc":"host"`)},
		{"userns_mode host", svc(`"userns_mode":"host"`)},
		{"cgroup host", svc(`"cgroup":"host"`)},
		{"privileged", svc(`"privileged":true`)},
		{"devices", svc(`"devices":[{"source":"/dev/kvm","target":"/dev/kvm","permissions":"rwm"}]`)},
		{"cap_add SYS_ADMIN", svc(`"cap_add":["NET_BIND_SERVICE","SYS_ADMIN"]`)},
		{"security_opt seccomp=unconfined", svc(`"security_opt":["seccomp=unconfined"]`)},
		{"security_opt apparmor:unconfined", svc(`"security_opt":["apparmor:unconfined"]`)},
		// The control plane's networks: by compose name, by an explicit name labelled with its project, external.
		{"network falak_backend (the control plane's)", `{"services":{"web":{"networks":{"b":null}}},"networks":{"b":{"name":"falak_backend","external":true}}}`},
		{"network cp-explicit (the control plane's)", `{"services":{"web":{"networks":{"b":null}}},"networks":{"b":{"name":"cp-explicit"}}}`},
		{"volume falak_pg-data (the control plane's)", `{"services":{"web":{"volumes":[{"type":"volume","source":"pg","target":"/d"}]}},"volumes":{"pg":{"name":"falak_pg-data","external":true}}}`},
		{"network_mode of container falak-db-1 (the control plane's)", svc(`"network_mode":"container:falak-db-1"`)},
		{"volumes_from of container falak-db-1 (the control plane's)", svc(`"volumes_from":["container:falak-db-1:ro"]`)},
		{"bind mount of /var/run/docker.sock", svc(`"volumes":[{"type":"bind","source":"/var/run/docker.sock","target":"/s"}]`)},
		{"bind mount of /", svc(`"volumes":[{"type":"bind","source":"/","target":"/h"}]`)},
		{"bind mount of /run", svc(`"volumes":[{"type":"bind","source":"/run","target":"/h"}]`)},
		{"bind mount of /opt/falak/state", svc(`"volumes":[{"type":"bind","source":"/opt/falak/state","target":"/h"}]`)},
		{"bind mount of /proc/1/root", svc(`"volumes":[{"type":"bind","source":"/proc/1/root","target":"/h"}]`)},
		{"bind mount of /srv/r/link", svc(`"volumes":[{"type":"bind","source":"/srv/r/link","target":"/h"}]`)},
		{"bind mount of /srv/r/dangling (through an unresolvable symlink)", svc(`"volumes":[{"type":"bind","source":"/srv/r/dangling","target":"/h"}]`)},
		{"name falak_x (the control plane's project)", ``},
		{"exit", ``},
	} {
		name, cfg := tc.name, tc.cfg
		t.Run(name, func(t *testing.T) {
			s, e, fr, _, root := newSvc(t)
			e.networks["falak_backend"], e.networks["cp-explicit"] = nil, nil
			e.networkLabels = map[string]map[string]string{"cp-explicit": {LabelComposeProject: "falak"}}
			addComposeContainer(e, "falak", "db", "postgres:17", "sha256:pg")
			// A link a container planted in the release directory, and one to a path that does not exist yet.
			os.MkdirAll(filepath.Join(root, "srv/r"), 0o755)
			os.MkdirAll(filepath.Join(root, "etc"), 0o755)
			os.Symlink("/etc", filepath.Join(root, "srv/r/link"))
			os.Symlink("/etc/new", filepath.Join(root, "srv/r/dangling"))
			project := "shop"
			if strings.HasPrefix(name, "name falak_x") {
				project, cfg = "falak_x", `{"services":{}}`
			}
			res := runner.Result{Stdout: []byte(cfg)}
			if cfg == "" {
				res = runner.Result{ExitCode: 1, Stderr: []byte("invalid compose file")}
			}
			fr.On("docker compose -p "+project+" -f compose.yaml config", res)
			fin, _ := exec1(t, s, "docker.compose.up", ComposeUpPayload{Project: project, Directory: "/srv/r", Files: files})
			if fin.Error == "" || fr.Ran("docker compose -p "+project+" -f compose.yaml up") {
				t.Fatalf("deployed: %+v %v", fin, fr.Lines())
			}
			if cfg != "" && !strings.Contains(fin.Error, name+" is not allowed on the control-plane machine") {
				t.Fatalf("error %q", fin.Error)
			}
		})
	}
}

// What stays allowed: safe capabilities, the project's own networks and volumes, binds under its directory, a
// sibling service's namespace.
func TestComposeAllowedOnTheControlPlaneHost(t *testing.T) {
	asControlPlaneHost(t, "falak.slice")
	s, e, fr, _, _ := newSvc(t)
	e.networks["falak_backend"] = nil
	cfg := `{"services":{"web":{"cap_add":["NET_BIND_SERVICE","CAP_CHOWN"],"security_opt":["no-new-privileges:true"],` +
		`"networks":{"default":null},"volumes":[{"type":"bind","source":"/srv/r/data","target":"/d"},{"type":"volume","source":"db","target":"/v"},` +
		`{"type":"volume","target":"/anon"}]},"side":{"network_mode":"service:web","volumes_from":["web"]}},` +
		`"networks":{"default":{"name":"shop_default"}},"volumes":{"db":{"name":"shop_db"}}}`
	fr.On("docker compose -p shop -f compose.yaml config", runner.Result{Stdout: []byte(cfg)})
	fin, _ := exec1(t, s, "docker.compose.up", ComposeUpPayload{Project: "shop", Directory: "/srv/r", Files: []ComposeFile{{Name: "compose.yaml", Content: "services: {}\n"}}})
	if fin.Error != "" || !fr.Ran("docker compose -p shop -f compose.yaml -f ") {
		t.Fatalf("%+v %v", fin, fr.Lines())
	}
}

func TestDockerSiteRefusedOnTheControlPlaneHost(t *testing.T) {
	asControlPlaneHost(t, "falak.slice")
	s, e, _, _, _ := newSvc(t)
	e.networks["falak_backend"] = nil
	addComposeContainer(e, "falak", "db", "postgres:17", "sha256:pg")
	for want, p := range map[string]RunPayload{
		"network mode host": {Network: "host"},
		"network mode of container falak-db-1 (the control plane's)": {Network: "container:falak-db-1"},
		"network falak_backend (the control plane's)":                {Networks: []NetworkJoin{{Name: "falak_backend"}}},
		"network falak_edge (the control plane's)":                   {Network: "falak_edge"},
		"bind mount of /var/lib/docker/volumes":                      {Volumes: []VolumeSpec{{Source: "/var/lib/docker/volumes", Target: "/v"}}},
		"volume falak_pg-data (the control plane's)":                 {Volumes: []VolumeSpec{{Source: "falak_pg-data", Target: "/v"}}},
	} {
		p.Name, p.Image = "site-x", "nginx:1"
		fin, _ := exec1(t, s, "docker.run", p)
		if !strings.Contains(fin.Error, want+" is not allowed on the control-plane machine") {
			t.Errorf("%s: %q", want, fin.Error)
		}
	}
	fin, _ := exec1(t, s, "docker.run", RunPayload{Name: "site-ok", Image: "nginx:1", Volumes: []VolumeSpec{{Source: "/srv/falak/sites/x/data", Target: "/d"}}})
	if fin.Error != "" {
		t.Fatalf("allowed site refused: %s", fin.Error)
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
