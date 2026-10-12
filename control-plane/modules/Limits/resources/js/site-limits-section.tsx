import { Button, ManagedBadge, Section, SkeletonRows, Tag, managedCovers, toast, type ManagedConfig } from '@/components/falak';
import { LimitsFields, cleanLimits, limitsSummary, type ResourceLimits } from '@/components/limits-fields';
import { useJson } from '@/hooks/use-json';
import { HttpError, errorMessage, requestJson } from '@/lib/http';
import { type ServiceTabProps } from '@/lib/registry';
import { useEffect, useState } from 'react';

/** GET /sites/{site}/limits. */
interface SiteLimitsData {
    runtime: string;
    scope: 'site' | 'services' | 'policy' | 'none';
    limits: ResourceLimits;
    effective: ResourceLimits;
    /** What a new service of this environment starts with. */
    defaults: ResourceLimits;
    services: { name: string; limits: ResourceLimits; effective: ResourceLimits }[];
    bounds: { memory_mb: number | null; cpus: number | null; min_memory_mb: number };
    can: { update: boolean };
    /** Limits the project's falak.yaml sets (/limits/...): the site's limits are then read-only here. */
    config: ManagedConfig | null;
}

const APPLIED: Record<string, string> = {
    live: 'Applied to the running service',
    redeploy: 'Saved — the next deploy applies them',
    none: 'Saved',
};

function LimitsEditor({
    url,
    initial,
    bounds,
    policyOnly,
    canUpdate,
    onSaved,
}: {
    url: string;
    initial: ResourceLimits;
    bounds: SiteLimitsData['bounds'];
    policyOnly: boolean;
    canUpdate: boolean;
    onSaved: () => void;
}) {
    const [value, setValue] = useState<ResourceLimits>(initial);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    useEffect(() => setValue(initial), [initial]);

    const save = async () => {
        setSaving(true);
        setErrors({});
        try {
            const body = await requestJson<{ data: { applied: string } }>(url, 'PUT', { limits: cleanLimits(value) });
            toast.success(APPLIED[body?.data.applied ?? 'none'] ?? 'Saved');
            onSaved();
        } catch (e) {
            if (e instanceof HttpError && Object.keys(e.errors).length) setErrors(e.errors);
            else toast.error('Could not save the limits', errorMessage(e));
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="grid gap-3">
            <LimitsFields value={value} onChange={setValue} errors={errors} bounds={bounds} policyOnly={policyOnly} disabled={!canUpdate} />
            {(errors.limits || errors.service) && <p className="text-danger text-xs">{errors.limits ?? errors.service}</p>}
            {canUpdate && (
                <div className="flex justify-end">
                    <Button size="sm" variant="primary" loading={saving} onClick={save}>
                        Save limits
                    </Button>
                </div>
            )}
        </div>
    );
}

/**
 * Settings → Resources: the site's limits (its container, or its slice: PHP-FPM, Octane, the web process), or each
 * compose service's. Memory, CPUs, processes and the restart policy apply live; log caps and the OOM preference on the
 * next deploy.
 */
export function SiteLimitsSection({ ctx }: ServiceTabProps) {
    const url = `/sites/${ctx.service.ref_id}/limits`;
    const { data, reload } = useJson<SiteLimitsData>(url);
    const [open, setOpen] = useState<string | null>(null);

    if (!data) return <SkeletonRows rows={3} />;
    if (data.scope === 'none') return null;

    const description =
        data.scope === 'policy'
            ? 'FrankenPHP sites run inside the shared edge: only the restart, log and OOM settings of their processes apply.'
            : 'Limits keep one service from starving the others on its servers. Empty fields mean no limit.';

    // The editor saves every field at once, so limits the file sets lock the whole site-level editor.
    const managed = data.scope !== 'services' && managedCovers(data.config, '/limits', true);

    return (
        <Section title="Resource limits" description={description} aside={managed ? <ManagedBadge config={data.config} /> : undefined}>
            {data.scope === 'services' ? (
                <ul className="border-border divide-border divide-y rounded-md border" aria-label="Compose services">
                    {data.services.map((service) => (
                        <li key={service.name} className="grid gap-3 px-3 py-2.5">
                            <div className="flex items-center justify-between gap-3">
                                <span className="text-fg font-mono text-sm">{service.name}</span>
                                <span className="flex items-center gap-2">
                                    <Tag mono>{limitsSummary(service.effective) ?? 'no limits'}</Tag>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => setOpen(open === service.name ? null : service.name)}
                                        aria-expanded={open === service.name}
                                    >
                                        {open === service.name ? 'Close' : 'Edit'}
                                    </Button>
                                </span>
                            </div>
                            {open === service.name && (
                                <LimitsEditor
                                    url={`/sites/${ctx.service.ref_id}/compose/services/${encodeURIComponent(service.name)}/limits`}
                                    initial={service.limits}
                                    bounds={data.bounds}
                                    policyOnly={false}
                                    canUpdate={data.can.update}
                                    onSaved={() => void reload()}
                                />
                            )}
                        </li>
                    ))}
                    {data.services.length === 0 && <li className="text-fg-muted px-3 py-2.5 text-sm">The compose project has no services yet.</li>}
                </ul>
            ) : (
                <LimitsEditor
                    url={url}
                    initial={data.limits}
                    bounds={data.bounds}
                    policyOnly={data.scope === 'policy'}
                    canUpdate={data.can.update && !managed}
                    onSaved={() => void reload()}
                />
            )}
        </Section>
    );
}
