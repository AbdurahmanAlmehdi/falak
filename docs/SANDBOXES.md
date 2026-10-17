# Sandboxes

A **sandbox** is a disposable Linux machine on one of your servers, built for code you don't trust: an AI agent
running without permission prompts, a script someone sent you, an install script from the internet, an upgrade you're
not sure about. Inside it you are root and can break anything; outside, nothing it does reaches the server, your sites
or your network. It starts in seconds, has a time limit, and is gone (files included) when it is killed or expires.

Sandboxes are isolated with [gVisor](https://gvisor.dev): a user-space kernel between the sandbox and the server, so
the sandbox never talks to the host kernel directly. It works on ordinary VPSes (no KVM needed).

| | Site / service | Sandbox |
|---|---|---|
| Purpose | Run your app for users | Run anything for a while |
| Lifetime | Permanent | Minutes to hours (time limit) |
| Code comes from | Git deploys | Whoever is inside |
| Trusted | Yes | No: assumed hostile |

Everything is available through the API, the `falak` CLI and an MCP server (`falak mcp`), so agents can use sandboxes
as easily as people.

## Quick start

```bash
falak sandbox create --name demo                 # ubuntu:24.04, 1 CPU, 1 GB, 1 h; waits until it runs
falak sandbox exec demo -- "uname -a && cat /etc/os-release"
falak sandbox ssh demo                           # an interactive shell
falak sandbox kill demo
```

`<sandbox>` in every command is the sandbox's id or its name.

## Create

```bash
falak sandbox create --image node:22 --name build-1 --cpus 2 --memory 2g --disk 20g --ttl 2h
falak sandbox create --image python:3.13 --env DEBUG=1 --env MODE=test
falak sandbox create --repo https://github.com/acme/shop@main --repo-secret GH_TOKEN
falak sandbox create --server main-1 --json     # pick the server; --json prints the sandbox
```

| Flag | Default | Notes |
|---|---|---|
| `--image` | `ubuntu:24.04` | Any public image the server can pull anonymously (`node:22`, `python:3.13`, `alpine:3.22`…), up to 10 GB. Registries on localhost, a private address or the server itself are refused, also by a name that resolves to one (checked on the server before the pull) |
| `--name` | generated (`brave-otter`) | |
| `--server` | the server with the most free memory | Name or id; the server must allow sandboxes |
| `--cpus` | 1 | Fractions work (`0.5`) |
| `--memory` | `1g` | `512m`, `2g` or MB. Going over it kills the whole sandbox (`killed: out of memory`) |
| `--disk` | `10g` | Space for everything the sandbox writes |
| `--ttl` | `1h` | `30m`, `2h`, `1h30m` or seconds; capped by your organization (24 h at most) |
| `--network` | `internet` | `none`, `internet` or `allowlist` (see [Network](#network)) |
| `--allow HOST` | | Repeatable, with `--network allowlist` |
| `--env K=V` | | Repeatable |
| `--repo URL[@REF]` | | Clone a git repository into the sandbox |
| `--repo-secret NAME` | | An organization secret holding a token for a private repository. It is used for the clone, offered only to the repository's host and not kept in the sandbox's environment. Handing a secret's value to a sandbox is revealing it: it needs `secrets.reveal` (a token created with that ability by name, `*` is not enough), and a sensitive (write-only) secret is refused, so create the token's secret with Sensitive off |
| `--from SNAPSHOT` | | Create from a [snapshot](#snapshots-and-fork) (name or id). Not combined with `--image`, `--cpus`, `--memory`, `--disk`, `--network`, `--allow`, `--server`, `--repo` or `--repo-secret`: the snapshot decides |
| `--idle-pause 10m` | off | [Pause](#pause-and-idle-pause) the sandbox after this long unused (at least `1m`) |
| `--db INSTANCE/DATABASE` | | [Copy a database](#database-copy) into the sandbox |
| `--wait=false` | waits | Return at once (status `creating`) instead of waiting up to 60 s for `running` |

`falak sandbox ls` lists your organization's sandboxes (`NAME STATUS IMAGE SERVER CPU MEM EXPIRES IN`; `--status
running` or `--status paused` filters) and `falak sandbox show <sandbox>` shows one.

## Run commands

```bash
falak sandbox exec demo -- "apt-get update && apt-get install -y curl"   # one argument: a shell line (sh -lc)
falak sandbox exec demo -- ls -la /root                                   # several: argv, no shell
falak sandbox exec demo --cwd /srv --timeout 2m -e NODE_ENV=test -- npm test
echo '{"a":1}' | falak sandbox exec demo -- "jq .a"                      # piped stdin is passed on
```

- Commands run as root. stdout and stderr come back on the matching local streams, and `falak sandbox exec` exits
  with the command's exit code (`124` when the timeout stopped it), so it works in scripts.
- An exec waits at most **120 s** (default 60 s, `--timeout`). Start longer jobs in the background and poll:

  ```bash
  falak sandbox exec demo -- "nohup ./long-job.sh > /tmp/job.log 2>&1 &"
  falak sandbox exec demo -- "tail -n 20 /tmp/job.log"
  ```

- Output is capped at 256 KB per stream (the CLI says when it was truncated): write big output to a file and copy it
  out.
- Every command is recorded in the sandbox's exec log (who ran what, when, exit code, duration; not the output).

## Files

```bash
falak sandbox cp ./script.sh sbx:demo:/root/script.sh     # upload (keeps the file mode)
falak sandbox cp ./data.csv sbx:demo:/tmp/                 # a path ending in / keeps the name
falak sandbox cp sbx:demo:/root/out.json ./out.json        # download
falak sandbox cp sbx:demo:/var/log/app.log -               # to stdout
cat notes.txt | falak sandbox cp - sbx:demo:/root/notes.txt
```

One side is `sbx:<sandbox>:/absolute/path`. Files up to 5 MB; directories aren't supported yet, so tar them:

```bash
tar -czf src.tgz src && falak sandbox cp src.tgz sbx:demo:/root/ && falak sandbox exec demo -- "tar -xzf /root/src.tgz -C /root"
falak sandbox exec demo -- "tar -czf /tmp/out.tgz -C /root/shop dist" && falak sandbox cp sbx:demo:/tmp/out.tgz .
```

## Shell

`falak sandbox ssh <sandbox>` (or `falak console --sandbox <sandbox>`) opens an interactive shell through Falak's
terminal relay, like site consoles: no port is opened on the sandbox or the server. It exits with the shell's exit
code.

## Time limit and kill

Every sandbox has a time limit. `falak sandbox extend demo 30m` adds time (up to your organization's maximum). When
the time is up, the sandbox is killed: the control plane sweeps expired sandboxes every minute, and the server's agent
enforces the limit on its own too, so a sandbox never outlives it.

```bash
falak sandbox kill demo                 # one
falak sandbox kill build-1 build-2      # several
```

Killing removes the sandbox, its processes and everything it wrote. Copy out what you need first.

## Snapshots and fork

A **snapshot** freezes a sandbox at one moment: its memory, its running processes and everything it wrote. The sandbox
keeps running (unless you ask it to stop). A snapshot lives on the sandbox's server, outlives the sandbox and never
expires; you delete it. Take one before a risky step, or to save a prepared environment.

```bash
falak sandbox snapshot build-1 --name node-ready          # waits until it is ready; --stop also removes the sandbox
falak sandbox snapshots                                    # NAME STATUS SOURCE SERVER IMAGE SIZE CREATED
falak sandbox snapshots ls --sandbox build-1               # only that sandbox's
falak sandbox snapshots rm node-ready old-one              # delete (names or ids)
```

**Fork** makes new sandboxes from a snapshot. Each continues exactly where the snapshot was taken (processes keep
running), on the snapshot's server, with its image, CPUs, memory, disk and network mode, and gets its own IP. Forks
copy rather than share: each one uses its own memory and disk, and counts against the running-sandbox cap and the
quotas. Use it to try several approaches in parallel, then kill the ones you don't keep.

```bash
falak sandbox fork node-ready -n 3                         # node-ready-1 … node-ready-3
falak sandbox fork node-ready -n 3 --name attempt --ttl 30m --env STRATEGY=b --idle-pause 10m
falak sandbox fork node-ready --json                       # prints the forks
falak sandbox kill attempt-2 attempt-3
```

`-n` is 1 to 10. With `-n` above 1 `--name` is a prefix (`attempt-1`…). `--env` adds to the snapshot's environment.

**Templates are named snapshots.** There is no separate object: give a snapshot a name and create sandboxes from it,
as many times as you like, instead of repeating an install every time.

```bash
falak sandbox create --name base --image node:22 --ttl 30m
falak sandbox exec base -- "npm ci && npm run build"
falak sandbox snapshot base --name shop-built --stop      # the template; base is gone
falak sandbox create --from shop-built --name run-1
```

Quotas by default: 20 snapshots and 50 GB of snapshot storage per organization. A snapshot with memory is about as big
as the sandbox's memory in use plus what it wrote. Snapshot names are unique in the organization. Forking or deleting
someone else's snapshot needs `sandboxes.manage`.

## Pause and idle pause

`falak sandbox pause <sandbox>…` freezes a sandbox's processes: it uses no CPU, but its memory stays allocated (so it
still counts against the quotas) and its time limit keeps running. `falak sandbox resume <sandbox>…` continues it.
`falak sandbox ls` shows `paused`.

A sandbox created with `--idle-pause 10m` is paused by Falak when nothing used it (exec, files, shell input, API calls)
for that long. You don't have to resume it: an exec, a file call or a shell on a paused sandbox resumes it first, at
the cost of one extra round trip.

## Database copy

`--db INSTANCE/DATABASE` on create, or later `falak sandbox db <sandbox> <instance>/<database>`, dumps a database from
a database container **on the same server** into the sandbox at `/workspace/db/<database>.dump`
(PostgreSQL: custom-format `pg_dump -Fc`, restore with `pg_restore`) or `/workspace/db/<database>.sql`
(MySQL/MariaDB). The instance is its id or name. The sandbox never connects to the database: it gets a copy of the
data, streamed in without touching the server's disk. It needs the permission that downloading a backup of that
instance needs (a token needs that ability by name).

```bash
falak sandbox create --name migrate-test --db pg-main/shop
falak sandbox exec migrate-test -- "createdb shop && pg_restore -d shop /workspace/db/shop.dump"
falak sandbox db migrate-test pg-main/reports         # another one later
```

Copy demo or staging data when you can: whatever is in the dump is readable by whatever runs in the sandbox.

## Network

| Mode | Reaches |
|---|---|
| `none` | Nothing (loopback only) |
| `internet` (default) | The public internet. **Not** private networks (10/8, 172.16/12, 192.168/16, 100.64/10), cloud metadata (169.254.169.254), the server itself, other sandboxes or your Falak services |
| `allowlist` | Only the hosts given with `--allow` (through an HTTP(S) proxy; the sandbox's `HTTP_PROXY`/`HTTPS_PROXY` point to it) |

Each sandbox gets its own network namespace and DNS settings.

An `internet` sandbox's traffic leaves from the server's public IP address (NAT), like the server's own. Anything that
trusts that address, such as a database firewall, an API allowlist or another server's firewall rule that lets this
server in, trusts every sandbox on it too. Use `none` or `allowlist` for code that mustn't reach those, or run
sandboxes on a server whose address nothing trusts.

## Limits

Per sandbox: CPUs, memory and disk as created (defaults above; at most 4 CPUs, 8 GB memory and 50 GB disk by default).
Per organization (defaults): 5 running sandboxes, 8 GB of memory and 100 GB of disk in total (the `--disk` of its live
sandboxes), 1 h default time limit, 24 h maximum. Administrators can change these (`FALAK_SANDBOX_MAX_RUNNING`,
`FALAK_SANDBOX_MAX_TOTAL_MEMORY_MB`, `FALAK_SANDBOX_MAX_TOTAL_DISK_MB`...), and a server can opt out of running
sandboxes (to keep production servers clean).

Per server, the agent keeps the disk from filling up: it doesn't pull an image, unpack it or start a sandbox when that
(the image's size plus the sandbox's `--disk`) would leave less than 10% of the disk, or 5 GB if that is less, free under
`/var/lib/falak/sandboxes`, and it cancels a pull that gets there. Such a create fails with `not enough disk space`.

Permissions (also token abilities): `sandboxes.view` (list, show), `sandboxes.use` (create; exec, files, shell,
rename, extend and kill on your own sandboxes), `sandboxes.manage` (the same on anyone's sandboxes, organization
settings).
`--repo-secret` also needs `secrets.reveal`. Exec output and file contents pass through the control plane and are not
kept there; the exec log keeps each command (redacted), who ran it, its exit code or error.

## For AI agents: MCP

`falak mcp` runs an [MCP](https://modelcontextprotocol.io) server on stdio, using the CLI's login. In Claude Code:

```bash
claude mcp add falak -- falak mcp
```

Other MCP clients: the command is `falak mcp` (no arguments). The tools:

| Tool | Does |
|---|---|
| `sandbox_create` `{image?, name?, ttl?, network?, cpus?, memory_mb?, repo_url?, from_snapshot?, idle_pause?}` | Create a sandbox and wait until it runs; returns it as JSON. `from_snapshot` replaces image, size, network and repo |
| `sandbox_list` `{}` | The organization's sandboxes |
| `sandbox_exec` `{id, command, cwd?, timeout?}` | Run a shell command (root, `sh -lc`, ≤ 120 s); returns the exit code, stdout and stderr |
| `sandbox_read_file` `{id, path}` | A file's content (binary files as base64) |
| `sandbox_write_file` `{id, path, content}` | Write a text file |
| `sandbox_list_files` `{id, path}` | A directory listing |
| `sandbox_extend` `{id, by}` | Add time (`"30m"` or seconds) |
| `sandbox_kill` `{id}` | Kill it |
| `sandbox_snapshot` `{id, name?}` | Snapshot a running sandbox (disk, memory, processes); it keeps running. Do this before a risky step |
| `sandbox_fork` `{snapshot, count?, ttl?}` | `count` (1–10) sandboxes from a snapshot, to try approaches in parallel; kill the ones you don't keep |
| `sandbox_pause` `{id}` / `sandbox_resume` `{id}` | Pause (no CPU, memory stays) / resume. Exec and file calls resume a paused sandbox by themselves |
| `sandbox_list_snapshots` `{}` | The organization's snapshots |

`id` is the sandbox's id or name. For an agent, prefer a token with only `sandboxes.view` and `sandboxes.use`
(Settings → API tokens) given to the server through its environment:

```bash
claude mcp add falak -e FALAK_URL=https://falak.example.com -e FALAK_TOKEN=… -- falak mcp
```

The [Falak skill](AI_SKILL.md) tells agents to use a sandbox for anything destructive or untrusted and to kill it when
they are done.

## API

All routes are under `/api/v1`, with a bearer token (`Accept: application/json`). `{sandbox}` is the sandbox's id.
`404` when the sandbox isn't in the token's organization, `409` when it isn't running, `422` for invalid input.

| Route | Body / query | Answer |
|---|---|---|
| `POST /sandboxes` | `{image?, name?, server?, cpus?, memory_mb?, disk_mb?, ttl? (seconds or "30m"), network?, allow_hosts?, env?, repo?: {url, ref?, secret?}}`; `?wait=1` waits up to 60 s for `running` | `201` the sandbox |
| `GET /sandboxes` | `?status=` | `{data: [sandbox]}` |
| `GET /sandboxes/{sandbox}` | | the sandbox |
| `PATCH /sandboxes/{sandbox}` | `{name?, extend? (seconds or "30m")}` | the sandbox |
| `DELETE /sandboxes/{sandbox}` | | `204` |
| `POST /sandboxes/{sandbox}/exec` | `{cmd: "shell line" or ["argv", …], cwd?, env?, stdin?, timeout? (1–120, default 60)}` | `{exit_code, stdout, stderr, truncated, timed_out, duration_ms}` |
| `GET /sandboxes/{sandbox}/files` | `?path=` (`&list=1` for a directory listing) | the file's bytes, or `{entries: [{name, type, size, mode, mtime}]}` |
| `PUT /sandboxes/{sandbox}/files` | `?path=&mode=`, body = the file's bytes (≤ 5 MB) | `{size}` |
| `DELETE /sandboxes/{sandbox}/files` | `?path=&recursive=1` | `204` |
| `GET /sandboxes/{sandbox}/execs` | | the exec log |
| `POST /sandboxes/{sandbox}/console` | | a terminal session (use `falak sandbox ssh`) |
| `POST /sandboxes/{sandbox}/snapshots` | `{name?, stop? (default false)}`; `?wait=1` waits up to 60 s for `ready` | `201` the snapshot |
| `GET /sandboxes/snapshots` | `?sandbox=` | `{data: [snapshot]}` |
| `GET /sandboxes/snapshots/{snapshot}` | | the snapshot |
| `PATCH /sandboxes/snapshots/{snapshot}` | `{name}` | the snapshot |
| `DELETE /sandboxes/snapshots/{snapshot}` | | `204` |
| `POST /sandboxes/snapshots/{snapshot}/fork` | `{count? (1–10), name? (prefix), ttl?, env?, idle_pause?}`; `?wait=1` | `201` `{data: [sandbox]}` |
| `POST /sandboxes` (also) | `{snapshot? (id or name; with it no image, cpus, memory_mb, disk_mb, network, server or repo), idle_pause? (seconds or "10m"), db?: {instance, database}}` | `201` the sandbox |
| `POST /sandboxes/{sandbox}/pause` · `/resume` | | the sandbox |
| `POST /sandboxes/{sandbox}/db` | `{instance, database}` (waits, up to 10 min) | `{data: {path, size_bytes}}` |

`{snapshot}` is a snapshot's id or name. Exec, files and the console on a `paused` sandbox resume it first.

A sandbox:

```json
{"id": "01jsbx0example000000000000", "name": "brave-otter", "status": "running", "image": "ubuntu:24.04",
 "server": {"id": "01jsrv0example000000000000", "name": "main-1"}, "cpus": 1, "memory_mb": 1024, "disk_mb": 10240,
 "network": "internet", "allow_hosts": [], "ttl_seconds": 3600, "expires_at": "2026-10-16T20:00:00+00:00",
 "created_at": "2026-10-16T19:00:00+00:00", "created_by": {"type": "token", "name": "ci"}, "ip": "10.88.0.5",
 "kill_reason": null, "idle_pause_seconds": null, "last_activity_at": "2026-10-16T19:05:00+00:00", "from_snapshot": null}
```

A snapshot:

```json
{"id": "01jsnap0example00000000000", "name": "node-ready", "status": "ready", "sandbox": {"id": "01jsbx0example000000000000", "name": "brave-otter"},
 "server": {"id": "01jsrv0example000000000000", "name": "main-1"}, "image": "node:22", "cpus": 1, "memory_mb": 1024,
 "disk_mb": 10240, "network": "internet", "size_bytes": 31457280, "created_at": "2026-10-16T19:10:00+00:00",
 "created_by": {"type": "user", "name": "Sam"}}
```

Sandbox statuses: `creating`, `running`, `paused`, `failed`, `killed`. Snapshot statuses: `creating`, `ready`, `failed`.
Permissions for snapshot, fork, pause, resume and db are those of the sandbox they act on (`sandboxes.use` on your own,
`sandboxes.manage` on anyone's); `db` also needs the backup-download permission on the instance. Rate limits: 30 creates and 120 execs a minute per token.

With curl (demo values):

```bash
export FALAK_URL=https://falak.example.com FALAK_TOKEN=…        # a token with sandboxes.use
api() { curl -fsS -H "Authorization: Bearer $FALAK_TOKEN" -H "Accept: application/json" "$@"; }

# create and wait
api -X POST "$FALAK_URL/api/v1/sandboxes?wait=1" -H "Content-Type: application/json" \
  -d '{"image": "python:3.13", "name": "demo", "ttl": "30m"}'

# run a command
api -X POST "$FALAK_URL/api/v1/sandboxes/01jsbx0example000000000000/exec" -H "Content-Type: application/json" \
  -d '{"cmd": "python3 -c \"print(6*7)\"", "timeout": 30}'
# {"exit_code":0,"stdout":"42\n","stderr":"","truncated":false,"timed_out":false,"duration_ms":95}

# upload and download a file
api -X PUT "$FALAK_URL/api/v1/sandboxes/01jsbx0example000000000000/files?path=/root/job.py&mode=0644" \
  -H "Content-Type: application/octet-stream" --data-binary @job.py
api "$FALAK_URL/api/v1/sandboxes/01jsbx0example000000000000/files?path=/root/out.json" -o out.json

# add 30 minutes, then kill
api -X PATCH "$FALAK_URL/api/v1/sandboxes/01jsbx0example000000000000" -H "Content-Type: application/json" -d '{"extend": "30m"}'
api -X DELETE "$FALAK_URL/api/v1/sandboxes/01jsbx0example000000000000"
```

## Coming next

Diffs against a snapshot, opening a PR from a sandbox and promoting a sandbox's result (v0.23). Preview URLs for a
port in a sandbox come later.

## Known gaps

- gVisor implements most of Linux, but some software that uses rare system calls or special filesystems misbehaves.
  If something fails only in a sandbox, that's the likely cause.
- No streaming exec yet: each exec returns when the command ends (or after 120 s).
- Files: one file per call, up to 5 MB; no directories (use tar).
