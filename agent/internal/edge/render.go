// Package edge renders the Falak edge model into Caddy JSON and applies it atomically through the
// Caddy (or FrankenPHP) admin API.
package edge

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"path"
	"regexp"
	"slices"
	"sort"
	"strings"

	"github.com/OthmanHaba/falak/agent/internal/localedge"
	"github.com/OthmanHaba/falak/agent/internal/logs"
)

// Payload is edge.caddy.apply.
type Payload struct {
	ACMEEmail string         `json:"acme_email,omitempty"`
	ACMECA    string         `json:"acme_ca,omitempty"`
	Sites     []Site         `json:"sites"`
	Raw       map[string]any `json:"raw,omitempty"`
	// TrustedProxies are CIDRs (Cloudflare's ranges when the site is proxied) whose client IP headers are believed:
	// logs, IP allow / deny lists and rate limits then see the visitor, not the proxy.
	TrustedProxies []string `json:"trusted_proxies,omitempty"`
	// WildcardCertificates are obtained and renewed through ACME DNS-01 (a preview domain): sites in tls mode
	// "wildcard" are served by the one covering their hosts, with no certificate per host.
	WildcardCertificates []WildcardCertificate `json:"wildcard_certificates,omitempty"`
	// RequestID stamps X-Request-Id on every request of every site (feature edge.request_id): a well-formed incoming
	// id is kept, anything else replaced by a UUID; passed upstream and echoed in the response (and so in the access
	// log). Without it the rendered config is exactly what it was before the feature.
	RequestID bool `json:"request_id,omitempty"`
	// CaddyBuild is Falak's Caddy build (rate_limit and caddy-dns modules) for this server's architecture: sent with
	// rate limits (feature edge.rate_limit) or DNS-01 certificates (edge.caddy_dns), installed only when the edge's
	// Caddy lacks a module the config uses.
	CaddyBuild *CaddyBuild `json:"caddy_build,omitempty"`
	// LocalEdge is this host's /etc/falak/local-edge.env (set by the agent, never sent): on the control-plane machine
	// the edge listens behind the control plane's edge instead of on 80/443.
	LocalEdge localedge.Config `json:"-"`
}

// CaddyBuild is a Caddy binary to download, checked against its SHA-256.
type CaddyBuild struct {
	URL    string `json:"url"`
	SHA256 string `json:"sha256"`
}

// WildcardCertificate is one *.<domain> certificate managed by the edge.
type WildcardCertificate struct {
	Subject string `json:"subject"`
	DNS     DNS    `json:"dns"`
}

// covers reports whether the wildcard covers host (exactly one label below its domain).
func (w WildcardCertificate) covers(host string) bool {
	label, ok := strings.CutSuffix(host, strings.TrimPrefix(w.Subject, "*"))
	return ok && label != "" && !strings.Contains(label, ".")
}

// Site is one routed site.
type Site struct {
	ID              string            `json:"id"`
	Domains         []string          `json:"domains"`
	RedirectDomains []string          `json:"redirect_domains,omitempty"`
	TLS             *TLS              `json:"tls,omitempty"`
	Kind            string            `json:"kind"`
	Root            string            `json:"root,omitempty"`
	PHPFPMSocket    string            `json:"php_fpm_socket,omitempty"`
	Upstreams       []Upstream        `json:"upstreams,omitempty"`
	LBPolicy        string            `json:"lb_policy,omitempty"`
	HealthURI       string            `json:"health_uri,omitempty"`
	TryDurationS    int               `json:"try_duration_s,omitempty"`
	RedirectTo      string            `json:"redirect_to,omitempty"`
	Headers         map[string]string `json:"headers,omitempty"`
	// RequestHeaders are set on the request sent upstream (kind=reverse_proxy), e.g. X-Falak-Function.
	RequestHeaders map[string]string `json:"request_headers,omitempty"`
	BasicAuth      []BasicAuth       `json:"basic_auth,omitempty"`
	Redirects      []Redirect        `json:"redirects,omitempty"`
	DenyIPs        []string          `json:"deny_ips,omitempty"`
	AllowIPs       []string          `json:"allow_ips,omitempty"`
	MaxBodyBytes   int64             `json:"max_body_bytes,omitempty"`
	Encode         *bool             `json:"encode,omitempty"`
	// Mounts send a path of this site to another upstream (a function: the local gateway or its own domain).
	Mounts []Mount `json:"mounts,omitempty"`
	// AccessLog names the site's HTTP access log (the site slug): JSON lines in logs.AccessLogDir/<name>.log,
	// shipped by the agent as kind=access records of that site. Routes sharing a name share the file.
	AccessLog string `json:"access_log,omitempty"`
	// RateLimits limit requests per client IP on paths of the site (feature edge.rate_limit; needs the rate_limit
	// module, see ratelimit.go). Sites without rules render exactly as before.
	RateLimits []RateLimit `json:"rate_limits,omitempty"`
}

// RateLimit allows Events requests per client IP to Path within WindowS seconds; more get 429 with Retry-After.
type RateLimit struct {
	Path    string `json:"path"`
	Events  int    `json:"events"`
	WindowS int    `json:"window_s"`
}

var accessLogName = regexp.MustCompile(`^[a-z0-9][a-z0-9-]{0,62}$`)

var wildcardSubject = regexp.MustCompile(`^\*\.[a-z0-9-]+(\.[a-z0-9-]+)+$`)

// AccessLogger is the Caddy logger of a site's access log (http.log.access.<AccessLogger>).
func AccessLogger(name string) string { return "falak-access-" + name }

// AccessLogPath is the file a site's access log is written to.
func AccessLogPath(name string) string { return path.Join(logs.AccessLogDir, name+".log") }

// TLS settings.
type TLS struct {
	Mode     string `json:"mode,omitempty"` // acme (default) | internal | custom | off | wildcard
	CertName string `json:"cert_name,omitempty"`
	DNS      *DNS   `json:"dns,omitempty"` // ACME DNS-01 challenge (wildcards)
	// HTTPChallengeOnly disables TLS-ALPN-01: behind a proxy that terminates TLS (Cloudflare's orange cloud) it can
	// never pass, and every failed attempt counts against Let's Encrypt's failed-authorization limit.
	HTTPChallengeOnly bool `json:"http_challenge_only,omitempty"`
}

// DNS configures the ACME DNS-01 challenge provider.
type DNS struct {
	Provider string `json:"provider"` // cloudflare
	APIToken string `json:"api_token"`
}

// Upstream is a reverse-proxy target.
type Upstream struct {
	Dial string `json:"dial"`
}

// BasicAuth account.
type BasicAuth struct {
	Username     string `json:"username"`
	PasswordHash string `json:"password_hash"`
	Path         string `json:"path,omitempty"` // Caddy path matcher; empty = whole site
}

// Redirect rule.
type Redirect struct {
	From   string `json:"from"`
	To     string `json:"to"`
	Status int    `json:"status,omitempty"`
}

// AdminListen is where the admin API listens: a unix socket only the edge user (and root, the agent) can open, in
// falak-edge.service's RuntimeDirectory. A TCP port on localhost would let any local process (a site, a preview's
// build) read the config or POST /load. Needs Caddy 2.8+ (socket permissions in the address).
const AdminListen = "unix//run/falak-edge/admin.sock|0600"

// AdminSocket is the socket path of AdminListen.
const AdminSocket = "/run/falak-edge/admin.sock"

// TokenPlaceholder is the {env.NAME} placeholder Caddy resolves a DNS provider token from: the value lives in the
// edge service's environment (EdgeEnv, root-only), never in the JSON config the admin API returns or on disk.
func TokenPlaceholder(token string) string { return "{env." + TokenEnv(token) + "}" }

// TokenEnv names the environment variable holding a DNS provider token (derived from it, so a rotated token is a new
// variable).
func TokenEnv(token string) string {
	sum := sha256.Sum256([]byte(token))
	return "FALAK_DNS_TOKEN_" + strings.ToUpper(hex.EncodeToString(sum[:6]))
}

// Secrets are the DNS provider tokens the payload's config refers to, by environment variable.
func (p Payload) Secrets() []string {
	var out []string
	for _, s := range p.Sites {
		if s.TLS != nil && s.TLS.DNS != nil && s.TLS.DNS.APIToken != "" {
			out = append(out, s.TLS.DNS.APIToken)
		}
	}
	for _, w := range p.WildcardCertificates {
		if w.DNS.APIToken != "" {
			out = append(out, w.DNS.APIToken)
		}
	}
	return out
}

// Environment is the edge service's environment for the payload: one variable per DNS provider token, sorted.
func (p Payload) Environment() []byte {
	seen := map[string]string{}
	for _, t := range p.Secrets() {
		seen[TokenEnv(t)] = t
	}
	keys := make([]string, 0, len(seen))
	for k := range seen {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	var b strings.Builder
	b.WriteString("# Managed by Falak: DNS provider tokens of the edge (root only)\n")
	for _, k := range keys {
		b.WriteString(k + "=" + seen[k] + "\n")
	}
	return []byte(b.String())
}

// RouteID / UpstreamsID are stable @id values addressable through /id/<id>.
func RouteID(site string) string     { return "falak-site-" + site }
func UpstreamsID(site string) string { return "falak-upstreams-" + site }

type obj = map[string]any

func (s Site) tlsMode() string {
	if s.TLS == nil || s.TLS.Mode == "" {
		return "acme"
	}
	return s.TLS.Mode
}

// Render converts the payload into a complete Caddy JSON config. certDir is where edge.cert.install
// stores custom certificates.
func Render(p Payload, certDir string) (obj, error) {
	if p.Raw != nil {
		raw := cloneMap(p.Raw)
		// The admin endpoint must stay local no matter what the raw config says.
		raw["admin"] = obj{"listen": AdminListen}
		return raw, nil
	}
	seen := map[string]string{}
	var tlsRoutes, plainRoutes []any
	var acmeSubjects, internalSubjects, skipCerts []string
	var dnsPolicies []any
	var loadFiles []any
	tlsLoggers, plainLoggers := obj{}, obj{}
	accessLogs := map[string]bool{}
	if p.CaddyBuild != nil && len(p.neededModules()) == 0 {
		return nil, fmt.Errorf("caddy_build is only sent with rate limits or DNS-01 certificates")
	}
	sites := append([]Site(nil), p.Sites...)
	sort.SliceStable(sites, func(i, j int) bool { return sites[i].ID < sites[j].ID })
	for _, s := range sites {
		if s.ID == "" || len(s.Domains) == 0 {
			return nil, fmt.Errorf("site %q: id and domains are required", s.ID)
		}
		for _, d := range append(append([]string{}, s.Domains...), s.RedirectDomains...) {
			if other, dup := seen[d]; dup {
				return nil, fmt.Errorf("domain %s used by sites %s and %s", d, other, s.ID)
			}
			seen[d] = s.ID
		}
		routes, err := siteRoutes(s, p.RequestID)
		if err != nil {
			return nil, fmt.Errorf("site %s: %w", s.ID, err)
		}
		hosts := append(append([]string{}, s.Domains...), s.RedirectDomains...)
		if s.AccessLog != "" {
			if !accessLogName.MatchString(s.AccessLog) {
				return nil, fmt.Errorf("site %s: invalid access_log %q", s.ID, s.AccessLog)
			}
			accessLogs[s.AccessLog] = true
			loggers := tlsLoggers
			if s.tlsMode() == "off" {
				loggers = plainLoggers
			}
			for _, h := range hosts {
				loggers[h] = []any{AccessLogger(s.AccessLog)}
			}
		}
		switch s.tlsMode() {
		case "off":
			plainRoutes = append(plainRoutes, routes...)
		case "internal":
			internalSubjects = append(internalSubjects, hosts...)
			tlsRoutes = append(tlsRoutes, routes...)
		case "custom":
			if s.TLS.CertName == "" {
				return nil, fmt.Errorf("site %s: tls.cert_name required for custom TLS", s.ID)
			}
			skipCerts = append(skipCerts, hosts...)
			loadFiles = append(loadFiles, obj{
				"certificate": path.Join(certDir, s.TLS.CertName+".crt"),
				"key":         path.Join(certDir, s.TLS.CertName+".key"),
				"tags":        []any{"falak-" + s.TLS.CertName},
			})
			tlsRoutes = append(tlsRoutes, routes...)
		case "wildcard":
			// The covering wildcard certificate (automated below) serves these hosts: none of their own.
			for _, h := range hosts {
				if !slices.ContainsFunc(p.WildcardCertificates, func(w WildcardCertificate) bool { return w.covers(h) }) {
					return nil, fmt.Errorf("site %s: no wildcard certificate covers %s", s.ID, h)
				}
			}
			skipCerts = append(skipCerts, hosts...)
			tlsRoutes = append(tlsRoutes, routes...)
		case "acme":
			if s.TLS != nil && s.TLS.DNS != nil {
				if s.TLS.DNS.Provider == "" || s.TLS.DNS.APIToken == "" {
					return nil, fmt.Errorf("site %s: tls.dns provider and api_token are required", s.ID)
				}
				iss := acmeIssuer(p)
				iss["challenges"] = obj{"dns": obj{"provider": obj{"name": s.TLS.DNS.Provider, "api_token": TokenPlaceholder(s.TLS.DNS.APIToken)}}}
				dnsPolicies = append(dnsPolicies, obj{"subjects": toAny(hosts), "issuers": []any{iss}})
			} else if s.TLS != nil && s.TLS.HTTPChallengeOnly {
				iss := acmeIssuer(p)
				iss["challenges"] = obj{"tls-alpn": obj{"disabled": true}}
				dnsPolicies = append(dnsPolicies, obj{"subjects": toAny(hosts), "issuers": []any{iss}})
			} else {
				acmeSubjects = append(acmeSubjects, hosts...)
			}
			tlsRoutes = append(tlsRoutes, routes...)
		default:
			return nil, fmt.Errorf("site %s: unknown tls mode %q", s.ID, s.tlsMode())
		}
	}
	servers := obj{}
	local := p.LocalEdge
	if len(tlsRoutes) > 0 {
		srv := obj{"listen": []any{":443"}, "routes": tlsRoutes}
		if local.Enabled {
			srv["listen"] = []any{local.HTTPSListen()}
			behindLocalEdge(srv, local, true)
		}
		trustProxies(srv, p.TrustedProxies)
		if len(skipCerts) > 0 {
			srv["automatic_https"] = obj{"skip_certificates": toAny(skipCerts)}
		}
		if len(tlsLoggers) > 0 {
			srv["logs"] = serverLogs(tlsLoggers)
		}
		servers["falak"] = srv
	}
	// Caddy adds its HTTP→HTTPS redirects for TLS sites to this existing :80 server. Behind the local edge it always
	// exists: the server Caddy would create for the redirects otherwise has no PROXY protocol wrapper and listens on
	// every address. The redirects carry no port (the TLS server listens on https_port, see Caddy's makeRedirRoute)
	// and every server answers ACME HTTP-01 challenges before its routes.
	if len(plainRoutes) > 0 || local.Enabled {
		srv := obj{"listen": []any{":80"}, "routes": plainRoutes}
		if local.Enabled {
			srv["listen"] = []any{local.HTTPListen()}
			if plainRoutes == nil {
				srv["routes"] = []any{}
			}
			behindLocalEdge(srv, local, false)
		}
		trustProxies(srv, p.TrustedProxies)
		if len(plainLoggers) > 0 {
			srv["logs"] = serverLogs(plainLoggers)
		}
		servers["falak_http"] = srv
	}
	httpApp := obj{"servers": servers}
	if local.Enabled {
		// ACME's HTTP-01 / TLS-ALPN-01 solvers and the automatic redirects use these instead of 80/443.
		httpApp["http_port"], httpApp["https_port"] = local.HTTPPort, local.HTTPSPort
	}
	cfg := obj{
		"admin": obj{"listen": AdminListen},
		"apps":  obj{"http": httpApp},
	}
	if len(accessLogs) > 0 {
		cfg["logging"] = accessLogging(accessLogs)
	}
	tlsApp := obj{}
	var policies []any
	if len(internalSubjects) > 0 {
		policies = append(policies, obj{"subjects": toAny(internalSubjects), "issuers": []any{obj{"module": "internal"}}})
	}
	policies = append(policies, dnsPolicies...)
	var automate []any
	for _, w := range p.WildcardCertificates {
		if !wildcardSubject.MatchString(w.Subject) || w.DNS.Provider == "" || w.DNS.APIToken == "" {
			return nil, fmt.Errorf("wildcard certificate %q: a *.<domain> subject, dns provider and api_token are required", w.Subject)
		}
		iss := acmeIssuer(p)
		iss["challenges"] = obj{"dns": obj{"provider": obj{"name": w.DNS.Provider, "api_token": TokenPlaceholder(w.DNS.APIToken)}}}
		policies = append(policies, obj{"subjects": []any{w.Subject}, "issuers": []any{iss}})
		automate = append(automate, w.Subject)
	}
	if len(acmeSubjects) > 0 && (p.ACMEEmail != "" || p.ACMECA != "") {
		policies = append(policies, obj{"subjects": toAny(acmeSubjects), "issuers": []any{acmeIssuer(p)}})
	}
	if len(policies) > 0 {
		tlsApp["automation"] = obj{"policies": policies}
	}
	certs := obj{}
	if len(loadFiles) > 0 {
		certs["load_files"] = loadFiles
	}
	if len(automate) > 0 {
		certs["automate"] = automate
	}
	if len(certs) > 0 {
		tlsApp["certificates"] = certs
	}
	if len(tlsApp) > 0 {
		cfg["apps"].(obj)["tls"] = tlsApp
	}
	return cfg, nil
}

// behindLocalEdge reads the PROXY protocol header of the control plane's edge before TLS (honoured only from its
// network; anyone else sending one is refused), so client IPs are the visitors'. HTTP/3 is off: it would advertise a
// UDP port the control plane's edge does not forward.
func behindLocalEdge(srv obj, c localedge.Config, tls bool) {
	wrappers := []any{obj{"wrapper": "proxy_protocol", "allow": []any{c.Trust}, "fallback_policy": "reject"}}
	if tls {
		wrappers = append(wrappers, obj{"wrapper": "tls"})
	}
	srv["listener_wrappers"] = wrappers
	srv["protocols"] = []any{"h1", "h2"}
}

func siteRoutes(s Site, requestID bool) ([]any, error) {
	var out []any
	if len(s.RedirectDomains) > 0 {
		scheme := "https"
		if s.tlsMode() == "off" {
			scheme = "http"
		}
		out = append(out, obj{
			"match": []any{obj{"host": toAny(s.RedirectDomains)}},
			"handle": []any{obj{"handler": "static_response", "status_code": 308,
				"headers": obj{"Location": []any{scheme + "://" + s.Domains[0] + "{http.request.uri}"}}}},
			"terminal": true,
		})
	}
	var sub []any
	if requestID {
		// First, so every response of the site carries it (a 403 of the IP rules, a basic auth challenge, a redirect).
		sub = append(sub, requestIDRoutes()...)
	}
	if len(s.DenyIPs) > 0 {
		sub = append(sub, obj{"match": []any{obj{"client_ip": obj{"ranges": toAny(s.DenyIPs)}}}, "handle": []any{forbidden()}})
	}
	if len(s.AllowIPs) > 0 {
		sub = append(sub, obj{"match": []any{obj{"not": []any{obj{"client_ip": obj{"ranges": toAny(s.AllowIPs)}}}}}, "handle": []any{forbidden()}})
	}
	// Rate limits come after the IP lists (a denied client is not counted) and before authentication (a login form or
	// basic auth is what they protect).
	if len(s.RateLimits) > 0 {
		h, err := rateLimitHandler(s)
		if err != nil {
			return nil, err
		}
		sub = append(sub, obj{"handle": []any{h}})
	}
	if len(s.BasicAuth) > 0 {
		// Group accounts by path; site-wide accounts ("") come first, then paths in first-seen order.
		byPath := map[string][]any{}
		var paths []string
		for _, a := range s.BasicAuth {
			if _, ok := byPath[a.Path]; !ok {
				paths = append(paths, a.Path)
			}
			byPath[a.Path] = append(byPath[a.Path], obj{"username": a.Username, "password": a.PasswordHash})
		}
		sort.SliceStable(paths, func(i, j int) bool { return paths[i] == "" && paths[j] != "" })
		for _, p := range paths {
			route := obj{"handle": []any{obj{"handler": "authentication", "providers": obj{"http_basic": obj{
				"accounts": byPath[p], "hash": obj{"algorithm": "bcrypt"}}}}}}
			if p != "" {
				route["match"] = []any{obj{"path": []any{p}}}
			}
			sub = append(sub, route)
		}
	}
	if len(s.Headers) > 0 {
		set := obj{}
		for k, v := range s.Headers {
			set[k] = []any{v}
		}
		sub = append(sub, obj{"handle": []any{obj{"handler": "headers", "response": obj{"set": set}}}})
	}
	if s.MaxBodyBytes > 0 {
		sub = append(sub, obj{"handle": []any{obj{"handler": "request_body", "max_size": s.MaxBodyBytes}}})
	}
	// Mounts (a function on a path of this site) come after the access rules above and before the site's own
	// redirects and handler.
	mounts, err := mountRoutes(s.Mounts)
	if err != nil {
		return nil, err
	}
	sub = append(sub, mounts...)
	for _, r := range s.Redirects {
		st := r.Status
		if st == 0 {
			st = 301
		}
		sub = append(sub, obj{"match": []any{obj{"path": []any{r.From}}}, "handle": []any{obj{
			"handler": "static_response", "status_code": st, "headers": obj{"Location": []any{r.To}}}}})
	}
	if s.Encode == nil || *s.Encode {
		sub = append(sub, obj{"handle": []any{obj{"handler": "encode",
			"encodings": obj{"zstd": obj{}, "gzip": obj{}}, "prefer": []any{"zstd", "gzip"}}}})
	}
	switch s.Kind {
	case "static":
		if s.Root == "" {
			return nil, fmt.Errorf("root required for static")
		}
		sub = append(sub, staticRoutes(s.Root)...)
	case "php_fpm", "frankenphp":
		if s.Root == "" {
			return nil, fmt.Errorf("root required for %s", s.Kind)
		}
		if s.Kind == "php_fpm" && s.PHPFPMSocket == "" {
			return nil, fmt.Errorf("php_fpm_socket required")
		}
		sub = append(sub, phpRoutes(s)...)
	case "reverse_proxy":
		if len(s.Upstreams) == 0 {
			return nil, fmt.Errorf("upstreams required for reverse_proxy")
		}
		if s.Root != "" {
			sub = append(sub, staticPassthrough(s.Root)...)
		}
		sub = append(sub, obj{"handle": []any{reverseProxy(s)}})
	case "redirect":
		if s.RedirectTo == "" {
			return nil, fmt.Errorf("redirect_to required")
		}
		sub = append(sub, obj{"handle": []any{obj{"handler": "static_response", "status_code": 308, "headers": obj{"Location": []any{s.RedirectTo}}}}})
	default:
		return nil, fmt.Errorf("unknown kind %q", s.Kind)
	}
	out = append(out, obj{
		"@id":      RouteID(s.ID),
		"match":    []any{obj{"host": toAny(s.Domains)}},
		"handle":   []any{obj{"handler": "subroute", "routes": sub}},
		"terminal": true,
	})
	return out, nil
}

// phpRoutes mirrors Caddy's php_fastcgi / FrankenPHP php_server expansion. With resolve_root_symlink the
// fastcgi transport resolves `current` per request, but FrankenPHP resolves it when the handler is
// provisioned: deploy.activate therefore force-reloads the config (Client.ReloadFrankenPHP), and
// Manager.ensureRoots gives never-deployed sites a placeholder release so the config always loads.
func phpRoutes(s Site) []any {
	tryFiles := obj{"file": obj{"try_files": []any{"{http.request.uri.path}", "{http.request.uri.path}/index.php", "index.php"}, "split_path": []any{".php"}, "root": s.Root}}
	var phpHandler obj
	if s.Kind == "frankenphp" {
		phpHandler = obj{"handler": "php", "root": s.Root, "split_path": []any{".php"}, "resolve_root_symlink": true}
	} else {
		phpHandler = obj{"handler": "reverse_proxy", "transport": obj{"protocol": "fastcgi", "root": s.Root, "split_path": []any{".php"}, "resolve_root_symlink": true},
			"upstreams": []any{obj{"dial": "unix/" + s.PHPFPMSocket}}}
	}
	return []any{
		obj{"handle": []any{obj{"handler": "vars", "root": s.Root}}},
		obj{"match": []any{obj{"file": obj{"try_files": []any{"{http.request.uri.path}/index.php"}, "root": s.Root}, "not": []any{obj{"path": []any{"*/"}}}}},
			"handle": []any{obj{"handler": "static_response", "status_code": 308, "headers": obj{"Location": []any{"{http.request.orig_uri.path}/{http.request.orig_uri.prefixed_query}"}}}}},
		obj{"match": []any{tryFiles}, "handle": []any{obj{"handler": "rewrite", "uri": "{http.matchers.file.relative}"}}},
		obj{"match": []any{obj{"path": []any{"*.php"}}}, "handle": []any{phpHandler}},
		obj{"handle": []any{obj{"handler": "file_server", "hide": []any{".env", ".git"}}}},
	}
}

// staticRoutes serves a static site: existing files and directories first; then, for a path that does not
// exist, the site's top-level 404.html with status 404 when it has one, otherwise — a single-page app, as
// Cloudflare Pages assumes — /index.html for extension-less paths (client-side routes such as /about survive a
// reload; a missing /app.js is still a 404). Matchers stat per request, so each release decides for itself.
func staticRoutes(root string) []any {
	file := func(paths ...any) obj { return obj{"file": obj{"try_files": paths, "root": root}} }
	serve := obj{"handler": "file_server", "hide": []any{".env", ".git"}}
	return []any{
		obj{"handle": []any{obj{"handler": "vars", "root": root}}},
		obj{"match": []any{file("{http.request.uri.path}", "{http.request.uri.path}/")}, "handle": []any{serve}},
		obj{"match": []any{file("/404.html")}, "handle": []any{
			obj{"handler": "rewrite", "uri": "/404.html"},
			obj{"handler": "file_server", "hide": []any{".env", ".git"}, "status_code": "404"},
		}},
		obj{"match": []any{obj{"file": obj{"try_files": []any{"/index.html"}, "root": root}, "not": []any{obj{"path_regexp": obj{"pattern": `\.[A-Za-z0-9]+$`}}}}},
			"handle": []any{obj{"handler": "rewrite", "uri": "/index.html"}, serve}},
		obj{"handle": []any{serve}},
	}
}

// staticPassthrough serves files that exist under root directly (Laravel Octane: public/ assets never reach the
// app server); PHP sources, dotfiles and directories are never served and fall through to the proxy. The file
// matcher stats per request, so a `current` symlink swap is picked up without a reload.
func staticPassthrough(root string) []any {
	return []any{
		obj{"handle": []any{obj{"handler": "vars", "root": root}}},
		obj{
			"match": []any{obj{
				"file": obj{"try_files": []any{"{http.request.uri.path}"}, "root": root},
				"not":  []any{obj{"path": []any{"*.php", "*/", "/.*", "*/.*"}}},
			}},
			"handle":   []any{obj{"handler": "file_server", "hide": []any{".env", ".git"}}},
			"terminal": true,
		},
	}
}

func reverseProxy(s Site) obj {
	var ups []any
	for _, u := range s.Upstreams {
		ups = append(ups, obj{"dial": u.Dial})
	}
	h := obj{"@id": UpstreamsID(s.ID), "handler": "reverse_proxy", "upstreams": ups}
	policy := s.LBPolicy
	if policy == "" {
		policy = "round_robin"
	}
	tryFor := 5
	if s.TryDurationS > 0 {
		tryFor = s.TryDurationS
	}
	h["load_balancing"] = obj{"selection_policy": obj{"policy": policy}, "try_duration": fmt.Sprintf("%ds", tryFor)}
	if len(s.RequestHeaders) > 0 {
		set := obj{}
		for k, v := range s.RequestHeaders {
			set[k] = []any{v}
		}
		h["headers"] = obj{"request": obj{"set": set}}
	}
	if s.HealthURI != "" {
		h["health_checks"] = obj{"active": obj{"uri": s.HealthURI, "interval": "10s", "timeout": "5s"}}
	}
	return h
}

// serverLogs maps each logged host to its site's access logger; other hosts (and Caddy's own redirects) are
// not access-logged.
func serverLogs(loggers obj) obj {
	return obj{"logger_names": loggers, "skip_unmapped_hosts": true}
}

// accessLogging writes every site's access log to its own JSON file (rotated by Caddy at 10 MB, 3 kept) and
// keeps access entries out of the default log (the falak-edge journal).
func accessLogging(names map[string]bool) obj {
	keys := make([]string, 0, len(names))
	for n := range names {
		keys = append(keys, n)
	}
	sort.Strings(keys)
	out := obj{"default": obj{"exclude": []any{"http.log.access"}}}
	for _, n := range keys {
		out[AccessLogger(n)] = obj{
			"writer": obj{"output": "file", "filename": AccessLogPath(n), "roll_size_mb": 10, "roll_keep": 3,
				"roll_keep_days": 7},
			"encoder": obj{"format": "json"},
			"include": []any{"http.log.access." + AccessLogger(n)},
		}
	}
	return obj{"logs": out}
}

func acmeIssuer(p Payload) obj {
	iss := obj{"module": "acme"}
	if p.ACMEEmail != "" {
		iss["email"] = p.ACMEEmail
	}
	if p.ACMECA != "" {
		iss["ca"] = p.ACMECA
	}
	return iss
}

// RequestIDPlaceholder is the request's X-Request-Id as Caddy sees it: several headers are joined with ",", which
// RequestIDPattern never matches, so a request carrying two ids (one chosen by the client, one by a proxy) gets a new
// one instead of the first.
const RequestIDPlaceholder = "{http.request.header." + logs.RequestIDHeader + "}"

// requestIDRoutes stamp X-Request-Id: a request without exactly one well-formed id (RequestIDPattern, at most 128
// characters) gets {http.request.uuid} (generated once per request), replacing whatever it carried, upstream; a
// well-formed id is passed on untouched. The response gets the request's id only when the app answered without one
// (`require`: an id the app set is never overwritten); Caddy's access log (resp_headers) then records it.
func requestIDRoutes() []any {
	valid := obj{"vars_regexp": obj{RequestIDPlaceholder: obj{"pattern": logs.RequestIDPattern}}}
	absent := obj{"headers": obj{logs.RequestIDHeader: nil}}
	return []any{
		obj{"match": []any{obj{"not": []any{valid}}}, "handle": []any{obj{"handler": "headers",
			"request":  obj{"set": obj{logs.RequestIDHeader: []any{"{http.request.uuid}"}}},
			"response": obj{"set": obj{logs.RequestIDHeader: []any{"{http.request.uuid}"}}, "require": absent, "deferred": true}}}},
		obj{"match": []any{valid}, "handle": []any{obj{"handler": "headers",
			"response": obj{"set": obj{logs.RequestIDHeader: []any{RequestIDPlaceholder}}, "require": absent, "deferred": true}}}},
	}
}

func forbidden() obj { return obj{"handler": "static_response", "status_code": 403} }

func toAny(ss []string) []any {
	out := make([]any, len(ss))
	for i, s := range ss {
		out[i] = s
	}
	return out
}

func cloneMap(m map[string]any) map[string]any {
	out := make(map[string]any, len(m))
	for k, v := range m {
		out[k] = v
	}
	return out
}

// trustProxies makes Caddy take the client IP from CF-Connecting-IP when the connection comes from one of ranges
// (Cloudflare always sets it and visitors cannot; X-Forwarded-For is not trusted since visitors can pre-fill it).
// The `client_ip` matcher and access logs then use it.
func trustProxies(srv obj, ranges []string) {
	if len(ranges) == 0 {
		return
	}
	srv["trusted_proxies"] = obj{"source": "static", "ranges": toAny(ranges)}
	srv["client_ip_headers"] = []any{"CF-Connecting-IP"}
}
