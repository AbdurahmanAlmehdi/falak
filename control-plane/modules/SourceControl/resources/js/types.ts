export type ProviderValue = 'github' | 'gitlab' | 'bitbucket' | 'custom';

export type AuthType = 'oauth' | 'app' | 'token' | 'basic' | 'none';

/** GitHub App installations can be suspended or uninstalled on GitHub. */
export type ConnectionStatus = 'active' | 'suspended' | 'disconnected';

/** An app new installations go through: the operator's env app, or one of this organization's registered apps (one per GitHub owner). */
export interface GitHubAppInfo {
    id: string;
    source: 'env' | 'registered';
    name: string;
    slug: string | null;
    owner: string | null;
    owner_type: 'User' | 'Organization' | null;
    html_url: string | null;
    settings_url: string | null;
    installable: boolean;
    install_url: string;
    webhook_url: string;
    last_delivery_at: string | null;
    created_at: string | null;
}

export interface GitHubInstallation {
    id: string;
    /** The app it was installed through ("env" for the operator's app). */
    app_id: string;
    name: string;
    account: string | null;
    target_type: 'User' | 'Organization' | null;
    status: ConnectionStatus;
    installation_id: string;
    repositories_count: number | null;
    manage_url: string;
    created_at: string;
}

export interface GitHubAppState {
    apps: GitHubAppInfo[];
    permissions: Record<string, string>;
    events: string[];
    installations: GitHubInstallation[];
}

export interface ConnectionRow {
    id: string;
    name: string;
    provider: ProviderValue;
    provider_label: string;
    auth_type: AuthType;
    status: ConnectionStatus;
    account: string | null;
    base_url: string | null;
    deploy_keys_count: number;
    webhooks_count: number;
    created_at: string;
}

export interface PushRow {
    id: string;
    connection_id: string;
    repository: string;
    branch: string;
    sha: string;
    message: string;
    author: string | null;
    pusher: string | null;
    url: string | null;
    received_at: string;
}

export interface ProviderOption {
    value: ProviderValue;
    label: string;
    has_api: boolean;
    oauth: boolean;
}

/** GET /source-control/connections/{id}/repositories */
export interface RepositoryOption {
    full_name: string;
    default_branch: string;
    private: boolean;
    ssh_url: string;
    https_url: string;
    web_url: string | null;
}

/** GET /source-control/connections/{id}/branches?repository= */
export interface BranchOption {
    name: string;
    sha: string | null;
    protected: boolean;
}

export const AUTH_LABELS: Record<AuthType, string> = {
    oauth: 'OAuth',
    app: 'GitHub App',
    token: 'Access token',
    basic: 'App password',
    none: 'Deploy key only',
};
