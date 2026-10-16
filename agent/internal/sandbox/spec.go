package sandbox

import (
	"fmt"
	"strings"
)

// The subset of the OCI runtime spec (config.json) runsc reads for a sandbox. Generated here rather than by
// `runsc spec`, so a runsc upgrade never changes what a sandbox gets.
type (
	ociSpec struct {
		OCIVersion string     `json:"ociVersion"`
		Process    ociProcess `json:"process"`
		Root       ociRoot    `json:"root"`
		Hostname   string     `json:"hostname"`
		Mounts     []ociMount `json:"mounts"`
		Linux      ociLinux   `json:"linux"`
	}
	ociProcess struct {
		Terminal     bool        `json:"terminal"`
		User         ociUser     `json:"user"`
		Args         []string    `json:"args"`
		Env          []string    `json:"env"`
		Cwd          string      `json:"cwd"`
		Capabilities *ociCaps    `json:"capabilities,omitempty"`
		Rlimits      []ociRlimit `json:"rlimits,omitempty"`
	}
	ociUser struct {
		UID int `json:"uid"`
		GID int `json:"gid"`
	}
	ociCaps struct {
		Bounding  []string `json:"bounding"`
		Effective []string `json:"effective"`
		Permitted []string `json:"permitted"`
	}
	ociRlimit struct {
		Type string `json:"type"`
		Hard uint64 `json:"hard"`
		Soft uint64 `json:"soft"`
	}
	ociRoot struct {
		Path     string `json:"path"`
		Readonly bool   `json:"readonly"`
	}
	ociMount struct {
		Destination string   `json:"destination"`
		Type        string   `json:"type"`
		Source      string   `json:"source"`
		Options     []string `json:"options,omitempty"`
	}
	ociLinux struct {
		Namespaces  []ociNamespace `json:"namespaces"`
		Resources   ociResources   `json:"resources"`
		CgroupsPath string         `json:"cgroupsPath"`
	}
	ociNamespace struct {
		Type string `json:"type"`
		Path string `json:"path,omitempty"`
	}
	ociResources struct {
		CPU    ociCPU    `json:"cpu"`
		Memory ociMemory `json:"memory"`
		Pids   ociPids   `json:"pids"`
	}
	ociCPU struct {
		Quota  int64  `json:"quota"`
		Period uint64 `json:"period"`
	}
	ociMemory struct {
		Limit int64 `json:"limit"`
		Swap  int64 `json:"swap"`
	}
	ociPids struct {
		Limit int64 `json:"limit"`
	}
)

// caps are Docker's default capabilities, inside gVisor's kernel (not the host's): enough for apt, npm and friends
// as root in the sandbox.
var caps = []string{"CAP_CHOWN", "CAP_DAC_OVERRIDE", "CAP_FSETID", "CAP_FOWNER", "CAP_MKNOD", "CAP_NET_RAW", "CAP_SETGID",
	"CAP_SETUID", "CAP_SETFCAP", "CAP_SETPCAP", "CAP_NET_BIND_SERVICE", "CAP_SYS_CHROOT", "CAP_KILL", "CAP_AUDIT_WRITE"}

// initArgs is the sandbox's PID 1: it waits forever and exits on SIGTERM (every image this targets has /bin/sh).
var initArgs = []string{"/bin/sh", "-c", "trap 'exit 0' TERM INT; while :; do sleep 86400 & wait $!; done"}

const cpuPeriod = 100000

// specInput is what a sandbox's config.json is made of.
type specInput struct {
	Rootfs   string   // the shared base rootfs of the image
	Env      []string // KEY=VALUE, in order (later wins)
	Netns    string   // network namespace path ("" = a new empty one: loopback only)
	Resolv   string   // host file mounted as /etc/resolv.conf
	Hosts    string   // host file mounted as /etc/hosts
	CPUs     float64
	MemoryMB int
	Pids     int
	Cgroup   string
}

func buildSpec(in specInput) ociSpec {
	mem := int64(in.MemoryMB) << 20
	return ociSpec{
		OCIVersion: "1.0.0",
		Process: ociProcess{
			User: ociUser{}, Args: initArgs, Env: dedupEnv(in.Env), Cwd: "/root",
			Capabilities: &ociCaps{Bounding: caps, Effective: caps, Permitted: caps},
			Rlimits:      []ociRlimit{{Type: "RLIMIT_NOFILE", Hard: 1048576, Soft: 1048576}},
		},
		Root:     ociRoot{Path: in.Rootfs, Readonly: false},
		Hostname: "falak-sbx",
		Mounts: []ociMount{
			{Destination: "/proc", Type: "proc", Source: "proc"},
			{Destination: "/dev", Type: "tmpfs", Source: "tmpfs", Options: []string{"nosuid", "strictatime", "mode=755", "size=65536k"}},
			{Destination: "/dev/pts", Type: "devpts", Source: "devpts", Options: []string{"nosuid", "noexec", "newinstance", "ptmxmode=0666", "mode=0620", "gid=5"}},
			{Destination: "/dev/shm", Type: "tmpfs", Source: "shm", Options: []string{"nosuid", "noexec", "nodev", "mode=1777", "size=65536k"}},
			{Destination: "/dev/mqueue", Type: "mqueue", Source: "mqueue", Options: []string{"nosuid", "noexec", "nodev"}},
			{Destination: "/sys", Type: "sysfs", Source: "sysfs", Options: []string{"nosuid", "noexec", "nodev", "ro"}},
			{Destination: "/etc/resolv.conf", Type: "bind", Source: in.Resolv, Options: []string{"rbind", "ro"}},
			{Destination: "/etc/hosts", Type: "bind", Source: in.Hosts, Options: []string{"rbind", "ro"}},
		},
		Linux: ociLinux{
			Namespaces: []ociNamespace{{Type: "pid"}, {Type: "network", Path: in.Netns}, {Type: "ipc"}, {Type: "uts"}, {Type: "mount"}},
			Resources: ociResources{
				CPU:    ociCPU{Quota: int64(in.CPUs * cpuPeriod), Period: cpuPeriod},
				Memory: ociMemory{Limit: mem, Swap: mem},
				Pids:   ociPids{Limit: int64(in.Pids)},
			},
			CgroupsPath: in.Cgroup,
		},
	}
}

// dedupEnv keeps the last value of each key, in first-seen order.
func dedupEnv(env []string) []string {
	idx := map[string]int{}
	var out []string
	for _, kv := range env {
		k, _, _ := strings.Cut(kv, "=")
		if i, ok := idx[k]; ok {
			out[i] = kv
			continue
		}
		idx[k] = len(out)
		out = append(out, kv)
	}
	return out
}

// overlayFlag caps the sandbox's writes: they go to a filestore next to the rootfs, at most diskMB (exact; spike).
func overlayFlag(diskMB int) string { return fmt.Sprintf("--overlay2=root:self,size=%dm", diskMB) }
