package api

import (
	"context"
	"fmt"
	"io"
	"net/http"
)

// Packet capture endpoints (`falak net capture`, docs/plans/network/capture.md).
const (
	PathServerNetCaptures   = "/api/v1/servers/{server}/net-captures"               // POST NetCaptureRequest → 201 {data:NetCapture}
	PathSiteNetCaptures     = "/api/v1/sites/{site}/net-captures"                   // POST NetCaptureRequest + {server_id?, service?} → 201
	PathDatabaseNetCaptures = "/api/v1/databases/instances/{instance}/net-captures" // POST NetCaptureRequest → 201
	PathNetCapture          = "/api/v1/net-captures/{capture}"                      // GET → {data:NetCapture}
	PathNetCaptureCancel    = "/api/v1/net-captures/{capture}/cancel"               // POST → {data:NetCapture}
	PathNetCaptureDownload  = "/api/v1/net-captures/{capture}/download"             // GET → the pcap
)

// NetCaptureFilter is the structured filter (never a BPF expression).
type NetCaptureFilter struct {
	Host      string `json:"host,omitempty"`
	Port      int    `json:"port,omitempty"`
	Protocol  string `json:"protocol,omitempty"`
	Direction string `json:"direction,omitempty"`
}

// NetCaptureRequest starts a capture.
type NetCaptureRequest struct {
	ServerID     string            `json:"server_id,omitempty"`
	Service      string            `json:"service,omitempty"`
	Interface    string            `json:"interface,omitempty"`
	DurationS    int               `json:"duration_s,omitempty"`
	MaxMB        int               `json:"max_mb,omitempty"`
	FullPackets  bool              `json:"full_packets,omitempty"`
	Filter       *NetCaptureFilter `json:"filter,omitempty"`
	Acknowledged bool              `json:"acknowledged"`
}

// NetCapture is a capture's state.
type NetCapture struct {
	ID              string  `json:"id"`
	TargetName      string  `json:"target_name"`
	ServerName      string  `json:"server_name"`
	Container       *string `json:"container"`
	Interface       *string `json:"interface"`
	Status          string  `json:"status"` // queued, capturing, uploading, ready, failed, cancelled, expired
	DurationS       int     `json:"duration_s"`
	BytesCaptured   int64   `json:"bytes_captured"`
	PacketsCaptured int64   `json:"packets_captured"`
	SizeBytes       *int64  `json:"size_bytes"`
	SHA256          *string `json:"sha256"`
	Error           *string `json:"error"`
	ExpiresAt       string  `json:"expires_at"`
}

// Done reports whether the capture settled (ready or not).
func (c NetCapture) Done() bool {
	switch c.Status {
	case "queued", "capturing", "uploading", "":
		return false
	}
	return true
}

func (c *Client) StartNetCapture(ctx context.Context, path string, r NetCaptureRequest) (NetCapture, error) {
	var env Envelope[NetCapture]
	err := c.Do(ctx, http.MethodPost, path, nil, r, &env)
	return env.Data, err
}

func (c *Client) NetCapture(ctx context.Context, id string) (NetCapture, error) {
	env, err := get[NetCapture](ctx, c, Path(PathNetCapture, "capture", id), nil)
	return env.Data, err
}

func (c *Client) CancelNetCapture(ctx context.Context, id string) (NetCapture, error) {
	var env Envelope[NetCapture]
	err := c.Do(ctx, http.MethodPost, Path(PathNetCaptureCancel, "capture", id), nil, nil, &env)
	return env.Data, err
}

// DownloadNetCapture streams the pcap into w (no overall timeout: up to 100 MiB; ctx bounds it).
func (c *Client) DownloadNetCapture(ctx context.Context, id string, w io.Writer) (int64, error) {
	path := Path(PathNetCaptureDownload, "capture", id)
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, c.BaseURL+path, nil)
	if err != nil {
		return 0, err
	}
	req.Header.Set("Authorization", "Bearer "+c.Token)
	if c.UserAgent != "" {
		req.Header.Set("User-Agent", c.UserAgent)
	}
	hc := &http.Client{}
	if c.HTTP != nil {
		hc.Transport = c.HTTP.Transport
	}
	resp, err := hc.Do(req)
	if err != nil {
		return 0, err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		raw, _ := io.ReadAll(io.LimitReader(resp.Body, 4096))
		return 0, &Error{Status: resp.StatusCode, Method: http.MethodGet, Path: path, Body: raw, Message: fmt.Sprintf("download failed (HTTP %d)", resp.StatusCode)}
	}
	return io.Copy(w, resp.Body)
}
