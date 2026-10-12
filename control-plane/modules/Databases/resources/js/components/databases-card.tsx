import { ManagedBadge } from '@/components/falak';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useForm } from '@inertiajs/react';
import { Archive, Plus, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { type DatabaseInstance, type DatabaseRow, type StorageOption } from '../types';
import { StatusBadge } from './database-ui';

interface Props {
    instance: DatabaseInstance;
    databases: DatabaseRow[];
    storageProviders: StorageOption[];
    canManage: boolean;
    defaults: { charset: string | null; collation: string | null };
}

export function DatabasesCard({ instance, databases, storageProviders, canManage, defaults }: Props) {
    const [creating, setCreating] = useState(false);
    const [deleting, setDeleting] = useState<DatabaseRow | null>(null);
    const [backingUp, setBackingUp] = useState<DatabaseRow | null>(null);
    const keyValue = instance.kind === 'key_value';
    const mysql = instance.engine === 'mysql' || instance.engine === 'mariadb';
    const noun = keyValue ? 'keyspace' : 'database';

    const create = useForm({ name: '', charset: '', collation: '', with_user: true, user: { username: '', password: '' } });
    const destroy = useForm({ confirm: '' });
    const backup = useForm({ storage_provider_id: storageProviders[0]?.id ?? '' });

    const submitCreate: FormEventHandler = (event) => {
        event.preventDefault();
        create.transform((data) => ({
            name: data.name,
            charset: mysql ? data.charset || null : null,
            collation: mysql ? data.collation || null : null,
            user: !keyValue && data.with_user && data.user.username ? { username: data.user.username, password: data.user.password || null } : null,
        }));
        create.post(`/databases/instances/${instance.id}/databases`, {
            preserveScroll: true,
            onSuccess: () => {
                create.reset();
                setCreating(false);
            },
        });
    };

    const submitDelete: FormEventHandler = (event) => {
        event.preventDefault();
        if (!deleting) return;
        destroy.delete(`/databases/databases/${deleting.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                destroy.reset();
                setDeleting(null);
            },
        });
    };

    const submitBackup: FormEventHandler = (event) => {
        event.preventDefault();
        if (!backingUp) return;
        backup.post(`/databases/databases/${backingUp.id}/backups`, { preserveScroll: true, onSuccess: () => setBackingUp(null) });
    };

    return (
        <Card className="gap-0 py-0">
            <CardHeader className="flex flex-row items-center justify-between gap-2 border-b py-4">
                <CardTitle className="text-base">{keyValue ? 'Keyspace' : 'Databases'}</CardTitle>
                {canManage && !keyValue && (
                    <Button size="sm" disabled={instance.status !== 'active'} onClick={() => setCreating(true)}>
                        <Plus /> New {noun}
                    </Button>
                )}
            </CardHeader>
            <CardContent className="px-0">
                {databases.length === 0 ? (
                    <p className="text-muted-foreground p-6 text-sm">No {noun}s yet.</p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="pl-6">Name</TableHead>
                                {mysql && <TableHead>Collation</TableHead>}
                                <TableHead>Status</TableHead>
                                {canManage && <TableHead className="w-24" />}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {databases.map((database) => (
                                <TableRow key={database.id}>
                                    <TableCell className="pl-6 font-mono">
                                        <span className="flex items-center gap-2">
                                            {database.name}
                                            <ManagedBadge config={database.managed} />
                                        </span>
                                    </TableCell>
                                    {mysql && <TableCell className="text-muted-foreground text-xs">{database.collation ?? '—'}</TableCell>}
                                    <TableCell>
                                        <StatusBadge status={database.status} title={database.status_message} />
                                        {database.status_message && <p className="mt-1 max-w-xs text-xs text-red-600">{database.status_message}</p>}
                                    </TableCell>
                                    {canManage && (
                                        <TableCell className="text-right">
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                disabled={database.status !== 'active' || storageProviders.length === 0}
                                                title={storageProviders.length === 0 ? 'Add a storage provider first' : 'Back up now'}
                                                aria-label={`Back up ${database.name}`}
                                                onClick={() => setBackingUp(database)}
                                            >
                                                <Archive />
                                            </Button>
                                            {/* A keyspace goes with its container (Container → Delete). */}
                                            {!keyValue && (
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    disabled={database.status === 'deleting' || !!database.managed}
                                                    aria-label={`Delete ${database.name}`}
                                                    onClick={() => setDeleting(database)}
                                                >
                                                    <Trash2 />
                                                </Button>
                                            )}
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </CardContent>

            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent>
                    <form onSubmit={submitCreate} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>New {noun}</DialogTitle>
                            <DialogDescription>
                                Created in {instance.name} ({instance.engine_label} {instance.version}).
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-2">
                            <Label htmlFor="db-name">Name</Label>
                            <Input id="db-name" value={create.data.name} onChange={(e) => create.setData('name', e.target.value)} placeholder="app" />
                            <InputError message={create.errors.name} />
                        </div>
                        {mysql && (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="db-charset">Charset</Label>
                                    <Input
                                        id="db-charset"
                                        value={create.data.charset}
                                        onChange={(e) => create.setData('charset', e.target.value)}
                                        placeholder={defaults.charset ?? ''}
                                    />
                                    <InputError message={create.errors.charset} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="db-collation">Collation</Label>
                                    <Input
                                        id="db-collation"
                                        value={create.data.collation}
                                        onChange={(e) => create.setData('collation', e.target.value)}
                                        placeholder={defaults.collation ?? ''}
                                    />
                                    <InputError message={create.errors.collation} />
                                </div>
                            </div>
                        )}
                        {!keyValue && (
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox checked={create.data.with_user} onCheckedChange={(value) => create.setData('with_user', value === true)} />
                                Also create a user with full access
                            </label>
                        )}
                        {!keyValue && create.data.with_user && (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="db-user">Username</Label>
                                    <Input
                                        id="db-user"
                                        value={create.data.user.username}
                                        onChange={(e) => create.setData('user', { ...create.data.user, username: e.target.value })}
                                        placeholder={create.data.name || 'app'}
                                    />
                                    <InputError message={create.errors['user.username' as keyof typeof create.errors]} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="db-password">Password</Label>
                                    <Input
                                        id="db-password"
                                        type="password"
                                        autoComplete="new-password"
                                        value={create.data.user.password}
                                        onChange={(e) => create.setData('user', { ...create.data.user, password: e.target.value })}
                                        placeholder="Generated when empty"
                                    />
                                    <InputError message={create.errors['user.password' as keyof typeof create.errors]} />
                                </div>
                            </div>
                        )}
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setCreating(false)}>
                                Cancel
                            </Button>
                            <Button disabled={create.processing}>Create {noun}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={deleting !== null} onOpenChange={(open) => !open && setDeleting(null)}>
                <DialogContent>
                    <form onSubmit={submitDelete} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>Drop {deleting?.name}?</DialogTitle>
                            <DialogDescription>
                                The database and all its data are permanently dropped from {instance.name}. Existing backups are kept.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-2">
                            <Label htmlFor="db-confirm">
                                Type <span className="font-mono">{deleting?.name}</span> to confirm
                            </Label>
                            <Input id="db-confirm" value={destroy.data.confirm} onChange={(e) => destroy.setData('confirm', e.target.value)} />
                            <InputError message={destroy.errors.confirm} />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setDeleting(null)}>
                                Cancel
                            </Button>
                            <Button variant="destructive" disabled={destroy.processing || destroy.data.confirm !== deleting?.name}>
                                Drop database
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={backingUp !== null} onOpenChange={(open) => !open && setBackingUp(null)}>
                <DialogContent>
                    <form onSubmit={submitBackup} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>Back up {backingUp?.name}</DialogTitle>
                            <DialogDescription>
                                The agent dumps the database and uploads it straight to the bucket via a presigned URL.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-2">
                            <Label>Storage provider</Label>
                            <Select value={backup.data.storage_provider_id} onValueChange={(value) => backup.setData('storage_provider_id', value)}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Choose a provider" />
                                </SelectTrigger>
                                <SelectContent>
                                    {storageProviders.map((provider) => (
                                        <SelectItem key={provider.id} value={provider.id}>
                                            {provider.name} ({provider.bucket})
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={backup.errors.storage_provider_id} />
                        </div>
                        <p className="text-muted-foreground text-xs">Compressed (zstd) and encrypted with a key of its own, held by Falak.</p>
                        <InputError message={(backup.errors as Record<string, string | undefined>).database} />
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setBackingUp(null)}>
                                Cancel
                            </Button>
                            <Button disabled={backup.processing || !backup.data.storage_provider_id}>Start backup</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </Card>
    );
}
