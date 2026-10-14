package logs

import (
	"context"
	"log/slog"
	"os"
	"path/filepath"
	"strings"
	"syscall"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/docker"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
)

// pollWithin fails the test when one poll takes longer than d (an open or read that blocked).
func pollWithin(t *testing.T, tl *Tailer, d time.Duration) {
	t.Helper()
	done := make(chan struct{})
	go func() { tl.Poll(); close(done) }()
	select {
	case <-done:
	case <-time.After(d):
		t.Fatal("poll blocked")
	}
}

// A FIFO swapped in for a log file between stat and open is opened without blocking and refused.
func TestCheckOpenedRefusesAFIFOSwappedIn(t *testing.T) {
	dir := t.TempDir()
	regular := filepath.Join(dir, "app.log")
	mkfile(t, regular, "x\n")
	want, _ := os.Stat(regular)
	fifo := filepath.Join(dir, "fifo.log")
	if err := syscall.Mkfifo(fifo, 0o644); err != nil {
		t.Skip("mkfifo:", err)
	}
	done := make(chan error, 1)
	go func() {
		f, err := os.OpenFile(fifo, openFlags, 0)
		if err == nil {
			err = checkOpened(f, want)
			f.Close()
		}
		done <- err
	}()
	select {
	case err := <-done:
		if err == nil {
			t.Fatal("a FIFO was accepted")
		}
	case <-time.After(2 * time.Second):
		t.Fatal("opening a FIFO blocked")
	}
	f, _ := os.OpenFile(regular, openFlags, 0)
	defer f.Close()
	other := filepath.Join(dir, "other.log")
	mkfile(t, other, "y\n")
	otherFi, _ := os.Stat(other)
	if err := checkOpened(f, otherFi); err != errReplaced {
		t.Fatalf("a different file than stat saw: %v", err)
	}
	if err := checkOpened(f, want); err != nil {
		t.Fatalf("the regular file: %v", err)
	}
}

// A log path that is a FIFO (on the host or in a container) never blocks the tailer.
func TestTailerSkipsFIFOs(t *testing.T) {
	fs := hostfs.FS{Root: t.TempDir()}
	os.MkdirAll(fs.P("/srv/logs"), 0o755)
	if err := syscall.Mkfifo(fs.P("/srv/logs/app.log"), 0o644); err != nil {
		t.Skip("mkfifo:", err)
	}
	sink := &memSink{}
	tl := NewTailer(fs, "/var/lib/falak", sink, nil)
	tl.SetSources([]Source{{Path: "/srv/logs/*.log", Site: "shop"}})
	pollWithin(t, tl, 2*time.Second)
	if len(tl.files) != 0 || len(sink.recs) != 0 {
		t.Fatalf("FIFO opened: %v", tl.files)
	}
}

// Paths under /proc, /sys and /dev of a container are never read, typed or reached through a link.
func TestGlobInRefusesPseudoPaths(t *testing.T) {
	rootDir := t.TempDir()
	mkfile(t, filepath.Join(rootDir, "proc/kmsg"), "kernel\n")
	mkfile(t, filepath.Join(rootDir, "sys/kernel/x"), "kernel\n")
	mkfile(t, filepath.Join(rootDir, "app/ok.log"), "ok\n")
	symlink(t, "/proc/kmsg", filepath.Join(rootDir, "app/kmsg.log"))
	symlink(t, "../../sys/kernel/x", filepath.Join(rootDir, "app/sys.log"))
	symlink(t, "/proc", filepath.Join(rootDir, "app/procdir"))
	root, err := os.OpenRoot(rootDir)
	if err != nil {
		t.Fatal(err)
	}
	defer root.Close()
	for _, pattern := range []string{"/proc/kmsg", "/proc/*", "/sys/kernel/x", "/app/procdir/kmsg", "/app/procdir/*"} {
		if got := GlobIn(root, pattern, MaxFilesPerSource); len(got) != 0 {
			t.Fatalf("GlobIn(%s) = %q", pattern, got)
		}
	}
	if got := GlobIn(root, "/app/*.log", MaxFilesPerSource); strings.Join(got, "|") != "/app/ok.log" {
		t.Fatalf("GlobIn = %q", got)
	}
	for p, want := range map[string]bool{"/proc": true, "/proc/1/root": true, "/sys/x": true, "/dev/stdout": true, "/procfs/x": false, "/var/proc": false, "/": false} {
		if PseudoPath(p) != want {
			t.Fatalf("PseudoPath(%s) = %v", p, !want)
		}
	}
}

// The pid is checked again after the root is opened: a container whose pid or start changed meanwhile, or whose root
// is the host's own, is not opened.
func TestContainerOpenRefusesPidReuseAndTheHostRoot(t *testing.T) {
	fs := hostfs.FS{Root: t.TempDir()}
	writeStat(t, fs, 42, 1)
	containerRoot(t, fs, 42, t.TempDir())
	engine := &fakeEngine{}
	engine.set([]docker.ContainerSummary{{ID: "c1", Labels: map[string]string{"falak.site": "shop"}}}, map[string]int{"c1": 42})
	s := newContainerSet(engine, "", fs, slog.Default())
	open := func() bool {
		s.listed = time.Time{}
		if c := s.byID["c1"]; c != nil {
			c.close()
		}
		s.refresh(context.Background())
		return len(s.matching("shop", "")) == 1
	}

	engine.inspected = func(c *docker.Container, n int) {
		if n%2 == 0 {
			c.State.StartedAt = "2026-10-14T10:00:05Z" // restarted between the two inspects
		}
	}
	if open() {
		t.Fatal("opened although the container restarted meanwhile")
	}
	engine.inspected = func(c *docker.Container, n int) {
		if n%2 == 0 {
			c.State.Pid = 43
		}
	}
	if open() {
		t.Fatal("opened although the pid changed meanwhile")
	}
	engine.inspected = nil
	if !open() {
		t.Fatal("not opened")
	}

	// /proc/50/root is the host's "/".
	writeStat(t, fs, 50, 1)
	symlink(t, fs.P("/"), fs.P("/proc/50/root"))
	engine.set([]docker.ContainerSummary{{ID: "c1", Labels: map[string]string{"falak.site": "shop"}}}, map[string]int{"c1": 50})
	if open() {
		t.Fatal("opened the host's root")
	}

	// Same mount namespace as the host's init.
	writeStat(t, fs, 51, 1)
	containerRoot(t, fs, 51, t.TempDir())
	symlink(t, "mnt:[4026531841]", fs.P("/proc/51/ns/mnt"))
	symlink(t, "mnt:[4026531841]", fs.P("/proc/1/ns/mnt"))
	engine.set([]docker.ContainerSummary{{ID: "c1", Labels: map[string]string{"falak.site": "shop"}}}, map[string]int{"c1": 51})
	if open() {
		t.Fatal("opened a root in the host's mount namespace")
	}
}

// A container created moments ago has its files read from the start, but only while it is fresh: a source added an
// hour later starts at the end of that container's files.
func TestContainerFreshnessIsCheckedWhenAFileIsFirstSeen(t *testing.T) {
	fs := hostfs.FS{Root: t.TempDir()}
	dir := fs.P("/containers/c1")
	mkfile(t, filepath.Join(dir, "app/boot.log"), "booted\n")
	mkfile(t, filepath.Join(dir, "app/history.json"), "{\"message\":\"an hour of history\"}\n")
	writeStat(t, fs, 42, 1)
	containerRoot(t, fs, 42, dir)
	now := time.Now()
	engine := &fakeEngine{}
	engine.set([]docker.ContainerSummary{{ID: "c1", Created: now.Unix(), Labels: map[string]string{"falak.site": "shop"}}}, map[string]int{"c1": 42})
	sink := &memSink{}
	tl := NewTailer(fs, "/var/lib/falak", sink, nil)
	tl.SetContainerEngine(engine, "")
	tl.containers.now = func() time.Time { return now }
	tl.SetSources([]Source{{Path: "/app/*.log", Site: "shop", Container: true}})
	tl.Poll()
	if got := sink.bodies(); strings.Join(got, "|") != "booted" {
		t.Fatalf("fresh container: %q", got)
	}

	now = now.Add(time.Hour)
	tl.SetSources([]Source{{Path: "/app/*.log", Site: "shop", Container: true}, {Path: "/app/*.json", Site: "shop", Container: true}})
	tl.Poll()
	if got := sink.bodies(); len(got) != 1 {
		t.Fatalf("history of an old container shipped: %q", got)
	}
}

// Directory listings of a glob are reused for a few seconds and bounded.
func TestContainerDirectoryListingsAreCached(t *testing.T) {
	dir := t.TempDir()
	mkfile(t, filepath.Join(dir, "logs/a.log"), "")
	root, err := os.OpenRoot(dir)
	if err != nil {
		t.Fatal(err)
	}
	defer root.Close()
	c := &logContainer{root: root}
	now := time.Now()
	if got := c.lister(now)("/logs"); strings.Join(got, "|") != "a.log" {
		t.Fatal(got)
	}
	mkfile(t, filepath.Join(dir, "logs/b.log"), "")
	if got := c.lister(now.Add(time.Second))("/logs"); len(got) != 1 {
		t.Fatalf("listed again within the TTL: %q", got)
	}
	if got := c.lister(now.Add(dirCacheTTL))("/logs"); len(got) != 2 {
		t.Fatalf("not listed again after the TTL: %q", got)
	}
}

// A file read in one poll is read up to the size it has then, never past it.
func TestReadAvailableStopsAtTheFileSize(t *testing.T) {
	fs := hostfs.FS{Root: t.TempDir()}
	p := fs.P("/srv/logs/app.log")
	mkfile(t, p, "")
	sink := &memSink{}
	tl := NewTailer(fs, "/var/lib/falak", sink, nil)
	tl.SetSources([]Source{{Path: "/srv/logs/*.log", Site: "shop"}})
	tl.Poll()
	appendFile(t, p, "one\ntwo\n")
	tl.Poll()
	st := tl.files[p]
	if st == nil || st.offset != 8 || strings.Join(sink.bodies(), "|") != "one|two" {
		t.Fatalf("state %+v, records %q", st, sink.bodies())
	}
	if n := tl.readAvailable(p, st); n != 0 {
		t.Fatalf("read %d bytes past the end", n)
	}
}
