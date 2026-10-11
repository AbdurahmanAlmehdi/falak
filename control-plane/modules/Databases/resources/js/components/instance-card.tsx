import { ManagedBadge, managedCovers } from '@/components/falak';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { router, useForm } from '@inertiajs/react';
import { format } from 'date-fns';
import { ArrowUpCircle, KeyRound, RotateCw, Settings2, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { type DatabaseInstance, type InstanceOptions } from '../types';
import { StatusBadge } from './database-ui';

interface Props {
    instance: DatabaseInstance;
    options: InstanceOptions;
    canManage: boolean;
}

const PERSISTENCE: Record<string, string> = {
    rdb: 'Snapshots (RDB)',
    aof: 'Append-only file (AOF)',
    none: 'None (memory only)',
};

type Open = 'limits' | 'upgrade' | 'password' | 'delete' | null;

/** The container itself: status and health, limits and settings, restart, upgrade, password, delete. */
export function InstanceCard({ instance, options, canManage }: Props) {
    const [open, setOpen] = useState<Open>(null);
    const keyValue = instance.kind === 'key_value';
    const running = instance.status === 'active';
    // Fields falak.yaml sets are read-only here (the server answers 422 to a change).
    const managed = (path: string) => managedCovers(instance.managed, path);

    const limits = useForm({
        memory_mb: String(instance.memory_mb),
        cpus: instance.cpus === null ? '' : String(instance.cpus),
        max_connections: instance.settings.max_connections ? String(instance.settings.max_connections) : '',
        slow_query_ms: instance.settings.slow_query_ms !== undefined ? String(instance.settings.slow_query_ms) : '',
        eviction: instance.settings.eviction ?? 'noeviction',
        persistence: instance.settings.persistence ?? 'rdb',
        public_access: instance.public_access,
        require_tls: instance.require_tls,
        allowed_sources: instance.allowed_sources.join(', '),
    });
    const upgrade = useForm({ version: options.versions[options.versions.length - 1] ?? instance.version });
    const password = useForm({ password: '' });
    const destroy = useForm({ confirm: '', delete_volume: false });
    const close = () => setOpen(null);

    const submitLimits: FormEventHandler = (event) => {
        event.preventDefault();
        limits.transform((data) => ({
            memory_mb: data.memory_mb === '' ? null : Number(data.memory_mb),
            cpus: data.cpus === '' ? null : Number(data.cpus),
            settings: keyValue
                ? {
                      eviction: data.eviction,
                      persistence: data.persistence,
                      slow_query_ms: data.slow_query_ms === '' ? null : Number(data.slow_query_ms),
                  }
                : {
                      max_connections: data.max_connections === '' ? null : Number(data.max_connections),
                      slow_query_ms: data.slow_query_ms === '' ? null : Number(data.slow_query_ms),
                  },
            public_access: data.public_access,
            require_tls: data.require_tls || data.public_access,
            allowed_sources: data.allowed_sources
                .split(/[s,]+/)
                .map((source) => source.trim())
                .filter(Boolean),
        }));
        limits.put(`/databases/instances/${instance.id}`, { preserveScroll: true, onSuccess: close });
    };

    const submitUpgrade: FormEventHandler = (event) => {
        event.preventDefault();
        upgrade.post(`/databases/instances/${instance.id}/upgrade`, { preserveScroll: true, onSuccess: close });
    };

    const submitPassword: FormEventHandler = (event) => {
        event.preventDefault();
        password.transform((data) => ({ password: data.password || null }));
        password.post(`/databases/instances/${instance.id}/password`, {
            preserveScroll: true,
            onSuccess: () => {
                password.reset();
                close();
            },
        });
    };

    const submitDelete: FormEventHandler = (event) => {
        event.preventDefault();
        destroy.delete(`/databases/instances/${instance.id}`);
    };

    const major = upgrade.data.version !== instance.version;

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between gap-2">
                <CardTitle className="text-base">Container</CardTitle>
                <div className="flex items-center gap-2">
                    <ManagedBadge config={instance.managed} />
                    <StatusBadge status={instance.status} title={instance.status_message} />
                </div>
            </CardHeader>
            <CardContent className="space-y-4 text-sm">
                {instance.status_message && <p className="text-muted-foreground text-xs">{instance.status_message}</p>}
                <dl className="grid grid-cols-2 gap-x-4 gap-y-2">
                    <dt className="text-muted-foreground">Health</dt>
                    <dd>{instance.health ?? 'not reported yet'}</dd>
                    <dt className="text-muted-foreground">Image</dt>
                    <dd className="truncate font-mono text-xs" title={instance.image_digest ?? undefined}>
                        {instance.image}
                    </dd>
                    <dt className="text-muted-foreground">Memory</dt>
                    <dd className="tabular-nums">
                        {instance.memory_mb} MB{instance.cpus ? ` · ${instance.cpus} CPU` : ''}
                    </dd>
                    {keyValue && (
                        <>
                            <dt className="text-muted-foreground">Eviction</dt>
                            <dd className="font-mono text-xs">{instance.settings.eviction ?? 'noeviction'}</dd>
                            <dt className="text-muted-foreground">Persistence</dt>
                            <dd>{PERSISTENCE[instance.settings.persistence ?? 'rdb']}</dd>
                        </>
                    )}
                    <dt className="text-muted-foreground">TLS</dt>
                    <dd>
                        {instance.require_tls ? 'required' : 'available'}
                        {instance.tls_expires_at && (
                            <span className="text-muted-foreground text-xs"> · until {format(new Date(instance.tls_expires_at), 'yyyy-MM-dd')}</span>
                        )}
                    </dd>
                    <dt className="text-muted-foreground">Public access</dt>
                    <dd>
                        {instance.public_access ? 'on' : 'off'}
                        {instance.public_access && (
                            <span className="text-muted-foreground text-xs">
                                {' '}
                                · {instance.allowed_sources.length > 0 ? `from ${instance.allowed_sources.join(', ')}` : 'nobody allowed yet'}
                            </span>
                        )}
                    </dd>
                    {instance.password_overlap_until && (
                        <>
                            <dt className="text-muted-foreground">Previous password</dt>
                            <dd>valid until {format(new Date(instance.password_overlap_until), 'yyyy-MM-dd HH:mm')} (redeploy apps before)</dd>
                        </>
                    )}
                    {instance.retire_at && (
                        <>
                            <dt className="text-muted-foreground">Container removed</dt>
                            <dd>{format(new Date(instance.retire_at), 'yyyy-MM-dd HH:mm')} (its data volume is kept)</dd>
                        </>
                    )}
                </dl>
                {instance.status === 'retired' && (
                    <p className="bg-warning-soft text-warning rounded-md px-3 py-2 text-xs">
                        Retired by a major upgrade: read-only, stopped, its data volume kept. Delete it once you verified the upgraded database.
                    </p>
                )}
                {instance.pending_published_addresses && (
                    <div className="bg-warning-soft text-warning flex flex-wrap items-center justify-between gap-2 rounded-md px-3 py-2 text-xs">
                        <span>
                            Restart required: publish on {instance.pending_published_addresses.join(', ') || 'loopback only'} for servers of this
                            environment (the container is recreated on its volume).
                        </span>
                        {canManage && running && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => router.post(`/databases/instances/${instance.id}/network`, {}, { preserveScroll: true })}
                            >
                                Apply
                            </Button>
                        )}
                    </div>
                )}
                {canManage && (
                    <div className="flex flex-wrap gap-2">
                        <Button size="sm" variant="outline" disabled={!running} onClick={() => setOpen('limits')}>
                            <Settings2 /> Limits &amp; settings
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={!running}
                            onClick={() => router.post(`/databases/instances/${instance.id}/restart`, {}, { preserveScroll: true })}
                        >
                            <RotateCw /> Restart
                        </Button>
                        <Button size="sm" variant="outline" disabled={!options.upgradable || managed('/version')} onClick={() => setOpen('upgrade')}>
                            <ArrowUpCircle /> Upgrade
                        </Button>
                        <Button size="sm" variant="outline" disabled={!running || instance.rotating_password} onClick={() => setOpen('password')}>
                            <KeyRound /> {instance.rotating_password ? 'Rotating…' : keyValue ? 'Rotate password' : 'Rotate superuser password'}
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={instance.status === 'upgrading' || !!instance.managed}
                            onClick={() => setOpen('delete')}
                        >
                            <Trash2 /> Delete
                        </Button>
                    </div>
                )}
            </CardContent>

            <Dialog open={open === 'limits'} onOpenChange={(value) => !value && close()}>
                <DialogContent>
                    <form onSubmit={submitLimits} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>Limits &amp; settings</DialogTitle>
                            <DialogDescription>
                                The engine&apos;s config is tuned to the memory limit. Saving recreates the container with the new settings (a short
                                restart); the data stays on its volume.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="limit-memory">Memory (MB)</Label>
                                <Input
                                    id="limit-memory"
                                    disabled={managed('/memory')}
                                    inputMode="numeric"
                                    value={limits.data.memory_mb}
                                    onChange={(e) => limits.setData('memory_mb', e.target.value.replace(/\D/g, ''))}
                                />
                                <p className="text-muted-foreground text-xs">At least {options.min_memory_mb} MB.</p>
                                <InputError message={limits.errors.memory_mb} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="limit-cpus">CPUs</Label>
                                <Input
                                    id="limit-cpus"
                                    disabled={managed('/cpus')}
                                    inputMode="decimal"
                                    value={limits.data.cpus}
                                    placeholder="No limit"
                                    onChange={(e) => limits.setData('cpus', e.target.value.replace(/[^\d.]/g, ''))}
                                />
                                <InputError message={limits.errors.cpus} />
                            </div>
                            {keyValue ? (
                                <>
                                    <div className="grid gap-2">
                                        <Label>Eviction</Label>
                                        <Select
                                            disabled={managed('/settings/eviction')}
                                            value={limits.data.eviction}
                                            onValueChange={(value) => limits.setData('eviction', value)}
                                        >
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {options.evictions.map((item) => (
                                                    <SelectItem key={item} value={item}>
                                                        {item}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="grid gap-2">
                                        <Label>Persistence</Label>
                                        <Select
                                            disabled={managed('/settings/persistence')}
                                            value={limits.data.persistence}
                                            onValueChange={(value) => limits.setData('persistence', value as 'rdb' | 'aof' | 'none')}
                                        >
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {options.persistences.map((item) => (
                                                    <SelectItem key={item} value={item}>
                                                        {PERSISTENCE[item] ?? item}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </>
                            ) : (
                                <div className="grid gap-2">
                                    <Label htmlFor="limit-connections">Max connections</Label>
                                    <Input
                                        id="limit-connections"
                                        disabled={managed('/settings/max_connections')}
                                        inputMode="numeric"
                                        value={limits.data.max_connections}
                                        placeholder="Default"
                                        onChange={(e) => limits.setData('max_connections', e.target.value.replace(/\D/g, ''))}
                                    />
                                </div>
                            )}
                            <div className="grid gap-2">
                                <Label htmlFor="limit-slow">Slow query log (ms)</Label>
                                <Input
                                    id="limit-slow"
                                    disabled={managed('/settings/slow_query_ms')}
                                    inputMode="numeric"
                                    value={limits.data.slow_query_ms}
                                    placeholder="Default"
                                    onChange={(e) => limits.setData('slow_query_ms', e.target.value.replace(/\D/g, ''))}
                                />
                            </div>
                        </div>
                        {keyValue && limits.data.persistence === 'none' && (
                            <p role="alert" className="rounded-md bg-amber-500/15 px-3 py-2 text-xs text-amber-700 dark:text-amber-300">
                                Nothing is written to disk: every restart starts the instance empty.
                            </p>
                        )}
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                disabled={managed('/require_tls')}
                                checked={limits.data.require_tls}
                                onCheckedChange={(value) => limits.setData('require_tls', value === true)}
                            />
                            Require TLS for every connection
                        </label>
                        <label className="flex items-start gap-2 text-sm">
                            <Checkbox
                                disabled={managed('/public')}
                                checked={limits.data.public_access}
                                onCheckedChange={(value) => limits.setData('public_access', value === true)}
                                className="mt-0.5"
                            />
                            <span>
                                Public access
                                <span className="text-muted-foreground block text-xs">
                                    Publishes the port on every address of the server, TLS required. Only the networks allowed below get through
                                    Falak's firewall.
                                </span>
                            </span>
                        </label>
                        {limits.data.public_access && (
                            <div className="grid gap-2">
                                <Label htmlFor="instance-sources">Allowed sources</Label>
                                <Input
                                    id="instance-sources"
                                    disabled={managed('/allowed_sources')}
                                    className="font-mono"
                                    placeholder="203.0.113.9, 198.51.100.0/24"
                                    value={limits.data.allowed_sources}
                                    onChange={(event) => limits.setData('allowed_sources', event.target.value)}
                                />
                                <InputError message={Object.entries(limits.errors).find(([key]) => key.startsWith('allowed_sources'))?.[1]} />
                            </div>
                        )}
                        {Object.entries(limits.errors)
                            .filter(([key]) => key.startsWith('settings') || key === 'instance')
                            .map(([key, message]) => (
                                <InputError key={key} message={message} />
                            ))}
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={close}>
                                Cancel
                            </Button>
                            <Button disabled={limits.processing}>Save and apply</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={open === 'upgrade'} onOpenChange={(value) => !value && close()}>
                <DialogContent>
                    <form onSubmit={submitUpgrade} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>Upgrade {instance.name}</DialogTitle>
                            <DialogDescription>
                                {major && !keyValue
                                    ? `A new ${instance.engine_label} ${upgrade.data.version} container is created. While every database is copied into it, ${instance.name} is read-only: writes fail until the copy is checked (downtime grows with the data). Then the new one takes over the name apps use; the old one stays stopped with its data until you delete it.`
                                    : 'The container is recreated with the newest image of this version this release pins, on the same volume (a short restart).'}
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-2">
                            <Label>Version</Label>
                            <Select value={upgrade.data.version} onValueChange={(value) => upgrade.setData('version', value)}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {options.versions.map((version) => (
                                        <SelectItem key={version} value={version}>
                                            {instance.engine_label} {version}
                                            {version === instance.version ? ' (current, latest image)' : ''}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={upgrade.errors.version} />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={close}>
                                Cancel
                            </Button>
                            <Button disabled={upgrade.processing}>Upgrade</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={open === 'password'} onOpenChange={(value) => !value && close()}>
                <DialogContent>
                    <form onSubmit={submitPassword} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>Rotate {keyValue ? 'the password' : 'the superuser password'}</DialogTitle>
                            <DialogDescription>
                                {keyValue
                                    ? 'The running instance takes the new password right away. Sites get it through their references on their next deploy.'
                                    : 'Only Falak uses the superuser; application users keep their passwords.'}
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-2">
                            <Label htmlFor="instance-password">New password</Label>
                            <Input
                                id="instance-password"
                                type="password"
                                autoComplete="new-password"
                                value={password.data.password}
                                placeholder="Generated when empty"
                                onChange={(e) => password.setData('password', e.target.value)}
                            />
                            <InputError message={password.errors.password} />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={close}>
                                Cancel
                            </Button>
                            <Button disabled={password.processing}>Rotate</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={open === 'delete'} onOpenChange={(value) => !value && close()}>
                <DialogContent>
                    <form onSubmit={submitDelete} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>Delete {instance.name}?</DialogTitle>
                            <DialogDescription>
                                The container and its databases go. Its data stays in an unattached volume (Volumes) unless you delete it too. Backups
                                in storage are kept.
                            </DialogDescription>
                        </DialogHeader>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={destroy.data.delete_volume}
                                onCheckedChange={(value) => destroy.setData('delete_volume', value === true)}
                            />
                            Also delete the data volume
                        </label>
                        <div className="grid gap-2">
                            <Label htmlFor="instance-confirm">
                                Type <span className="font-mono">{instance.name}</span> to confirm
                            </Label>
                            <Input id="instance-confirm" value={destroy.data.confirm} onChange={(e) => destroy.setData('confirm', e.target.value)} />
                            <InputError message={destroy.errors.confirm ?? (destroy.errors as Record<string, string | undefined>).instance} />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={close}>
                                Cancel
                            </Button>
                            <Button variant="destructive" disabled={destroy.processing || destroy.data.confirm !== instance.name}>
                                Delete database
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </Card>
    );
}
