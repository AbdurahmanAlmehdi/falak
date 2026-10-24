# `falak` CLI reference

Every command, flag and default below comes from the CLI source (`agent/internal/cli`) and its `-h` output.
`falak help` prints the list; `falak <command> -h` prints one command's flags.

## Global

```
falak [--json] [--url URL] [--token TOKEN] <command> [args]
```

| Flag / env | Meaning |
|---|---|
| `--json` | Print JSON (the API's data). Accepted before the command or among its flags |
| `--url URL` / `FALAK_URL` | Control-plane URL (`https://…`) |
| `--token TOKEN` / `FALAK_TOKEN` | API token. Prefer the env var: a flag lands in shell history |
| `FALAK_CONFIG_DIR` | Where `credentials.json` lives (default: `~/Library/Application Support/falak` on macOS, `$XDG_CONFIG_HOME/falak` or `~/.config/falak` on Linux) |
| `FALAK_PROJECT` | Default project (id or name) for `plan`, `apply`, `runs`, `drift`, `config export`, `secret`, `validate` |
| `--version` | Print the version (also `falak version`) |

Precedence: flags > env > stored credentials. Flags may come before or after positionals
(`falak deploy shop --wait`). A group without a subcommand runs its first one (`falak servers` = `servers list`,
`falak runs` = `runs list`, `falak config source` = `config source show`).

**Exit codes**: `0` ok · `1` error · `2` usage · `3` deployment failed · `4` newer function version (`fn deploy`) ·
`5` invalid falak.yaml · `6` waits for an approval (`apply`, `approve`) · `7` drifted (`drift`). `falak net …` exits
with the tool's own exit code, `124` when its timeout stopped it. `falak console` exits with the remote shell's
code (`130` when interrupted before connecting). `falak sandbox exec` exits with the command's code (`124` when its
timeout stopped it).

**Without a terminal** (an agent, CI): nothing prompts. `login` reads the token from stdin, `secret set` reads the
value from stdin, `secret delete`, `approve`, `reject` and `apply --approve` need `--yes`, `fn deploy` needs `--yes`
to include files new to the function, `init` accepts the defaults.

## Identity

| Command | What |
|---|---|
| `falak login [--url URL] [--token TOKEN \| --token-stdin] [--no-browser]` | On a terminal with no token: browser sign-in (RFC 8628 device flow): prints a code, opens the approval page (`--no-browser`: prints the link), waits up to the request's expiry (10 min). Otherwise stores a token: `--token`, `FALAK_TOKEN`, `--token-stdin`, or the first stdin line when stdin isn't a terminal. The token is checked with `GET /api/v1/me`, then saved (0600). `--json`: `{url, user, organization}` |
| `falak logout` | Deletes the stored credentials. A token minted by browser login (named `CLI — <host>`) is revoked on the server; a pasted token is kept (revoke it in Settings → API tokens) |
| `falak whoami` | User, organization, role; for tokens also the token name and abilities. `--json`: the `/me` payload |
| `falak orgs` | Organizations (a token sees only its own). Columns `ID NAME SLUG ROLE` |

## Servers

| Command | What |
|---|---|
| `falak servers list` | Columns `ID NAME TYPE STATUS AGENT IPV4 PROVIDER PHP` |
| `falak servers show <server>` | One server by id or unique name: status, message, provider, region, IPs, PHP, agent heartbeat, load, memory %, disk % |
| `falak servers stack <server> [--php VERSION] [--php-runtime fpm\|frankenphp\|cli] [--node VERSION]` | Add PHP and/or Node.js to a server created without them; **re-provisions it**. `--php-runtime` defaults to `cli` on builders, else `fpm`. At least one of `--php`/`--php-runtime`/`--node` |
| `falak ssh <server> [--user USER] [--private] [-- ssh args]` | `exec`s OpenSSH to the server (`--user` default: the server's ssh user, else `falak`; `--private`: the private IPv4). Interactive: not for agents |

## Sites and deployments

| Command | What |
|---|---|
| `falak sites list` | Columns `ID SLUG DOMAIN RUNTIME STATUS BRANCH` |
| `falak sites show <site>` | By id or slug: domain, aliases, URL, runtime, build mode, strategy, status, repository, branch, servers, current release |
| `falak deploy <site> [--branch BRANCH] [--wait]` | Trigger a deployment (`--branch` default: the site's). Without `--wait`: prints `Deployment <id> <status> for <site>` and the URL (`--json`: the deployment). `--wait`: streams output (`[server phase] line`) until done; exit `3` when it didn't succeed. With `--json --wait` the output goes to stderr and the final deployment JSON to stdout. Status `waiting` = servers still preparing; it starts on its own |
| `falak rollback <site> [--release ID] [--wait]` | Deploy a previous release (default: the one before the current). Same output and exit codes as `deploy` |
| `falak releases <site>` | Retained releases; `*` marks the active one. Columns `ID COMMIT BRANCH CREATED` |
| `falak open <site> [--panel]` | Print (and open in a browser) the site URL, or with `--panel` its page in the panel. `--json`: `{url}` |

## Environment file

| Command | What |
|---|---|
| `falak env pull <site> [--file PATH]` | Download the site's `.env` to stdout, or to `--file` (mode 0600). `--json`: `{content}`. **Contains secrets**: use `--file`, never stdout in a transcript |
| `falak env push <site> [--file PATH]` | Upload a `.env` from `--file` or stdin. **Replaces all variables**. Refuses an empty file. Takes effect on the next deployment |

## Logs

| Command | What |
|---|---|
| `falak logs <site> [--follow\|-f] [--since 1h] [--limit N] [--level LEVEL]` | Site logs (app logs and edge access lines). `--since` is a Go duration (`30m`, `24h`; default `1h`; the API caps it at 30 days), `--limit` per request (default 200, max 1000). `--level` is a minimum level the API accepts: `trace`, `debug`, `info`, `warn`, `error`, `fatal` (`warning` is taken as `warn`). `--follow` polls every 2 s. `--json`: NDJSON `{at, level, source, server, message, attributes}` per line |
| `falak fn logs <fn> [--follow] [--since 1h]` | The same for a function (same flags as `logs`) |

## Consoles (shells through the terminal relay)

```
falak console <site> [--server NAME|ID] [--service NAME]
falak console --db <instance>
falak console --host <server> [--user NAME]
falak console --sandbox <sandbox>           (same as falak sandbox ssh)
```

Exactly one target. A site opens in its container (Docker), the named compose `--service` (required when the stack
has several), or its current release as the site user (native runtimes); `--server` picks one of the site's servers
(default: the leader). `--db` opens the database container; `--host` a server shell (`--user` default `root`). Needs
`terminal.open` (Admin by default); every session is audited and recorded. Functions have no console. The relay may
be off on an installation (the error says so). It is an interactive shell: stdin piped from a non-terminal works,
but prefer the API/CLI read commands, and never run a console command the human hasn't approved.

## Network tools (audited, `network.tools`, Admin by default)

Run **from** a server's host network, a site's container (or host for native sites), or a database container:

```
WHERE := --server NAME|ID | --site SITE [--on SERVER] [--service NAME] | --db INSTANCE   [--timeout S]
falak net ping <host> [--count N] WHERE              # count 1-20, default 4
falak net traceroute <host> [--max-hops N] WHERE     # 1-30, default 20
falak net dns <name> [--type A|AAAA|CNAME|MX|TXT|NS|SRV|PTR] [--nameserver IP] WHERE   # default A
falak net http <url> [--method GET|HEAD] [--connect-to IP] [--insecure] WHERE         # curl -v with timings
falak net tcp <host> <port> [--count N] WHERE        # attempts 1-10, default 3
```

Exactly one of `--server`, `--site`, `--db`; `--on` and `--service` only with `--site`. `--timeout` 1-60 s (default
30). Output streams to stdout; `--json` prints the finished run with its lines.

### Packet capture (`network.capture`, Admin, audited)

```
falak net capture --yes [--interface default|wireguard|docker] [--duration S] [--max-size MiB] [--full]
  [--host IP|CIDR] [--port N] [--protocol tcp|udp|icmp] [--direction in|out|both] [-o FILE]
  --server NAME|ID | --site SITE [--on SERVER] [--service NAME] | --db INSTANCE
```

`--duration` 1-60 s (default 30), `--max-size` 1-100 MiB (default 20), headers only (first 96 bytes) unless `--full`.
Downloads to `-o` (default `falak-capture-<id>.pcap`, never overwritten). `--yes` acknowledges that captures can hold
cookies, tokens and personal data: **only with the human's explicit consent**.

## Functions

| Command | What |
|---|---|
| `falak fn list` | Columns `FUNCTION RUNTIME LIVE URL` |
| `falak fn pull <fn> [dir]` | Download the newest version's files into `dir` (default: the function's slug) and write `.falak-function.json` (base version, file hashes). Files the new version dropped are removed only if unchanged locally |
| `falak fn deploy <fn> [dir] [-m MESSAGE] [--force] [--wait] [--yes]` | Deploy the directory (default `.`) as a new version. Skips dot-files, `node_modules`, `__pycache__`, `.git`, `.venv`/`venv`, `.falakignore` patterns, symlinks, binaries and key/credential-looking files; max 50 files / 1 MB; the entrypoint must be present. Files new to the function need `--yes` (or a prompt). Exit `4` when someone deployed after your base: `fn pull` and merge, or `--force` (overwrites their version: ask first). `--wait` streams the deployment |
| `falak fn versions <fn>` | Columns `VERSION HASH AUTHOR CREATED MESSAGE`, `(live)` marks the running one |
| `falak fn rollback <fn> <version> [--wait]` | Deploy an earlier version again (`3` or `v3`) |
| `falak fn run <fn> <schedule>` | Run a schedule now (id, key or name) and stream its output; exits with the run's exit code |
| `falak fn invoke <fn> [path] [-X METHOD] [-d BODY\|@FILE] [-H 'K: V']…` | HTTP request to the function's URL (method default GET, POST with `-d`; JSON bodies get `Content-Type: application/json`). Prints status, headers, body; exit `1` on HTTP ≥ 400. `--json`: `{status, headers, body, duration_ms}` |

## Database containers

| Command | What |
|---|---|
| `falak db list` | Columns `ID NAME ENGINE STATUS SERVER MEMORY_MB DATABASES` |
| `falak db delete <instance> --confirm NAME [--delete-volume]` | **Destructive.** Delete a database container; `--confirm` must be the instance's name. The data volume stays unless `--delete-volume` (**irreversible data loss**). Refused while a falak.yaml declares it |

Backups, restores, schedules, upgrades and passwords are not in the CLI: dashboard (Databases) or falak.yaml `backups:`.

## Config as code

Project: `-p`/`--project` (id or name), else `FALAK_PROJECT`, else `.falak/project.json`. Environment: `-e`/`--env`
(slug), else the environment whose `branch` is the current git branch (production for a file without
environments, and for `drift`). File: the `[file]` argument, else `falak.yaml`, `falak.yml`, `falak.json`.

| Command | What |
|---|---|
| `falak init [dir] [--yes] [--stdout] [--json] [--full] [--force] [--dockerfile] [--update [--write]] [-p PROJECT]` | Detect the code and write `falak.yaml`. `--yes`: ask nothing (never links a project). `--stdout`: print it instead. `--json`: the detection report (writes nothing). `--full`: also defaults. `--force`: replace an existing file. `--dockerfile`: also a starter Dockerfile for languages Falak doesn't build natively. `--update`: diff of what the code adds to the existing file; `--write` applies it. Logged in and interactive, it asks for the project, environments, servers, domain and previews, and links the repo (`.falak/project.json`) only to a project you pick or pass with `-p` |
| `falak validate [file] [-e ENV] [--format yaml\|json] [--offline]` | Schema, environment merge and references offline; with a linked project and credentials also the organization checks (servers, secrets, names) unless `--offline`. Exit `5` if invalid. Issues print as `falak.yaml:12:17: schema: /pointer: message`. `--json`: `{valid, environments, errors, warnings}` |
| `falak plan [file] [-e ENV] [-p PROJECT]` | What applying the local file would change: `+` create, `~` update, `-` delete, `!` risky (with the rule and reason). Nothing is applied |
| `falak apply [file] [-e ENV] [-p PROJECT] [--allow-destroy] [--wait [--timeout 30m]] [--approve [--yes] [--comment TEXT]]` | Create a config run from the local file. `--wait`: stream its deployments until done (gives up after `--timeout`, the run goes on). Exit `6` when it waits for an approval; `3` when a unit's deployment failed. `--approve`: if you may approve, show the risky changes and approve (`--yes` without asking). `--allow-destroy`: let the run **delete** units, databases and volumes the file removed (needs `config.destroy`) |
| `falak runs list [-p PROJECT] [-e ENV] [--limit N]` | Runs newest first (default 20). Columns `RUN ENVIRONMENT STATUS TRIGGER COMMIT CREATED` |
| `falak runs show <run>` | A run: plan, approval, deployments |
| `falak approve <run> [--comment TEXT] [--yes]` | Approve a run waiting for an approval (sends the shown plan's hash; if the plan changed, nothing is approved and it shows the new one: exit `6` without a terminal) |
| `falak reject <run> [--comment TEXT] [--yes]` | Reject (cancel) a waiting run |
| `falak drift [-e ENV] [-p PROJECT] [--check]` | The environment against its last applied file (`--check`: check now). Exit `7` if drifted; `unknown` is not drift |
| `falak config export [project] [-e ENV] [-o FILE] [--format yaml\|json]` | Today's state of an environment (default: production) as a file (stdout, or `-o`/`--output`) |
| `falak config unmanaged [-e ENV] [-p PROJECT]` | What runs detached (removed from the file, kept). Columns `ENVIRONMENT UNIT TYPE RESOURCE REASON DETACHED` |
| `falak config source show [-p PROJECT]` | The repository file the project follows |
| `falak config source set [-p PROJECT] [--repo OWNER/NAME] [--connection ID] [--path FILE] [--enable\|--disable] [--allow-server NAME]…` | Change only what the flags name. `--path` default `falak.yaml`. `--disable` keeps every resource and cancels waiting runs. `--allow-server` (repeatable) replaces the list of servers the file may place units on (`""` clears; admins only). Connection ids: `GET /api/v1/source-control/connections` |

## Secrets

Scope: the project (`-p`), or one of its environments with `-e`. Names are `^[A-Z_][A-Z0-9_]*$`.

| Command | What |
|---|---|
| `falak secret list [-p PROJECT] [-e ENV]` | Names, kind, version, updated: **never values**. Columns `NAME KIND VERSION UPDATED` |
| `falak secret set <NAME> [-p PROJECT] [-e ENV]` | Value from a hidden prompt on a terminal, else all of stdin (one trailing newline dropped). Never an argument. Creates the secret or adds a version (current from the next deployment). `--json`: the secret metadata |
| `falak secret set --from-env FILE [-p PROJECT] [-e ENV]` | Import every `KEY=VALUE` line (blank values skipped; no multi-line values) |
| `falak secret delete <NAME> [-p PROJECT] [-e ENV] --yes` | Delete every version. Deployments referencing it fail. `--yes` required without a terminal |

## Sandboxes

Disposable gVisor machines (see SKILL.md). `<sandbox>` is an id or a name (a name matching several sandboxes prefers
the running one). Abilities: `sandboxes.view` (ls, show) and `sandboxes.use` (everything else).

| Command | What |
|---|---|
| `falak sandbox ls [--status S]` | Columns `NAME STATUS IMAGE SERVER CPU MEM EXPIRES IN`. Statuses `creating`, `running`, `paused`, `failed`, `killed`. `falak sandbox` alone = `ls` |
| `falak sandbox create [--image I] [--name N] [--server S] [--cpus N] [--memory 1g] [--disk 10g] [--ttl 1h] [--network none\|internet\|allowlist] [--allow HOST]… [--env K=V]… [--repo URL[@REF]] [--repo-secret NAME] [--wait=false]` | Defaults: `ubuntu:24.04`, 1 CPU, 1 GB memory, 10 GB disk, 1 h, `internet`, the server with the most free memory. Sizes: `512m`, `2g` or MB; durations: `30m`, `2h`, `1h30m` or seconds. `--allow` needs `--network allowlist`. `--repo` clones into the sandbox (`--repo-secret`: the name of a secret holding the clone token). Waits until it runs (up to 60 s) unless `--wait=false`. `--json`: the sandbox |
| `falak sandbox show <sandbox>` | Id, status, image, server, limits, network, IP, expiry, creator, kill reason |
| `falak sandbox exec <sandbox> [--timeout 60s] [--cwd DIR] [-e K=V]… -- <cmd…>` | One argument after `--` is a shell line (`sh -lc`, root); several are an argv run without a shell. stdout/stderr go to the matching local streams; exits with the remote code (`124` on timeout). `--timeout` max 120 s. Piped stdin (≤ 1 MB, text) is passed to the command. `--json`: `{exit_code, stdout, stderr, truncated, timed_out, duration_ms}` |
| `falak sandbox cp <src> <dst>` | One side is `sbx:<sandbox>:/absolute/path`, the other a local path or `-` (stdin/stdout). Files only, ≤ 5 MB; uploads keep the local mode; a remote path ending in `/` or a local directory keeps the file name |
| `falak sandbox ssh <sandbox>` | Interactive shell through the terminal relay (no ports opened) |
| `falak sandbox extend <sandbox> <30m>` | Add time (the organization caps the total) |
| `falak sandbox kill <sandbox>…` | Kill one or more; their files are gone. Exit 1 if any failed (the others are still killed) |
| `falak sandbox snapshot <sandbox> [--name N] [--stop] [--wait=false]` | Freeze the sandbox's disk, memory and processes; it keeps running (`--stop`: removed afterwards). Waits until `ready` (up to 60 s). Prints the snapshot (`--json`: the resource) |
| `falak sandbox snapshots [ls] [--sandbox S]` | Columns `NAME STATUS SOURCE SERVER IMAGE SIZE CREATED` |
| `falak sandbox snapshots rm <snapshot>…` | Delete snapshots (names or ids). Exit 1 if any failed |
| `falak sandbox fork <snapshot> [-n 1] [--name N] [--ttl 1h] [--env K=V]… [--idle-pause 10m] [--wait=false]` | `-n` 1–10 new sandboxes from the snapshot, on its server, with its image, size and network; `--name` is a prefix for `-n` above 1 (`name-1`…). Waits for them; prints them (`--json`: the list) |
| `falak sandbox create --from <snapshot>` · `--idle-pause 10m` · `--db <instance>/<database>` | `--from` can't be combined with `--image`, `--cpus`, `--memory`, `--disk`, `--network`, `--allow`, `--server`, `--repo`, `--repo-secret`. `--idle-pause` ≥ 1m |
| `falak sandbox pause <sandbox>…` · `resume <sandbox>…` | Pause (no CPU; memory stays allocated; the time limit keeps running) / resume. `ls` shows `paused`; exec, cp and ssh resume a paused sandbox by themselves |
| `falak sandbox db <sandbox> <instance>/<database>` | Copy a database of an instance on the sandbox's server to `/workspace/db/<database>.dump` (PostgreSQL) or `.sql` (MySQL/MariaDB). `<instance>`: id or name. Needs the backup-download ability on the instance |
| `falak mcp` | Run the MCP server on stdin/stdout (JSON-RPC, protocol 2025-06-18) with the sandbox tools; logs go to stderr. Uses the CLI's credentials (`FALAK_URL`/`FALAK_TOKEN` or `falak login`). Setup: `claude mcp add falak -- falak mcp` |

## The CLI itself

| Command | What |
|---|---|
| `falak update [--version vX.Y.Z] [--check]` | Replace this binary with the latest (or a given) release, verified against `SHA256SUMS` and its signature. `--check` only reports |
| `falak skill install [--user \| --project \| --dir DIR] [--force]` | Write this skill (`SKILL.md` + `references/`) into `~/.claude/skills/falak` (`--user`, default), `./.claude/skills/falak` (`--project`), or `DIR`. Refuses to overwrite existing files without `--force`. Only in CLIs that ship it (`falak help` lists it) |
| `falak version` | Print the version |
