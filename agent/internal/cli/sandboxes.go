package cli

import (
	"context"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/cli/api"
	"github.com/OthmanHaba/falak/agent/internal/relay"
)

const sandboxCreateSynopsis = "[--image I] [--name N] [--server S] [--cpus N] [--memory 1g] [--disk 10g] [--ttl 1h] [--network none|internet|allowlist] [--allow HOST]… [--env K=V]… [--repo URL[@REF]] [--repo-secret NAME] [--wait=false]"

func sandboxCommands() *command {
	return &command{name: "sandbox", summary: "disposable gVisor sandboxes for untrusted code", subs: []*command{
		{name: "ls", args: "[--status S]", summary: "list sandboxes", run: cmdSandboxList},
		{name: "create", args: sandboxCreateSynopsis, summary: "create a sandbox (waits until it runs)", run: cmdSandboxCreate},
		{name: "show", args: "<sandbox>", summary: "show one sandbox", run: cmdSandboxShow},
		{name: "exec", args: "<sandbox> [--timeout 60s] [--cwd DIR] [-e K=V]… -- <cmd…>", summary: "run a command in a sandbox (exits with its code)", run: cmdSandboxExec},
		{name: "cp", args: "<src> <dst>  (sbx:<sandbox>:/path on one side; - for stdin/stdout)", summary: "copy a file into or out of a sandbox", run: cmdSandboxCp},
		{name: "ssh", args: "<sandbox>", summary: "open a shell in a sandbox (through the terminal relay)", run: cmdSandboxSSH},
		{name: "extend", args: "<sandbox> <30m>", summary: "add time to a sandbox's limit", run: cmdSandboxExtend},
		{name: "kill", args: "<sandbox>…", summary: "kill sandboxes (their files are gone)", run: cmdSandboxKill},
	}}
}

var sandboxIDRe = regexp.MustCompile(`^[0-9a-z]{26}$`)

// resolveSandbox turns an id or a name into an id. Ids are used as is (no request); names are looked up in the list,
// running ones first.
func resolveSandbox(ctx context.Context, c api.API, ref string) (string, error) {
	if sandboxIDRe.MatchString(ref) { // ponytail: a 26-char lower-case alnum name reads as an id
		return ref, nil
	}
	all, err := c.Sandboxes(ctx, "")
	if err != nil {
		return "", err
	}
	var hits, live []api.Sandbox
	for _, s := range all {
		if s.Name == ref {
			hits = append(hits, s)
			if s.Status == "running" || s.Status == "creating" {
				live = append(live, s)
			}
		}
	}
	if len(live) > 0 {
		hits = live
	}
	switch len(hits) {
	case 0:
		return "", fmt.Errorf("sandbox %q not found", ref)
	case 1:
		return hits[0].ID, nil
	}
	return "", fmt.Errorf("sandbox name %q is ambiguous (%d matches); use the id", ref, len(hits))
}

// parseSeconds reads a duration: seconds ("3600") or "30m", "2h", "1h30m".
func parseSeconds(s string) (int64, error) {
	if n, err := strconv.ParseInt(s, 10, 64); err == nil && n > 0 {
		return n, nil
	}
	if n, ok := durationSeconds(s); ok && n > 0 {
		return n, nil
	}
	return 0, fmt.Errorf("invalid duration %q (use 30m, 2h, 1h30m or seconds)", s)
}

// parseMB reads a size in MB: "512" (MB), "512m", "2g", "1.5GiB".
func parseMB(s string) (int, error) {
	if n, err := strconv.Atoi(s); err == nil && n > 0 {
		return n, nil
	}
	if b, ok := sizeBytes(s); ok && b >= mib {
		return int(b / mib), nil
	}
	return 0, fmt.Errorf("invalid size %q (use 512m, 2g or MB)", s)
}

// parseEnv turns K=V pairs into a map.
func parseEnv(pairs []string) (map[string]string, error) {
	if len(pairs) == 0 {
		return nil, nil
	}
	env := map[string]string{}
	for _, p := range pairs {
		k, v, ok := strings.Cut(p, "=")
		if !ok || k == "" {
			return nil, usagef("invalid env %q (use K=V)", p)
		}
		env[k] = v
	}
	return env, nil
}

// splitRepo splits URL@REF. The last @ starts a ref when what precedes it is a full repository URL (has a path) and
// the ref has no ':' — so https://tok@host/o/r and git@host:o/r.git stay whole.
func splitRepo(s string) (string, string) {
	i := strings.LastIndex(s, "@")
	if i < 0 {
		return s, ""
	}
	base, ref := s[:i], s[i+1:]
	_, rest, found := strings.Cut(base, "://")
	if !found {
		rest = base
	}
	if ref == "" || strings.Contains(ref, ":") || !strings.Contains(rest, "/") {
		return s, ""
	}
	return base, ref
}

// expiresIn is the time left until an RFC 3339 instant, to the minute ("1h5m", "expired"; "-" when unknown).
func expiresIn(s api.Sandbox) string {
	if s.Status == "killed" || s.Status == "failed" {
		return "-"
	}
	t, err := time.Parse(time.RFC3339, s.ExpiresAt)
	if err != nil {
		return "-"
	}
	left := int64(time.Until(t) / time.Second)
	switch {
	case left <= 0:
		return "expired"
	case left >= 60:
		left -= left % 60
	}
	return humanDuration(left)
}

func cpus(n float64) string { return strconv.FormatFloat(n, 'f', -1, 64) }

func memory(mb int) string {
	if mb >= 1024 && mb%1024 == 0 {
		return strconv.Itoa(mb/1024) + "g"
	}
	return strconv.Itoa(mb) + "m"
}

func serverName(s api.Sandbox) string {
	if s.Server == nil {
		return "-"
	}
	return dash(s.Server.Name)
}

func cmdSandboxList(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("sandbox ls")
	status := fs.String("status", "", "only sandboxes with this status (creating, running, failed, killed)")
	if _, err := a.parse(fs, args, 0, 0, "[--status S]"); err != nil {
		return err
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	list, err := c.Sandboxes(ctx, *status)
	if err != nil {
		return err
	}
	if a.jsonOut {
		return a.printJSON(list)
	}
	rows := make([][]string, 0, len(list))
	for _, s := range list {
		rows = append(rows, []string{s.Name, s.Status, s.Image, serverName(s), cpus(s.CPUs), memory(s.MemoryMB), expiresIn(s)})
	}
	a.table([]string{"NAME", "STATUS", "IMAGE", "SERVER", "CPU", "MEM", "EXPIRES IN"}, rows)
	return nil
}

func cmdSandboxCreate(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("sandbox create")
	var r api.SandboxCreateRequest
	fs.StringVar(&r.Image, "image", "", "container image (default ubuntu:24.04)")
	fs.StringVar(&r.Name, "name", "", "name (default: generated, e.g. brave-otter)")
	fs.StringVar(&r.Server, "server", "", "server to run on, name or id (default: the one with the most free memory)")
	fs.Float64Var(&r.CPUs, "cpus", 0, "CPUs (default 1)")
	mem := fs.String("memory", "", "memory, e.g. 512m or 2g (default 1g)")
	disk := fs.String("disk", "", "disk for the sandbox's writes, e.g. 10g (default 10g)")
	ttl := fs.String("ttl", "", "time limit, e.g. 30m or 2h (default 1h)")
	fs.StringVar(&r.Network, "network", "", "none, internet (default) or allowlist")
	var allow, env headerFlags
	fs.Var(&allow, "allow", "a host the allowlist network reaches (repeatable)")
	fs.Var(&env, "env", "environment variable K=V (repeatable)")
	repo := fs.String("repo", "", "git repository to clone, URL[@REF]")
	repoSecret := fs.String("repo-secret", "", "name of the secret holding the clone token")
	wait := fs.Bool("wait", true, "wait until it runs (up to 60 s)")
	if _, err := a.parse(fs, args, 0, 0, sandboxCreateSynopsis); err != nil {
		return err
	}
	var err error
	if *mem != "" {
		if r.MemoryMB, err = parseMB(*mem); err != nil {
			return usageError{err.Error()}
		}
	}
	if *disk != "" {
		if r.DiskMB, err = parseMB(*disk); err != nil {
			return usageError{err.Error()}
		}
	}
	if *ttl != "" {
		if r.TTL, err = parseSeconds(*ttl); err != nil {
			return usageError{err.Error()}
		}
	}
	switch r.Network {
	case "", "none", "internet", "allowlist":
	default:
		return usagef("--network must be none, internet or allowlist")
	}
	if len(allow) > 0 && r.Network != "allowlist" {
		return usagef("--allow needs --network allowlist")
	}
	r.AllowHosts = allow
	if r.Env, err = parseEnv(env); err != nil {
		return err
	}
	if *repo != "" {
		u, ref := splitRepo(*repo)
		r.Repo = &api.SandboxRepo{URL: u, Ref: ref, Secret: *repoSecret}
	} else if *repoSecret != "" {
		return usagef("--repo-secret needs --repo")
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	s, err := c.CreateSandbox(ctx, r, *wait)
	if err != nil {
		return err
	}
	if a.jsonOut {
		return a.printJSON(s)
	}
	if s.Status == "failed" {
		reason := "no reason given"
		if s.KillReason != nil {
			reason = *s.KillReason
		}
		return fmt.Errorf("sandbox %s failed: %s", s.Name, reason)
	}
	fmt.Fprintf(a.Stdout, "%s (%s): %s on %s, %s, expires in %s\n", s.Name, s.ID, s.Status, serverName(s), s.Image, expiresIn(s))
	fmt.Fprintf(a.Stdout, "  falak sandbox exec %s -- uname -a\n  falak sandbox ssh %s\n  falak sandbox kill %s\n", s.Name, s.Name, s.Name)
	return nil
}

func cmdSandboxShow(ctx context.Context, a *App, args []string) error {
	pos, err := a.parse(a.flagSet("sandbox show"), args, 1, 1, "<sandbox>")
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
	s, err := c.Sandbox(ctx, id)
	if err != nil {
		return err
	}
	if a.jsonOut {
		return a.printJSON(s)
	}
	by, reason := "", ""
	if s.CreatedBy != nil {
		by = s.CreatedBy.Name + " (" + s.CreatedBy.Type + ")"
	}
	if s.KillReason != nil {
		reason = *s.KillReason
	}
	a.kv([][2]string{
		{"id", s.ID}, {"name", s.Name}, {"status", s.Status}, {"image", s.Image}, {"server", serverName(s)},
		{"cpus", cpus(s.CPUs)}, {"memory", memory(s.MemoryMB)}, {"disk", memory(s.DiskMB)},
		{"network", s.Network + strings.TrimSuffix(" "+strings.Join(s.AllowHosts, ", "), " ")}, {"ip", s.IP},
		{"expires", s.ExpiresAt + " (in " + expiresIn(s) + ")"}, {"created", s.CreatedAt}, {"created by", by},
		{"kill reason", reason},
	})
	return nil
}

// ExitTimeout: `falak sandbox exec` hit its --timeout (as timeout(1)).
const ExitTimeout = 124

func cmdSandboxExec(ctx context.Context, a *App, args []string) error {
	const synopsis = "<sandbox> [--timeout 60s] [--cwd DIR] [-e K=V]… -- <cmd…>"
	fs := a.flagSet("sandbox exec")
	timeout := fs.String("timeout", "", "time limit, up to 120s (default 60s)")
	cwd := fs.String("cwd", "", "working directory")
	var env headerFlags
	fs.Var(&env, "e", "environment variable K=V (repeatable)")
	var cmd []string
	for i, x := range args {
		if x == "--" {
			args, cmd = args[:i], args[i+1:]
			break
		}
	}
	pos, err := a.parse(fs, args, 1, 1, synopsis)
	if err != nil {
		return err
	}
	if len(cmd) == 0 {
		return usagef("usage: falak sandbox exec %s", synopsis)
	}
	r := api.SandboxExecRequest{Cwd: *cwd}
	// One argument is a shell line (sh -lc); several are argv, run without a shell.
	if len(cmd) == 1 {
		r.Cmd = cmd[0]
	} else {
		r.Cmd = cmd
	}
	if *timeout != "" {
		n, err := parseSeconds(*timeout)
		if err != nil || n > 120 {
			return usagef("--timeout must be 1s to 120s (run longer jobs in the background: nohup … > log &)")
		}
		r.Timeout = int(n)
	}
	if r.Env, err = parseEnv(env); err != nil {
		return err
	}
	if !a.Interactive && a.Stdin != nil { // piped stdin goes to the command
		b, err := io.ReadAll(io.LimitReader(a.Stdin, 1<<20+1))
		if err != nil {
			return err
		}
		if len(b) > 1<<20 {
			return errors.New("stdin is over 1 MB: copy it in with `falak sandbox cp` instead")
		}
		r.Stdin = string(b)
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	id, err := resolveSandbox(ctx, c, pos[0])
	if err != nil {
		return err
	}
	res, err := c.SandboxExec(ctx, id, r)
	if err != nil {
		return err
	}
	if a.jsonOut {
		if err := a.printJSON(res); err != nil {
			return err
		}
	} else {
		io.WriteString(a.Stdout, res.Stdout)
		io.WriteString(a.Stderr, res.Stderr)
		if res.Truncated {
			fmt.Fprintln(a.Stderr, "falak: output truncated (256 KB per stream); redirect to a file in the sandbox and copy it out")
		}
	}
	switch {
	case res.TimedOut:
		if !a.jsonOut {
			fmt.Fprintln(a.Stderr, "falak: timed out")
		}
		return exitError{code: ExitTimeout}
	case res.ExitCode == 0:
		return nil
	case res.ExitCode < 0 || res.ExitCode > 255:
		return exitError{code: ExitError}
	}
	return exitError{code: res.ExitCode}
}

// sandboxPath parses sbx:<sandbox>:/abs/path.
func sandboxPath(s string) (ref, p string, ok bool, err error) {
	rest, isSbx := strings.CutPrefix(s, "sbx:")
	if !isSbx {
		return "", "", false, nil
	}
	ref, p, _ = strings.Cut(rest, ":")
	if ref == "" || !strings.HasPrefix(p, "/") {
		return "", "", true, usagef("%q: use sbx:<sandbox>:/absolute/path", s)
	}
	return ref, p, true, nil
}

func cmdSandboxCp(ctx context.Context, a *App, args []string) error {
	const synopsis = "<src> <dst>  (sbx:<sandbox>:/path on one side; - for stdin/stdout)"
	pos, err := a.parse(a.flagSet("sandbox cp"), args, 2, 2, synopsis)
	if err != nil {
		return err
	}
	srcRef, srcPath, srcSbx, err := sandboxPath(pos[0])
	if err != nil {
		return err
	}
	dstRef, dstPath, dstSbx, err := sandboxPath(pos[1])
	if err != nil {
		return err
	}
	if srcSbx == dstSbx {
		return usagef("sandbox cp: exactly one side must be sbx:<sandbox>:/path")
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	if dstSbx { // upload
		var data []byte
		mode := ""
		if pos[0] == "-" {
			if data, err = io.ReadAll(io.LimitReader(a.Stdin, 5<<20+1)); err != nil {
				return err
			}
		} else {
			local := a.abs(pos[0])
			fi, err := os.Stat(local)
			if err != nil {
				return err
			}
			if fi.IsDir() {
				return errors.New("directories aren't supported yet: tar it (tar -czf dir.tgz dir), copy the file and untar it with falak sandbox exec")
			}
			if fi.Size() > 5<<20 {
				return errors.New("files over 5 MB aren't supported yet")
			}
			if data, err = os.ReadFile(local); err != nil {
				return err
			}
			mode = fmt.Sprintf("%04o", fi.Mode().Perm())
			if strings.HasSuffix(dstPath, "/") {
				dstPath += filepath.Base(local)
			}
		}
		if len(data) > 5<<20 {
			return errors.New("files over 5 MB aren't supported yet")
		}
		id, err := resolveSandbox(ctx, c, dstRef)
		if err != nil {
			return err
		}
		n, err := c.SandboxWriteFile(ctx, id, dstPath, mode, data)
		if err != nil {
			return err
		}
		if a.jsonOut {
			return a.printJSON(map[string]any{"path": dstPath, "size": n})
		}
		fmt.Fprintf(a.Stderr, "%s → %s:%s (%d bytes)\n", pos[0], dstRef, dstPath, n)
		return nil
	}
	id, err := resolveSandbox(ctx, c, srcRef)
	if err != nil {
		return err
	}
	data, truncated, err := c.SandboxReadFile(ctx, id, srcPath)
	if err != nil {
		var e *api.Error
		if errors.As(err, &e) && e.Status != http.StatusNotFound && strings.Contains(strings.ToLower(e.Message), "director") {
			return fmt.Errorf("%w: directories aren't supported yet; tar it with falak sandbox exec and copy the file", err)
		}
		return err
	}
	if truncated {
		return fmt.Errorf("%s is larger than 5 MB, the most a copy takes: nothing was written; split it in the sandbox (falak sandbox exec %s -- split -b 4M %s %s.part-) and copy the parts", srcPath, srcRef, srcPath, srcPath)
	}
	if pos[1] == "-" {
		_, err = a.Stdout.Write(data)
		return err
	}
	local := a.abs(pos[1])
	if fi, err := os.Stat(local); err == nil && fi.IsDir() {
		local = filepath.Join(local, path.Base(srcPath))
	}
	if err := os.WriteFile(local, data, 0o644); err != nil {
		return err
	}
	if a.jsonOut {
		return a.printJSON(map[string]any{"path": local, "size": len(data)})
	}
	fmt.Fprintf(a.Stderr, "%s → %s (%d bytes)\n", pos[0], local, len(data))
	return nil
}

func cmdSandboxSSH(ctx context.Context, a *App, args []string) error {
	pos, err := a.parse(a.flagSet("sandbox ssh"), args, 1, 1, "<sandbox>")
	if err != nil {
		return err
	}
	return a.sandboxConsole(ctx, pos[0])
}

// sandboxConsole opens a shell in a sandbox (`falak sandbox ssh`, `falak console --sandbox`).
func (a *App) sandboxConsole(ctx context.Context, ref string) error {
	c, err := a.api()
	if err != nil {
		return err
	}
	id, err := resolveSandbox(ctx, c, ref)
	if err != nil {
		return err
	}
	var req api.ConsoleRequest
	if a.Terminal != nil {
		if cols, rows, err := a.Terminal.Size(); err == nil && relay.ValidSize(cols, rows) {
			req.Cols, req.Rows = cols, rows
		}
	}
	sess, err := c.OpenSandboxConsole(ctx, id, req)
	if err != nil {
		return consoleErr(err)
	}
	return a.runConsole(ctx, c, sess)
}

func cmdSandboxExtend(ctx context.Context, a *App, args []string) error {
	pos, err := a.parse(a.flagSet("sandbox extend"), args, 2, 2, "<sandbox> <30m>")
	if err != nil {
		return err
	}
	by, err := parseSeconds(pos[1])
	if err != nil {
		return usageError{err.Error()}
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	id, err := resolveSandbox(ctx, c, pos[0])
	if err != nil {
		return err
	}
	s, err := c.ExtendSandbox(ctx, id, by)
	if err != nil {
		return err
	}
	if a.jsonOut {
		return a.printJSON(s)
	}
	fmt.Fprintf(a.Stdout, "%s expires in %s (%s)\n", s.Name, expiresIn(s), s.ExpiresAt)
	return nil
}

func cmdSandboxKill(ctx context.Context, a *App, args []string) error {
	pos, err := a.parse(a.flagSet("sandbox kill"), args, 1, -1, "<sandbox>…")
	if err != nil {
		return err
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	var errs []error
	for _, ref := range pos {
		id, err := resolveSandbox(ctx, c, ref)
		if err == nil {
			err = c.KillSandbox(ctx, id)
		}
		if err != nil {
			errs = append(errs, fmt.Errorf("%s: %w", ref, err))
			continue
		}
		fmt.Fprintf(a.Stdout, "killed %s\n", ref)
	}
	return errors.Join(errs...)
}
