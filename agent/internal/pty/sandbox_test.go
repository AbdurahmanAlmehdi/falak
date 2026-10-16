package pty

import (
	"os"
	"os/exec"
	"path/filepath"
	"sort"
	"strings"
	"testing"
)

func TestSandboxTarget(t *testing.T) {
	got := runscExecArgs("01j9z8y7x6w5v4t3s2r1q0p9na", "/tmp/x.pid", map[string]string{"B": "2", "A": "1 x", "TERM": "dumb"})
	want := []string{"--root=/run/falak-sbx", "exec", "--internal-pid-file=/tmp/x.pid", "--env=TERM=xterm-256color", "--env=A=1 x", "--env=B=2",
		"falak-sbx-01j9z8y7x6w5v4t3s2r1q0p9na", "/bin/sh", "-lc", "command -v bash >/dev/null 2>&1 && exec bash -l; exec sh -l"}
	if strings.Join(got, "|") != strings.Join(want, "|") {
		t.Fatalf("got  %q\nwant %q", got, want)
	}
	cmd := New(Options{Runsc: "/usr/bin/runsc"}).sandboxCmd(OpenPayload{SessionID: "sess-1234", Sandbox: &SandboxTarget{ID: "01j9z8y7x6w5v4t3s2r1q0p9na"}})
	if cmd.Path != "/usr/bin/runsc" || cmd.Args[1] != "--root=/run/falak-sbx" || cmd.Args[3] != "--internal-pid-file="+sandboxPIDFile("sess-1234") {
		t.Fatal(cmd.Args)
	}
	if err := (OpenPayload{Sandbox: &SandboxTarget{ID: "01j9z8y7x6w5v4t3s2r1q0p9na"}}).validate(); err != nil {
		t.Fatal(err)
	}
	for _, p := range []OpenPayload{
		{Sandbox: &SandboxTarget{ID: "../x"}},
		{Sandbox: &SandboxTarget{ID: "01j9z8y7x6w5v4t3s2r1q0p9na"}, Container: &ContainerTarget{Name: "web"}},
	} {
		if p.validate() == nil {
			t.Errorf("%+v accepted", p)
		}
	}
}

// The hang-up script signals exactly the processes of the session (field 6 of /proc/<pid>/stat), even with spaces and
// parentheses in a command name.
func TestSandboxHangupScript(t *testing.T) {
	proc := t.TempDir()
	for pid, stat := range map[string]string{
		"7":  "7 (sh) S 0 7 7 34816 7 4194560",
		"9":  "9 (sleep) S 7 9 7 34816 9 4194560",
		"12": "12 (we ird) (x) S 1 12 12 0 -1 4194560",
		"13": "13 (other) S 1 13 13 0 -1 4194560",
	} {
		_ = os.MkdirAll(filepath.Join(proc, pid), 0o755)
		_ = os.WriteFile(filepath.Join(proc, pid, "stat"), []byte(stat+"\n"), 0o644)
	}
	script := strings.ReplaceAll(sandboxHangup, "/proc/", proc+"/")
	script = strings.Replace(script, `kill -"$sig"`, `echo -"$sig"`, 1)
	out, err := exec.Command("/bin/sh", "-c", script, "_", "7", "HUP").CombinedOutput()
	if err != nil {
		t.Fatal(err, string(out))
	}
	got := strings.Fields(string(out))
	sort.Strings(got)
	if strings.Join(got, " ") != "-HUP -HUP 7 9" {
		t.Fatalf("%q", out)
	}
}
