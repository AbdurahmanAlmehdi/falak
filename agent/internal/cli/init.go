package cli

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"

	"github.com/OthmanHaba/falak/agent/internal/cli/api"
	"github.com/OthmanHaba/falak/agent/internal/configspec"
)

const initHead = "Written by `falak init` from the code (comments say why). Check it, then commit it.\nSchema and docs: " + configspec.SchemaID

type initOptions struct {
	full, force, yes, stdout, dockerfile bool
	project                              string
}

func cmdInit(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("init")
	var o initOptions
	fs.BoolVar(&o.full, "full", false, "also write default values")
	fs.BoolVar(&o.force, "force", false, "replace an existing falak.yaml")
	fs.BoolVar(&o.yes, "yes", false, "accept every default; ask nothing")
	fs.BoolVar(&o.stdout, "stdout", false, "print the file instead of writing it")
	fs.BoolVar(&o.dockerfile, "dockerfile", false, "write a starter Dockerfile for a language Falak doesn't build natively")
	projectFlag(fs, &o.project)
	pos, err := a.parse(fs, args, 0, 1, "[dir] [--yes] [--stdout] [--json] [--full] [--force] [--dockerfile]")
	if err != nil {
		return err
	}
	dir := a.workDir()
	if len(pos) == 1 {
		dir = a.abs(pos[0])
	}
	if st, err := os.Stat(dir); err != nil || !st.IsDir() {
		return fmt.Errorf("%s is not a directory", dir)
	}
	writing := !o.stdout && !a.jsonOut
	if writing {
		for _, n := range configFileNames {
			// Lstat: a symlink (even a dangling one) counts as existing, and is never written through.
			st, err := os.Lstat(filepath.Join(dir, n))
			if err != nil {
				continue
			}
			if st.Mode()&os.ModeSymlink != 0 {
				return fmt.Errorf("%s is a symlink: refusing to write through it", n)
			}
			if !o.force {
				return fmt.Errorf("%s already exists: use --force to replace it, or --stdout to compare", n)
			}
		}
	}

	d, err := detect(dir, detectOptions{dockerfile: o.dockerfile})
	if err != nil {
		return err
	}
	if d.Unsupported != nil {
		if a.jsonOut {
			_ = a.printJSON(d)
		}
		u := d.Unsupported
		return fmt.Errorf("%s (%s) isn't built natively by Falak and there is no Dockerfile: add one, or run `falak init --dockerfile` to write a starter one", langName(u.Language), u.Evidence)
	}
	if o.stdout && len(d.Dockerfiles) > 0 {
		return usagef("--stdout can't show the starter Dockerfiles --dockerfile writes: run without --stdout (or --json)")
	}

	p := &prompter{a: a, ask: a.Interactive && !o.yes && !a.jsonOut, in: bufio.NewReader(a.Stdin)}
	for _, off := range d.offers {
		label := map[string]string{"database": "database", "redis": "Redis/Valkey instance"}[off.unit.Type]
		if !p.confirm(fmt.Sprintf("Run %s (%s) as a Falak-managed %s?", off.service, off.image, label), true) {
			d.declineOffer(off)
		}
	}

	doc := newOmap()
	doc.set("$schema", configspec.SchemaID, "")
	doc.set("version", 1, "")
	units := doc.sub("units", "")
	for _, u := range d.Units {
		if o.full {
			fullDefaults(u)
		}
		units.set(u.Key, u.fields, "")
	}

	branch, branchWhy := a.gitBranch(dir), "detected: current git branch"
	if branch == "" {
		branch, branchWhy = "main", ""
	}
	acct := &account{}
	if a.loggedIn() {
		if err := a.askAccount(ctx, p, o, d, dir, branch, acct); err != nil {
			fmt.Fprintf(a.Stderr, "falak: skipped the account questions: %v\n", err)
			acct = &account{}
		}
	}
	if len(acct.envs) == 0 {
		// Not linked: environment slugs are unknown, and a guessed one would be created (not production) by the next
		// push to the production branch. Without environments the file deploys the project's production environment
		// from the repository's default branch.
		_ = branchWhy
		doc.foot = "No environments: the project's production environment deploys from the repository's default branch.\n" +
			"To bind branches, name the project's environment slugs (a push to production's branch creates the others):\n" +
			"environments:\n  production:\n    branch: " + branch + "\n  staging:\n    branch: develop"
	}
	var envs *omap
	if len(acct.envs) > 0 {
		envs = doc.sub("environments", "")
	}
	for _, e := range acct.envs {
		em := envs.sub(e.slug, "")
		em.set("branch", e.branch, e.why)
		if o.full || (e.production && e.slug != "production") {
			em.set("production", e.production, "")
		}
		if len(e.servers) > 0 {
			em.set("servers", e.servers, "")
		}
	}
	for key, domain := range acct.domains {
		if m, ok := units.get(key).(*omap); ok {
			m.set("domains", []any{domain}, "")
		}
	}
	if acct.previewsBase != "" {
		doc.sub("previews", "").set("enabled", true, "").set("base", acct.previewsBase, "")
	}

	content, err := renderYAML(doc, initHead)
	if err != nil {
		return err
	}
	content = spaceOut(content)
	v := validateOffline(configFile{Path: "falak.yaml", Display: "falak.yaml", Format: "yaml", Raw: content}, "")
	if !v.Valid {
		printIssues(a.Stderr, "falak.yaml (generated)", v.Errors)
		return errors.New("init generated an invalid falak.yaml (a bug: please report it); nothing written")
	}

	if a.jsonOut {
		return a.printJSON(struct {
			*detection
			File  string `json:"file"`
			Valid bool   `json:"valid"`
		}{d, string(content), v.Valid})
	}
	if o.stdout {
		if _, err := a.Stdout.Write(content); err != nil {
			return err
		}
		for _, n := range d.Notes {
			fmt.Fprintln(a.Stderr, "note:", n)
		}
		return nil
	}

	for _, df := range d.Dockerfiles {
		if err := writeInRepo(dir, df.Path, []byte(df.Content), false); err != nil {
			return err
		}
	}
	if err := writeInRepo(dir, "falak.yaml", content, o.force); err != nil {
		return err
	}
	if acct.project != nil {
		if err := writeProjectLink(dir, acct.project, acct.url); err != nil {
			return err
		}
	}
	a.initSummary(a.Stdout, d, acct)
	return nil
}

func langName(l string) string {
	switch l {
	case "go":
		return "Go"
	case "dotnet":
		return ".NET"
	}
	return strings.ToUpper(l[:1]) + l[1:]
}

func (a *App) initSummary(w io.Writer, d *detection, acct *account) {
	var parts []string
	for _, u := range d.Units {
		what := u.Type
		if u.Runtime != "" {
			what += ", " + u.Runtime
		}
		if u.Engine != "" {
			what += ", " + u.Engine
		}
		if u.Root != "" {
			what += ", " + u.Root
		}
		parts = append(parts, fmt.Sprintf("%s (%s)", u.Key, what))
	}
	fmt.Fprintf(w, "Wrote falak.yaml: %s\n", strings.Join(parts, ", "))
	for _, df := range d.Dockerfiles {
		fmt.Fprintf(w, "Wrote %s (a starter: check its build and start commands)\n", df.Path)
	}
	if acct.project != nil {
		fmt.Fprintf(w, "Linked to project %s (%s)\n", acct.project.Name, projectLinkFile)
	}
	for _, n := range d.Notes {
		fmt.Fprintln(w, "note:", n)
	}
	fmt.Fprintln(w, "Next: review it, `falak validate`, then `falak plan` (when logged in) and commit it.")
}

// spaceOut adds a blank line between top-level keys and between units, for reading.
func spaceOut(b []byte) []byte {
	lines := strings.Split(string(b), "\n")
	var out []string
	inUnits, seenTop, seenUnit := false, false, false
	unitLine := regexp.MustCompile(`^  [a-z][a-z0-9-]*:`)
	for _, l := range lines {
		top := l != "" && !strings.HasPrefix(l, " ") && !strings.HasPrefix(l, "#") && !strings.HasPrefix(l, "-")
		if top {
			if seenTop && (strings.HasPrefix(l, "units:") || strings.HasPrefix(l, "environments:") || strings.HasPrefix(l, "previews:")) {
				out = append(out, "")
			}
			seenTop = true
			inUnits, seenUnit = strings.HasPrefix(l, "units:"), false
		} else if inUnits && unitLine.MatchString(l) {
			if seenUnit {
				out = append(out, "")
			}
			seenUnit = true
		}
		out = append(out, l)
	}
	return []byte(strings.Join(out, "\n"))
}

// fullDefaults writes the defaults the file would otherwise leave out (--full).
func fullDefaults(u *detectedUnit) {
	f := u.fields
	switch u.Type {
	case "app":
		if u.plan != nil && u.plan.Framework != "" {
			if fw := map[string]string{"laravel": "laravel", "next": "next", "nuxt": "nuxt"}[u.plan.Framework]; fw != "" {
				f.setDefault("framework", fw, "default: detected")
			}
		}
		f.setDefault("health_path", "/", "default")
		f.setDefault("test_domain", true, "default")
		strategy := "zero-downtime"
		if u.Runtime == "docker" {
			strategy = "blue-green"
		}
		deploy := f.sub("deploy", "")
		deploy.setDefault("strategy", strategy, "default for "+u.Runtime)
		deploy.setDefault("keep_releases", 5, "default")
		h := deploy.sub("health", "")
		h.setDefault("timeout", "10s", "default")
		h.setDefault("retries", 3, "default")
	case "compose":
		f.setDefault("test_domain", true, "default")
		f.sub("deploy", "").setDefault("strategy", "compose", "default for compose units")
	case "database", "redis":
		engine := u.Engine
		if engine == "" {
			engine = "redis"
		}
		if u.Type == "redis" {
			f.setDefault("engine", engine, "default")
		}
		f.setDefault("version", supportedDBMajor[engine][0], "default: the newest")
	}
}

// ---- account questions -----------------------------------------------------------------------------------------

type account struct {
	project      *api.Project
	url          string
	envs         []accountEnv
	domains      map[string]string
	previewsBase string
}

type accountEnv struct {
	slug, branch, why string
	production        bool
	servers           []string
}

type prompter struct {
	a   *App
	ask bool
	in  *bufio.Reader
}

// line asks a question and returns the answer, or def when not asking (--yes, not a terminal) or on an empty
// answer.
func (p *prompter) line(q, def string) string {
	if !p.ask {
		return def
	}
	if def != "" {
		fmt.Fprintf(p.a.Stderr, "%s [%s]: ", q, def)
	} else {
		fmt.Fprintf(p.a.Stderr, "%s: ", q)
	}
	s, _ := p.in.ReadString('\n')
	if s = strings.TrimSpace(s); s == "" {
		return def
	}
	return s
}

func (p *prompter) confirm(q string, def bool) bool {
	if !p.ask {
		return def
	}
	hint := "y/N"
	if def {
		hint = "Y/n"
	}
	ans := strings.ToLower(p.line(q+" ("+hint+")", ""))
	switch {
	case ans == "":
		return def
	case strings.HasPrefix(ans, "y"):
		return true
	}
	return false
}

var hostRe = regexp.MustCompile(`^(\*\.)?([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$`)

// askAccount asks for the project, its environments (branch and servers), a domain and previews, with defaults from
// the organization. Without a terminal (or with --yes) it takes the defaults.
func (a *App) askAccount(ctx context.Context, p *prompter, o initOptions, d *detection, dir, branch string, acct *account) error {
	c, err := a.api()
	if err != nil {
		return err
	}
	creds, _ := a.creds()
	acct.url = creds.URL
	projects, err := c.Projects(ctx)
	if err != nil {
		return err
	}
	if len(projects) == 0 {
		return errors.New("the organization has no projects yet")
	}
	ref := o.project
	if ref == "" {
		ref = a.Getenv("FALAK_PROJECT")
	}
	pick := -1
	for i, pr := range projects {
		if ref != "" && (strings.EqualFold(pr.Name, ref) || strings.EqualFold(pr.ID, ref)) {
			pick = i
		}
	}
	if ref != "" && pick < 0 {
		return fmt.Errorf("no project %q", ref)
	}
	if pick < 0 {
		// Linking needs a choice: -p, FALAK_PROJECT or an answer here; --yes never picks a project.
		if !p.ask {
			d.Notes = append(d.Notes, "not linked to a project: pass -p <project> (or answer the questions without --yes) to link it")
			return nil
		}
		def := ""
		base := filepath.Base(dir)
		for i, pr := range projects {
			if strings.EqualFold(pr.Name, base) {
				def = strconv.Itoa(i + 1)
				break
			}
		}
		fmt.Fprintln(a.Stderr, "Projects:")
		for i, pr := range projects {
			fmt.Fprintf(a.Stderr, "  %d) %s\n", i+1, pr.Name)
		}
		ans := p.line("Link this repository to project (number; empty: don't link)", def)
		if ans == "" {
			return nil
		}
		n, err := strconv.Atoi(ans)
		if err != nil || n < 1 || n > len(projects) {
			return fmt.Errorf("no project %q", ans)
		}
		pick = n - 1
	}
	pr := projects[pick]
	acct.project = &pr

	servers, err := c.Servers(ctx)
	if err != nil {
		return err
	}
	names := make([]string, 0, len(servers))
	for _, s := range servers {
		names = append(names, s.Name)
	}
	sort.Strings(names)
	defServers := ""
	if len(names) == 1 {
		defServers = names[0]
	}

	envs := append([]api.ProjectEnvironment(nil), pr.Environments...)
	sort.SliceStable(envs, func(i, j int) bool { return envs[i].IsProduction && !envs[j].IsProduction })
	if len(envs) == 0 {
		envs = []api.ProjectEnvironment{{Slug: "production", IsProduction: true}}
	}
	if p.ask && len(names) > 0 {
		fmt.Fprintf(a.Stderr, "Servers: %s\n", strings.Join(names, ", "))
	}
	for i, e := range envs {
		def := ""
		if i == 0 {
			def = branch
		}
		q := fmt.Sprintf("Branch that deploys to %s", e.Slug)
		if i > 0 {
			q += " (empty: skip)"
		}
		b := p.line(q, def)
		if b == "" {
			continue
		}
		ae := accountEnv{slug: e.Slug, branch: b, production: e.IsProduction}
		if b == branch && i == 0 {
			ae.why = "detected: current git branch"
		}
		list := p.line(fmt.Sprintf("Servers of %s (comma-separated)", e.Slug), defServers)
		for _, s := range strings.Split(list, ",") {
			if s = strings.TrimSpace(s); s == "" {
				continue
			}
			if !contains(names, s) {
				return fmt.Errorf("no server %q", s)
			}
			ae.servers = append(ae.servers, s)
		}
		acct.envs = append(acct.envs, ae)
	}

	acct.domains = map[string]string{}
	for _, u := range d.Units {
		if u.Type != "app" && u.Type != "compose" {
			continue
		}
		if u.Type == "compose" && u.fields.get("services") == nil {
			continue
		}
		dom := strings.ToLower(p.line(fmt.Sprintf("Domain of %s (empty: none)", u.Key), ""))
		if dom == "" {
			continue
		}
		if !hostRe.MatchString(dom) {
			return fmt.Errorf("%q is not a domain name", dom)
		}
		acct.domains[u.Key] = dom
	}

	if len(acct.envs) > 0 && p.confirm("Preview environments for pull requests?", false) {
		base := acct.envs[len(acct.envs)-1].slug
		for _, e := range acct.envs {
			if !e.production {
				base = e.slug
			}
		}
		acct.previewsBase = base
	}
	return nil
}

func writeProjectLink(dir string, p *api.Project, url string) error {
	b, err := json.MarshalIndent(projectLink{Project: p.ID, Name: p.Name, URL: url}, "", "  ")
	if err != nil {
		return err
	}
	return writeInRepo(dir, projectLinkFile, append(b, '\n'), true)
}
