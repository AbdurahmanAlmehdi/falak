#!/usr/bin/env python3
"""Generate the provisioned Grafana dashboards in ../grafana/dashboards/*.json.

The JSON files are committed; re-run this after editing:  python3 tools/gen-dashboards.py

Series/label names follow observability/README.md ("Metric & label contract"):
  * span metrics from Tempo's metrics-generator: traces_spanmetrics_{calls_total,latency_bucket}
    with labels service, span_name, span_kind, status_code + the falak.* / http.* dimensions
    (dots -> underscores, e.g. falak_event_type, http_route, falak_site_id).
  * host / container / runtime metrics from falak-agent and @othmanhaba/apm-node, OTel semantic
    conventions translated with Prometheus naming (unit suffixes, _total).
"""
import json
import pathlib

OUT = pathlib.Path(__file__).resolve().parent.parent / "grafana" / "dashboards"
METRICS = {"type": "prometheus", "uid": "falak-metrics"}
LOKI = {"type": "loki", "uid": "falak-loki"}
TEMPO = {"type": "tempo", "uid": "falak-tempo"}

CALLS = "traces_spanmetrics_calls_total"
LAT = "traces_spanmetrics_latency_bucket"
RI = "$__rate_interval"
HIT = 'falak_cache_op="hit"'
HITMISS = 'falak_cache_op=~"hit|miss"'
OUTREQ = 'falak_event_type="outgoing_request"'
QUEUE_SEL = 'messaging_destination_name=~"$queue"'


# --------------------------------------------------------------------------- helpers
class Layout:
    def __init__(self):
        self.x = 0
        self.y = 0
        self.row_h = 0
        self.next_id = 1

    def place(self, w, h):
        if self.x + w > 24:
            self.x, self.y, self.row_h = 0, self.y + self.row_h, 0
        pos = {"x": self.x, "y": self.y, "w": w, "h": h}
        self.x += w
        self.row_h = max(self.row_h, h)
        return pos

    def newline(self):
        if self.x:
            self.x, self.y, self.row_h = 0, self.y + self.row_h, 0

    def id(self):
        self.next_id += 1
        return self.next_id - 1


def prom(expr, legend="", instant=False, ref="A"):
    t = {"datasource": METRICS, "refId": ref, "expr": expr, "legendFormat": legend or "__auto", "range": not instant, "instant": instant}
    if instant:
        t["format"] = "table"
    return t


def loki(expr, ref="A", legend=""):
    return {"datasource": LOKI, "refId": ref, "expr": expr, "queryType": "range", "legendFormat": legend}


def traceql(q, limit=20, ref="A"):
    return {"datasource": TEMPO, "refId": ref, "queryType": "traceql", "query": q, "limit": limit, "tableType": "spans", "spss": 3}


def panel(L, kind, title, targets, w=12, h=8, unit=None, desc="", ds=METRICS, overrides=None, options=None, custom=None, thresholds=None, decimals=None, minv=None, maxv=None):
    defaults = {}
    if unit:
        defaults["unit"] = unit
    if decimals is not None:
        defaults["decimals"] = decimals
    if minv is not None:
        defaults["min"] = minv
    if maxv is not None:
        defaults["max"] = maxv
    if custom:
        defaults["custom"] = custom
    if thresholds:
        defaults["thresholds"] = {"mode": "absolute", "steps": [{"color": "green", "value": None}] + [{"color": c, "value": v} for v, c in thresholds]}
        defaults["color"] = {"mode": "thresholds"}
    p = {
        "id": L.id(),
        "type": kind,
        "title": title,
        "description": desc,
        "datasource": ds,
        "gridPos": L.place(w, h),
        "targets": targets,
        "fieldConfig": {"defaults": defaults, "overrides": overrides or []},
        "options": options or {},
    }
    return p


def ts(L, title, targets, **kw):
    kw.setdefault("custom", {"drawStyle": "line", "lineWidth": 1, "fillOpacity": 10, "showPoints": "never", "spanNulls": True})
    kw.setdefault("options", {"legend": {"displayMode": "table", "placement": "bottom", "calcs": ["mean", "max", "lastNotNull"]}, "tooltip": {"mode": "multi", "sort": "desc"}})
    return panel(L, "timeseries", title, targets, **kw)


def stat(L, title, targets, w=6, h=4, **kw):
    kw.setdefault("options", {"reduceOptions": {"calcs": ["lastNotNull"], "fields": "", "values": False}, "colorMode": "value", "graphMode": "area", "textMode": "auto"})
    return panel(L, "stat", title, targets, w=w, h=h, **kw)


def table(L, title, targets, w=12, h=8, **kw):
    kw.setdefault("options", {"showHeader": True, "cellHeight": "sm"})
    p = panel(L, "table", title, targets, w=w, h=h, **kw)
    p["transformations"] = [{"id": "merge", "options": {}}, {"id": "organize", "options": {"excludeByName": {"Time": True}}}]
    return p


def row(L, title):
    L.newline()
    p = {"id": L.id(), "type": "row", "title": title, "collapsed": False, "gridPos": {"x": 0, "y": L.y, "w": 24, "h": 1}, "panels": []}
    L.y += 1
    return p


def var_query(name, label, query, ds=METRICS, multi=True, all_value=".+", hide=0):
    return {
        "name": name, "label": label, "type": "query", "datasource": ds,
        "query": {"query": query, "refId": f"{name}-var"} if ds is METRICS else query,
        "definition": query, "refresh": 2, "sort": 1, "multi": multi, "includeAll": multi,
        "allValue": all_value if multi else None, "current": {}, "hide": hide,
    }


def dashboard(uid, title, tags, panels, variables, annotations=None, desc="", refresh="30s", time_from="now-6h"):
    base_ann = [{
        "builtIn": 1, "datasource": {"type": "grafana", "uid": "-- Grafana --"}, "enable": True, "hide": True,
        "iconColor": "rgba(0, 211, 255, 1)", "name": "Annotations & Alerts", "type": "dashboard",
    }]
    return {
        "uid": uid, "title": title, "description": desc, "tags": ["falak"] + tags, "timezone": "browser",
        "editable": False, "graphTooltip": 1, "schemaVersion": 41, "version": 1, "refresh": refresh,
        "time": {"from": time_from, "to": "now"},
        "templating": {"list": variables},
        "annotations": {"list": base_ann + (annotations or [])},
        "links": [{"type": "dashboards", "tags": ["falak"], "asDropdown": True, "title": "Falak", "includeVars": True, "keepTime": True}],
        "panels": panels,
    }


# Grafana annotations written by the control plane (Telemetry module) on every deployment,
# plus agent deployment log events from Loki.
DEPLOY_ANNOTATIONS = [
    {
        "name": "Deployments (control plane)", "enable": True, "iconColor": "#8e44ff",
        "datasource": {"type": "grafana", "uid": "-- Grafana --"},
        "target": {"type": "tags", "tags": ["falak", "deployment"], "limit": 200, "matchAny": False},
    },
    {
        "name": "Deployments (agent logs)", "enable": True, "iconColor": "#ff9830",
        "datasource": LOKI,
        "expr": '{service_name="falak-agent"} | falak_event_type="deployment" | falak_site_id=~"${site:regex}"',
        "titleFormat": "Deploy {{falak_deployment_status}}", "textFormat": "{{falak_deployment_id}}",
        "tagKeys": "falak_deployment_status,falak_site_id",
    },
]


def site_sel(extra=""):
    s = 'falak_site_id=~"$site"'
    return s + ("," + extra if extra else "")


# --------------------------------------------------------------------------- Server
def server():
    L = Layout()
    h = 'host_name=~"$host"'
    P = [
        stat(L, "CPU busy", [prom(f'avg(system_cpu_utilization_ratio{{{h}}})', instant=True)], unit="percentunit", thresholds=[(0.8, "orange"), (0.95, "red")], decimals=1),
        stat(L, "Memory used", [prom(f'max(system_memory_utilization_ratio{{{h}}})', instant=True)], unit="percentunit", thresholds=[(0.8, "orange"), (0.9, "red")], decimals=1),
        stat(L, "Fullest filesystem", [prom(f'max(system_filesystem_utilization_ratio{{{h}}})', instant=True)], unit="percentunit", thresholds=[(0.75, "orange"), (0.85, "red")], decimals=1),
        stat(L, "Load (1m)", [prom(f'max(system_cpu_load_average_1m{{{h}}})', instant=True)], decimals=2),
        ts(L, "CPU utilisation by mode", [prom(f'sum by (host_name, cpu_mode) (rate(system_cpu_time_seconds_total{{{h},cpu_mode!="idle"}}[{RI}])) / on (host_name) group_left sum by (host_name) (rate(system_cpu_time_seconds_total{{{h}}}[{RI}]))', "{{host_name}} {{cpu_mode}}")], unit="percentunit", custom={"drawStyle": "line", "fillOpacity": 30, "stacking": {"mode": "normal"}, "showPoints": "never"}),
        ts(L, "Memory", [
            prom(f'sum by (host_name, system_memory_state) (system_memory_usage_bytes{{{h}}})', "{{host_name}} {{system_memory_state}}"),
        ], unit="bytes", custom={"drawStyle": "line", "fillOpacity": 30, "stacking": {"mode": "normal"}, "showPoints": "never"}),
        ts(L, "Filesystem utilisation", [prom(f'max by (host_name, system_filesystem_mountpoint) (system_filesystem_utilization_ratio{{{h}}})', "{{host_name}} {{system_filesystem_mountpoint}}")], unit="percentunit", maxv=1, minv=0,
           thresholds=[(0.85, "red")], custom={"drawStyle": "line", "fillOpacity": 0, "showPoints": "never", "thresholdsStyle": {"mode": "line+area"}}),
        ts(L, "Load average", [
            prom(f'system_cpu_load_average_1m{{{h}}}', "{{host_name}} 1m", ref="A"),
            prom(f'system_cpu_load_average_5m{{{h}}}', "{{host_name}} 5m", ref="B"),
            prom(f'system_cpu_load_average_15m{{{h}}}', "{{host_name}} 15m", ref="C"),
        ]),
        ts(L, "Network throughput", [prom(f'sum by (host_name, network_io_direction) (rate(system_network_io_bytes_total{{{h},network_interface_name!="lo"}}[{RI}]))', "{{host_name}} {{network_io_direction}}")], unit="Bps"),
        ts(L, "Disk I/O", [prom(f'sum by (host_name, disk_io_direction) (rate(system_disk_io_bytes_total{{{h}}}[{RI}]))', "{{host_name}} {{disk_io_direction}}")], unit="Bps"),
        panel(L, "logs", "System logs (journald)", [loki('{host_name=~"$host", service_name=~"falak-agent|journald"}')], w=24, h=10, ds=LOKI,
              options={"showTime": True, "wrapLogMessage": True, "sortOrder": "Descending", "enableLogDetails": True}),
    ]
    V = [var_query("host", "Host", "label_values(system_memory_usage_bytes, host_name)")]
    return dashboard("falak-server", "Falak / Server", ["server"], P, V, DEPLOY_ANNOTATIONS[:1],
                     desc="Host metrics shipped by falak-agent (OTel system.* semantic conventions).")


# --------------------------------------------------------------------------- Laravel site
def laravel():
    L = Layout()
    req = site_sel('falak_event_type="request"')

    def by(ev, extra=""):
        return site_sel(f'falak_event_type="{ev}"' + ("," + extra if extra else ""))

    P = [
        stat(L, "Requests / s", [prom(f'sum(rate({CALLS}{{{req}}}[5m]))', instant=True)], unit="reqps", decimals=2),
        stat(L, "5xx ratio", [prom(f'sum(rate({CALLS}{{{req},http_response_status_code=~"5.."}}[5m])) / sum(rate({CALLS}{{{req}}}[5m]))', instant=True)], unit="percentunit", thresholds=[(0.01, "orange"), (0.05, "red")], decimals=2),
        stat(L, "p95 latency", [prom(f'histogram_quantile(0.95, sum by (le) (rate({LAT}{{{req}}}[5m])))', instant=True)], unit="s", thresholds=[(0.5, "orange"), (1, "red")]),
        stat(L, "Cache hit ratio", [prom(f'sum(rate({CALLS}{{{by("cache", HIT)}}}[5m])) / sum(rate({CALLS}{{{by("cache", HITMISS)}}}[5m]))', instant=True)], unit="percentunit", decimals=1),

        row(L, "Requests"),
        ts(L, "Request rate by route", [prom(f'sum by (http_route) (rate({CALLS}{{{req}}}[{RI}]))', "{{http_route}}")], unit="reqps"),
        ts(L, "Latency p50 / p95", [
            prom(f'histogram_quantile(0.50, sum by (le) (rate({LAT}{{{req}}}[{RI}])))', "p50", ref="A"),
            prom(f'histogram_quantile(0.95, sum by (le) (rate({LAT}{{{req}}}[{RI}])))', "p95", ref="B"),
        ], unit="s"),
        ts(L, "p95 latency by route", [prom(f'histogram_quantile(0.95, sum by (le, http_route) (rate({LAT}{{{req}}}[{RI}])))', "{{http_route}}")], unit="s"),
        ts(L, "Responses by status", [prom(f'sum by (http_response_status_code) (rate({CALLS}{{{req}}}[{RI}]))', "{{http_response_status_code}}")], unit="reqps",
           custom={"drawStyle": "bars", "fillOpacity": 80, "stacking": {"mode": "normal"}, "showPoints": "never"}),
        table(L, "Routes (last range)", [
            prom(f'sum by (http_request_method, http_route) (increase({CALLS}{{{req}}}[$__range]))', ref="A", instant=True),
            prom(f'histogram_quantile(0.50, sum by (le, http_request_method, http_route) (rate({LAT}{{{req}}}[$__range])))', ref="B", instant=True),
            prom(f'histogram_quantile(0.95, sum by (le, http_request_method, http_route) (rate({LAT}{{{req}}}[$__range])))', ref="C", instant=True),
            prom(f'sum by (http_request_method, http_route) (increase({CALLS}{{{req},http_response_status_code=~"5.."}}[$__range]))', ref="D", instant=True),
        ], w=24, h=9, overrides=[
            {"matcher": {"id": "byName", "options": "Value #A"}, "properties": [{"id": "displayName", "value": "Requests"}, {"id": "decimals", "value": 0}]},
            {"matcher": {"id": "byName", "options": "Value #B"}, "properties": [{"id": "displayName", "value": "p50"}, {"id": "unit", "value": "s"}]},
            {"matcher": {"id": "byName", "options": "Value #C"}, "properties": [{"id": "displayName", "value": "p95"}, {"id": "unit", "value": "s"}]},
            {"matcher": {"id": "byName", "options": "Value #D"}, "properties": [{"id": "displayName", "value": "5xx"}, {"id": "decimals", "value": 0}]},
        ]),

        row(L, "Exceptions"),
        ts(L, "Error spans by event type", [prom(f'sum by (falak_event_type) (rate({CALLS}{{{site_sel()},status_code="STATUS_CODE_ERROR"}}[{RI}]))', "{{falak_event_type}}")], unit="ops"),
        panel(L, "table", "Recent failing spans (exceptions)", [traceql('{ resource.falak.site.id =~ "${site:regex}" && status = error }', limit=50)], ds=TEMPO),

        row(L, "Database"),
        ts(L, "Query rate & p95", [
            prom(f'sum by (db_system_name) (rate({CALLS}{{{by("query")}}}[{RI}]))', "{{db_system_name}} rate", ref="A"),
            prom(f'histogram_quantile(0.95, sum by (le, db_system_name) (rate({LAT}{{{by("query")}}}[{RI}])))', "{{db_system_name}} p95", ref="B"),
        ], overrides=[{"matcher": {"id": "byFrameRefID", "options": "B"}, "properties": [{"id": "unit", "value": "s"}, {"id": "custom.axisPlacement", "value": "right"}]}]),
        panel(L, "table", "Slow queries (> $slow_query_ms ms)", [traceql('{ resource.falak.site.id =~ "${site:regex}" && span.falak.event.type = "query" && duration > ${slow_query_ms}ms } | select(span.db.query.text, span.db.namespace)', limit=50)], ds=TEMPO),

        row(L, "Queues & jobs"),
        ts(L, "Jobs by status", [prom(f'sum by (falak_job_status) (rate({CALLS}{{{by("job")}}}[{RI}]))', "{{falak_job_status}}")], unit="ops",
           overrides=[{"matcher": {"id": "byName", "options": "failed"}, "properties": [{"id": "color", "value": {"mode": "fixed", "fixedColor": "red"}}]}]),
        ts(L, "Job duration p95 by class", [prom(f'histogram_quantile(0.95, sum by (le, falak_job_class) (rate({LAT}{{{by("job")}}}[{RI}])))', "{{falak_job_class}}")], unit="s"),

        row(L, "Cache"),
        ts(L, "Cache operations", [prom(f'sum by (falak_cache_op) (rate({CALLS}{{{by("cache")}}}[{RI}]))', "{{falak_cache_op}}")], unit="ops"),
        ts(L, "Cache hit ratio by store", [prom(f'sum by (falak_cache_store) (rate({CALLS}{{{by("cache", HIT)}}}[{RI}])) / sum by (falak_cache_store) (rate({CALLS}{{{by("cache", HITMISS)}}}[{RI}]))', "{{falak_cache_store}}")], unit="percentunit", minv=0, maxv=1),

        row(L, "Outgoing requests"),
        ts(L, "Outgoing request rate by host", [prom(f'sum by (server_address) (rate({CALLS}{{{by("outgoing_request")}}}[{RI}]))', "{{server_address}}")], unit="reqps"),
        ts(L, "Outgoing p95 by host", [prom(f'histogram_quantile(0.95, sum by (le, server_address) (rate({LAT}{{{by("outgoing_request")}}}[{RI}])))', "{{server_address}}")], unit="s"),

        row(L, "Mail & notifications"),
        ts(L, "Mail sent by class", [prom(f'sum by (falak_mail_class) (increase({CALLS}{{{by("mail")}}}[{RI}]))', "{{falak_mail_class}}")], unit="short",
           custom={"drawStyle": "bars", "fillOpacity": 80, "stacking": {"mode": "normal"}, "showPoints": "never"}),
        ts(L, "Notifications by channel / status", [prom(f'sum by (falak_notification_channel, falak_notification_status) (increase({CALLS}{{{by("notification")}}}[{RI}]))', "{{falak_notification_channel}} {{falak_notification_status}}")], unit="short",
           custom={"drawStyle": "bars", "fillOpacity": 80, "stacking": {"mode": "normal"}, "showPoints": "never"}),

        row(L, "Scheduled tasks & commands"),
        table(L, "Scheduled tasks (last range)", [
            prom(f'sum by (falak_schedule_name, falak_schedule_status) (increase({CALLS}{{{by("scheduled_task")}}}[$__range]))', ref="A", instant=True),
        ], overrides=[{"matcher": {"id": "byName", "options": "Value"}, "properties": [{"id": "displayName", "value": "Runs"}, {"id": "decimals", "value": 0}]}]),
        ts(L, "Commands p95 by name", [prom(f'histogram_quantile(0.95, sum by (le, falak_command_name) (rate({LAT}{{{by("command")}}}[{RI}])))', "{{falak_command_name}}")], unit="s"),

        row(L, "Logs"),
        panel(L, "logs", "Application logs", [loki('{falak_site_id=~"${site:regex}"}')], w=24, h=12, ds=LOKI,
              options={"showTime": True, "wrapLogMessage": True, "sortOrder": "Descending", "enableLogDetails": True}),
    ]
    V = [
        var_query("site", "Site", f'label_values({CALLS}{{falak_event_type="request"}}, falak_site_id)'),
        {"name": "slow_query_ms", "label": "Slow query (ms)", "type": "custom", "query": "100,250,500,1000", "current": {"text": "100", "value": "100"},
         "options": [], "multi": False, "includeAll": False},
    ]
    return dashboard("falak-laravel", "Falak / Laravel site", ["laravel", "site"], P, V, DEPLOY_ANNOTATIONS,
                     desc="Nightwatch-style APM for a Laravel site: span metrics (Tempo metrics-generator) + TraceQL + Loki.")


# --------------------------------------------------------------------------- Node app
def node():
    L = Layout()
    req = site_sel('falak_event_type="request"')
    rt = 'falak_site_id=~"$site"'
    P = [
        stat(L, "Requests / s", [prom(f'sum(rate({CALLS}{{{req}}}[5m]))', instant=True)], unit="reqps", decimals=2),
        stat(L, "5xx ratio", [prom(f'sum(rate({CALLS}{{{req},http_response_status_code=~"5.."}}[5m])) / sum(rate({CALLS}{{{req}}}[5m]))', instant=True)], unit="percentunit", thresholds=[(0.01, "orange"), (0.05, "red")], decimals=2),
        stat(L, "p95 latency", [prom(f'histogram_quantile(0.95, sum by (le) (rate({LAT}{{{req}}}[5m])))', instant=True)], unit="s", thresholds=[(0.5, "orange"), (1, "red")]),
        stat(L, "Event loop delay p99", [prom(f'max(nodejs_eventloop_delay_p99_seconds{{{rt}}})', instant=True)], unit="s", thresholds=[(0.1, "orange"), (0.5, "red")]),
        ts(L, "Request rate by route", [prom(f'sum by (http_route) (rate({CALLS}{{{req}}}[{RI}]))', "{{http_route}}")], unit="reqps"),
        ts(L, "p95 latency by route", [prom(f'histogram_quantile(0.95, sum by (le, http_route) (rate({LAT}{{{req}}}[{RI}])))', "{{http_route}}")], unit="s"),
        ts(L, "Event loop delay", [
            prom(f'max by (service_name, host_name) (nodejs_eventloop_delay_p50_seconds{{{rt}}})', "{{host_name}} p50", ref="A"),
            prom(f'max by (service_name, host_name) (nodejs_eventloop_delay_p99_seconds{{{rt}}})', "{{host_name}} p99", ref="B"),
        ], unit="s"),
        ts(L, "Event loop utilisation", [prom(f'avg by (host_name) (nodejs_eventloop_utilization_ratio{{{rt}}})', "{{host_name}}")], unit="percentunit", minv=0, maxv=1),
        ts(L, "V8 heap used by space", [prom(f'sum by (host_name, v8js_heap_space_name) (v8js_memory_heap_used_bytes{{{rt}}})', "{{host_name}} {{v8js_heap_space_name}}")], unit="bytes",
           custom={"drawStyle": "line", "fillOpacity": 30, "stacking": {"mode": "normal"}, "showPoints": "never"}),
        ts(L, "Outgoing requests p95 by host", [prom(f'histogram_quantile(0.95, sum by (le, server_address) (rate({LAT}{{{site_sel(OUTREQ)}}}[{RI}])))', "{{server_address}}")], unit="s"),
        ts(L, "Error spans", [prom(f'sum by (span_name) (rate({CALLS}{{{site_sel()},status_code="STATUS_CODE_ERROR"}}[{RI}]))', "{{span_name}}")], unit="ops"),
        panel(L, "table", "Recent failing spans", [traceql('{ resource.falak.site.id =~ "${site:regex}" && status = error }', limit=50)], ds=TEMPO),
        panel(L, "logs", "Application logs", [loki('{falak_site_id=~"${site:regex}"}')], w=24, h=12, ds=LOKI,
              options={"showTime": True, "wrapLogMessage": True, "sortOrder": "Descending", "enableLogDetails": True}),
    ]
    V = [var_query("site", "Site", f'label_values({CALLS}{{falak_event_type="request"}}, falak_site_id)')]
    return dashboard("falak-node", "Falak / Node app", ["node", "site"], P, V, DEPLOY_ANNOTATIONS,
                     desc="@othmanhaba/apm-node: HTTP span metrics + Node runtime metrics (OTel nodejs.* / v8js.* conventions).")


# --------------------------------------------------------------------------- Deployments
def deployments():
    L = Layout()
    req = site_sel('falak_event_type="request"')
    dep = '{service_name="falak-agent"} | falak_event_type="deployment" | falak_site_id=~"${site:regex}"'
    P = [
        stat(L, "Deployments (range)", [loki(f'sum(count_over_time({dep} | falak_deployment_status="started" [$__range]))')], ds=LOKI, w=8),
        stat(L, "Failed (range)", [loki(f'sum(count_over_time({dep} | falak_deployment_status="failed" [$__range]))')], ds=LOKI, w=8, thresholds=[(1, "red")]),
        stat(L, "Rolled back (range)", [loki(f'sum(count_over_time({dep} | falak_deployment_status="rolled_back" [$__range]))')], ds=LOKI, w=8, thresholds=[(1, "orange")]),
        ts(L, "Deployment events by status", [loki(f'sum by (falak_deployment_status) (count_over_time({dep} [$__auto]))', legend="{{falak_deployment_status}}")], ds=LOKI, w=24,
           custom={"drawStyle": "bars", "fillOpacity": 80, "stacking": {"mode": "normal"}, "showPoints": "never"}),
        ts(L, "Request rate around deployments", [prom(f'sum by (falak_site_id) (rate({CALLS}{{{req}}}[{RI}]))', "{{falak_site_id}}")], unit="reqps"),
        ts(L, "5xx ratio around deployments", [prom(f'sum by (falak_site_id) (rate({CALLS}{{{req},http_response_status_code=~"5.."}}[{RI}])) / sum by (falak_site_id) (rate({CALLS}{{{req}}}[{RI}]))', "{{falak_site_id}}")], unit="percentunit"),
        ts(L, "p95 latency around deployments", [prom(f'histogram_quantile(0.95, sum by (le, falak_site_id) (rate({LAT}{{{req}}}[{RI}])))', "{{falak_site_id}}")], unit="s"),
        panel(L, "annolist", "Deployment annotations", [], ds={"type": "grafana", "uid": "-- Grafana --"},
              options={"onlyFromThisDashboard": False, "onlyInTimeRange": True, "tags": ["deployment"], "limit": 20, "showUser": True, "showTime": True, "showTags": True, "navigateToPanel": False}),
        panel(L, "logs", "Deployment log", [loki(dep)], w=24, h=12, ds=LOKI,
              options={"showTime": True, "wrapLogMessage": True, "sortOrder": "Descending", "enableLogDetails": True}),
    ]
    V = [var_query("site", "Site", f'label_values({CALLS}{{falak_event_type="request"}}, falak_site_id)')]
    return dashboard("falak-deployments", "Falak / Deployments", ["deployments"], P, V, DEPLOY_ANNOTATIONS, time_from="now-24h",
                     desc="Deployment timeline: Grafana annotations from the control plane + agent deployment log events.")


# --------------------------------------------------------------------------- Workloads
def workloads():
    """Per-workload cgroup / per-user metrics (docs/plans/OBSERVABILITY.md phase 1): site containers (every compose
    service), database containers, native sites' slices or unix users, worker / daemon slices."""
    L = Layout()
    s = 'host_name=~"$host", service_name=~"$service", falak_workload_kind=~"$kind", falak_workload_name=~"$workload"'
    h = 'host_name=~"$host"'
    W = "host_name, service_name, falak_workload_name"
    WL = "{{host_name}} {{service_name}}/{{falak_workload_name}}"
    stacked = {"drawStyle": "line", "lineWidth": 1, "fillOpacity": 30, "stacking": {"mode": "normal"}, "showPoints": "never", "spanNulls": True}
    P = [
        stat(L, "Workloads", [prom(f'count(count by ({W}) (falak_workload_memory_usage_bytes{{{s}}}))', instant=True)], w=6),
        stat(L, "CPU used (cores)", [prom(f'sum(rate(falak_workload_cpu_time_seconds_total{{{s}}}[{RI}]))', instant=True)], w=6, decimals=2),
        stat(L, "Memory used", [prom(f'sum(falak_workload_memory_usage_bytes{{{s},falak_memory_type="used"}})', instant=True)], unit="bytes", w=6),
        stat(L, "OOM kills (range)", [prom(f'sum(increase(falak_workload_memory_oom_kills_total{{{s}}}[$__range]))', instant=True)], w=6, decimals=0, thresholds=[(1, "red")]),
        row(L, "CPU"),
        ts(L, "CPU by workload (cores)", [
            prom(f'sum by ({W}) (rate(falak_workload_cpu_time_seconds_total{{{s}}}[{RI}]))', WL, ref="A"),
            prom(f'sum by (host_name) (system_cpu_logical_count{{{h}}})', "{{host_name}} cores", ref="B"),
        ], custom=stacked, overrides=[{"matcher": {"id": "byFrameRefID", "options": "B"}, "properties": [
            {"id": "custom.stacking", "value": {"mode": "none"}}, {"id": "custom.fillOpacity", "value": 0},
            {"id": "custom.lineStyle", "value": {"fill": "dash", "dash": [6, 4]}}, {"id": "color", "value": {"mode": "fixed", "fixedColor": "text"}}]}],
           desc="rate(cpu time) = cores used; dashed: the server's logical CPUs."),
        ts(L, "CPU against limit", [
            prom(f'sum by ({W}) (rate(falak_workload_cpu_time_seconds_total{{{s}}}[{RI}])) / sum by ({W}) (falak_workload_cpu_limit{{{s}}})', WL),
        ], unit="percentunit", minv=0, desc="Workloads with a CPU limit (cpu.max) only."),
        ts(L, "CPU throttled (share of periods)", [
            prom(f'sum by ({W}) (rate(falak_workload_cpu_throttled_periods_total{{{s}}}[{RI}])) / sum by ({W}) (rate(falak_workload_cpu_periods_total{{{s}}}[{RI}]) > 0)', WL),
        ], unit="percentunit", minv=0, thresholds=[(0.25, "red")]),
        ts(L, "CPU throttled time", [
            prom(f'sum by ({W}) (rate(falak_workload_cpu_throttled_time_seconds_total{{{s}}}[{RI}]))', WL),
        ], unit="s", desc="Seconds of throttling per second."),
        row(L, "Memory"),
        ts(L, "Memory used by workload", [
            prom(f'sum by ({W}) (falak_workload_memory_usage_bytes{{{s},falak_memory_type="used"}})', WL, ref="A"),
            prom(f'sum by (host_name) (system_memory_limit_bytes{{{h}}})', "{{host_name}} memory", ref="B"),
        ], unit="bytes", custom=stacked, overrides=[{"matcher": {"id": "byFrameRefID", "options": "B"}, "properties": [
            {"id": "custom.stacking", "value": {"mode": "none"}}, {"id": "custom.fillOpacity", "value": 0},
            {"id": "custom.lineStyle", "value": {"fill": "dash", "dash": [6, 4]}}, {"id": "color", "value": {"mode": "fixed", "fixedColor": "text"}}]}],
           desc="Anon + kernel memory (the page cache is shown apart); dashed: the server's memory."),
        ts(L, "Memory against limit", [
            prom(f'sum by ({W}) (falak_workload_memory_usage_bytes{{{s},falak_memory_type="used"}}) / sum by ({W}) (falak_workload_memory_limit_bytes{{{s}}})', WL),
        ], unit="percentunit", minv=0, maxv=1, thresholds=[(0.9, "red")], desc="Workloads with a memory limit (memory.max) only."),
        ts(L, "Page cache by workload", [
            prom(f'sum by ({W}) (falak_workload_memory_usage_bytes{{{s},falak_memory_type="cache"}})', WL),
        ], unit="bytes", desc="Reclaimable: the kernel drops it before the workload runs out of memory."),
        ts(L, "OOM kills", [prom(f'sum by ({W}) (increase(falak_workload_memory_oom_kills_total{{{s}}}[{RI}]))', WL)],
           custom={"drawStyle": "bars", "fillOpacity": 80, "showPoints": "never"}, decimals=0),
        row(L, "I/O and processes"),
        ts(L, "Disk I/O", [prom(f'sum by ({W}, disk_io_direction) (rate(falak_workload_disk_io_bytes_total{{{s}}}[{RI}]))', WL + " {{disk_io_direction}}")], unit="Bps"),
        ts(L, "Network I/O (containers)", [prom(f'sum by ({W}, network_io_direction) (rate(falak_workload_network_io_bytes_total{{{s}}}[{RI}]))', WL + " {{network_io_direction}}")], unit="Bps",
           desc="Containers only: a native workload shares the server's network (see Falak / Server)."),
        ts(L, "Processes", [
            prom(f'sum by ({W}) (falak_workload_pids{{{s}}})', WL, ref="A"),
            prom(f'sum by ({W}) (falak_workload_pids_limit{{{s}}})', WL + " limit", ref="B"),
        ], decimals=0, overrides=[{"matcher": {"id": "byFrameRefID", "options": "B"}, "properties": [
            {"id": "custom.lineStyle", "value": {"fill": "dash", "dash": [6, 4]}}, {"id": "custom.fillOpacity", "value": 0}]}]),
        table(L, "Workloads now", [
            prom(f'sum by ({W}, falak_workload_kind, falak_compose_service) (rate(falak_workload_cpu_time_seconds_total{{{s}}}[{RI}]))', ref="A", instant=True),
            prom(f'sum by ({W}, falak_workload_kind, falak_compose_service) (falak_workload_memory_usage_bytes{{{s},falak_memory_type="used"}})', ref="B", instant=True),
            prom(f'sum by ({W}, falak_workload_kind, falak_compose_service) (falak_workload_memory_limit_bytes{{{s}}})', ref="C", instant=True),
        ], w=12, overrides=[
            {"matcher": {"id": "byName", "options": "Value #A"}, "properties": [{"id": "displayName", "value": "CPU (cores)"}, {"id": "decimals", "value": 2}]},
            {"matcher": {"id": "byName", "options": "Value #B"}, "properties": [{"id": "displayName", "value": "Memory"}, {"id": "unit", "value": "bytes"}]},
            {"matcher": {"id": "byName", "options": "Value #C"}, "properties": [{"id": "displayName", "value": "Memory limit"}, {"id": "unit", "value": "bytes"}]},
        ]),
        panel(L, "logs", "Container logs", [loki('{host_name=~"${host:regex}", service_name=~"${service:regex}"} | container_name=~".+"')], w=24, h=12, ds=LOKI,
              options={"showTime": True, "wrapLogMessage": True, "sortOrder": "Descending", "enableLogDetails": True}),
    ]
    # Memory usage is the one series every workload has (Docker stats on cgroup v1 report no processes).
    M = "falak_workload_memory_usage_bytes"
    V = [
        var_query("host", "Server", f"label_values({M}, host_name)"),
        var_query("service", "Site / service", f'label_values({M}{{host_name=~"$host"}}, service_name)'),
        var_query("kind", "Kind", f'label_values({M}{{host_name=~"$host", service_name=~"$service"}}, falak_workload_kind)'),
        var_query("workload", "Workload", f'label_values({M}{{host_name=~"$host", service_name=~"$service", falak_workload_kind=~"$kind"}}, falak_workload_name)'),
    ]
    # uid kept from "Falak / Containers": organizations' imported copies are replaced, not duplicated.
    return dashboard("falak-containers", "Falak / Workloads", ["workloads", "containers"], P, V, DEPLOY_ANNOTATIONS[:1],
                     desc="Per-workload CPU, memory, I/O and processes from cgroup v2 and /proc (falak-agent): site containers, "
                          "database containers, native sites (slice or unix user) and worker / daemon slices, against their limits.")


# --------------------------------------------------------------------------- Queues
def queues():
    L = Layout()
    job = site_sel('falak_event_type="job", messaging_destination_name=~"$queue"')
    P = [
        stat(L, "Processed / min", [prom(f'sum(rate({CALLS}{{{job},falak_job_status="processed"}}[5m])) * 60', instant=True)], decimals=1),
        stat(L, "Failed (1h)", [prom(f'sum(increase({CALLS}{{{job},falak_job_status="failed"}}[1h]))', instant=True)], decimals=0, thresholds=[(1, "red")]),
        stat(L, "Released / retried (1h)", [prom(f'sum(increase({CALLS}{{{job},falak_job_status="released"}}[1h]))', instant=True)], decimals=0, thresholds=[(1, "orange")]),
        stat(L, "Pending (queue size)", [prom(f'sum(falak_queue_size{{{site_sel(QUEUE_SEL)}}})', instant=True)], decimals=0,
             desc="Requires the falak.queue.size gauge from falak/apm-laravel's queue sampler."),
        ts(L, "Throughput by queue & status", [prom(f'sum by (messaging_destination_name, falak_job_status) (rate({CALLS}{{{job}}}[{RI}]))', "{{messaging_destination_name}} {{falak_job_status}}")], unit="ops"),
        ts(L, "Queue size", [prom(f'sum by (messaging_destination_name) (falak_queue_size{{{site_sel(QUEUE_SEL)}}})', "{{messaging_destination_name}}")]),
        ts(L, "Job duration p50 / p95", [
            prom(f'histogram_quantile(0.50, sum by (le, messaging_destination_name) (rate({LAT}{{{job}}}[{RI}])))', "{{messaging_destination_name}} p50", ref="A"),
            prom(f'histogram_quantile(0.95, sum by (le, messaging_destination_name) (rate({LAT}{{{job}}}[{RI}])))', "{{messaging_destination_name}} p95", ref="B"),
        ], unit="s"),
        ts(L, "Failed jobs by class", [prom(f'sum by (falak_job_class) (increase({CALLS}{{{job},falak_job_status="failed"}}[{RI}]))', "{{falak_job_class}}")],
           custom={"drawStyle": "bars", "fillOpacity": 80, "stacking": {"mode": "normal"}, "showPoints": "never"}),
        table(L, "Jobs by class (last range)", [
            prom(f'sum by (messaging_destination_name, falak_job_class, falak_job_status) (increase({CALLS}{{{job}}}[$__range]))', ref="A", instant=True),
        ], w=12, overrides=[{"matcher": {"id": "byName", "options": "Value"}, "properties": [{"id": "displayName", "value": "Count"}, {"id": "decimals", "value": 0}]}]),
        panel(L, "table", "Recent failed jobs", [traceql('{ resource.falak.site.id =~ "${site:regex}" && span.falak.event.type = "job" && span.falak.job.status = "failed" } | select(span.falak.job.class, span.messaging.destination.name, span.falak.job.attempt)', limit=50)], ds=TEMPO),
    ]
    V = [
        var_query("site", "Site", f'label_values({CALLS}{{falak_event_type="job"}}, falak_site_id)'),
        var_query("queue", "Queue", f'label_values({CALLS}{{falak_event_type="job", falak_site_id=~"$site"}}, messaging_destination_name)'),
    ]
    return dashboard("falak-queues", "Falak / Queues", ["queues"], P, V, DEPLOY_ANNOTATIONS,
                     desc="Queue workers: job throughput, failures, retries and durations from job spans.")


def main():
    OUT.mkdir(parents=True, exist_ok=True)
    for name, fn in [("server", server), ("laravel-site", laravel), ("node-app", node), ("deployments", deployments), ("containers", workloads), ("queues", queues)]:
        path = OUT / f"falak-{name}.json"
        path.write_text(json.dumps(fn(), indent=2) + "\n")
        print(f"wrote {path.relative_to(OUT.parent.parent)}")


if __name__ == "__main__":
    main()
