import { Tag } from '@/components/falak/tag';

export interface TailscaleNode {
    status: 'installing' | 'active' | 'error';
    hostname: string;
    ipv4: string | null;
    ipv6: string | null;
    routes_approved: boolean;
    version: string | null;
    error: string | null;
    updated_at: string | null;
}

const TONE = { installing: 'info', active: 'success', error: 'danger' } as const;

/** Node status, plus whether the tailnet approved its routes (without that, devices cannot reach it). */
export function TailscaleStatus({ node }: { node: TailscaleNode }) {
    return (
        <span className="flex flex-wrap items-center gap-1.5">
            <Tag tone={TONE[node.status]}>{node.status}</Tag>
            {node.status === 'active' &&
                (node.routes_approved ? <Tag tone="success">routes approved</Tag> : <Tag tone="warning">routes not approved</Tag>)}
        </span>
    );
}
