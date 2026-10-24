# Falak HTTP API

Three surfaces:

| Surface | Base | Auth | Consumers |
|---|---|---|---|
| Public REST API v1 | `/api/v1` | Sanctum bearer token (`Authorization: Bearer <token>`) | `falak` CLI (`agent/internal/cli/api`), CI, scripts |
| Deploy hooks | `/api/deploy/{token}` | the unguessable token in the URL | CI / chat ops |
| Internal builder API | `/api/internal` | builder token (`Authorization: Bearer kbt_…`) or signed URLs | `falak-builder serve` (`agent/internal/builder`) |

The agent protocol (`/agent/v1`, mTLS) is documented in `contracts/agent-protocol/README.md`.

## Conventions (v1)

- Always send `Accept: application/json`.
- **Tokens** are created under *Settings → API tokens*. A token is pinned to **one organization**; its
  abilities are permission names (`deployments.create`, …) or `*`. A request is allowed only when the token
  has the ability **and** the token owner's role grants the permission.
- **Ids** are lowercase ULIDs. Anything that accepts an id also accepts it uppercase. Sites can be addressed
  by **id or slug** everywhere (`{site}`).
- **Responses** wrap payloads in `{"data": …}`; paginated lists add `links` and `meta`
  (`current_page`, `per_page`, `total`, `last_page`; `?page=`, `?per_page=` ≤ 100).
- **Errors**: `401` missing/invalid token, `403` `{message}` (ability or role missing), `404` `{message}`
  (not found *or* in another organization), `422` Laravel validation body
  `{"message": "…", "errors": {"field": ["…"]}}`, `429` rate limited, `503` backend (Loki) unavailable.
- Timestamps are ISO-8601.

### Permissions (token abilities)

| Permission | Roles | Grants |
|---|---|---|
| `sites.view` | admin, developer, viewer | list/show sites |
| `sites.env.view` / `sites.env.manage` | admin, developer | read / replace the site environment |
| `sites.delete` | admin | delete sites (`DELETE /api/v1/sites/{site}`) |
| `deployments.view` | admin, developer, viewer | deployments, output, releases |
| `deployments.create` | admin, developer | deploy, cancel queued/waiting/building deployments |
| `deployments.rollback` | admin, developer | roll back to an earlier release |
| `deployments.manage` | admin, developer | strategy, health checks, retention, push-to-deploy, deploy hooks (UI) |
| `builds.view` / `builds.manage` | view: all; manage: admin, developer | builds, logs, builders / cancel builds, manage builders (UI) |
| `telemetry.view` | admin, developer, viewer | site logs, access logs |
| `servers.view` | admin, developer, viewer | list/show servers (incl. agent version) |
| `fleet.agents.manage` | admin | upgrade server agents |
| `projects.view` / `projects.manage` | view: all; manage: admin, developer | projects + environments / create, rename, delete, duplicate environments |
| `functions.view` | admin, developer, viewer | functions, their code, versions, schedules and runs |
| `functions.deploy` | admin, developer | deploy function code and versions (also needs `deployments.create`), run schedules |
| `secrets.view` | admin, developer, viewer | secret names, metadata, versions (never values) |
| `secrets.manage` | admin, developer | create secrets, set values, roll back, delete |
| `secrets.reveal` | admin, developer | read a non-sensitive value; a token needs this ability **by name** (`*` is not enough) |
| `security.view` | admin, developer, viewer | servers' security baseline reports |
| `security.fix` | admin | run audits, apply and undo baseline fixes |
| `config.view` | admin, developer, viewer | validate and plan falak.yaml, export it, read config runs |
| `config.apply` | admin, developer | create config runs (`falak apply`), cancel them, connect the config source (UI) |
| `config.approve` | admin | approve config runs with risky changes |
| `config.destroy` | admin | `allow_destroy`: let a run delete databases and volumes the file removed |

## Identity

### `GET /api/v1/me`
```json
{"data": {"user": {"id": "…", "name": "Ada", "email": "ada@example.com"},
          "organization": {"id": "…", "name": "Acme", "slug": "acme", "role": "owner"},
          "token": {"name": "cli", "abilities": ["*"]}}}
```

### `DELETE /api/v1/me/token`
Revokes the token making the request (`falak logout`). `204`.

### `POST /api/v1/cli/login` · `POST /api/v1/cli/login/token` — no token
`falak login` in the browser, the [RFC 8628](https://www.rfc-editor.org/rfc/rfc8628) device flow. Start with
`{"client_name": "ada-laptop"}` (rate-limited per address, 10/min):
```json
{"data": {"device_code": "…48 chars…", "user_code": "WDJB-MJHT", "expires_in": 600, "interval": 5,
          "verification_uri": "https://falak.example.com/cli/authorize",
          "verification_uri_complete": "https://falak.example.com/cli/authorize/WDJB-MJHT"}}
```
The person opens `verification_uri_complete`, signs in, confirms their password (and 2FA code) and approves for one
of their organizations. Poll `/cli/login/token` with `{"device_code": "…"}` every `interval` seconds: `400
{"error": "authorization_pending" | "slow_down" | "access_denied" | "expired_token"}` until approved, then once
`200 {"data": {"token": "…", "user": {…}, "organization": {"id", "name"}}}`. The token has the `*` ability (the
user's role decides), is named `CLI — <client_name>` and expires 90 days after its last use, and a year after the login at most. Codes are stored hashed;
a request expires after 10 minutes and its token can be collected once.

### `GET /api/v1/organizations`
Token requests return the token's organization only; session requests every membership.
```json
{"data": [{"id": "…", "name": "Acme", "slug": "acme", "role": "owner", "current": true}]}
```

## Servers

### `GET /api/v1/servers` · `GET /api/v1/servers/{server}` — `servers.view`
`status`: `creating`, `provisioning` (machine check and plan), `needs_attention` (the machine check found a conflict;
nothing was applied; `status_message` lists the conflicts), `active`, `error`, `deleting`. Only `active` servers are
deploy targets.
Addresses: `ipv4`, `private_ipv4` (the server's address on its private network, `null` when it has none; `falak ssh
--private` uses it) and `ssh_port`.
`agent` (null until an agent enrolled) carries `status`, `last_heartbeat_at`, `version`, `available_version` (the
build this control plane ships), `update_available` and `upgrade` (the latest upgrade: `status`
`queued|running|succeeded|failed|cancelled`, `from_version`, `to_version`, `error`, `requested_at`, `finished_at`).

### `POST /api/v1/servers/{server}/agent/upgrade` — `fleet.agents.manage`
Upgrades the server's agent to the shipped build (`system.upgrade_agent` with the panel download URL and its
SHA-256). `202` with the upgrade (`status: running`; the same upgrade when one is already in progress); it succeeds
once the restarted agent reports the new build, fails on a download/checksum/pre-flight error or after
`FALAK_AGENT_UPGRADE_TIMEOUT`. `409` with `message` when it cannot run: no agent, agent offline, no verifiable build
for the server's architecture, or the agent already runs it.
```json
{"data": {"id": "01k…", "server_id": "01k…", "status": "running", "from_version": "v0.3.0", "to_version": "v0.4.0",
          "rollout_id": null, "error": null, "requested_at": "2026-09-28T10:00:00+00:00", "finished_at": null}}
```

### Machine check
Before provisioning (after enrollment, on Re-provision), servers whose agent has the `provision.v2` feature get a
read-only machine check (`provision.inspect`): what is already installed and where it came from. Each component
(`base`, `docker`, `database`, `cache`, `edge`, `php`, `node`, `ssh`, `firewall`, `swap`, `hostname`,
`unattended_upgrades`, `fail2ban`) gets a decision: `install`, `adopt` (use what is there), `complete` (install only
the missing pieces from the same source), `block` (a conflict Falak won't resolve) or `skip` (found, not part of the
stack). When something blocks, the server's `status` is `needs_attention` and nothing is applied. Rules:
`docs/plans/MACHINE_CHECK.md`.

### `GET /api/v1/servers/{server}/inspection` — `servers.view`
The latest machine check and the decisions for the server's current stack. `404` when the server has none (agents
without `provision.v2`, or not enrolled yet).
```json
{"data": {"supported": true, "status": "finished", "purpose": "provision", "checked_at": "2026-10-13T09:12:00+00:00",
  "agent_version": "v0.6.0", "error": null, "command_id": "01k…", "blocking": true,
  "summary": "Machine check: 1 conflict to fix before provisioning. Port 80 is in use by nginx, which Falak's edge needs.",
  "components": [{"component": "edge", "label": "Web server", "decision": "block", "decision_label": "Blocked",
    "severity": "block", "reason": "Port 80 is in use by nginx, which Falak's edge needs.",
    "hint": "Stop and disable it (systemctl disable --now nginx.service) or move it to another port, then re-check.",
    "found": [{"name": "nginx", "version": "1.24.0", "source": "Ubuntu archive"}], "install": [], "keep": [],
    "service": null, "notes": [{"severity": "block", "message": "…", "hint": "…"}]}, …],
  "report": { /* provision.inspect $defs.result */ }}}
```
`status` is `running` (a check is in progress; the previous result stays until it finishes), `finished` or `failed`
(`error`). `components` sort blocks first, then warnings. `install` lists the packages provisioning installs for a
component, `keep` the installed packages an adopted one is made of (verified, never installed).

### `POST /api/v1/servers/{server}/inspection` — update permission on the server (`servers.manage`)
Re-check: runs the machine check again; it never applies anything. `202` with the same shape (`status: running`,
`purpose: check`, no `report`). On a `needs_attention` server the status message follows the result; provisioning
continues from the panel (Provision) once nothing blocks, or with Re-provision. `422` when the agent lacks
`provision.v2` or is not connected, a check is already running, or the server is being created or deleted. Rate
limited to 10/min.

### `POST /api/v1/servers/{server}/provision` — update permission on the server (`servers.manage`)
The panel's **Provision** button: applies the plan from the latest machine check. Only for a server whose status is
`needs_attention` or `error` and whose latest check `finished` without blocks. `202`
`{"data": {"status": "provisioning", "command_id": "01k…"}}`; `422` with `message` set to the summary of what blocks
(or why it cannot run: no finished check, wrong status). Rate limited to 10/min.

### `POST /api/v1/servers/{server}/reprovision` — update permission on the server (`servers.manage`)
**Re-provision** / **Retry provisioning**: the machine check first (agents with `provision.v2`), then the plan; older
agents get the plan directly. `202` `{"data": {"status", "status_message", "stage": "machine_check|provision",
"command_id"}}`. A server that was provisioned before keeps its status while the check runs and when something blocks
(`status_message` starts with "Re-provisioning stopped."); it never goes to `needs_attention`. `422` while the server is
being deleted. Rate limited to 10/min.

### `POST /api/v1/servers/{server}/stack` `{"php"?: {"runtime", "version"?}, "node"?: "22"}` — update permission on the server (`servers.manage`)
Adds PHP and/or Node.js to a server created without them, then re-provisions it (the machine check first) to install
them. `php.runtime` is `fpm` or `frankenphp` on app, web and worker servers, and `cli` on builders (the PHP CLI that
Composer builds need; a builder never serves HTTP). `php.version` defaults to the installation's default. Add-only:
`422` for a runtime the server already has (more PHP versions: `POST …/php`), a runtime its type can't have, or a server
not provisioned yet. → `202 {"data": {"stack": {"php": {"runtime", "versions", "default"}, "node"}, "status",
"status_message", "command_id"}}`; audited as `server.stack_runtimes_added`. CLI: `falak servers stack <server> --php 8.4
[--php-runtime fpm|frankenphp|cli] [--node 22]`.

### `POST /api/v1/servers/{server}/php` `{"version": "8.3"}` — update permission on the server (`servers.manage`)
Installs a PHP version (Servers → server → PHP → Install), e.g. one a falak.yaml unit's `php` needs. The agent installs
it (`runtime.php.install`); `202 {"data": {"version": "8.3", "status": "installing", "command_id": "01k…"}}`. `422` on
`version` for a version Falak doesn't offer or the server's OS can't install, one already installed or installing, a
server that doesn't run PHP or isn't active. `404` for a server of another organization. Rate limited to 10/min.

### `GET /api/v1/servers/{server}/capacity` — `servers.view`
What every service on the server may use against what it has (the agent's facts): each site, compose service,
worker, daemon, database instance and function with its effective limits (memory in MB, `null` = unlimited), the
totals, and `overcommitted` (memory limits or reservations over the RAM, CPU limits over the cores) with warnings.
A function counts `max_instances` × its memory and CPUs; a database instance reserves its whole memory.
```json
{"data": {"server": {"id": "…", "name": "app-1", "memory_mb": 2048, "cpus": 2},
          "totals": {"memory_limit_mb": 2176, "memory_reservation_mb": 1280, "cpus": 3.5},
          "unlimited": {"memory": 0, "cpus": 1}, "overcommitted": {"memory": true, "reservations": false, "cpus": true},
          "items": [{"kind": "database", "id": "…", "name": "app", "memory_limit_mb": 1024, "memory_reservation_mb": 1024, "cpus": 1.5, "url": "/databases/…"}],
          "warnings": ["Memory limits add up to 2176 MB, more than the server's 2 GB: …"]}}
```

### Security baseline

Every active server is audited daily (and after provisioning, and on demand) by the agent's read-only
`security.audit`: SSH, updates, firewall, fail2ban, Docker, files, kernel settings, accounts, time sync; the
control plane adds the backup checks. Each check has a `status` (`pass|warn|fail|info`) and a `severity`
(`critical|high|medium|low|info`). **Score** = 100 minus the cost of every failing check (critical 30, high 15,
medium 5, low 2) and warning (critical 15, high 7, medium 2, low 1), never below 0. `production_ready` is true when
no check of high or critical severity fails.

### `GET /api/v1/servers/{server}/security` — `security.view`
The latest completed report: `{id, score, production_ready, counts, trigger, duration_ms, ran_at, findings: [{id,
title, area, status, severity, evidence, fix_id}]}`. `404` before the first audit.

### `POST /api/v1/servers/{server}/security/audit` — `security.fix` (10/min)
`202 {id, status, error}`; one audit runs per server at a time (a running one is returned).

### `POST /api/v1/servers/{server}/security/fixes` `{fix_id, confirm?, reboot_at?}` — `security.fix` (30/min)
Applies a fix the latest report offers (`422` otherwise). Fixes come from an allowlist compiled into the agent
(`ssh.harden`, `updates.unattended`, `updates.install`, `updates.reboot`, `fail2ban.sshd`, `docker.tcp_off`,
`kernel.sysctl`, `files.secret_permissions`, `time.sync`) or are applied by the control plane (`firewall.apply`,
`firewall.close_port:<tcp|udp>:<port>`, a Network deny rule). Disruptive fixes (`ssh.harden`, `updates.install`,
`updates.reboot`, `docker.tcp_off`) need `confirm: true`; `reboot_at` (`HH:MM`, server time) sets the reboot window.
`202 {id, fix_id, status, error}`; the server is audited again once the fix settles. Fixes with a backup can be
undone from the server's Security tab for 7 days.

## Sites

### `GET /api/v1/sites` · `GET /api/v1/sites/{site}` — `sites.view`
```json
{"data": {
  "id": "01k…", "name": "Shop", "slug": "shop", "status": "ready",
  "framework": "laravel", "runtime": "frankenphp", "build_mode": "native",
  "php_version": "8.4", "node_version": null,
  "repository": "acme/shop", "branch": "main", "push_to_deploy": true,
  "domain": "shop.example.com", "url": "https://shop.example.com",
  "web_directory": "public", "root_path": "/srv/falak/sites/shop", "app_port": null, "container_port": null, "test_domain": null,
  "server_ids": ["01k…"],
  "targets": [{"id": "…", "server_id": "…", "server_name": "web-1", "server_ip": "203.0.113.1", "role": "leader", "status": "ready", "status_message": null, "command_id": null}],
  "strategy": "zero-downtime",
  "current_release": {"id": "01k…", "commit": "a1b2…", "branch": "main", "deployment_id": "01k…", "active": true, "…": "see Release"},
  "release_watch": {"enabled": false, "minutes": 5, "…": "see Watch after deploy"},
  "created_at": "2026-09-26T10:00:00+00:00"
}}
```
`show` also returns `deploy_script`, `shared_paths`, `laravel`. `strategy` / `current_release` / `release_watch` are
contributed by Deployments through `Sites\Contracts\SiteResourceExtension`.

### `POST /api/v1/sites` — `sites.create`
Same body and validation as the web form (`name`, `framework`, `server_ids[]`, optional `leader_server_id`, `runtime`,
`build_mode`, `source_connection_id` + `repository` + `branch`, `push_to_deploy`, `php_version`, `web_directory`,
`app_port`, `container_port`, `health_check_path`, …). `201` with the site resource plus `warnings[]` from the git provider;
`422` on errors. Docker sites take `container_port` (the port the app listens on inside its container, default 3000, may
repeat across sites; an `app_port` sent for a docker site is read as it); their `app_port` is the loopback host port Falak
allocates. Changing a docker site's `container_port` (site settings) redeploys it. Deleting a site
([`DELETE /api/v1/sites/{site}`](#delete-apiv1sitessite--sitesdelete)) stops its containers.
Optional `root_directory` (git sites, also `PATCH`): the repository subfolder the app lives in (monorepos), e.g.
`apps/api` — relative, surrounding slashes trimmed, no `.`/`..` segments. Builds run there and the release is that
folder (deploy steps and hooks run in it); Docker uses it as the build context and resolves `dockerfile` / the
compose file from it. Returned as `root_directory` (null = the repository root).
Optional `project_id` / `environment_id` place the site (Projects); without them it lands in the organization's
Default project, `production` environment. An environment of another organization/project is a `422`.

Optional `domain` (not for compose sites) — a [domain choice](#domains-and-dns): `{"type": "generated"}` routes
`<slug>.<leader-ip-with-dashes>.sslip.io`, `{"type": "custom", "name": "shop.example.com"}` (or just the name as a
string) routes your domain, `{"type": "test"}` keeps only the test domain (`422` when none is configured). Both are added
as the site's primary domain with automatic TLS, and `APP_URL` in the initial environment uses it. Without `domain` the
site only gets its test domain (as before). A name used by another site is a `422`.

Docker Compose sites (`runtime: compose`, `framework` optional — defaults to `docker`; docs/COMPOSE_TEMPLATES.md §5):
`compose_source` `repo` (`compose_file`, default `compose.yaml` then `docker-compose.yml`, built by falak-builder) or
`inline` (`compose_content`, versioned; no `build:`), `public_services` `[{service, port, domain?, health_check_path?}]`
(Falak allocates a loopback host port per service; `domain` is a name or a domain choice — generated names are
`<service>-<slug>.<ip-with-dashes>.<suffix>`, `null` / `{"type": "test"}` means the test domain; `health_check_path`
is the path the deploy health check requests through the service's domain — without it the first service uses the
site's check path and the others accept any answer below 500), `variables` `{KEY: value}` (initial environment; `${{ service.KEY }}` allowed) and
`template` `{slug, version, source: catalog|custom}`. Inline files must pass the compose policy (`422` otherwise) unless
the organization allows privileged compose. The site resource then carries `compose {source, file, version,
public_services[] (with host_port, test_domain, url, health_check_path), template}`.

Repository sources also take `compose_files` (list, `-f` order),
`compose_profiles`, `compose_services` `{<service>: {mode: keep|database|site, engine?, database_id?, site?}}`
(`engine`: `postgresql|mysql|mariadb|redis|valkey`; inline sources take `compose_services` too, read from the stored
file) and
`compose_adjustments {keep_binds: ["service:./path"]}`; with `compose_files`, creation reads the repository first
(files load, public services exist, required `${VAR}`s have a value in `variables`; `422` otherwise). Panel endpoints
for the create flow: `POST /sites/compose/candidates` and `POST /sites/compose/inspect` (`{source_connection_id,
repository, branch, compose_files, …}` → services, variables, adjustments, the merged and adjusted YAML; `no_api: true`
for plain git servers), and `POST /sites/{site}/compose/inspect` for existing sites.

A service running the official `redis` or `valkey/valkey` image (any tag; not `redis/redis-stack`, `bitnami/redis` or
other registries) has `database_engine: redis|valkey` and can become a Falak Redis / Valkey instance (`mode: database`,
`engine` = the image's): `<slug>-<service>` on the stack's leader, which must run that engine (else the service stays
in the stack with a warning: the other cache engine runs there, it isn't installed yet, or Valkey isn't offered for
its OS). `--maxmemory`, `--maxmemory-policy` and `--appendonly yes` in its `command` carry over. The other services'
`REDIS_HOST` / `REDIS_PORT` / `REDIS_PASSWORD` / `REDIS_URL`, `redis://[…@]<service>[:port]` and `<service>:<port>`
inside any value point at the instance (`${{ <stack> <service>.REDIS_… }}`), and a `REDIS_HOST` without `REDIS_PORT` /
`REDIS_PASSWORD` next to it gains them. The containers reach it through the Docker bridge. The container's data is not
copied.

Every public service has domains of its own (Edge `edge_domains` rows with `compose_service`; the first public service
is the site itself). A `domain` chosen here becomes the service's first domain row; after that the service's domains
are managed like a site's — panel Settings → Networking, service picker — and `public_services[].domain` reports the
service's primary domain (read-only mirror). Panel endpoints take an optional `service` (a public service name; null
or the first service's name = the site): `POST /sites/{site}/domains`, `POST /sites/{site}/redirects`,
`POST /sites/{site}/security-rules`, `POST /sites/{site}/headers`, `PUT /sites/{site}/edge-settings` (its IP lists
only: the service's allow list replaces the site's, its deny list adds to it) and a function's
`POST /sites/{function}/function-mounts`. `GET /sites/{site}/domains|routing` list `services` and each row's `service`.

### `DELETE /api/v1/sites/{site}` — `sites.delete`
Optional body `{"delete_volumes": true}`. `202` with no body: the site is deleted at once (the edge drops its
routes, its queue workers stop, the repository's webhook / deploy key are unlinked); stopping what it runs on its
servers follows as agent commands, as with `DELETE /api/v1/servers/{server}`. PHP sites lose their PHP-FPM pool,
docker sites their blue and green containers, compose sites run `docker compose down` (`delete_volumes: true` also
removes the named volumes; kept by default). Files under `/srv/falak/sites/<slug>` stay on the servers. The token
needs `sites.view` as well; `404` for a site of another organization, `422` when `delete_volumes` is not a boolean.

### `GET /api/v1/sites/{site}/env` — `sites.env.view`
Returns the latest environment version as dotenv (audited as a reveal).
```json
{"data": {"content": "APP_ENV=production\nAPP_KEY=base64:…\n", "version": 3}}
```

### `PUT /api/v1/sites/{site}/env` — `sites.env.manage`
Body `{"content": "<dotenv>"}` replaces all variables (deploy-script exposure of existing keys is kept).
`422` on unparsable content (`errors.content`). Takes effect on the next deployment.
```json
{"data": {"version": 4, "changed": true, "keys": ["APP_ENV", "APP_KEY"]}}
```

### `PUT /api/v1/sites/{site}/laravel` — `sites.manage`
Laravel toggles, each optional (unchanged when omitted): `scheduler`, `horizon`, `octane`, `maintenance`, and
`octane_server` (`frankenphp` — FrankenPHP runtime only, the default there — `swoole` (default on PHP-FPM) or
`roadrunner`). The Octane port is allocated by Falak (unique on every server of the site, persisted) and returned; it
cannot be set. `422` for a Laravel toggle on a non-Laravel site, an unavailable server, or no free port.
```json
{"data": {"scheduler": true, "horizon": false, "octane": true, "maintenance": false, "octane_server": "frankenphp", "octane_port": 8412}}
```

### Resource limits — `GET|PUT /api/v1/sites/{site}/limits` · `PUT /api/v1/sites/{site}/compose/services/{service}/limits` — `sites.view` / `sites.manage`
`{"limits": {…}}` with any of `memory_limit` (MB, ≥ 32), `memory_reservation` (MB, at most the limit), `cpus`
(cores, decimal), `pids_limit`, `restart_policy` (`always|unless-stopped|on-failure`), `max_restarts` (on-failure
only), `log_max_size` (MB per file), `log_max_files`, `oom` (`protect` = killed last | `normal`); `null` or `{}`
clears them. Memory and CPUs are bounded by the smallest server of the site (`422` otherwise). Docker sites and
compose services get them as container limits, classic sites as a systemd slice (PHP-FPM in its own master, Octane,
the web process). `applied` says how they took effect: `live` (docker update / set-property), `redeploy` (log caps,
the OOM preference, removed limits and compose services need a new container) or `none`. What is stored is what runs
(`effective` = `limits`): a site or worker created outside production starts with the environment's defaults
(`defaults`; `config/limits.php`) written on it, never merged later. A compose project's `*` entry is what its
services without their own get. Compose projects are limited per service; static and
function sites have no limits here; FrankenPHP sites only take `restart_policy`, `max_restarts`, log caps and `oom`.
Workers and daemons take the same `limits` object in their forms.
```json
{"data": {"limits": {"memory_limit": 512, "cpus": 1}, "effective": {"memory_limit": 512, "cpus": 1}, "applied": "live"}}
```

### `GET /api/v1/sites/{site}/logs` — `telemetry.view`
Query: `since` (seconds, default 3600, ≤ 30 days), `limit` (1–1000, default 100), `level`
(`trace|debug|info|warn|error|fatal`), `kind` (`app` — the site's log files, programs, cron, containers — or
`access` — edge requests; default both), `cursor` (from the previous page). Newest first; `meta.cursor` is empty
on the last page.
```json
{"data": [{"at": "2026-09-26T10:00:02.000000+00:00", "level": "ERROR", "source": "laravel", "server": "web-1",
           "message": "boom", "attributes": {"service_name": "laravel", "…": "…"}}],
 "meta": {"cursor": "1790000000000000001"}}
```

### `GET /api/v1/sites/{site}/access-logs` — `telemetry.view`
The site's edge HTTP access log ("Network Logs"): one entry per request served for the site, by its servers or by
the load balancer in front of them. Query: `since`, `limit`, `cursor` as above; filters `server` (server id),
`deployment` (requests served while that deployment's release was live), `method`, `status` (`404` or a class
`5xx`), `path` (substring of the request URI), `client_ip`. `503` when Loki is not configured.
```json
{"data": [{"ts": "1790000000000000002", "at": "2026-09-28T10:00:02.000000+00:00", "method": "GET", "path": "/cart",
           "query": "x=1", "status": 502, "duration_ms": 12.3, "bytes": 512, "request_bytes": 0,
           "client_ip": "203.0.113.9", "user_agent": "curl/8.5", "host": "shop.example.com",
           "server_id": "01k…", "deployment_id": "01k…", "release_id": "01k…"}],
 "meta": {"cursor": "1790000000000000002"}}
```

## Domains and DNS

A **domain choice** is `{"type": "generated" | "test" | "custom", "name"?: string}` (a plain string is a custom
domain). It is accepted by `POST /api/v1/sites` (`domain`), compose `public_services[].domain` and template deploys
(`domains.<service>`; a service without a choice gets the organization default: the test domain when
`FALAK_TEST_DOMAIN` is set, else a generated name, else a domain is required).

- **generated** — `<label>.<ipv4-with-dashes>.<suffix>`, e.g. `minio-files.63-182-218-247.sslip.io`. The label is the
  site slug (compose: `<service>-<slug>`); the IP is the leader server's public IPv4 (a site behind a load balancer: the
  `lb` server's). Wildcard DNS services (`sslip.io` by default, `nip.io`, or a self-hosted one via
  `FALAK_GENERATED_DOMAIN_SUFFIX`; per organization in Settings → Domains) resolve it to that IP, so it works without DNS
  setup and Let's Encrypt issues its certificate over HTTP-01. `422` when generated names are off or the server has no
  public IPv4 yet. One name per endpoint: sites on several servers without a load balancer are reached on the leader.
- **test** — `<slug>.<FALAK_TEST_DOMAIN>` (compose: `<service>-<slug>.…` after the first service).
- **custom** — your domain, routed with automatic TLS once DNS points at the server (see the check below).

### `GET|PUT|DELETE /api/v1/sites/{site}/domains/{domain}/rate-limit` — `edge.view` / `edge.manage`
A domain's Cloudflare rate limit (`{domain}`: its id or name; docs/CLOUDFLARE.md → Rate limits). `GET` →
`{domain, rule, zone, proxied, limits: {plan, rules, host, periods[], timeouts[], challenge_timeout, note}, zone_rule}` (`limits` null
outside a managed zone; `zone_rule` `{domain, path}`: another domain's Free-plan rule that applies to this one too). `PUT {path?, requests, period, action: block|managed_challenge, timeout}` writes the zone's rules and
returns the same shape (`timeout` is ignored and stored as 0 for `managed_challenge` when `challenge_timeout` is false:
below Enterprise Cloudflare challenges each request over the limit); `422` when the domain isn't proxied, the plan doesn't allow the window / duration or has no rule
left, or Cloudflare refuses (the token needs Zone → Zone WAF → Edit). `DELETE` removes the rule. Rate limited to
30/min.

### `GET /api/v1/domains/options?server=<id>[,<id>…]` · `?site=<site>` — `edge.view`
What a create form offers: `{test_domain, generated: {suffix, ipv4, target, available, reason}, default, targets[]}`
(`targets`: `{server_id, name, ipv4, ipv6, load_balancer}` — where DNS must point: the site's load balancer, else each
server, leader first). `server` may repeat (`server[]=`) or be comma-separated; `site` uses the site's servers.

### `GET /api/v1/dns/check?name=<domain>&server=<id>…` · `&site=<site>` — `edge.view` (60/min)
Resolves `name` from the control plane (DNS-over-HTTPS, `FALAK_DNS_RESOLVER=doh|system`, 3 s timeout) and compares it
with the targets:

```json
{"data": {
  "name": "shop.example.com", "status": "ok", "message": "Points to app-2 (63.182.218.247)",
  "addresses": ["63.182.218.247"], "cnames": [], "targets": [...], "matched": [...],
  "instructions": {"zone": "example.com", "host": "shop", "apex": false, "ttl": 300,
    "records": [{"type": "A", "name": "shop.example.com", "host": "shop", "value": "63.182.218.247", "target": "app-2"}],
    "alternative": {"type": "CNAME", "host": "shop", "value": "shop.63-182-218-247.sslip.io"}, "notes": ["…"]},
  "certificate": null, "checked_at": "2026-09-28T12:00:00+00:00"}}
```

`status`: `ok` (every address is a target), `mismatch` ("Resolves to 1.2.3.4 — expected …", or extra records to
remove), `proxied` (Cloudflare proxy addresses: HTTP-01 fails until the record is "DNS only"), `missing` (no A/AAAA
yet), `error` (lookup failed, invalid name, or no server IP to compare with). `instructions` lists the records to add
(A per target IPv4, AAAA per IPv6; apex vs subdomain; a CNAME to the generated name as an alternative for subdomains of
single-target sites). With `site` and `tls=1`, `certificate` reports what the site's server serves for the name:
`{status: issued|pending, issuer, expires_at, message}` (probed only when the name points at the site).

## Projects

Projects → environments → services (sites / databases). Every organization has a `Default` project (`is_default`);
new sites and databases without a placement land in its `production` environment. Environments are addressed by
slug or id.

```json
{ "id": "01j…", "name": "Shop", "description": null, "icon": null, "is_default": false, "created_at": "…",
  "environments": [{ "id": "01j…", "project_id": "01j…", "name": "production", "slug": "production",
                     "is_production": true, "forked_from_id": null, "services_count": 3, "created_at": "…" }] }
```

### `GET /api/v1/projects` · `GET /api/v1/projects/{project}` — `projects.view`
### `POST /api/v1/projects` `{name, description?, icon?}` — `projects.manage`
Creates the project with its `production` environment. `201`.
### `PATCH /api/v1/projects/{project}` · `DELETE /api/v1/projects/{project}` — `projects.manage`
Only empty, non-default projects can be deleted (`422` otherwise).
### `GET /api/v1/projects/{project}/environments` — `projects.view`
### `POST /api/v1/projects/{project}/environments` `{name, from_environment_id?}` — `projects.manage`
With `from_environment_id` (also needs `sites.create`), every site is copied through `Sites\Contracts\SiteFactory`
(configuration, deploy script, toggles, shared paths, variables — no servers, push-to-deploy off) at the same canvas
position and service name; databases are not copied. `201 {data, warnings[]}`.
### `POST /api/v1/projects/{project}/environments/{environment}/services` — `projects.manage` + `databases.manage` / `sites.create`
The canvas' Create. Databases: `{kind: "database", engine: postgresql|mysql|mariadb|redis|valkey, server_id, name,
version?, memory_mb?, disk_gb?, eviction?, persistence?}`: a database container on the server (a Falak image of the
major, default the engine's first; memory default 512 MB, 128 MB for Redis / Valkey; disk default 10 GB, 2 GB), joined
to the environment's network. SQL engines get a database and a user named after it; Redis / Valkey their keyspace and
`default` user (`eviction`: `noeviction` default, `allkeys-lru`, …; `persistence`: `rdb` default, `aof`, `none`). A
major this release ships no pinned image of is a `422` on `version`. Sites: the `POST /sites` body with
`kind: "site"`. `201 {data: <canvas service>, warnings[]}`; the service is `provisioning` until the container runs.
### `PATCH /api/v1/projects/{project}/environments/{environment}/services/{service}` `{name?, icon?}` — `projects.manage`
Rename a canvas service, or set its icon: a [Cool Icons](https://coolicon.falak.sh) slug, optionally with a variant
(`docker`, `docker/svgl-dark`); `null` brings back the icon derived from the site or engine. Projects take the same
`icon` (`PATCH /api/v1/projects/{project}`). Icons are canvas-only: `falak.yaml` ignores them.
`200 {data: {id, name, icon}}`.

Database containers can be listed, read and deleted over `/api/v1` (see Databases); the rest of their lifecycle and
backups have no `/api/v1` endpoints yet. The session routes (CSRF, `Accept: application/json`
for errors as JSON): `POST /databases/instances {engine, server_id, name, version?, memory_mb?, cpus?, disk_gb?,
settings?}`, `PUT /databases/instances/{instance} {memory_mb?, cpus?, settings?, public_access?, require_tls?,
allowed_sources?}` (a setting given as `null` returns to its default; `allowed_sources`: IPv4 CIDRs allowed to reach a
public port), `POST /databases/instances/{instance}/restart|upgrade {version?}|password {password?}|network` (`network`
applies pending published addresses: the container is recreated), `DELETE /databases/instances/{instance} {confirm,
delete_volume?}`, `POST /databases/databases/{database}/backups {storage_provider_id}`, `POST
/databases/instances/{instance}/schedules {name, storage_provider_id, database_ids, cron, retention_count?,
retention_days?, enabled?, encryption_mode? (cp|customer), age_recipient?, drill? (off|weekly|monthly), drill_query?,
drill_server_id?}` · `PUT|DELETE /databases/schedules/{schedule}` · `POST /databases/schedules/{schedule}/run|drill`,
`POST /databases/backups/{backup}/restore {database_instance_id, database, confirm, identity?}` (`databases.restore`;
into an existing database of a running instance of the same family; `identity`: the age private key of a
customer-held backup, used once), `POST /databases/backups/{backup}/key` (`databases.restore` and a re-authentication
within 5 minutes, else `423`: the backup's data key as a `falak-restore` key file, audited), `GET
/databases/backups/{backup}/download` (the encrypted FKB1 file), `DELETE /databases/backups/{backup}`, `GET
/databases/databases/{database}` (JSON: the panel). Volumes: `POST /volumes/{volume}/schedules` and `PUT
/volumes/schedules/{schedule}` take the same `encryption_mode`, `age_recipient`, `drill` and `drill_server_id`; `POST
/volumes/schedules/{schedule}/drill`, `POST /volumes/backups/{backup}/restore {…, identity?}`, `POST
/volumes/backups/{backup}/key` (`volumes.browse` and a re-authentication). Point-in-time recovery (SQL instances):
`PUT /databases/instances/{instance}/pitr {enabled, storage_provider_id?, encryption_mode? (cp|customer), age_recipient?,
window_days? (1–35), base_interval_days?}` (`databases.manage`; turning it on takes a base backup), `POST
/databases/instances/{instance}/pitr/base` (a base now), `POST /databases/instances/{instance}/pitr/restore
{target_time | "latest", identity?}` (JSON, `databases.restore`: `202 {data: {id, restored_instance_id}}`, a new read-only
instance at the time), `POST /databases/pitr-restores/{restore}/decision {decision: swap|keep|discard}`
(`databases.restore`), `POST /databases/pitr-restores/{restore}/inspection` (`databases.restore`: the read-only copy's
`falak_inspect` password). Turning PITR off or shortening the window takes `databases.restore`. Backups, drills and PITR:
docs/BACKUPS.md.
### `PATCH|DELETE /api/v1/projects/{project}/environments/{environment}` — `projects.manage`
Rename (the slug follows). Only empty, non-production environments can be deleted.

### Variable references
Site variables may contain `${{ <service>.<KEY> }}`; they resolve at deploy time (release `.env`, deploy script
environment, public build variables) against services of the **same environment**. Service names match
case-insensitively with spaces/dots/underscores as dashes. Database services expose `DATABASE_URL`,
`DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` (oldest user granted on the
database); Redis and Valkey services `REDIS_URL` (`redis://default:<password>@<host>:<port>`), `REDIS_HOST`,
`REDIS_PORT`, `REDIS_PASSWORD` and `REDIS_CLIENT` (`phpredis`); site services their own variables. The host and port
depend on the site:
- a container on the database's server (Docker site, compose stack): `falak-db-<id>` and the engine's port, on the
  environment's Docker network;
- a native site there: `127.0.0.1` and the container's host port;
- a site on another server of the environment: the database server's address on a private network they share (a Falak
  private network first, else the provider private network where both servers are on it for sure) and the host port,
  once that address is published (a restart someone applies). **Never a public address**: otherwise the reference
  fails with the reason (`… shares no private network with <server> …`, `… is not published on <address> yet …`).
Unknown services/keys and cycles fail the deployment: `Unresolved variable references: …`.
`${{ secrets.NAME }}` reads the secret store instead of a service (`secrets` is never a service name there): the
current version of the nearest secret `NAME` of the service owning the variable — its service, environment, project,
then organization secrets. A missing secret fails the deployment (`STRIPE_KEY: secret STRIPE_KEY is not defined for
this service …`), as does a linked secret whose provider is not configured. Each read is in the secret's access log
(once per deployment and version).

## Databases

Database containers (instances). Responses never carry a password: `rotating_password` and `password_overlap_until`
only say a rotation is pending.

### `GET /api/v1/databases/instances` · `GET /api/v1/databases/instances/{instance}` — `databases.view`
```json
{"data": {
  "id": "01k…", "name": "shop", "server_id": "01k…", "server_name": "db-1", "environment_id": "01k…",
  "engine": "postgresql", "engine_label": "PostgreSQL", "kind": "sql", "version": "17",
  "image": "ghcr.io/falak-sh/falak-postgres:17", "image_digest": "sha256:…",
  "hostname": "falak-db-01k…", "port": 5432, "host_port": 20000, "published_addresses": [], "public_access": false,
  "require_tls": true, "memory_mb": 512, "cpus": null, "settings": {}, "pitr_enabled": false, "volume_id": "01k…",
  "status": "active", "status_message": null, "health": "healthy", "rotating_password": false,
  "databases_count": 1, "users_count": 1, "managed": null, "created_at": "2026-10-01T10:00:00+00:00", "…": "…"
}}
```
The list is the organization's instances by name; `404` for an instance of another organization. `managed` is what
`falak.yaml` manages on it (`null` when nothing).

### `DELETE /api/v1/databases/instances/{instance}` `{"confirm": "<name>", "delete_volume": false}` — `databases.manage`
The dashboard's delete: `confirm` must be the instance's name (`422` otherwise). `202 {data: <instance>}` with
`status: deleting`; the agent removes the container, then the instance is gone. Its data volume stays unless
`delete_volume: true`. `422` on `confirm` when a `falak.yaml` declares the instance (remove it from the file first),
`422` on `instance` during a major upgrade or when the server's agent is not connected. The token needs
`databases.view` as well.

## Consoles

Shells where a site runs (its container, or its current release as the site user), in database containers and on
servers. `falak console` uses these. The answer carries a `ws(s)://` relay URL with a single-use ticket valid for 60
seconds; the client dials it and exchanges bytes as in `docs/plans/CONSOLE.md` ("Relay HTTP API"). Every console is a
terminal session: audited, recorded and listed under Terminal. All routes answer `409 {"message": "The terminal relay
is off on this installation."}` when the relay is off (`FALAK_TERMINAL_RELAY`), and `422` on `server` when the
server's agent is too old (no `terminal.v2`), not connected or not active.

### `POST /api/v1/sites/{site}/console` `{server_id?, service?, cols?, rows?}` — `terminal.open` + `sites.view`
### `POST /api/v1/databases/instances/{instance}/console` `{cols?, rows?}` — `terminal.open` + `databases.view`
### `POST /api/v1/servers/{server}/console` `{user?, cols?, rows?}` — `terminal.open`
```json
{"data": {
  "session_id": "01k…", "url": "wss://falak.example.com/terminal/v1/sessions/01k…?ticket=…", "writable": true,
  "server_id": "01k…", "server_name": "app-1", "target_type": "site", "target_name": "shop",
  "container": "compose:web"
}}
```
`201`. `{site}` is an id or slug, `{instance}` and `{server}` an id or name.

| Target | Opens |
|---|---|
| Docker site | its running container (`container: "docker:<slug>"`) |
| Compose site | the `service`'s running container (`compose:<service>`); `422` on `service` listing the services when the stack has several and none is given |
| Native site (PHP, Node, Bun, Deno, static) | a shell as the site user in its current release, with its variables (`container: null`) |
| Function | `422` on `site`: functions have no console |
| Database instance | its container (`docker:falak-db-<id>`); `422` on `instance` unless it is running |
| Server | a shell as `user` (default root); `target_name` and `container` are `null` |

`server_id` picks one of the site's servers (id or name; default: the leader). `cols`/`rows` default to 80×24.

### `GET /api/v1/sites/{site}/console` — `terminal.open` + `sites.view`
What a site console can be opened on: `{"data": {"servers": [{"id", "name", "leader"}], "services": ["web", "worker"] | null, "runtime": "compose"}}`.

### `POST /api/v1/terminal/sessions/{session}/ticket` — the session's view permission
A new URL for the same session (reconnect): `{"data": {"url", "writable"}}`. `404` once the session is closed or when
it doesn't use the relay, `403` when the token's user may not view it. `writable` is false for watchers of a shared
session without `terminal.control`.

### `DELETE /api/v1/terminal/sessions/{session}` — the session's close permission
Closes the session (`204`).

## Secrets

Organization secrets in four scopes (`organization`, `project`, `environment`, `service` — a project service id),
referenced from variables as `${{ secrets.NAME }}`. Names are environment variable names (`^[A-Z_][A-Z0-9_]*$`), unique
per scope. Every value is a new immutable version, sealed under the organization's data key and bound to the secret and
version. Responses carry metadata only (`id, name, scope, scope_id, scope_label, kind, sensitive, available_to_previews,
description, rotation_days, rotation_due_at, current_version, last_accessed_at, created_at, updated_at`, plus for linked
secrets `provider_id, watch_minutes, on_change, last_polled_at`). Secrets of other organizations are `404`.

### `GET /api/v1/secrets[?scope=&scope_id=]` · `GET /api/v1/secrets/{secret}` — `secrets.view`
### `POST /api/v1/secrets` — `secrets.manage`
`{name, scope, scope_id, value | (kind: "linked", reference, provider_id?, watch_minutes?, on_change?), sensitive?
(default true), available_to_previews? (default false), description?, rotation_days?}`. `201`. A linked secret's
reference must match its provider's type (see [SECRET_PROVIDERS.md](SECRET_PROVIDERS.md)); without `provider_id` the
organization's only provider of that type is used. `watch_minutes` (1–1440, null: not watched) and `on_change`
(`none|restart|redeploy`) set the watch. A **sensitive** secret is write-only:
it can be replaced, never revealed, and stays sensitive.
### `PUT /api/v1/secrets/{secret}/value` `{value}` — `secrets.manage`
A new version, current from the next deployment.
### `POST /api/v1/secrets/{secret}/rollback` `{version}` — `secrets.manage`
A new version with that version's value (history is never rewritten). Disabled versions can't be restored. A linked
version that recorded its value upstream is restored **pinned** to that value until a new reference is saved.
### `DELETE /api/v1/secrets/{secret}` — `secrets.manage`
Every version goes; the access log stays. References to it fail the next deployment.
### `POST /api/v1/secrets/{secret}/reveal[?version=]` — `secrets.reveal`, by name on the token
`{data: {version, value}}` (a linked secret: its reference). `403` for sensitive secrets and for tokens without the
`secrets.reveal` ability itself. Logged in the access log as the token, and audited.

### Secret providers
External providers behind linked secrets (Vault / OpenBao, AWS Secrets Manager and SSM, 1Password Connect, Doppler,
Infisical, HTTPS webhook): settings, references, caching and the webhook contract in
[SECRET_PROVIDERS.md](SECRET_PROVIDERS.md). Responses never carry credentials:
```json
{"id": "01k…", "name": "Production Vault", "type": "vault", "type_label": "HashiCorp Vault / OpenBao", "scheme": "vault",
 "settings": {"address": "https://vault.example.com", "kv_version": "2", "auth_method": "approle", "role_id": "…"},
 "stored_credentials": ["secret_id"], "allow_private_network": false, "cache_ttl_seconds": 300,
 "status": "untested|ok|error", "last_checked_at": "…", "last_error": null, "secrets_count": 3, "created_at": "…", "updated_at": "…"}
```
### `GET /api/v1/secrets/providers` · `GET /api/v1/secrets/providers/{provider}` — `secrets.view`
### `POST /api/v1/secrets/providers` — `secrets.providers.manage` (admins)
`{name, type: vault|aws_secrets_manager|aws_ssm|onepassword|doppler|infisical|http, config: {…}, allow_private_network?,
cache_ttl_seconds? (0–86400, default 300)}`. `config` per type:
- `vault`: `address, namespace?, kv_version (2|1), auth_method (approle|token|jwt), token | role_id + secret_id | role + jwt,
  auth_mount?, ca_pem?`
- `aws_secrets_manager`, `aws_ssm`: `region, auth_method (keys|instance_profile), access_key_id, secret_access_key,
  session_token?, role_arn?, external_id?`
- `onepassword`: `connect_url, token, ca_pem?` · `doppler`: `token` · `infisical`: `base_url, client_id, client_secret, ca_pem?`
- `http`: `base_url, header_name?, header_value?, ca_pem?`

URLs are `https://` and must resolve to public addresses unless `allow_private_network` (self-hostable types only, and
only when the instance sets `FALAK_SECRETS_PROVIDERS_ALLOW_PRIVATE=true`). With `auth_method: instance_profile`,
`role_arn` is required and the external ID is always the organization id.
### `PATCH /api/v1/secrets/providers/{provider}` — `secrets.providers.manage`
Same fields but `type`; without `config` the settings stay as they are. Credentials left empty keep their stored value,
unless a setting that decides where they are sent (URL, CA, namespace, region, role, external ID, header) changes:
then every credential must be sent again (`422` otherwise). Changing settings resets the status to `untested` and drops
the cached values.
### `POST /api/v1/secrets/providers/{provider}/test` — `secrets.providers.manage`
Checks the endpoint and credentials (Vault `lookup-self`, STS `GetCallerIdentity`, Doppler `/v3/me`, Infisical login,
Connect `/v1/vaults`, webhook test ref) and records the status. `422 {errors: {provider: [reason]}}` when it fails.
### `DELETE /api/v1/secrets/providers/{provider}` — `secrets.providers.manage`
`422` while linked secrets use it. Its cached values are deleted.

## Source control

### `GET /api/v1/source-control/connections` — `source_control.view`
### `POST /api/v1/source-control/connections` — `source_control.manage`
`{provider: github|gitlab|bitbucket|custom, auth_type: token|basic|none, name?, base_url?, token?, username?, password?}`.
Tokens are verified against the provider before saving. Credentials are write-only and never returned.
Custom git uses `auth_type: none` (public URLs) or per-site deploy keys.
GitHub App connections (`auth_type: app`) are created only through the browser flow (Settings → Source control →
Connect GitHub), since GitHub asks the user to confirm the app and pick repositories; they are listed like any
other connection and can be used as `source_connection_id` for sites.

## Config as code (`falak.yaml`)

A project can follow one file in its repository (`falak.yaml`, `falak.yml` or `falak.json`; schema
`https://falak.sh/schema/v1.json`; guide: [CONFIG_AS_CODE.md](CONFIG_AS_CODE.md), design: [plans/CONFIG_AS_CODE.md](plans/CONFIG_AS_CODE.md)). The source (connection,
repository, path, on/off) is set in the dashboard (Project settings → Config as code) or with `PUT …/config/source`
(`falak config source set`). A push to a branch an
environment of the file names (`environments.<slug>.branch`) creates one **config run** per environment: the file is read
at the pushed commit, validated, planned, applied, and the units that changed (their files, or their config) are
deployed; phase B (workers, schedules, domains…) is applied when each unit's release goes live. A plan with risky
changes **waits for an approval** (`awaiting_approval`, `config.approve`, expires after 7 days by default); `refused` is
only found on runs made before approvals. "Experimental" is a label only.
A file without `environments` binds the project's production environment to the repository's default branch.

`{project}` is the project id. Issues (`ConfigIssue`):

```json
{"code": "schema", "pointer": "/units/web/deploy/strategy", "source": null, "line": 12, "column": 17,
 "message": "The data should match one item from enum.", "severity": "error"}
```

`code`: `parse`, `parse.*`, `schema`, `environment.unknown`, `override.type`, `reference.*`, `name.server`,
`name.secret`, `name.unit`, `name.storage`, `name.dns_credential`, `name.certificate`, `name.environment`, `value.range`, `value.unsupported`, `value.forbidden`,
`value.conflict`, `limit.server`. `pointer` is a JSON Pointer into the file (or into the resolved file, `source` then
says where the value is in the file); `line`/`column` are 1-based, `null` when unknown.

An invalid file answers **422** in Laravel's shape plus the issues:

```json
{"message": "falak.yaml is invalid (2 errors).",
 "errors": {"file": ["/units/web/deploy/strategy: The data should match one item from enum."]},
 "config_errors": [ConfigIssue], "config_warnings": [ConfigIssue]}
```

### `GET /api/v1/projects/{project}/config/source` — `config.view`
`{"data": ConfigSource}`; `404 {"message": "Config as code is not connected for this project."}` when the project has
none.

```json
{"id": "01j…", "connection_id": "01j…", "repository": "acme/shop", "path": "falak.yaml", "enabled": true,
 "experimental": false, "separate_approver": false, "allowed_credentials": [], "allowed_servers": [],
 "created_at": "…", "updated_at": "…"}
```

### `PUT /api/v1/projects/{project}/config/source` — `config.apply` (30/min)
Connects, changes, enables or disables the source, exactly as the dashboard does. Body `{"connection_id", "repository":
"owner/name", "path"?: "falak.yaml", "enabled": true, "separate_approver"?: bool, "allowed_credentials"?: [name], "allowed_servers"?: [name]}`
(`path` defaults to `falak.yaml`; `falak.yml`/`falak.json`, in a folder or not). → `200 {"data": ConfigSource}`.
`separate_approver`, `allowed_credentials` and `allowed_servers` (server names the file may place units on besides those
hosting the project's services: a new project hosts none) can only be changed with `config.approve` (`403` otherwise;
sending the current value is fine). While either list is non-empty, changing `repository` or `connection_id` needs
`config.approve` too (`403`): the grants were given to that repository's file. `422` for a server name the organization lacks, an unknown connection (or one of a plain git server), a repository not `owner/name`
or a path not named `falak.*`. `enabled: false` keeps every resource, makes every field editable again and cancels the
runs `planning` or `awaiting_approval` (`cancel_reason: source_disabled`). Enabling registers the repository's webhook.

### `POST /api/v1/projects/{project}/config/validate` — `config.view` (60/min)
Body `{"file": "<raw text>", "format": "yaml"|"json", "environment": "staging"?}`. Without `environment`: reading, schema
and the merge of every environment of the file; with it: also the organization checks (servers attached to the
project or allowed on its source, secrets, services, each module's checks) for that environment. Always `200`:
`{"data": {"valid": false, "environments": ["production", "staging"], "errors": [ConfigIssue], "warnings": [ConfigIssue]}}`.

### `POST /api/v1/projects/{project}/config/plan` — `config.view` (30/min)
`data.environment_exists` / `data.preview_only`: for an environment the file declares but the project lacks, the plan
is a preview of its creation (a `create` of `/environments/<slug>` first, then its units) with the warning
`environment.created_on_push`. A file without `environments` plans the project's production environment.

Body: exactly one of `file` (+ `format`) / `commit` (the file at that commit), plus `environment`. Nothing is applied.
→ `200 {"data": {"environment", "commit", "resolved": {…}, "plan": {"changes": [Change], "summary": {"create": 1,
"update": 2, "delete": 0, "detach": 0, "noop": 9}, "risky": false, "deploy": ["web"]}, "warnings": [ConfigIssue]}}`.
`Change` is `{op, path, before, after, section, unit, risky, reason, rule}` (`op`: create, update, delete, detach,
noop; `path` a JSON Pointer, list items by identity: `/units/web/domains/shop.example.com`). `422` invalid file, `409
{"message": "Config as code is not enabled for this project."}`.

### `POST /api/v1/projects/{project}/config/runs` — `config.apply` (10/min)
Body as for plan, plus `"allow_destroy": false` (`true` needs `config.destroy`). A run from `file` applies that text
(`commit` null: units deploy their branch's head). → `201 {"data": ConfigRun}`, answered once the plan is saved
(status `applying`, `applied`, `awaiting_approval`, `refused` or `failed`); `422` (with `run`) for an invalid file.

```json
{"id": "01j…", "project_id": "01j…", "environment": "production", "environment_id": "01j…", "commit": null,
 "branch": null, "trigger": "cli", "status": "applying",
 "plan": {"changes": [Change], "summary": {…}, "risky": false, "deploy": ["web"]},
 "errors": [], "warnings": [], "error": null, "allow_destroy": false, "requested_by": "01j…", "approved_by": null,
 "approved_at": null, "error_code": null,
 "deployments": [{"unit": "web", "site_id": "01j…", "deployment_id": "01j…", "status": "deploying", "deployment_status": "deploying", "error": null}],
 "created_at": "…", "started_at": "…", "finished_at": null, "url": "https://…/projects/01j…/settings?config_run=01j…#config"}
```

`trigger`: `push`, `pr`, `cli`, `manual`, `rollback` (a rollback restores the config saved on the release it returns
to). `status`: `planning`, `awaiting_approval`, `applying`, `applied`, `failed`, `cancelled`, `refused`; the run's
`status` alone says whether it is finished (`applied`, `failed`, `cancelled`, `refused`). `deployments[].status` is
Config's view of each unit: `pending`, `deploying`, `released` (live, phase B applied), `skipped` (not deployed: no
deploy source, push to deploy off on a push, or a unit removed from the file), `failed`, `cancelled`, `superseded` (a
newer run of the environment took over; the run ends `cancelled`); `deployment_status` is the deployment's own status.
Env values are `••••` in plans and runs unless the token has `sites.env.view` (plans are stored masked).
`error_code` (only when `failed`): `invalid` (see `errors`), `unreadable` (the file couldn't be read at the commit),
`apply_failed` (applying a phase failed; a phase A failure deploys nothing), `deployment_failed` (a unit's deployment
failed or was cancelled: the entries with `status: failed` / `cancelled`).

### `GET /api/v1/projects/{project}/config/runs[?environment=&per_page=&page=]` · `GET /api/v1/config/runs/{run}` — `config.view`
Newest first, `{"data": [ConfigRun], "links": {"next", "prev"}, "meta": {"current_page", "last_page", "total", "per_page"}}`
(`per_page` 1–100, default 25; `page` from 1) / `{"data": ConfigRun}` (`falak apply --wait` polls it).

A run that needed an approval carries `approval` (null otherwise), and a cancelled run its `cancel_reason`:

```json
{"approval": {"required": true, "risky": [{"path": "/units/web/deploy/strategy", "rule": "production.strategy", "reason": "…"}],
              "expires_at": "…", "approved_by": null, "approved_at": null, "rejected_by": null, "rejected_at": null,
              "comment": null, "can_approve": true, "blocked_reason": null, "plan_hash": "3f1a…"},
 "cancel_reason": null}
```

`can_approve`/`blocked_reason` are the caller's ("You need the config.approve permission.", "You requested this run:
another approver must approve it." when the project's admins require a different approver). `cancel_reason`:
`superseded`, `rejected`, `expired`, `source_disabled`, `environment_released`, `user`.

### `POST /api/v1/config/runs/{run}/approve` `{"plan_hash", "comment"?}` — `config.approve` (30/min)
`plan_hash` (required) is the run's `approval.plan_hash` as shown: a different hash approves nothing (`409`, "The plan
changed since it was shown; review it again."). The run plans again and applies: `200 {"data": ConfigRun}` (`applying`, or already `applied`/`failed`). `403
{"message": blocked_reason}`; `409 {"message": "This run is not awaiting approval."}` (superseded, expired, rejected,
done); `409 {"message": "The plan changed since it was shown; review it again.", "data": ConfigRun}` when the fresh plan
has a risky change the approval didn't cover (the run waits again with the new plan).

### `POST /api/v1/config/runs/{run}/reject` `{"comment"?}` — `config.approve` (30/min)
`200 {"data": ConfigRun}` (`cancelled`, `cancel_reason: rejected`); `409` when it isn't awaiting approval.

### `GET /api/v1/projects/{project}/config/drift?environment=` · `POST …/config/drift` `{"environment"}` — `config.view` (POST 10/min)
The environment's state against the file last applied to it (POST checks now; a scheduled check runs every 30 minutes):

```json
{"data": {"environment": "production", "status": "drifted", "checked_at": "…", "since": "…",
          "baseline_run_id": "01j…", "baseline_commit": "abc…", "signature": "9f2c…", "changes": [Change]}}
```

`status`: `in_sync`, `drifted`, `unknown` (never checked, nothing applied yet, or a run is applying: **not drift**).
`changes` are what a run would change to put the file back (env values masked). A drift alerts `config.drift` once per
signature.

### `GET /api/v1/projects/{project}/config/unmanaged?environment=` — `config.view`
What runs detached (removed from the file but kept, or released with their environment):
`{"data": [{"id", "environment", "unit", "resource_type", "resource_id", "label", "reason", "run_id", "detached_at"}]}`
(`reason`: `removed_from_file`, `environment_released`). Delete them from their module's page (its permissions), or add
them back to the file to manage them again.

### `POST /api/v1/config/runs/{run}/cancel` — `config.apply`
A run `planning` or `awaiting_approval` → `cancelled`; `409` otherwise.

### `GET /api/v1/projects/{project}/config/export?environment=production&format=yaml` — `config.view`
`{"data": {"environment": "production", "format": "yaml", "file": "<text>"}}`: today's state of the environment as a
file (`units` from each module's current state, the environment with its `branch`). Without `sites.env.view` literal env
values are left out (references are kept).

## Deployments

### Deployment resource
```json
{"id": "01k…", "site_id": "01k…", "number": 42,
 "status": "queued|waiting|building|deploying|succeeded|failed|cancelled",
 "phase": "build|fetch|prepare|migrate|activate|restart|healthcheck|rollback|null",
 "trigger": "manual|push|api|hook|rollback", "strategy": "zero-downtime",
 "branch": "main", "commit": "a1b2c3…", "message": "Fix checkout", "author": "Ada",
 "release_id": "01k…", "build_id": "01k…", "rolled_back": false,
 "rolled_back_reason": null, "rolled_back_at": null, "auto_rollback_of": null, "watch": null,
 "url": "https://falak.example.com/sites/01k…/deployments/01k…", "error": null,
 "waiting_reason": null, "waiting_since": null,
 "created_at": "…", "started_at": "…", "finished_at": "…"}
```
`rolled_back: true` with `status: failed` means servers that had switched were returned to the previous release.
With `status: succeeded` it means the release went live and its watch rolled it back (`rolled_back_reason` says
which trigger, with the numbers); `auto_rollback_of` on a `rollback` deployment names the deployment it replaced.

`watch` (null unless the site watched the release, see "Watch after deploy" below):
```json
{"status": "watching|passed|rolled_back|alerted|stopped", "on_trigger": "rollback|alert_only",
 "triggers": {"health": true, "health_failures": 3, "crashes": true, "errors": true, "issues": false},
 "migrations": true, "started_at": "…", "ends_at": "…", "remaining_s": 212, "checked_at": "…",
 "checks": {"health": {"ok": true, "failures": 0, "threshold": 3, "message": "GET https://shop.example.com/up via 203.0.113.1 → 200 …"},
            "errors": {"total": 412, "errors": 3, "rate": 0.0073, "threshold": 0.05, "min_requests": 20}},
 "baseline": {"total": 5120, "errors": 21, "rate": 0.0041},
 "trigger": null, "reason": null, "rollback_deployment_id": null, "finished_at": null}
```
`alerted`: a trigger fired but the site is set to alert only, or the loop guard held the rollback back (`reason`
and the alert say why). `stopped`: another deployment of the site started or went live.

`waiting`: the deployment was triggered while some of the site's servers are still being prepared (site user,
PHP-FPM pool, Bun/Deno runtime). It holds the site's queue, `waiting_reason` says why
(`"Waiting for 2 servers to finish preparing: web-1, web-2"`) and `waiting_since` when it began; it starts on its
own once every preparing server is ready (`started_at` is set then). Servers whose preparation failed are skipped
with a warning in the output as long as another server is ready; it fails (`error` says why) when the leader's
preparation fails, when no server can be prepared, or after `FALAK_DEPLOY_WAIT_TIMEOUT_MINUTES` (default 30).

### Watch after deploy — `GET|PUT /api/v1/sites/{site}/release-watch` — `deployments.view` / `deployments.manage`
Rollback after a release goes live, opt-in per site (suggested for production services). `PUT` takes any of
`{"enabled": true, "minutes": 5, "health": true, "health_failures": 3, "crashes": true, "errors": true,
"issues": false, "on_trigger": "rollback|alert_only"}` (`minutes` 1–60, `health_failures` 1–20); both return those
fields plus `migrations` (the deploy script, or a compose `falak.deploy.leader_command`, runs database migrations,
which a rollback doesn't reverse) and `production` (the site's environment). Changes apply from the next deployment.

After a successful deployment (not the site's first, not a rollback, not a function) a window opens for `minutes`.
Every 30 s the health check runs through the edge on each server (`health_failures` failures in a row, at least 25 s
apart, trip it; skipped while the site's health check is off, and servers without an address are skipped), and the
release's 5xx share in the edge access log (the control plane's own health checks left out) is compared with
`max(3 × baseline, 5%)` once it has served at least 20 requests and 5 errors (baseline: the previous release's last
hour; no data → 5%). OOM kills and restart loops of the site, its compose services, workers and daemons that happen
after the release went live trip it at once, as does (opt-in) a new exception issue in Insights — Insights doesn't
record which release raised an issue, so any new one during the window counts; hence off by default. A window only
opens for the site's live release with nothing queued behind it. The first
trigger queues a `rollback` deployment to the previous release (`deployments.rolled_back` fires once it is live), or
with `alert_only` fires `deployments.watch_triggered`. Loop guard: never back to a release that was itself rolled back
automatically, at most one automatic rollback per site per hour, never while another deployment of the site is
queued or running (checked again under the site's trigger lock); a held-back rollback alerts instead. A rollback that
only starts after another release went live is cancelled with the reason.

### `POST /api/v1/sites/{site}/deployments` — `deployments.create`
Body (all optional): `{"branch": "main", "commit": "<sha>"}`. Without a commit the branch head is resolved
through the source-control provider. → `201 {"data": Deployment}`. The deployment starts immediately
(`building`), waits behind the site's running deployment (`queued`), or waits for the site's servers to finish
preparing (`waiting`). While a (non-rollback) deployment is `waiting`, further triggers — this endpoint, the CLI,
the panel, push-to-deploy, deploy hooks — update that deployment instead of creating another: the latest
branch/commit wins, and the response is that deployment (same `id`).

### `GET /api/v1/sites/{site}/deployments` — `deployments.view`
Paginated, newest first.

### `GET /api/v1/deployments/{deployment}` — `deployments.view`
Deployment + `targets`:
```json
{"targets": [{"id": "…", "server_id": "…", "server_name": "web-1", "role": "leader", "batch": 0,
  "status": "pending|deploying|succeeded|failed|rolled_back|skipped", "activated": true, "error": null,
  "steps": [{"key": "fetch:…", "kind": "fetch", "label": "fetch", "phase": "fetch", "rollback": false, "batch": 0,
             "status": "pending|running|succeeded|failed|skipped", "command_id": "…", "exit_code": null, "error": null,
             "started_at": "…", "finished_at": "…", "duration_ms": 812}]}]}
```

### `GET /api/v1/deployments/{deployment}/output?after=<seq>` — `deployments.view`
Lines with `seq > after` (≤ 1000 per page), in order. Poll with `after = meta.next` until the deployment is
terminal (`meta.status`). `server` is null for build/orchestration lines.
```json
{"data": [{"seq": 1812, "at": "…", "server": "web-1", "server_id": "…", "step_id": "…",
           "phase": "migrate", "stream": "stdout|stderr", "data": "Migrating: …\n"}],
 "meta": {"next": 1812, "status": "deploying"}}
```

### `POST /api/v1/deployments/{deployment}/cancel` — `deployments.create`
Cancels a `queued` or `waiting` deployment, or one still `building` (nothing has touched the servers yet).
→ `200 {"data": Deployment}`; `422` otherwise.

### `POST /api/v1/sites/{site}/rollback` — `deployments.rollback`
Body `{"release_id": "<ulid>"}` (optional; default = the newest retained release before the current one).
→ `201 {"data": Deployment}` with `trigger: "rollback"`. `422` (`errors.release_id`) when the release is current,
failed or pruned, or when there is nothing to roll back to.

### `GET /api/v1/sites/{site}/releases` — `deployments.view`
Retained releases, current first.
```json
{"data": [{"id": "01k…", "commit": "…", "branch": "main", "message": "…", "author": "Ada",
           "deployment_id": "…", "build_id": "…", "image": null,
           "status": "active|inactive", "active": true, "can_rollback": false,
           "activated_at": "…", "created_at": "…"}]}
```

## Functions

Cloud Functions are sites with the `function` runtime (docs/FUNCTIONS.md); `{site}` is the function's id or slug.
The `falak fn` commands use these endpoints.

### `GET /api/v1/functions` — `functions.view`
```json
{ "data": [{ "id": "01j…", "name": "Hooks", "slug": "hooks", "runtime": "bun", "entrypoint": "index.ts",
             "live": { "number": 3, "hash": "…", "short_hash": "4f1c2a9" }, "url": "https://hooks.example.com" }] }
```

### `GET /api/v1/functions/{site}` — `functions.view`
`{data: {site: {id, name, slug}, runtime: {key, label, language, family}, entrypoint, url, head, live, settings,
schedules}}`. `head` is the newest version **with** `files` (`{path: content}`); `live` is the version the servers
run (without files). A version is `{id, number, hash, short_hash, message, author, size, created_at}`.

### `POST /api/v1/functions/{site}/deploy` — `functions.deploy` + `deployments.create`
`{files: {path: content}, message?, base_version_id?, force?}`. Saves the files as a new version (unless they equal
the newest one) and deploys it: `201 {data: {version, created: true, deployment_id}, warnings[]}` (`200` with
`created: false` for unchanged code). Without `base_version_id` the code is deployed on top of the newest version.
When someone deployed after `base_version_id`: `409 {message, head}` (the newer version, with files), unless
`force: true`. `files` is the function's complete file set (files left out are removed in the new version); code
with more than one file is saved but not deployed while the server's agent is older than `fn.v3` (a warning says
so). Follow the deployment with `GET /api/v1/deployments/{deployment_id}`.

### `GET /api/v1/functions/{site}/versions` · `GET /api/v1/functions/{site}/versions/{number}` — `functions.view`
Newest first (up to 200); a single version includes `entrypoint`, `files` and `changes`
(`[{path, status: added|removed|modified}]` against the previous version).

### `POST /api/v1/functions/{site}/versions/{number}/deploy` — `functions.deploy` + `deployments.create`
Deploys that version again (a rollback when it is not the newest). `201 {data: {deployment_id}}`; `422` when the
version has several files and the server's agent is older than `fn.v3`.

### `POST /api/v1/functions/{site}/schedules/{schedule}/run` — `functions.deploy`
Runs a schedule now (`{schedule}` = its id, key or name). `202 {data: {run_id, schedule}}`.

### `GET /api/v1/functions/{site}/runs/{run}` — `functions.view`
`{data: {status, finished, exit_code, duration_ms, error, output}}`; poll until `finished`. Runs started from the
panel can be read here too.

## Deploy hooks

### `GET|POST /api/deploy/{token}`
The URL is shown (and regenerated) under *Site → Deploy settings*. Reserved query parameters:

| Parameter | Meaning |
|---|---|
| `falak_deploy_branch` | branch to deploy (default: the site branch) |
| `falak_deploy_commit` | exact commit SHA (7–64 hex) |
| `falak_deploy_author` | author shown in the UI / `FALAK_COMMIT_AUTHOR` |
| `falak_deploy_message` | message shown in the UI / `FALAK_COMMIT_MESSAGE` |

Every other parameter becomes `FALAK_VAR_<NAME>` in the deploy script environment (name upper-cased,
non-alphanumerics → `_`; ≤ 50 variables, ≤ 4 KiB each; stored encrypted). → `202`
`{"data": {"id", "status", "number", "url"}}`; `404` for unknown/rotated tokens; `422` for an invalid commit.
Rate limited to 30/min.

## Internal builder API

`falak-builder serve --url https://falak.example.com --token kbt_…` (env `FALAK_URL`, `FALAK_BUILDER_TOKEN`,
`FALAK_BUILDER_NAME`). Tokens: the control-plane host builder uses `FALAK_LOCAL_BUILDER_TOKEN` (serves every
organization); builder servers get one installed automatically when they finish provisioning; external
builders are created under *Builds → Builders* (organization-scoped). `401` for unknown/disabled tokens.

Docker-mode builds run in a BuildKit container (buildx `docker-container` driver) that falak-builder creates and
recreates, keeping its cache, when these change: `FALAK_BUILD_MEMORY_MB`, `FALAK_BUILD_CPUS`,
`FALAK_BUILD_CGROUP_PARENT` (its limits) and `FALAK_BUILD_NETWORK` (its network: `host`, `bridge` or a Docker
network name). That container resolves names on its own Docker network, not through the host's `/etc/hosts`, and it
is what pushes to the registry: on a builder that reaches the registry host under a private address (split DNS, a
private network), set `FALAK_BUILD_NETWORK=host` so the push takes the same route as the host.

### `GET /api/internal/builds/next?wait=<s>&builder=<name>&run=<run id>`
`run` identifies the falak-builder process (random, new at every start). A poll with a new run id first fails the
builds that an earlier run with the same `builder` name had claimed (`Builder <name> restarted during the build.`),
so a restarted builder (e.g. `falak-ctl update` recreating the container) does not leave them running until the
build timeout. Long-poll (≤ 25 s). `204` when nothing is queued for the builder (organization + mode eligibility), else
`200` with a job (`agent/internal/builder/job.go` `Job`):
```json
{"id": "01k…", "mode": "native", "timeout_s": 1800, "runtime": "php",
 "repo": {"url": "git@github.com:acme/shop.git", "ref": "main", "commit": "a1b2…",
          "deploy_key": "-----BEGIN OPENSSH PRIVATE KEY-----…", "known_hosts": "…"},
 "env": {"VITE_APP_NAME": "Shop"},
 "native": {"upload": {"url": "https://falak.example.com/api/internal/artifacts/…?expires=…&signature=…",
                       "headers": {"Content-Type": "application/octet-stream"}}}}
```
Jobs of a site with a `root_directory` carry it as `"subdir"`: the app root inside the checkout (a subdir resolving
outside the repository, e.g. through a symlink, fails the build).
Docker jobs carry `"docker": {"image": "<registry>/<namespace>/<site-slug>:<build-id>", "dockerfile": "…",
"build_args": {…}, "registry": {"server", "username", "password"}, "push": true}` instead of `native`.
Clone credentials come from SourceControl at hand-out time and are never stored. HTTPS clones use
`token`/`username` instead of `deploy_key`. `env` holds site variables with public front-end prefixes
(`builds.env_prefixes`) plus the variables exposed to the deploy script (the per-variable opt-in for other
build-time settings, e.g. Astro's `SITE_URL`). Native jobs carry `native.install_command` / `native.build_command`
when the site defines the variables `FALAK_INSTALL_COMMAND` / `FALAK_BUILD_COMMAND` (run with `sh -c`, replacing the
detected install / build step).

### `POST /api/internal/builds/{build}/events`
NDJSON body, one `contracts/agent-protocol/event.schema.json` object per line with `command_id` = build id.
Idempotent on `(build, seq)`. `started` → running; `output` → build log (live on `private-builds.{id}`, copied
into the deployment output as phase `build`); `progress`; `finished` with `exit_code` 0 and the builder
`Result` (`artifact.sha256|size_bytes|format` or `image.ref|digest`) → succeeded, otherwise failed
(`124` → timed out). With the local artifact driver the uploaded file's SHA-256 must match.
Responses: `204`; `404` build unknown or assigned to another builder; `413` batch > 8 MiB; `422` malformed line;
**`410` the build was cancelled — the builder aborts it** (`HTTPSink.OnGone`).

### `POST /api/internal/builds/{build}/heartbeat`
Sent every 20 s while a build runs. `204`; `404` unknown build or another builder's; `410` the build is over on the
control plane (cancelled, failed by the watchdog, reaped) — the builder aborts it. A running build of a builder that
reports run ids fails after `FALAK_BUILD_HEARTBEAT_TIMEOUT` seconds (default 90) without a heartbeat or event.

### Artifacts (local driver)
`PUT /api/internal/artifacts/{key}` (builder upload) and `GET /api/internal/artifacts/{key}` (agent
`deploy.fetch`) are authorized by the signed, expiring URL alone (`403` otherwise). URLs are always `https`
(`FALAK_ARTIFACTS_URL`, default `APP_URL`). With `FALAK_ARTIFACTS_DRIVER=s3` the builder and agents talk to the
bucket directly through SigV4-presigned URLs instead.

### `GET /install/builder/linux-{amd64|arm64}`
falak-builder binary for builder servers (from `FALAK_BUILDER_BINARIES_PATH`, or `FALAK_BUILDER_DOWNLOAD_URL`).
