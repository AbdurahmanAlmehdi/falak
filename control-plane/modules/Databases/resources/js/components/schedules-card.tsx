import { DrillHistory, ProtectionFields, protectionFrom, protectionPayload, type ProtectionValue } from '@/components/backup-protection';
import { ManagedBadge } from '@/components/falak';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { router, useForm } from '@inertiajs/react';
import { formatDistanceToNow } from 'date-fns';
import { FlaskConical, Pencil, Play, Plus, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { isKeyValue, type DatabaseInstance, type DatabaseRow, type ScheduleRow, type StorageOption } from '../types';

interface Props {
    instance: DatabaseInstance;
    schedules: ScheduleRow[];
    databases: DatabaseRow[];
    storageProviders: StorageOption[];
    canManage: boolean;
    /** The organization's other servers drills may run on. */
    drillServers?: { id: string; name: string }[];
}

const PRESETS = [
    { label: 'Hourly', cron: '0 * * * *' },
    { label: 'Daily 03:00', cron: '0 3 * * *' },
    { label: 'Every 6 hours', cron: '0 */6 * * *' },
    { label: 'Weekly (Sun 03:00)', cron: '0 3 * * 0' },
];

interface ScheduleForm {
    name: string;
    storage_provider_id: string;
    database_ids: string[];
    cron: string;
    retention_count: string;
    retention_days: string;
    enabled: boolean;
}

export function SchedulesCard({ instance, schedules, databases, storageProviders, canManage, drillServers = [] }: Props) {
    const [editing, setEditing] = useState<ScheduleRow | 'new' | null>(null);
    const [protection, setProtection] = useState<ProtectionValue>(protectionFrom());
    const withQuery = !isKeyValue(instance.engine);
    const [deleting, setDeleting] = useState<ScheduleRow | null>(null);
    const form = useForm<ScheduleForm>({
        name: 'Nightly',
        storage_provider_id: storageProviders[0]?.id ?? '',
        database_ids: [],
        cron: '0 3 * * *',
        retention_count: '14',
        retention_days: '',
        enabled: true,
    });

    const open = (schedule: ScheduleRow | 'new') => {
        form.clearErrors();

        if (schedule === 'new') {
            form.setData({
                name: 'Nightly',
                storage_provider_id: storageProviders[0]?.id ?? '',
                database_ids: databases.map((d) => d.id),
                cron: '0 3 * * *',
                retention_count: '14',
                retention_days: '',
                enabled: true,
            });
            setProtection(protectionFrom());
        } else {
            form.setData({
                name: schedule.name,
                storage_provider_id: schedule.storage_provider_id,
                database_ids: schedule.database_ids,
                cron: schedule.cron,
                retention_count: schedule.retention_count?.toString() ?? '',
                retention_days: schedule.retention_days?.toString() ?? '',
                enabled: schedule.enabled,
            });
            setProtection(protectionFrom(schedule));
        }

        setEditing(schedule);
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            retention_count: data.retention_count === '' ? null : Number(data.retention_count),
            retention_days: data.retention_days === '' ? null : Number(data.retention_days),
            ...protectionPayload(protection, withQuery),
        }));
        const options = { preserveScroll: true, onSuccess: () => setEditing(null) };

        if (editing === 'new') {
            form.post(`/databases/instances/${instance.id}/schedules`, options);
        } else if (editing) {
            form.put(`/databases/schedules/${editing.id}`, options);
        }
    };

    const toggleDatabase = (id: string, checked: boolean) => {
        form.setData('database_ids', checked ? [...form.data.database_ids, id] : form.data.database_ids.filter((d) => d !== id));
    };

    return (
        <Card className="gap-0 py-0">
            <CardHeader className="flex flex-row items-center justify-between gap-2 border-b py-4">
                <CardTitle className="text-base">Backup schedules</CardTitle>
                {canManage && (
                    <Button
                        size="sm"
                        onClick={() => open('new')}
                        disabled={storageProviders.length === 0 || databases.length === 0}
                        title={storageProviders.length === 0 ? 'Add a storage provider first' : undefined}
                    >
                        <Plus /> New schedule
                    </Button>
                )}
            </CardHeader>
            <CardContent className="px-0">
                {schedules.length === 0 ? (
                    <p className="text-muted-foreground p-6 text-sm">
                        No schedules. {storageProviders.length === 0 && 'Add a storage provider under Databases → Storage to enable backups.'}
                    </p>
                ) : (
                    <ul className="divide-y">
                        {schedules.map((schedule) => (
                            <li key={schedule.id} className="flex flex-wrap items-center justify-between gap-3 px-6 py-3 text-sm">
                                <div>
                                    <div className="flex items-center gap-2 font-medium">
                                        {schedule.name} {!schedule.enabled && <span className="text-muted-foreground text-xs">(paused)</span>}
                                        <ManagedBadge config={schedule.managed} />
                                    </div>
                                    <div className="text-muted-foreground text-xs">
                                        <span className="font-mono">{schedule.cron}</span> UTC → {schedule.storage_provider} ·{' '}
                                        {schedule.databases.join(', ')} · keep {schedule.retention_count ?? '∞'} /{' '}
                                        {schedule.retention_days ? `${schedule.retention_days}d` : '∞'} ·{' '}
                                        {schedule.encryption_mode === 'customer' ? 'your age key' : 'keys held by Falak'} ·{' '}
                                        {schedule.drill === 'off' ? 'no drills' : `${schedule.drill} drills`}
                                    </div>
                                    {schedule.drills.length > 0 && (
                                        <div className="mt-1">
                                            <DrillHistory drills={schedule.drills.slice(0, 3)} />
                                        </div>
                                    )}
                                    <div className="text-muted-foreground text-xs">
                                        {schedule.next_run_at && <>Next {formatDistanceToNow(new Date(schedule.next_run_at), { addSuffix: true })}</>}
                                        {schedule.last_run_at && (
                                            <> · last {formatDistanceToNow(new Date(schedule.last_run_at), { addSuffix: true })}</>
                                        )}
                                    </div>
                                </div>
                                {canManage && (
                                    <div className="flex">
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label={`Run ${schedule.name} now`}
                                            title="Run now"
                                            onClick={() => router.post(`/databases/schedules/${schedule.id}/run`, {}, { preserveScroll: true })}
                                        >
                                            <Play />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label={`Restore drill of ${schedule.name} now`}
                                            title="Drill now"
                                            onClick={() => router.post(`/databases/schedules/${schedule.id}/drill`, {}, { preserveScroll: true })}
                                        >
                                            <FlaskConical />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            disabled={!!schedule.managed}
                                            aria-label={`Edit ${schedule.name}`}
                                            onClick={() => open(schedule)}
                                        >
                                            <Pencil />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            disabled={!!schedule.managed}
                                            aria-label={`Delete ${schedule.name}`}
                                            onClick={() => setDeleting(schedule)}
                                        >
                                            <Trash2 />
                                        </Button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>

            <Dialog open={editing !== null} onOpenChange={(value) => !value && setEditing(null)}>
                <DialogContent className="sm:max-w-xl">
                    <form onSubmit={submit} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>{editing === 'new' ? 'New backup schedule' : 'Edit backup schedule'}</DialogTitle>
                            <DialogDescription>
                                Cron expressions are evaluated in UTC. The newest successful backup is never pruned.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="schedule-name">Name</Label>
                                <Input id="schedule-name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                                <InputError message={form.errors.name} />
                            </div>
                            <div className="grid gap-2">
                                <Label>Storage provider</Label>
                                <Select value={form.data.storage_provider_id} onValueChange={(value) => form.setData('storage_provider_id', value)}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="Choose" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {storageProviders.map((provider) => (
                                            <SelectItem key={provider.id} value={provider.id}>
                                                {provider.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={form.errors.storage_provider_id} />
                            </div>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="schedule-cron">Schedule (cron)</Label>
                            <Input
                                id="schedule-cron"
                                className="font-mono"
                                value={form.data.cron}
                                onChange={(e) => form.setData('cron', e.target.value)}
                            />
                            <div className="flex flex-wrap gap-1">
                                {PRESETS.map((preset) => (
                                    <Button
                                        key={preset.cron}
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() => form.setData('cron', preset.cron)}
                                    >
                                        {preset.label}
                                    </Button>
                                ))}
                            </div>
                            <InputError message={form.errors.cron} />
                        </div>
                        <div className="grid gap-2">
                            <Label>Databases</Label>
                            <div className="grid max-h-40 gap-1 overflow-y-auto rounded-md border p-2 sm:grid-cols-2">
                                {databases.map((database) => (
                                    <label key={database.id} className="flex items-center gap-2 text-sm">
                                        <Checkbox
                                            checked={form.data.database_ids.includes(database.id)}
                                            onCheckedChange={(value) => toggleDatabase(database.id, value === true)}
                                        />
                                        <span className="font-mono">{database.name}</span>
                                    </label>
                                ))}
                            </div>
                            <InputError message={form.errors.database_ids} />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="grid gap-2">
                                <Label htmlFor="retention-count">Keep last N</Label>
                                <Input
                                    id="retention-count"
                                    type="number"
                                    min={1}
                                    value={form.data.retention_count}
                                    onChange={(e) => form.setData('retention_count', e.target.value)}
                                    placeholder="∞"
                                />
                                <InputError message={form.errors.retention_count} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="retention-days">Max age (days)</Label>
                                <Input
                                    id="retention-days"
                                    type="number"
                                    min={1}
                                    value={form.data.retention_days}
                                    onChange={(e) => form.setData('retention_days', e.target.value)}
                                    placeholder="∞"
                                />
                                <InputError message={form.errors.retention_days} />
                            </div>
                        </div>
                        <ProtectionFields
                            value={protection}
                            onChange={setProtection}
                            errors={form.errors as Record<string, string | undefined>}
                            servers={drillServers}
                            withQuery={withQuery}
                        />
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox checked={form.data.enabled} onCheckedChange={(value) => form.setData('enabled', value === true)} />
                            Enabled
                        </label>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setEditing(null)}>
                                Cancel
                            </Button>
                            <Button disabled={form.processing}>Save schedule</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={deleting !== null} onOpenChange={(value) => !value && setDeleting(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete {deleting?.name}?</DialogTitle>
                        <DialogDescription>Existing backups are kept and remain restorable.</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setDeleting(null)}>
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() =>
                                deleting &&
                                router.delete(`/databases/schedules/${deleting.id}`, { preserveScroll: true, onFinish: () => setDeleting(null) })
                            }
                        >
                            Delete schedule
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </Card>
    );
}
