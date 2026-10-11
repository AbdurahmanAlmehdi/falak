package cli

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
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
	Dockerfile  *starterFile     `json:"dockerfile,omitempty"` // written by --dockerfile
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

type envEntry struct {
	key, value string
}

type detectOptions struct {
	railpack   bool // railpack is installed
	dockerfile bool // --dockerfile: write a starter Dockerfile for unsupported languages
	ctx        context.Context
	lookPath   func(string) (string, error)
}

// fsRepo reads files of a directory.
type fsRepo struct{ dir string }

func (r fsRepo) path(rel string) string { return filepath.Join(r.dir, filepath.FromSlash(rel)) }

func (r fsRepo) exists(rel string) bool {
	_, err := os.Stat(r.path(rel))
	return err == nil
}

func (r fsRepo) isDir(rel string) bool {
	st, err := os.Stat(r.path(rel))
	return err == nil && st.IsDir()
}

func (r fsRepo) read(rel string) string {
	b, err := os.ReadFile(r.path(rel))
	if err != nil {
		return ""
	}
	return string(b)
}

func (r fsRepo) sub(rel string) fsRepo { return fsRepo{dir: r.path(rel)} }

func (r fsRepo) nonEmptyDir(rel string) bool {
	entries, err := os.ReadDir(r.path(rel))
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

// ---- top level -------------------------------------------------------------------------------------------------

func detect(dir string, opt detectOptions) (*detection, error) {
	repo := fsRepo{dir: dir}
	d := &detection{Dir: dir}

	if c := composeFile(repo); c != "" {
		if ok, err := d.detectCompose(repo, c); err != nil || ok {
			return d, err
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
		matches, _ := filepath.Glob(repo.path(g))
		sort.Strings(matches)
		for _, m := range matches {
			rel, err := filepath.Rel(repo.dir, m)
			if err != nil || seen[rel] {
				continue
			}
			rel = filepath.ToSlash(rel)
			if st, err := os.Stat(m); err != nil || !st.IsDir() {
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
	p, err := builder.Detect(r.dir, "")
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
		if plan, err := builder.Detect(r.dir, ""); err == nil {
			u.plan = &plan
			if plan.Framework == "laravel" {
				d.Notes = append(d.Notes, fmt.Sprintf("%s: Laravel in a Dockerfile: migrations, queue workers and the scheduler run as your image runs them", key))
			}
		}
		u.needs = detectNeeds(r, pkg, composer)
		u.envFile = readEnvExample(r)
		return u, nil
	}

	plan, err := (&builder.Builder{LookPath: opt.lookPath}).Plan(opt.ctx, r.dir, "", nil, io.Discard)
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
		why := "detected: composer.json"
		if plan.Framework == "laravel" {
			why = "detected: laravel/framework " + composer.Require["laravel/framework"] + " in composer.json"
		}
		f.set("runtime", "frankenphp", why)
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
	if plan.DetectedBy == "railpack" {
		u.Reasons = append(u.Reasons, "railpack")
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
	envValueRe  = regexp.MustCompile(`^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$`)
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

func readEnvExample(r fsRepo) []envEntry {
	content := ""
	for _, n := range []string{".env.example", ".env.sample", ".env.dist", "example.env"} {
		if content = r.read(n); content != "" {
			break
		}
	}
	var out []envEntry
	seen := map[string]bool{}
	sc := bufio.NewScanner(strings.NewReader(content))
	for sc.Scan() {
		line := strings.TrimSpace(sc.Text())
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		m := envValueRe.FindStringSubmatch(line)
		if m == nil || seen[m[1]] {
			continue
		}
		seen[m[1]] = true
		out = append(out, envEntry{key: m[1], value: unquoteEnv(m[2])})
	}
	return out
}

func unquoteEnv(v string) string {
	v = strings.TrimSpace(v)
	if len(v) >= 2 && (v[0] == '"' || v[0] == '\'') {
		if i := strings.IndexByte(v[1:], v[0]); i >= 0 {
			return v[1 : i+1]
		}
	}
	if i := strings.Index(v, " #"); i >= 0 {
		v = strings.TrimSpace(v[:i])
	}
	return v
}

func envMap(entries []envEntry) map[string]string {
	m := map[string]string{}
	for _, e := range entries {
		m[e.key] = e.value
	}
	return m
}

var (
	pgPackages    = []string{"pg", "postgres", "pg-promise", "@neondatabase/serverless", "@vercel/postgres"}
	mysqlPackages = []string{"mysql2", "mysql", "mariadb"}
	redisPackages = []string{"ioredis", "redis", "bullmq", "bull", "@upstash/redis"}
	prismaRe      = regexp.MustCompile(`provider\s*=\s*"(postgresql|postgres|mysql)"`)
	schemeRe      = regexp.MustCompile(`^(postgres|postgresql|mysql|mariadb)://`)
)

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
	if m := schemeRe.FindStringSubmatch(env["DATABASE_URL"]); m != nil {
		engine := map[string]string{"postgres": "postgresql", "postgresql": "postgresql", "mysql": "mysql", "mariadb": "mariadb"}[m[1]]
		setDB(engine, "DATABASE_URL in .env.example")
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
		for _, k := range []string{"REDIS_URL", "REDIS_HOST"} {
			if _, ok := env[k]; ok {
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
		if u.Type != "app" {
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
		if u.Type == "app" {
			buildEnv(u, db, redis, d)
		}
	}
}
