package cron

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"log/slog"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"syscall"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/cgroup"
	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/envlinks"
	"github.com/OthmanHaba/falak/agent/internal/obs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/transport"
)

// InsightsPoster posts NDJSON to POST /agent/v1/insights (implemented by the transport client).
type InsightsPoster interface {
	PostInsights(ctx context.Context, ndjson []byte) error
}

// Clock abstracts time for tests.
type Clock interface {
	Now() time.Time
	After(d time.Duration) <-chan time.Time
}

type realClock struct{}

func (realClock) Now() time.Time                         { return time.Now() }
func (realClock) After(d time.Duration) <-chan time.Time { return time.After(d) }

// Options configures the scheduler.
type Options struct {
	StateDir string // cron.json ("" = no persistence)
	// SecretsPath is a tmpfs file for the masked env variables of persisted jobs: cron.json on disk keeps only the
	// others. After a reboot it is gone and those jobs wait (WaitingSites) until cron.apply sends them again.
	SecretsPath string
	Runner      runner.Runner
	Insights    InsightsPoster // nil = heartbeats only as spans
	Sink        obs.Sink
	Clock       Clock
	Logger      *slog.Logger
	// SiteID resolves a site slug to its ULID for heartbeats (optional; defaults to the slug).
	SiteID func(slug string) string
	// Slice is the slice unit runs go into, each as a transient scope (falak.slice on the control-plane machine, so
	// a job is capped with every workload); "" = the agent's own cgroup. LookupUser resolves a job's user then
	// (default runner.Credential): the scope starts as root and setpriv drops to the user.
	Slice      string
	LookupUser func(name string) (*syscall.Credential, string, error)
}

// Job mirrors one cron.apply job.
type Job struct {
	Name      string            `json:"name"`
	Schedule  string            `json:"schedule"`
	Command   string            `json:"command"`
	User      string            `json:"user,omitempty"`
	Cwd       string            `json:"cwd,omitempty"`
	Env       map[string]string `json:"env,omitempty"`
	Timezone  string            `json:"timezone,omitempty"`
	Overlap   string            `json:"overlap,omitempty"`
	TimeoutS  int               `json:"timeout_s,omitempty"`
	Heartbeat *bool             `json:"heartbeat,omitempty"`
	Site      string            `json:"site,omitempty"`
	// Mask names the secret variables of env (kept on the tmpfs, never in cron.json).
	Mask []string `json:"mask,omitempty"`
}

// stateFile is cron.json: the jobs without their secret variables, which live on the tmpfs (Options.SecretsPath).
type stateFile struct {
	Jobs       []Job               `json:"jobs"`
	SecretKeys map[string][]string `json:"secret_keys,omitempty"`
}

// waitingJob is a persisted job that cannot run: its secret env variables were on the tmpfs.
type waitingJob struct {
	job  Job
	keys []string
}

// ApplyPayload is the cron.apply payload.
type ApplyPayload struct {
	Jobs []Job `json:"jobs"`
}

// ApplyResult is the cron.apply result.
type ApplyResult struct {
	Changed bool      `json:"changed"`
	Jobs    []NextRun `json:"jobs"`
}

// NextRun reports the next activation of a job.
type NextRun struct {
	Name    string    `json:"name"`
	NextRun time.Time `json:"next_run"`
}

// Heartbeat is one NDJSON line posted to /agent/v1/insights (cron.apply.schema.json $defs.heartbeat).
type Heartbeat struct {
	Kind        string     `json:"kind"`
	Job         string     `json:"job"`
	SiteID      string     `json:"site_id,omitempty"`
	Schedule    string     `json:"schedule,omitempty"`
	Status      string     `json:"status"`
	ExitCode    *int       `json:"exit_code,omitempty"`
	DurationMS  *int64     `json:"duration_ms,omitempty"`
	ScheduledAt time.Time  `json:"scheduled_at"`
	StartedAt   *time.Time `json:"started_at,omitempty"`
	At          time.Time  `json:"at"`
}

// Run statuses.
const (
	StatusFinished = "finished"
	StatusFailed   = "failed"
	StatusSkipped  = "skipped"
	StatusTimeout  = "timeout"
	// ExitTimeout is timeout(1)'s exit code: a command that exits with it timed out.
	ExitTimeout = 124
)

var jobNameRe = regexp.MustCompile(`^[a-z0-9][a-z0-9_.-]{0,62}$`)

type entry struct {
	job  Job
	sch  Schedule
	next time.Time
}

// Scheduler runs cron jobs.
type Scheduler struct {
	opts Options
	log  *slog.Logger

	mu      sync.Mutex
	entries map[string]*entry
	hash    string
	running map[string]int // job name → in-flight runs
	waiting map[string]waitingJob
	wake    chan struct{}
	wg      sync.WaitGroup
	runs    atomic.Uint64 // names the runs' scopes (runs of one job may overlap)
}

// inSlice wraps a run in a transient scope of Options.Slice (cgroup.ScopeCommand): systemd-run starts it as root, so
// the user is dropped by setpriv instead of the runner, with the environment the runner would have set for that user.
func (s *Scheduler) inSlice(cmd runner.Cmd, job string) (runner.Cmd, error) {
	sp := cgroup.ScopeSpec{SliceUnit: s.opts.Slice, Unit: "falak-cron-" + job + "-" + strconv.FormatUint(s.runs.Add(1), 10),
		Command: append([]string{cmd.Name}, cmd.Args...)}
	if cmd.User != "" {
		cred, home, err := s.opts.LookupUser(cmd.User)
		if err != nil {
			return cmd, err
		}
		sp.AsUser, sp.UID, sp.GID = true, cred.Uid, cred.Gid
		cmd.Env = append([]string{"HOME=" + home, "USER=" + cmd.User, "LOGNAME=" + cmd.User}, cmd.Env...)
		cmd.User = ""
	}
	argv := cgroup.ScopeCommand(sp)
	cmd.Name, cmd.Args = argv[0], argv[1:]
	return cmd, nil
}

// New creates a scheduler; call Start to restore state and run the loop.
func New(o Options) *Scheduler {
	if o.Clock == nil {
		o.Clock = realClock{}
	}
	if o.Sink == nil {
		o.Sink = obs.Nop{}
	}
	if o.Logger == nil {
		o.Logger = slog.Default()
	}
	if o.Runner == nil {
		o.Runner = runner.Exec{}
	}
	if o.LookupUser == nil {
		o.LookupUser = runner.Credential
	}
	return &Scheduler{opts: o, log: o.Logger.With("component", "cron"), entries: map[string]*entry{},
		running: map[string]int{}, wake: make(chan struct{}, 1)}
}

// Register adds cron.apply.
func (s *Scheduler) Register(reg *commands.Registry) {
	reg.Register("cron.apply", commands.Typed(func(ctx context.Context, p ApplyPayload, st commands.Stream) (any, error) {
		return s.Apply(p.Jobs)
	}))
}

// Start restores persisted jobs and runs the scheduling loop until ctx is done. It returns after the
// loop has been started; in-flight runs are cancelled with ctx.
func (s *Scheduler) Start(ctx context.Context) error {
	if s.opts.StateDir != "" {
		b, err := os.ReadFile(filepath.Join(s.opts.StateDir, "cron.json"))
		switch {
		case errors.Is(err, fs.ErrNotExist):
		case err != nil:
			return err
		default:
			var st stateFile
			if err := json.Unmarshal(b, &st); err != nil {
				return fmt.Errorf("cron.json: %w", err)
			}
			stored := envlinks.SecretStore{Path: s.opts.SecretsPath}.Load()
			var ready []Job
			waiting := map[string]waitingJob{}
			for _, j := range st.Jobs {
				env, ok := envlinks.Restore(j.Env, st.SecretKeys[j.Name], stored[j.Name])
				if !ok {
					s.log.Warn("cron job waits for its secrets", "job", j.Name, "site", j.Site)
					waiting[j.Name] = waitingJob{job: j, keys: st.SecretKeys[j.Name]}
					continue
				}
				j.Env = env
				ready = append(ready, j)
			}
			if _, err := s.apply(ready, waiting); err != nil {
				return err
			}
		}
	}
	s.wg.Add(1)
	go func() {
		defer s.wg.Done()
		s.loop(ctx)
	}()
	return nil
}

// Wait blocks until the loop and all runs have ended (after ctx cancellation).
func (s *Scheduler) Wait() { s.wg.Wait() }

// Apply replaces the job set (and any job waiting for its secrets).
func (s *Scheduler) Apply(jobs []Job) (ApplyResult, error) { return s.apply(jobs, nil) }

// WaitingSites lists the sites of jobs waiting for their secrets.
func (s *Scheduler) WaitingSites() []string {
	s.mu.Lock()
	defer s.mu.Unlock()
	seen := map[string]bool{}
	var out []string
	for _, w := range s.waiting {
		if w.job.Site != "" && !seen[w.job.Site] {
			seen[w.job.Site] = true
			out = append(out, w.job.Site)
		}
	}
	sort.Strings(out)
	return out
}

func (s *Scheduler) apply(jobs []Job, waiting map[string]waitingJob) (ApplyResult, error) {
	now := s.opts.Clock.Now()
	next := map[string]*entry{}
	for _, j := range jobs {
		if !jobNameRe.MatchString(j.Name) {
			return ApplyResult{}, &commands.PayloadError{Err: fmt.Errorf("invalid job name %q", j.Name)}
		}
		if _, dup := next[j.Name]; dup {
			return ApplyResult{}, &commands.PayloadError{Err: fmt.Errorf("duplicate job %q", j.Name)}
		}
		if strings.TrimSpace(j.Command) == "" {
			return ApplyResult{}, &commands.PayloadError{Err: fmt.Errorf("job %s: empty command", j.Name)}
		}
		switch j.Overlap {
		case "", "allow", "skip":
		default:
			return ApplyResult{}, &commands.PayloadError{Err: fmt.Errorf("job %s: invalid overlap %q", j.Name, j.Overlap)}
		}
		loc := time.UTC
		if j.Timezone != "" {
			l, err := time.LoadLocation(j.Timezone)
			if err != nil {
				return ApplyResult{}, &commands.PayloadError{Err: fmt.Errorf("job %s: %w", j.Name, err)}
			}
			loc = l
		}
		sch, err := Parse(j.Schedule, loc)
		if err != nil {
			return ApplyResult{}, &commands.PayloadError{Err: fmt.Errorf("job %s: %w", j.Name, err)}
		}
		next[j.Name] = &entry{job: j, sch: sch}
	}
	sorted := append([]Job(nil), jobs...)
	sort.Slice(sorted, func(i, k int) bool { return sorted[i].Name < sorted[k].Name })
	raw, _ := json.Marshal(ApplyPayload{Jobs: sorted})
	sum := sha256.Sum256(raw)
	h := hex.EncodeToString(sum[:])

	s.mu.Lock()
	changed := h != s.hash
	persist := changed || len(waiting) > 0 || len(s.waiting) > 0
	s.waiting = waiting
	for name, e := range next {
		// Keep the pending activation of an unchanged job so a re-apply never skips/duplicates a run.
		if old, ok := s.entries[name]; ok && sameJob(old.job, e.job) {
			e.next = old.next
		} else {
			e.next = e.sch.Next(now)
		}
	}
	s.entries = next
	s.hash = h
	res := ApplyResult{Changed: changed, Jobs: []NextRun{}}
	for _, j := range sorted {
		res.Jobs = append(res.Jobs, NextRun{Name: j.Name, NextRun: next[j.Name].next.UTC()})
	}
	s.mu.Unlock()

	if persist && s.opts.StateDir != "" {
		if err := s.persist(sorted, waiting); err != nil {
			return res, err
		}
	}
	select {
	case s.wake <- struct{}{}:
	default:
	}
	return res, nil
}

func sameJob(a, b Job) bool {
	x, _ := json.Marshal(a)
	y, _ := json.Marshal(b)
	return bytes.Equal(x, y)
}

func (s *Scheduler) loop(ctx context.Context) {
	for {
		now := s.opts.Clock.Now()
		s.Tick(ctx, now)
		s.mu.Lock()
		var earliest time.Time
		for _, e := range s.entries {
			if !e.next.IsZero() && (earliest.IsZero() || e.next.Before(earliest)) {
				earliest = e.next
			}
		}
		s.mu.Unlock()
		wait := time.Hour
		if !earliest.IsZero() {
			wait = earliest.Sub(now)
			if wait < 0 {
				wait = 0
			}
		}
		select {
		case <-ctx.Done():
			return
		case <-s.wake:
		case <-s.opts.Clock.After(wait):
		}
	}
}

// Tick launches every job due at or before now and advances its next activation. Exposed for tests.
func (s *Scheduler) Tick(ctx context.Context, now time.Time) {
	type due struct {
		job       Job
		scheduled time.Time
	}
	var runs []due
	s.mu.Lock()
	for _, e := range s.entries {
		if e.next.IsZero() || e.next.After(now) {
			continue
		}
		runs = append(runs, due{e.job, e.next})
		// Advance from now (not from the missed slot) so a long pause does not burst catch-up runs.
		e.next = e.sch.Next(now)
	}
	s.mu.Unlock()
	for _, r := range runs {
		s.launch(ctx, r.job, r.scheduled)
	}
}

func (s *Scheduler) launch(ctx context.Context, j Job, scheduled time.Time) {
	s.mu.Lock()
	if j.Overlap != "allow" && s.running[j.Name] > 0 {
		s.mu.Unlock()
		s.log.Info("cron run skipped (overlap)", "job", j.Name)
		s.report(ctx, j, Heartbeat{Status: StatusSkipped, ScheduledAt: scheduled, At: s.opts.Clock.Now()}, time.Time{}, "")
		return
	}
	s.running[j.Name]++
	s.mu.Unlock()
	s.wg.Add(1)
	go func() {
		defer s.wg.Done()
		defer func() {
			s.mu.Lock()
			s.running[j.Name]--
			s.mu.Unlock()
		}()
		s.run(ctx, j, scheduled)
	}()
}

func (s *Scheduler) run(ctx context.Context, j Job, scheduled time.Time) {
	timeout := time.Duration(j.TimeoutS) * time.Second
	if timeout <= 0 {
		timeout = time.Hour
	}
	rctx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()
	env := make([]string, 0, len(j.Env)+2)
	keys := make([]string, 0, len(j.Env))
	for k := range j.Env {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, k := range keys {
		env = append(env, k+"="+j.Env[k])
	}
	env = append(env, "FALAK_SCHEDULE_NAME="+j.Name)
	started := s.opts.Clock.Now()
	cmd := runner.Cmd{Name: "/bin/sh", Args: []string{"-c", j.Command}, User: j.User, Dir: j.Cwd, Env: env, ClearEnv: true}
	var res runner.Result
	var err error
	if s.opts.Slice != "" {
		cmd, err = s.inSlice(cmd, j.Name)
	}
	if err == nil {
		res, err = s.opts.Runner.Run(rctx, cmd)
	}
	end := s.opts.Clock.Now()
	hb := Heartbeat{ScheduledAt: scheduled, StartedAt: &started, At: end}
	dur := end.Sub(started).Milliseconds()
	hb.DurationMS = &dur
	code := res.ExitCode
	errMsg := ""
	switch {
	case errors.Is(rctx.Err(), context.DeadlineExceeded):
		hb.Status = StatusTimeout
		code = ExitTimeout
		errMsg = "timeout after " + timeout.String()
	case err == nil && code == ExitTimeout:
		// The command reports its own timeout with timeout(1)'s exit code (falak-agent fn-run does).
		hb.Status = StatusTimeout
		errMsg = "the command timed out (exit 124)"
	case err != nil:
		hb.Status = StatusFailed
		errMsg = err.Error()
	case code != 0:
		hb.Status = StatusFailed
		errMsg = fmt.Sprintf("exit status %d", code)
	default:
		hb.Status = StatusFinished
	}
	hb.ExitCode = &code
	s.emitOutput(j, res)
	s.log.Info("cron run", "job", j.Name, "status", hb.Status, "ms", dur)
	s.report(ctx, j, hb, started, errMsg)
}

// emitOutput relays the (bounded) job output as log records.
func (s *Scheduler) emitOutput(j Job, res runner.Result) {
	for _, st := range []struct {
		name string
		b    []byte
		sev  string
	}{{"stdout", res.Stdout, "INFO"}, {"stderr", res.Stderr, "WARN"}} {
		lines := strings.Split(strings.TrimRight(string(st.b), "\n"), "\n")
		if len(lines) > 200 {
			lines = lines[len(lines)-200:]
		}
		for _, l := range lines {
			if l == "" {
				continue
			}
			s.opts.Sink.EmitLog(obs.LogRecord{Time: s.opts.Clock.Now(), Severity: st.sev, Body: l, Site: j.Site,
				Attrs: map[string]string{"falak.schedule.name": j.Name, "log.iostream": st.name}})
		}
	}
}

// report emits the scheduled_task span and posts the heartbeat line.
func (s *Scheduler) report(ctx context.Context, j Job, hb Heartbeat, started time.Time, errMsg string) {
	hb.Kind = "cron_heartbeat"
	hb.Job = j.Name
	hb.Schedule = j.Schedule
	if j.Site != "" {
		hb.SiteID = j.Site
		if s.opts.SiteID != nil {
			if id := s.opts.SiteID(j.Site); id != "" {
				hb.SiteID = id
			}
		}
	}
	hb.ScheduledAt = hb.ScheduledAt.UTC()
	hb.At = hb.At.UTC()
	if hb.StartedAt != nil {
		t := hb.StartedAt.UTC()
		hb.StartedAt = &t
	}
	if started.IsZero() {
		started = hb.At
	}
	attrs := map[string]any{
		"falak.event.type":          "scheduled_task",
		"falak.schedule.name":       j.Name,
		"falak.schedule.expression": j.Schedule,
		"falak.schedule.status":     spanStatus(hb.Status),
	}
	if hb.ExitCode != nil {
		attrs["process.exit.code"] = int64(*hb.ExitCode)
	}
	s.opts.Sink.EmitSpan(obs.Span{Name: j.Name, Kind: "INTERNAL", Start: started, End: hb.At, Site: j.Site,
		Attrs: attrs, Error: hb.Status == StatusFailed || hb.Status == StatusTimeout, ErrorMsg: errMsg})

	if j.Heartbeat != nil && !*j.Heartbeat || s.opts.Insights == nil {
		return
	}
	line, _ := json.Marshal(hb)
	line = append(line, '\n')
	s.wg.Add(1)
	go func() {
		defer s.wg.Done()
		backoff := 500 * time.Millisecond
		for attempt := 0; attempt < 4; attempt++ {
			pctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 10*time.Second)
			err := s.opts.Insights.PostInsights(pctx, line)
			cancel()
			if err == nil || transport.IsRevoked(err) { // revoked: reported once by the client; retrying cannot help
				return
			}
			s.log.Warn("heartbeat post failed", "job", j.Name, "err", err)
			select {
			case <-time.After(backoff):
			case <-ctx.Done():
				return
			}
			backoff *= 2
		}
	}()
}

// spanStatus maps a run status to the telemetry contract's falak.schedule.status enum.
func spanStatus(st string) string {
	switch st {
	case StatusFinished:
		return "finished"
	case StatusSkipped:
		return "skipped"
	default:
		return "failed"
	}
}

// persist writes cron.json without secret variables, which go to the tmpfs first.
func (s *Scheduler) persist(jobs []Job, waiting map[string]waitingJob) error {
	st := stateFile{Jobs: []Job{}, SecretKeys: map[string][]string{}}
	secrets := map[string]map[string]string{}
	for _, j := range jobs {
		plain, secret := envlinks.Split(j.Env, j.Mask)
		j.Env = plain
		if len(secret) > 0 {
			secrets[j.Name] = secret
			st.SecretKeys[j.Name] = envlinks.Keys(secret)
		}
		st.Jobs = append(st.Jobs, j)
	}
	for name, w := range waiting {
		st.Jobs = append(st.Jobs, w.job)
		st.SecretKeys[name] = w.keys
	}
	sort.Slice(st.Jobs, func(i, k int) bool { return st.Jobs[i].Name < st.Jobs[k].Name })
	if err := (envlinks.SecretStore{Path: s.opts.SecretsPath}).Save(secrets); err != nil {
		return err
	}
	raw, err := json.Marshal(st)
	if err != nil {
		return err
	}
	if err := os.MkdirAll(s.opts.StateDir, 0o700); err != nil {
		return err
	}
	p := filepath.Join(s.opts.StateDir, "cron.json")
	if err := os.WriteFile(p+".tmp", raw, 0o600); err != nil {
		return err
	}
	return os.Rename(p+".tmp", p)
}
