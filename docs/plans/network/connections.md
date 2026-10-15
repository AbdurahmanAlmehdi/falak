# Phase 4 · A. Connections (v0.17.x)

Part of [OBSERVABILITY.md phase 4](../OBSERVABILITY.md#phase-4-network-v017x) (the index and its shared rules apply).
What a server, a site, a compose service or a database container is connected to, right now and over the last days:
TCP connections by state and direction, listening sockets with their owner, "talks to" peers with counts and bytes,
long-lived (WebSocket) connections, the conntrack table, an exposed-ports audit, latency to private-network peers, and
WireGuard peer health (app-to-database traffic shows as peers, never as probes: see "Latency probes"). Read-only: nothing here changes traffic.

```
/proc/<pid>/net/{tcp,tcp6,udp,udp6} (host + every container namespace) ─┐
/proc/<pid>/fd (native sites' processes, listeners' owners)               ├─► agent netobs.Collector
conntrack (netlink dump, /proc/sys/net/netfilter/nf_conntrack_*)          │     every telemetry interval: states,
wg show (latest-handshakes, transfer, allowed-ips)                        │       listeners, long-lived, conntrack usage
TCP connect probes (targets from net.connections.configure)              ─┘     every minute: peers, bytes, WireGuard,
                                                                                  probes, the report
   ├─► OTLP metrics falak.net.*                 → VictoriaMetrics (30 d)
   └─► POST /agent/v1/requests/net.observed     → Network: latest report per server (tables), exposed-ports
                                                   audit → security Issues (Insights), alerts (Alerting),
                                                   reverse DNS of external peers (cached)
```

## Agent feature and protocol

Feature **`net.connections`** (`agent/internal/version.Features`):

- the collector itself (no payload: it starts with the agent and follows telemetry.configure `metrics.enabled` /
  `interval_s`, like the workload collector);
- the command **`net.connections.configure`** (whole command gated: the control plane only queues it for agents that
  list the feature; nothing is added to an existing command, so `PayloadCompatibility` has nothing to strip);
- the request **`net.observed`** (agent → control plane; an old control plane answers 404 `unknown_request`: logged
  once, the metrics keep flowing).

### `net.connections.configure`

What only the control plane knows: whom to probe and what to call the addresses the agent sees. Persisted in
`/etc/falak/net.json` (0600), so a restarted agent keeps probing. Idempotent; sent by the Network module when a
private network changes (`PrivateNetworkChanged`: every server of the organization), when an agent reports a new version
(`AgentVersionChanged`, forced) and at most hourly from the agent's own reports (`NetworkObserved`, which picks up new
databases); it is sent only when its hash differs from the one the agent last applied (`config_hash`, set when the
command succeeds) and from the one in flight (`config_pending_hash`, cleared when it fails, so it is sent again).

| Field | Content | Limit |
|---|---|---|
| `probes[]` | `{name, kind: server, host, port}`: WireGuard peers, on their private address and a port nothing listens on (`network.connections.probe_port`, 1): the private network's interface accepts every port, so the peer's kernel answers with a RST (the round trip) and no service ever logs a connection (sshd, or stream C's brute-force detection, would). Database ports are never probed: MySQL and MariaDB count a connection closed before its handshake in `Aborted_connects` (phase 3 would report authentication failures) and may block the host (`max_connect_errors`) | 64 |
| `names[]` | `{address, port (0 = any), name}`: `server:<id>` for every address of the servers sharing a private network with this one (public and private), `db:<id>` for every database instance's published address and port, and `127.0.0.1:<host_port>` for the instances on this server (the agent applies loopback names in the host's namespace only: a container's loopback is its own) | 512 |
| `wireguard[]` | `{public_key, name}`: the peers' keys → `server:<id>` | 128 |
| `long_lived_after_s` | an inbound connection seen for this long counts as long-lived (default 60) | 15..3600 |

`name` values are the labels the agent puts on metrics (`falak.net.peer`, `falak.net.probe.target`,
`falak.net.wireguard.peer`): ids, not display names, so a rename never splits a series; the UI resolves them.

## What the collector reads

### Namespaces

| Namespace | Sockets of | How |
|---|---|---|
| Host | the host's processes, native sites, edge, docker-proxy | `/proc/1/net/*` (through `hostfs`: the host's init, also when the agent runs re-rooted) |
| Each site / database container | that container (every compose service) | `/proc/<init pid>/net/*` |

The containers and their init pids come from the **workload collector's last pass** (`WorkloadCollector.NetSnapshot`):
it already lists containers once per interval and inspects each once (pid, start time, labels, and now the
container's IPs from the same inspect). The network collector makes **no Engine API call at all**. Namespaces are
deduplicated by `/proc/<pid>/ns/net` (a compose service with `network_mode: service:x`, a container on the host
network: counted once, the host's sockets stay the host's).

### Socket tables

`/proc/<pid>/net/tcp`, `tcp6`, `udp`, `udp6`: hex addresses (IPv4 as one little-endian word, IPv6 as four; v4-mapped
IPv6 addresses are unmapped), state, uid, inode. The tables are streamed line by line and at most 20,000 sockets are
read per namespace and pass; the rest of the table is never read (the report says `truncated`). At most 200 workloads
per report: native sites past the cap count for the host, containers past it are left out (`truncated`).

- **States** (TCP): `established`, `syn_sent`, `syn_recv`, `fin_wait` (1 and 2), `time_wait`, `close_wait`,
  `last_ack`, `closing`. Listening sockets are counted apart; `close` is not a connection.
- **Direction:** a socket whose local port has a listener in the same namespace (wildcard or the same address) is
  inbound, any other is outbound.
- **Listeners:** TCP in `LISTEN`, UDP sockets bound and unconnected. Each with protocol, bind address, port,
  `public` (bound to a wildcard or a global address: `security.Public`, the audit's rule: loopback, RFC 1918, ULA,
  CGNAT and link-local are not) and its owner (below).
- **UDP** is listed (listeners) but not counted as connections: it has no state.

### Owners (native sites, listeners)

A container's sockets are the container's. In the host namespace:

- **Native sites.** The workload collector already attributes processes to native sites (slice `cgroup.procs`, the
  isolated user's processes, the shared user's processes by PHP-FPM pool or working directory). Only those pids'
  `/proc/<pid>/fd` are read (`socket:[inode]` links), so a socket of the host table maps to the site by inode. At most
  4,096 fds per process and 50,000 per pass. A socket in `TIME_WAIT` has no inode any more: it counts for the host.
- **Listeners** whose inode is not a native site's: their owner is found by one scan of every `/proc/*/fd`, only when
  a listener's inode is unknown, at most every 5 minutes, at most 200,000 links; the answer (pid, start time, `comm`)
  is cached per inode while the listener exists. `docker-proxy` owners are resolved to the container they publish
  (`-container-ip` of its command line → the container whose IP it is).

### Long-lived connections (WebSockets)

`/proc/net/tcp` has no connection age. The collector remembers when it first saw each inbound established connection
(by namespace and 4-tuple; at most 50,000 per namespace) and counts as long-lived those seen for at least
`long_lived_after_s` (60 s). The age is therefore known to one interval (15 s), and connections already open when the
agent starts count from then. Server-sent events and long-polling count too (anything held open is long-lived).

### Peers ("talks to"), once a minute

Per workload, established connections are grouped by remote endpoint: outbound by `address:port`, inbound by address
(the client's port is ephemeral). The 20 largest of each direction (by connections, then bytes) are kept, the rest are
folded into `other`. Names, in order:

1. the control plane's `names` (`server:<id>`, `db:<id>`; exact address and port, else address with port 0);
2. a container on this server, by its IP: `db:<instance id>`, `site:<slug>` (`site:<slug>/<service>` for compose);
3. the host's own addresses (loopback, its interfaces' addresses seen in the host table): the listener's owner on
   that port (`site:<slug>`, `db:<id>`, or `host:<comm>`, e.g. `host:caddy`);
4. otherwise unnamed: the address stays in the report (control-plane table only), the metric label is the bucket
   `external:<port>` / `private:<port>` (outbound) or `external` / `private` (inbound).

No raw IP is ever a metric label.

Short-lived connections (a database connection per PHP request) are rarely open at a snapshot. While the eBPF probe
runs on the server ([ebpf.md](ebpf.md)), its successful outbound connects of the minute, per workload and destination
(at most 4,096 destinations between two reports, from the existing `peers` map: no extra kernel work), join the same
grouping as `connects` (a peer with 0 open connections and connects is kept); ranking uses connections + connects.
The server and site Network tabs show them as "Opened / min" and, while the probe is off, a note under
Talks to that such connections may not appear (`ebpf` in the connections JSON).

### Bytes per peer (conntrack)

Sockets carry no byte counters; conntrack does, when **accounting** is on (`/proc/sys/net/netfilter/nf_conntrack_acct`,
off by default on most kernels). The agent never changes it. When it is off the peers have connection counts only;
the report says `accounting: off` and the server's Network tab explains how to turn it on (`sysctl -w
net.netfilter.nf_conntrack_acct=1`, persisted in `/etc/sysctl.d/`; only connections opened after that have counters).

With accounting on, once a minute the agent dumps the table over netlink (`NETLINK_NETFILTER`, `IPCTNL_MSG_CT_GET`
with `NLM_F_DUMP`, AF_INET and AF_INET6; at most 65,536 flows: at the cap it stops reading and closes the socket) and
matches each socket's 4-tuple to a flow: the
original tuple (outbound, or inbound without NAT), the original tuple reversed, or the reply tuple (inbound through a
DNAT: a published container port). Per flow it keeps the last counters and adds the difference to the peer's counter,
so the series is monotonic; a flow already open on the agent's first dump is a baseline (only what it carries after
counts), like the per-user CPU counters. A capped dump sees a different subset of flows each minute: there a flow seen
for the first time is a baseline too (it may have been open, unseen, for long), and a flow missing from a dump keeps
its last counters for 3 dumps, so one that drops out and comes back never counts its whole lifetime again. A flow that
closes between two dumps loses its last minute of bytes: an
under-count, documented in the UI ("bytes of connections that last less than a minute are partly missed"). Container
traffic is seen in the host's table (the bridge and NAT run there).

### Conntrack usage

`nf_conntrack_count` and `nf_conntrack_max` every interval (missing when the module is not loaded).

### Latency probes and WireGuard

- **Probes**, once a minute: a TCP connect (2 s timeout, 16 at a time: ≤ 8 s for 64 targets) to each `probes[]`
  target, on a closed port: the RST measures the round trip (`refused: true`, `ok`); a timeout or an unreachable host
  is a failure. Probes and WireGuard run alongside the socket pass, not after it. ICMP is not used (raw sockets, and the firewall decides; the
  network tools of stream E have `ping`).
- **WireGuard**, once a minute when `/etc/wireguard/*.conf` exists: `wg show all latest-handshakes`, `transfer` and
  `allowed-ips` through the agent's runner (never `dump`, which prints the private key). Peers are named by
  `wireguard[]`, else by the first 8 characters of their key.

## Metrics (agent → OTLP → VictoriaMetrics)

Resources as the workload metrics: `service.name` = the site slug (enriched to `falak.site.id`), `falak-db` with
`falak.db.instance.id`, or `falak-host` for the host namespace. Data points carry `falak.workload.kind` (`container`
| `processes` | `host`) and `falak.workload.name` (+ `container.id`, `falak.compose.service`). Scope
`falak-agent/network`.

| Metric (OTLP) | Type | Unit | Attributes | Prometheus name | Cadence |
|---|---|---|---|---|---|
| `falak.net.connections` | gauge | {connection} | `falak.net.state`, `falak.net.direction` | `falak_net_connections` | interval |
| `falak.net.connections.long_lived` | gauge | {connection} | | `falak_net_connections_long_lived` | interval |
| `falak.net.listeners` | gauge | {socket} | `network.transport`, `falak.net.exposure` (`public` \| `private` \| `loopback`) | `falak_net_listeners` | interval |
| `falak.net.peer.connections` | gauge | {connection} | `falak.net.peer`, `falak.net.direction` | `falak_net_peer_connections` | minute |
| `falak.net.peer.io` | counter | By | `falak.net.peer`, `falak.net.direction`, `network.io.direction` | `falak_net_peer_io_bytes_total` | minute, accounting on |
| `falak.net.conntrack.entries` | gauge | {entry} | | `falak_net_conntrack_entries` | interval (host) |
| `falak.net.conntrack.limit` | gauge | {entry} | | `falak_net_conntrack_limit` | interval (host) |
| `falak.net.probe.connect_time` | gauge | s | `falak.net.probe.target`, `falak.net.probe.kind` | `falak_net_probe_connect_time_seconds` | minute (host) |
| `falak.net.probe.up` | gauge | (none) | same | `falak_net_probe_up` | minute (host) |
| `falak.net.wireguard.handshake.age` | gauge | s | `falak.net.wireguard.peer`, `network.interface.name` | `falak_net_wireguard_handshake_age_seconds` | minute (host) |
| `falak.net.wireguard.io` | counter | By | same + `network.io.direction` | `falak_net_wireguard_io_bytes_total` | minute (host) |

`falak.net.probe.up` has no unit on purpose: unit `1` would become `falak_net_probe_up_ratio`. Cardinality per
workload: ≤ 8 states × 2 directions, ≤ 2 × 21 peers; per server ≤ 64 probe targets and 128 WireGuard peers.

Approximations, as the UI says them: long-lived connections are counted from when the agent first saw them (±15 s,
from agent start); bytes per peer miss the last minute of connections that close between two dumps; a native site's
`TIME_WAIT` sockets (no inode any more) count for the host.

## `net.observed` (agent → control plane)

One request per server a minute (`contracts/agent-protocol/requests/net.observed.schema.json`): `at`, `window_s`,
`conntrack {entries, limit, accounting: on|off|unavailable}`, `workloads[]` (≤ 200: `kind`, `name`, `site`,
`compose_service`, `db_instance`, `container_id`, `connections {inbound, outbound, long_lived, states}`,
`listeners[]` ≤ 64 with `proto`, `address`, `port`, `public`, `process`, `container`, and `peers[]` ≤ 42 with
`direction`, `address`, `port`, `name`, `connections`, `rx_bytes`, `tx_bytes` (deltas of the minute, only with
accounting), plus an `other` peer), `wireguard[]` (`interface`, `public_key`, `name`, `allowed_ips`,
`handshake_age_s` (null: never), `rx_bytes`, `tx_bytes`), `probes[]` (`name`, `kind`, `host`, `port`, `ok`,
`refused`, `connect_ms`, `error`), `truncated`.

The control plane (Network module, `NetworkObserved` → `RecordNetworkObservation`):

- keeps the latest report per server (`network_observations`: the report, `observed_at`, the probes' latency baselines,
  the configuration's hashes) for the tables;
- runs the exposed-ports audit (below);
- raises and clears alerts (below);
- queues reverse DNS for unnamed public peers (≤ 20 per report, `network_peer_names`, kept 24 h; never on the agent,
  never per sample). Placeholder rows (name null) are written before the job is queued, so later reports do not queue
  the same addresses; one job per server at a time (`ShouldBeUnique`). Lookups are one UDP PTR query each to the
  system's nameserver with a 1 s timeout (`PtrResolver`: `gethostbyaddr` has none).

## Exposed-ports audit → security Issues

A **public** listener of the host namespace (the host's own sockets and the native sites', `kind` `host` and
`processes`) is **exposed** when it is reachable from anywhere and Falak does not
expose it on purpose:

- reachable: the firewall is not applied (no ruleset, or its last apply failed), or the first firewall rule that
  matches the port without an interface or sources accepts it (deny rules come first; no match = the default drop),
  or the listener is a container's published port (`docker-proxy`: Docker's DNAT runs before the input chain);
- on purpose: TCP 80 / 443 and UDP 443 (the edge), the SSH port (`network.ssh_port` and the server's own), the
  WireGuard ports of its private networks, and a database instance's published port with a public-access allowlist.

Each exposed port is an Insights Issue of the new kind **`security`**, through the additive contract
`Insights\Contracts\SecurityIssues` (like `DatabaseIssues`): fingerprint `sha256(server | source | proto/port)`,
source `network.exposed_port`, title "PostgreSQL 5432 reachable from the internet" (well-known ports named:
PostgreSQL, MySQL, Redis, MongoDB, Memcached, Elasticsearch, Docker API, …; else "Port tcp/8080"), culprit the owner
(`postgres`, `container falak-db-…`), `server_id`, `meta {source, proto, port, address, owner}`. An Issue is
recorded once per report while the port stays exposed and resolved automatically (`SecurityIssues::resolveExcept`)
when it is no longer (closed, rebound to a private address, or the firewall now drops it). Listeners are sent public
first and capped at 64 per workload; a cut list (`listeners_truncated`) resolves nothing. Stream B (scanners) and C
(brute force) use the same contract with their own `source`.

Security's daily audit (`security.unexpected_port`) keeps flagging listeners the firewall does *not* allow (hygiene);
this audit is about what *is* reachable, continuously.

## Alerts (Alerting, area Network, joins the default rule pack)

| Type | Condition | Severity |
|---|---|---|
| `network.conntrack_full` | entries ≥ `conntrack_percent` (85) % of the limit, held 5 minutes | Warning |
| `network.exposed_port` | a port became exposed (per server and port; recovers when it is no longer) | Warning |
| `network.wireguard_stale` | a peer's latest handshake older than `handshake_stale_seconds` (300) or never, held 5 minutes | Warning |
| `network.latency_spike` | a probe failing (timeout, unreachable), or its connect time ≥ max(3 × its baseline, baseline + 50 ms), held 5 minutes | Warning |

The baseline is an exponentially weighted mean of the probe's connect time (α 0.05 per report, ~20 min), not updated
while the condition holds.

Config (`network.connections`): `conntrack_percent` 85, `handshake_stale_seconds` 300, `latency_factor` 3,
`latency_min_ms` 50, `alert_seconds` 300, `stale_seconds` 300 (an older report is shown as stale), `rdns_ttl_hours` 24,
`rdns_per_report` 20, `long_lived_after_s` 60 (sent in the configuration).

Conditions of things that disappear (a peer removed, a probe target gone, a port no longer exposed) are cleared with
`AlertConditions::clearExcept`, which resolves the raised ones.

## UI

- **Server → Network tab** (`ServerNetwork.tsx`, below the private networks; `GET
  /network/servers/{server}/connections?range=`, `network.view`): connections by state and direction, long-lived
  connections, conntrack usage against its limit (accounting off: the callout with the sysctl), probe latency per
  target and WireGuard handshake age; tables from the latest report: **listeners** (protocol, address, port, owning
  workload, `exposed` / `public` badges, the Issue's link), **talks to** (every workload's outbound peers, names
  resolved, bytes when known), **WireGuard peers** (server, endpoint addresses, handshake age, rx / tx, latency).
- **Site → Network tab** (service panel, order 350, `telemetry.view`; `GET /network/sites/{site}/connections?range=
  &server=&service=`): the same charts for the site's containers (or its native processes), per compose service with a
  service picker, its listeners, talks-to and inbound clients.
- **Database → Network tab** (order 450, `databases.view`; `GET /network/databases/{instance}/connections`, the
  instance's id or the id of a database on it, as the canvas service carries): the instance container's connected
  clients (inbound peers, named: `site:…`, `server:…`) and connection counts.

Names: `server:<id>` → the server's name and link, `db:<id>` → the instance, `site:<slug>` → the site, external
addresses → their reverse DNS name when known. Every row with a remote IP renders the **`network.ip-actions`** slot
(`registerIpActions` / `ipActionsFor` in `resources/js/lib/registry.ts`): empty here; stream C registers "Block IP"
there (its own `IpBlocker` contract). The slot receives `{ip, serverId, siteId?, context: talks_to | clients}`; folded
("other") rows, Falak peers (`server:`, `db:`, `site:`) and loopback, private (RFC 1918, ULA), CGNAT and link-local
addresses get no actions, and the column is hidden while nothing is registered.

Insights: kind `security` (label Security, filter in the Issues list); `SecurityIssues::record`, `resolveExcept` and
`open` (by key) are shared with streams B and C, each with its own `source`.

`reporting` tells the UI why a table is empty: `agent_outdated` (no `net.connections`), `waiting` (no report yet),
`stale` (older than `stale_seconds`), `ok`.

## Performance budget

Per interval (15 s): one read of four socket tables per namespace (a few KB each on a small server), the native
sites' fds (tens of processes), two sysctl files. Per minute: one conntrack dump (only with accounting on), three `wg`
execs (only with WireGuard), ≤ 64 connects (≤ 8 s, concurrent with the rest). No Engine API call; no full `/proc/*/fd`
scan except for an unknown listener, at most every 5 minutes. Memory: per namespace ≤ 50,000 remembered connections
(long-lived, ~150 bytes each); a capped conntrack dump holds ≤ 65,536 flows (~120 bytes each) and its tuple index
(three keys per flow) for the minute's pass, ~30 MB at the cap, then the per-flow counters (~100 bytes each) stay. Within the agent budget (< 1 % of one CPU at 15 s) on servers with < 10k connections; the caps
keep a busy server bounded (counted as `truncated`).

## Security

- Read-only: the agent never changes `nf_conntrack_acct`, firewall rules or sockets.
- The report carries client and peer IPs: kept in the control plane only (latest report per server, overwritten each
  minute), never metric labels or logs.
- `wg show dump` is never run (it prints the private key).
- Peer names from the control plane are labels only; probes connect to control-plane-chosen targets only, with a
  timeout, never sending data.
- Every endpoint authorizes the organization and the permission (`network.view`, `telemetry.view`,
  `databases.view`); other organizations' servers, sites and instances are 404.

## Not in this stream

Per-process latency, retransmits and DNS (eBPF, phase 5); blocking (stream C fills `network.ip-actions`); HTTP
clients and bandwidth (stream B); ICMP.

## Tests

- Go: table tests on fake `/proc` trees: tcp / tcp6 / udp parsing (IPv4, IPv6, v4-mapped, states), listeners and
  direction, inode → pid for native sites, long-lived ageing, top-N folding and naming, conntrack netlink parsing
  (crafted messages), flow ↔ socket matching and monotonic byte counters, conntrack usage, `wg show` parsing, probes
  against a local listener, the configure command, the report's schema.
- PHP: ingest (schema, other servers and organizations ignored), the exposed-ports audit (firewall rules, docker-proxy,
  intended ports, resolution), alerts (conntrack, WireGuard, latency baseline), endpoints and their authorization, the
  configure payload and its feature gate.
- Vitest: name resolution, formatting and top-N helpers of the panels.
