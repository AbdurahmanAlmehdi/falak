import { Button } from '@/components/falak/button';
import { Callout } from '@/components/falak/callout';
import { ConfirmDestructive } from '@/components/falak/confirm-destructive';
import { Field } from '@/components/falak/field';
import { Input } from '@/components/falak/input';
import { Section } from '@/components/falak/section';
import { Switch } from '@/components/falak/switch';
import { Tag } from '@/components/falak/tag';
import SettingsLayout from '@/layouts/settings/layout';
import { router, useForm, usePage } from '@inertiajs/react';
import { Cloud, ExternalLink, RefreshCw, ShieldCheck, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface ZoneOption {
    id: string;
    name: string;
    status: string;
    plan: string;
    managed: { id: string; proxied: boolean } | null;
}

interface Connection {
    id: string;
    name: string;
    verified_at: string | null;
    error: string | null;
    zones: ZoneOption[];
}

interface ManagedRecord {
    name: string;
    type: string;
    content: string;
    proxied: boolean;
    status: 'pending' | 'synced' | 'conflict' | 'error';
    error: string | null;
}

interface ManagedZone {
    id: string;
    name: string;
    connection: string;
    proxied: boolean;
    generates: boolean;
    under_attack: boolean;
    health: Record<string, { value: string | null; recommended: string; ok: boolean }> | null;
    records: ManagedRecord[];
}

interface TunnelServer {
    id: string;
    name: string;
    ipv4: string | null;
    lock: 'closed' | 'cloudflare' | null;
    tunnel: {
        id: string;
        name: string;
        connection: string;
        status: 'installing' | 'active' | 'error';
        error: string | null;
        cname: string;
        health: { status: string; connections: number } | null;
    } | null;
}

interface Props {
    connections: Connection[];
    zones: ManagedZone[];
    servers: TunnelServer[];
    can: { manage: boolean };
}

// Dashboard template for the token (https://developers.cloudflare.com/fundamentals/api/how-to/account-owned-token-template/).
const TOKEN_PERMISSIONS = [
    { key: 'dns', type: 'edit' },
    { key: 'zone', type: 'read' },
    { key: 'zone_settings', type: 'edit' },
    { key: 'cache', type: 'purge' },
    { key: 'analytics', type: 'read' },
];
const TOKEN_URL = `https://dash.cloudflare.com/profile/api-tokens?permissionGroupKeys=${encodeURIComponent(JSON.stringify(TOKEN_PERMISSIONS))}&accountId=*&zoneId=all&name=${encodeURIComponent('Falak')}`;

const SETTING_LABELS: Record<string, string> = { ssl: 'SSL/TLS mode', min_tls_version: 'Minimum TLS version', always_use_https: 'Always Use HTTPS' };
const SETTING_VALUES: Record<string, string> = { strict: 'Full (strict)', full: 'Full', flexible: 'Flexible', off: 'Off', on: 'On' };

const RECORD_TONE = { synced: 'success', pending: 'info', conflict: 'warning', error: 'danger' } as const;

const options = { preserveScroll: true };

/** Settings → Integrations → Cloudflare: connect a token, pick the zones Falak manages, check their TLS settings. */
export default function Cloudflare({ connections, zones, servers, can }: Props) {
    const form = useForm({ name: 'Cloudflare', api_token: '' });
    const [releasing, setReleasing] = useState<ManagedZone | null>(null);
    const [disconnecting, setDisconnecting] = useState<Connection | null>(null);
    const [untunneling, setUntunneling] = useState<TunnelServer | null>(null);
    const [tunnelConnection, setTunnelConnection] = useState<string>(connections[0]?.id ?? '');
    // Tunnel actions fail as `server` (e.g. a token without Account → Cloudflare Tunnel → Edit).
    const pageErrors = usePage().props.errors as Record<string, string | undefined>;

    return (
        <SettingsLayout
            title="Cloudflare"
            description="Let Falak create your DNS records, generate names under your zone and put your services behind Cloudflare’s proxy (DDoS protection, edge cache, hidden origin)."
        >
            {can.manage && (
                <Section
                    title={connections.length === 0 ? 'Connect Cloudflare' : 'Add a connection'}
                    description="An API token scoped to the zones Falak should manage. It is verified with Cloudflare and stored encrypted."
                >
                    <ol className="text-fg-muted grid list-decimal gap-1 pl-5 text-sm">
                        <li>
                            <a href={TOKEN_URL} target="_blank" rel="noreferrer" className="text-fg inline-flex items-center gap-1 underline">
                                Create a token in Cloudflare <ExternalLink className="size-3.5" aria-hidden />
                            </a>{' '}
                            — the permissions are filled in: Zone → DNS → Edit, Zone → Zone → Read, Zone → Zone Settings → Edit, Zone → Cache Purge.
                        </li>
                        <li>
                            Under <span className="text-fg">Zone Resources</span>, pick <span className="text-fg">Specific zone</span> and your
                            domain.
                        </li>
                        <li>Create the token, copy it and paste it below.</li>
                    </ol>
                    <form
                        className="grid gap-3 sm:grid-cols-[12rem_1fr_auto] sm:items-end"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post('/settings/cloudflare', { ...options, onSuccess: () => form.reset('api_token') });
                        }}
                    >
                        <Field label="Name" error={form.errors.name}>
                            <Input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} />
                        </Field>
                        <Field label="API token" error={form.errors.api_token}>
                            <Input
                                type="password"
                                mono
                                autoComplete="off"
                                value={form.data.api_token}
                                onChange={(event) => form.setData('api_token', event.target.value)}
                                placeholder="Paste the token"
                            />
                        </Field>
                        <Button variant="primary" type="submit" icon={<Cloud />} loading={form.processing} disabled={form.data.api_token.length < 20}>
                            Connect
                        </Button>
                    </form>
                    <p className="text-fg-faint text-xs">
                        Later phases (tunnels, cache rules) also need Account → Cloudflare Tunnel → Edit and Zone → Cache Rules → Edit; you can add
                        them to the same token.
                    </p>
                </Section>
            )}

            {connections.map((connection) => (
                <Section
                    key={connection.id}
                    title={
                        <span className="flex items-center gap-2">
                            <Cloud className="text-fg-muted size-4" aria-hidden /> {connection.name}
                            {connection.error ? <Tag tone="danger">unreachable</Tag> : <Tag tone="success">connected</Tag>}
                        </span>
                    }
                    description="Zones this token can see. Manage a zone to let Falak create and remove the DNS records of its domains."
                    aside={
                        can.manage && (
                            <Button variant="ghost" size="sm" icon={<Trash2 />} onClick={() => setDisconnecting(connection)}>
                                Disconnect
                            </Button>
                        )
                    }
                >
                    {connection.error && <Callout tone="danger">{connection.error}</Callout>}
                    <ul className="divide-border grid divide-y">
                        {connection.zones.map((zone) => (
                            <li key={zone.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                <span className="flex items-center gap-2">
                                    <span className="text-fg font-mono text-sm">{zone.name}</span>
                                    <Tag>{zone.plan || 'zone'}</Tag>
                                    {zone.status !== 'active' && <Tag tone="warning">{zone.status}</Tag>}
                                </span>
                                {zone.managed ? (
                                    <Tag tone="success">managed by Falak</Tag>
                                ) : (
                                    can.manage && (
                                        <Button
                                            variant="secondary"
                                            size="sm"
                                            onClick={() =>
                                                router.post(
                                                    `/settings/cloudflare/${connection.id}/zones`,
                                                    { zone_id: zone.id, proxied: true, generate: zones.length === 0 },
                                                    options,
                                                )
                                            }
                                        >
                                            Manage
                                        </Button>
                                    )
                                )}
                            </li>
                        ))}
                    </ul>
                </Section>
            ))}

            {zones.map((zone) => (
                <Section
                    key={zone.id}
                    title={<span className="font-mono">{zone.name}</span>}
                    description={`Managed by Falak through ${zone.connection}. Falak only changes the records it created (tagged “falak:” in Cloudflare).`}
                    aside={
                        can.manage && (
                            <div className="flex gap-2">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    icon={<RefreshCw />}
                                    onClick={() => router.post(`/settings/cloudflare/zones/${zone.id}/sync`, {}, options)}
                                >
                                    Sync
                                </Button>
                                <Button variant="ghost" size="sm" onClick={() => setReleasing(zone)}>
                                    Stop managing
                                </Button>
                            </div>
                        )
                    }
                >
                    <div className="grid gap-3 sm:grid-cols-2">
                        <label className="flex items-start justify-between gap-3 text-sm">
                            <span>
                                <span className="text-fg font-medium">Proxy new records</span>
                                <span className="text-fg-muted block text-xs">
                                    Orange cloud: DDoS protection, edge cache and a hidden server IP. Each domain can override it.
                                </span>
                            </span>
                            <Switch
                                checked={zone.proxied}
                                disabled={!can.manage}
                                onCheckedChange={(proxied) => router.patch(`/settings/cloudflare/zones/${zone.id}`, { proxied }, options)}
                                aria-label={`Proxy new records in ${zone.name}`}
                            />
                        </label>
                        <label className="flex items-start justify-between gap-3 text-sm">
                            <span>
                                <span className="text-fg font-medium">Generate names here</span>
                                <span className="text-fg-muted block text-xs">
                                    New services get <span className="font-mono">service.{zone.name}</span> (copies in other environments:{' '}
                                    <span className="font-mono">service-staging.{zone.name}</span>).
                                </span>
                            </span>
                            <Switch
                                checked={zone.generates}
                                disabled={!can.manage}
                                onCheckedChange={(generate) => router.patch(`/settings/cloudflare/zones/${zone.id}`, { generate }, options)}
                                aria-label={`Generate names under ${zone.name}`}
                            />
                        </label>
                        <label className="flex items-start justify-between gap-3 text-sm sm:col-span-2">
                            <span>
                                <span className="text-fg font-medium">Under Attack mode</span>
                                <span className="text-fg-muted block text-xs">
                                    Every visitor of the zone gets a short browser check before reaching your services. Use it during an attack, then
                                    turn it off (the previous security level comes back).
                                </span>
                            </span>
                            <Switch
                                checked={zone.under_attack}
                                disabled={!can.manage}
                                onCheckedChange={(on) => router.put(`/settings/cloudflare/zones/${zone.id}/under-attack`, { on }, options)}
                                aria-label={`Under Attack mode for ${zone.name}`}
                            />
                        </label>
                    </div>

                    {zone.health ? (
                        <div className="grid gap-2">
                            {Object.entries(zone.health).map(([key, setting]) => (
                                <div key={key} className="flex flex-wrap items-center justify-between gap-2 text-sm">
                                    <span className="flex items-center gap-2">
                                        <ShieldCheck className={setting.ok ? 'text-success size-4' : 'text-warning size-4'} aria-hidden />
                                        {SETTING_LABELS[key] ?? key}:{' '}
                                        <span className="text-fg font-mono">{SETTING_VALUES[setting.value ?? ''] ?? setting.value ?? 'unknown'}</span>
                                        {!setting.ok && (
                                            <span className="text-fg-muted text-xs">
                                                (recommended: {SETTING_VALUES[setting.recommended] ?? setting.recommended})
                                            </span>
                                        )}
                                    </span>
                                    {!setting.ok && can.manage && (
                                        <Button
                                            variant="secondary"
                                            size="sm"
                                            onClick={() => router.put(`/settings/cloudflare/zones/${zone.id}/setting`, { setting: key }, options)}
                                        >
                                            Set to {SETTING_VALUES[setting.recommended] ?? setting.recommended}
                                        </Button>
                                    )}
                                </div>
                            ))}
                            {zone.health.ssl && zone.health.ssl.value === 'flexible' && (
                                <Callout tone="warning">
                                    Flexible makes Cloudflare talk to your server over plain HTTP, and Falak redirects HTTP to HTTPS: sites loop. Use
                                    Full (strict).
                                </Callout>
                            )}
                        </div>
                    ) : (
                        <p className="text-fg-faint text-xs">Zone settings could not be read (the token needs Zone Settings → Read or Edit).</p>
                    )}

                    <div className="grid gap-1">
                        <p className="text-fg text-sm font-medium">Records Falak manages ({zone.records.length})</p>
                        {zone.records.length === 0 ? (
                            <p className="text-fg-muted text-xs">None yet. Add a domain under {zone.name} to a service, or generate one.</p>
                        ) : (
                            <ul className="grid gap-1">
                                {zone.records.map((record) => (
                                    <li key={`${record.name}-${record.type}-${record.content}`} className="grid gap-0.5 text-xs">
                                        <span className="flex flex-wrap items-center gap-2">
                                            <span className="text-fg font-mono">{record.name}</span>
                                            <Tag>{record.type}</Tag>
                                            <span className="text-fg-muted font-mono">{record.content}</span>
                                            {record.proxied && <Tag tone="info">proxied</Tag>}
                                            <Tag tone={RECORD_TONE[record.status]}>{record.status}</Tag>
                                        </span>
                                        {record.error && <span className="text-fg-muted">{record.error}</span>}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </Section>
            ))}

            {connections.length > 0 && servers.length > 0 && (
                <Section
                    title="Servers"
                    description="Ingress per server. Through a Cloudflare Tunnel, cloudflared on the server connects out to Cloudflare, so the server needs no open inbound ports and works behind NAT; its names in your zones point at the tunnel."
                >
                    {pageErrors.server && <Callout tone="danger">{pageErrors.server}</Callout>}
                    <ul className="divide-border grid divide-y">
                        {servers.map((server) => (
                            <li key={server.id} className="grid gap-1 py-2.5">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="flex flex-wrap items-center gap-2">
                                        <span className="text-fg text-sm font-medium">{server.name}</span>
                                        <span className="text-fg-faint font-mono text-xs">{server.ipv4 ?? 'no public IP'}</span>
                                        {server.tunnel ? <TunnelTag tunnel={server.tunnel} /> : <Tag>public</Tag>}
                                    </span>
                                    {can.manage &&
                                        (server.tunnel ? (
                                            <span className="flex gap-2">
                                                {server.tunnel.status === 'error' && (
                                                    <Button
                                                        variant="secondary"
                                                        size="sm"
                                                        icon={<RefreshCw />}
                                                        onClick={() =>
                                                            router.post(`/settings/cloudflare/tunnels/${server.tunnel!.id}/reinstall`, {}, options)
                                                        }
                                                    >
                                                        Reinstall
                                                    </Button>
                                                )}
                                                <Button variant="ghost" size="sm" onClick={() => setUntunneling(server)}>
                                                    Back to public
                                                </Button>
                                            </span>
                                        ) : (
                                            <Button
                                                variant="secondary"
                                                size="sm"
                                                icon={<Cloud />}
                                                onClick={() =>
                                                    router.post(
                                                        '/settings/cloudflare/tunnels',
                                                        { server_id: server.id, connection_id: tunnelConnection },
                                                        options,
                                                    )
                                                }
                                            >
                                                Route through a tunnel
                                            </Button>
                                        ))}
                                </div>
                                {can.manage && (
                                    <div className="flex flex-wrap items-center gap-2 text-xs">
                                        <span className="text-fg-muted">Web ports (80/443):</span>
                                        {(
                                            [
                                                [null, 'Open'],
                                                ['cloudflare', 'Cloudflare only'],
                                                ['closed', 'Closed (tunnel)'],
                                            ] as const
                                        ).map(([mode, label]) => (
                                            <Button
                                                key={label}
                                                size="sm"
                                                variant={server.lock === mode ? 'primary' : 'ghost'}
                                                disabled={server.lock === mode || (mode === 'closed' && server.tunnel?.status !== 'active')}
                                                onClick={() => router.put(`/settings/cloudflare/servers/${server.id}/lock`, { mode }, options)}
                                            >
                                                {label}
                                            </Button>
                                        ))}
                                    </div>
                                )}
                                {server.lock && (
                                    <p className="text-fg-muted text-xs">
                                        {server.lock === 'closed'
                                            ? 'No inbound web traffic: the server is reached through its tunnel only. Names outside your Cloudflare zones stop working.'
                                            : 'Only Cloudflare reaches ports 80 and 443: names that are not proxied through Cloudflare (sslip.io, DNS only) stop working.'}
                                    </p>
                                )}
                                {server.tunnel && (
                                    <p className="text-fg-muted text-xs">
                                        {server.tunnel.error ??
                                            `Names in your zones are CNAMEs to ${server.tunnel.cname}. Once it shows healthy you can close the web ports.`}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>
                    {connections.length > 1 && (
                        <label className="text-fg-muted flex items-center gap-2 text-xs">
                            New tunnels use
                            <select
                                className="border-border bg-surface-2 text-fg rounded border px-2 py-1 text-xs"
                                value={tunnelConnection}
                                onChange={(event) => setTunnelConnection(event.target.value)}
                            >
                                {connections.map((connection) => (
                                    <option key={connection.id} value={connection.id}>
                                        {connection.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                    )}
                    <p className="text-fg-faint text-xs">
                        The token needs Account → Cloudflare Tunnel → Edit. Names outside your managed zones (sslip.io, other DNS providers) still
                        reach the server on its public IP.
                    </p>
                </Section>
            )}

            <ConfirmDestructive
                open={untunneling !== null}
                onOpenChange={(open) => !open && setUntunneling(null)}
                title={`Take ${untunneling?.name} off the tunnel?`}
                description="cloudflared is removed, the tunnel is deleted and the server's names point at its public IP again. Open ports 80 and 443 first if you closed them."
                confirmText={untunneling?.name ?? ''}
                confirmLabel="Back to public"
                onConfirm={() =>
                    new Promise<void>((resolve) => {
                        if (!untunneling?.tunnel) return resolve();
                        router.delete(`/settings/cloudflare/tunnels/${untunneling.tunnel.id}`, {
                            ...options,
                            onFinish: () => {
                                setUntunneling(null);
                                resolve();
                            },
                        });
                    })
                }
            />
            <ConfirmDestructive
                open={releasing !== null}
                onOpenChange={(open) => !open && setReleasing(null)}
                title={`Stop managing ${releasing?.name}?`}
                description="Falak stops changing DNS records in this zone. The records it created stay in Cloudflare, so your services keep resolving."
                confirmText={releasing?.name ?? ''}
                confirmLabel="Stop managing"
                onConfirm={() =>
                    // Resolves when the request is done, so the dialog stays busy and cannot be submitted twice.
                    new Promise<void>((resolve) => {
                        if (!releasing) return resolve();
                        router.delete(`/settings/cloudflare/zones/${releasing.id}`, {
                            ...options,
                            onFinish: () => {
                                setReleasing(null);
                                resolve();
                            },
                        });
                    })
                }
            />
            <ConfirmDestructive
                open={disconnecting !== null}
                onOpenChange={(open) => !open && setDisconnecting(null)}
                title={`Disconnect ${disconnecting?.name}?`}
                description="Its zones stop being managed (records stay in Cloudflare) and domains that got certificates through it switch back to HTTP validation."
                confirmText={disconnecting?.name ?? ''}
                confirmLabel="Disconnect"
                onConfirm={() =>
                    new Promise<void>((resolve) => {
                        if (!disconnecting) return resolve();
                        router.delete(`/settings/cloudflare/${disconnecting.id}`, {
                            ...options,
                            onFinish: () => {
                                setDisconnecting(null);
                                resolve();
                            },
                        });
                    })
                }
            />
        </SettingsLayout>
    );
}

/** Tunnel state: Falak's install status, then Cloudflare's live view (healthy / down, open connections). */
function TunnelTag({ tunnel }: { tunnel: NonNullable<TunnelServer['tunnel']> }) {
    if (tunnel.status === 'error') return <Tag tone="danger">tunnel error</Tag>;
    if (tunnel.status === 'installing') return <Tag tone="info">installing cloudflared</Tag>;

    const health = tunnel.health;

    return (
        <Tag tone={health?.status === 'healthy' ? 'success' : health ? 'warning' : 'neutral'}>
            tunnel · {health ? `${health.status}, ${health.connections} connection${health.connections === 1 ? '' : 's'}` : 'status unknown'}
        </Tag>
    );
}
