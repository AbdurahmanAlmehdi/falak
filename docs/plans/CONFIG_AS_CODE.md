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
