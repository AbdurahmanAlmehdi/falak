import { type ManagedConfig } from '@/components/falak';
import { type ResourceLimits } from '@/components/limits-fields';
import { type EnvRow, type ProcessServer, type ProgramStatus } from '../types';

export type ItemKind = 'web' | 'horizon' | 'octane' | 'worker' | 'daemon' | 'scheduler' | 'cron';

export interface WorkerConfig {
    connection: string | null;
    queue: string | null;
    command: string | null;
    processes: number;
    timeout: number;
    sleep: number;
    tries: number;
    backoff: number | null;
    max_jobs: number | null;
    max_time: number | null;
    memory: number;
    /** Resource limits of its slice (shared by its processes). */
    limits: ResourceLimits;
    env: EnvRow[];
    server_ids: string[];
}

export interface DaemonConfig {
    name: string;
    command: string;
    directory: string | null;
    user: string | null;
    instances: number;
    restart: string;
    stop_signal: string;
    stop_timeout: number;
    limits: ResourceLimits;
    env: EnvRow[];
    server_ids: string[];
}

export interface CronConfig {
    name: string;
    command: string;
    expression: string;
    timezone: string | null;
    user: string | null;
    overlap: 'allow' | 'skip';
    timeout: number;
    heartbeat: boolean;
    enabled: boolean;
    all_servers: boolean;
}

export interface ProcessItem {
    kind: ItemKind;
    id: string | null;
    /** Supervisor program / cron job name (= heartbeat `job`). */
    program: string;
    label: string;
    command: string | null;
    detail: string | null;
    instances: number;
    server_ids: string[];
    deployed?: boolean;
    config?: WorkerConfig | DaemonConfig | CronConfig;
    /** Set by the project's falak.yaml: read-only here (edit the file). Null when the file doesn't manage it. */
    managed?: ManagedConfig | null;
}

/** GET /sites/{site}/processes (JSON). */
export interface ProcessesData {
    site: { id: string; name: string; runtime: string; runtime_label: string };
    servers: ProcessServer[];
    programs: ProgramStatus[];
    items: ProcessItem[];
    leader: string | null;
    laravel: { available: boolean; horizon: boolean; octane: boolean; scheduler: boolean };
    defaults: { directory: string; user: string; php: string | null };
    options: { restart: string[]; stop_signals: string[]; presets: { label: string; value: string }[]; timezones: string[] };
    heartbeats_url: string;
    logs_url: string | null;
    container_runtime: boolean;
    can: { manage: boolean };
}

/** Subset of Insights' heartbeat monitor (GET /insights/sites/{site}/summary → heartbeats). */
export interface Heartbeat {
    job: string;
    enabled: boolean;
    last_status: string | null;
    last_run_at: string | null;
    next_expected_at: string | null;
    missed_at: string | null;
}

export const processesUrl = (siteId: string) => `/sites/${siteId}/processes`;

/** Where each kind is created / saved (the Processes module's endpoints). */
export const endpoints: Partial<Record<ItemKind, (siteId: string) => string>> = {
    worker: (siteId) => `/sites/${siteId}/queues`,
    daemon: (siteId) => `/sites/${siteId}/daemons`,
    cron: (siteId) => `/sites/${siteId}/scheduler`,
};

export function heartbeatState(beat: Heartbeat): { status: string; label: string } {
    if (!beat.enabled) return { status: 'inactive', label: 'Not monitored' };
    if (beat.missed_at) return { status: 'failed', label: 'Missed' };
    if (beat.last_status === 'failed' || beat.last_status === 'timeout')
        return { status: 'failed', label: beat.last_status === 'timeout' ? 'Timed out' : 'Failing' };
    if (!beat.last_run_at) return { status: 'queued', label: 'Waiting for first run' };

    return { status: 'healthy', label: 'Healthy' };
}

/**
 * Aggregate status of one program across the servers it runs on (from the last proc.status snapshot).
 */
export function programState(programs: ProgramStatus[]): { status: string; label: string; running: number; total: number } {
    if (programs.length === 0) return { status: 'queued', label: 'Not running yet', running: 0, total: 0 };
    const instances = programs.flatMap((program) => program.instances);
    const total = programs.reduce((sum, program) => sum + Math.max(program.numprocs, program.instances.length), 0);
    const running = instances.filter((instance) => instance.state === 'running').length;

    if (programs.some((program) => program.crash_looping) || instances.some((instance) => ['fatal', 'backoff', 'exited'].includes(instance.state)))
        return { status: 'crashed', label: 'Crashing', running, total };
    if (instances.length === 0)
        return programs.every((program) => program.applied)
            ? { status: 'queued', label: 'Applied', running, total }
            : { status: 'provisioning', label: 'Applying', running, total };
    if (running === 0) return { status: 'inactive', label: 'Stopped', running, total };
    if (instances.some((instance) => instance.state === 'starting' || instance.state === 'stopping'))
        return { status: 'provisioning', label: 'Starting', running, total };

    return { status: 'active', label: 'Running', running, total };
}
