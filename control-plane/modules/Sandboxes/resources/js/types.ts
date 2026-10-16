import { useEffect, useState } from 'react';

export type SandboxStatus = 'creating' | 'running' | 'failed' | 'killed';

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
}

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
        case 'failed':
            return { status: 'failed', label: 'Failed' };
        default:
            return { status: 'removed', label: 'Killed' };
    }
}

export function isLive(sandbox: Pick<Sandbox, 'status'>): boolean {
    return sandbox.status === 'creating' || sandbox.status === 'running';
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
