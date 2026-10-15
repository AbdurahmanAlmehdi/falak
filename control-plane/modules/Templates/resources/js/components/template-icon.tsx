import { cn } from '@/lib/utils';
import { LayoutTemplate } from 'lucide-react';
import {
    siAdminer,
    siAppsmith,
    siBookstack,
    siChangedetection,
    siClickhouse,
    siCoder,
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
    siUmami,
    siUptimekuma,
    siVaultwarden,
    siWikidotjs,
    siWordpress,
    type SimpleIcon,
} from 'simple-icons';
import { type TemplateIconRef } from '../types';

/**
 * Brand icons (simple-icons) the catalog uses. Keep in sync with CATALOG_BRAND_ICONS in TemplateCatalogTest.php;
 * unknown keys (custom templates) fall back to a generic icon.
 */
const BRANDS: Record<string, SimpleIcon> = {
    adminer: siAdminer,
    appsmith: siAppsmith,
    bookstack: siBookstack,
    changedetection: siChangedetection,
    clickhouse: siClickhouse,
    coder: siCoder,
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
    umami: siUmami,
    uptimekuma: siUptimekuma,
    vaultwarden: siVaultwarden,
    wikidotjs: siWikidotjs,
    wordpress: siWordpress,
};

// Near-black / near-white brand colors read poorly on one theme: draw them in the text color instead.
function monochrome(hex: string): boolean {
    const [r, g, b] = [0, 2, 4].map((i) => parseInt(hex.slice(i, i + 2), 16));
    const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;

    return luminance < 0.25 || luminance > 0.9;
}

export function TemplateIcon({ icon, name, size = 20, className }: { icon: TemplateIconRef; name: string; size?: number; className?: string }) {
    if (icon?.type === 'url') {
        return <img src={icon.url} alt="" width={size} height={size} className={cn('shrink-0', className)} loading="lazy" />;
    }

    const brand = icon?.type === 'brand' ? BRANDS[icon.key] : undefined;

    if (!brand) {
        return <LayoutTemplate width={size} height={size} className={cn('text-fg-muted shrink-0', className)} aria-hidden />;
    }

    const mono = monochrome(brand.hex);

    return (
        <svg
            viewBox="0 0 24 24"
            width={size}
            height={size}
            className={cn('shrink-0', mono && 'text-fg', className)}
            fill={mono ? 'currentColor' : `#${brand.hex}`}
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
