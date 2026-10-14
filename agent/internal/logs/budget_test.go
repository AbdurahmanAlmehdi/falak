package logs

import (
	"context"
	"fmt"
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

// A pid whose cgroup is not the container's (another container's process, a host process) gives no root.
func TestContainerOpenRequiresTheContainersCgroup(t *testing.T) {
	fs := hostfs.FS{Root: t.TempDir()}
	writeStat(t, fs, 42, 1)
	containerRoot(t, fs, 42, t.TempDir(), "c2") // the pid belongs to c2
	engine := &fakeEngine{}
	engine.set([]docker.ContainerSummary{{ID: "c1", Labels: map[string]string{"falak.site": "shop"}}}, map[string]int{"c1": 42})
	s := newContainerSet(engine, "", fs, slog.Default())
	s.refresh(context.Background())
	if len(s.matching("shop", "")) != 0 {
		t.Fatal("opened through another container's pid")
	}
	writeCgroup(t, fs, 42, "c1")
	s.listed = time.Time{}
	s.refresh(context.Background())
	if len(s.matching("shop", "")) != 1 {
		t.Fatal("not opened through its own pid")
	}
	os.Remove(fs.P("/proc/42/cgroup"))
	s.byID["c1"].close()
	s.listed = time.Time{}
	s.refresh(context.Background())
	if len(s.matching("shop", "")) != 0 {
		t.Fatal("opened without a readable cgroup")
	}
}

// A directory of a glob that is a FIFO (swapped in for it) is not opened: O_DIRECTORY fails before anything blocks.
func TestReadDirNamesNeverBlocksOnAFIFO(t *testing.T) {
	dir := t.TempDir()
	if err := syscall.Mkfifo(filepath.Join(dir, "logs"), 0o644); err != nil {
		t.Skip("mkfifo:", err)
	}
	root, err := os.OpenRoot(dir)
	if err != nil {
		t.Fatal(err)
	}
	defer root.Close()
	done := make(chan []string, 1)
	go func() { done <- readDirNames(root, "/logs") }()
	select {
	case names := <-done:
		if len(names) != 0 {
			t.Fatalf("listed a FIFO: %q", names)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("listing a FIFO blocked")
	}
	if got := GlobIn(root, "/logs/*.log", MaxFilesPerSource); len(got) != 0 {
		t.Fatal(got)
	}
}

// A glob over a huge directory stops at its budget of operations (entries looked at, lstats, stats).
func TestGlobStopsAtItsBudget(t *testing.T) {
	dir := t.TempDir()
	for i := range 300 {
		mkfile(t, filepath.Join(dir, fmt.Sprintf("logs/d%03d/app.log", i)), "")
	}
	root, err := os.OpenRoot(dir)
	if err != nil {
		t.Fatal(err)
	}
	defer root.Close()
	list := func(d string) []string { return readDirNames(root, d) }

	all := globIn(root, "/logs/*/app.log", MaxFilesPerSource+100, list, &globBudget{left: MaxGlobOps})
	if len(all) != 300 {
		t.Fatalf("unbounded glob: %d files", len(all))
	}
	small := &globBudget{left: 200}
	if got := globIn(root, "/logs/*/app.log", 1000, list, small); len(got) != 0 || small.left >= 0 {
		t.Fatalf("budget not applied in a middle segment: %d files, %d left", len(got), small.left)
	}
	last := &globBudget{left: 400}
	got := globIn(root, "/logs/d00*/app.log", 1000, list, last)
	if len(got) != 10 || last.left < 0 {
		t.Fatalf("a small glob: %d files, %d left", len(got), last.left)
	}
}

// One poll reads at most the site's byte budget; the rest follows next poll, nothing is lost.
func TestSiteReadBudgetPerPoll(t *testing.T) {
	fs := hostfs.FS{Root: t.TempDir()}
	os.MkdirAll(fs.P("/srv/a"), 0o755)
	sink := &memSink{}
	tl := NewTailer(fs, "/var/lib/falak", sink, nil)
	tl.maxSiteBytes = 64
	tl.SetSources([]Source{{Path: "/srv/a/*.log", Site: "shop"}, {Path: "/srv/b/*.log", Site: "blog"}})
	tl.Poll()
	var lines []string
	for i := range 20 {
		lines = append(lines, fmt.Sprintf("line %02d", i)) // 8 bytes with its newline
	}
	mkfile(t, fs.P("/srv/a/one.log"), strings.Join(lines[:10], "\n")+"\n")
	mkfile(t, fs.P("/srv/a/two.log"), strings.Join(lines[10:], "\n")+"\n")
	mkfile(t, fs.P("/srv/b/other.log"), "blog line\n")
	tl.Poll()
	shop := 0
	for _, r := range sink.recs {
		if r.Site == "shop" {
			shop++
		}
	}
	if shop != 8 {
		t.Fatalf("first poll read %d shop lines, want the 64-byte budget's 8: %q", shop, sink.bodies())
	}
	if !strings.Contains(strings.Join(sink.bodies(), "|"), "blog line") {
		t.Fatal("another site's budget was spent")
	}
	for range 3 {
		tl.Poll()
	}
	if n := len(sink.recs); n != 21 {
		t.Fatalf("got %d records: %q", n, sink.bodies())
	}
}

// A site has at most maxSiteOpen files open.
func TestOpenFilesPerSite(t *testing.T) {
	fs := hostfs.FS{Root: t.TempDir()}
	for i := range 5 {
		mkfile(t, fs.P(fmt.Sprintf("/srv/a/%d.log", i)), "")
	}
	tl := NewTailer(fs, "/var/lib/falak", &memSink{}, nil)
	tl.maxSiteOpen = 3
	tl.SetSources([]Source{{Path: "/srv/a/*.log", Site: "shop"}})
	tl.Poll()
	tl.Poll()
	if len(tl.files) != 3 {
		t.Fatalf("%d files open", len(tl.files))
	}
}

// A file already large when first seen in a fresh container is read from its last freshTail bytes, from a whole line.
func TestFreshContainerReadsOnlyTheTailOfALargeFile(t *testing.T) {
	fs := hostfs.FS{Root: t.TempDir()}
	dir := fs.P("/containers/c1")
	var b strings.Builder
	for i := 0; b.Len() < 3*freshTail; i++ {
		fmt.Fprintf(&b, "copied history line %07d\n", i)
	}
	mkfile(t, filepath.Join(dir, "app/app.log"), b.String())
	writeStat(t, fs, 42, 1)
	containerRoot(t, fs, 42, dir, "c1")
	engine := &fakeEngine{}
	engine.set([]docker.ContainerSummary{{ID: "c1", Created: time.Now().Unix(), Labels: map[string]string{"falak.site": "shop"}}}, map[string]int{"c1": 42})
	sink := &memSink{}
	tl := NewTailer(fs, "/var/lib/falak", sink, nil)
	tl.SetContainerEngine(engine, "")
	tl.SetSources([]Source{{Path: "/app/*.log", Site: "shop", Container: true}})
	tl.Poll()
	bodies := sink.bodies()
	lineLen := len("copied history line 0000000\n")
	if len(bodies) == 0 || len(bodies) > freshTail/lineLen+1 || !strings.HasPrefix(bodies[0], "copied history line ") || len(bodies[0]) != lineLen-1 {
		t.Fatalf("%d lines, first %q", len(bodies), bodies[0])
	}
	if !strings.HasSuffix(b.String(), bodies[len(bodies)-1]+"\n") {
		t.Fatal("not the end of the file")
	}
}
