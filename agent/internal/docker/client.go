// Package docker talks to the Docker Engine API over its unix socket with plain net/http (no SDK) and
// implements the docker.* commands plus deploy.container.swap (blue/green behind Caddy).
package docker

import (
	"bufio"
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"
)

// DefaultAPIVersion is the highest API version this client speaks (Docker Engine 24+).
const DefaultAPIVersion = "1.43"

// Client is a minimal Engine API client.
type Client struct {
	hc *http.Client

	once    sync.Once
	version string // negotiated API version
}

// NewClient returns a client for the unix socket at path.
func NewClient(socket string) *Client {
	if socket == "" {
		socket = "/var/run/docker.sock"
	}
	tr := &http.Transport{
		DialContext: func(ctx context.Context, _, _ string) (net.Conn, error) {
			var d net.Dialer
			return d.DialContext(ctx, "unix", socket)
		},
		MaxIdleConns:    4,
		IdleConnTimeout: 30 * time.Second,
	}
	return &Client{hc: &http.Client{Transport: tr}}
}

// APIError is a non-2xx Engine response.
type APIError struct {
	Status  int
	Message string
}

func (e *APIError) Error() string { return fmt.Sprintf("docker api %d: %s", e.Status, e.Message) }

// IsNotFound reports a 404.
func IsNotFound(err error) bool {
	var ae *APIError
	return errors.As(err, &ae) && ae.Status == http.StatusNotFound
}

// VersionInfo is GET /version.
type VersionInfo struct {
	Version    string `json:"Version"`
	APIVersion string `json:"ApiVersion"`
	MinAPI     string `json:"MinAPIVersion"`
}

// Version returns the engine version (used for host facts).
func (c *Client) Version(ctx context.Context) (VersionInfo, error) {
	var v VersionInfo
	resp, err := c.raw(ctx, http.MethodGet, "/version", nil, nil, nil)
	if err != nil {
		return v, err
	}
	defer resp.Body.Close()
	return v, json.NewDecoder(resp.Body).Decode(&v)
}

// negotiate picks min(server ApiVersion, DefaultAPIVersion), raised to the server's MinAPIVersion (Docker 29+
// refuses API versions below 1.44; the subset of the API this client uses is unchanged there).
func (c *Client) apiVersion(ctx context.Context) string {
	c.once.Do(func() {
		c.version = DefaultAPIVersion
		v, err := c.Version(ctx)
		if err != nil {
			return
		}
		if v.APIVersion != "" && versionLess(v.APIVersion, DefaultAPIVersion) {
			c.version = v.APIVersion
		}
		if v.MinAPI != "" && versionLess(c.version, v.MinAPI) {
			c.version = v.MinAPI
		}
	})
	return c.version
}

func versionLess(a, b string) bool {
	pa, pb := strings.Split(a, "."), strings.Split(b, ".")
	for i := 0; i < len(pa) && i < len(pb); i++ {
		x, _ := strconv.Atoi(pa[i])
		y, _ := strconv.Atoi(pb[i])
		if x != y {
			return x < y
		}
	}
	return len(pa) < len(pb)
}

func (c *Client) raw(ctx context.Context, method, path string, q url.Values, body any, hdr http.Header) (*http.Response, error) {
	u := "http://docker" + path
	if len(q) > 0 {
		u += "?" + q.Encode()
	}
	var rd io.Reader
	if body != nil {
		b, err := json.Marshal(body)
		if err != nil {
			return nil, err
		}
		rd = bytes.NewReader(b)
	}
	req, err := http.NewRequestWithContext(ctx, method, u, rd)
	if err != nil {
		return nil, err
	}
	for k, v := range hdr {
		req.Header[k] = v
	}
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	resp, err := c.hc.Do(req)
	if err != nil {
		return nil, err
	}
	if resp.StatusCode >= 400 {
		defer resp.Body.Close()
		b, _ := io.ReadAll(io.LimitReader(resp.Body, 64<<10))
		var m struct {
			Message string `json:"message"`
		}
		if json.Unmarshal(b, &m) != nil || m.Message == "" {
			m.Message = strings.TrimSpace(string(b))
		}
		return nil, &APIError{Status: resp.StatusCode, Message: m.Message}
	}
	return resp, nil
}

// do calls a versioned endpoint and decodes JSON into out (if non-nil).
func (c *Client) do(ctx context.Context, method, path string, q url.Values, body, out any) (int, error) {
	resp, err := c.raw(ctx, method, "/v"+c.apiVersion(ctx)+path, q, body, nil)
	if err != nil {
		return 0, err
	}
	defer resp.Body.Close()
	if out != nil && resp.StatusCode != http.StatusNoContent && resp.StatusCode != http.StatusNotModified {
		if err := json.NewDecoder(resp.Body).Decode(out); err != nil && !errors.Is(err, io.EOF) {
			return resp.StatusCode, err
		}
	} else {
		_, _ = io.Copy(io.Discard, resp.Body)
	}
	return resp.StatusCode, nil
}

// Auth is registry credentials.
type Auth struct {
	Username string `json:"username"`
	Password string `json:"password"`
	Server   string `json:"server,omitempty"`
}

func (a *Auth) header() string {
	if a == nil {
		return ""
	}
	b, _ := json.Marshal(map[string]string{"username": a.Username, "password": a.Password, "serveraddress": a.Server})
	return base64.URLEncoding.EncodeToString(b)
}

// splitRef splits an image reference into fromImage and tag for POST /images/create.
func splitRef(ref string) (string, string) {
	if strings.Contains(ref, "@") {
		return ref, ""
	}
	slash := strings.LastIndex(ref, "/")
	if i := strings.LastIndex(ref, ":"); i > slash {
		return ref[:i], ref[i+1:]
	}
	return ref, "latest"
}

// ImagePull pulls ref, writing human-readable progress lines to w (may be nil).
func (c *Client) ImagePull(ctx context.Context, ref string, auth *Auth, w io.Writer) error {
	img, tag := splitRef(ref)
	q := url.Values{"fromImage": {img}}
	if tag != "" {
		q.Set("tag", tag)
	}
	hdr := http.Header{}
	if h := auth.header(); h != "" {
		hdr.Set("X-Registry-Auth", h)
	}
	resp, err := c.raw(ctx, http.MethodPost, "/v"+c.apiVersion(ctx)+"/images/create", q, nil, hdr)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	sc := bufio.NewScanner(resp.Body)
	sc.Buffer(make([]byte, 64<<10), 1<<20)
	for sc.Scan() {
		var m struct {
			Status   string `json:"status"`
			ID       string `json:"id"`
			Progress string `json:"progress"`
			Error    string `json:"error"`
		}
		if json.Unmarshal(sc.Bytes(), &m) != nil {
			continue
		}
		if m.Error != "" {
			return fmt.Errorf("pull %s: %s", ref, m.Error)
		}
		// Only the pull's own status ("Pulling from …", "Digest: …", "Status: …"); per-layer frames (download and
		// extract progress, one line each per layer) would flood deployment logs.
		if w != nil && m.Status != "" && m.Progress == "" && (m.ID == "" || m.ID == tag || strings.HasPrefix(m.Status, "Pulling from")) {
			line := m.Status
			if m.ID != "" && !strings.HasPrefix(m.Status, "Pulling from") {
				line = m.ID + ": " + line
			}
			fmt.Fprintln(w, line)
		}
	}
	return sc.Err()
}

// ImageInspect returns the image id; exists=false on 404.
func (c *Client) ImageInspect(ctx context.Context, ref string) (id string, exists bool, err error) {
	var out struct {
		ID string `json:"Id"`
	}
	_, err = c.do(ctx, http.MethodGet, "/images/"+ref+"/json", nil, nil, &out)
	if IsNotFound(err) {
		return "", false, nil
	}
	return out.ID, err == nil, err
}

// Container is the subset of GET /containers/{id}/json we use.
type Container struct {
	ID           string `json:"Id"`
	Name         string `json:"Name"`
	Image        string `json:"Image"`
	Created      string `json:"Created"`
	RestartCount int    `json:"RestartCount"`
	// ResolvConfPath and HostsPath are the host-side files Docker bind-mounts as the container's /etc/resolv.conf and
	// /etc/hosts (under its data root, out of the container's reach as paths): net.tool reads them there.
	ResolvConfPath string `json:"ResolvConfPath"`
	HostsPath      string `json:"HostsPath"`
	State          struct {
		Status  string `json:"Status"`
		Running bool   `json:"Running"`
		// Pid is the container's init process on the host (0 when not running): its /proc/<pid>/net/dev is the
		// container's network namespace (workload metrics).
		Pid       int    `json:"Pid"`
		ExitCode  int    `json:"ExitCode"`
		OOMKilled bool   `json:"OOMKilled"`
		StartedAt string `json:"StartedAt"`
		Health    *struct {
			Status string `json:"Status"`
		} `json:"Health,omitempty"`
	} `json:"State"`
	Config struct {
		Image  string            `json:"Image"`
		Labels map[string]string `json:"Labels"`
	} `json:"Config"`
	// HostConfig: the limits db containers change in place (ContainerUpdate), also read by the workload metrics on
	// cgroup v1 hosts (where the cgroup files are not read); CgroupParent moves the container's cgroup directory.
	HostConfig struct {
		Memory       int64  `json:"Memory"`
		NanoCpus     int64  `json:"NanoCpus"`
		PidsLimit    *int64 `json:"PidsLimit"`
		CgroupParent string `json:"CgroupParent"`
	} `json:"HostConfig"`
	Mounts []struct {
		Source      string `json:"Source"`
		Destination string `json:"Destination"`
	} `json:"Mounts"`
	NetworkSettings struct {
		Ports    map[string][]PortBinding `json:"Ports"`
		Networks map[string]struct {
			IPAddress string `json:"IPAddress"`
		} `json:"Networks"`
	} `json:"NetworkSettings"`
}

// ImageRepoDigests returns RepoDigests of an image ("repo@sha256:…"); exists=false on 404.
func (c *Client) ImageRepoDigests(ctx context.Context, ref string) ([]string, bool, error) {
	var out struct {
		RepoDigests []string `json:"RepoDigests"`
	}
	_, err := c.do(ctx, http.MethodGet, "/images/"+ref+"/json", nil, nil, &out)
	if IsNotFound(err) {
		return nil, false, nil
	}
	return out.RepoDigests, err == nil, err
}

// ImageConfig is the subset of GET /images/{ref}/json sandboxes use: the image id (its content digest), its
// default environment and its unpacked size in bytes.
type ImageConfig struct {
	ID     string `json:"Id"`
	Size   int64  `json:"Size"`
	Config struct {
		Env    []string          `json:"Env"`
		Labels map[string]string `json:"Labels"`
	} `json:"Config"`
}

// ImageRemove removes an image by id without force (Docker refuses while a container uses it or it has other tags).
func (c *Client) ImageRemove(ctx context.Context, id string) error {
	_, err := c.do(ctx, http.MethodDelete, "/images/"+url.PathEscape(id), nil, nil, nil)
	return err
}

// ImageConfigOf returns an image's id and config; exists=false on 404.
func (c *Client) ImageConfigOf(ctx context.Context, ref string) (ImageConfig, bool, error) {
	var out ImageConfig
	_, err := c.do(ctx, http.MethodGet, "/images/"+ref+"/json", nil, nil, &out)
	if IsNotFound(err) {
		return out, false, nil
	}
	return out, err == nil, err
}

// ContainerExport streams a (created, not necessarily started) container's filesystem as a tar archive.
func (c *Client) ContainerExport(ctx context.Context, id string) (io.ReadCloser, error) {
	resp, err := c.raw(ctx, http.MethodGet, "/v"+c.apiVersion(ctx)+"/containers/"+id+"/export", nil, nil, nil)
	if err != nil {
		return nil, err
	}
	return resp.Body, nil
}

// ContainerUpdate changes a running container's memory limit (swap at the same value: none) and CPUs in place.
func (c *Client) ContainerUpdate(ctx context.Context, id string, memory, nanoCPUs int64) error {
	body := map[string]any{"Memory": memory, "MemorySwap": memory, "NanoCpus": nanoCPUs}
	_, err := c.do(ctx, http.MethodPost, "/containers/"+id+"/update", nil, body, nil)
	return err
}

// ContainerRestart restarts a container (stop timeout t).
func (c *Client) ContainerRestart(ctx context.Context, id string, timeout time.Duration) error {
	_, err := c.do(ctx, http.MethodPost, "/containers/"+id+"/restart", url.Values{"t": {strconv.Itoa(int(timeout.Seconds()))}}, nil, nil)
	return err
}

// Stats is the subset of GET /containers/{id}/stats we use.
type Stats struct {
	CPUStats    CPUStats `json:"cpu_stats"`
	PreCPUStats CPUStats `json:"precpu_stats"`
	MemoryStats struct {
		Usage uint64            `json:"usage"`
		Limit uint64            `json:"limit"`
		Stats map[string]uint64 `json:"stats"`
	} `json:"memory_stats"`
	Networks map[string]struct {
		RxBytes uint64 `json:"rx_bytes"`
		TxBytes uint64 `json:"tx_bytes"`
	} `json:"networks"`
}

// CPUStats of a stats sample.
type CPUStats struct {
	CPUUsage struct {
		TotalUsage uint64 `json:"total_usage"`
	} `json:"cpu_usage"`
	SystemUsage uint64 `json:"system_cpu_usage"`
	OnlineCPUs  uint32 `json:"online_cpus"`
}

// MemoryUsed is usage minus the page cache (what `docker stats` shows).
func (s Stats) MemoryUsed() uint64 {
	cache := s.MemoryStats.Stats["inactive_file"]
	if cache == 0 {
		cache = s.MemoryStats.Stats["total_inactive_file"]
	}
	if cache > s.MemoryStats.Usage {
		return s.MemoryStats.Usage
	}
	return s.MemoryStats.Usage - cache
}

// CPUPercent between prev and s (100 = one full CPU), like `docker stats`.
func CPUPercent(prev, cur CPUStats) float64 {
	cpu := float64(cur.CPUUsage.TotalUsage) - float64(prev.CPUUsage.TotalUsage)
	sys := float64(cur.SystemUsage) - float64(prev.SystemUsage)
	n := float64(cur.OnlineCPUs)
	if n == 0 {
		n = 1
	}
	if cpu <= 0 || sys <= 0 {
		return 0
	}
	return cpu / sys * n * 100
}

// ContainerStats takes one stats sample. oneShot skips the ~1s wait for precpu_stats.
func (c *Client) ContainerStats(ctx context.Context, id string, oneShot bool) (Stats, error) {
	var s Stats
	q := url.Values{"stream": {"false"}}
	if oneShot {
		q.Set("one-shot", "true")
	}
	_, err := c.do(ctx, http.MethodGet, "/containers/"+id+"/stats", q, nil, &s)
	return s, err
}

// ContainerInspect returns the container; exists=false on 404.
func (c *Client) ContainerInspect(ctx context.Context, nameOrID string) (*Container, bool, error) {
	var ct Container
	_, err := c.do(ctx, http.MethodGet, "/containers/"+nameOrID+"/json", nil, nil, &ct)
	if IsNotFound(err) {
		return nil, false, nil
	}
	if err != nil {
		return nil, false, err
	}
	return &ct, true, nil
}

// ContainerSummary is an entry of GET /containers/json.
type ContainerSummary struct {
	ID      string            `json:"Id"`
	Names   []string          `json:"Names"`
	Image   string            `json:"Image"`
	State   string            `json:"State"`
	Status  string            `json:"Status"` // "Up 5 minutes (healthy)"
	Created int64             `json:"Created"`
	Labels  map[string]string `json:"Labels"`
	Mounts  []MountPoint      `json:"Mounts,omitempty"`
}

// MountPoint is one of a container's mounts as GET /containers/json lists it (Name: a named volume's name;
// Source: the host path behind it).
type MountPoint struct {
	Type        string `json:"Type"`
	Name        string `json:"Name,omitempty"`
	Source      string `json:"Source"`
	Destination string `json:"Destination"`
}

// ContainerList lists containers (all states when all=true) matching label filters ("k=v").
func (c *Client) ContainerList(ctx context.Context, all bool, labels []string) ([]ContainerSummary, error) {
	q := url.Values{}
	if all {
		q.Set("all", "1")
	}
	if len(labels) > 0 {
		f, _ := json.Marshal(map[string][]string{"label": labels})
		q.Set("filters", string(f))
	}
	var out []ContainerSummary
	_, err := c.do(ctx, http.MethodGet, "/containers/json", q, nil, &out)
	return out, err
}

// CreateBody is POST /containers/create.
type CreateBody struct {
	Image        string              `json:"Image"`
	Env          []string            `json:"Env,omitempty"`
	Cmd          []string            `json:"Cmd,omitempty"`
	Entrypoint   []string            `json:"Entrypoint,omitempty"`
	User         string              `json:"User,omitempty"`
	WorkingDir   string              `json:"WorkingDir,omitempty"`
	Labels       map[string]string   `json:"Labels,omitempty"`
	ExposedPorts map[string]struct{} `json:"ExposedPorts,omitempty"`
	Healthcheck  *Healthcheck        `json:"Healthcheck,omitempty"`
	// StopTimeout is the seconds `docker stop` (and a daemon shutdown) waits before SIGKILL.
	StopTimeout      *int              `json:"StopTimeout,omitempty"`
	HostConfig       HostConfig        `json:"HostConfig"`
	NetworkingConfig *NetworkingConfig `json:"NetworkingConfig,omitempty"`
}

// Healthcheck of a container (durations in nanoseconds).
type Healthcheck struct {
	Test        []string `json:"Test"`
	Interval    int64    `json:"Interval,omitempty"`
	Timeout     int64    `json:"Timeout,omitempty"`
	Retries     int      `json:"Retries,omitempty"`
	StartPeriod int64    `json:"StartPeriod,omitempty"`
}

// NetworkingConfig names the endpoint a container is created on (HostConfig.NetworkMode), with its DNS aliases.
type NetworkingConfig struct {
	EndpointsConfig map[string]EndpointConfig `json:"EndpointsConfig"`
}

// EndpointConfig is one network endpoint.
type EndpointConfig struct {
	Aliases []string `json:"Aliases,omitempty"`
}

// HostConfig subset.
type HostConfig struct {
	PortBindings map[string][]PortBinding `json:"PortBindings,omitempty"`
	Binds        []string                 `json:"Binds,omitempty"`
	// Mounts, unlike Binds, never create a missing bind source: a container whose secret files are gone after a
	// reboot fails to start instead of starting without them.
	Mounts        []Mount       `json:"Mounts,omitempty"`
	NetworkMode   string        `json:"NetworkMode,omitempty"`
	RestartPolicy RestartPolicy `json:"RestartPolicy"`
	Memory        int64         `json:"Memory,omitempty"`
	// MemorySwap is memory + swap (equal to Memory: no swap on top of the limit).
	MemorySwap        int64      `json:"MemorySwap,omitempty"`
	MemoryReservation int64      `json:"MemoryReservation,omitempty"`
	NanoCPUs          int64      `json:"NanoCpus,omitempty"`
	OomScoreAdj       int        `json:"OomScoreAdj,omitempty"`
	LogConfig         *LogConfig `json:"LogConfig,omitempty"`
	ShmSize           int64      `json:"ShmSize,omitempty"`
	// Hardening (function containers).
	ReadonlyRootfs bool              `json:"ReadonlyRootfs,omitempty"`
	Tmpfs          map[string]string `json:"Tmpfs,omitempty"`
	CapDrop        []string          `json:"CapDrop,omitempty"`
	CapAdd         []string          `json:"CapAdd,omitempty"`
	SecurityOpt    []string          `json:"SecurityOpt,omitempty"`
	PidsLimit      int64             `json:"PidsLimit,omitempty"`
	AutoRemove     bool              `json:"AutoRemove,omitempty"`
}

// Mount is a bind mount.
type Mount struct {
	Type     string `json:"Type"`
	Source   string `json:"Source"`
	Target   string `json:"Target"`
	ReadOnly bool   `json:"ReadOnly,omitempty"`
}

// PortBinding is host ip/port.
type PortBinding struct {
	HostIP   string `json:"HostIp,omitempty"`
	HostPort string `json:"HostPort,omitempty"`
}

// RestartPolicy of a container.
type RestartPolicy struct {
	Name              string `json:"Name,omitempty"`
	MaximumRetryCount int    `json:"MaximumRetryCount,omitempty"`
}

// ContainerCreate creates a named container.
func (c *Client) ContainerCreate(ctx context.Context, name string, body CreateBody) (string, error) {
	var out struct {
		ID string `json:"Id"`
	}
	_, err := c.do(ctx, http.MethodPost, "/containers/create", url.Values{"name": {name}}, body, &out)
	return out.ID, err
}

// ContainerStart starts a container (already running is not an error).
func (c *Client) ContainerStart(ctx context.Context, id string) error {
	_, err := c.do(ctx, http.MethodPost, "/containers/"+id+"/start", nil, nil, nil)
	return err
}

// ContainerStop stops a container; returns stopped=false when it was not running.
func (c *Client) ContainerStop(ctx context.Context, id string, timeout time.Duration) (bool, error) {
	code, err := c.do(ctx, http.MethodPost, "/containers/"+id+"/stop", url.Values{"t": {strconv.Itoa(int(timeout.Seconds()))}}, nil, nil)
	if err != nil {
		return false, err
	}
	return code != http.StatusNotModified, nil
}

// ContainerRemove force-removes a container (404 is not an error).
func (c *Client) ContainerRemove(ctx context.Context, id string) error {
	_, err := c.do(ctx, http.MethodDelete, "/containers/"+id, url.Values{"force": {"1"}}, nil, nil)
	if IsNotFound(err) {
		return nil
	}
	return err
}

// Prune calls a prune endpoint (containers|images|volumes|networks|build) and returns reclaimed bytes.
func (c *Client) Prune(ctx context.Context, kind string, filters map[string][]string) (uint64, error) {
	q := url.Values{}
	if len(filters) > 0 {
		f, _ := json.Marshal(filters)
		q.Set("filters", string(f))
	}
	path := "/" + kind + "/prune"
	var out struct {
		SpaceReclaimed uint64 `json:"SpaceReclaimed"`
	}
	_, err := c.do(ctx, http.MethodPost, path, q, nil, &out)
	return out.SpaceReclaimed, err
}

// ContainerWait blocks until the container stops and returns its exit code.
func (c *Client) ContainerWait(ctx context.Context, id string) (int, error) {
	var out struct {
		StatusCode int `json:"StatusCode"`
		Error      *struct {
			Message string `json:"Message"`
		} `json:"Error"`
	}
	_, err := c.do(ctx, http.MethodPost, "/containers/"+id+"/wait", url.Values{"condition": {"not-running"}}, nil, &out)
	if err != nil {
		return -1, err
	}
	if out.Error != nil && out.Error.Message != "" {
		return out.StatusCode, errors.New(out.Error.Message)
	}
	return out.StatusCode, nil
}

// ContainerLogs copies the (non-TTY, multiplexed) stdout+stderr of a container to w; follow streams until the
// container stops or ctx ends. tail > 0 limits the output to the last lines.
func (c *Client) ContainerLogs(ctx context.Context, id string, follow bool, tail int, w io.Writer) error {
	q := url.Values{"stdout": {"1"}, "stderr": {"1"}}
	if follow {
		q.Set("follow", "1")
	}
	if tail > 0 {
		q.Set("tail", strconv.Itoa(tail))
	}
	return c.logs(ctx, id, q, w)
}

// ContainerLogTail copies the last tail lines of stdout+stderr, each prefixed with its RFC 3339 timestamp.
func (c *Client) ContainerLogTail(ctx context.Context, id string, tail int, w io.Writer) error {
	return c.logs(ctx, id, url.Values{"stdout": {"1"}, "stderr": {"1"}, "timestamps": {"1"}, "tail": {strconv.Itoa(tail)}}, w)
}

func (c *Client) logs(ctx context.Context, id string, q url.Values, w io.Writer) error {
	resp, err := c.raw(ctx, http.MethodGet, "/v"+c.apiVersion(ctx)+"/containers/"+id+"/logs", q, nil, nil)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	return Demux(resp.Body, w)
}

// Demux copies a Docker multiplexed stream (8-byte frame headers) to w, stdout and stderr alike.
func Demux(r io.Reader, w io.Writer) error {
	hdr := make([]byte, 8)
	for {
		if _, err := io.ReadFull(r, hdr); err != nil {
			if errors.Is(err, io.EOF) || errors.Is(err, io.ErrUnexpectedEOF) {
				return nil
			}
			return err
		}
		n := int64(hdr[4])<<24 | int64(hdr[5])<<16 | int64(hdr[6])<<8 | int64(hdr[7])
		if _, err := io.CopyN(w, r, n); err != nil {
			if errors.Is(err, io.EOF) {
				return nil
			}
			return err
		}
	}
}

// NetworkExists reports whether a network of that name exists.
func (c *Client) NetworkExists(ctx context.Context, name string) (bool, error) {
	_, err := c.do(ctx, http.MethodGet, "/networks/"+name, nil, nil, nil)
	if IsNotFound(err) {
		return false, nil
	}
	return err == nil, err
}

// NetworkConnect joins a (created, not yet started) container to a network under extra DNS aliases. Being
// connected already is not an error.
func (c *Client) NetworkConnect(ctx context.Context, network, container string, aliases []string) error {
	body := map[string]any{"Container": container, "EndpointConfig": map[string]any{"Aliases": aliases}}
	_, err := c.do(ctx, http.MethodPost, "/networks/"+url.PathEscape(network)+"/connect", nil, body, nil)
	var ae *APIError
	if errors.As(err, &ae) && ae.Status == http.StatusForbidden && strings.Contains(ae.Message, "already exists") {
		return nil
	}
	return err
}

// NetworkList returns the names of the networks matching label filters ("k=v").
func (c *Client) NetworkList(ctx context.Context, labels []string) ([]string, error) {
	q := url.Values{}
	if len(labels) > 0 {
		f, _ := json.Marshal(map[string][]string{"label": labels})
		q.Set("filters", string(f))
	}
	var out []struct {
		Name string `json:"Name"`
	}
	if _, err := c.do(ctx, http.MethodGet, "/networks", q, nil, &out); err != nil {
		return nil, err
	}
	names := make([]string, 0, len(out))
	for _, n := range out {
		names = append(names, n.Name)
	}
	return names, nil
}

// NetworkContainers returns the ids of the containers attached to a network (none when it doesn't exist).
func (c *Client) NetworkContainers(ctx context.Context, name string) ([]string, error) {
	var out struct {
		Containers map[string]json.RawMessage `json:"Containers"`
	}
	if _, err := c.do(ctx, http.MethodGet, "/networks/"+url.PathEscape(name), nil, nil, &out); err != nil {
		if IsNotFound(err) {
			return nil, nil
		}
		return nil, err
	}
	ids := make([]string, 0, len(out.Containers))
	for id := range out.Containers {
		ids = append(ids, id)
	}
	sort.Strings(ids)
	return ids, nil
}

// NetworkDisconnect detaches a container from a network (a container or network that is gone already is not an
// error).
func (c *Client) NetworkDisconnect(ctx context.Context, network, container string) error {
	body := map[string]any{"Container": container, "Force": true}
	_, err := c.do(ctx, http.MethodPost, "/networks/"+url.PathEscape(network)+"/disconnect", nil, body, nil)
	if IsNotFound(err) {
		return nil
	}
	return err
}

// NetworkCreate creates a bridge network with labels (a 409 "already exists" is not an error).
func (c *Client) NetworkCreate(ctx context.Context, name string, labels map[string]string) error {
	body := map[string]any{"Name": name, "Driver": "bridge", "Labels": labels, "CheckDuplicate": true}
	_, err := c.do(ctx, http.MethodPost, "/networks/create", nil, body, nil)
	var ae *APIError
	if errors.As(err, &ae) && ae.Status == http.StatusConflict {
		return nil
	}
	return err
}

// ContainerPause freezes a container's processes.
func (c *Client) ContainerPause(ctx context.Context, id string) error {
	_, err := c.do(ctx, http.MethodPost, "/containers/"+id+"/pause", nil, nil, nil)
	return err
}

// ContainerUnpause thaws a paused container.
func (c *Client) ContainerUnpause(ctx context.Context, id string) error {
	_, err := c.do(ctx, http.MethodPost, "/containers/"+id+"/unpause", nil, nil, nil)
	return err
}

// Volume is a named volume (GET /volumes/{name}).
type Volume struct {
	Name       string            `json:"Name"`
	Driver     string            `json:"Driver"`
	Mountpoint string            `json:"Mountpoint"`
	Labels     map[string]string `json:"Labels"`
}

// VolumeCreate creates a local named volume (an existing one is returned as is).
func (c *Client) VolumeCreate(ctx context.Context, name string, labels map[string]string) (Volume, error) {
	var out Volume
	_, err := c.do(ctx, http.MethodPost, "/volumes/create", nil, map[string]any{"Name": name, "Driver": "local", "Labels": labels}, &out)
	return out, err
}

// VolumeInspect returns a named volume; exists=false on 404.
func (c *Client) VolumeInspect(ctx context.Context, name string) (Volume, bool, error) {
	var out Volume
	_, err := c.do(ctx, http.MethodGet, "/volumes/"+url.PathEscape(name), nil, nil, &out)
	if IsNotFound(err) {
		return Volume{}, false, nil
	}
	return out, err == nil, err
}

// VolumeList lists every named volume.
func (c *Client) VolumeList(ctx context.Context) ([]Volume, error) {
	var out struct {
		Volumes []Volume `json:"Volumes"`
	}
	_, err := c.do(ctx, http.MethodGet, "/volumes", nil, nil, &out)
	return out.Volumes, err
}

// VolumeRemove removes a named volume; existed=false on 404. Docker refuses a volume a container still uses (409).
func (c *Client) VolumeRemove(ctx context.Context, name string) (bool, error) {
	_, err := c.do(ctx, http.MethodDelete, "/volumes/"+url.PathEscape(name), nil, nil, nil)
	if IsNotFound(err) {
		return false, nil
	}
	return err == nil, err
}
