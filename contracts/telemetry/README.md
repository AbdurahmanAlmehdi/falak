# Telemetry contract v1

Shared by `falak-agent` (receiver/relay), `packages/apm-laravel`, `packages/apm-node`, and the control-plane
Telemetry + Insights modules.

## Transport (app → agent)
The agent exposes a standard **OTLP/HTTP** receiver (both `application/x-protobuf` and `application/json`) on:
- `unix:/run/falak/otlp.sock` (preferred for PHP; `POST /v1/traces`, `/v1/logs`, `/v1/metrics`)
- `http://127.0.0.1:4318` (standard OTLP port; used by Node/Bun/Deno exporters)

Apps must **never block a request** on telemetry: the Laravel package flushes after the response is sent
(`terminating` callbacks), with a 250 ms socket timeout and silent drop on failure.

The agent batches, disk-buffers (bounded, default 64 MB) and exports to the observability endpoint from enrollment.

## Resource attributes (set by the agent on every signal; apps may omit)
| Attribute | Example |
|---|---|
| `service.name` | site slug, e.g. `shop-example-com` |
| `deployment.environment.name` | `production` |
| `falak.org.id`, `falak.server.id`, `falak.site.id` | ULIDs |
| `falak.deployment.id`, `falak.release.id` | ULIDs of the active release |
| `host.name` | server hostname |

Apps learn their ids from env vars injected by Deployments: `FALAK_SITE_ID`, `FALAK_SERVER_ID`, `FALAK_DEPLOYMENT_ID`, `FALAK_RELEASE_ID`.

## Event taxonomy (Nightwatch parity)
Every APM span carries `falak.event.type`:

| `falak.event.type` | Span kind | Required attributes |
|---|---|---|
| `request` | SERVER | `http.request.method`, `http.route`, `http.response.status_code`, `url.path` |
| `query` | CLIENT | `db.system.name`, `db.query.text` (redacted bindings), `db.namespace`, `falak.query.connection` |
| `job` | CONSUMER | `messaging.destination.name` (queue), `falak.job.class`, `falak.job.attempt`, `falak.job.status` (`processed`\|`released`\|`failed`) |
| `outgoing_request` | CLIENT | `http.request.method`, `url.full` (query redacted; functions: no query or userinfo, secret path segments → `{redacted}`), `http.response.status_code` |
| `mail` | INTERNAL | `falak.mail.class`, `falak.mail.recipients_count`, `falak.mail.mailer` |
| `notification` | INTERNAL | `falak.notification.class`, `falak.notification.channel`, `falak.notification.status` |
| `cache` | INTERNAL | `falak.cache.op` (`hit`\|`miss`\|`write`\|`forget`), `falak.cache.key`, `falak.cache.store` |
| `command` | INTERNAL | `falak.command.name`, `process.exit.code` |
| `scheduled_task` | INTERNAL | `falak.schedule.name`, `falak.schedule.expression`, `falak.schedule.status` (`finished`\|`failed`\|`skipped`) |

- **Timeline spans:** child spans that only mark request phases carry `falak.timeline.phase`
  (`bootstrap`\|`middleware`\|`controller`\|`response`) and **no** `falak.event.type`, so they never skew per-type aggregates.
- **User context:** `enduser.id` on the root span when authenticated.
- **Exceptions:** standard OTel span event `exception` (`exception.type`, `exception.message`, `exception.stacktrace`)
  plus `falak.exception.handled` (bool). The span status is `ERROR` for unhandled exceptions.
- **Logs:** OTLP logs, correlated with `trace_id`/`span_id`.
- **Redaction:** apps redact before sending (headers, query bindings, cache keys by pattern). Default denylist:
  `password`, `token`, `secret`, `authorization`, `cookie`, `api_key`.

## Insights tee (agent → control plane)
The agent inspects spans it relays and forwards compact summaries to `POST /agent/v1/insights` (NDJSON):
- every span with an `exception` event → `{ "kind": "exception", "trace_id", "span_id", "site_id", "type", "message", "stacktrace", "handled", "user_id", "event_type", "route_or_name", "at" }`
- every Laravel log entry at `ERROR` or above with an exception and its `[stacktrace]` (sites without the APM
  package) → the same `exception` line with `event_type: "log"`, `route_or_name` = the log file's name, empty
  `trace_id` / `span_id` / `user_id`, `handled: false`; held 15 s and dropped when the site's APM sent spans within
  15 minutes or an exception with the same class + first in-app frame within ±60 s (docs/plans/OBSERVABILITY.md phase 2)
- per-minute per-route/job/query-shape aggregates → `{ "kind": "aggregate", "site_id", "event_type", "name", "count", "p50_ms", "p95_ms", "max_ms", "errors", "minute" }`

- every cron run → `{ "kind": "cron_heartbeat", ... }` (shape: `contracts/agent-protocol/commands/cron.apply.schema.json` `$defs.heartbeat`); drives missed-run detection

Thresholds, grouping into issues, and alerts live in the control plane (Insights module).
