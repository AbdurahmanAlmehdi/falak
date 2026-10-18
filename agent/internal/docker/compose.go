package docker

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/envlinks"
	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// ---- docker.compose.* (docker compose CLI for up/pull/down; the Engine API for ps/restart) ----

// Compose labels set by `docker compose` on every container of a project.
const (
	LabelComposeProject = "com.docker.compose.project"
	LabelComposeService = "com.docker.compose.service"
	LabelComposeOneOff  = "com.docker.compose.oneoff"
)

// LabelEnvironmentNetwork "none" keeps a compose service off the environment networks (a template's label).
const LabelEnvironmentNetwork = "falak.environment_network"

type ComposeFile struct {
	Name    string `json:"name"`
	Content string `json:"content"`
}

// ComposeAsset is a repository file the project mounts or reads (bind-mount source, env_file, configs/secrets
// `file:`), written to <directory>/repo/<path>; the rendered compose file points there.
type ComposeAsset struct {
	Path    string `json:"path"`
	Content string `json:"content"` // base64
	Mode    int    `json:"mode,omitempty"`
}

// AssetsDir is the directory of a compose release holding its repository files.
const AssetsDir = "repo"

type ComposeUpPayload struct {
	Project        string            `json:"project"`
	Directory      string            `json:"directory"`
	Files          []ComposeFile     `json:"files,omitempty"`
	Assets         []ComposeAsset    `json:"assets,omitempty"`
	Env            map[string]string `json:"env,omitempty"`
	Pull           string            `json:"pull,omitempty"`
	RemoveOrphans  *bool             `json:"remove_orphans,omitempty"`
	Wait           bool              `json:"wait,omitempty"`
	WaitTimeoutS   int               `json:"wait_timeout_s,omitempty"`
	ProjectEnvFile string            `json:"project_env_file,omitempty"`
	RegistryAuth   *Auth             `json:"registry_auth,omitempty"`
	// Services starts only these (and what they depend on; feature compose.up.services): a stack's bootstrap pass
	// for the services its split-out sites use, before those sites and then the full stack deploy.
	Services []string `json:"services,omitempty"`
	// Mask names the secret variables of env.
	Mask []string `json:"mask,omitempty"`
	// JoinNetworks are environment networks (falak-env-<id>), where the environment's databases answer by name: created
	// before `up` (the control plane's override file declares them for every service), joined after it by any container
	// still missing them.
	JoinNetworks []string `json:"join_networks,omitempty"`
}

// Secrets are the masked env values.
func (p ComposeUpPayload) Secrets() []string { return secretValues(p.Env, p.Mask, nil) }

type ComposePullPayload struct {
	Project        string            `json:"project"`
	Directory      string            `json:"directory"`
	Files          []ComposeFile     `json:"files,omitempty"`
	Assets         []ComposeAsset    `json:"assets,omitempty"`
	Env            map[string]string `json:"env,omitempty"`
	ProjectEnvFile string            `json:"project_env_file,omitempty"`
	RegistryAuth   *Auth             `json:"registry_auth,omitempty"`
	Services       []string          `json:"services,omitempty"`
	Mask           []string          `json:"mask,omitempty"`
}

// Secrets are the masked env values.
func (p ComposePullPayload) Secrets() []string { return secretValues(p.Env, p.Mask, nil) }

type ComposeDownPayload struct {
	Project   string `json:"project"`
	Directory string `json:"directory"`
	Volumes   bool   `json:"volumes,omitempty"`
}

type ComposePsPayload struct {
	Project string `json:"project"`
	Stats   bool   `json:"stats,omitempty"`
}

type ComposeRestartPayload struct {
	Project  string   `json:"project"`
	Services []string `json:"services,omitempty"`
	TimeoutS *int     `json:"timeout_s,omitempty"`
}

type ExitResult struct {
	ExitCode int `json:"exit_code"`
}

// ComposeUpResult is the result of docker.compose.up: exit code plus the project's services after up.
type ComposeUpResult struct {
	ExitCode int             `json:"exit_code"`
	Services []ServiceStatus `json:"services,omitempty"`
}

// ComposePsResult is the result of docker.compose.ps.
type ComposePsResult struct {
	Services []ServiceStatus `json:"services"`
}

// ComposeRestartResult lists restarted containers' services.
type ComposeRestartResult struct {
	Restarted []string `json:"restarted"`
}

// ServiceStatus is one container of a compose project.
type ServiceStatus struct {
	Service       string       `json:"service"`
	ContainerID   string       `json:"container_id"`
	ContainerName string       `json:"container_name,omitempty"`
	State         string       `json:"state"`
	Health        string       `json:"health,omitempty"`
	ExitCode      *int         `json:"exit_code,omitempty"`
	Image         string       `json:"image"`
	ImageDigest   string       `json:"image_digest,omitempty"`
	Ports         []PortStatus `json:"ports,omitempty"`
	Restarts      int          `json:"restarts"`
	StartedAt     string       `json:"started_at,omitempty"`
	CPUPercent    *float64     `json:"cpu_percent,omitempty"`
	MemoryBytes   *int64       `json:"memory_bytes,omitempty"`
	MemoryLimit   *int64       `json:"memory_limit_bytes,omitempty"`
}

// PortStatus is a published (or exposed) container port.
type PortStatus struct {
	HostIP        string `json:"host_ip,omitempty"`
	HostPort      int    `json:"host_port,omitempty"`
	ContainerPort int    `json:"container_port"`
	Protocol      string `json:"protocol"`
}

var projectRe = regexp.MustCompile(`^[a-z0-9][a-z0-9_-]*$`)
var fileNameRe = regexp.MustCompile(`^[A-Za-z0-9_.-]+$`)
var serviceNameRe = regexp.MustCompile(`^[a-zA-Z0-9][a-zA-Z0-9_.-]*$`)

func validFileName(n string) bool { return fileNameRe.MatchString(n) && n != "." && n != ".." }

// validAssetPath: a relative repository path (any name a repository may hold, e.g. "logo@2x.png" or "my file.txt")
// without ".", ".." or empty segments, backslashes or control characters. Same rule as falak-builder and the
// control plane.
func validAssetPath(p string) bool {
	if p == "" || len(p) > 512 || strings.HasPrefix(p, "/") || strings.ContainsRune(p, '\\') {
		return false
	}
	for _, r := range p {
		if r < 0x20 || r == 0x7f {
			return false
		}
	}
	for _, seg := range strings.Split(p, "/") {
		if seg == "" || seg == "." || seg == ".." {
			return false
		}
	}
	return true
}

// prepare validates the project, writes the files and returns the global compose args
// (-p, --env-file, -f …).
func (s *Service) prepare(project, dir string, files []ComposeFile, envFile string, assets []ComposeAsset) ([]string, error) {
	if !projectRe.MatchString(project) || !path.IsAbs(dir) {
		return nil, &commands.PayloadError{Err: fmt.Errorf("invalid project or directory")}
	}
	if envFile != "" && !validFileName(envFile) {
		return nil, &commands.PayloadError{Err: fmt.Errorf("invalid project_env_file %q", envFile)}
	}
	for _, f := range files {
		if !validFileName(f.Name) {
			return nil, &commands.PayloadError{Err: fmt.Errorf("invalid file name %q", f.Name)}
		}
	}
	decoded := make([][]byte, len(assets))
	for i, a := range assets {
		if !validAssetPath(a.Path) {
			return nil, &commands.PayloadError{Err: fmt.Errorf("invalid asset path %q", a.Path)}
		}
		if a.Mode != 0 && a.Mode != 0o644 && a.Mode != 0o755 {
			return nil, &commands.PayloadError{Err: fmt.Errorf("asset %s: mode must be 0644 or 0755", a.Path)}
		}
		data, err := base64.StdEncoding.DecodeString(a.Content)
		if err != nil {
			return nil, &commands.PayloadError{Err: fmt.Errorf("asset %s: content is not base64", a.Path)}
		}
		decoded[i] = data
	}
	if err := s.opts.FS.MkdirAll(dir, 0o750); err != nil {
		return nil, err
	}
	// Containers can write into a release directory (bind mounts of ./ or ./repo/…) and plant symlinks there, and
	// rollbacks write into an old release again: every write below stays inside the release directory (os.Root) and
	// replaces entries instead of writing through them.
	release, err := os.OpenRoot(s.opts.FS.P(dir))
	if err != nil {
		return nil, err
	}
	defer release.Close()
	if assets != nil {
		if err := writeAssets(release, assets, decoded); err != nil {
			return nil, err
		}
	}
	args := []string{"compose", "-p", project}
	envArg := envFile
	for _, f := range files {
		if !strings.HasPrefix(f.Name, ".env") && f.Name != envFile {
			if err := replaceFile(release, f.Name, []byte(f.Content), 0o640); err != nil {
				return nil, err
			}
			args = append(args, "-f", f.Name)
			continue
		}
		// Env files hold secrets: on the tmpfs only (root, 0400); the release links to them, so `--env-file .env`
		// in leader commands keeps working, and after a reboot site.env.write restores them.
		target, err := s.writeComposeEnv(project, f.Name, f.Content)
		if err != nil {
			return nil, err
		}
		if err := replaceLink(release, f.Name, target); err != nil {
			return nil, err
		}
		if s.opts.Links != nil {
			if err := s.opts.Links.Record(project, filepath.Join(s.opts.FS.P(dir), f.Name), target); err != nil {
				s.log.Warn("record env link", "err", err)
			}
		}
		if f.Name == envFile {
			envArg = target
		}
	}
	if envArg != "" {
		args = append(args[:3], append([]string{"--env-file", envArg}, args[3:]...)...)
	}
	return args, nil
}

// HostOverrideFile is the agent's own compose override of a project, the last -f file: in the env directory (root
// only, outside the release a container or a user file could write), compose-<project>.host.yaml.
func (s *Service) HostOverrideFile(project string) string {
	return filepath.Join(s.opts.EnvDir, "compose-"+project+".host.yaml")
}

// localCompose runs on the control-plane machine before `up`. It resolves the project exactly as `up` will (compose
// config: include, extends, profiles, interpolation), refuses what would reach the host or the control plane's own
// stack (checkResolved), and puts every service under the workload slice with an override file. Any failure fails the
// deploy: a service must never run outside the slice there.
func (s *Service) localCompose(ctx context.Context, project, dir string, env map[string]string, args []string, st commands.Stream) ([]string, error) {
	// Stdout is not streamed: the resolved config holds the interpolated secrets.
	cmd := runner.Cmd{Name: "docker", Args: append(append([]string(nil), args...), "config", "--format", "json"), Dir: s.opts.FS.P(dir),
		Env: composeEnv(env), Stderr: st.Stderr()}
	res, err := s.opts.Runner.Run(ctx, cmd)
	if err == nil && res.ExitCode != 0 {
		err = fmt.Errorf("exit %d", res.ExitCode)
	}
	if err != nil {
		return nil, fmt.Errorf("docker compose config (resolving the services of %s on the control-plane machine): %w", project, err)
	}
	var cfg resolvedCompose
	if err := json.Unmarshal(res.Stdout, &cfg); err != nil {
		return nil, fmt.Errorf("docker compose config of %s: %w", project, err)
	}
	if err := s.checkResolved(ctx, project, cfg); err != nil {
		return nil, err
	}
	names := make([]string, 0, len(cfg.Services))
	for name := range cfg.Services {
		names = append(names, name)
	}
	sort.Strings(names)
	override := map[string]any{}
	for _, name := range names {
		override[name] = map[string]string{"cgroup_parent": Local.WorkloadSlice}
	}
	if Local.WorkloadSlice == "" || len(names) == 0 {
		return args, nil
	}
	b, _ := json.MarshalIndent(map[string]any{"services": override}, "", "  ") // JSON is YAML
	if err := envlinks.EnsureDir(s.opts.FS.P(s.opts.EnvDir)); err != nil {
		return nil, err
	}
	f := s.HostOverrideFile(project)
	if _, err := s.opts.FS.WriteFile(f, append(b, '\n'), 0o600); err != nil {
		return nil, err
	}
	return append(args, "-f", s.opts.FS.P(f)), nil
}

// writeComposeEnv writes a compose env file to the tmpfs and returns its real path.
func (s *Service) writeComposeEnv(project, name, content string) (string, error) {
	if err := envlinks.EnsureDir(s.opts.FS.P(s.opts.EnvDir)); err != nil {
		return "", err
	}
	host := envlinks.ComposeEnvFile(s.opts.EnvDir, project, name)
	if _, err := s.opts.FS.WriteFile(host, []byte(content), 0o400); err != nil {
		return "", err
	}
	return s.opts.FS.P(host), nil
}

// replaceLink makes name a symlink to target, replacing whatever is there (never writing through it).
func replaceLink(root *os.Root, name, target string) error {
	if cur, err := root.Readlink(name); err == nil && cur == target {
		return nil
	}
	tmp := "." + name + ".falak-link"
	if err := root.RemoveAll(tmp); err != nil {
		return err
	}
	if err := root.Symlink(target, tmp); err != nil {
		return err
	}
	return root.Rename(tmp, name)
}

// writeAssets builds repo/ afresh: repo.tmp is removed (RemoveAll never follows symlinks), filled through a root
// opened on it, and renamed over repo.
func writeAssets(release *os.Root, assets []ComposeAsset, decoded [][]byte) error {
	tmp := AssetsDir + ".tmp"
	if err := release.RemoveAll(tmp); err != nil {
		return err
	}
	if err := release.Mkdir(tmp, 0o755); err != nil {
		return err
	}
	root, err := release.OpenRoot(tmp)
	if err != nil {
		return err
	}
	defer root.Close()
	for i, a := range assets {
		mode := os.FileMode(0o644)
		if a.Mode == 0o755 {
			mode = 0o755
		}
		if d := path.Dir(a.Path); d != "." {
			if err := root.MkdirAll(d, 0o755); err != nil {
				return fmt.Errorf("asset %s: %w", a.Path, err)
			}
		}
		if err := createFile(root, a.Path, decoded[i], mode); err != nil {
			return fmt.Errorf("asset %s: %w", a.Path, err)
		}
	}
	if err := release.RemoveAll(AssetsDir); err != nil {
		return err
	}
	return release.Rename(tmp, AssetsDir)
}

// replaceFile writes name atomically: a new file (O_EXCL, so never through a link) renamed over the old entry.
func replaceFile(root *os.Root, name string, data []byte, mode os.FileMode) error {
	tmp := "." + name + ".falak-tmp"
	if err := root.RemoveAll(tmp); err != nil {
		return err
	}
	if err := createFile(root, tmp, data, mode); err != nil {
		return err
	}
	return root.Rename(tmp, name)
}

// createFile creates a new file (fails if anything exists there) and sets its mode on the open descriptor.
func createFile(root *os.Root, name string, data []byte, mode os.FileMode) error {
	f, err := root.OpenFile(name, os.O_WRONLY|os.O_CREATE|os.O_EXCL, mode)
	if err != nil {
		return err
	}
	if _, err := f.Write(data); err != nil {
		f.Close()
		return err
	}
	if err := f.Chmod(mode); err != nil {
		f.Close()
		return err
	}
	if err := f.Sync(); err != nil {
		f.Close()
		return err
	}
	return f.Close()
}

func (s *Service) composeUp(ctx context.Context, p ComposeUpPayload, st commands.Stream) (any, error) {
	switch p.Pull {
	case "":
		p.Pull = "missing"
	case "always", "missing", "never":
	default:
		return nil, &commands.PayloadError{Err: fmt.Errorf("invalid pull policy %q", p.Pull)}
	}
	if p.WaitTimeoutS < 0 || p.WaitTimeoutS > 3600 {
		return nil, &commands.PayloadError{Err: fmt.Errorf("wait_timeout_s out of range")}
	}
	for _, sv := range p.Services {
		if !serviceNameRe.MatchString(sv) {
			return nil, &commands.PayloadError{Err: fmt.Errorf("invalid service %q", sv)}
		}
	}
	for _, n := range p.JoinNetworks {
		if !EnvironmentNetworkRe.MatchString(n) {
			return nil, &commands.PayloadError{Err: fmt.Errorf("invalid join network %q", n)}
		}
	}
	args, err := s.prepare(p.Project, p.Directory, p.Files, p.ProjectEnvFile, p.Assets)
	if err != nil {
		return nil, err
	}
	if Local.Enabled {
		if args, err = s.localCompose(ctx, p.Project, p.Directory, p.Env, args, st); err != nil {
			return nil, err
		}
	}
	args = append(args, "up", "-d", "--pull", p.Pull)
	if p.RemoveOrphans == nil || *p.RemoveOrphans {
		args = append(args, "--remove-orphans")
	}
	if p.Wait {
		args = append(args, "--wait")
		if p.WaitTimeoutS > 0 {
			args = append(args, "--wait-timeout", strconv.Itoa(p.WaitTimeoutS))
		}
	}
	if len(p.Services) > 0 {
		args = append(append(args, "--"), p.Services...)
	}
	// The control plane's compose override declares them as external networks of every service, so containers start
	// on them (healthchecks reaching the environment's databases pass during --wait): they must exist first.
	for _, n := range p.JoinNetworks {
		if err := s.ensureEnvironmentNetwork(ctx, n); err != nil {
			return nil, err
		}
	}
	res, runErr := s.compose(ctx, p.Directory, p.Env, p.RegistryAuth, args, st)
	// Containers a stack's own file keeps off them (an older override, a service recreated by hand) join after `up`.
	if err := s.joinEnvironmentNetworks(ctx, p.Project, p.JoinNetworks, st); err != nil && runErr == nil {
		runErr = err
	}
	out := ComposeUpResult{ExitCode: res.ExitCode}
	// Report the project state even when up failed (unhealthy services explain the failure).
	if services, err := s.projectStatus(ctx, p.Project, false); err == nil {
		out.Services = services
		if runErr != nil {
			for _, sv := range services {
				if sv.Health == "unhealthy" || (sv.State != "running" && sv.State != "") {
					fmt.Fprintf(st.Stderr(), "service %s: %s%s\n", sv.Service, sv.State, map[bool]string{true: " (" + sv.Health + ")", false: ""}[sv.Health != ""])
				}
			}
		}
	} else {
		s.log.Debug("compose up: project status failed", "project", p.Project, "err", err)
	}
	return out, runErr
}

// joinEnvironmentNetworks connects every container of the project to the environment networks (compose recreates
// containers on its own networks only, so this runs after every `up`), except services labelled
// falak.environment_network=none (a code sandbox kept on an internal network must not reach the databases).
func (s *Service) joinEnvironmentNetworks(ctx context.Context, project string, networks []string, st commands.Stream) error {
	if len(networks) == 0 {
		return nil
	}
	list, err := s.c.ContainerList(ctx, true, []string{LabelComposeProject + "=" + project})
	if err != nil {
		return err
	}
	for _, n := range networks {
		if err := s.ensureEnvironmentNetwork(ctx, n); err != nil {
			return err
		}
		joined := 0
		for _, c := range list {
			if c.Labels[LabelEnvironmentNetwork] == "none" {
				continue
			}
			joined++
			if err := s.c.NetworkConnect(ctx, n, c.ID, nil); err != nil {
				return fmt.Errorf("joining %s to %s: %w", containerName(c), n, err)
			}
		}
		fmt.Fprintf(st.Stdout(), "%d container(s) of %s joined %s\n", joined, project, n)
	}
	return nil
}

func (s *Service) composePull(ctx context.Context, p ComposePullPayload, st commands.Stream) (any, error) {
	for _, sv := range p.Services {
		if !serviceNameRe.MatchString(sv) {
			return nil, &commands.PayloadError{Err: fmt.Errorf("invalid service %q", sv)}
		}
	}
	args, err := s.prepare(p.Project, p.Directory, p.Files, p.ProjectEnvFile, p.Assets)
	if err != nil {
		return nil, err
	}
	args = append(append(args, "pull", "--quiet"), p.Services...)
	res, err := s.compose(ctx, p.Directory, p.Env, p.RegistryAuth, args, st)
	return ExitResult{ExitCode: res.ExitCode}, err
}

func (s *Service) composeDown(ctx context.Context, p ComposeDownPayload, st commands.Stream) (any, error) {
	if !projectRe.MatchString(p.Project) || !path.IsAbs(p.Directory) {
		return nil, &commands.PayloadError{Err: fmt.Errorf("invalid project or directory")}
	}
	args := []string{"compose", "-p", p.Project, "down"}
	if p.Volumes {
		args = append(args, "--volumes")
	}
	dir := p.Directory
	if !s.opts.FS.Exists(dir) {
		dir = "" // compose down by project name works without the directory
	}
	s.releaseStackNetworks(ctx, p.Project, st)
	res, err := s.compose(ctx, dir, nil, nil, args, st)
	if err == nil && res.ExitCode == 0 {
		// The project is gone from this server (the site was deleted or moved away): its env files on the tmpfs hold
		// its secrets and must not outlive it until the next reboot.
		s.forgetComposeEnv(p.Project, st)
	}
	return ExitResult{ExitCode: res.ExitCode}, err
}

// forgetComposeEnv removes a compose project's env files from the env directory (compose-<project>.env,
// compose-<project>.<name>.env).
func (s *Service) forgetComposeEnv(project string, st commands.Stream) {
	dir := s.opts.FS.P(s.opts.EnvDir)
	for _, pattern := range []string{"compose-" + project + ".env", "compose-" + project + ".*.env", "compose-" + project + ".host.yaml"} {
		matches, _ := filepath.Glob(filepath.Join(dir, pattern))
		for _, m := range matches {
			if err := os.Remove(m); err != nil && !errors.Is(err, fs.ErrNotExist) {
				s.log.Warn("removing compose env file", "file", m, "err", err)
				continue
			}
			if st != nil {
				fmt.Fprintf(st.Stdout(), "removed %s\n", filepath.Base(m))
			}
		}
	}
}

// releaseStackNetworks detaches Falak's own containers (a split-out service run as its own site) from the project's
// networks, so `compose down` can remove them: Docker refuses to remove a network with active endpoints. Best effort:
// what fails here shows in compose's own output.
func (s *Service) releaseStackNetworks(ctx context.Context, project string, st commands.Stream) {
	nets, err := s.c.NetworkList(ctx, []string{LabelComposeProject + "=" + project})
	if err != nil {
		s.log.Warn("listing compose networks", "project", project, "err", err)
		return
	}
	for _, n := range nets {
		ids, err := s.c.NetworkContainers(ctx, n)
		if err != nil {
			s.log.Warn("reading compose network", "network", n, "err", err)
			continue
		}
		for _, id := range ids {
			c, ok, err := s.c.ContainerInspect(ctx, id)
			// The project's own containers are compose's to remove; only Falak's are detached.
			if err != nil || !ok || c.Config.Labels[LabelManaged] != "true" || c.Config.Labels[LabelComposeProject] != "" {
				continue
			}
			if err := s.c.NetworkDisconnect(ctx, n, id); err != nil {
				s.log.Warn("detaching container from compose network", "network", n, "container", c.Name, "err", err)
				continue
			}
			if st != nil {
				fmt.Fprintf(st.Stdout(), "detached %s from network %s\n", strings.TrimPrefix(c.Name, "/"), n)
			}
		}
	}
}

func (s *Service) composePs(ctx context.Context, p ComposePsPayload, _ commands.Stream) (any, error) {
	if !projectRe.MatchString(p.Project) {
		return nil, &commands.PayloadError{Err: fmt.Errorf("invalid project")}
	}
	services, err := s.projectStatus(ctx, p.Project, p.Stats)
	if err != nil {
		return nil, err
	}
	if services == nil {
		services = []ServiceStatus{}
	}
	return ComposePsResult{Services: services}, nil
}

func (s *Service) composeRestart(ctx context.Context, p ComposeRestartPayload, st commands.Stream) (any, error) {
	if !projectRe.MatchString(p.Project) {
		return nil, &commands.PayloadError{Err: fmt.Errorf("invalid project")}
	}
	want := map[string]bool{}
	for _, sv := range p.Services {
		if !serviceNameRe.MatchString(sv) {
			return nil, &commands.PayloadError{Err: fmt.Errorf("invalid service %q", sv)}
		}
		want[sv] = true
	}
	t := 10
	if p.TimeoutS != nil {
		t = *p.TimeoutS
	}
	list, err := s.c.ContainerList(ctx, true, []string{LabelComposeProject + "=" + p.Project})
	if err != nil {
		return nil, err
	}
	sort.Slice(list, func(i, j int) bool { return list[i].Labels[LabelComposeService] < list[j].Labels[LabelComposeService] })
	out := ComposeRestartResult{Restarted: []string{}}
	found := map[string]bool{}
	for _, c := range list {
		svc := c.Labels[LabelComposeService]
		if c.Labels[LabelComposeOneOff] == "True" || (len(want) > 0 && !want[svc]) {
			continue
		}
		found[svc] = true
		fmt.Fprintf(st.Stdout(), "restarting %s (%s)\n", svc, strings.TrimPrefix(firstName(c.Names), "/"))
		if err := s.c.ContainerRestart(ctx, c.ID, time.Duration(t)*time.Second); err != nil {
			return nil, fmt.Errorf("restart %s: %w", svc, err)
		}
		out.Restarted = append(out.Restarted, svc)
	}
	for sv := range want {
		if !found[sv] {
			return nil, fmt.Errorf("service %s has no container in project %s", sv, p.Project)
		}
	}
	return out, nil
}

func firstName(names []string) string {
	if len(names) == 0 {
		return ""
	}
	return names[0]
}

// projectStatus inspects every (non one-off) container of a compose project.
func (s *Service) projectStatus(ctx context.Context, project string, withStats bool) ([]ServiceStatus, error) {
	list, err := s.c.ContainerList(ctx, true, []string{LabelComposeProject + "=" + project})
	if err != nil {
		return nil, err
	}
	digests := map[string]string{}
	var out []ServiceStatus
	for _, c := range list {
		if c.Labels[LabelComposeOneOff] == "True" {
			continue
		}
		ct, ok, err := s.c.ContainerInspect(ctx, c.ID)
		if err != nil || !ok {
			continue
		}
		sv := ServiceStatus{
			Service:       c.Labels[LabelComposeService],
			ContainerID:   ct.ID,
			ContainerName: strings.TrimPrefix(ct.Name, "/"),
			State:         ct.State.Status,
			Image:         ct.Config.Image,
			Restarts:      ct.RestartCount,
			StartedAt:     ct.State.StartedAt,
		}
		if ct.State.Health != nil {
			sv.Health = ct.State.Health.Status
		}
		if !ct.State.Running {
			code := ct.State.ExitCode
			sv.ExitCode = &code
		}
		if i := strings.Index(ct.Config.Image, "@sha256:"); i >= 0 {
			sv.ImageDigest = ct.Config.Image[i+1:]
		} else if d, ok := digests[ct.Image]; ok {
			sv.ImageDigest = d
		} else {
			if rds, ok, _ := s.c.ImageRepoDigests(ctx, ct.Image); ok {
				repo := imageRepo(ct.Config.Image)
				for _, rd := range rds {
					r, dg, _ := strings.Cut(rd, "@")
					if dg != "" && (sv.ImageDigest == "" || r == repo) {
						sv.ImageDigest = dg
					}
				}
			}
			digests[ct.Image] = sv.ImageDigest
		}
		for key, binds := range ct.NetworkSettings.Ports {
			port, proto, _ := strings.Cut(key, "/")
			cp, _ := strconv.Atoi(port)
			if len(binds) == 0 {
				sv.Ports = append(sv.Ports, PortStatus{ContainerPort: cp, Protocol: proto})
			}
			for _, b := range binds {
				hp, _ := strconv.Atoi(b.HostPort)
				sv.Ports = append(sv.Ports, PortStatus{HostIP: b.HostIP, HostPort: hp, ContainerPort: cp, Protocol: proto})
			}
		}
		sort.Slice(sv.Ports, func(i, j int) bool {
			a, b := sv.Ports[i], sv.Ports[j]
			if a.ContainerPort != b.ContainerPort {
				return a.ContainerPort < b.ContainerPort
			}
			return a.HostIP+strconv.Itoa(a.HostPort) < b.HostIP+strconv.Itoa(b.HostPort)
		})
		out = append(out, sv)
	}
	sort.Slice(out, func(i, j int) bool {
		if out[i].Service != out[j].Service {
			return out[i].Service < out[j].Service
		}
		return out[i].ContainerName < out[j].ContainerName
	})
	if withStats {
		var wg sync.WaitGroup
		for i := range out {
			if out[i].State != "running" {
				continue
			}
			wg.Add(1)
			go func(sv *ServiceStatus) {
				defer wg.Done()
				sctx, cancel := context.WithTimeout(ctx, 5*time.Second)
				defer cancel()
				stats, err := s.c.ContainerStats(sctx, sv.ContainerID, false)
				if err != nil {
					return
				}
				cpu := float64(int64(CPUPercent(stats.PreCPUStats, stats.CPUStats)*100)) / 100
				mem := int64(stats.MemoryUsed())
				sv.CPUPercent, sv.MemoryBytes = &cpu, &mem
				if stats.MemoryStats.Limit > 0 {
					limit := int64(stats.MemoryStats.Limit)
					sv.MemoryLimit = &limit
				}
			}(&out[i])
		}
		wg.Wait()
	}
	return out, nil
}

func (s *Service) compose(ctx context.Context, dir string, env map[string]string, auth *Auth, args []string, st commands.Stream) (runner.Result, error) {
	envs := composeEnv(env)
	if auth != nil && auth.Username != "" {
		cfg, err := dockerConfigDir(auth)
		if err != nil {
			return runner.Result{}, err
		}
		defer os.RemoveAll(cfg)
		envs = append(envs, "DOCKER_CONFIG="+cfg)
	}
	realDir := ""
	if dir != "" {
		realDir = s.opts.FS.P(dir)
	}
	res, err := s.opts.Runner.Run(ctx, runner.Cmd{Name: "docker", Args: args, Dir: realDir, Env: envs, Stdout: st.Stdout(), Stderr: st.Stderr()})
	if err != nil {
		return res, err
	}
	if res.ExitCode != 0 {
		sub := "compose"
		for _, a := range args {
			if a == "up" || a == "pull" || a == "down" {
				sub = a
			}
		}
		return res, &commands.ExitError{Code: res.ExitCode, Err: fmt.Errorf("docker compose %s exited %d", sub, res.ExitCode)}
	}
	return res, nil
}

// composeEnv is a compose command's environment (KEY=value, sorted).
func composeEnv(env map[string]string) []string {
	keys := make([]string, 0, len(env))
	for k := range env {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	var envs []string
	for _, k := range keys {
		envs = append(envs, k+"="+env[k])
	}
	return envs
}

// dockerConfigDir writes a throwaway DOCKER_CONFIG with registry credentials (removed after the command).
func dockerConfigDir(a *Auth) (string, error) {
	dir, err := os.MkdirTemp("", "falak-docker-")
	if err != nil {
		return "", err
	}
	server := a.Server
	if server == "" {
		server = "https://index.docker.io/v1/"
	}
	b, _ := json.Marshal(map[string]any{"auths": map[string]any{server: map[string]string{
		"auth": base64.StdEncoding.EncodeToString([]byte(a.Username + ":" + a.Password)),
	}}})
	if err := os.WriteFile(filepath.Join(dir, "config.json"), b, 0o600); err != nil {
		os.RemoveAll(dir)
		return "", err
	}
	return dir, nil
}

// imageRepo strips a tag/digest from an image reference (keeping a registry port).
func imageRepo(ref string) string {
	if i := strings.Index(ref, "@"); i >= 0 {
		ref = ref[:i]
	}
	slash := strings.LastIndex(ref, "/")
	if i := strings.LastIndex(ref, ":"); i > slash {
		ref = ref[:i]
	}
	return ref
}
