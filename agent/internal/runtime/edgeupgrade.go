package runtime

import (
	"context"
	"io"
	"strings"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// RefreshEdgeUnit brings an existing falak-edge.service written by an older agent up to the current unit, keeping its
// user, binary, FrankenPHP mode and groups. Only provisioning (and FrankenPHP installs) wrote the unit, so a server
// provisioned before v0.10 kept a unit without RuntimeDirectory=falak-edge: /run/falak-edge never existed and Caddy
// refused every config that moved its admin API to the unix socket there. Called at agent start, which follows every
// agent upgrade. No unit, or one Falak doesn't manage: nothing to do. Restarts the edge only when the unit changed.
func RefreshEdgeUnit(ctx context.Context, r runner.Runner, fs hostfs.FS) (bool, error) {
	b, err := fs.ReadFile(EdgeUnitPath)
	if err != nil || !strings.HasPrefix(string(b), "# Managed by Falak") || strings.Contains(string(b), "\nRuntimeDirectory=falak-edge\n") {
		return false, nil
	}
	u := EdgeUnit{FrankenPHP: strings.Contains(string(b), "PHPRC=")}
	for _, l := range strings.Split(string(b), "\n") {
		if v, ok := strings.CutPrefix(l, "User="); ok {
			u.User = strings.TrimSpace(v)
		}
		if v, ok := strings.CutPrefix(l, "ExecStart="); ok {
			if f := strings.Fields(v); len(f) > 0 {
				u.Binary = f[0]
			}
		}
	}
	if u.Binary == "" {
		return false, nil // not a unit we can re-render faithfully
	}
	// Groups are merged from the existing unit by EnsureEdgeUnit.
	return EnsureEdgeUnit(ctx, r, fs, discardStream{}, u, false)
}

type discardStream struct{}

func (discardStream) Stdout() io.Writer   { return io.Discard }
func (discardStream) Stderr() io.Writer   { return io.Discard }
func (discardStream) Progress(float64)    {}
func (discardStream) Emit(string, string) {}
