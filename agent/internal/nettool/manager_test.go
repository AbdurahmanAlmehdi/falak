package nettool

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/docker"
)

type fakeDocker struct {
	list    []docker.ContainerSummary
	inspect map[string]*docker.Container
	filters []string
}

func (f *fakeDocker) ContainerList(_ context.Context, _ bool, labels []string) ([]docker.ContainerSummary, error) {
	f.filters = labels
	return f.list, nil
}

func (f *fakeDocker) ContainerInspect(_ context.Context, ref string) (*docker.Container, bool, error) {
	c, ok := f.inspect[ref]
	return c, ok, nil
}

func running(name string, pid int) *docker.Container {
	c := &docker.Container{Name: "/" + name}
	c.State.Running = true
	c.State.Pid = pid
	return c
}

func quiet() *slog.Logger { return slog.New(slog.NewTextHandler(io.Discard, nil)) }

func TestArgvHost(t *testing.T) {
	m := New(Options{Logger: quiet(), Binary: "/usr/local/bin/falak-agent"})
	argv, ns, err := m.argv(context.Background(), Spec{Tool: ToolTCP, Target: "db", Port: 5432, Count: 3, TimeoutS: 30})
	if err != nil || ns != "host" || len(argv) != 3 || argv[0] != "/usr/local/bin/falak-agent" || argv[1] != HelperCommand {
		t.Fatalf("argv %v ns %s err %v", argv, ns, err)
	}
	var hs HelperSpec
	if err := json.Unmarshal([]byte(argv[2]), &hs); err != nil || hs.Target != "db" || hs.ResolvConf != "" || hs.Container != nil {
		t.Fatalf("spec %s: %v", argv[2], err)
	}
}

func TestArgvContainerByLabels(t *testing.T) {
	d := &fakeDocker{
		list: []docker.ContainerSummary{
			{ID: "old", State: "running", Created: 1, Labels: map[string]string{"falak.site": "shop"}},
			{ID: "new", State: "running", Created: 2, Labels: map[string]string{"falak.site": "shop"}},
			{ID: "other", State: "running", Created: 3, Labels: map[string]string{"falak.site": "blog"}}, // a widened match is ignored
			{ID: "dead", State: "exited", Created: 4, Labels: map[string]string{"falak.site": "shop"}},
		},
		inspect: map[string]*docker.Container{"new": running("shop-web-1", 4242)},
	}
	m := New(Options{Logger: quiet(), Docker: d, Binary: "/agent", NSEnter: "sh", ProcRoot: "/hostproc"})
	argv, ns, err := m.argv(context.Background(), Spec{Tool: ToolDNS, Target: "db", RecordType: "A", TimeoutS: 5, Container: &Container{Labels: map[string]string{"falak.site": "shop"}}})
	if err != nil {
		t.Fatal(err)
	}
	if ns != "container:shop-web-1" || !strings.HasSuffix(argv[0], "/sh") || argv[1] != "--net=/hostproc/4242/ns/net" || argv[2] != "--" || argv[3] != "/agent" || argv[4] != HelperCommand {
		t.Fatalf("argv %v ns %s", argv, ns)
	}
	var hs HelperSpec
	_ = json.Unmarshal([]byte(argv[5]), &hs)
	if hs.ResolvConf != "/hostproc/4242/root/etc/resolv.conf" || hs.HostsFile != "/hostproc/4242/root/etc/hosts" || hs.Container != nil {
		t.Fatalf("helper spec %+v", hs)
	}
	if strings.Join(d.filters, ",") != "falak.site=shop" {
		t.Fatalf("filters %v", d.filters)
	}
}

func TestArgvContainerMissing(t *testing.T) {
	stopped := running("falak-db-1", 0)
	stopped.State.Running = false
	d := &fakeDocker{inspect: map[string]*docker.Container{"falak-db-1": stopped}}
	m := New(Options{Logger: quiet(), Docker: d, NSEnter: "sh"})
	for _, c := range []*Container{{Name: "falak-db-1"}, {Name: "falak-db-2"}, {Labels: map[string]string{"falak.site": "x"}}} {
		if _, _, err := m.argv(context.Background(), Spec{Tool: ToolPing, Target: "a", Count: 1, TimeoutS: 5, Container: c}); !errors.Is(err, ErrNoContainer) {
			t.Errorf("%+v: %v", c, err)
		}
	}
	m = New(Options{Logger: quiet(), Docker: d, NSEnter: "definitely-not-nsenter"})
	if _, _, err := m.argv(context.Background(), Spec{Tool: ToolPing, Target: "a", Container: &Container{Name: "falak-db-1"}}); err == nil || !strings.Contains(err.Error(), "nsenter") {
		t.Fatalf("missing nsenter: %v", err)
	}
}

func TestExecFoldsCRLFAndReportsExit(t *testing.T) {
	m := New(Options{Logger: quiet()})
	var out bytes.Buffer
	code, timedOut, err := m.exec(context.Background(), []string{"/bin/sh", "-c", "printf 'a\\r\\nb\\n'; exit 3"}, 5*time.Second, &out)
	if err != nil || timedOut || code != 3 || out.String() != "a\nb\n" {
		t.Fatalf("code %d timedOut %v err %v out %q", code, timedOut, err, out.String())
	}
}

func TestExecKillsPastTimeout(t *testing.T) {
	m := New(Options{Logger: quiet()})
	ctx, cancel := context.WithTimeout(context.Background(), 300*time.Millisecond)
	defer cancel()
	start := time.Now()
	_, timedOut, err := m.exec(ctx, []string{"/bin/sh", "-c", "sleep 30"}, 30*time.Second, io.Discard)
	if err != nil || !timedOut || time.Since(start) > 5*time.Second {
		t.Fatalf("timedOut %v err %v after %s", timedOut, err, time.Since(start))
	}
}

func TestExecCapsOutput(t *testing.T) {
	m := New(Options{Logger: quiet(), MaxOutput: 1000})
	var out bytes.Buffer
	start := time.Now()
	_, _, err := m.exec(context.Background(), []string{"/bin/sh", "-c", "yes falak"}, 30*time.Second, &out)
	if err != nil || time.Since(start) > 5*time.Second || !strings.HasSuffix(out.String(), "… output truncated\n") || out.Len() > 1100 {
		t.Fatalf("err %v len %d tail %q", err, out.Len(), out.String()[max(0, out.Len()-40):])
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

func TestRunMapsExitCodes(t *testing.T) {
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
}
