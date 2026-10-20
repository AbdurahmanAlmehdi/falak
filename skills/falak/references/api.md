# Falak HTTP API (`/api/v1`)

The `falak` CLI is a client of this API. Use it directly for what the CLI lacks. Full payloads live in the Falak
repository's `docs/API.md`; routes are in `control-plane/modules/*/routes/api.php`.

## Auth and conventions

```bash
curl -fsS "$FALAK_URL/api/v1/me" \
  -H "Authorization: Bearer $FALAK_TOKEN" -H "Accept: application/json"
```

- **Tokens**: created in the panel under **Settings → API tokens** with a name, a list of **abilities** (permission
  names, or `*`) and an optional expiry. A token is pinned to **one organization**. A request passes only when the
  token has the ability **and** the token owner's role grants that permission. You can't grant abilities you don't
  hold. `falak login` (browser) mints a `*` token named `CLI — <host>` that expires 90 days after its last use.
- Always send `Accept: application/json`; JSON bodies with `Content-Type: application/json`.
- Responses wrap payloads in `{"data": …}`; paginated lists add `links` and `meta` (`?page=`, `?per_page=` ≤ 100).
- Ids are lowercase ULIDs. Sites accept **id or slug** (`{site}`); servers and database instances id (the CLI also
  resolves names).
- Errors: `401` bad token · `403 {message}` ability or role missing · `404` not found **or another organization's** ·
  `409 {message}` state conflict · `422 {"message", "errors": {field: [...]}}` validation · `429` rate limited ·
  `503` log backend (Loki) unavailable.
- Mutating routes are rate limited (typically 10-60/min). Back off on `429`; never retry a destructive call blindly.

Never put the token in a URL, a committed file or the transcript. Read it from `$FALAK_TOKEN`.

## Abilities (permission names)

| Ability | Default roles | Grants |
|---|---|---|
| `sites.view` | admin, developer, viewer | list/show sites |
| `sites.create` / `sites.manage` / `sites.delete` | admin+developer / admin+developer / admin | create sites / settings, limits, Laravel toggles / delete sites |
| `sites.env.view` / `sites.env.manage` | admin, developer | read (audited as a reveal) / replace the site `.env` |
| `deployments.view` / `deployments.create` / `deployments.rollback` / `deployments.manage` | view: all; others admin, developer | deployments, output, releases / deploy, cancel / roll back / strategy, release watch |
| `telemetry.view` | all | site logs, access logs |
| `servers.view` / `servers.manage` / `servers.delete` | view: all; manage: admin, developer; delete: admin | servers / stack, PHP, machine check, (re)provision / delete |
| `projects.view` / `projects.manage` | view: all; manage: admin, developer | projects and environments |
| `functions.view` / `functions.deploy` | view: all; deploy: admin, developer | functions / deploy code and versions (with `deployments.create`), run schedules |
| `databases.view` / `databases.manage` | view: all; manage: admin, developer | database containers / create, change, delete |
| `secrets.view` / `secrets.manage` / `secrets.reveal` | view: all; others admin, developer | names and metadata / create, set, roll back, delete / read a non-sensitive value (must be granted **by name**, `*` is not enough) |
| `config.view` / `config.apply` / `config.approve` / `config.destroy` | view: all; apply: admin, developer; approve, destroy: admin | falak.yaml validate/plan/export/runs/drift / create runs, source / approve risky runs / `allow_destroy` |
| `edge.view` / `edge.manage` | view: all; manage: admin, developer | domain options, DNS check / Cloudflare rate limits |
| `templates.view` | all | template catalog (deploying also needs `projects.manage` + `sites.create`) |
| `sandboxes.view` / `sandboxes.use` / `sandboxes.manage` | view: all; use: admin, developer; manage: admin | list sandboxes / create, exec, files, console, extend, kill / organization sandbox settings |
| `terminal.open` | admin | consoles |
| `network.tools` / `network.capture` | admin | `falak net` tools / packet captures |
| `security.view` / `security.fix` | view: all; fix: admin | security baseline reports / audits and fixes |
| `fleet.agents.manage` | admin | upgrade server agents |
| `source_control.view` | all | source control connections |

## Identity

| Method and path | Ability | Notes |
|---|---|---|
| `GET /api/v1/me` | — | `{user, organization {id, name, slug, role}, token {name, abilities}}` |
| `DELETE /api/v1/me/token` | — | Revoke the calling token (`204`) |
| `GET /api/v1/organizations` | — | A token sees only its organization |
| `POST /api/v1/cli/login` · `POST /api/v1/cli/login/token` | none (no token) | RFC 8628 device flow used by `falak login` |

## Servers

| Method and path | Ability | Notes |
|---|---|---|
| `GET /api/v1/servers` · `GET /api/v1/servers/{server}` | `servers.view` | `status`: `creating`, `provisioning`, `needs_attention`, `active`, `error`, `deleting`; only `active` takes deploys |
| `DELETE /api/v1/servers/{server}` | `servers.delete` | **Destructive, no confirmation field**: `202` |
| `POST /api/v1/servers/{server}/stack` `{php?: {runtime, version?}, node?}` | `servers.manage` | Add runtimes and re-provision (`falak servers stack`) |
| `POST /api/v1/servers/{server}/php` `{version}` | `servers.manage` | Install another PHP version |
| `GET\|POST /api/v1/servers/{server}/inspection` | view / manage | Machine check result / re-check (read-only) |
| `POST /api/v1/servers/{server}/provision` · `/reprovision` | `servers.manage` | Apply the provisioning plan |
| `GET /api/v1/servers/{server}/capacity` | `servers.view` | Limits of everything on the server vs its RAM/CPUs, `overcommitted` |
| `POST /api/v1/servers/{server}/agent/upgrade` | `fleet.agents.manage` | Upgrade the agent |
| `GET /api/v1/servers/{server}/security` · `POST …/security/audit` · `POST …/security/fixes` | `security.view` / `security.fix` | Baseline report; some fixes need `confirm: true` (SSH hardening, reboots): human decision |

## Sites, env, logs

| Method and path | Ability | Notes |
|---|---|---|
| `GET /api/v1/sites` · `GET /api/v1/sites/{site}` | `sites.view` | |
| `POST /api/v1/sites` | `sites.create` | `name`, `framework`, `server_ids[]`, `runtime`, `build_mode`, `source_connection_id` + `repository` + `branch`, `push_to_deploy`, `php_version`, `domain` (`{"type": "generated"\|"test"\|"custom", "name"?}`), `project_id`/`environment_id`, `root_directory` … `201` + `warnings[]` |
| `DELETE /api/v1/sites/{site}` `{delete_volumes?}` | `sites.delete` (+ `sites.view`) | **Destructive**: `202`, the site is gone at once |
| `GET\|PUT /api/v1/sites/{site}/env` `{content}` | `sites.env.view` / `sites.env.manage` | Dotenv; PUT **replaces all variables**; applies on the next deploy |
| `PUT /api/v1/sites/{site}/laravel` | `sites.manage` | `scheduler`, `horizon`, `octane`, `maintenance`, `octane_server` |
| `GET\|PUT /api/v1/sites/{site}/limits` · `PUT …/compose/services/{service}/limits` | view / `sites.manage` | `{limits: {memory_limit, cpus, …}}` |
| `GET /api/v1/sites/{site}/logs?since=&limit=&level=&kind=&cursor=` | `telemetry.view` | `since` seconds (default 3600, ≤ 30 days), `limit` 1-1000, `level` trace…fatal, `kind` `app`\|`access` |
| `GET /api/v1/sites/{site}/access-logs?since=&limit=&cursor=&status=&path=&method=&client_ip=&server=&deployment=` | `telemetry.view` | Per-request edge log: `status`, `duration_ms`, `path`, `client_ip`… (`status` takes `404` or `5xx`) |

## Deployments

| Method and path | Ability | Notes |
|---|---|---|
| `POST /api/v1/sites/{site}/deployments` `{branch?, commit?}` | `deployments.create` | `201 {data: Deployment}`; status `queued\|waiting\|building\|deploying\|succeeded\|failed\|cancelled` |
| `GET /api/v1/sites/{site}/deployments` | `deployments.view` | Paginated, newest first |
| `GET /api/v1/deployments/{deployment}` | `deployments.view` | With per-server `targets` and steps |
| `GET /api/v1/deployments/{deployment}/output?after=<seq>` | `deployments.view` | Poll with `after = meta.next` until `meta.status` is terminal |
| `POST /api/v1/deployments/{deployment}/cancel` | `deployments.create` | Only `queued`, `waiting` or `building` |
| `POST /api/v1/sites/{site}/rollback` `{release_id?}` | `deployments.rollback` | Default: the previous retained release |
| `GET /api/v1/sites/{site}/releases` | `deployments.view` | Current first |
| `GET\|PUT /api/v1/sites/{site}/release-watch` | view / `deployments.manage` | Automatic rollback after a bad release |
| `GET\|POST /api/deploy/{token}` | the URL's token | Deploy hook (`falak_deploy_branch`, `falak_deploy_commit`); the URL is a secret |

## Projects and templates

| Method and path | Ability | Notes |
|---|---|---|
| `GET /api/v1/projects` · `GET /api/v1/projects/{project}` | `projects.view` | With `environments[]` (`slug`, `is_production`) |
| `POST /api/v1/projects` `{name, description?, icon?}` | `projects.manage` | Creates it with a `production` environment |
| `PATCH\|DELETE /api/v1/projects/{project}` | `projects.manage` | Only empty, non-default projects can be deleted |
| `GET\|POST /api/v1/projects/{project}/environments` `{name, from_environment_id?}` | view / manage | `from_environment_id` copies the sites (no databases) |
| `PATCH\|DELETE /api/v1/projects/{project}/environments/{environment}` | `projects.manage` | Only empty, non-production environments can be deleted |
| `POST /api/v1/projects/{project}/environments/{environment}/services` | `projects.manage` + `databases.manage` / `sites.create` | Canvas create. Database: `{kind: "database", engine: postgresql\|mysql\|mariadb\|redis\|valkey, server_id, name, version?, memory_mb?, disk_gb?}`; site: the `POST /sites` body with `kind: "site"` |
| `POST /api/v1/projects/{project}/{environment}/templates/{slug}/deploy` | `templates.view` + `projects.manage` + `sites.create` (deploys only with `deployments.create`) | `{server_ids: [...], name?, inputs?: {KEY: value}, domains?: {service: domain choice}, source?: catalog\|custom}` → `201 {data: {site_id, name, service_id, deployment_id, domains, panel_url}, warnings}`. `{project}` must be the project id |

Catalog template slugs (github.com/falak-sh/templates; panels fetch new ones hourly): adminer, appsmith, bookstack, changedetection,
code-server, directus, excalidraw, forgejo, ghost, gitea, grafana, langflow, listmonk, mailpit, matomo, meilisearch,
metabase, minio, n8n, nextcloud, nocodb, ntfy, ollama, paperless-ngx, plausible, qdrant, redis-stack, umami,
uptime-kuma, vaultwarden, wikijs, wordpress. Each `template.yaml` lists its `inputs` (keys, types, generated
defaults) and public services. Organizations may also have custom templates (`source: custom`).

## Databases

| Method and path | Ability | Notes |
|---|---|---|
| `GET /api/v1/databases/instances` · `GET …/{instance}` | `databases.view` | Never a password |
| `DELETE /api/v1/databases/instances/{instance}` `{confirm: "<name>", delete_volume: false}` | `databases.manage` (+ view) | **Destructive**; `delete_volume: true` destroys the data |

Backups, schedules, restores, PITR, upgrades and password changes have **no token API** (session routes of the
panel only). Ask the human to use the dashboard (Databases), or declare `backups:`/`pitr:` in falak.yaml.

## Functions

| Method and path | Ability | Notes |
|---|---|---|
| `GET /api/v1/functions` · `GET /api/v1/functions/{site}` | `functions.view` | `head` carries the newest version's `files` |
| `POST /api/v1/functions/{site}/deploy` `{files: {path: content}, message?, base_version_id?, force?}` | `functions.deploy` + `deployments.create` | `files` is the complete set; `409 {message, head}` when someone deployed after your base |
| `GET /api/v1/functions/{site}/versions` · `…/versions/{number}` | `functions.view` | |
| `POST /api/v1/functions/{site}/versions/{number}/deploy` | `functions.deploy` + `deployments.create` | Rollback |
| `POST /api/v1/functions/{site}/schedules/{schedule}/run` · `GET …/runs/{run}` | `functions.deploy` / `functions.view` | Poll the run until `finished` |

## Secrets

| Method and path | Ability | Notes |
|---|---|---|
| `GET /api/v1/secrets?scope=&scope_id=` · `GET /api/v1/secrets/{secret}` | `secrets.view` | Metadata only. Scopes: `organization`, `project`, `environment`, `service` |
| `POST /api/v1/secrets` `{name, scope, scope_id, value, sensitive?}` | `secrets.manage` | `sensitive` (default true) = write-only forever |
| `PUT /api/v1/secrets/{secret}/value` `{value}` | `secrets.manage` | New version, current from the next deployment |
| `POST /api/v1/secrets/{secret}/rollback` `{version}` | `secrets.manage` | New version with an older value |
| `DELETE /api/v1/secrets/{secret}` | `secrets.manage` | Every version; referencing deployments fail |
| `POST /api/v1/secrets/{secret}/reveal` | `secrets.reveal` by name | Audited. **Don't call it** unless the human asked for that value |

## Config as code

`{project}` is the project id. Bodies: `{file: "<raw text>", format: "yaml"|"json", environment}` (or `commit`
instead of `file`).

| Method and path | Ability | Notes |
|---|---|---|
| `POST /api/v1/projects/{project}/config/validate` | `config.view` | Always `200 {valid, environments, errors, warnings}` |
| `POST /api/v1/projects/{project}/config/plan` | `config.view` | `{plan: {changes, summary, risky, deploy}}`; `409` when not enabled |
| `POST /api/v1/projects/{project}/config/runs` `{…, allow_destroy?}` | `config.apply` (`config.destroy` for `allow_destroy`) | `201 {data: ConfigRun}` |
| `GET /api/v1/projects/{project}/config/runs` · `GET /api/v1/config/runs/{run}` | `config.view` | Status `planning`, `awaiting_approval`, `applying`, `applied`, `failed`, `cancelled`, `refused` |
| `POST /api/v1/config/runs/{run}/approve` `{plan_hash, comment?}` | `config.approve` | `plan_hash` from `approval.plan_hash`; `409` when the plan changed |
| `POST /api/v1/config/runs/{run}/reject` · `…/cancel` | `config.approve` / `config.apply` | |
| `GET\|POST /api/v1/projects/{project}/config/drift` | `config.view` | `in_sync`, `drifted`, `unknown` |
| `GET /api/v1/projects/{project}/config/unmanaged` · `…/config/export?environment=&format=` | `config.view` | |
| `GET\|PUT /api/v1/projects/{project}/config/source` | `config.view` / `config.apply` | `{connection_id, repository, path?, enabled}` |
| `GET /api/v1/source-control/connections` | `source_control.view` | Connection ids for the source |

## Sandboxes

Disposable gVisor machines. `{sandbox}` is the id (the CLI resolves names). `404` when the sandbox isn't in the
token's organization, `409` when it isn't running. Throttles: create 30/min, exec 120/min per token.

| Method and path | Ability | Notes |
|---|---|---|
| `POST /api/v1/sandboxes[?wait=1]` | `sandboxes.use` | `{image ("ubuntu:24.04"), name?, server? (id or name), cpus? (1), memory_mb? (1024), disk_mb? (10240), ttl? (seconds or "30m"; 3600), network? ("internet"\|"none"\|"allowlist"), allow_hosts?, env?, repo?: {url, ref?, secret?}}` → `201` sandbox (`creating`; `wait=1` waits up to 60 s for `running`) |
| `GET /api/v1/sandboxes[?status=]` · `GET /api/v1/sandboxes/{sandbox}` | `sandboxes.view` | `{id, name, status, image, server {id, name}, cpus, memory_mb, disk_mb, network, allow_hosts, ttl_seconds, expires_at, created_at, created_by {type, name}, ip, kill_reason}` |
| `PATCH /api/v1/sandboxes/{sandbox}` | `sandboxes.use` | `{name?, extend? (seconds or "30m")}` |
| `DELETE /api/v1/sandboxes/{sandbox}` | `sandboxes.use` | Kill (`204`); its files are gone |
| `POST /api/v1/sandboxes/{sandbox}/exec` | `sandboxes.use` | `{cmd: "shell line" \| ["argv", …], cwd?, env?, stdin? (text), timeout? (1-120, 60)}` → `{exit_code, stdout, stderr, truncated, timed_out, duration_ms}` (256 KB per stream). Synchronous; background long jobs with `nohup` |
| `GET /api/v1/sandboxes/{sandbox}/files?path=` | `sandboxes.use` | Raw bytes; `&list=1` → `{entries: [{name, type, size, mode, mtime}]}` |
| `PUT /api/v1/sandboxes/{sandbox}/files?path=&mode=` | `sandboxes.use` | Body = raw bytes (≤ 5 MB) → `{size}` |
| `DELETE /api/v1/sandboxes/{sandbox}/files?path=[&recursive=1]` | `sandboxes.use` | `204` |
| `GET /api/v1/sandboxes/{sandbox}/execs` | `sandboxes.view` | The exec audit trail `{id, cmd, exit_code, duration_ms, actor, started_at}` |
| `POST /api/v1/sandboxes/{sandbox}/console` | `sandboxes.use` | Terminal relay session; use `falak sandbox ssh` |
| `POST /api/v1/sandboxes/{sandbox}/snapshots[?wait=1]` | `sandboxes.use` | `{name?, stop? (false)}` → `201` snapshot `{id, name, status (creating\|ready\|failed), sandbox {id, name}, server, image, cpus, memory_mb, disk_mb, network, size_bytes, created_at, created_by}` |
| `GET /api/v1/sandboxes/snapshots[?sandbox=]` · `GET`/`PATCH {name}`/`DELETE /api/v1/sandboxes/snapshots/{snapshot}` | `sandboxes.view` / `sandboxes.use` | `{snapshot}` is an id or a name; `DELETE` → `204` |
| `POST /api/v1/sandboxes/snapshots/{snapshot}/fork[?wait=1]` | `sandboxes.use` | `{count? (1-10), name? (prefix), ttl?, env?, idle_pause?}` → `201` `{data: [sandbox]}` |
| `POST /api/v1/sandboxes` (also) | `sandboxes.use` | `snapshot?` (id or name; no image/cpus/memory_mb/disk_mb/network/server/repo with it), `idle_pause?` (seconds or "10m", ≥ 60), `db?: {instance, database}` |
| `POST /api/v1/sandboxes/{sandbox}/pause` · `/resume` | `sandboxes.use` | → the sandbox (`paused` / `running`). Exec, files and console resume a paused sandbox first |
| `POST /api/v1/sandboxes/{sandbox}/db` | `sandboxes.use` + backup download on the instance | `{instance, database}` → `{data: {path, size_bytes}}` (waits, ≤ 10 min) |

The sandbox resource also has `idle_pause_seconds`, `last_activity_at` and `from_snapshot {id, name}`; statuses add `paused`.

## Domains, consoles, network

| Method and path | Ability | Notes |
|---|---|---|
| `GET /api/v1/domains/options?server=<id>` · `?site=<site>` | `edge.view` | Test domain, generated name, DNS targets |
| `GET /api/v1/dns/check?name=<domain>&site=<site>[&tls=1]` | `edge.view` | `status`: `ok`, `mismatch`, `proxied`, `missing`, `error`, with the records to add |
| `GET\|PUT\|DELETE /api/v1/sites/{site}/domains/{domain}/rate-limit` | `edge.view` / `edge.manage` | Cloudflare rate limit of a proxied domain |
| `POST /api/v1/sites/{site}/console` · `/databases/instances/{instance}/console` · `/servers/{server}/console` | `terminal.open` | Returns a single-use relay URL (60 s); use `falak console` instead |
| `POST /api/v1/servers/{server}/net-tools` · `/sites/{site}/net-tools` · `/databases/instances/{instance}/net-tools` | `network.tools` | `{tool: ping\|traceroute\|dns\|http\|tcp, target?, url?, port?, …}`; poll `GET /api/v1/net-tools/runs/{run}?after=<seq>` |
| `POST …/net-captures` · `GET /api/v1/net-captures/{capture}/download` | `network.capture` | Packet capture; prefer `falak net capture` |
