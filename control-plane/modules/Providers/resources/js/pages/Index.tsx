import { Button } from '@/components/falak/button';
import { Callout } from '@/components/falak/callout';
import { ConfirmDestructive } from '@/components/falak/confirm-destructive';
import { DataTable } from '@/components/falak/data-table';
import { Dialog } from '@/components/falak/dialog';
import { EmptyState } from '@/components/falak/empty-state';
import { Field } from '@/components/falak/field';
import { Input, Textarea } from '@/components/falak/input';
import { IntegrationTile } from '@/components/falak/integration-icon';
import { RelativeTime } from '@/components/falak/relative-time';
import { SecretInput } from '@/components/falak/secret-input';
import { Section } from '@/components/falak/section';
import { StatusBadge } from '@/components/falak/status';
import SettingsLayout from '@/layouts/settings/layout';
import { cn } from '@/lib/utils';
import { router, useForm } from '@inertiajs/react';
import { Cloud, KeyRound, Pencil, Plus, ShieldCheck, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState, type FormEventHandler } from 'react';

interface CredentialField {
    name: string;
    label: string;
    secret: boolean;
    help: string;
    /** Paste box (e.g. Contabo's API details): newlines survive, unlike a password input. */
    multiline?: boolean;
}

interface ProviderOption {
    value: string;
    label: string;
    fields: CredentialField[];
}

interface Credential {
    id: string;
    name: string;
    provider: string;
    provider_label: string;
    status: 'active' | 'invalid';
    last_verified_at: string | null;
    last_error: string | null;
    created_at: string | null;
}

interface Props {
    credentials: Credential[];
    providers: ProviderOption[];
    can: { manage: boolean };
}

interface CredentialForm {
    provider: string;
    name: string;
    credentials: Record<string, string>;
    [key: string]: string | Record<string, string>;
}

const BLURBS: Record<string, string> = {
    hetzner: 'Cloud servers in Germany, Finland, the US and Singapore.',
    digitalocean: 'Droplets in 14 regions.',
    vultr: 'Cloud compute in 30+ locations.',
    linode: 'Akamai cloud compute (Linode).',
    aws: 'Amazon Lightsail instances.',
    contabo: 'Low-cost VPS and VDS in 9 regions, billed monthly.',
};

function credentialStatus(credential: Credential): { status: string; label: string } {
    if (credential.status === 'invalid') return { status: 'failed', label: 'Invalid' };
    if (credential.last_error) return { status: 'degraded', label: 'Unreachable' };
    if (credential.last_verified_at) return { status: 'active', label: 'Verified' };

    return { status: 'queued', label: 'Unverified' };
}

function CredentialFields({
    fields,
    values,
    errors,
    onChange,
    replace = false,
}: {
    fields: CredentialField[];
    values: Record<string, string>;
    errors: Partial<Record<string, string>>;
    onChange: (name: string, value: string) => void;
    /** Editing an existing credential: secrets are write-only (Replace), other fields optional. */
    replace?: boolean;
}) {
    return (
        <>
            {fields.map((field) => (
                <Field
                    key={field.name}
                    label={field.label}
                    hint={
                        replace && !field.secret
                            ? `${field.help ? `${field.help} ` : ''}Leave empty to keep the stored value.`
                            : field.help || undefined
                    }
                    error={errors[`credentials.${field.name}`]}
                    required={!replace}
                >
                    {field.multiline ? (
                        <Textarea
                            mono
                            autoComplete="off"
                            spellCheck={false}
                            value={values[field.name] ?? ''}
                            placeholder={
                                replace
                                    ? 'Paste new API details to replace the stored ones'
                                    : "cntb config set-credentials --oauth2-clientid=… --oauth2-client-secret=… --oauth2-user=… --oauth2-password='…'"
                            }
                            onChange={(event) => onChange(field.name, event.target.value)}
                        />
                    ) : field.secret ? (
                        <SecretInput stored={replace} value={values[field.name] ?? ''} onChange={(value) => onChange(field.name, value)} />
                    ) : (
                        <Input
                            mono
                            autoComplete="off"
                            value={values[field.name] ?? ''}
                            placeholder={replace ? 'Unchanged' : undefined}
                            onChange={(event) => onChange(field.name, event.target.value)}
                        />
                    )}
                </Field>
            ))}
        </>
    );
}

/** Only send filled-in credential fields (empty = keep the stored value when editing). */
function filled(values: Record<string, string>): Record<string, string> {
    return Object.fromEntries(Object.entries(values).filter(([, value]) => value.trim() !== ''));
}

function AddCredentialDialog({
    providers,
    provider,
    onClose,
}: {
    providers: ProviderOption[];
    /** Provider to add, or null when closed. */
    provider: string | null;
    onClose: () => void;
}) {
    const form = useForm<CredentialForm>({ provider: provider ?? providers[0]?.value ?? '', name: '', credentials: {} });
    const selected = providers.find((option) => option.value === form.data.provider);
    const errors = form.errors as Partial<Record<string, string>>;
    const { setData, clearErrors } = form;

    useEffect(() => {
        if (!provider) return;
        clearErrors();
        setData({ provider, name: '', credentials: {} });
        // eslint-disable-next-line react-hooks/exhaustive-deps -- reset only when the dialog opens for a provider
    }, [provider]);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(route('providers.store'), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    return (
        <Dialog
            open={provider !== null}
            onOpenChange={(open) => !open && onClose()}
            title="Add a cloud provider"
            description="Falak checks the credential against the provider's API before saving it, then stores it encrypted. It is never shown again."
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" form="add-credential" loading={form.processing}>
                        {form.processing ? 'Verifying…' : 'Verify and add'}
                    </Button>
                </>
            }
        >
            <form id="add-credential" onSubmit={submit} className="grid gap-4">
                <fieldset className="grid gap-1.5">
                    <legend className="text-fg mb-1.5 text-xs font-medium">Provider</legend>
                    <div className="grid grid-cols-2 gap-1.5 sm:grid-cols-3" role="radiogroup" aria-label="Provider">
                        {providers.map((option) => (
                            <button
                                key={option.value}
                                type="button"
                                role="radio"
                                aria-checked={form.data.provider === option.value}
                                onClick={() => form.setData({ ...form.data, provider: option.value, credentials: {} })}
                                className={cn(
                                    'flex items-center gap-2 rounded-md border px-2.5 py-2 text-left text-sm transition-colors duration-150',
                                    form.data.provider === option.value
                                        ? 'border-primary bg-primary-soft text-fg'
                                        : 'border-border bg-surface-2 text-fg-muted hover:border-border-strong hover:text-fg',
                                )}
                            >
                                <IntegrationTile name={option.value} size="sm" className="bg-surface-1" />
                                <span className="truncate">{option.label}</span>
                            </button>
                        ))}
                    </div>
                    {errors.provider && <p className="text-danger text-xs">{errors.provider}</p>}
                </fieldset>

                <Field label="Name" hint="How this account appears when creating servers." error={errors.name} required>
                    <Input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} placeholder="Production account" />
                </Field>

                <CredentialFields
                    fields={selected?.fields ?? []}
                    values={form.data.credentials}
                    errors={errors}
                    onChange={(name, value) => form.setData('credentials', { ...form.data.credentials, [name]: value })}
                />
                {errors.credentials && <Callout tone="danger">{errors.credentials}</Callout>}
            </form>
        </Dialog>
    );
}

function EditCredentialDialog({
    credential,
    provider,
    onClose,
}: {
    credential: Credential;
    provider: ProviderOption | undefined;
    onClose: () => void;
}) {
    const form = useForm<{ name: string; credentials: Record<string, string>; [key: string]: string | Record<string, string> }>({
        name: credential.name,
        credentials: {},
    });
    const errors = form.errors as Partial<Record<string, string>>;

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.transform((data) => {
            const credentials = filled(data.credentials);

            return Object.keys(credentials).length > 0 ? { name: data.name, credentials } : { name: data.name };
        });
        form.patch(route('providers.update', credential.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && onClose()}
            title={`Edit ${credential.name}`}
            description="Rename the credential or replace its secret. A new secret is verified before it replaces the stored one."
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" form="edit-credential" loading={form.processing}>
                        Save
                    </Button>
                </>
            }
        >
            <form id="edit-credential" onSubmit={submit} className="grid gap-4">
                <Field label="Name" error={errors.name} required>
                    <Input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} />
                </Field>
                <CredentialFields
                    fields={provider?.fields ?? []}
                    values={form.data.credentials}
                    errors={errors}
                    replace
                    onChange={(name, value) => form.setData('credentials', { ...form.data.credentials, [name]: value })}
                />
                {errors.credentials && <Callout tone="danger">{errors.credentials}</Callout>}
            </form>
        </Dialog>
    );
}

export default function ProvidersIndex({ credentials, providers, can }: Props) {
    const [adding, setAdding] = useState<string | null>(() =>
        typeof window !== 'undefined' && new URLSearchParams(window.location.search).has('add') && can.manage ? (providers[0]?.value ?? null) : null,
    );
    const [editing, setEditing] = useState<Credential | null>(null);
    const [removing, setRemoving] = useState<Credential | null>(null);
    const [verifying, setVerifying] = useState<string | null>(null);
    const providerByValue = useMemo(() => new Map(providers.map((provider) => [provider.value, provider])), [providers]);

    const verify = (credential: Credential) => {
        setVerifying(credential.id);
        router.post(route('providers.verify', credential.id), {}, { preserveScroll: true, onFinish: () => setVerifying(null) });
    };

    const remove = () =>
        new Promise<void>((resolve) => {
            if (!removing) return resolve();
            router.delete(route('providers.destroy', removing.id), {
                preserveScroll: true,
                onSuccess: () => setRemoving(null),
                onFinish: () => resolve(),
            });
        });

    return (
        <SettingsLayout
            title="Cloud providers"
            description="API credentials Falak uses to create, resize and destroy servers. Any Ubuntu machine can also join with the install command — no credential needed."
            actions={
                can.manage &&
                credentials.length > 0 && (
                    <Button variant="primary" icon={<Plus />} onClick={() => setAdding(providers[0]?.value ?? null)}>
                        Add credential
                    </Button>
                )
            }
            wide
        >
            {credentials.length === 0 ? (
                <EmptyState
                    icon={<Cloud />}
                    title="No cloud accounts connected"
                    description="Connect Hetzner Cloud, DigitalOcean, Vultr, Linode or AWS Lightsail to create servers from Falak. Tokens are verified before they are saved."
                    action={
                        can.manage && (
                            <Button variant="primary" icon={<Plus />} onClick={() => setAdding(providers[0]?.value ?? null)}>
                                Add a credential
                            </Button>
                        )
                    }
                />
            ) : (
                <DataTable
                    label="Cloud provider credentials"
                    rows={credentials}
                    rowKey={(credential) => credential.id}
                    columns={[
                        {
                            id: 'name',
                            header: 'Credential',
                            sortValue: (credential) => credential.name,
                            cell: (credential) => (
                                <span className="flex min-w-0 items-center gap-2.5 py-1.5">
                                    <IntegrationTile name={credential.provider} size="sm" />
                                    <span className="grid min-w-0">
                                        <span className="truncate font-medium">{credential.name}</span>
                                        <span className="text-fg-faint truncate text-xs">{credential.provider_label}</span>
                                    </span>
                                </span>
                            ),
                        },
                        {
                            id: 'status',
                            header: 'Status',
                            cell: (credential) => {
                                const spec = credentialStatus(credential);

                                return (
                                    <span className="grid justify-items-start gap-0.5 py-1.5">
                                        <StatusBadge status={spec.status} label={spec.label} />
                                        {credential.last_error && (
                                            <span className="text-danger line-clamp-2 max-w-xs text-xs" title={credential.last_error}>
                                                {credential.last_error}
                                            </span>
                                        )}
                                    </span>
                                );
                            },
                        },
                        {
                            id: 'verified',
                            header: 'Last verified',
                            hideOnMobile: true,
                            sortValue: (credential) => credential.last_verified_at ?? '',
                            cell: (credential) => <RelativeTime value={credential.last_verified_at} fallback="Never" className="text-fg-muted" />,
                        },
                        ...(can.manage
                            ? [
                                  {
                                      id: 'verify',
                                      hideOnMobile: true,
                                      header: <span className="sr-only">Verify</span>,
                                      align: 'right' as const,
                                      cell: (credential: Credential) => (
                                          <Button
                                              size="sm"
                                              variant="ghost"
                                              icon={<ShieldCheck />}
                                              loading={verifying === credential.id}
                                              onClick={() => verify(credential)}
                                              aria-label={`Verify ${credential.name}`}
                                          >
                                              <span className="hidden sm:inline">Verify</span>
                                          </Button>
                                      ),
                                  },
                              ]
                            : []),
                    ]}
                    rowActions={
                        can.manage
                            ? (credential) => [
                                  { label: 'Verify now', icon: <ShieldCheck />, onSelect: () => verify(credential) },
                                  { label: 'Rename', icon: <Pencil />, onSelect: () => setEditing(credential) },
                                  { label: 'Replace secret', icon: <KeyRound />, onSelect: () => setEditing(credential) },
                                  { type: 'separator' },
                                  { label: 'Remove', icon: <Trash2 />, danger: true, onSelect: () => setRemoving(credential) },
                              ]
                            : undefined
                    }
                />
            )}

            {can.manage && (
                <Section title="Supported providers" description="Pick one to connect another account." bare>
                    <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        {providers.map((provider) => (
                            <button
                                key={provider.value}
                                type="button"
                                onClick={() => setAdding(provider.value)}
                                className="border-border bg-surface-1 hover:border-border-strong hover:bg-surface-2 flex items-center gap-3 rounded-lg border p-3 text-left transition-colors duration-150"
                            >
                                <IntegrationTile name={provider.value} />
                                <span className="grid min-w-0">
                                    <span className="text-fg text-sm font-medium">{provider.label}</span>
                                    <span className="text-fg-muted truncate text-xs">{BLURBS[provider.value] ?? 'Cloud servers via API.'}</span>
                                </span>
                                <Plus className="text-fg-faint ml-auto size-4 shrink-0" aria-hidden />
                            </button>
                        ))}
                    </div>
                </Section>
            )}

            {can.manage && <AddCredentialDialog providers={providers} provider={adding} onClose={() => setAdding(null)} />}
            {editing && (
                <EditCredentialDialog credential={editing} provider={providerByValue.get(editing.provider)} onClose={() => setEditing(null)} />
            )}
            <ConfirmDestructive
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                title={`Remove ${removing?.name ?? ''}`}
                description={`Existing servers keep running, but Falak can no longer create, resize or destroy them through ${removing?.provider_label ?? 'this provider'}.`}
                confirmText={removing?.name ?? ''}
                confirmLabel="Remove credential"
                onConfirm={remove}
            />
        </SettingsLayout>
    );
}
