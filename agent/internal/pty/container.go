package pty

import (
	"bytes"
	"cmp"
	"context"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"slices"
	"sort"
	"strconv"
	"strings"
	"syscall"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/docker"
)

// ContainerTarget is terminal.open's container (exactly one of Name and Labels).
type ContainerTarget struct {
	// Name: the container must exist and run (database consoles: falak-db-<id>).
	Name string `json:"name,omitempty"`
	// Labels: the newest running container carrying all of them (sites: falak.site, compose project + service).
	Labels map[string]string `json:"labels,omitempty"`
	// User and Workdir are docker exec's -u and -w.
	User    string `json:"user,omitempty"`
	Workdir string `json:"workdir,omitempty"`
}

// ContainerLookup is the part of the Docker API container sessions use (*docker.Client).
type ContainerLookup interface {
	ContainerList(ctx context.Context, all bool, labels []string) ([]docker.ContainerSummary, error)
	ContainerInspect(ctx context.Context, nameOrID string) (*docker.Container, bool, error)
}

// ErrNoContainer fails a container session whose target isn't running.
var ErrNoContainer = errors.New("No running container for this console (is the site deployed?)") //nolint:staticcheck // shown to users as is

// shellProbe runs bash when the image has it, else sh.
const shellProbe = "if command -v bash >/dev/null 2>&1; then exec bash; else exec sh; fi"

var (
	containerNameRe = regexp.MustCompile(`^[a-zA-Z0-9][a-zA-Z0-9_.-]+$`)
	labelKeyRe      = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$`)
	labelValueRe    = regexp.MustCompile(`^[A-Za-z0-9_.:/@+-]{1,255}$`)
	execUserRe      = regexp.MustCompile(`^[a-zA-Z0-9_][a-zA-Z0-9_.-]{0,63}(:[a-zA-Z0-9_][a-zA-Z0-9_.-]{0,63})?$`)
)

const maxLabels = 8

func (p OpenPayload) validate() error {
	if p.Sandbox != nil {
		return p.validateSandbox()
	}
	t := p.Container
	if t == nil {
		return nil
	}
	switch {
	case (t.Name == "") == (len(t.Labels) == 0):
		return errors.New("container: exactly one of name and labels")
	case t.Name != "" && !containerNameRe.MatchString(t.Name):
		return fmt.Errorf("container: invalid name %q", t.Name)
	case len(t.Labels) > maxLabels:
		return fmt.Errorf("container: at most %d labels", maxLabels)
	case t.User != "" && !execUserRe.MatchString(t.User):
		return fmt.Errorf("container: invalid user %q", t.User)
	case t.Workdir != "" && (!strings.HasPrefix(t.Workdir, "/") || strings.ContainsAny(t.Workdir, "\x00\r\n")):
		return fmt.Errorf("container: invalid workdir %q", t.Workdir)
	}
	for k, v := range t.Labels {
		if !labelKeyRe.MatchString(k) || !labelValueRe.MatchString(v) {
			return fmt.Errorf("container: invalid label %q=%q", k, v)
		}
	}
	return nil
}

// containerCmd resolves the target and returns `docker exec -it …` for the PTY (run as root, like the agent).
func (m *Manager) containerCmd(ctx context.Context, p OpenPayload) (*exec.Cmd, error) {
	if m.opts.Docker == nil {
		return nil, errors.New("container consoles need Docker on this server")
	}
	lctx, cancel := context.WithTimeout(ctx, 15*time.Second)
	defer cancel()
	ref, err := resolveContainer(lctx, m.opts.Docker, p.Container)
	if err != nil {
		return nil, err
	}
	cmd := exec.Command(m.opts.DockerCLI, dockerExecArgs(p.SessionID, ref, p.Container, p.Env)...)
	home := rootHome()
	cmd.Dir = home
	cmd.Env = []string{"TERM=xterm-256color", "LANG=C.UTF-8", "HOME=" + home, "PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"}
	return cmd, nil
}

// resolveContainer returns the container to exec into: Name when it runs, else the newest running container
// carrying all of Labels.
func resolveContainer(ctx context.Context, d ContainerLookup, t *ContainerTarget) (string, error) {
	if t.Name != "" {
		ct, ok, err := d.ContainerInspect(ctx, t.Name)
		if err != nil {
			return "", fmt.Errorf("inspect container %s: %w", t.Name, err)
		}
		if !ok || !ct.State.Running {
			return "", ErrNoContainer
		}
		return t.Name, nil
	}
	filters := make([]string, 0, len(t.Labels))
	for k, v := range t.Labels {
		filters = append(filters, k+"="+v)
	}
	sort.Strings(filters)
	list, err := d.ContainerList(ctx, false, filters)
	if err != nil {
		return "", fmt.Errorf("list containers: %w", err)
	}
	var running []docker.ContainerSummary
	for _, c := range list {
		// The API filters too; checked again so a fake (or an older daemon) can't widen the match.
		if c.State == "running" && hasLabels(c.Labels, t.Labels) {
			running = append(running, c)
		}
	}
	if len(running) == 0 {
		return "", ErrNoContainer
	}
	newest := slices.MaxFunc(running, func(a, b docker.ContainerSummary) int {
		return cmp.Or(cmp.Compare(a.Created, b.Created), strings.Compare(a.ID, b.ID))
	})
	return newest.ID, nil
}

func hasLabels(have, want map[string]string) bool {
	for k, v := range want {
		if have[k] != v {
			return false
		}
	}
	return true
}

// sessionEnv marks every process of a container session (inherited by the shell's children): a closed session's
// processes are found by it in the host's /proc.
const sessionEnv = "FALAK_CONSOLE_SESSION"

// containerKillGrace is how long a container session's processes have after SIGHUP before SIGKILL.
var containerKillGrace = 3 * time.Second

// dockerExecArgs: exec -it -e TERM=xterm-256color -e FALAK_CONSOLE_SESSION=<sid> [-e K=V sorted] [-u user]
// [-w workdir] <container> sh -c <probe>.
func dockerExecArgs(sid, container string, t *ContainerTarget, env map[string]string) []string {
	args := []string{"exec", "-it", "-e", "TERM=xterm-256color", "-e", sessionEnv + "=" + sid}
	for _, kv := range sortedEnv(env) {
		if !strings.HasPrefix(kv, sessionEnv+"=") {
			args = append(args, "-e", kv)
		}
	}
	if t.User != "" {
		args = append(args, "-u", t.User)
	}
	if t.Workdir != "" {
		args = append(args, "-w", t.Workdir)
	}
	return append(args, container, "sh", "-c", shellProbe)
}

// hangUpContainer SIGHUPs the processes of a closed container session (their environ holds
// FALAK_CONSOLE_SESSION=<sid>; the agent is root on the host, which sees containers' processes), then SIGKILLs
// whatever is left after containerKillGrace.
func (m *Manager) hangUpContainer(sid string) {
	marker := []byte(sessionEnv + "=" + sid)
	pids := sessionPIDs(m.opts.ProcRoot, marker)
	if len(pids) == 0 {
		return
	}
	for _, pid := range pids {
		_ = m.opts.Signal(pid, syscall.SIGHUP)
	}
	deadline := time.Now().Add(containerKillGrace)
	for time.Now().Before(deadline) {
		if pids = sessionPIDs(m.opts.ProcRoot, marker); len(pids) == 0 {
			return
		}
		time.Sleep(min(100*time.Millisecond, containerKillGrace/10))
	}
	left := sessionPIDs(m.opts.ProcRoot, marker)
	for _, pid := range left {
		_ = m.opts.Signal(pid, syscall.SIGKILL)
	}
	if len(left) > 0 {
		m.log.Info("killed container console processes that ignored SIGHUP", "session", sid, "count", len(left))
	}
}

// sessionPIDs lists the processes under procRoot whose environment has exactly the entry marker.
func sessionPIDs(procRoot string, marker []byte) []int {
	ents, err := os.ReadDir(procRoot)
	if err != nil {
		return nil
	}
	var pids []int
	for _, e := range ents {
		pid, err := strconv.Atoi(e.Name())
		if err != nil || pid <= 1 {
			continue
		}
		b, err := os.ReadFile(filepath.Join(procRoot, e.Name(), "environ"))
		if err != nil {
			continue // gone, or a kernel thread
		}
		for _, kv := range bytes.Split(b, []byte{0}) {
			if bytes.Equal(kv, marker) {
				pids = append(pids, pid)
				break
			}
		}
	}
	return pids
}
