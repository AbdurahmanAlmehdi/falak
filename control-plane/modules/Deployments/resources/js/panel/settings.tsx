import {
    Button,
    Callout,
    CopyButton,
    Field,
    Input,
    ManagedBadge,
    Section,
    Select,
    SkeletonRows,
    Switch,
    managedCovers,
    toast,
    type ManagedConfig,
} from '@/components/falak';
import { useJson } from '@/hooks/use-json';
import { HttpError, errorMessage, requestJson, type HttpMethod } from '@/lib/http';
import { type ServiceTabProps } from '@/lib/registry';
import { Link2Off, RefreshCw } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';

interface DeploySettings {
    strategy: string;
    batch_size: number;
    keep_releases: number;
    health_enabled: boolean;
    health_path: string | null;
    health_status: number;
    health_timeout_s: number;
    health_retries: number;
    health_retry_delay_s: number;
    secrets_mode: 'env' | 'files';
}

/** GET /sites/{site}/deploy-settings (JSON). */
interface DeploySettingsData {
    settings: DeploySettings;
    defaultHealthPath: string;
    /** Container sites can take their secret variables as files. */
    secretFiles: boolean;
    strategies: { value: string; label: string; description: string }[];
    pushToDeploy: boolean;
    hasRepository: boolean;
    branch: string | null;
    hookUrl: string | null;
    hasHook: boolean;
    watch: WatchSettings & { migrations: boolean; production: boolean };
    can: { manage: boolean };
    /** Settings the project's falak.yaml manages (/deploy/...): read-only here. Null when none. */
    config: ManagedConfig | null;
}

/** Input fields => what they are in falak.yaml (pointers relative to the unit). */
const DEPLOY_PATHS: Partial<Record<keyof DeploySettings, string>> = {
    strategy: '/deploy/strategy',
    batch_size: '/deploy/batch_size',
    keep_releases: '/deploy/keep_releases',
    secrets_mode: '/deploy/secrets',
    health_enabled: '/deploy/health/enabled',
    health_path: '/deploy/health/path',
    health_status: '/deploy/health/status',
    health_timeout_s: '/deploy/health/timeout',
    health_retries: '/deploy/health/retries',
    health_retry_delay_s: '/deploy/health/retry_delay',
};

/** Rollback after a release goes live (opt-in). */
interface WatchSettings {
    enabled: boolean;
    minutes: number;
    health: boolean;
    health_failures: number;
    crashes: boolean;
    errors: boolean;
    issues: boolean;
    on_trigger: 'rollback' | 'alert_only';
}

const url = (siteId: string) => `/sites/${siteId}/deploy-settings`;

function useDeploySettings(ctx: ServiceTabProps['ctx']) {
    return useJson<DeploySettingsData>(url(ctx.service.ref_id));
}

async function send(
    method: HttpMethod,
    target: string,
    body: unknown,
    success: string,
    reload: () => Promise<void>,
): Promise<Record<string, string> | null> {
    try {
        await requestJson(target, method, body);
        toast.success(success);
        await reload();

        return null;
    } catch (error) {
        if (error instanceof HttpError && Object.keys(error.errors).length) return error.errors;
        toast.error('Could not save', errorMessage(error));

        return {};
    }
}

/** Deploy section: strategy, batch size, release retention and the health check gate. */
export function DeployStrategySettings({ ctx }: ServiceTabProps) {
    const { data, error, reload } = useDeploySettings(ctx);
    const [form, setForm] = useState<DeploySettings | null>(data?.settings ?? null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    useEffect(() => setForm(data?.settings ?? null), [data?.settings]);

    if (!data || !form) return error ? <Callout tone="danger">{error}</Callout> : <SkeletonRows rows={5} />;

    const manage = data.can.manage;
    const managed = (key: keyof DeploySettings) => managedCovers(data.config, DEPLOY_PATHS[key] ?? '');
    const lock = (key: keyof DeploySettings) => !manage || managed(key);
    const badge = (key: keyof DeploySettings) => (managed(key) ? <ManagedBadge config={data.config} /> : undefined);
    const dirty = JSON.stringify(form) !== JSON.stringify(data.settings);
    const set = (patch: Partial<DeploySettings>) => setForm({ ...form, ...patch });
    const number = (key: keyof DeploySettings, label: string, hint?: string) => (
        <Field label={label} hint={hint} error={errors[key]} aside={badge(key)}>
            <Input
                mono
                inputMode="numeric"
                disabled={lock(key)}
                value={String(form[key] ?? '')}
                onChange={(event) => set({ [key]: Number(event.target.value.replace(/\D/g, '') || 0) })}
            />
        </Field>
    );
    const strategy = data.strategies.find((item) => item.value === form.strategy);

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setSaving(true);
        setErrors((await send('PUT', url(ctx.service.ref_id), form, 'Deploy settings saved', reload)) ?? {});
        setSaving(false);
    };

    return (
        <Section
            title="Strategy & health check"
            description="How releases reach the servers, how many are kept for rollbacks, and what counts as healthy."
            footer={
                manage && (
                    <>
                        <Button variant="ghost" disabled={!dirty || saving} onClick={() => setForm(data.settings)}>
                            Reset
                        </Button>
                        <Button variant="primary" type="submit" form="deploy-strategy" loading={saving} disabled={!dirty}>
                            Save
                        </Button>
                    </>
                )
            }
        >
            <form id="deploy-strategy" onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                <Field label="Strategy" error={errors.strategy} hint={strategy?.description} className="sm:col-span-2" aside={badge('strategy')}>
                    <Select
                        value={form.strategy}
                        disabled={lock('strategy')}
                        onValueChange={(value) => set({ strategy: value })}
                        options={data.strategies.map((item) => ({ value: item.value, label: item.label }))}
                    />
                </Field>
                {form.strategy === 'rolling' && number('batch_size', 'Batch size', 'Servers updated at a time.')}
                {number('keep_releases', 'Releases to keep', 'Older releases are pruned after each deploy.')}
                {data.secretFiles && (
                    <Field
                        label="Secrets"
                        error={errors.secrets_mode}
                        hint={
                            form.secrets_mode === 'files'
                                ? 'Secret variables are files in /run/secrets (e.g. /run/secrets/DB_PASSWORD), not environment variables, so docker inspect never shows them. Applies from the next deploy.'
                                : 'Secret variables are passed as environment variables. Applies from the next deploy.'
                        }
                        className="sm:col-span-2"
                        aside={badge('secrets_mode')}
                    >
                        <Select
                            value={form.secrets_mode}
                            disabled={lock('secrets_mode')}
                            onValueChange={(value) => set({ secrets_mode: value === 'files' ? 'files' : 'env' })}
                            options={[
                                { value: 'env', label: 'Environment variables' },
                                { value: 'files', label: 'Files in /run/secrets' },
                            ]}
                        />
                    </Field>
                )}
                <div className="grid gap-3 sm:col-span-2">
                    <Field inline label="Health check gates activation (failed checks roll the server back)" aside={badge('health_enabled')}>
                        <Switch
                            checked={form.health_enabled}
                            disabled={lock('health_enabled')}
                            onCheckedChange={(on) => set({ health_enabled: on })}
                        />
                    </Field>
                </div>
                {form.health_enabled && (
                    <>
                        <Field label="Path" error={errors.health_path} hint={`Default ${data.defaultHealthPath}`} aside={badge('health_path')}>
                            <Input
                                mono
                                disabled={lock('health_path')}
                                placeholder={data.defaultHealthPath}
                                value={form.health_path ?? ''}
                                onChange={(event) => set({ health_path: event.target.value || null })}
                            />
                        </Field>
                        {number('health_status', 'Expected status')}
                        {number('health_timeout_s', 'Timeout (s)')}
                        {number('health_retries', 'Attempts')}
                        {number('health_retry_delay_s', 'Delay between attempts (s)')}
                    </>
                )}
            </form>
        </Section>
    );
}

const pickWatch = ({ enabled, minutes, health, health_failures, crashes, errors, issues, on_trigger }: WatchSettings): WatchSettings => ({
    enabled,
    minutes,
    health,
    health_failures,
    crashes,
    errors,
    issues,
    on_trigger,
});

/** Deploy section: watch the release after it goes live and roll back (or alert) when a trigger fires. */
export function WatchAfterDeploySettings({ ctx }: ServiceTabProps) {
    const { data, error, reload } = useDeploySettings(ctx);
    const initial = data ? pickWatch(data.watch) : null;
    const [form, setForm] = useState<WatchSettings | null>(initial);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    useEffect(() => setForm(data ? pickWatch(data.watch) : null), [data]);

    if (!data || !form || !initial) return error ? <Callout tone="danger">{error}</Callout> : <SkeletonRows rows={3} />;

    const manage = data.can.manage;
    const managed = (key: keyof WatchSettings) => managedCovers(data.config, `/deploy/watch/${key}`);
    const lock = (key: keyof WatchSettings) => !manage || managed(key);
    const badge = (key: keyof WatchSettings) => (managed(key) ? <ManagedBadge config={data.config} /> : undefined);
    const dirty = JSON.stringify(form) !== JSON.stringify(initial);
    const set = (patch: Partial<WatchSettings>) => setForm({ ...form, ...patch });
    const toggle = (key: 'health' | 'crashes' | 'errors' | 'issues', label: string) => (
        <Field inline label={label} aside={badge(key)}>
            <Switch checked={form[key]} disabled={lock(key) || !form.enabled} onCheckedChange={(on) => set({ [key]: on })} />
        </Field>
    );

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setSaving(true);
        setErrors((await send('PUT', `${url(ctx.service.ref_id)}/watch`, form, 'Watch after deploy saved', reload)) ?? {});
        setSaving(false);
    };

    return (
        <Section
            title="Watch after deploy"
            description="After a deployment goes live, watch it for a few minutes and roll back to the previous release if it turns unhealthy. Not for the first deployment, nor for rollbacks."
            footer={
                manage && (
                    <>
                        <Button variant="ghost" disabled={!dirty || saving} onClick={() => setForm(initial)}>
                            Reset
                        </Button>
                        <Button variant="primary" type="submit" form="deploy-watch" loading={saving} disabled={!dirty}>
                            Save
                        </Button>
                    </>
                )
            }
        >
            <form id="deploy-watch" onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                <Field inline label="Watch each release after it goes live" className="sm:col-span-2" aside={badge('enabled')}>
                    <Switch checked={form.enabled} disabled={lock('enabled')} onCheckedChange={(on) => set({ enabled: on })} />
                </Field>
                {!form.enabled && data.watch.production && (
                    <Callout tone="info" className="sm:col-span-2">
                        Suggested for production services: a release that fails after going live is rolled back within minutes.
                    </Callout>
                )}
                {form.enabled && (
                    <>
                        <Field label="Watch for (minutes)" error={errors.minutes} hint="1–60 minutes." aside={badge('minutes')}>
                            <Input
                                mono
                                inputMode="numeric"
                                disabled={lock('minutes')}
                                value={String(form.minutes)}
                                onChange={(event) => set({ minutes: Number(event.target.value.replace(/\D/g, '') || 0) })}
                            />
                        </Field>
                        <Field label="When a trigger fires" error={errors.on_trigger} aside={badge('on_trigger')}>
                            <Select
                                value={form.on_trigger}
                                disabled={lock('on_trigger')}
                                onValueChange={(value) => set({ on_trigger: value === 'alert_only' ? 'alert_only' : 'rollback' })}
                                options={[
                                    { value: 'rollback', label: 'Roll back and alert' },
                                    { value: 'alert_only', label: 'Alert only' },
                                ]}
                            />
                        </Field>
                        {data.watch.migrations && (
                            <Callout tone="warning" title="This service runs database migrations" className="sm:col-span-2">
                                A rollback returns to the previous code but does not reverse migrations. If the previous release can&apos;t run on the
                                migrated schema, choose &quot;Alert only&quot;.
                            </Callout>
                        )}
                        <div className="grid gap-3 sm:col-span-2">
                            <h4 className="text-fg-muted text-xs font-medium">Triggers</h4>
                            {toggle('health', 'The health check fails several times in a row (through the edge, every 30 s)')}
                            {form.health && !data.settings.health_enabled && (
                                <p className="text-fg-muted text-xs">
                                    The site&apos;s health check is off (Strategy &amp; health check above), so this trigger is skipped.
                                </p>
                            )}
                            {form.health && data.settings.health_enabled && (
                                <Field
                                    label="Failures in a row"
                                    error={errors.health_failures}
                                    hint="Uses the health check path and status above."
                                    aside={badge('health_failures')}
                                >
                                    <Input
                                        mono
                                        inputMode="numeric"
                                        disabled={lock('health_failures')}
                                        value={String(form.health_failures)}
                                        onChange={(event) => set({ health_failures: Number(event.target.value.replace(/\D/g, '') || 0) })}
                                    />
                                </Field>
                            )}
                            {toggle('crashes', 'A process crash-loops or is killed for running out of memory')}
                            {toggle('errors', '5xx responses above 3× the previous release’s rate (at least 5%)')}
                            {toggle('issues', 'A new error appears in Insights')}
                        </div>
                    </>
                )}
            </form>
        </Section>
    );
}

/** Deploy section: the secret deploy hook URL (rotate = refresh, disable). */
export function DeployHookSettings({ ctx }: ServiceTabProps) {
    const { data, error, reload } = useDeploySettings(ctx);
    const [busy, setBusy] = useState<'rotate' | 'disable' | null>(null);

    if (!data) return error ? <Callout tone="danger">{error}</Callout> : <SkeletonRows rows={2} />;

    const run = async (kind: 'rotate' | 'disable') => {
        if (kind === 'disable' && !window.confirm('Disable the deploy hook? Anything calling it stops deploying.')) return;
        if (kind === 'rotate' && data.hasHook && !window.confirm('Generate a new URL? The current one stops working.')) return;
        setBusy(kind);
        await send(
            kind === 'rotate' ? 'POST' : 'DELETE',
            `${url(ctx.service.ref_id)}/hook`,
            {},
            kind === 'rotate' ? 'New deploy hook URL' : 'Deploy hook disabled',
            reload,
        );
        setBusy(null);
    };

    return (
        <Section
            title="Deploy hook"
            description="POST to this secret URL (CI, a CMS publish button…) to start a deployment. Treat it like a password."
            aside={
                data.can.manage && (
                    <>
                        {data.hasHook && (
                            <Button size="sm" variant="ghost" icon={<Link2Off />} loading={busy === 'disable'} onClick={() => void run('disable')}>
                                Disable
                            </Button>
                        )}
                        <Button size="sm" icon={<RefreshCw />} loading={busy === 'rotate'} onClick={() => void run('rotate')}>
                            {data.hasHook ? 'Refresh URL' : 'Create URL'}
                        </Button>
                    </>
                )
            }
        >
            {data.hookUrl ? (
                <div className="flex items-center gap-2">
                    <Input mono readOnly aria-label="Deploy hook URL" value={data.hookUrl} onFocus={(event) => event.target.select()} />
                    <CopyButton value={data.hookUrl} label="Copy deploy hook URL" />
                </div>
            ) : (
                <p className="text-fg-muted text-sm">
                    {data.hasHook ? 'A deploy hook is configured (only managers can see its URL).' : 'No deploy hook yet.'}
                </p>
            )}
        </Section>
    );
}

/** Source section: deploy on every push to the branch. */
export function PushToDeploySettings({ ctx }: ServiceTabProps) {
    const { data, error, reload } = useDeploySettings(ctx);
    const [saving, setSaving] = useState(false);

    if (!data) return error ? <Callout tone="danger">{error}</Callout> : <SkeletonRows rows={1} />;

    return (
        <Section title="Push to deploy">
            <Field
                inline
                label={
                    data.hasRepository
                        ? `Deploy automatically when ${data.branch ?? 'the branch'} receives a push`
                        : 'Connect a repository to deploy on push'
                }
            >
                <Switch
                    checked={data.pushToDeploy}
                    disabled={!data.can.manage || !data.hasRepository || saving}
                    onCheckedChange={async (enabled) => {
                        setSaving(true);
                        await send(
                            'PUT',
                            `${url(ctx.service.ref_id)}/push-to-deploy`,
                            { enabled },
                            enabled ? 'Push to deploy enabled' : 'Push to deploy disabled',
                            reload,
                        );
                        setSaving(false);
                    }}
                />
            </Field>
        </Section>
    );
}
