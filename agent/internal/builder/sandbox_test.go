package builder

import (
	"bytes"
	"context"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

// asRoot makes the builder drop privileges (as when it runs as root) and records chowns instead of making them.
func asRoot(t *testing.T) map[string]int {
	t.Helper()
	owners := map[string]int{}
	oldDrop, oldChown := dropPrivileges, lchown
	dropPrivileges = func() bool { return true }
	lchown = func(p string, uid, _ int) error { owners[p] = uid; return nil }
	t.Cleanup(func() { dropPrivileges, lchown = oldDrop, oldChown })
	return owners
}

func viteBuild(t *testing.T, onBuild func(runnertest.Call)) (*runnertest.Fake, *Builder) {
	t.Helper()
	f := &runnertest.Fake{}
	fakeGit(t, f, filepath.Join(fixtures, "vite-static"))
	f.OnFunc("yarn run build", func(c runnertest.Call) (runner.Result, error) {
		_ = os.MkdirAll(filepath.Join(c.Dir, "dist"), 0o755)
		_ = os.WriteFile(filepath.Join(c.Dir, "dist", "index.html"), []byte("<p>hi</p>"), 0o644)
		if onBuild != nil {
			onBuild(c)
		}
		return runner.Result{}, nil
	})
	return f, newBuilder(t, f)
}

func TestBuildStepsRunAsTheOrganizationsUIDWithACleanEnv(t *testing.T) {
	owners := asRoot(t)
	t.Setenv("FALAK_BUILDER_TOKEN", "builder-token-secret")
	t.Setenv("HTTPS_PROXY", "http://proxy.test:3128")
	f, b := viteBuild(t, nil)
	b.Runner = f
	job := func(id, org string) Job {
		return Job{ID: id, OrganizationID: org, Mode: ModeNative, Repo: Repo{URL: "git@github.com:acme/app.git", DeployKey: "-----KEY-----"}, Native: &NativeSpec{}}
	}
	var out bytes.Buffer
	if _, err := b.Build(context.Background(), job("b1", "01JORGA"), NewNDJSONSink(&out)); err != nil {
		t.Fatalf("%v\n%s", err, out.String())
	}

	cache := filepath.Join(b.CacheDir, "orgs", "01JORGA")
	var steps, reaps int
	for _, c := range f.Calls() {
		switch {
		case c.Name == "git":
			if c.UID != 0 || c.ClearEnv {
				t.Fatalf("git runs as the builder: %+v", c.Cmd)
			}
		case c.Line == "/bin/sh -c kill -9 -1":
			reaps++
			if c.UID != orgUIDBase || steps != 2 {
				t.Fatalf("reap uid %d after %d steps", c.UID, steps)
			}
		default:
			steps++
			env := strings.Join(c.Env, "\n")
			if !c.ClearEnv || c.UID != orgUIDBase || c.GID != orgUIDBase || strings.Contains(env, "builder-token-secret") {
				t.Fatalf("step %s: clear=%v uid=%d env=%v", c.Line, c.ClearEnv, c.UID, c.Env)
			}
			for _, want := range []string{"npm_config_cache=" + filepath.Join(cache, "npm"), "YARN_CACHE_FOLDER=" + filepath.Join(cache, "yarn"), "COREPACK_HOME=" + filepath.Join(cache, "corepack"), "HTTPS_PROXY=http://proxy.test:3128", "PATH="} {
				if !strings.Contains(env, want) {
					t.Fatalf("step %s env missing %s:\n%s", c.Line, want, env)
				}
			}
			// The deploy key is gone and the workspace is the org uid's before any step runs.
			ws := filepath.Dir(c.Dir) // app = src (no subdir)
			if _, err := os.Stat(filepath.Join(ws, "secrets")); !os.IsNotExist(err) {
				t.Fatalf("secrets dir present during %s: %v", c.Line, err)
			}
			if !strings.Contains(env, "HOME="+filepath.Join(ws, "home")) || owners[filepath.Join(ws, "src", "package.json")] != orgUIDBase || owners[filepath.Join(ws, "home")] != orgUIDBase {
				t.Fatalf("step %s: home/ownership %v", c.Line, owners)
			}
		}
	}
	if steps != 2 || reaps != 1 || owners[cache] != orgUIDBase {
		t.Fatalf("steps=%d reaps=%d owners=%v", steps, reaps, owners)
	}
	if st, err := os.Stat(b.WorkDir); err != nil || st.Mode().Perm() != 0o711 {
		t.Fatalf("work dir mode: %v %v", st.Mode(), err)
	}
	if st, err := os.Stat(filepath.Join(b.CacheDir, "orgs", "uids.json")); err != nil || st.Mode().Perm() != 0o600 {
		t.Fatalf("uids.json: %v", err)
	}

	// Another organization gets the next uid; the first keeps its own.
	for _, org := range []string{"01JORGB", "01JORGA"} {
		f2, b2 := viteBuild(t, nil)
		b2.WorkDir, b2.CacheDir, b2.ArtifactsDir = b.WorkDir, b.CacheDir, b.ArtifactsDir
		if _, err := b2.Build(context.Background(), job("b-"+org, org), NewNDJSONSink(&out)); err != nil {
			t.Fatal(err)
		}
		want := uint32(orgUIDBase)
		if org == "01JORGB" {
			want++
		}
		if c := f2.Calls()[len(f2.Calls())-1]; c.UID != want {
			t.Fatalf("%s: uid %d, want %d", org, c.UID, want)
		}
	}
}

func TestBuildStepsKeepTheBuilderUIDWhenNotRoot(t *testing.T) {
	dropPrivileges = func() bool { return false }
	t.Cleanup(func() { dropPrivileges = func() bool { return os.Geteuid() == 0 } })
	t.Setenv("FALAK_BUILDER_TOKEN", "builder-token-secret")
	f, b := viteBuild(t, nil)
	if _, err := b.Build(context.Background(), Job{ID: "b1", Mode: ModeNative, Repo: Repo{URL: "https://x/y.git"}, Native: &NativeSpec{}}, NewNDJSONSink(&bytes.Buffer{})); err != nil {
		t.Fatal(err)
	}
	for _, c := range f.Calls() {
		if c.Name == "yarn" && (c.UID != 0 || !c.ClearEnv || slices.ContainsFunc(c.Env, func(e string) bool { return strings.HasPrefix(e, "FALAK_BUILDER_TOKEN=") })) {
			t.Fatalf("%+v", c.Cmd)
		}
		if strings.Contains(c.Line, "kill") {
			t.Fatal("nothing to reap without a build uid")
		}
	}
}

func TestPackagingRefusesAnOutputDirOutsideTheCheckout(t *testing.T) {
	outside := t.TempDir()
	_ = os.WriteFile(filepath.Join(outside, "secret"), []byte("root-only"), 0o600)
	_, b := viteBuild(t, func(c runnertest.Call) {
		_ = os.RemoveAll(filepath.Join(c.Dir, "dist"))
		_ = os.Symlink(outside, filepath.Join(c.Dir, "dist"))
	})
	_, err := b.Build(context.Background(), Job{ID: "b1", Mode: ModeNative, Repo: Repo{URL: "https://x/y.git"}, Native: &NativeSpec{}}, NewNDJSONSink(&bytes.Buffer{}))
	if err == nil || !strings.Contains(err.Error(), "outside the repository") {
		t.Fatalf("err = %v", err)
	}
}

func TestGitAllowsOnlyHTTPSAndSSH(t *testing.T) {
	env, err := gitEnv(Repo{URL: "https://github.com/acme/app.git", Token: "t0ken"}, t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	got := strings.Join(env, "\n")
	for _, want := range []string{"GIT_CONFIG_COUNT=4", "GIT_CONFIG_KEY_0=http.extraHeader", "GIT_CONFIG_KEY_1=protocol.allow\nGIT_CONFIG_VALUE_1=never", "GIT_CONFIG_KEY_2=protocol.https.allow\nGIT_CONFIG_VALUE_2=always", "GIT_CONFIG_KEY_3=protocol.ssh.allow\nGIT_CONFIG_VALUE_3=always"} {
		if !strings.Contains(got, want) {
			t.Fatalf("missing %q in\n%s", want, got)
		}
	}
	env, _ = gitEnv(Repo{URL: "http://gitea.lan/acme/app.git"}, t.TempDir())
	if got := strings.Join(env, "\n"); !strings.Contains(got, "GIT_CONFIG_COUNT=4") || !strings.Contains(got, "GIT_CONFIG_KEY_3=protocol.http.allow") {
		t.Fatal(got)
	}
	env, _ = gitEnv(Repo{URL: "git://git.lan/acme/app.git"}, t.TempDir())
	if got := strings.Join(env, "\n"); !strings.Contains(got, "GIT_CONFIG_COUNT=4") || !strings.Contains(got, "GIT_CONFIG_KEY_3=protocol.git.allow") || strings.Contains(got, "protocol.http.allow") {
		t.Fatal(got)
	}
}

func TestDockerCacheMountsAreScopedPerOrganization(t *testing.T) {
	d := dockerBuild{Spec: DockerSpec{Image: "r/a:1", BuildArgs: map[string]string{"A": "1", "BUILDKIT_CACHE_MOUNT_NS": "other-org"}}, ContextDir: "/c", Dockerfile: "/c/Dockerfile", CacheNS: "01JORGA"}
	for _, args := range [][]string{d.BuildxArgs(), d.BuildctlArgs()} {
		got := strings.Join(args, " ")
		if !strings.Contains(got, "BUILDKIT_CACHE_MOUNT_NS=01JORGA") || strings.Contains(got, "other-org") || strings.Contains(got, "cache-key") {
			t.Fatal(got)
		}
	}
	d.Railpack = true
	if got := strings.Join(d.BuildctlArgs(), " "); !strings.Contains(got, "build-arg:cache-key=01JORGA") {
		t.Fatal(got)
	}
	d.CacheNS = "" // single-tenant: the job's args as they are
	if got := strings.Join(d.BuildxArgs(), " "); !strings.Contains(got, "BUILDKIT_CACHE_MOUNT_NS=other-org") {
		t.Fatal(got)
	}
}
