package provision

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
	"github.com/OthmanHaba/falak/agent/internal/runtime"
)

// hostSim is a fake host: tracks installed packages and systemd state.
type hostSim struct {
	pkgs    map[string]bool
	enabled map[string]bool
	active  map[string]bool
	users   map[string]bool
}

func newHost(f *runnertest.Fake, root string) *hostSim {
	h := &hostSim{pkgs: map[string]bool{}, enabled: map[string]bool{}, active: map[string]bool{}, users: map[string]bool{}}
	f.OnFunc("dpkg-query", func(c runnertest.Call) (runner.Result, error) {
		var b strings.Builder
		for _, a := range c.Args[2:] {
			if h.pkgs[a] {
				b.WriteString(a + "\tinstalled\t1\n")
			}
		}
		return runner.Result{ExitCode: 1, Stdout: []byte(b.String())}, nil
	})
	f.OnFunc("apt-get install", func(c runnertest.Call) (runner.Result, error) {
		for _, a := range c.Args {
			h.pkgs[strings.SplitN(a, "=", 2)[0]] = true
		}
		return runner.Result{}, nil
	})
	f.OnFunc("systemctl is-enabled", func(c runnertest.Call) (runner.Result, error) {
		if h.enabled[c.Args[2]] {
			return runner.Result{}, nil
		}
		return runner.Result{ExitCode: 1}, nil
	})
	f.OnFunc("systemctl is-active", func(c runnertest.Call) (runner.Result, error) {
		if h.active[c.Args[2]] {
			return runner.Result{}, nil
		}
		return runner.Result{ExitCode: 3}, nil
	})
	f.OnFunc("systemctl enable", func(c runnertest.Call) (runner.Result, error) {
		n := c.Args[len(c.Args)-1]
		h.enabled[n] = true
		if c.Args[1] == "--now" {
			h.active[n] = true
		}
		return runner.Result{}, nil
	})
	f.OnFunc("systemctl start", func(c runnertest.Call) (runner.Result, error) {
		h.active[c.Args[1]] = true
		return runner.Result{}, nil
	})
	f.OnFunc("systemctl restart", func(c runnertest.Call) (runner.Result, error) {
		h.active[c.Args[1]] = true
		return runner.Result{}, nil
	})
	f.OnFunc("systemctl reload-or-restart", func(c runnertest.Call) (runner.Result, error) {
		h.active[c.Args[1]] = true
		return runner.Result{}, nil
	})
	f.OnFunc("getent passwd", func(c runnertest.Call) (runner.Result, error) {
		if h.users[c.Args[1]] {
			shell := "/bin/bash"
			if c.Args[1] == "caddy" {
				shell = "/usr/sbin/nologin"
			}
			return runner.Result{Stdout: []byte(c.Args[1] + ":x:1001:1001::/home/" + c.Args[1] + ":" + shell + "\n")}, nil
		}
		return runner.Result{ExitCode: 2}, nil
	})
	f.OnFunc("useradd", func(c runnertest.Call) (runner.Result, error) {
		h.users[c.Args[len(c.Args)-1]] = true
		return runner.Result{}, nil
	})
	// Default host is a VM; container tests override this.
	f.OnFunc("systemd-detect-virt", func(c runnertest.Call) (runner.Result, error) { return runner.Result{ExitCode: 1}, nil })
	f.OnFunc("hostnamectl set-hostname", func(c runnertest.Call) (runner.Result, error) {
		os.MkdirAll(filepath.Join(root, "etc"), 0o755)
		return runner.Result{}, os.WriteFile(filepath.Join(root, "etc/hostname"), []byte(c.Args[1]+"\n"), 0o644)
	})
	f.OnFunc("timedatectl set-timezone", func(c runnertest.Call) (runner.Result, error) {
		os.MkdirAll(filepath.Join(root, "etc"), 0o755)
		return runner.Result{}, os.Symlink("/usr/share/zoneinfo/"+c.Args[1], filepath.Join(root, "etc/localtime"))
	})
	f.OnFunc("fallocate", func(c runnertest.Call) (runner.Result, error) {
		fh, _ := os.Create(filepath.Join(root, "swapfile"))
		fh.Truncate(1 << 20)
		return runner.Result{}, fh.Close()
	})
	f.OnFunc("swapon", func(c runnertest.Call) (runner.Result, error) {
		os.MkdirAll(filepath.Join(root, "proc"), 0o755)
		return runner.Result{}, os.WriteFile(filepath.Join(root, "proc/swaps"), []byte("Filename Type\n/swapfile file 1024 0 -2\n"), 0o644)
	})
	return h
}

const planJSON = `{
  "hostname": "web-1", "timezone": "UTC", "swap_mb": 1,
  "apt": {"packages": ["git", "curl"], "remove": ["snapd"]},
  "users": [{"name": "shop", "groups": []}],
  "runtimes": {"caddy": {"enabled": true}},
  "services": [{"name": "cron", "enabled": true, "state": "started"}],
  "unattended_upgrades": {"enabled": true, "auto_reboot": true, "reboot_time": "03:30"},
  "ssh": {"port": 22, "permit_root_login": "prohibit-password", "password_authentication": false}
}`

func TestApplyConvergesAndIsIdempotent(t *testing.T) {
	root := t.TempDir()
	f := &runnertest.Fake{}
	h := newHost(f, root)
	h.pkgs["snapd"] = true
	f.OnFunc("apt-get remove", func(c runnertest.Call) (runner.Result, error) { delete(h.pkgs, "snapd"); return runner.Result{}, nil })
	h.users["caddy"] = true
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte("-----BEGIN PGP PUBLIC KEY BLOCK-----\n"))
	}))
	defer srv.Close()
	p := New(Deps{Runner: f, FS: hostfs.FS{Root: root}, HTTP: srv.Client(), Arch: "amd64", CaddyKeyURL: srv.URL + "/gpg.key"})
	plan, err := commands.Decode[Plan](json.RawMessage(planJSON))
	if err != nil {
		t.Fatal(err)
	}
	col := &commands.Collector{}
	st := commands.NewTestStream("c", col)
	r, err := p.Apply(context.Background(), plan, st)
	if err != nil {
		t.Fatal(err, r)
	}
	res := r.(Result)
	if !res.Changed {
		t.Fatal("expected changes")
	}
	var names []string
	for _, s := range res.Steps {
		names = append(names, s.Name)
		if s.Error != "" {
			t.Fatal(s)
		}
	}
	if strings.Join(names, ",") != "hostname,timezone,swap,apt,user:shop,caddy,service:cron,unattended_upgrades,ssh" {
		t.Fatal(names)
	}
	for _, w := range []string{"hostnamectl set-hostname web-1", "timedatectl set-timezone UTC", "mkswap /swapfile", "swapon /swapfile",
		"apt-get remove -y", "useradd", "systemctl enable cron", "systemctl start cron", "sshd -t", "systemctl reload-or-restart ssh.service", "systemctl restart falak-edge.service"} {
		if !f.Ran(w) {
			t.Fatalf("missing %q:\n%s", w, strings.Join(f.Lines(), "\n"))
		}
	}
	if !h.pkgs["caddy"] || !h.pkgs["unattended-upgrades"] || !h.pkgs["git"] {
		t.Fatal(h.pkgs)
	}
	b, _ := os.ReadFile(filepath.Join(root, "etc/apt/apt.conf.d/52falak-unattended"))
	if !strings.Contains(string(b), `Automatic-Reboot "true"`) || !strings.Contains(string(b), `"03:30"`) {
		t.Fatal(string(b))
	}
	b, _ = os.ReadFile(filepath.Join(root, "etc/ssh/sshd_config.d/50-falak.conf"))
	if !strings.Contains(string(b), "PasswordAuthentication no") {
		t.Fatal(string(b))
	}
	b, _ = os.ReadFile(filepath.Join(root, "etc/fstab"))
	if string(b) != "/swapfile none swap sw 0 0\n" {
		t.Fatal(string(b))
	}
	var progress int
	for _, e := range col.Snapshot() {
		if e.Kind == commands.KindProgress {
			progress++
		}
	}
	if progress != 9 {
		t.Fatal("progress events", progress)
	}

	// Second run: nothing to do, no mutating commands.
	f.Reset()
	r, err = p.Apply(context.Background(), plan, st)
	if err != nil || r.(Result).Changed {
		t.Fatalf("not idempotent: %+v %v\n%s", r, err, strings.Join(f.Lines(), "\n"))
	}
	for _, l := range f.Lines() {
		for _, bad := range []string{"apt-get", "useradd", "hostnamectl", "timedatectl", "mkswap", "swapon", "systemctl start", "systemctl restart", "systemctl reload", "sshd"} {
			if strings.HasPrefix(l, bad) {
				t.Fatalf("mutating command on second run: %s", l)
			}
		}
	}
}

func TestRuntimeMirrorsArePassedToTheInstallers(t *testing.T) {
	root := t.TempDir()
	f := &runnertest.Fake{}
	newHost(f, root)
	var mu sync.Mutex
	var paths []string
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		paths = append(paths, r.URL.Path)
		mu.Unlock()
		http.NotFound(w, r) // the installs fail; only the URLs they fetched matter here
	}))
	defer srv.Close()
	p := New(Deps{Runner: f, FS: hostfs.FS{Root: root}, HTTP: srv.Client(), Arch: "amd64", FrankenPHPBase: srv.URL + "/default"})
	plan, err := commands.Decode[Plan](json.RawMessage(`{"runtimes": {
		"frankenphp": {"version": "1.4.0", "mirror": "` + srv.URL + `/gh/php/frankenphp/releases/download"},
		"node": {"versions": ["22.11.0"], "default": "22.11.0", "mirror": "` + srv.URL + `/nodejs"}
	}}`))
	if err != nil {
		t.Fatal(err)
	}
	if _, err := p.Apply(context.Background(), plan, commands.NewTestStream("c", &commands.Collector{})); err == nil {
		t.Fatal("expected the downloads to fail against the stub mirror")
	}
	got := strings.Join(paths, " ")
	for _, want := range []string{"/gh/php/frankenphp/releases/download/v1.4.0/frankenphp-linux-x86_64", "/nodejs/v22.11.0/SHASUMS256.txt"} {
		if !strings.Contains(got, want) {
			t.Fatalf("mirror not used: want %s in %s", want, got)
		}
	}
	if strings.Contains(got, "/default") {
		t.Fatalf("default base used despite a mirror: %s", got)
	}
}

func TestFailedStepContinuesAndSSHRestores(t *testing.T) {
	root := t.TempDir()
	f := &runnertest.Fake{}
	f.On("hostnamectl", runner.Result{ExitCode: 1, Stderr: []byte("denied")})
	f.On("sshd -t", runner.Result{ExitCode: 255, Stderr: []byte("bad config")})
	newHost(f, root)
	os.MkdirAll(filepath.Join(root, "etc/ssh/sshd_config.d"), 0o755)
	os.WriteFile(filepath.Join(root, "etc/ssh/sshd_config.d/50-falak.conf"), []byte("Port 22\n"), 0o644)
	p := New(Deps{Runner: f, FS: hostfs.FS{Root: root}})
	r, err := p.Apply(context.Background(), Plan{Hostname: "x", Timezone: "UTC", SSH: &SSH{Port: 2222}}, commands.NewTestStream("c", &commands.Collector{}))
	if err == nil || !strings.Contains(err.Error(), "hostname, ssh") {
		t.Fatal(err)
	}
	res := r.(Result)
	if len(res.Steps) != 3 || res.Steps[1].Error != "" || !res.Steps[1].Changed {
		t.Fatalf("%+v", res)
	}
	b, _ := os.ReadFile(filepath.Join(root, "etc/ssh/sshd_config.d/50-falak.conf"))
	if string(b) != "Port 22\n" {
		t.Fatal("not restored", string(b))
	}
	if f.Ran("systemctl reload") || f.Ran("systemctl restart ssh") || f.Ran("systemctl start ssh") {
		t.Fatal("applied a broken ssh config")
	}
}

func TestContainerHostSkipsHostnameAndSwapButProvisionsTheRest(t *testing.T) {
	root := t.TempDir()
	f := &runnertest.Fake{}
	// Rules match in registration order, so these win over newHost's defaults.
	f.OnFunc("systemd-detect-virt", func(c runnertest.Call) (runner.Result, error) { return runner.Result{ExitCode: 0}, nil })
	f.OnFunc("hostnamectl set-hostname", func(c runnertest.Call) (runner.Result, error) {
		return runner.Result{ExitCode: 1}, errors.New("Could not set static hostname: Device or resource busy")
	})
	h := newHost(f, root)
	h.users["caddy"] = true
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte("-----BEGIN PGP PUBLIC KEY BLOCK-----\n"))
	}))
	defer srv.Close()
	p := New(Deps{Runner: f, FS: hostfs.FS{Root: root}, HTTP: srv.Client(), Arch: "amd64", CaddyKeyURL: srv.URL + "/gpg.key"})
	plan, err := commands.Decode[Plan](json.RawMessage(planJSON))
	if err != nil {
		t.Fatal(err)
	}
	r, err := p.Apply(context.Background(), plan, commands.NewTestStream("c", &commands.Collector{}))
	if err != nil {
		t.Fatal(err, r)
	}
	for _, s := range r.(Result).Steps {
		if s.Error != "" {
			t.Fatalf("step %s failed in a container: %s", s.Name, s.Error)
		}
	}
	for _, w := range []string{"fallocate", "swapon"} {
		if f.Ran(w) {
			t.Fatalf("%s must not run in a container", w)
		}
	}
	if !f.Ran("sshd -t") || !f.Ran("useradd") {
		t.Fatal("the rest of the plan must still converge")
	}
}

func TestSSHCreatesPrivilegeSeparationDirBeforeValidating(t *testing.T) {
	root := t.TempDir()
	f := &runnertest.Fake{}
	f.OnFunc("sshd -t", func(c runnertest.Call) (runner.Result, error) {
		if _, err := os.Stat(filepath.Join(root, "run/sshd")); err != nil {
			return runner.Result{ExitCode: 255}, errors.New("Missing privilege separation directory: /run/sshd")
		}
		return runner.Result{}, nil
	})
	p := New(Deps{Runner: f, FS: hostfs.FS{Root: root}})
	changed, err := p.ssh(context.Background(), commands.NewTestStream("c", &commands.Collector{}), SSH{Port: 22, PermitRootLogin: "prohibit-password"})
	if err != nil || !changed {
		t.Fatalf("changed=%v err=%v", changed, err)
	}
	// Host keys are generated (only missing ones) before sshd validates the config.
	lines := strings.Join(f.Lines(), "\n")
	if !strings.Contains(lines, "ssh-keygen -A") || strings.Index(lines, "ssh-keygen -A") > strings.Index(lines, "sshd -t") {
		t.Fatalf("ssh-keygen -A must run before sshd -t:\n%s", lines)
	}
}

func sshHost(t *testing.T, socketEnabled bool, active map[string]bool) (*Provisioner, *runnertest.Fake, string) {
	t.Helper()
	root := t.TempDir()
	f := &runnertest.Fake{}
	f.OnFunc("systemctl is-enabled --quiet ssh.socket", func(runnertest.Call) (runner.Result, error) {
		return runner.Result{ExitCode: map[bool]int{true: 0, false: 1}[socketEnabled]}, nil
	})
	f.OnFunc("systemctl is-active --quiet", func(c runnertest.Call) (runner.Result, error) {
		unit := c.Cmd.Args[len(c.Cmd.Args)-1]
		return runner.Result{ExitCode: map[bool]int{true: 0, false: 3}[active[unit]]}, nil
	})
	return New(Deps{Runner: f, FS: hostfs.FS{Root: root}}), f, root
}

func TestSSHIsStartedWhenStoppedEvenIfConfigIsUnchanged(t *testing.T) {
	p, f, _ := sshHost(t, false, map[string]bool{"ssh.service": false})
	cfg := SSH{Port: 22, PermitRootLogin: "prohibit-password"}
	st := commands.NewTestStream("c", &commands.Collector{})
	if _, err := p.ssh(context.Background(), st, cfg); err != nil {
		t.Fatal(err)
	}
	// Second converge: config unchanged, but a package upgrade stopped sshd in between.
	changed, err := p.ssh(context.Background(), st, cfg)
	if err != nil || !changed || !f.Ran("systemctl start ssh.service") {
		t.Fatalf("changed=%v err=%v\n%s", changed, err, strings.Join(f.Lines(), "\n"))
	}
}

func TestSocketActivatedSSHRestartsTheSocketNeverTheService(t *testing.T) {
	p, f, _ := sshHost(t, true, map[string]bool{"ssh.socket": false})
	if _, err := p.ssh(context.Background(), commands.NewTestStream("c", &commands.Collector{}), SSH{Port: 2222, PermitRootLogin: "prohibit-password"}); err != nil {
		t.Fatal(err)
	}
	if !f.Ran("systemctl daemon-reload") || !f.Ran("systemctl restart ssh.socket") || f.Ran("systemctl start ssh.service") || f.Ran("systemctl reload-or-restart ssh.service") {
		t.Fatalf("wrong unit handling:\n%s", strings.Join(f.Lines(), "\n"))
	}
}

func TestRunningSSHWithUnchangedConfigIsLeftAlone(t *testing.T) {
	p, f, _ := sshHost(t, false, map[string]bool{"ssh.service": true})
	cfg := SSH{Port: 22, PermitRootLogin: "prohibit-password"}
	st := commands.NewTestStream("c", &commands.Collector{})
	p.ssh(context.Background(), st, cfg)
	before := len(f.Lines())
	changed, err := p.ssh(context.Background(), st, cfg)
	for _, l := range f.Lines()[before:] {
		if strings.Contains(l, "restart") || strings.Contains(l, "systemctl start") {
			t.Fatalf("touched a healthy sshd: %s", l)
		}
	}
	if err != nil || changed {
		t.Fatalf("changed=%v err=%v", changed, err)
	}
}

// The incident on Ubuntu 26.04: an ondrej/php source without a resolute release broke apt-get update for the apt
// and caddy steps. Every step that updates apt goes through system.Apt.Update, which disables that source once.
func TestAptStepsRecoverFromAnOndrejSourceWithoutARelease(t *testing.T) {
	root := t.TempDir()
	f := &runnertest.Fake{}
	h := newHost(f, root)
	h.users["caddy"] = true
	ondrej := filepath.Join(root, "etc/apt/sources.list.d/ondrej-ubuntu-php-resolute.sources")
	os.MkdirAll(filepath.Dir(ondrej), 0o755)
	os.WriteFile(ondrej, []byte("Types: deb\nURIs: https://ppa.launchpadcontent.net/ondrej/php/ubuntu/\nSuites: resolute\n"), 0o644)
	f.OnFunc("apt-get update", func(runnertest.Call) (runner.Result, error) {
		if _, err := os.Stat(ondrej); err == nil {
			return runner.Result{ExitCode: 100, Stderr: []byte("E: The repository 'https://ppa.launchpadcontent.net/ondrej/php/ubuntu resolute Release' does not have a Release file.\n")}, nil
		}
		return runner.Result{}, nil
	})
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.Write([]byte("key")) }))
	defer srv.Close()
	p := New(Deps{Runner: f, FS: hostfs.FS{Root: root}, HTTP: srv.Client(), Arch: "amd64", CaddyKeyURL: srv.URL + "/gpg.key"})
	plan, err := commands.Decode[Plan](json.RawMessage(`{"apt": {"packages": ["git"]}, "runtimes": {"caddy": {"enabled": true}}}`))
	if err != nil {
		t.Fatal(err)
	}
	col := &commands.Collector{}
	st := commands.NewTestStream("c", col)
	r, err := p.Apply(context.Background(), plan, st)
	if err != nil {
		t.Fatalf("%v %+v", err, r)
	}
	if _, err := os.Stat(ondrej + ".disabled-by-falak"); err != nil || !h.pkgs["git"] || !h.pkgs["caddy"] {
		t.Fatalf("source not disabled or packages missing: %v %v", err, h.pkgs)
	}
	st.Flush()
	if !strings.Contains(col.Output(""), "has no release for this distribution; disabled /etc/apt/sources.list.d/ondrej-ubuntu-php-resolute.sources") {
		t.Fatal(col.Output(""))
	}

	// Another broken repository fails the step with its source file named.
	f2 := &runnertest.Fake{}
	newHost(f2, root)
	f2.On("apt-get update", runner.Result{ExitCode: 100, Stderr: []byte("E: The repository 'https://example.test/repo noble Release' is not signed.\n")})
	os.WriteFile(filepath.Join(root, "etc/apt/sources.list.d/example.list"), []byte("deb https://example.test/repo noble main\n"), 0o644)
	p2 := New(Deps{Runner: f2, FS: hostfs.FS{Root: root}, HTTP: srv.Client()})
	plan2, _ := commands.Decode[Plan](json.RawMessage(`{"apt": {"packages": ["htop"]}}`))
	r, err = p2.Apply(context.Background(), plan2, commands.NewTestStream("c", &commands.Collector{}))
	if err == nil || !strings.Contains(r.(Result).Steps[0].Error, "repository https://example.test/repo (/etc/apt/sources.list.d/example.list) breaks apt-get update") {
		t.Fatalf("%v %+v", err, r)
	}
}

// Re-provisioning a server whose edge runs Falak's Caddy build (rate limits) keeps it: stock Caddy could not load the
// edge's config (docs/plans/network/protection.md).
func TestReprovisionKeepsFalakCaddyBuild(t *testing.T) {
	root := t.TempDir()
	f := &runnertest.Fake{}
	h := newHost(f, root)
	h.users["caddy"] = true
	h.pkgs["caddy"] = true
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.Write([]byte("key")) }))
	defer srv.Close()
	fs := hostfs.FS{Root: root}
	build := fs.P(runtime.CaddyBuildDir + "/caddy-0123456789abcdef")
	os.MkdirAll(filepath.Dir(build), 0o755)
	os.WriteFile(build, []byte("caddy"), 0o755)
	os.MkdirAll(filepath.Dir(fs.P(runtime.EdgeUnitPath)), 0o755)
	os.WriteFile(fs.P(runtime.EdgeUnitPath), []byte(runtime.RenderEdgeUnit(runtime.EdgeUnit{Binary: build})), 0o644)
	p := New(Deps{Runner: f, FS: fs, HTTP: srv.Client(), Arch: "amd64", CaddyKeyURL: srv.URL + "/gpg.key"})
	plan, err := commands.Decode[Plan](json.RawMessage(`{"timezone": "UTC", "runtimes": {"caddy": {"enabled": true}}}`))
	if err != nil {
		t.Fatal(err)
	}
	if _, err := p.Apply(context.Background(), plan, commands.NewTestStream("c", &commands.Collector{})); err != nil {
		t.Fatal(err)
	}
	u, _ := fs.ReadFile(runtime.EdgeUnitPath)
	if !strings.Contains(string(u), "ExecStart="+build+" run") || f.Ran("/usr/bin/caddy validate") {
		t.Fatalf("%s\n%v", u, f.Lines())
	}

	// The build is gone (deleted by hand): stock Caddy again, but only if it accepts the edge's config.
	os.Remove(build)
	os.MkdirAll(filepath.Dir(fs.P(runtime.EdgeBootstrap)), 0o755)
	os.WriteFile(fs.P(runtime.EdgeBootstrap), []byte(`{"apps":{"http":{}}}`), 0o644)
	f.On("/usr/bin/caddy validate", runner.Result{ExitCode: 1, Stderr: []byte("module not registered: http.handlers.rate_limit")})
	col := &commands.Collector{}
	st := commands.NewTestStream("c", col)
	r, err := p.Apply(context.Background(), plan, st)
	st.Flush()
	if err == nil || !strings.Contains(fmt.Sprintf("%+v %s", r, col.Output("")), "refusing to switch") {
		t.Fatalf("%v %+v %s", err, r, col.Output(""))
	}
	if u, _ := fs.ReadFile(runtime.EdgeUnitPath); !strings.Contains(string(u), "ExecStart="+build+" run") {
		t.Fatal("the unit was switched to a binary that rejects the config")
	}
}
