import { CommandLog } from '@/components/command-log';
import { Button } from '@/components/falak/button';
import { CopyButton } from '@/components/falak/copy-button';
import { Dialog } from '@/components/falak/dialog';
import { EmptyState } from '@/components/falak/empty-state';
import { Field } from '@/components/falak/field';
import { Input } from '@/components/falak/input';
import { Menu } from '@/components/falak/menu';
import { RelativeTime } from '@/components/falak/relative-time';
import { Select } from '@/components/falak/select';
import { Tag } from '@/components/falak/tag';
import { toast } from '@/components/falak/toast';
import ServerLayout, { type ServerHeader } from '@/layouts/server-layout';
import { Link, router, useForm, usePoll } from '@inertiajs/react';
import { Network, Plus, RefreshCw, Unplug } from 'lucide-react';
import { useState, type FormEventHandler } from 'react';
import { ConnectionsView } from '../components/connections-view';
import { ApplyStatusBadge } from '../components/network-ui';
import { type ApplyStatus } from '../types';

interface Membership {
    id: string;
    network: { id: string; name: string; cidr: string; interface: string; listen_port: number; members_count: number };
    address: string;
    status: ApplyStatus;
    key_status: 'pending' | 'installed' | 'failed';
    error: string | null;
    command_id: string | null;
    applied_at: string | null;
}

interface Props {
    server: ServerHeader;
    privateIpv4: string | null;
    memberships: Membership[];
    availableNetworks: { id: string; name: string; cidr: string; members_count: number }[];
    defaults: { cidr: string; listen_port: number };
    can: { manage: boolean; join: boolean };
}

const RELOAD = ['server', 'memberships', 'availableNetworks'];

export default function ServerNetwork({ server, privateIpv4, memberships, availableNetworks, defaults, can }: Props) {
    const [dialog, setDialog] = useState<'join' | 'create' | null>(null);
    const [leaving, setLeaving] = useState<Membership | null>(null);
    const [leaveBusy, setLeaveBusy] = useState(false);
    const join = useForm({ network_id: '', server_id: server.id });
    const create = useForm({ name: '', cidr: defaults.cidr, listen_port: String(defaults.listen_port), server_id: server.id });

    const converging = memberships.some((member) => member.status === 'applying' || member.status === 'pending' || member.key_status === 'pending');
    usePoll(converging ? 3_000 : 60_000, { only: RELOAD });

    const submitJoin: FormEventHandler = (event) => {
        event.preventDefault();
        const network = availableNetworks.find((item) => item.id === join.data.network_id);
        join.transform((data) => ({ server_id: data.server_id }));
        join.post(`/network/private-networks/${join.data.network_id}/members`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(`Joining ${network?.name ?? 'the network'}`, 'Keys are exchanged and WireGuard is configured on every member.');
                join.reset();
                setDialog(null);
            },
        });
    };

    const submitCreate: FormEventHandler = (event) => {
        event.preventDefault();
        create.post('/network/private-networks', {
            preserveScroll: true,
            onSuccess: () => {
                create.reset();
                setDialog(null);
            },
        });
    };

    const leave = () => {
        if (!leaving) return;
        router.delete(`/network/private-networks/${leaving.network.id}/members/${leaving.id}`, {
            preserveScroll: true,
            onStart: () => setLeaveBusy(true),
            onSuccess: () => toast.success(`Leaving ${leaving.network.name}`),
            onFinish: () => {
                setLeaveBusy(false);
                setLeaving(null);
            },
        });
    };

    const reapply = (membership: Membership) =>
        router.post(
            `/network/private-networks/${membership.network.id}/apply`,
            {},
            { preserveScroll: true, onSuccess: () => toast.success(`Re-applying ${membership.network.name}`) },
        );

    const primary = can.manage && (
        <>
            <Button icon={<Plus />} onClick={() => setDialog('create')}>
                New network
            </Button>
            <Button variant="primary" icon={<Network />} onClick={() => setDialog('join')} disabled={!can.join || availableNetworks.length === 0}>
                Join network
            </Button>
        </>
    );

    return (
        <ServerLayout server={server} tab="network" reloadOnly={RELOAD} actions={memberships.length > 0 ? primary : undefined}>
            {privateIpv4 && (
                <p className="text-fg-muted flex items-center gap-2 text-sm">
                    Provider private IP <span className="text-fg font-mono text-xs">{privateIpv4}</span>
                    <CopyButton value={privateIpv4} size="xs" label="Copy private IP" />
                </p>
            )}

            {memberships.length === 0 ? (
                <EmptyState
                    icon={<Network />}
                    title="Not on a private network"
                    description="Private networks connect servers over WireGuard, so databases and caches never need a public port. Join an existing network or create one."
                    action={
                        can.manage && (
                            <Button
                                variant="primary"
                                icon={<Network />}
                                onClick={() => setDialog(availableNetworks.length > 0 ? 'join' : 'create')}
                                disabled={!can.join}
                            >
                                {availableNetworks.length > 0 ? 'Join network' : 'Create network'}
                            </Button>
                        )
                    }
                    secondary={
                        can.manage &&
                        availableNetworks.length > 0 && (
                            <Button variant="ghost" onClick={() => setDialog('create')} disabled={!can.join}>
                                Create a new one
                            </Button>
                        )
                    }
                />
            ) : (
                <ul className="grid gap-3">
                    {memberships.map((membership) => (
                        <li key={membership.id} className="border-border bg-surface-1 grid gap-3 rounded-lg border p-4">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div className="grid min-w-0 gap-0.5">
                                    <Link
                                        href={`/network/private-networks/${membership.network.id}`}
                                        className="text-fg flex items-center gap-1.5 text-sm font-medium hover:underline"
                                    >
                                        {membership.network.name}
                                    </Link>
                                    <p className="text-fg-muted text-xs">
                                        <span className="font-mono">{membership.network.cidr}</span> · {membership.network.interface} · UDP{' '}
                                        {membership.network.listen_port} · <span className="tabular">{membership.network.members_count}</span> member
                                        {membership.network.members_count === 1 ? '' : 's'}
                                    </p>
                                </div>
                                <div className="flex items-center gap-2">
                                    <ApplyStatusBadge status={membership.status} />
                                    {can.manage && (
                                        <Menu
                                            label={`Actions for ${membership.network.name}`}
                                            actions={[
                                                { label: 'Re-apply', icon: <RefreshCw />, onSelect: () => reapply(membership) },
                                                { type: 'separator' },
                                                { label: 'Leave network…', icon: <Unplug />, danger: true, onSelect: () => setLeaving(membership) },
                                            ]}
                                        />
                                    )}
                                </div>
                            </div>
                            <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                                <span className="flex items-center gap-1.5">
                                    <span className="text-fg-faint text-xs">Address</span>
                                    <span className="text-fg font-mono text-xs">{membership.address}</span>
                                    <CopyButton value={membership.address} size="xs" label="Copy private address" />
                                </span>
                                <span className="flex items-center gap-1.5">
                                    <span className="text-fg-faint text-xs">Keys</span>
                                    <Tag
                                        tone={
                                            membership.key_status === 'installed' ? 'success' : membership.key_status === 'failed' ? 'danger' : 'info'
                                        }
                                    >
                                        {membership.key_status}
                                    </Tag>
                                </span>
                                <span className="flex items-center gap-1.5">
                                    <span className="text-fg-faint text-xs">Applied</span>
                                    <RelativeTime value={membership.applied_at} className="text-fg-muted text-xs" fallback="not yet" />
                                </span>
                            </div>
                            {membership.error && (
                                <p role="alert" className="text-danger text-xs">
                                    {membership.error}
                                </p>
                            )}
                            {membership.command_id && membership.status === 'applying' && <CommandLog commandId={membership.command_id} />}
                        </li>
                    ))}
                </ul>
            )}

            {!can.join && can.manage && <p className="text-fg-faint text-xs">Servers can join a private network once they are active.</p>}

            <Dialog
                open={dialog === 'join'}
                onOpenChange={(open) => setDialog(open ? 'join' : null)}
                title="Join a private network"
                description={`${server.name} gets an address in the network and a WireGuard peer on every member.`}
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setDialog(null)}>
                            Cancel
                        </Button>
                        <Button variant="primary" type="submit" form="join-network" loading={join.processing} disabled={!join.data.network_id}>
                            Join
                        </Button>
                    </>
                }
            >
                <form id="join-network" onSubmit={submitJoin} className="grid gap-4">
                    <Field label="Network" error={join.errors.server_id ?? join.errors.network_id}>
                        <Select
                            value={join.data.network_id || undefined}
                            onValueChange={(value) => join.setData('network_id', value)}
                            placeholder="Choose a network"
                            options={availableNetworks.map((network) => ({
                                value: network.id,
                                label: network.name,
                                description: `${network.cidr} · ${network.members_count} member${network.members_count === 1 ? '' : 's'}`,
                            }))}
                        />
                    </Field>
                </form>
            </Dialog>

            <Dialog
                open={dialog === 'create'}
                onOpenChange={(open) => setDialog(open ? 'create' : null)}
                title="New private network"
                description={`Creates a WireGuard network and adds ${server.name} as its first member.`}
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setDialog(null)}>
                            Cancel
                        </Button>
                        <Button variant="primary" type="submit" form="create-network" loading={create.processing} disabled={!create.data.name.trim()}>
                            Create & join
                        </Button>
                    </>
                }
            >
                <form id="create-network" onSubmit={submitCreate} className="grid gap-4">
                    <Field label="Name" required error={create.errors.name}>
                        <Input
                            value={create.data.name}
                            onChange={(event) => create.setData('name', event.target.value)}
                            placeholder="backend"
                            autoFocus
                        />
                    </Field>
                    <div className="grid grid-cols-2 gap-4">
                        <Field label="CIDR" error={create.errors.cidr} hint="An unused private range.">
                            <Input value={create.data.cidr} onChange={(event) => create.setData('cidr', event.target.value)} mono />
                        </Field>
                        <Field label="Listen port (UDP)" error={create.errors.listen_port}>
                            <Input
                                type="number"
                                value={create.data.listen_port}
                                onChange={(event) => create.setData('listen_port', event.target.value)}
                                min={1024}
                                max={65535}
                                mono
                            />
                        </Field>
                    </div>
                </form>
            </Dialog>

            <Dialog
                open={leaving !== null}
                onOpenChange={(open) => !open && setLeaving(null)}
                size="sm"
                title={`Leave ${leaving?.network.name}?`}
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setLeaving(null)}>
                            Cancel
                        </Button>
                        <Button variant="danger" onClick={leave} loading={leaveBusy}>
                            Leave network
                        </Button>
                    </>
                }
            >
                <p className="text-fg-muted text-sm">
                    {server.name} loses its address <span className="font-mono">{leaving?.address}</span>; services reaching it over the private
                    network stop working.
                </p>
            </Dialog>

            <div className="border-border mt-8 border-t pt-6">
                <h2 className="text-fg mb-3 text-base font-medium">Connections</h2>
                <ConnectionsView url={`/network/servers/${server.id}/connections`} scope="server" serverId={server.id} />
            </div>
        </ServerLayout>
    );
}
