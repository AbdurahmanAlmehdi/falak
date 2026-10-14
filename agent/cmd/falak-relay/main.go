// Command falak-relay is the terminal relay (docs/plans/CONSOLE.md): it pipes terminal sessions' bytes between
// the agents running them and the browsers or CLIs attached to them, over WebSockets. It runs from the
// control-plane image (compose service `relay`) and checks the tokens the control plane mints with a key derived
// from the same APP_KEY. POST /internal/v1/sessions/{sid}/kick (aud "control" tokens) is for the control plane on
// the compose network; the edge never routes /internal/*.
//
//	APP_KEY             required (the control plane's)
//	FALAK_RELAY_LISTEN  listen address (default :8080)
//	FALAK_RELAY_ORIGINS optional comma-separated browser origin patterns (default: any; clients carry a ticket)
//	FALAK_LOG_LEVEL     debug|info|warn|error (default info)
package main

import (
	"context"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"strings"
	"syscall"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/relay"
	"github.com/OthmanHaba/falak/agent/internal/version"
)

func main() { os.Exit(run(os.Args[1:], os.Getenv, os.Stdout, os.Stderr)) }

func run(args []string, getenv func(string) string, stdout, stderr io.Writer) int {
	if len(args) > 0 {
		switch args[0] {
		case "version", "--version", "-v":
			fmt.Fprintln(stdout, version.Version)
			return 0
		default:
			fmt.Fprintln(stderr, "usage: falak-relay [version]   (configured by APP_KEY, FALAK_RELAY_LISTEN, FALAK_RELAY_ORIGINS)")
			return 2
		}
	}
	var lvl slog.Level
	_ = lvl.UnmarshalText([]byte(envOr(getenv, "FALAK_LOG_LEVEL", "info")))
	log := slog.New(slog.NewJSONHandler(stderr, &slog.HandlerOptions{Level: lvl})).With("component", "falak-relay")
	key, err := relay.DeriveKey(getenv("APP_KEY"))
	if err != nil {
		log.Error("cannot derive the relay key", "err", err)
		return 1
	}
	var origins []string
	for _, o := range strings.Split(getenv("FALAK_RELAY_ORIGINS"), ",") {
		if o = strings.TrimSpace(o); o != "" {
			origins = append(origins, o)
		}
	}
	rs := relay.New(relay.Options{Key: key, Logger: log, OriginPatterns: origins})
	addr := envOr(getenv, "FALAK_RELAY_LISTEN", ":8080")
	srv := &http.Server{Addr: addr, Handler: rs.Handler(), ReadHeaderTimeout: 10 * time.Second}

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()
	go rs.Run(ctx)
	errc := make(chan error, 1)
	go func() { errc <- srv.ListenAndServe() }()
	log.Info("falak-relay listening", "addr", addr, "version", version.Version)
	select {
	case err := <-errc:
		log.Error("listen", "err", err)
		return 1
	case <-ctx.Done():
	}
	log.Info("shutting down")
	// Shutdown stops accepting; WebSockets are hijacked, so the relay closes them itself (agents redial, clients
	// reconnect to the next relay).
	sctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	if err := srv.Shutdown(sctx); err != nil && !errors.Is(err, context.DeadlineExceeded) {
		log.Warn("shutdown", "err", err)
	}
	rs.Close()
	return 0
}

func envOr(getenv func(string) string, k, def string) string {
	if v := getenv(k); v != "" {
		return v
	}
	return def
}
