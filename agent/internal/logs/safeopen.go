package logs

import (
	"errors"
	"os"
	"syscall"
)

// openFlags opens a log file without ever blocking or taking a terminal: a FIFO swapped in for a log file (by a site
// user on the host, by anyone in a container) would block a plain open, and the tailer holds its lock while it opens.
const openFlags = os.O_RDONLY | syscall.O_NONBLOCK | syscall.O_NOCTTY

var (
	errNotRegular = errors.New("not a regular file")
	errReplaced   = errors.New("replaced between stat and open")
	errPseudoFS   = errors.New("on a kernel pseudo filesystem")
)

// checkOpened verifies an opened log file before anything is read from it: a regular file, still the one stat saw
// (same device and inode: nothing was swapped in between), and not on a kernel pseudo filesystem (procfs files look
// regular: a link to /proc/kmsg would drain the host's kernel log and block, /proc/kallsyms would show kernel
// addresses to the agent's root reader).
func checkOpened(f *os.File, want os.FileInfo) error {
	fi, err := f.Stat()
	if err != nil {
		return err
	}
	if !fi.Mode().IsRegular() {
		return errNotRegular
	}
	if !os.SameFile(fi, want) {
		return errReplaced
	}
	return checkFilesystem(f)
}
