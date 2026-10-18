import { registerHeaderItems, registerServerSections, registerSettingsNav } from '@/lib/registry';
import { ArrowUpCircle } from 'lucide-react';
import { LocalServerCard } from './components/local-server-card';
import { UpdateHeaderItem } from './components/update-header-item';
import { falakUpdateOf } from './types';

// "Update available vX" in the top bar: the shared prop is only the install's operators'.
registerHeaderItems({ id: 'controlplane.update', order: 50, permission: 'controlplane.update', component: UpdateHeaderItem });

// Organization settings → Falak updates: only in the install's operator organization.
registerSettingsNav({
    id: 'falak-updates',
    title: 'Falak updates',
    url: '/settings/falak-updates',
    group: 'organization',
    order: 185,
    icon: ArrowUpCircle,
    permission: 'controlplane.update',
    requiresOrganization: true,
    when: (ctx) => falakUpdateOf(ctx.props) !== null,
    keywords: ['update', 'upgrade', 'release', 'version', 'falak-ctl', 'control plane'],
});

// The control plane's own machine as a server: the host installing its agent, live.
registerServerSections({ id: 'controlplane.local-server', order: 10, permission: 'controlplane.update', component: LocalServerCard });
