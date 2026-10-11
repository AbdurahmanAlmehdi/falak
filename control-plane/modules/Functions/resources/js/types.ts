import { type ManagedConfig } from '@/components/falak';
export interface FunctionVersionSummary {
    id: string;
    number: number;
    hash: string;
    short_hash: string;
    message: string | null;
    author: string | null;
    size: number;
    created_at: string;
}

export type FunctionFiles = Record<string, string>;

export interface FunctionSettings {
    min_instances: number;
    max_instances: number;
    concurrency: number;
    idle_timeout_s: number;
    memory_mb: number;
    cpus: number;
    request_timeout_s: number;
}

/** GET /sites/{site}/function */
export interface FunctionState {
    site: { id: string; name: string; slug: string };
    runtime: { key: string; label: string; language: string; family: string };
    entrypoint: string;
    head: (FunctionVersionSummary & { files: FunctionFiles }) | null;
    live: FunctionVersionSummary | null;
    draft: { files: FunctionFiles; base_version_id: string | null; updated_at: string } | null;
    settings: FunctionSettings;
    /** What the project's falak.yaml manages (its code under `root`, settings): read-only here. */
    managed: ManagedConfig | null;
    bounds: Record<keyof FunctionSettings, [number, number]>;
    limits: { max_bytes: number; max_files: number };
    can: { edit: boolean; deploy: boolean };
}

/** GET /sites/{site}/function/status: the leader server's gateway (null until it answered). */
export interface LiveStatus {
    status: {
        release: string | null;
        running: number;
        starting: number;
        in_flight: number;
        cold_starts: number;
        requests: number;
        last_request_at?: string | null;
    } | null;
    at: string | null;
}

export interface Starter {
    key: string;
    title: string;
    description: string;
    category: string;
    variables: string[];
    schedule: { name: string; expression: string } | null;
    families: string[];
}

export interface RuntimeOption {
    key: string;
    label: string;
    family: string;
    language: string;
    entrypoint: string;
}

/** GET /functions/starters */
export interface StarterCatalog {
    runtimes: RuntimeOption[];
    default_runtime: string;
    starters: Starter[];
}

export const functionUrl = (siteId: string, path = '') => `/sites/${siteId}/function${path}`;
