/** Packet capture (docs/plans/network/capture.md): the form, the request body it builds, and progress formatting. */

export type CaptureInterface = 'default' | 'wireguard' | 'docker';

export interface NetCaptureForm {
    interface: CaptureInterface;
    duration_s: string;
    max_mb: string;
    full_packets: boolean;
    host: string;
    port: string;
    protocol: '' | 'tcp' | 'udp' | 'icmp';
    direction: 'both' | 'in' | 'out';
}

export interface NetCapture {
    id: string;
    target_type: 'server' | 'site' | 'database';
    target_name: string | null;
    server_name: string;
    container: string | null;
    interface: CaptureInterface | null;
    filter: { host?: string; port?: number; protocol?: string; direction?: string } | null;
    snaplen: number;
    duration_s: number;
    max_bytes: number;
    status: 'queued' | 'capturing' | 'uploading' | 'ready' | 'failed' | 'cancelled' | 'expired';
    cancel_requested: boolean;
    bytes_captured: number;
    packets_captured: number;
    size_bytes: number | null;
    error: string | null;
    created_at: string;
    started_at: string | null;
    expires_at: string;
    download_url: string | null;
}

export const CAPTURE_WARNING =
    'Packet captures may contain sensitive data (cookies, tokens, personal data). The file is kept encrypted for 24 hours and every download is audited.';

export const EMPTY_CAPTURE: NetCaptureForm = {
    interface: 'default',
    duration_s: '30',
    max_mb: '20',
    full_packets: false,
    host: '',
    port: '',
    protocol: '',
    direction: 'both',
};

const number = (value: string): string | number => (/^\d+$/.test(value.trim()) ? Number(value.trim()) : value.trim());

/** The request body: the interface only for host captures, the filter only with what's set, numbers as numbers. */
export function captureBody(form: NetCaptureForm, inContainer: boolean): Record<string, unknown> {
    const filter: Record<string, string | number> = {};
    if (form.host.trim()) filter.host = form.host.trim();
    if (form.port.trim()) filter.port = number(form.port);
    if (form.protocol) filter.protocol = form.protocol;
    if (form.direction !== 'both') filter.direction = form.direction;

    return {
        ...(inContainer ? {} : { interface: form.interface }),
        duration_s: number(form.duration_s),
        max_mb: number(form.max_mb),
        ...(form.full_packets ? { full_packets: true } : {}),
        ...(Object.keys(filter).length > 0 ? { filter } : {}),
        acknowledged: true,
    };
}

export function isActive(capture: Pick<NetCapture, 'status'>): boolean {
    return ['queued', 'capturing', 'uploading'].includes(capture.status);
}

export function formatBytes(bytes: number): string {
    if (bytes >= 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MiB`;
    if (bytes >= 1024) return `${(bytes / 1024).toFixed(1)} KiB`;
    return `${bytes} B`;
}

/** "142 packets · 31.0 KiB of 20.0 MiB". */
export function describeProgress(capture: Pick<NetCapture, 'packets_captured' | 'bytes_captured' | 'max_bytes'>): string {
    return `${capture.packets_captured} packets · ${formatBytes(capture.bytes_captured)} of ${formatBytes(capture.max_bytes)}`;
}

/** "tcp port 443 host 10.0.0.5, in" or "everything". */
export function describeFilter(filter: NetCapture['filter']): string {
    if (!filter) return 'everything';
    const parts = [filter.protocol, filter.port ? `port ${filter.port}` : null, filter.host ? `host ${filter.host}` : null].filter(Boolean);
    const text = parts.join(' ') || 'everything';
    return filter.direction && filter.direction !== 'both' ? `${text}, ${filter.direction}` : text;
}

/** Fraction of the duration elapsed since the capture started (0 before it starts, at most 1). */
export function elapsedFraction(capture: Pick<NetCapture, 'started_at' | 'duration_s'>, now: number): number {
    if (!capture.started_at) return 0;
    return Math.min(1, Math.max(0, (now - Date.parse(capture.started_at)) / (capture.duration_s * 1000)));
}
