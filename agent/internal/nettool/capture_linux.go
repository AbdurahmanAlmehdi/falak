package nettool

import (
	"syscall"

	"golang.org/x/sys/unix"
)

// captureSysProcAttr: tcpdump gets its own process group and dies with the agent.
func captureSysProcAttr() *syscall.SysProcAttr {
	return &syscall.SysProcAttr{Setpgid: true, Pdeathsig: syscall.SIGKILL}
}

// waitExitedNoReap blocks until pid exited without reaping it: the zombie keeps the pid (and its process group id)
// from being reused until cmd.Wait reaps it.
func waitExitedNoReap(pid int) {
	var info unix.Siginfo
	for {
		if err := unix.Waitid(unix.P_PID, pid, &info, unix.WEXITED|unix.WNOWAIT, nil); err != unix.EINTR {
			return
		}
	}
}
