import {
    Button,
    Callout,
    Field,
    IconButton,
    Input,
    MetricChart,
    Segmented,
    Select,
    Skeleton,
    Tag,
    toast,
    type MetricSeries,
} from '@/components/falak';
import { useJson } from '@/hooks/use-json';
import { errorMessage, requestJson } from '@/lib/http';
import { ipActionsFor, type IpAction, type IpActionProps } from '@/lib/registry';
import { format } from 'date-fns';
import { RotateCw, Search } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react';
import { BackendError } from '../components/backend-state';
import { bytePoints, describeRules, flag, protocolLabel, shares, STATUS_CLASSES, statusPoints, tlsLabel, type Share } from '../http-traffic';
import { formatBytes, formatDuration, getJson, queryString } from '../lib';
import { combine, latest } from '../site-metrics';
import { type LogLineDto, type MetricSeriesDto } from '../types';

const RANGES = ['1h', '6h', '24h', '7d'] as const;
type Range = (typeof RANGES)[number];
const ALL = 'all';
const TABLES = [
    { value: 'paths', label: 'Paths' },
    { value: 'slow', label: 'Slowest' },
    { value: 'clients', label: 'Clients' },
    { value: 'agents', label: 'User agents' },
    { value: 'status', label: 'Status' },
    { value: 'countries', label: 'Countries' },
    { value: 'threats', label: 'Threats' },
] as const;
type Table = (typeof TABLES)[number]['value'];

interface IssueRef {
    id: string;
    title: string;
    occurrences: number;
    last_seen_at: string;
    url: string;
}

/** GET /telemetry/sites/{id}/http/data */
interface HttpData {
    range: Range;
    step: number;
    servers: { id: string; name: string }[];
    source: 'agent' | 'edge-logs';
    charts: Partial<Record<'requests' | 'bytes' | 'p95' | 'protocols' | 'tls' | 'threats', MetricSeriesDto[]>>;
    errors: Record<string, string>;
    issues: { slow: IssueRef[]; security: IssueRef[] };
    can_manage: boolean;
    logs_configured: boolean;
}

interface GeoStatus {
    country: boolean;
    attribution: string;
    attribution_url: string;
}

type Row = Record<string, string | number | null | Record<string, number>>;

/** GET /telemetry/sites/{id}/http/top */
interface TopData {
    configured: boolean;
    rows: Row[];
    networks?: Row[];
    coverage?: number | null;
    geo: GeoStatus;
}

const rate = (value: number) => (value >= 100 ? value.toFixed(0) : value >= 1 ? value.toFixed(1) : value.toFixed(2));

/**
 * Service panel → HTTP tab (docs/plans/network/http.md): the edge's traffic of the site — requests by status class,
 * bytes, p95, protocols and TLS versions, open slow-endpoint and scanner Issues, drill-down tables, bandwidth per
 * month, per-site thresholds and the request id lookup.
 */
export default function SiteHttp({ siteId, can }: { siteId: string; can: (permission: string) => boolean }) {
    const [range, setRange] = useState<Range>('1h');
    const [server, setServer] = useState<string>(ALL);
    const [data, setData] = useState<HttpData | null>(null);
    const [error, setError] = useState<unknown>(null);
    const [loading, setLoading] = useState(true);
    const base = `/telemetry/sites/${encodeURIComponent(siteId)}`;
    const query = queryString({ range, server: server === ALL ? null : server });

    const load = useCallback(
        async (signal?: AbortSignal) => {
            setLoading(true);
            try {
                setData(await getJson<HttpData>(`${base}/http/data${query}`, signal));
                setError(null);
            } catch (caught) {
                if (!signal?.aborted) setError(caught);
            } finally {
                if (!signal?.aborted) setLoading(false);
            }
        },
        [base, query],
    );

    useEffect(() => {
        const controller = new AbortController();
        void load(controller.signal);
        const timer = window.setInterval(() => void load(), 60_000);

        return () => {
            controller.abort();
            window.clearInterval(timer);
        };
    }, [load]);

    const requests = useMemo(() => statusPoints(data?.charts.requests), [data]);
    const bytes = useMemo(() => bytePoints(data?.charts.bytes), [data]);
    const p95 = useMemo(() => combine({ value: data?.charts.p95 }, { value: 'max' }), [data]);
    const protocols = useMemo(() => shares(data?.charts.protocols, 'network_protocol_version'), [data]);
    const tls = useMemo(() => shares(data?.charts.tls, 'tls_protocol_version'), [data]);
    const servers = data?.servers ?? [];
    const timeFormat = (value: number | string) =>
        format(new Date(typeof value === 'number' ? value * 1000 : value), range === '7d' ? 'MMM d' : 'HH:mm');
    const initial = loading && data === null;
    const empty = (key: string) => data?.errors[key] ?? 'No requests in this range.';
    const statusSeries: MetricSeries[] = STATUS_CLASSES.filter((c) => requests.some((p) => typeof p[c.key] === 'number')).map((c) => ({
        key: c.key,
        label: c.label,
        color: c.color,
        mark: 'area',
        stack: 'status',
    }));

    if (error !== null && data === null) return <BackendError size="sm" backend="metrics backend" error={error} onRetry={() => void load()} />;

    return (
        <div className="grid min-w-0 gap-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-fg-muted text-xs">
                    Requests served by the edge
                    {data?.source === 'edge-logs' && (
                        <Tag
                            tone="faint"
                            className="ml-2"
                            title="Counted from the access log: the servers' agents send no HTTP metrics yet (update them for protocols, TLS and threats)"
                        >
                            from the access log
                        </Tag>
                    )}
                </p>
                <div className="flex flex-wrap items-center gap-1">
                    {servers.length > 1 && (
                        <Select
                            size="sm"
                            aria-label="Server"
                            className="w-40"
                            value={server}
                            onValueChange={setServer}
                            options={[{ value: ALL, label: 'All servers' }, ...servers.map((s) => ({ value: s.id.toLowerCase(), label: s.name }))]}
                        />
                    )}
                    <Segmented label="Time range" value={range} onValueChange={setRange} options={RANGES.map((value) => ({ value, label: value }))} />
                    <IconButton size="sm" label="Refresh" icon={<RotateCw />} onClick={() => void load()} loading={loading && data !== null} />
                </div>
            </div>

            {data && (data.issues.slow.length > 0 || data.issues.security.length > 0) && (
                <Callout tone="warning" title="Open issues">
                    <ul className="grid gap-0.5 text-xs">
                        {[...data.issues.slow, ...data.issues.security].map((issue) => (
                            <li key={issue.id}>
                                <a className="text-fg underline-offset-2 hover:underline" href={issue.url}>
                                    {issue.title}
                                </a>{' '}
                                <span className="text-fg-faint">· {issue.occurrences}×</span>
                            </li>
                        ))}
                    </ul>
                </Callout>
            )}

            <div className="grid gap-3 lg:grid-cols-2">
                <MetricChart
                    title="Requests / s"
                    type="composed"
                    data={requests}
                    series={statusSeries.length > 0 ? statusSeries : [{ key: '2xx', label: '2xx' }]}
                    format={rate}
                    loading={initial}
                    timeFormat={timeFormat}
                    emptyText={empty('requests')}
                />
                <MetricChart
                    title="p95 latency"
                    data={p95}
                    series={[{ key: 'value', label: 'p95' }]}
                    value={latest(p95) !== null ? formatDuration(latest(p95)!) : undefined}
                    format={(value) => formatDuration(value)}
                    loading={initial}
                    timeFormat={timeFormat}
                    emptyText={empty('p95')}
                />
                <MetricChart
                    title="Bandwidth"
                    data={bytes}
                    series={[
                        { key: 'out', label: 'Out (responses)' },
                        { key: 'in', label: 'In (request bodies)' },
                    ]}
                    format={(value) => `${formatBytes(value)}/s`}
                    loading={initial}
                    timeFormat={timeFormat}
                    emptyText={empty('bytes')}
                />
                <figure className="border-border bg-surface-1 grid content-start gap-3 rounded-lg border p-4">
                    <figcaption className="text-fg-muted text-xs font-medium">Clients</figcaption>
                    <ShareBar title="HTTP protocol" items={protocols} label={protocolLabel} />
                    <ShareBar title="TLS version" items={tls} label={tlsLabel} />
                </figure>
            </div>

            <TopTables base={base} query={query} siteId={siteId} can={can} logs={data?.logs_configured !== false} />
            <div className="grid gap-3 lg:grid-cols-2">
                <Bandwidth base={base} />
                <RequestLookup base={base} />
            </div>
            {data?.can_manage && <Thresholds base={base} />}
        </div>
    );
}

function ShareBar({ title, items, label }: { title: string; items: Share[]; label: (key: string) => string }) {
    return (
        <div className="grid gap-1">
            <p className="text-fg-faint text-xs">{title}</p>
            {items.length === 0 ? (
                <p className="text-fg-muted text-xs">No data (the agent reports it from v0.17).</p>
            ) : (
                <ul className="grid gap-1 text-xs">
                    {items.map((item) => (
                        <li key={item.key} className="flex items-center gap-2">
                            <span className="w-16 shrink-0">{label(item.key)}</span>
                            <span className="bg-surface-3 relative h-1.5 flex-1 overflow-hidden rounded-full">
                                <span className="bg-primary absolute inset-y-0 left-0" style={{ width: `${item.share * 100}%` }} />
                            </span>
                            <span className="text-fg-muted tabular w-10 text-right">{(item.share * 100).toFixed(0)}%</span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function IpActions({ ip, siteId, context, actions }: { ip: string; siteId: string; context: IpActionProps['context']; actions: IpAction[] }) {
    return (
        <span className="flex justify-end gap-1">
            {actions.map(({ id, component: Action }) => (
                <Action key={id} ip={ip} siteId={siteId} serverId={null} context={context} />
            ))}
        </span>
    );
}

function Country({ row }: { row: Row }) {
    const code = typeof row.country === 'string' ? row.country : null;

    return code ? (
        <span title={typeof row.country_name === 'string' ? row.country_name : code}>
            {flag(code)} {code}
        </span>
    ) : (
        <span className="text-fg-faint">—</span>
    );
}

/** One drill-down table at a time, loaded when picked. */
function TopTables({ base, query, siteId, can, logs }: { base: string; query: string; siteId: string; can: (p: string) => boolean; logs: boolean }) {
    const [table, setTable] = useState<Table>('paths');
    const url = logs ? `${base}/http/top${query}${query ? '&' : '?'}table=${table}` : null;
    const { data, error, loading } = useJson<TopData>(url, { unwrap: false });
    const actions = useMemo(() => ipActionsFor({ can }), [can]);
    const rows = data?.rows ?? [];
    const geo = data?.geo;
    const showsGeo = table === 'clients' || table === 'countries' || table === 'threats';

    const columns: { head: string; cell: (row: Row) => ReactNode; right?: boolean }[] = {
        paths: [
            { head: 'Route', cell: (r: Row) => <span className="font-mono">{String(r.route)}</span> },
            { head: 'Requests', cell: (r: Row) => Number(r.requests).toLocaleString(), right: true },
        ],
        slow: [
            { head: 'Route', cell: (r: Row) => <span className="font-mono">{String(r.route)}</span> },
            { head: 'p95', cell: (r: Row) => formatDuration(Number(r.p95_ms)), right: true },
            { head: 'Requests', cell: (r: Row) => Number(r.requests).toLocaleString(), right: true },
        ],
        clients: [
            { head: 'IP', cell: (r: Row) => <span className="font-mono">{String(r.ip)}</span> },
            { head: 'Country', cell: (r: Row) => <Country row={r} /> },
            { head: 'Network', cell: (r: Row) => (r.as_org ? `${String(r.as_org)} (AS${String(r.asn)})` : '—') },
            { head: 'Requests', cell: (r: Row) => Number(r.requests).toLocaleString(), right: true },
            ...(actions.length > 0
                ? [
                      {
                          head: '',
                          cell: (r: Row) => <IpActions ip={String(r.ip)} siteId={siteId} context="http_clients" actions={actions} />,
                          right: true,
                      },
                  ]
                : []),
        ],
        agents: [
            { head: 'User agent', cell: (r: Row) => <span className="font-mono break-all">{String(r.user_agent)}</span> },
            { head: 'Requests', cell: (r: Row) => Number(r.requests).toLocaleString(), right: true },
        ],
        status: [
            { head: 'Status', cell: (r: Row) => String(r.status) },
            { head: 'Requests', cell: (r: Row) => Number(r.requests).toLocaleString(), right: true },
        ],
        countries: [
            { head: 'Country', cell: (r: Row) => <Country row={r} /> },
            { head: 'Clients', cell: (r: Row) => Number(r.clients).toLocaleString(), right: true },
            { head: 'Requests', cell: (r: Row) => Number(r.requests).toLocaleString(), right: true },
        ],
        threats: [
            { head: 'IP', cell: (r: Row) => <span className="font-mono">{String(r.ip)}</span> },
            { head: 'Country', cell: (r: Row) => <Country row={r} /> },
            { head: 'Tried', cell: (r: Row) => describeRules((r.rules as Record<string, number>) ?? {}, Number(r.not_found ?? 0)) },
            { head: 'Requests', cell: (r: Row) => (Number(r.requests) + Number(r.not_found ?? 0)).toLocaleString(), right: true },
            ...(actions.length > 0
                ? [
                      {
                          head: '',
                          cell: (r: Row) => <IpActions ip={String(r.ip)} siteId={siteId} context="http_threats" actions={actions} />,
                          right: true,
                      },
                  ]
                : []),
        ],
    }[table];

    return (
        <figure className="border-border bg-surface-1 grid gap-2 rounded-lg border p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <Segmented label="Table" value={table} onValueChange={setTable} options={[...TABLES]} />
                {table === 'countries' && typeof data?.coverage === 'number' && (
                    <span className="text-fg-muted text-xs">countries of {(data.coverage * 100).toFixed(0)}% of requests (top clients)</span>
                )}
            </div>
            {!logs ? (
                <p className="text-fg-muted text-xs">Configure Loki to see the access log tables.</p>
            ) : error ? (
                <p className="text-danger text-xs">{error}</p>
            ) : loading && data === null ? (
                <Skeleton className="h-32" />
            ) : rows.length === 0 ? (
                <p className="text-fg-muted text-xs">
                    {table === 'countries' && geo && !geo.country ? 'The country database is not downloaded yet.' : 'Nothing in this range.'}
                </p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-xs">
                        <thead className="text-fg-faint">
                            <tr>
                                {columns.map((c, i) => (
                                    <th key={i} className={`py-1 font-medium ${c.right ? 'text-right' : 'text-left'}`}>
                                        {c.head}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="text-fg tabular">
                            {rows.map((row, index) => (
                                <tr key={index} className="border-border border-t">
                                    {columns.map((c, i) => (
                                        <td key={i} className={`py-1.5 pr-2 ${c.right ? 'text-right' : ''}`}>
                                            {c.cell(row)}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            {showsGeo && geo && (
                <p className="text-fg-faint text-[11px]">
                    <a href={geo.attribution_url} target="_blank" rel="noreferrer" className="hover:underline">
                        {geo.attribution}
                    </a>{' '}
                    (CC BY 4.0)
                </p>
            )}
        </figure>
    );
}

function Bandwidth({ base }: { base: string }) {
    const { data } = useJson<{ months: { month: string; bytes_out: number; requests: number }[]; threshold_bytes: number | null }>(
        `${base}/http/bandwidth`,
        {
            unwrap: false,
        },
    );
    const months = data?.months ?? [];

    return (
        <figure className="border-border bg-surface-1 grid content-start gap-2 rounded-lg border p-4">
            <figcaption className="text-fg-muted text-xs font-medium">Bandwidth per month (response bodies)</figcaption>
            {months.length === 0 ? (
                <p className="text-fg-muted text-xs">Nothing recorded yet.</p>
            ) : (
                <ul className="grid gap-1 text-xs">
                    {months.slice(0, 6).map((m) => (
                        <li key={m.month} className="flex justify-between">
                            <span>{m.month}</span>
                            <span className="tabular">
                                {formatBytes(m.bytes_out)} <span className="text-fg-faint">· {m.requests.toLocaleString()} requests</span>
                            </span>
                        </li>
                    ))}
                </ul>
            )}
            {data?.threshold_bytes ? <p className="text-fg-faint text-xs">Alert at {formatBytes(data.threshold_bytes)} a month.</p> : null}
        </figure>
    );
}

interface RequestResult {
    configured: boolean;
    access: { at: string; method: string; path: string; status: number; duration_ms: number | null }[];
    app: LogLineDto[];
    trace_id: string | null;
}

/** "App logs for this request": the access line of an X-Request-Id, the app's records with it, and its trace. */
function RequestLookup({ base }: { base: string }) {
    const [id, setId] = useState('');
    const [result, setResult] = useState<RequestResult | null>(null);
    const [busy, setBusy] = useState(false);
    const [failure, setFailure] = useState<string | null>(null);
    const valid = /^[A-Za-z0-9._:-]{8,128}$/.test(id.trim());

    const search = async () => {
        setBusy(true);
        try {
            setResult(await getJson<RequestResult>(`${base}/requests/${encodeURIComponent(id.trim())}`));
            setFailure(null);
        } catch (caught) {
            setFailure(errorMessage(caught));
        } finally {
            setBusy(false);
        }
    };

    return (
        <figure className="border-border bg-surface-1 grid content-start gap-2 rounded-lg border p-4">
            <figcaption className="text-fg-muted text-xs font-medium">Find a request (X-Request-Id)</figcaption>
            <form
                className="flex gap-1"
                onSubmit={(event) => {
                    event.preventDefault();
                    if (valid) void search();
                }}
            >
                <Input mono aria-label="Request id" placeholder="request id" value={id} onChange={(e) => setId(e.target.value)} className="flex-1" />
                <Button size="sm" type="submit" icon={<Search />} loading={busy} disabled={!valid}>
                    Find
                </Button>
            </form>
            {failure && <p className="text-danger text-xs">{failure}</p>}
            {result && (
                <div className="grid gap-1 text-xs">
                    {result.access.length === 0 ? (
                        <p className="text-fg-muted">No access line with this id.</p>
                    ) : (
                        result.access.map((a, i) => (
                            <p key={i} className="font-mono">
                                {format(new Date(a.at), 'PPpp')} · {a.method} {a.path} {a.status}
                                {a.duration_ms !== null ? ` · ${formatDuration(a.duration_ms)}` : ''}
                            </p>
                        ))
                    )}
                    <p className="text-fg-muted">
                        {result.app.length} app log line{result.app.length === 1 ? '' : 's'} mention it
                        {result.trace_id && (
                            <>
                                {' · '}
                                <a className="underline-offset-2 hover:underline" href={`/observability/traces/${result.trace_id}`}>
                                    open the trace
                                </a>
                            </>
                        )}
                    </p>
                    {result.app.slice(0, 20).map((line) => (
                        <p key={line.ts} className="text-fg font-mono break-all">
                            {line.line}
                        </p>
                    ))}
                </div>
            )}
        </figure>
    );
}

interface Settings {
    slow_ms: number;
    slow_min_requests: number;
    bandwidth_alert_bytes: number | null;
}

const GB = 1024 ** 3;

/** Per-site thresholds (sites.manage). */
function Thresholds({ base }: { base: string }) {
    const { data, reload } = useJson<Settings>(`${base}/http/settings`, { unwrap: false });
    const [form, setForm] = useState({ slow_ms: '', slow_min_requests: '', bandwidth_gb: '' });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (!data) return;
        setForm({
            slow_ms: String(data.slow_ms),
            slow_min_requests: String(data.slow_min_requests),
            bandwidth_gb: data.bandwidth_alert_bytes ? String(+(data.bandwidth_alert_bytes / GB).toFixed(2)) : '',
        });
    }, [data]);

    const save = async () => {
        setSaving(true);
        try {
            await requestJson(`${base}/http/settings`, 'PUT', {
                slow_ms: form.slow_ms ? Number(form.slow_ms) : null,
                slow_min_requests: form.slow_min_requests ? Number(form.slow_min_requests) : null,
                bandwidth_alert_bytes: form.bandwidth_gb ? Math.round(Number(form.bandwidth_gb) * GB) : null,
            });
            setErrors({});
            toast.success('HTTP thresholds saved');
            void reload();
        } catch (caught) {
            setErrors((caught as { errors?: Record<string, string> }).errors ?? {});
            toast.error('Could not save the thresholds', errorMessage(caught));
        } finally {
            setSaving(false);
        }
    };

    return (
        <figure className="border-border bg-surface-1 grid gap-3 rounded-lg border p-4">
            <figcaption className="text-fg-muted text-xs font-medium">Thresholds</figcaption>
            <div className="grid gap-3 sm:grid-cols-3">
                <Field label="Slow endpoint (p95, ms)" error={errors.slow_ms}>
                    <Input type="number" min={100} value={form.slow_ms} onChange={(e) => setForm({ ...form, slow_ms: e.target.value })} />
                </Field>
                <Field label="Minimum requests an hour" error={errors.slow_min_requests}>
                    <Input
                        type="number"
                        min={1}
                        value={form.slow_min_requests}
                        onChange={(e) => setForm({ ...form, slow_min_requests: e.target.value })}
                    />
                </Field>
                <Field label="Bandwidth alert (GB a month)" hint="Empty: no alert" error={errors.bandwidth_alert_bytes}>
                    <Input
                        type="number"
                        min={0}
                        step="any"
                        value={form.bandwidth_gb}
                        onChange={(e) => setForm({ ...form, bandwidth_gb: e.target.value })}
                    />
                </Field>
            </div>
            <div>
                <Button size="sm" variant="primary" loading={saving} onClick={() => void save()}>
                    Save
                </Button>
            </div>
        </figure>
    );
}
