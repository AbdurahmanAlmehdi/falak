package provision

import (
	"context"
	"fmt"
	"net/http"
	"os"
	"regexp"
	"slices"
	"strconv"
	"strings"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/system"
)

// Docker Engine from Docker's own apt repository (docker-ce): every server runs it (database containers), at least
// DockerPlan.MinVersion (28: its port publishing and DOCKER-USER behaviour are what the agent's database firewall
// relies on). The repository key is checked against Docker's published fingerprint before apt trusts it.
const (
	dockerKeyring     = "/etc/apt/keyrings/docker.asc"
	dockerSources     = "/etc/apt/sources.list.d/docker.sources"
	dockerFingerprint = "9DC858229FC7DD38854AE2D88D81803C0EBFCD88"
)

// DockerCEPackages are what Docker's repository installs.
var DockerCEPackages = []string{"docker-ce", "docker-ce-cli", "containerd.io", "docker-buildx-plugin", "docker-compose-plugin"}

// distroDockerPackages are the distribution's Docker (the fallback where Docker's repository has no suite yet).
var distroDockerPackages = []string{"docker.io", "docker-compose-v2", "docker-buildx"}

// conflictingDocker are removed before docker-ce goes in (Docker's install guide); images, containers and volumes stay
// in /var/lib/docker.
var conflictingDocker = []string{"docker.io", "docker-doc", "docker-compose", "docker-compose-v2", "docker-buildx", "podman-docker", "containerd", "runc"}

var versionStartRe = regexp.MustCompile(`^[0-9]+(\.[0-9]+)*`)

// upstreamVersion is a Debian version's upstream part as numbers: "5:28.1.1-1~ubuntu.24.04~noble" → 28.1.1.
func upstreamVersion(v string) string {
	if _, rest, ok := strings.Cut(v, ":"); ok {
		v = rest
	}
	return versionStartRe.FindString(v)
}

// versionAtLeast compares dotted numeric versions.
func versionAtLeast(v, min string) bool {
	a, b := strings.Split(v, "."), strings.Split(min, ".")
	for i := 0; i < len(a) || i < len(b); i++ {
		var x, y int
		if i < len(a) {
			x, _ = strconv.Atoi(a[i])
		}
		if i < len(b) {
			y, _ = strconv.Atoi(b[i])
		}
		if x != y {
			return x > y
		}
	}
	return true
}

// osRelease reads ID and VERSION_CODENAME from /etc/os-release.
func (p *Provisioner) osRelease() (id, codename string, err error) {
	b, err := p.d.FS.ReadFile("/etc/os-release")
	if err != nil {
		return "", "", err
	}
	for _, line := range strings.Split(string(b), "\n") {
		k, v, ok := strings.Cut(strings.TrimSpace(line), "=")
		if !ok {
			continue
		}
		v = strings.Trim(v, `"'`)
		switch k {
		case "ID":
			id = v
		case "VERSION_CODENAME":
			codename = v
		}
	}
	if id == "" || codename == "" {
		return "", "", fmt.Errorf("/etc/os-release has no ID or VERSION_CODENAME")
	}
	return id, codename, nil
}

// dockerEngine makes the server run Docker Engine min or newer: a Docker already that recent stays; otherwise docker-ce
// from Docker's repository replaces it (the distribution's docker.io removed first). Where Docker's repository has no
// suite for the release yet, the distribution's docker.io is used only if it is recent enough.
func (p *Provisioner) dockerEngine(ctx context.Context, st commands.Stream, min string) (bool, error) {
	a := system.AptFor(p.d.Runner, p.d.FS, st)
	have, err := a.Installed(ctx, []string{"docker-ce", "docker.io"})
	if err != nil {
		return false, err
	}
	for _, pkg := range []string{"docker-ce", "docker.io"} {
		if v, ok := have[pkg]; ok && versionAtLeast(upstreamVersion(v), min) {
			if pkg == "docker-ce" {
				inst, err := a.Ensure(ctx, DockerCEPackages, false)
				return len(inst) > 0, err
			}
			fmt.Fprintf(st.Stdout(), "docker: %s %s is recent enough (%s or newer)\n", pkg, upstreamVersion(v), min)
			return false, nil
		}
	}
	id, codename, err := p.osRelease()
	if err != nil {
		return false, err
	}
	if id != "ubuntu" && id != "debian" {
		return false, fmt.Errorf("docker: %s is not supported (Ubuntu or Debian)", id)
	}
	repo := strings.TrimRight(p.d.DockerRepoURL, "/") + "/" + id
	available, err := p.dockerSuite(ctx, repo, codename)
	if err != nil {
		return false, err
	}
	if !available {
		return p.distroDocker(ctx, st, a, have, id, codename, min)
	}
	// The key's fingerprint is read with gpg, which minimal images (and servers without the PHP repository) lack.
	if _, err := a.Ensure(ctx, []string{"gpg"}, true); err != nil {
		return false, err
	}
	if err := p.dockerKey(ctx, repo); err != nil {
		return false, err
	}
	sources := fmt.Sprintf("# Managed by Falak\nTypes: deb\nURIs: %s\nSuites: %s\nComponents: stable\nSigned-By: %s\n", repo, codename, dockerKeyring)
	if _, err := p.d.FS.WriteFile(dockerSources, []byte(sources), 0o644); err != nil {
		return false, err
	}
	if len(have) > 0 {
		fmt.Fprintf(st.Stdout(), "docker: replacing %s with Docker Engine from Docker's repository (containers, images and volumes stay)\n", strings.Join(sortedKeys(have), ", "))
	}
	if _, err := a.Remove(ctx, conflictingDocker); err != nil {
		return true, err
	}
	if err := a.Update(ctx); err != nil {
		return true, err
	}
	if cand, err := a.Candidate(ctx, "docker-ce"); err != nil {
		return true, err
	} else if !versionAtLeast(upstreamVersion(cand), min) {
		return true, fmt.Errorf("docker: Docker's repository offers docker-ce %q for %s %s, older than %s", cand, id, codename, min)
	}
	if _, err := a.Ensure(ctx, DockerCEPackages, false); err != nil {
		return true, err
	}
	return true, p.checkDockerVersion(ctx, a, "docker-ce", min)
}

// dockerSuite reports whether Docker's repository has a suite for the release (Release file present).
func (p *Provisioner) dockerSuite(ctx context.Context, repo, codename string) (bool, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodHead, repo+"/dists/"+codename+"/Release", nil)
	if err != nil {
		return false, err
	}
	res, err := p.d.HTTP.Do(req)
	if err != nil {
		return false, fmt.Errorf("docker: reaching Docker's repository: %w", err)
	}
	res.Body.Close()
	switch {
	case res.StatusCode == http.StatusOK:
		return true, nil
	case res.StatusCode == http.StatusNotFound || res.StatusCode == http.StatusForbidden:
		return false, nil
	}
	return false, fmt.Errorf("docker: Docker's repository answered %d for %s", res.StatusCode, codename)
}

// dockerKey installs Docker's repository key after checking its fingerprint.
func (p *Provisioner) dockerKey(ctx context.Context, repo string) error {
	if err := p.d.FS.MkdirAll("/etc/apt/keyrings", 0o755); err != nil {
		return err
	}
	tmp := dockerKeyring + ".new"
	if _, _, err := system.Download(ctx, p.d.HTTP, repo+"/gpg", "", p.d.FS.P(tmp), 0o644, nil); err != nil {
		return fmt.Errorf("docker: repository key: %w", err)
	}
	res, err := runner.Check(ctx, p.d.Runner, runner.Cmd{Name: "gpg", Args: []string{"--show-keys", "--with-colons", "--with-fingerprint", p.d.FS.P(tmp)}})
	if err != nil {
		_, _ = p.d.FS.Remove(tmp)
		return fmt.Errorf("docker: reading the repository key: %w", err)
	}
	if !strings.Contains(string(res.Stdout), "fpr:::::::::"+dockerFingerprint+":") {
		_, _ = p.d.FS.Remove(tmp)
		return fmt.Errorf("docker: the repository key is not Docker's (fingerprint %s expected)", dockerFingerprint)
	}
	return os.Rename(p.d.FS.P(tmp), p.d.FS.P(dockerKeyring))
}

// distroDocker installs the distribution's Docker where Docker's repository has no suite yet, if it is recent enough.
func (p *Provisioner) distroDocker(ctx context.Context, st commands.Stream, a system.Apt, have map[string]string, id, codename, min string) (bool, error) {
	if err := a.Update(ctx); err != nil {
		return false, err
	}
	cand, err := a.Candidate(ctx, "docker.io")
	if err != nil {
		return false, err
	}
	if !versionAtLeast(upstreamVersion(cand), min) {
		return false, fmt.Errorf("docker: Docker's repository has no packages for %s %s yet, and its docker.io (%q) is older than %s: Falak needs Docker %s or newer", id, codename, cand, min, min)
	}
	fmt.Fprintf(st.Stdout(), "docker: Docker's repository has no %s %s suite yet; using %s's docker.io %s\n", id, codename, id, upstreamVersion(cand))
	if _, ok := have["docker-ce"]; ok {
		return false, fmt.Errorf("docker: an older docker-ce is installed and Docker's repository has no %s %s suite to upgrade it", id, codename)
	}
	inst, err := a.Ensure(ctx, distroDockerPackages, false)
	if err != nil {
		return len(inst) > 0, err
	}
	return len(inst) > 0, p.checkDockerVersion(ctx, a, "docker.io", min)
}

func (p *Provisioner) checkDockerVersion(ctx context.Context, a system.Apt, pkg, min string) error {
	have, err := a.Installed(ctx, []string{pkg})
	if err != nil {
		return err
	}
	if v := upstreamVersion(have[pkg]); !versionAtLeast(v, min) {
		return fmt.Errorf("docker: %s %q installed, %s or newer needed", pkg, v, min)
	}
	return nil
}

func sortedKeys(m map[string]string) []string {
	out := make([]string, 0, len(m))
	for k := range m {
		out = append(out, k)
	}
	slices.Sort(out)
	return out
}
