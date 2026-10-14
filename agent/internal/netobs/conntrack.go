package netobs

import (
	"encoding/binary"
	"errors"
	"net/netip"
	"strconv"
	"strings"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
)

// Conntrack usage and accounting switches (/proc/sys/net/netfilter); missing when nf_conntrack is not loaded.
const (
	ctCountPath = "/proc/sys/net/netfilter/nf_conntrack_count"
	ctMaxPath   = "/proc/sys/net/netfilter/nf_conntrack_max"
	ctAcctPath  = "/proc/sys/net/netfilter/nf_conntrack_acct"
)

// Accounting states reported in net.observed conntrack.accounting.
const (
	acctOn          = "on"
	acctOff         = "off"
	acctUnavailable = "unavailable"
)

// ConntrackUsage is the table's fill.
type ConntrackUsage struct {
	Entries, Limit int64
	// Accounting is on | off | unavailable (no conntrack module). The agent never changes it.
	Accounting string
}

func readInt(fs hostfs.FS, p string) (int64, bool) {
	b, err := fs.ReadFile(p)
	if err != nil {
		return 0, false
	}
	n, err := strconv.ParseInt(strings.TrimSpace(string(b)), 10, 64)
	return n, err == nil
}

// readConntrackUsage reads the conntrack table's count, limit and accounting switch; ok is false without conntrack.
func readConntrackUsage(fs hostfs.FS) (ConntrackUsage, bool) {
	count, ok1 := readInt(fs, ctCountPath)
	limit, ok2 := readInt(fs, ctMaxPath)
	if !ok1 || !ok2 {
		return ConntrackUsage{Accounting: acctUnavailable}, false
	}
	u := ConntrackUsage{Entries: count, Limit: limit, Accounting: acctOff}
	if acct, ok := readInt(fs, ctAcctPath); ok && acct != 0 {
		u.Accounting = acctOn
	}
	return u, true
}

// Flow is one conntrack entry: the original direction (as the initiator sent it) and the reply direction (as the
// responder sends back: differs from the reversed original after NAT), with byte counters when accounting is on.
type Flow struct {
	ID                 uint32
	Proto              uint8 // 6 tcp, 17 udp
	OrigSrc, OrigDst   netip.AddrPort
	ReplySrc, ReplyDst netip.AddrPort
	OrigBytes          uint64 // initiator → responder
	ReplyBytes         uint64 // responder → initiator
	Counters           bool
}

// key identifies a flow between dumps: its id when the kernel gave one, else its original tuple.
func (f Flow) key() string {
	if f.ID != 0 {
		return "id:" + strconv.FormatUint(uint64(f.ID), 10)
	}
	return tupleKey(protoName(f.Proto), f.OrigSrc, f.OrigDst)
}

func protoName(p uint8) string {
	switch p {
	case 6:
		return "tcp"
	case 17:
		return "udp"
	}
	return strconv.Itoa(int(p))
}

func tupleKey(proto string, local, remote netip.AddrPort) string {
	return proto + "|" + local.String() + "|" + remote.String()
}

// ctnetlink attribute types (linux/netfilter/nfnetlink_conntrack.h).
const (
	ctaTupleOrig     = 1
	ctaTupleReply    = 2
	ctaCountersOrig  = 9
	ctaCountersReply = 10
	ctaID            = 12

	ctaTupleIP    = 1
	ctaTupleProto = 2

	ctaIPv4Src = 1
	ctaIPv4Dst = 2
	ctaIPv6Src = 3
	ctaIPv6Dst = 4

	ctaProtoNum     = 1
	ctaProtoSrcPort = 2
	ctaProtoDstPort = 3

	ctaCountersBytes   = 2
	ctaCounters32Bytes = 4

	nlmsgHdrLen  = 16
	nfgenmsgLen  = 4
	nlmsgError   = 2
	nlmsgDone    = 3
	nlaTypeMask  = 0x3fff
	nfnlSubsysCT = 1
)

var errNetlink = errors.New("conntrack: netlink error")

// parseConntrackMessages parses one buffer of netlink messages of a ctnetlink dump, appending flows to out. done is
// true at NLMSG_DONE, and when a flow arrives past limit (capped: the rest of the dump is not read).
func parseConntrackMessages(b []byte, out []Flow, limit int) (flows []Flow, done, capped bool, err error) {
	flows = out
	for len(b) >= nlmsgHdrLen {
		l := int(binary.NativeEndian.Uint32(b[0:4]))
		typ := binary.NativeEndian.Uint16(b[4:6])
		if l < nlmsgHdrLen || l > len(b) {
			return flows, false, false, errors.New("conntrack: truncated netlink message")
		}
		msg := b[nlmsgHdrLen:l]
		switch {
		case typ == nlmsgDone:
			return flows, true, false, nil
		case typ == nlmsgError:
			if len(msg) >= 4 && int32(binary.NativeEndian.Uint32(msg[0:4])) != 0 {
				return flows, true, false, errNetlink
			}
		case typ>>8 == nfnlSubsysCT && len(msg) >= nfgenmsgLen:
			if len(flows) >= limit {
				return flows, true, true, nil
			}
			if f, ok := parseFlow(msg[nfgenmsgLen:]); ok {
				flows = append(flows, f)
			}
		}
		b = b[align4(l):]
	}
	return flows, false, false, nil
}

func align4(n int) int {
	if n%4 == 0 {
		return n
	}
	if a := n + 4 - n%4; a > 0 {
		return a
	}
	return n
}

// attrs walks netlink attributes; fn gets the type (flags masked off) and payload.
func attrs(b []byte, fn func(typ uint16, val []byte)) {
	for len(b) >= 4 {
		l := int(binary.NativeEndian.Uint16(b[0:2]))
		typ := binary.NativeEndian.Uint16(b[2:4]) & nlaTypeMask
		if l < 4 || l > len(b) {
			return
		}
		fn(typ, b[4:l])
		next := align4(l)
		if next > len(b) {
			return
		}
		b = b[next:]
	}
}

func parseFlow(b []byte) (Flow, bool) {
	var f Flow
	var okOrig bool
	attrs(b, func(typ uint16, val []byte) {
		switch typ {
		case ctaTupleOrig:
			f.Proto, f.OrigSrc, f.OrigDst, okOrig = parseTuple(val)
		case ctaTupleReply:
			_, f.ReplySrc, f.ReplyDst, _ = parseTuple(val)
		case ctaCountersOrig:
			if n, ok := parseCounterBytes(val); ok {
				f.OrigBytes, f.Counters = n, true
			}
		case ctaCountersReply:
			if n, ok := parseCounterBytes(val); ok {
				f.ReplyBytes, f.Counters = n, true
			}
		case ctaID:
			if len(val) >= 4 {
				f.ID = binary.BigEndian.Uint32(val)
			}
		}
	})
	return f, okOrig
}

func parseTuple(b []byte) (proto uint8, src, dst netip.AddrPort, ok bool) {
	var sa, da netip.Addr
	var sp, dp uint16
	attrs(b, func(typ uint16, val []byte) {
		switch typ {
		case ctaTupleIP:
			attrs(val, func(t uint16, v []byte) {
				switch {
				case (t == ctaIPv4Src || t == ctaIPv4Dst) && len(v) == 4:
					a := netip.AddrFrom4([4]byte(v))
					if t == ctaIPv4Src {
						sa = a
					} else {
						da = a
					}
				case (t == ctaIPv6Src || t == ctaIPv6Dst) && len(v) == 16:
					a := netip.AddrFrom16([16]byte(v)).Unmap()
					if t == ctaIPv6Src {
						sa = a
					} else {
						da = a
					}
				}
			})
		case ctaTupleProto:
			attrs(val, func(t uint16, v []byte) {
				switch {
				case t == ctaProtoNum && len(v) >= 1:
					proto = v[0]
				case t == ctaProtoSrcPort && len(v) >= 2:
					sp = binary.BigEndian.Uint16(v)
				case t == ctaProtoDstPort && len(v) >= 2:
					dp = binary.BigEndian.Uint16(v)
				}
			})
		}
	})
	if !sa.IsValid() || !da.IsValid() {
		return 0, netip.AddrPort{}, netip.AddrPort{}, false
	}
	return proto, netip.AddrPortFrom(sa, sp), netip.AddrPortFrom(da, dp), true
}

func parseCounterBytes(b []byte) (uint64, bool) {
	var n uint64
	var ok bool
	attrs(b, func(typ uint16, val []byte) {
		switch {
		case typ == ctaCountersBytes && len(val) == 8:
			n, ok = binary.BigEndian.Uint64(val), true
		case typ == ctaCounters32Bytes && len(val) == 4:
			n, ok = uint64(binary.BigEndian.Uint32(val)), true
		}
	})
	return n, ok
}

// flowSide is how a socket sees a flow: as its initiator, as its responder without NAT, or as the responder behind a
// DNAT (a published container port: the socket's tuple is the reply's).
type flowSide uint8

const (
	sideInitiator flowSide = iota
	sideResponder
)

type flowMatch struct {
	key  string
	side flowSide
}

// flowIndex maps socket tuples (proto | local | remote) to the flow they belong to.
func flowIndex(flows []Flow) map[string]flowMatch {
	idx := make(map[string]flowMatch, len(flows)*3)
	put := func(k string, m flowMatch) {
		if _, ok := idx[k]; !ok {
			idx[k] = m
		}
	}
	for _, f := range flows {
		p := protoName(f.Proto)
		k := f.key()
		put(tupleKey(p, f.OrigSrc, f.OrigDst), flowMatch{k, sideInitiator})
		put(tupleKey(p, f.OrigDst, f.OrigSrc), flowMatch{k, sideResponder})
		if f.ReplySrc.IsValid() {
			put(tupleKey(p, f.ReplySrc, f.ReplyDst), flowMatch{k, sideResponder})
		}
	}
	return idx
}

// flowCounters keeps every flow's last counters between dumps and turns them into deltas: monotonic per-peer bytes.
type flowCounters struct {
	primed bool
	last   map[string]flowSeen
}

// flowSeen is a flow's last counters and the dumps it has been missing from since.
type flowSeen struct {
	v      [2]uint64
	missed int
}

// keepMissing is how many dumps a flow's counters are kept while it is absent: a truncated dump leaves some flows out
// one pass and in the next, and their whole lifetime must not count again when they come back.
const keepMissing = 3

// deltas returns what each flow carried since the previous dump (orig, reply). The first dump only records a baseline
// (flows already open count only what they carry after it); a flow first seen later counts fully, unless the dump was
// truncated (it may have been open, unseen, for long): then it is a baseline too. A counter that went down (a new flow
// reusing an id) counts from zero. Flows missing from a dump keep their counters for keepMissing dumps.
func (fc *flowCounters) deltas(flows []Flow, truncated bool) map[string][2]uint64 {
	if fc.last == nil {
		fc.last = map[string]flowSeen{}
	}
	out := make(map[string][2]uint64, len(flows))
	seen := make(map[string]bool, len(flows))
	for _, f := range flows {
		if !f.Counters {
			continue
		}
		k := f.key()
		v := [2]uint64{f.OrigBytes, f.ReplyBytes}
		seen[k] = true
		prev, known := fc.last[k]
		fc.last[k] = flowSeen{v: v}
		if !fc.primed || (!known && truncated) {
			continue
		}
		d := v
		if known {
			for i := range d {
				if v[i] >= prev.v[i] {
					d[i] = v[i] - prev.v[i]
				}
			}
		}
		if d[0] != 0 || d[1] != 0 {
			out[k] = d
		}
	}
	for k, f := range fc.last {
		if seen[k] {
			continue
		}
		if f.missed++; f.missed > keepMissing {
			delete(fc.last, k)
			continue
		}
		fc.last[k] = f
	}
	fc.primed = true
	return out
}
