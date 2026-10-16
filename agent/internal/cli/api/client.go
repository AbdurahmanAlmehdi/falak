// Package api is the typed client for the Falak control-plane public REST API.
package api

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"sort"
	"strconv"
	"strings"
	"time"
)

// API is the surface the CLI uses; tests substitute fakes or point Client at httptest.
type API interface {
	Me(ctx context.Context) (Me, error)
	Organizations(ctx context.Context) ([]Organization, error)
	Servers(ctx context.Context) ([]Server, error)
	Server(ctx context.Context, id string) (Server, error)
	AddServerRuntimes(ctx context.Context, server string, r StackRequest) (StackChange, error)
	Sites(ctx context.Context) ([]Site, error)
	Site(ctx context.Context, idOrSlug string) (Site, error)
	Deploy(ctx context.Context, site, branch string) (Deployment, error)
	Deployment(ctx context.Context, id string) (Deployment, error)
	DeploymentOutput(ctx context.Context, id string, after int64) ([]OutputLine, int64, error)
	Rollback(ctx context.Context, site, releaseID string) (Deployment, error)
	Releases(ctx context.Context, site string) ([]Release, error)
	EnvPull(ctx context.Context, site string) (string, error)
	EnvPush(ctx context.Context, site, content string) error
	Logs(ctx context.Context, site string, q LogQuery) ([]LogEntry, string, error)

	Functions(ctx context.Context) ([]FunctionSummary, error)
	Function(ctx context.Context, fn string) (Function, error)
	FunctionVersions(ctx context.Context, fn string) ([]FunctionVersion, error)
	FunctionVersion(ctx context.Context, fn string, number int) (FunctionVersion, error)
	FunctionDeploy(ctx context.Context, fn string, r FunctionDeployRequest) (FunctionDeployResult, error)
	FunctionRollback(ctx context.Context, fn string, number int) (string, error)
	FunctionRun(ctx context.Context, fn, schedule string) (string, error)
	FunctionRunStatus(ctx context.Context, fn, run string) (FunctionRun, error)

	DatabaseInstances(ctx context.Context) ([]DatabaseInstance, error)
	DatabaseInstance(ctx context.Context, id string) (DatabaseInstance, error)
	DeleteDatabaseInstance(ctx context.Context, id, confirm string, deleteVolume bool) (DatabaseInstance, error)

	SiteConsoleOptions(ctx context.Context, site string) (ConsoleOptions, error)
	OpenSiteConsole(ctx context.Context, site string, r ConsoleRequest) (ConsoleSession, error)
	OpenDatabaseConsole(ctx context.Context, instance string, r ConsoleRequest) (ConsoleSession, error)
	OpenServerConsole(ctx context.Context, server string, r ConsoleRequest) (ConsoleSession, error)
	ConsoleTicket(ctx context.Context, session string) (ConsoleTicket, error)
	CloseConsole(ctx context.Context, session string) error

	Projects(ctx context.Context) ([]Project, error)
	ConfigValidate(ctx context.Context, project string, r ConfigRequest) (ConfigValidation, error)
	ConfigPlan(ctx context.Context, project string, r ConfigRequest) (ConfigPlanResult, error)
	ConfigApply(ctx context.Context, project string, r ConfigRequest) (ConfigRun, error)
	ConfigRun(ctx context.Context, id string) (ConfigRun, error)
	ConfigExport(ctx context.Context, project, environment, format string) (ConfigExport, error)
	ConfigRuns(ctx context.Context, project, environment string, limit int) ([]ConfigRun, error)
	ConfigApprove(ctx context.Context, id, planHash, comment string) (ConfigRun, error)
	ConfigReject(ctx context.Context, id, comment string) (ConfigRun, error)
	ConfigDrift(ctx context.Context, project, environment string, check bool) (ConfigDrift, error)
	ConfigUnmanaged(ctx context.Context, project, environment string) ([]UnmanagedResource, error)
	ConfigSource(ctx context.Context, project string) (ConfigSource, error)
	SetConfigSource(ctx context.Context, project string, r ConfigSourceRequest) (ConfigSource, error)

	Secrets(ctx context.Context, scope, scopeID string) ([]Secret, error)
	CreateSecret(ctx context.Context, scope, scopeID, name, value string) (Secret, error)
	SetSecretValue(ctx context.Context, id, value string) (Secret, error)
	DeleteSecret(ctx context.Context, id string) error

	CliLoginStart(ctx context.Context, clientName string) (CliLoginStart, error)
	CliLoginToken(ctx context.Context, deviceCode string) (CliLoginResult, string, error)
	RevokeCurrentToken(ctx context.Context) error

	Sandboxes(ctx context.Context, status string) ([]Sandbox, error)
	Sandbox(ctx context.Context, id string) (Sandbox, error)
	CreateSandbox(ctx context.Context, r SandboxCreateRequest, wait bool) (Sandbox, error)
	ExtendSandbox(ctx context.Context, id string, seconds int64) (Sandbox, error)
	KillSandbox(ctx context.Context, id string) error
	SandboxExec(ctx context.Context, id string, r SandboxExecRequest) (SandboxExecResult, error)
	SandboxReadFile(ctx context.Context, id, path string) (data []byte, truncated bool, err error)
	SandboxListFiles(ctx context.Context, id, path string) (json.RawMessage, error)
	SandboxWriteFile(ctx context.Context, id, path, mode string, content []byte) (int64, error)
	OpenSandboxConsole(ctx context.Context, id string, r ConsoleRequest) (ConsoleSession, error)
	SnapshotSandbox(ctx context.Context, id string, r SnapshotRequest, wait bool) (Snapshot, error)
	Snapshots(ctx context.Context, sandbox string) ([]Snapshot, error)
	DeleteSnapshot(ctx context.Context, snapshot string) error
	ForkSnapshot(ctx context.Context, snapshot string, r ForkRequest, wait bool) ([]Sandbox, error)
	PauseSandbox(ctx context.Context, id string) (Sandbox, error)
	ResumeSandbox(ctx context.Context, id string) (Sandbox, error)
	SandboxImportDB(ctx context.Context, id string, r SandboxDB) (SandboxDBResult, error)
}

// Error is a non-2xx API response (Laravel `{message, errors}` bodies are decoded).
type Error struct {
	Status  int
	Method  string
	Path    string
	Message string
	Errors  map[string][]string
	// Body is the raw response body (for endpoints whose errors carry data, e.g. a 409 with the newer version).
	Body []byte
}

func (e *Error) Error() string {
	msg := e.Message
	if msg == "" {
		msg = http.StatusText(e.Status)
	}
	var fields []string
	for k, v := range e.Errors {
		fields = append(fields, k+": "+strings.Join(v, ", "))
	}
	sort.Strings(fields)
	if len(fields) > 0 {
		msg += " (" + strings.Join(fields, "; ") + ")"
	}
	s := fmt.Sprintf("%s %s: HTTP %d: %s", e.Method, e.Path, e.Status, msg)
	switch e.Status {
	case http.StatusUnauthorized:
		s += " — run `falak login` or set FALAK_TOKEN"
	case http.StatusForbidden:
		// The token's abilities, unless the server said why (e.g. an approval's blocked reason).
		if e.Message == "" || e.Message == "This action is unauthorized." || e.Message == "Invalid ability provided." {
			s += " — the token lacks the required ability"
		}
	}
	return s
}

// IsNotFound reports a 404 API error.
func IsNotFound(err error) bool {
	var e *Error
	return errors.As(err, &e) && e.Status == http.StatusNotFound
}

// Client implements API over HTTP.
type Client struct {
	BaseURL   string
	Token     string
	HTTP      *http.Client
	UserAgent string
}

// New returns a Client with sane timeouts.
func New(baseURL, token, userAgent string) *Client {
	return &Client{BaseURL: strings.TrimRight(baseURL, "/"), Token: token, HTTP: &http.Client{Timeout: 60 * time.Second}, UserAgent: userAgent}
}

var _ API = (*Client)(nil)

// Do performs a JSON request. body may be nil; out may be nil.
func (c *Client) Do(ctx context.Context, method, path string, q url.Values, body, out any) error {
	var b []byte
	ctype := ""
	if body != nil {
		var err error
		if b, err = json.Marshal(body); err != nil {
			return err
		}
		ctype = "application/json"
	}
	raw, err := c.DoRaw(ctx, method, path, q, b, ctype)
	if err != nil {
		return err
	}
	if out == nil || len(bytes.TrimSpace(raw)) == 0 {
		return nil
	}
	if err := json.Unmarshal(raw, out); err != nil {
		return fmt.Errorf("%s %s: decode response: %w", method, path, err)
	}
	return nil
}

// DoRaw sends body as is (ctype "" sends none) and returns the raw response body; non-2xx answers are an *Error.
func (c *Client) DoRaw(ctx context.Context, method, path string, q url.Values, body []byte, ctype string) ([]byte, error) {
	raw, _, err := c.doRaw(ctx, method, path, q, body, ctype)
	return raw, err
}

// doRaw is DoRaw with the response headers.
func (c *Client) doRaw(ctx context.Context, method, path string, q url.Values, body []byte, ctype string) ([]byte, http.Header, error) {
	u := c.BaseURL + path
	if len(q) > 0 {
		u += "?" + q.Encode()
	}
	var rd io.Reader
	if body != nil {
		rd = bytes.NewReader(body)
	}
	req, err := http.NewRequestWithContext(ctx, method, u, rd)
	if err != nil {
		return nil, nil, err
	}
	req.Header.Set("Accept", "application/json")
	if ctype != "" {
		req.Header.Set("Content-Type", ctype)
	}
	if c.Token != "" {
		req.Header.Set("Authorization", "Bearer "+c.Token)
	}
	if c.UserAgent != "" {
		req.Header.Set("User-Agent", c.UserAgent)
	}
	hc := c.HTTP
	if hc == nil {
		hc = http.DefaultClient
	}
	resp, err := hc.Do(req)
	if err != nil {
		return nil, nil, err
	}
	defer resp.Body.Close()
	raw, err := io.ReadAll(io.LimitReader(resp.Body, 32<<20))
	if err != nil {
		return nil, nil, err
	}
	if resp.StatusCode/100 != 2 {
		e := &Error{Status: resp.StatusCode, Method: method, Path: path, Body: raw}
		var lb struct {
			Message string              `json:"message"`
			Errors  map[string][]string `json:"errors"`
		}
		if json.Unmarshal(raw, &lb) == nil {
			e.Message, e.Errors = lb.Message, lb.Errors
		} else if len(raw) > 0 && len(raw) < 300 {
			e.Message = strings.TrimSpace(string(raw))
		}
		return nil, nil, e
	}
	return raw, resp.Header, nil
}

func get[T any](ctx context.Context, c *Client, path string, q url.Values) (Envelope[T], error) {
	var env Envelope[T]
	err := c.Do(ctx, http.MethodGet, path, q, nil, &env)
	return env, err
}

func (c *Client) Me(ctx context.Context) (Me, error) {
	env, err := get[Me](ctx, c, PathMe, nil)
	return env.Data, err
}

// Organizations lists organizations; when the endpoint is not available yet it returns the token's
// organization from /me.
func (c *Client) Organizations(ctx context.Context) ([]Organization, error) {
	env, err := get[[]Organization](ctx, c, PathOrganizations, nil)
	if IsNotFound(err) {
		me, merr := c.Me(ctx)
		if merr != nil {
			return nil, merr
		}
		return []Organization{me.Organization}, nil
	}
	return env.Data, err
}

func (c *Client) Servers(ctx context.Context) ([]Server, error) {
	env, err := get[[]Server](ctx, c, PathServers, nil)
	return env.Data, err
}

func (c *Client) Server(ctx context.Context, id string) (Server, error) {
	env, err := get[Server](ctx, c, Path(PathServer, "server", id), nil)
	return env.Data, err
}

func (c *Client) AddServerRuntimes(ctx context.Context, server string, r StackRequest) (StackChange, error) {
	var env Envelope[StackChange]
	err := c.Do(ctx, http.MethodPost, Path(PathServerStack, "server", server), nil, r, &env)
	return env.Data, err
}

func (c *Client) Sites(ctx context.Context) ([]Site, error) {
	env, err := get[[]Site](ctx, c, PathSites, nil)
	return env.Data, err
}

func (c *Client) Site(ctx context.Context, idOrSlug string) (Site, error) {
	env, err := get[Site](ctx, c, Path(PathSite, "site", idOrSlug), nil)
	return env.Data, err
}

func (c *Client) Deploy(ctx context.Context, site, branch string) (Deployment, error) {
	body := map[string]string{}
	if branch != "" {
		body["branch"] = branch
	}
	var env Envelope[Deployment]
	err := c.Do(ctx, http.MethodPost, Path(PathSiteDeployments, "site", site), nil, body, &env)
	return env.Data, err
}

func (c *Client) Deployment(ctx context.Context, id string) (Deployment, error) {
	env, err := get[Deployment](ctx, c, Path(PathDeployment, "deployment", id), nil)
	return env.Data, err
}

// DeploymentOutput returns lines with seq > after and the cursor for the next call.
func (c *Client) DeploymentOutput(ctx context.Context, id string, after int64) ([]OutputLine, int64, error) {
	env, err := get[[]OutputLine](ctx, c, Path(PathDeploymentOutput, "deployment", id), url.Values{"after": {strconv.FormatInt(after, 10)}})
	if err != nil {
		return nil, after, err
	}
	next := after
	for _, l := range env.Data {
		if l.Seq > next {
			next = l.Seq
		}
	}
	if env.Meta.Next != nil && *env.Meta.Next > next {
		next = *env.Meta.Next
	}
	return env.Data, next, nil
}

func (c *Client) Rollback(ctx context.Context, site, releaseID string) (Deployment, error) {
	body := map[string]string{}
	if releaseID != "" {
		body["release_id"] = releaseID
	}
	var env Envelope[Deployment]
	err := c.Do(ctx, http.MethodPost, Path(PathSiteRollback, "site", site), nil, body, &env)
	return env.Data, err
}

func (c *Client) Releases(ctx context.Context, site string) ([]Release, error) {
	env, err := get[[]Release](ctx, c, Path(PathSiteReleases, "site", site), nil)
	return env.Data, err
}

func (c *Client) EnvPull(ctx context.Context, site string) (string, error) {
	env, err := get[EnvFile](ctx, c, Path(PathSiteEnv, "site", site), nil)
	return env.Data.Content, err
}

func (c *Client) EnvPush(ctx context.Context, site, content string) error {
	return c.Do(ctx, http.MethodPut, Path(PathSiteEnv, "site", site), nil, EnvFile{Content: content}, nil)
}

func (c *Client) Logs(ctx context.Context, site string, q LogQuery) ([]LogEntry, string, error) {
	v := url.Values{}
	if q.Since > 0 {
		v.Set("since", strconv.FormatInt(int64(q.Since/time.Second), 10))
	}
	if q.Limit > 0 {
		v.Set("limit", strconv.Itoa(q.Limit))
	}
	if q.Level != "" {
		v.Set("level", q.Level)
	}
	if q.Cursor != "" {
		v.Set("cursor", q.Cursor)
	}
	env, err := get[[]LogEntry](ctx, c, Path(PathSiteLogs, "site", site), v)
	return env.Data, env.Meta.Cursor, err
}
