import { IconButton } from '@/components/falak/button';
import { EmptyState } from '@/components/falak/empty-state';
import { MetricChart, type MetricSeries } from '@/components/falak/metric-chart';
import { Segmented } from '@/components/falak/segmented';
import { Select } from '@/components/falak/select';
import { Skeleton } from '@/components/falak/skeleton';
import { Tag } from '@/components/falak/tag';
import { format } from 'date-fns';
import { RotateCw, TriangleAlert } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react';
import { BackendError, NotConfigured } from '../components/backend-state';
import { useSiteTelemetryContext } from '../components/site-context';
import { formatBytes, formatDuration, getJson, queryString } from '../lib';
import {
    CAPACITY,
    combine,
    formatCores,
    hasValues,
    latest,
    LIMIT,
    memorySeries,
    oomKills,
    peak,
    REST,
    serviceRows,
    SITE,
    unmeasuredNote,
    withLabel,
    type ServiceRow,
} from '../site-metrics';
import { type MetricSeriesDto } from '../types';

type ChartKey =
    | 'requests'
    | 'errors'
    | 'p95'
    | 'cpu'
    | 'memory'
    | 'site_cpu'
    | 'site_cpu_rest'
    | 'site_cpu_limit'
    | 'server_cores'
    | 'site_cpu_throttled'
    | 'site_memory'
    | 'site_memory_rest'
    | 'site_memory_limit'
    | 'server_memory'
    | 'site_oom_kills'
    | 'site_disk_io'
    | 'site_network'
    | 'server_network'
    | 'site_pids'
    | 'site_pids_limit'
    | 'services_cpu'
    | 'services_cpu_limit'
    | 'services_memory'
    | 'services_memory_limit';

/** GET /telemetry/sites/{siteId}/metrics/data */
interface SiteMetricsResponse {
    range: string;
    step: number;
    server: string | null;
    servers: { id: string; name: string }[];
    runtime: string;
    containers: boolean;
    /** A native site running as the unix user it shares with other sites: not measurable per user. */
    shared_user?: boolean;
    /** Where requests / 5xx / p95 come from: span metrics ("app") or the edge access log ("edge"). */
    traffic_source: 'app' | 'edge';
    charts: Partial<Record<ChartKey, MetricSeriesDto[]>>;
    errors: Record<string, string>;
}

const RANGES = ['1h', '6h', '24h', '7d'] as const;
type Range = (typeof RANGES)[number];
const REFRESH_MS = 30_000;
const ALL_SERVERS = 'all';

const rate = (value: number) => (value >= 100 ? value.toFixed(0) : value >= 1 ? value.toFixed(1) : value.toFixed(2));
const bytesPerSecond = (value: number) => `${formatBytes(value)}/s`;

export interface SiteMetricsProps {
    siteId: string;
}

/**
 * Service panel → Metrics tab (docs/UI_DESIGN.md §5.1, docs/plans/OBSERVABILITY.md "The Metrics tab"): the site's
 * traffic (span metrics, else its edge access log), and its workload — CPU, memory, disk, network, processes and each
 * compose service — against its limits and its servers. Refreshes every 30s.
 */
export default function SiteMetrics({ siteId }: SiteMetricsProps) {
    const [context, retryContext] = useSiteTelemetryContext(siteId);
    const [range, setRange] = useState<Range>('1h');
    const [server, setServer] = useState<string>(ALL_SERVERS);
    const [data, setData] = useState<SiteMetricsResponse | null>(null);
    const [error, setError] = useState<unknown>(null);
    const [loading, setLoading] = useState(true);
    const configured = context.status === 'ready' && context.data.configured.metrics;

    const load = useCallback(
        async (signal?: AbortSignal) => {
            setLoading(true);
            try {
                const query = queryString({ range, server: server === ALL_SERVERS ? null : server });
                const body = await getJson<SiteMetricsResponse>(`/telemetry/sites/${encodeURIComponent(siteId)}/metrics/data${query}`, signal);
                setData(body);
                setError(null);
            } catch (caught) {
                if (!signal?.aborted) setError(caught);
            } finally {
                if (!signal?.aborted) setLoading(false);
            }
        },
        [siteId, range, server],
    );

    useEffect(() => {
        if (!configured) return;
        const controller = new AbortController();
        void load(controller.signal);
        const timer = window.setInterval(() => void load(), REFRESH_MS);

        return () => {
            controller.abort();
            window.clearInterval(timer);
        };
    }, [configured, load]);

    const servers = useMemo(() => data?.servers ?? (context.status === 'ready' ? context.data.servers : []), [data, context]);
    const serverNames = useMemo(() => new Map(servers.map((item) => [item.id.toLowerCase(), item.name])), [servers]);
    const charts = data?.charts ?? {};
    const several = servers.length > 1 && server === ALL_SERVERS;

    const requests = useMemo(() => combine({ value: data?.charts.requests }), [data]);
    const errors = useMemo(() => combine({ value: data?.charts.errors }), [data]);
    const p95 = useMemo(() => combine({ value: data?.charts.p95 }, { value: 'max' }), [data]);
    const cpu = useMemo(
        () =>
            combine(
                {
                    site: data?.charts.site_cpu,
                    rest: data?.charts.site_cpu_rest,
                    limit: data?.charts.site_cpu_limit,
                    capacity: data?.charts.server_cores,
                },
                { limit: 'max' },
            ),
        [data],
    );
    const throttled = useMemo(() => combine({ value: data?.charts.site_cpu_throttled }), [data]);
    const memory = useMemo(
        () =>
            combine(
                {
                    used: withLabel(data?.charts.site_memory, 'falak_memory_type', 'used'),
                    cache: withLabel(data?.charts.site_memory, 'falak_memory_type', 'cache'),
                    rest: data?.charts.site_memory_rest,
                    limit: data?.charts.site_memory_limit,
                    capacity: data?.charts.server_memory,
                },
                { limit: 'max' },
            ),
        [data],
    );
    const oom = useMemo(() => oomKills(data?.charts.site_oom_kills), [data]);
    const diskIo = useMemo(
        () =>
            combine({
                read: withLabel(data?.charts.site_disk_io, 'disk_io_direction', 'read'),
                write: withLabel(data?.charts.site_disk_io, 'disk_io_direction', 'write'),
            }),
        [data],
    );
    const networkSeries = data?.containers ? data.charts.site_network : data?.charts.server_network;
    const network = useMemo(
        () =>
            combine({
                receive: withLabel(networkSeries, 'network_io_direction', 'receive'),
                transmit: withLabel(networkSeries, 'network_io_direction', 'transmit'),
            }),
        [networkSeries],
    );
    const pids = useMemo(() => combine({ pids: data?.charts.site_pids, limit: data?.charts.site_pids_limit }, { limit: 'max' }), [data]);
    const services = useMemo(() => serviceRows(data?.charts ?? {}), [data]);

    if (context.status === 'loading') {
        return (
            <div className="grid gap-3 sm:grid-cols-2">
                {Array.from({ length: 4 }, (_, index) => (
                    <Skeleton key={index} className="h-56" />
                ))}
            </div>
        );
    }
    if (context.status === 'error') return <BackendError size="sm" backend="metrics backend" error={context.error} onRetry={retryContext} />;
    if (!configured) return <NotConfigured size="sm" backend="metrics backend" />;

    const initial = loading && data === null;
    const timeFormat = (value: number | string) =>
        format(new Date(typeof value === 'number' ? value * 1000 : value), range === '7d' ? 'MMM d' : 'HH:mm');
    const empty = (...keys: string[]) => {
        const message = keys.map((key) => data?.errors[key]).find(Boolean);

        return message ? (
            <span className="text-danger flex items-center gap-1.5 text-xs">
                <TriangleAlert className="size-3.5" /> {message}
            </span>
        ) : (
            'No data for this range.'
        );
    };
    const measured = hasValues(charts.site_cpu) || hasValues(charts.site_memory);
    const restLabel = measured ? 'Rest of the server' : 'Server';
    const limitLabel = several ? 'Limit (per server)' : 'Limit';
    const capacityLabel = several ? 'Servers' : 'Server';

    const cpuSeries: MetricSeries[] = [
        { key: 'site', label: 'Site', mark: 'area', stack: 'cpu', color: SITE },
        { key: 'rest', label: restLabel, mark: 'area', stack: 'cpu', color: REST },
        { key: 'limit', label: limitLabel, color: LIMIT, dashed: true },
        { key: 'capacity', label: several ? 'Servers’ cores' : 'Server cores', color: CAPACITY, dashed: true },
    ];
    const memoryChartSeries = memorySeries({ rest: restLabel, limit: limitLabel, capacity: `${capacityLabel} memory` });
    const note = data ? unmeasuredNote(measured, data.shared_user === true) : null;
    const throttledPeak = peak(throttled);
    const siteCores = latest(cpu, 'site');
    const siteMemory = latest(memory, 'used');
    const sourceTag =
        data?.traffic_source === 'edge' ? (
            <Tag tone="faint" title="Counted from the edge access log (the site sends no traces)">
                from the edge
            </Tag>
        ) : data ? (
            <Tag tone="faint" title="From the site's traces (span metrics)">
                from the app
            </Tag>
        ) : null;

    return (
        <div className="grid min-w-0 gap-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-fg-muted text-xs">
                    {servers.length === 0 ? 'No servers' : `${servers.length} server${servers.length === 1 ? '' : 's'}`} · refreshes every 30s
                </p>
                <div className="flex flex-wrap items-center gap-1">
                    {servers.length > 1 && (
                        <Select
                            size="sm"
                            aria-label="Server"
                            className="w-40"
                            value={server}
                            onValueChange={setServer}
                            options={[
                                { value: ALL_SERVERS, label: 'All servers' },
                                ...servers.map((item) => ({ value: item.id.toLowerCase(), label: item.name })),
                            ]}
                        />
                    )}
                    <Segmented label="Time range" value={range} onValueChange={setRange} options={RANGES.map((value) => ({ value, label: value }))} />
                    <IconButton size="sm" label="Refresh" icon={<RotateCw />} onClick={() => void load()} loading={loading && data !== null} />
                </div>
            </div>
            {error !== null && data === null ? (
                <BackendError size="sm" backend="metrics backend" error={error} onRetry={() => void load()} />
            ) : (
                <>
                    <div className="grid gap-3 lg:grid-cols-2">
                        <MetricChart
                            title="Requests / s"
                            type="area"
                            data={requests}
                            series={[{ key: 'value', label: 'Requests / s' }]}
                            value={latest(requests) !== null ? rate(latest(requests)!) : undefined}
                            actions={sourceTag}
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
                            title="5xx errors / s"
                            type="bar"
                            data={errors}
                            series={[{ key: 'value', label: '5xx / s' }]}
                            value={latest(errors) !== null ? rate(latest(errors)!) : undefined}
                            format={rate}
                            loading={initial}
                            timeFormat={timeFormat}
                            emptyText={empty('errors')}
                        />
                    </div>
                    {servers.length === 0 ? (
                        <EmptyState size="sm" title="No servers" description="Add this site to a server to see its CPU, memory and processes." />
                    ) : (
                        <>
                            {note && <p className="text-fg-muted text-xs">{note}</p>}
                            <div className="grid gap-3 lg:grid-cols-2">
                                <MetricChart
                                    title="CPU (cores)"
                                    type="composed"
                                    data={cpu}
                                    series={cpuSeries}
                                    value={siteCores !== null ? formatCores(siteCores) : undefined}
                                    actions={
                                        throttledPeak !== null && throttledPeak >= 1 ? (
                                            <Tag tone="warning" title="Largest share of CPU periods in which the site hit its CPU limit">
                                                throttled {throttledPeak.toFixed(0)}%
                                            </Tag>
                                        ) : undefined
                                    }
                                    format={formatCores}
                                    loading={initial}
                                    timeFormat={timeFormat}
                                    emptyText={empty('site_cpu', 'site_cpu_rest')}
                                />
                                <MetricChart
                                    title="Memory"
                                    type="composed"
                                    data={memory}
                                    series={memoryChartSeries}
                                    markers={oom.markers}
                                    value={siteMemory !== null ? formatBytes(siteMemory) : undefined}
                                    actions={
                                        oom.total > 0 ? (
                                            <Tag tone="danger" title="Processes of the site killed for running out of memory">
                                                {oom.total} OOM kill{oom.total === 1 ? '' : 's'}
                                            </Tag>
                                        ) : undefined
                                    }
                                    format={formatBytes}
                                    loading={initial}
                                    timeFormat={timeFormat}
                                    emptyText={empty('site_memory', 'site_memory_rest')}
                                />
                                <MetricChart
                                    title="Disk I/O"
                                    data={diskIo}
                                    series={[
                                        { key: 'read', label: 'Read' },
                                        { key: 'write', label: 'Write' },
                                    ]}
                                    format={bytesPerSecond}
                                    loading={initial}
                                    timeFormat={timeFormat}
                                    emptyText={empty('site_disk_io')}
                                />
                                <MetricChart
                                    title={data?.containers === false ? 'Network (the server’s)' : 'Network'}
                                    data={network}
                                    series={[
                                        { key: 'receive', label: 'Receive' },
                                        { key: 'transmit', label: 'Transmit' },
                                    ]}
                                    actions={
                                        data?.containers === false ? (
                                            <Tag tone="faint" title="A native site's own traffic cannot be told apart from the server's yet">
                                                whole server
                                            </Tag>
                                        ) : undefined
                                    }
                                    format={bytesPerSecond}
                                    loading={initial}
                                    timeFormat={timeFormat}
                                    emptyText={empty('site_network', 'server_network')}
                                />
                                <MetricChart
                                    title="Processes"
                                    type="composed"
                                    data={pids}
                                    series={[
                                        { key: 'pids', label: 'Processes', color: SITE },
                                        { key: 'limit', label: limitLabel, color: LIMIT, dashed: true },
                                    ]}
                                    value={latest(pids, 'pids') ?? undefined}
                                    format={(value) => value.toFixed(0)}
                                    loading={initial}
                                    timeFormat={timeFormat}
                                    emptyText={empty('site_pids')}
                                />
                            </div>
                            {(data?.containers || services.length > 1) && services.length > 0 && (
                                <ServiceTable
                                    rows={services}
                                    serverName={servers.length > 1 ? (id) => serverNames.get(id ?? '') ?? id ?? '—' : null}
                                />
                            )}
                        </>
                    )}
                </>
            )}
        </div>
    );
}

/** Per service: one row per compose service / container (and server), its latest CPU and memory against its limits. */
function ServiceTable({ rows, serverName }: { rows: ServiceRow[]; serverName: ((id: string | null) => string) | null }) {
    return (
        <figure className="border-border bg-surface-1 grid gap-2 rounded-lg border p-4">
            <figcaption className="text-fg-muted text-xs font-medium">Per service</figcaption>
            <div className="overflow-x-auto">
                <table className="w-full text-xs">
                    <thead className="text-fg-faint">
                        <tr>
                            <th className="py-1 text-left font-medium">Service</th>
                            {serverName && <th className="py-1 text-left font-medium">Server</th>}
                            <th className="py-1 text-right font-medium">CPU (cores)</th>
                            <th className="py-1 pl-4 text-left font-medium">Memory</th>
                        </tr>
                    </thead>
                    <tbody className="text-fg tabular">
                        {rows.map((row) => (
                            <tr key={row.key} className="border-border border-t">
                                <td className="py-1.5 font-mono">{row.name}</td>
                                {serverName && <td className="text-fg-muted py-1.5">{serverName(row.serverId)}</td>}
                                <td className="py-1.5 text-right">
                                    {row.cpu !== null ? formatCores(row.cpu) : '—'}
                                    {row.cpuLimit !== null && <span className="text-fg-faint"> / {formatCores(row.cpuLimit)}</span>}
                                </td>
                                <td className="py-1.5 pl-4">
                                    <MemoryCell used={row.memory} limit={row.memoryLimit} />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </figure>
    );
}

function MemoryCell({ used, limit }: { used: number | null; limit: number | null }): ReactNode {
    if (used === null) return '—';
    if (limit === null || limit <= 0) return formatBytes(used);
    const share = Math.min(1, used / limit);

    return (
        <span className="flex items-center gap-2">
            <span
                className="bg-surface-3 relative h-1.5 w-16 overflow-hidden rounded-full"
                role="meter"
                aria-valuemin={0}
                aria-valuemax={limit}
                aria-valuenow={used}
                aria-label="Memory against its limit"
            >
                <span
                    className={
                        share >= 0.9
                            ? 'bg-danger absolute inset-y-0 left-0'
                            : share >= 0.75
                              ? 'bg-warning absolute inset-y-0 left-0'
                              : 'bg-primary absolute inset-y-0 left-0'
                    }
                    style={{ width: `${share * 100}%` }}
                />
            </span>
            <span>
                {formatBytes(used)} <span className="text-fg-faint">/ {formatBytes(limit)}</span>
            </span>
        </span>
    );
}
