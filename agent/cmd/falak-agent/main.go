// Command falak-agent is the Falak server daemon.
//
//	falak-agent run        enroll if needed (FALAK_PANEL_URL + FALAK_TOKEN), then serve
//	falak-agent enroll     enroll only; with a token it replaces an existing identity (backup in /etc/falak/previous/)
//	falak-agent install    install binary + systemd unit and start the service
//	falak-agent check      verify the identity and that the agent API accepts it (--wait 60s keeps retrying)
//	falak-agent fn-gateway serve functions (falak-fn-gateway.service; installed by fn.release.apply)
//	falak-agent fn-run     run a function's schedule once (its cron job)
//	falak-agent features   the protocol features this build understands, one per line
//	falak-agent nettool    run one network tool (net.tool's helper, run by the agent; under nsenter for a container)
//	falak-agent version
package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"log/slog"
	"os"
	"os/signal"
	"syscall"

	"github.com/OthmanHaba/falak/agent/internal/agent"
	"github.com/OthmanHaba/falak/agent/internal/config"
	"github.com/OthmanHaba/falak/agent/internal/fngateway"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/nettool"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/version"
)

func usage() {
	fmt.Fprintf(os.Stderr, "usage: falak-agent <run|enroll|install|check|fn-gateway|fn-run|features|version> [flags]\n")
	os.Exit(2)
}

func main() {
	if len(os.Args) < 2 {
		usage()
	}
	sub := os.Args[1]
	if sub == nettool.HelperCommand {
		// The agent's own helper (docs/plans/network/tools.md): no flags, one JSON spec, output on the PTY it runs in.
		ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM, syscall.SIGHUP)
		code := nettool.Main(ctx, os.Args[2:], os.Stdout)
		stop()
		os.Exit(code)
	}
	fs := flag.NewFlagSet(sub, flag.ExitOnError)
	cfg := config.Default()
	cfg.Bind(fs)
	noStart := fs.Bool("no-start", false, "install: enable but do not start the service")
	checkWait := fs.Duration("wait", 0, "check: keep retrying this long while the agent API cannot be reached")
	fnListen := fs.String("listen", fngateway.DefaultListen, "fn-gateway: proxy address (Caddy sends function traffic here)")
	fnAdmin := fs.String("admin", fngateway.DefaultAdmin, "fn-gateway: admin API unix socket")
	fnState := fs.String("state", fngateway.DefaultStateDir, "fn-gateway: functions directory (gateway.json, releases)")
	runSite := fs.String("site", "", "fn-run: function (site slug)")
	runSchedule := fs.String("schedule", "", "fn-run: schedule key")
	runName := fs.String("name", "", "fn-run: schedule name shown to the function")
	runCron := fs.String("cron", "", "fn-run: schedule expression shown to the function")
	runTimeout := fs.Int("timeout", 300, "fn-run: seconds before the run is stopped")
	logLevel := fs.String("log-level", envOr("FALAK_LOG_LEVEL", "info"), "debug|info|warn|error (env FALAK_LOG_LEVEL)")
	_ = fs.Parse(os.Args[2:])

	var lvl slog.Level
	_ = lvl.UnmarshalText([]byte(*logLevel))
	log := slog.New(slog.NewJSONHandler(os.Stderr, &slog.HandlerOptions{Level: lvl})).With("component", "falak-agent")
	slog.SetDefault(log)

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	var err error
	switch sub {
	case "run":
		err = agent.Run(ctx, cfg, log)
	case "enroll":
		err = agent.EnrollOnly(ctx, cfg, log, os.Stdout, runner.Exec{})
	case "install":
		self, _ := os.Executable()
		err = agent.Install(ctx, agent.InstallOptions{Config: cfg, Source: self, NoStart: *noStart, FS: hostfs.FS{Root: cfg.HostRoot}, Runner: runner.Exec{}, Out: os.Stdout})
	case "check":
		if checkErr := agent.Check(ctx, agent.CheckOptions{Config: cfg, Wait: *checkWait, Out: os.Stdout}); checkErr != nil {
			fmt.Fprintln(os.Stderr, "falak-agent check: "+checkErr.Error())
			os.Exit(1)
		}
	case "fn-gateway":
		err = fngateway.Run(ctx, fngateway.RunOptions{Listen: *fnListen, AdminSocket: *fnAdmin, StateDir: *fnState,
			DockerSocket: cfg.DockerSock, AgentOTLPSocket: cfg.OTLPSocket, Version: version.Version, Logger: log.With("component", "fn-gateway")})
	case "fn-run":
		// A function schedule's cron job: runs it once through the gateway; output and exit code are the run's.
		code, runErr := fngateway.NewClient(*fnAdmin).Run(ctx, *runSite, fngateway.RunRequest{Schedule: *runSchedule, Name: *runName, Cron: *runCron, Trigger: "cron", TimeoutS: *runTimeout}, os.Stdout)
		if runErr != nil {
			fmt.Fprintln(os.Stderr, "falak: "+runErr.Error())
			if errors.Is(runErr, fngateway.ErrRunTimeout) {
				os.Exit(fngateway.ExitTimeout) // the scheduler records a timeout
			}
			os.Exit(1)
		}
		os.Exit(code)
	case "features":
		// The installer asks before pointing at panel features the build supports (e.g. provision.v2: the machine check).
		for _, f := range version.Features {
			fmt.Println(f)
		}
	case "version", "--version", "-v":
		fmt.Println(version.Version)
	default:
		usage()
	}
	if err != nil {
		log.Error("fatal", "err", err)
		os.Exit(1)
	}
}

func envOr(k, d string) string {
	if v := os.Getenv(k); v != "" {
		return v
	}
	return d
}
