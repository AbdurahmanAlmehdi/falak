// Package sandbox implements the sandbox.* commands (docs/plans/SANDBOXES.md): disposable machines for untrusted
// code under gVisor. The agent drives runsc itself (--root=/run/falak-sbx), not through Docker: Docker only pulls an
// image and exports it once into a shared base rootfs per image digest. Per sandbox the agent owns a state directory
// (/var/lib/falak/sandboxes/<id>: the OCI bundle, resolv.conf, hosts, runsc's log and sandbox.json, the desired state
// it recovers from), a network namespace with a veth on br-sbx, and the sandbox's address in the FALAK-SBX chain.
// Files are read and written through `runsc exec` inside the sandbox, never through host paths.
package sandbox

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net"
	"net/http"
	"net/netip"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	goruntime "runtime"
	"sort"
	"strings"
	"sync"
	"syscall"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/docker"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/system"
)

// Docker is the subset of the Engine API sandboxes use (*docker.Client; tests fake it).
type Docker interface {
	ImageConfigOf(ctx context.Context, ref string) (docker.ImageConfig, bool, error)
	ImagePull(ctx context.Context, ref string, auth *docker.Auth, w io.Writer) error
	ContainerCreate(ctx context.Context, name string, body docker.CreateBody) (string, error)
	ContainerExport(ctx context.Context, id string) (io.ReadCloser, error)
	ContainerRemove(ctx context.Context, id string) error
	ImageRemove(ctx context.Context, id string) error
}

// Requester is the control plane's request channel (*transport.Client): sandbox.reaped.
type Requester interface {
	Request(ctx context.Context, typ string, in, out any) error
}

// Deps are the dependencies of the sandbox commands.
type Deps struct {
	Runner   runner.Runner
	FS       hostfs.FS // apt sources and keyrings (runsc's install)
	HTTP     *http.Client
	Docker   Docker
	Requests Requester // nil: reaps aren't reported
	DB       Dumper    // nil: no sandbox.db.import
	Logger   *slog.Logger
	// Root holds the state directories and base images (/var/lib/falak/sandboxes); RunscRoot is runsc's --root;
	// CgroupRoot is the cgroup v2 mount (memory and CPU in inventory, OOM kills).
	Root, RunscRoot, CgroupRoot string
	// GPGKeyURL and RepoURL are gVisor's apt repository.
	GPGKeyURL, RepoURL string
	Now                func() time.Time
	// LookupHost resolves an image's registry (default net.DefaultResolver); DiskSpace reports the free and total
	// bytes of the filesystem holding a path (default statfs). Tests fake both.
	LookupHost func(ctx context.Context, host string) ([]netip.Addr, error)
	DiskSpace  func(path string) (free, total uint64, err error)
}

// Service runs the sandbox.* commands and the reaper.
type Service struct {
	d     Deps
	alloc *allocator
	proxy *proxy

	locks    sync.Map   // id → *sync.Mutex
	inflight sync.Map   // id → *sync.RWMutex (busy)
	imgLocks sync.Map   // image id → *sync.Mutex
	netMu    sync.Mutex // the allocator, the chains and the proxy's listener

	diskMu   sync.Mutex
	reserved uint64 // bytes checkpoints being taken may still write (diskMu): every disk check counts them as used

	prepMu   sync.Mutex
	prepared *PrepareResult
	proxyLn  net.Listener
}

// goos is runtime.GOOS (tests pretend to be Linux).
var goos = goruntime.GOOS

// New returns a Service with defaults filled in.
func New(d Deps) *Service {
	if d.Logger == nil {
		d.Logger = slog.Default()
	}
	if d.HTTP == nil {
		d.HTTP = &http.Client{Timeout: 5 * time.Minute}
	}
	if d.Root == "" {
		d.Root = "/var/lib/falak/sandboxes"
	}
	if d.RunscRoot == "" {
		d.RunscRoot = "/run/falak-sbx"
	}
	if d.CgroupRoot == "" {
		d.CgroupRoot = "/sys/fs/cgroup"
	}
	if d.GPGKeyURL == "" {
		d.GPGKeyURL = "https://gvisor.dev/archive.key"
	}
	if d.RepoURL == "" {
		d.RepoURL = "https://storage.googleapis.com/gvisor/releases"
	}
	if d.Now == nil {
		d.Now = time.Now
	}
	if d.LookupHost == nil {
		d.LookupHost = func(ctx context.Context, host string) ([]netip.Addr, error) {
			return net.DefaultResolver.LookupNetIP(ctx, "ip", host)
		}
	}
	if d.DiskSpace == nil {
		d.DiskSpace = diskSpace
	}
	return &Service{d: d, alloc: newAllocator(filepath.Join(d.Root, "addresses.json")), proxy: newProxy()}
}

// Register adds the sandbox.* executors.
func (s *Service) Register(reg *commands.Registry) {
	reg.Register("sandbox.prepare", commands.Typed(s.Prepare))
	reg.Register("sandbox.create", commands.Typed(s.Create))
	reg.Register("sandbox.exec", commands.Typed(s.Exec))
	reg.Register("sandbox.files.read", commands.Typed(s.ReadFile))
	reg.Register("sandbox.files.list", commands.Typed(s.ListFiles))
	reg.Register("sandbox.files.write", commands.Typed(s.WriteFile))
	reg.Register("sandbox.files.delete", commands.Typed(s.DeleteFile))
	reg.Register("sandbox.extend", commands.Typed(s.Extend))
	reg.Register("sandbox.kill", commands.Typed(s.Kill))
	reg.Register("sandbox.inventory", commands.Typed(s.Inventory))
	reg.Register("sandbox.snapshot", commands.Typed(s.Snapshot))
	reg.Register("sandbox.snapshot.delete", commands.Typed(s.SnapshotDelete))
	reg.Register("sandbox.pause", commands.Typed(s.Pause))
	reg.Register("sandbox.resume", commands.Typed(s.Resume))
	reg.Register("sandbox.db.import", commands.Typed(s.DBImport))
}

var (
	idRe = regexp.MustCompile(`^[0-9a-z]{26}$`)
	// imageRe is Docker's reference grammar: [registry[:port]/]path-components[:tag][@sha256:digest] (no "..", no
	// URL syntax: the reference goes into Docker API paths).
	imageRe  = regexp.MustCompile(`^(?:(` + domainRe + `)/)?` + componentRe + `(?:/` + componentRe + `)*(?::[A-Za-z0-9_][A-Za-z0-9_.-]{0,127})?(?:@sha256:[0-9a-f]{64})?$`)
	hostRe   = regexp.MustCompile(`^(\*\.)?[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$`)
	envKeyRe = regexp.MustCompile(`^[A-Za-z_][A-Za-z0-9_]*$`)
	refRe    = regexp.MustCompile(`^[A-Za-z0-9._/@^~][A-Za-z0-9._/@^~-]{0,254}$`)
	repoRe   = regexp.MustCompile(`^(https://|git@|ssh://)[^\s]+$`)
	hexRe    = regexp.MustCompile(`^[0-9a-f]{64}$`)
	nameRe   = regexp.MustCompile(`^[A-Za-z0-9._-]{1,100}$`)
)

const (
	componentRe = `[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*`
	domainRe    = `(?:localhost|[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)+)(?::[0-9]{1,5})?`
)

// checkImage accepts a reference by Docker's grammar from a public registry: a registry on localhost or a private,
// link-local or loopback address (a server's local registry) is refused. A registry named by a host name is resolved
// (checkRegistry) before the pull.
func checkImage(ref string) error {
	m := imageRe.FindStringSubmatch(ref)
	if m == nil || len(ref) > 255 {
		return payloadErr("invalid image %q", ref)
	}
	host := registryHost(m[1])
	if host == "localhost" || strings.HasSuffix(host, ".localhost") {
		return payloadErr("image %q: a registry on localhost is not allowed", ref)
	}
	if a, err := netip.ParseAddr(host); err == nil && (!a.IsGlobalUnicast() || a.IsPrivate() || cgnat.Contains(a)) {
		return payloadErr("image %q: a registry on a private address is not allowed", ref)
	}
	return nil
}

// registryHost is a reference's registry part without its port, lower-cased; "" for Docker Hub.
func registryHost(registry string) string {
	if h, _, err := net.SplitHostPort(registry); err == nil {
		registry = h
	}
	switch registry = strings.ToLower(registry); registry {
	case "docker.io", "index.docker.io", "registry-1.docker.io":
		return ""
	}
	return registry
}

// checkRegistry resolves the registry of an image about to be pulled, on the agent, and refuses it when any of its
// addresses isn't public: a name like 127.0.0.1.nip.io or one pointing at the server itself would otherwise get past
// checkImage to a registry on the server or its network. Docker Hub isn't looked up.
func (s *Service) checkRegistry(ctx context.Context, ref string) error {
	m := imageRe.FindStringSubmatch(ref)
	if m == nil {
		return payloadErr("invalid image %q", ref)
	}
	host := registryHost(m[1])
	if host == "" {
		return nil
	}
	addrs, err := s.d.LookupHost(ctx, host)
	if err != nil {
		return fmt.Errorf("image %s: resolve registry %s: %w", ref, host, err)
	}
	if len(addrs) == 0 {
		return fmt.Errorf("image %s: registry %s has no address", ref, host)
	}
	for _, a := range addrs {
		if !publicAddr(a) {
			return payloadErr("image %q: registry %s resolves to %s, not a public address: not allowed", ref, host, a.Unmap())
		}
	}
	return nil
}

func payloadErr(format string, a ...any) error {
	return &commands.PayloadError{Err: fmt.Errorf(format, a...)}
}

func checkID(id string) error {
	if !idRe.MatchString(id) {
		return payloadErr("invalid sandbox id %q", id)
	}
	return nil
}

func cid(id string) string { return "falak-sbx-" + id }

// state is sandbox.json: what the agent needs to run, report and remove a sandbox after a restart. Never env values
// or tokens (those are only in the bundle's config.json, which runsc needs).
type state struct {
	ID          string   `json:"id"`
	Image       string   `json:"image"`
	ImageDigest string   `json:"image_digest"`
	Rootfs      string   `json:"rootfs"`
	CPUs        float64  `json:"cpus"`
	MemoryMB    int      `json:"memory_mb"`
	DiskMB      int      `json:"disk_mb"`
	Pids        int      `json:"pids"`
	Network     string   `json:"network"`
	AllowHosts  []string `json:"allow_hosts,omitempty"`
	IP          string   `json:"ip,omitempty"`
	ExpiresAt   int64    `json:"expires_at"`
	StartedAt   string   `json:"started_at,omitempty"`
	// FromSnapshot is the snapshot the sandbox was restored from.
	FromSnapshot string `json:"from_snapshot,omitempty"`
	// Reaped is set when the agent removed the sandbox on its own and the control plane hasn't been told yet.
	Reaped string `json:"reaped,omitempty"`
}

func (s *Service) dir(id string) string { return filepath.Join(s.d.Root, id) }

func (s *Service) readState(id string) (*state, error) {
	b, err := os.ReadFile(filepath.Join(s.dir(id), "sandbox.json"))
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	var st state
	return &st, json.Unmarshal(b, &st)
}

func (s *Service) writeState(st *state) error {
	b, _ := json.MarshalIndent(st, "", "  ")
	p := filepath.Join(s.dir(st.ID), "sandbox.json")
	if err := os.WriteFile(p+".tmp", b, 0o600); err != nil {
		return err
	}
	return os.Rename(p+".tmp", p)
}

// states lists every sandbox with a state directory.
func (s *Service) states() []*state {
	ents, _ := os.ReadDir(s.d.Root)
	var out []*state
	for _, e := range ents {
		if !e.IsDir() || !idRe.MatchString(e.Name()) {
			continue
		}
		if st, err := s.readState(e.Name()); err == nil && st != nil {
			out = append(out, st)
		}
	}
	return out
}

func (s *Service) lock(id string) *sync.Mutex {
	m, _ := s.locks.LoadOrStore(id, &sync.Mutex{}) // ponytail: one mutex per id ever seen, a few bytes each
	return m.(*sync.Mutex)
}

// busy is a sandbox's in-flight lock: execs and database imports hold it shared while they run, and pause only
// TryLocks it (it never waits, so shared holders may nest): a sandbox isn't paused under a running command.
func (s *Service) busy(id string) *sync.RWMutex {
	m, _ := s.inflight.LoadOrStore(id, &sync.RWMutex{})
	return m.(*sync.RWMutex)
}

func (s *Service) runscArgs(args ...string) []string {
	return append([]string{"--root=" + s.d.RunscRoot}, args...)
}

// ---- sandbox.prepare

// PreparePayload is sandbox.prepare's (empty) payload.
type PreparePayload struct{}

// PrepareResult is sandbox.prepare's result.
type PrepareResult struct {
	RunscVersion string `json:"runsc_version"`
	Platform     string `json:"platform"`
}

// Prepare installs runsc when it is missing and sets up br-sbx and the FALAK-SBX chain (idempotent).
func (s *Service) Prepare(ctx context.Context, _ PreparePayload, st commands.Stream) (any, error) {
	return s.prepare(ctx, st)
}

func (s *Service) prepare(ctx context.Context, st commands.Stream) (PrepareResult, error) {
	s.prepMu.Lock()
	defer s.prepMu.Unlock()
	if err := s.ensureDirs(); err != nil {
		return PrepareResult{}, err
	}
	if s.prepared != nil {
		return *s.prepared, nil
	}
	if goos != "linux" {
		return PrepareResult{}, fmt.Errorf("sandboxes need a Linux server (this one runs %s)", goos)
	}
	ver, err := s.runscVersion(ctx)
	if err != nil {
		if err := s.installRunsc(ctx, st); err != nil {
			return PrepareResult{}, fmt.Errorf("install runsc: %w", err)
		}
		if ver, err = s.runscVersion(ctx); err != nil {
			return PrepareResult{}, err
		}
	}
	if err := s.ensureNetwork(ctx); err != nil {
		return PrepareResult{}, err
	}
	s.prepared = &PrepareResult{RunscVersion: ver, Platform: "systrap"}
	return *s.prepared, nil
}

// ensureDirs makes the state root and the base images root-only (every time: MkdirAll leaves an existing directory's
// mode alone). Base rootfs trees come from untrusted images; no host user may reach them.
func (s *Service) ensureDirs() error {
	for _, d := range []string{s.d.Root, filepath.Join(s.d.Root, "images"), filepath.Join(s.d.Root, "snapshots")} {
		if err := os.MkdirAll(d, 0o700); err != nil {
			return err
		}
		if err := os.Chmod(d, 0o700); err != nil {
			return err
		}
	}
	return nil
}

// runscVersion parses `runsc --version` ("runsc version release-20261005.0").
func (s *Service) runscVersion(ctx context.Context) (string, error) {
	res, err := runner.Check(ctx, s.d.Runner, runner.Cmd{Name: "runsc", Args: []string{"--version"}})
	if err != nil {
		return "", err
	}
	first, _, _ := strings.Cut(string(res.Stdout), "\n")
	return strings.TrimSpace(strings.TrimPrefix(first, "runsc version")), nil
}

const (
	gvisorKeyring = "/etc/apt/keyrings/gvisor.asc"
	gvisorList    = "/etc/apt/sources.list.d/gvisor.list"
)

// installRunsc adds gVisor's apt repository (release channel) and installs runsc, like provision does for Caddy.
func (s *Service) installRunsc(ctx context.Context, st commands.Stream) error {
	if !s.d.FS.Exists(gvisorKeyring) {
		if err := s.d.FS.MkdirAll("/etc/apt/keyrings", 0o755); err != nil {
			return err
		}
		if _, _, err := system.Download(ctx, s.d.HTTP, s.d.GPGKeyURL, "", s.d.FS.P(gvisorKeyring), 0o644, nil); err != nil {
			return err
		}
	}
	list := fmt.Sprintf("# Managed by Falak\ndeb [signed-by=%s] %s release main\n", gvisorKeyring, s.d.RepoURL)
	if _, err := s.d.FS.WriteFile(gvisorList, []byte(list), 0o644); err != nil {
		return err
	}
	a := system.AptFor(s.d.Runner, s.d.FS, st)
	if err := a.Update(ctx); err != nil {
		return err
	}
	_, err := a.Ensure(ctx, []string{"runsc"}, false)
	return err
}

// ensureNetwork sets up the bridge, the chains for the sandboxes that exist, and the proxy (allowlists).
func (s *Service) ensureNetwork(ctx context.Context) error {
	s.netMu.Lock()
	defer s.netMu.Unlock()
	if err := s.ensureBridge(ctx); err != nil {
		return err
	}
	return s.syncNetLocked(ctx, "")
}

// syncNetLocked re-applies the chains and the proxy's allowlists from the state directories, leaving out the sandbox
// being removed (exclude). Callers hold netMu.
func (s *Service) syncNetLocked(ctx context.Context, exclude string) error {
	var internet []string
	allow := map[string][]string{}
	veths := map[string]string{}
	for _, st := range s.states() {
		if st.ID == exclude || st.Reaped != "" || st.IP == "" {
			continue
		}
		veths[vethName(st.ID)] = st.IP
		switch st.Network {
		case NetInternet:
			internet = append(internet, st.IP)
		case NetAllowlist:
			allow[st.IP] = st.AllowHosts
		}
	}
	sort.Strings(internet)
	if err := s.applyRules(ctx, internet); err != nil {
		return err
	}
	if err := s.applyAntiSpoof(ctx, veths); err != nil {
		return err
	}
	s.proxy.mu.Lock()
	s.proxy.allow = allow
	s.proxy.mu.Unlock()
	if len(allow) > 0 && s.proxyLn == nil {
		ln, err := net.Listen("tcp", net.JoinHostPort(gatewayIP, proxyPort))
		if err != nil {
			return fmt.Errorf("egress proxy: %w", err)
		}
		s.proxyLn = ln
		srv := &http.Server{Handler: s.proxy, ReadHeaderTimeout: 30 * time.Second}
		go func() { _ = srv.Serve(ln) }()
	}
	return nil
}

// ---- sandbox.create

// Repo is sandbox.create's repository to clone.
type Repo struct {
	URL   string `json:"url"`
	Ref   string `json:"ref,omitempty"`
	Token string `json:"token,omitempty"`
}

// CreatePayload is sandbox.create's payload.
type CreatePayload struct {
	ID         string            `json:"id"`
	Image      string            `json:"image"`
	CPUs       float64           `json:"cpus"`
	MemoryMB   int               `json:"memory_mb"`
	DiskMB     int               `json:"disk_mb"`
	Pids       int               `json:"pids,omitempty"`
	Network    string            `json:"network"`
	AllowHosts []string          `json:"allow_hosts,omitempty"`
	Env        map[string]string `json:"env,omitempty"`
	ExpiresAt  int64             `json:"expires_at"`
	Repo       *Repo             `json:"repo,omitempty"`
	// FromSnapshot restores the sandbox from a snapshot (v0.21): its limits and network must equal the snapshot's, env
	// adds to the snapshot's, and no repo.
	FromSnapshot string `json:"from_snapshot,omitempty"`
}

// Secrets keeps env values and the clone token out of the command's output and errors.
func (p CreatePayload) Secrets() []string {
	var out []string
	for _, v := range p.Env {
		if v != "" {
			out = append(out, v)
		}
	}
	if p.Repo != nil && p.Repo.Token != "" {
		out = append(out, p.Repo.Token)
	}
	return out
}

// CreateResult is sandbox.create's result.
type CreateResult struct {
	Status      string `json:"status"`
	IP          string `json:"ip,omitempty"`
	ImageDigest string `json:"image_digest"`
	StartedAt   string `json:"started_at"`
}

func (p *CreatePayload) validate() error {
	if err := checkID(p.ID); err != nil {
		return err
	}
	switch {
	case checkImage(p.Image) != nil:
		return checkImage(p.Image)
	case p.CPUs < 0.1 || p.CPUs > 64:
		return payloadErr("cpus must be 0.1–64")
	case p.MemoryMB < 64 || p.MemoryMB > 262144:
		return payloadErr("memory_mb must be 64–262144")
	case p.DiskMB < 256 || p.DiskMB > 1048576:
		return payloadErr("disk_mb must be 256–1048576")
	case p.Pids != 0 && (p.Pids < 16 || p.Pids > 65536):
		return payloadErr("pids must be 16–65536")
	case p.Network != NetNone && p.Network != NetInternet && p.Network != NetAllowlist:
		return payloadErr("unknown network %q", p.Network)
	}
	for _, h := range p.AllowHosts {
		if !hostRe.MatchString(h) {
			return payloadErr("invalid allow_hosts entry %q", h)
		}
	}
	for k := range p.Env {
		if !envKeyRe.MatchString(k) {
			return payloadErr("invalid env name %q", k)
		}
	}
	if r := p.Repo; r != nil {
		if !repoRe.MatchString(r.URL) || repoName(r.URL) == "" {
			return payloadErr("invalid repo url")
		}
		if r.Ref != "" && !refRe.MatchString(r.Ref) {
			return payloadErr("invalid repo ref %q", r.Ref)
		}
	}
	if p.FromSnapshot != "" {
		if err := checkSnapshotID(p.FromSnapshot); err != nil {
			return err
		}
		if p.Repo != nil {
			return payloadErr("repo is not allowed with from_snapshot")
		}
	}
	if p.Pids == 0 {
		p.Pids = 1024
	}
	return nil
}

// Create starts a sandbox: idempotent by id (a running sandbox is reported as is; a half-made one is removed and
// made again).
func (s *Service) Create(ctx context.Context, p CreatePayload, st commands.Stream) (any, error) {
	if err := p.validate(); err != nil {
		return nil, err
	}
	mu := s.lock(p.ID)
	mu.Lock()
	defer mu.Unlock()
	if old, err := s.readState(p.ID); err != nil {
		return nil, err
	} else if old != nil {
		status, known := s.status(ctx, p.ID)
		if !known {
			// Never remake a sandbox because runsc couldn't be asked: it may be running fine.
			return nil, fmt.Errorf("sandbox %s exists but runsc can't report its status; try again", p.ID)
		}
		if old.Reaped == "" && (status == "running" || status == "paused") {
			return CreateResult{Status: "running", IP: old.IP, ImageDigest: old.ImageDigest, StartedAt: old.StartedAt}, nil
		}
		s.teardown(ctx, old)
		_ = os.RemoveAll(s.dir(p.ID))
	}
	if _, err := s.prepare(ctx, st); err != nil {
		return nil, err
	}
	var res CreateResult
	var err error
	if p.FromSnapshot != "" {
		res, err = s.restore(ctx, p)
	} else {
		res, err = s.create(ctx, p, st)
	}
	if err != nil {
		// Without a state file (it failed before writing one) the teardown still releases an allocated address.
		old, _ := s.readState(p.ID)
		if old == nil {
			old = &state{ID: p.ID}
		}
		s.teardown(ctx, old)
		_ = os.RemoveAll(s.dir(p.ID))
		return nil, err
	}
	return res, nil
}

func (s *Service) create(ctx context.Context, p CreatePayload, st commands.Stream) (CreateResult, error) {
	img, err := s.baseImage(ctx, p.Image, uint64(p.DiskMB)<<20, st)
	if err != nil {
		return CreateResult{}, err
	}
	dir := s.dir(p.ID)
	if err := os.MkdirAll(filepath.Join(dir, "bundle"), 0o700); err != nil {
		return CreateResult{}, err
	}
	sb := &state{ID: p.ID, Image: p.Image, ImageDigest: img.ID, Rootfs: img.Rootfs, CPUs: p.CPUs, MemoryMB: p.MemoryMB,
		DiskMB: p.DiskMB, Pids: p.Pids, Network: p.Network, AllowHosts: p.AllowHosts, ExpiresAt: p.ExpiresAt}
	if p.Network != NetNone {
		s.netMu.Lock()
		sb.IP, err = s.alloc.Allocate(p.ID)
		s.netMu.Unlock()
		if err != nil {
			return CreateResult{}, err
		}
	}
	// Written first, so whatever happens next can be undone (here, or by the reaper after a crash).
	if err := s.writeState(sb); err != nil {
		return CreateResult{}, err
	}
	env := append([]string{"PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"}, img.Env...)
	env = append(env, "HOME=/root", "HOSTNAME=falak-sbx")
	for _, k := range sortedKeys(p.Env) {
		env = append(env, k+"="+p.Env[k])
	}
	if p.Network == NetAllowlist {
		env = append(env, proxyEnv()...)
	}
	if err := s.writeBundle(sb, env); err != nil {
		return CreateResult{}, err
	}
	if err := s.setupNetwork(ctx, sb); err != nil {
		return CreateResult{}, err
	}
	if err := s.start(ctx, p.ID, p.DiskMB, p.Network, "run"); err != nil {
		return CreateResult{}, err
	}
	// Init writes falak-bg as it starts: the first exec must find it. An image without mkfifo has none (not an error).
	if o, err := s.execIn(ctx, p.ID, execReq{argv: []string{"sh", "-c", waitSpawner}, timeout: 10 * time.Second, max: 4096}); err != nil || o.code != 0 {
		s.d.Logger.Debug("sandbox without falak-bg", "sandbox", p.ID, "err", err, "exit", o.code)
	}
	sb.StartedAt = s.d.Now().UTC().Format(time.RFC3339)
	if err := s.writeState(sb); err != nil {
		return CreateResult{}, err
	}
	if p.Repo != nil {
		if err := s.clone(ctx, p.ID, *p.Repo); err != nil {
			return CreateResult{}, err
		}
	}
	return CreateResult{Status: "running", IP: sb.IP, ImageDigest: sb.ImageDigest, StartedAt: sb.StartedAt}, nil
}

// writeBundle writes the sandbox's resolv.conf, hosts and bundle/config.json (its limits, network and env).
func (s *Service) writeBundle(sb *state, env []string) error {
	dir := s.dir(sb.ID)
	in := specInput{Rootfs: sb.Rootfs, Env: env, Resolv: filepath.Join(dir, "resolv.conf"), Hosts: filepath.Join(dir, "hosts"),
		CPUs: sb.CPUs, MemoryMB: sb.MemoryMB, Pids: sb.Pids, Cgroup: cgroupPath(sb.ID)}
	if sb.Network != NetNone {
		in.Netns = "/var/run/netns/" + netnsName(sb.ID)
	}
	spec, _ := json.MarshalIndent(buildSpec(in), "", "  ")
	for name, data := range map[string][]byte{"resolv.conf": []byte(resolvConf(sb.Network)), "hosts": []byte(hostsFile), "bundle/config.json": spec} {
		if err := os.WriteFile(filepath.Join(dir, name), data, 0o600); err != nil {
			return err
		}
	}
	return nil
}

// setupNetwork gives a sandbox with a network its namespace, veth and rules.
func (s *Service) setupNetwork(ctx context.Context, sb *state) error {
	if sb.Network == NetNone {
		return nil
	}
	s.netMu.Lock()
	defer s.netMu.Unlock()
	if err := s.ensureBridge(ctx); err != nil {
		return err
	}
	if err := s.setupNetns(ctx, sb.ID, sb.IP); err != nil {
		return err
	}
	return s.syncNetLocked(ctx, "")
}

func sortedKeys(m map[string]string) []string {
	keys := make([]string, 0, len(m))
	for k := range m {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	return keys
}

func cgroupPath(id string) string { return "/falak-sbx/" + cid(id) }

// runArgs is `runsc run --detach` (or `restore --image-path=… --detach`: verb and extra) under sh, so runsc's stdio
// goes to the sandbox's log (a detached runsc otherwise keeps the agent's pipes open).
func (s *Service) runArgs(id string, diskMB int, network, verb string, extra ...string) []string {
	args := []string{"-c", `log=$1; shift; exec "$@" </dev/null >>"$log" 2>&1`, "sh", filepath.Join(s.dir(id), "runsc.log"), "runsc",
		"--root=" + s.d.RunscRoot, overlayFlag(diskMB)}
	if network == NetNone {
		args = append(args, "--network=none")
	}
	args = append(append(append(args, verb), extra...), "--detach", "--bundle", filepath.Join(s.dir(id), "bundle"), cid(id))
	return args
}

// start runs (or restores) the sandbox from its bundle.
func (s *Service) start(ctx context.Context, id string, diskMB int, network, verb string, extra ...string) error {
	if err := os.MkdirAll(s.d.RunscRoot, 0o700); err != nil {
		return err
	}
	res, err := s.d.Runner.Run(ctx, runner.Cmd{Name: "sh", Args: s.runArgs(id, diskMB, network, verb, extra...)})
	if err == nil && res.ExitCode != 0 {
		err = fmt.Errorf("runsc "+verb+": exit status %d: %s", res.ExitCode, logTail(filepath.Join(s.dir(id), "runsc.log")))
	}
	return err
}

func logTail(path string) string {
	b, _ := os.ReadFile(path)
	if len(b) > 2048 {
		b = b[len(b)-2048:]
	}
	return strings.TrimSpace(string(b))
}

// image is a base rootfs: the image's id (its digest), the exported rootfs and the image's environment.
type image struct {
	ID, Rootfs string
	Env        []string
}

// baseImage exports an image once per image id into images/<hex>/rootfs (a stopped container's filesystem, extracted
// by tar). Concurrent creates of one image wait for the same export. An image without a base rootfs yet is pulled
// first, without credentials, even when it is local: one built on this server, or pulled with the server's registry
// credentials, may have secrets baked in, and only images anyone can pull become base rootfs trees.
//
// Disk: diskBytes is the sandbox's disk quota. Nothing is pulled, exported or started when it would leave less than
// the floor free under Root (checkDisk), a pull is cancelled as soon as free space reaches the floor, and an image
// over maxImageBytes is refused (and removed when this pull brought it).
func (s *Service) baseImage(ctx context.Context, ref string, diskBytes uint64, st commands.Stream) (image, error) {
	cfg, ok, err := s.d.Docker.ImageConfigOf(ctx, ref)
	if err != nil {
		return image{}, err
	}
	local := ok
	if !ok || !s.haveRootfs(cfg.ID) {
		if err := s.checkRegistry(ctx, ref); err != nil {
			return image{}, err
		}
		if err := s.checkDisk(diskBytes+pullReserve, "pulling "+ref); err != nil {
			return image{}, err
		}
		var w io.Writer
		if st != nil {
			w = st.Stdout()
		}
		if err := s.pull(ctx, ref, w); err != nil {
			return image{}, fmt.Errorf("pull %s (sandboxes use images anyone can pull from a registry): %w", ref, err)
		}
		if cfg, ok, err = s.d.Docker.ImageConfigOf(ctx, ref); err != nil || !ok {
			return image{}, fmt.Errorf("image %s missing after pull: %v", ref, err)
		}
	}
	if cfg.Size > maxImageBytes {
		if !local {
			_ = s.d.Docker.ImageRemove(context.WithoutCancel(ctx), cfg.ID) // best effort: pulled for this sandbox only
		}
		return image{}, payloadErr("image %s is %s, over the %s limit for sandbox images", ref, gib(uint64(cfg.Size)), gib(maxImageBytes))
	}
	hex := strings.TrimPrefix(cfg.ID, "sha256:")
	if !hexRe.MatchString(hex) {
		return image{}, fmt.Errorf("unexpected image id %q", cfg.ID)
	}
	for k := range cfg.Config.Labels {
		if strings.HasPrefix(k, "falak.") {
			return image{}, fmt.Errorf("image %s is a Falak image (label %s): not allowed in a sandbox", ref, k)
		}
	}
	img := image{ID: cfg.ID, Rootfs: filepath.Join(s.d.Root, "images", hex, "rootfs"), Env: cfg.Config.Env}
	m, _ := s.imgLocks.LoadOrStore(hex, &sync.Mutex{})
	mu := m.(*sync.Mutex)
	mu.Lock()
	defer mu.Unlock()
	if _, err := os.Stat(img.Rootfs); err == nil {
		if err := s.checkDisk(diskBytes, "the sandbox's disk"); err != nil {
			return image{}, err
		}
		now := s.d.Now()
		_ = os.Chtimes(filepath.Dir(img.Rootfs), now, now) // last used, for the image GC
		return img, nil
	}
	// The export writes about the image's size again, and the sandbox may write up to its quota.
	if err := s.checkDisk(uint64(max(cfg.Size, 0))+diskBytes, "exporting "+ref); err != nil {
		return image{}, err
	}
	tmp := img.Rootfs + ".tmp"
	_ = os.RemoveAll(tmp)
	if err := os.MkdirAll(tmp, 0o755); err != nil {
		return image{}, err
	}
	name := "falak-sbx-export-" + hex[:12]
	_ = s.d.Docker.ContainerRemove(ctx, name)
	id, err := s.d.Docker.ContainerCreate(ctx, name, docker.CreateBody{Image: ref, Entrypoint: []string{"/falak-export"},
		Labels: map[string]string{"falak.sandbox.export": "1"}})
	if err != nil {
		return image{}, fmt.Errorf("export %s: %w", ref, err)
	}
	defer func() { _ = s.d.Docker.ContainerRemove(context.WithoutCancel(ctx), id) }()
	tr, err := s.d.Docker.ContainerExport(ctx, id)
	if err != nil {
		return image{}, fmt.Errorf("export %s: %w", ref, err)
	}
	defer tr.Close()
	if _, err := runner.Check(ctx, s.d.Runner, runner.Cmd{Name: "tar", Args: []string{"-C", tmp, "-x", "--numeric-owner"}, Stdin: tr}); err != nil {
		return image{}, fmt.Errorf("extract %s: %w", ref, err)
	}
	// No setuid/setgid binaries or device nodes from an untrusted image on the host (gVisor's /dev is its own).
	for _, args := range [][]string{
		{tmp, "-xdev", "(", "-type", "b", "-o", "-type", "c", ")", "-delete"},
		{tmp, "-xdev", "-type", "f", "-perm", "/6000", "-exec", "chmod", "ug-s", "{}", "+"},
	} {
		if _, err := runner.Check(ctx, s.d.Runner, runner.Cmd{Name: "find", Args: args}); err != nil {
			return image{}, fmt.Errorf("extract %s: %w", ref, err)
		}
	}
	if err := os.Rename(tmp, img.Rootfs); err != nil {
		return image{}, err
	}
	return img, nil
}

const (
	// maxImageBytes caps a sandbox image's (unpacked) size.
	maxImageBytes = 10 << 30
	// diskFloorMax is the most free space sandboxes must leave under Root; below that, a tenth of the filesystem.
	diskFloorMax = 5 << 30
	// pullReserve is what a pull of an image whose size isn't known yet is assumed to take (the pull itself is
	// cancelled at the floor, and the size is checked once it is known).
	pullReserve = 1 << 30
)

// diskPoll is how often free space is checked during a pull (a var: tests shorten it).
var diskPoll = time.Second

// diskFloor is the free space sandboxes leave on a filesystem of total bytes: 10% of it or 5 GiB, whichever is smaller.
func diskFloor(total uint64) uint64 { return min(total/10, diskFloorMax) }

// checkDisk refuses when need more bytes under Root (and what checkpoints being taken reserved) would leave less than
// the floor free.
func (s *Service) checkDisk(need uint64, what string) error {
	s.diskMu.Lock()
	defer s.diskMu.Unlock()
	return s.checkDiskLocked(need, what)
}

// reserveDisk is checkDisk that also reserves need until release is called: snapshots taken at the same time can't
// each pass the check against the same free space.
func (s *Service) reserveDisk(need uint64, what string) (release func(), err error) {
	s.diskMu.Lock()
	defer s.diskMu.Unlock()
	if err := s.checkDiskLocked(need, what); err != nil {
		return nil, err
	}
	s.reserved += need
	return func() {
		s.diskMu.Lock()
		s.reserved -= need
		s.diskMu.Unlock()
	}, nil
}

func (s *Service) checkDiskLocked(need uint64, what string) error {
	free, total, err := s.d.DiskSpace(s.d.Root)
	if err != nil {
		return fmt.Errorf("free space under %s: %w", s.d.Root, err)
	}
	if floor := diskFloor(total); free < floor || free-floor < need+s.reserved {
		held := ""
		if s.reserved > 0 {
			held = ", " + gib(s.reserved) + " reserved for snapshots being taken"
		}
		return fmt.Errorf("not enough disk space for %s: %s free under %s, %s needed%s and %s kept free", what, gib(free), s.d.Root, gib(need), held, gib(floor))
	}
	return nil
}

// pull pulls an image anonymously, cancelled when free space under Root reaches the floor.
func (s *Service) pull(ctx context.Context, ref string, w io.Writer) error {
	pctx, cancel := context.WithCancelCause(ctx)
	defer cancel(nil)
	done := make(chan struct{})
	defer close(done)
	poll := diskPoll // read here: the goroutine may outlive pull
	go func() {
		t := time.NewTicker(poll)
		defer t.Stop()
		for {
			select {
			case <-done:
				return
			case <-t.C:
				if err := s.checkDisk(0, "pulling "+ref); err != nil {
					cancel(err)
					return
				}
			}
		}
	}()
	err := s.d.Docker.ImagePull(pctx, ref, nil, w)
	if cause := context.Cause(pctx); err != nil && cause != nil && ctx.Err() == nil {
		return cause
	}
	return err
}

func gib(b uint64) string { return fmt.Sprintf("%.1f GiB", float64(b)/(1<<30)) }

func diskSpace(path string) (free, total uint64, err error) {
	var st syscall.Statfs_t
	if err := syscall.Statfs(path, &st); err != nil {
		return 0, 0, err
	}
	bs := uint64(st.Bsize)
	return uint64(st.Bavail) * bs, uint64(st.Blocks) * bs, nil
}

// haveRootfs reports whether image id has a base rootfs (it was pulled anonymously and exported).
func (s *Service) haveRootfs(id string) bool {
	hex := strings.TrimPrefix(id, "sha256:")
	if !hexRe.MatchString(hex) {
		return false
	}
	_, err := os.Stat(filepath.Join(s.d.Root, "images", hex, "rootfs"))
	return err == nil
}

// repoName is the directory a repository is cloned into: /workspace/<name>.
func repoName(url string) string {
	u := strings.TrimSuffix(strings.TrimSuffix(url, "/"), ".git")
	if i := strings.LastIndexAny(u, "/:"); i >= 0 {
		u = u[i+1:]
	}
	if !nameRe.MatchString(u) || u == "." || u == ".." {
		return ""
	}
	return u
}

// gitCredential answers git's credential requests from FALAK_GIT_TOKEN, an env variable of the clone's process only:
// the token is never in the sandbox's env, its state or the repository's config.
const gitCredential = `!f() { echo username=x-access-token; echo "password=$FALAK_GIT_TOKEN"; }; f`

func cloneArgs(r Repo) ([]string, []string) {
	dest := "/workspace/" + repoName(r.URL)
	argv := []string{"git"}
	if r.Token != "" {
		// Only for the repository's host (not a redirect's or a submodule's), after clearing the image's helpers.
		if u, err := url.Parse(r.URL); err == nil && u.Scheme == "https" && u.Host != "" {
			argv = append(argv, "-c", "credential.helper=", "-c", "credential.https://"+u.Host+".helper="+gitCredential)
		}
	}
	argv = append(argv, "clone", "--", r.URL, dest)
	env := []string{"GIT_TERMINAL_PROMPT=0"}
	if r.Token != "" {
		env = append(env, "FALAK_GIT_TOKEN="+r.Token)
	}
	return argv, env
}

// clone runs git inside the sandbox (an image without git fails the create).
func (s *Service) clone(ctx context.Context, id string, r Repo) error {
	out, err := s.execIn(ctx, id, execReq{argv: []string{"sh", "-c", "command -v git"}, timeout: 30 * time.Second, max: 4096})
	if err != nil {
		return err
	}
	if out.code != 0 {
		return errors.New("the image has no git: use an image with git to clone a repository")
	}
	argv, env := cloneArgs(r)
	steps := map[string]execReq{"clone": {argv: argv, env: env, timeout: 10 * time.Minute, max: 64 << 10}}
	if r.Ref != "" {
		steps["checkout"] = execReq{argv: []string{"git", "-C", "/workspace/" + repoName(r.URL), "checkout", r.Ref, "--"}, timeout: time.Minute, max: 64 << 10}
	}
	for _, name := range []string{"clone", "checkout"} {
		step, ok := steps[name]
		if !ok {
			continue
		}
		out, err := s.execIn(ctx, id, step)
		if err != nil {
			return err
		}
		if out.timedOut || out.code != 0 {
			return fmt.Errorf("git %s failed (exit %d): %s", name, out.code, strings.TrimSpace(string(out.stderr)))
		}
	}
	return nil
}

// ---- sandbox.extend

// ExtendPayload is sandbox.extend's payload.
type ExtendPayload struct {
	ID        string `json:"id"`
	ExpiresAt int64  `json:"expires_at"`
}

// ExtendResult is sandbox.extend's result.
type ExtendResult struct {
	ExpiresAt int64 `json:"expires_at"`
}

// Extend sets a sandbox's expiry; it never touches the sandbox itself.
func (s *Service) Extend(_ context.Context, p ExtendPayload, _ commands.Stream) (any, error) {
	if err := checkID(p.ID); err != nil {
		return nil, err
	}
	if p.ExpiresAt <= 0 {
		return nil, payloadErr("expires_at must be a unix time")
	}
	mu := s.lock(p.ID)
	mu.Lock()
	defer mu.Unlock()
	st, err := s.readState(p.ID)
	if err != nil {
		return nil, err
	}
	if st == nil || st.Reaped != "" {
		return nil, fmt.Errorf("sandbox %s not found", p.ID)
	}
	st.ExpiresAt = p.ExpiresAt
	if err := s.writeState(st); err != nil {
		return nil, err
	}
	return ExtendResult{ExpiresAt: st.ExpiresAt}, nil
}

// ---- sandbox.kill

// KillPayload is sandbox.kill's payload.
type KillPayload struct {
	ID     string `json:"id"`
	Reason string `json:"reason,omitempty"`
}

// Kill removes a sandbox and everything it had (an unknown sandbox succeeds).
func (s *Service) Kill(ctx context.Context, p KillPayload, _ commands.Stream) (any, error) {
	if err := checkID(p.ID); err != nil {
		return nil, err
	}
	mu := s.lock(p.ID)
	mu.Lock()
	defer mu.Unlock()
	st, err := s.readState(p.ID)
	if err != nil {
		return nil, err
	}
	if st == nil {
		st = &state{ID: p.ID}
	}
	s.teardown(ctx, st)
	if err := os.RemoveAll(s.dir(p.ID)); err != nil {
		return nil, err
	}
	return struct{}{}, nil
}

// teardown stops the sandbox and releases what it held on the host; the state directory stays (callers remove it).
func (s *Service) teardown(ctx context.Context, st *state) {
	if res, err := s.d.Runner.Run(ctx, runner.Cmd{Name: "runsc", Args: s.runscArgs("delete", "-force", cid(st.ID))}); err != nil || res.ExitCode != 0 {
		s.d.Logger.Debug("runsc delete", "sandbox", st.ID, "err", err, "stderr", strings.TrimSpace(string(res.Stderr)))
	}
	if st.Rootfs != "" {
		// The writes' filestore sits next to the shared rootfs (--overlay2=root:self).
		files, _ := filepath.Glob(filepath.Join(st.Rootfs, ".gvisor.filestore."+cid(st.ID)+"*"))
		for _, f := range files {
			_ = os.Remove(f)
		}
	}
	s.netMu.Lock()
	defer s.netMu.Unlock()
	s.teardownNetns(ctx, st.ID)
	if err := s.alloc.Release(st.ID); err != nil {
		s.d.Logger.Warn("release sandbox address", "sandbox", st.ID, "err", err)
	}
	if st.IP != "" {
		if err := s.syncNetLocked(ctx, st.ID); err != nil {
			s.d.Logger.Warn("sandbox network rules", "sandbox", st.ID, "err", err)
		}
	}
}

// ---- sandbox.inventory

// InventoryPayload is sandbox.inventory's (empty) payload.
type InventoryPayload struct{}

// InventoryItem is one sandbox in the inventory.
type InventoryItem struct {
	ID          string  `json:"id"`
	Status      string  `json:"status"`
	OOMKilled   bool    `json:"oom_killed"`
	StartedAt   string  `json:"started_at,omitempty"`
	ExpiresAt   int64   `json:"expires_at"`
	MemoryBytes int64   `json:"memory_bytes"`
	CPUSeconds  float64 `json:"cpu_seconds"`
}

// Inventory lists the sandboxes with a state directory, their runsc status and usage.
func (s *Service) Inventory(ctx context.Context, _ InventoryPayload, _ commands.Stream) (any, error) {
	out := []InventoryItem{}
	snaps := []InventorySnapshot{}
	for _, m := range s.snapshots() {
		snaps = append(snaps, InventorySnapshot{SnapshotID: m.SnapshotID, SizeBytes: m.SizeBytes})
	}
	states := s.states()
	if len(states) == 0 {
		return InventoryResult{Sandboxes: out, Snapshots: snaps}, nil // runsc may not even be installed
	}
	list, err := s.list(ctx)
	if err != nil {
		return nil, err
	}
	for _, st := range states {
		it := InventoryItem{ID: st.ID, StartedAt: st.StartedAt, ExpiresAt: st.ExpiresAt, Status: "stopped"}
		switch {
		case st.Reaped != "":
			it.OOMKilled = st.Reaped == "oom"
		case st.StartedAt == "" || list[cid(st.ID)] == "created":
			it.Status = "creating" // mid-create (or a crashed create the reaper will remove): not "stopped"
		case list[cid(st.ID)] == "running" || list[cid(st.ID)] == "paused":
			it.Status = list[cid(st.ID)]
			it.MemoryBytes, it.CPUSeconds = s.usage(st.ID)
		default:
			it.OOMKilled = s.oomKilled(st.ID)
		}
		out = append(out, it)
	}
	return InventoryResult{Sandboxes: out, Snapshots: snaps}, nil
}

// InventoryResult is sandbox.inventory's result.
type InventoryResult struct {
	Sandboxes []InventoryItem     `json:"sandboxes"`
	Snapshots []InventorySnapshot `json:"snapshots"`
}

// InventorySnapshot is one snapshot on the server (v0.21).
type InventorySnapshot struct {
	SnapshotID string `json:"snapshot_id"`
	SizeBytes  int64  `json:"size_bytes"`
}

// list returns runsc's containers (id → status: created, running, paused, stopped).
func (s *Service) list(ctx context.Context) (map[string]string, error) {
	if err := os.MkdirAll(s.d.RunscRoot, 0o700); err != nil {
		return nil, err
	}
	res, err := runner.Check(ctx, s.d.Runner, runner.Cmd{Name: "runsc", Args: s.runscArgs("list", "--format=json")})
	if err != nil {
		return nil, err
	}
	var rows []struct {
		ID     string `json:"id"`
		Status string `json:"status"`
	}
	if b := strings.TrimSpace(string(res.Stdout)); b != "" && b != "null" {
		if err := json.Unmarshal([]byte(b), &rows); err != nil {
			return nil, fmt.Errorf("runsc list: %w", err)
		}
	}
	m := map[string]string{}
	for _, r := range rows {
		m[r.ID] = r.Status
	}
	return m, nil
}

// status is one sandbox's runsc status ("" = runsc doesn't know it); known=false when runsc couldn't be asked.
func (s *Service) status(ctx context.Context, id string) (string, bool) {
	m, err := s.list(ctx)
	if err != nil {
		return "", false
	}
	return m[cid(id)], true
}

func (s *Service) cgroupFile(id, name string) string {
	return filepath.Join(s.d.CgroupRoot, cgroupPath(id), name)
}

// usage reads memory.current and cpu.stat's usage_usec (0 when the cgroup can't be read).
func (s *Service) usage(id string) (int64, float64) {
	var mem int64
	if b, err := os.ReadFile(s.cgroupFile(id, "memory.current")); err == nil {
		_, _ = fmt.Sscan(string(b), &mem)
	}
	var usec int64
	if b, err := os.ReadFile(s.cgroupFile(id, "cpu.stat")); err == nil {
		for _, line := range strings.Split(string(b), "\n") {
			if v, ok := strings.CutPrefix(line, "usage_usec "); ok {
				_, _ = fmt.Sscan(v, &usec)
			}
		}
	}
	return mem, float64(usec) / 1e6
}

// oomKilled reports whether the sandbox's cgroup saw an OOM kill (memory.events oom_kill / oom_group_kill).
func (s *Service) oomKilled(id string) bool {
	b, err := os.ReadFile(s.cgroupFile(id, "memory.events"))
	if err != nil {
		return false
	}
	for _, line := range strings.Split(string(b), "\n") {
		f := strings.Fields(line)
		if len(f) == 2 && (f[0] == "oom_kill" || f[0] == "oom_group_kill") && f[1] != "0" {
			return true
		}
	}
	return false
}
