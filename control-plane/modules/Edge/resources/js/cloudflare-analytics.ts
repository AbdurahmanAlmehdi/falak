/** Settings → Networking → Cloudflare traffic: the shape of GET /sites/{id}/cloudflare/analytics and pure helpers. */

export interface CloudflareTraffic {
    requests: number;
    cached_requests: number;
    /** 0..1, null without requests. */
    cached_ratio: number | null;
    bytes: number;
    cached_bytes: number;
    visits: number;
    cache_status: { status: string; requests: number }[];
    countries: { country: string; requests: number }[];
    status_classes: Record<'2xx' | '3xx' | '4xx' | '5xx' | 'other', number>;
    series: { t: string; cached: number; origin: number }[];
}

export interface CloudflareThreats {
    mitigated: number;
    events: number;
    actions: { action: string; events: number }[];
    countries: { country: string; events: number }[];
}

export interface CloudflareDay {
    date: string;
    requests: number;
    cached_requests: number;
    bytes: number;
    cached_bytes: number;
    threats: number;
}

export interface CloudflareZoneReport {
    zone: string;
    connection: string;
    hosts: string[];
    window: { since: string; until: string } | null;
    traffic: CloudflareTraffic | null;
    threats: CloudflareThreats | null;
    daily: CloudflareDay[] | null;
    notes: string[];
    error: string | null;
    permission_missing: boolean;
    fetched_at: string;
}

export interface CloudflareAnalyticsData {
    zones: CloudflareZoneReport[];
    /** Set when every zone failed the same way (said once). */
    error: string | null;
}

const compact = new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 });

/** 1234 → "1.2K"; small numbers as they are. */
export function formatCount(value: number | null | undefined): string {
    if (value === null || value === undefined) return '—';

    return value < 1000 ? String(value) : compact.format(value);
}

/** 0.7012 → "70%", 0.0004 → "<1%", null → "—". */
export function formatPercent(ratio: number | null | undefined): string {
    if (ratio === null || ratio === undefined) return '—';
    if (ratio > 0 && ratio < 0.01) return '<1%';

    return `${Math.round(ratio * 100)}%`;
}

/** Share of the whole (0..1), 0 for an empty whole. */
export function share(part: number, whole: number): number {
    return whole > 0 ? part / whole : 0;
}

/** The hourly series as chart points: t in ms, cached + origin requests. */
export function hourlyPoints(traffic: CloudflareTraffic | null): { t: number; cached: number; origin: number }[] {
    if (!traffic) return [];

    return traffic.series.map((point) => ({ t: Date.parse(point.t), cached: point.cached, origin: point.origin })).sort((a, b) => a.t - b.t);
}

/** Status classes with a count, in order, with their share of the classified requests. */
export function statusRows(traffic: CloudflareTraffic | null): { label: string; requests: number; share: number }[] {
    if (!traffic) return [];
    const entries = (['2xx', '3xx', '4xx', '5xx', 'other'] as const).map((label) => ({ label, requests: traffic.status_classes[label] ?? 0 }));
    const total = entries.reduce((sum, row) => sum + row.requests, 0);

    return entries.filter((row) => row.requests > 0).map((row) => ({ ...row, share: share(row.requests, total) }));
}

/** Country rows with their share of all requests (the remainder is "other countries"). */
export function countryRows(traffic: CloudflareTraffic | null): { country: string; requests: number; share: number }[] {
    if (!traffic) return [];

    return traffic.countries.map((row) => ({ ...row, country: row.country || 'Unknown', share: share(row.requests, traffic.requests) }));
}

/** The 7-day zone totals summed, with the cached share; null without days. */
export function dailyTotals(days: CloudflareDay[] | null): { requests: number; cachedRatio: number | null; bytes: number; threats: number } | null {
    if (!days || days.length === 0) return null;
    const requests = days.reduce((sum, day) => sum + day.requests, 0);
    const cached = days.reduce((sum, day) => sum + day.cached_requests, 0);

    return {
        requests,
        cachedRatio: requests > 0 ? cached / requests : null,
        bytes: days.reduce((sum, day) => sum + day.bytes, 0),
        threats: days.reduce((sum, day) => sum + day.threats, 0),
    };
}

/** Firewall action as a label: managed_challenge → "Managed challenge". */
export function actionLabel(action: string): string {
    const text = action.replace(/_/g, ' ');

    return text.charAt(0).toUpperCase() + text.slice(1);
}
