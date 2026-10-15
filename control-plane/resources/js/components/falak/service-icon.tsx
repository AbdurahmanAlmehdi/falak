import { brandFillProps } from '@/lib/brand-color';
import { cn } from '@/lib/utils';
import { Box, Code2, Database, FileCode2, Globe, HardDrive, Server, SquareFunction, type LucideIcon } from 'lucide-react';
import {
    siAppsmith,
    siBun,
    siCaddy,
    siClickhouse,
    siDeno,
    siDirectus,
    siDocker,
    siElasticsearch,
    siGhost,
    siGitea,
    siGo,
    siGrafana,
    siLaravel,
    siListmonk,
    siMariadb,
    siMeilisearch,
    siMetabase,
    siMinio,
    siMongodb,
    siMysql,
    siN8n,
    siNextdotjs,
    siNginx,
    siNodedotjs,
    siNuxt,
    siPhp,
    siPlausibleanalytics,
    siPostgresql,
    siPython,
    siRabbitmq,
    siRedis,
    siStatamic,
    siSymfony,
    siTraefikproxy,
    siUmami,
    siUptimekuma,
    siVaultwarden,
    siWordpress,
    type SimpleIcon,
} from 'simple-icons';

/** Brand logos (simple-icons) keyed by the ServiceIcon keys the canvas read model uses (§9). */
const BRANDS: Record<string, SimpleIcon> = {
    laravel: siLaravel,
    symfony: siSymfony,
    statamic: siStatamic,
    wordpress: siWordpress,
    php: siPhp,
    frankenphp: siPhp,
    'php-fpm': siPhp,
    next: siNextdotjs,
    nextjs: siNextdotjs,
    nextdotjs: siNextdotjs,
    nuxt: siNuxt,
    node: siNodedotjs,
    nodejs: siNodedotjs,
    nodedotjs: siNodedotjs,
    bun: siBun,
    deno: siDeno,
    python: siPython,
    go: siGo,
    golang: siGo,
    docker: siDocker,
    compose: siDocker,
    postgresql: siPostgresql,
    postgres: siPostgresql,
    pgsql: siPostgresql,
    mysql: siMysql,
    mariadb: siMariadb,
    redis: siRedis,
    valkey: siRedis,
    caddy: siCaddy,
    // Compose services (icon keys from their image, see Projects ComposeGroup::icon) and template brands.
    n8n: siN8n,
    grafana: siGrafana,
    minio: siMinio,
    meilisearch: siMeilisearch,
    clickhouse: siClickhouse,
    elasticsearch: siElasticsearch,
    rabbitmq: siRabbitmq,
    mongodb: siMongodb,
    nginx: siNginx,
    traefik: siTraefikproxy,
    ghost: siGhost,
    gitea: siGitea,
    metabase: siMetabase,
    directus: siDirectus,
    umami: siUmami,
    plausibleanalytics: siPlausibleanalytics,
    uptimekuma: siUptimekuma,
    vaultwarden: siVaultwarden,
    listmonk: siListmonk,
    appsmith: siAppsmith,
};

/** Generic fallbacks for kinds without a brand logo. */
const GENERIC: Record<string, LucideIcon> = {
    site: Globe,
    static: FileCode2,
    database: Database,
    storage: HardDrive,
    server: Server,
    service: Box,
    custom: Code2,
    function: SquareFunction,
};

// Logos whose brand color is (near) black/white read poorly on one theme: draw them in the text color instead.
const MONOCHROME = new Set(['nextdotjs', 'bun', 'deno', 'symfony']);

export function hasServiceIcon(name: string | null | undefined): boolean {
    return Boolean(name && (BRANDS[name.toLowerCase()] || GENERIC[name.toLowerCase()]));
}

export interface ServiceIconProps {
    /** 'laravel' | 'next' | 'bun' | 'postgresql' | … or a generic kind ('site', 'database', 'server'). */
    name: string | null | undefined;
    size?: number;
    /** Render in currentColor instead of the brand color. */
    mono?: boolean;
    /** Accessible label; decorative when omitted. */
    title?: string;
    className?: string;
}

export function ServiceIcon({ name, size = 16, mono = false, title, className }: ServiceIconProps) {
    const key = (name ?? '').toLowerCase();
    const brand = BRANDS[key];
    const a11y = title ? { role: 'img' as const, 'aria-label': title } : { 'aria-hidden': true as const };

    if (brand) {
        const monochrome = mono || MONOCHROME.has(brand.slug);
        // Brand colors are data (the logo's identity), not UI tokens; adjusted per theme to stay visible (lib/brand-color).
        const fill = monochrome ? null : brandFillProps(brand.hex);

        return (
            <svg
                viewBox="0 0 24 24"
                width={size}
                height={size}
                className={cn('shrink-0', fill ? fill.className : 'text-fg', className)}
                style={fill?.style}
                fill={fill ? undefined : 'currentColor'}
                {...a11y}
            >
                {title && <title>{title}</title>}
                <path d={brand.path} />
            </svg>
        );
    }

    const Generic = GENERIC[key] ?? Box;

    return <Generic width={size} height={size} className={cn('text-fg-muted shrink-0', className)} {...a11y} />;
}

/** Icon key of a canvas service: a compose site made from a template shows the template's logo when there is one. */
export function serviceIconKey(service: { icon: string; kind: string; compose?: { template: string | null } | null }): string {
    const template = service.compose?.template;

    return template && hasServiceIcon(template) ? template : service.icon || service.kind;
}
