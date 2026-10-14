import {
    Button,
    DataTable,
    MetricChart,
    RelativeTime,
    Section,
    Segmented,
    SkeletonRows,
    StatusBadge,
    StatusDot,
    Tag,
    type DataTableColumn,
    type MenuAction,
    type MetricPoint,
} from '@/components/falak';
import { useJson } from '@/hooks/use-json';
import { errorMessage, requestJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import { format } from 'date-fns';
import { Activity, Pause, Pencil, Play, Plus, ShieldAlert, Trash2 } from 'lucide-react';
import { useState } from 'react';
import {
    formatDurationSeconds,
    formatUptime,
    intervalLabel,
    monitorStatus,
    tlsProblemLabel,
    uptimeTone,
    type MonitorData,
    type UptimeDay,
    type UptimeIncident,
    type UptimeLimits,
    type UptimeMonitor,
    type UptimeWindow,
} from '../lib';
import { MonitorForm } from './monitor-form';

const BAR_TONE = { success: 'bg-success', warning: 'bg-warning', danger: 'bg-danger', faint: 'bg-fg-faint/30', info: 'bg-info' } as const;

/** 90 daily bars: green ≥ 99.9%, amber ≥ 99%, red below; grey without checks. */
export function UptimeBars({ days, className }: { days: UptimeDay[]; className?: string }) {
    return (
        <div className={cn('flex h-8 items-stretch gap-px', className)} role="img" aria-label={`Daily uptime, last ${days.length} days`}>
            {days.map((day) => (
                <span
                    key={day.date}
                    title={`${day.date} · ${day.uptime === null ? 'no data' : `${formatUptime(day.uptime)}${day.down_minutes > 0 ? ` · ~${day.down_minutes} min down` : ''}`}`}
                    className={cn('min-w-px flex-1 rounded-[1px]', BAR_TONE[uptimeTone(day.uptime)])}
                />
            ))}
        </div>
    );
}

function UptimeCell({ value }: { value: number | null | undefined }) {
    const tone = uptimeTone(value);

    return (
        <span className={cn('tabular-nums', tone === 'danger' && 'text-danger', tone === 'warning' && 'text-warning')}>{formatUptime(value)}</span>
    );
}

export function IncidentList({ incidents, showMonitor = false }: { incidents: UptimeIncident[]; showMonitor?: boolean }) {
    const columns: DataTableColumn<UptimeIncident>[] = [
        {
            id: 'state',
            header: 'Incident',
            cell: (incident) => (
                <span className="flex items-center gap-2">
                    <StatusDot status={incident.resolved_at ? 'healthy' : 'failed'} size="sm" />
                    {showMonitor && <span className="font-medium">{incident.monitor_name}</span>}
                    <span className="text-fg-muted truncate">{incident.cause ?? 'Check failed'}</span>
                </span>
            ),
        },
        { id: 'started', header: 'Started', cell: (incident) => format(new Date(incident.started_at), 'MMM d, HH:mm'), width: '140px' },
        {
            id: 'duration',
            header: 'Duration',
            align: 'right',
            width: '130px',
            cell: (incident) =>
                incident.resolved_at ? (
                    formatDurationSeconds(incident.duration_seconds)
                ) : incident.resolution ? (
                    `${formatDurationSeconds(incident.duration_seconds)} (${incident.resolution})`
                ) : (
                    <Tag tone="danger">Ongoing · {formatDurationSeconds(incident.duration_seconds)}</Tag>
                ),
        },
    ];

    return (
        <DataTable
            label="Incidents"
            columns={columns}
            rows={incidents}
            rowKey={(incident) => incident.id}
            empty={{
                title: 'No incidents',
                description: 'An incident opens when two checks in a row fail, and closes after two successes.',
                size: 'sm',
            }}
        />
    );
}

function MonitorDetail({ monitor }: { monitor: UptimeMonitor }) {
    const [range, setRange] = useState<UptimeWindow>('24h');
    const { data, loading } = useJson<MonitorData>(`/uptime/monitors/${monitor.id}/data?range=${range}`, { interval: 60_000 });
    const timeFormat = (value: number | string) => format(new Date(value), range === '24h' ? 'HH:mm' : range === '7d' ? 'MMM d HH:mm' : 'MMM d');

    return (
        <div className="grid gap-4">
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                {(['24h', '7d', '30d', '90d'] as const).map((window) => (
                    <div key={window} className="border-border bg-surface rounded-md border px-3 py-2">
                        <div className="text-fg-muted text-xs">Uptime · {window}</div>
                        <div className="text-lg font-medium">
                            <UptimeCell value={monitor.uptime?.[window]} />
                        </div>
                    </div>
                ))}
            </div>
            {monitor.tls_problem && (
                <p className="text-warning flex items-center gap-2 text-sm">
                    <ShieldAlert className="size-4" /> {tlsProblemLabel(monitor.tls_problem)}
                    {monitor.tls?.expires_at && <span className="text-fg-muted">· expires {format(new Date(monitor.tls.expires_at), 'PP')}</span>}
                </p>
            )}
            <MetricChart
                title="Response time"
                type="area"
                height={180}
                loading={loading && !data}
                data={(data?.series ?? []) as unknown as MetricPoint[]}
                series={[
                    { key: 'dns', label: 'DNS' },
                    { key: 'connect', label: 'Connect' },
                    { key: 'tls', label: 'TLS' },
                    { key: 'ttfb', label: 'Server' },
                ]}
                format={(value) => `${Math.round(value)} ms`}
                timeFormat={timeFormat}
                emptyText="No successful checks in this range"
                actions={
                    <Segmented<UptimeWindow>
                        label="Range"
                        value={range}
                        onValueChange={setRange}
                        options={(['24h', '7d', '30d', '90d'] as const).map((value) => ({ value, label: value }))}
                    />
                }
            />
            {data && (
                <div>
                    <UptimeBars days={data.days} />
                    <div className="text-fg-faint mt-1 flex justify-between text-xs">
                        <span>90 days ago</span>
                        <span>Today</span>
                    </div>
                </div>
            )}
            {data && <IncidentList incidents={data.incidents} />}
            {data && data.failures.length > 0 && (
                <details className="text-sm">
                    <summary className="text-fg-muted cursor-pointer">Recent failed checks ({data.failures.length})</summary>
                    <ul className="mt-2 grid gap-1 font-mono text-xs">
                        {data.failures.map((failure) => (
                            <li key={failure.at}>
                                {format(new Date(failure.at), 'MMM d HH:mm:ss')} · {failure.error ?? failure.error_kind}
                                {failure.ip ? ` · ${failure.ip}` : ''}
                                {failure.attempts > 1 ? ' · confirmed' : ''}
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </div>
    );
}

interface MonitorsViewProps {
    monitors: UptimeMonitor[];
    limits: UptimeLimits;
    canManage: boolean;
    /** Re-fetch after a change. */
    reload: () => void;
    siteId?: string | null;
    sites?: Array<{ id: string; name: string }>;
    showSite?: boolean;
    selected?: string | null;
}

/** Monitors table, with the selected monitor's charts, bars and incidents below. */
export function MonitorsView({
    monitors,
    limits,
    canManage,
    reload,
    siteId = null,
    sites = [],
    showSite = false,
    selected = null,
}: MonitorsViewProps) {
    const [current, setCurrent] = useState<string | null>(selected ?? monitors[0]?.id ?? null);
    const [editing, setEditing] = useState<UptimeMonitor | 'new' | null>(null);
    const [error, setError] = useState<string | null>(null);
    const active = monitors.find((monitor) => monitor.id === current) ?? monitors[0] ?? null;

    const act = async (url: string, method: 'POST' | 'DELETE') => {
        setError(null);
        try {
            await requestJson(url, method);
            reload();
        } catch (e) {
            setError(errorMessage(e));
        }
    };

    const actions = (monitor: UptimeMonitor): MenuAction[] =>
        canManage
            ? [
                  { label: 'Edit', icon: <Pencil />, onSelect: () => setEditing(monitor) },
                  monitor.paused
                      ? { label: 'Resume', icon: <Play />, onSelect: () => act(`/uptime/monitors/${monitor.id}/resume`, 'POST') }
                      : { label: 'Pause', icon: <Pause />, onSelect: () => act(`/uptime/monitors/${monitor.id}/pause`, 'POST') },
                  ...(monitor.source === 'user'
                      ? [
                            {
                                label: 'Delete',
                                icon: <Trash2 />,
                                danger: true,
                                onSelect: () => act(`/uptime/monitors/${monitor.id}`, 'DELETE'),
                            } as MenuAction,
                        ]
                      : []),
              ]
            : [];

    const columns: DataTableColumn<UptimeMonitor>[] = [
        {
            id: 'name',
            header: 'Monitor',
            sortValue: (monitor) => monitor.name,
            cell: (monitor) => (
                <span className="flex min-w-0 flex-col">
                    <span className="flex items-center gap-2 font-medium">
                        {monitor.name}
                        {monitor.source === 'auto' && <Tag>Site domain</Tag>}
                        {monitor.tls_problem && <ShieldAlert className="text-warning size-3.5" aria-label={tlsProblemLabel(monitor.tls_problem)} />}
                    </span>
                    <span className="text-fg-muted truncate font-mono text-xs">
                        {monitor.target} · {intervalLabel(monitor.interval_seconds)}
                        {showSite && monitor.site_name ? ` · ${monitor.site_name}` : ''}
                    </span>
                </span>
            ),
        },
        {
            id: 'status',
            header: 'Status',
            width: '170px',
            sortValue: (monitor) => monitorStatus(monitor).label,
            cell: (monitor) => {
                const state = monitorStatus(monitor);

                return <StatusBadge status={state.status} label={state.label} />;
            },
        },
        {
            id: '24h',
            header: '24h',
            align: 'right',
            width: '80px',
            hideOnMobile: true,
            cell: (m) => <UptimeCell value={m.uptime?.['24h']} />,
            sortValue: (m) => m.uptime?.['24h'] ?? null,
        },
        {
            id: '30d',
            header: '30d',
            align: 'right',
            width: '80px',
            hideOnMobile: true,
            cell: (m) => <UptimeCell value={m.uptime?.['30d']} />,
            sortValue: (m) => m.uptime?.['30d'] ?? null,
        },
        {
            id: 'latency',
            header: 'Response',
            align: 'right',
            width: '110px',
            hideOnMobile: true,
            cell: (monitor) => (
                <span className="flex flex-col items-end">
                    <span
                        className={cn(
                            'tabular-nums',
                            monitor.last_total_ms !== null && monitor.last_total_ms >= monitor.slow_threshold_ms && 'text-warning',
                        )}
                    >
                        {monitor.last_total_ms !== null ? `${monitor.last_total_ms} ms` : '—'}
                    </span>
                    <RelativeTime value={monitor.last_checked_at} className="text-fg-faint text-xs" />
                </span>
            ),
        },
    ];

    return (
        <div className="grid gap-6">
            <Section
                title="Monitors"
                description="Checked from the control plane. An incident opens after two failed checks in a row, each confirmed by a second attempt."
                bare
                aside={
                    canManage && (
                        <Button size="sm" icon={<Plus />} onClick={() => setEditing('new')}>
                            Add monitor
                        </Button>
                    )
                }
            >
                <DataTable
                    label="Monitors"
                    columns={columns}
                    rows={monitors}
                    rowKey={(monitor) => monitor.id}
                    rowActions={canManage ? actions : undefined}
                    onRowClick={(monitor) => setCurrent(monitor.id)}
                    empty={{
                        icon: <Activity />,
                        title: 'No monitors yet',
                        description: 'Site domains get a monitor on their own; add one for any public URL, TCP port or TLS endpoint.',
                    }}
                />
                {error && <p className="text-danger mt-2 text-sm">{error}</p>}
            </Section>
            {active && (
                <Section title={active.name} description={active.target} bare>
                    <MonitorDetail key={active.id} monitor={active} />
                </Section>
            )}
            {editing && (
                <MonitorForm
                    monitor={editing === 'new' ? null : editing}
                    limits={limits}
                    siteId={siteId}
                    sites={siteId ? [] : sites}
                    onClose={() => setEditing(null)}
                    onSaved={(monitor) => {
                        setEditing(null);
                        setCurrent(monitor.id);
                        reload();
                    }}
                />
            )}
        </div>
    );
}

export function MonitorsLoading() {
    return <SkeletonRows rows={4} />;
}
