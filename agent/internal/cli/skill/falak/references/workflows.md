# Falak workflows

Recipes for common jobs. Each assumes `FALAK_URL` and `FALAK_TOKEN` are set (or `falak login` was run) and that
`falak whoami` works. `jq` is optional; `--json` output is plain JSON. Steps marked **(ask)** need the human's
explicit go-ahead (see [safety.md](safety.md)).

For raw API calls, define once:

```bash
api() { curl -fsS -H "Authorization: Bearer $FALAK_TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" "$@"; }
# api "$FALAK_URL/api/v1/projects"
```

## 1. Deploy an app from a repository

### a. The site already exists

```bash
falak sites show shop --json            # check runtime, branch, servers, current release
falak deploy shop --wait                # exit 0 = succeeded, 3 = failed
```

From CI, the same with `FALAK_URL`/`FALAK_TOKEN` (a token with `sites.view`, `deployments.view`,
`deployments.create`). A specific branch: `--branch`. A failed deployment: section 9.

### b. A new app with config as code (recommended)

```bash
cd <repo>
falak init --stdout --yes               # preview what Falak detects (runtime, framework, env, databases)
falak init --yes -p shop                # write falak.yaml and link the repo to project "shop" (.falak/project.json)
# edit: environments, servers (names from `falak servers list`), domains
falak validate                          # fix every error (exit 5) before going on
```

`falak init --yes` without `-p` writes the file but links no project: then pass `-p <project>` or set
`FALAK_PROJECT` on every command. `init` refuses to overwrite an existing file (`--force` replaces it: ask first).
For each `${{ secrets.NAME }}` in the file, have the human provide the value (section 5).

```bash
falak plan -p shop -e production        # show the plan to the human
falak apply -p shop -e production --wait   # (ask) exit 6 = waits for an approval: the human approves
```

To deploy on every push instead, the human (or you, with `config.apply`) connects the repository:

```bash
api "$FALAK_URL/api/v1/source-control/connections" | jq '.data[] | {id, name}'
falak config source set -p shop --repo acme/shop --connection <id> --enable
```

A brand-new project hosts nothing, so the file can't place units on any server until an admin allows them:
`falak config source set -p shop --allow-server app-1` (admins only).

### c. Adopt a project that already runs

```bash
falak config export shop -e production -o falak.yaml   # today's state; applying it as is changes nothing
falak plan -p shop -e production                       # expect an empty plan
```

### d. A site through the API (no falak.yaml)

`POST /api/v1/sites` with `name`, `framework`, `server_ids`, `runtime`, `source_connection_id`, `repository`,
`branch`, optional `domain` and `project_id`/`environment_id` (see api.md), then `falak deploy <slug> --wait`.

## 2. Deploy a template (n8n, Ghost, Plausible, …)

There is no CLI command; use the API. Needs `templates.view`, `projects.manage`, `sites.create` (and
`deployments.create` for the first deploy to start).

```bash
api "$FALAK_URL/api/v1/projects" | jq '.data[] | {id, name, environments: [.environments[].slug]}'
falak servers list --json | jq '.[] | select(.status=="active") | {id, name}'
api -X POST "$FALAK_URL/api/v1/projects/<project-id>/production/templates/n8n/deploy" \
  -d '{"server_ids": ["<server-id>"], "name": "n8n", "domains": {"n8n": {"type": "generated"}}}'
```

- `inputs`: `{KEY: value}` for the template's inputs (`templates/<slug>/template.yaml`). Inputs with `generate:` are
  generated when omitted; required ones without a default must be sent (`422 inputs.<KEY>` otherwise). Never print
  generated secret values back.
- `domains`: per public service, a name (`"n8n.example.com"`) or `{"type": "generated" | "test" | "custom", "name"?}`.
- The answer has `site_id`, `deployment_id`, `domains`, `panel_url` and `warnings`. Follow the deployment:
  `falak deploy` is not needed; poll `GET /api/v1/deployments/<deployment_id>` or watch `falak logs <slug>`.
- `stateful` templates warn when deployed to more than one server: use one.

## 3. Databases, backups and restore

Declare databases in falak.yaml (preferred; see config.md):

```yaml
units:
  db:
    type: database
    engine: postgresql
    version: "17"
    backups: { cron: "0 3 * * *", storage: offsite, keep: 14 }
  web:
    env:
      DATABASE_URL: ${{ db.DATABASE_URL }}
```

`backups.storage` is the **name** of a storage provider the project already uses (or an admin allowed). Then
validate, plan, apply.

Without falak.yaml: `POST /api/v1/projects/{project}/environments/{environment}/services` with
`{"kind": "database", "engine": "postgresql", "server_id": "…", "name": "shop"}`.

Inspect: `falak db list --json`, `api "$FALAK_URL/api/v1/databases/instances/<id>"` (status, health, version,
`pitr_enabled`; never a password). A shell in the container: `falak console --db shop` (Admin; audited) **(ask)**.

**Backups, restores, PITR, upgrades and password rotation have no token API or CLI.** They are dashboard actions
(Databases → the instance → Backups / Point-in-time recovery), and restores need `databases.restore` and overwrite
data. Tell the human exactly what to do; never try to restore by running SQL through a console.

Delete a database container **(ask; irreversible with `--delete-volume`)**:

```bash
falak db delete shop --confirm shop                     # container only; the data volume stays
falak db delete shop --confirm shop --delete-volume     # also destroys the data
```

A database a falak.yaml declares can't be deleted this way: remove it from the file (it is detached, never deleted
by a push).

## 4. Functions

```bash
falak fn list
falak fn pull hooks ./hooks             # writes the code and .falak-function.json (base version)
# edit ./hooks
falak fn deploy hooks ./hooks -m "Describe the change" --wait
falak fn invoke hooks /health           # exit 1 on HTTP >= 400
falak fn logs hooks --since 30m
```

- Exit `4` from `fn deploy`: someone deployed after your base. `falak fn pull hooks ./hooks` into a clean copy,
  merge, deploy again. `--force` overwrites their version **(ask)**.
- New files need `--yes` without a terminal: check the listed files first (no stray secrets; use `.falakignore`).
- Rollback: `falak fn versions hooks`, then `falak fn rollback hooks 3 --wait`.
- Run a schedule now: `falak fn run hooks nightly` (exits with the run's exit code).
- A function declared in falak.yaml (`type: function`) gets its code from the repository under `root` on each run:
  change the code there and push (or `falak apply`) rather than `fn deploy`.

## 5. Secrets

```bash
falak secret list -p shop --json                   # names only
op read 'op://vault/stripe/key' | falak secret set STRIPE_SECRET -p shop
falak secret set --from-env .env.production -p shop -e production
```

- Never put a value on the command line, in a file you commit, or in your output. If you need a value, ask the human
  to set it themselves.
- Setting an existing name adds a version: it changes production at the next deployment **(ask)** when it is used
  there. Rotation: set the new value, redeploy the units that use it, then revoke the old credential upstream.
- `falak secret delete NAME -p shop --yes` **(ask)**: deployments referencing it fail.
- A site's `.env` is separate from the secret store: `falak env pull shop --file .env.shop` (0600), edit, `falak env
  push shop --file .env.shop`, redeploy. `env push` replaces every variable **(ask in production)**; delete the local
  file afterwards.

## 6. Domains and the edge

- In falak.yaml: `domains:` on the unit (with `tls`, `www`, `primary`), `redirects:`, `headers:`, `mounts:`
  (config.md). Apply, then check DNS.
- DNS check (what to point where, and whether the certificate is issued):

```bash
api "$FALAK_URL/api/v1/dns/check?name=shop.example.com&site=shop&tls=1" | jq '.data | {status, message, instructions}'
```

  `status: ok` = every address is a target; `missing` / `mismatch` come with `instructions.records` to create;
  `proxied` = Cloudflare proxy in front (HTTP-01 fails until "DNS only" or Falak manages the zone).
- What a create form would offer (test domain, generated `sslip.io`-style name, targets):
  `api "$FALAK_URL/api/v1/domains/options?site=shop"`.
- Open a site: `falak open shop --json` prints its URL.

## 7. Logs, metrics, observability

```bash
falak logs shop --since 1h --level error --json | jq -r '.message'   # app logs, NDJSON
falak logs shop -f                                                  # follow
api "$FALAK_URL/api/v1/sites/shop/access-logs?since=3600&status=5xx&limit=100"   # per-request edge log
falak servers show web-1 --json                                     # load, memory %, disk %, agent heartbeat
api "$FALAK_URL/api/v1/servers/<server-id>/capacity"                # limits vs RAM/CPUs, overcommit warnings
api "$FALAK_URL/api/v1/deployments/<id>"                            # targets, steps, watch result
```

Metrics dashboards, traces, Insights issues and per-workload network/database insights are in the panel (and
Grafana); they have no token API. Release watch (automatic rollback after a bad release):
`GET|PUT /api/v1/sites/{site}/release-watch`.

## 8. Consoles and network tools (Admin, audited)

```bash
falak console shop                      # the site's container, or its release as the site user
falak console shop --service worker     # one compose service
falak console --db shop                 # database container
falak console --host web-1              # server shell (root by default)
```

Consoles are interactive and recorded: open one only when the human asked and knows what will run.

Network diagnostics run from where the workload runs:

```bash
falak net http https://api.stripe.com --site shop             # timings, TLS, status (curl -v)
falak net dns shop.example.com --server web-1 --type AAAA
falak net tcp 10.0.0.5 5432 --site shop --count 3             # can the site reach the database?
falak net ping 1.1.1.1 --server web-1
falak net traceroute example.com --db shop
```

Packet capture: `falak net capture --site shop --port 443 --duration 20 --yes -o shop.pcap` **(ask: captures can
contain credentials and personal data)**.

## 9. Debug a failed deployment

1. `falak deploy shop --wait` printed the failing phase (`[web-1 migrate] …`). Or: `api
   "$FALAK_URL/api/v1/deployments/<id>"` → the failed target's `steps[].error` and `exit_code`.
2. `falak logs shop --since 30m --level error`.
3. Status `waiting` isn't a failure: servers are still preparing (`waiting_reason`).
4. `Unresolved variable references: …` → a missing secret (`falak secret list`) or a service name typo.
5. Fix the cause in the code or config, then deploy once. If production is down and the human agrees, roll back:
   `falak rollback shop --wait` **(ask)**.

## 10. Config as code day to day

```bash
falak validate && falak plan -e production
falak apply -e production --wait               # (ask) for production
falak runs list --json | jq '.[0] | {id, status}'
falak runs show <run>
falak drift -e production                      # exit 7: something changed outside the file
falak config unmanaged                         # what removed units left running
```

A run waiting for an approval (exit 6): show the human the risky changes (`falak runs show <run>`); they approve
with `falak approve <run>` or in the dashboard. Only run `falak approve <run> --yes` yourself when told to approve
that run.

## 11. Sandboxes

### Try a risky command safely

Anything you aren't sure about (an install script, a migration tool, `rm -rf` in a script, an OS upgrade) goes into
a sandbox first; it can't touch the human's servers, sites or network.

```bash
falak sandbox create --image ubuntu:24.04 --name try --ttl 30m --json
falak sandbox exec try -- "curl -fsSL https://example.com/install.sh | sh"
falak sandbox exec try -- "some-tool --version; ls -la /usr/local/bin"
falak sandbox kill try
```

Nothing to clean up on the server; the sandbox's files go with it.

### Run an agent (or a long job) in a sandbox

Clone the repository into the sandbox, let the agent or the job work there, then take the result out.

```bash
printf '%s' "$GH_TOKEN" | falak secret set GH_TOKEN -p shop        # (ask) a token that can read the repo
falak sandbox create --image node:22 --name agent-1 --ttl 2h --memory 2g \
  --repo https://github.com/acme/shop@main --repo-secret GH_TOKEN --json
falak sandbox cp ./task.md sbx:agent-1:/root/task.md
falak sandbox exec agent-1 -- "cd /root/shop && nohup ./run-agent.sh /root/task.md > /tmp/agent.log 2>&1 &"
falak sandbox exec agent-1 -- "tail -n 50 /tmp/agent.log"         # poll; each exec waits at most 120 s
falak sandbox exec agent-1 -- "cd /root/shop && git diff" > agent.diff
falak sandbox extend agent-1 1h                                   # if it needs more time
falak sandbox kill agent-1
```

Where the repository is cloned depends on the server (look with `falak sandbox exec agent-1 -- "ls /root /workspace"`).
`ssh` is for people: `falak sandbox ssh agent-1` opens an interactive shell.

### Snapshot before a risky step, fork to try approaches in parallel

```bash
falak sandbox snapshot agent-1 --name before-refactor          # disk, memory and processes; agent-1 keeps running
falak sandbox fork before-refactor -n 3 --name try --ttl 30m    # try-1 … try-3, each from that exact moment
falak sandbox exec try-1 -- "cd /root/shop && ./approach-a.sh && npm test"   # one approach per fork
falak sandbox exec try-2 -- "cd /root/shop && ./approach-b.sh && npm test"
falak sandbox kill try-2 try-3                                   # keep only the winner
falak sandbox snapshots rm before-refactor                       # snapshots stay until deleted
```

A snapshot with a name is a template: `falak sandbox create --from before-refactor`. `--idle-pause 10m` pauses a
sandbox nobody is using (any exec or file call resumes it). To test a migration on real-shaped data:
`falak sandbox create --db pg-main/shop`, then `pg_restore` the file under `/workspace/db/` inside it; use
staging or demo data where you can.

### Use the sandboxes from an MCP client

```bash
claude mcp add falak -- falak mcp                                 # Claude Code; any MCP client: command `falak mcp`
```

The tools mirror the CLI: `sandbox_create` (waits until it runs), `sandbox_exec` (`sh -lc`, ≤ 120 s),
`sandbox_read_file` / `sandbox_write_file` / `sandbox_list_files`, `sandbox_list`, `sandbox_extend`,
`sandbox_kill`, `sandbox_snapshot` (before a risky step), `sandbox_fork` (try approaches in parallel; kill the forks
you don't keep), `sandbox_pause` / `sandbox_resume`, `sandbox_list_snapshots`; `sandbox_create` takes
`from_snapshot` and `idle_pause`. The server uses the CLI's credentials; for an agent prefer a token with only `sandboxes.view` and
`sandboxes.use` (`FALAK_URL` + `FALAK_TOKEN` in the MCP server's environment:
`claude mcp add falak -e FALAK_URL=https://falak.example.com -e FALAK_TOKEN=… -- falak mcp`).
