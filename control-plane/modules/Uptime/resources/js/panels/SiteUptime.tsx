import { Callout } from '@/components/falak';
import { useJson } from '@/hooks/use-json';
import { MonitorsLoading, MonitorsView } from '../components/monitors-view';
import { type UptimeIncident, type UptimeLimits, type UptimeMonitor } from '../lib';

interface SiteUptimeData {
    monitors: UptimeMonitor[];
    incidents: UptimeIncident[];
    limits: UptimeLimits;
    can: { manage: boolean };
}

/** Service panel → Uptime: the site's domain monitors and its own, uptime, response times and incidents. */
export default function SiteUptime({ siteId }: { siteId: string }) {
    const { data, error, reload } = useJson<SiteUptimeData>(`/uptime/sites/${siteId}`, { interval: 60_000 });

    if (error && !data) return <Callout tone="danger">{error}</Callout>;
    if (!data) return <MonitorsLoading />;

    return <MonitorsView monitors={data.monitors} limits={data.limits} canManage={data.can.manage} reload={() => void reload()} siteId={siteId} />;
}
