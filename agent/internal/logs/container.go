package logs

import (
	"context"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"log/slog"
	"os"
	"path"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/docker"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
)

// ContainerEngine is the Engine API subset container sources need (docker.Client): one list per refresh, one inspect
// per container (again after it restarted in place).
type ContainerEngine interface {
	ContainerList(ctx context.Context, all bool, labels []string) ([]docker.ContainerSummary, error)
	ContainerInspect(ctx context.Context, nameOrID string) (*docker.Container, bool, error)
}

// Limits of a container source.
const (
	// MaxFilesPerSource bounds the files one source matches in one container (a glob over a busy directory).
	MaxFilesPerSource = 256
	// maxLinks bounds the symlinks followed while resolving one path (ELOOP).
	maxLinks = 40
	// containerRefresh is how often the container list is read again (shared by every container source).
	containerRefresh = 10 * time.Second
)

// ContainerKeyPrefix starts the offset key of a file inside a container: container:<id>:<path in the container>.
const ContainerKeyPrefix = "container:"

func containerKey(id, inner string) string { return ContainerKeyPrefix + id + ":" + inner }

// logContainer is a running site container whose files container sources read.
type logContainer struct {
	id, name               string
	site, service, release string
	pid                    int
	pidStart               uint64
	root                   *os.Root  // the container's root (/proc/<pid>/root), opened once per pid
	created                time.Time // zero: unknown
	firstPass              bool      // not polled yet: files that already exist start at their end (unless fresh)
	dirs                   map[string]cachedDir
}

// cachedDir is a directory listing GlobIn reuses for dirCacheTTL: a glob is expanded on every poll (each second),
// a busy log directory is listed at most every few seconds.
type cachedDir struct {
	names []string
	at    time.Time
}

// Bounds of the directory work of a glob.
const (
	dirCacheTTL = 5 * time.Second
	// maxDirEntries bounds the entries read from one directory (a directory with millions of files).
	maxDirEntries = 4096
)

// fresh tells whether the container was created moments ago (a deploy), checked when a file is first seen: its files
// are read from the start, so its first lines are kept. A source added later to the same container is not.
func (c *logContainer) fresh(now time.Time) bool {
	return !c.created.IsZero() && now.Sub(c.created) < freshContainer
}

// lister lists a directory of the container through its root, cached for dirCacheTTL.
func (c *logContainer) lister(now time.Time) func(dir string) []string {
	return func(dir string) []string {
		if d, ok := c.dirs[dir]; ok && now.Sub(d.at) < dirCacheTTL {
			return d.names
		}
		names := readDirNames(c.root, dir)
		if c.dirs == nil || len(c.dirs) > 1024 {
			c.dirs = map[string]cachedDir{}
		}
		c.dirs[dir] = cachedDir{names: names, at: now}
		return names
	}
}

// attrs are the record attributes of the container's files: those of its stdout (docker.go), so both filter alike.
func (c *logContainer) attrs() map[string]string {
	a := map[string]string{"container.id": c.id, "container.name": c.name}
	if c.service != "" {
		a["falak.compose.service"] = c.service
		if c.release != "" {
			a["falak.release.id"] = c.release
		}
	}
	return a
}

// containerSet keeps the running site containers and their roots. Not safe for concurrent use: the tailer holds its
// lock around every call.
type containerSet struct {
	engine ContainerEngine
	socket string // literal path; checked before each list (Docker may be installed after the agent started)
	fs     hostfs.FS
	log    *slog.Logger
	now    func() time.Time
	every  time.Duration

	listed time.Time
	byID   map[string]*logContainer
}

func newContainerSet(engine ContainerEngine, socket string, fsys hostfs.FS, log *slog.Logger) *containerSet {
	return &containerSet{engine: engine, socket: socket, fs: fsys, log: log, now: time.Now, every: containerRefresh, byID: map[string]*logContainer{}}
}

// refresh lists the running site containers when the last list is older than the refresh interval, opens the root
// of new ones and of those restarted in place (their init pid changed), and closes those that are gone. It returns
// the ids of the containers that went away (their offsets are dropped) and of those whose root was opened again (their
// open files are closed, offsets kept, and reopened through the new root).
func (s *containerSet) refresh(ctx context.Context) (gone, reopened []string) {
	now := s.now()
	if !s.listed.IsZero() && now.Sub(s.listed) < s.every {
		return nil, nil
	}
	s.listed = now
	if s.socket != "" {
		if _, err := os.Stat(s.socket); err != nil {
			return s.dropAll(), nil
		}
	}
	cs, err := s.engine.ContainerList(ctx, false, []string{labelSite})
	if err != nil {
		// Keep what is open: a transient Engine API failure must not drop every offset.
		s.log.Debug("container log sources: docker list failed", "err", err)
		return nil, nil
	}
	live := map[string]bool{}
	for _, sum := range cs {
		site := sum.Labels[labelSite]
		if site == "" || !slugRE.MatchString(site) {
			continue
		}
		live[sum.ID] = true
		c := s.byID[sum.ID]
		if c != nil && c.root != nil && s.pidAlive(c) {
			continue
		}
		if c == nil {
			name := sum.ID[:min(12, len(sum.ID))]
			if len(sum.Names) > 0 {
				name = strings.TrimPrefix(sum.Names[0], "/")
			}
			c = &logContainer{id: sum.ID, name: name, site: site, service: sum.Labels[labelService], release: sum.Labels["falak.release"], firstPass: true}
			if sum.Created > 0 {
				c.created = time.Unix(sum.Created, 0)
			}
			s.byID[sum.ID] = c
		} else {
			reopened = append(reopened, c.id)
		}
		if err := s.open(ctx, c); err != nil {
			s.log.Debug("container log sources: cannot open the container's root", "container", c.name, "err", err)
		}
	}
	for id, c := range s.byID {
		if !live[id] {
			c.close()
			delete(s.byID, id)
			gone = append(gone, id)
		}
	}
	return gone, reopened
}

// open inspects c for its init pid and opens /proc/<pid>/root. The pid may have exited and been reused (by any
// process, possibly a host one) between the inspect and the open, so after the open the container is inspected again
// and must still run with the same pid and start (StartedAt), and the pid's start time must not have changed either.
// A root that is the host's own (same directory as the host's "/", or the host's mount namespace) is refused. Once
// open, the root stays pinned to the container's filesystem whatever happens to the pid.
func (s *containerSet) open(ctx context.Context, c *logContainer) error {
	c.close()
	info, found, err := s.engine.ContainerInspect(ctx, c.id)
	if err != nil {
		return err
	}
	if !found || !info.State.Running || info.State.Pid <= 0 {
		return fmt.Errorf("not running")
	}
	pid := info.State.Pid
	proc := "/proc/" + strconv.Itoa(pid)
	before, ok := procStart(s.fs, pid)
	if !ok {
		return fmt.Errorf("pid %d is gone", pid)
	}
	root, err := os.OpenRoot(s.fs.P(proc + "/root"))
	if err != nil {
		return err
	}
	refuse := func(format string, args ...any) error {
		root.Close()
		return fmt.Errorf(format, args...)
	}
	if after, ok := procStart(s.fs, pid); !ok || after != before {
		return refuse("pid %d was reused", pid)
	}
	again, found, err := s.engine.ContainerInspect(ctx, c.id)
	if err != nil || !found || !again.State.Running || again.State.Pid != pid || again.State.StartedAt != info.State.StartedAt {
		return refuse("container %s changed while its root was opened", c.name)
	}
	if fi, err := root.Stat("."); err != nil {
		return refuse("container root: %v", err)
	} else if host, err := os.Stat(s.fs.P("/")); err == nil && os.SameFile(fi, host) {
		return refuse("pid %d runs in the host's root", pid)
	}
	if ns, err := s.fs.Readlink(proc + "/ns/mnt"); err == nil {
		if hostNS, err := s.fs.Readlink("/proc/1/ns/mnt"); err == nil && ns == hostNS {
			return refuse("pid %d runs in the host's mount namespace", pid)
		}
	}
	c.pid, c.pidStart, c.root, c.dirs = pid, before, root, nil
	return nil
}

// pidAlive tells whether c's init process is still the one its root was opened through (a container restarted in
// place keeps its id and gets a new pid and a new root mount).
func (s *containerSet) pidAlive(c *logContainer) bool {
	start, ok := procStart(s.fs, c.pid)
	return ok && start == c.pidStart
}

func (s *containerSet) dropAll() (gone []string) {
	for id, c := range s.byID {
		c.close()
		delete(s.byID, id)
		gone = append(gone, id)
	}
	return gone
}

// matching returns the open containers of a site (and compose service, when set), by id.
func (s *containerSet) matching(site, service string) []*logContainer {
	var out []*logContainer
	for _, c := range s.byID {
		if c.root != nil && c.site == site && (service == "" || c.service == service) {
			out = append(out, c)
		}
	}
	sort.Slice(out, func(i, j int) bool { return out[i].id < out[j].id })
	return out
}

func (c *logContainer) close() {
	if c.root != nil {
		c.root.Close()
		c.root = nil
	}
}

// procStart is a process's start time (field 22 of /proc/<pid>/stat, after the parenthesised command).
func procStart(fsys hostfs.FS, pid int) (uint64, bool) {
	b, err := fsys.ReadFile("/proc/" + strconv.Itoa(pid) + "/stat")
	if err != nil {
		return 0, false
	}
	s := string(b)
	i := strings.LastIndexByte(s, ')')
	if i < 0 {
		return 0, false
	}
	f := strings.Fields(s[i+1:])
	if len(f) < 20 {
		return 0, false
	}
	v, err := strconv.ParseUint(f[19], 10, 64)
	return v, err == nil
}

// Labels container sources select by (those of the Docker log follower and the workload collector).
const (
	labelSite    = "falak.site"
	labelService = "falak.service"
)

// rootRel turns a clean absolute in-container path into the name os.Root takes (relative; "." for the root).
func rootRel(p string) string {
	if p = strings.TrimPrefix(p, "/"); p == "" {
		return "."
	}
	return p
}

// ResolveIn resolves every symlink of the absolute path p as the container sees it: an absolute link target starts
// again at the container's root, ".." stops at it. The result names a path inside root that has no symlink left (it
// may not exist). Lstat and Readlink go through the os.Root, so nothing here can reach outside it either.
func ResolveIn(root *os.Root, p string) (string, error) {
	rest := strings.Split(path.Clean("/"+p), "/")
	cur := "/"
	for links := 0; len(rest) > 0; {
		name := rest[0]
		rest = rest[1:]
		switch name {
		case "", ".":
			continue
		case "..":
			cur = path.Dir(cur) // path.Dir("/") is "/": clamped at the container's root
			continue
		}
		next := path.Join(cur, name)
		fi, err := root.Lstat(rootRel(next))
		if err != nil {
			return "", err
		}
		if fi.Mode()&fs.ModeSymlink == 0 {
			cur = next
			continue
		}
		if links++; links > maxLinks {
			return "", fmt.Errorf("%s: too many levels of symbolic links", p)
		}
		target, err := root.Readlink(rootRel(next))
		if err != nil {
			return "", err
		}
		if strings.HasPrefix(target, "/") {
			cur = "/"
		}
		rest = append(strings.Split(target, "/"), rest...)
	}
	return cur, nil
}

func hasMeta(s string) bool { return strings.ContainsAny(s, `*?[\`) }

// PseudoPath tells whether an in-container path is under /proc, /sys or /dev: kernel state, not logs (a link to
// /proc/kmsg would drain the host's kernel log, /proc/kallsyms shows kernel addresses to a root reader). Such paths are
// never read, whatever links lead to them.
func PseudoPath(p string) bool {
	first, _, _ := strings.Cut(strings.TrimPrefix(path.Clean("/"+p), "/"), "/")
	return first == "proc" || first == "sys" || first == "dev"
}

// GlobIn expands the absolute glob pattern inside root, one path segment at a time, resolving symlinks in the root
// (ResolveIn). It returns the symlink-free in-container paths of the regular files it matches, sorted, at most limit.
// Paths under /proc, /sys and /dev are left out (PseudoPath).
func GlobIn(root *os.Root, pattern string, limit int) []string {
	return globIn(root, pattern, limit, func(dir string) []string { return readDirNames(root, dir) })
}

// globIn is GlobIn with the directory listing given (a container caches its listings for dirCacheTTL).
func globIn(root *os.Root, pattern string, limit int, list func(dir string) []string) []string {
	segs := strings.Split(strings.Trim(path.Clean("/"+pattern), "/"), "/")
	if len(segs) == 0 || segs[0] == "" {
		return nil
	}
	cur := []string{"/"}
	for i, seg := range segs {
		last := i == len(segs)-1
		seen := map[string]bool{}
		var next []string
		for _, dir := range cur {
			names := []string{seg}
			if hasMeta(seg) {
				names = nil
				for _, n := range list(dir) {
					if ok, _ := path.Match(seg, n); ok {
						names = append(names, n)
					}
				}
			}
			for _, name := range names {
				p, err := ResolveIn(root, path.Join(dir, name))
				if err != nil || seen[p] || PseudoPath(p) {
					continue
				}
				fi, err := root.Stat(rootRel(p))
				if err != nil || (last && !fi.Mode().IsRegular()) || (!last && !fi.IsDir()) {
					continue
				}
				seen[p] = true
				next = append(next, p)
				if len(next) >= limit {
					break
				}
			}
			if len(next) >= limit {
				break
			}
		}
		if len(next) == 0 {
			return nil
		}
		sort.Strings(next)
		cur = next
	}
	return cur
}

// readDirNames lists the entries of dir (a resolved in-container directory), sorted, at most maxDirEntries of them.
func readDirNames(root *os.Root, dir string) []string {
	f, err := root.Open(rootRel(dir))
	if err != nil {
		return nil
	}
	defer f.Close()
	names, err := f.Readdirnames(maxDirEntries)
	if err != nil && !errors.Is(err, io.EOF) && len(names) == 0 {
		return nil
	}
	sort.Strings(names)
	return names
}
