import { FileCode2 } from 'lucide-react';
import { Tag } from './tag';
import { Tooltip } from './tooltip';

/**
 * What a project's falak.yaml manages on a resource (Config's `ManagedFields::toArray()`): the dashboard shows those
 * fields read-only with a badge linking to the file at the commit that set them. Null when the file manages nothing.
 */
export interface ManagedConfig {
    badge: string;
    commit: string | null;
    short: string | null;
    file: string | null;
    url: string | null;
    /** JSON Pointers relative to the unit: /deploy/strategy, /env/APP_ENV, /workers/queue, /domains/shop.example.com */
    paths: string[];
}

function escapeToken(token: string): string {
    return token.replace(/~/g, '~0').replace(/\//g, '~1');
}

/** A JSON Pointer from tokens (`pointer('env', 'APP_KEY')` → `/env/APP_KEY`). */
export function pointer(...tokens: string[]): string {
    return tokens.map((t) => '/' + escapeToken(t)).join('');
}

/**
 * Whether the file manages a field: the path itself or an ancestor (a whole worker); with `orDescendant`, also any
 * field under it (a section the file sets part of).
 */
export function managedCovers(config: ManagedConfig | null | undefined, path: string, orDescendant = false): boolean {
    if (!config) return false;
    return config.paths.some((p) => p === path || path.startsWith(p + '/') || (orDescendant && p.startsWith(path + '/')));
}

/** "Managed by falak.yaml @ abc1234", linking to the file at that commit. */
export function ManagedBadge({ config, className }: { config: ManagedConfig | null | undefined; className?: string }) {
    if (!config) return null;

    const tag = (
        <Tag tone="info" icon={<FileCode2 aria-hidden />} className={className} data-testid="managed-badge">
            {config.badge}
        </Tag>
    );
    const hint = `Set by ${config.file ?? 'falak.yaml'}${config.short ? ` at ${config.short}` : ''}: edit the file to change it.`;

    return (
        <Tooltip content={hint}>
            {config.url ? (
                <a href={config.url} target="_blank" rel="noreferrer noopener" className="inline-flex">
                    {tag}
                </a>
            ) : (
                <span className="inline-flex">{tag}</span>
            )}
        </Tooltip>
    );
}
