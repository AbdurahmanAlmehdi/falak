import { type MetricPoint } from '@/components/falak/metric-chart';
import { type EngineName } from './types';

/** Observability of a database instance (docs/plans/OBSERVABILITY.md phase 3): the Metrics, Logs and Insights tabs. */

export interface MetricSeriesDto {
    labels: Record<string, string>;
    points: [number, number | null][];
}

/** GET /databases/instances/{id}/metrics/data */
export interface DatabaseMetricsResponse {
    range: string;
    step: number;
    engine: EngineName;
    memory_bytes: number;
    cpus: number | null;
    observed_at: string | null;
    charts: Record<string, MetricSeriesDto[] | undefined>;
    errors: Record<string, string>;
}

export interface DatabaseLogLine {
    ts: string;
    at: string;
    line: string;
    kind: 'slow' | 'engine';
    severity: string | null;
    duration_ms: number | null;
    database: string | null;
}

export type Severity = 'info' | 'warning' | 'critical';

export interface Recommendation {
    key: string;
    severity: Severity;
    title: string;
    detail: string;
}

export interface TopQuery {
    fingerprint: string;
    query: string;
    database: string | null;
    source: 'pg_stat_statements' | 'digest' | 'slowlog';
    calls: number;
    total_ms: number;
    mean_ms: number | null;
    max_ms: number;
    rows: number;
    no_index: number;
    last_seen_at: string;
}

export interface DatabaseIssueRow {
    id: string;
    title: string;
    culprit: string | null;
    status: string;
    priority: string;
    occurrences: number;
    first_seen_at: string;
    last_seen_at: string;
    url: string;
}

/** GET /databases/instances/{id}/insights */
export interface DatabaseInsightsResponse {
    range: string;
    engine: EngineName;
    observed_at: string | null;
    reporting: 'ok' | 'agent_outdated' | 'image_outdated' | 'stopped';
    query_insights: {
        enabled: boolean;
        status: 'on' | 'off' | 'unavailable' | null;
        reason: string | null;
        /** Enabled but not applied yet: waiting for the restart (the enable's, or the next one the user causes). */
        pending?: boolean;
        can_enable: boolean;
        /** Why it can't be turned on now (agent or image too old, MariaDB below 1 GiB). */
        blocked?: string | null;
        /** The enable could not be sent (the agent is not connected). */
        error?: string | null;
    };
    slow_query_ms: number;
    gauges: Record<string, number>;
    labels: Record<string, string>;
    settings: Record<string, number>;
    databases: { name: string; size_bytes?: number; keys?: number }[];
    issues: DatabaseIssueRow[];
    top_queries: TopQuery[];
    recommendations: Recommendation[];
}

/**
 * Merges query results into chart points by timestamp: each entry of `sources` becomes one key, its series summed
 * (`max` per key on request). A timestamp where a source has no value leaves that key unset.
 */
export function combine(sources: Record<string, MetricSeriesDto[] | undefined>, reduce: Partial<Record<string, 'sum' | 'max'>> = {}): MetricPoint[] {
    const byTime = new Map<number, MetricPoint>();

    Object.entries(sources).forEach(([key, series]) => {
        const fold = reduce[key] ?? 'sum';

        (series ?? []).forEach((item) => {
            item.points.forEach(([t, value]) => {
                const point = byTime.get(t) ?? { t };
                byTime.set(t, point);
                if (value === null) return;
                const current = point[key];
                point[key] = typeof current !== 'number' ? value : fold === 'max' ? Math.max(current, value) : current + value;
            });
        });
    });

    return [...byTime.values()].sort((a, b) => Number(a.t) - Number(b.t));
}

/** One key per value of `label` (connections by state, sizes by database), at most `limit` keys (the largest last values). */
export function splitBy(series: MetricSeriesDto[] | undefined, label: string, limit = 5): { points: MetricPoint[]; keys: string[] } {
    const groups = new Map<string, MetricSeriesDto[]>();

    (series ?? []).forEach((item) => {
        const key = item.labels[label] ?? 'other';
        groups.set(key, [...(groups.get(key) ?? []), item]);
    });

    const ranked = [...groups.entries()]
        .map(([key, items]) => ({ key, last: lastValue(items) ?? 0 }))
        .sort((a, b) => b.last - a.last || a.key.localeCompare(b.key));
    const keys = ranked.slice(0, limit).map((item) => item.key);
    const rest = ranked.slice(limit).flatMap((item) => groups.get(item.key) ?? []);
    const sources: Record<string, MetricSeriesDto[]> = Object.fromEntries(keys.map((key) => [key, groups.get(key) ?? []]));

    if (rest.length > 0) {
        sources.other = [...(sources.other ?? []), ...rest];
        if (!keys.includes('other')) keys.push('other');
    }

    return { points: combine(sources), keys };
}

function lastValue(series: MetricSeriesDto[]): number | null {
    let out: number | null = null;

    series.forEach((item) => {
        for (let index = item.points.length - 1; index >= 0; index--) {
            const value = item.points[index][1];
            if (value !== null) {
                out = (out ?? 0) + value;
                break;
            }
        }
    });

    return out;
}

/** Merges point lists by timestamp (their keys must differ). */
export function mergePoints(...lists: MetricPoint[][]): MetricPoint[] {
    const byTime = new Map<string, MetricPoint>();

    lists.flat().forEach((point) => byTime.set(String(point.t), { ...byTime.get(String(point.t)), ...point }));

    return [...byTime.values()].sort((a, b) => Number(a.t) - Number(b.t));
}

export function hasValues(series: MetricSeriesDto[] | undefined): boolean {
    return (series ?? []).some((item) => item.points.some(([, value]) => value !== null));
}

export function latest(points: MetricPoint[], key = 'value'): number | null {
    for (let index = points.length - 1; index >= 0; index--) {
        const value = points[index][key];
        if (typeof value === 'number') return value;
    }

    return null;
}

export function formatMs(ms: number | null | undefined): string {
    if (ms === null || ms === undefined || Number.isNaN(ms)) return '—';
    if (ms >= 60_000) return `${(ms / 60_000).toFixed(1)} min`;
    if (ms >= 1000) return `${(ms / 1000).toFixed(ms >= 10_000 ? 0 : 2)} s`;

    return `${ms.toFixed(ms >= 100 ? 0 : 1)} ms`;
}

export function formatRate(value: number): string {
    if (value >= 1000) return `${(value / 1000).toFixed(1)}k`;

    return value >= 100 ? value.toFixed(0) : value >= 1 ? value.toFixed(1) : value.toFixed(2);
}

export function formatCount(value: number): string {
    return new Intl.NumberFormat('en', { notation: value >= 10_000 ? 'compact' : 'standard', maximumFractionDigits: 1 }).format(value);
}

/** Labels of the engine's charts. */
export const CONNECTION_STATES: Record<string, string> = {
    active: 'Active',
    idle: 'Idle',
    idle_in_transaction: 'Idle in transaction',
    running: 'Running',
    blocked: 'Blocked',
    normal: 'Connected',
    other: 'Other',
};

const SEVERITY_RANK: Record<Severity, number> = { critical: 2, warning: 1, info: 0 };

/** Most severe first (the control plane orders them too; kept stable for equal severities). */
export function sortRecommendations(items: Recommendation[]): Recommendation[] {
    return [...items].sort((a, b) => SEVERITY_RANK[b.severity] - SEVERITY_RANK[a.severity]);
}

export function severityTone(severity: Severity): 'danger' | 'warning' | 'info' {
    return severity === 'critical' ? 'danger' : severity === 'warning' ? 'warning' : 'info';
}

/** Where top queries come from, as the tab says it. */
export function sourceLabel(source: TopQuery['source']): string {
    return source === 'pg_stat_statements' ? 'pg_stat_statements' : source === 'digest' ? 'statement digests' : 'slow log';
}

/** What the Insights tab says when the instance does not report (and the action it suggests). */
export function reportingNote(reporting: DatabaseInsightsResponse['reporting']): string | null {
    switch (reporting) {
        case 'agent_outdated':
            return "This server's agent predates database insights: update the agent (Servers → the server → Agent) to see engine metrics, slow queries and recommendations.";
        case 'image_outdated':
            return 'No report from the database yet. Images built before Falak v0.16.0 cannot report: upgrade the instance to the same version (Settings → Upgrade) to get the new image. It can take a few minutes after a start.';
        case 'stopped':
            return 'The database is not running.';
        default:
            return null;
    }
}

/** Query insights status line. */
export function queryInsightsState(
    insights: DatabaseInsightsResponse['query_insights'],
    engine: EngineName,
): { tone: 'success' | 'warning' | 'danger' | 'faint'; label: string; text: string } {
    if (insights.status === 'on') {
        return { tone: 'success', label: 'On', text: engine === 'redis' || engine === 'valkey' ? 'On (SLOWLOG)' : 'On' };
    }
    if (insights.enabled && insights.error) {
        return { tone: 'danger', label: 'Not applied', text: `Enabled, but not applied: ${insights.error}` };
    }
    if (insights.enabled) {
        return {
            tone: 'warning',
            label: 'Pending',
            text: 'Enabled: applies when the database restarts (the restart you asked for, or the next one). This updates with the next report.',
        };
    }
    if (insights.status === 'off') {
        return { tone: 'faint', label: 'Off', text: insights.reason ? `Off: ${insights.reason}` : 'Off: top queries come from the slow log' };
    }
    if (insights.status === 'unavailable') {
        return { tone: 'warning', label: 'Unavailable', text: insights.reason ?? 'Unavailable' };
    }

    return { tone: 'faint', label: 'Unknown', text: 'Unknown: no report yet' };
}
