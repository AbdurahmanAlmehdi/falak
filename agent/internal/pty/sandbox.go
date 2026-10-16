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

// sandboxPIDFile is where runsc exec writes the shell's pid inside the sandbox (the session's id there).
func sandboxPIDFile(sessionID string) string {
	return filepath.Join(os.TempDir(), "falak-sbx-term-"+sessionID+".pid")
}

// sandboxCmd is `runsc exec` for the PTY.
func (m *Manager) sandboxCmd(p OpenPayload) *exec.Cmd {
	cmd := exec.Command(m.opts.Runsc, runscExecArgs(p.Sandbox.ID, sandboxPIDFile(p.SessionID), p.Env)...)
	home := rootHome()
	cmd.Dir = home
	cmd.Env = []string{"TERM=xterm-256color", "LANG=C.UTF-8", "HOME=" + home, "PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"}
	return cmd
}

// sandboxHangup signals every process of an in-sandbox session ($1: its id, $2: the signal), from /proc/*/stat
// (field 6 is the session).
const sandboxHangup = `sid=$1; sig=$2; for d in /proc/[0-9]*; do read -r l < "$d/stat" 2>/dev/null || continue; set -- ${l##*) }; [ "$4" = "$sid" ] && kill -"$sig" "${d#/proc/}" 2>/dev/null; done; true`

// hangUpSandbox ends what a sandbox session started: the sandbox holds the pty for as long as any of its processes
// do, and runsc exec going away doesn't end them, so the session's processes get SIGHUP, then SIGKILL.
func (m *Manager) hangUpSandbox(p OpenPayload) {
	pidFile := sandboxPIDFile(p.SessionID)
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
		ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
		out, err := exec.CommandContext(ctx, m.opts.Runsc, "--root=/run/falak-sbx", "exec", "falak-sbx-"+p.Sandbox.ID,
			"/bin/sh", "-c", sandboxHangup, "_", strconv.Itoa(sid), sig).CombinedOutput()
		cancel()
		if err != nil {
			m.log.Debug("sandbox console hangup", "session", p.SessionID, "err", err, "out", strings.TrimSpace(string(out)))
			return
		}
	}
}
