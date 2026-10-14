import { Button } from '@/components/falak/button';
import { Callout } from '@/components/falak/callout';
import { Field } from '@/components/falak/field';
import { Input } from '@/components/falak/input';
import { IntegrationIcon } from '@/components/falak/integration-icon';
import { RelativeTime } from '@/components/falak/relative-time';
import { SecretInput } from '@/components/falak/secret-input';
import { Section } from '@/components/falak/section';
import { StatusBadge } from '@/components/falak/status';
import { Switch } from '@/components/falak/switch';
import SettingsLayout from '@/layouts/settings/layout';
import { router, useForm, usePage } from '@inertiajs/react';
import { ExternalLink, LayoutDashboard, RefreshCw } from 'lucide-react';
import { useState, type FormEventHandler, type ReactNode } from 'react';

interface SettingsValues {
    otlp_endpoint: string | null;
    otlp_token_set: boolean;
    environment: string | null;
    traces_ratio: number | null;
    metrics_interval_s: number | null;
    request_id_linking: boolean;
}

interface Props {
    settings: SettingsValues;
    defaults: { otlp_endpoint: string; otlp_token_set: boolean; environment: string; traces_ratio: number; metrics_interval_s: number };
    backends: {
        metrics: { backend: string; configured: boolean };
        loki: { configured: boolean };
        tempo: { configured: boolean };
        grafana: { configured: boolean; provisioned_at: string | null; last_error: string | null; dashboards: { uid: string; url: string | null }[] };
    };
    can: { manage: boolean };
}

function inlineErrors(data: { otlp_endpoint: string; environment: string; traces_ratio: string; metrics_interval_s: string }) {
    const errors: Partial<Record<keyof typeof data, string>> = {};

    if (data.otlp_endpoint && !/^https?:\/\/\S+$/.test(data.otlp_endpoint)) errors.otlp_endpoint = 'Use an http:// or https:// URL.';
    if (data.environment && !/^[A-Za-z0-9_.-]+$/.test(data.environment)) errors.environment = 'Letters, digits, dots, dashes and underscores.';
    if (data.traces_ratio !== '' && !(Number(data.traces_ratio) >= 0 && Number(data.traces_ratio) <= 1)) errors.traces_ratio = 'Between 0 and 1.';
    if (data.metrics_interval_s !== '' && !(Number(data.metrics_interval_s) >= 5 && Number(data.metrics_interval_s) <= 3600)) {
        errors.metrics_interval_s = 'Between 5 and 3600 seconds.';
    }

    return errors;
}

function BackendRow({ icon, label, detail, configured }: { icon: string; label: string; detail: ReactNode; configured: boolean }) {
    return (
        <li className="flex items-center gap-3 py-2.5">
            <span className="border-border bg-surface-2 flex size-8 shrink-0 items-center justify-center rounded-md border">
                <IntegrationIcon name={icon} />
            </span>
            <span className="grid min-w-0 flex-1">
                <span className="text-fg text-sm font-medium">{label}</span>
                <span className="text-fg-faint truncate text-xs">{detail}</span>
            </span>
            <StatusBadge status={configured ? 'active' : 'inactive'} label={configured ? 'Connected' : 'Not configured'} />
        </li>
    );
}

export default function Settings({ settings, defaults, backends, can }: Props) {
    const { errors: pageErrors } = usePage<{ errors: Record<string, string | undefined> }>().props;
    const [provisioning, setProvisioning] = useState(false);
    const form = useForm({
        otlp_endpoint: settings.otlp_endpoint ?? '',
        otlp_token: '',
        clear_otlp_token: false,
        environment: settings.environment ?? '',
        traces_ratio: settings.traces_ratio?.toString() ?? '',
        metrics_interval_s: settings.metrics_interval_s?.toString() ?? '',
        request_id_linking: settings.request_id_linking,
    });
    const hints = inlineErrors(form.data);
    const error = (key: keyof typeof hints) => form.errors[key] ?? hints[key];

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.put(route('telemetry.settings.update'), {
            preserveScroll: true,
            onSuccess: () => {
                form.setDefaults({ ...form.data, otlp_token: '', clear_otlp_token: false });
                form.reset('otlp_token', 'clear_otlp_token');
            },
        });
    };

    const provision = () => {
        setProvisioning(true);
        router.post(route('telemetry.grafana.provision'), {}, { preserveScroll: true, onFinish: () => setProvisioning(false) });
    };

    const tokenHint = form.data.clear_otlp_token
        ? 'Will be removed on save'
        : settings.otlp_token_set
          ? 'Organization token set'
          : defaults.otlp_token_set
            ? 'Using the installation default'
            : 'No token';

    return (
        <SettingsLayout
            title="Observability"
            description="Where server agents ship OTLP traces, logs and metrics for this organization, and the backends Falak queries for charts and log search."
        >
            <form onSubmit={submit}>
                <Section
                    title="Agent pipeline"
                    description="Empty fields use the installation defaults shown as placeholders. Saving pushes the configuration to every active server."
                    footer={
                        can.manage && (
                            <>
                                {form.isDirty && <span className="text-fg-faint mr-auto text-xs">Unsaved changes</span>}
                                <Button
                                    variant="primary"
                                    type="submit"
                                    loading={form.processing}
                                    disabled={!form.isDirty || Object.keys(hints).length > 0}
                                >
                                    Save and reconfigure servers
                                </Button>
                            </>
                        )
                    }
                >
                    <Field label="OTLP/HTTP endpoint" error={error('otlp_endpoint')}>
                        <Input
                            mono
                            value={form.data.otlp_endpoint}
                            onChange={(event) => form.setData('otlp_endpoint', event.target.value)}
                            placeholder={defaults.otlp_endpoint || 'https://otlp.example.com'}
                            disabled={!can.manage}
                        />
                    </Field>
                    <Field
                        label="Bearer token"
                        error={form.errors.otlp_token}
                        hint="Sent by agents as Authorization: Bearer … — write-only."
                        aside={
                            can.manage &&
                            settings.otlp_token_set && (
                                <button
                                    type="button"
                                    className="text-fg-faint hover:text-danger text-xs"
                                    onClick={() => form.setData({ ...form.data, otlp_token: '', clear_otlp_token: !form.data.clear_otlp_token })}
                                >
                                    {form.data.clear_otlp_token ? 'Keep token' : 'Remove token'}
                                </button>
                            )
                        }
                    >
                        <SecretInput
                            stored={settings.otlp_token_set && !form.data.clear_otlp_token}
                            storedHint={tokenHint}
                            value={form.data.otlp_token}
                            onChange={(value) => form.setData('otlp_token', value)}
                            placeholder={tokenHint}
                            disabled={!can.manage}
                        />
                    </Field>
                    <div className="grid items-start gap-4 sm:grid-cols-3">
                        <Field label="Environment" error={error('environment')}>
                            <Input
                                mono
                                value={form.data.environment}
                                onChange={(event) => form.setData('environment', event.target.value)}
                                placeholder={defaults.environment}
                                disabled={!can.manage}
                            />
                        </Field>
                        <Field label="Trace sampling" hint="0 – 1" error={error('traces_ratio')}>
                            <Input
                                type="number"
                                step="0.01"
                                min={0}
                                max={1}
                                value={form.data.traces_ratio}
                                onChange={(event) => form.setData('traces_ratio', event.target.value)}
                                placeholder={String(defaults.traces_ratio)}
                                disabled={!can.manage}
                            />
                        </Field>
                        <Field label="Metrics interval" error={error('metrics_interval_s')}>
                            <Input
                                type="number"
                                min={5}
                                max={3600}
                                value={form.data.metrics_interval_s}
                                onChange={(event) => form.setData('metrics_interval_s', event.target.value)}
                                placeholder={String(defaults.metrics_interval_s)}
                                suffix={<span className="text-xs">s</span>}
                                disabled={!can.manage}
                            />
                        </Field>
                    </div>
                    <Field
                        label="Request IDs"
                        hint="The edge adds an X-Request-Id to requests that carry none (or a malformed one), passes it to the app and records it in the access log, so an access line links to the app's logs. Saving applies the edge configuration of every server whose agent supports it."
                        inline
                    >
                        <Switch
                            checked={form.data.request_id_linking}
                            onCheckedChange={(checked) => form.setData('request_id_linking', checked)}
                            disabled={!can.manage}
                            aria-label="Request IDs"
                        />
                    </Field>
                </Section>
            </form>

            <Section title="Backends" description="Installation-wide, configured with FALAK_* environment variables on the control plane.">
                <ul className="divide-border -my-2 divide-y">
                    <BackendRow
                        icon={backends.metrics.backend.toLowerCase().includes('victoria') ? 'victoriametrics' : 'prometheus'}
                        label="Metrics"
                        detail={backends.metrics.backend}
                        configured={backends.metrics.configured}
                    />
                    <BackendRow icon="grafana" label="Logs" detail="Loki" configured={backends.loki.configured} />
                    <BackendRow icon="grafana" label="Traces" detail="Tempo" configured={backends.tempo.configured} />
                    <BackendRow icon="grafana" label="Dashboards" detail="Grafana" configured={backends.grafana.configured} />
                </ul>
            </Section>

            <Section
                title="Grafana dashboards"
                description={
                    backends.grafana.provisioned_at ? (
                        <>
                            Provisioned <RelativeTime value={backends.grafana.provisioned_at} /> into this organization's Grafana folder.
                        </>
                    ) : (
                        'Falak creates a folder and dashboards for this organization in Grafana.'
                    )
                }
                aside={
                    can.manage && (
                        <Button
                            size="sm"
                            icon={<RefreshCw />}
                            loading={provisioning}
                            onClick={provision}
                            disabled={!backends.grafana.configured}
                            title={backends.grafana.configured ? undefined : 'Set FALAK_GRAFANA_URL and FALAK_GRAFANA_TOKEN first'}
                        >
                            {backends.grafana.provisioned_at ? 'Re-provision' : 'Provision'}
                        </Button>
                    )
                }
            >
                {(pageErrors.grafana ?? backends.grafana.last_error) && (
                    <Callout tone="danger" title="Provisioning failed">
                        {pageErrors.grafana ?? backends.grafana.last_error}
                    </Callout>
                )}
                {backends.grafana.dashboards.length === 0 ? (
                    <p className="text-fg-muted flex items-center gap-2 text-sm">
                        <LayoutDashboard className="text-fg-faint size-4" aria-hidden />
                        {backends.grafana.configured
                            ? 'No dashboards yet — provision them to get server, site and database dashboards.'
                            : 'Grafana is not configured on this installation. Charts inside Falak still work with a metrics backend.'}
                    </p>
                ) : (
                    <ul className="grid gap-1.5 sm:grid-cols-2">
                        {backends.grafana.dashboards.map((dashboard) => (
                            <li key={dashboard.uid}>
                                {dashboard.url ? (
                                    <a
                                        href={dashboard.url}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="border-border hover:bg-surface-2 text-fg flex items-center gap-2 rounded-md border px-3 py-2 text-sm transition-colors"
                                    >
                                        <LayoutDashboard className="text-fg-faint size-4" aria-hidden />
                                        <span className="truncate font-mono text-xs">{dashboard.uid}</span>
                                        <ExternalLink className="text-fg-faint ml-auto size-3.5" aria-hidden />
                                    </a>
                                ) : (
                                    <span className="border-border text-fg-muted flex items-center gap-2 rounded-md border px-3 py-2 font-mono text-xs">
                                        {dashboard.uid}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Section>
        </SettingsLayout>
    );
}
