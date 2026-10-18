package supervisor

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"log/slog"
	"os"
	"os/exec"
	"path/filepath"
	"sort"
	"strings"
	"sync"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/cgroup"
	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/envlinks"
	"github.com/OthmanHaba/falak/agent/internal/obs"
	"github.com/OthmanHaba/falak/agent/internal/resources"
	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// Options configures a Supervisor.
type Options struct {
	StateDir string // proc.json lives here ("" = no persistence)
	// SecretsPath is a tmpfs file for the masked env variables of persisted programs: proc.json on disk keeps only
	// the others. After a reboot it is gone and those programs wait (WaitingSites) until proc.apply sends them again.
	SecretsPath string
	LogDir      string // default program log directory (default /var/log/falak)
	Sink        obs.Sink
	Logger      *slog.Logger
	// Slices converges proc.apply `slices` (nil: a payload with slices is refused).
	Slices interface {
		Apply(ctx context.Context, desired []cgroup.Slice) ([]string, map[string]string, error)
		Prune(ctx context.Context, keep []cgroup.Slice) ([]string, error)
	}
	// Systemd runs systemctl for programs in slices (version, leftover scopes); default runner.Exec.
	Systemd runner.Runner
	// LookPath and CanEnter check a scope launch beforehand (tests replace them); default exec.LookPath and
	// cgroup.UserCanEnter.
	LookPath func(string) (string, error)
	CanEnter func(dir string, uid, gid uint32, groups []uint32) error
	// DefaultSlice is the slice unit a program without a slice of its own runs in (falak.slice on the control-plane
	// machine, so it is capped with every workload); "" = the agent's own cgroup.
	DefaultSlice string
}

// Supervisor owns all supervised programs.
type Supervisor struct {
	opts Options
	log  *slog.Logger

	applyMu  sync.Mutex // serializes apply/restart/shutdown
	mu       sync.Mutex
	programs map[string]*program
	waiting  map[string]waitingProgram // restored programs whose secrets are gone

	systemdOnce sync.Once
	systemdVer  int
}

// waitingProgram is a persisted program that cannot start: its secret env variables were on the tmpfs.
type waitingProgram struct {
	spec Program  // env without the secrets
	keys []string // the missing variables
}

type program struct {
	spec      Program // defaults applied
	hash      string
	instances []*instance
	out, err  *rotFile
}

// New creates a Supervisor. Call Start to restore persisted state.
func New(o Options) *Supervisor {
	if o.LogDir == "" {
		o.LogDir = "/var/log/falak"
	}
	if o.Sink == nil {
		o.Sink = obs.Nop{}
	}
	if o.Logger == nil {
		o.Logger = slog.Default()
	}
	if o.Systemd == nil {
		o.Systemd = runner.Exec{}
	}
	if o.LookPath == nil {
		o.LookPath = exec.LookPath
	}
	if o.CanEnter == nil {
		o.CanEnter = cgroup.UserCanEnter
	}
	return &Supervisor{opts: o, log: o.Logger.With("component", "supervisor"), programs: map[string]*program{}}
}

// Register adds proc.apply, proc.restart and proc.status.
func (s *Supervisor) Register(reg *commands.Registry) {
	reg.Register("proc.apply", commands.Typed(func(ctx context.Context, p ApplyPayload, st commands.Stream) (any, error) {
		return s.ApplyWithSlices(ctx, p.Programs, p.Slices)
	}))
	reg.Register("proc.restart", commands.Typed(func(ctx context.Context, p RestartPayload, st commands.Stream) (any, error) {
		names, err := s.resolve(p.Names, p.Site)
		if err != nil {
			return nil, err
		}
		if err := s.Restart(ctx, names); err != nil {
			return nil, err
		}
		return RestartResult{Restarted: nonNil(names)}, nil
	}))
	reg.Register("proc.status", commands.Typed(func(ctx context.Context, p StatusPayload, st commands.Stream) (any, error) {
		return StatusResult{Processes: s.Status(p.Names)}, nil
	}))
}

// Payloads and results.
type (
	ApplyPayload struct {
		Programs []Program `json:"programs"`
		// Slices is the full desired set of falak-<name>.slice units (programs' and PHP-FPM masters' limits).
		Slices []cgroup.Slice `json:"slices,omitempty"`
	}
	ApplyResult struct {
		Changed   bool     `json:"changed"`
		Started   []string `json:"started"`
		Stopped   []string `json:"stopped"`
		Restarted []string `json:"restarted"`
		Unchanged []string `json:"unchanged"`
		// Slices whose limits changed (applied live) or were removed.
		Slices []string `json:"slices,omitempty"`
		// SliceErrors are the slices that were skipped (invalid, or set-property failed), by name: their programs run
		// without them.
		SliceErrors map[string]string `json:"slice_errors,omitempty"`
	}
	RestartPayload struct {
		Names []string `json:"names,omitempty"`
		Site  string   `json:"site,omitempty"`
	}
	RestartResult struct {
		Restarted []string `json:"restarted"`
	}
	StatusPayload struct {
		Names []string `json:"names,omitempty"`
	}
	StatusResult struct {
		Processes []ProcessStatus `json:"processes"`
	}
)

type stateFile struct {
	Programs []Program `json:"programs"`
	// SecretKeys are each program's env variables kept on the tmpfs (Options.SecretsPath), not in this file.
	SecretKeys map[string][]string `json:"secret_keys,omitempty"`
}

// Start restores the persisted desired set. Programs keep running until Shutdown.
func (s *Supervisor) Start(ctx context.Context) error {
	if s.opts.StateDir == "" {
		return nil
	}
	b, err := os.ReadFile(filepath.Join(s.opts.StateDir, "proc.json"))
	if errors.Is(err, fs.ErrNotExist) {
		return nil
	}
	if err != nil {
		return err
	}
	var st stateFile
	if err := json.Unmarshal(b, &st); err != nil {
		return fmt.Errorf("proc.json: %w", err)
	}
	stored := envlinks.SecretStore{Path: s.opts.SecretsPath}.Load()
	var ready []Program
	waiting := map[string]waitingProgram{}
	for _, p := range st.Programs {
		keys := st.SecretKeys[p.Name]
		env, ok := envlinks.Restore(p.Env, keys, stored[p.Name])
		if !ok {
			// Never started without its secrets: it waits for the control plane (heartbeat missing_secrets).
			s.log.Warn("program waits for its secrets", "program", p.Name, "site", p.Site)
			waiting[p.Name] = waitingProgram{spec: p, keys: keys}
			continue
		}
		p.Env = env
		ready = append(ready, p)
	}
	s.stopOrphanScopes(ctx, ready)
	_, err = s.apply(ctx, ready, waiting)
	return err
}

// stopOrphanScopes stops the falak-proc-*.scope units no restored program instance owns: programs of an agent that
// crashed run on in their scopes (outside the agent's cgroup), and a program removed meanwhile would never be stopped.
// The scopes of restored programs are stopped by their own launch.
func (s *Supervisor) stopOrphanScopes(ctx context.Context, restored []Program) {
	if s.opts.Slices == nil {
		return
	}
	res, err := s.opts.Systemd.Run(ctx, runner.Cmd{Name: "systemctl", Args: []string{"list-units", "--type=scope", "--all", "--plain", "--no-legend", "--no-pager", "falak-proc-*"}})
	if err != nil || res.ExitCode != 0 {
		return
	}
	owned := map[string]bool{}
	for _, p := range restored {
		for i := 0; i < max(1, p.Numprocs); i++ {
			owned["falak-proc-"+p.Name+"-"+itoa(i)+".scope"] = true
		}
	}
	for _, line := range strings.Split(string(res.Stdout), "\n") {
		fields := strings.Fields(line)
		if len(fields) == 0 || !strings.HasSuffix(fields[0], ".scope") || !cgroup.ScopeUnitRe.MatchString(strings.TrimSuffix(fields[0], ".scope")) || owned[fields[0]] {
			continue
		}
		s.log.Warn("stopping orphaned program scope", "unit", fields[0])
		_, _ = s.opts.Systemd.Run(ctx, runner.Cmd{Name: "systemctl", Args: []string{"stop", "--quiet", fields[0]}})
	}
}

// WaitingSites lists the sites of programs waiting for their secrets.
func (s *Supervisor) WaitingSites() []string {
	s.mu.Lock()
	defer s.mu.Unlock()
	seen := map[string]bool{}
	var out []string
	for _, w := range s.waiting {
		if w.spec.Site != "" && !seen[w.spec.Site] {
			seen[w.spec.Site] = true
			out = append(out, w.spec.Site)
		}
	}
	sort.Strings(out)
	return out
}

// Shutdown gracefully stops every program (desired set stays persisted).
func (s *Supervisor) Shutdown() {
	s.applyMu.Lock()
	defer s.applyMu.Unlock()
	s.mu.Lock()
	progs := make([]*program, 0, len(s.programs))
	for _, p := range s.programs {
		progs = append(progs, p)
	}
	s.mu.Unlock()
	parallel(progs, func(p *program) { stopProgram(p) })
}

// Apply converges to the desired program set (which replaces any program waiting for its secrets).
func (s *Supervisor) Apply(ctx context.Context, desired []Program) (ApplyResult, error) {
	return s.apply(ctx, desired, nil)
}

// ApplyWithSlices converges the slices first (new limits apply live, programs moving into a slice find it ready),
// then the programs, then removes the slices nothing uses any more.
func (s *Supervisor) ApplyWithSlices(ctx context.Context, desired []Program, slices []cgroup.Slice) (ApplyResult, error) {
	// Invalid slices are skipped and reported, never the whole set: one service's bad limits must not stop the others.
	valid, errs := cgroup.Partition(slices)
	if s.opts.Slices == nil && len(valid) > 0 {
		for _, sl := range valid {
			errs[sl.Name] = "slices are not supported on this host"
		}
		valid = nil
	}
	var changed []string
	if s.opts.Slices != nil && len(valid) > 0 {
		var applyErrs map[string]string
		var err error
		if changed, applyErrs, err = s.opts.Slices.Apply(ctx, valid); err != nil {
			return ApplyResult{}, fmt.Errorf("slices: %w", err)
		}
		for name, e := range applyErrs {
			errs[name] = e
		}
	}
	usable := map[string]bool{}
	for _, sl := range valid {
		if _, failed := errs[sl.Name]; !failed {
			usable[sl.Name] = true
		}
	}
	// A program whose slice is missing or failed runs without it (its limits are reported as not applied).
	programs := make([]Program, len(desired))
	for i, p := range desired {
		if p.Slice != "" && !usable[p.Slice] {
			if _, known := errs[p.Slice]; !known {
				errs[p.Slice] = fmt.Sprintf("slice %q is not in slices", p.Slice)
			}
			s.log.Warn("program runs without its slice", "program", p.Name, "slice", p.Slice, "err", errs[p.Slice])
			p.Slice = ""
		}
		programs[i] = p
	}
	res, err := s.Apply(ctx, programs)
	if len(errs) > 0 {
		res.SliceErrors = errs
	}
	if err != nil || s.opts.Slices == nil {
		return res, err
	}
	// Units of slices that failed keep their file (and last good limits) until they are fixed or dropped.
	keep := append([]cgroup.Slice(nil), valid...)
	for name := range errs {
		if cgroup.NameRe.MatchString(name) {
			keep = append(keep, cgroup.Slice{Name: name})
		}
	}
	removed, err := s.opts.Slices.Prune(ctx, keep)
	res.Slices = append(changed, removed...)
	if len(res.Slices) == 0 {
		res.Slices = nil
	} else {
		sort.Strings(res.Slices)
		res.Changed = true
	}
	return res, err
}

// systemdVersion is the host's systemd version (0 = unknown), read once.
func (s *Supervisor) systemdVersion() int {
	s.systemdOnce.Do(func() { s.systemdVer = cgroup.SystemdVersion(context.Background(), s.opts.Systemd) })
	return s.systemdVer
}

// RestartPollEvery is how often program restart counters are compared.
var RestartPollEvery = time.Minute

// WatchRestarts reports program restarts (each instance's counter since the last poll) until ctx ends.
func (s *Supervisor) WatchRestarts(ctx context.Context, add func(resources.Event)) {
	var counts resources.Counter
	t := time.NewTicker(RestartPollEvery)
	defer t.Stop()
	for {
		s.restartsOnce(&counts, add)
		select {
		case <-ctx.Done():
			return
		case <-t.C:
		}
	}
}

func (s *Supervisor) restartsOnce(counts *resources.Counter, add func(resources.Event)) {
	sites := map[string]string{}
	s.mu.Lock()
	for name, p := range s.programs {
		sites[name] = p.spec.Site
	}
	s.mu.Unlock()
	keep := map[string]bool{}
	byName := map[string]int{}
	for _, st := range s.Status(nil) {
		key := st.Name + ":" + itoa(st.Instance)
		keep[key] = true
		byName[st.Name] += counts.Delta(key, st.Restarts)
	}
	counts.Forget(keep)
	names := make([]string, 0, len(byName))
	for n, c := range byName {
		if c > 0 {
			names = append(names, n)
		}
	}
	sort.Strings(names)
	for _, n := range names {
		add(resources.Event{Kind: resources.KindRestart, Source: resources.SourceProgram, Name: n, Site: sites[n], Count: byName[n]})
	}
}

func (s *Supervisor) apply(ctx context.Context, desired []Program, waiting map[string]waitingProgram) (ApplyResult, error) {
	res := ApplyResult{Started: []string{}, Stopped: []string{}, Restarted: []string{}, Unchanged: []string{}}
	want := map[string]Program{}
	for _, p := range desired {
		if err := p.validate(); err != nil {
			return res, &commands.PayloadError{Err: err}
		}
		if _, dup := want[p.Name]; dup {
			return res, &commands.PayloadError{Err: fmt.Errorf("duplicate program %q", p.Name)}
		}
		want[p.Name] = p.withDefaults(s.opts.LogDir)
	}
	s.applyMu.Lock()
	defer s.applyMu.Unlock()

	s.mu.Lock()
	var toStop []*program
	var toStart []Program
	for name, cur := range s.programs {
		w, ok := want[name]
		switch {
		case !ok:
			toStop = append(toStop, cur)
			res.Stopped = append(res.Stopped, name)
		case w.hash() != cur.hash:
			toStop = append(toStop, cur)
			toStart = append(toStart, w)
			res.Restarted = append(res.Restarted, name)
		default:
			res.Unchanged = append(res.Unchanged, name)
		}
	}
	for name, w := range want {
		if _, ok := s.programs[name]; !ok {
			toStart = append(toStart, w)
			res.Started = append(res.Started, name)
		}
	}
	for _, p := range toStop {
		delete(s.programs, p.spec.Name)
	}
	s.mu.Unlock()

	parallel(toStop, func(p *program) { stopProgram(p) })

	var errs []error
	for _, spec := range toStart {
		p, err := s.newProgram(spec)
		if err != nil {
			errs = append(errs, err)
			continue
		}
		s.mu.Lock()
		s.programs[spec.Name] = p
		s.mu.Unlock()
		if *spec.Autostart {
			for _, in := range p.instances {
				in.start()
			}
		}
	}
	for _, l := range [][]string{res.Started, res.Stopped, res.Restarted, res.Unchanged} {
		sort.Strings(l)
	}
	res.Changed = len(res.Started)+len(res.Stopped)+len(res.Restarted) > 0
	s.mu.Lock()
	s.waiting = waiting
	s.mu.Unlock()
	if err := s.persist(); err != nil {
		errs = append(errs, err)
	}
	s.log.Info("proc.apply", "started", res.Started, "stopped", res.Stopped, "restarted", res.Restarted)
	return res, errors.Join(errs...)
}

func (s *Supervisor) newProgram(spec Program) (*program, error) {
	out, err := openRot(spec.Log.Stdout, *spec.Log.MaxBytes, spec.Log.MaxFiles)
	if err != nil {
		return nil, fmt.Errorf("program %s: stdout log: %w", spec.Name, err)
	}
	errf := out
	if spec.Log.Stderr != spec.Log.Stdout {
		if errf, err = openRot(spec.Log.Stderr, *spec.Log.MaxBytes, spec.Log.MaxFiles); err != nil {
			out.Close()
			return nil, fmt.Errorf("program %s: stderr log: %w", spec.Name, err)
		}
	}
	p := &program{spec: spec, hash: spec.hash(), out: out, err: errf}
	for i := 0; i < spec.Numprocs; i++ {
		p.instances = append(p.instances, newInstance(s, spec, i, out, errf))
	}
	return p, nil
}

func stopProgram(p *program) {
	parallel(p.instances, func(in *instance) { in.stop() })
	p.out.Close()
	p.err.Close()
}

// RestartSite restarts every program of a site (none: nothing to do) and returns their names.
func (s *Supervisor) RestartSite(ctx context.Context, site string) ([]string, error) {
	names, err := s.resolve(nil, site)
	if err != nil || len(names) == 0 {
		return names, err
	}
	return names, s.Restart(ctx, names)
}

// Restart gracefully restarts the named programs (all when names is empty). Stopped/exited instances
// are started. Unknown names are an error.
func (s *Supervisor) Restart(ctx context.Context, names []string) error {
	s.applyMu.Lock()
	defer s.applyMu.Unlock()
	s.mu.Lock()
	var targets []*program
	if len(names) == 0 {
		for _, p := range s.programs {
			targets = append(targets, p)
		}
	} else {
		for _, n := range names {
			p, ok := s.programs[n]
			if !ok {
				s.mu.Unlock()
				return fmt.Errorf("unknown program %q", n)
			}
			targets = append(targets, p)
		}
	}
	s.mu.Unlock()
	parallel(targets, func(p *program) {
		for _, in := range p.instances {
			in.stop()
			in.mu.Lock()
			in.restarts = 0
			in.mu.Unlock()
			in.start()
		}
	})
	return ctx.Err()
}

// resolve expands a proc.restart selector.
func (s *Supervisor) resolve(names []string, site string) ([]string, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	var out []string
	if len(names) > 0 {
		for _, n := range names {
			p, ok := s.programs[n]
			if !ok {
				return nil, fmt.Errorf("unknown program %q", n)
			}
			if site == "" || p.spec.Site == site {
				out = append(out, n)
			}
		}
	} else {
		for n, p := range s.programs {
			if site == "" || p.spec.Site == site {
				out = append(out, n)
			}
		}
	}
	sort.Strings(out)
	return out, nil
}

// Status reports instance state (all programs when names is empty).
func (s *Supervisor) Status(names []string) []ProcessStatus {
	s.mu.Lock()
	var progs []*program
	filter := map[string]bool{}
	for _, n := range names {
		filter[n] = true
	}
	for n, p := range s.programs {
		if len(filter) == 0 || filter[n] {
			progs = append(progs, p)
		}
	}
	s.mu.Unlock()
	out := []ProcessStatus{}
	for _, p := range progs {
		for _, in := range p.instances {
			out = append(out, in.status())
		}
	}
	sort.Slice(out, func(i, j int) bool {
		if out[i].Name != out[j].Name {
			return out[i].Name < out[j].Name
		}
		return out[i].Instance < out[j].Instance
	})
	return out
}

func (s *Supervisor) persist() error {
	if s.opts.StateDir == "" {
		return nil
	}
	s.mu.Lock()
	st := stateFile{Programs: []Program{}, SecretKeys: map[string][]string{}}
	secrets := map[string]map[string]string{}
	for _, p := range s.programs {
		spec := p.spec
		plain, secret := envlinks.Split(spec.Env, spec.Mask)
		spec.Env = plain
		if len(secret) > 0 {
			secrets[spec.Name] = secret
			st.SecretKeys[spec.Name] = envlinks.Keys(secret)
		}
		st.Programs = append(st.Programs, spec)
	}
	for name, w := range s.waiting {
		st.Programs = append(st.Programs, w.spec)
		st.SecretKeys[name] = w.keys
	}
	s.mu.Unlock()
	sort.Slice(st.Programs, func(i, j int) bool { return st.Programs[i].Name < st.Programs[j].Name })
	// Secrets first: proc.json never names a secret the tmpfs doesn't hold yet.
	if err := (envlinks.SecretStore{Path: s.opts.SecretsPath}).Save(secrets); err != nil {
		return err
	}
	b, err := json.MarshalIndent(st, "", "  ")
	if err != nil {
		return err
	}
	if err := os.MkdirAll(s.opts.StateDir, 0o700); err != nil {
		return err
	}
	path := filepath.Join(s.opts.StateDir, "proc.json")
	tmp := path + ".tmp"
	if err := os.WriteFile(tmp, b, 0o600); err != nil {
		return err
	}
	return os.Rename(tmp, path)
}

func parallel[T any](items []T, fn func(T)) {
	var wg sync.WaitGroup
	for _, it := range items {
		wg.Add(1)
		go func(it T) {
			defer wg.Done()
			fn(it)
		}(it)
	}
	wg.Wait()
}

func nonNil(s []string) []string {
	if s == nil {
		return []string{}
	}
	return s
}
