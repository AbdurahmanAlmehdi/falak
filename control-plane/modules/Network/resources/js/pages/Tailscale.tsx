import { Button } from '@/components/falak/button';
import { Callout } from '@/components/falak/callout';
import { ConfirmDestructive } from '@/components/falak/confirm-destructive';
import { CopyButton } from '@/components/falak/copy-button';
import { Field } from '@/components/falak/field';
import { Input } from '@/components/falak/input';
import { RelativeTime } from '@/components/falak/relative-time';
import { Section } from '@/components/falak/section';
import SettingsLayout from '@/layouts/settings/layout';
import { Link, router, useForm } from '@inertiajs/react';
import { ExternalLink, KeyRound, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { TailscaleStatus, type TailscaleNode } from '../components/tailscale-ui';

interface Props {
    saved_key: { saved_at: string | null } | null;
    nodes: (TailscaleNode & { server: { id: string; name: string } })[];
    version: string;
    can: { manage: boolean };
}

const AUTO_APPROVERS = JSON.stringify({ autoApprovers: { routes: { '0.0.0.0/0': ['tag:falak'], '::/0': ['tag:falak'] } } }, null, 2);

const options = { preserveScroll: true };

/** Settings → Integrations → Tailscale (docs/plans/TAILSCALE.md): the auth key, how to set up the tailnet, the servers on it. */
export default function Tailscale({ saved_key: saved, nodes, version, can }: Props) {
    const form = useForm({ auth_key: '' });
    const [removing, setRemoving] = useState(false);
    const [removeError, setRemoveError] = useState<string | undefined>();

    return (
        <SettingsLayout
            title="Tailscale"
            description="Put servers on your tailnet, then make sites reachable from your tailnet devices only (Networking → Tailscale only). DNS, certificates and agents keep using the public path."
        >
            <Section
                title="Set up your tailnet"
                description="Each server advertises its own public IPs as routes: devices that accept routes reach them through Tailscale."
            >
                <ol className="text-fg-muted grid list-decimal gap-2 pl-5 text-sm">
                    <li>
                        <a
                            href="https://login.tailscale.com/admin/settings/keys"
                            target="_blank"
                            rel="noreferrer"
                            className="text-fg inline-flex items-center gap-1 underline"
                        >
                            Generate an auth key <ExternalLink className="size-3.5" aria-hidden />
                        </a>{' '}
                        — reusable and pre-approved, ideally tagged <span className="text-fg font-mono">tag:falak</span>.
                    </li>
                    <li className="grid gap-1.5">
                        <span>
                            Let the tag’s routes be approved automatically (Access controls), or approve each server’s routes by hand in the admin
                            console:
                        </span>
                        <div className="border-border bg-surface-2 relative rounded border p-3">
                            <pre className="text-fg overflow-x-auto font-mono text-xs">{AUTO_APPROVERS}</pre>
                            <span className="absolute top-2 right-2">
                                <CopyButton value={AUTO_APPROVERS} size="xs" label="Copy autoApprovers" />
                            </span>
                        </div>
                        <span className="text-fg-faint text-xs">
                            0.0.0.0/0 only means “any route this tag advertises”: servers advertise their own /32 and /128, nothing else.
                        </span>
                    </li>
                    <li>
                        Devices accept routes: macOS, Windows, iOS and Android do by default; on Linux run{' '}
                        <span className="text-fg font-mono">tailscale set --accept-routes</span>.
                    </li>
                    <li>Save the key below, then join servers from their Network tab.</li>
                </ol>
            </Section>

            <Section
                title="Auth key"
                description="Stored encrypted and sent to a server only when it joins; it is never shown again."
                aside={
                    can.manage &&
                    saved && (
                        <Button variant="ghost" size="sm" icon={<Trash2 />} onClick={() => setRemoving(true)}>
                            Remove
                        </Button>
                    )
                }
            >
                {saved && (
                    <p className="text-fg-muted text-sm">
                        A key is saved (<RelativeTime value={saved.saved_at} className="text-fg-muted" fallback="just now" />
                        ). Saving a new one replaces it for servers that join or refresh from now on.
                    </p>
                )}
                {form.errors.auth_key && <Callout tone="danger">{form.errors.auth_key}</Callout>}
                {can.manage && (
                    <form
                        className="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.put('/settings/tailscale', { ...options, onSuccess: () => form.reset('auth_key') });
                        }}
                    >
                        <Field label={saved ? 'Replace the key' : 'Auth key'}>
                            <Input
                                type="password"
                                mono
                                autoComplete="off"
                                value={form.data.auth_key}
                                onChange={(event) => form.setData('auth_key', event.target.value)}
                                placeholder="tskey-auth-…"
                            />
                        </Field>
                        <Button
                            variant="primary"
                            type="submit"
                            icon={<KeyRound />}
                            loading={form.processing}
                            disabled={form.data.auth_key.length < 10}
                        >
                            Save key
                        </Button>
                    </form>
                )}
            </Section>

            <Section title="Servers on the tailnet" description={`Tailscale ${version}, installed from the official static release.`}>
                {nodes.length === 0 ? (
                    <p className="text-fg-muted text-sm">None yet. Open a server’s Network tab and join it.</p>
                ) : (
                    <ul className="divide-border grid divide-y">
                        {nodes.map((node) => (
                            <li key={node.server.id} className="grid gap-1 py-2.5">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="flex flex-wrap items-center gap-2">
                                        <Link href={`/servers/${node.server.id}/network`} className="text-fg text-sm font-medium hover:underline">
                                            {node.server.name}
                                        </Link>
                                        <span className="text-fg-faint font-mono text-xs">{node.hostname}</span>
                                    </span>
                                    <TailscaleStatus node={node} />
                                </div>
                                {(node.ipv4 || node.ipv6) && (
                                    <p className="text-fg-muted font-mono text-xs">{[node.ipv4, node.ipv6].filter(Boolean).join(' · ')}</p>
                                )}
                                {node.error && (
                                    <p role="alert" className="text-danger text-xs">
                                        {node.error}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Section>

            <ConfirmDestructive
                open={removing}
                onOpenChange={(open) => {
                    setRemoving(open);
                    setRemoveError(undefined);
                }}
                error={removeError}
                title="Remove the Tailscale auth key?"
                description="Servers can no longer join or refresh. Take every server off the tailnet first."
                confirmText="remove"
                confirmLabel="Remove key"
                onConfirm={() =>
                    new Promise<void>((resolve) => {
                        router.delete('/settings/tailscale', {
                            ...options,
                            onSuccess: () => setRemoving(false),
                            onError: (errors) => setRemoveError(errors.auth_key),
                            onFinish: () => resolve(),
                        });
                    })
                }
            />
        </SettingsLayout>
    );
}
