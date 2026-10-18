import {
    AppShell,
    Button,
    Dialog,
    EmptyState,
    Field,
    IconButton,
    Input,
    Kbd,
    Menu,
    MenuCheckboxItem,
    MenuContent,
    MenuRoot,
    MenuTrigger,
    RelativeTime,
    ServiceIcon,
    SetupChecklist,
    StatusDot,
    Textarea,
    defaultSetupSteps,
    hasServiceIcon,
    toast,
    type SetupProgress,
} from '@/components/falak';
import { errorMessage, requestJson } from '@/lib/http';
import { shellContext } from '@/lib/registry';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import {
    BookOpen,
    ChevronDown,
    FolderKanban,
    Gauge,
    LayoutGrid,
    LayoutTemplate,
    List,
    Plus,
    Search,
    Server,
    Settings,
    Star,
    Users,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { canvasUrl, type ProjectSummary } from '../types';

interface Props {
    projects: ProjectSummary[];
    setup: SetupProgress;
    can: { create: boolean };
}

type Sort = 'activity' | 'name' | 'created';
type View = 'grid' | 'list';

const SETUP_DISMISSED = 'falak:setup-dismissed';
const PREFS = 'falak:projects-view';
const SORTS: { id: Sort; label: string }[] = [
    { id: 'activity', label: 'Recent activity' },
    { id: 'name', label: 'Name' },
    { id: 'created', label: 'Date created' },
];

function readDismissed(): boolean {
    try {
        return window.localStorage.getItem(SETUP_DISMISSED) === '1';
    } catch {
        return false;
    }
}

function readPrefs(): { sort: Sort; view: View } {
    try {
        return { sort: 'activity', view: 'grid', ...JSON.parse(window.localStorage.getItem(PREFS) ?? '{}') };
    } catch {
        return { sort: 'activity', view: 'grid' };
    }
}

function productionOf(project: ProjectSummary) {
    return project.environments.find((env) => env.is_production) ?? project.environments[0] ?? null;
}

function hrefOf(project: ProjectSummary): string {
    const production = productionOf(project);

    return production ? canvasUrl(project.id, production.slug) : `/projects/${project.id}`;
}

const HEALTH: Record<NonNullable<ProjectSummary['production']>['health'], string> = {
    online: 'active',
    partial: 'degraded',
    pending: 'deploying',
    failing: 'failed',
    empty: 'inactive',
};

/** "● production · 4/4 services online" (or "No services"). */
function HealthLine({ project, className }: { project: ProjectSummary; className?: string }) {
    const production = project.production;
    if (!production || production.services === 0) {
        return (
            <span className={cn('text-fg-faint flex items-center gap-2 text-xs', className)}>
                <StatusDot status="inactive" />
                No services
            </span>
        );
    }

    return (
        <span className={cn('text-fg-muted flex min-w-0 items-center gap-2 text-xs', className)} data-testid="project-health">
            <StatusDot status={HEALTH[production.health]} />
            <span className="truncate">
                {production.name} · {production.online}/{production.services} services online
            </span>
        </span>
    );
}

function FavoriteButton({ project, onToggle }: { project: ProjectSummary; onToggle: (project: ProjectSummary) => void }) {
    return (
        <button
            type="button"
            onClick={() => onToggle(project)}
            aria-pressed={project.favorite}
            aria-label={project.favorite ? `Unstar ${project.name}` : `Star ${project.name}`}
            className={cn(
                'relative z-10 flex size-7 shrink-0 items-center justify-center rounded-md transition-[color,transform,opacity] duration-150 active:scale-90',
                project.favorite ? 'text-warning' : 'text-fg-faint hover:text-fg-muted opacity-0 group-hover:opacity-100 focus-visible:opacity-100',
            )}
        >
            <Star className={cn('size-4', project.favorite && 'fill-current')} aria-hidden />
        </button>
    );
}

function projectMenu(project: ProjectSummary) {
    return [
        ...project.environments.map((env) => ({ label: `Open ${env.name}`, href: canvasUrl(project.id, env.slug) })),
        { type: 'separator' as const },
        { label: 'Project settings', icon: <Settings />, href: `/projects/${project.id}/settings` },
    ];
}

/** Service icons on a dotted canvas: what's inside the project at a glance. */
function Preview({ project }: { project: ProjectSummary }) {
    const shown = project.services.slice(0, 6);
    const extra = (project.production?.services ?? project.services.length) - shown.length;

    return (
        <div className="bg-dotted border-border relative mx-3 flex h-32 items-center justify-center overflow-hidden rounded-lg border">
            {shown.length === 0 ? (
                <span className="text-fg-faint text-xs">No services</span>
            ) : (
                <div className="flex flex-wrap items-center justify-center gap-2 px-4">
                    {shown.map((service, index) => (
                        <span
                            key={`${service.name}-${index}`}
                            title={service.name}
                            className="border-border bg-surface-1 flex size-10 items-center justify-center rounded-lg border shadow-[0_1px_2px_rgba(0,0,0,0.12)] transition-transform duration-200 group-hover:-translate-y-0.5"
                            style={{ transitionDelay: `${index * 25}ms` }}
                        >
                            <ServiceIcon name={hasServiceIcon(service.icon) ? service.icon : 'compose'} size={18} title={service.name} />
                        </span>
                    ))}
                    {extra > 0 && (
                        <span className="border-border bg-surface-1 text-fg-muted flex size-10 items-center justify-center rounded-lg border text-xs font-medium">
                            +{extra}
                        </span>
                    )}
                </div>
            )}
        </div>
    );
}

function ProjectCard({ project, onToggle }: { project: ProjectSummary; onToggle: (project: ProjectSummary) => void }) {
    return (
        <article
            className="group border-border bg-surface-1 hover:border-border-strong relative grid gap-3 rounded-xl border pb-3 transition-[border-color,transform,box-shadow] duration-200 hover:-translate-y-0.5 hover:shadow-[0_12px_32px_-16px_rgba(0,0,0,0.35)]"
            data-testid="project-card"
        >
            <div className="flex items-center gap-1 pt-3 pr-2 pl-4">
                <Link
                    href={hrefOf(project)}
                    className="text-fg min-w-0 flex-1 truncate text-sm font-semibold after:absolute after:inset-0 after:rounded-xl"
                >
                    {project.name}
                </Link>
                <FavoriteButton project={project} onToggle={onToggle} />
                <span className="relative z-10">
                    <Menu label={`${project.name} actions`} actions={projectMenu(project)} />
                </span>
            </div>
            <Preview project={project} />
            <div className="flex items-center justify-between gap-2 px-4 pt-0.5">
                <HealthLine project={project} />
                <span className="text-fg-faint shrink-0 text-xs">
                    <RelativeTime value={project.last_activity_at} />
                </span>
            </div>
        </article>
    );
}

function ProjectRow({ project, onToggle }: { project: ProjectSummary; onToggle: (project: ProjectSummary) => void }) {
    return (
        <div className="group hover:bg-surface-2/60 relative flex items-center gap-3 px-3 py-2.5 transition-colors" data-testid="project-row">
            <FavoriteButton project={project} onToggle={onToggle} />
            <div className="grid min-w-0 flex-1 gap-0.5 sm:flex-none sm:basis-64">
                <Link href={hrefOf(project)} className="text-fg truncate text-sm font-medium after:absolute after:inset-0">
                    {project.name}
                </Link>
                {project.description && <span className="text-fg-faint truncate text-xs">{project.description}</span>}
            </div>
            <span className="hidden min-w-0 flex-1 items-center gap-1.5 md:flex">
                {project.services.slice(0, 6).map((service, index) => (
                    <span
                        key={`${service.name}-${index}`}
                        className="border-border bg-surface-1 flex size-7 items-center justify-center rounded-md border"
                        title={service.name}
                    >
                        <ServiceIcon name={hasServiceIcon(service.icon) ? service.icon : 'compose'} size={14} title={service.name} />
                    </span>
                ))}
            </span>
            <HealthLine project={project} className="hidden w-60 sm:flex" />
            <span className="text-fg-faint hidden w-24 shrink-0 text-right text-xs lg:inline">
                <RelativeTime value={project.last_activity_at} />
            </span>
            <span className="relative z-10">
                <Menu label={`${project.name} actions`} actions={projectMenu(project)} />
            </span>
        </div>
    );
}

function CreateProjectDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
    const form = useForm({ name: '', description: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/projects', { onSuccess: () => onOpenChange(false) });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) form.reset();
                onOpenChange(next);
            }}
            title="New project"
            description="A project groups the services you deploy together. It starts with a production environment."
            footer={
                <>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" form="create-project" loading={form.processing}>
                        Create project
                    </Button>
                </>
            }
        >
            <form id="create-project" onSubmit={submit} className="grid gap-4">
                <Field label="Name" error={form.errors.name} required>
                    <Input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} placeholder="Storefront" autoFocus />
                </Field>
                <Field label="Description" hint="Optional." error={form.errors.description}>
                    <Textarea value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} rows={2} />
                </Field>
            </form>
        </Dialog>
    );
}

interface SidebarItem {
    title: string;
    href: string;
    icon: LucideIcon;
    permission?: string;
    external?: boolean;
}

const SIDEBAR: SidebarItem[] = [
    { title: 'Projects', href: '/projects', icon: FolderKanban },
    { title: 'Templates', href: '/templates', icon: LayoutTemplate, permission: 'templates.view' },
    { title: 'Infrastructure', href: '/servers', icon: Server, permission: 'servers.view' },
    { title: 'Observability', href: '/observability', icon: Gauge, permission: 'insights.view' },
    { title: 'Team', href: '/settings/members', icon: Users, permission: 'members.view' },
    { title: 'Settings', href: '/settings/organization', icon: Settings },
];

/** Workspace navigation next to the dashboard (the canvas stays full-bleed; §3). */
function Sidebar() {
    const { props } = usePage<SharedData>();
    const shell = useMemo(() => shellContext(props), [props]);

    return (
        <nav aria-label="Workspace" className="sticky top-18 hidden h-fit flex-col gap-0.5 lg:flex">
            {SIDEBAR.filter((item) => !item.permission || shell.can(item.permission)).map((item) => {
                const active = item.href === '/projects';

                return (
                    <Link
                        key={item.href}
                        href={item.href}
                        aria-current={active ? 'page' : undefined}
                        className={cn(
                            'flex h-8 items-center gap-2.5 rounded-md px-2.5 text-sm transition-colors duration-150',
                            active ? 'bg-surface-2 text-fg font-medium' : 'text-fg-muted hover:bg-surface-2/60 hover:text-fg',
                        )}
                    >
                        <item.icon className={cn('size-4', active ? 'text-fg' : 'text-fg-faint')} aria-hidden />
                        {item.title}
                    </Link>
                );
            })}
            <span className="bg-border mx-2.5 my-2 h-px" aria-hidden />
            <a
                href="https://falak.sh/docs/"
                target="_blank"
                rel="noreferrer"
                className="text-fg-muted hover:bg-surface-2/60 hover:text-fg flex h-8 items-center gap-2.5 rounded-md px-2.5 text-sm transition-colors duration-150"
            >
                <BookOpen className="text-fg-faint size-4" aria-hidden />
                Documentation
            </a>
        </nav>
    );
}

/** §3.1 Projects dashboard: search, sort, grid / list, starred first, a preview of what's inside each project. */
export default function Index({ projects: initial, setup, can }: Props) {
    const [projects, setProjects] = useState(initial);
    const [creating, setCreating] = useState(false);
    const [dismissed, setDismissed] = useState(readDismissed);
    const [prefs, setPrefs] = useState(readPrefs);
    const [query, setQuery] = useState('');
    const search = useRef<HTMLInputElement>(null);
    const first = projects.find((project) => project.is_default) ?? projects[0];
    const firstEnv = first ? productionOf(first) : null;
    const { props: shared } = usePage<SharedData>();
    // The install's operators also get the control plane's disaster recovery (Recovery's `disasterRecovery` prop).
    const dr = shared.disasterRecovery as { needs_setup: boolean; settings_url: string } | null | undefined;
    const steps = [
        ...defaultSetupSteps(setup, firstEnv && first ? { deploy: canvasUrl(first.id, firstEnv.slug) } : {}).map((step) =>
            step.id === 'project' && can.create ? { ...step, href: undefined, onAction: () => setCreating(true) } : step,
        ),
        ...(dr
            ? [
                  {
                      id: 'disaster-recovery',
                      title: 'Set up disaster recovery',
                      description: 'Encrypted backups of the control plane to a bucket, on a schedule: if this host is lost, everything comes back.',
                      done: !dr.needs_setup,
                      href: dr.settings_url,
                      actionLabel: 'Set up',
                  },
              ]
            : []),
    ];

    useEffect(() => setProjects(initial), [initial]);

    // `/` focuses the search field.
    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key !== '/' || event.metaKey || event.ctrlKey) return;
            const target = event.target as HTMLElement | null;
            if (target && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))) return;
            if (document.querySelector('[role="dialog"]')) return;
            event.preventDefault();
            search.current?.focus();
        };
        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, []);

    const update = (patch: Partial<typeof prefs>) =>
        setPrefs((current) => {
            const next = { ...current, ...patch };
            try {
                window.localStorage.setItem(PREFS, JSON.stringify(next));
            } catch {
                // Not remembered.
            }

            return next;
        });

    const toggle = async (project: ProjectSummary) => {
        const favorite = !project.favorite;
        setProjects((current) => current.map((item) => (item.id === project.id ? { ...item, favorite } : item)));
        try {
            await requestJson(`/projects/${project.id}/favorite`, favorite ? 'PUT' : 'DELETE');
        } catch (error) {
            setProjects((current) => current.map((item) => (item.id === project.id ? { ...item, favorite: !favorite } : item)));
            toast.error('Could not update favorites', errorMessage(error));
        }
    };

    const visible = useMemo(() => {
        const needle = query.trim().toLowerCase();
        const matches = needle
            ? projects.filter((project) =>
                  [project.name, project.description ?? '', ...project.services.map((service) => service.name)].some((text) =>
                      text.toLowerCase().includes(needle),
                  ),
              )
            : projects;
        const order = (a: ProjectSummary, b: ProjectSummary) => {
            if (prefs.sort === 'name') return a.name.localeCompare(b.name);
            if (prefs.sort === 'created') return b.created_at.localeCompare(a.created_at);

            return b.last_activity_at.localeCompare(a.last_activity_at);
        };

        return [...matches].sort((a, b) => Number(b.favorite) - Number(a.favorite) || order(a, b));
    }, [projects, query, prefs.sort]);

    return (
        <AppShell className="max-w-[1320px]">
            <Head title="Projects" />
            <div className="grid gap-8 lg:grid-cols-[200px_minmax(0,1fr)]">
                <Sidebar />
                <div className="grid min-w-0 content-start gap-6">
                    <div className="flex flex-wrap items-center gap-3">
                        <h1 className="text-fg mr-auto text-xl font-semibold tracking-tight">Projects</h1>
                        <div className="relative w-full sm:w-64">
                            <Search className="text-fg-faint pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2" aria-hidden />
                            <input
                                ref={search}
                                type="search"
                                value={query}
                                onChange={(event) => setQuery(event.target.value)}
                                placeholder="Search projects…"
                                aria-label="Search projects"
                                className="border-border bg-surface-1 text-fg placeholder:text-fg-faint hover:border-border-strong focus-visible:border-border-strong focus-visible:outline-primary h-8 w-full rounded-md border pr-9 pl-8 text-sm transition-colors focus-visible:outline-2 focus-visible:outline-offset-1 [&::-webkit-search-cancel-button]:hidden"
                            />
                            <Kbd className="pointer-events-none absolute top-1/2 right-2 -translate-y-1/2">/</Kbd>
                        </div>
                        {can.create && (
                            <Button variant="primary" icon={<Plus />} onClick={() => setCreating(true)}>
                                New
                            </Button>
                        )}
                    </div>

                    {!dismissed && (
                        <SetupChecklist
                            steps={steps}
                            onDismiss={() => {
                                setDismissed(true);
                                try {
                                    window.localStorage.setItem(SETUP_DISMISSED, '1');
                                } catch {
                                    // Not persisted; hidden for this visit.
                                }
                            }}
                        />
                    )}

                    {projects.length === 0 ? (
                        <EmptyState
                            icon={<FolderKanban />}
                            title="No projects yet"
                            description="Projects group the sites and databases you deploy together, with production and staging environments."
                            action={
                                can.create && (
                                    <Button variant="primary" icon={<Plus />} onClick={() => setCreating(true)}>
                                        New project
                                    </Button>
                                )
                            }
                        />
                    ) : (
                        <div className="grid gap-4">
                            <div className="text-fg-muted flex items-center gap-3 text-sm">
                                <span className="tabular-nums" aria-live="polite">
                                    {visible.length} {visible.length === 1 ? 'Project' : 'Projects'}
                                </span>
                                <span className="bg-border h-4 w-px" aria-hidden />
                                <MenuRoot>
                                    <MenuTrigger asChild>
                                        <button type="button" className="hover:text-fg flex items-center gap-1 rounded-sm transition-colors">
                                            Sort by: <span className="text-fg">{SORTS.find((sort) => sort.id === prefs.sort)?.label}</span>
                                            <ChevronDown className="size-3.5" aria-hidden />
                                        </button>
                                    </MenuTrigger>
                                    <MenuContent align="start" className="w-48">
                                        {SORTS.map((sort) => (
                                            <MenuCheckboxItem
                                                key={sort.id}
                                                checked={prefs.sort === sort.id}
                                                onCheckedChange={() => update({ sort: sort.id })}
                                            >
                                                {sort.label}
                                            </MenuCheckboxItem>
                                        ))}
                                    </MenuContent>
                                </MenuRoot>
                                <div
                                    className="border-border bg-surface-1 ml-auto flex items-center rounded-md border p-0.5"
                                    role="radiogroup"
                                    aria-label="View"
                                >
                                    {(
                                        [
                                            ['grid', LayoutGrid, 'Grid view'],
                                            ['list', List, 'List view'],
                                        ] as const
                                    ).map(([id, Icon, label]) => (
                                        <IconButton
                                            key={id}
                                            size="sm"
                                            role="radio"
                                            aria-checked={prefs.view === id}
                                            label={label}
                                            icon={<Icon />}
                                            onClick={() => update({ view: id })}
                                            className={cn('h-6 w-7', prefs.view === id ? 'bg-surface-3 text-fg' : 'text-fg-muted')}
                                        />
                                    ))}
                                </div>
                            </div>

                            {visible.length === 0 ? (
                                <p className="text-fg-muted border-border rounded-xl border border-dashed px-4 py-10 text-center text-sm">
                                    No projects match “{query}”.
                                </p>
                            ) : prefs.view === 'grid' ? (
                                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" data-testid="projects-grid">
                                    {visible.map((project) => (
                                        <ProjectCard key={project.id} project={project} onToggle={(item) => void toggle(item)} />
                                    ))}
                                </div>
                            ) : (
                                <div
                                    className="border-border bg-surface-1 divide-border divide-y overflow-hidden rounded-xl border"
                                    data-testid="projects-list"
                                >
                                    {visible.map((project) => (
                                        <ProjectRow key={project.id} project={project} onToggle={(item) => void toggle(item)} />
                                    ))}
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </div>
            {can.create && <CreateProjectDialog open={creating} onOpenChange={setCreating} />}
        </AppShell>
    );
}
