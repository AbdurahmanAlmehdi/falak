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
| `--image` | `ubuntu:24.04` | Any image the server can pull (`node:22`, `python:3.13`, `alpine:3.22`…) |
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
| `--wait=false` | waits | Return at once (status `creating`) instead of waiting up to 60 s for `running` |

`falak sandbox ls` lists your organization's sandboxes (`NAME STATUS IMAGE SERVER CPU MEM EXPIRES IN`; `--status
running` filters) and `falak sandbox show <sandbox>` shows one.

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

## Network

| Mode | Reaches |
|---|---|
| `none` | Nothing (loopback only) |
| `internet` (default) | The public internet. **Not** private networks (10/8, 172.16/12, 192.168/16, 100.64/10), cloud metadata (169.254.169.254), the server itself, other sandboxes or your Falak services |
| `allowlist` | Only the hosts given with `--allow` (through an HTTP(S) proxy; the sandbox's `HTTP_PROXY`/`HTTPS_PROXY` point to it) |

Each sandbox gets its own network namespace and DNS settings.

## Limits

Per sandbox: CPUs, memory and disk as created (defaults above; at most 4 CPUs, 8 GB memory and 50 GB disk by default).
Per organization (defaults): 5 running sandboxes, 8 GB of memory in total, 1 h default time limit, 24 h maximum.
Administrators can change these, and a server can opt out of running sandboxes (to keep production servers clean).

Permissions (also token abilities): `sandboxes.view` (list, show), `sandboxes.use` (create, extend; exec, files,
shell and kill on your own sandboxes), `sandboxes.manage` (the same on anyone's sandboxes, organization settings).
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
| `sandbox_create` `{image?, name?, ttl?, network?, cpus?, memory_mb?, repo_url?}` | Create a sandbox and wait until it runs; returns it as JSON |
| `sandbox_list` `{}` | The organization's sandboxes |
| `sandbox_exec` `{id, command, cwd?, timeout?}` | Run a shell command (root, `sh -lc`, ≤ 120 s); returns the exit code, stdout and stderr |
| `sandbox_read_file` `{id, path}` | A file's content (binary files as base64) |
| `sandbox_write_file` `{id, path, content}` | Write a text file |
| `sandbox_list_files` `{id, path}` | A directory listing |
| `sandbox_extend` `{id, by}` | Add time (`"30m"` or seconds) |
| `sandbox_kill` `{id}` | Kill it |

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

A sandbox:

```json
{"id": "01jsbx0example000000000000", "name": "brave-otter", "status": "running", "image": "ubuntu:24.04",
 "server": {"id": "01jsrv0example000000000000", "name": "main-1"}, "cpus": 1, "memory_mb": 1024, "disk_mb": 10240,
 "network": "internet", "allow_hosts": [], "ttl_seconds": 3600, "expires_at": "2026-10-16T20:00:00+00:00",
 "created_at": "2026-10-16T19:00:00+00:00", "created_by": {"type": "token", "name": "ci"}, "ip": "10.88.0.5",
 "kill_reason": null}
```

Statuses: `creating`, `running`, `failed`, `killed`. Rate limits: 30 creates and 120 execs a minute per token.

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

Snapshots (disk and memory), forking a snapshot into several sandboxes, pause/resume and idle pause (v0.21); preview
URLs, diffs against a snapshot and opening a PR from a sandbox (v0.22).

## Known gaps

- gVisor implements most of Linux, but some software that uses rare system calls or special filesystems misbehaves.
  If something fails only in a sandbox, that's the likely cause.
- No streaming exec yet: each exec returns when the command ends (or after 120 s).
- Files: one file per call, up to 5 MB; no directories (use tar).
