// Package netobs is the agent's network collector (docs/plans/network/connections.md): TCP connections by state and
// direction, listening sockets with their owner, "talks to" peers with counts and (with conntrack accounting) bytes,
// long-lived connections, conntrack usage, latency probes and WireGuard peer health, per network namespace (the host
// and every site / database container), as falak.net.* metrics and a per-minute net.observed report.
package netobs

import (
	"bufio"
	"bytes"
	"encoding/binary"
	"encoding/hex"
	"net/netip"
	"strconv"
	"strings"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
)

// TCP states as /proc/net/tcp numbers them (include/net/tcp_states.h).
const (
	tcpEstablished = 0x01
	tcpSynSent     = 0x02
	tcpSynRecv     = 0x03
	tcpFinWait1    = 0x04
	tcpFinWait2    = 0x05
	tcpTimeWait    = 0x06
	tcpClose       = 0x07
	tcpCloseWait   = 0x08
	tcpLastAck     = 0x09
	tcpListen      = 0x0A
	tcpClosing     = 0x0B
)

// stateName is the falak.net.state value of a TCP state ("" = not a connection: close, listen).
func stateName(st uint8) string {
	switch st {
	case tcpEstablished:
		return "established"
	case tcpSynSent:
		return "syn_sent"
	case tcpSynRecv:
		return "syn_recv"
	case tcpFinWait1, tcpFinWait2:
		return "fin_wait"
	case tcpTimeWait:
		return "time_wait"
	case tcpCloseWait:
		return "close_wait"
	case tcpLastAck:
		return "last_ack"
	case tcpClosing:
		return "closing"
	}
	return ""
}

// Socket is one line of /proc/<pid>/net/{tcp,tcp6,udp,udp6}.
type Socket struct {
	Proto  string // tcp | udp
	Local  netip.AddrPort
	Remote netip.AddrPort
	State  uint8
	UID    int
	Inode  uint64
}

// Listening reports whether the socket accepts connections: TCP in LISTEN, UDP bound and not connected.
func (s Socket) Listening() bool {
	if s.Proto == "tcp" {
		return s.State == tcpListen
	}
	return s.State == tcpClose && s.Remote.Port() == 0 && s.Local.Port() != 0
}

// parseHexAddr decodes "0100007F:1F90" (IPv4: one little-endian word) or a 32-digit IPv6 address (four little-endian
// words). An IPv4-mapped IPv6 address is unmapped.
func parseHexAddr(s string) (netip.AddrPort, bool) {
	h, p, ok := strings.Cut(s, ":")
	if !ok {
		return netip.AddrPort{}, false
	}
	port, err := strconv.ParseUint(p, 16, 16)
	if err != nil {
		return netip.AddrPort{}, false
	}
	b, err := hex.DecodeString(h)
	if err != nil {
		return netip.AddrPort{}, false
	}
	var addr netip.Addr
	switch len(b) {
	case 4:
		var a [4]byte
		binary.BigEndian.PutUint32(a[:], binary.LittleEndian.Uint32(b))
		addr = netip.AddrFrom4(a)
	case 16:
		var a [16]byte
		for i := 0; i < 16; i += 4 {
			binary.BigEndian.PutUint32(a[i:], binary.LittleEndian.Uint32(b[i:i+4]))
		}
		addr = netip.AddrFrom16(a).Unmap()
	default:
		return netip.AddrPort{}, false
	}
	return netip.AddrPortFrom(addr, uint16(port)), true
}

// parseSockets parses one socket table, at most limit lines (truncated reports lines left over).
func parseSockets(b []byte, proto string, limit int) (out []Socket, truncated int) {
	sc := bufio.NewScanner(bytes.NewReader(b))
	sc.Buffer(make([]byte, 0, 64*1024), 1024*1024)
	first := true
	for sc.Scan() {
		if first {
			first = false // header
			continue
		}
		f := strings.Fields(sc.Text())
		// sl local rem st tx:rx tr:when retrnsmt uid timeout inode ...
		if len(f) < 10 {
			continue
		}
		if len(out) >= limit {
			truncated++
			continue
		}
		local, ok1 := parseHexAddr(f[1])
		remote, ok2 := parseHexAddr(f[2])
		st, err := strconv.ParseUint(f[3], 16, 8)
		if !ok1 || !ok2 || err != nil {
			continue
		}
		uid, _ := strconv.Atoi(f[7])
		inode, _ := strconv.ParseUint(f[9], 10, 64)
		out = append(out, Socket{Proto: proto, Local: local, Remote: remote, State: uint8(st), UID: uid, Inode: inode})
	}
	return out, truncated
}

// readSockets reads the four socket tables of the network namespace /proc/<pid>/net belongs to (dir = "/proc/<pid>"),
// at most limit sockets in all. A missing table (no IPv6) is skipped; ok is false when none could be read.
func readSockets(fs hostfs.FS, dir string, limit int) (out []Socket, truncated int, ok bool) {
	for _, t := range []struct{ file, proto string }{{"tcp", "tcp"}, {"tcp6", "tcp"}, {"udp", "udp"}, {"udp6", "udp"}} {
		b, err := fs.ReadFile(dir + "/net/" + t.file)
		if err != nil {
			continue
		}
		ok = true
		socks, tr := parseSockets(b, t.proto, limit-len(out))
		out = append(out, socks...)
		truncated += tr
	}
	return out, truncated, ok
}

// listenerKey identifies a listening port in one namespace.
type listenerKey struct {
	proto string
	port  uint16
}

// inbound reports whether an established socket was accepted by a listener of its namespace: one on its local port,
// bound to a wildcard address or to the socket's own local address.
func inbound(s Socket, listening map[listenerKey][]netip.Addr) bool {
	for _, a := range listening[listenerKey{s.Proto, s.Local.Port()}] {
		if a.IsUnspecified() || a == s.Local.Addr() {
			return true
		}
	}
	return false
}

// exposure classifies a listener's bind address: public (a wildcard or a global address: reachable from outside
// unless the firewall drops it), private (RFC 1918, ULA, CGNAT, link-local) or loopback. Same rule as the security
// audit's.
func exposure(a netip.Addr) string {
	switch {
	case a.IsLoopback():
		return "loopback"
	case a.IsUnspecified():
		return "public"
	case a.IsPrivate() || a.IsLinkLocalUnicast() || cgnat.Contains(a):
		return "private"
	}
	return "public"
}

var cgnat = netip.MustParsePrefix("100.64.0.0/10")
