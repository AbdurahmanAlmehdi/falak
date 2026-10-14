# Phase 4, stream B: HTTP analytics (v0.17.x)

Everything the edge sees about a site's HTTP traffic, from the access logs Caddy already writes: requests, status
classes, bytes, top paths / clients / user agents, slow endpoints (performance Issues without the APM package),
bandwidth per month, countries and networks of clients, scanners and probes (Threats, security Issues), the HTTP
protocol and TLS versions of clients, and request ID linking from an access line to the app's logs and trace.

Index and shared rules: [OBSERVABILITY.md, phase 4](../OBSERVABILITY.md#phase-4-network-v017x).

```
Caddy (falak-edge) ── JSON access log /var/log/falak/access/<slug>.log
      │   (with edge.request_id: X-Request-Id stamped, passed upstream, echoed in the response)
      ▼
agent logs.Tailer ── ParseAccessLine ──► Loki records kind=access (structured metadata, + new fields below)
      │
      └─► httpstats.Aggregator (every record, health checks left out)
             └─► OTLP metrics falak.net.http.* every 60 s, per site ──► VictoriaMetrics (30 d)

control plane (Telemetry module, "Traffic")
  ├─ HTTP tab of a site / edge card of a server: charts from falak_net_http_* (Loki fallback), drill-down tables from LogQL
  ├─ EvaluateHttpTraffic (every 5 min, per organization, LogQL): slow endpoints → performance Issues, probes → security
  │    Issues, 5xx spike / scanner surge / slow endpoint alerts
  ├─ RecordBandwidth (every 10 min): hourly bytes per site → telemetry_http_bandwidth, monthly alert threshold
  └─ DownloadGeoDatabases (daily check, monthly refresh): DB-IP Lite country + ASN mmdb → lookups of top client IPs
```

## What Caddy logs, what the record carries

Caddy's access entry (`http.log.access`) has `request.proto` (`HTTP/1.1`, `HTTP/2.0`, `HTTP/3.0`), `request.tls`
(`version` as the TLS uint16, `cipher_suite` as the uint16 id, `proto` the ALPN, `server_name`), `request.headers`,
`resp_headers`, `bytes_read`, `size`, `duration`, `status`. The record (`logs.ParseAccessLine`) keeps its existing
attributes and gains, all as **structured metadata** (never Loki labels: stream cardinality is unchanged):

| Attribute (OTLP) | Loki metadata | Value |
|---|---|---|
| `network.protocol.version` | `network_protocol_version` | `1.0`, `1.1`, `2`, `3` (from `request.proto`; `network.protocol.name` keeps the raw proto as before) |
| `tls.protocol.version` | `tls_protocol_version` | `1.0` … `1.3`; absent on plain HTTP |
| `tls.cipher` | `tls_cipher` | Go's name of the suite (`TLS_AES_128_GCM_SHA256`) |
| `url.route` | `url_route` | the normalized path (below) |
| `http.request.id` | `http_request_id` | the request id (edge-stamped, see Request IDs) |
| `falak.threat.category` / `falak.threat.rule` | `falak_threat_category` / `falak_threat_rule` | a probe or attack pattern the request matched (below) |
| trace id of the record | `trace_id` | from an incoming W3C `traceparent` header, when the client sent one |

No feature is needed: records are free-form, older control planes ignore the fields, older agents' records just lack
them (every query below tolerates that).

### Route normalization (`logs.NormalizeRoute`)

Cardinality of `url.route` stays bounded per site: per path segment, a value that identifies a record becomes `:id`:
digits (`123`), UUIDs, ULIDs, hex of 16+ characters (hashes), and long mixed tokens (20+ characters with both letters
and digits: API keys, signed URL parts). A hashed asset name keeps its extension (`app-3f2a9c1b.js` → `:asset.js`).
At most 8 segments are kept (`/a/b/…/h/…`), the result at most 200 bytes; the query string is never part of it. The
same function runs in the agent only (the control plane groups by the field).

### Threat rules (`logs.ClassifyThreat`)

Stateless, per record (the agent sees every request; the control plane only aggregates):

| Category | Rule | Matches |
|---|---|---|
| `probe` | `env` | `/.env`, `/.env.*`, `*/.env` |
| `probe` | `git` | `/.git/`, `/.svn/`, `/.hg/` |
| `probe` | `wordpress` | `/wp-admin`, `/wp-login.php`, `/xmlrpc.php`, `/wp-content/`, `/wp-includes/` (on any site; a WordPress site sees its own admin as probes too, documented) |
| `probe` | `phpmyadmin` | `/phpmyadmin`, `/pma`, `/myadmin`, `/adminer` |
| `probe` | `phpunit` | `/vendor/phpunit` |
| `probe` | `cgi` | `/cgi-bin/` |
| `probe` | `config` | `/config.json`, `/.aws/`, `/.ssh/`, `/server-status`, `/actuator`, `/.DS_Store`, `/web.config`, `*.bak`, `*.sql`, `/backup.zip` |
| `traversal` | `traversal` | `../` or its encodings (`%2e%2e`, `..%2f`, `%252e`) in path or query, `/etc/passwd` |
| `injection` | `sqli` | `union select`, `' or '1'='1`, `sleep(`, `benchmark(`, `information_schema`, `--` after a quote, in the decoded query |
| `injection` | `xss` | `<script`, `javascript:`, `onerror=`, `onload=` in the decoded query |
| `method` | `method` | methods other than GET HEAD POST PUT PATCH DELETE OPTIONS (TRACE, CONNECT, PROPFIND, DEBUG, …) |

"Many 404s from one IP" needs state across records and is computed by the control plane (`404_storm`, below).

## Metrics: what is aggregated in the agent

Rule of thumb: **what is charted continuously is aggregated in the agent; what is drilled into is queried from Loki.**
Charts over 7 days from Loki would scan every line of the week on every refresh; a few counters per site do not.
Top-N tables need the raw dimensions (paths, IPs, user agents), which as metric labels would be unbounded (the shared
rule forbids client IPs as labels), so they stay in Loki, queried over the selected range only.

`httpstats.Aggregator` (agent) receives every parsed access record (`logs.AccessObserver`), leaves out the control
plane's health checks (`Falak-HealthCheck/*`), and every 60 s emits one ResourceMetrics per site (`service.name` = the
site slug, enriched by the relay like every site resource; scope `falak-agent/http`, which like the workload scope
gets no deployment / release ids). Cumulative counters from agent start; series of a site that sent nothing for an
hour are dropped.

| Metric (OTLP) | Type | Unit | Attributes | Prometheus name |
|---|---|---|---|---|
| `falak.net.http.requests` | counter | {request} | `http.response.status_class` (`1xx` … `5xx`) | `falak_net_http_requests_total` |
| `falak.net.http.io` | counter | By | `network.io.direction` (`receive` = request bodies, `transmit` = response bodies) | `falak_net_http_io_bytes_total` |
| `falak.net.http.server.duration` | histogram | ms | — (buckets 5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000) | `falak_net_http_server_duration_milliseconds_bucket` / `_sum` / `_count` |
| `falak.net.http.protocol` | counter | {request} | `network.protocol.version` (`1.0`, `1.1`, `2`, `3`, `other`) | `falak_net_http_protocol_requests_total` |
| `falak.net.http.tls` | counter | {request} | `tls.protocol.version` (`1.0` … `1.3`, `none`, `other`) | `falak_net_http_tls_requests_total` |
| `falak.net.http.threats` | counter | {request} | `falak.threat.category` (the four above) | `falak_net_http_threats_requests_total` |

Labels are fixed sets: at most 5 + 2 + 12 + 5 + 6 + 4 series per site. The edge view of a server is the same metrics
summed over the sites it serves (`falak_server_id`). Sites are selected by `falak_org_id` + `service_name` (the slug),
like their access log, so a load balancer's records count too (its agent may know the site only by slug).

Agents without the aggregator (before v0.17) have no `falak_net_http_*` series: the charts fall back to the LogQL of
phase 1 (requests / 5xx / p95) plus `sum_over_time(… | unwrap http_response_body_size)` for bytes; protocol and TLS
charts stay empty for them (the panel says the agent needs an update).

## Drill-downs (LogQL over the selected range)

All on the site's access selector (`LogQueryBuilder::access`, minus health checks), evaluated once at the end of the
range (`query_range` with start = end):

| Table | LogQL |
|---|---|
| Top paths | `topk(20, sum by (url_route) (count_over_time(sel \| url_route!="" [R])))` (older records: by `url_path`) |
| Slowest paths (p95) | `topk(20, quantile_over_time(0.95, sel \| url_route!="" \| unwrap http_server_duration_ms \| __error__="" [R]) by (url_route))`, routes with fewer than 5 requests left out |
| Top client IPs | `topk(20, sum by (client_address) (count_over_time(sel [R])))` + their 4xx / 5xx counts and bytes |
| Top user agents | `topk(20, sum by (user_agent_original) (count_over_time(sel [R])))` |
| Status codes | `sum by (http_response_status_code) (count_over_time(sel [R]))` |
| Protocols / TLS | `sum by (network_protocol_version)` / `sum by (tls_protocol_version)` over the range (from metrics when present) |
| Countries / networks | top 500 client IPs (as above), looked up in the control plane, summed by country and by ASN |
| Threats | `topk(100, sum by (client_address, falak_threat_rule) (count_over_time(sel \| falak_threat_rule!="" [R])))` + 404 storms (`sum by (client_address) (count_over_time(sel \| http_response_status_code="404" [R]))` ≥ 50) |

Ranges: 1h, 6h, 24h, 7d (Loki keeps 15 days). A range query costs one Loki query per table; the panel loads tables on
demand (the tab shown).

## Slow endpoints → performance Issues

`EvaluateHttpTraffic` runs every 5 minutes per organization with Loki configured, over the trailing hour:

```
p95:   quantile_over_time(0.95, {org, kind=access} | url_route!="" | ua!~probe | unwrap http_server_duration_ms | __error__="" [1h]) by (service_name, url_route)
count: sum by (service_name, url_route) (count_over_time({org, kind=access} | url_route!="" | ua!~probe [1h]))
```

A route of a site is **slow** when its count ≥ the site's `slow_min_requests` (default 20) and its p95 ≥ the site's
`slow_ms` (default 1000 ms, `telemetry.http.slow_ms`). It is recorded through the Insights contract
`PerformanceFindings::slowEndpoint()` (kind `performance`, the existing enum value; fingerprint
`sha256("edge|<site id>|<route>")`, title `Slow endpoint: GET /orders/:id` — the method is not part of the route, so
the title has none: `Slow endpoint: /orders/:id`; `meta.source = edge`, `meta.route`, `p95_ms`, `threshold_ms`,
`count`). One occurrence per evaluation that finds it slow (as Insights thresholds do). Routes of the site whose
open edge Issue is no longer slow (p95 under the threshold, or too few requests) are resolved automatically
(`PerformanceFindings::recoverSlowEndpoints()`, `automatic: true`); a resolved one that is slow again regresses.
At most 25 slow routes per site per evaluation (the slowest).

Settings per site (`telemetry_http_settings`, `PUT /telemetry/sites/{id}/http/settings`, `sites.manage`):
`slow_ms` (100 – 60,000), `slow_min_requests` (1 – 10,000), `bandwidth_alert_bytes` (null = off).

## Bandwidth per site per month

`RecordBandwidth` (every 10 minutes) recomputes the last two complete hours per organization and upserts
`telemetry_http_bandwidth` (site × hour: bytes out, bytes in, requests):

- `sum by (service_name) (increase(falak_net_http_io_bytes_total{falak_org_id="…",network_io_direction="transmit"}[1h]))`
  at the hour's end (and `receive`, and requests); sites without the series in that hour (older agents) get
  `sum by (service_name) (sum_over_time({org, kind=access} | unwrap http_response_body_size | __error__="" [1h]))`.
- The month is the sum of its hours (UTC), kept 400 days (`telemetry.http.bandwidth_retention_days`) so last year's
  month can be compared. Response bodies only: headers and TLS overhead are not counted (documented in the UI).
- A site with `bandwidth_alert_bytes` gets one `http.bandwidth` alert per month once the month's bytes out reach it
  (dedup key `http.bandwidth:<site>:<Ym>`, no recovery: the month ends).

## Countries and networks (DB-IP Lite)

The control plane downloads **DB-IP IP to Country Lite** and **IP to ASN Lite** (mmdb, CC BY 4.0, free, no account)
once a month: `DownloadGeoDatabases` runs daily and fetches when a file is missing or older than 32 days, from
`telemetry.geoip.country_url` / `asn_url` (env `FALAK_GEOIP_COUNTRY_URL`, `FALAK_GEOIP_ASN_URL`; defaults
`https://download.db-ip.com/free/dbip-country-lite-{Y}-{m}.mmdb.gz` and `…/dbip-asn-lite-{Y}-{m}.mmdb.gz`;
`{Y}` / `{m}` are the current month, the previous month is tried when the current one is not published yet).
The file is gunzipped to `storage/app/geoip/<name>.mmdb.tmp`, opened once to check it (metadata, a lookup) and
renamed over the old one; a failed download keeps the old file. `FALAK_GEOIP_ENABLED=false` turns it off (air-gapped
installs).

Lookups happen in the control plane (`GeoLookup`, a small pure-PHP MaxMind DB reader: no extension, no package) on
already aggregated top-N client IPs, never per record:

- the agent would need the database on every server (a 30 MB download per server per month) for a breakdown that is
  only ever shown on demand;
- top 500 IPs of a range cover almost all requests of a small site; the panel says how much the breakdown covers
  ("countries of 94% of requests").

Without the database (not downloaded yet, disabled, corrupt) tables show IPs without countries and the Countries card
says why; nothing fails. Attribution: "IP geolocation by DB-IP" (link to db-ip.com) under every table that shows a
country or network, and in the docs.

## Bots and scanners → Threats and security Issues

- **Threats view** (site HTTP tab): top offending IPs with their country / network, what they tried (rules, sample
  paths), counts, first / last seen in the range, and 404 storms. Each IP row has the actions registered in the UI slot
  **`network.ip-actions`** (`registerIpActions()` in `resources/js/lib/registry.ts`, props `{ ip, siteId?, serverId?,
  source }`); stream C (protection) registers "Block IP" there. This stream only renders the slot.
- **Security Issues** (Insights kind `security`, added to `IssueKind` additively): `EvaluateHttpTraffic` counts, per
  site and rule, the threat hits of the last complete 5-minute window
  (`sum by (service_name, falak_threat_rule) (count_over_time({org, kind=access} | falak_threat_rule!="" [5m]))`), and
  404s per client IP (`404_storm`: ≥ `telemetry.http.storm_404` (100) 404s from one IP in the window). A site and rule
  with at least `telemetry.http.security_min_hits` (20) hits in the window is recorded through the Insights contract
  `SecurityFindings::scanner()` (fingerprint `sha256("http|<site id>|<rule>")`, title `Scanner probing /.env files`,
  `meta.source = http`, `meta.rule`, sample: top IPs and paths). Background noise (a few probes an hour, which every
  public site gets) opens nothing; Issues are not resolved automatically (scanning comes and goes; the user resolves, a
  new wave regresses it). Stream A records its own security findings through its own contract methods on the same kind.

## Request IDs (agent feature `edge.request_id`)

`edge.caddy.apply` gains a top-level `request_id: true` (always sent by the control plane, stripped by
`PayloadCompatibility` for agents without `edge.request_id`). With it, every site's subroute starts with:

```json
{"match": [{"not": [{"header_regexp": {"X-Request-Id": {"pattern": "^[A-Za-z0-9._:-]{8,128}$"}}}]}],
 "handle": [{"handler": "headers",
             "request":  {"set": {"X-Request-Id": ["{http.request.uuid}"]}},
             "response": {"set": {"X-Request-Id": ["{http.request.uuid}"]}, "deferred": true}}]},
{"match": [{"header_regexp": {"X-Request-Id": {"pattern": "^[A-Za-z0-9._:-]{8,128}$"}}}],
 "handle": [{"handler": "headers",
             "response": {"set": {"X-Request-Id": ["{http.request.header.X-Request-Id}"]}, "deferred": true}}]}
```

A well-formed incoming id is kept (a proxy in front, a client that correlates); anything else is replaced by a UUID.
The header reaches the app (reverse proxy, FastCGI `HTTP_X_REQUEST_ID`, FrankenPHP) and the response. Caddy logs the
request as received, so the record takes the id from `resp_headers` first, then the request header. Trusting incoming
ids only from trusted proxies was considered and left out: an id is a correlation handle, not an authorization; a
client that forges one only mislabels its own requests.

**Rendering stays byte-identical without the flag**: `Render` adds the routes only when `request_id` is true, so a
server whose agent lacks the feature, and every config rendered before the control plane sends the flag, is unchanged
(`edge_test.go` compares a full render with and without). Upgrading the agent re-applies the edge once (the existing
`ReapplyAfterAgentUpgrade`), which now carries the flag: Caddy loads the new config through its admin API (`/load`),
a graceful switch (listeners kept, in-flight requests finish on the old handlers). Nothing restarts.

Linking (`GET /telemetry/sites/{id}/requests/{requestId}`):

- the access line(s): `sel | http_request_id="<id>"`;
- the app's records that contain the id: `{org, site, kind!=access} |= "<id>"` over ±15 minutes of the request. Apps
  log it when they put it in their log context — Laravel, one middleware:

  ```php
  // app/Http/Middleware/RequestId.php (register it first in the web and api groups)
  public function handle(Request $request, Closure $next)
  {
      if ($id = $request->header('X-Request-Id')) {
          \Illuminate\Support\Facades\Log::shareContext(['request_id' => $id]);
      }
      return $next($request);
  }
  ```

- the trace: the access record's `trace_id` when the client sent `traceparent`; otherwise the app's records found above
  carry the trace id the APM package put on them (`trace_id` metadata), and the panel links to that trace.

The access table of the HTTP tab (and its request detail) shows "App logs for this request" for records with an id.

## Alerts (Alerting, `http.*`, default rule pack area "HTTP")

| Type | Condition | Severity |
|---|---|---|
| `http.errors_spike` | in the last 5-minute window, a site's 5xx ≥ `telemetry.http.errors_min` (20) and ≥ `errors_ratio` (10%) of its requests; held two windows (`AlertConditions`, key `http.errors:<site>`) | Warning |
| `http.slow_endpoint` | a route found slow (above), key `http.slow:<site>:<route hash>`, cleared with the Issue | Warning |
| `http.bandwidth` | the month's bytes out ≥ the site's threshold, once a month | Warning |
| `http.scanner_surge` | ≥ `telemetry.http.scanner_surge` (300) threat hits on a site in a 5-minute window, key `http.scanner:<site>` | Warning |

## UI

- **Site service panel → HTTP tab** (`telemetry.view`; settings need `sites.manage`): range and server pickers; charts
  requests / s by status class, bytes in / out per second, p95 latency, protocols and TLS versions (stacked shares);
  a bandwidth card (this month, last month, threshold); tables Paths / Slowest / Clients / User agents / Countries /
  Threats, each loaded when shown; a request search by request id.
- **Server page → Edge traffic card**: the same charts summed over the server's sites, and requests per site.
- Countries table and client tables show the DB-IP attribution.

Endpoints (JSON, Telemetry module):

| Route | |
|---|---|
| `GET /telemetry/sites/{id}/http/data?range=&server=` | charts (`source`: `agent` / `edge-logs`) |
| `GET /telemetry/sites/{id}/http/top?range=&server=&table=paths\|slow\|clients\|agents\|status\|countries\|threats` | one table |
| `GET /telemetry/sites/{id}/http/bandwidth` | months (12), current month's threshold |
| `GET\|PUT /telemetry/sites/{id}/http/settings` | per-site thresholds |
| `GET /telemetry/sites/{id}/requests/{requestId}` | access lines, app records, trace id |
| `GET /telemetry/servers/{id}/http/data?range=` | the edge card |

## Compatibility

| Piece | Older agent | Older control plane |
|---|---|---|
| New record attributes | absent: tables fall back (`url_path`), protocol / TLS / threats empty | ignored (metadata) |
| `falak.net.http.*` | absent: charts from LogQL | unused series |
| `edge.request_id` | field stripped, config unchanged | never sent |

## Configuration and names

| | |
|---|---|
| Agent | `logs.NormalizeRoute`, `logs.ClassifyThreat`, `logs.AccessObserver` (tailer hook), package `httpstats` (`Aggregator`, scope `falak-agent/http`), `edge.Payload.RequestID`, feature `edge.request_id` |
| Control plane | `Telemetry\Application\Queries\HttpQueries`, `Application\Http\EvaluateHttpTraffic` / `RecordBandwidth`, job `AnalyzeHttpTraffic` (`evaluate` every 5 min, `bandwidth` every 10 min), `RefreshGeoDatabases` (daily 04:17), `Infrastructure\Geo\MaxMindReader` / `GeoDatabases`, `HttpTrafficController`, tables `telemetry_http_settings`, `telemetry_http_bandwidth` |
| Insights | `PerformanceFindings` (`slowEndpoint`, `recoverSlowEndpoints`, `openSlowEndpoints`), `SecurityFindings` (`scanner`, `openScanners`), `IssueKind::Security` |
| UI | Site tab `http` (order 350), server section `telemetry.edge-traffic`, slot `registerIpActions()` / `ipActionsFor()` (`network.ip-actions`) |
| Env | `FALAK_EDGE_REQUEST_ID` (default true), `FALAK_HTTP_SLOW_MS` (1000), `FALAK_GEOIP_ENABLED`, `FALAK_GEOIP_PATH` (`storage/app/geoip`), `FALAK_GEOIP_COUNTRY_URL`, `FALAK_GEOIP_ASN_URL`; the rest under `telemetry.http` |

## Not in this stream

- Blocking / rate-limiting (stream C fills `network.ip-actions`); Cloudflare analytics (stream E).
- Per-route metrics in VictoriaMetrics (top routes come from Loki; Issues are decided on Loki's exact p95).
- Countries on a map (a table first); GeoIP in the agent.
- Request bodies, cookies, any header other than the user agent and the request id (never logged).

## Tests

- Go: access record parsing (proto, TLS version and cipher, request id from response then request header, invalid
  ids dropped, traceparent, threat attributes); route normalization corpus; threat rules corpus; aggregator (classes,
  bytes, histogram, protocols, TLS, threats, health checks left out, idle sites pruned); edge render with and without
  `request_id` (without: byte-identical to the previous render).
- PHP: LogQL / PromQL builders; HTTP data with metrics and with the Loki fallback; tables; authorization (view vs
  manage, other organizations' sites); settings validation; slow endpoint Issues opened, counted, resolved and
  regressed; security Issues with the minimum; alerts (errors spike held, scanner surge, bandwidth once a month);
  bandwidth recording (metrics and Loki fallback, idempotent); MaxMind DB reader against a fixture written by a test
  writer; download (gzip, month fallback, keeps the old file on failure); `PayloadCompatibility` strips `request_id`.
- UI (vitest): chart shaping (status classes, shares), table formatting, the IP actions slot.
