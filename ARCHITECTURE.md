# Falak — Architecture

Self-hosted server management, deployment and observability platform.
Scope = Laravel Forge + Envoyer (multi-server) + Coolify (Docker/native builds) + Nightwatch (APM) + LGTM observability.

> Working name **Falak**. Rename via `FALAK_*` env prefix + namespace `Falak\`.

---

## 1. Repository layout

```
infra-deployment/
├── ARCHITECTURE.md              ← this file (the contract)
├── contracts/
│   └── agent-protocol/          ← JSON Schemas shared by control plane (PHP) and agent (Go)
├── control-plane/               ← Laravel 12 modular monolith + Inertia React TS
│   ├── app/                     ← framework glue only (no business logic)
│   ├── modules/<Module>/        ← ALL business logic lives here
│   └── tests/Architecture/      ← Pest arch tests enforcing module boundaries
├── agent/                       ← Go: `falak-agent` (servers) + `falak` (CLI) + `falak-builder`
├── packages/
│   ├── apm-laravel/             ← `falak/apm-laravel` Composer package (Nightwatch-equivalent)
│   └── apm-node/                ← `@othmanhaba/apm-node` TS package (OTel preset for Node/Bun/Deno)
├── observability/               ← Loki, Tempo, Grafana, VictoriaMetrics|Mimir configs, dashboards, alert rules
└── sim/                         ← docker compose E2E simulation (control plane + fake Ubuntu servers + LGTM)
```

---

## 2. Control-plane modules

Every module lives in `control-plane/modules/<Module>/` and is registered by its own `<Module>ServiceProvider`.

| # | Module | Owns | Depends on (Contracts only) |
|---|---|---|---|
| 0 | **Kernel** | Base classes, ULID ids, `Result`, encryption casts, `ModuleServiceProvider`, event bus helpers, pagination/filter DSL | — |
| 1 | **Identity** | Users, organizations, teams, roles/permissions, API tokens, 2FA, audit log | Kernel |
| 2 | **Providers** | Cloud provider credentials + adapters (Hetzner, DigitalOcean, Vultr, Linode, AWS, Custom) | Identity |
| 3 | **Fleet** | Agent enrollment, mTLS CA + cert issuance, command bus to agents, heartbeats, output streams | Identity |
| 4 | **Servers** | Server lifecycle, types (app/web/db/cache/worker/lb/builder), provisioning plans, PHP versions, packages, SSH keys, php.ini | Providers, Fleet |
| 5 | **SourceControl** | GitHub/GitLab/Bitbucket/custom git, GitHub Apps (manifest flow), OAuth, deploy keys, inbound push webhooks | Identity |
| 6 | **Sites** | Sites, runtimes, domains/aliases, env vars (encrypted), shared paths, user isolation, commands | Servers, SourceControl |
| 7 | **Edge** | Caddy/FrankenPHP routes, TLS (ACME + custom certs), redirects, basic-auth/security rules, load-balancer upstreams, Octane proxying | Sites, Fleet, Processes (Octane routing) |
| 8 | **Builds** | Build pipeline (Railpack native, BuildKit Docker), artifacts, built-in registry, build cache | Sites, SourceControl, Fleet |
| 9 | **Deployments** | Deploy plans, strategies, multi-server orchestration, releases, rollback, health checks, deploy hooks, deploy scripts & macros | Sites, Builds, Edge, Fleet |
| 10 | **Processes** | Queue workers, Horizon, Octane (+ its routing state), daemons, scheduler/cron, heartbeats (missed-run detection) | Sites, Servers, Fleet, Deployments (live releases), Edge (Octane drain) |
| 11 | **Databases** | DB engines, databases, users, backups (S3/R2/B2/local), restore, storage providers | Servers, Fleet |
| 12 | **Network** | Firewall rules (nftables via agent), private networks, load balancers | Servers, Fleet |
| 13 | **Recipes** | Saved scripts, run on N servers, run history | Servers, Fleet |
| 14 | **Telemetry** | OTLP pipeline config, metrics backend adapter (`VictoriaMetrics` \| `Mimir`), Loki/Tempo clients, Grafana provisioning, dashboards | Servers, Sites |
| 15 | **Insights** | Nightwatch-equivalent: event ingest, issues (fingerprint/group), affected users, route/job/query thresholds, timelines | Sites, Telemetry |
| 16 | **Alerting** | Alert rules, routing, channels (email, Slack, Discord, Telegram, webhook), notification center | Identity |
| 17 | **Terminal** | Web terminal sessions (PTY over agent), shared sessions, recording | Servers, Fleet |
| 18 | **Projects** | Projects → environments → services (sites/databases on a canvas), `${{ service.KEY }}` variable references, canvas read model | Sites, Databases, Deployments, Servers, Fleet |

### 2.1 Module internal structure (mandatory)

```
modules/Sites/
├── src/
│   ├── Contracts/        ← PUBLIC: interfaces + DTOs other modules may use
│   ├── Events/           ← PUBLIC: domain events other modules may listen to
│   ├── Domain/           ← PRIVATE: Eloquent models, enums, value objects, policies
│   ├── Application/      ← PRIVATE: Actions (one class = one use case), queries, jobs, listeners
│   ├── Infrastructure/   ← PRIVATE: contract implementations, external clients, agent command builders
│   ├── Http/             ← PRIVATE: controllers, form requests, API resources
│   └── SitesServiceProvider.php
├── database/migrations/  ← tables prefixed with module name: sites_*, edge_*, ...
├── routes/{web,api}.php
├── resources/js/         ← Inertia pages + components (TSX), resolved as `Sites/<Page>`
└── tests/{Unit,Feature}/
```

### 2.2 Boundary rules (enforced by `tests/Architecture`)

1. A module may only import another module's `Contracts\*` and `Events\*` namespaces. Never `Domain`, `Application`, `Infrastructure`, `Http`.
2. **No cross-module Eloquent relations.** Store foreign ULIDs (`server_id`), resolve via Contracts.
3. Cross-module writes go through a Contract; cross-module reactions go through Events (queued listeners).
4. Each module owns its tables; no module queries another module's tables.
5. Controllers are thin: validate → call Action → return Resource/Inertia response.
6. All agent interaction goes through `Fleet\Contracts\AgentGateway` — no module talks to servers directly.
7. Secrets use the `Encrypted` cast; never logged, never sent to the UI unless explicitly revealed.

---

## 3. Control plane ↔ Agent protocol

Agent **dials out** (no inbound SSH required; SSH stays as bootstrap fallback).

- **Transport:** HTTPS with mTLS. Control plane runs an internal CA (Fleet module).
- **Enrollment:** `curl -fsSL https://<panel>/install/<one-time-token> | sh` → installs agent → `POST /agent/v1/enroll {token, csr, facts}` → receives signed cert + agent id. A new install command on an enrolled machine replaces its identity (backup in `/etc/falak/previous/`); the script ends with `falak-agent check` (`GET /agent/v1/ping`).
- **Command channel:** agent long-polls `GET /agent/v1/commands?wait=30`. Returns 0..N commands.
- **Results/streams:** `POST /agent/v1/commands/{id}/events` (batched NDJSON: `started`, `output`, `progress`, `finished`).
- **Heartbeat:** `POST /agent/v1/heartbeat` every 15s with facts + lightweight metrics summary.
- **Sessions:** each agent process sends `X-Falak-Agent-Session`; commands lost to a restart (or unacknowledged past
  a 90 s lease) are redelivered when their schema is `x-falak-redeliverable`, else failed (`contracts/agent-protocol/README.md`).
- **Telemetry** does **not** go to the control plane — agent relays OTLP straight to the observability box. Only Insights-relevant summaries (exceptions, threshold breaches) are teed to `POST /agent/v1/insights`.

Schemas live in `contracts/agent-protocol/*.schema.json`. Command envelope:

```json
{ "id": "01J...", "type": "deploy.activate", "timeout_s": 600, "payload": { } , "idempotency_key": "..." }
```

Every command type is **idempotent** (safe to re-run) and declares a JSON Schema for `payload`.

### Command catalogue (v1)

| Namespace | Commands |
|---|---|
| `system` | `facts`, `exec`, `write_file`, `package.install`, `user.create`, `ssh_key.sync`, `upgrade_agent` |
| `provision` | `apply` (declarative provisioning plan: packages, users, runtimes, services) |
| `runtime` | `php.install`, `php.configure`, `node.install`, `bun.install`, `deno.install`, `frankenphp.configure`, `fpm.pool` |
| `edge` | `caddy.apply` (full route set, atomic via Caddy admin API), `cert.install` |
| `deploy` | `fetch`, `prepare`, `hook`, `activate`, `rollback`, `prune`, `container.swap` |
| `proc` | `apply` (desired set of supervised processes: workers, daemons), `restart`, `status` |
| `cron` | `apply` (desired schedule set with heartbeat wrapper) |
| `db` | `create`, `drop`, `user.apply`, `backup`, `restore` |
| `net` | `firewall.apply` (nftables), `wireguard.apply`, `tunnel.apply` (Cloudflare Tunnel) |
| `fn` | `release.apply` (write + install a function release, make it live behind `falak-fn-gateway`), `release.remove`, `status` |
| `docker` | `pull`, `run`, `stop`, `compose.up`, `compose.down`, `compose.pull`, `compose.ps`, `compose.restart`, `prune` |
| `telemetry` | `configure` (OTLP endpoints, sampling, log sources) |
| `terminal` | `open`, `input`, `resize`, `close` |

State-style commands (`*.apply`) send the **full desired state**; the agent converges. This keeps the agent stateless-ish and re-runnable.

---

## 4. Agent (Go) — `agent/`

```
agent/
├── cmd/falak-agent/     ← server daemon
├── cmd/falak/           ← CLI (talks to control-plane public API)
├── cmd/falak-builder/   ← build worker (Railpack/BuildKit), runs on builder nodes
└── internal/
    ├── enroll/  transport/  commands/ (registry + executors)
    ├── deploy/  docker/  runtime/  edge/ (Caddy admin API)
    ├── supervisor/      ← built-in process supervisor (replaces supervisord)
    ├── cron/            ← built-in scheduler w/ heartbeat (replaces crontab juggling)
    ├── metrics/         ← /proc reader → OTLP metrics (replaces node_exporter)
    ├── logs/            ← file/journald/docker tail → OTLP logs (replaces promtail/alloy)
    ├── otlp/            ← relay: unix socket + OTLP/HTTP receiver → batch → exporter
    └── pty/             ← terminal sessions
```

Budgets: static binary ≤ 20 MB, RSS ≤ 30 MB idle, ≤ 1% CPU idle on 1 vCPU.

---

## 5. Deployment model

### Runtimes (per site)
`frankenphp` (default for PHP) · `php-fpm` (+ Caddy) · `node` · `bun` · `deno` · `static` · `docker` (image or Dockerfile) · `compose`

Laravel **Octane** (PHP runtimes): Processes supervises `octane:start` (FrankenPHP worker mode / Swoole / RoadRunner) on a
per-server unique loopback port that Sites allocates; Edge switches the site to `reverse_proxy` (existing `public/` files
still served by Caddy) only after Processes verified Octane answers (`Processes\Contracts\OctaneRouting`), and switches
back before Octane is stopped. Deploys restart Octane (a reload would keep the old release) while Caddy holds requests.

### Build modes
- `native` — Railpack detects stack → produces release tarball (vendor/ + node_modules/ + built assets).
- `docker` — BuildKit builds image → pushed to built-in registry.
- `on-server` — allowed only when server has ≥ 2 GB RAM (explicit opt-in).

Builds run on a **builder** (control plane host by default, or dedicated `builder` server). Managed servers never build.

### Strategies
`in-place` · `zero-downtime` (releases + `current` symlink) · `blue-green` (containers) · `rolling` (N at a time) · `canary` (1 node, verify, rest)

### Multi-server orchestration (Deployments module)
```
BUILD once → FETCH (all, parallel) → PREPARE (all) → MIGRATE (leader only)
          → ACTIVATE (all, barrier) → RESTART procs → HEALTHCHECK (all)
          → on any failure: ROLLBACK (all) + alert
```

Release layout (native):
```
/srv/falak/sites/<site>/
├── releases/<release-ulid>/
├── shared/          (.env, storage/, custom shared paths)
└── current -> releases/<release-ulid>
```

Deploy script macros: `$FALAK_FETCH`, `$FALAK_ACTIVATE`, `$FALAK_RESTART_PROCS`; variables `FALAK_*` (commit, author, branch, release dir, site root, php binary, deployment id, trigger).

---

## 6. Observability

```
app ─(falak/apm-laravel | @othmanhaba/apm-node)─► unix:/run/falak/otlp.sock ─┐
host metrics / logs / container logs ──────────────────────────────────┤ falak-agent (batch, retry, disk buffer)
                                                                        └─► OTLP/HTTP ─► Loki · Tempo · VictoriaMetrics|Mimir ─► Grafana
                                                     exceptions/breaches ─► control plane Insights
```

- Metrics backend is an adapter behind `Telemetry\Contracts\MetricsBackend` (`victoriametrics`, `mimir`). Default VictoriaMetrics.
- Grafana provisioned automatically: datasources, folders per org, dashboards (Server, Site/Laravel, Node app, Deployments, Containers, Queues), alert rules.
- Every deployment → Grafana annotation + `deployment.id` resource attribute on telemetry.

### APM parity with Nightwatch (`packages/apm-laravel`)
Requests (with timeline) · queries · jobs & attempts · outgoing HTTP · mail · notifications · cache · commands · scheduled tasks · exceptions (+ user) · logs. Non-blocking socket writes, sampling, PII redaction, per-event-type toggles.

---

## 7. Tech stack

| Concern | Choice |
|---|---|
| Control plane | Laravel 12, PHP 8.4, Postgres 17, Redis/Valkey, Horizon, Reverb |
| UI | Inertia 2 + React 19 + TypeScript (strict) + Tailwind 4 + shadcn/ui, ⌘K palette |
| Agent/CLI/Builder | Go 1.25, zero CGO, single static binaries |
| Edge | Caddy 2 / FrankenPHP (admin API, automatic ACME) |
| Builds | Railpack, BuildKit, distribution registry |
| Observability | OpenTelemetry, Loki, Tempo, Grafana, VictoriaMetrics \| Mimir |
| Tests | Pest (unit, feature, arch), Go `testing`, Vitest, E2E via `sim/` |
