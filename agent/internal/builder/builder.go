package builder

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/redact"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/version"
)

// Builder runs build jobs. Zero values of optional fields get sensible defaults.
type Builder struct {
	Runner        runner.Runner
	HTTP          *http.Client
	WorkDir       string // per-job workspaces are created (and removed) under here
	CacheDir      string // persistent package-manager / BuildKit caches
	ArtifactsDir  string // native artifacts land here when the job has no upload URL
	LookPath      func(string) (string, error)
	Now           func() time.Time
	Log           *slog.Logger
	KeepWorkspace bool
}

func (b *Builder) defaults() {
	if b.Runner == nil {
		b.Runner = runner.Exec{}
	}
	if b.HTTP == nil {
		b.HTTP = &http.Client{Timeout: 30 * time.Minute}
	}
	if b.WorkDir == "" {
		b.WorkDir = filepath.Join(os.TempDir(), "falak-builder")
	}
	if b.CacheDir == "" {
		b.CacheDir = filepath.Join(b.WorkDir, "cache")
	}
	if b.LookPath == nil {
		b.LookPath = exec.LookPath
	}
	if b.Now == nil {
		b.Now = time.Now
	}
	if b.Log == nil {
		b.Log = slog.New(slog.NewTextHandler(io.Discard, nil))
	}
}

// ExitTimeout is the finished exit_code when a build exceeds its timeout (same as agent commands).
const ExitTimeout = commands.ExitTimeout

// job carries per-build state.
type job struct {
	Job
	st        *commands.EventStream
	out, errw io.Writer
	ws        string // workspace root
	src       string // repo checkout
	app       string // app root (src/subdir)
	secrets   string
	checkout  checkout
	started   time.Time
	steps     []StepResult
	// cacheSuffix separates the BuildKit cache of each compose service.
	cacheSuffix string
	// binDir holds corepack's pnpm/yarn shims when the builder has none (prepended to PATH).
	binDir string
}

func (j *job) logf(format string, args ...any) { fmt.Fprintf(j.out, "==> "+format+"\n", args...) }

// Build runs one job end to end and streams events into sink (started … finished). The returned
// error is also reported in the finished event.
func (b *Builder) Build(ctx context.Context, jb Job, sink commands.EventSink) (res Result, err error) {
	b.defaults()
	st := commands.NewEventStream(jb.ID, sink, b.Now)
	st.Started()
	j := &job{Job: jb, st: st, started: b.Now()}
	// Registry and repository tokens can be short: builds mask from 4 bytes.
	secrets := redact.NewSetMin(4, jb.Secrets()...)
	out, errw := redact.NewWriter(secrets, st.Stdout()), redact.NewWriter(secrets, st.Stderr())
	j.out, j.errw = out, errw
	res = Result{BuildID: jb.ID, Mode: jb.Mode}
	defer func() {
		res.DurationMS = b.Now().Sub(j.started).Milliseconds()
		code, msg := 0, ""
		if err != nil {
			code, msg = 1, secrets.String(err.Error())
			if errors.Is(ctx.Err(), context.DeadlineExceeded) || errors.Is(err, context.DeadlineExceeded) {
				code, msg = ExitTimeout, secrets.String(fmt.Sprintf("build timed out after %s: %v", jb.Timeout(), err))
			}
			fmt.Fprintf(j.errw, "build failed: %s\n", msg)
		}
		_ = out.Flush()
		_ = errw.Flush()
		if err != nil {
			st.Finished(code, nil, msg)
			return
		}
		st.Finished(0, res, "")
	}()
	if err = jb.Validate(); err != nil {
		return res, err
	}
	res.Mode = jb.Mode
	ctx, cancel := context.WithTimeout(ctx, jb.Timeout())
	defer cancel()

	if err = os.MkdirAll(b.WorkDir, 0o755); err != nil {
		return res, err
	}
	if j.ws, err = os.MkdirTemp(b.WorkDir, "build-"+jb.ID+"-"); err != nil {
		return res, err
	}
	if !b.KeepWorkspace {
		defer os.RemoveAll(j.ws)
	}
	j.src = filepath.Join(j.ws, "src")
	j.app = filepath.Join(j.src, filepath.FromSlash(jb.Subdir))
	j.secrets = filepath.Join(j.ws, "secrets")
	if err = os.MkdirAll(j.secrets, 0o700); err != nil {
		return res, err
	}

	j.logf("Cloning %s (%s)", redactURL(jb.Repo.URL), firstNonEmpty(jb.Repo.Commit, jb.Repo.Ref, "HEAD"))
	if j.checkout, err = b.clone(ctx, jb.Repo, j.src, j.secrets, j.out, j.errw); err != nil {
		return res, fmt.Errorf("clone: %w", err)
	}
	_ = os.RemoveAll(j.secrets)
	res.Commit = j.checkout.Commit
	j.logf("Checked out %s", j.checkout.Commit)
	st.Progress(0.1)
	if _, err = os.Stat(j.app); err != nil {
		return res, fmt.Errorf("subdir %q: %w", jb.Subdir, err)
	}
	if err = insideCheckout(j.src, j.app); err != nil {
		return res, fmt.Errorf("subdir %q: %w", jb.Subdir, err)
	}

	switch jb.Mode {
	case ModeDocker:
		if jb.Compose != nil {
			res.Compose, err = b.buildCompose(ctx, j)
		} else {
			res.Image, err = b.buildDocker(ctx, j)
		}
	default:
		res.Artifact, res.Manifest, err = b.buildNative(ctx, j)
	}
	return res, err
}

// Plan detects the build plan of dir (builtin detector, enriched by Railpack when enabled).
func (b *Builder) Plan(ctx context.Context, dir, hint string, useRailpack *bool, out io.Writer) (Plan, error) {
	b.defaults()
	plan, err := Detect(dir, hint)
	rp := b.railpackEnabled(useRailpack)
	if !rp {
		return plan, err
	}
	res, rerr := runner.Check(ctx, b.Runner, runner.Cmd{Name: "railpack", Args: []string{"info", "--format", "json", dir}})
	if rerr != nil {
		fmt.Fprintf(out, "railpack info failed (%v); using the built-in detector\n", rerr)
		return plan, err
	}
	var info railpackInfo
	if jerr := json.Unmarshal(res.Stdout, &info); jerr != nil {
		fmt.Fprintf(out, "railpack info: unparseable output (%v); using the built-in detector\n", jerr)
		return plan, err
	}
	if err != nil {
		if len(info.DetectedProviders) > 0 {
			return plan, fmt.Errorf("railpack detected %s, which native builds do not support; use the docker build mode", strings.Join(info.DetectedProviders, ", "))
		}
		return plan, err
	}
	mergeRailpack(&plan, info)
	return plan, nil
}

func (b *Builder) railpackEnabled(pref *bool) bool {
	if pref != nil && !*pref {
		return false
	}
	_, err := b.LookPath("railpack")
	return err == nil
}

// StepResult is recorded in the manifest.
type StepResult struct {
	Name       string `json:"name"`
	Command    string `json:"command"`
	DurationMS int64  `json:"duration_ms"`
}

// Manifest describes a native release artifact (written as manifest.json next to the tarball and
// returned in the finished event's result).
type Manifest struct {
	Schema          int            `json:"schema"`
	BuildID         string         `json:"build_id"`
	Commit          string         `json:"commit,omitempty"`
	CommitTime      string         `json:"commit_time,omitempty"`
	Ref             string         `json:"ref,omitempty"`
	Provider        string         `json:"provider"`
	Framework       string         `json:"framework,omitempty"`
	Runtime         string         `json:"runtime"`
	PHPVersion      string         `json:"php_version,omitempty"`
	NodeVersion     string         `json:"node_version,omitempty"`
	BunVersion      string         `json:"bun_version,omitempty"`
	DenoVersion     string         `json:"deno_version,omitempty"`
	PackageManager  string         `json:"package_manager,omitempty"`
	StartCommand    string         `json:"start_command,omitempty"`
	Entrypoint      string         `json:"entrypoint,omitempty"`
	DetectedBy      string         `json:"detected_by"`
	Steps           []StepResult   `json:"steps"`
	Artifact        ArtifactResult `json:"artifact"`
	BuildDurationMS int64          `json:"build_duration_ms"`
	BuiltAt         string         `json:"built_at"`
	Builder         string         `json:"builder"`
}

// defaultMTime is used for archive entries when the commit time is unknown.
var defaultMTime = time.Date(2000, 1, 1, 0, 0, 0, 0, time.UTC)

func (b *Builder) buildNative(ctx context.Context, j *job) (*ArtifactResult, *Manifest, error) {
	spec := j.Native
	if spec == nil {
		spec = &NativeSpec{}
	}
	j.logf("Detecting stack")
	plan, err := b.Plan(ctx, j.app, j.Runtime, spec.UseRailpack, j.out)
	if err != nil {
		return nil, nil, err
	}
	applyOverrides(&plan, spec)
	j.logf("Detected %s (runtime %s, via %s)", firstNonEmpty(plan.Framework, plan.Provider), plan.Runtime, plan.DetectedBy)
	for _, n := range plan.Notes {
		fmt.Fprintf(j.out, "note: %s\n", n)
	}
	if b.viaCorepack(&plan, filepath.Join(j.ws, "bin")) {
		j.binDir = filepath.Join(j.ws, "bin")
		if err := os.MkdirAll(j.binDir, 0o755); err != nil {
			return nil, nil, err
		}
		fmt.Fprintf(j.out, "note: %s is not installed; using corepack's shim (version from packageManager, else corepack's default)\n", plan.PackageManager)
	}
	if ok, err := b.viaComposerPhar(ctx, &plan, filepath.Join(j.ws, "bin")); err != nil {
		return nil, nil, err
	} else if ok {
		j.binDir = filepath.Join(j.ws, "bin")
		fmt.Fprintf(j.out, "note: composer is not installed; using composer %s (checksum verified)\n", composerVersion)
	}
	j.st.Progress(0.2)
	if err := b.runSteps(ctx, j, plan.Steps, 0.2, 0.8); err != nil {
		return nil, nil, err
	}

	root := filepath.Join(j.app, filepath.FromSlash(plan.OutputDir))
	if st, err := os.Stat(root); err != nil || !st.IsDir() {
		return nil, nil, fmt.Errorf("build output dir %q not found", plan.OutputDir)
	}
	j.logf("Packaging %s", firstNonEmpty(plan.OutputDir, "app root"))
	mtime := j.checkout.Time
	if mtime.IsZero() {
		mtime = defaultMTime
	}
	tmp := filepath.Join(j.ws, "release.tar.gz")
	f, err := os.Create(tmp)
	if err != nil {
		return nil, nil, err
	}
	excludes := append(append(append([]string{}, DefaultExcludes...), plan.Excludes...), spec.Excludes...)
	ti, err := WriteTarball(f, root, excludes, mtime)
	if cerr := f.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		return nil, nil, fmt.Errorf("package: %w", err)
	}
	fmt.Fprintf(j.out, "release.tar.gz: %d files, %d bytes, sha256 %s\n", ti.Files, ti.SizeBytes, ti.SHA256)
	j.st.Progress(0.85)

	art := &ArtifactResult{SHA256: ti.SHA256, SizeBytes: ti.SizeBytes, Format: "tar.gz", URL: spec.DownloadURL}
	man := &Manifest{
		Schema: 1, BuildID: j.ID, Commit: j.checkout.Commit, Ref: j.Repo.Ref,
		Provider: plan.Provider, Framework: plan.Framework, Runtime: plan.Runtime,
		PHPVersion: plan.PHPVersion, NodeVersion: plan.NodeVersion, BunVersion: plan.BunVersion, DenoVersion: plan.DenoVersion,
		PackageManager: plan.PackageManager, StartCommand: plan.StartCommand, Entrypoint: plan.Entrypoint,
		DetectedBy: plan.DetectedBy, Steps: j.steps, Builder: "falak-builder " + version.Version,
	}
	if man.Steps == nil {
		man.Steps = []StepResult{}
	}
	if !j.checkout.Time.IsZero() {
		man.CommitTime = j.checkout.Time.Format(time.RFC3339)
	}
	if spec.Upload != nil && spec.Upload.URL != "" {
		j.logf("Uploading artifact")
		if err := b.upload(ctx, spec.Upload, tmp, ti.SizeBytes); err != nil {
			return nil, nil, err
		}
	} else {
		if b.ArtifactsDir == "" {
			return nil, nil, errors.New("no upload url in job and no artifacts dir configured")
		}
		if err := os.MkdirAll(b.ArtifactsDir, 0o755); err != nil {
			return nil, nil, err
		}
		dst := filepath.Join(b.ArtifactsDir, j.ID+".tar.gz")
		if err := moveFile(tmp, dst); err != nil {
			return nil, nil, err
		}
		art.Path = dst
		fmt.Fprintf(j.out, "artifact written to %s\n", dst)
	}
	man.Artifact = *art
	man.BuildDurationMS = b.Now().Sub(j.started).Milliseconds()
	man.BuiltAt = b.Now().UTC().Format(time.RFC3339)
	if art.Path != "" {
		mb, _ := json.MarshalIndent(man, "", "  ")
		if err := os.WriteFile(filepath.Join(b.ArtifactsDir, j.ID+".manifest.json"), append(mb, '\n'), 0o644); err != nil {
			return nil, nil, err
		}
	}
	j.st.Progress(0.95)
	return art, man, nil
}

func applyOverrides(p *Plan, s *NativeSpec) {
	shell := func(name, cmd string) Step { return Step{Name: name, Cmd: []string{"sh", "-c", cmd}} }
	if s.InstallCommand != "" || s.BuildCommand != "" {
		var steps []Step
		if s.InstallCommand != "" {
			steps = append(steps, shell("install", s.InstallCommand))
		}
		for _, st := range p.Steps {
			isInstall := strings.Contains(st.Name, "install") && !strings.HasPrefix(st.Name, "composer")
			if (s.InstallCommand != "" && isInstall) || (s.BuildCommand != "" && strings.HasPrefix(st.Name, "build")) {
				continue
			}
			steps = append(steps, st)
		}
		if s.BuildCommand != "" {
			steps = append(steps, Step{Name: "build", Cmd: []string{"sh", "-c", s.BuildCommand}, Env: []string{"NODE_ENV=production"}})
		}
		// keep prune after the build
		var pr, rest []Step
		for _, st := range steps {
			if strings.HasPrefix(st.Name, "prune") {
				pr = append(pr, st)
			} else {
				rest = append(rest, st)
			}
		}
		p.Steps = append(rest, pr...)
	}
	if s.StartCommand != "" {
		p.StartCommand = s.StartCommand
	}
}

// viaCorepack makes pnpm/yarn available through corepack (shipped with Node ≤ 24) when the builder has no
// global install: a first step writes corepack's shim into binDir, which buildEnv puts first on PATH, so
// nested `pnpm …` calls from package scripts resolve too and the project's packageManager pin decides the
// version. Reports whether it did.
func (b *Builder) viaCorepack(p *Plan, binDir string) bool {
	pm := p.PackageManager
	if pm != "pnpm" && pm != "yarn" {
		return false
	}
	uses := false
	for _, st := range p.Steps {
		uses = uses || (len(st.Cmd) > 0 && st.Cmd[0] == pm)
	}
	if !uses {
		return false
	}
	if _, err := b.LookPath(pm); err == nil {
		return false
	}
	if _, err := b.LookPath("corepack"); err != nil {
		return false
	}
	// Top-level steps get the shim's absolute path (exec resolves names with the builder's own PATH).
	for i, st := range p.Steps {
		if len(st.Cmd) > 0 && st.Cmd[0] == pm {
			p.Steps[i].Cmd = append([]string{filepath.Join(binDir, pm)}, st.Cmd[1:]...)
		}
	}
	enable := Step{Name: "corepack enable " + pm, Cmd: []string{"corepack", "enable", "--install-directory", binDir, pm}}
	p.Steps = append([]Step{enable}, p.Steps...)
	return true
}

// buildEnv is the environment for build steps: CI mode, caches under CacheDir, then job env.
func (b *Builder) buildEnv(j *job) []string {
	c := b.CacheDir
	env := []string{
		"CI=true", "FALAK_BUILD=1", "FALAK_BUILD_ID=" + j.ID, "FALAK_COMMIT=" + j.checkout.Commit,
		"COMPOSER_CACHE_DIR=" + filepath.Join(c, "composer"), "COMPOSER_NO_INTERACTION=1", "COMPOSER_ALLOW_SUPERUSER=1",
		// Composer refuses to run without HOME or COMPOSER_HOME, and a builder started by systemd has no HOME.
		"COMPOSER_HOME=" + filepath.Join(c, "composer-home"),
		"npm_config_cache=" + filepath.Join(c, "npm"), "npm_config_store_dir=" + filepath.Join(c, "pnpm"),
		"YARN_CACHE_FOLDER=" + filepath.Join(c, "yarn"), "BUN_INSTALL_CACHE_DIR=" + filepath.Join(c, "bun"),
		"DENO_DIR=" + filepath.Join(c, "deno"), "NEXT_TELEMETRY_DISABLED=1",
		"COREPACK_HOME=" + filepath.Join(c, "corepack"), "COREPACK_ENABLE_DOWNLOAD_PROMPT=0",
		// Unpinned projects get corepack's known-good release, not "latest" (a newer major may not run under this corepack).
		"COREPACK_DEFAULT_TO_LATEST=0",
	}
	if !j.checkout.Time.IsZero() {
		env = append(env, fmt.Sprintf("SOURCE_DATE_EPOCH=%d", j.checkout.Time.Unix()))
	}
	if j.binDir != "" {
		env = append(env, "PATH="+j.binDir+string(os.PathListSeparator)+os.Getenv("PATH"))
	}
	for _, k := range sortedKeys(j.Env) {
		env = append(env, k+"="+j.Env[k])
	}
	return env
}

func (b *Builder) runSteps(ctx context.Context, j *job, steps []Step, from, to float64) error {
	env := b.buildEnv(j)
	for i, s := range steps {
		for _, p := range s.PreClean {
			_ = os.RemoveAll(filepath.Join(j.app, filepath.FromSlash(p)))
		}
		line := strings.Join(s.Cmd, " ")
		j.logf("%s", s.Name)
		fmt.Fprintf(j.out, "$ %s\n", line)
		t0 := b.Now()
		_, err := runner.Check(ctx, b.Runner, runner.Cmd{Name: s.Cmd[0], Args: s.Cmd[1:], Dir: j.app, Env: append(append([]string{}, env...), s.Env...), Stdout: j.out, Stderr: j.errw})
		if err != nil {
			return fmt.Errorf("step %q: %w", s.Name, err)
		}
		j.steps = append(j.steps, StepResult{Name: s.Name, Command: line, DurationMS: b.Now().Sub(t0).Milliseconds()})
		j.st.Progress(from + (to-from)*float64(i+1)/float64(len(steps)))
	}
	return nil
}

func (b *Builder) upload(ctx context.Context, u *Upload, file string, size int64) error {
	var last error
	for attempt := 0; attempt < 3; attempt++ {
		if attempt > 0 {
			select {
			case <-ctx.Done():
				return ctx.Err()
			case <-time.After(time.Duration(attempt) * 2 * time.Second):
			}
		}
		f, err := os.Open(file)
		if err != nil {
			return err
		}
		req, err := http.NewRequestWithContext(ctx, http.MethodPut, u.URL, f)
		if err != nil {
			f.Close()
			return err
		}
		req.ContentLength = size
		req.Header.Set("Content-Type", "application/gzip")
		for k, v := range u.Headers {
			req.Header.Set(k, v)
		}
		resp, err := b.HTTP.Do(req)
		f.Close()
		if err != nil {
			last = fmt.Errorf("upload artifact: %w", redactErr(err))
			continue
		}
		io.Copy(io.Discard, resp.Body)
		resp.Body.Close()
		if resp.StatusCode/100 == 2 {
			return nil
		}
		last = fmt.Errorf("upload artifact: HTTP %d", resp.StatusCode)
		if resp.StatusCode < 500 && resp.StatusCode != 429 {
			return last
		}
	}
	return last
}

// redactErr drops the query string (presigned signatures are secrets) from *url.Error messages.
func redactErr(err error) error {
	var ue *url.Error
	if errors.As(err, &ue) {
		if i := strings.Index(ue.URL, "?"); i >= 0 {
			ue.URL = ue.URL[:i] + "?…"
		}
	}
	return err
}

func moveFile(src, dst string) error {
	if err := os.Rename(src, dst); err == nil {
		return nil
	}
	in, err := os.Open(src)
	if err != nil {
		return err
	}
	defer in.Close()
	tmp := dst + ".partial"
	out, err := os.Create(tmp)
	if err != nil {
		return err
	}
	if _, err := io.Copy(out, in); err != nil {
		out.Close()
		return err
	}
	if err := out.Close(); err != nil {
		return err
	}
	return os.Rename(tmp, dst)
}

func redactURL(raw string) string {
	if i := strings.Index(raw, "://"); i >= 0 {
		rest := raw[i+3:]
		if at := strings.Index(rest, "@"); at >= 0 && at < strings.IndexAny(rest+"/", "/") {
			return raw[:i+3] + "***@" + rest[at+1:]
		}
	}
	return raw
}

// insideCheckout refuses an app root that resolves outside the checkout (a symlinked subdir in the repository).
func insideCheckout(src, app string) error {
	root, err := filepath.EvalSymlinks(src)
	if err != nil {
		return err
	}
	real, err := filepath.EvalSymlinks(app)
	if err != nil {
		return err
	}
	if rel, err := filepath.Rel(root, real); err != nil || rel == ".." || strings.HasPrefix(rel, ".."+string(filepath.Separator)) {
		return errors.New("resolves outside the repository")
	}
	return nil
}

func firstNonEmpty(v ...string) string {
	for _, s := range v {
		if s != "" {
			return s
		}
	}
	return ""
}

func randHex(n int) string {
	b := make([]byte, n)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

func cacheKey(parts ...string) string {
	h := sha256.Sum256([]byte(strings.Join(parts, "\x00")))
	return hex.EncodeToString(h[:8])
}
