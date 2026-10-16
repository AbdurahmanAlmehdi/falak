import {
    AppShell,
    Button,
    Callout,
    ConfirmDestructive,
    DataTable,
    EmptyState,
    KeyValue,
    PageHeader,
    RelativeTime,
    Section,
    Select,
    SkeletonRows,
    StatusBadge,
    Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
    toast,
} from '@/components/falak';
import { errorMessage, requestJson } from '@/lib/http';
import { formatBytes } from '@/lib/utils';
import { Head, router, usePoll } from '@inertiajs/react';
import { Box, ChevronUp, Clock, File, Folder, Skull, SquareTerminal } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { formatMemory, formatTimeLeft, isLive, sandboxBadge, secondsLeft, useNow, type FileEntry, type Sandbox, type SandboxExec } from '../types';

interface Props {
    sandbox: Sandbox;
    execs: SandboxExec[];
    can: { update: boolean; operate: boolean; kill: boolean };
}

const EXTENSIONS = [
    { value: '1800', label: '+30 minutes' },
    { value: '3600', label: '+1 hour' },
    { value: '7200', label: '+2 hours' },
];

/** /sandboxes/{id}: one sandbox — time left, console, the exec log and a read-only file browser. */
export default function Show({ sandbox, execs, can }: Props) {
    const now = useNow();
    const [killing, setKilling] = useState(false);
    const [extendBy, setExtendBy] = useState('1800');
    const [busy, setBusy] = useState(false);
    const badge = sandboxBadge(sandbox);
    const running = sandbox.status === 'running';

    usePoll(sandbox.status === 'creating' ? 3_000 : 15_000, { only: ['sandbox', 'execs'] });

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
                            ...(sandbox.env_keys.length > 0 ? [{ label: 'Environment', value: sandbox.env_keys.join(', '), mono: true }] : []),
                            ...(sandbox.repo
                                ? [{ label: 'Repository', value: sandbox.repo.url + (sandbox.repo.ref ? `@${sandbox.repo.ref}` : ''), mono: true }]
                                : []),
                        ]}
                    />
                </Section>

                <Tabs defaultValue={running && can.operate ? 'console' : 'activity'}>
                    <TabsList>
                        <TabsTrigger value="console">Console</TabsTrigger>
                        <TabsTrigger value="activity" badge={execs.length > 0 ? execs.length : undefined}>
                            Activity
                        </TabsTrigger>
                        <TabsTrigger value="files">Files</TabsTrigger>
                    </TabsList>

                    <TabsContent value="console" className="pt-4">
                        <EmptyState
                            icon={<SquareTerminal />}
                            title={running ? 'A root shell inside the sandbox' : 'The console opens once the sandbox runs'}
                            description="Everything you type stays inside the sandbox. From a terminal: falak sandbox ssh."
                            action={
                                can.operate && running ? (
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

                    <TabsContent value="files" className="pt-4">
                        {running && can.operate ? (
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
