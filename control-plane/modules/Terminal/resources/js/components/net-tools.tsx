import { CommandLog } from '@/components/command-log';
import { Button, Checkbox, Dialog, Field, Input, RelativeTime, Section, Segmented, Select, SkeletonRows } from '@/components/falak';
import { useJson } from '@/hooks/use-json';
import { HttpError, requestJson } from '@/lib/http';
import { type ServerSectionProps, type ServiceActionDialogProps } from '@/lib/registry';
import { useEffect, useState } from 'react';
import {
    EMPTY_FORM,
    NET_TOOLS,
    RECORD_TYPES,
    TOOL_FIELDS,
    describeRun,
    netToolBody,
    type NetTool,
    type NetToolForm,
    type NetToolRun,
} from '../net-tools';

interface NetToolsProps {
    /** POST endpoint that starts a run (…/net-tools). */
    endpoint: string;
    /** Where the run's history is listed. */
    target: { type: 'server' | 'site' | 'database'; id: string };
    /** Extra body fields (a site's server and compose service). */
    extra?: Record<string, string | undefined>;
}

/**
 * Network tools (docs/plans/network/tools.md): one tool's fields, Run, the live output (the net.tool command's log) and
 * the user's last runs of this target.
 */
export function NetTools({ endpoint, target, extra }: NetToolsProps) {
    const [form, setForm] = useState<NetToolForm>(EMPTY_FORM);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [running, setRunning] = useState(false);
    const [run, setRun] = useState<NetToolRun | null>(null);
    const history = useJson<NetToolRun[]>(`/net-tools/runs?target_type=${target.type}&target_id=${encodeURIComponent(target.id)}`);
    const fields = TOOL_FIELDS[form.tool];
    const set = <K extends keyof NetToolForm>(key: K, value: NetToolForm[K]) => setForm((current) => ({ ...current, [key]: value }));

    const submit = async () => {
        setErrors({});
        setRunning(true);
        try {
            const extras = Object.fromEntries(Object.entries(extra ?? {}).filter(([, value]) => value));
            const response = await requestJson<{ data: NetToolRun }>(endpoint, 'POST', { ...extras, ...netToolBody(form) });
            setRun(response.data);
            void history.reload();
        } catch (error) {
            if (error instanceof HttpError && Object.keys(error.errors).length > 0) setErrors(error.errors);
            else setErrors({ tool: error instanceof Error ? error.message : 'Could not run the tool.' });
        } finally {
            setRunning(false);
        }
    };

    const text = (key: keyof NetToolForm, label: string, placeholder: string, hint?: string) => (
        <Field label={label} hint={hint} error={errors[key]}>
            <Input mono value={String(form[key])} placeholder={placeholder} onChange={(event) => set(key, event.target.value as never)} />
        </Field>
    );

    return (
        <div className="grid gap-4" data-testid="net-tools">
            <Segmented<NetTool> label="Tool" value={form.tool} onValueChange={(tool) => set('tool', tool)} options={NET_TOOLS} />
            <form
                className="grid gap-3 sm:grid-cols-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    void submit();
                }}
            >
                {fields.includes('target') && text('target', 'Host', 'example.com or 10.0.0.5')}
                {fields.includes('url') && <div className="sm:col-span-2">{text('url', 'URL', 'https://example.com/health')}</div>}
                {fields.includes('port') && text('port', 'Port', '443')}
                {fields.includes('count') && text('count', form.tool === 'tcp' ? 'Attempts' : 'Packets', form.tool === 'tcp' ? '3' : '4')}
                {fields.includes('max_hops') && text('max_hops', 'Max hops', '20')}
                {fields.includes('record_type') && (
                    <Field label="Record type" error={errors.record_type}>
                        <Select
                            value={form.record_type}
                            onValueChange={(value) => set('record_type', value)}
                            options={RECORD_TYPES.map((type) => ({ value: type, label: type }))}
                        />
                    </Field>
                )}
                {fields.includes('nameserver') &&
                    text('nameserver', 'Nameserver', 'default resolver', 'An IP; empty asks the resolver the server or container uses.')}
                {fields.includes('method') && (
                    <Field label="Method" error={errors.method}>
                        <Select
                            value={form.method}
                            onValueChange={(value) => set('method', value)}
                            options={['GET', 'HEAD'].map((m) => ({ value: m, label: m }))}
                        />
                    </Field>
                )}
                {fields.includes('connect_to') &&
                    text('connect_to', 'Connect to', 'IP (optional)', 'Skip DNS and connect to this IP, e.g. the origin behind Cloudflare.')}
                {fields.includes('insecure') && (
                    <Field label="Skip certificate verification" inline error={errors.insecure}>
                        <Checkbox checked={form.insecure} onCheckedChange={(checked) => set('insecure', checked === true)} />
                    </Field>
                )}
                {text('timeout_s', 'Timeout (s)', '30')}
                <div className="flex items-end gap-3 sm:col-span-2">
                    <Button type="submit" variant="primary" loading={running}>
                        Run
                    </Button>
                    {(errors.tool || errors.server || errors.site || errors.service || errors.instance) && (
                        <p className="text-danger text-sm">{errors.tool ?? errors.server ?? errors.site ?? errors.service ?? errors.instance}</p>
                    )}
                </div>
            </form>
            {run?.command_id && (
                <div className="grid gap-1">
                    <p className="text-fg-muted text-xs">
                        {describeRun(run)} · {run.container ? `in ${run.container} on ` : 'on '}
                        {run.server_name}
                    </p>
                    <CommandLog commandId={run.command_id} onStatusChange={() => void history.reload()} />
                </div>
            )}
            {(history.data?.length ?? 0) > 0 && (
                <div className="grid gap-1">
                    <h3 className="text-fg-muted text-xs font-medium">Your last runs</h3>
                    <ul className="grid gap-0.5">
                        {history.data?.map((item) => (
                            <li key={item.id}>
                                <button
                                    type="button"
                                    className="hover:text-fg text-fg-muted flex w-full gap-2 text-left font-mono text-xs"
                                    onClick={() => setRun(item)}
                                >
                                    <span className="truncate">{describeRun(item)}</span>
                                    <span className="ml-auto shrink-0">
                                        {item.status} · <RelativeTime value={item.created_at} />
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}

/** Server page section. */
export function ServerNetTools({ serverId }: ServerSectionProps) {
    return (
        <Section
            id="network-tools"
            title="Network tools"
            description="Ping, traceroute, DNS, HTTP and TCP checks from this server. Every run is audited."
        >
            <NetTools endpoint={`/servers/${serverId}/net-tools`} target={{ type: 'server', id: serverId }} />
        </Section>
    );
}

interface SiteOptions {
    servers: { id: string; name: string; leader: boolean }[];
    services: string[] | null;
    runtime: string;
}

/** Site header `⋯` → Network tools: from where the site runs (its container, or the host for native runtimes). */
export function SiteNetToolsDialog({ ctx, open, onOpenChange }: ServiceActionDialogProps) {
    const { data, error } = useJson<SiteOptions>(open ? `/sites/${ctx.service.ref_id}/net-tools` : null);
    const [server, setServer] = useState<string | undefined>(undefined);
    const [service, setService] = useState<string | undefined>(undefined);

    useEffect(() => {
        if (!data) return;
        setServer((current) => current ?? data.servers.find((s) => s.leader)?.id ?? data.servers[0]?.id);
        setService((current) => current ?? data.services?.[0]);
    }, [data]);

    const native = data !== null && !['docker', 'compose'].includes(data.runtime);

    return (
        <Dialog
            open={open}
            onOpenChange={onOpenChange}
            size="lg"
            title="Network tools"
            description={
                native
                    ? 'Runs on the server’s network (native sites use the host network).'
                    : 'Runs in the site’s container network: its DNS, its networks.'
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
                    <NetTools
                        endpoint={`/sites/${ctx.service.ref_id}/net-tools`}
                        target={{ type: 'site', id: ctx.service.ref_id }}
                        extra={{ server_id: server, service }}
                    />
                </div>
            )}
        </Dialog>
    );
}
