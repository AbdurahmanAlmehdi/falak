import { Button } from '@/components/falak/button';
import { DataTable } from '@/components/falak/data-table';
import { Dialog } from '@/components/falak/dialog';
import { Field } from '@/components/falak/field';
import { Input } from '@/components/falak/input';
import { RelativeTime } from '@/components/falak/relative-time';
import { Section } from '@/components/falak/section';
import { Select } from '@/components/falak/select';
import { Tag } from '@/components/falak/tag';
import { toast } from '@/components/falak/toast';
import NetworkLayout from '@/layouts/network-layout';
import { router, useForm } from '@inertiajs/react';
import { Ban, Plus, Settings2, Trash2 } from 'lucide-react';
import { useState, type FormEventHandler } from 'react';
import { DURATIONS, formatCount, type BlockDuration } from '../protection';

interface BlockRow {
    id: string;
    cidr: string;
    server: { id: string; name: string } | null;
    reason: string | null;
    source: 'manual' | 'auto';
    suspended_reason: string | null;
    expires_at: string | null;
    created_at: string;
    packets: number;
    bytes: number;
    last_hit_at: string | null;
}

interface AutoBan {
    enabled: boolean;
    failures: number;
    window_s: number;
    ban_s: number;
}

interface ServerRow {
    id: string;
    name: string;
    supported: boolean;
    status: string | null;
    error: string | null;
    failed: { cidr: string; error: string }[];
    blocked_packets: number | null;
    auto_ban: AutoBan;
}

interface Props {
    blocks: BlockRow[];
    servers: ServerRow[];
    yourIp: string | null;
    can: { protect: boolean };
}

const BAN_TIMES = [
    { value: '3600', label: '1 hour' },
    { value: '86400', label: '24 hours' },
    { value: '604800', label: '7 days' },
];

function BlockDialog({ servers, open, onOpenChange }: { servers: ServerRow[]; open: boolean; onOpenChange: (open: boolean) => void }) {
    const form = useForm({ address: '', scope: 'organization', server_id: '', duration: '24h' as BlockDuration, reason: '', confirm: false });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post('/network/blocks', {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(`${form.data.address} blocked`);
                form.reset();
                onOpenChange(false);
            },
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={onOpenChange}
            title="Block an address"
            description="An IPv4 / IPv6 address or a CIDR. Dropped before it reaches anything on the server."
        >
            <form onSubmit={submit} className="grid gap-4">
                <Field label="Address or CIDR" error={form.errors.address} required>
                    <Input
                        value={form.data.address}
                        onChange={(event) => {
                            // A confirmation is for one address: another one asks again.
                            const address = event.target.value;
                            form.setData((data) => ({ ...data, address, confirm: false }));
                            form.clearErrors('confirm');
                        }}
                        placeholder="203.0.113.7 or 2001:db8::/48"
                        autoFocus
                    />
                </Field>
                <Field label="Where" error={form.errors.server_id}>
                    <Select
                        value={form.data.scope === 'organization' ? 'organization' : form.data.server_id}
                        onValueChange={(value) =>
                            form.setData((data) => ({
                                ...data,
                                scope: value === 'organization' ? 'organization' : 'server',
                                server_id: value === 'organization' ? '' : value,
                            }))
                        }
                        options={[
                            { value: 'organization', label: 'All servers' },
                            ...servers.map((server) => ({ value: server.id, label: server.name })),
                        ]}
                    />
                </Field>
                <Field label="For how long">
                    <Select<BlockDuration>
                        value={form.data.duration}
                        onValueChange={(value) => form.setData('duration', value)}
                        options={DURATIONS}
                    />
                </Field>
                <Field label="Reason" error={form.errors.reason}>
                    <Input value={form.data.reason} maxLength={500} onChange={(event) => form.setData('reason', event.target.value)} />
                </Field>
                {form.errors.confirm && (
                    <label className="text-fg flex items-start gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.confirm}
                            onChange={(event) => form.setData('confirm', event.target.checked)}
                            className="mt-0.5"
                        />
                        <span>{form.errors.confirm}</span>
                    </label>
                )}
                <div className="flex justify-end gap-2">
                    <Button type="button" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button type="submit" variant="danger" loading={form.processing}>
                        Block
                    </Button>
                </div>
            </form>
        </Dialog>
    );
}

function AutoBanDialog({ server, onClose }: { server: ServerRow; onClose: () => void }) {
    const form = useForm({
        enabled: server.auto_ban.enabled,
        failures: String(server.auto_ban.failures),
        window_s: String(server.auto_ban.window_s),
        ban_s: String(server.auto_ban.ban_s),
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, failures: Number(data.failures), window_s: Number(data.window_s), ban_s: Number(data.ban_s) }));
        form.put(`/network/servers/${server.id}/protection`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && onClose()}
            title={`SSH auto-ban on ${server.name}`}
            description="Addresses with too many failed SSH logins are blocked for a while. Addresses that logged in recently, private ranges, your servers and the control plane are never banned."
        >
            <form onSubmit={submit} className="grid gap-4">
                <label className="text-fg flex items-center gap-2 text-sm">
                    <input type="checkbox" checked={form.data.enabled} onChange={(event) => form.setData('enabled', event.target.checked)} />
                    Ban brute-force sources automatically
                </label>
                <p className="text-fg-muted text-xs">
                    Turning it off stops new bans only: existing bans stay until they expire (unblock them in the list to lift them now).
                </p>
                <div className="grid grid-cols-2 gap-3">
                    <Field label="Failed logins" error={form.errors.failures}>
                        <Input
                            type="number"
                            min={3}
                            max={1000}
                            value={form.data.failures}
                            onChange={(event) => form.setData('failures', event.target.value)}
                        />
                    </Field>
                    <Field label="Within (minutes)" error={form.errors.window_s}>
                        <Input
                            type="number"
                            min={1}
                            max={1440}
                            value={String(Number(form.data.window_s) / 60)}
                            onChange={(event) => form.setData('window_s', String(Number(event.target.value) * 60))}
                        />
                    </Field>
                </div>
                <Field label="Ban for" error={form.errors.ban_s}>
                    <Select value={form.data.ban_s} onValueChange={(value) => form.setData('ban_s', value)} options={BAN_TIMES} />
                </Field>
                <div className="flex justify-end gap-2">
                    <Button type="button" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" variant="primary" loading={form.processing}>
                        Save
                    </Button>
                </div>
            </form>
        </Dialog>
    );
}

export default function Blocked({ blocks, servers, yourIp, can }: Props) {
    const [blocking, setBlocking] = useState(false);
    const [settings, setSettings] = useState<ServerRow | null>(null);

    const unblock = (block: BlockRow) =>
        router.delete(`/network/blocks/${block.id}`, { preserveScroll: true, onSuccess: () => toast.success(`${block.cidr} unblocked`) });

    return (
        <NetworkLayout
            section="blocked"
            title="Blocked IPs"
            description={
                <>
                    Addresses dropped at the firewall of your servers
                    {yourIp ? (
                        <>
                            {' '}
                            · your address is <span className="font-mono">{yourIp}</span> (never blocked)
                        </>
                    ) : null}
                </>
            }
            actions={
                can.protect && (
                    <Button variant="primary" icon={<Plus />} onClick={() => setBlocking(true)}>
                        Block an address
                    </Button>
                )
            }
        >
            <DataTable
                label="Blocked addresses"
                rows={blocks}
                rowKey={(block) => block.id}
                defaultSort={{ column: 'created', direction: 'desc' }}
                empty={{
                    icon: <Ban />,
                    title: 'Nothing blocked',
                    description: 'Block an address here, or from the client rows of a site’s HTTP and connection views.',
                }}
                columns={[
                    {
                        id: 'cidr',
                        header: 'Address',
                        sortValue: (block) => block.cidr,
                        cell: (block) => <span className="font-mono text-xs">{block.cidr}</span>,
                    },
                    { id: 'where', header: 'Where', cell: (block) => block.server?.name ?? 'All servers' },
                    {
                        id: 'reason',
                        header: 'Reason',
                        hideOnMobile: true,
                        cell: (block) => (
                            <span className="flex items-center gap-2">
                                {block.source === 'auto' && <Tag tone="warning">auto-ban</Tag>}
                                {block.suspended_reason && (
                                    <Tag tone="danger" title={block.suspended_reason}>
                                        lifted: protected address
                                    </Tag>
                                )}
                                <span className="text-fg-muted text-xs">{block.reason ?? '—'}</span>
                            </span>
                        ),
                    },
                    {
                        id: 'hits',
                        header: 'Hits',
                        align: 'right',
                        sortValue: (block) => block.packets,
                        cell: (block) => (
                            <span className="tabular text-xs" title={`${block.packets} packets, ${block.bytes} bytes`}>
                                {formatCount(block.packets)}
                                {block.last_hit_at && (
                                    <span className="text-fg-faint">
                                        {' '}
                                        · <RelativeTime value={block.last_hit_at} />
                                    </span>
                                )}
                            </span>
                        ),
                    },
                    {
                        id: 'expires',
                        header: 'Expires',
                        sortValue: (block) => block.expires_at ?? '9999',
                        cell: (block) =>
                            block.expires_at ? <RelativeTime value={block.expires_at} /> : <span className="text-fg-muted">never</span>,
                    },
                    {
                        id: 'created',
                        header: 'Blocked',
                        hideOnMobile: true,
                        sortValue: (block) => block.created_at,
                        cell: (block) => <RelativeTime value={block.created_at} />,
                    },
                ]}
                rowActions={
                    can.protect ? (block) => [{ label: 'Unblock', icon: <Trash2 />, danger: true, onSelect: () => unblock(block) }] : undefined
                }
            />

            <Section title="Servers" description="Blocks and SSH auto-ban need an agent with network protection (update the agent otherwise)." bare>
                <DataTable
                    label="Server protection"
                    rows={servers}
                    rowKey={(server) => server.id}
                    columns={[
                        { id: 'name', header: 'Server', cell: (server) => <span className="text-fg font-medium">{server.name}</span> },
                        {
                            id: 'status',
                            header: 'Status',
                            cell: (server) =>
                                !server.supported ? (
                                    <Tag tone="faint">update the agent</Tag>
                                ) : server.status === 'failed' ? (
                                    <span className="text-danger text-xs">failed{server.error ? `: ${server.error}` : ''}</span>
                                ) : (
                                    <span className="text-fg-muted text-xs">
                                        {server.status === null || server.status === 'idle'
                                            ? 'nothing to apply'
                                            : server.status === 'pending' && server.error
                                              ? `waiting: ${server.error}`
                                              : server.status}
                                        {server.failed.length > 0 && ` · ${server.failed.length} refused`}
                                    </span>
                                ),
                        },
                        {
                            id: 'dropped',
                            header: 'Dropped',
                            align: 'right',
                            cell: (server) => (server.blocked_packets !== null ? `${formatCount(server.blocked_packets)} pkts` : '—'),
                        },
                        {
                            id: 'autoban',
                            header: 'SSH auto-ban',
                            cell: (server) =>
                                server.auto_ban.enabled ? (
                                    <Tag tone="success">
                                        {server.auto_ban.failures} in {server.auto_ban.window_s / 60} min
                                    </Tag>
                                ) : (
                                    <span className="text-fg-faint text-xs">off</span>
                                ),
                        },
                    ]}
                    rowActions={
                        can.protect ? (server) => [{ label: 'SSH auto-ban…', icon: <Settings2 />, onSelect: () => setSettings(server) }] : undefined
                    }
                />
            </Section>

            {can.protect && <BlockDialog servers={servers} open={blocking} onOpenChange={setBlocking} />}
            {settings && <AutoBanDialog server={settings} onClose={() => setSettings(null)} />}
        </NetworkLayout>
    );
}
