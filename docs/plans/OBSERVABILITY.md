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
| 5 | later | eBPF (TCP health, DNS, run-queue latency: `network/ebpf.md`), on-demand packet capture (`network/capture.md`) |

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
| `memory.stat` | `anon`, `file`, `shmem` (page cache is shown apart: "used" is `anon` + kernel + `shmem`, cache is `file` − `shmem`, reclaimable; shared memory such as Postgres' shared_buffers counts as used) |
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
The agent drops a log-derived exception when the same site sent an APM exception with the same key within ±60 s of
the moment the log line was read (not of the entry's own timestamp, see the timezone note below). Log-derived exceptions are held 15 s before they are
sent, so a span exported a few seconds after the log line still wins. Both spans and log lines pass through the same
agent, so no control-plane state is needed. At most 120 log-derived exceptions per site and minute are sent (the rest
are counted as dropped); at most 240 error entries per site and minute are parsed, only their first 64 KiB, and the
message is cut at 4 KiB.

Only a matching APM exception drops a log-derived one. v0.15.0 also dropped every log exception of a site whose APM
sent any span in the last 15 minutes; on cloud.falak.sh that lost a Horizon supervisor's `RedisException`, which the
APM package never sees (queue supervisors, artisan commands, a `Log::error($e)`), so v0.16.1 removed that rule. An
exception the APM reports under another key (a different first in-app frame) can be reported twice; the control plane's
fingerprinter usually still files both in the same Issue.

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

Every managed database runs `falak-db` inside its container (docs/DB_IMAGES.md); the agent already runs
`falak-db stats` once a minute for the heartbeat's connection figures. Phase 3 adds one helper command, `observe`,
that reads everything the engine knows about itself in one `docker exec`, and an agent loop that turns its answer into
metrics, slow-query logs and a per-minute report to the control plane, which keeps top queries, raises database Issues
and alerts, and computes recommendations.

```
falak-db observe ──► agent (db.Observer, every 60 s per running instance)
                       ├─► OTLP metrics falak.db.*            → VictoriaMetrics (30 d)
                       ├─► OTLP logs kind db.slow             → Loki (15 d)
                       └─► POST /agent/v1/requests/db.observed → Databases: snapshot on the instance,
                                                                   top queries (hourly, 30 d), size samples,
                                                                   Issues (Insights, kind database), alerts,
                                                                   recommendations (computed on read)
docker logs (engine stderr) ──► agent log follower (kind db.engine, falak.db.instance.id) → Loki
                           └──► db.Observer scans the same lines once a minute: Postgres slow statements, engine errors
```

### What an instance needs

| | New image (v0.16.0+) | Older image |
|---|---|---|
| Engine metrics, slow logs, report | yes | no: `observe` exits 2 (unknown command), the instance keeps its connection figures and the UI asks for a minor upgrade ("Update the image to see engine metrics") |
| Container CPU / memory (phase 1) | yes | yes |
| Engine logs in the Logs tab | yes | yes (docker logs) |

The agent tells the two apart by the exit code of `observe` (2), remembered per container id until the container
changes, so an old image costs one failed exec per container start, not one a minute.

Agent feature: **`db.observability`** (`db.instance.create|update` `instance.query_insights`, the `db.observed`
request). Older agents get no `query_insights` (PayloadCompatibility) and send no report; nothing else changes for them.

### `falak-db observe [--detail] [--cursor JSON]`

One JSON object. Every section is best effort: a failing query is reported in `warnings` and the rest still comes back.
`--detail` (every fifth call: five minutes) adds the heavier sections (`statements`, `tables`, `indexes`, `databases`).
`--cursor` is the previous answer's `cursor`, so slow-log entries are read once; without one (agent start) nothing
older is replayed.

| Section | PostgreSQL | MySQL / MariaDB | Redis / Valkey |
|---|---|---|---|
| `counters` (cumulative) | `pg_stat_database` summed: `xact_commit`, `xact_rollback`, `blks_hit`, `blks_read`, `tup_returned/fetched/inserted/updated/deleted`, `deadlocks`, `temp_bytes` | `SHOW GLOBAL STATUS`: `Questions`, `Com_select/insert/update/delete/commit/rollback`, `Innodb_buffer_pool_read_requests`, `Innodb_buffer_pool_reads`, `Innodb_rows_read/inserted/updated/deleted`, `Innodb_row_lock_waits`, `Innodb_row_lock_time`, `Slow_queries`, `Aborted_connects`, `Connection_errors_max_connections`, `Bytes_received/sent`; deadlocks from `Innodb_deadlocks` (MariaDB) or `information_schema.INNODB_METRICS` `lock_deadlocks` (MySQL) | `INFO`: `total_commands_processed`, `keyspace_hits/misses`, `evicted_keys`, `expired_keys`, `rejected_connections`, `total_net_input/output_bytes`, `errorstat_OOM`, `errorstat_NOAUTH` + `errorstat_WRONGPASS` |
| `gauges` | connections by state (`pg_stat_activity` client backends: active, idle, idle_in_transaction, other), `max_connections`, locks waiting (`pg_locks` not granted), replication lag (only on a streaming replica: a WAL receiver exists) | `Threads_connected`, `Threads_running`, `max_connections` (no replication: Falak runs no MySQL replicas) | `connected_clients`, `blocked_clients`, `maxclients`, `used_memory`, `maxmemory`, keys; on a replica `master_last_io_seconds_ago` as the lag and a broken link (`master_link_status` not up) |
| `labels` | `role` primary / replica | `role` | `maxmemory_policy`, `role` |
| `settings` | `shared_buffers`, `max_connections`, `log_min_duration_statement`, `shared_preload_libraries` | `innodb_buffer_pool_size`, `max_connections`, `long_query_time`, `performance_schema` | `maxmemory`, `maxmemory_policy` |
| `memory` | the container's cgroup as seen from inside: `memory.current`, `memory.max`, `memory.events` `oom_kill` | same | same |
| `databases` (detail) | `pg_database_size` of the 20 largest | `information_schema.TABLES` summed per schema, 20 largest | keys per logical database (`INFO keyspace`) |
| `tables` (detail) | `pg_stat_user_tables` of the 10 largest databases: 20 largest tables each with `n_live_tup`, `seq_scan`, `seq_tup_read`, `idx_scan`, size | `information_schema.TABLES`, 20 largest | — |
| `indexes` (detail) | `pg_stat_user_indexes` with `idx_scan = 0`, not unique / primary, 20 largest, and `stats_since` (the later of the statistics reset and the server start) | `performance_schema.table_io_waits_summary_by_index_usage` `COUNT_STAR = 0` (performance_schema on) | — |
| `statements` (detail) | `pg_stat_statements`, 100 by total time (falak-db's own left out: they start with `/* falak-db observe */`; apps using the superuser count) | `events_statements_summary_by_digest`, 100 by total time (statements without a default schema, falak-db's own, left out) | — |
| `slow` | — (Postgres writes its slow log to stderr, read by the agent) | the slow log file `/var/log/falak-db/slow.log` from the cursor's offset (inode + offset; 4 MiB per call); truncated by the helper once read past 64 MiB, so it no longer grows forever | `SLOWLOG GET 128` newer than the cursor's id (an id lower than the cursor: the server restarted, everything is new) |
| `query_insights` | `on` when `pg_stat_statements` is preloaded (the helper creates the extension in `postgres` when missing), else `off` | `on` with `performance_schema`, else `off` (MySQL turns it off below 1 GiB of memory; MariaDB has it off by default) | always `on` (SLOWLOG) |

Query text is normalized in the helper and again in the agent (`agent/internal/sqlnorm`), never shipped raw: string,
numeric, hex, bit and dollar-quoted literals become `?`, lists of placeholders `(?, ?, ?)` become `(?, ...)` and
repeated `VALUES` tuples one, comments are dropped, whitespace collapses, 2,000 characters at most.
`pg_stat_statements` and MySQL digests are normalized already, except utility statements, which
`pg_stat_statements.track_utility = off` keeps out (and the normalizer catches `PASSWORD '…'`, `IDENTIFIED BY '…'` all
the same). Redis entries keep the command name and the number of keys: `MGET <3 keys>`, `SET <1 key>`; arguments are
dropped.

### Query insights (`pg_stat_statements`, MariaDB `performance_schema`)

Both need a restart, and these are users' production databases: **nothing restarts without the user's action.**

- **New instances** get `instance.query_insights: true` when their server's agent has `db.observability` (PostgreSQL;
  MariaDB from 1 GiB of memory: `performance_schema` takes a few hundred MiB, and the helper never renders it below
  1 GiB either). The agent writes `query-insights.json` next to the settings file
  (`/run/falak/db/conf`); a v0.16.0 helper renders `shared_preload_libraries = 'pg_stat_statements'`,
  `pg_stat_statements.max = 1000`, `pg_stat_statements.track = top`, `pg_stat_statements.track_utility = off`
  (PostgreSQL) or `performance_schema = ON` (MariaDB). An older helper never reads the file (the settings file stays as
  it was: an older helper rejects unknown settings).
- **Existing instances** show *Enable query insights (restarts the database)* in the Insights tab, offered only when the
  agent has `db.observability` and the instance's latest report shows a helper that can (`query_insights` in it). It
  sets the flag and sends `db.instance.update` with `apply_query_insights: true`: the only update that restarts a
  running container for a query insights change. Every other update carrying the flag (network convergence, a renewed
  certificate, PITR settling) only writes the file, which applies at the next restart the user causes; the enable
  action still restarts an engine started before the file was written (its mtime against the container's start).
- MySQL has `performance_schema` on from 1 GiB of memory (below, it is off to save memory): instances below get top
  queries from the slow log instead.
- `pg_stat_statements` ships with the official PostgreSQL images (contrib): no image change beyond the new `falak-db`.

Without query insights, top queries come from slow-log entries grouped by their normalized text (`source: slowlog`).

### Settings changes without a restart

`slow_query_ms` is reloaded in place: when it is the only setting that changed, the agent rewrites the settings file
and runs `falak-db reload` (renders the config again; PostgreSQL `pg_reload_conf()`, MySQL / MariaDB
`SET GLOBAL slow_query_log` / `long_query_time`) instead of restarting. Redis / Valkey can't: `CONFIG` is denied to the
`default` user, so their threshold applies at the next restart, and so does every other setting, as before. An older
helper without `reload` (exit 2) gets the restart.

### Metrics (agent → OTLP → VictoriaMetrics)

Resource: `service.name` `falak-db`, `falak.db.instance.id` (the instance id **in lowercase**, as phase 1's container
metrics: `falak_db_instance_id="01k…"`, while `falak_server_id` is uppercase), `db.system.name` (`postgresql`,
`mysql`, `mariadb`, `redis`, `valkey`). Scope `falak-agent/databases`. Emitted every minute (detail-only gauges from the
last detail call).

| Metric (OTLP) | Type | Unit | Attributes | Prometheus name | Engines |
|---|---|---|---|---|---|
| `falak.db.up` | gauge | (none: `1` would become `falak_db_up_ratio`) | | `falak_db_up` | all (0: `observe` failed, or the container is starting / unhealthy) |
| `falak.db.connections` | gauge | {connection} | `falak.db.connection.state` | `falak_db_connections` | pg: active, idle, idle_in_transaction, other; my: running, idle; kv: blocked, normal |
| `falak.db.connections.max` | gauge | {connection} | | `falak_db_connections_max` | all |
| `falak.db.connections.aborted` | counter | {connection} | | `falak_db_connections_aborted_total` | my (`Aborted_connects`), kv (`rejected_connections`) |
| `falak.db.queries` | counter | {query} | | `falak_db_queries_total` | my (`Questions`), kv (commands) |
| `falak.db.operations` | counter | {operation} | `falak.db.operation` select / insert / update / delete | `falak_db_operations_total` | my |
| `falak.db.transactions` | counter | {transaction} | `falak.db.transaction.outcome` commit / rollback | `falak_db_transactions_total` | pg, my |
| `falak.db.rows` | counter | {row} | `falak.db.row.operation` returned / fetched / read / inserted / updated / deleted | `falak_db_rows_total` | pg, my |
| `falak.db.cache.requests` | counter | {request} | `falak.db.cache.result` hit / miss | `falak_db_cache_requests_total` | pg (blocks), my (buffer pool), kv (keyspace) |
| `falak.db.locks.waiting` | gauge | {lock} | | `falak_db_locks_waiting` | pg |
| `falak.db.lock.waits` | counter | {wait} | | `falak_db_lock_waits_total` | my |
| `falak.db.lock.wait.time` | counter | s | | `falak_db_lock_wait_time_seconds_total` | my |
| `falak.db.deadlocks` | counter | {deadlock} | | `falak_db_deadlocks_total` | pg, my |
| `falak.db.slow_queries` | counter | {query} | | `falak_db_slow_queries_total` | all (pg and kv: counted by the agent from the log / SLOWLOG, from 0 at agent start) |
| `falak.db.temp.io` | counter | By | | `falak_db_temp_io_bytes_total` | pg |
| `falak.db.network.io` | counter | By | `network.io.direction` | `falak_db_network_io_bytes_total` | my, kv |
| `falak.db.replication.lag` | gauge | s | | `falak_db_replication_lag_seconds` | replicas only |
| `falak.db.memory.usage` | gauge | By | | `falak_db_memory_usage_bytes` | kv (`used_memory`) |
| `falak.db.memory.limit` | gauge | By | `falak.db.maxmemory_policy` | `falak_db_memory_limit_bytes` | kv (`maxmemory`, when set) |
| `falak.db.keys` | gauge | {key} | `falak.db.name` (db0 …) | `falak_db_keys` | kv |
| `falak.db.keys.evicted` / `.expired` | counter | {key} | | `falak_db_keys_evicted_total`, `falak_db_keys_expired_total` | kv |
| `falak.db.size` | gauge | By | `falak.db.name` (20 largest) | `falak_db_size_bytes` | pg, my |
| `falak.db.table.size` | gauge | By | `falak.db.name`, `falak.db.table` (10 largest) | `falak_db_table_size_bytes` | pg, my |

Cardinality per instance is bounded: ≤ 20 database names, ≤ 10 tables, fixed state / operation sets.

### Logs (Loki)

| Records | `service.name` | `falak.log.kind` | Record attributes |
|---|---|---|---|
| Engine stderr (docker logs follower, unchanged path) | `falak-db-<id>` (the container name, as before) | `db.engine` | `falak.db.instance.id`, `container.*` |
| Slow queries (the observer) | `falak-db-<id>` | `db.slow` | `falak.db.instance.id`, `db.system.name`, `db.namespace`, `db.query.text` (normalized), `falak.db.query.fingerprint`, `falak.db.duration_ms` |

An instance's logs are `{falak_org_id="…", service_name="falak-db-<id>"}` (+ `falak_log_kind="db.slow"` for the slow
ones). The changes to the shared follower (`agent/internal/logs/docker.go`) are tagging containers labelled
`falak.db.instance` with the kind and the instance id, and a line filter hook for PostgreSQL (`logs/dbredact.go`). At most 100 slow records per instance a minute are written
(the rest are counted).

**No SQL text or values from PostgreSQL's stderr.** PostgreSQL writes slow statements, failing statements and bind
parameters with their literals. The follower drops, per container, `LOG: duration: … statement|execute|bind|parse`,
`LOG: statement:`, `STATEMENT:`, `QUERY:`, `DETAIL: parameters:` and SQL `CONTEXT:` lines with their tab-indented
continuation lines, and rewrites `Key (…)=(…)` / `Failing row contains (…)` to `(?)`; the observer reads the container's
log itself and ships the slow ones normalized. New instances' config also has `log_parameter_max_length = 0`,
`log_parameter_max_length_on_error = 0` and `log_min_error_statement = panic`; existing ones pick them up at their next
start (the agent does not reload them for it; the rendered config is not part of the spec hash, so an agent upgrade
recreates or restarts nothing). MySQL / MariaDB keep `innodb_print_all_deadlocks` off.

**Limits.** Every observe query runs with PostgreSQL `statement_timeout = 15s`, `lock_timeout = 1s`,
`client_connection_check_interval = 5s`; MySQL `lock_wait_timeout = 2`, `max_execution_time = 15000`; MariaDB
`max_statement_time = 15`. Sizes come from information_schema's cache (MySQL: up to a day old) rather than refreshing
every table's statistics.

**Counters.** PostgreSQL's are summed over databases, so dropping one (or a statistics reset) lowers them without a
restart: the agent keeps every counter monotonic (it adds what was lost) unless the engine's uptime went down (a real
restart). Container memory is the working set (`memory.current` − `inactive_file`).

### Engine errors

Counted per minute from counter deltas where the engine has a counter, else from the engine's stderr (`docker logs
--since`, read by the observer once a minute, 5,000 lines at most):

| Kind | PostgreSQL | MySQL / MariaDB | Redis / Valkey |
|---|---|---|---|
| `deadlock` | `deadlocks` delta | deadlock counter delta | — |
| `too_many_connections` | `too many clients already`, `remaining connection slots are reserved` | `Connection_errors_max_connections` delta | `rejected_connections` delta |
| `auth_failed` | `password authentication failed` | `Aborted_connects` delta | `errorstat_NOAUTH` + `errorstat_WRONGPASS` delta |
| `oom` | cgroup `oom_kill` delta, `out of memory`, `terminated by signal 9` | cgroup `oom_kill` delta | cgroup `oom_kill` delta, `errorstat_OOM` delta (writes refused at `maxmemory`) |
| `replication` | `could not receive data from WAL stream`, `could not connect to the primary server` | — | the replica's link down, `MASTER <-> REPLICA sync: Error` |
| `disk_full` | `No space left on device` | same | same |
| `crash` | `PANIC:`, `was terminated by signal` (other than 9) | `got signal`, `Assertion failure` | `crashed by signal`, the bug report header |

### `db.observed` (agent → control plane)

One request per server a minute (`contracts/agent-protocol/requests/db.observed.schema.json`), every observed
instance in it (≤ 50): `id`, `engine`, `at`, `window_s`, `query_insights`, `gauges`, `deltas` (counter increases since
the previous call: `slow_queries`, `deadlocks`, …), `labels`, `settings`, `memory`, `errors` (`kind`, `count`,
`message` sample, normalized, ≤ 300 chars) and with detail `statements` (deltas since the previous detail call: `calls`,
`total_ms`, `rows`, plus `max_ms` and `fingerprint`, `database`, `query`, `source`), `tables`, `indexes`, `databases`.
A statement first seen is only a baseline (its delta is unknown); the agent's first detail call after start sends none.
Old control planes answer 404 `unknown_request`: the agent logs it once and keeps the metrics and logs going.

The control plane (Databases module, `DatabaseObserved` handler):

- stores the latest report on the instance (`observed`, `observed_at`) for the UI and the recommendations;
- adds statements to `databases_query_shapes` (instance, fingerprint, normalized text, database, source, first / last
  seen) and `databases_query_stats` (shape × hour: calls, total ms, max ms, rows, no-index calls), pruned after 30 days
  (`databases.observability.retention_days`, daily `PruneObservations`). PostgreSQL's `max_ms` is
  `pg_stat_statements`' running maximum, not the hour's: Issues are decided on the mean;
- records a daily size sample per instance (`databases_size_samples`) for the disk forecast;
- raises Issues and alerts (below).

### Database Issues (Insights, kind `database`)

Issues are the Insights ones (`insights_issues`: status, assignee, priority, comments), kind `database`, `server_id`
the instance's server, `meta.instance_id`; the Databases module records them through the Insights contract
`DatabaseIssues` (Insights owns the table).

- **Slow query shapes:** a statement whose mean in the window is at least the instance's `slow_query_ms` (every
  slow-log statement is) is an occurrence of the Issue fingerprinted `sha256(instance | fingerprint)` (`queryid`, digest,
  or the normalized text's hash), counted by its calls, once the shape has at least `issue_min_calls` (5; Redis / Valkey `issue_min_calls_kv`, 20) calls in the hour and, for engine statistics, an hourly mean at the threshold; title "Slow query: <normalized text>", sample with mean / max /
  calls. A resolved shape that is slow again regresses, as exceptions do.
- **Engine errors:** one Issue per instance and error kind (`auth_failed` from 5 in a minute, the others from 1).

### Recommendations (computed on read)

From the latest report (and the size samples), shown as a card in the Insights tab:

| Recommendation | When |
|---|---|
| Add an index to `<table>` | pg: `seq_scan` > 1,000 and `seq_tup_read / seq_scan` > 10,000 rows on a table above 100 MB and `seq_scan` > `idx_scan` |
| Drop unused index `<index>` | pg: `idx_scan` 0, above 10 MB, statistics at least 7 days old; my: no use since the server started (performance_schema), uptime ≥ 7 days |
| Queries without an index | my: digests with `SUM_NO_INDEX_USED` among the top statements |
| Raise the memory (`shared_buffers` / `innodb_buffer_pool_size`) | cache hit ratio below 95% (pg) / 99% (InnoDB) over the report's counters, with enough reads to matter |
| Redis near `maxmemory` with `noeviction` | `used_memory` ≥ 90% of `maxmemory` and policy `noeviction`: writes will be refused |
| Connections near the limit | ≥ 80% of `max_connections` |
| Disk full in N days | linear fit of 14 days of size samples reaches the volume size within 30 days |
| Enable query insights | pg / mariadb with `query_insights: off` |

### Alerts (Alerting, `databases.*`, in the default rule pack's Databases area)

| Type | Condition (AlertConditions, per instance) |
|---|---|
| `databases.slow_queries_spike` | ≥ `databases.observability.slow_spike_per_minute` (30) slow queries a minute, held 5 minutes |
| `databases.replication_lag` | lag ≥ 60 s, held 5 minutes |
| `databases.deadlocks` | deadlocks in the last minute: raised directly (`Alerts`), deduplicated per instance and hour (`databases.deadlocks:<id>:<YmdH>`), no recovery |
| `databases.memory_high` | Redis / Valkey `used_memory` ≥ 90% of `maxmemory`, or the container's memory ≥ 90% of its limit, held 5 minutes |

### UI (database panel tabs)

- **Metrics** (`GET /databases/instances/{id}/metrics/data?range=`): per engine — throughput (transactions / queries /
  ops per second), cache hit %, connections by state against the limit, rows, lock waits and deadlocks, slow queries,
  replication lag, database sizes; Redis memory against `maxmemory`, evictions; and the phase 1 container CPU and
  memory against the server and the instance's limits (`falak_workload_*{falak_db_instance_id="<id>"}`).
- **Logs** (`GET /databases/instances/{id}/logs/data?kind=all|slow|engine&search=`): engine and slow-query records.
- **Insights** (`GET /databases/instances/{id}/insights?range=`): query insights status (and the enable action,
  `POST /databases/instances/{id}/query-insights`, `databases.manage`), open Issues, top queries by total time (calls,
  mean, max, rows), recommendations. `reporting` tells the UI why there is nothing: `agent_outdated` (no
  `db.observability`), `image_outdated` (no report although the agent reports: an image without `observe`), `stopped`.

Permissions: the tabs need `databases.view`; Logs and Metrics also `telemetry.view` as the site tabs do.

Config (`databases.observability`): `retention_days` 30, `slow_spike_per_minute` 30, `replication_lag_seconds` 60,
`memory_percent` 90 (Redis / Valkey `used_memory` against `maxmemory` only with `noeviction`, else the container's working set against its limit), `alert_seconds` 300, `auth_failed_min` 5, `issue_min_calls` 5, `issue_min_calls_kv` 20, `stale_seconds` 600 (a report older than this is not
`ok`).

### Images

No Dockerfile changes: `pg_stat_statements` is in the official PostgreSQL images (contrib). The images must be rebuilt
for the new `falak-db` (`observe`, `reload`, `query-insights.json`); `.github/workflows/db-images.yml` does on `main`,
weekly and on release tags, and running instances pick it up through a minor upgrade. `images/db/test.sh` checks
`observe` (and `reload`) on every engine: a slow MySQL / MariaDB query and a slow Redis / Valkey `EVAL` read back
normalized through the cursor, PostgreSQL started with query insights.

## Phase 4: network (v0.17.x)

Everything about traffic: what reaches a site, what it talks to, who attacks it, whether it is up, and tools to debug
it. Five workstreams, built in parallel on their own branches and released as each is ready (v0.17.0, v0.17.1, …).
Each stream writes its detailed design in its own file under `docs/plans/network/` (this section is only the index and
the shared rules), gets its own independent review, and is checked live on cloud.falak.sh.

| Stream | Plan | Scope |
|---|---|---|
| A. Connections | [`network/connections.md`](network/connections.md) (feature `net.connections`; `falak.net.*`, `net.observed`, Insights kind `security`, slot `network.ip-actions`) | Agent network collector: TCP/UDP connections by state, listening ports, "talks to" peers with bytes and counts (per container, native site, server), WebSocket / long-lived connection counts, conntrack table usage, exposed-ports audit (public listeners vs firewall → security Issue), server-to-server and app-to-database latency over WireGuard and WireGuard peer health. Network tab on sites, containers and servers. |
| B. HTTP analytics | `network/http.md` | From the edge access logs: requests / status / bytes / top paths / top clients, slow endpoints → performance Issues without the APM package, bandwidth per site per month with an alert, countries and ASN map (DB-IP lite, downloaded by the control plane), bot and scanner detection (probes of `/.env`, `/wp-admin`, `/.git`, injection patterns, 404 storms → a "Threats" view and Issues), TLS versions and HTTP protocol of clients, request ID linking (`X-Request-Id` stamped by the edge, access line → app logs and trace). |
| C. Protection | `network/protection.md` | Act on what A and B find: block an IP or CIDR (nftables sets with timeouts, dropped at raw prerouting so published container ports are covered) or rate-limit a path at the edge (Falak's Caddy build with caddy-ratelimit, installed on first use; FrankenPHP edges refused) from the panel, with expiry and audit; guards that never block the requester, members' recent addresses, the control plane, servers, networks, DNS servers or gateways; SSH brute-force from the auth log (anchored patterns, one failure per connection) with optional auto-ban (fail2ban-like, built into the agent); per-rule firewall hit and drop counters. Registers "Block" in the `network.ip-actions` slot A and B render on their rows. |
| D. Uptime | `network/uptime.md` | Control-plane checks of every site domain (HTTP status and body match, TCP, TLS handshake and chain validity) every minute from the control plane, uptime %, incident history, alerts on down / recovered / certificate chain problems, optional public status page per project. |
| E. Tools + Cloudflare | `network/tools.md` | Network tools in the panel through the terminal relay: `ping`, `traceroute`/`mtr`, `dig`, `curl -v` and "test connection to host:port" from a server or inside a container, streamed live, audited, own permission. Cloudflare analytics for proxied zones (cached vs origin, threats blocked, countries) through the existing Cloudflare connection. |

**Shared rules (all streams):**

- **Metrics:** `falak.net.*`, bounded labels only (top 20 peers or paths per workload per minute, the rest `other`;
  no raw client IPs as metric labels: IPs live in Loki records or control-plane tables).
- **Agent features:** one per stream (`net.connections`, `net.protection`, `net.tools`; B and D are control-plane
  only unless they need the edge: `edge.request_id`). PayloadCompatibility strips the new fields for older agents.
- **Issues:** new Insights kinds `performance` (B), `security` (A exposed ports, B scanners, C brute force), `uptime`
  (D), through additive contracts like `DatabaseIssues`.
- **Alerts:** through Alerting events and the default rule pack, with sane thresholds so they don't fire constantly.
- **Read-only by default:** nothing changes traffic unless the user acts (block, rate-limit, auto-ban are opt-in).
  Blocking can never lock the user out of the panel or SSH from their current IP (refuse, or require confirmation).
- **Shared files:** `agent/internal/version/version.go`, Fleet `PayloadCompatibility.php`, `contracts/agent-protocol/README.md`
  and this index are touched additively only; each stream keeps its UI in its own panels/files.
- **Order of merge:** A and B first (C hooks into both), then C, D, E.

## Phase 5: eBPF and packet capture

Two streams, built in parallel on their own branches, each with its own plan under `docs/plans/network/`; phase 4's
shared rules (bounded labels, one agent feature per stream, additive shared files, read-only by default) apply.

| Stream | Plan | Scope |
|---|---|---|
| F. eBPF | [`network/ebpf.md`](network/ebpf.md) (feature `net.ebpf`; command `net.ebpf.configure`, `facts.ebpf`; `falak.net.tcp.*`, `falak.net.dns.*`, `falak.workload.runqueue.delay`, `falak.ebpf.*`) | Opt-in per server (default off) CO-RE probe (cilium/ebpf, bpf2go objects committed): TCP retransmits, resets, connect latency and RTT per workload and peer; DNS queries, latency, failures and top names per workload; run-queue delay; in-kernel aggregation in bounded LRU maps, attributed by cgroup id / pid / uid; self-measured, auto-disabled over 1 % of CPU; TCP & DNS section on the Network tabs; DNS failure and retransmit alerts |
| G. Packet capture | [`network/capture.md`](network/capture.md) | On-demand capture: a short `tcpdump` on one container or port, downloaded as pcap; its own permission, audited |

## Agent overhead budget

Under ~1% of one CPU on a small server at the 15 s interval: one `/proc` pass, a handful of file reads per cgroup,
no Engine API calls per sample except listing containers.
