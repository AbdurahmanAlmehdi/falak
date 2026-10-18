package cgroup

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/resources"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

func TestRenderSlice(t *testing.T) {
	got := Render(Slice{Name: "site_shop", MemoryMaxBytes: 512 << 20, MemoryHighBytes: 460 << 20, MemoryLowBytes: 128 << 20, CPUQuotaPercent: 150, TasksMax: 512})
	want := `# Managed by Falak — do not edit
[Unit]
Description=Falak limits for site_shop
Before=slices.target

[Slice]
MemoryAccounting=yes
CPUAccounting=yes
TasksAccounting=yes
MemoryMax=536870912
MemoryHigh=482344960
MemoryLow=134217728
MemorySwapMax=0
CPUQuota=150%
TasksMax=512
`
	if got != want {
		t.Fatalf("got:\n%s\nwant:\n%s", got, want)
	}
	// Unset limits: no quota line, memory and tasks unlimited, swap allowed.
	got = Render(Slice{Name: "worker_01j9z8y7x6w5v4t3s2r1q0p9na"})
	if strings.Contains(got, "CPUQuota") || !strings.Contains(got, "MemoryMax=infinity\n") || !strings.Contains(got, "MemorySwapMax=infinity\n") || !strings.Contains(got, "TasksMax=infinity\n") {
		t.Fatal(got)
	}
}

func TestApplyWritesUnitsAndSetsPropertiesLive(t *testing.T) {
	root := t.TempDir()
	fr := &runnertest.Fake{}
	m := &Manager{Runner: fr, FS: hostfs.FS{Root: root}}
	ctx := context.Background()
	shop := Slice{Name: "site_shop", MemoryMaxBytes: 256 << 20, CPUQuotaPercent: 50}
	worker := Slice{Name: "worker_01j9z8y7x6w5v4t3s2r1q0p9na", TasksMax: 64}

	changed, errs, err := m.Apply(ctx, []Slice{shop, worker})
	if err != nil || len(changed) != 2 || len(errs) != 0 {
		t.Fatalf("%v %v", changed, err)
	}
	if b, _ := os.ReadFile(filepath.Join(root, UnitDir, "falak-site_shop.slice")); !strings.Contains(string(b), "MemoryMax=268435456") {
		t.Fatalf("%s", b)
	}
	want := []string{
		"systemctl daemon-reload",
		"systemctl set-property --runtime falak-site_shop.slice MemoryMax=268435456 MemoryHigh=infinity MemoryLow=0 MemorySwapMax=0 CPUQuota=50% TasksMax=infinity",
		"systemctl set-property --runtime falak-worker_01j9z8y7x6w5v4t3s2r1q0p9na.slice MemoryMax=infinity MemoryHigh=infinity MemoryLow=0 MemorySwapMax=infinity CPUQuota= TasksMax=64",
	}
	if got := fr.Lines(); strings.Join(got, "\n") != strings.Join(want, "\n") {
		t.Fatalf("got:\n%s", strings.Join(got, "\n"))
	}

	// Unchanged files: no reload, but every slice's limits are set again (idempotent; heals a lost runtime drop-in).
	fr.Reset()
	if changed, _, _ := m.Apply(ctx, []Slice{shop, worker}); len(changed) != 0 || len(fr.Lines()) != 2 || strings.Contains(strings.Join(fr.Lines(), ""), "daemon-reload") {
		t.Fatalf("%v %v", changed, fr.Lines())
	}
	fr.Reset()
	shop.MemoryMaxBytes = 512 << 20
	if changed, _, _ := m.Apply(ctx, []Slice{shop, worker}); strings.Join(changed, ",") != "site_shop" || len(fr.Lines()) != 3 || !strings.Contains(fr.Lines()[1], "falak-site_shop.slice MemoryMax=536870912") {
		t.Fatalf("%v %v", changed, fr.Lines())
	}

	// Prune: managed units not kept are removed and their live limits cleared; foreign units stay.
	os.WriteFile(filepath.Join(root, UnitDir, "falak-custom.slice"), []byte("[Slice]\n"), 0o644)
	fr.Reset()
	removed, err := m.Prune(ctx, []Slice{shop})
	if err != nil || strings.Join(removed, ",") != worker.Name {
		t.Fatalf("%v %v", removed, err)
	}
	fs := hostfs.FS{Root: root}
	if fs.Exists(filepath.Join(UnitDir, Unit(worker.Name))) || !fs.Exists(filepath.Join(UnitDir, "falak-custom.slice")) {
		t.Fatal("prune removed the wrong units")
	}
	if l := fr.Lines(); len(l) != 2 || l[0] != "systemctl daemon-reload" || !strings.HasPrefix(l[1], "systemctl set-property --runtime falak-worker_01j9z8y7x6w5v4t3s2r1q0p9na.slice MemoryMax=infinity") {
		t.Fatalf("%v", l)
	}
}

// falak.slice itself and its drop-ins belong to falak-ctl on the control-plane machine (50-host-cap.conf caps every
// workload): neither Apply nor Prune ever writes or removes them, even when they carry Falak's header.
func TestFalakSliceAndDropInsAreNeverTouched(t *testing.T) {
	root := t.TempDir()
	fs := hostfs.FS{Root: root}
	parent, dropIn := filepath.Join(UnitDir, "falak.slice"), filepath.Join(UnitDir, "falak.slice.d", "50-host-cap.conf")
	for _, p := range []string{parent, dropIn} {
		if _, err := fs.WriteFile(p, []byte(header+"[Slice]\nMemoryMax=1G\n"), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	m := &Manager{Runner: &runnertest.Fake{}, FS: fs}
	if _, _, err := m.Apply(context.Background(), []Slice{{Name: "site_shop", MemoryMaxBytes: 64 << 20}}); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Prune(context.Background(), nil); err != nil {
		t.Fatal(err)
	}
	for _, p := range []string{parent, dropIn} {
		if b, err := fs.ReadFile(p); err != nil || string(b) != header+"[Slice]\nMemoryMax=1G\n" {
			t.Fatalf("%s touched: %q %v", p, b, err)
		}
	}
	if fs.Exists(filepath.Join(UnitDir, Unit("site_shop"))) {
		t.Fatal("prune kept an unwanted slice")
	}
}

func TestPartitionSkipsOnlyInvalidSlices(t *testing.T) {
	valid, errs := Partition([]Slice{
		{Name: "site_shop", MemoryMaxBytes: 64 << 20},
		{Name: "site-shop"},
		{Name: "../x"},
		{Name: "site_shop"},
		{Name: "site_blog", MemoryMaxBytes: 512 << 20, MemoryLowBytes: 1 << 30},
		{Name: "worker_a", TasksMax: -1},
		{Name: "worker_b", TasksMax: 10},
	})
	if len(valid) != 2 || valid[0].Name != "site_shop" || valid[1].Name != "worker_b" {
		t.Fatalf("%+v", valid)
	}
	for _, name := range []string{"site-shop", "../x", "site_blog", "worker_a"} {
		if errs[name] == "" {
			t.Errorf("no error for %s: %v", name, errs)
		}
	}
	if !strings.Contains(errs["site_shop"], "duplicate") {
		t.Fatal(errs)
	}
}

// A slice whose set-property fails gets its previous unit back (no unit file ahead of its live limits) and is
// reported; the other slices are applied.
func TestApplyRestoresTheUnitWhenSetPropertyFails(t *testing.T) {
	root := t.TempDir()
	fr := (&runnertest.Fake{}).On("systemctl set-property --runtime falak-site_bad.slice", runner.Result{ExitCode: 1, Stderr: []byte("Unit falak-site_bad.slice not found")})
	m := &Manager{Runner: fr, FS: hostfs.FS{Root: root}}
	fs := hostfs.FS{Root: root}
	fs.MkdirAll(UnitDir, 0o755)
	old := Render(Slice{Name: "site_bad", MemoryMaxBytes: 64 << 20})
	fs.WriteFile(filepath.Join(UnitDir, Unit("site_bad")), []byte(old), 0o644)

	changed, errs, err := m.Apply(context.Background(), []Slice{{Name: "site_bad", MemoryMaxBytes: 128 << 20}, {Name: "site_new", CPUQuotaPercent: 50}, {Name: "site_gone", TasksMax: 5}})
	if err != nil || strings.Join(changed, ",") != "site_gone,site_new" || len(errs) != 1 || !strings.Contains(errs["site_bad"], "falak-site_bad.slice") {
		t.Fatalf("%v %v %v", changed, errs, err)
	}
	if b, _ := fs.ReadFile(filepath.Join(UnitDir, Unit("site_bad"))); string(b) != old {
		t.Fatalf("unit not restored:\n%s", b)
	}
	if l := fr.Lines(); l[len(l)-1] != "systemctl daemon-reload" {
		t.Fatal(l)
	}
}

func TestScopeCommand(t *testing.T) {
	spec := ScopeSpec{Slice: "worker_01j9z8y7x6w5v4t3s2r1q0p9na", Unit: "falak-proc-shop-worker-0", UID: 1001, GID: 1001, AsUser: true,
		Command: []string{"php", "artisan", "queue:work"}, SystemdVersion: 255, OomScoreAdj: -500}
	got := ScopeCommand(spec)
	// choom sets the OOM preference as root, before setpriv drops privileges.
	want := "systemd-run --scope --quiet --collect --slice=falak-worker_01j9z8y7x6w5v4t3s2r1q0p9na.slice --unit=falak-proc-shop-worker-0.scope " +
		"--property=OOMPolicy=continue -- choom -n -500 -- setpriv --reuid=1001 --regid=1001 --init-groups -- php artisan queue:work"
	if strings.Join(spec.Tools(), ",") != "systemd-run,choom,setpriv" {
		t.Fatal(spec.Tools())
	}
	if strings.Join(got, " ") != want {
		t.Fatalf("%s", strings.Join(got, " "))
	}
	// systemd 249/252 (Ubuntu 22.04, Debian 12): no OOMPolicy for scopes. Root programs need no setpriv.
	got = ScopeCommand(ScopeSpec{Slice: "site_shop", Unit: "falak-proc-shop-app-0", Command: []string{"node", "server.js"}, SystemdVersion: 252})
	if strings.Join(got, " ") != "systemd-run --scope --quiet --collect --slice=falak-site_shop.slice --unit=falak-proc-shop-app-0.scope -- node server.js" {
		t.Fatalf("%v", got)
	}
}

func TestSystemdVersion(t *testing.T) {
	fr := (&runnertest.Fake{}).On("systemctl --version", runner.Result{Stdout: []byte("systemd 255 (255.4-1ubuntu8)\n+PAM +AUDIT\n")})
	if v := SystemdVersion(context.Background(), fr); v != 255 {
		t.Fatal(v)
	}
	if v := SystemdVersion(context.Background(), (&runnertest.Fake{}).On("systemctl", runner.Result{ExitCode: 1})); v != 0 {
		t.Fatal(v)
	}
}

func TestWatchOOMReportsIncreases(t *testing.T) {
	root := t.TempDir()
	write := func(slice, events string) {
		d := filepath.Join(root, "falak-"+slice+".slice")
		os.MkdirAll(d, 0o755)
		os.WriteFile(filepath.Join(d, "memory.events"), []byte(events), 0o644)
	}
	write("site_shop", "low 0\nhigh 4\nmax 2\noom 1\noom_kill 1\noom_group_kill 0\n")
	write("worker_01j9z8y7x6w5v4t3s2r1q0p9na", "low 0\nhigh 0\nmax 0\noom 0\noom_kill 0\n")
	w := &WatchOOM{Root: root}
	var got []resources.Event
	add := func(e resources.Event) { got = append(got, e) }
	w.Once(add) // baseline
	if len(got) != 0 {
		t.Fatalf("%+v", got)
	}
	write("site_shop", "low 0\nhigh 9\nmax 5\noom 3\noom_kill 3\n")
	w.Once(add)
	if len(got) != 1 || got[0].Name != "site_shop" || got[0].Count != 2 || got[0].Kind != resources.KindOOMKill || got[0].Source != resources.SourceSlice {
		t.Fatalf("%+v", got)
	}
}

func TestLaunchFailureAndUserCanEnter(t *testing.T) {
	for in, want := range map[string]bool{
		"Failed to start transient scope unit: Unit falak-proc-x-0.scope already exists.\n": true,
		"setpriv: setresuid failed: Operation not permitted":                                true,
		"PHP Fatal error: Allowed memory size exhausted":                                    false,
	} {
		if LaunchFailure([]byte(in)) != want {
			t.Errorf("%q", in)
		}
	}
	dir, _ := os.MkdirTemp("/tmp", "cg")
	t.Cleanup(func() { os.RemoveAll(dir) })
	locked := filepath.Join(dir, "locked")
	os.Mkdir(locked, 0o700)
	os.Mkdir(filepath.Join(locked, "app"), 0o755)
	os.Chmod(dir, 0o755)
	if err := UserCanEnter(filepath.Join(locked, "app"), 54321, 54321, nil); err == nil {
		t.Fatal("entered a directory only its owner may enter")
	}
	if err := UserCanEnter(dir, 54321, 54321, nil); err != nil {
		t.Fatal(err)
	}
	if err := UserCanEnter(filepath.Join(locked, "app"), 0, 0, nil); err != nil {
		t.Fatal("root may enter anywhere")
	}
}
