import { cn, formatBytes } from '@/lib/utils';
import { type CanvasService, type CanvasVolume } from '@/types';
import { HardDrive, Star } from 'lucide-react';
import { type HTMLAttributes } from 'react';
import { ServiceIcon } from './service-icon';
import { StatusDot, statusSpec } from './status';

/** Fixed geometry so groups can be sized before cards are measured (canvas §4). */
export const SERVICE_CARD = { width: 240, height: 112, strip: 30 } as const;

export interface ServiceCardProps extends HTMLAttributes<HTMLDivElement> {
    service: Pick<CanvasService, 'kind' | 'name' | 'icon' | 'status' | 'status_label' | 'url' | 'subtitle' | 'servers' | 'badges'> & {
        volumes?: CanvasVolume[];
    };
    /** The service whose panel is open. */
    selected?: boolean;
    /** Part of a multi-selection on the canvas (e.g. to group). */
    marked?: boolean;
    /** A plain click on a volume chip (the canvas opens it in the panel); ⌘/Ctrl/⇧-click still follows its page link. */
    onOpenVolume?: (volumeId: string) => void;
}

/** Host of a URL without the scheme ("https://shop.acme.com" → "shop.acme.com"). */
function host(url: string): string {
    return url.replace(/^https?:\/\//, '').replace(/\/$/, '');
}

const LABEL_TONE: Record<string, string> = {
    success: 'text-success',
    danger: 'text-danger',
    warning: 'text-warning',
    info: 'text-info',
    faint: 'text-fg-muted',
};

/**
 * The canvas card of a service (§4): icon + name (+ runtime badges), the public domain (sites) or engine/server
 * (databases) underneath, and the live status at the bottom ("● Online", "● Deploying 64%") with server chips. A
 * service with persistent storage gets a strip of disks docked under the card: its volumes with used / limit, each
 * linking to its volume page (an engine's data dir, or a compose volume before the first deploy, has no page); on the
 * canvas a click opens it in the side panel instead.
 * Selected (panel open) = accent border; hover lifts the card (canvas CSS).
 */
export function ServiceCard({ service, selected = false, marked = false, onOpenVolume, className, ...props }: ServiceCardProps) {
    const detail = service.kind === 'site' ? (service.url ? host(service.url) : service.subtitle) : service.subtitle;
    const servers = service.kind === 'site' ? service.servers : [];
    const volumes = service.volumes ?? [];
    const tone = statusSpec(service.status).tone;

    return (
        <div className={cn('group/card relative w-60', className)} data-selected={selected || undefined} {...props}>
            <div
                className={cn(
                    'falak-card bg-surface-1 relative z-10 flex h-28 flex-col rounded-lg border px-4 pt-3.5 pb-3 text-left',
                    selected
                        ? 'border-primary shadow-[0_0_0_1px_var(--accent),0_8px_28px_-8px_var(--accent-soft)]'
                        : 'border-border group-hover/card:border-border-strong shadow-[0_1px_2px_rgba(0,0,0,0.06)]',
                    marked && !selected && 'outline-primary/70 outline-2 outline-offset-2 outline-dashed',
                )}
            >
                <div className="flex min-w-0 items-center gap-2.5">
                    <ServiceIcon name={service.icon || service.kind} size={18} />
                    <span className="text-fg min-w-0 truncate text-sm font-semibold">{service.name}</span>
                    {service.badges?.map((badge) => (
                        <span
                            key={badge}
                            className="border-border bg-surface-2 text-fg-muted text-2xs inline-flex h-4 shrink-0 items-center rounded-sm border px-1 font-medium"
                        >
                            {badge}
                        </span>
                    ))}
                </div>
                {detail && <span className="text-fg-muted mt-0.5 min-w-0 truncate pl-[1.75rem] text-xs">{detail}</span>}
                <div className="mt-auto flex min-w-0 items-center gap-2">
                    <span className={cn('flex min-w-0 flex-1 items-center gap-2 text-xs font-medium', LABEL_TONE[tone])}>
                        <StatusDot status={service.status} className="ring-2 ring-current/15" />
                        <span className="truncate">{service.status_label}</span>
                    </span>
                    {servers.length > 0 && (
                        <span className="flex shrink-0 items-center gap-1">
                            {servers.slice(0, 1).map((server) => (
                                <span
                                    key={server.id}
                                    title={`${server.name}${server.leader ? ' (leader)' : ''}${server.online ? '' : ' · offline'}`}
                                    className={cn(
                                        'border-border bg-surface-2 text-2xs inline-flex h-4.5 items-center gap-0.5 rounded-sm border px-1 font-mono',
                                        server.online ? 'text-fg-muted' : 'text-fg-faint',
                                    )}
                                >
                                    {server.leader && servers.length > 1 && (
                                        <Star className="fill-warning text-warning size-2.5" aria-label="leader" />
                                    )}
                                    {server.name}
                                </span>
                            ))}
                            {servers.length > 1 && <span className="text-fg-faint text-2xs">+{servers.length - 1}</span>}
                        </span>
                    )}
                </div>
            </div>
            {volumes.length > 0 && (
                <div
                    className="border-border bg-surface-2 text-fg-muted relative -mt-2 flex h-[38px] items-end gap-1.5 overflow-hidden rounded-b-lg border border-t-0 px-3 pb-1.5 text-xs"
                    data-testid="volume-strip"
                >
                    {volumes.slice(0, 2).map((volume, index) => (
                        <VolumeChip key={volume.id ?? `${volume.name}-${index}`} volume={volume} onOpen={onOpenVolume} />
                    ))}
                    {volumes.length > 2 && <span className="text-fg-faint text-2xs shrink-0 self-center">+{volumes.length - 2}</span>}
                </div>
            )}
        </div>
    );
}

/** "1.2 GB / 10 GB", "340 MB" (no limit), or the detail (server, mount path) while the usage is not known yet. */
function usage(volume: CanvasVolume): string | null {
    if (volume.used_bytes !== null && volume.limit_bytes !== null) return `${formatBytes(volume.used_bytes)} / ${formatBytes(volume.limit_bytes)}`;
    if (volume.used_bytes !== null) return formatBytes(volume.used_bytes);

    return volume.detail;
}

function VolumeChip({ volume, onOpen }: { volume: CanvasVolume; onOpen?: (volumeId: string) => void }) {
    const text = usage(volume);
    const full = volume.used_bytes !== null && volume.limit_bytes ? volume.used_bytes / volume.limit_bytes > 0.85 : false;
    const className = cn(
        'border-border bg-surface-1 text-2xs inline-flex h-5 min-w-0 items-center gap-1 rounded-sm border px-1.5',
        volume.url && 'hover:border-border-strong hover:text-fg',
        full && 'border-warning/50',
    );
    const title = [volume.name, text].filter(Boolean).join(' · ');
    const content = (
        <>
            <HardDrive className={cn('size-3 shrink-0', full ? 'text-warning' : 'text-fg-faint')} aria-hidden />
            <span className="min-w-0 truncate">{volume.name}</span>
            {text && <span className="text-fg-faint tabular shrink-0 truncate">{text}</span>}
        </>
    );

    // Opens the volume (panel or page); never starts a card drag or selects the card.
    return volume.url ? (
        <a
            href={volume.url}
            title={title}
            className={className}
            data-testid="volume-chip"
            onClick={(event) => {
                event.stopPropagation();
                if (!onOpen || !volume.id || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;
                event.preventDefault();
                onOpen(volume.id);
            }}
            onPointerDown={(event) => event.stopPropagation()}
        >
            {content}
        </a>
    ) : (
        <span title={title} className={className} data-testid="volume-chip">
            {content}
        </span>
    );
}
