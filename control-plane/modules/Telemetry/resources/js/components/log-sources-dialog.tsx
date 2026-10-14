import { Button, Callout, Dialog, Field, IconButton, Input, Select, SkeletonRows, toast } from '@/components/falak';
import { useJson } from '@/hooks/use-json';
import { HttpError, errorMessage, requestJson } from '@/lib/http';
import { Plus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { cleanSources, emptySource, rowErrors, type LogSource } from '../log-sources';

/** GET /sites/{site}/log-sources (Falak\Sites\Http\Controllers\SiteLogSourcesController). */
interface LogSourcesData {
    runtime: string;
    containers: boolean;
    php_fpm: boolean;
    sources: LogSource[];
    compose_services: string[];
    slowlog_timeout: number | null;
    limits: { sources: number; slowlog_timeout: number; default_slowlog_timeout: number; pattern_bytes: number };
    can: { update: boolean };
}

const ALL_SERVICES = '__all__';
const NO_MULTILINE = '__none__';

/**
 * Site → Logs → Sources (docs/plans/OBSERVABILITY.md phase 2): files inside a Docker or compose site's containers the
 * agent tails (through the container's own root, never the host), and a PHP-FPM site's slow log threshold.
 */
export function LogSourcesDialog({ siteId, open, onOpenChange }: { siteId: string; open: boolean; onOpenChange: (open: boolean) => void }) {
    const url = `/sites/${siteId}/log-sources`;
    const { data, reload } = useJson<LogSourcesData>(open ? url : null);
    const [sources, setSources] = useState<LogSource[]>([]);
    const [slowlog, setSlowlog] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (!data || !open) return;
        setSources(data.sources);
        setSlowlog(data.slowlog_timeout === null ? '' : String(data.slowlog_timeout));
        setErrors({});
    }, [data, open]);

    const readOnly = !data?.can.update;
    const update = (index: number, patch: Partial<LogSource>) =>
        setSources((rows) => rows.map((row, i) => (i === index ? { ...row, ...patch } : row)));

    const save = async () => {
        if (!data) return;
        setSaving(true);
        setErrors({});
        const body: Record<string, unknown> = {};
        if (data.containers) body.sources = cleanSources(sources);
        if (data.php_fpm) body.slowlog_timeout = slowlog.trim() === '' ? null : Number(slowlog);
        try {
            await requestJson(url, 'PUT', body);
            toast.success('Log sources saved — the agents pick them up within a minute');
            await reload();
            onOpenChange(false);
        } catch (e) {
            if (e instanceof HttpError && Object.keys(e.errors).length) setErrors(e.errors);
            else toast.error('Could not save the log sources', errorMessage(e));
        } finally {
            setSaving(false);
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={onOpenChange}
            size="lg"
            title="Log sources"
            description="Files the agent ships as this site's logs, besides its output and the edge's requests."
            footer={
                data &&
                !readOnly && (
                    <>
                        <Button size="sm" variant="ghost" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button size="sm" variant="primary" loading={saving} onClick={() => void save()} data-testid="log-sources-save">
                            Save
                        </Button>
                    </>
                )
            }
        >
            {!data ? (
                <SkeletonRows rows={3} />
            ) : (
                <div className="grid gap-4">
                    {data.containers && (
                        <section className="grid gap-3" aria-label="Files inside the containers">
                            <p className="text-fg-muted text-sm">
                                Paths inside the site&apos;s containers: absolute, globs allowed (<code className="font-mono">*.log</code>). They are
                                read through each container&apos;s own filesystem, followed across restarts and rotation.
                            </p>
                            {errors.sources && <p className="text-danger text-xs">{errors.sources}</p>}
                            {sources.length === 0 && <p className="text-fg-muted text-sm">No files yet.</p>}
                            <ul className="grid gap-3" aria-label="Sources">
                                {sources.map((source, index) => {
                                    const rowError = rowErrors(errors, index);

                                    return (
                                        <li key={index} className="border-border grid gap-2 rounded-md border p-3" data-testid="log-source-row">
                                            <div className="flex items-start gap-2">
                                                <Field label="Path" error={rowError.path} className="flex-1">
                                                    <Input
                                                        mono
                                                        value={source.path}
                                                        placeholder="/var/www/html/storage/logs/*.log"
                                                        disabled={readOnly}
                                                        onChange={(event) => update(index, { path: event.target.value })}
                                                    />
                                                </Field>
                                                {!readOnly && (
                                                    <IconButton
                                                        className="mt-6"
                                                        label="Remove source"
                                                        icon={<Trash2 className="size-4" />}
                                                        onClick={() => setSources((rows) => rows.filter((_, i) => i !== index))}
                                                    />
                                                )}
                                            </div>
                                            <div className="grid gap-2 sm:grid-cols-3">
                                                <Field label="Format" error={rowError.format}>
                                                    <Select
                                                        size="sm"
                                                        value={source.format}
                                                        disabled={readOnly}
                                                        onValueChange={(format) => update(index, { format })}
                                                        options={[
                                                            { value: 'plain', label: 'Plain text' },
                                                            { value: 'json', label: 'JSON lines' },
                                                        ]}
                                                    />
                                                </Field>
                                                <Field label="Multi-line" error={rowError.multiline}>
                                                    <Select
                                                        size="sm"
                                                        value={source.multiline ?? NO_MULTILINE}
                                                        disabled={readOnly}
                                                        onValueChange={(value) =>
                                                            update(index, {
                                                                multiline: value === NO_MULTILINE ? undefined : (value as LogSource['multiline']),
                                                            })
                                                        }
                                                        options={[
                                                            { value: NO_MULTILINE, label: 'One line per record' },
                                                            { value: 'laravel', label: 'Laravel (stack traces)' },
                                                            { value: 'pattern', label: 'Start pattern' },
                                                        ]}
                                                    />
                                                </Field>
                                                {data.compose_services.length > 0 && (
                                                    <Field label="Service" error={rowError.compose_service}>
                                                        <Select
                                                            size="sm"
                                                            value={source.compose_service ?? ALL_SERVICES}
                                                            disabled={readOnly}
                                                            onValueChange={(value) =>
                                                                update(index, { compose_service: value === ALL_SERVICES ? undefined : value })
                                                            }
                                                            options={[
                                                                { value: ALL_SERVICES, label: 'Every service' },
                                                                ...data.compose_services.map((name) => ({ value: name, label: name })),
                                                            ]}
                                                        />
                                                    </Field>
                                                )}
                                            </div>
                                            {source.multiline === 'pattern' && (
                                                <Field
                                                    label="Start pattern"
                                                    hint="A regular expression the first line of a record matches; other lines join the record before them."
                                                    error={rowError.multiline_pattern}
                                                >
                                                    <Input
                                                        mono
                                                        value={source.multiline_pattern ?? ''}
                                                        placeholder="^\[\d{4}-\d{2}-\d{2}"
                                                        maxLength={data.limits.pattern_bytes}
                                                        disabled={readOnly}
                                                        onChange={(event) => update(index, { multiline_pattern: event.target.value })}
                                                    />
                                                </Field>
                                            )}
                                        </li>
                                    );
                                })}
                            </ul>
                            {!readOnly && sources.length < data.limits.sources && (
                                <div>
                                    <Button size="sm" variant="ghost" onClick={() => setSources((rows) => [...rows, emptySource()])}>
                                        <Plus className="size-4" /> Add a file
                                    </Button>
                                </div>
                            )}
                        </section>
                    )}
                    {data.php_fpm && (
                        <Field
                            label="Slow request threshold (seconds)"
                            hint={`PHP-FPM writes the backtrace of a request running longer to the slow log (Slow requests). 0 or empty: off (new sites start at ${data.limits.default_slowlog_timeout} s).`}
                            error={errors.slowlog_timeout}
                        >
                            <Input
                                type="number"
                                min={0}
                                max={data.limits.slowlog_timeout}
                                className="w-32"
                                value={slowlog}
                                disabled={readOnly}
                                onChange={(event) => setSlowlog(event.target.value)}
                            />
                        </Field>
                    )}
                    {!data.containers && !data.php_fpm && (
                        <Callout tone="info">
                            This site&apos;s logs need no sources: its output and the edge&apos;s requests are shipped as they are.
                        </Callout>
                    )}
                </div>
            )}
        </Dialog>
    );
}
