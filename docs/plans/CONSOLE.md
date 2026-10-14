# Consoles for sites, containers and databases (plan, v0.13.0)

Today Servers → Terminal opens a root (or chosen user) shell on a server. Keystrokes travel browser → panel (HTTP
POST) → a `terminal.input` command → the agent's long-poll; output travels agent → outbox (flushed every 250 ms) →
`POST /agent/v1/commands/{id}/events` → Reverb → browser. That works, but every keystroke costs a few hundred
milliseconds. v0.13.0 adds:

- **Site consoles:** a shell where the site runs.
  - Docker site: `docker exec` into its running container.
  - Compose site: `docker exec` into one of its services.
  - Native runtimes (PHP, Node, Bun, Deno, static): a shell as the site's unix user, in its current release, with
    the site's environment.
- **Database consoles:** a shell in a database container (`falak-db-<id>`).
- **A fast path, the terminal relay:** a small Go WebSocket relay that pipes bytes between the agent and the browser
  or CLI. Typing costs one network round trip.
- **`falak console`:** the same consoles from the CLI. `falak ssh <server>` keeps running real OpenSSH, as today.

The old path stays. Recording and playback, shared watchers, and installs or agents without the relay keep working.

## Pieces

| Piece | Where |
|---|---|
| Relay server (`falak-relay`) | `agent/internal/relay`, `agent/cmd/falak-relay`. Baked into the control-plane image, run as the compose service `relay` (entrypoint role `relay`). |
| Agent: container target and relay client | `agent/internal/pty`, feature `terminal.v2` |
| `terminal.open` schema | `contracts/agent-protocol/commands/terminal.open.schema.json` (additive fields) |
| Control plane: targets, tickets, routes | `control-plane/modules/Terminal` (plus Sites and Databases for buttons and lookups) |
| UI | the Terminal session page (relay with fallback), Console on the site page and the database instance page |
| CLI | `falak console` (`agent/internal/cli`) |
| Edge routes, compose service | `deploy/edge/Caddyfile`, `deploy/compose.yml`, `deploy/control-plane/entrypoint.sh`, `control-plane/Dockerfile`, `sim/` |

## Tokens (control plane → relay)

The relay and the control plane share no new secret. Both derive the key from `APP_KEY` (the relay service runs from
the control-plane image with the same env):

```
app_key_bytes = base64-decode(APP_KEY without "base64:")   (or the raw bytes when there is no prefix)
key           = HMAC-SHA256(key = app_key_bytes, message = "falak-terminal-relay/v1")
token         = b64url(payload_json) + "." + b64url(HMAC-SHA256(key, b64url(payload_json)))
```

- `b64url` is URL-safe base64 without padding.
- The payload is a JSON object: `{"v":1,"sid":"<session id>","aud":"agent"|"client","sub":"<server id | user id>","w":true|false,"exp":<unix s>,"n":"<nonce>"}`.
- The relay checks the signature (constant time), `v == 1`, `aud`, `exp > now` and `sid` == the session in the path.

| Token | Lifetime | `w` | `sub` | Reuse |
|---|---|---|---|---|
| Agent token | session max duration + 300 s | true | the server id | The agent reconnects with it as often as it needs. |
| Client ticket | 60 s (time to connect) | can this user type | the user id | Single use: the relay remembers `n` until `exp` and refuses a second connection with the same nonce. |

- **Agent tokens carry `fp`:** the lowercase hex SHA-256 of the agent's client certificate. The relay requires
  `X-Falak-Client-Cert-Fingerprint` to match it, so a leaked token is useless with any other Fleet certificate.
- **Control tokens** (`aud: "control"`) revoke access. A kick with `keep: <user id>` drops every client whose
  ticket `sub` isn't that user; without `keep` it drops all clients. The agent stays attached.
- **When the control plane kicks:** on unshare (keeping the owner) and on close (everyone), best effort. Relay
  connections are authorized when they connect; other permission changes (a role change, removal from the
  organization) take effect at the next ticket. The session itself ends within the idle timeout or the one-hour cap.

**Test vector** (both sides have a unit test for it):

```
APP_KEY  = base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=   (32 zero bytes)
key hex  = 0f39093fd3813fb62ac265d353c61a32221cded696810b5a38c87671d252bc9e   # gitleaks:allow (test vector)
payload  = {"v":1,"sid":"01JTESTSESSION0000000000000","aud":"client","sub":"01JTESTUSER00000000000000000","w":true,"exp":1900000000,"n":"abc123"}
token    = eyJ2IjoxLCJzaWQiOiIwMUpURVNUU0VTU0lPTjAwMDAwMDAwMDAwMDAiLCJhdWQiOiJjbGllbnQiLCJzdWIiOiIwMUpURVNUVVNFUjAwMDAwMDAwMDAwMDAwMDAwIiwidyI6dHJ1ZSwiZXhwIjoxOTAwMDAwMDAwLCJuIjoiYWJjMTIzIn0.PtCiVt0KkqNOxHVpYCgRJumDkqjyxeKI2TIuZEm8x7E
```

The PHP side signs the exact bytes it encodes, so key order doesn't matter for verification. The vector only pins
the algorithm.

## Relay HTTP API

The relay listens on `FALAK_RELAY_LISTEN` (default `:8080`) and reads `APP_KEY`.

| Endpoint | Who | Auth |
|---|---|---|
| `GET /agent/v1/relay/sessions/{sid}` (WebSocket) | agent | `Authorization: Bearer <agent token>`. The edge routes it only on the mTLS agent host, with `X-Falak-Client-Cert-Fingerprint` set; the relay refuses a request without that header. |
| `GET /terminal/v1/sessions/{sid}?ticket=<client ticket>` (WebSocket) | browser, CLI | the ticket (browsers can't set headers on WebSockets) |
| `POST /internal/v1/sessions/{sid}/kick` | control plane | `Authorization: Bearer <control token>` (`aud: "control"`, 60 s, optional `keep`). Not routed by the edge: only containers on the backend network reach it. |
| `GET /healthz` | compose healthcheck | none; answers `ok` |

- **Subprotocol:** `falak.terminal.v1`. The relay accepts it when offered and doesn't require it.
- **Size limits:** a read message is at most 1 MiB. Input from a client is at most 64 KiB per message.
- **Keepalive:** pings every 25 s, because Cloudflare and the edge drop idle connections after about 100 s.

### Frames
- **Binary frames are raw bytes.**
  - agent → relay → every client: PTY output.
  - client → relay → agent: keystrokes. Only from clients whose ticket has `w: true`; others are dropped silently.
- **Text frames are JSON objects with a `t` field:**

| `t` | Direction | Meaning |
|---|---|---|
| `resize` `{cols, rows}` | client → agent (writers only) | window size, 1–1000 each |
| `exit` `{reason, code}` | agent → clients | the session ended (`reason` as in terminal.open's result, `code` int or null). The relay forwards it, then closes the clients normally. |
| `waiting` | relay → client | No agent is attached yet (sent right after connecting). Clients keep waiting. |
| `ready` | relay → client | The agent is attached. Sent right after the replay, or when the agent attaches later. |
| `error` `{message}` | relay → client | Sent before the relay closes a client for a reason the user should see. |

### Session hub
- **Agents:** at most one per session. A new agent connection replaces the old one, which the relay closes.
- **Clients:** many per session (the owner, watchers of a shared session).
- **Replay:** the relay keeps the last 256 KiB of output per session. A new client first gets it as one binary
  frame, then `ready`, then live output. The browser resets xterm before writing the replay, so a reconnect redraws
  instead of duplicating.
- **Waiting:** a client that connects before the agent waits up to 30 s for it. Then it gets
  `error {message:"The server did not attach to this session."}` and is closed.
- **End:** after `exit`, or when the agent disconnects and doesn't come back within 30 s, the hub sends
  `exit`/`error` to its clients, closes them and is dropped. Hubs with nothing attached are swept after 60 s.
- **Writers:** keystrokes from all writer clients go to the agent in order, with no ownership arbitration (same as
  today's shared sessions).
- **No agent attached:** the relay buffers up to 64 KiB of keystrokes while no agent is attached and flushes them
  when one attaches.
- **Memory:** the replay ring grows lazily. A client more than 256 frames or 1 MiB behind is disconnected. The
  service runs with `GOMEMLIMIT=48MiB` under a 64 MB limit.

## Agent (`terminal.v2`)

`terminal.open` gets two optional fields. The control plane sends them only to agents that list `terminal.v2` in
their facts, because agents reject unknown fields.

```json
"relay_token": "<agent token>",
"container": {
  "name": "falak-db-01abc…",                                                   // or:
  "labels": {"com.docker.compose.project": "shop", "com.docker.compose.service": "web"},
  "user": "www-data",                                                          // optional: docker exec -u
  "workdir": "/app"                                                            // optional: docker exec -w
}
```

**`container` (exactly one of `name` and `labels`):**
- The agent resolves `labels` to the newest running container matching all of them (Docker's API, `ContainerList`).
  With `name`, the container must exist and run.
- No match fails the command with the error "No running container for this console (is the site deployed?)".
- The PTY then runs, as root (the agent):

  ```
  docker exec -it -e TERM=xterm-256color [-u user] [-w workdir] <container> \
      sh -c 'if command -v bash >/dev/null 2>&1; then exec bash; else exec sh; fi'
  ```

  `user`, `shell` and `cwd` of the payload don't apply to a container session. `env` is passed with `-e` (sorted).
- Idle timeout, close, resize, exit code and the reasons all work as for a host shell.
- **Ending a container shell:** killing a `docker exec` client doesn't end the process it started (moby#9098). So the
  exec sets `FALAK_CONSOLE_SESSION=<sid>`, and on close, idle or timeout the agent also signals every host process
  whose environment carries it: SIGHUP, then SIGKILL after 3 s.

**`cwd` that doesn't exist (all sessions):** the agent starts in the user's home instead and prints one line first:
`falak: <cwd> does not exist; starting in <home>`. A native site that was never deployed has no `current`.

**`relay_token`:**
- The agent dials `wss://<agent API base>/relay/sessions/<sid>`, where the base is the same one it long-polls (for
  example `https://agents.example/agent/v1`). It uses its mTLS client certificate over HTTP/1.1, with no h2 in ALPN
  so the upgrade works.
- **Output:** every PTY read is sent as one binary frame. It is also emitted as `output` events as today, which keep
  recording, playback and old clients working.
- **Input and resize:** binary frames from the relay are written to the PTY; text `resize` frames resize it.
  Today's `terminal.input` and `terminal.resize` commands keep working too.
- **Exit:** when the session ends, the agent sends `{"t":"exit","reason":…,"code":…}` and closes normally.
- **Failures:**
  - A failed dial or a dropped connection is retried while the session lives, with backoff from 0.5 s up to 5 s.
  - A relay that can't keep up (send queue of 256 chunks full) is disconnected and redialled. Its replay buffer
    covers the gap as far as it can.
  - The relay being down never fails the session.

## Control plane

**Sessions:** `terminal_sessions` gets these nullable columns:
- `target_type`: `server` (default), `site` or `database`;
- `target_id`: the site or database instance id;
- `target_name`: the site or instance name at open time, like `server_name`;
- `container`: the resolved selector, kept for display (`docker:<name>`, `compose:<service>`, or null for native and server);
- `relayed`: bool, true when the agent was given a relay token.

**Opening a console (`OpenSession` takes a target):**

| Target | Server | Runs | Notes |
|---|---|---|---|
| Server | the given server | today's root shell or chosen user | |
| Site, native runtime | a site server (default: the leader target) | the site's `unix_user`, `cwd` = the current release path, `env` = the site's variables for that server plus TERM | |
| Site, Docker | a site server | `container.labels = {"falak.site": <slug>}` | |
| Site, Compose | a site server | `container.labels = {"com.docker.compose.project": <slug>, "com.docker.compose.service": <service>}` | The service is required when the stack has several. The UI and CLI list them. |
| Site, Function | none | refused (422) | "Functions run on demand; they have no console." |
| Database instance | the instance's server | `container.name = falak-db-<id>` | |

- **Permissions:** the same `terminal.open` permission as server terminals, plus being able to see the site or instance.
- **Audit:** `terminal.session_opened` gains `target_type`, `target_id` and `target_name`.

**Relay settings (`config/terminal.php`):**
- `relay.enabled`: env `FALAK_TERMINAL_RELAY`, default false. The production compose sets it to 1.
- `relay.url`: env `FALAK_TERMINAL_RELAY_URL`, default `APP_URL` with http(s) changed to ws(s), plus `/terminal/v1`.

The agent token goes into `terminal.open` only when the relay is enabled and the agent supports `terminal.v2`
(otherwise `relayed` is false).

**Routes:**
- **Web** (session auth, existing middleware):
  - `POST terminal/sessions/{session}/ticket` answers `{url, writable}`, where `url` is
    `<relay.url>/sessions/<id>?ticket=…`. It answers 404 when the session isn't relayed or is closed, and 403
    unless the user may view the session.
  - `POST sites/{site}/console` `{server_id?, service?}` opens a site console and redirects to the session page.
  - `POST databases/instances/{instance}/console` does the same for a database.
- **API** (`auth:sanctum`, `org`, prefix `v1`):

| Route | Answer |
|---|---|
| `POST v1/sites/{site}/console` `{server_id?, service?, cols?, rows?}` | 201 `{data: {session_id, url, writable, server_id, server_name, target_type, target_name, container}}` |
| `POST v1/databases/instances/{instance}/console` `{cols?, rows?}` | same shape |
| `POST v1/servers/{server}/console` `{user?, cols?, rows?}` | same shape |
| `POST v1/terminal/sessions/{session}/ticket` | `{data: {url, writable}}` (reconnect) |
| `DELETE v1/terminal/sessions/{session}` | closes the session |
| `GET v1/sites/{site}/console` | `{data: {servers: [{id, name, leader}], services: [..] or null, runtime}}` (what the CLI offers) |

The API answers 409 `{message: "The terminal relay is off on this installation."}` when the relay is disabled,
because the CLI only speaks the relay.

## UI

- **Session page:**
  - When the session is `relayed`, the page gets a ticket and opens the WebSocket. It resets xterm on the replay,
    sends keystrokes as binary frames and sends resizes as `resize`.
  - It falls back to today's POST input and Reverb output in any of these cases:
    - the ticket call fails;
    - the socket doesn't open within 5 s;
    - an `error` frame arrives;
    - no `ready` comes within 8 s;
    - it stays `waiting` for more than 10 s;
    - the socket drops 3 times in a minute.
  - While the socket is live, the Reverb output listener is ignored, so output isn't written twice.
  - The header shows the target: "Console · shop (container web) on main-1", for example.
- **Site page:** a Console button opens a session. It asks first only when there's a real choice: the server when
  the site runs on several, the service for compose.
- **Database instance page:** a Console button.
- **Terminal index:** lists target sessions too, with the target name.

## CLI

```
falak console <site> [--server NAME|ID] [--service NAME]     site console
falak console --db <instance name|id>                        database container shell
falak console --host <server name|id> [--user NAME]          server shell (root unless --user)
```

`falak ssh <server>` is unchanged: it runs OpenSSH to the server (it needs port 22 and your key). Its help points
to `falak console` for shells in sites, containers and databases.

- It opens the session through the API and dials the returned URL. The local terminal goes into raw mode
  (`golang.org/x/term`).
- stdin is sent as binary frames and SIGWINCH as `resize`. Output goes to stdout.
- On `exit`, it restores the terminal and exits with the remote code (0 when null).
- On a dropped socket, it asks for a new ticket and reconnects, up to 5 times in a row, silently.
- Ctrl-C is sent to the remote shell, not handled locally.
- If stdin isn't a terminal, it doesn't use raw mode or resizes. Piping keystrokes still works.
- It prints the target to stderr on connect: `Connected to shop (container web) on main-1. Exit the shell to close
  the session.`

## Edge and install

- **`deploy/edge/Caddyfile`:**
  - Agent host: `/agent/v1/relay/*` (client cert required) goes to `relay:8080` with the fingerprint header. It is
    matched before `@machine_with_cert`.
  - Panel host: `/terminal/v1/*` goes to `relay:8080`.
- **`deploy/compose.yml`:**
  - A new `relay` service: the control-plane image, `command: ["relay"]`, networks `edge`, a healthcheck on
    `/healthz`, a 64m memory limit.
  - The cp-env gets `FALAK_TERMINAL_RELAY: "1"`.
- **`control-plane/Dockerfile`:** builds `falak-relay` for the target arch into `/usr/local/bin/falak-relay`.
- **`deploy/control-plane/entrypoint.sh`:** role `relay` runs `exec falak-relay`.
- **`sim/compose.yml` and the sim edge:** the same, so `sim/e2e` can exercise it.
