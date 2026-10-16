package sandbox

import (
	"bufio"
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net"
	"net/http"
	"net/http/httptest"
	"net/netip"
	"net/url"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/docker"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

const sid = "01j9z8y7x6w5v4t3s2r1q0p9na"

type fakeDocker struct {
	mu      sync.Mutex
	pulled  []string
	created []string
	removed []string
	rmi     []string
	have    bool
	pullErr error             // the registry refuses an anonymous pull
	labels  map[string]string // the image's labels
	size    int64             // the image's size
	block   bool              // a pull runs until it is cancelled
}

func (f *fakeDocker) ImageRemove(_ context.Context, id string) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.rmi = append(f.rmi, id)
	return nil
}

func (f *fakeDocker) ImageConfigOf(context.Context, string) (docker.ImageConfig, bool, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	var c docker.ImageConfig
	c.ID = "sha256:" + strings.Repeat("ab", 32)
	c.Config.Env = []string{"PATH=/usr/bin:/bin", "LANG=C.UTF-8"}
	c.Config.Labels = f.labels
	c.Size = f.size
	return c, f.have, nil
}

func (f *fakeDocker) ImagePull(ctx context.Context, ref string, _ *docker.Auth, _ io.Writer) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.pulled = append(f.pulled, ref)
	if f.block {
		f.mu.Unlock()
		<-ctx.Done()
		f.mu.Lock()
		return ctx.Err()
	}
	if f.pullErr != nil {
		return f.pullErr
	}
	f.have = true
	return nil
}

func (f *fakeDocker) ContainerCreate(_ context.Context, name string, _ docker.CreateBody) (string, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.created = append(f.created, name)
	return "ctr-" + name, nil
}

func (f *fakeDocker) ContainerExport(context.Context, string) (io.ReadCloser, error) {
	return io.NopCloser(strings.NewReader("tar")), nil
}

func (f *fakeDocker) ContainerRemove(_ context.Context, id string) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.removed = append(f.removed, id)
	return nil
}

type fakeRequests struct {
	mu   sync.Mutex
	got  []map[string]string
	fail bool
}

func (f *fakeRequests) Request(_ context.Context, typ string, in, _ any) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.fail {
		return errors.New("control plane down")
	}
	m := in.(map[string]string)
	m["type"] = typ
	f.got = append(f.got, m)
	return nil
}

type env struct {
	svc  *Service
	run  *runnertest.Fake
	dock *fakeDocker
	reqs *fakeRequests
	root string
	now  time.Time

	mu       sync.Mutex
	statuses map[string]string // what `runsc list` reports
	listErr  bool              // `runsc list` fails
	free     uint64            // free bytes under Root (of 200 GiB)
	lookups  []string          // registries resolved
	resolve  map[string][]netip.Addr
}

func (e *env) setFree(b uint64) {
	e.mu.Lock()
	e.free = b
	e.mu.Unlock()
}

func newEnv(t *testing.T) *env {
	t.Helper()
	old := goos
	goos = "linux"
	t.Cleanup(func() { goos = old })
	root := t.TempDir()
	e := &env{run: &runnertest.Fake{}, dock: &fakeDocker{}, reqs: &fakeRequests{}, root: root, now: time.Unix(1760000000, 0), free: 100 << 30}
	e.run.On("runsc --version", runner.Result{Stdout: []byte("runsc version release-20261005.0\nspec: 1.1.0\n")})
	e.svc = New(Deps{Runner: e.run, FS: hostfs.FS{Root: root}, Docker: e.dock, Requests: e.reqs, Logger: slog.New(slog.NewTextHandler(io.Discard, nil)),
		Root: filepath.Join(root, "sandboxes"), RunscRoot: filepath.Join(root, "run"), CgroupRoot: filepath.Join(root, "cgroup"),
		Now: func() time.Time { return e.now },
		LookupHost: func(_ context.Context, host string) ([]netip.Addr, error) {
			e.mu.Lock()
			defer e.mu.Unlock()
			e.lookups = append(e.lookups, host)
			if a, ok := e.resolve[host]; ok {
				return a, nil
			}
			return nil, errors.New("no such host")
		},
		DiskSpace: func(string) (uint64, uint64, error) {
			e.mu.Lock()
			defer e.mu.Unlock()
			return e.free, 200 << 30, nil
		}})
	e.run.OnFunc("runsc --root="+e.svc.d.RunscRoot+" list", func(runnertest.Call) (runner.Result, error) {
		e.mu.Lock()
		defer e.mu.Unlock()
		if e.listErr {
			return runner.Result{ExitCode: 1}, nil
		}
		var rows []map[string]string
		for id, st := range e.statuses {
			rows = append(rows, map[string]string{"id": id, "status": st})
		}
		b, _ := json.Marshal(rows)
		return runner.Result{Stdout: b}, nil
	})
	return e
}

// runscList sets what `runsc list` reports (container id → status).
func (e *env) runscList(statuses map[string]string) {
	e.mu.Lock()
	e.statuses = statuses
	e.mu.Unlock()
}

func createPayload(network string) CreatePayload {
	return CreatePayload{ID: sid, Image: "ubuntu:24.04", CPUs: 0.5, MemoryMB: 512, DiskMB: 2048, Network: network,
		Env: map[string]string{"APP_ENV": "sandbox", "PATH": "/custom/bin"}, ExpiresAt: 1760003600}
}

func TestSpec(t *testing.T) {
	s := buildSpec(specInput{Rootfs: "/var/lib/falak/sandboxes/images/x/rootfs", Env: []string{"PATH=/a", "A=1", "PATH=/b"},
		Netns: "/var/run/netns/falak-sbx-x", Resolv: "/s/resolv.conf", Hosts: "/s/hosts", CPUs: 1.5, MemoryMB: 512, Pids: 256, Cgroup: "/falak-sbx/falak-sbx-x"})
	if s.Root.Readonly || s.Root.Path != "/var/lib/falak/sandboxes/images/x/rootfs" || s.Process.Terminal || s.Process.Cwd != "/root" || s.Hostname != "falak-sbx" {
		t.Fatalf("%+v", s)
	}
	if r := s.Linux.Resources; r.CPU.Quota != 150000 || r.CPU.Period != 100000 || r.Memory.Limit != 512<<20 || r.Memory.Swap != 512<<20 || r.Pids.Limit != 256 {
		t.Fatalf("resources %+v", r)
	}
	if s.Linux.CgroupsPath != "/falak-sbx/falak-sbx-x" {
		t.Fatal(s.Linux.CgroupsPath)
	}
	if strings.Join(s.Process.Env, ",") != "PATH=/b,A=1" {
		t.Fatalf("env %v", s.Process.Env)
	}
	mounts := map[string]ociMount{}
	for _, m := range s.Mounts {
		mounts[m.Destination] = m
	}
	if m := mounts["/etc/resolv.conf"]; m.Type != "bind" || m.Source != "/s/resolv.conf" || !slices.Contains(m.Options, "ro") {
		t.Fatalf("resolv mount %+v", m)
	}
	if m := mounts["/etc/hosts"]; m.Source != "/s/hosts" {
		t.Fatalf("hosts mount %+v", m)
	}
	var netns string
	for _, n := range s.Linux.Namespaces {
		if n.Type == "network" {
			netns = n.Path
		}
	}
	if netns != "/var/run/netns/falak-sbx-x" {
		t.Fatal(netns)
	}
	b, _ := json.Marshal(s)
	if !strings.Contains(string(b), `"readonly":false`) || !strings.Contains(string(b), `"terminal":false`) {
		t.Fatalf("%s", b)
	}
	if got := overlayFlag(10240); got != "--overlay2=root:self,size=10240m" {
		t.Fatal(got)
	}
	if resolvConf(NetInternet) != "nameserver 1.1.1.1\nnameserver 8.8.8.8\n" || strings.Contains(resolvConf(NetAllowlist), "nameserver") {
		t.Fatal("resolv.conf")
	}
}

func TestRunArgs(t *testing.T) {
	e := newEnv(t)
	dir := e.svc.dir(sid)
	got := e.svc.runArgs(sid, 2048, NetNone, "run")
	want := []string{"-c", `log=$1; shift; exec "$@" </dev/null >>"$log" 2>&1`, "sh", dir + "/runsc.log", "runsc", "--root=" + e.svc.d.RunscRoot,
		"--overlay2=root:self,size=2048m", "--network=none", "run", "--detach", "--bundle", dir + "/bundle", "falak-sbx-" + sid}
	if strings.Join(got, "|") != strings.Join(want, "|") {
		t.Fatalf("got  %q\nwant %q", got, want)
	}
	if slices.Contains(e.svc.runArgs(sid, 2048, NetInternet, "run"), "--network=none") {
		t.Fatal("internet sandbox without network")
	}
}

func TestRules(t *testing.T) {
	want := `*filter
:FALAK-SBX - [0:0]
-A FALAK-SBX -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT
-A FALAK-SBX -i br-sbx -d 10.88.0.1/32 -p tcp --dport 3128 -j ACCEPT
-A FALAK-SBX -i br-sbx -m addrtype --dst-type LOCAL -j DROP
-A FALAK-SBX -i br-sbx -o br-sbx -j DROP
-A FALAK-SBX -d 169.254.0.0/16 -j DROP
-A FALAK-SBX -d 10.0.0.0/8 -j DROP
-A FALAK-SBX -d 172.16.0.0/12 -j DROP
-A FALAK-SBX -d 192.168.0.0/16 -j DROP
-A FALAK-SBX -d 100.64.0.0/10 -j DROP
-A FALAK-SBX -d 0.0.0.0/8 -j DROP
-A FALAK-SBX -d 192.0.0.0/24 -j DROP
-A FALAK-SBX -d 198.18.0.0/15 -j DROP
-A FALAK-SBX -d 224.0.0.0/4 -j DROP
-A FALAK-SBX -i br-sbx -s 10.88.0.2/32 -j ACCEPT
-A FALAK-SBX -j DROP
COMMIT
*nat
:FALAK-SBX-NAT - [0:0]
-A FALAK-SBX-NAT -s 10.88.0.2/32 ! -o br-sbx -j MASQUERADE
COMMIT
*raw
:FALAK-SBX-PRE - [0:0]
-A FALAK-SBX-PRE -d 10.88.0.1/32 -p tcp --dport 3128 -j RETURN
-A FALAK-SBX-PRE -m addrtype --dst-type LOCAL -j DROP
-A FALAK-SBX-PRE -d 169.254.0.0/16 -j DROP
-A FALAK-SBX-PRE -d 10.0.0.0/8 -j DROP
-A FALAK-SBX-PRE -d 172.16.0.0/12 -j DROP
-A FALAK-SBX-PRE -d 192.168.0.0/16 -j DROP
-A FALAK-SBX-PRE -d 100.64.0.0/10 -j DROP
-A FALAK-SBX-PRE -d 0.0.0.0/8 -j DROP
-A FALAK-SBX-PRE -d 192.0.0.0/24 -j DROP
-A FALAK-SBX-PRE -d 198.18.0.0/15 -j DROP
-A FALAK-SBX-PRE -d 224.0.0.0/4 -j DROP
COMMIT
`
	if got := rules([]string{"10.88.0.2"}); got != want {
		t.Fatalf("got:\n%s\nwant:\n%s", got, want)
	}
	if none := rules(nil); strings.Contains(none, " -s ") || strings.Contains(none, "MASQUERADE") {
		t.Fatalf("nothing may go out without an internet sandbox:\n%s", none)
	}
}

// fakeIptables keeps chains (table/chain → rules) for iptables -S, -D and -I 1.
type fakeIptables struct {
	chains  map[string][]string
	inserts []string
}

func (f *fakeIptables) run(c runnertest.Call) (runner.Result, error) {
	key := c.Args[2] + "/" + c.Args[4]
	switch c.Args[3] {
	case "-S":
		out := "-P " + c.Args[4] + " ACCEPT\n"
		for _, r := range f.chains[key] {
			out += "-A " + c.Args[4] + " " + r + "\n"
		}
		return runner.Result{Stdout: []byte(out)}, nil
	case "-D":
		r := strings.Join(c.Args[5:], " ")
		if i := slices.Index(f.chains[key], r); i >= 0 {
			f.chains[key] = slices.Delete(f.chains[key], i, i+1)
			return runner.Result{}, nil
		}
		return runner.Result{ExitCode: 1}, nil
	case "-I":
		f.inserts = append(f.inserts, c.Line)
		f.chains[key] = append([]string{strings.Join(c.Args[6:], " ")}, f.chains[key]...)
	}
	return runner.Result{}, nil
}

func TestApplyRulesKeepsJumpsOnTop(t *testing.T) {
	e := newEnv(t)
	ctx := context.Background()
	// Docker's jumps are on top, with a stale copy of one of ours below them.
	f := &fakeIptables{chains: map[string][]string{"filter/FORWARD": {"-j DOCKER-USER", "-j DOCKER-FORWARD", "-i br-sbx -j FALAK-SBX"}}}
	e.run.OnFunc("iptables -w", f.run)
	if err := e.svc.applyRules(ctx, []string{"10.88.0.2"}); err != nil {
		t.Fatal(err)
	}
	want := map[string][]string{
		"filter/FORWARD":  {"-i br-sbx -j FALAK-SBX", "-o br-sbx -j FALAK-SBX", "-j DOCKER-USER", "-j DOCKER-FORWARD"},
		"filter/INPUT":    {"-i br-sbx -j FALAK-SBX"},
		"nat/POSTROUTING": {"-s 10.88.0.0/16 -j FALAK-SBX-NAT"},
		"raw/PREROUTING":  {"-i br-sbx -j FALAK-SBX-PRE"},
	}
	for k, w := range want {
		if strings.Join(f.chains[k], "|") != strings.Join(w, "|") {
			t.Fatalf("%s: %q", k, f.chains[k])
		}
	}
	// Re-applying changes nothing.
	f.inserts = nil
	if err := e.svc.applyRules(ctx, []string{"10.88.0.2"}); err != nil || len(f.inserts) != 0 {
		t.Fatal(err, f.inserts)
	}
	// Docker restarted and put its jump above ours: ours go back to the top, once.
	f.chains["filter/FORWARD"] = append([]string{"-j DOCKER-USER"}, f.chains["filter/FORWARD"]...)
	if err := e.svc.applyRules(ctx, []string{"10.88.0.2"}); err != nil {
		t.Fatal(err)
	}
	if got := strings.Join(f.chains["filter/FORWARD"], "|"); got != "-i br-sbx -j FALAK-SBX|-o br-sbx -j FALAK-SBX|-j DOCKER-USER|-j DOCKER-USER|-j DOCKER-FORWARD" {
		t.Fatal(got)
	}
	var restores int
	for _, c := range e.run.Calls() {
		if c.Name == "iptables-restore" {
			restores++
			if c.Line != "iptables-restore -w --noflush" || c.Stdin != rules([]string{"10.88.0.2"}) {
				t.Fatalf("%s:\n%s", c.Line, c.Stdin)
			}
		}
	}
	if restores != 3 {
		t.Fatal(restores)
	}
}

func TestAntiSpoof(t *testing.T) {
	want := `table bridge falak_sbx {}
delete table bridge falak_sbx
table bridge falak_sbx {
	chain prerouting {
		type filter hook prerouting priority -300; policy accept;
		iifname "sbx*" ether type != { ip, arp } drop
		iifname "sbxa" ether type ip ip saddr != 10.88.0.2 drop
		iifname "sbxa" ether type arp arp saddr ip != 10.88.0.2 drop
		iifname "sbxb" ether type ip ip saddr != 10.88.0.3 drop
		iifname "sbxb" ether type arp arp saddr ip != 10.88.0.3 drop
	}
}
`
	if got := antiSpoof(map[string]string{"sbxb": "10.88.0.3", "sbxa": "10.88.0.2"}); got != want {
		t.Fatalf("got:\n%s", got)
	}
}

func TestNetnsArgs(t *testing.T) {
	got := netnsArgs(netnsName(sid), vethName(sid), "10.88.0.7")
	if netnsName(sid) != "falak-sbx-t3s2r1q0p9na" || len(vethName(sid)) > 15 {
		t.Fatal(netnsName(sid), vethName(sid))
	}
	lines := make([]string, len(got))
	for i, a := range got {
		lines[i] = strings.Join(a, " ")
	}
	want := []string{
		"netns add falak-sbx-t3s2r1q0p9na",
		"link add sbxt3s2r1q0p9na type veth peer name eth0 netns falak-sbx-t3s2r1q0p9na",
		"link set sbxt3s2r1q0p9na master br-sbx",
		"link set dev sbxt3s2r1q0p9na type bridge_slave isolated on",
		"link set sbxt3s2r1q0p9na up",
		"-n falak-sbx-t3s2r1q0p9na addr add 10.88.0.7/16 dev eth0",
		"-n falak-sbx-t3s2r1q0p9na link set eth0 up",
		"-n falak-sbx-t3s2r1q0p9na link set lo up",
		"-n falak-sbx-t3s2r1q0p9na route add default via 10.88.0.1",
	}
	if strings.Join(lines, "\n") != strings.Join(want, "\n") {
		t.Fatalf("got:\n%s", strings.Join(lines, "\n"))
	}
}

func TestAllocator(t *testing.T) {
	path := filepath.Join(t.TempDir(), "addresses.json")
	a := newAllocator(path)
	ip1, err := a.Allocate("a")
	if err != nil || ip1 != "10.88.0.2" {
		t.Fatal(ip1, err)
	}
	ip2, _ := a.Allocate("b")
	if again, _ := a.Allocate("a"); ip2 != "10.88.0.3" || again != ip1 {
		t.Fatal(ip2, again)
	}
	// Persisted: a new allocator (agent restart) sees both.
	b := newAllocator(path)
	if ip, _ := b.Allocate("c"); ip != "10.88.0.4" {
		t.Fatal(ip)
	}
	if err := b.Release("a"); err != nil {
		t.Fatal(err)
	}
	if ip, _ := b.Allocate("d"); ip != "10.88.0.2" {
		t.Fatalf("released address not reused: %s", ip)
	}
	if err := b.Release("nobody"); err != nil {
		t.Fatal(err)
	}
	// Exhaustion.
	small := &allocator{path: filepath.Join(t.TempDir(), "x.json"), first: netip.MustParseAddr("10.88.0.2"), last: netip.MustParseAddr("10.88.0.3")}
	_, _ = small.Allocate("a")
	_, _ = small.Allocate("b")
	if _, err := small.Allocate("c"); err == nil || !strings.Contains(err.Error(), "no free sandbox address") {
		t.Fatal(err)
	}
}

func TestCreateKillAndRedelivery(t *testing.T) {
	e := newEnv(t)
	ctx := context.Background()
	e.runscList(map[string]string{})
	res, err := e.svc.Create(ctx, createPayload(NetInternet), nil)
	if err != nil {
		t.Fatal(err)
	}
	r := res.(CreateResult)
	if r.Status != "running" || r.IP != "10.88.0.2" || r.ImageDigest != "sha256:"+strings.Repeat("ab", 32) || r.StartedAt != "2025-10-09T08:53:20Z" {
		t.Fatalf("%+v", r)
	}
	if len(e.dock.pulled) != 1 || len(e.dock.created) != 1 || len(e.dock.removed) != 2 {
		t.Fatalf("docker %+v", e.dock)
	}
	var spec ociSpec
	b, _ := os.ReadFile(filepath.Join(e.svc.dir(sid), "bundle", "config.json"))
	if err := json.Unmarshal(b, &spec); err != nil {
		t.Fatal(err)
	}
	if !slices.Contains(spec.Process.Env, "PATH=/custom/bin") || !slices.Contains(spec.Process.Env, "APP_ENV=sandbox") || !slices.Contains(spec.Process.Env, "LANG=C.UTF-8") {
		t.Fatalf("env %v", spec.Process.Env)
	}
	if b, _ := os.ReadFile(filepath.Join(e.svc.dir(sid), "resolv.conf")); string(b) != resolvConf(NetInternet) {
		t.Fatalf("%s", b)
	}
	st, _ := os.ReadFile(filepath.Join(e.svc.dir(sid), "sandbox.json"))
	if strings.Contains(string(st), "APP_ENV") {
		t.Fatalf("env in sandbox.json: %s", st)
	}
	if !e.run.Ran("ip netns add falak-sbx-t3s2r1q0p9na") || !e.run.Ran("sh -c") || !e.run.Ran("tar -C") {
		t.Fatal(e.run.Lines())
	}
	var restore string
	for _, c := range e.run.Calls() {
		if c.Name == "iptables-restore" {
			restore = c.Stdin
		}
	}
	if !strings.Contains(restore, "-s 10.88.0.2/32 -j ACCEPT") {
		t.Fatal(restore)
	}

	// Redelivered while running: reported as is, nothing re-run.
	e.runscList(map[string]string{"falak-sbx-" + sid: "running"})
	e.run.Reset()
	again, err := e.svc.Create(ctx, createPayload(NetInternet), nil)
	if err != nil || again.(CreateResult) != r {
		t.Fatal(again, err)
	}
	if e.run.Ran("sh -c") {
		t.Fatal("re-ran runsc run")
	}

	// Extend moves the expiry only.
	res, err = e.svc.Extend(ctx, ExtendPayload{ID: sid, ExpiresAt: 1760005400}, nil)
	if err != nil || res.(ExtendResult).ExpiresAt != 1760005400 || e.run.Ran("sh -c") || e.run.Ran("runsc --root="+e.svc.d.RunscRoot+" delete") {
		t.Fatal("extend", res, err, e.run.Lines())
	}
	if _, err := e.svc.Extend(ctx, ExtendPayload{ID: "01j9z8y7x6w5v4t3s2r1q0p9nz", ExpiresAt: 1}, nil); err == nil || !strings.Contains(err.Error(), "not found") {
		t.Fatal(err)
	}
	if _, err := e.svc.Extend(ctx, ExtendPayload{ID: sid}, nil); err == nil {
		t.Fatal("expires_at 0 accepted")
	}

	// Inventory.
	inv, err := e.svc.Inventory(ctx, InventoryPayload{}, nil)
	if err != nil {
		t.Fatal(err)
	}
	if it := inv.(InventoryResult).Sandboxes; len(it) != 1 || it[0].Status != "running" || it[0].ExpiresAt != 1760005400 {
		t.Fatalf("%+v", it)
	}

	// A redelivered create while runsc can't be asked is refused: the sandbox is never remade.
	e.listErr = true
	e.run.Reset()
	if _, err := e.svc.Create(ctx, createPayload(NetInternet), nil); err == nil || e.run.Ran("runsc --root="+e.svc.d.RunscRoot+" delete") {
		t.Fatal("recreated on an unknown status", err)
	}
	if _, err := os.Stat(filepath.Join(e.svc.dir(sid), "sandbox.json")); err != nil {
		t.Fatal(err)
	}
	e.listErr = false

	// Kill: everything goes, twice is fine.
	e.run.Reset()
	for range 2 {
		if _, err := e.svc.Kill(ctx, KillPayload{ID: sid}, nil); err != nil {
			t.Fatal(err)
		}
	}
	if _, err := os.Stat(e.svc.dir(sid)); !os.IsNotExist(err) {
		t.Fatal("state dir left", err)
	}
	if !e.run.Ran("runsc --root="+e.svc.d.RunscRoot+" delete -force falak-sbx-"+sid) || !e.run.Ran("ip netns del falak-sbx-t3s2r1q0p9na") {
		t.Fatal(e.run.Lines())
	}
	if ip, _ := e.svc.alloc.Allocate("other"); ip != "10.88.0.2" {
		t.Fatal("address not released", ip)
	}
}

func TestCreateFailsCleanly(t *testing.T) {
	e := newEnv(t)
	e.runscList(map[string]string{})
	e.run.On("sh -c", runner.Result{ExitCode: 1})
	p := createPayload(NetNone)
	if _, err := e.svc.Create(context.Background(), p, nil); err == nil || !strings.Contains(err.Error(), "runsc run") {
		t.Fatal(err)
	}
	if _, err := os.Stat(e.svc.dir(sid)); !os.IsNotExist(err) {
		t.Fatal("state dir left after a failed create")
	}
	if e.run.Ran("ip netns add") {
		t.Fatal("network none got a namespace")
	}
}

func TestCreateValidation(t *testing.T) {
	e := newEnv(t)
	for _, mod := range []func(*CreatePayload){
		func(p *CreatePayload) { p.ID = "BAD" },
		func(p *CreatePayload) { p.Image = "-rm" },
		func(p *CreatePayload) { p.Image = "../../containers/x/json" },
		func(p *CreatePayload) { p.Image = "alpine/../../x" },
		func(p *CreatePayload) { p.Image = "alpine?x=1" },
		func(p *CreatePayload) { p.Image = "localhost:5000/app" },
		func(p *CreatePayload) { p.Image = "127.0.0.1:5000/app" },
		func(p *CreatePayload) { p.Image = "10.0.0.5/app:1" },
		func(p *CreatePayload) { p.Image = "169.254.169.254/app" },
		func(p *CreatePayload) { p.CPUs = 0 },
		func(p *CreatePayload) { p.MemoryMB = 1 },
		func(p *CreatePayload) { p.DiskMB = 1 },
		func(p *CreatePayload) { p.Network = "host" },
		func(p *CreatePayload) { p.AllowHosts = []string{"a b"} },
		func(p *CreatePayload) { p.Env = map[string]string{"A=B": "x"} },
		func(p *CreatePayload) { p.Repo = &Repo{URL: "file:///etc"} },
		func(p *CreatePayload) { p.Repo = &Repo{URL: "https://github.com/a/b", Ref: "--upload-pack=x"} },
	} {
		p := createPayload(NetInternet)
		mod(&p)
		if _, err := e.svc.Create(context.Background(), p, nil); err == nil {
			t.Errorf("%+v accepted", p)
		}
	}
}

func TestCloneArgsKeepTheTokenOutOfTheRepo(t *testing.T) {
	argv, env := cloneArgs(Repo{URL: "https://github.com/acme/shop.git", Token: "tok"})
	want := []string{"git", "-c", "credential.helper=", "-c", "credential.https://github.com.helper=" + gitCredential, "clone", "--", "https://github.com/acme/shop.git", "/workspace/shop"}
	if strings.Join(argv, "|") != strings.Join(want, "|") || strings.Contains(strings.Join(argv, " "), "tok ") {
		t.Fatalf("%q", argv)
	}
	if !slices.Contains(env, "FALAK_GIT_TOKEN=tok") {
		t.Fatal(env)
	}
	argv, env = cloneArgs(Repo{URL: "git@github.com:acme/shop.git"})
	if argv[len(argv)-1] != "/workspace/shop" || len(env) != 1 {
		t.Fatal(argv, env)
	}
	if (CreatePayload{Repo: &Repo{Token: "tok"}, Env: map[string]string{"K": "v"}}).Secrets() == nil {
		t.Fatal("secrets")
	}
}

func TestCloneWithoutGit(t *testing.T) {
	e := newEnv(t)
	e.runscList(map[string]string{})
	e.run.OnFunc("runsc --root="+e.svc.d.RunscRoot+" exec", func(c runnertest.Call) (runner.Result, error) {
		if slices.Contains(procOf(c).Args, "command -v git") {
			return runner.Result{ExitCode: 1}, nil
		}
		return runner.Result{}, nil
	})
	p := createPayload(NetNone)
	p.Repo = &Repo{URL: "https://github.com/acme/shop.git"}
	if _, err := e.svc.Create(context.Background(), p, nil); err == nil || !strings.Contains(err.Error(), "the image has no git") {
		t.Fatal(err)
	}
	if _, err := os.Stat(e.svc.dir(sid)); !os.IsNotExist(err) {
		t.Fatal("state dir left")
	}
}

// procOf reads the process file of a `runsc exec --process=<file>` call (during the call: it is removed after).
func procOf(c runnertest.Call) ociProcess {
	var p ociProcess
	for _, a := range c.Args {
		if f, ok := strings.CutPrefix(a, "--process="); ok {
			b, _ := os.ReadFile(f)
			_ = json.Unmarshal(b, &p)
		}
	}
	return p
}

// writeBundle gives sid a state directory with a bundle (exec reads the sandbox's env from it).
func (e *env) writeBundle(t *testing.T) {
	t.Helper()
	dir := e.svc.dir(sid)
	if err := os.MkdirAll(filepath.Join(dir, "bundle"), 0o700); err != nil {
		t.Fatal(err)
	}
	b, _ := json.Marshal(buildSpec(specInput{Env: []string{"PATH=/bin", "A=1"}}))
	if err := os.WriteFile(filepath.Join(dir, "bundle", "config.json"), b, 0o600); err != nil {
		t.Fatal(err)
	}
}

func TestExec(t *testing.T) {
	e := newEnv(t)
	e.writeBundle(t)
	var call runnertest.Call
	var proc ociProcess
	var procMode os.FileMode
	e.run.OnFunc("runsc", func(c runnertest.Call) (runner.Result, error) {
		call, proc = c, procOf(c)
		if fi, err := os.Stat(strings.TrimPrefix(c.Args[2], "--process=")); err == nil {
			procMode = fi.Mode().Perm()
		}
		return runner.Result{ExitCode: 3, Stdout: []byte(strings.Repeat("o", 10)), Stderr: []byte("err")}, nil
	})
	res, err := e.svc.Exec(context.Background(), ExecPayload{ID: sid, Cmd: []string{"sh", "-lc", "echo hi"}, Cwd: "/work", Env: map[string]string{"A": "2", "B": "x"},
		Stdin: base64.StdEncoding.EncodeToString([]byte("input")), TimeoutS: 5, MaxOutput: 4}, nil)
	if err != nil {
		t.Fatal(err)
	}
	r := res.(ExecResult)
	out, _ := base64.StdEncoding.DecodeString(r.Stdout)
	errb, _ := base64.StdEncoding.DecodeString(r.Stderr)
	if r.ExitCode != 3 || string(out) != "oooo" || string(errb) != "err" || !r.Truncated || r.TimedOut {
		t.Fatalf("%+v", r)
	}
	base := filepath.Join(e.svc.dir(sid), "exec-")
	a := call.Args
	if len(a) != 5 || a[0] != "--root="+e.svc.d.RunscRoot || a[1] != "exec" || !strings.HasPrefix(a[2], "--process="+base) ||
		!strings.HasPrefix(a[3], "--internal-pid-file="+base) || a[4] != "falak-sbx-"+sid || call.Stdin != "input" {
		t.Fatalf("%q stdin=%q", a, call.Stdin)
	}
	// The sandbox's env with the exec's on top (A overridden, not duplicated); no capabilities: runsc uses the sandbox's.
	if strings.Join(proc.Args, "|") != "sh|-lc|echo hi" || strings.Join(proc.Env, ",") != "PATH=/bin,A=2,B=x" || proc.Cwd != "/work" ||
		proc.Capabilities != nil || proc.Terminal || procMode != 0o600 {
		t.Fatalf("%+v mode %v", proc, procMode)
	}
	if m, _ := filepath.Glob(base + "*"); len(m) != 0 {
		t.Fatal("exec files left", m)
	}

	for _, p := range []ExecPayload{
		{ID: sid, TimeoutS: 5},
		{ID: sid, Cmd: []string{"true"}, TimeoutS: 0},
		{ID: sid, Cmd: []string{"true"}, TimeoutS: 121},
		{ID: sid, Cmd: []string{"true"}, TimeoutS: 5, Cwd: "rel"},
		{ID: sid, Cmd: []string{"true"}, TimeoutS: 5, Stdin: "%%%"},
		{ID: sid, Cmd: []string{"a\x00b"}, TimeoutS: 5},
	} {
		if _, err := e.svc.Exec(context.Background(), p, nil); err == nil {
			t.Errorf("%+v accepted", p)
		}
	}
	if _, err := e.svc.Exec(context.Background(), ExecPayload{ID: "01j9z8y7x6w5v4t3s2r1q0p9nz", Cmd: []string{"true"}, TimeoutS: 5}, nil); err == nil ||
		!strings.Contains(err.Error(), "not found") {
		t.Fatal(err)
	}
}

func TestExecTimeoutKillsTheProcess(t *testing.T) {
	e := newEnv(t)
	e.writeBundle(t)
	e.run.OnFunc("runsc --root="+e.svc.d.RunscRoot+" exec", func(c runnertest.Call) (runner.Result, error) {
		for _, a := range c.Args {
			if f, ok := strings.CutPrefix(a, "--internal-pid-file="); ok {
				_ = os.WriteFile(f, []byte("42\n"), 0o600)
			}
		}
		time.Sleep(150 * time.Millisecond)
		return runner.Result{ExitCode: -1, Stdout: []byte("partial")}, context.DeadlineExceeded
	})
	o, err := e.svc.execIn(context.Background(), sid, execReq{argv: []string{"sleep", "100"}, timeout: 50 * time.Millisecond, max: 100})
	if err != nil {
		t.Fatal(err)
	}
	if !o.timedOut || o.code != -1 || string(o.stdout) != "partial" {
		t.Fatalf("%+v", o)
	}
	if !e.run.Ran("runsc --root=" + e.svc.d.RunscRoot + " kill --pgid=42 falak-sbx-" + sid + " 9") {
		t.Fatal(e.run.Lines())
	}
	if m, _ := filepath.Glob(filepath.Join(e.svc.dir(sid), "exec-*.pid")); len(m) != 0 {
		t.Fatal("pid file left", m)
	}
}

// A timeout that comes before runsc exec wrote its pid file still kills the process group: the kill is retried while
// runsc exec runs, and once more after it returned.
func TestExecTimeoutKillsAProcessThatStartedLate(t *testing.T) {
	old := killRetry
	killRetry = 10 * time.Millisecond
	t.Cleanup(func() { killRetry = old })
	for name, writeAt := range map[string]time.Duration{"while running": 100 * time.Millisecond, "on return": 150 * time.Millisecond} {
		e := newEnv(t)
		e.writeBundle(t)
		e.run.OnFunc("runsc --root="+e.svc.d.RunscRoot+" exec", func(c runnertest.Call) (runner.Result, error) {
			time.Sleep(writeAt)
			for _, a := range c.Args {
				if f, ok := strings.CutPrefix(a, "--internal-pid-file="); ok {
					_ = os.WriteFile(f, []byte("42\n"), 0o600)
				}
			}
			time.Sleep(150*time.Millisecond - writeAt)
			return runner.Result{ExitCode: -1}, context.DeadlineExceeded
		})
		o, err := e.svc.execIn(context.Background(), sid, execReq{argv: []string{"sleep", "100"}, timeout: 50 * time.Millisecond, max: 100})
		if err != nil || !o.timedOut {
			t.Fatal(name, o, err)
		}
		if !e.run.Ran("runsc --root=" + e.svc.d.RunscRoot + " kill --pgid=42 falak-sbx-" + sid + " 9") {
			t.Fatal(name, e.run.Lines())
		}
	}
}

func TestFiles(t *testing.T) {
	e := newEnv(t)
	e.writeBundle(t)
	ctx := context.Background()
	for _, p := range []string{"rel/path", "", "/a\x00b", "/" + strings.Repeat("a", 5000)} {
		if checkPath(p) == nil {
			t.Errorf("%q accepted", p)
		}
	}
	if _, err := e.svc.ReadFile(ctx, ReadPayload{ID: sid, Path: "etc/passwd", MaxBytes: 10}, nil); err == nil {
		t.Fatal("relative path accepted")
	}
	if _, err := e.svc.WriteFile(ctx, WritePayload{ID: sid, Path: "/a", Content: "", Mode: "999"}, nil); err == nil {
		t.Fatal("bad mode accepted")
	}

	code := 0
	var out string
	var calls []runnertest.Call
	e.run.OnFunc("runsc", func(c runnertest.Call) (runner.Result, error) {
		c.Args = procOf(c).Args // the argv run in the sandbox
		calls = append(calls, c)
		return runner.Result{ExitCode: code, Stdout: []byte(out)}, nil
	})
	out = "11 644\nhello"
	res, err := e.svc.ReadFile(ctx, ReadPayload{ID: sid, Path: "/root/a.txt", MaxBytes: 5}, nil)
	if err != nil {
		t.Fatal(err)
	}
	if r := res.(ReadResult); r.Content != base64.StdEncoding.EncodeToString([]byte("hello")) || r.Size != 11 || !r.Truncated || r.Mode != "0644" {
		t.Fatalf("%+v", r)
	}
	if a := calls[0].Args; strings.Join(a[len(a)-5:], "|") != "-c|"+readScript+"|_|/root/a.txt|5" {
		t.Fatalf("%q", a)
	}
	for c, want := range map[int]error{44: ErrNotFound, 45: ErrIsDirectory} {
		code = c
		if _, err := e.svc.ReadFile(ctx, ReadPayload{ID: sid, Path: "/x", MaxBytes: 5}, nil); !errors.Is(err, want) || err.Error() != want.Error() {
			t.Fatalf("exit %d: %v", c, err)
		}
	}
	code = 46
	if _, err := e.svc.ListFiles(ctx, ListPayload{ID: sid, Path: "/etc/hosts"}, nil); !errors.Is(err, ErrNotDirectory) {
		t.Fatal(err)
	}

	code, calls = 0, nil
	if _, err := e.svc.WriteFile(ctx, WritePayload{ID: sid, Path: "/w/run.sh", Content: base64.StdEncoding.EncodeToString([]byte("#!/bin/sh\n")), Mode: "0755"}, nil); err != nil {
		t.Fatal(err)
	}
	if a := calls[0].Args; strings.Join(a[len(a)-5:], "|") != "-c|"+writeMkdirScript+"|_|/w/run.sh|0755" || calls[0].Stdin != "#!/bin/sh\n" {
		t.Fatalf("%q", a)
	}
	no := false
	calls = nil
	if _, err := e.svc.WriteFile(ctx, WritePayload{ID: sid, Path: "/w/x", Content: "", Mkdirs: &no}, nil); err != nil {
		t.Fatal(err)
	}
	if a := calls[0].Args; a[len(a)-4] != writeScript || a[len(a)-1] != "0644" {
		t.Fatalf("%q", a)
	}
	calls = nil
	if _, err := e.svc.DeleteFile(ctx, DeletePayload{ID: sid, Path: "/w", Recursive: true}, nil); err != nil {
		t.Fatal(err)
	}
	if a := calls[0].Args; !strings.Contains(a[len(a)-3], `rm -rf -- "$1"`) || a[len(a)-1] != "/w" {
		t.Fatalf("%q", a)
	}
}

func TestParseList(t *testing.T) {
	out := "directory/4096/755/1760000000/src\nregular file/12/644/1760000001/README.md\nsymbolic link/7/777/1/lnk\n" +
		"regular empty file/0/600/2/.env\nfifo/0/644/3/p\n\nbroken line\n"
	r := parseList([]byte(out))
	want := []Entry{
		{Name: "src", Type: "dir", Size: 4096, Mode: "0755", MTime: 1760000000},
		{Name: "README.md", Type: "file", Size: 12, Mode: "0644", MTime: 1760000001},
		{Name: "lnk", Type: "link", Size: 7, Mode: "0777", MTime: 1},
		{Name: ".env", Type: "file", Size: 0, Mode: "0600", MTime: 2},
		{Name: "p", Type: "other", Size: 0, Mode: "0644", MTime: 3},
	}
	if !slices.Equal(r.Entries, want) || r.Truncated {
		t.Fatalf("%+v", r)
	}
	var many strings.Builder
	for i := range 1001 {
		fmt.Fprintf(&many, "regular file/1/644/1/f%d\n", i)
	}
	if r := parseList([]byte(many.String())); len(r.Entries) != 1000 || !r.Truncated {
		t.Fatal(len(r.Entries), r.Truncated)
	}
}

func TestReapReason(t *testing.T) {
	for _, c := range []struct {
		expires, now int64
		status       string
		known, oom   bool
		want         string
	}{
		{100, 100, "running", true, false, ReasonExpired},
		{100, 200, "", false, false, ReasonExpired},
		{300, 200, "running", true, false, ""},
		{300, 200, "paused", true, false, ""},
		{300, 200, "", false, false, ""}, // runsc couldn't be asked: never removed
		{300, 200, "stopped", true, true, ReasonOOM},
		{300, 200, "stopped", true, false, ReasonExited},
		{300, 200, "", true, false, ReasonExited}, // gone after a reboot
	} {
		if got := reapReason(c.expires, c.now, c.status, c.known, c.oom); got != c.want {
			t.Errorf("%+v: got %q", c, got)
		}
	}
}

func TestReaper(t *testing.T) {
	e := newEnv(t)
	ctx := context.Background()
	e.runscList(map[string]string{})
	if _, err := e.svc.Create(ctx, createPayload(NetNone), nil); err != nil {
		t.Fatal(err)
	}
	// Running and not expired: kept.
	e.runscList(map[string]string{"falak-sbx-" + sid: "running"})
	e.run.Reset()
	e.svc.ReapOnce(ctx)
	if e.run.Ran("runsc --root="+e.svc.d.RunscRoot+" delete") || len(e.reqs.got) != 0 {
		t.Fatal("a running sandbox was reaped")
	}
	// Stopped after an OOM kill, control plane down: torn down, kept as stopped until reported.
	e.runscList(map[string]string{"falak-sbx-" + sid: "stopped"})
	cg := filepath.Join(e.svc.d.CgroupRoot, cgroupPath(sid))
	_ = os.MkdirAll(cg, 0o755)
	_ = os.WriteFile(filepath.Join(cg, "memory.events"), []byte("low 0\nhigh 0\nmax 3\noom 1\noom_kill 1\n"), 0o644)
	e.reqs.fail = true
	e.svc.ReapOnce(ctx)
	if !e.run.Ran("runsc --root=" + e.svc.d.RunscRoot + " delete -force") {
		t.Fatal(e.run.Lines())
	}
	inv, _ := e.svc.Inventory(ctx, InventoryPayload{}, nil)
	if it := inv.(InventoryResult).Sandboxes; len(it) != 1 || it[0].Status != "stopped" || !it[0].OOMKilled {
		t.Fatalf("%+v", it)
	}
	// Control plane back: reported once, state removed.
	e.reqs.fail = false
	e.run.Reset()
	e.svc.ReapOnce(ctx)
	if len(e.reqs.got) != 1 || e.reqs.got[0]["reason"] != "oom" || e.reqs.got[0]["id"] != sid || e.reqs.got[0]["type"] != "sandbox.reaped" {
		t.Fatalf("%+v", e.reqs.got)
	}
	if e.run.Ran("runsc --root=" + e.svc.d.RunscRoot + " delete") {
		t.Fatal("torn down twice")
	}
	if _, err := os.Stat(e.svc.dir(sid)); !os.IsNotExist(err) {
		t.Fatal("state dir left")
	}

	// Expired while running.
	e.runscList(map[string]string{})
	if _, err := e.svc.Create(ctx, createPayload(NetNone), nil); err != nil {
		t.Fatal(err)
	}
	e.now = time.Unix(1760003600, 0)
	e.svc.ReapOnce(ctx)
	if len(e.reqs.got) != 2 || e.reqs.got[1]["reason"] != "expired" {
		t.Fatalf("%+v", e.reqs.got)
	}
}

func TestHostAllowed(t *testing.T) {
	list := []string{"github.com", "*.githubusercontent.com", "PyPI.org."}
	for host, want := range map[string]bool{
		"github.com": true, "GitHub.com.": true, "api.github.com": false, "raw.githubusercontent.com": true,
		"a.b.githubusercontent.com": true, "githubusercontent.com": false, "evilgithubusercontent.com": false,
		"pypi.org": true, "example.com": false, "": false,
	} {
		if got := hostAllowed(list, host); got != want {
			t.Errorf("%q: got %v", host, got)
		}
	}
	for addr, want := range map[string]bool{"169.254.169.254": false, "10.1.2.3": false, "172.17.0.1": false, "192.168.1.1": false,
		"100.64.0.1": false, "127.0.0.1": false, "::1": false, "fe80::1": false, "8.8.8.8": true, "2606:4700::1111": true} {
		if got := publicAddr(netip.MustParseAddr(addr)); got != want {
			t.Errorf("%s: got %v", addr, got)
		}
	}
	if err := refusePrivate("tcp", "169.254.169.254:80", nil); err == nil {
		t.Fatal("metadata dial allowed")
	}
}

func TestProxy(t *testing.T) {
	// An echo server stands in for an allowlisted host (the test dials loopback, which the real dialer refuses).
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer ln.Close()
	go func() {
		for {
			c, err := ln.Accept()
			if err != nil {
				return
			}
			go func() { defer c.Close(); _, _ = io.Copy(c, c) }()
		}
	}()
	var webHost string
	web := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Host != webHost {
			_, _ = w.Write([]byte("fronted to " + r.Host))
			return
		}
		_, _ = w.Write([]byte("plain ok"))
	}))
	webHost = strings.TrimPrefix(web.URL, "http://")
	defer web.Close()

	p := newProxy()
	var d net.Dialer
	p.dial = d.DialContext
	p.set("127.0.0.1", []string{"localhost", "127.0.0.1"})
	srv := httptest.NewServer(p)
	defer srv.Close()

	connect := func(target string) (*bufio.Reader, net.Conn, string) {
		c, err := net.Dial("tcp", srv.Listener.Addr().String())
		if err != nil {
			t.Fatal(err)
		}
		fmt.Fprintf(c, "CONNECT %s HTTP/1.1\r\nHost: %s\r\n\r\n", target, target)
		br := bufio.NewReader(c)
		status, _ := br.ReadString('\n')
		return br, c, status
	}
	_, port, _ := net.SplitHostPort(ln.Addr().String())
	br, c, status := connect("localhost:" + port)
	if !strings.Contains(status, "200") {
		t.Fatal(status)
	}
	for line, _ := br.ReadString('\n'); line != "\r\n"; line, _ = br.ReadString('\n') {
	}
	fmt.Fprint(c, "ping\n")
	if got, _ := br.ReadString('\n'); got != "ping\n" {
		t.Fatalf("echo %q", got)
	}
	c.Close()

	_, c, status = connect("example.com:443")
	c.Close()
	if !strings.Contains(status, "403") {
		t.Fatal(status)
	}

	pu, _ := url.Parse(srv.URL)
	hc := &http.Client{Transport: &http.Transport{Proxy: http.ProxyURL(pu)}}
	resp, err := hc.Get(web.URL)
	if err != nil {
		t.Fatal(err)
	}
	body, _ := io.ReadAll(resp.Body)
	resp.Body.Close()
	if resp.StatusCode != 200 || string(body) != "plain ok" {
		t.Fatal(resp.StatusCode, string(body))
	}
	resp, err = hc.Get("http://example.com/")
	if err != nil {
		t.Fatal(err)
	}
	resp.Body.Close()
	if resp.StatusCode != http.StatusForbidden {
		t.Fatal(resp.StatusCode)
	}
	// An allowlisted URL with another Host header: the upstream still gets the URL's host.
	raw, err := net.Dial("tcp", srv.Listener.Addr().String())
	if err != nil {
		t.Fatal(err)
	}
	fmt.Fprintf(raw, "GET %s/ HTTP/1.1\r\nHost: other.example\r\n\r\n", web.URL)
	rr, err := http.ReadResponse(bufio.NewReader(raw), nil)
	if err != nil {
		t.Fatal(err)
	}
	body, _ = io.ReadAll(rr.Body)
	raw.Close()
	if string(body) != "plain ok" {
		t.Fatal(string(body))
	}
	// Another sandbox (source address) has its own list.
	p.del("127.0.0.1")
	if p.allowed("127.0.0.1", "localhost") {
		t.Fatal("deleted allowlist still allows")
	}
}

func TestPrepareOnlyOnLinux(t *testing.T) {
	e := newEnv(t)
	goos = "darwin"
	if _, err := e.svc.Prepare(context.Background(), PreparePayload{}, nil); err == nil || !strings.Contains(err.Error(), "Linux") {
		t.Fatal(err)
	}
	goos = "linux"
	res, err := e.svc.Prepare(context.Background(), PreparePayload{}, nil)
	if err != nil || res.(PrepareResult) != (PrepareResult{RunscVersion: "release-20261005.0", Platform: "systrap"}) {
		t.Fatal(res, err)
	}
	if e.run.Ran("apt-get") {
		t.Fatal("installed runsc although it was there")
	}
}

func TestPrepareInstallsRunsc(t *testing.T) {
	e := newEnv(t)
	installed := false
	e.run = &runnertest.Fake{}
	e.svc.d.Runner = e.run
	e.run.OnFunc("runsc --version", func(runnertest.Call) (runner.Result, error) {
		if !installed {
			return runner.Result{ExitCode: -1}, errors.New(`exec: "runsc": executable file not found in $PATH`)
		}
		return runner.Result{Stdout: []byte("runsc version release-20261005.0\n")}, nil
	})
	e.run.OnFunc("apt-get install", func(runnertest.Call) (runner.Result, error) {
		installed = true
		return runner.Result{}, nil
	})
	e.run.On("dpkg-query", runner.Result{ExitCode: 1})
	key := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) { _, _ = w.Write([]byte("KEY")) }))
	defer key.Close()
	e.svc.d.GPGKeyURL, e.svc.d.HTTP = key.URL, key.Client()
	if _, err := e.svc.Prepare(context.Background(), PreparePayload{}, nil); err != nil {
		t.Fatal(err, e.run.Lines())
	}
	list, _ := e.svc.d.FS.ReadFile(gvisorList)
	if string(list) != "# Managed by Falak\ndeb [signed-by=/etc/apt/keyrings/gvisor.asc] https://storage.googleapis.com/gvisor/releases release main\n" {
		t.Fatalf("%s", list)
	}
	if k, _ := e.svc.d.FS.ReadFile(gvisorKeyring); string(k) != "KEY" {
		t.Fatalf("%s", k)
	}
}

func TestImageChecks(t *testing.T) {
	for _, ok := range []string{"alpine", "alpine:3.20", "node:22-alpine", "alpine/git:latest", "ghcr.io/acme/tool:v1.2", "registry.example.com:5000/a/b",
		"ubuntu@sha256:" + strings.Repeat("a", 64), "my_repo/x__y-z"} {
		if err := checkImage(ok); err != nil {
			t.Errorf("%s: %v", ok, err)
		}
	}
	ctx := context.Background()
	for name, mod := range map[string]func(*fakeDocker){
		"built here": func(f *fakeDocker) { f.pullErr = errors.New("pull access denied") },
		"falak":      func(f *fakeDocker) { f.labels = map[string]string{"falak.site": "shop"} },
	} {
		e := newEnv(t)
		e.runscList(map[string]string{})
		e.dock.have = true
		mod(e.dock)
		if _, err := e.svc.Create(ctx, createPayload(NetNone), nil); err == nil || !strings.Contains(err.Error(), "sandbox") ||
			len(e.dock.pulled) != 1 {
			t.Fatal(name, err, e.dock.pulled)
		}
	}
}

// A registry is resolved on the agent before the pull: a name for a loopback, private or the server's own address is
// refused like the address itself would be. Docker Hub isn't looked up.
func TestRegistryIsResolvedBeforeThePull(t *testing.T) {
	ctx := context.Background()
	for image, want := range map[string]string{
		"127.0.0.1.nip.io:5000/x":    "not a public address",
		"10.0.0.5.sslip.io/app:1":    "not a public address",
		"metadata.example.com/x":     "not a public address",
		"unresolvable.example.com/x": "resolve registry",
		"ghcr.io/acme/tool:v1":       "",
		"ubuntu:24.04":               "",
		"docker.io/library/alpine":   "",
	} {
		e := newEnv(t)
		e.runscList(map[string]string{})
		e.resolve = map[string][]netip.Addr{
			"127.0.0.1.nip.io":     {netip.MustParseAddr("127.0.0.1")},
			"10.0.0.5.sslip.io":    {netip.MustParseAddr("10.0.0.5")},
			"metadata.example.com": {netip.MustParseAddr("140.82.112.33"), netip.MustParseAddr("::ffff:169.254.169.254")},
			"ghcr.io":              {netip.MustParseAddr("140.82.112.33")},
		}
		p := createPayload(NetNone)
		p.Image = image
		_, err := e.svc.Create(ctx, p, nil)
		if want == "" {
			if err != nil {
				t.Fatal(image, err)
			}
		} else if err == nil || !strings.Contains(err.Error(), want) || len(e.dock.pulled) != 0 {
			t.Fatal(image, err, e.dock.pulled)
		}
		if hub := !strings.Contains(strings.Split(image, "/")[0], "."); (hub || strings.HasPrefix(image, "docker.io/")) && len(e.lookups) != 0 {
			t.Fatal(image, "looked up", e.lookups)
		}
	}
}

// Nothing is pulled, exported or started when it would leave less than the floor free under Root; an image over the
// limit is refused and removed; a pull is cancelled when free space reaches the floor.
func TestDiskSpaceChecks(t *testing.T) {
	ctx := context.Background()
	if diskFloor(200<<30) != 5<<30 || diskFloor(20<<30) != 2<<30 {
		t.Fatal(diskFloor(200<<30), diskFloor(20<<30))
	}
	// The pull needs the disk quota (2 GiB) + 1 GiB + the floor (5 GiB).
	e := newEnv(t)
	e.runscList(map[string]string{})
	e.setFree(7 << 30)
	if _, err := e.svc.Create(ctx, createPayload(NetNone), nil); err == nil || !strings.Contains(err.Error(), "not enough disk space for pulling") || len(e.dock.pulled) != 0 {
		t.Fatal(err, e.dock.pulled)
	}
	// Too big: refused after the pull, and removed.
	e = newEnv(t)
	e.runscList(map[string]string{})
	e.dock.size = 11 << 30
	if _, err := e.svc.Create(ctx, createPayload(NetNone), nil); err == nil || !strings.Contains(err.Error(), "over the 10.0 GiB limit") || len(e.dock.rmi) != 1 {
		t.Fatal(err, e.dock.rmi)
	}
	// The export needs the image (8 GiB) + the quota + the floor = 15 GiB.
	e = newEnv(t)
	e.runscList(map[string]string{})
	e.dock.size = 8 << 30
	e.setFree(14 << 30)
	if _, err := e.svc.Create(ctx, createPayload(NetNone), nil); err == nil || !strings.Contains(err.Error(), "not enough disk space for exporting") || len(e.dock.created) != 0 {
		t.Fatal(err, e.dock.created)
	}
	e.setFree(15 << 30)
	if _, err := e.svc.Create(ctx, createPayload(NetNone), nil); err != nil {
		t.Fatal(err)
	}
	// Exported already: the sandbox's quota + the floor.
	_, _ = e.svc.Kill(ctx, KillPayload{ID: sid}, nil)
	e.setFree(6 << 30)
	if _, err := e.svc.Create(ctx, createPayload(NetNone), nil); err == nil || !strings.Contains(err.Error(), "not enough disk space for the sandbox's disk") {
		t.Fatal(err)
	}

	// A pull that fills the disk is cancelled.
	old := diskPoll
	diskPoll = 10 * time.Millisecond
	t.Cleanup(func() { diskPoll = old })
	e = newEnv(t)
	e.runscList(map[string]string{})
	e.dock.block = true
	go func() {
		time.Sleep(50 * time.Millisecond)
		e.setFree(4 << 30)
	}()
	if _, err := e.svc.Create(ctx, createPayload(NetNone), nil); err == nil || !strings.Contains(err.Error(), "not enough disk space for pulling") {
		t.Fatal(err)
	}
}

func TestDirsAreRootOnlyAndExtractionIsStripped(t *testing.T) {
	e := newEnv(t)
	e.runscList(map[string]string{})
	if err := os.MkdirAll(filepath.Join(e.svc.d.Root, "images"), 0o755); err != nil { // an older agent's
		t.Fatal(err)
	}
	if _, err := e.svc.Create(context.Background(), createPayload(NetNone), nil); err != nil {
		t.Fatal(err)
	}
	for _, d := range []string{e.svc.d.Root, filepath.Join(e.svc.d.Root, "images")} {
		if fi, err := os.Stat(d); err != nil || fi.Mode().Perm() != 0o700 {
			t.Fatal(d, fi.Mode(), err)
		}
	}
	if !e.run.Ran("find "+filepath.Join(e.svc.d.Root, "images", strings.Repeat("ab", 32), "rootfs.tmp")+" -xdev ( -type b -o -type c ) -delete") ||
		!e.run.Ran("find "+filepath.Join(e.svc.d.Root, "images", strings.Repeat("ab", 32), "rootfs.tmp")+" -xdev -type f -perm /6000 -exec chmod ug-s {} +") {
		t.Fatal(e.run.Lines())
	}
}

func TestInventoryReportsCreating(t *testing.T) {
	e := newEnv(t)
	e.runscList(map[string]string{"falak-sbx-" + sid: "created"})
	if err := os.MkdirAll(e.svc.dir(sid), 0o700); err != nil {
		t.Fatal(err)
	}
	if err := e.svc.writeState(&state{ID: sid, ExpiresAt: 1}); err != nil {
		t.Fatal(err)
	}
	inv, err := e.svc.Inventory(context.Background(), InventoryPayload{}, nil)
	if err != nil || inv.(InventoryResult).Sandboxes[0].Status != "creating" {
		t.Fatal(inv, err)
	}
}

func TestImageGC(t *testing.T) {
	e := newEnv(t)
	e.runscList(map[string]string{})
	images := filepath.Join(e.svc.d.Root, "images")
	old, used, fresh := strings.Repeat("a", 64), strings.Repeat("b", 64), strings.Repeat("c", 64)
	for _, h := range []string{old, used, fresh} {
		if err := os.MkdirAll(filepath.Join(images, h, "rootfs"), 0o700); err != nil {
			t.Fatal(err)
		}
		at := e.now.Add(-7 * time.Hour)
		if h == fresh {
			at = e.now.Add(-time.Hour)
		}
		_ = os.Chtimes(filepath.Join(images, h), at, at)
	}
	if err := os.MkdirAll(e.svc.dir(sid), 0o700); err != nil {
		t.Fatal(err)
	}
	_ = e.svc.writeState(&state{ID: sid, Rootfs: filepath.Join(images, used, "rootfs"), StartedAt: "x", ExpiresAt: e.now.Add(time.Hour).Unix()})
	e.runscList(map[string]string{"falak-sbx-" + sid: "running"})
	e.svc.ReapOnce(context.Background())
	for h, keep := range map[string]bool{old: false, used: true, fresh: true} {
		if _, err := os.Stat(filepath.Join(images, h)); (err == nil) != keep {
			t.Errorf("%s kept=%v", h[:1], err == nil)
		}
	}
	if strings.Join(e.dock.rmi, ",") != "sha256:"+old {
		t.Fatal(e.dock.rmi)
	}
}
