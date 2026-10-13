package builder

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

func newBuilder(t *testing.T, f runner.Runner) *Builder {
	t.Helper()
	root := t.TempDir()
	return &Builder{Runner: f, WorkDir: filepath.Join(root, "work"), CacheDir: filepath.Join(root, "cache"), ArtifactsDir: filepath.Join(root, "artifacts"), LookPath: noRailpack}
}

func TestNativeLaravelBuildLocalArtifact(t *testing.T) {
	f := &runnertest.Fake{}
	fakeGit(t, f, filepath.Join(fixtures, "laravel"))
	f.OnFunc("composer install", func(c runnertest.Call) (runner.Result, error) {
		_ = os.MkdirAll(filepath.Join(c.Dir, "vendor"), 0o755)
		_ = os.WriteFile(filepath.Join(c.Dir, "vendor", "autoload.php"), []byte("<?php\n"), 0o644)
		return runner.Result{Stdout: []byte("Generating optimized autoload files\n")}, nil
	})
	f.OnFunc("npm ci", func(c runnertest.Call) (runner.Result, error) {
		_ = os.MkdirAll(filepath.Join(c.Dir, "node_modules", "vite"), 0o755)
		return runner.Result{}, nil
	})
	f.OnFunc("npm run build", func(c runnertest.Call) (runner.Result, error) {
		_ = os.MkdirAll(filepath.Join(c.Dir, "public", "build"), 0o755)
		_ = os.WriteFile(filepath.Join(c.Dir, "public", "build", "manifest.json"), []byte("{}"), 0o644)
		return runner.Result{Stdout: []byte("vite v6 building for production...\n")}, nil
	})
	b := newBuilder(t, f)
	var out bytes.Buffer
	job := Job{ID: "01JBUILD0000000000000000AA", Mode: ModeNative, Repo: Repo{URL: "https://github.com/acme/app.git", Ref: "main", Token: "ghs_supersecret"},
		Env: map[string]string{"VITE_APP_NAME": "Acme"}, Native: &NativeSpec{DownloadURL: "https://artifacts.falak.test/01JBUILD.tar.gz"}}
	res, err := b.Build(context.Background(), job, NewNDJSONSink(&out))
	if err != nil {
		t.Fatalf("%v\n%s", err, out.String())
	}

	// Result → deploy.fetch artifact.
	if res.Commit != "0123456789abcdef0123456789abcdef01234567" || res.Artifact == nil || res.Manifest == nil {
		t.Fatalf("res = %+v", res)
	}
	data, err := os.ReadFile(res.Artifact.Path)
	if err != nil {
		t.Fatal(err)
	}
	sum := sha256.Sum256(data)
	if hex.EncodeToString(sum[:]) != res.Artifact.SHA256 || int64(len(data)) != res.Artifact.SizeBytes || res.Artifact.Format != "tar.gz" || res.Artifact.URL != job.Native.DownloadURL {
		t.Fatalf("artifact = %+v", res.Artifact)
	}
	var names []string
	for _, h := range tarEntries(t, data) {
		names = append(names, h.Name)
		if !h.ModTime.Equal(time.Unix(1700000000, 0)) {
			t.Fatalf("mtime should be the commit time, got %v", h.ModTime)
		}
	}
	for _, want := range []string{"vendor/autoload.php", "public/build/manifest.json", "artisan"} {
		if !contains(names, want) {
			t.Fatalf("missing %s", want)
		}
	}
	for _, n := range names {
		if strings.HasPrefix(n, "node_modules") || strings.HasPrefix(n, ".git") {
			t.Fatalf("%s must not ship in a PHP release", n)
		}
	}

	// Manifest content (returned and written next to the tarball).
	m := res.Manifest
	if m.Schema != 1 || m.Runtime != "php" || m.Provider != "laravel" || m.PHPVersion != "8.3" || m.Entrypoint != "public/index.php" ||
		m.PackageManager != "npm" || m.DetectedBy != "builtin" || m.Commit != res.Commit || m.CommitTime != "2023-11-14T22:13:20Z" ||
		m.Artifact.SHA256 != res.Artifact.SHA256 || len(m.Steps) != 3 || m.BuiltAt == "" || !strings.HasPrefix(m.Builder, "falak-builder ") {
		t.Fatalf("manifest = %+v", m)
	}
	mb, err := os.ReadFile(filepath.Join(b.ArtifactsDir, job.ID+".manifest.json"))
	if err != nil {
		t.Fatal(err)
	}
	var disk Manifest
	if err := json.Unmarshal(mb, &disk); err != nil || disk.Artifact.SHA256 != m.Artifact.SHA256 {
		t.Fatalf("manifest.json = %s", mb)
	}

	// Build env: caches + job env; token never on a command line; workspace cleaned.
	for _, c := range f.Calls() {
		if strings.Contains(c.Line, "ghs_supersecret") {
			t.Fatalf("token on command line: %s", c.Line)
		}
		if c.Name == "composer" {
			env := strings.Join(c.Env, "\n")
			for _, want := range []string{"COMPOSER_CACHE_DIR=" + filepath.Join(b.CacheDir, "composer"), "COMPOSER_HOME=" + filepath.Join(b.CacheDir, "composer-home"), "VITE_APP_NAME=Acme", "CI=true", "SOURCE_DATE_EPOCH=1700000000"} {
				if !strings.Contains(env, want) {
					t.Fatalf("composer env missing %s", want)
				}
			}
		}
		if c.Name == "git" && c.Args[2] == "fetch" {
			env := strings.Join(c.Env, "\n")
			want := "GIT_CONFIG_VALUE_0=Authorization: Basic " + base64.StdEncoding.EncodeToString([]byte("x-access-token:ghs_supersecret"))
			if !strings.Contains(env, want) || !strings.Contains(env, "GIT_TERMINAL_PROMPT=0") {
				t.Fatalf("git env = %v", c.Env)
			}
			if got := strings.Join(c.Args[2:], " "); got != "fetch -q --depth 1 --no-tags origin main" {
				t.Fatalf("fetch = %s", got)
			}
		}
	}
	if entries, _ := os.ReadDir(b.WorkDir); len(entries) != 0 {
		t.Fatalf("workspace not cleaned: %v", entries)
	}

	// Event stream: schema-valid, progress monotonic, finished carries the result.
	evs := validateNDJSON(t, out.Bytes())
	last := evs[len(evs)-1]
	if *last.ExitCode != 0 || last.Error != "" {
		t.Fatalf("finished = %+v", last)
	}
	rb, _ := json.Marshal(last.Result)
	if !bytes.Contains(rb, []byte(res.Artifact.SHA256)) {
		t.Fatalf("finished result lacks artifact: %s", rb)
	}
	prev := -1.0
	var output string
	for _, ev := range evs {
		if ev.Kind == commands.KindProgress {
			if *ev.Progress < prev {
				t.Fatalf("progress went backwards")
			}
			prev = *ev.Progress
		}
		output += ev.Data
	}
	for _, want := range []string{"==> Cloning https://github.com/acme/app.git (main)", "$ composer install", "vite v6 building", "==> Detected laravel"} {
		if !strings.Contains(output, want) {
			t.Fatalf("output missing %q:\n%s", want, output)
		}
	}
}

func TestNativeUploadPresignedPUT(t *testing.T) {
	var mu sync.Mutex
	var got []byte
	var hdr http.Header
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPut {
			http.Error(w, "method", 405)
			return
		}
		mu.Lock()
		got, _ = io.ReadAll(r.Body)
		hdr = r.Header.Clone()
		mu.Unlock()
	}))
	defer srv.Close()
	f := &runnertest.Fake{}
	fakeGit(t, f, filepath.Join(fixtures, "static"))
	b := newBuilder(t, f)
	b.ArtifactsDir = ""
	job := Job{ID: "b-upload", Repo: Repo{URL: "git@github.com:acme/site.git", DeployKey: "-----BEGIN OPENSSH PRIVATE KEY-----\nabc\n-----END OPENSSH PRIVATE KEY-----"},
		Native: &NativeSpec{Upload: &Upload{URL: srv.URL + "/bucket/b.tar.gz?X-Amz-Signature=sig", Headers: map[string]string{"x-amz-acl": "private"}}}}
	var out bytes.Buffer
	res, err := b.Build(context.Background(), job, NewNDJSONSink(&out))
	if err != nil {
		t.Fatalf("%v\n%s", err, out.String())
	}
	sum := sha256.Sum256(got)
	if hex.EncodeToString(sum[:]) != res.Artifact.SHA256 || hdr.Get("Content-Type") != "application/gzip" || hdr.Get("X-Amz-Acl") != "private" {
		t.Fatalf("uploaded %d bytes, hdr %v, res %+v", len(got), hdr, res.Artifact)
	}
	if res.Manifest.Runtime != "static" || res.Artifact.Path != "" {
		t.Fatalf("res = %+v", res)
	}
	// Deploy key → GIT_SSH_COMMAND with a 0600 key file.
	var sshCmd string
	for _, c := range f.Calls() {
		for _, e := range c.Env {
			if strings.HasPrefix(e, "GIT_SSH_COMMAND=") {
				sshCmd = e
			}
		}
	}
	if !strings.Contains(sshCmd, "-o IdentitiesOnly=yes") || !strings.Contains(sshCmd, "StrictHostKeyChecking=accept-new") {
		t.Fatalf("GIT_SSH_COMMAND = %q", sshCmd)
	}
	if strings.Contains(out.String(), "X-Amz-Signature=sig") || strings.Contains(out.String(), "BEGIN OPENSSH") {
		t.Fatal("secret leaked into events")
	}
}

func TestGitEnvKeyFilePerms(t *testing.T) {
	dir := t.TempDir()
	env, err := gitEnv(Repo{URL: "git@host:r.git", DeployKey: "KEY", KnownHosts: "host ssh-ed25519 AAAA"}, dir)
	if err != nil {
		t.Fatal(err)
	}
	st, err := os.Stat(filepath.Join(dir, "deploy_key"))
	if err != nil || st.Mode().Perm() != 0o600 {
		t.Fatalf("key perms %v %v", st.Mode(), err)
	}
	if !strings.Contains(strings.Join(env, " "), "StrictHostKeyChecking=yes") {
		t.Fatalf("pinned known_hosts should be strict: %v", env)
	}
	if _, err := gitEnv(Repo{URL: "git@host:r.git", Token: "t"}, dir); err == nil {
		t.Fatal("token with ssh url should fail")
	}
}

func TestBuildFailureEvents(t *testing.T) {
	f := &runnertest.Fake{}
	fakeGit(t, f, filepath.Join(fixtures, "next"))
	f.On("pnpm run build", runner.Result{ExitCode: 1, Stderr: []byte("Type error: boom\n")})
	b := newBuilder(t, f)
	var out bytes.Buffer
	_, err := b.Build(context.Background(), Job{ID: "b-fail", Repo: Repo{URL: "https://x/y.git"}}, NewNDJSONSink(&out))
	if err == nil || !strings.Contains(err.Error(), `step "build"`) {
		t.Fatalf("err = %v", err)
	}
	evs := validateNDJSON(t, out.Bytes())
	last := evs[len(evs)-1]
	if *last.ExitCode != 1 || !strings.Contains(last.Error, "boom") || last.Result != nil {
		t.Fatalf("finished = %+v", last)
	}
	if f.Ran("pnpm prune") {
		t.Fatal("ran prune after failed build")
	}
}

func TestInvalidJobStillEmitsFrame(t *testing.T) {
	var out bytes.Buffer
	_, err := newBuilder(t, &runnertest.Fake{}).Build(context.Background(), Job{ID: "b-bad", Mode: "docker"}, NewNDJSONSink(&out))
	if err == nil || !strings.Contains(err.Error(), "docker.image") || !strings.Contains(err.Error(), "repo.url") {
		t.Fatalf("err = %v", err)
	}
	validateNDJSON(t, out.Bytes())
}

// blockingRunner blocks every "sleepy" command until ctx is done.
type blockingRunner struct{ *runnertest.Fake }

func (b blockingRunner) Run(ctx context.Context, c runner.Cmd) (runner.Result, error) {
	if c.Name == "pnpm" {
		<-ctx.Done()
		return runner.Result{ExitCode: -1}, ctx.Err()
	}
	return b.Fake.Run(ctx, c)
}

func TestBuildTimeout(t *testing.T) {
	f := &runnertest.Fake{}
	fakeGit(t, f, filepath.Join(fixtures, "next"))
	b := newBuilder(t, blockingRunner{f})
	var out bytes.Buffer
	_, err := b.Build(context.Background(), Job{ID: "b-slow", Repo: Repo{URL: "https://x/y.git"}, TimeoutS: 1}, NewNDJSONSink(&out))
	if err == nil {
		t.Fatal("expected timeout")
	}
	evs := validateNDJSON(t, out.Bytes())
	last := evs[len(evs)-1]
	if *last.ExitCode != ExitTimeout || !strings.Contains(last.Error, "timed out") {
		t.Fatalf("finished = %+v", last)
	}
	if entries, _ := os.ReadDir(b.WorkDir); len(entries) != 0 {
		t.Fatal("workspace not cleaned after timeout")
	}
}

func TestDecodeJob(t *testing.T) {
	j, err := DecodeJob(strings.NewReader(`{"id":"b1","repo":{"url":"https://x/y.git"}}`))
	if err != nil || j.Mode != ModeNative || j.Timeout() != DefaultTimeout {
		t.Fatalf("j=%+v err=%v", j, err)
	}
	if _, err := DecodeJob(strings.NewReader(`{"id":"b1","repo":{"url":"u"},"bogus":1}`)); err == nil {
		t.Fatal("unknown fields must be rejected")
	}
	if _, err := DecodeJob(strings.NewReader(`{"id":"../x","repo":{"url":"u"},"subdir":"../../etc"}`)); err == nil {
		t.Fatal("bad id/subdir accepted")
	}
}

func TestPnpmAndYarnComeFromCorepackShimsWhenNotInstalled(t *testing.T) {
	plan := func() Plan {
		return Plan{PackageManager: "pnpm", Steps: []Step{
			{Name: "pnpm install", Cmd: []string{"pnpm", "install", "--frozen-lockfile"}},
			{Name: "build", Cmd: []string{"pnpm", "run", "build"}},
			{Name: "custom", Cmd: []string{"sh", "-c", "echo hi"}},
		}}
	}
	have := func(bins ...string) func(string) (string, error) {
		return func(name string) (string, error) {
			for _, b := range bins {
				if b == name {
					return "/usr/local/bin/" + name, nil
				}
			}
			return "", os.ErrNotExist
		}
	}

	p := plan()
	if !(&Builder{LookPath: have("node", "corepack")}).viaCorepack(&p, "/ws/bin") {
		t.Fatal("expected corepack shims")
	}
	var got []string
	for _, st := range p.Steps {
		got = append(got, strings.Join(st.Cmd, " "))
	}
	want := []string{"corepack enable --install-directory /ws/bin pnpm", "/ws/bin/pnpm install --frozen-lockfile", "/ws/bin/pnpm run build", "sh -c echo hi"}
	if strings.Join(got, " | ") != strings.Join(want, " | ") {
		t.Fatal(got)
	}

	for name, lookPath := range map[string]func(string) (string, error){
		"pnpm installed": have("pnpm", "corepack"),
		"no corepack":    have("node"),
		"npm project":    have("corepack"),
	} {
		p := plan()
		if name == "npm project" {
			p.PackageManager = "npm"
		}
		if (&Builder{LookPath: lookPath}).viaCorepack(&p, "/ws/bin") || p.Steps[0].Cmd[0] != "pnpm" {
			t.Fatal(name, p.Steps[0].Cmd)
		}
	}

	// Nested `pnpm …` calls from package scripts find the shim first on PATH.
	env := strings.Join((&Builder{CacheDir: "/cache"}).buildEnv(&job{binDir: "/ws/bin"}), "\n")
	if !strings.Contains(env, "PATH=/ws/bin"+string(os.PathListSeparator)) {
		t.Fatal(env)
	}
}

func TestBuildEnvKeepsCorepackNonInteractiveAndCached(t *testing.T) {
	b := &Builder{CacheDir: "/cache"}
	env := strings.Join(b.buildEnv(&job{}), "\n")
	if !strings.Contains(env, "COREPACK_ENABLE_DOWNLOAD_PROMPT=0") || !strings.Contains(env, "COREPACK_HOME=/cache/corepack") || !strings.Contains(env, "COREPACK_DEFAULT_TO_LATEST=0") {
		t.Fatal(env)
	}
}
