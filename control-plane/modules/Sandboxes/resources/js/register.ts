import { registerCommands, registerNavigation } from '@/lib/registry';
import { Box } from 'lucide-react';

registerNavigation({ id: 'sandboxes', title: 'Sandboxes', url: '/sandboxes', icon: Box, order: 350, permission: 'sandboxes.view' });

registerCommands({
    id: 'sandboxes.navigation',
    commands: () => [
        {
            id: 'sandboxes.index',
            title: 'Sandboxes',
            group: 'Navigation',
            icon: Box,
            href: '/sandboxes',
            keywords: ['sandbox', 'gvisor', 'agent', 'mcp', 'exec', 'vm'],
            permission: 'sandboxes.view',
        },
    ],
});
