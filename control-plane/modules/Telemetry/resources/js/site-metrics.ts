import { type MetricMarker, type MetricPoint, type MetricSeries } from '@/components/falak/metric-chart';
import { type MetricSeriesDto } from './types';

/** How the series of one chart key fold into one value per timestamp (several servers, workloads or limits). */
export type Reduce = 'sum' | 'max';

/** Keeps the series whose `label` equals `value` (e.g. `falak_memory_type="cache"`). */
export function withLabel(series: MetricSeriesDto[] | undefined, label: string, value: string): MetricSeriesDto[] {
    return (series ?? []).filter((item) => item.labels[label] === value);
}

/**
 * Merges several query results into chart points by timestamp: each entry of `sources` becomes one key, its series
 * folded with `reduce[key]` (default sum). A timestamp where a source has no value leaves that key unset.
 */
export function combine(sources: Record<string, MetricSeriesDto[] | undefined>, reduce: Partial<Record<string, Reduce>> = {}): MetricPoint[] {
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

/**
 * A quiet counter (no 5xx at all) has no series: its value is 0 at each step where `seen` (the workload's requests)
 * has one, so the chart shows 0 instead of "No data".
 */
export function quietAsZero(points: MetricPoint[]): MetricPoint[] {
    return points.map(({ seen, ...point }) => (typeof point.value !== 'number' && typeof seen === 'number' ? { ...point, value: 0 } : point));
}

/** Whether any series carries a value. */
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

export function peak(points: MetricPoint[], key = 'value'): number | null {
    let max: number | null = null;

    points.forEach((point) => {
        const value = point[key];
        if (typeof value === 'number' && (max === null || value > max)) max = value;
    });

    return max;
}

// The site in the accent, what is not the site in neutral tones, limits in warning, capacity dashed and faint.
export const SITE = 'var(--chart-1)';
export const CACHE = 'var(--chart-4)';
export const REST = 'var(--border-strong)';
export const LIMIT = 'var(--warning)';
export const CAPACITY = 'var(--text-faint)';

/**
 * The memory chart: the site's used memory and the rest of the server's stack to the server's used memory (the host's
 * "used" is total - available, so it leaves the page cache out). The site's page cache is reclaimable and also outside
 * that total: a line of its own, never stacked on top.
 */
export function memorySeries(labels: { rest: string; limit: string; capacity: string }): MetricSeries[] {
    return [
        { key: 'used', label: 'Used', mark: 'area', stack: 'memory', color: SITE },
        { key: 'rest', label: labels.rest, mark: 'area', stack: 'memory', color: REST },
        { key: 'cache', label: 'Cache (reclaimable)', color: CACHE },
        { key: 'limit', label: labels.limit, color: LIMIT, reference: true },
        { key: 'capacity', label: labels.capacity, color: CAPACITY, reference: true },
    ];
}

/**
 * Why the CPU / memory charts show only the servers (null when the site's own usage is there). agentOutdated: a
 * shared-user site on a server whose agent cannot attribute that user's processes per site yet.
 */
export function unmeasuredNote(measured: boolean, sharedUser: boolean, agentOutdated = false): string | null {
    if (measured) return null;
    if (sharedUser && agentOutdated) return 'Update this server’s agent to see this site’s own usage; the server’s is shown.';
    if (sharedUser) return 'No usage of this site’s own has arrived yet; the server’s is shown.';

    return 'No workload metrics for this site yet: the charts show its servers. Servers report each site’s own CPU, memory and processes once their agent is updated.';
}

/** OOM kills per step (`increase`, so fractional) → a marker at each step with at least one kill, and the total. */
export function oomKills(series: MetricSeriesDto[] | undefined): { markers: MetricMarker[]; total: number } {
    const points = combine({ kills: series });
    const markers: MetricMarker[] = [];
    let total = 0;

    points.forEach((point) => {
        const kills = typeof point.kills === 'number' ? Math.round(point.kills) : 0;
        if (kills < 1) return;
        total += kills;
        markers.push({ t: point.t, label: kills === 1 ? 'OOM' : `OOM ×${kills}` });
    });

    return { markers, total };
}

export interface ServiceRow {
    key: string;
    /** Compose service, else the site (its slug), else the container / slice / unix user name. */
    name: string;
    /** The container / slice / unix user name when it is not the name. */
    container: string | null;
    serverId: string | null;
    cpu: number | null;
    cpuLimit: number | null;
    memory: number | null;
    memoryLimit: number | null;
}

/** One row per compose service / container and server, with its latest CPU (cores) and memory against its limits. */
export function serviceRows(charts: {
    services_cpu?: MetricSeriesDto[];
    services_cpu_limit?: MetricSeriesDto[];
    services_memory?: MetricSeriesDto[];
    services_memory_limit?: MetricSeriesDto[];
}): ServiceRow[] {
    const rows = new Map<string, ServiceRow>();
    const fields = [
        ['cpu', charts.services_cpu],
        ['cpuLimit', charts.services_cpu_limit],
        ['memory', charts.services_memory],
        ['memoryLimit', charts.services_memory_limit],
    ] as const;

    fields.forEach(([field, series]) => {
        (series ?? []).forEach((item) => {
            const serverId = item.labels.falak_server_id?.toLowerCase() ?? null;
            const workload = item.labels.falak_workload_name || null;
            const name = item.labels.falak_compose_service || item.labels.service_name || workload || 'site';
            const key = `${serverId ?? ''}/${item.labels.falak_compose_service ?? ''}/${item.labels.falak_workload_name ?? ''}`;
            const container = workload !== name ? workload : null;
            const row = rows.get(key) ?? { key, name, container, serverId, cpu: null, cpuLimit: null, memory: null, memoryLimit: null };
            row[field] = latest(item.points.map(([t, value]) => ({ t, value })));
            rows.set(key, row);
        });
    });

    return [...rows.values()].sort((a, b) => a.name.localeCompare(b.name) || (a.serverId ?? '').localeCompare(b.serverId ?? ''));
}

/** CPU in cores: "0.25", "1.5", "12". */
export function formatCores(value: number): string {
    if (value === 0) return '0';
    if (value < 0.01) return value.toFixed(3);
    if (value < 10) return value.toFixed(2).replace(/\.?0+$/, '');

    return value.toFixed(0);
}
