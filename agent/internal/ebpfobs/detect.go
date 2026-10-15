package ebpfobs

import (
	"errors"
	"strconv"
	"strings"
	"syscall"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
)

// detect checks what the probe needs on linux (docs/plans/network/ebpf.md, "Capability detection"): kernel ≥ 5.8,
// kernel BTF, cgroup v2, and a bpf() syscall that works. Returns the first reason it cannot run.
func detect(fs hostfs.FS, bpf func() error) (bool, string) {
	rel, err := fs.ReadFile("/proc/sys/kernel/osrelease")
	if err != nil {
		return false, "kernel version unknown"
	}
	if major, minor, ok := kernelVersion(string(rel)); !ok || major < 5 || (major == 5 && minor < 8) {
		return false, "kernel " + strings.TrimSpace(string(rel)) + " is older than 5.8"
	}
	if !fs.Exists("/sys/kernel/btf/vmlinux") {
		return false, "no kernel BTF (/sys/kernel/btf/vmlinux)"
	}
	if !fs.Exists("/sys/fs/cgroup/cgroup.controllers") {
		return false, "cgroup v2 is not mounted at /sys/fs/cgroup"
	}
	if err := bpf(); err != nil {
		if errors.Is(err, syscall.EPERM) || errors.Is(err, syscall.EACCES) {
			return false, "bpf() not permitted (unprivileged container or seccomp?)"
		}
		return false, "bpf() failed: " + err.Error()
	}
	return true, ""
}

// kernelVersion parses "6.8.0-45-generic".
func kernelVersion(rel string) (major, minor int, ok bool) {
	parts := strings.SplitN(strings.TrimSpace(rel), ".", 3)
	if len(parts) < 2 {
		return 0, 0, false
	}
	major, err1 := strconv.Atoi(parts[0])
	digits := parts[1]
	if i := strings.IndexFunc(digits, func(r rune) bool { return r < '0' || r > '9' }); i >= 0 {
		digits = digits[:i]
	}
	minor, err2 := strconv.Atoi(digits)
	return major, minor, err1 == nil && err2 == nil
}
