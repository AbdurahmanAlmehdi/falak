package dbhelper

import (
	"bytes"
	"encoding/json"
	"fmt"
	"os"
	"strconv"
	"strings"
)

// Settings are the user-tunable knobs of an instance (FALAK_DB_SETTINGS, or --settings for `config render`). Zero
// values take the engine's defaults; unknown fields are rejected.
type Settings struct {
	MaxConnections       int      `json:"max_connections,omitempty"`        // postgres (100), mysql/mariadb (151)
	ServerID             int      `json:"server_id,omitempty"`              // mysql/mariadb, unique per instance (1)
	TLS                  *bool    `json:"tls,omitempty"`                    // serve TLS with the mounted certificate (true)
	RequireTLS           bool     `json:"require_tls,omitempty"`            // refuse plaintext TCP clients (false)
	SlowQueryMS          *int     `json:"slow_query_ms,omitempty"`          // 0 = off (1000; redis/valkey 10)
	BinlogRetentionHours int      `json:"binlog_retention_hours,omitempty"` // mysql/mariadb, binlogs kept on the volume (168)
	Eviction             string   `json:"eviction,omitempty"`               // redis/valkey maxmemory-policy (noeviction)
	Persistence          string   `json:"persistence,omitempty"`            // redis/valkey: rdb | aof | none (rdb)
	Bind                 []string `json:"bind,omitempty"`                   // redis/valkey bind addresses (* -::*)
	// ReadOnly (sql) refuses writes in the rendered config, so it holds across restarts: a point-in-time restore's copy
	// while it is inspected. PostgreSQL default_transaction_read_only; MySQL super_read_only + read_only; MariaDB
	// read_only (accounts with READ ONLY ADMIN, root, still write). falak-db's own sessions are not held back.
	ReadOnly bool `json:"read_only,omitempty"`
	// EventScheduler (mysql/mariadb) false turns the event scheduler off: no event writes while binlogs are replayed or
	// a copy is inspected.
	EventScheduler *bool `json:"event_scheduler,omitempty"`
}

// ParseSettings decodes settings JSON strictly. Empty input is the defaults.
func ParseSettings(b []byte) (Settings, error) {
	var s Settings
	if len(bytes.TrimSpace(b)) == 0 {
		return s, nil
	}
	dec := json.NewDecoder(bytes.NewReader(b))
	dec.DisallowUnknownFields()
	if err := dec.Decode(&s); err != nil {
		return s, usageErr("settings: %v", err)
	}
	if dec.More() {
		return s, usageErr("settings: trailing data")
	}
	return s, nil
}

var evictionPolicies = []string{
	"noeviction", "allkeys-lru", "allkeys-lfu", "allkeys-random",
	"volatile-lru", "volatile-lfu", "volatile-random", "volatile-ttl",
}

func (s Settings) tls() bool { return s.TLS == nil || *s.TLS }

func (s Settings) slowMS(e Engine) int {
	if s.SlowQueryMS != nil {
		return *s.SlowQueryMS
	}
	if e.kv() {
		return 10
	}
	return 1000
}

func (s Settings) validate(e Engine) error {
	if s.MaxConnections < 0 || s.MaxConnections > 10000 {
		return usageErr("settings: max_connections must be between 1 and 10000")
	}
	if s.ServerID < 0 || s.ServerID > 4294967295 {
		return usageErr("settings: server_id must be between 1 and 4294967295")
	}
	if s.SlowQueryMS != nil && (*s.SlowQueryMS < 0 || *s.SlowQueryMS > 3600000) {
		return usageErr("settings: slow_query_ms must be between 0 and 3600000")
	}
	if s.BinlogRetentionHours < 0 || s.BinlogRetentionHours > 24*90 {
		return usageErr("settings: binlog_retention_hours must be between 1 and 2160")
	}
	if s.RequireTLS && !s.tls() {
		return usageErr("settings: require_tls needs tls")
	}
	if s.Eviction != "" && !contains(evictionPolicies, s.Eviction) {
		return usageErr("settings: unknown eviction policy %q", s.Eviction)
	}
	if s.Persistence != "" && !contains([]string{"rdb", "aof", "none"}, s.Persistence) {
		return usageErr("settings: persistence must be rdb, aof or none")
	}
	for _, b := range s.Bind {
		if b == "" || strings.ContainsAny(b, " \t\r\n\"'\\") {
			return usageErr("settings: invalid bind address %q", b)
		}
	}
	if !e.kv() && (s.Eviction != "" || s.Persistence != "" || len(s.Bind) > 0) {
		return usageErr("settings: eviction, persistence and bind are for redis and valkey")
	}
	if !e.mysqlFamily() && (s.ServerID != 0 || s.BinlogRetentionHours != 0) {
		return usageErr("settings: server_id and binlog_retention_hours are for mysql and mariadb")
	}
	if e.kv() && s.ReadOnly {
		return usageErr("settings: read_only is for the SQL engines")
	}
	if !e.mysqlFamily() && s.EventScheduler != nil {
		return usageErr("settings: event_scheduler is for mysql and mariadb")
	}
	if e.kv() && s.MaxConnections != 0 {
		return usageErr("settings: max_connections is not used by %s", e)
	}
	return nil
}

func contains(list []string, s string) bool {
	for _, v := range list {
		if v == s {
			return true
		}
	}
	return false
}

// RenderInput is everything a config depends on. Render is a pure function of it.
type RenderInput struct {
	Engine      Engine
	MemoryBytes int64
	Settings    Settings
	DataDir     string // postgres: PGDATA (the recovery include lives there); redis/valkey: dir (default /data)
	TLSCA       bool   // a CA certificate is mounted next to the server certificate
	// PasswordSHA256 is the hex SHA-256 of the redis/valkey password, for the ACL file (the plaintext never lands in
	// a config file).
	PasswordSHA256 string
	// PreviousPasswordSHA256 stays valid next to it while a rotation overlaps (FALAK_DB_PREVIOUS_PASSWORD_FILE).
	PreviousPasswordSHA256 string
	// QueryInsights loads what top queries need at start: PostgreSQL pg_stat_statements, MariaDB performance_schema
	// (query-insights.json next to the settings file, or FALAK_DB_QUERY_INSIGHTS). Not a setting: an older helper
	// rejects unknown settings, and never reads the file.
	QueryInsights bool
}

// File is one rendered file.
type File struct {
	Path    string      `json:"path"`
	Mode    os.FileMode `json:"-"`
	Content string      `json:"-"`
}

// Tuning is the memory-derived values, reported by `config render` so the agent can show them.
type Tuning map[string]string

const minMemory = 64 << 20

func mib(n int64) int64 { return n >> 20 }

// Render produces the engine's config files.
func Render(in RenderInput) ([]File, Tuning, error) {
	if in.MemoryBytes < minMemory {
		return nil, nil, usageErr("memory must be at least 64 MiB (got %d bytes)", in.MemoryBytes)
	}
	if err := in.Settings.validate(in.Engine); err != nil {
		return nil, nil, err
	}
	switch in.Engine {
	case Postgres:
		if in.DataDir == "" {
			return nil, nil, usageErr("postgres needs the data directory")
		}
		return renderPostgres(in)
	case MySQL, MariaDB:
		return renderMySQL(in)
	case Redis, Valkey:
		if len(in.PasswordSHA256) != 64 {
			return nil, nil, usageErr("%s needs the password hash", in.Engine)
		}
		return renderKV(in)
	}
	return nil, nil, usageErr("unknown engine %q", in.Engine)
}

func clamp(v, lo, hi int64) int64 { return max(lo, min(v, hi)) }

type confWriter struct{ strings.Builder }

func (w *confWriter) line(format string, a ...any) { fmt.Fprintf(&w.Builder, format+"\n", a...) }

const header = "# Rendered by falak-db at every container start: do not edit, changes are lost.\n"

// pgQuote quotes a postgresql.conf string value.
func pgQuote(s string) string { return "'" + strings.ReplaceAll(s, "'", "''") + "'" }

func renderPostgres(in RenderInput) ([]File, Tuning, error) {
	mem := mib(in.MemoryBytes)
	conns := int64(in.Settings.MaxConnections)
	if conns == 0 {
		conns = 100
	}
	shared := mem / 4
	cache := mem * 3 / 4
	// work_mem is per sort/hash node, so a few per connection may be live at once.
	work := clamp((mem-shared)/(conns*4), 4, 256)
	maint := clamp(mem/16, 16, 2048)

	w := &confWriter{}
	w.WriteString(header)
	w.line("# Memory limit: %d MiB", mem)
	w.line("")
	w.line("listen_addresses = '*'")
	w.line("port = %d", pgPort)
	w.line("max_connections = %d", conns)
	w.line("unix_socket_directories = %s", pgQuote(pgSocketDir))
	w.line("hba_file = %s", pgQuote(pgHBAPath))
	w.line("password_encryption = 'scram-sha-256'")
	w.line("")
	w.line("shared_buffers = %dMB", shared)
	w.line("effective_cache_size = %dMB", cache)
	w.line("work_mem = %dMB", work)
	w.line("maintenance_work_mem = %dMB", maint)
	w.line("dynamic_shared_memory_type = posix")
	w.line("")
	w.line("# Point-in-time recovery: every WAL segment is spooled; the agent ships the spool.")
	w.line("wal_level = replica")
	w.line("archive_mode = on")
	w.line("archive_command = 'falak-db wal-push %%p'")
	w.line("archive_timeout = 60")
	w.line("max_wal_senders = 10")
	w.line("")
	if in.Settings.ReadOnly {
		w.line("# Read-only (a point-in-time restore's copy being inspected).")
		w.line("default_transaction_read_only = on")
		w.line("")
	}
	if in.Settings.tls() {
		w.line("ssl = on")
		w.line("ssl_cert_file = %s", pgQuote(tlsDir+"/server.crt"))
		w.line("ssl_key_file = %s", pgQuote(tlsDir+"/server.key"))
		if in.TLSCA {
			w.line("ssl_ca_file = %s", pgQuote(tlsDir+"/ca.crt"))
		}
		w.line("ssl_min_protocol_version = 'TLSv1.2'")
	} else {
		w.line("ssl = off")
	}
	w.line("")
	if in.QueryInsights {
		w.line("# Query insights: per-statement figures (normalized text), without utility statements (they may hold passwords).")
		w.line("shared_preload_libraries = 'pg_stat_statements'")
		w.line("pg_stat_statements.max = 1000")
		w.line("pg_stat_statements.track = top")
		w.line("pg_stat_statements.track_utility = off")
		w.line("")
	}
	w.line("log_destination = 'stderr'")
	w.line("logging_collector = off")
	if ms := in.Settings.slowMS(Postgres); ms > 0 {
		w.line("log_min_duration_statement = %d", ms)
	} else {
		w.line("log_min_duration_statement = -1")
	}
	w.line("log_line_prefix = '%%m [%%p] %%q%%u@%%d '")
	w.line("# No query text or bind values in the log beyond the slow statements (normalized by the agent).")
	w.line("log_parameter_max_length = 0")
	w.line("log_parameter_max_length_on_error = 0")
	w.line("log_min_error_statement = panic")
	w.line("log_timezone = 'UTC'")
	w.line("timezone = 'UTC'")
	w.line("datestyle = 'iso, mdy'")
	w.line("default_text_search_config = 'pg_catalog.english'")
	w.line("")
	w.line("# Written by `falak-db recover` into a restored data directory.")
	w.line("include_if_exists = %s", pgQuote(strings.TrimRight(in.DataDir, "/")+"/"+recoveryConf))

	h := &confWriter{}
	h.WriteString(header)
	h.line("# Inside the container: trusted (the socket is not shared). Over TCP: SCRAM passwords.")
	h.line("local   all          all                 trust")
	h.line("local   replication  all                 trust")
	h.line("host    all          all  127.0.0.1/32   scram-sha-256")
	h.line("host    all          all  ::1/128        scram-sha-256")
	switch {
	case in.Settings.RequireTLS:
		h.line("hostssl all          all  all            scram-sha-256")
	default:
		h.line("host    all          all  all            scram-sha-256")
	}

	files := []File{
		{Path: pgConfPath, Mode: 0o644, Content: w.String()},
		{Path: pgHBAPath, Mode: 0o644, Content: h.String()},
	}
	return files, Tuning{
		"memory_mb":            strconv.FormatInt(mem, 10),
		"shared_buffers":       fmt.Sprintf("%dMB", shared),
		"effective_cache_size": fmt.Sprintf("%dMB", cache),
		"work_mem":             fmt.Sprintf("%dMB", work),
		"maintenance_work_mem": fmt.Sprintf("%dMB", maint),
		"max_connections":      strconv.FormatInt(conns, 10),
	}, nil
}

// innodbPool sizes the InnoDB buffer pool at ~55% of the limit. MySQL rounds the pool up to a multiple of
// chunk_size × instances (128 MiB by default), which would push a small pool far past its share, so below 1 GiB
// the chunk is the whole pool, and above it the pool is rounded down to whole chunks.
func innodbPool(mem int64) (pool, instances, chunk int64) {
	pool = max(mem*55/100, 32)
	if pool <= 1024 {
		return pool, 1, pool
	}
	instances = min(8, pool/1024)
	chunk = 128
	pool -= pool % (chunk * instances)
	return pool, instances, chunk
}

func renderMySQL(in RenderInput) ([]File, Tuning, error) {
	e := in.Engine
	mem := mib(in.MemoryBytes)
	conns := int64(in.Settings.MaxConnections)
	if conns == 0 {
		conns = 151
	}
	serverID := in.Settings.ServerID
	if serverID == 0 {
		serverID = 1
	}
	retention := in.Settings.BinlogRetentionHours
	if retention == 0 {
		retention = 168
	}
	pool, instances, chunk := innodbPool(mem)
	if e == MariaDB {
		pool = max(mem*55/100, 32) // MariaDB sizes its chunks itself
	}

	w := &confWriter{}
	w.WriteString(header)
	w.line("# Memory limit: %d MiB", mem)
	w.line("")
	w.line("[mysqld]")
	w.line("port = %d", myPort)
	w.line("socket = %s", mySocket)
	w.line("max_connections = %d", conns)
	w.line("skip_name_resolve = ON")
	w.line("character_set_server = utf8mb4")
	w.line("")
	w.line("innodb_buffer_pool_size = %dM", pool)
	if e == MySQL {
		// MariaDB sizes chunks itself (and dropped the instances setting).
		w.line("innodb_buffer_pool_instances = %d", instances)
		w.line("innodb_buffer_pool_chunk_size = %dM", chunk)
		if mem < 1024 {
			w.line("performance_schema = OFF")
		}
	} else if in.QueryInsights && mem >= 1024 {
		// performance_schema takes a few hundred MiB: below 1 GiB, as MySQL, top queries come from the slow log.
		w.line("# Query insights: statement digests.")
		w.line("performance_schema = ON")
	}
	w.line("")
	w.line("# Point-in-time recovery: binlogs are rotated and spooled every 60 s; the agent ships the spool.")
	w.line("server_id = %d", serverID)
	w.line("log_bin = binlog")
	w.line("binlog_format = ROW")
	w.line("binlog_row_image = FULL")
	w.line("sync_binlog = 1")
	w.line("innodb_flush_log_at_trx_commit = 1")
	w.line("binlog_expire_logs_seconds = %d", retention*3600)
	if e == MySQL {
		w.line("gtid_mode = ON")
		w.line("enforce_gtid_consistency = ON")
	} else {
		w.line("gtid_strict_mode = ON")
	}
	w.line("")
	if in.Settings.ReadOnly || (in.Settings.EventScheduler != nil && !*in.Settings.EventScheduler) {
		w.line("# A point-in-time restore's copy: read-only while it is inspected, no scheduled events.")
		if in.Settings.EventScheduler != nil && !*in.Settings.EventScheduler {
			w.line("event_scheduler = OFF")
		}
		if in.Settings.ReadOnly {
			w.line("read_only = ON")
			if e == MySQL {
				w.line("super_read_only = ON")
			}
		}
		w.line("")
	}
	if in.Settings.tls() {
		w.line("ssl_cert = %s", tlsDir+"/server.crt")
		w.line("ssl_key = %s", tlsDir+"/server.key")
		if in.TLSCA {
			w.line("ssl_ca = %s", tlsDir+"/ca.crt")
		}
		w.line("tls_version = TLSv1.2,TLSv1.3")
		if in.Settings.RequireTLS {
			w.line("require_secure_transport = ON")
		}
	} else if e == MySQL {
		w.line("tls_version = ''")
	} else {
		w.line("skip_ssl")
	}
	w.line("")
	if ms := in.Settings.slowMS(e); ms > 0 {
		w.line("slow_query_log = ON")
		w.line("slow_query_log_file = %s", mySlowLog)
		w.line("long_query_time = %s", strconv.FormatFloat(float64(ms)/1000, 'f', 3, 64))
	} else {
		w.line("slow_query_log = OFF")
	}

	return []File{{Path: myConfPath, Mode: 0o644, Content: w.String()}}, Tuning{
		"memory_mb":               strconv.FormatInt(mem, 10),
		"innodb_buffer_pool_size": fmt.Sprintf("%dM", pool),
		"max_connections":         strconv.FormatInt(conns, 10),
		"server_id":               strconv.Itoa(serverID),
	}, nil
}

func renderKV(in RenderInput) ([]File, Tuning, error) {
	s := in.Settings
	maxmem := in.MemoryBytes * 80 / 100
	eviction := s.Eviction
	if eviction == "" {
		eviction = "noeviction"
	}
	persistence := s.Persistence
	if persistence == "" {
		persistence = "rdb"
	}
	bind := s.Bind
	if len(bind) == 0 {
		bind = []string{"*", "-::*"}
	}

	w := &confWriter{}
	w.WriteString(header)
	w.line("# Memory limit: %d MiB", mib(in.MemoryBytes))
	w.line("")
	w.line("bind %s", strings.Join(bind, " "))
	w.line("protected-mode no")
	// Redis can't offer TLS and plaintext on one port: TLS only when clients must use it (require_tls, e.g. public
	// access, where apps get rediss:// URLs), else plaintext on the environment's private network like the SQL
	// engines' optional TLS.
	if s.tls() && in.Settings.RequireTLS {
		w.line("port 0")
		w.line("tls-port %d", kvPort)
		w.line("tls-cert-file %s", tlsDir+"/server.crt")
		w.line("tls-key-file %s", tlsDir+"/server.key")
		if in.TLSCA {
			w.line("tls-ca-cert-file %s", tlsDir+"/ca.crt")
		}
		w.line("tls-auth-clients no")
		w.line("tls-protocols \"TLSv1.2 TLSv1.3\"")
	} else {
		w.line("port %d", kvPort)
	}
	w.line("# falak-db talks to the server over this socket only.")
	w.line("unixsocket %s", kvSocket)
	w.line("unixsocketperm 700")
	w.line("aclfile %s", kvACLPath)
	w.line("")
	w.line("maxmemory %d", maxmem)
	w.line("maxmemory-policy %s", eviction)
	w.line("")
	dir := in.DataDir
	if dir == "" {
		dir = "/data"
	}
	if strings.ContainsAny(dir, " \t\r\n\"'\\") {
		return nil, nil, usageErr("data directory %q has unsafe characters", dir)
	}
	w.line("dir %s", dir)
	w.line("dbfilename dump.rdb")
	switch persistence {
	case "rdb":
		w.line("save 3600 1 300 100 60 10000")
		w.line("appendonly no")
	case "aof":
		w.line("save \"\"")
		w.line("appendonly yes")
		w.line("appendfsync everysec")
	default:
		w.line("save \"\"")
		w.line("appendonly no")
	}
	w.line("")
	w.line("loglevel notice")
	w.line("logfile \"\"")
	if ms := s.slowMS(in.Engine); ms > 0 {
		w.line("slowlog-log-slower-than %d", ms*1000)
	} else {
		w.line("slowlog-log-slower-than -1")
	}

	// The default user, with the password as a SHA-256 hash. CONFIG, DEBUG and MODULE stay with Falak: settings change
	// by re-rendering at start. Redis 7 and Valkey reject comments in ACL files.
	hashes := "#" + in.PasswordSHA256
	if in.PreviousPasswordSHA256 != "" && in.PreviousPasswordSHA256 != in.PasswordSHA256 {
		hashes = "#" + in.PreviousPasswordSHA256 + " " + hashes
	}
	acl := "user default on " + hashes + " ~* &* +@all -config -debug -module\n"

	return []File{
			{Path: kvConfPath, Mode: 0o640, Content: w.String()},
			{Path: kvACLPath, Mode: 0o600, Content: acl},
		}, Tuning{
			"memory_mb":        strconv.FormatInt(mib(in.MemoryBytes), 10),
			"maxmemory":        strconv.FormatInt(maxmem, 10),
			"maxmemory_policy": eviction,
			"persistence":      persistence,
		}, nil
}
