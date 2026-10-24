# Falak observability stack

Grafana + Loki + Tempo + a pluggable metrics backend (VictoriaMetrics **or** Mimir) behind one
OTLP/HTTP ingress. It runs on one 2–4 GB box. `falak-agent` sends every signal here; apps never
talk to it directly (see `contracts/telemetry/README.md`).

```
falak-agent ──OTLP/HTTP──► gateway :4318 (Caddy)
                            ├─ /v1/traces  ─► Tempo :4318 ── metrics-generator (span metrics) ─┐
                            ├─ /v1/logs    ─► Loki  /otlp/v1/logs                             │ remote write
                            └─ /v1/metrics ─► VictoriaMetrics /opentelemetry/v1/metrics        │ via gateway :9090
                                              or Mimir        /otlp/v1/metrics  ◄──────────────┘
Grafana ─► Loki :3100 · Tempo :3200 · gateway :9090/prometheus (the active metrics backend)
```

The gateway is a stock Caddy with a static config. It is not an OTel Collector: it does
no parsing, batching or re-encoding. It health-checks both metrics adapters every 5 s and
sends metrics traffic (OTLP ingest, Tempo remote-write, Grafana queries) to whichever
backend is up. Choosing the compose profile is the only step needed to switch backends.

## Quick start

```bash
cd observability
cp .env.example .env            # then set GRAFANA_ADMIN_PASSWORD and FALAK_OTLP_TOKEN (both required)
docker compose --profile victoriametrics up -d
FALAK_OTLP_TOKEN=<token> ./smoke-test.sh   # verifies traces, logs, metrics and span-metrics end to end
open http://localhost:3000      # admin / GRAFANA_ADMIN_PASSWORD
```

To bring the stack up, test it and tear it down (including volumes) in one run:
`./smoke-test.sh --stack victoriametrics`, `--stack mimir` or `--stack all`.

## Switching metrics backend

```bash
docker compose --profile victoriametrics down
docker compose --profile mimir up -d
# optional: enable Mimir-specific datasource features
echo FALAK_METRICS_PROMETHEUS_TYPE=Mimir >> .env && docker compose --profile mimir up -d grafana
```

Run only one profile at a time. If both are running, VictoriaMetrics wins (`lb_policy first`).
Data is not migrated between backends because each one keeps its own volume (`vm-data`,
`mimir-data`). The dashboards and alert rules work unchanged on both, because both backends
use the same names:

* VictoriaMetrics runs with `-opentelemetry.usePrometheusNaming`, so it adds unit suffixes
  and `_total` the same way Mimir's OTLP translation does.
* Mimir promotes the Falak resource attributes to labels (`promote_otel_resource_attributes`).
  VictoriaMetrics keeps every resource attribute as a label by default.

The control plane's `Telemetry\Contracts\MetricsBackend` adapter can also call a backend
directly: `http://victoriametrics:8428` or `http://mimir:9009/prometheus`.

## Sizing

| Service | Image | Memory limit | Measured RSS (sim, light load) | Retention |
|---|---|---|---|---|
| gateway | `caddy:2.11.4-alpine` | 64 MB | ~20 MB | – |
| Loki (monolithic) | `grafana/loki:3.7.8` | 512 MB | ~45–80 MB | 15 d (`FALAK_LOGS_RETENTION=360h`) |
| Tempo (monolithic) | `grafana/tempo:2.10.8` | 512 MB | ~40 MB | 15 d (`FALAK_TRACES_RETENTION=360h`) |
| VictoriaMetrics | `victoriametrics/victoria-metrics:v1.152.0` | 512 MB | ~80 MB | 30 d (`FALAK_METRICS_RETENTION=30d`) |
| Mimir (monolithic) | `grafana/mimir:3.2.1` | 1 GB | ~60 MB idle (grows with series) | 30 d |
| Grafana | `grafana/grafana:13.2.2` | 768 MB | ~300–400 MB | – |

* **2 GB box**: use the VictoriaMetrics profile. The limits add up to about 2.4 GB, but in
  steady state the stack uses about 0.6–0.7 GB. Grafana is the largest process. The other
  limits are ceilings to protect the host, not what each service normally uses.
* **4 GB box**: either profile. Mimir is worth its extra memory only if you want Mimir
  features (object storage, horizontal scale-out later). Otherwise VictoriaMetrics is
  lighter per series.
* **Disk**: plan for about 1–3 GB/day of logs and 0.5–2 GB/day of traces for a busy fleet.
  Metrics are small (VictoriaMetrics uses about 1 byte per sample). All state lives in
  named volumes: `loki-data`, `tempo-data`, `vm-data`, `mimir-data`, `grafana-data`.
* **Cardinality**: Tempo's span-metrics dimensions (`tempo/tempo.yaml`) are chosen to be
  bounded: route templates, job/mail/notification classes, cache op/store, schedule name,
  outgoing host. Never add per-request ids, raw SQL or full URLs. Slow queries come from
  TraceQL instead.

## Security

* `:4318` is the only port meant to be public, and binds to `127.0.0.1` unless
  `FALAK_OTLP_BIND=0.0.0.0`. `/v1/*` requires `Authorization: Bearer $FALAK_OTLP_TOKEN`; without
  a token every request is refused (`FALAK_OTLP_ALLOW_ANONYMOUS=1` turns that off, for a loopback
  trial only). For TLS, reach it over the private network (WireGuard, see `net.wireguard.apply`)
  or put it behind the edge Caddy.
* Grafana, Loki, Tempo and the metrics query port bind to `127.0.0.1` by default.

## What is provisioned

* **Datasources** (`grafana/provisioning/datasources/falak.yaml`): `Metrics` (Prometheus type,
  uid `falak-metrics`), `Loki` (uid `falak-loki`, `trace_id` derived field → Tempo), and `Tempo`
  (uid `falak-tempo`) with trace→logs (Loki, by trace id and `service_name`), trace→metrics
  (span-metrics rate/errors/p95), service map and exemplars.
* **Dashboards** (folder *Falak*, generated by `tools/gen-dashboards.py`; edit the generator,
  not the JSON):
  Server · Laravel site · Node app · Deployments · Containers · Queues.
* **Alert rules** (Grafana-managed, `grafana/provisioning/alerting/falak-rules.yaml`):

  | Rule | Query (summary) | For |
  |---|---|---|
  | Disk usage > 85% | `system_filesystem_utilization_ratio` | 5m |
  | Memory usage > 90% | `system_memory_utilization_ratio` | 10m |
  | Host heartbeat missing | host seen in last 24h but no `system_memory_usage_bytes` in 3m | 2m |
  | 5xx rate spike | 5xx share > 5 % **and** > 0.1 req/s per site | 3m |
  | p95 latency above threshold | request p95 > 1 s per site | 10m |
  | Queue failed jobs | any `falak_job_status="failed"` in 5m | 0s |
  | Deployment failed (log-based) | Loki: agent log with `falak.deployment.status=failed` | 0s |

  Grafana does not expand env vars in alert provisioning files, so to change a threshold,
  edit the YAML. Contact points and notification policies are left to the control plane
  Alerting module; the rules carry the labels `falak=default` and `severity` for routing.

## Metric & label contract (integration point for `falak-agent` / APM packages)

`contracts/telemetry/README.md` defines span attributes and resource attributes. The
dashboards also depend on the following, which the agent and APM packages must emit.

**Encoding.** VictoriaMetrics accepts OTLP metrics only as **protobuf**
(`json encoding isn't supported for opentelemetry format`). The agent must export metrics as
`application/x-protobuf`. Traces and logs accept JSON or protobuf on both backends.

**Resource attributes → labels.** `service.name`→`service_name`, `host.name`→`host_name`,
`falak.site.id`→`falak_site_id`, `falak.server.id`→`falak_server_id`. In Loki, `host.name`,
`falak.org.id`, `falak.server.id`, `falak.site.id` and `falak.log.kind` are index labels. Other attributes
(including `trace_id`) are structured metadata.

**Site logs.** Every record the agent ships for a site has `service_name=<site slug>` and
`falak_log_kind`: `app` (the site's log files such as `storage/logs/*.log` with stack traces merged into one
record, supervised programs, cron, containers) or `access` (the edge's per-site HTTP access log, one record per
request with structured metadata `http_request_method`, `url_path`, `url_query`, `http_response_status_code`,
`http_server_duration_ms`, `http_response_body_size`, `client_address`, `user_agent_original`,
`server_address`). Example: `{falak_site_id="01…", falak_log_kind="access"} | http_response_status_code >= 500`.
App records read from files carry the structured metadata `falak_log_origin`: `file` (host log files),
`container_file` (files inside the site's containers, with `container_id`, `container_name`,
`falak_compose_service`), `php_fpm` (an own PHP-FPM master's error log), `php_fpm_slow` (a pool's slow log); output
(containers, programs, cron) has `log_iostream` instead. It is not an index label: stream cardinality is unchanged.

**Span metrics** (from Tempo, no app work needed): `traces_spanmetrics_calls_total`,
`traces_spanmetrics_latency_{bucket,sum,count}` with `service`, `span_name`, `span_kind`,
`status_code` and the dimensions `falak_event_type`, `falak_site_id`, `falak_server_id`,
`http_route`, `http_request_method`, `http_response_status_code`,
`messaging_destination_name`, `falak_job_class`, `falak_job_status`, `falak_cache_op`,
`falak_cache_store`, `falak_mail_class`, `falak_notification_{class,channel,status}`,
`falak_command_name`, `falak_schedule_{name,status}`, `db_system_name`, `server_address`.
Outgoing requests should set `server.address`, per the OTel HTTP client semconv.
Tempo drops spans whose start time is more than 30 s old from span metrics (they are
still stored as traces), so the agent should not hold spans longer than that before exporting.

**Host metrics** (falak-agent `internal/metrics`, OTel `system.*` semconv; verified against the agent in the sim):

| OTel metric | Series | Attributes → labels |
|---|---|---|
| `system.cpu.utilization` (1, busy fraction, all CPUs) | `system_cpu_utilization_ratio` | – |
| `system.cpu.time` (s, sum) | `system_cpu_time_seconds_total` | `cpu.mode` |
| `system.memory.usage` (By) | `system_memory_usage_bytes` | `system.memory.state` |
| `system.memory.utilization` (1, used/total) | `system_memory_utilization_ratio` | – |
| `system.filesystem.utilization` (1) | `system_filesystem_utilization_ratio` | `system.device`, `system.filesystem.mountpoint` |
| `system.cpu.load_average.{1m,5m,15m}` ({thread}) | `system_cpu_load_average_1m` … | – |
| `system.network.io` (By, sum) | `system_network_io_bytes_total` | `network.io.direction`, `network.interface.name` |
| `system.disk.io` (By, sum) | `system_disk_io_bytes_total` | `disk.io.direction`, `system.device` |

`system_memory_usage_bytes` also serves as the telemetry heartbeat for the
*Host heartbeat missing* rule.

**Container metrics** (falak-agent docker collector): `container.cpu.utilization` (1),
`container.memory.usage` (By), `container.memory.limit` (By), `container.network.io` (By, sum)
with `container.name` and `network.io.direction`.

**Node runtime** (`@othmanhaba/apm-node`, OTel runtime-node conventions):
`nodejs.eventloop.delay.p50|p99` (s), `nodejs.eventloop.utilization` (1),
`v8js.memory.heap.used` (By, `v8js.heap.space.name`).

**Queue size** (`falak/apm-laravel`): gauge `falak.queue.size` with `messaging.destination.name`.

**Deployment events** (falak-agent → Loki, used by the Deployments dashboard and the
*Deployment failed* alert): an OTLP log record with resource `service.name=falak-agent` and
attributes `falak.event.type=deployment`, `falak.deployment.id`, `falak.site.id`,
`falak.deployment.status` ∈ `started|succeeded|failed|rolled_back`. The control plane should
also create a Grafana annotation tagged `falak`,`deployment` for each deployment. The
dashboards show both.

## Files

```
compose.yml                      services, profiles, limits, volumes
gateway/Caddyfile                OTLP ingress + metrics-backend router
loki/loki.yaml  tempo/tempo.yaml  mimir/mimir.yaml
grafana/provisioning/            datasources, dashboard provider, alert rules
grafana/dashboards/*.json        generated dashboards
tools/gen-dashboards.py          dashboard generator
smoke-test.sh                    end-to-end ingest → query verification
```
