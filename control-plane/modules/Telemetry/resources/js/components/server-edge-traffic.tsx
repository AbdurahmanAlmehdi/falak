import { MetricChart, Segmented, type MetricSeries } from '@/components/falak';
import { useJson } from '@/hooks/use-json';
import { type ServerSectionProps } from '@/lib/registry';
import { format } from 'date-fns';
import { useMemo, useState } from 'react';
import { STATUS_CLASSES, statusPoints } from '../http-traffic';
import { type MetricSeriesDto } from '../types';

const RANGES = ['1h', '6h', '24h', '7d'] as const;
type Range = (typeof RANGES)[number];

/** Server page → Edge traffic: requests by status class over every site this server's edge serves, and per site. */
export default function ServerEdgeTraffic({ serverId }: ServerSectionProps) {
    const [range, setRange] = useState<Range>('1h');
    const { data } = useJson<{ charts: { requests?: MetricSeriesDto[]; sites?: MetricSeriesDto[] } }>(
        `/telemetry/servers/${encodeURIComponent(serverId)}/http/data?range=${range}`,
        { interval: 60_000, unwrap: false },
    );
    const requests = useMemo(() => statusPoints(data?.charts.requests), [data]);
    const sites = useMemo(
        () =>
            (data?.charts.sites ?? [])
                .map((s) => ({ name: s.labels.service_name ?? '?', value: [...s.points].reverse().find(([, v]) => v !== null)?.[1] ?? 0 }))
                .sort((a, b) => b.value - a.value)
                .slice(0, 10),
        [data],
    );

    if (data && requests.length === 0) return null; // no edge traffic (or an agent without HTTP metrics): nothing to show

    const series: MetricSeries[] = STATUS_CLASSES.map((c) => ({ key: c.key, label: c.label, color: c.color, mark: 'area', stack: 's' }));

    return (
        <section className="grid gap-2">
            <div className="flex items-center justify-between">
                <h2 className="text-fg text-sm font-medium">Edge traffic</h2>
                <Segmented label="Time range" value={range} onValueChange={setRange} options={RANGES.map((value) => ({ value, label: value }))} />
            </div>
            <div className="grid gap-3 lg:grid-cols-2">
                <MetricChart
                    title="Requests / s"
                    type="composed"
                    data={requests}
                    series={series}
                    loading={data === null}
                    format={(v) => v.toFixed(v >= 10 ? 0 : 2)}
                    timeFormat={(v) => format(new Date(typeof v === 'number' ? v * 1000 : v), range === '7d' ? 'MMM d' : 'HH:mm')}
                />
                <figure className="border-border bg-surface-1 grid content-start gap-1 rounded-lg border p-4 text-xs">
                    <figcaption className="text-fg-muted font-medium">Requests / s per site (now)</figcaption>
                    {sites.map((s) => (
                        <p key={s.name} className="flex justify-between">
                            <span className="font-mono">{s.name}</span>
                            <span className="tabular">{s.value.toFixed(2)}</span>
                        </p>
                    ))}
                </figure>
            </div>
        </section>
    );
}
