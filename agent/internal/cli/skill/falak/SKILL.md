---
name: falak
description: Operate a Falak (falak.sh) self-hosted deployment platform through the `falak` CLI, the falak.yaml config-as-code file and the /api/v1 HTTP API. Use when the user wants to deploy, roll back or inspect sites, functions, compose apps, templates or database containers on Falak; write, validate, plan or apply a falak.yaml; manage Falak secrets or a site's .env; read site logs; open consoles; run network diagnostics; run commands, untrusted code or another agent in a disposable Falak sandbox (`falak sandbox`, `falak mcp`); or script Falak with an API token. Not for administering the control-plane host itself (that is `falak-ctl`, run as root on the host).
---

# Falak

Falak is a self-hosted Railway/Forge-style platform: a Laravel **control plane** (the panel and `/api/v1`), a Go
**agent** on every server, and the **`falak` CLI** that talks to `/api/v1` with a bearer token. Projects hold
environments; environments hold services (sites, compose stacks, functions, databases, Redis/Valkey).

Three ways to drive it, in order of preference for an agent:

1. **`falak` CLI** with `--json`: covers deploys, rollbacks, releases, `.env`, logs, functions, database containers,
   config as code, secrets, consoles, network tools and sandboxes. Every command is in
   [references/cli.md](references/cli.md).
2. **`falak.yaml`** (config as code): the declarative way to describe a project's units and environments, applied on
   push or with `falak apply`. Schema and fields: [references/config.md](references/config.md).
3. **HTTP API** (`/api/v1`, `Authorization: Bearer <token>`): for what the CLI lacks (creating sites, projects,
   template deploys, access logs, server checks). Endpoints: [references/api.md](references/api.md).

Step-by-step recipes: [references/workflows.md](references/workflows.md). **Before anything destructive, read
[references/safety.md](references/safety.md).**

## Ground rules

- **Never print, log or echo secrets.** Not tokens, not `.env` contents, not secret values, not database passwords.
  Don't run `falak env pull` to stdout or `cat ~/.config/falak/credentials.json`; don't pass tokens with `--token` on
  a command line that ends up in shell history or a transcript — use `FALAK_TOKEN` from the environment.
- **Ask the human before destructive or irreversible actions**: deleting a database container (especially with
  `--delete-volume`), `falak apply --allow-destroy`, approving a risky config run, deleting or overwriting secrets,
  `falak env push` (replaces the whole `.env`), rollbacks in production, packet captures, anything on the
  control-plane host (`falak-ctl restore`, `kek rotate`). Show the exact command first. Details in safety.md.
- **Read before you write.** `falak sites show X --json`, `falak plan`, `falak validate`, `falak drift` are free;
  look at the current state before changing it.
- **Prefer `--json`** for anything you parse. It prints the API's data as is (for `deploy --wait` the streamed
  output goes to stderr and the final deployment JSON to stdout; `falak logs --json` is NDJSON, one entry per line).
- **Use exit codes**, not text matching: `0` ok, `1` error, `2` usage, `3` deployment failed, `4` newer function
  version (`fn deploy`), `5` invalid falak.yaml, `6` waits for an approval (`apply`, `approve`), `7` drifted
  (`drift`). `falak net` tools exit with the tool's own code, `124` when stopped by their timeout.
- **You are not on a terminal.** Without a TTY the CLI never prompts: commands that would ask need an explicit flag
  (`--yes`, `--confirm NAME`) or read stdin. Only add `--yes` after the human agreed to that specific action.

## Setup and authentication

Check first: `falak version` and `falak whoami --json` (user, organization, role, and the token's name and
abilities). If `falak` is missing, ask the human to install it (`curl -fsSL https://falak.sh/install-cli.sh | sh`).

Credentials resolve in this order: flags (`--url`, `--token`) > env (`FALAK_URL`, `FALAK_TOKEN`) > the stored file
`<config dir>/credentials.json` (`FALAK_CONFIG_DIR`, else `~/Library/Application Support/falak` on macOS,
`$XDG_CONFIG_HOME/falak` or `~/.config/falak` on Linux; mode 0600).

- **Human at the keyboard**: they run `falak login --url https://falak.example.com` (browser device flow: a code,
  an approval page; `--no-browser` prints the link). Don't run it yourself unless they ask; it waits for their
  approval in a browser.
- **Agent / CI**: the human creates an API token in the panel (**Settings → API tokens**) with only the abilities
  needed, and exports `FALAK_URL` and `FALAK_TOKEN`. Or stores it: `printf '%s\n' "$FALAK_TOKEN" | falak login
  --url "$FALAK_URL" --token-stdin`.
- A token belongs to one organization. A request is allowed only when the token has the ability **and** the user's
  role grants it. `403` means one of them is missing: report it, don't work around it.

## Core workflows

### Inspect

```bash
falak whoami --json
falak servers list --json            # status: only "active" servers are deploy targets
falak sites list --json               # id, slug, domain, runtime, status, branch
falak sites show shop --json          # sites take an id or slug; servers an id or (unique) name
falak releases shop --json            # current release first ("active": true)
falak db list --json                  # database containers
falak fn list --json                  # functions
```

### Deploy and roll back a site

```bash
falak deploy shop --wait              # streams output; exit 3 if the deployment failed
falak deploy shop --branch develop --wait
falak deploy shop --json              # returns at once: {id, status, url, ...}; status may be "waiting" while servers prepare
falak rollback shop --wait            # previous release; --release <id> for a specific one (ask first in production)
```

On failure (exit 3): read the streamed output, then `falak logs shop --since 30m --level error`. Don't redeploy in a
loop; fix the cause.

### Environment variables and secrets

- A site's `.env`: `falak env pull shop --file .env.shop` (written 0600; never to stdout in a transcript), edit,
  `falak env push shop --file .env.shop`, then redeploy. **push replaces every variable** — always pull, edit, push.
- Secrets for `${{ secrets.NAME }}` references (falak.yaml or site variables): value from stdin, never an argument.

```bash
falak secret list -p shop --json                                  # names and versions, never values
printf '%s' "$VALUE" | falak secret set STRIPE_SECRET -p shop     # creates, or adds a version
falak secret set STRIPE_SECRET -p shop -e staging < value.txt     # environment scope
```

Get the value from the human or their secret manager (e.g. `op read … | falak secret set NAME -p shop`); never
invent or echo it.

### Config as code (`falak.yaml`)

```bash
falak init --stdout --yes             # preview a falak.yaml detected from the code (writes nothing)
falak init --yes                      # write it (refuses to overwrite; --force replaces)
falak validate                        # offline schema/merge/reference checks; exit 5 if invalid
falak plan -p shop -e production      # + create, ~ update, - delete, ! risky
falak apply -p shop -e production --wait   # exit 6 if it waits for an approval
falak runs list -p shop --json
falak drift -p shop -e production     # exit 7 if drifted
falak config export shop -e production -o falak.yaml   # adopt a running project
```

Project: `-p` (id or name), else `FALAK_PROJECT`, else `.falak/project.json` (written by `falak init` when a project
is picked). Environment: `-e`, else the one whose `branch` is the current git branch. Always `validate` and `plan`
before `apply`, and show the plan to the human. Risky changes wait for an approval by someone with
`config.approve`: let the human approve (`falak approve <run>` or the dashboard) unless they explicitly told you to.

### Logs and diagnostics

```bash
falak logs shop --since 1h --limit 200 --json      # NDJSON; levels: trace, debug, info, warn, error, fatal
falak logs shop --level error --since 24h
falak fn logs hooks --since 1h
falak net http https://api.example.com/health --site shop     # curl -v timing from where the site runs
falak net dns db.internal --server web-1 --type A
falak net tcp 10.0.0.5 5432 --site shop                      # can the site reach host:port?
```

`falak net` and `falak console` need Admin-level abilities (`network.tools`, `terminal.open`) and are audited.
Access logs (per-request status/latency) are only in the API: `GET /api/v1/sites/{site}/access-logs`.

### Functions

```bash
falak fn pull hooks ./hooks           # code + .falak-function.json (the base version)
# edit files
falak fn deploy hooks ./hooks -m "Add /health" --wait   # exit 4: someone deployed after your base: pull and merge
falak fn invoke hooks /health
falak fn versions hooks && falak fn rollback hooks 3 --wait
```

### Sandboxes (disposable machines for risky or untrusted work)

A sandbox is a throwaway Linux machine on one of the organization's servers, isolated by gVisor: root inside, but
nothing it does reaches the server, its sites or private networks (no cloud metadata, no host, no other sandboxes).
It has a time limit (default 1 h) and its files are gone when it is killed or expires. **Prefer a sandbox for
anything destructive, untrusted or experimental** (an install script from the internet, an upgrade you're unsure
about, code you were asked to run), and kill it when you're done.

```bash
falak sandbox create --image node:22 --name try-upgrade --ttl 30m --json    # waits until it runs; ids or names work below
falak sandbox exec try-upgrade -- "npm i -g some-tool && some-tool --version"   # one argument: sh -lc; exit code is the command's
falak sandbox cp ./script.sh sbx:try-upgrade:/root/script.sh
falak sandbox exec try-upgrade -- bash /root/script.sh
falak sandbox cp sbx:try-upgrade:/root/result.json ./result.json
falak sandbox kill try-upgrade
```

`exec` waits at most 120 s (`--timeout`); start longer jobs in the background (`nohup … > /tmp/job.log 2>&1 &`) and
poll the log. Output is capped at 256 KB per stream; files at 5 MB (no directories: tar them). Network: `internet`
(default; public internet only), `none`, or `allowlist --allow HOST`. Abilities: `sandboxes.view`, `sandboxes.use`.

**MCP**: `falak mcp` is a stdio MCP server with the same operations as tools (`sandbox_create`, `sandbox_exec`,
`sandbox_read_file`, `sandbox_write_file`, `sandbox_list_files`, `sandbox_list`, `sandbox_extend`, `sandbox_kill`),
using the CLI's login. Claude Code: `claude mcp add falak -- falak mcp`. Recipes: workflows.md §11.

## When the CLI can't do it

Use the API with `curl` and the same token (send `Accept: application/json`; responses are `{"data": …}`; `422`
carries `errors`). Examples: create a site (`POST /api/v1/sites`), create a project (`POST /api/v1/projects`),
deploy a template (`POST /api/v1/projects/{project}/{environment}/templates/{slug}/deploy`), DNS check
(`GET /api/v1/dns/check`). See [references/api.md](references/api.md). Database backups, restores, schedules, volume
backups and most server management have **no token API**: they are dashboard actions (or `backups:` in falak.yaml);
tell the human what to click instead of guessing an endpoint.

## Reporting back

Say what you ran, the result (deployment id and status, run id and plan summary, exit code), and links the API
returned (`url` on deployments and config runs). Never paste secrets, tokens or `.env` content into the report.
