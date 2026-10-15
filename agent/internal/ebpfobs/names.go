package ebpfobs

import (
	"math"
	"sort"
	"strings"
	"time"
)

const (
	// nameHalfLife is how fast a DNS name's rank fades: names are ranked over about an hour, not per pass.
	nameHalfLife = 30 * time.Minute
	// nameChallenge is how much busier a name must be than the quietest of a workload's top names to take its place:
	// the label set changes rarely, so the series count stays bounded.
	nameChallenge = 1.5
	// maxRanked is how many names a workload's ranking remembers (the quietest are forgotten past it).
	maxRanked = 500
	longName  = "(long name)"
)

// secondLevel are the labels under a two-letter country code that registries sell names beneath (example.co.uk,
// example.com.au). ponytail: a short rule instead of the public suffix list; a name under another multi-label suffix
// folds one label too short (it.github.io stays it.github.io, foo.blogspot.com → blogspot.com). Upgrade path:
// golang.org/x/net/publicsuffix once it is a dependency.
var secondLevel = map[string]bool{"co": true, "com": true, "net": true, "org": true, "gov": true, "edu": true, "ac": true,
	"or": true, "ne": true, "go": true, "gob": true, "mil": true, "nic": true}

// registrable folds a name to its registrable domain ("eTLD+1"): api.eu.stripe.com → stripe.com, a.b.example.co.uk →
// example.co.uk. Single labels (redis, a container name) and the root stay; a truncated name has lost its suffix.
func registrable(name string) string {
	if strings.HasSuffix(name, "…") {
		return longName
	}
	labels := strings.Split(name, ".")
	n := 2
	if len(labels) >= 3 && len(labels[len(labels)-1]) == 2 && secondLevel[labels[len(labels)-2]] {
		n = 3
	}
	if len(labels) <= n {
		return name
	}
	return strings.Join(labels[len(labels)-n:], ".")
}

// nameRanker keeps each workload's top DNS names over about an hour (decayed query counts), sticky: a name joins the
// top only by beating the quietest member by nameChallenge.
type nameRanker struct {
	scores map[string]map[string]float64 // workload => name => decayed queries
	top    map[string]map[string]bool
}

func newNameRanker() *nameRanker {
	return &nameRanker{scores: map[string]map[string]float64{}, top: map[string]map[string]bool{}}
}

// update decays every score by the time since the last pass, adds this pass's queries and recomputes the top sets.
func (r *nameRanker) update(queries map[string]map[string]uint64, elapsed time.Duration) {
	decay := math.Pow(0.5, elapsed.Seconds()/nameHalfLife.Seconds())
	for wl, scores := range r.scores {
		for name, s := range scores {
			if s *= decay; s < 0.01 {
				delete(scores, name)
				continue
			}
			scores[name] = s
		}
		if len(scores) == 0 && len(queries[wl]) == 0 {
			delete(r.scores, wl)
			delete(r.top, wl)
		}
	}
	for wl, names := range queries {
		scores := r.scores[wl]
		if scores == nil {
			scores = map[string]float64{}
			r.scores[wl] = scores
		}
		for name, q := range names {
			scores[name] += float64(q)
		}
		ranked := make([]string, 0, len(scores))
		for name := range scores {
			ranked = append(ranked, name)
		}
		sort.Slice(ranked, func(i, j int) bool {
			if scores[ranked[i]] != scores[ranked[j]] {
				return scores[ranked[i]] > scores[ranked[j]]
			}
			return ranked[i] < ranked[j]
		})
		for _, name := range ranked[min(len(ranked), maxRanked):] {
			delete(scores, name)
		}
		top := r.top[wl]
		if top == nil {
			top = map[string]bool{}
			r.top[wl] = top
		}
		for name := range top {
			if _, ok := scores[name]; !ok {
				delete(top, name)
			}
		}
		for _, name := range ranked {
			if top[name] || scores[name] == 0 {
				continue
			}
			if len(top) < maxNames {
				top[name] = true
				continue
			}
			quietest := ""
			for t := range top {
				if quietest == "" || scores[t] < scores[quietest] || (scores[t] == scores[quietest] && t > quietest) {
					quietest = t
				}
			}
			if scores[name] <= scores[quietest]*nameChallenge {
				break // ranked: no later name beats it either
			}
			delete(top, quietest)
			top[name] = true
		}
	}
}

// label is the metric label of a workload's name: itself in the top set, else "other".
func (r *nameRanker) label(wl, name string) string {
	if r.top[wl][name] {
		return name
	}
	return otherLabel
}
