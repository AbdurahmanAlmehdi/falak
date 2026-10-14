package protect

import (
	"net/netip"
	"sort"
	"time"
)

// Bounds of the tracker (memory) and of the auto-ban (rate).
const (
	maxTracked     = 10000 // source prefixes with failures in the window
	maxConns       = 40000 // sshd connections remembered for de-duplication
	maxTrusted     = 1000
	trustFor       = 24 * time.Hour
	maxBansPerPass = 60
	maxSources     = 100 // in one report
	maxUsers       = 5   // sample user names per source
	defaultWindow  = 10 * time.Minute
	bucketsPerWin  = 10
	v6Aggregate    = 64 // IPv6 sources are counted (and banned) per /64: one host has a whole /64 to rotate through
)

// SourceStat is one source in a report: an IPv4 address or an IPv6 /64.
type SourceStat struct {
	IP           string    `json:"ip"`
	Failures     int       `json:"failures"`
	InvalidUsers int       `json:"invalid_users"`
	Users        []string  `json:"users"`
	LastAt       time.Time `json:"last_at"`
}

// bucket counts failures from start for a tenth of the window.
type bucket struct {
	start time.Time
	n     int
}

type connKey struct {
	pid int
	src netip.Prefix
}

// tracker keeps failure counts per source within the auto-ban window (a few buckets per source, never one entry per
// attempt), the sshd connections already counted, the trusted (logged-in) addresses and the current report's stats.
type tracker struct {
	hits    map[netip.Prefix][]bucket
	conns   map[connKey]time.Time
	trusted map[netip.Addr]time.Time
	pass    map[netip.Prefix]*SourceStat
	total   uint64 // failures since start (falak.net.auth.failures)
	// trustChanged: the trusted set changed since it was last saved.
	trustChanged bool
}

func newTracker() *tracker {
	return &tracker{hits: map[netip.Prefix][]bucket{}, conns: map[connKey]time.Time{}, trusted: map[netip.Addr]time.Time{},
		pass: map[netip.Prefix]*SourceStat{}}
}

// sourcePrefix is what a source is counted and banned as: the IPv4 address, or the IPv6 /64.
func sourcePrefix(a netip.Addr) netip.Prefix {
	a = a.Unmap()
	if a.Is4() {
		return netip.PrefixFrom(a, 32)
	}
	p, _ := a.Prefix(v6Aggregate)
	return p
}

// add records events; window is how far back failures count.
func (t *tracker) add(evs []AuthEvent, window time.Duration) {
	size := max(window/bucketsPerWin, time.Second)
	for _, ev := range evs {
		switch ev.Kind {
		case AuthAccepted:
			if _, ok := t.trusted[ev.Addr]; ok || len(t.trusted) < maxTrusted {
				t.trusted[ev.Addr] = ev.At
				t.trustChanged = true
			}
			delete(t.hits, sourcePrefix(ev.Addr))
		case AuthFailure:
			src := sourcePrefix(ev.Addr)
			n := max(ev.Count, 1)
			if ev.PID > 0 {
				// One connection, one failure, however many lines (Invalid user, Failed, repeated) it logs.
				k := connKey{pid: ev.PID, src: src}
				if _, seen := t.conns[k]; seen {
					continue
				}
				if len(t.conns) < maxConns {
					t.conns[k] = ev.At
				}
				n = 1
			}
			t.total += uint64(n)
			st := t.pass[src]
			if st == nil {
				if len(t.pass) >= maxTracked {
					continue
				}
				st = &SourceStat{IP: Canonical(src), Users: []string{}}
				t.pass[src] = st
			}
			st.Failures += n
			if ev.Invalid {
				st.InvalidUsers += n
			}
			if ev.At.After(st.LastAt) {
				st.LastAt = ev.At
			}
			if ev.User != "" && len(st.Users) < maxUsers && !contains(st.Users, ev.User) {
				st.Users = append(st.Users, ev.User)
			}
			bs, ok := t.hits[src]
			if !ok && len(t.hits) >= maxTracked {
				continue
			}
			if l := len(bs); l > 0 && ev.At.Sub(bs[l-1].start) < size && !ev.At.Before(bs[l-1].start) {
				bs[l-1].n += n
			} else {
				bs = append(bs, bucket{start: ev.At, n: n})
				if len(bs) > bucketsPerWin+2 {
					bs = bs[len(bs)-bucketsPerWin-2:]
				}
			}
			t.hits[src] = bs
		}
	}
}

// count is the failures of a source in the window ending now.
func (t *tracker) count(src netip.Prefix, now time.Time, window time.Duration) int {
	n := 0
	for _, b := range t.hits[src] {
		if !b.start.Before(now.Add(-window)) {
			n += b.n
		}
	}
	return n
}

// prune forgets failures and connections older than the window and trust older than a day.
func (t *tracker) prune(now time.Time, window time.Duration) {
	since := now.Add(-window)
	for src, bs := range t.hits {
		i := 0
		for i < len(bs) && bs[i].start.Before(since) {
			i++
		}
		if i == len(bs) {
			delete(t.hits, src)
		} else {
			t.hits[src] = bs[i:]
		}
	}
	for k, at := range t.conns {
		if at.Before(since) {
			delete(t.conns, k)
		}
	}
	for a, at := range t.trusted {
		if now.Sub(at) > trustFor {
			delete(t.trusted, a)
			t.trustChanged = true
		}
	}
}

// over lists the sources with at least n failures in the window, most failures first.
func (t *tracker) over(n int, now time.Time, window time.Duration) []netip.Prefix {
	counts := map[netip.Prefix]int{}
	var out []netip.Prefix
	for src := range t.hits {
		if c := t.count(src, now, window); c >= n {
			counts[src] = c
			out = append(out, src)
		}
	}
	sort.Slice(out, func(i, j int) bool {
		if counts[out[i]] != counts[out[j]] {
			return counts[out[i]] > counts[out[j]]
		}
		return out[i].Addr().Less(out[j].Addr())
	})
	return out
}

// isTrusted: an address of the source logged in successfully within a day.
func (t *tracker) isTrusted(src netip.Prefix, now time.Time) bool {
	for a, at := range t.trusted {
		if src.Contains(a) && now.Sub(at) <= trustFor {
			return true
		}
	}
	return false
}

// trustedSnapshot is the trusted set for the state file.
func (t *tracker) trustedSnapshot() map[string]time.Time {
	out := make(map[string]time.Time, len(t.trusted))
	for a, at := range t.trusted {
		out[a.String()] = at.UTC()
	}
	return out
}

// restoreTrusted loads the trusted set from the state file.
func (t *tracker) restoreTrusted(m map[string]time.Time) {
	for s, at := range m {
		if a, err := netip.ParseAddr(s); err == nil && len(t.trusted) < maxTrusted {
			t.trusted[a.Unmap()] = at
		}
	}
}

// report takes the current pass's sources (top maxSources by failures) and starts a new pass.
func (t *tracker) report() []SourceStat {
	out := make([]SourceStat, 0, len(t.pass))
	for _, st := range t.pass {
		out = append(out, *st)
	}
	sort.Slice(out, func(i, j int) bool {
		if out[i].Failures != out[j].Failures {
			return out[i].Failures > out[j].Failures
		}
		return out[i].IP < out[j].IP
	})
	if len(out) > maxSources {
		out = out[:maxSources]
	}
	t.pass = map[netip.Prefix]*SourceStat{}
	return out
}

func contains(ss []string, s string) bool {
	for _, x := range ss {
		if x == s {
			return true
		}
	}
	return false
}
