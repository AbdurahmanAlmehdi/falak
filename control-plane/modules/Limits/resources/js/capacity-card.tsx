import { Callout, Section, SkeletonRows, Tag } from '@/components/falak';
import { memoryLabel } from '@/components/limits-fields';
import { useJson } from '@/hooks/use-json';
import { Link } from '@inertiajs/react';

/** GET /servers/{server}/capacity. */
interface CapacityData {
    server: { id: string; name: string; memory_mb: number | null; cpus: number | null };
    totals: { memory_limit_mb: number; memory_reservation_mb: number; cpus: number };
    unlimited: { memory: number; cpus: number };
    overcommitted: { memory: boolean; reservations: boolean; cpus: boolean };
    items: {
        kind: string;
        id: string;
        name: string;
        memory_limit_mb: number | null;
        memory_reservation_mb: number | null;
        cpus: number | null;
        url: string | null;
    }[];
    warnings: string[];
}

const KINDS: Record<string, string> = {
    site: 'Site',
    compose_service: 'Compose service',
    worker: 'Worker',
    daemon: 'Daemon',
    database: 'Database',
    function: 'Function',
    sandbox: 'Sandbox',
};

function Meter({
    label,
    used,
    total,
    unit,
    over,
}: {
    label: string;
    used: number;
    total: number | null;
    unit: (n: number) => string;
    over: boolean;
}) {
    const percent = total ? Math.min(100, Math.round((used / total) * 100)) : 0;

    return (
        <div className="grid gap-1.5">
            <div className="flex items-baseline justify-between gap-3 text-xs">
                <span className="text-fg-muted">{label}</span>
                <span className={`tabular font-mono ${over ? 'text-warning' : 'text-fg'}`}>
                    {unit(used)}
                    {total !== null && <span className="text-fg-faint"> / {unit(total)}</span>}
                </span>
            </div>
            <div
                className="bg-surface-2 h-1.5 overflow-hidden rounded-full"
                role="meter"
                aria-label={label}
                aria-valuenow={used}
                aria-valuemin={0}
                aria-valuemax={total ?? undefined}
            >
                <div className={`h-full rounded-full ${over ? 'bg-warning' : 'bg-primary'}`} style={{ width: `${percent}%` }} />
            </div>
        </div>
    );
}

/**
 * Server page: what every service on the server may use (memory limits and reservations, CPU limits) against what
 * the server has, with a warning when it is overcommitted.
 */
export function CapacityCard({ serverId }: { serverId: string }) {
    const { data } = useJson<CapacityData>(`/servers/${serverId}/capacity`, { interval: 60000 });

    if (!data) return <SkeletonRows rows={2} />;
    if (data.items.length === 0) return null;

    const cores = (n: number) => `${Number(n.toFixed(2))} ${n === 1 ? 'CPU' : 'CPUs'}`;

    return (
        <Section
            title="Capacity"
            description="What the services on this server may use, against what it has."
            aside={
                (data.overcommitted.memory || data.overcommitted.cpus || data.overcommitted.reservations) && <Tag tone="warning">Overcommitted</Tag>
            }
        >
            <div className="grid gap-4 sm:grid-cols-3">
                <Meter
                    label="Memory limits"
                    used={data.totals.memory_limit_mb}
                    total={data.server.memory_mb}
                    unit={memoryLabel}
                    over={data.overcommitted.memory}
                />
                <Meter
                    label="Memory reserved"
                    used={data.totals.memory_reservation_mb}
                    total={data.server.memory_mb}
                    unit={memoryLabel}
                    over={data.overcommitted.reservations}
                />
                <Meter label="CPU limits" used={data.totals.cpus} total={data.server.cpus} unit={cores} over={data.overcommitted.cpus} />
            </div>
            {data.warnings.length > 0 && (
                <Callout tone={data.overcommitted.memory || data.overcommitted.reservations || data.overcommitted.cpus ? 'warning' : 'info'}>
                    <ul className="grid gap-1">
                        {data.warnings.map((warning) => (
                            <li key={warning}>{warning}</li>
                        ))}
                    </ul>
                </Callout>
            )}
            <table className="w-full text-sm" aria-label="Limits per service">
                <thead>
                    <tr className="text-fg-faint text-left text-xs">
                        <th className="py-1.5 font-normal">Service</th>
                        <th className="py-1.5 text-right font-normal">Memory</th>
                        <th className="py-1.5 text-right font-normal">Reserved</th>
                        <th className="py-1.5 text-right font-normal">CPUs</th>
                    </tr>
                </thead>
                <tbody className="divide-border divide-y">
                    {data.items.map((item) => (
                        <tr key={`${item.kind}-${item.id}`}>
                            <td className="py-1.5">
                                {item.url ? (
                                    <Link href={item.url} className="text-fg hover:underline">
                                        {item.name}
                                    </Link>
                                ) : (
                                    <span className="text-fg">{item.name}</span>
                                )}
                                <span className="text-fg-faint ml-2 text-xs">{KINDS[item.kind] ?? item.kind}</span>
                            </td>
                            <td className="tabular py-1.5 text-right font-mono text-xs">
                                {item.memory_limit_mb !== null ? memoryLabel(item.memory_limit_mb) : <span className="text-fg-faint">unlimited</span>}
                            </td>
                            <td className="tabular py-1.5 text-right font-mono text-xs">
                                {item.memory_reservation_mb !== null ? memoryLabel(item.memory_reservation_mb) : '—'}
                            </td>
                            <td className="tabular py-1.5 text-right font-mono text-xs">
                                {item.cpus !== null ? Number(item.cpus) : <span className="text-fg-faint">unlimited</span>}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </Section>
    );
}
