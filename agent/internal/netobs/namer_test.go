package netobs

import (
	"net/netip"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/metrics"
)

func TestNamerForEBPF(t *testing.T) {
	c := New(Options{})
	c.SetConfig(Config{Names: []PeerName{
		{Address: "10.90.0.2", Port: 0, Name: "server:01abc"},
		{Address: "127.0.0.1", Port: 5432, Name: "db:01kdb"},
	}})
	name := c.Namer(metrics.NetSnapshot{Containers: []metrics.NetContainer{{ID: "x", Site: "shop", Service: "app", IPs: []string{"172.18.0.4"}}}})
	for _, tc := range []struct {
		inHost bool
		dir    string
		addr   string
		port   uint16
		want   string
	}{
		{true, "outbound", "10.90.0.2", 22, "server:01abc"},
		{true, "outbound", "127.0.0.1", 5432, "db:01kdb"},
		{false, "outbound", "127.0.0.1", 5432, "local:5432"}, // a container's loopback is its own
		{true, "outbound", "172.18.0.4", 8080, "site:shop/app"},
		{true, "outbound", "1.1.1.1", 443, "external:443"},
		{true, "inbound", "1.1.1.1", 51234, "external"},
		{true, "outbound", "::ffff:192.168.1.9", 3306, "private:3306"},
	} {
		if got := name(tc.inHost, tc.dir, netip.MustParseAddr(tc.addr), tc.port); got != tc.want {
			t.Errorf("%s %s:%d => %q, want %q", tc.dir, tc.addr, tc.port, got, tc.want)
		}
	}
}
