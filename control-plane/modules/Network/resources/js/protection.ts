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
}

export function blockAddress(request: BlockRequest): Promise<{ blocks: { id: string; cidr: string; server_id: string | null }[] }> {
    return requestJson('/network/blocks', 'POST', request);
}

/** The scopes a row can offer: the server and the site it was seen on, and every server. */
export function scopesFor(props: { serverId?: string; siteId?: string }): { value: BlockScope; label: string }[] {
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
