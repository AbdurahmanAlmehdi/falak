# Integration notes

Cross-team decisions and open wiring, collected while modules were built in parallel.
All three build waves are merged; the sections below record the resulting contracts, fixes and limits.

## Identifiers
- The control plane stores ULIDs **lowercase** (Laravel default). Agent schemas require **uppercase** Crockford ULIDs.
  **Rule:** convert to uppercase only at the agent boundary (payloads, `FALAK_*` env injected into releases);
  normalise to lowercase on ingest (Fleet, Insights already do). Telemetry query filters use the uppercase form
  because agents label signals with what they were given.
  **Audited (wave 3, outside Builds/Deployments):** `telemetry.configure` ids, `FALAK_SITE_ID`/`FALAK_SERVER_ID` from
  `SiteDirectory::deployVariables()` and site commands (Deployments-supplied `FALAK_*_ID` context values are upper-cased
  there too), Processes program/cron env — all upper-case. Ingest: Insights lower-cases `site_id`; Processes'
  `ScheduleDirectory` / `ProcessControl` accept either case. Fleet command and agent ids are minted upper-case
  (`Str::ulid()`) and stored that way, so envelopes/events/heartbeats round-trip unchanged.
  Deliberate lower-case labels (not ids to the agent, schema-mandated or opaque): Edge `route_id`
  (`^[a-z0-9-]+$`), firewall rule ids, Terminal `session_id` (never echoed back). Covered by `tests/Feature/WiringTest.php`.

## Agent ↔ control plane
- `deploy.fetch|prepare|activate|rollback` accept optional `context` (`deployment_id`, `site_id`, …). Deployments
  **must** send it, or deployment lifecycle logs (Grafana "deployment failed" alert, Deployments dashboard) lose ids.
- `deploy.container.swap` rewrites Caddy upstreams on the host and returns `upstream` in its result. Deployments must
  call `Edge\Contracts\EdgeRoutes::recordUpstream(...)` with it, or the next `edge.caddy.apply` reverts the swap.
- `db.backup` uploads only to a local path or presigned URL (Databases presigns; no storage creds on servers).
- Terminal output events carry base64 PTY bytes in `data`.
- Cron heartbeats arrive at `/agent/v1/insights` as `kind: cron_heartbeat` (schema: `cron.apply` `$defs.heartbeat`).
- Agent restart (`KillMode=mixed`) restarts supervised programs.
- `telemetry.configure` is sent on provisioning completion (or ~30s after enroll), not synchronously at enroll.

## Public API expected by the Go tools
`agent/internal/cli/api/endpoints.go` and `agent/internal/builder/endpoints.go` define the paths the `falak` CLI and
`falak-builder` call. Existing: `/api/v1/me`, `/api/v1/servers`. Wave 3 implements the rest to match (or updates the
Go endpoint files in the same change): sites, deployments (+ output `?after=seq`), rollback, releases, env
(`{content}`), logs (`meta.cursor`), `GET /api/internal/builds/next` (200 job | 204), `POST /api/internal/builds/{id}/events` (NDJSON).
✅ Implemented; see `docs/API.md`. Additions on the Go side: `HTTPSink.OnGone` — a `410` from the events endpoint
means the build was cancelled and `falak-builder` aborts it.

## Builds / Deployments (wave 3)
- `Builds\Contracts\BuildService` (request / find / status / artifactFor / imageFor / cancel / output); events
  `BuildSucceeded`, `BuildFailed` (Alertable), `BuildCancelled`, `BuildOutputReceived`, `BuildUpdated`.
- Deployments events: `DeploymentStarted`, `DeploymentSucceeded`, `DeploymentFailed` (Alertable),
  `DeploymentRolledBack` (Alertable), `ReleaseActivated`. The release directory / `FALAK_RELEASE_ID` is the release
  ULID upper-cased; `.env` of every release gets `FALAK_SITE_ID`, `FALAK_SERVER_ID`, `FALAK_DEPLOYMENT_ID`, `FALAK_RELEASE_ID`.
- Sites gained `SiteResourceExtension` (tagged; Deployments adds `strategy` + `current_release` to the site API)
  and `SiteDeploySettings` (push-to-deploy toggle from the Deploy settings tab), plus `GET|PUT /api/v1/sites/{site}/env`.
- Restart phase uses `ProcessControl::restartForSite()`; a restart step completes when all returned commands finish.
- Not supported yet (deployments fail fast with a clear error): **on-server** builds. (Compose: see below.)
- Registry images are not garbage-collected by Falak (run the registry's GC); artifacts are pruned per site.

## Bindings to wire once providers exist
- ✅ `Insights\Contracts\SiteNameResolver` and `Telemetry\Contracts\ServerSites` are bound by Sites
  (`EloquentSiteNameResolver`, `EloquentServerSites`); Telemetry re-sends `telemetry.configure` on
  `SiteCreated` / `SiteTargetsChanged` / `SiteDeleted`.
- ✅ Alertable events: Databases `BackupFailed` (+ `BackupSucceeded` recovery), `RestoreFinished`; Fleet `AgentRevoked`;
  Network `FirewallApplyFailed` (new, + `FirewallApplied` recovery); Edge `CertificateInstallFailed` (new, +
  `CertificateIssued` recovery); Processes `ProgramCrashLooping` / `ProgramRecovered`. Types are registered with
  `AlertTypes`. Deployments `DeploymentFailed` / `DeploymentRolledBack` and Builds `BuildFailed` implement `Alertable`
  (types `deployments.failed`, `deployments.rolled_back`, `builds.failed`).
- ✅ Servers detail page links to Telemetry server metrics and logs (for `telemetry.view`).
- ✅ Flash messages are shared as `flash` (`success`, `error`, `warning`, `status`) and rendered as toasts by the app
  layouts (`status` values that are machine codes like `verification-link-sent` are left to their pages).

## From Sites / Edge / SourceControl
- ✅ Sites' Laravel toggles (scheduler, Horizon, Octane) are converged by Processes into `proc.apply` / `cron.apply`.

## Processes (wave 3)
- Full desired `proc.apply` + `cron.apply` per server, compiled from every **ready** site target on it that has a
  **live release** there (container runtimes excluded; see *Deploy gaps* below) and dispatched by a debounced unique
  job; identical pending/applied state is never re-sent.
  Triggers: Processes changes, `SiteCreated/Updated/Deleted/TargetsChanged` and the new `Sites\Events\SiteTargetReady`.
- Names: `<slug>.horizon|octane|schedule`, `<slug>.worker-<id8>`, `<slug>.daemon-<id8>`, `<slug>.cron-<id8>` (unique per server).
- **Deployments:** call `Processes\Contracts\ProcessControl::restartForSite($siteId, $serverId)` after activation
  (`$FALAK_RESTART_PROCS`). It converges the server first (the new release's env changes every program of the site,
  so that `proc.apply` restarts them — and starts them on the first deploy); running programs it left unchanged get
  `horizon:terminate` (Horizon, system.exec) or `proc.restart`; emits `ProcessesRestarted`.
- Heartbeats: cron jobs carry `site` (slug); the agent maps it to the upper-case site id from `telemetry.configure`.
  Insights consumes `Processes\Contracts\ScheduleDirectory` (site attribution, removed jobs are no longer expected)
  and `Processes\Events\SchedulesApplied` (monitors are seeded, so a job that never runs is detected).
- Crash loops: `proc.status` every `processes.status_poll_minutes` (default 5) on servers with programs; `fatal`, or
  `backoff` with ≥ `crash_loop_restarts` restarts, raises `ProgramCrashLooping`.
- `deploy.container.swap`: use `EdgeRoutes::routeId(siteId)` as `edge_route_id`, then `EdgeRoutes::recordUpstream(...)` with the result.
- Builders get clone URL + credentials from `SourceControlGateway`; pushes arrive as `SourceControl\Events\PushReceived`.
- `edge.caddy.apply` gained optional `basic_auth[].path` and `tls.dns` (Cloudflare DNS-01) — needs a Caddy/FrankenPHP build with the Cloudflare DNS module on servers.
- Site pages are extensible via `registerSiteTabs` (Deployments, Processes add tabs).

## Projects (UI redesign backend)
- Module `Projects` (after Databases in `Kernel\Modules::ALL`): `projects_projects`, `projects_environments`,
  `projects_services` (`kind` site|database, `ref_id` unique per kind, `name` unique per environment, `x`/`y`).
  Contracts `ProjectDirectory` (lookups, `projectOf`, `servicesIn`, `serviceUrl(kind, refId, tab)` →
  `/projects/{p}/{env}/service/{kind}/{id}/{tab}` for the upcoming legacy redirects) and `VariableReferences`;
  events `ProjectCreated`, `EnvironmentCreated`, `ServiceLinked`, `ServiceUnlinked`.
- Data migration `backfill_default_projects` (= `php artisan projects:backfill [--organization=]`, idempotent) gives
  every organization a `Default` project / `production` environment and places every site and database in it.
  `OrganizationCreated` creates it for new organizations.
- Placement: `Sites\Events\SiteCreated` carries an optional `SitePlacement` (project, environment, x, y, service
  name) from `SiteFactory::create` / `POST /sites` (`project_id`, `environment_id`); Projects places the site
  synchronously. Databases are placed on `DatabaseCreated` (i.e. once `db.create` converged) unless the canvas already
  placed them via `Databases\Contracts\DatabaseProvisioner`. `SiteDeleted` / `DatabaseDeleted` unlink.
- New contracts used by Projects: `Sites\Contracts\SiteFactory` (create / duplicate), `Databases\Contracts\
  DatabaseConnections` (reference variables; the only place passwords leave Databases) and `DatabaseProvisioner`,
  `Deployments\Contracts\DeploymentDirectory::currentForSites`, `Identity\Contracts\OrganizationDirectory::all`,
  `DatabaseDirectory::findMany/forOrganization`.
- `Deployments\StepPayloads` resolves references for `deploy.prepare` env files, container env and deploy-script env
  (unresolved → the deployment fails with the reason); `Builds\BuildConfiguration` resolves public build variables.
- Shared Inertia props: `Kernel\Support\SharedProps` registry merged by `HandleInertiaRequests`; Projects registers
  `falak` (UI_DESIGN §9). Pages `Projects/Index`, `Projects/Canvas`, `Projects/Settings` are rendered with their props;
  the TSX pages come with the UI wave.
- Limits: Redis / Valkey became Databases engines in v0.7.0 (see *Redis and Valkey* below); duplicated environments
  get sites without servers (pick servers per service); canvas status has no "crashed" state yet (no process
  health contract).

## Docker Compose sites (roadmap step 4, lane A — docs/COMPOSE_TEMPLATES.md §1)
- Contracts (Sites): `SiteFactory::create` accepts `compose_source|compose_content|compose_file|public_services|variables|
  template` (§5); `ComposeInspector::parse()` → `ComposeSummary` (services, ports, volumes, policy violations, errors,
  warnings; never throws); `ComposeSites` (inline versions, `render()` for a release, `pinDigests()`, service state for
  the Services tab / canvas). `SiteData::$compose` (`ComposeConfig`: source, file, public services with host ports and
  test domains, template, inline version).
- Rendering: `build:` services → built image (digest), `ports` removed everywhere and `127.0.0.1:<host port>:<port>` on
  public services, labels `falak.site|release|service`; `${VAR}` stays Compose-native — the release `.env` (site variables
  with references resolved + `FALAK_SITE_ID|SERVER_ID|DEPLOYMENT_ID|RELEASE_ID`) is written next to `compose.yaml` and
  also passed as the compose process env. Policy toggle: org setting `sites_organization_settings.allow_privileged_compose`
  (Settings → Compose, permission `sites.compose.policy`, admins).
- Builds: compose sites build in docker mode with a `compose` job spec; falak-builder builds every `build:` service to
  `<registry>/<ns>/<slug>/<service>:<build id>` and returns the unmodified compose file + pinned images
  (`BuildService::composeFor`). Repo compose sites therefore need a docker-capable builder even without `build:` services.
- Deployments (`compose` strategy; rolling/canary batch the activations): `docker.compose.pull` (FETCH, writes
  `releases/<ID>/compose.yaml` + `.env`) → leader `system.exec` running `docker compose run --rm <svc> <argv>` for every
  `falak.deploy.leader_command` label (settles instantly when there is none) → `docker.compose.up --wait` → health check of
  every public service through the edge (primary: configured path/status; others: `/` and `< 500`). The rendered files are
  stored encrypted on the release (`deployments_releases.compose`) and pulled images are re-pinned to the digests the
  server resolved; rollback (failure or manual) = `up --wait` with that release's files. Project name = site slug, so
  named volumes survive releases. `deploy.prune` keeps N release directories like native sites.
- Edge: the primary public service is the site route (site domains + `<slug>` test domain → app port = its host port);
  each other public service gets `<route>-svc-<service>[-test]` routes for its custom domain / `<service>-<slug>` test domain.
- Telemetry: compose containers log with `service.name=<slug>` and `falak.compose.service` (Loki structured metadata
  `falak_compose_service`, filter `compose_service` on the logs endpoints); `docker stats` → `falak.container.cpu.utilization`,
  `falak.container.memory.usage|limit`, `falak.container.network.io` per site resource.
- Limits: only `compose.yaml` + `.env` reach the servers — repository files referenced by relative bind mounts or extra
  `env_file`s are not shipped; `include`/`extends` are rejected; a failed first deploy has nothing to roll back to
  (containers stay as `up` left them); falak-builder with registry credentials looks up the buildx builder in its
  temporary DOCKER_CONFIG (the sim registry has no auth).

## Deploy gaps (roadmap step 6)
- **Deploying while servers prepare.** `DeploymentQueue` claims a queued deployment as `waiting` (new status; holds the
  site's queue like a running one) when any site target is `pending`/`provisioning`, with `waiting_reason` /
  `waiting_since`. It is re-evaluated on `Sites\Events\SiteTargetReady`, the new `SiteTargetFailed` (dispatched by
  `TargetProvisioner::fail`), `SiteTargetsChanged` (queued listener `ResumeWaitingDeployments`) and by the minute
  reconciler. Decisions: it waits for **every** preparing server (rather than deploying the ready ones and leaving late
  servers without a release); servers whose preparation failed are skipped with a warning (in the output) while
  another server is ready; a failed **leader** or no preparable server fails it with the reason (`DeploymentFailed`,
  so it alerts); `deployments.waiting.timeout_minutes` (`FALAK_DEPLOY_WAIT_TIMEOUT_MINUTES`, default 30) fails it
  too. Non-rollback triggers (panel, API/CLI, push, deploy hook, `DeploymentTrigger`) coalesce into the site's
  waiting deployment — latest branch/commit/trigger wins, same number; rollbacks queue behind it. Cancel works on it
  (panel, and the new `POST /api/v1/deployments/{id}/cancel`). `SiteTargetData` gained `statusMessage`.
  Canvas: a waiting deployment reads *Waiting for servers* (tone `queued`); `DeploymentDirectory::currentForSites`
  returns it as the current deployment.
- **Release ids + site variables in the process env.** Deployments records the release each server runs
  (`deployments_server_releases`, written on activate/switch/swap/compose up/revert; backfilled from active
  releases) and the resolved variables each release's `.env` was written with (`deployments_releases.environment`,
  encrypted; set at the first `deploy.prepare`). Contract `Deployments\Contracts\LiveReleases::onServer()`. Processes
  builds every program and cron job env as: release variables → program env (worker/daemon env; `<slug>.app`'s
  `PORT`/`HOST`/`PATH`, `NODE_ENV` defaulting to `production` unless the site sets it) → `FALAK_SITE`,
  `FALAK_SITE_ID`, `FALAK_SERVER_ID`, `FALAK_RELEASE_ID`, `FALAK_DEPLOYMENT_ID` (upper-case; the deployment that built
  the release, like its `.env`, also after a rollback). Env edits still take effect on the next deploy (the release
  snapshot, not the latest version). The agent's supervisor already restarts a program whose definition (env
  included) changed; Deployments now settles restart steps on `proc.apply` outcomes and converges its servers when a
  deployment finishes (static sites have no restart step). PHP sites get the same env; Laravel's dotenv doesn't
  override existing env vars and the values are identical.
- **No programs before the first deploy.** Processes skips a site on a server until it has a live release there
  (programs, Horizon, Octane, scheduler, cron), so never-deployed sites no longer crash-loop or alert; they start on
  the first activation (the restart step's `proc.apply`). Consequence: a site's daemons/cron only run once it has
  been deployed, also on a server newly added to a deployed site (until the next deploy).
- **CLI:** `falak deploy` prints the waiting reason; `api.Deployment.WaitingReason`.
- Still open from *Known limits* (not small or not safe to change here): a failed **first** compose deploy leaves containers as `up` left them (stopping them would also discard their logs
  for debugging); on-server builds; registry GC; the compose file-shipping limits.

## Octane routing (roadmap step 5)
- **Port + server (Sites).** `LaravelSettings` gained `octaneServer` (`Sites\Contracts\OctaneServer`: `frankenphp` — FrankenPHP
  runtime only, its default — `swoole` (PHP-FPM default) or `roadrunner`) and `octanePort`, persisted in the site's
  `laravel` JSON when Octane is enabled (`Sites\Application\OctanePorts`): first candidate `8000 + crc32(site id) % 1000`
  (`sites.octane_port_base|span`), then the next free port. Free = not another site's app port, compose host port, Octane
  port or Octane aux port (port + 10000: FrankenPHP's `--admin-port` — the edge owns :2019 — or RoadRunner's `--rpc-port`)
  on **any** server of the site, and its own aux port free too. Re-checked when servers are added (`SetSiteTargets`),
  on duplication, and by the backfill migration (legacy `app_port`/crc32 ports kept when free). The port is never taken
  from a request; it survives switching Octane off. Edge and Processes both read it from `SiteData::$laravel`.
- **Processes** supervises `<slug>.octane` = `php artisan octane:start --server=<s> --host=127.0.0.1 --port=<p>`
  (+ `--admin-port`/`--rpc-port`) and owns the routing state `processes_octane_routes` (per site × server:
  `starting | listening | failed | draining`), exposed as `Processes\Contracts\OctaneRouting::listeningPort()` and
  `Processes\Events\OctaneRoutingChanged`. A probe (`system.exec`, waits ≤ `processes.octane_probe_seconds` for any HTTP
  answer on 127.0.0.1:<port>) runs after a `proc.apply` / `proc.restart` that involves the program and on every status
  poll for unverified routes; only a verified route is proxied. Programs only exist once a release is live (deploy
  gaps), so never-deployed sites are never probed.
- **Edge** renders a verified Octane site (direct and LB-backend roles, domains + test domain) as `kind: reverse_proxy`
  with `root` = document root and `try_duration_s: 30`: the agent serves existing files under `root` with `file_server`
  (never `*.php`, dotfiles or directories) and proxies the rest, retrying the upstream for 30s. TLS, redirects, headers,
  basic auth, IP rules and body limits are unchanged. Anything else (starting, failed, placeholder release, port moved)
  keeps the FrankenPHP `php_server` / PHP-FPM route. `edge_server_states.octane_sites|applied_octane_sites` record which
  sites the dispatched / applied config proxies (`EdgeRoutes::proxiesToOctane()`).
- **Ordering.** Enable: program converged → probe answers → `OctaneRoutingChanged` → edge switches to the proxy. Disable:
  a verified route turns `draining` (edge serves directly again; the program is kept in `proc.apply`) until neither the
  applied nor a pending edge config proxies to it (`StopDrainedOctane` on `EdgeApplied`, the status poll, or a 10 min
  grace), then it is dropped and the program stops. Port/server change: back to `starting` (served directly until the
  new process answers).
- **Deploys restart, UI restarts reload.** Octane resolves `current` once at start (PHP resolves `__DIR__`/`base_path()`
  through the symlink), so `octane:reload` after a release swap would re-boot the workers on the **old** release. After an
  activation the program is restarted — its definition carries the new `FALAK_RELEASE_ID`, so the restart step's
  `proc.apply` does it — while the edge holds requests (`try_duration`) until the new server listens: no failed requests,
  a short latency spike. `ProcessControl::restartForSite(..., newRelease: false)` (Restart processes in the UI, same
  release) sends `php artisan octane:reload --server=<s>` to a verified Octane instead (graceful worker reload behind the
  open port); a failed reload falls back to `proc.restart`.
- API: `PUT /api/v1/sites/{site}/laravel` (docs/API.md). UI: Settings → Laravel (server select, port) + a Processes
  block with per-server routing state; canvas cards / panel header carry an **Octane** badge (`CanvasService.badges`).
- Sim E2E stage `octane` (fixture `laravel-demo` now requires `laravel/octane`, ships `public/frankenphp-worker.php` and a
  `/octane` route whose per-worker counter proves worker mode): placeholder kept before the first deploy, worker mode
  through the edge, static passthrough, zero failed requests during a redeploy and while switching Octane off.

## Found by the sim E2E (all fixed, with regression tests)
Real provisioning and deploys on Ubuntu 24.04 (`sim/e2e-deploy.sh`) surfaced these; each is fixed and covered:
1. A failed live broadcast (Reverb down) aborted provisioning/deployments → all `ShouldBroadcastNow` events are
   `ShouldRescue`; an architecture test enforces it.
2. `sshd -t` failed on fresh 24.04 (`/run/sshd` missing) and on hosts without host keys → created / `ssh-keygen -A`.
3. `systemctl reload ssh` failed when sshd runs on demand; and after an openssh upgrade **nothing listened on :22** →
   the SSH step now converges "SSH reachable" every run (socket vs service aware).
4. hostname / swap steps fail inside containers → skipped only when `systemd-detect-virt --container`.
5. Transient download failures (DNS, truncated bodies, 5xx) failed provisioning → retried with backoff.
6. FrankenPHP rejects a config whose PHP root doesn't exist, so a never-deployed site took down **every route on the
   server** → `current` points at a 503 placeholder release until the first deploy.
7. FrankenPHP resolves the `current` symlink at config load, so a release swap kept serving the **old** release →
   activation force-reloads the running config.
8. Health checks verified internal-CA certificates → only publicly trusted (ACME/DNS-01) certificates are verified.
9. PHP under FrankenPHP (edge user) couldn't read the site `.env` or write `storage/` → edge joins site groups
   (`SupplementaryGroups=`), writable dirs are recursively group-writable (setgid), confined to the site root.
10. Node/Bun/Deno sites were never started and servers had no Bun/Deno → `runtime.bun|deno.install` on target
    preparation; Processes supervises `<slug>.app` (`npm|bun run start` / `deno task start`) on `app_port`.
Also added for automation: `POST /api/v1/sites`, `POST|GET /api/v1/source-control/connections`, `falak:admin`.

## Sim speed (feat/sim-speed)
Measuring the E2E per stage (`sim/e2e-deploy.sh` now prints durations and a summary) surfaced two product bugs and
one broken E2E check. The rest is sim-side caching; see `sim/README.md` → *Caches and speed*.
- **Agent wake-up raced the transaction (product bug, fixed).** With `FALAK_AGENT_WAKE_DRIVER=redis`, `QueueCommand`
  RPUSHed the wake-up token while its command row was still uncommitted. The deployment orchestrator queues every
  step inside its `lockForUpdate` transaction, so the woken long-poll re-checked, saw nothing, consumed the token
  and slept out the rest of its 30 s window: **~29 s of dead time per deployment step**, 1.5–2 min per deploy.
  `QueueCommand` now notifies through the connection's `afterCommit` (immediate outside a transaction, dropped on
  rollback). Covered in `Fleet/tests/Feature/CommandChannelTest.php`. The database driver (the default) polls
  every 500 ms and was not affected.
- **Runtime download mirrors (product feature).** `FALAK_FRANKENPHP_MIRROR`, `FALAK_NODE_MIRROR`, `FALAK_BUN_MIRROR`,
  `FALAK_DENO_MIRROR` (unset by default) replace the GitHub / nodejs.org release bases in `provision.apply`
  (`runtimes.frankenphp|node.mirror`, new optional schema fields), `runtime.frankenphp.configure` (`mirror`, new)
  and `runtime.bun|deno.install` (`mirror`, which existed but was never set). Documented in `docs/INSTALL.md`.
  The sim points them at its caching proxy.
- **Octane E2E check.** The demo app's `/octane` counted requests in a `static` inside the route closure, but the
  Laravel preset runs `artisan optimize`, and cached closure routes are unserialized per request, so the counter
  was always 1 even under Octane. It now counts in a class static (`App\Support\OctaneProbe`), and the stage
  decides the mode by the app's `LARAVEL_OCTANE` flag.
- **Octane restart could drop a request (product bug, fixed).** A deploy restarts Octane while the edge holds requests
  (`try_duration` 30 s). The old FrankenPHP closes its port at SIGTERM, but its graceful shutdown is unbounded (Caddy's
  default grace period) and intermittently hangs until SIGKILL. The stop timeout (30 s, the supervisor default) equalled
  the edge's window, so the first held request ran out of retries just before the SIGKILL: one 502 in about 1 of 7
  redeploys under load. Octane programs now stop with `processes.octane_stop_timeout` (10 s); `OctaneTest` pins it to
  at most half the edge's window. (A running program stops with the spec it was started with, so the first redeploy
  after upgrading still uses 30 s.)

## GitHub App (feat/github-app)
"Connect GitHub" registers a GitHub App through the manifest flow instead of asking for a personal access token.
- **Scoping decision: registered apps belong to one Falak organization, one per GitHub owner** (v0.26; before that
  `organization_id` was unique), created by its owners/admins (`source_control.manage`). A private app can only be
  installed on the account that owns it, so reaching a personal account and a GitHub organization takes two apps
  (Dokploy does the same). Same owner twice → 409 (`manifest`, org owners) or refused after conversion (personal
  owners). `connect/github-app?app=<id>` picks the app (none → env app, else the oldest); the install state carries the
  app id, so `setup` connects the installation to that app; `DELETE github-app/{app}`. Not instance-wide, because Falak has open
  registration and multiple organizations: an instance-wide app would hand its private key and every installation's
  pushes to whichever tenant created it, and GitHub only lets a private (`public: false`) app be installed on its
  owning account anyway. An operator who wants one shared app sets `GITHUB_APP_*` (instance-wide, overrides registered
  apps for new installations; connections remember their app in `github_app_id` = `env` | app id).
- Flow: `POST /source-control/github-app/manifest` (JSON: form action + manifest, state in the session bound to user +
  organization + a pre-generated app id that the webhook URL embeds) → the browser POSTs the manifest to
  `github.com/[organizations/<org>/]settings/apps/new?state=` → `GET /source-control/github-app/manifest/callback`
  converts the code (`POST /app-manifests/{code}/conversions`), stores pem/webhook secret/client secret encrypted, and
  redirects to `github.com/apps/<slug>/installations/new?state=` → the existing setup URL creates the connection after
  verifying the installation with the app's JWT. `setup_on_update` redirects (no state) only refresh a connection the
  organization already has; a new installation needs a state. An installation already connected to another Falak
  organization is refused; reinstalling on the same account revives the organization's `disconnected` connection.
- Manifest: permissions `contents: read`, `metadata: read`; events `push` (`installation` and
  `installation_repositories` are always delivered to apps). No `statuses`/`checks` (Falak reports no commit status)
  and no `pull_requests` (no previews yet): add them to `AppManifest::PERMISSIONS` when those features land.
- One webhook per app: `POST /api/webhooks/source-control/github-app/{app|env}`, `X-Hub-Signature-256` with the app's
  secret, throttled per app (`FALAK_GITHUB_APP_WEBHOOK_RATE_LIMIT`, 600/min). `push` → push log + `PushReceived` for
  repositories a push-to-deploy site uses (the `source_control_webhooks` row `ensureWebhook()` keeps, with no provider
  hook); `installation` deleted/suspend/unsuspend → connection `status` disconnected/suspended/active (tokens refused
  while not active); `installation_repositories` → repository list re-fetched (queued `RefreshInstallationRepositories`).
- App connections: `SourceControlLinker` skips deploy keys (the app has no `administration` permission);
  `checkoutCredentials()` always returns HTTPS + `x-access-token` + a fresh installation token (50 min cache, per app
  and installation). `ConnectionData` gained `status` and `isGitHubApp()`. `GET /installation/repositories` is
  paginated and cached 10 min per connection so the pickers can search as you type.
- Disconnect uninstalls the app from the account (`DELETE /app/installations/{id}`, best effort); *Delete app*
  does that for every installation and deletes the stored credentials (the registration is deleted on GitHub).
- Not verified against real GitHub (faked in Pest and in `tests/Browser/github-app.spec.ts`): the real manifest
  confirmation page, GitHub's exact redirect parameters on `setup_on_update`, and webhook delivery over the internet.

## Gaps found on AWS (fix/gaps)
A real-server test on AWS surfaced these; each is fixed and covered by tests.

### Site web logs reach Loki
- **Problem.** Laravel sites defaulted to `LOG_CHANNEL=stderr`. PHP under FrankenPHP runs inside the edge process and
  PHP-FPM pools share the FPM master's stderr, so web request logs landed in the falak-edge journal with no site
  attribution; only supervised programs (workers, scheduler) reached Loki.
- **App logs = files.** New Laravel sites get `LOG_CHANNEL=daily`; the data migration
  `switch_laravel_sites_to_file_logs` moves Laravel sites on `stderr` (Falak's old default) to `daily` (PHP runtimes
  only; a new env version, effective on the next deploy; other values untouched). `Sites\Infrastructure\EloquentServerSites` sends
  each PHP site's shared log directory as a `telemetry.configure` log source (`<root>/shared/storage/logs/*.log`, or
  Symfony's `var/log`), `kind: app`, `multiline: laravel` for Laravel/Statamic. The agent tailer merges continuation
  lines into the record they belong to (a record starts with `[YYYY-MM-DD HH:MM:SS`; flushed after one quiet poll,
  capped at 256 KiB; the saved offset excludes the pending record so a restart re-reads it).
- **Shared log files.** PHP under FrankenPHP writes as the edge user, workers/cron as the site user; whoever creates
  the day's file first would lock the other out (Monolog then throws). `deploy.prepare` writable dirs get a default
  POSIX ACL `user::rwx group::rwx other::---` (set through the `system.posix_acl_default` xattr, skipped where the
  filesystem has no ACLs), so new files are group-writable whatever the creator's umask, and not world-readable.
- **Access logs.** `edge.caddy.apply` `sites[].access_log` (= site slug; Edge sets it on direct and load-balancer
  routes, not on backends behind an LB, which only see the LB) makes Caddy write that route's requests as JSON to
  `/var/log/falak/access/<slug>.log` (10 MB × 3 rotation; excluded from the edge journal). The agent always tails that
  directory, attributes by file name, and flattens Caddy's entry into OTel HTTP attributes (headers other than
  `User-Agent` are dropped). 5xx → ERROR, 4xx → WARN.
- **Labels.** Every site record carries `service_name=<slug>`, `falak_server_id`, `falak_site_id` (when the agent knows
  the site) and the new index label **`falak_log_kind`** (`app` | `access`; resource attribute `falak.log.kind`,
  records attributed to a site default to `app`). Deployment/release ids: Telemetry now fills `sites[].deployment_id|
  release_id` from `Deployments\Contracts\LiveReleases` and re-sends `telemetry.configure` on `ReleaseActivated`,
  so records carry `falak_deployment_id` / `falak_release_id` structured metadata.
- **Contract for the UI (Logs / Network Logs tabs).** `Telemetry\Contracts\AccessLogs::forSite($organizationId,
  $siteId, $from, $to, $filters, $limit)` → `list<Data\AccessLogEntry>` (method, path, query, status, durationMs,
  bytes, requestBytes, clientIp, userAgent, host, serverId, deploymentId, releaseId, `toArray()`), newest first;
  filters `server_id`, `deployment_id`, `release_id`, `method`, `status` (code or `5xx`), `path`, `client_ip`. Access logs are
  selected by slug (`service_name`), because an LB's agent may not know the site id. App logs: the existing
  `LogQueryBuilder` filters gained `kind` (`app` = `falak_log_kind!="access"`, so records of agents that predate the
  label still match). HTTP: `GET /api/v1/sites/{site}/access-logs`, `…/logs?kind=` (docs/API.md).
- **UI.** The deployment panel's *Network Logs* tab (Deployments `panel/network-logs.tsx`) lists the requests served by
  the deployment's release (`release_id` filter, since the deployment started, status filter, 10 s refresh while live)
  from `GET /telemetry/sites/{site}/access-logs/data`. The service panel's *Logs* tab now asks for `kind=app` (edge
  requests live in Network Logs).
- **Agent compatibility.** Agents reject unknown payload fields, so new optional fields must not reach old agents.
  Agents now report `facts.features` (`edge.access_log`, `telemetry.log_kind`, `system.upgrade_agent.v2`);
  `Fleet\Application\PayloadCompatibility` strips fields of features an agent does not list when a command is queued
  (`AgentInfo::supports()` for callers that need to know). Heartbeats that report a new `agent_version` dispatch
  `Fleet\Events\AgentVersionChanged`; Edge force-applies and Telemetry reconfigures on it, so an upgraded agent
  gets the fields even though the compiled payloads are unchanged. **Rule for new protocol fields:** add a feature
  to `agent/internal/version.Features` and the field path to `PayloadCompatibility::FIELDS`.
- Smaller: the agent refreshes OTLP `host.name` whenever facts are re-collected (every 5 min), so a renamed host (EC2)
  no longer keeps its old name until the agent restarts.
- Verified by the sim E2E (FrankenPHP 1.9.1): access records and merged Laravel error records reach Loki with
  `falak_log_kind`, and `/api/v1/sites/{id}/access-logs` returns them. Not verified: the PHP-FPM + Caddy edge path,
  and Caddy < 2.9 (`logger_names` array form). The migration cannot tell a deliberate `stderr` from the old default and switches both (a user can set it
  back; web logs of such a site then only reach the edge journal).

### Fleet agent upgrades
- **Versions.** Agents report `facts.agent_version`, `features` and `agent_sha256` (checksum of the running binary).
  The shipped build per arch is `Fleet\Application\ShippedAgent` (sha256 from `FALAK_AGENT_SHA256_*` or the served file;
  version from the `falak-agent-linux-<arch>.version` sidecar that `make agent` now writes and the image copies, else
  `FALAK_AGENT_VERSION` / `FALAK_VERSION`). *Outdated* = checksums differ (dev/CI builds share version strings), except
  that a newer release than the shipped one never is; without a checksum, semver or string comparison.
- **Contract** `Fleet\Contracts\AgentUpgrades`: `versionsFor()` (→ `AgentVersionInfo`: version, availableVersion,
  updateAvailable, latest `AgentUpgradeData`), `upgrade()`, `upgradeOrganization()`, `outdatedCount()`; exception
  `AgentUpgradeUnavailable`. Table `fleet_agent_upgrades`; `AgentUpgradeRollout` sends `system.upgrade_agent` (panel
  URL + sha256, idempotency key per upgrade), marks it installed on `CommandFinished`, succeeded when the agent's facts
  show the shipped checksum (or version), failed on `CommandFailed` or after `fleet.agent.upgrade.timeout_seconds`
  (SweepFleet). Rollouts share a `rollout_id`, run `batch_size` at a time, and cancel their queued rest after a
  failure. Events `AgentUpgradeSucceeded` (recovery) / `AgentUpgradeFailed` (Alertable `fleet.agent_upgrade_failed`).
- **Agent.** `system.upgrade_agent` now also pre-flights the download (`<bin>.new version` must run, so a
  wrong-arch or truncated build never replaces a working agent), replaces the installed `/usr/local/bin/falak-agent`
  (not the resolved executable, so symlinked installs such as the sim work), keeps `.prev` (copy when a hard link is
  impossible), and restarts again when the binary is current but the running process is not. Schema `version` is any
  string now (dev/CI builds).
- **UI/API.** Servers list: *Agent* column (version, *update available*, upgrade state) and *Upgrade all agents*;
  server page: *Upgrade agent* in the Agent section (`fleet.agents.manage`). `POST /api/v1/servers/{server}/agent/upgrade`;
  `GET /api/v1/servers[/{id}]` `agent.*` fields; `php artisan falak:agents [--outdated --count]`; `falak-ctl update`
  prints a hint when agents are outdated.
- Verified by hand in the sim (not part of the E2E, whose agents run the served build): publishing a newer build and
  `POST …/agent/upgrade` downloaded it over HTTPS from the panel, swapped it (`.prev` kept), restarted in ~3 s and
  reported `succeeded` with the new version; the E2E checks the version report and the no-op. Not tried on a real
  fleet or with "Upgrade all agents" across several servers.

### Builder restarts no longer orphan builds
- falak-builder generates a run id per process and sends it on every poll (`run=`), and heartbeats
  (`POST /api/internal/builds/{id}/heartbeat`, every 20 s) while building. Builds record `builder_name`,
  `builder_run_id` and `heartbeat_at` (events count as heartbeats).
- A poll with a new run id fails the builds (assigned or running) that the same builder name claimed under another run
  (`ReapOrphanedBuilds`: "Builder <name> restarted during the build."); `BuildFailed` fails the deployment as before.
  `ExpireBuilds` fails running builds of run-id builders after `builds.heartbeat_timeout_seconds` (90) of silence;
  a `410` heartbeat answer makes the builder abort. Builders without run ids (older falak-builder) keep the old
  behaviour (build timeout + grace). Assigned-but-never-started builds are still re-queued (`assign_timeout_seconds`).
- Two builder processes sharing one token must use different `--name`s (a poll by one would otherwise fail the
  other's build).

### Release files are not world-readable
- `artisan optimize` writes `bootstrap/cache/config.php` (database password, `APP_KEY`) with the deploy user's umask
  (0644), and releases were 0755, so any local user could read it (and `.env` through `shared/` was protected only by
  its own mode). `deploy.fetch` now closes every release directory and `deploy.prepare` the site's `shared/`: mode
  0750 (owner = site user, group = site group) plus a POSIX ACL entry `user:caddy:r-x` for the edge (it serves
  static files, and under FrankenPHP runs PHP; on PHP-FPM servers Caddy is not in the site groups). Other local users
  cannot enter them, whatever the file modes inside. PHP-FPM pools, workers, cron and hooks run as the site user.
  Without ACL support the directories stay 0755 and fetch prints a warning.
- The writable dirs' default ACL (see *Site web logs*) is `user::rwx group::rwx other::r-x` (the closed parents keep
  others out; `other` read keeps public uploads servable by a Caddy edge outside the site group).
- Hooks keep the user's umask (a `umask 027` in hooks would hide generated public assets from a Caddy edge outside
  the site group); the closed release directory is what protects the files.
- falak-builder no longer ships the build's own logs: `storage/logs/*` is excluded from artifacts except
  `storage/logs/.gitignore` (the first deploy used to move the build's `laravel.log` into shared storage).
- Verified in the sim (FrankenPHP): releases/shared are `drwxr-x---` with `user:caddy:r-x`, `nobody` cannot read
  `bootstrap/cache/config.php`, the site still serves, and FrankenPHP (caddy) and the site user share the daily log
  file. Not verified on a PHP-FPM server with a standalone Caddy edge. Releases deployed before this change stay open
  until they are pruned.

### Smaller fixes
- "Firewall applied again" was dispatched on every successful apply. Network now records `failed_at` on the firewall
  state: `FirewallApplyFailed` fires when applies start failing (not on every failing retry) and `FirewallApplied`
  (the recovery) only on the first success after that.
- The agent refreshes OTLP `host.name` with every facts collection (see *Site web logs*).

## Database containers (v0.10.0)
Every managed database is a container of a Falak image (`docs/DB_IMAGES.md`, plan `docs/plans/V0_10_PRODUCTION.md`
§3). Host engines, `InstallDatabaseEngine`, `EngineInventory`, Redis / Valkey host instances (`redis-server@`,
`db.redis.apply` / `db.redis.remove`, the agent's `RedisWatch`), container access to host engines (`db.containers`,
`EnableContainerAccess`) and the firewall's container ports are gone.
- **Model:** `DatabaseInstance` (one container `falak-db-<id>`, its data on a sized volume, a memory limit the config is
  tuned to, a sealed superuser password, a Falak CA certificate). `Database` / `DatabaseUser` live inside it; a Redis /
  Valkey instance has one keyspace and its `default` user.
- **Agent:** `db.instance.create|update|restart|stop|delete|password|secrets|upgrade`, `db.create|drop`,
  `db.user.apply`, `db.backup|restore`, all through `docker exec <ctr> falak-db …`; one command at a time per instance.
  Passwords only as files on the tmpfs (`/run/falak/secrets/falak-db-<id>`), restored after a reboot from the heartbeat's
  `databases[].secrets_missing`. Settings, certificates and limits change in place (a restart); a container is only
  recreated for a new image, ports, network or mounts. Images run pinned by the digests this release ships
  (`modules/Databases/config/db-image-digests.json`); the agent refuses anything else.
- **Network:** apps of the environment reach `falak-db-<id>` on `falak-env-<environment>` (Docker sites join it, compose
  stacks start on it through `compose.falak.yaml`); native sites use `127.0.0.1:<host port>`; other servers a private /
  WireGuard address, published only once someone applies it (a restart) and filtered by the agent's DOCKER-USER chain to
  the consumers' addresses (and a public allowlist). Compose files may not use `falak*` networks, other containers'
  namespaces or `falak.*` labels.
- **Upgrades:** minor = the newer pinned digest, recreated in place. SQL majors copy into a new instance from a
  read-only source, compare row counts per table, then take over its name and port; the old instance stays stopped with
  its volume until someone deletes it (its container goes after 24 h once the new one is healthy).
- **Restores:** PostgreSQL loads into a scratch database swapped in on success, every object owned by the app's user.
- **Redis / Valkey passwords** overlap during a rotation (both valid for `FALAK_DB_PASSWORD_OVERLAP_HOURS`, 24 h).

## Alerts coverage (v0.10.0 step 8)
Plan `docs/plans/V0_10_PRODUCTION.md` §8.
- **Default rule pack** (`Alerting\Application\DefaultRulePack`): one editable rule per alert type group whose types
  reach Warning, matching the group's type prefixes (`databases.*`, `pitr.*`) from Warning up, routed to the
  organization's default channel (the first channel it adds; "Make default" on the channels page), in-app only while it
  has none (the rules page says so). Organizations that had several channels before get no default (the rules page asks
  to choose one); pack rules someone edited are never re-routed. Applied on `OrganizationCreated`, by the migration for existing organizations and
  daily (`alerting:default-rules`), so a group a module registers later (`dr.*`) joins every organization.
  `alerting_rule_packs` records each area and pattern applied: deleted rules and removed patterns stay deleted. Areas
  are keyed by their main type prefix (`area:databases`, `area:dr`).
- **Stateful conditions** (`Alerting\Contracts\AlertConditions`): a periodic check calls `observe($org, $key, $holds, …)`;
  the alert is raised once (optionally after holding `forSeconds`) and resolved when it clears (recovery to the channels
  that got it); `null` means "in the hysteresis band": no change. Conditions nobody observes for a week are dropped
  without a recovery.
- **In-app details:** `AlertData::$detail` (who, from where, raw errors) is shown in the history and notification center
  only; third-party channels get the title and a neutral body. Stored text is scrubbed of URL credentials and signed
  query parameters.
- **Suggested fix:** `AlertTypes::register(…, $fix)` or `AlertData::$action` labels the alert's link ("Grow volume",
  "Fix in baseline", "Review backups", "Inspect certificate", "Update agent"); channels and the history show it.
- **New sources:** `servers.disk_usage` (80 / 90 % per mount, held 5 min, resolved 5 points below), `servers.disk_forecast` (fills within 48 h; least-squares
  over 6 h, rising and R² ≥ 0.6 only), `servers.memory_high` / `cpu_high` / `load_high` (whole window above; resolved
  after 5 min 5 points / 10% below), `servers.reboot_required`, `servers.agent_outdated` (one per organization, after
  1 h) — `Servers\CheckServerHealth`;
  `edge.certificate_expiring` (14 / 7 / 1 days, ACME expiries from the servers serving the domain, wildcards included,
  and uploaded certificates in use); `databases.backup_missed` (the schedule's last two runs missed: `Kernel\MissedRuns`), `databases.storage_unreachable` (two failed probes), `databases.connections_high` (80 % for
  5 min), `pitr.stopped`; `volumes.backup_failed` / `backup_succeeded`, `volumes.backup_missed`, `volumes.almost_full` now
  resolves; `secrets.rotation_due`, `secrets.unusual_reveals` (> 20 reveals by one user in 10 min).
- **Agent:** heartbeats carry `disks` (data filesystems as df sees them) and `databases[].connections` (`falak-db stats`,
  at most once a minute, in the background); facts carry `reboot_required` and `tls_certificates` (the edge's ACME
  certificates under `/var/lib/caddy`).

## Not covered by the E2E yet (unit/feature tested only)
Docker/Compose runtimes and docker builds on a real BuildKit, database backups/restore to real S3, WireGuard private
networks, web terminal, recipes, provider APIs (Hetzner/DO/Vultr/Linode/Lightsail), load balancers, DNS-01 wildcard
certificates, alert delivery to real Slack/Discord/Telegram, the Grafana provisioning API, Deno runtime at runtime.

## Known limits (accepted for now)
- Octane: Swoole needs the `swoole`/`openswoole` extension and RoadRunner the `rr` binary + `spiral/roadrunner-http` in
  the app (Falak installs neither; the probe keeps the site served directly until they work). Octane's FrankenPHP
  server binds `:<port>` on all interfaces (Octane gives no bind option; the nftables default policy drops it) and
  uses the FrankenPHP binary's embedded PHP, not the site's `phpX.Y` CLI. A verified Octane that crashes later is not
  un-routed automatically (502 after the 30s retry window; the crash-loop alert fires) — the next restart/poll re-probes
  only unverified routes.
- LB active health checks send the backend IP as Host, so backends whose routes are domain-only can be marked down; leave health path empty to balance without active checks. Weights are emulated by repeating upstreams.
- SourceControl: no Bitbucket Server; OAuth for only one self-hosted GitLab (others via token).
- Providers: AWS is Lightsail only (no EC2).
- Deleting an org revokes agents but does not destroy provider machines.
- Alerting webhook SSRF guard doesn't resolve DNS (rebinding not blocked).
- Grafana dashboards are copied per org folder but not org-filtered inside Grafana.
- Laravel generated Dockerfile builds assets before composer (projects importing CSS from vendor/ need own Dockerfile).
