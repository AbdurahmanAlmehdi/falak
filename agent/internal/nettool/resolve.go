package nettool

import (
	"bufio"
	"context"
	"errors"
	"io"
	"net"
	"net/netip"
	"strings"
	"time"
)

// Resolver is the part of *net.Resolver the tools use (tests fake it).
type Resolver interface {
	LookupNetIP(ctx context.Context, network, host string) ([]netip.Addr, error)
	LookupCNAME(ctx context.Context, host string) (string, error)
	LookupMX(ctx context.Context, name string) ([]*net.MX, error)
	LookupTXT(ctx context.Context, name string) ([]string, error)
	LookupNS(ctx context.Context, name string) ([]*net.NS, error)
	LookupSRV(ctx context.Context, service, proto, name string) (string, []*net.SRV, error)
	LookupAddr(ctx context.Context, addr string) ([]string, error)
}

// names resolves hostnames the way the target namespace does: its hosts file first, then its nameservers (or the
// host's resolver when no resolv.conf was given, i.e. on the host).
type names struct {
	r       Resolver
	hosts   map[string][]netip.Addr // lowercased name → addresses
	servers []string                // host:port the resolver dials; empty = the system's
	source  string                  // where the servers came from, for the output
}

// newNames builds the resolver for a run. nameserver (dns tool) overrides everything; in a container, the contents of
// its resolv.conf and hosts file (HelperSpec, read by the agent) replace the host's.
func newNames(nameserver string, inContainer bool, resolvConf, hosts string) (*names, error) {
	n := &names{hosts: map[string][]netip.Addr{}}
	switch {
	case nameserver != "":
		n.servers = []string{net.JoinHostPort(nameserver, "53")}
		n.source = "requested"
	case inContainer:
		n.servers = parseResolvConf(strings.NewReader(resolvConf))
		if len(n.servers) == 0 {
			return nil, errors.New("the container's resolv.conf names no nameserver")
		}
		n.source = "container resolv.conf"
	default:
		n.source = "system resolver"
	}
	if inContainer && nameserver == "" {
		n.hosts = parseHosts(strings.NewReader(hosts))
	}
	if len(n.servers) == 0 {
		n.r = &net.Resolver{PreferGo: true}
		return n, nil
	}
	servers := n.servers
	n.r = &net.Resolver{PreferGo: true, Dial: func(ctx context.Context, network, _ string) (net.Conn, error) {
		// Each query tries the namespace's servers in order (the Go resolver retries per its own config too).
		var d net.Dialer
		var last error
		for _, s := range servers {
			cctx, cancel := context.WithTimeout(ctx, 3*time.Second)
			c, err := d.DialContext(cctx, network, s)
			cancel()
			if err == nil {
				return c, nil
			}
			last = err
		}
		return nil, last
	}}
	return n, nil
}

// lookupIP resolves host to addresses (an IP is returned as is): hosts file first, then DNS.
func (n *names) lookupIP(ctx context.Context, host string) ([]netip.Addr, error) {
	if a, err := netip.ParseAddr(host); err == nil {
		return []netip.Addr{a}, nil
	}
	key := strings.ToLower(strings.TrimSuffix(host, "."))
	if addrs, ok := n.hosts[key]; ok {
		return addrs, nil
	}
	addrs, err := n.r.LookupNetIP(ctx, "ip", host)
	if err != nil {
		return nil, err
	}
	out := make([]netip.Addr, 0, len(addrs))
	for _, a := range addrs {
		out = append(out, a.Unmap())
	}
	return out, nil
}

// serverLabel describes where answers come from.
func (n *names) serverLabel() string {
	if len(n.servers) == 0 {
		return n.source
	}
	return strings.Join(n.servers, ", ") + " (" + n.source + ")"
}

// parseResolvConf returns the nameservers as host:53 (IPv6 bracketed), in order, at most 3 (as glibc).
func parseResolvConf(r io.Reader) []string {
	var out []string
	sc := bufio.NewScanner(r)
	for sc.Scan() {
		f := strings.Fields(sc.Text())
		if len(f) < 2 || f[0] != "nameserver" {
			continue
		}
		a, err := netip.ParseAddr(f[1])
		if err != nil {
			continue
		}
		out = append(out, net.JoinHostPort(a.String(), "53"))
		if len(out) == 3 {
			break
		}
	}
	return out
}

// parseHosts reads an /etc/hosts file: lowercased name → addresses in file order.
func parseHosts(r io.Reader) map[string][]netip.Addr {
	out := map[string][]netip.Addr{}
	sc := bufio.NewScanner(r)
	for sc.Scan() {
		line := sc.Text()
		if i := strings.IndexByte(line, '#'); i >= 0 {
			line = line[:i]
		}
		f := strings.Fields(line)
		if len(f) < 2 {
			continue
		}
		a, err := netip.ParseAddr(f[0])
		if err != nil || a.Zone() != "" {
			continue
		}
		for _, name := range f[1:] {
			key := strings.ToLower(strings.TrimSuffix(name, "."))
			out[key] = append(out[key], a)
		}
	}
	return out
}

// awsMetadataV6 is EC2's IPv6 instance metadata endpoint (a ULA, not link-local).
var awsMetadataV6 = netip.MustParseAddr("fd00:ec2::254")

// linkLocal reports addresses the http and tcp tools refuse: 169.254.0.0/16 and fe80::/10, where cloud metadata
// endpoints live, and EC2's IPv6 metadata address.
func linkLocal(a netip.Addr) bool {
	a = a.Unmap()
	return a.IsLinkLocalUnicast() || a.IsLinkLocalMulticast() || a == awsMetadataV6
}

// errLinkLocal is shown when a link-local address is refused.
var errLinkLocal = errors.New("link-local addresses (169.254.0.0/16, fe80::/10, cloud metadata endpoints) are not reachable from network tools")
