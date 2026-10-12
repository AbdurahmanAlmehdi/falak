import {
    AppShell,
    Button,
    Callout,
    Checkbox,
    ConfirmDestructive,
    DataTable,
    Dialog,
    Field,
    Input,
    KeyValue,
    ManagedBadge,
    PageHeader,
    RelativeTime,
    Section,
    Select,
    StatusBadge,
    Switch,
    Tag,
} from '@/components/falak';
import { HttpError, errorMessage, requestJson } from '@/lib/http';
import { formatBytes } from '@/lib/utils';
import { Head, Link, router, usePoll } from '@inertiajs/react';
import { ArrowRightLeft, Copy, Expand, HardDrive, Link2, Lock, Plus, Unlink } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import { SHOW_RELOAD, useVolumeAction } from '../components/api';
import { BackupsSection } from '../components/backups-section';
import { FileBrowser } from '../components/file-browser';
import { UsageBar } from '../components/volume-ui';
import {
    CONSISTENCY_OPTIONS,
    GIB,
    kindLabel,
    volumeStatus,
    type BackupSchedule,
    type Consistency,
    type ServerOption,
    type StorageProvider,
    type Volume,
    type VolumeAbilities,
    type VolumeAttachment,
    type VolumeBackup,
    type VolumeOperation,
} from '../types';

interface Props {
    volume: Volume;
    backups: VolumeBackup[];
    schedules: BackupSchedule[];
    operations: VolumeOperation[];
    storage_providers: StorageProvider[];
    servers: ServerOption[];
    attachable_sites: { id: string; name: string }[];
    browsable: boolean;
    download_max_bytes: number;
    can: VolumeAbilities;
}

type Dialogs = 'attach' | 'resize' | 'clone' | 'move' | 'delete' | null;

/** /volumes/{id}: one volume — where it is mounted, its size, backups, copies, files and deletion. */
export default function Show({
    volume,
    backups,
    schedules,
    operations,
    storage_providers,
    servers,
    attachable_sites,
    browsable,
    download_max_bytes,
    can,
}: Props) {
    const [dialog, setDialog] = useState<Dialogs>(null);
    const [detaching, setDetaching] = useState<VolumeAttachment | null>(null);
    const action = useVolumeAction();
    const portable = volume.kind === 'docker' || volume.kind === 'sized';
    const running =
        volume.status === 'pending' ||
        volume.status === 'deleting' ||
        operations.some((operation) => operation.status === 'running' || operation.status === 'pending') ||
        backups.some((backup) => backup.status === 'pending');

    usePoll(running ? 3_000 : 60_000, { only: SHOW_RELOAD });

    const backTo = volume.server ? `/servers/${volume.server.id}/volumes` : null;
    // A field falak.yaml sets (/volumes/<key>/<field>) is read-only here; the server answers 422 to a change.
    const managedField = (field: string) => !!volume.managed?.paths.some((path) => path.split('/').length === 4 && path.endsWith(`/${field}`));
    const deleteBlocked = volume.managed
        ? 'Declared in falak.yaml: remove it from the file (the file never deletes it on its own).'
        : volume.protected
          ? 'Protected volumes cannot be deleted: turn protection off first.'
          : volume.attachments.length > 0
            ? 'Detach it from its services first (or delete it with its service).'
            : null;

    return (
        <AppShell
            breadcrumbs={[
                ...(volume.server && backTo
                    ? [
                          { title: volume.server.name, href: `/servers/${volume.server.id}` },
                          { title: 'Volumes', href: backTo },
                      ]
                    : []),
                { title: volume.name, href: volume.url },
            ]}
        >
            <Head title={`${volume.name} · Volume`} />
            <div className="mx-auto grid w-full max-w-5xl gap-8">
                <PageHeader
                    title={
                        <span className="flex items-center gap-2">
                            <HardDrive className="text-fg-faint size-4" aria-hidden />
                            <span className="font-mono">{volume.name}</span>
                            {volume.protected && <Lock className="text-fg-faint size-3.5" aria-label="Protected" />}
                            <ManagedBadge config={volume.managed} />
                            <StatusBadge
                                status={volumeStatus(volume)}
                                label={volume.status_message && volume.status === 'active' ? 'Attention' : undefined}
                            />
                        </span>
                    }
                    description={`${kindLabel(volume.kind)} · ${volume.server?.name ?? 'every server of its site'}`}
                    actions={
                        can.manage && (
                            <>
                                {volume.kind === 'sized' && (
                                    <Button
                                        icon={<Expand />}
                                        onClick={() => setDialog('resize')}
                                        disabled={volume.status !== 'active' || managedField('size')}
                                    >
                                        Resize
                                    </Button>
                                )}
                                {portable && (
                                    <Button icon={<Copy />} onClick={() => setDialog('clone')} disabled={volume.status !== 'active'}>
                                        Clone
                                    </Button>
                                )}
                                {portable && !volume.compose && (
                                    <Button
                                        icon={<ArrowRightLeft />}
                                        onClick={() => setDialog('move')}
                                        disabled={volume.status !== 'active' || managedField('server')}
                                    >
                                        Move
                                    </Button>
                                )}
                            </>
                        )
                    }
                />

                {volume.status_message && (
                    <Callout
                        tone={volume.status === 'failed' ? 'danger' : 'warning'}
                        title={volume.status === 'failed' ? 'The volume could not be created' : undefined}
                    >
                        {volume.status_message}
                    </Callout>
                )}

                <Section title="Overview">
                    <div className="grid gap-4 sm:grid-cols-[1fr_auto] sm:items-center">
                        <UsageBar volume={volume} className="max-w-sm" />
                        {can.manage && (
                            <label className="text-fg-muted flex items-center gap-2 text-sm">
                                <Switch
                                    checked={volume.protected}
                                    disabled={action.busy || managedField('protected')}
                                    onCheckedChange={(checked) =>
                                        void action.run(
                                            'PATCH',
                                            `/volumes/${volume.id}`,
                                            { protected: checked },
                                            checked ? 'Volume protected' : 'Protection removed',
                                        )
                                    }
                                />
                                Protected
                            </label>
                        )}
                    </div>
                    <KeyValue
                        items={[
                            ...(volume.docker_name
                                ? [{ label: 'Docker volume', value: volume.docker_name, mono: true, copy: volume.docker_name }]
                                : []),
                            ...(volume.host_path
                                ? [
                                      {
                                          label: volume.kind === 'sized' ? 'Mountpoint' : 'Path',
                                          value: volume.host_path,
                                          mono: true,
                                          copy: volume.host_path,
                                      },
                                  ]
                                : []),
                            ...(volume.compose
                                ? [
                                      {
                                          label: 'Compose',
                                          value: `${volume.compose.key}${volume.compose.external ? ' (external: never deleted by Falak)' : ''}`,
                                      },
                                  ]
                                : []),
                            { label: 'Usage measured', value: <RelativeTime value={volume.used_at} fallback="Not yet" /> },
                            { label: 'Created', value: <RelativeTime value={volume.created_at} /> },
                        ]}
                    />
                </Section>

                <Section
                    title="Attached to"
                    description="Services that mount this volume. Attaching or detaching redeploys the service."
                    bare
                    aside={
                        can.manage &&
                        attachable_sites.length > 0 && (
                            <Button size="sm" icon={<Plus />} onClick={() => setDialog('attach')}>
                                Attach
                            </Button>
                        )
                    }
                >
                    <DataTable<VolumeAttachment>
                        label="Attachments"
                        rows={volume.attachments}
                        rowKey={(row) => row.id}
                        empty={{ icon: <Link2 />, title: 'Not attached', description: 'Attach it to a container service to mount it.', size: 'sm' }}
                        columns={[
                            {
                                id: 'service',
                                header: 'Service',
                                cell: (row) =>
                                    row.url ? (
                                        <Link href={row.url} className="hover:underline">
                                            {row.name}
                                        </Link>
                                    ) : (
                                        row.name
                                    ),
                            },
                            {
                                id: 'path',
                                header: volume.kind === 'shared_path' ? 'Path in the release' : 'Mount path',
                                cell: (row) => (
                                    <span className="flex items-center gap-2">
                                        <span className="font-mono text-xs">{row.mount_path}</span>
                                        {row.read_only && <Tag tone="faint">read-only</Tag>}
                                    </span>
                                ),
                            },
                        ]}
                        rowActions={
                            can.manage
                                ? (row) => [
                                      row.detachable && volume.managed
                                          ? { label: 'Mounted by falak.yaml', disabled: true }
                                          : row.detachable
                                            ? { label: 'Detach', icon: <Unlink />, danger: true, onSelect: () => setDetaching(row) }
                                            : {
                                                  label: row.type === 'compose_service' ? 'Set by the compose file' : 'Kept by the database',
                                                  disabled: true,
                                              },
                                  ]
                                : undefined
                        }
                    />
                </Section>

                <BackupsSection
                    volume={volume}
                    backups={backups}
                    schedules={schedules}
                    providers={storage_providers}
                    servers={servers}
                    canManage={can.manage}
                />

                {can.browse && browsable && volume.status !== 'pending' && (
                    <FileBrowser volumeId={volume.id} providers={storage_providers} maxBytes={download_max_bytes} />
                )}

                {operations.length > 0 && <OperationsSection operations={operations} />}

                {can.manage && (
                    <Section title="Danger zone" tone="danger">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <p className="text-fg-muted text-sm">
                                {deleteBlocked ??
                                    (volume.kind === 'docker' || volume.kind === 'sized'
                                        ? 'Deleting removes the volume and its data from the server. Backups in storage are kept.'
                                        : 'Falak forgets the volume; the files stay on the server.')}
                            </p>
                            <Button
                                variant="danger"
                                disabled={deleteBlocked !== null || volume.status === 'deleting'}
                                onClick={() => setDialog('delete')}
                            >
                                Delete volume
                            </Button>
                        </div>
                    </Section>
                )}
            </div>

            <AttachDialog
                open={dialog === 'attach'}
                onOpenChange={(open) => setDialog(open ? 'attach' : null)}
                volume={volume}
                sites={attachable_sites}
            />
            <ResizeDialog open={dialog === 'resize'} onOpenChange={(open) => setDialog(open ? 'resize' : null)} volume={volume} />
            <CopyDialog
                mode="clone"
                open={dialog === 'clone'}
                onOpenChange={(open) => setDialog(open ? 'clone' : null)}
                volume={volume}
                servers={servers}
                providers={storage_providers}
            />
            <CopyDialog
                mode="move"
                open={dialog === 'move'}
                onOpenChange={(open) => setDialog(open ? 'move' : null)}
                volume={volume}
                servers={servers.filter((server) => server.id !== volume.server?.id)}
                providers={storage_providers}
            />

            <Dialog
                open={detaching !== null}
                onOpenChange={(open) => !open && setDetaching(null)}
                size="sm"
                title={`Detach from ${detaching?.name}?`}
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setDetaching(null)}>
                            Cancel
                        </Button>
                        <Button
                            variant="danger"
                            loading={action.busy}
                            onClick={async () => {
                                if (
                                    (await action.run(
                                        'DELETE',
                                        `/volumes/attachments/${detaching?.id}`,
                                        undefined,
                                        'Detached',
                                        'The service redeploys without it.',
                                    )) !== null
                                )
                                    setDetaching(null);
                            }}
                        >
                            Detach
                        </Button>
                    </>
                }
            >
                <p className="text-fg-muted text-sm">
                    {volume.kind === 'shared_path'
                        ? 'The path stops being shared across releases; its files stay on the servers.'
                        : `The service is redeployed without ${detaching?.mount_path}. The volume and its data stay.`}
                </p>
            </Dialog>

            <ConfirmDestructive
                open={dialog === 'delete'}
                onOpenChange={(open) => setDialog(open ? 'delete' : null)}
                title={`Delete ${volume.name}?`}
                description="The volume and its data are removed from the server. This cannot be undone; backups in storage are kept."
                confirmText={volume.name}
                confirmLabel="Delete volume"
                error={action.errors.confirm ?? action.errors.volume}
                onConfirm={async (confirm) => {
                    try {
                        await requestJson(`/volumes/${volume.id}`, 'DELETE', { confirm });
                        setDialog(null);
                        router.visit(backTo ?? '/servers');
                    } catch (error) {
                        action.setErrors(error instanceof HttpError ? error.errors : { volume: errorMessage(error) });
                    }
                }}
            />
        </AppShell>
    );
}

function OperationsSection({ operations }: { operations: VolumeOperation[] }) {
    return (
        <Section title="Activity" bare>
            <DataTable<VolumeOperation>
                label="Recent operations"
                rows={operations}
                rowKey={(row) => row.id}
                columns={[
                    {
                        id: 'kind',
                        header: 'Operation',
                        cell: (row) => (
                            <span className="flex items-center gap-2 text-sm">
                                <span className="capitalize">{row.kind}</span>
                                {row.step && row.status === 'running' && <Tag tone="faint">{row.step}</Tag>}
                                <span className="text-fg-muted truncate text-xs">{describe(row)}</span>
                            </span>
                        ),
                    },
                    { id: 'when', header: 'Started', hideOnMobile: true, cell: (row) => <RelativeTime value={row.created_at} /> },
                    {
                        id: 'status',
                        header: 'Status',
                        align: 'right',
                        cell: (row) => (
                            <span title={row.error ?? undefined}>
                                <StatusBadge status={row.status} />
                            </span>
                        ),
                    },
                ]}
            />
        </Section>
    );
}

function describe(operation: VolumeOperation): string {
    const { meta, result } = operation;
    if (operation.error) return operation.error;
    switch (operation.kind) {
        case 'resize':
            return meta.from !== undefined && meta.to !== undefined ? `${formatBytes(meta.from)} → ${formatBytes(meta.to)}` : '';
        case 'download':
            return [meta.path || '/', result.size_bytes !== undefined ? formatBytes(result.size_bytes) : null].filter(Boolean).join(' · ');
        case 'clone':
        case 'move':
            return meta.source_name ? `from ${meta.source_name}` : '';
        default:
            return result.bytes !== undefined ? formatBytes(result.bytes) : '';
    }
}

function AttachDialog({
    open,
    onOpenChange,
    volume,
    sites,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    volume: Volume;
    sites: { id: string; name: string }[];
}) {
    const [form, setForm] = useState({ site_id: '', mount_path: '/data', read_only: false });
    const action = useVolumeAction();
    const readOnlyId = useId();

    useEffect(() => {
        if (open) {
            setForm({ site_id: sites[0]?.id ?? '', mount_path: '/data', read_only: volume.kind === 'bind' });
            action.setErrors({});
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    return (
        <Dialog
            open={open}
            onOpenChange={onOpenChange}
            title={`Attach ${volume.name}`}
            description="The service is redeployed so its container mounts the volume."
            footer={
                <>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button
                        variant="primary"
                        loading={action.busy}
                        disabled={!form.site_id || !form.mount_path.trim()}
                        onClick={async () => {
                            if (
                                (await action.run(
                                    'POST',
                                    `/volumes/${volume.id}/attachments`,
                                    form,
                                    'Volume attached',
                                    'The service is redeploying.',
                                )) !== null
                            )
                                onOpenChange(false);
                        }}
                    >
                        Attach and redeploy
                    </Button>
                </>
            }
        >
            <div className="grid gap-4">
                <Field label="Service" error={action.errors.site_id}>
                    <Select
                        value={form.site_id}
                        onValueChange={(value) => setForm({ ...form, site_id: value })}
                        options={sites.map((site) => ({ value: site.id, label: site.name }))}
                    />
                </Field>
                <Field label="Mount path" required error={action.errors.mount_path} hint="Absolute path inside the container.">
                    <Input mono value={form.mount_path} onChange={(event) => setForm({ ...form, mount_path: event.target.value })} />
                </Field>
                <label htmlFor={readOnlyId} className="text-fg-muted flex items-center gap-2 text-sm">
                    <Checkbox
                        id={readOnlyId}
                        checked={form.read_only}
                        onCheckedChange={(checked) => setForm({ ...form, read_only: checked === true })}
                    />
                    Read-only
                </label>
            </div>
        </Dialog>
    );
}

function ResizeDialog({ open, onOpenChange, volume }: { open: boolean; onOpenChange: (open: boolean) => void; volume: Volume }) {
    const current = (volume.size_limit_bytes ?? 0) / GIB;
    const [size, setSize] = useState('');
    const action = useVolumeAction();
    const bytes = Math.round(Number(size) * GIB);

    useEffect(() => {
        if (open) {
            setSize(String(Math.ceil(current * 2)));
            action.setErrors({});
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    return (
        <Dialog
            open={open}
            onOpenChange={onOpenChange}
            size="sm"
            title={`Resize ${volume.name}`}
            description="Grown online, without downtime. Volumes only grow."
            footer={
                <>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button
                        variant="primary"
                        loading={action.busy}
                        disabled={!(bytes > (volume.size_limit_bytes ?? 0))}
                        onClick={async () => {
                            if ((await action.run('POST', `/volumes/${volume.id}/resize`, { size_bytes: bytes }, 'Resizing the volume')) !== null)
                                onOpenChange(false);
                        }}
                    >
                        Grow
                    </Button>
                </>
            }
        >
            <Field label="New size" error={action.errors.size_bytes} hint={`Now ${formatBytes(volume.size_limit_bytes)}.`}>
                <Input
                    type="number"
                    min={Math.ceil(current)}
                    value={size}
                    onChange={(event) => setSize(event.target.value)}
                    suffix={<span className="text-fg-faint text-xs">GiB</span>}
                    autoFocus
                />
            </Field>
        </Dialog>
    );
}

/** Clone (a new volume, same or another server) or move (to another server, then the source is deleted). */
function CopyDialog({
    mode,
    open,
    onOpenChange,
    volume,
    servers,
    providers,
}: {
    mode: 'clone' | 'move';
    open: boolean;
    onOpenChange: (open: boolean) => void;
    volume: Volume;
    servers: ServerOption[];
    providers: StorageProvider[];
}) {
    const [form, setForm] = useState({ server_id: '', name: '', storage_provider_id: '', consistency: 'none' as Consistency, confirm: '' });
    const action = useVolumeAction();
    const otherServer = form.server_id !== '' && form.server_id !== volume.server?.id;

    useEffect(() => {
        if (open) {
            setForm({
                server_id: mode === 'clone' ? (volume.server?.id ?? '') : (servers[0]?.id ?? ''),
                name: mode === 'clone' ? `${volume.name}-copy`.slice(0, 63) : volume.name,
                storage_provider_id: providers[0]?.id ?? '',
                consistency: mode === 'move' ? 'stop' : 'none',
                confirm: '',
            });
            action.setErrors({});
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const submit = async () => {
        const body =
            mode === 'clone'
                ? {
                      server_id: form.server_id,
                      name: form.name.trim(),
                      consistency: form.consistency,
                      ...(otherServer ? { storage_provider_id: form.storage_provider_id } : {}),
                  }
                : { server_id: form.server_id, storage_provider_id: form.storage_provider_id, confirm: form.confirm };
        const done = await action.run(
            'POST',
            `/volumes/${volume.id}/${mode}`,
            body,
            mode === 'clone' ? 'Cloning the volume' : 'Moving the volume',
            mode === 'move' ? 'Its services switch over and redeploy once the data arrived.' : undefined,
        );
        if (done !== null) onOpenChange(false);
    };

    const needsStorage = mode === 'move' || otherServer;
    const ready =
        form.server_id !== '' &&
        (mode === 'move' ? form.confirm === volume.name : form.name.trim() !== '') &&
        (!needsStorage || form.storage_provider_id !== '');

    return (
        <Dialog
            open={open}
            onOpenChange={onOpenChange}
            title={mode === 'clone' ? `Clone ${volume.name}` : `Move ${volume.name} to another server`}
            description={
                mode === 'clone'
                    ? 'Copies the data into a new volume. To another server it travels through your backup storage.'
                    : 'Its services stop → archive → restore on the target → they switch to it and redeploy → this volume is deleted. Its services must already run on the target server.'
            }
            footer={
                <>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button variant={mode === 'move' ? 'danger' : 'primary'} loading={action.busy} disabled={!ready} onClick={() => void submit()}>
                        {mode === 'clone' ? 'Clone' : 'Move volume'}
                    </Button>
                </>
            }
        >
            <div className="grid gap-4">
                {servers.length === 0 && <Callout tone="info">There is no other server to move it to.</Callout>}
                <Field label="Server" error={action.errors.server_id}>
                    <Select
                        value={form.server_id}
                        onValueChange={(value) => setForm({ ...form, server_id: value })}
                        options={servers.map((server) => ({ value: server.id, label: server.name }))}
                    />
                </Field>
                {mode === 'clone' && (
                    <Field label="New volume name" required error={action.errors.name}>
                        <Input mono value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} />
                    </Field>
                )}
                {needsStorage && (
                    <Field label="Through storage" error={action.errors.storage_provider_id} hint="The archive is removed once restored.">
                        {providers.length > 0 ? (
                            <Select
                                value={form.storage_provider_id}
                                onValueChange={(value) => setForm({ ...form, storage_provider_id: value })}
                                options={providers.map((provider) => ({ value: provider.id, label: provider.name }))}
                            />
                        ) : (
                            <p className="text-fg-muted text-sm">Add backup storage first (Settings → Backup storage).</p>
                        )}
                    </Field>
                )}
                {mode === 'clone' && (
                    <Field label="Consistency" error={action.errors.consistency}>
                        <Select
                            value={form.consistency}
                            onValueChange={(value) => setForm({ ...form, consistency: value })}
                            options={CONSISTENCY_OPTIONS}
                        />
                    </Field>
                )}
                {mode === 'move' && (
                    <Callout tone="warning">
                        Downtime: the services using this volume stay stopped from the archive until they run on the target server (the time to copy
                        the data through storage, then redeploy).
                    </Callout>
                )}
                {mode === 'move' && (
                    <Field label={`Type ${volume.name} to confirm`} error={action.errors.confirm}>
                        <Input mono value={form.confirm} onChange={(event) => setForm({ ...form, confirm: event.target.value })} autoComplete="off" />
                    </Field>
                )}
                {action.errors.volume && <Callout tone="danger">{action.errors.volume}</Callout>}
            </div>
        </Dialog>
    );
}
