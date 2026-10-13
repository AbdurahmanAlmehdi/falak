package cli

import (
	"bufio"
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/cli/api"
)

func cmdLogin(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("login")
	fromStdin := fs.Bool("token-stdin", false, "read the token from stdin")
	noBrowser := fs.Bool("no-browser", false, "print the sign-in link instead of opening a browser")
	if _, err := a.parse(fs, args, 0, 0, "[--url URL] [--token TOKEN | --token-stdin] [--no-browser]"); err != nil {
		return err
	}
	creds, err := a.creds()
	if err != nil {
		return err
	}
	in := bufio.NewReader(a.Stdin)
	if creds.URL == "" {
		if !a.Interactive {
			return usagef("--url (or FALAK_URL) is required")
		}
		fmt.Fprint(a.Stderr, "Control-plane URL: ")
		line, _ := in.ReadString('\n')
		creds.URL = strings.TrimRight(strings.TrimSpace(line), "/")
	}
	if !strings.HasPrefix(creds.URL, "https://") && !strings.HasPrefix(creds.URL, "http://") {
		return usagef("url must start with https:// (got %q)", creds.URL)
	}
	noToken := a.token == "" && a.Getenv("FALAK_TOKEN") == ""
	switch {
	case *fromStdin || (noToken && !a.Interactive): // scripts pipe the token in
		line, err := in.ReadString('\n')
		if err != nil && !errors.Is(err, io.EOF) {
			return err
		}
		creds.Token = strings.TrimSpace(line)
	case noToken:
		// On a terminal without a token: sign in through the browser.
		if creds.Token, err = a.browserLogin(ctx, creds.URL, !*noBrowser); err != nil {
			return err
		}
	}
	if creds.Token == "" {
		return usagef("no token given")
	}
	me, err := a.newAPI(creds.URL, creds.Token).Me(ctx)
	if err != nil {
		return fmt.Errorf("verify token: %w", err)
	}
	creds.User, creds.Organization = me.User.Email, me.Organization.Name
	dir, err := a.configDir()
	if err != nil {
		return err
	}
	if err := SaveCredentials(dir, creds); err != nil {
		return fmt.Errorf("save credentials: %w", err)
	}
	if a.jsonOut {
		return a.printJSON(map[string]any{"url": creds.URL, "user": me.User, "organization": me.Organization})
	}
	fmt.Fprintf(a.Stdout, "Logged in to %s as %s (organization %s)\n", creds.URL, me.User.Email, me.Organization.Name)
	return nil
}

func cmdLogout(ctx context.Context, a *App, args []string) error {
	if _, err := a.parse(a.flagSet("logout"), args, 0, 0, ""); err != nil {
		return err
	}
	dir, err := a.configDir()
	if err != nil {
		return err
	}
	stored, err := LoadCredentials(dir)
	if err != nil {
		return err
	}
	note := a.revokeStoredToken(ctx, stored)
	if err := DeleteCredentials(dir); err != nil {
		return err
	}
	fmt.Fprintln(a.Stdout, "Logged out"+note)
	return nil
}

func cmdWhoami(ctx context.Context, a *App, args []string) error {
	if _, err := a.parse(a.flagSet("whoami"), args, 0, 0, ""); err != nil {
		return err
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	me, err := c.Me(ctx)
	if err != nil {
		return err
	}
	if a.jsonOut {
		return a.printJSON(me)
	}
	pairs := [][2]string{{"User", me.User.Name + " <" + me.User.Email + ">"}, {"Organization", me.Organization.Name}, {"Role", me.Organization.Role}}
	if me.Token != nil {
		pairs = append(pairs, [2]string{"Token", me.Token.Name}, [2]string{"Abilities", strings.Join(me.Token.Abilities, ", ")})
	}
	a.kv(pairs)
	return nil
}

func cmdOrgs(ctx context.Context, a *App, args []string) error {
	if _, err := a.parse(a.flagSet("orgs"), args, 0, 0, ""); err != nil {
		return err
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	orgs, err := c.Organizations(ctx)
	if err != nil {
		return err
	}
	if a.jsonOut {
		return a.printJSON(orgs)
	}
	rows := make([][]string, 0, len(orgs))
	for _, o := range orgs {
		rows = append(rows, []string{o.ID, o.Name, dash(o.Slug), dash(o.Role)})
	}
	a.table([]string{"ID", "NAME", "SLUG", "ROLE"}, rows)
	return nil
}

func cmdServersList(ctx context.Context, a *App, args []string) error {
	if _, err := a.parse(a.flagSet("servers list"), args, 0, 0, ""); err != nil {
		return err
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	servers, err := c.Servers(ctx)
	if err != nil {
		return err
	}
	if a.jsonOut {
		return a.printJSON(servers)
	}
	rows := make([][]string, 0, len(servers))
	for _, s := range servers {
		agent := "-"
		if s.Agent != nil {
			agent = s.Agent.Status
		}
		rows = append(rows, []string{s.ID, s.Name, s.Type, s.Status, agent, dash(s.IPv4), dash(s.Provider), dash(s.PHP)})
	}
	a.table([]string{"ID", "NAME", "TYPE", "STATUS", "AGENT", "IPV4", "PROVIDER", "PHP"}, rows)
	return nil
}

func cmdServersShow(ctx context.Context, a *App, args []string) error {
	pos, err := a.parse(a.flagSet("servers show"), args, 1, 1, "<server>")
	if err != nil {
		return err
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	s, err := resolveServer(ctx, c, pos[0])
	if err != nil {
		return err
	}
	if a.jsonOut {
		return a.printJSON(s)
	}
	pairs := [][2]string{{"ID", s.ID}, {"Name", s.Name}, {"Type", s.Type}, {"Status", s.Status}, {"Message", s.StatusMessage},
		{"Provider", s.Provider}, {"Region", s.Region}, {"IPv4", s.IPv4}, {"Private IPv4", s.PrivateIPv4}, {"PHP", s.PHP}}
	if s.Agent != nil {
		pairs = append(pairs, [2]string{"Agent", s.Agent.Status + " (last heartbeat " + dash(s.Agent.LastHeartbeatAt) + ")"})
	}
	pct := func(p *float64) string {
		if p == nil {
			return ""
		}
		return strconv.FormatFloat(*p, 'f', 1, 64) + "%"
	}
	if s.Load1 != nil {
		pairs = append(pairs, [2]string{"Load (1m)", strconv.FormatFloat(*s.Load1, 'f', 2, 64)})
	}
	pairs = append(pairs, [2]string{"Memory", pct(s.MemoryPercent)}, [2]string{"Disk", pct(s.DiskPercent)}, [2]string{"Created", s.CreatedAt})
	a.kv(pairs)
	return nil
}

func cmdServersStack(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("servers stack")
	php := fs.String("php", "", "add PHP: the version (e.g. 8.4)")
	runtime := fs.String("php-runtime", "", "fpm or frankenphp on site servers, cli on builders (default: cli on builders, else fpm)")
	node := fs.String("node", "", "add Node.js: the major version (e.g. 22)")
	pos, err := a.parse(fs, args, 1, 1, "<server> [--php VERSION] [--php-runtime fpm|frankenphp|cli] [--node VERSION]")
	if err != nil {
		return err
	}
	if *php == "" && *runtime == "" && *node == "" {
		return usageError{"name a runtime to add: --php and/or --node"}
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	s, err := resolveServer(ctx, c, pos[0])
	if err != nil {
		return err
	}
	req := api.StackRequest{Node: *node}
	if *php != "" || *runtime != "" {
		rt := *runtime
		if rt == "" {
			rt = "fpm"
			if s.Type == "builder" {
				rt = "cli"
			}
		}
		req.PHP = &api.StackPHP{Runtime: rt, Version: *php}
	}
	ch, err := c.AddServerRuntimes(ctx, s.ID, req)
	if err != nil {
		return err
	}
	if a.jsonOut {
		return a.printJSON(ch)
	}
	var added []string
	if p := ch.Stack.PHP; p != nil && req.PHP != nil {
		added = append(added, "PHP "+strings.Join(p.Versions, ", ")+" ("+p.Runtime+")")
	}
	if req.Node != "" {
		added = append(added, "Node.js "+ch.Stack.Node)
	}
	fmt.Fprintf(a.Stdout, "%s: added %s; re-provisioning to install it (%s)\n", s.Name, strings.Join(added, " and "), ch.Status)
	return nil
}

// resolveServer accepts an id or a (unique) name.
func resolveServer(ctx context.Context, c api.API, ref string) (api.Server, error) {
	s, err := c.Server(ctx, ref)
	if err == nil || !api.IsNotFound(err) {
		return s, err
	}
	all, lerr := c.Servers(ctx)
	if lerr != nil {
		return s, lerr
	}
	var hits []api.Server
	for _, x := range all {
		if strings.EqualFold(x.Name, ref) {
			hits = append(hits, x)
		}
	}
	switch len(hits) {
	case 0:
		return s, fmt.Errorf("server %q not found", ref)
	case 1:
		return hits[0], nil
	}
	return s, fmt.Errorf("server name %q is ambiguous (%d matches); use the id", ref, len(hits))
}

func cmdSitesList(ctx context.Context, a *App, args []string) error {
	if _, err := a.parse(a.flagSet("sites list"), args, 0, 0, ""); err != nil {
		return err
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	sites, err := c.Sites(ctx)
	if err != nil {
		return err
	}
	if a.jsonOut {
		return a.printJSON(sites)
	}
	rows := make([][]string, 0, len(sites))
	for _, s := range sites {
		rows = append(rows, []string{s.ID, s.Slug, dash(s.Domain), dash(s.Runtime), dash(s.Status), dash(s.Branch)})
	}
	a.table([]string{"ID", "SLUG", "DOMAIN", "RUNTIME", "STATUS", "BRANCH"}, rows)
	return nil
}

func cmdSitesShow(ctx context.Context, a *App, args []string) error {
	pos, err := a.parse(a.flagSet("sites show"), args, 1, 1, "<site>")
	if err != nil {
		return err
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	s, err := c.Site(ctx, pos[0])
	if err != nil {
		return err
	}
	if a.jsonOut {
		return a.printJSON(s)
	}
	pairs := [][2]string{{"ID", s.ID}, {"Slug", s.Slug}, {"Name", s.Name}, {"Domain", s.Domain}, {"Aliases", strings.Join(s.Aliases, ", ")},
		{"URL", siteURL(s)}, {"Runtime", s.Runtime}, {"Build mode", s.BuildMode}, {"Strategy", s.Strategy}, {"Status", s.Status},
		{"Repository", s.Repository}, {"Branch", s.Branch}, {"Servers", strings.Join(s.ServerIDs, ", ")}}
	if r := s.CurrentRelease; r != nil {
		pairs = append(pairs, [2]string{"Release", r.ID + " (" + shortSHA(r.Commit) + ")"})
	}
	pairs = append(pairs, [2]string{"Created", s.CreatedAt})
	a.kv(pairs)
	return nil
}

func cmdDeploy(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("deploy")
	branch := fs.String("branch", "", "branch to deploy (default: the site's branch)")
	wait := fs.Bool("wait", false, "stream output and wait for the deployment to finish")
	pos, err := a.parse(fs, args, 1, 1, "<site> [--branch BRANCH] [--wait]")
	if err != nil {
		return err
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	d, err := c.Deploy(ctx, pos[0], *branch)
	if err != nil {
		return err
	}
	return a.afterTrigger(ctx, c, "Deployment", pos[0], d, *wait)
}

func cmdRollback(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("rollback")
	release := fs.String("release", "", "release id to roll back to (default: the previous release)")
	wait := fs.Bool("wait", false, "stream output and wait for the rollback to finish")
	pos, err := a.parse(fs, args, 1, 1, "<site> [--release ID] [--wait]")
	if err != nil {
		return err
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	d, err := c.Rollback(ctx, pos[0], *release)
	if err != nil {
		return err
	}
	return a.afterTrigger(ctx, c, "Rollback", pos[0], d, *wait)
}

func (a *App) afterTrigger(ctx context.Context, c api.API, what, site string, d api.Deployment, wait bool) error {
	if !wait {
		if a.jsonOut {
			return a.printJSON(d)
		}
		fmt.Fprintf(a.Stdout, "%s %s %s for %s\n", what, d.ID, dash(d.Status), site)
		if d.Status == "waiting" && d.WaitingReason != "" {
			fmt.Fprintf(a.Stdout, "%s; it starts automatically once they are ready\n", d.WaitingReason)
		}
		if d.URL != "" {
			fmt.Fprintln(a.Stdout, d.URL)
		}
		return nil
	}
	// With --json, output lines go to stderr and the final deployment JSON to stdout.
	out := a.Stdout
	if a.jsonOut {
		out = a.Stderr
	} else {
		fmt.Fprintf(out, "%s %s started for %s\n", what, d.ID, site)
	}
	final, err := a.follow(ctx, c, d, out)
	if err != nil {
		return err
	}
	if a.jsonOut {
		if err := a.printJSON(final); err != nil {
			return err
		}
	} else {
		fmt.Fprintf(out, "%s %s %s\n", what, final.ID, final.Status)
	}
	if !final.Succeeded() {
		msg := fmt.Sprintf("%s %s %s", strings.ToLower(what), final.ID, final.Status)
		if final.Error != "" {
			msg += ": " + final.Error
		}
		return exitError{ExitDeployFailed, errors.New(msg)}
	}
	return nil
}

// follow polls output + status until the deployment is done.
func (a *App) follow(ctx context.Context, c api.API, d api.Deployment, out io.Writer) (api.Deployment, error) {
	var after int64
	errs := 0
	for {
		lines, next, err := c.DeploymentOutput(ctx, d.ID, after)
		if err == nil {
			after = next
			for _, l := range lines {
				printOutputLine(out, l)
			}
			d, err = c.Deployment(ctx, d.ID)
		}
		if err != nil {
			if ctx.Err() != nil {
				return d, ctx.Err()
			}
			// Transient API errors: keep polling a while.
			if errs++; errs > 5 {
				return d, err
			}
			fmt.Fprintf(a.Stderr, "falak: %v (retrying)\n", err)
		} else {
			errs = 0
			if d.Done() {
				// Drain output written between the last output poll and the terminal status.
				if lines, _, err := c.DeploymentOutput(ctx, d.ID, after); err == nil {
					for _, l := range lines {
						printOutputLine(out, l)
					}
				}
				return d, nil
			}
		}
		select {
		case <-ctx.Done():
			return d, ctx.Err()
		case <-time.After(a.Poll):
		}
	}
}

func printOutputLine(w io.Writer, l api.OutputLine) {
	prefix := ""
	switch {
	case l.Server != "" && l.Phase != "":
		prefix = "[" + l.Server + " " + l.Phase + "] "
	case l.Server != "":
		prefix = "[" + l.Server + "] "
	case l.Phase != "":
		prefix = "[" + l.Phase + "] "
	}
	for _, line := range strings.Split(strings.TrimRight(l.Data, "\n"), "\n") {
		fmt.Fprintln(w, prefix+line)
	}
}

func cmdReleases(ctx context.Context, a *App, args []string) error {
	pos, err := a.parse(a.flagSet("releases"), args, 1, 1, "<site>")
	if err != nil {
		return err
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	rels, err := c.Releases(ctx, pos[0])
	if err != nil {
		return err
	}
	if a.jsonOut {
		return a.printJSON(rels)
	}
	rows := make([][]string, 0, len(rels))
	for _, r := range rels {
		active := ""
		if r.Active {
			active = "*"
		}
		rows = append(rows, []string{active, r.ID, shortSHA(r.Commit), dash(r.Branch), dash(r.CreatedAt)})
	}
	a.table([]string{"", "ID", "COMMIT", "BRANCH", "CREATED"}, rows)
	return nil
}

func cmdEnvPull(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("env pull")
	file := fs.String("file", "", "write to this file (mode 0600) instead of stdout")
	pos, err := a.parse(fs, args, 1, 1, "<site> [--file PATH]")
	if err != nil {
		return err
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	content, err := c.EnvPull(ctx, pos[0])
	if err != nil {
		return err
	}
	if a.jsonOut {
		return a.printJSON(api.EnvFile{Content: content})
	}
	if *file == "" {
		_, err := io.WriteString(a.Stdout, content)
		return err
	}
	if err := os.WriteFile(*file, []byte(content), 0o600); err != nil {
		return err
	}
	fmt.Fprintf(a.Stderr, "Wrote %s (%d variables)\n", *file, countVars(content))
	return nil
}

func cmdEnvPush(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("env push")
	file := fs.String("file", "", "read from this file instead of stdin")
	pos, err := a.parse(fs, args, 1, 1, "<site> [--file PATH]")
	if err != nil {
		return err
	}
	var b []byte
	if *file != "" {
		b, err = os.ReadFile(*file)
	} else {
		b, err = io.ReadAll(a.Stdin)
	}
	if err != nil {
		return err
	}
	if len(strings.TrimSpace(string(b))) == 0 {
		return usagef("refusing to push an empty .env")
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	if err := c.EnvPush(ctx, pos[0], string(b)); err != nil {
		return err
	}
	if a.jsonOut {
		return a.printJSON(map[string]any{"site": pos[0], "variables": countVars(string(b))})
	}
	fmt.Fprintf(a.Stdout, "Pushed %d variables to %s (redeploy to apply)\n", countVars(string(b)), pos[0])
	return nil
}

var envLineRe = regexp.MustCompile(`^\s*(export\s+)?[A-Za-z_][A-Za-z0-9_]*\s*=`)

func countVars(content string) int {
	n := 0
	for _, l := range strings.Split(content, "\n") {
		if envLineRe.MatchString(l) {
			n++
		}
	}
	return n
}

func cmdLogs(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("logs")
	followF := fs.Bool("follow", false, "keep polling for new lines")
	fs.BoolVar(followF, "f", false, "shorthand for --follow")
	since := fs.Duration("since", time.Hour, "how far back to start")
	limit := fs.Int("limit", 200, "max lines per request")
	level := fs.String("level", "", "minimum level (debug|info|warning|error)")
	pos, err := a.parse(fs, args, 1, 1, "<site> [--follow] [--since 1h] [--limit N] [--level LEVEL]")
	if err != nil {
		return err
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	q := api.LogQuery{Since: *since, Limit: *limit, Level: *level}
	for {
		entries, cursor, err := c.Logs(ctx, pos[0], q)
		if err != nil {
			if ctx.Err() != nil {
				return nil
			}
			return err
		}
		for _, e := range entries {
			if a.jsonOut {
				// NDJSON: one entry per line, pipe-friendly under --follow.
				if err := writeJSONLine(a.Stdout, e); err != nil {
					return err
				}
				continue
			}
			printLogEntry(a.Stdout, e)
		}
		if !*followF {
			return nil
		}
		if cursor != "" {
			q.Cursor = cursor
		}
		q.Since = 0
		select {
		case <-ctx.Done():
			return nil
		case <-time.After(a.Poll):
		}
	}
}

func printLogEntry(w io.Writer, e api.LogEntry) {
	parts := []string{e.At}
	if e.Level != "" {
		parts = append(parts, strings.ToUpper(e.Level))
	}
	src := e.Source
	if e.Server != "" {
		src = strings.Trim(e.Server+"/"+src, "/")
	}
	if src != "" {
		parts = append(parts, "["+src+"]")
	}
	parts = append(parts, strings.TrimRight(e.Message, "\n"))
	fmt.Fprintln(w, strings.Join(parts, " "))
}

func cmdSSH(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("ssh")
	user := fs.String("user", "", "remote user (default: the server's ssh_user, else falak)")
	private := fs.Bool("private", false, "connect to the private IPv4")
	var extra []string
	for i, x := range args {
		if x == "--" {
			args, extra = args[:i], args[i+1:]
			break
		}
	}
	pos, err := a.parse(fs, args, 1, 1, "<server> [--user USER] [-- ssh args]")
	if err != nil {
		return err
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	s, err := resolveServer(ctx, c, pos[0])
	if err != nil {
		return err
	}
	host := s.IPv4
	if *private {
		host = s.PrivateIPv4
	}
	if host == "" {
		return fmt.Errorf("server %s has no address yet (status %s)", s.Name, s.Status)
	}
	u := firstNonEmpty(*user, s.SSHUser, "falak")
	argv := []string{"ssh"}
	if s.SSHPort != 0 && s.SSHPort != 22 {
		argv = append(argv, "-p", strconv.Itoa(s.SSHPort))
	}
	argv = append(argv, u+"@"+host)
	argv = append(argv, extra...)
	if a.Exec == nil {
		return errors.New("ssh is not available on this platform")
	}
	return a.Exec(argv)
}

func cmdOpen(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("open")
	panel := fs.Bool("panel", false, "open the site's page in the control panel instead")
	pos, err := a.parse(fs, args, 1, 1, "<site> [--panel]")
	if err != nil {
		return err
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	s, err := c.Site(ctx, pos[0])
	if err != nil {
		return err
	}
	target := siteURL(s)
	if *panel {
		creds, _ := a.creds()
		target = creds.URL + "/sites/" + s.ID
	}
	if target == "" {
		return fmt.Errorf("site %s has no domain", s.Slug)
	}
	if a.jsonOut {
		return a.printJSON(map[string]string{"url": target})
	}
	fmt.Fprintln(a.Stdout, target)
	if a.Open == nil {
		return nil
	}
	return a.Open(target)
}

func siteURL(s api.Site) string {
	if s.URL != "" {
		return s.URL
	}
	if s.Domain != "" {
		return "https://" + s.Domain
	}
	return ""
}

func shortSHA(s string) string {
	if len(s) > 7 {
		return s[:7]
	}
	return dash(s)
}

func firstNonEmpty(v ...string) string {
	for _, s := range v {
		if s != "" {
			return s
		}
	}
	return ""
}
