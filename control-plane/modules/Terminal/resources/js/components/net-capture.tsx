import { Button, Callout, Checkbox, Dialog, Field, Input, RelativeTime, Section, Segmented, Select, SkeletonRows } from '@/components/falak';
import { useJson } from '@/hooks/use-json';
import { HttpError, requestJson } from '@/lib/http';
import { type ServerSectionProps, type ServiceActionDialogProps } from '@/lib/registry';
import { useEffect, useState } from 'react';
import {
    CAPTURE_WARNING,
    EMPTY_CAPTURE,
    captureBody,
    describeFilter,
    describeProgress,
    elapsedFraction,
    formatBytes,
    isActive,
    type CaptureInterface,
    type NetCapture,
    type NetCaptureForm,
} from '../net-capture';

interface NetCapturePanelProps {
    /** POST endpoint that starts a capture (…/net-captures). */
    endpoint: string;
    target: { type: 'server' | 'site' | 'database'; id: string };
    /** Captures in a container's namespace (no interface choice). */
    inContainer: boolean;
    /** Extra body fields (a site's server and compose service). */
    extra?: Record<string, string | undefined>;
}

/**
 * Packet capture (docs/plans/network/capture.md): the form, a confirmation about sensitive data, the live progress with
 * Cancel, the download link, and the target's captures with their expiry.
 */
export function NetCapturePanel({ endpoint, target, inContainer, extra }: NetCapturePanelProps) {
    const [form, setForm] = useState<NetCaptureForm>(EMPTY_CAPTURE);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [confirming, setConfirming] = useState(false);
    const [starting, setStarting] = useState(false);
    const [currentId, setCurrentId] = useState<string | null>(null);
    const [now, setNow] = useState(() => Date.now());
    const history = useJson<NetCapture[]>(`/net-captures?target_type=${target.type}&target_id=${encodeURIComponent(target.id)}`);
    const polled = useJson<NetCapture>(currentId ? `/net-captures/${currentId}` : null, { interval: 1000 });
    const current = polled.data ?? history.data?.find((c) => c.id === currentId) ?? null;
    const live = current !== null && isActive(current);
    const set = <K extends keyof NetCaptureForm>(key: K, value: NetCaptureForm[K]) => setForm((f) => ({ ...f, [key]: value }));

    useEffect(() => {
        if (!live) return;
        const timer = window.setInterval(() => setNow(Date.now()), 500);
        return () => window.clearInterval(timer);
    }, [live]);

    useEffect(() => {
        if (current && !isActive(current)) void history.reload();
        // Reload the history once when the current capture settles.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [current?.status]);

    const start = async () => {
        setErrors({});
        setStarting(true);
        try {
            const extras = Object.fromEntries(Object.entries(extra ?? {}).filter(([, value]) => value));
            const response = await requestJson<{ data: NetCapture }>(endpoint, 'POST', { ...extras, ...captureBody(form, inContainer) });
            setCurrentId(response.data.id);
            void history.reload();
        } catch (error) {
            if (error instanceof HttpError && Object.keys(error.errors).length > 0) setErrors(error.errors);
            else setErrors({ form: error instanceof Error ? error.message : 'Could not start the capture.' });
        } finally {
            setStarting(false);
            setConfirming(false);
        }
    };

    const cancel = async (id: string) => {
        await requestJson(`/net-captures/${id}/cancel`, 'POST');
        void polled.reload();
    };

    const text = (key: 'duration_s' | 'max_mb' | 'host' | 'port', label: string, placeholder: string, error?: string) => (
        <Field label={label} error={error}>
            <Input mono value={form[key]} placeholder={placeholder} onChange={(event) => set(key, event.target.value)} />
        </Field>
    );
    const formError = errors.form ?? errors.acknowledged ?? errors.server ?? errors.site ?? errors.service ?? errors.instance ?? errors.filter;

    return (
        <div className="grid gap-4" data-testid="net-capture">
            <form
                className="grid gap-3 sm:grid-cols-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    setConfirming(true);
                }}
            >
                {!inContainer && (
                    <div className="sm:col-span-2">
                        <Segmented<CaptureInterface>
                            label="Interface"
                            value={form.interface}
                            onValueChange={(value) => set('interface', value)}
                            options={[
                                { value: 'default', label: 'Default route' },
                                { value: 'wireguard', label: 'WireGuard' },
                                { value: 'docker', label: 'Docker bridge' },
                            ]}
                        />
                        {errors.interface && <p className="text-danger mt-1 text-sm">{errors.interface}</p>}
                    </div>
                )}
                {text('duration_s', 'Duration (s, at most 60)', '30', errors.duration_s)}
                {text('max_mb', 'Size cap (MiB, at most 100)', '20', errors.max_mb)}
                {text('host', 'Host', 'IP or CIDR (optional)', errors['filter.host'])}
                {text('port', 'Port', 'optional', errors['filter.port'])}
                <Field label="Protocol" error={errors['filter.protocol']}>
                    <Select
                        value={form.protocol || 'any'}
                        onValueChange={(value) => set('protocol', value === 'any' ? '' : (value as NetCaptureForm['protocol']))}
                        options={[
                            { value: 'any', label: 'Any' },
                            { value: 'tcp', label: 'TCP' },
                            { value: 'udp', label: 'UDP' },
                            { value: 'icmp', label: 'ICMP' },
                        ]}
                    />
                </Field>
                <Field label="Direction" error={errors['filter.direction']}>
                    <Select
                        value={form.direction}
                        onValueChange={(value) => set('direction', value as NetCaptureForm['direction'])}
                        options={[
                            { value: 'both', label: 'Both' },
                            { value: 'in', label: 'Incoming' },
                            { value: 'out', label: 'Outgoing' },
                        ]}
                    />
                </Field>
                <Field label="Capture full packets (default: packet headers, which may include the first bytes of a payload)" inline>
                    <Checkbox checked={form.full_packets} onCheckedChange={(checked) => set('full_packets', checked === true)} />
                </Field>
                <div className="flex items-end gap-3 sm:col-span-2">
                    <Button type="submit" variant="primary" loading={starting} disabled={live}>
                        Start capture
                    </Button>
                    {formError && <p className="text-danger text-sm">{formError}</p>}
                </div>
            </form>

            <Dialog
                open={confirming}
                onOpenChange={setConfirming}
                size="sm"
                title="Capture packets?"
                description={CAPTURE_WARNING}
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setConfirming(false)}>
                            Cancel
                        </Button>
                        <Button variant="primary" loading={starting} onClick={() => void start()}>
                            Start capture
                        </Button>
                    </>
                }
            >
                {form.full_packets && (
                    <Callout tone="warning">Full packets include request and response bodies: passwords, session cookies and personal data.</Callout>
                )}
            </Dialog>

            {current && (
                <div className="border-border grid gap-2 rounded-md border p-3" data-testid="net-capture-current">
                    <div className="flex items-center gap-2 text-sm">
                        <span className="font-medium">{current.status}</span>
                        <span className="text-fg-muted">{describeProgress(current)}</span>
                        <span className="ml-auto flex gap-2">
                            {live && (
                                <Button size="sm" variant="ghost" disabled={current.cancel_requested} onClick={() => void cancel(current.id)}>
                                    {current.cancel_requested ? 'Cancelling…' : 'Cancel'}
                                </Button>
                            )}
                            {current.download_url && (
                                <Button size="sm" variant="primary" asChild>
                                    <a href={current.download_url}>Download pcap</a>
                                </Button>
                            )}
                        </span>
                    </div>
                    {current.status === 'capturing' && (
                        <div className="bg-surface-2 h-1.5 overflow-hidden rounded-full">
                            <div
                                className="bg-accent h-full transition-[width]"
                                style={{ width: `${Math.round(elapsedFraction(current, now) * 100)}%` }}
                            />
                        </div>
                    )}
                    {current.error && <p className="text-danger text-sm">{current.error}</p>}
                </div>
            )}

            {(history.data?.length ?? 0) > 0 && (
                <div className="grid gap-1">
                    <h3 className="text-fg-muted text-xs font-medium">Captures (kept 24 hours)</h3>
                    <ul className="grid gap-0.5">
                        {history.data?.map((item) => (
                            <li key={item.id} className="text-fg-muted flex gap-2 font-mono text-xs">
                                <button type="button" className="hover:text-fg truncate text-left" onClick={() => setCurrentId(item.id)}>
                                    {item.container ?? item.interface ?? 'host'} · {describeFilter(item.filter)} · {item.duration_s}s
                                </button>
                                <span className="ml-auto shrink-0">
                                    {item.status}
                                    {item.size_bytes !== null && ` · ${formatBytes(item.size_bytes)}`} · <RelativeTime value={item.created_at} />
                                    {item.download_url && (
                                        <>
                                            {' · '}
                                            <a className="text-accent hover:underline" href={item.download_url}>
                                                download
                                            </a>{' '}
                                            (expires <RelativeTime value={item.expires_at} />)
                                        </>
                                    )}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}

/** Server page section. */
export function ServerNetCapture({ serverId }: ServerSectionProps) {
    return (
        <Section
            id="packet-capture"
            title="Packet capture"
            description="Capture this server's traffic for up to 60 s and download it for Wireshark. Admins only; every capture and download is audited."
        >
            <NetCapturePanel endpoint={`/servers/${serverId}/net-captures`} target={{ type: 'server', id: serverId }} inContainer={false} />
        </Section>
    );
}

interface SiteOptions {
    servers: { id: string; name: string; leader: boolean }[];
    services: string[] | null;
    runtime: string;
}

/** Site `⋯` → Packet capture: in the site's container, or on the host for native runtimes. */
export function SiteNetCaptureDialog({ ctx, open, onOpenChange }: ServiceActionDialogProps) {
    const { data, error } = useJson<SiteOptions>(open ? `/sites/${ctx.service.ref_id}/net-captures` : null);
    const [server, setServer] = useState<string | undefined>(undefined);
    const [service, setService] = useState<string | undefined>(undefined);

    useEffect(() => {
        if (!data) return;
        setServer((current) => current ?? data.servers.find((s) => s.leader)?.id ?? data.servers[0]?.id);
        setService((current) => current ?? data.services?.[0]);
    }, [data]);

    const inContainer = data !== null && ['docker', 'compose'].includes(data.runtime);

    return (
        <Dialog
            open={open}
            onOpenChange={onOpenChange}
            size="lg"
            title="Packet capture"
            description={
                inContainer ? 'Captures on every interface of the site’s container.' : 'Native sites use the server’s network: pick an interface.'
            }
        >
            {error && <p className="text-danger text-sm">{error}</p>}
            {!data && !error && <SkeletonRows rows={3} />}
            {data && (
                <div className="grid gap-4">
                    {(data.servers.length > 1 || (data.services?.length ?? 0) > 1) && (
                        <div className="grid gap-3 sm:grid-cols-2">
                            {data.servers.length > 1 && (
                                <Field label="Server">
                                    <Select
                                        value={server}
                                        onValueChange={setServer}
                                        options={data.servers.map((s) => ({ value: s.id, label: s.leader ? `${s.name} (leader)` : s.name }))}
                                    />
                                </Field>
                            )}
                            {(data.services?.length ?? 0) > 1 && (
                                <Field label="Service">
                                    <Select
                                        value={service}
                                        onValueChange={setService}
                                        options={(data.services ?? []).map((name) => ({ value: name, label: name }))}
                                    />
                                </Field>
                            )}
                        </div>
                    )}
                    <NetCapturePanel
                        endpoint={`/sites/${ctx.service.ref_id}/net-captures`}
                        target={{ type: 'site', id: ctx.service.ref_id }}
                        inContainer={inContainer}
                        extra={{ server_id: server, service }}
                    />
                </div>
            )}
        </Dialog>
    );
}
