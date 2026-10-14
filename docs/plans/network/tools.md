# Network tools and Cloudflare analytics (phase 4, stream E)

Stream E of [OBSERVABILITY.md phase 4](../OBSERVABILITY.md#phase-4-network-v017x) covers two things.

1. **Network tools in the panel and the CLI.** You can run `ping`, a traceroute, a DNS lookup, an HTTP check
   (`curl -v` with a timing breakdown) and a TCP connection test from a server (its host network) or from inside a
   site's or database's container (the container's network namespace). Output streams live. Every run is audited and
   needs its own permission.
2. **Cloudflare analytics** for proxied zones, read through the organization's existing Cloudflare connection. It
   shows requests, cached vs. origin traffic, bytes, threats blocked and countries.

Both are read-only: nothing here changes traffic.

## 1. Network tools

### Transport: command events, not the terminal relay

The index suggests the terminal relay. A tool run is a one-shot, read-only process that lasts at most 60 s and
prints a few kilobytes, so it doesn't need what the relay and terminal sessions add: keystrokes, resize, sharing,
idle timeouts, replay or recordings. It goes through the plain command path instead:

- The control plane dispatches a new agent command, **`net.tool`**, with a timeout of `timeout_s + 15`.
- The agent streams the tool's output as ordinary `output` events (`stdout` text, flushed every 200 ms).
- The browser shows them with the existing `CommandLog` component: the initial load comes from
  `/fleet/commands/{id}`, live updates arrive on `private-fleet.commands.{id}` (Reverb), and it polls when Echo is
  down.
- The CLI polls `GET /api/v1/net-tools/runs/{run}?after=<seq>` every 500 ms until the run settles. That is the same
  polling `falak deploy --wait` does.

So the feature adds nothing to the relay, the edge or the compose stack, and it works on installs without the relay.
The output stays in Fleet's command output like any other command's (not `terminal.*`, so it isn't private output).

### Tools

| Tool | Implementation | Arguments (validated on both sides) |
|---|---|---|
| `ping` | system `ping` (iputils): `ping -n -c <count> -W 2 -w <timeout> <ip>` | `target` host or IP; `count` 1–20 (default 4) |
| `traceroute` | system `traceroute -n -q 1 -w 2 -m <hops> <ip>`, else `mtr -n -r -w -c 3 -m <hops> <ip>`, else `tracepath -n -m <hops> <ip>` | `target`; `max_hops` 1–30 (default 20) |
| `dns` | Go resolver (`net.Resolver`, pure Go, dialing the chosen server) | `target` name (an IP for `PTR`); `record_type` A, AAAA, CNAME, MX, TXT, NS, SRV, PTR (default A); `nameserver` IP (optional) |
| `http` | Go `net/http` with `httptrace`: DNS, connect, TLS, request sent, first byte, total; request and response headers like `curl -v`; TLS version, cipher, ALPN and the certificate chain (subject, issuer, expiry); body counted, not printed | `url` http(s)://host[:port]/path?query (≤ 2048 chars); `method` GET or HEAD; `connect_to` IP (optional, like `curl --resolve`: test an origin behind Cloudflare); `insecure` (skip certificate verification, flagged in the output) |
| `tcp` | Go `net.Dialer`: one line per attempt with the address and the time it took, or the error (refused, timeout, no route) | `target`, `port` 1–65535, `count` 1–10 (default 3) |

**Common to every tool:**

- `timeout_s` is 1–60 (default 30). The agent kills the whole process group when it runs out.
- **Missing binaries.** If `ping` or every traceroute binary is missing, the run prints
  `ping is not installed on this server (apt install iputils-ping)` and exits 127. For traceroute it also suggests
  the TCP test. Ubuntu server images ship `ping` and `tracepath`, so the fallback chain nearly always finds
  something. Provisioning doesn't install anything for this.
- **Name resolution.** `ping` and `traceroute` get an IP: the helper resolves the name itself first, and prints
  `target example.com → 93.184.216.34`. Then the system binary never resolves anything with the host's
  configuration inside a container (see below).
- **Link-local addresses are refused** for `http` and `tcp`: 169.254.0.0/16 and fe80::/10, which covers cloud
  metadata endpoints such as `169.254.169.254`. The check runs at dial time, after resolution, so a DNS name that
  points there is refused too. Developers can deploy code anyway, so this isn't a hard boundary. It just keeps a
  panel button from becoming a one-click credentials dump.

### Where it runs

| Target | Namespace | How |
|---|---|---|
| Server | host | `falak-agent nettool` (the spec on stdin) |
| Native site (PHP, Node, Bun, Deno, static) | host (native sites use the host network) | same; the run is recorded with the site as its target |
| Docker site | its newest running container (`falak.site=<slug>`) | `nsenter --net=/proc/self/fd/3 -- falak-agent nettool`, fd 3 the container's network namespace |
| Compose site | a service's container (`com.docker.compose.project` + `.service`; the service is required when the stack has several) | same |
| Database instance | `falak-db-<id>` | same |
| Function | refused (422, "Functions run on demand; they have no network of their own to test from.") | |

**How the namespace is entered:**

- **The agent re-execs itself** (`/usr/local/bin/falak-agent`, falling back to `os.Executable()`) as a short-lived
  helper. In a container it runs under util-linux `nsenter`, which every supported distribution ships. Only the
  network namespace changes, so the container image needs none of the tools.
- **Why not `setns` from Go:**
  - In the long-running agent, a locked OS thread doesn't help: the Go resolver and dialer use other goroutines, and
    `os/exec` forks from arbitrary threads.
  - A helper process is simple and safe, and it keeps a stuck tool out of the agent.
- **Finding the container's process.** The agent resolves the container (by name, or the newest running one by
  labels, exactly like consoles) and reads `State.Pid` from inspect. A container that isn't running fails the run
  with "No running container to run the tool in (is it deployed?)".
- **Pinning the namespace (pid reuse).** The agent opens `/proc/<pid>/ns/net` first and keeps the descriptor. Only
  then does it check that the process is the container's: `/proc/<pid>/cgroup` must name the container id, and a
  second inspect must show the same pid and `StartedAt`. The descriptor goes to the child as fd 3 and nsenter enters
  `/proc/self/fd/3`, so a pid reused after the checks can't redirect the run into another process's network.
- **The container's DNS.** With only the network namespace switched, the helper would still read the host's
  `/etc/resolv.conf`, whose `127.0.0.53` doesn't exist inside the container. So the agent reads the container's
  files from Docker's host-side copies (inspect's `ResolvConfPath` and `HostsPath`, under Docker's data root), never
  through `/proc/<pid>/root`, whose `/etc` the container controls (a symlink to a FIFO or a device, a watchdog, would
  block or reboot the host). They are opened `O_RDONLY|O_NONBLOCK|O_NOFOLLOW`, must be regular files by `fstat`, and
  are read up to 64 KiB. Their **contents** go to the helper in its spec; the helper opens nothing the container
  controls. Its resolver dials those nameservers (Docker's embedded `127.0.0.11` on user networks) and checks the
  hosts entries first, so compose service names and `extra_hosts` resolve exactly as they do in the app.
- **The spec goes on stdin**, not argv (process listings would show it, resolv.conf and hosts included).

### Agent

- **Feature `net.tools`.** It gates the whole command: the control plane only queues `net.tool` for agents that list
  it. For any other agent the run is refused with "The Falak agent on this server is too old for network tools;
  update it first."
- **`net.tool` payload** (`contracts/agent-protocol/commands/net.tool.schema.json`):

```json
{
  "tool": "http",
  "target": "example.com",
  "url": "https://example.com/health",
  "port": 443,
  "count": 3,
  "max_hops": 20,
  "record_type": "A",
  "nameserver": "1.1.1.1",
  "method": "GET",
  "connect_to": "203.0.113.10",
  "insecure": false,
  "timeout_s": 30,
  "container": { "name": "falak-db-01…" }
}
```

  `container` is the consoles' selector: exactly one of `name` and `labels`, with the same patterns, and no `user` or
  `workdir`. A tool that exits 0 answers `{exit_code: 0, timed_out: false, namespace: "host" | "container:<name>"}`;
  any other exit fails the command with that exit code (124 timeout, 127 not installed).

- **Validation.** `agent/internal/nettool/spec.go` validates the spec, and the helper validates it again. The rules:
  - A hostname is LDH labels of at most 63 characters (underscores allowed), at most 253 characters in total, with an
    optional trailing dot. It can't start with `-`.
  - IPs are parsed with `netip`.
  - Ports and counts must be in range.
  - The URL must use the `http`/`https` scheme, with no userinfo and no control characters or spaces.
  - Each tool accepts only its own fields: a stray field is refused with a payload error.
  - argv is built from fixed templates and never through a shell.
- **The PTY.** The helper's stdout and stderr are a PTY of 160×50 (its controlling terminal), so the system tools
  line-buffer. `\r\n` becomes `\n`, and the output goes to the command's stdout stream. What servers send (reason
  phrases, header values, certificate names) is printed `%q`-quoted when it holds non-printable characters, and
  `falak net` drops C0/C1 control characters (except newline and tab) before printing.
- **Limits.**
  - The agent runs at most 4 tools at once; a fifth fails with "too many network tools running".
  - The output is capped at 256 KiB: beyond that, the run says `… output truncated` and the tool is stopped; the
    command fails with "output limit reached" (exit 1), not a signal's 137.
  - A cancelled command ends the tool as "cancelled" (exit 130, run status `cancelled`), not as a timeout.

### Control plane (Terminal module)

The runs live in the Terminal module, which already resolves sites, compose services and database containers to a
server and a container selector for consoles (`ConsoleTargets`). Tools reuse that.

- **Permission `network.tools`:** Admin only by default, like `terminal.open` (owners have every permission; a role can be
  granted it), group `network`, described as
  "Run network tools (ping, traceroute, DNS, HTTP, TCP) from servers and containers". Running a tool on a site or
  database also requires `sites.view` / `databases.view`, as consoles do.
- **Table `terminal_net_tool_runs`** (30-day retention; pruned by the existing `terminal:sweep` job). Columns:
  - `id`, `organization_id`, `user_id`, `server_id`, `server_name`;
  - `target_type` (server, site or database), `target_id`, `target_name`, `container` (display);
  - `tool`, `input` (json: the validated arguments), `command_id`;
  - `status` (running, succeeded, failed, timed_out or cancelled), `exit_code`, `error`, `created_at`, `finished_at`.

  The command outcome listeners (`CommandFinished` / `CommandFailed`) settle a run. Output isn't copied: Fleet
  keeps it.
- **Validation:** `NetToolRequest`, the same rules as the agent (`NetToolSpec`, unit tested). The payload contains
  only the fields of the chosen tool.
- **Rate limits:**
  - 10 runs a minute per user (`throttle:net-tools`);
  - at most 2 runs `running` per user, otherwise 429 "Wait for your other network tools to finish." (runs older than
    2 minutes don't count). The count and the insert run under one cache lock per user, so concurrent requests can't
    both pass.
- **Audit:** `network.tool_run` on the run, with `tool`, `target_type`, `target_name`, `server_name` and the
  arguments (a URL's query string included: it's what the user typed, and it's never a credential Falak holds).
- **Routes** (the web routes use `auth, org`; the API routes use `auth:sanctum, org`, with prefix `v1`):

| Route | Answer |
|---|---|
| `POST servers/{server}/net-tools` · `POST api/v1/servers/{server}/net-tools` | 201 `{data: Run}` |
| `POST sites/{site}/net-tools` `{server_id?, service?, …}` · API same | 201 |
| `POST databases/instances/{instance}/net-tools` · API same | 201 |
| `GET net-tools/runs?target_type=&target_id=` | the last 10 runs of the user for that target (the panel's history) |
| `GET net-tools/runs/{run}` · `GET api/v1/net-tools/runs/{run}?after=<seq>` | `{data: Run + lines + last_seq}` (owner, or anyone with `network.tools` in the organization) |

`Run` is `{id, tool, input, target_type, target_name, server_id, server_name, container, command_id, status,
exit_code, error, created_at, finished_at}`.

### UI

- **Server page:** a "Network tools" section (`registerServerSections`, order 700).
- **Sites:** a "Network tools" action in the service panel's `⋯` menu (`registerServiceActions`, kind site; hidden
  for functions). It opens a dialog with:
  - the server picker (sites on several servers) and the service picker (compose);
  - the tool tabs, each with only its fields;
  - Run;
  - the live `CommandLog`;
  - the last runs, which can be opened again.
- **Database containers:** the API and `falak net --db` for now. The canvas database service points at a database,
  not its instance, so a panel entry needs the Databases module's instance page (follow-up).

The panel is hidden without `network.tools`. A 422 from validation appears next to the field.

### CLI

```
falak net ping <host> [--count N]                                 on --server/--site/--db (one is required)
falak net traceroute <host> [--max-hops N]
falak net dns <name> [--type A|AAAA|CNAME|MX|TXT|NS|SRV|PTR] [--nameserver IP]
falak net http <url> [--method GET|HEAD] [--connect-to IP] [--insecure]
falak net tcp <host> <port> [--count N]
  common: --server NAME|ID | --site SITE [--on SERVER] [--service NAME] | --db INSTANCE, --timeout SECONDS
```

It prints the output as it arrives and exits with the tool's exit code (124 on a timeout). With `--json` it prints
the settled run with its output.

## 2. Cloudflare analytics

### Data

All data comes from the Cloudflare GraphQL Analytics API (`POST /client/v4/graphql`), using the token of the
connection that manages the zone (`DnsCredential.api_token`, encrypted). The token is never logged, returned or put
in an error message: Cloudflare's own error messages are shown, and they don't contain it.

**First, ask which datasets the zone can read and how far back.** Cloudflare doesn't publish per-plan limits, so the
settings node is queried first:

```graphql
viewer { zones(filter: {zoneTag: $zone}) { settings {
  httpRequestsAdaptiveGroups { enabled maxDuration notOlderThan }
  firewallEventsAdaptiveGroups { enabled maxDuration notOlderThan }
  httpRequests1dGroups { enabled maxDuration notOlderThan }
} } }
```

An `authz` error here, or a 403 / code 10000 or 9109, means the token lacks **Zone → Analytics → Read**. The panel
then shows: "The Cloudflare token can't read analytics. In Cloudflare → My Profile → API Tokens, edit the token of
the connection *<name>* and add Zone → Analytics → Read for <zone>." The token template link in Settings →
Cloudflare now includes `analytics: read`.

**Then, per dataset, each only when `enabled`.** The window is the last 24 h, shortened to `maxDuration` and
`notOlderThan`.

| Block | Dataset | Content |
|---|---|---|
| Traffic of the site's hosts | `httpRequestsAdaptiveGroups` filtered on `clientRequestHTTPHost_in` = the site's proxied hosts | requests, bytes, visits; split by `cacheStatus`; top 10 countries; status classes; an hourly series |
| Threats | `firewallEventsAdaptiveGroups` on the same hosts | events by `action` (blocked, challenged, managed challenge, …) and the top 5 countries |
| Zone context, 7 days | `httpRequests1dGroups` (zone-wide) | requests, cached requests, bytes, cached bytes, threats per day |

- **Cached vs. origin:** `cacheStatus` hit, stale, updating and revalidated count as served from Cloudflare's cache.
  Everything else (miss, expired, bypass, dynamic, none) counts as origin.
- **Unavailable datasets:** a dataset that isn't enabled, or that answers with an error, is left out with a note,
  such as "Firewall events are not available on this zone's plan". The other blocks still show.

### Caching

The results are cached for 5 minutes (`Cache::remember`), keyed by zone, sorted hosts and window. Refresh bypasses
the cache, at most once a minute per site (`throttle`).

### Where

- **Edge module:**
  - `CloudflareAnalytics` (Application) builds the queries and folds the answers.
  - `CloudflareApi::graphql()` handles transport and errors: `CloudflareError`, plus `CloudflareGraphqlError` with the
    error paths and codes.
- **Route:** `GET sites/{site}/cloudflare/analytics` returns
  `{data: {zones: [{zone, hosts, window, traffic, threats, daily, notes}], error}}` and requires `edge.view`. A site
  with no proxied domain in a managed zone gets `{zones: []}`.
- **UI:** a "Cloudflare traffic" block in the site's Settings → Networking section (`edge.cloudflare-analytics`,
  order 415, under the domains). It renders nothing for sites with no proxied name in a managed zone. It shows:
  - tiles: requests, % served from cache, bandwidth, threats;
  - an hourly bar series;
  - the countries and the status classes;
  - the zone's 7 days as context.

## Tests

- **Go:**
  - spec validation (hostnames, IPs, URLs, ranges, per-tool fields, injection attempts like `-oProxyCommand`,
    `a;b`, spaces, a leading `-`);
  - argv templates;
  - the namespace wrapper and resolver files, with a fake Docker lookup;
  - the HTTP check against `httptest` (HTTP and TLS) for the timings, headers and certificate lines;
  - the TCP test against a local listener and a closed port;
  - DNS with a fake resolver, and hosts-file parsing;
  - link-local refusal;
  - output capping and timeout killing, with a stand-in helper;
  - the CLI against an `httptest` API.
- **PHP:**
  - the permission (Admin and owner allowed; Developer and Viewer 403);
  - validation per tool;
  - the payload sent to Fleet (fake gateway) for a server, a native site, a Docker site, a compose service and a
    database;
  - a refused function;
  - "agent too old";
  - rate and concurrency limits;
  - the audit entry;
  - the outcome listener;
  - the run endpoints (ownership, other organizations get 404);
  - Cloudflare GraphQL with `Http::fake`: success, missing permission (authz, 403 / 10000), a disabled dataset, a
    dataset error, caching, a site without proxied hosts, and the token never in the response.
- **Vitest:** the tool form building only its fields, and folding the analytics series.

## Not in this stream

- Packet capture (phase 5).
- Installing missing tools through provisioning.
- An mtr-like Go traceroute (it needs raw ICMP sockets per TTL).
- Zone-wide analytics on Settings → Cloudflare.
