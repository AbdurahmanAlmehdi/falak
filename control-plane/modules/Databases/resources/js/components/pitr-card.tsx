import { ManagedBadge, managedCovers } from '@/components/falak';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { HttpError, errorMessage, requestJson } from '@/lib/http';
import { router, useForm } from '@inertiajs/react';
import { History } from 'lucide-react';
import { FormEventHandler, useEffect, useMemo, useState } from 'react';
import {
    AGE_IDENTITY,
    AGE_RECIPIENT,
    ageOf,
    inRecoveryRange,
    isoToUtcInput,
    timelineBar,
    utcInputToIso,
    type DatabaseInstance,
    type PitrDecision,
    type PitrRestoreRow,
    type PitrState,
    type StorageOption,
} from '../types';
import { CopyButton, StatusBadge, formatBytes, formatDuration } from './database-ui';

interface Props {
    instance: DatabaseInstance;
    pitr: PitrState;
    storageProviders: StorageOption[];
    canManage: boolean;
    canRestore: boolean;
}

const DECISIONS: Record<PitrDecision, { label: string; explain: string }> = {
    swap: {
        label: 'Swap',
        explain:
            'The copy takes over this database: its name, address, host port, databases, users and schedules. The current one is stopped and kept, with its data volume, until you delete it.',
    },
    keep: { label: 'Keep as a new database', explain: 'The copy becomes a database of its own, next to this one (and on the canvas).' },
    discard: { label: 'Discard', explain: 'The copy and its data volume are deleted.' },
};

/**
 * Point-in-time recovery of an instance: settings, live shipping state, the timeline of recovery points with its gaps,
 * a UTC time picker that restores into a new read-only instance, and the three decisions about it.
 */
export function PitrCard({ instance, pitr, storageProviders, canManage, canRestore }: Props) {
    const [now, setNow] = useState(() => Date.now());

    // The lag ("last segment shipped 12 s ago") counts live.
    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 1000);

        return () => window.clearInterval(timer);
    }, []);

    if (!pitr.supported) {
        return null;
    }

    const awaiting = pitr.restores.filter((restore) => restore.status === 'awaiting_decision');

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between gap-2">
                <CardTitle className="flex items-center gap-2 text-base">
                    <History className="size-4" /> Point-in-time recovery
                </CardTitle>
                <div className="flex items-center gap-2">
                    {managedCovers(instance.managed, '/pitr', true) && <ManagedBadge config={instance.managed} />}
                    <StatusBadge status={pitr.enabled ? 'active' : 'pending'} className={pitr.enabled ? '' : 'bg-muted text-muted-foreground'} />
                </div>
            </CardHeader>
            <CardContent className="space-y-6">
                {pitr.enabled && <PitrStatus pitr={pitr} now={now} />}
                {pitr.timeline && pitr.timeline.ranges.length + pitr.timeline.gaps.length > 0 && <TimelineBar pitr={pitr} now={now} />}
                {awaiting.map((restore) => (
                    <AwaitingDecision key={restore.id} restore={restore} canRestore={canRestore} />
                ))}
                {canRestore && pitr.timeline && pitr.timeline.ranges.length > 0 && <RestoreToTime instance={instance} pitr={pitr} />}
                {canManage && <PitrSettings instance={instance} pitr={pitr} storageProviders={storageProviders} />}
                <RestoreHistory restores={pitr.restores.filter((restore) => restore.status !== 'awaiting_decision')} />
            </CardContent>
        </Card>
    );
}

function PitrStatus({ pitr, now }: { pitr: PitrState; now: number }) {
    const report = pitr.report;
    const share = report && report.volume_bytes > 0 ? Math.round((report.spool_bytes / report.volume_bytes) * 100) : null;
    const lagging = report?.oldest_pending_at ? now - Date.parse(report.oldest_pending_at) > 5 * 60 * 1000 : false;

    return (
        <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm md:grid-cols-4">
            <div>
                <dt className="text-muted-foreground text-xs">Last segment shipped</dt>
                <dd className={lagging ? 'text-red-600' : ''}>{pitr.last_shipped_at ? `${ageOf(pitr.last_shipped_at, now)} ago` : 'not yet'}</dd>
            </div>
            <div>
                <dt className="text-muted-foreground text-xs">Waiting in the spool</dt>
                <dd className={share !== null && share > 20 ? 'text-red-600' : ''}>
                    {report ? `${report.pending} · ${formatBytes(report.spool_bytes)}${share !== null ? ` (${share}% of the volume)` : ''}` : '—'}
                </dd>
            </div>
            <div>
                <dt className="text-muted-foreground text-xs">Recoverable</dt>
                <dd>{pitr.timeline?.from ? `${utc(pitr.timeline.from)} → ${utc(pitr.timeline.to)}` : 'after the first base backup'}</dd>
            </div>
            <div>
                <dt className="text-muted-foreground text-xs">Next base backup</dt>
                <dd>{pitr.next_base_at ? utc(pitr.next_base_at) : '—'}</dd>
            </div>
            {report?.error && <p className="col-span-full text-xs text-red-600">Shipping: {report.error}</p>}
        </dl>
    );
}

/** The window's recoverable ranges (green) and gaps (red). */
function TimelineBar({ pitr, now }: { pitr: PitrState; now: number }) {
    const timeline = pitr.timeline!;
    const start = now - pitr.window_days * 86400 * 1000;
    const bar = useMemo(() => timelineBar(timeline, start, now), [timeline, start, now]);

    return (
        <div className="space-y-1">
            <div className="bg-muted relative h-4 w-full overflow-hidden rounded" role="img" aria-label="Recovery timeline">
                {bar.ranges.map((range) => (
                    <div
                        key={range.from}
                        className="absolute inset-y-0 bg-emerald-500/70"
                        style={{ left: `${range.left}%`, width: `${Math.max(range.width, 0.5)}%` }}
                        title={`Recoverable ${utc(range.from)} → ${utc(range.to)}`}
                    />
                ))}
                {bar.gaps.map((gap) => (
                    <div
                        key={gap.at}
                        className="absolute inset-y-0 w-1 bg-red-600"
                        style={{ left: `${gap.left}%` }}
                        title={`Gap at ${utc(gap.at)}: ${gap.detail}`}
                    />
                ))}
            </div>
            <div className="text-muted-foreground flex justify-between text-xs">
                <span>{utc(new Date(start).toISOString())}</span>
                <span>now</span>
            </div>
            {timeline.gaps.length > 0 && (
                <ul className="text-xs text-red-600">
                    {timeline.gaps.map((gap) => (
                        <li key={gap.at}>
                            Gap at {utc(gap.at)}: {gap.detail}
                            {gap.resolved && ' (a newer base backup covers what follows)'}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function RestoreToTime({ instance, pitr }: { instance: DatabaseInstance; pitr: PitrState }) {
    const latest = pitr.timeline?.to ?? new Date().toISOString();
    const [value, setValue] = useState(() => isoToUtcInput(latest));
    const [identity, setIdentity] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const customer = pitr.encryption_mode === 'customer' || pitr.bases.some((base) => base.encryption_mode === 'customer');
    const iso = utcInputToIso(value, pitr.second_precision);
    const inRange = iso !== null && inRecoveryRange(pitr.timeline, iso);

    // Posted as JSON, not an Inertia form visit: a validation error never flashes the age identity into the session.
    const restoreTo = async (target: string) => {
        setBusy(true);
        setErrors({});
        try {
            await requestJson(`/databases/instances/${instance.id}/pitr/restore`, 'POST', {
                target_time: target,
                ...(customer ? { identity: identity.trim() } : {}),
            });
            router.reload();
        } catch (e) {
            setErrors(e instanceof HttpError && Object.keys(e.errors).length > 0 ? e.errors : { target_time: errorMessage(e) });
        } finally {
            setIdentity('');
            setBusy(false);
        }
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        if (iso) void restoreTo(iso);
    };

    return (
        <form onSubmit={submit} className="space-y-3 rounded-md border p-4">
            <div>
                <h3 className="text-sm font-medium">Restore to a point in time</h3>
                <p className="text-muted-foreground text-xs">
                    A new, read-only database is made at that time (this one is not touched). Then swap it in, keep it or discard it.
                </p>
            </div>
            <div className="grid gap-3 md:grid-cols-2">
                <div className="space-y-1">
                    <Label htmlFor="pitr-time">Time (UTC)</Label>
                    <Input id="pitr-time" type="datetime-local" step={1} value={value} onChange={(e) => setValue(e.target.value)} />
                    <p className="text-muted-foreground text-xs">
                        {iso ? `Your time: ${new Date(iso).toLocaleString()}` : 'Pick a time.'}
                        {pitr.second_precision && ' MySQL / MariaDB restore to the second: changes at that second and later are not replayed.'}
                    </p>
                    {iso && !inRange && <p className="text-xs text-amber-600">Outside the recoverable ranges.</p>}
                    <InputError message={errors.target_time} />
                </div>
                {customer && (
                    <div className="space-y-1">
                        <Label htmlFor="pitr-identity">Your age private key</Label>
                        <Input
                            id="pitr-identity"
                            type="password"
                            autoComplete="off"
                            placeholder="AGE-SECRET-KEY-1…"
                            value={identity}
                            onChange={(e) => setIdentity(e.target.value)}
                        />
                        <p className="text-muted-foreground text-xs">Used for this restore only, never stored.</p>
                        <InputError message={errors.identity} />
                    </div>
                )}
            </div>
            <div className="flex flex-wrap gap-2">
                <Button type="submit" disabled={busy || !iso || !inRange || (customer && !AGE_IDENTITY.test(identity.trim()))}>
                    Restore to this time
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    disabled={busy || (customer && !AGE_IDENTITY.test(identity.trim()))}
                    onClick={() => void restoreTo('latest')}
                    title="Everything shipped so far"
                >
                    Restore to the latest point
                </Button>
            </div>
        </form>
    );
}

function AwaitingDecision({ restore, canRestore }: { restore: PitrRestoreRow; canRestore: boolean }) {
    const [deciding, setDeciding] = useState<PitrDecision | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const copy = restore.copy;

    const decide = async () => {
        if (!deciding) return;
        setBusy(true);
        setError(null);
        try {
            await requestJson(`/databases/pitr-restores/${restore.id}/decision`, 'POST', { decision: deciding });
            setDeciding(null);
            router.reload();
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="space-y-3 rounded-md border border-amber-500/50 p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-sm font-medium">
                    {restore.to_latest ? 'Restored to the latest point' : 'Restored to'} {restore.target_time ? utc(restore.target_time) : '—'}{' '}
                    <span className="text-muted-foreground">(read-only)</span>
                </h3>
                <StatusBadge status={restore.status} />
            </div>
            {copy && (
                <div className="text-sm">
                    <p>
                        <span className="font-mono">{copy.name}</span> on {copy.server_name}:{' '}
                        <span className="font-mono">
                            {copy.host}:{copy.port}
                        </span>
                        {copy.port !== null && <CopyButton value={`${copy.host}:${copy.port}`} label="Copy the address" />}
                    </p>
                    <p className="text-muted-foreground text-xs">
                        Reachable from the server only (an SSH tunnel). Inspect it as <span className="font-mono">{copy.username}</span>, an account
                        that can only read.
                    </p>
                    {canRestore && <InspectionPassword restoreId={restore.id} />}
                </div>
            )}
            <RowCounts counts={restore.table_counts} />
            {restore.warnings.map((warning) => (
                <p key={warning} className="text-xs text-amber-600">
                    {warning}
                </p>
            ))}
            {restore.error && <p className="text-xs text-red-600">{restore.error}</p>}
            {canRestore && (
                <div className="flex flex-wrap gap-2">
                    {(Object.keys(DECISIONS) as PitrDecision[]).map((decision) => (
                        <Button key={decision} variant={decision === 'discard' ? 'outline' : 'default'} onClick={() => setDeciding(decision)}>
                            {DECISIONS[decision].label}
                        </Button>
                    ))}
                </div>
            )}
            <Dialog open={deciding !== null} onOpenChange={(open) => !open && setDeciding(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{deciding ? DECISIONS[deciding].label : ''}</DialogTitle>
                        <DialogDescription>{deciding ? DECISIONS[deciding].explain : ''}</DialogDescription>
                    </DialogHeader>
                    {error && <p className="text-sm text-red-600">{error}</p>}
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDeciding(null)}>
                            Cancel
                        </Button>
                        <Button variant={deciding === 'discard' ? 'destructive' : 'default'} disabled={busy} onClick={decide}>
                            {deciding ? DECISIONS[deciding].label : ''}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

/** The copy's read-only account's password, fetched on demand (never part of the page). */
function InspectionPassword({ restoreId }: { restoreId: string }) {
    const [password, setPassword] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    const reveal = async () => {
        try {
            const res = await requestJson<{ data: { password: string } }>(`/databases/pitr-restores/${restoreId}/inspection`, 'POST', {});
            setPassword(res.data.password);
        } catch (e) {
            setError(errorMessage(e));
        }
    };

    if (password) {
        return (
            <p className="text-xs">
                Password: <span className="font-mono">{password}</span> <CopyButton value={password} label="Copy the password" />
            </p>
        );
    }

    return (
        <>
            <Button type="button" variant="ghost" size="sm" onClick={() => void reveal()}>
                Reveal the password
            </Button>
            {error && <p className="text-xs text-red-600">{error}</p>}
        </>
    );
}

function RowCounts({ counts }: { counts: Record<string, Record<string, number>> }) {
    const databases = Object.entries(counts);

    if (databases.length === 0) {
        return null;
    }

    return (
        <div className="grid gap-2 md:grid-cols-2">
            {databases.map(([database, tables]) => (
                <div key={database} className="text-xs">
                    <p className="font-mono font-medium">{database}</p>
                    <ul className="text-muted-foreground max-h-40 overflow-auto">
                        {Object.entries(tables).map(([table, rows]) => (
                            <li key={table} className="flex justify-between gap-2 font-mono">
                                <span>{table}</span>
                                <span className="tabular-nums">{rows.toLocaleString()}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            ))}
        </div>
    );
}

function RestoreHistory({ restores }: { restores: PitrRestoreRow[] }) {
    if (restores.length === 0) {
        return null;
    }

    return (
        <ul className="divide-y text-sm">
            {restores.map((restore) => (
                <li key={restore.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                    <span>
                        To {restore.target_time ? utc(restore.target_time) : '—'}
                        {restore.decision && <span className="text-muted-foreground"> · {DECISIONS[restore.decision].label.toLowerCase()}</span>}
                    </span>
                    <span className="flex items-center gap-3">
                        <span className="text-muted-foreground tabular-nums">
                            {restore.segments ?? 0} segments · {formatDuration(restore.duration_ms)}
                        </span>
                        <StatusBadge status={restore.status} title={restore.error} />
                    </span>
                    {restore.error && <p className="w-full text-xs text-red-600">{restore.error}</p>}
                </li>
            ))}
        </ul>
    );
}

interface SettingsForm {
    enabled: boolean;
    storage_provider_id: string;
    encryption_mode: 'cp' | 'customer';
    age_recipient: string;
    window_days: number;
    base_interval_days: number;
}

function PitrSettings({ instance, pitr, storageProviders }: { instance: DatabaseInstance; pitr: PitrState; storageProviders: StorageOption[] }) {
    const form = useForm<SettingsForm>({
        enabled: pitr.enabled,
        storage_provider_id: pitr.storage_provider_id ?? storageProviders[0]?.id ?? '',
        encryption_mode: pitr.encryption_mode,
        age_recipient: pitr.age_recipient ?? '',
        window_days: pitr.window_days,
        base_interval_days: pitr.base_interval_days,
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.put(`/databases/instances/${instance.id}/pitr`, { preserveScroll: true });
    };

    const customerInvalid = form.data.encryption_mode === 'customer' && !AGE_RECIPIENT.test(form.data.age_recipient.trim());
    // Fields falak.yaml sets are read-only here (the server answers 422 to a change).
    const managed = (path: string) => managedCovers(instance.managed, path);

    return (
        <form onSubmit={submit} className="space-y-3 rounded-md border p-4">
            <div className="flex items-center gap-2">
                <Checkbox
                    id="pitr-enabled"
                    disabled={managed('/pitr/enabled')}
                    checked={form.data.enabled}
                    onCheckedChange={(checked) => form.setData('enabled', checked === true)}
                />
                <Label htmlFor="pitr-enabled">Ship the log continuously (restore to any second of the window)</Label>
            </div>
            <div className="grid gap-3 md:grid-cols-2">
                <div className="space-y-1">
                    <Label>Storage</Label>
                    <Select
                        disabled={managed('/pitr/storage')}
                        value={form.data.storage_provider_id}
                        onValueChange={(value) => form.setData('storage_provider_id', value)}
                    >
                        <SelectTrigger>
                            <SelectValue placeholder="Choose a storage provider" />
                        </SelectTrigger>
                        <SelectContent>
                            {storageProviders.map((provider) => (
                                <SelectItem key={provider.id} value={provider.id}>
                                    {provider.name} ({provider.bucket})
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={form.errors.storage_provider_id} />
                </div>
                <div className="space-y-1">
                    <Label>Keys</Label>
                    <Select
                        disabled={managed('/pitr/encryption/mode')}
                        value={form.data.encryption_mode}
                        onValueChange={(value) => form.setData('encryption_mode', value as SettingsForm['encryption_mode'])}
                    >
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="cp">Held by Falak (a key per segment, sealed)</SelectItem>
                            <SelectItem value="customer">Held by you (age public key)</SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError message={form.errors.encryption_mode} />
                </div>
                {form.data.encryption_mode === 'customer' && (
                    <div className="space-y-1 md:col-span-2">
                        <Label htmlFor="pitr-recipient">Age public key</Label>
                        <Input
                            id="pitr-recipient"
                            disabled={managed('/pitr/encryption/recipient')}
                            placeholder="age1…"
                            value={form.data.age_recipient}
                            onChange={(e) => form.setData('age_recipient', e.target.value)}
                        />
                        <p className="text-muted-foreground text-xs">Restores will ask for the matching private key; Falak never has it.</p>
                        <InputError message={form.errors.age_recipient} />
                    </div>
                )}
                <div className="space-y-1">
                    <Label htmlFor="pitr-window">Keep (days)</Label>
                    <Input
                        id="pitr-window"
                        disabled={managed('/pitr/window_days')}
                        type="number"
                        min={1}
                        max={35}
                        value={form.data.window_days}
                        onChange={(e) => form.setData('window_days', Number(e.target.value))}
                    />
                    <InputError message={form.errors.window_days} />
                </div>
                <div className="space-y-1">
                    <Label htmlFor="pitr-interval">Base backup every (days)</Label>
                    <Input
                        id="pitr-interval"
                        disabled={managed('/pitr/base_interval_days')}
                        type="number"
                        min={1}
                        max={form.data.window_days}
                        value={form.data.base_interval_days}
                        onChange={(e) => form.setData('base_interval_days', Number(e.target.value))}
                    />
                    <InputError message={form.errors.base_interval_days} />
                </div>
            </div>
            <InputError message={(form.errors as Record<string, string | undefined>).pitr} />
            <div className="flex flex-wrap gap-2">
                <Button type="submit" disabled={form.processing || (form.data.enabled && (!form.data.storage_provider_id || customerInvalid))}>
                    Save
                </Button>
                {pitr.enabled && (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => router.post(`/databases/instances/${instance.id}/pitr/base`, {}, { preserveScroll: true })}
                    >
                        Take a base backup now
                    </Button>
                )}
            </div>
        </form>
    );
}

/** "2026-10-09 12:30:05 UTC" */
function utc(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return `${new Date(iso).toISOString().slice(0, 19).replace('T', ' ')} UTC`;
}
