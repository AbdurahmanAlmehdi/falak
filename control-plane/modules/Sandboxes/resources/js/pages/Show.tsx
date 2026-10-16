import {
    AppShell,
    Button,
    Callout,
    ConfirmDestructive,
    DataTable,
    Dialog,
    EmptyState,
    Field,
    Input,
    KeyValue,
    PageHeader,
    RelativeTime,
    Section,
    Segmented,
    Select,
    SkeletonRows,
    StatusBadge,
    Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
    toast,
} from '@/components/falak';
import { HttpError, errorMessage, requestJson } from '@/lib/http';
import { formatBytes } from '@/lib/utils';
import { Head, router, usePoll } from '@inertiajs/react';
import { Box, Camera, ChevronUp, Clock, Database, File, Folder, Pause, Play, Skull, SquareTerminal } from 'lucide-react';
import { useCallback, useEffect, useState, type FormEventHandler } from 'react';
import { SnapshotsTable } from '../snapshots';
import {
    formatMemory,
    formatTimeLeft,
    isLive,
    sandboxBadge,
    secondsLeft,
    useNow,
    type FileEntry,
    type Sandbox,
    type SandboxExec,
    type Snapshot,
} from '../types';

interface DatabaseChoice {
    instance: string;
    name: string;
    engine: string;
    databases: string[];
}

interface Props {
    sandbox: Sandbox;
    execs: SandboxExec[];
    snapshots: Snapshot[];
    databases: DatabaseChoice[];
    limits: { max_ttl: number; default_ttl: number; max_fork_count: number };
    can: { update: boolean; operate: boolean; kill: boolean; db: boolean };
}

const EXTENSIONS = [
    { value: '1800', label: '+30 minutes' },
    { value: '3600', label: '+1 hour' },
    { value: '7200', label: '+2 hours' },
];

/** /sandboxes/{id}: one sandbox — time left, console, the exec log and a read-only file browser. */
export default function Show({ sandbox, execs, snapshots, databases, limits, can }: Props) {
    const now = useNow();
    const [killing, setKilling] = useState(false);
    const [extendBy, setExtendBy] = useState('1800');
    const [busy, setBusy] = useState(false);
    const badge = sandboxBadge(sandbox);
    const running = sandbox.status === 'running';
    const started = running || sandbox.status === 'paused';
    const [snapshotting, setSnapshotting] = useState(false);
    const [copying, setCopying] = useState(false);

    usePoll(sandbox.status === 'creating' || snapshots.some((snapshot) => snapshot.status === 'creating') ? 3_000 : 15_000, {
        only: ['sandbox', 'execs', 'snapshots'],
    });

    const pauseOrResume = async () => {
        setBusy(true);
        try {
            await requestJson(`/sandboxes/${sandbox.id}/${running ? 'pause' : 'resume'}`, 'POST');
            toast.success(running ? 'Paused' : 'Resumed');
            router.reload({ only: ['sandbox'] });
        } catch (error) {
            toast.error(running ? 'Could not pause the sandbox' : 'Could not resume the sandbox', errorMessage(error));
        } finally {
            setBusy(false);
        }
    };

    const extend = async () => {
        setBusy(true);
        try {
            await requestJson(`/sandboxes/${sandbox.id}`, 'PATCH', { extend: Number(extendBy) });
            toast.success('Time extended');
            router.reload({ only: ['sandbox'] });
        } catch (error) {
            toast.error('Could not extend the sandbox', errorMessage(error));
        } finally {
            setBusy(false);
        }
    };

    const kill = async () => {
        try {
            await requestJson(`/sandboxes/${sandbox.id}`, 'DELETE');
            toast.success(`Killed ${sandbox.name}`);
            setKilling(false);
            router.reload();
        } catch (error) {
            toast.error('Could not kill the sandbox', errorMessage(error));
        }
    };

    const openConsole = () =>
        router.post(
            `/sandboxes/${sandbox.id}/console`,
            {},
            { onError: (errors) => toast.error('Could not open a console', Object.values(errors)[0]) },
        );

    return (
        <AppShell
            breadcrumbs={[
                { title: 'Sandboxes', href: '/sandboxes' },
                { title: sandbox.name, href: `/sandboxes/${sandbox.id}` },
            ]}
        >
            <Head title={`${sandbox.name} · Sandbox`} />
            <div className="mx-auto grid w-full max-w-5xl gap-8">
                <PageHeader
                    title={
                        <span className="flex items-center gap-2">
                            <Box className="text-fg-faint size-4" aria-hidden />
                            <span className="font-mono">{sandbox.name}</span>
                            <StatusBadge status={badge.status} label={badge.label} />
                            {isLive(sandbox) && (
                                <span className="text-fg-muted flex items-center gap-1 text-sm font-normal tabular-nums">
                                    <Clock className="size-3.5" aria-hidden /> {formatTimeLeft(secondsLeft(sandbox, now))} left
                                </span>
                            )}
                        </span>
                    }
                    description={`${sandbox.image} · ${sandbox.server.name ?? 'unknown server'}`}
                    actions={
                        isLive(sandbox) && (
                            <>
                                {can.update && (
                                    <>
                                        <Select
                                            value={extendBy}
                                            onValueChange={setExtendBy}
                                            options={EXTENSIONS}
                                            aria-label="Extend by"
                                            className="w-36"
                                        />
                                        <Button onClick={() => void extend()} loading={busy}>
                                            Extend
                                        </Button>
                                    </>
                                )}
                                {can.operate && started && (
                                    <Button icon={running ? <Pause /> : <Play />} onClick={() => void pauseOrResume()} loading={busy}>
                                        {running ? 'Pause' : 'Resume'}
                                    </Button>
                                )}
                                {can.db && started && (
                                    <Button icon={<Database />} onClick={() => setCopying(true)}>
                                        Copy database
                                    </Button>
                                )}
                                {can.kill && (
                                    <Button variant="danger" icon={<Skull />} onClick={() => setKilling(true)}>
                                        Kill
                                    </Button>
                                )}
                            </>
                        )
                    }
                />

                {sandbox.status === 'failed' && (
                    <Callout tone="danger" title="The sandbox could not be created">
                        {sandbox.error ?? sandbox.kill_reason}
                    </Callout>
                )}
                {sandbox.status === 'paused' && (
                    <Callout tone="info" title="Paused">
                        It uses no CPU; its memory stays allocated and its time keeps running. An exec, file call or console resumes it.
                    </Callout>
                )}
                {sandbox.status === 'killed' && sandbox.kill_reason && (
                    <Callout tone="info" title="Killed">
                        Reason: {sandbox.kill_reason}
                    </Callout>
                )}

                <Section title="Overview">
                    <KeyValue
                        columns={3}
                        items={[
                            { label: 'Sandbox ID', value: sandbox.id, mono: true, copy: sandbox.id },
                            {
                                label: 'Resources',
                                value: `${sandbox.cpus} CPU · ${formatMemory(sandbox.memory_mb)} · ${formatMemory(sandbox.disk_mb)} disk`,
                            },
                            {
                                label: 'Network',
                                value: sandbox.network === 'allowlist' ? `allowlist (${sandbox.allow_hosts.join(', ')})` : sandbox.network,
                            },
                            { label: 'IP', value: sandbox.ip ?? '—', mono: true },
                            { label: 'Created', value: <RelativeTime value={sandbox.created_at} /> },
                            {
                                label: 'Created by',
                                value: `${sandbox.created_by.name ?? '—'}${sandbox.created_by.type === 'token' ? ' (token)' : ''}`,
                            },
                            {
                                label: 'Idle pause',
                                value: sandbox.idle_pause_seconds ? `after ${Math.round(sandbox.idle_pause_seconds / 60)} min` : 'never',
                            },
                            ...(sandbox.last_activity_at ? [{ label: 'Last used', value: <RelativeTime value={sandbox.last_activity_at} /> }] : []),
                            ...(sandbox.from_snapshot
                                ? [{ label: 'From snapshot', value: sandbox.from_snapshot.name ?? sandbox.from_snapshot.id, mono: true }]
                                : []),
                            ...(sandbox.env_keys.length > 0 ? [{ label: 'Environment', value: sandbox.env_keys.join(', '), mono: true }] : []),
                            ...(sandbox.repo
                                ? [{ label: 'Repository', value: sandbox.repo.url + (sandbox.repo.ref ? `@${sandbox.repo.ref}` : ''), mono: true }]
                                : []),
                        ]}
                    />
                </Section>

                <Tabs defaultValue={started && can.operate ? 'console' : 'activity'}>
                    <TabsList>
                        <TabsTrigger value="console">Console</TabsTrigger>
                        <TabsTrigger value="activity" badge={execs.length > 0 ? execs.length : undefined}>
                            Activity
                        </TabsTrigger>
                        <TabsTrigger value="files">Files</TabsTrigger>
                        <TabsTrigger value="snapshots" badge={snapshots.length > 0 ? snapshots.length : undefined}>
                            Snapshots
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent value="console" className="pt-4">
                        <EmptyState
                            icon={<SquareTerminal />}
                            title={started ? 'A root shell inside the sandbox' : 'The console opens once the sandbox runs'}
                            description="Everything you type stays inside the sandbox. From a terminal: falak sandbox ssh."
                            action={
                                can.operate && started ? (
                                    <Button variant="primary" icon={<SquareTerminal />} onClick={openConsole}>
                                        Open console
                                    </Button>
                                ) : undefined
                            }
                        />
                    </TabsContent>

                    <TabsContent value="activity" className="pt-4">
                        <DataTable
                            label="Exec log"
                            rows={execs}
                            rowKey={(exec) => exec.id}
                            empty={{
                                icon: <SquareTerminal />,
                                title: 'Nothing ran yet',
                                description: 'Every command and file write is recorded here (output is not kept).',
                            }}
                            columns={[
                                {
                                    id: 'cmd',
                                    header: 'Command',
                                    cell: (exec) => <span className="font-mono text-xs break-all">{exec.cmd}</span>,
                                },
                                {
                                    id: 'exit',
                                    header: 'Exit',
                                    align: 'right',
                                    cell: (exec) =>
                                        exec.timed_out ? (
                                            <StatusBadge status="failed" label="Timed out" />
                                        ) : (
                                            <span className={exec.exit_code === 0 ? 'text-fg-muted tabular-nums' : 'text-danger tabular-nums'}>
                                                {exec.exit_code ?? '—'}
                                            </span>
                                        ),
                                },
                                {
                                    id: 'duration',
                                    header: 'Duration',
                                    align: 'right',
                                    hideOnMobile: true,
                                    cell: (exec) => (
                                        <span className="text-fg-muted tabular-nums">
                                            {exec.duration_ms !== null ? `${exec.duration_ms} ms` : '—'}
                                        </span>
                                    ),
                                },
                                {
                                    id: 'actor',
                                    header: 'By',
                                    hideOnMobile: true,
                                    cell: (exec) => <span className="text-fg-muted">{exec.actor ?? '—'}</span>,
                                },
                                { id: 'at', header: 'When', cell: (exec) => <RelativeTime value={exec.started_at} className="text-fg-muted" /> },
                            ]}
                        />
                    </TabsContent>

                    <TabsContent value="snapshots" className="grid gap-3 pt-4">
                        {can.operate && started && (
                            <div>
                                <Button variant="primary" icon={<Camera />} onClick={() => setSnapshotting(true)}>
                                    Take snapshot
                                </Button>
                            </div>
                        )}
                        <SnapshotsTable
                            snapshots={snapshots}
                            maxTtl={limits.max_ttl}
                            defaultTtl={limits.default_ttl}
                            maxForkCount={limits.max_fork_count}
                            empty="A snapshot keeps this sandbox's memory, processes and files; fork it into new sandboxes later."
                        />
                    </TabsContent>

                    <TabsContent value="files" className="pt-4">
                        {started && can.operate ? (
                            <FileBrowser sandbox={sandbox} />
                        ) : (
                            <EmptyState icon={<Folder />} title="Files can be browsed while the sandbox runs" size="sm" />
                        )}
                    </TabsContent>
                </Tabs>
            </div>

            <ConfirmDestructive
                open={killing}
                onOpenChange={setKilling}
                title={`Kill ${sandbox.name}?`}
                description="Its container, files and network go away at once. This cannot be undone."
                confirmText={sandbox.name}
                confirmLabel="Kill sandbox"
                onConfirm={kill}
            />

            {snapshotting && <SnapshotDialog sandbox={sandbox} onOpenChange={setSnapshotting} />}
            {copying && <DatabaseCopyDialog sandbox={sandbox} databases={databases} onOpenChange={setCopying} />}
        </AppShell>
    );
}

/** Read-only: list directories, download files (sandbox.files.list / read). */
function FileBrowser({ sandbox }: { sandbox: Sandbox }) {
    const [path, setPath] = useState('/');
    const [entries, setEntries] = useState<FileEntry[] | null>(null);
    const [truncated, setTruncated] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const load = useCallback(
        async (dir: string) => {
            setEntries(null);
            setError(null);
            try {
                const { data } = await requestJson<{ data: { entries: FileEntry[]; truncated: boolean } }>(
                    `/sandboxes/${sandbox.id}/files?list=1&path=${encodeURIComponent(dir)}`,
                );
                setEntries([...data.entries].sort((a, b) => (a.type === 'dir' ? 0 : 1) - (b.type === 'dir' ? 0 : 1) || a.name.localeCompare(b.name)));
                setTruncated(data.truncated);
            } catch (e) {
                setError(errorMessage(e));
            }
        },
        [sandbox.id],
    );

    useEffect(() => void load(path), [load, path]);

    const join = (name: string) => (path === '/' ? `/${name}` : `${path}/${name}`);
    const parent = path === '/' ? null : path.replace(/\/[^/]+$/, '') || '/';

    return (
        <div className="grid gap-3">
            <div className="flex items-center gap-2">
                <Button size="sm" variant="ghost" icon={<ChevronUp />} disabled={parent === null} onClick={() => parent && setPath(parent)}>
                    Up
                </Button>
                <span className="font-mono text-sm break-all">{path}</span>
            </div>
            {error && <p className="text-danger text-sm">{error}</p>}
            {!entries && !error && <SkeletonRows rows={4} />}
            {entries && (
                <DataTable
                    label="Files"
                    rows={entries}
                    rowKey={(entry) => entry.name}
                    onRowClick={(entry) => entry.type === 'dir' && setPath(join(entry.name))}
                    empty={{ icon: <Folder />, title: 'Empty directory', size: 'sm' }}
                    columns={[
                        {
                            id: 'name',
                            header: 'Name',
                            cell: (entry) =>
                                entry.type === 'file' ? (
                                    <a
                                        href={`/sandboxes/${sandbox.id}/files?path=${encodeURIComponent(join(entry.name))}`}
                                        className="flex items-center gap-2 font-mono text-xs hover:underline"
                                        onClick={(event) => event.stopPropagation()}
                                    >
                                        <File className="text-fg-faint size-3.5" aria-hidden /> {entry.name}
                                    </a>
                                ) : (
                                    <span className="flex items-center gap-2 font-mono text-xs">
                                        <Folder className="text-fg-faint size-3.5" aria-hidden /> {entry.name}
                                        {entry.type === 'link' && <span className="text-fg-faint">→</span>}
                                    </span>
                                ),
                        },
                        {
                            id: 'size',
                            header: 'Size',
                            align: 'right',
                            cell: (entry) => (
                                <span className="text-fg-muted tabular-nums">{entry.type === 'file' ? formatBytes(entry.size ?? 0) : '—'}</span>
                            ),
                        },
                        {
                            id: 'mode',
                            header: 'Mode',
                            hideOnMobile: true,
                            cell: (entry) => <span className="text-fg-muted font-mono text-xs">{entry.mode ?? ''}</span>,
                        },
                    ]}
                />
            )}
            {truncated && <p className="text-fg-muted text-xs">Only the first 1000 entries are shown.</p>}
        </div>
    );
}

/** Take a snapshot (sandbox.snapshot): name it to use it as a template; stop removes the sandbox afterwards. */
function SnapshotDialog({ sandbox, onOpenChange }: { sandbox: Sandbox; onOpenChange: (open: boolean) => void }) {
    const [name, setName] = useState('');
    const [stop, setStop] = useState<'keep' | 'stop'>('keep');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);

    const submit: FormEventHandler = async (event) => {
        event.preventDefault();
        setBusy(true);
        setErrors({});
        try {
            await requestJson(`/sandboxes/${sandbox.id}/snapshots`, 'POST', { name: name.trim() || null, stop: stop === 'stop' });
            toast.success('Taking the snapshot');
            onOpenChange(false);
            router.reload();
        } catch (error) {
            if (error instanceof HttpError && Object.keys(error.errors).length > 0) {
                setErrors(error.errors);
            } else {
                toast.error('Could not take the snapshot', errorMessage(error));
            }
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog
            open
            onOpenChange={onOpenChange}
            title={`Snapshot ${sandbox.name}`}
            description="Its memory, running processes and files, frozen on its server. Snapshots never expire; delete them when done."
            footer={
                <>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" form="take-snapshot" loading={busy}>
                        Take snapshot
                    </Button>
                </>
            }
        >
            <form id="take-snapshot" onSubmit={submit} className="grid gap-4">
                <Field label="Name" error={errors.name} hint="Optional; a named snapshot is a template (falak sandbox create --from NAME).">
                    <Input mono value={name} placeholder="node-ready" onChange={(event) => setName(event.target.value)} />
                </Field>
                <Field label="Afterwards">
                    <Segmented
                        label="Afterwards"
                        size="md"
                        value={stop}
                        onValueChange={setStop}
                        options={[
                            { value: 'keep', label: 'Keep running' },
                            { value: 'stop', label: 'Stop the sandbox' },
                        ]}
                    />
                </Field>
                {(errors.snapshot ?? errors.server) && <p className="text-danger text-sm">{errors.snapshot ?? errors.server}</p>}
            </form>
        </Dialog>
    );
}

/** Copy a database of an instance on the sandbox's server into /workspace/db (sandbox.db.import). */
function DatabaseCopyDialog({
    sandbox,
    databases,
    onOpenChange,
}: {
    sandbox: Sandbox;
    databases: DatabaseChoice[];
    onOpenChange: (open: boolean) => void;
}) {
    const choices = databases.flatMap((instance) =>
        instance.databases.map((database) => ({
            value: `${instance.instance}/${database}`,
            label: `${instance.name} / ${database}`,
            description: instance.engine,
        })),
    );
    const [choice, setChoice] = useState(choices[0]?.value ?? '');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);

    const submit: FormEventHandler = async (event) => {
        event.preventDefault();
        const [instance, database] = choice.split('/');
        setBusy(true);
        setErrors({});
        try {
            const { data } = await requestJson<{ data: { path: string; size_bytes: number } }>(`/sandboxes/${sandbox.id}/db`, 'POST', {
                instance,
                database,
            });
            toast.success(`Copied to ${data.path}`, formatBytes(data.size_bytes));
            onOpenChange(false);
            router.reload({ only: ['sandbox', 'execs'] });
        } catch (error) {
            if (error instanceof HttpError && Object.keys(error.errors).length > 0) {
                setErrors(error.errors);
            } else {
                toast.error('Could not copy the database', errorMessage(error));
            }
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog
            open
            onOpenChange={onOpenChange}
            title="Copy a database"
            description="A dump of the database lands in /workspace/db (PostgreSQL: .dump for pg_restore; MySQL/MariaDB: .sql). The sandbox gets a copy of the data, never a connection."
            footer={
                <>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" form="copy-database" loading={busy} disabled={choices.length === 0}>
                        Copy
                    </Button>
                </>
            }
        >
            <form id="copy-database" onSubmit={submit} className="grid gap-4">
                {choices.length === 0 ? (
                    <EmptyState icon={<Database />} title="No databases on this server" size="sm" />
                ) : (
                    <Field label="Database" error={errors.instance ?? errors.database ?? errors.server}>
                        <Select value={choice} onValueChange={setChoice} options={choices} />
                    </Field>
                )}
            </form>
        </Dialog>
    );
}
