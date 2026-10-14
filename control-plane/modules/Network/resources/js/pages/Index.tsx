import { Button } from '@/components/falak/button';
import { DataTable } from '@/components/falak/data-table';
import { Dialog } from '@/components/falak/dialog';
import { Field } from '@/components/falak/field';
import { Input } from '@/components/falak/input';
import { Section } from '@/components/falak/section';
import InfrastructureLayout from '@/layouts/infrastructure-layout';
import { Link, router, useForm } from '@inertiajs/react';
import { Ban, Gauge, Network, Plus, Scale, Shield } from 'lucide-react';
import { useState, type FormEventHandler } from 'react';
import { ApplyStatusBadge } from '../components/network-ui';
import { type FirewallStateSummary, type PrivateAddress } from '../types';

interface ServerRow {
    id: string;
    name: string;
    type: string;
    type_label: string;
    status: string;
    ipv4: string | null;
    rules_count: number;
    firewall: FirewallStateSummary | null;
    private_addresses: PrivateAddress[];
}

interface NetworkRow {
    id: string;
    name: string;
    cidr: string;
    interface: string;
    listen_port: number;
    members_count: number;
}

interface LoadBalancerRow {
    id: string;
    name: string;
    status: string;
    ipv4: string | null;
    ipv6: string | null;
    private_addresses: PrivateAddress[];
}

interface Props {
    servers: ServerRow[];
    networks: NetworkRow[];
    loadBalancers: LoadBalancerRow[];
    defaults: { cidr: string; listen_port: number };
    can: { manage: boolean };
}

function Addresses({ addresses }: { addresses: PrivateAddress[] }) {
    if (addresses.length === 0) {
        return <span className="text-fg-faint text-xs">—</span>;
    }

    return (
        <span className="flex flex-col gap-0.5">
            {addresses.map((address) => (
                <span key={address.network_id} className="font-mono text-xs">
                    {address.address} <span className="text-fg-faint font-sans">{address.network}</span>
                </span>
            ))}
        </span>
    );
}

export default function Index({ servers, networks, loadBalancers, defaults, can }: Props) {
    const [creating, setCreating] = useState(false);
    const form = useForm({ name: '', cidr: defaults.cidr, listen_port: String(defaults.listen_port) });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post('/network/private-networks', { preserveScroll: true, onSuccess: () => setCreating(false) });
    };

    const openCreate = () => {
        form.clearErrors();
        setCreating(true);
    };

    return (
        <InfrastructureLayout
            section="networks"
            description="WireGuard networks between your servers, their firewalls and load balancers."
            actions={
                <>
                    <Button icon={<Ban />} onClick={() => router.visit('/network/blocked')}>
                        Blocked IPs
                    </Button>
                    <Button icon={<Gauge />} onClick={() => router.visit('/network/rate-limits')}>
                        Rate limits
                    </Button>
                    {can.manage && (
                        <Button variant="primary" icon={<Plus />} onClick={openCreate}>
                            New network
                        </Button>
                    )}
                </>
            }
        >
            <DataTable
                label="Private networks"
                rows={networks}
                rowKey={(network) => network.id}
                onRowClick={(network) => router.visit(`/network/private-networks/${network.id}`)}
                defaultSort={{ column: 'name', direction: 'asc' }}
                empty={{
                    icon: <Network />,
                    title: 'No private networks',
                    description:
                        'Connect servers over an encrypted WireGuard mesh so databases, caches and internal services never need a public port.',
                    action: can.manage ? (
                        <Button variant="primary" size="sm" icon={<Plus />} onClick={openCreate}>
                            New network
                        </Button>
                    ) : undefined,
                }}
                columns={[
                    {
                        id: 'name',
                        header: 'Network',
                        sortValue: (network) => network.name,
                        cell: (network) => (
                            <Link
                                href={`/network/private-networks/${network.id}`}
                                className="text-fg font-medium hover:underline"
                                onClick={(event) => event.stopPropagation()}
                            >
                                {network.name}
                            </Link>
                        ),
                    },
                    { id: 'cidr', header: 'CIDR', cell: (network) => <span className="font-mono text-xs">{network.cidr}</span> },
                    {
                        id: 'interface',
                        header: 'Interface',
                        hideOnMobile: true,
                        cell: (network) => (
                            <span className="text-fg-muted font-mono text-xs">
                                {network.interface} · UDP {network.listen_port}
                            </span>
                        ),
                    },
                    {
                        id: 'members',
                        header: 'Members',
                        align: 'right',
                        sortValue: (network) => network.members_count,
                        cell: (network) => network.members_count,
                    },
                ]}
            />

            <Section title="Firewalls" description="Per-server nftables rulesets. Open a server to edit its rules." bare>
                <DataTable
                    label="Server firewalls"
                    rows={servers}
                    rowKey={(server) => server.id}
                    onRowClick={(server) => router.visit(`/servers/${server.id}/firewall`)}
                    defaultSort={{ column: 'name', direction: 'asc' }}
                    empty={{
                        icon: <Shield />,
                        title: 'No servers yet',
                        description: 'Every server gets a default-deny firewall when it is provisioned.',
                        size: 'sm',
                    }}
                    columns={[
                        {
                            id: 'name',
                            header: 'Server',
                            sortValue: (server) => server.name,
                            cell: (server) => (
                                <span className="grid py-1.5">
                                    <span className="text-fg font-medium">{server.name}</span>
                                    <span className="text-fg-faint text-xs">{server.type_label}</span>
                                </span>
                            ),
                        },
                        { id: 'firewall', header: 'Firewall', cell: (server) => <ApplyStatusBadge status={server.firewall?.status} /> },
                        {
                            id: 'rules',
                            header: 'Rules',
                            align: 'right',
                            sortValue: (server) => server.rules_count,
                            cell: (server) => server.rules_count,
                        },
                        {
                            id: 'private',
                            header: 'Private addresses',
                            hideOnMobile: true,
                            cell: (server) => <Addresses addresses={server.private_addresses} />,
                        },
                    ]}
                />
            </Section>

            {loadBalancers.length > 0 && (
                <Section title="Load balancers" description="Caddy load balancers in front of your web servers." bare>
                    <DataTable
                        label="Load balancers"
                        rows={loadBalancers}
                        rowKey={(lb) => lb.id}
                        onRowClick={(lb) => router.visit(`/servers/${lb.id}`)}
                        columns={[
                            {
                                id: 'name',
                                header: 'Server',
                                cell: (lb) => (
                                    <span className="text-fg flex items-center gap-2 font-medium">
                                        <Scale className="text-fg-faint size-4" aria-hidden /> {lb.name}
                                    </span>
                                ),
                            },
                            { id: 'ipv4', header: 'Public IPv4', cell: (lb) => <span className="font-mono text-xs">{lb.ipv4 ?? '—'}</span> },
                            {
                                id: 'ipv6',
                                header: 'Public IPv6',
                                hideOnMobile: true,
                                cell: (lb) => <span className="font-mono text-xs">{lb.ipv6 ?? '—'}</span>,
                            },
                            { id: 'private', header: 'Private', hideOnMobile: true, cell: (lb) => <Addresses addresses={lb.private_addresses} /> },
                        ]}
                    />
                </Section>
            )}

            <Dialog
                open={creating}
                onOpenChange={setCreating}
                title="New private network"
                description="Add servers to it afterwards; each gets an address and a WireGuard peer on every member."
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setCreating(false)}>
                            Cancel
                        </Button>
                        <Button variant="primary" type="submit" form="create-network" loading={form.processing} disabled={!form.data.name.trim()}>
                            Create network
                        </Button>
                    </>
                }
            >
                <form id="create-network" onSubmit={submit} className="grid gap-4">
                    <Field label="Name" required error={form.errors.name}>
                        <Input
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                            placeholder="backend"
                            autoFocus
                        />
                    </Field>
                    <div className="grid grid-cols-2 gap-4">
                        <Field label="CIDR" error={form.errors.cidr}>
                            <Input value={form.data.cidr} onChange={(event) => form.setData('cidr', event.target.value)} mono />
                        </Field>
                        <Field label="Listen port (UDP)" error={form.errors.listen_port}>
                            <Input
                                type="number"
                                value={form.data.listen_port}
                                onChange={(event) => form.setData('listen_port', event.target.value)}
                                mono
                            />
                        </Field>
                    </div>
                </form>
            </Dialog>
        </InfrastructureLayout>
    );
}
