# Sandboxes (plan, v0.20.0 → v0.22.0)

A sandbox is a disposable Linux machine on one of your servers, built for code you don't trust: an AI agent with no
permission prompts, a user's script, an install script from the internet, an upgrade you're not sure about. Inside it
you are root and can break anything; outside, nothing it does reaches the server, your sites or your network. It
starts in seconds, has a time limit, and can be snapshotted (disk and memory), forked, paused and killed.

Agents come first: everything is an API call and an MCP tool before it is a button. People get the same things in the
CLI and the dashboard. It has to work for a two-person team on one VPS and for a company that needs every command an
agent ran on record.

## What it is (and isn't)

| | Site / service | Sandbox |
|---|---|---|
| Purpose | Run your app for users | Run anything for a while |
| Lifetime | Permanent | Minutes to hours (TTL) |
| Code comes from | Git deploys | Whoever is inside |
| Trusted | Yes | No: assumed hostile |
| Public URL | Domains + TLS | Only a port you expose (v0.22) |

Not in scope: Firecracker/microVMs (later, as a second backend for bare-metal servers), a real sshd inside sandboxes,
SDK packages (REST + MCP cover agents), GPUs, usage billing.

## Isolation: gVisor

Plain containers share the host kernel, which is not enough for hostile code. Typical VPSes (main-1, EC2 non-metal)
have no `/dev/kvm`, which rules out microVMs. gVisor (`runsc`) puts a user-space kernel between the sandbox and the
host, needs no KVM (systrap platform), plugs into Docker as a runtime, and has checkpoint/restore with memory.

**The agent drives `runsc` directly, not through Docker.** Docker 29 cannot restore a runsc checkpoint (and its
embedded DNS doesn't work under gVisor), so Docker is used only to pull images and export them as a base rootfs.
Per sandbox the agent owns: an OCI bundle (`runsc spec`, patched), a network namespace with a veth on the `br-sbx`
bridge, a cgroup for CPU/memory/pids, and `runsc --root=/run/falak-sbx run|exec|checkpoint|restore|pause|resume|delete`.

- **Base rootfs:** one read-only rootfs per image (`/var/lib/falak/sandboxes/images/<digest>`), shared by every
  sandbox of that image.
- **Writes:** `--overlay2=root:self,size=<disk>`: the sandbox's writes go to a sparse filestore file, capped at the
  disk quota (enforced exactly). The host rootfs is never modified.
- **Persistent workspace (optional):** a loop-mounted ext4 image at `/workspace`, for data that should live outside
  snapshots.
- **Memory limit:** enforced by the host cgroup; hitting it kills the whole sandbox (reported as
  `killed: out of memory`, not as a process OOM).
- **Hardening:** no capabilities beyond gVisor's defaults, no host mounts, no Docker socket, detached stdio
  redirected to a log.

## Model

```
sandboxes        id, organization_id, server_id, name, image, template_id?, from_snapshot_id?,
                 status (creating|running|paused|stopped|failed|killed), cpus, memory_mb, disk_mb,
                 network (none|internet|allowlist), allow_hosts json, env (encrypted), ttl_seconds, expires_at,
                 idle_pause_seconds?, created_by (user or token), last_activity_at, killed_at, kill_reason
sandbox_snapshots id, organization_id, sandbox_id, server_id, name, with_memory bool, size_bytes, status, created_at
sandbox_execs    id, sandbox_id, actor, cmd (redacted), cwd, exit_code, duration_ms, stdout_bytes, stderr_bytes,
                 started_at   (the audit trail; output itself is not stored by default)
```

runsc container id `falak-sbx-<id>`, state under `/var/lib/falak/sandboxes/<id>` (bundle, filestore, snapshots under `snapshots/`).

## Life cycle

```
create ─► running ─► paused ─► running ─► killed
            │                     ▲
            └─ snapshot ─► fork ──┘   (N new sandboxes from one moment)
```

- **Create** from an image (`ubuntu:24.04`, `node:22`, `python:3.13`...), a template, or a snapshot.
- **TTL** is required (default 1 h, org max configurable, up to 24 h; `extend` adds time). The control plane sweeps
  expired sandboxes every minute and the agent enforces `expires_at` itself, so a dead control plane can't leave
  sandboxes running forever.
- **Idle pause** (v0.21): no exec, file call or terminal input for N minutes → paused; any call resumes it.
- **Kill** removes the container, its writable layer and its network attachments. Snapshots stay until deleted.

## API (agents first)

Sanctum tokens, organization-scoped, with new permissions (grantable to tokens): `sandboxes.view`,
`sandboxes.use` (create, exec, files, terminal, snapshot, fork, kill own), `sandboxes.manage` (kill anyone's,
settings). An enterprise can mint a token that can only drive sandboxes.

```
POST   /api/v1/sandboxes                {image|template|snapshot, name?, server?, cpus, memory, disk, ttl,
                                         network, allow_hosts?, env?, repo?: {url, ref, token_secret?}}
GET    /api/v1/sandboxes                ?status=
GET    /api/v1/sandboxes/{id}
PATCH  /api/v1/sandboxes/{id}           {ttl_extend?, name?}
DELETE /api/v1/sandboxes/{id}

POST   /api/v1/sandboxes/{id}/exec      {cmd: string|string[], cwd?, env?, stdin?, timeout? (≤ 120 s)}
                                        → {exit_code, stdout, stderr, duration_ms, truncated}
GET    /api/v1/sandboxes/{id}/files     ?path=  → file bytes, or a directory listing (?list=1)
PUT    /api/v1/sandboxes/{id}/files     ?path=  body = bytes (≤ 5 MB in v0.20)
DELETE /api/v1/sandboxes/{id}/files     ?path=
POST   /api/v1/sandboxes/{id}/console   → terminal ticket (same as site consoles)

v0.21:
POST   /api/v1/sandboxes/{id}/snapshots {name?, memory: true}
GET    /api/v1/sandboxes/snapshots
POST   /api/v1/sandboxes/snapshots/{id}/fork {count ≤ 10, ttl?, name?}
DELETE /api/v1/sandboxes/snapshots/{id}
POST   /api/v1/sandboxes/{id}/pause | /resume
```

Exec output is capped (256 KB per stream, `truncated: true` beyond). Exec is synchronous (the request waits for the
agent, up to 120 s); longer jobs run in the background inside the sandbox (`nohup … > log &`) and are polled.
Streaming exec (SSE) and big file transfers (chunked uploads) come later. Every exec and file write is a `sandbox_execs` row; create/kill/snapshot/fork also go to the
organization audit log (`Identity\Contracts\AuditLog`).

## Getting work in and out

The main agent use case is "clone my repo, let the agent work, give me the result".
- **In:** `repo` on create clones a git URL (token from Secrets, never stored in the sandbox env after clone), or
  `PUT files` / a tarball upload.
- **Out (v0.20):** `GET files` and `falak sandbox cp`; `falak sandbox diff` = `git diff` of the cloned repo.
- **Out (v0.22):** diff against a snapshot (any files, not just git), open a PR, promote to site.

## MCP

`falak mcp` is a stdio MCP server in the existing CLI binary, using the CLI's login. Tools:

| v0.20 | v0.21 |
|---|---|
| `sandbox_create`, `sandbox_list`, `sandbox_exec`, `sandbox_read_file`, `sandbox_write_file`, `sandbox_list_files`, `sandbox_kill`, `sandbox_extend` | `sandbox_snapshot`, `sandbox_fork`, `sandbox_pause`, `sandbox_resume` |

Set up in Claude Code with `claude mcp add falak -- falak mcp`. The falak skill (`skills/falak` and its embedded copy)
gets a sandboxes section: use a sandbox for anything destructive, snapshot before risky steps, kill when done.

## CLI

```
falak sandbox create [--image I | --template T | --from SNAPSHOT] [--cpus --memory --disk --ttl --network --allow]
falak sandbox ls | show <id> | kill <id> | extend <id> 30m
falak sandbox ssh <id>                  (terminal relay; no ports open)
falak sandbox exec <id> -- <cmd...>
falak sandbox cp <src> <dst>            (sbx:<id>:/path on either side)
falak sandbox snapshot <id> | fork <snapshot> [-n 3] | pause | resume     (v0.21)
falak mcp
```

## Network

| Mode | Reaches |
|---|---|
| `none` | Nothing (loopback only) |
| `internet` (default) | The internet. Not RFC1918/CGNAT/link-local (cloud metadata 169.254.169.254), not the host, not other sandboxes, not Falak's environment networks |
| `allowlist` | Only the listed hosts (via an egress proxy; spike decides) |

Each sandbox gets its own network namespace on the bridge `br-sbx` (its own subnet). The agent owns a
`FALAK-SBX` iptables chain (jumped to from `FORWARD` and `INPUT`, re-applied at agent start so it survives Docker
and firewall reloads):

- drop to 169.254.0.0/16, 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 100.64.0.0/10 and the server's own addresses;
- drop sandbox ↔ sandbox on the bridge; drop `INPUT` from the bridge (no host services);
- NAT to the internet for `internet`; no veth for `none`; no NAT for `allowlist`, whose only way out is an
  allowlisting HTTP(S) proxy on the bridge (`HTTP(S)_PROXY` set in the sandbox).
- The agent writes the sandbox's `/etc/resolv.conf` (public resolvers, or the proxy).

The spike proved this is mandatory: on Docker's default bridge a sandbox reached the cloud metadata endpoint
(including IMDSv2 tokens), the host and other containers.

## Limits and quotas

- Per sandbox: CPUs, memory, pids (reuse `Limits\Contracts\ResourceLimits` + `LimitValidator`), disk (new: method
  from the spike).
- Per organization (`config/sandboxes.php`, overridable in settings): max running sandboxes, max total CPU/memory,
  max TTL, max snapshots and snapshot storage. Defaults sized for a small VPS: 5 running, 1 h TTL.
- Per server: sandboxes register a `CapacitySource`, so the server's capacity view counts them, and placement picks
  the server with the most free memory among servers that have the `sandbox.v1` agent feature (or the one given).
- A server can opt out of sandboxes (setting), for teams that keep production servers clean.

## Agent

New package `agent/internal/sandbox`, registered in `agent.go`, feature `sandbox.v1`:

| Command | Does |
|---|---|
| `sandbox.prepare` | Install runsc from gVisor's apt repo (pinned release), create `br-sbx` + the `FALAK-SBX` chain (idempotent; runs on first use) |
| `sandbox.create` | Pull the image, export it as a base rootfs once, write the bundle, create netns + cgroup, `runsc run --detach`; optional git clone |
| `sandbox.exec` | `runsc exec` with timeout, separate stdout/stderr, output caps |
| `sandbox.files.read/write/list/delete` | tar streams through `runsc exec` (`tar -c`/`tar -x`), so no host path is ever opened |
| `sandbox.kill` | `runsc delete -force`, remove bundle, filestore, netns, cgroup |
| `sandbox.reap` | Local TTL enforcement every minute, independent of the control plane; also rebuilds state after an agent restart |
| `sandbox.snapshot` (v0.21) | `runsc checkpoint [--leave-running] --image-path` (memory + written files) |
| `sandbox.restore/fork` (v0.21) | `runsc restore` into a new bundle + netns per fork (forks get their own IPs) |
| `sandbox.pause/resume` (v0.21) | `runsc pause/resume` |

Terminal: a new console target `sandbox`. The agent's pty package gains a runsc target (`runsc exec`, terminal mode from the pty, in place of
`docker exec -it`); the relay and CLI are unchanged.

## Control plane

New module `Sandboxes` (after Fleet, Limits, Identity; before Terminal, which consumes `SandboxDirectory`):
Contracts (`SandboxDirectory`), Actions (`CreateSandbox`, `ExecInSandbox`, `KillSandbox`, ...), `SweepSandboxes`
job (TTL, stuck `creating`), `HandleCommandOutcome` listener, `SandboxPolicy`, API + web routes, Inertia pages.

## Dashboard

- **Sandboxes** page: list (name, image, server, status, time left, CPU/RAM, created by user or token), filters,
  create dialog, kill.
- **Sandbox** page: terminal, exec log (the audit trail), files browser (read-only in v0.20), snapshots (v0.21),
  metrics (reuses the per-container metrics).
- **Settings → Sandboxes**: organization quotas, default TTL, servers allowed to run sandboxes.

## Releases

| Release | Contents |
|---|---|
| v0.20.0 | Create (image), exec, files, console, kill, TTL + reaper, network modes, limits + quotas, exec audit, `falak sandbox`, `falak mcp`, skill, dashboard list + detail |
| v0.21.0 | Snapshot (disk + memory), fork, pause/resume, idle pause, templates (snapshot of a prepared image), database fork into a sandbox |
| v0.22.0 | Preview URLs (expose a port through Edge), diff vs snapshot, open a PR, promote to site |

Each release: tests (PHP, Go, Vitest), a live check on a real server, signed tag, website changelog + docs page.

## Contracts (v0.20)

These are fixed before the three lanes (agent, control plane, CLI) start, so they can build in parallel.

### Ids and names

- Sandbox id: lower-case ULID (26 chars, `^[0-9a-z]{26}$`), created by the control plane.
- runsc container id `falak-sbx-<id>`; state dir `/var/lib/falak/sandboxes/<id>`; runsc root `/run/falak-sbx`.
- Agent feature: `sandbox.v1` (reported in `version.go` features).

### Agent commands (`contracts/agent-protocol/commands/sandbox.*.schema.json`)

| Command | Payload | Result |
|---|---|---|
| `sandbox.prepare` | `{}` | `{runsc_version, platform}` |
| `sandbox.create` (redeliverable, idempotent by id) | `{id, image, cpus (number, 0.1–64), memory_mb (64–262144), disk_mb (256–1048576), pids (default 1024), network: "none"\|"internet"\|"allowlist", allow_hosts: [host], env: {K: V}, expires_at (unix s), repo?: {url, ref?, token?}}` | `{status: "running", ip?, image_digest, started_at}` |
| `sandbox.exec` | `{id, cmd: [string] (argv; a string cmd is sent as ["sh","-lc",cmd]), cwd?, env?, stdin? (base64, ≤ 1 MB), timeout_s (1–120), max_output (bytes per stream, default 262144)}` | `{exit_code, stdout (base64), stderr (base64), truncated: bool, duration_ms, timed_out: bool}` |
| `sandbox.files.read` | `{id, path (absolute), max_bytes (≤ 5242880)}` | `{content (base64), size, truncated, mode}`; error `not_found` / `is_directory` |
| `sandbox.files.list` | `{id, path}` | `{entries: [{name, type: "file"\|"dir"\|"link"\|"other", size, mode, mtime}]}` (≤ 1000 entries, `truncated`) |
| `sandbox.files.write` | `{id, path, content (base64, ≤ 5 MB decoded), mode? (octal string, default "0644"), mkdirs? (default true)}` | `{size}` |
| `sandbox.files.delete` | `{id, path, recursive?}` | `{}` |
| `sandbox.kill` (redeliverable, idempotent) | `{id, reason?}` | `{}` |
| `sandbox.inventory` | `{}` | `{sandboxes: [{id, status: "running"\|"paused"\|"stopped", oom_killed, started_at, expires_at, memory_bytes, cpu_seconds}]}` |

`sandbox.create` runs `sandbox.prepare` itself when needed. Errors use the usual failed-command shape; `stdout` /
`stderr` are base64 so binary output survives. The agent reaps sandboxes past `expires_at` on its own every minute
(and on start), and reports each reap as an agent request `sandbox.reaped` `{id, reason: "expired"|"oom"|"exited"}`
(a new `contracts/agent-protocol/requests/sandbox.reaped.schema.json`).

### Terminal

`terminal.open` gains an optional `sandbox: {id}` target (exclusive with `container`). The agent runs
`runsc --root=/run/falak-sbx exec falak-sbx-<id> /bin/sh -lc 'command -v bash >/dev/null 2>&1 && exec bash -l; exec sh -l'` under the
existing pty + relay code. Control plane: `TerminalSession::TARGET_SANDBOX`, `ConsoleTargets::forSandbox`, route
`POST /api/v1/sandboxes/{sandbox}/console` (returns the same body as the other console routes).

### HTTP API (control plane; consumed by the CLI and MCP)

All under `/api/v1`, `auth:sanctum` + `org`. JSON in and out. Errors: 422 with `{message, errors}` (Laravel),
404 when the sandbox is not in the token's organization, 409 when the sandbox isn't running.

Sandbox resource:
```json
{"id":"…","name":"brave-otter","status":"running","image":"ubuntu:24.04","server":{"id":"…","name":"main-1"},
 "cpus":1,"memory_mb":1024,"disk_mb":10240,"network":"internet","allow_hosts":[],
 "ttl_seconds":3600,"expires_at":"2026-10-16T20:00:00+00:00","created_at":"…","created_by":{"type":"user|token","name":"…"},
 "ip":"10.88.0.5","kill_reason":null}
```
Statuses: `creating`, `running`, `failed`, `killed`. (`paused`/`stopped` arrive in v0.21.)

| Route | Body / query | Response |
|---|---|---|
| `POST /sandboxes` | `{image (default "ubuntu:24.04"), name?, server? (id or name), cpus? (1), memory_mb? (1024), disk_mb? (10240), ttl? (seconds or "30m"/"2h"; default 3600), network? ("internet"), allow_hosts?, env?, repo?: {url, ref?, secret?}}` | 201 sandbox (status `creating`); `?wait=1` waits up to 60 s for `running` |
| `GET /sandboxes` | `?status=` | `{data: [sandbox]}` |
| `GET /sandboxes/{id}` | | sandbox |
| `PATCH /sandboxes/{id}` | `{name?, extend? (seconds or "30m")}` | sandbox |
| `DELETE /sandboxes/{id}` | | 204 |
| `POST /sandboxes/{id}/exec` | `{cmd: string\|[string], cwd?, env?, stdin? (text), timeout? (1–120, default 60)}` | `{exit_code, stdout, stderr, truncated, timed_out, duration_ms}` (stdout/stderr as UTF-8 text, invalid bytes replaced; `?raw=1` returns base64) |
| `GET /sandboxes/{id}/files` | `?path=` | file → raw bytes (`application/octet-stream`); `?list=1` → `{entries: [...]}` |
| `PUT /sandboxes/{id}/files` | `?path=&mode=`, body = raw bytes (≤ 5 MB) | `{size}` |
| `DELETE /sandboxes/{id}/files` | `?path=&recursive=1` | 204 |
| `GET /sandboxes/{id}/execs` | | `{data: [{id, cmd, exit_code, duration_ms, actor, started_at}]}` |
| `POST /sandboxes/{id}/console` | | console session body (as the other console routes) |

Permissions: `sandboxes.view` (GET), `sandboxes.use` (everything else on your organization's sandboxes),
`sandboxes.manage` (organization settings). Throttles: create 30/min, exec 120/min per token.

### Quotas (`config/sandboxes.php`, defaults)

`max_running` 5, `max_ttl` 86400, `default_ttl` 3600, `max_cpus` 4 / `max_memory_mb` 8192 / `max_disk_mb` 51200 per
sandbox, `max_total_memory_mb` 8192 per organization. Placement: the given server, else the active app server with the
`sandbox.v1` feature and the most free memory; servers can opt out (`servers.sandboxes_enabled`, default true).

### MCP tools (`falak mcp`, stdio, JSON-RPC 2.0, protocol 2025-06-18)

`sandbox_create {image?, name?, ttl?, network?, cpus?, memory_mb?, repo_url?}`, `sandbox_list {}`,
`sandbox_exec {id, command, cwd?, timeout?}`, `sandbox_read_file {id, path}`, `sandbox_write_file {id, path, content}`,
`sandbox_list_files {id, path}`, `sandbox_extend {id, by}`, `sandbox_kill {id}`. Each maps 1:1 to the HTTP API;
results are text content (JSON for structured ones), errors set `isError`.

## Risks

- **gVisor compatibility:** some software misbehaves under gVisor (rare syscalls, some filesystems). Mitigation: the
  spike covers common stacks; document known gaps.
- **Disk on small VPSes:** snapshots with memory are as big as the sandbox's RAM. Quotas and snapshot storage caps.
- **A server's own workloads:** sandboxes compete for CPU/RAM with sites. Per-server opt-out and capacity counting.
- **Network rules:** agent-managed iptables must survive Docker restarts and not touch other rules (own chain).
- **Forks copy, they don't share:** each fork restores its own copy of the snapshot's memory and written files, so
  disk and RAM grow linearly with the fork count. Fork count is capped (10) and counted against quotas.
- **Owning runsc directly** means the agent manages state Docker would have managed: it must recover sandboxes
  after an agent restart or reboot (bundles and state on disk; `runsc list`).

## Spike

2026-10-16, EC2 t3.small (2 vCPU, 1.9 GB, no `/dev/kvm`), Ubuntu 24.04, Docker 29.1.3, runsc release-20261005.0
(gVisor apt repo). Instance and all its resources deleted after the run (36 minutes).

| Capability | Result |
|---|---|
| runsc without KVM | Works (systrap default; ptrace also works) |
| Cold start, alpine | 92 ms with raw runsc; ~400 ms via `docker run` (same as runc) |
| Cold start, node:22 / python:3.13 | ~600 ms / ~540 ms (docker) |
| Exec latency | ~70 ms |
| Snapshot with memory (raw runsc) | Works: a counter resumed where it stopped. Alpine 40 ms / 328 KB; with 50 MB of written files 82 ms / 59 MB, restore 146 ms; node+express 29 MB |
| Snapshot via `docker checkpoint` | Create works, **restore fails** on Docker 29 → drive runsc directly |
| Fork ×3 from one snapshot | Works: 224 ms (alpine), 606 ms for a live node server; each fork independent, own IP, internet works |
| Written files survive snapshot/fork | Works (`apk add`, files, 50 MB blob) |
| Pause / resume | Works; CPU drops to 0 |
| CPU limit | Exact (0.5 → 50%) |
| Memory limit | Enforced; the whole sandbox is OOM-killed, host unaffected |
| Disk limit | `--overlay2=root:self,size=N` exact; Docker `--storage-opt` silently ignored on ext4 |
| Network isolation | Default bridge leaks metadata (IMDSv2), host, other containers; own bridge + iptables blocks all of it with internet working |
| Docker embedded DNS under runsc | Broken → write our own `resolv.conf` |
| Escape smoke checks | gVisor kernel, synthetic dmesg, only sandbox processes, no host devices |
| Idle memory per sandbox | ~17 MB → 60+ idle sandboxes on 2 GB |
| npm / pip installs | Work |

Scripts: the session scratchpad `sbx-spike/` (raw.sh, net.sh, node.sh), to be turned into the agent package.
