package cli

import (
	"context"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"os"
	"strings"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/cli/api"
)

func envFlag(fs *flag.FlagSet, p *string) {
	fs.StringVar(p, "e", "", "environment (slug)")
	fs.StringVar(p, "env", "", "environment (slug)")
}

func projectFlag(fs *flag.FlagSet, p *string) {
	fs.StringVar(p, "p", "", "project id or name (default: FALAK_PROJECT, then .falak/project.json)")
	fs.StringVar(p, "project", "", "project id or name")
}

func optArg(pos []string) string {
	if len(pos) > 0 {
		return pos[0]
	}
	return ""
}

// loggedIn reports stored or given credentials (without contacting the control plane).
func (a *App) loggedIn() bool {
	c, err := a.creds()
	return err == nil && c.URL != "" && c.Token != ""
}

func cmdValidate(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("validate")
	var env, project string
	envFlag(fs, &env)
	projectFlag(fs, &project)
	format := fs.String("format", "", "yaml or json (default: from the file name)")
	offline := fs.Bool("offline", false, "don't check names against the control plane")
	pos, err := a.parse(fs, args, 0, 1, "[file] [-e ENV] [--format yaml|json] [--offline]")
	if err != nil {
		return err
	}
	f, err := a.readConfigFile(optArg(pos), *format)
	if err != nil {
		return err
	}
	v := validateOffline(f, env)
	if v.Valid && !*offline && a.loggedIn() {
		a.validateOnline(ctx, f, env, project, &v)
	}
	if a.jsonOut {
		if err := a.printJSON(v); err != nil {
			return err
		}
	} else {
		printIssues(a.Stderr, f.Display, v.Errors)
		printIssues(a.Stderr, f.Display, v.Warnings)
	}
	if !v.Valid {
		return invalid(f.Display, len(v.Errors))
	}
	if !a.jsonOut {
		where := "every environment"
		if env != "" {
			where = env
		}
		fmt.Fprintf(a.Stdout, "%s is valid (%s)\n", f.Display, where)
	}
	return nil
}

// validateOnline adds stage 4 (names, config lists, limits) from the control plane for a linked project. It only
// warns when the control plane can't answer: the offline result stands.
func (a *App) validateOnline(ctx context.Context, f configFile, env, project string, v *validation) {
	ref, err := a.projectRef(project, f.Path)
	if err != nil || ref == "" {
		return
	}
	c, err := a.api()
	if err != nil {
		return
	}
	id, err := resolveProject(ctx, c, ref)
	if err != nil {
		fmt.Fprintf(a.Stderr, "falak: names not checked: %v\n", err)
		return
	}
	envs := v.Environments
	if env != "" {
		envs = []string{env}
	}
	seen := map[string]bool{}
	for _, e := range envs {
		res, err := c.ConfigValidate(ctx, id, api.ConfigRequest{File: string(f.Raw), Format: f.Format, Environment: e})
		if err != nil {
			fmt.Fprintf(a.Stderr, "falak: names not checked (%s): %v\n", e, err)
			return
		}
		for _, list := range []struct {
			in  []api.ConfigIssue
			out *[]api.ConfigIssue
			sev string
		}{{res.Errors, &v.Errors, "error"}, {res.Warnings, &v.Warnings, "warning"}} {
			for _, i := range list.in {
				src := ""
				if i.Source != nil {
					src = *i.Source
				}
				k := i.Code + "\x00" + i.Pointer + "\x00" + src
				if seen[k] {
					continue
				}
				seen[k] = true
				if i.Severity == "" {
					i.Severity = list.sev
				}
				*list.out = append(*list.out, i)
			}
		}
	}
	v.Valid = len(v.Errors) == 0
}

// prepare reads and validates the file offline, then picks the environment and the project.
func (a *App) prepareRun(ctx context.Context, file, env, project string) (configFile, string, string, api.API, error) {
	f, err := a.readConfigFile(file, "")
	if err != nil {
		return f, "", "", nil, err
	}
	v := validateOffline(f, env)
	if !v.Valid {
		if a.jsonOut {
			_ = a.printJSON(map[string]any{"errors": v.Errors, "warnings": v.Warnings})
		} else {
			printIssues(a.Stderr, f.Display, v.Errors)
			printIssues(a.Stderr, f.Display, v.Warnings)
		}
		return f, "", "", nil, invalid(f.Display, len(v.Errors))
	}
	if env == "" {
		if env, err = a.defaultEnvironment(f, v.doc); err != nil {
			return f, "", "", nil, err
		}
	}
	c, err := a.api()
	if err != nil {
		return f, "", "", nil, err
	}
	id, err := a.requireProject(ctx, c, project, f.Path)
	if err != nil {
		return f, "", "", nil, err
	}
	return f, env, id, c, nil
}

// configAPIError prints a 422's issues (exit 5); other errors pass through.
func (a *App) configAPIError(f configFile, err error) error {
	var ie *api.ConfigInvalidError
	if !errors.As(err, &ie) {
		return err
	}
	if a.jsonOut {
		_ = a.printJSON(map[string]any{"errors": ie.Errors, "warnings": ie.Warnings})
	} else {
		printIssues(a.Stderr, f.Display, ie.Errors)
		printIssues(a.Stderr, f.Display, ie.Warnings)
	}
	return invalid(f.Display, len(ie.Errors))
}

func cmdPlan(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("plan")
	var env, project string
	envFlag(fs, &env)
	projectFlag(fs, &project)
	pos, err := a.parse(fs, args, 0, 1, "[file] [-e ENV] [-p PROJECT]")
	if err != nil {
		return err
	}
	f, env, id, c, err := a.prepareRun(ctx, optArg(pos), env, project)
	if err != nil {
		return err
	}
	res, err := c.ConfigPlan(ctx, id, api.ConfigRequest{File: string(f.Raw), Format: f.Format, Environment: env})
	if err != nil {
		return a.configAPIError(f, err)
	}
	if a.jsonOut {
		return a.printRaw(res.Raw)
	}
	printIssues(a.Stderr, f.Display, res.Warnings)
	printPlan(a.Stdout, res.Environment, res.Plan)
	return nil
}

func (a *App) printRaw(raw json.RawMessage) error {
	var v any
	if err := json.Unmarshal(raw, &v); err != nil {
		return err
	}
	return a.printJSON(v)
}

var opSymbols = map[string]string{"create": "+", "update": "~", "delete": "-", "detach": "-", "noop": " "}

// compact renders a change's value on one line.
func compact(v any) string {
	if v == nil {
		return "(none)"
	}
	if s, ok := v.(string); ok {
		return s
	}
	b, _ := json.Marshal(v)
	s := string(b)
	if len(s) > 80 {
		s = s[:77] + "..."
	}
	return s
}

func printPlan(w io.Writer, env string, p api.ConfigPlan) {
	sum := p.Summary
	changes := make([]api.ConfigChange, 0, len(p.Changes))
	for _, ch := range p.Changes {
		if ch.Op != "noop" {
			changes = append(changes, ch)
		}
	}
	if len(changes) == 0 {
		fmt.Fprintf(w, "No changes: %s matches the file.\n", env)
		return
	}
	fmt.Fprintf(w, "Plan for %s: %d to create, %d to update, %d to delete, %d to detach (%d unchanged)\n\n",
		env, sum["create"], sum["update"], sum["delete"], sum["detach"], sum["noop"])
	for _, ch := range changes {
		sym := opSymbols[ch.Op]
		if ch.Risky {
			sym = "!"
		}
		line := fmt.Sprintf("  %s %-6s %s", sym, ch.Op, ch.Path)
		switch ch.Op {
		case "create":
			if ch.After != nil {
				line += " = " + compact(ch.After)
			}
		case "update":
			line += ": " + compact(ch.Before) + " → " + compact(ch.After)
		case "detach":
			line += " (kept, no longer managed by the file)"
		}
		fmt.Fprintln(w, line)
		if ch.Risky {
			reason := ch.Reason
			if reason == "" {
				reason = "needs an approval"
			}
			if ch.Rule != "" {
				reason += " (" + ch.Rule + ")"
			}
			fmt.Fprintf(w, "           risky: %s\n", reason)
		}
	}
	if len(p.Deploy) > 0 {
		fmt.Fprintf(w, "\nDeploys: %s\n", strings.Join(p.Deploy, ", "))
	}
	if p.Risky {
		fmt.Fprintln(w, "\nThis plan has risky changes (!): applying it waits for an approval.")
	}
}

func cmdApply(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("apply")
	var env, project string
	envFlag(fs, &env)
	projectFlag(fs, &project)
	allowDestroy := fs.Bool("allow-destroy", false, "let the run delete what the file removed (databases and volumes included)")
	wait := fs.Bool("wait", false, "wait for the run and stream its deployments")
	timeout := fs.Duration("timeout", 30*time.Minute, "--wait: give up after this long (the run goes on)")
	pos, err := a.parse(fs, args, 0, 1, "[file] [-e ENV] [-p PROJECT] [--allow-destroy] [--wait [--timeout 30m]]")
	if err != nil {
		return err
	}
	f, env, id, c, err := a.prepareRun(ctx, optArg(pos), env, project)
	if err != nil {
		return err
	}
	run, err := c.ConfigApply(ctx, id, api.ConfigRequest{File: string(f.Raw), Format: f.Format, Environment: env, AllowDestroy: allowDestroy})
	if err != nil {
		return a.configAPIError(f, err)
	}
	out := a.Stdout
	if a.jsonOut {
		out = a.Stderr
	}
	if !a.jsonOut {
		fmt.Fprintf(out, "Run %s for %s: %s\n", run.ID, dash(run.Environment), run.Status)
		if run.Plan != nil {
			fmt.Fprintln(out)
			printPlan(out, run.Environment, *run.Plan)
			fmt.Fprintln(out)
		}
		if run.URL != "" {
			fmt.Fprintln(out, run.URL)
		}
	}
	if *wait && !run.Done() {
		run, err = a.followRun(ctx, c, run, out, *timeout)
		if err != nil {
			return err
		}
	}
	if a.jsonOut {
		if err := a.printRaw(run.Raw); err != nil {
			return err
		}
	}
	return a.runOutcome(out, f.Display, run, *wait)
}

// runOutcome maps a run's status to the exit code: 6 waiting for an approval, 3 failed because a deployment failed,
// 1 any other failure.
func (a *App) runOutcome(out io.Writer, file string, run api.ConfigRun, waited bool) error {
	msg := func() string {
		if run.Error != nil && *run.Error != "" {
			return ": " + *run.Error
		}
		return ""
	}
	switch run.Status {
	case api.RunAwaitingApproval:
		return exitError{ExitApproval, fmt.Errorf("run %s waits for an approval (risky changes): approve it in the dashboard", run.ID)}
	case api.RunRefused:
		return fmt.Errorf("run %s refused%s (risky changes are refused while config as code is experimental)", run.ID, msg())
	case api.RunFailed:
		printIssues(a.Stderr, file, run.Errors)
		var failed []string
		for _, d := range run.Deployments {
			if d.Failed() {
				failed = append(failed, d.Unit)
			}
		}
		if len(failed) > 0 {
			return exitError{ExitDeployFailed, fmt.Errorf("run %s failed: deployment of %s failed%s", run.ID, strings.Join(failed, ", "), msg())}
		}
		return fmt.Errorf("run %s failed%s", run.ID, msg())
	case api.RunCancelled:
		return fmt.Errorf("run %s was cancelled", run.ID)
	}
	if waited && !a.jsonOut {
		fmt.Fprintf(out, "Run %s %s\n", run.ID, run.Status)
	}
	return nil
}

// followRun polls the run until its status says it is finished (applied, failed, cancelled, refused) or waits for
// an approval, streaming the output of its deployments prefixed with the unit, for at most timeout.
func (a *App) followRun(ctx context.Context, c api.API, run api.ConfigRun, out io.Writer, timeout time.Duration) (api.ConfigRun, error) {
	ctx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()
	cursors := map[string]int64{}
	reported := map[string]string{}
	drain := func(final bool) {
		for _, d := range run.Deployments {
			id := d.ID()
			if id == "" {
				continue
			}
			if _, known := cursors[id]; !known {
				cursors[id] = 0
				if !a.jsonOut {
					fmt.Fprintf(out, "[%s] deployment %s started\n", d.Unit, id)
				}
			}
			if reported[id] == "" || final {
				if lines, next, err := c.DeploymentOutput(ctx, id, cursors[id]); err == nil {
					cursors[id] = next
					for _, l := range lines {
						printOutputLine(prefixWriter{out, "[" + d.Unit + "] "}, l)
					}
				}
			}
			if d.Settled() && reported[id] == "" {
				reported[id] = d.Status
				line := fmt.Sprintf("[%s] deployment %s %s", d.Unit, id, d.Status)
				if d.Error != nil && *d.Error != "" {
					line += ": " + *d.Error
				}
				fmt.Fprintln(out, line)
			}
		}
	}
	errs := 0
	for {
		drain(false)
		if run.Done() {
			drain(true)
			return run, nil
		}
		select {
		case <-ctx.Done():
			if errors.Is(ctx.Err(), context.DeadlineExceeded) {
				return run, fmt.Errorf("gave up waiting for run %s after %s (still %s); it goes on: %s", run.ID, timeout, run.Status, dash(run.URL))
			}
			return run, ctx.Err()
		case <-time.After(a.Poll):
		}
		next, err := c.ConfigRun(ctx, run.ID)
		if err != nil {
			if ctx.Err() != nil {
				continue
			}
			if errs++; errs > 5 {
				return run, err
			}
			fmt.Fprintf(a.Stderr, "falak: %v (retrying)\n", err)
			continue
		}
		errs = 0
		if next.Status != run.Status && !a.jsonOut {
			fmt.Fprintf(out, "Run %s: %s\n", next.ID, next.Status)
		}
		run = next
	}
}

// prefixWriter prefixes each line written (printOutputLine writes whole lines).
type prefixWriter struct {
	w      io.Writer
	prefix string
}

func (p prefixWriter) Write(b []byte) (int, error) {
	var sb strings.Builder
	for _, line := range strings.SplitAfter(string(b), "\n") {
		if line != "" {
			sb.WriteString(p.prefix + line)
		}
	}
	if _, err := io.WriteString(p.w, sb.String()); err != nil {
		return 0, err
	}
	return len(b), nil
}

func cmdConfigExport(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("config export")
	var env, project, output string
	envFlag(fs, &env)
	projectFlag(fs, &project)
	fs.StringVar(&output, "o", "", "write to this file (default: stdout)")
	fs.StringVar(&output, "output", "", "write to this file (default: stdout)")
	format := fs.String("format", "", "yaml or json (default: from -o's name, else yaml)")
	pos, err := a.parse(fs, args, 0, 1, "[project] [-e ENV] [-o FILE] [--format yaml|json]")
	if err != nil {
		return err
	}
	if len(pos) == 1 {
		if project != "" && project != pos[0] {
			return usagef("give the project once (argument or -p)")
		}
		project = pos[0]
	}
	if *format == "" {
		*format = "yaml"
		if strings.HasSuffix(strings.ToLower(output), ".json") {
			*format = "json"
		}
	}
	if *format != "yaml" && *format != "json" {
		return usagef("--format must be yaml or json")
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	id, err := a.requireProject(ctx, c, project, "")
	if err != nil {
		return err
	}
	x, err := c.ConfigExport(ctx, id, env, *format)
	if err != nil {
		return err
	}
	if a.jsonOut && output == "" {
		return a.printRaw(x.Raw)
	}
	content := x.File
	if content != "" && !strings.HasSuffix(content, "\n") {
		content += "\n"
	}
	if output == "" {
		_, err := io.WriteString(a.Stdout, content)
		return err
	}
	if err := os.WriteFile(a.abs(output), []byte(content), 0o644); err != nil {
		return err
	}
	if a.jsonOut {
		return a.printRaw(x.Raw)
	}
	fmt.Fprintf(a.Stderr, "Wrote %s (%s)\n", output, dash(x.Environment))
	return nil
}
