# Observability per workload (plan, v0.14.0 →)

Today the agent ships host metrics, container stdout, edge access logs and app logs over OTLP into
VictoriaMetrics / Loki, and Insights turns APM spans into Issues. What is missing is everything **per workload**:

- a site's Metrics tab shows the CPU and memory of the *whole server*;
- container numbers (four `docker stats` gauges) reach no screen, and the Grafana container dashboard queries names
  the agent never emits;
- managed databases report one number (connections used / max);
- no file inside a container can be tailed;
- the Network tab only configures (firewall, WireGuard); no traffic is shown.

This plan adds per-workload metrics, logs, database insights and traffic, in five releases. Everything lands in the
existing stack: metrics → VictoriaMetrics (30 days), logs → Loki (15 days), issues and recommendations → the Insights
tables (30 days), alerts → Alerting events. No new service runs.

| Phase | Release | Content |
|---|---|---|
| 1 | v0.14.0 | Workload metrics: cgroup v2 + per-user `/proc` collector; Docker and native Metrics tabs against the server, with the enforced limits; requests / 5xx / p95 from access logs for every site; container dashboard fixed |
| 2 | v0.15.0 | Logs: files inside containers, PHP-FPM error / slow logs, journald of a site's units, Laravel log exceptions → Issues |
| 3 | v0.16.0 | Databases: Metrics tab, slow-query and engine logs, top queries, database Issues and recommendations, alerts |
| 4 | v0.17.0 | Network: HTTP traffic from access logs, TCP/UDP connections per workload, "talks to" peers |
| 5 | later | eBPF (per-process latency, DNS, retransmits), on-demand packet capture |

---

## Phase 1: workload metrics (v0.14.0)

### What a workload is

| Workload | How the agent finds it | Limits it reads |
|---|---|---|
| Site container (`falak.site` label, every compose service) | Engine API list, then the container's cgroup directory | `cpu.max`, `memory.max`, `pids.max` of that cgroup |
| Database container (`falak.db.instance` label) | Same | Same |
| Native site with limits (`falak-site_<slug>.slice`) | `/sys/fs/cgroup/falak.slice/falak-site_<slug>.slice`: its own PHP-FPM master, web process, Octane, Horizon | The slice's `cpu.max`, `memory.max`, `pids.max` |
| Native isolated site without limits | Every process owned by the site's unix user (`/proc/<pid>/status` Uid): its pool in the shared PHP-FPM master, its programs, schedules, consoles | None (the chart shows the server's capacity instead) |
| Native site sharing a unix user, without limits | The shared user's processes attributed to the site: its PHP-FPM pool workers by title (`php-fpm: pool falak-<slug>` in `/proc/<pid>/cmdline`), else its processes by working directory (`/proc/<pid>/cwd` under `<sites root>/<slug>/`): Horizon, workers, daemons, schedules, consoles | None |
| Worker / daemon slice (`falak-worker_<id>.slice`, `falak-daemon_<id>.slice`) | Their slice directories | The slice's |

Native sites are **not** moved into a slice to be measured: that would restart the PHP-FPM master of every unlimited
site. The per-user sum covers the same processes. When a site has a slice, the slice wins (exact, and it carries the
limits), unless the slice has no process yet (`pids.current` 0: the site still runs outside it), then its user does.

Only an isolated site has a unix user of its own: every non-isolated site runs as the shared `falak` user, which is
also the servers' login user (admin SSH sessions, tmux, the deploy hooks of Docker sites run as it). The control plane
sends `unix_user` for every native site (not for Docker, compose and function sites) and `isolated: true` for an
isolated one. Only an isolated site whose user no other site claims is measured whole (`falak.workload.kind` `user`);
every other user, including the user of a non-isolated site that is the only site on its server, is attributed
process by process (`processes`, `falak.workload.name` = the slug). Only that user's processes are read further:
argv[0] of `cmdline` names the PHP-FPM pool (`runtime.FPMPoolName` of the slug, as in the pool file; the shared master
runs as root and is never counted), else `cwd` is matched against the agent's sites root by whole path segment (`shop`
does not take `shop2`'s processes; a removed release's `(deleted)` suffix is ignored), both as configured and as its
symlinks resolve on the host (a `/srv` linked to `/data/srv`; resolved once, retried each pass until it succeeds).
The answer is cached per pid and start time, except for a process titled `php-fpm: …` but not with a pool (a worker
the master just forked, before it titles itself), which is read again next pass. A process of the shared user that
matches neither (SSH sessions, `systemd --user`) counts for no site. The counters work as the per-user ones (below),
one per site. A site with a slice is measured by it, and its processes count for no other site.

In both modes a process running in a container (its `/proc/<pid>/cgroup` holds `/docker/`, `docker-`, `containerd`,
`kubepods` or `libpod`) is skipped whatever its uid: a container's processes may run as a site user's uid. That check
is cached with the attribution.

Per-process attribution needs the agent feature `telemetry.workloads.shared`. Agents without it (v0.14.0) measure a
site with a `unix_user` by its whole user, so Fleet's `PayloadCompatibility::UNLESS` sends them `unix_user` only where
`isolated` is true (and strips `isolated`): exactly what they got before. Until a shared-user site's series arrive,
its Metrics tab shows the server's usage and says so (`shared_user` in the response); when one of its servers' agents
lacks the feature (`agent_outdated`), it asks for that agent's update instead.

### Reading cgroup v2

All values come from the cgroup directory, never `docker stats` (one file read per value instead of an Engine API
call that blocks ~1 s per container on some Docker versions):

| File | Values |
|---|---|
| `cpu.stat` | `usage_usec` (CPU time), `nr_periods`, `nr_throttled`, `throttled_usec` |
| `cpu.max` | `<quota> <period>` → limit in cores; `max` = none |
| `memory.current`, `memory.max` | usage, limit (`max` = none) |
| `memory.stat` | `anon`, `file` (page cache is shown apart: "used" is `anon` + kernel, cache is reclaimable) |
| `memory.events` | `oom_kill` |
| `io.stat` | `rbytes`, `wbytes`, `rios`, `wios` summed over devices |
| `pids.current`, `pids.max` | processes, limit |

The container's cgroup directory is found once per container id and cached:
`/sys/fs/cgroup/system.slice/docker-<id>.scope` (systemd driver), else `/sys/fs/cgroup/docker/<id>` (cgroupfs
driver), or under a custom cgroup parent (a systemd slice name `a-b.slice` expanded to `a.slice/a-b.slice`). Network bytes come from `/proc/<pid>/net/dev` of the container's init pid (the container's own network
namespace, `lo` excluded); the pid comes from the inspect call made when the container is first seen, kept with its start time
(`/proc/<pid>/stat`) so a pid reused after an in-place restart is told apart and the container inspected again. Hosts on cgroup
v1 (none supported by the installer, but enrolled machines may be) keep the Docker stats path for CPU, memory and
network only.

Per-user reads for unlimited native sites: `/proc/<pid>/stat` (utime + stime, in USER_HZ), `/proc/<pid>/status`
(`Uid`, `RssAnon` — not `VmRSS`, which counts shared pages such as OPcache's shared memory once per worker),
`/proc/<pid>/io` (`read_bytes`, `write_bytes`), one pass over `/proc` per interval for all sites. Processes that
exited between two samples make the CPU counter drop; the counter is kept per site as a monotonic sum (a drop is folded
into a running offset), so `rate()` stays right. A counter starts at zero when it is created (agent start, a site
entering the per-user pass): processes already running count only what they use from then on, processes born later
count fully, so the series never jumps by a process's whole lifetime. A failed read of `/etc/passwd` keeps the last
user → uid map (and the counters).

### Metric names (agent → OTLP → VictoriaMetrics)

One resource per workload, `service.name` = the site slug (enriched to `falak.site.id` by the relay as today), or
`falak-db` with `falak.db.instance.id` for databases, or `falak-agent` with `falak.slice` for worker / daemon slices.
Data point attributes: `falak.workload.kind` (`container` | `slice` | `user` | `processes`), `falak.workload.name`
(container name, slice key, unix user or, for `processes`, the site slug), `falak.compose.service` (compose),
`container.id` (containers). Workload resources
(scope `falak-agent/workloads`) get no `falak.deployment.id` / `falak.release.id`: they describe the site's processes,
not a release, and a deployment must not split every workload series (which would double `sum(rate())` while the old
and new series are both in the window).

| Metric (OTLP) | Type | Unit | Prometheus name |
|---|---|---|---|
| `falak.workload.cpu.time` | counter | s | `falak_workload_cpu_time_seconds_total` |
| `falak.workload.cpu.limit` | gauge | {cpu} | `falak_workload_cpu_limit` |
| `falak.workload.cpu.throttled.time` | counter | s | `falak_workload_cpu_throttled_time_seconds_total` |
| `falak.workload.cpu.throttled.periods` | counter | {period} | `falak_workload_cpu_throttled_periods_total` |
| `falak.workload.cpu.periods` | counter | {period} | `falak_workload_cpu_periods_total` |
| `falak.workload.memory.usage` | gauge | By | `falak_workload_memory_usage_bytes` (attribute `falak.memory.type` = `used` \| `cache`) |
| `falak.workload.memory.limit` | gauge | By | `falak_workload_memory_limit_bytes` |
| `falak.workload.memory.oom_kills` | counter | {kill} | `falak_workload_memory_oom_kills_total` |
| `falak.workload.disk.io` | counter | By | `falak_workload_disk_io_bytes_total` (`disk.io.direction` = `read` \| `write`) |
| `falak.workload.network.io` | counter | By | `falak_workload_network_io_bytes_total` (`network.io.direction`) |
| `falak.workload.pids` | gauge | {process} | `falak_workload_pids` |
| `falak.workload.pids.limit` | gauge | {process} | `falak_workload_pids_limit` |

A limit series exists only while a limit is set. CPU time replaces the old utilisation gauge: `rate(cpu_time[w])` is
cores used (the panel's rate windows are `max(5m, step)`, so a 7d chart's 30 minute points miss nothing; OOM kills are
counted per step over windows that do not overlap, adding up to the range's total), comparable to the limit (cores) and to the host (`system.cpu.logical.count`, new on the host resource).
The old `falak.container.*` metrics are removed (nothing in the panel reads them; the dashboard is rewritten).

Network for native sites is not attributable without eBPF or socket tracking (phase 4), so native workloads have no
network series; the panel shows the server's.

### telemetry.configure

`sites[]` entries gain `unix_user` and `runtime` (additive, feature `telemetry.workloads`; agents without them skip
the per-user collector), then `isolated` and `unix_user` for non-isolated sites (feature `telemetry.workloads.shared`,
see above). The schema in `contracts/agent-protocol/commands/telemetry.configure.schema.json` is updated. A Docker /
compose site needs neither: its containers are labelled.

### Requests without the APM package

The edge access logs give every site its traffic. In Loki the line is plain text (`GET /cart 503 4.0ms`); the
fields travel as structured metadata (`http_response_status_code`, `http_server_duration_ms`, `user_agent_original`).
The selector is `LogQueryBuilder::access`'s (by slug, so load balancers count too), minus Falak's health checks:

```
sel        = <access selector> | user_agent_original !~ "Falak-HealthCheck/.*"
requests:  sum(count_over_time(sel [<step>s])) / <step>
5xx:       sum(count_over_time(sel | http_response_status_code >= 500 | __error__="" [<step>s])) / <step>
p95 (ms):  quantile_over_time(0.95, sel | unwrap http_server_duration_ms | __error__="" [<max(60, step)>s]) by ()
```

The site's Metrics tab uses span metrics when the site sends spans and the access logs otherwise, and says which
("from the edge" / "from the app").

### The Metrics tab

Docker / compose site:

```
CPU (cores)   ███ site containers  ░░░ rest of the server  ── limit  ── server cores   ▲ throttled %
Memory        ███ used  ░░░ rest of the server  ·· cache (reclaimable)  ── limit  ── server memory  ✕ OOM kills
Disk I/O      read / write
Network       receive / transmit
Processes     ── pids limit
Per service   one row per compose service / container: CPU, memory against its limit, restarts
```

Native site: the same CPU, memory, disk and process charts, from its slice or its user, against the server; the
network chart is the server's (labelled so). "Rest of the server" is the host total minus the site, so the two stack
to the server's real usage: busy CPU (all but idle, iowait and steal), used memory (total − available, which leaves
the page cache out). The site's page cache is therefore a separate, unstacked line, not part of the stack.

Several servers: a server picker (default: all, summed; the limit lines then show per-server values). Ranges stay
1h / 6h / 24h / 7d.

### Grafana

`observability/grafana/dashboards/falak-containers.json` is rewritten as **Falak workloads** on the new names
(containers, slices, users; per server, per site).

---

## Phase 2: logs (v0.15.0)

- **Files inside a container.** Site → Logs → Sources: paths (globs) in the container, optional multi-line start
  pattern, format (plain / JSON). The agent tails `/proc/<container init pid>/root/<path>`: no `docker exec`, nothing
  in the image. It follows the container across restarts (new pid) and handles rotation the way `tail.go` does for host
  files. Offsets are kept per container id.
- **Native sites:** PHP-FPM error log (`/var/log/falak/fpm-<pool>.log` for own masters, the pool's
  `slowlog` enabled with `request_slowlog_timeout`), journald of the site's units (own FPM master, scopes).
- **Laravel exceptions → Issues** without the APM package: the agent already merges Laravel multi-line entries; an
  `ERROR`/`CRITICAL` entry with a stack trace is sent to the Insights ingest as an exception (fingerprint from the
  exception class + top in-app frame), so plain Laravel sites get the Issues list.

## Phase 3: databases (v0.16.0)

The `falak-db` helper inside each database container (already polled every minute for connections) collects:

| | PostgreSQL | MySQL / MariaDB | Redis / Valkey |
|---|---|---|---|
| Metrics | transactions/s, cache hit %, connections by state, locks, deadlocks, replication lag, database and table sizes | queries/s, threads, InnoDB buffer pool hit %, row lock waits, slow queries | ops/s, hit rate, memory vs `maxmemory`, evictions, clients, keys |
| Logs | engine log + slow queries (`log_min_duration_statement`) | error log + slow log (`long_query_time`) | `SLOWLOG` |
| Top queries | `pg_stat_statements` | `performance_schema` statement digests | slowest commands |

Plus the phase 1 container metrics against the server and the instance's memory setting.

- **Database Issues** (Insights, new source `database`): a slow query is grouped by its normalized shape (`queryid` /
  digest) into one Issue with status, assignee, comments; engine errors (deadlocks, too many connections, repeated
  auth failures, broken replication, OOM) become Issues too.
- **Recommendations:** sequential scans of large tables, unused indexes, low cache hit (suggested
  `shared_buffers` / `innodb_buffer_pool_size`), Redis near `maxmemory` with `noeviction`, disk-full forecast.
- **Privacy:** only normalized query text (`WHERE id = ?`) is stored; Redis command arguments are dropped.
- **Alerts:** slow-query spike, replication lag, deadlocks, memory near the limit.
- **UI:** database instance tabs gain Metrics, Logs and Insights.

## Phase 4: network (v0.17.0)

| Level | Shows | Source |
|---|---|---|
| HTTP | requests, status codes, bytes, top paths, top client IPs per site | access logs (Loki) |
| Connections | connections by state, listening ports, TCP/UDP, peers the workload talks to with bytes and counts | conntrack (netlink, with `nf_conntrack_acct`) and `/proc/<pid>/net/{tcp,tcp6,udp,udp6}` per container namespace; native sockets attributed by owning process → cgroup or uid |

Per-minute summaries, top 20 peers per workload, the rest folded into `other`, so label cardinality stays bounded.
The site and container Network tab shows a "talks to" list (`postgres:5432`, `redis:6379`, `api.stripe.com:443`)
over time and inbound traffic by port; the server's shows the sum.

## Phase 5: later

- eBPF probe in the agent: per-process latency, DNS lookups, retransmits (needs a recent kernel; optional feature).
- On-demand capture: a 30 s `tcpdump` on one container or port, downloaded as pcap; its own permission, audited.

## Agent overhead budget

Under ~1% of one CPU on a small server at the 15 s interval: one `/proc` pass, a handful of file reads per cgroup,
no Engine API calls per sample except listing containers.
