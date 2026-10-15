import { Button, Callout, DataTable, MetricChart, Section, Switch, toast, type DataTableColumn } from '@/components/falak';
import { useJson } from '@/hooks/use-json';
import { errorMessage } from '@/lib/http';
import { format } from 'date-fns';
import { useMemo, useState } from 'react';
import { bucketLabel, latest, pointsBy } from '../connections';
import { formatRate, formatSeconds, mergeCharts, serverNotice, setEbpf, type EbpfResponse, type EbpfServer, type EbpfSettings } from '../ebpf';

export interface EbpfViewProps {
    /** Endpoint without the query string, e.g. /network/servers/{id}/ebpf. */
    url: string;
    scope: 'server' | 'site' | 'database';
    range: string;
    service?: string;
}

type NameRow = EbpfResponse['names'][number];

const NAME_COLUMNS: DataTableColumn<NameRow>[] = [
    { id: 'name', header: 'Name', cell: (row) => <span className="font-mono text-xs">{row.name}</span>, sortValue: (row) => row.name },
    { id: 'queries', header: 'Queries', align: 'right', cell: (row) => row.queries.toLocaleString(), sortValue: (row) => row.queries },
];

/**
 * The TCP & DNS section of the Network tabs (docs/plans/network/ebpf.md, "UI"): the eBPF probe's status (and, on a
 * server, its switch), DNS queries, failures, latency and top names, TCP retransmits, resets, RTT and connect latency,
 * run-queue delay. Refreshes every 30 s with the connections above it.
 */
export function EbpfView({ url, scope, range, service }: EbpfViewProps) {
    const query = new URLSearchParams({ range, ...(service ? { service } : {}) }).toString();
    const { data, error, loading, reload } = useJson<EbpfResponse>(`${url}?${query}`, { interval: 30_000, unwrap: false });
    const charts = useMemo(() => data?.charts ?? {}, [data]);
    const timeFormat = (value: number | string) => format(new Date(Number(value) * 1000), range === '7d' ? 'MMM d HH:mm' : 'HH:mm');
    const initial = loading && data === null;
    const empty = (key: string) => (charts[key]?.length ? undefined : (data?.errors[key] ?? data?.errors.metrics ?? 'No data in this range'));
    const view = useMemo(
        () => ({
            queries: pointsBy(charts.dns_queries),
            failures: pointsBy(charts.dns_failures, 'falak_net_dns_error'),
            dnsLatency: mergeCharts({ p50: charts.dns_p50, p95: charts.dns_p95 }),
            retransmits: pointsBy(charts.retransmits, 'falak_net_peer'),
            resets: pointsBy(charts.resets, 'falak_net_reset_direction'),
            tcpLatency: mergeCharts({ rtt50: charts.rtt_p50, rtt95: charts.rtt_p95, connect95: charts.connect_p95 }),
            connectFailed: pointsBy(charts.connect_failed),
            runq: mergeCharts({ p50: charts.runq_p50, p95: charts.runq_p95, p99: charts.runq_p99 }),
            overhead: pointsBy(charts.overhead, 'falak_ebpf_component'),
        }),
        [charts],
    );
    const servers = data?.servers ?? [];
    const on = servers.some((server) => server.enabled || ['running', 'degraded'].includes(server.probe?.state ?? 'off'));
    const notices = servers.map((server) => ({ server, notice: serverNotice(server) })).filter((item) => item.notice !== null);
    const lastFailed = latest(view.connectFailed.points);

    return (
        <Section
            title="TCP & DNS"
            description="From the eBPF probe: DNS lookups, TCP health and how long threads wait for a CPU, per workload."
            aside={scope === 'server' && servers[0] ? <EbpfSwitch server={servers[0]} onChanged={() => void reload()} /> : undefined}
        >
            <div className="grid min-w-0 gap-3">
                {error && data === null && (
                    <Callout tone="danger" title="eBPF insights are not available">
                        {error}
                    </Callout>
                )}
                {notices.map(({ server, notice }) => (
                    <Callout
                        key={server.server_id}
                        tone={notice!.tone}
                        title={notice!.title}
                        action={
                            server.probe?.state === 'over_budget' && server.enabled && server.can_manage ? (
                                <ReenableButton server={server} onChanged={() => void reload()} />
                            ) : undefined
                        }
                    >
                        {notice!.body}
                    </Callout>
                ))}
                {on && (
                    <>
                        <div className="grid gap-3 lg:grid-cols-2">
                            <MetricChart
                                title="DNS queries"
                                data={view.queries.points}
                                series={[{ key: 'value', label: 'Queries/s' }]}
                                value={latest(view.queries.points) !== null ? formatRate(latest(view.queries.points)!) : undefined}
                                format={formatRate}
                                loading={initial}
                                timeFormat={timeFormat}
                                emptyText={empty('dns_queries')}
                            />
                            <MetricChart
                                title="DNS failures"
                                type="bar"
                                data={view.failures.points}
                                series={view.failures.keys.slice(0, 5).map((key) => ({ key, label: key === 'nxdomain' ? 'NXDOMAIN' : key }))}
                                format={formatRate}
                                loading={initial}
                                timeFormat={timeFormat}
                                emptyText={empty('dns_failures')}
                            />
                            <MetricChart
                                title="DNS latency"
                                data={view.dnsLatency}
                                series={[
                                    { key: 'p50', label: 'p50' },
                                    { key: 'p95', label: 'p95' },
                                ]}
                                format={formatSeconds}
                                loading={initial}
                                timeFormat={timeFormat}
                                emptyText={empty('dns_p95')}
                            />
                            <MetricChart
                                title="TCP retransmits"
                                data={view.retransmits.points}
                                series={view.retransmits.keys.slice(0, 5).map((key) => ({ key, label: bucketLabel(key) }))}
                                format={formatRate}
                                loading={initial}
                                timeFormat={timeFormat}
                                emptyText={empty('retransmits')}
                            />
                            <MetricChart
                                title="Round trip and connect time"
                                data={view.tcpLatency}
                                series={[
                                    { key: 'rtt50', label: 'RTT p50' },
                                    { key: 'rtt95', label: 'RTT p95' },
                                    { key: 'connect95', label: 'Connect p95', dashed: true },
                                ]}
                                value={lastFailed ? `${formatRate(lastFailed)} failed connects` : undefined}
                                format={formatSeconds}
                                loading={initial}
                                timeFormat={timeFormat}
                                emptyText={empty('rtt_p95')}
                            />
                            <MetricChart
                                title="TCP resets"
                                data={view.resets.points}
                                series={[
                                    { key: 'sent', label: 'Sent' },
                                    { key: 'received', label: 'Received' },
                                ]}
                                format={formatRate}
                                loading={initial}
                                timeFormat={timeFormat}
                                emptyText={empty('resets')}
                            />
                            <MetricChart
                                title="Run-queue delay"
                                data={view.runq}
                                series={[
                                    { key: 'p50', label: 'p50' },
                                    { key: 'p95', label: 'p95' },
                                    { key: 'p99', label: 'p99' },
                                ]}
                                format={formatSeconds}
                                loading={initial}
                                timeFormat={timeFormat}
                                emptyText={empty('runq_p95')}
                            />
                            {scope === 'server' && (
                                <MetricChart
                                    title="Probe overhead"
                                    type="composed"
                                    data={view.overhead.points}
                                    series={view.overhead.keys.map((key) => ({
                                        key,
                                        label: key === 'agent' ? 'Agent reads' : key,
                                        mark: 'area' as const,
                                        stack: 'o',
                                    }))}
                                    format={(value) => `${(value * 100).toFixed(2)}% of a CPU`}
                                    value={
                                        servers[0]?.probe?.overhead_percent !== undefined
                                            ? `${servers[0].probe.overhead_percent}% of capacity`
                                            : undefined
                                    }
                                    loading={initial}
                                    timeFormat={timeFormat}
                                    emptyText={empty('overhead')}
                                />
                            )}
                        </div>
                        <p className="text-fg-muted text-xs">
                            DNS names are kept 30 days
                            {servers.some((server) => server.full_dns_names) ? '' : ', folded to their domain (api.stripe.com → stripe.com)'}
                            {data?.names_scope === 'workloads' ? "; the server's own processes' names are shown to network managers only" : ''}.
                        </p>
                        <DataTable
                            label="Most queried names"
                            rows={data?.names ?? []}
                            rowKey={(row) => row.name}
                            columns={NAME_COLUMNS}
                            empty={{ title: data?.errors.names ?? 'No DNS queries in this range' }}
                        />
                    </>
                )}
            </div>
        </Section>
    );
}

function useSave(server: EbpfServer, onChanged: () => void) {
    const [busy, setBusy] = useState(false);
    const save = async (settings: EbpfSettings, done: string) => {
        setBusy(true);
        try {
            await setEbpf(server.server_id, settings);
            toast.success(done, settings.enabled ? 'The agent applies it within a minute.' : undefined);
            onChanged();
        } catch (e) {
            toast.error('Could not change eBPF', errorMessage(e));
        } finally {
            setBusy(false);
        }
    };

    return { busy, save };
}

/** The server's switches (Admin only): the probe, and whole DNS names instead of their domain. */
function EbpfSwitch({ server, onChanged }: { server: EbpfServer; onChanged: () => void }) {
    const { busy, save } = useSave(server, onChanged);
    const disabled = busy || !server.can_manage;

    return (
        <div className="text-fg-muted flex flex-wrap items-center gap-4 text-sm">
            {server.enabled && (
                <label className="flex items-center gap-2">
                    Full DNS names
                    <Switch
                        checked={server.full_dns_names}
                        disabled={disabled}
                        onCheckedChange={(checked) =>
                            void save({ enabled: true, full_dns_names: checked }, checked ? 'Full DNS names on' : 'DNS names folded to domains')
                        }
                        aria-label="Collect full DNS names"
                    />
                </label>
            )}
            <label className="flex items-center gap-2">
                eBPF
                <Switch
                    checked={server.enabled}
                    disabled={disabled}
                    onCheckedChange={(checked) => void save({ enabled: checked }, checked ? 'eBPF turned on' : 'eBPF turned off')}
                    aria-label="eBPF probe on this server"
                />
            </label>
        </div>
    );
}

function ReenableButton({ server, onChanged }: { server: EbpfServer; onChanged: () => void }) {
    const { busy, save } = useSave(server, onChanged);

    return (
        <Button size="sm" loading={busy} onClick={() => void save({ enabled: true, reenable: true }, 'eBPF re-enabled')}>
            Re-enable
        </Button>
    );
}
