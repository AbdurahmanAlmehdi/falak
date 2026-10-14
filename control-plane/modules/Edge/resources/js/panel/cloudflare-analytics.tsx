import { Button, Callout, MetricChart, RelativeTime, Section, SkeletonRows } from '@/components/falak';
import { Stat } from '@/components/falak/stat';
import { useJson } from '@/hooks/use-json';
import { errorMessage, requestJson } from '@/lib/http';
import { type ServiceTabProps } from '@/lib/registry';
import { formatBytes } from '@/lib/utils';
import { RotateCw } from 'lucide-react';
import { useState } from 'react';
import {
    actionLabel,
    countryRows,
    dailyTotals,
    formatCount,
    formatPercent,
    hourlyPoints,
    statusRows,
    type CloudflareAnalyticsData,
    type CloudflareZoneReport,
} from '../cloudflare-analytics';

const url = (siteId: string) => `/sites/${siteId}/cloudflare/analytics`;

/** A labelled share bar (countries, status classes): text in text tokens, the bar only carries magnitude. */
function ShareRow({ label, value, share }: { label: string; value: string; share: number }) {
    return (
        <li className="grid gap-1">
            <div className="flex items-baseline justify-between gap-3 text-sm">
                <span className="text-fg truncate">{label}</span>
                <span className="text-fg-muted tabular text-xs">{value}</span>
            </div>
            <div className="bg-surface-2 h-1.5 overflow-hidden rounded-full" aria-hidden>
                <div className="h-full rounded-full" style={{ width: `${Math.max(1, Math.round(share * 100))}%`, background: 'var(--chart-1)' }} />
            </div>
        </li>
    );
}

function ZoneReport({ report, single }: { report: CloudflareZoneReport; single: boolean }) {
    const traffic = report.traffic;
    const points = hourlyPoints(traffic);
    const countries = countryRows(traffic);
    const statuses = statusRows(traffic);
    const week = dailyTotals(report.daily);

    return (
        <div className="grid gap-4">
            {!single && (
                <p className="text-fg-muted text-sm">
                    <span className="text-fg font-medium">{report.zone}</span> · {report.hosts.join(', ')}
                </p>
            )}
            {report.error ? (
                <Callout
                    tone={report.permission_missing ? 'warning' : 'danger'}
                    title={report.permission_missing ? 'Analytics permission missing' : 'Cloudflare did not answer'}
                >
                    {report.error}
                </Callout>
            ) : (
                <>
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <Stat
                            label="Requests (24 h)"
                            value={formatCount(traffic?.requests)}
                            hint={traffic ? `${formatCount(traffic.visits)} visits` : undefined}
                        />
                        <Stat
                            label="Served from cache"
                            value={formatPercent(traffic?.cached_ratio)}
                            hint={traffic ? `${formatCount(traffic.requests - traffic.cached_requests)} reached the origin` : undefined}
                        />
                        <Stat
                            label="Bandwidth"
                            value={traffic ? formatBytes(traffic.bytes) : '—'}
                            hint={traffic ? `${formatBytes(traffic.cached_bytes)} from cache` : undefined}
                        />
                        <Stat
                            label="Threats stopped"
                            value={formatCount(report.threats?.mitigated)}
                            hint={report.threats ? `${formatCount(report.threats.events)} firewall events` : 'Not available'}
                        />
                    </div>
                    {traffic && (
                        <MetricChart
                            title="Requests per hour"
                            type="bar"
                            data={points}
                            series={[
                                { key: 'cached', label: 'From cache' },
                                { key: 'origin', label: 'From origin' },
                            ]}
                            format={(value) => formatCount(value)}
                            emptyText="No requests in this window."
                            height={160}
                        />
                    )}
                    {traffic && (countries.length > 0 || statuses.length > 0) && (
                        <div className="grid gap-6 sm:grid-cols-2">
                            {countries.length > 0 && (
                                <div className="grid content-start gap-2">
                                    <h3 className="text-fg-muted text-xs font-medium">Top countries</h3>
                                    <ul className="grid gap-2" aria-label="Top countries">
                                        {countries.map((row) => (
                                            <ShareRow
                                                key={row.country}
                                                label={row.country}
                                                value={`${formatCount(row.requests)} · ${formatPercent(row.share)}`}
                                                share={row.share}
                                            />
                                        ))}
                                    </ul>
                                </div>
                            )}
                            {statuses.length > 0 && (
                                <div className="grid content-start gap-2">
                                    <h3 className="text-fg-muted text-xs font-medium">Responses</h3>
                                    <ul className="grid gap-2" aria-label="Responses by status class">
                                        {statuses.map((row) => (
                                            <ShareRow
                                                key={row.label}
                                                label={row.label}
                                                value={`${formatCount(row.requests)} · ${formatPercent(row.share)}`}
                                                share={row.share}
                                            />
                                        ))}
                                    </ul>
                                </div>
                            )}
                        </div>
                    )}
                    {report.threats && report.threats.actions.length > 0 && (
                        <p className="text-fg-muted text-sm">
                            Firewall: {report.threats.actions.map((row) => `${actionLabel(row.action)} ${formatCount(row.events)}`).join(' · ')}
                            {report.threats.countries.length > 0 &&
                                ` (mostly from ${report.threats.countries.map((row) => row.country || 'unknown').join(', ')})`}
                        </p>
                    )}
                    {week && (
                        <p className="text-fg-muted text-sm">
                            Whole zone, last {report.daily?.length} days: {formatCount(week.requests)} requests, {formatPercent(week.cachedRatio)}{' '}
                            cached, {formatBytes(week.bytes)}, {formatCount(week.threats)} threats.
                        </p>
                    )}
                </>
            )}
            {report.notes.length > 0 && (
                <ul className="text-fg-faint grid gap-0.5 text-xs">
                    {report.notes.map((note) => (
                        <li key={note}>{note}</li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/**
 * Settings → Networking → Cloudflare traffic (docs/plans/network/tools.md part 2): the site's proxied names as
 * Cloudflare saw them over the last 24 h. Renders nothing for sites without a proxied name in a managed zone.
 */
export function CloudflareAnalytics({ ctx }: ServiceTabProps) {
    const siteId = ctx.service.ref_id;
    const { data, error, setData } = useJson<CloudflareAnalyticsData>(url(siteId));
    const [refreshing, setRefreshing] = useState(false);
    const [refreshError, setRefreshError] = useState<string | null>(null);

    if (!data) return error ? null : <SkeletonRows rows={3} />;
    if (data.zones.length === 0) return null;

    const refresh = async () => {
        setRefreshing(true);
        setRefreshError(null);
        try {
            const body = await requestJson<{ data: CloudflareAnalyticsData }>(`${url(siteId)}?refresh=1`);
            setData(body.data);
        } catch (e) {
            setRefreshError(errorMessage(e));
        } finally {
            setRefreshing(false);
        }
    };
    const fetchedAt = data.zones[0]?.fetched_at;

    return (
        <Section
            title="Cloudflare traffic"
            description={
                <>
                    Last 24 hours of {data.zones.flatMap((zone) => zone.hosts).join(', ')} at Cloudflare
                    {fetchedAt && (
                        <>
                            {' '}
                            · updated <RelativeTime value={fetchedAt} />
                        </>
                    )}
                    .
                </>
            }
            aside={
                <Button size="sm" icon={<RotateCw />} loading={refreshing} onClick={() => void refresh()}>
                    Refresh
                </Button>
            }
        >
            {refreshError && <Callout tone="danger">{refreshError}</Callout>}
            {data.error && data.zones.every((zone) => zone.error === data.error) ? (
                <Callout
                    tone={data.zones[0].permission_missing ? 'warning' : 'danger'}
                    title={data.zones[0].permission_missing ? 'Analytics permission missing' : 'Cloudflare did not answer'}
                >
                    {data.error}
                </Callout>
            ) : (
                data.zones.map((report) => <ZoneReport key={report.zone} report={report} single={data.zones.length === 1} />)
            )}
        </Section>
    );
}
