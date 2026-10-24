// Package transport implements the agent side of the control-plane protocol: mTLS HTTP client,
// command long-poll, batched NDJSON event posting, heartbeats and insights.
package transport

import (
	"bytes"
	"context"
	crand "crypto/rand"
	"crypto/tls"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"math/rand/v2"
	"net"
	"net/http"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
)

// Client talks to the mTLS API base URL (e.g. https://agents.falak.example/agent/v1).
type Client struct {
	base string
	hc   *http.Client
	// UserAgent is sent on every request.
	UserAgent string
	// Session identifies this agent process (SessionHeader on every request). The control plane redelivers or
	// fails commands delivered to an earlier session, and ignores long-polls left behind by one.
	Session string
	// AgentID and Log report a revoked agent (see IsRevoked); a nil Log disables the report.
	AgentID string
	Log     *slog.Logger

	revokedMu   sync.Mutex
	revokedLast time.Time
}

// RevokedReportEvery rate-limits the "server was removed" error: every loop hits it, every few seconds.
const RevokedReportEvery = 10 * time.Minute

// RevokedRetry is how long a loop waits after its request was answered agent_revoked (the identity stays revoked
// until a new install command replaces it, which restarts the agent). Every component that posts to the agent API
// waits this long and logs nothing itself: the client's rate-limited message says it once.
var RevokedRetry = 10 * time.Minute

// SessionHeader carries Client.Session.
const SessionHeader = "X-Falak-Agent-Session"

// NewSessionID returns a random id for one agent process ("s-" + 32 hex characters).
func NewSessionID() string {
	var b [16]byte
	if _, err := crand.Read(b[:]); err != nil {
		panic(err) // crypto/rand never fails on supported platforms
	}
	return "s-" + hex.EncodeToString(b[:])
}

// New creates a client; tlsConf should come from enroll.Identity.TLSConfig().
func New(apiBase string, tlsConf *tls.Config) *Client {
	tr := &http.Transport{
		Proxy:               http.ProxyFromEnvironment,
		DialContext:         (&net.Dialer{Timeout: 15 * time.Second, KeepAlive: 30 * time.Second}).DialContext,
		TLSClientConfig:     tlsConf,
		TLSHandshakeTimeout: 15 * time.Second,
		MaxIdleConnsPerHost: 4,
		IdleConnTimeout:     90 * time.Second,
		ForceAttemptHTTP2:   true,
		// Ping a quiet HTTP/2 connection and drop it when the ping goes unanswered: without this a connection to an
		// address that vanished (the control plane moved to a new IP) is reused forever and every request times out.
		HTTP2: &http.HTTP2Config{SendPingTimeout: 30 * time.Second, PingTimeout: 10 * time.Second},
	}
	return NewWithHTTPClient(apiBase, &http.Client{Transport: tr})
}

// NewHTTP1Client returns a client with the same mTLS identity that speaks HTTP/1.1 only (no h2 in ALPN): the
// terminal relay's WebSocket upgrade needs it. The transport's timeouts bound the handshake; the connection itself
// lives as long as the WebSocket.
func NewHTTP1Client(tlsConf *tls.Config) *http.Client {
	tc := tlsConf.Clone()
	tc.NextProtos = []string{"http/1.1"}
	return &http.Client{Transport: &http.Transport{
		Proxy:                 http.ProxyFromEnvironment,
		DialContext:           (&net.Dialer{Timeout: 15 * time.Second, KeepAlive: 30 * time.Second}).DialContext,
		TLSClientConfig:       tc,
		TLSHandshakeTimeout:   15 * time.Second,
		ResponseHeaderTimeout: 15 * time.Second,
		ForceAttemptHTTP2:     false,
		TLSNextProto:          map[string]func(string, *tls.Conn) http.RoundTripper{},
	}}
}

// NewWithHTTPClient uses a caller-provided http.Client (tests).
func NewWithHTTPClient(apiBase string, hc *http.Client) *Client {
	return &Client{base: strings.TrimRight(apiBase, "/"), hc: hc, UserAgent: "falak-agent"}
}

// StatusError is a non-2xx response.
type StatusError struct {
	Code int
	Body string
	// Reason is the body's "error" code when the control plane sent one (e.g. ReasonAgentRevoked).
	Reason string
	// RetryAfter is the Retry-After delay from the response header (>0 when present and parseable).
	RetryAfter time.Duration
}

// RetryAfterDuration returns the server-requested backoff from err when present (>0), otherwise 0.
// Callers fall back to their own exponential backoff when this returns 0.
func RetryAfterDuration(err error) time.Duration {
	var se *StatusError
	if errors.As(err, &se) {
		return se.RetryAfter
	}
	return 0
}

func (e *StatusError) Error() string { return fmt.Sprintf("HTTP %d: %s", e.Code, e.Body) }

// ReasonAgentRevoked is the 401 reason for the certificate of an agent whose server was deleted from Falak.
// Older control planes answer a plain 401.
const ReasonAgentRevoked = "agent_revoked"

// IsRevoked reports whether err is a 401 for a revoked agent.
func IsRevoked(err error) bool {
	var se *StatusError
	return errors.As(err, &se) && se.Code == http.StatusUnauthorized && se.Reason == ReasonAgentRevoked
}

// RevokedMessage tells the operator what a revoked identity means and what to do.
func RevokedMessage(agentID string) string {
	return fmt.Sprintf("this agent was revoked or its server was removed from Falak (agent %s); run a new install command from the panel to connect this machine again", agentID)
}

func (c *Client) reportRevoked() {
	if c.Log == nil {
		return
	}
	c.revokedMu.Lock()
	now := time.Now()
	due := c.revokedLast.IsZero() || now.Sub(c.revokedLast) >= RevokedReportEvery
	if due {
		c.revokedLast = now
	}
	c.revokedMu.Unlock()
	if due {
		c.Log.Error(RevokedMessage(c.AgentID))
	}
}

// Retryable reports whether a failed request should be retried later.
func Retryable(err error) bool {
	var se *StatusError
	if errors.As(err, &se) {
		return se.Code == 401 || se.Code == 408 || se.Code == 425 || se.Code == 429 || se.Code >= 500
	}
	return true // network errors
}

func (c *Client) do(ctx context.Context, method, path, ctype string, body []byte, timeout time.Duration) ([]byte, error) {
	return c.doWith(ctx, method, path, ctype, body, timeout, nil)
}

// Upload posts one raw chunk (application/octet-stream) to path, e.g. "/uploads/net.capture/<id>?offset=0", with the
// given headers (checksums). Fleet hands it to the module that registered the upload type.
func (c *Client) Upload(ctx context.Context, path string, body []byte, headers map[string]string) error {
	_, err := c.doWith(ctx, http.MethodPost, path, "application/octet-stream", body, 2*time.Minute, headers)
	return err
}

func (c *Client) doWith(ctx context.Context, method, path, ctype string, body []byte, timeout time.Duration, headers map[string]string) ([]byte, error) {
	if timeout > 0 {
		var cancel context.CancelFunc
		ctx, cancel = context.WithTimeout(ctx, timeout)
		defer cancel()
	}
	var rd io.Reader
	if body != nil {
		rd = bytes.NewReader(body)
	}
	req, err := http.NewRequestWithContext(ctx, method, c.base+path, rd)
	if err != nil {
		return nil, err
	}
	if ctype != "" {
		req.Header.Set("Content-Type", ctype)
	}
	req.Header.Set("Accept", "application/json")
	req.Header.Set("User-Agent", c.UserAgent)
	for k, v := range headers {
		req.Header.Set(k, v)
	}
	if c.Session != "" {
		req.Header.Set(SessionHeader, c.Session)
	}
	resp, err := c.hc.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	out, err := io.ReadAll(io.LimitReader(resp.Body, 16<<20))
	if err != nil {
		return nil, err
	}
	if resp.StatusCode/100 != 2 {
		b := strings.TrimSpace(string(out))
		if len(b) > 512 {
			b = b[:512]
		}
		se := &StatusError{Code: resp.StatusCode, Body: b}
		var shape struct {
			Error string `json:"error"`
		}
		if json.Unmarshal(out, &shape) == nil {
			se.Reason = shape.Error
		}
		if ra := resp.Header.Get("Retry-After"); ra != "" {
			if secs, err := strconv.Atoi(strings.TrimSpace(ra)); err == nil && secs > 0 {
				se.RetryAfter = time.Duration(secs) * time.Second
			}
		}
		if IsRevoked(se) {
			c.reportRevoked()
		}
		return nil, se
	}
	return out, nil
}

// Pong is the GET /ping response.
type Pong struct {
	AgentID string    `json:"agent_id"`
	Time    time.Time `json:"time"`
}

// Ping calls GET /ping, an authenticated no-op (falak-agent check).
func (c *Client) Ping(ctx context.Context) (Pong, error) {
	var p Pong
	out, err := c.do(ctx, http.MethodGet, "/ping", "", nil, 15*time.Second)
	if err != nil {
		return p, err
	}
	if err := json.Unmarshal(out, &p); err != nil || p.AgentID == "" {
		return p, fmt.Errorf("ping: unexpected response: %s", strings.TrimSpace(string(out)))
	}
	return p, nil
}

// Poll long-polls GET /commands?wait=N.
func (c *Client) Poll(ctx context.Context, wait int) ([]commands.Envelope, error) {
	body, err := c.do(ctx, http.MethodGet, "/commands?wait="+strconv.Itoa(wait), "", nil, time.Duration(wait+30)*time.Second)
	if err != nil {
		return nil, err
	}
	if len(bytes.TrimSpace(body)) == 0 {
		return nil, nil
	}
	var r struct {
		Commands []commands.Envelope `json:"commands"`
	}
	if err := json.Unmarshal(body, &r); err != nil {
		return nil, fmt.Errorf("decode commands: %w", err)
	}
	return r.Commands, nil
}

// PostEvents posts NDJSON events for one command.
func (c *Client) PostEvents(ctx context.Context, commandID string, evs []commands.Event) error {
	var buf bytes.Buffer
	enc := json.NewEncoder(&buf) // Encode appends '\n' → NDJSON
	for _, e := range evs {
		if err := enc.Encode(e); err != nil {
			return err
		}
	}
	_, err := c.do(ctx, http.MethodPost, "/commands/"+commandID+"/events", "application/x-ndjson", buf.Bytes(), 30*time.Second)
	return err
}

// Heartbeat posts a heartbeat document.
func (c *Client) Heartbeat(ctx context.Context, hb any) error {
	b, err := json.Marshal(hb)
	if err != nil {
		return err
	}
	_, err = c.do(ctx, http.MethodPost, "/heartbeat", "application/json", b, 20*time.Second)
	return err
}

// PostInsights posts NDJSON insight lines (implements the insights/cron sink interfaces).
func (c *Client) PostInsights(ctx context.Context, ndjson []byte) error {
	_, err := c.do(ctx, http.MethodPost, "/insights", "application/x-ndjson", ndjson, 30*time.Second)
	return err
}

// Request posts one JSON request to the control plane (POST /requests/<type>) and decodes its JSON reply into out (nil:
// ignored). Modules answer them: pitr.upload_urls, pitr.shipped and pitr.gap (contracts/agent-protocol/README.md).
func (c *Client) Request(ctx context.Context, typ string, in, out any) error {
	b, err := json.Marshal(in)
	if err != nil {
		return err
	}
	body, err := c.do(ctx, http.MethodPost, "/requests/"+typ, "application/json", b, 60*time.Second)
	if err != nil {
		return err
	}
	if out == nil || len(bytes.TrimSpace(body)) == 0 {
		return nil
	}
	if err := json.Unmarshal(body, out); err != nil {
		return fmt.Errorf("%s: decode reply: %w", typ, err)
	}
	return nil
}

// Renew posts a CSR to /renew and returns the new certificate PEM.
func (c *Client) Renew(ctx context.Context, csrPEM []byte) ([]byte, error) {
	b, _ := json.Marshal(map[string]string{"csr_pem": string(csrPEM)})
	out, err := c.do(ctx, http.MethodPost, "/renew", "application/json", b, 60*time.Second)
	if err != nil {
		return nil, err
	}
	var r struct {
		CertPEM string `json:"cert_pem"`
	}
	if err := json.Unmarshal(out, &r); err != nil || r.CertPEM == "" {
		return nil, fmt.Errorf("renew: bad response: %v", err)
	}
	return []byte(r.CertPEM), nil
}

// Backoff is exponential with full jitter.
type Backoff struct {
	Min, Max time.Duration
	n        int
}

// Next returns the next delay.
func (b *Backoff) Next() time.Duration {
	min, max := b.Min, b.Max
	if min <= 0 {
		min = 500 * time.Millisecond
	}
	if max <= 0 {
		max = 30 * time.Second
	}
	d := min << min64(b.n, 20)
	if d > max || d <= 0 {
		d = max
	}
	b.n++
	return d/2 + time.Duration(rand.Int64N(int64(d/2)+1))
}

// Reset after success.
func (b *Backoff) Reset() { b.n = 0 }

func min64(a, b int) int {
	if a < b {
		return a
	}
	return b
}

func sleep(ctx context.Context, d time.Duration) bool {
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
		return false
	case <-t.C:
		return true
	}
}
