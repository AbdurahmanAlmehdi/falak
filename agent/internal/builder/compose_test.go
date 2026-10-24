package builder

import (
	"bytes"
	"context"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

func TestParseComposeBuilds(t *testing.T) {
	builds, err := ParseComposeBuilds([]byte(`
services:
  api:
    build:
      context: services/api
      dockerfile: docker/Dockerfile.prod
      args: ["A=1", "B=two"]
  web:
    build: .
  db:
    image: postgres:17
`))
	if err != nil {
		t.Fatal(err)
	}
	if len(builds) != 2 || builds[0].Service != "api" || builds[1].Service != "web" {
		t.Fatalf("%+v", builds)
	}
	api := builds[0]
	if api.Context != "services/api" || api.Dockerfile != "services/api/docker/Dockerfile.prod" || api.Args["B"] != "two" {
		t.Fatalf("api %+v", api)
	}
	if builds[1].Context != "." || builds[1].Dockerfile != "Dockerfile" {
		t.Fatalf("web %+v", builds[1])
	}
	for name, bad := range map[string]string{
		"escape":  "services: {x: {build: ../other}}",
		"remote":  "services: {x: {build: https://github.com/a/b.git}}",
		"inline":  "services: {x: {build: {dockerfile_inline: 'FROM x'}}}",
		"dfleak":  "services: {x: {build: {context: a, dockerfile: ../../Dockerfile}}}",
		"invalid": "services: [",
	} {
		if _, err := ParseComposeBuilds([]byte(bad)); err == nil {
			t.Errorf("%s: accepted", name)
		}
	}
}

func TestComposeBuildBuildsEveryBuildService(t *testing.T) {
	f := &runnertest.Fake{}
	fakeGit(t, f, filepath.Join(fixtures, "compose"))
	f.OnFunc("docker buildx build", writeMetadata)
	b := newBuilder(t, f)
	var out bytes.Buffer
	job := Job{ID: "01JCOMPOSE", Mode: ModeDocker, Repo: Repo{URL: "https://x/stack.git"}, Compose: &ComposeSpec{
		ImagePrefix: "registry.falak.test/falak/shop", Registry: &RegistryAuth{Username: "robot", Password: "hunter2-pass"},
		BuildArgs: map[string]string{"VITE_URL": "https://x", "NODE_ENV": "dev"},
	}}
	res, err := b.Build(context.Background(), job, NewNDJSONSink(&out))
	if err != nil {
		t.Fatalf("%v\n%s", err, out.String())
	}
	if res.Compose == nil || res.Compose.File != "compose.yaml" || !strings.Contains(res.Compose.Content, "redis-data") {
		t.Fatalf("compose result %+v", res.Compose)
	}
	if len(res.Compose.Images) != 2 || res.Compose.Images["web"].Pinned != "registry.falak.test/falak/shop/web@"+digest ||
		res.Compose.Images["worker"].Ref != "registry.falak.test/falak/shop/worker:01jcompose" {
		t.Fatalf("images %+v", res.Compose.Images)
	}
	var builds []runnertest.Call
	for _, c := range f.Calls() {
		if strings.HasPrefix(c.Line, "docker buildx build") {
			builds = append(builds, c)
		}
	}
	if len(builds) != 2 {
		t.Fatalf("builds %v", f.Lines())
	}
	web := strings.Join(builds[0].Args, " ")
	for _, want := range []string{"--target prod", "--build-arg NODE_ENV=production", "--build-arg VITE_URL=https://x", "--tag registry.falak.test/falak/shop/web:01jcompose", "/app/Dockerfile", "--push"} {
		if !strings.Contains(web, want) {
			t.Errorf("web build missing %q: %s", want, web)
		}
	}
	if !strings.HasSuffix(web, filepath.Join("src", "app")) {
		t.Errorf("web context: %s", web)
	}
	if !strings.Contains(web, cacheKey("", "https://x/stack.git", "")+"-web") || strings.Contains(strings.Join(builds[1].Args, " "), "-web") {
		t.Errorf("per-service cache dirs: %s", web)
	}
	if strings.Contains(out.String(), "hunter2-pass") {
		t.Fatal("registry password leaked into the log")
	}
}

func TestComposeBuildMergesFilesAndShipsMountedFiles(t *testing.T) {
	f := &runnertest.Fake{}
	fakeGit(t, f, filepath.Join(fixtures, "compose-project"))
	f.OnFunc("docker buildx build", writeMetadata)
	b := newBuilder(t, f)
	var out bytes.Buffer
	job := Job{ID: "01JPROJECT", Mode: ModeDocker, Repo: Repo{URL: "https://x/shop.git"}, Compose: &ComposeSpec{
		ImagePrefix: "registry.falak.test/falak/shop", Files: []string{"deploy/compose.yml", "deploy/compose.prod.yml"}, Profiles: []string{"ops"},
	}}
	res, err := b.Build(context.Background(), job, NewNDJSONSink(&out))
	if err != nil {
		t.Fatalf("%v\n%s", err, out.String())
	}
	c := res.Compose
	for _, want := range []string{"nginx:1.28-alpine", "./deploy/nginx.conf:/etc/nginx/conf.d/default.conf:ro", "./.env.example", "backup:", "./app"} {
		if !strings.Contains(c.Content, want) {
			t.Errorf("merged project lacks %q:\n%s", want, c.Content)
		}
	}
	if strings.Contains(c.Content, "mailpit") || strings.Contains(c.Content, "profiles") {
		t.Errorf("inactive profile kept:\n%s", c.Content)
	}
	if !reflect.DeepEqual(c.Files, []string{"deploy/compose.yml", "deploy/compose.prod.yml"}) || c.File != "deploy/compose.yml" {
		t.Errorf("files %v / %s", c.Files, c.File)
	}
	var paths []string
	for _, a := range c.Assets {
		paths = append(paths, a.Path)
	}
	if !reflect.DeepEqual(paths, []string{".env.example", "deploy/conf.d/gzip.conf", "deploy/nginx.conf"}) {
		t.Errorf("assets %v", paths)
	}
	if !reflect.DeepEqual(c.Missing, []string{"deploy/data"}) {
		t.Errorf("missing %v", c.Missing)
	}
	if c.Images["app"].Ref != "registry.falak.test/falak/shop/app:01jproject" {
		t.Errorf("images %+v", c.Images)
	}
	for _, call := range f.Calls() {
		if strings.HasPrefix(call.Line, "docker buildx build") && !strings.HasSuffix(call.Line, filepath.Join("src", "app")) {
			t.Errorf("app context: %s", call.Line)
		}
	}
}

func TestComposeAssetsStayInsideTheRepository(t *testing.T) {
	root := t.TempDir()
	outside := t.TempDir()
	if err := os.WriteFile(filepath.Join(outside, "secret"), []byte("x"), 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(filepath.Join(root, "conf"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(filepath.Join(outside, "secret"), filepath.Join(root, "conf", "leak")); err != nil {
		t.Fatal(err)
	}
	if _, _, err := collectComposeAssets(root, []string{"conf"}); err == nil || !strings.Contains(err.Error(), "outside the repository") {
		t.Fatalf("symlink out of the repository: %v", err)
	}
	if _, err := readRepoFile(root, "conf/leak"); err == nil {
		t.Fatal("read a file outside the repository")
	}
	big := make([]byte, maxComposeAssetBytes+1)
	if err := os.WriteFile(filepath.Join(root, "big.bin"), big, 0o644); err != nil {
		t.Fatal(err)
	}
	if _, _, err := collectComposeAssets(root, []string{"big.bin"}); err == nil || !strings.Contains(err.Error(), "bake it into an image") {
		t.Fatalf("large file: %v", err)
	}
}

func TestComposeBuildMissingFile(t *testing.T) {
	f := &runnertest.Fake{}
	fakeGit(t, f, filepath.Join(fixtures, "static"))
	b := newBuilder(t, f)
	var out bytes.Buffer
	_, err := b.Build(context.Background(), Job{ID: "01JNOFILE", Mode: ModeDocker, Repo: Repo{URL: "https://x/y.git"}, Compose: &ComposeSpec{ImagePrefix: "r/k/s"}}, NewNDJSONSink(&out))
	if err == nil || !strings.Contains(err.Error(), "no compose file found") {
		t.Fatalf("err = %v", err)
	}
	if err := (&Job{ID: "x", Mode: ModeDocker, Repo: Repo{URL: "u"}, Compose: &ComposeSpec{}}).Validate(); err == nil {
		t.Fatal("compose without image_prefix accepted")
	}
}
