import {
    AppShell,
    Button,
    Callout,
    ConfirmDestructive,
    CopyButton,
    EmptyState,
    Field,
    Input,
    ManagedBadge,
    PageHeader,
    RelativeTime,
    Section,
    Select,
    StatusBadge,
    Switch,
    Tag,
    Textarea,
} from '@/components/falak';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Check, ExternalLink, GitFork, GitPullRequest, RotateCw, Trash2 } from 'lucide-react';
import { useState, type FormEventHandler } from 'react';
import {
    DATABASE_STRATEGIES,
    SERVICE_MODES,
    STATUS_LABELS,
    type DatabaseSettings,
    type PreviewRow,
    type PreviewSettingsForm,
    type ServiceMode,
} from '../types';

interface Props {
    project: { id: string; name: string };
    previews: PreviewRow[];
    settings: PreviewSettingsForm;
    environments: { id: string; name: string; is_production: boolean }[];
    services: { name: string; kind: 'site' | 'database' }[];
    servers: { id: string; name: string }[];
    preview_domain: { domain: string; status: string } | null;
    can: { manage: boolean };
}

/** /projects/{p}/previews: a preview per pull request, and the project's preview settings. */
export default function Index({ project, previews, settings, environments, services, servers, preview_domain, can }: Props) {
    const [deleting, setDeleting] = useState<PreviewRow | null>(null);
    const open = previews.filter((preview) => preview.status !== 'closed');
    const closed = previews.filter((preview) => preview.status === 'closed');

    return (
        <AppShell
            breadcrumbs={[
                { title: project.name, href: `/projects/${project.id}` },
                { title: 'Previews', href: `/projects/${project.id}/previews` },
            ]}
        >
            <Head title={`Previews · ${project.name}`} />
            <div className="mx-auto grid w-full max-w-5xl gap-6">
                <div className="grid gap-3">
                    <Link href={`/projects/${project.id}`} className="text-fg-muted hover:text-fg flex w-fit items-center gap-1 text-xs">
                        <ArrowLeft className="size-3.5" aria-hidden /> Canvas
                    </Link>
                    <PageHeader title="Previews" description={`A preview environment for every pull request of ${project.name}`} />
                </div>

                {!preview_domain && (
                    <Callout tone="warning" title="No preview domain">
                        This Falak has no preview domain yet: its operator sets one in <Link href="/settings/previews">Settings → Previews</Link>.
                    </Callout>
                )}

                {open.length === 0 ? (
                    <EmptyState
                        icon={<GitPullRequest />}
                        title="No open previews"
                        description={
                            settings.enabled
                                ? 'Open a pull request on a repository the base environment deploys: its preview shows up here.'
                                : 'Turn previews on below to get an environment per pull request.'
                        }
                    />
                ) : (
                    <div className="grid gap-3" data-testid="previews">
                        {open.map((preview) => (
                            <PreviewCard key={preview.id} preview={preview} canManage={can.manage} onDelete={() => setDeleting(preview)} />
                        ))}
                    </div>
                )}

                {closed.length > 0 && (
                    <Section title="Closed" description="Previews whose pull request was merged or closed, or that idled out." bare>
                        <ul className="divide-border border-border bg-surface-1 divide-y rounded-lg border text-sm">
                            {closed.map((preview) => (
                                <li key={preview.id} className="flex items-center gap-3 px-4 py-2.5">
                                    <span className="text-fg font-mono text-xs">#{preview.number}</span>
                                    <span className="text-fg-muted min-w-0 flex-1 truncate">{preview.title}</span>
                                    <span className="text-fg-faint hidden text-xs sm:inline">{preview.status_message}</span>
                                    <RelativeTime value={preview.closed_at} className="text-fg-faint text-xs" />
                                </li>
                            ))}
                        </ul>
                    </Section>
                )}

                <SettingsForm
                    project={project}
                    settings={settings}
                    environments={environments}
                    services={services}
                    servers={servers}
                    canManage={can.manage}
                />
            </div>

            {deleting && (
                <ConfirmDestructive
                    open
                    onOpenChange={(value) => !value && setDeleting(null)}
                    title={`Delete the preview of #${deleting.number}?`}
                    description="Its sites, databases, volumes and DNS records are deleted. A new push to the pull request starts it again."
                    confirmText={`#${deleting.number}`}
                    confirmLabel="Delete preview"
                    onConfirm={() => router.delete(`/previews/${deleting.id}`, { preserveScroll: true, onFinish: () => setDeleting(null) })}
                />
            )}
        </AppShell>
    );
}

function PreviewCard({ preview, canManage, onDelete }: { preview: PreviewRow; canManage: boolean; onDelete: () => void }) {
    // An approval names the head the member reviewed: refused when new commits arrived since.
    const post = (action: 'approve' | 'redeploy') =>
        router.post(`/previews/${preview.id}/${action}`, action === 'approve' ? { sha: preview.head_sha } : {}, { preserveScroll: true });
    const urls = Object.entries(preview.urls);

    return (
        <div className="border-border bg-surface-1 grid gap-3 rounded-lg border p-4" data-testid={`preview-${preview.number}`}>
            <div className="flex flex-wrap items-center gap-2">
                <a href={preview.url ?? '#'} target="_blank" rel="noreferrer" className="text-fg flex min-w-0 items-center gap-2 text-sm font-medium">
                    <span className="font-mono">#{preview.number}</span>
                    <span className="truncate">{preview.title}</span>
                </a>
                {preview.is_fork && (
                    <Tag tone="warning" icon={<GitFork />}>
                        fork
                    </Tag>
                )}
                <StatusBadge status={preview.status} label={STATUS_LABELS[preview.status] ?? preview.status} />
                <div className="ml-auto flex items-center gap-1.5">
                    {canManage && preview.status === 'waiting_approval' && (
                        <Button size="sm" variant="primary" icon={<Check />} onClick={() => post('approve')}>
                            Approve
                        </Button>
                    )}
                    {canManage && ['ready', 'failed', 'deploying'].includes(preview.status) && (
                        <Button size="sm" icon={<RotateCw />} onClick={() => post('redeploy')}>
                            Redeploy
                        </Button>
                    )}
                    {canManage && (
                        <Button
                            size="sm"
                            variant="ghost"
                            icon={<Trash2 />}
                            onClick={onDelete}
                            aria-label={`Delete the preview of #${preview.number}`}
                        >
                            Delete
                        </Button>
                    )}
                </div>
            </div>

            <p className="text-fg-muted text-xs">
                <span className="font-mono">{preview.head_branch}</span> @ <span className="font-mono">{preview.head_sha.slice(0, 7)}</span>
                {preview.author && <> by {preview.author}</>} · opened <RelativeTime value={preview.created_at} /> · last activity{' '}
                <RelativeTime value={preview.last_activity_at} />
            </p>

            {preview.status_message && <p className="text-fg-muted text-xs">{preview.status_message}</p>}
            {preview.is_fork && preview.status === 'waiting_approval' && (
                <p className="text-fg-muted text-xs">
                    Pull requests from forks never deploy on their own and never get secrets. Approve each new commit here, or comment{' '}
                    <code>/falak preview</code> on the pull request from a provider account connected in Falak.
                </p>
            )}

            {urls.length > 0 && (
                <ul className="grid gap-1 text-sm">
                    {urls.map(([service, url]) => (
                        <li key={service} className="flex items-center gap-2">
                            <span className="text-fg-muted w-28 truncate text-xs">{service}</span>
                            <a href={url} target="_blank" rel="noreferrer" className="text-primary flex items-center gap-1 font-mono text-xs">
                                {url.replace('https://', '')} <ExternalLink className="size-3" aria-hidden />
                            </a>
                        </li>
                    ))}
                </ul>
            )}

            {preview.credentials && (
                <div className="bg-surface-2 flex flex-wrap items-center gap-x-4 gap-y-1 rounded-md px-3 py-2 text-xs">
                    <span className="text-fg-muted">Basic auth</span>
                    <span className="font-mono">{preview.credentials.username}</span>
                    <span className="flex items-center gap-1 font-mono">
                        {preview.credentials.password}
                        <CopyButton value={preview.credentials.password ?? ''} label="Copy password" />
                    </span>
                </div>
            )}

            {Object.keys(preview.databases).length > 0 && (
                <p className="text-fg-faint text-xs">
                    Databases:{' '}
                    {Object.entries(preview.databases)
                        .map(
                            ([name, db]) =>
                                `${name} (${DATABASE_STRATEGIES.find((s) => s.value === db.strategy)?.label ?? db.strategy}, ${db.state})`,
                        )
                        .join(', ')}
                </p>
            )}
        </div>
    );
}

function SettingsForm({
    project,
    settings,
    environments,
    services,
    servers,
    canManage,
}: Pick<Props, 'project' | 'settings' | 'environments' | 'services' | 'servers'> & { canManage: boolean }) {
    const form = useForm<PreviewSettingsForm>(settings);
    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.put(`/projects/${project.id}/previews/settings`, { preserveScroll: true });
    };
    const mode = (name: string): ServiceMode => form.data.services[name] ?? 'include';
    const database = (name: string): DatabaseSettings => form.data.databases[name] ?? { strategy: 'empty' };
    const setDatabase = (name: string, change: Partial<DatabaseSettings>) =>
        form.setData('databases', { ...form.data.databases, [name]: { ...database(name), ...change } });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <form onSubmit={submit}>
            <Section
                title="Settings"
                description="Each pull request forks the base environment: the services you include run on the preview server under the preview domain."
                aside={<ManagedBadge config={settings.managed} />}
                footer={
                    canManage &&
                    !settings.managed && (
                        <Button type="submit" variant="primary" loading={form.processing}>
                            Save settings
                        </Button>
                    )
                }
            >
                {/* A managed block locks the whole form (it saves every field at once); the server refuses managed changes. */}
                <fieldset disabled={!canManage || !!settings.managed} className="grid gap-4">
                    <Field label="Previews for pull requests" inline>
                        <Switch checked={form.data.enabled} onCheckedChange={(value) => form.setData('enabled', value)} />
                    </Field>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Base environment" error={errors.base_environment_id} hint="Usually staging. Previews copy its services.">
                            <Select
                                value={form.data.base_environment_id ?? undefined}
                                onValueChange={(value) => form.setData('base_environment_id', value)}
                                options={environments.map((e) => ({ value: e.id, label: e.name }))}
                            />
                        </Field>
                        <Field label="Server" error={errors.server_id} hint="Default: the base environment's server.">
                            <Select
                                value={form.data.server_id ?? '_'}
                                onValueChange={(value) => form.setData('server_id', value === '_' ? null : value)}
                                options={[{ value: '_', label: 'Base environment server' }, ...servers.map((s) => ({ value: s.id, label: s.name }))]}
                            />
                        </Field>
                        <Field
                            label="Fork server"
                            error={errors.fork_server_id}
                            hint="Pull requests from forks run only here: a server with nothing but previews, never the preview edge. Docker and Compose services only."
                        >
                            <Select
                                value={form.data.fork_server_id ?? '_'}
                                onValueChange={(value) => form.setData('fork_server_id', value === '_' ? null : value)}
                                options={[
                                    { value: '_', label: 'None (forks are not previewed)' },
                                    ...servers.map((s) => ({ value: s.id, label: s.name })),
                                ]}
                            />
                        </Field>
                        <Field
                            label="Variables previews copy"
                            error={errors.variables}
                            hint="Names, comma separated. Secret values never copy; your own pull requests also keep ${{ }} references."
                        >
                            <Input
                                mono
                                value={form.data.variables.join(', ')}
                                onChange={(e) =>
                                    form.setData(
                                        'variables',
                                        e.target.value
                                            .split(',')
                                            .map((name) => name.trim())
                                            .filter(Boolean),
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Domain pattern"
                            error={errors.domain_pattern}
                            hint="Under the preview domain. Placeholders: {number}, {service}, {project}."
                        >
                            <Input mono value={form.data.domain_pattern} onChange={(e) => form.setData('domain_pattern', e.target.value)} />
                        </Field>
                        <Field label="Access" error={errors.access}>
                            <Select
                                value={form.data.access}
                                onValueChange={(value) => form.setData('access', value as 'basic' | 'public')}
                                options={[
                                    { value: 'basic', label: 'Basic auth (credentials shown here only)' },
                                    { value: 'public', label: 'Public' },
                                ]}
                            />
                        </Field>
                        <Field label="Concurrent previews" error={errors.max_concurrent} hint="More pull requests wait until one closes.">
                            <Input
                                type="number"
                                min={1}
                                max={50}
                                value={form.data.max_concurrent}
                                onChange={(e) => form.setData('max_concurrent', Number(e.target.value))}
                            />
                        </Field>
                        <Field
                            label="Idle time to live (hours)"
                            error={errors.idle_ttl_hours}
                            hint="Previews without a push for this long are deleted."
                        >
                            <Input
                                type="number"
                                min={1}
                                max={720}
                                value={form.data.idle_ttl_hours}
                                onChange={(e) => form.setData('idle_ttl_hours', Number(e.target.value))}
                            />
                        </Field>
                    </div>

                    {services.length > 0 && (
                        <div className="grid gap-2">
                            <p className="text-fg text-sm font-medium">Services</p>
                            {services.map((service) => (
                                <div key={service.name} className="border-border grid gap-2 rounded-md border p-3">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="text-fg text-sm">{service.name}</span>
                                        <Tag>{service.kind}</Tag>
                                        <Select
                                            size="sm"
                                            className="ml-auto w-56"
                                            aria-label={`${service.name} in previews`}
                                            value={mode(service.name)}
                                            onValueChange={(value) =>
                                                form.setData('services', { ...form.data.services, [service.name]: value as ServiceMode })
                                            }
                                            options={SERVICE_MODES}
                                        />
                                    </div>
                                    {service.kind === 'database' && mode(service.name) === 'include' && (
                                        <DatabaseFields
                                            name={service.name}
                                            value={database(service.name)}
                                            environments={environments}
                                            onChange={(change) => setDatabase(service.name, change)}
                                            errors={errors}
                                        />
                                    )}
                                </div>
                            ))}
                            {services.some((s) => s.kind === 'database' && mode(s.name) === 'share') && (
                                <Field label="Previews use the base environment's database" error={errors.acknowledge_shared_database} inline>
                                    <Switch
                                        checked={form.data.acknowledge_shared_database}
                                        onCheckedChange={(value) => form.setData('acknowledge_shared_database', value)}
                                    />
                                </Field>
                            )}
                            <p className="text-fg-faint text-xs">
                                Redis and Valkey always start empty. Only secrets marked “available to previews” reach previews; pull requests from
                                forks get none.
                            </p>
                        </div>
                    )}
                </fieldset>
            </Section>
        </form>
    );
}

function DatabaseFields({
    name,
    value,
    environments,
    onChange,
    errors,
}: {
    name: string;
    value: DatabaseSettings;
    environments: Props['environments'];
    onChange: (change: Partial<DatabaseSettings>) => void;
    errors: Record<string, string | undefined>;
}) {
    return (
        <div className="grid gap-3 sm:grid-cols-2">
            <Field label="Data" error={errors[`databases.${name}.strategy`]}>
                <Select value={value.strategy} onValueChange={(strategy) => onChange({ strategy })} options={DATABASE_STRATEGIES} />
            </Field>
            {value.strategy !== 'empty' && (
                <Field label="From" hint={value.strategy === 'clone_sanitize' ? 'Default: production' : 'Default: the base environment'}>
                    <Select
                        value={value.source_environment_id ?? '_'}
                        onValueChange={(id) => onChange({ source_environment_id: id === '_' ? null : id })}
                        options={[{ value: '_', label: 'Default' }, ...environments.map((e) => ({ value: e.id, label: e.name }))]}
                    />
                </Field>
            )}
            {value.strategy === 'clone_backup' && (
                <Field
                    label="Unsanitized production data"
                    inline
                    error={errors[`databases.${name}.acknowledge_production`]}
                    hint="Needed when this copies a production backup: previews would hold it as is. Forks always start empty."
                >
                    <Switch
                        checked={Boolean(value.acknowledge_production)}
                        onCheckedChange={(checked) => onChange({ acknowledge_production: checked })}
                    />
                </Field>
            )}
            {value.strategy === 'clone_sanitize' && (
                <>
                    <Field label="Sanitize with" error={errors[`databases.${name}.sanitize_kind`]}>
                        <Select
                            value={value.sanitize_kind ?? 'sql'}
                            onValueChange={(kind) => onChange({ sanitize_kind: kind })}
                            options={[
                                { value: 'sql', label: 'SQL script' },
                                { value: 'command', label: 'Shell command (in the database container)' },
                            ]}
                        />
                    </Field>
                    <Field
                        label="Sanitize script"
                        className="sm:col-span-2"
                        error={errors[`databases.${name}.sanitize_script`]}
                        hint="Runs after the restore, before the preview starts. If it fails, the preview does not start and the restored data is deleted."
                    >
                        <Textarea mono rows={5} value={value.sanitize_script ?? ''} onChange={(e) => onChange({ sanitize_script: e.target.value })} />
                    </Field>
                </>
            )}
        </div>
    );
}
