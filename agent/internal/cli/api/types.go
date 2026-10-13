package api

import "time"

// Envelope is the Laravel resource wrapper.
type Envelope[T any] struct {
	Data T    `json:"data"`
	Meta Meta `json:"meta,omitempty"`
}

// Meta carries pagination / cursor info.
type Meta struct {
	Next   *int64 `json:"next,omitempty"`   // deployment output: next `after` value
	Cursor string `json:"cursor,omitempty"` // logs: opaque cursor for the next page
}

// Me is GET /me.
type Me struct {
	User struct {
		ID    string `json:"id"`
		Name  string `json:"name"`
		Email string `json:"email"`
	} `json:"user"`
	Organization Organization `json:"organization"`
	Token        *struct {
		Name      string   `json:"name"`
		Abilities []string `json:"abilities"`
	} `json:"token"`
}

// Organization summary.
type Organization struct {
	ID   string `json:"id"`
	Name string `json:"name"`
	Slug string `json:"slug,omitempty"`
	Role string `json:"role,omitempty"`
}

// Server mirrors the Servers module API summary (+ SSH hints).
type Server struct {
	ID            string `json:"id"`
	Name          string `json:"name"`
	Type          string `json:"type"`
	Status        string `json:"status"`
	StatusMessage string `json:"status_message,omitempty"`
	Provider      string `json:"provider"`
	Region        string `json:"region,omitempty"`
	IPv4          string `json:"ipv4,omitempty"`
	PrivateIPv4   string `json:"private_ipv4,omitempty"`
	PHP           string `json:"php,omitempty"`
	SSHUser       string `json:"ssh_user,omitempty"`
	SSHPort       int    `json:"ssh_port,omitempty"`
	Agent         *struct {
		Status          string `json:"status"`
		LastHeartbeatAt string `json:"last_heartbeat_at"`
	} `json:"agent,omitempty"`
	Load1         *float64 `json:"load1,omitempty"`
	MemoryPercent *float64 `json:"memory_percent,omitempty"`
	DiskPercent   *float64 `json:"disk_percent,omitempty"`
	CreatedAt     string   `json:"created_at,omitempty"`
}

// StackRequest adds runtimes to a server created without them (POST /servers/{server}/stack).
type StackRequest struct {
	PHP  *StackPHP `json:"php,omitempty"`
	Node string    `json:"node,omitempty"`
}

// StackPHP: runtime fpm | frankenphp (site servers) or cli (builders); version empty = the installation's default.
type StackPHP struct {
	Runtime string `json:"runtime"`
	Version string `json:"version,omitempty"`
}

// StackChange is the server's new stack and the re-provisioning that installs it.
type StackChange struct {
	Stack struct {
		PHP *struct {
			Runtime  string   `json:"runtime"`
			Versions []string `json:"versions"`
		} `json:"php"`
		Node string `json:"node"`
	} `json:"stack"`
	Status        string `json:"status"`
	StatusMessage string `json:"status_message,omitempty"`
	CommandID     string `json:"command_id,omitempty"`
}

// Site summary.
type Site struct {
	ID             string   `json:"id"`
	Slug           string   `json:"slug"`
	Name           string   `json:"name,omitempty"`
	Domain         string   `json:"domain,omitempty"`
	Aliases        []string `json:"aliases,omitempty"`
	URL            string   `json:"url,omitempty"`
	Runtime        string   `json:"runtime,omitempty"`
	BuildMode      string   `json:"build_mode,omitempty"`
	Strategy       string   `json:"strategy,omitempty"`
	Status         string   `json:"status,omitempty"`
	Repository     string   `json:"repository,omitempty"`
	Branch         string   `json:"branch,omitempty"`
	ServerIDs      []string `json:"server_ids,omitempty"`
	CurrentRelease *Release `json:"current_release,omitempty"`
	CreatedAt      string   `json:"created_at,omitempty"`
}

// Deployment of a site.
type Deployment struct {
	ID        string `json:"id"`
	SiteID    string `json:"site_id,omitempty"`
	Status    string `json:"status"` // queued|waiting|building|deploying|succeeded|failed|cancelled
	Trigger   string `json:"trigger,omitempty"`
	Branch    string `json:"branch,omitempty"`
	Commit    string `json:"commit,omitempty"`
	Message   string `json:"message,omitempty"`
	ReleaseID string `json:"release_id,omitempty"`
	URL       string `json:"url,omitempty"` // panel URL
	Error     string `json:"error,omitempty"`
	// Set while Status is "waiting" (the site's servers are still being prepared; it starts on its own).
	WaitingReason string `json:"waiting_reason,omitempty"`
	CreatedAt     string `json:"created_at,omitempty"`
	StartedAt     string `json:"started_at,omitempty"`
	FinishedAt    string `json:"finished_at,omitempty"`
}

// Deployment statuses the CLI understands. Unknown statuses are treated as in progress.
var (
	successStatuses = map[string]bool{"succeeded": true, "success": true, "finished": true, "deployed": true}
	failureStatuses = map[string]bool{"failed": true, "error": true, "cancelled": true, "canceled": true, "rolled_back": true, "timed_out": true}
)

// Done reports whether the deployment reached a terminal status.
func (d Deployment) Done() bool { return successStatuses[d.Status] || failureStatuses[d.Status] }

// Succeeded reports a successful terminal status.
func (d Deployment) Succeeded() bool { return successStatuses[d.Status] }

// OutputLine is one deployment log line.
type OutputLine struct {
	Seq    int64  `json:"seq"`
	At     string `json:"at,omitempty"`
	Server string `json:"server,omitempty"` // server name, "" for build/orchestration output
	Phase  string `json:"phase,omitempty"`  // build|fetch|prepare|migrate|activate|restart|healthcheck
	Stream string `json:"stream,omitempty"` // stdout|stderr
	Data   string `json:"data"`
}

// Release of a site.
type Release struct {
	ID           string `json:"id"`
	Commit       string `json:"commit,omitempty"`
	Branch       string `json:"branch,omitempty"`
	DeploymentID string `json:"deployment_id,omitempty"`
	Active       bool   `json:"active,omitempty"`
	CreatedAt    string `json:"created_at,omitempty"`
}

// LogEntry is one application/server log line.
type LogEntry struct {
	At      string            `json:"at"`
	Level   string            `json:"level,omitempty"`
	Source  string            `json:"source,omitempty"` // laravel, php-fpm, caddy, worker:default, ...
	Server  string            `json:"server,omitempty"`
	Message string            `json:"message"`
	Attrs   map[string]string `json:"attributes,omitempty"`
}

// LogQuery filters GET /sites/{site}/logs.
type LogQuery struct {
	Since  time.Duration
	Limit  int
	Level  string
	Cursor string
}

// EnvFile is GET/PUT /sites/{site}/env.
type EnvFile struct {
	Content string `json:"content"`
}
