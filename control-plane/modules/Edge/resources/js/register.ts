import { registerDomainPicker, registerServiceSettingsSections, registerSettingsNav } from '@/lib/registry';
import { Cloud, Globe } from 'lucide-react';
import { lazy } from 'react';

// Settings tab → Networking (docs/UI_DESIGN.md §5.1): domains + TLS, certificates, edge servers, load balancer
// (410), then redirects, basic auth, headers and access limits (430). Sites adds the test domain toggle (420).
const networking = () => import('./panel/networking');

registerServiceSettingsSections(
    {
        id: 'edge.domains',
        kinds: ['site'],
        section: 'networking',
        sectionTitle: 'Networking',
        order: 410,
        permission: 'edge.view',
        component: lazy(() => networking().then((module) => ({ default: module.DomainsSettings }))),
    },
    // Cloudflare traffic of the site's proxied names (docs/plans/network/tools.md part 2); nothing without one.
    {
        id: 'edge.cloudflare-analytics',
        kinds: ['site'],
        section: 'networking',
        sectionTitle: 'Networking',
        order: 415,
        permission: 'edge.view',
        component: lazy(() => import('./panel/cloudflare-analytics').then((module) => ({ default: module.CloudflareAnalytics }))),
    },
    {
        id: 'edge.routing',
        kinds: ['site'],
        section: 'networking',
        sectionTitle: 'Networking',
        order: 430,
        permission: 'edge.view',
        component: lazy(() => networking().then((module) => ({ default: module.RoutingSettings }))),
    },
);

// Domain choices in create forms (templates, git / image services) and Settings → Compose: generated, test or custom.
registerDomainPicker(lazy(() => import('./components/domain-picker').then((module) => ({ default: module.DomainPicker }))));

// Organization settings → Domains: which service generates domains (sslip.io, nip.io, off).
registerSettingsNav({
    id: 'domains',
    title: 'Domains',
    url: '/settings/domains',
    group: 'organization',
    order: 145,
    icon: Globe,
    permission: 'edge.view',
    requiresOrganization: true,
    keywords: ['domain', 'dns', 'sslip', 'nip.io', 'generated', 'test domain'],
});

// Organization settings → Integrations → Cloudflare: DNS records, generated names under a zone, proxy / TLS checks.
registerSettingsNav({
    id: 'cloudflare',
    title: 'Cloudflare',
    url: '/settings/cloudflare',
    group: 'integrations',
    order: 215,
    icon: Cloud,
    permission: 'edge.view',
    requiresOrganization: true,
    keywords: ['cloudflare', 'dns', 'zone', 'proxy', 'orange cloud', 'ddos', 'cdn', 'records'],
});
