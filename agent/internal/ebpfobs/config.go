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
}

// Register adds net.ebpf.configure.
func (c *Collector) Register(reg *commands.Registry) {
	reg.Register(commandType, commands.Typed(c.configure))
}

func (c *Collector) configPath() string { return path.Join(c.opts.EtcDir, configFile) }

// configure persists the setting, applies it at once and answers with the probe's status (a kernel that cannot run it
// is a status, not a failed command).
func (c *Collector) configure(_ context.Context, cfg Config, _ commands.Stream) (any, error) {
	b, _ := json.Marshal(cfg)
	if err := c.opts.FS.MkdirAll(c.opts.EtcDir, 0o755); err != nil {
		return nil, err
	}
	if _, err := c.opts.FS.WriteFile(c.configPath(), append(b, '\n'), 0o600); err != nil {
		return nil, err
	}
	return c.setEnabled(cfg.Enabled), nil
}

// loadConfig reads the persisted setting (missing or corrupt: off).
func (c *Collector) loadConfig() {
	b, err := c.opts.FS.ReadFile(c.configPath())
	if err != nil {
		return
	}
	var cfg Config
	if err := json.Unmarshal(b, &cfg); err != nil {
		c.log.Warn("ignoring corrupt ebpf.json", "err", err)
		return
	}
	c.enabled = cfg.Enabled
}
