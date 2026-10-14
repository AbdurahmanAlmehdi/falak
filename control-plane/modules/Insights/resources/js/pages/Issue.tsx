import { Avatar } from '@/components/falak/avatar';
import { Button } from '@/components/falak/button';
import { CodeBlock } from '@/components/falak/code-block';
import { DataTable, type DataTableColumn } from '@/components/falak/data-table';
import { Field } from '@/components/falak/field';
import { Textarea } from '@/components/falak/input';
import { KeyValue } from '@/components/falak/key-value';
import { MetricChart, type MetricPoint } from '@/components/falak/metric-chart';
import { RelativeTime } from '@/components/falak/relative-time';
import { Select } from '@/components/falak/select';
import { StatusBadge } from '@/components/falak/status';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/falak/tabs';
import { Tag } from '@/components/falak/tag';
import ObservabilityLayout from '@/layouts/observability-layout';
import { Link, router, useForm } from '@inertiajs/react';
import { format } from 'date-fns';
import { Activity, ArrowLeft, CheckCircle2, ExternalLink, EyeOff, RotateCcw, ScrollText, Trash2 } from 'lucide-react';
import { useState, type FormEventHandler } from 'react';
import { TraceTimelineCard } from '../../../../Telemetry/resources/js/components/trace-waterfall';
import {
    formatCount,
    formatMs,
    formatMsShort,
    IssueKindTag,
    IssueStatusBadge,
    priorityLabel,
    PriorityTag,
    StackTraceView,
} from '../components/insights-ui';
import { Block, timeFormatFor } from '../components/overview-parts';
import { type Frame, type IssuePriority, type IssueStatus, type IssueSummary, type Member } from '../types';

interface IssueDetail extends IssueSummary {
    exception_type: string | null;
    event_type: string | null;
    unhandled_occurrences: number;
    regressions: number;
    regressed_at: string | null;
    resolved_at: string | null;
    resolved_by: string | null;
    server_id: string | null;
    sample: {
        message?: string;
        stacktrace?: string | null;
        route_or_name?: string | null;
        handled?: boolean;
        span_id?: string | null;
        at?: string;
    } | null;
    meta: Record<string, string | number | null> | null;
    frames: Frame[];
    trace_id: string | null;
}

interface Occurrence {
    id: number;
    at: string;
    message: string;
    handled: boolean;
    route_or_name: string | null;
    server_id: string | null;
    trace_id: string | null;
    trace_url: string | null;
}

interface ActivityEntry {
    id: string;
    type: string;
    user: string | null;
    data: Record<string, unknown> | null;
    at: string;
}

interface Comment {
    id: string;
    body: string;
    user: string;
    at: string;
    can_delete: boolean;
}

interface Props {
    issue: IssueDetail;
    occurrences: Occurrence[];
    timeline: { t: string; value: number | null; secondary?: number | null }[];
    monitor: {
        id: string;
        job: string;
        schedule: string | null;
        timezone: string;
        last_status: string | null;
        last_run_at: string | null;
        next_expected_at: string | null;
        runs: { status: string; exit_code: number | null; duration_ms: number | null; scheduled_at: string; at: string }[];
    } | null;
    activity: ActivityEntry[];
    comments: Comment[];
    members: Member[];
    links: { trace: string | null; traceLogs: string | null; grafanaTrace: string | null; siteLogs: string | null };
    priorities: IssuePriority[];
    can: { manage: boolean };
}

const UNASSIGNED = '__none__';

function describeActivity(entry: ActivityEntry, members: Member[]): string {
    const who = entry.user ?? 'Falak';

    switch (entry.type) {
        case 'opened':
            return 'Issue first seen';
        case 'regressed':
            return 'Regressed — occurred again after being resolved';
        case 'resolved':
            return entry.data?.automatic ? 'Resolved automatically (recovered)' : `${who} resolved the issue`;
        case 'ignored':
            return `${who} ignored the issue`;
        case 'reopened':
            return `${who} reopened the issue`;
        case 'assigned':
            return `${who} assigned ${members.find((m) => m.id === entry.data?.assignee_id)?.name ?? 'a former member'}`;
        case 'unassigned':
            return `${who} removed the assignee`;
        case 'priority':
            return `${who} set priority to ${priorityLabel(String(entry.data?.to ?? 'none') as IssuePriority).toLowerCase()}`;
        case 'commented':
            return `${who} commented`;
        case 'comment_deleted':
            return `${who} deleted a comment`;
        default:
            return entry.type.replace(/_/g, ' ');
    }
}

export default function Issue({ issue, occurrences, timeline, monitor, activity, comments, members, links, priorities, can }: Props) {
    const comment = useForm({ body: '' });
    const [pending, setPending] = useState<IssueStatus | null>(null);
    const options = { preserveScroll: true };
    const performance = issue.kind === 'performance';

    const setStatus = (status: IssueStatus) => {
        setPending(status);
        router.put(route('insights.issues.status', issue.id), { status }, { ...options, onFinish: () => setPending(null) });
    };
    const setAssignee = (value: string) =>
        router.put(route('insights.issues.assign', issue.id), { assignee_id: value === UNASSIGNED ? null : value }, options);
    const setPriority = (priority: string) => router.put(route('insights.issues.priority', issue.id), { priority }, options);

    const submitComment: FormEventHandler = (event) => {
        event.preventDefault();
        comment.post(route('insights.issues.comments.store', issue.id), { ...options, onSuccess: () => comment.reset() });
    };

    const occurrenceColumns: DataTableColumn<Occurrence>[] = [
        {
            id: 'at',
            header: 'When',
            width: '140px',
            cell: (row) => (
                <span className="flex items-center gap-2">
                    <RelativeTime value={row.at} className="text-fg-muted text-xs whitespace-nowrap" />
                    {!row.handled && <Tag tone="danger">Unhandled</Tag>}
                </span>
            ),
        },
        {
            id: 'route',
            header: 'Route / job',
            cell: (row) => <span className="text-fg-muted block max-w-56 truncate font-mono text-xs">{row.route_or_name ?? '—'}</span>,
            hideOnMobile: true,
        },
        {
            id: 'message',
            header: 'Message',
            cell: (row) => (
                <span className="block max-w-80 truncate text-xs" title={row.message}>
                    {row.message}
                </span>
            ),
        },
        {
            id: 'trace',
            header: 'Trace',
            align: 'right',
            cell: (row) =>
                row.trace_url ? (
                    <Link
                        href={row.trace_url}
                        className="text-fg-muted hover:text-primary font-mono text-xs"
                        onClick={(event) => event.stopPropagation()}
                    >
                        {row.trace_id?.slice(0, 8)}
                    </Link>
                ) : (
                    <span className="text-fg-faint">—</span>
                ),
        },
    ];

    const headerActions = can.manage && (
        <div className="flex flex-wrap gap-2">
            {issue.status !== 'resolved' && (
                <Button variant="primary" icon={<CheckCircle2 />} loading={pending === 'resolved'} onClick={() => setStatus('resolved')}>
                    Resolve
                </Button>
            )}
            {issue.status !== 'ignored' && (
                <Button icon={<EyeOff />} loading={pending === 'ignored'} onClick={() => setStatus('ignored')}>
                    Ignore
                </Button>
            )}
            {issue.status !== 'open' && (
                <Button icon={<RotateCcw />} loading={pending === 'open'} onClick={() => setStatus('open')}>
                    Reopen
                </Button>
            )}
        </div>
    );

    return (
        <ObservabilityLayout
            tab="issues"
            title={`${issue.title} · Issues`}
            breadcrumbs={[
                { title: issue.title.length > 40 ? `${issue.title.slice(0, 40)}…` : issue.title, href: `/observability/issues/${issue.id}` },
            ]}
            header={
                <div className="grid gap-3">
                    <Link href="/observability/issues" className="text-fg-muted hover:text-fg inline-flex w-fit items-center gap-1 text-xs">
                        <ArrowLeft className="size-3.5" /> Issues
                    </Link>
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="grid min-w-0 gap-2">
                            <div className="flex flex-wrap items-center gap-1.5">
                                <IssueStatusBadge status={issue.status} />
                                <IssueKindTag kind={issue.kind} />
                                {issue.handled === false && <Tag tone="danger">Unhandled</Tag>}
                                <PriorityTag priority={issue.priority} />
                                {issue.regressions > 0 && <Tag tone="warning">Regressed {issue.regressions}×</Tag>}
                            </div>
                            <h1 className="text-fg text-lg font-semibold break-words">{issue.title}</h1>
                            {issue.culprit && <p className="text-fg-muted font-mono text-xs break-all">{issue.culprit}</p>}
                            <p className="text-fg-faint flex flex-wrap items-center gap-x-1.5 text-xs">
                                {issue.site_id ? (
                                    <Link href={`/observability?site=${issue.site_id}`} className="text-fg-muted hover:text-fg">
                                        {issue.site_name}
                                    </Link>
                                ) : (
                                    <span>Server-level task</span>
                                )}
                                <span aria-hidden>·</span> first seen <RelativeTime value={issue.first_seen_at} />
                                <span aria-hidden>·</span> last seen <RelativeTime value={issue.last_seen_at} />
                                {issue.resolved_at && (
                                    <>
                                        <span aria-hidden>·</span> resolved <RelativeTime value={issue.resolved_at} />
                                        {issue.resolved_by && ` by ${issue.resolved_by}`}
                                    </>
                                )}
                            </p>
                        </div>
                        {headerActions}
                    </div>
                </div>
            }
        >
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_18rem]">
                <div className="grid min-w-0 grid-cols-1 content-start gap-6">
                    <MetricChart
                        title={performance ? 'p95 · last 24 hours' : 'Occurrences · last 24 hours'}
                        type={performance ? 'line' : 'bar'}
                        data={timeline as unknown as MetricPoint[]}
                        series={
                            performance
                                ? [
                                      { key: 'value', label: 'p95' },
                                      { key: 'secondary', label: 'max' },
                                  ]
                                : [{ key: 'value', label: 'Occurrences' }]
                        }
                        format={performance ? formatMsShort : (value) => formatCount(Math.round(value))}
                        height={150}
                        timeFormat={timeFormatFor('24h')}
                    />

                    <Tabs defaultValue={issue.kind === 'exception' ? 'stack' : monitor ? 'runs' : performance ? 'details' : 'activity'}>
                        <TabsList>
                            {issue.kind === 'exception' && <TabsTrigger value="stack">Stack trace</TabsTrigger>}
                            {performance && <TabsTrigger value="details">Threshold</TabsTrigger>}
                            {monitor && <TabsTrigger value="runs">Runs</TabsTrigger>}
                            {occurrences.length > 0 && (
                                <TabsTrigger value="occurrences" badge={occurrences.length}>
                                    Occurrences
                                </TabsTrigger>
                            )}
                            {issue.trace_id && <TabsTrigger value="trace">Trace</TabsTrigger>}
                            <TabsTrigger value="activity" badge={comments.length > 0 ? comments.length : undefined}>
                                Activity
                            </TabsTrigger>
                        </TabsList>

                        {issue.kind === 'exception' && (
                            <TabsContent value="stack" className="grid gap-3 pt-4">
                                {issue.sample?.message && (
                                    <div className="border-danger/30 bg-danger-soft rounded-lg border px-3 py-2">
                                        {issue.exception_type && <p className="text-danger font-mono text-xs font-medium">{issue.exception_type}</p>}
                                        <p className="text-fg font-mono text-sm break-words whitespace-pre-wrap">{issue.sample.message}</p>
                                    </div>
                                )}
                                <StackTraceView frames={issue.frames} raw={issue.sample?.stacktrace ?? null} />
                            </TabsContent>
                        )}

                        {performance && issue.meta && (
                            <TabsContent value="details" className="pt-4">
                                <KeyValue
                                    columns={2}
                                    items={[
                                        { label: 'Name', value: String(issue.meta.name), mono: true },
                                        { label: 'Metric', value: String(issue.meta.metric ?? '—') },
                                        {
                                            label: 'Last breach',
                                            value: `${formatMs(Number(issue.meta.value_ms))} over ${String(issue.meta.window_minutes)} min`,
                                        },
                                        {
                                            label: 'Limit',
                                            value: `${formatMs(Number(issue.meta.threshold_ms))} (${formatCount(Number(issue.meta.count ?? 0))} samples)`,
                                        },
                                    ]}
                                />
                            </TabsContent>
                        )}

                        {monitor && (
                            <TabsContent value="runs" className="grid gap-3 pt-4">
                                <p className="text-fg-muted text-xs">
                                    <span className="text-fg font-mono">{monitor.job}</span> · schedule{' '}
                                    <span className="font-mono">{monitor.schedule ?? 'unknown'}</span> ({monitor.timezone}) · last run{' '}
                                    <RelativeTime value={monitor.last_run_at} fallback="never" />
                                    {monitor.next_expected_at && ` · next expected ${format(new Date(monitor.next_expected_at), 'PPp')}`}
                                </p>
                                <DataTable
                                    label="Recent runs"
                                    rows={monitor.runs}
                                    rowKey={(run) => run.scheduled_at}
                                    columns={[
                                        {
                                            id: 'scheduled',
                                            header: 'Scheduled',
                                            cell: (run) => <span className="text-xs">{format(new Date(run.scheduled_at), 'PP HH:mm')}</span>,
                                        },
                                        {
                                            id: 'status',
                                            header: 'Status',
                                            cell: (run) => (
                                                <StatusBadge
                                                    status={run.status === 'finished' ? 'succeeded' : run.status === 'skipped' ? 'skipped' : 'failed'}
                                                    label={run.status}
                                                />
                                            ),
                                        },
                                        { id: 'exit', header: 'Exit', align: 'right', cell: (run) => run.exit_code ?? '—' },
                                        { id: 'duration', header: 'Duration', align: 'right', cell: (run) => formatMs(run.duration_ms) },
                                    ]}
                                    empty={{ title: 'No runs recorded', size: 'sm' }}
                                />
                            </TabsContent>
                        )}

                        {occurrences.length > 0 && (
                            <TabsContent value="occurrences" className="pt-4">
                                <DataTable
                                    label="Recent occurrences"
                                    columns={occurrenceColumns}
                                    rows={occurrences}
                                    rowKey={(row) => String(row.id)}
                                    onRowClick={(row) => row.trace_url && router.visit(row.trace_url)}
                                />
                            </TabsContent>
                        )}

                        {issue.trace_id && (
                            <TabsContent value="trace" className="pt-4">
                                <TraceTimelineCard
                                    traceId={issue.trace_id}
                                    title="Latest occurrence"
                                    highlightSpanId={issue.sample?.span_id ?? null}
                                />
                            </TabsContent>
                        )}

                        <TabsContent value="activity" className="grid gap-4 pt-4">
                            <ol className="grid gap-0">
                                {[
                                    ...activity.map((entry) => ({ kind: 'activity' as const, at: entry.at, entry })),
                                    ...comments.map((entry) => ({ kind: 'comment' as const, at: entry.at, entry })),
                                ]
                                    .sort((a, b) => a.at.localeCompare(b.at))
                                    .map((item) =>
                                        item.kind === 'comment' ? (
                                            <li key={`c${item.entry.id}`} className="border-border relative ml-2 border-l py-2 pl-5">
                                                <span className="absolute top-3 -left-3">
                                                    <Avatar name={item.entry.user} size="sm" />
                                                </span>
                                                <div className="border-border bg-surface-1 rounded-lg border px-3 py-2">
                                                    <div className="mb-1 flex items-center justify-between gap-2 text-xs">
                                                        <span className="text-fg font-medium">{item.entry.user}</span>
                                                        <span className="text-fg-faint flex items-center gap-1">
                                                            <RelativeTime value={item.entry.at} />
                                                            {item.entry.can_delete && (
                                                                <button
                                                                    type="button"
                                                                    aria-label="Delete comment"
                                                                    className="hover:text-danger rounded-sm p-0.5"
                                                                    onClick={() =>
                                                                        router.delete(
                                                                            route('insights.issues.comments.destroy', [issue.id, item.entry.id]),
                                                                            options,
                                                                        )
                                                                    }
                                                                >
                                                                    <Trash2 className="size-3.5" />
                                                                </button>
                                                            )}
                                                        </span>
                                                    </div>
                                                    <p className="text-fg text-sm whitespace-pre-wrap">{item.entry.body}</p>
                                                </div>
                                            </li>
                                        ) : (
                                            <li
                                                key={`a${item.entry.id}`}
                                                className="border-border relative ml-2 flex items-center gap-2 border-l py-1.5 pl-5 text-xs"
                                            >
                                                <span
                                                    className="bg-border-strong absolute top-1/2 -left-[3.5px] size-1.5 -translate-y-1/2 rounded-full"
                                                    aria-hidden
                                                />
                                                <span className="text-fg-muted">{describeActivity(item.entry, members)}</span>
                                                <RelativeTime value={item.entry.at} className="text-fg-faint" />
                                            </li>
                                        ),
                                    )}
                            </ol>
                            {can.manage && (
                                <form onSubmit={submitComment} className="grid gap-2">
                                    <Field label="Comment" error={comment.errors.body}>
                                        <Textarea
                                            value={comment.data.body}
                                            onChange={(event) => comment.setData('body', event.target.value)}
                                            placeholder="Share findings, link a fix, @ a teammate…"
                                            rows={3}
                                        />
                                    </Field>
                                    <div>
                                        <Button
                                            type="submit"
                                            size="sm"
                                            variant="primary"
                                            loading={comment.processing}
                                            disabled={comment.data.body.trim() === ''}
                                        >
                                            Comment
                                        </Button>
                                    </div>
                                </form>
                            )}
                        </TabsContent>
                    </Tabs>
                </div>

                <aside className="grid content-start gap-4">
                    <Block title="Details">
                        <div className="grid gap-4 p-4">
                            <div className="grid grid-cols-2 gap-3">
                                <div className="grid gap-0.5">
                                    <span className="text-fg-faint text-xs">Events</span>
                                    <span className="text-fg tabular text-lg font-semibold">{formatCount(issue.occurrences)}</span>
                                </div>
                                <div className="grid gap-0.5">
                                    <span className="text-fg-faint text-xs">Users</span>
                                    <span className="text-fg tabular text-lg font-semibold">{formatCount(issue.affected_users)}</span>
                                </div>
                                {issue.kind === 'exception' && (
                                    <div className="col-span-2 grid gap-0.5">
                                        <span className="text-fg-faint text-xs">Unhandled</span>
                                        <span className={issue.unhandled_occurrences > 0 ? 'text-danger tabular text-sm' : 'text-fg tabular text-sm'}>
                                            {formatCount(issue.unhandled_occurrences)} of {formatCount(issue.occurrences)}
                                        </span>
                                    </div>
                                )}
                            </div>
                            <Field label="Assignee">
                                {can.manage ? (
                                    <Select
                                        value={issue.assignee?.id ?? UNASSIGNED}
                                        onValueChange={setAssignee}
                                        options={[
                                            { value: UNASSIGNED, label: 'Unassigned' },
                                            ...members.map((member) => ({
                                                value: member.id,
                                                label: member.name,
                                                icon: <Avatar name={member.name} size="xs" />,
                                            })),
                                        ]}
                                    />
                                ) : (
                                    <p className="text-fg text-sm">{issue.assignee?.name ?? 'Unassigned'}</p>
                                )}
                            </Field>
                            <Field label="Priority">
                                {can.manage ? (
                                    <Select
                                        value={issue.priority}
                                        onValueChange={setPriority}
                                        options={priorities.map((priority) => ({ value: priority, label: priorityLabel(priority) }))}
                                    />
                                ) : (
                                    <p className="text-fg text-sm">{priorityLabel(issue.priority)}</p>
                                )}
                            </Field>
                        </div>
                    </Block>
                    {(links.trace || links.traceLogs || links.siteLogs || links.grafanaTrace) && (
                        <Block title="Investigate">
                            <div className="grid gap-1 p-2">
                                {links.trace && (
                                    <Button asChild variant="ghost" className="justify-start">
                                        <Link href={links.trace}>
                                            <Activity /> Latest trace
                                        </Link>
                                    </Button>
                                )}
                                {links.traceLogs && (
                                    <Button asChild variant="ghost" className="justify-start">
                                        <Link href={links.traceLogs}>
                                            <ScrollText /> Logs for the trace
                                        </Link>
                                    </Button>
                                )}
                                {links.siteLogs && (
                                    <Button asChild variant="ghost" className="justify-start">
                                        <Link href={links.siteLogs}>
                                            <ScrollText /> Site logs around last occurrence
                                        </Link>
                                    </Button>
                                )}
                                {links.grafanaTrace && (
                                    <Button asChild variant="ghost" className="justify-start">
                                        <a href={links.grafanaTrace} target="_blank" rel="noreferrer">
                                            <ExternalLink /> Open in Grafana
                                        </a>
                                    </Button>
                                )}
                            </div>
                        </Block>
                    )}
                    {issue.sample && (issue.sample.route_or_name || issue.server_id) && (
                        <Block title="Latest sample">
                            <div className="p-4">
                                <KeyValue
                                    items={[
                                        ...(issue.sample.route_or_name
                                            ? [{ label: 'Route / job', value: issue.sample.route_or_name, mono: true }]
                                            : []),
                                        ...(issue.event_type ? [{ label: 'Event', value: issue.event_type }] : []),
                                        ...(issue.sample.at ? [{ label: 'At', value: format(new Date(issue.sample.at), 'PP HH:mm:ss') }] : []),
                                        ...(issue.trace_id ? [{ label: 'Trace', value: issue.trace_id, mono: true, copy: issue.trace_id }] : []),
                                    ]}
                                />
                            </div>
                        </Block>
                    )}
                    {issue.meta && (issue.kind === 'heartbeat' || issue.kind === 'database' || issue.kind === 'security') && (
                        <Block title="Context">
                            <div className="p-3">
                                <CodeBlock
                                    code={JSON.stringify(issue.kind === 'database' ? { ...issue.meta, ...issue.sample } : issue.meta, null, 2)}
                                    maxHeight={issue.kind === 'database' ? 400 : 200}
                                />
                            </div>
                        </Block>
                    )}
                </aside>
            </div>
        </ObservabilityLayout>
    );
}
