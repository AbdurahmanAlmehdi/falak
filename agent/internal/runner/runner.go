// Package runner isolates every call to an OS tool (apt-get, systemctl, nft, mysql, ...) behind a small
// interface so executors are unit-testable with a fake (see runnertest).
package runner

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"os/user"
	"strconv"
	"strings"
	"syscall"
	"time"
)

// Cmd describes one process invocation.
type Cmd struct {
	Name  string
	Args  []string
	Env   []string  // KEY=VALUE, appended to the agent's environment (or to a minimal env when ClearEnv); last wins
	Dir   string    // working directory
	Stdin io.Reader // optional
	User  string    // run as this unix user (requires root); "" = agent user
	// UID/GID run the process as a numeric uid/gid without a passwd entry and no supplementary groups (requires
	// root; wins over User). 0 = unset.
	UID, GID uint32
	// Optional live streams. Output is always also captured (bounded) in Result.
	Stdout   io.Writer
	Stderr   io.Writer
	ClearEnv bool
}

// String renders the command line (for logs and fake matching).
func (c Cmd) String() string {
	return strings.TrimSpace(c.Name + " " + strings.Join(c.Args, " "))
}

// Result of a finished process.
type Result struct {
	ExitCode int
	Stdout   []byte
	Stderr   []byte
}

// Runner executes commands. Run returns err == nil when the process ran to completion, even with a
// non-zero exit code; use Check to turn a non-zero exit into an error.
type Runner interface {
	Run(ctx context.Context, c Cmd) (Result, error)
}

// ExitError is returned by Check for non-zero exits.
type ExitError struct {
	Cmd    string
	Code   int
	Stderr string
}

func (e *ExitError) Error() string {
	msg := strings.TrimSpace(e.Stderr)
	if len(msg) > 2048 {
		msg = "…" + msg[len(msg)-2048:]
	}
	return fmt.Sprintf("%s: exit status %d: %s", e.Cmd, e.Code, msg)
}

// Check runs c and returns an *ExitError when it exits non-zero.
func Check(ctx context.Context, r Runner, c Cmd) (Result, error) {
	res, err := r.Run(ctx, c)
	if err != nil {
		return res, err
	}
	if res.ExitCode != 0 {
		return res, &ExitError{Cmd: c.String(), Code: res.ExitCode, Stderr: string(res.Stderr)}
	}
	return res, nil
}

// Exec is the real Runner backed by os/exec.
type Exec struct {
	// CaptureLimit bounds captured stdout/stderr (per stream). Default 1 MiB.
	CaptureLimit int
}

const defaultCapture = 1 << 20

func (e Exec) Run(ctx context.Context, c Cmd) (Result, error) {
	limit := e.CaptureLimit
	if limit <= 0 {
		limit = defaultCapture
	}
	cmd := exec.CommandContext(ctx, c.Name, c.Args...)
	cmd.Dir = c.Dir
	cmd.Stdin = c.Stdin
	var env []string
	if c.ClearEnv {
		env = []string{"PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin", "LANG=C.UTF-8"}
	} else {
		env = os.Environ()
	}
	cmd.SysProcAttr = &syscall.SysProcAttr{Setpgid: true}
	if c.User != "" {
		cred, home, err := Credential(c.User)
		if err != nil {
			return Result{ExitCode: -1}, err
		}
		cmd.SysProcAttr.Credential = cred
		env = append(env, "HOME="+home, "USER="+c.User, "LOGNAME="+c.User)
	}
	if c.UID != 0 {
		cmd.SysProcAttr.Credential = &syscall.Credential{Uid: c.UID, Gid: c.GID} // nil Groups: setgroups(0)
	}
	cmd.Env = append(env, c.Env...)
	outBuf := &capBuf{limit: limit}
	errBuf := &capBuf{limit: limit}
	cmd.Stdout = tee(outBuf, c.Stdout)
	cmd.Stderr = tee(errBuf, c.Stderr)
	// Kill the whole process group on cancellation, then give pipes a moment to drain.
	cmd.Cancel = func() error {
		if cmd.Process != nil {
			_ = syscall.Kill(-cmd.Process.Pid, syscall.SIGKILL)
		}
		return nil
	}
	cmd.WaitDelay = 5 * time.Second
	err := cmd.Run()
	res := Result{Stdout: outBuf.Bytes(), Stderr: errBuf.Bytes()}
	var ee *exec.ExitError
	switch {
	case err == nil:
	case errors.As(err, &ee):
		res.ExitCode = ee.ExitCode()
		if ctx.Err() != nil {
			return res, ctx.Err()
		}
		return res, nil
	default:
		res.ExitCode = -1
		if ctx.Err() != nil {
			return res, ctx.Err()
		}
		return res, err
	}
	return res, nil
}

// Credential resolves a unix user to a syscall credential and home directory.
func Credential(name string) (*syscall.Credential, string, error) {
	u, err := user.Lookup(name)
	if err != nil {
		return nil, "", fmt.Errorf("lookup user %q: %w", name, err)
	}
	uid, _ := strconv.ParseUint(u.Uid, 10, 32)
	gid, _ := strconv.ParseUint(u.Gid, 10, 32)
	cred := &syscall.Credential{Uid: uint32(uid), Gid: uint32(gid)}
	if gids, err := u.GroupIds(); err == nil {
		for _, g := range gids {
			if n, err := strconv.ParseUint(g, 10, 32); err == nil {
				cred.Groups = append(cred.Groups, uint32(n))
			}
		}
	}
	return cred, u.HomeDir, nil
}

func tee(a io.Writer, b io.Writer) io.Writer {
	if b == nil {
		return a
	}
	return io.MultiWriter(a, b)
}

// capBuf keeps the last `limit` bytes written.
type capBuf struct {
	limit int
	buf   bytes.Buffer
}

func (c *capBuf) Write(p []byte) (int, error) {
	n := len(p)
	c.buf.Write(p)
	if over := c.buf.Len() - c.limit; over > 0 {
		b := c.buf.Bytes()[over:]
		nb := make([]byte, len(b))
		copy(nb, b)
		c.buf.Reset()
		c.buf.Write(nb)
	}
	return n, nil
}

func (c *capBuf) Bytes() []byte { return c.buf.Bytes() }
