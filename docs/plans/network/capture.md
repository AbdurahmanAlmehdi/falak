# On-demand packet capture (phase 5, stream G)

Stream G of [OBSERVABILITY.md phase 5](../OBSERVABILITY.md#phase-5-later): an admin captures the traffic of a server
(one of its interfaces) or of a site's or database's container (its network namespace) for at most 60 s, and
downloads it as a `.pcap` for Wireshark. Captures can hold secrets (cookies, tokens, queries), so everything here is
opt-in, Admin only, audited, size and time bounded, encrypted at rest and deleted after 24 h.

It reuses stream E's machinery ([tools.md](tools.md)): the console target resolution (`ConsoleTargets`), the
container selector, and the agent's namespace pinning (the container's `/proc/<pid>/ns/net` opened first, checked
second, entered as `/proc/self/fd/3` by `nsenter`).

## What a capture is

| Field | Values | Default |
|---|---|---|
| target | a server (its host network), a site (its container; native sites: the host), a database instance (`falak-db-<id>`) | |
| `interface` | servers and native sites: `default` (the default route's interface), `wireguard` (the first WireGuard interface), `docker` (`docker0`). Containers always capture on `any` (every interface of the container's namespace, loopback included) | `default` |
| `duration_s` | 1–60 | 30 |
| `max_bytes` | 1 MiB–100 MiB (pcap file size) | 20 MiB |
| `snaplen` | `128` (headers only) or `0` = full packets (262144) | 128 |
| `filter.host` | an IP or a CIDR (never a name: tcpdump would resolve it with the host's resolver, in a container too) | |
| `filter.port` | 1–65535 (not with `icmp`) | |
| `filter.protocol` | `tcp`, `udp`, `icmp` (`icmp or icmp6`) | |
| `filter.direction` | `in`, `out`, `both` (tcpdump `-Q`) | `both` |

**No free-form BPF.** The expression is built by the agent from the validated fields (`host 10.0.0.5 and tcp and
port 443`, `net 10.0.0.0/24`), with fixed keywords and values that passed `netip` and integer checks, and goes to
tcpdump as one argv element after `--`. Admins don't get a raw-BPF box: the structured fields cover the cases the
panel is for (one peer, one port, one protocol), and a raw expression would be one more parser to trust. A capture
without a filter takes everything on the interface.

**Headers only by default.** A snap length of 128 bytes keeps L2–L4 headers and the start of the payload (enough for
TLS SNI and most HTTP request lines, not bodies). Full packets are a checkbox with its own warning.

## Agent: `net.capture` (feature `net.capture`)

### Payload

```json
{
  "capture_id": "01JA…",
  "interface": "default",
  "container": { "labels": { "falak.site": "shop" } },
  "filter": { "host": "10.0.0.5", "port": 443, "protocol": "tcp", "direction": "both" },
  "snaplen": 128,
  "duration_s": 30,
  "max_bytes": 20971520
}
```

`interface` and `container` exclude each other. The agent re-validates every field (`nettool.CaptureSpec`).

### Running tcpdump

```
tcpdump -n -p -U -i <iface> -s <snaplen> -w - -Z <user> [-Q in|out] [-- <expression>]
nsenter --net=/proc/self/fd/3 -- tcpdump …          (containers; fd 3 the pinned namespace)
```

- **argv only, fixed template, no shell.** The interface is resolved by the agent (`/proc/net/route`, the
  `DEVTYPE=wireguard` interfaces in `/sys/class/net`, `docker0`) and must exist; container captures use `any`.
- `-p`: never promiscuous (no other hosts' frames on a shared segment). `-n`: no name lookups.
- `-Z tcpdump` (or `nobody` when that user doesn't exist): tcpdump drops root right after opening the capture
  device. With `-w -` it opens no file at all; Ubuntu's AppArmor profile for tcpdump allows writing to stdout.
- **The pcap comes on stdout (`-w -`, `-U` flushes per packet).** The agent reads the stream record by record (24-byte
  global header, 16-byte record headers), counts packets and bytes, hashes (SHA-256) and writes complete records to
  its temp file. A record that would cross `max_bytes` is not written and ends the capture ("size limit reached"):
  the file is always a valid pcap of at most `max_bytes`. tcpdump never writes a file, so its privileges, AppArmor
  and the file system don't meet.
- tcpdump's stderr (`listening on …`, `N packets captured … dropped by kernel`) streams as the command's stderr
  output. Packet contents never reach the output, the logs or the control plane's command log.
- The capture ends at `duration_s` (SIGINT to tcpdump's process group, which flushes and prints its counters), at the
  size cap, on cancel, or when tcpdump exits by itself (a bad interface: the command fails with tcpdump's message).
- **tcpdump missing:** provisioning installs it (`servers.base_packages`); on servers provisioned earlier the agent
  installs it on first use (`apt-get install tcpdump`, as `net.wireguard.apply` does with wireguard-tools). The
  command's timeout leaves room for that.

### Files on the server

- `/var/lib/falak/captures/` (root, 0700) holds one `cap-<random>` directory per capture (`os.MkdirTemp`, 0700, owned
  by root), with `capture.pcap` created `O_CREAT|O_EXCL|O_NOFOLLOW`, 0600. Nothing in the path comes from the payload
  (the capture id is only used in URLs), so there is no path traversal, and no other user can plant a symlink in a
  root-owned 0700 directory.
- The directory is removed when the command ends, whatever the outcome (uploaded, failed, cancelled). Directories
  left by a crash are removed on the next capture (anything older than 10 minutes).
- **Disk:** the agent refuses a capture when the file system has less than `max_bytes` + 256 MiB free. At most 2
  captures run at once per agent (a third fails with "too many captures running"), so a server never holds more than
  2 × 100 MiB of captures.

### Progress and cancel: `net.capture.progress` request

Every 5 s while capturing, the agent posts `POST /agent/v1/requests/net.capture.progress`
`{capture_id, bytes, packets}`. The control plane stores the counters (the panel's progress) and answers
`{cancel: bool}`: true when the user pressed Cancel, the capture is gone, or it belongs to another server. On
`cancel` the agent stops tcpdump, uploads nothing and fails the command as cancelled (exit 130). The command channel
can't cancel a delivered command, so this is how Cancel reaches the agent; it also prints a progress line
(`12 packets, 3.1 KiB`) to stdout.

### Upload: `POST /agent/v1/uploads/net.capture/{capture_id}?offset=N`

After the capture, the agent uploads the file in chunks of 4 MiB over its mTLS identity (Fleet's new generic upload
route, see below), with headers:

- `Content-Type: application/octet-stream`, `Content-Length` (required),
- `X-Falak-Chunk-Sha256`: the chunk's SHA-256 (Fleet refuses a mismatch, 422),
- `X-Falak-Upload-Size` / `X-Falak-Upload-Sha256`: the whole file's size and SHA-256 (known before the upload starts).

The control plane accepts a chunk only when:

- the agent's server is the capture's server (otherwise 409 `unknown_capture`, the same answer as for an id that
  doesn't exist: no capture of another server is ever confirmed);
- the capture is still `capturing` or `uploading` (a finished, failed, cancelled or expired capture answers 409:
  a replayed or late upload changes nothing);
- `offset` equals the bytes received so far, and the declared size is at most the capture's `max_bytes` and doesn't
  change between chunks. A retried chunk that already landed (`offset + length == received` and the same chunk hash
  as the last one) answers 200 again, so a lost reply is harmless.

The last chunk (`received == size`) is verified: the stored file is decrypted and hashed, and must match
`X-Falak-Upload-Sha256`; otherwise the chunks are deleted and the capture fails ("checksum mismatch"). Then the
capture is `ready`. The command's result (`bytes`, `packets`, `sha256`, `ended`, `interface`, `namespace`,
`expression`) is recorded when the command finishes; a command that finished without a verified upload marks the
capture failed.

Retries: the agent retries a chunk 3 times (1 s, 2 s, 4 s) on network errors, 5xx, 408 and 429.

## Control plane (Terminal module)

### Fleet: generic agent uploads

`Falak\Fleet\Contracts\AgentUploads` (`register(type, handler)`) and `AgentUploadHandler`
(`receive(AgentCaller, id, offset, chunk, headers): array`), like `AgentRequests`. Route
`POST /agent/v1/uploads/{type}/{id}` (`AuthenticateAgent`, `throttle:fleet-agent-uploads`, 300 a minute per agent):

- 404 for an unregistered type, 411 without `Content-Length`, 413 above `fleet.upload_chunk_bytes` (8 MiB), the body
  read with that limit;
- 422 when `X-Falak-Chunk-Sha256` is missing or doesn't match the body;
- the handler's `AgentRequestRefused` becomes 409 with its reason.

### Table `terminal_net_captures`

`id`, `organization_id`, `user_id`, `server_id`, `server_name`, `target_type` (server, site, database), `target_id`,
`target_name`, `container` (display), `interface`, `filter` (json), `snaplen`, `duration_s`, `max_bytes`,
`command_id`, `status` (queued → capturing → uploading → ready; failed, cancelled, expired), `cancel_requested_at`,
`bytes_captured`, `packets_captured`, `bytes_received`, `size_bytes`, `sha256`, `last_chunk_sha256`, `data_key`
(sealed), `result` (json), `error`, `started_at`, `finished_at`, `expires_at`, timestamps.

### Encryption at rest

Each capture gets a random 32-byte data key, sealed with the organization's data key
(`Sealer::seal(key, Sealer::aad('net-capture', organization, capture), organization)`) and kept on the row. Each chunk
is sealed with AES-256-GCM (`Aead::seal`, AAD `net-capture:<capture>:<offset>`, so chunks can't be reordered or moved
to another capture) and stored as `net-captures/<capture>/<offset, 12 digits>.bin` on the disk
`terminal.captures.disk` (default `local`, any Flysystem disk works: chunks are written whole, never appended).
Laravel's APP_KEY encryption isn't used (the architecture test forbids it).

### Permission, limits, audit

- **`network.capture`**: Admin only (owners have every permission), group `network`, "Capture network traffic on
  servers and containers (pcap downloads may contain sensitive data)". Site and database targets also need
  `sites.view` / `databases.view`, as consoles do. Downloading needs `network.capture` too and belongs to the
  capture's organization (another organization's id is a 404); within the organization, any member with the
  permission can download (the requester and the other admins).
- **Limits per organization:** at most 3 captures in progress (queued, capturing or uploading, younger than
  15 minutes), at most 10 started in the last hour; both counted and inserted under one cache lock per
  organization. Over the limit: 429 "Wait for the organization's other captures to finish." / "At most 10 captures an
  hour per organization."
- **Feature:** only agents listing `net.capture` get the command; others: 422 "The Falak agent on this server is too
  old for packet capture; update it first."
- **Functions** are refused (no network of their own), as for tools.
- **Audit:** `network.capture_started` (target, server, container, interface, filter, duration, size cap, snap
  length), `network.capture_cancelled`, `network.capture_downloaded` (size, sha256). The finished capture's size
  and packet count are on the row; the audit never holds packet data.

### Expiry

Captures are downloadable for 24 h (`expires_at`). The existing `terminal:sweep` job (every minute) fails captures
stuck in progress for 15 minutes (and deletes their chunks), deletes the chunks of ready captures past `expires_at`
and marks them `expired` (the row stays for the history), and deletes rows after 30 days.

### Routes

| Route | Answer |
|---|---|
| `POST servers/{server}/net-captures` · `api/v1` same | 201 `{data: Capture}` |
| `POST sites/{site}/net-captures` `{server_id?, service?, …}` · API same | 201 |
| `POST databases/instances/{instance}/net-captures` · API same | 201 |
| `GET net-captures?target_type=&target_id=` · API same | the organization's last 10 captures of the target |
| `GET net-captures/{capture}` · API same | `{data: Capture}` |
| `POST net-captures/{capture}/cancel` · API same | `{data: Capture}` |
| `GET net-captures/{capture}/download` · API same | the pcap (`application/vnd.tcpdump.pcap`, `falak-<target>-<date>.pcap`), streamed and decrypted chunk by chunk; 410 once expired |

`Capture` is `{id, target_type, target_id, target_name, server_id, server_name, container, interface, filter,
snaplen, duration_s, max_bytes, status, bytes_captured, packets_captured, size_bytes, sha256, error, user_name?,
created_at, started_at, finished_at, expires_at, download_url}`.

## UI

- **Server page:** a "Packet capture" section (`registerServerSections`, order 710) under Network tools.
- **Sites:** a "Packet capture" action in the service panel's `⋯` (hidden for functions), with the server and service
  pickers of the tools dialog.
- The form: interface (servers and native sites), duration, size cap, filter fields, "Capture full packets". Start
  opens a confirmation: "Captures may contain sensitive data (cookies, tokens, personal data). The file is kept
  encrypted for 24 hours and every download is audited." Then the progress (status, packets and bytes so far, a bar
  for the elapsed time), Cancel, and the download link once ready. The history lists the target's captures with their
  status, size and expiry.
- Database containers: API and CLI (as for tools).

## CLI

```
falak net capture (--server NAME|ID | --site SITE [--on SERVER] [--service NAME] | --db INSTANCE)
    [--interface default|wireguard|docker] [--duration 30] [--max-size 20] (MiB) [--full]
    [--host IP|CIDR] [--port N] [--protocol tcp|udp|icmp] [--direction in|out|both] [-o FILE]
```

It starts the capture, prints progress, waits for `ready` and downloads to `-o` (default
`falak-capture-<id>.pcap`, created exclusively: an existing file is never overwritten). Ctrl-C cancels the capture.

## Security review

| Concern | Answer |
|---|---|
| argv injection | fixed template; interface resolved by the agent (or a fixed kind); filter values are `netip` addresses/prefixes, integers and enums, joined with fixed keywords into one argv element after `--`; validated by the JSON schema, `NetCaptureSpec` (PHP) and `CaptureSpec` (Go) |
| shell | none: `exec` with argv |
| path traversal | file and directory names never come from the payload (`MkdirTemp` + a fixed name); the capture id only appears in URLs, validated as a ULID |
| symlink attacks | parent `/var/lib/falak/captures` created 0700 and checked to be a root-owned directory (not a symlink, `Lstat`); file opened `O_EXCL|O_NOFOLLOW`; tcpdump writes no file |
| namespace pinning | stream E's: the namespace fd is opened before the pid is checked (cgroup + second inspect), entered via `/proc/self/fd/3` |
| disk exhaustion | `max_bytes` ≤ 100 MiB enforced by the agent record by record, free-space check before starting, 2 captures per agent, removal on every exit path and of crash leftovers |
| upload authentication | mTLS agent identity; the capture must belong to the agent's server; bound to the capture id; per-chunk and whole-file SHA-256; only while `capturing`/`uploading`; strict offsets; declared size ≤ `max_bytes`; chunk ≤ 8 MiB |
| replay | an uploaded (ready) or settled capture refuses chunks (409); a duplicate of the last chunk is an idempotent no-op; an earlier offset is refused |
| cross-organization download | lookups are scoped by the current organization (404 otherwise) and need `network.capture` |
| pcap content in logs | tcpdump's stderr only (counters, interface); packets never go to stdout events, the agent log, the audit or Laravel logs; the download streams without buffering the file in memory |
| at rest | AES-256-GCM per chunk with a per-capture key sealed under the organization's key; deleted after 24 h |

## Tests

- **Go:** spec validation (fields, ranges, `-` prefixes, names refused as hosts, icmp + port), the BPF expression and
  argv (host and container), interface resolution, the pcap record reader (counting, the size cap at a record
  boundary, a bad magic, a truncated record), the temp directory (modes, removal, stale cleanup), the upload client
  (chunks, headers, retries, a 409) against `httptest`, a whole capture with a fake tcpdump (duration, cancel via
  progress, size cap, tcpdump failing), the CLI against an `httptest` API.
- **PHP:** permissions (Admin and owner yes; Developer, Viewer 403), validation, payloads for each target, a refused
  function, "agent too old", the organization limits (3 in progress, 10 an hour), audit entries, progress and cancel
  replies, the upload route (another server's agent, offsets, a replayed chunk, a bad chunk hash, size above
  `max_bytes`, after ready, the final checksum), encryption (no plaintext on disk, AAD binding), download (decrypted
  bytes, other organization 404, expired 410, audited), the settle listener, the sweep (expiry, stuck captures).
- **Vitest:** the request body built from the form and the progress formatting.

## Not in this stream

- Raw BPF expressions, ring buffers / continuous capture, capturing more than one container at once.
- Captures on functions.
- A database-instance panel entry (API and CLI only, as for tools).
