import { registerCommands, registerNavigation, registerServerSections, registerServiceActions } from '@/lib/registry';
import { Radar, SquareTerminal } from 'lucide-react';
import { lazy } from 'react';

registerNavigation({ id: 'terminal', title: 'Terminal', url: '/terminal', icon: SquareTerminal, order: 600, permission: 'terminal.open' });

// Site consoles (docs/plans/CONSOLE.md) from the canvas service panel's `⋯`. Functions run on demand: no console.
registerServiceActions({
    id: 'terminal.console',
    kinds: ['site'],
    label: 'Console',
    icon: SquareTerminal,
    order: 300,
    separated: true,
    permission: 'terminal.open',
    when: (ctx) => ctx.service.icon !== 'function',
    dialog: lazy(() => import('./components/console-dialog').then((module) => ({ default: module.ConsoleDialog }))),
});

// Network tools (docs/plans/network/tools.md): from a site's container (or host for native sites) and from servers.
const netTools = () => import('./components/net-tools');

registerServiceActions({
    id: 'terminal.net-tools',
    kinds: ['site'],
    label: 'Network tools',
    icon: Radar,
    order: 310,
    permission: 'network.tools',
    when: (ctx) => ctx.service.icon !== 'function',
    dialog: lazy(() => netTools().then((module) => ({ default: module.SiteNetToolsDialog }))),
});

registerServerSections({
    id: 'terminal.net-tools',
    order: 700,
    permission: 'network.tools',
    component: lazy(() => netTools().then((module) => ({ default: module.ServerNetTools }))),
});

registerCommands({
    id: 'terminal.navigation',
    commands: () => [
        {
            id: 'terminal.index',
            title: 'Terminal sessions',
            group: 'Navigation',
            icon: SquareTerminal,
            href: '/terminal',
            keywords: ['shell', 'ssh', 'console', 'recording'],
            permission: 'terminal.open',
        },
    ],
});
