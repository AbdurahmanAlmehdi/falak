import ServerLayout, { type ServerHeader } from '@/layouts/server-layout';
import { serverSectionsFor, shellContext } from '@/lib/registry';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { Suspense, useMemo } from 'react';
import { ConnectionsView } from '../components/connections-view';

/**
 * The server's Network tab: connections, listeners, talks-to, TCP & DNS (eBPF, with its switch), then the sections other
 * modules register for this tab (Terminal: network tools and packet capture). WireGuard is on the Private network tab.
 */
export default function ServerNetwork({ server }: { server: ServerHeader }) {
    const { props: shared } = usePage<SharedData>();
    const sections = useMemo(() => serverSectionsFor(shellContext(shared), 'network'), [shared]);

    return (
        <ServerLayout server={server} tab="network">
            <ConnectionsView url={`/network/servers/${server.id}/connections`} scope="server" serverId={server.id} />
            {sections.map((section) => {
                const Component = section.component;

                return (
                    <Suspense key={section.id} fallback={null}>
                        <Component serverId={server.id} />
                    </Suspense>
                );
            })}
        </ServerLayout>
    );
}
