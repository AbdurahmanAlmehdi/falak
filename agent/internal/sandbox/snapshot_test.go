package sandbox

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

const (
	sid2 = "01j9z8y7x6w5v4t3s2r1q0p9nb"
	snap = "01j9z8y7x6w5v4t3s2r1q0snap"
)

// checkpointWrites makes `runsc checkpoint` write a 100-byte pages.img into its --image-path.
func (e *env) checkpointWrites() {
	e.run.OnFunc("runsc --root="+e.svc.d.RunscRoot+" checkpoint", func(c runnertest.Call) (runner.Result, error) {
		for _, a := range c.Args {
			if p, ok := strings.CutPrefix(a, "--image-path="); ok {
				_ = os.WriteFile(filepath.Join(p, "pages.img"), make([]byte, 100), 0o600)
			}
		}
		return runner.Result{}, nil
	})
}

// running creates sid (internet) and makes runsc report it running.
func (e *env) running(t *testing.T) CreateResult {
	t.Helper()
	e.runscList(map[string]string{})
	res, err := e.svc.Create(context.Background(), createPayload(NetInternet), nil)
	if err != nil {
		t.Fatal(err)
	}
	e.runscList(map[string]string{cid(sid): "running"})
	return res.(CreateResult)
}

func restorePayload() CreatePayload {
	p := createPayload(NetInternet)
	p.ID, p.FromSnapshot, p.Env = sid2, snap, map[string]string{"FORK": "1", "APP_ENV": "fork"}
	return p
}

func TestSnapshotRestoreAndDelete(t *testing.T) {
	e := newEnv(t)
	ctx := context.Background()
	src := e.running(t)
	e.checkpointWrites()
	e.run.Reset()

	res, err := e.svc.Snapshot(ctx, SnapshotPayload{ID: sid, SnapshotID: snap}, nil)
	if err != nil {
		t.Fatal(err)
	}
	r := res.(SnapshotResult)
	if r != (SnapshotResult{SnapshotID: snap, SizeBytes: 100, ImageDigest: src.ImageDigest, CreatedAt: "2025-10-09T08:53:20Z"}) {
		t.Fatalf("%+v", r)
	}
	img := e.svc.snapImage(snap)
	if !e.run.Ran("runsc --root=" + e.svc.d.RunscRoot + " checkpoint --image-path=" + e.svc.snapDir(snap) + ".tmp/image --leave-running falak-sbx-" + sid) {
		t.Fatal(e.run.Lines())
	}
	if _, err := os.Stat(filepath.Join(img, "pages.img")); err != nil {
		t.Fatal(err)
	}
	for p, want := range map[string]os.FileMode{e.svc.snapRoot(): 0o700, e.svc.snapDir(snap): 0o700, filepath.Join(e.svc.snapDir(snap), "meta.json"): 0o600} {
		if fi, err := os.Stat(p); err != nil || fi.Mode().Perm() != want {
			t.Errorf("%s: %v %v", p, fi, err)
		}
	}
	var m snapMeta
	_ = json.Unmarshal(mustRead(t, filepath.Join(e.svc.snapDir(snap), "meta.json")), &m)
	if m.SandboxID != sid || m.CPUs != 0.5 || m.MemoryMB != 512 || m.DiskMB != 2048 || m.Pids != 1024 || m.Network != NetInternet ||
		!slices.Contains(m.Env, "APP_ENV=sandbox") || !slices.Contains(m.Env, "LANG=C.UTF-8") || m.Rootfs == "" {
		t.Fatalf("%+v", m)
	}
	// The source keeps running.
	if _, err := os.Stat(e.svc.dir(sid)); err != nil || e.run.Ran("runsc --root="+e.svc.d.RunscRoot+" delete") {
		t.Fatal("source removed", err)
	}

	// Redelivered: the same result, no second checkpoint.
	e.run.Reset()
	again, err := e.svc.Snapshot(ctx, SnapshotPayload{ID: sid, SnapshotID: snap}, nil)
	if err != nil || again.(SnapshotResult) != r || e.run.Ran("runsc --root="+e.svc.d.RunscRoot+" checkpoint") {
		t.Fatal(again, err, e.run.Lines())
	}

	// Inventory lists it.
	inv, _ := e.svc.Inventory(ctx, InventoryPayload{}, nil)
	if s := inv.(InventoryResult).Snapshots; len(s) != 1 || s[0] != (InventorySnapshot{SnapshotID: snap, SizeBytes: 100}) {
		t.Fatalf("%+v", s)
	}

	// Restore: a new bundle (snapshot env + the create's), netns and address; runsc restore from the image.
	e.run.Reset()
	e.dock.pulled = nil
	cr, err := e.svc.Create(ctx, restorePayload(), nil)
	if err != nil {
		t.Fatal(err)
	}
	c := cr.(CreateResult)
	if c.Status != "running" || c.IP != "10.88.0.3" || c.ImageDigest != src.ImageDigest {
		t.Fatalf("%+v", c)
	}
	dir := e.svc.dir(sid2)
	want := strings.Join([]string{"sh -c", `log=$1; shift; exec "$@" </dev/null >>"$log" 2>&1`, "sh", dir + "/runsc.log", "runsc", "--root=" + e.svc.d.RunscRoot,
		"--overlay2=root:self,size=2048m", "restore", "--image-path=" + img, "--detach", "--bundle", dir + "/bundle", "falak-sbx-" + sid2}, " ")
	if !e.run.Ran(want) || !e.run.Ran("ip netns add falak-sbx-t3s2r1q0p9nb") || len(e.dock.pulled) != 0 || e.run.Ran("tar") {
		t.Fatal(e.run.Lines())
	}
	var spec ociSpec
	_ = json.Unmarshal(mustRead(t, filepath.Join(dir, "bundle", "config.json")), &spec)
	if !slices.Contains(spec.Process.Env, "FORK=1") || !slices.Contains(spec.Process.Env, "APP_ENV=fork") || slices.Contains(spec.Process.Env, "APP_ENV=sandbox") ||
		!slices.Contains(spec.Process.Env, "LANG=C.UTF-8") || spec.Root.Path != m.Rootfs || spec.Linux.CgroupsPath != cgroupPath(sid2) ||
		spec.Linux.Resources.Memory.Limit != 512<<20 || spec.Linux.Namespaces[1].Path != "/var/run/netns/falak-sbx-t3s2r1q0p9nb" {
		t.Fatalf("%+v", spec)
	}
	st, _ := e.svc.readState(sid2)
	if st.FromSnapshot != snap || st.StartedAt == "" || st.IP != "10.88.0.3" || strings.Contains(string(mustRead(t, filepath.Join(dir, "sandbox.json"))), "FORK") {
		t.Fatalf("%+v", st)
	}

	// Limits must equal the snapshot's; the snapshot must exist; no repo.
	for name, mod := range map[string]func(*CreatePayload){
		"cpus":        func(p *CreatePayload) { p.CPUs = 1 },
		"memory_mb":   func(p *CreatePayload) { p.MemoryMB = 1024 },
		"disk_mb":     func(p *CreatePayload) { p.DiskMB = 4096 },
		"network":     func(p *CreatePayload) { p.Network = NetNone },
		"allow_hosts": func(p *CreatePayload) { p.AllowHosts = []string{"example.com"} },
	} {
		p := restorePayload()
		p.ID = "01j9z8y7x6w5v4t3s2r1q0p9nc"
		mod(&p)
		if _, err := e.svc.Create(ctx, p, nil); err == nil || !strings.Contains(err.Error(), "must equal") || !strings.Contains(err.Error(), name) {
			t.Errorf("%s: %v", name, err)
		}
		if _, err := os.Stat(e.svc.dir(p.ID)); !os.IsNotExist(err) {
			t.Errorf("%s: state left", name)
		}
	}
	p := restorePayload()
	p.ID, p.FromSnapshot = "01j9z8y7x6w5v4t3s2r1q0p9nc", "01j9z8y7x6w5v4t3s2r1q0nope"
	if _, err := e.svc.Create(ctx, p, nil); err == nil || err.Error() != "snapshot 01j9z8y7x6w5v4t3s2r1q0nope not found" {
		t.Fatal(err)
	}
	p = restorePayload()
	p.Repo = &Repo{URL: "https://github.com/a/b.git"}
	if _, err := e.svc.Create(ctx, p, nil); err == nil || !strings.Contains(err.Error(), "repo") {
		t.Fatal(err)
	}

	// The image GC keeps a snapshot's base rootfs after its sandboxes are gone.
	for _, id := range []string{sid, sid2} {
		_, _ = e.svc.Kill(ctx, KillPayload{ID: id}, nil)
	}
	imgDir := filepath.Dir(m.Rootfs)
	old := e.now.Add(-7 * time.Hour)
	_ = os.Chtimes(imgDir, old, old)
	e.svc.ReapOnce(ctx)
	if _, err := os.Stat(imgDir); err != nil {
		t.Fatal("snapshot's base image removed", err)
	}
	// A restore after its source was killed still works.
	e.runscList(map[string]string{})
	if _, err := e.svc.Create(ctx, restorePayload(), nil); err != nil {
		t.Fatal(err)
	}
	_, _ = e.svc.Kill(ctx, KillPayload{ID: sid2}, nil)

	// Delete: gone, twice is fine; then the GC may take the image.
	for range 2 {
		if _, err := e.svc.SnapshotDelete(ctx, SnapshotDeletePayload{SnapshotID: snap}, nil); err != nil {
			t.Fatal(err)
		}
	}
	if _, err := os.Stat(e.svc.snapDir(snap)); !os.IsNotExist(err) {
		t.Fatal("snapshot left", err)
	}
	_ = os.Chtimes(imgDir, old, old)
	e.svc.ReapOnce(ctx)
	if _, err := os.Stat(imgDir); !os.IsNotExist(err) {
		t.Fatal("unused image kept", err)
	}
	if _, err := e.svc.SnapshotDelete(ctx, SnapshotDeletePayload{SnapshotID: "x"}, nil); err == nil {
		t.Fatal("invalid id accepted")
	}
}

func mustRead(t *testing.T, p string) []byte {
	t.Helper()
	b, err := os.ReadFile(p)
	if err != nil {
		t.Fatal(err)
	}
	return b
}

func TestSnapshotStopRemovesTheSandbox(t *testing.T) {
	e := newEnv(t)
	ctx := context.Background()
	e.running(t)
	e.checkpointWrites()
	e.run.Reset()
	leave := false
	if _, err := e.svc.Snapshot(ctx, SnapshotPayload{ID: sid, SnapshotID: snap, LeaveRunning: &leave}, nil); err != nil {
		t.Fatal(err)
	}
	if e.run.Ran("runsc --root="+e.svc.d.RunscRoot+" checkpoint --image-path="+e.svc.snapDir(snap)+".tmp/image --leave-running") ||
		!e.run.Ran("runsc --root="+e.svc.d.RunscRoot+" checkpoint --image-path="+e.svc.snapDir(snap)+".tmp/image falak-sbx-"+sid) ||
		!e.run.Ran("runsc --root="+e.svc.d.RunscRoot+" delete -force falak-sbx-"+sid) || !e.run.Ran("ip netns del falak-sbx-t3s2r1q0p9na") {
		t.Fatal(e.run.Lines())
	}
	if _, err := os.Stat(e.svc.dir(sid)); !os.IsNotExist(err) {
		t.Fatal("state dir left")
	}
	if m, _ := e.svc.readMeta(snap); m == nil {
		t.Fatal("no snapshot")
	}
	if ip, _ := e.svc.alloc.Allocate("other"); ip != "10.88.0.2" {
		t.Fatal("address not released", ip)
	}
}

func TestSnapshotErrors(t *testing.T) {
	e := newEnv(t)
	ctx := context.Background()
	if _, err := e.svc.Snapshot(ctx, SnapshotPayload{ID: sid, SnapshotID: snap}, nil); err == nil || err.Error() != "sandbox "+sid+" not found" {
		t.Fatal(err)
	}
	for _, p := range []SnapshotPayload{{ID: "x", SnapshotID: snap}, {ID: sid, SnapshotID: "X"}} {
		if _, err := e.svc.Snapshot(ctx, p, nil); err == nil {
			t.Errorf("%+v accepted", p)
		}
	}
	e.running(t)

	e.runscList(map[string]string{cid(sid): "paused"})
	if _, err := e.svc.Snapshot(ctx, SnapshotPayload{ID: sid, SnapshotID: snap}, nil); err == nil || !strings.Contains(err.Error(), "paused") {
		t.Fatal(err)
	}
	e.runscList(map[string]string{cid(sid): "running"})

	// The checkpoint takes about the sandbox's memory plus what it wrote: refused below the floor.
	e.setFree(5<<30 + 256<<20)
	if _, err := e.svc.Snapshot(ctx, SnapshotPayload{ID: sid, SnapshotID: snap}, nil); err == nil || !strings.Contains(err.Error(), "not enough disk space") {
		t.Fatal(err)
	}
	e.setFree(100 << 30)

	// A failed checkpoint leaves nothing.
	e.run.On("runsc --root="+e.svc.d.RunscRoot+" checkpoint", runner.Result{ExitCode: 1, Stderr: []byte("noise\ncheckpoint failed: boom\n")})
	if _, err := e.svc.Snapshot(ctx, SnapshotPayload{ID: sid, SnapshotID: snap}, nil); err == nil || !strings.Contains(err.Error(), "checkpoint failed: boom") {
		t.Fatal(err)
	}
	if ents, _ := os.ReadDir(e.svc.snapRoot()); len(ents) != 0 {
		t.Fatal("left", ents)
	}

	// A crashed snapshot's .tmp goes on the next reaper pass (unless a snapshot command is writing it).
	tmp := e.svc.snapDir(snap) + ".tmp"
	_ = os.MkdirAll(tmp, 0o700)
	mu := e.svc.lock(snap)
	mu.Lock()
	e.svc.ReapOnce(ctx)
	if _, err := os.Stat(tmp); err != nil {
		t.Fatal("removed while locked")
	}
	mu.Unlock()
	e.svc.ReapOnce(ctx)
	if _, err := os.Stat(tmp); !os.IsNotExist(err) {
		t.Fatal("tmp left")
	}
}

func TestPauseResume(t *testing.T) {
	e := newEnv(t)
	ctx := context.Background()
	if _, err := e.svc.Pause(ctx, IDPayload{ID: sid}, nil); err == nil || err.Error() != "sandbox "+sid+" not found" {
		t.Fatal(err)
	}
	e.running(t)
	e.run.Reset()
	res, err := e.svc.Pause(ctx, IDPayload{ID: sid}, nil)
	if err != nil || res.(StatusResult).Status != "paused" || !e.run.Ran("runsc --root="+e.svc.d.RunscRoot+" pause falak-sbx-"+sid) {
		t.Fatal(res, err, e.run.Lines())
	}
	e.runscList(map[string]string{cid(sid): "paused"})
	e.run.Reset()
	if res, err := e.svc.Pause(ctx, IDPayload{ID: sid}, nil); err != nil || res.(StatusResult).Status != "paused" || e.run.Ran("runsc --root="+e.svc.d.RunscRoot+" pause") {
		t.Fatal("pause again", res, err)
	}
	// Paused and not expired: the reaper leaves it alone; inventory says paused.
	e.svc.ReapOnce(ctx)
	if st, _ := e.svc.readState(sid); st == nil || st.Reaped != "" {
		t.Fatal("paused sandbox reaped")
	}
	inv, _ := e.svc.Inventory(ctx, InventoryPayload{}, nil)
	if it := inv.(InventoryResult).Sandboxes; it[0].Status != "paused" {
		t.Fatalf("%+v", it)
	}
	res, err = e.svc.Resume(ctx, IDPayload{ID: sid}, nil)
	if err != nil || res.(StatusResult).Status != "running" || !e.run.Ran("runsc --root="+e.svc.d.RunscRoot+" resume falak-sbx-"+sid) {
		t.Fatal(res, err, e.run.Lines())
	}
	e.runscList(map[string]string{cid(sid): "running"})
	e.run.Reset()
	if res, err := e.svc.Resume(ctx, IDPayload{ID: sid}, nil); err != nil || res.(StatusResult).Status != "running" || e.run.Ran("runsc --root="+e.svc.d.RunscRoot+" resume") {
		t.Fatal("resume again", res, err)
	}
	e.runscList(map[string]string{cid(sid): "stopped"})
	if _, err := e.svc.Pause(ctx, IDPayload{ID: sid}, nil); err == nil || !strings.Contains(err.Error(), "not running") {
		t.Fatal(err)
	}
	// Paused past expires_at: reaped.
	e.runscList(map[string]string{cid(sid): "paused"})
	e.now = time.Unix(1760003600, 0)
	e.svc.ReapOnce(ctx)
	if len(e.reqs.got) != 1 || e.reqs.got[0]["reason"] != "expired" {
		t.Fatalf("%+v", e.reqs.got)
	}
}

// TestInitSpawnsBackgroundJobs runs the init script under the host's sh (paths moved into a temporary directory):
// falak-bg hands a job to init, which runs it with the caller's env and working directory.
func TestInitSpawnsBackgroundJobs(t *testing.T) {
	if _, err := exec.LookPath("mkfifo"); err != nil {
		t.Skip("no mkfifo")
	}
	tmp := t.TempDir()
	script := strings.NewReplacer("/run/falak", tmp+"/run", "/usr/local/bin", tmp+"/bin").Replace(initScript)
	initCmd := exec.Command("sh", "-c", script)
	if err := initCmd.Start(); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = initCmd.Process.Kill(); _ = initCmd.Wait() })
	bg := filepath.Join(tmp, "bin", "falak-bg")
	for i := 0; i < 100; i++ {
		if _, err := os.Stat(bg); err == nil {
			break
		}
		time.Sleep(20 * time.Millisecond)
	}
	work := filepath.Join(tmp, "it's here")
	_ = os.MkdirAll(work, 0o755)
	work, _ = filepath.EvalSymlinks(work)
	_ = os.MkdirAll(work, 0o755)
	body, _ := os.ReadFile(bg)
	cmd := exec.Command("sh", "-c", strings.ReplaceAll(string(body), "/run/falak", tmp+"/run"), "falak-bg", `echo "$JOB_VAR $(pwd)" > out; echo done > done`)
	cmd.Dir, cmd.Env = work, append(os.Environ(), "JOB_VAR=it's set")
	if out, err := cmd.CombinedOutput(); err != nil {
		t.Fatal(err, string(out))
	}
	for i := 0; i < 500; i++ { // up to 10 s: the job runs in the background, slow under a loaded -race run
		if _, err := os.Stat(filepath.Join(work, "done")); err == nil {
			break
		}
		time.Sleep(20 * time.Millisecond)
	}
	if b, _ := os.ReadFile(filepath.Join(work, "out")); string(b) != "it's set "+work+"\n" {
		t.Fatalf("%q", b)
	}
	if jobs, _ := filepath.Glob(filepath.Join(tmp, "run", "job.*")); len(jobs) != 0 {
		t.Fatal("job script left", jobs)
	}
}

type fakeDumper struct {
	data string
	err  error
	got  []string
}

func (f *fakeDumper) Dump(_ context.Context, instance, engine, database string, w io.Writer) error {
	f.got = append(f.got, instance+" "+engine+" "+database)
	if _, err := io.WriteString(w, f.data); err != nil {
		return err
	}
	return f.err
}

func TestDBImport(t *testing.T) {
	e := newEnv(t)
	ctx := context.Background()
	dump := &fakeDumper{data: "PGDMP" + strings.Repeat("x", 70000)}
	e.svc.d.DB = dump
	p := DBImportPayload{ID: sid, Instance: "01hzyinst00000000000000001", Engine: "postgres", Database: "shop", Path: "/workspace/db/shop.dump"}
	if _, err := e.svc.DBImport(ctx, p, nil); err == nil || err.Error() != "sandbox "+sid+" not found" {
		t.Fatal(err)
	}
	e.running(t)
	var stdin string
	var procs [][]string
	e.run.OnFunc("runsc --root="+e.svc.d.RunscRoot+" exec", func(c runnertest.Call) (runner.Result, error) {
		proc := procOf(c)
		procs = append(procs, proc.Args)
		if proc.Args[0] == "sh" {
			stdin = c.Stdin
		}
		return runner.Result{}, nil
	})
	res, err := e.svc.DBImport(ctx, p, nil)
	if err != nil {
		t.Fatal(err)
	}
	if r := res.(DBImportResult); r.Path != p.Path || r.SizeBytes != int64(len(dump.data)) || stdin != dump.data {
		t.Fatalf("%+v stdin=%d", r, len(stdin))
	}
	if len(procs) != 1 || strings.Join(procs[0], "|") != "sh|-c|"+importScript+"|_|/workspace/db/shop.dump" ||
		strings.Join(dump.got, ",") != "01hzyinst00000000000000001 postgres shop" {
		t.Fatalf("%q %q", procs, dump.got)
	}

	// A failed dump removes the partial file.
	procs = nil
	dump.err = errors.New("falak-db backup logical: exit 1: database \"shop\" does not exist")
	if _, err := e.svc.DBImport(ctx, p, nil); err == nil || !strings.Contains(err.Error(), "does not exist") {
		t.Fatal(err)
	}
	if len(procs) != 2 || strings.Join(procs[1], " ") != "rm -f -- /workspace/db/shop.dump" {
		t.Fatalf("%q", procs)
	}
	// So does a failed write in the sandbox.
	dump.err, procs = nil, nil
	e.run = &runnertest.Fake{} // a fresh fake: the first matching rule wins
	e.svc.d.Runner = e.run
	e.run.OnFunc("runsc --root="+e.svc.d.RunscRoot+" exec", func(c runnertest.Call) (runner.Result, error) {
		procs = append(procs, procOf(c).Args)
		return runner.Result{ExitCode: 1, Stderr: []byte("cat: write error: No space left on device")}, nil
	})
	if _, err := e.svc.DBImport(ctx, p, nil); err == nil || !strings.Contains(err.Error(), "No space left") {
		t.Fatal(err)
	}
	if len(procs) != 2 || procs[1][0] != "rm" {
		t.Fatalf("%q", procs)
	}

	for _, bad := range []DBImportPayload{
		{ID: sid, Instance: p.Instance, Engine: "redis", Database: "shop", Path: "/a"},
		{ID: sid, Instance: p.Instance, Engine: "postgres", Database: "shop", Path: "rel"},
		{ID: "x", Instance: p.Instance, Engine: "postgres", Database: "shop", Path: "/a"},
	} {
		if _, err := e.svc.DBImport(ctx, bad, nil); err == nil {
			t.Errorf("%+v accepted", bad)
		}
	}
	e.svc.d.DB = nil
	if _, err := e.svc.DBImport(ctx, p, nil); err == nil {
		t.Fatal("no dumper accepted")
	}
}

// A checkpoint being taken reserves the sandbox's memory: a create (or another snapshot) at the same time can't pass
// the disk floor check against the same free space; once it is written the reservation goes.
func TestSnapshotReservesDiskWhileCheckpointing(t *testing.T) {
	e := newEnv(t)
	ctx := context.Background()
	e.running(t)
	started, finish := make(chan struct{}), make(chan struct{})
	e.run.OnFunc("runsc --root="+e.svc.d.RunscRoot+" checkpoint", func(runnertest.Call) (runner.Result, error) {
		close(started)
		<-finish
		return runner.Result{}, nil
	})
	done := make(chan error, 1)
	go func() {
		_, err := e.svc.Snapshot(ctx, SnapshotPayload{ID: sid, SnapshotID: snap}, nil)
		done <- err
	}()
	<-started
	// 5 GiB floor + 2.25 GiB: enough for sid2's 2 GiB disk alone, not with the checkpoint's 512 MiB reserved.
	e.setFree(5<<30 + 2304<<20)
	p := createPayload(NetNone)
	p.ID = sid2
	if _, err := e.svc.Create(ctx, p, nil); err == nil || !strings.Contains(err.Error(), "0.5 GiB reserved for snapshots being taken") {
		t.Fatal(err)
	}
	close(finish)
	if err := <-done; err != nil {
		t.Fatal(err)
	}
	if _, err := e.svc.Create(ctx, p, nil); err != nil {
		t.Fatal(err)
	}
}

// Pause refuses while an exec or a database import runs in the sandbox (the import holds it until its cleanup ran),
// and an import into a paused sandbox is refused.
func TestPauseRefusedWhileBusy(t *testing.T) {
	e := newEnv(t)
	ctx := context.Background()
	e.running(t)
	started, finish := make(chan struct{}), make(chan struct{})
	e.svc.d.DB = &fakeDumper{data: "x"}
	e.run.OnFunc("runsc --root="+e.svc.d.RunscRoot+" exec", func(runnertest.Call) (runner.Result, error) {
		close(started)
		<-finish
		return runner.Result{}, nil
	})
	p := DBImportPayload{ID: sid, Instance: "01hzyinst00000000000000001", Engine: "postgres", Database: "shop", Path: "/workspace/db/shop.dump"}
	done := make(chan error, 1)
	go func() {
		_, err := e.svc.DBImport(ctx, p, nil)
		done <- err
	}()
	<-started
	if _, err := e.svc.Pause(ctx, IDPayload{ID: sid}, nil); err == nil || !strings.HasPrefix(err.Error(), "busy: a command is running") || e.run.Ran("runsc --root="+e.svc.d.RunscRoot+" pause") {
		t.Fatal(err)
	}
	close(finish)
	if err := <-done; err != nil {
		t.Fatal(err)
	}
	if _, err := e.svc.Pause(ctx, IDPayload{ID: sid}, nil); err != nil {
		t.Fatal(err)
	}
	e.runscList(map[string]string{cid(sid): "paused"})
	if _, err := e.svc.DBImport(ctx, p, nil); err == nil || !strings.Contains(err.Error(), "is paused") {
		t.Fatal(err)
	}
}

// stallRunner is a sandbox that never reads its stdin: runsc exec with stdin blocks until cancelled.
type stallRunner struct{ runner.Runner }

func (r stallRunner) Run(ctx context.Context, c runner.Cmd) (runner.Result, error) {
	if c.Stdin != nil && slices.Contains(c.Args, "exec") {
		<-ctx.Done()
		return runner.Result{ExitCode: -1}, ctx.Err()
	}
	return r.Runner.Run(ctx, c)
}

// A sandbox that stops reading the dump aborts the import after importStall (the dump ends, freeing the instance's
// lock), well before the overall timeout.
func TestDBImportAbortsWhenTheSandboxStopsReading(t *testing.T) {
	old := importStall
	importStall = 100 * time.Millisecond
	t.Cleanup(func() { importStall = old })
	e := newEnv(t)
	e.running(t)
	dump := &fakeDumper{data: "PGDMP"}
	e.svc.d.DB = dump
	e.svc.d.Runner = stallRunner{e.run}
	start := time.Now()
	_, err := e.svc.DBImport(context.Background(), DBImportPayload{ID: sid, Instance: "01hzyinst00000000000000001", Engine: "postgres", Database: "shop", Path: "/workspace/db/shop.dump"}, nil)
	if err == nil || !strings.Contains(err.Error(), "read nothing of the dump") || time.Since(start) > 5*time.Second {
		t.Fatal(err, time.Since(start))
	}
	if !e.run.Ran("runsc --root=" + e.svc.d.RunscRoot + " exec") { // the cleanup's rm
		t.Fatal(e.run.Lines())
	}
}
