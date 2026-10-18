package docker

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"regexp"
	"sort"
	"strconv"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/envlinks"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// UpstreamSetter repoints a Caddy route's reverse_proxy upstreams (implemented by internal/edge).
type UpstreamSetter interface {
	SetUpstreams(ctx context.Context, routeID string, dials []string) error
}

// Options configures the docker executors.
type Options struct {
	Socket    string // default /var/run/docker.sock
	Runner    runner.Runner
	FS        hostfs.FS
	Upstreams UpstreamSetter
	HTTP      *http.Client // health checks
	// SecretsDir holds containers' secret files, on a tmpfs; default DefaultSecretsDir.
	SecretsDir string
	// EnvDir holds compose projects' env files, on a tmpfs; default envlinks.DefaultEnvDir.
	EnvDir string
	// Links remembers the release links to compose env files (MissingSecrets after a reboot); nil = none.
	Links interface {
		Record(site, link, target string) error
	}
	Logger *slog.Logger
	// Client overrides the Engine client (tests).
	Client *Client
}

// Service implements docker.* and deploy.container.swap.
type Service struct {
	opts Options
	c    *Client
	log  *slog.Logger
}

// New creates the service.
func New(o Options) *Service {
	if o.Runner == nil {
		o.Runner = runner.Exec{}
	}
	if o.HTTP == nil {
		o.HTTP = &http.Client{Timeout: 5 * time.Second}
	}
	if o.Logger == nil {
		o.Logger = slog.Default()
	}
	if o.SecretsDir == "" {
		o.SecretsDir = DefaultSecretsDir
	}
	if o.EnvDir == "" {
		o.EnvDir = envlinks.DefaultEnvDir
	}
	c := o.Client
	if c == nil {
		c = NewClient(o.Socket)
	}
	return &Service{opts: o, c: c, log: o.Logger.With("component", "docker")}
}

// Client exposes the Engine client (facts).
func (s *Service) Client() *Client { return s.c }

// Register adds docker.* (docker.update changes live limits) and deploy.container.swap.
func (s *Service) Register(reg *commands.Registry) {
	reg.Register("docker.pull", commands.Typed(s.pull))
	reg.Register("docker.run", commands.Typed(s.run))
	reg.Register("docker.stop", commands.Typed(s.stop))
	reg.Register("docker.prune", commands.Typed(s.prune))
	reg.Register("docker.update", commands.Typed(s.update))
	reg.Register("docker.compose.up", commands.Typed(s.composeUp))
	reg.Register("docker.compose.down", commands.Typed(s.composeDown))
	reg.Register("docker.compose.pull", commands.Typed(s.composePull))
	reg.Register("docker.compose.ps", commands.Typed(s.composePs))
	reg.Register("docker.compose.restart", commands.Typed(s.composeRestart))
	reg.Register("deploy.container.swap", commands.Typed(s.swap))
}

// ---- docker.pull ----

type PullPayload struct {
	Image string `json:"image"`
	Auth  *Auth  `json:"auth,omitempty"`
}

type PullResult struct {
	ImageID string `json:"image_id"`
}

func (s *Service) pull(ctx context.Context, p PullPayload, st commands.Stream) (any, error) {
	if p.Image == "" {
		return nil, &commands.PayloadError{Err: fmt.Errorf("image required")}
	}
	if err := s.c.ImagePull(ctx, p.Image, p.Auth, st.Stdout()); err != nil {
		return nil, err
	}
	id, ok, err := s.c.ImageInspect(ctx, p.Image)
	if err != nil {
		return nil, err
	}
	if !ok {
		return nil, fmt.Errorf("image %s missing after pull", p.Image)
	}
	return PullResult{ImageID: id}, nil
}

func (s *Service) ensureImage(ctx context.Context, image, policy string, auth *Auth, st commands.Stream) error {
	switch policy {
	case "", "missing":
		_, ok, err := s.c.ImageInspect(ctx, image)
		if err != nil || ok {
			return err
		}
	case "never":
		return nil
	case "always":
	default:
		return &commands.PayloadError{Err: fmt.Errorf("invalid pull policy %q", policy)}
	}
	var w interface{ Write([]byte) (int, error) }
	if st != nil {
		w = st.Stdout()
	}
	return s.c.ImagePull(ctx, image, auth, w)
}

// ---- docker.run ----

type PortSpec struct {
	HostIP        string `json:"host_ip,omitempty"`
	HostPort      int    `json:"host_port,omitempty"`
	ContainerPort int    `json:"container_port"`
	Protocol      string `json:"protocol,omitempty"`
}

type VolumeSpec struct {
	Source   string `json:"source"`
	Target   string `json:"target"`
	ReadOnly bool   `json:"read_only,omitempty"`
}

type RunPayload struct {
	Name       string            `json:"name"`
	Image      string            `json:"image"`
	Pull       string            `json:"pull,omitempty"`
	Auth       *Auth             `json:"auth,omitempty"`
	Env        map[string]string `json:"env,omitempty"`
	Command    []string          `json:"command,omitempty"`
	Entrypoint []string          `json:"entrypoint,omitempty"`
	User       string            `json:"user,omitempty"`
	Ports      []PortSpec        `json:"ports,omitempty"`
	Volumes    []VolumeSpec      `json:"volumes,omitempty"`
	Network    string            `json:"network,omitempty"`
	Networks   []NetworkJoin     `json:"networks,omitempty"`
	Labels     map[string]string `json:"labels,omitempty"`
	// Limits: memory, CPUs, processes, restart policy, log caps and OOM preference.
	Limits
	// SecretFiles are mounted read-only at /run/secrets/<name> (never in the container's env, which docker inspect shows).
	SecretFiles []SecretFile `json:"secret_files,omitempty"`
	// Mask names the secret variables of env.
	Mask []string `json:"mask,omitempty"`
}

// Secrets are the masked env values and the secret files.
func (p RunPayload) Secrets() []string { return secretValues(p.Env, p.Mask, p.SecretFiles) }

// NetworkJoin is an existing network the container joins besides its own, under extra DNS names (a compose service
// run as its own Falak site joins its stack's network as the service it was, so both sides keep resolving each other).
type NetworkJoin struct {
	Name    string   `json:"name"`
	Aliases []string `json:"aliases,omitempty"`
	// Compose marks a network a compose project owns (feature docker.networks.create): when it doesn't exist yet the
	// agent creates it with Compose's own labels, so the project's first `docker compose up` adopts it instead of
	// failing. A service split out of a stack at creation can then deploy before the stack.
	Compose *ComposeNetwork `json:"compose,omitempty"`
	// Environment marks a Falak environment network (falak-env-<id>, where the environment's databases answer by
	// name): created when missing instead of waited for.
	Environment bool `json:"environment,omitempty"`
}

// EnvironmentNetworkRe is the name of a Falak environment network.
var EnvironmentNetworkRe = regexp.MustCompile(`^falak-env-[0-9a-z]{26}$`)

// ensureEnvironmentNetwork creates an environment network when missing (labels falak.managed, falak.network).
func (s *Service) ensureEnvironmentNetwork(ctx context.Context, name string) error {
	if !EnvironmentNetworkRe.MatchString(name) {
		return &commands.PayloadError{Err: fmt.Errorf("invalid environment network %q", name)}
	}
	ok, err := s.c.NetworkExists(ctx, name)
	if err != nil || ok {
		return err
	}
	return s.c.NetworkCreate(ctx, name, map[string]string{LabelManaged: "true", "falak.network": "environment"})
}

// ComposeNetwork is the compose project and network key that own a network.
type ComposeNetwork struct {
	Project string `json:"project"`
	Network string `json:"network"`
}

// labels are what Docker Compose checks on an existing network before it reuses it ("network … was found but has
// incorrect label com.docker.compose.network …" otherwise; verified with Compose v2.34).
func (n ComposeNetwork) labels() map[string]string {
	return map[string]string{"com.docker.compose.project": n.Project, "com.docker.compose.network": n.Network}
}

// networkWait is how long a container waits for a network it joins to appear (a compose stack deploying in
// parallel creates it); the deploy then fails with a clear error instead of starting a container that can't resolve.
var networkWait = 60 * time.Second

type RunResult struct {
	Changed     bool   `json:"changed"`
	ContainerID string `json:"container_id"`
}

var containerNameRe = regexp.MustCompile(`^[a-zA-Z0-9][a-zA-Z0-9_.-]+$`)

// Labels set on every managed container.
const (
	LabelManaged  = "falak.managed"
	LabelSpecHash = "falak.spec-hash"
	LabelSite     = "falak.site"
	LabelColor    = "falak.color"
)

// specHash hashes everything that defines the container (not pull policy or credentials).
func (p RunPayload) specHash() string {
	q := p
	q.Pull, q.Auth, q.Mask = "", nil, nil
	b, _ := json.Marshal(q)
	sum := sha256.Sum256(b)
	return hex.EncodeToString(sum[:])
}

func (p RunPayload) createBody(hash string) CreateBody {
	b := CreateBody{Image: p.Image, Cmd: p.Command, Entrypoint: p.Entrypoint, User: p.User,
		Labels: map[string]string{LabelManaged: "true", LabelSpecHash: hash}}
	for k, v := range p.Labels {
		if k != LabelManaged && k != LabelSpecHash {
			b.Labels[k] = v
		}
	}
	keys := make([]string, 0, len(p.Env))
	for k := range p.Env {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, k := range keys {
		b.Env = append(b.Env, k+"="+p.Env[k])
	}
	for _, pt := range p.Ports {
		proto := pt.Protocol
		if proto == "" {
			proto = "tcp"
		}
		key := strconv.Itoa(pt.ContainerPort) + "/" + proto
		if b.ExposedPorts == nil {
			b.ExposedPorts = map[string]struct{}{}
			b.HostConfig.PortBindings = map[string][]PortBinding{}
		}
		b.ExposedPorts[key] = struct{}{}
		if pt.HostPort > 0 {
			ip := pt.HostIP
			if ip == "" {
				ip = "127.0.0.1"
			}
			b.HostConfig.PortBindings[key] = append(b.HostConfig.PortBindings[key], PortBinding{HostIP: ip, HostPort: strconv.Itoa(pt.HostPort)})
		}
	}
	for _, v := range p.Volumes {
		bind := v.Source + ":" + v.Target
		if v.ReadOnly {
			bind += ":ro"
		}
		b.HostConfig.Binds = append(b.HostConfig.Binds, bind)
	}
	b.HostConfig.NetworkMode = p.Network
	p.Limits.apply(&b.HostConfig)
	return b
}

func (s *Service) run(ctx context.Context, p RunPayload, st commands.Stream) (any, error) {
	if !containerNameRe.MatchString(p.Name) || p.Image == "" {
		return nil, &commands.PayloadError{Err: fmt.Errorf("name and image are required")}
	}
	if err := p.Limits.validate(); err != nil {
		return nil, &commands.PayloadError{Err: err}
	}
	id, changed, err := s.ensureContainer(ctx, p, st)
	return RunResult{Changed: changed, ContainerID: id}, err
}

// ensureContainer converges a named container to the payload spec.
func (s *Service) ensureContainer(ctx context.Context, p RunPayload, st commands.Stream) (string, bool, error) {
	hash := p.specHash()
	cur, exists, err := s.c.ContainerInspect(ctx, p.Name)
	if err != nil {
		return "", false, err
	}
	if exists && cur.Config.Labels[LabelSpecHash] == hash && p.Pull != "always" {
		if cur.State.Running {
			return cur.ID, false, nil
		}
		return cur.ID, true, s.c.ContainerStart(ctx, cur.ID)
	}
	if err := s.ensureImage(ctx, p.Image, p.Pull, p.Auth, st); err != nil {
		return "", false, err
	}
	if exists && p.Pull == "always" && cur.Config.Labels[LabelSpecHash] == hash {
		// Same spec: only recreate if the pulled image is newer than the container's.
		if id, ok, _ := s.c.ImageInspect(ctx, p.Image); ok && id == cur.Image {
			if cur.State.Running {
				return cur.ID, false, nil
			}
			return cur.ID, true, s.c.ContainerStart(ctx, cur.ID)
		}
	}
	// Before the current container is touched: a network that never appears fails the run and leaves it in place.
	if err := s.awaitNetworks(ctx, p.Networks, st); err != nil {
		return "", false, err
	}
	if exists {
		s.log.Info("recreating container", "name", p.Name)
		if _, err := s.c.ContainerStop(ctx, cur.ID, 10*time.Second); err != nil && !IsNotFound(err) {
			return "", false, err
		}
		if err := s.c.ContainerRemove(ctx, cur.ID); err != nil {
			return "", false, err
		}
	}
	if len(p.SecretFiles) > 0 {
		if err := s.writeSecrets(p.Name, numericOwner(p.User), p.SecretFiles); err != nil {
			return "", false, err
		}
	} else {
		s.removeSecrets(p.Name)
	}
	body := p.createBody(hash)
	s.withSecrets(&body, p.Name, p.User, p.SecretFiles)
	id, err := s.c.ContainerCreate(ctx, p.Name, body)
	if err != nil {
		return "", false, err
	}
	for _, n := range p.Networks {
		if err := s.c.NetworkConnect(ctx, n.Name, id, n.Aliases); err != nil {
			_ = s.c.ContainerRemove(ctx, id)
			return "", false, fmt.Errorf("joining network %s: %w", n.Name, err)
		}
	}
	return id, true, s.c.ContainerStart(ctx, id)
}

// awaitNetworks waits (up to networkWait) for the networks a container joins. A network a compose project owns is
// created up front with Compose's labels (the project adopts it on its first `up`); any other network belongs to
// someone else and is only waited for.
func (s *Service) awaitNetworks(ctx context.Context, joins []NetworkJoin, st commands.Stream) error {
	for _, n := range joins {
		if n.Environment {
			if err := s.ensureEnvironmentNetwork(ctx, n.Name); err != nil {
				return err
			}
			continue
		}
		deadline := time.Now().Add(networkWait)
		for said := false; ; said = true {
			ok, err := s.c.NetworkExists(ctx, n.Name)
			if err != nil {
				return err
			}
			if ok {
				break
			}
			if n.Compose != nil {
				if err := s.c.NetworkCreate(ctx, n.Name, n.Compose.labels()); err != nil {
					return fmt.Errorf("creating network %s for compose project %s: %w", n.Name, n.Compose.Project, err)
				}
				if st != nil {
					fmt.Fprintf(st.Stdout(), "created network %s (compose project %s adopts it)\n", n.Name, n.Compose.Project)
				}
				break
			}
			if time.Now().After(deadline) {
				return fmt.Errorf("network %s does not exist: deploy the compose stack it belongs to first, then redeploy this site", n.Name)
			}
			if !said && st != nil {
				fmt.Fprintf(st.Stdout(), "waiting for network %s (deploy the compose stack it belongs to)\n", n.Name)
			}
			select {
			case <-ctx.Done():
				return ctx.Err()
			case <-time.After(time.Second):
			}
		}
	}
	return nil
}

// ---- docker.stop ----

type StopPayload struct {
	Name     string `json:"name"`
	TimeoutS *int   `json:"timeout_s,omitempty"`
	Remove   bool   `json:"remove,omitempty"`
}

type ChangedResult struct {
	Changed bool `json:"changed"`
}

func (s *Service) stop(ctx context.Context, p StopPayload, _ commands.Stream) (any, error) {
	cur, exists, err := s.c.ContainerInspect(ctx, p.Name)
	if err != nil || !exists {
		return ChangedResult{}, err
	}
	t := 10
	if p.TimeoutS != nil {
		t = *p.TimeoutS
	}
	changed := false
	if cur.State.Running {
		stopped, err := s.c.ContainerStop(ctx, cur.ID, time.Duration(t)*time.Second)
		if err != nil {
			return nil, err
		}
		changed = stopped
	}
	if p.Remove {
		if err := s.c.ContainerRemove(ctx, cur.ID); err != nil {
			return nil, err
		}
		s.removeSecrets(p.Name)
		changed = true
	}
	return ChangedResult{Changed: changed}, nil
}

// ---- docker.prune ----

type PrunePayload struct {
	Containers *bool  `json:"containers,omitempty"`
	Images     string `json:"images,omitempty"`
	Volumes    bool   `json:"volumes,omitempty"`
	Networks   *bool  `json:"networks,omitempty"`
	BuildCache bool   `json:"build_cache,omitempty"`
	Until      string `json:"until,omitempty"`
}

type PruneResult struct {
	SpaceReclaimedBytes uint64 `json:"space_reclaimed_bytes"`
}

var untilRe = regexp.MustCompile(`^[0-9]+[smh]$`)

func (s *Service) prune(ctx context.Context, p PrunePayload, st commands.Stream) (any, error) {
	if p.Until != "" && !untilRe.MatchString(p.Until) {
		return nil, &commands.PayloadError{Err: fmt.Errorf("invalid until %q", p.Until)}
	}
	// Never the control plane's own stack (the control-plane machine as a server): its containers, images, volumes and
	// networks carry its compose project label. A stopped panel mid-update must not lose its volumes. The build cache
	// has no label filter (it is only cache).
	cp := os.Getenv("FALAK_PROJECT_NAME")
	if cp == "" {
		cp = "falak"
	}
	notCP := []string{LabelComposeProject + "=" + cp}
	until := func() map[string][]string {
		f := map[string][]string{"label!": notCP}
		if p.Until != "" {
			f["until"] = []string{p.Until}
		}
		return f
	}
	type step struct {
		kind    string
		filters map[string][]string
	}
	var steps []step
	if p.Containers == nil || *p.Containers {
		steps = append(steps, step{"containers", until()})
	}
	switch p.Images {
	case "", "dangling":
		f := until()
		f["dangling"] = []string{"true"}
		steps = append(steps, step{"images", f})
	case "all":
		f := until()
		f["dangling"] = []string{"false"}
		steps = append(steps, step{"images", f})
	case "none":
	default:
		return nil, &commands.PayloadError{Err: fmt.Errorf("invalid images mode %q", p.Images)}
	}
	if p.Volumes {
		steps = append(steps, step{"volumes", map[string][]string{"label!": notCP}}) // volumes prune does not support `until`
	}
	if p.Networks == nil || *p.Networks {
		steps = append(steps, step{"networks", until()})
	}
	if p.BuildCache {
		f := until()
		delete(f, "label!")
		steps = append(steps, step{"build", f})
	}
	var total uint64
	for _, stp := range steps {
		n, err := s.c.Prune(ctx, stp.kind, stp.filters)
		if err != nil {
			return nil, fmt.Errorf("prune %s: %w", stp.kind, err)
		}
		fmt.Fprintf(st.Stdout(), "pruned %s: %d bytes\n", stp.kind, n)
		total += n
	}
	return PruneResult{SpaceReclaimedBytes: total}, nil
}
