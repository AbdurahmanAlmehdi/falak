import { type MetricPoint } from '@/components/falak/metric-chart';
import { requestJson } from '@/lib/http';
import { pointsBy, type MetricSeriesDto } from './connections';

/** The agent's facts.ebpf (contracts/agent-protocol/facts.schema.json#/$defs/ebpf). */
export interface ProbeStatus {
    supported: boolean;
    reason?: string;
    enabled: boolean;
    state: 'off' | 'running' | 'degraded' | 'over_budget' | 'failed' | 'unsupported';
    detail?: string;
    programs: string[];
    overhead_percent?: number;
}

export interface EbpfServer {
    server_id: string;
    name: string | null;
    enabled: boolean;
    delivery: 'pending' | 'applied' | 'failed' | null;
    error: string | null;
    agent_outdated: boolean;
    probe: ProbeStatus | null;
    can_manage: boolean;
}

/** GET /network/{servers|sites|databases}/{id}/ebpf */
export interface EbpfResponse {
    range: string;
    step: number;
    servers: EbpfServer[];
    charts: Record<string, MetricSeriesDto[]>;
    names: { name: string; queries: number }[];
    errors: Record<string, string>;
}

export interface Notice {
    tone: 'info' | 'warning' | 'danger';
    title: string;
    body: string;
}

/**
 * Why a server shows no (or partial) eBPF data, in the order it matters: an agent without the feature, the setting off,
 * a kernel that cannot run it, the delivery or the load failing, the probe over its budget, waiting for the agent's
 * first report. Null while it runs whole.
 */
export function serverNotice(server: EbpfServer): Notice | null {
    const probe = server.probe;
    const name = server.name ?? 'This server';

    if (server.agent_outdated) {
        return { tone: 'info', title: 'Update the agent', body: `${name} needs a newer Falak agent for TCP and DNS insights (eBPF).` };
    }
    if (probe && !probe.supported && probe.reason) {
        return { tone: 'info', title: 'Not supported', body: `${name} cannot run the eBPF probe: ${probe.reason}.` };
    }
    if (!server.enabled) {
        return {
            tone: 'info',
            title: 'TCP and DNS insights are off',
            body: `Turn on eBPF in ${name}'s Network tab to see DNS lookups, TCP retransmits and round-trip times per workload.`,
        };
    }
    if (server.delivery === 'failed') {
        return { tone: 'danger', title: 'The setting did not reach the agent', body: server.error ?? 'The command failed.' };
    }
    switch (probe?.state) {
        case 'running':
            return null;
        case 'degraded':
            return { tone: 'warning', title: 'Running in part', body: probe.detail ?? 'Some programs are not attached.' };
        case 'over_budget':
            return {
                tone: 'warning',
                title: 'Turned off: over its CPU budget',
                body: `${probe.detail ?? 'The probe cost more than 1% of the CPU.'} Turn it off and on again to retry.`,
            };
        case 'failed':
            return { tone: 'danger', title: 'The probe did not start', body: probe.detail ?? 'Loading the eBPF programs failed.' };
        case 'unsupported':
            return {
                tone: 'info',
                title: 'Not supported',
                body: `${name} cannot run the eBPF probe: ${probe.detail ?? probe.reason ?? 'unknown reason'}.`,
            };
        default:
            return { tone: 'info', title: 'Starting', body: 'The agent reports the probe within a few minutes.' };
    }
}

/** "820 µs", "12.4 ms", "1.2 s"; "—" without a value. */
export function formatSeconds(seconds: number | null | undefined): string {
    if (seconds === null || seconds === undefined || !Number.isFinite(seconds)) return '—';
    if (seconds < 0.001) return `${(seconds * 1e6).toFixed(0)} µs`;
    if (seconds < 1) return `${(seconds * 1000).toFixed(seconds < 0.1 ? 1 : 0)} ms`;

    return `${seconds.toFixed(1)} s`;
}

/** "0.3/s", "12/s". */
export function formatRate(value: number): string {
    return `${value >= 10 ? value.toFixed(0) : value.toFixed(value >= 1 ? 1 : 2)}/s`;
}

/** Several single-series charts (p50, p95, ...) as one chart's points, keyed by the chart name. */
export function mergeCharts(charts: Record<string, MetricSeriesDto[] | undefined>): MetricPoint[] {
    const byTime = new Map<number, MetricPoint>();

    Object.entries(charts).forEach(([key, series]) => {
        pointsBy(series).points.forEach((point) => {
            const t = Number(point.t);
            const merged = byTime.get(t) ?? { t };
            if (typeof point.value === 'number') merged[key] = point.value;
            byTime.set(t, merged);
        });
    });

    return [...byTime.values()].sort((a, b) => Number(a.t) - Number(b.t));
}

/** PUT /network/servers/{id}/ebpf */
export function setEbpf(serverId: string, enabled: boolean): Promise<{ servers: EbpfServer[] }> {
    return requestJson(`/network/servers/${encodeURIComponent(serverId)}/ebpf`, 'PUT', { enabled });
}
