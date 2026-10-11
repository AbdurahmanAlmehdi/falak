import {
    Button,
    Callout,
    CopyButton,
    Dialog,
    Field,
    IconButton,
    Input,
    ManagedBadge,
    RelativeTime,
    SkeletonRows,
    Textarea,
    managedCovers,
    toast,
    type ManagedConfig,
} from '@/components/falak';
import { useJson } from '@/hooks/use-json';
import { HttpError, errorMessage, requestJson } from '@/lib/http';
import { type ServiceTabProps } from '@/lib/registry';
import { KeyRound, Plus, Save, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { functionUrl } from '../types';

interface AccessState {
    keys: { id: string; name: string; prefix: string; created_at: string }[];
    allow_cidrs: string[];
    managed: ManagedConfig | null;
    can: { manage: boolean };
}

/**
 * Settings → Access of a function: API keys (the gateway rejects calls without one) and an IP allowlist. Changes
 * redeploy the live version; the gateway checks before waking the function.
 */
export function AccessSettings({ ctx }: ServiceTabProps) {
    const siteId = ctx.service.ref_id;
    const state = useJson<AccessState>(functionUrl(siteId, '/access'));
    const [name, setName] = useState('');
    const [creating, setCreating] = useState(false);
    const [created, setCreated] = useState<string | null>(null);
    const [cidrs, setCidrs] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (state.data) setCidrs(state.data.allow_cidrs.join('\n'));
    }, [state.data]);

    if (!state.data) return <SkeletonRows rows={3} />;
    const { keys, can } = state.data;

    const createKey = async () => {
        setCreating(true);
        try {
            const response = await requestJson<{ data: { key: string } }>(functionUrl(siteId, '/access/keys'), 'POST', {
                name: name.trim() || 'API key',
            });
            setCreated(response.data.key);
            setName('');
            await state.reload();
        } catch (e) {
            toast.error('Could not create the key', errorMessage(e));
        } finally {
            setCreating(false);
        }
    };

    const revoke = async (id: string, keyName: string) => {
        try {
            await requestJson(functionUrl(siteId, `/access/keys/${id}`), 'DELETE');
            toast.success(`${keyName} revoked`, 'Callers using it get 401 within a few seconds.');
            await state.reload();
        } catch (e) {
            toast.error('Could not revoke the key', errorMessage(e));
        }
    };

    const saveAllowlist = async () => {
        setSaving(true);
        setError(null);
        try {
            const entries = cidrs
                .split(/[\s,]+/)
                .map((s) => s.trim())
                .filter(Boolean);
            const response = await requestJson<{ data: { allow_cidrs: string[] } }>(functionUrl(siteId, '/access/allowlist'), 'PUT', {
                allow_cidrs: entries,
            });
            setCidrs(response.data.allow_cidrs.join('\n'));
            toast.success(entries.length ? 'Allowlist saved' : 'Allowlist cleared', 'Applied with a redeploy of the live version.');
            await state.reload();
        } catch (e) {
            setError(e instanceof HttpError ? (Object.values(e.errors)[0] ?? e.message) : errorMessage(e));
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="grid gap-5">
            <div className="grid gap-2">
                <div className="flex items-center justify-between gap-2">
                    <div>
                        <h4 className="text-fg text-sm font-medium">API keys</h4>
                        <p className="text-fg-muted text-xs">
                            With a key, every request needs <code className="text-fg">Authorization: Bearer &lt;key&gt;</code> or{' '}
                            <code className="text-fg">X-Falak-Key: &lt;key&gt;</code> (use the second when your code reads Authorization itself).
                        </p>
                    </div>
                </div>
                {keys.length > 0 && (
                    <ul className="border-border divide-border divide-y rounded-md border">
                        {keys.map((key) => (
                            <li key={key.id} className="flex items-center gap-3 px-3 py-2 text-sm">
                                <KeyRound className="text-fg-muted size-4" aria-hidden />
                                <span className="text-fg font-medium">{key.name}</span>
                                <span className="text-fg-faint font-mono text-xs">{key.prefix}…</span>
                                <span className="text-fg-faint ml-auto text-xs">
                                    <RelativeTime value={key.created_at} />
                                </span>
                                {can.manage && (
                                    <IconButton size="sm" label={`Revoke ${key.name}`} icon={<Trash2 />} onClick={() => revoke(key.id, key.name)} />
                                )}
                            </li>
                        ))}
                    </ul>
                )}
                {can.manage && (
                    <div className="flex gap-2">
                        <Input value={name} maxLength={64} placeholder="Key name, e.g. Zapier" onChange={(e) => setName(e.target.value)} />
                        <Button icon={<Plus />} loading={creating} onClick={createKey}>
                            Create key
                        </Button>
                    </div>
                )}
                {keys.length === 0 && <p className="text-fg-faint text-xs">No keys: anyone with the URL can call the function.</p>}
            </div>

            {managedCovers(state.data.managed, '/access/allow') && (
                <div>
                    <ManagedBadge config={state.data.managed} />
                </div>
            )}
            <Field
                label="IP allowlist"
                hint="One IP or CIDR range per line (IPv4 or IPv6). Empty: every address. Behind Cloudflare, the visitor's address is used."
                error={error}
            >
                <Textarea
                    rows={4}
                    className="font-mono"
                    value={cidrs}
                    disabled={!can.manage || managedCovers(state.data.managed, '/access/allow')}
                    placeholder={'203.0.113.0/24\n2001:db8::/32'}
                    onChange={(e) => setCidrs(e.target.value)}
                />
            </Field>
            {can.manage && !managedCovers(state.data.managed, '/access/allow') && (
                <div className="flex justify-end">
                    <Button variant="primary" icon={<Save />} loading={saving} onClick={saveAllowlist}>
                        Save allowlist
                    </Button>
                </div>
            )}

            <Dialog
                open={created !== null}
                onOpenChange={(open) => !open && setCreated(null)}
                title="Your new API key"
                description="Copy it now: Falak keeps only a hash and cannot show it again."
                footer={<Button onClick={() => setCreated(null)}>Done</Button>}
            >
                <Callout tone="warning" title="Shown once">
                    <span className="flex items-center gap-2">
                        <code className="text-fg break-all">{created}</code>
                        {created && <CopyButton value={created} label="Copy key" />}
                    </span>
                </Callout>
            </Dialog>
        </div>
    );
}
