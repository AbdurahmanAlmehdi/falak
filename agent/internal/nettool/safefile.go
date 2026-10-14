package nettool

import (
	"errors"
	"fmt"
	"io"
	"os"

	"golang.org/x/sys/unix"
)

// maxHostFile bounds a container's resolv.conf / hosts file the agent reads.
const maxHostFile = 64 << 10

// readRegularFile reads a small regular file without following a final symlink and without blocking: opened
// O_NONBLOCK|O_NOFOLLOW, then fstat must say regular file (a FIFO or a device — a watchdog — is refused before any
// read), and at most maxHostFile bytes are read.
func readRegularFile(path string) (string, error) {
	fd, err := unix.Open(path, unix.O_RDONLY|unix.O_NONBLOCK|unix.O_NOFOLLOW|unix.O_CLOEXEC, 0)
	if err != nil {
		return "", fmt.Errorf("open %s: %w", path, err)
	}
	f := os.NewFile(uintptr(fd), path)
	defer f.Close()
	var st unix.Stat_t
	if err := unix.Fstat(fd, &st); err != nil {
		return "", fmt.Errorf("stat %s: %w", path, err)
	}
	if uint32(st.Mode)&unix.S_IFMT != unix.S_IFREG {
		return "", fmt.Errorf("%s is not a regular file", path)
	}
	b, err := io.ReadAll(io.LimitReader(f, maxHostFile+1))
	if err != nil {
		return "", fmt.Errorf("read %s: %w", path, err)
	}
	if len(b) > maxHostFile {
		return "", errors.New(path + " is larger than 64 KiB")
	}
	return string(b), nil
}
