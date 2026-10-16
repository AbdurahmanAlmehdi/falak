package cli

import (
	"context"
	"errors"
	"fmt"
	"strconv"
	"strings"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/cli/api"
)

// v0.21: snapshots, fork, pause/resume, database copy (docs/plans/SANDBOXES.md, "Contracts (v0.21)").

func snapshotCommands() []*command {
	return []*command{
		{name: "snapshot", args: "<sandbox> [--name N] [--stop] [--wait=false]", summary: "snapshot a sandbox: disk, memory and running processes", run: cmdSandboxSnapshot},
		{name: "snapshots", summary: "list or delete snapshots", subs: []*command{
			{name: "ls", args: "[--sandbox S]", summary: "list snapshots", run: cmdSnapshotList},
			{name: "rm", args: "<snapshot>…", summary: "delete snapshots (names or ids)", run: cmdSnapshotRm},
		}},
		{name: "fork", args: "<snapshot> [-n N] [--name N] [--ttl 1h] [--env K=V]… [--idle-pause 10m] [--wait=false]", summary: "create N sandboxes from a snapshot", run: cmdSandboxFork},
		{name: "pause", args: "<sandbox>…", summary: "pause sandboxes (no CPU; memory stays allocated)", run: cmdSandboxPause},
		{name: "resume", args: "<sandbox>…", summary: "resume paused sandboxes", run: cmdSandboxResume},
		{name: "db", args: "<sandbox> <instance>/<database>", summary: "copy a database of the sandbox's server into /workspace/db", run: cmdSandboxDB},
	}
}

// parseIdlePause reads --idle-pause: at least a minute.
func parseIdlePause(s string) (int64, error) {
	n, err := parseSeconds(s)
	if err != nil {
		return 0, usageError{err.Error()}
	}
	if n < 60 {
		return 0, usagef("--idle-pause must be at least 1m")
	}
	return n, nil
}

// parseDBRef splits <instance>/<database>.
func parseDBRef(s string) (*api.SandboxDB, error) {
	inst, db, ok := strings.Cut(s, "/")
	if !ok || inst == "" || db == "" {
		return nil, usagef("%q: use <instance>/<database>", s)
	}
	return &api.SandboxDB{Instance: inst, Database: db}, nil
}

func byteSize(n int64) string {
	switch {
	case n >= gib:
		return strconv.FormatFloat(float64(n)/gib, 'f', 1, 64) + "g"
	case n >= mib:
		return strconv.FormatFloat(float64(n)/mib, 'f', 1, 64) + "m"
	case n >= 1024:
		return strconv.FormatInt(n/1024, 10) + "k"
	}
	return strconv.FormatInt(n, 10) + "b"
}

func shortTime(s string) string {
	if t, err := time.Parse(time.RFC3339, s); err == nil {
		return t.Local().Format("2006-01-02 15:04")
	}
	return dash(s)
}

// v21Rows are the show rows added in v0.21 (only those that apply).
func v21Rows(s api.Sandbox) [][2]string {
	var rows [][2]string
	if s.FromSnapshot != nil {
		rows = append(rows, [2]string{"from snapshot", s.FromSnapshot.Name})
	}
	if s.IdlePauseSeconds != nil && *s.IdlePauseSeconds > 0 {
		rows = append(rows, [2]string{"idle pause", humanDuration(*s.IdlePauseSeconds)}, [2]string{"last activity", shortTime(s.LastActivityAt)})
	}
	return rows
}

func snapshotSource(s api.Snapshot) string {
	if s.Sandbox == nil {
		return "-"
	}
	return dash(s.Sandbox.Name)
}

func cmdSandboxSnapshot(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("sandbox snapshot")
	var r api.SnapshotRequest
	fs.StringVar(&r.Name, "name", "", "name it, to fork or create from it later (default: generated)")
	fs.BoolVar(&r.Stop, "stop", false, "stop and remove the sandbox after the snapshot")
	wait := fs.Bool("wait", true, "wait until the snapshot is ready (up to 60 s)")
	pos, err := a.parse(fs, args, 1, 1, "<sandbox> [--name N] [--stop] [--wait=false]")
	if err != nil {
		return err
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	id, err := resolveSandbox(ctx, c, pos[0])
	if err != nil {
		return err
	}
	s, err := c.SnapshotSandbox(ctx, id, r, *wait)
	if err != nil {
		return err
	}
	if a.jsonOut {
		return a.printJSON(s)
	}
	if s.Status == "failed" {
		return fmt.Errorf("snapshot %s failed", s.Name)
	}
	fmt.Fprintf(a.Stdout, "%s (%s): %s, %s on %s\n", s.Name, s.ID, s.Status, byteSize(s.SizeBytes), dash(snapshotServer(s)))
	fmt.Fprintf(a.Stdout, "  falak sandbox fork %s -n 3\n  falak sandbox create --from %s\n", s.Name, s.Name)
	return nil
}

func snapshotServer(s api.Snapshot) string {
	if s.Server == nil {
		return ""
	}
	return s.Server.Name
}

func cmdSnapshotList(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("sandbox snapshots ls")
	sandbox := fs.String("sandbox", "", "only snapshots of this sandbox (name or id)")
	if _, err := a.parse(fs, args, 0, 0, "[--sandbox S]"); err != nil {
		return err
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	src := ""
	if *sandbox != "" {
		if src, err = resolveSandbox(ctx, c, *sandbox); err != nil {
			return err
		}
	}
	list, err := c.Snapshots(ctx, src)
	if err != nil {
		return err
	}
	if a.jsonOut {
		return a.printJSON(list)
	}
	rows := make([][]string, 0, len(list))
	for _, s := range list {
		rows = append(rows, []string{s.Name, s.Status, snapshotSource(s), dash(snapshotServer(s)), s.Image, byteSize(s.SizeBytes), shortTime(s.CreatedAt)})
	}
	a.table([]string{"NAME", "STATUS", "SOURCE", "SERVER", "IMAGE", "SIZE", "CREATED"}, rows)
	return nil
}

func cmdSnapshotRm(ctx context.Context, a *App, args []string) error {
	pos, err := a.parse(a.flagSet("sandbox snapshots rm"), args, 1, -1, "<snapshot>…")
	if err != nil {
		return err
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	var errs []error
	for _, ref := range pos { // the API takes an id or a name
		if err := c.DeleteSnapshot(ctx, ref); err != nil {
			errs = append(errs, fmt.Errorf("%s: %w", ref, err))
			continue
		}
		fmt.Fprintf(a.Stdout, "deleted %s\n", ref)
	}
	return errors.Join(errs...)
}

func cmdSandboxFork(ctx context.Context, a *App, args []string) error {
	const synopsis = "<snapshot> [-n N] [--name N] [--ttl 1h] [--env K=V]… [--idle-pause 10m] [--wait=false]"
	fs := a.flagSet("sandbox fork")
	var r api.ForkRequest
	fs.IntVar(&r.Count, "n", 1, "how many sandboxes (1-10)")
	fs.StringVar(&r.Name, "name", "", "name, or the prefix of name-1…name-N when -n is above 1")
	ttl := fs.String("ttl", "", "time limit, e.g. 30m or 2h (default 1h)")
	idle := fs.String("idle-pause", "", "pause after this long unused, e.g. 10m")
	var env headerFlags
	fs.Var(&env, "env", "environment variable K=V added to the snapshot's (repeatable)")
	wait := fs.Bool("wait", true, "wait until they run (up to 60 s)")
	pos, err := a.parse(fs, args, 1, 1, synopsis)
	if err != nil {
		return err
	}
	if r.Count < 1 || r.Count > 10 {
		return usagef("-n must be 1 to 10")
	}
	if *ttl != "" {
		if r.TTL, err = parseSeconds(*ttl); err != nil {
			return usageError{err.Error()}
		}
	}
	if *idle != "" {
		if r.IdlePause, err = parseIdlePause(*idle); err != nil {
			return err
		}
	}
	if r.Env, err = parseEnv(env); err != nil {
		return err
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	list, err := c.ForkSnapshot(ctx, pos[0], r, *wait)
	if err != nil {
		return err
	}
	if a.jsonOut {
		return a.printJSON(list)
	}
	rows := make([][]string, 0, len(list))
	var failed []string
	for _, s := range list {
		if s.Status == "failed" {
			failed = append(failed, s.Name)
		}
		rows = append(rows, []string{s.Name, s.ID, s.Status, serverName(s), expiresIn(s)})
	}
	a.table([]string{"NAME", "ID", "STATUS", "SERVER", "EXPIRES IN"}, rows)
	if len(failed) > 0 {
		return fmt.Errorf("failed to start: %s", strings.Join(failed, ", "))
	}
	return nil
}

func cmdSandboxPause(ctx context.Context, a *App, args []string) error {
	return sandboxPauseResume(ctx, a, args, "pause")
}

func cmdSandboxResume(ctx context.Context, a *App, args []string) error {
	return sandboxPauseResume(ctx, a, args, "resume")
}

func sandboxPauseResume(ctx context.Context, a *App, args []string, verb string) error {
	pos, err := a.parse(a.flagSet("sandbox "+verb), args, 1, -1, "<sandbox>…")
	if err != nil {
		return err
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	var out []api.Sandbox
	var errs []error
	for _, ref := range pos {
		id, err := resolveSandbox(ctx, c, ref)
		var s api.Sandbox
		if err == nil {
			if verb == "pause" {
				s, err = c.PauseSandbox(ctx, id)
			} else {
				s, err = c.ResumeSandbox(ctx, id)
			}
		}
		if err != nil {
			errs = append(errs, fmt.Errorf("%s: %w", ref, err))
			continue
		}
		out = append(out, s)
		if !a.jsonOut {
			fmt.Fprintf(a.Stdout, "%s: %s\n", s.Name, s.Status)
		}
	}
	if a.jsonOut {
		if err := a.printJSON(out); err != nil {
			return err
		}
	}
	return errors.Join(errs...)
}

func cmdSandboxDB(ctx context.Context, a *App, args []string) error {
	pos, err := a.parse(a.flagSet("sandbox db"), args, 2, 2, "<sandbox> <instance>/<database>")
	if err != nil {
		return err
	}
	db, err := parseDBRef(pos[1])
	if err != nil {
		return err
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	id, err := resolveSandbox(ctx, c, pos[0])
	if err != nil {
		return err
	}
	res, err := c.SandboxImportDB(ctx, id, *db)
	if err != nil {
		return err
	}
	if a.jsonOut {
		return a.printJSON(res)
	}
	fmt.Fprintf(a.Stdout, "%s/%s copied to %s:%s (%s)\n", db.Instance, db.Database, pos[0], res.Path, byteSize(res.SizeBytes))
	return nil
}
