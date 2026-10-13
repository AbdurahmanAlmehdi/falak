package runtime

import (
	"context"
	"strings"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

// A unit from before v0.10: no RuntimeDirectory, so /run/falak-edge (the admin socket's directory) never exists.
const preV010Unit = `# Managed by Falak
[Unit]
Description=Falak edge (Caddy/FrankenPHP)

[Service]
Type=notify
User=caddy
Group=caddy
SupplementaryGroups=falak shop
ExecStart=/usr/bin/caddy run --environ --config /etc/falak/caddy/config.json
`

func TestRefreshEdgeUnitUpgradesAnOldUnitOnce(t *testing.T) {
	fs := hostfs.FS{Root: t.TempDir()}
	if _, err := fs.WriteFile(EdgeUnitPath, []byte(preV010Unit), 0o644); err != nil {
		t.Fatal(err)
	}
	f := (&runnertest.Fake{}).On("getent passwd caddy", runner.Result{Stdout: []byte("caddy:x:998:998::/var/lib/caddy:/usr/sbin/nologin\n")})

	changed, err := RefreshEdgeUnit(context.Background(), f, fs)
	if err != nil || !changed {
		t.Fatalf("changed=%v err=%v", changed, err)
	}
	b, _ := fs.ReadFile(EdgeUnitPath)
	unit := string(b)
	for _, want := range []string{"RuntimeDirectory=falak-edge\n", "User=caddy\n", "SupplementaryGroups=falak shop\n", "ExecStart=/usr/bin/caddy run"} {
		if !strings.Contains(unit, want) {
			t.Fatalf("missing %q in:\n%s", want, unit)
		}
	}
	if strings.Contains(unit, "PHPRC") {
		t.Fatal("a Caddy unit became a FrankenPHP unit")
	}
	var restarted bool
	for _, c := range f.Calls() {
		restarted = restarted || c.Line == "systemctl restart falak-edge.service"
	}
	if !restarted {
		t.Fatalf("edge not restarted: %v", f.Calls())
	}

	// Current unit: nothing to do, nothing restarted.
	f2 := &runnertest.Fake{}
	if changed, err := RefreshEdgeUnit(context.Background(), f2, fs); err != nil || changed || len(f2.Calls()) != 0 {
		t.Fatalf("second refresh: changed=%v err=%v calls=%v", changed, err, f2.Calls())
	}
}

func TestRefreshEdgeUnitLeavesOtherUnitsAlone(t *testing.T) {
	f := &runnertest.Fake{}
	// No unit (not an edge server).
	if changed, err := RefreshEdgeUnit(context.Background(), f, hostfs.FS{Root: t.TempDir()}); err != nil || changed {
		t.Fatalf("no unit: %v %v", changed, err)
	}
	// A unit Falak doesn't manage.
	fs := hostfs.FS{Root: t.TempDir()}
	_, _ = fs.WriteFile(EdgeUnitPath, []byte("[Service]\nExecStart=/usr/bin/caddy run\n"), 0o644)
	if changed, err := RefreshEdgeUnit(context.Background(), f, fs); err != nil || changed || len(f.Calls()) != 0 {
		t.Fatalf("foreign unit: %v %v %v", changed, err, f.Calls())
	}
}
