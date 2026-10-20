import { Button } from '@/components/falak/button';
import { Callout } from '@/components/falak/callout';
import { ConfirmDestructive } from '@/components/falak/confirm-destructive';
import { DataTable } from '@/components/falak/data-table';
import { Dialog } from '@/components/falak/dialog';
import { EmptyState } from '@/components/falak/empty-state';
import { Field } from '@/components/falak/field';
import { Input } from '@/components/falak/input';
import { RelativeTime } from '@/components/falak/relative-time';
import { SecretInput } from '@/components/falak/secret-input';
import { Section } from '@/components/falak/section';
import { Tag } from '@/components/falak/tag';
import SettingsLayout from '@/layouts/settings/layout';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { useForm, usePage } from '@inertiajs/react';
import { FolderGit2, GitBranch, GitCommitHorizontal, Unplug } from 'lucide-react';
import { useEffect, useState, type FormEventHandler } from 'react';
import { GitHubAppCard, withReturn } from '../components/github-app-card';
import { ProviderIcon } from '../components/provider-icon';
import { RepositoryBrowser } from '../components/repository-browser';
import { AUTH_LABELS, type ConnectionRow, type GitHubAppState, type ProviderOption, type ProviderValue, type PushRow } from '../types';

interface Props {
    connections: ConnectionRow[];
    pushes: PushRow[];
    providers: ProviderOption[];
    githubApp: GitHubAppState;
    canManage: boolean;
}

type ManualAuth = 'token' | 'basic' | 'none';

interface ConnectForm {
    provider: ProviderValue;
    auth_type: ManualAuth;
    name: string;
    base_url: string;
    token: string;
    username: string;
    password: string;
}

const MANUAL_AUTH: Record<ProviderValue, ManualAuth[]> = {
    github: ['token'],
    gitlab: ['token'],
    bitbucket: ['basic', 'token'],
    custom: ['none'],
};

const TOKEN_HINTS: Record<ProviderValue, string> = {
    github: 'Fine-grained or classic token with repository contents, deploy keys and webhooks access.',
    gitlab: 'Personal, group or project access token with the api scope.',
    bitbucket: 'Repository or workspace access token with repository and webhook scopes.',
    custom: '',
};

const DESCRIPTIONS: Record<ProviderValue, string> = {
    github: 'GitHub Enterprise Server, or a token-based connection instead of the GitHub App.',
    gitlab: 'GitLab.com or self-managed, via the API.',
    bitbucket: 'Bitbucket Cloud workspaces via the API.',
    custom: 'Any SSH-reachable git server, with a deploy key per site.',
};

const BASE_URL: Record<ProviderValue, { label: string; placeholder: string } | null> = {
    github: { label: 'GitHub Enterprise URL', placeholder: 'https://github.example.com' },
    gitlab: { label: 'Self-managed URL', placeholder: 'https://gitlab.example.com' },
    bitbucket: null,
    custom: { label: 'Server URL', placeholder: 'ssh://git@git.example.com' },
};

function ProviderTile({ option, onManual }: { option: ProviderOption; onManual: () => void }) {
    const github = option.value === 'github';

    return (
        <div className="border-border bg-surface-1 flex flex-col gap-3 rounded-lg border p-4" data-testid={`provider-${option.value}`}>
            <div className="flex items-center gap-2.5">
                <span className="border-border bg-surface-2 text-fg flex size-8 items-center justify-center rounded-md border">
                    <ProviderIcon provider={option.value} className="size-4" />
                </span>
                <span className="text-fg text-sm font-medium">{github ? 'GitHub (token or OAuth)' : option.label}</span>
            </div>
            <p className="text-fg-muted flex-1 text-xs">{DESCRIPTIONS[option.value]}</p>
            <div className="flex flex-wrap gap-1.5">
                {option.oauth && (
                    <Button asChild size="sm" variant={github ? 'secondary' : 'primary'}>
                        <a href={route('source-control.connect', option.value)}>{github ? 'Connect with OAuth' : 'Connect'}</a>
                    </Button>
                )}
                <Button size="sm" variant={option.oauth || github ? 'ghost' : 'secondary'} onClick={onManual}>
                    {github ? 'Use a personal access token instead' : option.has_api ? 'Use a token' : 'Add server'}
                </Button>
            </div>
        </div>
    );
}

/** `?connect=github&return_to=/path` (the create-service flow's "Connect GitHub"): continue straight to GitHub when possible. */
function useConnectIntent(githubApp: GitHubAppState, canManage: boolean): string | null {
    const [returnTo] = useState(() => {
        const params = new URLSearchParams(window.location.search);
        const path = params.get('return_to');

        return path && path.startsWith('/') && !path.startsWith('//') ? path : null;
    });

    useEffect(() => {
        const params = new URLSearchParams(window.location.search);

        if (params.get('connect') !== 'github' || !canManage) return;

        // Several apps: the user picks which one (they are installable on their own owner only).
        const [app, ...more] = githubApp.apps;
        if (app?.installable && more.length === 0) {
            window.location.assign(withReturn(app.install_url, returnTo));
        } else {
            document.querySelector('[data-testid="github-app"]')?.scrollIntoView({ block: 'center' });
        }
    }, [githubApp, canManage, returnTo]);

    return returnTo;
}

export default function Index({ connections, pushes, providers, githubApp, canManage }: Props) {
    const { errors } = usePage<SharedData & { errors: Record<string, string | undefined> }>().props;
    const returnTo = useConnectIntent(githubApp, canManage);
    const [connecting, setConnecting] = useState<ProviderOption | null>(null);
    const [deleting, setDeleting] = useState<ConnectionRow | null>(null);
    const [browsing, setBrowsing] = useState<ConnectionRow | null>(null);
    const form = useForm<ConnectForm>({ provider: 'github', auth_type: 'token', name: '', base_url: '', token: '', username: '', password: '' });
    const removal = useForm({ name: '' });

    const openConnect = (option: ProviderOption) => {
        form.clearErrors();
        form.setData({
            provider: option.value,
            auth_type: MANUAL_AUTH[option.value][0],
            name: '',
            base_url: '',
            token: '',
            username: '',
            password: '',
        });
        setConnecting(option);
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(route('source-control.connections.store'), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setConnecting(null);
            },
        });
    };

    const disconnect = (name: string) =>
        new Promise<void>((resolve) => {
            if (!deleting) return resolve();
            removal.transform(() => ({ name }));
            removal.delete(route('source-control.connections.destroy', deleting.id), {
                preserveScroll: true,
                onSuccess: () => setDeleting(null),
                onFinish: () => resolve(),
            });
        });

    const connectionName = (id: string) => connections.find((connection) => connection.id === id)?.name ?? '—';
    const connectionById = (id: string) => connections.find((connection) => connection.id === id) ?? null;
    const others = connections.filter((connection) => connection.auth_type !== 'app');
    const provider = form.data.provider;
    const baseUrl = BASE_URL[provider];

    const tiles = (
        <div className="grid gap-3 sm:grid-cols-2">
            {providers.map((option) => (
                <ProviderTile key={option.value} option={option} onManual={() => openConnect(option)} />
            ))}
        </div>
    );

    return (
        <SettingsLayout
            title="Source control"
            description="Git providers Falak deploys from. GitHub connects through a GitHub App; other providers get a deploy key and a push webhook per site."
            wide
        >
            {(errors.oauth ?? errors.github_app) && (
                <Callout tone="danger" title="The provider did not connect">
                    {errors.oauth ?? errors.github_app}
                </Callout>
            )}

            <Section
                title="GitHub"
                description="The GitHub App gets read-only access to the repositories you pick, and sends push events for push-to-deploy."
                bare
            >
                <GitHubAppCard
                    state={githubApp}
                    canManage={canManage}
                    returnTo={returnTo}
                    onBrowse={(installation) => setBrowsing(connectionById(installation.id))}
                    onDisconnect={(installation) => setDeleting(connectionById(installation.id))}
                />
            </Section>

            <Section title="Other connections" description="GitLab, Bitbucket, git servers and token-based GitHub accounts." bare>
                {others.length === 0 ? (
                    <EmptyState
                        size="sm"
                        icon={<GitBranch />}
                        title="No other connections"
                        description={
                            canManage
                                ? 'Add GitLab, Bitbucket or a git server below. For GitHub, prefer the app above.'
                                : 'Ask an admin of this organization to connect GitLab, Bitbucket or a git server.'
                        }
                    />
                ) : (
                    <DataTable
                        label="Git connections"
                        rows={others}
                        rowKey={(connection) => connection.id}
                        columns={[
                            {
                                id: 'name',
                                header: 'Name',
                                sortValue: (connection) => connection.name,
                                cell: (connection) => (
                                    <span className="flex min-w-0 items-center gap-2.5 py-1.5">
                                        <span className="border-border bg-surface-2 text-fg flex size-6 shrink-0 items-center justify-center rounded-md border">
                                            <ProviderIcon provider={connection.provider} className="size-3.5" />
                                        </span>
                                        <span className="grid min-w-0">
                                            <span className="truncate font-medium">{connection.name}</span>
                                            <span className="text-fg-faint truncate text-xs">
                                                {connection.provider_label}
                                                {connection.base_url ? ` · ${connection.base_url}` : ''}
                                            </span>
                                        </span>
                                    </span>
                                ),
                            },
                            {
                                id: 'account',
                                header: 'Account',
                                hideOnMobile: true,
                                cell: (connection) => <span className="text-fg-muted font-mono text-xs">{connection.account ?? '—'}</span>,
                            },
                            {
                                id: 'auth',
                                header: 'Auth',
                                hideOnMobile: true,
                                cell: (connection) => <Tag>{AUTH_LABELS[connection.auth_type]}</Tag>,
                            },
                            {
                                id: 'usage',
                                header: 'Keys · hooks',
                                align: 'right',
                                hideOnMobile: true,
                                cell: (connection) => (
                                    <span className="text-fg-muted">
                                        {connection.deploy_keys_count} · {connection.webhooks_count}
                                    </span>
                                ),
                            },
                            {
                                id: 'created',
                                header: 'Connected',
                                hideOnMobile: true,
                                sortValue: (connection) => connection.created_at,
                                cell: (connection) => <RelativeTime value={connection.created_at} className="text-fg-muted" />,
                            },
                        ]}
                        rowActions={(connection) => [
                            ...(connection.provider !== 'custom'
                                ? [{ label: 'Browse repositories', icon: <FolderGit2 />, onSelect: () => setBrowsing(connection) }]
                                : []),
                            ...(canManage
                                ? [
                                      ...(connection.provider !== 'custom' ? [{ type: 'separator' as const }] : []),
                                      { label: 'Disconnect', icon: <Unplug />, danger: true, onSelect: () => setDeleting(connection) },
                                  ]
                                : []),
                        ]}
                    />
                )}
            </Section>

            {canManage && (
                <Section
                    title="Add a connection"
                    description="Credentials are verified with the provider, then stored encrypted and never shown again."
                    bare
                >
                    {tiles}
                </Section>
            )}

            <Section
                title="Recent pushes"
                description="The last 20 push webhooks received. Each one can trigger push-to-deploy on matching sites."
                bare
            >
                <DataTable
                    label="Recent pushes"
                    rows={pushes}
                    rowKey={(push) => push.id}
                    empty={{
                        icon: <GitCommitHorizontal />,
                        title: 'No pushes received yet',
                        description: 'Push to a branch of a connected repository that a site deploys from, and it shows up here within seconds.',
                        size: 'sm',
                    }}
                    columns={[
                        {
                            id: 'commit',
                            header: 'Commit',
                            cell: (push) => (
                                <span className="flex min-w-0 items-center gap-2">
                                    {push.url ? (
                                        <a
                                            href={push.url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="text-primary shrink-0 font-mono text-xs hover:underline"
                                        >
                                            {push.sha.slice(0, 7)}
                                        </a>
                                    ) : (
                                        <span className="shrink-0 font-mono text-xs">{push.sha.slice(0, 7)}</span>
                                    )}
                                    <span className="max-w-[16rem] truncate sm:max-w-md">{push.message}</span>
                                </span>
                            ),
                        },
                        {
                            id: 'repository',
                            header: 'Repository',
                            hideOnMobile: true,
                            cell: (push) => (
                                <span className="flex items-center gap-1.5">
                                    <span className="text-fg-muted font-mono text-xs">{push.repository}</span>
                                    <Tag mono icon={<GitBranch />}>
                                        {push.branch}
                                    </Tag>
                                </span>
                            ),
                        },
                        {
                            id: 'author',
                            header: 'Author',
                            hideOnMobile: true,
                            cell: (push) => <span className="text-fg-muted">{push.author ?? push.pusher ?? '—'}</span>,
                        },
                        {
                            id: 'connection',
                            header: 'Connection',
                            hideOnMobile: true,
                            cell: (push) => <span className="text-fg-muted">{connectionName(push.connection_id)}</span>,
                        },
                        {
                            id: 'received',
                            header: 'Received',
                            align: 'right',
                            sortValue: (push) => push.received_at,
                            cell: (push) => <RelativeTime value={push.received_at} className="text-fg-muted" />,
                        },
                    ]}
                />
            </Section>

            <Dialog
                open={connecting !== null}
                onOpenChange={(open) => !open && setConnecting(null)}
                title={`Connect ${connecting?.label ?? ''}`}
                description={
                    provider === 'custom'
                        ? 'Falak generates a deploy key per site; add it to your git server and point its push webhook at the URL shown on the site.'
                        : 'The credentials are checked with the provider before anything is saved.'
                }
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setConnecting(null)}>
                            Cancel
                        </Button>
                        <Button variant="primary" type="submit" form="connect-form" loading={form.processing}>
                            {provider === 'custom' ? 'Add server' : 'Verify and connect'}
                        </Button>
                    </>
                }
            >
                <form id="connect-form" onSubmit={submit} className="grid gap-4">
                    {MANUAL_AUTH[provider].length > 1 && (
                        <div className="bg-surface-2 grid grid-cols-2 gap-1 rounded-md p-1" role="radiogroup" aria-label="Authentication">
                            {MANUAL_AUTH[provider].map((auth) => (
                                <button
                                    key={auth}
                                    type="button"
                                    role="radio"
                                    aria-checked={form.data.auth_type === auth}
                                    onClick={() => form.setData('auth_type', auth)}
                                    className={cn(
                                        'h-7 rounded-sm text-xs font-medium transition-colors',
                                        form.data.auth_type === auth ? 'bg-surface-1 text-fg shadow-sm' : 'text-fg-muted hover:text-fg',
                                    )}
                                >
                                    {AUTH_LABELS[auth]}
                                </button>
                            ))}
                        </div>
                    )}

                    <Field label="Name" hint="Optional — defaults to the account name." error={form.errors.name}>
                        <Input
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                            placeholder={provider === 'custom' ? 'Internal git' : `${connecting?.label ?? ''} (account)`}
                        />
                    </Field>

                    {baseUrl && (
                        <Field label={baseUrl.label} hint="Optional — leave empty for the hosted service." error={form.errors.base_url}>
                            <Input
                                mono
                                value={form.data.base_url}
                                onChange={(event) => form.setData('base_url', event.target.value)}
                                placeholder={baseUrl.placeholder}
                            />
                        </Field>
                    )}

                    {form.data.auth_type === 'token' && (
                        <Field label="Access token" hint={TOKEN_HINTS[provider]} error={form.errors.token} required>
                            <SecretInput value={form.data.token} onChange={(value) => form.setData('token', value)} />
                        </Field>
                    )}

                    {form.data.auth_type === 'basic' && (
                        <div className="grid items-start gap-4 sm:grid-cols-2">
                            <Field label="Username" error={form.errors.username} required>
                                <Input value={form.data.username} onChange={(event) => form.setData('username', event.target.value)} />
                            </Field>
                            <Field label="App password" error={form.errors.password} required>
                                <SecretInput value={form.data.password} onChange={(value) => form.setData('password', value)} />
                            </Field>
                        </div>
                    )}

                    {(errors.credentials ?? form.errors.auth_type ?? form.errors.provider) && (
                        <Callout tone="danger">{errors.credentials ?? form.errors.auth_type ?? form.errors.provider}</Callout>
                    )}
                </form>
            </Dialog>

            <ConfirmDestructive
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title={`Disconnect ${deleting?.name ?? ''}`}
                description={
                    deleting?.auth_type === 'app'
                        ? "Falak uninstalls the GitHub App from this account. Sites using it can't deploy until they point at another connection."
                        : `Its ${deleting?.deploy_keys_count ?? 0} deploy key(s) and ${deleting?.webhooks_count ?? 0} webhook(s) are removed from the provider. Sites using it can't deploy until they point at another connection.`
                }
                confirmText={deleting?.name ?? ''}
                confirmLabel="Disconnect"
                onConfirm={disconnect}
                processing={removal.processing}
                error={removal.errors.name}
            />

            <RepositoryBrowser connection={browsing} onClose={() => setBrowsing(null)} />
        </SettingsLayout>
    );
}
