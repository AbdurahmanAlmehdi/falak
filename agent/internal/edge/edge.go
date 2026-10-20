package edge

import (
	"bytes"
	"context"
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"log/slog"
	"net"
	"net/http"
	"os"
	"os/user"
	"path"
	"path/filepath"
	"strings"
	"sync"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/localedge"
	"github.com/OthmanHaba/falak/agent/internal/logs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// Client is a minimal Caddy admin API client.
type Client struct {
	// Base is unix:///run/falak-edge/admin.sock (the admin socket) or an http:// URL (tests).
	Base string
	// Fallback is used while the socket does not exist yet: a Caddy started from an older config, before the first
	// edge.caddy.apply moves its admin API to the socket.
	Fallback string
	HTTP     *http.Client
}

func (c *Client) socket() string {
	if p, ok := strings.CutPrefix(c.Base, "unix://"); ok {
		if _, err := os.Stat(p); err == nil || c.Fallback == "" {
			return p
		}
	}
	return ""
}

// base and hc take the socket() result of one request, so a socket appearing mid-request can't mix the two paths.
func (c *Client) base(sock string) string {
	if sock != "" {
		// Caddy accepts Host: localhost on its unix socket admin listener.
		return "http://localhost"
	}
	if strings.HasPrefix(c.Base, "unix://") {
		return strings.TrimRight(c.Fallback, "/")
	}
	return strings.TrimRight(c.Base, "/")
}

func (c *Client) hc(sock string) *http.Client {
	if c.HTTP != nil {
		return c.HTTP
	}
	if sock != "" {
		return &http.Client{Timeout: 60 * time.Second, Transport: &http.Transport{
			DialContext: func(ctx context.Context, _, _ string) (net.Conn, error) {
				return (&net.Dialer{}).DialContext(ctx, "unix", sock)
			},
		}}
	}
	return &http.Client{Timeout: 60 * time.Second}
}

func (c *Client) req(ctx context.Context, method, p string, body []byte, headers ...string) ([]byte, error) {
	var rd io.Reader
	if body != nil {
		rd = bytes.NewReader(body)
	}
	sock := c.socket()
	base := c.base(sock)
	r, err := http.NewRequestWithContext(ctx, method, base+p, rd)
	if err != nil {
		return nil, err
	}
	if body != nil {
		r.Header.Set("Content-Type", "application/json")
	}
	// Over TCP, Caddy checks Host/Origin against the listen address: a matching Origin keeps requests accepted. On the
	// unix socket it allows no origins at all and checks one only when the request carries it, so none is sent there
	// (with one, every admin call got 403 "client is not allowed to access from origin").
	if sock == "" {
		r.Header.Set("Origin", base)
	}
	for i := 0; i+1 < len(headers); i += 2 {
		r.Header.Set(headers[i], headers[i+1])
	}
	resp, err := c.hc(sock).Do(r)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	out, _ := io.ReadAll(io.LimitReader(resp.Body, 32<<20))
	if resp.StatusCode/100 != 2 {
		return nil, fmt.Errorf("caddy admin %s %s: HTTP %d: %s", method, p, resp.StatusCode, strings.TrimSpace(string(out)))
	}
	return out, nil
}

// Load replaces the whole config atomically (Caddy rolls back on failure).
func (c *Client) Load(ctx context.Context, cfg []byte) error {
	_, err := c.req(ctx, http.MethodPost, "/load", cfg)
	return err
}

// Config returns the running config (JSON, "null" when empty).
func (c *Client) Config(ctx context.Context) ([]byte, error) {
	return c.req(ctx, http.MethodGet, "/config/", nil)
}

// SetUpstreams replaces the upstreams of the reverse_proxy handler of an edge site (used by
// deploy.container.swap). The change is persisted by the Manager.
func (c *Client) SetUpstreams(ctx context.Context, routeID string, dials []string) error {
	ups := make([]map[string]string, 0, len(dials))
	for _, d := range dials {
		ups = append(ups, map[string]string{"dial": d})
	}
	b, _ := json.Marshal(ups)
	_, err := c.req(ctx, http.MethodPatch, "/id/"+UpstreamsID(routeID)+"/upstreams", b)
	return err
}

// ReloadFrankenPHP makes a freshly activated release live. FrankenPHP resolves a site's root symlink
// (current -> releases/<id>) when its php handler is provisioned, not per request, so the running
// config is force-reloaded (Caddy skips identical configs unless told to revalidate); that also
// restarts worker scripts gracefully.
func (c *Client) ReloadFrankenPHP(ctx context.Context) error {
	cfg, err := c.Config(ctx)
	if err != nil {
		return err
	}
	if t := bytes.TrimSpace(cfg); len(t) == 0 || bytes.Equal(t, []byte("null")) {
		_, err = c.req(ctx, http.MethodPost, "/frankenphp/workers/restart", nil)
		return err
	}
	_, err = c.req(ctx, http.MethodPost, "/load", cfg, "Cache-Control", "must-revalidate")
	return err
}

// Options for the Manager.
type Options struct {
	Client *Client
	FS     hostfs.FS
	EtcDir string // /etc/falak (certs/ and caddy/bootstrap.json live here)
	Logger *slog.Logger
	// RestartEdge restarts falak-edge.service: its environment (DNS provider tokens, EdgeEnv) is only read at start.
	RestartEdge func(ctx context.Context) error
	// Runner and HTTP install Falak's Caddy build when a config needs the rate_limit module (ratelimit.go).
	Runner runner.Runner
	HTTP   *http.Client
	// AdminWait is how long the admin API may take to answer after a restart (0 = 30 s).
	AdminWait time.Duration
}

// Manager implements edge.* executors.
type Manager struct {
	o  Options
	mu sync.Mutex
}

// New creates a Manager.
func New(o Options) *Manager {
	if o.Logger == nil {
		o.Logger = slog.Default()
	}
	if o.EtcDir == "" {
		o.EtcDir = "/etc/falak"
	}
	return &Manager{o: o}
}

func (m *Manager) certDir() string       { return path.Join(m.o.EtcDir, "certs") }
func (m *Manager) bootstrapPath() string { return path.Join(m.o.EtcDir, "caddy", "bootstrap.json") }

// EnvPath is the edge service's environment file (falak-edge.service EnvironmentFile): DNS provider tokens.
func (m *Manager) EnvPath() string { return path.Join(m.o.EtcDir, "caddy", "edge.env") }

// syncEnv writes the tokens the config refers to into the edge's environment file (root only, read by systemd when
// the service starts) and restarts the edge when they changed, before the config that needs them is loaded.
func (m *Manager) syncEnv(ctx context.Context, p Payload, s commands.Stream) error {
	changed, err := m.o.FS.WriteFile(m.EnvPath(), p.Environment(), 0o600)
	if err != nil || !changed || m.o.RestartEdge == nil {
		return err
	}
	fmt.Fprintln(s.Stdout(), "DNS provider tokens changed: restarting the edge")
	if err := m.o.RestartEdge(ctx); err != nil {
		return fmt.Errorf("restart falak-edge: %w", err)
	}
	return m.waitAdmin(ctx)
}

// waitAdmin waits up to 30 s for the edge's admin API after a restart.
func (m *Manager) waitAdmin(ctx context.Context) error {
	tries := 60
	if m.o.AdminWait > 0 {
		tries = max(int(m.o.AdminWait/(500*time.Millisecond)), 1)
	}
	for i := 0; i < tries; i++ {
		if _, err := m.o.Client.Config(ctx); err == nil {
			return nil
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(500 * time.Millisecond):
		}
	}
	return errors.New("the edge did not come back after its restart")
}

// Register adds edge.caddy.apply and edge.cert.install.
func (m *Manager) Register(reg *commands.Registry) {
	reg.Register("edge.caddy.apply", commands.Typed(m.Apply))
	reg.Register("edge.cert.install", commands.Typed(m.InstallCert))
}

// ApplyResult is edge.caddy.apply's result.
type ApplyResult struct {
	Changed      bool   `json:"changed"`
	ConfigSHA256 string `json:"config_sha256"`
	Routes       int    `json:"routes"`
	// RateLimitsRefused says why the payload's rate limits and DNS-01 certificates were left out of the applied config
	// (edge.rate_limit, edge.caddy_dns).
	RateLimitsRefused string `json:"rate_limits_refused,omitempty"`
}

// Apply renders and loads the full config; no-op when the running config is already identical.
func (m *Manager) Apply(ctx context.Context, p Payload, s commands.Stream) (any, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	// Read on every apply: falak-ctl writes it when the control-plane machine becomes a server (localedge.Path).
	local, err := localedge.Load(m.o.FS, path.Join(m.o.EtcDir, "local-edge.env"))
	if err != nil {
		return nil, err
	}
	p.LocalEdge = local
	// Modules the edge cannot run (no module and no usable build, a FrankenPHP edge): the config is applied without
	// rate limits and with DNS-01 certificates over HTTP, and the result says why, so the panel can show it. Validated
	// first: a bad payload is refused as before.
	if _, err := Render(p, m.certDir()); err != nil {
		return nil, &commands.PayloadError{Err: err}
	}
	refused, err := m.ensureCaddyModules(ctx, p, s)
	if err != nil {
		return nil, err
	}
	if refused != "" {
		fmt.Fprintf(s.Stderr(), "rate limits and DNS-01 certificates not applied (HTTP-01 instead): %s\n", refused)
		p = p.withoutFalakModules()
	}
	cfg, err := Render(p, m.certDir())
	if err != nil {
		return nil, &commands.PayloadError{Err: err}
	}
	want, err := canonical(cfg)
	if err != nil {
		return nil, err
	}
	res := ApplyResult{ConfigSHA256: hostfs.SHA256(want), Routes: countRoutes(cfg), RateLimitsRefused: refused}
	if err := m.syncEnv(ctx, p, s); err != nil {
		return nil, err
	}
	if cur, err := m.o.Client.Config(ctx); err == nil {
		if c, err := canonicalBytes(cur); err == nil && bytes.Equal(c, want) {
			fmt.Fprintf(s.Stdout(), "caddy config unchanged (%s)\n", res.ConfigSHA256[:12])
			_, err := m.persist(want) // make sure the boot config matches even if it was lost
			return res, err
		}
	} else {
		return nil, fmt.Errorf("caddy admin API unreachable: %w", err)
	}
	if err := m.ensureRoots(p, s); err != nil {
		return nil, err
	}
	if err := m.ensureAccessLogDir(p); err != nil {
		return nil, err
	}
	if err := m.o.Client.Load(ctx, want); err != nil {
		return nil, err
	}
	fmt.Fprintf(s.Stdout(), "loaded caddy config: %d site routes (%s)\n", res.Routes, res.ConfigSHA256[:12])
	res.Changed = true
	_, err = m.persist(want)
	return res, err
}

// PlaceholderRelease is the release `current` points at until a site's first deploy. It is not a ULID,
// so deploy's release listing, pruning and rollback never treat it as a release.
const PlaceholderRelease = ".falak-placeholder"

const placeholderPage = `<?php
http_response_code(503);
header('Retry-After: 30');
echo "This site has not been deployed yet.\n";
`

// ensureRoots makes every PHP site's document root resolvable before loading: FrankenPHP (and the
// fastcgi transport with resolve_root_symlink) refuse a config whose root does not exist, which before
// a site's first deploy would take every route on the server down. `current` is pointed at a
// placeholder release that answers 503 until the first real release is activated.
func (m *Manager) ensureRoots(p Payload, s commands.Stream) error {
	for _, site := range p.Sites {
		if site.Root == "" || (site.Kind != "frankenphp" && site.Kind != "php_fpm" && site.Kind != "php-fpm") {
			continue
		}
		if _, err := os.Stat(m.o.FS.P(site.Root)); err == nil {
			continue
		}
		base, rest, ok := strings.Cut(site.Root, "/current")
		if !ok || (rest != "" && !strings.HasPrefix(rest, "/")) {
			if err := m.o.FS.MkdirAll(site.Root, 0o755); err != nil {
				return err
			}
			continue
		}
		current := base + "/current"
		if _, err := os.Lstat(m.o.FS.P(current)); err == nil {
			continue // a real (possibly broken) current link belongs to deploy; never replace it
		}
		release := base + "/releases/" + PlaceholderRelease
		if _, err := m.o.FS.WriteFile(release+rest+"/index.php", []byte(placeholderPage), 0o644); err != nil {
			return err
		}
		if err := os.Symlink(filepath.Join("releases", PlaceholderRelease), m.o.FS.P(current)); err != nil && !errors.Is(err, fs.ErrExist) {
			return err
		}
		fmt.Fprintf(s.Stdout(), "%s: not deployed yet, serving a placeholder\n", site.ID)
	}
	return nil
}

// ensureAccessLogDir creates the access log directory, writable by the edge user (Caddy opens the files) and
// closed to other users (request paths and client IPs).
func (m *Manager) ensureAccessLogDir(p Payload) error {
	need := false
	for _, s := range p.Sites {
		need = need || s.AccessLog != ""
	}
	if !need {
		return nil
	}
	if err := m.o.FS.MkdirAll(logs.AccessLogDir, 0o750); err != nil {
		return err
	}
	if g := m.edgeGroup(); g != "" {
		if err := m.o.FS.Chown(logs.AccessLogDir, g, g); err != nil {
			return err
		}
	}
	return os.Chmod(m.o.FS.P(logs.AccessLogDir), 0o750)
}

// PersistRunning snapshots the live config into bootstrap.json (after out-of-band PATCHes).
func (m *Manager) PersistRunning(ctx context.Context) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	cur, err := m.o.Client.Config(ctx)
	if err != nil {
		return err
	}
	c, err := canonicalBytes(cur)
	if err != nil {
		return err
	}
	_, err = m.persist(c)
	return err
}

// SetUpstreams patches upstreams live and persists the resulting config so a restart keeps them.
func (m *Manager) SetUpstreams(ctx context.Context, routeID string, dials []string) error {
	if err := m.o.Client.SetUpstreams(ctx, routeID, dials); err != nil {
		return err
	}
	return m.PersistRunning(ctx)
}

func (m *Manager) persist(cfg []byte) (bool, error) {
	var pretty bytes.Buffer
	_ = json.Indent(&pretty, cfg, "", "  ")
	pretty.WriteByte('\n')
	changed, err := m.o.FS.WriteFile(m.bootstrapPath(), pretty.Bytes(), 0o640)
	if err != nil {
		return changed, err
	}
	// falak-edge.service runs as the caddy user and reads this file at boot.
	if g := m.edgeGroup(); g != "" {
		return changed, m.o.FS.Chown(m.bootstrapPath(), "root", g)
	}
	return changed, nil
}

// edgeGroup returns the group of the edge service user ("caddy") when it exists on a real host.
func (m *Manager) edgeGroup() string {
	if !m.o.FS.IsReal() {
		return ""
	}
	if _, err := user.LookupGroup("caddy"); err == nil {
		return "caddy"
	}
	return ""
}

// CertPayload is edge.cert.install.
type CertPayload struct {
	Name     string `json:"name"`
	CertPEM  string `json:"cert_pem,omitempty"`
	KeyPEM   string `json:"key_pem,omitempty"`
	ChainPEM string `json:"chain_pem,omitempty"`
	State    string `json:"state,omitempty"`
}

// CertResult is edge.cert.install's result.
type CertResult struct {
	Changed           bool   `json:"changed"`
	FingerprintSHA256 string `json:"fingerprint_sha256,omitempty"`
	NotAfter          string `json:"not_after,omitempty"`
	CertPath          string `json:"cert_path,omitempty"`
	KeyPath           string `json:"key_path,omitempty"`
}

// InstallCert validates the key pair and stores it where Render's load_files points.
func (m *Manager) InstallCert(ctx context.Context, p CertPayload, s commands.Stream) (any, error) {
	certPath := path.Join(m.certDir(), p.Name+".crt")
	keyPath := path.Join(m.certDir(), p.Name+".key")
	if p.State == "absent" {
		a, err := m.o.FS.Remove(certPath)
		if err != nil {
			return nil, err
		}
		b, err := m.o.FS.Remove(keyPath)
		return CertResult{Changed: a || b}, err
	}
	if p.CertPEM == "" || p.KeyPEM == "" {
		return nil, &commands.PayloadError{Err: errors.New("cert_pem and key_pem are required")}
	}
	full := strings.TrimSpace(p.CertPEM) + "\n"
	if p.ChainPEM != "" {
		full += strings.TrimSpace(p.ChainPEM) + "\n"
	}
	pair, err := tls.X509KeyPair([]byte(full), []byte(p.KeyPEM))
	if err != nil {
		return nil, &commands.PayloadError{Err: fmt.Errorf("certificate/key mismatch or invalid PEM: %w", err)}
	}
	leaf, err := x509.ParseCertificate(pair.Certificate[0])
	if err != nil {
		return nil, err
	}
	if time.Now().After(leaf.NotAfter) {
		return nil, &commands.PayloadError{Err: fmt.Errorf("certificate expired at %s", leaf.NotAfter)}
	}
	if err := m.o.FS.MkdirAll(m.certDir(), 0o750); err != nil {
		return nil, err
	}
	group := m.edgeGroup()
	if group != "" {
		if err := m.o.FS.Chown(m.certDir(), "root", group); err != nil {
			return nil, err
		}
	}
	c1, err := m.o.FS.WriteFile(certPath, []byte(full), 0o644)
	if err != nil {
		return nil, err
	}
	keyMode := 0o600
	if group != "" {
		keyMode = 0o640 // readable by the edge (caddy group), never world-readable
	}
	c2, err := m.o.FS.WriteFile(keyPath, normalizeKey(p.KeyPEM), osMode(keyMode))
	if err != nil {
		return nil, err
	}
	if group != "" {
		if err := m.o.FS.Chown(keyPath, "root", group); err != nil {
			return nil, err
		}
	}
	fp := sha256.Sum256(leaf.Raw)
	res := CertResult{Changed: c1 || c2, FingerprintSHA256: hex.EncodeToString(fp[:]), NotAfter: leaf.NotAfter.UTC().Format(time.RFC3339), CertPath: certPath, KeyPath: keyPath}
	fmt.Fprintf(s.Stdout(), "certificate %s (CN=%s, expires %s) changed=%v\n", p.Name, leaf.Subject.CommonName, res.NotAfter, res.Changed)
	return res, nil
}

func normalizeKey(k string) []byte {
	if blk, _ := pem.Decode([]byte(k)); blk != nil {
		return pem.EncodeToMemory(blk)
	}
	return []byte(k)
}

func canonical(v any) ([]byte, error) {
	b, err := json.Marshal(v)
	if err != nil {
		return nil, err
	}
	return canonicalBytes(b)
}

// canonicalBytes re-encodes JSON with sorted keys so configs can be compared byte-wise.
func canonicalBytes(b []byte) ([]byte, error) {
	var v any
	dec := json.NewDecoder(bytes.NewReader(b))
	dec.UseNumber()
	if err := dec.Decode(&v); err != nil {
		return nil, err
	}
	var out bytes.Buffer
	enc := json.NewEncoder(&out)
	enc.SetEscapeHTML(false)
	if err := enc.Encode(v); err != nil {
		return nil, err
	}
	return bytes.TrimSpace(out.Bytes()), nil
}

func countRoutes(cfg obj) int {
	n := 0
	apps, _ := cfg["apps"].(obj)
	httpApp, _ := apps["http"].(obj)
	servers, _ := httpApp["servers"].(obj)
	for _, s := range servers {
		if so, ok := s.(obj); ok {
			if r, ok := so["routes"].([]any); ok {
				for _, x := range r {
					if xo, ok := x.(obj); ok && xo["@id"] != nil {
						n++
					}
				}
			}
		}
	}
	return n
}
