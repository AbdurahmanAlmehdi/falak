package pty

import (
	"context"
	"encoding/base64"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/docker"
)

// fakeDocker answers ContainerList/ContainerInspect from fixed data and records the label filters.
type fakeDocker struct {
	list    []docker.ContainerSummary
	byName  map[string]bool // name → running
	filters []string
}

func (f *fakeDocker) ContainerList(_ context.Context, _ bool, labels []string) ([]docker.ContainerSummary, error) {
	f.filters = labels
	return f.list, nil
}

func (f *fakeDocker) ContainerInspect(_ context.Context, name string) (*docker.Container, bool, error) {
	running, ok := f.byName[name]
	if !ok {
		return nil, false, nil
	}
	c := &docker.Container{Name: "/" + name}
	c.State.Running = running
	return c, true, nil
}

func TestContainerValidation(t *testing.T) {
	ok := []ContainerTarget{
		{Name: "falak-db-01jdb000000000000000000000"},
		{Labels: map[string]string{"com.docker.compose.project": "shop", "com.docker.compose.service": "web"}, User: "www-data:www-data", Workdir: "/app"},
		{Labels: map[string]string{"falak.site": "shop"}, User: "1000"},
	}
	for _, c := range ok {
		if err := (OpenPayload{Container: &c}).validate(); err != nil {
			t.Errorf("%+v: %v", c, err)
		}
	}
	bad := []ContainerTarget{
		{},
		{Name: "a", Labels: map[string]string{"falak.site": "shop"}},
		{Name: "-rm"},
		{Name: "x;y"},
		{Labels: map[string]string{"falak.site": "a b"}},
		{Labels: map[string]string{"-k": "v"}},
		{Labels: map[string]string{"k": ""}},
		{Name: "web", User: "root -v"},
		{Name: "web", Workdir: "app"},
		{Name: "web", Workdir: "/app\n"},
	}
	for _, c := range bad {
		if err := (OpenPayload{Container: &c}).validate(); err == nil {
			t.Errorf("%+v accepted", c)
		}
	}
}

func TestDockerExecArgs(t *testing.T) {
	got := dockerExecArgs("sess-1", "abc123", &ContainerTarget{User: "www-data", Workdir: "/app"}, map[string]string{"B": "2", "A": "1 x", "FALAK_CONSOLE_SESSION": "spoof"})
	want := []string{"exec", "-it", "-e", "TERM=xterm-256color", "-e", "FALAK_CONSOLE_SESSION=sess-1", "-e", "A=1 x", "-e", "B=2", "-u", "www-data", "-w", "/app", "abc123",
		"sh", "-c", "if command -v bash >/dev/null 2>&1; then exec bash; else exec sh; fi"}
	if strings.Join(got, "|") != strings.Join(want, "|") {
		t.Fatalf("got  %q\nwant %q", got, want)
	}
	if got := dockerExecArgs("sess-2", "db", &ContainerTarget{}, nil); strings.Join(got[:7], " ") != "exec -it -e TERM=xterm-256color -e FALAK_CONSOLE_SESSION=sess-2 db" {
		t.Fatalf("%q", got)
	}
}

func TestResolveContainer(t *testing.T) {
	ctx := context.Background()
	shop := map[string]string{"com.docker.compose.project": "shop", "com.docker.compose.service": "web"}
	d := &fakeDocker{list: []docker.ContainerSummary{
		{ID: "old", State: "running", Created: 100, Labels: shop},
		{ID: "new", State: "running", Created: 300, Labels: shop},
		{ID: "exited", State: "exited", Created: 400, Labels: shop},
		{ID: "other", State: "running", Created: 500, Labels: map[string]string{"com.docker.compose.project": "shop", "com.docker.compose.service": "worker"}},
	}, byName: map[string]bool{"falak-db-1": true, "falak-db-2": false}}
	if id, err := resolveContainer(ctx, d, &ContainerTarget{Labels: shop}); err != nil || id != "new" {
		t.Fatalf("labels: %q %v", id, err)
	}
	if strings.Join(d.filters, ",") != "com.docker.compose.project=shop,com.docker.compose.service=web" {
		t.Fatalf("filters %q", d.filters)
	}
	if _, err := resolveContainer(ctx, d, &ContainerTarget{Labels: map[string]string{"falak.site": "gone"}}); !errors.Is(err, ErrNoContainer) {
		t.Fatalf("no match: %v", err)
	}
	if id, err := resolveContainer(ctx, d, &ContainerTarget{Name: "falak-db-1"}); err != nil || id != "falak-db-1" {
		t.Fatalf("name: %q %v", id, err)
	}
	for _, n := range []string{"falak-db-2", "missing"} {
		if _, err := resolveContainer(ctx, d, &ContainerTarget{Name: n}); !errors.Is(err, ErrNoContainer) {
			t.Fatalf("%s: %v", n, err)
		}
	}
	if ErrNoContainer.Error() != "No running container for this console (is the site deployed?)" {
		t.Fatal(ErrNoContainer)
	}
}

// A container session runs the docker CLI in the PTY (a stand-in script here) and ends with its exit code.
func TestContainerSession(t *testing.T) {
	dir := t.TempDir()
	cli := filepath.Join(dir, "docker")
	if err := os.WriteFile(cli, []byte("#!/bin/sh\necho \"ARGS: $*\"\nexit 5\n"), 0o755); err != nil {
		t.Fatal(err)
	}
	m := New(Options{DockerCLI: cli, Docker: &fakeDocker{byName: map[string]bool{"falak-db-1": true}}})
	col := &commands.Collector{}
	st := commands.NewTestStream("c1", col)
	res, err := m.Open(context.Background(), OpenPayload{SessionID: "sess-ctr1", User: "ignored", Cwd: "/nonexistent",
		Env: map[string]string{"APP_ENV": "production"}, Container: &ContainerTarget{Name: "falak-db-1", User: "postgres"}}, st)
	if err != nil {
		t.Fatal(err)
	}
	st.Flush()
	if r := res.(OpenResult); r.Reason != ReasonExited || r.ExitCode == nil || *r.ExitCode != 5 {
		t.Fatalf("%+v", r)
	}
	out := decoded(col, "c1")
	if !strings.Contains(out, "ARGS: exec -it -e TERM=xterm-256color -e FALAK_CONSOLE_SESSION=sess-ctr1 -e APP_ENV=production -u postgres falak-db-1 sh -c if command -v bash") {
		t.Fatalf("output %q", out)
	}
	if strings.Contains(out, "does not exist") {
		t.Fatalf("cwd note in a container session: %q", out)
	}

	// Nothing running: the command fails with the console's message.
	_, err = m.Open(context.Background(), OpenPayload{SessionID: "sess-ctr2", Container: &ContainerTarget{Name: "falak-db-9"}}, st)
	if !errors.Is(err, ErrNoContainer) {
		t.Fatalf("err = %v", err)
	}
	// Invalid targets are payload errors.
	_, err = m.Open(context.Background(), OpenPayload{SessionID: "sess-ctr3", Container: &ContainerTarget{}}, st)
	if !commands.IsPayloadError(err) {
		t.Fatalf("err = %v", err)
	}
}

func TestMissingCwdStartsInHome(t *testing.T) {
	home := t.TempDir()
	t.Setenv("HOME", home)
	m := New(Options{})
	col := &commands.Collector{}
	st := commands.NewTestStream("h1", col)
	go func() {
		for i := 0; i < 200 && m.count() == 0; i++ {
			time.Sleep(5 * time.Millisecond)
		}
		_, _ = m.Input(InputPayload{SessionID: "sess-cwd1", Data: base64.StdEncoding.EncodeToString([]byte("echo \"in:$(pwd -P)\"; exit\n"))})
	}()
	if _, err := m.Open(context.Background(), OpenPayload{SessionID: "sess-cwd1", Shell: "/bin/sh", Cwd: "/srv/falak/sites/nope/current"}, st); err != nil {
		t.Fatal(err)
	}
	st.Flush()
	out := decoded(col, "h1")
	note := "falak: /srv/falak/sites/nope/current does not exist; starting in " + home + "\r\n"
	if !strings.HasPrefix(out, note) {
		t.Fatalf("output %q", out)
	}
	real, _ := filepath.EvalSymlinks(home)
	if !strings.Contains(out, "in:"+real) {
		t.Fatalf("not in home: %q", out)
	}
}

// fakeProc is a /proc tree: pid → environ; a SIGHUP ends the pids in dies.
type fakeProc struct {
	t    *testing.T
	root string
	mu   sync.Mutex
	dies map[int]bool
	sent []string
}

func newFakeProc(t *testing.T, procs map[int]string, dies ...int) *fakeProc {
	f := &fakeProc{t: t, root: t.TempDir(), dies: map[int]bool{}}
	for pid, env := range procs {
		d := filepath.Join(f.root, strconv.Itoa(pid))
		if err := os.MkdirAll(d, 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(filepath.Join(d, "environ"), []byte(env), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	_ = os.MkdirAll(filepath.Join(f.root, "self"), 0o755)
	_ = os.MkdirAll(filepath.Join(f.root, "77"), 0o755) // a kernel thread: no environ
	for _, p := range dies {
		f.dies[p] = true
	}
	return f
}

func (f *fakeProc) signal(pid int, sig syscall.Signal) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.sent = append(f.sent, fmt.Sprintf("%d:%s", pid, sig))
	if sig == syscall.SIGKILL || f.dies[pid] {
		_ = os.RemoveAll(filepath.Join(f.root, strconv.Itoa(pid)))
	}
	return nil
}

func (f *fakeProc) signals() []string {
	f.mu.Lock()
	defer f.mu.Unlock()
	out := append([]string(nil), f.sent...)
	sort.Strings(out)
	return out
}

func TestHangUpContainerSession(t *testing.T) {
	defer func(d time.Duration) { containerKillGrace = d }(containerKillGrace)
	containerKillGrace = 200 * time.Millisecond
	fp := newFakeProc(t, map[int]string{
		101: "PATH=/bin\x00FALAK_CONSOLE_SESSION=sess-ctr9\x00HOME=/root\x00", // the shell: dies on SIGHUP
		102: "FALAK_CONSOLE_SESSION=sess-ctr9\x00",                            // ignores SIGHUP: killed
		103: "FALAK_CONSOLE_SESSION=sess-ctr90\x00",                           // another session
		104: "X=FALAK_CONSOLE_SESSION=sess-ctr9\x00",                          // not the entry itself
	}, 101)
	m := New(Options{ProcRoot: fp.root, Signal: fp.signal})
	m.hangUpContainer("sess-ctr9")
	if got := strings.Join(fp.signals(), " "); got != "101:hangup 102:hangup 102:killed" {
		t.Fatalf("signals %s", got)
	}
}

// A closed container session hangs up its processes in the container; one that exited on its own doesn't.
func TestClosedContainerSessionHangsUp(t *testing.T) {
	dir := t.TempDir()
	cli := filepath.Join(dir, "docker")
	if err := os.WriteFile(cli, []byte("#!/bin/sh\necho ready\nexec sleep 30\n"), 0o755); err != nil {
		t.Fatal(err)
	}
	fp := newFakeProc(t, map[int]string{201: "FALAK_CONSOLE_SESSION=sess-ctr5\x00"}, 201)
	m := New(Options{DockerCLI: cli, Docker: &fakeDocker{byName: map[string]bool{"web": true}}, ProcRoot: fp.root, Signal: fp.signal})
	col := &commands.Collector{}
	go func() {
		for i := 0; i < 200 && m.count() == 0; i++ {
			time.Sleep(5 * time.Millisecond)
		}
		m.Close(context.Background(), "sess-ctr5")
	}()
	res, err := m.Open(context.Background(), OpenPayload{SessionID: "sess-ctr5", Container: &ContainerTarget{Name: "web"}}, commands.NewTestStream("c5", col))
	if err != nil || res.(OpenResult).Reason != ReasonClosed {
		t.Fatalf("%v %v", res, err)
	}
	if got := strings.Join(fp.signals(), " "); got != "201:hangup" {
		t.Fatalf("signals %q", got)
	}
}
