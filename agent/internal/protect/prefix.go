// Package protect implements net.protection.apply (blocked addresses in nftables sets with timeouts, docs/plans/
// network/protection.md) and the protection observer: SSH brute-force detection from the auth log with the optional
// auto-ban, and the firewall's rule and block-set counters.
package protect

import (
	"fmt"
	"net/netip"
	"strings"
)

// Hard limits: a block is never broader than these (the control plane refuses them too).
const (
	minBits4 = 8
	minBits6 = 16
)

// ParsePrefix parses an address ("203.0.113.7", "2001:db8::1") or a CIDR in canonical form ("203.0.113.0/24": no
// host bits). IPv4-mapped IPv6 addresses and zones are refused: the IPv4 form is the one that matches.
func ParsePrefix(s string) (netip.Prefix, error) {
	s = strings.TrimSpace(s)
	if s == "" || len(s) > 64 {
		return netip.Prefix{}, fmt.Errorf("invalid address %q", s)
	}
	var p netip.Prefix
	if strings.Contains(s, "/") {
		pp, err := netip.ParsePrefix(s)
		if err != nil {
			return netip.Prefix{}, fmt.Errorf("invalid CIDR %q", s)
		}
		if pp != pp.Masked() {
			return netip.Prefix{}, fmt.Errorf("CIDR %q has host bits set (%s)", s, pp.Masked())
		}
		p = pp
	} else {
		a, err := netip.ParseAddr(s)
		if err != nil || a.Zone() != "" {
			return netip.Prefix{}, fmt.Errorf("invalid address %q", s)
		}
		p = netip.PrefixFrom(a, a.BitLen())
	}
	if p.Addr().Zone() != "" || p.Addr().Is4In6() {
		return netip.Prefix{}, fmt.Errorf("invalid address %q (use the plain IPv4 or IPv6 form)", s)
	}
	return p, nil
}

// ParseBlock is ParsePrefix plus the hard limits for something that will be dropped.
func ParseBlock(s string) (netip.Prefix, error) {
	p, err := ParsePrefix(s)
	if err != nil {
		return p, err
	}
	if min := minBits(p); p.Bits() < min {
		return netip.Prefix{}, fmt.Errorf("%s is too broad to block (at least /%d)", p, min)
	}
	return p, nil
}

func minBits(p netip.Prefix) int {
	if p.Addr().Is4() {
		return minBits4
	}
	return minBits6
}

// Canonical prints a prefix the way nft and the control plane do: a single address without its length.
func Canonical(p netip.Prefix) string {
	if p.IsSingleIP() {
		return p.Addr().String()
	}
	return p.String()
}

// key is the map key of a prefix: always with the length ("203.0.113.7/32").
func key(p netip.Prefix) string { return p.String() }

func mustPrefixes(ss ...string) []netip.Prefix {
	out := make([]netip.Prefix, 0, len(ss))
	for _, s := range ss {
		out = append(out, netip.MustParsePrefix(s))
	}
	return out
}

// special are ranges a block may never touch: loopback, unspecified ("this network"), link-local, multicast, broadcast.
var special = mustPrefixes("0.0.0.0/8", "127.0.0.0/8", "169.254.0.0/16", "224.0.0.0/4", "255.255.255.255/32",
	"::/128", "::1/128", "fe80::/10", "ff00::/8", "::ffff:0:0/96")

// private are ranges an auto-ban never touches (Docker networks, load balancers, WireGuard peers live there); a user
// may block them after confirming in the panel.
var private = mustPrefixes("10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16", "100.64.0.0/10", "fc00::/7")

func overlapsAny(p netip.Prefix, list []netip.Prefix) (netip.Prefix, bool) {
	for _, g := range list {
		if g.Addr().Is4() == p.Addr().Is4() && g.Overlaps(p) {
			return g, true
		}
	}
	return netip.Prefix{}, false
}

// IsPrivate reports whether the address is in a private, CGNAT or ULA range.
func IsPrivate(a netip.Addr) bool {
	_, ok := overlapsAny(netip.PrefixFrom(a, a.BitLen()), private)
	return ok
}
