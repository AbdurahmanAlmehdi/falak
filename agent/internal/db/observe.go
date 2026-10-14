package db

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"log/slog"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/dbhelper"
	"github.com/OthmanHaba/falak/agent/internal/docker"
	"github.com/OthmanHaba/falak/agent/internal/obs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/sqlnorm"
	"github.com/OthmanHaba/falak/agent/internal/transport"
	metricspb "go.opentelemetry.io/proto/otlp/metrics/v1"
)

// Database observability (docs/plans/OBSERVABILITY.md phase 3): once a minute the Observer runs `falak-db observe` in
// every running database container, reads the engine's stderr since the last pass, and turns both into OTLP metrics
// (falak.db.*), slow-query log records (kind db.slow) and one db.observed request to the control plane.

// ObserveEvery is the pass interval; every ObserveDetailEvery-th pass of an instance adds the detail sections
// (statements, sizes, tables, indexes).
var (
	ObserveEvery       = time.Minute
	ObserveDetailEvery = 5
)

const (
	maxObserved       = 50   // instances in one db.observed request (the schema's maxItems)
	maxSlowRecords    = 100  // db.slow log records per instance and pass; the rest are only counted
	maxlogLines       = 5000 // engine stderr lines read per instance and pass
	maxReportStmts    = 100
	observeExecWait   = 45 * time.Second
	logKindSlow       = "db.slow"
	databasesScope    = "falak-agent/databases"
	unknownCommandRef = "unknown command"
)

// MetricsEmitter receives OTLP metrics (the telemetry relay).
type MetricsEmitter interface {
	EmitMetrics(*metricspb.ResourceMetrics)
}

// Observer runs the passes. Its state is per container: a new container (recreated, upgraded) starts afresh.
type Observer struct {
	db      *DB
	cp      Requester
	logs    obs.Sink
	metrics MetricsEmitter
	log     *slog.Logger
	now     func() time.Time

	mu        sync.Mutex
	instances map[string]*observed
	cpMissing bool // the control plane predates db.observed (404 unknown_request): logged once
}

// observed is what the Observer keeps about one instance between passes.
type observed struct {
	container   string
	engine      string
	unsupported bool // the image's falak-db predates `observe`
	cursor      json.RawMessage
	passes      int
	prev        map[string]float64 // counters of the last pass
	prevAt      time.Time
	stmts       map[string]dbhelper.Statement // cumulative statements of the last detail pass
	haveStmts   bool
	slowShapes  map[string]*ObservedStatement // slow entries since the last detail pass (top queries without query insights)
	slowCount   float64                       // slow queries counted by the agent (PostgreSQL log, SLOWLOG)
	logSince    time.Time
	databases   []dbhelper.DatabaseSize
	tables      []dbhelper.TableStat
	lastQI      string
	prevUptime  float64
	raw         map[string]float64 // counters as the engine reported them last
	offset      map[string]float64 // what they lost without a restart
	connections *Connections
	connAt      time.Time
}

// ObservedInstance is one instance in db.observed (contracts/agent-protocol/requests/db.observed.schema.json).
type ObservedInstance struct {
	ID            string                  `json:"id"`
	Engine        string                  `json:"engine"`
	At            time.Time               `json:"at"`
	WindowS       int                     `json:"window_s"`
	Version       string                  `json:"version,omitempty"`
	QueryInsights *dbhelper.QueryInsights `json:"query_insights,omitempty"`
	Gauges        map[string]float64      `json:"gauges,omitempty"`
	Deltas        map[string]float64      `json:"deltas,omitempty"`
	Labels        map[string]string       `json:"labels,omitempty"`
	Settings      map[string]float64      `json:"settings,omitempty"`
	Memory        *ObservedMemory         `json:"memory,omitempty"`
	Errors        []ObservedError         `json:"errors,omitempty"`
	// The detail sections are only in every ObserveDetailEvery-th report; there, an empty list is sent as [] (an index
	// used since, a table dropped), so the control plane replaces what it kept.
	Statements *[]ObservedStatement     `json:"statements,omitempty"`
	Databases  *[]dbhelper.DatabaseSize `json:"databases,omitempty"`
	Tables     *[]dbhelper.TableStat    `json:"tables,omitempty"`
	Indexes    *[]dbhelper.IndexStat    `json:"indexes,omitempty"`
}

// ObservedMemory is the container's memory from inside its cgroup.
type ObservedMemory struct {
	UsedBytes  int64 `json:"used_bytes"`
	LimitBytes int64 `json:"limit_bytes"`
}

// ObservedError is an engine error kind seen in the window.
type ObservedError struct {
	Kind    string `json:"kind"`
	Count   int    `json:"count"`
	Message string `json:"message,omitempty"`
}

// ObservedStatement is a statement shape with what it did in the window.
type ObservedStatement struct {
	Fingerprint string  `json:"fingerprint"`
	Database    string  `json:"database,omitempty"`
	Query       string  `json:"query"`
	Calls       int64   `json:"calls"`
	TotalMS     float64 `json:"total_ms"`
	MaxMS       float64 `json:"max_ms,omitempty"`
	Rows        int64   `json:"rows,omitempty"`
	NoIndex     int64   `json:"no_index,omitempty"`
	Source      string  `json:"source"`
}

// NewObserver builds the Observer: cp gets db.observed, logs the slow-query records, metrics the falak.db.* metrics.
func (db *DB) NewObserver(cp Requester, logs obs.Sink, metrics MetricsEmitter) *Observer {
	o := &Observer{db: db, cp: cp, logs: logs, metrics: metrics, log: db.d.Logger.With("component", "db-observer"), now: time.Now,
		instances: map[string]*observed{}}
	db.observer.Store(o)
	return o
}

// Run passes every ObserveEvery until ctx ends.
func (o *Observer) Run(ctx context.Context) {
	for {
		select {
		case <-ctx.Done():
			return
		case <-time.After(ObserveEvery):
		}
		o.Pass(ctx)
	}
}

// Pass observes every running, healthy database container once and reports them.
func (o *Observer) Pass(ctx context.Context) {
	if o.db.d.Docker == nil {
		return
	}
	list, err := o.db.d.Docker.ContainerList(ctx, false, []string{LabelInstance})
	if err != nil {
		return
	}
	sort.Slice(list, func(i, j int) bool { return list[i].Labels[LabelInstance] < list[j].Labels[LabelInstance] })
	var report []ObservedInstance
	live := map[string]bool{}
	for _, c := range list {
		id := c.Labels[LabelInstance]
		if !idRe.MatchString(id) || c.State != "running" {
			continue
		}
		live[id] = true
		if healthFromStatus(c.Status) != "healthy" {
			// Starting, unhealthy: not asked anything, but down on the charts and for alerts on falak_db_up.
			if st := o.state(id, c); checkEngine(st.engine) == nil && !st.unsupported {
				o.emitMetrics(id, st.engine, o.now().UTC(), nil, st)
			}
			continue
		}
		if len(report) >= maxObserved {
			continue
		}
		if item := o.observe(ctx, id, c); item != nil {
			report = append(report, *item)
		}
	}
	o.mu.Lock()
	for id := range o.instances {
		if !live[id] {
			delete(o.instances, id)
		}
	}
	o.mu.Unlock()
	if len(report) == 0 || o.cp == nil {
		return
	}
	if err := o.cp.Request(ctx, "db.observed", map[string]any{"instances": report}, nil); err != nil {
		var se *transport.StatusError
		if errors.As(err, &se) && se.Code == 404 {
			o.mu.Lock()
			first := !o.cpMissing
			o.cpMissing = true
			o.mu.Unlock()
			if first {
				o.log.Info("the control plane does not take database reports yet (db.observed); metrics and logs still flow")
			}
			return
		}
		o.log.Warn("db.observed failed", "err", err)
	}
}

// Connections returns the instance's connections from a recent pass (nil when there is none): `stats` is then not
// needed for the heartbeat.
func (o *Observer) Connections(id string) *Connections {
	if o == nil {
		return nil
	}
	o.mu.Lock()
	defer o.mu.Unlock()
	st := o.instances[id]
	if st == nil || st.connections == nil || time.Since(st.connAt) > 2*ObserveEvery {
		return nil
	}
	c := *st.connections
	return &c
}

func (o *Observer) state(id string, c docker.ContainerSummary) *observed {
	o.mu.Lock()
	defer o.mu.Unlock()
	st := o.instances[id]
	if st == nil || st.container != c.ID {
		st = &observed{container: c.ID, engine: c.Labels[LabelEngine]}
		o.instances[id] = st
	}
	return st
}

// observe runs one instance's pass; nil when there is nothing to report (an older image, a failed exec).
func (o *Observer) observe(ctx context.Context, id string, c docker.ContainerSummary) *ObservedInstance {
	st := o.state(id, c)
	if st.unsupported || checkEngine(st.engine) != nil {
		return nil
	}
	now := o.now().UTC()
	detail := st.passes%max(ObserveDetailEvery, 1) == 0
	args := []string{"exec", Container(id), "falak-db", "observe"}
	if detail {
		args = append(args, "--detail")
	}
	if len(st.cursor) > 0 {
		args = append(args, "--cursor", string(st.cursor))
	}
	ectx, cancel := context.WithTimeout(ctx, observeExecWait)
	res, err := o.db.d.Runner.Run(ectx, runner.Cmd{Name: "docker", Args: args})
	cancel()
	if err == nil && res.ExitCode == 2 && strings.Contains(string(res.Stderr), unknownCommandRef) {
		// An image built before v0.16.0: one failed exec per container, then nothing until it changes.
		st.unsupported = true
		return nil
	}
	if err != nil || res.ExitCode != 0 {
		o.log.Debug("falak-db observe failed", "instance", id, "exit", res.ExitCode, "err", err, "stderr", lastLines(string(res.Stderr), 2))
		o.emitMetrics(id, st.engine, now, nil, st)
		return nil
	}
	var ob dbhelper.Observation
	if err := json.Unmarshal(bytes.TrimSpace(res.Stdout), &ob); err != nil {
		o.log.Debug("falak-db observe printed no observation", "instance", id, "err", err)
		o.emitMetrics(id, st.engine, now, nil, st)
		return nil
	}
	st.passes++
	if cur, err := json.Marshal(ob.Cursor); err == nil {
		st.cursor = cur
	}
	scan := o.scanLogs(ctx, id, st, now)
	slow := append(slowFromHelper(ob), scan.slow...)
	o.emitSlow(id, st.engine, slow)
	// PostgreSQL's slow statements are in its log and Redis' in SLOWLOG: counted here. MySQL counts its own.
	if !isMySQLFamily(st.engine) {
		st.slowCount += float64(len(slow))
		ob.Counters["slow_queries"] = st.slowCount
	}
	if ob.Memory != nil {
		ob.Counters["oom_kills"] = float64(ob.Memory.OOMKills)
	}
	if ob.QueryInsights.Status != "on" || isKeyValue(st.engine) {
		st.aggregateSlow(slow, st.engine)
	}
	if detail {
		st.databases, st.tables = ob.Databases, ob.Tables
	}

	item := &ObservedInstance{ID: id, Engine: st.engine, At: now, Version: clip(ob.Version, 100), Gauges: ob.Gauges, Labels: clipLabels(ob.Labels),
		Settings: ob.Settings}
	qi := ob.QueryInsights
	item.QueryInsights = &qi
	if ob.Memory != nil {
		item.Memory = &ObservedMemory{UsedBytes: ob.Memory.UsedBytes, LimitBytes: ob.Memory.LimitBytes}
	}
	// Counters summed over databases drop when one is dropped or its statistics reset, without a restart: kept
	// monotonic, so neither the metrics nor the deltas see a spike. A lower uptime is a real restart.
	uptime := ob.Settings["uptime_s"]
	st.monotonic(ob.Counters, st.prevUptime > 0 && uptime > 0 && uptime < st.prevUptime)
	st.prevUptime = uptime
	if !st.prevAt.IsZero() {
		item.WindowS = int(now.Sub(st.prevAt).Round(time.Second) / time.Second)
		item.Deltas = deltas(st.prev, ob.Counters)
		item.Errors = errorsOf(item.Deltas, ob.Gauges, scan.errors)
	}
	st.prev, st.prevAt = ob.Counters, now
	if detail {
		stmts, dbs, tables, indexes := st.statements(ob.Statements), clipDatabases(ob.Databases), clipTables(ob.Tables), clipIndexes(ob.Indexes)
		if stmts == nil {
			stmts = []ObservedStatement{}
		}
		item.Statements, item.Databases, item.Tables, item.Indexes = &stmts, &dbs, &tables, &indexes
	}
	if limit := ob.Gauges["max_connections"]; limit > 0 {
		o.mu.Lock()
		st.connections, st.connAt = &Connections{Used: int64(ob.Gauges["connections"]), Max: int64(limit)}, time.Now()
		o.mu.Unlock()
	}
	o.emitMetrics(id, st.engine, now, &ob, st)
	return item
}

func isMySQLFamily(engine string) bool { return engine == "mysql" || engine == "mariadb" }

// monotonic adds to each counter what it lost without a restart (a dropped database, a statistics reset), so it never
// goes down; after a restart the counters start again from the engine's values.
func (st *observed) monotonic(c map[string]float64, restarted bool) {
	if st.raw == nil || restarted {
		st.raw, st.offset = map[string]float64{}, map[string]float64{}
	}
	for k, v := range c {
		if prev, ok := st.raw[k]; ok && v < prev {
			st.offset[k] += prev - v
		}
		st.raw[k] = v
		c[k] = v + st.offset[k]
	}
}

// deltaKeys are the counters reported as deltas (the rest only become metrics).
var deltaKeys = []string{"slow_queries", "deadlocks", "cache_hits", "cache_misses", "queries", "aborted_connects",
	"rejected_connections", "max_connections_errors", "evicted_keys", "expired_keys", "oom_kills", "oom_errors",
	"auth_errors", "lock_waits", "temp_bytes", "xact_rollback"}

// deltas are the counters' increases; a counter that went down (a server restart) counts from zero.
func deltas(prev, cur map[string]float64) map[string]float64 {
	out := map[string]float64{}
	diff := func(k string) (float64, bool) {
		c, ok := cur[k]
		if !ok {
			return 0, false
		}
		p, had := prev[k]
		if !had {
			return 0, false
		}
		if c < p {
			return c, true
		}
		return c - p, true
	}
	for _, k := range deltaKeys {
		if d, ok := diff(k); ok {
			name := k
			if k == "xact_rollback" {
				name = "rollbacks"
			}
			out[name] = d
		}
	}
	c, okc := diff("xact_commit")
	r, okr := diff("xact_rollback")
	if okc || okr {
		out["transactions"] = c + r
	}
	return out
}

// errorsOf turns counter deltas, gauges and log matches into the window's error kinds.
func errorsOf(d, gauges map[string]float64, logged map[string]*ObservedError) []ObservedError {
	add := func(kind string, n float64, msg string) {
		if n < 1 {
			return
		}
		e := logged[kind]
		if e == nil {
			e = &ObservedError{Kind: kind, Message: msg}
			logged[kind] = e
		}
		e.Count += int(n)
	}
	if logged == nil {
		logged = map[string]*ObservedError{}
	}
	add("deadlock", d["deadlocks"], "deadlocks detected")
	add("too_many_connections", d["max_connections_errors"]+d["rejected_connections"], "connections refused: the connection limit was reached")
	add("auth_failed", d["aborted_connects"]+d["auth_errors"], "failed connection attempts (wrong password, unknown user or aborted handshake)")
	add("oom", d["oom_kills"], "the engine was killed by the kernel: out of memory")
	add("oom", d["oom_errors"], "writes refused: maxmemory reached with noeviction")
	if gauges["replication_broken"] > 0 {
		add("replication", 1, "the replica's link to its primary is down")
	}
	kinds := make([]string, 0, len(logged))
	for k := range logged {
		kinds = append(kinds, k)
	}
	sort.Strings(kinds)
	var out []ObservedError
	for _, k := range kinds {
		if e := logged[k]; e.Count > 0 {
			e.Message = sqlnorm.Clean(e.Message, 300)
			out = append(out, *e)
		}
	}
	return out
}

// statements are the window's statements: the engine's own (pg_stat_statements, digests) as deltas against the last
// detail pass, else the slow entries gathered since. The first detail pass after start only sets the baseline.
func (st *observed) statements(cur []dbhelper.Statement) []ObservedStatement {
	var out []ObservedStatement
	if len(cur) > 0 {
		next := make(map[string]dbhelper.Statement, len(cur))
		for _, s := range cur {
			next[s.ID] = s
			p, ok := st.stmts[s.ID]
			if !st.haveStmts || !ok {
				continue // no baseline yet: its delta is unknown
			}
			calls, total, rows, noIndex := s.Calls-p.Calls, s.TotalMS-p.TotalMS, s.Rows-p.Rows, s.NoIndex-p.NoIndex
			if calls < 0 || total < 0 { // statistics reset
				calls, total, rows, noIndex = s.Calls, s.TotalMS, s.Rows, s.NoIndex
			}
			if calls <= 0 {
				continue
			}
			out = append(out, ObservedStatement{Fingerprint: fingerprintID(s.ID), Database: clip(s.Database, 128), Query: s.Query, Calls: calls,
				TotalMS: round3(total), MaxMS: round3(s.MaxMS), Rows: max(rows, 0), NoIndex: max(noIndex, 0), Source: s.Source})
		}
		st.stmts, st.haveStmts = next, true
	}
	for _, s := range st.slowShapes {
		out = append(out, *s)
	}
	st.slowShapes = nil
	sort.Slice(out, func(i, j int) bool { return out[i].TotalMS > out[j].TotalMS })
	if len(out) > maxReportStmts {
		out = out[:maxReportStmts]
	}
	return out
}

var fingerprintRe = regexp.MustCompile(`^[a-z]+:[A-Za-z0-9_:.-]{1,100}$`)

func fingerprintID(id string) string {
	if fingerprintRe.MatchString(id) {
		return id
	}
	return "sql:" + sqlnorm.Fingerprint(id)
}

// aggregateSlow groups slow entries by shape: the top queries of instances without query insights (and Redis').
func (st *observed) aggregateSlow(entries []dbhelper.SlowEntry, engine string) {
	for _, e := range entries {
		if e.Query == "" {
			continue
		}
		prefix := "sql:"
		if isKeyValue(engine) {
			prefix = "cmd:"
		}
		key := prefix + sqlnorm.Fingerprint(e.Database+"\x00"+e.Query)
		if st.slowShapes == nil {
			st.slowShapes = map[string]*ObservedStatement{}
		}
		s := st.slowShapes[key]
		if s == nil {
			if len(st.slowShapes) >= maxReportStmts {
				continue
			}
			s = &ObservedStatement{Fingerprint: key, Database: clip(e.Database, 128), Query: e.Query, Source: "slowlog"}
			st.slowShapes[key] = s
		}
		s.Calls++
		s.TotalMS = round3(s.TotalMS + e.DurationMS)
		s.MaxMS = max(s.MaxMS, round3(e.DurationMS))
		s.Rows += e.Rows
	}
}

func round3(f float64) float64 { return float64(int64(f*1000+0.5)) / 1000 }

func clip(s string, n int) string {
	if len(s) <= n {
		return s
	}
	for n > 0 && n < len(s) && s[n]&0xC0 == 0x80 {
		n--
	}
	return s[:n]
}

func clipLabels(m map[string]string) map[string]string {
	out := map[string]string{}
	for k, v := range m {
		if v != "" {
			out[k] = clip(v, 200)
		}
	}
	return out
}

func clipDatabases(in []dbhelper.DatabaseSize) []dbhelper.DatabaseSize {
	out := make([]dbhelper.DatabaseSize, 0, min(len(in), 20))
	for _, d := range in {
		if len(out) == 20 {
			break
		}
		d.Name = clip(d.Name, 128)
		out = append(out, d)
	}
	return out
}

func clipTables(in []dbhelper.TableStat) []dbhelper.TableStat {
	out := make([]dbhelper.TableStat, 0, min(len(in), 200))
	for _, t := range in {
		if len(out) == 200 {
			break
		}
		t.Database, t.Schema, t.Name = clip(t.Database, 128), clip(t.Schema, 128), clip(t.Name, 128)
		out = append(out, t)
	}
	return out
}

func clipIndexes(in []dbhelper.IndexStat) []dbhelper.IndexStat {
	out := make([]dbhelper.IndexStat, 0, min(len(in), 200))
	for _, i := range in {
		if len(out) == 200 {
			break
		}
		i.Database, i.Schema, i.Table, i.Name = clip(i.Database, 128), clip(i.Schema, 128), clip(i.Table, 128), clip(i.Name, 128)
		out = append(out, i)
	}
	return out
}

func slowFromHelper(ob dbhelper.Observation) []dbhelper.SlowEntry { return ob.Slow }

// emitSlow writes the slow entries as log records (kind db.slow) on the container's stream, at most maxSlowRecords.
func (o *Observer) emitSlow(id, engine string, entries []dbhelper.SlowEntry) {
	if o.logs == nil {
		return
	}
	for i, e := range entries {
		if i >= maxSlowRecords {
			break
		}
		attrs := map[string]string{
			"falak.db.instance.id":       id,
			"db.system.name":             systemName(engine),
			"db.query.text":              e.Query,
			"falak.db.query.fingerprint": sqlnorm.Fingerprint(e.Database + "\x00" + e.Query),
			"falak.db.duration_ms":       strconv.FormatFloat(round3(e.DurationMS), 'f', -1, 64),
		}
		if e.Database != "" {
			attrs["db.namespace"] = e.Database
		}
		at := e.At
		if at.IsZero() {
			at = o.now()
		}
		o.logs.EmitLog(obs.LogRecord{Time: at, Severity: "WARN", Body: strconv.FormatFloat(round3(e.DurationMS), 'f', -1, 64) + " ms " + e.Query,
			Service: Container(id), Kind: logKindSlow, Attrs: attrs})
	}
}

// systemName is the OpenTelemetry db.system.name of an engine.
func systemName(engine string) string {
	if engine == "postgres" {
		return "postgresql"
	}
	return engine
}

// ---- engine stderr ----

type logScan struct {
	slow   []dbhelper.SlowEntry
	errors map[string]*ObservedError
}

// scanLogs reads the container's stderr / stdout since the last pass (`docker logs --since`, 5,000 lines at most):
// PostgreSQL's slow statements, and engine errors no counter reports. The first pass only sets the start.
func (o *Observer) scanLogs(ctx context.Context, id string, st *observed, now time.Time) logScan {
	since := st.logSince
	st.logSince = now
	if since.IsZero() {
		return logScan{}
	}
	var out, errOut bytes.Buffer
	ctx, cancel := context.WithTimeout(ctx, 20*time.Second)
	defer cancel()
	res, err := o.db.d.Runner.Run(ctx, runner.Cmd{Name: "docker", Args: []string{"logs", "--since", since.Format(time.RFC3339Nano), "--timestamps",
		"--tail", strconv.Itoa(maxlogLines), Container(id)}, Stdout: &out, Stderr: &errOut})
	if err != nil || res.ExitCode != 0 {
		return logScan{}
	}
	lines := append(timestamped(out.Bytes()), timestamped(errOut.Bytes())...)
	sort.SliceStable(lines, func(i, j int) bool { return lines[i].at.Before(lines[j].at) })
	return scanEngineLog(st.engine, lines)
}

// logLine is one engine log line with Docker's timestamp.
type logLine struct {
	at   time.Time
	text string
}

// timestamped splits `docker logs --timestamps` output; PostgreSQL's continuation lines (a tab first) join the line
// before them.
func timestamped(b []byte) []logLine {
	var out []logLine
	sc := bufio.NewScanner(bytes.NewReader(b))
	sc.Buffer(make([]byte, 64<<10), 1<<20)
	for sc.Scan() {
		l := sc.Text()
		ts, rest, ok := strings.Cut(l, " ")
		t, err := time.Parse(time.RFC3339Nano, ts)
		if !ok || err != nil {
			continue
		}
		if strings.HasPrefix(rest, "\t") && len(out) > 0 {
			out[len(out)-1].text += " " + strings.TrimSpace(rest)
			continue
		}
		out = append(out, logLine{at: t, text: rest})
	}
	return out
}

var (
	// pgLine: '%m [%p] %q%u@%d ' then the level: "2026-10-14 12:00:00.123 UTC [42] app@shop LOG:  duration: …".
	pgLine = regexp.MustCompile(`\[\d+\] (?:(\S*)@(\S*) )?(LOG|ERROR|FATAL|PANIC|WARNING):\s+(.*)$`)
	pgSlow = regexp.MustCompile(`^duration: ([0-9.]+) ms\s+(?:statement|execute [^:]*|bind [^:]*|parse [^:]*): (.*)$`)
)

// logErrors are engine error lines no counter reports, by kind (matched lowercased).
var logErrors = []struct {
	kind    string
	engines string // "" = every engine
	needles []string
}{
	{"too_many_connections", "postgres", []string{"too many clients already", "remaining connection slots are reserved"}},
	{"auth_failed", "postgres", []string{"password authentication failed", "no pg_hba.conf entry"}},
	{"oom", "", []string{"out of memory", "cannot allocate memory", "was terminated by signal 9"}},
	{"replication", "postgres", []string{"could not receive data from wal stream", "could not connect to the primary server"}},
	{"replication", "redis valkey", []string{"master <-> replica sync: error", "error condition on socket for sync"}},
	{"disk_full", "", []string{"no space left on device", "disk is full"}},
	{"crash", "postgres", []string{"panic:", "was terminated by signal"}},
	{"crash", "mysql mariadb", []string{"got signal", "assertion failure"}},
	{"crash", "redis valkey", []string{"crashed by signal", "redis bug report start", "valkey bug report start"}},
}

// scanEngineLog finds slow statements (PostgreSQL) and error kinds in engine log lines.
func scanEngineLog(engine string, lines []logLine) logScan {
	res := logScan{errors: map[string]*ObservedError{}}
	for _, l := range lines {
		text := l.text
		if engine == "postgres" {
			if m := pgLine.FindStringSubmatch(text); m != nil {
				if m[3] == "LOG" {
					if s := pgSlow.FindStringSubmatch(m[4]); s != nil {
						ms, _ := strconv.ParseFloat(s[1], 64)
						res.slow = append(res.slow, dbhelper.SlowEntry{At: l.at.UTC(), DurationMS: ms, Database: m[2], Query: sqlnorm.Normalize(s[2], sqlnorm.Postgres)})
						continue
					}
				}
				text = m[3] + ": " + m[4]
			}
		}
		lower := strings.ToLower(text)
		for _, p := range logErrors {
			if p.engines != "" && !strings.Contains(" "+p.engines+" ", " "+engine+" ") {
				continue
			}
			if p.kind == "oom" && strings.Contains(lower, "was terminated by signal") && !strings.Contains(lower, "signal 9") {
				continue
			}
			if p.kind == "crash" && strings.Contains(lower, "signal 9") {
				continue // counted as oom
			}
			hit := false
			for _, n := range p.needles {
				if strings.Contains(lower, n) {
					hit = true
					break
				}
			}
			if !hit {
				continue
			}
			e := res.errors[p.kind]
			if e == nil {
				e = &ObservedError{Kind: p.kind, Message: sqlnorm.Clean(text, 300)}
				res.errors[p.kind] = e
			}
			e.Count++
			break
		}
	}
	return res
}

// ---- metrics ----

func (o *Observer) emitMetrics(id, engine string, now time.Time, ob *dbhelper.Observation, st *observed) {
	if o.metrics == nil {
		return
	}
	ts := uint64(now.UnixNano())
	m := &metricBuilder{ts: ts}
	if ob == nil {
		m.gauge("falak.db.up", "", 0) // no unit: "1" would make it falak_db_up_ratio
	} else {
		m.gauge("falak.db.up", "", 1)
		buildMetrics(m, engine, ob, st)
	}
	o.metrics.EmitMetrics(&metricspb.ResourceMetrics{
		Resource: resource(id, engine),
		ScopeMetrics: []*metricspb.ScopeMetrics{{
			Scope:   scope(),
			Metrics: m.out,
		}},
	})
}

// buildMetrics maps an observation to the falak.db.* metrics (docs/plans/OBSERVABILITY.md phase 3, "Metrics").
func buildMetrics(m *metricBuilder, engine string, ob *dbhelper.Observation, st *observed) {
	c, g := ob.Counters, ob.Gauges
	has := func(mp map[string]float64, k string) bool { _, ok := mp[k]; return ok }

	var states []string
	switch {
	case engine == "postgres":
		states = []string{"active", "idle", "idle_in_transaction", "other"}
	case isMySQLFamily(engine):
		states = []string{"running", "idle"}
	default:
		states = []string{"blocked", "normal"}
	}
	var pts []point
	for _, s := range states {
		pts = append(pts, point{g["connections_"+s], [][2]string{{"falak.db.connection.state", s}}})
	}
	m.gaugeN("falak.db.connections", "{connection}", pts...)
	if has(g, "max_connections") {
		m.gauge("falak.db.connections.max", "{connection}", g["max_connections"])
	}
	if has(c, "aborted_connects") || has(c, "rejected_connections") {
		m.counter("falak.db.connections.aborted", "{connection}", c["aborted_connects"]+c["rejected_connections"])
	}
	if has(c, "queries") {
		m.counter("falak.db.queries", "{query}", c["queries"])
	}
	if isMySQLFamily(engine) {
		var ops []point
		for _, op := range []string{"select", "insert", "update", "delete"} {
			ops = append(ops, point{c["op_"+op], [][2]string{{"falak.db.operation", op}}})
		}
		m.counterN("falak.db.operations", "{operation}", ops...)
	}
	if has(c, "xact_commit") {
		m.counterN("falak.db.transactions", "{transaction}",
			point{c["xact_commit"], [][2]string{{"falak.db.transaction.outcome", "commit"}}},
			point{c["xact_rollback"], [][2]string{{"falak.db.transaction.outcome", "rollback"}}})
	}
	var rows []point
	for _, op := range []string{"returned", "fetched", "read", "inserted", "updated", "deleted"} {
		if has(c, "rows_"+op) {
			rows = append(rows, point{c["rows_"+op], [][2]string{{"falak.db.row.operation", op}}})
		}
	}
	m.counterN("falak.db.rows", "{row}", rows...)
	if has(c, "cache_hits") {
		m.counterN("falak.db.cache.requests", "{request}",
			point{c["cache_hits"], [][2]string{{"falak.db.cache.result", "hit"}}},
			point{c["cache_misses"], [][2]string{{"falak.db.cache.result", "miss"}}})
	}
	if has(g, "locks_waiting") {
		m.gauge("falak.db.locks.waiting", "{lock}", g["locks_waiting"])
	}
	if has(c, "lock_waits") {
		m.counter("falak.db.lock.waits", "{wait}", c["lock_waits"])
		m.counter("falak.db.lock.wait.time", "s", c["lock_wait_ms"]/1000)
	}
	if has(c, "deadlocks") {
		m.counter("falak.db.deadlocks", "{deadlock}", c["deadlocks"])
	}
	if has(c, "slow_queries") {
		m.counter("falak.db.slow_queries", "{query}", c["slow_queries"])
	}
	if has(c, "temp_bytes") {
		m.counter("falak.db.temp.io", "By", c["temp_bytes"])
	}
	if has(c, "bytes_received") {
		m.counterN("falak.db.network.io", "By",
			point{c["bytes_received"], [][2]string{{"network.io.direction", "receive"}}},
			point{c["bytes_sent"], [][2]string{{"network.io.direction", "transmit"}}})
	}
	if has(g, "replication_lag_s") {
		m.gauge("falak.db.replication.lag", "s", g["replication_lag_s"])
	}
	if isKeyValue(engine) {
		m.gauge("falak.db.memory.usage", "By", g["used_memory"])
		if g["maxmemory"] > 0 {
			m.gaugeN("falak.db.memory.limit", "By", point{g["maxmemory"], [][2]string{{"falak.db.maxmemory_policy", ob.Labels["maxmemory_policy"]}}})
		}
		m.counter("falak.db.keys.evicted", "{key}", c["evicted_keys"])
		m.counter("falak.db.keys.expired", "{key}", c["expired_keys"])
		var keys []point
		for _, d := range ob.Databases {
			keys = append(keys, point{float64(d.Keys), [][2]string{{"falak.db.name", d.Name}}})
		}
		m.gaugeN("falak.db.keys", "{key}", keys...)
		return
	}
	var sizes []point
	for i, d := range st.databases {
		if i >= 20 {
			break
		}
		sizes = append(sizes, point{float64(d.SizeBytes), [][2]string{{"falak.db.name", d.Name}}})
	}
	m.gaugeN("falak.db.size", "By", sizes...)
	tables := append([]dbhelper.TableStat(nil), st.tables...)
	sort.SliceStable(tables, func(i, j int) bool { return tables[i].SizeBytes > tables[j].SizeBytes })
	var tpts []point
	for i, t := range tables {
		if i >= 10 {
			break
		}
		name := t.Name
		if t.Schema != "" && t.Schema != "public" {
			name = t.Schema + "." + t.Name
		}
		tpts = append(tpts, point{float64(t.SizeBytes), [][2]string{{"falak.db.name", t.Database}, {"falak.db.table", name}}})
	}
	m.gaugeN("falak.db.table.size", "By", tpts...)
}
