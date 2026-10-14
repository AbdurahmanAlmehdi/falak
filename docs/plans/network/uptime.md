# Uptime (phase 4, stream D)

Checks from the control plane that every site answers, and user-defined checks of anything public: uptime %,
response times, incidents, alerts and an optional public status page per project. Control plane only: no agent
changes, no agent feature, no new protocol request. Index and shared rules: `docs/plans/OBSERVABILITY.md`, phase 4.

## Module

A new module `Uptime` (`control-plane/modules/Uptime`, namespace `Falak\Uptime`), registered after `Alerting` in
`Kernel\Modules::ALL` (it reads Sites, Edge, Projects through their contracts and writes Insights issues and alerts).
It owns its tables (`uptime_*`), its routes, the Uptime tab of site panels, the Uptime tab of `/observability` and the
public status pages. It talks to other modules only through their `Contracts` and `Events` (architecture test).

## Monitors

| Type | Target | Succeeds when |
|---|---|---|
| `http` | `http(s)://host[:port]/path` (GET) | the final response's status matches `expected_status` (default `2xx,3xx`; a list of codes or classes, e.g. `200,301,4xx`) and, when set, the body contains `keyword` (or does not, `keyword_absent`) within the first `body_bytes` (64 KiB) |
| `tcp` | `host:port` | the TCP connection is accepted |
| `tls` | `host:port` (default 443) | the TLS handshake succeeds (the chain is inspected, see TLS) |

- **Automatic monitors** (`source = auto`): one `http` monitor `https://<domain>/` per non-wildcard domain of every
  site (`Edge\Contracts\DomainRecords`) plus the site's test domain. Sites of **preview environments** are skipped:
  short-lived, often behind basic auth (401). Synced by `SyncSiteMonitors` on `DomainAdded`,
  `DomainRemoved`, `SiteDeleted` and every 10 minutes, one job per organization on the `uptime` queue (domains added
  before the module existed, missed events). A
  removed domain removes its monitor (history included). The user may pause them, change the interval, timeout,
  expected status, keyword and slow threshold, but not the URL; they never count against the limit.
  An automatic monitor is **armed** once it has seen one success: until then (a site that never deployed, DNS not yet
  pointed) it shows "Waiting for first success" and opens no incident.
- **User monitors** (`source = user`): any of the three types, optionally attached to a site (then shown on its
  Uptime tab). Limit `uptime.max_monitors` (50) per organization.
- Interval `interval_seconds`: 60 by default, 30–600 (within the organization's load budget, see Runner); timeout 1–20 s (10) for the whole attempt, redirect hops included; redirects followed up to `max_redirects`
  (5; 0: a redirect is the final answer); slow threshold `slow_ms` (null: `uptime.slow_ms`, 3,000 ms).
- Paused monitors are not checked and do not count in uptime % (no rollup rows while paused).

## Runner

- `DispatchChecks` (scheduler, every 10 s, `withoutOverlapping`, itself on the `uptime` queue): claims due monitors
  (`next_check_at <= now`, not paused) with a conditional update (two schedulers never claim one twice) and queues one
  `RunCheck` per monitor on the queue `uptime.queue` (`uptime`). A claim is a **lease**: `next_check_at` moves past
  the job timeout and `claimed_at` is set; `RecordCheck` sets the real next time and clears the claim, a lost job
  makes the monitor due again when the lease runs out. `RunCheck` is unique per monitor (`ShouldBeUnique`).
- **Fairness between organizations:** each organization has at most `uptime.max_in_flight_per_org` (2) checks queued
  or running; every run serves every organization (oldest due first) up to its free slots, at most
  `uptime.dispatch_batch` (500) in total. A dedicated Horizon supervisor (`FALAK_UPTIME_WORKERS`, 4 processes, at
  least twice the per-organization cap) runs the checks so they never delay deploys on `default`: an organization
  whose targets all time out holds two workers and only delays its own checks.
- **Load budget:** a monitor's worst-case cost is `2 × timeout / interval` workers (two attempts per interval, every
  target timing out). Creating, changing or resuming a monitor that would take the organization's active monitors past
  `uptime.org_budget` (20) is refused with the numbers ("would keep 24.0 workers busy (limit 20)"); site domain
  monitors count but are never refused.
- **Check delay:** every run measures, per organization, how long its oldest due check has waited (kept for the UI:
  "checks N s late", and the Observability → Uptime summary); `uptime.checks_delayed` (Warning) is raised when it stays
  at or above `uptime.lag_alert_seconds` (120) for 5 minutes and clears below half. Operators also see the `uptime`
  queue's wait in Horizon.
- `RunCheck`: one attempt; on failure a second attempt after `uptime.retry_delay_ms` (1 s) confirms it (a check fails
  only when both fail). The next check: the interval after a success; after a failure `min(interval, 30 s)` so an
  outage is confirmed quickly — except for a site domain monitor that has never been up (pending, not armed), which
  backs off: `interval × 2^(failures − 1)`, at most `uptime.pending_backoff_max` (1 h). Job timeout:
  `2 × max_timeout + 15` s (two attempts, each with its TLS inspection inside the attempt's deadline), below the
  queue's `retry_after` (90 s).
- **Control plane offline:** when a check fails, the control plane's own connectivity is checked (`uptime.canaries`,
  default `1.1.1.1:443,8.8.8.8:443`, TCP, cached 30 s). If no canary answers either, the check is stored as `offline`
  (unknown) and changes nothing: no state, no rollup, no incident, no alert; the UI says "Monitoring paused: the
  control plane is offline".
- **Prober** (`Domain\Probing\Prober`, faked in tests) per type:
  - `http`: the host must be a plain name (`[a-z0-9.-]`) or an IP literal — no percent-encoding (parse_url keeps
    `%2d`, curl decodes it, so an encoded host would be looked up by curl itself), at creation and on every redirect
    hop. Resolve it once (A + AAAA), check every address against the SSRF guard, pin the first allowed one with
    `CURLOPT_CONNECT_TO` (`::<ip>:<port>`: whatever host curl derives from the URL, it connects to the checked
    address; a DNS answer that changes in between — rebinding — cannot reach another address), and treat a
    connection to any other address (`CURLINFO_PRIMARY_IP`) as blocked. Send GET with redirects off, read at most `body_bytes` (the write callback stops the
    transfer), and follow `Location` manually: every hop is resolved, checked and pinned again. Timings from
    `curl_getinfo`: DNS (our own lookup), connect, TLS (appconnect − connect), TTFB (starttransfer), total.
  - `tcp`: resolve, guard, `stream_socket_client` to the pinned address.
  - `tls`: resolve, guard, TLS handshake with SNI and `capture_peer_cert_chain` (no verification during the handshake:
    the chain is evaluated afterwards, so a broken chain is reported precisely instead of as a failed handshake). The
    handshake gets what is left of the timeout after connecting; running out is a `timeout`. The hourly inspection of
    an `https` monitor shares its attempt's deadline.
- **TLS inspection** (`CertificateInspector`, pure): issuer, subject, expiry, hostname match (SAN DNS names with one
  wildcard label, CN only without SANs), chain trust against the system CA bundle (`uptime.ca_file`, default the
  OpenSSL default file) using the presented intermediates as untrusted certificates; a chain that does not verify
  while the presented chain has only the leaf (issuer ≠ subject) is `intermediate_missing`; `expired`,
  `hostname_mismatch`, `untrusted`, `self_signed`. Run on every `tls` check and, for `https` monitors, once an hour
  (`uptime.tls_check_minutes`) through the same prober. Stored on the monitor (`tls` JSON) and in the check row.
  An `http` check over a broken chain fails (curl verifies), with error kind `tls`.
- **Per check** (`uptime_checks`): `ok`, `status_code`, `error_kind` (`dns`, `blocked`, `connect`, `timeout`, `tls`,
  `status`, `keyword`, `redirects`, `http`), `error` (≤ 300 chars), `ip`, `dns_ms`, `connect_ms`, `tls_ms`,
  `ttfb_ms`, `total_ms`, `attempts`. Kept `uptime.retention.checks_days` (7).
- **Rollups** (`uptime_rollups`: monitor × `hour`|`day` bucket, UTC): checks, down checks (taken while the monitor
  was down), **seconds and down seconds**, latency sum / count / max of successful checks. Incremented by every check
  (insert-or-ignore, then one increment), so uptime % and charts are always current. Hourly rows kept 30 days, daily
  90 (`uptime.retention.hourly_days`, `daily_days`). Uptime is **time-weighted**: each check stands for the time since
  the previous check, at most the interval, so a failing monitor checked every 30 s weighs no more than a healthy one
  checked every 60 s (one hour up then one hour down is 50%, not the 34% check counts would give). Uptime % =
  1 − down seconds / seconds (rows without seconds fall back to counts); down minutes per day = down seconds / 60. A
  first failure that recovers on the next check does not count as down.
- `PruneUptime` (daily) deletes old checks, rollups and incidents (`incidents_days`, 90).

## Incidents and the state machine

`Domain\MonitorState` (pure, unit-tested) decides from the monitor and a check result:

| State | Check fails | Check succeeds |
|---|---|---|
| `pending` (never up) | stays pending (automatic: not armed) / user monitor: counts failures | → `up` (armed) |
| `up` | failures + 1; at `confirm_after` (2) consecutive failed checks → `down`, incident opens (started at the first failed check) | failures = 0 |
| `down` | incident's last error updated | successes + 1; at `recover_after` (2) → `up`, incident closes with its duration |

- **Flapping guard:** a monitor whose incident closes as the `uptime.flapping.incidents`-th (3) within
  `uptime.flapping.window_minutes` (60) is flapping for `uptime.flapping.hold_minutes` (15) after that recovery: the
  incidents are still recorded, but the down alert stays raised through further flaps (no new down / recovered
  notifications) and the recovery is announced once, after the monitor stayed up for the hold.
- An incident starts at the first failed check after the last success, the previous incident's end and the
  monitor's last resume (`resumed_at`): a failure from before a pause never stretches a new outage back.
- Incident (`uptime_incidents`): monitor, started / confirmed / resolved at, duration, cause (the first failing check's
  error kind + message + status code), last error, the Insights issue id.

## Issues (Insights, kind `uptime`)

Additive contract `Insights\Contracts\UptimeIssues` (implemented by `TrackedUptimeIssues`, as `DatabaseIssues`):
`record()` and `resolve()`; fingerprint `sha256("<monitor id>|<problem>")`, `meta.monitor_id`, `site_id` the monitor's
site. Problems: `down` (opened with the incident, resolved automatically on recovery; the next outage regresses it, so
one issue per monitor counts its outages) and `tls` (chain / hostname / expiry problem, resolved when it clears). The
Insights UI gets the `uptime` kind (label, icon, filter). Alerting does not turn uptime issues into
`insights.issue_*` alerts: the `uptime.*` alerts below announce them (no double notification).

## Alerts (Alerting, group "Uptime", in the default rule pack)

| Type | Severity | Raised | Clears |
|---|---|---|---|
| `uptime.monitor_down` | Critical | the incident opens (`AlertConditions`, key `uptime.down:<monitor>`) | recovery `uptime.monitor_recovered` (Info) when it closes (after the flapping hold) |
| `uptime.tls_problem` | Warning | chain incomplete / untrusted, hostname mismatch, expired, or (user monitors) expiry within `uptime.tls_expiry_days` (14). Automatic monitors leave "expires soon" to Edge's `edge.certificate_expiring`; an expired certificate is reported | the problem clears |
| `uptime.slow` | Warning | median total time of the last 5 successful checks ≥ the slow threshold, held 5 minutes (hysteresis: clears below 80%) | back under |
| `uptime.checks_delayed` | Warning | the organization's oldest due check waited ≥ `uptime.lag_alert_seconds` (120 s), held 5 minutes | below half of it |

## Permissions

`uptime.view` (Admin, Developer, Viewer): the tabs and pages. `uptime.manage` (Admin, Developer): create, edit,
pause, delete monitors. `uptime.publish` (Admin): status pages (they make names public). Audited:
`uptime.monitor.created|updated|deleted|paused|resumed`, `uptime.status_page.saved|deleted`.

## UI

- **Site panel → Uptime tab** (`kinds: ['site']`, order 520, `uptime.view`): the site's monitors with status, uptime %
  24 h / 7 d / 30 d / 90 d, last response time and TLS state; the selected monitor's response time chart (DNS,
  connect, TLS, TTFB stacked from hourly rollups; 24 h from raw checks) with the range selector, 90-day bars, and its
  incidents, recent failed checks. Add / edit / pause / delete (`uptime.manage`; site domain monitors can be paused
  and tuned, not deleted or retargeted). JSON: `GET /uptime/sites/{site}`,
  `GET /uptime/monitors/{monitor}/data?range=24h|7d|30d|90d`; writes `POST|PUT|DELETE /uptime/monitors…`.
- **`/observability/uptime`** (tab of the Observability page): every monitor of the organization (filter by status,
  site), summary stats (up / down / paused, open incidents), recent incidents; create user monitors;
  `?monitor=<id>` selects one (alert links of monitors without a site). Status pages:
  `/observability/uptime/status-pages`.

## Public status page

- Opt-in per project (`uptime_status_pages`: project, slug, title, description, enabled; `uptime_status_page_monitors`:
  monitor, display name, position). Only monitors of the organization; the display name starts empty (nothing internal is
  published by accident) and the page shows **only** what the user typed (no URL, host, IP, error, status code, latency source).
- `GET /status/{slug}`: server-rendered Blade (no session, no cookies, no app JS), overall status, each monitor's
  current state and 90 daily bars (uptime % per day, "no data" before the monitor existed), incidents of the last
  14 days (display name, start, duration, resolved or not — no cause). `Cache-Control: public, max-age=60`, the
  rendered data cached 60 s, `throttle:uptime-status` (60 / minute per IP), `X-Robots-Tag` configurable. A disabled
  or unknown slug is a 404. The footer says how often the shown monitors are checked (their shortest interval).
- Addresses: reserved words (falak, admin, api, status, login, www, support, help, billing, …) and brand names a page
  could impersonate (stripe, google, github, paypal, apple, microsoft, amazon, cloudflare, …, also as `<word>-…` /
  `…-<word>`) are refused. A reserved, taken or concurrently taken address gets the same "This address is not
  available." (whether another organization uses it is not revealed).
- **Custom domains are out of scope**: the page lives on the control plane's host. Later: a status domain routed by
  Edge to the control plane, with its certificate, mapping Host → slug.

## Security

- **SSRF guard** (`Domain\Probing\AddressGuard`): every address a target resolves to must be public; refused:
  loopback, RFC 1918, CGNAT (100.64/10), link-local (169.254/16 incl. the 169.254.169.254 metadata service, fe80::/10),
  ULA fc00::/7, `0.0.0.0/8`, multicast, reserved / benchmarking / documentation ranges, IPv4-mapped and NAT64 forms of
  those, and `uptime.blocked_cidrs` (e.g. the control plane's WireGuard range). A host resolving to any refused address
  is refused (`blocked`), not just that address. Re-checked on every redirect hop; the connection is pinned to the
  checked address. Always refused, even with private targets allowed: link-local and the metadata services
  (169.254.169.254, `fd00:ec2::254`, Alibaba's 100.100.100.200, Oracle's 192.0.0.192), SIIT `::ffff:0:0:0/96` and
  local-use NAT64 `64:ff9b:1::/48`. The control plane's own public addresses (what `APP_URL`'s host resolves to, plus
  `FALAK_UPTIME_SELF_IPS`) are refused for user monitors; site domain monitors may reach them (on a single-host
  install the sites run next to the control plane). Only `http`/`https` schemes; credentials in URLs are refused. Hosts in `.internal`, `.local`,
  `localhost` are refused before resolution.
- Private targets can only be enabled by whoever runs the control plane (`FALAK_UPTIME_ALLOW_PRIVATE_TARGETS`), not by
  an organization: on a shared install an organization admin must not be able to probe the control plane's network.
- Response bodies are read up to `body_bytes`; headers capped by curl; redirects capped.
- Status pages expose only user-chosen names and computed percentages.

## Limitations and later

- **Single vantage point:** every check runs from the control plane's location. A network problem between the control
  plane and a site looks like an outage, and regional problems elsewhere are invisible. Later: agents as probes —
  servers of the organization in other regions run its monitors through a new agent feature (`net.probe`: the
  control plane sends the targets with `telemetry.configure`, the agent reports results in an `uptime.probed`
  request, with the same SSRF guard in the agent), checks get a `location`, and an incident opens only when a quorum
  of locations agrees (e.g. 2 of 3); the control plane stays one of the locations.
- No multi-step / browser checks, no ICMP, no DNS record checks, no POST bodies or custom headers.
- No custom status page domains (above); no subscriptions to status pages.

## Config (`uptime.*`)

`queue` (uptime), `dispatch_batch` 500, `retry_delay_ms` 1000, `confirm_after` 2, `recover_after` 2,
`default_interval` 60, `min_interval` 30, `max_interval` 600, `default_timeout` 10, `max_timeout` 20, `max_redirects` 5, `body_bytes`
65536, `max_monitors` 50, `slow_ms` 3000, `tls_expiry_days` 14, `tls_check_minutes` 60, `allow_private_targets` false,
`blocked_cidrs` [], `self_ips` [] (+ `APP_URL`'s addresses), `ca_file` null, `max_in_flight_per_org` 2, `org_budget` 20,
`lag_alert_seconds` 120, `retry_interval` 30, `pending_backoff_max` 3600, `canaries` [1.1.1.1:443, 8.8.8.8:443], `flapping` {incidents 3, window_minutes 60, hold_minutes 15},
`retention` {checks_days 7, hourly_days 30, daily_days 90, incidents_days 90}, `status_page` {cache_seconds 60,
rate_per_minute 60, incident_days 14, noindex true}. Environment: `FALAK_UPTIME_WORKERS` (4),
`FALAK_UPTIME_MAX_IN_FLIGHT_PER_ORG`, `FALAK_UPTIME_ORG_BUDGET`, `FALAK_UPTIME_CANARIES`, `FALAK_UPTIME_SELF_IPS`,
`FALAK_UPTIME_ALLOW_PRIVATE_TARGETS`, `FALAK_UPTIME_BLOCKED_CIDRS`.

## Tests

PHP: SSRF guard (ranges, mapped forms, a host with one private address, rebinding: the transport receives the pinned
address only, redirects re-checked), HTTP prober with a fake transport (status / keyword / redirects / body limit /
timings), certificate inspector with generated certificates (valid, hostname mismatch, expired, missing intermediate,
self-signed), state machine (confirm, recover, pending automatic monitors, flapping), runner (incident open / close,
rollups, Issues, alerts down / recovered / TLS / slow through `AlertConditions`), monitor sync from site domains,
limits and validation, authorization (viewer read-only, other organizations 404), status page output (no internal
data, cache headers, rate limit, disabled 404), pruning. Vitest: uptime %, bar tones, expected-status parsing helpers.
