# Config as code (`falak.yaml`): plan

Status: designed 2026-10-11; building all phases end to end on `feat/config-as-code` (target v0.11.0).

A project's whole deployment pipeline is described in **one file at the root of its repository**: `falak.yaml` (or
`falak.json`, same schema). The file declares several **deployable units** (apps, compose stacks, functions,
databases, Redis/Valkey) and per-environment overrides. Falak reads it at the pushed commit, plans the changes,
applies them, then deploys the units that changed. `falak init` writes a first file from the code.

## Decisions (agreed)

| Topic | Decision |
|---|---|
| Formats | YAML and JSON, one JSON Schema (`https://falak.sh/schema/v1.json`, also on SchemaStore). **No HCL.** |
| Files | **One file per repository**, with several deployable units; each unit can set its own `root:` |
| Applying | **A push applies automatically**; risky changes pause for approval (see [Risky changes](#risky-changes)) |
| UI edits | **A field the file manages is read-only in the UI** ("Managed by falak.yaml @ abc1234") |
| Secrets | Never in the file: only `${{ secrets.NAME }}` references; values stay in the secret store |
| Org resources | Servers, provider/storage/DNS credentials, alert channels and teams are **named** in the file, never defined |
| Deletion | Removing a unit deletes it only if the file created it; databases and volumes are **never** deleted automatically |
| Rollback | The applied config is saved on each release; rolling back restores it |
| Previews | A PR's preview uses the **PR branch's** file; fork PRs always use the base branch's file |
| Opt-in | Per project. A project without a file works exactly as today |
| `falak init` | Generates the first file from the code, reusing the builder's detector (`builder.Detect`) |

## The file

```yaml
$schema: https://falak.sh/schema/v1.json
version: 1

units:
  web:
    type: app                        # app | compose | function | database | redis
    root: apps/web                   # default "."
    runtime: frankenphp
    php: "8.4"
    build: { mode: docker, dockerfile: Dockerfile }
    deploy:
      strategy: rolling
      batch_size: 1
      health: { path: /up, timeout: 10s, retries: 3 }
      watch:  { minutes: 15, on_trigger: rollback }
      hooks:
        before_activate: php artisan migrate --force
    env:
      APP_ENV: production
      DATABASE_URL: ${{ db.url }}
      STRIPE_SECRET: ${{ secrets.STRIPE_SECRET }}
    domains: [shop.example.com]
    workers:
      queue: { command: php artisan queue:work, processes: 2 }
    schedules:
      - { command: php artisan schedule:run, cron: "* * * * *" }
    limits: { memory: 1g, cpus: 1 }

  db:
    type: database
    engine: postgresql
    version: "17"
    memory: 1g
    backups: { cron: "0 3 * * *", storage: offsite, keep: 14 }

environments:
  production:
    branch: main
    servers: [web-1, web-2]          # names of existing servers in the organization
  staging:
    branch: develop
    servers: [staging-1]
    units:
      web:
        domains: [staging.shop.example.com]
        deploy: { strategy: in-place }

previews:
  enabled: true
  base: staging
  units: { web: include, db: { strategy: clone_backup } }
  domain: "pr-{number}.preview.shop.example.com"
```

- A unit's key is the canvas service `name`, the name `${{ unit.KEY }}` references already resolve against.
- Environment overrides merge over `units`: maps merge, lists replace, `null` removes a key.
- `hooks` map onto the sections of today's `deploy_script` (`before_fetch`, `before_activate`, `after_activate`,
  `after_restart`, see `Deployments/src/Application/Planning/ScriptSections.php`).
- `secrets.NAME` must exist in the secret store at project, environment or service scope; validation fails if not.
- **[decision]** The file's `environments.<name>.branch` binds an environment to a branch. One repo can feed several
  environments (main → production, develop → staging).

## What the file can and can't do

| In the file | Named only (must already exist) | Never |
|---|---|---|
| Units and their build, deploy, health, watch, hooks | Servers | Secret values |
| Env vars (literals and references) | Storage providers (`backups.storage`) | Credentials of any kind |
| Workers, daemons, schedules, limits | DNS credentials, Cloudflare zones | Firewall and private networks |
| Domains, redirects, headers | Alert channels | Teams, roles, tokens |
| Databases, Redis/Valkey, volumes, backups | | |
| Preview settings | | |

This split is the security model: whoever can push to the repo can change **how the project runs**, but cannot reach
servers, credentials or projects the project doesn't already have. A server named in the file must already be
attached to the project.

## Architecture

### New module `Config` (`Falak\Config`)

It owns the schema, the parser, the planner and the run history. It talks to other modules only through their
`Contracts\*`, like every module (ARCHITECTURE.md §2.2).

Tables:
- `config_sources`: `project_id` (unique), `source_connection_id`, `repository`, `path` (`falak.yaml`), `enabled`.
- `config_runs`: `id`, `project_id`, `environment_id`, `commit`, `trigger` (push, pr, cli, manual), `status`
  (planning, awaiting_approval, applying, applied, failed, cancelled), `file` (the raw file), `resolved` (json: the
  file with the environment merged in), `plan` (json), `error`, `approved_by`, timestamps.
- `config_ownership`: `resource_type`, `resource_id`, `project_id`, `unit`, `path` (the field path managed, e.g.
  `deploy.strategy`), `run_id`. This table drives the read-only UI and safe deletion.

### `Reconcilable` contract (each module implements it)

```php
interface Reconcilable
{
    /** The schema section this module owns, e.g. "units.*.workers". */
    public function section(): string;

    /** Today's state of the section, in the file's shape. */
    public function current(ConfigScope $scope): array;

    /** Changes needed to go from current to desired. Pure: no writes. */
    public function diff(ConfigScope $scope, array $desired, array $current): ChangeSet;

    /** Apply the changes through the module's own Actions. */
    public function apply(ConfigScope $scope, ChangeSet $changes, ApplyContext $ctx): void;
}
```

Implementations: Sites (unit, build, env, hooks), Deployments (`SiteSettings`), Processes (workers, daemons,
schedules), Limits, Edge (domains, redirects, headers), Databases (instances, backups), Volumes, Functions, Previews.
`ChangeSet` items are `create | update | delete | detach | noop`, each with a `risk` flag (see below).

### Flow on a push

```
PushReceived ─▶ Config: environments bound to this branch?
                 │ yes, per environment
                 ▼
          read file at commit ─▶ validate ─▶ plan ─▶ risky? ──yes──▶ awaiting_approval ─┐
                                                       │ no                             │ approved
                                                       ▼                                ▼
                                          apply phase A (before build) ◀───────────────┘
                                                       ▼
                         TriggerDeployment for each unit that changed (code under its root, or its config)
                                                       ▼
                                apply phase B with each unit's release (activate step)
```

1. **Read** with `SourceControlGateway::file()` at the commit. Plain git servers (no API): falak-builder reads the
   file from its checkout and posts it back.
2. **Validate**: the JSON Schema, then names (servers, secrets, storage, channels) and limits.
3. **Plan**: run every `Reconcilable::diff`, save the plan on the run; it's shown in the dashboard and the deploy log.
4. **Phase A**, before any build: databases, Redis, volumes, env versions, build settings. A failure here stops the
   run and touches no running release.
5. **Deploy**: one `TriggerDeployment` per unit that changed. A unit "changed" when files under its `root` changed
   in the push (from the push payload) or its resolved config changed. Units that didn't change are not redeployed.
6. **Phase B**, at each unit's activate step: workers, daemons, schedules, domains, edge rules. They go live with the
   release that expects them.
7. **Saving**: each `Release` gets a `config` column holding the unit's resolved config. A rollback re-applies it.

**[decision]** One push = one config run per environment (not per site), so a repo with five units plans once.
`DeployOnPush` skips sites whose project has an enabled config source; the Config module deploys them instead.

### Risky changes

A plan with any of these waits for an approval (`config.approve` permission) in the dashboard, or `falak apply`:
- a database or Redis engine change, a major version change, or making one public;
- deleting a volume, or shrinking a sized volume;
- deleting or detaching a unit that holds data;
- changing the deploy strategy or the server list of a production environment;
- removing a domain from a production environment.

Everything else applies on push.

### Ownership and deletion

- On apply, every field the file sets is written to `config_ownership`. The UI shows those fields read-only with
  the badge and a link to the file at that commit. Fields the file doesn't set stay editable.
- A unit removed from the file: deleted if `config_ownership` says the file created it; otherwise only "unmanaged"
  (badge removed). Databases and volumes are always detached and flagged, never deleted; deleting them is a separate
  action (`falak apply --allow-destroy` or the UI).
- Disabling the config source keeps all resources and makes every field editable again.

### Pull requests and previews

- On `PullRequestOpened` / `PullRequestUpdated`: validate and plan against the target environment, post the plan as
  a PR comment (`commentOnPullRequest`) and a `falak/config` commit status (`setCommitStatus`). Nothing is applied.
- The preview environment is built from the PR branch's file (its `previews` and `units`), so a config change can be
  tried in a preview before merging. `PreviewSettings` becomes the stored copy of `previews:` for projects with a file.
- Fork PRs: the base branch's file only, and the existing `/falak preview` approval still applies.

### API and CLI

API (`/api/v1`, Sanctum):
- `POST projects/{p}/config/validate` (body: file) → errors with line and column.
- `POST projects/{p}/config/plan` (body: file, environment) → plan.
- `POST projects/{p}/config/runs` (body: file or commit, environment) → run; `POST config/runs/{r}/approve`.
- `GET projects/{p}/config/export?environment=` → file.

CLI (`agent/internal/cli`):
- `falak init` (below), `falak validate`, `falak plan [-e env]`, `falak apply [-e env] [--allow-destroy]`,
  `falak config export [project] [-e env]`.
- `validate` works offline (embedded schema). `plan`/`apply` need a login and send the local file.

## `falak init`

Writes a first `falak.yaml` from the code. It runs locally in the CLI, using the same Go code the builder uses, so
the file matches what the builder will do.

Detection layers:
1. **Build plan**: `builder.Detect()` (`agent/internal/builder/detect.go`: PHP/Laravel, Node with Next, Nuxt, Vite,
   Astro and more, Bun, Deno, static), merged with Railpack when installed.
2. **Containers**: a `Dockerfile` → `build.mode: docker`, port from `EXPOSE`; a `compose.yaml` → a `compose` unit,
   with its database containers offered as Falak databases (as compose import does).
3. **Needs**, from files in the repo:
   - `.env.example` keys → `env`. Keys that look secret (`*_KEY`, `*_SECRET`, `*_TOKEN`, `*PASSWORD*`) become
     `${{ secrets.NAME }}` placeholders, never literal values.
   - Database and Redis hints (`DB_CONNECTION`, `DATABASE_URL`, `REDIS_*`, `pg`/`mysql2`/`ioredis` packages) →
     `database` / `redis` units, wired with `${{ db.url }}`.
   - Framework conventions: Laravel → `/up`, migrate hook, Horizon/queue workers, scheduler; Next → port 3000, etc.
4. **Account** (only when logged in): servers, domain, environments, previews, asked interactively.

Monorepos: `pnpm-workspace.yaml`, `package.json` workspaces, `apps/*`, `services/*` → one unit per app with `root:`.

Unsupported languages (Go, Python, Ruby, Rust, Java...): a Dockerfile or Railpack if present; otherwise `init` says
so and offers a starter Dockerfile. It never writes a config it knows won't build.

Rules:
- Each detected value carries a comment with its reason (`# detected: laravel/framework ^12 in composer.json`).
- Only real decisions are written; defaults are left out (`--full` writes them).
- Never overwrites an existing file without `--force`; `--update` (later) proposes a diff.
- `--yes` (accept everything), `--stdout`, `--json` (detection report). It runs `validate` before writing.

In the dashboard, creating a site from a repo runs the same detection as a short `detect` job on falak-builder and
offers "Open a PR adding falak.yaml" (through the GitHub App).

## Phases

Production-ready = phases 1–5. Until phase 4 lands, a project's config source is marked **experimental** and
risky changes are refused (not applied) instead of waiting for approval.

| Phase | Contents |
|---|---|
| 0 | Fixed contract: `contracts/config/v1.schema.json` + shared cases, `Reconcilable` / `ChangeSet` / `ConfigScope` PHP contracts, API request/response shapes |
| 1 | The `Config` module; push → validate → plan → apply → deploy; `app` units; Sites, Deployments, Processes, Limits, Edge (domains) reconcilers; read-only UI; `Release.config` and rollback |
| 2 | CLI `init`, `validate`, `plan`, `apply`, `config export`; API endpoints; PR plan comments and status |
| 3 | `database`, `redis`, `compose`, `function` units; volumes and backups; several environments with overrides; `previews:` |
| 4 | Deletion and ownership rules in full, risky-change approvals, drift alerts, `init --update` |
| 5 | Hardening and release: user docs (repo + website), schema versioning rules, monorepo load test, upgrade tests (turn the file on and off), live test on AWS test servers with real repos, tagged release |
| 6 | (after release) Dashboard `detect` job and "Open a PR adding falak.yaml" |

Phase 2's CLI (Go) runs in parallel with phases 1 and 3, against the phase 0 contract. After the release, schema v1
only gains optional fields.

## Tests

- **Schema**: `contracts/config/v1.schema.json` with shared valid/invalid cases (`contracts/config/cases/*.yaml`),
  run by both the PHP and Go suites, like `contracts/compose/merge-cases.json`.
- **Planner (Pest)**: per reconciler, `current → desired → ChangeSet` tables; the plan is pure, so these are unit
  tests. Feature tests for the whole push flow with the fake source control gateway.
- **Safety**: removing a database never deletes it; a risky change waits; a fork PR never applies its own file;
  a failed phase A leaves the running release untouched; rollback restores the saved config.
- **UI**: Playwright test that a managed field is read-only and an unmanaged one isn't.
- **`init` (Go)**: example repos in `agent/internal/cli/testdata/init/` (Laravel + Postgres, Next.js, a monorepo,
  Dockerfile only, compose, an unsupported language), each with the expected `falak.yaml`; changes show as diffs.
- **Round trip**: `export` then `plan` on the same project gives an empty plan.

## Risks

- **Two sources of truth.** Handled by ownership: the file wins for the fields it sets, the UI locks them.
- **Push access becomes deploy-config access.** Already true for `deploy_script`; the "named only" rule keeps
  servers and credentials out of reach, and risky changes need an approval.
- **Many resources have no API or contract today** (databases, volumes, workers, schedules, domains). Each
  reconciler adds the contract it needs; this is most of the work in phases 1 and 3.
- **Preview copying is partial today**: `DuplicateSite` doesn't copy workers, schedules, `SiteSettings`, domains or
  limits. With a file, previews are built from the file instead, which fixes this for config-managed projects.
- **Detection drift between `init` and the builder.** Both call `builder.Detect`, and the `init` fixtures pin it.

## Phase 0 contract (fixed)

Both lanes build against this section: the PHP lane (Config module, reconcilers, API) and the Go lane (CLI). Where
it differs from the sections above, this section wins. Choices made here that the sections above didn't settle are
marked **[decision]**.

### Files

| Path | What |
|---|---|
| `contracts/config/v1.schema.json` | The schema, draft 2020-12, `$id` `https://falak.sh/schema/v1.json` (published there and on SchemaStore at release) |
| `contracts/config/risky-changes.json` | The risky-change rules, as data ([Risky changes](#risky-changes-1)) |
| `contracts/config/cases/{valid,invalid,parse,resolve}/` | Shared cases; `contracts/config/README.md` explains their format |
| `agent/internal/configspec/` | Go: embedded copy of the schema (`v1.schema.json`, kept identical by tests in both suites), `Parse`, `Validate`, `Resolve` |
| `control-plane/modules/Config/src/Contracts/` | PHP: `Reconcilable`, `ConfigScope`, `ChangeSet`, `Change`, `ChangeOp`, `ApplyContext`, `ApplyPhase` (`A` before build, `B` with the release) |
| `control-plane/modules/Config/` | The module skeleton: `ConfigServiceProvider` (registered last in `Kernel\Modules::ALL`), tests |

**[decision]** The Go package holds the reference implementation of reading, schema validation and the merge with
its reference checks (`configspec.Parse/Validate/Resolve`); `falak validate` uses it offline, and the Pest suite will
run the resolve cases against the PHP merge once phase 1 writes it.

### Validation stages and issue codes

A file goes through four stages; each stops the next on an error. Every issue is
`{code, pointer, source?, line, column, message}`.

| Stage | Where | Codes |
|---|---|---|
| 1. Read | CLI and control plane | `parse`, `parse.empty`, `parse.timestamp`, `parse.number`, `parse.tag`, `parse.key`, `parse.duplicate_key`, `parse.multiple_documents` |
| 2. Schema | CLI and control plane | `schema` |
| 3. Resolve (merge + references), per environment | CLI and control plane | `environment.unknown`, `override.type`, `reference.malformed`, `reference.secret_name`, `reference.unknown_key`, `reference.not_allowed`; warning `reference.external` |
| 4. Semantic, per environment | control plane only (needs the organization) | `name.server`, `name.secret`, `name.storage`, `name.dns_credential`, `name.certificate`, `name.unit`, `name.environment`, `value.range`, `value.unsupported`, `value.conflict`, `value.forbidden`, `limit.server` |

Stage 4 in detail:
- `name.*`: a named resource doesn't exist: a server not attached to the project (`servers`, `server`, `drill_server`,
  `previews.server`), a secret not in the scope chain of the unit (`${{ secrets.NAME }}`), a storage provider, DNS
  credential or certificate of the organization, a unit key (`mounts[].function` must be a `function` unit,
  `replace_with` a `database`/`redis` unit, `previews.units` keys), an environment (`previews.base`,
  `previews.units.*.source`). A `reference.external` warning becomes `name.unit` when the environment has no canvas
  service by that name.
- `value.range`: bounds the schema can't express on durations and sizes (see the mapping table), and sizes that
  aren't a whole number of the stored unit.
- `value.unsupported`: config-dependent lists: `php` in `sites.php_versions`, `node` in `sites.node_versions`, database
  `version` in `databases.versions.<engine>`, function `runtime` in `functions.runtimes`, `timezone` an IANA zone,
  `cron` a valid expression (Processes' `ProcessRules::validExpression`), `deploy.strategy` allowed for the runtime
  (`Strategy::for`), `laravel` only on PHP runtimes, `volumes`/`port`/`image`/`deploy.secrets: files` only on the
  `docker` runtime (and compose units), `pitr` only for `postgresql`/`mysql`/`mariadb`, `drill_query` only on SQL engines.
- `value.conflict`: two domains with `primary: true`; a domain in two units; `memory_reservation` above `memory`;
  `min_instances` above `max_instances`; a compose service with `domains` but no `port`; `www` on a wildcard or
  `www.` domain; `previews.enabled` with a `base` the file doesn't deploy to.
- `limit.server`: Limits' `LimitValidator` against the unit's servers.

**Pointers** are RFC 6901 JSON Pointers into the parsed file (stage 1, 2) or into the resolved file (3, 4; `source` is
then the pointer of the value in the file). For unknown, missing or badly named properties the pointer is the object
holding them, and the message names the property. **line/column** are 1-based, of the value at the pointer in the
file (of the offending key for unknown properties); `null` when unknown (e.g. the YAML parser's own errors). The Go
side gets them from yaml.v3 nodes; the control plane needs a position-aware lookup (symfony/yaml has no positions).

### Reading the file

`falak.yaml` (or `falak.yml`) is YAML, `falak.json` is JSON; the path is `config_sources.path` (default `falak.yaml`).
Both parse to the JSON data model. Rules both readers follow, pinned by `cases/parse/`:

- One document; a UTF-8 BOM is ignored; an empty or comment-only file is `parse.empty`.
- YAML 1.2 booleans and nulls: `true/false` (any case), `~`/`null`. `yes`, `no`, `on`, `off` are strings.
- Integers: decimal (with `_` separators), `0x…`, `0o…`. Other int-looking scalars (`0755`, `08`, `-0`, `0b11`) are
  strings. Floats need a dot or an exponent; `.inf` and `.nan` are `parse.number`.
- Unquoted dates and timestamps (`2026-10-11`) are `parse.timestamp` (quote them). `12:30` is a string.
- Anchors, aliases and merge keys (`<<`) work; explicit keys win over merged ones. Custom tags (`!env`) are `parse.tag`.
- Keys are plain scalars, unique per map (`parse.duplicate_key`).
- PHP: `Yaml::parse($raw, PARSE_OBJECT_FOR_MAP | PARSE_DATETIME | PARSE_EXCEPTION_ON_INVALID_TYPE)` then reject
  `DateTimeInterface` and INF/NAN (see `modules/Config/tests/Support/ConfigCases.php`). Go: walk the `yaml.Node` tree
  (`configspec.Parse`), which also yields line/column.

### Units of measure

- **Duration** (schema `$defs/duration`): `^([0-9]+d)?([0-9]+h)?([0-9]+m)?([0-9]+s)?$`, at least 2 characters:
  whole numbers with units from largest to smallest (`30s`, `5m`, `1h30m`, `2d`, `0s`). d = 86400 s, h = 3600 s,
  m = 60 s. Stored as seconds (or hours where the column is hours: it must then be whole hours, else `value.range`).
  `90s` and `1m30s` are equal. No bare numbers, no fractions, no `ms`.
- **Size** (`$defs/size`): `^[0-9]+(\.[0-9]+)?[kKmMgGtT]([iI]?[bB])?$`: binary multiples, case-insensitive, optional
  `b`/`ib` (`512m` = `512MiB` = `512MB` = 512 × 1024² bytes; `1.5g` = 1536 MiB). The byte value must be a whole number of
  the stored unit (MiB for `*_mb` / MB columns, GiB for `disk_gb`, bytes for `*_bytes`), else `value.range`.
- **CPUs**: a JSON number of cores (`0.5`, `2`). **Percent, counts, ports**: integers.
- **Versions** (`php`, `node`, database `version`): strings; unquoted numbers fail the schema (`php: 8.4` is a number).
- **Cron**: `cron` of backups is 5 fields (UTC); `cron` of schedules also takes `@hourly`, `@daily`, `@weekly`,
  `@monthly`, `@yearly` and `@every <go duration>` (≥ 1s), like Processes' `Schedule.expression`.

### Environments and merge rules

`Resolve(file, environment)` produces the **resolved file**: the same shape as the file, with `units` merged for the
environment, `environments` holding only that environment (without its `units` overrides), and `$schema`, `version`,
`previews` unchanged. It is what `config_runs.resolved` stores and what reconcilers diff against.

1. The environment must be a key of `environments` (`environment.unknown`, pointer `/environments`).
2. For each key of `environments.<env>.units` (overrides):
   - `null` removes the unit from this environment.
   - A key not in `units` adds an environment-only unit (it needs `type`).
   - Otherwise the override is merged into the unit: a map merges key by key (recursively); any other value (list,
     scalar) **replaces**; `null` **removes** the key. A map replacing a non-map replaces it. An override setting a
     `type` different from the unit's is `override.type` (pointer and source `/environments/<env>/units/<u>/type`)
     and stops the merge.
3. The resolved file must pass the schema again (an override can produce an invalid unit).
4. Defaults are not filled in by the merge; reconcilers apply them. A unit's `servers` default to the environment's
   `servers`; a `database`/`redis` unit's `server` defaults to the environment's first server.

`null` is only valid inside overrides; in `units` the schema rejects it. **[decision]** Lists of keyed things
(`workers`, `daemons`, `schedules`, `volumes`, `headers`, compose `services`) are maps so an environment can change one
entry; `domains`, `redirects`, `mounts`, `servers` are lists and replace as a whole.

Which environments a push applies to: those whose `branch` equals the pushed branch, read from the file **at the
pushed commit**. An environment without `branch` is applied only by `falak apply` or the dashboard. Environment keys
are the project's environment slugs; **[decision]** an environment key the project doesn't have is created by the
Projects reconciler (phase 3) with `is_production` from `production`.

### References

Compatible with today's resolver (`Projects\Contracts\VariableReferences::PATTERN`, same regex in Go
`configspec.RefPattern`):

```
\$\{\{\s*([A-Za-z0-9][A-Za-z0-9 _.\-]*?)\.([A-Za-z_][A-Za-z0-9_]*)\s*\}\}
```

- `${{ secrets.NAME }}`: the secret store, nearest scope of the unit's service (service, environment, project,
  organization). NAME is `[A-Z_][A-Z0-9_]*` (`reference.secret_name`). Existence is checked at plan time (`name.secret`).
- `${{ <unit>.<KEY> }}`: another unit's variable. A **`database`** unit exposes `DATABASE_URL`, `DB_CONNECTION`,
  `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`; a **`redis`** unit `REDIS_URL`, `REDIS_CLIENT`,
  `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` (Databases' `DatabaseConnections::KEYS` / `REDIS_KEYS`); an app,
  compose or function unit the keys of its resolved `env`. Else `reference.unknown_key`. **[decision]** The plan's
  `${{ db.url }}` is not valid: write `${{ db.DATABASE_URL }}` (no lower-case aliases in v1).
- A name that is not a unit of the resolved file is `reference.external` (a warning): it must be a canvas service of
  the environment, checked at plan time (`name.unit`). A unit removed by an override makes its references external.
- References are resolved **only in `units.<u>.env` values**, at deploy time by today's resolver (the file stores them
  as written; cycles are the resolver's errors). `${{` anywhere else (hooks, commands, worker and daemon `env`) is
  `reference.not_allowed`; `${{` not starting a valid reference is `reference.malformed`. **[decision]** Worker and
  daemon `env` are literal (Processes stores them as is; those processes already see the unit's env).
- Integer and boolean env values are stored as their text (`true`, `false`, `42`). Floats are not allowed (`0.5` must
  be quoted).

### Field mapping

Every field of the schema, the model it maps to, and its owner. Unit keys map to the canvas service `name`
(`projects_services`) and the site's / instance's `name`. A `.` path is within the unit unless it starts at the root.
Defaults are the module's current defaults (listed in the schema descriptions).

**Units (Sites, `sites_sites` unless noted)**

| Field | Column / model | Notes |
|---|---|---|
| `type` | `runtime` for compose (`compose`) and function (`function`); database/redis: a `DatabaseInstance` | app: `runtime` below |
| `root` | `root_directory` | `.` → null |
| `runtime` (app) | `runtime` (`SiteRuntime`: frankenphp, php-fpm, node, bun, deno, static, docker) | |
| `framework` | `framework` (`Framework`) | |
| `php` / `node` | `php_version` / `node_version` | `value.unsupported` outside config lists |
| `web_directory` | `web_directory` | |
| `port` | `container_port` | |
| `image` | `docker_image` | |
| `health_path` | `health_check_path` | |
| `test_domain` | `test_domain_enabled` | |
| `servers` | `sites_site_targets` (`server_id` by server name; first = `leader`) | |
| `build.mode` / `build.dockerfile` | `build_mode` (`BuildMode`) / `dockerfile` | |
| `laravel.scheduler`, `.horizon` | `laravel.scheduler`, `laravel.horizon` | |
| `laravel.octane` | `laravel.octane` + `laravel.octane_server` (`OctaneServer`); port allocated by Sites | `false` → off |
| `deploy.hooks.*` | `deploy_script`, composed as `before_fetch`, `$FALAK_FETCH`, `before_activate`, `$FALAK_ACTIVATE`, `after_activate`, `$FALAK_RESTART_PROCS`, `after_restart` (`ScriptSections`) | managed as one field |
| `env.<K>` | a new `sites_environment_versions` row (`variables`) | per key: keys not in the file stay as they are |
| `expose` | `sites_environment_versions.exposed` | |
| compose `files` | `compose_files` (and `compose_file` = first), `compose_source` = `repo` | |
| compose `profiles` | `compose_profiles` | |
| compose `services.<s>.port`, `.health_path` | `public_services[] {service, port, health_check_path}` | |
| compose `services.<s>.replace_with` | `compose_services.<s> = {mode: database, database_id}` | the unit's instance |
| compose `services.<s>.limits` | `compose_limits.<s>` (Limits JSON) | owner Limits |

**Deploy settings (Deployments, `deployments_site_settings`)**

| Field | Column | Bounds |
|---|---|---|
| `deploy.strategy` | `strategy` (`Strategy`: in-place, zero-downtime, blue-green, rolling, canary, compose) | per runtime: `Strategy::for` |
| `deploy.batch_size` / `keep_releases` | `batch_size` / `keep_releases` | 1–100 / 1–50 |
| `deploy.secrets` | `secrets_mode` (env, files) | files: docker only |
| `deploy.health.enabled`, `.path`, `.status` | `health_enabled`, `health_path`, `health_status` | status 100–599 |
| `deploy.health.timeout` / `.retries` / `.retry_delay` | `health_timeout_s` / `health_retries` / `health_retry_delay_s` | 1s–2m / 1–30 / 0s–5m |
| `deploy.watch` (present) / `.enabled` | `watch_enabled` | present ⇒ true unless `enabled: false` |
| `deploy.watch.minutes`, `.on_trigger` | `watch_minutes`, `watch_on_trigger` (rollback, alert_only) | 1–60 |
| `deploy.watch.health`, `.health_failures`, `.crashes`, `.errors`, `.issues` | `watch_health`, `watch_health_failures`, `watch_crashes`, `watch_errors`, `watch_issues` | failures 1–20 |

**Processes**

| Field | Model / column | Bounds |
|---|---|---|
| `workers.<key>` | `processes_workers` row; **[decision]** phase 1 adds a nullable `name` column holding the key (export uses it; unnamed rows export as `worker-<n>`) | |
| `.connection`, `.command`, `.processes`, `.tries`, `.max_jobs` | same names | processes 1–64, tries 0–1000 |
| `.queue` | `queue` (a list is joined with `,`) | |
| `.timeout`, `.sleep`, `.backoff`, `.max_time` | `timeout`, `sleep`, `backoff`, `max_time` (seconds) | 0s–1d, 0s–1h, 0s–1d, 0s–7d |
| `.memory` | `memory` (MB) | 32m–64g |
| `.env`, `.limits`, `.servers` | `env` (encrypted), `limits` (Limits JSON), `server_ids` | env ≤ 50 keys, values ≤ 4096 |
| `daemons.<key>` | `processes_daemons` row, `name` = key | |
| `.command`, `.directory`, `.user`, `.instances`, `.restart`, `.stop_signal` | same names | instances 1–64 |
| `.stop_timeout` | `stop_timeout` (seconds) | 1s–1h |
| `.env`, `.limits`, `.servers` | `env`, `limits`, `server_ids` | |
| `schedules.<key>` (app) | `processes_schedules` row, `name` = key | |
| `.command`, `.timezone`, `.user`, `.overlap`, `.heartbeat`, `.enabled`, `.all_servers` | same names | |
| `.cron` | `expression` | |
| `.timeout` | `timeout` (seconds) | 1s–1d |

**Limits** (`units.<u>.limits`, compose `services.<s>.limits`, and inside workers/daemons): the `ResourceLimits`
JSON stored on the owner's row: `memory` → `memory_limit` (MB, ≥ 32m), `memory_reservation` → `memory_reservation`
(MB), `cpus` → `cpus`, `pids` → `pids_limit`, `restart` → `restart_policy`, `max_restarts` → `max_restarts`,
`log.max_size` → `log_max_size` (MB, 1m–10g), `log.max_files` → `log_max_files`, `oom` → `oom`. Unit limits are
`sites_sites.limits`, compose service limits `sites_sites.compose_limits.<s>`.

**Edge**

| Field | Model / column | Notes |
|---|---|---|
| `domains[]` (string or `.name`) | `edge_domains.name`, `compose_service` null (compose `services.<s>.domains`: `compose_service` = s) | identity: name |
| `.primary` | `is_primary` | default: the first |
| `.www` | `www_redirect` (`WwwRedirect`: none, to_www, to_apex) | |
| `.tls` | `tls_mode` (`TlsMode`: auto, dns, custom, internal, off) | |
| `.dns_credential` / `.certificate` | `dns_credential_id` / `certificate_id` (by name) | |
| `.cloudflare.proxied` / `.cache` | `cloudflare_proxied` / `cloudflare_cache` | |
| `redirects[]` `.from`, `.to`, `.status` | `edge_redirects` `from`, `to`, `status` (default 301); `position` = index | identity: from |
| `headers.<Name>` | `edge_headers` `name`, `value` | identity: name |
| `mounts[]` `.path`, `.function`, `.strip_prefix` | `edge_mounts` `path_prefix`, `function_site_id` (the function unit's site), `strip_prefix` (default true) | identity: path |

**Databases** (`database` and `redis` units)

| Field | Model / column | Notes |
|---|---|---|
| unit | `databases_instances` row (`name` = key) | never deleted by the file |
| `engine` | `engine` (`Engine`: postgresql, mysql, mariadb / redis, valkey; redis default `redis`) | |
| `version` | `version` (major) | |
| `server` | `server_id` (by name) | |
| `memory` / `cpus` | `memory_bytes` / `cpus` | memory ≥ 16m (whole MiB); cpus 0.1–256 |
| `disk` | a sized data volume (`volume_id`, create: `disk_gb`) | whole GiB, 1g–16t |
| `settings` | `settings` | |
| `public` / `allowed_sources` / `require_tls` | `public_access` / `allowed_sources` / `require_tls` | |
| `databases` | `databases_databases` rows (`name`) | default `[<key with - as _>]`; redis: none |
| `backups` | one `databases_backup_schedules` row, **[decision]** `name` = `falak.yaml` | |
| `backups.cron`, `.keep`, `.keep_days` | `cron`, `retention_count`, `retention_days` | |
| `backups.storage` | `storage_provider_id` (by name) | |
| `backups.databases` | the schedule's databases | default: all |
| `backups.encryption.mode` / `.recipient` | `encryption_mode` / `age_recipient` | |
| `backups.drill`, `.drill_query`, `.drill_server`, `.enabled` | `drill`, `drill_query`, `drill_server_id`, `enabled` | |
| `pitr.enabled`, `.storage` | `pitr_enabled`, `pitr_storage_provider_id` | present ⇒ enabled unless `enabled: false` |
| `pitr.window_days`, `.base_interval_days` | `pitr_window_days`, `pitr_base_interval_days` | 1–35 |
| `pitr.encryption` | `pitr_encryption_mode`, `pitr_age_recipient` | |

**Volumes** (`units.<u>.volumes.<name>`)

| Field | Model / column | Notes |
|---|---|---|
| key | `volumes_volumes.name` | `^[a-z0-9][a-z0-9_.-]{0,62}$`; never deleted by the file |
| `kind` | `kind` (`VolumeKind`: docker, sized) | **[decision]** `bind` and `shared_path` are not in the file (host paths are admin-only) |
| `size` | `size_limit_bytes` | sized only |
| `protected` | `protected` | |
| `server` | `server_id` | default: the unit's first server |
| `mount`, `read_only`, `service` | `volumes_attachments` `mount_path`, `read_only`, `service`; `attachable_type` site / compose_service | |
| `backups.*` | one `volumes_backup_schedules` row: `cron`, `storage_provider_id`, `retention_count` (`keep`), `retention_days` (`keep_days`), `consistency`, `encryption_mode`/`age_recipient`, `drill`, `drill_server_id`, `enabled` | |

**Functions** (`function` units: a site with runtime `function` plus `functions_functions`)

| Field | Column | Bounds |
|---|---|---|
| `runtime` / `entrypoint` | `runtime` / `entrypoint` | runtimes from `functions.runtimes` |
| `scaling.min_instances`, `.max_instances`, `.concurrency` | same names | 0–20, 1–50, 1–10000 |
| `scaling.idle_timeout` | `idle_timeout_s` | 10s–1d |
| `memory` / `cpus` / `timeout` | `memory_mb` / `cpus` / `request_timeout_s` | 64m–16g / 0.1–16 / 1s–15m |
| `access.allow` | `allow_cidrs` | API keys stay in the dashboard |
| `schedules.<key>` | `functions_schedules` row: `name` = key, `expression` (`cron`), `timezone`, `overlap`, `timeout_s`, `enabled` | |
| code | the files under `root`: a new `FunctionVersion` when they changed | |

**Previews** (`previews_settings`, one per project) **[decision]** `domain` of the plan's example is
`domain_pattern`, the host label under the organization's preview domain (`PreviewHosts`: holds `{number}` and
`{service}`).

| Field | Column |
|---|---|
| `enabled` (present ⇒ true) | `enabled` |
| `base` | `base_environment_id` (by slug) |
| `units.<u>`: include / share / omit | `services.<u>` |
| `units.<u>`: `{strategy, source, sanitize}` | `services.<u>` = include; `databases.<u>` = `{strategy, source_environment_id, sanitize_kind (sql\|command), sanitize_script}` |
| `server` / `fork_server` | `server_id` / `fork_server_id` |
| `variables`, `domain_pattern`, `max_concurrent`, `access`, `acknowledge_shared_database` | same names |
| `idle_ttl` | `idle_ttl_hours` (whole hours, 1h–30d) |

**Environments (Projects)**: `environments.<slug>` ↔ `projects_environments.slug`; `production` ↔ `is_production`;
`servers` is the default of every unit's `servers`; `branch` is read from the file only (no column).

### Sections, owners and phases

A `Reconcilable` returns `sections()` as patterns: a dotted path where `*` is any unit key and `*(t1|t2)` a unit
key of those types. Its documents (`current`, `diff`, `apply`) are the resolved file pruned to those sections, from
the root. One section has one owner.

| Owner (module) | Sections | Phase |
|---|---|---|
| Projects | `environments` | A (first) |
| Databases | `units.*(database\|redis)` (all its fields) | A |
| Volumes | `units.*(app\|compose).volumes` | A |
| Sites | `units.*(app\|compose\|function)` minus the sections below: `type`, `root`, `runtime`, `framework`, `php`, `node`, `web_directory`, `port`, `image`, `health_path`, `test_domain`, `servers`, `build`, `laravel`, `files`, `profiles`, `services.*.port`, `services.*.health_path`, `services.*.replace_with`; plus `units.*.env`, `units.*.expose`, `units.*.deploy.hooks` | A |
| Deployments | `units.*(app\|compose).deploy` minus `hooks` | A |
| Limits | `units.*(app\|compose).limits`, `units.*(compose).services.*.limits` | A |
| Functions | `units.*(function)`: `entrypoint`, `scaling`, `memory`, `cpus`, `timeout`, `access` | A |
| Previews | `previews` | A |
| Functions | `units.*(function).schedules` | B |
| Processes | `units.*(app).workers`, `units.*(app).daemons`, `units.*(app).schedules` | B |
| Edge | `units.*.domains`, `units.*.redirects`, `units.*.headers`, `units.*.mounts`, `units.*(compose).services.*.{domains,redirects,headers}` | B |

**[decision]** The interface takes `sections(): list<string>` and `phase(): ApplyPhase` on top of the plan's
methods; a module whose sections apply in both phases registers two reconcilers (Functions). Order within a phase is
the table's order (units exist before what hangs off them). Phase B runs per unit at its activate step
(`ApplyContext.unit`, `.deploymentId`, `.releaseId`).

### Changes

`Change` = `{op, path, before, after, section, unit, risky, reason, rule}`:
- `op`: `create | update | delete | detach | noop` (`ChangeOp`). `detach` = gone from the file but kept (not
  created by it, or a database / volume); `noop` = managed and already right (plans list them; `ChangeSet::isEmpty`
  ignores them).
- `path`: a JSON Pointer into the resolved file. Items of lists are addressed by identity, not index:
  domains by host name, redirects by `from`, mounts by `path` (`/units/web/domains/shop.example.com`,
  `/units/web/redirects/~1old`). Granularity: one change per resource for create/delete/detach, one per field for
  update (`/units/web/deploy/strategy`).
- `before`/`after`: values in the file's shape; `null` = absent. Never secret values (references only).
- `rule`/`reason`: set when a risky rule matched.

`config_ownership.path` uses the same pointers, relative to the unit (`/deploy/strategy`, `/workers/queue`,
`/domains/shop.example.com`). **[decision]** JSON Pointers rather than the plan's dotted paths (host names contain dots).

### Risky changes

`contracts/config/risky-changes.json` holds the rules: `{id, paths, ops, unit_types?, production_only?, condition?,
reason}`; a change is risky when it matches one (path patterns use `*` for one token; conditions `became_true`,
`became_false`, `decrease`, `has_volumes`). The rules: `database.engine`, `database.version` (versions are majors),
`database.public`, `volume.delete`, `volume.shrink` (also `disk`), `unit.remove_with_data`, `unit.remove_with_volumes`,
`production.strategy`, `production.servers`, `production.domain_removed`, as the plan lists; **[decision]** also
`database.move` (a database's `server` changes), `database.backups_removed`, `database.pitr_off`,
`database.pitr_removed`, `database.pitr_window` (shrinking needs an admin in the UI today) and
`environment.production`.

### Tables

```
config_sources      id ulid pk, organization_id ulid, project_id ulid unique, source_connection_id ulid,
                    repository string, path string default 'falak.yaml', enabled bool default true,
                    experimental bool default true, created_by ulid null, timestamps
config_runs         id ulid pk, organization_id ulid, project_id ulid, environment_id ulid null,
                    environment string (slug), commit string(40) null, branch string null,
                    trigger enum(push, pr, cli, manual, rollback),
                    status enum(planning, awaiting_approval, applying, applied, failed, cancelled, refused),
                    file longtext (raw), format enum(yaml, json), resolved json null, plan json null
                    (ChangeSet::toArray()), deploy json null (unit keys deployed), errors json null (issues),
                    error text null, allow_destroy bool default false, requested_by ulid null,
                    approved_by ulid null, approved_at null, started_at null, finished_at null, timestamps;
                    index (project_id, created_at), index (environment_id, status)
config_ownership    id ulid pk, organization_id ulid, project_id ulid, environment_id ulid,
                    resource_type string (site, database_instance, volume, worker, daemon, schedule, domain,
                    redirect, header, mount, function_schedule, backup_schedule, preview_settings, environment),
                    resource_id ulid, unit string null, path string (pointer relative to the unit),
                    created_by_file bool, run_id ulid, timestamps;
                    unique (resource_type, resource_id, path), index (project_id, environment_id, unit)
deployments_releases.config  json null   (Deployments: the unit's slice of the resolved file at release)
```

`refused`: a risky plan while the source is `experimental` (until phase 4). `created_by_file` drives deletion
(delete when true, detach otherwise).

### API (`/api/v1`, Sanctum, `auth:sanctum` + `org`)

`{project}` is the project id. Permissions **[decision]**: `config.view` (validate, plan, export, read runs),
`config.apply` (create runs), `config.approve` (approve), `config.destroy` (`allow_destroy`); admins and developers
get view/apply, admins approve/destroy.

`ConfigIssue`:

```json
{"code": "schema", "pointer": "/units/web/deploy/strategy", "source": null, "line": 12, "column": 17,
 "message": "value must be one of 'in-place', 'zero-downtime', …", "severity": "error"}
```

Invalid files answer **422** in Laravel's shape plus the issues, so existing clients keep decoding `errors`:

```json
{"message": "falak.yaml is invalid (2 errors).",
 "errors": {"file": ["/units/web/deploy/strategy: value must be one of …"]},
 "config_errors": [ConfigIssue, …], "config_warnings": [ConfigIssue, …]}
```

**`POST /projects/{project}/config/validate`** (`config.view`, throttle 60/min)

```json
// request
{"file": "<raw text>", "format": "yaml", "environment": "staging"}
// 200, always (validity is the answer, not the status)
{"data": {"valid": false, "environments": ["production", "staging"],
          "errors": [ConfigIssue], "warnings": [ConfigIssue]}}
```

`format`: `yaml` (default) or `json`. Without `environment` stages 1–3 run for every environment of the file and 4
is skipped; with it, stages 1–4 for that one.

**`POST /projects/{project}/config/plan`** (`config.view`, throttle 30/min)

```json
// request: exactly one of file / commit
{"file": "<raw text>", "format": "yaml", "environment": "production"}
{"commit": "abc1234…", "environment": "production"}
// 200
{"data": {"environment": "production", "commit": null, "resolved": {…},
          "plan": {"changes": [Change], "summary": {"create": 1, "update": 2, "delete": 0, "detach": 0, "noop": 9},
                   "risky": true, "deploy": ["web"]},
          "warnings": [ConfigIssue]}}
// 422 invalid file (above); 404 unknown project; 409 {"message": "Config as code is not enabled for this project."}
```

`Change` is `Change::toArray()`. `deploy`: units whose config changed (a push adds units whose files changed).

**`POST /projects/{project}/config/runs`** (`config.apply`, throttle 10/min)

```json
// request: exactly one of file / commit
{"file": "<raw text>", "format": "yaml", "environment": "production", "allow_destroy": false}
// 201
{"data": ConfigRun}
```

`ConfigRun`:

```json
{"id": "01j…", "project_id": "01j…", "environment": "production", "environment_id": "01j…",
 "commit": null, "trigger": "cli", "status": "awaiting_approval",
 "plan": {"changes": [Change], "summary": {…}, "risky": true, "deploy": ["web"]},
 "errors": [], "error": null, "allow_destroy": false,
 "requested_by": "01j…", "approved_by": null, "approved_at": null,
 "deployments": [{"unit": "web", "deployment_id": "01j…", "status": "running"}],
 "created_at": "2026-10-11T12:00:00Z", "started_at": null, "finished_at": null,
 "url": "https://…/projects/01j…/config/runs/01j…"}
```

A run from a `file` is applied as the file at the source's current commit plus that text (`commit` null). The run
starts at `planning`; it answers once the plan is saved (status `applying`, `awaiting_approval`, `refused` or
`failed`).

**`GET /projects/{project}/config/runs`** (`config.view`): `{"data": [ConfigRun], "links": …, "meta": …}`, newest first,
`?environment=` filter. **`GET /config/runs/{run}`** (`config.view`): `{"data": ConfigRun}`. **[decision]** these two
are added for `falak apply --wait`.

**`POST /config/runs/{run}/approve`** (`config.approve`): `{}` → 200 `{"data": ConfigRun}` (status `applying`); 409
`{"message": "This run is not awaiting approval."}`. **`POST /config/runs/{run}/cancel`** (`config.apply`): awaiting
approval or planning → `cancelled`.

**`GET /projects/{project}/config/export?environment=production&format=yaml`** (`config.view`)

```json
{"data": {"environment": "production", "format": "yaml", "file": "<text>"}}
```

The file holds `units` (today's state, `Reconcilable::current`), the environment with its `branch` and `servers`,
and `previews`; defaults are left out. Exporting then planning gives an empty plan.

**Runs over the API [decision]** (phase 1, pinned for the CLI): the run's `status` alone says whether it is finished
(`applied`, `failed`, `cancelled`, `refused`). `deployments[]` entries are `{unit, site_id, deployment_id, status,
deployment_status, error}` with `status` one of `pending`, `deploying`, `released` (the release went live and phase B
was applied), `skipped` (not deployed: no deploy source, push to deploy off on a push, or a unit the file removed),
`failed`, `cancelled` (the deployment was cancelled), `superseded` (a newer run of the environment took over); `deployment_status` is
the deployment's own status. A failed run carries `error_code`: `invalid` (see `errors`), `unreadable` (the file couldn't
be read at the commit), `apply_failed` (a phase failed; phase A failures deploy nothing), `deployment_failed` (a unit's
deployment failed or was cancelled: its entry has `status: failed` or `cancelled`; the CLI exits 3). A superseded run
ends `cancelled` with `error` "Superseded by a newer run (<id>)". `errors` and `warnings` are split by severity.
`POST …/config/runs` answers `422` with the issues (and the stored `run`) for an invalid file.

### Phase 1 decisions (control plane)

Settled while building phase 1 (PHP lane, `feat/config-p1`); each is a **[decision]** on top of the sections above.

- **[decision] The file manages only what it sets.** An absent field keeps today's value (no reset to the schema's
  default): `laravel.scheduler` absent keeps the Laravel preset's scheduler, `deploy.health` absent keeps the site's
  health check. Removing a field from the file makes it editable again; it is not reset. The schema's "default"
  descriptions are what a new unit gets.
- **[decision] Hooks merge into the deploy script's sections.** Each `deploy.hooks.<section>` the file sets replaces only
  that section of today's `deploy_script` (`before_fetch`, `before_activate`, `after_activate`, `after_restart`); absent
  hooks keep today's section (e.g. the Laravel preset's `optimize`, `storage:link` and leader-only `migrate`), and the
  macros stay. Ownership is per section (`/deploy/hooks/before_activate`).
- **[decision] A file without `environments`** binds the project's production environment to the repository's default
  branch: a push there applies it to production, and plans for production work as if the file said
  `environments: {<production slug>: {branch: <default branch>}}`. Other environments then have no file.
- **[decision] Servers attached to a project** (`name.server`): the servers hosting any of the project's sites, in any
  environment. A new project's first service is created in the dashboard; the file can then use its servers.
- **[decision] Phase 1 supports `app` units** (sections `units.*(app)…`). Everything no reconciler owns yet (database,
  redis, compose and function units, volumes, mounts, previews…) is a `value.unsupported` **warning** and is ignored,
  so a file written for later phases still applies its app units.
- **[decision] Phase B runs when the unit's release went live** (`ReleaseActivated`: every server activated and the
  health checks passed), not inside the activate step: a failed deployment never gets the new workers, schedules,
  domains or edge rules. Units with changes that are not deployed (no deploy source, or removed from the file) get
  phase B right after phase A.
- **[decision] Which units deploy:** those with an actionable change in the plan, plus (on a push) the units whose
  `root` holds a file the push changed. The changed files come from the push payload (`commits[].added|modified|
  removed`); when it can't list them (Bitbucket, a push of 20 commits or more, a new branch) every unit counts as changed.
- **[decision] A rollback** (manual or the release watch's) re-applies the config saved on the release it returns to,
  as a `rollback` run (both phases, no deployment). A deployment Config didn't start (dashboard, API) saves the last
  applied run's unit config on its release.
- **[decision] Pull request plans are not runs:** the plan goes to the PR comment and the `falak/config` status only
  (`trigger: pr` stays reserved). `config_pull_requests` (`project_id`, `number`, `comment_id`, `head_sha`, `state`)
  keeps the one comment per pull request; a head already planned isn't planned again.
- **[decision] Plain git servers** (no provider API) can't be a config source yet: connecting one is refused, and a
  push whose file can't be read records a failed run. (The plan's builder-side read is a later step.)
- **[decision] An environment key the project doesn't have** fails that environment's run ("create it in the
  dashboard") until the Projects reconciler (phase 3) creates environments.
- **[decision] Dashboard routes** live under `/projects/{p}/settings/config` (`settings` is a reserved environment
  slug, so they can't collide with the canvas's `/projects/{p}/{environment}`).
- **[decision] Paths in `config_ownership`** for whole resources are the resource's pointer (`/workers/queue`,
  `/domains/shop.example.com`); for fields of the unit's site, one row per field (`/deploy/strategy`, `/env/APP_KEY`).
  A disabled source keeps its rows but `ManagedResources::managed()` answers nothing, so every field is editable.
- **[decision] Env:** keys merge into today's variables (a new environment version); a key dropped from the file stays,
  no longer managed. Integers and booleans are stored as text. A stored secret value never appears in a plan (`••••`),
  and `export` leaves secret-looking literal values out (it never writes values, nor references the store doesn't
  have), so export → plan stays empty. `expose` is one field (the whole list).
- **[decision] Units the file adds** are created through `SiteFactory` (Sites' `CreateSite`, the same path as the
  dashboard) with push to deploy on, so the framework preset applies: a Laravel unit gets a generated `APP_KEY` (and `APP_NAME`, `APP_URL`)
  unless the file sets them; `falak init` relies on it and leaves `APP_KEY` out. A new unit needs the environment's
  `branch` and servers (the unit's, else the environment's); `framework` defaults from the runtime. The site is named
  after the unit key (`<unit>-<env>` when taken in the organization).
- **[decision] Servers** compare equal when the leader (first) and the set are the same; member order doesn't matter.
- **[decision] Limits:** a managed limit locks the whole site limits editor in the dashboard (it saves every field at
  once); the server refuses (422) only edits that change a managed field.
- **[decision] Guards** refuse (422, on the input key) only an edit that changes a managed value; resubmitting a form with
  the managed values unchanged is accepted.
- **[decision] Processes:** a worker the dashboard created without a name reads as `worker-<n>`; a file using that key
  adopts the row and names it. Daemon and schedule names the file uses are refused (422) when created in the
  dashboard. Worker and daemon `servers` must be among the unit's servers (`name.server`). Env values never appear in a
  plan (`••••`); phase B re-plans right before applying, so the values come from the file then.
- **[decision] Edge:** a listed domain's `primary`, `www` and `tls` are always managed (their defaults: the first
  domain, `none`, `auto`); `dns_credential`/`certificate` only with their TLS mode; Cloudflare options only when set.
  Only the site's own routes are managed (compose services' routes and `mounts` come with phase 3). A host already used
  outside the environment is `value.conflict`.
- **[decision] Defaults on create** are the module's (e.g. a daemon's `stop_timeout` is 30s in Processes; the schema
  description's 10s is corrected at release).
- **[decision] CLI compatibility** (from the CLI lane): the project comes from `-p` (id or name through `GET
  /projects`), `FALAK_PROJECT`, or `.falak/project.json` `{"project", "name", "url"}`; `falak validate` also calls the
  validate endpoint (stage 4) when logged in and only warns when it is unreachable. No approve command until phase 4.

### Phase 1 review fixes

- **[decision] Processes run as the site's user.** A daemon or schedule in the file may leave `user` out or name the
  unit's site unix user; anything else is the stage 4 error **`value.forbidden`** (a new code), and apply always writes
  the site's user. Push access to the repository never runs a process as another (sudo) user.
- **[decision] Deleting a unit** needs `allow_destroy` (`config.destroy`) and a unit the file created; otherwise it is
  detached. Every unit delete is risky (rule **`unit.delete`** in `risky-changes.json`), so it is refused while the
  source is experimental. Units map to sites by the resource the file manages (`config_ownership`), not by the canvas
  name, and a managed service can't be renamed in the dashboard (422); rename the unit in the file.
- **[decision] Who Config deploys on push:** a site is Config's only when it is a unit of the file last applied to its
  environment, deploys from the source's repository and connection, and that file binds the site's branch. Every other
  site keeps push to deploy. A push whose file is missing, unreadable or invalid records a failed run only for the
  environments the last applied file binds to that branch, and those sites deploy the old way for that push. A push
  never deploys a site whose push to deploy is off (its config is applied; entry `skipped`). A commit already being
  deployed for a site (whichever of push to deploy and Config got the push first) is not deployed twice.
- **[decision] Env values** are masked (`••••`) in stored plans, in plan, run and export responses for callers without
  `sites.env.view`, and always in pull request comments (env changes show the key and added / changed / removed).
  References (`${{ … }}`) are kept. Exports without `sites.env.view` leave literal values out. The run's raw file is
  only returned with `sites.env.view`.
- **[decision] Phase A doesn't touch the running release:** `laravel` (scheduler, Horizon, Octane) is applied in phase
  B, new units included (they start with the preset's settings). `servers` stay in phase A: the deployment must reach
  the new servers; changing them on production is risky (`production.servers`).
- **[decision] Runs of an environment apply one at a time** (a lock); a run that starts applying supersedes the older
  runs of the environment not finished yet (`cancelled`, their entries `superseded`). Applying (and approving) plans
  again against today's state, re-checks the risky rules and that the source is still enabled (a disabled source
  cancels the run). A release is paired only with the run whose deployment it is; a cancelled deployment settles its
  entry.
- **[decision] Changed files are unknown** (every unit counts as changed) for a force push, an empty commit list, 20
  commits or more, or a truncated list.
- **[decision] A rollback to a release made before the file** records an applied `rollback` run with the warning
  `rollback.no_config` ("the config was not restored").
- **[decision] Certificates and DNS credentials** a file names must be used by the project's domains already, uploaded
  for one of its sites, or listed by an admin in the source's allowed credentials (`config_sources.allowed_credentials`,
  Project settings → Config as code); else `name.certificate` / `name.dns_credential`. A host taken by another
  organization answers "This domain is not available."
- **[decision] Commits are up to 64 characters** (`config_runs.commit`, SHA-256 repositories). "Apply now" in the
  dashboard deploys the head of the branch the environment's last applied file binds (the default branch for
  production without environments) and labels the run with that branch and commit.

### Phase 3 (data): databases, Redis, volumes, backups

Built on `feat/config-p3-data` (Databases' `DatabasesReconciler`, Volumes' `VolumesReconciler`, both phase A). Each
item is a **[decision]** on top of the sections above; they replace phase 1's `value.unsupported` warnings for
`database` and `redis` units and app units' `volumes`.

- **[decision] Owners and order.** Databases owns `units.*(database|redis)` as one section (the whole unit); Volumes owns
  `units.*(app).volumes`. Volumes applies **after Sites** (`Reconcilers::ORDER`): a volume is mounted into its unit's
  site, which Sites creates for a unit the file adds. Databases stays before Sites, so a release's env can reference it.
- **[decision] A unit is a database container.** A `database`/`redis` unit is the environment's canvas service of that
  name (kind database) and the container its database lives in; one unit per container. A managed unit is mapped by the
  container id in `config_ownership` (`database_instance`), so renaming its canvas service doesn't turn a push into a
  create (`ScopeFactory`, as for sites). A unit the file adds is created through `CreateInstance` (the canvas path) and
  linked on the canvas under the unit key (`PreviewEnvironments::place`, the idempotent link contract, in a free grid
  cell): `${{ db.DATABASE_URL }}` resolves to the container's first database with the unit's user. Its container is named
  after the key (41 characters at most), else `<key>-<environment>`, `-2`…
- **[decision] Defaults** are the module's, and the file manages only what it sets: a database unit gets one database
  named after the key (dashes as underscores), not managed until the file lists `databases`; `redis` defaults to the
  `redis` engine; `server` to the environment's first server; PITR follows `CreateInstance` (on in production with the
  organization's first provider, off in previews) unless the file has `pitr`; drills follow the environment.
- **[decision] `databases` are items by name** (`/units/db/databases/shop`): a new name is created (while the container
  starts, it waits pending and is created with it) and the unit's user (the container's oldest) gets all privileges on
  it. A name dropped from the list is detached (kept, unmanaged); it is deleted only with `allow_destroy` and when the
  file created it, and that is the new risky rule **`database.database_removed`**. Databases created in the dashboard
  are left alone. The file never manages users.
- **[decision] Passwords** are Falak's (generated by `CreateInstance`, never read by the reconciler): none appears in the
  file, plans, runs, exports, API answers or pull request comments (tested against every generated password).
- **[decision] `backups` is the container's schedule named `falak.yaml`** (other schedules stay the dashboard's, and the
  dashboard can't create one by that name on a managed container). `backups.databases` defaults to all and compares as a
  set. Removing the block detaches the schedule (it keeps running); deleting it needs `allow_destroy` and a schedule the
  file created (its backups stay in storage). `enabled: false` is risky (**`database.backups_disabled`**).
- **[decision] `pitr` is fields of the container**, one change per field; present ⇒ enabled. Removing the block never
  turns PITR off: it is detached (risky `database.pitr_removed`), and `enabled: false` or a shorter window are risky.
- **[decision] `version` is the engine's major line** (`databases.versions`); minor versions come with Falak releases
  (the pinned image digest of the line) and are not in the file. Every `version` change is a major upgrade: risky
  (`database.version`), and once approved it runs `UpgradeInstance` (PostgreSQL / MySQL / MariaDB: a new container and a
  copy; Redis / Valkey: in place). A downgrade is `value.unsupported` at stage 4. After a SQL major upgrade the unit
  follows its canvas service to the new container and the next apply records ownership on it.
- **[decision] Engine changes and moves are never applied by a run.** They stay risky (`database.engine`,
  `database.move`), and an approved run fails phase A with a message: a new engine is a new unit, a move is the
  Databases page's (relocation restores from backups). A volume's `kind` can't change (`value.conflict`); its `server`
  change is the new risky rule **`volume.move`** and is not applied either.
- **[decision] Restarts are notes, not risks.** `Change` gets an optional **`note`** (omitted when null; API, PR comments
  and the dashboard show it): an update of `memory`, `cpus`, `settings.*`, `require_tls` or `public` on a running
  container says "Restarts the database container to apply it" (`db.instance.update` re-renders its config and restarts
  it). `allowed_sources` applies live (firewall); `disk` grows online (`ServiceVolumes::resize`, a new contract method),
  and a smaller disk is risky (`volume.shrink`) and refused by Volumes.
- **[decision] A new unit that is `public`** is flagged `database.public` by the reconciler (on the whole-unit create the
  path rule can't see).
- **[decision] Storage is named only.** `backups.storage`, `pitr.storage` and volume `backups.storage` must be a
  provider the project already backs up to (its databases' schedules and PITR, its volumes' schedules, any
  environment) or one an admin listed in the source's allowed credentials (`allowed_credentials` now also holds
  storage provider names; Project settings → Config as code), else `name.storage`: `BackupStorage::namesForProject`.
  It is checked at stage 4 and again at apply, before a new container exists (its PITR default must not make the
  organization's first provider usable). A `drill_server` must be attached to the project (`name.server`): drills put
  restored data on that server.
- **[decision] Attached servers** (`name.server`) also include the servers running the project's databases.
- **[decision] Removing a data unit** detaches it: the container keeps running with its data, no longer managed, its
  status message says so (`unit.remove_with_data`, refused while experimental). With `allow_destroy` and a container the
  file created, it is deleted (`InstanceLifecycle::delete`) and its data volume is kept.
- **[decision] Volumes are for app units with the `docker` runtime** (`value.unsupported` otherwise); compose units'
  volumes stay a `value.unsupported` warning (their compose file declares what each service mounts; a compose stack
  can't mount a Falak volume yet). A unit's volume is a docker or sized volume attached to its site, keyed by the key the
  file manages it under (ownership), else its name; a new one is named after the key, else `<unit>-<key>`, then
  `-<environment>`, on the unit's leader server by default. Mounting and unmounting don't redeploy on their own: the unit
  deploys with the run. A `mount`/`read_only` change remounts it.
- **[decision] Removing a volume** unmounts it from the unit and keeps it with its data, flagged in its status message
  (`volume.delete`, risky). With `allow_destroy` and a volume the file created it is deleted, never while protected.
  A volume's `backups` is its oldest schedule; removing the block detaches it (**`volume.backups_removed`**), and
  `enabled: false` is **`volume.backups_disabled`**.
- **[decision] Ownership** resource types: `database_instance` (the unit's fields: `/memory`, `/backups/cron`,
  `/pitr/window_days`, `/databases/shop`), `database` (`/databases/<name>`), `backup_schedule` (`/backups/…`, or
  `/volumes/<key>/backups/…`) and `volume` (`/volumes/<key>/…`). The Databases and Volumes pages show the badge and
  disable those fields; the server answers 422 to a change of them (limits, settings, access, version, PITR, the
  schedule, deleting a managed container, database, schedule or volume, unmounting, resizing or moving a managed
  volume). Unchanged managed values and unmanaged fields are accepted; a disabled source unlocks everything.
- **[decision] A rollback never applies a risky change** (it has no approval): restoring a release made before a volume
  was added would unmount it, so phase A of a `rollback` run skips risky changes and lists each as a
  `rollback.risky_skipped` warning.
- **[decision] Preview environments** need nothing special: the reconcilers take their scope as any environment's
  (`CreateInstance` keeps PITR off there). The preview strategies (`previews.units.<db>`) are the previews lane's.
- Known gaps: compose units' volumes and `replace_with`; applying engine changes, moves and volume kind changes; the
  Playwright check of the read-only fields.

Phase 3 review fixes (data lane):

- **[decision] The canvas service's database is never deleted** by a run (dropping it from `databases` only detaches
  it, `allow_destroy` or not), and the dashboard refuses deleting a managed container's first database, deleting its
  canvas service, or renaming it (422), as for sites. A unit the environment's file manages whose container no canvas
  service points to any more is a stage 4 `value.conflict` and nothing is planned for it: never a new, empty container.
- **[decision] Ownership follows a container that takes over** (a major upgrade, a swapped PITR restore):
  `TakeOverInstance` moves its `config_ownership` rows (`ManagedResources::move`).
- **[decision] Removal decisions are the environment's**: detach or delete, "managed" and "created by the file" read
  only the scope environment's ownership rows (`managedIn`, `createdByFile(…, $environmentId)`), and `forget` drops
  only them. Servers and storage count for a project only through containers of its own (non-preview) environments.
- **[decision] Databases a unit adds** are placed on the unit's environment canvas as `<unit>-<name>` (not the default
  project).
- **[decision] Rollbacks never create data resources** (a database or Redis unit, a volume, a database): an empty one at
  an old mount path or name would stand in for detached data. They are skipped with a `rollback.risky_skipped` warning;
  adding them back takes a push (a volume a run unmounted to delete is then mounted again, see below).
- **[decision] Retention and coverage are risky**: new rules `database.backups_retention`, `volume.backups_retention`
  (condition **`tightens`**: a `keep`/`keep_days` set where there was none, or lowered) and
  `database.backups_databases_removed` (condition **`removes_items`**). Only the control plane reads
  `risky-changes.json` (the Go CLI doesn't).
- **[decision] Preview servers are not production's**: `attachedServers` leaves out preview environments' servers
  except for a preview's own scope, and a production `drill_server` change is risky (**`production.drill_server`**).
- **[decision] Removing a volume deploys its unit** (a phase A removal below a unit deploys it; phase B removals don't).
  With `allow_destroy` the volume is unmounted and marked, and deleted by a later run with `allow_destroy` once the
  unit's last deployment, started after the unmount, succeeded (or the unit's site is gone): never while a running
  release may mount it. Listing its key again mounts that volume again.
- **[decision] Restart notes** also go on settings, CPUs, TLS or public set for the first time on a running container.
  A version upgrade must be alone in its push for that unit (`value.conflict` otherwise), and `disk` on a container
  whose data volume isn't sized is `value.unsupported`.

### Phase 3 (units): compose, functions, environments, previews

Built on `feat/config-p3-units` (the data lane, `feat/config-p3-data`, builds database and redis units, volumes and
backups). Each choice is a **[decision]** on top of the sections above; the phase 1 safety rules apply unchanged
(processes run as the site's user, no host paths, names-only credentials, ownership by resource id, masked env values,
deletes risky and only with `allow_destroy`). The `value.unsupported` warnings are gone for compose and function units,
`mounts` and `previews`; database and redis units and `volumes` keep them until the data lane lands.

**Engine**
- **[decision] A `*` inside a unit's section matches any key of a map** (`units.*(compose).services.*.limits`), so
  Sites, Limits and Edge each own their fields of one compose service.
- `ConfigScope` gains `preview` (a pull request's preview environment): units it creates are isolated, never deployed
  on push.
- A push deploys app, compose and function units whose `root` holds a changed file.

**Environments (Projects reconciler, section `environments`, phase A first)**
- **[decision] A push creates an environment the file declares and the project doesn't have** (review fix: **only a
  push to the production environment's branch** — its `branch` in the pushed file, else the repository's default
  branch — creates them, every declared one, whatever branch it is bound to; a push to another branch naming an
  unknown environment records a failed run saying so). Creating is serialized per project (two pushes create it
  once), empty, named after the key, **not production**. `production: true` (on a new or an existing environment) is then the run's
  `environment.production` change: refused while the source is experimental, else it waits for an approval. Only
  `production` is managed (ownership `environment`, `/production`); `branch` and `servers` have no column.
- A reserved slug (`settings`, `previews`…) is `value.forbidden`, a key with capitals "must be lowercase"; a key taken by a preview environment's slug fails the
  run (never a run against another environment than the one the file names). `falak apply -e <new>` creates a declared
  environment too, for a caller who may also manage projects (`projects.manage`); `falak plan -e <new>` still answers
  that the environment doesn't exist.
- **[decision] An environment removed from the file is released, never deleted**: on the next push to the branch the
  last applied file bound it to, its sites deploy that push the old way and its ownership rows are dropped (fields
  editable, push to deploy takes over), and its runs still planning or waiting for an approval are cancelled. A file
  without `environments` declares production only: the other environments it bound are released the same way.
- **[decision] A unit follows its environment's branch:** when the file moves an environment to another branch, the
  file-managed sites deploying the source's repository take the new branch at the environment's next run.
- Several environments: each environment bound to the pushed branch gets its own run, resolved with its overrides
  (the merge of the phase 0 contract); a unit an environment doesn't have yet is created there on the environment's
  servers with its branch.

**Compose units**
- Owners: Sites (`type`, `root`, `servers`, `env`, `expose`, `test_domain`, `files`, `profiles`,
  `services.*.port|health_path|replace_with`), Deployments (`deploy`: `compose`, `rolling` or `canary`; `deploy.hooks`
  is `value.unsupported`), Limits (`services.*.limits` → `compose_limits.<s>`, merged over the service's own limits, the
  `*` defaults untouched, applied on the next deploy), Edge (the unit's `domains`, `redirects`, `headers`, `mounts`: its
  first public service's route; `services.<s>.domains|redirects|headers`: that service's, `services.<first>` being the
  unit's own).
- A new unit is created through `SiteFactory` from the repository (`compose_source: repo`, the unit's files relative to
  its root, public services in the file's order). **[decision] Public services keep their order** once created: a
  service the file makes public is added last; removing a `port` stops managing it (it stays public).
- **[decision] `replace_with`** names a database or redis unit (or a database service of the environment): the stack's
  service runs as that instance (Sites' extraction, through Databases' `DatabaseDirectory` contract only). Switching a
  replaced service to another database, putting it back, and running a service as its own site stay dashboard
  actions (`value.conflict`).
- **[decision] Host binds stay an admin's choice:** `keep_binds` (and any other adjustment) is not in the schema, so a
  file with it fails validation, and applying a file never changes a stack's adjustments.
- The dashboard shows managed compose fields read-only with the "Managed by falak.yaml" badge, and refuses (422) an
  edit changing one (files, profiles, a public service's port or health path, a replaced service — a form resending
  the same decision passes — and restoring an inline version).

**Function units**
- **[decision] A function unit's code is the repository's files under its `root` at the run's commit**, read by the
  control plane through the provider API (`tree` + `file`, like falak.yaml): no builder or agent change. Skipped:
  dot-files and dot-folders, `node_modules`, `__pycache__`, `.venv`, `venv`, falak.yaml, names a version can't hold and
  non-UTF-8 files; the list must be whole (a provider listing that was truncated, or a limit reached, fails the run
  instead of deploying part of the function: `tree(…, complete: true)` throws `TreeTruncated`); the rest must be a valid version (≤ 50 files, ≤ 1 MB, the entrypoint present), else `value.range` on
  `root` (a plain git server can't give the code either). Phase A saves the code as a new `FunctionVersion`
  ("falak.yaml @ abc1234") only when its hash changed, and **records the commit against the version**
  (`functions_version_commits`): the unit's deployment of that git commit runs exactly that version (Functions'
  `FunctionSources::find` matches a version hash or such a commit). A run without a commit (a CLI file) reads the
  environment's branch and deploys the newest version.
- A function site the file creates has no repository: it deploys versions, push to deploy never touches it, and Config
  deploys it when its code or its config changed. **[decision] The editor no longer deploys** a function whose code the
  file manages (ownership `/code`); rolling back to a version still works.
- Functions (phase A) owns `runtime`, `entrypoint`, `root`, `scaling`, `memory`, `cpus`, `timeout`, `access` (written
  on the function row: the run's deployment carries them, no extra redeploy); API keys stay in the dashboard.
  `schedules` are Functions' phase B (`function_schedule` ownership: deleted when the file created them, else
  released). A function's `domains` are Edge's; a function created by the file gets no default domain (it is reached
  through its domains or mounts).
- `mounts` (Edge, phase B of the host unit): identity `path`, `function` must be a function unit (`name.unit`),
  `strip_prefix` default true.
- The dashboard shows a function's managed code, scaling, allowlist and schedules read-only with the badge, and
  refuses (422) edits of them.

**Previews**
- **[decision] `previews:` is applied by the run of its `base` environment** (production's when it names none); other
  environments' runs plan nothing for it. `PreviewSettings` is the stored copy (through `SavePreviewSettings`); fields
  the file doesn't set keep today's value, `units` replaces the map, a removed block is released (previews stay on).
  `fork_server` must be the dashboard's fork server or a server attached to the project.
- **[decision] Production data in previews needs an approval:** whenever a database unit's preview copy newly
  resolves to production's backups — its `strategy`, its `source`, or the block's `base` (a copy without `source` clones
  the base) changed — or a script run on such a copy changes, the change is the risky `previews.production_data`
  (flagged by the reconciler, on the unit's or the base's change). **Sharing a database** with the base environment is
  the risky `previews.shared_database`. Only an approved change sends the production acknowledgement the dashboard
  asks for (an acknowledgement already given stays).
- **[decision] A preview is built from the pull request's file** (`Config\Contracts\PreviewConfig`): the head's file
  for a pull request of the repository, **the base branch's newest file for a fork** (after the existing
  `/falak preview` approval; a fork never supplies config), resolved for the previews' base environment. Left out:
  database and redis units (Previews creates their copies per data strategy), `domains` (the preview hosts),
  `servers` (the preview server), `volumes`, a compose service's `domains` and `replace_with`. The file's
  `previews.units: omit` leaves units out; data strategies, sharing, the server and access stay the stored settings'
  (the base branch's), so a pull request can't widen what data reaches its preview.
- Phase A is applied to the preview environment before its first deploy and again before a redeploy whose file
  changed (`previews_previews.config_ref`), recorded as a `pr` run of the preview environment; risky rules don't apply
  (the preview is disposable). **[decision] Phase B (workers, schedules, routes) goes live with each unit's preview
  deployment** (`PreviewConfig::released()` when it succeeded), never before.
- **[decision] No reads of a shared service's values:** an env value of the preview's file referencing a service the
  preview shares (`${{ api.APP_KEY }}`) is left out (with a warning) unless `previews.variables` lists that name. Units the base environment doesn't have are created in the preview (isolated, never
  deployed on push, on the preview server, the pull request's branch) and routed like the copies; a fork's preview
  never gets new units. The Previews settings form shows the badge and is read-only when the block manages it (it saves
  every field at once); the server refuses (422) managed changes. Workers, schedules, deploy settings, Laravel settings and limits now come from the file (the
  `DuplicateSite` gap is closed for projects with a file). An invalid file fails the preview with the reason.
- Teardown deletes only the preview's own resources: its recorded sites and any site placed in the preview
  environment, never a site placed elsewhere, then forgets what the file managed there.

**Gaps (later)**
- `falak plan` for an environment the project doesn't have yet.

### Phase 4: approvals, ownership, drift

Contract for both lanes (control plane `feat/config-p4`, CLI `config-cli`). Choices are **[decision]**s.

**Approvals replace refusals.**
- **[decision]** A risky plan always waits: the run goes to `awaiting_approval` (never `refused` any more; `refused`
  stays a status only old runs carry). `config_sources.experimental` stays as a label only ("Experimental" in the
  dashboard until the release), with no effect on runs.
- **[decision] Who approves:** anyone with `config.approve` (admins by default), the run's requester included, unless
  the project's admin setting **"approver must differ from the requester"** (`config_sources.separate_approver`, off by
  default) is on. A push run has no Falak requester (`requested_by` null), so the rule never blocks it.
- **[decision] Approvals expire** after `config_as_code.approval_days` (env `FALAK_CONFIG_APPROVAL_DAYS`, default 7):
  the run is cancelled (`cancel_reason: expired`). A newer run of the environment supersedes a waiting one
  (`superseded`), a disabled source cancels it (`source_disabled`).
- **[decision] Approving plans again.** The approval covers the risky changes the approver saw, by `op` + `path` +
  `rule` (an approved `detach` never covers a `delete`). The approver sends the **`plan_hash`** they were shown: when
  it isn't the stored plan's, nothing is approved (`409`, "The plan changed since it was shown; review it again.").
  After approving, the run plans again; if the fresh plan has a risky change outside the approved set, nothing is
  applied and the run waits again with the new plan (same `409`). Otherwise it applies.
- **[decision] `plan_hash`** (review fix H1) is the hex SHA-256 of the canonical risky set of the run's stored plan:
  the risky changes as `{"op", "path", "rule"}` objects (keys in that order, a missing rule as `""`), sorted by `path`,
  then `rule`, then `op` (byte order), JSON-encoded without spaces or escaped slashes. Clients echo the value from the
  run; they never compute it.
- **[decision] One waiting run per environment** (review fix H2): a run that starts waiting supersedes the older
  waiting runs of its environment, and a run can't be approved once a newer run of its environment exists (`409`, "This
  run is not awaiting approval."; it is cancelled as `superseded`).

`ConfigRun` gains (other fields unchanged):

```json
{"approval": {"required": true, "risky": [{"path": "/units/web/deploy/strategy", "rule": "production.strategy", "reason": "…"}],
              "expires_at": "2026-10-18T12:00:00Z", "approved_by": null, "approved_at": null,
              "rejected_by": null, "rejected_at": null, "comment": null,
              "can_approve": true, "blocked_reason": null,
              "plan_hash": "3f1a…"},
 "cancel_reason": null}
```

`approval` is null for a run that never needed one. `can_approve` / `blocked_reason` are for the caller (`blocked_reason`:
"You need the config.approve permission." or "You requested this run: another approver must approve it.").
`cancel_reason` (only when `status` is `cancelled`): `superseded`, `rejected`, `expired`, `source_disabled`,
`environment_released` (the file no longer declares the environment), `user`.

- **`POST /api/v1/config/runs/{run}/approve`** `{"plan_hash": "…", "comment": "…"?}` — `config.approve` (30/min).
  `plan_hash` is required (`422` without it). `200 {"data": ConfigRun}` (now `applying`, or already
  `applied`/`failed`); `403 {"message": blocked_reason}`; `409 {"message": "This run is not awaiting approval."}`
  (superseded, expired, rejected, cancelled, done, or a newer run of the environment exists); `409 {"message": "The plan
  changed since it was shown; review it again.", "data": ConfigRun}` (the hash differs, or the fresh plan has a risky
  change the approval didn't cover).
- **`POST /api/v1/config/runs/{run}/reject`** `{"comment": "…"?}` — `config.approve`. `200 {"data": ConfigRun}`
  (`cancelled`, `cancel_reason: rejected`, `approval.rejected_by`); `409` when not awaiting approval.
- CLI: `falak approve <run> [--comment]`, `falak reject <run> [--comment]`; `apply --wait` keeps exiting 6 while the
  run waits.

**Notifications.** Alert types (Alerting module, routed by the organization's rules to its channels):
`config.run.awaiting_approval` (warning, once per run), `config.run.failed` (warning, once per run), `config.drift`
(warning, once per drift signature of an environment). A push run waiting for an approval also edits the plan comment
of the pull request it merged (found by `#<number>` in the commit message or its head sha) with "Waiting for an
approval" and the run's link.

**Plan for an environment the project doesn't have yet.** `POST …/config/plan` with an `environment` the file declares
but the project lacks answers `200` with `data.environment_exists: false` and `data.preview_only: true`: the plan has a
`create` of `/environments/<slug>` and the creation of every unit, and a warning `environment.created_on_push` saying only
a push to the production environment's branch (or `falak apply -e` by someone with `projects.manage`) creates it.
Otherwise `environment_exists: true`, `preview_only: false`.

**Drift.** Today's state of an environment against its last applied run (every reconciler's `current()`/`diff()`,
env values masked).
- **`GET /api/v1/projects/{project}/config/drift?environment=production`** — `config.view`.
- **`POST /api/v1/projects/{project}/config/drift`** `{"environment": "production"}` — `config.view` (10/min): checks now.

```json
{"data": {"environment": "production", "status": "drifted", "checked_at": "…", "since": "…",
          "baseline_run_id": "01j…", "baseline_commit": "abc…", "signature": "9f2c…",
          "changes": [Change]}}
```

`status`: `in_sync`, `drifted`, `unknown` (nothing applied yet, or a run is applying). `changes` are the changes a run
would make to put the file back (`before` today's value, `after` the file's). A scheduled check runs every
`config_as_code.drift_minutes` (default 30).

`unknown` is **not drift**: never checked, nothing applied yet, or a run of the environment is planning or applying
(its phase B may still be on its way); the CLI exits 0 for it. **[decision]** `falak drift` exits **7** when the
environment drifted (0 for `in_sync` and `unknown`). A file without `environments` is planned, applied and
checked against the project's production environment, as for a push. The run list takes `per_page` (1–100, default 25)
and `page`.

**Built (phase 4, control plane).**
- **[decision]** Alerts are module events implementing `Alertable` (`ConfigRunAwaitingApproval`, `ConfigRunFailed`,
  `ConfigDriftDetected`), so the organization's rules and channels deliver them; their types are registered in the
  "Config as code" group. A run alerts once per transition (awaiting approval, failed); a drift once per signature, and
  again only after the environment was back in sync.
- **[decision] Phase B re-plans when the release is live and applies a risky change only if the run's approval covered
  it** (`path` + `rule`); others are skipped with the warning `phase_b.risky_skipped`. A rollback has no approval: every
  risky change, phase A or B (e.g. removing a production domain), is skipped with `rollback.risky_skipped`.
- **[decision] Drift** compares the last applied run's resolved file with today's state through the reconcilers' own
  `current()`/`diff()` (the plan a run would make: `before` today's, `after` the file's), env values masked; its
  signature is a hash of the sorted changes. The canvas shows a banner for a drifted environment with "Check again" and
  a link to the runs. Checks run every `config_as_code.drift_minutes` (default 30, at least 5) and skip preview
  environments.
- **[decision] The approval UI** lives on the run in Project settings → Config as code: the risky changes with their
  rule and reason, expiry, who approved or rejected and their comment; Approve / Reject with an optional comment for
  those who may. The "approver must differ from the requester" toggle is an admin setting there.

- **[decision] One deletion model, two classes.** Units (app, compose, function), data units, a unit's databases,
  volumes and backup schedules are deleted only with `allow_destroy` (`config.destroy`, the only entry point being
  `POST …/config/runs`, i.e. `falak apply --allow-destroy`) and when this environment's file created them; otherwise
  they are detached. Items hanging on a unit (workers, daemons, schedules, domains, redirects, headers, mounts, function
  schedules) are deleted without `allow_destroy` when this environment's file created them and their unit is still
  managed there (else detached): requiring it would make dropping a worker or a domain from the file never take effect;
  removing a production domain stays risky. Items follow their unit: a unit leaving the file detaches its items too.
  Every decision reads only the environment's ownership rows; apply re-checks it (a stale `delete` only detaches).
- **[decision] Detached resources are recorded** in `config_detached` (`ManagedResources::detach()`: label, the
  module's own dashboard delete route and the body it needs, the site it hangs on); preview environments record none.
  A row leaves the list when the file manages the resource again, when the module announces its deletion
  (`SiteDeleted` — with everything on that site —, `DomainRemoved`, `DatabaseDeleted`), or when the dashboard dismisses
  it after the module's delete succeeded. Releasing an environment records each resource its file managed
  (`environment_released`). The API list needs `?environment=` and has no delete route; the dashboard's has it.
- **[decision] The dashboard's "Unmanaged resources"** (Project settings → Config as code) deletes through the module's
  own route (its permissions, its confirmations, its 403/422 shown), then dismisses the row (`config.apply`).

**Unmanaged resources.** What a run detached (removed from the file but kept, or released with its environment).
- **`GET /api/v1/projects/{project}/config/unmanaged?environment=production`** — `config.view`:
  `{"data": [{"id", "environment", "unit", "resource_type", "resource_id", "label", "reason": "removed_from_file" |
  "environment_released", "run_id", "detached_at"}]}`. The dashboard lists them with the module's own delete (its
  permissions) and an "adopt into the file" hint; a resource the file manages again leaves the list.

### CLI (`agent/internal/cli`)

| Command | Flags | Does |
|---|---|---|
| `falak init [dir]` | `--full`, `--force`, `--yes`, `--stdout`, `--json`, `--update` (phase 4) | writes `falak.yaml` from the code |
| `falak validate [file]` | `-e/--env <slug>`, `--json`, `--format yaml\|json` | stages 1–3 offline (embedded schema); with a login and a linked project, also stage 4 through the API |
| `falak plan [file]` | `-e/--env` (default: the environment whose `branch` is the current git branch), `-p/--project`, `--json` | `POST …/config/plan` with the local file |
| `falak apply [file]` | `-e/--env`, `-p/--project`, `--allow-destroy`, `--wait`, `--json` | `POST …/config/runs`; `--wait` polls the run and streams its deployments |
| `falak config export [project]` | `-e/--env`, `-o/--output <file>`, `--format yaml\|json` | `GET …/config/export` |

`[file]` defaults to `falak.yaml`, then `falak.yml`, then `falak.json` in the current directory. The project comes
from `-p`, else `.falak/project.json` in the repository (written by `init` when logged in). Exit codes: 0 ok, 1 error,
2 usage, 3 a deployment failed, 5 the file is invalid, 6 the run waits for an approval (`apply` without `--wait`, or
`--wait` stopped there). `--json` prints the API's `data` (or the issues) as is. Issues print as
`falak.yaml:12:17: schema: /units/web/deploy/strategy: value must be one of …`.
