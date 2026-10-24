// Package builder implements falak-builder: clone a repository, build it natively (release tarball) or
// as an OCI image (BuildKit), and stream progress as agent-protocol events (event.schema.json).
package builder

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"regexp"
	"sort"
	"strings"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/redact"
)

var sprintf = fmt.Sprintf

// Build modes (ARCHITECTURE.md §5).
const (
	ModeNative = "native"
	ModeDocker = "docker"
)

// Job is one build request from the control plane (Builds module).
type Job struct {
	ID       string            `json:"id"` // build id; used as command_id of emitted events
	Mode     string            `json:"mode"`
	Repo     Repo              `json:"repo"`
	Subdir   string            `json:"subdir,omitempty"`  // app root inside the repo (monorepos)
	Runtime  string            `json:"runtime,omitempty"` // optional hint: php|node|bun|deno|static
	Env      map[string]string `json:"env,omitempty"`     // build-time environment
	TimeoutS int               `json:"timeout_s,omitempty"`
	Native   *NativeSpec       `json:"native,omitempty"`
	Docker   *DockerSpec       `json:"docker,omitempty"`
	Compose  *ComposeSpec      `json:"compose,omitempty"` // docker mode: build the `build:` services of a compose file
	// Mask names the secret variables of Env and Docker.BuildArgs: their values are masked in the build log.
	Mask []string `json:"mask,omitempty"`
	// OrganizationID owns the build: native build steps run as that organization's uid, with its own caches.
	OrganizationID string `json:"organization_id,omitempty"`
}

// Repo to clone.
type Repo struct {
	URL        string `json:"url"`
	Ref        string `json:"ref,omitempty"`    // branch or tag
	Commit     string `json:"commit,omitempty"` // exact sha (wins over ref)
	DeployKey  string `json:"deploy_key,omitempty"`
	KnownHosts string `json:"known_hosts,omitempty"` // pins host keys when set
	Token      string `json:"token,omitempty"`       // HTTPS token
	Username   string `json:"username,omitempty"`    // HTTPS user for Token (default x-access-token)
}

// NativeSpec configures native (tarball) builds.
type NativeSpec struct {
	Upload         *Upload  `json:"upload,omitempty"`       // presigned PUT; nil = write to the local artifacts dir
	DownloadURL    string   `json:"download_url,omitempty"` // echoed in the result for deploy.fetch
	Excludes       []string `json:"excludes,omitempty"`     // extra exclude patterns
	InstallCommand string   `json:"install_command,omitempty"`
	BuildCommand   string   `json:"build_command,omitempty"`
	StartCommand   string   `json:"start_command,omitempty"`
	UseRailpack    *bool    `json:"use_railpack,omitempty"` // default: when the railpack CLI is on PATH
}

// Upload is a presigned PUT target.
type Upload struct {
	URL     string            `json:"url"`
	Headers map[string]string `json:"headers,omitempty"`
}

// DockerSpec configures image builds.
type DockerSpec struct {
	Image      string            `json:"image"`                // repo[:tag] to push, e.g. registry.falak.local/acme/app:01J...
	Tags       []string          `json:"tags,omitempty"`       // additional tags
	Dockerfile string            `json:"dockerfile,omitempty"` // path relative to the app root; "" = Dockerfile if present, else generated
	Context    string            `json:"context,omitempty"`    // relative to the app root; default "."
	Target     string            `json:"target,omitempty"`
	BuildArgs  map[string]string `json:"build_args,omitempty"`
	Platforms  []string          `json:"platforms,omitempty"`
	Registry   *RegistryAuth     `json:"registry,omitempty"`
	Push       *bool             `json:"push,omitempty"`   // default true
	Engine     string            `json:"engine,omitempty"` // buildx|buildctl; default: buildx when docker is available
}

// RegistryAuth mirrors deploy.container.swap registry_auth.
type RegistryAuth struct {
	Server   string `json:"server,omitempty"`
	Username string `json:"username"`
	Password string `json:"password"`
}

// Result is the `result` of the finished event.
type Result struct {
	BuildID    string          `json:"build_id"`
	Mode       string          `json:"mode"`
	Commit     string          `json:"commit,omitempty"`
	DurationMS int64           `json:"duration_ms"`
	Artifact   *ArtifactResult `json:"artifact,omitempty"`
	Manifest   *Manifest       `json:"manifest,omitempty"`
	Image      *ImageResult    `json:"image,omitempty"`
	Compose    *ComposeResult  `json:"compose,omitempty"`
}

// ArtifactResult feeds deploy.fetch's `artifact` (url + sha256 + size_bytes + format).
type ArtifactResult struct {
	URL       string `json:"url,omitempty"`
	Path      string `json:"path,omitempty"` // local artifacts dir mode
	SHA256    string `json:"sha256"`
	SizeBytes int64  `json:"size_bytes"`
	Format    string `json:"format"`
}

// ImageResult feeds deploy.container.swap `image` / docker.pull `image`.
type ImageResult struct {
	Ref    string `json:"ref"`              // as pushed (tag)
	Digest string `json:"digest,omitempty"` // sha256:...
	Pinned string `json:"pinned,omitempty"` // repo@sha256:... (use this for deploys)
}

var buildIDRe = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$`)

// DefaultTimeout applies when a job has no timeout_s.
const DefaultTimeout = 30 * time.Minute

// Timeout returns the effective job timeout.
func (j Job) Timeout() time.Duration {
	if j.TimeoutS > 0 {
		return time.Duration(j.TimeoutS) * time.Second
	}
	return DefaultTimeout
}

// Validate checks required fields.
func (j *Job) Validate() error {
	var errs []string
	if !buildIDRe.MatchString(j.ID) {
		errs = append(errs, "id: must match "+buildIDRe.String())
	}
	if j.OrganizationID != "" && !buildIDRe.MatchString(j.OrganizationID) {
		errs = append(errs, "organization_id: must match "+buildIDRe.String())
	}
	if j.Mode == "" {
		j.Mode = ModeNative
	}
	switch j.Mode {
	case ModeNative:
	case ModeDocker:
		switch {
		case j.Compose != nil:
			if j.Compose.ImagePrefix == "" {
				errs = append(errs, "compose.image_prefix: required for compose builds")
			}
			if j.Docker != nil {
				errs = append(errs, "docker and compose are mutually exclusive")
			}
		case j.Docker == nil || j.Docker.Image == "":
			errs = append(errs, "docker.image: required for docker builds")
		}
	default:
		errs = append(errs, fmt.Sprintf("mode: unknown %q", j.Mode))
	}
	if j.Repo.URL == "" {
		errs = append(errs, "repo.url: required")
	}
	if strings.Contains(j.Subdir, "..") || strings.HasPrefix(j.Subdir, "/") {
		errs = append(errs, "subdir: must be relative without ..")
	}
	if len(errs) > 0 {
		return errors.New("invalid job: " + strings.Join(errs, "; "))
	}
	return nil
}

// DecodeJob reads and validates a job from JSON.
func DecodeJob(r io.Reader) (Job, error) {
	var j Job
	dec := json.NewDecoder(r)
	dec.DisallowUnknownFields()
	if err := dec.Decode(&j); err != nil {
		return j, fmt.Errorf("decode job: %w", err)
	}
	return j, j.Validate()
}

// Secrets returns values that must never appear in logs.
func (j Job) Secrets() []string {
	var s []string
	add := func(v string) {
		if v != "" {
			s = append(s, v)
		}
	}
	for _, v := range redact.FromEnv(j.Env, j.Mask) {
		add(v)
	}
	if j.Docker != nil {
		for _, v := range redact.FromEnv(j.Docker.BuildArgs, j.Mask) {
			add(v)
		}
	}
	add(j.Repo.Token)
	add(j.Repo.DeployKey)
	if j.Docker != nil && j.Docker.Registry != nil {
		add(j.Docker.Registry.Password)
	}
	if j.Compose != nil && j.Compose.Registry != nil {
		add(j.Compose.Registry.Password)
	}
	if j.Native != nil && j.Native.Upload != nil {
		add(j.Native.Upload.URL)
		for _, v := range j.Native.Upload.Headers {
			add(v)
		}
	}
	return s
}

func sortedKeys(m map[string]string) []string {
	keys := make([]string, 0, len(m))
	for k := range m {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	return keys
}
