package builder

import (
	"bytes"
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

func TestBuildxDriverOpts(t *testing.T) {
	if o, err := buildxDriverOpts(); err != nil || o != nil {
		t.Fatalf("no env: %v %v", o, err)
	}
	t.Setenv("FALAK_BUILD_MEMORY_MB", "1536")
	t.Setenv("FALAK_BUILD_CPUS", "1.5")
	t.Setenv("FALAK_BUILD_CGROUP_PARENT", "falak.slice")
	t.Setenv("FALAK_BUILD_NETWORK", "host")
	o, err := buildxDriverOpts()
	if err != nil || strings.Join(o, " ") != "memory=1536m memory-swap=1536m cpu-period=100000 cpu-quota=150000 cgroup-parent=falak.slice network=host" {
		t.Fatalf("%v %v", o, err)
	}
	t.Setenv("FALAK_BUILD_NETWORK", "falak-env_production.1")
	if o, err := buildxDriverOpts(); err != nil || o[len(o)-1] != "network=falak-env_production.1" {
		t.Fatalf("named network: %v %v", o, err)
	}
	for _, kv := range [][2]string{{"FALAK_BUILD_MEMORY_MB", "1g"}, {"FALAK_BUILD_CPUS", "0"}, {"FALAK_BUILD_CPUS", "NaN"}, {"FALAK_BUILD_CPUS", "Inf"},
		{"FALAK_BUILD_CPUS", "-Inf"}, {"FALAK_BUILD_CGROUP_PARENT", "a,memory=1"}, {"FALAK_BUILD_NETWORK", "host,memory=1"},
		{"FALAK_BUILD_NETWORK", "-host"}, {"FALAK_BUILD_NETWORK", "my net"}, {"FALAK_BUILD_NETWORK", "net=host"}} {
		k, bad := kv[0], kv[1]
		t.Run(k+"="+bad, func(t *testing.T) {
			t.Setenv(k, bad)
			if _, err := buildxDriverOpts(); err == nil {
				t.Fatalf("%s=%s accepted", k, bad)
			}
		})
	}
}

// The builder is created with the limits, kept while they stay the same and recreated (state kept) when they change.
func TestBuildxBuilderFollowsItsLimits(t *testing.T) {
	f := &runnertest.Fake{}
	fx := t.TempDir()
	copyDir(t, filepath.Join(fixtures, "bun"), fx)
	_ = os.WriteFile(filepath.Join(fx, "Dockerfile"), []byte("FROM oven/bun:1\n"), 0o644)
	fakeGit(t, f, fx)
	exists := false
	f.OnFunc("docker buildx inspect", func(runnertest.Call) (runner.Result, error) {
		if exists {
			return runner.Result{}, nil
		}
		return runner.Result{ExitCode: 1}, nil
	})
	f.OnFunc("docker buildx create", func(runnertest.Call) (runner.Result, error) { exists = true; return runner.Result{}, nil })
	f.OnFunc("docker buildx build", writeMetadata)
	b := newBuilder(t, f)
	push := false
	build := func() []string {
		f.Reset()
		job := Job{ID: "b-x", Mode: ModeDocker, Repo: Repo{URL: "https://x/y.git"}, Docker: &DockerSpec{Image: "acme/web:1", Push: &push}}
		if _, err := b.Build(context.Background(), job, NewNDJSONSink(&bytes.Buffer{})); err != nil {
			t.Fatal(err)
		}
		var out []string
		for _, l := range f.Lines() {
			if strings.HasPrefix(l, "docker buildx create") || strings.HasPrefix(l, "docker buildx rm") {
				out = append(out, l)
			}
		}
		return out
	}
	t.Setenv("FALAK_BUILD_MEMORY_MB", "2048")
	if l := build(); len(l) != 1 || l[0] != "docker buildx create --name falak --driver docker-container --driver-opt memory=2048m --driver-opt memory-swap=2048m" {
		t.Fatalf("create: %v", l)
	}
	if l := build(); len(l) != 0 {
		t.Fatalf("same limits recreated the builder: %v", l)
	}
	t.Setenv("FALAK_BUILD_CPUS", "2")
	if l := build(); len(l) != 2 || l[0] != "docker buildx rm --keep-state falak" || !strings.HasSuffix(l[1], "--driver-opt cpu-period=100000 --driver-opt cpu-quota=200000") {
		t.Fatalf("recreate: %v", l)
	}
	// A network change recreates it too: the BuildKit container's network is fixed at creation.
	t.Setenv("FALAK_BUILD_NETWORK", "host")
	if l := build(); len(l) != 2 || l[0] != "docker buildx rm --keep-state falak" || !strings.HasSuffix(l[1], "--driver-opt cpu-quota=200000 --driver-opt network=host") {
		t.Fatalf("network: %v", l)
	}
}
