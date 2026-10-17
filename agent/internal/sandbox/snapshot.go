package sandbox

// v0.21 (feature sandbox.v2): snapshots (runsc checkpoint), restore and fork (runsc restore into a new bundle, netns
// and cgroup), pause/resume, and a database's dump streamed into a sandbox.
//
// A snapshot lives under Root/snapshots/<snapshot id> (root-only): image/ is runsc's checkpoint image (memory, the
// processes and everything the sandbox wrote: its overlay's filestore is saved into pages.img, so a snapshot doesn't
// need its sandbox any more) and meta.json is what a restore needs (base rootfs, limits, network, env). It outlives
// its sandbox and agent restarts; only sandbox.snapshot.delete removes it, and the image GC keeps its base rootfs.

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"sync"
	"sync/atomic"
	"syscall"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// Dumper streams a database's logical dump (*db.DB): sandbox.db.import.
type Dumper interface {
	Dump(ctx context.Context, instance, engine, database string, w io.Writer) error
}

// snapMeta is a snapshot's meta.json. Env holds the sandbox's env values (secrets): the file is 0600 in a root-only
// directory, like the bundle's config.json it was copied from.
type snapMeta struct {
	SnapshotID  string   `json:"snapshot_id"`
	SandboxID   string   `json:"sandbox_id"`
	Image       string   `json:"image"`
	ImageDigest string   `json:"image_digest"`
	Rootfs      string   `json:"rootfs"`
	CPUs        float64  `json:"cpus"`
	MemoryMB    int      `json:"memory_mb"`
	DiskMB      int      `json:"disk_mb"`
	Pids        int      `json:"pids"`
	Network     string   `json:"network"`
	AllowHosts  []string `json:"allow_hosts,omitempty"`
	Env         []string `json:"env"`
	SizeBytes   int64    `json:"size_bytes"`
	CreatedAt   string   `json:"created_at"`
}

func checkSnapshotID(id string) error {
	if !idRe.MatchString(id) {
		return payloadErr("invalid snapshot id %q", id)
	}
	return nil
}

func (s *Service) snapRoot() string           { return filepath.Join(s.d.Root, "snapshots") }
func (s *Service) snapDir(id string) string   { return filepath.Join(s.snapRoot(), id) }
func (s *Service) snapImage(id string) string { return filepath.Join(s.snapDir(id), "image") }

// readMeta returns a snapshot's meta (nil when there is no such snapshot).
func (s *Service) readMeta(id string) (*snapMeta, error) {
	b, err := os.ReadFile(filepath.Join(s.snapDir(id), "meta.json"))
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	var m snapMeta
	return &m, json.Unmarshal(b, &m)
}

// snapshots lists the snapshots on this server (directories with a meta.json; a half-made one has none).
func (s *Service) snapshots() []*snapMeta {
	ents, _ := os.ReadDir(s.snapRoot())
	var out []*snapMeta
	for _, e := range ents {
		if !e.IsDir() || !idRe.MatchString(e.Name()) {
			continue
		}
		if m, err := s.readMeta(e.Name()); err == nil && m != nil {
			out = append(out, m)
		}
	}
	return out
}

// dirSize is the bytes of the regular files under dir.
func dirSize(dir string) int64 {
	var n int64
	_ = filepath.WalkDir(dir, func(_ string, d os.DirEntry, err error) error {
		if err == nil && d.Type().IsRegular() {
			if fi, err := d.Info(); err == nil {
				n += fi.Size()
			}
		}
		return nil
	})
	return n
}

// filestoreBytes is the disk the sandbox's writes take (its overlay filestore is sparse: allocated blocks count).
func filestoreBytes(st *state) uint64 {
	files, _ := filepath.Glob(filepath.Join(st.Rootfs, ".gvisor.filestore."+cid(st.ID)+"*"))
	var n uint64
	for _, f := range files {
		if fi, err := os.Stat(f); err == nil {
			if sys, ok := fi.Sys().(*syscall.Stat_t); ok {
				n += uint64(sys.Blocks) * 512
			} else {
				n += uint64(fi.Size())
			}
		}
	}
	return n
}

// ---- sandbox.snapshot

// SnapshotPayload is sandbox.snapshot's payload.
type SnapshotPayload struct {
	ID           string `json:"id"`
	SnapshotID   string `json:"snapshot_id"`
	LeaveRunning *bool  `json:"leave_running,omitempty"`
}

// SnapshotResult is sandbox.snapshot's result.
type SnapshotResult struct {
	SnapshotID  string `json:"snapshot_id"`
	SizeBytes   int64  `json:"size_bytes"`
	ImageDigest string `json:"image_digest"`
	CreatedAt   string `json:"created_at"`
}

// Snapshot checkpoints a sandbox (memory, processes, written files) into a new snapshot: idempotent by snapshot_id.
// With leave_running false the sandbox is removed after the checkpoint, as by a kill.
func (s *Service) Snapshot(ctx context.Context, p SnapshotPayload, _ commands.Stream) (any, error) {
	if err := checkID(p.ID); err != nil {
		return nil, err
	}
	if err := checkSnapshotID(p.SnapshotID); err != nil {
		return nil, err
	}
	leave := p.LeaveRunning == nil || *p.LeaveRunning
	mu := s.lock(p.ID)
	mu.Lock()
	defer mu.Unlock()
	smu := s.lock(p.SnapshotID)
	smu.Lock()
	defer smu.Unlock()
	if m, err := s.readMeta(p.SnapshotID); err != nil {
		return nil, err
	} else if m != nil {
		return SnapshotResult{SnapshotID: m.SnapshotID, SizeBytes: m.SizeBytes, ImageDigest: m.ImageDigest, CreatedAt: m.CreatedAt}, nil
	}
	st, err := s.readState(p.ID)
	if err != nil {
		return nil, err
	}
	if st == nil || st.Reaped != "" || st.StartedAt == "" {
		return nil, fmt.Errorf("sandbox %s not found", p.ID)
	}
	switch status, known := s.status(ctx, p.ID); {
	case !known:
		return nil, fmt.Errorf("sandbox %s: runsc can't report its status; try again", p.ID)
	case status == "paused":
		return nil, fmt.Errorf("sandbox %s is paused: resume it first", p.ID)
	case status != "running":
		return nil, fmt.Errorf("sandbox %s is not running (%s)", p.ID, orUnknown(status))
	}
	var spec ociSpec
	b, err := os.ReadFile(filepath.Join(s.dir(p.ID), "bundle", "config.json"))
	if err != nil {
		return nil, err
	}
	if err := json.Unmarshal(b, &spec); err != nil {
		return nil, err
	}
	// The checkpoint holds the sandbox's memory and what it wrote: reserved until it is written.
	release, err := s.reserveDisk(uint64(st.MemoryMB)<<20+filestoreBytes(st), "a snapshot of sandbox "+p.ID)
	if err != nil {
		return nil, err
	}
	defer release()
	if err := s.ensureDirs(); err != nil {
		return nil, err
	}
	final := s.snapDir(p.SnapshotID)
	tmp := final + ".tmp"
	_ = os.RemoveAll(tmp)
	if err := os.MkdirAll(filepath.Join(tmp, "image"), 0o700); err != nil {
		return nil, err
	}
	ok := false
	defer func() {
		if !ok {
			_ = os.RemoveAll(tmp)
		}
	}()
	args := []string{"checkpoint", "--image-path=" + filepath.Join(tmp, "image")}
	if leave {
		args = append(args, "--leave-running")
	}
	cctx, cancel := context.WithTimeout(ctx, 10*time.Minute)
	res, err := s.d.Runner.Run(cctx, runner.Cmd{Name: "runsc", Args: s.runscArgs(append(args, cid(p.ID))...)})
	cancel()
	if err == nil && res.ExitCode != 0 {
		err = fmt.Errorf("exit status %d: %s", res.ExitCode, lastLine(string(res.Stderr)))
	}
	if err != nil {
		return nil, fmt.Errorf("runsc checkpoint: %w", err)
	}
	m := snapMeta{SnapshotID: p.SnapshotID, SandboxID: p.ID, Image: st.Image, ImageDigest: st.ImageDigest, Rootfs: st.Rootfs,
		CPUs: st.CPUs, MemoryMB: st.MemoryMB, DiskMB: st.DiskMB, Pids: st.Pids, Network: st.Network, AllowHosts: st.AllowHosts,
		Env: spec.Process.Env, SizeBytes: dirSize(tmp), CreatedAt: s.d.Now().UTC().Format(time.RFC3339)}
	mb, _ := json.MarshalIndent(m, "", "  ")
	if err := os.WriteFile(filepath.Join(tmp, "meta.json"), mb, 0o600); err != nil {
		return nil, err
	}
	_ = os.RemoveAll(final)
	if err := os.Rename(tmp, final); err != nil {
		return nil, err
	}
	ok = true
	if !leave {
		s.teardown(ctx, st)
		if err := os.RemoveAll(s.dir(p.ID)); err != nil {
			return nil, err
		}
	}
	return SnapshotResult{SnapshotID: m.SnapshotID, SizeBytes: m.SizeBytes, ImageDigest: m.ImageDigest, CreatedAt: m.CreatedAt}, nil
}

func orUnknown(status string) string {
	if status == "" {
		return "unknown to runsc"
	}
	return status
}

func lastLine(s string) string {
	s = strings.TrimSpace(s)
	if i := strings.LastIndexByte(s, '\n'); i >= 0 {
		s = s[i+1:]
	}
	return s
}

// ---- sandbox.snapshot.delete

// SnapshotDeletePayload is sandbox.snapshot.delete's payload.
type SnapshotDeletePayload struct {
	SnapshotID string `json:"snapshot_id"`
}

// SnapshotDelete removes a snapshot (an unknown one succeeds). Sandboxes restored from it don't need it any more.
func (s *Service) SnapshotDelete(_ context.Context, p SnapshotDeletePayload, _ commands.Stream) (any, error) {
	if err := checkSnapshotID(p.SnapshotID); err != nil {
		return nil, err
	}
	mu := s.lock(p.SnapshotID)
	mu.Lock()
	defer mu.Unlock()
	for _, d := range []string{s.snapDir(p.SnapshotID), s.snapDir(p.SnapshotID) + ".tmp"} {
		if err := os.RemoveAll(d); err != nil {
			return nil, err
		}
	}
	return struct{}{}, nil
}

// gcSnapshotTemps removes what a crashed snapshot left behind (a .tmp directory no snapshot command is writing).
func (s *Service) gcSnapshotTemps() {
	tmps, _ := filepath.Glob(filepath.Join(s.snapRoot(), "*.tmp"))
	for _, t := range tmps {
		id := strings.TrimSuffix(filepath.Base(t), ".tmp")
		if !idRe.MatchString(id) {
			continue
		}
		mu := s.lock(id)
		if mu.TryLock() {
			_ = os.RemoveAll(t)
			mu.Unlock()
		}
	}
}

// ---- sandbox.create from_snapshot

// restore makes sandbox p from snapshot p.FromSnapshot: a new bundle (the snapshot's env with p.Env on top), netns,
// address and cgroup, then `runsc restore`. Its processes continue where they were. Callers hold p.ID's lock.
func (s *Service) restore(ctx context.Context, p CreatePayload) (CreateResult, error) {
	smu := s.lock(p.FromSnapshot)
	smu.Lock()
	defer smu.Unlock()
	m, err := s.readMeta(p.FromSnapshot)
	if err != nil {
		return CreateResult{}, err
	}
	if m == nil {
		return CreateResult{}, fmt.Errorf("snapshot %s not found", p.FromSnapshot)
	}
	// A checkpoint restores into the machine it was taken on: same CPUs, memory, disk and network.
	var diff []string
	if p.CPUs != m.CPUs {
		diff = append(diff, fmt.Sprintf("cpus %g (snapshot: %g)", p.CPUs, m.CPUs))
	}
	if p.MemoryMB != m.MemoryMB {
		diff = append(diff, fmt.Sprintf("memory_mb %d (snapshot: %d)", p.MemoryMB, m.MemoryMB))
	}
	if p.DiskMB != m.DiskMB {
		diff = append(diff, fmt.Sprintf("disk_mb %d (snapshot: %d)", p.DiskMB, m.DiskMB))
	}
	if p.Network != m.Network {
		diff = append(diff, fmt.Sprintf("network %s (snapshot: %s)", p.Network, m.Network))
	}
	if !sameHosts(p.AllowHosts, m.AllowHosts) {
		diff = append(diff, fmt.Sprintf("allow_hosts %v (snapshot: %v)", p.AllowHosts, m.AllowHosts))
	}
	if len(diff) > 0 {
		return CreateResult{}, payloadErr("limits must equal snapshot %s's: %s", p.FromSnapshot, strings.Join(diff, ", "))
	}
	hex := filepath.Base(filepath.Dir(m.Rootfs))
	imu, _ := s.imgLocks.LoadOrStore(hex, &sync.Mutex{})
	im := imu.(*sync.Mutex)
	im.Lock()
	_, statErr := os.Stat(m.Rootfs)
	if statErr == nil {
		now := s.d.Now()
		_ = os.Chtimes(filepath.Dir(m.Rootfs), now, now) // last used, for the image GC
	}
	im.Unlock()
	if statErr != nil {
		return CreateResult{}, fmt.Errorf("snapshot %s: its base image %s is gone: %w", p.FromSnapshot, m.ImageDigest, statErr)
	}
	if err := s.checkDisk(uint64(p.DiskMB)<<20, "the sandbox's disk"); err != nil {
		return CreateResult{}, err
	}
	dir := s.dir(p.ID)
	if err := os.MkdirAll(filepath.Join(dir, "bundle"), 0o700); err != nil {
		return CreateResult{}, err
	}
	sb := &state{ID: p.ID, Image: m.Image, ImageDigest: m.ImageDigest, Rootfs: m.Rootfs, CPUs: m.CPUs, MemoryMB: m.MemoryMB,
		DiskMB: m.DiskMB, Pids: m.Pids, Network: m.Network, AllowHosts: m.AllowHosts, ExpiresAt: p.ExpiresAt, FromSnapshot: m.SnapshotID}
	if m.Network != NetNone {
		s.netMu.Lock()
		sb.IP, err = s.alloc.Allocate(p.ID)
		s.netMu.Unlock()
		if err != nil {
			return CreateResult{}, err
		}
	}
	if err := s.writeState(sb); err != nil {
		return CreateResult{}, err
	}
	env := slices.Clone(m.Env)
	for _, k := range sortedKeys(p.Env) {
		env = append(env, k+"="+p.Env[k])
	}
	if err := s.writeBundle(sb, env); err != nil {
		return CreateResult{}, err
	}
	if err := s.setupNetwork(ctx, sb); err != nil {
		return CreateResult{}, err
	}
	if err := s.start(ctx, p.ID, m.DiskMB, m.Network, "restore", "--image-path="+s.snapImage(m.SnapshotID)); err != nil {
		return CreateResult{}, err
	}
	sb.StartedAt = s.d.Now().UTC().Format(time.RFC3339)
	if err := s.writeState(sb); err != nil {
		return CreateResult{}, err
	}
	return CreateResult{Status: "running", IP: sb.IP, ImageDigest: sb.ImageDigest, StartedAt: sb.StartedAt}, nil
}

func sameHosts(a, b []string) bool {
	a, b = slices.Clone(a), slices.Clone(b)
	slices.Sort(a)
	slices.Sort(b)
	return slices.Equal(a, b)
}

// ---- sandbox.pause / sandbox.resume

// IDPayload is sandbox.pause's and sandbox.resume's payload.
type IDPayload struct {
	ID string `json:"id"`
}

// StatusResult is sandbox.pause's and sandbox.resume's result.
type StatusResult struct {
	Status string `json:"status"`
}

// Pause freezes a sandbox (runsc pause: no CPU; its memory stays). Idempotent.
func (s *Service) Pause(ctx context.Context, p IDPayload, _ commands.Stream) (any, error) {
	return s.setPaused(ctx, p.ID, true)
}

// Resume unfreezes a paused sandbox. Idempotent.
func (s *Service) Resume(ctx context.Context, p IDPayload, _ commands.Stream) (any, error) {
	return s.setPaused(ctx, p.ID, false)
}

func (s *Service) setPaused(ctx context.Context, id string, pause bool) (any, error) {
	if err := checkID(id); err != nil {
		return nil, err
	}
	mu := s.lock(id)
	mu.Lock()
	defer mu.Unlock()
	st, err := s.readState(id)
	if err != nil {
		return nil, err
	}
	if st == nil || st.Reaped != "" || st.StartedAt == "" {
		return nil, fmt.Errorf("sandbox %s not found", id)
	}
	from, to, verb := "running", "paused", "pause"
	if !pause {
		from, to, verb = "paused", "running", "resume"
	}
	status, known := s.status(ctx, id)
	switch {
	case !known:
		return nil, fmt.Errorf("sandbox %s: runsc can't report its status; try again", id)
	case status == to:
		return StatusResult{Status: to}, nil
	case status != from:
		return nil, fmt.Errorf("sandbox %s is not running (%s)", id, orUnknown(status))
	}
	if pause {
		// An exec or a database import in flight: refused, the control plane's idle pause tries again later.
		b := s.busy(id)
		if !b.TryLock() {
			return nil, fmt.Errorf("busy: a command is running in sandbox %s", id)
		}
		defer b.Unlock()
	}
	if _, err := runner.Check(ctx, s.d.Runner, runner.Cmd{Name: "runsc", Args: s.runscArgs(verb, cid(id))}); err != nil {
		return nil, fmt.Errorf("runsc %s: %w", verb, err)
	}
	return StatusResult{Status: to}, nil
}

// ---- sandbox.db.import

// DBImportPayload is sandbox.db.import's payload.
type DBImportPayload struct {
	ID       string `json:"id"`
	Instance string `json:"instance"`
	Engine   string `json:"engine"`
	Database string `json:"database"`
	Path     string `json:"path"`
}

// DBImportResult is sandbox.db.import's result.
type DBImportResult struct {
	Path      string `json:"path"`
	SizeBytes int64  `json:"size_bytes"`
}

// importScript writes stdin to $1 inside the sandbox, making its directory.
const importScript = `mkdir -p "$(dirname "$1")" && cat > "$1"`

// importTimeout bounds a database import (the control plane waits 10 minutes).
var importTimeout = 10 * time.Minute

type countWriter struct {
	w io.Writer
	n atomic.Int64
}

func (c *countWriter) Write(p []byte) (int, error) {
	n, err := c.w.Write(p)
	c.n.Add(int64(n))
	return n, err
}

// DBImport streams an instance's logical dump of one database (unencrypted, as db.backup makes it) into a file inside
// the sandbox: docker exec's stdout is piped into runsc exec's stdin, so the dump never touches the host's disk. A
// failed dump or write leaves no partial file behind.
func (s *Service) DBImport(ctx context.Context, p DBImportPayload, _ commands.Stream) (any, error) {
	if err := checkID(p.ID); err != nil {
		return nil, err
	}
	if err := checkPath(p.Path); err != nil {
		return nil, err
	}
	switch p.Engine {
	case "postgres", "mysql", "mariadb":
	default:
		return nil, payloadErr("engine must be postgres, mysql or mariadb")
	}
	if s.d.DB == nil {
		return nil, errors.New("database imports are not available on this agent")
	}
	if st, err := s.readState(p.ID); err != nil {
		return nil, err
	} else if st == nil || st.Reaped != "" || st.StartedAt == "" {
		return nil, fmt.Errorf("sandbox %s not found", p.ID)
	}
	// Held until the cleanup ran: the sandbox can't be paused under the import (or its cleanup), and one paused already
	// is refused instead of hanging.
	b := s.busy(p.ID)
	b.RLock()
	defer b.RUnlock()
	if status, known := s.status(ctx, p.ID); known && status == "paused" {
		return nil, fmt.Errorf("sandbox %s is paused: resume it first", p.ID)
	}
	ictx, cancel := context.WithTimeout(ctx, importTimeout)
	defer cancel()
	pr, pw := io.Pipe()
	cw := &countWriter{w: pw}
	dumped := make(chan error, 1)
	go func() {
		err := s.d.DB.Dump(ictx, p.Instance, p.Engine, p.Database, cw)
		_ = pw.CloseWithError(err) // nil: the sandbox side sees EOF
		dumped <- err
	}()
	o, err := s.execIn(ictx, p.ID, execReq{argv: []string{"sh", "-c", importScript, "_", p.Path}, in: pr, timeout: importTimeout, max: 64 << 10})
	_ = pr.CloseWithError(errors.New("the sandbox stopped reading")) // a dump still writing fails instead of blocking
	if err != nil || o.timedOut || o.code != 0 {
		cancel()
	}
	dumpErr := <-dumped
	switch {
	case err != nil:
	case o.timedOut:
		err = errors.New("timed out")
	case o.code != 0:
		err = fmt.Errorf("exit status %d: %s", o.code, strings.TrimSpace(string(o.stderr)))
	}
	if err != nil || dumpErr != nil {
		rctx, rcancel := context.WithTimeout(context.WithoutCancel(ctx), 30*time.Second)
		_, _ = s.execIn(rctx, p.ID, execReq{argv: []string{"rm", "-f", "--", p.Path}, timeout: 30 * time.Second, max: 4096})
		rcancel()
		if dumpErr != nil {
			var pe *commands.PayloadError
			if errors.As(dumpErr, &pe) {
				return nil, dumpErr
			}
			return nil, fmt.Errorf("database import: %w", dumpErr)
		}
		return nil, fmt.Errorf("database import: write %s in the sandbox: %w", p.Path, err)
	}
	return DBImportResult{Path: p.Path, SizeBytes: cw.n.Load()}, nil
}
