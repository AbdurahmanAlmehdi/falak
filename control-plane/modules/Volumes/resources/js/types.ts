import { type DrillFrequency, type DrillRow, type DrillStatus, type EncryptionMode } from '@/components/backup-protection';
import { type ManagedConfig } from '@/components/falak';

/** Volumes read models (Falak\Volumes\Http\Controllers\PresentsVolumes). */

export type VolumeKind = 'docker' | 'sized' | 'bind' | 'shared_path';
export type VolumeStatus = 'pending' | 'active' | 'failed' | 'deleting';
export type Consistency = 'none' | 'pause' | 'stop';

export interface VolumeAttachment {
    id: string;
    type: 'site' | 'compose_service' | 'database';
    attachable_id: string;
    name: string;
    service: string | null;
    mount_path: string;
    read_only: boolean;
    url: string | null;
    /** Only plain site attachments: compose services follow their file, databases keep their data volume. */
    detachable: boolean;
}

export interface Volume {
    id: string;
    name: string;
    kind: VolumeKind;
    kind_label: string;
    server: { id: string; name: string } | null;
    docker_name: string | null;
    host_path: string | null;
    size_limit_bytes: number | null;
    used_bytes: number | null;
    used_at: string | null;
    /** used / limit (0–1), sized volumes only. */
    usage: number | null;
    protected: boolean;
    status: VolumeStatus;
    status_message: string | null;
    labels: Record<string, string>;
    compose: { site_id: string | null; key: string | null; external: boolean } | null;
    shared_type: 'file' | 'directory' | null;
    attachments: VolumeAttachment[];
    url: string;
    created_at: string | null;
    schedules: number;
    last_backup_at: string | null;
    /** What the project's falak.yaml manages (read-only here), null when nothing. */
    managed?: ManagedConfig | null;
}

export interface VolumeBackup {
    id: string;
    volume_id: string | null;
    volume_name: string;
    volume_kind: VolumeKind;
    server_id: string | null;
    schedule_id: string | null;
    storage_provider_id: string | null;
    trigger: 'manual' | 'scheduled' | 'move' | 'clone';
    consistency: Consistency;
    status: 'pending' | 'succeeded' | 'failed' | 'pruned';
    size_bytes: number | null;
    uncompressed_bytes: number | null;
    volume_size_bytes: number | null;
    sha256: string | null;
    plaintext_sha256: string | null;
    files: number | null;
    /** Null: taken before encryption (not restorable). */
    encryption_mode: EncryptionMode | null;
    cipher: string | null;
    drill_status: DrillStatus | null;
    verified_at: string | null;
    duration_ms: number | null;
    error: string | null;
    restorable: boolean;
    created_at: string;
    finished_at: string | null;
}

export interface BackupSchedule {
    id: string;
    /** The volume's `backups` in falak.yaml: read-only here. */
    managed?: ManagedConfig | null;
    storage_provider_id: string | null;
    cron: string;
    retention_count: number | null;
    retention_days: number | null;
    consistency: Consistency;
    enabled: boolean;
    last_run_at: string | null;
    next_run_at: string | null;
    encryption_mode: EncryptionMode;
    age_recipient: string | null;
    drill: DrillFrequency;
    drill_server_id: string | null;
    next_drill_at: string | null;
    drills: DrillRow[];
}

export interface VolumeOperation {
    id: string;
    volume_id: string | null;
    kind: 'create' | 'resize' | 'delete' | 'clone' | 'restore' | 'move' | 'download';
    status: 'pending' | 'running' | 'succeeded' | 'failed';
    step: string | null;
    error: string | null;
    meta: { path?: string; from?: number; to?: number; source_id?: string; source_name?: string; backup_id?: string; swap_from?: string };
    result: { bytes?: number; files?: number; size_bytes?: number; name?: string; format?: string; redeployed?: string[]; source_kept?: string };
    created_at: string;
    finished_at: string | null;
}

export interface StorageProvider {
    id: string;
    name: string;
    driver: string;
    bucket: string;
}

export interface ServerOption {
    id: string;
    name: string;
    docker: boolean;
}

export interface VolumeAbilities {
    manage: boolean;
    browse: boolean;
}

/** What the "New volume" dialog offers (VolumePageController::creation). */
export interface CreationProps {
    servers: ServerOption[];
    bind_allow: string[];
    limits: { min_size_bytes: number; max_size_bytes: number };
    can: VolumeAbilities;
}

export interface BrowseEntry {
    name: string;
    path: string;
    type: 'file' | 'dir' | 'symlink' | 'other';
    size: number;
    mtime: string;
}

export interface BrowseResult {
    path: string;
    entries: BrowseEntry[];
    total: number;
    truncated: boolean;
}

/** GET /sites/{site}/volumes. */
export interface SiteVolumes {
    attachable: boolean;
    volumes: Volume[];
    available: Volume[];
    can: { manage: boolean };
}

export const GIB = 1024 ** 3;

export const CONSISTENCY_OPTIONS: { value: Consistency; label: string }[] = [
    { value: 'none', label: 'None (read while running)' },
    { value: 'pause', label: 'Pause containers (seconds)' },
    { value: 'stop', label: 'Stop containers' },
];

export function kindLabel(kind: VolumeKind): string {
    return { docker: 'Docker', sized: 'Sized', bind: 'Host path', shared_path: 'Shared path' }[kind];
}

/** The status shown for a volume: its lifecycle status, or "error" when the last report had a problem. */
export function volumeStatus(volume: Pick<Volume, 'status' | 'status_message'>): string {
    return volume.status === 'active' && volume.status_message ? 'degraded' : volume.status;
}
