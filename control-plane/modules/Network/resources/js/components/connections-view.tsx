import {
    Callout,
    CodeBlock,
    DataTable,
    IconButton,
    MetricChart,
    Section,
    Segmented,
    Select,
    Tag,
    type DataTableColumn,
    type MetricSeries,
} from '@/components/falak';
import { useJson } from '@/hooks/use-json';
import { ipActionsFor, shellContext, type IpActionProps } from '@/lib/registry';
import { formatBytes } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { format } from 'date-fns';
import { RotateCw } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    actionableIp,
    bucketLabel,
    conntrackPercent,
    formatAge,
    formatMs,
    latest,
    peerLabel,
    pointsBy,
    STATES,
    type ConnectionsResponse,
    type ListenerRow,
    type PeerRef,
    type PeerRow,
    type WireGuardRow,
} from '../connections';

const RANGES = ['1h', '6h', '24h', '7d'] as const;
type Range = (typeof RANGES)[number];

export interface ConnectionsViewProps {
    /** Endpoint without the query string, e.g. /network/servers/{id}/connections. */
    url: string;
    scope: 'server' | 'site' | 'database';
    serverId?: string | null;
    siteId?: string | null;
}

const count = (value: number) => value.toFixed(0);

/**
 * The Network tabs (docs/plans/network/connections.md, "UI"): connections by state and direction, long-lived
 * connections, conntrack, probe latency and WireGuard handshakes (charts); listeners, talks-to, clients and WireGuard
 * peers from the agent's latest report (tables). Refreshes every 30s.
 */
export function ConnectionsView({ url, scope, serverId = null, siteId = null }: ConnectionsViewProps) {
    const [range, setRange] = useState<Range>('1h');
    const [service, setService] = useState<string>('');
    const query = new URLSearchParams({ range, ...(service ? { service } : {}) }).toString();
    const { data, error, loading, reload } = useJson<ConnectionsResponse>(`${url}?${query}`, { interval: 30_000, unwrap: false });
    const charts = useMemo(() => data?.charts ?? {}, [data]);
    const timeFormat = (value: number | string) => format(new Date(Number(value) * 1000), range === '7d' ? 'MMM d HH:mm' : 'HH:mm');
    const view = useMemo(
        () => ({
            states: pointsBy(charts.states, 'falak_net_state'),
            directions: pointsBy(charts.directions, 'falak_net_direction'),
            longLived: pointsBy(charts.long_lived),
            conntrack: (() => {
                const entries = pointsBy(charts.conntrack).points;
                const limit = new Map(pointsBy(charts.conntrack_limit).points.map((point) => [point.t, point.value]));

                return entries.map((point) => ({ ...point, limit: limit.get(point.t) ?? null }));
            })(),
            probes: pointsBy(charts.probes, 'falak_net_probe_target'),
            handshakes: pointsBy(charts.handshakes, 'falak_net_wireguard_peer'),
            peersOut: pointsBy(charts.peers_out, 'falak_net_peer'),
            peerBytes: pointsBy(charts.peer_bytes, 'falak_net_peer'),
        }),
        [charts],
    );
    const initial = loading && data === null;
    const empty = (key: string) => (charts[key]?.length ? undefined : (data?.errors[key] ?? data?.errors.metrics ?? 'No data in this range'));
    const stateSeries: MetricSeries[] = Object.keys(STATES)
        .filter((key) => view.states.keys.includes(key))
        .slice(0, 5)
        .map((key) => ({ key, label: STATES[key], mark: 'area' as const, stack: 's' }));
    const top = (keys: string[]) => keys.slice(0, 5).map((key) => ({ key, label: bucketLabel(key) }));

    return (
        <div className="grid min-w-0 gap-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-fg-muted text-xs">
                    Sockets every 15 s, peers every minute{data?.observed_at ? ` · report ${format(new Date(data.observed_at), 'HH:mm:ss')}` : ''}
                </p>
                <div className="flex items-center gap-1">
                    {scope === 'site' && (data?.services?.length ?? 0) > 0 && (
                        <Select
                            size="sm"
                            aria-label="Compose service"
                            value={service || 'all'}
                            onValueChange={(value) => setService(value === 'all' ? '' : value)}
                            options={[
                                { value: 'all', label: 'All services' },
                                ...(data?.services ?? []).map((name) => ({ value: name, label: name })),
                            ]}
                        />
                    )}
                    <Segmented label="Time range" value={range} onValueChange={setRange} options={RANGES.map((value) => ({ value, label: value }))} />
                    <IconButton size="sm" label="Refresh" icon={<RotateCw />} onClick={() => void reload()} loading={loading && data !== null} />
                </div>
            </div>
            {error && data === null && (
                <Callout tone="danger" title="Connections are not available">
                    {error}
                </Callout>
            )}
            {data && <ReportingNotice reporting={data.reporting} />}
            {data?.conntrack?.accounting === 'off' && scope === 'server' && <AccountingNotice />}

            <div className="grid gap-3 lg:grid-cols-2">
                <MetricChart
                    title="Connections by state"
                    type="composed"
                    data={view.states.points}
                    series={stateSeries}
                    format={count}
                    loading={initial}
                    timeFormat={timeFormat}
                    emptyText={empty('states')}
                />
                <MetricChart
                    title="Established connections"
                    data={view.directions.points}
                    series={[
                        { key: 'inbound', label: 'Inbound' },
                        { key: 'outbound', label: 'Outbound' },
                    ]}
                    value={latest(view.longLived.points) !== null ? `${count(latest(view.longLived.points)!)} long-lived` : undefined}
                    format={count}
                    loading={initial}
                    timeFormat={timeFormat}
                    emptyText={empty('directions')}
                />
                {scope === 'server' ? (
                    <>
                        <MetricChart
                            title="Connection tracking table"
                            data={view.conntrack}
                            series={[
                                { key: 'value', label: 'Entries' },
                                { key: 'limit', label: 'Limit', color: 'var(--danger)', dashed: true },
                            ]}
                            value={conntrackPercent(data?.conntrack) !== null ? `${conntrackPercent(data?.conntrack)}%` : undefined}
                            format={count}
                            loading={initial}
                            timeFormat={timeFormat}
                            emptyText={empty('conntrack')}
                        />
                        <MetricChart
                            title="Connect time"
                            data={view.probes.points}
                            series={top(view.probes.keys).map((item) => ({ ...item, label: probeName(data, item.key) }))}
                            format={formatMs}
                            loading={initial}
                            timeFormat={timeFormat}
                            emptyText={empty('probes')}
                        />
                    </>
                ) : (
                    <>
                        <MetricChart
                            title="Talks to (connections)"
                            data={view.peersOut.points}
                            series={top(view.peersOut.keys)}
                            format={count}
                            loading={initial}
                            timeFormat={timeFormat}
                            emptyText={empty('peers_out')}
                        />
                        <MetricChart
                            title="Traffic per peer"
                            data={view.peerBytes.points}
                            series={top(view.peerBytes.keys)}
                            format={(value) => `${formatBytes(value)}/s`}
                            loading={initial}
                            timeFormat={timeFormat}
                            emptyText={empty('peer_bytes') ?? undefined}
                        />
                    </>
                )}
            </div>

            {data?.listeners && (
                <Section title="Listening sockets" description="What accepts connections, who owns it, and whether the internet can reach it.">
                    <DataTable
                        label="Listening sockets"
                        rows={data.listeners}
                        rowKey={(row) => `${row.proto}|${row.address}|${row.port}|${row.workload ?? ''}`}
                        columns={LISTENER_COLUMNS}
                        empty={{ title: 'No listeners' }}
                    />
                </Section>
            )}
            {data?.talks_to && (
                <Section title="Talks to" description="Outbound connections, by remote endpoint (the 20 busiest per workload, the rest folded).">
                    <PeerTable rows={data.talks_to} context="talks_to" serverId={serverId} siteId={siteId} />
                </Section>
            )}
            {data?.clients && (
                <Section title={scope === 'database' ? 'Connected clients' : 'Clients'} description="Inbound connections, by remote address.">
                    <PeerTable rows={data.clients} context="clients" serverId={serverId} siteId={siteId} />
                </Section>
            )}
            {scope === 'server' && (data?.wireguard?.length ?? 0) > 0 && (
                <Section title="WireGuard peers" description="Latest handshake, traffic and connect time to each private-network peer.">
                    <DataTable
                        label="WireGuard peers"
                        rows={data!.wireguard!}
                        rowKey={(row) => `${row.interface}|${row.public_key}`}
                        columns={WIREGUARD_COLUMNS}
                    />
                </Section>
            )}
        </div>
    );
}

function probeName(data: ConnectionsResponse | null, key: string): string {
    return data?.probes?.find((probe) => probe.name === key)?.target?.label ?? bucketLabel(key);
}

function PeerName({ peer, fallback }: { peer: PeerRef | null; fallback: string }) {
    if (peer?.url) {
        return (
            <Link href={peer.url} className="text-fg hover:underline">
                {peer.label}
            </Link>
        );
    }

    return <span className="text-fg">{peer?.label ?? fallback}</span>;
}

const LISTENER_COLUMNS: DataTableColumn<ListenerRow>[] = [
    {
        id: 'port',
        header: 'Port',
        cell: (row) => <span className="tabular font-mono text-xs">{`${row.proto}/${row.port}`}</span>,
        sortValue: (row) => row.port,
        width: '110px',
    },
    { id: 'address', header: 'Bound to', cell: (row) => <span className="font-mono text-xs">{row.address}</span>, hideOnMobile: true },
    {
        id: 'owner',
        header: 'Owner',
        cell: (row) => <PeerName peer={row.owner} fallback={row.container ?? row.process ?? row.workload ?? '—'} />,
        sortValue: (row) => row.owner?.label ?? row.process ?? '',
    },
    {
        id: 'exposure',
        header: '',
        align: 'right',
        cell: (row) =>
            row.exposed ? (
                row.issue_url ? (
                    <Link href={row.issue_url}>
                        <Tag tone="danger">Exposed</Tag>
                    </Link>
                ) : (
                    <Tag tone="danger">Exposed</Tag>
                )
            ) : row.public ? (
                <Tag>Public interface</Tag>
            ) : null,
    },
];

const bytes = (value: number | null | undefined) => (value === null || value === undefined ? '—' : formatBytes(value));

function PeerTable({
    rows,
    context,
    serverId,
    siteId,
}: {
    rows: PeerRow[];
    context: IpActionProps['context'];
    serverId: string | null;
    siteId: string | null;
}) {
    const { props } = usePage<SharedData>();
    const actions = useMemo(() => ipActionsFor(shellContext(props)), [props]);
    const withBytes = rows.some((row) => row.rx_bytes !== undefined && row.rx_bytes !== null);
    const columns: DataTableColumn<PeerRow>[] = [
        {
            id: 'peer',
            header: 'Peer',
            cell: (row) => <PeerName peer={row.peer} fallback={peerLabel(row)} />,
            sortValue: (row) => peerLabel(row),
        },
        { id: 'address', header: 'Address', cell: (row) => <span className="font-mono text-xs">{row.address ?? ''}</span>, hideOnMobile: true },
        { id: 'workload', header: 'From', cell: (row) => row.workload, hideOnMobile: true },
        { id: 'connections', header: 'Connections', align: 'right', cell: (row) => count(row.connections), sortValue: (row) => row.connections },
        ...(withBytes
            ? [
                  {
                      id: 'rx',
                      header: 'Received / min',
                      align: 'right' as const,
                      cell: (row: PeerRow) => bytes(row.rx_bytes),
                      sortValue: (row: PeerRow) => row.rx_bytes ?? 0,
                  },
                  {
                      id: 'tx',
                      header: 'Sent / min',
                      align: 'right' as const,
                      cell: (row: PeerRow) => bytes(row.tx_bytes),
                      sortValue: (row: PeerRow) => row.tx_bytes ?? 0,
                  },
              ]
            : []),
        ...(actions.length > 0
            ? [
                  {
                      id: 'actions',
                      header: '',
                      align: 'right' as const,
                      cell: (row: PeerRow) => {
                          const ip = actionableIp(row);

                          return ip ? (
                              <span className="flex justify-end gap-1">
                                  {actions.map(({ id, component: Action }) => (
                                      <Action key={id} ip={ip} serverId={serverId} siteId={siteId} context={context} />
                                  ))}
                              </span>
                          ) : null;
                      },
                  },
              ]
            : []),
    ];

    return (
        <DataTable
            label={context === 'talks_to' ? 'Talks to' : 'Clients'}
            rows={rows}
            rowKey={(row) => `${row.workload}|${row.direction}|${row.address ?? 'other'}|${row.port}`}
            columns={columns}
            empty={{ title: 'No connections in the latest report' }}
        />
    );
}

const WIREGUARD_COLUMNS: DataTableColumn<WireGuardRow>[] = [
    {
        id: 'peer',
        header: 'Peer',
        cell: (row) => <PeerName peer={row.peer} fallback={`${row.public_key.slice(0, 8)}…`} />,
        sortValue: (row) => row.peer?.label ?? row.public_key,
    },
    { id: 'ips', header: 'Allowed IPs', cell: (row) => <span className="font-mono text-xs">{row.allowed_ips.join(', ')}</span>, hideOnMobile: true },
    {
        id: 'handshake',
        header: 'Handshake',
        cell: (row) => (
            <Tag tone={row.handshake_age_s === null || row.handshake_age_s > 300 ? 'danger' : 'success'}>{formatAge(row.handshake_age_s)}</Tag>
        ),
        sortValue: (row) => row.handshake_age_s ?? Number.MAX_SAFE_INTEGER,
    },
    { id: 'latency', header: 'Connect time', align: 'right', cell: (row) => formatMs(row.connect_ms), sortValue: (row) => row.connect_ms ?? null },
    { id: 'rx', header: 'Received', align: 'right', cell: (row) => formatBytes(row.rx_bytes), sortValue: (row) => row.rx_bytes, hideOnMobile: true },
    { id: 'tx', header: 'Sent', align: 'right', cell: (row) => formatBytes(row.tx_bytes), sortValue: (row) => row.tx_bytes, hideOnMobile: true },
];

function ReportingNotice({ reporting }: { reporting: ConnectionsResponse['reporting'] }) {
    if (reporting === 'ok') return null;
    if (reporting === 'agent_outdated') {
        return (
            <Callout tone="info" title="Update the agent">
                Connections, listeners and peers need Falak agent v0.17.0 or later on the server.
            </Callout>
        );
    }

    return (
        <Callout
            tone={reporting === 'stale' ? 'warning' : 'info'}
            title={reporting === 'stale' ? 'The latest report is old' : 'Waiting for the first report'}
        >
            {reporting === 'stale'
                ? 'The agent has not reported its connections for a while: is it online?'
                : 'The agent reports its connections once a minute.'}
        </Callout>
    );
}

function AccountingNotice() {
    return (
        <Callout tone="info" title="Bytes per peer are off">
            The kernel counts bytes per connection only with conntrack accounting on. Falak never changes it for you; to turn it on (only connections
            opened afterwards are counted):
            <CodeBlock
                className="mt-2"
                code={
                    'echo net.netfilter.nf_conntrack_acct=1 | sudo tee /etc/sysctl.d/90-conntrack-acct.conf\nsudo sysctl -w net.netfilter.nf_conntrack_acct=1'
                }
            />
        </Callout>
    );
}
