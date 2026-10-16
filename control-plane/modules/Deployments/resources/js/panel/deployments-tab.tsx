import { Button, EmptyState, SkeletonRows, type MenuAction } from '@/components/falak';
import { useEchoChannel } from '@/hooks/use-echo-channel';
import { useJson } from '@/hooks/use-json';
import { requestJson } from '@/lib/http';
import { type ServicePanelContext, type ServiceTabProps } from '@/lib/registry';
import { Ban, Globe, Layers, MapPin, Rocket, RotateCcw, ScrollText } from 'lucide-react';
import { useState } from 'react';
import { type Deployment } from '../types';
import { cancelDeployment, deploy, deploymentsUrl, liveDeploymentId, rollback, type DeploymentsOverview } from './api';
import { DeploymentCard, HistoryCard } from './deployment-card';

function rowActions(ctx: ServicePanelContext, overview: DeploymentsOverview, deployment: Deployment, reload?: () => void): MenuAction[] {
    const actions: MenuAction[] = [{ label: 'View logs', icon: <ScrollText />, onSelect: () => ctx.openLayer('deployment', deployment.id) }];

    if (overview.can.cancel && (deployment.status === 'waiting' || deployment.status === 'queued')) {
        actions.push({
            label: 'Cancel',
            icon: <Ban />,
            onSelect: () => void cancelDeployment(ctx, deployment).then((ok) => ok && reload?.()),
        });
    }

    if (overview.can.create && deployment.commit) {
        actions.push({
            label: 'Redeploy this commit',
            icon: <Rocket />,
            onSelect: () => void deploy(ctx, { commit: deployment.commit, branch: deployment.branch }),
        });
    }
    if (overview.can.rollback && deployment.release_id && deployment.status === 'succeeded' && deployment.release_id !== overview.current?.id) {
        actions.push({ label: 'Rollback to this release', icon: <RotateCcw />, onSelect: () => void rollback(ctx, deployment.release_id!) });
    }

    return actions;
}

/** Where the service answers and runs: public domain on the left, servers and replica count on the right. */
function MetaRow({ ctx }: { ctx: ServicePanelContext }) {
    const servers = ctx.service.servers;
    const host = ctx.service.url?.replace(/^https?:\/\//, '').replace(/\/$/, '');

    return (
        <div className="text-fg-muted flex min-w-0 flex-wrap items-center gap-x-5 gap-y-1.5 text-sm" data-testid="service-meta">
            {host ? (
                <a
                    href={ctx.service.url ?? '#'}
                    target="_blank"
                    rel="noreferrer"
                    className="hover:text-fg flex min-w-0 items-center gap-2 transition-colors"
                >
                    <Globe className="text-success size-4 shrink-0" aria-hidden />
                    <span className="text-fg truncate">{host}</span>
                </a>
            ) : (
                <span className="text-fg-faint flex items-center gap-2">
                    <Globe className="size-4" aria-hidden /> No public domain
                </span>
            )}
            {servers.length > 0 && (
                <span className="text-fg-faint ml-auto flex items-center gap-4 text-xs">
                    <span className="flex min-w-0 items-center gap-1.5" title={servers.map((server) => server.name).join(', ')}>
                        <MapPin className="size-3.5 shrink-0" aria-hidden />
                        <span className="max-w-48 truncate">{servers.map((server) => server.name).join(', ')}</span>
                    </span>
                    <span className="flex items-center gap-1.5">
                        <Layers className="size-3.5" aria-hidden />
                        {servers.length} {servers.length === 1 ? 'Replica' : 'Replicas'}
                    </span>
                </span>
            )}
        </div>
    );
}

function DeploymentList({ ctx }: { ctx: ServicePanelContext }) {
    const siteId = ctx.service.ref_id;
    const [interval, setIntervalMs] = useState(15000);
    const { data, error, reload } = useJson<DeploymentsOverview>(deploymentsUrl(siteId), { interval });
    const [more, setMore] = useState<{ items: Deployment[]; next: string | null } | null>(null);
    const [loadingMore, setLoadingMore] = useState(false);

    const live = useEchoChannel(`deployments.site.${siteId}`, ['deployment.updated'], () => {
        void reload();
        ctx.refresh();
    });

    const busy = Boolean(data?.active || (data?.queued.length ?? 0) > 0);
    const wanted = busy ? (live ? 10000 : 3000) : live ? 60000 : 15000;
    if (wanted !== interval) setIntervalMs(wanted);

    if (!data) {
        return error ? <p className="text-danger text-sm">{error}</p> : <SkeletonRows rows={6} />;
    }

    const history = [...data.history.data, ...(more?.items ?? [])];
    const next = more ? more.next : data.history.links.next;
    const liveId = liveDeploymentId(data, history);
    const liveDeployment = liveId ? history.find((item) => item.id === liveId) : undefined;
    // The newest finished deployment failed after the live one (or nothing is live): surface it next to the live card.
    const latestFailed = history.find((item) => item.status === 'failed' || item.status === 'cancelled');
    const failedOnTop =
        latestFailed && !data.active && (!liveDeployment || new Date(latestFailed.created_at) > new Date(liveDeployment.created_at))
            ? latestFailed
            : undefined;
    const rest = history.filter((item) => item.id !== liveDeployment?.id && item.id !== failedOnTop?.id);
    const openLayer = ctx.layer?.id === 'deployment' ? ctx.layer.record : null;

    if (!data.active && data.queued.length === 0 && history.length === 0) {
        return (
            <div className="grid gap-5">
                <MetaRow ctx={ctx} />
                <EmptyState
                    icon={<Rocket />}
                    title="No deployments yet"
                    description={
                        data.canDeploy
                            ? `Deploy ${data.defaultBranch ? `the ${data.defaultBranch} branch` : 'the service'} to its servers. Every deployment builds, releases and health-checks with zero downtime.`
                            : 'Connect a repository or a Docker image in Settings, then deploy it here.'
                    }
                    action={
                        data.can.create &&
                        data.canDeploy && (
                            <Button variant="primary" icon={<Rocket />} onClick={() => void deploy(ctx)}>
                                Deploy now
                            </Button>
                        )
                    }
                />
            </div>
        );
    }

    const loadMore = async () => {
        if (!next) return;
        setLoadingMore(true);
        try {
            const body = await requestJson<{ data: DeploymentsOverview }>(next);
            setMore({ items: [...(more?.items ?? []), ...body.data.history.data], next: body.data.history.links.next });
        } finally {
            setLoadingMore(false);
        }
    };

    return (
        <div className="grid gap-4">
            <MetaRow ctx={ctx} />

            {data.active && (
                <DeploymentCard
                    ctx={ctx}
                    deployment={data.active}
                    mode={data.active.status === 'waiting' ? 'waiting' : 'running'}
                    label={data.active.status === 'waiting' ? 'Waiting' : data.active.status === 'building' ? 'Building' : 'Deploying'}
                    actions={rowActions(ctx, data, data.active, () => void reload())}
                    selected={openLayer === data.active.id}
                />
            )}

            {failedOnTop && (
                <DeploymentCard
                    ctx={ctx}
                    deployment={failedOnTop}
                    mode="failed"
                    label={failedOnTop.status === 'cancelled' ? 'Cancelled' : 'Failed'}
                    actions={rowActions(ctx, data, failedOnTop)}
                    selected={openLayer === failedOnTop.id}
                />
            )}

            {liveDeployment && (
                <DeploymentCard
                    ctx={ctx}
                    deployment={liveDeployment}
                    mode="live"
                    label="Active"
                    actions={rowActions(ctx, data, liveDeployment)}
                    selected={openLayer === liveDeployment.id}
                />
            )}

            {data.queued.length > 0 && (
                <section aria-label="Queued deployments" className="grid gap-2">
                    <h3 className="text-fg-faint px-0.5 text-xs font-medium">
                        Queued · runs after the {data.active?.status === 'waiting' ? 'waiting' : 'current'} deployment
                    </h3>
                    {data.queued.map((deployment) => (
                        <HistoryCard
                            key={deployment.id}
                            ctx={ctx}
                            deployment={deployment}
                            actions={rowActions(ctx, data, deployment, () => void reload())}
                            selected={openLayer === deployment.id}
                        />
                    ))}
                </section>
            )}

            {rest.length > 0 && (
                <section aria-label="Deployment history" className="mt-2 grid gap-2">
                    <h3 className="text-fg-faint px-0.5 text-xs font-medium">History</h3>
                    {rest.map((deployment) => (
                        <HistoryCard
                            key={deployment.id}
                            ctx={ctx}
                            deployment={deployment}
                            actions={rowActions(ctx, data, deployment)}
                            selected={openLayer === deployment.id}
                        />
                    ))}
                    {next && (
                        <Button variant="ghost" size="sm" className="justify-self-center" loading={loadingMore} onClick={() => void loadMore()}>
                            Load older deployments
                        </Button>
                    )}
                </section>
            )}
        </div>
    );
}

/** §5.1 Deployments tab: meta row, featured deployment cards, queued and history; "View logs" stacks the §5.5 deployment panel. */
export function DeploymentsTab({ ctx }: ServiceTabProps) {
    return <DeploymentList ctx={ctx} />;
}
