package db

import (
	"context"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/docker"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

// fakeDocker is an in-memory Engine: containers by name, images by reference.
type fakeDocker struct {
	mu         sync.Mutex
	containers map[string]*docker.Container
	bodies     map[string]docker.CreateBody
	images     map[string][]string // ref → RepoDigests
	imageIDs   map[string]string   // ref → image id
	networks   map[string]map[string][]string
	netLabels  map[string]map[string]string
	pulls      []string
	calls      []string
	seq        int
	health     string // health of started containers (default healthy)
	onStart    func(*docker.Container)
	logs       string
}

func newFakeDocker() *fakeDocker {
	return &fakeDocker{containers: map[string]*docker.Container{}, bodies: map[string]docker.CreateBody{}, images: map[string][]string{},
		imageIDs: map[string]string{}, networks: map[string]map[string][]string{}, netLabels: map[string]map[string]string{}, health: "healthy"}
}

func (f *fakeDocker) log(format string, a ...any) {
	f.calls = append(f.calls, fmt.Sprintf(format, a...))
}

func (f *fakeDocker) called(prefix string) bool {
	f.mu.Lock()
	defer f.mu.Unlock()
	for _, c := range f.calls {
		if strings.HasPrefix(c, prefix) {
			return true
		}
	}
	return false
}

func (f *fakeDocker) byName(n string) *docker.Container {
	if c, ok := f.containers[n]; ok {
		return c
	}
	for _, c := range f.containers {
		if c.ID == n {
			return c
		}
	}
	return nil
}

func (f *fakeDocker) ImagePull(_ context.Context, ref string, _ *docker.Auth, _ io.Writer) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.pulls = append(f.pulls, ref)
	repo := repository(ref)
	digest := "sha256:" + strings.Repeat("d", 64)
	if i := strings.Index(ref, "@"); i >= 0 {
		digest = ref[i+1:]
	}
	f.images[ref] = []string{repo + "@" + digest}
	if f.imageIDs[ref] == "" {
		f.imageIDs[ref] = "sha256:img1"
	}
	return nil
}

func (f *fakeDocker) ImageRepoDigests(_ context.Context, ref string) ([]string, bool, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	d, ok := f.images[ref]
	return d, ok, nil
}

func (f *fakeDocker) ImageInspect(_ context.Context, ref string) (string, bool, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	id, ok := f.imageIDs[ref]
	return id, ok, nil
}

func (f *fakeDocker) ContainerInspect(_ context.Context, n string) (*docker.Container, bool, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	c := f.byName(n)
	if c == nil {
		return nil, false, nil
	}
	cp := *c
	return &cp, true, nil
}

func (f *fakeDocker) ContainerList(_ context.Context, _ bool, labels []string) ([]docker.ContainerSummary, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	var out []docker.ContainerSummary
	for name, c := range f.containers {
		ok := true
		for _, l := range labels {
			k, v, hasV := strings.Cut(l, "=")
			if got, present := c.Config.Labels[k]; !present || hasV && got != v {
				ok = false
			}
		}
		if ok {
			status := "Exited (0)"
			if c.State.Running {
				status = "Up 1 minute (" + healthOf(c) + ")"
			}
			out = append(out, docker.ContainerSummary{ID: c.ID, Names: []string{"/" + name}, State: c.State.Status, Status: status, Labels: c.Config.Labels})
		}
	}
	return out, nil
}

func (f *fakeDocker) ContainerCreate(_ context.Context, name string, b docker.CreateBody) (string, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.containers[name] != nil {
		return "", fmt.Errorf("conflict: %s exists", name)
	}
	f.seq++
	c := &docker.Container{ID: fmt.Sprintf("c%d", f.seq), Name: "/" + name, Image: f.imageIDs[b.Image]}
	c.Config.Image = b.Image
	c.Config.Labels = b.Labels
	c.HostConfig.Memory, c.HostConfig.NanoCpus = b.HostConfig.Memory, b.HostConfig.NanoCPUs
	c.State.Status = "created"
	for _, m := range b.HostConfig.Mounts {
		c.Mounts = append(c.Mounts, struct {
			Source      string `json:"Source"`
			Destination string `json:"Destination"`
		}{m.Source, m.Target})
	}
	f.containers[name] = c
	f.bodies[name] = b
	f.log("create %s", name)
	return c.ID, nil
}

func (f *fakeDocker) ContainerStart(_ context.Context, id string) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	c := f.byName(id)
	if c == nil {
		return &docker.APIError{Status: 404, Message: "no such container"}
	}
	c.State.Running, c.State.Status = true, "running"
	c.State.StartedAt = time.Now().UTC().Format(time.RFC3339Nano)
	c.State.Health = &struct {
		Status string `json:"Status"`
	}{f.health}
	f.log("start %s", strings.TrimPrefix(c.Name, "/"))
	if f.onStart != nil {
		f.onStart(c)
	}
	return nil
}

func (f *fakeDocker) ContainerStop(_ context.Context, id string, timeout time.Duration) (bool, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	c := f.byName(id)
	if c == nil {
		return false, &docker.APIError{Status: 404, Message: "no such container"}
	}
	was := c.State.Running
	c.State.Running, c.State.Status = false, "exited"
	f.log("stop %s %s", strings.TrimPrefix(c.Name, "/"), timeout)
	return was, nil
}

func (f *fakeDocker) ContainerRestart(_ context.Context, id string, timeout time.Duration) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	c := f.byName(id)
	if c == nil {
		return &docker.APIError{Status: 404, Message: "no such container"}
	}
	c.State.Running, c.State.Status = true, "running"
	c.State.StartedAt = time.Now().UTC().Format(time.RFC3339Nano)
	f.log("restart %s %s", strings.TrimPrefix(c.Name, "/"), timeout)
	return nil
}

func (f *fakeDocker) ContainerUpdate(_ context.Context, id string, memory, cpus int64) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	c := f.byName(id)
	if c == nil {
		return &docker.APIError{Status: 404, Message: "no such container"}
	}
	c.HostConfig.Memory, c.HostConfig.NanoCpus = memory, cpus
	f.log("update %s %d %d", strings.TrimPrefix(c.Name, "/"), memory, cpus)
	return nil
}

func (f *fakeDocker) ContainerRemove(_ context.Context, id string) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	for n, c := range f.containers {
		if c.ID == id || n == id {
			delete(f.containers, n)
			f.log("remove %s", n)
		}
	}
	return nil
}

func (f *fakeDocker) ContainerLogs(_ context.Context, _ string, _ bool, _ int, w io.Writer) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	_, err := io.WriteString(w, f.logs)
	return err
}

func (f *fakeDocker) NetworkExists(_ context.Context, name string) (bool, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	_, ok := f.networks[name]
	return ok, nil
}

func (f *fakeDocker) NetworkCreate(_ context.Context, name string, labels map[string]string) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.networks[name] = map[string][]string{}
	f.netLabels[name] = labels
	f.log("network create %s", name)
	return nil
}

func (f *fakeDocker) NetworkConnect(_ context.Context, network, container string, aliases []string) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.networks[network] == nil {
		f.networks[network] = map[string][]string{}
	}
	f.networks[network][container] = aliases
	f.log("connect %s %s %s", network, container, strings.Join(aliases, ","))
	return nil
}

func (f *fakeDocker) NetworkDisconnect(_ context.Context, network, container string) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	delete(f.networks[network], container)
	f.log("disconnect %s %s", network, container)
	return nil
}

// harness wires a DB to the fakes, with the host under a temporary root and every volume mounted.
type harness struct {
	db   *DB
	dock *fakeDocker
	run  *runnertest.Fake
	root string
}

func newHarness(t *testing.T) *harness {
	t.Helper()
	root := t.TempDir()
	t.Cleanup(func() {
		// Secret directories are read-only: open them so the temporary root can be removed.
		filepath.Walk(root, func(p string, info os.FileInfo, err error) error {
			if err == nil && info.IsDir() {
				os.Chmod(p, 0o755)
			}
			return nil
		})
	})
	h := &harness{dock: newFakeDocker(), run: &runnertest.Fake{}, root: root}
	h.db = New(Deps{Runner: h.run, Docker: h.dock, FS: hostfs.FS{Root: root}, TempDir: t.TempDir(),
		Mounted: func(string) bool { return true }, Poll: time.Millisecond, HealthWait: time.Second, VolumeWait: 50 * time.Millisecond})
	return h
}

func (h *harness) path(p string) string { return filepath.Join(h.root, p) }

func stream() commands.Stream { return commands.NewTestStream("c", &commands.Collector{}) }

const (
	instID  = "01hzyinst00000000000000001"
	instID2 = "01hzyinst00000000000000002"
	volID   = "01hzyvol000000000000000001"
	secret  = "s3cret-Pa55"
)

func spec() InstanceSpec {
	return InstanceSpec{ID: instID, Engine: "postgres", Version: "17", Image: "ghcr.io/falak-sh/falak-postgres:17", VolumeID: volID,
		Digest: "sha256:" + strings.Repeat("a", 64), HostPort: 20001, MemoryBytes: 512 << 20, Settings: []byte(`{"max_connections": 200}`), Network: "falak-env-01hzyenv000000000000000001"}
}
