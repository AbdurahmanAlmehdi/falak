package netcfg

import (
	"context"
	"strings"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

func TestParseWireGuardStats(t *testing.T) {
	k1, k2 := "xTIBA5rboUvnH4htodjb6e697QjLERt1NAB4mZqp8Dg=", "TrMvSoP4jYQlY6RIzBgbssQqY3vxI2Pi+y71lOWWXX0="
	hs := "wg0\t" + k2 + "\t0\nwg0\t" + k1 + "\t1760529600\n"
	tr := "wg0\t" + k1 + "\t1000\t2000\nwg0\t" + k2 + "\t0\t0\n"
	ips := "wg0\t" + k1 + "\t10.90.0.2/32 10.90.1.0/24\nwg0\t" + k2 + "\t(none)\n"
	got := ParseWireGuardStats(hs, tr, ips)
	if len(got) != 2 || got[0].PublicKey != k2 || got[1].PublicKey != k1 {
		t.Fatalf("%+v", got)
	}
	if !got[0].LatestHandshake.IsZero() || len(got[0].AllowedIPs) != 0 {
		t.Fatal("a peer without a handshake")
	}
	p := got[1]
	if !p.LatestHandshake.Equal(time.Unix(1760529600, 0)) || p.RxBytes != 1000 || p.TxBytes != 2000 || len(p.AllowedIPs) != 2 {
		t.Fatalf("%+v", p)
	}
}

func TestWireGuardStatsNeverDumps(t *testing.T) {
	f := &runnertest.Fake{}
	if _, err := WireGuardStats(context.Background(), f); err != nil {
		t.Fatal(err)
	}
	lines := f.Lines()
	if len(lines) != 3 {
		t.Fatalf("%v", lines)
	}
	for _, l := range lines {
		if strings.Contains(l, "dump") {
			t.Fatalf("ran %s (prints the private key)", l)
		}
	}
}
