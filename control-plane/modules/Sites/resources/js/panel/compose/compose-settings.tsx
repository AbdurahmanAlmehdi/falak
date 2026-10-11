import { DomainPicker, type DomainChoice } from '@/components/domain-picker';
import {
    Button,
    Callout,
    Field,
    IconButton,
    Input,
    ManagedBadge,
    RelativeTime,
    Section,
    Segmented,
    Select,
    SkeletonRows,
    Tag,
    managedCovers,
    pointer,
    toast,
} from '@/components/falak';
import { useJson } from '@/hooks/use-json';
import { HttpError, errorMessage, requestJson } from '@/lib/http';
import { type ComposeServiceChoice, type ServiceTabProps } from '@/lib/registry';
import { Link } from '@inertiajs/react';
import { ExternalLink, GitBranch, History, Plus, RotateCcw, ShieldAlert, ShieldCheck, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { deployNow, lineDiff } from '../api';
import { composeUrl, type ComposeSettingsData, type ComposeSummary } from './api';
import { useInspection, useSiteCandidates } from './project-api';
import { AdjustmentsList, InspectionProblems, ProjectFiles, ServicesTable, VariablesList } from './project-parts';
import { DiffView, YamlEditor } from './yaml-editor';

interface PublicDraft {
    service: string;
    port: string;
    health_check_path: string;
    /** Saved domains load as custom (a generated name shows as generated); no domain = the test domain. */
    domain: DomainChoice;
}

function choiceOf(domain: string | null, testDomain: string | null): DomainChoice {
    if (domain) return { type: 'custom', name: domain };

    return testDomain ? { type: 'test' } : { type: 'custom', name: '' };
}

/** Comparable form for dirty tracking. */
function choiceKey(choice: DomainChoice): string {
    return choice.type === 'custom' ? (choice.name ?? '').trim().toLowerCase() : choice.type === 'test' ? '' : '<generated>';
}

/** PUT body: a custom name, null for the test domain, or {type: generated}. */
function choiceBody(choice: DomainChoice): string | null | { type: 'generated' } {
    if (choice.type === 'generated') return { type: 'generated' };

    return choice.type === 'custom' ? (choice.name ?? '').trim() || null : null;
}

type Source = 'repo' | 'inline';

/**
 * Settings → Compose (docs/COMPOSE_TEMPLATES.md §1.6): source (repository path or an inline, versioned compose file
 * with highlighting, live validation, diff and history), public services (service picker + port + domain) and the
 * policy status.
 */
export function ComposeSettings({ ctx }: ServiceTabProps) {
    const url = composeUrl(ctx.service.ref_id);
    const { data, error, reload } = useJson<ComposeSettingsData>(url);
    const [source, setSource] = useState<Source>('inline');
    const [file, setFile] = useState('');
    const [content, setContent] = useState('');
    const [publicServices, setPublicServices] = useState<PublicDraft[]>([]);
    const [summary, setSummary] = useState<ComposeSummary | null>(null);
    const [validating, setValidating] = useState(false);
    const [reviewing, setReviewing] = useState(false);
    const [history, setHistory] = useState<{ version: number; content: string } | null>(null);
    const [saving, setSaving] = useState<'save' | 'deploy' | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    // Repository sources (docs/plans/COMPOSE_APPS.md): files, profiles, per-service decisions, kept bind folders.
    const [files, setFiles] = useState<string[]>([]);
    const [profiles, setProfiles] = useState<string[]>([]);
    const [decisions, setDecisions] = useState<Record<string, ComposeServiceChoice>>({});
    const [keepBinds, setKeepBinds] = useState<string[]>([]);

    const reset = (value: ComposeSettingsData) => {
        setSource(value.source);
        setFile(value.file ?? '');
        setFiles(value.files ?? []);
        setProfiles(value.profiles ?? []);
        setDecisions(Object.fromEntries(Object.entries(value.services ?? {}).map(([service, d]) => [service, { mode: d.mode }])));
        setKeepBinds(value.adjustments?.keep_binds ?? []);
        setContent(value.content ?? '');
        setSummary(value.summary);
        setPublicServices(
            value.public_services.map((item) => ({
                service: item.service,
                port: String(item.port),
                domain: choiceOf(item.domain, item.test_domain),
                health_check_path: item.health_check_path ?? '',
            })),
        );
        setReviewing(false);
        setErrors({});
    };

    useEffect(() => {
        if (data) reset(data);
    }, [data]);

    // Live validation of the inline file (debounced).
    useEffect(() => {
        if (source !== 'inline' || !data || content === (data.content ?? '')) {
            if (data && content === (data.content ?? '')) setSummary(data.summary);
            setValidating(false);

            return;
        }
        setValidating(true);
        const timer = window.setTimeout(() => {
            requestJson<{ data: ComposeSummary }>(`${url}/validate`, 'POST', { content })
                .then((body) => setSummary(body.data))
                .catch(() => undefined)
                .finally(() => setValidating(false));
        }, 400);

        return () => {
            window.clearTimeout(timer);
            setValidating(false);
        };
    }, [content, source, data, url]);

    // Decisions sent on save: extracted services, plus "keep" for those brought back into the stack.
    const decisionsBody = useMemo(
        () =>
            Object.fromEntries(
                Object.entries(decisions).filter(([service, d]) => d.mode !== 'keep' || (data?.services?.[service]?.mode ?? 'keep') !== 'keep'),
            ),
        [decisions, data],
    );
    const inspectBody = useMemo(
        () =>
            data && source === 'repo' && data.repository
                ? {
                      compose_files: files.filter(Boolean),
                      compose_profiles: profiles,
                      compose_services: decisionsBody,
                      compose_adjustments: { keep_binds: keepBinds },
                      public_services: publicServices.filter((p) => p.service).map((p) => ({ service: p.service, port: Number(p.port) || 1 })),
                  }
                : null,
        [data, source, files, profiles, decisionsBody, keepBinds, publicServices],
    );
    const { inspection, loading: inspecting } = useInspection(`${url}/inspect`, inspectBody);
    const candidates = useSiteCandidates(`${url}/candidates`, !!data?.can.update && source === 'repo' && !!data?.repository);

    const services = useMemo(
        () =>
            source === 'repo' && inspection && !inspection.no_api
                ? (inspection.services as unknown as ComposeSummary['services'])
                : (summary?.services ?? []),
        [source, inspection, summary],
    );

    if (!data) return error ? <Callout tone="danger">{error}</Callout> : <SkeletonRows rows={8} />;

    const canUpdate = data.can.update;
    // Fields falak.yaml manages are read-only (the server refuses changing them).
    const filesManaged = managedCovers(data.managed, '/files') || managedCovers(data.managed, '/profiles');
    const servicesManaged = (data.managed?.paths ?? []).some((path) => /^\/services\/[^/]+\/(port|health_path|replace_with)$/.test(path));
    const managedField = (service: string, field: string) => service !== '' && managedCovers(data.managed, pointer('services', service, field));
    const original = JSON.stringify({
        source: data.source,
        files: (data.files ?? []).join('\n'),
        profiles: (data.profiles ?? []).join(','),
        decisions: Object.entries(data.services ?? {}).map(([s, d]) => `${s}:${d.mode}`),
        keepBinds: data.adjustments?.keep_binds ?? [],
        content: data.content ?? '',
        public: data.public_services.map((item) => [item.service, String(item.port), item.domain ?? '', item.health_check_path ?? '']),
    });
    const current = JSON.stringify({
        source,
        files: files.filter(Boolean).join('\n'),
        profiles: profiles.join(','),
        decisions: Object.entries(decisions)
            .filter(([, d]) => d.mode !== 'keep')
            .map(([s, d]) => `${s}:${d.mode}`),
        keepBinds,
        content: source === 'inline' ? content : (data.content ?? ''),
        public: publicServices.map((item) => [item.service, item.port, choiceKey(item.domain), item.health_check_path.trim()]),
    });
    const dirty = original !== current;
    const contentChanged = source === 'inline' && content !== (data.content ?? '');
    const blocking =
        source === 'inline'
            ? [...(summary?.errors ?? []), ...(data.policy.allow_privileged ? [] : (summary?.violations ?? []))]
            : [...(inspection?.errors ?? []), ...(inspection && !data.policy.allow_privileged ? inspection.violations : [])];

    const save = async (then: 'save' | 'deploy') => {
        setSaving(then);
        setErrors({});
        try {
            const body = await requestJson<{ data: { version: number | null; warnings?: string[] } }>(url, 'PUT', {
                compose_source: source,
                ...(source === 'repo'
                    ? {
                          compose_files: files.filter(Boolean),
                          compose_profiles: profiles,
                          compose_services: decisionsBody,
                          compose_adjustments: { keep_binds: keepBinds },
                      }
                    : {}),
                compose_content: source === 'inline' ? content : null,
                public_services: publicServices
                    .filter((item) => item.service !== '')
                    .map((item) => ({
                        service: item.service,
                        port: Number(item.port),
                        domain: choiceBody(item.domain),
                        ...(item.health_check_path.trim() ? { health_check_path: item.health_check_path.trim() } : {}),
                    })),
                base_version: data.version,
            });
            toast.success(
                body.data.version && contentChanged ? `Saved as version ${body.data.version}` : 'Compose settings saved',
                then === 'deploy' ? 'Deploying…' : 'Changes apply on the next deploy.',
            );
            for (const warning of body.data.warnings ?? []) toast.error('Not applied', warning);
            await reload();
            ctx.refresh();
            if (then === 'deploy') await deployNow(ctx);
        } catch (e) {
            if (e instanceof HttpError && Object.keys(e.errors).length > 0) {
                setErrors(e.errors);
                setReviewing(false);
            } else toast.error('Could not save', errorMessage(e));
        } finally {
            setSaving(null);
        }
    };

    const showVersion = async (version: number) => {
        try {
            const body = await requestJson<{ data: { version: number; content: string } }>(`${url}/versions/${version}`);
            setHistory(body.data);
        } catch (e) {
            toast.error('Could not load the version', errorMessage(e));
        }
    };

    const restore = async (version: number) => {
        try {
            const body = await requestJson<{ data: { version: number } }>(`${url}/versions/${version}/restore`, 'POST', {});
            toast.success(`Version ${version} restored as version ${body.data.version}`, 'Changes apply on the next deploy.');
            setHistory(null);
            await reload();
            ctx.refresh();
        } catch (e) {
            toast.error('Could not restore', errorMessage(e));
        }
    };

    const updatePublic = (index: number, patch: Partial<PublicDraft>) =>
        setPublicServices((items) => items.map((item, position) => (position === index ? { ...item, ...patch } : item)));

    return (
        <div className="grid gap-6" data-testid="compose-settings">
            <Section
                title="Compose file"
                description={
                    source === 'repo'
                        ? 'Read from the repository on every deploy; services with build: are built by falak-builder and pinned by digest.'
                        : 'Stored in Falak and versioned. Interpolation stays Compose-native: ${VAR} reads the site variables.'
                }
                aside={
                    <>
                        {filesManaged && <ManagedBadge config={data.managed} />}
                        {data.template && (
                            <Tag tone="accent" mono>
                                template {data.template.slug}@{data.template.version}
                            </Tag>
                        )}
                        <Segmented<Source>
                            label="Compose source"
                            value={source}
                            onValueChange={(value) => canUpdate && !filesManaged && setSource(value)}
                            options={[
                                { value: 'repo', label: 'Repository' },
                                { value: 'inline', label: 'Inline' },
                            ]}
                        />
                    </>
                }
                footer={
                    canUpdate && (
                        <>
                            <span className="text-fg-faint mr-auto text-xs">
                                {source === 'inline' ? '⌘S to review · ' : ''}applies on the next deploy
                            </span>
                            <Button variant="ghost" disabled={!dirty || saving !== null} onClick={() => reset(data)}>
                                Reset
                            </Button>
                            {reviewing ? (
                                <>
                                    <Button variant="ghost" onClick={() => setReviewing(false)}>
                                        Back to editor
                                    </Button>
                                    <Button loading={saving === 'save'} disabled={saving !== null} onClick={() => void save('save')}>
                                        Save
                                    </Button>
                                    <Button
                                        variant="primary"
                                        loading={saving === 'deploy'}
                                        disabled={saving !== null}
                                        onClick={() => void save('deploy')}
                                    >
                                        Save & deploy
                                    </Button>
                                </>
                            ) : (
                                <Button
                                    variant="primary"
                                    disabled={!dirty || blocking.length > 0 || validating}
                                    onClick={() => (contentChanged ? setReviewing(true) : void save('save'))}
                                >
                                    {contentChanged ? 'Review changes' : 'Save'}
                                </Button>
                            )}
                        </>
                    )
                }
            >
                {source === 'repo' ? (
                    <div className="grid gap-3">
                        <ProjectFiles
                            files={files.length > 0 ? files : file ? [file] : []}
                            profiles={profiles}
                            candidates={candidates}
                            disabled={!canUpdate || filesManaged}
                            errors={{ ...errors, compose_files: errors.compose_files ?? errors.compose_file }}
                            onChange={(nextFiles, nextProfiles) => {
                                setFiles(nextFiles);
                                setProfiles(nextProfiles);
                            }}
                        />
                        {inspection && !inspection.no_api && (
                            <InspectionProblems inspection={inspection} allowPrivileged={data.policy.allow_privileged} />
                        )}
                        {inspection?.no_api && (
                            <p className="text-fg-muted text-xs">
                                Falak can’t read files from this git server; the builder reads them at deploy time.
                            </p>
                        )}
                        {data.repository ? (
                            <p className="text-fg-muted flex items-center gap-1.5 text-xs">
                                <GitBranch className="size-3.5" aria-hidden /> <span className="font-mono">{data.repository}</span>
                                {data.branch && <span className="font-mono">@ {data.branch}</span>}
                                {inspecting && <span className="text-fg-faint">· reading…</span>}
                            </p>
                        ) : (
                            <Callout tone="warning">Connect a repository in the Source section, or switch to an inline compose file.</Callout>
                        )}
                    </div>
                ) : reviewing ? (
                    <DiffView diff={lineDiff(data.content ?? '', content)} header={`v${data.version ?? 0} → v${(data.version ?? 0) + 1}`} />
                ) : (
                    <YamlEditor
                        value={content}
                        onChange={setContent}
                        readOnly={!canUpdate}
                        onSave={() => dirty && blocking.length === 0 && setReviewing(true)}
                    />
                )}
                {errors.compose_content && <p className="text-danger text-xs whitespace-pre-line">{errors.compose_content}</p>}
                {source === 'inline' && summary && (
                    <Validation summary={summary} validating={validating} allowPrivileged={data.policy.allow_privileged} />
                )}
            </Section>

            {source === 'repo' && inspection && !inspection.no_api && inspection.services.length > 0 && (
                <>
                    <Section title="Services" description="Where each service runs. Services moved out of the stack are no longer started with it.">
                        <ServicesTable
                            services={inspection.services}
                            withPublic={false}
                            disabled={!canUpdate}
                            errors={errors}
                            keepBinds={keepBinds}
                            onKeepBinds={setKeepBinds}
                            choiceOf={(service) => {
                                const choice = decisions[service] ?? { mode: 'keep' as const };

                                return { row: choice.mode, choice };
                            }}
                            onChoice={(service, _row, choice) => setDecisions((current) => ({ ...current, [service]: choice }))}
                        />
                    </Section>
                    <Section title="Variables" description="What the stack reads; set the values in the Variables tab.">
                        <VariablesList variables={inspection.variables} />
                    </Section>
                    <Section title="Falak adjustments" description="Applied when the stack runs; the repository is never changed.">
                        <AdjustmentsList inspection={inspection} />
                    </Section>
                </>
            )}

            <Section
                title="Public services"
                description="Falak publishes each on 127.0.0.1 and routes it through the edge. The first one gets the site's domains; other host ports are not published."
                aside={
                    <>
                        {servicesManaged && <ManagedBadge config={data.managed} />}
                        {canUpdate && (
                            <Button
                                size="sm"
                                variant="ghost"
                                icon={<Plus />}
                                onClick={() =>
                                    setPublicServices((items) => [
                                        ...items,
                                        { service: '', port: '', domain: { type: 'generated' }, health_check_path: '' },
                                    ])
                                }
                            >
                                Add
                            </Button>
                        )}
                    </>
                }
            >
                {publicServices.length === 0 ? (
                    <p className="text-fg-muted text-sm">
                        No public service — the stack runs without an HTTP entry point (health checks rely on container healthchecks).
                    </p>
                ) : (
                    <ul className="grid gap-3" aria-label="Public services">
                        {publicServices.map((item, index) => {
                            const known = data.public_services.find((saved) => saved.service === item.service);
                            const exposed = services.find((service) => service.name === item.service)?.ports ?? [];

                            return (
                                <li
                                    key={index}
                                    className="border-border grid items-start gap-2 border-b pb-3 last:border-b-0 last:pb-0 sm:grid-cols-[minmax(0,1fr)_7rem_4.5rem]"
                                >
                                    <Field
                                        label={index === 0 ? 'Service' : <span className="sr-only">Service</span>}
                                        error={errors[`public_services.${index}.service`]}
                                    >
                                        {services.length > 0 ? (
                                            <Select
                                                aria-label="Service"
                                                value={item.service || undefined}
                                                placeholder="Pick a service"
                                                disabled={!canUpdate}
                                                onValueChange={(service) => {
                                                    const ports = services.find((candidate) => candidate.name === service)?.ports ?? [];
                                                    updatePublic(index, { service, port: item.port || (ports[0] ? String(ports[0]) : '') });
                                                }}
                                                options={services.map((service) => ({ value: service.name, label: service.name }))}
                                            />
                                        ) : (
                                            <Input
                                                value={item.service}
                                                placeholder="web"
                                                disabled={!canUpdate}
                                                onChange={(event) => updatePublic(index, { service: event.target.value })}
                                            />
                                        )}
                                    </Field>
                                    <Field
                                        label={index === 0 ? 'Port' : <span className="sr-only">Port</span>}
                                        error={errors[`public_services.${index}.port`]}
                                    >
                                        <Input
                                            value={item.port}
                                            inputMode="numeric"
                                            placeholder={exposed[0] ? String(exposed[0]) : '8080'}
                                            disabled={!canUpdate || managedField(item.service, 'port')}
                                            onChange={(event) => updatePublic(index, { port: event.target.value.replace(/\D/g, '') })}
                                            className="font-mono"
                                        />
                                    </Field>
                                    <div className={index === 0 ? 'flex items-center gap-1 pt-6' : 'flex items-center gap-1'}>
                                        {known?.url && (
                                            <IconButton
                                                size="sm"
                                                variant="ghost"
                                                label={`Open ${item.service}`}
                                                icon={<ExternalLink />}
                                                onClick={() => window.open(known.url ?? '', '_blank', 'noopener')}
                                            />
                                        )}
                                        {canUpdate && !managedField(item.service, 'port') && (
                                            <IconButton
                                                size="sm"
                                                variant="ghost"
                                                label="Remove"
                                                icon={<Trash2 />}
                                                onClick={() => setPublicServices((items) => items.filter((_, position) => position !== index))}
                                            />
                                        )}
                                    </div>
                                    <div className="sm:col-span-3 sm:col-start-1 sm:row-start-2">
                                        <Field
                                            label={<span className="text-fg-muted">Domain</span>}
                                            hint={index === 0 ? 'The site’s own domains (Networking) route here too.' : undefined}
                                            error={errors[`public_services.${index}.domain`]}
                                        >
                                            {known?.domain ? (
                                                // Saved domains are edge domains: added and removed per service in Settings → Networking.
                                                <span className="text-fg-muted flex flex-wrap items-center gap-1.5 text-xs">
                                                    <span className="text-fg font-mono">{known.domain}</span>
                                                    <Button size="sm" variant="ghost" onClick={() => ctx.open('settings', 'networking')}>
                                                        Manage domains in Networking
                                                    </Button>
                                                </span>
                                            ) : canUpdate ? (
                                                <DomainPicker
                                                    label={!item.service || item.service === data.slug ? data.slug : `${item.service}-${data.slug}`}
                                                    serverIds={[]}
                                                    siteId={ctx.service.ref_id}
                                                    testDomain={known?.test_domain ?? null}
                                                    value={item.domain}
                                                    onChange={(domain) => updatePublic(index, { domain })}
                                                    ariaLabel={`${item.service || 'service'} domain`}
                                                />
                                            ) : (
                                                <span className="text-fg font-mono text-xs">{known?.url?.replace(/^https:\/\//, '') ?? '—'}</span>
                                            )}
                                        </Field>
                                    </div>
                                    <div className="sm:col-span-3 sm:col-start-1">
                                        <Field
                                            label={<span className="text-fg-muted">Health check path</span>}
                                            hint="Checked through the edge after each deploy; empty = the container healthcheck only."
                                            error={errors[`public_services.${index}.health_check_path`]}
                                        >
                                            <Input
                                                value={item.health_check_path}
                                                placeholder="/"
                                                disabled={!canUpdate || managedField(item.service, 'health_path')}
                                                aria-label={`${item.service || 'service'} health check path`}
                                                onChange={(event) => updatePublic(index, { health_check_path: event.target.value })}
                                                className="font-mono"
                                            />
                                        </Field>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </Section>

            {data.versions.length > 0 && (
                <Section
                    title="History"
                    description="Every saved inline compose file. Restoring creates a new version."
                    aside={<History className="text-fg-faint size-4" aria-hidden />}
                >
                    {history ? (
                        <div className="grid gap-3">
                            <DiffView
                                diff={lineDiff(history.content, data.content ?? '')}
                                header={`v${history.version} → current (v${data.version})`}
                            />
                            <div className="flex justify-end gap-2">
                                <Button variant="ghost" size="sm" onClick={() => setHistory(null)}>
                                    Close
                                </Button>
                                {canUpdate && history.version !== data.version && (
                                    <Button size="sm" icon={<RotateCcw />} onClick={() => void restore(history.version)}>
                                        Restore v{history.version}
                                    </Button>
                                )}
                            </div>
                        </div>
                    ) : (
                        <ul className="divide-border -my-2 divide-y" aria-label="Compose versions">
                            {data.versions.map((version) => (
                                <li key={version.version} className="flex items-center gap-3 py-2 text-sm">
                                    <Tag mono>v{version.version}</Tag>
                                    <span className="text-fg-muted flex-1 truncate text-xs">
                                        {version.created_by ?? 'System'} · <RelativeTime value={version.created_at} />
                                    </span>
                                    {version.version === data.version ? (
                                        <Tag tone="success">Current</Tag>
                                    ) : (
                                        <Button size="sm" variant="ghost" onClick={() => void showVersion(version.version)}>
                                            Compare
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>
            )}
        </div>
    );
}

function Validation({ summary, validating, allowPrivileged }: { summary: ComposeSummary; validating: boolean; allowPrivileged: boolean }) {
    const violations = summary.violations;

    return (
        <div className="grid gap-2" aria-live="polite" data-testid="compose-validation">
            {summary.errors.length > 0 ? (
                <Callout tone="danger" title="The compose file cannot be deployed">
                    <ul className="list-disc pl-4">
                        {summary.errors.map((item) => (
                            <li key={item}>{item}</li>
                        ))}
                    </ul>
                </Callout>
            ) : violations.length > 0 ? (
                <Callout
                    tone={allowPrivileged ? 'warning' : 'danger'}
                    title={allowPrivileged ? 'Privileged — allowed by the organization policy' : 'Blocked by the compose policy'}
                >
                    <ul className="list-disc pl-4">
                        {violations.map((item) => (
                            <li key={item}>{item}</li>
                        ))}
                    </ul>
                    {!allowPrivileged && (
                        <p className="mt-1.5 text-xs">
                            An admin can allow privileged compose files in{' '}
                            <Link href="/settings/compose" className="underline">
                                Settings → Compose
                            </Link>
                            .
                        </p>
                    )}
                </Callout>
            ) : (
                <p className="text-fg-muted flex flex-wrap items-center gap-1.5 text-xs">
                    <ShieldCheck className="text-success size-3.5" aria-hidden />
                    {validating ? 'Checking…' : 'Valid · passes the compose policy'}
                    <span className="text-fg-faint">·</span>
                    {summary.services.map((service) => (
                        <Tag key={service.name} mono>
                            {service.name}
                            {service.ports.length > 0 ? `:${service.ports.join(',')}` : ''}
                        </Tag>
                    ))}
                    {summary.volumes.map((volume) => (
                        <Tag key={volume} mono tone="info">
                            ◉ {volume}
                        </Tag>
                    ))}
                </p>
            )}
            {summary.warnings.map((warning) => (
                <p key={warning} className="text-fg-faint flex items-start gap-1.5 text-xs">
                    <ShieldAlert className="mt-0.5 size-3.5 shrink-0" aria-hidden />
                    {warning}
                </p>
            ))}
        </div>
    );
}
