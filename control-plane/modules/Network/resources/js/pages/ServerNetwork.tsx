import { Button } from '@/components/falak/button';
import { ConfirmDestructive } from '@/components/falak/confirm-destructive';
import { Section } from '@/components/falak/section';
import ServerLayout, { type ServerHeader } from '@/layouts/server-layout';
import { serverSectionsFor, shellContext } from '@/lib/registry';
import { type SharedData } from '@/types';
import { Link, router, usePage, usePoll } from '@inertiajs/react';
import { RefreshCw, Waypoints } from 'lucide-react';
import { Suspense, useMemo, useState } from 'react';
import { ConnectionsView } from '../components/connections-view';
import { TailscaleStatus, type TailscaleNode } from '../components/tailscale-ui';

interface Tailscale {
    configured: boolean;
    node: TailscaleNode | null;
    can: { manage: boolean };
}

/**
 * The server's Network tab: connections, listeners, talks-to, TCP & DNS (eBPF, with its switch), then the sections other
 * modules register for this tab (Terminal: network tools and packet capture). WireGuard is on the Private network tab.
 */
export default function ServerNetwork({ server, tailscale }: { server: ServerHeader; tailscale: Tailscale }) {
    const { props: shared } = usePage<SharedData>();
    const sections = useMemo(() => serverSectionsFor(shellContext(shared), 'network'), [shared]);

    return (
        <ServerLayout server={server} tab="network">
            <ConnectionsView url={`/network/servers/${server.id}/connections`} scope="server" serverId={server.id} />
            <TailscaleSection server={server} tailscale={tailscale} />
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

const options = { preserveScroll: true, only: ['tailscale'] };

/** Join / refresh / leave the organization's tailnet (docs/plans/TAILSCALE.md). */
function TailscaleSection({ server, tailscale }: { server: ServerHeader; tailscale: Tailscale }) {
    const { node, can } = tailscale;
    const [leaving, setLeaving] = useState(false);
    const [error, setError] = useState<string | undefined>();
    usePoll(node?.status === 'installing' ? 3_000 : 60_000, { only: ['tailscale'] });

    const join = () =>
        router.post(
            `/network/servers/${server.id}/tailscale`,
            {},
            { ...options, onStart: () => setError(undefined), onError: (errors) => setError(errors.server) },
        );

    return (
        <Section
            title="Tailscale"
            description="On the tailnet, the server advertises its public IPs as routes: your tailnet devices reach it (and its Tailscale-only sites) through the tunnel."
            aside={
                can.manage &&
                tailscale.configured &&
                (node ? (
                    <span className="flex gap-2">
                        <Button variant="ghost" size="sm" icon={<RefreshCw />} onClick={join}>
                            Refresh
                        </Button>
                        <Button variant="ghost" size="sm" onClick={() => setLeaving(true)}>
                            Leave
                        </Button>
                    </span>
                ) : (
                    <Button variant="secondary" size="sm" icon={<Waypoints />} onClick={join}>
                        Join the tailnet
                    </Button>
                ))
            }
        >
            {error && (
                <p role="alert" className="text-danger text-sm">
                    {error}
                </p>
            )}
            {node ? (
                <div className="grid gap-1 text-sm">
                    <span className="flex flex-wrap items-center gap-2">
                        <span className="text-fg font-mono text-xs">{node.hostname}</span>
                        <TailscaleStatus node={node} />
                        {node.version && <span className="text-fg-faint text-xs">Tailscale {node.version}</span>}
                    </span>
                    {(node.ipv4 || node.ipv6) && (
                        <p className="text-fg-muted font-mono text-xs">{[node.ipv4, node.ipv6].filter(Boolean).join(' · ')}</p>
                    )}
                    {node.status === 'active' && !node.routes_approved && (
                        <p className="text-fg-muted text-xs">
                            Approve the server’s routes in the Tailscale admin console (or auto-approve tag:falak), then refresh.
                        </p>
                    )}
                    {node.error && (
                        <p role="alert" className="text-danger text-xs">
                            {node.error}
                        </p>
                    )}
                </div>
            ) : (
                <p className="text-fg-muted text-sm">
                    {tailscale.configured ? (
                        'Not on the tailnet.'
                    ) : (
                        <>
                            Not on the tailnet. Save an auth key in{' '}
                            <Link href="/settings/tailscale" className="text-fg underline">
                                Settings → Tailscale
                            </Link>{' '}
                            first.
                        </>
                    )}
                </p>
            )}
            <ConfirmDestructive
                open={leaving}
                onOpenChange={setLeaving}
                title={`Take ${server.name} off the tailnet?`}
                description="Tailscale is logged out and removed from the server. Its Tailscale-only sites stop answering tailnet devices."
                confirmText={server.name}
                confirmLabel="Leave the tailnet"
                onConfirm={() =>
                    new Promise<void>((resolve) => {
                        router.delete(`/network/servers/${server.id}/tailscale`, {
                            ...options,
                            onFinish: () => {
                                setLeaving(false);
                                resolve();
                            },
                        });
                    })
                }
            />
        </Section>
    );
}
