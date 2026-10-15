import { Callout, IconButton, MetricChart, Segmented, SkeletonRows, type MetricSeries } from '@/components/falak';
import { useJson } from '@/hooks/use-json';
import { type ServiceTabProps } from '@/lib/registry';
import { format } from 'date-fns';
import { RotateCw } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    CONNECTION_STATES,
    combine,
    formatMs,
    formatRate,
    hasValues,
    latest,
    mergePoints,
    splitBy,
    type DatabaseMetricsResponse,
} from '../observability';
import { formatBytes, useDatabasePanel } from './api';

const RANGES = ['1h', '6h', '24h', '7d'] as const;
type Range = (typeof RANGES)[number];

const REST = 'var(--text-faint)';
const LIMIT = 'var(--danger)';
const CAPACITY = 'var(--text-muted)';

const bytes = (value: number) => formatBytes(value);
const bytesPerSecond = (value: number) => `${formatBytes(value)}/s`;
const cores = (value: number) => (value >= 10 ? value.toFixed(0) : value >= 1 ? value.toFixed(1) : value.toFixed(2));
const percent = (value: number) => `${value.toFixed(1)}%`;

/**
 * Database panel → Metrics (docs/plans/OBSERVABILITY.md phase 3): the engine's own figures from `falak-db observe`
 * and the container's CPU and memory against the server and the instance's limits. Refreshes every 30s.
 */
export function DatabaseMetricsTab({ ctx }: ServiceTabProps) {
    const panel = useDatabasePanel(ctx);
    const [range, setRange] = useState<Range>('1h');
    const instanceId = panel.data?.instance.id;
    const { data, error, loading, reload } = useJson<DatabaseMetricsResponse>(
        instanceId ? `/databases/instances/${instanceId}/metrics/data?range=${range}` : null,
        {
            interval: 30_000,
            unwrap: false,
        },
    );
    const charts = useMemo(() => data?.charts ?? {}, [data]);
    const engine = panel.data?.instance.engine;
    const keyValue = engine === 'redis' || engine === 'valkey';
    const timeFormat = (value: number | string) => format(new Date(Number(value) * 1000), range === '7d' ? 'MMM d HH:mm' : 'HH:mm');

    const view = useMemo(() => {
        const connections = splitBy(charts.connections, 'falak_db_connection_state', 5);
        const memory = combine({
            used: charts.memory?.filter((s) => s.labels.falak_memory_type === 'used'),
            cache: charts.memory?.filter((s) => s.labels.falak_memory_type === 'cache'),
            rest: charts.memory_rest,
            limit: charts.memory_limit,
            server: charts.server_memory,
        });

        return {
            connections,
            connectionsData: mergePoints(connections.points, combine({ max: charts.connections_max }, { max: 'max' })),
            throughput: keyValue
                ? combine({ ops: charts.ops })
                : engine === 'postgresql'
                  ? splitBy(charts.transactions, 'falak_db_transaction_outcome').points
                  : combine({ queries: charts.queries }),
            cacheHit: combine({ value: charts.cache_hit }),
            slow: combine({ value: charts.slow_queries }),
            cpu: combine({ db: charts.cpu, rest: charts.cpu_rest, limit: charts.cpu_limit, server: charts.server_cores }, { limit: 'max' }),
            memory,
            sizes: splitBy(keyValue ? charts.keys : charts.sizes, 'falak_db_name', 5),
            rows: splitBy(charts.rows, 'falak_db_row_operation', 5),
            locks: combine({ deadlocks: charts.deadlocks, waiting: charts.locks_waiting ?? charts.lock_waits }),
            lag: combine({ value: charts.replication_lag }),
            kvMemory: combine({ used: charts.used_memory, max: charts.maxmemory }, { max: 'max' }),
            evictions: combine({ evicted: charts.evicted, expired: charts.expired }),
            network: splitBy(charts.network, 'network_io_direction'),
        };
    }, [charts, keyValue, engine]);

    if (!panel.data) return panel.error ? <p className="text-danger text-sm">{panel.error}</p> : <SkeletonRows rows={6} />;

    const initial = loading && data === null;
    const empty = (...keys: string[]) =>
        keys.some((key) => hasValues(charts[key])) ? undefined : (data?.errors[keys[0]] ?? 'No data in this range');
    const connectionSeries: MetricSeries[] = [
        ...view.connections.keys.map((key) => ({ key, label: CONNECTION_STATES[key] ?? key, mark: 'area' as const, stack: 'c' })),
        { key: 'max', label: 'Limit', color: LIMIT, reference: true },
    ];

    return (
        <div className="grid min-w-0 gap-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-fg-muted text-xs">Engine figures every minute · refreshes every 30s</p>
                <div className="flex items-center gap-1">
                    <Segmented label="Time range" value={range} onValueChange={setRange} options={RANGES.map((value) => ({ value, label: value }))} />
                    <IconButton size="sm" label="Refresh" icon={<RotateCw />} onClick={() => void reload()} loading={loading && data !== null} />
                </div>
            </div>
            {error && data === null && (
                <Callout tone="danger" title="Metrics are not available">
                    {error}
                </Callout>
            )}
            {data && !hasValues(charts.up) && (
                <Callout tone="info" title="No engine figures yet">
                    The container's CPU and memory are shown below. Engine figures need Falak v0.16.0 on the server and an image built with it
                    (Settings → Upgrade to the same version).
                </Callout>
            )}
            <div className="grid gap-3 lg:grid-cols-2">
                <MetricChart
                    title={keyValue ? 'Operations / s' : engine === 'postgresql' ? 'Transactions / s' : 'Queries / s'}
                    type={engine === 'postgresql' ? 'area' : 'line'}
                    data={view.throughput}
                    series={
                        engine === 'postgresql'
                            ? [
                                  { key: 'commit', label: 'Commits' },
                                  { key: 'rollback', label: 'Rollbacks', color: 'var(--danger)' },
                              ]
                            : [{ key: keyValue ? 'ops' : 'queries', label: keyValue ? 'Operations' : 'Queries' }]
                    }
                    format={formatRate}
                    loading={initial}
                    timeFormat={timeFormat}
                    emptyText={empty(keyValue ? 'ops' : engine === 'postgresql' ? 'transactions' : 'queries')}
                />
                <MetricChart
                    title="Cache hit ratio"
                    data={view.cacheHit}
                    series={[{ key: 'value', label: 'Hits' }]}
                    value={latest(view.cacheHit) !== null ? percent(latest(view.cacheHit)!) : undefined}
                    format={percent}
                    loading={initial}
                    timeFormat={timeFormat}
                    emptyText={empty('cache_hit')}
                />
                <MetricChart
                    title="Connections"
                    type="composed"
                    data={view.connectionsData}
                    series={connectionSeries}
                    format={(value) => value.toFixed(0)}
                    loading={initial}
                    timeFormat={timeFormat}
                    emptyText={empty('connections')}
                />
                <MetricChart
                    title="Slow queries / min"
                    type="bar"
                    data={view.slow}
                    series={[{ key: 'value', label: 'Slow queries', color: 'var(--warning)' }]}
                    format={formatRate}
                    loading={initial}
                    timeFormat={timeFormat}
                    emptyText={empty('slow_queries')}
                />
                <MetricChart
                    title="CPU (cores)"
                    type="composed"
                    data={view.cpu}
                    series={[
                        { key: 'db', label: 'This database', mark: 'area', stack: 'cpu' },
                        { key: 'rest', label: 'Rest of the server', mark: 'area', stack: 'cpu', color: REST },
                        { key: 'limit', label: 'Limit', color: LIMIT, reference: true },
                        { key: 'server', label: 'Server cores', color: CAPACITY, reference: true },
                    ]}
                    value={latest(view.cpu, 'db') !== null ? cores(latest(view.cpu, 'db')!) : undefined}
                    format={cores}
                    loading={initial}
                    timeFormat={timeFormat}
                    emptyText={empty('cpu')}
                />
                <MetricChart
                    title="Memory"
                    type="composed"
                    data={view.memory}
                    series={[
                        { key: 'used', label: 'Used', mark: 'area', stack: 'mem' },
                        { key: 'rest', label: 'Rest of the server', mark: 'area', stack: 'mem', color: REST },
                        { key: 'cache', label: 'Page cache', dashed: true },
                        { key: 'limit', label: 'Limit', color: LIMIT, reference: true },
                        { key: 'server', label: 'Server memory', color: CAPACITY, reference: true },
                    ]}
                    value={latest(view.memory, 'used') !== null ? bytes(latest(view.memory, 'used')!) : undefined}
                    format={bytes}
                    loading={initial}
                    timeFormat={timeFormat}
                    emptyText={empty('memory')}
                />
                {keyValue ? (
                    <>
                        <MetricChart
                            title="Memory against maxmemory"
                            data={view.kvMemory}
                            series={[
                                { key: 'used', label: 'used_memory' },
                                { key: 'max', label: 'maxmemory', color: LIMIT, reference: true },
                            ]}
                            format={bytes}
                            loading={initial}
                            timeFormat={timeFormat}
                            emptyText={empty('used_memory')}
                        />
                        <MetricChart
                            title="Keys evicted / expired per second"
                            data={view.evictions}
                            series={[
                                { key: 'evicted', label: 'Evicted', color: 'var(--danger)' },
                                { key: 'expired', label: 'Expired' },
                            ]}
                            format={formatRate}
                            loading={initial}
                            timeFormat={timeFormat}
                            emptyText={empty('evicted', 'expired')}
                        />
                    </>
                ) : (
                    <>
                        <MetricChart
                            title="Rows / s"
                            data={view.rows.points}
                            series={view.rows.keys.map((key) => ({ key, label: key }))}
                            format={formatRate}
                            loading={initial}
                            timeFormat={timeFormat}
                            emptyText={empty('rows')}
                        />
                        <MetricChart
                            title={engine === 'postgresql' ? 'Deadlocks and waiting locks' : 'Deadlocks and lock waits / s'}
                            data={view.locks}
                            series={[
                                { key: 'deadlocks', label: 'Deadlocks', color: 'var(--danger)' },
                                { key: 'waiting', label: engine === 'postgresql' ? 'Waiting locks' : 'Lock waits / s' },
                            ]}
                            format={formatRate}
                            loading={initial}
                            timeFormat={timeFormat}
                            emptyText={empty('deadlocks', 'locks_waiting', 'lock_waits')}
                        />
                        {hasValues(charts.replication_lag) && (
                            <MetricChart
                                title="Replication lag"
                                data={view.lag}
                                series={[{ key: 'value', label: 'Lag' }]}
                                format={(value) => formatMs(value * 1000)}
                                loading={initial}
                                timeFormat={timeFormat}
                            />
                        )}
                    </>
                )}
                <MetricChart
                    title={keyValue ? 'Keys' : 'Database sizes'}
                    type="area"
                    data={view.sizes.points}
                    series={view.sizes.keys.map((key) => ({ key, label: key, mark: 'area' as const, stack: 's' }))}
                    format={keyValue ? (value) => value.toFixed(0) : bytes}
                    loading={initial}
                    timeFormat={timeFormat}
                    emptyText={empty(keyValue ? 'keys' : 'sizes')}
                />
                {hasValues(charts.network) && (
                    <MetricChart
                        title="Client traffic"
                        data={view.network.points}
                        series={view.network.keys.map((key) => ({ key, label: key === 'receive' ? 'Received' : key === 'transmit' ? 'Sent' : key }))}
                        format={bytesPerSecond}
                        loading={initial}
                        timeFormat={timeFormat}
                    />
                )}
            </div>
        </div>
    );
}
