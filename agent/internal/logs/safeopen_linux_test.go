//go:build linux

package logs

import (
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
)

// procfs files look regular (size 0): a log path linked to one is opened, then refused by its filesystem.
func TestProcfsFilesAreRefused(t *testing.T) {
	f, err := os.OpenFile("/proc/self/stat", openFlags, 0)
	if err != nil {
		t.Skip("no /proc:", err)
	}
	fi, _ := os.Stat("/proc/self/stat")
	if err := checkOpened(f, fi); err != errPseudoFS && err != errReplaced {
		t.Fatalf("checkOpened(/proc/self/stat) = %v", err)
	}
	f.Close()
	if g, err := os.OpenFile("/proc/version", openFlags, 0); err == nil {
		gi, _ := g.Stat()
		if err := checkOpened(g, gi); err != errPseudoFS {
			t.Fatalf("checkOpened(/proc/version) = %v", err)
		}
		g.Close()
	}

	// A site's log file linked to a procfs file on the host: nothing is shipped.
	root := t.TempDir()
	fs := hostfs.FS{Root: root}
	os.MkdirAll(fs.P("/srv/logs"), 0o755)
	if err := os.Symlink("/proc/version", filepath.Join(fs.P("/srv/logs"), "laravel.log")); err != nil {
		t.Fatal(err)
	}
	sink := &memSink{}
	tl := NewTailer(fs, "/var/lib/falak", sink, nil)
	tl.SetSources([]Source{{Path: "/srv/logs/*.log", Site: "shop"}})
	pollWithin(t, tl, 2*time.Second)
	pollWithin(t, tl, 2*time.Second)
	if len(tl.files) != 0 || len(sink.recs) != 0 {
		t.Fatalf("procfs file read: %v %q", tl.files, sink.bodies())
	}
}
