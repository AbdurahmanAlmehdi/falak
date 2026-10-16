import {
    Avatar,
    copyText,
    EmptyState,
    formatDuration,
    LogViewer,
    Menu,
    PanelHeader,
    PhaseTimeline,
    RelativeTime,
    ServiceIcon,
    serviceIconKey,
    SkeletonRows,
    StatusBadge,
    Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
    toast,
    type MenuAction,
} from '@/components/falak';
import { timeZoneLabel } from '@/components/falak/log-viewer';
import { useJson } from '@/hooks/use-json';
import { errorMessage, requestJson } from '@/lib/http';
import { type ServiceLayerProps } from '@/lib/registry';
import { AlertTriangle, Ban, Copy, Globe, Rocket, RotateCcw } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { type Deployment } from '../types';
import { deploy, deploymentsUrl, durationMs, firstLine, liveDeploymentId, rollback, type DeploymentsOverview } from './api';
import { CommitTag, triggerLabel } from './deployment-row';
import { NetworkLogs } from './network-logs';
import { PHASES, timelineRows, useDeployment } from './use-deployment';
import { WaitingNotice } from './waiting-notice';
import { WatchNotice } from './watch-notice';

export type DeploymentTab = 'details' | 'build' | 'deploy' | 'network';

export const DEPLOYMENT_TABS: { id: DeploymentTab; label: string }[] = [
    { id: 'details', label: 'Details' },
    { id: 'build', label: 'Build Logs' },
    { id: 'deploy', label: 'Deploy Logs' },
    { id: 'network', label: 'Network Logs' },
];

/** Short, stable handle of a deployment for headers ("4d2947b1"): the random tail of its ULID. */
export function shortId(id: string): string {
    return id.slice(-8).toLowerCase();
}

const pad = (value: number) => String(value).padStart(2, '0');

function stamp(value: string): string {
    const date = new Date(value);

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())} ${timeZoneLabel()}`;
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid min-w-0 gap-1">
            <dt className="text-fg-faint text-xs">{label}</dt>
            <dd className="text-fg min-w-0 truncate text-sm">{children}</dd>
        </div>
    );
}

/** Details tab: what was deployed, by whom, how long it took, errors and the per-server phase timeline. */
function Details({
    deployment,
    rows,
    usedPhases,
    onOpen,
}: {
    deployment: Deployment;
    rows: ReturnType<typeof timelineRows>;
    usedPhases: typeof PHASES;
    onOpen: (deploymentId: string) => void;
}) {
    const duration = durationMs(deployment.started_at, deployment.finished_at);

    return (
        <div className="grid gap-6 px-5 pb-8 sm:px-7">
            {deployment.status === 'waiting' && <WaitingNotice deployment={deployment} />}
            <WatchNotice deployment={deployment} onOpen={onOpen} />
            {deployment.auto_rollback_of && (
                <p className="text-fg-muted text-xs">
                    Started automatically: the release that went live before it tripped its watch.{' '}
                    <button type="button" className="text-fg font-medium underline" onClick={() => onOpen(deployment.auto_rollback_of!)}>
                        View that deployment
                    </button>
                </p>
            )}
            {deployment.error && (
                <div role="alert" className="border-danger/35 bg-danger-soft flex gap-2.5 rounded-lg border px-3.5 py-3 text-sm">
                    <AlertTriangle className="text-danger mt-0.5 size-4 shrink-0" aria-hidden />
                    <div className="grid gap-0.5">
                        <span className="text-fg font-medium">{deployment.status === 'cancelled' ? 'Cancelled' : 'Deployment failed'}</span>
                        <span className="text-fg-muted">
                            {deployment.error}
                            {deployment.rolled_back && ' Servers that had switched were rolled back to the previous release.'}
                        </span>
                    </div>
                </div>
            )}

            <section className="border-border grid gap-4 rounded-xl border p-4 sm:p-5" aria-label="Deployment summary">
                <div className="flex min-w-0 items-start gap-3">
                    <Avatar name={deployment.author ?? triggerLabel(deployment.trigger)} size="md" />
                    <div className="grid min-w-0 gap-0.5">
                        <p className="text-fg truncate text-sm font-medium">{firstLine(deployment.message) ?? triggerLabel(deployment.trigger)}</p>
                        <p className="text-fg-muted text-xs">
                            <RelativeTime value={deployment.started_at ?? deployment.created_at} /> via{' '}
                            {triggerLabel(deployment.trigger).toLowerCase()}
                            {deployment.author ? ` by ${deployment.author}` : ''}
                        </p>
                    </div>
                </div>
                <dl className="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-4">
                    <Fact label="Commit">
                        <CommitTag sha={deployment.commit} branch={deployment.branch} />
                    </Fact>
                    <Fact label="Strategy">{deployment.strategy ?? '—'}</Fact>
                    <Fact label="Duration">
                        <span className="tabular">{duration !== null ? formatDuration(duration) : '—'}</span>
                    </Fact>
                    <Fact label="Number">
                        <span className="tabular">#{deployment.number}</span>
                    </Fact>
                </dl>
            </section>

            <section className="grid gap-2" aria-label="Phases">
                <h3 className="text-fg-muted text-xs font-medium">Phases</h3>
                {rows.length > 0 ? (
                    <PhaseTimeline phases={usedPhases} rows={rows} />
                ) : (
                    <p className="text-fg-faint text-sm">
                        {deployment.status === 'queued'
                            ? 'Waiting for the deployment ahead of this one.'
                            : deployment.status === 'waiting'
                              ? 'Servers are added here once they finish preparing.'
                              : 'No servers in this deployment.'}
                    </p>
                )}
            </section>
        </div>
    );
}

/**
 * §5.5 stacked deployment panel ("Storefront / 4d2947b1 · Active"): Details · Build Logs · Deploy Logs · Network Logs,
 * streamed live. Opened from "View logs" on a deployment card; Esc / ✕ returns to the service panel underneath.
 */
export function DeploymentPanel({ ctx, record, tab, onTabChange, close }: ServiceLayerProps) {
    const siteId = ctx.service.ref_id;
    // Shared (cached) with the Deployments tab underneath: is this the deployment whose release is live? Fetched again
    // when the deployment finishes: the copy from while it ran still names the previous release as live.
    const { data: overview, reload: reloadOverview } = useJson<DeploymentsOverview>(deploymentsUrl(siteId));
    const { url, detail, error, terminal, refresh, buildLines, deployLines } = useDeployment(siteId, record, () => {
        ctx.refresh();
        void reloadOverview();
    });
    const [busy, setBusy] = useState<string | null>(null);
    const deployment = detail?.deployment;
    const live = overview ? liveDeploymentId(overview) === record : false;
    const [badgeStatus, badgeLabel] =
        deployment?.status === 'succeeded'
            ? !overview
                ? ['succeeded', undefined]
                : live
                  ? ['active', 'Active']
                  : deployment.rolled_back
                    ? ['degraded', 'Rolled back']
                    : ['removed', 'Removed']
            : [deployment?.status ?? 'queued', deployment?.status === 'waiting' ? 'Waiting' : undefined];

    // Default view: build output while building, else the deploy log (build output when a release has no deploy log).
    const fallbackTab: DeploymentTab =
        deployment && (['queued', 'waiting', 'building'].includes(deployment.status) || (deployLines.length === 0 && buildLines.length > 0))
            ? 'build'
            : 'deploy';
    const active = (DEPLOYMENT_TABS.find((item) => item.id === tab)?.id ?? fallbackTab) as DeploymentTab;

    const act = async (id: string, run: () => Promise<void>) => {
        setBusy(id);
        try {
            await run();
        } finally {
            setBusy(null);
        }
    };

    const menu: MenuAction[] = deployment
        ? [
              ...(detail?.can.redeploy
                  ? [
                        {
                            label: 'Redeploy',
                            icon: <Rocket />,
                            disabled: busy !== null,
                            onSelect: () => void act('redeploy', () => deploy(ctx, { commit: deployment.commit, branch: deployment.branch })),
                        },
                    ]
                  : []),
              ...(detail?.can.rollback && deployment.release_id
                  ? [
                        {
                            label: 'Rollback to this release',
                            icon: <RotateCcw />,
                            onSelect: () => void act('rollback', () => rollback(ctx, deployment.release_id!)),
                        },
                    ]
                  : []),
              ...(detail?.can.cancel && !terminal
                  ? [
                        {
                            label: 'Cancel deployment',
                            icon: <Ban />,
                            danger: true,
                            onSelect: () =>
                                void act('cancel', async () => {
                                    try {
                                        await requestJson(`${url}/cancel`, 'POST', {});
                                        toast.success(`Deployment #${deployment.number} cancelled`);
                                        await refresh();
                                        ctx.refresh();
                                    } catch (e) {
                                        toast.error('Could not cancel', errorMessage(e));
                                    }
                                }),
                        },
                    ]
                  : []),
              { type: 'separator' as const },
              {
                  label: 'Copy deployment id',
                  icon: <Copy />,
                  onSelect: () =>
                      void copyText(deployment.id).then((ok) => (ok ? toast.success('Deployment id copied') : toast.error('Could not copy'))),
              },
          ]
        : [];

    const rows = detail
        ? timelineRows(detail.targets, detail.steps, (target) => (
              <span className="flex min-w-0 items-center gap-1.5">
                  <span className="text-fg truncate font-mono">{target.server_name}</span>
                  {target.role === 'leader' && detail.targets.length > 1 && <span className="text-warning">★</span>}
              </span>
          ))
        : [];
    const usedPhases = PHASES.filter((phase) => phase.id !== 'rollback' || rows.some((row) => row.cells.rollback));
    const popOut = () => {
        const next = new URL(window.location.href);
        next.searchParams.set('focus', '1');
        window.open(next.toString(), '_blank', 'noopener');
    };

    return (
        <div className="flex min-h-0 flex-1 flex-col" data-testid="deployment-panel">
            <PanelHeader
                size="md"
                icon={<ServiceIcon name={serviceIconKey(ctx.service)} size={18} />}
                parent={ctx.service.name}
                title={<span className="font-mono text-[0.95em] font-medium">{shortId(record)}</span>}
                status={deployment && <StatusBadge status={badgeStatus} label={badgeLabel} />}
                actions={
                    <>
                        {menu.length > 0 && <Menu actions={menu} label="Deployment actions" />}
                        {deployment && (
                            <span className="text-fg-faint tabular hidden px-1 text-xs whitespace-nowrap md:inline">
                                {stamp(deployment.started_at ?? deployment.created_at)}
                            </span>
                        )}
                    </>
                }
                onClose={close}
            />
            <Tabs value={active} onValueChange={(value) => onTabChange(value)} className="flex min-h-0 flex-1 flex-col">
                <TabsList className="gap-4 px-5 sm:px-7" aria-label="Deployment views">
                    {DEPLOYMENT_TABS.map((item) => (
                        <TabsTrigger
                            key={item.id}
                            value={item.id}
                            className="px-0.5"
                            badge={
                                item.id === 'build'
                                    ? buildLines.length || undefined
                                    : item.id === 'deploy'
                                      ? deployLines.length || undefined
                                      : undefined
                            }
                        >
                            {item.label}
                        </TabsTrigger>
                    ))}
                </TabsList>
                {error ? (
                    <p className="text-danger px-7 py-5 text-sm">{error}</p>
                ) : !detail || !deployment ? (
                    <div className="px-7 py-5">
                        <SkeletonRows rows={8} />
                    </div>
                ) : (
                    <>
                        <TabsContent value="details" className="min-h-0 flex-1 overflow-y-auto pt-5">
                            <Details deployment={deployment} rows={rows} usedPhases={usedPhases} onOpen={(id) => ctx.openLayer('deployment', id)} />
                        </TabsContent>
                        <TabsContent value="build" className="flex min-h-0 flex-1 flex-col pt-4">
                            <LogViewer
                                variant="flush"
                                lines={buildLines}
                                label={`Build log of deployment #${deployment.number}`}
                                filename={`deployment-${deployment.number}-build.log`}
                                streaming={!terminal}
                                onPopOut={popOut}
                                emptyText={
                                    deployment.status === 'queued' || deployment.status === 'waiting'
                                        ? 'Waiting to start…'
                                        : 'No build output (the release reuses an existing build or builds on the servers).'
                                }
                            />
                        </TabsContent>
                        <TabsContent value="deploy" className="flex min-h-0 flex-1 flex-col pt-4">
                            <LogViewer
                                variant="flush"
                                lines={deployLines}
                                label={`Deploy log of deployment #${deployment.number}`}
                                filename={`deployment-${deployment.number}-deploy.log`}
                                streaming={!terminal}
                                onPopOut={popOut}
                                emptyText={terminal ? 'No deploy output.' : 'Waiting for the servers…'}
                            />
                        </TabsContent>
                        <TabsContent value="network" className="flex min-h-0 flex-1 flex-col pt-4">
                            {ctx.can('telemetry.view') ? (
                                <NetworkLogs ctx={ctx} deployment={deployment} live={live} />
                            ) : (
                                <div className="px-5 pt-1 pb-8 sm:px-7">
                                    <EmptyState
                                        icon={<Globe />}
                                        title="No access to logs"
                                        description="Network logs need the telemetry.view permission."
                                    />
                                </div>
                            )}
                        </TabsContent>
                    </>
                )}
            </Tabs>
        </div>
    );
}
