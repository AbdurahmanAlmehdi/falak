import { type MetricPoint } from '@/components/falak/metric-chart';
import { combine } from './site-metrics';
import { type MetricSeriesDto } from './types';

/** Status classes in chart order, with their colors (success → error). */
export const STATUS_CLASSES = [
    { key: '2xx', label: '2xx', color: 'var(--color-success)' },
    { key: '3xx', label: '3xx', color: 'var(--color-info, #3b82f6)' },
    { key: '4xx', label: '4xx', color: 'var(--color-warning)' },
    { key: '5xx', label: '5xx', color: 'var(--color-danger)' },
    { key: '1xx', label: '1xx', color: 'var(--color-fg-faint)' },
] as const;

/** What each threat rule of the agent stands for (docs/plans/network/http.md, "Threat rules"). */
export const THREAT_RULES: Record<string, string> = {
    env: '.env files',
    git: 'version control (/.git)',
    wordpress: 'WordPress',
    phpmyadmin: 'database admin',
    phpunit: 'PHPUnit RCE',
    cgi: 'CGI',
    config: 'config / backups',
    traversal: 'path traversal',
    sqli: 'SQL injection',
    xss: 'XSS',
    method: 'unusual method',
    '404_storm': '404 storm',
};

/** Requests / s per status class, one chart key per class. */
export function statusPoints(series: MetricSeriesDto[] | undefined): MetricPoint[] {
    const sources: Record<string, MetricSeriesDto[]> = {};

    (series ?? []).forEach((item) => {
        const key = item.labels.http_response_status_class ?? 'other';
        (sources[key] ??= []).push(item);
    });

    return combine(sources);
}

/** Bytes in / out per second. Series without a direction (the access-log fallback) are bytes out. */
export function bytePoints(series: MetricSeriesDto[] | undefined): MetricPoint[] {
    const sources: Record<string, MetricSeriesDto[]> = { in: [], out: [] };

    (series ?? []).forEach((item) => sources[item.labels.network_io_direction === 'receive' ? 'in' : 'out'].push(item));

    return combine(sources);
}

export interface Share {
    key: string;
    value: number;
    share: number;
}

/**
 * Share of each label value over the whole range (the mean of its rate), largest first: HTTP protocols, TLS versions.
 */
export function shares(series: MetricSeriesDto[] | undefined, label: string): Share[] {
    const totals = new Map<string, number>();

    (series ?? []).forEach((item) => {
        const key = item.labels[label] ?? 'other';
        const sum = item.points.reduce((acc, [, value]) => acc + (value ?? 0), 0);
        totals.set(key, (totals.get(key) ?? 0) + sum);
    });

    const all = [...totals.values()].reduce((a, b) => a + b, 0);
    if (all <= 0) return [];

    return [...totals.entries()].map(([key, value]) => ({ key, value, share: value / all })).sort((a, b) => b.value - a.value);
}

/** Protocol label as people know them. */
export function protocolLabel(version: string): string {
    if (version === 'other') return 'Other';

    return `HTTP/${version}`;
}

/** TLS version label ("none": plain HTTP). */
export function tlsLabel(version: string): string {
    if (version === 'none') return 'No TLS';

    return version === 'other' ? 'Other' : `TLS ${version}`;
}

/** Regional indicator flag of an ISO country code (empty when unknown). */
export function flag(country: string | null | undefined): string {
    if (!country || !/^[A-Za-z]{2}$/.test(country)) return '';

    return String.fromCodePoint(...[...country.toUpperCase()].map((c) => 0x1f1e6 + c.charCodeAt(0) - 65));
}

/** The rules a client tried, most hits first, as "env ×40, git ×3". */
export function describeRules(rules: Record<string, number>, notFound = 0): string {
    const parts = Object.entries(rules)
        .sort((a, b) => b[1] - a[1])
        .map(([rule, count]) => `${THREAT_RULES[rule] ?? rule} ×${count}`);

    if (notFound > 0) parts.push(`404 ×${notFound}`);

    return parts.join(', ');
}
