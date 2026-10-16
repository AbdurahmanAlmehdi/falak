package api

import (
	"bytes"
	"context"
	"encoding/json"
	"net/http"
	"net/url"
	"time"
)

// Sandbox endpoints (`falak sandbox …`, `falak mcp`; docs/plans/SANDBOXES.md, "HTTP API"). {sandbox} is the id.
const (
	PathSandboxes      = "/api/v1/sandboxes"                   // GET ?status= → {data:[Sandbox]}; POST SandboxCreateRequest (?wait=1) → 201 Sandbox
	PathSandbox        = "/api/v1/sandboxes/{sandbox}"         // GET → Sandbox; PATCH {name?, extend?} → Sandbox; DELETE → 204
	PathSandboxExec    = "/api/v1/sandboxes/{sandbox}/exec"    // POST SandboxExecRequest → SandboxExecResult
	PathSandboxFiles   = "/api/v1/sandboxes/{sandbox}/files"   // GET ?path= → bytes (?list=1 → {entries}); PUT ?path=&mode= bytes → {size}
	PathSandboxConsole = "/api/v1/sandboxes/{sandbox}/console" // POST {cols?, rows?} → 201 {data:ConsoleSession}

	// v0.21. {snapshot} is an id or a name.
	PathSandboxSnapshots = "/api/v1/sandboxes/{sandbox}/snapshots"       // POST SnapshotRequest (?wait=1) → 201 Snapshot
	PathSnapshots        = "/api/v1/sandboxes/snapshots"                 // GET ?sandbox= → {data:[Snapshot]}
	PathSnapshot         = "/api/v1/sandboxes/snapshots/{snapshot}"      // GET → Snapshot; DELETE → 204
	PathSnapshotFork     = "/api/v1/sandboxes/snapshots/{snapshot}/fork" // POST ForkRequest (?wait=1) → 201 {data:[Sandbox]}
	PathSandboxPause     = "/api/v1/sandboxes/{sandbox}/pause"           // POST → Sandbox
	PathSandboxResume    = "/api/v1/sandboxes/{sandbox}/resume"          // POST → Sandbox
	PathSandboxDB        = "/api/v1/sandboxes/{sandbox}/db"              // POST SandboxDB → {data:{path,size_bytes}}
)

const (
	sandboxCreateWait   = 60 * time.Second // ?wait=1 waits this long at most
	sandboxRequestSlack = 30 * time.Second // on top of a server-side wait
)

// Sandbox is the sandbox resource.
type Sandbox struct {
	ID         string          `json:"id"`
	Name       string          `json:"name"`
	Status     string          `json:"status"` // creating, running, failed, killed
	Image      string          `json:"image"`
	Server     *SandboxServer  `json:"server"`
	CPUs       float64         `json:"cpus"`
	MemoryMB   int             `json:"memory_mb"`
	DiskMB     int             `json:"disk_mb"`
	Network    string          `json:"network"`
	AllowHosts []string        `json:"allow_hosts"`
	TTLSeconds int64           `json:"ttl_seconds"`
	ExpiresAt  string          `json:"expires_at"`
	CreatedAt  string          `json:"created_at"`
	CreatedBy  *SandboxCreator `json:"created_by"`
	IP         string          `json:"ip"`
	KillReason *string         `json:"kill_reason"`

	IdlePauseSeconds *int64      `json:"idle_pause_seconds"`
	LastActivityAt   string      `json:"last_activity_at"`
	FromSnapshot     *SandboxRef `json:"from_snapshot"`
}

// SandboxRef is an {id, name} pair (a snapshot or the sandbox it came from).
type SandboxRef struct {
	ID   string `json:"id"`
	Name string `json:"name"`
}

// Snapshot is the snapshot resource (status creating, ready, failed).
type Snapshot struct {
	ID        string          `json:"id"`
	Name      string          `json:"name"`
	Status    string          `json:"status"`
	Sandbox   *SandboxRef     `json:"sandbox"`
	Server    *SandboxServer  `json:"server"`
	Image     string          `json:"image"`
	CPUs      float64         `json:"cpus"`
	MemoryMB  int             `json:"memory_mb"`
	DiskMB    int             `json:"disk_mb"`
	Network   string          `json:"network"`
	SizeBytes int64           `json:"size_bytes"`
	CreatedAt string          `json:"created_at"`
	CreatedBy *SandboxCreator `json:"created_by"`
}

// SnapshotRequest is POST /sandboxes/{sandbox}/snapshots.
type SnapshotRequest struct {
	Name string `json:"name,omitempty"`
	Stop bool   `json:"stop,omitempty"` // stop and remove the sandbox after the checkpoint
}

// ForkRequest is POST /sandboxes/snapshots/{snapshot}/fork.
type ForkRequest struct {
	Count     int               `json:"count,omitempty"`
	Name      string            `json:"name,omitempty"`
	TTL       int64             `json:"ttl,omitempty"`
	Env       map[string]string `json:"env,omitempty"`
	IdlePause int64             `json:"idle_pause,omitempty"` // seconds
}

// SandboxDB names a database of an instance (id or name) on the sandbox's server.
type SandboxDB struct {
	Instance string `json:"instance"`
	Database string `json:"database"`
}

// SandboxDBResult is where the dump landed inside the sandbox.
type SandboxDBResult struct {
	Path      string `json:"path"`
	SizeBytes int64  `json:"size_bytes"`
}

type SandboxServer struct {
	ID   string `json:"id"`
	Name string `json:"name"`
}

type SandboxCreator struct {
	Type string `json:"type"` // user or token
	Name string `json:"name"`
}

// SandboxCreateRequest is POST /sandboxes; zero values take the server's defaults.
type SandboxCreateRequest struct {
	Image      string            `json:"image,omitempty"`
	Name       string            `json:"name,omitempty"`
	Server     string            `json:"server,omitempty"`
	CPUs       float64           `json:"cpus,omitempty"`
	MemoryMB   int               `json:"memory_mb,omitempty"`
	DiskMB     int               `json:"disk_mb,omitempty"`
	TTL        int64             `json:"ttl,omitempty"` // seconds
	Network    string            `json:"network,omitempty"`
	AllowHosts []string          `json:"allow_hosts,omitempty"`
	Env        map[string]string `json:"env,omitempty"`
	Repo       *SandboxRepo      `json:"repo,omitempty"`
	Snapshot   string            `json:"snapshot,omitempty"`   // id or name; the sandbox is a fork of it
	IdlePause  int64             `json:"idle_pause,omitempty"` // seconds
	DB         *SandboxDB        `json:"db,omitempty"`
}

type SandboxRepo struct {
	URL    string `json:"url"`
	Ref    string `json:"ref,omitempty"`
	Secret string `json:"secret,omitempty"` // a secret's name holding the clone token
}

// SandboxExecRequest is POST /exec. Cmd is a string (run by sh -lc) or a []string (argv).
type SandboxExecRequest struct {
	Cmd     any               `json:"cmd"`
	Cwd     string            `json:"cwd,omitempty"`
	Env     map[string]string `json:"env,omitempty"`
	Stdin   string            `json:"stdin,omitempty"`
	Timeout int               `json:"timeout,omitempty"` // seconds, 1–120 (server default 60)
}

type SandboxExecResult struct {
	ExitCode   int    `json:"exit_code"`
	Stdout     string `json:"stdout"`
	Stderr     string `json:"stderr"`
	Truncated  bool   `json:"truncated"`
	TimedOut   bool   `json:"timed_out"`
	DurationMS int64  `json:"duration_ms"`
}

// decodeData decodes a body that may or may not be wrapped in {"data": …} (Laravel resources wrap; the contract
// shows some bodies bare), so either shape works.
func decodeData(raw json.RawMessage, out any) error {
	var env struct {
		Data json.RawMessage `json:"data"`
	}
	if json.Unmarshal(raw, &env) == nil && len(env.Data) > 0 && env.Data[0] != 'n' {
		raw = env.Data
	}
	if len(bytes.TrimSpace(raw)) == 0 {
		return nil
	}
	return json.Unmarshal(raw, out)
}

// within returns a copy of c whose requests may take d (exec and create wait server-side past the default 60 s).
func (c *Client) within(d time.Duration) *Client {
	cc := *c
	hc := http.Client{}
	if c.HTTP != nil {
		hc = *c.HTTP
	}
	hc.Timeout = d
	cc.HTTP = &hc
	return &cc
}

func (c *Client) sandboxDo(ctx context.Context, method, path string, q url.Values, body, out any) error {
	var raw json.RawMessage
	if err := c.Do(ctx, method, path, q, body, &raw); err != nil {
		return err
	}
	if out == nil {
		return nil
	}
	return decodeData(raw, out)
}

func (c *Client) Sandboxes(ctx context.Context, status string) ([]Sandbox, error) {
	var q url.Values
	if status != "" {
		q = url.Values{"status": {status}}
	}
	var out []Sandbox
	err := c.sandboxDo(ctx, http.MethodGet, PathSandboxes, q, nil, &out)
	return out, err
}

func (c *Client) Sandbox(ctx context.Context, id string) (Sandbox, error) {
	var out Sandbox
	err := c.sandboxDo(ctx, http.MethodGet, Path(PathSandbox, "sandbox", id), nil, nil, &out)
	return out, err
}

// CreateSandbox creates a sandbox; wait asks the server to answer once it runs (up to 60 s).
func (c *Client) CreateSandbox(ctx context.Context, r SandboxCreateRequest, wait bool) (Sandbox, error) {
	var out Sandbox
	var q url.Values
	cc := c
	if wait {
		q = url.Values{"wait": {"1"}}
		cc = c.within(sandboxCreateWait + sandboxRequestSlack)
	}
	err := cc.sandboxDo(ctx, http.MethodPost, PathSandboxes, q, r, &out)
	return out, err
}

// ExtendSandbox adds seconds to the sandbox's time limit.
func (c *Client) ExtendSandbox(ctx context.Context, id string, seconds int64) (Sandbox, error) {
	var out Sandbox
	err := c.sandboxDo(ctx, http.MethodPatch, Path(PathSandbox, "sandbox", id), nil, map[string]int64{"extend": seconds}, &out)
	return out, err
}

func (c *Client) KillSandbox(ctx context.Context, id string) error {
	return c.Do(ctx, http.MethodDelete, Path(PathSandbox, "sandbox", id), nil, nil, nil)
}

func (c *Client) SandboxExec(ctx context.Context, id string, r SandboxExecRequest) (SandboxExecResult, error) {
	var out SandboxExecResult
	timeout := time.Duration(r.Timeout) * time.Second
	if timeout <= 0 {
		timeout = 60 * time.Second
	}
	err := c.within(timeout+sandboxRequestSlack).sandboxDo(ctx, http.MethodPost, Path(PathSandboxExec, "sandbox", id), nil, r, &out)
	return out, err
}

// SandboxReadFile downloads one file; truncated: it is larger than 5 MB and data is only its start (X-Falak-Truncated).
func (c *Client) SandboxReadFile(ctx context.Context, id, path string) ([]byte, bool, error) {
	data, h, err := c.doRaw(ctx, http.MethodGet, Path(PathSandboxFiles, "sandbox", id), url.Values{"path": {path}}, nil, "")
	if err != nil {
		return nil, false, err
	}
	return data, h.Get("X-Falak-Truncated") == "1", nil
}

// SandboxListFiles returns the directory listing as the API sent it ({entries: [...], truncated?}).
func (c *Client) SandboxListFiles(ctx context.Context, id, path string) (json.RawMessage, error) {
	var out json.RawMessage
	err := c.sandboxDo(ctx, http.MethodGet, Path(PathSandboxFiles, "sandbox", id), url.Values{"path": {path}, "list": {"1"}}, nil, &out)
	return out, err
}

// SandboxWriteFile uploads content to path (≤ 5 MB); mode is an octal string ("" = the server's 0644).
func (c *Client) SandboxWriteFile(ctx context.Context, id, path, mode string, content []byte) (int64, error) {
	q := url.Values{"path": {path}}
	if mode != "" {
		q.Set("mode", mode)
	}
	raw, err := c.DoRaw(ctx, http.MethodPut, Path(PathSandboxFiles, "sandbox", id), q, content, "application/octet-stream")
	if err != nil {
		return 0, err
	}
	var out struct {
		Size int64 `json:"size"`
	}
	err = decodeData(raw, &out)
	return out.Size, err
}

func (c *Client) OpenSandboxConsole(ctx context.Context, id string, r ConsoleRequest) (ConsoleSession, error) {
	return c.openConsole(ctx, Path(PathSandboxConsole, "sandbox", id), r)
}

// waitQuery returns the client and query for a server-side wait (up to 60 s) when wait is set.
func (c *Client) waitQuery(wait bool) (*Client, url.Values) {
	if !wait {
		return c, nil
	}
	return c.within(sandboxCreateWait + sandboxRequestSlack), url.Values{"wait": {"1"}}
}

// SnapshotSandbox takes a snapshot; wait asks the server to answer once it is ready (up to 60 s).
func (c *Client) SnapshotSandbox(ctx context.Context, id string, r SnapshotRequest, wait bool) (Snapshot, error) {
	var out Snapshot
	cc, q := c.waitQuery(wait)
	err := cc.sandboxDo(ctx, http.MethodPost, Path(PathSandboxSnapshots, "sandbox", id), q, r, &out)
	return out, err
}

func (c *Client) Snapshots(ctx context.Context, sandbox string) ([]Snapshot, error) {
	var q url.Values
	if sandbox != "" {
		q = url.Values{"sandbox": {sandbox}}
	}
	var out []Snapshot
	err := c.sandboxDo(ctx, http.MethodGet, PathSnapshots, q, nil, &out)
	return out, err
}

func (c *Client) DeleteSnapshot(ctx context.Context, snapshot string) error {
	return c.Do(ctx, http.MethodDelete, Path(PathSnapshot, "snapshot", snapshot), nil, nil, nil)
}

// ForkSnapshot creates r.Count (default 1) sandboxes from a snapshot; wait waits for them to run.
func (c *Client) ForkSnapshot(ctx context.Context, snapshot string, r ForkRequest, wait bool) ([]Sandbox, error) {
	var out []Sandbox
	cc, q := c.waitQuery(wait)
	err := cc.sandboxDo(ctx, http.MethodPost, Path(PathSnapshotFork, "snapshot", snapshot), q, r, &out)
	return out, err
}

func (c *Client) PauseSandbox(ctx context.Context, id string) (Sandbox, error) {
	var out Sandbox
	err := c.sandboxDo(ctx, http.MethodPost, Path(PathSandboxPause, "sandbox", id), nil, nil, &out)
	return out, err
}

func (c *Client) ResumeSandbox(ctx context.Context, id string) (Sandbox, error) {
	var out Sandbox
	err := c.sandboxDo(ctx, http.MethodPost, Path(PathSandboxResume, "sandbox", id), nil, nil, &out)
	return out, err
}

// SandboxImportDB copies a database into the sandbox (the server waits up to 10 minutes).
func (c *Client) SandboxImportDB(ctx context.Context, id string, r SandboxDB) (SandboxDBResult, error) {
	var out SandboxDBResult
	err := c.within(10*time.Minute+sandboxRequestSlack).sandboxDo(ctx, http.MethodPost, Path(PathSandboxDB, "sandbox", id), nil, r, &out)
	return out, err
}
