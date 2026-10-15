# `falak.yaml` reference (config as code, schema v1)

One file at the root of the repository (or in a folder): `falak.yaml`, `falak.yml`, or `falak.json` with the same
content. Schema: `https://falak.sh/schema/v1.json` (in the Falak repository: `contracts/config/v1.schema.json`; the
full guide is `docs/CONFIG_AS_CODE.md`). The CLI validates offline with the same schema: **always run
`falak validate` after editing**, then `falak plan`.

The file says **how** things run. It never holds secret values or credentials: secrets are referenced
(`${{ secrets.NAME }}`), servers / storage providers / DNS credentials / certificates are **named**, never defined.
It manages only what it sets: a field left out keeps today's value and stays editable in the dashboard; removing a
field never resets it.

## Top level

```yaml
$schema: https://falak.sh/schema/v1.json   # optional, for editors
version: 1                                  # required, always 1
units: { … }                                # required: deployable units by key
environments: { … }                         # environments by slug
previews: { … }                             # pull request previews
```

Unit keys: lower case letters, digits, dashes, starting with a letter (`secrets` is reserved). At most 100 units, 20
environments, 500 env vars per unit, 50 workers per unit.

## Value rules (common validation errors)

- **Durations**: whole numbers with units, largest first: `30s`, `5m`, `1h30m`, `2d`. No bare numbers, fractions or `ms`.
- **Sizes**: binary multiples: `512m` = `512MiB`; `1.5g` = 1536 MiB. Memory in whole MiB, database `disk` in whole GiB.
- **CPUs**: cores (`0.5`, `2`).
- **Versions are strings**: `php: "8.4"`, `version: "17"`, `node: "22"`. Unquoted `8.4` fails the schema.
- YAML 1.2: `yes`/`no`/`on`/`off` are strings; quote dates and anything with leading zeros.

## Unit types

| `type` | What |
|---|---|
| `app` | A site: `frankenphp`, `php-fpm`, `node`, `bun`, `deno`, `static`, or `docker` runtime |
| `compose` | A Docker Compose stack from the repository |
| `function` | A cloud function, code from the repository under `root` (`bun`, `node`, `deno`, `python`, `go`) |
| `database` | PostgreSQL, MySQL or MariaDB container (never deleted by a push) |
| `redis` | Redis or Valkey container (never deleted by a push) |

`app`, `compose`, `function` share: `root` (directory, default `.`; a push changing files under it redeploys the
unit), `servers` (names; the first leads; default: the environment's `servers`), `env` (literals and references),
`expose` (variables also given to the build and hooks), `domains`.

### `app`

```yaml
web:
  type: app
  root: apps/web
  runtime: php-fpm            # frankenphp | php-fpm | node | bun | deno | static | docker
  framework: laravel          # laravel | symfony | statamic | wordpress | php | next | nuxt | node | static | docker
  php: "8.4"                  # or node: "22"
  web_directory: public
  health_path: /up
  build: { mode: native }     # native | docker (+ dockerfile) | on-server
  laravel: { scheduler: true, horizon: false, octane: false }   # octane: false | frankenphp | swoole | roadrunner
  limits: { memory: 1g, cpus: 1 }
  deploy: { … }               # below
  workers: { … }              # below
  daemons: { … }
  schedules: { … }
  domains: [shop.example.com]
```

`runtime` is required for a new unit and never switches between `docker` and a native runtime (use a new key).
`docker` runtime: `port` (inside the container), `image` (deploy a prebuilt image), `volumes`. `test_domain`
(default `true`) also serves it on the installation's test domain. A new Laravel unit gets a generated `APP_KEY`.

### `compose`

```yaml
shop:
  type: compose
  root: stack
  files: [compose.yaml, compose.prod.yaml]
  profiles: [web]
  deploy: { strategy: rolling }
  domains: [shop.example.com]          # the first public service's
  services:
    front: { port: 80, health_path: /up, limits: { memory: 256m } }
    api:   { port: 8080, domains: [api.example.com] }
    db:    { replace_with: db }        # run this service as the `db` database unit instead
```

A service with `port` is public. No deploy hooks for compose units; they can't mount Falak volumes.

### `function`

```yaml
hooks:
  type: function
  root: functions/hooks
  runtime: bun                 # bun | node | deno | python | go
  entrypoint: index.ts
  memory: 512m                 # 64m-16g, default 256m
  cpus: 0.5                    # default 0.5
  timeout: 1m                  # request timeout 1s-15m, default 30s
  scaling: { min_instances: 0, max_instances: 5, concurrency: 50, idle_timeout: 5m }
  access: { allow: [10.0.0.0/8] }
  schedules:
    nightly: { cron: "0 3 * * *", timezone: Europe/Berlin, overlap: skip, timeout: 5m }
```

Code is the repository's files under `root` (dot-files, `node_modules`, `__pycache__`, `.venv` left out; 50 files, 1 MB).
A function the file creates gets no default domain: give it `domains`, or mount it on another unit (`mounts`).

### `database` and `redis`

```yaml
db:
  type: database
  engine: postgresql           # postgresql | mysql | mariadb
  version: "17"                # major line; a change is a major upgrade (risky); downgrades refused
  server: db-1
  memory: 2g
  disk: 50g                    # only grows
  databases: [shop]            # default: one named after the key (- as _)
  public: false                # true is risky; then allowed_sources: [CIDR]
  require_tls: true
  backups:
    cron: "0 3 * * *"          # 5 fields, UTC
    storage: offsite           # name of a storage provider the project uses (or an admin allowed)
    keep: 14
    keep_days: 30
    drill: monthly             # off | weekly | monthly
  pitr: { storage: offsite, window_days: 7 }

cache:
  type: redis
  engine: valkey               # redis | valkey
  memory: 256m
```

Passwords are generated by Falak and never appear in the file, plans, runs or exports. A run never changes an
engine, moves a database, or shrinks a disk (dashboard actions only). Changing `memory`, `cpus`, `settings`,
`require_tls` or `public` restarts the container. A major `version` upgrade must be the only change to that unit.

## Deploy settings (`deploy:`)

```yaml
deploy:
  strategy: rolling            # host: zero-downtime | in-place | rolling | canary; docker: blue-green | rolling | canary; compose: compose | rolling | canary
  batch_size: 1
  keep_releases: 5
  health: { enabled: true, path: /up, status: 200, timeout: 10s, retries: 3, retry_delay: 5s }
  watch:  { minutes: 15, on_trigger: rollback, health: true, health_failures: 3, crashes: true, errors: true }
  hooks:
    before_fetch: echo starting
    before_activate: php artisan migrate --force
    after_activate: php artisan optimize
    after_restart: php artisan horizon:terminate
```

Each hook you set replaces only that section of the deploy script. Changing `strategy` in production is risky.

## Processes (`app` units)

```yaml
workers:
  default: { queue: [high, default], processes: 2, timeout: 90s, tries: 3, memory: 256m }
  reports: { command: node worker.js, processes: 1, servers: [web-2] }
daemons:
  reverb: { command: php artisan reverb:start, instances: 1, restart: always }
schedules:
  prune: { command: php artisan model:prune, cron: "0 4 * * *", timezone: Europe/Berlin, overlap: skip, timeout: 1h }
```

A worker without `command` runs `queue:work` (Laravel only). Processes always run as the unit's site user: `user`
may only be omitted or that user (never `root`).

## Domains and edge rules

```yaml
domains:
  - shop.example.com
  - name: example.com          # www redirects need the apex name here (www.example.com is refused)
    www: to_apex               # none | to_www (apex → www) | to_apex (www → apex)
  - name: "*.shop.example.com"
    tls: dns                   # auto | dns | custom | internal | off
    dns_credential: cloudflare-main
redirects:
  - { from: /old, to: /new }                                     # status 301 default; 302 | 307 | 308
headers:
  X-Frame-Options: DENY
mounts:
  - { path: /api, function: hooks, strip_prefix: true }
```

A host belongs to one unit of one environment. `dns_credential` / `certificate` are names of existing ones. Removing
a production domain is risky.

## Limits (`limits:` on an app, a compose service, a worker or a daemon)

`memory` (≥ 32m), `memory_reservation`, `cpus` (0.05-1024), `pids`, `restart` (`always`, `unless-stopped`,
`on-failure`), `max_restarts`, `log: { max_size, max_files }`, `oom` (`protect` | `normal`). Not for `static` units;
FrankenPHP units only take `log`, `restart`, `oom`.

## Volumes (`docker` runtime apps)

```yaml
volumes:
  uploads:
    mount: /srv/uploads
    backups: { cron: "0 4 * * *", storage: offsite, keep: 7, consistency: pause }
```

`kind` (`docker` | `sized` + `size`, can't change), `read_only`, `protected`, `server`. Never host paths.

## Environments

```yaml
environments:
  production:
    branch: main               # a push to main applies the file here
    production: true           # risky changes need an approval
    servers: [web-1, web-2]
  staging:
    branch: develop
    servers: [staging-1]
    units:                     # overrides, by unit key
      web:
        domains: [staging.shop.example.com]
        env: { APP_ENV: staging }
      analytics: null          # remove a unit from this environment
```

Keys are the project's environment slugs. Overrides merge maps key by key; lists replace; `null` removes. An
environment without `branch` is applied only by `falak apply` or the dashboard. A file without `environments` binds
the production environment to the repository's default branch. Removing an environment from the file releases it
(nothing deleted).

## References (only in a unit's `env` values)

```yaml
env:
  STRIPE_SECRET: ${{ secrets.STRIPE_SECRET }}   # must exist: falak secret set STRIPE_SECRET -p <project>
  DATABASE_URL: ${{ db.DATABASE_URL }}
  REDIS_URL: ${{ cache.REDIS_URL }}
  API_URL: https://${{ api.APP_HOST }}/v1
```

- `${{ secrets.NAME }}`: nearest scope of the unit's service (service, environment, project, organization).
- A `database` unit exposes `DATABASE_URL`, `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`,
  `DB_PASSWORD`; a `redis` unit `REDIS_URL`, `REDIS_CLIENT`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`; other
  units the keys of their `env`.
- References in hooks, commands or a worker's `env` are errors. A database on another server needs a private network.

## Previews

```yaml
previews:
  base: staging
  units:
    web: include               # include | share | omit
    db: { strategy: clone_sanitize, source: production, sanitize: { sql: "update users set email = concat(id, '@example.test')" } }
  max_concurrent: 5
  idle_ttl: 3d
  access: basic                # basic | public
```

Copying production data into previews and sharing a database are risky.

## Risky changes (wait for an approval)

A run whose plan has any of these waits for someone with `config.approve` (expires after 7 days by default):
database/Redis major version change; making a database public; removing/disabling a backup schedule or reducing its
retention; dropping a database from backups or from `databases`; turning off/shortening PITR; removing a volume or a
unit (rule `unit.delete`, with data `unit.remove_with_data`); in production: the strategy, the servers, a removed
domain, the drill server; an environment becoming or stopping being production; previews getting production data or
sharing a database.

## What removing things does

| Removed from the file | Effect |
|---|---|
| A field or env key | No longer managed; value kept |
| Worker, daemon, schedule, domain, redirect, header, mount | Deleted if the file created it, else detached |
| An app / compose / function unit | Detached (keeps running). Deleted only by `falak apply --allow-destroy` (`config.destroy`) if the file created it |
| A database, Redis, volume, backup schedule | Detached, never deleted by a push. `--allow-destroy` deletes one the file created (a database container's data volume is kept) |

## Permissions

`config.view` (validate, plan, export, runs, drift; Admin/Developer/Viewer) · `config.apply` (apply, cancel, source;
Admin/Developer) · `config.approve` (approve/reject risky runs; Admin) · `config.destroy` (`--allow-destroy`; Admin).
