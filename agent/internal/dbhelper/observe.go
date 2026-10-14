package dbhelper

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/sqlnorm"
	"github.com/OthmanHaba/falak/agent/internal/version"
)

// Features are what this helper answers beyond the v0.10.0 commands, reported by `observe` (the agent reads them).
var Features = []string{"observe", "reload", "query_insights"}

// Observation is `falak-db observe`: what the engine knows about itself (docs/plans/OBSERVABILITY.md phase 3). Counters
// are cumulative since the server started; the agent turns them into rates and deltas. Every section is best effort:
// a failing query adds a warning and the rest still comes back. Query text is normalized (sqlnorm), never raw.
type Observation struct {
	Engine        Engine             `json:"engine"`
	Version       string             `json:"version,omitempty"`
	Helper        string             `json:"helper,omitempty"`
	Features      []string           `json:"features"`
	At            time.Time          `json:"at"`
	Counters      map[string]float64 `json:"counters"`
	Gauges        map[string]float64 `json:"gauges"`
	Labels        map[string]string  `json:"labels,omitempty"`
	Settings      map[string]float64 `json:"settings,omitempty"`
	Memory        *CgroupMemory      `json:"memory,omitempty"`
	QueryInsights QueryInsights      `json:"query_insights"`
	Databases     []DatabaseSize     `json:"databases,omitempty"`
	Tables        []TableStat        `json:"tables,omitempty"`
	Indexes       []IndexStat        `json:"indexes,omitempty"`
	Statements    []Statement        `json:"statements,omitempty"`
	Slow          []SlowEntry        `json:"slow,omitempty"`
	Cursor        ObserveCursor      `json:"cursor"`
	Warnings      []string           `json:"warnings,omitempty"`
}

// CgroupMemory is the container's memory as its own cgroup sees it.
type CgroupMemory struct {
	UsedBytes  int64 `json:"used_bytes"`
	LimitBytes int64 `json:"limit_bytes"` // 0: no limit
	OOMKills   int64 `json:"oom_kills"`
}

// QueryInsights says whether top queries come from the engine (pg_stat_statements, statement digests, SLOWLOG).
type QueryInsights struct {
	Status string `json:"status"` // on | off | unavailable
	Reason string `json:"reason,omitempty"`
}

// DatabaseSize is one database (redis/valkey: a logical database and its keys).
type DatabaseSize struct {
	Name      string `json:"name"`
	SizeBytes int64  `json:"size_bytes,omitempty"`
	Keys      int64  `json:"keys,omitempty"`
}

// TableStat is one of the largest tables.
type TableStat struct {
	Database   string `json:"database"`
	Schema     string `json:"schema,omitempty"`
	Name       string `json:"name"`
	SizeBytes  int64  `json:"size_bytes"`
	Rows       int64  `json:"rows"`
	SeqScan    int64  `json:"seq_scan,omitempty"`
	SeqTupRead int64  `json:"seq_tup_read,omitempty"`
	IdxScan    int64  `json:"idx_scan,omitempty"`
}

// IndexStat is an index nothing has used since the statistics began (unique and primary keys left out).
type IndexStat struct {
	Database  string `json:"database"`
	Schema    string `json:"schema,omitempty"`
	Table     string `json:"table"`
	Name      string `json:"name"`
	SizeBytes int64  `json:"size_bytes"`
	Scans     int64  `json:"scans"`
}

// Statement is one statement shape with its cumulative figures.
type Statement struct {
	ID       string  `json:"id"` // pg:<queryid> | my:<digest>
	Database string  `json:"database,omitempty"`
	Query    string  `json:"query"`
	Calls    int64   `json:"calls"`
	TotalMS  float64 `json:"total_ms"`
	MaxMS    float64 `json:"max_ms"`
	Rows     int64   `json:"rows"`
	NoIndex  int64   `json:"no_index,omitempty"`
	Source   string  `json:"source"` // pg_stat_statements | digest
}

// SlowEntry is one slow query (MySQL/MariaDB slow log, Redis/Valkey SLOWLOG), normalized.
type SlowEntry struct {
	At         time.Time `json:"at"`
	DurationMS float64   `json:"duration_ms"`
	Database   string    `json:"database,omitempty"`
	Query      string    `json:"query"`
	Rows       int64     `json:"rows,omitempty"`
}

// ObserveCursor is where the next call resumes reading slow entries. Zero values: start at the end (nothing older is
// replayed after an agent restart).
type ObserveCursor struct {
	SlowLogInode  uint64 `json:"slow_log_inode,omitempty"`
	SlowLogOffset int64  `json:"slow_log_offset,omitempty"`
	SlowLogID     int64  `json:"slowlog_id,omitempty"` // the id the next SLOWLOG entry gets
	Started       bool   `json:"started,omitempty"`    // the first call has run
}

// Limits of what one call returns.
const (
	maxStatements = 100
	maxSlow       = 200
	maxDatabases  = 20
	maxTables     = 20
	maxIndexes    = 20
	maxPGDetailDB = 10
	slowReadMax   = 4 << 20
	slowTruncate  = 64 << 20
)

// Observe is `falak-db observe [--detail] [--cursor JSON]`.
func (h *Helper) Observe(ctx context.Context, detail bool, cursorJSON string) error {
	var cur ObserveCursor
	if strings.TrimSpace(cursorJSON) != "" {
		if err := json.Unmarshal([]byte(cursorJSON), &cur); err != nil {
			return usageErr("--cursor: %v", err)
		}
	}
	o := &Observation{
		Engine: h.Engine, Features: Features, At: h.now(), Counters: map[string]float64{}, Gauges: map[string]float64{},
		Labels: map[string]string{}, Settings: map[string]float64{}, Cursor: cur,
	}
	o.Helper = helperVersion()
	o.Memory = h.cgroupMemory()
	var err error
	switch {
	case h.Engine == Postgres:
		err = h.observePostgres(ctx, o, detail)
	case h.Engine.mysqlFamily():
		err = h.observeMySQL(ctx, o, detail)
	case h.Engine.kv():
		err = h.observeKV(ctx, o, detail)
	default:
		return unsupported(h.Engine, "observe")
	}
	if err != nil {
		return err
	}
	o.Cursor.Started = true
	return h.result(o)
}

func (h *Helper) now() time.Time {
	if h.Now != nil {
		return h.Now().UTC()
	}
	return time.Now().UTC()
}

func (o *Observation) warn(format string, a ...any) {
	if len(o.Warnings) < 20 {
		o.Warnings = append(o.Warnings, firstLine(fmt.Sprintf(format, a...)))
	}
}

// cgroupMemory reads the container's own cgroup (v2): usage (without inactive page cache), limit and OOM kills.
func (h *Helper) cgroupMemory() *CgroupMemory {
	b, err := os.ReadFile(h.path("/sys/fs/cgroup/memory.current"))
	if err != nil {
		return nil
	}
	m := &CgroupMemory{}
	m.UsedBytes, _ = strconv.ParseInt(strings.TrimSpace(string(b)), 10, 64)
	// The working set, as the kernel's reclaim sees it: inactive page cache is given back under pressure, so a
	// database reading its files would otherwise always look "near the limit".
	if b, err := os.ReadFile(h.path("/sys/fs/cgroup/memory.stat")); err == nil {
		for _, l := range lines(string(b)) {
			if k, v, ok := strings.Cut(l, " "); ok && k == "inactive_file" {
				n, _ := strconv.ParseInt(strings.TrimSpace(v), 10, 64)
				m.UsedBytes = max(m.UsedBytes-n, 0)
			}
		}
	}
	if b, err := os.ReadFile(h.path("/sys/fs/cgroup/memory.max")); err == nil {
		m.LimitBytes, _ = strconv.ParseInt(strings.TrimSpace(string(b)), 10, 64) // "max": 0
	}
	if b, err := os.ReadFile(h.path("/sys/fs/cgroup/memory.events")); err == nil {
		for _, l := range lines(string(b)) {
			if k, v, ok := strings.Cut(l, " "); ok && k == "oom_kill" {
				m.OOMKills, _ = strconv.ParseInt(strings.TrimSpace(v), 10, 64)
			}
		}
	}
	return m
}

// ---- PostgreSQL ----

// observeMarker starts every statement observe runs, so pg_stat_statements' own entries can be told apart.
const observeMarker = "/* falak-db observe */ "

// pgObserve runs an observe query with limits: a lock or a slow catalog never holds falak-db (or the engine) up.
func (h *Helper) pgObserve(ctx context.Context, dbname, sql string) (string, error) {
	out, err := output(ctx, h.Run, Cmd{Name: "psql", Args: []string{
		"-h", pgSocketDir, "-p", strconv.Itoa(pgPort), "-U", h.pgUser(), "-d", dbname,
		"-AtqX", "-v", "ON_ERROR_STOP=1", "-f", "-",
	}, Stdin: strings.NewReader(sql), Env: pgObserveEnv})
	return strings.TrimSpace(out), err
}

// pgObserveEnv: falak-db's own read-only-proof session, with a statement and lock timeout, and the connection checked
// so a query whose client went away (the agent's exec timed out) stops too.
var pgObserveEnv = []string{"PGOPTIONS=-c default_transaction_read_only=off -c statement_timeout=15s -c lock_timeout=1s " +
	"-c client_connection_check_interval=5s -c application_name=falak-db"}

// pgObserveSQL is one round trip for the cluster-wide figures, as one JSON object.
const pgObserveSQL = observeMarker + `SELECT jsonb_build_object(
 'version', current_setting('server_version'),
 'counters', (SELECT jsonb_build_object('xact_commit', sum(xact_commit), 'xact_rollback', sum(xact_rollback),
   'cache_hits', sum(blks_hit), 'cache_misses', sum(blks_read), 'rows_returned', sum(tup_returned),
   'rows_fetched', sum(tup_fetched), 'rows_inserted', sum(tup_inserted), 'rows_updated', sum(tup_updated),
   'rows_deleted', sum(tup_deleted), 'deadlocks', sum(deadlocks), 'temp_bytes', sum(temp_bytes)) FROM pg_stat_database),
 'states', (SELECT coalesce(json_object_agg(s, n), '{}') FROM (SELECT coalesce(state, 'other') AS s, count(*) AS n
   FROM pg_stat_activity WHERE backend_type = 'client backend' GROUP BY 1) a),
 'max_connections', current_setting('max_connections')::bigint,
 'locks_waiting', (SELECT count(*) FROM pg_locks WHERE NOT granted),
 'in_recovery', pg_is_in_recovery(),
 'lag_s', CASE WHEN pg_is_in_recovery() AND EXISTS (SELECT 1 FROM pg_stat_wal_receiver)
   THEN CASE WHEN pg_last_wal_receive_lsn() = pg_last_wal_replay_lsn() THEN 0
     ELSE coalesce(extract(epoch FROM now() - pg_last_xact_replay_timestamp()), 0) END END,
 'shared_buffers_bytes', (SELECT setting::bigint * current_setting('block_size')::bigint FROM pg_settings WHERE name = 'shared_buffers'),
 'slow_ms', (SELECT setting::bigint FROM pg_settings WHERE name = 'log_min_duration_statement'),
 'preload', current_setting('shared_preload_libraries'),
 'uptime_s', extract(epoch FROM now() - pg_postmaster_start_time()),
 'stats_age_s', extract(epoch FROM now() - coalesce((SELECT max(stats_reset) FROM pg_stat_database), pg_postmaster_start_time())),
 'pss', EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'pg_stat_statements'),
 'read_only', current_setting('default_transaction_read_only') = 'on'
);`

type pgObservation struct {
	Version            string             `json:"version"`
	Counters           map[string]float64 `json:"counters"`
	States             map[string]float64 `json:"states"`
	MaxConnections     float64            `json:"max_connections"`
	LocksWaiting       float64            `json:"locks_waiting"`
	InRecovery         bool               `json:"in_recovery"`
	LagS               *float64           `json:"lag_s"`
	SharedBuffersBytes float64            `json:"shared_buffers_bytes"`
	SlowMS             float64            `json:"slow_ms"`
	Preload            string             `json:"preload"`
	UptimeS            float64            `json:"uptime_s"`
	StatsAgeS          float64            `json:"stats_age_s"`
	PSS                bool               `json:"pss"`
	ReadOnly           bool               `json:"read_only"`
}

// pgState maps pg_stat_activity.state to the connection states Falak reports.
func pgState(s string) string {
	switch {
	case s == "active" || s == "idle":
		return s
	case strings.HasPrefix(s, "idle in transaction"):
		return "idle_in_transaction"
	}
	return "other"
}

func (h *Helper) observePostgres(ctx context.Context, o *Observation, detail bool) error {
	out, err := h.pgObserve(ctx, "postgres", pgObserveSQL)
	if err != nil {
		return err
	}
	var p pgObservation
	if err := json.Unmarshal([]byte(lastLine(out)), &p); err != nil {
		return fmt.Errorf("observe: unexpected output %q", firstLine(out))
	}
	applyPostgres(o, p)
	switch {
	case !strings.Contains(p.Preload, "pg_stat_statements"):
		o.QueryInsights = QueryInsights{Status: "off", Reason: "pg_stat_statements is not loaded (enabling it restarts the database)"}
	case p.PSS:
		o.QueryInsights = QueryInsights{Status: "on"}
	case p.ReadOnly || p.InRecovery:
		// A copy being inspected (read-only) or a replica: falak-db writes nothing there, not even the extension.
		o.QueryInsights = QueryInsights{Status: "unavailable", Reason: "the database is read-only: pg_stat_statements can't be created"}
	default:
		// Loaded but not created yet (a new instance): the view needs the extension in the database falak-db reads it in.
		if _, err := h.pgObserve(ctx, "postgres", "CREATE EXTENSION IF NOT EXISTS pg_stat_statements;"); err != nil {
			o.QueryInsights = QueryInsights{Status: "unavailable", Reason: "CREATE EXTENSION pg_stat_statements failed: " + firstLine(err.Error())}
		} else {
			o.QueryInsights = QueryInsights{Status: "on"}
		}
	}
	if !detail {
		return nil
	}
	if o.QueryInsights.Status == "on" {
		if out, err := h.pgObserve(ctx, "postgres", pgStatementsSQL); err != nil {
			o.warn("pg_stat_statements: %v", err)
		} else if err := parsePGStatements(lastLine(out), o); err != nil {
			o.warn("pg_stat_statements: %v", err)
		}
	}
	out, err = h.pgObserve(ctx, "postgres", pgDatabasesSQL)
	if err != nil {
		o.warn("databases: %v", err)
		return nil
	}
	if err := json.Unmarshal([]byte(lastLine(out)), &o.Databases); err != nil {
		o.warn("databases: unexpected output")
		return nil
	}
	for i, d := range o.Databases {
		if i >= maxPGDetailDB {
			break
		}
		// The name becomes psql's -d: only plain identifiers (a name holding "=" would be read as a connection string).
		if !identifier.MatchString(d.Name) {
			continue
		}
		out, err := h.pgObserve(ctx, d.Name, pgTablesSQL)
		if err != nil {
			o.warn("tables of %s: %v", d.Name, err)
			continue
		}
		if err := parsePGTables(lastLine(out), d.Name, o); err != nil {
			o.warn("tables of %s: %v", d.Name, err)
		}
	}
	sortTables(o)
	return nil
}

func applyPostgres(o *Observation, p pgObservation) {
	o.Version = p.Version
	for k, v := range p.Counters {
		o.Counters[k] = v
	}
	var total float64
	for _, s := range []string{"active", "idle", "idle_in_transaction", "other"} {
		o.Gauges["connections_"+s] = 0
	}
	for state, n := range p.States {
		o.Gauges["connections_"+pgState(state)] += n
		total += n
	}
	o.Gauges["connections"] = total
	o.Gauges["max_connections"] = p.MaxConnections
	o.Gauges["locks_waiting"] = p.LocksWaiting
	o.Labels["role"] = "primary"
	if p.InRecovery {
		o.Labels["role"] = "replica"
	}
	if p.LagS != nil {
		o.Gauges["replication_lag_s"] = *p.LagS
	}
	o.Settings["shared_buffers_bytes"] = p.SharedBuffersBytes
	o.Settings["max_connections"] = p.MaxConnections
	o.Settings["slow_query_ms"] = p.SlowMS
	o.Settings["uptime_s"] = p.UptimeS
	o.Settings["stats_age_s"] = p.StatsAgeS
}

// pgStatementsSQL: the statements by total time, falak-db's own (they start with observeMarker: pg_stat_statements
// keeps a leading comment) left out. Apps may well use the superuser: its statements count.
var pgStatementsSQL = observeMarker + fmt.Sprintf(`SELECT coalesce(jsonb_agg(s), '[]') FROM (SELECT s.queryid::text AS id, d.datname AS database,
 s.calls, s.total_exec_time AS total_ms, s.max_exec_time AS max_ms, s.rows, s.query
 FROM pg_stat_statements s JOIN pg_database d ON d.oid = s.dbid
 WHERE s.queryid IS NOT NULL AND s.query NOT LIKE '%s%%'
 ORDER BY s.total_exec_time DESC LIMIT %d) s;`, observeMarker, maxStatements)

var pgDatabasesSQL = observeMarker + fmt.Sprintf(`SELECT coalesce(jsonb_agg(d), '[]') FROM (SELECT datname AS name, pg_database_size(oid) AS size_bytes
 FROM pg_database WHERE datallowconn AND NOT datistemplate ORDER BY 2 DESC LIMIT %d) d;`, maxDatabases)

// pgTablesSQL runs in each database: its largest tables and its largest indexes nothing has scanned.
var pgTablesSQL = observeMarker + fmt.Sprintf(`SELECT jsonb_build_object(
 'tables', (SELECT coalesce(jsonb_agg(t), '[]') FROM (SELECT schemaname AS schema, relname AS name,
   pg_total_relation_size(relid) AS size_bytes, n_live_tup AS rows, coalesce(seq_scan, 0) AS seq_scan,
   coalesce(seq_tup_read, 0) AS seq_tup_read, coalesce(idx_scan, 0) AS idx_scan
   FROM pg_stat_user_tables ORDER BY 3 DESC LIMIT %d) t),
 'indexes', (SELECT coalesce(jsonb_agg(i), '[]') FROM (SELECT s.schemaname AS schema, s.relname AS "table",
   s.indexrelname AS name, pg_relation_size(s.indexrelid) AS size_bytes, s.idx_scan AS scans
   FROM pg_stat_user_indexes s JOIN pg_index x ON x.indexrelid = s.indexrelid
   WHERE s.idx_scan = 0 AND NOT x.indisunique AND NOT x.indisprimary ORDER BY 4 DESC LIMIT %d) i));`, maxTables, maxIndexes)

func parsePGStatements(out string, o *Observation) error {
	var rows []struct {
		ID       string  `json:"id"`
		Database string  `json:"database"`
		Calls    int64   `json:"calls"`
		TotalMS  float64 `json:"total_ms"`
		MaxMS    float64 `json:"max_ms"`
		Rows     int64   `json:"rows"`
		Query    string  `json:"query"`
	}
	if err := json.Unmarshal([]byte(out), &rows); err != nil {
		return fmt.Errorf("unexpected output")
	}
	for _, r := range rows {
		o.Statements = append(o.Statements, Statement{
			ID: "pg:" + r.ID, Database: r.Database, Query: sqlnorm.Normalize(r.Query, sqlnorm.Postgres),
			Calls: r.Calls, TotalMS: r.TotalMS, MaxMS: r.MaxMS, Rows: r.Rows, Source: "pg_stat_statements",
		})
	}
	return nil
}

func parsePGTables(out, database string, o *Observation) error {
	var r struct {
		Tables  []TableStat `json:"tables"`
		Indexes []IndexStat `json:"indexes"`
	}
	if err := json.Unmarshal([]byte(out), &r); err != nil {
		return fmt.Errorf("unexpected output")
	}
	for _, t := range r.Tables {
		t.Database = database
		o.Tables = append(o.Tables, t)
	}
	for _, i := range r.Indexes {
		i.Database = database
		o.Indexes = append(o.Indexes, i)
	}
	return nil
}

// sortTables keeps the largest tables and indexes over every database.
func sortTables(o *Observation) {
	sort.SliceStable(o.Tables, func(i, j int) bool { return o.Tables[i].SizeBytes > o.Tables[j].SizeBytes })
	if len(o.Tables) > maxTables*2 {
		o.Tables = o.Tables[:maxTables*2]
	}
	sort.SliceStable(o.Indexes, func(i, j int) bool { return o.Indexes[i].SizeBytes > o.Indexes[j].SizeBytes })
	if len(o.Indexes) > maxIndexes*2 {
		o.Indexes = o.Indexes[:maxIndexes*2]
	}
}

// lastLine is psql's result: the JSON a query printed after any notices. The queries build jsonb, whose text is one
// line; a document spread over several lines (json_agg puts each array element on its own) is still taken whole: the
// shortest tail of the output that is valid JSON.
func lastLine(s string) string {
	s = strings.TrimSpace(s)
	lines := strings.Split(s, "\n")
	for i := len(lines) - 1; i >= 0; i-- {
		if tail := strings.Join(lines[i:], "\n"); json.Valid([]byte(tail)) {
			return tail
		}
	}
	return lines[len(lines)-1]
}

// ---- MySQL / MariaDB ----

// myStatus maps SHOW GLOBAL STATUS names to the counters Falak reports.
var myStatus = map[string]string{
	"Questions": "queries", "Com_select": "op_select", "Com_insert": "op_insert", "Com_update": "op_update",
	"Com_delete": "op_delete", "Com_commit": "xact_commit", "Com_rollback": "xact_rollback",
	"Innodb_rows_read": "rows_read", "Innodb_rows_inserted": "rows_inserted", "Innodb_rows_updated": "rows_updated",
	"Innodb_rows_deleted": "rows_deleted", "Innodb_row_lock_waits": "lock_waits", "Innodb_row_lock_time": "lock_wait_ms",
	"Slow_queries": "slow_queries", "Aborted_connects": "aborted_connects",
	"Connection_errors_max_connections": "max_connections_errors", "Bytes_received": "bytes_received",
	"Bytes_sent": "bytes_sent", "Innodb_deadlocks": "deadlocks", "Created_tmp_disk_tables": "tmp_disk_tables",
}

var mySystemSchemas = "('mysql', 'sys', 'performance_schema', 'information_schema')"

func (h *Helper) myObserveSQL() string {
	sql := "SHOW GLOBAL STATUS;\n" +
		"SELECT 'max_connections', @@max_connections;\nSELECT 'innodb_buffer_pool_size', @@innodb_buffer_pool_size;\n" +
		"SELECT 'long_query_time', @@long_query_time;\nSELECT 'slow_query_log', @@slow_query_log;\n" +
		"SELECT 'performance_schema', @@performance_schema;\nSELECT 'version', @@version;\n"
	if h.Engine == MySQL {
		// MySQL has no deadlock status variable; this InnoDB metric is on by default.
		sql += "SELECT 'lock_deadlocks', COUNT FROM information_schema.INNODB_METRICS WHERE NAME = 'lock_deadlocks';\n"
	}
	return sql
}

// mySessionLimits keeps observe's queries short: MySQL max_execution_time (ms, SELECTs), MariaDB max_statement_time (s).
func (h *Helper) mySessionLimits() string {
	if h.Engine == MariaDB {
		return "SET SESSION lock_wait_timeout = 2, max_statement_time = 15;\n"
	}
	return "SET SESSION lock_wait_timeout = 2, max_execution_time = 15000;\n"
}

func (h *Helper) observeMySQL(ctx context.Context, o *Observation, detail bool) error {
	out, err := h.myExec(ctx, h.mySessionLimits()+h.myObserveSQL())
	if err != nil {
		return err
	}
	vars := tabMap(out)
	if _, ok := vars["Threads_connected"]; !ok {
		return fmt.Errorf("observe: unexpected output %q", firstLine(out))
	}
	applyMySQL(o, vars)
	if vars["performance_schema"] == "1" {
		o.QueryInsights = QueryInsights{Status: "on"}
	} else {
		reason := "performance_schema is off (MySQL turns it off below 1 GiB of memory): top queries come from the slow log"
		if h.Engine == MariaDB {
			reason = "performance_schema is off (enabling it restarts the database): top queries come from the slow log"
		}
		o.QueryInsights = QueryInsights{Status: "off", Reason: reason}
	}
	h.readSlowLog(o)
	if !detail {
		return nil
	}
	// Sizes as information_schema caches them (MySQL: up to a day old): refreshing them would read every table's stats.
	if out, err := h.myExec(ctx, h.mySessionLimits()+mySizesSQL); err != nil {
		o.warn("sizes: %v", err)
	} else {
		parseMySizes(out, o)
	}
	if o.QueryInsights.Status == "on" {
		if out, err := h.myExec(ctx, h.mySessionLimits()+myStatementsSQL); err != nil {
			o.warn("statement digests: %v", err)
		} else {
			parseMyStatements(out, o)
		}
		if out, err := h.myExec(ctx, h.mySessionLimits()+myUnusedIndexesSQL); err != nil {
			o.warn("index usage: %v", err)
		} else {
			parseMyIndexes(out, o)
		}
	}
	return nil
}

// tabMap reads "name<TAB>value" lines.
func tabMap(out string) map[string]string {
	m := map[string]string{}
	for _, l := range lines(out) {
		if k, v, ok := strings.Cut(l, "\t"); ok {
			m[k] = strings.TrimSpace(v)
		}
	}
	return m
}

func num(s string) float64 {
	f, _ := strconv.ParseFloat(strings.TrimSpace(s), 64)
	return f
}

func applyMySQL(o *Observation, v map[string]string) {
	o.Version = v["version"]
	for status, name := range myStatus {
		if s, ok := v[status]; ok {
			o.Counters[name] = num(s)
		}
	}
	if s, ok := v["lock_deadlocks"]; ok {
		o.Counters["deadlocks"] = num(s)
	}
	// Logical reads and the ones that had to go to disk.
	requests, reads := num(v["Innodb_buffer_pool_read_requests"]), num(v["Innodb_buffer_pool_reads"])
	o.Counters["cache_hits"] = max(requests-reads, 0)
	o.Counters["cache_misses"] = reads
	connected, running := num(v["Threads_connected"]), num(v["Threads_running"])
	o.Gauges["connections"] = connected
	o.Gauges["connections_running"] = running
	o.Gauges["connections_idle"] = max(connected-running, 0)
	o.Gauges["max_connections"] = num(v["max_connections"])
	o.Labels["role"] = "primary"
	o.Settings["max_connections"] = num(v["max_connections"])
	o.Settings["innodb_buffer_pool_bytes"] = num(v["innodb_buffer_pool_size"])
	if v["slow_query_log"] == "1" {
		o.Settings["slow_query_ms"] = num(v["long_query_time"]) * 1000
	} else {
		o.Settings["slow_query_ms"] = 0
	}
	o.Settings["uptime_s"] = num(v["Uptime"])
	o.Settings["stats_age_s"] = num(v["Uptime"])
}

var mySizesSQL = fmt.Sprintf(`SELECT 'db', TABLE_SCHEMA, '', SUM(DATA_LENGTH + INDEX_LENGTH), 0 FROM information_schema.TABLES
 WHERE TABLE_SCHEMA NOT IN %[1]s GROUP BY TABLE_SCHEMA ORDER BY 4 DESC LIMIT %[2]d;
SELECT 'table', TABLE_SCHEMA, TABLE_NAME, DATA_LENGTH + INDEX_LENGTH, IFNULL(TABLE_ROWS, 0) FROM information_schema.TABLES
 WHERE TABLE_SCHEMA NOT IN %[1]s AND TABLE_TYPE = 'BASE TABLE' ORDER BY 4 DESC LIMIT %[3]d;
`, mySystemSchemas, maxDatabases, maxTables)

func parseMySizes(out string, o *Observation) {
	for _, l := range lines(out) {
		f := strings.Split(l, "\t")
		if len(f) != 5 {
			continue
		}
		switch f[0] {
		case "db":
			o.Databases = append(o.Databases, DatabaseSize{Name: myUnescape(f[1]), SizeBytes: int64(num(f[3]))})
		case "table":
			o.Tables = append(o.Tables, TableStat{Database: myUnescape(f[1]), Name: myUnescape(f[2]), SizeBytes: int64(num(f[3])), Rows: int64(num(f[4]))})
		}
	}
}

// myStatementsSQL: digests by total time; statements without a default schema (falak-db's own) left out. The digest
// text comes last: it may hold tabs (escaped by mysql -B, unescaped here).
var myStatementsSQL = fmt.Sprintf(`SELECT SCHEMA_NAME, DIGEST, COUNT_STAR, SUM_TIMER_WAIT / 1000000000, MAX_TIMER_WAIT / 1000000000,
 SUM_ROWS_SENT, SUM_NO_INDEX_USED, DIGEST_TEXT FROM performance_schema.events_statements_summary_by_digest
 WHERE DIGEST IS NOT NULL AND DIGEST_TEXT IS NOT NULL AND SCHEMA_NAME IS NOT NULL AND SCHEMA_NAME NOT IN %s
 ORDER BY SUM_TIMER_WAIT DESC LIMIT %d;
`, mySystemSchemas, maxStatements)

func parseMyStatements(out string, o *Observation) {
	for _, l := range lines(out) {
		f := strings.SplitN(l, "\t", 8)
		if len(f) != 8 {
			continue
		}
		o.Statements = append(o.Statements, Statement{
			ID: "my:" + f[1], Database: myUnescape(f[0]), Calls: int64(num(f[2])), TotalMS: num(f[3]), MaxMS: num(f[4]),
			Rows: int64(num(f[5])), NoIndex: int64(num(f[6])), Query: sqlnorm.Normalize(myUnescape(f[7]), sqlnorm.MySQL), Source: "digest",
		})
	}
}

// myUnusedIndexesSQL: non-unique indexes nothing used since the server started, except those backing a foreign key
// (InnoDB needs an index on the referencing columns: it would refuse the drop).
var myUnusedIndexesSQL = fmt.Sprintf(`SELECT u.OBJECT_SCHEMA, u.OBJECT_NAME, u.INDEX_NAME, IFNULL(s.stat_value, 0) * @@innodb_page_size
 FROM performance_schema.table_io_waits_summary_by_index_usage u
 LEFT JOIN mysql.innodb_index_stats s ON s.database_name = u.OBJECT_SCHEMA AND s.table_name = u.OBJECT_NAME
   AND s.index_name = u.INDEX_NAME AND s.stat_name = 'size'
 WHERE u.INDEX_NAME IS NOT NULL AND u.INDEX_NAME <> 'PRIMARY' AND u.COUNT_STAR = 0 AND u.OBJECT_SCHEMA NOT IN %s
   AND EXISTS (SELECT 1 FROM information_schema.STATISTICS st WHERE st.TABLE_SCHEMA = u.OBJECT_SCHEMA
     AND st.TABLE_NAME = u.OBJECT_NAME AND st.INDEX_NAME = u.INDEX_NAME AND st.NON_UNIQUE = 1)
   AND NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS s1 JOIN information_schema.KEY_COLUMN_USAGE k
     ON k.TABLE_SCHEMA = s1.TABLE_SCHEMA AND k.TABLE_NAME = s1.TABLE_NAME AND k.COLUMN_NAME = s1.COLUMN_NAME
     AND k.ORDINAL_POSITION = 1 AND k.REFERENCED_TABLE_NAME IS NOT NULL
     WHERE s1.TABLE_SCHEMA = u.OBJECT_SCHEMA AND s1.TABLE_NAME = u.OBJECT_NAME AND s1.INDEX_NAME = u.INDEX_NAME AND s1.SEQ_IN_INDEX = 1)
 ORDER BY 4 DESC LIMIT %d;
`, mySystemSchemas, maxIndexes)

func parseMyIndexes(out string, o *Observation) {
	for _, l := range lines(out) {
		f := strings.Split(l, "\t")
		if len(f) != 4 {
			continue
		}
		o.Indexes = append(o.Indexes, IndexStat{Database: myUnescape(f[0]), Table: myUnescape(f[1]), Name: myUnescape(f[2]), SizeBytes: int64(num(f[3]))})
	}
}

// myUnescape undoes mysql -B's escaping (\t, \n, \\, \0) and its NULL.
func myUnescape(s string) string {
	if s == "NULL" {
		return ""
	}
	if !strings.Contains(s, `\`) {
		return s
	}
	return strings.NewReplacer(`\t`, "\t", `\n`, "\n", `\\`, `\`, `\0`, "").Replace(s)
}

// ---- Redis / Valkey ----

func (h *Helper) observeKV(ctx context.Context, o *Observation, detail bool) error {
	info, err := h.kvCmd(ctx, "INFO", "server", "clients", "memory", "stats", "replication", "errorstats", "keyspace")
	if err != nil {
		return err
	}
	if !applyKVInfo(o, info) {
		return fmt.Errorf("observe: unexpected output %q", firstLine(info))
	}
	o.QueryInsights = QueryInsights{Status: "on"}
	if out, err := h.kvCmd(ctx, "--json", "SLOWLOG", "GET", strconv.Itoa(128)); err != nil {
		o.warn("SLOWLOG: %v", err)
	} else if err := parseSlowlog(out, o); err != nil {
		o.warn("SLOWLOG: %v", err)
	}
	if !detail {
		o.Databases = nil
	}
	return nil
}

// applyKVInfo reads INFO; false when it is not INFO output.
func applyKVInfo(o *Observation, info string) bool {
	v := map[string]string{}
	for _, l := range lines(info) {
		if k, val, ok := strings.Cut(strings.TrimSpace(l), ":"); ok && !strings.HasPrefix(k, "#") {
			v[k] = val
		}
	}
	if _, ok := v["connected_clients"]; !ok {
		return false
	}
	o.Version = v["redis_version"]
	if vv := v["valkey_version"]; vv != "" {
		o.Version = vv
	}
	for k, name := range map[string]string{
		"total_commands_processed": "queries", "keyspace_hits": "cache_hits", "keyspace_misses": "cache_misses",
		"evicted_keys": "evicted_keys", "expired_keys": "expired_keys", "rejected_connections": "rejected_connections",
		"total_net_input_bytes": "bytes_received", "total_net_output_bytes": "bytes_sent",
	} {
		if s, ok := v[k]; ok {
			o.Counters[name] = num(s)
		}
	}
	// errorstat_<ERR>:count=n (Redis 6.2+, Valkey).
	o.Counters["oom_errors"] = errorstat(v["errorstat_OOM"])
	o.Counters["auth_errors"] = errorstat(v["errorstat_NOAUTH"]) + errorstat(v["errorstat_WRONGPASS"])
	connected, blocked := num(v["connected_clients"]), num(v["blocked_clients"])
	o.Gauges["connections"] = connected
	o.Gauges["connections_blocked"] = blocked
	o.Gauges["connections_normal"] = max(connected-blocked, 0)
	o.Gauges["max_connections"] = num(v["maxclients"])
	o.Gauges["used_memory"] = num(v["used_memory"])
	o.Gauges["maxmemory"] = num(v["maxmemory"])
	o.Labels["maxmemory_policy"] = v["maxmemory_policy"]
	o.Labels["role"] = "primary"
	if v["role"] == "slave" || v["role"] == "replica" {
		o.Labels["role"] = "replica"
		if v["master_link_status"] != "up" {
			o.Gauges["replication_broken"] = 1
		}
		if s, ok := v["master_last_io_seconds_ago"]; ok && num(s) >= 0 {
			o.Gauges["replication_lag_s"] = num(s)
		}
	}
	o.Settings["maxmemory_bytes"] = num(v["maxmemory"])
	o.Settings["max_connections"] = num(v["maxclients"])
	o.Settings["uptime_s"] = num(v["uptime_in_seconds"])
	var keys float64
	names := []string{}
	for k := range v {
		if strings.HasPrefix(k, "db") {
			if _, err := strconv.Atoi(k[2:]); err == nil {
				names = append(names, k)
			}
		}
	}
	sort.Strings(names)
	for _, k := range names {
		n := kvField(v[k], "keys")
		keys += n
		if len(o.Databases) < maxDatabases {
			o.Databases = append(o.Databases, DatabaseSize{Name: k, Keys: int64(n)})
		}
	}
	o.Gauges["keys"] = keys
	return true
}

// kvField reads one "a=1,b=2" field.
func kvField(s, name string) float64 {
	for _, kv := range strings.Split(s, ",") {
		if k, v, ok := strings.Cut(kv, "="); ok && k == name {
			return num(v)
		}
	}
	return 0
}

func errorstat(s string) float64 { return kvField(s, "count") }

// parseSlowlog reads `--json SLOWLOG GET` ([[id, unix time, µs, [args], client, name], ...], newest first): the
// entries from the cursor's id on (the id the next entry gets). Without a cursor (first call) only the id is taken; a
// newest id below the cursor means the server restarted (or SLOWLOG RESET), so every entry is new.
func parseSlowlog(out string, o *Observation) error {
	var raw [][]json.RawMessage
	if err := json.Unmarshal([]byte(strings.TrimSpace(out)), &raw); err != nil {
		return fmt.Errorf("unexpected output")
	}
	type entry struct {
		id, at, us int64
		args       []string
	}
	var entries []entry
	var top int64 = -1
	for _, r := range raw {
		if len(r) < 4 {
			continue
		}
		var e entry
		if json.Unmarshal(r[0], &e.id) != nil || json.Unmarshal(r[1], &e.at) != nil || json.Unmarshal(r[2], &e.us) != nil {
			continue
		}
		_ = json.Unmarshal(r[3], &e.args)
		entries = append(entries, e)
		top = max(top, e.id)
	}
	cur := o.Cursor
	if top >= 0 {
		o.Cursor.SlowLogID = top + 1
	}
	if !cur.Started {
		return nil
	}
	from := cur.SlowLogID
	if top >= 0 && top < from-1 {
		from = 0
	}
	sort.Slice(entries, func(i, j int) bool { return entries[i].id < entries[j].id })
	for _, e := range entries {
		if e.id < from || len(e.args) == 0 {
			continue
		}
		o.Slow = append(o.Slow, SlowEntry{At: time.Unix(e.at, 0).UTC(), DurationMS: float64(e.us) / 1000, Query: KVShape(e.args)})
	}
	if len(o.Slow) > maxSlow {
		o.Slow = o.Slow[len(o.Slow)-maxSlow:]
	}
	return nil
}

// kvAllKeys are commands whose every argument is a key; kvPairKeys take key value pairs.
var (
	kvAllKeys  = map[string]bool{"MGET": true, "DEL": true, "UNLINK": true, "EXISTS": true, "TOUCH": true, "WATCH": true, "SUNION": true, "SINTER": true, "SDIFF": true, "PFCOUNT": true, "PFMERGE": true, "SUNIONSTORE": true, "SINTERSTORE": true, "SDIFFSTORE": true}
	kvPairKeys = map[string]bool{"MSET": true, "MSETNX": true}
	kvNoKeys   = map[string]bool{"PING": true, "INFO": true, "KEYS": true, "SCAN": true, "FLUSHALL": true, "FLUSHDB": true, "DBSIZE": true, "SELECT": true, "AUTH": true, "CLIENT": true, "MULTI": true, "EXEC": true, "DISCARD": true, "SAVE": true, "BGSAVE": true, "BGREWRITEAOF": true, "LASTSAVE": true, "SLOWLOG": true, "COMMAND": true, "SCRIPT": true, "FUNCTION": true, "ACL": true, "HELLO": true, "TIME": true, "PUBLISH": true, "SUBSCRIBE": true, "PSUBSCRIBE": true, "LATENCY": true, "MEMORY": true, "WAIT": true}
)

// KVShape is a Redis command without its arguments: its name and how many keys it touched ("MGET <3 keys>"). KEYS keeps
// nothing of its pattern either.
func KVShape(args []string) string {
	if len(args) == 0 {
		return ""
	}
	name := strings.ToUpper(args[0])
	if len(name) > 40 || strings.ContainsAny(name, " \t\r\n") {
		name = "?"
	}
	rest := len(args) - 1
	var keys int
	switch {
	case kvNoKeys[name] || rest == 0:
		return name
	case kvAllKeys[name]:
		keys = rest
	case kvPairKeys[name]:
		keys = rest / 2
	default:
		keys = 1
	}
	if keys == 1 {
		return name + " <1 key>"
	}
	return fmt.Sprintf("%s <%d keys>", name, keys)
}

func helperVersion() string { return version.Version }
