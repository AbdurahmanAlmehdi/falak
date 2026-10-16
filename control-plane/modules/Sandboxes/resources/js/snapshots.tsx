import { Button, ConfirmDestructive, DataTable, Dialog, Field, Input, RelativeTime, Select, StatusBadge, toast } from '@/components/falak';
import { HttpError, errorMessage, requestJson } from '@/lib/http';
import { formatBytes } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';
import { Camera, Copy, Trash2 } from 'lucide-react';
import { useState, type FormEventHandler } from 'react';
import { IDLE_PAUSES, snapshotBadge, snapshotLabel, ttlLabel, type Sandbox, type Snapshot } from './types';

const TTLS = [1800, 3600, 7200, 14400, 28800, 86400];

/** Snapshots with fork and delete (the Sandboxes list and a sandbox's Snapshots tab). */
export function SnapshotsTable({
    snapshots,
    showSource = false,
    maxTtl,
    defaultTtl,
    maxForkCount = 10,
    empty,
}: {
    snapshots: Snapshot[];
    showSource?: boolean;
    maxTtl: number;
    defaultTtl: number;
    maxForkCount?: number;
    empty: string;
}) {
    const [forking, setForking] = useState<Snapshot | null>(null);
    const [deleting, setDeleting] = useState<Snapshot | null>(null);

    const remove = async (snapshot: Snapshot) => {
        try {
            await requestJson(`/sandboxes/snapshots/${snapshot.id}`, 'DELETE');
            toast.success(`Deleted ${snapshotLabel(snapshot)}`);
            setDeleting(null);
            router.reload({ only: ['snapshots'] });
        } catch (error) {
            toast.error('Could not delete the snapshot', errorMessage(error));
        }
    };

    return (
        <>
            <DataTable
                label="Snapshots"
                rows={snapshots}
                rowKey={(snapshot) => snapshot.id}
                empty={{ icon: <Camera />, title: 'No snapshots', description: empty, size: 'sm' }}
                rowActions={(snapshot) =>
                    snapshot.can_operate
                        ? [
                              ...(snapshot.status === 'ready' ? [{ label: 'Fork', icon: <Copy />, onSelect: () => setForking(snapshot) }] : []),
                              { label: 'Delete', icon: <Trash2 />, danger: true, onSelect: () => setDeleting(snapshot) },
                          ]
                        : []
                }
                columns={[
                    {
                        id: 'name',
                        header: 'Name',
                        sortValue: (snapshot) => snapshotLabel(snapshot),
                        cell: (snapshot) => <span className="font-mono text-xs">{snapshotLabel(snapshot)}</span>,
                    },
                    ...(showSource
                        ? [
                              {
                                  id: 'source',
                                  header: 'Source',
                                  hideOnMobile: true,
                                  cell: (snapshot: Snapshot) => (
                                      <Link href={`/sandboxes/${snapshot.sandbox.id}`} className="font-mono text-xs hover:underline">
                                          {snapshot.sandbox.name ?? snapshot.sandbox.id.slice(0, 10)}
                                      </Link>
                                  ),
                              },
                          ]
                        : []),
                    {
                        id: 'status',
                        header: 'Status',
                        cell: (snapshot) => {
                            const badge = snapshotBadge(snapshot);

                            return <StatusBadge status={badge.status} label={badge.label} />;
                        },
                    },
                    {
                        id: 'size',
                        header: 'Size',
                        align: 'right',
                        hideOnMobile: true,
                        cell: (snapshot) => (
                            <span className="text-fg-muted tabular-nums">
                                {snapshot.size_bytes !== null ? formatBytes(snapshot.size_bytes) : '—'}
                            </span>
                        ),
                    },
                    {
                        id: 'created',
                        header: 'Created',
                        cell: (snapshot) => <RelativeTime value={snapshot.created_at} className="text-fg-muted" />,
                    },
                ]}
            />

            {forking && (
                <ForkDialog
                    snapshot={forking}
                    onOpenChange={(open) => !open && setForking(null)}
                    maxTtl={maxTtl}
                    defaultTtl={defaultTtl}
                    maxForkCount={maxForkCount}
                />
            )}

            <ConfirmDestructive
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title={`Delete ${deleting ? snapshotLabel(deleting) : 'snapshot'}?`}
                description="Its memory and files are removed from the server. Sandboxes forked from it keep running."
                confirmText={deleting ? snapshotLabel(deleting) : ''}
                confirmLabel="Delete snapshot"
                onConfirm={() => (deleting ? remove(deleting) : undefined)}
            />
        </>
    );
}

/** Fork: N new sandboxes from the snapshot, on its server, with its size and network. */
function ForkDialog({
    snapshot,
    onOpenChange,
    maxTtl,
    defaultTtl,
    maxForkCount,
}: {
    snapshot: Snapshot;
    onOpenChange: (open: boolean) => void;
    maxTtl: number;
    defaultTtl: number;
    maxForkCount: number;
}) {
    const [count, setCount] = useState('1');
    const [name, setName] = useState('');
    const [ttl, setTtl] = useState(String(defaultTtl));
    const [idlePause, setIdlePause] = useState('0');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);

    const submit: FormEventHandler = async (event) => {
        event.preventDefault();
        setBusy(true);
        setErrors({});

        try {
            const { data } = await requestJson<{ data: Sandbox[] }>(`/sandboxes/snapshots/${snapshot.id}/fork`, 'POST', {
                count: Number(count),
                name: name.trim() || null,
                ttl: Number(ttl),
                idle_pause: Number(idlePause) || null,
            });
            toast.success(data.length === 1 ? `Forking ${data[0].name}` : `Forking ${data.length} sandboxes`);
            onOpenChange(false);
            router.visit(data.length === 1 ? `/sandboxes/${data[0].id}` : '/sandboxes');
        } catch (error) {
            if (error instanceof HttpError && Object.keys(error.errors).length > 0) {
                setErrors(error.errors);
            } else {
                toast.error('Could not fork the snapshot', errorMessage(error));
            }
        } finally {
            setBusy(false);
        }
    };

    const formError = errors.sandbox ?? errors.server ?? errors.memory_mb ?? errors.disk_mb;

    return (
        <Dialog
            open
            onOpenChange={onOpenChange}
            title={`Fork ${snapshotLabel(snapshot)}`}
            description="New sandboxes continue where the snapshot stopped, each with its own copy and IP. They run on the snapshot's server with its size and network."
            footer={
                <>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" form="fork-snapshot" loading={busy}>
                        Fork
                    </Button>
                </>
            }
        >
            <form id="fork-snapshot" onSubmit={submit} className="grid gap-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Sandboxes" error={errors.count} hint={`1 to ${maxForkCount}`}>
                        <Input type="number" min={1} max={maxForkCount} value={count} onChange={(event) => setCount(event.target.value)} />
                    </Field>
                    <Field
                        label="Name"
                        error={errors.name}
                        hint={Number(count) > 1 ? 'Optional; they are name-1, name-2…' : 'Optional; one is made up.'}
                    >
                        <Input mono value={name} placeholder="worker" onChange={(event) => setName(event.target.value)} />
                    </Field>
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Time to live" error={errors.ttl}>
                        <Select
                            value={ttl}
                            onValueChange={setTtl}
                            options={TTLS.filter((seconds) => seconds <= maxTtl).map((seconds) => ({
                                value: String(seconds),
                                label: ttlLabel(seconds),
                            }))}
                        />
                    </Field>
                    <Field label="Idle pause" error={errors.idle_pause}>
                        <Select value={idlePause} onValueChange={setIdlePause} options={IDLE_PAUSES} />
                    </Field>
                </div>
                {formError && <p className="text-danger text-sm">{formError}</p>}
            </form>
        </Dialog>
    );
}
