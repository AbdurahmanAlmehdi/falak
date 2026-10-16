import { toast } from '@/components/falak';
import { errorMessage, requestJson } from '@/lib/http';
import { type ServicePanelContext } from '@/lib/registry';
import { type Deployment, type OutputLine, type Release, type Step, type Target } from '../types';

export type RunningDeployment = Deployment & { targets: Target[] };

/** GET /sites/{site}/deployments (JSON) — the panel's Deployments tab. */
export interface DeploymentsOverview {
    active: RunningDeployment | null;
    queued: Deployment[];
    history: { data: Deployment[]; links: { prev: string | null; next: string | null } };
    current: Release | null;
    strategy: string;
    defaultBranch: string | null;
    canDeploy: boolean;
    can: { create: boolean; cancel: boolean; rollback: boolean };
}

/** GET /sites/{site}/deployments/{deployment} (JSON) — the Deploy view. */
export interface DeploymentDetail {
    deployment: Deployment;
    targets: Target[];
    steps: Step[];
    lines: OutputLine[];
    can: { cancel: boolean; redeploy: boolean; rollback: boolean };
}

export const TERMINAL = ['succeeded', 'failed', 'cancelled'];

export const deploymentsUrl = (siteId: string) => `/sites/${siteId}/deployments`;

/**
 * The deployment whose release is live: the newest succeeded one carrying the current release (a rollback or a
 * redeploy of the same release makes it live again, while the release still names the deployment that built it), else
 * the release's own deployment. `deployments` is newest first.
 */
export function liveDeploymentId(overview: DeploymentsOverview, deployments: Deployment[] = overview.history.data): string | undefined {
    const current = overview.current;
    if (!current) return undefined;

    return deployments.find((item) => item.status === 'succeeded' && item.release_id === current.id)?.id ?? current.deployment_id ?? undefined;
}

/** Start a deployment (optionally of a given commit) and stack its deployment panel. */
export async function deploy(ctx: ServicePanelContext, ref: { commit?: string | null; branch?: string | null } = {}): Promise<void> {
    try {
        const body = await requestJson<{ data: Deployment }>(deploymentsUrl(ctx.service.ref_id), 'POST', {
            ...(ref.commit ? { commit: ref.commit } : {}),
            ...(ref.branch ? { branch: ref.branch } : {}),
        });
        toast.success(`Deployment #${body.data.number} started`);
        ctx.refresh();
        ctx.openLayer('deployment', body.data.id);
    } catch (error) {
        toast.error('Could not deploy', errorMessage(error));
    }
}

/** Cancel a queued or waiting deployment (or one still building). */
export async function cancelDeployment(ctx: ServicePanelContext, deployment: { id: string; number: number }): Promise<boolean> {
    try {
        await requestJson(`${deploymentsUrl(ctx.service.ref_id)}/${deployment.id}/cancel`, 'POST', {});
        toast.success(`Deployment #${deployment.number} cancelled`);
        ctx.refresh();

        return true;
    } catch (error) {
        toast.error('Could not cancel', errorMessage(error));

        return false;
    }
}

/** Roll back to a release (a deployment of its build) and stack the rollback's deployment panel. */
export async function rollback(ctx: ServicePanelContext, releaseId: string): Promise<void> {
    try {
        const body = await requestJson<{ data: { id: string; number: number } }>(
            `/sites/${ctx.service.ref_id}/releases/${releaseId}/rollback`,
            'POST',
            {},
        );
        toast.success(`Rollback #${body.data.number} started`);
        ctx.refresh();
        ctx.openLayer('deployment', body.data.id);
    } catch (error) {
        toast.error('Could not roll back', errorMessage(error));
    }
}

export function durationMs(from: string | null, to: string | null): number | null {
    if (!from) return null;

    return Math.max(0, (to ? new Date(to).getTime() : Date.now()) - new Date(from).getTime());
}

export function firstLine(message: string | null): string | null {
    return message ? (message.split('\n')[0] ?? null) : null;
}
