import { brandFillProps } from '@/lib/brand-color';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { Box, Code2, Database, FileCode2, Globe, HardDrive, Server, SquareFunction, type LucideIcon } from 'lucide-react';
import { useState, type ReactNode } from 'react';
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

/** A Cool Icons key as the picker stores it: a slug, optionally with a variant ("docker", "docker/svgl-dark"). */
const COOL_ICON = /^[a-z0-9][a-z0-9-]{0,63}(\/[a-z0-9][a-z0-9-]{0,47})?$/;

/** URL of a Cool Icons key's SVG, or null when the library is off (FALAK_ICONS_URL empty) or the key is not one. */
export function coolIconUrl(base: string | null | undefined, key: string): string | null {
    return base && COOL_ICON.test(key) ? `${base}/i/${key}.svg` : null;
}

/** The Cool Icons base URL the panel shares (null: picker off). */
export function useIconsUrl(): string | null {
    return usePage<SharedData>().props.icons_url ?? null;
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
    /** Drawn for an unknown key or an icon that fails to load (default: a box; null: nothing). */
    fallback?: ReactNode;
}

export function ServiceIcon({ name, size = 16, mono = false, title, className, fallback }: ServiceIconProps) {
    const key = (name ?? '').toLowerCase();
    const brand = BRANDS[key];
    const a11y = title ? { role: 'img' as const, 'aria-label': title } : { 'aria-hidden': true as const };
    const iconsUrl = useIconsUrl();
    const remote = brand || GENERIC[key] ? null : coolIconUrl(iconsUrl, key);
    const [failed, setFailed] = useState<string | null>(null);

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

    // Anything else is a Cool Icons pick. Its default variant is drawn for light backgrounds: on the dark theme it
    // sits on a light tile, unless the user picked a variant made for dark ones.
    if (remote && failed !== remote) {
        return (
            <img
                src={remote}
                width={size}
                height={size}
                alt={title ?? ''}
                loading="lazy"
                draggable={false}
                onError={() => setFailed(remote)}
                className={cn('shrink-0 object-contain', !key.includes('dark') && 'dark:rounded-[3px] dark:bg-white dark:p-px', className)}
            />
        );
    }

    if (!GENERIC[key] && fallback !== undefined) return <>{fallback}</>;

    const Generic = GENERIC[key] ?? Box;

    return <Generic width={size} height={size} className={cn('text-fg-muted shrink-0', className)} {...a11y} />;
}

/**
 * Icon key of a canvas service: the icon picked for it, else a compose site made from a template shows the template's
 * logo when there is one.
 */
export function serviceIconKey(service: {
    icon: string;
    kind: string;
    custom_icon?: string | null;
    compose?: { template: string | null } | null;
}): string {
    const template = service.compose?.template;

    return service.custom_icon || (template && hasServiceIcon(template) ? template : service.icon || service.kind);
}
