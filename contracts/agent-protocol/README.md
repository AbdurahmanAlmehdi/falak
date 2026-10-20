# Agent protocol v1

Shared contract between the control plane (Fleet module, PHP) and `falak-agent` (Go).
Both sides validate against these schemas in their test suites.

## Authentication
1. **Enroll** (`POST /agent/v1/enroll`): plain TLS + one-time token. Agent sends a CSR; private key never leaves the host.
2. **Everything else**: mTLS. The edge (Caddy/FrankenPHP) verifies the client cert against the Falak CA and forwards
   `X-Falak-Client-Cert-Fingerprint` (SHA-256 of the DER cert, lowercase hex). Fleet matches it to an enrolled, non-revoked agent.
   Requests without a matching fingerprint get `401` with `{ "message": "...", "error": "<reason>" }`. Reasons:
   `agent_revoked` (this agent was revoked or its server was removed from Falak; the machine needs a new install
   command), `certificate_revoked`, `certificate_expired`, `unknown_certificate`, and two that point at the edge or
   proxy setup rather than the agent: `missing_certificate` (no fingerprint forwarded) and `untrusted_peer` (the
   request did not come from a trusted proxy). Agents log `agent_revoked` as one clear, rate-limited error and back
   off; control planes before it send no `error` (a plain `401`).
3. Certs are valid 90 days; the agent renews via `POST /agent/v1/renew` (new CSR, authenticated by the current cert) when < 30 days remain.

## Endpoints
| Method | Path | Body | Response |
|---|---|---|---|
| POST | `/agent/v1/enroll` | `enroll-request` | `enroll-response` |
| GET  | `/agent/v1/ping` | — | `{ "agent_id": "...", "time": "<ISO 8601>" }` (for `falak-agent check`: no heartbeat, no session; like every mTLS request, a certificate's first use sets `first_used_at` and retires the certificates it superseded) |
| POST | `/agent/v1/renew` | `{ "csr_pem": "..." }` | `{ "cert_pem": "..." }` |
| POST | `/agent/v1/heartbeat` | `heartbeat` | `204` |
| GET  | `/agent/v1/commands?wait=30` | — | `{ "commands": [envelope...] }` (long-poll, returns early when a command is queued) |
| POST | `/agent/v1/commands/{id}/events` | NDJSON of `event` | `204` (idempotent on `(command_id, seq)`) |
| POST | `/agent/v1/insights` | NDJSON of insight events | `204` |
| POST | `/agent/v1/requests/{type}` | `requests/<type>.schema.json` | the type's `$defs/reply` (`404 unknown_request`, `409 {error: <reason>}` when refused, `422` when invalid) |
| POST | `/agent/v1/uploads/{type}/{id}?offset=N` | raw chunk (`application/octet-stream`, `Content-Length` required, at most 8 MiB), `X-Falak-Chunk-Sha256`, plus the type's `X-Falak-Upload-*` headers | the type's JSON reply (`404 unknown_upload`, `411`, `413`, `422 chunk_checksum`, `409 {error: <reason>}` when refused); `net.capture` (docs/plans/network/capture.md) |

## Command payloads
`commands/<type>.schema.json` — one schema per command type in the catalogue in `ARCHITECTURE.md` §3.
Owned by the agent implementation; the control plane builds payloads against them.

## Evolving payloads (features)
Agents decode payloads strictly: an unknown field fails the command. A new **optional** field therefore needs a
feature name: the agent lists it in `facts.features` (`agent/internal/version.Features`) and the control plane
removes the field for agents that do not (`Fleet\Application\PayloadCompatibility::FIELDS`; `UNLESS` keeps it only
in the list items whose sibling flag is true). When an agent reports a new version (`Fleet\Events\AgentVersionChanged`),
modules re-send state they would otherwise deduplicate.
Current features: `edge.access_log`, `telemetry.log_kind`, `system.upgrade_agent.v2`, `fn.v1`, `fn.v2`, `fn.v3`,
`compose.v2`, `docker.networks`, `docker.networks.create`, `compose.up.services`, `provision.v2`, `db.instances`,
`terminal.v2`, `telemetry.workloads`, `telemetry.workloads.shared`, `db.observability`, `net.protection`, `edge.rate_limit`, `edge.caddy_dns`.
Logs (docs/plans/OBSERVABILITY.md phase 2): `logs.container_files` (telemetry.configure `log_sources[]` `container`,
`compose_service`, `multiline_pattern`, `origin`; container sources are removed whole for older agents,
`PayloadCompatibility::DROP`), `runtime.fpm.slowlog` (runtime.fpm.pool `slowlog_timeout`).
Network (docs/plans/network/): `net.connections` gates the whole command `net.connections.configure` (latency probes,
peer names, WireGuard peer names) and the agent's `net.observed` request (connections, listeners, peers, conntrack,
WireGuard, probes; `requests/net.observed.schema.json`).
HTTP analytics (docs/plans/network/http.md): `edge.request_id` (edge.caddy.apply `request_id`: X-Request-Id stamped by
the edge; stripped for older agents, whose rendered config is then unchanged).
Network tools (docs/plans/network/tools.md): `net.tools` gates the whole `net.tool` command (ping, traceroute, DNS,
HTTP, TCP from the host or, with `container`, inside a container's network namespace; output as stdout events).
Packet capture (docs/plans/network/capture.md): `net.capture` gates the whole `net.capture` command (tcpdump on a host
interface or in a container's namespace), its `net.capture.progress` request (whose reply can cancel it) and the
chunk uploads to `/agent/v1/uploads/net.capture/<capture_id>`.
eBPF (docs/plans/network/ebpf.md): `net.ebpf` gates the whole `net.ebpf.configure` command (`{enabled, full_dns_names?,
reenable?}`, opt-in per server; persisted in `/etc/falak/ebpf.json` with an over-budget stop that only `reenable`
clears; its result is the probe's status) and the agent's `facts.ebpf`
(`facts.schema.json#/$defs/ebpf`: supported / reason, state, programs, overhead).

A feature can also gate a whole **command**: the control plane only queues it for agents that list the feature
(older agents would fail it as an unknown type). `provision.v2` adds `provision.inspect` and `provision.apply`
`components`.

## Machine check (`provision.v2`)
`provision.inspect` is read-only: it reports the software already on the machine and where it came from
(`$defs.result`: packages with their origin — `archive`, `vendor` with the repository URL, `manual` —, snaps, apt
sources, systemd units, TCP listeners with process, unit and container proxies, containers' published ports,
Docker, sshd, firewalls, swap, Node / PHP / FrankenPHP binaries, unattended-upgrades, fail2ban). A detector that
fails leaves its part empty and adds an `errors` entry; the command only fails when cancelled. Extra package
patterns (the plan's base packages) can be passed in `packages`. The inspector never executes a file root does not
own (it and every directory on its path must be root-owned and not group/world-writable): versions of user installs
come from directory names and package metadata. Repository URLs and errors carry no URL credentials. A package whose
installed version no repository offers takes the origin of the repository offering the package; with no package
lists at all the origin is `unknown`. It runs a file by its symlink-resolved path, checked component by component; authorized_keys
files are opened without following symlinks (O_NOFOLLOW|O_NONBLOCK, regular files only, first MiB, key lines only).
`falak-agent features` prints the build's features (the installer only points at the machine check for
`provision.v2`). Login users carry their groups, and the effective `AllowUsers` / `DenyUsers` /
`AllowGroups` / `DenyGroups` are reported for the lockout rule.

The control plane decides per component (`install`, `adopt`, `complete`, `block`; see `docs/plans/MACHINE_CHECK.md`)
and sends no `provision.apply` while anything blocks. The plan already reflects the decisions; `components` tells the
agent which components were adopted: their `packages` are never installed (removed from the apt step, verified in an
`adopt:<name>` step that fails when one is gone), an adopted `swap` / `hostname` is kept, and an adopted
`unattended_upgrades` gets no Falak config. `components` is stripped for agents without `provision.v2`, which also
never get `provision.inspect` and keep today's plan.

## Database containers (`db.instances`)
Every managed database (PostgreSQL, MySQL, MariaDB, Redis, Valkey) is a container `falak-db-<instance>` of a Falak
database image (`docs/DB_IMAGES.md`); nothing runs on the host. The agent drives it through the Docker Engine API and
`docker exec <ctr> falak-db <op>`; SQL and passwords never appear in an argument.

- `db.instance.create` / `db.instance.update` (redeliverable) converge the container to `instance`: the image (a
  `digest` pin is pulled as `<repository>@<digest>` and checked against RepoDigests; without one the tag is pulled again
  and a newer image recreates the container — minor upgrades), data on the sized volume `volume_id`
  (`<volume>/data` at the engine's data directory, `<volume>/spool` at `FALAK_DB_SPOOL`; the agent waits up to 120 s
  for the volume to be mounted), `memory_bytes` / `cpus`, PostgreSQL's `/dev/shm` (a quarter of the memory, 64 MiB–1
  GiB), restart `unless-stopped`, a 60 s stop timeout, the `falak-db health` healthcheck, `settings` as
  `FALAK_DB_SETTINGS` (TLS off when no certificate is installed). It joins `network` (an environment's
  `falak-env-<id>`, created when missing) under `aliases` (default `falak-db-<id>`) and publishes the engine on
  `127.0.0.1:<host_port>` plus `publish.addresses` (private and CGNAT IPv4 only; `publish.public` binds every
  address). The password goes to `/run/falak/secrets/falak-db-<id>/password` (tmpfs, 0444 in a 0555 directory under
  a 0700 parent; a `Mounts` bind at `/run/secrets`, read-only) and reaches the engine as its `*_FILE` variable. `tls`
  (create only) is written to `/etc/falak/db/<id>/tls` (root, 0600) and mounted read-only; updates keep it. The
  container is recreated (on the same volume) when its spec hash or image changes, and the command waits until it is
  healthy (300 s; the failure carries the log tail). The image's cosign signature is not verified yet.
- `db.instance.restart`, `db.instance.stop`, `db.instance.delete` (container, secret files and certificate; the volume
  is `volume.delete`'s).
- `db.instance.password` rotates the superuser / root / default password: `.password.new` in the secrets directory,
  `falak-db password set --file`, then it replaces `password`. `db.instance.secrets` puts the file back after a reboot
  (heartbeat `databases[].secrets_missing`) and starts the container; a present directory is never touched (it may be
  mounted).
- `db.instance.upgrade` (`mode: major`, SQL engines): for each database, `falak-db database create` on the target, then
  `falak-db backup logical` on the source piped into `restore logical` on the target; the users are applied there,
  the target takes the source's DNS alias on the network, and the source stops.
- `db.create` / `db.drop` / `db.user.apply` (SQL engines) run `falak-db database create|drop` and `falak-db user apply
  --spec <file>`; the spec, with the password, is a dot-file in the instance's secrets directory, removed afterwards.
- `db.backup` streams `falak-db backup logical` (pg_dump `-Fc`, mysqldump / mariadb-dump, an RDB snapshot) through
  zstd and AES-256-GCM (FKB1, `encryption`; docs/BACKUPS.md) to the presigned URL; falak-db's `falak-db-result:` line
  must be there. The result has the stored file's size and sha256, the dump's `uncompressed_bytes` and
  `plaintext_sha256`, and with `table_counts` the row counts taken before the dump. `db.restore` checks the file's
  sha256, opens it with `encryption` (the key, or the customer's age identity) and pipes it into `falak-db restore
  logical` (postgres `--swap`; MySQL / MariaDB after `database create`); a segment that fails authentication fails the
  restore. Redis / Valkey stop, a one-off `docker run --rm -i --network none --entrypoint falak-db` of the instance's
  image on its data directory replaces the snapshot, and the instance starts again (also when the restore failed).
- `db.drill` restores a backup into a throwaway container (`falak-db-drill-<id>`, label `falak.db.drill`): the
  instance's image digest, its memory limit from the payload, `--network none`, no port, a scratch directory under
  `/var/lib/falak/drills/<id>`. It checks tables (keys), the 10 largest tables' row counts against `table_counts`
  (± `tolerance_percent`) and the optional `query` (`falak-db query`, read-only), then removes the container, its data
  and its password file whatever happened. A server without the memory or disk for it answers `status: skipped` with
  the reason; failed checks are `status: failed` (the command itself succeeds).

The heartbeat's `databases` lists every container labelled `falak.db.instance` with its state, health and
`secrets_missing`. `provision.apply` `docker.live_restore` merges `"live-restore": true` into
`/etc/docker/daemon.json` and reloads dockerd, so databases keep running while Docker restarts or is upgraded.
`docker.compose.up` `join_networks` and `networks[].environment` (`docker.run`, `deploy.container.swap`) put apps on
their environment's network, where the databases answer by name.

## Point-in-time recovery (v0.10.0)
Instances whose spec has `pitr.enabled` (written to `/etc/falak/db/<id>/pitr.json`; without it the spool is emptied)
have their spool shipped by the agent (`agent/internal/db/pitr.go`, docs/BACKUPS.md "Point-in-time recovery"):

- MySQL / MariaDB: `falak-db binlog-rotate` every 60 s (skipped while the binlog being written did not grow); exit 4 is
  reported once with `pitr.gap`, and after a `reset` the next `db.pitr.base` runs `binlog-rotate --restart`.
- Completed spool files (never `.` files) go in batches: `pitr.upload_urls` (`{instance, kind: wal|binlog,
  segments: [{name, bytes, sha256}]}` → `{segments: [{name, id, url, encryption} | {name, id, shipped: true}]}`: a URL
  and a key or recipient per segment, `encryption.key_id` = the segment id), FKB1 + PUT, then `pitr.shipped`
  (`{instance, kind, segments: [{id, name, size_bytes, sha256, plaintext_bytes, plaintext_sha256, end_time}]}` →
  `{acknowledged: [id]}`: only once a HEAD finds the object with the reported size and, up to 64 MiB, SHA-256). A spool
  file is deleted only once acknowledged; the spool is read beneath the volume without following symlinks. Refusals:
  `too_many_pending`, `too_many_gaps`, `unknown_instance` (not this
  server's, or the wrong kind), `pitr_disabled`, `bad_recipient`. Failures back off with jitter; the heartbeat reports
  `databases[].pitr`.
- `db.pitr.base`: `falak-db backup physical` like `db.backup` (result: `started_at`, `finished_at`, `start_wal`,
  `stop_wal` / `start_binlog`). `db.pitr.restore`: a new instance from a base and segments (each checked, decrypted
  and fully verified before use; `segments.*.encryption.key` and `identity` forgotten when it settled), recovered to
  `target_time`, read-only, with row counts. `db.pitr.promote`: `readonly off`, and with `stop` the replaced instance
  stops.

## Database observability (v0.16.0)
Agents with `db.observability` run `falak-db observe` in every running database container once a minute
(docs/plans/OBSERVABILITY.md phase 3) and send `db.observed` (`{instances: [{id, engine, at, window_s, gauges, deltas,
errors, statements, …}]}` → `{accepted}`), one request per server; query text in it is normalized. Control planes
without the type answer 404 and the agent keeps the metrics and logs only. `db.instance.create|update`
`instance.query_insights` writes `/etc/falak/db/<id>/conf/query-insights.json` (pg_stat_statements / MariaDB
performance_schema at the next start; a running container restarts for a change only when the update carries `apply_query_insights`, the user's action). A settings change that only moves
`slow_query_ms` runs `falak-db reload` instead of a restart (SQL engines; an image without it is restarted).

## Network protection (`net.protection`, v0.17.x)
docs/plans/network/protection.md. `net.protection.apply` (only queued for agents with the feature) carries the server's
full desired state: active `blocks` (`{cidr, expires_at}`), `release` (auto-bans a user lifted), `never_block` (the
organization's servers, private networks and the control plane) and `auto_ban`. The agent keeps them in
`/var/lib/falak/protection.json` and applies them element by element to the sets `falak_block4` / `falak_block6`
(nftables timeouts) of its own table `inet falak_protect`, dropped at prerouting priority raw (before Docker's DNAT);
`table inet falak` is never touched by it. `net.firewall.apply` `counters` (stripped for older agents) puts a counter on
every rule. Once a minute the agent sends `net.protection.observed` (sshd failures per source, its auto-bans, rule
counters, block hits); control planes without the type answer 404 and the agent keeps the metrics only.
`edge.rate_limit` adds `edge.caddy.apply` `sites[].rate_limits` (`{path, events, window_s}`, only on sites with rules)
and `caddy_build` (`{url, sha256}`, only with rate limits): the agent installs Falak's Caddy build (caddy-ratelimit)
when the edge's Caddy lacks `http.handlers.rate_limit`; rate limits it cannot run (FrankenPHP edge, no or failed build) are left out of the applied config and the result says why (`rate_limits_refused`).
`edge.caddy_dns` sends `caddy_build` for DNS-01 certificates too (a domain's `tls.dns`, `wildcard_certificates`): the
agent installs the build when the edge's Caddy lacks the `dns.providers.<provider>` module, and an edge that cannot get
it applies the config with those certificates over HTTP-01 (`rate_limits_refused` says why) instead of refusing it whole.

## Secrets on servers (v0.10.0)
**Env files on tmpfs.** `deploy.prepare` writes `env_file` to `/run/falak/env/<site>.env` (directory 0711 root, file
0440 owned by the site user and its group, which the edge user joins for FrankenPHP) and makes `shared/.env` a symlink
to it, replacing the regular file earlier agents kept; every release's `.env` links to `shared/.env`. The link is
made through an `os.Root` on the site directory and refused when `shared/` or a directory on the path is a symlink
(the site user owns the site directory). Laravel's config cache holds the resolved secrets too: with `config_cache`
the agent gives each release a tmpfs directory `/run/falak/env/<site>.d/<release>` (0750, site user and group) linked
as `<release>/.falak-cache`, and the control plane sets `APP_CONFIG_CACHE=.falak-cache/config.php` in the `.env`
(Laravel resolves it against the release, so each release keeps its own cache). PHP-FPM pools of isolated sites get
both tmpfs paths in `open_basedir` (PHP checks the resolved path);
the control plane re-applies the pools when an agent reports a new version. Nothing with a secret is written under
`/srv/falak/sites`.

**Compose projects.** `docker.compose.up` / `.pull` write their `.env*` files to `/run/falak/env/compose-<project>.env`
(0400 root); the release directory links to it and `--env-file` points at it.

**Secret files for containers.** `docker.run` and `deploy.container.swap` take `secret_files`: written to
`/run/falak/secrets/<container>/<NAME>` and bind-mounted read-only at `/run/secrets` (a `Mounts` bind, which never
creates a missing source). The parent directory is 0700 root, so no other host user reaches them; inside the container
they are 0444 in a 0555 directory (the image's user is unknown), or 0400/0500 owned by a numeric `user`. The
variables passed as files are not in the container's env, so `docker inspect` doesn't show them. Each color of a swap
has its own directory, removed with the container.

**Supervised programs and cron jobs.** `proc.apply` programs and `cron.apply` jobs name their secret variables in
`mask`. The agent's state files (`/var/lib/falak/proc.json`, `cron.json`) keep only the other variables and the names
of the secret ones; the values are in `/run/falak/state/{proc,cron}-secrets.json` (0600). After a reboot a program or
job whose secrets are gone does not start: it waits, its site is reported in `missing_secrets`, and the control plane
answers with a forced `proc.apply` / `cron.apply` for the server.

**After a reboot** `/run` is empty: Docker can't start a container whose secret directory is missing, and env links
dangle. The agent remembers every env link it made (`/var/lib/falak/env-links.json`, paths only: any sites root,
compose releases) and every heartbeat carries `missing_secrets` (at most 500 site slugs) until they are restored. The
control plane answers with `site.env.write` for the live release (`release_id`), unless a deployment of the site is
running, at most once per server and site every two minutes: the `.env` (classic sites; then `after` runs
`artisan config:cache` for Laravel and `reload` reloads PHP and restarts the site's programs, `site_procs`), the compose
env file (`compose`), or the secret files of a container site in the files mode. The agent only restores what is
missing (an existing file or directory was written by a deployment since, and may be mounted), refuses a release that
is no longer current, and starts the containers that could not start without their files.

**Output masking.** Payloads that carry secrets list the names of their secret variables in `mask`
(`deploy.prepare`, `deploy.hook`, `deploy.container.swap`, `docker.run`, `docker.compose.up`, `docker.compose.pull`,
`site.env.write`, `fn.release.apply`, `system.exec`, and each `proc.apply` program); the values are never sent twice.
The agent takes the values from the same payload (env maps, dotenv content, every `secret_files` content) and, for
`deploy.hook` and `system.exec` with `site`, from the site's env file, which scripts read. Each value (6 bytes or
more; 4 in build logs) becomes `••••` as is and in the forms it commonly leaks in: base64 (standard and URL alphabets,
at all three byte alignments inside a longer encoding), URL-encoded, lower-case hex, JSON-escaped (Go and PHP styles)
and quoted for a single-quoted shell word; matches split across writes are masked too (at most 8 KiB is held back).
This covers command output, errors, results, deployment lifecycle events and supervised programs' log files and OTLP
records. Build jobs take the same `mask` for `env` and `build_args`.

## Volumes (v0.10.0)
`volume.*` commands name a volume by `{id, kind, name?, path?}`: `docker` (a named volume, `name`), `sized` (an ext4
image `/var/lib/falak/volumes/images/<id>.img` loop-mounted at `/var/lib/falak/volumes/<id>` by the systemd unit
`var-lib-falak-volumes-<id>.mount` — systemd names a mount unit after its path — so the mount survives reboots),
`bind` (a host path within the agent's `FALAK_VOLUME_BIND_ALLOW`, colon-separated; empty refuses bind volumes) and
`shared_path` (a classic site's `<sites root>/<site>/shared/<path>`). `volume.create` and `volume.resize` (grow only:
`fallocate`, `losetup -c`, `resize2fs` online) are idempotent; `volume.delete` waits up to `wait_s` for running
containers that mount the volume (by name, or by host path) to go away and refuses otherwise unless `force`; bind
paths are never deleted. `volume.inventory` reports usage (statfs for mounted sized volumes, a du-style walk that
never follows symlinks otherwise) and the server's Docker volumes.

Snapshots (`volume.archive`) are tar streams compressed and encrypted like database backups (FKB1, `encryption`), PUT
to a presigned URL (sha256 and size of the stored file, the tar stream's `plaintext_sha256`, the signature never
echoed); `volume.drill` restores one into a scratch directory next to the volumes, checks its file count, and removes
it; `consistency` pauses or stops the running containers that mount the
volume while it is read. `volume.restore` and `volume.clone` write into a new or empty volume only (created when
missing), check the sha256 before anything is written, and extract through an `os.Root`: absolute names, `..`
segments, writes through symlinks and hard links out of the volume are refused. `volume.browse` (list, or a name
search) and `volume.download` (a file as is, a folder as `.tar.zst`, capped by `max_bytes`) refuse any path with a
symlink in it.

Bind and shared-path directories may be writable by others (a site's user owns its site directory), so the agent
never re-walks their path as a string: it opens them once from a trusted anchor (the allowlist entry — the entry
itself is refused, only paths strictly below it — or the sites root) one component at a time, refusing symlinks and
directories swapped while being opened, and every later access (browse, archive, download, du, delete) goes through
that handle. `volume.create` refuses an existing Docker volume that does not carry the volume's `falak.volume.id`
label unless `adopt: true` (restores and clones never adopt). `volume.archive` with `consistency: stop` and
`keep_stopped: true` leaves the stopped containers stopped after a successful upload (moves). Snapshots and
downloads are staged in `<volumes root>/.staging` (0700, the volume store's filesystem, never /tmp) after a free-space
check (estimate + 10%); `volume.restore` aborts a download larger than `archive_bytes` + 1 MiB and never unpacks more
than `uncompressed_bytes` + 1%. A sized volume's empty mountpoint is made immutable (`chattr +i`) before it is
mounted, so containers bound to it while the mount is missing cannot write to the host's disk; the mount unit is
ordered `Before=docker.service`. `volume.resize` to the current size still runs `losetup -c` and `resize2fs`, so a
resize that failed half way can be retried.

## Security baseline (v0.10.0)
`security.audit` (redeliverable, read-only) runs every check within 55 s, each command with its own short timeout,
file walks bounded (depth and entries) and never following symlinks, without network calls: `apt-get -s upgrade` reads
the package lists already on disk. The payload carries what the control plane expects — `managed_keys` (per unix
user; other authorized keys are reported by fingerprint, never by content), `expected_ports` (what the firewall
accepts; public listeners outside them are reported), `known_users`, `ssh_port`. Evidence is short and secret-free.

`security.fix {fix_id}` runs only fixes compiled into the agent (`agent/internal/security/fixes.go`); the control
plane can't send commands or paths. Each fix is idempotent (`changed: false` and no backup when there is nothing to
do), saves every file it touches under `/var/lib/falak/security-backups/<backup_id>/` (`manifest.json`: path, mode,
owner, sha256 and a copy; secret files' permission fixes record mode, owner and inode only, never their content),
validates (`sshd -t` and `sshd -T`, `apt-config dump`, `fail2ban-client -t`, `dockerd --validate`, `sysctl -p`) and
restores the backup on failure. `ssh.harden` refuses when no login user has a key, writes every hardened setting
into `50-falak.conf` and comments out conflicting lines elsewhere (an earlier drop-in such as `50-cloud-init.conf`
wins otherwise). Container secret files stay 0444 (their 0700 parent and 0555 / 0500 directories are what is checked); the permissions fix opens files component by component without symlinks and skips hard-linked files and owners other than the site's user. `ssh.harden` also needs a user sshd lets in (`sshd -T -C`) with a shell key Falak did not install for its falak user (`managed_keys`), refuses two-factor setups, and restarts sshd after a rollback. `security.undo {fix_id, backup_id, force?}` refuses files changed since the fix unless `force`; it restores the backup exactly (a copy whose checksum does not
match, or a secret file swapped since, is refused) and reloads what the fix changed; backups older than 7 days are
pruned. `firewall.apply` and `firewall.close_port:<proto>:<port>` are applied by the control plane (Network owns the
firewall); the agent refuses them.

## Agent sessions and lost deliveries
Every `falak-agent` process sends a random session id (`X-Falak-Agent-Session: s-<32 hex>`, 8-64 characters of
`[A-Za-z0-9._:-]`) on every mTLS request. Agents from before sessions send none; that is accepted.

- A command records the session it was delivered to. When a request arrives with a **new** session (the agent
  restarted: upgrade, crash, reboot), commands still `delivered` or `running` under an older session were lost.
- Only the current session claims commands: a long-poll the old process abandoned keeps waiting on the server, and
  it no longer takes commands meant for the new process.
- A `delivered` command the agent does not report as started, running (heartbeat `running_commands`) or finished
  within the lease (`FALAK_AGENT_COMMAND_LEASE`, default 90 s) was lost too.

A lost command whose schema has `"x-falak-redeliverable": true` at its root is queued again (up to 5 deliveries);
the agent answers a command id it already finished from its journal, so nothing runs twice. Redeliverable:
declarative state (`edge.caddy.apply`, `edge.cert.install`, `telemetry.configure`, `proc.apply`, `cron.apply`,
`net.firewall.apply`, `net.wireguard.apply`, `db.user.apply`, `db.instance.create`, `db.instance.update`,
`db.instance.stop`, `db.instance.delete`, `db.instance.secrets`,
`system.ssh_key.sync`, `site.env.write`), read-only commands
(`proc.status`, `system.facts`, `docker.compose.ps`, `provision.inspect`) and `system.upgrade_agent` (a no-op once
installed). Any other type fails instead, so the deployment waiting on it fails fast: `failed` with "The agent
restarted before running the command" when it was never started, `timed_out` otherwise (a late result still
overrides a `timed_out`).

On shutdown the agent stops long-polling first and does not start commands from a response that arrives while it
stops; running commands get 20 s to finish (heartbeats keep reporting them) before they are cancelled.

## Sandboxes (`sandbox.v1`, v0.20.0)
`sandbox.*` commands drive gVisor sandboxes with `runsc --root=/run/falak-sbx` directly (docs/plans/SANDBOXES.md
"Contracts"): container `falak-sbx-<id>`, state under `/var/lib/falak/sandboxes/<id>`. `sandbox.create` (runs
`sandbox.prepare` when needed), `sandbox.kill` and `sandbox.extend {id, expires_at}` (result `{expires_at}`; how
the control plane extends a running sandbox, and one whose create was still running when it was extended) are
redeliverable and idempotent by id. `sandbox.inventory` reports a sandbox whose create is still running as
`creating`. `sandbox.exec` and `sandbox.files.*` answer within the request (the control plane waits for them);
stdout, stderr and file contents are base64. A schema with `"x-falak-forget-result": true` at its root
(`sandbox.exec`, `sandbox.files.read`, `sandbox.files.list`) has its result forgotten (the command's and its events')
once the waiting request read it, or by the fleet sweep when nobody did. `sandbox.create`'s image is refused with
`..` or a trailing dot; `repo.token` is only offered to the repository's own host. Errors are failed commands whose error starts with a code: `not_found`,
`is_directory`, `not_directory`, or `sandbox <id> not found` when the agent doesn't know the sandbox. The agent reaps
sandboxes past `expires_at`, out of memory or whose init exited on its own, and reports each with the request
`sandbox.reaped` `{id, reason}` (retried each minute until a 2xx). `terminal.open` takes a `sandbox: {id}` target
(exclusive with `container`). Payload secrets forgotten once settled: create `repo.token` and `env`, exec `env` and
`stdin`, files.write `content`.

## Sandboxes v2 (`sandbox.v2`, v0.21.0)
Snapshots, pause and database copies (docs/plans/SANDBOXES.md "Contracts (v0.21)"); the control plane sends none of
these to an agent without the `sandbox.v2` feature. `sandbox.snapshot {id, snapshot_id, leave_running?}` (redeliverable,
idempotent by `snapshot_id`) is a `runsc checkpoint` into `/var/lib/falak/sandboxes/snapshots/<snapshot_id>` and answers
`{snapshot_id, size_bytes, image_digest, created_at}`; with `leave_running: false` the sandbox is removed afterwards, as
by `sandbox.kill`. `sandbox.snapshot.delete {snapshot_id}` is redeliverable and idempotent. `sandbox.create` takes
`from_snapshot` (restore into a new bundle and netns; `image` is the snapshot's, the sizes and network equal the
snapshot's, no `repo`, `env` adds to the snapshot's; error `snapshot <id> not found`). `sandbox.pause` / `sandbox.resume
{id}` are idempotent and answer `{status: "paused" | "running"}`. `sandbox.db.import {id, instance, engine, database,
path}` streams the instance's logical dump (postgres `pg_dump -Fc`, mysql/mariadb SQL; unencrypted, never on the host
disk) into the sandbox and answers `{path, size_bytes}`. `sandbox.inventory` adds `snapshots: [{snapshot_id,
size_bytes}]` and reports paused sandboxes as `paused`; the reaper leaves paused sandboxes alone except for
`expires_at`.
