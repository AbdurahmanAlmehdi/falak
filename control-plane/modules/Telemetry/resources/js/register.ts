import {
    registerCommands,
    registerNavigation,
    registerServerSections,
    registerServiceTabs,
    registerSettingsNav,
    type ServiceTabProps,
} from '@/lib/registry';
import { Activity, Radar, ScrollText } from 'lucide-react';
import { createElement, lazy } from 'react';

const SiteMetrics = lazy(() => import('./panels/SiteMetrics'));
const SiteLogs = lazy(() => import('./panels/SiteLogs'));
const SiteHttp = lazy(() => import('./panels/SiteHttp'));
const ServerEdgeTraffic = lazy(() => import('./components/server-edge-traffic'));

// Canvas service panel (docs/UI_DESIGN.md §5.1): Metrics 300, HTTP 350, Logs 400.
registerServiceTabs(
    {
        id: 'metrics',
        kinds: ['site'],
        title: 'Metrics',
        order: 300,
        permission: 'telemetry.view',
        component: ({ ctx }: ServiceTabProps) => createElement(SiteMetrics, { siteId: ctx.service.ref_id }),
    },
    {
        // The edge's traffic of the site (docs/plans/network/http.md).
        id: 'http',
        kinds: ['site'],
        title: 'HTTP',
        order: 350,
        permission: 'telemetry.view',
        component: ({ ctx }: ServiceTabProps) => createElement(SiteHttp, { siteId: ctx.service.ref_id, can: ctx.can }),
    },
    {
        id: 'logs',
        kinds: ['site'],
        title: 'Logs',
        order: 400,
        permission: 'telemetry.view',
        component: ({ ctx }: ServiceTabProps) =>
            createElement(SiteLogs, {
                siteId: ctx.service.ref_id,
                composeService: ctx.item ?? undefined,
                onClearComposeService: () => ctx.open('logs'),
            }),
    },
);

// Server page: what its edge serves.
registerServerSections({ id: 'telemetry.edge-traffic', order: 200, permission: 'telemetry.view', component: ServerEdgeTraffic });

registerNavigation(
    { id: 'telemetry.logs', title: 'Logs', url: '/observability/logs', icon: ScrollText, order: 400, permission: 'telemetry.view' },
    { id: 'telemetry.traces', title: 'Traces', url: '/observability/traces', icon: Activity, order: 410, permission: 'telemetry.view' },
);

registerCommands({
    id: 'telemetry.navigation',
    commands: () => [
        {
            id: 'telemetry.logs',
            title: 'Logs',
            group: 'Navigation',
            icon: ScrollText,
            href: '/observability/logs',
            permission: 'telemetry.view',
            keywords: ['loki', 'observability', 'tail'],
        },
        {
            id: 'telemetry.traces',
            title: 'Traces',
            group: 'Navigation',
            icon: Activity,
            href: '/observability/traces',
            permission: 'telemetry.view',
            keywords: ['tempo', 'apm', 'observability', 'spans'],
        },
        {
            id: 'telemetry.traces.errors',
            title: 'Traces with errors',
            group: 'Navigation',
            icon: Activity,
            href: '/observability/traces?status=error',
            permission: 'telemetry.view',
            keywords: ['tempo', 'failed'],
        },
    ],
});

// Organization settings → Integrations (docs/UI_DESIGN.md §3). The palette lists it under "Settings".
registerSettingsNav({
    id: 'observability',
    title: 'Observability',
    url: '/settings/observability',
    group: 'integrations',
    order: 240,
    icon: Radar,
    permission: 'telemetry.view',
    requiresOrganization: true,
    keywords: ['telemetry', 'otlp', 'grafana', 'loki', 'tempo', 'metrics', 'prometheus'],
});
