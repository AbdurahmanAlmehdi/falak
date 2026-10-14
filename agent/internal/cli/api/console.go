package api

import (
	"context"
	"net/http"
)

// Console endpoints (`falak console …`, docs/plans/CONSOLE.md). A console is a terminal session reached through the
// terminal relay: the answer carries the relay's WebSocket URL with a single-use ticket.
const (
	PathSiteConsole           = "/api/v1/sites/{site}/console"                   // GET → {data:ConsoleOptions}; POST ConsoleRequest → 201 {data:ConsoleSession}
	PathDatabaseConsole       = "/api/v1/databases/instances/{instance}/console" // POST {cols?, rows?} → 201 {data:ConsoleSession}
	PathServerConsole         = "/api/v1/servers/{server}/console"               // POST {user?, cols?, rows?} → 201 {data:ConsoleSession}
	PathTerminalSession       = "/api/v1/terminal/sessions/{session}"            // DELETE → closes the session
	PathTerminalSessionTicket = "/api/v1/terminal/sessions/{session}/ticket"     // POST → {data:ConsoleTicket} (reconnect)
)

// ConsoleRequest opens a console. ServerID and Service apply to sites, User to servers.
type ConsoleRequest struct {
	ServerID string `json:"server_id,omitempty"`
	Service  string `json:"service,omitempty"`
	User     string `json:"user,omitempty"`
	Cols     int    `json:"cols,omitempty"`
	Rows     int    `json:"rows,omitempty"`
}

// ConsoleSession is an opened console.
type ConsoleSession struct {
	SessionID  string `json:"session_id"`
	URL        string `json:"url"`
	Writable   bool   `json:"writable"`
	ServerID   string `json:"server_id"`
	ServerName string `json:"server_name"`
	TargetType string `json:"target_type"` // server, site or database
	TargetName string `json:"target_name"`
	// Container is the resolved selector (docker:<name>, compose:<service>; null for native sites and servers).
	Container *string `json:"container"`
}

// ConsoleTicket is a new single-use ticket for a session (reconnect).
type ConsoleTicket struct {
	URL      string `json:"url"`
	Writable bool   `json:"writable"`
}

// ConsoleOptions is what a site's console offers: its servers and, for compose, its services.
type ConsoleOptions struct {
	Servers  []ConsoleServer `json:"servers"`
	Services []string        `json:"services"`
	Runtime  string          `json:"runtime"`
}

type ConsoleServer struct {
	ID     string `json:"id"`
	Name   string `json:"name"`
	Leader bool   `json:"leader"`
}

func (c *Client) SiteConsoleOptions(ctx context.Context, site string) (ConsoleOptions, error) {
	env, err := get[ConsoleOptions](ctx, c, Path(PathSiteConsole, "site", site), nil)
	return env.Data, err
}

func (c *Client) OpenSiteConsole(ctx context.Context, site string, r ConsoleRequest) (ConsoleSession, error) {
	return c.openConsole(ctx, Path(PathSiteConsole, "site", site), r)
}

func (c *Client) OpenDatabaseConsole(ctx context.Context, instance string, r ConsoleRequest) (ConsoleSession, error) {
	return c.openConsole(ctx, Path(PathDatabaseConsole, "instance", instance), r)
}

func (c *Client) OpenServerConsole(ctx context.Context, server string, r ConsoleRequest) (ConsoleSession, error) {
	return c.openConsole(ctx, Path(PathServerConsole, "server", server), r)
}

func (c *Client) openConsole(ctx context.Context, path string, r ConsoleRequest) (ConsoleSession, error) {
	var env Envelope[ConsoleSession]
	err := c.Do(ctx, http.MethodPost, path, nil, r, &env)
	return env.Data, err
}

func (c *Client) ConsoleTicket(ctx context.Context, session string) (ConsoleTicket, error) {
	var env Envelope[ConsoleTicket]
	err := c.Do(ctx, http.MethodPost, Path(PathTerminalSessionTicket, "session", session), nil, nil, &env)
	return env.Data, err
}

func (c *Client) CloseConsole(ctx context.Context, session string) error {
	return c.Do(ctx, http.MethodDelete, Path(PathTerminalSession, "session", session), nil, nil, nil)
}
