import { type ManagedConfig } from '@/components/falak';
import { type CanvasService } from '@/types';

/** Compose sites have the `compose` icon and a "Compose · N services" subtitle (Projects canvas read model). */
export const isCompose = (service: CanvasService): boolean =>
    service.kind === 'site' && (service.icon === 'compose' || (service.subtitle ?? '').startsWith('Compose'));

export interface PublicServiceData {
    service: string;
    port: number;
    domain: string | null;
    host_port: number | null;
    test_domain: string | null;
    health_check_path?: string | null;
    url: string | null;
}

export interface ComposeServiceSummary {
    name: string;
    image: string | null;
    build: boolean;
    ports: number[];
    published_ports: string[];
    volumes: string[];
    bind_mounts: string[];
    healthcheck: boolean;
    depends_on: string[];
    leader_command: string | null;
}

export interface ComposeSummary {
    services: ComposeServiceSummary[];
    volumes: string[];
    violations: string[];
    errors: string[];
    warnings: string[];
    allow_privileged?: boolean;
}

/** GET /sites/{site}/compose */
export interface ComposeSettingsData {
    source: 'repo' | 'inline';
    file: string | null;
    /** Repository sources: compose files in -f order, active profiles, per-service decisions, adjustment choices. */
    files: string[];
    profiles: string[];
    services: Record<string, { mode: 'keep' | 'database' | 'site'; database_id?: string; site_id?: string }>;
    adjustments: { keep_binds?: string[] };
    repository: string | null;
    branch: string | null;
    slug: string;
    version: number | null;
    content: string | null;
    summary: ComposeSummary | null;
    versions: { version: number; created_by: string | null; created_at: string }[];
    public_services: PublicServiceData[];
    template: { slug: string; version: string; source: string } | null;
    policy: { allow_privileged: boolean };
    /** What the project's falak.yaml manages (files, profiles, services' ports and health paths): read-only here. */
    managed: ManagedConfig | null;
    can: { update: boolean };
}

export interface ServicePort {
    host_ip?: string;
    host_port?: number;
    container_port: number;
    protocol: string;
}

export interface ServiceRow {
    service: string;
    server_id: string;
    server_name: string;
    container: string | null;
    state: string;
    health: string | null;
    image: string;
    digest: string | null;
    ports: ServicePort[];
    restarts: number;
    cpu_percent: number | null;
    memory_bytes: number | null;
    memory_limit_bytes: number | null;
    started_at: string | null;
    public: PublicServiceData | null;
}

/** GET /sites/{site}/compose/services */
export interface ServicesData {
    services: ServiceRow[];
    servers: { id: string; name: string; reported_at: string | null; refreshing: boolean }[];
    public_services: PublicServiceData[];
    can: { restart: boolean };
}

export const composeUrl = (siteId: string) => `/sites/${siteId}/compose`;

/** StatusDot / StatusBadge key and label of a container. */
export function containerStatus(row: Pick<ServiceRow, 'state' | 'health'>): { status: string; label: string } {
    if (row.health === 'unhealthy') return { status: 'crashed', label: 'Unhealthy' };
    if (row.state === 'restarting') return { status: 'crashed', label: 'Restarting' };
    if (row.state === 'exited' || row.state === 'dead') return { status: 'failed', label: row.state === 'dead' ? 'Dead' : 'Exited' };
    if (row.state === 'running' && row.health === 'starting') return { status: 'deploying', label: 'Starting' };
    if (row.state === 'running') return { status: 'active', label: row.health === 'healthy' ? 'Healthy' : 'Running' };
    if (row.state === 'paused') return { status: 'inactive', label: 'Paused' };

    return { status: 'queued', label: row.state ? row.state[0].toUpperCase() + row.state.slice(1) : 'Unknown' };
}

export function formatBytes(bytes: number | null): string {
    if (bytes === null) return '—';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value >= 100 || unit === 0 ? Math.round(value) : value.toFixed(1)} ${units[unit]}`;
}

/** "redis:7.4.1-alpine" from "docker.io/library/redis:7.4.1-alpine@sha256:…"; registry image names keep their last path segment. */
export function shortImage(image: string): string {
    const withoutDigest = image.split('@')[0];
    const parts = withoutDigest.split('/');

    return parts[parts.length - 1] || withoutDigest;
}
