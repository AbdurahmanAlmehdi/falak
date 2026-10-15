import { AreaLayout, type AreaSection } from '@/layouts/infrastructure-layout';
import { type ComponentProps } from 'react';

/** The /network area: private networks (with the firewalls and load balancers), blocked IPs and rate limits. */
const SECTIONS: AreaSection[] = [
    { id: 'networks', label: 'Private networks', href: '/network', permissions: ['network.view'] },
    { id: 'blocked', label: 'Blocked IPs', href: '/network/blocked', permissions: ['network.view'] },
    { id: 'rate-limits', label: 'Rate limits', href: '/network/rate-limits', permissions: ['edge.view'] },
];

export default function NetworkLayout(props: Omit<ComponentProps<typeof AreaLayout>, 'heading' | 'sections'>) {
    return <AreaLayout heading="Network" sections={SECTIONS} {...props} />;
}
