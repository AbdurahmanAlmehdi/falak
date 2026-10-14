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

// End reasons of a run that didn't exit by itself.
const (
	endTimeout   = "timeout"
	endOutput    = "output limit reached"
	endCancelled = "cancelled"
)

// ExitCancelled is the exit code of a run the control plane (or the agent's shutdown) cancelled (as SIGINT).
const ExitCancelled = 130

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
	h, err := m.prepare(ctx, p)
	if err != nil {
		return nil, err
	}
	defer h.close()
	m.log.Info("net tool", "tool", p.Tool, "namespace", h.namespace)
	code, end, err := m.exec(ctx, h, time.Duration(p.TimeoutS)*time.Second, st.Stdout())
	if err != nil {
		return nil, err
	}
	if end == "" && code == ExitTimeout {
		end = endTimeout
	}
	switch {
	case end == endTimeout:
		return nil, &commands.ExitError{Code: ExitTimeout, Err: fmt.Errorf("%s stopped after %ds (timeout)", p.Tool, p.TimeoutS)}
	case end == endOutput:
		return nil, &commands.ExitError{Code: 1, Err: fmt.Errorf("%s stopped: output limit reached (%d KiB)", p.Tool, m.opts.MaxOutput>>10)}
	case end == endCancelled:
		return nil, &commands.ExitError{Code: ExitCancelled, Err: fmt.Errorf("%s cancelled", p.Tool)}
	case code == ExitNotInstalled:
		return nil, &commands.ExitError{Code: code, Err: fmt.Errorf("%s is not installed on this server", p.Tool)}
	case code != 0:
		return nil, &commands.ExitError{Code: code, Err: fmt.Errorf("%s exited with %d", p.Tool, code)}
	}
	return Result{ExitCode: 0, Namespace: h.namespace}, nil
}

// helper is how one run starts: argv, the spec for its stdin, and for a container the open network namespace it
// enters (fd 3 in the child).
type helper struct {
	argv      []string
	spec      []byte
	netns     *os.File
	namespace string
}

func (h *helper) close() {
	if h.netns != nil {
		h.netns.Close()
	}
}

// prepare builds the helper: on the host, or under nsenter in a container's network namespace. The namespace is
// opened first and pinned by its descriptor (nsenter enters /proc/self/fd/3, never a pid path); only then is the
// process checked to be the container's (its cgroup names the container id, and a second inspect shows the same pid
// and start), so a pid reused in between is refused instead of entering another process's network. The container's
// resolv.conf and hosts come from Docker's host-side files, read safely, and go to the helper as contents.
func (m *Manager) prepare(ctx context.Context, p Spec) (*helper, error) {
	hs := HelperSpec{Spec: p}
	hs.Container = nil
	if p.Container == nil {
		b, _ := json.Marshal(hs)
		return &helper{argv: []string{m.opts.Binary, HelperCommand}, spec: b, namespace: "host"}, nil
	}
	if m.opts.Docker == nil {
		return nil, errors.New("container targets need Docker on this server")
	}
	nsenter, err := exec.LookPath(m.opts.NSEnter)
	if err != nil {
		return nil, errors.New("nsenter (util-linux) is not installed on this server")
	}
	lctx, cancel := context.WithTimeout(ctx, 15*time.Second)
	defer cancel()
	ct, err := resolveContainer(lctx, m.opts.Docker, p.Container)
	if err != nil {
		return nil, err
	}
	pid := ct.State.Pid
	proc := filepath.Join(m.opts.ProcRoot, strconv.Itoa(pid))
	netns, err := os.Open(filepath.Join(proc, "ns", "net"))
	if err != nil {
		return nil, ErrNoContainer
	}
	refuse := func(err error) (*helper, error) {
		netns.Close()
		return nil, err
	}
	cg, err := os.ReadFile(filepath.Join(proc, "cgroup"))
	if err != nil || ct.ID == "" || !strings.Contains(string(cg), ct.ID) {
		return refuse(fmt.Errorf("pid %d is not container %s's process (it exited?)", pid, ct.name()))
	}
	again, ok, err := m.opts.Docker.ContainerInspect(lctx, ct.ID)
	if err != nil || !ok || !again.State.Running || again.State.Pid != pid || again.State.StartedAt != ct.State.StartedAt {
		return refuse(fmt.Errorf("container %s restarted while the tool started; run it again", ct.name()))
	}
	if ct.ResolvConfPath == "" {
		return refuse(fmt.Errorf("container %s has no resolv.conf", ct.name()))
	}
	if hs.ResolvConf, err = readRegularFile(ct.ResolvConfPath); err != nil {
		return refuse(fmt.Errorf("container %s's resolv.conf: %w", ct.name(), err))
	}
	if ct.HostsPath != "" {
		if hs.Hosts, err = readRegularFile(ct.HostsPath); err != nil {
			return refuse(fmt.Errorf("container %s's hosts file: %w", ct.name(), err))
		}
	}
	hs.InContainer = true
	b, _ := json.Marshal(hs)
	return &helper{
		argv: []string{nsenter, "--net=/proc/self/fd/3", "--", m.opts.Binary, HelperCommand},
		spec: b, netns: netns, namespace: "container:" + ct.name(),
	}, nil
}

type inspected struct{ *docker.Container }

func (c inspected) name() string { return strings.TrimPrefix(c.Name, "/") }

// resolveContainer inspects the container (by name, or the newest running one with all labels); it must run.
func resolveContainer(ctx context.Context, d ContainerLookup, t *Container) (inspected, error) {
	ref := t.Name
	if ref == "" {
		filters := make([]string, 0, len(t.Labels))
		for k, v := range t.Labels {
			filters = append(filters, k+"="+v)
		}
		sort.Strings(filters)
		list, err := d.ContainerList(ctx, false, filters)
		if err != nil {
			return inspected{}, fmt.Errorf("list containers: %w", err)
		}
		var running []docker.ContainerSummary
		for _, c := range list {
			if c.State == "running" && hasLabels(c.Labels, t.Labels) {
				running = append(running, c)
			}
		}
		if len(running) == 0 {
			return inspected{}, ErrNoContainer
		}
		ref = slices.MaxFunc(running, func(a, b docker.ContainerSummary) int {
			return cmp.Or(cmp.Compare(a.Created, b.Created), strings.Compare(a.ID, b.ID))
		}).ID
	}
	ct, ok, err := d.ContainerInspect(ctx, ref)
	if err != nil {
		return inspected{}, fmt.Errorf("inspect container: %w", err)
	}
	if !ok || !ct.State.Running || ct.State.Pid <= 0 {
		return inspected{}, ErrNoContainer
	}
	return inspected{ct}, nil
}

func hasLabels(have, want map[string]string) bool {
	for k, v := range want {
		if have[k] != v {
			return false
		}
	}
	return true
}

// exec runs the helper under a PTY for its output (system tools line-buffer on a terminal; the spec goes on stdin),
// copies the output to w with CRLF folded to LF, and kills the process group past the timeout (plus a grace for the
// helper's own timeout to report), on cancellation or at the output cap. end says why it was killed ("" when it exited
// by itself).
func (m *Manager) exec(ctx context.Context, h *helper, timeout time.Duration, w io.Writer) (code int, end string, err error) {
	cmd := exec.Command(h.argv[0], h.argv[1:]...)
	cmd.Env = append([]string{"PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin", "LANG=C.UTF-8", "TERM=dumb"}, m.opts.Env...)
	cmd.Dir = "/"
	cmd.Stdin = bytes.NewReader(h.spec)
	if h.netns != nil {
		cmd.ExtraFiles = []*os.File{h.netns} // fd 3
	}
	// Stdout (fd 1) is the PTY and becomes the controlling terminal; stdin is the spec.
	f, err := cpty.StartWithAttrs(cmd, &cpty.Winsize{Cols: 160, Rows: 50}, &syscall.SysProcAttr{Setsid: true, Setctty: true, Ctty: 1})
	if err != nil {
		return 0, "", fmt.Errorf("start the tool: %w", err)
	}
	defer f.Close()

	var (
		once   sync.Once
		mu     sync.Mutex
		reason string
	)
	kill := func(why string) {
		once.Do(func() {
			mu.Lock()
			reason = why
			mu.Unlock()
			_ = syscall.Kill(-cmd.Process.Pid, syscall.SIGKILL)
			_ = cmd.Process.Kill()
		})
	}
	readDone := make(chan struct{})
	go func() {
		defer close(readDone)
		copyOutput(f, w, m.opts.MaxOutput, func() { kill(endOutput) })
	}()
	exited := make(chan struct{})
	go func() { _ = cmd.Wait(); close(exited) }()

	timer := time.NewTimer(timeout + 5*time.Second)
	defer timer.Stop()
	select {
	case <-exited:
	case <-timer.C:
		kill(endTimeout)
		<-exited
	case <-ctx.Done():
		if errors.Is(ctx.Err(), context.DeadlineExceeded) {
			kill(endTimeout)
		} else {
			kill(endCancelled)
		}
		<-exited
	}
	// The PTY reports EIO once every holder of the slave is gone; don't wait forever for a grandchild that kept it.
	select {
	case <-readDone:
	case <-time.After(time.Second):
		kill("")
		f.Close()
		<-readDone
	}
	mu.Lock()
	end = reason
	mu.Unlock()
	code = cmd.ProcessState.ExitCode()
	if ws, ok := cmd.ProcessState.Sys().(syscall.WaitStatus); ok && ws.Signaled() {
		code = 128 + int(ws.Signal())
	}
	return code, end, nil
}

// crlf matches a line end as the PTY renders it: "\n" becomes "\r\n" (onlcr), so a tool's own "\r\n" arrives as
// "\r\r\n".
var crlf = regexp.MustCompile("\r+\n")

// copyOutput copies r to w with CRs before a newline dropped until EOF/EIO; past limit bytes it notes the
// truncation and calls stop.
func copyOutput(r io.Reader, w io.Writer, limit int, stop func()) {
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
				return
			}
			total += len(chunk)
			_, _ = w.Write(chunk)
		}
		if err != nil {
			return
		}
	}
}
