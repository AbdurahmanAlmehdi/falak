package netcfg

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

const fakeTSKey = "tskey-auth-test"

func tsPayload() TailscalePayload {
	return TailscalePayload{State: "present", Version: "1.104.1", URL: "https://pkgs.tailscale.com/stable/tailscale_1.104.1_amd64.tgz",
		SHA256: strings.Repeat("a", 64), AuthKey: fakeTSKey, Hostname: "falak-main-1", AdvertiseRoutes: []string{"203.0.113.10/32", "2001:db8::10/128"}}
}

func TestValidTailscale(t *testing.T) {
	for name, tc := range map[string]struct {
		mod func(*TailscalePayload)
		ok  bool
	}{
		"ok":           {func(*TailscalePayload) {}, true},
		"no routes":    {func(p *TailscalePayload) { p.AdvertiseRoutes = nil }, true},
		"bad version":  {func(p *TailscalePayload) { p.Version = "latest" }, false},
		"http url":     {func(p *TailscalePayload) { p.URL = "http://x/t.tgz" }, false},
		"bad sha":      {func(p *TailscalePayload) { p.SHA256 = "abc" }, false},
		"no key":       {func(p *TailscalePayload) { p.AuthKey = "" }, false},
		"not a tskey":  {func(p *TailscalePayload) { p.AuthKey = "secret" }, false},
		"key newline":  {func(p *TailscalePayload) { p.AuthKey = fakeTSKey + "\n--x" }, false},
		"upper host":   {func(p *TailscalePayload) { p.Hostname = "Falak" }, false},
		"long host":    {func(p *TailscalePayload) { p.Hostname = strings.Repeat("a", 64) }, false},
		"dash host":    {func(p *TailscalePayload) { p.Hostname = "-a" }, false},
		"subnet route": {func(p *TailscalePayload) { p.AdvertiseRoutes = []string{"10.0.0.0/8"} }, false},
		"v6 subnet":    {func(p *TailscalePayload) { p.AdvertiseRoutes = []string{"2001:db8::/64"} }, false},
		"not a cidr":   {func(p *TailscalePayload) { p.AdvertiseRoutes = []string{"203.0.113.10"} }, false},
		"too many": {func(p *TailscalePayload) {
			p.AdvertiseRoutes = strings.Split("1.1.1.1/32,1.1.1.2/32,1.1.1.3/32,1.1.1.4/32,1.1.1.5/32", ",")
		}, false},
	} {
		p := tsPayload()
		tc.mod(&p)
		if _, err := validTailscale(p); (err == nil) != tc.ok {
			t.Errorf("%s: %v", name, err)
		}
	}
}

func TestRenderTailscaleUnit(t *testing.T) {
	u := RenderTailscaleUnit()
	for _, w := range []string{"ExecStart=" + TailscaledBinary + " --state=/var/lib/tailscale/tailscaled.state --socket=" + TailscaleSocket + " --port=41641", "Type=notify", "StateDirectory=tailscale"} {
		if !strings.Contains(u, w) {
			t.Fatalf("missing %q in\n%s", w, u)
		}
	}
}

func TestTailscaleResultParsesStatus(t *testing.T) {
	status := `{"BackendState":"Running","Self":{"HostName":"falak-main-1","DNSName":"falak-main-1-1.tail1234.ts.net.","TailscaleIPs":["100.101.102.103","fd7a:115c:a1e0::1"],"PrimaryRoutes":["203.0.113.10/32"]}}`
	var r TailscaleResult
	if err := tailscaleResult([]byte(status), []string{"203.0.113.10/32", "2001:db8::10/128"}, &r); err != nil {
		t.Fatal(err)
	}
	if !r.Active || r.BackendState != "Running" || r.Hostname != "falak-main-1-1" || r.IPv4 != "100.101.102.103" || r.IPv6 != "fd7a:115c:a1e0::1" || r.RoutesApproved {
		t.Fatalf("%+v", r)
	}
	r = TailscaleResult{}
	_ = tailscaleResult([]byte(status), []string{"203.0.113.10/32"}, &r)
	if !r.RoutesApproved {
		t.Fatal("approved route not seen")
	}
	r = TailscaleResult{}
	_ = tailscaleResult([]byte(`{"BackendState":"NeedsLogin"}`), nil, &r)
	if r.Active || !r.RoutesApproved {
		t.Fatalf("%+v", r)
	}
}

func tsTarball(t *testing.T) []byte {
	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	tw := tar.NewWriter(gz)
	for name, body := range map[string]string{"tailscale": "#!/bin/sh\n", "tailscaled": "#!/bin/sh\n", "systemd/tailscaled.service": "[Unit]\n"} {
		_ = tw.WriteHeader(&tar.Header{Name: "tailscale_1.104.1_amd64/" + name, Mode: 0o755, Size: int64(len(body)), Typeflag: tar.TypeReg})
		_, _ = tw.Write([]byte(body))
	}
	if err := tw.Close(); err != nil {
		t.Fatal(err)
	}
	_ = gz.Close()
	return buf.Bytes()
}

func TestTailscaleApplyJoinsAndRemoves(t *testing.T) {
	tgz := tsTarball(t)
	sum := sha256.Sum256(tgz)
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) { _, _ = w.Write(tgz) }))
	defer srv.Close()
	root := t.TempDir()

	running := false
	var keyFile string
	f := &runnertest.Fake{}
	sock := TailscaleBinary + " --socket=" + TailscaleSocket
	f.OnFunc(sock+" up", func(c runnertest.Call) (runner.Result, error) {
		for _, a := range c.Args {
			if v, ok := strings.CutPrefix(a, "--auth-key=file:"); ok {
				keyFile = v
			}
		}
		b, err := os.ReadFile(keyFile)
		info, _ := os.Stat(keyFile)
		if err != nil || string(b) != fakeTSKey || info.Mode().Perm() != 0o600 {
			t.Errorf("key file %q: %q %v", keyFile, b, err)
		}
		running = true
		return runner.Result{}, nil
	})
	f.OnFunc(sock+" status --json", func(runnertest.Call) (runner.Result, error) {
		if !running {
			return runner.Result{Stdout: []byte(`{"BackendState":"NeedsLogin"}`)}, nil
		}
		return runner.Result{Stdout: []byte(`{"BackendState":"Running","Self":{"DNSName":"falak-main-1.ts.net.","TailscaleIPs":["100.64.0.5","fd7a:115c:a1e0::5"],"PrimaryRoutes":["203.0.113.10/32","2001:db8::10/128"]}}`)}, nil
	})
	n := New(Deps{Runner: f, FS: hostfs.FS{Root: root}, HTTP: srv.Client()})
	p := tsPayload()
	p.URL, p.SHA256 = srv.URL+"/tailscale_1.104.1_amd64.tgz", hex.EncodeToString(sum[:])

	r, err := n.TailscaleApply(context.Background(), p, st)
	want := TailscaleResult{Changed: true, Active: true, Version: "1.104.1", BackendState: "Running", Hostname: "falak-main-1", IPv4: "100.64.0.5", IPv6: "fd7a:115c:a1e0::5", RoutesApproved: true}
	if err != nil || r != want {
		t.Fatal(r, err)
	}
	for _, b := range []string{TailscaleBinary, TailscaledBinary, TailscaleUnitPath} {
		if _, err := os.Stat(filepath.Join(root, b)); err != nil {
			t.Fatal(err)
		}
	}
	if _, err := os.Stat(keyFile); err == nil {
		t.Fatal("auth key file left behind")
	}
	lines := strings.Join(f.Lines(), "|")
	if strings.Contains(lines, fakeTSKey) || !strings.Contains(lines, "--hostname=falak-main-1 --advertise-routes=203.0.113.10/32,2001:db8::10/128 --accept-routes=false --accept-dns=false --reset --snat-subnet-routes=false --timeout=60s") ||
		!strings.Contains(lines, "systemctl restart falak-tailscaled.service") {
		t.Fatal(lines)
	}

	// Same payload on a running node: no download, no restart, unchanged; a key file a crash left is removed.
	f.Reset()
	stale := filepath.Join(root, "/etc/falak/.tailscale-key-crashed")
	_ = os.WriteFile(stale, []byte(fakeTSKey), 0o600)
	r, _ = n.TailscaleApply(context.Background(), p, st)
	if r.(TailscaleResult).Changed || strings.Contains(strings.Join(f.Lines(), "|"), "restart") {
		t.Fatal("not idempotent", f.Lines())
	}
	if _, err := os.Stat(stale); err == nil {
		t.Fatal("stale key file left behind")
	}

	// A bad payload is refused before anything runs.
	f.Reset()
	bad := p
	bad.AdvertiseRoutes = []string{"0.0.0.0/0"}
	if _, err := n.TailscaleApply(context.Background(), bad, st); err == nil || len(f.Lines()) != 0 {
		t.Fatal(err, f.Lines())
	}

	_ = os.MkdirAll(filepath.Join(root, tailscaleState), 0o700)
	_ = os.MkdirAll(filepath.Join(root, tailscaleCache), 0o750)
	r, _ = n.TailscaleApply(context.Background(), TailscalePayload{State: "absent"}, st)
	if !r.(TailscaleResult).Changed || !strings.Contains(strings.Join(f.Lines(), "|"), sock+" logout") {
		t.Fatal("absent did not remove", f.Lines())
	}
	for _, p := range []string{TailscaleUnitPath, TailscaleBinary, TailscaledBinary, tailscaleMarker, tailscaleState, tailscaleCache} {
		if _, err := os.Stat(filepath.Join(root, p)); err == nil {
			t.Errorf("%s left behind", p)
		}
	}
}
