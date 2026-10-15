package metrics

import (
	"os"
	"path/filepath"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
)

// writeTree writes files (host paths relative to root) into a fake host root.
func writeTree(t *testing.T, root string, files map[string]string) {
	t.Helper()
	for p, c := range files {
		full := filepath.Join(root, p)
		if err := os.MkdirAll(filepath.Dir(full), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(full, []byte(c), 0o644); err != nil {
			t.Fatal(err)
		}
	}
}

func TestParseCPUMax(t *testing.T) {
	for _, tc := range []struct {
		in    string
		cores float64
		ok    bool
	}{
		{"max 100000\n", 0, false},
		{"150000 100000\n", 1.5, true},
		{"50000 100000", 0.5, true},
		{"200000 50000\n", 4, true},
		{"25000\n", 0.25, true}, // period defaults to 100 ms
		{"", 0, false},
		{"garbage 100000", 0, false},
	} {
		c, ok := parseCPUMax([]byte(tc.in))
		if c != tc.cores || ok != tc.ok {
			t.Errorf("parseCPUMax(%q) = %v %v, want %v %v", tc.in, c, ok, tc.cores, tc.ok)
		}
	}
}

func TestParseMax(t *testing.T) {
	for _, tc := range []struct {
		in string
		n  int64
		ok bool
	}{
		{"max\n", 0, false},
		{"536870912\n", 536870912, true},
		{"128", 128, true},
		{"", 0, false},
		{"0\n", 0, false},
	} {
		n, ok := parseMax([]byte(tc.in))
		if n != tc.n || ok != tc.ok {
			t.Errorf("parseMax(%q) = %d %v", tc.in, n, ok)
		}
	}
}

func TestParseFlatKeyedAndIOStat(t *testing.T) {
	kv := parseFlatKeyed([]byte("usage_usec 2500000\nuser_usec 2000000\nnr_periods 40\nnr_throttled 4\nthrottled_usec 125000\n"))
	if kv["usage_usec"] != 2500000 || kv["nr_throttled"] != 4 || kv["throttled_usec"] != 125000 {
		t.Fatalf("cpu.stat %v", kv)
	}
	if kv := parseFlatKeyed([]byte("anon 1000\nfile 4096\nkernel 12\n")); kv["file"] != 4096 || kv["anon"] != 1000 {
		t.Fatalf("memory.stat %v", kv)
	}
	for _, tc := range []struct {
		name, in         string
		rb, wb, rio, wio uint64
	}{
		{"empty", "", 0, 0, 0, 0},
		{"one device", "8:0 rbytes=4096 wbytes=8192 rios=1 wios=2 dbytes=0 dios=0\n", 4096, 8192, 1, 2},
		{"summed", "8:0 rbytes=100 wbytes=200 rios=1 wios=2 dbytes=0 dios=0\n259:0 rbytes=1 wbytes=2 rios=3 wios=4 dbytes=5 dios=6\n", 101, 202, 4, 6},
	} {
		rb, wb, rio, wio := parseIOStat([]byte(tc.in))
		if rb != tc.rb || wb != tc.wb || rio != tc.rio || wio != tc.wio {
			t.Errorf("%s: io.stat %d %d %d %d", tc.name, rb, wb, rio, wio)
		}
	}
}

func TestReadCgroup(t *testing.T) {
	full := map[string]string{
		"cg/cpu.stat":       "usage_usec 2500000\nuser_usec 2000000\nsystem_usec 500000\nnr_periods 40\nnr_throttled 4\nthrottled_usec 125000\n",
		"cg/cpu.max":        "150000 100000\n",
		"cg/memory.current": "10000\n",
		"cg/memory.stat":    "anon 6000\nfile 3000\nkernel 1000\nshmem 1000\n",
		"cg/memory.max":     "536870912\n",
		"cg/memory.events":  "low 0\nhigh 0\nmax 3\noom 1\noom_kill 2\noom_group_kill 0\n",
		"cg/io.stat":        "8:0 rbytes=4096 wbytes=8192 rios=1 wios=2 dbytes=0 dios=0\n",
		"cg/pids.current":   "7\n",
		"cg/pids.max":       "256\n",
	}
	unlimited := map[string]string{
		"cg/cpu.stat":       "usage_usec 10\nuser_usec 5\nsystem_usec 5\n", // no cpu controller: no periods
		"cg/cpu.max":        "max 100000\n",
		"cg/memory.current": "500\n",
		"cg/memory.max":     "max\n",
		"cg/pids.max":       "max\n",
	}
	for _, tc := range []struct {
		name  string
		files map[string]string
		ok    bool
		check func(t *testing.T, s CgroupStats)
	}{
		{"limited", full, true, func(t *testing.T, s CgroupStats) {
			if !s.HasCPU || s.CPUUsageUsec != 2500000 || !s.HasThrottle || s.NrPeriods != 40 || s.NrThrottled != 4 || s.ThrottledUsec != 125000 {
				t.Errorf("cpu %+v", s)
			}
			if s.CPULimit != 1.5 || s.MemLimit != 536870912 || s.PidsLimit != 256 {
				t.Errorf("limits %+v", s)
			}
			if !s.HasMem || s.MemUsed() != 8000 || s.MemCache() != 2000 || s.MemFile != 3000 || !s.HasOOM || s.OOMKills != 2 {
				t.Errorf("memory %+v", s)
			}
			if !s.HasIO || s.ReadBytes != 4096 || s.WriteBytes != 8192 || !s.HasPids || s.Pids != 7 {
				t.Errorf("io/pids %+v", s)
			}
		}},
		{"unlimited", unlimited, true, func(t *testing.T, s CgroupStats) {
			if s.CPULimit != 0 || s.MemLimit != 0 || s.PidsLimit != 0 || s.HasThrottle {
				t.Errorf("no limits expected %+v", s)
			}
			if !s.HasMem || s.MemUsed() != 500 || s.HasIO || s.HasPids || s.HasOOM {
				t.Errorf("presence %+v", s)
			}
		}},
		{"missing", map[string]string{"cg/memory.current": "1\n"}, false, nil},
	} {
		t.Run(tc.name, func(t *testing.T) {
			root := t.TempDir()
			writeTree(t, root, tc.files)
			s, ok := ReadCgroup(hostfs.FS{Root: root}, "/cg")
			if ok != tc.ok {
				t.Fatalf("ok %v", ok)
			}
			if tc.check != nil {
				tc.check(t, s)
			}
		})
	}
}
