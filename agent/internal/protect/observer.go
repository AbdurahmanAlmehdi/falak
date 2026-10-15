package protect

import (
	"context"
	"errors"
	"io"
	"log/slog"
	"net/netip"
	"regexp"
	"sort"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/transport"
	"github.com/OthmanHaba/falak/agent/internal/version"
	commonpb "go.opentelemetry.io/proto/otlp/common/v1"
	metricspb "go.opentelemetry.io/proto/otlp/metrics/v1"
	resourcepb "go.opentelemetry.io/proto/otlp/resource/v1"
)

// ObserveEvery is the observer's interval.
const ObserveEvery = time.Minute

// Bounds of a report.
const (
	maxRuleCounters  = 500
	maxBlockCounters = 1000
	maxReportBans    = 100
)

// Requester sends agent requests (net.protection.observed).
type Requester interface {
	Request(ctx context.Context, typ string, in, out any) error
}

// MetricsEmitter receives OTLP metrics (the telemetry relay).
type MetricsEmitter interface {
	EmitMetrics(*metricspb.ResourceMetrics)
}

// Ban is an auto-ban the agent made.
type Ban struct {
	CIDR     string    `json:"cidr"`
	Until    time.Time `json:"until"`
	Failures int       `json:"failures"`
}

// AuthReport is the auth part of a report.
type AuthReport struct {
	Source  string       `json:"source"`
	Sources []SourceStat `json:"sources"`
}

// RuleCounter is a firewall rule's counter.
type RuleCounter struct {
	ID      string `json:"id"`
	Packets uint64 `json:"packets"`
	Bytes   uint64 `json:"bytes"`
}

// BlockCounter is a block's counter (hits).
type BlockCounter struct {
	CIDR    string `json:"cidr"`
	Packets uint64 `json:"packets"`
	Bytes   uint64 `json:"bytes"`
}

// Report is net.protection.observed (contracts/agent-protocol/requests/net.protection.observed.schema.json).
type Report struct {
	At      time.Time      `json:"at"`
	WindowS int            `json:"window_s"`
	Auth    *AuthReport    `json:"auth,omitempty"`
	Bans    []Ban          `json:"bans,omitempty"`
	Rules   []RuleCounter  `json:"rules,omitempty"`
	Blocks  []BlockCounter `json:"blocks,omitempty"`
	Blocked *Counter       `json:"blocked,omitempty"`
}

// Observer reads the auth log, auto-bans when it is on, reads the counters and reports.
type Observer struct {
	p         *Protection
	cp        Requester
	metrics   MetricsEmitter
	reader    *authReader
	log       *slog.Logger
	cpMissing bool
}

// NewObserver builds the observer: cp gets net.protection.observed, metrics the falak.net.* metrics.
func (p *Protection) NewObserver(cp Requester, metrics MetricsEmitter) *Observer {
	return &Observer{p: p, cp: cp, metrics: metrics, log: p.d.Logger.With("component", "protection"),
		reader: &authReader{runner: p.d.Runner, fs: p.d.FS, now: p.d.Now}}
}

// Run restores the block sets, then passes every ObserveEvery until ctx ends.
func (o *Observer) Run(ctx context.Context) {
	o.p.Restore(ctx)
	// The first read only positions the readers (the journal's last minute, the end of auth.log).
	o.reader.Read(ctx)
	for {
		select {
		case <-ctx.Done():
			return
		case <-time.After(ObserveEvery):
		}
		o.Pass(ctx)
	}
}

// Pass is one observation.
func (o *Observer) Pass(ctx context.Context) Report {
	p := o.p
	evs, source := o.reader.Read(ctx)
	now := p.d.Now()

	p.mu.Lock()
	window := defaultWindow
	if p.st.AutoBan.WindowS > 0 {
		window = time.Duration(p.st.AutoBan.WindowS) * time.Second
	}
	p.auth.add(evs, window, source == SourceJournal)
	p.auth.prune(now, window)
	newBans := 0
	if p.st.Configured && p.st.AutoBan.Enabled {
		newBans = p.autoBanLocked(ctx, now, window)
	}
	if p.auth.trustChanged && p.st.Configured {
		if err := p.saveLocked(); err != nil {
			o.log.Warn("saving protection state", "err", err)
		}
	}
	// Every report carries all active auto-bans, not only this minute's: a report that failed to arrive never loses
	// one (the control plane upserts them).
	bans := p.activeBansLocked(now)
	sources := p.auth.report()
	total := p.auth.total
	var prot Listing
	protOK, missing := false, false
	if p.st.Configured {
		if prot, protOK, missing = p.list(ctx); !protOK && missing {
			// Flushed behind our back (nftables.service restarted with `flush ruleset`, a reboot without the agent):
			// put it back.
			if _, err := p.syncLocked(ctx, io.Discard); err != nil {
				o.log.Warn("restoring the block sets failed", "err", err)
			}
			prot, protOK, _ = p.list(ctx)
		}
	}
	p.mu.Unlock()

	r := Report{At: now.UTC(), WindowS: int(ObserveEvery / time.Second), Bans: bans}
	if len(sources) > 0 {
		r.Auth = &AuthReport{Source: source, Sources: sources}
	}
	var fw Listing
	if res, err := p.nft(ctx, "-j", "list", "table", "inet", "falak"); err == nil {
		fw, _ = ParseListing(res.Stdout)
	}
	for id, c := range fw.Rules {
		if ruleIDRe.MatchString(id) {
			r.Rules = append(r.Rules, RuleCounter{ID: id, Packets: c.Packets, Bytes: c.Bytes})
		}
	}
	sort.Slice(r.Rules, func(i, j int) bool { return r.Rules[i].ID < r.Rules[j].ID })
	if len(r.Rules) > maxRuleCounters {
		r.Rules = r.Rules[:maxRuleCounters]
	}
	if protOK {
		var blocked Counter
		for _, id := range []string{"block4", "block6"} {
			blocked.Packets += prot.Rules[id].Packets
			blocked.Bytes += prot.Rules[id].Bytes
		}
		r.Blocked = &blocked
		for _, s := range []string{Set4, Set6} {
			for _, el := range prot.Sets[s] {
				if el.Counter != nil && el.Counter.Packets > 0 {
					r.Blocks = append(r.Blocks, BlockCounter{CIDR: Canonical(el.Prefix), Packets: el.Counter.Packets, Bytes: el.Counter.Bytes})
				}
			}
		}
		sort.Slice(r.Blocks, func(i, j int) bool {
			if r.Blocks[i].Packets != r.Blocks[j].Packets {
				return r.Blocks[i].Packets > r.Blocks[j].Packets
			}
			return r.Blocks[i].CIDR < r.Blocks[j].CIDR
		})
		if len(r.Blocks) > maxBlockCounters {
			r.Blocks = r.Blocks[:maxBlockCounters]
		}
	}
	o.emit(now, fw, prot, protOK, total)
	if newBans > 0 {
		o.log.Info("auto-banned", "count", newBans)
	}
	if r.Auth != nil || len(r.Bans) > 0 || len(r.Rules) > 0 || len(r.Blocks) > 0 {
		o.send(ctx, r)
	}
	return r
}

func (o *Observer) send(ctx context.Context, r Report) {
	if o.cp == nil {
		return
	}
	err := o.cp.Request(ctx, "net.protection.observed", r, nil)
	if err == nil {
		return
	}
	var se *transport.StatusError
	if errors.As(err, &se) && se.Code == 404 {
		if !o.cpMissing {
			o.cpMissing = true
			o.log.Info("the control plane does not take protection reports yet (net.protection.observed); metrics still flow")
		}
		return
	}
	o.log.Warn("net.protection.observed failed", "err", err)
}

// ruleIDRe bounds the rule ids a report carries (the schema's pattern and length).
var ruleIDRe = regexp.MustCompile(`^[A-Za-z0-9_-]{1,100}$`)

// activeBansLocked are the agent's unexpired auto-bans, the latest maxReportBans.
func (p *Protection) activeBansLocked(now time.Time) []Ban {
	var out []Ban
	for _, b := range p.st.Bans {
		if b.Until == nil || !b.Until.After(now) {
			continue
		}
		if pr, err := netip.ParsePrefix(b.CIDR); err == nil {
			out = append(out, Ban{CIDR: Canonical(pr), Until: b.Until.UTC(), Failures: b.Failures})
		}
	}
	sort.SliceStable(out, func(i, j int) bool { return out[i].Until.After(out[j].Until) })
	if len(out) > maxReportBans {
		out = out[:maxReportBans]
	}
	return out
}

// autoBanLocked bans the sources (IPv4 addresses, IPv6 /64s) over the threshold: never a trusted (logged-in) one, a
// private one, a guarded one or one a block already covers; at most maxBansPerPass a pass. Returns how many it banned.
func (p *Protection) autoBanLocked(ctx context.Context, now time.Time, window time.Duration) int {
	ab := p.st.AutoBan
	if ab.Failures < 2 || ab.BanS < 60 {
		return 0
	}
	over := p.auth.over(ab.Failures, now, window)
	if len(over) == 0 {
		return 0
	}
	guards := p.guardsLocked(ctx)
	var covering []netip.Prefix
	for k := range p.desiredLocked(now) {
		if pr, err := netip.ParsePrefix(k); err == nil {
			covering = append(covering, pr)
		}
	}
	banned := 0
	for _, src := range over {
		if banned >= maxBansPerPass {
			break
		}
		if p.auth.isTrusted(src, now) || IsPrivate(src.Addr()) || refusal(src, guards) != "" {
			continue
		}
		if _, ok := overlapsAny(src, covering); ok {
			delete(p.auth.hits, src)
			continue
		}
		if p.elements(ctx, "add", []elementSpec{{prefix: src, timeoutS: int64(ab.BanS)}}, nil) == 0 {
			continue
		}
		until := now.Add(time.Duration(ab.BanS) * time.Second).UTC()
		p.st.Bans = append(p.st.Bans, entry{CIDR: key(src), Until: &until, Failures: p.auth.count(src, now, window)})
		covering = append(covering, src)
		delete(p.auth.hits, src)
		banned++
	}
	if banned > 0 {
		if err := p.saveLocked(); err != nil {
			p.d.Logger.Warn("saving protection state", "err", err)
		}
	}
	return banned
}

func (o *Observer) emit(now time.Time, fw, prot Listing, protOK bool, authTotal uint64) {
	if o.metrics == nil {
		return
	}
	ts := uint64(now.UnixNano())
	var out []*metricspb.Metric
	sum := func(name, unit string, pts []*metricspb.NumberDataPoint) {
		if len(pts) > 0 {
			out = append(out, &metricspb.Metric{Name: name, Unit: unit, Data: &metricspb.Metric_Sum{Sum: &metricspb.Sum{
				DataPoints: pts, IsMonotonic: true, AggregationTemporality: metricspb.AggregationTemporality_AGGREGATION_TEMPORALITY_CUMULATIVE}}})
		}
	}
	point := func(v float64, attrs ...[2]string) *metricspb.NumberDataPoint {
		dp := &metricspb.NumberDataPoint{TimeUnixNano: ts, Value: &metricspb.NumberDataPoint_AsDouble{AsDouble: v}}
		for _, a := range attrs {
			dp.Attributes = append(dp.Attributes, kv(a[0], a[1]))
		}
		return dp
	}
	ids := make([]string, 0, len(fw.Rules))
	for id := range fw.Rules {
		ids = append(ids, id)
	}
	sort.Strings(ids)
	if len(ids) > maxRuleCounters {
		ids = ids[:maxRuleCounters]
	}
	var pk, by []*metricspb.NumberDataPoint
	for _, id := range ids {
		c := fw.Rules[id]
		pk = append(pk, point(float64(c.Packets), [2]string{"falak.firewall.rule", id}))
		by = append(by, point(float64(c.Bytes), [2]string{"falak.firewall.rule", id}))
	}
	sum("falak.net.firewall.packets", "{packet}", pk)
	sum("falak.net.firewall.bytes", "By", by)
	if protOK {
		var bp, bb []*metricspb.NumberDataPoint
		var gauge []*metricspb.NumberDataPoint
		for _, f := range [][3]string{{"block4", Set4, "ipv4"}, {"block6", Set6, "ipv6"}} {
			c := prot.Rules[f[0]]
			bp = append(bp, point(float64(c.Packets), [2]string{"network.type", f[2]}))
			bb = append(bb, point(float64(c.Bytes), [2]string{"network.type", f[2]}))
			gauge = append(gauge, point(float64(len(prot.Sets[f[1]])), [2]string{"network.type", f[2]}))
		}
		sum("falak.net.firewall.blocked.packets", "{packet}", bp)
		sum("falak.net.firewall.blocked.bytes", "By", bb)
		out = append(out, &metricspb.Metric{Name: "falak.net.protection.blocks", Unit: "{block}", Data: &metricspb.Metric_Gauge{Gauge: &metricspb.Gauge{DataPoints: gauge}}})
	}
	sum("falak.net.auth.failures", "{attempt}", []*metricspb.NumberDataPoint{point(float64(authTotal))})
	o.metrics.EmitMetrics(&metricspb.ResourceMetrics{
		Resource: &resourcepb.Resource{Attributes: []*commonpb.KeyValue{kv("service.name", "falak-host")}},
		ScopeMetrics: []*metricspb.ScopeMetrics{{
			Scope:   &commonpb.InstrumentationScope{Name: "falak-agent/network", Version: version.Version},
			Metrics: out,
		}},
	})
}

func kv(k, v string) *commonpb.KeyValue {
	return &commonpb.KeyValue{Key: k, Value: &commonpb.AnyValue{Value: &commonpb.AnyValue_StringValue{StringValue: v}}}
}
