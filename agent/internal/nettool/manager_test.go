package nettool

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"syscall"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/docker"
)

type fakeDocker struct {
	list    []docker.ContainerSummary
	inspect map[string]*docker.Container
	// after, when set, answers inspects from the second one on (the container changed in between).
	after   *docker.Container
	calls   int
	filters []string
}

func (f *fakeDocker) ContainerList(_ context.Context, _ bool, labels []string) ([]docker.ContainerSummary, error) {
	f.filters = labels
	return f.list, nil
}

func (f *fakeDocker) ContainerInspect(_ context.Context, ref string) (*docker.Container, bool, error) {
	f.calls++
	if f.after != nil && f.calls > 1 {
		return f.after, true, nil
	}
	if c, ok := f.inspect[ref]; ok {
		return c, true, nil
	}
	for _, c := range f.inspect { // by id, as Docker answers a name or an id
		if c.ID == ref {
			return c, true, nil
		}
	}
	return nil, false, nil
}

// containerID is a full Docker id (the cgroup check looks for it).
const containerID = "4f1c2b3a4d5e6f708192a3b4c5d6e7f8091a2b3c4d5e6f708192a3b4c5d6e7f8"

func running(name string, pid int) *docker.Container {
	c := &docker.Container{ID: containerID, Name: "/" + name}
	c.State.Running = true
	c.State.Pid = pid
	c.State.StartedAt = "2026-10-15T10:00:00Z"
	return c
}

func quiet() *slog.Logger { return slog.New(slog.NewTextHandler(io.Discard, nil)) }

// fakeContainer lays out a host for a container with init pid 4242: /proc/4242/{ns/net,cgroup} under a proc root and
// Docker's host-side resolv.conf and hosts; it returns the proc root and the inspect answer.
func fakeContainer(t *testing.T, cgroup string) (string, *docker.Container) {
	t.Helper()
	dir := t.TempDir()
	proc := filepath.Join(dir, "proc")
	pid := filepath.Join(proc, "4242")
	_ = os.MkdirAll(filepath.Join(pid, "ns"), 0o755)
	_ = os.WriteFile(filepath.Join(pid, "ns", "net"), nil, 0o644)
	_ = os.WriteFile(filepath.Join(pid, "cgroup"), []byte(cgroup), 0o644)
	ct := running("shop-web-1", 4242)
	ct.ResolvConfPath = filepath.Join(dir, "containers", "resolv.conf")
	ct.HostsPath = filepath.Join(dir, "containers", "hosts")
	_ = os.MkdirAll(filepath.Dir(ct.ResolvConfPath), 0o755)
	_ = os.WriteFile(ct.ResolvConfPath, []byte("nameserver 127.0.0.11\noptions ndots:0\n"), 0o644)
	_ = os.WriteFile(ct.HostsPath, []byte("172.18.0.5 db\n"), 0o644)
	return proc, ct
}

func decodeSpec(t *testing.T, b []byte) HelperSpec {
	t.Helper()
	var hs HelperSpec
	if err := json.Unmarshal(b, &hs); err != nil {
		t.Fatal(err)
	}
	return hs
}

func TestPrepareHost(t *testing.T) {
	m := New(Options{Logger: quiet(), Binary: "/usr/local/bin/falak-agent"})
	h, err := m.prepare(context.Background(), Spec{Tool: ToolTCP, Target: "db", Port: 5432, Count: 3, TimeoutS: 30})
	if err != nil || h.namespace != "host" || strings.Join(h.argv, " ") != "/usr/local/bin/falak-agent nettool" || h.netns != nil {
		t.Fatalf("helper %+v err %v", h, err)
	}
	// The spec goes on stdin, never in argv.
	if hs := decodeSpec(t, h.spec); hs.Target != "db" || hs.InContainer || hs.ResolvConf != "" || hs.Container != nil {
		t.Fatalf("spec %s", h.spec)
	}
}

func TestPrepareContainerByLabels(t *testing.T) {
	proc, ct := fakeContainer(t, "0::/system.slice/docker-"+containerID+".scope\n")
	d := &fakeDocker{
		list: []docker.ContainerSummary{
			{ID: "old", State: "running", Created: 1, Labels: map[string]string{"falak.site": "shop"}},
			{ID: containerID, State: "running", Created: 2, Labels: map[string]string{"falak.site": "shop"}},
			{ID: "other", State: "running", Created: 3, Labels: map[string]string{"falak.site": "blog"}}, // a widened match is ignored
			{ID: "dead", State: "exited", Created: 4, Labels: map[string]string{"falak.site": "shop"}},
		},
		inspect: map[string]*docker.Container{containerID: ct},
	}
	m := New(Options{Logger: quiet(), Docker: d, Binary: "/agent", NSEnter: "sh", ProcRoot: proc})
	h, err := m.prepare(context.Background(), Spec{Tool: ToolDNS, Target: "db", RecordType: "A", TimeoutS: 5, Container: &Container{Labels: map[string]string{"falak.site": "shop"}}})
	if err != nil {
		t.Fatal(err)
	}
	defer h.close()
	if h.namespace != "container:shop-web-1" || !strings.HasSuffix(h.argv[0], "/sh") || strings.Join(h.argv[1:], " ") != "--net=/proc/self/fd/3 -- /agent nettool" {
		t.Fatalf("argv %v ns %s", h.argv, h.namespace)
	}
	if h.netns == nil || h.netns.Name() != filepath.Join(proc, "4242", "ns", "net") {
		t.Fatalf("the namespace is pinned by an open descriptor: %v", h.netns)
	}
	hs := decodeSpec(t, h.spec)
	if !hs.InContainer || hs.ResolvConf != "nameserver 127.0.0.11\noptions ndots:0\n" || hs.Hosts != "172.18.0.5 db\n" || hs.Container != nil {
		t.Fatalf("helper spec %+v", hs)
	}
	if strings.Join(d.filters, ",") != "falak.site=shop" || d.calls != 2 {
		t.Fatalf("filters %v inspects %d", d.filters, d.calls)
	}
}

func TestPrepareRefusesReusedPid(t *testing.T) {
	// The pid's cgroup is another process's (the init exited and the pid was reused).
	proc, ct := fakeContainer(t, "0::/user.slice/user-1000.slice/session-3.scope\n")
	d := &fakeDocker{inspect: map[string]*docker.Container{"shop": ct}}
	m := New(Options{Logger: quiet(), Docker: d, NSEnter: "sh", ProcRoot: proc})
	if _, err := m.prepare(context.Background(), Spec{Tool: ToolPing, Target: "a", Count: 1, TimeoutS: 5, Container: &Container{Name: "shop"}}); err == nil || !strings.Contains(err.Error(), "not container") {
		t.Fatalf("foreign cgroup: %v", err)
	}

	// The container restarted between the two inspects (same id, new start).
	proc, ct = fakeContainer(t, "0::/system.slice/docker-"+containerID+".scope\n")
	restarted := running("shop-web-1", 4242)
	restarted.State.StartedAt = "2026-10-15T10:05:00Z"
	d = &fakeDocker{inspect: map[string]*docker.Container{"shop": ct}, after: restarted}
	m = New(Options{Logger: quiet(), Docker: d, NSEnter: "sh", ProcRoot: proc})
	if _, err := m.prepare(context.Background(), Spec{Tool: ToolPing, Target: "a", Count: 1, TimeoutS: 5, Container: &Container{Name: "shop"}}); err == nil || !strings.Contains(err.Error(), "restarted") {
		t.Fatalf("restart: %v", err)
	}
}

func TestPrepareRefusesUnsafeResolvConf(t *testing.T) {
	for name, swap := range map[string]func(string) error{
		"fifo":    func(p string) error { return syscall.Mkfifo(p, 0o644) },
		"symlink": func(p string) error { return os.Symlink("/etc/hostname", p) },
	} {
		proc, ct := fakeContainer(t, "0::/docker/"+containerID+"\n")
		_ = os.Remove(ct.ResolvConfPath)
		if err := swap(ct.ResolvConfPath); err != nil {
			t.Fatal(err)
		}
		m := New(Options{Logger: quiet(), Docker: &fakeDocker{inspect: map[string]*docker.Container{"shop": ct}}, NSEnter: "sh", ProcRoot: proc})
		done := make(chan error, 1)
		go func() {
			_, err := m.prepare(context.Background(), Spec{Tool: ToolPing, Target: "a", Count: 1, TimeoutS: 5, Container: &Container{Name: "shop"}})
			done <- err
		}()
		select {
		case err := <-done:
			if err == nil || !strings.Contains(err.Error(), "resolv.conf") {
				t.Errorf("%s: %v", name, err)
			}
		case <-time.After(3 * time.Second):
			t.Fatalf("%s: prepare blocked", name)
		}
	}
}

func TestPrepareContainerMissing(t *testing.T) {
	stopped := running("falak-db-1", 0)
	stopped.State.Running = false
	d := &fakeDocker{inspect: map[string]*docker.Container{"falak-db-1": stopped}}
	m := New(Options{Logger: quiet(), Docker: d, NSEnter: "sh", ProcRoot: t.TempDir()})
	for _, c := range []*Container{{Name: "falak-db-1"}, {Name: "falak-db-2"}, {Labels: map[string]string{"falak.site": "x"}}} {
		if _, err := m.prepare(context.Background(), Spec{Tool: ToolPing, Target: "a", Count: 1, TimeoutS: 5, Container: c}); !errors.Is(err, ErrNoContainer) {
			t.Errorf("%+v: %v", c, err)
		}
	}
	// Running per inspect, but its /proc entry is gone already.
	d = &fakeDocker{inspect: map[string]*docker.Container{"shop": running("shop", 99999)}}
	m = New(Options{Logger: quiet(), Docker: d, NSEnter: "sh", ProcRoot: t.TempDir()})
	if _, err := m.prepare(context.Background(), Spec{Tool: ToolPing, Target: "a", Count: 1, TimeoutS: 5, Container: &Container{Name: "shop"}}); !errors.Is(err, ErrNoContainer) {
		t.Errorf("gone pid: %v", err)
	}
	m = New(Options{Logger: quiet(), Docker: d, NSEnter: "definitely-not-nsenter"})
	if _, err := m.prepare(context.Background(), Spec{Tool: ToolPing, Target: "a", Container: &Container{Name: "falak-db-1"}}); err == nil || !strings.Contains(err.Error(), "nsenter") {
		t.Fatalf("missing nsenter: %v", err)
	}
}

func shHelper(script string) *helper {
	return &helper{argv: []string{"/bin/sh", "-c", script}, spec: []byte("the-spec\n")}
}

func TestExecSpecOnStdinFoldsCRLFAndReportsExit(t *testing.T) {
	m := New(Options{Logger: quiet()})
	var out bytes.Buffer
	code, end, err := m.exec(context.Background(), shHelper(`read x; printf '%s\r\nb\n' "$x"; exit 3`), 5*time.Second, &out)
	if err != nil || end != "" || code != 3 || out.String() != "the-spec\nb\n" {
		t.Fatalf("code %d end %q err %v out %q", code, end, err, out.String())
	}
}

func TestExecPassesTheNamespaceDescriptor(t *testing.T) {
	f, err := os.Open(os.DevNull)
	if err != nil {
		t.Fatal(err)
	}
	defer f.Close()
	h := shHelper(`[ -e /dev/fd/3 ] && echo fd3`)
	h.netns = f
	var out bytes.Buffer
	if code, _, err := New(Options{Logger: quiet()}).exec(context.Background(), h, 5*time.Second, &out); err != nil || code != 0 || out.String() != "fd3\n" {
		t.Fatalf("code %d err %v out %q", code, err, out.String())
	}
}

func TestExecEndReasons(t *testing.T) {
	m := New(Options{Logger: quiet()})

	ctx, cancel := context.WithTimeout(context.Background(), 300*time.Millisecond)
	defer cancel()
	start := time.Now()
	if _, end, err := m.exec(ctx, shHelper("sleep 30"), 30*time.Second, io.Discard); err != nil || end != endTimeout || time.Since(start) > 5*time.Second {
		t.Fatalf("deadline: end %q err %v after %s", end, err, time.Since(start))
	}

	ctx, cancel = context.WithCancel(context.Background())
	time.AfterFunc(200*time.Millisecond, cancel)
	if _, end, err := m.exec(ctx, shHelper("sleep 30"), 30*time.Second, io.Discard); err != nil || end != endCancelled {
		t.Fatalf("cancel: end %q err %v", end, err)
	}

	capped := New(Options{Logger: quiet(), MaxOutput: 1000})
	var out bytes.Buffer
	if _, end, err := capped.exec(context.Background(), shHelper("yes falak"), 30*time.Second, &out); err != nil || end != endOutput ||
		!strings.HasSuffix(out.String(), "… output truncated\n") || out.Len() > 1100 {
		t.Fatalf("cap: end %q err %v len %d", end, err, out.Len())
	}
}

type events struct {
	mu  sync.Mutex
	evs []commands.Event
}

func (e *events) Emit(ev commands.Event) { e.mu.Lock(); e.evs = append(e.evs, ev); e.mu.Unlock() }

func TestRunRefusesInvalidAndBusy(t *testing.T) {
	m := New(Options{Logger: quiet(), MaxRunning: 1})
	st := commands.NewTestStream("c", &events{})
	if _, err := m.Run(context.Background(), Spec{Tool: ToolPing, Target: "-x"}, st); !commands.IsPayloadError(err) {
		t.Fatalf("invalid: %v", err)
	}
	m.sem <- struct{}{}
	if _, err := m.Run(context.Background(), Spec{Tool: ToolPing, Target: "a"}, st); err == nil || !strings.Contains(err.Error(), "too many") {
		t.Fatalf("busy: %v", err)
	}
}

func TestRunMapsExitCodesAndReasons(t *testing.T) {
	// A stand-in helper: Binary is a script that ignores its arguments.
	dir := t.TempDir()
	script := dir + "/helper"
	for code, want := range map[string]int{"0": 0, "1": 1, "124": ExitTimeout, "127": ExitNotInstalled} {
		if err := writeExec(script, "#!/bin/sh\necho out\nexit "+code+"\n"); err != nil {
			t.Fatal(err)
		}
		m := New(Options{Logger: quiet(), Binary: script})
		sink := &events{}
		st := commands.NewTestStream("c", sink)
		res, err := m.Run(context.Background(), Spec{Tool: ToolTCP, Target: "a", Port: 1}, st)
		st.Flush()
		if want == 0 {
			if r, ok := res.(Result); err != nil || !ok || r.Namespace != "host" {
				t.Fatalf("exit 0: %v %v", res, err)
			}
		} else {
			var ee *commands.ExitError
			if !errors.As(err, &ee) || ee.Code != want {
				t.Fatalf("exit %s: %v", code, err)
			}
		}
		if len(sink.evs) == 0 || sink.evs[0].Stream != "stdout" {
			t.Fatalf("exit %s: no stdout events: %+v", code, sink.evs)
		}
	}

	// Killed at the output cap: "output limit reached", not 137.
	_ = writeExec(script, "#!/bin/sh\nyes falak\n")
	m := New(Options{Logger: quiet(), Binary: script, MaxOutput: 2000})
	_, err := m.Run(context.Background(), Spec{Tool: ToolTCP, Target: "a", Port: 1}, commands.NewTestStream("c", &events{}))
	var ee *commands.ExitError
	if !errors.As(err, &ee) || ee.Code != 1 || !strings.Contains(err.Error(), "output limit reached") {
		t.Fatalf("cap: %v", err)
	}

	// Cancelled (the control plane cancelled the command): "cancelled", not a timeout.
	_ = writeExec(script, "#!/bin/sh\nsleep 30\n")
	ctx, cancel := context.WithCancel(context.Background())
	time.AfterFunc(200*time.Millisecond, cancel)
	_, err = New(Options{Logger: quiet(), Binary: script}).Run(ctx, Spec{Tool: ToolTCP, Target: "a", Port: 1}, commands.NewTestStream("c", &events{}))
	if !errors.As(err, &ee) || ee.Code != ExitCancelled || !strings.Contains(err.Error(), "cancelled") {
		t.Fatalf("cancel: %v", err)
	}
}
