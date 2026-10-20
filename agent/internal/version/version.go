// Package version holds the build version, injected via -ldflags.
package version

import (
	"crypto/sha256"
	"encoding/hex"
	"io"
	"os"
	"sync"
)

// Version is overridden at build time: -X github.com/OthmanHaba/falak/agent/internal/version.Version=v1.2.3
var Version = "dev"

// Features are protocol additions this agent understands, reported in facts ("features"). Agents reject
// unknown payload fields, so the control plane only sends an optional field to agents that list it.
var Features = []string{
	// edge.caddy.apply sites[].access_log (per-site HTTP access logs, shipped as kind=access).
	"edge.access_log",
	// telemetry.configure log_sources[].kind and .multiline.
	"telemetry.log_kind",
	// system.upgrade_agent verifies sha256, swaps atomically and reports the running version.
	"system.upgrade_agent.v2",
	// fn.release.apply / fn.release.remove / fn.status (Cloud Functions + falak-fn-gateway), and
	// edge.caddy.apply sites[].request_headers.
	"fn.v1",
	// fn.release.apply access (gateway API keys / IP allowlist) and edge.caddy.apply sites[].mounts.
	"fn.v2",
	// fn.release.apply with file trees (several files, folders): validated as a tree before anything is written.
	"fn.v3",
	// docker.compose.pull / docker.compose.up assets (repository files a compose project mounts, under repo/).
	"compose.v2",
	// docker.run / deploy.container.swap networks: containers join existing networks (a compose service run as its
	// own site joins its stack's network).
	"docker.networks",
	// networks[].compose: a compose project's missing network is created with Compose's labels (a service split out at
	// a stack's creation deploys before the stack).
	"docker.networks.create",
	// docker.compose.up services: a stack's bootstrap pass starts only the services its split-out sites use.
	"compose.up.services",
	// provision.inspect (read-only machine check) and provision.apply components (adopted components are verified,
	// never installed).
	"provision.v2",
	// db.instance.* and db.* on database containers (falak-db images), heartbeat `databases`, provision.apply
	// docker.live_restore, docker.compose.up join_networks, networks[].environment.
	"db.instances",
	// terminal.open relay_token (the terminal relay: output and input over a WebSocket, docs/plans/CONSOLE.md) and
	// container (a console in a container: docker exec), and a missing cwd starts in the home directory.
	"terminal.v2",
	// Workload metrics (falak.workload.*, docs/plans/OBSERVABILITY.md phase 1) instead of falak.container.*, the host's
	// system.cpu.logical.count, and telemetry.configure sites[].unix_user / sites[].runtime (native sites measured by
	// their unix user's processes).
	"telemetry.workloads",
	// telemetry.configure sites[].isolated, and sites[].unix_user for non-isolated sites too: the shared user's
	// processes attributed per site (its PHP-FPM pool, else its working directory), falak.workload.kind=processes.
	"telemetry.workloads.shared",
	// telemetry.configure log_sources[].container / .compose_service (files inside a site's containers, read through
	// /proc/<pid>/root), .multiline_pattern and .origin (falak.log.origin: the Logs tab's source filter).
	"logs.container_files",
	// runtime.fpm.pool slowlog_timeout (the pool's slow log, /var/log/falak/php-fpm-slow/<pool>.log).
	"runtime.fpm.slowlog",
	// Database observability (docs/plans/OBSERVABILITY.md phase 3): db.instance.create|update instance.query_insights,
	// and the db.observed request (engine metrics, slow queries, top statements).
	"db.observability",
	// Network connections (docs/plans/network/connections.md): the net.connections.configure command (probes, peer
	// names), falak.net.* metrics and the net.observed request.
	"net.connections",
	// edge.caddy.apply request_id: the edge stamps X-Request-Id on every request (kept when well-formed, passed
	// upstream, echoed in the response and the access record), docs/plans/network/http.md.
	"edge.request_id",
	// net.tool (docs/plans/network/tools.md): ping, traceroute, DNS, HTTP and TCP checks from the host or inside a
	// container's network namespace (the agent re-run as `falak-agent nettool`, under nsenter).
	"net.tools",
	// Network protection (docs/plans/network/protection.md): net.protection.apply (block sets with timeouts in table
	// inet falak_protect, auto-ban settings), the net.protection.observed request (sshd auth failures, auto-bans,
	// counters) and net.firewall.apply counters.
	"net.protection",
	// edge.caddy.apply sites[].rate_limits and caddy_build: path rate limits at the edge (the rate_limit module of
	// Falak's Caddy build, installed on first use).
	"edge.rate_limit",
	// Packet capture (docs/plans/network/capture.md): the net.capture command (tcpdump on an interface or in a
	// container's network namespace), the net.capture.progress request and chunk uploads to /uploads/net.capture/<id>.
	"net.capture",
	// The eBPF probe (docs/plans/network/ebpf.md): the net.ebpf.configure command (opt-in per server), facts.ebpf, and
	// falak.net.tcp.*, falak.net.dns.*, falak.workload.runqueue.delay metrics.
	"net.ebpf",
	// net.observed workloads[].talks_to[].connects: the eBPF probe's outbound connects of the minute (a connection
	// per request shows up although it is rarely open at a snapshot).
	"net.ebpf.connects",
	// Sandboxes (docs/plans/SANDBOXES.md): sandbox.* commands (gVisor runsc driven directly), the sandbox.reaped
	// request, and terminal.open sandbox.
	"sandbox.v1",
	// Sandboxes v0.21: sandbox.snapshot, sandbox.snapshot.delete, sandbox.create from_snapshot (restore / fork),
	// sandbox.pause, sandbox.resume, sandbox.db.import, and sandbox.inventory's snapshots.
	"sandbox.v2",
	// Tailscale (docs/plans/TAILSCALE.md): net.tailscale.apply (pinned tailscale + tailscaled, joins the tailnet
	// advertising the server's own public IPs as host routes).
	"net.tailscale",
	// edge.caddy.apply caddy_build with DNS-01 certificates: Falak's Caddy build (caddy-dns/cloudflare) is installed for
	// them too, and an edge that cannot get it obtains them over HTTP instead of refusing every route.
	"edge.caddy_dns",
}

var (
	binaryOnce sync.Once
	binarySum  string
)

// BinarySHA256 is the SHA-256 of the running executable (computed once; "" when unreadable), reported in facts
// so the control plane can tell whether a server runs the agent build it ships. It hashes the running image
// (/proc/self/exe keeps pointing at it after system.upgrade_agent renamed a new build over the path), never the
// file now at the path: a process that swapped its binary still reports its own build until it restarts.
func BinarySHA256() string {
	binaryOnce.Do(func() {
		f, err := openRunningExecutable()
		if err != nil {
			return
		}
		defer f.Close()
		h := sha256.New()
		if _, err := io.Copy(h, f); err == nil {
			binarySum = hex.EncodeToString(h.Sum(nil))
		}
	})
	return binarySum
}

// openRunningExecutable opens the executable image of this process.
func openRunningExecutable() (*os.File, error) {
	if f, err := os.Open("/proc/self/exe"); err == nil {
		return f, nil
	}
	exe, err := os.Executable()
	if err != nil {
		return nil, err
	}
	return os.Open(exe)
}
