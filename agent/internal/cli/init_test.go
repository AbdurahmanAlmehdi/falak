package cli

import (
	"encoding/json"
	"flag"
	"io/fs"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/configspec"
)

var update = flag.Bool("update", false, "rewrite the expected files of testdata/init")

// copyRepo copies a fixture (without its expected.* files) to a temporary directory.
func copyRepo(t *testing.T, src string) string {
	t.Helper()
	dst := t.TempDir()
	err := filepath.WalkDir(src, func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		rel, _ := filepath.Rel(src, p)
		if strings.HasPrefix(d.Name(), "expected.") {
			return nil
		}
		if d.IsDir() {
			return os.MkdirAll(filepath.Join(dst, rel), 0o755)
		}
		b, err := os.ReadFile(p)
		if err != nil {
			return err
		}
		return os.WriteFile(filepath.Join(dst, rel), b, 0o644)
	})
	if err != nil {
		t.Fatal(err)
	}
	return dst
}

func golden(t *testing.T, path string, got []byte) {
	t.Helper()
	if *update {
		if err := os.WriteFile(path, got, 0o644); err != nil {
			t.Fatal(err)
		}
		return
	}
	want, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("%v (run go test -run TestInitGolden ./internal/cli -update)", err)
	}
	if string(want) != string(got) {
		t.Errorf("%s differs (go test ./internal/cli -run TestInitGolden -update rewrites it):\n--- want\n%s\n--- got\n%s", path, want, got)
	}
}

// TestInitGolden runs `falak init --yes` on each example repository of testdata/init and compares the file with
// expected.falak.yaml (and the starter Dockerfile with expected.Dockerfile, an error with expected.err).
func TestInitGolden(t *testing.T) {
	cases, err := os.ReadDir(filepath.Join("testdata", "init"))
	if err != nil {
		t.Fatal(err)
	}
	for _, c := range cases {
		if !c.IsDir() {
			continue
		}
		t.Run(c.Name(), func(t *testing.T) {
			src := filepath.Join("testdata", "init", c.Name())
			args := []string{"init", "--yes"}
			if b, err := os.ReadFile(filepath.Join(src, "expected.args")); err == nil {
				args = append(args, strings.Fields(string(b))...)
			}
			h := newHarness(t, nil)
			h.wd = copyRepo(t, src)
			h.branch = "main"
			code := h.run(args...)
			if _, err := os.Stat(filepath.Join(src, "expected.err")); err == nil {
				if code != ExitError {
					t.Fatalf("code %d, want an error; out %s", code, h.out.String())
				}
				golden(t, filepath.Join(src, "expected.err"), h.err.Bytes())
				if _, err := os.Stat(filepath.Join(h.wd, "falak.yaml")); err == nil {
					t.Fatal("wrote falak.yaml for an unsupported repository")
				}
				return
			}
			if code != 0 {
				t.Fatalf("code %d: %s", code, h.err.String())
			}
			got, err := os.ReadFile(filepath.Join(h.wd, "falak.yaml"))
			if err != nil {
				t.Fatal(err)
			}
			golden(t, filepath.Join(src, "expected.falak.yaml"), got)
			// Starter Dockerfiles init wrote (not in the fixture): expected.Dockerfile, expected.apps-api.Dockerfile, …
			_ = filepath.WalkDir(h.wd, func(p string, de fs.DirEntry, err error) error {
				if err != nil || de.IsDir() || de.Name() != "Dockerfile" {
					return err
				}
				rel, _ := filepath.Rel(h.wd, p)
				if fileExists(filepath.Join(src, rel)) {
					return nil
				}
				name := "expected.Dockerfile"
				if dir := filepath.Dir(rel); dir != "." {
					name = "expected." + strings.ReplaceAll(filepath.ToSlash(dir), "/", "-") + ".Dockerfile"
				}
				b, _ := os.ReadFile(p)
				golden(t, filepath.Join(src, name), b)
				return nil
			})
			golden(t, filepath.Join(src, "expected.out"), h.out.Bytes())

			// Every generated file passes the schema and the reference checks.
			doc, err := configspec.Parse(got, "yaml")
			if err != nil {
				t.Fatal(err)
			}
			if issues, err := configspec.Validate(doc); err != nil || len(issues) > 0 {
				t.Fatalf("schema: %v %+v", err, issues)
			}
			if v := validateOffline(configFile{Path: "falak.yaml", Display: "falak.yaml", Format: "yaml", Raw: got}, ""); !v.Valid {
				t.Fatalf("invalid: %+v", v.Errors)
			}
			for env := range asMapAny(doc.(map[string]any)["environments"]) {
				if _, errs, _ := configspec.Resolve(doc, env); len(errs) > 0 {
					t.Fatalf("resolve %s: %+v", env, errs)
				}
			}
		})
	}
}

func fileExists(p string) bool {
	_, err := os.Stat(p)
	return err == nil
}

func TestInitRefusesToOverwrite(t *testing.T) {
	h := newHarness(t, nil)
	h.wd = copyRepo(t, filepath.Join("testdata", "init", "nextjs"))
	writeFile(t, h.wd, "falak.yaml", "keep me\n")
	if code := h.run("init", "--yes"); code != ExitError || !strings.Contains(h.err.String(), "--force") {
		t.Fatalf("%d %s", code, h.err.String())
	}
	if b, _ := os.ReadFile(filepath.Join(h.wd, "falak.yaml")); string(b) != "keep me\n" {
		t.Fatal("overwritten")
	}
	// --stdout writes nothing; --force replaces.
	if code := h.run("init", "--yes", "--stdout"); code != 0 || !strings.HasPrefix(h.out.String(), "# Written by `falak init`") {
		t.Fatalf("stdout: %d %s", code, h.err.String())
	}
	if code := h.run("init", "--yes", "--force"); code != 0 {
		t.Fatalf("force: %d %s", code, h.err.String())
	}
	if b, _ := os.ReadFile(filepath.Join(h.wd, "falak.yaml")); !strings.Contains(string(b), "runtime: node") {
		t.Fatalf("not replaced: %s", b)
	}
}

func TestInitJSONReportAndFull(t *testing.T) {
	h := newHarness(t, nil)
	h.wd = copyRepo(t, filepath.Join("testdata", "init", "laravel-postgres-redis-horizon"))
	if code := h.run("init", "--json"); code != 0 {
		t.Fatalf("%d %s", code, h.err.String())
	}
	var rep struct {
		Kind  string
		Units []struct{ Key, Type, Runtime, Engine string }
		File  string
		Valid bool
	}
	if err := json.Unmarshal(h.out.Bytes(), &rep); err != nil {
		t.Fatal(err)
	}
	if rep.Kind != "app" || len(rep.Units) != 3 || rep.Units[1].Engine != "postgresql" || !rep.Valid || !strings.Contains(rep.File, "horizon: true") {
		t.Fatalf("%+v", rep)
	}
	if fileExists(filepath.Join(h.wd, "falak.yaml")) {
		t.Fatal("--json wrote the file")
	}
	if code := h.run("init", "--yes", "--stdout", "--full"); code != 0 {
		t.Fatalf("full: %d %s", code, h.err.String())
	}
	for _, want := range []string{"strategy: zero-downtime # default for frankenphp", "keep_releases: 5 # default", "version: \"17\" # default: the newest", "framework: laravel"} {
		if !strings.Contains(h.out.String(), want) {
			t.Errorf("--full: missing %q in\n%s", want, h.out.String())
		}
	}
}

func TestSecretKeys(t *testing.T) {
	for k, want := range map[string]bool{
		"APP_KEY": true, "STRIPE_SECRET": true, "GITHUB_TOKEN": true, "DB_PASSWORD": true, "MAIL_PASSWORD": true,
		"AWS_SECRET_ACCESS_KEY": true, "AWS_ACCESS_KEY_ID": true, "SESSION_SECRET": true, "JWT_PRIVATE_KEY": true,
		"VITE_PUSHER_APP_KEY": false, "NEXT_PUBLIC_STRIPE_KEY": false, "STRIPE_PUBLISHABLE_KEY": false, "APP_NAME": false,
		"LOG_LEVEL": false, "CACHE_PREFIX": false, "PUBLIC_KEY": false, "MAIL_FROM_ADDRESS": false,
		"MAPS_APIKEY": true, "PUSH_SK": true, "ATM_PIN": true, "BASIC_AUTH": true, "WEBHOOK_SIGNING": true, "SLACK_WEBHOOK_URL": true,
		"SENTRY_DSN": true, "GOOGLE_CREDENTIALS": true, "REQUEST_SIGNING_KEY": true, "JWT_PRIVATE_KEY_PATH": false,
		"GOOGLE_APPLICATION_CREDENTIALS_FILE": false, "AUTH_URL": false, "NEXTAUTH_URL": false, "GOOGLE_AUTH_CLIENT_ID": false,
		"AUTH_DRIVER": false, "SIGNUP_ENABLED": false, "DESIGN_SYSTEM": false,
	} {
		if got := isSecretKey(k); got != want {
			t.Errorf("%s: %v, want %v", k, got, want)
		}
	}
}

func TestInitAccountQuestions(t *testing.T) {
	h, _ := configHarness(t)
	h.wd = copyRepo(t, filepath.Join("testdata", "init", "nextjs"))
	h.branch = "trunk"
	stdin := strings.Join([]string{
		"1",         // project
		"",          // production branch: trunk
		"web-1",     // servers
		"develop",   // staging branch
		"db-1",      // staging servers
		"shop.test", // domain of web
		"y",         // previews
	}, "\n") + "\n"
	h.out.Reset()
	h.err.Reset()
	h.app = h.newApp(stdin)
	h.app.Interactive = true
	code := h.app.Run(t.Context(), []string{"init"})
	if code != 0 {
		t.Fatalf("%d %s", code, h.err.String())
	}
	b, _ := os.ReadFile(filepath.Join(h.wd, "falak.yaml"))
	for _, want := range []string{
		"  production:\n    branch: trunk # detected: current git branch\n    servers: [web-1]",
		"  staging:\n    branch: develop\n    servers: [db-1]",
		"domains: [shop.test]",
		"previews:\n  enabled: true\n  base: staging",
	} {
		if !strings.Contains(string(b), want) {
			t.Errorf("missing %q in\n%s", want, b)
		}
	}
	link, err := os.ReadFile(filepath.Join(h.wd, ".falak", "project.json"))
	if err != nil || !strings.Contains(string(link), projectID) {
		t.Fatalf("link: %v %s", err, link)
	}
}

func TestSecretValues(t *testing.T) {
	for v, want := range map[string]bool{
		"amqp://user:hunter2@rabbit:5672/":                                true,
		"postgres://app@db:5432/app":                                      false,
		"https://0f1e2d3c4b5a69788796a5b4c3d2e1f0@o1.ingest.sentry.io/42": true,
		"https://hooks.slack.com/services/T000/B000/XXXXXXXX":             true,
		"https://discord.com/api/webhooks/1/abc":                          true,
		"base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=":             true,
		"sk_test_FAKE": true, "pk_live_x": true, "ghp_abc": true, "xoxb-1-2": true,
		"Zx81kq0PLm2Vn7Tb4Rc9Wd3Y":              true,
		"0123456789abcdef0123456789abcdef":      true,
		"pk_test_x":                             false,
		"https://api.example.com/v1":            false,
		"my-application-production-bucket":      false,
		"storage/app/public/uploads2024/images": false,
		"inventory-reports":                     false,
		"Hello World":                           false,
		"${APP_KEY}":                            false,
	} {
		if got := isSecretValue(v); got != want {
			t.Errorf("%q: %v, want %v", v, got, want)
		}
	}
}

func TestUnquoteEnv(t *testing.T) {
	for in, want := range map[string]string{
		`"a\nb"`:          "a\nb",
		`"a\\nb"`:         `a\nb`,
		`"say \"hi\""`:    `say "hi"`,
		`'it''s'`:         "it's",
		`'it\'s'`:         "it's",
		`'raw \n'`:        `raw \n`,
		`plain # comment`: "plain",
		`""`:              "",
		`''`:              "",
	} {
		if got := unquoteEnv(in); got != want {
			t.Errorf("%s: %q, want %q", in, got, want)
		}
	}
}

// No example value that looks like a credential reaches the file, the notes or the --json report.
func TestInitNeverPrintsSecretValues(t *testing.T) {
	h := newHarness(t, nil)
	h.wd = copyRepo(t, filepath.Join("testdata", "init", "secret-values"))
	for _, args := range [][]string{{"init", "--yes", "--stdout"}, {"init", "--json"}} {
		if code := h.run(args...); code != 0 {
			t.Fatalf("%v: %d %s", args, code, h.err.String())
		}
		all := h.out.String() + h.err.String()
		for _, secret := range []string{"hunter2", "0f1e2d3c4b5a", "XXXXXXXX", "sk_test_FAKE", "Zx81kq0", "changeme"} {
			if strings.Contains(all, secret) {
				t.Errorf("%v printed %q:\n%s", args, secret, all)
			}
		}
	}
}

func TestInitSymlinks(t *testing.T) {
	outside := t.TempDir()
	writeFile(t, outside, "real.env", "LEAKED_FROM_OUTSIDE=yes\nSTOLEN_TOKEN=abc\n")
	writeFile(t, outside, "package.json", `{"name":"outside","dependencies":{"next":"15.0.0"}}`)

	// A .env.example (and a whole app directory) pointing out of the repository are not read.
	h := newHarness(t, nil)
	h.wd = copyRepo(t, filepath.Join("testdata", "init", "node-express"))
	_ = os.Remove(filepath.Join(h.wd, ".env.example"))
	if err := os.Symlink(filepath.Join(outside, "real.env"), filepath.Join(h.wd, ".env.example")); err != nil {
		t.Fatal(err)
	}
	if code := h.run("init", "--yes", "--stdout"); code != 0 {
		t.Fatalf("%d %s", code, h.err.String())
	}
	if strings.Contains(h.out.String(), "LEAKED") || !strings.Contains(h.err.String(), ".env.example leads out of the repository") {
		t.Fatalf("read through the symlink:\n%s\n%s", h.out.String(), h.err.String())
	}
	mono := t.TempDir()
	writeFile(t, mono, "pnpm-workspace.yaml", "packages: [apps/*]\n")
	writeFile(t, mono, "apps/web/package.json", `{"name":"web","scripts":{"start":"node a.js"},"dependencies":{"express":"5.0.0"}}`)
	if err := os.Symlink(outside, filepath.Join(mono, "apps", "evil")); err != nil {
		t.Fatal(err)
	}
	h.wd = mono
	if code := h.run("init", "--yes", "--stdout"); code != 0 || strings.Contains(h.out.String(), "evil") {
		t.Fatalf("followed a symlinked app: %d\n%s%s", code, h.out.String(), h.err.String())
	}

	// Writing: never through a symlink, dangling ones included, even with --force.
	h.wd = copyRepo(t, filepath.Join("testdata", "init", "nextjs"))
	target := filepath.Join(outside, "written-by-init.yaml")
	if err := os.Symlink(target, filepath.Join(h.wd, "falak.yaml")); err != nil {
		t.Fatal(err)
	}
	for _, args := range [][]string{{"init", "--yes"}, {"init", "--yes", "--force"}} {
		if code := h.run(args...); code == 0 || !strings.Contains(h.err.String(), "symlink") {
			t.Fatalf("%v: %d %s", args, code, h.err.String())
		}
		if fileExists(target) {
			t.Fatalf("%v wrote outside the repository", args)
		}
	}
	// A symlinked .falak directory: the link file isn't written through it.
	if err := writeInRepo(h.wd, "x/../ok.txt", []byte("ok"), false); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(outside, filepath.Join(h.wd, ".falak")); err != nil {
		t.Fatal(err)
	}
	if err := writeInRepo(h.wd, projectLinkFile, []byte("{}"), true); err == nil || fileExists(filepath.Join(outside, "project.json")) {
		t.Fatalf("wrote through .falak: %v", err)
	}
	// O_EXCL-like creation: an existing file is kept without replace.
	if err := writeInRepo(h.wd, "ok.txt", []byte("again"), false); err == nil {
		t.Fatal("replaced an existing file")
	}
}

func TestInitDockerfileFlags(t *testing.T) {
	h := newHarness(t, nil)
	h.wd = copyRepo(t, filepath.Join("testdata", "init", "monorepo-dockerfile"))
	if code := h.run("init", "--yes", "--stdout", "--dockerfile"); code != ExitUsage || !strings.Contains(h.err.String(), "--stdout can't show") {
		t.Fatalf("%d %s", code, h.err.String())
	}
	if fileExists(filepath.Join(h.wd, "apps", "api", "Dockerfile")) {
		t.Fatal("--stdout wrote a Dockerfile")
	}
}

func TestInitYesDoesNotLinkAProject(t *testing.T) {
	h, _ := configHarness(t)
	h.wd = copyRepo(t, filepath.Join("testdata", "init", "nextjs"))
	if code := h.run("init", "--yes"); code != 0 {
		t.Fatalf("%d %s", code, h.err.String())
	}
	if fileExists(filepath.Join(h.wd, ".falak", "project.json")) || !strings.Contains(h.out.String(), "not linked to a project") {
		t.Fatalf("linked without a choice: %s", h.out.String())
	}
	// -p links (and its environments are used).
	h.wd = copyRepo(t, filepath.Join("testdata", "init", "nextjs"))
	if code := h.run("init", "--yes", "-p", "shop"); code != 0 {
		t.Fatalf("%d %s", code, h.err.String())
	}
	if b, err := os.ReadFile(filepath.Join(h.wd, ".falak", "project.json")); err != nil || !strings.Contains(string(b), projectID) {
		t.Fatalf("link: %v %s", err, b)
	}
}

// Secret-looking keys left empty in the example are optional: commented out, not required references; uncommented
// they are valid.
func TestInitOptionalSecrets(t *testing.T) {
	h := newHarness(t, nil)
	h.wd = copyRepo(t, filepath.Join("testdata", "init", "nextjs"))
	writeFile(t, h.wd, ".env.example", "API_TOKEN=\nVITE_TOKEN=${API_TOKEN}\n")
	if code := h.run("init", "--yes", "--stdout"); code != 0 {
		t.Fatalf("%d %s", code, h.err.String())
	}
	out := h.out.String()
	for _, want := range []string{
		"    # env:\n    #   API_TOKEN: ${{ secrets.API_TOKEN }} # empty in the example env file; uncomment if used\n",
		"    #   VITE_TOKEN: ${{ secrets.API_TOKEN }} # public variable: this value ends up in the browser bundle; uncomment with the secrets it uses\n",
	} {
		if !strings.Contains(out, want) {
			t.Fatalf("missing %q in\n%s", want, out)
		}
	}
	if strings.Contains(h.err.String(), "create these secrets") || !strings.Contains(h.err.String(), "optional secrets") {
		t.Fatalf("notes: %s", h.err.String())
	}
	uncommented := strings.NewReplacer("    # env:", "    env:", "    #   ", "      ").Replace(out)
	if v := validateOffline(configFile{Path: "falak.yaml", Display: "falak.yaml", Format: "yaml", Raw: []byte(uncommented)}, ""); !v.Valid || !strings.Contains(uncommented, "      API_TOKEN: ${{") {
		t.Fatalf("uncommented: %+v\n%s", v.Errors, uncommented)
	}
}
