package pty

import (
	"context"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"
)

// SandboxTarget is terminal.open's sandbox (docs/plans/SANDBOXES.md): a shell in runsc container falak-sbx-<id>.
type SandboxTarget struct {
	ID string `json:"id"`
}

var sandboxIDRe = regexp.MustCompile(`^[0-9a-z]{26}$`)

func (p OpenPayload) validateSandbox() error {
	if p.Container != nil {
		return errors.New("container and sandbox are exclusive")
	}
	if !sandboxIDRe.MatchString(p.Sandbox.ID) {
		return fmt.Errorf("sandbox: invalid id %q", p.Sandbox.ID)
	}
	return nil
}

// sandboxShell starts a login bash when the image has it, else sh. (Not `exec bash || exec sh`: a failed exec ends a
// non-interactive sh, so an image without bash got no shell at all.)
const sandboxShell = "command -v bash >/dev/null 2>&1 && exec bash -l; exec sh -l"

// runscExecArgs: --root=/run/falak-sbx exec --internal-pid-file=<pidFile> --env=TERM=xterm-256color [--env=K=V sorted]
// falak-sbx-<id> /bin/sh -lc <shell>. runsc exec has no -t flag: it gives the process a terminal when its stdio is a
// pty, as here.
func runscExecArgs(id, pidFile string, env map[string]string) []string {
	args := []string{"--root=/run/falak-sbx", "exec", "--internal-pid-file=" + pidFile, "--env=TERM=xterm-256color"}
	for _, kv := range sortedEnv(env) {
		if !strings.HasPrefix(kv, "TERM=") {
			args = append(args, "--env="+kv)
		}
	}
	return append(args, "falak-sbx-"+id, "/bin/sh", "-lc", sandboxShell)
}

// sandboxPIDFile is where runsc exec writes the shell's pid inside the sandbox (the session's id there): a root-only
// directory, never a world-writable one like /tmp, where anyone could plant a symlink for runsc (root) to write through
// or a pid of their choosing for the hang-up to signal.
func (m *Manager) sandboxPIDFile(sessionID string) string {
	return filepath.Join(m.opts.SandboxPIDDir, "sbx-"+sessionID+".pid")
}

// sandboxCmd is `runsc exec` for the PTY. The pid file's directory is made root-only (0700) when missing, and fixed when
// it isn't.
func (m *Manager) sandboxCmd(p OpenPayload) (*exec.Cmd, error) {
	if err := os.MkdirAll(m.opts.SandboxPIDDir, 0o700); err != nil {
		return nil, fmt.Errorf("sandbox console: %w", err)
	}
	if err := os.Chmod(m.opts.SandboxPIDDir, 0o700); err != nil {
		return nil, fmt.Errorf("sandbox console: %w", err)
	}
	pidFile := m.sandboxPIDFile(p.SessionID)
	_ = os.Remove(pidFile) // a stale one from a crashed agent
	cmd := exec.Command(m.opts.Runsc, runscExecArgs(p.Sandbox.ID, pidFile, p.Env)...)
	home := rootHome()
	cmd.Dir = home
	cmd.Env = []string{"TERM=xterm-256color", "LANG=C.UTF-8", "HOME=" + home, "PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"}
	return cmd, nil
}

// sandboxHangup signals every process of an in-sandbox session ($1: its id, $2: the signal), from /proc/*/stat
// (field 6 is the session).
const sandboxHangup = `sid=$1; sig=$2; for d in /proc/[0-9]*; do read -r l < "$d/stat" 2>/dev/null || continue; set -- ${l##*) }; [ "$4" = "$sid" ] && kill -"$sig" "${d#/proc/}" 2>/dev/null; done; true`

// hangupTimeout bounds one hang-up `runsc exec` (a var: tests shorten it).
var hangupTimeout = 10 * time.Second

// hangUpSandbox ends what a sandbox session started: the sandbox holds the pty for as long as any of its processes
// do, and runsc exec going away doesn't end them, so the session's processes get SIGHUP, then SIGKILL.
func (m *Manager) hangUpSandbox(p OpenPayload) {
	pidFile := m.sandboxPIDFile(p.SessionID)
	defer os.Remove(pidFile)
	b, err := os.ReadFile(pidFile)
	if err != nil {
		return
	}
	sid, err := strconv.Atoi(strings.TrimSpace(string(b)))
	if err != nil || sid < 2 {
		return
	}
	for i, sig := range []string{"HUP", "KILL"} {
		if i > 0 {
			time.Sleep(time.Second)
		}
		ctx, cancel := context.WithTimeout(context.Background(), hangupTimeout)
		cmd := exec.CommandContext(ctx, m.opts.Runsc, "--root=/run/falak-sbx", "exec", "falak-sbx-"+p.Sandbox.ID,
			"/bin/sh", "-c", sandboxHangup, "_", strconv.Itoa(sid), sig)
		// Killing runsc on the timeout doesn't close its output pipe while something it started still holds it:
		// without a WaitDelay, CombinedOutput (and the session's close) would wait for that forever.
		cmd.WaitDelay = 2 * time.Second
		out, err := cmd.CombinedOutput()
		cancel()
		if err != nil {
			m.log.Debug("sandbox console hangup", "session", p.SessionID, "err", err, "out", strings.TrimSpace(string(out)))
			return
		}
	}
}
