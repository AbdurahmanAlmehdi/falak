package dbhelper

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"strings"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/version"
)

const usageText = `usage: falak-db <command> [flags]

Runs inside a Falak database image. The engine comes from FALAK_DB_ENGINE (or --engine).

commands:
  init [-- server args]                 entrypoint: render the config, then start the server via the official entrypoint
  config render [--memory-bytes N] [--settings JSON]
                                        write the engine config tuned to the memory limit
  health                                liveness probe (the image's HEALTHCHECK)
  stats                                 open client connections and the limit: {"connections": n, "max_connections": m}
  observe [--detail] [--cursor JSON]    engine metrics, slow queries and (--detail) top statements, sizes, unused indexes
  reload                                render the config again and apply the slow query threshold without a restart
                                        (postgres, mysql, mariadb)
  backup logical --database DB --out -  dump to stdout (postgres, mysql, mariadb; redis/valkey: RDB, no --database)
  backup physical --out -               base backup to stdout (postgres tar, mysql/mariadb xbstream)
  restore logical [--database DB] --in - [--clean | --swap] [--owner ROLE]
                                        postgres --swap: into a scratch database, renamed in on success; --owner: hand
                                        every object to ROLE afterwards
  restore physical --in -               unpack (and prepare) a base backup into the empty data directory
  recover [--target-time T] (--wal-dir DIR [--action promote|pause|shutdown] | --binlog-dir DIR)
  promote                               postgres: end a paused recovery, open for writes
  wal-push PATH                         postgres archive_command: spool a WAL segment
  wal-fetch NAME DEST --from DIR        postgres restore_command
  binlog-rotate [--no-flush] [--restart] mysql/mariadb: flush binary logs, spool the closed ones (exit 4 on gaps)
  database create --name N [--charset C] [--collation X] [--owner O]
  database drop --name N                postgres, mysql, mariadb
  user apply --spec FILE                converge a user, its password and grants (JSON spec file)
  password set --file FILE [--keep-current]
                                        the superuser's / root's / default user's new password (redis/valkey
                                        --keep-current: the current one stays valid too)
  readonly on|off                       postgres, mysql, mariadb: read-only mode (ends postgres sessions)
  table-counts --database DB            exact row count per table (redis/valkey: keys per logical database)
  query --database DB                   run the check query on stdin (one SELECT) read-only: {"rows": n}
  reassign --database DB --owner ROLE   postgres: hand the database and every object in it to ROLE
  version

Results are one JSON line on stdout; for commands streaming data on stdout, the result is the stderr line starting
with "falak-db-result: ". Exit codes: 0 ok, 1 failed, 2 usage, 3 unsupported for the engine, 4 conflict, 5 not found.
`

// Main runs one falak-db command and returns the exit code.
func Main(ctx context.Context, h *Helper, args []string) int {
	err := dispatch(ctx, h, args)
	if errors.Is(err, flag.ErrHelp) {
		return ExitUsage
	}
	if err != nil {
		fmt.Fprintf(h.Stderr, "falak-db: %v\n", err)
	}
	return ExitCode(err)
}

// parse parses flags that may come before, between or after positional arguments.
func parse(fs *flag.FlagSet, args []string) ([]string, error) {
	var pos []string
	for {
		if err := fs.Parse(args); err != nil {
			if errors.Is(err, flag.ErrHelp) {
				return nil, err
			}
			return nil, usageErr("%v", err)
		}
		if fs.NArg() == 0 {
			return pos, nil
		}
		pos = append(pos, fs.Arg(0))
		args = fs.Args()[1:]
	}
}

func dispatch(ctx context.Context, h *Helper, args []string) error {
	if len(args) == 0 {
		fmt.Fprint(h.Stderr, usageText)
		return usageErr("no command")
	}
	cmd, args := args[0], args[1:]
	switch cmd {
	case "help", "-h", "--help":
		fmt.Fprint(h.Stdout, usageText)
		return nil
	case "version", "--version":
		return h.result(map[string]any{"version": version.Version, "engine": h.Engine})
	case "config", "backup", "restore", "database", "user", "password":
		if len(args) == 0 {
			return usageErr("%s needs a subcommand", cmd)
		}
		cmd, args = cmd+" "+args[0], args[1:]
	}

	fs := flag.NewFlagSet("falak-db "+cmd, flag.ContinueOnError)
	fs.SetOutput(h.Stderr)
	engine := fs.String("engine", string(h.Engine), "engine (default: FALAK_DB_ENGINE)")
	var (
		memory   = new(int64)
		settings = new(string)
		database = new(string)
		out, in  = new(string), new(string)
		clean    = new(bool)
		ro       RecoverOptions
		from     = new(string)
		noFlush  = new(bool)
		restart  = new(bool)
		dbo      DatabaseOptions
		file     = new(string)
		owner    = new(string)
		swap     = new(bool)
		keep     = new(bool)
		detail   = new(bool)
		cursor   = new(string)
	)
	var extra []string
	switch cmd {
	case "init":
		// Everything after "--" goes to the server.
		for i, a := range args {
			if a == "--" {
				extra, args = args[i+1:], args[:i]
				break
			}
		}
	case "config render":
		fs.Int64Var(memory, "memory-bytes", 0, "memory limit in bytes (default: detected)")
		fs.StringVar(settings, "settings", h.settingsJSON(), "settings JSON (default: FALAK_DB_SETTINGS_FILE, else FALAK_DB_SETTINGS)")
	case "backup logical":
		fs.StringVar(database, "database", "", "database to dump")
		fs.StringVar(out, "out", "", "output (-: stdout)")
	case "backup physical":
		fs.StringVar(out, "out", "", "output (-: stdout)")
	case "restore logical":
		fs.StringVar(database, "database", "", "target database (must exist)")
		fs.StringVar(in, "in", "", "input (-: stdin)")
		fs.BoolVar(clean, "clean", false, "postgres: drop objects before recreating them")
		fs.BoolVar(swap, "swap", false, "postgres: restore into a scratch database and rename it into place on success")
		fs.StringVar(owner, "owner", "", "postgres: hand the database and its objects to this role afterwards")
	case "restore physical":
		fs.StringVar(in, "in", "", "input (-: stdin)")
	case "recover":
		fs.StringVar(&ro.TargetTime, "target-time", "", "recover up to this time (RFC 3339); empty: everything available")
		fs.StringVar(&ro.WALDir, "wal-dir", "", "postgres: directory holding the WAL segments to replay")
		fs.StringVar(&ro.Action, "action", "", "postgres: at the target, promote (default), pause (read-only; holds in-flight locks) or shutdown")
		fs.StringVar(&ro.BinlogDir, "binlog-dir", "", "mysql/mariadb: directory holding the binlogs to replay")
	case "wal-fetch":
		fs.StringVar(from, "from", "", "directory holding archived WAL")
	case "binlog-rotate":
		fs.BoolVar(noFlush, "no-flush", false, "only spool binlogs that are already closed")
		fs.BoolVar(restart, "restart", false, "after a reset (exit 4, gap kind \"reset\") and a new base backup: forget the last spooled name")
	case "database create":
		fs.StringVar(&dbo.Name, "name", "", "database name")
		fs.StringVar(&dbo.Charset, "charset", "", "mysql/mariadb: character set (default utf8mb4)")
		fs.StringVar(&dbo.Collation, "collation", "", "mysql/mariadb: collation")
		fs.StringVar(&dbo.Owner, "owner", "", "postgres: owner role")
	case "database drop":
		fs.StringVar(&dbo.Name, "name", "", "database name")
	case "user apply":
		fs.StringVar(file, "spec", "", "JSON user spec (holds the password: a file in the secrets directory)")
	case "password set":
		fs.StringVar(file, "file", "", "file holding the new password")
		fs.BoolVar(keep, "keep-current", false, "redis/valkey: keep the current password valid too")
	case "table-counts":
		fs.StringVar(database, "database", "", "database (redis/valkey: ignored)")
	case "query":
		fs.StringVar(database, "database", "", "database")
	case "reassign":
		fs.StringVar(database, "database", "", "database")
		fs.StringVar(owner, "owner", "", "the new owner role")
	case "observe":
		fs.BoolVar(detail, "detail", false, "add statements, sizes, tables and unused indexes")
		fs.StringVar(cursor, "cursor", "", "the previous call's cursor (JSON)")
	case "readonly":
	case "health", "promote", "wal-push", "stats", "reload":
	default:
		fmt.Fprint(h.Stderr, usageText)
		return usageErr("unknown command %q", cmd)
	}
	pos, err := parse(fs, args)
	if err != nil {
		return err
	}
	if *engine == "" {
		return usageErr("no engine: set FALAK_DB_ENGINE or pass --engine")
	}
	if h.Engine, err = ParseEngine(*engine); err != nil {
		return err
	}
	wantArgs := map[string]int{"wal-push": 1, "wal-fetch": 2, "readonly": 1}[cmd]
	if len(pos) != wantArgs {
		return usageErr("%s takes %d argument(s), got %d (%s)", cmd, wantArgs, len(pos), strings.Join(pos, " "))
	}

	switch cmd {
	case "init":
		return h.Init(extra)
	case "config render":
		return h.ConfigRender(*memory, *settings)
	case "health":
		ctx, cancel := context.WithTimeout(ctx, 20*time.Second)
		defer cancel()
		return h.Health(ctx)
	case "stats":
		ctx, cancel := context.WithTimeout(ctx, 10*time.Second)
		defer cancel()
		return h.Stats(ctx)
	case "observe":
		ctx, cancel := context.WithTimeout(ctx, 30*time.Second)
		defer cancel()
		return h.Observe(ctx, *detail, *cursor)
	case "reload":
		ctx, cancel := context.WithTimeout(ctx, 30*time.Second)
		defer cancel()
		return h.Reload(ctx)
	case "backup logical":
		return h.BackupLogical(ctx, *database, *out)
	case "backup physical":
		return h.BackupPhysical(ctx, *out)
	case "restore logical":
		switch {
		case *swap && *clean:
			return usageErr("--swap replaces the database: --clean is not needed")
		case *swap:
			return h.RestoreSwap(ctx, *database, *in, *owner)
		case *owner != "":
			return h.RestoreOwned(ctx, *database, *in, *owner)
		}
		return h.RestoreLogical(ctx, *database, *in, *clean)
	case "restore physical":
		return h.RestorePhysical(ctx, *in)
	case "recover":
		return h.Recover(ctx, ro)
	case "promote":
		return h.Promote(ctx)
	case "wal-push":
		return h.WALPush(pos[0])
	case "wal-fetch":
		return h.WALFetch(pos[0], pos[1], *from)
	case "binlog-rotate":
		return h.BinlogRotate(ctx, *noFlush, *restart)
	case "database create":
		return h.DatabaseCreate(ctx, dbo)
	case "database drop":
		return h.DatabaseDrop(ctx, dbo.Name)
	case "user apply":
		return h.UserApply(ctx, *file)
	case "password set":
		return h.PasswordSet(ctx, *file, *keep)
	case "readonly":
		if pos[0] != "on" && pos[0] != "off" {
			return usageErr("readonly takes on or off")
		}
		return h.ReadOnly(ctx, pos[0] == "on")
	case "table-counts":
		return h.TableCounts(ctx, *database)
	case "query":
		return h.Query(ctx, *database)
	case "reassign":
		return h.ReassignOwner(ctx, *database, *owner)
	}
	return nil
}
