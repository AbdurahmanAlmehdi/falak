package protect

import (
	"encoding/json"
	"fmt"
	"net/netip"
	"strings"
)

// The protection table. Never part of table inet falak: net.firewall.apply replaces that one atomically, which would
// drop every element and counter. Created once, then only changed element by element.
const (
	Table   = "falak_protect"
	Set4    = "falak_block4"
	Set6    = "falak_block6"
	Chain   = "prerouting"
	DefPath = "/etc/falak/protection.nft"
)

// TableDefinition is the table. The chain hangs on prerouting at raw priority: before conntrack, Docker's DNAT
// (published container ports are forwarded, never seen by an input chain) and routing, so one drop covers the host,
// the edge and every published port. setCounters adds per-element counters (hits per block; nftables ≥ 0.9.5).
func TableDefinition(setCounters bool) string {
	counter := ""
	if setCounters {
		counter = "\t\tcounter\n"
	}
	var b strings.Builder
	b.WriteString("#!/usr/sbin/nft -f\n# Managed by Falak (net.protection.apply) — do not edit\n")
	fmt.Fprintf(&b, "table inet %s {\n", Table)
	for _, s := range [][2]string{{Set4, "ipv4_addr"}, {Set6, "ipv6_addr"}} {
		fmt.Fprintf(&b, "\tset %s {\n\t\ttype %s\n\t\tflags interval, timeout\n%s\t}\n", s[0], s[1], counter)
	}
	fmt.Fprintf(&b, "\tchain %s {\n\t\ttype filter hook prerouting priority raw; policy accept;\n", Chain)
	fmt.Fprintf(&b, "\t\tip saddr @%s counter drop comment \"falak:block4\"\n", Set4)
	fmt.Fprintf(&b, "\t\tip6 saddr @%s counter drop comment \"falak:block6\"\n", Set6)
	b.WriteString("\t}\n}\n")
	return b.String()
}

// Counter is an nftables counter.
type Counter struct {
	Packets uint64 `json:"packets"`
	Bytes   uint64 `json:"bytes"`
}

// Element is one element of a block set as listed.
type Element struct {
	Prefix   netip.Prefix
	ExpiresS int64 // seconds left; -1 = no timeout
	Counter  *Counter
}

// Listing is what `nft -j list table` returned: set elements by set name, and the counters of rules by their
// "falak:<id>" comment (rules sharing a comment, e.g. a rule's IPv4 and IPv6 lines, are summed).
type Listing struct {
	Sets  map[string][]Element
	Rules map[string]Counter
	// HasChain: the drop chain is there (a table without it is rebuilt).
	HasChain bool
}

type nftDoc struct {
	Nftables []map[string]json.RawMessage `json:"nftables"`
}

type nftSet struct {
	Name string            `json:"name"`
	Elem []json.RawMessage `json:"elem"`
}

type nftRule struct {
	Chain   string            `json:"chain"`
	Comment string            `json:"comment"`
	Expr    []json.RawMessage `json:"expr"`
}

// ParseListing parses `nft -j list table …` output.
func ParseListing(raw []byte) (Listing, error) {
	var doc nftDoc
	if err := json.Unmarshal(raw, &doc); err != nil {
		return Listing{}, fmt.Errorf("nft -j: %w", err)
	}
	l := Listing{Sets: map[string][]Element{}, Rules: map[string]Counter{}}
	for _, obj := range doc.Nftables {
		if raw, ok := obj["chain"]; ok {
			var c struct {
				Name string `json:"name"`
			}
			if json.Unmarshal(raw, &c) == nil && c.Name == Chain {
				l.HasChain = true
			}
		}
		if raw, ok := obj["set"]; ok {
			var s nftSet
			if err := json.Unmarshal(raw, &s); err != nil {
				continue
			}
			els := l.Sets[s.Name]
			if els == nil {
				els = []Element{}
			}
			for _, e := range s.Elem {
				if el, ok := parseElem(e); ok {
					els = append(els, el)
				}
			}
			l.Sets[s.Name] = els
		}
		if raw, ok := obj["rule"]; ok {
			var r nftRule
			if err := json.Unmarshal(raw, &r); err != nil || !strings.HasPrefix(r.Comment, "falak:") {
				continue
			}
			id := strings.Fields(strings.TrimPrefix(r.Comment, "falak:"))
			if len(id) == 0 {
				continue
			}
			for _, e := range r.Expr {
				var ex struct {
					Counter *Counter `json:"counter"`
				}
				if json.Unmarshal(e, &ex) == nil && ex.Counter != nil {
					c := l.Rules[id[0]]
					c.Packets += ex.Counter.Packets
					c.Bytes += ex.Counter.Bytes
					l.Rules[id[0]] = c
				}
			}
		}
	}
	return l, nil
}

// parseElem reads one set element: "1.2.3.4", {"prefix": …}, {"range": [a, b]} (only when it is a prefix), or
// {"elem": {"val": …, "timeout": s, "expires": s, "counter": …}}.
func parseElem(raw json.RawMessage) (Element, bool) {
	el := Element{ExpiresS: -1}
	var wrapped struct {
		Elem *struct {
			Val     json.RawMessage `json:"val"`
			Timeout *int64          `json:"timeout"`
			Expires *int64          `json:"expires"`
			Counter *Counter        `json:"counter"`
		} `json:"elem"`
	}
	if json.Unmarshal(raw, &wrapped) == nil && wrapped.Elem != nil {
		p, ok := parseVal(wrapped.Elem.Val)
		if !ok {
			return el, false
		}
		el.Prefix, el.Counter = p, wrapped.Elem.Counter
		if wrapped.Elem.Expires != nil {
			el.ExpiresS = *wrapped.Elem.Expires
		} else if wrapped.Elem.Timeout != nil {
			el.ExpiresS = *wrapped.Elem.Timeout
		}
		return el, true
	}
	p, ok := parseVal(raw)
	el.Prefix = p
	return el, ok
}

func parseVal(raw json.RawMessage) (netip.Prefix, bool) {
	var s string
	if json.Unmarshal(raw, &s) == nil {
		p, err := ParsePrefix(s)
		return p, err == nil
	}
	var obj struct {
		Prefix *struct {
			Addr string `json:"addr"`
			Len  int    `json:"len"`
		} `json:"prefix"`
		Range []string `json:"range"`
	}
	if json.Unmarshal(raw, &obj) != nil {
		return netip.Prefix{}, false
	}
	if obj.Prefix != nil {
		a, err := netip.ParseAddr(obj.Prefix.Addr)
		if err != nil {
			return netip.Prefix{}, false
		}
		p, err := a.Prefix(obj.Prefix.Len)
		return p, err == nil
	}
	if len(obj.Range) == 2 {
		lo, err1 := netip.ParseAddr(obj.Range[0])
		hi, err2 := netip.ParseAddr(obj.Range[1])
		if err1 != nil || err2 != nil || lo.Is4() != hi.Is4() {
			return netip.Prefix{}, false
		}
		for bits := 0; bits <= lo.BitLen(); bits++ {
			p, _ := lo.Prefix(bits)
			if p.Addr() == lo && lastAddr(p) == hi {
				return p, true
			}
		}
	}
	return netip.Prefix{}, false
}

// lastAddr is the highest address of a prefix.
func lastAddr(p netip.Prefix) netip.Addr {
	b := p.Masked().Addr().AsSlice()
	for i := p.Bits(); i < len(b)*8; i++ {
		b[i/8] |= 1 << (7 - uint(i%8))
	}
	a, _ := netip.AddrFromSlice(b)
	return a
}

// elementArgs renders `{ a timeout 60s, b }` as separate arguments (never one shell string). Every item is printed
// from a netip.Prefix, so nothing from a payload reaches nft verbatim.
func elementArgs(items []elementSpec) []string {
	args := []string{"{"}
	for i, it := range items {
		if i > 0 {
			args = append(args, ",")
		}
		args = append(args, Canonical(it.prefix))
		if it.timeoutS > 0 {
			args = append(args, "timeout", fmt.Sprintf("%ds", it.timeoutS))
		}
	}
	return append(args, "}")
}

type elementSpec struct {
	prefix   netip.Prefix
	timeoutS int64 // 0 = permanent
}

func setFor(p netip.Prefix) string {
	if p.Addr().Is4() {
		return Set4
	}
	return Set6
}
