package nettool

import (
	"bytes"
	"cmp"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"slices"
	"sort"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"

	cpty "github.com/creack/pty"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/docker"
)

// ContainerLookup is the part of the Docker API used to find a container's init process (*docker.Client).
type ContainerLookup interface {
	ContainerList(ctx context.Context, all bool, labels []string) ([]docker.ContainerSummary, error)
	ContainerInspect(ctx context.Context, nameOrID string) (*docker.Container, bool, error)
}

// ErrNoContainer fails a run whose container isn't running.
var ErrNoContainer = errors.New("No running container to run the tool in (is it deployed?)") //nolint:staticcheck // shown to users as is

// Options configures the manager.
type Options struct {
	Logger *slog.Logger
	Docker ContainerLookup
	// Binary is the agent executable re-run as the helper (`<Binary> nettool <spec>`).
	Binary string
	// NSEnter is util-linux nsenter (default "nsenter", looked up on PATH).
	NSEnter string
	// ProcRoot is the host's /proc (default "/proc").
	ProcRoot string
	// MaxRunning bounds concurrent runs (default 4); MaxOutput bounds a run's output (default 256 KiB).
	MaxRunning int
	MaxOutput  int
	// Env is added to the helper's environment (tests).
	Env []string
}

// Manager runs net.tool commands.
type Manager struct {
	opts Options
	log  *slog.Logger
	sem  chan struct{}
}

// New creates a Manager.
func New(o Options) *Manager {
	if o.Logger == nil {
		o.Logger = slog.Default()
	}
	if o.NSEnter == "" {
		o.NSEnter = "nsenter"
	}
	if o.ProcRoot == "" {
		o.ProcRoot = "/proc"
	}
	if o.MaxRunning <= 0 {
		o.MaxRunning = 4
	}
	if o.MaxOutput <= 0 {
		o.MaxOutput = 256 << 10
	}
	if o.Binary == "" {
		o.Binary, _ = os.Executable()
	}
	return &Manager{opts: o, log: o.Logger.With("component", "nettool"), sem: make(chan struct{}, o.MaxRunning)}
}

// Result is net.tool's result for a tool that exited 0 (others fail the command with their exit code).
type Result struct {
	ExitCode  int    `json:"exit_code"`
	TimedOut  bool   `json:"timed_out"`
	Namespace string `json:"namespace"`
}

// Register adds net.tool.
func (m *Manager) Register(reg *commands.Registry) {
	reg.Register("net.tool", commands.Typed(m.Run))
}

// Run validates the spec, enters the namespace and streams the tool's output as stdout events.
func (m *Manager) Run(ctx context.Context, p Spec, st commands.Stream) (any, error) {
	if err := p.Validate(); err != nil {
		return nil, &commands.PayloadError{Err: err}
	}
	select {
	case m.sem <- struct{}{}:
		defer func() { <-m.sem }()
	default:
		return nil, fmt.Errorf("too many network tools running (max %d)", m.opts.MaxRunning)
	}
	argv, namespace, err := m.argv(ctx, p)
	if err != nil {
		return nil, err
	}
	m.log.Info("net tool", "tool", p.Tool, "namespace", namespace)
	code, timedOut, err := m.exec(ctx, argv, time.Duration(p.TimeoutS)*time.Second, st.Stdout())
	if err != nil {
		return nil, err
	}
	if code == ExitTimeout {
		timedOut = true
	}
	switch {
	case timedOut:
		return nil, &commands.ExitError{Code: ExitTimeout, Err: fmt.Errorf("%s stopped after %ds (timeout)", p.Tool, p.TimeoutS)}
	case code == ExitNotInstalled:
		return nil, &commands.ExitError{Code: code, Err: fmt.Errorf("%s is not installed on this server", p.Tool)}
	case code != 0:
		return nil, &commands.ExitError{Code: code, Err: fmt.Errorf("%s exited with %d", p.Tool, code)}
	}
	return Result{ExitCode: 0, Namespace: namespace}, nil
}

// argv is the helper's command line: on the host, or under nsenter in a container's network namespace with the
// container's resolv.conf and hosts file.
func (m *Manager) argv(ctx context.Context, p Spec) ([]string, string, error) {
	hs := HelperSpec{Spec: p}
	hs.Container = nil
	if p.Container == nil {
		b, _ := json.Marshal(hs)
		return []string{m.opts.Binary, HelperCommand, string(b)}, "host", nil
	}
	if m.opts.Docker == nil {
		return nil, "", errors.New("container targets need Docker on this server")
	}
	nsenter, err := exec.LookPath(m.opts.NSEnter)
	if err != nil {
		return nil, "", errors.New("nsenter (util-linux) is not installed on this server")
	}
	lctx, cancel := context.WithTimeout(ctx, 15*time.Second)
	defer cancel()
	name, pid, err := containerPID(lctx, m.opts.Docker, p.Container)
	if err != nil {
		return nil, "", err
	}
	root := filepath.Join(m.opts.ProcRoot, strconv.Itoa(pid))
	hs.ResolvConf = filepath.Join(root, "root", "etc", "resolv.conf")
	hs.HostsFile = filepath.Join(root, "root", "etc", "hosts")
	b, _ := json.Marshal(hs)
	return []string{nsenter, "--net=" + filepath.Join(root, "ns", "net"), "--", m.opts.Binary, HelperCommand, string(b)}, "container:" + name, nil
}

// containerPID resolves the container (by name, or the newest running one with all labels) to its name and init pid.
func containerPID(ctx context.Context, d ContainerLookup, t *Container) (string, int, error) {
	ref := t.Name
	if ref == "" {
		filters := make([]string, 0, len(t.Labels))
		for k, v := range t.Labels {
			filters = append(filters, k+"="+v)
		}
		sort.Strings(filters)
		list, err := d.ContainerList(ctx, false, filters)
		if err != nil {
			return "", 0, fmt.Errorf("list containers: %w", err)
		}
		var running []docker.ContainerSummary
		for _, c := range list {
			if c.State == "running" && hasLabels(c.Labels, t.Labels) {
				running = append(running, c)
			}
		}
		if len(running) == 0 {
			return "", 0, ErrNoContainer
		}
		ref = slices.MaxFunc(running, func(a, b docker.ContainerSummary) int {
			return cmp.Or(cmp.Compare(a.Created, b.Created), strings.Compare(a.ID, b.ID))
		}).ID
	}
	ct, ok, err := d.ContainerInspect(ctx, ref)
	if err != nil {
		return "", 0, fmt.Errorf("inspect container: %w", err)
	}
	if !ok || !ct.State.Running || ct.State.Pid <= 0 {
		return "", 0, ErrNoContainer
	}
	return strings.TrimPrefix(ct.Name, "/"), ct.State.Pid, nil
}

func hasLabels(have, want map[string]string) bool {
	for k, v := range want {
		if have[k] != v {
			return false
		}
	}
	return true
}

// exec runs argv under a PTY (system tools line-buffer on a terminal), copies its output to w with CRLF folded to
// LF, and kills the process group past the timeout (plus a grace for the helper's own timeout to report) or the
// output cap.
func (m *Manager) exec(ctx context.Context, argv []string, timeout time.Duration, w io.Writer) (code int, timedOut bool, err error) {
	cmd := exec.Command(argv[0], argv[1:]...)
	cmd.Env = append([]string{"PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin", "LANG=C.UTF-8", "TERM=dumb"}, m.opts.Env...)
	cmd.Dir = "/"
	f, err := cpty.StartWithSize(cmd, &cpty.Winsize{Cols: 160, Rows: 50})
	if err != nil {
		return 0, false, fmt.Errorf("start the tool: %w", err)
	}
	defer f.Close()

	var once sync.Once
	kill := func() {
		once.Do(func() {
			_ = syscall.Kill(-cmd.Process.Pid, syscall.SIGKILL)
			_ = cmd.Process.Kill()
		})
	}
	readDone := make(chan bool, 1) // true: output cap reached
	go func() {
		readDone <- copyOutput(f, w, m.opts.MaxOutput, kill)
	}()
	exited := make(chan error, 1)
	go func() { exited <- cmd.Wait() }()

	timer := time.NewTimer(timeout + 5*time.Second)
	defer timer.Stop()
	var waitErr error
	select {
	case waitErr = <-exited:
	case <-timer.C:
		timedOut = true
		kill()
		waitErr = <-exited
	case <-ctx.Done():
		timedOut = true
		kill()
		waitErr = <-exited
	}
	// The PTY reports EIO once every holder of the slave is gone; don't wait forever for a grandchild that kept it.
	select {
	case <-readDone:
	case <-time.After(time.Second):
		kill()
		f.Close()
		<-readDone
	}
	if cmd.ProcessState == nil {
		return 0, timedOut, waitErr
	}
	code = cmd.ProcessState.ExitCode()
	if ws, ok := cmd.ProcessState.Sys().(syscall.WaitStatus); ok && ws.Signaled() {
		code = 128 + int(ws.Signal())
	}
	return code, timedOut, nil
}

// crlf matches a line end as the PTY renders it: "\n" becomes "\r\n" (onlcr), so a tool's own "\r\n" arrives as
// "\r\r\n".
var crlf = regexp.MustCompile("\r+\n")

// copyOutput copies r to w with CRs before a newline dropped until EOF/EIO; past limit bytes it notes the
// truncation, calls stop and returns true.
func copyOutput(r io.Reader, w io.Writer, limit int, stop func()) bool {
	buf := make([]byte, 16<<10)
	total := 0
	var pending []byte // trailing CRs of the last read: the "\n" they belong to may come with the next
	for {
		n, err := r.Read(buf)
		if n > 0 || (err != nil && len(pending) > 0) {
			chunk := append(pending, buf[:n]...)
			pending = nil
			if err == nil {
				trimmed := bytes.TrimRight(chunk, "\r")
				pending = append([]byte(nil), chunk[len(trimmed):]...)
				chunk = trimmed
			}
			chunk = crlf.ReplaceAll(chunk, []byte("\n"))
			if total+len(chunk) > limit {
				_, _ = w.Write(chunk[:limit-total])
				_, _ = io.WriteString(w, "\n… output truncated\n")
				stop()
				_, _ = io.Copy(io.Discard, r)
				return true
			}
			total += len(chunk)
			_, _ = w.Write(chunk)
		}
		if err != nil {
			return false
		}
	}
}
