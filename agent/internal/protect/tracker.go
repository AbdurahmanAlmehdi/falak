package protect

import (
	"net/netip"
	"sort"
	"time"
)

// Bounds of the tracker (memory) and of the auto-ban (rate).
const (
	maxTracked     = 10000 // source addresses with failures in the window
	maxTrusted     = 1000
	trustFor       = 24 * time.Hour
	maxBansPerPass = 60
	maxSources     = 100 // in one report
	maxUsers       = 5   // sample user names per source
	defaultWindow  = 10 * time.Minute
)

// SourceStat is one source address in a report.
type SourceStat struct {
	IP           string    `json:"ip"`
	Failures     int       `json:"failures"`
	InvalidUsers int       `json:"invalid_users"`
	Users        []string  `json:"users"`
	LastAt       time.Time `json:"last_at"`
}

// tracker keeps failures per source within the auto-ban window, the trusted (logged-in) addresses and the stats of
// the current report.
type tracker struct {
	hits    map[netip.Addr][]time.Time
	trusted map[netip.Addr]time.Time
	pass    map[netip.Addr]*SourceStat
	total   uint64 // failures since start (falak.net.auth.failures)
}

func newTracker() *tracker {
	return &tracker{hits: map[netip.Addr][]time.Time{}, trusted: map[netip.Addr]time.Time{}, pass: map[netip.Addr]*SourceStat{}}
}

// add records events; window is how far back failures count.
func (t *tracker) add(evs []AuthEvent, window time.Duration) {
	for _, ev := range evs {
		switch ev.Kind {
		case AuthAccepted:
			if _, ok := t.trusted[ev.Addr]; ok || len(t.trusted) < maxTrusted {
				t.trusted[ev.Addr] = ev.At
			}
			delete(t.hits, ev.Addr)
		case AuthFailure:
			n := max(ev.Count, 1)
			t.total += uint64(n)
			st := t.pass[ev.Addr]
			if st == nil {
				if len(t.pass) >= maxTracked {
					continue
				}
				st = &SourceStat{IP: ev.Addr.String(), Users: []string{}}
				t.pass[ev.Addr] = st
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
			if _, ok := t.hits[ev.Addr]; !ok && len(t.hits) >= maxTracked {
				continue
			}
			for range min(n, 1000) {
				t.hits[ev.Addr] = append(t.hits[ev.Addr], ev.At)
			}
			t.hits[ev.Addr] = trim(t.hits[ev.Addr], ev.At.Add(-window))
		}
	}
}

// prune forgets failures older than the window and trust older than a day.
func (t *tracker) prune(now time.Time, window time.Duration) {
	for a, ts := range t.hits {
		if ts = trim(ts, now.Add(-window)); len(ts) == 0 {
			delete(t.hits, a)
		} else {
			t.hits[a] = ts
		}
	}
	for a, at := range t.trusted {
		if now.Sub(at) > trustFor {
			delete(t.trusted, a)
		}
	}
}

// over lists the addresses with at least n failures in the window, most failures first.
func (t *tracker) over(n int) []netip.Addr {
	var out []netip.Addr
	for a, ts := range t.hits {
		if len(ts) >= n {
			out = append(out, a)
		}
	}
	sort.Slice(out, func(i, j int) bool {
		if len(t.hits[out[i]]) != len(t.hits[out[j]]) {
			return len(t.hits[out[i]]) > len(t.hits[out[j]])
		}
		return out[i].Less(out[j])
	})
	return out
}

func (t *tracker) isTrusted(a netip.Addr, now time.Time) bool {
	at, ok := t.trusted[a]
	return ok && now.Sub(at) <= trustFor
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
	t.pass = map[netip.Addr]*SourceStat{}
	return out
}

func trim(ts []time.Time, since time.Time) []time.Time {
	i := 0
	for i < len(ts) && ts[i].Before(since) {
		i++
	}
	ts = ts[i:]
	// Bounded per address: enough to decide any threshold the schema allows.
	if len(ts) > 1000 {
		ts = ts[len(ts)-1000:]
	}
	return ts
}

func contains(ss []string, s string) bool {
	for _, x := range ss {
		if x == s {
			return true
		}
	}
	return false
}
