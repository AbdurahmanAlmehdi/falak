import { AppShell } from '@/components/falak/app-shell';
import { Button } from '@/components/falak/button';
import { Checkbox } from '@/components/falak/checkbox';
import { EmptyState } from '@/components/falak/empty-state';
import { Field } from '@/components/falak/field';
import { Input } from '@/components/falak/input';
import { PageHeader, Section } from '@/components/falak/section';
import { Select, type SelectOption } from '@/components/falak/select';
import { Skeleton } from '@/components/falak/skeleton';
import { Tag } from '@/components/falak/tag';
import { cn } from '@/lib/utils';
import { Head, Link, useForm } from '@inertiajs/react';
import { Check, KeyRound, Server as ServerIcon, Terminal } from 'lucide-react';
import { useMemo, type FormEventHandler, type ReactNode } from 'react';
import { useCatalog, type Image, type Region, type Size } from '../components/catalog';
import { ProviderIcon } from '../components/server-ui';
import { type SshKeyOption, type StackComponent, type StackConfig } from '../types';

interface TypeOption {
    value: string;
    label: string;
    description: string;
    components: StackComponent[];
    defaults: StackConfig;
}

interface Option {
    value: string;
    label: string;
}

interface Props {
    types: TypeOption[];
    providers: (Option & { has_api: boolean })[];
    credentials: { id: string; name: string; provider: string }[];
    options: {
        php_versions: string[];
        php_runtimes: Option[];
        node_versions: string[];
    };
    sshKeys: SshKeyOption[];
    canManageProviders: boolean;
    /** "This machine" (the control plane's own host): operators only, once the host channel exists, at most once. */
    thisMachine: { reserve_mb: number; allowed: boolean; hint: string | null } | null;
}

type CreateForm = {
    name: string;
    type: string;
    provider: string;
    credential_id: string;
    region: string;
    size: string;
    image: string;
    timezone: string;
    stack: StackConfig;
    ssh_key_ids: string[];
    control_plane_host: boolean;
};

const NONE = 'none';
const NAME_PATTERN = /^[A-Za-z0-9][A-Za-z0-9 ._-]*$/;

function nullable(items: Option[], noneLabel: string): SelectOption[] {
    return [{ value: NONE, label: noneLabel }, ...items];
}

function ChoiceCard({
    selected,
    onSelect,
    title,
    description,
    icon,
    aside,
    disabled = false,
}: {
    selected: boolean;
    onSelect: () => void;
    disabled?: boolean;
    title: ReactNode;
    description?: ReactNode;
    icon?: ReactNode;
    aside?: ReactNode;
}) {
    return (
        <button
            type="button"
            role="radio"
            aria-checked={selected}
            onClick={onSelect}
            disabled={disabled}
            className={cn(
                'disabled:cursor-not-allowed disabled:opacity-60',
                'group relative flex min-h-16 items-start gap-3 rounded-lg border p-3 text-left transition-[border-color,background-color] duration-150',
                'focus-visible:outline-primary focus-visible:outline-2 focus-visible:outline-offset-2',
                selected ? 'border-primary bg-primary-soft' : 'border-border bg-surface-1 hover:border-border-strong hover:bg-surface-2',
            )}
        >
            {icon && (
                <span
                    className={cn(
                        'flex size-8 shrink-0 items-center justify-center rounded-md border',
                        selected ? 'border-primary/40 text-primary' : 'border-border bg-surface-2 text-fg-muted',
                    )}
                >
                    {icon}
                </span>
            )}
            <span className="grid min-w-0 flex-1 gap-0.5">
                <span className="text-fg flex items-center gap-2 text-sm font-medium">{title}</span>
                {description && <span className="text-fg-muted text-xs">{description}</span>}
            </span>
            {aside}
            {selected && <Check className="text-primary absolute top-2.5 right-2.5 size-4" aria-hidden />}
        </button>
    );
}

function CatalogHint({ loading, error, empty }: { loading: boolean; error: string | null; empty?: string }) {
    if (loading) return <Skeleton className="h-3 w-24" />;
    if (error) return <span className="text-danger">{error}</span>;

    return empty ? <span>{empty}</span> : null;
}

export default function Create({ types, providers, credentials, options, sshKeys, canManageProviders, thisMachine }: Props) {
    const initialType = types.find((type) => type.value === 'app') ?? types[0];
    const requested = typeof window === 'undefined' ? null : new URLSearchParams(window.location.search).get('provider');
    const initialProvider = providers.find((provider) => provider.value === requested)?.value ?? providers.find((p) => p.has_api)?.value ?? 'custom';

    const { data, setData, post, processing, errors, transform, clearErrors } = useForm<CreateForm>({
        name: '',
        type: initialType.value,
        provider: initialProvider,
        credential_id: credentials.find((credential) => credential.provider === initialProvider)?.id ?? '',
        region: '',
        size: '',
        image: '',
        timezone: 'UTC',
        stack: initialType.defaults,
        ssh_key_ids: [],
        control_plane_host: false,
    });

    const errorBag = errors as Partial<Record<string, string>>;
    const selectedType = types.find((type) => type.value === data.type) ?? initialType;
    const provider = providers.find((option) => option.value === data.provider);
    const hasApi = provider?.has_api ?? false;
    const providerCredentials = credentials.filter((credential) => credential.provider === data.provider);
    const allows = (component: StackComponent) => selectedType.components.includes(component);

    const base = data.credential_id ? `/providers/${data.credential_id}` : null;
    const regions = useCatalog<Region>(hasApi && base ? `${base}/regions` : null);
    const sizes = useCatalog<Size>(hasApi && base && data.region ? `${base}/sizes?region=${encodeURIComponent(data.region)}` : null);
    const images = useCatalog<Image>(hasApi && base ? `${base}/images` : null);

    const sizeOptions = useMemo(
        () => sizes.items.filter((size) => size.regions.length === 0 || size.regions.includes(data.region)),
        [sizes.items, data.region],
    );

    const nameError =
        data.name !== '' && !NAME_PATTERN.test(data.name)
            ? 'Start with a letter or digit; use letters, digits, spaces, dots, dashes or underscores.'
            : null;
    const needsCredential = hasApi && providerCredentials.length === 0;
    const ready =
        data.name.trim() !== '' && !nameError && !needsCredential && (!hasApi || (data.credential_id && data.region && data.size && data.image));

    const setStack = (patch: Partial<StackConfig>) => setData('stack', { ...data.stack, ...patch });

    const chooseType = (value: string) => {
        const type = types.find((option) => option.value === value);
        if (type) setData((current) => ({ ...current, type: value, stack: type.defaults }));
    };

    const chooseProvider = (value: string, controlPlaneHost = false) => {
        clearErrors('provider', 'credential_id', 'region', 'size', 'image', 'control_plane_host');
        setData((current) => ({
            ...current,
            provider: value,
            control_plane_host: controlPlaneHost,
            credential_id: credentials.find((credential) => credential.provider === value)?.id ?? '',
            region: '',
            size: '',
            image: '',
        }));
    };

    const togglePhpVersion = (version: string, checked: boolean) => {
        const php = data.stack.php ?? { runtime: 'frankenphp', versions: [], default: null };
        const versions = checked ? [...new Set([...php.versions, version])].sort() : php.versions.filter((v) => v !== version);
        const fallback = versions.length > 0 ? versions[versions.length - 1] : null;
        setStack({ php: { ...php, versions, default: php.default && versions.includes(php.default) ? php.default : fallback } });
    };

    const toggleSshKey = (id: string, checked: boolean) =>
        setData('ssh_key_ids', checked ? [...data.ssh_key_ids, id] : data.ssh_key_ids.filter((key) => key !== id));

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        if (!ready) return;

        transform((form) => ({
            ...form,
            credential_id: hasApi ? form.credential_id : null,
            region: hasApi ? form.region : null,
            size: hasApi ? form.size : null,
            image: hasApi ? form.image : null,
            stack: {
                php: allows('php') ? form.stack.php : null,
                node: allows('node') ? form.stack.node : null,
            },
        }));

        post(route('servers.store'), { preserveScroll: true });
    };

    return (
        <AppShell
            breadcrumbs={[
                { title: 'Infrastructure', href: '/servers' },
                { title: 'Add server', href: '/servers/create' },
            ]}
        >
            <Head title="Add server" />
            <form onSubmit={submit} className="mx-auto grid w-full max-w-3xl gap-8" noValidate>
                <PageHeader
                    title="Add server"
                    description="Create a machine at a cloud provider, or bring any Ubuntu LTS server and connect it with one command."
                />

                <Section title="1. Where does it run?" description="Pick a provider. Custom works with any machine you can SSH into as root." bare>
                    <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3" role="radiogroup" aria-label="Provider">
                        {providers.map((option) => {
                            const count = credentials.filter((credential) => credential.provider === option.value).length;

                            return (
                                <ChoiceCard
                                    key={option.value}
                                    selected={data.provider === option.value && !data.control_plane_host}
                                    onSelect={() => chooseProvider(option.value)}
                                    icon={<ProviderIcon provider={option.value} />}
                                    title={option.value === 'custom' ? 'Custom server' : option.label}
                                    description={
                                        option.value === 'custom'
                                            ? 'Bring your own · install the agent'
                                            : count > 0
                                              ? `${count} credential${count === 1 ? '' : 's'} connected`
                                              : 'Not connected yet'
                                    }
                                />
                            );
                        })}
                        {thisMachine && (
                            <ChoiceCard
                                selected={data.control_plane_host}
                                onSelect={() => chooseProvider('custom', true)}
                                disabled={!thisMachine.allowed}
                                icon={<ServerIcon className="size-4" aria-hidden />}
                                title="This machine"
                                description="The control plane's own server"
                            />
                        )}
                    </div>
                    {thisMachine && !thisMachine.allowed && thisMachine.hint && (
                        <p className="text-fg-muted text-xs">This machine: {thisMachine.hint}</p>
                    )}
                    {errors.provider && <p className="text-danger text-xs">{errors.provider}</p>}
                    {errors.control_plane_host && <p className="text-danger text-xs">{errors.control_plane_host}</p>}
                </Section>

                <Section title="2. Server" description="A name you'll recognise, and what the machine is for.">
                    <Field
                        label="Name"
                        required
                        error={errors.name ?? nameError}
                        hint={!errors.name && !nameError ? 'Shown in Falak and used as the hostname.' : undefined}
                    >
                        <Input
                            value={data.name}
                            onChange={(event) => setData('name', event.target.value)}
                            placeholder="app-1"
                            autoFocus
                            autoComplete="off"
                            spellCheck={false}
                            className="sm:max-w-sm"
                        />
                    </Field>
                    <div className="grid gap-1.5">
                        <span className="text-fg text-xs font-medium">Type</span>
                        <div className="grid gap-2 sm:grid-cols-2" role="radiogroup" aria-label="Server type">
                            {types.map((type) => (
                                <ChoiceCard
                                    key={type.value}
                                    selected={data.type === type.value}
                                    onSelect={() => chooseType(type.value)}
                                    title={type.label}
                                    description={type.description}
                                />
                            ))}
                        </div>
                        {errors.type && <p className="text-danger text-xs">{errors.type}</p>}
                    </div>
                </Section>

                {hasApi ? (
                    <Section title="3. Location & size" description={`Where ${provider?.label ?? 'the provider'} creates the machine.`}>
                        {needsCredential ? (
                            <EmptyState
                                size="sm"
                                icon={<KeyRound />}
                                title={`Connect ${provider?.label}`}
                                description={`Add an API token for ${provider?.label} to pick regions and sizes here.`}
                                action={
                                    canManageProviders ? (
                                        <Button variant="primary" size="sm" asChild>
                                            <Link href="/settings/cloud-providers?add=1">Connect provider</Link>
                                        </Button>
                                    ) : (
                                        <span className="text-fg-muted text-xs">Ask an admin to connect it.</span>
                                    )
                                }
                                secondary={
                                    <Button variant="ghost" size="sm" onClick={() => chooseProvider('custom')}>
                                        Use a custom server
                                    </Button>
                                }
                            />
                        ) : (
                            <>
                                {providerCredentials.length > 1 && (
                                    <Field label="Credential" error={errors.credential_id} className="sm:max-w-sm">
                                        <Select
                                            value={data.credential_id || undefined}
                                            onValueChange={(value) =>
                                                setData((current) => ({ ...current, credential_id: value, region: '', size: '', image: '' }))
                                            }
                                            options={providerCredentials.map((credential) => ({ value: credential.id, label: credential.name }))}
                                            placeholder="Choose a credential"
                                        />
                                    </Field>
                                )}
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <Field
                                        label="Region"
                                        required
                                        error={errors.region}
                                        hint={<CatalogHint loading={regions.loading} error={regions.error} />}
                                    >
                                        <Select
                                            value={data.region || undefined}
                                            onValueChange={(value) => setData((current) => ({ ...current, region: value, size: '' }))}
                                            disabled={regions.loading || regions.items.length === 0}
                                            placeholder="Choose a region"
                                            options={regions.items.map((region) => ({
                                                value: region.id,
                                                label: region.country ? `${region.name} (${region.country})` : region.name,
                                                disabled: !region.available,
                                            }))}
                                        />
                                    </Field>
                                    <Field
                                        label="Size"
                                        required
                                        error={errors.size}
                                        hint={
                                            <CatalogHint
                                                loading={sizes.loading}
                                                error={sizes.error}
                                                empty={data.region ? undefined : 'Choose a region first'}
                                            />
                                        }
                                    >
                                        <Select
                                            value={data.size || undefined}
                                            onValueChange={(value) => setData('size', value)}
                                            disabled={!data.region || sizes.loading || sizeOptions.length === 0}
                                            placeholder="Choose a size"
                                            options={sizeOptions.map((size) => ({
                                                value: size.id,
                                                label: size.name,
                                                description: [
                                                    // Unknown (0) for providers without a catalog API, e.g. Contabo.
                                                    size.cpus ? `${size.cpus} vCPU` : null,
                                                    size.memory_mb ? `${(size.memory_mb / 1024).toFixed(size.memory_mb < 1024 ? 1 : 0)} GB` : null,
                                                    `${size.disk_gb} GB disk`,
                                                    size.arch !== 'amd64' ? size.arch : null,
                                                    size.price_monthly !== null ? `$${size.price_monthly.toFixed(2)}/mo` : null,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · '),
                                            }))}
                                        />
                                    </Field>
                                    <Field
                                        label="Image"
                                        required
                                        error={errors.image}
                                        hint={<CatalogHint loading={images.loading} error={images.error} />}
                                    >
                                        <Select
                                            value={data.image || undefined}
                                            onValueChange={(value) => setData('image', value)}
                                            disabled={images.loading || images.items.length === 0}
                                            placeholder="Choose an image"
                                            options={images.items.map((image) => ({
                                                value: image.id,
                                                label: image.arch ? `${image.name} (${image.arch})` : image.name,
                                            }))}
                                        />
                                    </Field>
                                </div>
                                <Field label="Timezone" error={errors.timezone} className="sm:max-w-xs">
                                    <Input
                                        value={data.timezone}
                                        onChange={(event) => setData('timezone', event.target.value)}
                                        placeholder="UTC"
                                        mono
                                    />
                                </Field>
                            </>
                        )}
                    </Section>
                ) : data.control_plane_host && thisMachine ? (
                    <Section
                        title="3. Run sites next to the control plane"
                        description="Falak installs the agent on this machine for you, through falak-ctl on the host."
                    >
                        <ul className="text-fg-muted grid list-disc gap-1.5 pl-5 text-sm">
                            <li>
                                {thisMachine.reserve_mb >= 1024
                                    ? `${(thisMachine.reserve_mb / 1024).toFixed(1).replace(/\.0$/, '')} GB`
                                    : `${thisMachine.reserve_mb} MB`}{' '}
                                of memory and a CPU share stay reserved for Falak itself; sites get the rest, capped so a build or a busy site can't
                                take the panel down.
                            </li>
                            <li>
                                The control plane's edge keeps ports 80 and 443 and hands your sites' domains to the agent's edge. HTTP/3 is not
                                available for sites on this machine.
                            </li>
                            <li>Falak keeps this machine's Docker, SSH, swap and hostname as they are.</li>
                            <li>
                                If this machine is lost, the panel and these sites go down together: keep disaster recovery set up and move important
                                sites to their own servers.
                            </li>
                        </ul>
                        <Field label="Timezone" error={errors.timezone} className="sm:max-w-xs">
                            <Input value={data.timezone} onChange={(event) => setData('timezone', event.target.value)} placeholder="UTC" mono />
                        </Field>
                    </Section>
                ) : (
                    <Section title="3. Connect your machine" description="Any Ubuntu 22.04 / 24.04 LTS server with outbound HTTPS.">
                        <div className="flex items-start gap-3">
                            <span className="border-border bg-surface-2 text-fg-muted flex size-8 shrink-0 items-center justify-center rounded-md border">
                                <Terminal className="size-4" aria-hidden />
                            </span>
                            <p className="text-fg-muted text-sm">
                                After you create the server, Falak shows a one-line install command. Run it as root on the machine; the agent dials
                                out, enrolls, and provisioning starts on its own — you can watch it live.
                            </p>
                        </div>
                        <Field label="Timezone" error={errors.timezone} className="sm:max-w-xs">
                            <Input value={data.timezone} onChange={(event) => setData('timezone', event.target.value)} placeholder="UTC" mono />
                        </Field>
                    </Section>
                )}

                {selectedType.components.length > 0 && (
                    <Section title="4. Software" description="Installed by the provisioning plan. You can add PHP versions later.">
                        {allows('php') && (
                            <div className="grid gap-4">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field label="PHP runtime" error={errorBag['stack.php.runtime'] ?? errorBag['stack.php']}>
                                        <Select
                                            value={data.stack.php?.runtime ?? NONE}
                                            onValueChange={(runtime) =>
                                                setStack({
                                                    php:
                                                        runtime === NONE
                                                            ? null
                                                            : {
                                                                  runtime,
                                                                  versions: data.stack.php?.versions ?? [],
                                                                  default: data.stack.php?.default ?? null,
                                                              },
                                                })
                                            }
                                            options={nullable(options.php_runtimes, 'No PHP')}
                                        />
                                    </Field>
                                    {data.stack.php && data.stack.php.versions.length > 0 && (
                                        <Field label="Default PHP version" error={errorBag['stack.php.default']}>
                                            <Select
                                                value={data.stack.php.default ?? undefined}
                                                onValueChange={(value) => data.stack.php && setStack({ php: { ...data.stack.php, default: value } })}
                                                options={data.stack.php.versions.map((version) => ({ value: version, label: `PHP ${version}` }))}
                                            />
                                        </Field>
                                    )}
                                </div>
                                {data.stack.php && (
                                    <fieldset className="grid gap-2">
                                        <legend className="text-fg mb-1.5 text-xs font-medium">PHP versions</legend>
                                        <div className="flex flex-wrap gap-2">
                                            {options.php_versions.map((version) => {
                                                const checked = data.stack.php?.versions.includes(version) ?? false;

                                                return (
                                                    <label
                                                        key={version}
                                                        className={cn(
                                                            'flex h-8 cursor-pointer items-center gap-2 rounded-md border px-2.5 text-sm transition-colors duration-150',
                                                            checked
                                                                ? 'border-primary bg-primary-soft text-fg'
                                                                : 'border-border bg-surface-2 text-fg-muted hover:border-border-strong',
                                                        )}
                                                    >
                                                        <Checkbox
                                                            checked={checked}
                                                            onCheckedChange={(value) => togglePhpVersion(version, value === true)}
                                                        />
                                                        {version}
                                                    </label>
                                                );
                                            })}
                                        </div>
                                        {errorBag['stack.php.versions'] && <p className="text-danger text-xs">{errorBag['stack.php.versions']}</p>}
                                    </fieldset>
                                )}
                            </div>
                        )}

                        {allows('node') && (
                            <div className="grid gap-4 sm:grid-cols-3">
                                <Field label="Node.js" error={errorBag['stack.node']}>
                                    <Select
                                        value={data.stack.node ?? NONE}
                                        onValueChange={(node) => setStack({ node: node === NONE ? null : node })}
                                        options={nullable(
                                            options.node_versions.map((version) => ({ value: version, label: `Node ${version}` })),
                                            'No Node.js',
                                        )}
                                    />
                                </Field>
                            </div>
                        )}
                    </Section>
                )}

                <Section
                    title={`${selectedType.components.length > 0 ? 5 : 4}. Access`}
                    description="SSH keys installed for the falak user once the server is provisioned."
                    aside={
                        <Button variant="ghost" size="sm" asChild>
                            <Link href="/ssh-keys">Manage keys</Link>
                        </Button>
                    }
                >
                    {sshKeys.length === 0 ? (
                        <p className="text-fg-muted text-sm">
                            No organization SSH keys yet — you can attach keys later from the server's SSH keys tab.
                        </p>
                    ) : (
                        <div className="grid gap-2 sm:grid-cols-2">
                            {sshKeys.map((key) => (
                                <label
                                    key={key.id}
                                    className="border-border hover:border-border-strong flex cursor-pointer items-start gap-2.5 rounded-md border p-2.5 text-sm transition-colors duration-150"
                                >
                                    <Checkbox
                                        className="mt-0.5"
                                        checked={data.ssh_key_ids.includes(key.id)}
                                        onCheckedChange={(checked) => toggleSshKey(key.id, checked === true)}
                                    />
                                    <span className="grid min-w-0 gap-0.5">
                                        <span className="text-fg font-medium">{key.name}</span>
                                        <span className="text-fg-faint truncate font-mono text-xs">{key.fingerprint}</span>
                                    </span>
                                </label>
                            ))}
                        </div>
                    )}
                    {errors.ssh_key_ids && <p className="text-danger text-xs">{errors.ssh_key_ids}</p>}
                </Section>

                <div className="border-border-strong bg-surface-2 shadow-panel sticky bottom-4 z-20 rounded-xl border">
                    <div className="flex items-center justify-between gap-3 py-2 pr-2 pl-4">
                        <p className="text-fg-muted hidden min-w-0 items-center gap-2 truncate text-sm sm:flex">
                            <ProviderIcon provider={data.provider} size={14} className="text-fg-faint" />
                            <span className="text-fg truncate font-medium">{data.name || 'Unnamed server'}</span>
                            <Tag>{selectedType.label}</Tag>
                        </p>
                        <div className="ml-auto flex items-center gap-2">
                            <Button variant="ghost" asChild>
                                <Link href="/servers">Cancel</Link>
                            </Button>
                            <Button type="submit" variant="primary" loading={processing} disabled={!ready}>
                                {hasApi ? 'Create server' : data.control_plane_host ? 'Add this machine' : 'Create & get install command'}
                            </Button>
                        </div>
                    </div>
                </div>
            </form>
        </AppShell>
    );
}
