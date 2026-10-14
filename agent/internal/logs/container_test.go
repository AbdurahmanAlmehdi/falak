package logs

import (
	"context"
	"encoding/json"
	"fmt"
	"log/slog"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/docker"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
)

func mkfile(t *testing.T, p, s string) {
	t.Helper()
	if err := os.MkdirAll(filepath.Dir(p), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(p, []byte(s), 0o644); err != nil {
		t.Fatal(err)
	}
}

func symlink(t *testing.T, target, link string) {
	t.Helper()
	if err := os.MkdirAll(filepath.Dir(link), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(target, link); err != nil {
		t.Fatal(err)
	}
}

// A container's paths are resolved as the container sees them: absolute link targets start again at its root, ".."
// stops there, so no link reaches a host file.
func TestResolveInAndGlobInStayInTheContainerRoot(t *testing.T) {
	host := t.TempDir()
	mkfile(t, filepath.Join(host, "etc/secret.log"), "host secret\n") // outside the container root
	rootDir := filepath.Join(host, "proc/42/root")
	mkfile(t, filepath.Join(rootDir, "app/storage/logs/a.log"), "a\n")
	mkfile(t, filepath.Join(rootDir, "app/storage/logs/b.log"), "b\n")
	mkfile(t, filepath.Join(rootDir, "app/storage/logs/notes.txt"), "x\n")
	os.MkdirAll(filepath.Join(rootDir, "app/storage/logs/dir.log"), 0o755) // not a regular file
	mkfile(t, filepath.Join(rootDir, "data/shared/logs/c.log"), "c\n")
	mkfile(t, filepath.Join(rootDir, "etc/inner.log"), "inner\n")
	symlink(t, "/data/shared", filepath.Join(rootDir, "srv/shared"))                                      // absolute: in the container
	symlink(t, "../../../../../../etc/secret.log", filepath.Join(rootDir, "app/storage/logs/escape.log")) // ".." clamped
	symlink(t, "/etc/secret.log", filepath.Join(rootDir, "app/storage/logs/abs-escape.log"))              // not the host's
	symlink(t, "/etc/inner.log", filepath.Join(rootDir, "app/storage/logs/inner.log"))
	symlink(t, "/dev/stdout", filepath.Join(rootDir, "app/storage/logs/stdout.log"))
	symlink(t, "loop2.log", filepath.Join(rootDir, "app/storage/logs/loop1.log"))
	symlink(t, "loop1.log", filepath.Join(rootDir, "app/storage/logs/loop2.log"))
	symlink(t, host, filepath.Join(rootDir, "hostroot")) // an absolute link to the host's own path

	root, err := os.OpenRoot(rootDir)
	if err != nil {
		t.Fatal(err)
	}
	defer root.Close()

	for _, tc := range []struct {
		name, path, want string
		err              bool
	}{
		{"plain", "/app/storage/logs/a.log", "/app/storage/logs/a.log", false},
		{"absolute link directory", "/srv/shared/logs/c.log", "/data/shared/logs/c.log", false},
		{"relative escape is clamped", "/app/storage/logs/escape.log", "", true},
		{"absolute escape stays inside", "/app/storage/logs/abs-escape.log", "", true},
		{"absolute link inside", "/app/storage/logs/inner.log", "/etc/inner.log", false},
		{"dot-dot clamped", "/../../app/./storage/../storage/logs/a.log", "/app/storage/logs/a.log", false},
		{"loop", "/app/storage/logs/loop1.log", "", true},
		{"host path link", "/hostroot/etc/secret.log", "", true},
	} {
		t.Run(tc.name, func(t *testing.T) {
			got, err := ResolveIn(root, tc.path)
			if tc.err {
				// Resolved or not, it must never be readable through the root as the host's file.
				if err == nil {
					if b, rerr := root.ReadFile(rootRel(got)); rerr == nil && strings.Contains(string(b), "host secret") {
						t.Fatalf("%s reached the host file through %s", tc.path, got)
					}
				}
				return
			}
			if err != nil || got != tc.want {
				t.Fatalf("ResolveIn(%s) = %q, %v; want %q", tc.path, got, err, tc.want)
			}
		})
	}

	got := GlobIn(root, "/app/storage/logs/*.log", MaxFilesPerSource)
	want := []string{"/app/storage/logs/a.log", "/app/storage/logs/b.log", "/etc/inner.log"}
	if strings.Join(got, "|") != strings.Join(want, "|") {
		t.Fatalf("GlobIn = %q, want %q (no escape, no directory, no /dev/stdout, no loop)", got, want)
	}
	if got := GlobIn(root, "/srv/*/logs/*.log", MaxFilesPerSource); strings.Join(got, "|") != "/data/shared/logs/c.log" {
		t.Fatalf("glob through a linked directory = %q", got)
	}
	if got := GlobIn(root, "/app/storage/logs/[ab].log", 1); len(got) != 1 {
		t.Fatalf("limit not applied: %q", got)
	}
	if got := GlobIn(root, "/hostroot/etc/*.log", MaxFilesPerSource); len(got) != 0 {
		t.Fatalf("glob reached the host: %q", got)
	}
}

type fakeEngine struct {
	mu     sync.Mutex
	list   []docker.ContainerSummary
	pids   map[string]int
	lists  int
	failed bool
	// inspected, when set, runs after each inspect (n counts them from 1) and may change the container under it.
	inspected func(c *docker.Container, n int)
	inspects  int
}

func (f *fakeEngine) ContainerList(_ context.Context, _ bool, labels []string) ([]docker.ContainerSummary, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.lists++
	if f.failed {
		return nil, fmt.Errorf("engine down")
	}
	if len(labels) != 1 || labels[0] != "falak.site" {
		return nil, fmt.Errorf("unexpected filter %v", labels)
	}
	return append([]docker.ContainerSummary(nil), f.list...), nil
}

func (f *fakeEngine) ContainerInspect(_ context.Context, id string) (*docker.Container, bool, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	pid, ok := f.pids[id]
	if !ok {
		return nil, false, nil
	}
	c := &docker.Container{ID: id}
	c.State.Running, c.State.Pid, c.State.StartedAt = true, pid, "2026-10-14T10:00:00Z"
	f.inspects++
	if f.inspected != nil {
		f.inspected(c, f.inspects)
	}
	return c, true, nil
}

func (f *fakeEngine) set(list []docker.ContainerSummary, pids map[string]int) {
	f.mu.Lock()
	f.list, f.pids = list, pids
	f.mu.Unlock()
}

// writeStat writes a fake /proc/<pid>/stat whose start time (field 22) is start.
func writeStat(t *testing.T, fs hostfs.FS, pid int, start uint64) {
	t.Helper()
	mkfile(t, fs.P(fmt.Sprintf("/proc/%d/stat", pid)), fmt.Sprintf("%d (php-fpm: master) S %s%d 0 0\n", pid, strings.Repeat("0 ", 18), start))
}

// containerRoot makes /proc/<pid>/root a link to the container's filesystem (dir), as the kernel's magic link is.
func containerRoot(t *testing.T, fs hostfs.FS, pid int, dir string) {
	t.Helper()
	symlink(t, dir, fs.P(fmt.Sprintf("/proc/%d/root", pid)))
}

func TestProcStart(t *testing.T) {
	fs := hostfs.FS{Root: t.TempDir()}
	mkfile(t, fs.P("/proc/7/stat"), "7 (a b) c) S 1 7 7 0 -1 4194560 100 0 0 0 1 2 0 0 20 0 1 0 98765 1000 10\n")
	if v, ok := procStart(fs, 7); !ok || v != 98765 {
		t.Fatalf("procStart = %d, %v", v, ok)
	}
	if _, ok := procStart(fs, 8); ok {
		t.Fatal("missing process has a start time")
	}
}

// Files inside a site's containers: attributed like its stdout, followed across a restart in place (new pid, same
// offsets), rotation inside the container, a new container of a deploy read from its start, offsets per container id
// and dropped with the container.
func TestContainerSourceRestartRotationAndOffsets(t *testing.T) {
	fs := hostfs.FS{Root: t.TempDir()}
	c1Dir := fs.P("/var/lib/docker/overlay2/c1/merged")
	c2Dir := fs.P("/var/lib/docker/overlay2/c2/merged")
	otherDir := fs.P("/var/lib/docker/overlay2/other/merged")
	logPath := filepath.Join(c1Dir, "var/www/html/storage/logs/laravel.log")
	mkfile(t, logPath, "history before the agent\n")
	mkfile(t, filepath.Join(otherDir, "var/www/html/storage/logs/laravel.log"), "")
	writeStat(t, fs, 42, 1000)
	containerRoot(t, fs, 42, c1Dir)
	writeStat(t, fs, 50, 3000)
	containerRoot(t, fs, 50, otherDir)

	old := time.Now().Add(-time.Hour).Unix()
	engine := &fakeEngine{}
	engine.set([]docker.ContainerSummary{
		{ID: "c1", Names: []string{"/shop-app-1"}, Created: old, Labels: map[string]string{"falak.site": "shop", "falak.service": "app", "falak.release": "01REL"}},
		{ID: "other", Names: []string{"/blog-app-1"}, Created: old, Labels: map[string]string{"falak.site": "blog", "falak.service": "app"}},
	}, map[string]int{"c1": 42, "other": 50})

	sink := &memSink{}
	tl := NewTailer(fs, "/var/lib/falak", sink, nil)
	tl.SetContainerEngine(engine, "")
	tl.containers.every = 0 // list on every poll
	tl.SetSources([]Source{{Path: "/var/www/html/storage/logs/*.log", Site: "shop", Container: true, ComposeService: "app"}})
	tl.Poll()
	if got := sink.bodies(); len(got) != 0 {
		t.Fatalf("pre-existing history shipped: %q", got)
	}

	appendFile(t, logPath, "line one\n")
	tl.Poll()
	if got := sink.bodies(); strings.Join(got, "|") != "line one" {
		t.Fatalf("got %q", got)
	}
	rec := sink.recs[0]
	for k, v := range map[string]string{"container.id": "c1", "container.name": "shop-app-1", "falak.compose.service": "app", "falak.release.id": "01REL",
		"log.file.path": "/var/www/html/storage/logs/laravel.log", "log.file.name": "laravel.log", "falak.log.origin": "container_file"} {
		if rec.Attrs[k] != v {
			t.Fatalf("%s = %q, want %q (%v)", k, rec.Attrs[k], v, rec.Attrs)
		}
	}
	if rec.Site != "shop" || rec.Service != "" {
		t.Fatalf("record = %+v", rec)
	}

	// Restarted in place: same container id, a new init pid (the old one is gone). Nothing is lost or repeated.
	appendFile(t, logPath, "written while restarting\n")
	os.RemoveAll(fs.P("/proc/42"))
	writeStat(t, fs, 43, 2000)
	containerRoot(t, fs, 43, c1Dir)
	engine.set(engine.list, map[string]int{"c1": 43, "other": 50})
	tl.Poll()
	appendFile(t, logPath, "after the restart\n")
	tl.Poll()
	want := []string{"line one", "written while restarting", "after the restart"}
	if got := sink.bodies(); strings.Join(got, "|") != strings.Join(want, "|") {
		t.Fatalf("got %q\nwant %q", got, want)
	}
	if tl.containers.byID["c1"].pid != 43 {
		t.Fatalf("pid not followed: %d", tl.containers.byID["c1"].pid)
	}

	// Rotation inside the container: the old file's last line, then the new file from its start.
	appendFile(t, logPath, "last in old\n")
	os.Rename(logPath, logPath+".1")
	appendFile(t, logPath, "first in new\n")
	tl.Poll()
	want = append(want, "last in old", "first in new")
	if got := sink.bodies(); strings.Join(got, "|") != strings.Join(want, "|") {
		t.Fatalf("got %q\nwant %q", got, want)
	}

	// A deploy replaces c1 by c2 (created moments ago): its file is read from the start, c1's offsets are dropped.
	mkfile(t, filepath.Join(c2Dir, "var/www/html/storage/logs/laravel.log"), "booted\n")
	writeStat(t, fs, 60, 4000)
	containerRoot(t, fs, 60, c2Dir)
	engine.set([]docker.ContainerSummary{
		{ID: "c2", Names: []string{"/shop-app-2"}, Created: time.Now().Unix(), Labels: map[string]string{"falak.site": "shop", "falak.service": "app"}},
		{ID: "other", Names: []string{"/blog-app-1"}, Created: old, Labels: map[string]string{"falak.site": "blog", "falak.service": "app"}},
	}, map[string]int{"c2": 60, "other": 50})
	tl.Poll()
	want = append(want, "booted")
	if got := sink.bodies(); strings.Join(got, "|") != strings.Join(want, "|") {
		t.Fatalf("got %q\nwant %q", got, want)
	}
	if last := sink.recs[len(sink.recs)-1]; last.Attrs["container.id"] != "c2" {
		t.Fatalf("attributed to %q", last.Attrs["container.id"])
	}
	b, err := os.ReadFile(fs.P("/var/lib/falak/log-offsets.json"))
	if err != nil {
		t.Fatal(err)
	}
	var saved map[string]savedOffset
	json.Unmarshal(b, &saved)
	if _, ok := saved["container:c2:/var/www/html/storage/logs/laravel.log"]; !ok {
		t.Fatalf("no offset for c2: %v", saved)
	}
	for k := range saved {
		if strings.HasPrefix(k, "container:c1:") || strings.HasPrefix(k, "container:other:") {
			t.Fatalf("offset %s kept", k)
		}
	}

	// A transient Engine API failure keeps the containers (and their offsets).
	engine.failed = true
	appendFile(t, filepath.Join(c2Dir, "var/www/html/storage/logs/laravel.log"), "engine down\n")
	tl.Poll()
	if got := sink.bodies(); got[len(got)-1] != "engine down" {
		t.Fatalf("got %q", got)
	}
}

// A container whose init pid was reused between the inspect and the open is not opened (the root could be anyone's).
func TestContainerOpenRefusesAReusedPid(t *testing.T) {
	fs := hostfs.FS{Root: t.TempDir()}
	engine := &fakeEngine{}
	engine.set([]docker.ContainerSummary{{ID: "c1", Labels: map[string]string{"falak.site": "shop"}}}, map[string]int{"c1": 42})
	s := newContainerSet(engine, "", fs, slog.Default())
	// No /proc/42/stat: the pid is gone.
	s.refresh(context.Background())
	if c := s.byID["c1"]; c == nil || c.root != nil {
		t.Fatalf("container = %+v", c)
	}
	if len(s.matching("shop", "")) != 0 {
		t.Fatal("a container without a root matches")
	}
	writeStat(t, fs, 42, 1)
	containerRoot(t, fs, 42, t.TempDir())
	s.listed = time.Time{}
	s.refresh(context.Background())
	if len(s.matching("shop", "")) != 1 || len(s.matching("shop", "worker")) != 0 || len(s.matching("blog", "")) != 0 {
		t.Fatal("matching")
	}
}

// A custom start-of-record pattern merges a PHP-FPM slow log entry with its backtrace, at WARN, origin php_fpm_slow.
func TestMultilinePatternAndOrigin(t *testing.T) {
	fs := hostfs.FS{Root: t.TempDir()}
	p := fs.P("/var/log/falak/php-fpm-slow/shop.log")
	mkfile(t, p, "")
	sink := &memSink{}
	tl := NewTailer(fs, "/var/lib/falak", sink, nil)
	tl.SetSources([]Source{
		{Path: "/var/log/falak/php-fpm-slow/shop.log", Site: "shop", Origin: OriginPHPFPMSlow, MultilinePattern: `^\[\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2}\]`},
		{Path: "/var/log/falak/fpm-shop.log", Site: "shop", Origin: OriginPHPFPM},
	})
	tl.Poll()
	appendFile(t, p, "\n[14-Oct-2026 10:11:12]  [pool falak-shop] pid 4321\nscript_filename = /srv/falak/sites/shop/current/public/index.php\n"+
		"[0x00007f8b1c013f40] sleep() /srv/falak/sites/shop/current/app/Http/Controllers/Home.php:12\n\n"+
		"[14-Oct-2026 10:11:20]  [pool falak-shop] pid 4322\n")
	appendFile(t, fs.P("/var/log/falak/fpm-shop.log"), "")
	tl.Poll()
	tl.Poll()
	if len(sink.recs) != 2 {
		t.Fatalf("got %q", sink.bodies())
	}
	first := sink.recs[0]
	if !strings.HasPrefix(first.Body, "[14-Oct-2026 10:11:12]") || !strings.HasSuffix(first.Body, "Home.php:12") || first.Severity != "WARN" || first.Attrs["falak.log.origin"] != "php_fpm_slow" {
		t.Fatalf("record = %q %s %v", first.Body, first.Severity, first.Attrs)
	}

	appendFile(t, fs.P("/var/log/falak/fpm-shop.log"), "[14-Oct-2026 10:12:00] WARNING: [pool falak-shop] server reached pm.max_children setting (5)\n")
	tl.Poll()
	last := sink.recs[len(sink.recs)-1]
	if last.Attrs["falak.log.origin"] != "php_fpm" || last.Severity != "WARN" {
		t.Fatalf("record = %+v", last)
	}
}
