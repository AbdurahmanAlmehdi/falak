//go:build !linux

package nettool

import "syscall"

// captureSysProcAttr: tcpdump gets its own process group (no Pdeathsig outside Linux; the agent only runs on Linux).
func captureSysProcAttr() *syscall.SysProcAttr {
	return &syscall.SysProcAttr{Setpgid: true}
}

// waitExitedNoReap is a no-op outside Linux (development machines run the tests).
func waitExitedNoReap(int) {}
