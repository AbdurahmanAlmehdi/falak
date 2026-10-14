package db

import (
	"bytes"
	"context"
	"encoding/json"
	"net/http"
	"os"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/dbhelper"
	"github.com/OthmanHaba/falak/agent/internal/obs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
	"github.com/OthmanHaba/falak/agent/internal/transport"
	"github.com/santhosh-tekuri/jsonschema/v6"
	metricspb "go.opentelemetry.io/proto/otlp/metrics/v1"
)

type recordedCP struct {
	mu     sync.Mutex
	bodies [][]byte
	err    error
}

func (r *recordedCP) Request(_ context.Context, typ string, in, _ any) error {
	r.mu.Lock()
	defer r.mu.Unlock()
	if typ != "db.observed" {
		return &transport.StatusError{Code: http.StatusNotFound}
	}
	b, _ := json.Marshal(in)
	r.bodies = append(r.bodies, b)
	return r.err
}

func (r *recordedCP) last(t *testing.T) []ObservedInstance {
	t.Helper()
	r.mu.Lock()
	defer r.mu.Unlock()
	if len(r.bodies) == 0 {
		t.Fatal("no db.observed sent")
	}
	var body struct {
		Instances []ObservedInstance `json:"instances"`
	}
	if err := json.Unmarshal(r.bodies[len(r.bodies)-1], &body); err != nil {
		t.Fatal(err)
	}
	return body.Instances
}

type logSink struct {
	mu   sync.Mutex
	recs []obs.LogRecord
}

func (s *logSink) EmitLog(r obs.LogRecord) { s.mu.Lock(); s.recs = append(s.recs, r); s.mu.Unlock() }
func (s *logSink) EmitSpan(obs.Span)       {}

type metricSink struct {
	mu  sync.Mutex
	rms []*metricspb.ResourceMetrics
}

func (s *metricSink) EmitMetrics(rm *metricspb.ResourceMetrics) {
	s.mu.Lock()
	s.rms = append(s.rms, rm)
	s.mu.Unlock()
}

// value finds a metric's data point with the given attribute (or the first one) in the last ResourceMetrics.
func (s *metricSink) value(t *testing.T, name, attr, val string) (float64, bool) {
	t.Helper()
	s.mu.Lock()
	defer s.mu.Unlock()
	if len(s.rms) == 0 {
		return 0, false
	}
	for _, m := range s.rms[len(s.rms)-1].ScopeMetrics[0].Metrics {
		if m.Name != name {
			continue
		}
		var pts []*metricspb.NumberDataPoint
		if g := m.GetGauge(); g != nil {
			pts = g.DataPoints
		} else {
			pts = m.GetSum().DataPoints
		}
		for _, p := range pts {
			if attr == "" {
				return p.GetAsDouble(), true
			}
			for _, a := range p.Attributes {
				if a.Key == attr && a.Value.GetStringValue() == val {
					return p.GetAsDouble(), true
				}
			}
		}
	}
	return 0, false
}

type observeHarness struct {
	*harness
	cp      *recordedCP
	logs    *logSink
	metrics *metricSink
	o       *Observer
	now     time.Time
	ob      dbhelper.Observation
	logOut  string
	exec    *runner.Result // replaces the observation when set
	mu      sync.Mutex
}

func newObserveHarness(t *testing.T) *observeHarness {
	t.Helper()
	h := &observeHarness{harness: newHarness(t), cp: &recordedCP{}, logs: &logSink{}, metrics: &metricSink{}, now: time.Date(2026, 10, 14, 12, 0, 0, 0, time.UTC)}
	if _, err := h.db.InstanceCreate(context.Background(), createPayload(), stream()); err != nil {
		t.Fatal(err)
	}
	h.o = h.db.NewObserver(h.cp, h.logs, h.metrics)
	h.o.now = func() time.Time { return h.now }
	h.ob = dbhelper.Observation{Engine: "postgres", Version: "17.2", Features: dbhelper.Features,
		Counters: map[string]float64{"xact_commit": 100, "xact_rollback": 1, "cache_hits": 990, "cache_misses": 10, "deadlocks": 1, "temp_bytes": 0},
		Gauges:   map[string]float64{"connections": 4, "connections_active": 1, "connections_idle": 3, "max_connections": 100, "locks_waiting": 0},
		Labels:   map[string]string{"role": "primary"}, Settings: map[string]float64{"slow_query_ms": 1000},
		Memory: &dbhelper.CgroupMemory{UsedBytes: 100, LimitBytes: 1000}, QueryInsights: dbhelper.QueryInsights{Status: "on"},
		Statements: []dbhelper.Statement{{ID: "pg:42", Database: "shop", Query: "SELECT * FROM orders WHERE id = $1", Calls: 10, TotalMS: 100, MaxMS: 30, Rows: 10, Source: "pg_stat_statements"}},
		Databases:  []dbhelper.DatabaseSize{{Name: "shop", SizeBytes: 5 << 20}},
		Tables:     []dbhelper.TableStat{{Database: "shop", Schema: "public", Name: "orders", SizeBytes: 4 << 20, Rows: 1000}}}
	h.run.OnFunc("docker exec falak-db-"+instID+" falak-db observe", func(c runnertest.Call) (runner.Result, error) {
		h.mu.Lock()
		defer h.mu.Unlock()
		if h.exec != nil {
			return *h.exec, nil
		}
		ob := h.ob
		if !strings.Contains(c.Line, "--detail") {
			ob.Statements, ob.Databases, ob.Tables = nil, nil, nil
		}
		b, _ := json.Marshal(ob)
		return runner.Result{Stdout: append(b, '\n')}, nil
	})
	h.run.OnFunc("docker logs", func(runnertest.Call) (runner.Result, error) {
		h.mu.Lock()
		defer h.mu.Unlock()
		return runner.Result{Stderr: []byte(h.logOut)}, nil
	})
	return h
}

func (h *observeHarness) pass(d time.Duration) {
	h.now = h.now.Add(d)
	h.o.Pass(context.Background())
}

func (h *observeHarness) set(fn func(ob *dbhelper.Observation)) {
	h.mu.Lock()
	defer h.mu.Unlock()
	fn(&h.ob)
}

func observedSchema(t *testing.T) *jsonschema.Schema {
	t.Helper()
	c := jsonschema.NewCompiler()
	c.DefaultDraft(jsonschema.Draft2020)
	c.AssertFormat()
	f, err := os.Open("../../../contracts/agent-protocol/requests/db.observed.schema.json")
	if err != nil {
		t.Fatal(err)
	}
	defer f.Close()
	doc, err := jsonschema.UnmarshalJSON(f)
	if err != nil {
		t.Fatal(err)
	}
	const id = "https://falak.sh/agent-protocol/requests/db.observed.schema.json"
	if err := c.AddResource(id, doc); err != nil {
		t.Fatal(err)
	}
	s, err := c.Compile(id)
	if err != nil {
		t.Fatal(err)
	}
	return s
}

func (h *observeHarness) validateAll(t *testing.T) {
	t.Helper()
	s := observedSchema(t)
	h.cp.mu.Lock()
	defer h.cp.mu.Unlock()
	for _, b := range h.cp.bodies {
		v, _ := jsonschema.UnmarshalJSON(bytes.NewReader(b))
		if err := s.Validate(v); err != nil {
			t.Errorf("db.observed invalid: %v\n%s", err, b)
		}
	}
}

func TestObserverReportsMetricsAndDeltas(t *testing.T) {
	h := newObserveHarness(t)
	ObserveDetailEvery = 1
	t.Cleanup(func() { ObserveDetailEvery = 5 })

	// First pass (a detail one): a baseline, no deltas, no statements yet.
	h.pass(0)
	first := h.cp.last(t)
	if len(first) != 1 || first[0].ID != instID || first[0].Engine != "postgres" || first[0].WindowS != 0 || len(first[0].Deltas) != 0 || first[0].Statements == nil || len(*first[0].Statements) != 0 {
		t.Fatalf("first report %+v", first)
	}
	if first[0].QueryInsights.Status != "on" || len(*first[0].Databases) != 1 || first[0].Memory.LimitBytes != 1000 || first[0].Indexes == nil {
		t.Errorf("first report details %+v", first[0])
	}
	if v, ok := h.metrics.value(t, "falak.db.up", "", ""); !ok || v != 1 {
		t.Error("falak.db.up")
	}
	if v, _ := h.metrics.value(t, "falak.db.connections", "falak.db.connection.state", "idle"); v != 3 {
		t.Errorf("idle connections %v", v)
	}
	if v, _ := h.metrics.value(t, "falak.db.cache.requests", "falak.db.cache.result", "miss"); v != 10 {
		t.Errorf("cache misses %v", v)
	}
	if v, _ := h.metrics.value(t, "falak.db.table.size", "falak.db.table", "orders"); v != 4<<20 {
		t.Errorf("table size %v", v)
	}
	rm := h.metrics.rms[len(h.metrics.rms)-1]
	attrs := map[string]string{}
	for _, a := range rm.Resource.Attributes {
		attrs[a.Key] = a.Value.GetStringValue()
	}
	if attrs["service.name"] != "falak-db" || attrs["falak.db.instance.id"] != instID || attrs["db.system.name"] != "postgresql" || rm.ScopeMetrics[0].Scope.Name != "falak-agent/databases" {
		t.Errorf("resource %v", attrs)
	}

	// Second pass: deltas over the window, a deadlock, a slow statement and an auth failure from the log.
	h.set(func(ob *dbhelper.Observation) {
		ob.Counters = map[string]float64{"xact_commit": 160, "xact_rollback": 3, "cache_hits": 1990, "cache_misses": 20, "deadlocks": 2, "temp_bytes": 0}
		ob.Statements[0].Calls, ob.Statements[0].TotalMS = 15, 5100
	})
	h.logOut = "2026-10-14T12:00:30.000000000Z 2026-10-14 12:00:30.000 UTC [77] app@shop LOG:  duration: 1500.250 ms  statement: SELECT * FROM users WHERE email = 'alice@example.com'\n" +
		"2026-10-14T12:00:31.000000000Z 2026-10-14 12:00:31.000 UTC [78] app@shop FATAL:  password authentication failed for user \"app\"\n" +
		"2026-10-14T12:00:32.000000000Z 2026-10-14 12:00:32.000 UTC [79] app@shop FATAL:  password authentication failed for user \"app\"\n"
	h.pass(time.Minute)
	second := h.cp.last(t)[0]
	if second.WindowS != 60 || second.Deltas["transactions"] != 62 || second.Deltas["rollbacks"] != 2 || second.Deltas["cache_hits"] != 1000 || second.Deltas["deadlocks"] != 1 || second.Deltas["slow_queries"] != 1 {
		t.Errorf("deltas %+v window %d", second.Deltas, second.WindowS)
	}
	kinds := map[string]int{}
	for _, e := range second.Errors {
		kinds[e.Kind] = e.Count
		if strings.Contains(e.Message, `"app"`) {
			t.Errorf("error message kept a quoted value: %q", e.Message)
		}
	}
	if kinds["deadlock"] != 1 || kinds["auth_failed"] != 2 {
		t.Errorf("errors %+v", second.Errors)
	}
	// The detail pass: the statement's delta against the first one.
	if second.Statements == nil || len(*second.Statements) != 1 || (*second.Statements)[0].Fingerprint != "pg:42" || (*second.Statements)[0].Calls != 5 || (*second.Statements)[0].TotalMS != 5000 {
		t.Errorf("statements %+v", second.Statements)
	}
	// The slow statement became a db.slow record on the container's stream, normalized.
	h.logs.mu.Lock()
	if len(h.logs.recs) != 1 {
		t.Fatalf("slow records %+v", h.logs.recs)
	}
	r := h.logs.recs[0]
	h.logs.mu.Unlock()
	if r.Kind != "db.slow" || r.Service != "falak-db-"+instID || r.Attrs["falak.db.instance.id"] != instID || r.Attrs["db.namespace"] != "shop" ||
		r.Attrs["db.query.text"] != "SELECT * FROM users WHERE email = ?" || strings.Contains(r.Body, "alice") || r.Attrs["falak.db.duration_ms"] != "1500.25" {
		t.Errorf("slow record %+v", r)
	}
	if v, _ := h.metrics.value(t, "falak.db.slow_queries", "", ""); v != 1 {
		t.Errorf("slow queries counter %v", v)
	}
	h.validateAll(t)

	// The heartbeat's connection figures come from the observer: no `stats` exec.
	if c := h.db.Report(context.Background())[0].Connections; c == nil || c.Used != 4 || c.Max != 100 {
		t.Errorf("report connections %+v", c)
	}
	for _, c := range h.run.Calls() {
		if strings.HasSuffix(c.Line, "falak-db stats") {
			t.Error("stats ran although the observer has the figures")
		}
	}
}

func TestObserverWithoutQueryInsightsGroupsSlowEntries(t *testing.T) {
	h := newObserveHarness(t)
	ObserveDetailEvery = 1
	t.Cleanup(func() { ObserveDetailEvery = 5 })
	h.set(func(ob *dbhelper.Observation) {
		ob.Engine = "mysql"
		ob.QueryInsights = dbhelper.QueryInsights{Status: "off", Reason: "performance_schema is off"}
		ob.Statements = nil
	})
	h.pass(0)
	h.set(func(ob *dbhelper.Observation) {
		ob.Slow = []dbhelper.SlowEntry{
			{At: h.now, DurationMS: 1200, Database: "shop", Query: "SELECT * FROM carts WHERE token = ?"},
			{At: h.now, DurationMS: 1800, Database: "shop", Query: "SELECT * FROM carts WHERE token = ?"},
		}
	})
	h.pass(time.Minute)
	got := h.cp.last(t)[0]
	if got.Statements == nil || len(*got.Statements) != 1 {
		t.Fatalf("statements %+v", got.Statements)
	}
	s := (*got.Statements)[0]
	if s.Source != "slowlog" || s.Calls != 2 || s.TotalMS != 3000 || s.MaxMS != 1800 || !strings.HasPrefix(s.Fingerprint, "sql:") || s.Database != "shop" {
		t.Errorf("slow shape %+v", s)
	}
	h.validateAll(t)
}

func TestObserverSkipsOlderImages(t *testing.T) {
	h := newObserveHarness(t)
	h.exec = &runner.Result{ExitCode: 2, Stderr: []byte(`falak-db: unknown command "observe"` + "\n")}
	for i := 0; i < 3; i++ {
		h.pass(time.Minute)
	}
	n := 0
	for _, c := range h.run.Calls() {
		if strings.Contains(c.Line, "falak-db observe") {
			n++
		}
	}
	if n != 1 {
		t.Errorf("observe ran %d times on an image without it", n)
	}
	if len(h.cp.bodies) != 0 || len(h.metrics.rms) != 0 {
		t.Errorf("an older image reported %d bodies, %d metrics", len(h.cp.bodies), len(h.metrics.rms))
	}
}

func TestObserverFailedExecReportsDown(t *testing.T) {
	h := newObserveHarness(t)
	h.exec = &runner.Result{ExitCode: 1, Stderr: []byte("falak-db: psql: connection refused\n")}
	h.pass(time.Minute)
	if v, ok := h.metrics.value(t, "falak.db.up", "", ""); !ok || v != 0 {
		t.Errorf("falak.db.up %v %v", v, ok)
	}
	if len(h.cp.bodies) != 0 {
		t.Error("a failed observation was reported")
	}
}

func TestObserverOldControlPlane(t *testing.T) {
	h := newObserveHarness(t)
	h.cp.err = &transport.StatusError{Code: http.StatusNotFound, Reason: "unknown_request"}
	h.pass(0)
	h.pass(time.Minute)
	if !h.o.cpMissing {
		t.Error("404 not remembered")
	}
	if v, ok := h.metrics.value(t, "falak.db.up", "", ""); !ok || v != 1 {
		t.Error("metrics stopped with the report")
	}
}

func TestScanEngineLog(t *testing.T) {
	at := time.Date(2026, 10, 14, 12, 0, 0, 0, time.UTC)
	lines := timestamped([]byte(
		"2026-10-14T12:00:00Z 2026-10-14 12:00:00.000 UTC [1] app@shop LOG:  duration: 2001.5 ms  execute <unnamed>: SELECT * FROM t WHERE a = $1\n" +
			"2026-10-14T12:00:00Z 2026-10-14 12:00:00.000 UTC [1] app@shop DETAIL:  parameters: $1 = 'secret'\n" +
			"2026-10-14T12:00:01Z 2026-10-14 12:00:01.000 UTC [2] app@shop LOG:  duration: 1000.0 ms  statement: SELECT *\n" +
			"2026-10-14T12:00:01Z \tFROM accounts WHERE pin = '1234'\n" +
			"2026-10-14T12:00:02Z 2026-10-14 12:00:02.000 UTC [3] @ FATAL:  sorry, too many clients already\n" +
			"2026-10-14T12:00:03Z 2026-10-14 12:00:03.000 UTC [1] LOG:  server process (PID 9) was terminated by signal 9: Killed\n" +
			"2026-10-14T12:00:04Z 2026-10-14 12:00:04.000 UTC [1] LOG:  server process (PID 10) was terminated by signal 11: Segmentation fault\n" +
			"2026-10-14T12:00:05Z 2026-10-14 12:00:05.000 UTC [1] ERROR:  could not extend file: No space left on device\n" +
			"not a docker line\n"))
	res := scanEngineLog("postgres", lines)
	if len(res.slow) != 2 || res.slow[0].DurationMS != 2001.5 || res.slow[0].Database != "shop" || res.slow[0].Query != "SELECT * FROM t WHERE a = $1" || !res.slow[0].At.Equal(at) {
		t.Fatalf("slow %+v", res.slow)
	}
	if res.slow[1].Query != "SELECT * FROM accounts WHERE pin = ?" {
		t.Errorf("multi-line statement %q", res.slow[1].Query)
	}
	want := map[string]int{"too_many_connections": 1, "oom": 1, "crash": 1, "disk_full": 1}
	for k, n := range want {
		if e := res.errors[k]; e == nil || e.Count != n {
			t.Errorf("%s: %+v", k, e)
		}
	}
	if len(res.errors) != len(want) {
		t.Errorf("errors %v", res.errors)
	}

	my := scanEngineLog("mysql", timestamped([]byte("2026-10-14T12:00:00Z 2026-10-14T12:00:00.000Z 0 [ERROR] [MY-000000] [Server] mysqld got signal 11 ;\n")))
	if my.errors["crash"] == nil || len(my.slow) != 0 {
		t.Errorf("mysql %+v", my.errors)
	}
	kv := scanEngineLog("valkey", timestamped([]byte("2026-10-14T12:00:00Z 1:M 14 Oct 2026 12:00:00.000 # Can't save in background: fork: Cannot allocate memory\n")))
	if kv.errors["oom"] == nil {
		t.Errorf("valkey %+v", kv.errors)
	}
}

func TestDeltasSurviveARestart(t *testing.T) {
	d := deltas(map[string]float64{"deadlocks": 10, "xact_commit": 500}, map[string]float64{"deadlocks": 2, "xact_commit": 20, "cache_hits": 5})
	if d["deadlocks"] != 2 || d["transactions"] != 20 {
		t.Errorf("%+v", d)
	}
	if _, ok := d["cache_hits"]; ok {
		t.Error("a counter without a previous value has a delta")
	}
}

func TestQueryInsightsRestartsAndSlowQueryReloads(t *testing.T) {
	h := newHarness(t)
	p := createPayload()
	if _, err := h.db.InstanceCreate(context.Background(), p, stream()); err != nil {
		t.Fatal(err)
	}
	qi := h.path("/etc/falak/db/" + instID + "/conf/query-insights.json")
	if _, err := os.Stat(qi); err == nil {
		t.Fatal("query insights file without the flag")
	}
	hash := h.dock.containers["falak-db-"+instID].Config.Labels["falak.spec.hash"]

	// Any update carrying query_insights (network, certificate, PITR converging) writes the file but never restarts a
	// running production database: it applies at the next restart the user causes.
	h.dock.calls = nil
	p.Instance.QueryInsights = true
	res, err := h.db.InstanceUpdate(context.Background(), p, stream())
	if err != nil || res.(InstanceResult).Restarted || h.dock.called("restart") || h.dock.called("stop") || h.dock.called("create") {
		t.Fatalf("query insights without the user's action: %+v %v %v", res, err, h.dock.calls)
	}
	if b, _ := os.ReadFile(qi); strings.TrimSpace(string(b)) != `{"enabled":true}` {
		t.Errorf("file %q", b)
	}
	if got := h.dock.containers["falak-db-"+instID].Config.Labels["falak.spec.hash"]; hash != "" && got != hash {
		t.Error("query insights changed the spec hash (it would recreate the container)")
	}
	// The user's enable action (apply_query_insights) restarts it, the file having been deferred.
	time.Sleep(5 * time.Millisecond)
	os.Chtimes(qi, time.Now(), time.Now())
	h.dock.calls = nil
	p.ApplyQueryInsights = true
	res, err = h.db.InstanceUpdate(context.Background(), p, stream())
	if err != nil || !res.(InstanceResult).Restarted || h.dock.called("create") {
		t.Fatalf("enable query insights: %+v %v %v", res, err, h.dock.calls)
	}
	// Repeated (a retry): the engine already runs with it, nothing happens.
	h.dock.calls = nil
	if res, _ := h.db.InstanceUpdate(context.Background(), p, stream()); res.(InstanceResult).Changed || h.dock.called("restart") {
		t.Errorf("idempotent: %+v %v", res, h.dock.calls)
	}
	p.ApplyQueryInsights = false

	// Only the slow query threshold: falak-db reload, no restart.
	h.dock.calls = nil
	p.Instance.Settings = []byte(`{"max_connections": 200, "slow_query_ms": 250}`)
	res, err = h.db.InstanceUpdate(context.Background(), p, stream())
	if err != nil || res.(InstanceResult).Restarted || !res.(InstanceResult).Changed || h.dock.called("restart") {
		t.Fatalf("slow query change: %+v %v %v", res, err, h.dock.calls)
	}
	reloaded := false
	for _, c := range h.run.Calls() {
		if c.Line == "docker exec falak-db-"+instID+" falak-db reload" {
			reloaded = true
		}
	}
	if !reloaded {
		t.Error("falak-db reload not run")
	}

	// An image without `reload` is restarted instead.
	h.run.On("docker exec falak-db-"+instID+" falak-db reload", runner.Result{ExitCode: 2, Stderr: []byte(`falak-db: unknown command "reload"`)})
	h.dock.calls = nil
	p.Instance.Settings = []byte(`{"max_connections": 200, "slow_query_ms": 500}`)
	if res, err := h.db.InstanceUpdate(context.Background(), p, stream()); err != nil || !res.(InstanceResult).Restarted {
		t.Fatalf("older image: %+v %v", res, err)
	}

	// Anything else restarts, as before.
	h.dock.calls = nil
	p.Instance.Settings = []byte(`{"max_connections": 50, "slow_query_ms": 500}`)
	if res, err := h.db.InstanceUpdate(context.Background(), p, stream()); err != nil || !res.(InstanceResult).Restarted {
		t.Fatalf("other setting: %+v %v", res, err)
	}

	// Off again without the user's action: the file goes, nothing restarts for it.
	h.dock.calls = nil
	p.Instance.QueryInsights = false
	if res, err := h.db.InstanceUpdate(context.Background(), p, stream()); err != nil || res.(InstanceResult).Restarted {
		t.Fatalf("query insights off: %+v %v", res, err)
	}
	if _, err := os.Stat(qi); err == nil {
		t.Error("file kept")
	}
}

// Agent upgrades never recreate or restart existing instances: the spec hash ignores query_insights and the
// rendered config (falak-db renders it inside the container at start, never on an agent's say).
func TestSpecHashUnchangedByObservability(t *testing.T) {
	s := spec()
	b, _ := json.Marshal(s)
	if strings.Contains(string(b), "query_insights") {
		t.Errorf("query_insights in a spec that does not set it: %s", b)
	}
	h := s.hash()
	s.QueryInsights = true
	if s.hash() != h {
		t.Error("query_insights is part of the spec hash")
	}
}

// A dropped database (or a statistics reset) lowers PostgreSQL's summed counters without a restart: no delta, no
// counter going down; a lower uptime is a restart and the counters start again.
func TestObserverCountersStayMonotonic(t *testing.T) {
	h := newObserveHarness(t)
	h.set(func(ob *dbhelper.Observation) {
		ob.Counters = map[string]float64{"deadlocks": 10, "xact_commit": 1000}
		ob.Settings = map[string]float64{"uptime_s": 1000}
	})
	h.pass(0)
	h.set(func(ob *dbhelper.Observation) {
		ob.Counters = map[string]float64{"deadlocks": 3, "xact_commit": 400} // a database with 7 deadlocks dropped
		ob.Settings = map[string]float64{"uptime_s": 1060}
	})
	h.pass(time.Minute)
	if d := h.cp.last(t)[0].Deltas; d["deadlocks"] != 0 || d["transactions"] != 0 {
		t.Errorf("a dropped database counted as activity: %+v", d)
	}
	if v, _ := h.metrics.value(t, "falak.db.deadlocks", "", ""); v != 10 {
		t.Errorf("deadlocks counter went down to %v", v)
	}
	h.set(func(ob *dbhelper.Observation) {
		ob.Counters = map[string]float64{"deadlocks": 5, "xact_commit": 450}
		ob.Settings = map[string]float64{"uptime_s": 1120}
	})
	h.pass(time.Minute)
	if d := h.cp.last(t)[0].Deltas; d["deadlocks"] != 2 || d["transactions"] != 50 {
		t.Errorf("after the drop: %+v", d)
	}
	h.set(func(ob *dbhelper.Observation) {
		ob.Counters = map[string]float64{"deadlocks": 1, "xact_commit": 20}
		ob.Settings = map[string]float64{"uptime_s": 30} // restarted
	})
	h.pass(time.Minute)
	if d := h.cp.last(t)[0].Deltas; d["deadlocks"] != 1 || d["transactions"] != 20 {
		t.Errorf("after a restart: %+v", d)
	}
	if v, _ := h.metrics.value(t, "falak.db.deadlocks", "", ""); v != 1 {
		t.Errorf("after a restart the counter is the engine's: %v", v)
	}
}

func TestObserverUnhealthyIsDown(t *testing.T) {
	h := newObserveHarness(t)
	h.dock.containers["falak-db-"+instID].State.Health.Status = "unhealthy"
	h.pass(time.Minute)
	if v, ok := h.metrics.value(t, "falak.db.up", "", ""); !ok || v != 0 {
		t.Errorf("falak.db.up %v %v", v, ok)
	}
	for _, c := range h.run.Calls() {
		if strings.Contains(c.Line, "falak-db observe") {
			t.Error("observe ran in an unhealthy container")
		}
	}
	h.metrics.mu.Lock()
	unit := h.metrics.rms[0].ScopeMetrics[0].Metrics[0].Unit
	h.metrics.mu.Unlock()
	if unit != "" {
		t.Errorf("falak.db.up unit %q (\"1\" becomes falak_db_up_ratio)", unit)
	}
}
