import {
    AppShell,
    Button,
    ConfirmDestructive,
    DataTable,
    Dialog,
    Field,
    Input,
    PageHeader,
    RelativeTime,
    Section,
    Tag,
    Textarea,
} from '@/components/falak';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Copy, ExternalLink, HardDrive, KeyRound, Pencil, Plus, Trash2 } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import { ConfigSection } from '../components/config-section';
import { NewEnvironmentDialog } from '../components/new-environment-dialog';
import { canvasUrl, type ProjectAbilities, type ProjectDetail, type ProjectEnvironment } from '../types';

interface Props {
    project: ProjectDetail;
    can: ProjectAbilities;
}

function RenameEnvironmentDialog({
    projectId,
    environment,
    onClose,
}: {
    projectId: string;
    environment: ProjectEnvironment | null;
    onClose: () => void;
}) {
    const form = useForm({ name: environment?.name ?? '' });

    useEffect(() => {
        if (environment) form.setData('name', environment.name);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [environment]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!environment) return;
        form.patch(`/projects/${projectId}/environments/${environment.id}`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog
            open={environment !== null}
            onOpenChange={(open) => !open && onClose()}
            title="Rename environment"
            description="The URL slug follows the new name; existing links to the old slug stop working."
            size="sm"
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" form="rename-environment" loading={form.processing}>
                        Rename
                    </Button>
                </>
            }
        >
            <form id="rename-environment" onSubmit={submit}>
                <Field label="Name" error={form.errors.name}>
                    <Input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} autoFocus />
                </Field>
            </form>
        </Dialog>
    );
}

/** /projects/{p}/settings: name, environments (create / duplicate / rename / delete) and the danger zone. */
export default function Settings({ project, can }: Props) {
    const general = useForm({ name: project.name, description: project.description ?? '' });
    const [creating, setCreating] = useState<{ from: string | null } | null>(null);
    const [renaming, setRenaming] = useState<ProjectEnvironment | null>(null);
    const [deletingEnv, setDeletingEnv] = useState<ProjectEnvironment | null>(null);
    const [deletingProject, setDeletingProject] = useState(false);
    const [error, setError] = useState<string | undefined>();
    const production = project.environments.find((env) => env.is_production) ?? project.environments[0];

    // Deep links from the environment switcher / ⌘K: #environments scrolls, #new-environment opens the dialog.
    useEffect(() => {
        const hash = window.location.hash;
        if (hash === '#environments' || hash === '#new-environment') document.getElementById('environments')?.scrollIntoView();
        if (hash === '#new-environment' && can.manage) setCreating({ from: null });
    }, [can.manage]);

    const saveGeneral = (event: FormEvent) => {
        event.preventDefault();
        general.patch(`/projects/${project.id}`, { preserveScroll: true });
    };

    const deleteEnvironment = (environment: ProjectEnvironment) =>
        new Promise<void>((resolve) => {
            router.delete(`/projects/${project.id}/environments/${environment.id}`, {
                preserveScroll: true,
                onSuccess: () => setDeletingEnv(null),
                onError: (errors) => setError(errors.environment ?? Object.values(errors)[0]),
                onFinish: () => resolve(),
            });
        });

    const deleteProject = (confirm: string) =>
        new Promise<void>((resolve) => {
            router.delete(`/projects/${project.id}`, {
                data: { confirm },
                onError: (errors) => setError(errors.project ?? errors.confirm ?? Object.values(errors)[0]),
                onFinish: () => resolve(),
            });
        });

    return (
        <AppShell>
            <Head title={`Settings · ${project.name}`} />
            <div className="mx-auto grid w-full max-w-3xl gap-8">
                <div className="grid gap-3">
                    {production && (
                        <Link
                            href={canvasUrl(project.id, production.slug)}
                            className="text-fg-muted hover:text-fg flex w-fit items-center gap-1 text-xs"
                        >
                            <ArrowLeft className="size-3.5" aria-hidden /> Back to canvas
                        </Link>
                    )}
                    <PageHeader title="Project settings" description={project.name} />
                </div>

                <Section
                    id="general"
                    title="General"
                    description="How the project appears in the switcher and on the projects grid."
                    footer={
                        can.manage && (
                            <Button variant="primary" type="submit" form="project-general" loading={general.processing} disabled={!general.isDirty}>
                                Save
                            </Button>
                        )
                    }
                >
                    <form id="project-general" onSubmit={saveGeneral} className="grid gap-4">
                        <Field label="Name" error={general.errors.name}>
                            <Input
                                value={general.data.name}
                                onChange={(event) => general.setData('name', event.target.value)}
                                disabled={!can.manage}
                            />
                        </Field>
                        <Field label="Description" error={general.errors.description}>
                            <Textarea
                                value={general.data.description}
                                onChange={(event) => general.setData('description', event.target.value)}
                                rows={2}
                                disabled={!can.manage}
                            />
                        </Field>
                    </form>
                </Section>

                <Section
                    id="environments"
                    title="Environments"
                    description="Each environment has its own canvas, services and variables. Duplicating copies service configs and variables, not servers."
                    aside={
                        can.manage && (
                            <Button size="sm" icon={<Plus />} onClick={() => setCreating({ from: null })}>
                                New environment
                            </Button>
                        )
                    }
                    bare
                >
                    <DataTable
                        label="Environments"
                        rows={project.environments}
                        rowKey={(env) => env.id}
                        onRowClick={(env) => router.visit(canvasUrl(project.id, env.slug))}
                        columns={[
                            {
                                id: 'name',
                                header: 'Name',
                                cell: (env) => (
                                    <span className="flex items-center gap-2">
                                        <span className="text-fg font-medium">{env.name}</span>
                                        {env.is_production && <Tag tone="success">production</Tag>}
                                        {env.forked_from_id && (
                                            <span className="text-fg-faint text-xs">
                                                from {project.environments.find((other) => other.id === env.forked_from_id)?.name ?? 'deleted'}
                                            </span>
                                        )}
                                    </span>
                                ),
                            },
                            { id: 'slug', header: 'Slug', hideOnMobile: true, cell: (env) => <span className="font-mono text-xs">{env.slug}</span> },
                            {
                                id: 'services',
                                header: 'Services',
                                align: 'right',
                                cell: (env) => <span className="tabular">{env.services_count ?? 0}</span>,
                            },
                            {
                                id: 'created',
                                header: 'Created',
                                hideOnMobile: true,
                                cell: (env) => <RelativeTime value={env.created_at} className="text-fg-muted text-xs" />,
                            },
                        ]}
                        rowActions={(env) => [
                            { label: 'Open canvas', icon: <ExternalLink />, href: canvasUrl(project.id, env.slug) },
                            ...(can.manage
                                ? [
                                      { label: 'Rename', icon: <Pencil />, onSelect: () => setRenaming(env) },
                                      { label: 'Duplicate', icon: <Copy />, onSelect: () => setCreating({ from: env.id }) },
                                      { type: 'separator' as const },
                                      {
                                          label: 'Delete',
                                          icon: <Trash2 />,
                                          danger: true,
                                          disabled: env.is_production,
                                          onSelect: () => {
                                              setError(undefined);
                                              setDeletingEnv(env);
                                          },
                                      },
                                  ]
                                : []),
                        ]}
                    />
                </Section>

                <ConfigSection projectId={project.id} />

                <Section
                    id="secrets"
                    title="Secrets"
                    description="API keys and passwords for the project, its environments and services, referenced from variables as ${{ secrets.NAME }}."
                    aside={
                        <Button asChild>
                            <Link href={`/projects/${project.id}/settings/secrets`}>
                                <KeyRound aria-hidden /> Manage secrets
                            </Link>
                        </Button>
                    }
                    bare
                />

                <Section
                    id="volumes"
                    title="Volumes"
                    description="The persistent data of the project's services: usage, backups, files."
                    aside={
                        <Button asChild>
                            <Link href={`/projects/${project.id}/volumes`}>
                                <HardDrive aria-hidden /> Open volumes
                            </Link>
                        </Button>
                    }
                    bare
                />

                {can.manage && (
                    <Section id="danger" title="Danger zone" tone="danger">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div className="grid gap-0.5">
                                <span className="text-fg text-sm font-medium">Delete this project</span>
                                <span className="text-fg-muted text-sm">
                                    {project.is_default
                                        ? 'The default project holds services created outside a project and cannot be deleted.'
                                        : 'Removes the project and its environments. Delete its services first.'}
                                </span>
                            </div>
                            <Button
                                variant="danger"
                                icon={<Trash2 />}
                                disabled={project.is_default}
                                onClick={() => {
                                    setError(undefined);
                                    setDeletingProject(true);
                                }}
                            >
                                Delete project
                            </Button>
                        </div>
                    </Section>
                )}
            </div>

            <NewEnvironmentDialog
                projectId={project.id}
                environments={project.environments}
                open={creating !== null}
                from={creating?.from ?? null}
                onOpenChange={(open) => !open && setCreating(null)}
            />
            <RenameEnvironmentDialog projectId={project.id} environment={renaming} onClose={() => setRenaming(null)} />
            <ConfirmDestructive
                open={deletingEnv !== null}
                onOpenChange={(open) => !open && setDeletingEnv(null)}
                title={`Delete ${deletingEnv?.name ?? 'environment'}?`}
                description="The environment and its canvas are removed. Services must be deleted first."
                confirmText={deletingEnv?.name ?? ''}
                onConfirm={() => (deletingEnv ? deleteEnvironment(deletingEnv) : undefined)}
                error={error}
            />
            <ConfirmDestructive
                open={deletingProject}
                onOpenChange={setDeletingProject}
                title={`Delete ${project.name}?`}
                description="This removes the project and all of its environments. It cannot be undone."
                confirmText={project.name}
                confirmLabel="Delete project"
                onConfirm={deleteProject}
                error={error}
            />
        </AppShell>
    );
}
