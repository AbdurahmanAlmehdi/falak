import { domainPayload, DomainPicker, type DomainChoice } from '@/components/domain-picker';
import { Button, Callout, Field, Input, Select, ServiceIcon, Skeleton, Tag, toast } from '@/components/falak';
import { useJson } from '@/hooks/use-json';
import { errorMessage, HttpError, requestJson } from '@/lib/http';
import { type CreateOptionProps } from '@/lib/registry';
import { cn } from '@/lib/utils';
import { type Canvas } from '@/types';
import { Link } from '@inertiajs/react';
import { CalendarClock, KeyRound, Plus } from 'lucide-react';
import { useMemo, useState, type FormEvent } from 'react';
import { type StarterCatalog } from '../types';

interface SiteOptions {
    options: { servers: { id: string; name: string; type_label: string; status: string; docker: boolean; functions?: boolean }[] };
}

function slugify(value: string): string {
    return (
        value
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .slice(0, 50) || 'function'
    );
}

/**
 * Create picker → Function: name, server, starter and domain. The function is created as a draft with the starter as
 * v1 (`deploy: false`); its panel opens on the Code tab to edit code, variables, schedules and settings, and nothing
 * runs until Deploy there.
 */
export function FunctionStep({ projectId, environmentSlug, position, onCreated }: CreateOptionProps) {
    const sites = useJson<SiteOptions>('/sites/create');
    const catalog = useJson<StarterCatalog>('/functions/starters');
    const [runtime, setRuntime] = useState<string | null>(null);
    const [name, setName] = useState('');
    const [serverId, setServerId] = useState<string | null>(null);
    const [starter, setStarter] = useState('hello');
    const [domain, setDomain] = useState<DomainChoice | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [submitting, setSubmitting] = useState(false);

    const servers = useMemo(() => (sites.data?.options.servers ?? []).filter((server) => server.docker), [sites.data]);
    const capable = servers.filter((server) => server.functions);
    const selected = serverId ?? capable.find((server) => server.status === 'active')?.id ?? capable[0]?.id ?? null;
    const runtimes = catalog.data?.runtimes ?? [];
    const chosenRuntime = runtimes.find((r) => r.key === (runtime ?? catalog.data?.default_runtime)) ?? runtimes[0];
    const available = (catalog.data?.starters ?? []).filter((s) => !chosenRuntime || s.families.includes(chosenRuntime.family));
    const categories = [...new Set(available.map((s) => s.category))];
    const chosenStarter = available.find((s) => s.key === starter) ?? available[0];

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        if (!selected) return;
        setSubmitting(true);
        setErrors({});
        try {
            const response = await requestJson<{
                data: { site_id: string; name: string; deployment_id: string | null; panel_url: string | null };
                warnings: string[];
            }>(`/projects/${projectId}/${environmentSlug}/functions`, 'POST', {
                name: name.trim(),
                server_id: selected,
                starter: chosenStarter?.key ?? starter,
                runtime: chosenRuntime?.key,
                domain: domainPayload(domain),
                position,
                deploy: false,
            });
            response.warnings.forEach((warning) => toast.warning(warning));
            const canvas = await requestJson<Canvas>(`/projects/${projectId}/${environmentSlug}/canvas`);
            const card = canvas.services.find((service) => service.kind === 'site' && service.ref_id === response.data.site_id);
            toast.success(`${response.data.name} created`, 'Not deployed yet: edit it, then Deploy.');
            if (card) onCreated(card, null, 'code');
            else if (response.data.panel_url) window.location.assign(response.data.panel_url);
        } catch (error) {
            setErrors(error instanceof HttpError && Object.keys(error.errors).length > 0 ? error.errors : { form: errorMessage(error) });
        } finally {
            setSubmitting(false);
        }
    };

    if (!sites.data || !catalog.data) return <Skeleton className="m-4 h-64" />;

    const shown = ['name', 'server_id', 'server_ids', 'starter', 'runtime', 'domain'];
    const other = Object.entries(errors)
        .filter(([key]) => !shown.includes(key))
        .map(([, message]) => message);

    return (
        <form onSubmit={submit} className="grid gap-4 p-4">
            <Field label="Name" error={errors.name}>
                <Input autoFocus value={name} placeholder="webhooks" maxLength={64} onChange={(event) => setName(event.target.value)} />
            </Field>

            <Field label="Runtime" error={errors.runtime}>
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    {runtimes.map((option) => (
                        <button
                            key={option.key}
                            type="button"
                            onClick={() => setRuntime(option.key)}
                            aria-pressed={chosenRuntime?.key === option.key}
                            className={cn(
                                'border-border hover:border-border-strong flex items-center gap-2 rounded-md border px-3 py-2 text-left',
                                chosenRuntime?.key === option.key && 'border-primary bg-primary-soft',
                            )}
                        >
                            <ServiceIcon name={option.key === 'node' ? 'nodedotjs' : option.key} size={18} />
                            <span className="grid">
                                <span className="text-fg text-sm font-medium">{option.label}</span>
                                <span className="text-fg-faint font-mono text-[11px]">{option.entrypoint}</span>
                            </span>
                        </button>
                    ))}
                </div>
            </Field>

            <Field label="Starter" error={errors.starter}>
                <div className="grid max-h-[340px] gap-3 overflow-auto pr-1">
                    {categories.map((category) => (
                        <div key={category} className="grid gap-1.5">
                            <span className="text-fg-faint text-[11px] font-medium tracking-wide uppercase">{category}</span>
                            <div className="grid gap-2 sm:grid-cols-2">
                                {available
                                    .filter((option) => option.category === category)
                                    .map((option) => (
                                        <button
                                            key={option.key}
                                            type="button"
                                            onClick={() => setStarter(option.key)}
                                            aria-pressed={chosenStarter?.key === option.key}
                                            className={cn(
                                                'border-border hover:border-border-strong grid gap-1 rounded-md border p-3 text-left',
                                                chosenStarter?.key === option.key && 'border-primary bg-primary-soft',
                                            )}
                                        >
                                            <span className="text-fg text-sm font-medium">{option.title}</span>
                                            <span className="text-fg-muted text-xs">{option.description}</span>
                                            {(option.schedule || option.variables.length > 0) && (
                                                <span className="mt-1 flex flex-wrap gap-1">
                                                    {option.schedule && (
                                                        <Tag tone="neutral" icon={<CalendarClock />}>
                                                            {option.schedule.name}
                                                        </Tag>
                                                    )}
                                                    {option.variables.length > 0 && (
                                                        <Tag tone="neutral" icon={<KeyRound />} title={option.variables.join(', ')}>
                                                            {option.variables.length} variable{option.variables.length === 1 ? '' : 's'}
                                                        </Tag>
                                                    )}
                                                </span>
                                            )}
                                        </button>
                                    ))}
                            </div>
                        </div>
                    ))}
                </div>
            </Field>

            {chosenStarter && chosenStarter.variables.length > 0 && (
                <p className="text-fg-muted -mt-2 text-xs">
                    Creates the variables <span className="text-fg font-mono">{chosenStarter.variables.join(', ')}</span> (secrets are generated);
                    fill in the rest in the Variables tab.
                </p>
            )}

            <Field label="Server" error={errors.server_id ?? errors.server_ids} hint="The function sleeps when idle and wakes on the first request.">
                {capable.length === 0 ? (
                    <Callout tone="warning" title="No server can run functions yet">
                        Functions need Docker and a Falak agent with the function gateway (0.4 or newer).{' '}
                        {servers.length > 0 ? 'Update the agent on a Docker server.' : <Link href="/servers/create">Add a server</Link>}
                    </Callout>
                ) : (
                    <Select
                        value={selected ?? undefined}
                        onValueChange={setServerId}
                        options={capable.map((server) => ({
                            value: server.id,
                            label: `${server.name}${server.status !== 'active' ? ` (${server.status})` : ''}`,
                        }))}
                        aria-label="Server"
                    />
                )}
            </Field>

            {selected && (
                <Field label="Domain" error={errors.domain}>
                    <DomainPicker label={slugify(name)} serverIds={[selected]} value={domain} onChange={setDomain} ariaLabel="Domain" />
                </Field>
            )}

            {other.length > 0 && (
                <Callout tone="danger" title="Could not create the function">
                    {other.join(' ')}
                </Callout>
            )}

            <div className="flex justify-end">
                <Button type="submit" variant="primary" icon={<Plus />} loading={submitting} disabled={!selected || !name.trim()}>
                    Create function
                </Button>
            </div>
        </form>
    );
}
