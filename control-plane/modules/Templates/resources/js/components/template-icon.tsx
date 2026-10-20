import { ServiceIcon } from '@/components/falak/service-icon';
import { brandFillProps } from '@/lib/brand-color';
import { cn } from '@/lib/utils';
import { LayoutTemplate } from 'lucide-react';
import {
    siAdminer,
    siAppsmith,
    siBookstack,
    siChangedetection,
    siClickhouse,
    siCoder,
    siDify,
    siDirectus,
    siDocker,
    siErpnext,
    siExcalidraw,
    siForgejo,
    siGhost,
    siGitea,
    siGrafana,
    siLangflow,
    siListmonk,
    siMariadb,
    siMatomo,
    siMeilisearch,
    siMetabase,
    siMinio,
    siMysql,
    siN8n,
    siNextcloud,
    siNtfy,
    siOdoo,
    siOllama,
    siPaperlessngx,
    siPlausibleanalytics,
    siPostgresql,
    siQdrant,
    siRedis,
    siSearxng,
    siUmami,
    siUptimekuma,
    siVaultwarden,
    siWikidotjs,
    siWordpress,
    type SimpleIcon,
} from 'simple-icons';
import { type TemplateIconRef } from '../types';

/**
 * Brand icons (simple-icons) bundled for the catalog;
 * other keys load from Cool Icons, or fall back to a generic icon.
 */
export const BRANDS: Record<string, SimpleIcon> = {
    adminer: siAdminer,
    appsmith: siAppsmith,
    bookstack: siBookstack,
    changedetection: siChangedetection,
    clickhouse: siClickhouse,
    coder: siCoder,
    dify: siDify,
    directus: siDirectus,
    docker: siDocker,
    erpnext: siErpnext,
    excalidraw: siExcalidraw,
    forgejo: siForgejo,
    ghost: siGhost,
    gitea: siGitea,
    grafana: siGrafana,
    langflow: siLangflow,
    listmonk: siListmonk,
    mariadb: siMariadb,
    matomo: siMatomo,
    meilisearch: siMeilisearch,
    metabase: siMetabase,
    minio: siMinio,
    mysql: siMysql,
    n8n: siN8n,
    nextcloud: siNextcloud,
    ntfy: siNtfy,
    odoo: siOdoo,
    ollama: siOllama,
    paperlessngx: siPaperlessngx,
    plausibleanalytics: siPlausibleanalytics,
    postgresql: siPostgresql,
    qdrant: siQdrant,
    redis: siRedis,
    searxng: siSearxng,
    umami: siUmami,
    uptimekuma: siUptimekuma,
    vaultwarden: siVaultwarden,
    wikidotjs: siWikidotjs,
    wordpress: siWordpress,
};

export function TemplateIcon({ icon, name, size = 20, className }: { icon: TemplateIconRef; name: string; size?: number; className?: string }) {
    if (icon?.type === 'url') {
        return <img src={icon.url} alt="" width={size} height={size} className={cn('shrink-0', className)} loading="lazy" />;
    }

    const brand = icon?.type === 'brand' ? BRANDS[icon.key] : undefined;

    if (!brand) {
        const generic = <LayoutTemplate width={size} height={size} className={cn('text-fg-muted shrink-0', className)} aria-hidden />;

        // Any other key is a Cool Icons key (coolicon.falak.sh), so a new catalog template needs no panel release for its logo.
        return icon?.type === 'brand' ? <ServiceIcon name={icon.key} size={size} className={className} fallback={generic} /> : generic;
    }

    // Brand colors are data (the logo's identity), adjusted per theme to stay visible (lib/brand-color).
    const fill = brandFillProps(brand.hex);

    return (
        <svg
            viewBox="0 0 24 24"
            width={size}
            height={size}
            className={cn('shrink-0', fill.className, className)}
            style={fill.style}
            role="img"
            aria-label={name}
        >
            <path d={brand.path} />
        </svg>
    );
}

/** Icon in a soft tile (cards, headers). */
export function TemplateIconTile({ icon, name, size = 'md' }: { icon: TemplateIconRef; name: string; size?: 'sm' | 'md' | 'lg' }) {
    const box = { sm: 'size-8 rounded-md', md: 'size-10 rounded-lg', lg: 'size-12 rounded-xl' }[size];
    const glyph = { sm: 16, md: 20, lg: 24 }[size];

    return (
        <span className={cn('border-border bg-surface-2 flex shrink-0 items-center justify-center border', box)}>
            <TemplateIcon icon={icon} name={name} size={glyph} />
        </span>
    );
}
