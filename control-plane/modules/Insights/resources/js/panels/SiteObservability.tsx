import { Button, IconButton } from '@/components/falak/button';
import { EmptyState } from '@/components/falak/empty-state';
import { MetricChart, type MetricPoint } from '@/components/falak/metric-chart';
import { Segmented } from '@/components/falak/segmented';
import { Skeleton } from '@/components/falak/skeleton';
import { Stat } from '@/components/falak/stat';
import { Link } from '@inertiajs/react';
import { RotateCw, SlidersHorizontal, TriangleAlert } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { getJson } from '../../../../Telemetry/resources/js/lib';
import { monitorState } from '../components/heartbeat-table';
import { formatCount, formatMs, formatPercent } from '../components/insights-ui';
import { Block, IssueList, TopList } from '../components/overview-parts';
import { type HeartbeatMonitor, type OverviewData } from '../types';

/** Chart ticks: time of day for short ranges, the date for multi-day ones. */
function chartTime(range: string): (value: number | string) => string {
    const days = range.endsWith('d') && range !== '1d';

    return (value) => new Date(value).toLocaleString(undefined, days ? { month: 'short', day: 'numeric' } : { hour: '2-digit', minute: '2-digit' });
}

/** GET /insights/sites/{siteId}/summary (Falak\Insights\Http\Controllers\OverviewController::summary). */
interface SiteSummary {
    site: { id: string; name: string };
    range: string;
    ranges: string[];
    overview: OverviewData;
    /** Requests and 5xx answers from the edge's access log when the app reports none (no APM package). */
    edge: { requests: number; errors: number } | null;
    open_issues: number;
    heartbeats: HeartbeatMonitor[];
    links: { overview: string; issues: string; heartbeats: string; thresholds: string; traces: string; slowTraces: string };
}

const RANGES = ['1h', '24h', '7d'] as const;
type Range = (typeof RANGES)[number];

export interface SiteObservabilityProps {
    siteId: string;
}

/**
 * Service panel → Observability tab (docs/UI_DESIGN.md §5.1): the site's open issues, slow routes/jobs/queries and
 * scheduled-task heartbeats, from Insights. Refreshes every minute.
 */
export default function SiteObservability({ siteId }: SiteObservabilityProps) {
    const [range, setRange] = useState<Range>('24h');
    const [data, setData] = useState<SiteSummary | null>(null);
    const [error, setError] = useState<unknown>(null);
    const [loading, setLoading] = useState(true);

    const load = useCallback(
        async (signal?: AbortSignal) => {
            setLoading(true);
            try {
                setData(await getJson<SiteSummary>(`/insights/sites/${encodeURIComponent(siteId)}/summary?range=${range}`, signal));
                setError(null);
            } catch (caught) {
                if (!signal?.aborted) setError(caught);
            } finally {
                if (!signal?.aborted) setLoading(false);
            }
        },
        [siteId, range],
    );

    useEffect(() => {
        const controller = new AbortController();
        void load(controller.signal);
        const timer = window.setInterval(() => void load(), 60_000);

        return () => {
            controller.abort();
            window.clearInterval(timer);
        };
    }, [load]);

    if (data === null) {
        if (error !== null) {
            return (
                <EmptyState
                    size="sm"
                    icon={<TriangleAlert />}
                    title="Couldn't load observability data"
                    description={error instanceof Error ? error.message : undefined}
                    action={
                        <Button size="sm" icon={<RotateCw />} onClick={() => void load()}>
                            Retry
                        </Button>
                    }
                />
            );
        }

        return (
            <div className="grid gap-3">
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    {Array.from({ length: 4 }, (_, index) => (
                        <Skeleton key={index} className="h-20" />
                    ))}
                </div>
                <Skeleton className="h-48" />
            </div>
        );
    }

    const { totals } = data.overview;
    // No requests from the app (no APM package): the edge's numbers, labelled, as the Metrics tab does.
    const edge = totals.requests === 0 ? data.edge : null;
    const requests = edge?.requests ?? totals.requests;
    const failed = edge?.errors ?? totals.request_errors;
    const errorRate = requests > 0 ? failed / requests : null;
    const exceptions = totals.exceptions_handled + totals.exceptions_unhandled;
    const quiet = requests + totals.jobs + exceptions === 0 && data.open_issues === 0 && data.heartbeats.length === 0;

    return (
        <div className="grid min-w-0 gap-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-3 text-xs">
                    <Link href={data.links.overview} className="text-fg-muted hover:text-fg">
                        Open in Observability
                    </Link>
                    <Link href={data.links.thresholds} className="text-fg-muted hover:text-fg inline-flex items-center gap-1">
                        <SlidersHorizontal className="size-3.5" /> Thresholds
                    </Link>
                </div>
                <div className="flex items-center gap-1">
                    <Segmented label="Time range" value={range} onValueChange={setRange} options={RANGES.map((value) => ({ value, label: value }))} />
                    <IconButton size="sm" label="Refresh" icon={<RotateCw />} loading={loading} onClick={() => void load()} />
                </div>
            </div>

            {quiet ? (
                <EmptyState
                    size="sm"
                    title="No application data from this site yet"
                    description="Install the Falak Insights package in the app and deploy: requests, jobs, queries, exceptions and scheduled tasks are reported through the Falak agent."
                />
            ) : (
                <>
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <Stat
                            label="Requests"
                            value={formatCount(requests)}
                            hint={edge ? 'from the edge' : `p95 ${formatMs(totals.request_p95_ms)}`}
                        />
                        <Stat
                            label="Error rate"
                            value={formatPercent(errorRate)}
                            tone={errorRate !== null && errorRate >= 0.01 ? 'danger' : undefined}
                            hint={`${formatCount(failed)} ${edge ? '5xx from the edge' : 'failed'}`}
                        />
                        <Stat
                            label="Exceptions"
                            value={formatCount(exceptions)}
                            tone={totals.exceptions_unhandled > 0 ? 'danger' : undefined}
                            hint={`${formatCount(totals.exceptions_unhandled)} unhandled`}
                        />
                        <Stat label="Open issues" value={formatCount(data.open_issues)} href={data.links.issues} hint="view all" />
                    </div>

                    {totals.requests > 0 && (
                        <div className="grid gap-3 lg:grid-cols-2">
                            <MetricChart
                                title="Requests"
                                type="area"
                                height={160}
                                data={data.overview.series as unknown as MetricPoint[]}
                                series={[
                                    { key: 'requests', label: 'Requests' },
                                    { key: 'request_errors', label: 'Failed' },
                                ]}
                                format={(value) => formatCount(Math.round(value))}
                                timeFormat={chartTime(range)}
                            />
                            <MetricChart
                                title="p95 latency"
                                height={160}
                                data={data.overview.series as unknown as MetricPoint[]}
                                series={[{ key: 'request_p95_ms', label: 'p95' }]}
                                format={(value) => formatMs(value)}
                                timeFormat={chartTime(range)}
                            />
                        </div>
                    )}

                    <Block
                        title="Issues"
                        aside={
                            <Link href={data.links.issues} className="text-fg-muted hover:text-fg">
                                View all
                            </Link>
                        }
                    >
                        <IssueList issues={data.overview.issues.slice(0, 5)} />
                    </Block>

                    <div className="grid gap-3 xl:grid-cols-2">
                        <Block
                            title="Slow routes"
                            aside={
                                <Link href={data.links.slowTraces} className="text-fg-muted hover:text-fg">
                                    Slow traces
                                </Link>
                            }
                        >
                            <TopList rows={data.overview.routes.slice(0, 5)} emphasis="p95" empty="No requests in this window." />
                        </Block>
                        <Block title="Slow queries">
                            <TopList rows={data.overview.queries.slice(0, 5)} emphasis="max" empty="No queries in this window." />
                        </Block>
                        <Block title="Jobs">
                            <TopList rows={data.overview.jobs.slice(0, 5)} emphasis="count" empty="No jobs ran in this window." />
                        </Block>
                        <Block
                            title="Scheduled tasks"
                            aside={
                                <Link href={data.links.heartbeats} className="text-fg-muted hover:text-fg">
                                    Heartbeats
                                </Link>
                            }
                        >
                            {data.heartbeats.length === 0 ? (
                                <p className="text-fg-faint px-4 py-6 text-center text-sm">No scheduled tasks have reported yet.</p>
                            ) : (
                                <ul className="divide-border divide-y">
                                    {data.heartbeats.map((monitor) => {
                                        const state = monitorState(monitor);

                                        return (
                                            <li key={monitor.id} className="flex min-w-0 items-center justify-between gap-3 px-4 py-2">
                                                <span className="grid min-w-0 gap-0.5">
                                                    <span className="text-fg truncate font-mono text-xs">{monitor.job}</span>
                                                    <span className="text-fg-faint text-2xs font-mono">{monitor.schedule ?? 'no schedule'}</span>
                                                </span>
                                                <span
                                                    className={
                                                        state.status === 'failed' ? 'text-danger shrink-0 text-xs' : 'text-fg-muted shrink-0 text-xs'
                                                    }
                                                >
                                                    {state.label}
                                                </span>
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                        </Block>
                    </div>
                </>
            )}
        </div>
    );
}
