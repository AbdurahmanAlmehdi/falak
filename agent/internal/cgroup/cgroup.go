// Package cgroup gives services on the host (classic sites, PHP-FPM, workers, daemons) their resource limits: one
// systemd slice per service, falak-<key>.slice under falak.slice (cgroup v2), with MemoryMax / MemoryHigh / MemoryLow
// / CPUQuota / TasksMax. The supervisor starts a program inside its slice as a transient scope (systemd-run --scope);
// a site's own PHP-FPM master runs as a service with Slice=. Changed limits are applied live with
// `systemctl set-property --runtime` — nothing restarts.
package cgroup

import (
	"bufio"
	"bytes"
	"context"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"sync"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/resources"
	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// Slice mirrors one proc.apply `slices` entry. Zero values are "no limit".
type Slice struct {
	// Name is the key: the unit is falak-<name>.slice. No dashes (a dash in a slice name nests it under another).
	Name            string `json:"name"`
	MemoryMaxBytes  int64  `json:"memory_max_bytes,omitempty"`
	MemoryHighBytes int64  `json:"memory_high_bytes,omitempty"`
	MemoryLowBytes  int64  `json:"memory_low_bytes,omitempty"`
	// CPUQuotaPercent is CPU time per wall-clock second: 150 = one and a half CPUs.
	CPUQuotaPercent int   `json:"cpu_quota_percent,omitempty"`
	TasksMax        int64 `json:"tasks_max,omitempty"`
}

// NameRe is a slice key.
var NameRe = regexp.MustCompile(`^[a-z0-9_]{1,80}$`)

// Unit is the slice's systemd unit name.
func Unit(name string) string { return "falak-" + name + ".slice" }

// UnitDir holds the slice units.
const UnitDir = "/etc/systemd/system"

// CgroupRoot is where falak.slice's children live on cgroup v2.
var CgroupRoot = "/sys/fs/cgroup/falak.slice"

const header = "# Managed by Falak — do not edit\n"

func (s Slice) validate() error {
	if !NameRe.MatchString(s.Name) {
		return fmt.Errorf("invalid slice name %q", s.Name)
	}
	if s.MemoryMaxBytes < 0 || s.MemoryHighBytes < 0 || s.MemoryLowBytes < 0 || s.CPUQuotaPercent < 0 || s.TasksMax < 0 {
		return fmt.Errorf("slice %s: limits must not be negative", s.Name)
	}
	if s.MemoryMaxBytes > 0 && (s.MemoryHighBytes > s.MemoryMaxBytes || s.MemoryLowBytes > s.MemoryMaxBytes) {
		return fmt.Errorf("slice %s: MemoryHigh and MemoryLow must not exceed MemoryMax", s.Name)
	}
	return nil
}

// properties are the slice's resource settings, unset ones reset (so set-property clears a removed limit live).
func (s Slice) properties() []string {
	bytesOr := func(v int64, unset string) string {
		if v <= 0 {
			return unset
		}
		return strconv.FormatInt(v, 10)
	}
	quota := ""
	if s.CPUQuotaPercent > 0 {
		quota = strconv.Itoa(s.CPUQuotaPercent) + "%"
	}
	// No swap on top of a memory limit: a service over its limit is OOM-killed instead of paging the host.
	swap := "infinity"
	if s.MemoryMaxBytes > 0 {
		swap = "0"
	}
	return []string{
		"MemoryMax=" + bytesOr(s.MemoryMaxBytes, "infinity"),
		"MemoryHigh=" + bytesOr(s.MemoryHighBytes, "infinity"),
		"MemoryLow=" + bytesOr(s.MemoryLowBytes, "0"),
		"MemorySwapMax=" + swap,
		"CPUQuota=" + quota,
		"TasksMax=" + bytesOr(s.TasksMax, "infinity"),
	}
}

// Render renders the slice unit.
func Render(s Slice) string {
	var b strings.Builder
	b.WriteString(header)
	fmt.Fprintf(&b, "[Unit]\nDescription=Falak limits for %s\nBefore=slices.target\n\n[Slice]\nMemoryAccounting=yes\nCPUAccounting=yes\nTasksAccounting=yes\n", s.Name)
	for _, p := range s.properties() {
		if strings.HasSuffix(p, "=") {
			continue // CPUQuota= empty: no quota
		}
		b.WriteString(p + "\n")
	}
	return b.String()
}

// Partition splits a desired set into the slices that can be applied and the errors of the others (by name): an
// invalid slice (or a second one of a name) is skipped, never the whole set — one service's bad limits must not stop
// every other service's.
func Partition(desired []Slice) ([]Slice, map[string]string) {
	valid := make([]Slice, 0, len(desired))
	errs := map[string]string{}
	seen := map[string]bool{}
	for _, s := range desired {
		switch err := s.validate(); {
		case err != nil:
			errs[s.Name] = err.Error()
		case seen[s.Name]:
			errs[s.Name] = fmt.Sprintf("duplicate slice %q", s.Name)
		default:
			seen[s.Name] = true
			valid = append(valid, s)
		}
	}
	return valid, errs
}

// Manager converges the slice units.
type Manager struct {
	Runner runner.Runner
	FS     hostfs.FS

	mu sync.Mutex
}

// Apply writes the slices (Partition's valid ones) and sets every slice's limits live with set-property --runtime —
// each time, so a runtime drop-in can never stay behind a unit file. A slice whose set-property fails gets its
// previous unit file back and is reported in errs; the others are applied. changed lists the slices whose unit file
// changed. Slices no longer wanted are only removed by Prune, after the programs in them moved out.
func (m *Manager) Apply(ctx context.Context, desired []Slice) (changed []string, errs map[string]string, err error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	errs = map[string]string{}
	type previous struct {
		data    []byte
		existed bool
	}
	before := map[string]previous{}
	for _, s := range desired {
		path := filepath.Join(UnitDir, Unit(s.Name))
		old, readErr := m.FS.ReadFile(path)
		ch, err := m.FS.WriteFile(path, []byte(Render(s)), 0o644)
		if err != nil {
			return nil, nil, err
		}
		if ch {
			before[s.Name] = previous{old, readErr == nil}
			changed = append(changed, s.Name)
		}
	}
	restore := func(name string) {
		path := filepath.Join(UnitDir, Unit(name))
		if prev := before[name]; prev.existed {
			_, _ = m.FS.WriteFile(path, prev.data, 0o644)
		} else {
			_, _ = m.FS.Remove(path)
		}
	}
	if len(changed) > 0 {
		if err := m.run(ctx, "systemctl", "daemon-reload"); err != nil {
			for _, name := range changed {
				restore(name)
			}
			return nil, nil, err
		}
	}
	restored := false
	applied := make([]string, 0, len(changed))
	for _, s := range desired {
		if err := m.run(ctx, "systemctl", append([]string{"set-property", "--runtime", Unit(s.Name)}, s.properties()...)...); err != nil {
			errs[s.Name] = fmt.Sprintf("applying limits of %s: %v", Unit(s.Name), err)
			if _, ok := before[s.Name]; ok {
				restore(s.Name)
				restored = true
			}
			continue
		}
		if _, ok := before[s.Name]; ok {
			applied = append(applied, s.Name)
		}
	}
	if restored {
		_ = m.run(ctx, "systemctl", "daemon-reload")
	}
	sort.Strings(applied)
	return applied, errs, nil
}

// Prune removes the managed slice units not in keep. A unit still active (a process outlived its program) only loses
// its limits: stopping a slice would kill what runs in it.
func (m *Manager) Prune(ctx context.Context, keep []Slice) ([]string, error) {
	want := map[string]bool{}
	for _, s := range keep {
		want[Unit(s.Name)] = true
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	files, _ := filepath.Glob(m.FS.P(filepath.Join(UnitDir, "falak-*.slice")))
	var removed []string
	for _, f := range files {
		unit := filepath.Base(f)
		if want[unit] {
			continue
		}
		b, err := os.ReadFile(f)
		if err != nil || !bytes.HasPrefix(b, []byte(header)) {
			continue
		}
		if _, err := m.FS.Remove(filepath.Join(UnitDir, unit)); err != nil {
			return nil, err
		}
		removed = append(removed, strings.TrimSuffix(strings.TrimPrefix(unit, "falak-"), ".slice"))
	}
	if len(removed) == 0 {
		return []string{}, nil
	}
	if err := m.run(ctx, "systemctl", "daemon-reload"); err != nil {
		return nil, err
	}
	for _, name := range removed {
		// Best effort: an inactive slice has nothing to clear.
		_, _ = m.Runner.Run(ctx, runner.Cmd{Name: "systemctl", Args: append([]string{"set-property", "--runtime", Unit(name)}, Slice{Name: name}.properties()...)})
	}
	sort.Strings(removed)
	return removed, nil
}

func (m *Manager) run(ctx context.Context, name string, args ...string) error {
	_, err := runner.Check(ctx, m.Runner, runner.Cmd{Name: name, Args: args})
	return err
}

// ---- scopes ----

// ScopeSpec is a supervised process started inside a slice.
type ScopeSpec struct {
	// Slice is the key of the scope's slice (falak-<Slice>.slice); without one the scope goes into SliceUnit, a full
	// slice unit name (falak.slice on the control-plane machine).
	Slice, SliceUnit string
	// Unit is the scope's name without .scope (deterministic, so a leftover of a crashed agent is found and stopped).
	Unit    string
	UID     uint32
	GID     uint32
	AsUser  bool // drop to UID/GID (and the user's groups) before exec
	Command []string
	// SystemdVersion decides OOMPolicy= (scopes take it from systemd 253; older ones never stop a scope on OOM).
	SystemdVersion int
	// OomScoreAdj is set by choom (util-linux) while still root, before setpriv drops privileges (lowering it needs
	// CAP_SYS_RESOURCE) and before the program runs: no window where a child keeps the old value. Scopes have no
	// OOMScoreAdjust= property (it is an exec setting of services).
	OomScoreAdj int
}

// ScopeTools are the binaries a scope launch needs (checked before launching, so a missing one is a launch error,
// not an exit code of the program).
func (s ScopeSpec) Tools() []string {
	tools := []string{"systemd-run"}
	if s.OomScoreAdj != 0 {
		tools = append(tools, "choom")
	}
	if s.AsUser {
		tools = append(tools, "setpriv")
	}
	return tools
}

// LaunchFailure reports whether a scope launch's first stderr bytes are systemd-run's, choom's or setpriv's own error
// (the program never started) rather than the program's.
func LaunchFailure(stderr []byte) bool {
	for _, prefix := range []string{"Failed to start transient scope unit", "Failed to create bus connection", "Unknown assignment", "setpriv: ", "choom: "} {
		if bytes.HasPrefix(stderr, []byte(prefix)) {
			return true
		}
	}
	return false
}

// UserCanEnter reports whether uid (with gid and groups) may search every directory down to dir: the supervisor
// changes into a program's directory as root before systemd-run, so it checks what the user could do itself.
func UserCanEnter(dir string, uid, gid uint32, groups []uint32) error {
	if uid == 0 || dir == "" {
		return nil
	}
	inGroup := func(g uint32) bool {
		if g == gid {
			return true
		}
		for _, x := range groups {
			if x == g {
				return true
			}
		}
		return false
	}
	path := "/"
	for _, part := range append([]string{""}, strings.Split(strings.Trim(filepath.Clean(dir), "/"), "/")...) {
		path = filepath.Join(path, part)
		st, err := os.Stat(path)
		if err != nil {
			return err
		}
		mode := st.Mode()
		if !mode.IsDir() {
			return fmt.Errorf("%s is not a directory", path)
		}
		owner, group := statOwner(st)
		ok := mode&0o001 != 0 || (owner == uid && mode&0o100 != 0) || (owner != uid && inGroup(group) && mode&0o010 != 0)
		if !ok {
			return fmt.Errorf("user %d may not enter %s", uid, path)
		}
	}
	return nil
}

// ScopeUnitRe is a scope name.
var ScopeUnitRe = regexp.MustCompile(`^falak-proc-[a-z0-9_.-]+-[0-9]+$`)

// ScopeCommand is the argv that runs the command inside the slice. systemd-run --scope registers the scope and
// execs in place, and setpriv (util-linux) drops to the user with its supplementary groups the same way: the
// supervisor's child keeps its pid and process group, so stop signals and exit codes work as without a slice. The
// scope is outside the agent's cgroup: the supervisor stops its programs on shutdown, and stops a leftover scope of
// the same name (an agent that crashed) before it starts the program again.
// OOMPolicy=continue: an OOM kill inside the scope takes that process only (a worker of Octane, say), and the
// supervisor's restart policy handles the rest.
func ScopeCommand(s ScopeSpec) []string {
	slice := s.SliceUnit
	if s.Slice != "" {
		slice = Unit(s.Slice)
	}
	argv := []string{"systemd-run", "--scope", "--quiet", "--collect", "--slice=" + slice, "--unit=" + s.Unit + ".scope"}
	if s.SystemdVersion >= 253 {
		argv = append(argv, "--property=OOMPolicy=continue")
	}
	argv = append(argv, "--")
	if s.OomScoreAdj != 0 {
		argv = append(argv, "choom", "-n", strconv.Itoa(s.OomScoreAdj), "--")
	}
	if s.AsUser {
		argv = append(argv, "setpriv", "--reuid="+strconv.FormatUint(uint64(s.UID), 10), "--regid="+strconv.FormatUint(uint64(s.GID), 10), "--init-groups", "--")
	}
	return append(argv, s.Command...)
}

// SystemdVersion parses `systemctl --version` ("systemd 255 (255.4-1ubuntu8)"); 0 when unknown.
func SystemdVersion(ctx context.Context, r runner.Runner) int {
	res, err := r.Run(ctx, runner.Cmd{Name: "systemctl", Args: []string{"--version"}})
	if err != nil || res.ExitCode != 0 {
		return 0
	}
	f := strings.Fields(string(res.Stdout))
	if len(f) >= 2 && f[0] == "systemd" {
		v, _ := strconv.Atoi(f[1])
		return v
	}
	return 0
}

// SetOOMScoreAdj writes a process's OOM preference (children forked afterwards inherit it).
func SetOOMScoreAdj(pid, adj int) error {
	return os.WriteFile("/proc/"+strconv.Itoa(pid)+"/oom_score_adj", []byte(strconv.Itoa(adj)), 0o644)
}

// ---- OOM kills ----

// OOMKills reads the oom_kill counter of every Falak slice (memory.events counts the slice's whole subtree: its
// scopes and services). The map is keyed by slice name.
func OOMKills(root string) map[string]int {
	out := map[string]int{}
	dirs, _ := filepath.Glob(filepath.Join(root, "falak-*.slice"))
	for _, d := range dirs {
		name := strings.TrimSuffix(strings.TrimPrefix(filepath.Base(d), "falak-"), ".slice")
		if !NameRe.MatchString(name) {
			continue
		}
		if n, ok := memoryEvent(filepath.Join(d, "memory.events"), "oom_kill"); ok {
			out[name] = n
		}
	}
	return out
}

func memoryEvent(path, key string) (int, bool) {
	f, err := os.Open(path)
	if err != nil {
		return 0, false
	}
	defer f.Close()
	sc := bufio.NewScanner(f)
	for sc.Scan() {
		k, v, ok := strings.Cut(sc.Text(), " ")
		if ok && k == key {
			n, err := strconv.Atoi(strings.TrimSpace(v))
			return n, err == nil
		}
	}
	return 0, false
}

// WatchOOM turns the slices' oom_kill counters into events (the first reading of a slice is its baseline).
type WatchOOM struct {
	Root   string
	counts resources.Counter
}

// Once reads the counters and reports increases.
func (w *WatchOOM) Once(add func(resources.Event)) {
	root := w.Root
	if root == "" {
		root = CgroupRoot
	}
	cur := OOMKills(root)
	keep := map[string]bool{}
	for name, n := range cur {
		keep[name] = true
		if d := w.counts.Delta(name, n); d > 0 {
			add(resources.Event{Kind: resources.KindOOMKill, Source: resources.SourceSlice, Name: name, Count: d})
		}
	}
	w.counts.Forget(keep)
}
