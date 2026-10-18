package docker

import (
	"context"
	"fmt"
	"io"
	"net/http"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/redact"
)

// SwapPayload is deploy.container.swap.
type SwapPayload struct {
	Site          string `json:"site"`
	Image         string `json:"image"`
	Pull          string `json:"pull,omitempty"`
	RegistryAuth  *Auth  `json:"registry_auth,omitempty"`
	ContainerPort int    `json:"container_port"`
	Ports         struct {
		Blue  int `json:"blue"`
		Green int `json:"green"`
	} `json:"ports"`
	Env      map[string]string `json:"env,omitempty"`
	Command  []string          `json:"command,omitempty"`
	Volumes  []VolumeSpec      `json:"volumes,omitempty"`
	Network  string            `json:"network,omitempty"`
	Networks []NetworkJoin     `json:"networks,omitempty"`
	// Limits apply to both colors (the restart policy defaults to unless-stopped).
	Limits
	Health      *HealthSpec       `json:"health,omitempty"`
	EdgeRouteID string            `json:"edge_route_id"`
	DrainS      *int              `json:"drain_s,omitempty"`
	Labels      map[string]string `json:"labels,omitempty"`
	// SecretFiles are mounted read-only at /run/secrets/<name> instead of being passed as env (the site's secrets
	// mode "files"); each color has its own directory.
	SecretFiles []SecretFile `json:"secret_files,omitempty"`
	// Mask names the secret variables of env.
	Mask []string `json:"mask,omitempty"`
}

// Secrets are the masked env values and the secret files.
func (p SwapPayload) Secrets() []string { return secretValues(p.Env, p.Mask, p.SecretFiles) }

// HealthSpec is the HTTP readiness probe.
type HealthSpec struct {
	Path         string `json:"path,omitempty"`
	ExpectStatus int    `json:"expect_status,omitempty"`
	TimeoutS     int    `json:"timeout_s,omitempty"`
	IntervalMS   int    `json:"interval_ms,omitempty"`
}

// SwapResult is the structured result.
type SwapResult struct {
	Changed       bool   `json:"changed"`
	ActiveColor   string `json:"active_color"`
	ContainerID   string `json:"container_id,omitempty"`
	Upstream      string `json:"upstream"`
	PreviousColor string `json:"previous_color,omitempty"`
}

func (p SwapPayload) port(color string) int {
	if color == "green" {
		return p.Ports.Green
	}
	return p.Ports.Blue
}

func other(color string) string {
	if color == "blue" {
		return "green"
	}
	return "blue"
}

// runSpec builds the docker.run spec for one color.
func (p SwapPayload) runSpec(color string) RunPayload {
	labels := map[string]string{LabelSite: p.Site, LabelColor: color}
	for k, v := range p.Labels {
		if k != LabelSite && k != LabelColor {
			labels[k] = v
		}
	}
	return RunPayload{
		Name: "falak-" + p.Site + "-" + color, Image: p.Image, Env: p.Env, Command: p.Command,
		Ports:   []PortSpec{{HostIP: "127.0.0.1", HostPort: p.port(color), ContainerPort: p.ContainerPort}},
		Volumes: p.Volumes, Network: p.Network, Networks: p.Networks, Labels: labels, Limits: p.Limits, SecretFiles: p.SecretFiles,
	}
}

// specHash is color-independent so the same deploy is recognized on either color.
func (p SwapPayload) specHash() string {
	r := p.runSpec("")
	r.Name, r.Ports = "", []PortSpec{{ContainerPort: p.ContainerPort}}
	delete(r.Labels, LabelColor)
	return r.specHash()
}

func (s *Service) swap(ctx context.Context, p SwapPayload, st commands.Stream) (any, error) {
	if p.Site == "" || p.Image == "" || p.ContainerPort == 0 || p.Ports.Blue == 0 || p.Ports.Green == 0 {
		return nil, &commands.PayloadError{Err: fmt.Errorf("site, image, container_port and ports are required")}
	}
	// The site names containers and their secret directories (removed and rewritten below).
	if !siteRe.MatchString(p.Site) {
		return nil, &commands.PayloadError{Err: fmt.Errorf("invalid site %q", p.Site)}
	}
	if err := p.Limits.validate(); err != nil {
		return nil, &commands.PayloadError{Err: err}
	}
	if err := hostNamespaceAllowed(p.Site, p.Network); err != nil {
		return nil, &commands.PayloadError{Err: err}
	}
	if p.Ports.Blue == p.Ports.Green {
		return nil, &commands.PayloadError{Err: fmt.Errorf("blue and green ports must differ")}
	}
	if s.opts.Upstreams == nil && p.EdgeRouteID != "" {
		return nil, fmt.Errorf("container.swap: no edge upstream setter configured")
	}
	h := HealthSpec{Path: "/", ExpectStatus: 200, TimeoutS: 60, IntervalMS: 1000}
	if p.Health != nil {
		if p.Health.Path != "" {
			h.Path = p.Health.Path
		}
		if p.Health.ExpectStatus != 0 {
			h.ExpectStatus = p.Health.ExpectStatus
		}
		if p.Health.TimeoutS > 0 {
			h.TimeoutS = p.Health.TimeoutS
		}
		if p.Health.IntervalMS > 0 {
			h.IntervalMS = p.Health.IntervalMS
		}
	}
	drain := 10 * time.Second
	if p.DrainS != nil {
		drain = time.Duration(*p.DrainS) * time.Second
	}
	hash := p.specHash()

	// Active color = newest running container of this site.
	list, err := s.c.ContainerList(ctx, true, []string{LabelSite + "=" + p.Site})
	if err != nil {
		return nil, err
	}
	var running []ContainerSummary
	for _, c := range list {
		if c.State == "running" && (c.Labels[LabelColor] == "blue" || c.Labels[LabelColor] == "green") {
			running = append(running, c)
		}
	}
	sort.Slice(running, func(i, j int) bool { return running[i].Created > running[j].Created })
	active := ""
	var activeC *ContainerSummary
	if len(running) > 0 {
		activeC = &running[0]
		active = activeC.Labels[LabelColor]
	}

	// Idempotent: same spec already live and healthy → just make sure the edge points at it.
	if activeC != nil && activeC.Labels["falak.swap-hash"] == hash && p.Pull != "always" {
		up := "127.0.0.1:" + strconv.Itoa(p.port(active))
		if s.healthy(ctx, p.port(active), h) {
			if err := s.setEdge(ctx, p.EdgeRouteID, up); err != nil {
				return nil, err
			}
			return SwapResult{Changed: false, ActiveColor: active, ContainerID: activeC.ID, Upstream: up}, nil
		}
	}

	target := "blue"
	if active != "" {
		target = other(active)
	}
	spec := p.runSpec(target)
	spec.Labels["falak.swap-hash"] = hash
	fmt.Fprintf(st.Stdout(), "starting %s (%s) on 127.0.0.1:%d\n", spec.Name, p.Image, p.port(target))
	if err := s.ensureImage(ctx, p.Image, p.Pull, p.RegistryAuth, st); err != nil {
		return nil, err
	}
	// Stale container of the target color (e.g. failed earlier swap) is replaced.
	if old, ok, err := s.c.ContainerInspect(ctx, spec.Name); err != nil {
		return nil, err
	} else if ok {
		_ = s.c.ContainerRemove(ctx, old.ID)
	}
	if err := s.awaitNetworks(ctx, spec.Networks, st); err != nil {
		return nil, err
	}
	s.removeSecrets(spec.Name)
	if len(spec.SecretFiles) > 0 {
		if err := s.writeSecrets(spec.Name, "", spec.SecretFiles); err != nil {
			return nil, err
		}
	}
	body := spec.createBody(spec.specHash())
	s.withSecrets(&body, spec.Name, "", spec.SecretFiles)
	id, err := s.c.ContainerCreate(ctx, spec.Name, body)
	if err != nil {
		s.removeSecrets(spec.Name)
		return nil, err
	}
	// A split-out compose service joins its stack's networks before it starts, so it resolves the stack's services
	// (and they it) from its first request.
	for _, n := range spec.Networks {
		if err := s.c.NetworkConnect(ctx, n.Name, id, n.Aliases); err != nil {
			_ = s.c.ContainerRemove(context.WithoutCancel(ctx), id)
			return nil, fmt.Errorf("joining network %s: %w", n.Name, err)
		}
	}
	if err := s.c.ContainerStart(ctx, id); err != nil {
		_ = s.c.ContainerRemove(context.WithoutCancel(ctx), id)
		s.removeSecrets(spec.Name)
		return nil, err
	}
	fail := func(err error) (any, error) {
		s.log.Warn("container swap failed, removing new container", "site", p.Site, "color", target, "err", err)
		_ = s.c.ContainerRemove(context.WithoutCancel(ctx), id)
		s.removeSecrets(spec.Name)
		return nil, err
	}
	if err := s.waitHealthy(ctx, id, p.port(target), h, st); err != nil {
		// The container (and its env) is removed next: what it printed is the only clue to why it never got healthy.
		s.reportContainer(ctx, id, st)
		return fail(err)
	}
	up := "127.0.0.1:" + strconv.Itoa(p.port(target))
	if err := s.setEdge(ctx, p.EdgeRouteID, up); err != nil {
		return fail(fmt.Errorf("switch edge upstream: %w", err))
	}
	if p.EdgeRouteID != "" {
		fmt.Fprintf(st.Stdout(), "edge route %s → %s\n", p.EdgeRouteID, up)
	}

	// Drain and retire every other container of this site.
	if len(running) > 0 && drain > 0 {
		select {
		case <-time.After(drain):
		case <-ctx.Done():
		}
	}
	for _, c := range list {
		if c.ID == id {
			continue
		}
		if c.State == "running" {
			if _, err := s.c.ContainerStop(context.WithoutCancel(ctx), c.ID, 30*time.Second); err != nil && !IsNotFound(err) {
				s.log.Warn("stop old container", "id", c.ID, "err", err)
			}
		}
		if err := s.c.ContainerRemove(context.WithoutCancel(ctx), c.ID); err != nil {
			s.log.Warn("remove old container", "id", c.ID, "err", err)
		} else {
			s.removeSecrets(containerName(c))
		}
	}
	return SwapResult{Changed: true, ActiveColor: target, ContainerID: id, Upstream: up, PreviousColor: active}, nil
}

func (s *Service) healthy(ctx context.Context, port int, h HealthSpec) bool {
	url := "http://127.0.0.1:" + strconv.Itoa(port) + h.Path
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return false
	}
	resp, err := s.opts.HTTP.Do(req)
	if err != nil {
		return false
	}
	_, _ = io.Copy(io.Discard, io.LimitReader(resp.Body, 64<<10))
	resp.Body.Close()
	return resp.StatusCode == h.ExpectStatus
}

func (s *Service) waitHealthy(ctx context.Context, id string, port int, h HealthSpec, st commands.Stream) error {
	deadline := time.Now().Add(time.Duration(h.TimeoutS) * time.Second)
	interval := time.Duration(h.IntervalMS) * time.Millisecond
	for {
		if s.healthy(ctx, port, h) {
			fmt.Fprintf(st.Stdout(), "health check passed on :%d%s\n", port, h.Path)
			return nil
		}
		// A container that stopped won't get healthy: say so now rather than at the deadline.
		if c, ok, err := s.c.ContainerInspect(ctx, id); err == nil && ok && !c.State.Running {
			return fmt.Errorf("the container stopped before the health check %s passed (%s)", h.Path, containerState(c))
		}
		if time.Now().After(deadline) {
			return fmt.Errorf("health check %s on :%d did not return %d within %ds", h.Path, port, h.ExpectStatus, h.TimeoutS)
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(interval):
		}
	}
}

// setEdge points the site's edge route at the new container. Sites without a route (no domain: a compose service split
// out into its own site that only its stack reaches, over the stack's network) have nothing to switch.
func (s *Service) setEdge(ctx context.Context, routeID, upstream string) error {
	if routeID == "" {
		return nil
	}
	return s.opts.Upstreams.SetUpstreams(ctx, routeID, []string{upstream})
}

// What reportContainer streams: at most reportLines lines of at most reportLineMax bytes each.
const (
	reportLines   = 50
	reportLineMax = 500
)

// reportContainer streams a failed container's state and the last lines it printed (stdout and stderr, with their
// timestamps) into the command output, before the container is removed. Secret values are masked like any output.
func (s *Service) reportContainer(ctx context.Context, id string, st commands.Stream) {
	ctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 10*time.Second)
	defer cancel()
	out := st.Stdout()
	if c, ok, err := s.c.ContainerInspect(ctx, id); err == nil && ok {
		fmt.Fprintf(out, "container state: %s\n", containerState(c))
	}
	var b limitedBuffer
	b.max = reportLines * (reportLineMax + 64)
	if err := s.c.ContainerLogTail(ctx, id, reportLines, &b); err != nil {
		fmt.Fprintf(out, "container output: unavailable (%v)\n", err)
		return
	}
	text := b.String()
	if b.cut {
		// The front was cut mid-line: start at the next whole line (a secret straddling the cut can't be masked).
		_, text, _ = strings.Cut(text, "\n")
	}
	text = strings.TrimRight(strings.ReplaceAll(text, "\r\n", "\n"), "\n")
	if text == "" {
		fmt.Fprintln(out, "container output: (none)")
		return
	}
	lines := strings.Split(text, "\n")
	if len(lines) > reportLines {
		lines = lines[len(lines)-reportLines:]
	}
	fmt.Fprintf(out, "container output (last %d lines):\n", len(lines))
	secrets := redact.FromContext(ctx)
	for _, l := range lines {
		// Masked whole, then cut: a cut must not split a secret the masker would no longer recognize.
		l = secrets.String(l)
		if len(l) > reportLineMax {
			l = strings.ToValidUTF8(l[:reportLineMax], "") + " …"
		}
		fmt.Fprintf(out, "  | %s\n", l)
	}
}

// containerState is "running", or "exited with code N" plus "killed: out of memory" and the restart count.
func containerState(c *Container) string {
	state := "running"
	if !c.State.Running {
		state = fmt.Sprintf("%s with code %d", firstNonEmpty(c.State.Status, "exited"), c.State.ExitCode)
	}
	if c.State.OOMKilled {
		state += ", killed: out of memory"
	}
	if c.RestartCount > 0 {
		state += fmt.Sprintf(", restarted %d times", c.RestartCount)
	}
	return state
}

func firstNonEmpty(a, b string) string {
	if a != "" {
		return a
	}
	return b
}

// limitedBuffer keeps the last max bytes written to it; cut says the front was dropped.
type limitedBuffer struct {
	buf []byte
	max int
	cut bool
}

func (l *limitedBuffer) Write(p []byte) (int, error) {
	l.buf = append(l.buf, p...)
	if len(l.buf) > l.max {
		l.buf = l.buf[len(l.buf)-l.max:]
		l.cut = true
	}
	return len(p), nil
}

func (l *limitedBuffer) String() string { return string(l.buf) }
