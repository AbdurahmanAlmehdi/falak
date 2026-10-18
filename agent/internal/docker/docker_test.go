package docker

import (
	"context"
	"encoding/base64"
	"encoding/binary"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/envlinks"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

// fakeEngine is an in-memory subset of the Docker Engine API.
type fakeEngine struct {
	mu         sync.Mutex
	images     map[string]string // ref → id
	containers map[string]*fcont // id → container
	seq        int
	pulls      []string
	pullAuth   []string
	prunes     []string
	calls      []string
	failStart  bool
	restarts   []string
	// image id → RepoDigests
	repoDigests map[string][]string
	// network name → "container:alias,alias" joins
	networks map[string][]string
	// network name → labels
	networkLabels map[string]map[string]string
	// container name → docker.update bodies
	updates map[string][]UpdateBody
	// served by GET /events (then the stream ends)
	events []EngineEvent
	// container name → what GET logs serves (stdout and stderr frames) and the query it was asked with
	logs     map[string][2]string
	logQuery []string
	// container name → exit code: the container stops right after it starts (127+ means OOM killed)
	exitOnStart map[string]int
}

type fcont struct {
	id, name, image, imageID string
	running                  bool
	created                  int64
	body                     CreateBody
	health                   string
	restarts                 int
	ports                    map[string][]PortBinding
	exitCode                 int
	oom                      bool
}

func newEngine() *fakeEngine {
	return &fakeEngine{images: map[string]string{}, containers: map[string]*fcont{}, repoDigests: map[string][]string{}, networks: map[string][]string{}}
}

func (e *fakeEngine) byName(n string) *fcont {
	n = strings.TrimPrefix(n, "/")
	for _, c := range e.containers {
		if c.id == n || c.name == n {
			return c
		}
	}
	return nil
}

func jsonOut(w http.ResponseWriter, code int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(code)
	json.NewEncoder(w).Encode(v)
}

func (e *fakeEngine) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	e.mu.Lock()
	defer e.mu.Unlock()
	p := r.URL.Path
	e.calls = append(e.calls, r.Method+" "+p)
	if p == "/version" {
		jsonOut(w, 200, VersionInfo{Version: "27.1.1", APIVersion: "1.46"})
		return
	}
	if !strings.HasPrefix(p, "/v1.43/") {
		jsonOut(w, 400, map[string]string{"message": "bad version " + p})
		return
	}
	p = strings.TrimPrefix(p, "/v1.43")
	q := r.URL.Query()
	switch {
	case r.Method == "POST" && p == "/images/create":
		ref := q.Get("fromImage")
		if t := q.Get("tag"); t != "" {
			ref += ":" + t
		}
		e.pulls = append(e.pulls, ref)
		e.pullAuth = append(e.pullAuth, r.Header.Get("X-Registry-Auth"))
		if strings.Contains(ref, "nope") {
			fmt.Fprintln(w, `{"error":"manifest unknown"}`)
			return
		}
		e.seq++
		e.images[ref] = "sha256:img" + strconv.Itoa(e.seq)
		fmt.Fprintln(w, `{"status":"Pulling from library/x","id":"latest"}`)
		fmt.Fprintln(w, `{"status":"Downloading","progressDetail":{},"progress":"[==>   ]","id":"abc"}`)
		fmt.Fprintln(w, `{"status":"Download complete","progressDetail":{},"id":"abc"}`)
		fmt.Fprintln(w, `{"status":"Status: Downloaded newer image"}`)
	case r.Method == "GET" && strings.HasPrefix(p, "/images/") && strings.HasSuffix(p, "/json"):
		ref := strings.TrimSuffix(strings.TrimPrefix(p, "/images/"), "/json")
		if !strings.Contains(ref, ":") {
			ref += ":latest"
		}
		id, ok := e.images[ref]
		for _, v := range e.images {
			if v == ref {
				id, ok = v, true
			}
		}
		if !ok {
			jsonOut(w, 404, map[string]string{"message": "No such image: " + ref})
			return
		}
		jsonOut(w, 200, map[string]any{"Id": id, "RepoDigests": e.repoDigests[id]})
	case r.Method == "POST" && p == "/containers/create":
		name := q.Get("name")
		if e.byName(name) != nil {
			jsonOut(w, 409, map[string]string{"message": "Conflict"})
			return
		}
		var b CreateBody
		json.NewDecoder(r.Body).Decode(&b)
		ref := b.Image
		if !strings.Contains(ref, ":") {
			ref += ":latest"
		}
		e.seq++
		c := &fcont{id: "c" + strconv.Itoa(e.seq), name: name, image: b.Image, imageID: e.images[ref], created: int64(e.seq), body: b}
		e.containers[c.id] = c
		jsonOut(w, 201, map[string]string{"Id": c.id})
	case r.Method == "GET" && p == "/containers/json":
		var f map[string][]string
		json.Unmarshal([]byte(q.Get("filters")), &f)
		out := []ContainerSummary{}
		for _, c := range e.containers {
			match := true
			for _, l := range f["label"] {
				k, v, _ := strings.Cut(l, "=")
				if c.body.Labels[k] != v {
					match = false
				}
			}
			if !match || (!c.running && q.Get("all") == "") {
				continue
			}
			st := "exited"
			if c.running {
				st = "running"
			}
			out = append(out, ContainerSummary{ID: c.id, Names: []string{"/" + c.name}, Image: c.image, State: st, Created: c.created, Labels: c.body.Labels})
		}
		jsonOut(w, 200, out)
	case strings.HasPrefix(p, "/containers/"):
		rest := strings.TrimPrefix(p, "/containers/")
		parts := strings.SplitN(rest, "/", 2)
		if parts[0] == "prune" {
			e.prunes = append(e.prunes, "containers "+q.Get("filters"))
			jsonOut(w, 200, map[string]any{"SpaceReclaimed": 100})
			return
		}
		c := e.byName(parts[0])
		if c == nil {
			jsonOut(w, 404, map[string]string{"message": "No such container: " + parts[0]})
			return
		}
		action := ""
		if len(parts) == 2 {
			action = parts[1]
		}
		switch {
		case r.Method == "GET" && action == "json":
			var ct Container
			ct.ID, ct.Name, ct.Image = c.id, "/"+c.name, c.imageID
			ct.State.Running = c.running
			ct.State.Status = map[bool]string{true: "running", false: "exited"}[c.running]
			ct.State.ExitCode, ct.State.OOMKilled = c.exitCode, c.oom
			ct.RestartCount = c.restarts
			if c.health != "" {
				ct.State.Health = &struct {
					Status string `json:"Status"`
				}{c.health}
			}
			ct.NetworkSettings.Ports = c.ports
			ct.Config.Image = c.image
			ct.Config.Labels = c.body.Labels
			jsonOut(w, 200, ct)
		case r.Method == "POST" && action == "start":
			if e.failStart {
				jsonOut(w, 500, map[string]string{"message": "port is already allocated"})
				return
			}
			if c.running {
				w.WriteHeader(304)
				return
			}
			c.running = true
			if code, ok := e.exitOnStart[c.name]; ok {
				c.running, c.exitCode, c.oom = false, code, code == 137
			}
			w.WriteHeader(204)
		case r.Method == "GET" && action == "logs":
			e.logQuery = append(e.logQuery, r.URL.RawQuery)
			w.WriteHeader(200)
			for i, text := range e.logs[c.name] {
				if text != "" {
					hdr := []byte{byte(i + 1), 0, 0, 0, 0, 0, 0, 0}
					binary.BigEndian.PutUint32(hdr[4:], uint32(len(text)))
					w.Write(append(hdr, text...))
				}
			}
		case r.Method == "POST" && action == "update":
			var b UpdateBody
			json.NewDecoder(r.Body).Decode(&b)
			if e.updates == nil {
				e.updates = map[string][]UpdateBody{}
			}
			e.updates[c.name] = append(e.updates[c.name], b)
			jsonOut(w, 200, map[string]any{"Warnings": []string{}})
		case r.Method == "POST" && action == "restart":
			e.restarts = append(e.restarts, c.name)
			c.running = true
			w.WriteHeader(204)
		case r.Method == "GET" && action == "stats":
			jsonOut(w, 200, map[string]any{
				"cpu_stats":    map[string]any{"cpu_usage": map[string]any{"total_usage": 3000}, "system_cpu_usage": 20000, "online_cpus": 2},
				"precpu_stats": map[string]any{"cpu_usage": map[string]any{"total_usage": 1000}, "system_cpu_usage": 10000, "online_cpus": 2},
				"memory_stats": map[string]any{"usage": 5000, "limit": 100000, "stats": map[string]any{"inactive_file": 1000}},
			})
		case r.Method == "POST" && action == "stop":
			if !c.running {
				w.WriteHeader(304)
				return
			}
			c.running = false
			w.WriteHeader(204)
		case r.Method == "DELETE" && action == "":
			delete(e.containers, c.id)
			w.WriteHeader(204)
		default:
			jsonOut(w, 404, map[string]string{"message": "unknown " + r.Method + " " + p})
		}
	case r.Method == "GET" && p == "/events":
		var f map[string][]string
		json.Unmarshal([]byte(q.Get("filters")), &f)
		if strings.Join(f["event"], ",") != "oom" || strings.Join(f["type"], ",") != "container" {
			jsonOut(w, 400, map[string]string{"message": "unexpected filters " + q.Get("filters")})
			return
		}
		w.WriteHeader(200)
		for _, ev := range e.events {
			json.NewEncoder(w).Encode(ev)
		}
	case r.Method == "GET" && p == "/networks":
		var f map[string][]string
		json.Unmarshal([]byte(q.Get("filters")), &f)
		out := []map[string]string{}
		for name := range e.networks {
			match := true
			for _, l := range f["label"] {
				k, v, _ := strings.Cut(l, "=")
				if e.networkLabels[name][k] != v {
					match = false
				}
			}
			if match {
				out = append(out, map[string]string{"Name": name})
			}
		}
		jsonOut(w, 200, out)
	case p == "/networks/create" && r.Method == "POST":
		var b struct {
			Name   string
			Labels map[string]string
		}
		json.NewDecoder(r.Body).Decode(&b)
		if _, ok := e.networks[b.Name]; ok {
			jsonOut(w, 409, map[string]string{"message": "network with name " + b.Name + " already exists"})
			return
		}
		e.networks[b.Name] = nil
		if e.networkLabels == nil {
			e.networkLabels = map[string]map[string]string{}
		}
		e.networkLabels[b.Name] = b.Labels
		jsonOut(w, 201, map[string]string{"Id": "net-" + b.Name})
	case strings.HasPrefix(p, "/networks/") && p != "/networks/create" && p != "/networks/prune":
		name, action, _ := strings.Cut(strings.TrimPrefix(p, "/networks/"), "/")
		joins, ok := e.networks[name]
		if !ok {
			jsonOut(w, 404, map[string]string{"message": "network " + name + " not found"})
			return
		}
		if r.Method == "POST" && action == "connect" {
			var b struct {
				Container      string
				EndpointConfig struct{ Aliases []string }
			}
			json.NewDecoder(r.Body).Decode(&b)
			e.networks[name] = append(joins, b.Container+":"+strings.Join(b.EndpointConfig.Aliases, ","))
			w.WriteHeader(200)
			return
		}
		if r.Method == "POST" && action == "disconnect" {
			var b struct{ Container string }
			json.NewDecoder(r.Body).Decode(&b)
			kept := []string{}
			for _, j := range joins {
				if id, _, _ := strings.Cut(j, ":"); id != b.Container {
					kept = append(kept, j)
				}
			}
			e.networks[name] = kept
			w.WriteHeader(200)
			return
		}
		containers := map[string]any{}
		for _, j := range joins {
			id, _, _ := strings.Cut(j, ":")
			containers[id] = map[string]string{}
		}
		jsonOut(w, 200, map[string]any{"Name": name, "Containers": containers})
	case r.Method == "POST" && strings.HasSuffix(p, "/prune"):
		e.prunes = append(e.prunes, strings.Trim(strings.TrimSuffix(p, "/prune"), "/")+" "+q.Get("filters"))
		jsonOut(w, 200, map[string]any{"SpaceReclaimed": 1000})
	default:
		jsonOut(w, 404, map[string]string{"message": "unknown " + r.Method + " " + p})
	}
}

// startEngine serves the fake engine on a short unix socket path (macOS limits sun_path to 104 bytes).
func startEngine(t *testing.T) (*fakeEngine, string) {
	t.Helper()
	dir, err := os.MkdirTemp("/tmp", "kd")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { os.RemoveAll(dir) })
	sock := filepath.Join(dir, "d.sock")
	l, err := net.Listen("unix", sock)
	if err != nil {
		t.Fatal(err)
	}
	e := newEngine()
	srv := &httptest.Server{Listener: l, Config: &http.Server{Handler: e}}
	srv.Start()
	t.Cleanup(srv.Close)
	return e, sock
}

type upstreams struct {
	mu    sync.Mutex
	calls [][]string
	err   error
}

func (u *upstreams) SetUpstreams(ctx context.Context, route string, dials []string) error {
	u.mu.Lock()
	defer u.mu.Unlock()
	if u.err != nil {
		return u.err
	}
	u.calls = append(u.calls, append([]string{route}, dials...))
	return nil
}

func newSvc(t *testing.T) (*Service, *fakeEngine, *runnertest.Fake, *upstreams, string) {
	e, sock := startEngine(t)
	fr := &runnertest.Fake{}
	up := &upstreams{}
	root := t.TempDir()
	return New(Options{Socket: sock, Runner: fr, FS: hostfs.FS{Root: root}, Upstreams: up}), e, fr, up, root
}

func exec1(t *testing.T, s *Service, typ string, payload any) (commands.Event, *commands.Collector) {
	t.Helper()
	reg := commands.NewRegistry()
	s.Register(reg)
	col := &commands.Collector{}
	d := commands.NewDispatcher(context.Background(), reg, col, nil)
	b, _ := json.Marshal(payload)
	d.Submit(commands.Envelope{ID: "01HZZZZZZZZZZZZZZZZZZZZZZ1", Type: typ, TimeoutS: 60, IdempotencyKey: "x", Payload: b})
	d.Wait()
	ev := col.Snapshot()
	return ev[len(ev)-1], col
}

func TestVersionNegotiation(t *testing.T) {
	_, sock := startEngine(t)
	c := NewClient(sock)
	v, err := c.Version(context.Background())
	if err != nil || v.Version != "27.1.1" {
		t.Fatalf("%v %+v", err, v)
	}
	if c.apiVersion(context.Background()) != "1.43" {
		t.Fatal("should cap at 1.43")
	}
	if !versionLess("1.41", "1.43") || versionLess("1.46", "1.43") {
		t.Fatal("versionLess")
	}
	// Docker 29 refuses API versions below its minimum (1.44): raise to it.
	dir, _ := os.MkdirTemp("/tmp", "kv")
	defer os.RemoveAll(dir)
	l, err := net.Listen("unix", filepath.Join(dir, "d.sock"))
	if err != nil {
		t.Fatal(err)
	}
	srv := &httptest.Server{Listener: l, Config: &http.Server{Handler: http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		jsonOut(w, 200, VersionInfo{Version: "29.0.0", APIVersion: "1.52", MinAPI: "1.44"})
	})}}
	srv.Start()
	defer srv.Close()
	if got := NewClient(filepath.Join(dir, "d.sock")).apiVersion(context.Background()); got != "1.44" {
		t.Fatalf("docker 29: api version %s, want 1.44", got)
	}
	if a, b := splitRef("ghcr.io:443/org/app:1.2"); a != "ghcr.io:443/org/app" || b != "1.2" {
		t.Fatal(a, b)
	}
	if a, b := splitRef("localhost:5000/app"); a != "localhost:5000/app" || b != "latest" {
		t.Fatal(a, b)
	}
}

func TestPull(t *testing.T) {
	s, e, _, _, _ := newSvc(t)
	fin, col := exec1(t, s, "docker.pull", PullPayload{Image: "nginx:1.27", Auth: &Auth{Username: "u", Password: "p", Server: "ghcr.io"}})
	if fin.Error != "" || fin.Result.(PullResult).ImageID == "" {
		t.Fatalf("%+v", fin)
	}
	if out := col.Output("stdout"); !strings.Contains(out, "Pulling from library/x") || !strings.Contains(out, "Downloaded newer image") || strings.Contains(out, "[==>") || strings.Contains(out, "abc:") {
		t.Fatalf("output %q", col.Output("stdout"))
	}
	raw, _ := base64.URLEncoding.DecodeString(e.pullAuth[0])
	if !strings.Contains(string(raw), `"serveraddress":"ghcr.io"`) {
		t.Fatalf("auth %s", raw)
	}
	fin, _ = exec1(t, s, "docker.pull", PullPayload{Image: "nope:1"})
	if !strings.Contains(fin.Error, "manifest unknown") {
		t.Fatalf("%+v", fin)
	}
}

func TestRunIdempotent(t *testing.T) {
	s, e, _, _, _ := newSvc(t)
	p := RunPayload{Name: "redis", Image: "redis:7", Env: map[string]string{"B": "2", "A": "1"},
		Ports: []PortSpec{{HostPort: 6379, ContainerPort: 6379}}, Volumes: []VolumeSpec{{Source: "/data/redis", Target: "/data", ReadOnly: true}}, Limits: Limits{CPUs: 0.5}}
	fin, _ := exec1(t, s, "docker.run", p)
	r := fin.Result.(RunResult)
	if fin.Error != "" || !r.Changed {
		t.Fatalf("%+v", fin)
	}
	c := e.containers[r.ContainerID]
	if !c.running || c.body.Env[0] != "A=1" || c.body.HostConfig.PortBindings["6379/tcp"][0].HostIP != "127.0.0.1" ||
		c.body.HostConfig.Binds[0] != "/data/redis:/data:ro" || c.body.HostConfig.NanoCPUs != 5e8 || c.body.HostConfig.RestartPolicy.Name != "unless-stopped" {
		t.Fatalf("create body %+v", c.body)
	}
	if len(e.pulls) != 1 {
		t.Fatal("missing image not pulled")
	}
	// Same spec → no change.
	fin, _ = exec1(t, s, "docker.run", p)
	if r2 := fin.Result.(RunResult); r2.Changed || r2.ContainerID != r.ContainerID {
		t.Fatalf("%+v", r2)
	}
	// Stopped → started, same container.
	e.containers[r.ContainerID].running = false
	fin, _ = exec1(t, s, "docker.run", p)
	if r2 := fin.Result.(RunResult); !r2.Changed || r2.ContainerID != r.ContainerID || !e.containers[r.ContainerID].running {
		t.Fatalf("%+v", r2)
	}
	// Changed spec → recreated.
	p.Env["A"] = "changed"
	fin, _ = exec1(t, s, "docker.run", p)
	if r2 := fin.Result.(RunResult); !r2.Changed || r2.ContainerID == r.ContainerID || len(e.containers) != 1 {
		t.Fatalf("%+v %d", r2, len(e.containers))
	}
	if len(e.pulls) != 1 {
		t.Fatal("present image re-pulled with policy missing")
	}
}

func TestStop(t *testing.T) {
	s, e, _, _, _ := newSvc(t)
	fin, _ := exec1(t, s, "docker.stop", map[string]any{"name": "ghost"})
	if fin.Error != "" || fin.Result.(ChangedResult).Changed {
		t.Fatalf("%+v", fin)
	}
	exec1(t, s, "docker.run", RunPayload{Name: "web", Image: "nginx"})
	fin, _ = exec1(t, s, "docker.stop", map[string]any{"name": "web"})
	if !fin.Result.(ChangedResult).Changed || e.byName("web").running {
		t.Fatalf("%+v", fin)
	}
	fin, _ = exec1(t, s, "docker.stop", map[string]any{"name": "web"})
	if fin.Result.(ChangedResult).Changed {
		t.Fatal("second stop changed")
	}
	fin, _ = exec1(t, s, "docker.stop", map[string]any{"name": "web", "remove": true})
	if !fin.Result.(ChangedResult).Changed || e.byName("web") != nil {
		t.Fatal("not removed")
	}
}

func TestPrune(t *testing.T) {
	s, e, _, _, _ := newSvc(t)
	fin, _ := exec1(t, s, "docker.prune", map[string]any{"images": "all", "volumes": true, "build_cache": true, "until": "24h"})
	if fin.Error != "" {
		t.Fatal(fin.Error)
	}
	if got := fin.Result.(PruneResult).SpaceReclaimedBytes; got != 100+1000*4 {
		t.Fatalf("reclaimed %d", got)
	}
	// The control plane's own stack (compose project falak) is never pruned; the build cache has no label filter.
	want := []string{
		`containers {"label!":["com.docker.compose.project=falak"],"until":["24h"]}`,
		`images {"dangling":["false"],"label!":["com.docker.compose.project=falak"],"until":["24h"]}`,
		`volumes {"label!":["com.docker.compose.project=falak"]}`,
		`networks {"label!":["com.docker.compose.project=falak"],"until":["24h"]}`,
		`build {"until":["24h"]}`,
	}
	if strings.Join(e.prunes, "|") != strings.Join(want, "|") {
		t.Fatalf("prunes %q", e.prunes)
	}
	t.Setenv("FALAK_PROJECT_NAME", "falakcp")
	e.prunes = nil
	exec1(t, s, "docker.prune", map[string]any{"images": "none", "networks": false})
	if strings.Join(e.prunes, "|") != `containers {"label!":["com.docker.compose.project=falakcp"]}` {
		t.Fatalf("prunes %q", e.prunes)
	}
}

func TestCompose(t *testing.T) {
	s, _, fr, _, root := newSvc(t)
	fr.On("docker compose -p broken", runner.Result{ExitCode: 1, Stderr: []byte("no such service")})
	fin, _ := exec1(t, s, "docker.compose.up", ComposeUpPayload{Project: "stack", Directory: "/srv/falak/compose/stack",
		Files: []ComposeFile{{Name: "compose.yaml", Content: "services: {}\n"}, {Name: "compose.prod.yaml", Content: "x: 1\n"}},
		Env:   map[string]string{"TAG": "v2"}, Pull: "always"})
	if fin.Error != "" || fin.Result.(ComposeUpResult).ExitCode != 0 {
		t.Fatalf("%+v", fin)
	}
	b, err := os.ReadFile(filepath.Join(root, "srv/falak/compose/stack/compose.yaml"))
	if err != nil || string(b) != "services: {}\n" {
		t.Fatalf("file %q %v", b, err)
	}
	c := fr.Calls()[0]
	if c.Line != "docker compose -p stack -f compose.yaml -f compose.prod.yaml up -d --pull always --remove-orphans" ||
		c.Dir != filepath.Join(root, "srv/falak/compose/stack") || c.Env[0] != "TAG=v2" {
		t.Fatalf("%+v", c)
	}
	fin, _ = exec1(t, s, "docker.compose.down", ComposeDownPayload{Project: "stack", Directory: "/srv/falak/compose/stack", Volumes: true})
	if fin.Error != "" || fr.Calls()[1].Line != "docker compose -p stack down --volumes" {
		t.Fatalf("%+v %v", fin, fr.Lines())
	}
	fin, _ = exec1(t, s, "docker.compose.up", ComposeUpPayload{Project: "broken", Directory: "/srv/x"})
	if fin.ExitCode == nil || *fin.ExitCode != 1 || fin.Result.(ComposeUpResult).ExitCode != 1 {
		t.Fatalf("%+v", fin)
	}
	fin, _ = exec1(t, s, "docker.compose.up", ComposeUpPayload{Project: "x", Directory: "/srv/x", Files: []ComposeFile{{Name: "../evil", Content: ""}}})
	if fin.ExitCode == nil || *fin.ExitCode != 2 {
		t.Fatalf("path traversal accepted: %+v", fin)
	}
}

// healthServer returns an HTTP server on 127.0.0.1 whose port stands in for a container's host port.
func healthServer(t *testing.T, code *int) int {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/up" {
			w.WriteHeader(404)
			return
		}
		w.WriteHeader(*code)
	}))
	t.Cleanup(srv.Close)
	_, port, _ := net.SplitHostPort(strings.TrimPrefix(srv.URL, "http://"))
	n, _ := strconv.Atoi(port)
	return n
}

func swapPayload(blue, green int) SwapPayload {
	p := SwapPayload{Site: "shop", Image: "registry.local/shop:abc", ContainerPort: 8080, EdgeRouteID: "shop",
		Health: &HealthSpec{Path: "/up", TimeoutS: 1, IntervalMS: 50}, Env: map[string]string{"APP_ENV": "production"}}
	zero := 0
	p.DrainS = &zero
	p.Ports.Blue, p.Ports.Green = blue, green
	return p
}

func TestContainerSwap(t *testing.T) {
	s, e, _, up, _ := newSvc(t)
	ok := 200
	blue, green := healthServer(t, &ok), healthServer(t, &ok)
	p := swapPayload(blue, green)

	fin, col := exec1(t, s, "deploy.container.swap", p)
	if fin.Error != "" {
		t.Fatal(fin.Error, col.Output(""))
	}
	r := fin.Result.(SwapResult)
	if !r.Changed || r.ActiveColor != "blue" || r.Upstream != "127.0.0.1:"+strconv.Itoa(blue) || r.PreviousColor != "" {
		t.Fatalf("%+v", r)
	}
	c := e.byName("falak-shop-blue")
	if c == nil || !c.running || c.body.HostConfig.PortBindings["8080/tcp"][0].HostPort != strconv.Itoa(blue) || c.body.Labels[LabelSite] != "shop" {
		t.Fatalf("blue container %+v", c)
	}

	// Same spec again → no change, edge re-asserted.
	fin, _ = exec1(t, s, "deploy.container.swap", p)
	if r := fin.Result.(SwapResult); r.Changed || r.ActiveColor != "blue" {
		t.Fatalf("%+v", r)
	}

	// New image → green takes over, blue retired.
	p.Image = "registry.local/shop:def"
	fin, _ = exec1(t, s, "deploy.container.swap", p)
	r = fin.Result.(SwapResult)
	if fin.Error != "" || !r.Changed || r.ActiveColor != "green" || r.PreviousColor != "blue" {
		t.Fatalf("%+v %s", r, fin.Error)
	}
	if e.byName("falak-shop-blue") != nil || !e.byName("falak-shop-green").running {
		t.Fatal("blue not retired")
	}
	if last := up.calls[len(up.calls)-1]; last[0] != "shop" || last[1] != "127.0.0.1:"+strconv.Itoa(green) {
		t.Fatalf("upstream %v", last)
	}
}

func TestContainerSwapHealthFailureKeepsOld(t *testing.T) {
	s, e, _, up, _ := newSvc(t)
	ok, bad := 200, 503
	blue, green := healthServer(t, &ok), healthServer(t, &bad)
	p := swapPayload(blue, green)
	if fin, _ := exec1(t, s, "deploy.container.swap", p); fin.Error != "" {
		t.Fatal(fin.Error)
	}
	nUp := len(up.calls)
	p.Image = "registry.local/shop:broken"
	fin, _ := exec1(t, s, "deploy.container.swap", p)
	if !strings.Contains(fin.Error, "health check") {
		t.Fatalf("%+v", fin)
	}
	if e.byName("falak-shop-green") != nil {
		t.Fatal("unhealthy green container left behind")
	}
	if b := e.byName("falak-shop-blue"); b == nil || !b.running {
		t.Fatal("old container disturbed")
	}
	if len(up.calls) != nUp {
		t.Fatal("edge switched to unhealthy container")
	}

	// Edge failure also rolls back.
	bad = 200
	up.err = errors.New("caddy down")
	fin, _ = exec1(t, s, "deploy.container.swap", p)
	if !strings.Contains(fin.Error, "caddy down") || e.byName("falak-shop-green") != nil || !e.byName("falak-shop-blue").running {
		t.Fatalf("%+v", fin)
	}
}

// A compose service run as its own site joins its stack's network under the service's name, before it starts; a
// network that never appears fails the run instead of starting a container that can't reach the stack.
func TestRunJoinsExistingNetworksWithAliases(t *testing.T) {
	s, e, _, _, _ := newSvc(t)
	e.networks["shop_default"] = nil
	p := RunPayload{Name: "falak-shop-api-blue", Image: "api:1", Networks: []NetworkJoin{{Name: "shop_default", Aliases: []string{"api"}}}}
	fin, _ := exec1(t, s, "docker.run", p)
	if fin.Error != "" {
		t.Fatalf("%+v", fin)
	}
	id := fin.Result.(RunResult).ContainerID
	if got := e.networks["shop_default"]; len(got) != 1 || got[0] != id+":api" || !e.containers[id].running {
		t.Fatalf("joins %v", got)
	}
	// Same spec: nothing recreated or re-joined.
	exec1(t, s, "docker.run", p)
	if len(e.networks["shop_default"]) != 1 {
		t.Fatalf("re-joined: %v", e.networks["shop_default"])
	}

	old := networkWait
	networkWait = 0
	t.Cleanup(func() { networkWait = old })
	fin, _ = exec1(t, s, "docker.run", RunPayload{Name: "falak-other-api-blue", Image: "api:1", Networks: []NetworkJoin{{Name: "missing_default"}}})
	if !strings.Contains(fin.Error, "network missing_default does not exist") || e.byName("falak-other-api-blue") != nil {
		t.Fatalf("%+v", fin)
	}
}

// A changed spec whose network is missing fails before the current container is touched: the service keeps running.
func TestRunKeepsCurrentContainerWhenNetworkIsMissing(t *testing.T) {
	s, e, _, _, _ := newSvc(t)
	e.networks["shop_default"] = nil
	p := RunPayload{Name: "falak-shop-api-blue", Image: "api:1", Networks: []NetworkJoin{{Name: "shop_default", Aliases: []string{"api"}}}}
	fin, _ := exec1(t, s, "docker.run", p)
	if fin.Error != "" {
		t.Fatalf("%+v", fin)
	}
	id := fin.Result.(RunResult).ContainerID

	old := networkWait
	networkWait = 0
	t.Cleanup(func() { networkWait = old })
	delete(e.networks, "shop_default") // the stack is being redeployed or was deleted
	p.Env = map[string]string{"A": "b"}
	fin, _ = exec1(t, s, "docker.run", p)
	if !strings.Contains(fin.Error, "network shop_default does not exist") {
		t.Fatalf("%+v", fin)
	}
	if c := e.byName("falak-shop-api-blue"); c == nil || c.id != id || !c.running {
		t.Fatalf("current container stopped or replaced: %+v", c)
	}
}

// compose down first detaches Falak's containers (split-out services) from the project's networks, which Docker
// refuses to remove while they have endpoints; the project's own containers and other networks are left alone.
func TestComposeDownDetachesFalakContainersFromStackNetworks(t *testing.T) {
	s, e, fr, _, _ := newSvc(t)
	e.networks["shop_default"] = nil
	e.networks["other_default"] = nil
	e.networkLabels = map[string]map[string]string{
		"shop_default":  {LabelComposeProject: "shop"},
		"other_default": {LabelComposeProject: "other"},
	}
	fin, _ := exec1(t, s, "docker.run", RunPayload{Name: "falak-shop-api-blue", Image: "api:1", Networks: []NetworkJoin{{Name: "shop_default", Aliases: []string{"api"}}, {Name: "other_default"}}})
	if fin.Error != "" {
		t.Fatalf("%+v", fin)
	}
	falak := fin.Result.(RunResult).ContainerID
	e.mu.Lock()
	e.containers["redis1"] = &fcont{id: "redis1", name: "shop-redis-1", running: true, body: CreateBody{Labels: map[string]string{LabelComposeProject: "shop"}}}
	e.networks["shop_default"] = append(e.networks["shop_default"], "redis1:redis")
	e.mu.Unlock()

	fin, col := exec1(t, s, "docker.compose.down", ComposeDownPayload{Project: "shop", Directory: "/srv/falak/compose/shop"})
	if fin.Error != "" || fr.Calls()[0].Line != "docker compose -p shop down" {
		t.Fatalf("%+v %v", fin, fr.Lines())
	}
	if got := e.networks["shop_default"]; len(got) != 1 || got[0] != "redis1:redis" {
		t.Fatalf("shop_default joins %v", got)
	}
	if got := e.networks["other_default"]; len(got) != 1 || !strings.HasPrefix(got[0], falak+":") {
		t.Fatalf("other_default joins %v", got)
	}
	if !strings.Contains(col.Output(""), "detached falak-shop-api-blue from network shop_default") {
		t.Fatalf("output %q", col.Output(""))
	}
}

// A split-out compose service deployed with a container swap joins its stack's network before it starts (seen on
// AWS: the swap path created the container on the default bridge only, so it couldn't resolve redis).
func TestContainerSwapJoinsStackNetworks(t *testing.T) {
	s, e, _, _, _ := newSvc(t)
	ok := 200
	p := swapPayload(healthServer(t, &ok), healthServer(t, &ok))
	p.Networks = []NetworkJoin{{Name: "shop_default", Aliases: []string{"api"}}}
	e.networks["shop_default"] = nil

	fin, col := exec1(t, s, "deploy.container.swap", p)
	if fin.Error != "" {
		t.Fatal(fin.Error, col.Output(""))
	}
	c := e.byName("falak-shop-blue")
	if c == nil || !c.running {
		t.Fatalf("blue container missing or stopped")
	}
	if got := e.networks["shop_default"]; len(got) != 1 || !strings.HasSuffix(got[0], ":api") {
		t.Fatalf("joins %v", got)
	}

	old := networkWait
	networkWait = 0
	t.Cleanup(func() { networkWait = old })
	p.Networks = []NetworkJoin{{Name: "missing_default"}}
	p.Image = "registry.local/shop:def"
	fin, _ = exec1(t, s, "deploy.container.swap", p)
	if !strings.Contains(fin.Error, "network missing_default does not exist") {
		t.Fatalf("%+v", fin)
	}
}

// A site without a domain (a split-out compose service only its stack reaches) has no edge route: the swap runs the
// new container and switches nothing.
func TestContainerSwapWithoutEdgeRoute(t *testing.T) {
	s, _, _, up, _ := newSvc(t)
	ok := 200
	p := swapPayload(healthServer(t, &ok), healthServer(t, &ok))
	p.EdgeRouteID = ""

	fin, col := exec1(t, s, "deploy.container.swap", p)
	if fin.Error != "" {
		t.Fatal(fin.Error, col.Output(""))
	}
	if len(up.calls) != 0 {
		t.Fatalf("edge switched: %v", up.calls)
	}
}

func TestComposeDownForgetsTheProjectsEnvFiles(t *testing.T) {
	s, _, _, _, root := newSvc(t)
	dir := filepath.Join(root, envlinks.DefaultEnvDir)
	if err := os.MkdirAll(dir, 0o711); err != nil {
		t.Fatal(err)
	}
	for _, n := range []string{"compose-shop.env", "compose-shop.prod.env", "compose-shop2.env", "compose-shop-api.env", "shop.env"} {
		if err := os.WriteFile(filepath.Join(dir, n), []byte("SECRET=x\n"), 0o400); err != nil {
			t.Fatal(err)
		}
	}
	fin, col := exec1(t, s, "docker.compose.down", ComposeDownPayload{Project: "shop", Directory: "/srv/falak/compose/shop"})
	if fin.Error != "" {
		t.Fatalf("%+v", fin)
	}
	var left []string
	entries, _ := os.ReadDir(dir)
	for _, e := range entries {
		left = append(left, e.Name())
	}
	// Only shop's own files go: other projects' (shop2, shop-api) and site env files stay.
	if strings.Join(left, ",") != "compose-shop-api.env,compose-shop2.env,shop.env" {
		t.Fatalf("left %v", left)
	}
	if !strings.Contains(col.Output(""), "removed compose-shop.env") {
		t.Fatalf("output %q", col.Output(""))
	}
}

// A swap whose new container never gets healthy streams the container's state and last lines (secrets masked) before
// removing it; one that stops while waiting fails at once, with its exit code.
func TestContainerSwapReportsTheFailedContainer(t *testing.T) {
	s, e, _, _, _ := newSvc(t)
	bad := 503
	p := swapPayload(healthServer(t, &bad), healthServer(t, &bad))
	p.Env["DB_PASSWORD"] = "hunter2-secret"
	p.Mask = []string{"DB_PASSWORD"}
	var many strings.Builder
	for i := 1; i <= 60; i++ {
		fmt.Fprintf(&many, "2026-10-12T10:00:%02dZ line %d\n", i%60, i)
	}
	e.logs = map[string][2]string{"falak-shop-blue": {many.String() + "2026-10-12T10:01:00Z " + strings.Repeat("x", 900) + "\n",
		"2026-10-12T10:01:01Z SQLSTATE[08006] connecting with password hunter2-secret\n"}}

	fin, col := exec1(t, s, "deploy.container.swap", p)
	out := col.Output("stdout")
	if !strings.Contains(fin.Error, "health check /up") || !strings.Contains(out, "container state: running") ||
		!strings.Contains(out, "container output (last 50 lines):") || !strings.Contains(out, "  | 2026-10-12T10:01:01Z SQLSTATE[08006]") {
		t.Fatalf("error %q output %q", fin.Error, out)
	}
	if strings.Contains(out, "hunter2-secret") || strings.Contains(out, "line 12\n") || !strings.Contains(out, "line 13\n") || !strings.Contains(out, strings.Repeat("x", 400)) || strings.Contains(out, strings.Repeat("x", 600)) {
		t.Fatalf("output not bounded or masked: %q", out)
	}
	if q := e.logQuery[len(e.logQuery)-1]; !strings.Contains(q, "timestamps=1") || !strings.Contains(q, "tail=50") {
		t.Fatalf("logs query %q", q)
	}
	if e.byName("falak-shop-blue") != nil {
		t.Fatal("failed container left behind")
	}

	// Stopped during the wait (out of memory): reported right away, not at the deadline.
	e.exitOnStart = map[string]int{"falak-shop-blue": 137}
	e.logs = nil
	p.Health.TimeoutS = 30
	start := time.Now()
	fin, col = exec1(t, s, "deploy.container.swap", p)
	if time.Since(start) > 10*time.Second || !strings.Contains(fin.Error, "the container stopped before the health check /up passed (exited with code 137, killed: out of memory)") ||
		!strings.Contains(col.Output("stdout"), "container output: (none)") {
		t.Fatalf("error %q output %q", fin.Error, col.Output("stdout"))
	}
}

// A secret across the line-length cut, or across the front of the bounded buffer, never shows in part.
func TestContainerSwapReportMasksBeforeCutting(t *testing.T) {
	s, e, _, _, _ := newSvc(t)
	bad := 503
	p := swapPayload(healthServer(t, &bad), healthServer(t, &bad))
	p.Env["DB_PASSWORD"] = "hunter2-secret"
	p.Mask = []string{"DB_PASSWORD"}
	straddle := strings.Repeat("a", reportLineMax-6) + "hunter2-secret tail\n"
	front := strings.Repeat("b", reportLines*(reportLineMax+64)) + "hunter2-secret\n"
	e.logs = map[string][2]string{"falak-shop-blue": {front + straddle + "last line\n", ""}}

	_, col := exec1(t, s, "deploy.container.swap", p)
	out := col.Output("stdout")
	for _, part := range []string{"hunter2", "-secret", "secret"} {
		if strings.Contains(out, part) {
			t.Fatalf("%q leaked: %q", part, out)
		}
	}
	if !strings.Contains(out, "  | last line") || !strings.Contains(out, "  | "+strings.Repeat("a", 100)) || strings.Contains(out, "bbbb") {
		t.Fatalf("output %q", out)
	}
}
