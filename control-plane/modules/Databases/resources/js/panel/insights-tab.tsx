import { Button, Callout, DataTable, Dialog, Section, Segmented, SkeletonRows, Tag, type DataTableColumn } from '@/components/falak';
import { useJson } from '@/hooks/use-json';
import { type ServiceTabProps } from '@/lib/registry';
import { Link } from '@inertiajs/react';
import { formatDistanceToNowStrict } from 'date-fns';
import { Gauge } from 'lucide-react';
import { useState } from 'react';
import {
    formatCount,
    formatMs,
    queryInsightsState,
    reportingNote,
    severityTone,
    sortRecommendations,
    sourceLabel,
    type DatabaseInsightsResponse,
    type DatabaseIssueRow,
    type TopQuery,
} from '../observability';
import { formatBytes, mutate, useDatabasePanel } from './api';

const RANGES = ['24h', '7d', '30d'] as const;
type Range = (typeof RANGES)[number];

const ago = (iso: string) => `${formatDistanceToNowStrict(new Date(iso))} ago`;

const QUERY_COLUMNS: DataTableColumn<TopQuery>[] = [
    {
        id: 'query',
        header: 'Query',
        cell: (row) => (
            <div className="grid min-w-0 gap-1">
                <code className="text-fg line-clamp-3 font-mono text-xs break-all whitespace-pre-wrap">{row.query}</code>
                <span className="text-fg-faint text-xs">
                    {[row.database, sourceLabel(row.source), row.no_index > 0 ? `${formatCount(row.no_index)} without an index` : null]
                        .filter(Boolean)
                        .join(' · ')}
                </span>
            </div>
        ),
    },
    { id: 'calls', header: 'Calls', align: 'right', cell: (row) => formatCount(row.calls), sortValue: (row) => row.calls },
    { id: 'total', header: 'Total', align: 'right', cell: (row) => formatMs(row.total_ms), sortValue: (row) => row.total_ms },
    { id: 'mean', header: 'Mean', align: 'right', cell: (row) => formatMs(row.mean_ms), sortValue: (row) => row.mean_ms },
    { id: 'max', header: 'Max', align: 'right', cell: (row) => formatMs(row.max_ms), sortValue: (row) => row.max_ms, hideOnMobile: true },
    { id: 'rows', header: 'Rows', align: 'right', cell: (row) => formatCount(row.rows), sortValue: (row) => row.rows, hideOnMobile: true },
];

const ISSUE_COLUMNS: DataTableColumn<DatabaseIssueRow>[] = [
    {
        id: 'title',
        header: 'Issue',
        cell: (row) => (
            <Link href={row.url} className="text-fg hover:text-primary grid min-w-0 gap-0.5">
                <span className="truncate text-sm">{row.title}</span>
                {row.culprit && <span className="text-fg-faint truncate text-xs">{row.culprit}</span>}
            </Link>
        ),
    },
    { id: 'occurrences', header: 'Events', align: 'right', cell: (row) => formatCount(row.occurrences), sortValue: (row) => row.occurrences },
    {
        id: 'last',
        header: 'Last seen',
        align: 'right',
        cell: (row) => ago(row.last_seen_at),
        sortValue: (row) => row.last_seen_at,
        hideOnMobile: true,
    },
];

/**
 * Database panel → Insights (docs/plans/OBSERVABILITY.md phase 3): query insights, the instance's open Issues (slow
 * query shapes, engine errors), top queries by total time and recommendations.
 */
export function DatabaseInsightsTab({ ctx }: ServiceTabProps) {
    const panel = useDatabasePanel(ctx);
    const [range, setRange] = useState<Range>('24h');
    const [confirming, setConfirming] = useState(false);
    const [enabling, setEnabling] = useState(false);
    const instance = panel.data?.instance;
    const { data, error, reload } = useJson<DatabaseInsightsResponse>(
        instance ? `/databases/instances/${instance.id}/insights?range=${range}` : null,
        {
            interval: 60_000,
            unwrap: false,
        },
    );

    if (!panel.data || !instance) return panel.error ? <p className="text-danger text-sm">{panel.error}</p> : <SkeletonRows rows={6} />;
    if (!data) return error ? <p className="text-danger text-sm">{error}</p> : <SkeletonRows rows={6} />;

    const note = reportingNote(data.reporting);
    const state = queryInsightsState(data.query_insights, data.engine);
    const recommendations = sortRecommendations(data.recommendations);
    const enable = async () => {
        setEnabling(true);
        try {
            await mutate('POST', `/databases/instances/${instance.id}/query-insights`, {}, { success: `Restarting ${instance.name} with query insights`, reload });
        } finally {
            // Success or not, the dialog closes and the card shows what the server says (pending, or why not).
            setEnabling(false);
            setConfirming(false);
        }
    };

    return (
        <div className="grid min-w-0 gap-6">
            {note && <Callout tone={data.reporting === 'stopped' ? 'info' : 'warning'}>{note}</Callout>}

            <Section
                title="Query insights"
                description={`Slow query threshold: ${formatMs(data.slow_query_ms)}${data.observed_at ? ` · last report ${ago(data.observed_at)}` : ''}`}
                aside={
                    panel.data.can.manage &&
                    data.query_insights.can_enable && (
                        <Button size="sm" onClick={() => setConfirming(true)}>
                            {data.query_insights.enabled ? 'Apply query insights now (restarts the database)' : 'Enable query insights (restarts the database)'}
                        </Button>
                    )
                }
            >
                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <Tag tone={state.tone}>{state.label}</Tag>
                    <span className="text-fg-muted">{state.text}</span>
                    {data.query_insights.pending && (
                        <Button size="sm" variant="ghost" onClick={() => void reload()}>
                            Refresh
                        </Button>
                    )}
                </div>
                {data.query_insights.blocked && data.query_insights.status !== 'on' && (
                    <p className="text-fg-muted mt-2 text-sm">{data.query_insights.blocked}</p>
                )}
            </Section>

            <Section title="Recommendations" description="From the latest report: tables, indexes, cache, memory, connections and disk growth.">
                {recommendations.length === 0 ? (
                    <p className="text-fg-muted text-sm">Nothing to recommend right now.</p>
                ) : (
                    <div className="grid gap-2">
                        {recommendations.map((item) => (
                            <Callout key={item.key} tone={severityTone(item.severity)} title={item.title}>
                                {item.detail}
                            </Callout>
                        ))}
                    </div>
                )}
            </Section>

            <Section title="Open issues" description="Slow query shapes and engine errors; assign, comment and resolve them in Issues.">
                <DataTable
                    label="Database issues"
                    columns={ISSUE_COLUMNS}
                    rows={data.issues}
                    rowKey={(row) => row.id}
                    empty={{ icon: <Gauge />, title: 'No open issues', description: 'Slow queries and engine errors show up here.', size: 'sm' }}
                />
            </Section>

            <Section
                title="Top queries"
                description="By total time. Query text is normalized: values never leave the server."
                aside={<Segmented label="Range" value={range} onValueChange={setRange} options={RANGES.map((value) => ({ value, label: value }))} />}
            >
                <DataTable
                    label="Top queries"
                    columns={QUERY_COLUMNS}
                    rows={data.top_queries}
                    rowKey={(row) => row.fingerprint}
                    defaultSort={{ column: 'total', direction: 'desc' }}
                    empty={{
                        title: 'No queries recorded in this range',
                        description:
                            data.query_insights.status === 'on'
                                ? 'They appear within five minutes of running.'
                                : 'Without query insights, only slow queries are listed.',
                        size: 'sm',
                    }}
                />
            </Section>

            {data.databases.length > 0 && (
                <Section title={data.engine === 'redis' || data.engine === 'valkey' ? 'Keyspaces' : 'Database sizes'}>
                    <ul className="grid gap-1 text-sm">
                        {data.databases.map((item) => (
                            <li key={item.name} className="flex justify-between gap-4">
                                <span className="text-fg font-mono">{item.name}</span>
                                <span className="text-fg-muted tabular">
                                    {item.size_bytes !== undefined ? formatBytes(item.size_bytes) : `${formatCount(item.keys ?? 0)} keys`}
                                </span>
                            </li>
                        ))}
                    </ul>
                </Section>
            )}

            <Dialog
                open={confirming}
                onOpenChange={setConfirming}
                title="Enable query insights?"
                description={
                    data.engine === 'postgresql'
                        ? `${instance.name} restarts to load pg_stat_statements: open connections are closed, and it is back in a few seconds. Its data stays as it is.`
                        : `${instance.name} restarts with performance_schema on (about 100 MB more memory): open connections are closed, and it is back in a few seconds.`
                }
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setConfirming(false)}>
                            Cancel
                        </Button>
                        <Button variant="primary" loading={enabling} onClick={() => void enable()}>
                            Restart and enable
                        </Button>
                    </>
                }
            />
        </div>
    );
}
