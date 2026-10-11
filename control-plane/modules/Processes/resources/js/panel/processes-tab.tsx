import {
    Button,
    Callout,
    EmptyState,
    ManagedBadge,
    Menu,
    MenuActions,
    MenuContent,
    MenuRoot,
    MenuTrigger,
    SkeletonRows,
    StatusBadge,
    StatusDot,
    Tag,
    Tooltip,
    toast,
} from '@/components/falak';
import { useJson } from '@/hooks/use-json';
import { errorMessage, requestJson } from '@/lib/http';
import { type ServiceTabProps } from '@/lib/registry';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { Clock, Cog, Cpu, HeartPulse, Layers, Plus, RefreshCw, RotateCw, ScrollText, Server, Timer, Workflow, Zap } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { type ProcessInstance } from '../types';
import { endpoints, heartbeatState, processesUrl, programState, type Heartbeat, type ItemKind, type ProcessItem, type ProcessesData } from './api';
import { ProcessForm } from './process-form';

const KIND: Record<ItemKind, { label: string; icon: ReactNode }> = {
    web: { label: 'Web', icon: <Zap /> },
    horizon: { label: 'Horizon', icon: <Layers /> },
    octane: { label: 'Octane', icon: <Cpu /> },
    worker: { label: 'Worker', icon: <Workflow /> },
    daemon: { label: 'Daemon', icon: <Cog /> },
    scheduler: { label: 'Scheduler', icon: <Clock /> },
    cron: { label: 'Cron', icon: <Timer /> },
};

interface LiveResult {
    id: string;
    server_id: string;
    terminal: boolean;
    error: string | null;
    processes: (ProcessInstance & { name: string })[];
}

/** §5.1 Processes — web process, Horizon, Octane, queue workers, daemons, scheduler and cron jobs in one list. */
export function ProcessesTab({ ctx }: ServiceTabProps) {
    const siteId = ctx.service.ref_id;
    const { data, error, reload } = useJson<ProcessesData>(processesUrl(siteId), { interval: 15000 });
    const insights = useJson<{ heartbeats: Heartbeat[] }>(ctx.can('insights.view') ? `/insights/sites/${siteId}/summary?range=24h` : null, {
        interval: 60000,
        unwrap: false,
    });
    const [form, setForm] = useState<{ kind: 'worker' | 'daemon' | 'cron'; item: ProcessItem | null } | null>(null);
    const [live, setLive] = useState<Record<string, LiveResult>>({});
    const [refreshing, setRefreshing] = useState(false);
    const [restarting, setRestarting] = useState<string | null>(null);
    const timer = useRef<number | null>(null);

    useEffect(() => () => void (timer.current && window.clearTimeout(timer.current)), []);

    if (!data) return error ? <Callout tone="danger">{error}</Callout> : <SkeletonRows rows={6} />;

    const heartbeats = insights.data?.heartbeats ?? [];
    const manage = data.can.manage;

    /** Programs of an item, with live proc.status results layered over the stored snapshot. */
    const programsOf = (item: ProcessItem) =>
        data.programs
            .filter((program) => program.name === item.program)
            .map((program) => {
                const result = live[program.server_id];
                if (!result) return program;
                const instances = result.processes.filter((process) => process.name === program.name);

                return { ...program, instances };
            });

    const poll = (ids: string[], attempt: number) => {
        void requestJson<{ results: LiveResult[] }>(`/sites/${siteId}/processes/status?commands=${ids.join(',')}`)
            .then((body) => {
                const settled = body.results.filter((result) => result.terminal);
                setLive((current) => ({ ...current, ...Object.fromEntries(settled.map((result) => [result.server_id, result])) }));
                if (body.results.length === ids.length && body.results.every((result) => result.terminal)) {
                    setRefreshing(false);
                    void reload();
                } else if (attempt >= 20) {
                    setRefreshing(false);
                    toast.info('Some servers did not answer', 'Showing the last known status.');
                } else {
                    timer.current = window.setTimeout(() => poll(ids, attempt + 1), 1500);
                }
            })
            .catch(() => setRefreshing(false));
    };

    const refresh = async () => {
        setRefreshing(true);
        try {
            const body = await requestJson<{ commands: { id: string }[] }>(`/sites/${siteId}/processes/status`, 'POST', {});
            if (body.commands.length === 0) {
                setRefreshing(false);
                toast.info('No server to ask', 'The site has no ready server with processes yet.');

                return;
            }
            timer.current = window.setTimeout(
                () =>
                    poll(
                        body.commands.map((command) => command.id),
                        0,
                    ),
                1000,
            );
        } catch (e) {
            setRefreshing(false);
            toast.error('Could not refresh', errorMessage(e));
        }
    };

    const restart = async (serverId: string | null) => {
        setRestarting(serverId ?? 'all');
        try {
            const body = await requestJson<{ data: { commands: number } } | null>(
                `/sites/${siteId}/processes/restart`,
                'POST',
                serverId ? { server_id: serverId } : {},
            );
            if (body && body.data.commands === 0) toast.info('Nothing to restart', 'Nothing runs for this site yet.');
            else
                toast.success(
                    'Restarting processes',
                    serverId ? data.servers.find((server) => server.id === serverId)?.name : 'On every server of the site',
                );
        } catch (e) {
            toast.error('Could not restart', errorMessage(e));
        } finally {
            setRestarting(null);
        }
    };

    const remove = async (item: ProcessItem) => {
        const base = endpoints[item.kind]?.(siteId);
        if (!base || !item.id) return;
        if (!window.confirm(`Remove ${item.label}? It stops on every server.`)) return;
        try {
            await requestJson(`${base}/${item.id}`, 'DELETE');
            toast.success(`${item.label} removed`);
            await reload();
        } catch (e) {
            toast.error('Could not remove', errorMessage(e));
        }
    };

    const saved = async (message: string) => {
        setForm(null);
        toast.success(message);
        await reload();
    };

    const addMenu = [
        { label: 'Queue worker', icon: <Workflow />, onSelect: () => setForm({ kind: 'worker' as const, item: null }) },
        { label: 'Daemon', icon: <Cog />, onSelect: () => setForm({ kind: 'daemon' as const, item: null }) },
        { label: 'Cron job', icon: <Timer />, onSelect: () => setForm({ kind: 'cron' as const, item: null }) },
    ];

    return (
        <div className="grid gap-4" data-testid="processes-tab">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex flex-wrap items-center gap-1.5">
                    {data.servers.map((server) => (
                        <Tooltip
                            key={server.id}
                            content={
                                <span className="grid gap-0.5">
                                    <span>Processes: {server.proc.status ?? 'not applied'}</span>
                                    <span>Cron: {server.cron.status ?? 'not applied'}</span>
                                    {(server.proc.error || server.cron.error) && (
                                        <span className="text-danger">{server.proc.error ?? server.cron.error}</span>
                                    )}
                                </span>
                            }
                        >
                            <span className="border-border bg-surface-2 text-fg-muted inline-flex h-6 items-center gap-1.5 rounded-md border px-2 font-mono text-[11px]">
                                <StatusDot
                                    status={
                                        server.proc.status === 'failed' || server.proc.status === 'error'
                                            ? 'failed'
                                            : server.proc.status === 'pending'
                                              ? 'provisioning'
                                              : server.proc.status === 'applied'
                                                ? 'active'
                                                : 'inactive'
                                    }
                                />
                                {server.name}
                                {server.role === 'leader' && <span className="text-fg-faint">★</span>}
                            </span>
                        </Tooltip>
                    ))}
                </div>
                <div className="flex items-center gap-1.5">
                    {data.logs_url && (
                        <Button size="sm" variant="ghost" icon={<ScrollText />} onClick={() => ctx.open('logs')}>
                            Logs
                        </Button>
                    )}
                    <Button
                        size="sm"
                        variant="ghost"
                        icon={<RefreshCw className={cn(refreshing && 'animate-spin')} />}
                        disabled={refreshing}
                        onClick={() => void refresh()}
                    >
                        {refreshing ? 'Asking servers…' : 'Refresh status'}
                    </Button>
                    {manage && (
                        <>
                            <Button size="sm" icon={<RotateCw />} loading={restarting === 'all'} onClick={() => void restart(null)}>
                                Restart all
                            </Button>
                            {!data.container_runtime && (
                                <MenuRoot>
                                    <MenuTrigger asChild>
                                        <Button size="sm" variant="primary" icon={<Plus />}>
                                            Add
                                        </Button>
                                    </MenuTrigger>
                                    <MenuContent align="end">
                                        <MenuActions actions={addMenu} />
                                    </MenuContent>
                                </MenuRoot>
                            )}
                        </>
                    )}
                </div>
            </div>

            {data.container_runtime && (
                <Callout tone="info">Container sites run their processes in the container; workers, daemons and cron jobs live in the image.</Callout>
            )}

            {form && !form.item && (
                <div className="border-border overflow-hidden rounded-lg border">
                    <p className="border-border text-fg border-b px-4 py-2.5 text-sm font-medium">New {KIND[form.kind].label.toLowerCase()}</p>
                    <ProcessForm kind={form.kind} id={null} data={data} onDone={(message) => void saved(message)} onCancel={() => setForm(null)} />
                </div>
            )}

            {data.items.length === 0 && !form ? (
                <EmptyState
                    size="sm"
                    icon={<Workflow />}
                    title="Nothing runs next to the web server yet"
                    description="Queue workers, long-running daemons and cron jobs of this site are supervised on its servers and restarted on deploy."
                    action={
                        manage &&
                        !data.container_runtime && (
                            <Button
                                size="sm"
                                variant="primary"
                                icon={<Plus />}
                                onClick={() => setForm({ kind: data.laravel.available ? 'worker' : 'daemon', item: null })}
                            >
                                {data.laravel.available ? 'Add a queue worker' : 'Add a daemon'}
                            </Button>
                        )
                    }
                />
            ) : (
                <ul className="border-border bg-surface-1 divide-border divide-y overflow-hidden rounded-lg border" aria-label="Processes">
                    {data.items.map((item) => {
                        const isCron = item.kind === 'cron' || item.kind === 'scheduler';
                        const programs = isCron ? [] : programsOf(item);
                        const state = programState(programs);
                        const beat = isCron ? heartbeats.find((monitor) => monitor.job === item.program) : undefined;
                        const beatState = beat ? heartbeatState(beat) : null;
                        const editing = form?.item?.program === item.program;
                        const configurable = item.kind === 'worker' || item.kind === 'daemon' || item.kind === 'cron';
                        // Managed by the project's falak.yaml: read-only here.
                        const locked = Boolean(item.managed);
                        const editable = configurable && !locked;
                        const disabled = item.kind === 'cron' && item.config && 'enabled' in item.config && !item.config.enabled;
                        const serverNames = [
                            ...new Set(
                                programs.map((program) => data.servers.find((server) => server.id === program.server_id)?.name).filter(Boolean),
                            ),
                        ];

                        return (
                            <li key={item.program} data-program={item.program}>
                                <div className="flex flex-wrap items-center gap-3 px-3 py-2.5">
                                    <span className="border-border bg-surface-2 text-fg-muted flex size-8 shrink-0 items-center justify-center rounded-md border [&_svg]:size-4">
                                        {KIND[item.kind].icon}
                                    </span>
                                    <div className="grid min-w-0 flex-1 gap-0.5">
                                        <div className="flex min-w-0 flex-wrap items-center gap-1.5">
                                            <span className="text-fg truncate text-sm font-medium">{item.label}</span>
                                            {item.label !== KIND[item.kind].label && <Tag>{KIND[item.kind].label}</Tag>}
                                            {item.instances > 1 && <Tag mono>×{item.instances}</Tag>}
                                            <ManagedBadge config={item.managed} />
                                        </div>
                                        <p className="text-fg-muted truncate font-mono text-[11px]" title={item.command ?? undefined}>
                                            {item.detail && <span className="text-fg-faint">{item.detail} · </span>}
                                            {item.command ?? (item.kind === 'worker' ? 'artisan queue:work' : '')}
                                        </p>
                                    </div>
                                    <div className="flex shrink-0 items-center gap-2">
                                        {isCron ? (
                                            <>
                                                {disabled ? (
                                                    <StatusBadge status="inactive" label="Disabled" />
                                                ) : (
                                                    <StatusBadge
                                                        status={item.deployed ? 'active' : 'queued'}
                                                        label={item.deployed ? 'Scheduled' : 'Not applied yet'}
                                                    />
                                                )}
                                                {beatState && (
                                                    <Tooltip
                                                        content={
                                                            beat?.last_run_at
                                                                ? `Last run ${new Date(beat.last_run_at).toLocaleString()}`
                                                                : 'No run reported yet'
                                                        }
                                                    >
                                                        <Link
                                                            href={`${data.heartbeats_url}?job=${encodeURIComponent(item.program)}`}
                                                            className="text-fg-muted hover:text-fg inline-flex items-center gap-1 text-xs"
                                                        >
                                                            <HeartPulse className="size-3.5" aria-hidden />
                                                            <StatusDot status={beatState.status} />
                                                            {beatState.label}
                                                        </Link>
                                                    </Tooltip>
                                                )}
                                            </>
                                        ) : (
                                            <Tooltip
                                                content={
                                                    programs.length
                                                        ? `${state.running}/${state.total} running${serverNames.length ? ` on ${serverNames.join(', ')}` : ''}`
                                                        : 'Not reported by any server yet'
                                                }
                                            >
                                                <span>
                                                    <StatusBadge
                                                        status={state.status}
                                                        label={state.total > 0 ? `${state.label} ${state.running}/${state.total}` : state.label}
                                                    />
                                                </span>
                                            </Tooltip>
                                        )}
                                        {manage && (
                                            <Menu
                                                label={`${item.label} actions`}
                                                actions={[
                                                    ...(configurable
                                                        ? [
                                                              {
                                                                  label: locked ? 'Edit (managed by the config file)' : 'Edit',
                                                                  icon: <Cog />,
                                                                  disabled: locked,
                                                                  onSelect: () => setForm({ kind: item.kind as 'worker' | 'daemon' | 'cron', item }),
                                                              },
                                                          ]
                                                        : [
                                                              {
                                                                  label: item.kind === 'web' ? 'Runtime settings' : 'Laravel settings',
                                                                  icon: <Cog />,
                                                                  onSelect: () => ctx.open('settings', item.kind === 'web' ? 'build' : 'laravel'),
                                                              },
                                                          ]),
                                                    ...(!isCron
                                                        ? data.servers.map((server) => ({
                                                              label: `Restart on ${server.name}`,
                                                              icon: <Server />,
                                                              disabled: restarting !== null,
                                                              onSelect: () => void restart(server.id),
                                                          }))
                                                        : []),
                                                    ...(editable
                                                        ? [
                                                              { type: 'separator' as const },
                                                              { label: 'Remove', danger: true, onSelect: () => void remove(item) },
                                                          ]
                                                        : []),
                                                ]}
                                            />
                                        )}
                                    </div>
                                </div>
                                {editing && form && (
                                    <div className="border-border border-t">
                                        <ProcessForm
                                            kind={form.kind}
                                            id={item.id}
                                            initial={item.config}
                                            data={data}
                                            onDone={(message) => void saved(message)}
                                            onCancel={() => setForm(null)}
                                        />
                                    </div>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}

            {data.items.some((item) => item.kind === 'cron' || item.kind === 'scheduler') && (
                <p className="text-fg-faint text-xs">
                    Cron jobs report heartbeats; missed and failed runs open issues in{' '}
                    <Link href={data.heartbeats_url} className="text-primary hover:underline">
                        Observability → Heartbeats
                    </Link>
                    .
                </p>
            )}
        </div>
    );
}
