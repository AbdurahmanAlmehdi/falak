package cli

import (
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"

	"gopkg.in/yaml.v3"

	"github.com/OthmanHaba/falak/agent/internal/builder"
)

// Versions Falak offers (control plane: Sites' php_versions / node_versions, Databases' versions; the first database
// version of each engine is the default of a new instance). init writes only versions in these lists: a version
// outside them doesn't build.
var (
	supportedPHP     = []string{"7.4", "8.0", "8.1", "8.2", "8.3", "8.4", "8.5"}
	supportedNode    = []string{"18", "20", "22", "24"}
	supportedDBMajor = map[string][]string{
		"postgresql": {"17", "18", "16", "15"},
		"mysql":      {"8.4", "8.0"},
		"mariadb":    {"11.4", "10.11"},
		"redis":      {"8", "7.4"},
		"valkey":     {"8.1"},
	}
)

// detection is what `falak init` found in a repository (also its --json report).
type detection struct {
	Dir         string           `json:"dir"`
	Kind        string           `json:"kind"` // app | monorepo | compose
	Units       []*detectedUnit  `json:"units"`
	Notes       []string         `json:"notes,omitempty"`
	Unsupported *unsupportedLang `json:"unsupported,omitempty"`
	Dockerfiles []*starterFile   `json:"dockerfiles,omitempty"` // written by --dockerfile, one per unit root
	offers      []composeOffer
}

// detectedUnit is one unit of the file, with the reasons of its values.
type detectedUnit struct {
	Key     string   `json:"key"`
	Type    string   `json:"type"`
	Root    string   `json:"root,omitempty"`
	Runtime string   `json:"runtime,omitempty"`
	Engine  string   `json:"engine,omitempty"`
	Reasons []string `json:"reasons"`
	fields  *omap
	needs   needs
	envFile []envEntry
	plan    *builder.Plan
	dir     string
}

type unsupportedLang struct {
	Language string `json:"language"`
	Evidence string `json:"evidence"`
	Root     string `json:"root,omitempty"`
}

type starterFile struct {
	Path    string `json:"path"`
	Content string `json:"-"`
}

// needs are the backing services an app asks for.
type needs struct {
	db, dbWhy       string // engine and reason
	redis, redisWhy string
}

type detectOptions struct {
	dockerfile bool // --dockerfile: write a starter Dockerfile for unsupported languages
}

// fsRepo reads files of a directory inside a repository. Nothing outside the repository is read: a path whose
// symlinks lead out of root counts as missing (and is reported once in the notes).
type fsRepo struct {
	root string // the repository, symlinks resolved
	dir  string
	seen *map[string]bool // escaping paths already reported
	out  *[]string        // where they are reported
}

func newRepo(dir string, notes *[]string) (fsRepo, error) {
	real, err := filepath.EvalSymlinks(dir)
	if err != nil {
		return fsRepo{}, err
	}
	real, err = filepath.Abs(real)
	if err != nil {
		return fsRepo{}, err
	}
	seen := map[string]bool{}
	return fsRepo{root: real, dir: real, seen: &seen, out: notes}, nil
}

func (r fsRepo) path(rel string) string { return filepath.Join(r.dir, filepath.FromSlash(rel)) }

// within reports whether a path stays inside the repository once its symlinks are resolved.
func within(root, p string) bool {
	real, err := filepath.EvalSymlinks(p)
	if err != nil {
		return false
	}
	return real == root || strings.HasPrefix(real, root+string(filepath.Separator))
}

// safe is the path of rel when it exists inside the repository, else "".
func (r fsRepo) safe(rel string) string {
	p := r.path(rel)
	if _, err := os.Lstat(p); err != nil {
		return ""
	}
	if !within(r.root, p) {
		if r.seen != nil && !(*r.seen)[p] {
			(*r.seen)[p] = true
			if r.out != nil {
				shown, _ := filepath.Rel(r.root, p)
				*r.out = append(*r.out, fmt.Sprintf("%s leads out of the repository (symlink); not read", filepath.ToSlash(shown)))
			}
		}
		return ""
	}
	return p
}

func (r fsRepo) exists(rel string) bool { return r.safe(rel) != "" }

func (r fsRepo) isDir(rel string) bool {
	p := r.safe(rel)
	if p == "" {
		return false
	}
	st, err := os.Stat(p)
	return err == nil && st.IsDir()
}

func (r fsRepo) read(rel string) string {
	p := r.safe(rel)
	if p == "" {
		return ""
	}
	st, err := os.Stat(p)
	if err != nil || !st.Mode().IsRegular() || st.Size() > 4<<20 {
		return ""
	}
	b, err := os.ReadFile(p)
	if err != nil {
		return ""
	}
	return string(b)
}

func (r fsRepo) sub(rel string) fsRepo {
	c := r
	c.dir = r.path(rel)
	return c
}

// glob lists rel paths matching pattern that stay inside the repository.
func (r fsRepo) glob(pattern string) []string {
	matches, _ := filepath.Glob(r.path(pattern))
	sort.Strings(matches)
	var out []string
	for _, m := range matches {
		rel, err := filepath.Rel(r.dir, m)
		if err != nil {
			continue
		}
		rel = filepath.ToSlash(rel)
		if r.exists(rel) {
			out = append(out, rel)
		}
	}
	return out
}

func (r fsRepo) nonEmptyDir(rel string) bool {
	p := r.safe(rel)
	if p == "" {
		return false
	}
	entries, err := os.ReadDir(p)
	if err != nil {
		return false
	}
	for _, e := range entries {
		if !strings.HasPrefix(e.Name(), ".") {
			return true
		}
	}
	return false
}

// builderInputs are the files builder.Detect reads itself.
var builderInputs = []string{"package.json", "composer.json", "deno.json", "deno.jsonc", "artisan", "index.html", "public", "public/index.html",
	"public/index.php", ".nvmrc", ".node-version", ".tool-versions", "bun.lock", "bun.lockb", "pnpm-lock.yaml", "yarn.lock",
	".yarnrc.yml", "package-lock.json", "npm-shrinkwrap.json", "deno.lock", "dist", "build", "out"}

// detectable runs builder.Detect only when the files it reads stay inside the repository.
func (r fsRepo) detect() (builder.Plan, error) {
	if !within(r.root, r.dir) {
		return builder.Plan{}, errors.New("the directory leads out of the repository")
	}
	for _, f := range builderInputs {
		if _, err := os.Lstat(r.path(f)); err == nil && r.safe(f) == "" {
			return builder.Plan{}, fmt.Errorf("%s leads out of the repository", f)
		}
	}
	return builder.Detect(r.dir, "")
}

// ---- top level -------------------------------------------------------------------------------------------------

func detect(dir string, opt detectOptions) (*detection, error) {
	d := &detection{Dir: dir}
	repo, err := newRepo(dir, &d.Notes)
	if err != nil {
		return d, err
	}

	if c := composeFile(repo); c != "" {
		ok, err := d.detectCompose(repo, c)
		if err != nil {
			return d, err
		}
		if ok {
			// The stack's env and needs come from the repository like an app's.
			u := d.Units[0]
			if plan, err := repo.detect(); err == nil {
				u.plan = &plan
			}
			u.needs = repoNeeds(repo)
			u.envFile = readEnvExample(repo)
			d.wireNeeds(repo)
			return d, nil
		}
	}
	if apps := workspaceApps(repo); len(apps) > 0 {
		d.Kind = "monorepo"
		for _, rel := range apps {
			u, err := detectApp(repo, rel, opt, d)
			if err != nil {
				return d, err
			}
			if u != nil {
				d.Units = append(d.Units, u)
			}
		}
		if len(d.Units) == 0 {
			return d, errors.New("found a workspace but no deployable app in it")
		}
	} else {
		d.Kind = "app"
		u, err := detectApp(repo, ".", opt, d)
		if err != nil {
			return d, err
		}
		if u == nil {
			return d, nil // unsupported
		}
		d.Units = append(d.Units, u)
	}
	d.wireNeeds(repo)
	return d, nil
}

// ---- monorepos -------------------------------------------------------------------------------------------------

// workspaceApps lists app directories of a monorepo: pnpm-workspace.yaml and package.json workspaces globs, plus
// apps/* and services/*. Only directories under apps/ or services/, or with a framework or a start script, count:
// shared packages are not apps.
func workspaceApps(repo fsRepo) []string {
	var globs []string
	explicit := false
	if s := repo.read("pnpm-workspace.yaml"); s != "" {
		var ws struct {
			Packages []string `yaml:"packages"`
		}
		if yaml.Unmarshal([]byte(s), &ws) == nil {
			globs = append(globs, ws.Packages...)
			explicit = true
		}
	}
	if s := repo.read("package.json"); s != "" {
		var pkg struct {
			Workspaces json.RawMessage `json:"workspaces"`
		}
		if json.Unmarshal([]byte(s), &pkg) == nil && len(pkg.Workspaces) > 0 {
			var list []string
			var obj struct {
				Packages []string `json:"packages"`
			}
			if json.Unmarshal(pkg.Workspaces, &list) == nil {
				globs = append(globs, list...)
			} else if json.Unmarshal(pkg.Workspaces, &obj) == nil {
				globs = append(globs, obj.Packages...)
			}
			explicit = explicit || len(globs) > 0
		}
	}
	if !explicit {
		// Only a root that isn't an app itself is a monorepo of apps/* and services/*.
		if repo.exists("package.json") || repo.exists("composer.json") || repo.exists("Dockerfile") || repo.exists("go.mod") {
			return nil
		}
	}
	globs = append(globs, "apps/*", "services/*")
	seen := map[string]bool{}
	var out []string
	for _, g := range globs {
		g = strings.TrimPrefix(strings.TrimSuffix(strings.TrimSpace(g), "/"), "./")
		if strings.HasPrefix(g, "!") || g == "" {
			continue
		}
		for _, rel := range repo.glob(g) {
			if seen[rel] || !repo.isDir(rel) {
				continue
			}
			seen[rel] = true
			if isApp(repo.sub(rel), strings.HasPrefix(rel, "apps/") || strings.HasPrefix(rel, "services/")) {
				out = append(out, rel)
			}
		}
	}
	sort.Strings(out)
	return out
}

func isApp(r fsRepo, appDir bool) bool {
	if r.exists("Dockerfile") || r.exists("composer.json") {
		return true
	}
	if !r.exists("package.json") {
		return appDir && (r.exists("go.mod") || r.exists("requirements.txt") || r.exists("pyproject.toml") || r.exists("Gemfile") || r.exists("Cargo.toml"))
	}
	p, err := r.detect()
	if err != nil {
		return false
	}
	if appDir {
		return true
	}
	var pkg packageFile
	_ = json.Unmarshal([]byte(r.read("package.json")), &pkg)
	return p.Framework != "" || pkg.Scripts["start"] != ""
}

// ---- apps ------------------------------------------------------------------------------------------------------

type packageFile struct {
	Name            string            `json:"name"`
	Scripts         map[string]string `json:"scripts"`
	Dependencies    map[string]string `json:"dependencies"`
	DevDependencies map[string]string `json:"devDependencies"`
	Engines         map[string]string `json:"engines"`
}

func (p packageFile) dep(name string) (string, bool) {
	if v, ok := p.Dependencies[name]; ok {
		return v, true
	}
	v, ok := p.DevDependencies[name]
	return v, ok
}

type composerFile struct {
	Require    map[string]string `json:"require"`
	RequireDev map[string]string `json:"require-dev"`
	Config     struct {
		Platform map[string]string `json:"platform"`
	} `json:"config"`
}

// unitKeyRe is the schema's unit key pattern.
var unitKeyRe = regexp.MustCompile(`^[a-z]([a-z0-9-]{0,61}[a-z0-9])?$`)

// unitKey makes a unit key from a name (`apps/Web_App` → `web-app`).
func unitKey(name, fallback string) string {
	k := strings.ToLower(path.Base(name))
	k = regexp.MustCompile(`[^a-z0-9-]+`).ReplaceAllString(k, "-")
	k = strings.Trim(regexp.MustCompile(`-+`).ReplaceAllString(k, "-"), "-")
	k = strings.TrimLeft(k, "0123456789-")
	if len(k) > 63 {
		k = strings.TrimRight(k[:63], "-")
	}
	if !unitKeyRe.MatchString(k) || k == "secrets" {
		return fallback
	}
	return k
}

func detectApp(repo fsRepo, rel string, opt detectOptions, d *detection) (*detectedUnit, error) {
	r := repo
	if rel != "." {
		r = repo.sub(rel)
	}
	key := "web"
	if rel != "." {
		key = unitKey(rel, "app")
	}
	u := &detectedUnit{Key: key, Type: "app", fields: newOmap(), dir: r.dir}
	if rel != "." {
		u.Root = rel
	}
	f := u.fields
	f.set("type", "app", "")
	if rel != "." {
		f.set("root", rel, "")
	}

	var pkg packageFile
	_ = json.Unmarshal([]byte(r.read("package.json")), &pkg)
	var composer composerFile
	_ = json.Unmarshal([]byte(r.read("composer.json")), &composer)

	if r.exists("Dockerfile") {
		port, why := dockerfilePort(r.read("Dockerfile"))
		u.Runtime = "docker"
		f.set("runtime", "docker", "detected: Dockerfile")
		if port > 0 {
			f.set("port", port, why)
		} else {
			d.Notes = append(d.Notes, fmt.Sprintf("%s: the Dockerfile has no EXPOSE; set port: to the port the app listens on", key))
		}
		f.sub("build", "").set("mode", "docker", "detected: Dockerfile")
		u.Reasons = append(u.Reasons, "Dockerfile")
		if plan, err := r.detect(); err == nil {
			u.plan = &plan
			if plan.Framework == "laravel" {
				if m := healthRe.FindStringSubmatch(r.read("bootstrap/app.php")); m != nil {
					f.set("health_path", m[1], "detected: health route in bootstrap/app.php")
				}
				d.Notes = append(d.Notes, fmt.Sprintf("%s: Laravel in a Dockerfile: migrations, queue workers and the scheduler run as your image runs them", key))
			}
		}
		u.needs = detectNeeds(r, pkg, composer)
		u.envFile = readEnvExample(r)
		return u, nil
	}

	// Only the built-in detector: versions come from the repository (engines, .nvmrc, composer.json), never from a
	// tool's defaults, so the file doesn't depend on what is installed locally.
	plan, err := r.detect()
	if err != nil {
		lang, evidence := sniffLanguage(r)
		if lang == "" {
			if rel != "." {
				return nil, nil // not an app after all
			}
			return nil, fmt.Errorf("nothing to deploy found in %s (no package.json, composer.json, Dockerfile or compose file): %v", r.dir, err)
		}
		return unsupportedApp(r, rel, u, lang, evidence, opt, d)
	}
	u.plan = &plan
	u.needs = detectNeeds(r, pkg, composer)
	u.envFile = readEnvExample(r)

	switch plan.Runtime {
	case "php":
		u.Runtime = "frankenphp"
		f.set("runtime", "frankenphp", "detected: composer.json")
		if plan.Framework == "laravel" {
			// Explicit, so a new site is created as Laravel (its deploy steps, queue:work workers, the scheduler).
			f.set("framework", "laravel", "detected: laravel/framework "+composer.Require["laravel/framework"]+" in composer.json")
		}
		if v := plan.PHPVersion; v != "" {
			src := "php " + composer.Require["php"] + " in composer.json"
			if p := composer.Config.Platform["php"]; p != "" {
				src = "config.platform.php " + p + " in composer.json"
			}
			if contains(supportedPHP, v) {
				f.set("php", v, "detected: "+src)
			} else {
				d.Notes = append(d.Notes, fmt.Sprintf("%s: PHP %s (%s) isn't offered; Falak uses its default", key, v, src))
			}
		}
		if plan.Framework == "laravel" {
			laravelConventions(r, u, composer, d)
		}
	case "node", "bun", "deno", "static":
		u.Runtime = plan.Runtime
		why := "detected: package.json"
		if plan.Framework != "" {
			ver, _ := pkg.dep(frameworkDep(plan.Framework))
			why = strings.TrimSpace(fmt.Sprintf("detected: %s %s in package.json", frameworkDep(plan.Framework), ver))
		}
		if plan.Runtime == "static" {
			why += " (static output"
			if plan.OutputDir != "" {
				why += " in " + plan.OutputDir + "/"
			}
			why += ")"
		}
		if plan.Runtime == "deno" {
			why = "detected: deno.json"
		}
		if plan.Runtime == "bun" {
			why += ", bun"
		}
		f.set("runtime", plan.Runtime, why)
		if (plan.Runtime == "node" || plan.Runtime == "static") && plan.NodeVersion != "" {
			src := nodeVersionSource(r, pkg)
			if contains(supportedNode, plan.NodeVersion) {
				f.set("node", plan.NodeVersion, "detected: "+src)
			} else {
				d.Notes = append(d.Notes, fmt.Sprintf("%s: Node %s (%s) isn't offered; Falak uses its default", key, plan.NodeVersion, src))
			}
		}
		if plan.Runtime != "static" && plan.StartCommand == "" {
			d.Notes = append(d.Notes, fmt.Sprintf("%s: no start command found; add a \"start\" script to package.json", key))
		}
	default:
		return nil, fmt.Errorf("%s: unexpected runtime %q", key, plan.Runtime)
	}
	u.Reasons = append(u.Reasons, firstNonEmpty(plan.Framework, plan.Provider))
	return u, nil
}

// frameworkDep is the package.json dependency builder.Detect keys a framework on.
func frameworkDep(framework string) string {
	switch framework {
	case "remix":
		return "@remix-run/node"
	case "sveltekit":
		return "@sveltejs/kit"
	case "nestjs":
		return "@nestjs/core"
	case "cra":
		return "react-scripts"
	case "astro-ssr":
		return "astro"
	}
	return framework
}

func nodeVersionSource(r fsRepo, pkg packageFile) string {
	for _, f := range []string{".nvmrc", ".node-version"} {
		if s := strings.TrimSpace(r.read(f)); s != "" {
			return f
		}
	}
	if strings.Contains(r.read(".tool-versions"), "nodejs") {
		return ".tool-versions"
	}
	return "engines.node " + pkg.Engines["node"] + " in package.json"
}

var exposeRe = regexp.MustCompile(`(?im)^\s*EXPOSE\s+([0-9]+)`)

func dockerfilePort(content string) (int, string) {
	m := exposeRe.FindAllStringSubmatch(content, -1)
	if m == nil {
		return 0, ""
	}
	last := m[len(m)-1][1] // the final stage's EXPOSE
	n, err := strconv.Atoi(last)
	if err != nil || n < 1 || n > 65535 {
		return 0, ""
	}
	return n, "detected: EXPOSE " + last + " in Dockerfile"
}

// ---- Laravel ---------------------------------------------------------------------------------------------------

var (
	healthRe    = regexp.MustCompile(`health:\s*['"](/[^'"]*)['"]`)
	scheduleRe  = regexp.MustCompile(`Schedule::|\$schedule->`)
	migrateHook = "if [ \"$FALAK_IS_LEADER\" = \"1\" ]; then\n  $FALAK_PHP artisan migrate --force\nfi\n$FALAK_PHP artisan optimize\n$FALAK_PHP artisan storage:link --force\n"
)

func laravelConventions(r fsRepo, u *detectedUnit, composer composerFile, d *detection) {
	f := u.fields
	if m := healthRe.FindStringSubmatch(r.read("bootstrap/app.php")); m != nil {
		f.set("health_path", m[1], "detected: health route in bootstrap/app.php")
	}
	lv := newOmap()
	if _, ok := composer.Require["laravel/horizon"]; ok {
		lv.set("horizon", true, "detected: laravel/horizon in composer.json")
	}
	if _, ok := composer.Require["laravel/octane"]; ok && u.Runtime == "frankenphp" {
		lv.set("octane", "frankenphp", "detected: laravel/octane in composer.json")
	}
	for _, src := range []string{"routes/console.php", "app/Console/Kernel.php"} {
		if scheduleRe.MatchString(r.read(src)) {
			lv.set("scheduler", true, "detected: schedules in "+src)
			break
		}
	}
	if !lv.empty() {
		f.set("laravel", lv, "")
	}
	if r.nonEmptyDir("database/migrations") {
		f.sub("deploy", "").sub("hooks", "").set("before_activate", migrateHook, "detected: database/migrations (Falak's Laravel deploy steps, migrations on the leader)")
	}
	if !lv.has("horizon") && r.nonEmptyDir("app/Jobs") {
		conn := ""
		for _, e := range readEnvExample(r) {
			if e.key == "QUEUE_CONNECTION" {
				conn = e.value
			}
		}
		if conn != "" && conn != "sync" && conn != "null" {
			f.sub("workers", "").set("queue", newOmap().set("connection", conn, "detected: QUEUE_CONNECTION in .env.example"), "detected: app/Jobs")
		} else if conn == "" {
			f.sub("workers", "").set("queue", newOmap().set("connection", "database", ""), "detected: app/Jobs")
		}
	}
}

// ---- needs: databases and Redis --------------------------------------------------------------------------------

var (
	pgPackages    = []string{"pg", "postgres", "pg-promise", "@neondatabase/serverless", "@vercel/postgres"}
	mysqlPackages = []string{"mysql2", "mysql", "mariadb"}
	redisPackages = []string{"ioredis", "redis", "bullmq", "bull", "@upstash/redis"}
	prismaRe      = regexp.MustCompile(`provider\s*=\s*"(postgresql|postgres|mysql)"`)
	schemeRe      = regexp.MustCompile(`^(postgres|postgresql|mysql|mariadb)://`)
)

// repoNeeds reads package.json and composer.json of r for detectNeeds.
func repoNeeds(r fsRepo) needs {
	var pkg packageFile
	var composer composerFile
	_ = json.Unmarshal([]byte(r.read("package.json")), &pkg)
	_ = json.Unmarshal([]byte(r.read("composer.json")), &composer)
	return detectNeeds(r, pkg, composer)
}

func detectNeeds(r fsRepo, pkg packageFile, composer composerFile) needs {
	var n needs
	env := envMap(readEnvExample(r))
	setDB := func(engine, why string) {
		if n.db == "" {
			n.db, n.dbWhy = engine, why
		}
	}
	setRedis := func(why string) {
		if n.redis == "" {
			n.redis, n.redisWhy = "redis", why
		}
	}
	laravel := composer.Require["laravel/framework"] != ""

	switch c := env["DB_CONNECTION"]; c {
	case "pgsql":
		setDB("postgresql", "DB_CONNECTION=pgsql in .env.example")
	case "mysql":
		setDB("mysql", "DB_CONNECTION=mysql in .env.example")
	case "mariadb":
		setDB("mariadb", "DB_CONNECTION=mariadb in .env.example")
	}
	keys := make([]string, 0, len(env))
	for k := range env {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, k := range keys {
		if !dbURLKeyRe.MatchString(strings.ToUpper(k)) {
			continue
		}
		if m := schemeRe.FindStringSubmatch(env[k]); m != nil {
			engine := map[string]string{"postgres": "postgresql", "postgresql": "postgresql", "mysql": "mysql", "mariadb": "mariadb"}[m[1]]
			setDB(engine, k+" in .env.example")
		}
	}
	if _, ok := pkg.dep("prisma"); ok || r.exists("prisma/schema.prisma") {
		if m := prismaRe.FindStringSubmatch(r.read("prisma/schema.prisma")); m != nil {
			engine := "postgresql"
			if m[1] == "mysql" {
				engine = "mysql"
			}
			setDB(engine, "provider "+m[1]+" in prisma/schema.prisma")
		}
	}
	for _, p := range pgPackages {
		if _, ok := pkg.dep(p); ok {
			setDB("postgresql", p+" in package.json")
		}
	}
	for _, p := range mysqlPackages {
		if _, ok := pkg.dep(p); ok {
			setDB("mysql", p+" in package.json")
		}
	}
	for _, p := range redisPackages {
		if _, ok := pkg.dep(p); ok {
			setRedis(p + " in package.json")
		}
	}

	// Other languages: their dependency files.
	deps := strings.ToLower(r.read("requirements.txt") + "\n" + r.read("pyproject.toml") + "\n" + r.read("Pipfile"))
	for _, p := range []string{"psycopg", "asyncpg"} {
		if strings.Contains(deps, p) {
			setDB("postgresql", p+" in the Python requirements")
		}
	}
	for _, p := range []string{"mysqlclient", "pymysql"} {
		if strings.Contains(deps, p) {
			setDB("mysql", p+" in the Python requirements")
		}
	}
	if regexp.MustCompile(`(?m)^\s*"?(redis|celery\[redis\])\b`).MatchString(deps) {
		setRedis("redis in the Python requirements")
	}
	gomod := r.read("go.mod")
	for _, p := range []string{"github.com/jackc/pgx", "github.com/lib/pq"} {
		if strings.Contains(gomod, p) {
			setDB("postgresql", p+" in go.mod")
		}
	}
	if strings.Contains(gomod, "github.com/go-sql-driver/mysql") {
		setDB("mysql", "github.com/go-sql-driver/mysql in go.mod")
	}
	for _, p := range []string{"github.com/redis/go-redis", "github.com/go-redis/redis", "github.com/gomodule/redigo"} {
		if strings.Contains(gomod, p) {
			setRedis(p + " in go.mod")
		}
	}
	gemfile := r.read("Gemfile")
	if regexp.MustCompile(`gem ['"]pg['"]`).MatchString(gemfile) {
		setDB("postgresql", "pg in Gemfile")
	}
	if regexp.MustCompile(`gem ['"](redis|sidekiq)['"]`).MatchString(gemfile) {
		setRedis("redis in Gemfile")
	}

	// Redis: Laravel's .env.example always lists REDIS_*; only a store using it counts there.
	if laravel {
		if _, ok := composer.Require["laravel/horizon"]; ok {
			setRedis("laravel/horizon in composer.json")
		}
		if _, ok := composer.Require["predis/predis"]; ok {
			setRedis("predis/predis in composer.json")
		}
		for _, k := range []string{"QUEUE_CONNECTION", "CACHE_STORE", "CACHE_DRIVER", "SESSION_DRIVER", "BROADCAST_CONNECTION"} {
			if env[k] == "redis" {
				setRedis(k + "=redis in .env.example")
			}
		}
	} else {
		for _, k := range keys {
			up := strings.ToUpper(k)
			if redisURLKeyRe.MatchString(up) || redisAddrRe.MatchString(up) || up == "REDIS_HOST" {
				setRedis(k + " in .env.example")
			}
		}
	}
	return n
}

// wireNeeds adds database and Redis units for the apps' needs and builds each app's env.
func (d *detection) wireNeeds(repo fsRepo) {
	var db, redis *detectedUnit
	taken := map[string]bool{}
	for _, u := range d.Units {
		taken[u.Key] = true
		// Units a compose file already brought (replace_with) serve the needs first.
		if u.Type == "database" && db == nil {
			db = u
		}
		if u.Type == "redis" && redis == nil {
			redis = u
		}
	}
	free := func(k string) string {
		if !taken[k] {
			taken[k] = true
			return k
		}
		for i := 2; ; i++ {
			c := fmt.Sprintf("%s-%d", k, i)
			if !taken[c] {
				taken[c] = true
				return c
			}
		}
	}
	apps := append([]*detectedUnit(nil), d.Units...)
	for _, u := range apps {
		if u.Type != "app" && u.Type != "compose" {
			continue
		}
		if u.needs.db != "" && db == nil {
			db = &detectedUnit{Key: free("db"), Type: "database", Engine: u.needs.db, Reasons: []string{u.needs.dbWhy}, fields: newOmap()}
			db.fields.set("type", "database", "")
			db.fields.set("engine", u.needs.db, "detected: "+u.needs.dbWhy)
			d.Units = append(d.Units, db)
		} else if u.needs.db != "" && db.Engine != u.needs.db {
			d.Notes = append(d.Notes, fmt.Sprintf("%s wants %s (%s); %s is %s: add a second database unit if they must differ", u.Key, u.needs.db, u.needs.dbWhy, db.Key, db.Engine))
		}
		if u.needs.redis != "" && redis == nil {
			redis = &detectedUnit{Key: free("redis"), Type: "redis", Engine: "redis", Reasons: []string{u.needs.redisWhy}, fields: newOmap()}
			redis.fields.set("type", "redis", "detected: "+u.needs.redisWhy)
			d.Units = append(d.Units, redis)
		}
	}
	for _, u := range apps {
		if u.Type == "app" || u.Type == "compose" {
			buildEnv(u, db, redis, d)
		}
	}
}
