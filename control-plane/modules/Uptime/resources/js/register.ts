import { registerCommands, registerServiceTabs, type ServiceTabProps } from '@/lib/registry';
import { Activity, Globe } from 'lucide-react';
import { createElement, lazy } from 'react';

const SiteUptime = lazy(() => import('./panels/SiteUptime'));

// Canvas service panel (docs/UI_DESIGN.md §5.1): Uptime 520, after Observability.
registerServiceTabs({
    id: 'uptime',
    kinds: ['site'],
    title: 'Uptime',
    order: 520,
    permission: 'uptime.view',
    component: ({ ctx }: ServiceTabProps) => createElement(SiteUptime, { siteId: ctx.service.ref_id }),
});

registerCommands({
    id: 'uptime.navigation',
    commands: () => [
        {
            id: 'uptime.index',
            title: 'Uptime monitors',
            group: 'Navigation',
            icon: Activity,
            href: '/observability/uptime',
            permission: 'uptime.view',
            keywords: ['uptime', 'monitor', 'down', 'incident', 'ping', 'availability'],
        },
        {
            id: 'uptime.status-pages',
            title: 'Status pages',
            group: 'Navigation',
            icon: Globe,
            href: '/observability/uptime/status-pages',
            permission: 'uptime.view',
            keywords: ['status page', 'public', 'statuspage'],
        },
    ],
});
