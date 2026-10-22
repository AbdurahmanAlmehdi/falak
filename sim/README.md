# Falak local end-to-end simulation

This is a laptop-sized version of a real Falak install: the control plane (FrankenPHP +
Horizon + Reverb + Postgres 17 + Valkey), an edge that terminates TLS and checks agent
mTLS, the full observability stack, and three managed Ubuntu 24.04 servers. Each server
runs systemd as PID 1 and sshd.

```
host ──https://localhost:8443──► edge (Caddy)
                                  ├─ falak.test / localhost  TLS: Caddy internal CA ─► control-plane :8080 (panel, FrankenPHP worker mode)
                                  │                                                ├► agent-api :8080 (/agent/*, /install/*, /api/internal/*)
                                  │                                                └► reverb :8080 (/app, /apps)
                                  └─ agents.falak.test       TLS: Fleet-issued cert + agent mTLS ─► agent-api
srv-app-1 · srv-app-2 · srv-db-1  ── fleet network (10.77.20.0/24) ──► edge as falak.test / agents.falak.test
  (Ubuntu 24.04, systemd, sshd)   └─ observability network ─────────► gateway:4318 (OTLP)
control-plane · agent-api · horizon · reverb ── backend network (10.77.10.0/24) ► postgres · valkey
```

control-plane and agent-api run the production runtime (`deploy/control-plane`: Caddyfile, entrypoint, php.ini).
Agents and the builder long-poll agent-api, which has its own PHP thread pool, so they cannot starve the panel
(docs/INSTALL.md, "Performance").

## Usage

```bash
cd sim
make up                  # build + start everything, waits until healthy (METRICS=mimir to switch backend)
make e2e                 # end-to-end checks (see below)
make enroll-all          # dev shortcut: mint a token per server and enroll all three
make enroll TOKEN=<t> [SERVERS="srv-app-1"]   # enroll with a token minted by the control plane
make token               # mint one install token via Fleet's Enrollment contract
make ca                  # export the edge TLS root to .data/edge-root.crt
make logs S=edge | make shell S=srv-db-1 | make ps
make down                # stop (keeps volumes) | make reset (also wipes volumes and .data/, keeps the caches)
make reset-all           # make reset + wipe the download/package caches (next run is cold)
make clean-cache         # stop the sim and wipe the caches | make cache-stats: cache volume sizes
make e2e-deploy          # full product E2E (./e2e-deploy.sh; ONLY=deploy,release / SKIP=... / FAST=1)
```

Endpoints on the host:

| What | URL |
|---|---|
| Control plane | `https://localhost:8443` (trust `.data/edge-root.crt`, or `curl -k`) |
| Agent API | `https://agents.falak.test:8443` (`--resolve agents.falak.test:8443:127.0.0.1`, trust the Fleet CA) |
| Grafana | `http://localhost:13000` (admin / admin) |
| OTLP ingress | `http://localhost:14318` |

Host ports are set in `sim.env` and are deliberately not the defaults, so the sim does not
clash with a standalone observability stack. To run the servers with amd64 agent binaries,
use `SIM_AGENT_ARCH=amd64 make up`. Emulation is required on arm64 hosts.

## Components

| Service | Built from / image |
|---|---|
| control-plane / agent-api / horizon / reverb | `control-plane.Dockerfile` (context `../control-plane`, plus `../deploy/control-plane` for the production Caddyfile, php.ini and entrypoint): `dunglas/frankenphp:1.13.2-php8.4-trixie` + pdo_pgsql, redis, pcntl, intl, zip, bcmath, gmp, sockets. Composer deps come from `composer:2.10.3`, the frontend is built with `oven/bun:1.4.2`. Entrypoint roles `web` (runs migrations with `FALAK_MIGRATE=1`, then the panel in worker mode), `agent-api`, `horizon`, `reverb`. |
| postgres | `postgres:17.11` |
| valkey | `valkey/valkey:9.1.2-alpine` |
| edge | `edge/Dockerfile` (`caddy:2.11.4-alpine` + openssl, curl) |
| srv-app-1, srv-app-2, srv-db-1 | `server.Dockerfile` (`ubuntu:noble-20260911` + systemd, openssh-server), privileged, private cgroupns |
| gateway, loki, tempo, victoriametrics or mimir, grafana | included from `../observability/compose.yml` (+ `observability.override.yml`: faster start-up healthchecks, sim only) |
| sim-apt-cache | `caches/apt-cache.Dockerfile` (apt-cacher-ng) |
| sim-downloads | `nginx:1.31.6-alpine` + `caches/downloads.conf` |
| sim-hub-mirror, sim-ghcr-mirror | `registry:2.8.3` in pull-through (proxy) mode for Docker Hub / ghcr.io |

The servers are privileged with their own cgroup namespace so that systemd can run as PID 1.
Units that don't work in a container (udev, getty, timesyncd, …) are masked, and sshd uses
Ubuntu's stock `ssh.socket` activation. Each container generates its own SSH host keys at
first boot. `SIM_SSH_PUBKEY` in `sim.env` authorises a key for `root`.

The agent binary is mounted, not baked in. The repository is mounted read-only at
`/opt/falak/src`, and at boot `falak-sim-agent-link.service` links
`agent/bin/falak-agent-linux-$SIM_AGENT_ARCH` to `/usr/local/bin/falak-agent` if the file
exists. If the agent hasn't been built, the servers still boot. The control plane serves the
same binaries to the installer (`FALAK_AGENT_BINARIES_PATH=/opt/falak/src/agent/bin`).

## Caches and speed

A full `make reset && make up && ./e2e-deploy.sh` provisions three servers for real, builds and deploys a
dozen releases and pulls third-party images. Everything the servers and the builder download goes through
local caches that **survive `make reset`** (external volumes `falak-sim-cache-*`, created by `make up`), so only
the first run after `make reset-all` / `make clean-cache` fetches from the internet. The product code paths do
not change: apt still resolves and installs packages, the agent still downloads and sha256-verifies runtime
binaries, dockerd still pulls, the builder still runs composer/npm/bun and BuildKit. Only the bytes are local.

| Cache | What it serves | How the sim uses it |
|---|---|---|
| `sim-apt-cache` (apt-cacher-ng, `falak-sim-cache-apt`) | Ubuntu archive (HTTP) | the server image sets `Acquire::http::Proxy-Auto-Detect` to `server/bin/falak-sim-apt-proxy`: the cache when it answers, `DIRECT` otherwise (`SIM_APT_PROXY`, empty = off) |
| `sim-downloads` (caching nginx, `falak-sim-cache-downloads`) | FrankenPHP / Bun / Deno GitHub releases, Node.js dist, the ondrej/php PPA | the control plane's **product** mirror settings `FALAK_{FRANKENPHP,NODE,BUN,DENO}_MIRROR` point at `https://downloads.falak.test/{github,nodejs}/…` (the edge terminates TLS). The PPA is HTTPS, so the servers resolve `ppa.launchpadcontent.net` to the edge (`extra_hosts`, the edge has a static fleet address), which proxies to this cache (package files cached for a year, `dists/` indexes revalidated every 5 min) |
| `sim-hub-mirror` (registry proxy, `falak-sim-cache-hub`) | Docker Hub | `registry-mirrors` in the servers' `/etc/docker/daemon.json` and a `docker.io` mirror in the builder's BuildKit config |
| `sim-ghcr-mirror` (registry proxy, `falak-sim-cache-ghcr`) | ghcr.io | the servers resolve `ghcr.io` to the edge (`extra_hosts`; dockerd only mirrors Docker Hub) |
| builder (`falak-sim-cache-builder`) | composer / npm / bun caches, BuildKit export cache | `FALAK_BUILDER_CACHE_DIR=/root/.cache/falak-builder`; the buildx builder keeps its state volume (`buildx_buildkit_falak0_state`) across restarts |

The host overrides exist only on the three servers, so the caches themselves and BuildKit resolve the real
upstreams. (A separate network for this was tried and dropped: Docker assigns interface names in no stable order,
and agents report their first private address, which the control plane health-checks sites through.)
`sim-prefetch` warms the two registry caches with the E2E's compose and template images right after `make up`:
the pull-through registry fetches an uncached blob upstream twice, so without it a cold pull inside a deployment
is ~2.5x slower than pulling directly.

`./e2e-deploy.sh` prints each stage's duration and a summary table at the end, and appends the timings to
`.data/e2e-timings.tsv`. Pick stages for quick iteration: `ONLY=deploy,release` (comma or space separated;
state from the previous run is in `.data/e2e.env`), `SKIP=templates`, or `FAST=1` (skips the third-party
template stage). Status polls run every `POLL=1` second. The `redis` stage (Redis on app-2, an instance created
through `POST /projects/{project}/environments/{env}/services`, `${{ cache.REDIS_* }}` references deployed with the Bun
site, instance user / file modes / isolation from the stock 6379 checked on the server) needs the `servers`, `sites`
and `bun` stages' state: after a full run, `ONLY=redis ./e2e-deploy.sh`. Server images built before it lack
`redis-server`: `make build` (or `docker compose build srv-app-2`) rebuilds them. `make up` prints how long the stack took to become
healthy; healthchecks poll every 1–2 s while containers start.

## Integration points

1. **Fleet CA → edge client verification.** The control plane runs with
   `FALAK_CA_PATH=/falak/ca` on the shared `falak-ca` volume, and Fleet writes the CA
   certificate (never the key) to `/falak/ca/ca.pem`. The edge mounts the volume read-only,
   checks the file every 3 s, copies it to `/etc/caddy/trust/ca.pem` and hot-reloads Caddy.
   Until the file exists, the edge trusts a throwaway placeholder CA whose key has been
   discarded, so every non-enroll agent call fails closed.
2. **Agent API server certificate.** Agents pin the Falak CA on the mTLS API, so that API has
   its own host, `agents.falak.test` (`FALAK_AGENT_API_URL=https://agents.falak.test/agent/v1`).
   The panel stays on `falak.test`, which uses a browser/installer-trusted cert. The
   control-plane entrypoint runs `php artisan fleet:ca:server-cert agents.falak.test --out /falak/ca`
   when `agent-api.{pem,key}` are missing, expiring or not signed by the current CA. The
   edge adds the `agents.falak.test` site as soon as they appear.
3. **Agent mTLS contract** (`contracts/agent-protocol`). `client_auth verify_if_given` runs
   against the Fleet CA. `/agent/v1/*` except `/agent/v1/enroll` without a client cert gets
   `401` at the edge. A cert from any other CA fails the TLS handshake. With a valid cert the
   edge sets `X-Falak-Client-Cert-Fingerprint` to the lowercase-hex SHA-256 of the client DER
   cert. The edge always strips any client-supplied value of that header. The control plane
   trusts the header only from `FALAK_AGENT_TRUSTED_PROXIES=10.77.10.0/24` (the backend
   network). `GET /_edge/whoami` shows what the edge would forward, which helps when
   debugging.
4. **Edge TLS root → servers.** The edge publishes Caddy's internal root to the `edge-pki`
   volume. At boot, each server installs it into the system trust store
   (`falak-sim-trust.service`), so `curl https://falak.test/install/<token> | sh` works
   unchanged.
5. **Telemetry.** Servers reach the OTLP gateway at `http://gateway:4318`
   (`FALAK_OTLP_ENDPOINT`), and the control plane reaches Grafana, Loki, Tempo and the metrics
   API by service name (`FALAK_GRAFANA_URL`, …).

## e2e (`make e2e` → `e2e.sh`)

| # | Check | Status |
|---|---|---|
| 1 | every container running, and healthy where it has a healthcheck | works |
| 2 | `GET /up` through the edge from the host (edge CA verified) and from every server over the fleet network | works |
| 3 | edge mTLS: no-cert → 401, spoofed header → 401, enroll passes, fingerprint = sha256(DER) (checked against a disposable edge with a throwaway CA), untrusted CA → handshake failure, Fleet CA trusted, agent API cert chains to the Fleet CA | works |
| 4 | `../observability/smoke-test.sh` against the sim stack, and OTLP gateway reachable from every server | works |
| 5 | each server: PID 1 = systemd, `systemctl is-system-running` = `running`, sshd answers, agent binary mounted, private network reachability | works |
| 6 | per server: agent enrolled and active, heartbeat and long-poll over mTLS without errors, host metrics in the metrics backend | works after `make enroll-all`, otherwise PENDING |
| 7 | site provisioning and deploy, app APM data on the Laravel/Node/Queues dashboards, agent deployment log events → *Deployment failed* alert | **PENDING**: needs the Sites/Deployments modules, the agent `deploy.*` executors and the APM packages running in a deployed site |

`make token` and `make enroll-all` are dev shortcuts. They call
`Falak\Fleet\Contracts\Enrollment::issueInstallToken()` through `artisan tinker` with a fixed
sim organisation id (`SIM_ORG_ID`). Once the Servers module has a real "add server" flow,
use a token from that flow with `make enroll TOKEN=…`.
