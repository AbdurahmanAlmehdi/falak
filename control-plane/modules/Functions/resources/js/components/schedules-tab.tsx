import {
    Button,
    Callout,
    Dialog,
    EmptyState,
    Field,
    IconButton,
    Input,
    LogViewer,
    ManagedBadge,
    Select,
    SkeletonRows,
    Switch,
    Tag,
    toast,
    type ManagedConfig,
} from '@/components/falak';
import { useJson } from '@/hooks/use-json';
import { HttpError, errorMessage, requestJson } from '@/lib/http';
import { type ServiceTabProps } from '@/lib/registry';
import { CalendarClock, Pencil, Play, Plus, Trash2 } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import { functionUrl } from '../types';

interface Schedule {
    id: string;
    key: string;
    name: string;
    expression: string;
    timezone: string;
    overlap: 'allow' | 'skip';
    timeout_s: number;
    enabled: boolean;
    job: string;
    /** Set by the project's falak.yaml: read-only here. */
    managed: ManagedConfig | null;
}

interface SchedulesState {
    schedules: Schedule[];
    timezones: string[];
    can: { manage: boolean };
}

interface RunState {
    status: string;
    finished: boolean;
    exit_code: number | null;
    duration_ms: number | null;
    error: string | null;
    output: string;
}

const PRESETS = [
    { value: '*/5 * * * *', label: 'Every 5 minutes' },
    { value: '*/15 * * * *', label: 'Every 15 minutes' },
    { value: '@hourly', label: 'Every hour' },
    { value: '0 3 * * *', label: 'Every day at 03:00' },
    { value: '0 9 * * 1', label: 'Mondays at 09:00' },
    { value: '@monthly', label: 'First day of the month' },
];

/** "every 5 minutes", "daily at 03:00" … for the common shapes; the expression itself otherwise. */
export function describeSchedule(expression: string): string {
    const preset = PRESETS.find((p) => p.value === expression);
    if (preset) return preset.label;
    const named: Record<string, string> = {
        '@hourly': 'Every hour',
        '@daily': 'Every day at 00:00',
        '@weekly': 'Sundays at 00:00',
        '@yearly': 'Every year',
    };
    if (named[expression]) return named[expression];
    const every = /^@every (.+)$/.exec(expression);
    if (every) return `Every ${every[1]}`;
    const [min, hour, dom, mon, dow] = expression.split(' ');
    if (/^\*\/\d+$/.test(min) && hour === '*' && dom === '*' && mon === '*' && dow === '*') return `Every ${min.slice(2)} minutes`;
    if (/^\d+$/.test(min) && /^\d+$/.test(hour) && dom === '*' && mon === '*' && dow === '*')
        return `Every day at ${hour.padStart(2, '0')}:${min.padStart(2, '0')}`;

    return expression;
}

interface ScheduleForm {
    id?: string;
    name: string;
    expression: string;
    timezone: string;
    overlap: 'allow' | 'skip';
    timeout_s: number;
    enabled: boolean;
}

const EMPTY: ScheduleForm = { name: '', expression: '@hourly', timezone: 'UTC', overlap: 'skip', timeout_s: 300, enabled: true };

/**
 * Schedules tab of a function: each schedule runs the function's `scheduled()` handler on its server, in its own
 * container. History and missed runs are in the Observability tab (Scheduled tasks).
 */
export function SchedulesTab({ ctx }: ServiceTabProps) {
    const siteId = ctx.service.ref_id;
    const state = useJson<SchedulesState>(functionUrl(siteId, '/schedules'));
    const [editing, setEditing] = useState<ScheduleForm | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [run, setRun] = useState<{ id: string; name: string } | null>(null);
    const [removing, setRemoving] = useState<Schedule | null>(null);

    const save = async (event: FormEvent) => {
        event.preventDefault();
        if (!editing) return;
        setSaving(true);
        setErrors({});
        try {
            const { id, ...body } = editing;
            await requestJson(functionUrl(siteId, id ? `/schedules/${id}` : '/schedules'), id ? 'PUT' : 'POST', body);
            toast.success(id ? 'Schedule saved' : 'Schedule added', 'The server picks it up in a few seconds.');
            setEditing(null);
            await state.reload();
        } catch (error) {
            if (error instanceof HttpError && Object.keys(error.errors).length > 0) setErrors(error.errors);
            else toast.error('Could not save the schedule', errorMessage(error));
        } finally {
            setSaving(false);
        }
    };

    const toggle = async (schedule: Schedule, enabled: boolean) => {
        try {
            await requestJson(functionUrl(siteId, `/schedules/${schedule.id}`), 'PUT', { ...schedule, enabled });
            await state.reload();
        } catch (error) {
            toast.error('Could not change the schedule', errorMessage(error));
        }
    };

    const remove = async (schedule: Schedule) => {
        try {
            await requestJson(functionUrl(siteId, `/schedules/${schedule.id}`), 'DELETE');
            toast.success(`${schedule.name} removed`);
            await state.reload();
        } catch (error) {
            toast.error('Could not remove the schedule', errorMessage(error));
        }
    };

    const runNow = async (schedule: Schedule) => {
        try {
            const response = await requestJson<{ data: { run_id: string } }>(functionUrl(siteId, `/schedules/${schedule.id}/run`), 'POST');
            setRun({ id: response.data.run_id, name: schedule.name });
        } catch (error) {
            toast.error('Could not start the run', errorMessage(error));
        }
    };

    if (state.error)
        return (
            <Callout tone="danger" title="Could not load the schedules">
                {state.error}
            </Callout>
        );
    if (!state.data) return <SkeletonRows rows={3} />;
    const { schedules, can } = state.data;

    return (
        <div className="grid gap-3">
            <div className="flex items-center justify-between gap-3">
                <p className="text-fg-muted text-xs">
                    Each schedule calls your <code className="text-fg">export async function scheduled(event)</code> on the function’s server, in its
                    own container. Runs and missed runs show under Observability → Scheduled tasks.
                </p>
                {can.manage && (
                    <Button size="sm" variant="primary" icon={<Plus />} onClick={() => setEditing({ ...EMPTY })}>
                        Add schedule
                    </Button>
                )}
            </div>

            {schedules.length === 0 ? (
                <EmptyState
                    icon={<CalendarClock />}
                    title="No schedules"
                    description="Run this function every few minutes, nightly or weekly: cleanups, reports, syncs."
                    action={
                        can.manage ? (
                            <Button size="sm" icon={<Plus />} onClick={() => setEditing({ ...EMPTY })}>
                                Add schedule
                            </Button>
                        ) : undefined
                    }
                />
            ) : (
                <ul className="border-border divide-border divide-y rounded-md border">
                    {schedules.map((schedule) => (
                        <li key={schedule.id} className="flex flex-wrap items-center gap-3 px-3 py-2.5">
                            <Switch
                                checked={schedule.enabled}
                                disabled={!can.manage || schedule.managed !== null}
                                onCheckedChange={(checked) => toggle(schedule, checked)}
                                aria-label={`${schedule.name} enabled`}
                            />
                            <div className="grid min-w-0 flex-1 gap-0.5">
                                <span className="text-fg truncate text-sm font-medium">{schedule.name}</span>
                                <span className="text-fg-muted text-xs">
                                    {describeSchedule(schedule.expression)} <span className="text-fg-faint font-mono">({schedule.expression})</span> ·{' '}
                                    {schedule.timezone} · timeout {schedule.timeout_s}s
                                    {schedule.overlap === 'allow' ? ' · overlapping runs allowed' : ''}
                                </span>
                            </div>
                            {!schedule.enabled && <Tag tone="neutral">Paused</Tag>}
                            <ManagedBadge config={schedule.managed} />
                            {can.manage && (
                                <span className="flex items-center gap-1">
                                    <Button size="sm" icon={<Play />} onClick={() => runNow(schedule)}>
                                        Run now
                                    </Button>
                                    {schedule.managed === null && (
                                        <IconButton
                                            size="sm"
                                            label="Edit"
                                            icon={<Pencil />}
                                            onClick={() =>
                                                setEditing({
                                                    id: schedule.id,
                                                    name: schedule.name,
                                                    expression: schedule.expression,
                                                    timezone: schedule.timezone,
                                                    overlap: schedule.overlap,
                                                    timeout_s: schedule.timeout_s,
                                                    enabled: schedule.enabled,
                                                })
                                            }
                                        />
                                    )}
                                    {schedule.managed === null && (
                                        <IconButton size="sm" label="Remove" icon={<Trash2 />} onClick={() => setRemoving(schedule)} />
                                    )}
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            <Dialog
                open={editing !== null}
                onOpenChange={(open) => !open && setEditing(null)}
                title={editing?.id ? 'Edit schedule' : 'Add schedule'}
                size="md"
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setEditing(null)}>
                            Cancel
                        </Button>
                        <Button variant="primary" type="submit" form="function-schedule" loading={saving}>
                            Save
                        </Button>
                    </>
                }
            >
                {editing && (
                    <form id="function-schedule" onSubmit={save} className="grid gap-3">
                        <Field label="Name" error={errors.name}>
                            <Input
                                autoFocus
                                value={editing.name}
                                maxLength={64}
                                placeholder="Nightly cleanup"
                                onChange={(e) => setEditing({ ...editing, name: e.target.value })}
                            />
                        </Field>
                        <Field label="When" error={errors.expression} hint={describeSchedule(editing.expression)}>
                            <div className="flex gap-2">
                                <Select
                                    value={PRESETS.some((p) => p.value === editing.expression) ? editing.expression : undefined}
                                    placeholder="Presets"
                                    onValueChange={(expression) => setEditing({ ...editing, expression })}
                                    options={PRESETS}
                                    aria-label="Preset"
                                />
                                <Input
                                    className="font-mono"
                                    value={editing.expression}
                                    onChange={(e) => setEditing({ ...editing, expression: e.target.value })}
                                    aria-label="Cron expression"
                                />
                            </div>
                        </Field>
                        <div className="grid gap-3 sm:grid-cols-3">
                            <Field label="Timezone" error={errors.timezone}>
                                <Input
                                    list="function-timezones"
                                    value={editing.timezone}
                                    onChange={(e) => setEditing({ ...editing, timezone: e.target.value })}
                                />
                                <datalist id="function-timezones">
                                    {state.data?.timezones.map((tz) => (
                                        <option key={tz} value={tz} />
                                    ))}
                                </datalist>
                            </Field>
                            <Field label="Timeout (s)" error={errors.timeout_s}>
                                <Input
                                    type="number"
                                    min={1}
                                    max={86400}
                                    value={editing.timeout_s}
                                    onChange={(e) => setEditing({ ...editing, timeout_s: Number(e.target.value) })}
                                />
                            </Field>
                            <Field label="If still running" error={errors.overlap}>
                                <Select
                                    value={editing.overlap}
                                    onValueChange={(overlap) => setEditing({ ...editing, overlap: overlap as 'allow' | 'skip' })}
                                    options={[
                                        { value: 'skip', label: 'Skip the next run' },
                                        { value: 'allow', label: 'Run anyway' },
                                    ]}
                                    aria-label="Overlap"
                                />
                            </Field>
                        </div>
                    </form>
                )}
            </Dialog>

            <Dialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                title={`Remove “${removing?.name}”?`}
                description="The server stops running it within a few seconds. Its run history stays in Observability."
                size="sm"
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setRemoving(null)}>
                            Cancel
                        </Button>
                        <Button
                            variant="danger"
                            icon={<Trash2 />}
                            onClick={async () => {
                                if (removing) await remove(removing);
                                setRemoving(null);
                            }}
                        >
                            Remove
                        </Button>
                    </>
                }
            />

            {run && <RunDialog siteId={siteId} run={run} onClose={() => setRun(null)} />}
        </div>
    );
}

/** Live output of a "Run now". */
function RunDialog({ siteId, run, onClose }: { siteId: string; run: { id: string; name: string }; onClose: () => void }) {
    const [finished, setFinished] = useState(false);
    const state = useJson<RunState>(functionUrl(siteId, `/runs/${run.id}`), { interval: finished ? false : 1000 });
    const data = state.data;

    useEffect(() => {
        if (data?.finished) setFinished(true);
    }, [data?.finished]);

    const ok = data?.finished && data.status === 'succeeded';

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && onClose()}
            title={`Run: ${run.name}`}
            size="lg"
            description={
                !data?.finished
                    ? 'Running on the function’s server…'
                    : ok
                      ? `Finished${data.duration_ms !== null ? ` in ${(data.duration_ms / 1000).toFixed(1)}s` : ''}.`
                      : `Failed${data.exit_code !== null ? ` (exit ${data.exit_code})` : ''}${data.error ? `: ${data.error}` : ''}.`
            }
            footer={
                <Button variant="ghost" onClick={onClose}>
                    Close
                </Button>
            }
        >
            <LogViewer
                lines={(data?.output ?? '').split('\n').filter((line, i, all) => line !== '' || i < all.length - 1)}
                height={320}
                streaming={!data?.finished}
                label={`${run.name} output`}
                emptyText="Waiting for output…"
            />
        </Dialog>
    );
}
