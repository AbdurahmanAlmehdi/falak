import { Button, Callout, CopyButton, Field, Input, Section, Select, Switch, Textarea } from '@/components/falak';
import ObservabilityLayout from '@/layouts/observability-layout';
import { HttpError, requestJson } from '@/lib/http';
import { router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ExternalLink, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface PageMonitor {
    id: string;
    display_name: string;
}

interface Project {
    id: string;
    name: string;
    page: { id: string; slug: string; title: string; description: string | null; enabled: boolean; url: string; monitors: PageMonitor[] } | null;
}

interface Props {
    projects: Project[];
    monitors: Array<{ id: string; name: string }>;
    can: { publish: boolean };
}

function slugify(value: string): string {
    return value
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 64);
}

function StatusPageForm({ project, monitors, canPublish }: { project: Project; monitors: Props['monitors']; canPublish: boolean }) {
    const [form, setForm] = useState({
        slug: project.page?.slug ?? slugify(project.name),
        title: project.page?.title ?? `${project.name} status`,
        description: project.page?.description ?? '',
        enabled: project.page?.enabled ?? false,
        monitors: project.page?.monitors ?? [],
    });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const unused = monitors.filter((monitor) => !form.monitors.some((chosen) => chosen.id === monitor.id));

    const move = (index: number, by: number) =>
        setForm((current) => {
            const list = [...current.monitors];
            const [item] = list.splice(index, 1);
            list.splice(Math.max(0, Math.min(list.length, index + by)), 0, item);

            return { ...current, monitors: list };
        });

    const save = async () => {
        setSaving(true);
        setErrors({});
        try {
            await requestJson(`/uptime/status-pages/${project.id}`, 'PUT', { ...form, description: form.description || null });
            router.reload({ only: ['projects'] });
        } catch (error) {
            setErrors(error instanceof HttpError ? { form: error.message, ...error.errors } : { form: 'Could not save' });
        } finally {
            setSaving(false);
        }
    };

    const remove = async () => {
        if (!project.page) return;
        await requestJson(`/uptime/status-pages/${project.page.id}`, 'DELETE');
        router.reload({ only: ['projects'] });
    };

    return (
        <Section
            title={project.name}
            description={project.page ? (project.page.enabled ? 'Public' : 'Not published') : 'No status page'}
            aside={
                project.page?.enabled && (
                    <span className="flex items-center gap-1">
                        <CopyButton value={project.page.url} />
                        <Button asChild size="sm" variant="ghost" icon={<ExternalLink />}>
                            <a href={project.page.url} target="_blank" rel="noreferrer">
                                Open
                            </a>
                        </Button>
                    </span>
                )
            }
            footer={
                canPublish && (
                    <div className="flex justify-end gap-2">
                        {project.page && (
                            <Button variant="ghost" icon={<Trash2 />} onClick={() => void remove()}>
                                Delete page
                            </Button>
                        )}
                        <Button variant="primary" loading={saving} onClick={() => void save()}>
                            Save
                        </Button>
                    </div>
                )
            }
        >
            <fieldset disabled={!canPublish} className="grid gap-4">
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Title" error={errors.title}>
                        <Input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} maxLength={120} />
                    </Field>
                    <Field label="Address" error={errors.slug} hint="Lower-case letters, digits and dashes.">
                        <Input prefix="/status/" value={form.slug} onChange={(e) => setForm({ ...form, slug: e.target.value })} mono />
                    </Field>
                </div>
                <Field label="Description" error={errors.description}>
                    <Textarea rows={2} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} maxLength={500} />
                </Field>
                <Field
                    label="Published"
                    inline
                    hint="Anyone with the link sees the names below, their state, daily uptime and incident times. Nothing else."
                >
                    <Switch checked={form.enabled} onCheckedChange={(enabled) => setForm({ ...form, enabled })} />
                </Field>
                <div className="grid gap-2">
                    <span className="text-sm font-medium">Components</span>
                    {form.monitors.map((chosen, index) => (
                        <div key={chosen.id} className="flex items-center gap-2">
                            <Input
                                aria-label="Display name"
                                value={chosen.display_name}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        monitors: form.monitors.map((m) => (m.id === chosen.id ? { ...m, display_name: e.target.value } : m)),
                                    })
                                }
                                maxLength={120}
                            />
                            <span className="text-fg-faint w-48 truncate text-xs">{monitors.find((m) => m.id === chosen.id)?.name}</span>
                            <Button size="sm" variant="ghost" aria-label="Up" icon={<ArrowUp />} onClick={() => move(index, -1)} />
                            <Button size="sm" variant="ghost" aria-label="Down" icon={<ArrowDown />} onClick={() => move(index, 1)} />
                            <Button
                                size="sm"
                                variant="ghost"
                                aria-label="Remove"
                                icon={<Trash2 />}
                                onClick={() => setForm({ ...form, monitors: form.monitors.filter((m) => m.id !== chosen.id) })}
                            />
                        </div>
                    ))}
                    {unused.length > 0 && canPublish && (
                        <div className="flex items-center gap-2">
                            <Plus className="text-fg-faint size-4" />
                            <Select
                                value={undefined}
                                placeholder="Add a monitor…"
                                onValueChange={(id) =>
                                    // The page shows only the display name: it starts empty so nothing internal is published by accident.
                                    setForm({ ...form, monitors: [...form.monitors, { id, display_name: '' }] })
                                }
                                options={unused.map((monitor) => ({ value: monitor.id, label: monitor.name }))}
                            />
                        </div>
                    )}
                    {errors.form && <Callout tone="danger">{errors.form}</Callout>}
                </div>
            </fieldset>
        </Section>
    );
}

/** /observability/uptime/status-pages: one opt-in public page per project. */
export default function StatusPages({ projects, monitors, can }: Props) {
    return (
        <ObservabilityLayout
            tab="uptime"
            title="Status pages · Uptime"
            breadcrumbs={[{ title: 'Status pages', href: '/observability/uptime/status-pages' }]}
        >
            <Callout>
                Status pages are served by the control plane at <code>/status/&lt;address&gt;</code>, cached for a minute. Custom domains are not
                supported yet.
            </Callout>
            {projects.map((project) => (
                <StatusPageForm key={project.id} project={project} monitors={monitors} canPublish={can.publish} />
            ))}
        </ObservabilityLayout>
    );
}
