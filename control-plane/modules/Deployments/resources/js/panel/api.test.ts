import { describe, expect, it } from 'vitest';
import { type Deployment, type Release } from '../types';
import { liveDeploymentId, type DeploymentsOverview } from './api';

const deployment = (id: string, status: Deployment['status'], release_id: string | null) => ({ id, status, release_id }) as Deployment;
const overview = (current: Partial<Release> | null, history: Deployment[]) =>
    ({ current: current as Release | null, history: { data: history, links: { prev: null, next: null } } }) as DeploymentsOverview;

describe('liveDeploymentId', () => {
    it('is the newest succeeded deployment carrying the live release, not the one that first built it', () => {
        // #3 rolled back to #1's release: #3 is live, #1 (whose id the release still names) is history.
        const history = [deployment('d3', 'succeeded', 'r1'), deployment('d2', 'succeeded', 'r2'), deployment('d1', 'succeeded', 'r1')];

        expect(liveDeploymentId(overview({ id: 'r1', deployment_id: 'd1' }, history))).toBe('d3');
        expect(liveDeploymentId(overview({ id: 'r2', deployment_id: 'd2' }, history))).toBe('d2');
    });

    it("falls back to the release's deployment, and is nothing without a live release", () => {
        expect(liveDeploymentId(overview({ id: 'r9', deployment_id: 'd9' }, [deployment('d1', 'failed', 'r9')]))).toBe('d9');
        expect(liveDeploymentId(overview(null, [deployment('d1', 'succeeded', 'r1')]))).toBeUndefined();
    });
});
