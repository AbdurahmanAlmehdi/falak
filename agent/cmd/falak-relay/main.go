// Command falak-relay is the terminal relay (docs/plans/CONSOLE.md): it pipes terminal sessions' bytes between
// the agents running them and the browsers or CLIs attached to them, over WebSockets. It runs from the
// control-plane image (compose service `relay`) and checks the tokens the control plane mints with a key derived
// from the same APP_KEY. POST /internal/v1/sessions/{sid}/kick (aud "control" tokens) is for the control plane on
// the compose network; the edge never routes /internal/*.
//
//	APP_KEY             required (the control plane's)
//	FALAK_URL           the panel's public URL; its origin is the default allowed WebSocket origin
//	FALAK_RELAY_LISTEN  listen address (default :8080)
//	FALAK_RELAY_ORIGINS comma-separated browser origin patterns; defaults to FALAK_URL's origin
//	FALAK_LOG_LEVEL     debug|info|warn|error (default info)
package main

import (
	"context"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"net/url"
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
			fmt.Fprintln(stderr, "usage: falak-relay [version]   (configured by APP_KEY, FALAK_URL, FALAK_RELAY_LISTEN, FALAK_RELAY_ORIGINS)")
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
	origins := parseOrigins(getenv("FALAK_RELAY_ORIGINS"))
	// When no explicit origins are configured, default to the panel's own origin so cross-origin
	// browsers are blocked. FALAK_RELAY_ORIGINS can be set to "*" to restore the old open behaviour,
	// but that is not recommended for managed installs.
	if len(origins) == 0 {
		if o := panelOrigin(getenv("FALAK_URL")); o != "" {
			origins = []string{o}
			log.Info("WebSocket origin restricted to panel host", "origin", o)
		} else {
			log.Warn("FALAK_URL not set; WebSocket origin check is disabled — set FALAK_URL or FALAK_RELAY_ORIGINS")
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

// parseOrigins splits a comma-separated FALAK_RELAY_ORIGINS value.
func parseOrigins(s string) []string {
	var out []string
	for _, o := range strings.Split(s, ",") {
		if o = strings.TrimSpace(o); o != "" {
			out = append(out, o)
		}
	}
	return out
}

// panelOrigin extracts the scheme+host origin from the panel's URL (e.g. "https://cloud.falak.sh").
// Returns "" when the URL is empty or unparseable.
func panelOrigin(panelURL string) string {
	panelURL = strings.TrimSpace(panelURL)
	if panelURL == "" {
		return ""
	}
	u, err := url.Parse(panelURL)
	if err != nil || u.Host == "" {
		return ""
	}
	return u.Scheme + "://" + u.Host
}

func envOr(getenv func(string) string, k, def string) string {
	if v := getenv(k); v != "" {
		return v
	}
	return def
}
