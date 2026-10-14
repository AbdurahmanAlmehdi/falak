// Package nettool implements net.tool (docs/plans/network/tools.md): ping, traceroute, DNS lookups, an HTTP check and
// a TCP connection test, run from the host or inside a container's network namespace. The agent re-execs itself as a
// short-lived helper (`falak-agent nettool <spec>`), under nsenter for a container, so a stuck tool never blocks the
// agent and the container image needs none of the tools. Arguments are validated here (and again by the helper);
// argv is always built from fixed templates, never through a shell.
package nettool

import (
	"errors"
	"fmt"
	"net/netip"
	"net/url"
	"regexp"
	"strings"
)

// Tools.
const (
	ToolPing       = "ping"
	ToolTraceroute = "traceroute"
	ToolDNS        = "dns"
	ToolHTTP       = "http"
	ToolTCP        = "tcp"
)

// Limits.
const (
	MaxTimeoutS     = 60
	DefaultTimeoutS = 30
	maxURL          = 2048
	maxLabels       = 8
)

// RecordTypes are the DNS record types the dns tool looks up.
var RecordTypes = []string{"A", "AAAA", "CNAME", "MX", "TXT", "NS", "SRV", "PTR"}

// Spec is net.tool's payload; the same JSON is the helper's argument.
type Spec struct {
	Tool       string     `json:"tool"`
	Target     string     `json:"target,omitempty"`
	URL        string     `json:"url,omitempty"`
	Port       int        `json:"port,omitempty"`
	Count      int        `json:"count,omitempty"`
	MaxHops    int        `json:"max_hops,omitempty"`
	RecordType string     `json:"record_type,omitempty"`
	Nameserver string     `json:"nameserver,omitempty"`
	Method     string     `json:"method,omitempty"`
	ConnectTo  string     `json:"connect_to,omitempty"`
	Insecure   bool       `json:"insecure,omitempty"`
	TimeoutS   int        `json:"timeout_s,omitempty"`
	Container  *Container `json:"container,omitempty"`
}

// HelperSpec is the helper's argument: the payload plus what only the agent sets (never accepted from a payload).
type HelperSpec struct {
	Spec
	// InContainer: the helper runs in a container's network namespace, with the contents of the container's
	// resolv.conf and hosts file (read by the agent from Docker's host-side files: only the network namespace is
	// switched, so the host's /etc/resolv.conf, 127.0.0.53, would not answer there). Contents, never paths: the helper
	// opens nothing the container controls.
	InContainer bool   `json:"in_container,omitempty"`
	ResolvConf  string `json:"resolv_conf,omitempty"`
	Hosts       string `json:"hosts,omitempty"`
}

// Container selects the container whose network namespace the tool runs in: exactly one of Name and Labels (as for
// consoles).
type Container struct {
	Name   string            `json:"name,omitempty"`
	Labels map[string]string `json:"labels,omitempty"`
}

var (
	labelRe        = regexp.MustCompile(`^[A-Za-z0-9_]([A-Za-z0-9_-]{0,61}[A-Za-z0-9_])?$`)
	containerName  = regexp.MustCompile(`^[a-zA-Z0-9][a-zA-Z0-9_.-]{1,127}$`)
	labelKeyRe     = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$`)
	labelValueRe   = regexp.MustCompile(`^[A-Za-z0-9_.:/@+-]{1,255}$`)
	urlPathAllowed = regexp.MustCompile(`^[A-Za-z0-9\-._~:/?#\[\]@!$&'()*+,;=%]*$`)
)

// ValidHost reports whether s is a hostname (LDH labels, underscores allowed, ≤ 63 per label, ≤ 253 in all, optional
// trailing dot) or an IP address. A leading "-" can never pass, so a host is never mistaken for an option.
func ValidHost(s string) bool {
	if s == "" || len(s) > 254 {
		return false
	}
	if _, err := netip.ParseAddr(s); err == nil {
		return !strings.Contains(s, "%") // no zones: "fe80::1%eth0" names an interface
	}
	name := strings.TrimSuffix(s, ".")
	if name == "" || len(name) > 253 {
		return false
	}
	for _, label := range strings.Split(name, ".") {
		if !labelRe.MatchString(label) {
			return false
		}
	}
	return true
}

// Validate checks the spec and fills defaults. Each tool accepts only its own fields.
func (s *Spec) Validate() error {
	if s.TimeoutS == 0 {
		s.TimeoutS = DefaultTimeoutS
	}
	if s.TimeoutS < 1 || s.TimeoutS > MaxTimeoutS {
		return fmt.Errorf("timeout_s must be 1–%d", MaxTimeoutS)
	}
	if err := s.Container.validate(); err != nil {
		return err
	}
	allowed := map[string]bool{}
	switch s.Tool {
	case ToolPing:
		allowed = fields("target", "count")
		if s.Count == 0 {
			s.Count = 4
		}
		if s.Count < 1 || s.Count > 20 {
			return errors.New("count must be 1–20")
		}
	case ToolTraceroute:
		allowed = fields("target", "max_hops")
		if s.MaxHops == 0 {
			s.MaxHops = 20
		}
		if s.MaxHops < 1 || s.MaxHops > 30 {
			return errors.New("max_hops must be 1–30")
		}
	case ToolDNS:
		allowed = fields("target", "record_type", "nameserver")
		if s.RecordType == "" {
			s.RecordType = "A"
		}
		s.RecordType = strings.ToUpper(s.RecordType)
		if !contains(RecordTypes, s.RecordType) {
			return fmt.Errorf("record_type must be one of %s", strings.Join(RecordTypes, ", "))
		}
		if s.Nameserver != "" {
			if a, err := netip.ParseAddr(s.Nameserver); err != nil || a.Zone() != "" {
				return errors.New("nameserver must be an IP address")
			}
		}
		if s.RecordType == "PTR" {
			if _, err := netip.ParseAddr(s.Target); err != nil {
				return errors.New("a PTR lookup needs an IP address")
			}
		}
	case ToolHTTP:
		allowed = fields("url", "method", "connect_to", "insecure")
		if s.Method == "" {
			s.Method = "GET"
		}
		s.Method = strings.ToUpper(s.Method)
		if s.Method != "GET" && s.Method != "HEAD" {
			return errors.New("method must be GET or HEAD")
		}
		if err := validURL(s.URL); err != nil {
			return err
		}
		if s.ConnectTo != "" {
			if a, err := netip.ParseAddr(s.ConnectTo); err != nil || a.Zone() != "" {
				return errors.New("connect_to must be an IP address")
			}
		}
	case ToolTCP:
		allowed = fields("target", "port", "count")
		if s.Port < 1 || s.Port > 65535 {
			return errors.New("port must be 1–65535")
		}
		if s.Count == 0 {
			s.Count = 3
		}
		if s.Count < 1 || s.Count > 10 {
			return errors.New("count must be 1–10")
		}
	default:
		return fmt.Errorf("unknown tool %q", s.Tool)
	}
	if allowed["target"] && !ValidHost(s.Target) {
		return errors.New("target must be a hostname or an IP address")
	}
	for name, set := range map[string]bool{
		"target": s.Target != "", "url": s.URL != "", "port": s.Port != 0, "count": s.Count != 0, "max_hops": s.MaxHops != 0,
		"record_type": s.RecordType != "", "nameserver": s.Nameserver != "", "method": s.Method != "", "connect_to": s.ConnectTo != "",
		"insecure": s.Insecure,
	} {
		if set && !allowed[name] {
			return fmt.Errorf("%s does not apply to %s", name, s.Tool)
		}
	}
	return nil
}

func validURL(raw string) error {
	if raw == "" || len(raw) > maxURL {
		return fmt.Errorf("url is required (at most %d characters)", maxURL)
	}
	if strings.ContainsAny(raw, " \t\r\n\x00\\") {
		return errors.New("url must not contain spaces or control characters")
	}
	u, err := url.Parse(raw)
	if err != nil {
		return errors.New("url is not a valid URL")
	}
	switch {
	case u.Scheme != "http" && u.Scheme != "https":
		return errors.New("url must start with http:// or https://")
	case u.User != nil:
		return errors.New("url must not contain credentials")
	case u.Opaque != "":
		return errors.New("url is not a valid URL")
	case !ValidHost(u.Hostname()):
		return errors.New("url must name a hostname or an IP address")
	}
	if p := u.Port(); p != "" {
		n := 0
		for _, c := range p {
			n = n*10 + int(c-'0')
			if c < '0' || c > '9' || n > 65535 {
				return errors.New("url has an invalid port")
			}
		}
		if n == 0 {
			return errors.New("url has an invalid port")
		}
	}
	if !urlPathAllowed.MatchString(u.RequestURI()) {
		return errors.New("url has characters that must be percent-encoded")
	}
	return nil
}

func (c *Container) validate() error {
	if c == nil {
		return nil
	}
	switch {
	case (c.Name == "") == (len(c.Labels) == 0):
		return errors.New("container: exactly one of name and labels")
	case c.Name != "" && !containerName.MatchString(c.Name):
		return fmt.Errorf("container: invalid name %q", c.Name)
	case len(c.Labels) > maxLabels:
		return fmt.Errorf("container: at most %d labels", maxLabels)
	}
	for k, v := range c.Labels {
		if !labelKeyRe.MatchString(k) || !labelValueRe.MatchString(v) {
			return fmt.Errorf("container: invalid label %q=%q", k, v)
		}
	}
	return nil
}

func fields(names ...string) map[string]bool {
	m := make(map[string]bool, len(names))
	for _, n := range names {
		m[n] = true
	}
	return m
}

func contains(list []string, s string) bool {
	for _, x := range list {
		if x == s {
			return true
		}
	}
	return false
}
