import { type DrillFrequency, type DrillRow, type DrillStatus, type EncryptionMode } from '@/components/backup-protection';
import { type ManagedConfig } from '@/components/falak';

export {
    AGE_IDENTITY,
    AGE_RECIPIENT,
    needsIdentity,
    type DrillCheck,
    type DrillFrequency,
    type DrillRow,
    type DrillStatus,
    type EncryptionMode,
} from '@/components/backup-protection';
export type EngineName = 'mysql' | 'mariadb' | 'postgresql' | 'redis' | 'valkey';
/** sql: databases, users and grants; key_value: Redis / Valkey (one keyspace, one `default` user). */
export type EngineKind = 'sql' | 'key_value';

export const KEY_VALUE_ENGINES: readonly string[] = ['redis', 'valkey'];

export function isKeyValue(engine: string | null | undefined): boolean {
    return engine !== null && engine !== undefined && KEY_VALUE_ENGINES.includes(engine);
}

/** A running database container a backup can be restored into, with its active databases (a restore goes into one). */
export interface RestoreTarget {
    id: string;
    label: string;
    engine: EngineName;
    databases: string[];
}

export type ResourceStatus = 'pending' | 'active' | 'failed' | 'deleting';
/** inspecting: a point-in-time restore's read-only copy, waiting for a decision. */
export type InstanceStatus = 'pending' | 'active' | 'failed' | 'upgrading' | 'retired' | 'deleting' | 'inspecting';
/** Heartbeat health of the container (null before the first report). */
export type InstanceHealth = 'healthy' | 'unhealthy' | 'starting' | 'none' | 'stopped' | 'missing';
export type BackupStatus = 'pending' | 'running' | 'succeeded' | 'failed' | 'pruned';
export type RestoreStatus = 'pending' | 'running' | 'succeeded' | 'failed' | 'awaiting_decision' | 'discarded';

/** falak-db settings of a container (docs/DB_IMAGES.md "Settings"). */
export interface InstanceSettings {
    max_connections?: number;
    slow_query_ms?: number;
    /** Redis / Valkey */
    eviction?: string;
    persistence?: 'rdb' | 'aof' | 'none';
}

/** One database container (falak-db-<id>) on a server. */
export interface DatabaseInstance {
    id: string;
    name: string;
    server_id: string;
    server_name: string;
    environment_id: string | null;
    engine: EngineName;
    engine_label: string;
    kind: EngineKind;
    version: string;
    image: string;
    image_digest: string | null;
    /** DNS name on the environment's Docker network */
    hostname: string;
    /** Port inside the container */
    port: number;
    /** Published on 127.0.0.1 (and private addresses) */
    host_port: number | null;
    published_addresses: string[];
    /** New addresses for servers of the environment, waiting to be applied (the container is recreated). */
    pending_published_addresses: string[] | null;
    /** Public access allowlist (CIDRs). */
    allowed_sources: string[];
    /** Redis / Valkey: the previous password stays valid until then. */
    password_overlap_until: string | null;
    replaced_by: string | null;
    public_access: boolean;
    require_tls: boolean;
    memory_mb: number;
    cpus: number | null;
    settings: InstanceSettings;
    pitr_enabled: boolean;
    /** A point-in-time restore's copy: the instance it was restored from (until a decision). */
    restored_from: string | null;
    volume_id: string | null;
    tls_expires_at: string | null;
    status: InstanceStatus;
    status_message: string | null;
    health: InstanceHealth | null;
    health_at: string | null;
    rotating_password: boolean;
    upgrade_of: string | null;
    retire_at: string | null;
    databases_count: number | null;
    users_count: number | null;
    created_at: string;
    /** What the project's falak.yaml manages (read-only here), null when nothing. */
    managed?: ManagedConfig | null;
}

/** Create options of the Databases page and the canvas picker. */
export interface EngineOption {
    value: EngineName;
    label: string;
    kind: EngineKind;
    versions: string[];
    default_version: string;
    default_memory_mb: number;
    min_memory_mb: number;
    default_disk_gb: number;
}

export interface CreateOptions {
    engines: EngineOption[];
    servers: { id: string; name: string }[];
}

export interface DatabaseRow {
    id: string;
    instance_id: string;
    name: string;
    charset: string | null;
    collation: string | null;
    site_id: string | null;
    status: ResourceStatus;
    status_message: string | null;
    command_id: string | null;
    created_at: string;
    managed?: ManagedConfig | null;
}

export interface GrantRow {
    database_id: string;
    database: string | null;
    privileges: string[];
}

export interface DatabaseUserRow {
    id: string;
    username: string;
    host: string;
    site_id: string | null;
    status: ResourceStatus;
    status_message: string | null;
    command_id: string | null;
    grants: GrantRow[];
    created_at: string;
}

export interface ScheduleRow {
    id: string;
    name: string;
    /** The unit's `backups` in falak.yaml: read-only here. */
    managed?: ManagedConfig | null;
    cron: string;
    storage_provider_id: string;
    storage_provider: string | null;
    database_ids: string[];
    databases: string[];
    retention_count: number | null;
    retention_days: number | null;
    enabled: boolean;
    last_run_at: string | null;
    next_run_at: string | null;
    /** cp: Falak holds each backup's key (sealed); customer: encrypted to age_recipient, Falak never has it. */
    encryption_mode: EncryptionMode;
    age_recipient: string | null;
    drill: DrillFrequency;
    drill_query: string | null;
    drill_server_id: string | null;
    last_drill_at: string | null;
    next_drill_at: string | null;
    drills: DrillRow[];
}

export interface BackupRow {
    id: string;
    database_name: string;
    server_id: string;
    server_name: string;
    instance_id: string | null;
    instance_name: string | null;
    engine: EngineName;
    engine_version: string | null;
    storage_provider: string | null;
    object_key: string;
    compression: 'zstd';
    /** Null: taken before encryption (not restorable). */
    encryption_mode: EncryptionMode | null;
    cipher: string | null;
    plaintext_sha256: string | null;
    drill_status: DrillStatus | null;
    /** When a restore drill last restored it successfully. */
    verified_at: string | null;
    trigger: 'manual' | 'scheduled' | 'pitr';
    /** base: a physical backup of the whole instance for point-in-time recovery. */
    type: 'logical' | 'base';
    log_start: string | null;
    base_started_at: string | null;
    base_finished_at: string | null;
    schedule_id: string | null;
    status: BackupStatus;
    size_bytes: number | null;
    sha256: string | null;
    duration_ms: number | null;
    error: string | null;
    prune_error: string | null;
    command_id: string | null;
    restorable: boolean;
    created_at: string;
    /** When the backup was handed to the agent. */
    started_at: string | null;
    finished_at: string | null;
    pruned_at: string | null;
}

export interface RestoreRow {
    id: string;
    type: 'backup' | 'pitr';
    backup_id: string;
    target_time: string | null;
    source_database: string;
    database_name: string | null;
    status: RestoreStatus;
    bytes: number | null;
    duration_ms: number | null;
    error: string | null;
    warnings: string[];
    command_id: string | null;
    created_at: string;
    finished_at: string | null;
}

export interface StorageProviderRow {
    id: string;
    name: string;
    driver: 's3' | 'r2' | 'b2' | 'spaces' | 'minio';
    driver_label: string;
    endpoint: string | null;
    region: string;
    bucket: string;
    prefix: string | null;
    path_style: boolean;
    access_key_hint: string;
    verified_at: string | null;
    created_at: string;
}

export interface StorageOption {
    id: string;
    name: string;
    driver: string;
    bucket: string;
}

/** An address applications reach the container on (the port differs: the container's own on the network, the host port elsewhere). */
export interface ConnectionHost {
    label: string;
    value: string;
    port: number;
    hint: string;
}

/** A site of the container's environment, and the host its references resolve to (or why not). */
export interface ConnectionAccess {
    name: string;
    host: string | null;
    port: number;
    reason: string | null;
}

export interface Connection {
    engine: EngineName;
    kind: EngineKind;
    driver: string;
    port: number;
    host_port: number | null;
    hosts: ConnectionHost[];
    access: ConnectionAccess[];
}

/** Options of one container's page / panel. */
export interface InstanceOptions {
    privileges: string[];
    /** The current major and newer ones (upgrades only go forward). */
    versions: string[];
    /** The organization's other servers drills may run on. */
    drill_servers: { id: string; name: string }[];
    default_charset?: string | null;
    default_collation?: string | null;
    evictions: string[];
    persistences: string[];
    min_memory_mb: number;
    upgradable: boolean;
}

/**
 * The restore form after another target container is picked: the database chosen for the previous target is kept only
 * when the new one has a database of that name (a restore goes into an existing database), else it is cleared, and
 * with it the confirmation.
 */
export function retargetRestore<T extends { database_instance_id: string; database: string; confirm: string }>(
    data: T,
    instanceId: string,
    targets: RestoreTarget[],
): T {
    const databases = targets.find((target) => target.id === instanceId)?.databases ?? [];

    return databases.includes(data.database)
        ? { ...data, database_instance_id: instanceId }
        : { ...data, database_instance_id: instanceId, database: '', confirm: '' };
}

/** The heartbeat's view of an instance's PITR spool. */
export interface PitrReport {
    spool_bytes: number;
    volume_bytes: number;
    pending: number;
    oldest_pending_at: string | null;
    last_shipped_at: string | null;
    error: string | null;
    at: string;
}

export interface PitrTimeline {
    /** Recoverable ranges (UTC, ms precision), oldest first. */
    ranges: { from: string; to: string; base_id: string }[];
    gaps: { at: string; detail: string; resolved: boolean }[];
    from: string | null;
    to: string | null;
    last_segment_at: string | null;
}

export type PitrDecision = 'swap' | 'keep' | 'discard';

export interface PitrRestoreRow {
    id: string;
    status: RestoreStatus;
    target_time: string | null;
    /** Recovered to the end of the shipped log (target_time: how far it reached). */
    to_latest: boolean;
    decision: PitrDecision | null;
    error: string | null;
    warnings: string[];
    segments: number | null;
    /** Rows per table of each database, as restored. */
    table_counts: Record<string, Record<string, number>>;
    duration_ms: number | null;
    created_at: string;
    finished_at: string | null;
    /** The read-only copy (127.0.0.1 on its server only, on no network). */
    copy: {
        id: string;
        name: string;
        status: InstanceStatus;
        status_message: string | null;
        server_name: string;
        host: string;
        port: number | null;
        username: string;
    } | null;
}

export interface PitrState {
    supported: boolean;
    enabled: boolean;
    storage_provider_id: string | null;
    encryption_mode: EncryptionMode;
    age_recipient: string | null;
    window_days: number;
    base_interval_days: number;
    next_base_at: string | null;
    last_shipped_at: string | null;
    report: PitrReport | null;
    /** MySQL / MariaDB: a restore lands on the second (binlog timestamps have whole seconds). */
    second_precision: boolean;
    timeline: PitrTimeline | null;
    bases: BackupRow[];
    restores: PitrRestoreRow[];
}

/** Whether a time (ISO 8601) falls in one of the timeline's recoverable ranges. */
export function inRecoveryRange(timeline: PitrTimeline | null, iso: string): boolean {
    const t = Date.parse(iso);

    if (!timeline || Number.isNaN(t)) {
        return false;
    }

    return timeline.ranges.some((range) => Date.parse(range.from) <= t && t <= Date.parse(range.to));
}

/**
 * Positions (percent of the bar) of the timeline's ranges and gaps between `start` and `end` (ms since the epoch):
 * what lies outside is clipped, what is entirely outside is left out.
 */
export function timelineBar(
    timeline: PitrTimeline,
    start: number,
    end: number,
): { ranges: { left: number; width: number; from: string; to: string }[]; gaps: { left: number; at: string; detail: string }[] } {
    const span = Math.max(1, end - start);
    const pct = (t: number) => ((Math.min(end, Math.max(start, t)) - start) / span) * 100;

    return {
        ranges: timeline.ranges
            .filter((range) => Date.parse(range.to) >= start && Date.parse(range.from) <= end)
            .map((range) => ({
                left: pct(Date.parse(range.from)),
                width: pct(Date.parse(range.to)) - pct(Date.parse(range.from)),
                from: range.from,
                to: range.to,
            })),
        gaps: timeline.gaps
            .filter((gap) => Date.parse(gap.at) >= start && Date.parse(gap.at) <= end)
            .map((gap) => ({ left: pct(Date.parse(gap.at)), at: gap.at, detail: gap.detail })),
    };
}

/**
 * A `datetime-local` value (read as UTC: the restore picker works in UTC) as ISO 8601 with a Z, or null. Seconds are
 * optional; MySQL / MariaDB restores (secondPrecision) drop fractions.
 */
export function utcInputToIso(value: string, secondPrecision = false): string | null {
    const match = /^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})(?::(\d{2})(\.\d{1,3})?)?$/.exec(value.trim());

    if (!match) {
        return null;
    }

    const fraction = secondPrecision ? '' : (match[4] ?? '');
    const iso = `${match[1]}T${match[2]}:${match[3] ?? '00'}${fraction}Z`;

    return Number.isNaN(Date.parse(iso)) ? null : iso;
}

/** An ISO time as the UTC `datetime-local` value of the restore picker (seconds precision). */
export function isoToUtcInput(iso: string): string {
    return new Date(iso).toISOString().slice(0, 19);
}

/** "12 s", "4 min", "3 h", "2 d" since `iso` (or "—"). */
export function ageOf(iso: string | null, now: number = Date.now()): string {
    if (!iso) {
        return '—';
    }

    const seconds = Math.max(0, Math.round((now - Date.parse(iso)) / 1000));

    if (seconds < 120) return `${seconds} s`;
    if (seconds < 7200) return `${Math.round(seconds / 60)} min`;
    if (seconds < 172800) return `${Math.round(seconds / 3600)} h`;

    return `${Math.round(seconds / 86400)} d`;
}

/** "PostgreSQL 17 · 512 MB" */
export function instanceSummary(instance: Pick<DatabaseInstance, 'engine_label' | 'version' | 'memory_mb'>): string {
    return `${instance.engine_label} ${instance.version} · ${instance.memory_mb} MB`;
}

/** The Falak status language for a container: health wins once it is running. */
export function instanceState(instance: Pick<DatabaseInstance, 'status' | 'health'>): string {
    if (instance.status === 'active' && instance.health && ['unhealthy', 'stopped', 'missing'].includes(instance.health)) {
        return instance.health === 'unhealthy' ? 'degraded' : 'offline';
    }

    return instance.status === 'pending' ? 'provisioning' : instance.status;
}
