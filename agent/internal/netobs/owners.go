package netobs

import (
	"bytes"
	"os"
	"strconv"
	"strings"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
)

// socketInodes reads the socket inodes a process holds open (/proc/<pid>/fd links "socket:[<inode>]"), at most max
// links (budget is decremented by what was read). Unreadable processes (exited, kernel threads) give nothing.
func socketInodes(fs hostfs.FS, pid int, max int, budget *int) []uint64 {
	dir := "/proc/" + strconv.Itoa(pid) + "/fd"
	entries, err := os.ReadDir(fs.P(dir))
	if err != nil {
		return nil
	}
	var out []uint64
	for i, e := range entries {
		if i >= max || *budget <= 0 {
			break
		}
		*budget--
		target, err := fs.Readlink(dir + "/" + e.Name())
		if err != nil {
			continue
		}
		if inode, ok := socketInode(target); ok {
			out = append(out, inode)
		}
	}
	return out
}

// socketInode parses "socket:[12345]".
func socketInode(link string) (uint64, bool) {
	s, ok := strings.CutPrefix(link, "socket:[")
	if !ok {
		return 0, false
	}
	s, ok = strings.CutSuffix(s, "]")
	if !ok {
		return 0, false
	}
	n, err := strconv.ParseUint(s, 10, 64)
	return n, err == nil
}

// procOwner is the process holding a listening socket.
type procOwner struct {
	pid   int
	start uint64
	comm  string
	// publishes is the container address a docker-proxy forwards to ("" for any other process).
	publishes string
}

// findOwners scans every process's fds for the wanted inodes, at most budget links in all; returns what it found.
func findOwners(fs hostfs.FS, wanted map[uint64]bool, budget int) map[uint64]procOwner {
	found := map[uint64]procOwner{}
	entries, err := os.ReadDir(fs.P("/proc"))
	if err != nil {
		return found
	}
	for _, e := range entries {
		pid, err := strconv.Atoi(e.Name())
		if err != nil || pid <= 0 {
			continue
		}
		if budget <= 0 || len(found) == len(wanted) {
			break
		}
		var mine []uint64
		for _, inode := range socketInodes(fs, pid, 1<<20, &budget) {
			if wanted[inode] {
				mine = append(mine, inode)
			}
		}
		if len(mine) == 0 {
			continue
		}
		o := readOwner(fs, pid)
		for _, inode := range mine {
			found[inode] = o
		}
	}
	return found
}

// readOwner reads a process's name, start time and, for a docker-proxy, the container address it publishes.
func readOwner(fs hostfs.FS, pid int) procOwner {
	dir := "/proc/" + strconv.Itoa(pid)
	o := procOwner{pid: pid}
	if b, err := fs.ReadFile(dir + "/comm"); err == nil {
		o.comm = strings.TrimSpace(string(b))
	}
	o.start = procStart(fs, pid)
	if o.comm == "docker-proxy" {
		if b, err := fs.ReadFile(dir + "/cmdline"); err == nil {
			args := strings.Split(string(bytes.TrimRight(b, "\x00")), "\x00")
			for i := 0; i+1 < len(args); i++ {
				if args[i] == "-container-ip" {
					o.publishes = args[i+1]
				}
			}
		}
	}
	return o
}

// procStart is a process's start time (field 22 of /proc/<pid>/stat; 0 when unreadable).
func procStart(fs hostfs.FS, pid int) uint64 {
	b, err := fs.ReadFile("/proc/" + strconv.Itoa(pid) + "/stat")
	if err != nil {
		return 0
	}
	i := bytes.LastIndexByte(b, ')')
	if i < 0 {
		return 0
	}
	f := strings.Fields(string(b[i+1:]))
	if len(f) < 20 {
		return 0
	}
	n, _ := strconv.ParseUint(f[19], 10, 64)
	return n
}

// nsID identifies a process's network namespace (the /proc/<pid>/ns/net link, "net:[4026531840]"); "" when unreadable.
func nsID(fs hostfs.FS, pid int) string {
	l, err := fs.Readlink("/proc/" + strconv.Itoa(pid) + "/ns/net")
	if err != nil {
		return ""
	}
	return l
}
