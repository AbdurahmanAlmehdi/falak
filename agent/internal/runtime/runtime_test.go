package runtime

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

func setup(t *testing.T, f *runnertest.Fake, client *http.Client) (*Runtime, string, commands.Stream) {
	root := t.TempDir()
	return New(Deps{Runner: f, FS: hostfs.FS{Root: root}, HTTP: client, Arch: "amd64"}), root, commands.NewTestStream("c", &commands.Collector{})
}

func mk(t *testing.T, root, p string) {
	if err := os.MkdirAll(filepath.Join(root, p), 0o755); err != nil {
		t.Fatal(err)
	}
}

func TestPHPInstall(t *testing.T) {
	installed := map[string]bool{}
	f := &runnertest.Fake{}
	f.OnFunc("dpkg-query", func(c runnertest.Call) (runner.Result, error) {
		var b strings.Builder
		for _, a := range c.Args[2:] {
			if installed[a] {
				b.WriteString(a + "\tinstalled\t1\n")
			}
		}
		return runner.Result{ExitCode: 1, Stdout: []byte(b.String())}, nil
	})
	f.OnFunc("apt-get install", func(c runnertest.Call) (runner.Result, error) {
		for _, a := range c.Args {
			installed[a] = true
		}
		return runner.Result{}, nil
	})
	active := false
	f.OnFunc("systemctl is-active", func(runnertest.Call) (runner.Result, error) {
		if active {
			return runner.Result{}, nil
		}
		return runner.Result{ExitCode: 3}, nil
	})
	f.OnFunc("systemctl enable --now", func(runnertest.Call) (runner.Result, error) { active = true; return runner.Result{}, nil })
	alt := "Value: /usr/bin/php8.3\n"
	f.OnFunc("update-alternatives --query", func(runnertest.Call) (runner.Result, error) { return runner.Result{Stdout: []byte(alt)}, nil })
	f.OnFunc("update-alternatives --set", func(runnertest.Call) (runner.Result, error) {
		alt = "Value: /usr/bin/php8.4\n"
		return runner.Result{}, nil
	})
	f.OnFunc("add-apt-repository", func(c runnertest.Call) (runner.Result, error) { return runner.Result{}, nil })
	f.On("apt-cache policy php8.4-cli", runner.Result{Stdout: []byte("php8.4-cli:\n  Installed: (none)\n  Candidate: 8.4.13-1+ubuntu24.04.1+deb.sury.org+1\n")})
	rt, root, st := setup(t, f, nil)
	p := PHPInstallPayload{Version: "8.4", Extensions: []string{"mbstring", "intl", "pdo_mysql", "ctype", "redis"}, CLIDefault: true}
	r, err := rt.PHPInstall(context.Background(), p, st)
	if err != nil || !r.(PHPInstallResult).Changed {
		t.Fatal(r, err)
	}
	for _, want := range []string{"add-apt-repository -y ppa:ondrej/php", "systemctl enable --now php8.4-fpm", "update-alternatives --set php /usr/bin/php8.4"} {
		if !f.Ran(want) {
			t.Fatalf("missing %q in %v", want, f.Lines())
		}
	}
	for _, pk := range []string{"php8.4-cli", "php8.4-fpm", "php8.4-mbstring", "php8.4-intl", "php8.4-mysql", "php8.4-redis"} {
		if !installed[pk] {
			t.Fatalf("%s not installed", pk)
		}
	}
	if installed["php8.4-ctype"] {
		t.Fatal("ctype is in common")
	}
	// PPA now present on disk
	mk(t, root, "/etc/apt/sources.list.d")
	os.WriteFile(filepath.Join(root, "etc/apt/sources.list.d/ondrej-ubuntu-php-noble.sources"), []byte("URIs: https://ppa.launchpadcontent.net/ondrej/php/ubuntu/\n"), 0o644)
	f.Reset()
	r, err = rt.PHPInstall(context.Background(), p, st)
	if err != nil || r.(PHPInstallResult).Changed || f.Ran("apt-get") || f.Ran("add-apt-repository") || f.Ran("update-alternatives --set") {
		t.Fatal(r, err, f.Lines())
	}
}

// phpFake installs whatever apt-get install is given and offers the PHP versions in archive.
func phpFake(archive ...string) (*runnertest.Fake, map[string]bool) {
	installed := map[string]bool{}
	f := &runnertest.Fake{}
	f.OnFunc("dpkg-query", func(c runnertest.Call) (runner.Result, error) {
		var b strings.Builder
		for _, a := range c.Args[2:] {
			if installed[a] {
				b.WriteString(a + "\tinstalled\t1\n")
			}
		}
		return runner.Result{ExitCode: 1, Stdout: []byte(b.String())}, nil
	})
	f.OnFunc("apt-get install", func(c runnertest.Call) (runner.Result, error) {
		for _, a := range c.Args {
			installed[a] = true
		}
		return runner.Result{}, nil
	})
	f.OnFunc("apt-cache policy", func(c runnertest.Call) (runner.Result, error) {
		pkg := c.Args[1]
		cand := "(none)"
		for _, v := range archive {
			if pkg == "php"+v+"-cli" {
				cand = v + ".0-1ubuntu1"
			}
		}
		return runner.Result{Stdout: []byte(pkg + ":\n  Installed: (none)\n  Candidate: " + cand + "\n")}, nil
	})
	f.OnFunc("apt-cache search", func(runnertest.Call) (runner.Result, error) {
		var b strings.Builder
		for _, v := range archive {
			b.WriteString("php" + v + "-cli - command-line interpreter for the PHP scripting language\n")
		}
		return runner.Result{Stdout: []byte(b.String())}, nil
	})
	return f, installed
}

// ppaServer serves dists/<codename>/Release for the given codenames only.
func ppaServer(t *testing.T, codenames ...string) (*httptest.Server, *[]string) {
	var hits []string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hits = append(hits, r.URL.Path)
		for _, c := range codenames {
			if r.URL.Path == "/ondrej/php/ubuntu/dists/"+c+"/Release" {
				w.Write([]byte("Origin: LP-PPA-ondrej-php\n"))
				return
			}
		}
		http.NotFound(w, r)
	}))
	t.Cleanup(srv.Close)
	return srv, &hits
}

func osRelease(t *testing.T, root, version, codename string) {
	mk(t, root, "/etc")
	os.WriteFile(filepath.Join(root, "etc/os-release"), []byte("ID=ubuntu\nVERSION_ID=\""+version+"\"\nVERSION_CODENAME="+codename+"\n"), 0o644)
}

func TestPHPInstallFromUbuntuArchiveWhenThePPAHasNoRelease(t *testing.T) {
	srv, hits := ppaServer(t, "noble", "jammy")
	f, installed := phpFake("8.5")
	root := t.TempDir()
	osRelease(t, root, "26.04", "resolute")
	rt := New(Deps{Runner: f, FS: hostfs.FS{Root: root}, HTTP: srv.Client(), OndrejPPAURL: srv.URL + "/ondrej/php/ubuntu"})
	col := &commands.Collector{}
	st := commands.NewTestStream("c", col)

	r, err := rt.PHPInstall(context.Background(), PHPInstallPayload{Version: "8.5", Extensions: []string{"intl"}}, st)
	if err != nil || !r.(PHPInstallResult).Changed {
		t.Fatal(r, err)
	}
	if len(*hits) != 1 || (*hits)[0] != "/ondrej/php/ubuntu/dists/resolute/Release" {
		t.Fatalf("probe %v", *hits)
	}
	if f.Ran("add-apt-repository") || !f.Ran("apt-get update") || !installed["php8.5-cli"] || !installed["php8.5-intl"] {
		t.Fatalf("%v", f.Lines())
	}
	st.Flush()
	if out := col.Output(""); !strings.Contains(out, "ppa:ondrej/php has no packages for ubuntu 26.04 (resolute); installing PHP from the distribution's archive") {
		t.Fatalf("output %q", out)
	}

	f.Reset()
	_, err = rt.PHPInstall(context.Background(), PHPInstallPayload{Version: "8.4"}, st)
	want := "PHP 8.4 is not available on ubuntu 26.04 (ppa:ondrej/php has no packages for ubuntu 26.04 (resolute)); PHP versions available here: 8.5"
	if err == nil || err.Error() != want {
		t.Fatalf("got %v\nwant %s", err, want)
	}
	if f.Ran("apt-get install") {
		t.Fatal("must not try to install an unavailable version")
	}
}

// The incident machine: add-apt-repository had already written the deb822 source for resolute, which the PPA does
// not publish, so every apt-get update failed.
func TestPHPInstallOnTheIncidentMachine(t *testing.T) {
	srv, hits := ppaServer(t, "noble")
	f, installed := phpFake("8.5")
	root := t.TempDir()
	osRelease(t, root, "26.04", "resolute")
	src := filepath.Join(root, "etc/apt/sources.list.d/ondrej-ubuntu-php-resolute.sources")
	mk(t, root, "/etc/apt/sources.list.d")
	os.WriteFile(src, []byte("Types: deb\nURIs: https://ppa.launchpadcontent.net/ondrej/php/ubuntu/\nSuites: resolute\nComponents: main\nSigned-By: -----BEGIN PGP PUBLIC KEY BLOCK-----\n .\n mQINBGYo0HwBEADH\n -----END PGP PUBLIC KEY BLOCK-----\n"), 0o644)
	f.OnFunc("apt-get update", func(runnertest.Call) (runner.Result, error) {
		if _, err := os.Stat(src); err == nil {
			return runner.Result{ExitCode: 100, Stderr: []byte("E: The repository 'https://ppa.launchpadcontent.net/ondrej/php/ubuntu resolute Release' does not have a Release file.\n")}, nil
		}
		return runner.Result{}, nil
	})
	rt := New(Deps{Runner: f, FS: hostfs.FS{Root: root}, HTTP: srv.Client(), OndrejPPAURL: srv.URL + "/ondrej/php/ubuntu"})
	st := commands.NewTestStream("c", &commands.Collector{})

	if _, err := rt.PHPInstall(context.Background(), PHPInstallPayload{Version: "8.5"}, st); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(src + ".disabled-by-falak"); err != nil || !installed["php8.5-fpm"] || f.Ran("add-apt-repository") {
		t.Fatalf("%v %v", err, f.Lines())
	}
	// Next time the disabled file does not count as the PPA: the release is probed and the PPA not re-added.
	if OndrejPPAPresent(hostfs.FS{Root: root}) {
		t.Fatal("a .disabled-by-falak file is not a source")
	}
	f.Reset()
	if _, err := rt.PHPInstall(context.Background(), PHPInstallPayload{Version: "8.5", Extensions: []string{"intl"}}, st); err != nil {
		t.Fatal(err)
	}
	if len(*hits) != 1 || f.Ran("add-apt-repository") || !installed["php8.5-intl"] {
		t.Fatalf("%v %v", *hits, f.Lines())
	}
}

func TestPHPInstallAddsThePPAWhenItHasTheRelease(t *testing.T) {
	srv, hits := ppaServer(t, "noble")
	f, installed := phpFake("8.3", "8.4")
	root := t.TempDir()
	osRelease(t, root, "24.04", "noble")
	rt := New(Deps{Runner: f, FS: hostfs.FS{Root: root}, HTTP: srv.Client(), OndrejPPAURL: srv.URL + "/ondrej/php/ubuntu"})
	if _, err := rt.PHPInstall(context.Background(), PHPInstallPayload{Version: "8.4"}, commands.NewTestStream("c", &commands.Collector{})); err != nil {
		t.Fatal(err)
	}
	if len(*hits) != 1 || !f.Ran("add-apt-repository -y ppa:ondrej/php") || !installed["php8.4-fpm"] {
		t.Fatalf("%v %v", *hits, f.Lines())
	}
}

func TestPHPConfigure(t *testing.T) {
	f := &runnertest.Fake{}
	rt, root, st := setup(t, f, nil)
	mk(t, root, "/etc/php/8.4/fpm")
	mk(t, root, "/etc/php/8.4/cli")
	p := PHPConfigurePayload{Version: "8.4", INI: map[string]any{"memory_limit": "512M", "opcache.enable": true, "max_execution_time": float64(60), "date.timezone": "Europe/Amsterdam", "error_log": "a b"}}
	r, err := rt.PHPConfigure(context.Background(), p, st)
	if err != nil || !r.(FilesResult).Changed || len(r.(FilesResult).Files) != 2 {
		t.Fatal(r, err)
	}
	b, _ := os.ReadFile(filepath.Join(root, "etc/php/8.4/fpm/conf.d/99-falak.ini"))
	want := "; Managed by Falak — do not edit\ndate.timezone = Europe/Amsterdam\nerror_log = \"a b\"\nmax_execution_time = 60\nmemory_limit = 512M\nopcache.enable = On\n"
	if string(b) != want {
		t.Fatalf("%q", b)
	}
	if !f.Ran("php-fpm8.4 -t") || !f.Ran("systemctl reload php8.4-fpm") {
		t.Fatal(f.Lines())
	}
	f.Reset()
	r, _ = rt.PHPConfigure(context.Background(), p, st)
	if r.(FilesResult).Changed || len(f.Lines()) != 0 {
		t.Fatal("not idempotent", f.Lines())
	}
	// failing config test reverts
	f.On("php-fpm8.4 -t", runner.Result{ExitCode: 1, Stderr: []byte("bad")})
	p.INI["memory_limit"] = "bogus"
	if _, err := rt.PHPConfigure(context.Background(), PHPConfigurePayload{Version: "8.4", SAPI: "fpm", INI: p.INI}, st); err == nil {
		t.Fatal("expected failure")
	}
	b, _ = os.ReadFile(filepath.Join(root, "etc/php/8.4/fpm/conf.d/99-falak.ini"))
	if string(b) != want {
		t.Fatal("not reverted")
	}
}

type entry struct {
	name, link string
	typ        byte
	body       string
}

func tgz(entries []entry) []byte {
	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	tw := tar.NewWriter(gz)
	for _, e := range entries {
		h := &tar.Header{Name: e.name, Typeflag: e.typ, Mode: 0o755, Linkname: e.link, Size: int64(len(e.body))}
		if e.typ != tar.TypeReg {
			h.Size = 0
		}
		tw.WriteHeader(h)
		if e.typ == tar.TypeReg {
			tw.Write([]byte(e.body))
		}
	}
	tw.Close()
	gz.Close()
	return buf.Bytes()
}

func TestNodeInstall(t *testing.T) {
	name := "node-v22.11.0-linux-x64.tar.gz"
	archive := tgz([]entry{
		{name: "node-v22.11.0-linux-x64/", typ: tar.TypeDir},
		{name: "node-v22.11.0-linux-x64/bin/node", typ: tar.TypeReg, body: "ELF"},
		{name: "node-v22.11.0-linux-x64/lib/node_modules/npm/bin/npm-cli.js", typ: tar.TypeReg, body: "js"},
		{name: "node-v22.11.0-linux-x64/bin/npm", typ: tar.TypeSymlink, link: "../lib/node_modules/npm/bin/npm-cli.js"},
	})
	sum := sha256.Sum256(archive)
	hits := 0
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hits++
		switch r.URL.Path {
		case "/dist/v22.11.0/SHASUMS256.txt":
			fmt.Fprintf(w, "%s  %s\nabc  other.tar.gz\n", hex.EncodeToString(sum[:]), name)
		case "/dist/v22.11.0/" + name:
			w.Write(archive)
		default:
			http.NotFound(w, r)
		}
	}))
	defer srv.Close()
	rt, root, st := setup(t, &runnertest.Fake{}, srv.Client())
	p := NodePayload{Version: "22.11.0", Default: true, Mirror: srv.URL + "/dist"}
	r, err := rt.NodeInstall(context.Background(), p, st)
	if err != nil || !r.(NodeResult).Changed {
		t.Fatal(r, err)
	}
	if b, _ := os.ReadFile(filepath.Join(root, "opt/falak/node/22.11.0/bin/node")); string(b) != "ELF" {
		t.Fatal("not extracted")
	}
	if l, _ := os.Readlink(filepath.Join(root, "usr/local/bin/node")); l != "/opt/falak/node/22.11.0/bin/node" {
		t.Fatal(l)
	}
	if b, err := os.ReadFile(filepath.Join(root, "opt/falak/node/22.11.0/bin/npm")); err != nil || string(b) != "js" {
		t.Fatal("npm symlink", err)
	}
	h := hits
	r, _ = rt.NodeInstall(context.Background(), p, st)
	if r.(NodeResult).Changed || hits != h {
		t.Fatal("not idempotent")
	}
	// sha mismatch
	p2 := NodePayload{Version: "22.11.0", SHA256: strings.Repeat("a", 64), Mirror: srv.URL + "/dist"}
	rt2, root2, _ := setup(t, &runnertest.Fake{}, srv.Client())
	if _, err := rt2.NodeInstall(context.Background(), p2, st); err == nil || !strings.Contains(err.Error(), "sha256 mismatch") {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(root2, "opt/falak/node/22.11.0")); err == nil {
		t.Fatal("partial install left behind")
	}
}

func TestExtractRejectsTraversal(t *testing.T) {
	cases := [][]entry{
		{{name: "x/../../evil", typ: tar.TypeReg, body: "x"}},
		{{name: "/etc/passwd", typ: tar.TypeReg, body: "x"}},
		{{name: "x/link", typ: tar.TypeSymlink, link: "../../../etc"}},
		{{name: "x/abs", typ: tar.TypeSymlink, link: "/etc/shadow"}},
	}
	for i, c := range cases {
		dst := t.TempDir()
		if err := ExtractTarGz(bytes.NewReader(tgz(c)), dst, 1); err == nil {
			t.Fatalf("case %d accepted", i)
		}
	}
}

func TestFrankenPHP(t *testing.T) {
	bin := []byte("frankenphp-binary")
	sum := sha256.Sum256(bin)
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/v1.4.0/frankenphp-linux-x86_64" {
			http.NotFound(w, r)
			return
		}
		w.Write(bin)
	}))
	defer srv.Close()
	f := &runnertest.Fake{}
	f.On("getent passwd caddy", runner.Result{Stdout: []byte("caddy:x:998:998::/var/lib/caddy:/usr/sbin/nologin\n")})
	f.On("systemctl is-enabled --quiet caddy.service", runner.Result{ExitCode: 1})
	rt, root, st := setup(t, f, srv.Client())
	rt.d.FrankenPHPBase = srv.URL
	p := FrankenPHPPayload{Version: "v1.4.0", SHA256: hex.EncodeToString(sum[:]), INI: map[string]any{"memory_limit": "256M"}}
	r, err := rt.FrankenPHPConfigure(context.Background(), p, st)
	if err != nil || !r.(BinaryResult).Changed {
		t.Fatal(r, err)
	}
	fi, _ := os.Stat(filepath.Join(root, "usr/local/bin/frankenphp"))
	if fi == nil || fi.Mode().Perm() != 0o755 {
		t.Fatal("binary")
	}
	unit, _ := os.ReadFile(filepath.Join(root, "etc/systemd/system/falak-edge.service"))
	if !strings.Contains(string(unit), "ExecStart=/usr/local/bin/frankenphp run --environ --config /etc/falak/caddy/bootstrap.json") ||
		!strings.Contains(string(unit), "ExecReload=/usr/local/bin/frankenphp reload --config /etc/falak/caddy/bootstrap.json --force") ||
		!strings.Contains(string(unit), "PHPRC=/etc/frankenphp") {
		t.Fatal(string(unit))
	}
	if b, _ := os.ReadFile(filepath.Join(root, "etc/falak/caddy/bootstrap.json")); !strings.Contains(string(b), `"unix//run/falak-edge/admin.sock|0600"`) || !strings.Contains(string(unit), "EnvironmentFile=-/etc/falak/caddy/edge.env") || !strings.Contains(string(unit), "RuntimeDirectory=falak-edge") {
		t.Fatal(string(b))
	}
	for _, w := range []string{"systemctl daemon-reload", "systemctl enable falak-edge.service", "systemctl restart falak-edge.service"} {
		if !f.Ran(w) {
			t.Fatal(w, f.Lines())
		}
	}
	// existing bootstrap (full config from edge) is never overwritten
	os.WriteFile(filepath.Join(root, "etc/falak/caddy/bootstrap.json"), []byte(`{"full":true}`), 0o644)
	f.Reset()
	r, err = rt.FrankenPHPConfigure(context.Background(), p, st)
	if err != nil || r.(BinaryResult).Changed || f.Ran("systemctl restart") || f.Ran("systemctl daemon-reload") {
		t.Fatal(r, err, f.Lines())
	}
	if b, _ := os.ReadFile(filepath.Join(root, "etc/falak/caddy/bootstrap.json")); string(b) != `{"full":true}` {
		t.Fatal("bootstrap overwritten")
	}
	// sha mismatch
	rt3, _, _ := setup(t, f, srv.Client())
	rt3.d.FrankenPHPBase = srv.URL
	p.SHA256 = strings.Repeat("b", 64)
	if _, err := rt3.FrankenPHPConfigure(context.Background(), p, st); err == nil {
		t.Fatal("mismatch accepted")
	}
}

func TestFrankenPHPDownloadsFromThePayloadMirror(t *testing.T) {
	bin := []byte("frankenphp-binary")
	var paths []string
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		paths = append(paths, r.URL.Path)
		if r.URL.Path != "/mirror/php/frankenphp/v1.4.0/frankenphp-linux-aarch64" {
			http.NotFound(w, r)
			return
		}
		w.Write(bin)
	}))
	defer srv.Close()
	f := &runnertest.Fake{}
	f.On("getent passwd caddy", runner.Result{Stdout: []byte("caddy:x:998:998::/var/lib/caddy:/usr/sbin/nologin\n")})
	f.On("systemctl is-enabled --quiet caddy.service", runner.Result{ExitCode: 1})
	rt, root, st := setup(t, f, srv.Client())
	rt.d.Arch = "arm64"
	rt.d.FrankenPHPBase = srv.URL + "/default-base-must-not-be-used"
	off := false
	r, err := rt.FrankenPHPConfigure(context.Background(), FrankenPHPPayload{Version: "1.4.0", Mirror: srv.URL + "/mirror/php/frankenphp/", AsEdge: &off}, st)
	if err != nil || !r.(BinaryResult).Changed {
		t.Fatal(r, err, paths)
	}
	if b, _ := os.ReadFile(filepath.Join(root, "usr/local/bin/frankenphp")); string(b) != string(bin) {
		t.Fatal("binary not downloaded from the mirror", paths)
	}
	if len(paths) != 1 {
		t.Fatal(paths)
	}
}

func TestFPMPool(t *testing.T) {
	f := &runnertest.Fake{}
	rt, root, st := setup(t, f, nil)
	mk(t, root, "/etc/php/8.4/fpm/pool.d")
	mr := 0
	p := FPMPoolPayload{PHPVersion: "8.4", Pool: "shop", User: "shop", MaxRequests: &mr, PHPAdminValues: map[string]string{"open_basedir": "/srv/falak/sites/shop"}, Env: map[string]string{"APP_ENV": "production"}}
	r, err := rt.FPMPool(context.Background(), p, st)
	if err != nil || !r.(ListenResult).Changed || r.(ListenResult).Listen != "/run/php/falak-shop-8.4.sock" {
		t.Fatal(r, err)
	}
	b, _ := os.ReadFile(filepath.Join(root, "etc/php/8.4/fpm/pool.d/falak-shop.conf"))
	for _, w := range []string{"[falak-shop]", "listen = /run/php/falak-shop-8.4.sock", "listen.group = caddy", "pm.max_requests = 0", "php_admin_value[open_basedir] = /srv/falak/sites/shop", `env[APP_ENV] = "production"`} {
		if !strings.Contains(string(b), w) {
			t.Fatalf("missing %q in\n%s", w, b)
		}
	}
	if strings.Join(f.Lines(), "|") != "php-fpm8.4 -t|systemctl reload php8.4-fpm" {
		t.Fatal(f.Lines())
	}
	f.Reset()
	r, _ = rt.FPMPool(context.Background(), p, st)
	if r.(ListenResult).Changed || len(f.Lines()) > 0 {
		t.Fatal("not idempotent")
	}
	p.State = "absent"
	r, _ = rt.FPMPool(context.Background(), p, st)
	r2, _ := rt.FPMPool(context.Background(), p, st)
	if !r.(ListenResult).Changed || r2.(ListenResult).Changed {
		t.Fatal("absent")
	}
}

// The slow log is a pool setting: rendered in the shared master's pool file and in an own master's alike, its
// directory made before php-fpm -t opens it, logrotate configured for it.
func TestFPMPoolSlowlog(t *testing.T) {
	f := &runnertest.Fake{}
	rt, root, st := setup(t, f, nil)
	mk(t, root, "/etc/php/8.4/fpm/pool.d")
	ctx := context.Background()
	read := func(rel string) string { b, _ := os.ReadFile(filepath.Join(root, rel)); return string(b) }
	p := FPMPoolPayload{PHPVersion: "8.4", Pool: "shop", User: "shop"}
	// A pool an older agent wrote: the payload without slowlog_timeout (a site that predates the slow log) rewrites
	// nothing and reloads no master.
	written := p
	written.Listen = "/run/php/falak-shop-8.4.sock"
	old := RenderPool(written, "caddy")
	mkfile := filepath.Join(root, "etc/php/8.4/fpm/pool.d/falak-shop.conf")
	if err := os.WriteFile(mkfile, []byte(old), 0o644); err != nil {
		t.Fatal(err)
	}
	if r, err := rt.FPMPool(ctx, p, st); err != nil || r.(ListenResult).Changed || len(f.Lines()) != 0 {
		t.Fatalf("an unchanged pool was rewritten: %v %v %v", r, err, f.Lines())
	}
	if read("etc/php/8.4/fpm/pool.d/falak-shop.conf") != old || strings.Contains(old, "slowlog") {
		t.Fatal("slow log without slowlog_timeout")
	}
	if strings.Contains(read("etc/php/8.4/fpm/pool.d/falak-shop.conf"), "slowlog") {
		t.Fatal("slow log without slowlog_timeout")
	}
	if !strings.Contains(read("etc/logrotate.d/falak-php-fpm"), "/var/log/falak/fpm-*.log /var/log/falak/php-fpm-slow/*.log {") {
		t.Fatal(read("etc/logrotate.d/falak-php-fpm"))
	}

	p.SlowlogTimeout = 5
	f.Reset()
	if r, err := rt.FPMPool(ctx, p, st); err != nil || !r.(ListenResult).Changed {
		t.Fatal(r, err)
	}
	want := "request_slowlog_timeout = 5s\nrequest_slowlog_trace_depth = 20\nslowlog = /var/log/falak/php-fpm-slow/shop.log\n"
	if !strings.Contains(read("etc/php/8.4/fpm/pool.d/falak-shop.conf"), want) {
		t.Fatal(read("etc/php/8.4/fpm/pool.d/falak-shop.conf"))
	}
	if fi, err := os.Stat(filepath.Join(root, "var/log/falak/php-fpm-slow")); err != nil || !fi.IsDir() {
		t.Fatal("slow log directory missing", err)
	}
	if strings.Join(f.Lines(), "|") != "php-fpm8.4 -t|systemctl reload php8.4-fpm" {
		t.Fatal(f.Lines())
	}

	p.Slice = "site_shop"
	if _, err := rt.FPMPool(ctx, p, st); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(read("etc/falak/fpm/shop.conf"), want) {
		t.Fatal(read("etc/falak/fpm/shop.conf"))
	}

	for _, bad := range []int{-1, 3601} {
		p.SlowlogTimeout = bad
		if _, err := rt.FPMPool(ctx, p, st); !commands.IsPayloadError(err) {
			t.Fatalf("slowlog_timeout %d: %v", bad, err)
		}
	}

	// The pool removed: its slow log goes with it.
	mk(t, root, "/var/log/falak/php-fpm-slow")
	os.WriteFile(filepath.Join(root, "var/log/falak/php-fpm-slow/shop.log"), []byte("x\n"), 0o644)
	p.SlowlogTimeout, p.State = 0, "absent"
	if _, err := rt.FPMPool(ctx, p, st); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(root, "var/log/falak/php-fpm-slow/shop.log")); !os.IsNotExist(err) {
		t.Fatal("slow log left behind", err)
	}
}

// A pool with a slice moves into its own master in that slice, and back into the shared one without.
func TestFPMPoolOwnMasterInSlice(t *testing.T) {
	f := &runnertest.Fake{}
	rt, root, st := setup(t, f, nil)
	mk(t, root, "/etc/php/8.4/fpm/pool.d")
	ctx := context.Background()
	p := FPMPoolPayload{PHPVersion: "8.4", Pool: "shop", User: "shop"}
	if _, err := rt.FPMPool(ctx, p, st); err != nil {
		t.Fatal(err)
	}
	f.Reset()

	p.Slice, p.OomScoreAdj = "site_shop", -500
	r, err := rt.FPMPool(ctx, p, st)
	if err != nil || !r.(ListenResult).Changed || r.(ListenResult).Listen != "/run/php/falak-shop-8.4.sock" {
		t.Fatal(r, err)
	}
	read := func(rel string) string { b, _ := os.ReadFile(filepath.Join(root, rel)); return string(b) }
	if read("etc/php/8.4/fpm/pool.d/falak-shop.conf") != "" {
		t.Fatal("pool still in the shared master")
	}
	conf := read("etc/falak/fpm/shop.conf")
	for _, w := range []string{"[global]\npid = /run/php/falak-fpm-shop.pid\n", "daemonize = no\n", "[falak-shop]\nuser = shop\n", "listen = /run/php/falak-shop-8.4.sock\n"} {
		if !strings.Contains(conf, w) {
			t.Fatalf("missing %q in\n%s", w, conf)
		}
	}
	if strings.Count(conf, "Managed by Falak") != 1 {
		t.Fatal(conf)
	}
	unit := read("etc/systemd/system/falak-fpm-shop.service")
	for _, w := range []string{"Slice=falak-site_shop.slice\n", "ExecStart=/usr/sbin/php-fpm8.4 --nodaemonize --fpm-config /etc/falak/fpm/shop.conf\n", "OOMPolicy=continue\n", "OOMScoreAdjust=-500\n", "Type=notify\n"} {
		if !strings.Contains(unit, w) {
			t.Fatalf("missing %q in\n%s", w, unit)
		}
	}
	want := "php-fpm8.4 -t --fpm-config /etc/falak/fpm/shop.conf|systemctl reload php8.4-fpm|systemctl daemon-reload|systemctl enable falak-fpm-shop.service|systemctl restart falak-fpm-shop.service"
	if strings.Join(f.Lines(), "|") != want {
		t.Fatal(f.Lines())
	}

	// Idempotent; a pool change only reloads the own master.
	f.Reset()
	if r, _ := rt.FPMPool(ctx, p, st); r.(ListenResult).Changed || len(f.Lines()) != 0 {
		t.Fatal(f.Lines())
	}
	p.MaxChildren = 9
	rt.FPMPool(ctx, p, st)
	if strings.Join(f.Lines(), "|") != "php-fpm8.4 -t --fpm-config /etc/falak/fpm/shop.conf|systemctl reload-or-restart falak-fpm-shop.service" {
		t.Fatal(f.Lines())
	}

	// Limits removed: back into the shared master.
	f.Reset()
	p.Slice = ""
	if r, _ := rt.FPMPool(ctx, p, st); !r.(ListenResult).Changed {
		t.Fatal("not moved back")
	}
	if read("etc/systemd/system/falak-fpm-shop.service") != "" || read("etc/falak/fpm/shop.conf") != "" || !strings.Contains(read("etc/php/8.4/fpm/pool.d/falak-shop.conf"), "[falak-shop]") {
		t.Fatal("own master left behind")
	}
	if strings.Join(f.Lines(), "|") != "systemctl disable --now falak-fpm-shop.service|systemctl daemon-reload|php-fpm8.4 -t|systemctl reload php8.4-fpm" {
		t.Fatal(f.Lines())
	}

	// A bad slice name is a payload error.
	p.Slice = "site-shop"
	if _, err := rt.FPMPool(ctx, p, st); !commands.IsPayloadError(err) {
		t.Fatal(err)
	}
}

// An own master that fails to start is rolled back: the pool is served by the shared master again.
func TestFPMPoolOwnMasterRollsBackWhenItFailsToStart(t *testing.T) {
	f := (&runnertest.Fake{}).On("systemctl restart falak-fpm-shop.service", runner.Result{ExitCode: 1, Stderr: []byte("Job failed")})
	rt, root, st := setup(t, f, nil)
	mk(t, root, "/etc/php/8.4/fpm/pool.d")
	ctx := context.Background()
	p := FPMPoolPayload{PHPVersion: "8.4", Pool: "shop", User: "shop"}
	rt.FPMPool(ctx, p, st)
	f.Reset()

	p.Slice = "site_shop"
	_, err := rt.FPMPool(ctx, p, st)
	if err == nil || !strings.Contains(err.Error(), "shared php8.4-fpm again") {
		t.Fatal(err)
	}
	read := func(rel string) string { b, _ := os.ReadFile(filepath.Join(root, rel)); return string(b) }
	if !strings.Contains(read("etc/php/8.4/fpm/pool.d/falak-shop.conf"), "[falak-shop]") || read("etc/systemd/system/falak-fpm-shop.service") != "" || read("etc/falak/fpm/shop.conf") != "" {
		t.Fatal("not rolled back")
	}
	if !f.Ran("systemctl disable --now falak-fpm-shop.service") || f.Lines()[len(f.Lines())-1] != "systemctl reload php8.4-fpm" {
		t.Fatal(f.Lines())
	}
}

func TestEdgeUnitJoinsSiteGroupsAndNeverDropsThem(t *testing.T) {
	fs := hostfs.FS{Root: t.TempDir()}
	f := (&runnertest.Fake{}).On("getent passwd caddy", runner.Result{Stdout: []byte("caddy:x:998:998::/var/lib/caddy:/usr/sbin/nologin\n")})
	st := commands.NewTestStream("x", &commands.Collector{})
	if _, err := EnsureEdgeUnit(context.Background(), f, fs, st, EdgeUnit{Binary: FrankenPHPBinary, FrankenPHP: true, Groups: []string{"falak"}}, false); err != nil {
		t.Fatal(err)
	}
	// A later call that doesn't know the site groups (standalone runtime.frankenphp.configure).
	if _, err := EnsureEdgeUnit(context.Background(), f, fs, st, EdgeUnit{Binary: FrankenPHPBinary, FrankenPHP: true, Groups: []string{"shop"}}, false); err != nil {
		t.Fatal(err)
	}
	b, _ := fs.ReadFile(EdgeUnitPath)
	if !strings.Contains(string(b), "SupplementaryGroups=falak shop\n") {
		t.Fatalf("unit:\n%s", b)
	}
}

func TestCaddyBinaryAndValidatedSwitches(t *testing.T) {
	fs := hostfs.FS{Root: t.TempDir()}
	if CaddyBinary(fs) != StockCaddy {
		t.Fatal("no unit: stock caddy")
	}
	build := fs.P(CaddyBuildDir + "/caddy-0123456789abcdef")
	os.MkdirAll(filepath.Dir(build), 0o755)
	os.WriteFile(build, []byte("x"), 0o755)
	os.MkdirAll(filepath.Dir(fs.P(EdgeUnitPath)), 0o755)
	os.WriteFile(fs.P(EdgeUnitPath), []byte(RenderEdgeUnit(EdgeUnit{Binary: build})), 0o644)
	if CaddyBinary(fs) != build {
		t.Fatal(CaddyBinary(fs))
	}
	os.WriteFile(fs.P(EdgeUnitPath), []byte(RenderEdgeUnit(EdgeUnit{Binary: FrankenPHPBinary, FrankenPHP: true})), 0o644)
	if CaddyBinary(fs) != StockCaddy {
		t.Fatal("a FrankenPHP unit is not a Caddy build")
	}

	// Switching binaries validates the boot config as the edge user, with the edge's environment.
	os.MkdirAll(filepath.Dir(fs.P(EdgeBootstrap)), 0o755)
	os.WriteFile(fs.P(EdgeBootstrap), []byte(`{}`), 0o644)
	os.WriteFile(fs.P(EdgeEnvPath), []byte("# tokens\nFALAK_DNS_TOKEN_ABC=secret\n"), 0o600)
	var call *runnertest.Call
	f := (&runnertest.Fake{}).On("getent passwd caddy", runner.Result{Stdout: []byte("caddy:x:998:998::/var/lib/caddy:/usr/sbin/nologin\n")})
	f.OnFunc(build+" validate", func(c runnertest.Call) (runner.Result, error) {
		call = &c
		return runner.Result{ExitCode: 1, Stderr: []byte("bad")}, nil
	})
	st := commands.NewTestStream("x", &commands.Collector{})
	if _, err := EnsureEdgeUnit(context.Background(), f, fs, st, EdgeUnit{Binary: build}, false); err == nil || !strings.Contains(err.Error(), "refusing to switch") {
		t.Fatal(err)
	}
	if call == nil || call.User != "caddy" || !call.ClearEnv || !slices.Contains(call.Env, "FALAK_DNS_TOKEN_ABC=secret") {
		t.Fatalf("%+v", call)
	}
	if f.Ran("systemctl restart") {
		t.Fatal("restarted")
	}
	// A rollback skips validation.
	if _, err := EnsureEdgeUnit(context.Background(), f, fs, st, EdgeUnit{Binary: build, SkipValidate: true}, false); err != nil {
		t.Fatal(err)
	}
}
