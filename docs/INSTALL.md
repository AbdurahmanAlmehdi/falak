# Installing Falak (production)

Falak runs as a Docker Compose stack on a single Linux host. The installer sets up Docker, writes a
generated configuration to `/opt/falak/.env`, starts the stack, and creates the first administrator.
Day-2 operations use `falak-ctl`.

```
internet ──:80/:443──► edge (Caddy)
                        ├─ falak.example.com         Let's Encrypt ─► control-plane (FrankenPHP) · reverb (websockets)
                        │                                                · relay (consoles: /terminal/v1)
                        ├─ agents.falak.example.com  Fleet-CA cert + client-cert (mTLS) check ─► control-plane
                        ├─ registry.falak.example.com Let's Encrypt ─► registry (built-in image registry, token auth)
                        └─ grafana.falak.example.com Let's Encrypt ─► grafana            (optional)
control-plane · horizon · reverb · scheduler ─► postgres 17 · valkey
builder (falak-builder serve: PHP/Composer, Node, Bun) ─► edge
```

Releases are published from [github.com/falak-sh/falak](https://github.com/falak-sh/falak); a fork can install its own releases with `--repo OWNER/NAME`.

## 1. Requirements

| | Minimum | Recommended |
|---|---|---|
| OS | Ubuntu 22.04 / 24.04 / 26.04, Debian 12 (amd64 or arm64); others need `--force` | Ubuntu 24.04 or 26.04 |
| RAM | 2 GB (installer warns below 4 GB) | 4 GB; **8 GB with `--observability`** |
| Disk | 10 GB free | 25 GB+ (images, build artifacts, backups) |
| Network | public IPv4 (or IPv6); ports **80** and **443** free and reachable | |
| DNS | records for the panel, `agents.` and `registry.` hosts (below) | |

The servers Falak manages are usually separate machines; the control-plane machine can host sites too, with a reserved
share for the panel (see [Sites on the control-plane machine](#sites-on-the-control-plane-machine)). Servers: Ubuntu 22.04, 24.04 or 26.04 (the agent
installer warns on other apt-based systems). On 26.04 PHP comes from Ubuntu's archive (PHP 8.5 only) until `ppa:ondrej/php`
publishes packages for it. Every server runs Docker Engine 28 or newer from Docker's apt repository (`docker-ce`, key fingerprint
`9DC8 5822 9FC7 DD38 854A  E2D8 8D81 803C 0EBF CD88` checked): provisioning replaces an older Docker (containers, images and
volumes stay); where Docker's repository has no packages for the release yet, the release's `docker.io` is used if it is
28 or newer, else provisioning stops with that reason. Databases run as containers of Falak's images (`docs/DB_IMAGES.md`).

**Resource budget** (limits are caps, not reservations). Idle values were measured with three managed servers enrolled and a site deployed (sim) and on a 2-CPU host profile (bench):

| Service | Limit | Idle | Notes |
|---|---|---|---|
| control-plane (panel, FrankenPHP worker mode) | 512 MB | 130–150 MB | ~12 MB per booted worker (2 × CPUs), autoscales under load |
| agent-api (agents, installer, builders) | 512 MB | 70–100 MB | +2–3 MB per waiting long-poll (one per server and builder) |
| horizon (`FALAK_HORIZON_MAX_PROCESSES`, default 4, auto-balanced from 1) | 512 MB | 130–150 MB | |
| reverb | 192 MB | 50 MB | |
| scheduler | 256 MB | 45 MB | a short `schedule:run` process every minute |
| relay (consoles, Go) | 64 MB | 4–12 MB | pipes console bytes between agents and browsers/the CLI |
| postgres (`shared_buffers=128MB`) | 512 MB | 40–150 MB | agents' long-polls hold no connection |
| valkey (`maxmemory 128mb`, AOF) | 192 MB | 15 MB | |
| edge | 128 MB | 25–35 MB | |
| builder | 1.5 GB | 10–120 MB | builds use up to ~1 GB |
| **core total** | **≈ 4.4 GB** | **≈ 0.6–0.8 GB** | idle CPU: a few % of one core |
| observability: gateway, loki, tempo, victoriametrics, grafana | 64 + 384 + 384 + 384 + 512 MB | 20 + 75 + 75 + 65 + 320–360 MB (≈ 0.6 GB) | |

The scheduler stays a separate container. Running `schedule:work` inside Horizon would save only its ~45 MB
and would tie the two services' restarts and health together. Grafana 12+ downloads its Drilldown, Advisor
and Pyroscope apps on first start. Falak does not use them, and they add about 110 MB, which put a fresh
Grafana at ~470 MB against its 512 MB limit. The stack therefore skips that step
(`FALAK_GRAFANA_PREINSTALL_DISABLED=false` in `.env` restores it). On a 2-vCPU / 4 GB host the core stack
leaves more than 3 GB free. With `--observability`, plan for 8 GB so builds have room.

## 2. DNS

Create these records before you install (replace the IP with your server's public address):

| Name | Type | Value | Why |
|---|---|---|---|
| `falak.example.com` | A (and/or AAAA) | `203.0.113.10` | panel, installer script, agent enrollment |
| `agents.falak.example.com` | A (and/or AAAA) | `203.0.113.10` | agent API (mTLS) |
| `registry.falak.example.com` | A (and/or AAAA) | `203.0.113.10` | built-in image registry: docker builds push, servers pull |
| `grafana.falak.example.com` | A (and/or AAAA) | `203.0.113.10` | only with `--observability` |

- The panel, the registry and Grafana get **Let's Encrypt** certificates automatically (HTTP-01/TLS-ALPN on ports 80/443).
- The **registry** host serves Falak's built-in image registry (Docker Distribution) with token auth: docker-mode
  builds (Dockerfile sites, compose services with `build:`) push there and servers pull from it, pinned by digest.
  The control plane is the token server (`https://<panel>/api/internal/registry/token`, the registry's realm): it
  signs short-lived tokens with `secrets/registry-token.key` and the registry checks them with
  `secrets/registry-token.crt` (both created by `falak-ctl up` / `update`). Builders and servers never get a shared
  password: each build gets a credential that pushes only to its site's repositories while the build runs, each deploy
  command one that pulls only the deploying organization's images, for an hour. The operator credential in `.env`
  (`FALAK_REGISTRY_USERNAME`, `FALAK_REGISTRY_PASSWORD`) is for `falak-ctl registry` and the prune job only. With `--tls internal` its certificate is not trusted by Docker on other hosts,
  so docker builds need `--tls acme` (or the internal root trusted in each Docker daemon).
- The **agents** host does *not* use Let's Encrypt. Agents pin Falak's own Fleet CA, so the edge serves
  that host with a certificate issued by the Fleet CA, and verifies agent client certificates against it.
  Behind Cloudflare, set all records to **DNS only** (grey cloud): a proxy would terminate TLS and break mTLS.
- The installer checks that every name resolves to this host's public IP, and it prints the missing records.
- **Installs from before the registry** get `FALAK_REGISTRY_*` added to `.env` by the next `falak-ctl up` or
  `falak-ctl update` (an update run by an older falak-ctl: run `falak-ctl up` once afterwards). Add the
  `registry.` DNS record; `falak-ctl registry status` checks it.

## 3. Install

```bash
curl -fsSL https://falak.sh/install.sh \
  | sudo bash -s -- --domain falak.example.com --email you@example.com
```

`https://falak.sh/install.sh` redirects to the script on GitHub `main`
(`https://raw.githubusercontent.com/falak-sh/falak/main/deploy/install.sh`), so either URL works.

Or pin a release with its own copy of the script:
`curl -fsSL https://github.com/falak-sh/falak/releases/download/v1.2.3/install.sh | sudo bash -s -- --domain ... --email ...`

What it does:

1. **Preflight:** checks that you are root, the OS, the CPU architecture, RAM, disk, that ports 80/443 are free, and that DNS for the panel and `agents.` host (plus `grafana.` with `--observability`) points at this host's public IP.
2. Installs **Docker Engine** and the Compose plugin from Docker's official apt repository if they are missing, using
   the suite of the host's codename (`jammy`, `noble`, `resolute`, `bookworm`). `jammy`, `noble` and `bookworm` are
   used as they are; for other codenames it checks (with retries) that Docker has published the suite, and if not
   yet (a brand-new release) it falls back to `noble` (Ubuntu) or `bookworm` (Debian) and says so.
3. Downloads the release bundle `falak-deploy.tar.gz`, verifies it against `SHA256SUMS`, and unpacks it to
   `/opt/falak/{deploy,observability}`. It also installs `falak-ctl` to `/usr/local/bin`.
4. Generates `/opt/falak/.env` (mode 600) with `APP_KEY`, database and Valkey passwords, Reverb keys, the
   builder token, the OTLP token, and all `FALAK_*` URLs.
5. Pulls the images `ghcr.io/<owner>/falak-{control-plane,builder,edge}:<version>` (retrying transient registry
   errors up to 4 times with backoff, `FALAK_PULL_ATTEMPTS`), starts the stack, and
   waits until every service is healthy. Database migrations run in the `control-plane` service on start.
6. Creates the first administrator with `falak:admin` and **prints the password once**.

| Option | Env | Default |
|---|---|---|
| `--domain NAME` | `FALAK_DOMAIN` | required |
| `--email ADDRESS` | `FALAK_EMAIL` | required for Let's Encrypt; also the admin e-mail |
| `--admin-email ADDRESS` | `FALAK_ADMIN_EMAIL` | `--email` |
| `--version TAG` | `FALAK_VERSION` | latest release |
| `--observability` | `FALAK_OBSERVABILITY=1` | off |
| `--tls acme\|internal` | `FALAK_TLS` | `acme` (`internal` = Caddy's local CA, for testing only) |
| `--repo OWNER/NAME` | `FALAK_REPO` | `falak-sh/falak` |
| `--image-prefix PREFIX` | `FALAK_IMAGE_PREFIX` | `ghcr.io/<owner>` |
| `--build-from-source [--ref REF]` | `FALAK_BUILD_FROM_SOURCE=1` | clones the repo and builds images locally (no registry) |
| `--source-dir PATH` | `FALAK_DEPLOY_SOURCE` | use deploy files from a local checkout |
| `--http-port/--https-port` | `FALAK_HTTP_PORT/FALAK_HTTPS_PORT` | 80/443 (other ports only with `--tls internal`) |
| `--allow-local-server` | `FALAK_ALLOW_LOCAL_SERVER=1` | off: the panel may not make this machine a server ([below](#sites-on-the-control-plane-machine)) |
| `--skip-dns-check`, `--force` | | |

Running the installer again is safe. Secrets are kept, settings from the flags are updated, the stack is
converged, and the admin is not created a second time. To enable observability later, re-run it with
`--observability`. The installer then creates a Grafana service account token for Falak
(`FALAK_GRAFANA_TOKEN`). Grafana's `admin` password is `GRAFANA_ADMIN_PASSWORD` in `/opt/falak/.env`.

**Who can sign up.** By default anyone who can reach the panel can create an account (and gets an empty
organization of their own). On a panel reachable from the internet, set `FALAK_REGISTRATION` in `/opt/falak/.env`
and run `falak-ctl up`:

| Value | Sign-up |
|---|---|
| `open` (default) | anyone |
| `invite` | only through an invitation link (invite from **Settings → Members**): the person opens the e-mailed link, chooses *Sign up* and registers with the invited address, which also joins the organization |
| `closed` | nobody; the *Sign up* links are hidden. Create accounts with `falak-ctl admin create <email>` |

An unknown value counts as `closed`. A panel without any account always accepts the first sign-up, so the first
administrator can register before the setting matters.

Next steps: log in, open **Servers → Create**, and run the printed install command on each server.
The agent binaries come from the control-plane image, so servers download them from your panel
(`/install/agent/linux-{amd64,arm64}`), not from GitHub.

### Files

```
/opt/falak/.env            settings + secrets (install.sh; never commit or share)
/opt/falak/secrets/kek     key-encryption key: decrypts every secret in the database (see "Encryption keys")
/opt/falak/custom.env      optional extra app env (GITHUB_APP_*, mirrors, FALAK_* tuning) — loaded by the app containers
/opt/falak/deploy/         compose.yml, falak-ctl, image support files (replaced on update; previous kept as deploy.prev)
/opt/falak/observability/  Loki/Tempo/Grafana/gateway configs
/opt/falak/backups/        falak-ctl backup output
/opt/falak/edge/           optional extra sites served by the edge (yours; never touched by updates)
```

**Which file?** `.env` holds the settings `deploy/compose.yml` passes to the containers by name (domains, secrets,
`MAIL_*`, sizing, telemetry, `FALAK_REGISTRATION`, …) plus falak-ctl's own (`FALAK_BACKUP_KEEP`, `FALAK_PRUNE_IMAGES`).
The DR bucket and passphrase are not there: they live in `/opt/falak/dr/dr.env` (`falak-ctl dr setup`).
Every other app variable — e.g. `GITHUB_APP_*`, `FALAK_WEBHOOK_URL`, `FALAK_*_MIRROR` — goes in `custom.env`;
compose does not forward it from `.env`. A variable compose passes by name always comes from `.env`: setting it in
`custom.env` has no effect.

For e-mail, set `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` and
`MAIL_FROM_ADDRESS` in `/opt/falak/.env`, then run `falak-ctl up`. To send through [Resend](https://resend.com)
instead, set `MAIL_MAILER=resend`, `RESEND_API_KEY=re_…` and a `MAIL_FROM_ADDRESS` on a domain verified in Resend
(the `MAIL_HOST`/`MAIL_PORT`/`MAIL_USERNAME`/`MAIL_PASSWORD` settings are then unused). Mail covers email
verification, password resets, invitations and email alerts; it is sent from the queue (`horizon`).

**Managed (multi-tenant) installs.** A panel where strangers sign up sets `FALAK_MANAGED=true` in `.env`: accounts
must verify their email address before they can use the panel, API tokens or `falak login` (existing accounts
count as verified), webhook alert channels can never target private addresses
(`FALAK_ALERTING_ALLOW_PRIVATE_WEBHOOKS` is ignored), and privileged compose services can't be allowed. It needs
working mail (above). Self-hosted installs leave it unset.

### Encryption keys

Every secret Falak stores (site environments, database and storage credentials, private keys, tokens, two-factor
secrets) is encrypted at rest with AES-256-GCM under a **data key**. Data keys are stored in the database, wrapped
by the **key-encryption key (KEK)**. The KEK is not `APP_KEY` and is never in `.env` or the database, so a copy of
the database together with `.env` reveals no secrets. Each value is bound to its row: copied to another row or
column (another organization's, say), it no longer decrypts.

- **Where.** `install.sh` creates `/opt/falak/secrets/kek`: 32 random bytes, mode `0400`, owned by uid 33 (the
  containers' `www-data`), in a `0711` directory. It is mounted read-only into the PHP containers (`control-plane`,
  `agent-api`, `horizon`, `reverb`, `scheduler`). They refuse to start without a usable KEK, and the panel also
  refuses one that doesn't unwrap the database's data keys. `falak-ctl kek init` creates a missing KEK (never
  replaces one) and fixes its permissions; `falak-ctl doctor` checks it.
- **Emergency kit.** Save it right after installing, and after every rotation:
  `falak-ctl kek export /root/falak-emergency-kit.txt`, then move the file to a password manager or offline
  storage and delete it from the host. It is never printed to the terminal. `falak-ctl kek import <file>` puts the
  KEK back (`--force` replaces a different one, which is kept aside).
- **Backups.** A backup contains the KEK only when it is encrypted (the DR passphrase, `falak-ctl dr setup`). Otherwise
  `falak-ctl backup` warns that the backup can't be decrypted without the KEK, which you then keep separately (the
  emergency kit). It also leaves the KMS / Vault credentials (`FALAK_KEK_AWS_ACCESS_KEY_ID`,
  `FALAK_KEK_AWS_SECRET_ACCESS_KEY`, `FALAK_KEK_AWS_SESSION_TOKEN`, `FALAK_KEK_VAULT_TOKEN`) out of an unencrypted
  backup's `.env` / `custom.env`, with the same warning. `falak-ctl restore` refuses a backup without its KEK when
  this host has neither the KEK its data keys are wrapped by nor its predecessor (`kek.previous`); import it first,
  or pass `--force`.
- **Rotation.** `falak-ctl kek rotate` creates a new KEK, keeps the old one as `secrets/kek.previous` (backups from
  before the rotation need it) and re-wraps every data key (`falak:keys:rotate-kek`). Secrets are not re-encrypted:
  only the data keys change. It refuses to start while a data key is still wrapped by `kek.previous` (an unfinished
  rotation: run `falak-ctl artisan falak:keys:rotate-kek` first). A `kek.previous` from an earlier rotation moves to
  `kek.retired-<id>`. `falak-ctl artisan falak:keys:rotate-data` starts a new data key and re-encrypts every value
  under it in batches. Running workers may use the old key for up to a minute (`FALAK_KEYS_ACTIVE_TTL`), so it passes
  again after that until nothing is left under the old key; if it is interrupted, run it again with `--resume`.
- **KMS / Vault.** Set `FALAK_KEK_PROVIDER=aws-kms` or `vault-transit` in `.env` to keep the KEK in AWS KMS or a
  Vault/OpenBao transit key: it never leaves them, and there is no file. Put the provider's settings in
  `custom.env`: `FALAK_KEK_AWS_KMS_KEY_ID`, `FALAK_KEK_AWS_REGION`, `FALAK_KEK_AWS_ACCESS_KEY_ID`,
  `FALAK_KEK_AWS_SECRET_ACCESS_KEY` (optional `FALAK_KEK_AWS_SESSION_TOKEN`), or `FALAK_KEK_VAULT_ADDR`,
  `FALAK_KEK_VAULT_TOKEN`, `FALAK_KEK_VAULT_KEY` (default `falak`), `FALAK_KEK_VAULT_MOUNT` (default `transit`) and
  optionally `FALAK_KEK_VAULT_NAMESPACE`. To move existing data keys, keep the old KEK readable (for a local KEK, as
  `secrets/kek.previous`) and run `falak-ctl artisan falak:keys:rotate-kek`. After rotating the key inside KMS or
  Vault, `--all` re-wraps every data key under the newest key version.

### Connect GitHub (GitHub App, one click)

Open **Settings → Source control → Connect GitHub**. Falak registers a private GitHub App for your Falak organization
(on your personal GitHub account, or on a GitHub organization you own: type its name) using GitHub's
[app manifest flow](https://docs.github.com/en/apps/sharing-github-apps/registering-a-github-app-from-a-manifest):
you confirm the app on github.com, Falak stores its id, private key and webhook secret **encrypted in its database**,
and GitHub continues straight to the installation page, where you pick the repositories Falak may deploy. Change
that selection any time with **Manage access on GitHub**; GitHub sends you back to Falak afterwards.

- **Access requested:** repository contents and metadata, **read-only**, plus `push` events. No deploy keys and no
  per-repository webhooks: builds clone over HTTPS with installation tokens minted per build (valid for one hour,
  cached for at most 50 minutes, never stored or logged).
- **Falak's URL must be reachable from GitHub** for push-to-deploy: the app's single webhook is
  `https://<panel>/api/webhooks/source-control/github-app/<id>` (shown on the card). `FALAK_WEBHOOK_URL` overrides the
  base URL (set it in `/opt/falak/custom.env`) if GitHub must reach Falak through a different host. Creating the app and cloning work without it; only
  push-triggered deploys and installation status updates (suspended / uninstalled on GitHub) need the webhook.
- **One app per Falak organization.** GitHub only lets a private app be installed on the account that owns it, so
  repositories from a second GitHub account need their own app (another Falak organization), or a token connection.
- **Remove:** *Disconnect* on an installation uninstalls the app from that account; *Delete app* uninstalls it
  everywhere and forgets the credentials. Delete the app registration itself on GitHub (App settings → Advanced).
- **Personal access tokens** still work (*Use a personal access token instead*), e.g. for GitHub Enterprise Server.

**Operator-managed app (optional).** To use one app you created yourself for every Falak organization, set these in
`/opt/falak/custom.env` (not `.env`: compose does not forward them from there) and run `falak-ctl up`. When set, they take precedence over registered apps for new installations
(existing installations keep the app they were made with), and the one-click registration is hidden.

| Variable | Value |
|---|---|
| `GITHUB_APP_ID` | the app's numeric id |
| `GITHUB_APP_SLUG` | the app's URL name (`github.com/apps/<slug>`) |
| `GITHUB_APP_PRIVATE_KEY` | the PEM private key (newlines may be written as `\n`) |
| `GITHUB_APP_WEBHOOK_SECRET` | the webhook secret; webhook URL `https://<panel>/api/webhooks/source-control/github-app/env` |

Configure that app with Setup URL `https://<panel>/source-control/github-app/setup` ("Redirect on update" on),
permissions Contents: read and Metadata: read, and the `push` event.

### Canvas icons (optional)

The canvas icon picker (projects, services and groups) searches [Cool Icons](https://coolicon.falak.sh), and browsers
load picked icons from it. To use a self-hosted copy, set `FALAK_ICONS_URL` (its base URL, serving `/search.json` and
`/i/<slug>.svg`) in `/opt/falak/custom.env`; set it empty to turn the picker off and keep only the logos bundled with the
panel. Then `falak-ctl up`. Icons are canvas-only: `falak.yaml` never reads or sets them.

### Template catalog updates (optional)

The panel fetches the curated template catalog from the [falak-sh/templates](https://github.com/falak-sh/templates)
releases every hour (`catalog.json` + a sha256-checked `catalog.tar.gz`), so new and fixed templates arrive without a
Falak update. It serves whichever is newer: the last good download or the catalog baked into the image; a failed
download never replaces it. To follow a fork, set `FALAK_TEMPLATES_URL` to its release download base (e.g.
`https://github.com/<owner>/templates/releases/latest/download`) in `/opt/falak/custom.env`; set it empty to use only
the baked catalog (air-gapped installs). Then `falak-ctl up`. Settings → Templates shows the catalog version in use;
`php artisan templates:sync` runs a fetch now. Sites already deployed from a template are never changed by an update.

### Runtime download mirrors (optional)

Servers download FrankenPHP, Node.js, Bun and Deno release binaries during provisioning (sha256-verified).
If servers cannot reach GitHub / nodejs.org, or you run a caching proxy in front of them, point the agents at
an HTTPS mirror with the same path layout. Set these in `/opt/falak/custom.env`, then `falak-ctl up`:

| Variable | Replaces | Fetched path |
|---|---|---|
| `FALAK_FRANKENPHP_MIRROR` | `https://github.com/php/frankenphp/releases/download` | `<mirror>/v<version>/frankenphp-linux-<arch>-gnu` |
| `FALAK_NODE_MIRROR` | `https://nodejs.org/dist` | `<mirror>/v<version>/SHASUMS256.txt`, `node-v<version>-linux-<arch>.tar.gz` |
| `FALAK_BUN_MIRROR` | `https://github.com/oven-sh/bun/releases/download` | `<mirror>/bun-v<version>/SHASUMS256.txt`, `bun-linux-<arch>.zip` |
| `FALAK_DENO_MIRROR` | `https://github.com/denoland/deno/releases/download` | `<mirror>/v<version>/deno-<arch>-unknown-linux-gnu.zip{,.sha256sum}` |

Unset (the default) means the upstream URLs. The mirror applies to servers provisioned (or runtimes
installed) after the change.

### Extra sites on the control-plane host (optional)

The edge (Caddy, ports 80/443) also loads every `/opt/falak/edge/*.caddyfile`. The folder is mounted read-only at
`/etc/caddy/custom`, so files next to a site file are served from there. Example, a static site in
`/opt/falak/edge/www/example.com/`:

```
# /opt/falak/edge/example.com.caddyfile
example.com {
	import security_headers
	encode zstd gzip
	root * /etc/caddy/custom/www/example.com
	file_server
}
```

Point the domain's DNS at this server, then reload: `falak-ctl compose exec edge caddy reload --config
/etc/caddy/Caddyfile --adapter caddyfile` (Let's Encrypt issues the certificate on the first request). A broken file
makes the reload fail and the running config stays; at a container restart it would stop the edge, so validate first
with `caddy validate` in place of `caddy reload`.

### Sites on the control-plane machine

The machine that runs Falak can also be one of its servers: **Servers → Add server → This machine** (operator
organization) creates the server and asks the host to enrol it, or run it yourself as root with the install URL the
panel shows:

```bash
sudo falak-ctl local-server allow     # once: the panel may ask for it (install.sh --allow-local-server does the same)
falak-ctl local-server enable --install-url https://<domain>/install/<token>
falak-ctl local-server status
falak-ctl local-server disable
sudo falak-ctl local-server deny      # the panel's request is refused again
```

**Consent.** Allowing it lets the panel act as root on this machine, as it does on every server it manages: the agent
it enrols runs whatever the panel tells it to. It is off by default (`FALAK_HOST_ALLOW_LOCAL_SERVER=0` in `.env`); until
`allow`, a request from the panel is refused (`not allowed on this host`) and the panel shows **This machine** as
unavailable (`local_server_allowed` in `state/host/channel.json`). Running `enable` yourself as root needs no `allow`.

`enable`:

- writes `/etc/falak/local-edge.env` for the agent: the edge network's gateway and ports 8080/8443 (where the agent's
  edge listens instead of 80/443), the panel edge's fixed address (`FALAK_LOCAL_EDGE_PANEL`; PROXY protocol is accepted
  from it alone, `FALAK_LOCAL_EDGE_TRUST`), the subnets of every network of the Falak stack
  (`FALAK_LOCAL_EDGE_PROTECT`: the agent keeps the sites' traffic out of them except the panel edge's HTTP/HTTPS ports,
  `FALAK_LOCAL_EDGE_PANEL_PORTS`), `FALAK_PROJECT_NAME` and `FALAK_WORKLOAD_SLICE=falak.slice`. `falak-ctl ensure-host`
  (install, `up`, `update`) rewrites it while the local server is on, so new networks or another edge address reach the
  agent at its next apply; `local-server status` prints it;
- adds `/etc/systemd/system/falak-edge.service.d/10-local-edge.conf` (start after Docker, keep retrying): the agent's
  edge binds the Docker gateway address, which exists only once Docker is up;
- sets `FALAK_LOCAL_EDGE=1` and the two upstreams in `.env` and recreates the edge. The panel's edge keeps every host it
  serves itself (panel, `agents.`, `registry.`, Grafana, former domains, your `/opt/falak/edge/*.caddyfile` sites,
  wildcards included; re-read whenever those files change) and passes the connection of any other host untouched, TLS
  included, to the agent's edge with a PROXY protocol v2 header, so sites see the real client address;
- waits for the new edge, then downloads (retrying) and runs the install script like on any server (machine check,
  agent, enrolment).

`enable` needs Docker's systemd cgroup driver (below) and refuses without it.

**Reserved share.** With Docker's systemd cgroup driver (the default on Ubuntu/Debian with Docker's packages; check
`docker info -f '{{.CgroupDriver}}'`) every Falak container but the builder runs in `falakcp.slice`:
`MemoryMin=FALAK_CP_RESERVE_MB` (default 1536 MB, set in `.env`), `CPUWeight=1000`, `IOWeight=500`, OOM score −900.
The agent's sites and the builder run in `falak.slice`, capped by `/etc/systemd/system/falak.slice.d/50-host-cap.conf` at
total memory − the reserve (`MemoryHigh` 95 % of that), (cores − 1) × 100 % CPU, `IOWeight=50` and `TasksMax` = 80 % of
the kernel's task limit (min of `pid_max` and `threads-max`). The builder is capped either way: `FALAK_BUILDER_CPUS`
(cores − 1, minimum 1; recomputed when it exceeds that after a resize), 4096 pids, 1.5 GB, OOM score +500; its disk
guard floors come from `FALAK_BUILD_MIN_FREE_GB` / `FALAK_BUILD_ABORT_FREE_GB` in `.env` (empty: 5 and 2 GiB). The
panel's containers get the slice's `MemoryMin` only when cgroup2 is mounted with `memory_recursiveprot` (systemd 248+;
`local-server status` warns otherwise). A build or a site that runs out
of memory is killed inside its slice; the panel keeps its memory and wins every CPU contention. `enable` refuses a
machine that would leave sites less than 512 MB. systemd creates a slice on first use, so a missing unit file never
stops `falak-ctl up`; with another cgroup driver the slices are skipped (with a warning) and only the per-container
limits apply. `falak-ctl ensure-host` (run by install, `up` and `update`) rewrites the slices.

**HTTP/3.** UDP 443 stays with the panel's edge: sites on this machine are served over HTTP/1.1 and HTTP/2 only
(browsers fall back on their own).

`disable` removes `local-edge.env`, the upstreams and the `falak.slice` cap and recreates the edge: the agent and its
sites stay installed but get no traffic any more. Move the sites to another server, then delete this machine in the
panel (**Servers → this machine → Delete**).

### Domains for new services

When a service is created (template, Git repository, Docker image) each public endpoint gets a domain:

- **Generate** — `<name>.<server-ip-with-dashes>.sslip.io` (e.g. `minio-files.63-182-218-247.sslip.io`). Works at
  once, with a Let's Encrypt certificate, no DNS setup. The default when no test domain is configured. It points at the
  leader server (or the site's load balancer); a service on several servers without a load balancer is reached on the
  leader only. sslip.io / nip.io names are shared by all their users (common certificate rate limits, no cookie
  isolation): fine for trying things out, use your own domain for production.
- **Test domain** — `<slug>.<FALAK_TEST_DOMAIN>` when you run a wildcard test domain (the default then).
- **Custom domain** — Falak shows the record(s) to add (`A` → the server's IPv4, `AAAA` → its IPv6; one per server for
  DNS round-robin, or the load balancer only) and checks DNS live until the name points at the server. Cloudflare
  proxying ("orange cloud") is detected: keep the record "DNS only" until the certificate is issued.

Organizations pick the generated-domain provider (sslip.io, nip.io, off) in **Settings → Domains**. Server-wide settings
in `.env` (then `falak-ctl up`):

| Variable | Default | |
|---|---|---|
| `FALAK_GENERATED_DOMAIN_SUFFIX` | `sslip.io` | `nip.io`, the domain of a self-hosted [sslip.io server](https://github.com/cunnie/sslip.io), or `off` |
| `FALAK_DNS_RESOLVER` | `doh` | how the DNS check resolves: `doh` (DNS-over-HTTPS, no local cache) or `system` (the host's resolver) |
| `FALAK_DNS_DOH_URL` | `https://cloudflare-dns.com/dns-query` | any DNS-over-HTTPS JSON endpoint (e.g. `https://dns.google/resolve`) |

## 4. Operate: `falak-ctl`

```bash
falak-ctl status                          # services, health, version, URLs, PHP thread usage, last backup
falak-ctl logs [service] [-f]             # e.g. falak-ctl logs control-plane -f
falak-ctl doctor                          # DNS, certificates, ports, disk, containers, agent API (mTLS), PHP threads, backups
falak-ctl admin reset-password you@example.com [--password=...]
falak-ctl admin create ops@example.com [--token=cli]
falak-ctl kek status | export <file> | import <file> | rotate   # key-encryption key (see "Encryption keys")
falak-ctl artisan <command>               # php artisan in the control-plane container
falak-ctl prune-images [--dry-run]        # remove Falak images except the current and previous version
falak-ctl registry status                 # built-in image registry: address, size, answers with its credentials
falak-ctl registry gc [--dry-run] [--force] # delete registry layers no image references (stops the registry briefly)
falak-ctl panel-access tailnet | public | status  # panel only over Tailscale (see below)
falak-ctl up | down | restart [service]
```

### Panel over Tailscale only

Once the control-plane machine is on the organization's tailnet (**Settings → Network → Tailscale**, then join that
server from its **Network** tab), the panel can be closed to everyone else:

```bash
falak-ctl panel-access tailnet   # refuses unless `tailscale ip -4` works on this machine
falak-ctl panel-access status
falak-ctl panel-access public    # back to reachable from anywhere (over SSH if you are locked out)
```

`tailnet` sets `FALAK_PANEL_ALLOW="100.64.0.0/10 fd7a:115c:a1e0::/48"` in `.env` and recreates the edge. Browser paths
on the panel domain (and the agent API host) then answer 403 to any other address; DNS and certificates do not change,
devices reach the public address through the tailnet (they must accept routes; Linux: `tailscale set --accept-routes`).
Machine paths stay public: `/agent/*`, `/install/*`, `/api/*` (token API, Git webhooks, deploy hooks, builders),
`/terminal/v1/*` (CLI consoles), `/otlp/*`, `/up` and public status pages (`/status/<slug>`). `FALAK_PANEL_ALLOW` takes
any space-separated CIDR list; the edge sees the TCP peer address (no proxy headers are trusted), so a panel behind a
Cloudflare proxy or tunnel would be refused: keep its record "DNS only".

## 5. Upgrade

```bash
falak-ctl update                 # latest release
falak-ctl update --version v1.3.0
```

An update:

1. takes a backup (`backups/falak-backup-<ts>-pre-update-<old>.tar.gz`);
2. fetches the new deploy bundle and pulls the new images. Transient registry errors (`connection reset by peer`, IPv6
   resets) are retried with backoff: 4 attempts, 5 s / 10 s / 20 s apart (`FALAK_PULL_ATTEMPTS` in `.env` changes the
   count; anything but a positive number means 4, with a warning). Errors no retry fixes (access denied, unknown
   manifest or tag) fail at once. If the pull still fails, nothing changes;
3. recreates the stack. The `control-plane` service runs the migrations, and `horizon`, `reverb` and
   `scheduler` wait until it is healthy;
4. recreates every service whose **mounted config files** changed (see below) and prints their names;
5. health-checks every container and `https://<domain>/up`;
6. after a successful update, removes older Falak images (see below).

The release's `SHA256SUMS` is required: an update stops before changing anything when it is missing, lacks
`falak-deploy.tar.gz` or does not match (`FALAK_DEPLOY_SOURCE` checkouts are your own and not checked). Everything the
update prints also goes to `/opt/falak/state/host/update-<UTC>.log` (the newest 20 are kept).

### Updating from the panel

The panel checks GitHub's latest release every 6 hours (`FALAK_UPDATE_CHECK=0` in `custom.env` turns it off) and shows
**Update to vX.Y.Z** to the operator organization's members with the control-plane permission. The panel can't run
anything on the host itself: it writes `/opt/falak/state/host/request.json`, and `falak-host.path` (installed by
install.sh and every `falak-ctl update`/`up`) starts `falak-host.service`, i.e. `falak-ctl host-run` as root. host-run
takes the file away and accepts exactly two requests: `update` to a `vX.Y.Z` version newer than the installed one (a
downgrade or reinstall is for root: `falak-ctl update --version …`), at most one every 10 minutes after the last
finished, and `local-server` with an install URL under the panel's own `/install/`, only after `falak-ctl local-server
allow`; anything else is refused and logged. The update runs in its own
transient unit (`falak-update-<id>`), so the panel restarting under it can't stop it; the panel shows its progress from
`state/host/status.json` and `state/host/<id>.log` and reloads once it is back. An update still marked running whose
unit is gone (the host rebooted, the job was killed) is marked failed (`interrupted`) by the next host-run or
ensure-host.

The directory is `root:www-data`, mode 1770 (sticky), mounted read-write only into `control-plane`, `horizon` and
`scheduler` at `/falak/host`; the idle `host-init` container (16 MB) creates it with those permissions before they
start. Once the units are in place falak-ctl writes `state/host/channel.json`, and the panel offers its host buttons
only then: after an update from v0.22 or older (run by the old falak-ctl) run `falak-ctl up` once so the new falak-ctl
installs `falak-host.path` and the slices.

**Old images.** Each release pulls new `falak-control-plane`, `falak-edge` and `falak-builder` images (about 1 GB
together), so a host that updates often fills its disk. After a successful update falak-ctl records the version it
came from as `FALAK_PREVIOUS_VERSION` in `.env` and removes every other tag of those three images: the current and
the previous version stay, so a manual rollback (`falak-ctl update --version <previous>`) needs no download.
Third-party images (Postgres, Valkey, Grafana, …), images still used by a container and volumes are never touched.
Run it on its own with `falak-ctl prune-images` (`--dry-run` lists what it would remove); set
`FALAK_PRUNE_IMAGES=0` in `.env` to keep every image. With `FALAK_PULL=0` (images built locally, e.g.
`--build-from-source`) an update never prunes: removed images could not be pulled again. `falak-ctl prune-images`
still works there and warns first.

**Registry storage.** Every docker build pushes an image to the built-in registry (`registry-data` volume). Two
steps keep it from growing forever:
- **Daily, in the control plane** (`falak:registry-prune`, 03:45, after the artifacts prune): deletes the images of
  builds whose artifact was pruned (each site keeps its newest `FALAK_ARTIFACTS_KEEP` builds, default 10). An image
  stays while a release may still run it (pending, live or kept for rollback), while its build is running or less
  than a day old, and tags that aren't build ids are never touched. Images of a deleted site's builds go
  `FALAK_REGISTRY_DELETED_SITE_GRACE_DAYS` (default 7) days after the build. Preview with
  `falak-ctl registry prune --dry-run`; run it now with `falak-ctl registry prune`.
- **Weekly, from cron** (`/etc/cron.d/falak-registry-gc`, Sunday 04:17, written by `falak-ctl up`/`update`):
  `falak-ctl registry gc` deletes the layers nothing references any more, which is what frees disk space. The
  registry is stopped while it runs (a push during garbage collection could lose layers), so it runs at night and is
  skipped (logged, tried again the next week) while an image build is queued or running, or when the control plane
  can't tell; `--force` runs it anyway. `FALAK_REGISTRY_GC=0` removes the cron entry. Output goes to `/var/log/falak-registry-gc.log`.

**Mounted config files.** Some services read config files bind-mounted from `/opt/falak/observability/` and
`/opt/falak/deploy/` (`loki.yaml`, `tempo.yaml`, the gateway `Caddyfile`, Grafana provisioning and dashboards).
An update replaces those directories, but a running container keeps the files it was started with (the mount
holds the old file), and `docker compose up` only recreates services whose compose definition changed. So
after `compose up`, `falak-ctl` compares what each running container sees at its Falak mounts with the files on
disk and force-recreates exactly the services that differ:

```
==> mounted config files changed: recreating loki
  ✓ recreated loki
```

The same check runs on `falak-ctl up`, after a restore and during a rollback. Run it on its own with
`falak-ctl reload-configs`, for example after editing `/opt/falak/observability/loki/loki.yaml` by hand (such
edits are replaced by the next update). falak-ctl v0.2.5 and older did not do this, so Loki could keep the previous
`loki.yaml` (access logs in **Network Logs** were then not queryable). An update is run by the falak-ctl that is
already installed, so after updating *from* v0.2.5 or older run `falak-ctl reload-configs` once; it fixes such
containers.

**Upgrading from 0.2.x with the thread hotfix.** If you added `FRANKENPHP_CONFIG=num_threads 24` to
`/opt/falak/custom.env`, the update keeps working: a thread count in `FRANKENPHP_CONFIG` still wins over the
automatic sizing (the containers log a notice). It is no longer needed, because agents now long-poll their own
`agent-api` service (see [Performance](#performance-php-threads-and-worker-mode)). Remove the line, then run
`falak-ctl up`. `falak-ctl doctor` reports it until you do.

**Upgrading to v0.9.0: database references no longer use public or provider-only addresses.** `${{ db.DB_HOST }}`
and `${{ db.DATABASE_URL }}` of a PostgreSQL / MySQL / MariaDB database on a **dedicated database server** used to
fall back to that server's provider private IP, then its public IPv4 / IPv6, when the site's servers shared no Falak
private network with it. From v0.9.0 they follow the Redis / Valkey rules: a Falak private network (WireGuard) first,
else the provider private network only where Falak knows the servers share it (DigitalOcean, Lightsail: same provider
credential and region) — never a public address. So references to a dedicated database server reached over its
**public IP**, or over a **Hetzner, Vultr or Linode private IP** (opt-in networks Falak can't verify), or between
custom servers, **no longer resolve**, and the next deploy of those sites fails with "… shares no private network
with <server>, and database references never point at a public address". Before you deploy after the update:

1. open the project canvas: such references are drawn in amber with a "!" mark that gives the reason (the site's
   **Variables** tab lists them too, under "Unresolved references");
2. add the database server and the site's servers to a private network (**Network → Private networks**), wait until
   it is applied, and deploy. (Or set the host by hand in the site's variables instead of a reference.)

Engines on app / worker servers are unaffected for native sites (`127.0.0.1`); containers on that server now get the
Docker bridge address (`172.17.0.1`, or `FALAK_DOCKER_BRIDGE_HOST`) instead of the server's own address.

**Upgrading to v0.10.0: secrets move from `APP_KEY` to the key-encryption key.** Every encrypted column is
re-encrypted once by a migration during the update (see [Encryption keys](#encryption-keys)): it decrypts each value
with `APP_KEY` and seals it under a new data key. Values already converted are skipped, so an interrupted migration
can run again. There is no fallback afterwards: keep `APP_KEY` unchanged until the update has finished. The KEK file
must exist before v0.10.0 starts, and the falak-ctl that runs the update is the one already installed. Install the
v0.10.0 falak-ctl first; it creates the KEK during the update. The `agent-api`, `horizon`, `reverb` and `scheduler`
services wait until the `control-plane` service has run the migrations:

```bash
curl -fsSL https://github.com/falak-sh/falak/releases/download/v0.10.0/falak-ctl -o /usr/local/bin/falak-ctl
chmod 755 /usr/local/bin/falak-ctl
falak-ctl update --version v0.10.0
falak-ctl kek export /root/falak-emergency-kit.txt   # then store it offline and delete it here
```

If you update with an older falak-ctl, the containers refuse to start without the KEK and the update rolls back.
That update has already installed the new falak-ctl, so running `falak-ctl update` again then works. Backups taken
before v0.10.0 hold `APP_KEY` ciphertexts and restore as before: the migration converts them again.

If step 3, 4 or 5 fails, `falak-ctl` **rolls back automatically**. It restores the previous deploy files and
`FALAK_VERSION`, restores the database, storage and Fleet CA from the pre-update backup (the new migrations
may already have run), and starts the previous version again.

### Upgrading to v0.10.0: databases in containers

v0.10.0 runs every managed database in a container and no longer manages the PostgreSQL, MySQL, MariaDB, Redis and
Valkey engines earlier versions installed on servers. They keep running, but Falak forgets them: no backups, no
references. The update's migration refuses to run while those databases are registered, and nothing changes:

```
Falak v0.10 runs every database in a container and no longer manages the host databases of earlier versions …
```

1. Back up every database you need (a dump of each, kept outside Falak).
2. Set `FALAK_DROP_LEGACY_DATABASES=1` in `/opt/falak/custom.env` and run `falak-ctl update` again. The old rows, their
   backup history and their canvas services go (references to them fail until they point at new services).
3. Create database containers (canvas → Create → Database) and restore your dumps into them, then remove the flag.

### Upgrading to v0.10.0: resource limits

Services can have memory, CPU, process, restart, log and OOM limits (a site's or compose service's Settings →
Resources, a worker's or daemon's form). The upgrade sets none: **every existing site, compose service, worker and
daemon keeps running without limits**, in production and elsewhere, until you set them. Only services created after
the upgrade outside a production environment start with that environment's defaults (512 MB, 1 CPU, 512 processes,
20 MB × 3 log files; `FALAK_LIMITS_NONPROD_*` in `/opt/falak/custom.env`), written on the service when it is created —
changing the defaults later, or moving a service, never changes the limits of services that exist. A PHP-FPM site
that gets memory, CPU or process limits moves into its own PHP-FPM master (a short restart of its pool).

### Upgrading to v0.10.0: disaster recovery

- `FALAK_BACKUP_PASSPHRASE` and `FALAK_BACKUP_S3_*` are no longer read from `.env`: run `falak-ctl dr setup`
  with the same bucket and passphrase (it stores them in `/opt/falak/dr/dr.env`, root only), then delete them
  from `.env`. Encrypted backups taken before need that passphrase.
- The cron line from earlier docs (`/etc/cron.d/falak-backup`) is replaced by `falak-backup.timer`: remove it.
- Backups now also hold `edge-pki`; restoring an older backup keeps this host's.
- Backups are `*.fdr` now (encrypted and authenticated). Older `*.tar.gz.enc` backups restore from local files only
  (no MAC: with a warning), never from the bucket: take a new backup (`falak-ctl backup --upload`) after upgrading.
- Single-organization installs need nothing; on installs with several organizations, set `FALAK_DR_ORGANIZATION`
  (the operator organization's id) in `.env` and run `falak-ctl up`, or nobody sees the control plane's DR.
- The PHP containers mount `/opt/falak/state` read-only (`state/dr.json`, written by falak-ctl, no secrets).

### Upgrading the server agents

An update does not touch your servers: each keeps running its `falak-agent` until you upgrade it. The new
control-plane image ships the matching agent build (`/install/agent/linux-{amd64,arm64}`), and after an update
`falak-ctl update` prints how many agents are older. **Servers** shows each agent's version with *update
available*; organization admins update one server (**Update** next to the version in the list, or **Update agent**
on the server page, `POST /api/v1/servers/{server}/agent/upgrade`), the servers they tick in the list (**Update
selected**), or every outdated one (**Update all agents**).

The agent downloads the build from the panel, verifies its SHA-256, checks that it runs (`falak-agent version`),
swaps it atomically (the previous binary stays as `/usr/local/bin/falak-agent.prev`), restarts, and reports the new
version and binary checksum in its next heartbeat. Supervised programs restart with it (`KillMode=mixed`).
A bulk update upgrades `FALAK_AGENT_UPGRADE_BATCH_SIZE` servers at a time (default 2) and stops at the first
failure; an upgrade fails when the agent has not come back with the new build within `FALAK_AGENT_UPGRADE_TIMEOUT`
seconds (default 600). Failures raise the *Agent upgrade failed* alert. To roll a server back by hand:
`mv /usr/local/bin/falak-agent.prev /usr/local/bin/falak-agent && systemctl restart falak-agent`.
`falak-ctl artisan falak:agents` shows the shipped build and the number of outdated agents.

Commands in flight during an agent restart are not lost: each agent process has a session id, and commands
delivered to the previous process are delivered again (Caddy routes, telemetry, processes, cron, firewall and other
`*.apply` state) or fail with "The agent restarted before running the command" (deploy steps, scripts). A command the
agent never acknowledges is handled the same way after `FALAK_AGENT_COMMAND_LEASE` seconds (default 90). Agents
before this release get the new behaviour after their next upgrade; until then the lease covers them.

### Performance: PHP threads and worker mode

The web tier is two FrankenPHP services built from the same image, each with its own PHP thread pool:

| Service | Serves (edge routing, both hosts) | PHP mode | Threads |
|---|---|---|---|
| `control-plane` | the panel and the REST API: everything not listed below | **worker mode**: Laravel boots once per thread (Laravel Octane's FrankenPHP worker) | `FALAK_PHP_WORKERS` workers (default 2 × CPUs), autoscaled up to `FALAK_PHP_MAX_THREADS` (default max(8, 4 × CPUs)) |
| `agent-api` | `/agent/*` (agents), `/install/*` (installer), `/api/internal/*` (builders, artifacts) | classic (one boot per request) | 32 started, autoscaled up to `FALAK_AGENT_API_THREADS` (default 128) |

The split matters because each agent and each builder holds a 30-second long-poll open all the time (it
returns as soon as a command or build is queued). A waiting long-poll occupies one PHP thread. Up to
v0.2.x, the panel and the agents shared FrankenPHP's default pool of 2 × CPUs threads. On a 2-vCPU host that
is 4 threads, so three servers plus the builder took all of them, and every page queued for 5–11 s behind
the long-polls. Now the panel's threads serve only people. A waiting long-poll costs about 2–3 MB and no
CPU, and it does not hold a database connection (agents wait on Valkey).

Sizing (in `/opt/falak/.env`, then `falak-ctl up`):

- `FALAK_AGENT_API_THREADS`: at least *managed servers + builders + 8*. The default of 128 covers about
  120 servers within the 512 MB limit of `agent-api`. For bigger fleets, raise it together with that
  limit (about 3 MB per thread).
- `FALAK_PHP_WORKERS` / `FALAK_PHP_MAX_THREADS`: the defaults suit 2–8 CPUs. Each panel worker keeps a
  booted app, about 12 MB.
- `FALAK_WORKER_MODE=0`: fallback to classic mode for the panel. It is about 2–4× slower per request but
  keeps no state between requests. `FALAK_PHP_THREADS` then sets the starting thread count.
- Each container logs its pool at start, e.g. `falak: web: PHP worker mode, 4 workers, num_threads 2
  max_threads 8`. `falak-ctl status` shows live usage (`PHP threads: panel 1/8 busy · agent-api 5/128
  busy`), and `falak-ctl doctor` flags a pool that is ≥ 80 % busy or saturated.

Measured on the production image with 2 pinned CPUs, Postgres and Valkey. Panel TTFB is the median of 20
requests. Before = v0.2.0 (one shared pool of 4 threads).

| | before, idle | before, 4 long-polls | before, 10 long-polls | after, idle | after, 50 long-polls |
|---|---|---|---|---|---|
| `/up` | 5.4 ms | 7.6 s | timeout (> 15 s) | 1.8 ms | 2.7 ms |
| `/login` | 11 ms | 11.1 s | timeout | 3.9 ms | 3.5 ms |
| `/servers` | 32 ms | 11.1 s | timeout | 16.6 ms | 16.2 ms |
| project canvas | 38 ms | 11.0 s | timeout | 23 ms | 23 ms |

Throughput with 16 concurrent clients: `/login` 212 → 978 req/s and the canvas 66 → 119 req/s (classic
→ worker). The image also ships `config:cache`, `route:cache`, `event:cache` and `view:cache` at start, an
authoritative Composer classmap, and OPcache without timestamp checks and with a 4 MB realpath cache. The
CLI (Horizon, Reverb, scheduler, healthchecks) gets an OPcache file cache, which cuts an artisan boot from
86 to 40 ms with no extra RAM. JIT stays off: tracing JIT measured within noise (+2–7 %) for +15 MB.

## 6. Backup and restore

```bash
falak-ctl backup                          # -> /opt/falak/backups/falak-backup-<UTC timestamp>.tar.gz
falak-ctl restore /opt/falak/backups/falak-backup-20260101T030000Z.tar.gz --yes
```

A backup contains:

- `db.dump`: `pg_dump -Fc` of the database;
- the `falak-ca` volume (Fleet CA certificate and agent API certificate), `app-storage` (build artifacts,
  app files), `caddy-data` (ACME account and certificates) and `edge-pki` (the edge's client certificate for
  the agent API); `registry-data` (the built-in registry's images) with `--include-registry` or
  `falak-ctl dr setup --include-registry`;
- `.env` and `custom.env`;
- the key-encryption key (`secrets/kek`, and `kek.previous`), **only when the backup is encrypted with the DR
  passphrase**. Without a passphrase the backup warns that it can't be decrypted without the KEK: keep the
  emergency kit (`falak-ctl kek export`) apart from the backups;
- a `manifest`: Falak version, image digests, volumes, KEK id(s), row counts of the main tables (drills compare
  them).

> **The Fleet CA is critical.** Every agent trusts only this CA, and its private key is stored in the database,
> sealed under the KEK. A backup is only useful with its **database, `.env` and the KEK together**. If you lose
> any of them, every server must be re-enrolled and every secret entered again. Keep copies **off the host**.

- Retention: the newest `FALAK_BACKUP_KEEP` backups are kept on the host (default 14). Expire old uploads with
  a lifecycle rule on the bucket, and turn on versioning or object lock there.
- **Off-site, encrypted, on a schedule: `falak-ctl dr setup`.** It asks for an S3-compatible bucket (endpoint,
  bucket, region, keys, prefix) and a DR passphrase, tests the bucket, and installs `falak-backup.timer`
  (every 6 h by default, `falak-ctl backup --upload --scheduled`) and a monthly `falak-drill.timer`. The settings
  live in `/opt/falak/dr/dr.env` (root only, never mounted into a container, never in a backup). Uploads are always
  encrypted and authenticated (`*.fdr`: AES-256 then HMAC-SHA256, PBKDF2 with 600 000 iterations; see
  DISASTER_RECOVERY.md "Backup format"); `backup --upload` without the passphrase is refused. `falak-ctl dr status` and Settings → Disaster recovery show the last
  backup, its age and size, and the last drill. Until it is configured the panel shows its owners and admins a
  banner, `doctor` warns and a weekly `dr.not_configured` alert goes out.
- **Restore drills:** `falak-ctl dr drill` downloads the latest upload, restores it into a throwaway compose
  project (`falak-drill`: its own directory, volumes, network and subnet; postgres, valkey and the control plane
  only, so nothing talks to your servers), checks the migrations, that the KEK unwraps every data key and the row
  counts, then removes it.
- `restore` takes a file, a name in `backups/`, `s3://latest` or `s3://<backup name>` (downloaded, authenticated
  with the DR passphrase before it is decrypted; only `.fdr` objects).

**Move to a new host, or the host is lost:** see [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md). In short, on a
fresh VPS with the same `--domain`:

```bash
curl -fsSL https://falak.sh/install.sh | sudo bash -s -- --domain falak.example.com --email you@example.com \
  --restore-from s3://latest      # asks for the bucket settings and the DR passphrase
```

then point DNS at the new host. The restore brings back the old `.env` (including `APP_KEY`), the KEK, the Fleet
CA and the edge PKI, so agents reconnect without re-enrolling. With a local file instead: install, copy it over,
`falak-ctl restore <file> --yes`. For an unencrypted backup, import the KEK first:
`falak-ctl kek import <emergency kit> --force`.

## 7. Change the domain

```bash
falak-ctl domain set falak.new-example.com [--keep-old]
```

This command:

- takes a backup;
- rewrites the URLs in `.env`;
- re-issues the agent API certificate for `agents.<new>`, keeping the **old agents host** as an alias,
  because enrolled agents keep calling the API host they enrolled with. Keep that old DNS record pointing
  here;
- moves the built-in registry to `registry.<new>`, keeping the **old registry host** as an alias: images of
  earlier releases are pinned to the old name, so keep its DNS record too (a rollback to a release built before
  the move may need a rebuild, because servers get credentials for the current registry name only);
- with `--keep-old`, redirects the old panel domain to the new one.

Create the new DNS records first. Users of the `falak` CLI need to run `falak login --url https://<new>` again.

## 8. Uninstall

```bash
falak-ctl backup                                   # optional, then copy it off the host
cd /opt/falak && docker compose -p falak --env-file .env -f deploy/compose.yml --profile observability down -v
rm -rf /opt/falak /usr/local/bin/falak-ctl /etc/cron.d/falak-backup
```

`down -v` deletes every volume: the database, the Fleet CA and certificates. Leave out `-v` to keep the
data. Managed servers keep running. Remove the agent there with `systemctl disable --now falak-agent`.

## 9. Troubleshooting

| Symptom | Check / fix |
|---|---|
| Installer: `DNS does not point at this host` | Create the printed A/AAAA records and wait for propagation (`dig +short falak.example.com`). With Cloudflare, use DNS only. |
| Installer: `port 80 is in use` | Stop the other web server, e.g. `systemctl disable --now nginx apache2 caddy`. |
| Browser shows a certificate error | `falak-ctl logs edge` and look for ACME errors. Ports 80/443 must be reachable from the internet (cloud firewall / security group). Let's Encrypt rate limits apply to repeated reinstalls. |
| Stack not healthy | `falak-ctl status`, `falak-ctl logs control-plane`. A migration error shows in the `control-plane` logs. |
| Server install command fails to enroll | The server must reach `https://<domain>` (system CAs) **and** `https://agents.<domain>` (Fleet CA). Run `falak-ctl doctor`. The agent API must answer `401` without a client certificate. The install command checks both before it changes anything, and stops if the server's clock is more than 5 minutes off (`timedatectl set-ntp true`). |
| Install command ends with `falak-agent is installed but not connected` | It runs `falak-agent check --wait 60s`, which prints the reason: revoked identity, agents host unreachable, TLS error or clock. Run `sudo falak-agent check` again at any time; logs: `journalctl -u falak-agent`. |
| New server stays **Waiting for agent** after you deleted the old one and reinstalled on the same machine | From v0.5.2 a new install command replaces the old identity: the old files move to `/etc/falak/previous/<UTC time>/`. The agent logs `this agent was revoked or its server was removed from Falak` while it still has a deleted server's identity. Install commands from Falak before v0.5.2 enroll only when `/etc/falak` has no identity, so they keep the deleted server's certificate and get `401`. On such a machine: `sudo systemctl stop falak-agent && sudo mkdir -p /root/falak-old && sudo mv /etc/falak/agent.key /etc/falak/agent.crt /etc/falak/ca.crt /etc/falak/agent.json /root/falak-old/`, then run a freshly generated install command. |
| Provisioning step `apt`, `caddy` or `php:<version>` fails on `apt-get update` | The error names the repository and its file under `/etc/apt/sources.list.d`; fix or remove that file and retry. A `ppa:ondrej/php` source without a release for the server's Ubuntu (e.g. 26.04) is disabled automatically (`<file>.disabled-by-falak`). Without the PPA, PHP comes from Ubuntu's archive: on Ubuntu 26.04 that is PHP 8.5 only, and a server planned with another version gets 8.5 instead (the server's status says so). |
| Server shows **Needs attention** | The machine check (v0.6.0 agents) found software Falak won't change on its own and installed nothing. The server page's *Machine check* panel lists each conflict with its fix; after fixing, click **Re-check**, then **Provision**. The rows below are the conflicts it reports. Agents before v0.6.0 skip the check: update the agent and Re-provision to use it. |
| Machine check: `Port 80/443 is in use by nginx` (apache2, …) | Another web server holds the edge's ports: `systemctl disable --now nginx` (or move it to other ports), then Re-check. Falak's edge (falak-edge) serves 80 and 443. |
| Machine check: `caddy.service is running` | Falak would stop it for falak-edge. Move the sites it serves into Falak, `systemctl disable --now caddy`, Re-check. |
| Machine check: `A container (…) publishes port 5432/3306/6379/80` | A container holds a port Falak's engine or edge needs: `docker stop <name>` or publish it on another port, then Re-check. To keep the database in Docker, add it to Falak as a compose service instead of choosing the engine for the server. |
| Machine check: `MariaDB … is installed, but this server is set up for MySQL` (or the reverse, Redis ↔ Valkey, Percona) | Falak won't run two engines of a kind on one machine. Remove the other one (`apt purge mariadb-server`) or create the server in Falak with the engine that is installed (it is then used as is). |
| Machine check: `… is older than …, the oldest Falak supports` | Upgrade the engine or Docker from the same source to at least the minimum (`servers.machine_check.minimum_versions`: Docker 20.10, PostgreSQL 14, MySQL 8.0, MariaDB 10.6, Redis 6.0, Valkey 7.2, what Falak installs on Ubuntu 22.04), then Re-check. |
| Machine check: `Docker from Docker's repository has no compose (buildx), and that repository is not configured` | Falak completes a Docker install only from its own source, never with Ubuntu's packages (they overwrite each other's files). Add Docker's apt repository (docs.docker.com/engine/install/ubuntu) or install `docker-compose-plugin` / `docker-buildx-plugin`, then Re-check. |
| Machine check: `… has no compose, and Falak does not know where this Docker came from` | A Docker engine that is no known package (static binary, other vendor): install the compose / buildx plugin next to it, then Re-check. |
| Machine check: `docker.service is masked` / `Only the Docker CLI is installed` | `systemctl unmask docker.service docker.socket` if Docker should run, or install the engine from the CLI's source (`docker-ce` from Docker's repository); or remove the CLI (`apt purge docker-ce-cli`) and Falak installs Ubuntu's Docker. Then Re-check. |
| Machine check: `Docker is installed as a snap` / `Only a rootless Docker is set up` / `podman-docker provides the docker command` | Falak needs the system Docker daemon from apt. `snap remove docker` (or set up the system daemon, or `apt purge podman-docker`), then Re-check; Falak then installs Docker, or keeps one you install from Docker's repository. |
| Machine check: `Password login would be turned off, but no user who may log in over SSH has a key` | Falak turns off SSH password and keyboard-interactive login. Add your public key to `~/.ssh/authorized_keys` of a sudo user sshd lets in. Root's keys only count when root may log in (`PermitRootLogin` not `no` / `forced-commands-only`), and `AllowUsers` / `DenyUsers` / `AllowGroups` / `DenyGroups` apply. Then Re-check. |
| Re-provision of an active server says `Re-provisioning stopped. Machine check: …` | The server keeps running as it is; nothing was applied. Fix the listed conflicts (Machine check panel), then Re-provision again. |
| Machine check warnings (provisioning goes on) | `ufw`/`firewalld` active: a port must be allowed by both, e.g. `ufw allow 80,443/tcp`. An earlier `sshd_config.d` file (e.g. `50-cloud-init.conf`) wins over Falak's `50-falak.conf`. `daemon.json` `"iptables": false` breaks published ports. Existing swap, hostname, fail2ban jails and unattended-upgrades config are kept. |
| Provisioning step `adopt:<component>` fails: `… is no longer installed; run the machine check again` | A package the machine check found was removed since. Re-provision: the check runs again first. |
| Agents go offline after a restore | The restored `.env` / `APP_KEY` must belong to the same backup as the database. The `falak-ca` volume is re-synced by the edge within 3 seconds. |
| Live updates in the UI don't refresh | Check that the `reverb` service is healthy. Browsers connect to `wss://<domain>/app/…` through the edge. |
| `FALAK_EDGE_SUBNET ... overlaps` | Pick another private /24 in `.env` and re-run the installer. The edge gets the subnet's `.250` address (`FALAK_EDGE_IP`, set by `falak-ctl ensure-host`/`up`) and the app trusts proxy headers only from it. |
| Builds stay queued | `falak-ctl logs builder`. The builder polls `https://<domain>` with `FALAK_BUILDER_TOKEN`. Docker-mode builds need a `builder` server: the bundled builder does native builds only (`FALAK_LOCAL_BUILDER_MODES=native`, the default). |
| Docker build fails at push (`lookup registry.falak.local … no such host`, `401`, `x509`) | The install has no built-in registry yet or its DNS is missing: run `falak-ctl up` (adds `FALAK_REGISTRY_*`), create the `registry.<domain>` record, then `falak-ctl registry status`. `x509` with `--tls internal`: see section 2. |
| Panel slow (seconds per page) | `falak-ctl doctor`, section *PHP threads*. A saturated `agent-api` pool delays agents, not the panel. Raise `FALAK_AGENT_API_THREADS` (or `FALAK_PHP_MAX_THREADS` for the panel) in `.env`, then `falak-ctl up`. See [Performance](#performance-php-threads-and-worker-mode). |
| Something only breaks in worker mode | Set `FALAK_WORKER_MODE=0` in `.env`, run `falak-ctl up` and report it. The panel then boots Laravel for every request (classic mode). |
| Low memory | Lower `FALAK_HORIZON_MAX_PROCESSES` in `.env`, or move observability to its own host. |

## 10. Testing the installer locally (`--tls internal`)

`--tls internal` makes Caddy issue certificates from its own local CA and skips the DNS check. It also
allows custom ports, for example `--http-port 8080 --https-port 9443 --domain falak.test`. Reach the panel with
`curl -k --resolve falak.test:9443:127.0.0.1 https://falak.test:9443/up`. Do not use internal TLS in
production: server install scripts and agents would not trust the panel certificate.

Building images yourself (the release workflow does the same, multi-arch):

```bash
docker build -f control-plane/Dockerfile --build-arg FALAK_VERSION=dev -t falak-local/falak-control-plane:dev .
docker build -f deploy/builder.Dockerfile --build-arg FALAK_VERSION=dev -t falak-local/falak-builder:dev .
docker build --build-arg FALAK_VERSION=dev -t falak-local/falak-edge:dev deploy/edge
```

## 11. Releasing (maintainers)

Push a tag `vX.Y.Z` (`vX.Y.Z-rc.N` for pre-releases, which are not tagged `latest`). The
`.github/workflows/release.yml` workflow then:

- builds `falak-control-plane`, `falak-builder` and `falak-edge` on native amd64 and arm64 runners and pushes
  multi-arch manifests to `ghcr.io/<owner>/…:<tag>` and `:latest`;
- builds `falak-agent`, `falak` and `falak-builder` with `make build`;
- creates the GitHub release with the binaries, `falak-deploy.tar.gz`, `install.sh` (pinned to the tag),
  `falak-ctl` and `SHA256SUMS`.

It uses only `GITHUB_TOKEN`. After the first release, make the three GHCR packages **public** so that hosts can
pull them anonymously.
