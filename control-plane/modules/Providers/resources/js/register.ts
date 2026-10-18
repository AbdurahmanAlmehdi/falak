import { registerCommands, registerSettingsNav } from '@/lib/registry';
import { Cloud, Plus } from 'lucide-react';

// Organization settings → Integrations (docs/UI_DESIGN.md §3). The palette lists it under "Settings".
registerSettingsNav({
    id: 'cloud-providers',
    title: 'Cloud providers',
    url: '/settings/cloud-providers',
    group: 'integrations',
    order: 210,
    icon: Cloud,
    permission: 'providers.view',
    requiresOrganization: true,
    keywords: ['cloud', 'hetzner', 'digitalocean', 'vultr', 'linode', 'aws', 'lightsail', 'contabo', 'credentials'],
});

registerCommands({
    id: 'providers.actions',
    commands: () => [
        {
            id: 'providers.add',
            title: 'Add cloud provider credential',
            group: 'Actions',
            icon: Plus,
            href: '/settings/cloud-providers?add=1',
            permission: 'providers.manage',
            keywords: ['cloud', 'token', 'api key', 'hetzner', 'digitalocean'],
        },
    ],
});
