package agent

import (
	"context"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"

	"github.com/OthmanHaba/falak/agent/internal/config"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// UnitPath is where the systemd unit is installed.
const UnitPath = "/etc/systemd/system/falak-agent.service"

// BinaryPath is the installed binary location.
const BinaryPath = "/usr/local/bin/falak-agent"

// Unit renders the systemd unit. KillMode=mixed sends SIGTERM to the agent only, letting the built-in
// supervisor stop its programs gracefully (stop signals + timeouts) before systemd kills leftovers.
//
// No sandboxing directives (NoNewPrivileges, PrivateTmp, ProtectSystem/Home, Restrict*, ...): the supervisor starts
// sites' programs as the agent's own children (systemd-run --scope keeps the caller's process), so every one of them
// would inherit the restriction (no sudo/setuid for site users and consoles, a /tmp and mount view of their own),
// and the agent itself writes across /etc, /var, /home and the package database.
func Unit(binary string) string {
	return fmt.Sprintf(`[Unit]
Description=Falak agent
Documentation=https://falak.sh
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart=%s run
EnvironmentFile=-/etc/falak/agent.env
Restart=always
RestartSec=5
KillMode=mixed
TimeoutStopSec=90
LimitNOFILE=65536
RuntimeDirectory=falak
RuntimeDirectoryPreserve=yes
StateDirectory=falak
LogsDirectory=falak
Environment=GOMEMLIMIT=24MiB

[Install]
WantedBy=multi-user.target
`, binary)
}

// InstallOptions for `falak-agent install`.
type InstallOptions struct {
	Config  config.Config
	Source  string // path of the running binary (copied to BinaryPath)
	NoStart bool
	FS      hostfs.FS
	Runner  runner.Runner
	Out     io.Writer
}

// Install copies the binary, writes the env file + systemd unit and enables the service.
func Install(ctx context.Context, o InstallOptions) error {
	fs := o.FS
	if o.Source != "" && o.Source != fs.P(BinaryPath) {
		b, err := os.ReadFile(o.Source)
		if err != nil {
			return err
		}
		if _, err := fs.WriteFile(BinaryPath, b, 0o755); err != nil {
			return fmt.Errorf("install binary: %w", err)
		}
	}
	if err := fs.MkdirAll(o.Config.EtcDir, 0o711); err != nil {
		return err
	}
	// MkdirAll leaves an existing directory as it was: make sure it is traversable (0711).
	if _, err := hostfs.EnsureTraversable(fs, o.Config.EtcDir); err != nil {
		return err
	}
	var env strings.Builder
	add := func(k, v string) {
		if v != "" {
			fmt.Fprintf(&env, "%s=%s\n", k, v)
		}
	}
	add("FALAK_PANEL_URL", o.Config.PanelURL)
	add("FALAK_TOKEN", o.Config.Token)
	if o.Config.Insecure {
		add("FALAK_INSECURE_ENROLL", "1")
	}
	envPath := filepath.Join(o.Config.EtcDir, "agent.env")
	if env.Len() > 0 {
		if _, err := fs.WriteFile(envPath, []byte(env.String()), 0o600); err != nil {
			return err
		}
	}
	changed, err := fs.WriteFile(UnitPath, []byte(Unit(BinaryPath)), 0o644)
	if err != nil {
		return err
	}
	for _, dir := range []string{o.Config.StateDir, o.Config.LogDir, o.Config.SitesRoot} {
		if err := fs.MkdirAll(dir, 0o755); err != nil {
			return err
		}
	}
	if changed {
		if _, err := runner.Check(ctx, o.Runner, runner.Cmd{Name: "systemctl", Args: []string{"daemon-reload"}}); err != nil {
			return err
		}
	}
	if o.NoStart {
		_, err = runner.Check(ctx, o.Runner, runner.Cmd{Name: "systemctl", Args: []string{"enable", "falak-agent.service"}})
	} else {
		_, err = runner.Check(ctx, o.Runner, runner.Cmd{Name: "systemctl", Args: []string{"enable", "--now", "falak-agent.service"}})
		if err == nil {
			_, err = runner.Check(ctx, o.Runner, runner.Cmd{Name: "systemctl", Args: []string{"restart", "falak-agent.service"}})
		}
	}
	if err == nil && o.Out != nil {
		fmt.Fprintf(o.Out, "falak-agent installed (%s)\n", UnitPath)
	}
	return err
}
