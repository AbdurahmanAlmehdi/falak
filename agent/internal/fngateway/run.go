package fngateway

import (
	"context"
	"log/slog"
	"path/filepath"

	"github.com/OthmanHaba/falak/agent/internal/docker"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/localedge"
)

// RunOptions of `falak-agent fn-gateway`.
type RunOptions struct {
	Listen       string
	AdminSocket  string
	StateDir     string
	DockerSocket string
	// AgentOTLPSocket is the agent's OTLP receiver (functions' telemetry is relayed there).
	AgentOTLPSocket string
	Version         string
	Logger          *slog.Logger
}

// Run restores the registered functions, adopts their containers and serves until ctx ends.
func Run(ctx context.Context, o RunOptions) error {
	if o.Listen == "" {
		o.Listen = DefaultListen
	}
	if o.AdminSocket == "" {
		o.AdminSocket = DefaultAdmin
	}
	if o.StateDir == "" {
		o.StateDir = DefaultStateDir
	}
	// On the control-plane machine function containers are capped and guarded like the agent's (docker.Local): this
	// process creates them, not the agent.
	local, err := localedge.Load(hostfs.FS{}, localedge.Path)
	if err != nil {
		return err
	}
	if local.Enabled {
		docker.Local = local
	}
	g := New(Options{
		Engine:          DockerEngine{C: docker.NewClient(o.DockerSocket)},
		StateFile:       filepath.Join(o.StateDir, "gateway.json"),
		Logger:          o.Logger,
		Version:         o.Version,
		AgentOTLPSocket: o.AgentOTLPSocket,
	})
	if err := g.Load(ctx); err != nil {
		return err
	}
	return g.Serve(ctx, o.Listen, o.AdminSocket)
}
