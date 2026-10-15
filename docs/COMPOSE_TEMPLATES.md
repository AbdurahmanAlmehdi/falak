# Docker Compose deploys + template engine (binding spec, roadmap step 4)

Two lanes built in parallel against the contract in §5. Design decisions are marked **[decision]** — they were made
without user input to keep momentum; revisit if the user disagrees.

---

## 1. Compose sites (lane A)

A site with `runtime = compose` is a Docker Compose project managed by Falak: deployed, routed, observed, rolled back.

### 1.1 Source of the compose file
| `compose_source` | Where the compose file comes from | Used by |
|---|---|---|
| `repo` | `compose_file` path in the site's git repository (default `compose.yaml`, then `docker-compose.yml`) | Git-backed apps |
| `inline` | `compose_content` stored in Falak, **versioned** like environment variables (history + restore) | Templates, pasted stacks |

Repository sources ([plans/COMPOSE_APPS.md](plans/COMPOSE_APPS.md)): `compose_files` (several files merged in `-f`
order; `compose_file` is the first), `compose_profiles` (services of other profiles don't run), `include` and
`extends` (files from the repository only). falak-builder merges the project like `docker compose config` with every
relative path rebased to the repository root, and ships the repository files it mounts or reads (bind sources,
`env_file`, `configs`/`secrets` `file:`; at most 200 files / 2 MB) with each release under `<release>/repo/` (agent
feature `compose.v2`). The control plane previews the same project (`ComposeProject`); both follow
`contracts/compose/merge-cases.json`.

**Falak adjustments** (render time; the repository is never edited, Settings → Compose shows the diff): services
replaced by a Falak database or split into their own Falak site are removed with their `depends_on`, and the stack's
variables that pointed at them are rewritten; `container_name` is removed; `restart: unless-stopped` is added where no
policy is set; bind sources the repository lacks become named volumes `<service>-<path>` (kept across deploys) unless
the user keeps them as folders; env files the repository lacks are dropped and only those services get Falak's `.env`
(every site variable) instead — other services read site variables through `${VAR}` interpolation, so third-party
images don't receive unrelated secrets; bind mounts and env files naming Falak's own release files (`./.env`,
`./compose.yaml`) keep pointing at them; public services without a healthcheck get a warning. Repository paths may
use any name except `.`/`..`/empty segments, backslashes and control characters (same rule in the builder, the
agent and the control plane); the release's `repo/` is rebuilt from scratch on every write, without following links.
Previews read the project under the site's root directory and check each referenced path (files or folders; at
most 100 lookups in 20 s). Only `${VAR:?…}` / `${VAR?…}` are required variables; viewers see services and variable
names but not the YAML or env-file values.

### 1.2 Builds — managed servers never build **[decision]**
Services with `image:` are pulled on the server. Services with `build:` are built by **falak-builder in docker mode**
(a `builder` server or a host builder with `FALAK_LOCAL_BUILDER_MODES=native,docker`), pushed to the built-in
registry (production: `registry` service behind `https://registry.<domain>` with basic auth, `FALAK_REGISTRY_*` in
`.env`, docs/INSTALL.md §2), and the rendered compose file references them **by digest**. `repo` sources only; `inline` compose may
not use `build:` (validation error). Old images are deleted daily by `falak:registry-prune` (builds past
`FALAK_ARTIFACTS_KEEP`, never an image a pending, live or rollback release references — Deployments'
`RetainedImages` contract) and their layers freed by the weekly `falak-ctl registry gc` (docs/INSTALL.md → Registry
storage).

### 1.3 Rendering (control plane, per release)
Falak renders the compose file the agent receives:
- Interpolation stays Compose-native (`${VAR}`); the site's variables (after `${{ service.KEY }}` resolution) are
  written as the project `.env` and passed as `env`. `FALAK_SITE_ID/SERVER_ID/DEPLOYMENT_ID/RELEASE_ID` are added.
- Images pinned to digests where known (built images always; pulled images resolved on first deploy and recorded
  in the release so rollback is exact) **[decision]**.
- **Public services**: `public_services: [{service, port, domain?, health_check_path?}]`. For each, Falak publishes
  `127.0.0.1:<allocated host port>:<port>` on that service (removing any other host port mapping for it) and Edge
  routes the service's domains (and test domain `<service>-<slug>.<FALAK_TEST_DOMAIN>` / `<slug>` for the first) to
  it. On creation `domain` may also be a choice `{type: generated|test|custom, name?}`; a generated one is
  `<service>-<slug>.<leader-ip-with-dashes>.sslip.io` (docs/API.md → Domains and DNS).
- **Edge per public service** (docs/plans/COMPOSE_APPS.md, phase 2): every public service's domains are Edge domain
  rows (`compose_service`; the first public service is the site itself), so each service can have several domains
  (generated / test / custom / Cloudflare names, automatic TLS, `www` redirects), Cloudflare records, proxy and cache
  mode per domain, purge after deploy and tunnel routing. Redirects, basic auth, headers and function paths apply to
  the whole site or to one service; IP lists per service (its allow list replaces the site's, its deny list adds).
  The `domain` chosen in `public_services` becomes the service's first domain; afterwards `public_services[].domain`
  mirrors the service's primary domain. The deploy health check requests each public service through its own
  domains (`health_check_path`, else the site's check path for the first service and "any answer below 500" for the
  others).
- Labels `falak.site`, `falak.release`, `falak.service` on every service (logs/metrics attribution).
- **Policy** (org setting "Allow privileged compose", off by default): reject `privileged: true`, `network_mode: host`,
  `pid: host`, `cap_add` beyond a safe list, host bind mounts outside the release dir, `devices`, and
  `/var/run/docker.sock` mounts. Named volumes are always allowed.

### 1.4 Deploy flow (Deployments, strategy `compose`)
Release layout `/srv/falak/sites/<slug>/releases/<id>/` with the rendered `compose.yaml` + `.env`; project name =
site slug (stable, so **named volumes persist across releases**).
`FETCH` (write files, `docker compose pull`) → `ACTIVATE` (`docker compose up -d --remove-orphans --wait
--wait-timeout <health timeout>`) → `HEALTHCHECK` (Falak HTTP check of each public service through the edge, like
other sites) → success. On failure: **rollback** = `up --wait` with the previous release's files (digest-pinned) +
alert. Multi-server: the project runs on every target (replicated) **[decision]**; the create flow warns when a
template is marked `stateful` and more than one server is picked. `MIGRATE`/leader hooks: optional
`falak.deploy.leader_command` on a service (`docker compose run --rm <service> <cmd>` on the leader before activate).

### 1.5 Agent
- `docker.compose.up` gains `wait`, `wait_timeout_s`, `remove_orphans`, `project_env_file`; results include
  per-service state and resolved image digests.
- New `docker.compose.ps` (per-service: state, health, image digest, ports, restarts) and
  `docker.compose.restart` (optional service list). Schemas in `contracts/agent-protocol/commands/`.
- Logs: containers labelled `falak.site` are tailed to OTLP logs with `service.name=<slug>` and
  `falak.compose.service`; `docker stats` per container → OTLP metrics (`falak.container.*`).

### 1.6 UI (panel, compose sites)
- **Services** tab: one row per compose service — state/health, image (digest short), ports/public URL, restarts,
  CPU/mem, actions (restart, logs filtered to that service).
- **Settings → Compose** section: source (repo path / inline editor with YAML syntax highlighting + validation +
  diff + history), public services (service picker from parsed compose + port + domain), policy status.
- **Settings → Networking**: a service picker; domains, certificates and DNS checks per public service, rules for all
  services or one.
- Canvas card subtitle: `Compose · 3 services`; status aggregates service health.

### 1.7 Services that run as Falak services (docs/plans/COMPOSE_APPS.md, phase 3)
`Sites\Contracts\ComposeServiceExtraction` takes a service out of the stack; the decision is stored in
`compose_services` (`{<service>: {mode: database|site, database_id|site_id, rewrites}}`) and the service leaves the
stack's public services.
- **`toDatabase`** — a `postgres`/`mysql`/`mariadb` image service becomes a Falak database on the stack's leader (named
  after `POSTGRES_DB` / `MYSQL_DATABASE` / `MARIADB_DATABASE`, else `<slug>_<service>`), or links an existing one of
  the same engine and environment. Projects places it next to the stack as "<stack> <service>". Redis is not a Falak
  database: keep it in the stack.
- **`toSite`** — an app service becomes its own site from the same repository and branch: `root_directory` = its
  build context (relative to the compose file and the stack's own root directory; contexts outside the repository and
  remote contexts are refused), `dockerfile` and container port from the service, its `environment:` as variables
  (`${VAR}` / `${VAR:-default}` filled from the stack's variables) after the keys of its `env_file`s (read from the
  repository relative to the stack's root directory, also above it inside the repository, later files winning, `environment:` winning over them; Falak's own
  `.env`, `FALAK_*` keys and files Falak can't read add nothing), the stack's servers. The user picks framework,
  runtime, name and domain.
- **`rewrites`** — variables that pointed at the service, in the remaining services and in the stack's variables,
  and what they become: a URL with the service as host (`postgres://u:p@db:5432/app`, `http://api:8000/v1`), the bare
  name under a host-like key (`DB_HOST=db`, `PGHOST=db`) or with a port (`db:5432`), and for databases the companion
  keys of a service that points at it (`DB_`/`DATABASE_`/`POSTGRES_`/`PG`/`MYSQL_`/`MARIADB_` + `…PORT`, `…USER`,
  `…PASSWORD`, `…DB`/`…NAME`). Databases → `${{ <name>.KEY }}` references (resolved like any other; containers on
  the engine's server get the server address, see docs/API.md → Variable references); sites → `https://<primary
  domain>` plus the path. `DB_CONNECTION=mysql` (a driver name equal to the service name) is not a host.
- Rewrites are kept per group (`Sites\Contracts\Data\ComposeRewrites`): each remaining service, and the stack's own
  variables (`.stack`), so `DB_PASSWORD` in two services can point at two databases. The renderer drops the extracted
  services (and `depends_on` on them) and points a service's rewritten key at its own project variable
  (`DB_PASSWORD: ${FALAK_SVC_WORKER_DB_PASSWORD}`); the release `.env` gets those plus the stack's rewritten variables.
- Extracting needs the actor's permission for what it creates: `databases.manage` for a database, `sites.create` for
  a site (otherwise the service stays in the stack, with a warning). The service is claimed under the stack's row
  lock before anything is created; a failed creation gives it back.
- **Reaching the stack from a split-out site:** a service run as its own **Docker** site joins the stack's networks
  (`<stack-slug>_default`, or the networks it was on, by their Compose names with the stack's variables filled in;
  none for a `network_mode` service) on every server the stack runs on, under its service name plus the aliases it
  declared per network — so `postgres`, `redis` … still resolve from it, and the stack still reaches it as before
  (`compose_services[service].networks` / `.network_aliases`; agent feature `docker.networks`, `networks` on
  `docker.run` / `deploy.container.swap`). Before a deploy replaces its container the network must exist, so a
  missing one fails the deploy and leaves the running container in place; `docker compose down` on the stack first
  detaches Falak's containers from the stack's networks so Compose can remove them. A plain network of the stack's
  compose project (no definition, or only a `name:`) carries `compose {project, network}`
  (`compose_services[service].compose_networks`): when it doesn't exist yet the agent creates it with Compose's labels
  (`com.docker.compose.project`, `com.docker.compose.network`; agent feature `docker.networks.create`) and the
  stack's first `docker compose up` adopts it, so a service split out at the stack's creation can deploy first.
  Compose v2 reuses a labelled network without comparing its configuration, so a configured network (driver, driver
  options, IPAM, `internal`, IPv6, `attachable`, labels) is only ever created by Compose; it, `external` networks,
  reserved names (`bridge`, `host`, `none`, `default`, `falak*`) and agents without the feature are only waited for, up
  to 60 s, then the deploy fails with "deploy the compose stack first" (`waited_networks`, with a warning at
  extraction: split such a service out of a stack that already runs).
- **Falak database names:** a service replaced by a Falak database keeps its `POSTGRES_DB` / `MYSQL_DATABASE` name when
  it's free on the leader, else `<stack>_<name>`, then `_2`, `_3`…; the rewritten `DATABASE_URL` / `DB_DATABASE`
  carry the new name.
- **Deploy order:** the stack runs without its split-out services, which may in turn use some of its services
  (`uses`). A stack deployment that finds split-out sites not yet live records them (`settings.awaits_sites`) and:
  1. **bootstraps** a stack that never ran: `docker compose up --wait` of only the services those sites use that
     still run in the stack (plus their `depends_on`), no `--remove-orphans`, no leader command, no public-service
     check ("Bootstrap: started postgres, redis for <site>; the full stack follows once <site> is live.";
     `settings.bootstrap`, shown as **Partial** in the panel and as `partial` in the API; a rollback to that release
     starts the same subset; agent feature `compose.up.services`);
  2. otherwise (the stack already runs, the sites use nothing in it, or an agent lacks the feature) stops: "Deploying
     <site> first; the stack follows when it's live."
  Then `DeploySplitSitesFirst` deploys the waiting sites, and once all of them are live deploys the full stack once,
  with that deployment's commit unless a newer stack deployment exists, and only within 24 h
  (`ComposeSites::stacksUsing()`). A site whose deploy fails leaves the stack bootstrapped (or stopped) until the next
  deploy. A **native** site (Laravel, Node.js on the host), or a
  server without the stack, only reaches the stack's public services: the services table and extraction warn
  (`uses` in the inspect rows: `depends_on` plus hosts in its environment; at extraction, after the stack's variables
  are filled in). Without the stack the site keeps its own
  network only. A container joins at most 8 networks with Docker-safe names (`[a-zA-Z0-9][a-zA-Z0-9_.-]*`): others
  are left out at extraction with a warning (`compose_services[service].skipped_networks`) instead of failing every
  deploy. The legacy `external: {name: x}` form names the network x.

---

## 2. Template format (lane B)

`templates/<slug>/template.yaml` + `templates/<slug>/compose.yaml` (+ optional `icon.svg`), in the repo
(**curated catalog, versioned with Falak**) **[decision]**. Organizations can add **custom templates** (same format,
stored in the DB, imported from YAML or created from an existing compose site: "Save as template").

```yaml
name: n8n
slug: n8n
version: 1.0.0            # template version (bumped when the compose changes)
description: Workflow automation with 400+ integrations.
category: automation      # automation | analytics | cms | databases | dev-tools | monitoring | storage | communication | ai | business
icon: n8n                 # simple-icons key or ./icon.svg
docs: https://docs.n8n.io
stateful: true            # warn when deploying to more than one server
min_memory_mb: 512
public:
  - service: n8n
    port: 5678
    # health_check_path: /healthz   # optional: the deploy health check's path for this service (default: the site's
    #                               # check path for the first service, "any answer below 500" for the others)
inputs:
  - key: N8N_ENCRYPTION_KEY
    type: secret          # string | secret | email | number | boolean | select | domain
    generate: secret(32)  # secret(n) | password(n) | uuid | hex(n)
    label: Encryption key
  - key: TIMEZONE
    type: select
    options: [UTC, Europe/Berlin, America/New_York]
    default: UTC
```

`compose.yaml` uses Compose interpolation for inputs (`${N8N_ENCRYPTION_KEY}`) and Falak placeholders rendered once
at creation: `${{ falak.url(<service>) }}` (public https URL of a public service), `${{ falak.domain(<service>) }}`,
`${{ falak.site }}` (slug). Inputs become **site variables** (secrets encrypted, generated once — stable across
redeploys); they can reference other services: `${{ postgres.DATABASE_URL }}` (§5.3 of UI_DESIGN.md).

**Validation** (catalog CI test + import): schema of template.yaml, compose parses, every `${VAR}` is an input or a
known Falak variable, public services exist and expose the port, policy (§1.3) passes, image tags pinned (no
`latest`) **[decision]**.

### 2.1 Catalog v1 (pinned image versions, each with a working healthcheck)
n8n · Uptime Kuma · Plausible (+ClickHouse, Postgres) · Umami (+Postgres) · Ghost (+MySQL) · WordPress (+MariaDB) ·
Metabase · Gitea · MinIO · Meilisearch · Mailpit · Vaultwarden · Directus (+Postgres) · Listmonk (+Postgres) ·
NocoDB · Redis Stack · Grafana OSS · Appsmith.

Catalog v2 adds: Ollama + Open WebUI · Langflow (+Postgres) · Qdrant · changedetection.io · ntfy · Nextcloud
(+MariaDB, Redis) · Paperless-ngx (+Redis) · BookStack (+MariaDB) · Wiki.js (+Postgres) · Forgejo · code-server ·
Excalidraw · Adminer · Matomo (+MariaDB). Every image supports amd64 and arm64.

Catalog v3 adds ERP: Odoo 19 (+Postgres; threaded, so `/websocket` is served on the public port 8069 — the edge
routes per service, not per path, to the gevent port 8072) · ERPNext v16 (frappe_docker: backend, nginx frontend,
websocket, two queues, scheduler, MariaDB, two Redis; 4 GB). One-time jobs (ERPNext's `configurator` and
`create-site`) do their step and then stay up idle (`exec sleep infinity`, healthy once a marker exists) instead of
exiting: a catalog service restarts unless stopped and has a healthcheck, and an exited container is reported as down
(`ComposeServiceState::failing()`). Their step must be idempotent (it reruns on every start), and the health marker
lives in `/tmp`, removed first, so it never outlives a restart. `create-site` migrates only a site that has
`.falak-installed` (written once `bench new-site` returned); a site without it, an install cut short, is dropped and
installed again. It sets Administrator's password from `ADMIN_PASSWORD` on every start. Odoo's database credentials are
written to its config file (0600) rather than passed on `odoo`'s argv.

---

## 3. Template UX (lane B)
- **Create picker → Template**: gallery with search, categories, popular; card = icon, name, description,
  services count. Also `/templates` full page and ⌘K "Deploy template…".
- **Template detail → Configure**: inputs form (generated secrets hidden behind "Show / Regenerate"), domains per
  public service (the domain picker: generated sslip.io name — the default without a test domain —, the test domain,
  or your own with the DNS records to add and a live DNS check), server picker (warning if `stateful` and >1), resources hint → **Deploy**:
  creates the compose site at the chosen canvas position and opens its panel on the deploy stream.
- **Settings → Templates** (org): custom templates list, import YAML (paste/upload/URL), edit, delete,
  "Save as template" action in a compose site's panel `⋯` menu.

## 4. Verification
- Pest: rendering (public port rewriting, labels, digest pinning, policy rejections, inline history), Deployments
  compose flow incl. rollback, template schema + every catalog template validated in CI, input generation stability,
  custom template import, create-from-template end-to-end with fakes.
- Go: new/extended executors with fake runner; schema examples.
- **Sim E2E** (`sim/e2e-deploy.sh` new stages): deploy a repo compose app (sim git fixture with `build:` + redis),
  and deploy 2 catalog templates (e.g. Uptime Kuma, Umami) — assert public URL responds through the edge, container
  logs reach Loki, redeploy keeps the named volume data, broken release rolls back.
- Playwright: gallery, configure form, Services tab, Compose settings section, both themes.

---

## 5. Contract between lanes (fixed)
**Lane A** (compose runtime) exposes via `Sites\Contracts\SiteFactory::create($org, $user, $data, $placement)` the
site fields:
```php
[
  'runtime' => 'compose',
  'compose_source' => 'inline' | 'repo',
  'compose_content' => string|null,           // inline only
  'compose_file' => string|null,              // repo only
  'public_services' => [['service' => 'n8n', 'port' => 5678, 'domain' => null]],
  'variables' => ['KEY' => 'value', ...],     // initial environment (encrypted), may contain ${{ ref }} values
  'template' => ['slug' => 'n8n', 'version' => '1.0.0', 'source' => 'catalog' | 'custom'] | null,
]
```
and `Sites\Contracts\ComposeInspector::parse(string $yaml): ComposeSummary` (services, exposed ports, volumes,
policy violations) — lane B uses it for validation and the "Save as template" flow.

**Lane B** (templates) owns the new **Templates** module, `templates/` catalog, rendering of Falak placeholders +
input generation, and the template UIs; it calls only the contracts above (with test fakes until lane A merges).
