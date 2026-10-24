package builder

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

const digest = "sha256:4f53cda18c2baa0c0354bb5f9a3ecbe5ed12ab4d8e11ba873c2f11161202b945"

// writeMetadata emulates BuildKit writing --metadata-file.
func writeMetadata(c runnertest.Call) (runner.Result, error) {
	for i, a := range c.Args {
		if a == "--metadata-file" {
			b, _ := json.Marshal(map[string]any{"containerimage.digest": digest, "image.name": "x"})
			_ = os.WriteFile(c.Args[i+1], b, 0o644)
		}
	}
	return runner.Result{Stderr: []byte("#1 [internal] load build definition\n")}, nil
}

func TestDockerBuildxGeneratedDockerfile(t *testing.T) {
	f := &runnertest.Fake{}
	fakeGit(t, f, filepath.Join(fixtures, "next"))
	f.On("docker buildx inspect", runner.Result{ExitCode: 1, Stderr: []byte("no builder \"falak\" found")})
	var dockerfile, dockerCfg string
	f.OnFunc("docker buildx build", func(c runnertest.Call) (runner.Result, error) {
		for i, a := range c.Args {
			if a == "--file" {
				b, _ := os.ReadFile(c.Args[i+1])
				dockerfile = string(b)
			}
		}
		for _, e := range c.Env {
			if v, ok := strings.CutPrefix(e, "DOCKER_CONFIG="); ok {
				b, _ := os.ReadFile(filepath.Join(v, "config.json"))
				dockerCfg = string(b)
			}
		}
		return writeMetadata(c)
	})
	b := newBuilder(t, f)
	var out bytes.Buffer
	job := Job{ID: "b-docker", Mode: ModeDocker, Repo: Repo{URL: "https://x/y.git"}, Docker: &DockerSpec{
		Image: "registry.falak.test:5000/acme/web:01JB", Tags: []string{"registry.falak.test:5000/acme/web:latest"},
		BuildArgs: map[string]string{"Z_ARG": "1", "A_ARG": "2"}, Platforms: []string{"linux/amd64", "linux/arm64"},
		Registry: &RegistryAuth{Username: "robot", Password: "hunter2-pass"},
	}}
	res, err := b.Build(context.Background(), job, NewNDJSONSink(&out))
	if err != nil {
		t.Fatalf("%v\n%s", err, out.String())
	}
	if res.Image == nil || res.Image.Digest != digest || res.Image.Pinned != "registry.falak.test:5000/acme/web@"+digest || res.Image.Ref != job.Docker.Image {
		t.Fatalf("image = %+v", res.Image)
	}
	if !f.Ran("docker buildx create --name falak --driver docker-container") {
		t.Fatalf("builder not created: %v", f.Lines())
	}
	var build runnertest.Call
	for _, c := range f.Calls() {
		if strings.HasPrefix(c.Line, "docker buildx build") {
			build = c
		}
	}
	args := build.Args
	cache := filepath.Join(b.CacheDir, "buildkit", cacheKey("", "https://x/y.git", ""))
	wantSeq := []string{"buildx", "build", "--progress", "plain", "--builder", "falak", "--file"}
	if strings.Join(args[:7], " ") != strings.Join(wantSeq, " ") {
		t.Fatalf("args = %v", args)
	}
	line := strings.Join(args, " ")
	for _, want := range []string{
		"--tag registry.falak.test:5000/acme/web:01JB --tag registry.falak.test:5000/acme/web:latest",
		"--platform linux/amd64,linux/arm64",
		"--build-arg A_ARG=2 --build-arg Z_ARG=1",
		"--cache-to type=local,dest=" + cache + ",mode=max",
		"--provenance=false --metadata-file ",
		"--push",
	} {
		if !strings.Contains(line, want) {
			t.Fatalf("missing %q in\n%s", want, line)
		}
	}
	if strings.Contains(line, "--cache-from") {
		t.Fatal("cache-from without an existing cache")
	}
	if !strings.HasSuffix(line, filepath.Join("src", ".")) && !strings.HasSuffix(line, "/src") {
		t.Fatalf("context should be the checkout: %s", line)
	}
	if !strings.Contains(dockerfile, "FROM node:22-slim") || !strings.Contains(dockerfile, "RUN corepack enable") ||
		!strings.Contains(dockerfile, "RUN NODE_ENV=production pnpm run build") || !strings.Contains(dockerfile, `CMD ["sh", "-c", "pnpm run start"]`) {
		t.Fatalf("generated Dockerfile:\n%s", dockerfile)
	}
	wantAuth := base64.StdEncoding.EncodeToString([]byte("robot:hunter2-pass"))
	if !strings.Contains(dockerCfg, `"registry.falak.test:5000"`) || !strings.Contains(dockerCfg, wantAuth) {
		t.Fatalf("docker config = %s", dockerCfg)
	}
	if strings.Contains(out.String(), "hunter2-pass") {
		t.Fatal("registry password leaked")
	}
	validateNDJSON(t, out.Bytes())
}

func TestDockerRepoDockerfileAndCacheReuse(t *testing.T) {
	f := &runnertest.Fake{}
	fx := t.TempDir()
	copyDir(t, filepath.Join(fixtures, "bun"), fx)
	_ = os.WriteFile(filepath.Join(fx, "Dockerfile"), []byte("FROM oven/bun:1\n"), 0o644)
	fakeGit(t, f, fx)
	f.OnFunc("docker buildx build", writeMetadata)
	b := newBuilder(t, f)
	cache := filepath.Join(b.CacheDir, "buildkit", cacheKey("", "https://x/y.git", ""))
	_ = os.MkdirAll(cache, 0o755)
	_ = os.WriteFile(filepath.Join(cache, "index.json"), []byte("{}"), 0o644)
	push := false
	job := Job{ID: "b-df", Mode: ModeDocker, Repo: Repo{URL: "https://x/y.git"}, Docker: &DockerSpec{Image: "acme/web:1", Push: &push}}
	res, err := b.Build(context.Background(), job, NewNDJSONSink(&bytes.Buffer{}))
	if err != nil {
		t.Fatal(err)
	}
	line := ""
	for _, l := range f.Lines() {
		if strings.HasPrefix(l, "docker buildx build") {
			line = l
		}
	}
	if !strings.Contains(line, "/src/Dockerfile ") || !strings.Contains(line, "--cache-from type=local,src="+cache) || !strings.Contains(line, "--load") || strings.Contains(line, "--push") {
		t.Fatalf("line = %s", line)
	}
	if f.Ran("docker buildx create") {
		t.Fatal("builder recreated although inspect succeeded")
	}
	if res.Image.Pinned != "acme/web@"+digest {
		t.Fatalf("pinned = %s", res.Image.Pinned)
	}
}

func TestDockerBuildctlArgs(t *testing.T) {
	push := true
	d := dockerBuild{
		Spec:         DockerSpec{Image: "r.test/a:1", Tags: []string{"r.test/a:latest"}, Target: "prod", BuildArgs: map[string]string{"B": "2"}, Push: &push},
		ContextDir:   "/ws/src",
		Dockerfile:   "/ws/src/docker/Dockerfile.prod",
		MetadataFile: "/ws/metadata.json",
		CacheDir:     "/cache/bk/abc",
	}
	got := strings.Join(d.BuildctlArgs(), " ")
	want := `build --progress plain --local context=/ws/src --local dockerfile=/ws/src/docker --frontend dockerfile.v0 --opt filename=Dockerfile.prod --opt target=prod --opt build-arg:B=2 --output type=image,"name=r.test/a:1,r.test/a:latest",push=true --export-cache type=local,dest=/cache/bk/abc,mode=max --metadata-file /ws/metadata.json`
	if got != want {
		t.Fatalf("got\n%s\nwant\n%s", got, want)
	}
	d.Railpack = true
	if got := strings.Join(d.BuildctlArgs(), " "); !strings.Contains(got, "--frontend gateway.v0 --opt source="+RailpackFrontend) {
		t.Fatalf("railpack buildctl = %s", got)
	}
}

func TestDockerBuildctlEngineWhenNoDocker(t *testing.T) {
	f := &runnertest.Fake{}
	fakeGit(t, f, filepath.Join(fixtures, "static"))
	f.OnFunc("buildctl build", writeMetadata)
	b := newBuilder(t, f)
	b.LookPath = func(n string) (string, error) {
		if n == "buildctl" {
			return "/usr/bin/buildctl", nil
		}
		return "", errors.New("nope")
	}
	res, err := b.Build(context.Background(), Job{ID: "b-bk", Mode: ModeDocker, Repo: Repo{URL: "https://x/y.git"}, Docker: &DockerSpec{Image: "r.test/s:1"}}, NewNDJSONSink(&bytes.Buffer{}))
	if err != nil || res.Image.Digest != digest || f.Ran("docker ") {
		t.Fatalf("err=%v res=%+v lines=%v", err, res.Image, f.Lines())
	}
}

func TestDockerRailpackPlan(t *testing.T) {
	f := &runnertest.Fake{}
	fakeGit(t, f, filepath.Join(fixtures, "bun"))
	f.OnFunc("docker buildx build", writeMetadata)
	b := newBuilder(t, f)
	b.LookPath = func(string) (string, error) { return "/usr/bin/x", nil }
	_, err := b.Build(context.Background(), Job{ID: "b-rp", Mode: ModeDocker, Repo: Repo{URL: "https://x/y.git"}, Docker: &DockerSpec{Image: "r.test/s:1"}}, NewNDJSONSink(&bytes.Buffer{}))
	if err != nil {
		t.Fatal(err)
	}
	var line string
	for _, l := range f.Lines() {
		if strings.HasPrefix(l, "docker buildx build") {
			line = l
		}
	}
	if !f.Ran("railpack prepare ") || !strings.Contains(line, "railpack-plan.json") || !strings.Contains(line, "--build-arg BUILDKIT_SYNTAX="+RailpackFrontend) {
		t.Fatalf("lines = %v", f.Lines())
	}
}

func TestDockerPushWithoutDigestFails(t *testing.T) {
	f := &runnertest.Fake{}
	fakeGit(t, f, filepath.Join(fixtures, "static"))
	b := newBuilder(t, f)
	_, err := b.Build(context.Background(), Job{ID: "b-nod", Mode: ModeDocker, Repo: Repo{URL: "https://x/y.git"}, Docker: &DockerSpec{Image: "r.test/s:1"}}, NewNDJSONSink(&bytes.Buffer{}))
	if err == nil || !strings.Contains(err.Error(), "digest") {
		t.Fatalf("err = %v", err)
	}
}

func TestGenerateDockerfiles(t *testing.T) {
	for fixture, wants := range map[string][]string{
		"laravel":     {"FROM node:22-slim AS assets", "RUN npm ci --include=dev", "FROM dunglas/frankenphp:1-php8.3", "COPY --from=assets /app/public /app/public", "RUN composer install --no-dev", "ENV SERVER_NAME=:8080"},
		"bun":         {"FROM oven/bun:1", "RUN bun install --frozen-lockfile", "RUN rm -rf node_modules && bun install --production", `CMD ["sh", "-c", "bun index.ts"]`},
		"static":      {"FROM caddy:2-alpine", "COPY . /srv", "--listen\", \":8080"},
		"vite-static": {"FROM node:20-slim AS build", "RUN corepack enable", "COPY --from=build /app/dist /srv"},
		"deno":        {"FROM denoland/deno:2", "RUN deno task build", `CMD ["sh", "-c", "deno task start"]`},
	} {
		p, err := Detect(filepath.Join(fixtures, fixture), "")
		if err != nil {
			t.Fatal(err)
		}
		df, err := GenerateDockerfile(p)
		if err != nil {
			t.Fatal(err)
		}
		for _, w := range wants {
			if !strings.Contains(df, w) {
				t.Errorf("%s: missing %q in\n%s", fixture, w, df)
			}
		}
	}
}

func TestImageRefHelpers(t *testing.T) {
	for in, want := range map[string]string{"r.test:5000/a/b:1": "r.test:5000/a/b", "a/b": "a/b", "nginx:1.27": "nginx", "r.test/a@sha256:x": "r.test/a"} {
		if got := imageRepo(in); got != want {
			t.Errorf("imageRepo(%q) = %q", in, got)
		}
	}
	for in, want := range map[string]string{"ghcr.io/a/b": "ghcr.io", "localhost/a": "localhost", "acme/web": "docker.io", "nginx": "docker.io"} {
		if got := registryHost(in); got != want {
			t.Errorf("registryHost(%q) = %q", in, got)
		}
	}
}
