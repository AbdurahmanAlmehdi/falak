package api

import (
	"context"
	"net/http"
	"net/url"
	"strconv"
)

// Network tool endpoints (`falak net …`, docs/plans/network/tools.md): start a run, then poll it for output.
const (
	PathServerNetTools   = "/api/v1/servers/{server}/net-tools"               // POST NetToolRequest → 201 {data:NetToolRun}
	PathSiteNetTools     = "/api/v1/sites/{site}/net-tools"                   // POST NetToolRequest + {server_id?, service?} → 201
	PathDatabaseNetTools = "/api/v1/databases/instances/{instance}/net-tools" // POST NetToolRequest → 201
	PathNetToolRun       = "/api/v1/net-tools/runs/{run}"                     // GET ?after=<seq> → {data:NetToolRun with lines}
)

// NetToolRequest is a tool and its arguments (only the tool's own fields are sent).
type NetToolRequest struct {
	ServerID   string `json:"server_id,omitempty"`
	Service    string `json:"service,omitempty"`
	Tool       string `json:"tool"`
	Target     string `json:"target,omitempty"`
	URL        string `json:"url,omitempty"`
	Port       int    `json:"port,omitempty"`
	Count      int    `json:"count,omitempty"`
	MaxHops    int    `json:"max_hops,omitempty"`
	RecordType string `json:"record_type,omitempty"`
	Nameserver string `json:"nameserver,omitempty"`
	Method     string `json:"method,omitempty"`
	ConnectTo  string `json:"connect_to,omitempty"`
	Insecure   bool   `json:"insecure,omitempty"`
	TimeoutS   int    `json:"timeout_s,omitempty"`
}

// NetToolRun is a run; Lines and LastSeq come with a poll.
type NetToolRun struct {
	ID         string       `json:"id"`
	Tool       string       `json:"tool"`
	TargetType string       `json:"target_type"`
	TargetName string       `json:"target_name"`
	ServerName string       `json:"server_name"`
	Container  *string      `json:"container"`
	Status     string       `json:"status"` // running, succeeded, failed, timed_out
	ExitCode   *int         `json:"exit_code"`
	Error      *string      `json:"error"`
	Lines      []OutputLine `json:"lines,omitempty"`
	LastSeq    int64        `json:"last_seq"`
}

// Done reports whether the run settled.
func (r NetToolRun) Done() bool { return r.Status != "" && r.Status != "running" }

func (c *Client) StartNetTool(ctx context.Context, path string, r NetToolRequest) (NetToolRun, error) {
	var env Envelope[NetToolRun]
	err := c.Do(ctx, http.MethodPost, path, nil, r, &env)
	return env.Data, err
}

func (c *Client) NetToolRun(ctx context.Context, id string, after int64) (NetToolRun, error) {
	env, err := get[NetToolRun](ctx, c, Path(PathNetToolRun, "run", id), url.Values{"after": {strconv.FormatInt(after, 10)}})
	return env.Data, err
}
