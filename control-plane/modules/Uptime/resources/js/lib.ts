import { type StatusTone } from '@/components/falak/status';

export type MonitorType = 'http' | 'tcp' | 'tls';
export type UptimeWindow = '24h' | '7d' | '30d' | '90d';

export interface UptimeMonitor {
    id: string;
    name: string;
    source: 'auto' | 'user';
    type: MonitorType;
    target: string;
    url: string | null;
    host: string | null;
    port: number | null;
    site_id: string | null;
    site_name: string | null;
    expected_status: string;
    keyword: string | null;
    keyword_absent: boolean;
    max_redirects: number;
    timeout_seconds: number;
    interval_seconds: number;
    slow_ms: number | null;
    slow_threshold_ms: number;
    status: 'pending' | 'up' | 'down';
    armed: boolean;
    paused: boolean;
    last_checked_at: string | null;
    status_changed_at: string | null;
    last_total_ms: number | null;
    last_status_code: number | null;
    last_error: string | null;
    tls: { problem: string | null; issuer: string | null; expires_at: string | null; subject: string | null } | null;
    tls_problem: string | null;
    uptime: Record<UptimeWindow, number | null> | null;
}

export interface UptimeIncident {
    id: string;
    monitor_id: string;
    monitor_name: string | null;
    started_at: string;
    resolved_at: string | null;
    duration_seconds: number;
    cause_kind: string | null;
    cause: string | null;
    cause_status_code: number | null;
    last_error: string | null;
    resolution: string | null;
    issue_id: string | null;
}

export interface UptimeDay {
    date: string;
    uptime: number | null;
    down_minutes: number;
}

export interface UptimeLimits {
    max_monitors: number;
    min_interval: number;
    max_interval: number;
    max_timeout: number;
    max_redirects: number;
    /** Worst-case worker load allowed per organization, and what its active monitors use. */
    budget: number;
    load: number | null;
    /** Seconds the organization's oldest due check has waited. */
    lag_seconds: number | null;
    /** Set while the control plane cannot reach the internet (checks are recorded as unknown). */
    offline_since: string | null;
}

export interface MonitorData {
    monitor: UptimeMonitor;
    range: UptimeWindow;
    series: Array<Record<string, number | string | null> & { t: string }>;
    days: UptimeDay[];
    incidents: UptimeIncident[];
    failures: Array<{ at: string; error_kind: string | null; error: string | null; status_code: number | null; ip: string | null; attempts: number }>;
}

/** "99.95%" — two decimals at most, trailing zeros dropped; "—" without checks. */
export function formatUptime(value: number | null | undefined): string {
    if (value === null || value === undefined) return '—';

    return `${Number(value.toFixed(2))}%`;
}

/** Bar / text tone of an uptime percentage: ≥ 99.9 good, ≥ 99 degraded, below that bad. */
export function uptimeTone(value: number | null | undefined): StatusTone {
    if (value === null || value === undefined) return 'faint';
    if (value >= 99.9) return 'success';
    if (value >= 99) return 'warning';

    return 'danger';
}

export function formatDurationSeconds(seconds: number): string {
    if (seconds < 60) return `${seconds} s`;
    if (seconds < 3600) return `${Math.floor(seconds / 60)} min`;
    if (seconds < 86400) return `${Math.floor(seconds / 3600)} h ${Math.floor((seconds % 3600) / 60)} min`;

    return `${Math.floor(seconds / 86400)} d ${Math.floor((seconds % 86400) / 3600)} h`;
}

/** Status for StatusDot / StatusBadge plus a label. */
export function monitorStatus(monitor: Pick<UptimeMonitor, 'paused' | 'status' | 'source' | 'armed'>): { status: string; label: string } {
    if (monitor.paused) return { status: 'inactive', label: 'Paused' };
    if (monitor.status === 'down') return { status: 'failed', label: 'Down' };
    if (monitor.status === 'up') return { status: 'healthy', label: 'Up' };

    return { status: 'queued', label: monitor.source === 'auto' ? 'Waiting for first success' : 'Pending' };
}

/** Mirrors the server's ExpectedStatus: codes (200) and classes (2xx), comma-separated; empty = default. */
export function isValidExpectedStatus(value: string): boolean {
    if (value.trim() === '') return true;

    return value.split(',').every((part) => /^([1-5]xx|[1-5][0-9]{2})$/.test(part.trim().toLowerCase()));
}

const TLS_PROBLEMS: Record<string, string> = {
    expired: 'Certificate expired',
    not_yet_valid: 'Certificate not valid yet',
    self_signed: 'Self-signed certificate',
    intermediate_missing: 'Intermediate certificate missing',
    untrusted: 'Untrusted certificate chain',
    hostname_mismatch: 'Certificate does not cover the host name',
    no_certificate: 'No certificate',
    expiring: 'Certificate expires soon',
};

export function tlsProblemLabel(problem: string): string {
    return TLS_PROBLEMS[problem] ?? problem;
}

export function intervalLabel(seconds: number): string {
    return seconds % 60 === 0 ? `every ${seconds / 60} min` : `every ${seconds} s`;
}
