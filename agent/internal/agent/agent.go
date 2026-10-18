// Package agent wires every subsystem of falak-agent together: enrollment, transport loops, the
// command registry with all executors, the supervisor, cron, terminals and the telemetry relay.
package agent

import (
	"context"
	"crypto/tls"
	"errors"
	"fmt"
	"log/slog"
	"net/http"
	"net/netip"
	"os"
	"path/filepath"
	"sort"
	"sync"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/cgroup"
	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/config"
	"github.com/OthmanHaba/falak/agent/internal/cron"
	"github.com/OthmanHaba/falak/agent/internal/db"
	"github.com/OthmanHaba/falak/agent/internal/deploy"
	"github.com/OthmanHaba/falak/agent/internal/docker"
	"github.com/OthmanHaba/falak/agent/internal/ebpfobs"
	"github.com/OthmanHaba/falak/agent/internal/edge"
	"github.com/OthmanHaba/falak/agent/internal/enroll"
	"github.com/OthmanHaba/falak/agent/internal/envlinks"
	"github.com/OthmanHaba/falak/agent/internal/facts"
	"github.com/OthmanHaba/falak/agent/internal/fngateway"
	"github.com/OthmanHaba/falak/agent/internal/functions"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/localedge"
	"github.com/OthmanHaba/falak/agent/internal/metrics"
	"github.com/OthmanHaba/falak/agent/internal/netcfg"
	"github.com/OthmanHaba/falak/agent/internal/netobs"
	"github.com/OthmanHaba/falak/agent/internal/nettool"
	"github.com/OthmanHaba/falak/agent/internal/protect"
	"github.com/OthmanHaba/falak/agent/internal/provision"
	"github.com/OthmanHaba/falak/agent/internal/pty"
	"github.com/OthmanHaba/falak/agent/internal/resources"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runtime"
	"github.com/OthmanHaba/falak/agent/internal/sandbox"
	"github.com/OthmanHaba/falak/agent/internal/security"
	"github.com/OthmanHaba/falak/agent/internal/supervisor"
	"github.com/OthmanHaba/falak/agent/internal/system"
	"github.com/OthmanHaba/falak/agent/internal/telemetry"
	"github.com/OthmanHaba/falak/agent/internal/transport"
	"github.com/OthmanHaba/falak/agent/internal/version"
	"github.com/OthmanHaba/falak/agent/internal/volumes"
)

// InsightsPoster posts NDJSON to /agent/v1/insights.
type InsightsPoster interface {
	PostInsights(ctx context.Context, ndjson []byte) error
}

// Deps are the external dependencies of Build.
type Deps struct {
	Config    config.Config
	FS        hostfs.FS
	Runner    runner.Runner
	HTTP      *http.Client // outbound downloads (artifacts, runtimes, upgrades)
	Insights  InsightsPoster
	Telemetry *telemetry.Service
	// Requests is the control plane's request channel (net.observed); nil = no reports.
	Requests netobs.Requester
	// Captures reports packet captures' progress and uploads them (net.capture); nil = captures fail.
	Captures nettool.CaptureControlPlane
	Logger   *slog.Logger
	// RestartAgent restarts the service after system.upgrade_agent.
	RestartAgent func() error
	// RelayBase and RelayHTTP reach the terminal relay: the API base the agent long-polls and an HTTP/1.1 client with
	// its mTLS identity ("" = terminal sessions ignore relay tokens).
	RelayBase string
	RelayHTTP *http.Client
	// ControlPlane resolves the control plane's addresses: never blocked (net.protection.apply, auto-bans).
	ControlPlane func(ctx context.Context) []netip.Addr
}

// Components are the long-running subsystems plus the populated registry.
type Components struct {
	Registry   *commands.Registry
	Supervisor *supervisor.Supervisor
	Cron       *cron.Scheduler
	PTY        *pty.Manager
	Docker     *docker.Service
	Edge       *edge.Manager
	Deployer   *deploy.Deployer
	Functions  *functions.Functions
	DB         *db.DB
	Volumes    *volumes.Service
	// Protection holds the block sets and the auth-log observer (docs/plans/network/protection.md).
	Protection *protect.Protection
	// Events queues OOM kills and restarts for the heartbeat.
	Events *resources.Queue
	// Network is the network collector (docs/plans/network/connections.md).
	Network *netobs.Collector
	// EBPF is the opt-in eBPF collector (docs/plans/network/ebpf.md).
	EBPF *ebpfobs.Collector
	// Sandboxes runs sandbox.* and the sandbox reaper (docs/plans/SANDBOXES.md).
	Sandboxes *sandbox.Service
}

// Build constructs every executor and registers the full v1 catalogue.
func Build(d Deps) *Components {
	cfg, log := d.Config, d.Logger
	if log == nil {
		log = slog.Default()
	}
	if d.HTTP == nil {
		d.HTTP = &http.Client{Timeout: 30 * time.Minute}
	}
	reg := commands.NewRegistry()
	sink := d.Telemetry.Sink()
	// On the control-plane machine docker workloads run under the capped workload slice, never in the host's
	// namespaces (falak-ctl's local-edge.env; see docker.Local).
	if l, err := localedge.Load(d.FS, localedge.Path); err != nil {
		log.Error("local-edge.env ignored", "err", err)
	} else if l.Enabled {
		docker.Local = l
	}

	// Programs' and cron jobs' secret env variables live on the tmpfs, never in their state files.
	// Programs with limits run in their slices (falak-<key>.slice), converged by proc.apply.
	sup := supervisor.New(supervisor.Options{StateDir: d.FS.P(cfg.StateDir), SecretsPath: d.FS.P(filepath.Join(cfg.RunDir, "state", "proc-secrets.json")),
		LogDir: d.FS.P(cfg.LogDir), Sink: sink, Logger: log.With("component", "supervisor"),
		Slices: &cgroup.Manager{Runner: d.Runner, FS: d.FS}, Systemd: d.Runner})
	var insights cron.InsightsPoster
	if d.Insights != nil {
		insights = d.Insights
	}
	tel := d.Telemetry
	sched := cron.New(cron.Options{
		StateDir: d.FS.P(cfg.StateDir), SecretsPath: d.FS.P(filepath.Join(cfg.RunDir, "state", "cron-secrets.json")), Runner: d.Runner, Insights: insights, Sink: sink,
		Logger: log.With("component", "cron"),
		SiteID: func(slug string) string {
			for _, s := range tel.Relay().Config().Sites {
				if s.Slug == slug {
					return s.SiteID
				}
			}
			return slug
		},
	})
	edgeClient := &edge.Client{Base: cfg.CaddyAdmin, Fallback: "http://127.0.0.1:2019"}
	edgeMgr := edge.New(edge.Options{Client: edgeClient, FS: d.FS, EtcDir: cfg.EtcDir, Logger: log.With("component", "edge"), Runner: d.Runner, HTTP: d.HTTP,
		RestartEdge: func(ctx context.Context) error {
			_, err := runner.Check(ctx, d.Runner, runner.Cmd{Name: "systemctl", Args: []string{"restart", "falak-edge.service"}})
			return err
		}})
	// Secrets on servers live on the tmpfs only: sites' env files and containers' secret files.
	envDir := filepath.Join(cfg.RunDir, "env")
	links := envlinks.New(filepath.Join(d.FS.P(cfg.StateDir), "env-links.json"))
	dock := docker.New(docker.Options{Socket: cfg.DockerSock, Runner: d.Runner, FS: d.FS, Upstreams: edgeMgr, SecretsDir: filepath.Join(cfg.RunDir, "secrets"),
		EnvDir: envDir, Links: links, Logger: log.With("component", "docker")})
	dep := deploy.New(deploy.Options{FS: d.FS, Runner: d.Runner, HTTP: d.HTTP, SitesRoot: cfg.SitesRoot, EnvDir: envDir, Containers: dock, Links: links,
		Procs: sup, Workers: edgeClient, Events: sink, Logger: log.With("component", "deploy")})
	terms := pty.New(pty.Options{Logger: log.With("component", "pty"), RelayBase: d.RelayBase, RelayHTTP: d.RelayHTTP, Docker: docker.NewClient(cfg.DockerSock)})

	system.New(system.Deps{Runner: d.Runner, FS: d.FS, Logger: log, HTTP: d.HTTP, AgentVersion: version.Version, Restart: d.RestartAgent,
		BinaryPath: installedBinary(d.FS), RunningSHA256: version.BinarySHA256, SiteSecrets: dep.SiteSecrets}).Register(reg)
	provision.New(provision.Deps{Runner: d.Runner, FS: d.FS, Logger: log, HTTP: d.HTTP}).Register(reg)
	runtime.New(runtime.Deps{Runner: d.Runner, FS: d.FS, Logger: log, HTTP: d.HTTP}).Register(reg)
	edgeMgr.Register(reg)
	dep.Register(reg)
	dock.Register(reg) // docker.* + deploy.container.swap
	sup.Register(reg)
	sched.Register(reg)
	dbs := db.New(db.Deps{Runner: d.Runner, Docker: docker.NewClient(cfg.DockerSock), FS: d.FS, Logger: log.With("component", "db"), HTTP: d.HTTP,
		VolumesRoot: filepath.Join(cfg.StateDir, "volumes"), SecretsDir: filepath.Join(cfg.RunDir, "secrets"), EtcDir: cfg.EtcDir})
	dbs.Register(reg)
	netcfg.New(netcfg.Deps{Runner: d.Runner, FS: d.FS, Logger: log, HTTP: d.HTTP}).Register(reg)
	network := netobs.New(netobs.Options{FS: d.FS, EtcDir: cfg.EtcDir, Workloads: d.Telemetry.Workloads(), CP: d.Requests,
		Out: d.Telemetry.Relay(), Runner: d.Runner, Logger: log.With("component", "network")})
	network.Register(reg)
	d.Telemetry.Follow(network)
	probe := ebpfobs.New(ebpfobs.Options{FS: d.FS, EtcDir: cfg.EtcDir, Workloads: d.Telemetry.Workloads(), Names: network.Namer,
		Out: d.Telemetry.Relay(), Logger: log.With("component", "ebpf")})
	probe.Register(reg)
	d.Telemetry.Follow(probe)
	// Talks-to sees short-lived connections (a database connection per request) while the probe runs.
	network.SetConnects(func() map[netobs.Connect]uint64 {
		m := probe.DrainConnects()
		out := make(map[netobs.Connect]uint64, len(m))
		for k, v := range m {
			out[netobs.Connect(k)] = v
		}
		return out
	})
	prot := protect.New(protect.Deps{Runner: d.Runner, FS: d.FS, Logger: log.With("component", "protection"), StateDir: cfg.StateDir,
		ControlPlane: d.ControlPlane})
	prot.Register(reg)
	fns := functions.New(functions.Deps{FS: d.FS, Runner: d.Runner, Docker: docker.NewClient(cfg.DockerSock), Gateway: fngateway.NewClient(""),
		Logger: log.With("component", "functions"), Binary: BinaryPath, Version: version.Version})
	fns.Register(reg)
	vols := volumes.New(volumes.Deps{Runner: d.Runner, FS: d.FS, HTTP: d.HTTP, Docker: docker.NewClient(cfg.DockerSock), Logger: log.With("component", "volumes"),
		Root: filepath.Join(cfg.StateDir, "volumes"), SitesRoot: cfg.SitesRoot, BindAllow: cfg.BindAllow()})
	vols.Register(reg)
	security.New(security.Deps{Runner: d.Runner, FS: d.FS, Logger: log.With("component", "security"), SitesRoot: cfg.SitesRoot, RunDir: cfg.RunDir,
		StateDir: cfg.StateDir}).Register(reg)
	d.Telemetry.Register(reg)
	terms.Register(reg)
	// net.tool (docs/plans/network/tools.md): the helper is this binary, re-run as `falak-agent nettool`.
	// net.capture (docs/plans/network/capture.md): tcpdump on the host or under nsenter, uploaded to the control plane.
	nt := nettool.New(nettool.Options{Logger: log, Docker: docker.NewClient(cfg.DockerSock), Binary: installedBinary(d.FS),
		CP: d.Captures, CaptureDir: d.FS.P(filepath.Join(cfg.StateDir, "captures")),
		InstallTcpdump: func(ctx context.Context, st commands.Stream) error {
			_, err := system.AptFor(d.Runner, d.FS, st).Ensure(ctx, []string{"tcpdump"}, true)
			return err
		}})
	nt.Register(reg)
	nt.RegisterCapture(reg)
	sbx := sandbox.New(sandbox.Deps{Runner: d.Runner, FS: d.FS, HTTP: d.HTTP, Docker: docker.NewClient(cfg.DockerSock), Requests: d.Requests, DB: dbs,
		Logger: log.With("component", "sandbox"), Root: d.FS.P(filepath.Join(cfg.StateDir, "sandboxes"))})
	sbx.Register(reg)

	return &Components{Registry: reg, Supervisor: sup, Cron: sched, PTY: terms, Docker: dock, Edge: edgeMgr, Deployer: dep, Functions: fns, DB: dbs, Volumes: vols,
		Protection: prot, Events: &resources.Queue{}, Network: network, EBPF: probe, Sandboxes: sbx}
}

// ensureEnrolled enrolls only when there is no identity yet: `falak-agent run` never replaces one because
// FALAK_TOKEN is still in agent.env (see EnrollOnly). With restore (`run` only), an identity left in an unfinished
// replacement's backup comes back before any enrollment.
func ensureEnrolled(ctx context.Context, cfg config.Config, log *slog.Logger, restore bool) (*enroll.Identity, error) {
	paths := enroll.Paths{Dir: cfg.EtcDir}
	if !paths.Enrolled() && !(restore && restoreIncomplete(cfg, log)) {
		if cfg.PanelURL == "" || cfg.Token == "" {
			return nil, errors.New("agent is not enrolled: set FALAK_PANEL_URL and FALAK_TOKEN (or --panel/--token)")
		}
		if err := os.MkdirAll(cfg.EtcDir, 0o711); err != nil {
			return nil, err
		}
		if _, err := hostfs.EnsureTraversable(hostfs.FS{}, cfg.EtcDir); err != nil {
			return nil, err
		}
		if _, err := enrollInto(ctx, cfg, log, paths); err != nil {
			return nil, err
		}
	}
	return enroll.Load(paths)
}

// enrollInto enrolls with cfg's panel URL and token and writes the identity to paths.
func enrollInto(ctx context.Context, cfg config.Config, log *slog.Logger, paths enroll.Paths) (*enroll.State, error) {
	f, err := facts.Collect(ctx, runner.Exec{}, hostfs.FS{Root: cfg.HostRoot}, version.Version)
	if err != nil {
		log.Warn("facts collection incomplete", "err", err)
	}
	host, _ := os.Hostname()
	hc := &http.Client{Timeout: 60 * time.Second}
	if cfg.Insecure {
		hc.Transport = &http.Transport{TLSClientConfig: &tls.Config{InsecureSkipVerify: true}} //nolint:gosec // explicit dev-only flag
	}
	st, err := enroll.Enroll(ctx, enroll.Options{PanelURL: cfg.PanelURL, Token: cfg.Token, Facts: f, Hostname: host, Paths: paths, Client: hc})
	if err != nil {
		return nil, err
	}
	log.Info("enrolled", "agent_id", st.AgentID, "api", st.Endpoints.API)
	return st, nil
}

// Run is `falak-agent run`: enroll if needed, start every subsystem, serve until ctx is cancelled.
func Run(ctx context.Context, cfg config.Config, log *slog.Logger) error {
	id, err := ensureEnrolled(ctx, cfg, log, true)
	if err != nil {
		return err
	}
	fs := hostfs.FS{Root: cfg.HostRoot}
	for _, dir := range []string{cfg.StateDir, cfg.LogDir, cfg.RunDir} {
		if err := fs.MkdirAll(dir, 0o755); err != nil {
			return err
		}
	}
	if cfg.OTLPSocket != "" {
		_ = os.MkdirAll(filepath.Dir(cfg.OTLPSocket), 0o755)
	}
	log = log.With("agent_id", id.State.AgentID)
	client := transport.New(id.State.Endpoints.API, id.TLSConfig())
	client.UserAgent = "falak-agent/" + version.Version
	client.Session = transport.NewSessionID()
	client.AgentID, client.Log = id.State.AgentID, log.With("component", "transport")
	// Checksum the running build now, before a system.upgrade_agent could replace the file on disk.
	_ = version.BinarySHA256()
	host, _ := os.Hostname()
	r := runner.Exec{}
	// An agent upgrade never re-ran provisioning: bring an older falak-edge.service up to date (see RefreshEdgeUnit).
	if changed, err := runtime.RefreshEdgeUnit(ctx, r, fs); err != nil {
		log.Warn("falak-edge.service refresh failed", "err", err)
	} else if changed {
		log.Info("falak-edge.service updated to the current unit")
	}

	tel, err := telemetry.New(telemetry.Options{
		FS: fs, EtcDir: cfg.EtcDir, StateDir: cfg.StateDir, UnixSocket: cfg.OTLPSocket, HTTPAddr: cfg.OTLPHTTP,
		Endpoint: id.State.Endpoints.OTLP, ServerID: id.State.AgentID, HostName: host, DockerSocket: cfg.DockerSock,
		SitesRoot: cfg.SitesRoot, Insights: client, Logger: log.With("component", "telemetry"),
	})
	if err != nil {
		return err
	}
	comps := Build(Deps{
		Config: cfg, FS: fs, Runner: r, Insights: client, Requests: client, Captures: client, Telemetry: tel, Logger: log,
		RelayBase: id.State.Endpoints.API, RelayHTTP: transport.NewHTTP1Client(id.TLSConfig()),
		ControlPlane: protect.ResolveHosts(id.State.Endpoints.API, id.State.Endpoints.OTLP, cfg.PanelURL),
		RestartAgent: func() error {
			// --no-block queues the restart so the upgrade command's finished event is flushed first.
			_, err := runner.Check(context.Background(), r, runner.Cmd{Name: "systemctl", Args: []string{"--no-block", "restart", "falak-agent.service"}})
			return err
		},
	})

	// Not derived from ctx: on shutdown the subsystems keep running while commands drain (see gracefulStop).
	runCtx, cancel := context.WithCancel(context.WithoutCancel(ctx))
	defer cancel()
	// A Caddy build switch an agent restart interrupted: kept when the edge answers, else rolled back.
	if err := comps.Edge.ResolvePendingSwap(runCtx); err != nil {
		log.Error("edge Caddy build switch", "err", err)
	}
	if err := tel.Start(runCtx); err != nil {
		return fmt.Errorf("telemetry: %w", err)
	}
	if err := comps.Supervisor.Start(runCtx); err != nil {
		log.Error("supervisor restore failed", "err", err)
	}
	if err := comps.Cron.Start(runCtx); err != nil {
		log.Error("cron restore failed", "err", err)
	}

	outbox := transport.NewOutbox(client, log.With("component", "outbox"))
	outboxCtx, stopOutbox := context.WithCancel(context.Background())
	var outboxDone sync.WaitGroup
	outboxDone.Add(1)
	go func() { defer outboxDone.Done(); outbox.Run(outboxCtx) }()

	disp := commands.NewDispatcher(runCtx, comps.Registry, outbox, log.With("component", "dispatcher"))
	// Completed commands survive restarts, so a finished non-idempotent step is never re-run.
	if err := disp.Persist(filepath.Join(fs.P(cfg.StateDir), "commands.json"), commands.DefaultJournalSize); err != nil {
		log.Warn("command journal", "err", err)
	}
	poller := &transport.Poller{Client: client, Submit: disp.Submit, Wait: cfg.PollWait, Log: log.With("component", "poller")}
	hb := &transport.Heartbeater{
		Client: client, Interval: cfg.Heartbeat, Running: disp.Running, Log: log.With("component", "heartbeat"),
		ServiceEvents: func() (any, func()) {
			if ev, delivered := comps.Events.Pending(); len(ev) > 0 {
				return ev, delivered
			}
			return nil, nil
		},
		Summary: func() transport.Heartbeat {
			s := tel.Summary()
			hb := transport.Heartbeat{UptimeS: s.UptimeS, Load: s.Load, CPUPercent: s.CPUPercent, MemoryUsedBytes: s.MemUsedBytes, DiskUsedBytes: s.DiskUsedBytes,
				Disks: disksOrNil(s.Disks), MissingSecrets: missingSecrets(comps.Deployer.MissingSecrets(runCtx), comps.Supervisor.WaitingSites(), comps.Cron.WaitingSites())}
			dctx, cancel := context.WithTimeout(runCtx, 5*time.Second)
			defer cancel()
			if dbs := comps.DB.Report(dctx); len(dbs) > 0 {
				hb.Databases = dbs
			}
			return hb
		},
		Facts: func(ctx context.Context) (any, error) {
			f, err := facts.Collect(ctx, r, fs, version.Version)
			f.EBPF = comps.EBPF.Status()
			// Facts are re-collected every few minutes: a renamed host shows up in telemetry without a restart.
			tel.SetHostName(f.Hostname)
			return f, err
		},
	}
	renewer := &transport.Renewer{
		NeedsRenewal: id.NeedsRenewal,
		Renew:        func(ctx context.Context) error { return id.Renew(ctx, client.Renew) },
		Log:          log.With("component", "renew"),
	}
	pollCtx, stopPolling := context.WithCancel(runCtx)
	var polling, loops sync.WaitGroup
	polling.Add(1)
	go func() { defer polling.Done(); poller.Run(pollCtx) }()
	for _, fn := range []func(context.Context){hb.Run, renewer.Run} {
		loops.Add(1)
		go func(f func(context.Context)) { defer loops.Done(); f(runCtx) }(fn)
	}
	log.Info("falak-agent running", "version", version.Version, "session", client.Session, "commands", len(comps.Registry.Types()))
	loops.Add(1)
	go func() { defer loops.Done(); sweepDrills(runCtx, comps, log, time.Hour) }()
	// OOM kills and restarts of containers, slices and programs, delivered with the heartbeats.
	for _, fn := range []func(context.Context){
		func(ctx context.Context) { comps.Docker.Watch(ctx, comps.Events.Add) },
		func(ctx context.Context) { comps.Supervisor.WatchRestarts(ctx, comps.Events.Add) },
		func(ctx context.Context) {
			watchSliceOOM(ctx, &cgroup.WatchOOM{Root: fs.P(cgroup.CgroupRoot)}, comps.Events.Add, time.Minute)
		},
	} {
		loops.Add(1)
		go func(f func(context.Context)) { defer loops.Done(); f(runCtx) }(fn)
	}
	// Point-in-time recovery: the spools of instances with PITR on go to storage through presigned URLs.
	shipper := comps.DB.NewShipper(client)
	loops.Add(1)
	go func() { defer loops.Done(); shipper.Run(runCtx) }()
	// Database observability: engine metrics, slow queries and the db.observed report (docs/plans/OBSERVABILITY.md
	// phase 3).
	observer := comps.DB.NewObserver(client, tel.Sink(), tel.Relay())
	loops.Add(1)
	go func() { defer loops.Done(); observer.Run(runCtx) }()
	// Connections, listeners, peers, conntrack, WireGuard and probes (docs/plans/network/connections.md).
	loops.Add(1)
	go func() { defer loops.Done(); comps.Network.Run(runCtx) }()
	// The opt-in eBPF probe (docs/plans/network/ebpf.md): detached when runCtx ends.
	loops.Add(1)
	go func() { defer loops.Done(); comps.EBPF.Run(runCtx) }()
	// Sandboxes: TTLs enforced here even when the control plane is unreachable; recovery after a restart or reboot.
	loops.Add(1)
	go func() { defer loops.Done(); comps.Sandboxes.Run(runCtx) }()
	// Protection (docs/plans/network/protection.md): block sets restored, sshd's auth failures, firewall counters.
	protection := comps.Protection.NewObserver(client, tel.Relay())
	loops.Add(1)
	go func() { defer loops.Done(); protection.Run(runCtx) }()
	loops.Add(1)
	go func() {
		defer loops.Done()
		if err := comps.Functions.RefreshGateway(runCtx); err != nil {
			log.Warn("function gateway refresh failed", "err", err)
		}
	}()

	<-ctx.Done()
	log.Info("shutting down")
	gracefulStop(stopPolling, polling.Wait, disp.Wait, shutdownDrain, cancel)
	loops.Wait()
	waitTimeout(disp.Wait, 10*time.Second)
	comps.PTY.CloseAll()
	comps.Supervisor.Shutdown()
	comps.Cron.Wait()
	// Telemetry stops with runCtx (cancelled above) and writes its state on the way out (log offsets, OTLP buffer):
	// done before Run returns, never into a state directory that is being removed or reused.
	waitTimeout(tel.Wait, 15*time.Second)
	stopOutbox()
	outboxDone.Wait()
	return nil
}

// shutdownDrain is how long running commands may finish on shutdown before they are cancelled (the systemd unit
// allows 90s in total).
const shutdownDrain = 20 * time.Second

// gracefulStop shuts the command path down in order:
//
//  1. stop long-polling and wait until the poller has returned: from here on no command is accepted, so none is
//     received by a process that is about to exit (the control plane redelivers to the next session instead);
//  2. let running commands finish for up to drain, while heartbeats keep reporting them;
//  3. cancel everything else (still-running commands, heartbeats, telemetry, supervisor).
func gracefulStop(stopPolling, pollerStopped, commandsDone func(), drain time.Duration, cancelRest func()) {
	stopPolling()
	pollerStopped()
	waitTimeout(commandsDone, drain)
	cancelRest()
}

func waitTimeout(fn func(), d time.Duration) {
	done := make(chan struct{})
	go func() { fn(); close(done) }()
	select {
	case <-done:
	case <-time.After(d):
	}
}

// installedBinary is the path system.upgrade_agent replaces: the systemd unit's binary (BinaryPath) when the
// agent is installed, else the running executable ("" = os.Executable()).
func installedBinary(fs hostfs.FS) string {
	if fs.Exists(BinaryPath) {
		return fs.P(BinaryPath)
	}
	return ""
}

// missingSecrets merges the sites whose secrets are gone (env files, container files, waiting programs and jobs).
// disksOrNil keeps `disks` out of the heartbeat when no mount was readable (an interface holding a nil slice is not nil).
func disksOrNil(d []metrics.DiskUsage) any {
	if len(d) == 0 {
		return nil
	}
	return d
}

func missingSecrets(lists ...[]string) []string {
	seen := map[string]bool{}
	out := []string{}
	for _, l := range lists {
		for _, s := range l {
			if !seen[s] {
				seen[s] = true
				out = append(out, s)
			}
		}
	}
	sort.Strings(out)
	return out
}

// sweepDrills removes what interrupted restore drills left behind (containers, scratch data, secret files), at start
// and then every interval.
func sweepDrills(ctx context.Context, comps *Components, log *slog.Logger, every time.Duration) {
	for {
		n := comps.DB.SweepDrills(ctx, db.DrillMaxAge) + comps.Volumes.SweepDrills(db.DrillMaxAge)
		if n > 0 {
			log.Info("removed leftovers of interrupted restore drills", "count", n)
		}
		select {
		case <-ctx.Done():
			return
		case <-time.After(every):
		}
	}
}

// watchSliceOOM reads the slices' OOM-kill counters every interval until ctx ends.
func watchSliceOOM(ctx context.Context, w *cgroup.WatchOOM, add func(resources.Event), every time.Duration) {
	t := time.NewTicker(every)
	defer t.Stop()
	for {
		w.Once(add)
		select {
		case <-ctx.Done():
			return
		case <-t.C:
		}
	}
}
