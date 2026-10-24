package builder

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"math"
	"os"
	"path/filepath"
	"strconv"
	"strings"

	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// RailpackFrontend is the BuildKit frontend image that builds a railpack-plan.json.
const RailpackFrontend = "ghcr.io/railwayapp/railpack-frontend"

// BuildxBuilderName is the docker-container buildx builder falak-builder creates (the default `docker`
// driver cannot export a local build cache).
const BuildxBuilderName = "falak"

// dockerBuild is the resolved input for one image build.
type dockerBuild struct {
	Spec          DockerSpec
	ContextDir    string
	Dockerfile    string // absolute path
	Railpack      bool   // Dockerfile is a railpack-plan.json built by the railpack frontend
	CacheDir      string
	MetadataFile  string
	DockerConfig  string // dir containing config.json with registry auth ("" = none)
	BuildxBuilder string
	// CacheNS prefixes the ids of `RUN --mount=type=cache` mounts (BUILDKIT_CACHE_MOUNT_NS; railpack's cache-key), so
	// organizations sharing a BuildKit never share a cache mount. Layer cache stays shared: BuildKit reuses a step's
	// result only for identical instructions and inputs.
	CacheNS string
}

// cacheNSArgs are the build args that scope cache mounts; a job's own value for them is dropped.
func (d dockerBuild) cacheNSArgs() map[string]string {
	args := map[string]string{}
	for k, v := range d.Spec.BuildArgs {
		if d.CacheNS == "" || (k != "BUILDKIT_CACHE_MOUNT_NS" && k != "cache-key") {
			args[k] = v
		}
	}
	if d.CacheNS != "" {
		args["BUILDKIT_CACHE_MOUNT_NS"] = d.CacheNS
		if d.Railpack {
			args["cache-key"] = d.CacheNS
		}
	}
	return args
}

func (d dockerBuild) images() []string {
	return append([]string{d.Spec.Image}, d.Spec.Tags...)
}

func (d dockerBuild) push() bool { return d.Spec.Push == nil || *d.Spec.Push }

// BuildxArgs returns `docker buildx build` arguments.
func (d dockerBuild) BuildxArgs() []string {
	a := []string{"buildx", "build", "--progress", "plain"}
	if d.BuildxBuilder != "" {
		a = append(a, "--builder", d.BuildxBuilder)
	}
	a = append(a, "--file", d.Dockerfile)
	for _, img := range d.images() {
		a = append(a, "--tag", img)
	}
	if d.Spec.Target != "" {
		a = append(a, "--target", d.Spec.Target)
	}
	if len(d.Spec.Platforms) > 0 {
		a = append(a, "--platform", strings.Join(d.Spec.Platforms, ","))
	}
	if d.Railpack {
		a = append(a, "--build-arg", "BUILDKIT_SYNTAX="+RailpackFrontend)
	}
	args := d.cacheNSArgs()
	for _, k := range sortedKeys(args) {
		a = append(a, "--build-arg", k+"="+args[k])
	}
	if d.CacheDir != "" {
		if _, err := os.Stat(filepath.Join(d.CacheDir, "index.json")); err == nil {
			a = append(a, "--cache-from", "type=local,src="+d.CacheDir)
		}
		a = append(a, "--cache-to", "type=local,dest="+d.CacheDir+",mode=max")
	}
	a = append(a, "--provenance=false", "--metadata-file", d.MetadataFile)
	if d.push() {
		a = append(a, "--push")
	} else {
		a = append(a, "--load")
	}
	return append(a, d.ContextDir)
}

// BuildctlArgs returns `buildctl build` arguments (BuildKit without the docker CLI).
func (d dockerBuild) BuildctlArgs() []string {
	a := []string{"build", "--progress", "plain", "--local", "context=" + d.ContextDir, "--local", "dockerfile=" + filepath.Dir(d.Dockerfile)}
	if d.Railpack {
		a = append(a, "--frontend", "gateway.v0", "--opt", "source="+RailpackFrontend)
	} else {
		a = append(a, "--frontend", "dockerfile.v0")
	}
	a = append(a, "--opt", "filename="+filepath.Base(d.Dockerfile))
	if d.Spec.Target != "" {
		a = append(a, "--opt", "target="+d.Spec.Target)
	}
	if len(d.Spec.Platforms) > 0 {
		a = append(a, "--opt", "platform="+strings.Join(d.Spec.Platforms, ","))
	}
	args := d.cacheNSArgs()
	for _, k := range sortedKeys(args) {
		a = append(a, "--opt", "build-arg:"+k+"="+args[k])
	}
	out := fmt.Sprintf("type=image,%q,push=%t", "name="+strings.Join(d.images(), ","), d.push())
	a = append(a, "--output", out)
	if d.CacheDir != "" {
		if _, err := os.Stat(filepath.Join(d.CacheDir, "index.json")); err == nil {
			a = append(a, "--import-cache", "type=local,src="+d.CacheDir)
		}
		a = append(a, "--export-cache", "type=local,dest="+d.CacheDir+",mode=max")
	}
	return append(a, "--metadata-file", d.MetadataFile)
}

func (b *Builder) buildDocker(ctx context.Context, j *job) (*ImageResult, error) {
	spec := *j.Docker
	d := dockerBuild{
		Spec:         spec,
		ContextDir:   filepath.Join(j.app, filepath.FromSlash(firstNonEmpty(spec.Context, "."))),
		CacheDir:     filepath.Join(b.CacheDir, "buildkit", cacheKey(j.OrganizationID, j.Repo.URL, j.Subdir)+suffix(j.cacheSuffix)),
		MetadataFile: filepath.Join(j.ws, "metadata.json"),
		CacheNS:      j.OrganizationID,
	}
	if err := os.MkdirAll(filepath.Dir(d.CacheDir), 0o700); err != nil {
		return nil, err
	}
	if err := os.Chmod(filepath.Dir(d.CacheDir), 0o700); err != nil { // older builders made it 0755
		return nil, err
	}
	engine := spec.Engine
	if engine == "" {
		engine = "buildx"
		if _, err := b.LookPath("docker"); err != nil {
			if _, err := b.LookPath("buildctl"); err == nil {
				engine = "buildctl"
			}
		}
	}
	if engine != "buildx" && engine != "buildctl" {
		return nil, fmt.Errorf("docker.engine: unknown %q", engine)
	}

	// Dockerfile: explicit → repo Dockerfile → railpack plan → generated per detected stack.
	switch df := spec.Dockerfile; {
	case df != "":
		d.Dockerfile = filepath.Join(j.app, filepath.FromSlash(df))
		if _, err := os.Stat(d.Dockerfile); err != nil {
			return nil, fmt.Errorf("dockerfile %q: %w", df, err)
		}
	case fileExists(filepath.Join(j.app, "Dockerfile")):
		d.Dockerfile = filepath.Join(j.app, "Dockerfile")
	case b.railpackEnabled(nil):
		j.logf("No Dockerfile; preparing a Railpack build plan")
		plan := filepath.Join(j.ws, "railpack", "railpack-plan.json")
		if err := os.MkdirAll(filepath.Dir(plan), 0o755); err != nil {
			return nil, err
		}
		if _, err := runner.Check(ctx, b.Runner, runner.Cmd{Name: "railpack", Args: []string{"prepare", d.ContextDir, "--plan-out", plan, "--info-out", filepath.Join(j.ws, "railpack-info.json")}, Stdout: j.out, Stderr: j.errw}); err != nil {
			return nil, fmt.Errorf("railpack prepare: %w", err)
		}
		d.Dockerfile, d.Railpack = plan, true
	default:
		j.logf("No Dockerfile; generating one for the detected stack")
		plan, err := Detect(j.app, j.Runtime)
		if err != nil {
			return nil, err
		}
		content, err := GenerateDockerfile(plan)
		if err != nil {
			return nil, err
		}
		d.Dockerfile = filepath.Join(j.ws, "gen", "Dockerfile")
		if err := os.MkdirAll(filepath.Dir(d.Dockerfile), 0o755); err != nil {
			return nil, err
		}
		if err := os.WriteFile(d.Dockerfile, []byte(content), 0o644); err != nil {
			return nil, err
		}
		// BuildKit reads <Dockerfile>.dockerignore beside an out-of-context Dockerfile.
		if !fileExists(filepath.Join(d.ContextDir, ".dockerignore")) {
			if err := os.WriteFile(d.Dockerfile+".dockerignore", []byte(generatedDockerignore), 0o644); err != nil {
				return nil, err
			}
		}
		fmt.Fprintf(j.out, "generated Dockerfile (%s):\n%s", firstNonEmpty(plan.Framework, plan.Provider), content)
	}
	j.st.Progress(0.2)

	env := []string{"BUILDKIT_PROGRESS=plain"}
	if spec.Registry != nil && spec.Registry.Username != "" {
		d.DockerConfig = filepath.Join(j.ws, "docker")
		if err := writeDockerConfig(d.DockerConfig, spec.Image, *spec.Registry); err != nil {
			return nil, err
		}
		env = append(env, "DOCKER_CONFIG="+d.DockerConfig)
	}
	for _, k := range sortedKeys(j.Env) {
		env = append(env, k+"="+j.Env[k])
	}

	var cmd runner.Cmd
	if engine == "buildx" {
		d.BuildxBuilder = BuildxBuilderName
		opts, err := buildxDriverOpts()
		if err != nil {
			return nil, err
		}
		// The BuildKit container runs outside falak-builder's own cgroup: its limits are driver options, fixed at
		// creation. The options it was created with are kept beside the cache; a builder with others is recreated
		// (keeping its state volume, the build cache).
		stamp, want := filepath.Join(b.CacheDir, "buildx-driver-opts"), strings.Join(opts, "\n")
		_, missing := runner.Check(ctx, b.Runner, runner.Cmd{Name: "docker", Args: []string{"buildx", "inspect", BuildxBuilderName}, Env: env})
		if have, _ := os.ReadFile(stamp); missing == nil && string(have) != want {
			j.logf("Recreating the buildx builder with new limits")
			if _, err := runner.Check(ctx, b.Runner, runner.Cmd{Name: "docker", Args: []string{"buildx", "rm", "--keep-state", BuildxBuilderName}, Env: env, Stdout: j.out, Stderr: j.errw}); err != nil {
				return nil, fmt.Errorf("remove buildx builder: %w", err)
			}
			missing = errors.New("recreate")
		}
		if missing != nil {
			args := []string{"buildx", "create", "--name", BuildxBuilderName, "--driver", "docker-container"}
			for _, o := range opts {
				args = append(args, "--driver-opt", o)
			}
			if _, err := runner.Check(ctx, b.Runner, runner.Cmd{Name: "docker", Args: args, Env: env, Stdout: j.out, Stderr: j.errw}); err != nil {
				return nil, fmt.Errorf("create buildx builder: %w", err)
			}
			if err := os.MkdirAll(b.CacheDir, 0o755); err != nil {
				return nil, err
			}
			if err := os.WriteFile(stamp, []byte(want), 0o644); err != nil {
				return nil, err
			}
		}
		cmd = runner.Cmd{Name: "docker", Args: d.BuildxArgs()}
	} else {
		cmd = runner.Cmd{Name: "buildctl", Args: d.BuildctlArgs()}
	}
	cmd.Env, cmd.Dir, cmd.Stdout, cmd.Stderr = env, j.app, j.out, j.errw
	j.logf("Building %s with %s", spec.Image, engine)
	fmt.Fprintf(j.out, "$ %s\n", cmd.String())
	t0 := b.Now()
	if _, err := runner.Check(ctx, b.Runner, cmd); err != nil {
		return nil, fmt.Errorf("image build: %w", err)
	}
	j.steps = append(j.steps, StepResult{Name: "image build", Command: cmd.String(), DurationMS: b.Now().Sub(t0).Milliseconds()})
	j.st.Progress(0.9)

	img := &ImageResult{Ref: spec.Image}
	if mb, err := os.ReadFile(d.MetadataFile); err == nil {
		var md map[string]any
		if json.Unmarshal(mb, &md) == nil {
			if s, ok := md["containerimage.digest"].(string); ok {
				img.Digest = s
			}
		}
	}
	if img.Digest == "" && d.push() {
		return nil, errors.New("image pushed but no digest in build metadata")
	}
	if img.Digest != "" {
		img.Pinned = imageRepo(spec.Image) + "@" + img.Digest
		fmt.Fprintf(j.out, "image %s\n", img.Pinned)
	}
	return img, nil
}

// buildxDriverOpts are the BuildKit container's limits (docker-container driver options, buildx 0.12+) from
// FALAK_BUILD_MEMORY_MB (no swap on top), FALAK_BUILD_CPUS (a CFS quota) and FALAK_BUILD_CGROUP_PARENT, and its
// network from FALAK_BUILD_NETWORK. None set = none: the builder is created exactly as before.
//
// The BuildKit container resolves names on its own Docker network, never through the host's /etc/hosts, and it is
// what pushes to the registry. FALAK_BUILD_NETWORK=host gives it the host's network and /etc/hosts, so a registry the
// host reaches under a private address (split DNS, a private network) is reached the same way by the push.
func buildxDriverOpts() ([]string, error) {
	var opts []string
	if v := os.Getenv("FALAK_BUILD_MEMORY_MB"); v != "" {
		mb, err := strconv.Atoi(v)
		if err != nil || mb < 64 {
			return nil, fmt.Errorf("FALAK_BUILD_MEMORY_MB %q: a whole number of MB, at least 64", v)
		}
		opts = append(opts, fmt.Sprintf("memory=%dm", mb), fmt.Sprintf("memory-swap=%dm", mb))
	}
	if v := os.Getenv("FALAK_BUILD_CPUS"); v != "" {
		cpus, err := strconv.ParseFloat(v, 64)
		if err != nil || !(cpus >= 0.01 && cpus <= 1024) { // also NaN; +Inf is above the bound
			return nil, fmt.Errorf("FALAK_BUILD_CPUS %q: a number of CPUs", v)
		}
		opts = append(opts, "cpu-period=100000", "cpu-quota="+strconv.Itoa(int(math.Round(cpus*100000))))
	}
	if v := os.Getenv("FALAK_BUILD_CGROUP_PARENT"); v != "" {
		if strings.ContainsAny(v, ", \t\n=\"") {
			return nil, fmt.Errorf("FALAK_BUILD_CGROUP_PARENT %q: not a cgroup or slice name", v)
		}
		opts = append(opts, "cgroup-parent="+v)
	}
	if v := os.Getenv("FALAK_BUILD_NETWORK"); v != "" {
		if !dockerNetworkName(v) {
			return nil, fmt.Errorf("FALAK_BUILD_NETWORK %q: host, bridge or a Docker network name", v)
		}
		opts = append(opts, "network="+v)
	}
	return opts, nil
}

// dockerNetworkName reports whether s is a name Docker accepts for a network ([a-zA-Z0-9][a-zA-Z0-9_.-]*), which
// also keeps it from smuggling another driver option into the comma-separated --driver-opt value.
func dockerNetworkName(s string) bool {
	for i, r := range s {
		alnum := r >= 'a' && r <= 'z' || r >= 'A' && r <= 'Z' || r >= '0' && r <= '9'
		if !alnum && (i == 0 || !strings.ContainsRune("_.-", r)) {
			return false
		}
	}
	return s != ""
}

func suffix(s string) string {
	if s == "" {
		return ""
	}
	return "-" + s
}

// imageRepo strips a tag/digest from an image reference (keeping a registry port).
func imageRepo(ref string) string {
	if i := strings.Index(ref, "@"); i >= 0 {
		ref = ref[:i]
	}
	slash := strings.LastIndex(ref, "/")
	if i := strings.LastIndex(ref, ":"); i > slash {
		ref = ref[:i]
	}
	return ref
}

// registryHost returns the registry of an image ref ("docker.io" for Docker Hub short names).
func registryHost(ref string) string {
	first, _, ok := strings.Cut(ref, "/")
	if ok && (strings.ContainsAny(first, ".:") || first == "localhost") {
		return first
	}
	return "docker.io"
}

func writeDockerConfig(dir, image string, a RegistryAuth) error {
	server := a.Server
	if server == "" {
		server = registryHost(image)
	}
	if server == "docker.io" {
		server = "https://index.docker.io/v1/"
	}
	cfg := map[string]any{"auths": map[string]any{server: map[string]string{
		"auth": base64.StdEncoding.EncodeToString([]byte(a.Username + ":" + a.Password)),
	}}}
	b, _ := json.Marshal(cfg)
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return err
	}
	return os.WriteFile(filepath.Join(dir, "config.json"), b, 0o600)
}

func fileExists(p string) bool {
	st, err := os.Stat(p)
	return err == nil && !st.IsDir()
}
