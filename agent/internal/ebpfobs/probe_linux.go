//go:build linux && (amd64 || arm64)

package ebpfobs

import (
	"errors"
	"fmt"
	"io"
	"sort"
	"strings"
	"sync"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/cilium/ebpf"
	"github.com/cilium/ebpf/btf"
	"github.com/cilium/ebpf/link"
	"github.com/cilium/ebpf/rlimit"
	"golang.org/x/sys/unix"
)

// groups maps each program group to its programs (cgroup_skb programs attach to the cgroup v2 root, tp_btf ones to
// their tracepoint).
const platformSupported = true

var groups = map[string][]string{
	groupTCP:  {"on_set_state", "on_retransmit", "on_send_reset", "on_receive_reset"},
	groupDNS:  {"dns_egress", "dns_ingress"},
	groupRunq: {"on_wakeup", "on_wakeup_new", "on_switch"},
}

// probe is the loaded probe: its maps, and per group its programs and links.
type probe struct {
	mu    sync.Mutex
	maps  map[string]*ebpf.Map
	progs map[string][]*ebpf.Program
	links map[string][]link.Link
	stats io.Closer
}

// bpfWorks checks that the bpf() syscall is allowed here (EPERM in an unprivileged container or under seccomp).
func bpfWorks() error {
	_ = rlimit.RemoveMemlock()
	m, err := ebpf.NewMap(&ebpf.MapSpec{Type: ebpf.Array, KeySize: 4, ValueSize: 4, MaxEntries: 1})
	if err != nil {
		return err
	}
	return m.Close()
}

// openProbe loads the maps, then each group's programs alone and attaches them: a group that fails to load or attach
// is left out (reported), the others run. Fails only when nothing could be attached.
func openProbe(fs hostfs.FS) (source, []string, error) {
	_ = rlimit.RemoveMemlock()
	spec, err := loadProbe()
	if err != nil {
		return nil, nil, fmt.Errorf("probe object: %w", err)
	}
	kernel, err := btf.LoadSpec(fs.P("/sys/kernel/btf/vmlinux"))
	if err != nil {
		return nil, nil, fmt.Errorf("kernel BTF: %w", err)
	}
	p := &probe{maps: map[string]*ebpf.Map{}, progs: map[string][]*ebpf.Program{}, links: map[string][]link.Link{}}
	// Run-time statistics for the overhead budget (kernel 5.8+); without them the overhead is the agent's own only.
	if c, err := ebpf.EnableStats(uint32(unix.BPF_STATS_RUN_TIME)); err == nil {
		p.stats = c
	}
	for name, ms := range spec.Maps {
		m, err := ebpf.NewMap(ms)
		if err != nil {
			_ = p.Close()
			return nil, nil, fmt.Errorf("map %s: %w", name, err)
		}
		p.maps[name] = m
	}
	var problems []string
	names := make([]string, 0, len(groups))
	for g := range groups {
		names = append(names, g)
	}
	sort.Strings(names)
	for _, g := range names {
		if err := p.attach(fs, spec, kernel, g); err != nil {
			problems = append(problems, g+": "+shortErr(err))
		}
	}
	if len(p.links) == 0 {
		_ = p.Close()
		return nil, problems, errors.New(strings.Join(problems, "; "))
	}
	return p, problems, nil
}

// shortErr keeps a verifier rejection's first line (the log runs to thousands of lines).
func shortErr(err error) string {
	s := err.Error()
	if i := strings.IndexByte(s, '\n'); i >= 0 {
		s = s[:i]
	}
	if len(s) > 300 {
		s = s[:300]
	}
	return s
}

func (p *probe) attach(fs hostfs.FS, spec *ebpf.CollectionSpec, kernel *btf.Spec, group string) error {
	gs := spec.Copy()
	keep := map[string]bool{}
	for _, n := range groups[group] {
		keep[n] = true
	}
	for n := range gs.Programs {
		if !keep[n] {
			delete(gs.Programs, n)
		}
	}
	coll, err := ebpf.NewCollectionWithOptions(gs, ebpf.CollectionOptions{MapReplacements: p.maps,
		Programs: ebpf.ProgramOptions{KernelTypes: kernel}})
	if err != nil {
		return err
	}
	var progs []*ebpf.Program
	var links []link.Link
	fail := func(err error) error {
		for _, l := range links {
			_ = l.Close()
		}
		coll.Close()
		return err
	}
	for _, n := range groups[group] {
		prog := coll.Programs[n]
		if prog == nil {
			return fail(fmt.Errorf("program %s missing", n))
		}
		var l link.Link
		switch prog.Type() {
		case ebpf.CGroupSKB:
			at := ebpf.AttachCGroupInetEgress
			if gs.Programs[n].AttachType == ebpf.AttachCGroupInetIngress {
				at = ebpf.AttachCGroupInetIngress
			}
			l, err = link.AttachCgroup(link.CgroupOptions{Path: fs.P("/sys/fs/cgroup"), Attach: at, Program: prog})
		default:
			l, err = link.AttachTracing(link.TracingOptions{Program: prog})
		}
		if err != nil {
			return fail(fmt.Errorf("%s: %w", n, err))
		}
		links = append(links, l)
		progs = append(progs, prog)
	}
	// The collection holds clones of the shared maps: the programs keep their own references.
	for _, m := range coll.Maps {
		_ = m.Close()
	}
	p.mu.Lock()
	p.progs[group], p.links[group] = progs, links
	p.mu.Unlock()
	return nil
}

func (p *probe) Groups() []string {
	p.mu.Lock()
	defer p.mu.Unlock()
	out := make([]string, 0, len(p.links))
	for g := range p.links {
		out = append(out, g)
	}
	sort.Strings(out)
	return out
}

func (p *probe) Shed(group string) {
	p.mu.Lock()
	defer p.mu.Unlock()
	for _, l := range p.links[group] {
		_ = l.Close()
	}
	for _, prog := range p.progs[group] {
		_ = prog.Close()
	}
	delete(p.links, group)
	delete(p.progs, group)
}

func (p *probe) Close() error {
	for _, g := range p.Groups() {
		p.Shed(g)
	}
	p.mu.Lock()
	defer p.mu.Unlock()
	for _, m := range p.maps {
		_ = m.Close()
	}
	p.maps = map[string]*ebpf.Map{}
	if p.stats != nil {
		_ = p.stats.Close()
		p.stats = nil
	}
	return nil
}

func (p *probe) Runtime() map[string]time.Duration {
	p.mu.Lock()
	defer p.mu.Unlock()
	out := map[string]time.Duration{}
	for g, progs := range p.progs {
		for _, prog := range progs {
			if s, err := prog.Stats(); err == nil {
				out[g] += s.Runtime
			}
		}
	}
	return out
}

func (p *probe) m(name string) *ebpf.Map {
	p.mu.Lock()
	defer p.mu.Unlock()
	return p.maps[name]
}

// iterate reads every entry of a map (an LRU hash changing under the iteration may skip or repeat a few: counted
// again or next pass).
func iterate[K, V any](m *ebpf.Map, fn func(K, V)) error {
	if m == nil {
		return errors.New("map closed")
	}
	var k K
	var v V
	it := m.Iterate()
	for it.Next(&k, &v) {
		fn(k, v)
	}
	return it.Err()
}

func (p *probe) Peers(fn func(peerKey, peerVal)) error { return iterate(p.m("peers"), fn) }
func (p *probe) Hists(fn func(histKey, histVal)) error { return iterate(p.m("hists"), fn) }
func (p *probe) DNSNames(fn func(dnsKey, dnsVal)) error {
	return iterate(p.m("dns_names"), fn)
}

func (p *probe) ExpirePending(age time.Duration) ([]dnsPending, error) {
	m := p.m("dns_pending")
	var ts unix.Timespec
	if err := unix.ClockGettime(unix.CLOCK_MONOTONIC, &ts); err != nil {
		return nil, err
	}
	now := uint64(ts.Nano())
	var old []dnsPendingKey
	var out []dnsPending
	err := iterate(m, func(k dnsPendingKey, v dnsPending) {
		if v.TS != 0 && now > v.TS && time.Duration(now-v.TS) > age {
			old = append(old, k)
			out = append(out, v)
		}
	})
	for _, k := range old {
		_ = m.Delete(k)
	}
	return out, err
}
