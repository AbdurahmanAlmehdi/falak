// Package metrics reads host metrics from /proc (replacing node_exporter) and converts them to OTLP
// metrics. It also provides the lightweight summary sent with every heartbeat.
package metrics

import (
	"bufio"
	"bytes"
	"fmt"
	"regexp"
	"strconv"
	"strings"
	"syscall"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
)

// CPUTimes are cumulative jiffies from the first line of /proc/stat.
type CPUTimes struct {
	User, Nice, System, Idle, IOWait, IRQ, SoftIRQ, Steal uint64
}

// Total jiffies.
func (c CPUTimes) Total() uint64 {
	return c.User + c.Nice + c.System + c.Idle + c.IOWait + c.IRQ + c.SoftIRQ + c.Steal
}

// Busy jiffies (everything but idle and iowait).
func (c CPUTimes) Busy() uint64 { return c.Total() - c.Idle - c.IOWait }

// Utilization between two samples as a 0..1 fraction.
func Utilization(prev, cur CPUTimes) float64 {
	dt := float64(cur.Total()) - float64(prev.Total())
	if dt <= 0 {
		return 0
	}
	u := (float64(cur.Busy()) - float64(prev.Busy())) / dt
	if u < 0 {
		return 0
	}
	if u > 1 {
		return 1
	}
	return u
}

// Mem holds /proc/meminfo values in bytes.
type Mem struct {
	Total, Free, Available, Buffers, Cached, SwapTotal, SwapFree int64
}

// Used = Total - Available (what the kernel cannot reclaim).
func (m Mem) Used() int64 { return m.Total - m.Available }

// NetDev is one /proc/net/dev line.
type NetDev struct {
	Name                             string
	RxBytes, TxBytes, RxPkts, TxPkts uint64
	RxErrs, TxErrs                   uint64
}

// DiskStat is one /proc/diskstats line (bytes use 512-byte sectors).
type DiskStat struct {
	Name                  string
	ReadBytes, WriteBytes uint64
	ReadOps, WriteOps     uint64
	IOTimeMs              uint64
}

// FSUsage for one mount point.
type FSUsage struct {
	Mount             string
	Total, Free, Used int64
}

func readFile(fs hostfs.FS, p string) ([]byte, error) { return fs.ReadFile(p) }

// ReadCPU parses /proc/stat.
func ReadCPU(fs hostfs.FS) (CPUTimes, error) {
	b, err := readFile(fs, "/proc/stat")
	if err != nil {
		return CPUTimes{}, err
	}
	line, _, _ := bytes.Cut(b, []byte("\n"))
	f := strings.Fields(string(line))
	if len(f) < 5 || f[0] != "cpu" {
		return CPUTimes{}, fmt.Errorf("unexpected /proc/stat header %q", line)
	}
	n := make([]uint64, 8)
	for i := 0; i < 8 && i+1 < len(f); i++ {
		n[i], _ = strconv.ParseUint(f[i+1], 10, 64)
	}
	return CPUTimes{n[0], n[1], n[2], n[3], n[4], n[5], n[6], n[7]}, nil
}

// ReadLogicalCPUs counts the cpuN lines of /proc/stat: the online logical CPUs, the host's capacity in the unit of a
// workload's CPU limit and of rate(cpu time) (cores).
func ReadLogicalCPUs(fs hostfs.FS) (int, error) {
	b, err := readFile(fs, "/proc/stat")
	if err != nil {
		return 0, err
	}
	n := 0
	for _, line := range strings.Split(string(b), "\n") {
		if len(line) > 3 && strings.HasPrefix(line, "cpu") && line[3] >= '0' && line[3] <= '9' {
			n++
		}
	}
	if n == 0 {
		return 0, fmt.Errorf("no cpuN lines in /proc/stat")
	}
	return n, nil
}

// ReadMem parses /proc/meminfo.
func ReadMem(fs hostfs.FS) (Mem, error) {
	b, err := readFile(fs, "/proc/meminfo")
	if err != nil {
		return Mem{}, err
	}
	var m Mem
	haveAvail := false
	sc := bufio.NewScanner(bytes.NewReader(b))
	for sc.Scan() {
		k, v, ok := strings.Cut(sc.Text(), ":")
		if !ok {
			continue
		}
		f := strings.Fields(v)
		if len(f) == 0 {
			continue
		}
		n, _ := strconv.ParseInt(f[0], 10, 64)
		if len(f) > 1 && f[1] == "kB" {
			n *= 1024
		}
		switch k {
		case "MemTotal":
			m.Total = n
		case "MemFree":
			m.Free = n
		case "MemAvailable":
			m.Available, haveAvail = n, true
		case "Buffers":
			m.Buffers = n
		case "Cached":
			m.Cached = n
		case "SwapTotal":
			m.SwapTotal = n
		case "SwapFree":
			m.SwapFree = n
		}
	}
	if !haveAvail {
		m.Available = m.Free + m.Buffers + m.Cached
	}
	return m, nil
}

// ReadLoad parses /proc/loadavg.
func ReadLoad(fs hostfs.FS) ([3]float64, error) {
	var l [3]float64
	b, err := readFile(fs, "/proc/loadavg")
	if err != nil {
		return l, err
	}
	f := strings.Fields(string(b))
	if len(f) < 3 {
		return l, fmt.Errorf("unexpected /proc/loadavg %q", b)
	}
	for i := 0; i < 3; i++ {
		l[i], _ = strconv.ParseFloat(f[i], 64)
	}
	return l, nil
}

// ReadUptime parses /proc/uptime (seconds).
func ReadUptime(fs hostfs.FS) (float64, error) {
	b, err := readFile(fs, "/proc/uptime")
	if err != nil {
		return 0, err
	}
	f := strings.Fields(string(b))
	if len(f) == 0 {
		return 0, fmt.Errorf("empty /proc/uptime")
	}
	return strconv.ParseFloat(f[0], 64)
}

// ReadNetDev parses /proc/net/dev, skipping loopback.
func ReadNetDev(fs hostfs.FS) ([]NetDev, error) { return readNetDevAt(fs, "/proc/net/dev") }

// readNetDevAt parses a net/dev file: /proc/net/dev (the agent's own namespace: the host's) or /proc/<pid>/net/dev
// (that process's network namespace, i.e. a container's).
func readNetDevAt(fs hostfs.FS, path string) ([]NetDev, error) {
	b, err := readFile(fs, path)
	if err != nil {
		return nil, err
	}
	var out []NetDev
	sc := bufio.NewScanner(bytes.NewReader(b))
	for sc.Scan() {
		name, rest, ok := strings.Cut(sc.Text(), ":")
		if !ok {
			continue
		}
		name = strings.TrimSpace(name)
		if name == "lo" {
			continue
		}
		f := strings.Fields(rest)
		if len(f) < 16 {
			continue
		}
		u := func(i int) uint64 { n, _ := strconv.ParseUint(f[i], 10, 64); return n }
		out = append(out, NetDev{Name: name, RxBytes: u(0), RxPkts: u(1), RxErrs: u(2), TxBytes: u(8), TxPkts: u(9), TxErrs: u(10)})
	}
	return out, nil
}

var partitionRe = regexp.MustCompile(`^((sd|vd|xvd|hd)[a-z]+\d+|nvme\d+n\d+p\d+|mmcblk\d+p\d+)$`)

// ReadDiskStats parses /proc/diskstats for whole disks (partitions, loop and ram devices skipped).
func ReadDiskStats(fs hostfs.FS) ([]DiskStat, error) {
	b, err := readFile(fs, "/proc/diskstats")
	if err != nil {
		return nil, err
	}
	var out []DiskStat
	sc := bufio.NewScanner(bytes.NewReader(b))
	for sc.Scan() {
		f := strings.Fields(sc.Text())
		if len(f) < 14 {
			continue
		}
		name := f[2]
		if strings.HasPrefix(name, "loop") || strings.HasPrefix(name, "ram") || strings.HasPrefix(name, "zram") || partitionRe.MatchString(name) {
			continue
		}
		u := func(i int) uint64 { n, _ := strconv.ParseUint(f[i], 10, 64); return n }
		out = append(out, DiskStat{Name: name, ReadOps: u(3), ReadBytes: u(5) * 512, WriteOps: u(7), WriteBytes: u(9) * 512, IOTimeMs: u(12)})
	}
	return out, nil
}

// ReadFS returns usage of the filesystem holding mount (via statfs on the re-rooted path).
func ReadFS(fs hostfs.FS, mount string) (FSUsage, error) {
	var st syscall.Statfs_t
	if err := syscall.Statfs(fs.P(mount), &st); err != nil {
		return FSUsage{}, err
	}
	bs := int64(st.Bsize)
	total := int64(st.Blocks) * bs
	free := int64(st.Bavail) * bs
	used := (int64(st.Blocks) - int64(st.Bfree)) * bs
	return FSUsage{Mount: mount, Total: total, Free: free, Used: used}, nil
}
