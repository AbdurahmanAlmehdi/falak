import { registerCommands, registerIpActions, registerNavigation, registerServiceTabs, type ServiceTabProps } from '@/lib/registry';
import { Ban, Gauge, Network, Shield } from 'lucide-react';
import { createElement, lazy } from 'react';
import { BlockIpAction } from './components/block-ip-action';

registerNavigation({ id: 'network', title: 'Network', url: '/network', icon: Network, order: 400, permission: 'network.view' });

const ConnectionsView = lazy(() => import('./components/connections-view').then((module) => ({ default: module.ConnectionsView })));

// Canvas service panel: a site's and a database's connections (docs/plans/network/connections.md, "UI").
registerServiceTabs(
    {
        id: 'network',
        kinds: ['site'],
        title: 'Network',
        order: 350,
        permission: 'telemetry.view',
        component: ({ ctx }: ServiceTabProps) =>
            createElement(ConnectionsView, {
                url: `/network/sites/${encodeURIComponent(ctx.service.ref_id)}/connections`,
                scope: 'site',
                siteId: ctx.service.ref_id,
            }),
    },
    {
        id: 'network',
        kinds: ['database'],
        title: 'Network',
        order: 450,
        permission: 'databases.view',
        component: ({ ctx }: ServiceTabProps) =>
            createElement(ConnectionsView, { url: `/network/databases/${encodeURIComponent(ctx.service.ref_id)}/connections`, scope: 'database' }),
    },
);

// "Block" in the network.ip-actions slot on the client and peer rows (docs/plans/network/protection.md).
registerIpActions({ id: 'network.block', order: 100, permission: 'network.protect', component: BlockIpAction });

registerCommands({
    id: 'network.navigation',
    commands: () => [
        {
            id: 'network.index',
            title: 'Private networks',
            group: 'Navigation',
            icon: Network,
            href: '/network',
            permission: 'network.view',
            keywords: ['firewall', 'wireguard', 'private network', 'load balancer'],
        },
        {
            id: 'network.firewalls',
            title: 'Firewalls',
            group: 'Navigation',
            icon: Shield,
            href: '/network',
            permission: 'network.view',
            keywords: ['nftables', 'ports', 'firewall rules'],
        },
        {
            id: 'network.blocked',
            title: 'Blocked IPs',
            group: 'Navigation',
            icon: Ban,
            href: '/network/blocked',
            permission: 'network.view',
            keywords: ['block', 'ban', 'fail2ban', 'brute force', 'ssh'],
        },
        {
            id: 'network.rate-limits',
            title: 'Rate limits',
            group: 'Navigation',
            icon: Gauge,
            href: '/network/rate-limits',
            permission: 'edge.view',
            keywords: ['rate limit', '429', 'login', 'throttle', 'caddy'],
        },
    ],
});
