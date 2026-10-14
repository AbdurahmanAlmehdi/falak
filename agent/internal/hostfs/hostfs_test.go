package hostfs

import (
	"os"
	"testing"
)

func TestEnsureTraversableOpensOnlyTheSearchBit(t *testing.T) {
	f := FS{Root: t.TempDir()}
	if err := os.MkdirAll(f.P("/etc/falak"), 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.Chmod(f.P("/etc/falak"), 0o700); err != nil {
		t.Fatal(err)
	}
	changed, err := EnsureTraversable(f, "/etc/falak")
	if err != nil || !changed {
		t.Fatalf("changed %v err %v", changed, err)
	}
	fi, _ := os.Stat(f.P("/etc/falak"))
	if fi.Mode().Perm() != 0o711 {
		t.Fatalf("mode %o", fi.Mode().Perm())
	}
	// Already traversable: untouched; missing: no error, nothing created.
	if changed, err := EnsureTraversable(f, "/etc/falak"); err != nil || changed {
		t.Fatalf("second call changed %v err %v", changed, err)
	}
	if changed, err := EnsureTraversable(f, "/etc/missing"); err != nil || changed {
		t.Fatalf("missing changed %v err %v", changed, err)
	}
	if _, err := os.Stat(f.P("/etc/missing")); !os.IsNotExist(err) {
		t.Fatal("created a missing directory")
	}
}

func TestReadlinkReturnsTheHostTarget(t *testing.T) {
	f := FS{Root: t.TempDir()}
	if err := os.MkdirAll(f.P("/proc/7"), 0o755); err != nil {
		t.Fatal(err)
	}
	// A host-absolute target is not rewritten under Root (and need not exist there).
	if err := os.Symlink("/srv/falak/sites/blog/current", f.P("/proc/7/cwd")); err != nil {
		t.Fatal(err)
	}
	if got, err := f.Readlink("/proc/7/cwd"); err != nil || got != "/srv/falak/sites/blog/current" {
		t.Fatalf("got %q err %v", got, err)
	}
	if _, err := f.Readlink("/proc/8/cwd"); err == nil {
		t.Fatal("missing link")
	}
}

func TestEvalSymlinksResolvesHostTargetsUnderRoot(t *testing.T) {
	f := FS{Root: t.TempDir()}
	if err := os.MkdirAll(f.P("/data/srv/falak/sites"), 0o755); err != nil {
		t.Fatal(err)
	}
	// /srv -> /data/srv (absolute: a host path), /data/web -> srv (relative).
	if err := os.Symlink("/data/srv", f.P("/srv")); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink("srv", f.P("/data/web")); err != nil {
		t.Fatal(err)
	}
	for in, want := range map[string]string{
		"/srv/falak/sites":           "/data/srv/falak/sites",
		"/data/web/falak/sites/":     "/data/srv/falak/sites",
		"/data/srv/falak/../falak/.": "/data/srv/falak",
	} {
		if got, err := f.EvalSymlinks(in); err != nil || got != want {
			t.Fatalf("%s: got %q err %v, want %q", in, got, err, want)
		}
	}
	if _, err := f.EvalSymlinks("/srv/missing"); err == nil {
		t.Fatal("a missing path is an error")
	}
	if err := os.Symlink("/loop", f.P("/loop")); err != nil {
		t.Fatal(err)
	}
	if _, err := f.EvalSymlinks("/loop"); err == nil {
		t.Fatal("a link loop is an error")
	}
}
