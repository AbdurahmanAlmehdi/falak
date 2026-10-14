//go:build linux

package logs

import (
	"io"
	"os"
	"path/filepath"
	"syscall"
	"testing"
	"time"
)

// openLog opens O_PATH first: a FIFO or a device node in place of the log file is refused on the handle, before
// anything opens it for reading (no driver's open runs), and nothing blocks.
func TestOpenLogRefusesFIFOsAndDevicesBeforeOpeningThem(t *testing.T) {
	dir := t.TempDir()
	regular := filepath.Join(dir, "app.log")
	mkfile(t, regular, "hello\n")
	regularFi, _ := os.Stat(regular)

	fifo := filepath.Join(dir, "fifo.log")
	if err := syscall.Mkfifo(fifo, 0o644); err != nil {
		t.Fatal(err)
	}
	opened := make(chan error, 1)
	go func() {
		// The stat saw the regular file; a FIFO is there at the open (exchanged in between).
		f, err := openLog(func(flag int) (*os.File, error) { return os.OpenFile(fifo, flag, 0) }, regularFi)
		if f != nil {
			f.Close()
		}
		opened <- err
	}()
	select {
	case err := <-opened:
		if err == nil {
			t.Fatal("a FIFO was opened")
		}
	case <-time.After(2 * time.Second):
		t.Fatal("blocked on a FIFO")
	}

	// A device node (needs CAP_MKNOD): /dev/null's numbers, harmless if it were opened.
	dev := filepath.Join(dir, "dev.log")
	if err := syscall.Mknod(dev, syscall.S_IFCHR|0o644, 1<<8|3); err == nil {
		if _, err := openLog(func(flag int) (*os.File, error) {
			if flag&syscall.O_PATH == 0 {
				t.Fatal("a device node opened without O_PATH")
			}
			return os.OpenFile(dev, flag, 0)
		}, regularFi); err == nil {
			t.Fatal("a device node was accepted")
		}
	} else {
		t.Log("no device node test (not root):", err)
	}

	// The regular file goes through: O_PATH, then reopened for reading through /proc/self/fd.
	var flags []int
	f, err := openLog(func(flag int) (*os.File, error) { flags = append(flags, flag); return os.OpenFile(regular, flag, 0) }, regularFi)
	if err != nil {
		t.Fatal(err)
	}
	defer f.Close()
	if b, _ := io.ReadAll(f); string(b) != "hello\n" || len(flags) != 1 || flags[0]&syscall.O_PATH == 0 {
		t.Fatalf("read %q, opens %v", b, flags)
	}

	// Through an os.Root (container sources): the same.
	root, err := os.OpenRoot(dir)
	if err != nil {
		t.Fatal(err)
	}
	defer root.Close()
	if _, err := openLog(func(flag int) (*os.File, error) { return root.OpenFile("fifo.log", flag, 0) }, regularFi); err == nil {
		t.Fatal("a FIFO was opened through the root")
	}
	g, err := openLog(func(flag int) (*os.File, error) { return root.OpenFile("app.log", flag, 0) }, regularFi)
	if err != nil {
		t.Fatal(err)
	}
	g.Close()
}
