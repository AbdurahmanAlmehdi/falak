package pty

import (
	"errors"
	"fmt"
	"os/exec"
	"regexp"
	"strings"
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

// sandboxShell starts a login bash when the image has it, else sh.
const sandboxShell = "exec bash -l 2>/dev/null || exec sh -l"

// runscExecArgs: --root=/run/falak-sbx exec --env=TERM=xterm-256color [--env=K=V sorted] falak-sbx-<id>
// /bin/sh -lc <shell>. runsc exec has no -t flag: it gives the process a terminal when its stdio is a pty, as here.
func runscExecArgs(id string, env map[string]string) []string {
	args := []string{"--root=/run/falak-sbx", "exec", "--env=TERM=xterm-256color"}
	for _, kv := range sortedEnv(env) {
		if !strings.HasPrefix(kv, "TERM=") {
			args = append(args, "--env="+kv)
		}
	}
	return append(args, "falak-sbx-"+id, "/bin/sh", "-lc", sandboxShell)
}

// sandboxCmd is `runsc exec` for the PTY (a closed session SIGHUPs runsc exec, which forwards signals to the
// shell it started inside the sandbox).
func (m *Manager) sandboxCmd(p OpenPayload) *exec.Cmd {
	cmd := exec.Command(m.opts.Runsc, runscExecArgs(p.Sandbox.ID, p.Env)...)
	home := rootHome()
	cmd.Dir = home
	cmd.Env = []string{"TERM=xterm-256color", "LANG=C.UTF-8", "HOME=" + home, "PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"}
	return cmd
}
