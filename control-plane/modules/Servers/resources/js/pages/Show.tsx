import { CommandLog, TERMINAL_COMMAND_STATUSES, type CommandStatus } from '@/components/command-log';
import { Button } from '@/components/falak/button';
import { Callout } from '@/components/falak/callout';
import { CodeBlock } from '@/components/falak/code-block';
import { copyText } from '@/components/falak/copy-button';
import { EmptyState } from '@/components/falak/empty-state';
import { KeyValue } from '@/components/falak/key-value';
import { Menu, type MenuAction } from '@/components/falak/menu';
import { Stepper, type Step } from '@/components/falak/phase-timeline';
import { RelativeTime } from '@/components/falak/relative-time';
import { Section } from '@/components/falak/section';
import { ServiceIcon } from '@/components/falak/service-icon';
import { StatusBadge } from '@/components/falak/status';
import { Tag } from '@/components/falak/tag';
import { toast } from '@/components/falak/toast';
import ServerLayout from '@/layouts/server-layout';
import { serverSectionsFor, shellContext } from '@/lib/registry';
import { type SharedData } from '@/types';
import { Link, router, usePage, usePoll } from '@inertiajs/react';
import { ArrowUpCircle, ChevronRight, Copy, Globe, RefreshCw, RotateCw, Settings, SquareTerminal, Trash2 } from 'lucide-react';
import { Suspense, useMemo, useRef, useState, type ReactNode } from 'react';
import { MachineCheckPanel } from '../components/machine-check';
import { AgentVersion, formatBytes, formatUptime, Sparkline } from '../components/server-ui';
import { type AgentDetails, type MachineCheck, type MetricSample, type ServerDetails, type ServerService } from '../types';

interface Props {
    server: ServerDetails;
    agent: AgentDetails | null;
    metrics: MetricSample[];
    services: ServerService[];
    machineCheck: MachineCheck;
    can: { update: boolean; delete: boolean; upgrade_agent: boolean };
}

const RELOAD = ['server', 'agent', 'metrics', 'services', 'machineCheck'];

function StatTile({ label, value, detail, spark }: { label: string; value: ReactNode; detail?: ReactNode; spark?: (number | null)[] }) {
    return (
        <div className="border-border bg-surface-1 grid gap-1 rounded-lg border p-3">
            <span className="text-fg-faint text-xs">{label}</span>
            <div className="flex items-end justify-between gap-2">
                <span className="text-fg tabular text-base font-semibold">{value}</span>
                {spark && spark.length > 1 && <Sparkline values={spark} label={`${label}, last hour`} width={64} height={18} />}
            </div>
            {detail && <span className="text-fg-muted tabular truncate text-xs">{detail}</span>}
        </div>
    );
}

function percent(used: number | undefined, total: number | null): number | null {
    return used !== undefined && total ? (used / total) * 100 : null;
}

/** Create → Install agent → (Check the machine) → Provision → Ready, derived from the lifecycle status. */
function lifecycleSteps(server: ServerDetails, agent: AgentDetails | null, check: MachineCheck): Step[] {
    const custom = server.provider === 'custom';
    const enrolled = agent !== null || ['provisioning', 'needs_attention', 'active'].includes(server.status);
    const failed = server.status === 'error';
    const checking = check.status === 'running' && check.purpose === 'provision';
    const checkStep: Step[] =
        check.supported || check.status !== null
            ? [
                  {
                      id: 'check',
                      label: 'Check the machine',
                      status: checking
                          ? 'running'
                          : server.status === 'needs_attention' || check.status === 'failed'
                            ? 'failed'
                            : check.status === 'finished'
                              ? 'succeeded'
                              : enrolled
                                ? 'skipped'
                                : 'waiting',
                      detail: checking
                          ? 'Looking at the software already on the machine…'
                          : server.status === 'needs_attention'
                            ? 'Something on the machine blocks provisioning'
                            : undefined,
                  },
              ]
            : [];

    return [
        {
            id: 'create',
            label: custom ? 'Server registered' : `Machine created at ${server.provider_label}`,
            status: custom || server.ipv4 || enrolled ? 'succeeded' : failed ? 'failed' : 'running',
        },
        {
            id: 'agent',
            label: custom ? 'Install the agent' : 'Agent enrolls',
            status: enrolled ? 'succeeded' : failed ? 'failed' : 'waiting',
            detail: enrolled || failed ? undefined : custom ? 'Waiting for the agent to connect…' : 'Waiting for cloud-init to start the agent…',
        },
        ...checkStep,
        {
            id: 'provision',
            label: 'Provision the stack',
            status:
                server.status === 'active'
                    ? 'succeeded'
                    : server.status === 'provisioning' && !checking
                      ? 'running'
                      : failed && enrolled && check.status !== 'failed'
                        ? 'failed'
                        : 'skipped',
            detail: server.status === 'provisioning' && !checking ? 'Installing packages, runtimes and the firewall…' : undefined,
        },
        { id: 'ready', label: 'Ready for services', status: server.status === 'active' ? 'succeeded' : 'skipped' },
    ];
}

function serviceStatus(service: ServerService): string {
    return service.status === 'ready' ? 'active' : service.status === 'pending' ? 'queued' : service.status;
}

export default function Show({ server, agent, metrics, services, machineCheck, can }: Props) {
    const awaitingAgent = server.install_command !== null && ['creating', 'error'].includes(server.status) && !agent;
    const upgrading = ['queued', 'running'].includes(server.agent?.upgrade?.status ?? '');
    // Offered wherever "update available" shows: the header, a banner and the Agent section.
    const canUpdateAgent = can.upgrade_agent && !!agent && !!server.agent?.update_available && !upgrading;
    const [updating, setUpdating] = useState(false);
    const updateAgent = () =>
        router.post(
            `/servers/${server.id}/agent/upgrade`,
            {},
            { preserveScroll: true, only: [...RELOAD, 'flash'], onStart: () => setUpdating(true), onFinish: () => setUpdating(false) },
        );
    const settling = ['creating', 'provisioning', 'deleting'].includes(server.status);
    const checking = machineCheck.status === 'running';
    // Needs attention, a blocked re-provision or failed provisioning: the machine check is the first thing to look at.
    const checkProminent =
        server.status === 'needs_attention' || machineCheck.blocking || (server.status === 'error' && machineCheck.status !== null);
    const showCheck = machineCheck.status !== null || (machineCheck.supported && server.status !== 'creating');
    const [showLog, setShowLog] = useState(server.status !== 'active');
    const [regenerating, setRegenerating] = useState(false);
    const [reprovisioning, setReprovisioning] = useState(false);

    // Live: the layout listens for `server.updated`; poll while the server is settling in case Reverb is down.
    usePoll(settling || upgrading || checking ? 4_000 : 60_000, { only: RELOAD });

    const previousLogStatus = useRef<CommandStatus | null>(null);
    const onProvisionStatus = (status: CommandStatus) => {
        const previous = previousLogStatus.current;
        previousLogStatus.current = status;

        if (previous !== null && !TERMINAL_COMMAND_STATUSES.includes(previous) && TERMINAL_COMMAND_STATUSES.includes(status)) {
            router.reload({ only: RELOAD });
        }
    };

    const reprovision = () =>
        router.post(
            route('servers.reprovision', server.id),
            {},
            {
                preserveScroll: true,
                onStart: () => setReprovisioning(true),
                onFinish: () => setReprovisioning(false),
                onSuccess: () => {
                    setShowLog(true);
                    toast.success('Provisioning started', 'Follow the log below.');
                },
                onError: (errors) => toast.error('Could not re-provision', Object.values(errors)[0]),
            },
        );

    const regenerate = () =>
        router.post(
            route('servers.install-command', server.id),
            {},
            {
                preserveScroll: true,
                onStart: () => setRegenerating(true),
                onFinish: () => setRegenerating(false),
                onSuccess: () => toast.success('New install command created', 'The previous command no longer works.'),
            },
        );

    const { props: shared } = usePage<SharedData>();
    // Blocks other modules add below the services (Limits: capacity).
    const sections = useMemo(() => serverSectionsFor(shellContext(shared)), [shared]);
    const cpu = useMemo(() => metrics.map((sample) => sample.cpu_percent), [metrics]);
    const memory = useMemo(() => metrics.map((sample) => percent(sample.memory_used_bytes, server.memory_bytes)), [metrics, server.memory_bytes]);
    const latest = agent?.metrics ?? {};
    const memoryPercent = percent(latest.memory_used_bytes, server.memory_bytes);
    const diskPercent = percent(latest.disk_used_bytes, server.disk_bytes);

    const canReprovision = can.update && ['error', 'active', 'needs_attention'].includes(server.status);
    const menu: MenuAction[] = [
        { label: 'Refresh', icon: <RefreshCw />, onSelect: () => router.reload({ only: RELOAD }) },
        ...(canReprovision ? [{ label: 'Re-provision', icon: <RotateCw />, onSelect: reprovision }] : []),
        {
            label: 'Copy server ID',
            icon: <Copy />,
            onSelect: () => void copyText(server.id).then((ok) => (ok ? toast.success('Server ID copied') : toast.error('Could not copy'))),
        },
        { type: 'separator' },
        { label: 'Settings', icon: <Settings />, href: `/servers/${server.id}/settings` },
        ...(can.delete ? [{ label: 'Delete server…', icon: <Trash2 />, href: `/servers/${server.id}/settings#danger` }] : []),
    ];

    const stack =
        [
            server.stack.php && `${server.stack.php.runtime === 'fpm' ? 'PHP-FPM' : 'FrankenPHP'}${server.php ? ` ${server.php}` : ''}`,
            server.stack.node && `Node ${server.stack.node}`,
        ]
            .filter(Boolean)
            .join(' · ') || null;

    return (
        <ServerLayout
            server={server}
            tab="overview"
            reloadOnly={RELOAD}
            actions={
                <>
                    {canUpdateAgent && (
                        <Button
                            variant="primary"
                            icon={<ArrowUpCircle />}
                            disabled={agent?.status !== 'online'}
                            loading={updating}
                            onClick={updateAgent}
                        >
                            Update agent
                        </Button>
                    )}
                    {server.status === 'active' && (
                        <Button variant="secondary" asChild>
                            <Link href={`/servers/${server.id}/terminal`}>
                                <SquareTerminal aria-hidden /> Terminal
                            </Link>
                        </Button>
                    )}
                    {canReprovision && server.status === 'error' && (
                        <Button variant="primary" icon={<RotateCw />} loading={reprovisioning} onClick={reprovision}>
                            Retry provisioning
                        </Button>
                    )}
                    <Menu actions={menu} label="Server actions" />
                </>
            }
        >
            {canUpdateAgent && (
                <Callout
                    tone="warning"
                    title={`Agent ${server.agent?.available_version} is available`}
                    action={
                        <Button
                            variant="secondary"
                            size="sm"
                            icon={<ArrowUpCircle />}
                            disabled={agent?.status !== 'online'}
                            loading={updating}
                            onClick={updateAgent}
                        >
                            Update now
                        </Button>
                    }
                >
                    {agent?.status === 'online'
                        ? `This server runs ${server.agent?.version ?? 'an older build'}. The agent downloads the new build, verifies its checksum and restarts in a few seconds. Containers and the edge keep serving; Node processes and workers restart with it.`
                        : 'The agent is offline; update it once it reconnects.'}
                </Callout>
            )}
            {server.control_plane_host && (
                <Callout tone="info" title="Control-plane machine">
                    Falak's own control plane runs here and keeps {Math.round((server.reserved_memory_mb / 1024) * 10) / 10} GB of memory and a CPU
                    share for itself; sites get the rest. Its edge owns ports 80 and 443 and forwards your sites to this server's edge (no HTTP/3).{' '}
                    {agent &&
                        (agent.local_edge ? (
                            'Local edge: on.'
                        ) : (
                            <>
                                Local edge: not set up yet; run <code>sudo falak-ctl local-server enable</code> on the host.
                            </>
                        ))}
                </Callout>
            )}
            {server.status_message && !['active', 'error', 'needs_attention'].includes(server.status) && (
                <div
                    role={server.status === 'error' ? 'alert' : 'status'}
                    className={
                        server.status === 'error'
                            ? 'border-danger/40 bg-danger-soft text-danger rounded-lg border px-4 py-3 text-sm'
                            : 'border-border bg-surface-1 text-fg-muted rounded-lg border px-4 py-3 text-sm'
                    }
                >
                    {server.status_message}
                </div>
            )}

            {(awaitingAgent || settling || server.status === 'error') && server.status !== 'deleting' && (
                <Section
                    title={awaitingAgent ? 'Connect your server' : server.status === 'error' ? 'Provisioning failed' : 'Setting up'}
                    description={
                        awaitingAgent
                            ? 'Run this once as root on the machine (Ubuntu 22.04 / 24.04 LTS). The link is single-use; provisioning starts when the agent enrolls.'
                            : server.status === 'error'
                              ? 'Fix the cause below, then retry — provisioning is idempotent.'
                              : 'This page updates live as the agent reports progress.'
                    }
                >
                    <div className="grid items-start gap-5 md:grid-cols-[220px_minmax(0,1fr)]">
                        <Stepper steps={lifecycleSteps(server, agent, machineCheck)} />
                        <div className="grid min-w-0 content-start gap-3">
                            {awaitingAgent && server.install_command && (
                                <>
                                    <div data-testid="install-command">
                                        <CodeBlock code={server.install_command} title="Install command · run as root" wrap />
                                    </div>
                                    <div className="text-fg-muted flex flex-wrap items-center gap-3 text-xs">
                                        <span className="flex items-center gap-2" aria-live="polite">
                                            <span className="animate-pulse-dot bg-info text-info size-1.5 rounded-full" aria-hidden />
                                            Waiting for the agent to connect…
                                        </span>
                                        {server.can_regenerate_install_command && (
                                            <Button variant="ghost" size="sm" icon={<RefreshCw />} loading={regenerating} onClick={regenerate}>
                                                Regenerate
                                            </Button>
                                        )}
                                    </div>
                                </>
                            )}
                            {server.status === 'error' && server.status_message && (
                                <p role="alert" className="border-danger/40 bg-danger-soft text-danger rounded-lg border px-3 py-2 text-sm">
                                    {server.status_message}
                                </p>
                            )}
                            {!awaitingAgent && server.provision_command_id && (
                                <CommandLog commandId={server.provision_command_id} onStatusChange={onProvisionStatus} />
                            )}
                            {!awaitingAgent && !server.provision_command_id && server.status !== 'error' && (
                                <p className="text-fg-muted text-sm">
                                    {server.provider === 'custom'
                                        ? 'The agent has not connected yet.'
                                        : `Waiting for ${server.provider_label} to finish creating the machine…`}
                                </p>
                            )}
                        </div>
                    </div>
                </Section>
            )}

            {showCheck && checkProminent && (
                <MachineCheckPanel server={server} check={machineCheck} canUpdate={can.update} prominent reloadOnly={RELOAD} />
            )}

            {agent && (
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
                    <StatTile
                        label="CPU"
                        value={latest.cpu_percent !== undefined && latest.cpu_percent !== null ? `${latest.cpu_percent.toFixed(0)}%` : '—'}
                        detail={server.cpus ? `${server.cpus} vCPU` : undefined}
                        spark={cpu}
                    />
                    <StatTile
                        label="Memory"
                        value={memoryPercent !== null ? `${memoryPercent.toFixed(0)}%` : '—'}
                        detail={`${formatBytes(latest.memory_used_bytes)} of ${formatBytes(server.memory_bytes)}`}
                        spark={memory}
                    />
                    <StatTile
                        label="Disk"
                        value={diskPercent !== null ? `${diskPercent.toFixed(0)}%` : '—'}
                        detail={`${formatBytes(latest.disk_used_bytes)} of ${formatBytes(server.disk_bytes)}`}
                    />
                    <StatTile
                        label="Load"
                        value={latest.load?.[0]?.toFixed(2) ?? '—'}
                        detail={latest.load ? latest.load.map((v) => v.toFixed(2)).join(' · ') : undefined}
                    />
                    <StatTile
                        label="Uptime"
                        value={formatUptime(latest.uptime_s)}
                        detail={<RelativeTime value={agent.last_heartbeat_at} fallback="no heartbeat" />}
                    />
                </div>
            )}

            <Section
                title="Services"
                description="Sites and databases running on this server."
                bare
                aside={
                    services.length > 0 && (
                        <span className="text-fg-faint tabular text-xs">
                            {services.length} service{services.length === 1 ? '' : 's'}
                        </span>
                    )
                }
            >
                {services.length === 0 ? (
                    <EmptyState
                        size="sm"
                        icon={<Globe />}
                        title="Nothing runs here yet"
                        description={
                            server.status === 'active'
                                ? 'Deploy a site or create a database and pick this server as its target.'
                                : 'Once the server is ready you can deploy sites and databases to it.'
                        }
                        action={
                            server.status === 'active' && (
                                <Button variant="secondary" size="sm" asChild>
                                    <Link href="/sites/create">Deploy a site</Link>
                                </Button>
                            )
                        }
                    />
                ) : (
                    <ul className="border-border bg-surface-1 divide-border divide-y overflow-hidden rounded-lg border">
                        {services.map((service) => (
                            <li key={`${service.kind}-${service.id}`}>
                                <Link
                                    href={service.url}
                                    className="hover:bg-surface-2 group flex items-center gap-3 px-3 py-2.5 transition-colors duration-150"
                                >
                                    <span className="border-border bg-surface-2 flex size-8 shrink-0 items-center justify-center rounded-md border">
                                        <ServiceIcon name={service.icon} size={16} />
                                    </span>
                                    <span className="grid min-w-0 flex-1">
                                        <span className="text-fg flex items-center gap-2 truncate text-sm font-medium">
                                            {service.name}
                                            {service.role === 'leader' && <Tag>leader</Tag>}
                                        </span>
                                        {service.subtitle && <span className="text-fg-faint truncate font-mono text-xs">{service.subtitle}</span>}
                                    </span>
                                    <StatusBadge status={serviceStatus(service)} />
                                    <ChevronRight className="text-fg-faint group-hover:text-fg size-4 shrink-0" aria-hidden />
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </Section>

            {services.length > 0 &&
                sections.map((section) => {
                    const Component = section.component;

                    return (
                        <Suspense key={section.id} fallback={null}>
                            <Component serverId={server.id} />
                        </Suspense>
                    );
                })}

            {!awaitingAgent && (
                <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                    <Section title="Details">
                        <KeyValue
                            columns={3}
                            items={[
                                { label: 'Public IPv4', value: server.ipv4, copy: server.ipv4 ?? undefined, mono: true },
                                { label: 'Public IPv6', value: server.ipv6, copy: server.ipv6 ?? undefined, mono: true },
                                { label: 'Private IPv4', value: server.private_ipv4, copy: server.private_ipv4 ?? undefined, mono: true },
                                { label: 'SSH port', value: server.ssh_port },
                                { label: 'OS', value: server.os },
                                { label: 'Architecture', value: server.arch },
                                { label: 'CPUs', value: server.cpus },
                                { label: 'Memory', value: formatBytes(server.memory_bytes) },
                                { label: 'Disk', value: formatBytes(server.disk_bytes) },
                                { label: 'Size', value: server.size },
                                { label: 'Image', value: server.image },
                                { label: 'Timezone', value: server.timezone },
                                { label: 'Stack', value: stack },
                                { label: 'Provisioned', value: <RelativeTime value={server.provisioned_at} /> },
                                { label: 'Created', value: <RelativeTime value={server.created_at} /> },
                                ...(server.provider_server_id
                                    ? [{ label: 'Provider ID', value: server.provider_server_id, mono: true, copy: server.provider_server_id }]
                                    : []),
                            ]}
                        />
                    </Section>
                    <Section
                        title="Agent"
                        aside={
                            can.upgrade_agent &&
                            agent &&
                            server.agent?.update_available && (
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    icon={<ArrowUpCircle />}
                                    disabled={upgrading || agent.status !== 'online'}
                                    loading={updating}
                                    onClick={updateAgent}
                                >
                                    Update agent
                                </Button>
                            )
                        }
                    >
                        {agent ? (
                            <KeyValue
                                columns={2}
                                items={[
                                    { label: 'Status', value: <StatusBadge status={agent.status === 'online' ? 'online' : 'offline'} /> },
                                    { label: 'Version', value: <AgentVersion agent={server.agent} />, mono: true },
                                    { label: 'Last heartbeat', value: <RelativeTime value={agent.last_heartbeat_at} /> },
                                    { label: 'Enrolled', value: <RelativeTime value={agent.enrolled_at} /> },
                                    { label: 'Certificate expires', value: <RelativeTime value={agent.certificate_expires_at} /> },
                                    { label: 'Kernel', value: agent.kernel, mono: true },
                                    { label: 'Docker', value: agent.docker ?? 'not installed' },
                                    { label: 'Hostname', value: agent.hostname, mono: true },
                                ]}
                            />
                        ) : (
                            <p className="text-fg-muted text-sm">No agent enrolled yet.</p>
                        )}
                    </Section>
                </div>
            )}

            {showCheck && !checkProminent && (
                <MachineCheckPanel server={server} check={machineCheck} canUpdate={can.update} prominent={false} reloadOnly={RELOAD} />
            )}

            {server.provision_command_id && !settling && server.status !== 'error' && (
                <Section
                    title="Provisioning log"
                    description="Output of the latest provisioning run."
                    bare
                    aside={
                        <Button variant="ghost" size="sm" onClick={() => setShowLog((open) => !open)} aria-expanded={showLog}>
                            {showLog ? 'Hide' : 'Show'}
                        </Button>
                    }
                >
                    {showLog && <CommandLog commandId={server.provision_command_id} onStatusChange={onProvisionStatus} />}
                </Section>
            )}
        </ServerLayout>
    );
}
