import { Button, Field, Input, ManagedBadge, Skeleton, managedCovers, toast } from '@/components/falak';
import { useJson } from '@/hooks/use-json';
import { HttpError, errorMessage, requestJson } from '@/lib/http';
import { type ServiceTabProps } from '@/lib/registry';
import { Save } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import { functionUrl, type FunctionSettings, type FunctionState } from '../types';

const FIELDS: { key: keyof FunctionSettings; label: string; hint: string; path: string; step?: string; unit?: string }[] = [
    {
        key: 'min_instances',
        path: '/scaling/min_instances',
        label: 'Min instances',
        hint: '0 scales to zero when idle (the next request waits for a cold start).',
    },
    { key: 'max_instances', path: '/scaling/max_instances', label: 'Max instances', hint: 'Upper bound under load, on this server.' },
    { key: 'concurrency', path: '/scaling/concurrency', label: 'Concurrency', hint: 'Requests one instance handles at once before another starts.' },
    {
        key: 'idle_timeout_s',
        path: '/scaling/idle_timeout',
        label: 'Idle timeout',
        unit: 's',
        hint: 'An instance without requests this long is stopped.',
    },
    { key: 'memory_mb', path: '/memory', label: 'Memory', unit: 'MB', hint: 'Per instance.' },
    { key: 'cpus', path: '/cpus', label: 'CPU', step: '0.1', unit: 'cores', hint: 'Per instance.' },
    { key: 'request_timeout_s', path: '/timeout', label: 'Request timeout', unit: 's', hint: 'Longer requests get a 504.' },
];

/** Settings → Scaling of a function: saving redeploys the live version with the new values. */
export function ScalingSettings({ ctx }: ServiceTabProps) {
    const siteId = ctx.service.ref_id;
    const state = useJson<FunctionState>(functionUrl(siteId));
    const [values, setValues] = useState<Record<string, string>>({});
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (state.data) setValues(Object.fromEntries(Object.entries(state.data.settings).map(([key, value]) => [key, String(value)])));
    }, [state.data]);

    if (!state.data) return <Skeleton className="h-64" />;
    const data = state.data;
    const dirty = FIELDS.some(({ key }) => values[key] !== undefined && Number(values[key]) !== data.settings[key]);

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setSaving(true);
        setErrors({});
        try {
            const body = Object.fromEntries(FIELDS.map(({ key }) => [key, Number(values[key])]));
            const response = await requestJson<{ data: { settings: FunctionSettings; deployment_id: string | null } }>(
                functionUrl(siteId, '/settings'),
                'PUT',
                body,
            );
            state.setData({ ...data, settings: response.data.settings });
            toast.success('Scaling saved', response.data.deployment_id ? 'Redeploying the live version with the new settings.' : undefined);
            ctx.refresh();
        } catch (error) {
            if (error instanceof HttpError && Object.keys(error.errors).length > 0) setErrors(error.errors);
            else toast.error('Could not save', errorMessage(error));
        } finally {
            setSaving(false);
        }
    };

    return (
        <form onSubmit={submit} className="grid gap-4">
            {FIELDS.some(({ path }) => managedCovers(data.managed, path)) && (
                <div>
                    <ManagedBadge config={data.managed} />
                </div>
            )}
            <div className="grid gap-4 sm:grid-cols-2">
                {FIELDS.map(({ key, label, hint, path, step, unit }) => {
                    const [min, max] = data.bounds[key] ?? [0, 0];

                    return (
                        <Field key={key} label={unit ? `${label} (${unit})` : label} hint={hint} error={errors[key]}>
                            <Input
                                type="number"
                                min={min}
                                max={max}
                                step={step ?? '1'}
                                value={values[key] ?? ''}
                                disabled={!data.can.deploy || managedCovers(data.managed, path)}
                                onChange={(event) => setValues((current) => ({ ...current, [key]: event.target.value }))}
                            />
                        </Field>
                    );
                })}
            </div>
            <div className="flex justify-end">
                <Button type="submit" variant="primary" icon={<Save />} loading={saving} disabled={!dirty || !data.can.deploy}>
                    Save scaling
                </Button>
            </div>
        </form>
    );
}
