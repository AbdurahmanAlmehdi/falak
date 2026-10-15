import { type MetricPoint } from '@/components/falak/metric-chart';

/** A query result of the Network tabs' charts. */
export interface MetricSeriesDto {
    labels: Record<string, string>;
    points: [number, number | null][];
}

export interface PeerRef {
    label: string;
    url: string | null;
}

export type Reporting = 'agent_outdated' | 'waiting' | 'stale' | 'ok';

export interface ConnectionCounts {
    inbound: number;
    outbound: number;
    long_lived: number;
    states: Record<string, number>;
}

export interface ListenerRow {
    proto: 'tcp' | 'udp';
    address: string;
    port: number;
    public: boolean;
    process?: string;
    container?: string;
    owner: PeerRef | null;
    exposed?: boolean;
    issue_url?: string | null;
    workload?: string;
}

export interface PeerRow {
    direction: 'inbound' | 'outbound';
    address: string | null;
    port: number;
    name?: string;
    peer: PeerRef | null;
    rdns: string | null;
    workload: string;
    connections: number;
    rx_bytes?: number | null;
    tx_bytes?: number | null;
    /** The folded peers past the top 20. */
    other?: boolean;
    peers?: number;
    /** Why the address cannot be blocked ("Control plane", "Cloudflare", "Your server", "Private range", …), from the server's guards. */
    unblockable?: string | null;
}

export interface WireGuardRow {
    interface: string;
    public_key: string;
    peer: PeerRef | null;
    allowed_ips: string[];
    handshake_age_s: number | null;
    rx_bytes: number;
    tx_bytes: number;
    connect_ms: number | null;
}

export interface ProbeRow {
    name: string;
    kind: 'server';
    host: string;
    port: number;
    ok: boolean;
    refused?: boolean;
    connect_ms?: number | null;
    error?: string;
    target: PeerRef | null;
}

/** GET /network/{servers|sites|databases}/{id}/connections */
export interface ConnectionsResponse {
    range: string;
    step: number;
    observed_at: string | null;
    reporting: Reporting;
    conntrack?: { entries?: number; limit?: number; accounting: 'on' | 'off' | 'unavailable' } | null;
    connections?: ConnectionCounts | null;
    listeners?: ListenerRow[];
    talks_to?: PeerRow[];
    clients?: PeerRow[];
    wireguard?: WireGuardRow[];
    probes?: ProbeRow[];
    services?: string[];
    charts: Record<string, MetricSeriesDto[]>;
    errors: Record<string, string>;
}

/** Display order and labels of the TCP states (falak.net.state). */
export const STATES: Record<string, string> = {
    established: 'Established',
    time_wait: 'Time wait',
    close_wait: 'Close wait',
    syn_recv: 'SYN received',
    syn_sent: 'SYN sent',
    fin_wait: 'FIN wait',
    last_ack: 'Last ACK',
    closing: 'Closing',
};

/**
 * Chart points: one key per value of `label` (each key's series summed), ordered by time. A missing label is `value`.
 */
export function pointsBy(series: MetricSeriesDto[] | undefined, label?: string): { points: MetricPoint[]; keys: string[] } {
    const byTime = new Map<number, MetricPoint>();
    const keys = new Set<string>();

    (series ?? []).forEach((item) => {
        const key = (label ? item.labels[label] : undefined) ?? 'value';
        keys.add(key);
        item.points.forEach(([t, value]) => {
            const point = byTime.get(t) ?? { t };
            byTime.set(t, point);
            if (value === null) return;
            const current = point[key];
            point[key] = typeof current === 'number' ? current + value : value;
        });
    });

    return { points: [...byTime.values()].sort((a, b) => Number(a.t) - Number(b.t)), keys: [...keys].sort() };
}

/** The last value of a key (null when it has none). */
export function latest(points: MetricPoint[], key = 'value'): number | null {
    for (let index = points.length - 1; index >= 0; index--) {
        const value = points[index][key];
        if (typeof value === 'number') return value;
    }

    return null;
}

/**
 * How a peer is shown: its Falak name, else its reverse DNS name, else its address; `host:port` for outbound peers
 * (the port says what it is), the folded rest as "N other peers".
 */
export function peerLabel(row: PeerRow): string {
    if (row.other) return `${row.peers ?? 0} other peer${row.peers === 1 ? '' : 's'}`;
    const name = row.peer?.label ?? row.rdns ?? row.address ?? '';

    return row.direction === 'outbound' && row.port > 0 && !row.peer ? `${name}:${row.port}` : name;
}

/** Peer names of the agent's metric labels ("server:<id>", "db:<id>", "external:443", "other") as short labels. */
export function bucketLabel(name: string): string {
    const [kind, rest] = name.includes(':') ? [name.slice(0, name.indexOf(':')), name.slice(name.indexOf(':') + 1)] : [name, ''];

    switch (kind) {
        case 'external':
            return rest ? `Internet :${rest}` : 'Internet';
        case 'private':
            return rest ? `Private network :${rest}` : 'Private network';
        case 'local':
            return rest ? `This server :${rest}` : 'This server';
        case 'other':
            return 'Other';
        case 'host':
            return rest || 'This server';
        default:
            return rest || kind;
    }
}

/**
 * Whether an address can be acted on (blocked): a public remote IP. Never a folded row, a Falak peer (a server, a
 * database, a site), loopback, private (RFC 1918, ULA), CGNAT or link-local addresses.
 */
export function actionableIp(row: PeerRow): string | null {
    if (row.other || !row.address || row.peer || row.unblockable || isPrivateAddress(row.address)) return null;

    return row.address;
}

/** Loopback, RFC 1918, CGNAT, link-local, unspecified (IPv4); loopback, ULA, link-local, unspecified (IPv6). */
export function isPrivateAddress(address: string): boolean {
    const v4 = address.match(/^(\d+)\.(\d+)\.\d+\.\d+$/);
    if (v4) {
        const [a, b] = [Number(v4[1]), Number(v4[2])];

        return (
            a === 0 ||
            a === 10 ||
            a === 127 ||
            (a === 172 && b >= 16 && b <= 31) ||
            (a === 192 && b === 168) ||
            (a === 100 && b >= 64 && b <= 127) ||
            (a === 169 && b === 254)
        );
    }
    const v6 = address.toLowerCase();

    return v6 === '::' || v6 === '::1' || /^f[cd][0-9a-f]{0,2}:/.test(v6) || /^fe[89ab][0-9a-f]?:/.test(v6);
}

/** Conntrack fill as a percentage (null without a limit). */
export function conntrackPercent(conntrack: ConnectionsResponse['conntrack']): number | null {
    if (!conntrack?.limit) return null;

    return Math.round(((conntrack.entries ?? 0) * 1000) / conntrack.limit) / 10;
}

/** "never", "42 s", "5 min", "3 h". */
export function formatAge(seconds: number | null | undefined): string {
    if (seconds === null || seconds === undefined) return 'never';
    if (seconds < 120) return `${seconds} s`;
    if (seconds < 7200) return `${Math.round(seconds / 60)} min`;

    return `${Math.round(seconds / 3600)} h`;
}

export function formatMs(ms: number | null | undefined): string {
    if (ms === null || ms === undefined) return '—';

    return ms >= 100 ? `${ms.toFixed(0)} ms` : `${ms.toFixed(1)} ms`;
}
