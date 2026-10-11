import { Button, ConfirmDestructive, Field, Input, ManagedBadge, managedCovers, Section, Select, SkeletonRows, toast } from '@/components/falak';
import { Checkbox } from '@/components/ui/checkbox';
import { errorMessage, HttpError, requestJson } from '@/lib/http';
import { type ServiceTabProps } from '@/lib/registry';
import { ArrowUpCircle, KeyRound, RotateCw, Trash2 } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import { mutate, useDatabasePanel } from './api';

const PERSISTENCE: Record<string, string> = {
    rdb: 'Snapshots (RDB)',
    aof: 'Append-only file (AOF, fsync every second)',
    none: 'None (memory only)',
};

/** §5.4 Settings: the container's limits and settings, restart, upgrade, password rotation and deletion. */
export function DatabaseSettingsTab({ ctx }: ServiceTabProps) {
    const { data, error, reload } = useDatabasePanel(ctx);
    const [memory, setMemory] = useState('');
    const [maxConnections, setMaxConnections] = useState('');
    const [eviction, setEviction] = useState('noeviction');
    const [persistence, setPersistence] = useState('rdb');
    const [version, setVersion] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [deleteVolume, setDeleteVolume] = useState(false);
    const [deleteError, setDeleteError] = useState<string | undefined>();

    useEffect(() => {
        if (!data) return;
        setMemory(String(data.instance.memory_mb));
        setMaxConnections(data.instance.settings.max_connections ? String(data.instance.settings.max_connections) : '');
        setEviction(data.instance.settings.eviction ?? 'noeviction');
        setPersistence(data.instance.settings.persistence ?? 'rdb');
        setVersion(data.instance.version);
    }, [data]);

    if (!data) return error ? <p className="text-danger text-sm">{error}</p> : <SkeletonRows rows={6} />;

    const { instance, can, options } = data;
    const keyValue = instance.kind === 'key_value';
    const running = instance.status === 'active';
    // Fields falak.yaml sets are read-only here (the server answers 422 to a change).
    const managed = (path: string) => managedCovers(instance.managed, path);
    const base = `/databases/instances/${instance.id}`;
    const done = (success: string) => ({ success, reload });

    const save = async (event: FormEvent) => {
        event.preventDefault();
        setSaving(true);
        setErrors({});
        try {
            await requestJson(base, 'PUT', {
                memory_mb: Number(memory) || null,
                settings: keyValue ? { eviction, persistence } : { max_connections: Number(maxConnections) || null },
            });
            toast.success('Settings saved', 'The container restarts with them; its data stays on the volume.');
            await reload();
        } catch (e) {
            setErrors(e instanceof HttpError ? e.errors : { form: errorMessage(e) });
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="grid gap-8">
            <Section
                title="Container"
                description={`${instance.engine_label} ${instance.version} on ${instance.server_name}. The engine's memory settings are tuned to the limit.`}
                footer={
                    can.manage && (
                        <Button variant="primary" type="submit" form="instance-settings" loading={saving} disabled={!running}>
                            Save
                        </Button>
                    )
                }
            >
                <form id="instance-settings" onSubmit={save} className="grid gap-4 sm:grid-cols-2">
                    {instance.managed && (
                        <div className="sm:col-span-2">
                            <ManagedBadge config={instance.managed} />
                        </div>
                    )}
                    <Field label="Memory limit (MB)" hint={`At least ${options.min_memory_mb} MB.`} error={errors.memory_mb}>
                        <Input
                            value={memory}
                            onChange={(event) => setMemory(event.target.value.replace(/\D/g, ''))}
                            inputMode="numeric"
                            mono
                            disabled={!can.manage || managed('/memory')}
                        />
                    </Field>
                    {keyValue ? (
                        <>
                            <Field
                                label="Eviction"
                                hint="noeviction suits queues and sessions; allkeys-lru suits caches."
                                error={errors['settings.eviction']}
                            >
                                <Select
                                    value={eviction}
                                    onValueChange={setEviction}
                                    disabled={!can.manage || managed('/settings/eviction')}
                                    options={options.evictions.map((item) => ({ value: item, label: item }))}
                                />
                            </Field>
                            <Field label="Persistence" error={errors['settings.persistence']}>
                                <Select
                                    value={persistence}
                                    onValueChange={setPersistence}
                                    disabled={!can.manage || managed('/settings/persistence')}
                                    options={options.persistences.map((item) => ({ value: item, label: PERSISTENCE[item] ?? item }))}
                                />
                            </Field>
                        </>
                    ) : (
                        <Field label="Max connections" hint="Empty: the engine's default." error={errors['settings.max_connections']}>
                            <Input
                                value={maxConnections}
                                onChange={(event) => setMaxConnections(event.target.value.replace(/\D/g, ''))}
                                inputMode="numeric"
                                mono
                                disabled={!can.manage || managed('/settings/max_connections')}
                            />
                        </Field>
                    )}
                    {errors.form && <p className="text-danger text-xs sm:col-span-2">{errors.form}</p>}
                </form>
            </Section>

            {can.manage && (
                <Section title="Operations">
                    <div className="grid gap-4">
                        <div className="flex flex-wrap items-end gap-3">
                            <Field
                                label="Version"
                                hint="The same major moves to the newest pinned build (a short restart). A newer major copies the data into a new container; the current one is read-only until the copy is checked, then kept stopped until you delete it."
                            >
                                <Select
                                    value={version}
                                    onValueChange={setVersion}
                                    disabled={!options.upgradable || managed('/version')}
                                    options={options.versions.map((item) => ({ value: item, label: item }))}
                                />
                            </Field>
                            <Button
                                icon={<ArrowUpCircle />}
                                disabled={!options.upgradable || managed('/version')}
                                onClick={() => mutate('POST', `${base}/upgrade`, { version }, done(`Upgrading ${instance.name}`))}
                            >
                                Upgrade
                            </Button>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                icon={<RotateCw />}
                                disabled={!running}
                                onClick={() => mutate('POST', `${base}/restart`, {}, done(`Restarting ${instance.name}`))}
                            >
                                Restart
                            </Button>
                            <Button
                                icon={<KeyRound />}
                                disabled={!running || instance.rotating_password}
                                onClick={() => mutate('POST', `${base}/password`, {}, done('Rotating the password'))}
                            >
                                {keyValue ? 'Rotate password' : 'Rotate superuser password'}
                            </Button>
                        </div>
                    </div>
                </Section>
            )}

            {can.manage && (
                <Section title="Danger zone" tone="danger">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="grid gap-0.5">
                            <span className="text-fg text-sm font-medium">Delete this database server</span>
                            <span className="text-fg-muted text-sm">
                                The container and its databases go. The data stays in its volume unless you delete it too. Backups in storage are
                                kept.
                            </span>
                        </div>
                        <Button variant="danger" icon={<Trash2 />} disabled={!!instance.managed} onClick={() => setDeleting(true)}>
                            Delete
                        </Button>
                    </div>
                </Section>
            )}

            <ConfirmDestructive
                open={deleting}
                onOpenChange={setDeleting}
                title={`Delete ${instance.name}?`}
                description="The container is removed from the server. This cannot be undone."
                confirmText={instance.name}
                confirmLabel="Delete"
                error={deleteError}
                onConfirm={async (confirm) => {
                    try {
                        await requestJson(base, 'DELETE', { confirm, delete_volume: deleteVolume });
                        toast.success(`Deleting ${instance.name}`);
                        setDeleting(false);
                        ctx.close();
                    } catch (e) {
                        setDeleteError(errorMessage(e));
                    }
                }}
            >
                <label className="flex items-center gap-2 text-sm">
                    <Checkbox checked={deleteVolume} onCheckedChange={(value) => setDeleteVolume(value === true)} />
                    Also delete the data volume
                </label>
            </ConfirmDestructive>
        </div>
    );
}
