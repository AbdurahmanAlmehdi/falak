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
			if df := filepath.Join(src, "expected.Dockerfile"); fileExists(df) || *update && fileExists(filepath.Join(h.wd, "Dockerfile")) && !fileExists(filepath.Join(src, "Dockerfile")) {
				b, err := os.ReadFile(filepath.Join(h.wd, "Dockerfile"))
				if err != nil {
					t.Fatal(err)
				}
				golden(t, df, b)
			}
			golden(t, filepath.Join(src, "expected.out"), h.out.Bytes())

			// Every generated file passes the schema and the reference checks.
			doc, err := configspec.Parse(got, "yaml")
			if err != nil {
				t.Fatal(err)
			}
			if issues, err := configspec.Validate(doc); err != nil || len(issues) > 0 {
				t.Fatalf("schema: %v %+v", err, issues)
			}
			for env := range doc.(map[string]any)["environments"].(map[string]any) {
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

func TestInitUnsupportedWithRailpack(t *testing.T) {
	h := newHarness(t, nil)
	h.wd = copyRepo(t, filepath.Join("testdata", "init", "python-unsupported"))
	h.out.Reset()
	h.err.Reset()
	h.app = h.newApp("")
	h.app.LookPath = func(name string) (string, error) { return filepath.Join(t.TempDir(), name), nil } // "installed"
	if code := h.app.Run(t.Context(), []string{"init", "--yes", "--stdout"}); code != 0 {
		t.Fatalf("%d %s", code, h.err.String())
	}
	for _, want := range []string{"runtime: docker # detected: python (requirements.txt); no Dockerfile: built with Railpack", "port: 8000", "mode: docker"} {
		if !strings.Contains(h.out.String(), want) {
			t.Errorf("missing %q in\n%s", want, h.out.String())
		}
	}
}
