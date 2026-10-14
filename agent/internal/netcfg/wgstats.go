package netcfg

import (
	"context"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// PeerStats is one WireGuard peer's health as `wg show` reports it (docs/plans/network/connections.md).
type PeerStats struct {
	Interface  string
	PublicKey  string
	AllowedIPs []string
	// LatestHandshake is zero when the peer never completed a handshake.
	LatestHandshake time.Time
	RxBytes         uint64
	TxBytes         uint64
}

// WireGuardStats runs `wg show all latest-handshakes`, `transfer` and `allowed-ips` (never `dump`, which prints the
// interface's private key) and merges them per interface and peer, sorted. A failing `latest-handshakes` (no wg, no
// interface) is an error; the other two are best effort.
func WireGuardStats(ctx context.Context, r runner.Runner) ([]PeerStats, error) {
	show := func(what string) (string, error) {
		res, err := runner.Check(ctx, r, runner.Cmd{Name: "wg", Args: []string{"show", "all", what}})
		return string(res.Stdout), err
	}
	hs, err := show("latest-handshakes")
	if err != nil {
		return nil, err
	}
	tr, _ := show("transfer")
	ips, _ := show("allowed-ips")
	return ParseWireGuardStats(hs, tr, ips), nil
}

// ParseWireGuardStats merges the three `wg show all <what>` outputs (tab-separated: interface, public key, values).
func ParseWireGuardStats(handshakes, transfer, allowedIPs string) []PeerStats {
	peers := map[string]*PeerStats{}
	get := func(iface, key string) *PeerStats {
		k := iface + "\t" + key
		if peers[k] == nil {
			peers[k] = &PeerStats{Interface: iface, PublicKey: key}
		}
		return peers[k]
	}
	lines := func(s string, fn func(f []string)) {
		for _, l := range strings.Split(s, "\n") {
			f := strings.Split(strings.TrimSpace(l), "\t")
			if len(f) >= 3 && f[0] != "" && f[1] != "" {
				fn(f)
			}
		}
	}
	lines(handshakes, func(f []string) {
		p := get(f[0], f[1])
		if ts, err := strconv.ParseInt(strings.TrimSpace(f[2]), 10, 64); err == nil && ts > 0 {
			p.LatestHandshake = time.Unix(ts, 0).UTC()
		}
	})
	lines(transfer, func(f []string) {
		if len(f) < 4 {
			return
		}
		p := get(f[0], f[1])
		p.RxBytes, _ = strconv.ParseUint(strings.TrimSpace(f[2]), 10, 64)
		p.TxBytes, _ = strconv.ParseUint(strings.TrimSpace(f[3]), 10, 64)
	})
	lines(allowedIPs, func(f []string) {
		p := get(f[0], f[1])
		for _, ip := range strings.Fields(f[2]) {
			if ip != "(none)" {
				p.AllowedIPs = append(p.AllowedIPs, ip)
			}
		}
	})
	out := make([]PeerStats, 0, len(peers))
	for _, p := range peers {
		out = append(out, *p)
	}
	sort.Slice(out, func(i, j int) bool {
		if out[i].Interface != out[j].Interface {
			return out[i].Interface < out[j].Interface
		}
		return out[i].PublicKey < out[j].PublicKey
	})
	return out
}
