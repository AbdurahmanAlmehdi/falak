import {
    AppShell,
    Button,
    ConfirmDestructive,
    DataTable,
    Dialog,
    Field,
    Input,
    PageHeader,
    Segmented,
    Select,
    StatusBadge,
    Textarea,
    toast,
} from '@/components/falak';
import { HttpError, errorMessage, requestJson } from '@/lib/http';
import { Head, Link, router, usePoll } from '@inertiajs/react';
import { Box, Plus, Skull } from 'lucide-react';
import { useState, type FormEventHandler } from 'react';
import { formatMemory, formatTimeLeft, isLive, sandboxBadge, secondsLeft, ttlLabel, useNow, type Sandbox } from '../types';

interface Limits {
    max_running: number;
    max_ttl: number;
    default_ttl: number;
    max_cpus: number;
    max_memory_mb: number;
    max_disk_mb: number;
}

interface Props {
    sandboxes: Sandbox[];
    servers: { id: string; name: string }[];
    limits: Limits;
    can: { use: boolean };
}

const IMAGES = ['ubuntu:24.04', 'node:22', 'python:3.13'];

const SIZES = {
    small: { label: 'Small', cpus: 1, memory_mb: 1024, disk_mb: 10240 },
    medium: { label: 'Medium', cpus: 2, memory_mb: 2048, disk_mb: 20480 },
    large: { label: 'Large', cpus: 4, memory_mb: 4096, disk_mb: 40960 },
} as const;

type Size = keyof typeof SIZES;

const TTLS = [1800, 3600, 7200, 14400, 28800, 86400];

/** /sandboxes: the organization's sandboxes (live ones and those killed in the last day). */
export default function Index({ sandboxes, servers, limits, can }: Props) {
    const [creating, setCreating] = useState(false);
    const [killing, setKilling] = useState<Sandbox | null>(null);
    const now = useNow();

    usePoll(sandboxes.some((sandbox) => sandbox.status === 'creating') ? 3_000 : 30_000, { only: ['sandboxes'] });

    const kill = async (sandbox: Sandbox) => {
        try {
            await requestJson(`/sandboxes/${sandbox.id}`, 'DELETE');
            toast.success(`Killed ${sandbox.name}`);
            setKilling(null);
            router.reload({ only: ['sandboxes'] });
        } catch (error) {
            toast.error('Could not kill the sandbox', errorMessage(error));
        }
    };

    const newButton = can.use && (
        <Button variant="primary" icon={<Plus />} onClick={() => setCreating(true)} disabled={servers.length === 0}>
            New sandbox
        </Button>
    );

    return (
        <AppShell breadcrumbs={[{ title: 'Sandboxes', href: '/sandboxes' }]}>
            <Head title="Sandboxes" />
            <div className="mx-auto grid w-full max-w-6xl gap-6">
                <PageHeader
                    title="Sandboxes"
                    description={`Disposable gVisor machines for agents and untrusted code: root inside, nothing reaches your servers. Up to ${limits.max_running} at a time.`}
                    actions={newButton}
                />

                <DataTable
                    label="Sandboxes"
                    rows={sandboxes}
                    rowKey={(sandbox) => sandbox.id}
                    onRowClick={(sandbox) => router.visit(`/sandboxes/${sandbox.id}`)}
                    empty={{
                        icon: <Box />,
                        title: 'No sandboxes',
                        description:
                            servers.length === 0
                                ? 'Sandboxes need an active app server with sandboxes enabled and an up-to-date agent.'
                                : 'Create one here, with `falak sandbox create`, or let an agent create its own through `falak mcp`.',
                        action: newButton || undefined,
                    }}
                    rowActions={(sandbox) =>
                        can.use && sandbox.status !== 'killed'
                            ? [{ label: 'Kill', icon: <Skull />, danger: true, onSelect: () => setKilling(sandbox) }]
                            : []
                    }
                    columns={[
                        {
                            id: 'name',
                            header: 'Name',
                            sortValue: (sandbox) => sandbox.name,
                            cell: (sandbox) => (
                                <Link
                                    href={`/sandboxes/${sandbox.id}`}
                                    className="text-fg font-mono font-medium hover:underline"
                                    onClick={(event) => event.stopPropagation()}
                                >
                                    {sandbox.name}
                                </Link>
                            ),
                        },
                        {
                            id: 'image',
                            header: 'Image',
                            hideOnMobile: true,
                            cell: (sandbox) => <span className="font-mono text-xs">{sandbox.image}</span>,
                        },
                        { id: 'server', header: 'Server', hideOnMobile: true, cell: (sandbox) => sandbox.server.name ?? '—' },
                        {
                            id: 'status',
                            header: 'Status',
                            cell: (sandbox) => {
                                const badge = sandboxBadge(sandbox);

                                return <StatusBadge status={badge.status} label={badge.label} />;
                            },
                        },
                        {
                            id: 'left',
                            header: 'Time left',
                            sortValue: (sandbox) => (isLive(sandbox) ? secondsLeft(sandbox, now) : -1),
                            cell: (sandbox) =>
                                isLive(sandbox) ? (
                                    <span className="tabular-nums">{formatTimeLeft(secondsLeft(sandbox, now))}</span>
                                ) : (
                                    <span className="text-fg-faint text-xs">{sandbox.kill_reason ?? '—'}</span>
                                ),
                        },
                        {
                            id: 'size',
                            header: 'CPU / RAM',
                            hideOnMobile: true,
                            cell: (sandbox) => (
                                <span className="text-fg-muted tabular-nums">
                                    {sandbox.cpus} · {formatMemory(sandbox.memory_mb)}
                                </span>
                            ),
                        },
                        {
                            id: 'created_by',
                            header: 'Created by',
                            hideOnMobile: true,
                            cell: (sandbox) => (
                                <span className="text-fg-muted">
                                    {sandbox.created_by.name ?? '—'}
                                    {sandbox.created_by.type === 'token' && <span className="text-fg-faint"> (token)</span>}
                                </span>
                            ),
                        },
                    ]}
                />
            </div>

            {can.use && <CreateSandboxDialog open={creating} onOpenChange={setCreating} servers={servers} limits={limits} />}

            <ConfirmDestructive
                open={killing !== null}
                onOpenChange={(open) => !open && setKilling(null)}
                title={`Kill ${killing?.name ?? 'sandbox'}?`}
                description="Its container, files and network go away at once. This cannot be undone."
                confirmText={killing?.name ?? ''}
                confirmLabel="Kill sandbox"
                onConfirm={() => (killing ? kill(killing) : undefined)}
            />
        </AppShell>
    );
}

function CreateSandboxDialog({
    open,
    onOpenChange,
    servers,
    limits,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    servers: Props['servers'];
    limits: Limits;
}) {
    const [image, setImage] = useState<string>(IMAGES[0]);
    const [customImage, setCustomImage] = useState('');
    const [size, setSize] = useState<Size>('small');
    const [ttl, setTtl] = useState(String(limits.default_ttl));
    const [network, setNetwork] = useState<'internet' | 'none' | 'allowlist'>('internet');
    const [allowHosts, setAllowHosts] = useState('');
    const [server, setServer] = useState('auto');
    const [name, setName] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);

    const submit: FormEventHandler = async (event) => {
        event.preventDefault();
        setBusy(true);
        setErrors({});

        try {
            const { data } = await requestJson<{ data: Sandbox }>('/sandboxes', 'POST', {
                image: image === 'custom' ? customImage.trim() : image,
                name: name.trim() || null,
                server: server === 'auto' ? null : server,
                cpus: SIZES[size].cpus,
                memory_mb: SIZES[size].memory_mb,
                disk_mb: SIZES[size].disk_mb,
                ttl: Number(ttl),
                network,
                allow_hosts: network === 'allowlist' ? allowHosts.split(/[\s,]+/).filter(Boolean) : [],
            });
            toast.success(`Creating ${data.name}`);
            onOpenChange(false);
            router.visit(`/sandboxes/${data.id}`);
        } catch (error) {
            if (error instanceof HttpError && Object.keys(error.errors).length > 0) {
                setErrors(error.errors);
            } else {
                toast.error('Could not create the sandbox', errorMessage(error));
            }
        } finally {
            setBusy(false);
        }
    };

    const formError = errors.sandbox ?? errors.server;

    return (
        <Dialog
            open={open}
            onOpenChange={onOpenChange}
            title="New sandbox"
            description="A fresh Linux machine on one of your servers. It is killed when its time runs out."
            footer={
                <>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" form="create-sandbox" loading={busy}>
                        Create sandbox
                    </Button>
                </>
            }
        >
            <form id="create-sandbox" onSubmit={submit} className="grid gap-4">
                <Field label="Image" error={errors.image}>
                    <Select
                        value={image}
                        onValueChange={setImage}
                        options={[...IMAGES.map((value) => ({ value, label: value })), { value: 'custom', label: 'Other image…' }]}
                    />
                </Field>
                {image === 'custom' && (
                    <Field label="Image reference" error={errors.image}>
                        <Input mono value={customImage} placeholder="golang:1.23" onChange={(event) => setCustomImage(event.target.value)} />
                    </Field>
                )}
                <Field
                    label="Size"
                    error={errors.cpus ?? errors.memory_mb ?? errors.disk_mb}
                    hint={`${SIZES[size].cpus} CPU · ${formatMemory(SIZES[size].memory_mb)} RAM · ${formatMemory(SIZES[size].disk_mb)} disk`}
                >
                    <Segmented
                        label="Size"
                        size="md"
                        value={size}
                        onValueChange={setSize}
                        options={(Object.keys(SIZES) as Size[])
                            .filter((key) => SIZES[key].cpus <= limits.max_cpus && SIZES[key].memory_mb <= limits.max_memory_mb)
                            .map((key) => ({ value: key, label: SIZES[key].label }))}
                    />
                </Field>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Time to live" error={errors.ttl}>
                        <Select
                            value={ttl}
                            onValueChange={setTtl}
                            options={TTLS.filter((seconds) => seconds <= limits.max_ttl).map((seconds) => ({
                                value: String(seconds),
                                label: ttlLabel(seconds),
                            }))}
                        />
                    </Field>
                    <Field label="Network" error={errors.network}>
                        <Select
                            value={network}
                            onValueChange={setNetwork}
                            options={[
                                { value: 'internet', label: 'Internet', description: 'Not your servers or private networks' },
                                { value: 'allowlist', label: 'Allowlist', description: 'Only the hosts you list' },
                                { value: 'none', label: 'None', description: 'No network at all' },
                            ]}
                        />
                    </Field>
                </div>
                {network === 'allowlist' && (
                    <Field label="Allowed hosts" error={errors.allow_hosts} hint="One per line; *.example.com covers its subdomains.">
                        <Textarea
                            mono
                            rows={3}
                            value={allowHosts}
                            placeholder={'github.com\nregistry.npmjs.org'}
                            onChange={(event) => setAllowHosts(event.target.value)}
                        />
                    </Field>
                )}
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Server" error={errors.server}>
                        <Select
                            value={server}
                            onValueChange={setServer}
                            options={[{ value: 'auto', label: 'Most free memory' }, ...servers.map((s) => ({ value: s.id, label: s.name }))]}
                        />
                    </Field>
                    <Field label="Name" error={errors.name} hint="Optional; one is made up.">
                        <Input mono value={name} placeholder="brave-otter" onChange={(event) => setName(event.target.value)} />
                    </Field>
                </div>
                {formError && formError !== errors.server && <p className="text-danger text-sm">{formError}</p>}
            </form>
        </Dialog>
    );
}
