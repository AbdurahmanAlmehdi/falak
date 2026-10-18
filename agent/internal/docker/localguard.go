package docker

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path"
	"sort"
	"strings"

	"github.com/OthmanHaba/falak/agent/internal/commands"
)

// ---- the control-plane machine (Local.Enabled): what tenant workloads may not do there ----
//
// A workload that shares the host's namespaces, mounts the Docker socket or the host's system directories, joins the
// control plane's networks or volumes, or gets extra capabilities would reach the panel's database, its secrets or
// Docker itself. Compose projects are checked on their resolved config (checkResolved), Docker sites on their payload
// (checkSite). Regular servers are never checked.

// cpProject is the control plane's compose project: local-edge.env, then the agent's environment, then falak.
func cpProject() string {
	if Local.ProjectName != "" {
		return Local.ProjectName
	}
	if p := os.Getenv("FALAK_PROJECT_NAME"); p != "" {
		return p
	}
	return "falak"
}

// forbiddenBinds may not be bind-mounted, nor any directory above or below them (/run holds docker.sock, / all of
// them).
var forbiddenBinds = []string{"/var/run/docker.sock", "/run/docker.sock", "/etc", "/opt/falak", "/var/lib/docker", "/proc", "/sys"}

// safeCaps are the capabilities a workload may add.
var safeCaps = map[string]bool{"NET_BIND_SERVICE": true, "CHOWN": true, "SETUID": true, "SETGID": true, "DAC_OVERRIDE": true,
	"FOWNER": true, "KILL": true}

func notAllowed(what, format string, a ...any) error {
	return &commands.PayloadError{Err: fmt.Errorf("%s: %s is not allowed on the control-plane machine", what, fmt.Sprintf(format, a...))}
}

// cpGuard checks references against the control plane's stack (its compose project's networks, volumes, containers).
type cpGuard struct {
	s       *Service
	ctx     context.Context
	project string
	nets    map[string]bool // networks labelled with the control plane's project (exact names, even explicit ones)
}

func (s *Service) newGuard(ctx context.Context) (*cpGuard, error) {
	g := &cpGuard{s: s, ctx: ctx, project: cpProject(), nets: map[string]bool{}}
	names, err := s.c.NetworkList(ctx, []string{LabelComposeProject + "=" + g.project})
	if err != nil {
		return nil, fmt.Errorf("listing the control plane's networks: %w", err)
	}
	for _, n := range names {
		g.nets[n] = true
	}
	return g, nil
}

func (g *cpGuard) ownName(name string) bool {
	return name == g.project || strings.HasPrefix(name, g.project+"_")
}

func (g *cpGuard) network(what, name string) error {
	if g.ownName(name) || g.nets[name] {
		return notAllowed(what, "network %s (the control plane's)", name)
	}
	return nil
}

func (g *cpGuard) volume(what, name string) error {
	if g.ownName(name) {
		return notAllowed(what, "volume %s (the control plane's)", name)
	}
	v, ok, err := g.s.c.VolumeInspect(g.ctx, name)
	if err != nil {
		return err
	}
	if ok && v.Labels[LabelComposeProject] == g.project {
		return notAllowed(what, "volume %s (the control plane's)", name)
	}
	return nil
}

func (g *cpGuard) container(what, field, name string) error {
	c, ok, err := g.s.c.ContainerInspect(g.ctx, name)
	if err != nil {
		return err
	}
	if ok && c.Config.Labels[LabelComposeProject] == g.project {
		return notAllowed(what, "%s of container %s (the control plane's)", field, name)
	}
	return nil
}

// namespace checks network_mode / pid / ipc: never the host's, never a control plane container's.
func (g *cpGuard) namespace(what, field, v string) error {
	if v == "host" {
		return notAllowed(what, "%s host", field)
	}
	if c, ok := strings.CutPrefix(v, "container:"); ok {
		return g.container(what, field, c)
	}
	return nil
}

// bind checks a bind mount's host path (symlinks resolved on the host, so a link planted in a release directory
// does not get around it).
func (g *cpGuard) bind(what, src string) error {
	p := path.Clean(src)
	if real, err := g.s.opts.FS.EvalSymlinks(p); err == nil {
		p = path.Clean(real)
	} else {
		// Missing: Docker creates it, through any symlink on the way (a dangling link to /etc/x would land there).
		for q := p; q != "/"; q = path.Dir(q) {
			if fi, err := os.Lstat(g.s.opts.FS.P(q)); err == nil && fi.Mode()&os.ModeSymlink != 0 {
				return notAllowed(what, "bind mount of %s (through an unresolvable symlink)", src)
			}
		}
	}
	for _, f := range forbiddenBinds {
		if p == f || strings.HasPrefix(p, f+"/") || strings.HasPrefix(f, strings.TrimSuffix(p, "/")+"/") {
			return notAllowed(what, "bind mount of %s", src)
		}
	}
	return nil
}

func checkCaps(what string, caps []string) error {
	for _, c := range caps {
		if n := strings.TrimPrefix(strings.ToUpper(c), "CAP_"); !safeCaps[n] {
			return notAllowed(what, "cap_add %s", c)
		}
	}
	return nil
}

func checkSecurityOpts(what string, opts []string) error {
	for _, o := range opts {
		if strings.Contains(strings.ToLower(o), "unconfined") {
			return notAllowed(what, "security_opt %s", o)
		}
	}
	return nil
}

// resolvedCompose is the part of `docker compose config --format json` the checks read.
type resolvedCompose struct {
	Services map[string]struct {
		NetworkMode string                     `json:"network_mode"`
		Pid         string                     `json:"pid"`
		Ipc         string                     `json:"ipc"`
		UsernsMode  string                     `json:"userns_mode"`
		Cgroup      string                     `json:"cgroup"`
		Privileged  bool                       `json:"privileged"`
		Networks    map[string]json.RawMessage `json:"networks"`
		Volumes     []struct {
			Type   string `json:"type"`
			Source string `json:"source"`
		} `json:"volumes"`
		VolumesFrom []string          `json:"volumes_from"`
		Devices     []json.RawMessage `json:"devices"`
		CapAdd      []string          `json:"cap_add"`
		SecurityOpt []string          `json:"security_opt"`
	} `json:"services"`
	Networks map[string]struct {
		Name string `json:"name"`
	} `json:"networks"`
	Volumes map[string]struct {
		Name string `json:"name"`
	} `json:"volumes"`
}

// checkResolved refuses a compose project that would reach the host or the control plane's stack.
func (s *Service) checkResolved(ctx context.Context, project string, cfg resolvedCompose) error {
	g, err := s.newGuard(ctx)
	if err != nil {
		return err
	}
	if g.ownName(project) {
		return notAllowed("project "+project, "name %s (the control plane's project)", project)
	}
	names := make([]string, 0, len(cfg.Services))
	for n := range cfg.Services {
		names = append(names, n)
	}
	sort.Strings(names)
	for _, name := range names {
		sv, what := cfg.Services[name], "service "+name
		switch {
		case sv.Privileged:
			return notAllowed(what, "privileged")
		case sv.UsernsMode == "host":
			return notAllowed(what, "userns_mode host")
		case sv.Cgroup == "host":
			return notAllowed(what, "cgroup host")
		case len(sv.Devices) > 0:
			return notAllowed(what, "devices")
		}
		for _, ns := range [][2]string{{"network_mode", sv.NetworkMode}, {"pid", sv.Pid}, {"ipc", sv.Ipc}} {
			if err := g.namespace(what, ns[0], ns[1]); err != nil {
				return err
			}
		}
		if err := checkCaps(what, sv.CapAdd); err != nil {
			return err
		}
		if err := checkSecurityOpts(what, sv.SecurityOpt); err != nil {
			return err
		}
		for key := range sv.Networks {
			n := cfg.Networks[key].Name
			if n == "" {
				n = project + "_" + key
			}
			if err := g.network(what, n); err != nil {
				return err
			}
		}
		for _, v := range sv.Volumes {
			switch v.Type {
			case "bind":
				err = g.bind(what, v.Source)
			case "volume":
				if v.Source == "" {
					continue // anonymous
				}
				n := cfg.Volumes[v.Source].Name
				if n == "" {
					n = project + "_" + v.Source
				}
				err = g.volume(what, n)
			}
			if err != nil {
				return err
			}
		}
		for _, vf := range sv.VolumesFrom {
			if c, ok := strings.CutPrefix(vf, "container:"); ok {
				c, _, _ = strings.Cut(c, ":") // container:<name>[:ro|rw]
				if err := g.container(what, "volumes_from", c); err != nil {
					return err
				}
			}
		}
	}
	return nil
}

// checkSite refuses a Docker site (docker.run, deploy.container.swap) that would reach the host or the control
// plane's stack; a no-op on other servers.
func (s *Service) checkSite(ctx context.Context, what, network string, networks []NetworkJoin, volumes []VolumeSpec) error {
	if !Local.Enabled {
		return nil
	}
	g, err := s.newGuard(ctx)
	if err != nil {
		return err
	}
	if err := g.namespace(what, "network mode", network); err != nil {
		return err
	}
	if network != "" && !strings.HasPrefix(network, "container:") {
		if err := g.network(what, network); err != nil {
			return err
		}
	}
	for _, n := range networks {
		if err := g.network(what, n.Name); err != nil {
			return err
		}
	}
	for _, v := range volumes {
		if path.IsAbs(v.Source) {
			err = g.bind(what, v.Source)
		} else {
			err = g.volume(what, v.Source)
		}
		if err != nil {
			return err
		}
	}
	return nil
}
