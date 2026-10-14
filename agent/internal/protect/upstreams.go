package protect

import (
	"encoding/binary"
	"encoding/hex"
	"net/netip"
	"strings"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
)

// Files the host's upstreams are read from.
const (
	ResolvConf         = "/etc/resolv.conf"
	ResolvedUpstreams  = "/run/systemd/resolve/resolv.conf" // systemd-resolved's real servers (resolv.conf has the stub)
	ProcRoute          = "/proc/net/route"
	ProcIPv6Route      = "/proc/net/ipv6_route"
	maxUpstreamsPerSrc = 32
)

// UpstreamAddrs are the addresses the host cannot lose without losing the network: its DNS servers and its default
// gateways. Blocking one would cut name resolution or routing for everything, the agent's connection included.
func UpstreamAddrs(fs hostfs.FS) []netip.Addr {
	var out []netip.Addr
	for _, f := range []string{ResolvConf, ResolvedUpstreams} {
		if raw, err := fs.ReadFile(f); err == nil {
			out = append(out, ParseNameservers(string(raw))...)
		}
	}
	if raw, err := fs.ReadFile(ProcRoute); err == nil {
		out = append(out, ParseRouteGateways(string(raw))...)
	}
	if raw, err := fs.ReadFile(ProcIPv6Route); err == nil {
		out = append(out, ParseIPv6RouteGateways(string(raw))...)
	}
	return out
}

// ParseNameservers reads the nameserver lines of a resolv.conf (loopback stubs included: harmless as guards).
func ParseNameservers(conf string) []netip.Addr {
	var out []netip.Addr
	for _, line := range strings.Split(conf, "\n") {
		f := strings.Fields(line)
		if len(f) >= 2 && f[0] == "nameserver" {
			if a, err := netip.ParseAddr(f[1]); err == nil && len(out) < maxUpstreamsPerSrc {
				out = append(out, a.Unmap().WithZone(""))
			}
		}
	}
	return out
}

// ParseRouteGateways reads the gateways of the default routes in /proc/net/route (hex, little-endian).
func ParseRouteGateways(table string) []netip.Addr {
	var out []netip.Addr
	for _, line := range strings.Split(table, "\n")[1:] {
		f := strings.Fields(line)
		if len(f) < 3 || f[1] != "00000000" || f[2] == "00000000" {
			continue
		}
		b, err := hex.DecodeString(f[2])
		if err != nil || len(b) != 4 {
			continue
		}
		var ip [4]byte
		binary.BigEndian.PutUint32(ip[:], binary.LittleEndian.Uint32(b))
		if len(out) < maxUpstreamsPerSrc {
			out = append(out, netip.AddrFrom4(ip))
		}
	}
	return out
}

// ParseIPv6RouteGateways reads the next hops of the default routes in /proc/net/ipv6_route.
func ParseIPv6RouteGateways(table string) []netip.Addr {
	zero := strings.Repeat("0", 32)
	var out []netip.Addr
	for _, line := range strings.Split(table, "\n") {
		f := strings.Fields(line)
		if len(f) < 5 || f[0] != zero || f[1] != "00" || f[4] == zero {
			continue
		}
		b, err := hex.DecodeString(f[4])
		if err != nil || len(b) != 16 {
			continue
		}
		if a, ok := netip.AddrFromSlice(b); ok && !a.IsLinkLocalUnicast() && len(out) < maxUpstreamsPerSrc {
			out = append(out, a)
		}
	}
	return out
}
