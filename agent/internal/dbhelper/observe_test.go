package dbhelper

import (
	"context"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
)

func fixture(t *testing.T, name string) string {
	t.Helper()
	b, err := os.ReadFile(filepath.Join("testdata", "observe", name))
	if err != nil {
		t.Fatal(err)
	}
	return string(b)
}

func observation(t *testing.T, th *testHelper) Observation {
	t.Helper()
	var o Observation
	if err := json.Unmarshal([]byte(strings.TrimSpace(th.out.String())), &o); err != nil {
		t.Fatalf("observe printed %q: %v", th.out.String(), err)
	}
	th.out.Reset()
	return o
}

const pgObserved = `{"version":"17.2","counters":{"xact_commit":1000,"xact_rollback":10,"cache_hits":9900,"cache_misses":100,"rows_returned":5,"rows_fetched":4,"rows_inserted":3,"rows_updated":2,"rows_deleted":1,"deadlocks":2,"temp_bytes":4096},` +
	`"states":{"active":2,"idle":5,"idle in transaction":1,"idle in transaction (aborted)":1,"fastpath function call":1},"max_connections":100,"locks_waiting":3,"in_recovery":false,"lag_s":null,` +
	`"shared_buffers_bytes":134217728,"slow_ms":1000,"preload":"pg_stat_statements","uptime_s":3600.5,"stats_age_s":3600.5,"pss":true}`

func TestObservePostgres(t *testing.T) {
	th := newTestHelper(t, Postgres)
	os.MkdirAll(filepath.Join(th.Root, "sys/fs/cgroup"), 0o755)
	os.WriteFile(filepath.Join(th.Root, "sys/fs/cgroup/memory.current"), []byte("104857600\n"), 0o644)
	os.WriteFile(filepath.Join(th.Root, "sys/fs/cgroup/memory.max"), []byte("536870912\n"), 0o644)
	os.WriteFile(filepath.Join(th.Root, "sys/fs/cgroup/memory.events"), []byte("low 0\nhigh 0\nmax 4\noom 1\noom_kill 1\n"), 0o644)
	var dbs []string
	th.run.handle = func(c Cmd) (string, error) {
		sql := th.run.stdins["psql"]
		db := c.Args[7]
		switch {
		case strings.Contains(sql, "jsonb_build_object(\n 'version'"):
			return pgObserved + "\n", nil
		case strings.Contains(sql, "pg_stat_statements s"):
			// As psql prints a json_agg (one element per line, as seen live): the result is taken whole all the same.
			return `[{"id":"-123","database":"shop","calls":10,"total_ms":2500.5,"max_ms":900,"rows":10,"query":"SELECT * FROM orders WHERE id = $1"}, ` + "\n " +
				`{"id":"77","database":"shop","calls":1,"total_ms":3,"max_ms":3,"rows":0,"query":"ALTER ROLE app PASSWORD 'hunter2'"}]`, nil
		case strings.Contains(sql, "pg_database_size"):
			return `[{"name":"shop","size_bytes":2000000},{"name":"bad=name","size_bytes":10},{"name":"postgres","size_bytes":800}]`, nil
		case strings.Contains(sql, "pg_stat_user_tables"):
			dbs = append(dbs, db)
			return `{"tables":[{"schema":"public","name":"orders","size_bytes":500000,"rows":1000,"seq_scan":5000,"seq_tup_read":5000000,"idx_scan":10}],` +
				`"indexes":[{"schema":"public","table":"orders","name":"orders_note_idx","size_bytes":40000,"scans":0}]}`, nil
		}
		return "", errors.New("unexpected query: " + sql)
	}
	if err := th.Observe(context.Background(), true, ""); err != nil {
		t.Fatal(err)
	}
	o := observation(t, th)
	if o.Version != "17.2" || o.Counters["cache_hits"] != 9900 || o.Counters["deadlocks"] != 2 {
		t.Errorf("counters: %+v", o)
	}
	want := map[string]float64{"connections": 10, "connections_active": 2, "connections_idle": 5, "connections_idle_in_transaction": 2, "connections_other": 1, "max_connections": 100, "locks_waiting": 3}
	for k, v := range want {
		if o.Gauges[k] != v {
			t.Errorf("gauge %s = %v, want %v", k, o.Gauges[k], v)
		}
	}
	if _, ok := o.Gauges["replication_lag_s"]; ok || o.Labels["role"] != "primary" {
		t.Errorf("a primary reports lag: %+v", o.Gauges)
	}
	if o.QueryInsights.Status != "on" || len(o.Statements) != 2 || o.Statements[0].ID != "pg:-123" || o.Statements[0].Source != "pg_stat_statements" {
		t.Fatalf("statements: %+v %+v", o.QueryInsights, o.Statements)
	}
	if strings.Contains(o.Statements[1].Query, "hunter2") {
		t.Errorf("a utility statement's literal was kept: %q", o.Statements[1].Query)
	}
	if strings.Join(dbs, ",") != "shop,postgres" {
		t.Errorf("tables read in %v (a name that is not an identifier must be skipped)", dbs)
	}
	if len(o.Tables) != 2 || o.Tables[0].Database == "" || len(o.Indexes) != 2 || o.Indexes[0].Name != "orders_note_idx" {
		t.Errorf("tables %+v indexes %+v", o.Tables, o.Indexes)
	}
	if o.Memory == nil || o.Memory.UsedBytes != 104857600 || o.Memory.LimitBytes != 536870912 || o.Memory.OOMKills != 1 {
		t.Errorf("memory %+v", o.Memory)
	}
	if o.Settings["slow_query_ms"] != 1000 || o.Settings["shared_buffers_bytes"] != 134217728 || !o.Cursor.Started {
		t.Errorf("settings %+v cursor %+v", o.Settings, o.Cursor)
	}
	for _, c := range th.run.calls {
		if c.Name != "psql" || !strings.Contains(strings.Join(c.Args, " "), "-h /var/run/postgresql") {
			t.Errorf("not over the socket: %v", c)
		}
	}
}

func TestObservePostgresQueryInsights(t *testing.T) {
	for _, c := range []struct {
		preload, created string
		createErr        error
		want             string
	}{
		{"", "false", nil, "off"},
		{"pg_stat_statements", "false", nil, "on"},
		{"pg_stat_statements", "false", errors.New("cannot execute CREATE EXTENSION in a read-only transaction"), "unavailable"},
		{"pg_stat_statements", "false", nil, "unavailable"}, // read-only: never created
	} {
		th := newTestHelper(t, Postgres)
		created := false
		th.run.handle = func(Cmd) (string, error) {
			sql := th.run.stdins["psql"]
			switch {
			case strings.Contains(sql, "CREATE EXTENSION"):
				created = true
				return "", c.createErr
			case strings.Contains(sql, "'version'"):
				readOnly := c.want == "unavailable" && c.createErr == nil
				return strings.NewReplacer(`"preload":"pg_stat_statements"`, `"preload":"`+c.preload+`"`, `"pss":true`, `"pss":`+c.created+`,"read_only":`+strconv.FormatBool(readOnly),
					`"in_recovery":false,"lag_s":null`, `"in_recovery":false,"lag_s":4.5`).Replace(pgObserved), nil
			}
			return "[]", nil
		}
		if err := th.Observe(context.Background(), false, ""); err != nil {
			t.Fatal(err)
		}
		o := observation(t, th)
		readOnly := c.want == "unavailable" && c.createErr == nil
		if o.QueryInsights.Status != c.want || created != (c.preload != "" && !readOnly) {
			t.Errorf("preload %q: %+v (created %v)", c.preload, o.QueryInsights, created)
		}
		if o.Gauges["replication_lag_s"] != 4.5 {
			t.Errorf("lag: %+v", o.Gauges)
		}
		if len(o.Statements) != 0 || len(o.Tables) != 0 {
			t.Errorf("detail sections without --detail")
		}
	}
}

func TestObserveMySQL(t *testing.T) {
	for _, e := range []Engine{MySQL, MariaDB} {
		th := newTestHelper(t, e)
		status := fixture(t, "mysql-status.txt")
		if e == MariaDB {
			status = strings.Replace(status, "lock_deadlocks\t2", "Innodb_deadlocks\t2", 1)
		}
		th.run.handle = func(c Cmd) (string, error) {
			sql := th.run.stdins[c.Name]
			switch {
			case strings.Contains(sql, "SHOW GLOBAL STATUS"):
				if !strings.HasPrefix(sql, "SET SESSION lock_wait_timeout = 2") {
					t.Errorf("%s: no session limits %q", e, sql)
				}
				if (e == MySQL) != strings.Contains(sql, "INNODB_METRICS") {
					t.Errorf("%s: deadlock query %q", e, sql)
				}
				return status, nil
			case strings.Contains(sql, "information_schema.TABLES"):
				if strings.Contains(sql, "information_schema_stats_expiry") {
					t.Errorf("%s: sizes refreshed on every call %q", e, sql)
				}
				limit := map[Engine]string{MySQL: "max_execution_time = 15000", MariaDB: "max_statement_time = 15"}[e]
				if !strings.HasPrefix(sql, "SET SESSION lock_wait_timeout = 2, "+limit) {
					t.Errorf("%s: no session limits %q", e, sql)
				}
				return "db\tshop\t\t5000000\t0\ntable\tshop\torders\t4000000\t1200\n", nil
			}
			return "", errors.New("unexpected " + sql)
		}
		if err := th.Observe(context.Background(), true, ""); err != nil {
			t.Fatal(e, err)
		}
		o := observation(t, th)
		if o.Counters["cache_hits"] != 99750 || o.Counters["cache_misses"] != 250 || o.Counters["deadlocks"] != 2 || o.Counters["queries"] != 1234 || o.Counters["lock_wait_ms"] != 1500 {
			t.Errorf("%s counters %+v", e, o.Counters)
		}
		if o.Gauges["connections"] != 12 || o.Gauges["connections_running"] != 3 || o.Gauges["connections_idle"] != 9 || o.Gauges["max_connections"] != 151 {
			t.Errorf("%s gauges %+v", e, o.Gauges)
		}
		if o.Settings["slow_query_ms"] != 1000 || o.Settings["innodb_buffer_pool_bytes"] != 295698432 || o.Settings["uptime_s"] != 864000 {
			t.Errorf("%s settings %+v", e, o.Settings)
		}
		if o.QueryInsights.Status != "off" || !strings.Contains(o.QueryInsights.Reason, "slow log") {
			t.Errorf("%s query insights %+v", e, o.QueryInsights)
		}
		if len(o.Databases) != 1 || o.Databases[0].SizeBytes != 5000000 || len(o.Tables) != 1 || o.Tables[0].Rows != 1200 {
			t.Errorf("%s sizes %+v %+v", e, o.Databases, o.Tables)
		}
		if len(o.Statements) != 0 {
			t.Errorf("%s: digests read with performance_schema off", e)
		}
	}
}

func TestObserveMySQLDigests(t *testing.T) {
	th := newTestHelper(t, MySQL)
	th.run.handle = func(c Cmd) (string, error) {
		sql := th.run.stdins[c.Name]
		switch {
		case strings.Contains(sql, "SHOW GLOBAL STATUS"):
			return strings.Replace(fixture(t, "mysql-status.txt"), "performance_schema\t0", "performance_schema\t1", 1), nil
		case strings.Contains(sql, "events_statements_summary_by_digest"):
			return "shop\tabc123\t40\t8000.5\t900.25\t40\t40\tSELECT * FROM `orders` WHERE `email` = ? AND `note` = 'x\\ty'\n" +
				"NULL\tbad\t1\t1\t1\t1\t0\tshort\n", nil
		case strings.Contains(sql, "table_io_waits_summary_by_index_usage"):
			return "shop\torders\tidx_note\t16384\n", nil
		}
		return "", nil
	}
	if err := th.Observe(context.Background(), true, ""); err != nil {
		t.Fatal(err)
	}
	o := observation(t, th)
	if o.QueryInsights.Status != "on" || len(o.Statements) != 2 {
		t.Fatalf("%+v %+v", o.QueryInsights, o.Statements)
	}
	s := o.Statements[0]
	if s.ID != "my:abc123" || s.Database != "shop" || s.Calls != 40 || s.TotalMS != 8000.5 || s.MaxMS != 900.25 || s.NoIndex != 40 || s.Source != "digest" {
		t.Errorf("statement %+v", s)
	}
	if strings.Contains(s.Query, "'x") || !strings.Contains(s.Query, "`note` = ?") {
		t.Errorf("digest text not normalized: %q", s.Query)
	}
	if len(o.Indexes) != 1 || o.Indexes[0].Name != "idx_note" || o.Indexes[0].SizeBytes != 16384 {
		t.Errorf("indexes %+v", o.Indexes)
	}
}

func TestParseSlowLog(t *testing.T) {
	b := []byte(fixture(t, "mysql-slow.log"))
	entries, used := ParseSlowLog(b)
	if len(entries) != 2 {
		t.Fatalf("entries %+v", entries)
	}
	if e := entries[0]; e.DurationMS != 2500 || e.Database != "shop" || e.Rows != 120000 || e.At.Unix() != 1760443201 ||
		e.Query != "SELECT * FROM orders WHERE customer_email = ? AND total > ?" {
		t.Errorf("first %+v", e)
	}
	if e := entries[1]; e.Database != "shop" || e.Query != "UPDATE users SET password_hash = ?, login_count = ? WHERE id IN (?, ...)" {
		t.Errorf("second %+v", e)
	}
	// The third entry is still being written: the next read starts at it.
	if rest := string(b[used:]); !strings.HasPrefix(rest, "# Time: 2026-10-14T12:00:09") {
		t.Errorf("used %d leaves %q", used, rest)
	}
	for _, e := range entries {
		if strings.Contains(e.Query, "alice") || strings.Contains(e.Query, "secret") {
			t.Errorf("literal kept: %q", e.Query)
		}
	}

	entries, used = ParseSlowLog([]byte(fixture(t, "mariadb-slow.log")))
	if len(entries) != 2 || used != len(fixture(t, "mariadb-slow.log")) {
		t.Fatalf("mariadb entries %+v (%d)", entries, used)
	}
	if e := entries[0]; e.Database != "shop" || e.DurationMS != 1500 || e.Query != "SELECT id FROM carts WHERE token = ?" || e.Rows != 9000 {
		t.Errorf("mariadb first %+v", e)
	}
	if e := entries[1]; e.Query != "SELECT COUNT(*) FROM carts" || e.DurationMS != 1100 {
		t.Errorf("mariadb second %+v", e)
	}
}

func TestReadSlowLogCursor(t *testing.T) {
	th := newTestHelper(t, MySQL)
	path := filepath.Join(th.Root, mySlowLog)
	os.MkdirAll(filepath.Dir(path), 0o755)
	old := "# Time: 2026-10-14T11:00:00Z\n# Query_time: 9.0\nSELECT 'old';\n"
	os.WriteFile(path, []byte(old), 0o644)

	// First call: starts at the end, nothing older is replayed.
	o := &Observation{}
	th.readSlowLog(o)
	if len(o.Slow) != 0 || o.Cursor.SlowLogOffset != int64(len(old)) {
		t.Fatalf("first call %+v", o)
	}
	o.Cursor.Started = true
	f, _ := os.OpenFile(path, os.O_APPEND|os.O_WRONLY, 0)
	f.WriteString(fixture(t, "mysql-slow.log"))
	f.Close()
	cur := o.Cursor
	o = &Observation{Cursor: cur}
	th.readSlowLog(o)
	if len(o.Slow) != 2 || o.Slow[0].Query != "SELECT * FROM orders WHERE customer_email = ? AND total > ?" {
		t.Fatalf("second call %+v", o.Slow)
	}
	// The unfinished entry is read once complete.
	f, _ = os.OpenFile(path, os.O_APPEND|os.O_WRONLY, 0)
	f.WriteString("finished';\n")
	f.Close()
	cur = o.Cursor
	o = &Observation{Cursor: cur}
	th.readSlowLog(o)
	if len(o.Slow) != 1 || o.Slow[0].DurationMS != 3000 || o.Slow[0].Query != "SELECT SLEEP(?) FROM dual WHERE note = ?" {
		t.Fatalf("third call %+v", o.Slow)
	}
	// A shorter file (rotated, truncated) is read from its start.
	os.WriteFile(path, []byte("# Time: 2026-10-14T13:00:00Z\n# Query_time: 1.5\nSELECT 1;\n"), 0o644)
	cur = o.Cursor
	o = &Observation{Cursor: cur}
	th.readSlowLog(o)
	if len(o.Slow) != 1 || o.Slow[0].Query != "SELECT ?" {
		t.Fatalf("after truncation %+v", o.Slow)
	}
}

func TestReadSlowLogTruncatesBigLogs(t *testing.T) {
	th := newTestHelper(t, MariaDB)
	path := filepath.Join(th.Root, mySlowLog)
	os.MkdirAll(filepath.Dir(path), 0o755)
	os.WriteFile(path, []byte("# Time: 261014 12:00:01\n# Query_time: 1.5\nSELECT 1;\n"), 0o644)
	st, _ := os.Stat(path)
	o := &Observation{}
	th.readSlowLog(o)
	o.Cursor.Started = true
	// Pretend everything up to slowTruncate was read: the next complete read empties the file.
	o.Cursor.SlowLogOffset = slowTruncate
	big := strings.Repeat("x", int(slowTruncate-st.Size())) + "\n"
	f, _ := os.OpenFile(path, os.O_APPEND|os.O_WRONLY, 0)
	f.WriteString(big)
	f.WriteString("# Time: 261014 12:00:02\n# Query_time: 2\nSELECT 2;\n")
	f.Close()
	th.readSlowLog(o)
	if len(o.Slow) != 1 || o.Cursor.SlowLogOffset != 0 {
		t.Fatalf("slow %+v cursor %+v", o.Slow, o.Cursor)
	}
	if st, _ := os.Stat(path); st.Size() != 0 {
		t.Errorf("slow log not truncated: %d bytes", st.Size())
	}
}

func TestObserveRedis(t *testing.T) {
	for _, e := range []Engine{Redis, Valkey} {
		th := newTestHelper(t, e)
		slowlog := fixture(t, "redis-slowlog.json")
		th.run.handle = func(c Cmd) (string, error) {
			args := strings.Join(c.Args, " ")
			if c.Name != e.kvCLI() {
				t.Errorf("%s: ran %s", e, c.Name)
			}
			switch {
			case strings.Contains(args, "SLOWLOG GET"):
				if !strings.Contains(args, "--json") {
					t.Errorf("SLOWLOG without --json: %s", args)
				}
				return slowlog, nil
			case strings.Contains(args, "INFO"):
				info := fixture(t, "redis-info.txt")
				if e == Valkey {
					info = strings.Replace(info, "redis_version:7.4.1", "redis_version:7.2.4\nvalkey_version:8.1.1", 1)
				}
				return info, nil
			}
			return "", errors.New("unexpected " + args)
		}
		if err := th.Observe(context.Background(), true, ""); err != nil {
			t.Fatal(err)
		}
		o := observation(t, th)
		if (e == Valkey) != (o.Version == "8.1.1") {
			t.Errorf("%s version %q", e, o.Version)
		}
		if o.Gauges["connections"] != 12 || o.Gauges["connections_blocked"] != 2 || o.Gauges["connections_normal"] != 10 ||
			o.Gauges["used_memory"] != 94371840 || o.Gauges["maxmemory"] != 107374182 || o.Gauges["keys"] != 1520 {
			t.Errorf("%s gauges %+v", e, o.Gauges)
		}
		if o.Counters["oom_errors"] != 7 || o.Counters["auth_errors"] != 3 || o.Counters["cache_hits"] != 900 || o.Counters["rejected_connections"] != 3 {
			t.Errorf("%s counters %+v", e, o.Counters)
		}
		if o.Labels["maxmemory_policy"] != "noeviction" || o.QueryInsights.Status != "on" || len(o.Databases) != 2 {
			t.Errorf("%s labels %+v %+v %+v", e, o.Labels, o.QueryInsights, o.Databases)
		}
		// First call: the id only.
		if len(o.Slow) != 0 || o.Cursor.SlowLogID != 15 {
			t.Fatalf("%s first slowlog %+v %+v", e, o.Slow, o.Cursor)
		}
		cur, _ := json.Marshal(ObserveCursor{Started: true, SlowLogID: 13})
		if err := th.Observe(context.Background(), false, string(cur)); err != nil {
			t.Fatal(err)
		}
		o = observation(t, th)
		if len(o.Slow) != 2 || o.Slow[0].Query != "SET <1 key>" || o.Slow[1].Query != "MGET <3 keys>" || o.Slow[1].DurationMS != 25 || o.Cursor.SlowLogID != 15 {
			t.Errorf("%s slow %+v", e, o.Slow)
		}
		for _, s := range o.Slow {
			if strings.Contains(s.Query, "secret") || strings.Contains(s.Query, "user:") {
				t.Errorf("argument kept: %q", s.Query)
			}
		}
		// The server restarted: ids start again below the cursor, every entry is new.
		cur, _ = json.Marshal(ObserveCursor{Started: true, SlowLogID: 500})
		if err := th.Observe(context.Background(), false, string(cur)); err != nil {
			t.Fatal(err)
		}
		if o = observation(t, th); len(o.Slow) != 3 || o.Slow[0].Query != "KEYS" {
			t.Errorf("%s after a restart %+v", e, o.Slow)
		}
		// Nothing in SLOWLOG on the first call: its first entry (id 0) is still read later.
		slowlog = "[]"
		if err := th.Observe(context.Background(), false, ""); err != nil {
			t.Fatal(err)
		}
		first := observation(t, th).Cursor
		slowlog = `[[0,1760443210,25000,["GET","k"],"",""]]`
		cur, _ = json.Marshal(first)
		if err := th.Observe(context.Background(), false, string(cur)); err != nil {
			t.Fatal(err)
		}
		if o = observation(t, th); len(o.Slow) != 1 || o.Slow[0].Query != "GET <1 key>" || o.Cursor.SlowLogID != 1 {
			t.Errorf("%s first entry %+v %+v", e, o.Slow, o.Cursor)
		}
	}
}

func TestKVShape(t *testing.T) {
	for in, want := range map[string]string{
		"GET a":            "GET <1 key>",
		"set a b EX 10":    "SET <1 key>",
		"MSET a 1 b 2":     "MSET <2 keys>",
		"DEL a b c d":      "DEL <4 keys>",
		"PING":             "PING",
		"KEYS secret:*":    "KEYS",
		"HSET h f v f2 v2": "HSET <1 key>",
	} {
		if got := KVShape(strings.Fields(in)); got != want {
			t.Errorf("KVShape(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestObserveCLI(t *testing.T) {
	th := newTestHelper(t, Postgres)
	th.run.handle = func(Cmd) (string, error) { return pgObserved, nil }
	if code := Main(context.Background(), th.Helper, []string{"observe", "--cursor", "{not json"}); code != ExitUsage {
		t.Errorf("bad cursor: exit %d", code)
	}
	if code := Main(context.Background(), th.Helper, []string{"observe"}); code != 0 {
		t.Fatalf("observe: exit %d: %s", code, th.errOut.String())
	}
	o := observation(t, th)
	if strings.Join(o.Features, ",") != "observe,reload,query_insights" || o.Engine != Postgres {
		t.Errorf("%+v", o)
	}
	// Garbage instead of the figures is an error, not zeros.
	th.run.handle = func(Cmd) (string, error) { return "ERROR: nope", nil }
	if code := Main(context.Background(), th.Helper, []string{"observe"}); code == 0 {
		t.Error("garbage accepted")
	}
}

func TestReload(t *testing.T) {
	for _, e := range []Engine{Postgres, MySQL, MariaDB} {
		th := newTestHelper(t, e)
		th.env["FALAK_DB_MEMORY_BYTES"] = "536870912"
		th.env["FALAK_DB_SETTINGS"] = `{"slow_query_ms": 250, "tls": false}`
		th.env["PGDATA"] = "/var/lib/postgresql/data"
		if err := th.Reload(context.Background()); err != nil {
			t.Fatal(e, err)
		}
		var sql string
		if e == Postgres {
			sql = th.run.stdins["psql"]
			if !strings.Contains(sql, "pg_reload_conf()") {
				t.Errorf("postgres reload %q", sql)
			}
			conf, _ := os.ReadFile(filepath.Join(th.Root, pgConfPath))
			if !strings.Contains(string(conf), "log_min_duration_statement = 250") {
				t.Errorf("config not rendered again:\n%s", conf)
			}
		} else {
			sql = th.run.stdins[e.client("mysql")]
			if !strings.Contains(sql, "SET GLOBAL long_query_time = 0.250") || !strings.Contains(sql, "SET GLOBAL slow_query_log = ON") {
				t.Errorf("%s reload %q", e, sql)
			}
		}
		if !strings.Contains(th.out.String(), `"reloaded":true`) {
			t.Errorf("%s result %q", e, th.out.String())
		}
	}
	th := newTestHelper(t, Redis)
	if code := Main(context.Background(), th.Helper, []string{"reload"}); code != ExitUnsupported {
		t.Errorf("redis reload: exit %d", code)
	}
}

func TestRenderQueryInsights(t *testing.T) {
	for _, e := range []Engine{Postgres, MariaDB, MySQL} {
		in := RenderInput{Engine: e, MemoryBytes: 1 << 30, DataDir: "/var/lib/postgresql/data", Settings: Settings{TLS: new(bool)}}
		plain, _, err := Render(in)
		if err != nil {
			t.Fatal(err)
		}
		in.QueryInsights = true
		on, _, err := Render(in)
		if err != nil {
			t.Fatal(err)
		}
		switch e {
		case Postgres:
			if !strings.Contains(on[0].Content, "shared_preload_libraries = 'pg_stat_statements'") || !strings.Contains(on[0].Content, "pg_stat_statements.track_utility = off") ||
				strings.Contains(plain[0].Content, "pg_stat_statements") {
				t.Errorf("postgres:\n%s", on[0].Content)
			}
		case MariaDB:
			if !strings.Contains(on[0].Content, "performance_schema = ON") || strings.Contains(plain[0].Content, "performance_schema") {
				t.Errorf("mariadb:\n%s", on[0].Content)
			}
		case MySQL:
			// MySQL decides by memory (off below 1 GiB) either way.
			if on[0].Content != plain[0].Content {
				t.Errorf("mysql changed with query insights")
			}
		}
	}
	// MariaDB below 1 GiB: performance_schema would take a large share of the memory (OOM): never on.
	small, _, err := Render(RenderInput{Engine: MariaDB, MemoryBytes: 512 << 20, Settings: Settings{TLS: new(bool)}, QueryInsights: true})
	if err != nil || strings.Contains(small[0].Content, "performance_schema") {
		t.Errorf("mariadb 512 MiB with query insights: %v\n%s", err, small[0].Content)
	}
}

func TestRenderPostgresKeepsValuesOutOfTheLog(t *testing.T) {
	files, _, err := Render(RenderInput{Engine: Postgres, MemoryBytes: 512 << 20, DataDir: "/var/lib/postgresql/data", Settings: Settings{TLS: new(bool)}})
	if err != nil {
		t.Fatal(err)
	}
	for _, l := range []string{"log_parameter_max_length = 0", "log_parameter_max_length_on_error = 0", "log_min_error_statement = panic"} {
		if !strings.Contains(files[0].Content, l+"\n") {
			t.Errorf("missing %q", l)
		}
	}
}

func TestObserveTimeoutsAndOwnStatements(t *testing.T) {
	th := newTestHelper(t, Postgres)
	th.run.handle = func(c Cmd) (string, error) {
		if strings.Contains(th.run.stdins["psql"], "pg_stat_statements s") {
			return "[]", nil
		}
		return pgObserved, nil
	}
	if err := th.Observe(context.Background(), true, ""); err != nil {
		t.Fatal(err)
	}
	for _, c := range th.run.calls {
		env := strings.Join(c.Env, " ")
		if !strings.Contains(env, "statement_timeout=15s") || !strings.Contains(env, "lock_timeout=1s") || !strings.Contains(env, "client_connection_check_interval") {
			t.Errorf("psql without timeouts: %v", c.Env)
		}
	}
	if !strings.Contains(pgStatementsSQL, "NOT LIKE '/* falak-db observe */ %'") || strings.Contains(pgStatementsSQL, "current_user") {
		t.Errorf("statements filter: %s", pgStatementsSQL)
	}
	if !strings.HasPrefix(pgObserveSQL, observeMarker) || !strings.HasPrefix(pgTablesSQL, observeMarker) {
		t.Error("observe statements not marked")
	}
}

func TestCgroupMemoryLeavesPageCacheOut(t *testing.T) {
	th := newTestHelper(t, Redis)
	os.MkdirAll(filepath.Join(th.Root, "sys/fs/cgroup"), 0o755)
	os.WriteFile(filepath.Join(th.Root, "sys/fs/cgroup/memory.current"), []byte("900\n"), 0o644)
	os.WriteFile(filepath.Join(th.Root, "sys/fs/cgroup/memory.stat"), []byte("anon 300\nfile 600\nactive_file 100\ninactive_file 500\n"), 0o644)
	if m := th.cgroupMemory(); m == nil || m.UsedBytes != 400 {
		t.Errorf("used %+v", m)
	}
}

func TestMySQLUnusedIndexesSkipForeignKeys(t *testing.T) {
	if !strings.Contains(myUnusedIndexesSQL, "REFERENCED_TABLE_NAME IS NOT NULL") {
		t.Error("indexes backing a foreign key are not left out")
	}
}

func TestParseSlowLogSetHeader(t *testing.T) {
	entries, _ := ParseSlowLog([]byte("# Time: 2026-10-14T12:00:01Z\n# Query_time: 2.0\nuse shop;\nSET last_insert_id=7,insert_id=8,timestamp=1760443201;\nINSERT INTO t VALUES (1);\n"))
	if len(entries) != 1 || entries[0].Query != "INSERT INTO t VALUES (?)" || entries[0].At.Unix() != 1760443201 {
		t.Errorf("%+v", entries)
	}
}

func TestQueryInsightsFile(t *testing.T) {
	th := newTestHelper(t, Postgres)
	dir := t.TempDir()
	th.env["FALAK_DB_SETTINGS_FILE"] = filepath.Join(dir, "settings.json")
	if th.queryInsights() {
		t.Error("on without the file")
	}
	os.WriteFile(filepath.Join(dir, queryInsightsFile), []byte(`{"enabled": true}`), 0o644)
	if !th.queryInsights() {
		t.Error("off with the file")
	}
	os.WriteFile(filepath.Join(dir, queryInsightsFile), []byte(`{"enabled": false}`), 0o644)
	if th.queryInsights() {
		t.Error("on with enabled false")
	}
}

// A replica caught up with an idle primary is not lagging, however old the last replayed transaction is.
func TestPostgresLagOnIdlePrimary(t *testing.T) {
	if !strings.Contains(pgObserveSQL, "pg_last_wal_receive_lsn() = pg_last_wal_replay_lsn() THEN 0") {
		t.Error("lag measured from the last transaction even when everything received is replayed")
	}
}
