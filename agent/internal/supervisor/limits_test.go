package supervisor

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"syscall"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/cgroup"
	"github.com/OthmanHaba/falak/agent/internal/resources"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

type fakeSlices struct {
	mu    sync.Mutex
	calls []string
	fail  map[string]string // set-property failures by slice
}

func (f *fakeSlices) Apply(_ context.Context, desired []cgroup.Slice) ([]string, map[string]string, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	var names []string
	for _, s := range desired {
		if _, failed := f.fail[s.Name]; !failed {
			names = append(names, s.Name)
		}
	}
	all := make([]string, 0, len(desired))
	for _, s := range desired {
		all = append(all, s.Name)
	}
	f.calls = append(f.calls, "apply "+strings.Join(all, ","))
	return names, f.fail, nil
}

func (f *fakeSlices) Prune(_ context.Context, keep []cgroup.Slice) ([]string, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	var names []string
	for _, s := range keep {
		names = append(names, s.Name)
	}
	f.calls = append(f.calls, "prune "+strings.Join(names, ","))
	return []string{"old"}, nil
}

func TestApplyWithSlicesOrdersSlicesAroundPrograms(t *testing.T) {
	s, _, _ := newSup(t)
	fs := &fakeSlices{}
	s.opts.Slices = fs
	ctx := context.Background()
	res, err := s.ApplyWithSlices(ctx, []Program{{Name: "web", Command: sh("exec sleep 30")}}, []cgroup.Slice{{Name: "site_shop", MemoryMaxBytes: 64 << 20}})
	if err != nil || strings.Join(fs.calls, "|") != "apply site_shop|prune site_shop" || strings.Join(res.Slices, ",") != "old,site_shop" || !res.Changed || res.SliceErrors != nil {
		t.Fatalf("%+v %v %v", res, err, fs.calls)
	}
	if _, err := s.ApplyWithSlices(ctx, nil, nil); err != nil {
		t.Fatal(err)
	}
}

// One service's bad slice (or a failed set-property) never stops the others: it is skipped and reported, its
// programs run without it, its unit is kept.
func TestApplyWithSlicesSkipsOnlyBadSlices(t *testing.T) {
	s, _, _ := newSup(t)
	fs := &fakeSlices{fail: map[string]string{"worker_b": "set-property failed"}}
	s.opts.Slices = fs
	res, err := s.ApplyWithSlices(context.Background(), []Program{
		{Name: "good", Command: sh("exec sleep 30"), Slice: "site_good"},
		{Name: "bad", Command: sh("exec sleep 30"), Slice: "site_bad"},
		{Name: "failing", Command: sh("exec sleep 30"), Slice: "worker_b"},
		{Name: "undeclared", Command: sh("exec sleep 30"), Slice: "site_other"},
	}, []cgroup.Slice{
		{Name: "site_good", MemoryMaxBytes: 64 << 20},
		{Name: "site_bad", MemoryMaxBytes: 512 << 20, MemoryLowBytes: 1 << 30},
		{Name: "worker_b", TasksMax: 10},
	})
	if err != nil || len(res.Started) != 4 {
		t.Fatalf("%+v %v", res, err)
	}
	if len(res.SliceErrors) != 3 || res.SliceErrors["site_bad"] == "" || res.SliceErrors["worker_b"] == "" || !strings.Contains(res.SliceErrors["site_other"], "not in slices") {
		t.Fatalf("%v", res.SliceErrors)
	}
	s.mu.Lock()
	slices := map[string]string{}
	for name, p := range s.programs {
		slices[name] = p.spec.Slice
	}
	s.mu.Unlock()
	if slices["good"] != "site_good" || slices["bad"] != "" || slices["failing"] != "" || slices["undeclared"] != "" {
		t.Fatalf("%v", slices)
	}
	if fs.calls[0] != "apply site_good,worker_b" || !strings.Contains(fs.calls[1], "site_bad") || !strings.Contains(fs.calls[1], "worker_b") {
		t.Fatal(fs.calls)
	}
}

func TestLaunchInSliceWrapsInScope(t *testing.T) {
	fr := (&runnertest.Fake{}).On("systemctl --version", runner.Result{Stdout: []byte("systemd 249 (249.11-0ubuntu3)\n")})
	var entered string
	s := New(Options{Systemd: fr, LookPath: func(name string) (string, error) { return "/usr/bin/" + name, nil },
		CanEnter: func(dir string, uid, gid uint32, groups []uint32) error { entered = dir; return nil }})
	cred := &syscall.Credential{Uid: 1001, Gid: 1002, Groups: []uint32{1002, 33}}

	argv, c, err := s.launch(Program{Name: "shop.worker", Command: []string{"php", "artisan", "queue:work"}}, 0, cred)
	if err != nil || strings.Join(argv, " ") != "php artisan queue:work" || c != cred || len(fr.Lines()) != 0 {
		t.Fatalf("%v %v", argv, fr.Lines())
	}
	argv, c, err = s.launch(Program{Name: "shop.worker", Command: []string{"php", "artisan", "queue:work"}, Slice: "worker_01j9z8y7x6w5v4t3s2r1q0p9na",
		Cwd: "/srv/falak/sites/shop/current", OomScoreAdj: -500}, 2, cred)
	want := "systemd-run --scope --quiet --collect --slice=falak-worker_01j9z8y7x6w5v4t3s2r1q0p9na.slice --unit=falak-proc-shop.worker-2.scope -- " +
		"choom -n -500 -- setpriv --reuid=1001 --regid=1002 --init-groups -- php artisan queue:work"
	if err != nil || strings.Join(argv, " ") != want || c != nil || entered != "/srv/falak/sites/shop/current" {
		t.Fatalf("%s %v", strings.Join(argv, " "), err)
	}
	if l := fr.Lines(); len(l) != 2 || l[0] != "systemctl --version" || l[1] != "systemctl stop --quiet falak-proc-shop.worker-2.scope" {
		t.Fatalf("%v", l)
	}
	if !cgroup.ScopeUnitRe.MatchString("falak-proc-shop.worker-2") {
		t.Fatal("scope name")
	}

	// The control-plane machine: a program without limits still runs capped, in falak.slice; one with limits in its
	// own slice (a child of falak.slice).
	s.opts.DefaultSlice = "falak.slice"
	argv, c, err = s.launch(Program{Name: "shop.worker", Command: []string{"php", "artisan", "queue:work"}}, 0, cred)
	want = "systemd-run --scope --quiet --collect --slice=falak.slice --unit=falak-proc-shop.worker-0.scope -- " +
		"setpriv --reuid=1001 --regid=1002 --init-groups -- php artisan queue:work"
	if err != nil || strings.Join(argv, " ") != want || c != nil {
		t.Fatalf("%s %v", strings.Join(argv, " "), err)
	}
	argv, _, _ = s.launch(Program{Name: "shop.worker", Command: []string{"php"}, Slice: "site_shop"}, 0, nil)
	if !strings.Contains(strings.Join(argv, " "), "--slice=falak-site_shop.slice ") {
		t.Fatalf("%v", argv)
	}
}

// A launch that can't run the program is a launch error (state, status), never mistaken for the program's exit.
func TestLaunchErrorsAreReported(t *testing.T) {
	s, _, _ := newSup(t)
	s.opts.LookPath = func(name string) (string, error) { return "", errors.New("not found") }
	s.opts.Systemd = (&runnertest.Fake{}).On("systemctl --version", runner.Result{Stdout: []byte("systemd 255\n")})
	if _, err := s.Apply(context.Background(), []Program{{Name: "w", Command: sh("exit 0"), Slice: "site_shop", Backoff: &Backoff{InitialMS: 10, MaxMS: 10}}}); err != nil {
		t.Fatal(err)
	}
	eventually(t, 3*time.Second, func() bool {
		st := s.Status(nil)
		return len(st) == 1 && strings.Contains(st[0].LaunchError, "systemd-run is not installed")
	}, "launch error")

	st := s.Status(nil)[0]
	if *st.LastExitCode != 127 {
		t.Fatalf("%+v", st)
	}
}

// After an agent crash, scopes of programs that are not restored are stopped at start.
func TestStartStopsOrphanedScopes(t *testing.T) {
	dir := t.TempDir()
	fr := (&runnertest.Fake{}).On("systemctl list-units", runner.Result{Stdout: []byte(
		"falak-proc-shop.web-0.scope loaded active running Falak\nfalak-proc-gone-0.scope loaded active running x\nother.scope loaded active running y\n")})
	state := `{"programs":[{"name":"shop.web","command":["/bin/sh","-c","exec sleep 30"],"numprocs":1}]}`
	os.MkdirAll(filepath.Join(dir, "state"), 0o700)
	os.WriteFile(filepath.Join(dir, "state", "proc.json"), []byte(state), 0o600)
	s := New(Options{StateDir: filepath.Join(dir, "state"), LogDir: filepath.Join(dir, "log"), Systemd: fr, Slices: &fakeSlices{}})
	t.Cleanup(s.Shutdown)
	if err := s.Start(context.Background()); err != nil {
		t.Fatal(err)
	}
	var stops []string
	for _, l := range fr.Lines() {
		if strings.HasPrefix(l, "systemctl stop") {
			stops = append(stops, l)
		}
	}
	if strings.Join(stops, "|") != "systemctl stop --quiet falak-proc-gone-0.scope" {
		t.Fatal(fr.Lines())
	}
}

func TestMaxRestartsGivesUp(t *testing.T) {
	s, _, _ := newSup(t)
	_, err := s.Apply(context.Background(), []Program{{Name: "crash", Command: sh("exit 3"), MaxRestarts: 2, StartSeconds: ip(5),
		Backoff: &Backoff{InitialMS: 10, MaxMS: 10}}})
	if err != nil {
		t.Fatal(err)
	}
	eventually(t, 5*time.Second, func() bool { st := s.Status(nil); return len(st) == 1 && st[0].State == StateFatal }, "fatal after max restarts")
	if st := s.Status(nil)[0]; st.Restarts != 2 || *st.LastExitCode != 3 {
		t.Fatalf("%+v", st)
	}
}

func TestLogRotationKeepsFiles(t *testing.T) {
	dir := t.TempDir()
	r, err := openRot(filepath.Join(dir, "x.log"), 4, 2)
	if err != nil {
		t.Fatal(err)
	}
	for _, l := range []string{"aaa\n", "bbb\n", "ccc\n", "ddd\n"} {
		r.Write([]byte(l))
	}
	r.Close()
	read := func(n string) string { b, _ := os.ReadFile(filepath.Join(dir, n)); return string(b) }
	if read("x.log") != "ddd\n" || read("x.log.1") != "ccc\n" || read("x.log.2") != "bbb\n" || read("x.log.3") != "" {
		t.Fatalf("%q %q %q", read("x.log"), read("x.log.1"), read("x.log.2"))
	}
}

func TestRestartsAreReported(t *testing.T) {
	s, _, _ := newSup(t)
	_, err := s.Apply(context.Background(), []Program{{Name: "shop.flaky", Site: "shop", Command: sh("exit 1"), Numprocs: 2, Backoff: &Backoff{InitialMS: 10, MaxMS: 10}}})
	if err != nil {
		t.Fatal(err)
	}
	var counts resources.Counter
	var got []resources.Event
	s.restartsOnce(&counts, func(e resources.Event) { got = append(got, e) })
	eventually(t, 5*time.Second, func() bool {
		got = nil
		s.restartsOnce(&counts, func(e resources.Event) { got = append(got, e) })
		return len(got) == 1 && got[0].Count >= 2
	}, "restarts of both instances reported")
	if got[0].Kind != resources.KindRestart || got[0].Source != resources.SourceProgram || got[0].Name != "shop.flaky" || got[0].Site != "shop" {
		t.Fatalf("%+v", got)
	}
}
