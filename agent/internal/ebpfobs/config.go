package ebpfobs

import (
	"context"
	"encoding/json"
	"path"

	"github.com/OthmanHaba/falak/agent/internal/commands"
)

const (
	commandType = "net.ebpf.configure"
	configFile  = "ebpf.json"
)

// Config is net.ebpf.configure (contracts/agent-protocol/commands/net.ebpf.configure.schema.json).
type Config struct {
	Enabled bool `json:"enabled"`
	// FullDNSNames ships whole query names; off, names fold to their registrable domain (api.stripe.com → stripe.com).
	FullDNSNames bool `json:"full_dns_names,omitempty"`
	// Reenable clears an over-budget stop: only the panel's explicit action sends it, never a re-send of the setting.
	Reenable bool `json:"reenable,omitempty"`
}

// persisted is ebpf.json: the setting and an over-budget stop, which survives restarts.
type persisted struct {
	Enabled      bool   `json:"enabled"`
	FullDNSNames bool   `json:"full_dns_names,omitempty"`
	AutoDisabled string `json:"auto_disabled,omitempty"`
}

// Register adds net.ebpf.configure.
func (c *Collector) Register(reg *commands.Registry) {
	reg.Register(commandType, commands.Typed(c.configure))
}

func (c *Collector) configPath() string { return path.Join(c.opts.EtcDir, configFile) }

// configure applies (and persists) the setting at once and answers with the probe's status (a kernel that cannot run
// it is a status, not a failed command).
func (c *Collector) configure(_ context.Context, cfg Config, _ commands.Stream) (any, error) {
	return c.apply(cfg), nil
}

// saveLocked writes ebpf.json. Called with c.mu held.
func (c *Collector) saveLocked() error {
	if c.opts.EtcDir == "" {
		return nil
	}
	b, _ := json.Marshal(persisted{Enabled: c.enabled, FullDNSNames: c.fullNames, AutoDisabled: c.autoDisabled})
	if err := c.opts.FS.MkdirAll(c.opts.EtcDir, 0o755); err != nil {
		return err
	}
	_, err := c.opts.FS.WriteFile(c.configPath(), append(b, '\n'), 0o600)
	return err
}

// loadConfig reads the persisted setting (missing or corrupt: off).
func (c *Collector) loadConfig() {
	b, err := c.opts.FS.ReadFile(c.configPath())
	if err != nil {
		return
	}
	var p persisted
	if err := json.Unmarshal(b, &p); err != nil {
		c.log.Warn("ignoring corrupt ebpf.json", "err", err)
		return
	}
	c.enabled, c.fullNames, c.autoDisabled = p.Enabled, p.FullDNSNames, p.AutoDisabled
	c.status.Enabled = p.Enabled
	if p.AutoDisabled != "" {
		c.status.State, c.status.Detail = StateOverBudget, p.AutoDisabled
	}
}
