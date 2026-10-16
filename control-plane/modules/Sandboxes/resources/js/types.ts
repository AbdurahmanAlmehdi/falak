import { useEffect, useState } from 'react';

export type SandboxStatus = 'creating' | 'running' | 'paused' | 'failed' | 'killed';

/** The API resource (docs/plans/SANDBOXES.md "HTTP API"). */
export interface Sandbox {
    id: string;
    name: string;
    status: SandboxStatus;
    image: string;
    server: { id: string; name: string | null };
    cpus: number;
    memory_mb: number;
    disk_mb: number;
    network: 'none' | 'internet' | 'allowlist';
    allow_hosts: string[];
    env_keys: string[];
    repo: { url: string; ref?: string } | null;
    ttl_seconds: number;
    expires_at: string;
    created_at: string;
    created_by: { type: 'user' | 'token'; name: string | null };
    ip: string | null;
    kill_reason: string | null;
    error: string | null;
    idle_pause_seconds: number | null;
    last_activity_at: string | null;
    from_snapshot: { id: string; name: string | null } | null;
}

/** A snapshot (docs/plans/SANDBOXES.md "Contracts (v0.21)"); can_operate: fork, rename, delete. */
export interface Snapshot {
    id: string;
    name: string | null;
    status: 'creating' | 'ready' | 'failed' | 'deleted';
    sandbox: { id: string; name: string | null };
    server: { id: string; name: string | null };
    image: string;
    cpus: number;
    memory_mb: number;
    disk_mb: number;
    network: Sandbox['network'];
    env_keys: string[];
    size_bytes: number | null;
    error: string | null;
    created_at: string;
    created_by: { type: 'user' | 'token'; name: string | null };
    can_operate?: boolean;
}

/** Idle pause choices of the create and fork dialogs (seconds; 0 = never). */
export const IDLE_PAUSES = [
    { value: '0', label: 'Never' },
    { value: '300', label: 'After 5 minutes' },
    { value: '600', label: 'After 10 minutes' },
    { value: '1800', label: 'After 30 minutes' },
    { value: '3600', label: 'After 1 hour' },
];

export interface SandboxExec {
    id: string;
    cmd: string;
    cwd: string | null;
    exit_code: number | null;
    duration_ms: number | null;
    timed_out: boolean;
    actor: string | null;
    actor_type: 'user' | 'token';
    started_at: string;
}

export interface FileEntry {
    name: string;
    type: 'file' | 'dir' | 'link' | 'other';
    size?: number;
    mode?: string;
    mtime?: number | string;
}

/** StatusBadge status and label for a sandbox. */
export function sandboxBadge(sandbox: Pick<Sandbox, 'status'>): { status: string; label: string } {
    switch (sandbox.status) {
        case 'creating':
            return { status: 'provisioning', label: 'Creating' };
        case 'running':
            return { status: 'active', label: 'Running' };
        case 'paused':
            return { status: 'inactive', label: 'Paused' };
        case 'failed':
            return { status: 'failed', label: 'Failed' };
        default:
            return { status: 'removed', label: 'Killed' };
    }
}

export function isLive(sandbox: Pick<Sandbox, 'status'>): boolean {
    return sandbox.status === 'creating' || sandbox.status === 'running' || sandbox.status === 'paused';
}

export function snapshotBadge(snapshot: Pick<Snapshot, 'status'>): { status: string; label: string } {
    switch (snapshot.status) {
        case 'creating':
            return { status: 'provisioning', label: 'Taking' };
        case 'ready':
            return { status: 'active', label: 'Ready' };
        case 'failed':
            return { status: 'failed', label: 'Failed' };
        default:
            return { status: 'removed', label: 'Deleted' };
    }
}

/** A snapshot's name, or its id's start. */
export function snapshotLabel(snapshot: Pick<Snapshot, 'id' | 'name'>): string {
    return snapshot.name ?? snapshot.id.slice(0, 10);
}

/** "42m 10s", "1h 05m"; "expired" at zero. */
export function formatTimeLeft(seconds: number): string {
    if (seconds <= 0) return 'expired';
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = Math.floor(seconds % 60);

    return h > 0 ? `${h}h ${String(m).padStart(2, '0')}m` : `${m}m ${String(s).padStart(2, '0')}s`;
}

/** The current time, ticking every second (countdowns). */
export function useNow(): number {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 1000);

        return () => window.clearInterval(timer);
    }, []);

    return now;
}

export function secondsLeft(sandbox: Pick<Sandbox, 'expires_at'>, now: number): number {
    return Math.floor((new Date(sandbox.expires_at).getTime() - now) / 1000);
}

export function formatMemory(mb: number): string {
    return mb >= 1024 && mb % 1024 === 0 ? `${mb / 1024} GB` : `${mb} MB`;
}

export function ttlLabel(seconds: number): string {
    return seconds < 3600 ? `${seconds / 60} minutes` : seconds === 3600 ? '1 hour' : `${seconds / 3600} hours`;
}
