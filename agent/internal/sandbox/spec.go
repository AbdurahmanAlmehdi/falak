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

// initArgs is the sandbox's PID 1 (every image this targets has /bin/sh). It also starts background jobs: a restore
// (runsc restore, v0.21) kills every process that descends from a `runsc exec` (gVisor: their stdio is gone), so a
// process that should live on in snapshots, restores and forks must be a child of init. `falak-bg 'command'` (written
// by init into /usr/local/bin) hands a job script to init through the FIFO /run/falak/spawn; init runs it with the
// caller's env and working directory, stdio on /dev/null unless the command redirects it. Without mkfifo the spawner
// is skipped and init only waits.
var initArgs = []string{"/bin/sh", "-c", initScript}

const initScript = `trap 'exit 0' TERM INT
if mkdir -p /run/falak /usr/local/bin && rm -f /run/falak/spawn && mkfifo -m 600 /run/falak/spawn; then
cat > /usr/local/bin/falak-bg <<'FALAKEOF'
` + bgScript + `FALAKEOF
chmod 755 /usr/local/bin/falak-bg
while :; do
  if read -r j < /run/falak/spawn; then
    case "$j" in /run/falak/job.*) [ -f "$j" ] && { sh "$j" </dev/null >/dev/null 2>&1 & } ;; esac
  fi
  jobs >/dev/null
done
fi
while :; do sleep 86400 & wait $!; done`

// waitSpawner waits up to 5 s for init's falak-bg (exit 1 without it).
const waitSpawner = `i=0; while [ $i -lt 50 ]; do [ -x /usr/local/bin/falak-bg ] && [ -p /run/falak/spawn ] && exit 0; sleep 0.1; i=$((i+1)); done; exit 1`

// bgScript is falak-bg: the job script holds the caller's exported env, its working directory and the command.
const bgScript = `#!/bin/sh
# falak-bg 'command [> log 2>&1]': run a command in the background as a child of the sandbox's init (PID 1), so it
# is part of snapshots and keeps running in restores and forks. Processes started from an exec or a console (also
# with nohup or &) are stopped when a snapshot is restored.
[ $# -gt 0 ] || { echo "usage: falak-bg 'command [> log 2>&1]'" >&2; exit 2; }
j=$(mktemp /run/falak/job.XXXXXX) || exit 1
q() { printf "'%s'" "$(printf '%s' "$1" | sed "s/'/'\\\\''/g")"; }
{ echo 'rm -f "$0"'; export -p; echo "cd $(q "$(pwd)") || exit 1"; printf '%s\n' "$*"; } > "$j"
echo "$j" > /run/falak/spawn
`

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
