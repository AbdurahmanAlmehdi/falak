package provision

import (
	"context"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

// dockerRepo serves Docker's repository for the suites given (Release files) and its key.
func dockerRepo(t *testing.T, suites ...string) *httptest.Server {
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case strings.HasSuffix(r.URL.Path, "/gpg"):
			w.Write([]byte("-----BEGIN PGP PUBLIC KEY BLOCK-----\nfake\n-----END PGP PUBLIC KEY BLOCK-----\n"))
		case strings.HasSuffix(r.URL.Path, "/Release"):
			for _, s := range suites {
				if strings.Contains(r.URL.Path, "/dists/"+s+"/") {
					return
				}
			}
			w.WriteHeader(http.StatusNotFound)
		default:
			w.WriteHeader(http.StatusNotFound)
		}
	}))
	t.Cleanup(srv.Close)
	return srv
}

func engineHarness(t *testing.T, osRelease string, repo *httptest.Server) (*Provisioner, *runnertest.Fake, string) {
	root := t.TempDir()
	os.MkdirAll(filepath.Join(root, "etc"), 0o755)
	os.WriteFile(filepath.Join(root, "etc/os-release"), []byte(osRelease), 0o644)
	f := &runnertest.Fake{}
	f.On("gpg --show-keys", runner.Result{Stdout: []byte("pub:-:4096:1:8D81803C0EBFCD88:1487788586:::-:::scESAP:::::::\nfpr:::::::::" + dockerFingerprint + ":\n")})
	p := New(Deps{Runner: f, FS: hostfs.FS{Root: root}, HTTP: repo.Client(), DockerRepoURL: repo.URL + "/linux"})
	return p, f, root
}

func dpkg(lines ...string) runner.Result {
	return runner.Result{Stdout: []byte(strings.Join(lines, "\n") + "\n")}
}

func stream() commands.Stream { return commands.NewTestStream("c", &commands.Collector{}) }

func TestDockerEngineReplacesAnOldDockerIOWithDockerCE(t *testing.T) {
	repo := dockerRepo(t, "noble")
	p, f, root := engineHarness(t, "ID=ubuntu\nVERSION_ID=\"24.04\"\nVERSION_CODENAME=noble\n", repo)
	installed := false
	f.OnFunc("dpkg-query", func(c runnertest.Call) (runner.Result, error) {
		if installed {
			return dpkg("docker-ce\tinstalled\t5:28.3.2-1~ubuntu.24.04~noble", "docker-ce-cli\tinstalled\t5:28.3.2-1", "containerd.io\tinstalled\t1.7.27-1", "docker-buildx-plugin\tinstalled\t0.25.0-1", "docker-compose-plugin\tinstalled\t2.38.2-1"), nil
		}
		return dpkg("docker.io\tinstalled\t27.5.1-0ubuntu3~24.04.2"), nil
	})
	f.On("apt-cache policy docker-ce", runner.Result{Stdout: []byte("docker-ce:\n  Installed: (none)\n  Candidate: 5:28.3.2-1~ubuntu.24.04~noble\n")})
	f.OnFunc("apt-get", func(c runnertest.Call) (runner.Result, error) {
		if strings.Contains(c.Line, " install ") && strings.Contains(c.Line, "docker") {
			installed = true
		}
		return runner.Result{}, nil
	})
	changed, err := p.dockerEngine(context.Background(), stream(), "28")
	if err != nil || !changed {
		t.Fatalf("%v %v\n%s", changed, err, strings.Join(f.Lines(), "\n"))
	}
	lines := strings.Join(f.Lines(), "\n")
	removeAt, installAt := strings.Index(lines, "remove"), strings.Index(lines, "docker-ce-cli")
	if removeAt < 0 || installAt < 0 || removeAt > installAt || !strings.Contains(lines, "docker.io") {
		t.Errorf("docker.io not removed before docker-ce went in:\n%s", lines)
	}
	sources, _ := os.ReadFile(filepath.Join(root, dockerSources))
	if !strings.Contains(string(sources), "URIs: "+repo.URL+"/linux/ubuntu\nSuites: noble\nComponents: stable\nSigned-By: /etc/apt/keyrings/docker.asc") {
		t.Errorf("sources %s", sources)
	}
	if _, err := os.Stat(filepath.Join(root, dockerKeyring)); err != nil {
		t.Error("keyring missing")
	}
}

func TestDockerEngineKeepsARecentDocker(t *testing.T) {
	p, f, _ := engineHarness(t, "ID=debian\nVERSION_CODENAME=trixie\n", dockerRepo(t, "trixie"))
	f.On("dpkg-query", dpkg("docker.io\tinstalled\t28.2.2+dfsg-1"))
	changed, err := p.dockerEngine(context.Background(), stream(), "28")
	if err != nil || changed || f.Ran("apt-get") {
		t.Fatalf("%v %v %v", changed, err, f.Lines())
	}
}

func TestDockerEngineRefusesAKeyThatIsNotDockers(t *testing.T) {
	repo := dockerRepo(t, "noble")
	p, _, root := engineHarness(t, "ID=ubuntu\nVERSION_CODENAME=noble\n", repo)
	f := (&runnertest.Fake{}).On("gpg --show-keys", runner.Result{Stdout: []byte("fpr:::::::::0000000000000000000000000000000000000000:\n")})
	p.d.Runner = f
	if _, err := p.dockerEngine(context.Background(), stream(), "28"); err == nil || !strings.Contains(err.Error(), "not Docker's") {
		t.Fatalf("key accepted: %v", err)
	}
	if _, err := os.Stat(filepath.Join(root, dockerKeyring)); err == nil {
		t.Error("an unchecked key was installed")
	}
}

func TestDockerEngineWithoutADockerSuiteUsesARecentDistroDockerOrFails(t *testing.T) {
	p, f, _ := engineHarness(t, "ID=ubuntu\nVERSION_ID=\"26.04\"\nVERSION_CODENAME=resolute\n", dockerRepo(t, "noble"))
	installed := false
	f.OnFunc("dpkg-query", func(runnertest.Call) (runner.Result, error) {
		if installed {
			return dpkg("docker.io\tinstalled\t28.5.1-0ubuntu1"), nil
		}
		return dpkg(), nil
	})
	f.On("apt-cache policy docker.io", runner.Result{Stdout: []byte("docker.io:\n  Candidate: 28.5.1-0ubuntu1\n")})
	f.OnFunc("apt-get", func(c runnertest.Call) (runner.Result, error) {
		if strings.Contains(c.Line, " install ") && strings.Contains(c.Line, "docker") {
			installed = true
		}
		return runner.Result{}, nil
	})
	if changed, err := p.dockerEngine(context.Background(), stream(), "28"); err != nil || !changed || f.Ran("gpg") {
		t.Fatalf("%v %v %v", changed, err, f.Lines())
	}

	p, f, _ = engineHarness(t, "ID=ubuntu\nVERSION_CODENAME=resolute\n", dockerRepo(t))
	f.On("dpkg-query", dpkg())
	f.On("apt-cache policy docker.io", runner.Result{Stdout: []byte("docker.io:\n  Candidate: 27.5.1-0ubuntu3\n")})
	if _, err := p.dockerEngine(context.Background(), stream(), "28"); err == nil || !strings.Contains(err.Error(), "older than 28") {
		t.Fatalf("an old docker.io accepted: %v", err)
	}
}

func TestDockerVersions(t *testing.T) {
	for v, want := range map[string]string{"5:28.3.2-1~ubuntu.24.04~noble": "28.3.2", "27.5.1-0ubuntu3~24.04.2": "27.5.1", "28.2.2+dfsg-1": "28.2.2", "": ""} {
		if got := upstreamVersion(v); got != want {
			t.Errorf("%s: %s", v, got)
		}
	}
	if !versionAtLeast("28.0.1", "28") || versionAtLeast("27.5.1", "28") || versionAtLeast("", "28") {
		t.Error("versionAtLeast")
	}
}
