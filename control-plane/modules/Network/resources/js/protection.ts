import { requestJson } from '@/lib/http';

/** Network protection (docs/plans/network/protection.md). */

export type BlockDuration = '1h' | '24h' | '7d' | 'permanent';
export type BlockScope = 'server' | 'site' | 'organization';

export const DURATIONS: { value: BlockDuration; label: string }[] = [
    { value: '1h', label: '1 hour' },
    { value: '24h', label: '24 hours' },
    { value: '7d', label: '7 days' },
    { value: 'permanent', label: 'Permanent' },
];

export type RuleCounters = Record<string, { packets: number; bytes: number }>;

export interface BlockRequest {
    address: string;
    scope: BlockScope;
    server_id?: string;
    site_id?: string;
    duration: BlockDuration;
    reason?: string;
    confirm?: boolean;
    /** The row is a peer the server connects to: the server asks for confirmation. */
    outbound?: boolean;
}

/**
 * What a `network.ip-actions` row passes: the slot's IpActionProps (`ip`, a nullable `serverId`, `siteId`, `context`:
 * "talks_to" for peers the server connects to, "clients", "http_clients", "http_threats"), plus two optional additions
 * of this action: `source` and an explicit `direction`. Everything but `ip` is optional, so it fits the slot as is.
 */
export interface BlockIpProps {
    ip: string;
    serverId?: string | null;
    siteId?: string | null;
    source?: string;
    context?: string;
    direction?: 'inbound' | 'outbound';
}

/** Whether the row's address is a peer the server connects to (outbound), not a client. */
export function isOutbound(props: Pick<BlockIpProps, 'source' | 'context' | 'direction'>): boolean {
    return props.direction === 'outbound' || props.context === 'talks_to' || /(^|\.)(talks_to|peers?|outbound)$/.test(props.source ?? '');
}

export function blockAddress(request: BlockRequest): Promise<{ blocks: { id: string; cidr: string; server_id: string | null }[] }> {
    return requestJson('/network/blocks', 'POST', request);
}

/** The scopes a row can offer: the server and the site it was seen on, and every server. */
export function scopesFor(props: { serverId?: string | null; siteId?: string | null }): { value: BlockScope; label: string }[] {
    return [
        ...(props.serverId ? [{ value: 'server' as const, label: 'This server' }] : []),
        ...(props.siteId ? [{ value: 'site' as const, label: "The site's servers" }] : []),
        { value: 'organization' as const, label: 'All servers' },
    ];
}

/** 1234 → "1.2k", 5_300_000 → "5.3M" (with a unit suffix: "5.3 MB"). */
export function formatCount(value: number, unit = ''): string {
    const steps: [number, string][] = [
        [1e12, 'T'],
        [1e9, 'G'],
        [1e6, 'M'],
        [1e3, 'k'],
    ];

    for (const [size, suffix] of steps) {
        if (value >= size) {
            const n = (value / size).toFixed(value / size >= 10 ? 0 : 1);

            return unit ? `${n} ${suffix}${unit}` : `${n}${suffix}`;
        }
    }

    return unit ? `${value} ${unit}` : String(value);
}
