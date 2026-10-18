package runtime

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/localedge"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

const localEdgeEnv = "FALAK_LOCAL_EDGE=1\nFALAK_LOCAL_EDGE_ADDR=172.17.0.1\nFALAK_LOCAL_EDGE_TRUST=172.30.0.2/32\nFALAK_WORKLOAD_SLICE=falak.slice\n"

// On the control-plane machine a pool without limits still gets its own master, in falak.slice: never the distro's
// shared master (system.slice, uncapped); a failed start is not rolled back into it.
func TestFPMPoolOnTheControlPlaneHost(t *testing.T) {
	f := &runnertest.Fake{}
	rt, root, st := setup(t, f, nil)
	mk(t, root, "/etc/php/8.4/fpm/pool.d")
	if _, err := rt.d.FS.WriteFile(localedge.Path, []byte(localEdgeEnv), 0o644); err != nil {
		t.Fatal(err)
	}
	p := FPMPoolPayload{PHPVersion: "8.4", Pool: "shop", User: "shop"}
	if _, err := rt.FPMPool(context.Background(), p, st); err != nil {
		t.Fatal(err)
	}
	read := func(rel string) string { b, _ := os.ReadFile(filepath.Join(root, rel)); return string(b) }
	if read("etc/php/8.4/fpm/pool.d/falak-shop.conf") != "" || !strings.Contains(read("etc/systemd/system/falak-fpm-shop.service"), "\nSlice=falak.slice\n") {
		t.Fatalf("pool not in its own master in falak.slice:\n%s", read("etc/systemd/system/falak-fpm-shop.service"))
	}
	// With limits: its own slice (falak-site_shop.slice, a child of falak.slice).
	p.Slice = "site_shop"
	rt.FPMPool(context.Background(), p, st)
	if !strings.Contains(read("etc/systemd/system/falak-fpm-shop.service"), "\nSlice=falak-site_shop.slice\n") {
		t.Fatal(read("etc/systemd/system/falak-fpm-shop.service"))
	}

	f2 := (&runnertest.Fake{}).On("systemctl restart falak-fpm-blog.service", runner.Result{ExitCode: 1})
	rt.d.Runner = f2
	if _, err := rt.FPMPool(context.Background(), FPMPoolPayload{PHPVersion: "8.4", Pool: "blog", User: "blog"}, st); err == nil {
		t.Fatal("a failed own master was accepted")
	}
	if read("etc/php/8.4/fpm/pool.d/falak-blog.conf") != "" || f2.Ran("systemctl reload php8.4-fpm") {
		t.Fatal("rolled back into the shared master")
	}
}

// The edge runs in falak.slice on the control-plane machine; RefreshEdgeUnit moves an existing one there at agent start.
func TestEdgeUnitOnTheControlPlaneHost(t *testing.T) {
	if strings.Contains(RenderEdgeUnit(EdgeUnit{Binary: StockCaddy}), "Slice=") {
		t.Fatal("slice on a regular server")
	}
	fs := hostfs.FS{Root: t.TempDir()}
	f := (&runnertest.Fake{}).On("getent passwd caddy", runner.Result{Stdout: []byte("caddy:x:998:998::/var/lib/caddy:/usr/sbin/nologin\n")})
	f.On("systemctl is-enabled", runner.Result{ExitCode: 1})
	if _, err := EnsureEdgeUnit(context.Background(), f, fs, discardStream{}, EdgeUnit{Binary: StockCaddy}, false); err != nil {
		t.Fatal(err)
	}
	// A drop-in of falak-ctl's is never touched.
	dropIn := EdgeUnitPath + ".d/50-after-docker.conf"
	fs.WriteFile(dropIn, []byte("[Unit]\nAfter=docker.service\n"), 0o644)
	f.Reset()
	if changed, _ := RefreshEdgeUnit(context.Background(), f, fs); changed {
		t.Fatal("refreshed a current unit")
	}
	fs.WriteFile(localedge.Path, []byte(localEdgeEnv), 0o644)
	changed, err := RefreshEdgeUnit(context.Background(), f, fs)
	b, _ := fs.ReadFile(EdgeUnitPath)
	if err != nil || !changed || !strings.Contains(string(b), "\nSlice=falak.slice\n") || !f.Ran("systemctl restart falak-edge.service") {
		t.Fatalf("changed=%v err=%v\n%s", changed, err, b)
	}
	if d, _ := fs.ReadFile(dropIn); string(d) != "[Unit]\nAfter=docker.service\n" {
		t.Fatalf("drop-in touched: %q", d)
	}
	f.Reset()
	if changed, _ := RefreshEdgeUnit(context.Background(), f, fs); changed {
		t.Fatal("refreshed again")
	}
}
