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

Today a site's Logs tab shows its containers' stdout / stderr, its supervised programs and cron, and (PHP sites) the
files of its shared `storage/logs` / `var/log` directory. Phase 2 adds what is still invisible: files written *inside*
a container, PHP-FPM's own error and slow logs, and the exceptions of Laravel sites that do not run the APM package.

### Sources: files inside a container

Site → Logs → **Sources** (Docker and compose sites): up to 10 entries per site, each

| Field | Rule |
|---|---|
| `path` | Absolute path or glob inside the container (`/var/www/html/storage/logs/*.log`); no `..` segment, no control character or backslash, not a directory (`/x/`), not under `/proc`, `/sys` or `/dev` (nor a glob of its first segment matching them), ≤ 255 bytes; `*`, `?` and closed `[...]` are allowed in any segment |
| `format` | `plain` (default) or `json` (one JSON object per line: message / level / time / trace ids extracted, as for host files) |
| `multiline` | none, `laravel` (records start with `[YYYY-MM-DD HH:MM:SS`), or `pattern` with `multiline_pattern` (≤ 256 bytes, kept untrimmed): a regex a record's first line matches; every other line is appended to the record before it. The agent compiles it with Go's RE2 and skips (logs) a source whose pattern does not compile, so the control plane only accepts syntax PCRE and RE2 read alike, checked token by token: no lookaround, named or atomic group, back-reference, possessive or nested quantifier, `{,n}`, count over 1000; escapes `\d \s \w \b \A \z \t \n \r \f \v` (and their negations; `\b \B \A \z` not in a class), `\x##` / `\x{hex}`, `\pL` / `\p{Name}`, or escaped punctuation. `contracts/agent-protocol/testdata/multiline-patterns.json` is the corpus both test suites read (every accepted pattern must compile in Go) |
| `compose_service` | compose sites: only the containers of that service (it must be a service of the project); empty = every service |

Stored on the site (`sites_sites.log_sources`, JSON list), edited through `GET` / `PUT /sites/{site}/log-sources` (web
and API, `view` / `update` on the site, audited as `site.log_sources_updated`). Saving re-sends telemetry.configure to
the site's servers (`SiteUpdated` with `log_sources`). The same endpoint carries the PHP-FPM slow log threshold (below).

`telemetry.configure` `log_sources[]` gains (feature `logs.container_files`):

| Field | Meaning |
|---|---|
| `container: true` | `path` is inside the running containers labelled `falak.site=<site>` (required with it) |
| `compose_service` | …and `falak.service=<name>` |
| `multiline_pattern` | RE2 start-of-record pattern (any source; wins over `multiline`) |
| `origin` | `file` \| `php_fpm` \| `php_fpm_slow`: the record attribute `falak.log.origin` (container sources are always `container_file`) |

**How the agent reads them.** No `docker exec`, nothing in the image. The tailer lists the running containers labelled
`falak.site` (Engine API, at most every 10 s, shared by every container source; a failed list keeps what is open) and
inspects a container once for its init pid, kept with the pid's start time (`/proc/<pid>/stat`, as the workload
collector does). A container restarted in place (same id, new pid) or whose pid is gone is inspected again and its open
files are closed (offsets kept, a partial last line left out of them) and reopened through the new root; a replaced
container (deploy: new id) is a new container. The files are read through `/proc/<pid>/root`, the container's own root
(its mount namespace: overlay, volumes, bind mounts):

- The root is opened once per pid as an `os.Root`. The pid may exit and be reused (by any process, possibly a host
  one) between the inspect and the open, so the pid's start time is read before and after the open and the container
  is inspected again after it: same pid, same `StartedAt`, still running, or the root is refused. A root that is the
  host's own (the same directory as the host's `/`, or `/proc/<pid>/ns/mnt` equal to `/proc/1/ns/mnt`) is refused too,
  and so is a pid whose `/proc/<pid>/cgroup` (read between the two start-time reads) does not name the container's id.
  Every later open, stat, `lstat` and `readlink` is resolved beneath that root (`os.Root` refuses to leave it), so a
  symlink swapped in by the container between two checks cannot point the agent at a host file.
- Globs are expanded segment by segment inside that root. Symlinks are resolved **in** the container root, as the
  container sees them: an absolute target (`/app/storage → /data/storage`) restarts at the container root, `..` stops
  at it. A target that names a host path (`../../../../etc/shadow`) therefore names that path inside the container,
  never on the host. At most 40 links per path, 256 files per source and container; a directory listing reads at most
  4096 entries and is reused for 5 s (a glob is expanded every second). A glob has a budget of operations (directory
  entries looked at, lstats, stats): 16384 per source and container, 131072 per poll for all container sources; one
  that spends it stops (logged once).
- Kernel state is never read. A resolved path under `/proc`, `/sys` or `/dev` is skipped (a link
  `storage/logs/laravel.log → /proc/kmsg` would drain the host's kernel log and block, `/proc/kallsyms` would show
  kernel addresses to the agent's root reader).
- Nothing but a regular file is ever opened for reading. A container has `CAP_MKNOD`: a device node exchanged with the
  log file between the stat and the open (`renameat2(RENAME_EXCHANGE)`, e.g. `/dev/watchdog`, whose open arms the
  host's watchdog) must never be opened at all. On Linux every log file, host files included, is first opened
  `O_PATH` (through the container's `os.Root`, which adds `O_NOFOLLOW`: no driver's open, no read access), checked on
  that handle (`fstat`: a regular file, the same device and inode the stat saw; `fstatfs`: not procfs, sysfs, debugfs,
  tracefs, cgroup, securityfs, devpts, bpf, …), and only then reopened `O_RDONLY | O_NONBLOCK | O_NOCTTY` through
  `/proc/self/fd/<n>`, which names that same inode, and checked again. Container directories are opened
  `O_DIRECTORY | O_NONBLOCK | O_NOCTTY`: on a FIFO or a device, `O_DIRECTORY` fails with `ENOTDIR` in the path walk
  (the kernel's `do_open` checks it against the dentry before `vfs_open`), so no driver's open runs and nothing
  blocks; `os.Root` opens every intermediate directory `O_DIRECTORY | O_NOFOLLOW` too.
- The tailer holds its lock during a poll, so every call it makes is non-blocking and bounded: a poll reads a file at
  most up to the size `fstat` gives it then (4 MiB at a time), at most 16 MiB per site per poll (the rest follows),
  with at most 256 files open per site. A file already larger than 1 MiB when first seen in a fresh container is read
  from the first whole line of its last MiB. Known exception: host sources are expanded with `filepath.Glob`, whose
  plain open of the directories of the paths the control plane names (a site's shared log directory) would block on a
  FIFO a site user swapped in for such a directory.
- Only regular files are read (a log symlinked to `/dev/stdout`, as official nginx images do, is skipped: it is already
  in the container's stdout).
- Offsets are kept per container id and in-container path (`container:<id>:<path>` in `log-offsets.json`), with the
  inode and the first 64 bytes as for host files: a restart in place resumes where it was; rotation (rename + new file)
  and truncation are handled the way `tail.go` handles host files. The offsets of a container no longer listed are
  dropped.
- A file first seen in a container created less than two minutes before (a deploy) is read from the start, so its
  first lines are kept; files already present in an older container when the agent first sees them start at their end,
  like host files. Freshness is checked when the file is first seen: a source added an hour after a deploy does not
  ship that container's history.
- A source the agent refuses (a pattern RE2 does not compile, a container path under `/proc`) is skipped and logged;
  the rest of telemetry.configure applies.

Records are the site's (`service.name` = slug, `falak.log.kind` `app`), with the attributes of its container's stdout
(`container.id`, `container.name`, `falak.compose.service`, `falak.release.id`) plus `log.file.path` (the path inside the
container), `log.file.name` and `falak.log.origin=container_file`.

### Native sites: PHP-FPM error and slow logs

- **Error log.** A pool with its own master (a site with limits) writes `/var/log/falak/fpm-<pool>.log` (its
  `[global] error_log`): worker crashes, `max_children` reached, "child said into stderr". The control plane adds it
  as a source of every PHP-FPM site (`origin: php_fpm`; the file only exists while the site has its own master). The
  shared master's log (`/var/log/php<version>-fpm.log`) mixes every pool and is not shipped per site.
- **Slow log.** `runtime.fpm.pool` gains `slowlog_timeout` (seconds, 0 = off; feature `runtime.fpm.slowlog`). The pool
  gets `request_slowlog_timeout = <n>s`, `request_slowlog_trace_depth = 20` and `slowlog =
  /var/log/falak/php-fpm-slow/<pool>.log`, in the shared master and in an own master alike (the slow log is a pool
  setting). New PHP-FPM sites start at 5 s; **sites that existed before stay off** (`sites_sites.fpm_slowlog_timeout`
  null = off), so the agent upgrade rewrites none of their pool files and reloads no master (a reload per site, with
  in-flight requests, is not worth a log nobody asked for). Site → Logs → Sources turns it on or changes it (1–600 s,
  0 = off). Saving re-sends the pool to the site's ready servers; an agent reporting a new version with the feature gets
  the pools whose slow log is on again (sites created while it ran an older agent, which stripped it), besides the
  isolated ones it always gets (`ReapplyFpmPools`). Removing a pool removes its slow log. The source is sent as `origin: php_fpm_slow` with a `multiline_pattern` matching the entry
  header (`[14-Oct-2026 10:11:12]  [pool falak-shop] pid 123`), so an entry and its backtrace are one record (WARN).
- The agent writes `/etc/logrotate.d/falak-php-fpm` (daily, 7 files, 50 MB) for both, since neither log is rotated
  today. `copytruncate`: a master reopens its logs on `SIGUSR1`, but the slow logs of one directory belong to several
  masters (the shared one of each PHP version, each site's own), which a `postrotate` would have to find and signal;
  the tailer handles truncation (lines written between the copy and the truncation can be lost).
- **Journald** of a site's units is **not** shipped: the own master logs to its file (`daemonize = no`, `error_log`),
  supervised programs and cron are already captured by the agent, and what is left in the journal is systemd's
  start / stop lines (OOM kills are counted by the phase 1 workload metrics). A `journalctl --follow` per unit, or the
  journal API (cgo / sd-journal export format), is not worth that.

### Laravel exceptions → Issues without the APM package

The tailer already merges Laravel's multi-line entries (`multiline: laravel`, host `storage/logs` and container
sources alike). For an entry at `ERROR`, `CRITICAL`, `ALERT` or `EMERGENCY` whose context holds an exception with a
trace:

```
[2026-10-14 10:11:12] production.ERROR: Division by zero {"userId":1,"exception":"[object] (DivisionByZeroError(code: 0): Division by zero at /srv/falak/sites/shop/releases/01J…/app/Http/Controllers/Cart.php:12)
[stacktrace]
#0 /srv/falak/sites/shop/releases/01J…/vendor/laravel/framework/src/Illuminate/Routing/Controller.php(54): App\\Http\\Controllers\\Cart->show()
…
#38 {main}
"}
```

the agent sends an `exception` line to the Insights tee (`POST /agent/v1/insights`, the APM's contract): `type` = the
outermost exception class, `message` its message, `stacktrace` its `[stacktrace]` block (JSON escapes undone, ≤ 16 KiB,
a `[previous exception]` chain is not included), `handled: false`, `event_type: log`, `route_or_name` = the log file
name, no trace / span id, `at` = the entry's timestamp. The control plane groups it like an APM exception: its
fingerprint is the class plus the top in-app frames (not `vendor/`), release paths stripped, so the same error reported
by the APM package and by the log lands in the **same** Issue. The agent computes the same key (class + first in-app
frame) for its own de-duplication.

**No double reports.** A site that also runs the APM package reports each exception twice (span event and log line).
The agent drops a log-derived exception when the same site sent an APM span (`falak.event.type` set) within the last 15
minutes, or sent an APM exception with the same key within ±60 s. Log-derived exceptions are held 15 s before they are
sent, so a span exported a few seconds after the log line still wins. Both spans and log lines pass through the same
agent, so no control-plane state is needed. At most 120 log-derived exceptions per site and minute are sent (the rest
are counted as dropped); at most 240 error entries per site and minute are parsed, only their first 64 KiB, and the
message is cut at 4 KiB.

The de-duplication is coarse on purpose: a site whose APM sent any span in 15 minutes gets no log-derived exceptions
at all, including the ones its APM package does not report (a `Log::error($e)` the package does not see), and a site
behind the APM's trace sampling keeps that sampling for its exceptions. A site without spans gets every one.

The timestamp of Laravel's default line format has no zone: it is the app's `app.timezone`, UTC unless the app changed
it. The agent does not know the app's setting and takes it as UTC; an app on another zone sees its log exceptions
shifted by its offset (a time more than 5 minutes ahead becomes the arrival time in Insights).

Messages and stack traces may contain personal data (an e-mail in a message, an argument in a frame), exactly as the
APM package's exceptions do; they are stored in the Insights tables for their 30 days, nothing is redacted beyond what
the app's own log formatter does.

### The Logs tab

A **source** filter next to level and server, as structured metadata (no new Loki index label: `falak_log_kind`
keeps its two values, so stream cardinality does not change):

| Filter | LogQL (after the stream selector) |
|---|---|
| Output (stdout / stderr) | `\| log_iostream=~"stdout\|stderr"` (containers, programs, cron) |
| App log files | `\| log_file_path!="" \| falak_log_origin=~"file\|"` (records of older agents have no origin) |
| Container files | `\| falak_log_origin="container_file"` |
| PHP-FPM | `\| falak_log_origin="php_fpm"` |
| Slow requests | `\| falak_log_origin="php_fpm_slow"` |
| Access | the `falak_log_kind="access"` selector (the edge's requests, otherwise left to Network Logs) |

The panel shows the options that apply to the site's runtime. Its **Sources** button opens the editor (container
sources; the slow log threshold for PHP-FPM sites), read-only without `update` on the site.

### Compatibility

| Feature | Payload | Older agents |
|---|---|---|
| `logs.container_files` | telemetry.configure `log_sources[].container`, `.compose_service`, `.multiline_pattern`, `.origin` | items with `container: true` are removed whole (stripping the flag would tail that path on the host: `PayloadCompatibility::DROP`); the other fields are stripped |
| `runtime.fpm.slowlog` | runtime.fpm.pool `slowlog_timeout` | stripped (no slow log) |

Laravel log exceptions need no feature: the agent derives them from records it already reads.

### Not in this phase

- Sources in `falak.yaml` (config as code): the UI and API only for now.
- Exceptions from Laravel's JSON log format (`Monolog\Formatter\JsonFormatter`: no `#0 …` frames, only `file:line`).
- Log-derived exceptions still in their 15 s hold when the agent stops are not sent.
- Host files of native sites other than the shared log directory and the PHP-FPM logs (a free path on the host would
  let a site read any file of the server).

### Tests

- Go: in-root path resolution and globbing over a fake `/proc/<pid>/root` (absolute and relative symlinks, `..`
  clamped, escape to a host file blocked, non-regular files skipped); a container restarted in place (new pid) resumes
  at its offset, a new container id starts its own; rotation and truncation inside a container; offsets per container
  id and pruning; Laravel entry → exception (class, message, frames, first in-app frame, levels below ERROR ignored);
  tee de-duplication (APM active, matching APM exception, hold, per-minute cap); pool rendering with a slow log, an
  older agent's pool rewritten by nothing, the slow log removed with its pool; telemetry.configure validation of the
  new fields and a refused source skipped alone; the pattern corpus compiles; FIFOs (swapped in after the stat, or as
  the log path) never block; `/proc`, `/sys`, `/dev` paths and links refused; procfs files refused by `fstatfs`
  (Linux-only test); pid reuse, a changed `StartedAt` and the host's root or mount namespace refused; freshness
  checked when a file is first seen; directory listings cached and bounded; reads stop at the file's size; parse
  bounds and rate.
- PHP: sources CRUD, validation (absolute, `..`, `/proc` `/sys` `/dev`, glob, pattern corpus, compose service, limit,
  runtime) and authorization; the telemetry.configure payload (container sources, FPM sources); `PayloadCompatibility`
  (drop + strip); the pool payload with `slowlog_timeout`; an upgrade on a server with sites that predate the slow
  log re-sends no pool with it; a log-derived stack trace fingerprints like the APM's; an origin never selects the
  edge's access records.
- UI: the source filter's options per runtime (vitest).

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
