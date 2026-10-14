import { AppShell } from '@/components/falak/app-shell';
import { PageHeader } from '@/components/falak/section';
import { tabTriggerClasses } from '@/components/falak/tabs';
import { shellContext } from '@/lib/registry';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { type ReactNode } from 'react';

interface ObservabilityTab {
    id: string;
    title: string;
    href: string;
    permission: string;
}

/** The /observability sections (docs/UI_DESIGN.md §3). Insights owns Overview/Issues/Heartbeats, Telemetry Traces/Logs, Uptime Uptime, Alerting Alerts. */
const TABS: ObservabilityTab[] = [
    { id: 'overview', title: 'Overview', href: '/observability', permission: 'insights.view' },
    { id: 'issues', title: 'Issues', href: '/observability/issues', permission: 'insights.view' },
    { id: 'traces', title: 'Traces', href: '/observability/traces', permission: 'telemetry.view' },
    { id: 'logs', title: 'Logs', href: '/observability/logs', permission: 'telemetry.view' },
    { id: 'heartbeats', title: 'Heartbeats', href: '/observability/heartbeats', permission: 'insights.view' },
    { id: 'uptime', title: 'Uptime', href: '/observability/uptime', permission: 'uptime.view' },
    { id: 'alerts', title: 'Alerts', href: '/observability/alerts', permission: 'alerting.view' },
];

export type ObservabilityTabId = 'overview' | 'issues' | 'traces' | 'logs' | 'heartbeats' | 'uptime' | 'alerts';

interface ObservabilityLayoutProps {
    /** Active tab. */
    tab: ObservabilityTabId;
    /** Browser title (defaults to the tab title). */
    title?: string;
    /** Right side of the header row (range picker, filters, primary action). */
    actions?: ReactNode;
    /** Extra breadcrumbs after "Observability › Tab" (detail pages). */
    breadcrumbs?: BreadcrumbItem[];
    /** Detail pages (issue, trace) replace the tab strip with their own header. */
    header?: ReactNode;
    children: ReactNode;
}

/** /observability shell: one page with in-page tabs, each tab a module-owned Inertia page. */
export default function ObservabilityLayout({ tab, title, actions, breadcrumbs = [], header, children }: ObservabilityLayoutProps) {
    const { props } = usePage<SharedData>();
    const ctx = shellContext(props);
    const tabs = TABS.filter((item) => ctx.can(item.permission));
    const active = TABS.find((item) => item.id === tab) ?? TABS[0];

    return (
        <AppShell
            breadcrumbs={[
                { title: 'Observability', href: '/observability' },
                ...(tab === 'overview' ? [] : [{ title: active.title, href: active.href }]),
                ...breadcrumbs,
            ]}
        >
            <Head title={title ?? `${active.title} · Observability`} />
            <div className="grid min-w-0 grid-cols-1 gap-6">
                {header ?? (
                    <div className="grid gap-4">
                        <PageHeader title="Observability" actions={actions} />
                        <nav aria-label="Observability sections" className="-mx-4 overflow-x-auto px-4 md:mx-0 md:px-0">
                            <ul className="border-border flex min-w-max items-center gap-1 border-b">
                                {tabs.map((item) => (
                                    <li key={item.id}>
                                        <Link
                                            href={item.href}
                                            prefetch
                                            aria-current={item.id === tab ? 'page' : undefined}
                                            className={cn(tabTriggerClasses)}
                                        >
                                            {item.title}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </nav>
                    </div>
                )}
                {children}
            </div>
        </AppShell>
    );
}
