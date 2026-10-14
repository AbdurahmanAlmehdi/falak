export type IssueKind = 'exception' | 'performance' | 'heartbeat' | 'database' | 'security';
export type IssueStatus = 'open' | 'resolved' | 'ignored';
export type IssuePriority = 'none' | 'low' | 'medium' | 'high' | 'urgent';

export interface Member {
    id: string;
    name: string;
    email: string;
}

export interface IssueSummary {
    id: string;
    kind: IssueKind;
    status: IssueStatus;
    priority: IssuePriority;
    title: string;
    culprit: string | null;
    site_id: string | null;
    site_name: string | null;
    occurrences: number;
    affected_users: number;
    first_seen_at: string;
    last_seen_at: string;
    assignee: Member | null;
    handled: boolean | null;
}

export interface Frame {
    raw: string;
    file: string | null;
    line: number | null;
    function: string | null;
    in_app: boolean;
}

export interface TopEntry {
    name: string;
    count: number;
    errors: number;
    p95_ms: number;
    max_ms: number;
}

export interface OverviewPoint {
    t: string;
    requests: number;
    request_errors: number;
    request_p95_ms: number | null;
    jobs: number;
    failed_jobs: number;
    queries: number;
    exceptions_handled: number;
    exceptions_unhandled: number;
}

export interface HeartbeatMonitor {
    id: string;
    job: string;
    site_id: string | null;
    site_name?: string | null;
    server_id: string | null;
    schedule: string | null;
    timezone: string;
    grace_seconds: number | null;
    enabled: boolean;
    last_status: string | null;
    last_exit_code: number | null;
    last_duration_ms: number | null;
    last_run_at: string | null;
    next_expected_at: string | null;
    missed_at: string | null;
    healthy: boolean;
}

export interface OverviewTotals {
    requests: number;
    request_errors: number;
    request_p95_ms: number | null;
    jobs: number;
    failed_jobs: number;
    queries: number;
    outgoing_requests: number;
    exceptions_handled: number;
    exceptions_unhandled: number;
    cache_hit_ratio: number | null;
    cache_hits: number;
    cache_misses: number;
}

export interface TopIssue {
    id: string;
    kind: IssueKind;
    title: string;
    culprit: string | null;
    site_id: string | null;
    occurrences: number;
    occurrences_in_range: number;
    affected_users: number;
    priority: IssuePriority;
    handled: boolean | null;
    last_seen_at: string;
}

/** Falak\Insights\Application\Queries\SiteOverview output. */
export interface OverviewData {
    range: string;
    from: string;
    to: string;
    bucket_minutes: number;
    totals: OverviewTotals;
    series: OverviewPoint[];
    routes: TopEntry[];
    jobs: TopEntry[];
    queries: TopEntry[];
    outgoing: TopEntry[];
    issues: TopIssue[];
}

export interface HeartbeatSlot {
    at: string;
    status: string;
    duration_ms: number | null;
}

export interface HeartbeatRow extends HeartbeatMonitor {
    expected_24h: number | null;
    actual_24h: number;
    missed_24h: number | null;
    slots: HeartbeatSlot[];
}
