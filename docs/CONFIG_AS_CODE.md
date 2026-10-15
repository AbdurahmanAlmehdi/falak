# Config as code (`falak.yaml`)

A project can describe how it runs in **one file at the root of its repository**: `falak.yaml` (or `falak.yml`, or
`falak.json` with the same content). The file lists the project's **units** (apps, Docker Compose stacks, functions,
databases, Redis/Valkey) and the **environments** they deploy to. On every push, Falak reads the file at the pushed
commit, plans what changes, applies it, and deploys the units that changed.

- The file says **how** things run: build, deploy strategy, health checks, env vars, workers, schedules, domains,
  limits, databases, volumes, backups, previews.
- It never holds **secrets** or **credentials**: secret values stay in Falak's secret store and the file references them
  (`${{ secrets.STRIPE_SECRET }}`); servers, storage providers, DNS credentials and certificates are **named**, never
  defined.
- What the file sets is **read-only in the dashboard** ("Managed by falak.yaml @ abc1234"); everything else stays
  editable.
- **Risky changes wait for an approval** (a database major upgrade, removing a production domain, changing production's
  servers…). Everything else applies on push.
- It is **opt-in per project**. A project without a file works exactly as before.

The schema is `https://falak.sh/schema/v1.json` (also in the repository: `contracts/config/v1.schema.json`). Put
`$schema` at the top of the file and editors with YAML/JSON schema support complete and check it as you type.

## Quick start

1. **Write the file.** In your repository, on your machine:

   ```bash
   falak init            # detects the code and writes falak.yaml, each value with the reason it was chosen
   falak validate        # offline: schema, environments, references (exit 5 if invalid)
   ```

   `falak init` reads the repository the way Falak's builder does (Laravel, Symfony, Next, Nuxt, Vite, Astro, Bun,
   Deno, static sites, a `Dockerfile`, a `compose.yaml`, `.env.example`, monorepo workspaces). Already running on
   Falak? `falak config export -p shop -e production -o falak.yaml` writes the project's **current state** as a file
   instead: pushing it as is changes nothing.
2. **Commit it** to the branch your production environment deploys from (usually `main`). Nothing happens yet.
3. **Turn it on**: Project settings → **Config as code**: pick the source control connection, the repository and the
   path (`falak.yaml` by default; a file in a folder works too), and enable it. Falak registers the repository's
   webhook. GitHub, GitLab and Bitbucket work; plain git servers can't be a config source yet. From the terminal:
   `falak config source set --repo acme/shop --connection <id> --enable` (the connection ids:
   `GET /api/v1/source-control/connections`).

   **A new project** hosts nothing yet, so no server is attached to it and the file can't place a unit anywhere
   (`name.server`). An admin lists the servers the file may use under **Servers the file may place units on** (or
   `falak config source set --allow-server app-1`). A project that already runs services can use their servers
   without this.
4. **Push.** The push creates a **config run** per environment bound to the pushed branch. Follow it in Project
   settings → Config as code, or from the terminal:

   ```bash
   falak plan            # what applying would change: + create, ~ update, - delete, ! risky (with the reason)
   falak apply --wait    # apply the local file now and stream the deployments
   falak runs            # the project's runs, newest first
   ```

A minimal file:

```yaml
$schema: https://falak.sh/schema/v1.json
version: 1

units:
  web:
    type: app
    runtime: frankenphp
    framework: laravel
    php: "8.4"
    env:
      APP_ENV: production
      DATABASE_URL: ${{ db.DATABASE_URL }}
      STRIPE_SECRET: ${{ secrets.STRIPE_SECRET }}
    deploy:
      health: { path: /up }
      hooks:
        before_activate: php artisan migrate --force
    workers:
      queue: { processes: 2 }
    domains: [shop.example.com]

  db:
    type: database
    engine: postgresql
    version: "17"
    memory: 1g
    backups: { cron: "0 3 * * *", storage: offsite, keep: 14 }

environments:
  production:
    branch: main
    servers: [web-1]
```

## How a push is applied

```
push ─▶ environments bound to the branch ─▶ read the file at the commit ─▶ validate ─▶ plan
        ─▶ risky? ── yes ─▶ wait for an approval ─┐
              │ no                                 │ approved
              ▼                                    ▼
        phase A (before any build) ─▶ deploy the units that changed ─▶ phase B (when each release is live)
```

1. **Which environments.** Those whose `branch` is the pushed branch, read from the file **at the pushed commit**. An
   environment without `branch` is applied only from the CLI (`falak apply -e staging`) or the dashboard ("Apply
   now"). A file without `environments` binds the project's production environment to the repository's default
   branch.
2. **Validate.** Four stages, each stopping the next on an error: reading (YAML/JSON), the schema, the merge of the
   environment's overrides and the references, and the organization checks (servers, secrets, storage, names,
   bounds). Errors point at the line and column.
3. **Plan.** Every change with its operation (`create`, `update`, `delete`, `detach`, `noop`) and, when risky, the
   rule and the reason. The plan is shown on the run, in `falak plan`, and on pull requests.
4. **Phase A**, before any build: environments, databases and Redis, sites and their build settings, env vars, deploy
   settings, limits, function settings, volumes, previews. A failure here stops the run and touches no running
   release.
5. **Deploy** each unit that changed: files under its `root` changed in the push, or its config changed. Units that
   didn't change are not redeployed. (When the push can't list its files — Bitbucket, a force push, 20 commits or
   more — every unit counts as changed.)
6. **Phase B**, when each unit's release is live (every server activated and the health checks passed): workers,
   daemons, schedules, domains, redirects, headers, mounts, function schedules, Laravel's scheduler/Horizon/Octane.
   A failed deployment never gets them, and a phase B that fails fails the run.
7. **Saved on the release.** Each release keeps the unit's config; rolling back restores it (see [Rollbacks](#rollbacks)).

One push makes one run per environment, never one per site: a repository with 30 units plans once per environment.
Runs of an environment apply one at a time, and a newer run supersedes the older ones not finished yet.

A **pull request** gets the plan for its target environment as a comment and a `falak/config` commit status. Nothing is
applied until it is merged.

## The file

```yaml
$schema: https://falak.sh/schema/v1.json   # optional, for editors
version: 1                                  # required, always 1
units: { … }                                # required: the deployable units, by key
environments: { … }                         # the environments the file deploys to, by slug
previews: { … }                             # pull request previews
```

**Units** are keyed by name: lower case letters, digits and dashes, starting with a letter (`web`, `api`, `db`; `secrets`
is reserved). The key is the service's name on the project canvas and the name `${{ <unit>.KEY }}` references use. A
file holds at most 100 units and 20 environments.

**Values:**
- **Durations**: whole numbers with units from largest to smallest: `30s`, `5m`, `1h30m`, `2d`, `0s`. No bare numbers,
  no fractions, no `ms`.
- **Sizes**: binary multiples, case-insensitive, with an optional `b`/`ib`: `512m` = `512MiB` = `512MB`; `1.5g` = 1536
  MiB. Each field says the unit it must be a whole number of (MiB for memory, GiB for a database `disk`).
- **CPUs**: a number of cores (`0.5`, `2`).
- **Versions** are strings: quote them (`php: "8.4"`, `version: "17"`). `php: 8.4` is a number and fails the schema.
- **YAML 1.2 rules**: `yes`/`no`/`on`/`off` are strings, not booleans; `0755` is a string; an unquoted date
  (`2026-10-11`) is an error (quote it). Anchors and aliases (`&defaults`, `<<: *defaults`) work.

**The file manages only what it sets.** A field you leave out keeps today's value and stays editable in the dashboard;
removing a field from the file makes it editable again, it is never reset to a default. A new unit gets the defaults
listed below.

### Unit types

| `type` | What it is | Notes |
|---|---|---|
| `app` | A site: PHP (FrankenPHP, PHP-FPM), Node, Bun, Deno, static, or a Docker image | Workers, daemons, schedules, volumes |
| `compose` | A Docker Compose stack from the repository | Falak settings per compose service |
| `function` | A cloud function, code from the repository under `root` | Scales to zero ([FUNCTIONS.md](FUNCTIONS.md)) |
| `database` | A PostgreSQL, MySQL or MariaDB container | Never deleted by the file |
| `redis` | A Redis or Valkey container | Never deleted by the file |

Every unit has `type`; `app`, `compose` and `function` units also have `root`, `servers`, `env`, `domains` and the fields
below.

### Fields of every deployable unit (`app`, `compose`, `function`)

| Field | What | Default |
|---|---|---|
| `type` | `app`, `compose`, `function`, `database` or `redis` | required |
| `root` | The unit's directory in the repository. A push changing files under it redeploys the unit | `.` |
| `servers` | Names of servers attached to the project (hosting one of its services, or allowed by an admin in Project settings → Config as code). The first one leads (migrations, the scheduler) | the environment's `servers` |
| `env` | Environment variables: literals and references (see [References](#references)); at most 500 | — |
| `expose` | Variables also given to the deploy hooks and the build (`VITE_*`, `NEXT_PUBLIC_*` always are) | — |
| `domains` | Domains served by the unit (below) | — |

`env` keys merge into the unit's current variables: a key you drop from the file stays, no longer managed. Integers
and booleans are stored as text (`true`, `42`); floats must be quoted.

### `app` units

```yaml
units:
  web:
    type: app
    root: apps/web
    runtime: php-fpm            # frankenphp | php-fpm | node | bun | deno | static | docker
    framework: laravel          # laravel | symfony | statamic | wordpress | php | next | nuxt | node | static | docker
    php: "8.4"                  # node: "22" for Node runtimes
    web_directory: public
    health_path: /up
    test_domain: false
    build: { mode: native }     # native | docker | on-server; dockerfile: Dockerfile
    laravel: { scheduler: true, horizon: false, octane: false }
    limits: { memory: 1g, cpus: 1 }
```

| Field | What | Default |
|---|---|---|
| `runtime` | `frankenphp`, `php-fpm`, `node`, `bun`, `deno`, `static` or `docker`. A unit never switches between `docker` and a native runtime (it needs a new site): give it another key | — (required for a new unit) |
| `framework` | Sets defaults such as the web directory and the deploy script | from the runtime; `laravel` for a new unit on a PHP runtime with a `laravel` block |
| `php` / `node` | PHP version (`"8.4"`) / Node.js major version (`"22"`), among the versions the installation offers | the installation's |
| `web_directory` | The public directory served, relative to the release (`public`). A `static` unit built natively ships its build output (`dist/`, `build/`…) as the release itself: leave it unset | the framework's |
| `port` | `docker` runtime: the port the app listens on inside its container | — |
| `image` | `docker` runtime: deploy this prebuilt image instead of building (`ghcr.io/acme/web:1.2`) | — |
| `health_path` | The unit's health path: deploys wait for it on the new release (unless `deploy.health.path` is set), and the edge checks it to keep each server in rotation (2xx only, so not a path that redirects) | deploys check `/`; no edge check |
| `test_domain` | Also serve on the installation's test domain | `true` |
| `build.mode` | `native` (Railpack on a builder), `docker` (an image from a Dockerfile) or `on-server` (built on each server) | `native` |
| `build.dockerfile` | `docker` mode: the Dockerfile, relative to `root` | `Dockerfile` |
| `laravel.scheduler` | Run `php artisan schedule:run` every minute on the leader | `false` |
| `laravel.horizon` | Run Horizon | `false` |
| `laravel.octane` | Serve with Octane: `false`, `frankenphp`, `swoole` or `roadrunner` | `false` |
| `deploy` | Deploy settings ([below](#deploy)) | — |
| `workers`, `daemons`, `schedules` | Processes ([below](#processes-app-units)) | — |
| `redirects`, `headers`, `mounts` | Edge rules ([below](#domains-and-edge-rules)) | — |
| `limits` | Resource limits ([below](#limits)); not for `static` units, and only `log`, `restart`, `oom` on FrankenPHP (the shared edge) | — |
| `volumes` | `docker` runtime only ([below](#volumes)) | — |

A unit the file adds is created as the dashboard would create it: a Laravel unit (framework `laravel`; not a docker or
compose unit, whose image is opaque to Falak: give those `APP_KEY: ${{ secrets.APP_KEY }}`) gets a generated `APP_KEY`, `APP_NAME`
and `APP_URL` unless the file sets them.

### `compose` units

```yaml
units:
  shop:
    type: compose
    root: stack
    files: [compose.yaml, compose.prod.yaml]
    profiles: [web]
    deploy: { strategy: rolling }
    domains: [shop.example.com]            # the first public service's
    services:
      front: { port: 80, health_path: /up, limits: { memory: 256m } }
      api:   { port: 8080, domains: [api.example.com] }
      db:    { replace_with: db }          # run this service as the `db` database unit instead
```

| Field | What | Default |
|---|---|---|
| `files` | Compose files relative to `root`, in `-f` order | `compose.yaml` (or `docker-compose.yml`) |
| `profiles` | Compose profiles to enable | — |
| `test_domain` | Serve public services on the installation's test domain too | `true` |
| `services.<name>.port` | Makes the service public: the port it listens on inside its container | — |
| `services.<name>.health_path` | Path checked before it goes live | — |
| `services.<name>.domains` / `redirects` / `headers` | That service's routes (needs `port`) | — |
| `services.<name>.limits` | That service's limits, over its own compose limits | — |
| `services.<name>.replace_with` | Run the service as a Falak database instead: the key of a `database` or `redis` unit; its references are rewritten to that unit | — |

The unit's own `domains`, `redirects` and `headers` go to its first public service. Public services keep their order once
created; a service the file makes public is added last, and removing its `port` stops managing it (it stays public).
Host bind mounts are an administrator's choice in the dashboard and can't be set from the file. Compose units can't
mount Falak volumes yet (their compose file declares what each service mounts).

### `function` units

```yaml
units:
  hooks:
    type: function
    root: functions/hooks
    runtime: bun                 # bun | node | deno | python | go
    entrypoint: index.ts
    memory: 512m
    cpus: 0.5
    timeout: 1m
    scaling: { min_instances: 0, max_instances: 5, concurrency: 50, idle_timeout: 5m }
    access: { allow: [10.0.0.0/8] }
    schedules:
      nightly: { cron: "0 3 * * *", timezone: Europe/Berlin, overlap: skip, timeout: 5m }
```

| Field | What | Default |
|---|---|---|
| `runtime` | `bun`, `node`, `deno`, `python` or `go` | — |
| `entrypoint` | The entry file, relative to `root` | the runtime's (`index.ts`, `main.py`, `main.go`…) |
| `memory` / `cpus` / `timeout` | Per instance: 64m–16g / 0.1–16 / request timeout 1s–15m | `256m` / `0.5` / `30s` |
| `scaling.min_instances` | Instances kept warm (0–20) | `0` |
| `scaling.max_instances` | 1–50, at least `min_instances` | `5` |
| `scaling.concurrency` | Requests one instance handles at once (1–10000) | `50` |
| `scaling.idle_timeout` | Stop an idle instance after (10s–1d) | `5m` |
| `access.allow` | Only these addresses (CIDRs) may call it. API keys are created in the dashboard, never here | — |
| `schedules.<name>` | `cron`, `timezone`, `overlap` (`skip`/`allow`), `timeout` (default `5m`), `enabled` | — |

A function's **code is the repository's files under `root`** at the run's commit: dot-files, `node_modules`,
`__pycache__`, `.venv`/`venv` and the config file itself are left out; the rest must fit a function version (50 files,
1 MB, the entrypoint present). A new version is saved only when the code changed, and the dashboard's editor no
longer deploys a function whose code the file manages (rolling back to a version still works). A function the file
creates gets no default domain: reach it through its `domains` or another unit's `mounts`.

### `database` and `redis` units

```yaml
units:
  db:
    type: database
    engine: postgresql           # postgresql | mysql | mariadb
    version: "17"                # the major line
    server: db-1
    memory: 2g
    cpus: 2
    disk: 50g
    settings: { max_connections: 200 }
    databases: [shop, analytics]
    public: false
    require_tls: true
    backups:
      cron: "0 3 * * *"
      storage: offsite
      keep: 14
      keep_days: 30
      databases: [shop]
      encryption: { mode: customer, recipient: age1… }
      drill: monthly
      drill_query: select 1 from users limit 1
    pitr: { storage: offsite, window_days: 7, base_interval_days: 1 }

  cache:
    type: redis
    engine: valkey               # redis | valkey
    memory: 256m
```

| Field | What | Default |
|---|---|---|
| `engine` | `postgresql`, `mysql`, `mariadb` / `redis`, `valkey`. A run never changes it: a new engine is a new unit | `redis` for redis units |
| `version` | The major version (`"17"`, `"8.4"`, `"11.4"`, `"8"`, `"8.1"`). A change is a major upgrade: **risky**; a downgrade is refused | the newest Falak supports |
| `server` | The server it runs on. A run never moves a database (the Databases page does, from backups) | the environment's first server |
| `memory` | Memory limit; the engine is tuned to it (whole MiB) | — |
| `cpus` | CPU cores (0.1–256) | — |
| `disk` | Size of its data volume (whole GiB, 1g–16t); it only grows | a Docker volume without a size |
| `settings` | Engine settings ([DB_IMAGES.md](DB_IMAGES.md) "Settings") | — |
| `public` | Publish the port, only to `allowed_sources`. Turning it on is **risky** | `false` |
| `allowed_sources` | `public`: addresses (CIDRs) allowed to connect | — |
| `require_tls` | Refuse unencrypted connections | `false` |
| `databases` | `database` units: the databases in the container | one named after the key (`-` as `_`) |
| `backups.cron` | When (5 fields, UTC) | — |
| `backups.storage` | The name of a storage provider the project already uses, or one an admin allowed | — |
| `backups.keep` / `backups.keep_days` | Keep at most N successful backups per database / delete those older than N days | — |
| `backups.databases` | Databases backed up | all of the unit's |
| `backups.encryption.mode` / `.recipient` | `cp` (Falak's key) or `customer` (your `age1…` recipient) | — |
| `backups.drill` / `drill_query` / `drill_server` | Restore drills (`off`, `weekly`, `monthly`), a read-only `SELECT` that must return rows, another server to run them on | `off` |
| `backups.enabled` | | `true` |
| `pitr.enabled` / `storage` | Point-in-time recovery (PostgreSQL, MySQL, MariaDB): continuous archiving | on when the block is present |
| `pitr.window_days` / `base_interval_days` | Recovery window (1–35) / a new base backup every N days (1–35) | `7` / `1` |
| `pitr.encryption` | As `backups.encryption` | — |

Passwords are Falak's: generated when the container is created and never in the file, a plan, a run, an export or a
pull request comment. The container's first user gets every database the file adds. A change of `memory`, `cpus`,
`settings`, `require_tls` or `public` restarts the container (the plan says so); `allowed_sources` applies live and
`disk` grows online. A major `version` upgrade must be the only change to that unit in its push.

### Deploy

```yaml
deploy:
  strategy: rolling
  batch_size: 1
  keep_releases: 5
  secrets: env
  health: { enabled: true, path: /up, status: 200, timeout: 10s, retries: 3, retry_delay: 5s }
  watch:  { minutes: 15, on_trigger: rollback, health: true, health_failures: 3, crashes: true, errors: true, issues: false }
  hooks:
    before_fetch: echo starting
    before_activate: php artisan migrate --force
    after_activate: php artisan optimize
    after_restart: php artisan horizon:terminate
```

| Field | What | Default |
|---|---|---|
| `strategy` | Host runtimes: `zero-downtime`, `in-place`, `rolling`, `canary`; `docker`: `blue-green`, `rolling`, `canary`; compose: `compose`, `rolling`, `canary`. Changing it in production is **risky** | the first of those |
| `batch_size` | `rolling`: servers switched at a time (1–100) | `1` |
| `keep_releases` | Releases kept on each server for rollbacks (1–50) | `5` |
| `secrets` | `docker` runtime: `env`, or `files` (secret variables as `/run/secrets` files) | `env` |
| `health.enabled` / `path` / `status` | The check on the new release before it goes live (blue/green swaps too); `path` only for deploys, not the edge | `true` / the unit's `health_path`, else `/` / `200` |
| `health.timeout` / `retries` / `retry_delay` | One attempt (1s–2m) / attempts (1–30) / wait between (0s–5m) | `10s` / `3` / `5s` |
| `watch.enabled` | Watch the release after it went live | `true` when the block is present |
| `watch.minutes` / `on_trigger` | How long (1–60) / `rollback` or `alert_only` | `5` / `rollback` |
| `watch.health` / `health_failures` | Trigger: the health check fails N times in a row (1–20) | `true` / `3` |
| `watch.crashes` | Trigger: an OOM kill or a restart loop | `true` |
| `watch.errors` | Trigger: the 5xx rate goes above max(baseline × 3, 5%) | `true` |
| `watch.issues` | Trigger: a new error issue in Insights | `false` |
| `hooks.before_fetch`, `before_activate`, `after_activate`, `after_restart` | Commands of the deploy script's sections | the framework's |

Each hook you set replaces only that section of the deploy script; the hooks you leave out keep today's commands (for
Laravel: `optimize`, `storage:link` and the leader-only `migrate`). Compose units have no hooks.

### Processes (`app` units)

```yaml
workers:
  default: { queue: [high, default], processes: 2, timeout: 90s, tries: 3, backoff: 10s, memory: 256m }
  emails:  { connection: redis, queue: [mail], max_jobs: 1000, max_time: 1h }
  reports: { command: node worker.js, processes: 1, servers: [web-2] }
daemons:
  reverb: { command: php artisan reverb:start, instances: 1, restart: always, stop_signal: TERM, stop_timeout: 30s }
schedules:
  prune: { command: php artisan model:prune, cron: "0 4 * * *", timezone: Europe/Berlin, overlap: skip, timeout: 1h }
  ping:  { command: curl -fsS https://hc.example.com/ping, cron: "@every 5m", heartbeat: false, all_servers: true }
```

**Workers** (`workers.<name>`):

| Field | What | Default |
|---|---|---|
| `command` | The start command. Without it the worker runs `queue:work`, which only Laravel units have | `queue:work` (Laravel) |
| `connection` / `queue` | Queue connection / queue names in priority order | — |
| `processes` | Processes per server (1–64) | `1` |
| `timeout` / `sleep` / `backoff` | Job timeout (0s = none, ≤ 1d) / sleep when the queue is empty (≤ 1h) / wait before a retry (≤ 1d) | `60s` / `3s` / — |
| `tries` / `max_jobs` / `max_time` | Attempts per job (0 = unlimited, ≤ 1000) / restart after N jobs / restart after running this long (≤ 7d) | `1` / — / — |
| `memory` | Memory a worker may use before it restarts (32m–64g) | `128m` |
| `env` | Extra literal variables (no references; the worker already sees the unit's env); at most 50 | — |
| `limits` | Resource limits of the worker | — |
| `servers` | Run only on these servers of the unit | all of them |

**Daemons** (`daemons.<name>`): `command`, `directory` (working directory; default the current release), `instances`
(1–64, default 1), `restart` (`always`, `on-failure`, `never`), `stop_signal` (`TERM`, `INT`, `QUIT`, `HUP`, `KILL`,
`USR1`, `USR2`), `stop_timeout` (1s–1h, default 30s), `env`, `limits`, `servers`, `user`.

**Schedules** (`schedules.<name>`): `command`, `cron` (5 fields, `@hourly`, `@daily`, `@weekly`, `@monthly`, `@yearly`,
or `@every 90s`), `timezone` (IANA, default UTC), `overlap` (`skip` by default: don't start while the previous run is
going; `allow`), `timeout` (1s–1d, default 1h), `heartbeat` (alert on missed or failed runs, default true), `enabled`,
`all_servers` (run on every server instead of the leader), `user`.

**`user`**: processes from the file always run as the unit's site user. Leave `user` out, or write exactly that user;
anything else (including `root`) is refused.

A worker made in the dashboard without a name reads as `worker-1`, `worker-2`… (creation order) in an export; a file
using that key adopts the worker and names it.

### Domains and edge rules

```yaml
domains:
  - api.shop.example.com                               # a plain host
  - name: shop.example.com                             # www redirects go on the apex
    www: to_apex                                       # none | to_www | to_apex
  - name: "*.shop.example.com"
    tls: dns                                           # auto | dns | custom | internal | off
    dns_credential: cloudflare-main
  - name: legacy.example.com
    tls: custom
    certificate: legacy-2026
    primary: true
    cloudflare: { proxied: true, cache: standard }     # standard | everything | bypass
redirects:
  - { from: /old, to: /new }
  - { from: "/blog/*", to: https://blog.example.com, status: 302 }   # 301 | 302 | 307 | 308
headers:
  X-Frame-Options: DENY
  Strict-Transport-Security: max-age=31536000
mounts:
  - { path: /api, function: hooks, strip_prefix: true }
```

| Field | What | Default |
|---|---|---|
| `domains[]` | A host name, or an object with `name` | — |
| `primary` | The primary domain (`APP_URL`, links) | the first in the list |
| `www` | `none`, `to_www` (apex → www) or `to_apex` (www → apex) | `none` |
| `tls` | `auto` (ACME HTTP), `dns` (ACME DNS challenge, needs `dns_credential`; required for wildcards), `custom` (needs `certificate`), `internal` (Caddy's internal CA), `off` (plain HTTP) | `auto` |
| `dns_credential` / `certificate` | The **name** of an existing DNS credential / uploaded certificate | — |
| `cloudflare.proxied` / `cloudflare.cache` | For a domain in a Cloudflare zone Falak manages ([CLOUDFLARE.md](CLOUDFLARE.md)) | the zone's |
| `redirects[]` | `from` (a path matcher: `/old`, `/blog/*`), `to` (an absolute URL or a path), `status` | `status: 301` |
| `headers.<Name>` | A response header added to every response | — |
| `mounts[]` | `path` (a prefix, no trailing slash), `function` (the key of a `function` unit), `strip_prefix` | `strip_prefix: true` |

A host can belong to one unit of one environment. A DNS credential or certificate the file names must already be used by
the project's domains, uploaded for one of its sites, or listed by an admin in Project settings → Config as code
("Allowed credentials"). Removing a production domain is **risky**.

### Limits

`limits` on an app unit, a compose service, a worker or a daemon:

| Field | What |
|---|---|
| `memory` | Hard memory limit (whole MiB, at least 32m) |
| `memory_reservation` | Memory kept for the service (at most `memory`) |
| `cpus` | CPU cores, fractional (0.05–1024) |
| `pids` | Most processes and threads (16–4194304) |
| `restart` | Restart policy: `always`, `unless-stopped`, `on-failure` |
| `max_restarts` | `on-failure`: give up after N restarts (1–1000) |
| `log.max_size` / `log.max_files` | Log rotation: size of one file (1m–10g) / files kept (1–100) |
| `oom` | `protect` (the kernel kills other processes first) or `normal` |

Limits are checked against the unit's servers. Static units have nothing to limit, and FrankenPHP units run inside the
shared edge (no `memory`, `memory_reservation`, `cpus` or `pids`: use Octane workers, or the `php-fpm` runtime).

### Volumes

`app` units with the `docker` runtime can mount Falak volumes:

```yaml
volumes:
  uploads:
    mount: /srv/uploads
    backups: { cron: "0 4 * * *", storage: offsite, keep: 7, consistency: pause }
  cache:
    kind: sized
    size: 512m
    mount: /cache
    read_only: true
    protected: true
    server: web-1
```

| Field | What | Default |
|---|---|---|
| `kind` | `docker` (a Docker named volume) or `sized` (a fixed-size volume, needs `size`). Can't change later | `docker` |
| `size` | `sized`: the size (whole MiB). It only grows | — |
| `mount` | Absolute path in the container | — |
| `service` | Compose units: the compose service it is mounted in (not supported yet) | — |
| `read_only` | Mount read-only | `false` |
| `protected` | Refuse deleting the volume until this is turned off | `false` |
| `server` | The server it lives on (a run never moves a volume) | the unit's first server |
| `backups.cron` / `storage` / `keep` / `keep_days` | As for databases | — |
| `backups.consistency` | `none`, `pause` or `stop` the services using the volume while it is archived | `none` |
| `backups.encryption` / `drill` / `drill_server` / `enabled` | As for databases | — |

Volume names are lower case letters, digits, `.`, `_` and `-`. Host paths (bind mounts) are never in the file.

## Environments and branches

```yaml
environments:
  production:
    branch: main
    production: true
    servers: [web-1, web-2]
  staging:
    branch: develop
    servers: [staging-1]
    units:
      web:
        domains: [staging.shop.example.com]
        deploy: { strategy: in-place }
        env: { APP_ENV: staging }
      worker-only: { type: app, runtime: node, node: "22", root: tools/worker }
      analytics: null
```

| Field | What |
|---|---|
| `branch` | A push to this branch applies the file to this environment. Without it, only `falak apply` or the dashboard apply it |
| `production` | A production environment: its risky changes need an approval. Changing it is itself risky |
| `servers` | The default `servers` of every unit of the environment; a database unit's default `server` is the first |
| `units` | Overrides of units in this environment, by unit key |

Keys are the project's environment slugs. One repository can feed several environments (`main` → production,
`develop` → staging).

**Overrides** merge over `units`: maps merge key by key, lists (`domains`, `redirects`, `mounts`, `servers`) replace,
and `null` removes a key. `units.<key>: null` removes the unit from that environment; a key that isn't in `units` adds an
environment-only unit (it needs `type`). An override can't change a unit's `type`. Workers, daemons, schedules,
volumes, headers and compose services are maps precisely so an environment can change one entry.

**New environments.** A push to production's branch creates every environment the file declares and the project
doesn't have yet (empty, named after the key, not production). A push to another branch naming an unknown environment
fails its run. `falak apply -e <new>` also creates it, for someone who may manage projects. `falak plan -e <new>` shows
the plan as a preview of its creation.

**Removing an environment from the file releases it**: its sites deploy the old way again (push to deploy), its fields
become editable and its waiting runs are cancelled. Nothing is deleted.

## Previews

```yaml
previews:
  enabled: true
  base: staging                    # the environment previews copy
  units:
    web: include                   # include (default) | share | omit
    docs: omit
    db: { strategy: clone_sanitize, source: production, sanitize: { sql: "update users set email = concat(id, '@example.test')" } }
  server: preview-1
  fork_server: forks-1
  variables: [STRIPE_KEY]
  domain_pattern: "pr-{number}-{service}"
  max_concurrent: 5
  idle_ttl: 3d
  access: basic                    # basic (HTTP basic auth) | public
  acknowledge_shared_database: false
```

| Field | What | Default |
|---|---|---|
| `enabled` | Previews for pull requests | `true` when the block is present |
| `base` | The environment previews copy (required when enabled; the file must deploy to it) | — |
| `units.<key>` | `include`, `share` (use the base environment's service), `omit`, or for a database unit `{strategy, source, sanitize}` | `include` |
| `strategy` | `empty`, `clone_backup` (the latest backup of `source`) or `clone_sanitize` (that backup, then `sanitize.sql` or `sanitize.command`) | `empty` |
| `source` | The environment whose backup is cloned | `base` |
| `server` / `fork_server` | The server previews run on / a server for pull requests from forks | the base's first server / — |
| `variables` | Variables previews copy from the base environment's units | — |
| `domain_pattern` | Host label under the organization's preview domain; holds `{number}` and `{service}` (`{project}` works too) | `pr-{number}-{service}` |
| `max_concurrent` / `idle_ttl` | Previews at once (1–50) / delete a preview idle this long (whole hours, 1h–30d) | `5` / `3d` |
| `access` | `basic` or `public` | `basic` |
| `acknowledge_shared_database` | Allow `share` of a database unit (previews then write to it) | `false` |

The block is applied by the run of its `base` environment (production's when it names none) and is the stored copy of
the dashboard's preview settings. A preview is built from the **pull request's** file, so a config change can be tried
before merging; a pull request **from a fork** always uses the base branch's file (after the usual `/falak preview`
approval), and never creates new units. Data strategies, sharing, the server and access always come from the base
branch's settings, so a pull request can't widen what data reaches its preview. Copying production data into
previews (`clone_backup`/`clone_sanitize` from production, or a script run on such a copy) and sharing a database are
**risky**.

## References

`env` values can reference the secret store and other units:

```yaml
env:
  STRIPE_SECRET: ${{ secrets.STRIPE_SECRET }}
  DATABASE_URL: ${{ db.DATABASE_URL }}
  REDIS_URL: ${{ cache.REDIS_URL }}
  API_URL: https://${{ api.APP_HOST }}/v1
```

- `${{ secrets.NAME }}`: a secret of the store, the nearest scope of the unit's service (service, environment,
  project, organization). `NAME` is upper case letters, digits and `_`. It must exist when the file is planned:
  `falak secret set NAME` sets a project secret before the first push (`-e ENV` for one environment).
- `${{ <unit>.<KEY> }}`: another unit's variable. A `database` unit exposes `DATABASE_URL`, `DB_CONNECTION`, `DB_HOST`,
  `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`; a `redis` unit `REDIS_URL`, `REDIS_CLIENT`, `REDIS_HOST`,
  `REDIS_PORT`, `REDIS_PASSWORD`; an app, compose or function unit the keys of its `env`. A name that isn't a unit of
  the file must be a service on the environment's canvas.
- References work **only in a unit's `env`** values. They are resolved at deploy time, never stored resolved: a
  reference in hooks, commands or a worker's `env` is an error.
- A unit referencing a database on another server needs both servers in a private network (Network → Private
  networks); otherwise its deployment fails with the reason.

## Risky changes and approvals

A plan with a risky change **waits for an approval** instead of applying. Nothing of it is applied (and nothing is
deployed) until someone with `config.approve` approves it. The rules (`contracts/config/risky-changes.json`):

| Rule | When |
|---|---|
| `database.version` | A database or Redis major version changes (a major upgrade) |
| `database.public` | A database is made public |
| `database.backups_removed`, `database.backups_disabled`, `volume.backups_removed`, `volume.backups_disabled` | A backup schedule is removed or disabled |
| `database.backups_retention`, `volume.backups_retention` | Fewer backups kept, or for fewer days |
| `database.backups_databases_removed` | A database dropped from the backup schedule |
| `database.pitr_off`, `database.pitr_removed`, `database.pitr_window` | Point-in-time recovery turned off, removed, or its window shortened |
| `database.database_removed` | A database removed from a unit's `databases` |
| `volume.delete` | A volume removed from the file |
| `unit.delete`, `unit.remove_with_data`, `unit.remove_with_volumes` | A unit removed from the file (any; one holding data; one with volumes) |
| `production.strategy`, `production.servers`, `production.domain_removed`, `production.drill_server` | In a production environment: the deploy strategy, the servers, a removed domain, the drill server |
| `environment.production` | An environment becomes production or stops being one |
| `previews.production_data`, `previews.shared_database` | Previews get production data, or share a database |

**Approving.** In Project settings → Config as code, the waiting run shows its risky changes with their rule and
reason; Approve or Reject, with an optional comment. From the terminal: `falak approve <run>` / `falak reject <run>`,
or `falak apply --wait --approve`. An approval covers exactly the risky changes shown: when you approve, the run plans
again, and if a risky change appeared that you didn't see, nothing is applied and the run waits again ("The plan
changed since it was shown; review it again.").

- Admins have `config.approve` by default. A project can require **a different approver** than the person who
  requested the run (Project settings → Config as code; a push run has no requester, so it never blocks those).
- A waiting run **expires** after 7 days (`FALAK_CONFIG_APPROVAL_DAYS`), and a newer run of the environment supersedes
  it. Disabling the source cancels it.
- A waiting run alerts `config.run.awaiting_approval` through the organization's alert rules, and the pull request it
  merged gets a note with the run's link.

**Never applied by a run**, whatever the approval: changing a database engine, moving a database or a volume to another
server, shrinking a database disk or a volume, changing a volume's `kind`. These fail validation with the dashboard
action to use instead.

## Ownership, read-only fields and unmanaged resources

- Every field and resource a run applies is recorded as **managed** by the file. The dashboard shows it read-only with
  the badge "Managed by falak.yaml @ abc1234" and a link to the file at that commit; the server refuses (422) an edit
  that changes a managed value. Fields the file doesn't set stay editable.
- A managed service can't be renamed or deleted in the dashboard: rename or remove the unit in the file.

**Removing things from the file:**

| Removed from the file | What happens |
|---|---|
| A field | It is no longer managed: editable again, value kept |
| An env key | It stays with its value, no longer managed |
| A worker, daemon, schedule, domain, redirect, header, mount, function schedule | Deleted if the file created it and its unit is still managed; otherwise detached (kept, unmanaged). Removing a production domain is risky |
| An app, compose or function unit | **Detached**: the service keeps running, unmanaged. Deleted only by `falak apply --allow-destroy` (`config.destroy`) and only if the file created it |
| A database or Redis unit, a database, a volume, a backup schedule | **Detached**, never deleted by a push. `falak apply --allow-destroy` deletes one the file created; a volume another site or service still mounts is never deleted; a database container's data volume is kept |

What a run detached is listed in Project settings → Config as code → **Unmanaged resources** (and `falak config
unmanaged`): delete it there through its own module's page and permissions, or add it back to the file to manage it
again.

## Drift

Falak compares each environment with the file last applied to it every 30 minutes (`FALAK_CONFIG_DRIFT_MINUTES`):
changes made outside the file's locks (another API, a module, a server) show as **drift**. A drifted environment gets a
banner on the canvas, an alert `config.drift` (once per distinct drift), and `falak drift` lists the changes a run would
make to put the file back. Push the file again (or "Apply now") to restore it. `unknown` means nothing was applied yet,
or a run is applying: it is not drift.

## Rollbacks

Each release keeps the config of its unit. Rolling back a unit (dashboard, `falak rollback`, or the release watch)
re-applies the config saved on the release it returns to, as a `rollback` run, without a new deployment. A rollback has
no approval, so it **skips every risky change** (each listed as a warning `rollback.risky_skipped`) and never creates
data resources (a database, Redis, a volume): adding them back takes a push. Rolling back to a release made before the
file existed restores the code only (warning `rollback.no_config`).

## Turning it off and on

- **Disabling** the source (Project settings → Config as code) keeps every resource, makes every field editable again,
  cancels runs that wait for an approval, and gives the sites back to push to deploy. Nothing is deleted.
- **Re-enabling** it: the next push plans the file against today's state; the file wins for the fields it sets
  (changes made while it was off show in that plan).
- **Adopting** a running project: `falak config export -e production -o falak.yaml`, commit, enable, push. The export
  holds today's state (units, the environment with its branch, previews), so the first run's plan is empty: nothing is
  redeployed beyond the push's own deployment, nothing is deleted. Exports without the `sites.env.view` permission leave
  literal env values out (references are kept), which also plans empty.

## The CLI

Install and log in: see the README ("CLI"). The project comes from `-p` (id or name), then `FALAK_PROJECT`, then
`.falak/project.json` (written by `falak init` when you pick a project). The environment comes from `-e`, else the one
whose `branch` is the current git branch (production for a file without environments). The file defaults to
`falak.yaml`, then `falak.yml`, then `falak.json`.

| Command | What |
|---|---|
| `falak init [dir]` | Write `falak.yaml` from the code. `--yes` (ask nothing), `--stdout`, `--json` (the detection report), `--full` (write defaults too), `--force` (replace a file), `--dockerfile` (a starter Dockerfile for Go, Python, Ruby, Rust, Java), `--update` (a diff of what the code adds; `--write` applies it) |
| `falak validate [file]` | Check the file offline (schema, environments, references); logged in with a linked project, also the organization checks. `-e`, `--format yaml\|json`, `--offline` |
| `falak plan [file]` | What applying the local file would change. `-e`, `-p`, `--json` |
| `falak apply [file]` | Apply the local file now. `--wait` (stream the deployments; `--timeout`, default 30m), `--approve [--yes] [--comment]`, `--allow-destroy` |
| `falak runs list` / `falak runs show <run>` | Runs newest first (`--limit`); one run's plan, approval and deployments |
| `falak approve <run>` / `falak reject <run>` | Decide a waiting run. `--comment`, `--yes` |
| `falak drift` | The environment against its last applied file. `--check` checks now |
| `falak config export [project]` | Today's state as a file. `-e`, `-o FILE`, `--format yaml\|json` |
| `falak config unmanaged` | What runs detached |
| `falak secret set NAME` / `list` / `delete NAME` | The project's secrets (`-e ENV`: one environment's). `set` reads the value from a hidden prompt or stdin, never an argument, and adds a version when the secret exists; `--from-env FILE` imports every `KEY=VALUE` line. `list` shows names, never values; `delete` needs `--yes` without a terminal |
| `falak config source show` / `falak config source set` | The file the project follows. `set` changes only what its flags name: `--repo owner/name`, `--connection <id>`, `--path falak.yaml`, `--enable` / `--disable` (a new source is enabled), `--allow-server NAME` (repeatable; replaces the list, `""` clears it; admins only) |

`--json` prints the API's data as is. Issues print as `falak.yaml:12:17: schema: /units/web/deploy/strategy: value must
be one of …`.

**Exit codes**

| Code | Meaning |
|---|---|
| `0` | Done (for `drift`: in sync, or unknown) |
| `1` | An error (the run failed in phase A or B, the API refused, `--wait` gave up) |
| `2` | Wrong usage |
| `3` | A unit's deployment failed |
| `5` | The file is invalid |
| `6` | The run waits for an approval |
| `7` | `drift`: the environment drifted |

The API behind them is in [API.md](API.md) ("Config as code").

## Security model

Whoever can push to the repository can change **how the project runs**. They cannot reach anything the project
doesn't already have:

| A pusher can | A pusher can't |
|---|---|
| Change units, builds, deploy settings, hooks, processes, domains, limits, previews of this project | Read or write secret values (only reference secrets that already exist) |
| Create units, databases, Redis, volumes and backups on servers attached to the project (hosting one of its services, or allowed by an admin) | Use a server not attached to the project, or create servers |
| Reference storage providers, DNS credentials and certificates the project already uses (or an admin allowed) | Define credentials, firewall rules, private networks, teams, roles or tokens |
| Remove resources from the file (they are detached) | Delete data (databases, volumes) without `config.destroy` and `--allow-destroy` |
| Propose risky changes | Apply a risky change without someone with `config.approve` |
| Run processes as the unit's site user | Run processes as another user (`root`, `sudo` users), or mount host paths |

- Pull requests only get a plan comment; a fork's pull request never supplies config (its preview uses the base
  branch's file).
- Env values are masked (`••••`) in stored plans, in pull request comments, and in API answers for callers without
  `sites.env.view`; database passwords never leave Falak.
- Deploy hooks already ran the repository's commands before config as code; the file doesn't widen that.

**Permissions** (organization roles; Owner has all):

| Permission | What | Default roles |
|---|---|---|
| `config.view` | Validate, plan, export, read runs, drift and unmanaged resources | Admin, Developer, Viewer |
| `config.apply` | Create runs (`falak apply`, "Apply now"), cancel them, connect / enable / disable the source | Admin, Developer |
| `config.approve` | Approve or reject runs with risky changes; change who may approve and the allowed credentials | Admin |
| `config.destroy` | Runs with `allow_destroy` (delete what the file removed, data included) | Admin |

API tokens need these abilities too: a token made with a list of abilities before config as code doesn't have them.

## Schema versions

- Every file says `version: 1`. **Version 1 only gains optional fields**: a file valid today stays valid in every later
  Falak release, and a new field never changes what an existing file means.
- A breaking change (a field removed or renamed, a type narrowed, a value no longer accepted, a new required field) is
  a **new version**: `version: 2`, with its own schema `https://falak.sh/schema/v2.json`.
- Falak accepts **every published version it knows**, so upgrading Falak never breaks your file; you move a file to a
  new version when you choose to.
- The released v1 schema is frozen in `contracts/config/v1.baseline.json`; a contract test fails when
  `v1.schema.json` would narrow it.

## Limits and known gaps

- At most 100 units, 20 environments, 500 env vars per unit, 50 workers per unit; function code 50 files / 1 MB.
- Plain git servers (no provider API) can't be a config source yet.
- Compose units can't mount Falak volumes; volumes need the `docker` runtime.
- The file never manages database users, function API keys, firewall rules, private networks, alert channels or
  teams; servers, storage providers, DNS credentials and certificates are named only.
- A run never changes a database engine, moves a database or volume, shrinks a disk or changes a volume's kind (the
  dashboard's actions do).
- Two environments of the same push claiming the same host both pass validation; the second one's phase B fails with
  "This domain is already in use." Give each environment its own hosts (overrides).
- A unit referencing a database on another server without a private network passes validation and fails at deploy
  time with the reason.
- A plan reads each unit's resources one by one (about 40 queries per unit); a 40-unit file plans in well under a
  second.
- Previews of a fork never create units the base environment doesn't have.
