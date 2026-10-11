import { type ManagedConfig, type SelectOption } from '@/components/falak';

export type ServiceMode = 'include' | 'share' | 'omit';

export type DatabaseStrategy = 'empty' | 'clone_backup' | 'clone_sanitize';

export interface DatabaseSettings {
    strategy: DatabaseStrategy;
    source_environment_id?: string | null;
    sanitize_kind?: 'sql' | 'command' | null;
    sanitize_script?: string | null;
    acknowledge_production?: boolean;
}

export interface PreviewSettingsForm {
    enabled: boolean;
    base_environment_id: string | null;
    services: Record<string, ServiceMode>;
    server_id: string | null;
    fork_server_id: string | null;
    variables: string[];
    acknowledge_shared_database: boolean;
    domain_pattern: string;
    databases: Record<string, DatabaseSettings>;
    max_concurrent: number;
    idle_ttl_hours: number;
    access: 'basic' | 'public';
    /** Set by the project's falak.yaml `previews:` block: the form is read-only (edit the file). */
    managed?: ManagedConfig | null;
}

export interface PreviewRow {
    id: string;
    number: number;
    title: string;
    url: string | null;
    author: string | null;
    repository: string;
    head_branch: string;
    head_sha: string;
    is_fork: boolean;
    status: string;
    status_message: string | null;
    urls: Record<string, string>;
    credentials: { username: string; password: string | null } | null;
    databases: Record<string, { strategy: DatabaseStrategy; state: string }>;
    environment_id: string | null;
    approved_at: string | null;
    created_at: string;
    last_activity_at: string | null;
    closed_at: string | null;
}

export const STATUS_LABELS: Record<string, string> = {
    waiting_approval: 'Waiting for approval',
    queued: 'Waiting (limit reached)',
    creating: 'Setting up',
    deploying: 'Deploying',
    ready: 'Ready',
    failed: 'Failed',
    closed: 'Closed',
};

export const SERVICE_MODES: SelectOption<ServiceMode>[] = [
    { value: 'include', label: 'Run in each preview' },
    { value: 'share', label: 'Share the base environment’s' },
    { value: 'omit', label: 'Leave out' },
];

export const DATABASE_STRATEGIES: SelectOption<DatabaseStrategy>[] = [
    { value: 'empty', label: 'Empty (the deploy migrates and seeds)' },
    { value: 'clone_backup', label: 'Newest backup of an environment' },
    { value: 'clone_sanitize', label: 'Production, sanitized' },
];
