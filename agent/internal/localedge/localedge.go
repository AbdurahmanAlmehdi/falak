// Package localedge reads /etc/falak/local-edge.env, which falak-ctl writes on the control-plane machine when it is
// also a Falak server: the control plane's edge owns ports 80/443 there and passes every host that is not the panel's
// to this server's edge on a bridge address (TLS untouched, PROXY protocol v2). Without the file nothing changes.
package localedge

import (
	"bufio"
	"bytes"
	"errors"
	"fmt"
	"io/fs"
	"net/netip"
	"regexp"
	"strconv"
	"strings"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
)

// Path is written by falak-ctl (KEY=VALUE lines).
const Path = "/etc/falak/local-edge.env"

// Config is the local edge setup. The zero value (no file, FALAK_LOCAL_EDGE not 1) is a regular server.
type Config struct {
	Enabled bool
	// Addr is the docker bridge gateway the control plane's edge dials (the edge listens there, not on 0.0.0.0).
	Addr      string
	HTTPPort  int
	HTTPSPort int
	// Trust is the CIDR of the control plane's edge network: the only PROXY protocol header honoured, and the only
	// source the firewall lets reach the ports.
	Trust string
	// WorkloadSlice is the systemd slice docker workloads are put under (the capped falak.slice), empty = none.
	WorkloadSlice string
	// Panel is the control plane's edge address: tenants may reach the panel through it (80/443), nothing else of the
	// control plane's stack. Empty = no rule.
	Panel string
	// PanelPorts are the TCP ports of Panel tenants may reach (FALAK_LOCAL_EDGE_PANEL_PORTS, default 80 and 443).
	PanelPorts []int
	// Protect are the networks of the control plane's compose project (IPv4 CIDRs) tenants must never reach.
	Protect []string
	// ProjectName is the control plane's compose project (docker.prune never touches it); empty = unset.
	ProjectName string
}

var (
	sliceName   = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9_.-]*\.slice$`)
	projectName = regexp.MustCompile(`^[a-z0-9][a-z0-9_-]{0,62}$`)
)

// Load reads the file at p (Path on a host). A missing file is the zero Config; a malformed one is an error, so a
// half-written setup never puts the edge on the wrong ports.
func Load(f hostfs.FS, p string) (Config, error) {
	b, err := f.ReadFile(p)
	if errors.Is(err, fs.ErrNotExist) {
		return Config{}, nil
	}
	if err != nil {
		return Config{}, err
	}
	return Parse(b)
}

// Parse parses the file's content.
func Parse(b []byte) (Config, error) {
	kv := map[string]string{}
	sc := bufio.NewScanner(bytes.NewReader(b))
	for sc.Scan() {
		line := strings.TrimSpace(sc.Text())
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		k, v, ok := strings.Cut(line, "=")
		if !ok {
			return Config{}, fmt.Errorf("local-edge.env: invalid line %q", line)
		}
		kv[strings.TrimSpace(k)] = strings.Trim(strings.TrimSpace(v), `"'`)
	}
	if kv["FALAK_LOCAL_EDGE"] != "1" {
		return Config{}, nil
	}
	c := Config{Enabled: true, Addr: kv["FALAK_LOCAL_EDGE_ADDR"], Trust: kv["FALAK_LOCAL_EDGE_TRUST"], WorkloadSlice: kv["FALAK_WORKLOAD_SLICE"]}
	if a, err := netip.ParseAddr(c.Addr); err != nil || !a.Is4() {
		return Config{}, fmt.Errorf("local-edge.env: FALAK_LOCAL_EDGE_ADDR %q is not an IPv4 address", c.Addr)
	}
	if p, err := netip.ParsePrefix(c.Trust); err != nil || !p.Addr().Is4() {
		return Config{}, fmt.Errorf("local-edge.env: FALAK_LOCAL_EDGE_TRUST %q is not an IPv4 CIDR", c.Trust)
	}
	for _, port := range []struct {
		key string
		def int
		dst *int
	}{{"FALAK_LOCAL_EDGE_HTTP_PORT", 8080, &c.HTTPPort}, {"FALAK_LOCAL_EDGE_HTTPS_PORT", 8443, &c.HTTPSPort}} {
		*port.dst = port.def
		if v := kv[port.key]; v != "" {
			n, err := strconv.Atoi(v)
			if err != nil || n < 1 || n > 65535 || n == 80 || n == 443 {
				return Config{}, fmt.Errorf("local-edge.env: invalid %s %q", port.key, v)
			}
			*port.dst = n
		}
	}
	if c.HTTPPort == c.HTTPSPort {
		return Config{}, errors.New("local-edge.env: the HTTP and HTTPS ports must differ")
	}
	if c.WorkloadSlice != "" && !sliceName.MatchString(c.WorkloadSlice) {
		return Config{}, fmt.Errorf("local-edge.env: FALAK_WORKLOAD_SLICE %q is not a slice name", c.WorkloadSlice)
	}
	if c.Panel = kv["FALAK_LOCAL_EDGE_PANEL"]; c.Panel != "" {
		if a, err := netip.ParseAddr(c.Panel); err != nil || !a.Is4() {
			return Config{}, fmt.Errorf("local-edge.env: FALAK_LOCAL_EDGE_PANEL %q is not an IPv4 address", c.Panel)
		}
		c.PanelPorts = []int{80, 443}
		if v := kv["FALAK_LOCAL_EDGE_PANEL_PORTS"]; v != "" {
			c.PanelPorts = nil
			for _, s := range strings.Split(v, ",") {
				n, err := strconv.Atoi(strings.TrimSpace(s))
				if err != nil || n < 1 || n > 65535 {
					return Config{}, fmt.Errorf("local-edge.env: FALAK_LOCAL_EDGE_PANEL_PORTS %q: ports 1-65535", v)
				}
				c.PanelPorts = append(c.PanelPorts, n)
			}
			if len(c.PanelPorts) > 4 {
				return Config{}, fmt.Errorf("local-edge.env: FALAK_LOCAL_EDGE_PANEL_PORTS %q: at most 4 ports", v)
			}
		}
	}
	if v := kv["FALAK_LOCAL_EDGE_PROTECT"]; v != "" {
		for _, s := range strings.Split(v, ",") {
			s = strings.TrimSpace(s)
			if p, err := netip.ParsePrefix(s); err != nil || !p.Addr().Is4() {
				return Config{}, fmt.Errorf("local-edge.env: FALAK_LOCAL_EDGE_PROTECT %q is not an IPv4 CIDR", s)
			}
			c.Protect = append(c.Protect, s)
		}
	}
	if c.ProjectName = kv["FALAK_PROJECT_NAME"]; c.ProjectName != "" && !projectName.MatchString(c.ProjectName) {
		return Config{}, fmt.Errorf("local-edge.env: FALAK_PROJECT_NAME %q is not a compose project name", c.ProjectName)
	}
	return c, nil
}

// Slice is the systemd slice every workload (native or docker) and the edge run under on the control-plane machine:
// FALAK_WORKLOAD_SLICE, falak.slice when unset (falak-ctl caps it). "" on other servers.
func (c Config) Slice() string {
	switch {
	case !c.Enabled:
		return ""
	case c.WorkloadSlice != "":
		return c.WorkloadSlice
	}
	return "falak.slice"
}

// HTTPListen and HTTPSListen are the edge's listen addresses.
func (c Config) HTTPListen() string  { return c.Addr + ":" + strconv.Itoa(c.HTTPPort) }
func (c Config) HTTPSListen() string { return c.Addr + ":" + strconv.Itoa(c.HTTPSPort) }
