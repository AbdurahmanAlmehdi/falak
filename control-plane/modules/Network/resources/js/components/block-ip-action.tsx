import { Button } from '@/components/falak/button';
import { Dialog } from '@/components/falak/dialog';
import { Field } from '@/components/falak/field';
import { Input } from '@/components/falak/input';
import { Select } from '@/components/falak/select';
import { toast } from '@/components/falak/toast';
import { errorMessage, HttpError } from '@/lib/http';
import { type IpActionProps } from '@/lib/registry';
import { Ban } from 'lucide-react';
import { useState, type FormEventHandler } from 'react';
import { blockAddress, DURATIONS, scopesFor, type BlockDuration, type BlockScope } from '../protection';

/**
 * "Block IP" on the client rows of the network views (slot `network.ip-actions`, docs/plans/network/protection.md).
 * The guards are the server's: a refusal (your own address, a server, the control plane) is shown in the dialog, and a
 * private range or broad prefix asks for confirmation.
 */
export function BlockIpAction({ ip, serverId, siteId }: IpActionProps) {
    const scopes = scopesFor({ serverId, siteId });
    const [open, setOpen] = useState(false);
    const [scope, setScope] = useState<BlockScope>(scopes[0].value);
    const [duration, setDuration] = useState<BlockDuration>('24h');
    const [reason, setReason] = useState('');
    const [needsConfirm, setNeedsConfirm] = useState<string | null>(null);
    const [confirmed, setConfirmed] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const reset = (next: boolean) => {
        setOpen(next);
        setError(null);
        setNeedsConfirm(null);
        setConfirmed(false);
    };

    const submit: FormEventHandler = async (event) => {
        event.preventDefault();
        setBusy(true);
        setError(null);

        try {
            await blockAddress({
                address: ip,
                scope,
                server_id: scope === 'server' ? serverId : undefined,
                site_id: scope === 'site' ? siteId : undefined,
                duration,
                reason: reason.trim() || undefined,
                confirm: confirmed,
            });
            toast.success(`${ip} blocked`, 'Dropped at the firewall within a few seconds.');
            reset(false);
        } catch (e) {
            if (e instanceof HttpError && e.errors.confirm) {
                setNeedsConfirm(e.errors.confirm);
            } else {
                setError(errorMessage(e, 'Could not block the address'));
            }
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={reset}
            trigger={
                <Button size="sm" variant="ghost" icon={<Ban />} aria-label={`Block ${ip}`}>
                    Block
                </Button>
            }
            title={`Block ${ip}`}
            description="Drops every packet from this address before it reaches the server, its sites and its containers."
            size="sm"
        >
            <form onSubmit={submit} className="grid gap-4">
                <Field label="Where">
                    <Select<BlockScope> value={scope} onValueChange={setScope} options={scopes} />
                </Field>
                <Field label="For how long">
                    <Select<BlockDuration> value={duration} onValueChange={setDuration} options={DURATIONS} />
                </Field>
                <Field label="Reason" hint="Shown in the block list and the audit log.">
                    <Input
                        value={reason}
                        maxLength={500}
                        onChange={(event) => setReason(event.target.value)}
                        placeholder="e.g. credential stuffing"
                    />
                </Field>
                {needsConfirm && (
                    <label className="text-fg flex items-start gap-2 text-sm">
                        <input type="checkbox" checked={confirmed} onChange={(event) => setConfirmed(event.target.checked)} className="mt-0.5" />
                        <span>{needsConfirm.replace(/ Confirm to block it anyway\.$/, '')} Block it anyway.</span>
                    </label>
                )}
                {error && (
                    <p role="alert" className="text-danger text-sm">
                        {error}
                    </p>
                )}
                <div className="flex justify-end gap-2">
                    <Button type="button" onClick={() => reset(false)}>
                        Cancel
                    </Button>
                    <Button type="submit" variant="danger" loading={busy} disabled={needsConfirm !== null && !confirmed}>
                        Block
                    </Button>
                </div>
            </form>
        </Dialog>
    );
}
