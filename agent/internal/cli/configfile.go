package cli

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"sort"
	"strings"

	"github.com/OthmanHaba/falak/agent/internal/cli/api"
	"github.com/OthmanHaba/falak/agent/internal/configspec"
)

// Exit codes of the config commands (docs/plans/CONFIG_AS_CODE.md, "CLI").
const (
	ExitInvalid  = 5 // the file is invalid
	ExitApproval = 6 // the run waits for an approval
)

// configFileNames are looked up in this order when no file is given.
var configFileNames = []string{"falak.yaml", "falak.yml", "falak.json"}

// projectLinkFile links a repository to a project (written by `falak init` when logged in).
const projectLinkFile = ".falak/project.json"

type projectLink struct {
	Project string `json:"project"`
	Name    string `json:"name,omitempty"`
	URL     string `json:"url,omitempty"`
}

// configFile is a falak.yaml read from disk.
type configFile struct {
	Path    string // as given or found (shown in issues)
	Display string // the name issues print
	Format  string // yaml | json
	Raw     []byte
}

func (a *App) workDir() string {
	if a.Dir != "" {
		return a.Dir
	}
	d, err := os.Getwd()
	if err != nil {
		return "."
	}
	return d
}

func (a *App) abs(p string) string {
	if filepath.IsAbs(p) {
		return p
	}
	return filepath.Join(a.workDir(), p)
}

// readConfigFile reads [file], else falak.yaml, falak.yml or falak.json in the working directory.
func (a *App) readConfigFile(arg, format string) (configFile, error) {
	var f configFile
	if arg != "" {
		f.Path = arg
	} else {
		for _, n := range configFileNames {
			if _, err := os.Stat(a.abs(n)); err == nil {
				f.Path = n
				break
			}
		}
		if f.Path == "" {
			return f, usagef("no falak.yaml, falak.yml or falak.json here (run `falak init`, or give the file)")
		}
	}
	raw, err := os.ReadFile(a.abs(f.Path))
	if err != nil {
		return f, err
	}
	f.Raw, f.Display = raw, f.Path
	switch format {
	case "":
		f.Format = "yaml"
		if strings.EqualFold(filepath.Ext(f.Path), ".json") {
			f.Format = "json"
		}
	case "yaml", "json":
		f.Format = format
	default:
		return f, usagef("--format must be yaml or json")
	}
	return f, nil
}

// validation is the result of stages 1–3 (and the offline part of 4) on a file.
type validation struct {
	Valid        bool              `json:"valid"`
	Environments []string          `json:"environments"`
	Errors       []api.ConfigIssue `json:"errors"`
	Warnings     []api.ConfigIssue `json:"warnings"`
	doc          map[string]any
}

func intPtr(n int) *int {
	if n <= 0 {
		return nil
	}
	return &n
}

func strPtr(s string) *string {
	if s == "" {
		return nil
	}
	return &s
}

// validateOffline runs stages 1–3 for env (every environment of the file when env is ""), then the stage 4 rules
// that need no organization (configcheck.go).
func validateOffline(f configFile, env string) validation {
	v := validation{Errors: []api.ConfigIssue{}, Warnings: []api.ConfigIssue{}, Environments: []string{}}
	pos := configspec.NewPositions(f.Raw)
	issue := func(severity string, i configspec.Issue) api.ConfigIssue {
		at := i.Pointer
		if i.Source != "" {
			at = i.Source
		}
		line, col := 0, 0
		if strings.HasPrefix(i.Code, configspec.CodeParse) {
			line = configspec.MessageLine(i.Message)
		}
		if line == 0 && (at != "" || !strings.HasPrefix(i.Code, configspec.CodeParse)) {
			line, col = pos.Locate(at, i.Property)
		}
		msg := i.Message
		if i.Property != "" && !strings.Contains(msg, i.Property) {
			msg += " (" + i.Property + ")"
		}
		return api.ConfigIssue{Code: i.Code, Pointer: i.Pointer, Source: strPtr(i.Source), Line: intPtr(line), Column: intPtr(col), Message: msg, Severity: severity}
	}

	parsed, err := configspec.Parse(f.Raw, f.Format)
	if err != nil {
		var pi configspec.Issue
		if !errors.As(err, &pi) {
			pi = configspec.Issue{Code: configspec.CodeParse, Message: err.Error()}
		}
		v.Errors = append(v.Errors, issue("error", pi))
		return v
	}
	doc, ok := parsed.(map[string]any)
	if !ok {
		v.Errors = append(v.Errors, api.ConfigIssue{Code: configspec.CodeSchema, Pointer: "", Line: intPtr(1), Column: intPtr(1), Message: "the file must be a mapping (version, units, …)", Severity: "error"})
		return v
	}
	v.doc = doc
	fileEnvs := sortedMapKeys(asMapAny(doc["environments"]))
	v.Environments = append(v.Environments, fileEnvs...)

	schemaIssues, err := configspec.Validate(doc)
	if err != nil {
		v.Errors = append(v.Errors, api.ConfigIssue{Code: configspec.CodeSchema, Message: err.Error(), Severity: "error"})
		return v
	}
	for _, i := range schemaIssues {
		v.Errors = append(v.Errors, issue("error", i))
	}
	if len(v.Errors) > 0 {
		return v
	}

	envs := fileEnvs
	synthetic := ""
	if env != "" && len(fileEnvs) > 0 {
		envs = []string{env}
	} else if len(envs) == 0 {
		// No environments (production deploys from the default branch): check the units' references on their own.
		synthetic = "-"
		doc = shallowWith(doc, "environments", map[string]any{synthetic: map[string]any{}})
		envs = []string{synthetic}
	}
	seen := map[string]bool{}
	add := func(list *[]api.ConfigIssue, severity string, i configspec.Issue) {
		key := i.Code + "\x00" + i.Pointer + "\x00" + i.Source
		if seen[key] {
			return
		}
		seen[key] = true
		*list = append(*list, issue(severity, i))
	}
	for _, e := range envs {
		resolved, errs, warns := configspec.Resolve(doc, e)
		for _, i := range errs {
			add(&v.Errors, "error", i)
		}
		for _, i := range warns {
			add(&v.Warnings, "warning", i)
		}
		if resolved == nil {
			continue
		}
		if synthetic != "" {
			delete(resolved, "environments")
		}
		// The merge can produce an invalid unit (stage 3, rule 3).
		again, err := configspec.Validate(resolved)
		if err == nil {
			for _, i := range again {
				i.Source = sourceOf(doc, e, i.Pointer)
				add(&v.Errors, "error", i)
			}
		}
		if len(errs) > 0 || len(again) > 0 {
			continue
		}
		for _, s := range semanticChecks(resolved, fileEnvs) {
			add(&v.Errors, "error", configspec.Issue{Code: s.code, Pointer: s.pointer, Source: sourceOf(doc, e, s.pointer), Message: s.message})
		}
	}
	v.Valid = len(v.Errors) == 0
	return v
}

func shallowWith(m map[string]any, k string, val any) map[string]any {
	out := make(map[string]any, len(m)+1)
	for kk, vv := range m {
		out[kk] = vv
	}
	out[k] = val
	return out
}

// sourceOf maps a pointer of a resolved file to the value's place in the file: the environment's override when it
// sets it, else the same pointer.
func sourceOf(doc map[string]any, env, ptr string) string {
	if !strings.HasPrefix(ptr, "/units/") {
		return ptr
	}
	over := "/environments/" + escapeToken(env) + ptr
	if lookup(doc, over) {
		return over
	}
	return ptr
}

func lookup(doc any, ptr string) bool {
	cur := doc
	for _, tok := range strings.Split(strings.TrimPrefix(ptr, "/"), "/") {
		tok = strings.ReplaceAll(strings.ReplaceAll(tok, "~1", "/"), "~0", "~")
		switch t := cur.(type) {
		case map[string]any:
			v, ok := t[tok]
			if !ok {
				return false
			}
			cur = v
		case []any:
			i := -1
			fmt.Sscanf(tok, "%d", &i)
			if i < 0 || i >= len(t) {
				return false
			}
			cur = t[i]
		default:
			return false
		}
	}
	return true
}

// printIssues writes issues as `falak.yaml:12:17: schema: /units/web/deploy/strategy: message`.
func printIssues(w io.Writer, file string, issues []api.ConfigIssue) {
	for _, i := range issues {
		loc := file
		if i.Line != nil {
			loc += fmt.Sprintf(":%d", *i.Line)
			if i.Column != nil {
				loc += fmt.Sprintf(":%d", *i.Column)
			}
		}
		code := i.Code
		if i.Severity == "warning" {
			code = "warning: " + code
		}
		ptr := i.Pointer
		if i.Source != nil && *i.Source != "" && *i.Source != i.Pointer {
			ptr = *i.Source
		}
		if ptr != "" {
			fmt.Fprintf(w, "%s: %s: %s: %s\n", loc, code, ptr, i.Message)
		} else {
			fmt.Fprintf(w, "%s: %s: %s\n", loc, code, i.Message)
		}
	}
}

// invalid is the error a command returns for an invalid file (exit 5); the issues were printed already.
func invalid(file string, n int) error {
	s := "s"
	if n == 1 {
		s = ""
	}
	return exitError{ExitInvalid, fmt.Errorf("%s is invalid (%d error%s)", file, n, s)}
}

var ulidRe = regexp.MustCompile(`^[0-9A-Za-z]{26}$`)

// findUp looks for rel in dir and its parents, stopping at the repository root (a directory holding .git).
func findUp(dir, rel string) string {
	for {
		p := filepath.Join(dir, rel)
		if _, err := os.Stat(p); err == nil {
			return p
		}
		if _, err := os.Stat(filepath.Join(dir, ".git")); err == nil {
			return ""
		}
		parent := filepath.Dir(dir)
		if parent == dir {
			return ""
		}
		dir = parent
	}
}

// projectRef is -p, else FALAK_PROJECT, else .falak/project.json of the repository ("" when none).
func (a *App) projectRef(flagValue, near string) (string, error) {
	if flagValue != "" {
		return flagValue, nil
	}
	if v := a.Getenv("FALAK_PROJECT"); v != "" {
		return v, nil
	}
	start := a.workDir()
	if near != "" {
		start = filepath.Dir(a.abs(near))
	}
	p := findUp(start, projectLinkFile)
	if p == "" {
		return "", nil
	}
	b, err := os.ReadFile(p)
	if err != nil {
		return "", err
	}
	var l projectLink
	if err := json.Unmarshal(b, &l); err != nil {
		return "", fmt.Errorf("%s: %w", p, err)
	}
	return l.Project, nil
}

// resolveProject turns a project id or name into its id.
func resolveProject(ctx context.Context, c api.API, ref string) (string, error) {
	if ulidRe.MatchString(ref) {
		return strings.ToLower(ref), nil
	}
	projects, err := c.Projects(ctx)
	if err != nil {
		return "", err
	}
	var names []string
	for _, p := range projects {
		if strings.EqualFold(p.Name, ref) || p.ID == ref {
			return p.ID, nil
		}
		names = append(names, p.Name)
	}
	sort.Strings(names)
	return "", fmt.Errorf("no project %q (projects: %s)", ref, strings.Join(names, ", "))
}

func (a *App) requireProject(ctx context.Context, c api.API, flagValue, near string) (string, error) {
	ref, err := a.projectRef(flagValue, near)
	if err != nil {
		return "", err
	}
	if ref == "" {
		return "", usagef("which project? pass -p <project>, set FALAK_PROJECT, or run `falak init` to link this repository (%s)", projectLinkFile)
	}
	return resolveProject(ctx, c, ref)
}

// gitBranch is the current branch of the repository holding dir ("" when unknown).
func (a *App) gitBranch(dir string) string {
	if a.GitBranch != nil {
		return a.GitBranch(dir)
	}
	cmd := exec.Command("git", "-C", dir, "rev-parse", "--abbrev-ref", "HEAD")
	out, err := cmd.Output()
	if err != nil {
		return ""
	}
	b := strings.TrimSpace(string(out))
	if b == "HEAD" {
		return ""
	}
	return b
}

// defaultEnvironment picks the environment whose branch is the current git branch, else the file's only one.
func (a *App) defaultEnvironment(f configFile, doc map[string]any) (string, error) {
	envs := asMapAny(doc["environments"])
	branch := a.gitBranch(filepath.Dir(a.abs(f.Path)))
	if branch != "" {
		var match []string
		for _, k := range sortedMapKeys(envs) {
			if str(asMapAny(envs[k])["branch"]) == branch {
				match = append(match, k)
			}
		}
		if len(match) == 1 {
			return match[0], nil
		}
		if len(match) > 1 {
			return "", usagef("environments %s all deploy branch %s: pick one with -e", strings.Join(match, ", "), branch)
		}
	}
	if len(envs) == 1 {
		return sortedMapKeys(envs)[0], nil
	}
	if len(envs) == 0 {
		return "", nil // the project's production environment (the caller asks the control plane)
	}
	why := "no environment of " + f.Display + " has branch " + branch
	if branch == "" {
		why = "not on a git branch"
	}
	return "", usagef("%s: pick one with -e (%s)", why, strings.Join(sortedMapKeys(envs), ", "))
}
