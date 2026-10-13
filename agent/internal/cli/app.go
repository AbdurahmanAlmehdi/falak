// Package cli implements the `falak` command-line client for the control-plane public API.
package cli

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"os"
	"strings"
	"text/tabwriter"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/cli/api"
)

// Exit codes.
const (
	ExitOK           = 0
	ExitError        = 1
	ExitUsage        = 2
	ExitDeployFailed = 3
)

// App holds I/O and injectable dependencies; one App serves one invocation.
type App struct {
	Stdout io.Writer
	Stderr io.Writer
	Stdin  io.Reader
	Getenv func(string) string
	// NewAPI builds the API client (tests may return a fake).
	NewAPI func(baseURL, token string) api.API
	// Exec replaces the process (ssh). Open opens a URL in a browser.
	Exec func(argv []string) error
	Open func(url string) error
	// Poll is the interval for --wait / --follow polling.
	Poll    time.Duration
	Version string
	// Interactive reports whether Stdin is a terminal (login prompt).
	Interactive bool
	// ReadSecret reads a line without echo (interactive login); nil falls back to Stdin.
	ReadSecret func() (string, error)
	// Dir is the working directory ("" = the process's).
	Dir string
	// GitBranch returns the current branch of the repository holding dir (nil: runs git).
	GitBranch func(dir string) string

	// resolved global options
	jsonOut bool
	baseURL string
	token   string
	cfgDir  string
	client  api.API
	in      *bufio.Reader // Stdin, read line by line by prompts
}

// usageError makes Run exit with ExitUsage.
type usageError struct{ msg string }

func (e usageError) Error() string { return e.msg }

func usagef(format string, a ...any) error { return usageError{fmt.Sprintf(format, a...)} }

// exitError carries a specific exit code.
type exitError struct {
	code int
	err  error
}

func (e exitError) Error() string { return e.err.Error() }

type command struct {
	name    string
	args    string // usage synopsis
	summary string
	run     func(ctx context.Context, a *App, args []string) error
	subs    []*command
}

func (a *App) commands() []*command {
	return []*command{
		{name: "login", args: "[--url URL] [--token TOKEN | --token-stdin]", summary: "store an API token for a control plane", run: cmdLogin},
		{name: "logout", summary: "forget the stored token", run: cmdLogout},
		{name: "whoami", summary: "show the current user and organization", run: cmdWhoami},
		{name: "orgs", summary: "list organizations", run: cmdOrgs},
		{name: "servers", summary: "list or inspect servers", subs: []*command{
			{name: "list", summary: "list servers", run: cmdServersList},
			{name: "show", args: "<server>", summary: "show one server", run: cmdServersShow},
		}},
		{name: "sites", summary: "list or inspect sites", subs: []*command{
			{name: "list", summary: "list sites", run: cmdSitesList},
			{name: "show", args: "<site>", summary: "show one site", run: cmdSitesShow},
		}},
		{name: "deploy", args: "<site> [--branch BRANCH] [--wait]", summary: "deploy a site", run: cmdDeploy},
		{name: "rollback", args: "<site> [--release ID] [--wait]", summary: "roll a site back to a previous release", run: cmdRollback},
		{name: "releases", args: "<site>", summary: "list a site's releases", run: cmdReleases},
		{name: "env", summary: "manage a site's .env", subs: []*command{
			{name: "pull", args: "<site> [--file PATH]", summary: "download the .env (stdout or --file)", run: cmdEnvPull},
			{name: "push", args: "<site> [--file PATH]", summary: "upload the .env (stdin or --file)", run: cmdEnvPush},
		}},
		{name: "logs", args: "<site> [--follow] [--since 1h] [--limit N] [--level LEVEL]", summary: "show site logs", run: cmdLogs},
		{name: "ssh", args: "<server> [--user USER] [-- ssh args]", summary: "open an SSH session to a server", run: cmdSSH},
		{name: "open", args: "<site> [--panel]", summary: "open a site (or its panel page) in the browser", run: cmdOpen},
		functionCommands(),
		{name: "db", summary: "database containers", subs: []*command{
			{name: "list", summary: "list database containers", run: cmdDBList},
			{name: "delete", args: "<instance> --confirm NAME [--delete-volume]", summary: "delete a database container (its volume stays unless --delete-volume)", run: cmdDBDelete},
		}},
		{name: "init", args: "[dir] [--yes] [--stdout] [--json] [--full] [--force] [--dockerfile] [-p PROJECT]", summary: "write a falak.yaml from the code", run: cmdInit},
		{name: "validate", args: "[file] [-e ENV] [--format yaml|json] [--offline]", summary: "check a falak.yaml (offline; names too when logged in)", run: cmdValidate},
		{name: "plan", args: "[file] [-e ENV] [-p PROJECT]", summary: "show what applying a falak.yaml would change", run: cmdPlan},
		{name: "apply", args: "[file] [-e ENV] [-p PROJECT] [--allow-destroy] [--wait] [--approve [--yes]]", summary: "apply a falak.yaml (and deploy what changed)", run: cmdApply},
		{name: "config", summary: "config as code", subs: []*command{
			{name: "export", args: "[project] [-e ENV] [-o FILE] [--format yaml|json]", summary: "write a project's current state as a falak.yaml", run: cmdConfigExport},
			{name: "unmanaged", args: "[-e ENV] [-p PROJECT]", summary: "list what runs detached (kept, no longer managed)", run: cmdConfigUnmanaged},
			{name: "source", summary: "the repository file a project follows", subs: []*command{
				{name: "show", args: "[-p PROJECT]", summary: "show a project's config source", run: cmdConfigSourceShow},
				{name: "set", args: "[-p PROJECT] [--repo OWNER/NAME] [--connection ID] [--path FILE] [--enable|--disable] [--allow-server NAME]…", summary: "connect, change, enable or disable a project's config source", run: cmdConfigSourceSet},
			}},
		}},
		runsCommands(),
		{name: "secret", summary: "project and environment secrets, for ${{ secrets.NAME }}", subs: []*command{
			{name: "list", args: "[-p PROJECT] [-e ENV]", summary: "list secret names (never values)", run: cmdSecretList},
			{name: "set", args: "<NAME> [-p PROJECT] [-e ENV] | --from-env FILE", summary: "create or update a secret; the value comes from a hidden prompt or stdin", run: cmdSecretSet},
			{name: "delete", args: "<NAME> [-p PROJECT] [-e ENV] --yes", summary: "delete a secret", run: cmdSecretDelete},
		}},
		{name: "approve", args: "<run> [--comment TEXT] [--yes]", summary: "approve a config run waiting for an approval", run: cmdApprove},
		{name: "reject", args: "<run> [--comment TEXT] [--yes]", summary: "reject a config run waiting for an approval", run: cmdReject},
		{name: "drift", args: "[-e ENV] [-p PROJECT] [--check]", summary: "compare an environment with its last applied falak.yaml", run: cmdDrift},
		{name: "version", summary: "print the version", run: func(_ context.Context, a *App, _ []string) error {
			fmt.Fprintln(a.Stdout, a.Version)
			return nil
		}},
	}
}

// Run executes argv (without the program name) and returns the process exit code.
func (a *App) Run(ctx context.Context, argv []string) int {
	if a.Getenv == nil {
		a.Getenv = os.Getenv
	}
	if a.Poll <= 0 {
		a.Poll = 2 * time.Second
	}
	err := a.dispatch(ctx, argv)
	if err == nil {
		return ExitOK
	}
	if errors.Is(err, flag.ErrHelp) {
		return ExitOK
	}
	fmt.Fprintln(a.Stderr, "falak:", err)
	var ue usageError
	var ee exitError
	switch {
	case errors.As(err, &ue):
		return ExitUsage
	case errors.As(err, &ee):
		return ee.code
	}
	return ExitError
}

func (a *App) dispatch(ctx context.Context, argv []string) error {
	// Leading global flags: falak --json servers list
	cmds := a.commands()
	gfs := a.flagSet("falak")
	showVersion := gfs.Bool("version", false, "print the version")
	gfs.Usage = func() { a.usage(a.Stderr, cmds, "") }
	if err := gfs.Parse(argv); err != nil {
		return a.flagErr(err)
	}
	if *showVersion {
		fmt.Fprintln(a.Stdout, a.Version)
		return nil
	}
	argv = gfs.Args()
	if len(argv) == 0 || argv[0] == "help" || argv[0] == "-h" || argv[0] == "--help" {
		a.usage(a.Stdout, cmds, "")
		if len(argv) == 0 {
			return usageError{"no command given"}
		}
		return nil
	}
	cmd, prefix, rest := find(cmds, argv)
	if cmd == nil {
		return usagef("unknown command %q (run `falak help`)", strings.Join(prefix, " "))
	}
	if cmd.run == nil { // group without subcommand → first sub (servers → servers list)
		if len(rest) > 0 && !strings.HasPrefix(rest[0], "-") {
			return usagef("unknown command %q", strings.Join(append(prefix, rest[0]), " "))
		}
		cmd = cmd.subs[0]
	}
	return cmd.run(ctx, a, rest)
}

func find(cmds []*command, argv []string) (*command, []string, []string) {
	var prefix []string
	var cur *command
	list := cmds
	for len(argv) > 0 {
		var next *command
		for _, c := range list {
			if c.name == argv[0] {
				next = c
			}
		}
		if next == nil {
			if cur == nil {
				return nil, []string{argv[0]}, nil
			}
			break
		}
		cur, prefix, argv, list = next, append(prefix, next.name), argv[1:], next.subs
		if len(list) == 0 {
			break
		}
	}
	return cur, prefix, argv
}

func (a *App) usage(w io.Writer, cmds []*command, _ string) {
	fmt.Fprintln(w, "falak — Falak control-plane CLI")
	fmt.Fprintln(w, "\nusage: falak [--json] [--url URL] [--token TOKEN] <command> [args]")
	fmt.Fprintln(w, "\ncommands:")
	tw := tabwriter.NewWriter(w, 0, 0, 2, ' ', 0)
	for _, c := range cmds {
		if len(c.subs) > 0 {
			for _, s := range c.subs {
				fmt.Fprintf(tw, "  %s %s %s\t%s\n", c.name, s.name, s.args, s.summary)
			}
			continue
		}
		fmt.Fprintf(tw, "  %s %s\t%s\n", c.name, c.args, c.summary)
	}
	tw.Flush()
	fmt.Fprintln(w, "\nenvironment: FALAK_URL, FALAK_TOKEN (override stored credentials; for CI), FALAK_CONFIG_DIR, FALAK_PROJECT (plan, apply, runs, drift, export)")
	fmt.Fprintln(w, "exit codes: 0 ok, 1 error, 2 usage, 3 deployment failed, 4 newer function version (fn deploy), 5 invalid falak.yaml, 6 waits for an approval (apply, approve), 7 drifted (drift)")
}

// flagSet returns a FlagSet with the global flags registered.
func (a *App) flagSet(name string) *flag.FlagSet {
	fs := flag.NewFlagSet(name, flag.ContinueOnError)
	fs.SetOutput(a.Stderr)
	fs.BoolVar(&a.jsonOut, "json", a.jsonOut, "print JSON")
	fs.StringVar(&a.baseURL, "url", a.baseURL, "control-plane URL (env FALAK_URL)")
	fs.StringVar(&a.token, "token", a.token, "API token (env FALAK_TOKEN)")
	return fs
}

func (a *App) flagErr(err error) error {
	if errors.Is(err, flag.ErrHelp) {
		return err
	}
	return usageError{err.Error()}
}

// parse parses flags interspersed with positionals ("falak deploy app --wait") and checks the
// positional count.
func (a *App) parse(fs *flag.FlagSet, args []string, minPos, maxPos int, synopsis string) ([]string, error) {
	fs.Usage = func() {
		fmt.Fprintf(a.Stderr, "usage: falak %s %s\n", fs.Name(), synopsis)
		fs.PrintDefaults()
	}
	var pos []string
	for {
		if err := fs.Parse(args); err != nil {
			return nil, a.flagErr(err)
		}
		args = fs.Args()
		if len(args) == 0 {
			break
		}
		pos = append(pos, args[0])
		args = args[1:]
	}
	if len(pos) < minPos || (maxPos >= 0 && len(pos) > maxPos) {
		return nil, usagef("usage: falak %s %s", fs.Name(), synopsis)
	}
	return pos, nil
}

// api resolves credentials (flags > env > stored) and returns the client.
func (a *App) api() (api.API, error) {
	if a.client != nil {
		return a.client, nil
	}
	creds, err := a.creds()
	if err != nil {
		return nil, err
	}
	if creds.URL == "" {
		return nil, errors.New("no control-plane URL: run `falak login --url https://falak.example.com` or set FALAK_URL")
	}
	if creds.Token == "" {
		return nil, errors.New("not logged in: run `falak login` or set FALAK_TOKEN")
	}
	a.client = a.newAPI(creds.URL, creds.Token)
	return a.client, nil
}

func (a *App) newAPI(url, token string) api.API {
	if a.NewAPI != nil {
		return a.NewAPI(url, token)
	}
	return api.New(url, token, "falak-cli/"+a.Version)
}

func (a *App) configDir() (string, error) {
	if a.cfgDir == "" {
		d, err := ConfigDir(a.Getenv)
		if err != nil {
			return "", err
		}
		a.cfgDir = d
	}
	return a.cfgDir, nil
}

func (a *App) creds() (Credentials, error) {
	dir, err := a.configDir()
	if err != nil {
		return Credentials{}, err
	}
	c, err := LoadCredentials(dir)
	if err != nil {
		return c, fmt.Errorf("read credentials: %w", err)
	}
	if v := a.Getenv("FALAK_URL"); v != "" {
		c.URL = v
	}
	if v := a.Getenv("FALAK_TOKEN"); v != "" {
		c.Token = v
	}
	if a.baseURL != "" {
		c.URL = a.baseURL
	}
	if a.token != "" {
		c.Token = a.token
	}
	c.URL = strings.TrimRight(c.URL, "/")
	return c, nil
}

// printJSON writes v as indented JSON.
func (a *App) printJSON(v any) error {
	enc := json.NewEncoder(a.Stdout)
	enc.SetIndent("", "  ")
	return enc.Encode(v)
}

// table prints rows with a header via tabwriter.
func (a *App) table(header []string, rows [][]string) {
	tw := tabwriter.NewWriter(a.Stdout, 0, 0, 2, ' ', 0)
	fmt.Fprintln(tw, strings.Join(header, "\t"))
	for _, r := range rows {
		fmt.Fprintln(tw, strings.Join(r, "\t"))
	}
	tw.Flush()
}

// kv prints aligned key/value pairs, skipping empty values.
func (a *App) kv(pairs [][2]string) {
	tw := tabwriter.NewWriter(a.Stdout, 0, 0, 2, ' ', 0)
	for _, p := range pairs {
		if p[1] != "" {
			fmt.Fprintf(tw, "%s:\t%s\n", p[0], p[1])
		}
	}
	tw.Flush()
}

func dash(s string) string {
	if s == "" {
		return "-"
	}
	return s
}
