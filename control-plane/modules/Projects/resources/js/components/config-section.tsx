import { Button, Callout, Field, Input, RelativeTime, Section, Select, StatusBadge, Switch, Tag } from '@/components/falak';
import { HttpError, requestJson } from '@/lib/http';
import { FileCode2 } from 'lucide-react';
import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { RunApproval, type ConfigApproval } from './config-run-approval';
import { UnmanagedResources } from './unmanaged-resources';

/** Config as code (Config module, `GET /projects/{p}/settings/config`). */
interface ConfigSource {
    id: string;
    connection_id: string;
    repository: string;
    path: string;
    enabled: boolean;
    experimental: boolean;
    separate_approver: boolean;
    allowed_credentials: string[];
}

interface ConfigIssue {
    code: string;
    pointer: string | null;
    line: number | null;
    column: number | null;
    message: string;
}

interface ConfigChange {
    op: 'create' | 'update' | 'delete' | 'detach' | 'noop';
    path: string;
    before: unknown;
    after: unknown;
    risky: boolean;
    reason: string | null;
    /** What applying it does besides the change, e.g. a database restart. */
    note?: string | null;
}

interface ConfigRun {
    id: string;
    environment: string;
    commit: string | null;
    branch: string | null;
    trigger: string;
    status: string;
    plan: { changes: ConfigChange[]; summary: Record<string, number>; risky: boolean; deploy: string[] } | null;
    errors: ConfigIssue[];
    warnings: ConfigIssue[];
    error: string | null;
    deployments: { unit: string; deployment_id: string | null; status: string | null; error: string | null }[];
    created_at: string;
    approval: ConfigApproval | null;
    cancel_reason: string | null;
}

interface ConfigData {
    source: ConfigSource | null;
    connections: { id: string; name: string; provider: string }[];
    runs: ConfigRun[];
    can: { apply: boolean; approve: boolean };
}

const RUN_TONES: Record<string, 'success' | 'danger' | 'warning' | 'info' | 'faint'> = {
    applied: 'success',
    failed: 'danger',
    refused: 'warning',
    awaiting_approval: 'warning',
    applying: 'info',
    planning: 'info',
    cancelled: 'faint',
};

function show(value: unknown): string {
    if (value === null || value === undefined) return '';
    return typeof value === 'string' ? value : JSON.stringify(value);
}

function RunDetails({ run, onChange }: { run: ConfigRun; onChange: () => void }) {
    const changes = (run.plan?.changes ?? []).filter((c) => c.op !== 'noop');

    return (
        <div className="grid gap-2 pt-2">
            {run.approval && <RunApproval runId={run.id} status={run.status} approval={run.approval} onChange={onChange} />}
            {run.cancel_reason && <p className="text-fg-muted text-sm">Cancelled: {run.cancel_reason.replace('_', ' ')}.</p>}
            {run.error && <p className="text-danger text-sm">{run.error}</p>}
            {run.errors.length > 0 && (
                <ul className="grid gap-1 font-mono text-xs">
                    {run.errors.map((issue, i) => (
                        <li key={i} className="text-danger">
                            {issue.line !== null ? `${issue.line}:${issue.column ?? 1} ` : ''}
                            {issue.code}: {issue.pointer ? `${issue.pointer}: ` : ''}
                            {issue.message}
                        </li>
                    ))}
                </ul>
            )}
            {changes.length > 0 && (
                <table className="w-full text-left text-xs">
                    <thead className="text-fg-muted">
                        <tr>
                            <th className="py-1 pr-2 font-medium">Change</th>
                            <th className="py-1 pr-2 font-medium">Path</th>
                            <th className="py-1 pr-2 font-medium">Before</th>
                            <th className="py-1 font-medium">After</th>
                        </tr>
                    </thead>
                    <tbody className="font-mono">
                        {changes.map((change, i) => (
                            <tr key={i} className="border-border border-t align-top">
                                <td className="py-1 pr-2">
                                    {change.op}
                                    {change.risky && (
                                        <Tag tone="warning" className="ml-1" title={change.reason ?? undefined}>
                                            risky
                                        </Tag>
                                    )}
                                    {change.note && (
                                        <Tag tone="info" className="ml-1" title={change.note}>
                                            note
                                        </Tag>
                                    )}
                                </td>
                                <td className="py-1 pr-2 break-all">{change.path}</td>
                                <td className="text-fg-muted py-1 pr-2 break-all">{show(change.before)}</td>
                                <td className="py-1 break-all">{show(change.after)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
            {run.plan && changes.length === 0 && <p className="text-fg-muted text-sm">No changes: the environment already matches the file.</p>}
            {run.deployments.length > 0 && (
                <p className="text-fg-muted text-sm">
                    Deployed: {run.deployments.map((d) => `${d.unit} (${d.status ?? 'pending'}${d.error ? `: ${d.error}` : ''})`).join(', ')}
                </p>
            )}
            {run.warnings.length > 0 && (
                <ul className="text-warning grid gap-1 font-mono text-xs">
                    {run.warnings.map((issue, i) => (
                        <li key={i}>
                            {issue.code}: {issue.pointer ? `${issue.pointer}: ` : ''}
                            {issue.message}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/**
 * The project's falak.yaml: which repository and file it follows, whether it is on, and the runs (each push to an
 * environment's branch plans and applies the file there).
 */
export function ConfigSection({ projectId }: { projectId: string }) {
    const [data, setData] = useState<ConfigData | null>(null);
    const [form, setForm] = useState({
        connection_id: '',
        repository: '',
        path: 'falak.yaml',
        enabled: true,
        separate_approver: false,
        credentials: '',
    });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [open, setOpen] = useState<string | null>(null);

    const load = useCallback(async () => {
        const response = await requestJson<{ data: ConfigData }>(`/projects/${projectId}/settings/config`);
        setData(response.data);
        const source = response.data.source;
        setForm({
            connection_id: source?.connection_id ?? response.data.connections[0]?.id ?? '',
            repository: source?.repository ?? '',
            path: source?.path ?? 'falak.yaml',
            enabled: source?.enabled ?? true,
            separate_approver: source?.separate_approver ?? false,
            credentials: (source?.allowed_credentials ?? []).join(', '),
        });
    }, [projectId]);

    useEffect(() => {
        void load().catch(() => setData(null));
    }, [load]);

    const save = async (event: FormEvent) => {
        event.preventDefault();
        setSaving(true);
        setErrors({});
        try {
            const { credentials, ...rest } = form;
            await requestJson(`/projects/${projectId}/settings/config`, 'PUT', {
                ...rest,
                allowed_credentials: credentials
                    .split(',')
                    .map((name) => name.trim())
                    .filter((name) => name !== ''),
            });
            await load();
        } catch (error) {
            setErrors(error instanceof HttpError ? error.errors : { repository: 'Saving failed.' });
        } finally {
            setSaving(false);
        }
    };

    const canApply = data?.can.apply ?? false;

    return (
        <Section
            id="config"
            title="Config as code"
            description="Describe the project's services in a falak.yaml at the root of its repository: a push to an environment's branch plans and applies it there, and the fields it sets become read-only here."
        >
            {data === null ? (
                <p className="text-fg-muted text-sm">Loading…</p>
            ) : (
                <div className="grid gap-4">
                    <Callout tone="info" title="Experimental">
                        Risky changes (a production deploy strategy or servers, removing a production domain, anything that touches data) wait for an
                        approval before they are applied. Disabling the source keeps every resource and makes every field editable again.
                    </Callout>
                    <form className="grid gap-3 sm:grid-cols-2" onSubmit={save} data-testid="config-source-form">
                        <Field label="Connection" error={errors.connection_id}>
                            <Select
                                value={form.connection_id || undefined}
                                onValueChange={(value) => setForm({ ...form, connection_id: value })}
                                options={data.connections.map((c) => ({ value: c.id, label: `${c.name} (${c.provider})` }))}
                                placeholder="Choose a connection"
                                disabled={!canApply}
                            />
                        </Field>
                        <Field label="Repository" error={errors.repository} hint="owner/name">
                            <Input
                                value={form.repository}
                                onChange={(event) => setForm({ ...form, repository: event.target.value })}
                                placeholder="acme/shop"
                                disabled={!canApply}
                                mono
                            />
                        </Field>
                        <Field label="File" error={errors.path} hint="falak.yaml, falak.yml or falak.json">
                            <Input value={form.path} onChange={(event) => setForm({ ...form, path: event.target.value })} disabled={!canApply} mono />
                        </Field>
                        <Field
                            label="Approver must differ from the requester"
                            error={errors.separate_approver}
                            hint="Risky runs wait for an approval (config.approve). Admins only."
                            inline
                        >
                            <Switch
                                checked={form.separate_approver}
                                onCheckedChange={(separate_approver) => setForm({ ...form, separate_approver })}
                                disabled={!canApply || !data.can.approve}
                            />
                        </Field>
                        <Field label="Follow the file" error={errors.enabled} inline>
                            <Switch checked={form.enabled} onCheckedChange={(enabled) => setForm({ ...form, enabled })} disabled={!canApply} />
                        </Field>
                        <Field
                            label="Certificates, DNS credentials and backup storage the file may use"
                            error={errors.allowed_credentials}
                            hint="Names, comma-separated, besides those the project's domains and backups already use. Admins only."
                            className="sm:col-span-2"
                        >
                            <Input
                                value={form.credentials}
                                onChange={(event) => setForm({ ...form, credentials: event.target.value })}
                                disabled={!canApply || !data.can.approve}
                                mono
                            />
                        </Field>
                        {canApply && (
                            <div className="sm:col-span-2">
                                <Button type="submit" variant="primary" loading={saving} icon={<FileCode2 />}>
                                    {data.source ? 'Save' : 'Connect falak.yaml'}
                                </Button>
                            </div>
                        )}
                    </form>

                    <div className="grid gap-2">
                        <h3 className="text-fg text-sm font-medium">Runs</h3>
                        {data.runs.length === 0 ? (
                            <p className="text-fg-muted text-sm">No runs yet. Push to a branch an environment of the file deploys.</p>
                        ) : (
                            <ul className="divide-border border-border divide-y rounded-md border" data-testid="config-runs">
                                {data.runs.map((run) => (
                                    <li key={run.id} className="px-3 py-2">
                                        <button
                                            type="button"
                                            className="flex w-full flex-wrap items-center gap-2 text-left text-sm"
                                            onClick={() => setOpen(open === run.id ? null : run.id)}
                                            aria-expanded={open === run.id}
                                        >
                                            <StatusBadge status={run.status} tone={RUN_TONES[run.status]} />
                                            <span className="text-fg font-medium">{run.environment || 'unknown environment'}</span>
                                            <Tag mono>{run.commit ? run.commit.slice(0, 7) : 'local file'}</Tag>
                                            <span className="text-fg-muted">{run.trigger}</span>
                                            {run.plan && (
                                                <span className="text-fg-muted">
                                                    {run.plan.summary.create ?? 0} create · {run.plan.summary.update ?? 0} update ·{' '}
                                                    {(run.plan.summary.delete ?? 0) + (run.plan.summary.detach ?? 0)} remove
                                                </span>
                                            )}
                                            <span className="text-fg-faint ml-auto">
                                                <RelativeTime value={run.created_at} />
                                            </span>
                                        </button>
                                        {open === run.id && <RunDetails run={run} onChange={() => void load()} />}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    <UnmanagedResources projectId={projectId} canDismiss={canApply} />
                </div>
            )}
        </Section>
    );
}
