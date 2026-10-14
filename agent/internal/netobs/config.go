package netobs

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/netip"
	"path"
	"regexp"

	"github.com/OthmanHaba/falak/agent/internal/commands"
)

// Config is net.connections.configure (contracts/agent-protocol/commands/net.connections.configure.schema.json): what
// only the control plane knows, whom to probe and what to call the addresses the agent sees.
type Config struct {
	Probes          []ProbeTarget `json:"probes"`
	Names           []PeerName    `json:"names"`
	WireGuard       []WGName      `json:"wireguard"`
	LongLivedAfterS int           `json:"long_lived_after_s,omitempty"`
}

// ProbeTarget is a TCP connect probe, once a minute: a private-network peer's address on a port nothing listens on, so
// the peer's kernel answers with a RST (the round trip) and no service ever sees a connection. Never a database port:
// MySQL and MariaDB count a connection closed before its handshake as an aborted connect (and may block the host).
type ProbeTarget struct {
	Name string `json:"name"`
	Kind string `json:"kind"` // server
	Host string `json:"host"`
	Port int    `json:"port"`
}

// PeerName names an address (and port; 0 = any) on metrics and in the report.
type PeerName struct {
	Address string `json:"address"`
	Port    int    `json:"port"`
	Name    string `json:"name"`
}

// WGName names a WireGuard peer by its public key.
type WGName struct {
	PublicKey string `json:"public_key"`
	Name      string `json:"name"`
}

// Result of net.connections.configure.
type Result struct {
	Changed bool `json:"changed"`
}

const (
	maxProbes            = 64
	maxNames             = 512
	maxWGNames           = 128
	defaultLongLivedS    = 60
	configFile           = "net.json"
	commandType          = "net.connections.configure"
	minLongLived         = 15
	maxLongLived         = 3600
	probeKindServer      = "server"
	maxNameLen           = 160
	maxProbeHostLen      = 253
	namePrefixPattern    = `^(server|db|site):[A-Za-z0-9._/-]{1,150}$`
	probeHostNamePattern = `^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$`
)

var (
	nameRe      = regexp.MustCompile(namePrefixPattern)
	probeHostRe = regexp.MustCompile(probeHostNamePattern)
	wgKeyRe     = regexp.MustCompile(`^[A-Za-z0-9+/]{42,43}=$`)
)

func (cfg Config) validate() error {
	if len(cfg.Probes) > maxProbes || len(cfg.Names) > maxNames || len(cfg.WireGuard) > maxWGNames {
		return errors.New("too many probes, names or wireguard peers")
	}
	for _, p := range cfg.Probes {
		if p.Kind != probeKindServer {
			return fmt.Errorf("probe %q: kind must be server", p.Name)
		}
		if !nameRe.MatchString(p.Name) || p.Port < 1 || p.Port > 65535 || len(p.Host) > maxProbeHostLen {
			return fmt.Errorf("probe %q: invalid name or port", p.Name)
		}
		if _, err := netip.ParseAddr(p.Host); err != nil && !probeHostRe.MatchString(p.Host) {
			return fmt.Errorf("probe %q: invalid host %q", p.Name, p.Host)
		}
	}
	for _, n := range cfg.Names {
		if _, err := netip.ParseAddr(n.Address); err != nil {
			return fmt.Errorf("name %q: invalid address %q", n.Name, n.Address)
		}
		if !nameRe.MatchString(n.Name) || n.Port < 0 || n.Port > 65535 {
			return fmt.Errorf("name %q: invalid name or port", n.Name)
		}
	}
	for _, w := range cfg.WireGuard {
		if !wgKeyRe.MatchString(w.PublicKey) || !nameRe.MatchString(w.Name) {
			return fmt.Errorf("wireguard peer %q: invalid key or name", w.Name)
		}
	}
	if cfg.LongLivedAfterS != 0 && (cfg.LongLivedAfterS < minLongLived || cfg.LongLivedAfterS > maxLongLived) {
		return fmt.Errorf("long_lived_after_s must be within [%d,%d]", minLongLived, maxLongLived)
	}
	return nil
}

// Register adds net.connections.configure.
func (c *Collector) Register(reg *commands.Registry) {
	reg.Register(commandType, commands.Typed(c.configure))
}

func (c *Collector) configPath() string { return path.Join(c.opts.EtcDir, configFile) }

func (c *Collector) configure(_ context.Context, cfg Config, _ commands.Stream) (any, error) {
	if err := cfg.validate(); err != nil {
		return nil, &commands.PayloadError{Err: err}
	}
	b, err := json.MarshalIndent(cfg, "", "  ")
	if err != nil {
		return nil, err
	}
	b = append(b, '\n')
	cur, _ := c.opts.FS.ReadFile(c.configPath())
	changed := !bytes.Equal(cur, b)
	if changed {
		if err := c.opts.FS.MkdirAll(c.opts.EtcDir, 0o755); err != nil {
			return nil, err
		}
		if _, err := c.opts.FS.WriteFile(c.configPath(), b, 0o600); err != nil {
			return nil, err
		}
	}
	c.SetConfig(cfg)
	return Result{Changed: changed}, nil
}

// loadConfig reads the persisted configuration (a missing or corrupt file leaves none: no probes, no names).
func (c *Collector) loadConfig() {
	b, err := c.opts.FS.ReadFile(c.configPath())
	if err != nil {
		return
	}
	var cfg Config
	if err := json.Unmarshal(b, &cfg); err != nil || cfg.validate() != nil {
		c.log.Warn("ignoring corrupt net.json", "err", err)
		return
	}
	c.SetConfig(cfg)
}
