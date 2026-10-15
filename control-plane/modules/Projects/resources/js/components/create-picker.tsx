import { ComposeProject, composeProjectPayload, emptyComposeProject, type ComposeProjectValue } from '@/components/compose-project';
import { domainPayload, DomainPicker, type DomainChoice } from '@/components/domain-picker';
import {
    Button,
    Checkbox,
    Combobox,
    Field,
    IconButton,
    Input,
    PickItem,
    PickList,
    Segmented,
    Select,
    ServiceIcon,
    Skeleton,
    StepBackProvider,
    Tag,
    toast,
    useStepBack,
} from '@/components/falak';
import { useJson } from '@/hooks/use-json';
import { errorMessage, HttpError, requestJson } from '@/lib/http';
import { createOptionsFor, shellContext, type CreateOption } from '@/lib/registry';
import { cn } from '@/lib/utils';
import { type CanvasService, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { ArrowLeft, Box, ChevronRight, Database, GitBranch, LayoutTemplate, Lock, Rocket, X } from 'lucide-react';
import { Suspense, useCallback, useEffect, useMemo, useState, type ReactNode } from 'react';
import { type ProjectAbilities } from '../types';

/** Sites' GET /sites/create JSON (the create form options). */
interface SiteOptions {
    options: {
        frameworks: { value: string; label: string; runtimes: string[]; is_php: boolean }[];
        servers: { id: string; name: string; type_label: string; status: string; docker: boolean }[];
        connections: { id: string; name: string; provider: string; provider_label: string; has_api: boolean }[];
    };
    can_manage_source_control: boolean;
}

interface RepositoryItem {
    full_name: string;
    default_branch: string;
    private: boolean;
}

/** Databases' GET /databases JSON `options`: engines with their versions and defaults, and the servers. */
interface DatabaseCreateOptions {
    engines: {
        value: string;
        versions: string[];
        default_version: string;
        default_memory_mb: number;
        min_memory_mb: number;
        default_disk_gb: number;
    }[];
    servers: { id: string; name: string }[];
}

type BuiltinStep = 'root' | 'git' | 'docker' | 'empty' | 'database';
/** Built-in kinds, or `option:<id>` for a kind another module registered (registerCreateOptions). */
type Step = BuiltinStep | `option:${string}`;

const TITLES: Record<BuiltinStep, string> = {
    root: 'Create',
    git: 'Deploy a Git repository',
    docker: 'Deploy a Docker image',
    empty: 'Empty service',
    database: 'Add a database',
};

export interface CreatePickerProps {
    projectId: string;
    environmentSlug: string;
    can: ProjectAbilities;
    /** Canvas coordinates for the new card (right-click position); auto-placed when null. */
    position: { x: number; y: number } | null;
    onClose: () => void;
    /** The new card, and the first deployment when one was started. */
    onCreated: (service: CanvasService, deploymentId: string | null) => void;
    /** Open a registered option right away (e.g. ⌘K → Deploy template…). */
    initialOption?: string | null;
}

/** Guess the framework preset from the repository name (the user can change it before deploying). */
export function detectPreset(repository: string, frameworks: string[]): string {
    const name = repository.toLowerCase();
    const rules: [RegExp, string][] = [
        [/next/, 'next'],
        [/nuxt/, 'nuxt'],
        [/wordpress|(^|[/-])wp([-.]|$)/, 'wordpress'],
        [/statamic/, 'statamic'],
        [/symfony/, 'symfony'],
        [/docs|static|landing|hugo|astro|jekyll/, 'static'],
        [/docker|compose/, 'docker'],
        [/bun|node|express|worker|bot/, 'node'],
    ];
    const hit = rules.find(([pattern, preset]) => pattern.test(name) && frameworks.includes(preset));

    return hit?.[1] ?? (frameworks.includes('laravel') ? 'laravel' : (frameworks[0] ?? 'laravel'));
}

const ENGINES = [
    { value: 'postgresql', label: 'PostgreSQL' },
    { value: 'mysql', label: 'MySQL' },
    { value: 'mariadb', label: 'MariaDB' },
    { value: 'redis', label: 'Redis' },
    { value: 'valkey', label: 'Valkey' },
] as const;

/** Redis / Valkey: an instance (own process, port and password) rather than a database in a shared engine. */
const KEY_VALUE_ENGINES: readonly string[] = ['redis', 'valkey'];

const EVICTIONS = [
    { value: 'noeviction', label: 'noeviction — refuse writes when full (queues, sessions)' },
    { value: 'allkeys-lru', label: 'allkeys-lru — evict least recently used (caches)' },
    { value: 'allkeys-lfu', label: 'allkeys-lfu — evict least frequently used' },
    { value: 'volatile-lru', label: 'volatile-lru — evict keys with a TTL, LRU' },
    { value: 'volatile-ttl', label: 'volatile-ttl — evict keys closest to expiry' },
];

function slugName(value: string): string {
    return (
        (value.split('/').pop() ?? value)
            .replace(/\.git$/, '')
            .replace(/[^A-Za-z0-9 ._-]+/g, '-')
            .replace(/^[^A-Za-z0-9]+/, '') || 'service'
    );
}

function Errors({ errors }: { errors: string[] }) {
    if (errors.length === 0) return null;

    return (
        <div role="alert" className="bg-danger-soft text-danger grid gap-0.5 rounded-md px-3 py-2 text-xs">
            {errors.map((message) => (
                <span key={message}>{message}</span>
            ))}
        </div>
    );
}

function ServersField({
    servers,
    value,
    onChange,
    error,
    requireDocker = false,
}: {
    servers: SiteOptions['options']['servers'];
    value: string[];
    onChange: (ids: string[]) => void;
    error?: string;
    requireDocker?: boolean;
}) {
    const eligible = servers.filter((server) => !requireDocker || server.docker);

    return (
        <Field
            label="Servers"
            hint={value.length > 1 ? 'The first server is the leader (runs migrations and the scheduler).' : undefined}
            error={error}
        >
            {eligible.length === 0 ? (
                <p className="text-fg-muted text-xs">
                    No {requireDocker ? 'Docker-capable ' : ''}app servers yet.{' '}
                    <Link href="/servers/create" className="text-primary hover:underline">
                        Add a server
                    </Link>
                </p>
            ) : (
                <div className="border-border grid max-h-36 overflow-y-auto rounded-md border">
                    {eligible.map((server) => {
                        const checked = value.includes(server.id);

                        return (
                            <label key={server.id} className="hover:bg-surface-2 flex cursor-pointer items-center gap-2.5 px-2.5 py-1.5 text-sm">
                                <Checkbox
                                    checked={checked}
                                    onCheckedChange={(next) => onChange(next ? [...value, server.id] : value.filter((id) => id !== server.id))}
                                    aria-label={server.name}
                                />
                                <span className="text-fg font-mono text-xs">{server.name}</span>
                                <span className="text-fg-faint text-xs">{server.type_label}</span>
                                {checked && value[0] === server.id && value.length > 1 && <Tag className="ml-auto">leader</Tag>}
                                {server.status !== 'active' && (
                                    <Tag tone="warning" className="ml-auto">
                                        {server.status}
                                    </Tag>
                                )}
                            </label>
                        );
                    })}
                </div>
            )}
        </Field>
    );
}

/** The site slug Sites derives from the service name (lowercase, dashes). */
function siteSlug(name: string): string {
    return name
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 50);
}

/** Generated sslip.io name, the test domain or the user's own (with DNS instructions); needs the servers first. */
function DomainField({
    name,
    serverIds,
    value,
    onChange,
    error,
}: {
    name: string;
    serverIds: string[];
    value: DomainChoice | null;
    onChange: (value: DomainChoice) => void;
    error?: string;
}) {
    if (serverIds.length === 0) return null;

    return (
        <Field label="Domain" error={error}>
            <DomainPicker label={siteSlug(name) || 'app'} serverIds={serverIds} value={value} onChange={onChange} ariaLabel="Domain" />
        </Field>
    );
}

/**
 * The canvas Create picker (§4). Everything happens inline: pick a kind, fill the few fields it needs, and the
 * service is created through the Projects create-service endpoint; sites deploy right away and open on their
 * Deploy view.
 */
export function CreatePicker({ projectId, environmentSlug, can, position, onClose, onCreated, initialOption = null }: CreatePickerProps) {
    const { props } = usePage<SharedData>();
    const options = useMemo(() => (can.create_sites ? createOptionsFor(shellContext(props)) : []), [can.create_sites, props]);
    const [step, setStep] = useState<Step>(() =>
        initialOption && options.some((option) => option.id === initialOption) ? `option:${initialOption}` : 'root',
    );
    const option = step.startsWith('option:') ? (options.find((item) => `option:${item.id}` === step) ?? null) : null;
    // A sub-step's way back (useStepBack), e.g. from a picked repository to the repository list.
    const [inner, setInner] = useState<(() => void) | null>(null);
    const setInnerBack = useCallback((back: (() => void) | null) => setInner(() => back), []);
    // Radix restores focus to what was focused when its focus scope mounted, which is already the autofocused search
    // box; give it back to the opener (the Create button) instead.
    const [opener] = useState(() => document.activeElement);
    useEffect(
        () => () => {
            window.setTimeout(() => opener instanceof HTMLElement && opener.isConnected && opener.focus());
        },
        [opener],
    );
    const needsSites = step === 'git' || step === 'docker' || step === 'empty';
    const sites = useJson<SiteOptions>(needsSites && can.create_sites ? '/sites/create' : null);
    const engines = useJson<{ options: DatabaseCreateOptions }>(step === 'database' && can.create_databases ? '/databases' : null, { unwrap: false });
    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const go = (next: Step) => {
        setErrors({});
        setStep(next);
    };

    /** POST the service, then (sites with a source) start the first deployment. */
    const create = async (payload: Record<string, unknown>, deploy: boolean) => {
        setSubmitting(true);
        setErrors({});
        try {
            const created = await requestJson<{ data: CanvasService; warnings: string[] }>(
                `/projects/${projectId}/${environmentSlug}/services`,
                'POST',
                {
                    ...payload,
                    ...(position ?? {}),
                },
            );
            created.warnings.forEach((warning) => toast.warning(warning));
            let deploymentId: string | null = null;

            if (deploy && created.data.kind === 'site') {
                try {
                    const deployment = await requestJson<{ data: { id: string } }>(`/sites/${created.data.ref_id}/deployments`, 'POST', {});
                    deploymentId = deployment.data.id;
                } catch (error) {
                    toast.error(`${created.data.name} was created, but the first deploy did not start`, errorMessage(error));
                }
            }

            toast.success(`${created.data.name} created`);
            onCreated(created.data, deploymentId);
        } catch (error) {
            setErrors(error instanceof HttpError && Object.keys(error.errors).length > 0 ? error.errors : { form: errorMessage(error) });
        } finally {
            setSubmitting(false);
        }
    };

    const wide = option?.wide ?? false;
    const title = option ? (option.stepTitle ?? option.title) : TITLES[step as BuiltinStep];
    // Esc and Back leave the innermost sub-step first (template form → gallery), then the step, then the picker.
    const back = inner ?? (step !== 'root' ? () => go('root') : null);

    return (
        <DialogPrimitive.Root open onOpenChange={(open) => !open && onClose()}>
            <DialogPrimitive.Portal>
                <DialogPrimitive.Overlay className="animate-fade-in bg-overlay fixed inset-0 z-50" />
                <DialogPrimitive.Content
                    aria-describedby={undefined}
                    onEscapeKeyDown={(event) => {
                        if (!back) return;
                        event.preventDefault();
                        back();
                    }}
                    className={cn(
                        'animate-picker-in border-border bg-surface-1 shadow-panel fixed left-1/2 z-50 flex -translate-x-1/2 flex-col overflow-hidden rounded-xl border outline-none',
                        // Centered when full height; shorter steps keep the same top edge so filtering never makes it jump.
                        wide
                            ? 'top-[max(1rem,calc(50svh-380px))] max-h-[min(760px,calc(100svh-2rem))] w-[min(560px,calc(100vw-2rem))]'
                            : 'top-[max(1rem,calc(50svh-320px))] max-h-[min(640px,calc(100svh-2rem))] w-[min(440px,calc(100vw-2rem))]',
                    )}
                >
                    <div className="border-border flex items-center gap-1 border-b px-2 py-2">
                        {back && <IconButton size="sm" label="Back" icon={<ArrowLeft />} onClick={back} />}
                        <DialogPrimitive.Title className="text-fg flex-1 px-1 text-sm font-medium">{title}</DialogPrimitive.Title>
                        <IconButton size="sm" label="Close" shortcut="Esc" icon={<X />} onClick={onClose} />
                    </div>
                    <StepBackProvider value={setInnerBack}>
                        <div key={step} className="animate-fade-in flex min-h-0 flex-1 flex-col overflow-y-auto">
                            {step === 'root' && <RootStep can={can} options={options} onPick={go} />}
                            {option && (
                                <Suspense
                                    fallback={
                                        <div className="grid gap-2 p-4">
                                            {[0, 1, 2].map((i) => (
                                                <Skeleton key={i} className="h-8" />
                                            ))}
                                        </div>
                                    }
                                >
                                    <option.component
                                        projectId={projectId}
                                        environmentSlug={environmentSlug}
                                        position={position}
                                        onCreated={onCreated}
                                        onClose={onClose}
                                    />
                                </Suspense>
                            )}
                            {needsSites && !sites.data && (
                                <div className="grid gap-2 p-4">
                                    {sites.error ? <Errors errors={[sites.error]} /> : [0, 1, 2].map((i) => <Skeleton key={i} className="h-8" />)}
                                </div>
                            )}
                            {step === 'git' && sites.data && (
                                <GitStep
                                    options={sites.data}
                                    submitting={submitting}
                                    errors={errors}
                                    onSubmit={(payload) => void create(payload, true)}
                                />
                            )}
                            {step === 'docker' && sites.data && (
                                <DockerStep
                                    options={sites.data}
                                    submitting={submitting}
                                    errors={errors}
                                    onSubmit={(payload) => void create(payload, true)}
                                />
                            )}
                            {step === 'empty' && sites.data && (
                                <EmptyStep
                                    options={sites.data}
                                    submitting={submitting}
                                    errors={errors}
                                    onSubmit={(payload) => void create(payload, false)}
                                />
                            )}
                            {step === 'database' && (
                                <DatabaseStep
                                    options={engines.data?.options ?? null}
                                    loadError={engines.error}
                                    submitting={submitting}
                                    errors={errors}
                                    onSubmit={(payload) => void create(payload, false)}
                                />
                            )}
                        </div>
                    </StepBackProvider>
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}

function RootStep({ can, options, onPick }: { can: ProjectAbilities; options: CreateOption[]; onPick: (step: Step) => void }) {
    const items: { id: Step | 'template'; title: string; description: string; icon: ReactNode; disabled?: boolean; soon?: boolean }[] = [
        {
            id: 'git',
            title: 'Git repository',
            description: 'Deploy a repo from GitHub, GitLab, Bitbucket or any git server',
            icon: <GitBranch />,
            disabled: !can.create_sites,
        },
        {
            id: 'database',
            title: 'Database',
            description: 'PostgreSQL, MySQL or MariaDB on one of your servers',
            icon: <Database />,
            disabled: !can.create_databases,
        },
        {
            id: 'docker',
            title: 'Docker image',
            description: 'Run a public or private registry image',
            icon: <ServiceIcon name="docker" size={16} mono />,
            disabled: !can.create_sites,
        },
        { id: 'empty', title: 'Empty service', description: 'Configure the source later', icon: <Box />, disabled: !can.create_sites },
        ...options.map((option) => ({
            id: `option:${option.id}` as Step,
            title: option.title,
            description: option.description,
            icon: <option.icon />,
        })),
        // Stand-in until the Templates module registers its option.
        ...(options.some((option) => option.id === 'template')
            ? []
            : [
                  {
                      id: 'template' as const,
                      title: 'Template',
                      description: 'Multi-service compose templates',
                      icon: <LayoutTemplate />,
                      disabled: true,
                      soon: true,
                  },
              ]),
    ];

    return (
        <PickList label="Create a service" placeholder="What do you want to create?">
            {items.map((item) => (
                <PickItem
                    key={item.id}
                    value={`${item.title} ${item.description}`}
                    disabled={item.disabled}
                    onSelect={() => item.id !== 'template' && onPick(item.id)}
                    icon={item.icon}
                    title={item.title}
                    description={item.description}
                    trailing={item.soon ? <Tag>Coming soon</Tag> : <ChevronRight className="text-fg-faint size-4" aria-hidden />}
                />
            ))}
        </PickList>
    );
}

interface StepProps {
    options: SiteOptions;
    submitting: boolean;
    errors: Record<string, string>;
    onSubmit: (payload: Record<string, unknown>) => void;
}

function otherErrors(errors: Record<string, string>, shown: string[]): string[] {
    return Object.entries(errors)
        .filter(([key]) => !shown.includes(key.split('.')[0]))
        .map(([, message]) => message);
}

function GitStep({ options, submitting, errors, onSubmit }: StepProps) {
    const { connections, frameworks, servers } = options.options;
    const [connectionId, setConnectionId] = useState<string>(connections[0]?.id ?? '');
    const connection = connections.find((item) => item.id === connectionId) ?? null;
    const [search, setSearch] = useState('');
    const [repositories, setRepositories] = useState<RepositoryItem[] | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [repository, setRepository] = useState('');
    const [branches, setBranches] = useState<string[]>([]);
    const [branch, setBranch] = useState('');
    const [framework, setFramework] = useState('laravel');
    const [serverIds, setServerIds] = useState<string[]>(
        servers
            .filter((server) => server.status === 'active')
            .slice(0, 1)
            .map((server) => server.id),
    );
    const [name, setName] = useState('');
    const [domain, setDomain] = useState<DomainChoice | null>(null);
    // The user says the repository is a Docker Compose app (nothing is detected), then points at its compose files.
    const [appType, setAppType] = useState<'preset' | 'compose'>('preset');
    const [compose, setCompose] = useState<ComposeProjectValue>(emptyComposeProject);
    const [composeReady, setComposeReady] = useState(false);
    const onComposeReady = useCallback((ready: boolean) => setComposeReady(ready), []);
    const frameworkValues = useMemo(() => frameworks.map((item) => item.value), [frameworks]);

    useEffect(() => {
        if (!connection?.has_api) {
            setRepositories(null);

            return;
        }
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            requestJson<{ data: RepositoryItem[] }>(
                `/source-control/connections/${connection.id}/repositories?search=${encodeURIComponent(search)}`,
                'GET',
                undefined,
                {
                    signal: controller.signal,
                },
            )
                .then((body) => {
                    setRepositories(body.data);
                    setLoadError(null);
                })
                .catch((error: unknown) => !controller.signal.aborted && setLoadError(errorMessage(error, 'Could not load repositories')));
        }, 250);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [connection, search]);

    useEffect(() => {
        if (!connection?.has_api || !repository) {
            setBranches([]);

            return;
        }
        let cancelled = false;
        requestJson<{ data: { name: string }[] }>(
            `/source-control/connections/${connection.id}/branches?repository=${encodeURIComponent(repository)}`,
        )
            .then((body) => !cancelled && setBranches(body.data.map((item) => item.name)))
            .catch(() => !cancelled && setBranches([]));

        return () => {
            cancelled = true;
        };
    }, [connection, repository]);

    const pick = (item: { full_name: string; default_branch: string }) => {
        setRepository(item.full_name);
        setBranch(item.default_branch);
        setFramework(detectPreset(item.full_name, frameworkValues));
        setName(slugName(item.full_name));
    };
    useStepBack(repository !== '', () => setRepository(''));

    if (connections.length === 0) {
        return (
            <div className="grid gap-3 p-4 text-sm">
                <p className="text-fg-muted">
                    Connect GitHub to deploy its repositories: Falak installs a GitHub App and you pick the repositories on GitHub.
                </p>
                {options.can_manage_source_control ? (
                    <div className="flex flex-wrap gap-2">
                        {/* Full page navigation: the settings page continues to github.com and GitHub returns here. */}
                        <Button asChild variant="primary" className="w-fit">
                            <a href={`/settings/source-control?connect=github&return_to=${encodeURIComponent(window.location.pathname)}`}>
                                Connect GitHub
                            </a>
                        </Button>
                        <Button asChild variant="ghost" className="w-fit">
                            <Link href="/settings/source-control">GitLab, Bitbucket or git server</Link>
                        </Button>
                    </div>
                ) : (
                    <p className="text-fg-faint text-xs">Ask an organization admin to connect one.</p>
                )}
            </div>
        );
    }

    const connectionSelect = (
        <Select
            aria-label="Connection"
            value={connectionId}
            onValueChange={(value) => {
                setConnectionId(value);
                setRepository('');
                setBranch('');
            }}
            options={connections.map((item) => ({ value: item.id, label: `${item.name} · ${item.provider_label}` }))}
        />
    );

    if (!repository && connection?.has_api) {
        return (
            <PickList
                label="Repositories"
                placeholder="Search repositories"
                search={search}
                onSearchChange={setSearch}
                filter={false}
                toolbar={<div className="border-border border-b px-3 py-2">{connectionSelect}</div>}
                empty={
                    loadError ? (
                        <span className="text-danger">{loadError}</span>
                    ) : repositories === null ? (
                        'Loading repositories…'
                    ) : (
                        'No repositories match.'
                    )
                }
            >
                {repositories?.map((item) => (
                    <PickItem
                        key={item.full_name}
                        value={item.full_name}
                        onSelect={() => pick(item)}
                        icon={<GitBranch />}
                        title={item.full_name}
                        description={item.default_branch}
                        trailing={item.private && <Lock className="text-fg-faint size-3.5" aria-label="private" />}
                    />
                ))}
            </PickList>
        );
    }

    const detected = frameworks.find((item) => item.value === framework);

    return (
        <form
            className="grid gap-4 p-4"
            onSubmit={(event) => {
                event.preventDefault();
                const common = {
                    kind: 'site',
                    name,
                    source_connection_id: connectionId,
                    repository,
                    branch,
                    push_to_deploy: true,
                    server_ids: serverIds,
                    leader_server_id: serverIds[0],
                };
                onSubmit(
                    appType === 'compose'
                        ? { ...common, ...composeProjectPayload(compose) }
                        : { ...common, framework, domain: domainPayload(domain) },
                );
            }}
        >
            <Field label="Connection">{connectionSelect}</Field>

            {!repository ? (
                <CustomRepository onPick={pick} />
            ) : (
                <>
                    <div className="border-border bg-surface-2 flex items-center gap-2 rounded-md border px-2.5 py-2">
                        <GitBranch className="text-fg-muted size-4 shrink-0" aria-hidden />
                        <span className="text-fg min-w-0 flex-1 truncate font-mono text-xs">{repository}</span>
                        <Button size="sm" variant="ghost" onClick={() => setRepository('')}>
                            Change
                        </Button>
                    </div>
                    <Field label="App type">
                        <Segmented<'preset' | 'compose'>
                            label="App type"
                            value={appType}
                            onValueChange={setAppType}
                            options={[
                                { value: 'preset', label: 'One app' },
                                { value: 'compose', label: 'Docker Compose app' },
                            ]}
                        />
                    </Field>
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="Branch" error={errors.branch}>
                            {branches.length > 0 ? (
                                <Combobox
                                    value={branch}
                                    onValueChange={(value) => setBranch(value ?? '')}
                                    options={branches.map((item) => ({ value: item, label: item }))}
                                />
                            ) : (
                                <Input value={branch} onChange={(event) => setBranch(event.target.value)} mono />
                            )}
                        </Field>
                        {appType === 'preset' && (
                            <Field label="Preset" hint={detected ? 'Guessed from the repository name' : undefined} error={errors.framework}>
                                <Select
                                    value={framework}
                                    onValueChange={setFramework}
                                    options={frameworks.map((item) => ({ value: item.value, label: item.label }))}
                                />
                            </Field>
                        )}
                    </div>
                    <Field label="Service name" error={errors.name}>
                        <Input value={name} onChange={(event) => setName(event.target.value)} autoFocus />
                    </Field>
                    <ServersField
                        servers={servers}
                        value={serverIds}
                        onChange={setServerIds}
                        error={errors.server_ids}
                        requireDocker={appType === 'compose'}
                    />
                    {appType === 'compose' ? (
                        <ComposeProject
                            connectionId={connectionId}
                            repository={repository}
                            branch={branch}
                            name={name}
                            serverIds={serverIds}
                            value={compose}
                            onChange={setCompose}
                            errors={errors}
                            onReadyChange={onComposeReady}
                        />
                    ) : (
                        <DomainField name={name} serverIds={serverIds} value={domain} onChange={setDomain} error={errors.domain} />
                    )}
                    <Errors
                        errors={otherErrors(errors, [
                            'branch',
                            'framework',
                            'name',
                            'server_ids',
                            'domain',
                            ...(appType === 'compose'
                                ? ['compose_files', 'compose_profiles', 'compose_services', 'public_services', 'variables']
                                : []),
                        ])}
                    />
                    <Button
                        variant="primary"
                        type="submit"
                        icon={<Rocket />}
                        loading={submitting}
                        disabled={!branch || serverIds.length === 0 || !name || (appType === 'compose' && !composeReady)}
                    >
                        Deploy
                    </Button>
                </>
            )}
        </form>
    );
}

function CustomRepository({ onPick }: { onPick: (item: { full_name: string; default_branch: string }) => void }) {
    const [url, setUrl] = useState('');

    return (
        <div className="grid gap-2">
            <Field label="Clone URL" hint="SSH or HTTPS; Falak adds a deploy key you install on the git server.">
                <Input value={url} onChange={(event) => setUrl(event.target.value)} placeholder="git@git.example.com:acme/shop.git" mono autoFocus />
            </Field>
            <Button className="w-fit" disabled={!url} onClick={() => onPick({ full_name: url.trim(), default_branch: 'main' })}>
                Continue
            </Button>
        </div>
    );
}

function DockerStep({ options, submitting, errors, onSubmit }: StepProps) {
    const [image, setImage] = useState('');
    const [port, setPort] = useState('');
    const [name, setName] = useState('');
    const [domain, setDomain] = useState<DomainChoice | null>(null);
    const [serverIds, setServerIds] = useState<string[]>(() =>
        options.options.servers
            .filter((server) => server.docker && server.status === 'active')
            .slice(0, 1)
            .map((server) => server.id),
    );

    return (
        <form
            className="grid gap-4 p-4"
            onSubmit={(event) => {
                event.preventDefault();
                onSubmit({
                    kind: 'site',
                    name: name || slugName(image.split(':')[0]),
                    framework: 'docker',
                    runtime: 'docker',
                    docker_image: image,
                    container_port: port ? Number(port) : null,
                    server_ids: serverIds,
                    leader_server_id: serverIds[0],
                    domain: domainPayload(domain),
                });
            }}
        >
            <Field label="Image" hint="e.g. ghcr.io/acme/api:latest" error={errors.docker_image}>
                <Input value={image} onChange={(event) => setImage(event.target.value)} placeholder="nginx:1.27" mono autoFocus />
            </Field>
            <div className="grid grid-cols-2 gap-3">
                <Field label="Service name" error={errors.name}>
                    <Input
                        value={name}
                        onChange={(event) => setName(event.target.value)}
                        placeholder={image ? slugName(image.split(':')[0]) : 'api'}
                    />
                </Field>
                <Field label="Container port" hint="The port the app listens on inside its container" error={errors.container_port}>
                    <Input value={port} onChange={(event) => setPort(event.target.value.replace(/\D/g, ''))} placeholder="3000" inputMode="numeric" />
                </Field>
            </div>
            <ServersField servers={options.options.servers} value={serverIds} onChange={setServerIds} error={errors.server_ids} requireDocker />
            <DomainField
                name={name || slugName(image.split(':')[0])}
                serverIds={serverIds}
                value={domain}
                onChange={setDomain}
                error={errors.domain}
            />
            <Errors errors={otherErrors(errors, ['docker_image', 'name', 'container_port', 'server_ids', 'domain'])} />
            <Button variant="primary" type="submit" icon={<Rocket />} loading={submitting} disabled={!image || serverIds.length === 0}>
                Deploy
            </Button>
        </form>
    );
}

function EmptyStep({ options, submitting, errors, onSubmit }: StepProps) {
    const [name, setName] = useState('');
    const [framework, setFramework] = useState('laravel');
    const [serverIds, setServerIds] = useState<string[]>(
        options.options.servers
            .filter((server) => server.status === 'active')
            .slice(0, 1)
            .map((server) => server.id),
    );

    return (
        <form
            className="grid gap-4 p-4"
            onSubmit={(event) => {
                event.preventDefault();
                onSubmit({ kind: 'site', name, framework, server_ids: serverIds, leader_server_id: serverIds[0] });
            }}
        >
            <p className="text-fg-muted text-xs">
                Creates the service on its servers without a source; connect a repository or image later in Settings.
            </p>
            <div className="grid grid-cols-2 gap-3">
                <Field label="Service name" error={errors.name}>
                    <Input value={name} onChange={(event) => setName(event.target.value)} placeholder="api" autoFocus />
                </Field>
                <Field label="Preset" error={errors.framework}>
                    <Select
                        value={framework}
                        onValueChange={setFramework}
                        options={options.options.frameworks.map((item) => ({ value: item.value, label: item.label }))}
                    />
                </Field>
            </div>
            <ServersField servers={options.options.servers} value={serverIds} onChange={setServerIds} error={errors.server_ids} />
            <Errors errors={otherErrors(errors, ['name', 'framework', 'server_ids'])} />
            <Button variant="primary" type="submit" loading={submitting} disabled={!name || serverIds.length === 0}>
                Create service
            </Button>
        </form>
    );
}

function DatabaseStep({
    options,
    loadError,
    submitting,
    errors,
    onSubmit,
}: {
    options: DatabaseCreateOptions | null;
    loadError: string | null;
    submitting: boolean;
    errors: Record<string, string>;
    onSubmit: (payload: Record<string, unknown>) => void;
}) {
    const [engine, setEngine] = useState<string | null>(null);
    const [picked, setServerId] = useState('');
    const [name, setName] = useState('app');
    const [advanced, setAdvanced] = useState(false);
    const [version, setVersion] = useState('');
    const [memory, setMemory] = useState('');
    const [disk, setDisk] = useState('');
    const [eviction, setEviction] = useState('noeviction');
    const servers = options?.servers ?? [];
    const serverId = servers.some((server) => server.id === picked) ? picked : (servers[0]?.id ?? '');
    const choice = options?.engines.find((item) => item.value === engine) ?? null;
    const keyValue = engine !== null && KEY_VALUE_ENGINES.includes(engine);
    const label = ENGINES.find((item) => item.value === engine)?.label;

    const pick = (value: string) => {
        setEngine(value);
        const next = options?.engines.find((item) => item.value === value);
        setVersion(next?.default_version ?? '');
        setMemory(next ? String(next.default_memory_mb) : '');
        setDisk(next ? String(next.default_disk_gb) : '');
        if (name === 'app' || name === 'cache') setName(KEY_VALUE_ENGINES.includes(value) ? 'cache' : 'app');
    };
    useStepBack(engine !== null, () => setEngine(null));

    if (!engine) {
        return (
            <PickList
                label="Engines"
                placeholder="Search engines"
                toolbar={
                    loadError && (
                        <div className="px-3 pt-3">
                            <Errors errors={[loadError]} />
                        </div>
                    )
                }
            >
                {ENGINES.map((item) => {
                    const versions = options?.engines.find((option) => option.value === item.value)?.versions.join(', ');

                    return (
                        <PickItem
                            key={item.value}
                            value={`${item.label} ${item.value}`}
                            onSelect={() => pick(item.value)}
                            icon={<ServiceIcon name={item.value} size={16} />}
                            title={item.label}
                            description={options === null ? '…' : versions}
                            trailing={<ChevronRight className="text-fg-faint size-4" aria-hidden />}
                        />
                    );
                })}
            </PickList>
        );
    }

    return (
        <form
            className="grid gap-4 p-4"
            onSubmit={(event) => {
                event.preventDefault();
                onSubmit({
                    kind: 'database',
                    engine,
                    server_id: serverId,
                    name,
                    version: version || undefined,
                    memory_mb: Number(memory) || undefined,
                    disk_gb: Number(disk) || undefined,
                    ...(keyValue ? { eviction } : {}),
                });
            }}
        >
            <div className="border-border bg-surface-2 flex items-center gap-2 rounded-md border px-2.5 py-2">
                <ServiceIcon name={engine} size={16} />
                <span className="text-fg min-w-0 flex-1 truncate text-sm">{label}</span>
                <Button size="sm" variant="ghost" onClick={() => setEngine(null)}>
                    Change
                </Button>
            </div>
            <Field label="Server" hint="The database runs there as a container, on its own data volume." error={errors.server_id}>
                {servers.length > 0 ? (
                    <Select
                        value={serverId}
                        onValueChange={setServerId}
                        options={servers.map((server) => ({ value: server.id, label: server.name }))}
                    />
                ) : (
                    <p className="text-fg-muted text-xs">
                        No active server yet.{' '}
                        <Link href="/servers/create" className="text-primary hover:underline">
                            Provision a server
                        </Link>
                    </p>
                )}
            </Field>
            <Field
                label="Name"
                hint={
                    keyValue
                        ? `Its own ${label} container with a password (user default). Lower-case letters, digits, - and _.`
                        : 'A database and a user with this name and a generated password are created in it.'
                }
                error={errors.name}
            >
                <Input value={name} onChange={(event) => setName(event.target.value)} mono autoFocus />
            </Field>
            <div className="grid gap-3">
                <button
                    type="button"
                    aria-expanded={advanced}
                    onClick={() => setAdvanced(!advanced)}
                    className="text-fg-muted hover:text-fg flex items-center gap-1 text-xs font-medium"
                >
                    <ChevronRight size={12} className={cn('transition-transform duration-150', advanced && 'rotate-90')} />
                    Advanced
                </button>
                {advanced && (
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="Version" error={errors.version}>
                            <Select
                                value={version}
                                onValueChange={setVersion}
                                options={(choice?.versions ?? []).map((item) => ({ value: item, label: item }))}
                            />
                        </Field>
                        <Field label="Memory limit (MB)" hint={choice ? `At least ${choice.min_memory_mb} MB.` : undefined} error={errors.memory_mb}>
                            <Input value={memory} onChange={(event) => setMemory(event.target.value.replace(/\D/g, ''))} inputMode="numeric" mono />
                        </Field>
                        <Field label="Disk (GB)" error={errors.disk_gb}>
                            <Input value={disk} onChange={(event) => setDisk(event.target.value.replace(/\D/g, ''))} inputMode="numeric" mono />
                        </Field>
                        {keyValue && (
                            <Field label="Eviction" error={errors['settings.eviction'] ?? errors.eviction}>
                                <Select value={eviction} onValueChange={setEviction} options={EVICTIONS} />
                            </Field>
                        )}
                    </div>
                )}
            </div>
            <Errors errors={otherErrors(errors, ['server_id', 'name', 'version', 'memory_mb', 'disk_gb', 'eviction', 'settings.eviction'])} />
            <Button variant="primary" type="submit" loading={submitting} disabled={!serverId || !name}>
                Create {label}
            </Button>
        </form>
    );
}
