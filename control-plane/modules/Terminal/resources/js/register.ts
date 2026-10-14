import { registerCommands, registerNavigation, registerServiceActions } from '@/lib/registry';
import { SquareTerminal } from 'lucide-react';
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
