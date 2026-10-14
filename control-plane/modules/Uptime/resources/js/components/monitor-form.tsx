import { Button, Dialog, Field, Input, Select, Switch } from '@/components/falak';
import { HttpError, requestJson } from '@/lib/http';
import { useState, type FormEvent } from 'react';
import { isValidExpectedStatus, type MonitorType, type UptimeLimits, type UptimeMonitor } from '../lib';

interface Props {
    /** Edit this monitor; null creates one. */
    monitor: UptimeMonitor | null;
    limits: UptimeLimits;
    /** New monitors: attach them to this site. */
    siteId?: string | null;
    sites?: Array<{ id: string; name: string }>;
    onClose: () => void;
    onSaved: (monitor: UptimeMonitor) => void;
}

/** Create / edit a monitor. Site domain monitors keep their URL; only the check settings change. */
export function MonitorForm({ monitor, limits, siteId = null, sites = [], onClose, onSaved }: Props) {
    const [form, setForm] = useState({
        type: (monitor?.type ?? 'http') as MonitorType,
        name: monitor?.name ?? '',
        url: monitor?.url ?? 'https://',
        host: monitor?.host ?? '',
        port: monitor?.port ? String(monitor.port) : '',
        site_id: monitor?.site_id ?? siteId ?? '',
        expected_status: monitor?.expected_status ?? '2xx,3xx',
        keyword: monitor?.keyword ?? '',
        keyword_absent: monitor?.keyword_absent ?? false,
        interval_seconds: String(monitor?.interval_seconds ?? 60),
        timeout_seconds: String(monitor?.timeout_seconds ?? 10),
        max_redirects: String(monitor?.max_redirects ?? limits.max_redirects),
        slow_ms: monitor?.slow_ms ? String(monitor.slow_ms) : '',
    });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const set = <K extends keyof typeof form>(key: K, value: (typeof form)[K]) => setForm((current) => ({ ...current, [key]: value }));
    const creating = monitor === null;
    const http = form.type === 'http';

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        if (!isValidExpectedStatus(form.expected_status)) {
            setErrors({ expected_status: 'Use status codes (200) or classes (2xx), separated by commas.' });

            return;
        }

        const settings = {
            name: form.name,
            interval_seconds: Number(form.interval_seconds),
            timeout_seconds: Number(form.timeout_seconds),
            slow_ms: form.slow_ms === '' ? null : Number(form.slow_ms),
            ...(http
                ? {
                      expected_status: form.expected_status,
                      keyword: form.keyword || null,
                      keyword_absent: form.keyword_absent,
                      max_redirects: Number(form.max_redirects),
                  }
                : {}),
        };
        const body = creating
            ? {
                  ...settings,
                  type: form.type,
                  site_id: form.site_id || null,
                  ...(http ? { url: form.url } : { host: form.host, port: form.port === '' ? null : Number(form.port) }),
              }
            : settings;

        setSaving(true);
        try {
            const response = await requestJson<{ data: UptimeMonitor }>(
                creating ? '/uptime/monitors' : `/uptime/monitors/${monitor.id}`,
                creating ? 'POST' : 'PUT',
                body,
            );
            onSaved(response.data);
        } catch (error) {
            setErrors(error instanceof HttpError ? { form: error.message, ...error.errors } : { form: 'Could not save the monitor' });
        } finally {
            setSaving(false);
        }
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && onClose()}
            title={creating ? 'Add monitor' : `Edit ${monitor.name}`}
            description={monitor?.source === 'auto' ? `Checks ${monitor.target} as long as the domain belongs to the site.` : undefined}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" form="uptime-monitor-form" loading={saving}>
                        {creating ? 'Add monitor' : 'Save'}
                    </Button>
                </>
            }
        >
            <form id="uptime-monitor-form" onSubmit={submit} className="grid gap-4">
                {creating && (
                    <Field label="Check">
                        <Select<MonitorType>
                            value={form.type}
                            onValueChange={(value) => set('type', value)}
                            options={[
                                { value: 'http', label: 'HTTP(S) request', description: 'Status code and optional keyword' },
                                { value: 'tcp', label: 'TCP port', description: 'The port accepts connections' },
                                { value: 'tls', label: 'TLS handshake', description: 'Handshake and certificate chain' },
                            ]}
                        />
                    </Field>
                )}
                <Field label="Name" error={errors.name} hint="Shown in alerts; status pages use their own display names.">
                    <Input value={form.name} onChange={(e) => set('name', e.target.value)} required maxLength={120} />
                </Field>
                {creating && http && (
                    <Field label="URL" error={errors.url} hint="Public addresses only: private and internal networks are refused.">
                        <Input value={form.url} onChange={(e) => set('url', e.target.value)} required mono />
                    </Field>
                )}
                {creating && !http && (
                    <div className="grid grid-cols-[1fr_8rem] gap-3">
                        <Field label="Host" error={errors.host}>
                            <Input value={form.host} onChange={(e) => set('host', e.target.value)} required mono />
                        </Field>
                        <Field label="Port" error={errors.port}>
                            <Input
                                value={form.port}
                                onChange={(e) => set('port', e.target.value)}
                                inputMode="numeric"
                                placeholder={form.type === 'tls' ? '443' : ''}
                                required={form.type === 'tcp'}
                            />
                        </Field>
                    </div>
                )}
                {creating && sites.length > 0 && (
                    <Field label="Site" hint="Optional: the monitor shows on the site's Uptime tab." error={errors.site_id}>
                        <Select
                            value={form.site_id || 'none'}
                            onValueChange={(value) => set('site_id', value === 'none' ? '' : value)}
                            options={[{ value: 'none', label: 'No site' }, ...sites.map((site) => ({ value: site.id, label: site.name }))]}
                        />
                    </Field>
                )}
                {http && (
                    <>
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Expected status" error={errors.expected_status} hint="e.g. 2xx,3xx or 200">
                                <Input value={form.expected_status} onChange={(e) => set('expected_status', e.target.value)} mono />
                            </Field>
                            <Field label="Follow redirects" error={errors.max_redirects} hint={`0 to ${limits.max_redirects}`}>
                                <Input
                                    type="number"
                                    min={0}
                                    max={limits.max_redirects}
                                    value={form.max_redirects}
                                    onChange={(e) => set('max_redirects', e.target.value)}
                                />
                            </Field>
                        </div>
                        <Field label="Keyword" error={errors.keyword} hint="Optional, case-insensitive, searched in the first 64 KB of the body.">
                            <Input value={form.keyword} onChange={(e) => set('keyword', e.target.value)} maxLength={200} />
                        </Field>
                        {form.keyword !== '' && (
                            <Field label="Fail when the keyword is present" inline>
                                <Switch checked={form.keyword_absent} onCheckedChange={(checked) => set('keyword_absent', checked)} />
                            </Field>
                        )}
                    </>
                )}
                <div className="grid grid-cols-3 gap-3">
                    <Field label="Interval (s)" error={errors.interval_seconds}>
                        <Input
                            type="number"
                            min={limits.min_interval}
                            max={limits.max_interval}
                            value={form.interval_seconds}
                            onChange={(e) => set('interval_seconds', e.target.value)}
                        />
                    </Field>
                    <Field label="Timeout (s)" error={errors.timeout_seconds}>
                        <Input
                            type="number"
                            min={1}
                            max={limits.max_timeout}
                            value={form.timeout_seconds}
                            onChange={(e) => set('timeout_seconds', e.target.value)}
                        />
                    </Field>
                    <Field label="Slow above (ms)" error={errors.slow_ms}>
                        <Input
                            type="number"
                            min={100}
                            max={60000}
                            placeholder="3000"
                            value={form.slow_ms}
                            onChange={(e) => set('slow_ms', e.target.value)}
                        />
                    </Field>
                </div>
                {errors.form && !Object.keys(errors).some((key) => key !== 'form') && <p className="text-danger text-sm">{errors.form}</p>}
            </form>
        </Dialog>
    );
}
