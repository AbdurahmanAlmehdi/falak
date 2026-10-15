import { AppShell } from '@/components/falak/app-shell';
import { Button } from '@/components/falak/button';
import { ConfirmDestructive } from '@/components/falak/confirm-destructive';
import { DataTable } from '@/components/falak/data-table';
import { Dialog } from '@/components/falak/dialog';
import { Field } from '@/components/falak/field';
import { KeyValue } from '@/components/falak/key-value';
import { Menu } from '@/components/falak/menu';
import { PageHeader, Section } from '@/components/falak/section';
import { Select } from '@/components/falak/select';
import { Tag } from '@/components/falak/tag';
import { toast } from '@/components/falak/toast';
import { Head, Link, router, useForm, usePoll } from '@inertiajs/react';
import { Network, Plus, RefreshCw, Trash2, Unplug } from 'lucide-react';
import { useState, type FormEventHandler } from 'react';
import { ApplyStatusBadge } from '../components/network-ui';
import { type ApplyStatus } from '../types';

interface Member {
    id: string;
    server_id: string;
    server_name: string;
    public_ipv4: string | null;
    address: string;
    public_key: string;
    key_status: 'pending' | 'installed' | 'failed';
    status: ApplyStatus;
    error: string | null;
    command_id: string | null;
    applied_at: string | null;
}

interface Props {
    network: { id: string; name: string; cidr: string; interface: string; listen_port: number; created_at: string };
    members: Member[];
    availableServers: { id: string; name: string; ipv4: string | null; type_label: string }[];
    can: { manage: boolean };
}

export default function PrivateNetwork({ network, members, availableServers, can }: Props) {
    const [adding, setAdding] = useState(false);
    const [removing, setRemoving] = useState<Member | null>(null);
    const [removeBusy, setRemoveBusy] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [deleteError, setDeleteError] = useState<string | undefined>();
    const addForm = useForm({ server_id: '' });

    const converging = members.some((member) => member.status === 'applying' || (member.key_status === 'pending' && member.status !== 'failed'));
    usePoll(converging ? 3_000 : 60_000, { only: ['members', 'availableServers'] });

    const add: FormEventHandler = (event) => {
        event.preventDefault();
        const server = availableServers.find((item) => item.id === addForm.data.server_id);
        addForm.post(`/network/private-networks/${network.id}/members`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(`Adding ${server?.name ?? 'server'}`, 'WireGuard is configured on every member.');
                addForm.reset();
                setAdding(false);
            },
        });
    };

    const remove = () => {
        if (!removing) return;
        router.delete(`/network/private-networks/${network.id}/members/${removing.id}`, {
            preserveScroll: true,
            onStart: () => setRemoveBusy(true),
            onSuccess: () => toast.success(`Removing ${removing.server_name}`),
            onFinish: () => {
                setRemoveBusy(false);
                setRemoving(null);
            },
        });
    };

    const destroy = (name: string) =>
        new Promise<void>((resolve) => {
            router.delete(`/network/private-networks/${network.id}`, {
                data: { name },
                onError: (errors) => setDeleteError(errors.name),
                onFinish: () => resolve(),
            });
        });

    const reapply = () =>
        router.post(
            `/network/private-networks/${network.id}/apply`,
            {},
            { preserveScroll: true, onSuccess: () => toast.success('Re-applying WireGuard') },
        );

    return (
        <AppShell
            breadcrumbs={[
                { title: 'Network', href: '/network' },
                { title: network.name, href: `/network/private-networks/${network.id}` },
            ]}
        >
            <Head title={network.name} />
            <div className="grid gap-6">
                <PageHeader
                    title={
                        <span className="flex items-center gap-2">
                            <Network className="text-fg-faint size-4" aria-hidden />
                            {network.name}
                        </span>
                    }
                    description={`WireGuard mesh · ${members.length} member${members.length === 1 ? '' : 's'}`}
                    actions={
                        can.manage && (
                            <>
                                <Button variant="primary" icon={<Plus />} onClick={() => setAdding(true)} disabled={availableServers.length === 0}>
                                    Add server
                                </Button>
                                <Menu
                                    label="Network actions"
                                    actions={[
                                        { label: 'Re-apply', icon: <RefreshCw />, onSelect: reapply, disabled: members.length === 0 },
                                        { type: 'separator' },
                                        { label: 'Delete network…', icon: <Trash2 />, danger: true, onSelect: () => setDeleting(true) },
                                    ]}
                                />
                            </>
                        )
                    }
                />

                <Section title="Configuration">
                    <KeyValue
                        columns={3}
                        items={[
                            { label: 'CIDR', value: network.cidr, mono: true, copy: network.cidr },
                            { label: 'Interface', value: network.interface, mono: true },
                            { label: 'Listen port', value: `UDP ${network.listen_port}` },
                        ]}
                    />
                </Section>

                <Section title="Members" description="Each member has a private address and a tunnel to every other member." bare>
                    <DataTable
                        label="Members"
                        rows={members}
                        rowKey={(member) => member.id}
                        defaultSort={{ column: 'server', direction: 'asc' }}
                        empty={{
                            icon: <Network />,
                            title: 'No members yet',
                            description: 'Add at least two servers to route traffic between them privately.',
                            action:
                                can.manage && availableServers.length > 0 ? (
                                    <Button variant="primary" size="sm" icon={<Plus />} onClick={() => setAdding(true)}>
                                        Add server
                                    </Button>
                                ) : undefined,
                        }}
                        columns={[
                            {
                                id: 'server',
                                header: 'Server',
                                sortValue: (member) => member.server_name,
                                cell: (member) => (
                                    <Link href={`/servers/${member.server_id}/private-network`} className="text-fg font-medium hover:underline">
                                        {member.server_name}
                                    </Link>
                                ),
                            },
                            {
                                id: 'address',
                                header: 'Address',
                                sortValue: (member) => member.address,
                                cell: (member) => <span className="font-mono text-xs">{member.address}</span>,
                            },
                            {
                                id: 'endpoint',
                                header: 'Endpoint',
                                hideOnMobile: true,
                                cell: (member) => (
                                    <span className="text-fg-muted font-mono text-xs">
                                        {member.public_ipv4 ? `${member.public_ipv4}:${network.listen_port}` : '—'}
                                    </span>
                                ),
                            },
                            {
                                id: 'key',
                                header: 'Public key',
                                hideOnMobile: true,
                                cell: (member) => (
                                    <span className="text-fg-faint block max-w-40 truncate font-mono text-xs" title={member.public_key}>
                                        {member.public_key || '—'}
                                    </span>
                                ),
                            },
                            {
                                id: 'status',
                                header: 'Status',
                                cell: (member) => (
                                    <span className="flex flex-wrap items-center gap-1.5">
                                        <ApplyStatusBadge status={member.status} />
                                        {member.key_status !== 'installed' && (
                                            <Tag tone={member.key_status === 'failed' ? 'danger' : 'info'}>key {member.key_status}</Tag>
                                        )}
                                        {member.error && <span className="text-danger text-xs">{member.error}</span>}
                                    </span>
                                ),
                            },
                        ]}
                        rowActions={
                            can.manage
                                ? (member) => [{ label: 'Remove from network', icon: <Unplug />, danger: true, onSelect: () => setRemoving(member) }]
                                : undefined
                        }
                    />
                </Section>
            </div>

            <Dialog
                open={adding}
                onOpenChange={setAdding}
                title={`Add a server to ${network.name}`}
                description="Only active servers that are not yet members are listed."
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setAdding(false)}>
                            Cancel
                        </Button>
                        <Button variant="primary" type="submit" form="add-member" loading={addForm.processing} disabled={!addForm.data.server_id}>
                            Add server
                        </Button>
                    </>
                }
            >
                <form id="add-member" onSubmit={add} className="grid gap-4">
                    <Field label="Server" error={addForm.errors.server_id}>
                        <Select
                            value={addForm.data.server_id || undefined}
                            onValueChange={(value) => addForm.setData('server_id', value)}
                            placeholder="Choose a server"
                            options={availableServers.map((server) => ({
                                value: server.id,
                                label: server.name,
                                description: [server.type_label, server.ipv4].filter(Boolean).join(' · '),
                            }))}
                        />
                    </Field>
                </form>
            </Dialog>

            <Dialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                size="sm"
                title={`Remove ${removing?.server_name}?`}
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setRemoving(null)}>
                            Cancel
                        </Button>
                        <Button variant="danger" onClick={remove} loading={removeBusy}>
                            Remove
                        </Button>
                    </>
                }
            >
                <p className="text-fg-muted text-sm">The server loses its private address and the other members drop their tunnel to it.</p>
            </Dialog>

            <ConfirmDestructive
                open={deleting}
                onOpenChange={(open) => {
                    setDeleting(open);
                    if (!open) setDeleteError(undefined);
                }}
                title={`Delete ${network.name}?`}
                description="WireGuard is removed from every member. Services talking over the private network lose connectivity."
                confirmText={network.name}
                confirmLabel="Delete network"
                onConfirm={destroy}
                error={deleteError}
            />
        </AppShell>
    );
}
