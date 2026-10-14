import { Button, Section } from '@/components/falak';
import { Stat } from '@/components/falak/stat';
import ObservabilityLayout from '@/layouts/observability-layout';
import { Link, router } from '@inertiajs/react';
import { Globe } from 'lucide-react';
import { IncidentList, MonitorsView } from '../components/monitors-view';
import { type UptimeIncident, type UptimeLimits, type UptimeMonitor } from '../lib';

interface Props {
    monitors: UptimeMonitor[];
    incidents: UptimeIncident[];
    sites: Array<{ id: string; name: string }>;
    selected: string | null;
    limits: UptimeLimits;
    can: { manage: boolean; publish: boolean };
}

/** /observability/uptime: every monitor of the organization. */
export default function UptimeIndex({ monitors, incidents, sites, selected, limits, can }: Props) {
    const active = monitors.filter((monitor) => !monitor.paused);
    const down = active.filter((monitor) => monitor.status === 'down').length;
    const open = incidents.filter((incident) => !incident.resolved_at).length;

    return (
        <ObservabilityLayout
            tab="uptime"
            actions={
                <Button asChild size="sm" icon={<Globe />}>
                    <Link href="/observability/uptime/status-pages">Status pages</Link>
                </Button>
            }
        >
            <section aria-label="Summary" className="grid grid-cols-2 gap-3 md:grid-cols-4">
                <Stat label="Monitors" value={active.length} hint={`${monitors.length - active.length} paused`} />
                <Stat label="Up" value={active.filter((monitor) => monitor.status === 'up').length} tone="success" />
                <Stat label="Down" value={down} tone={down > 0 ? 'danger' : undefined} />
                <Stat
                    label="Open incidents"
                    value={open}
                    tone={open > 0 ? 'danger' : undefined}
                    hint={`checks ${limits.lag_seconds ? `${limits.lag_seconds} s late` : 'on time'} · load ${limits.load ?? 0}/${limits.budget}`}
                />
            </section>
            <MonitorsView
                monitors={monitors}
                limits={limits}
                canManage={can.manage}
                reload={() => router.reload({ only: ['monitors', 'incidents'] })}
                sites={sites}
                showSite
                selected={selected}
            />
            <Section title="Recent incidents" bare>
                <IncidentList incidents={incidents} showMonitor />
            </Section>
        </ObservabilityLayout>
    );
}
