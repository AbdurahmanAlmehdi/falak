package cli

import (
	"bufio"
	"context"
	"errors"
	"fmt"
	"io"
	"net/http"
	"strings"

	"github.com/OthmanHaba/falak/agent/internal/cli/api"
)

// ExitDrift: `falak drift` found the environment drifted from its last applied file.
const ExitDrift = 7

func runsCommands() *command {
	return &command{name: "runs", summary: "config runs (falak.yaml applies)", subs: []*command{
		{name: "list", args: "[-p PROJECT] [-e ENV] [--limit N]", summary: "list a project's config runs, newest first", run: cmdRunsList},
		{name: "show", args: "<run>", summary: "show a config run: plan, approval, deployments", run: cmdRunsShow},
	}}
}

func cmdRunsList(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("runs list")
	var env, project string
	envFlag(fs, &env)
	projectFlag(fs, &project)
	limit := fs.Int("limit", 20, "show at most this many runs")
	if _, err := a.parse(fs, args, 0, 0, "[-p PROJECT] [-e ENV] [--limit N]"); err != nil {
		return err
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	id, err := a.requireProject(ctx, c, project, "")
	if err != nil {
		return err
	}
	runs, err := c.ConfigRuns(ctx, id, env, *limit)
	if err != nil {
		return err
	}
	if a.jsonOut {
		return a.printJSON(runs)
	}
	rows := make([][]string, 0, len(runs))
	for _, r := range runs {
		status := r.Status
		if r.CancelReason != nil && *r.CancelReason != "" {
			status += " (" + *r.CancelReason + ")"
		}
		commit := ""
		if r.Commit != nil {
			commit = shortSHA(*r.Commit)
		}
		rows = append(rows, []string{r.ID, dash(r.Environment), status, dash(r.Trigger), dash(commit), dash(r.CreatedAt)})
	}
	a.table([]string{"RUN", "ENVIRONMENT", "STATUS", "TRIGGER", "COMMIT", "CREATED"}, rows)
	return nil
}

func cmdRunsShow(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("runs show")
	pos, err := a.parse(fs, args, 1, 1, "<run>")
	if err != nil {
		return err
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	run, err := c.ConfigRun(ctx, pos[0])
	if err != nil {
		return err
	}
	if a.jsonOut {
		return a.printRaw(run.Raw)
	}
	a.printRun(a.Stdout, run)
	return nil
}

func deref(s *string) string {
	if s == nil {
		return ""
	}
	return *s
}

// printRun shows a run: status, approval, plan and deployments.
func (a *App) printRun(w io.Writer, run api.ConfigRun) {
	status := run.Status
	if r := deref(run.CancelReason); r != "" {
		status += " (" + r + ")"
	}
	pairs := [][2]string{{"run", run.ID}, {"environment", run.Environment}, {"status", status}, {"trigger", run.Trigger},
		{"commit", deref(run.Commit)}, {"branch", deref(run.Branch)}, {"created", run.CreatedAt}, {"finished", deref(run.FinishedAt)},
		{"error", deref(run.Error)}, {"url", run.URL}}
	if ap := run.Approval; ap != nil {
		state := "waiting"
		switch {
		case ap.ApprovedAt != nil:
			state = "approved by " + deref(ap.ApprovedBy) + " at " + *ap.ApprovedAt
		case ap.RejectedAt != nil:
			state = "rejected by " + deref(ap.RejectedBy) + " at " + *ap.RejectedAt
		case ap.ExpiresAt != nil:
			state = "waiting (expires " + *ap.ExpiresAt + ")"
		}
		pairs = append(pairs, [2]string{"approval", state}, [2]string{"comment", deref(ap.Comment)})
		if run.Status == api.RunAwaitingApproval && !ap.CanApprove {
			pairs = append(pairs, [2]string{"you", deref(ap.BlockedReason)})
		}
	}
	saved := a.Stdout
	a.Stdout = w
	a.kv(pairs)
	a.Stdout = saved
	if run.Plan != nil {
		fmt.Fprintln(w)
		printPlan(w, run.Environment, *run.Plan)
	}
	if len(run.Deployments) > 0 {
		fmt.Fprintln(w, "\nDeployments:")
		for _, d := range run.Deployments {
			line := fmt.Sprintf("  %s: %s", d.Unit, d.Status)
			if id := d.ID(); id != "" {
				line += " (" + id + ")"
			}
			if e := deref(d.Error); e != "" {
				line += ": " + e
			}
			fmt.Fprintln(w, line)
		}
	}
}

// printRisky lists what an approval covers.
func printRisky(w io.Writer, run api.ConfigRun) {
	var items []api.RiskyItem
	if run.Approval != nil {
		items = run.Approval.Risky
	}
	if len(items) == 0 && run.Plan != nil {
		for _, ch := range run.Plan.Changes {
			if ch.Risky {
				items = append(items, api.RiskyItem{Op: ch.Op, Path: ch.Path, Rule: ch.Rule, Reason: ch.Reason})
			}
		}
	}
	for i, it := range items {
		// The approval names path + rule; the op comes from the plan when the item doesn't carry it.
		if it.Op == "" && run.Plan != nil {
			for _, ch := range run.Plan.Changes {
				if ch.Risky && ch.Path == it.Path && ch.Rule == it.Rule {
					items[i].Op = ch.Op
					break
				}
			}
		}
	}
	fmt.Fprintf(w, "Run %s (%s) waits for an approval of %d risky change(s):\n", run.ID, dash(run.Environment), len(items))
	for _, it := range items {
		fmt.Fprintf(w, "  ! %-6s %s", dash(it.Op), it.Path)
		if it.Rule != "" {
			fmt.Fprintf(w, " (%s)", it.Rule)
		}
		fmt.Fprintln(w)
		if it.Reason != "" {
			fmt.Fprintf(w, "      %s\n", it.Reason)
		}
	}
	if run.URL != "" {
		fmt.Fprintln(w, run.URL)
	}
}

// confirm asks on the terminal; without one, yes must be given.
func (a *App) confirm(q string, yes bool) (bool, error) {
	if yes {
		return true, nil
	}
	if !a.Interactive {
		return false, usagef("not a terminal: pass --yes to confirm")
	}
	fmt.Fprintf(a.Stderr, "%s (y/N): ", q)
	if a.in == nil {
		a.in = bufio.NewReader(a.Stdin)
	}
	line, _ := a.in.ReadString('\n')
	return strings.HasPrefix(strings.ToLower(strings.TrimSpace(line)), "y"), nil
}

// approve shows the risky changes, asks, and approves; when the plan changed since it was shown it shows the new
// one and asks again.
func (a *App) approve(ctx context.Context, c api.API, run api.ConfigRun, comment string, yes bool, out io.Writer) (api.ConfigRun, error) {
	for attempt := 0; attempt < 3; attempt++ {
		if run.Status != api.RunAwaitingApproval {
			return run, fmt.Errorf("run %s is %s, not awaiting an approval", run.ID, run.Status)
		}
		if ap := run.Approval; ap != nil && !ap.CanApprove {
			reason := deref(ap.BlockedReason)
			if reason == "" {
				reason = "you can't approve it"
			}
			return run, exitError{ExitApproval, fmt.Errorf("run %s waits for an approval: %s %s", run.ID, reason, run.URL)}
		}
		// The hash of exactly what is shown below: never re-fetched between showing and sending.
		hash := ""
		if run.Approval != nil {
			hash = run.Approval.PlanHash
		}
		if hash == "" {
			return run, fmt.Errorf("run %s has no plan_hash to approve (the control plane is older than this CLI): approve it in the dashboard %s", run.ID, run.URL)
		}
		printRisky(out, run)
		ok, err := a.confirm(fmt.Sprintf("Approve run %s?", run.ID), yes)
		if err != nil {
			return run, err
		}
		if !ok {
			return run, exitError{ExitApproval, fmt.Errorf("run %s not approved: it still waits", run.ID)}
		}
		next, err := c.ConfigApprove(ctx, run.ID, hash, comment)
		var pc *api.PlanChangedError
		if errors.As(err, &pc) {
			fmt.Fprintf(a.Stderr, "falak: %s\n", pc.Message)
			run, yes = pc.Run, false // a new plan is confirmed again, even with --yes
			if !a.Interactive {
				return run, exitError{ExitApproval, fmt.Errorf("run %s: the plan changed since it was shown; review it again", run.ID)}
			}
			continue
		}
		if err != nil {
			return run, err
		}
		if !a.jsonOut {
			fmt.Fprintf(out, "Approved run %s: %s\n", next.ID, next.Status)
		}
		return next, nil
	}
	return run, exitError{ExitApproval, fmt.Errorf("run %s: the plan keeps changing; approve it in the dashboard", run.ID)}
}

func cmdApprove(ctx context.Context, a *App, args []string) error {
	return runDecision(ctx, a, args, true)
}

func cmdReject(ctx context.Context, a *App, args []string) error {
	return runDecision(ctx, a, args, false)
}

func runDecision(ctx context.Context, a *App, args []string, approve bool) error {
	name := map[bool]string{true: "approve", false: "reject"}[approve]
	fs := a.flagSet(name)
	comment := fs.String("comment", "", "a comment recorded with the decision")
	yes := fs.Bool("yes", false, "don't ask for confirmation")
	pos, err := a.parse(fs, args, 1, 1, "<run> [--comment TEXT] [--yes]")
	if err != nil {
		return err
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	run, err := c.ConfigRun(ctx, pos[0])
	if err != nil {
		return err
	}
	out := a.Stdout
	if a.jsonOut {
		out = a.Stderr
	}
	if approve {
		run, err = a.approve(ctx, c, run, *comment, *yes, out)
		if err != nil {
			return err
		}
	} else {
		if run.Status != api.RunAwaitingApproval {
			return fmt.Errorf("run %s is %s, not awaiting an approval", run.ID, run.Status)
		}
		printRisky(out, run)
		ok, err := a.confirm(fmt.Sprintf("Reject run %s (it is cancelled)?", run.ID), *yes)
		if err != nil {
			return err
		}
		if !ok {
			return errors.New("not rejected")
		}
		if run, err = c.ConfigReject(ctx, run.ID, *comment); err != nil {
			return err
		}
		if !a.jsonOut {
			fmt.Fprintf(out, "Rejected run %s: %s\n", run.ID, run.Status)
		}
	}
	if a.jsonOut {
		return a.printRaw(run.Raw)
	}
	return nil
}

func cmdDrift(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("drift")
	var env, project string
	envFlag(fs, &env)
	projectFlag(fs, &project)
	check := fs.Bool("check", false, "check now instead of reading the last scheduled check")
	if _, err := a.parse(fs, args, 0, 0, "[-e ENV] [-p PROJECT] [--check]"); err != nil {
		return err
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	id, err := a.requireProject(ctx, c, project, "")
	if err != nil {
		return err
	}
	if env == "" {
		if env, err = productionSlug(ctx, c, id); err != nil {
			return err
		}
	}
	d, err := c.ConfigDrift(ctx, id, env, *check)
	if err != nil {
		return err
	}
	if a.jsonOut {
		if err := a.printRaw(d.Raw); err != nil {
			return err
		}
	} else {
		printDrift(a.Stdout, d)
	}
	if d.Status == "drifted" {
		return exitError{ExitDrift, fmt.Errorf("%s drifted from its last applied falak.yaml (%d change(s))", dash(d.Environment), len(d.Changes))}
	}
	return nil
}

func printDrift(w io.Writer, d api.ConfigDrift) {
	when := ""
	if d.CheckedAt != nil {
		when = " (checked " + *d.CheckedAt + ")"
	}
	switch d.Status {
	case "in_sync":
		fmt.Fprintf(w, "%s matches its last applied falak.yaml%s.\n", d.Environment, when)
		return
	case "unknown":
		fmt.Fprintf(w, "%s: drift unknown%s (nothing applied yet, or a run is applying).\n", d.Environment, when)
		return
	}
	since := ""
	if d.Since != nil {
		since = " since " + *d.Since
	}
	base := ""
	if c := deref(d.BaselineCommit); c != "" {
		base = " @ " + shortSHA(c)
	}
	fmt.Fprintf(w, "%s drifted from falak.yaml%s%s%s. Applying the file again would make these changes:\n\n", d.Environment, base, since, when)
	for _, ch := range d.Changes {
		fmt.Fprintf(w, "  %s %-6s %s", opSymbols[ch.Op], ch.Op, ch.Path)
		if ch.Op == "update" {
			fmt.Fprintf(w, ": %s → %s", compact(ch.Before), compact(ch.After))
		}
		fmt.Fprintln(w)
	}
}

func cmdConfigUnmanaged(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("config unmanaged")
	var env, project string
	envFlag(fs, &env)
	projectFlag(fs, &project)
	if _, err := a.parse(fs, args, 0, 0, "[-e ENV] [-p PROJECT]"); err != nil {
		return err
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	id, err := a.requireProject(ctx, c, project, "")
	if err != nil {
		return err
	}
	if env == "" {
		// The API needs an environment: the project's production one, as for plan and drift.
		if env, err = productionSlug(ctx, c, id); err != nil {
			return err
		}
	}
	list, err := c.ConfigUnmanaged(ctx, id, env)
	if err != nil {
		return err
	}
	if a.jsonOut {
		return a.printJSON(list)
	}
	if len(list) == 0 {
		fmt.Fprintln(a.Stdout, "Nothing detached: every resource a run released is managed again or gone.")
		return nil
	}
	rows := make([][]string, 0, len(list))
	for _, r := range list {
		rows = append(rows, []string{dash(r.Environment), dash(deref(r.Unit)), r.ResourceType, r.Label, r.Reason, r.DetachedAt})
	}
	a.table([]string{"ENVIRONMENT", "UNIT", "TYPE", "RESOURCE", "REASON", "DETACHED"}, rows)
	return nil
}

func cmdConfigSourceShow(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("config source show")
	var project string
	projectFlag(fs, &project)
	if _, err := a.parse(fs, args, 0, 0, "[-p PROJECT]"); err != nil {
		return err
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	id, err := a.requireProject(ctx, c, project, "")
	if err != nil {
		return err
	}
	s, err := c.ConfigSource(ctx, id)
	if err != nil {
		return sourceErr(err)
	}
	return a.printSource(s)
}

// cmdConfigSourceSet changes only what its flags name: the rest is read from the current source first.
func cmdConfigSourceSet(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("config source set")
	var project, repo, connection, path string
	projectFlag(fs, &project)
	fs.StringVar(&repo, "repo", "", "repository, owner/name")
	fs.StringVar(&connection, "connection", "", "source control connection id")
	fs.StringVar(&path, "path", "", "the file in the repository (default falak.yaml)")
	enable := fs.Bool("enable", false, "follow the file (pushes apply it)")
	disable := fs.Bool("disable", false, "stop following the file (keeps every resource; cancels waiting runs)")
	var servers headerFlags // ponytail: the -H flag type, a repeatable string list
	fs.Var(&servers, "allow-server", "a server the file may place units on (repeatable; replaces the list; \"\" clears it; admins only)")
	if _, err := a.parse(fs, args, 0, 0, "[-p PROJECT] [--repo OWNER/NAME] [--connection ID] [--path FILE] [--enable|--disable] [--allow-server NAME]…"); err != nil {
		return err
	}
	if *enable && *disable {
		return usagef("give --enable or --disable, not both")
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	id, err := a.requireProject(ctx, c, project, "")
	if err != nil {
		return err
	}
	// A new source is enabled unless --disable says otherwise.
	r := api.ConfigSourceRequest{Enabled: true}
	cur, err := c.ConfigSource(ctx, id)
	var e *api.Error
	switch {
	case err == nil:
		r = api.ConfigSourceRequest{ConnectionID: cur.ConnectionID, Repository: cur.Repository, Path: cur.Path, Enabled: cur.Enabled}
	case !errors.As(err, &e) || e.Status != http.StatusNotFound:
		return err
	}
	if repo != "" {
		r.Repository = repo
	}
	if connection != "" {
		r.ConnectionID = connection
	}
	if path != "" {
		r.Path = path
	}
	if *enable || *disable {
		r.Enabled = *enable
	}
	if len(servers) > 0 {
		list := []string{}
		for _, s := range servers {
			if s = strings.TrimSpace(s); s != "" {
				list = append(list, s)
			}
		}
		r.AllowedServers = &list
	}
	if r.Repository == "" || r.ConnectionID == "" {
		return usagef("the project has no config source yet: give --repo and --connection")
	}
	s, err := c.SetConfigSource(ctx, id, r)
	if err != nil {
		return err
	}
	return a.printSource(s)
}

func sourceErr(err error) error {
	var e *api.Error
	if errors.As(err, &e) && e.Status == http.StatusNotFound {
		return fmt.Errorf("config as code is not connected for this project (falak config source set --repo OWNER/NAME --connection ID)")
	}
	return err
}

func (a *App) printSource(s api.ConfigSource) error {
	if a.jsonOut {
		return a.printRaw(s.Raw)
	}
	state := "disabled"
	if s.Enabled {
		state = "enabled"
	}
	fmt.Fprintf(a.Stdout, "%s:%s (%s)\n", s.Repository, s.Path, state)
	fmt.Fprintf(a.Stdout, "  connection          %s\n", s.ConnectionID)
	fmt.Fprintf(a.Stdout, "  separate approver   %t\n", s.SeparateApprover)
	fmt.Fprintf(a.Stdout, "  allowed credentials %s\n", dash(strings.Join(s.AllowedCredentials, ", ")))
	fmt.Fprintf(a.Stdout, "  allowed servers     %s\n", dash(strings.Join(s.AllowedServers, ", ")))
	return nil
}
