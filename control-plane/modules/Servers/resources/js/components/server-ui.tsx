import { Tag } from '@/components/falak/tag';
import { Tooltip } from '@/components/falak/tooltip';
import { cn } from '@/lib/utils';
import { Server } from 'lucide-react';
import { siAkamai, siContabo, siDigitalocean, siHetzner, siVultr, type SimpleIcon } from 'simple-icons';
import { type ServerSummary } from '../types';

const PROVIDER_ICONS: Record<string, SimpleIcon> = {
    hetzner: siHetzner,
    digitalocean: siDigitalocean,
    vultr: siVultr,
    linode: siAkamai,
    contabo: siContabo,
};

/** Provider mark (monochrome, current text color); a generic server glyph for custom / unknown providers. */
export function ProviderIcon({ provider, size = 16, className }: { provider: string; size?: number; className?: string }) {
    const icon = PROVIDER_ICONS[provider];

    if (!icon) {
        return <Server className={cn('shrink-0', className)} style={{ width: size, height: size }} aria-hidden />;
    }

    return (
        <svg viewBox="0 0 24 24" width={size} height={size} className={cn('shrink-0 fill-current', className)} aria-hidden>
            <path d={icon.path} />
        </svg>
    );
}

/** Tone of a utilisation percentage: calm until it matters. */
function usageTone(value: number): string {
    if (value >= 90) return 'bg-danger';
    if (value >= 75) return 'bg-warning';

    return 'bg-fg-faint';
}

/** Thin usage meter + percentage (tabular). */
export function UsageMeter({ value, label, className }: { value: number | null; label: string; className?: string }) {
    if (value === null) {
        return <span className="text-fg-faint text-xs">—</span>;
    }

    const clamped = Math.max(0, Math.min(100, value));

    return (
        <div className={cn('flex items-center gap-2', className)} title={`${label} ${clamped.toFixed(1)}%`}>
            <div
                className="bg-surface-3 h-1 w-14 overflow-hidden rounded-full"
                role="meter"
                aria-label={label}
                aria-valuenow={Math.round(clamped)}
                aria-valuemin={0}
                aria-valuemax={100}
            >
                <div className={cn('h-full rounded-full', usageTone(clamped))} style={{ width: `${clamped}%` }} />
            </div>
            <span className="text-fg-muted tabular w-9 text-right text-xs">{clamped.toFixed(0)}%</span>
        </div>
    );
}

/** Inline SVG sparkline for percentages, with a faint area, for dense tables. */
export function Sparkline({
    values,
    width = 72,
    height = 20,
    label,
    className,
}: {
    values: (number | null)[];
    width?: number;
    height?: number;
    label: string;
    className?: string;
}) {
    const points = values
        .map((value, index) => ({ value, index }))
        .filter((point): point is { value: number; index: number } => point.value !== null);

    if (points.length < 2) {
        return <span className="text-fg-faint inline-block text-xs" style={{ width }} />;
    }

    // Fit the visible range (at least 20 points wide) so small movements stay readable without looking dramatic.
    const lo = Math.min(...points.map((point) => point.value));
    const hi = Math.max(...points.map((point) => point.value));
    const span = Math.max(20, hi - lo + 10);
    const floor = Math.max(0, Math.min(100 - span, (lo + hi) / 2 - span / 2));
    const last = values.length - 1 || 1;
    const x = (index: number) => (index / last) * width;
    const y = (value: number) => height - 1 - ((Math.max(floor, Math.min(floor + span, value)) - floor) / span) * (height - 2);
    const line = points.map((point) => `${x(point.index).toFixed(1)},${y(point.value).toFixed(1)}`).join(' ');
    const area = `${x(points[0].index).toFixed(1)},${height} ${line} ${x(points[points.length - 1].index).toFixed(1)},${height}`;

    return (
        <svg
            width={width}
            height={height}
            viewBox={`0 0 ${width} ${height}`}
            className={cn('text-chart-1 shrink-0 overflow-visible', className)}
            role="img"
            aria-label={label}
        >
            <polygon points={area} className="fill-current opacity-15" />
            <polyline points={line} fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinejoin="round" strokeLinecap="round" />
        </svg>
    );
}

export function formatBytes(bytes: number | null | undefined): string {
    if (bytes === null || bytes === undefined) {
        return '—';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value.toFixed(value >= 10 || unit === 0 ? 0 : 1)} ${units[unit]}`;
}

export function formatUptime(seconds: number | undefined | null): string {
    if (seconds === undefined || seconds === null) return '—';
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);

    return days > 0 ? `${days}d ${hours}h` : hours > 0 ? `${hours}h ${minutes}m` : `${minutes}m`;
}

function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

/** Same-origin JSON request (session auth + CSRF). Throws with the server's message on non-2xx. */
export async function requestJson<T>(url: string, init: { method?: 'GET' | 'POST'; signal?: AbortSignal } = {}): Promise<T> {
    const response = await fetch(url, {
        method: init.method ?? 'GET',
        credentials: 'same-origin',
        signal: init.signal,
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken() },
    });
    const body = (await response.json().catch(() => ({}))) as T & { message?: string };

    if (!response.ok) {
        throw Object.assign(new Error(body.message ?? `HTTP ${response.status}`), { status: response.status });
    }

    return body;
}

/** "v1.2.0" plus whether the control plane ships a newer build, and the state of an upgrade in progress or failed. */
export function AgentVersion({ agent, compact = false }: { agent: ServerSummary['agent']; compact?: boolean }) {
    if (!agent) return <span className="text-fg-faint">—</span>;
    const upgrade = agent.upgrade;
    const inProgress = upgrade && ['queued', 'running'].includes(upgrade.status);
    const failed = upgrade?.status === 'failed' && agent.update_available;

    return (
        <span className="inline-flex max-w-full flex-wrap items-center gap-1.5">
            <span className="font-mono">{agent.version ?? 'unknown'}</span>
            {inProgress ? (
                <Tag tone="info">{upgrade.status === 'queued' ? 'upgrade queued' : `upgrading to ${upgrade.to_version}`}</Tag>
            ) : failed ? (
                <Tooltip content={upgrade.error ?? 'The upgrade failed.'}>
                    <Tag tone="danger">upgrade failed</Tag>
                </Tooltip>
            ) : (
                agent.update_available && <Tag tone="warning">{compact ? 'update available' : `update available: ${agent.available_version}`}</Tag>
            )}
        </span>
    );
}
