package pty

import (
	"strings"
	"testing"
)

func TestSandboxTarget(t *testing.T) {
	got := runscExecArgs("01j9z8y7x6w5v4t3s2r1q0p9na", map[string]string{"B": "2", "A": "1 x", "TERM": "dumb"})
	want := []string{"--root=/run/falak-sbx", "exec", "--env=TERM=xterm-256color", "--env=A=1 x", "--env=B=2",
		"falak-sbx-01j9z8y7x6w5v4t3s2r1q0p9na", "/bin/sh", "-lc", "command -v bash >/dev/null 2>&1 && exec bash -l; exec sh -l"}
	if strings.Join(got, "|") != strings.Join(want, "|") {
		t.Fatalf("got  %q\nwant %q", got, want)
	}
	cmd := New(Options{Runsc: "/usr/bin/runsc"}).sandboxCmd(OpenPayload{Sandbox: &SandboxTarget{ID: "01j9z8y7x6w5v4t3s2r1q0p9na"}})
	if cmd.Path != "/usr/bin/runsc" || cmd.Args[1] != "--root=/run/falak-sbx" {
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
