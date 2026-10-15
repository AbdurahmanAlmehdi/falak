package nettool

import (
	"syscall"
	"testing"
)

func TestCaptureSysProcAttrDiesWithTheAgent(t *testing.T) {
	if a := captureSysProcAttr(); !a.Setpgid || a.Pdeathsig != syscall.SIGKILL {
		t.Fatalf("%+v", a)
	}
}
