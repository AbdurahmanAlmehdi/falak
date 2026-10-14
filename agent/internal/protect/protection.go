package protect

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log/slog"
	"net"
	"net/netip"
	"path"
	"sort"
	"strings"
	"sync"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// Limits of net.protection.apply (the schema has the same).
const (
	MaxBlocks     = 10000
	MaxNeverBlock = 1000
	// An element whose expiry moved by more than this is re-added (its counter restarts).
	expirySlack = 60
	// Elements per nft call.
	batchSize = 500
	stateFile = "protection.json"
)

// Deps are the collaborators.
type Deps struct {
	Runner   runner.Runner
	FS       hostfs.FS
	Logger   *slog.Logger
	StateDir string // /var/lib/falak
	Now      func() time.Time
	// ControlPlane resolves the control plane's addresses (never blocked); nil = none known.
	ControlPlane func(ctx context.Context) []netip.Addr
	// LocalAddrs are the host's own addresses; nil = the interfaces' addresses.
	LocalAddrs func() []netip.Addr
	// Upstreams are addresses the host depends on: its DNS servers and default gateways; nil = read from the host
	// (resolv.conf, systemd-resolved's upstream list, /proc/net/route and ipv6_route).
	Upstreams func() []netip.Addr
}

// Block is one blocked prefix of net.protection.apply.
type Block struct {
	CIDR      string     `json:"cidr"`
	ExpiresAt *time.Time `json:"expires_at"`
}

// AutoBan are the server's auto-ban settings (off by default).
type AutoBan struct {
	Enabled  bool `json:"enabled"`
	Failures int  `json:"failures"`
	WindowS  int  `json:"window_s"`
	BanS     int  `json:"ban_s"`
}

// ApplyPayload is net.protection.apply: the server's full desired state.
type ApplyPayload struct {
	Blocks     []Block  `json:"blocks"`
	Release    []string `json:"release"`
	NeverBlock []string `json:"never_block"`
	AutoBan    *AutoBan `json:"auto_ban"`
}

// Failure is an element that could not be applied.
type Failure struct {
	CIDR  string `json:"cidr"`
	Error string `json:"error"`
}

// ApplyResult is its result.
type ApplyResult struct {
	Changed     bool      `json:"changed"`
	Added       int       `json:"added"`
	Removed     int       `json:"removed"`
	Failed      []Failure `json:"failed"`
	SetCounters bool      `json:"set_counters"`
}

// entry is a block the agent keeps: from the control plane or its own auto-ban.
type entry struct {
	CIDR     string     `json:"cidr"`
	Until    *time.Time `json:"until,omitempty"`
	Failures int        `json:"failures,omitempty"`
}

// state is persisted in StateDir/protection.json (absolute expiry times: a restart restores the remaining timeouts).
type state struct {
	Configured bool     `json:"configured"`
	Blocks     []entry  `json:"blocks"`
	Bans       []entry  `json:"bans"`
	NeverBlock []string `json:"never_block"`
	AutoBan    AutoBan  `json:"auto_ban"`
	// NoSetCounters: this host refused per-element counters.
	NoSetCounters bool `json:"no_set_counters,omitempty"`
	// Trusted are addresses that logged in over SSH recently (never auto-banned), kept across restarts and upgrades.
	Trusted map[string]time.Time `json:"trusted,omitempty"`
}

// Protection holds the executor and the observer's state.
type Protection struct {
	d Deps

	mu      sync.Mutex // serializes applies, bans and observer passes
	st      state
	auth    *tracker
	cpAddrs []netip.Addr
	cpAt    time.Time
}

// New builds Protection and loads its state.
func New(d Deps) *Protection {
	if d.Logger == nil {
		d.Logger = slog.Default()
	}
	if d.Now == nil {
		d.Now = time.Now
	}
	if d.LocalAddrs == nil {
		d.LocalAddrs = interfaceAddrs
	}
	if d.Upstreams == nil {
		fs := d.FS
		d.Upstreams = func() []netip.Addr { return UpstreamAddrs(fs) }
	}
	p := &Protection{d: d, auth: newTracker()}
	if raw, err := d.FS.ReadFile(p.statePath()); err == nil {
		if err := json.Unmarshal(raw, &p.st); err != nil {
			d.Logger.Warn("protection state is corrupt, starting empty", "err", err)
			p.st = state{}
		}
	}
	p.auth.restoreTrusted(p.st.Trusted)
	return p
}

// Register adds net.protection.apply.
func (p *Protection) Register(reg *commands.Registry) {
	reg.Register("net.protection.apply", commands.Typed(p.Apply))
}

func (p *Protection) statePath() string { return path.Join(p.d.StateDir, stateFile) }

func (p *Protection) saveLocked() error {
	p.st.Trusted = p.auth.trustedSnapshot()
	p.auth.trustChanged = false
	b, err := json.MarshalIndent(p.st, "", "  ")
	if err != nil {
		return err
	}
	if err := p.d.FS.MkdirAll(p.d.StateDir, 0o755); err != nil {
		return err
	}
	_, err = p.d.FS.WriteFile(p.statePath(), b, 0o600)
	return err
}

func perr(format string, a ...any) error {
	return &commands.PayloadError{Err: fmt.Errorf(format, a...)}
}

func validAutoBan(a AutoBan) error {
	if !a.Enabled && a.Failures == 0 && a.WindowS == 0 && a.BanS == 0 {
		return nil
	}
	if a.Failures < 2 || a.Failures > 1000 || a.WindowS < 60 || a.WindowS > 86400 || a.BanS < 60 || a.BanS > 30*86400 {
		return perr("invalid auto_ban %+v", a)
	}
	return nil
}

// Apply validates the desired state, keeps it and makes the sets match it.
func (p *Protection) Apply(ctx context.Context, pl ApplyPayload, st commands.Stream) (any, error) {
	if len(pl.Blocks) > MaxBlocks {
		return nil, perr("too many blocks (%d > %d)", len(pl.Blocks), MaxBlocks)
	}
	if len(pl.NeverBlock) > MaxNeverBlock || len(pl.Release) > MaxBlocks {
		return nil, perr("too many never_block / release entries")
	}
	blocks := make([]entry, 0, len(pl.Blocks))
	for _, b := range pl.Blocks {
		pr, err := ParseBlock(b.CIDR)
		if err != nil {
			return nil, perr("%v", err)
		}
		var until *time.Time
		if b.ExpiresAt != nil {
			t := b.ExpiresAt.UTC()
			until = &t
		}
		blocks = append(blocks, entry{CIDR: key(pr), Until: until})
	}
	never := make([]string, 0, len(pl.NeverBlock))
	for _, s := range pl.NeverBlock {
		pr, err := ParsePrefix(s)
		if err != nil {
			return nil, perr("never_block: %v", err)
		}
		never = append(never, key(pr))
	}
	release := map[string]bool{}
	for _, s := range pl.Release {
		pr, err := ParsePrefix(s)
		if err != nil {
			return nil, perr("release: %v", err)
		}
		release[key(pr)] = true
	}
	ab := AutoBan{}
	if pl.AutoBan != nil {
		if err := validAutoBan(*pl.AutoBan); err != nil {
			return nil, err
		}
		ab = *pl.AutoBan
	}

	p.mu.Lock()
	defer p.mu.Unlock()
	p.st.Configured = true
	p.st.Blocks, p.st.NeverBlock, p.st.AutoBan = blocks, never, ab
	kept := p.st.Bans[:0]
	for _, b := range p.st.Bans {
		if !release[b.CIDR] {
			kept = append(kept, b)
		}
	}
	p.st.Bans = kept
	if err := p.saveLocked(); err != nil {
		return nil, err
	}
	return p.syncLocked(ctx, st.Stdout())
}

// Restore re-creates the table and the remaining timeouts after a start (the kernel forgot them at reboot).
func (p *Protection) Restore(ctx context.Context) {
	p.mu.Lock()
	defer p.mu.Unlock()
	if !p.st.Configured {
		return
	}
	if _, err := p.syncLocked(ctx, io.Discard); err != nil {
		p.d.Logger.Warn("protection restore failed", "err", err)
	}
}

func (p *Protection) nft(ctx context.Context, args ...string) (runner.Result, error) {
	res, err := p.d.Runner.Run(ctx, runner.Cmd{Name: "nft", Args: args})
	if err == nil && res.ExitCode != 0 {
		err = fmt.Errorf("nft %s: exit %d: %s", strings.Join(args[:min(len(args), 5)], " "), res.ExitCode, strings.TrimSpace(string(res.Stderr)))
	}
	return res, err
}

// list reads the protection table; ok=false when it is missing or incomplete (missing tells which).
func (p *Protection) list(ctx context.Context) (l Listing, ok, missing bool) {
	res, err := p.d.Runner.Run(ctx, runner.Cmd{Name: "nft", Args: []string{"-j", "list", "table", "inet", Table}})
	if err != nil {
		return Listing{}, false, false
	}
	if res.ExitCode != 0 {
		// "No such file or directory": the table is not there. Anything else (no permission, nft broken) is not a
		// reason to create anything.
		return Listing{}, false, strings.Contains(string(res.Stderr), "No such file or directory")
	}
	l, err = ParseListing(res.Stdout)
	if err != nil {
		return Listing{}, false, false
	}
	_, has4 := l.Sets[Set4]
	_, has6 := l.Sets[Set6]
	return l, has4 && has6 && l.HasChain, false
}

// ensureTableLocked creates the table when it is truly missing and returns the live listing. A table that exists but
// is incomplete is completed without deleting anything (its elements and counters stay): the definition only adds
// what is missing, and a table that has its chain but lacks a set is left alone (an error, someone edited it).
func (p *Protection) ensureTableLocked(ctx context.Context, out io.Writer) (Listing, error) {
	l, ok, missing := p.list(ctx)
	if ok {
		return l, nil
	}
	if !missing && l.HasChain {
		return Listing{}, fmt.Errorf("table inet %s exists but is incomplete; not touching it (delete it to let Falak recreate it)", Table)
	}
	if !missing && l.Sets == nil {
		return Listing{}, fmt.Errorf("table inet %s could not be listed", Table)
	}
	create := func(counters bool) error {
		if _, err := p.d.FS.WriteFile(DefPath, []byte(TableDefinition(counters)), 0o600); err != nil {
			return err
		}
		_, err := p.nft(ctx, "-f", p.d.FS.P(DefPath))
		return err
	}
	err := create(!p.st.NoSetCounters)
	if err != nil && !p.st.NoSetCounters {
		// nft or the kernel without per-element counters: sets without them (no hits per block).
		if err2 := create(false); err2 == nil {
			p.st.NoSetCounters = true
			_ = p.saveLocked()
			err = nil
		}
	}
	if err != nil {
		return Listing{}, fmt.Errorf("creating table inet %s: %w", Table, err)
	}
	fmt.Fprintf(out, "created table inet %s\n", Table)
	l, ok, _ = p.list(ctx)
	if !ok {
		return Listing{}, fmt.Errorf("table inet %s is missing after creating it", Table)
	}
	return l, nil
}

// guards are the prefixes nothing may overlap: the never_block list, the host's own addresses, its DNS servers and
// gateways, and the control plane's. Resolved control-plane addresses are cached for 10 minutes.
func (p *Protection) guardsLocked(ctx context.Context) []netip.Prefix {
	var g []netip.Prefix
	for _, s := range p.st.NeverBlock {
		if pr, err := netip.ParsePrefix(s); err == nil {
			g = append(g, pr)
		}
	}
	for _, a := range append(p.d.LocalAddrs(), p.d.Upstreams()...) {
		g = append(g, netip.PrefixFrom(a.Unmap().WithZone(""), a.Unmap().BitLen()))
	}
	if p.d.ControlPlane != nil && (p.cpAt.IsZero() || p.d.Now().Sub(p.cpAt) > 10*time.Minute) {
		rctx, cancel := context.WithTimeout(ctx, 5*time.Second)
		if addrs := p.d.ControlPlane(rctx); len(addrs) > 0 {
			p.cpAddrs, p.cpAt = addrs, p.d.Now()
		}
		cancel()
	}
	for _, a := range p.cpAddrs {
		g = append(g, netip.PrefixFrom(a.Unmap().WithZone(""), a.Unmap().BitLen()))
	}
	return g
}

// refusal says why a prefix may not be in a block set ("" = it may).
func refusal(pr netip.Prefix, guards []netip.Prefix) string {
	if pr.Bits() < minBits(pr) {
		return "too broad"
	}
	if g, ok := overlapsAny(pr, special); ok {
		return "overlaps the reserved range " + g.String()
	}
	if g, ok := overlapsAny(pr, guards); ok {
		return "overlaps a protected address (" + Canonical(g) + ")"
	}
	return ""
}

// desiredLocked is every unexpired block and ban: prefix → expiry (zero = permanent). Two entries for one prefix keep
// the later expiry.
func (p *Protection) desiredLocked(now time.Time) map[string]time.Time {
	want := map[string]time.Time{}
	add := func(e entry) {
		if e.Until != nil && !e.Until.After(now) {
			return
		}
		var until time.Time
		if e.Until != nil {
			until = *e.Until
		}
		if cur, ok := want[e.CIDR]; ok && (cur.IsZero() || (!until.IsZero() && cur.After(until))) {
			return
		}
		want[e.CIDR] = until
	}
	for _, e := range p.st.Blocks {
		add(e)
	}
	for _, e := range p.st.Bans {
		add(e)
	}
	return want
}

// syncLocked makes the live sets match the desired state, element by element.
func (p *Protection) syncLocked(ctx context.Context, out io.Writer) (ApplyResult, error) {
	res := ApplyResult{Failed: []Failure{}}
	now := p.d.Now()
	// Expired auto-bans are forgotten (the kernel dropped them already).
	kept := p.st.Bans[:0]
	for _, b := range p.st.Bans {
		if b.Until == nil || b.Until.After(now) {
			kept = append(kept, b)
		}
	}
	p.st.Bans = kept

	live, err := p.ensureTableLocked(ctx, out)
	if err != nil {
		return res, err
	}
	res.SetCounters = !p.st.NoSetCounters
	guards := p.guardsLocked(ctx)
	want := p.desiredLocked(now)
	current := map[string]Element{}
	for _, s := range []string{Set4, Set6} {
		for _, el := range live.Sets[s] {
			current[key(el.Prefix)] = el
		}
	}

	var del, add []elementSpec
	for k, el := range current {
		until, ok := want[k]
		if !ok {
			del = append(del, elementSpec{prefix: el.Prefix})
			continue
		}
		if why := refusal(el.Prefix, guards); why != "" {
			// Protected since it was added (a new server, a changed never_block list): it goes.
			del = append(del, elementSpec{prefix: el.Prefix})
			res.Failed = append(res.Failed, Failure{CIDR: Canonical(el.Prefix), Error: why})
			continue
		}
		if !sameExpiry(el.ExpiresS, until, now) {
			del = append(del, elementSpec{prefix: el.Prefix})
			add = append(add, elementSpec{prefix: el.Prefix, timeoutS: remaining(until, now)})
		}
	}
	for k, until := range want {
		if _, ok := current[k]; ok {
			continue
		}
		pr, err := netip.ParsePrefix(k)
		if err != nil {
			continue
		}
		if why := refusal(pr, guards); why != "" {
			res.Failed = append(res.Failed, Failure{CIDR: Canonical(pr), Error: why})
			continue
		}
		add = append(add, elementSpec{prefix: pr, timeoutS: remaining(until, now)})
	}
	sortSpecs(del)
	sortSpecs(add)
	// Re-added elements whose expiry moved are guarded like new ones.
	add = filterGuarded(add, guards, &res)

	res.Removed = p.elements(ctx, "delete", del, nil)
	res.Added = p.elements(ctx, "add", add, &res.Failed)
	res.Changed = res.Added > 0 || res.Removed > 0
	if res.Changed {
		fmt.Fprintf(out, "block sets: %d added, %d removed\n", res.Added, res.Removed)
	}
	sort.Slice(res.Failed, func(i, j int) bool { return res.Failed[i].CIDR < res.Failed[j].CIDR })
	return res, p.saveLocked()
}

func filterGuarded(add []elementSpec, guards []netip.Prefix, res *ApplyResult) []elementSpec {
	out := add[:0]
	seen := map[string]bool{}
	for _, f := range res.Failed {
		seen[f.CIDR] = true
	}
	for _, a := range add {
		if why := refusal(a.prefix, guards); why != "" {
			if !seen[Canonical(a.prefix)] {
				res.Failed = append(res.Failed, Failure{CIDR: Canonical(a.prefix), Error: why})
			}
			continue
		}
		out = append(out, a)
	}
	return out
}

func sortSpecs(s []elementSpec) {
	sort.Slice(s, func(i, j int) bool { return key(s[i].prefix) < key(s[j].prefix) })
}

// remaining is the timeout to set for an expiry (0 = permanent; at least 1 s).
func remaining(until, now time.Time) int64 {
	if until.IsZero() {
		return 0
	}
	return max(int64(until.Sub(now).Round(time.Second)/time.Second), 1)
}

func sameExpiry(expiresS int64, until, now time.Time) bool {
	if until.IsZero() {
		return expiresS < 0
	}
	if expiresS < 0 {
		return false
	}
	d := remaining(until, now) - expiresS
	return d >= -expirySlack && d <= expirySlack
}

// elements adds or deletes elements in batches per set; a refused batch is retried one element at a time so one
// element (an overlap, one that expired meanwhile) does not fail the others. Returns how many succeeded.
func (p *Protection) elements(ctx context.Context, op string, specs []elementSpec, failed *[]Failure) int {
	bySet := map[string][]elementSpec{}
	for _, s := range specs {
		bySet[setFor(s.prefix)] = append(bySet[setFor(s.prefix)], s)
	}
	done := 0
	for _, set := range []string{Set4, Set6} {
		items := bySet[set]
		for start := 0; start < len(items); start += batchSize {
			chunk := items[start:min(start+batchSize, len(items))]
			base := []string{op, "element", "inet", Table, set}
			if _, err := p.nft(ctx, append(base, elementArgs(chunk)...)...); err == nil {
				done += len(chunk)
				continue
			}
			for _, it := range chunk {
				_, err := p.nft(ctx, append(base, elementArgs([]elementSpec{it})...)...)
				switch {
				case err == nil:
					done++
				case failed != nil:
					*failed = append(*failed, Failure{CIDR: Canonical(it.prefix), Error: shortErr(err)})
				default:
					p.d.Logger.Debug("nft element", "op", op, "cidr", Canonical(it.prefix), "err", err)
				}
			}
		}
	}
	return done
}

func shortErr(err error) string {
	s := err.Error()
	if len(s) > 300 {
		s = s[:300]
	}
	return s
}

// interfaceAddrs are the host's addresses.
func interfaceAddrs() []netip.Addr {
	addrs, err := net.InterfaceAddrs()
	if err != nil {
		return nil
	}
	var out []netip.Addr
	for _, a := range addrs {
		if n, ok := a.(*net.IPNet); ok {
			if ip, ok := netip.AddrFromSlice(n.IP); ok {
				out = append(out, ip.Unmap())
			}
		}
	}
	return out
}

// ResolveHosts resolves the hosts of URLs (the control plane's API and OTLP endpoints) for the guard.
func ResolveHosts(urls ...string) func(ctx context.Context) []netip.Addr {
	return func(ctx context.Context) []netip.Addr {
		var out []netip.Addr
		seen := map[netip.Addr]bool{}
		for _, u := range urls {
			host := hostOf(u)
			if host == "" {
				continue
			}
			if a, err := netip.ParseAddr(host); err == nil {
				if !seen[a] {
					seen[a] = true
					out = append(out, a)
				}
				continue
			}
			addrs, err := net.DefaultResolver.LookupNetIP(ctx, "ip", host)
			if err != nil {
				continue
			}
			for _, a := range addrs {
				a = a.Unmap()
				if !seen[a] {
					seen[a] = true
					out = append(out, a)
				}
			}
		}
		return out
	}
}

func hostOf(u string) string {
	if i := strings.Index(u, "://"); i >= 0 {
		u = u[i+3:]
	}
	if i := strings.IndexAny(u, "/?#"); i >= 0 {
		u = u[:i]
	}
	if i := strings.LastIndex(u, "@"); i >= 0 {
		u = u[i+1:]
	}
	if h, _, err := net.SplitHostPort(u); err == nil {
		return h
	}
	return strings.Trim(u, "[]")
}
