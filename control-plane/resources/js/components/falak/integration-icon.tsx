import { brandFillProps } from '@/lib/brand-color';
import { cn } from '@/lib/utils';
import { Cloud, Code2, HardDrive, Hash, KeyRound, Mail, Plug, Server, Webhook, type LucideIcon } from 'lucide-react';
import {
    siAkamai,
    siBackblaze,
    siBitbucket,
    siCloudflare,
    siContabo,
    siDigitalocean,
    siDiscord,
    siGit,
    siGithub,
    siGitlab,
    siGrafana,
    siHetzner,
    siMinio,
    siOpentelemetry,
    siPrometheus,
    siTelegram,
    siVictoriametrics,
    siVultr,
    type SimpleIcon,
} from 'simple-icons';

/**
 * Logos of the external systems an organization connects in Settings → Integrations (git providers, clouds,
 * object storage, alert targets, observability backends). Keys match the backend enum values.
 */
const BRANDS: Record<string, SimpleIcon> = {
    github: siGithub,
    gitlab: siGitlab,
    bitbucket: siBitbucket,
    git: siGit,
    hetzner: siHetzner,
    digitalocean: siDigitalocean,
    spaces: siDigitalocean,
    vultr: siVultr,
    linode: siAkamai,
    contabo: siContabo,
    akamai: siAkamai,
    cloudflare: siCloudflare,
    r2: siCloudflare,
    backblaze: siBackblaze,
    b2: siBackblaze,
    minio: siMinio,
    discord: siDiscord,
    telegram: siTelegram,
    grafana: siGrafana,
    prometheus: siPrometheus,
    victoriametrics: siVictoriametrics,
    opentelemetry: siOpentelemetry,
    otlp: siOpentelemetry,
};

/** Integrations without a usable brand mark. */
const GENERIC: Record<string, LucideIcon> = {
    aws: Cloud,
    lightsail: Cloud,
    s3: HardDrive,
    slack: Hash,
    email: Mail,
    webhook: Webhook,
    custom: Server,
    token: KeyRound,
    script: Code2,
};

// Near-black marks disappear on the dark theme: draw them in the text color.
const MONOCHROME = new Set(['github', 'minio', 'vultr', 'opentelemetry']);

export interface IntegrationIconProps {
    /** 'github' | 'hetzner' | 'r2' | 'slack' | 'grafana' | … */
    name: string | null | undefined;
    size?: number;
    /** Render in currentColor instead of the brand color. */
    mono?: boolean;
    title?: string;
    className?: string;
}

export function IntegrationIcon({ name, size = 16, mono = false, title, className }: IntegrationIconProps) {
    const key = (name ?? '').toLowerCase();
    const brand = BRANDS[key];
    const a11y = title ? { role: 'img' as const, 'aria-label': title } : { 'aria-hidden': true as const };

    if (brand) {
        const monochrome = mono || MONOCHROME.has(key);
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

    const Generic = GENERIC[key] ?? Plug;

    return <Generic width={size} height={size} className={cn('text-fg-muted shrink-0', className)} {...a11y} />;
}

/** The logo in a small bordered tile (lists, pickers). */
export function IntegrationTile({ name, size = 'md', className }: { name: string | null | undefined; size?: 'sm' | 'md'; className?: string }) {
    return (
        <span
            className={cn(
                'border-border bg-surface-2 flex shrink-0 items-center justify-center rounded-md border',
                size === 'sm' ? 'size-6' : 'size-8',
                className,
            )}
        >
            <IntegrationIcon name={name} size={size === 'sm' ? 14 : 16} />
        </span>
    );
}
