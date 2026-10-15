package metrics

import (
	"bufio"
	"bytes"
	"path"
	"strconv"
	"strings"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
)

// CgroupStats are the values of one cgroup v2 directory (a container's scope, a falak slice). One file read per
// group of values, never the Engine API. A controller not enabled for the directory has no files: its Has* flag stays
// false and the collector emits no series for it (rather than a zero that reads as "idle").
type CgroupStats struct {
	// cpu.stat: CPU time and, with the cpu controller (a quota possible), the CFS periods.
	CPUUsageUsec, NrPeriods, NrThrottled, ThrottledUsec uint64
	HasCPU, HasThrottle                                 bool
	// CPULimit is cpu.max's quota/period in cores; 0 = no limit ("max").
	CPULimit float64
	// memory.current and memory.stat's file (page cache) and shmem (shared memory and tmpfs, part of file but not
	// reclaimable: Postgres' shared_buffers): used = current - (file - shmem), cache = file - shmem.
	MemCurrent, MemFile, MemShmem uint64
	HasMem              bool
	// MemLimit is memory.max; 0 = no limit.
	MemLimit int64
	// OOMKills is memory.events' oom_kill.
	OOMKills uint64
	HasOOM   bool
	// io.stat summed over devices.
	ReadBytes, WriteBytes, ReadOps, WriteOps uint64
	HasIO                                    bool
	// pids.current and pids.max (0 = no limit).
	Pids      uint64
	HasPids   bool
	PidsLimit int64
}

// MemUsed is memory.current without the page cache (reclaimable, shown apart); shared memory counts as used.
func (s CgroupStats) MemUsed() uint64 {
	return s.MemCurrent - s.MemCache()
}

// MemCache is the reclaimable page cache: memory.stat's file without shmem, at most memory.current.
func (s CgroupStats) MemCache() uint64 {
	return min(s.MemFile-min(s.MemShmem, s.MemFile), s.MemCurrent)
}

// ReadCgroup reads a cgroup v2 directory (a host path under fs). ok is false when the directory has no cpu.stat
// (gone, or not a cgroup v2 directory): nothing in it is worth reporting.
func ReadCgroup(fs hostfs.FS, dir string) (CgroupStats, bool) {
	var s CgroupStats
	read := func(name string) ([]byte, bool) {
		b, err := fs.ReadFile(path.Join(dir, name))
		return b, err == nil
	}
	b, ok := read("cpu.stat")
	if !ok {
		return s, false
	}
	kv := parseFlatKeyed(b)
	s.CPUUsageUsec, s.HasCPU = kv["usage_usec"]
	if _, ok := kv["nr_periods"]; ok {
		s.NrPeriods, s.NrThrottled, s.ThrottledUsec, s.HasThrottle = kv["nr_periods"], kv["nr_throttled"], kv["throttled_usec"], true
	}
	if b, ok := read("cpu.max"); ok {
		s.CPULimit, _ = parseCPUMax(b)
	}
	if b, ok := read("memory.current"); ok {
		if n, err := strconv.ParseUint(strings.TrimSpace(string(b)), 10, 64); err == nil {
			s.MemCurrent, s.HasMem = n, true
			if b, ok := read("memory.stat"); ok {
				kv := parseFlatKeyed(b)
				s.MemFile, s.MemShmem = kv["file"], kv["shmem"]
			}
		}
	}
	if b, ok := read("memory.max"); ok {
		s.MemLimit, _ = parseMax(b)
	}
	if b, ok := read("memory.events"); ok {
		s.OOMKills, s.HasOOM = parseFlatKeyed(b)["oom_kill"]
	}
	if b, ok := read("io.stat"); ok {
		s.ReadBytes, s.WriteBytes, s.ReadOps, s.WriteOps = parseIOStat(b)
		s.HasIO = true
	}
	if b, ok := read("pids.current"); ok {
		if n, err := strconv.ParseUint(strings.TrimSpace(string(b)), 10, 64); err == nil {
			s.Pids, s.HasPids = n, true
		}
	}
	if b, ok := read("pids.max"); ok {
		s.PidsLimit, _ = parseMax(b)
	}
	return s, true
}

// parseFlatKeyed parses "key value" lines (cpu.stat, memory.stat, memory.events).
func parseFlatKeyed(b []byte) map[string]uint64 {
	out := map[string]uint64{}
	sc := bufio.NewScanner(bytes.NewReader(b))
	for sc.Scan() {
		k, v, ok := strings.Cut(sc.Text(), " ")
		if !ok {
			continue
		}
		if n, err := strconv.ParseUint(strings.TrimSpace(v), 10, 64); err == nil {
			out[k] = n
		}
	}
	return out
}

// parseCPUMax parses cpu.max ("<quota> <period>" in µs, quota "max" = none) into cores.
func parseCPUMax(b []byte) (float64, bool) {
	f := strings.Fields(string(b))
	if len(f) == 0 || f[0] == "max" {
		return 0, false
	}
	quota, err := strconv.ParseFloat(f[0], 64)
	if err != nil || quota <= 0 {
		return 0, false
	}
	period := 100000.0 // the kernel's default when only the quota is written
	if len(f) > 1 {
		if p, err := strconv.ParseFloat(f[1], 64); err == nil && p > 0 {
			period = p
		}
	}
	return quota / period, true
}

// parseMax parses a single-value limit file (memory.max, pids.max); "max" = none.
func parseMax(b []byte) (int64, bool) {
	v := strings.TrimSpace(string(b))
	if v == "" || v == "max" {
		return 0, false
	}
	n, err := strconv.ParseInt(v, 10, 64)
	if err != nil || n <= 0 {
		return 0, false
	}
	return n, true
}

// parseIOStat sums io.stat's per-device lines ("8:0 rbytes=… wbytes=… rios=… wios=… dbytes=… dios=…").
func parseIOStat(b []byte) (rbytes, wbytes, rios, wios uint64) {
	sc := bufio.NewScanner(bytes.NewReader(b))
	for sc.Scan() {
		for _, f := range strings.Fields(sc.Text()) {
			k, v, ok := strings.Cut(f, "=")
			if !ok {
				continue
			}
			n, err := strconv.ParseUint(v, 10, 64)
			if err != nil {
				continue
			}
			switch k {
			case "rbytes":
				rbytes += n
			case "wbytes":
				wbytes += n
			case "rios":
				rios += n
			case "wios":
				wios += n
			}
		}
	}
	return
}
