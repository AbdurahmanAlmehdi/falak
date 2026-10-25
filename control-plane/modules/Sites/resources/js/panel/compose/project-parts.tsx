import { Button, Callout, Checkbox, Field, IconButton, Input, Select, Tag } from '@/components/falak';
import { type ComposeServiceChoice } from '@/lib/registry';
import { Database, FileCode2, Hammer, Plus, Trash2, Wand2 } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { lineDiff } from '../api';
import { ENGINE_LABELS, KEY_VALUE_ENGINES, type Adjustment, type InspectedService, type InspectedVariable, type Inspection } from './project-api';
import { DiffView } from './yaml-editor';

const parseProfiles = (text: string) =>
    text
        .split(/[\s,]+/)
        .map((p) => p.trim())
        .filter(Boolean);

/** Compose files in -f order (the first is the project file) + active profiles. */
export function ProjectFiles({
    files,
    profiles,
    candidates,
    onChange,
    disabled = false,
    errors = {},
}: {
    files: string[];
    profiles: string[];
    candidates: string[];
    onChange: (files: string[], profiles: string[]) => void;
    disabled?: boolean;
    errors?: Record<string, string>;
}) {
    const list = files.length > 0 ? files : [''];
    const [profileText, setProfileText] = useState(profiles.join(', '));
    // Follow the prop when it changes from outside (Reset): the text is kept only while it still parses to it.
    if (parseProfiles(profileText).join(',') !== profiles.join(',')) setProfileText(profiles.join(', '));
    const setFile = (index: number, value: string) =>
        onChange(
            list.map((file, i) => (i === index ? value.trim() : file)),
            profiles,
        );
    const listId = 'compose-file-candidates';

    return (
        <div className="grid gap-3">
            <datalist id={listId}>
                {candidates.map((file) => (
                    <option key={file} value={file} />
                ))}
            </datalist>
            {list.map((file, index) => (
                <Field
                    key={index}
                    label={index === 0 ? 'Compose file' : `Override file ${index}`}
                    hint={
                        index === 0
                            ? candidates.length > 0
                                ? `Path in the repository. Found: ${candidates.slice(0, 4).join(', ')}${candidates.length > 4 ? '…' : ''}`
                                : 'Path in the repository, e.g. docker/compose.prod.yml.'
                            : 'Merged over the files before it, like docker compose -f.'
                    }
                    error={errors[`compose_files.${index}`] ?? (index === 0 ? errors.compose_files : undefined)}
                >
                    <Input
                        mono
                        list={listId}
                        value={file}
                        placeholder={index === 0 ? 'compose.yaml' : 'compose.prod.yaml'}
                        disabled={disabled}
                        aria-label={index === 0 ? 'Compose file' : `Override file ${index}`}
                        onChange={(event) => setFile(index, event.target.value)}
                        prefix={<FileCode2 aria-hidden />}
                        suffix={
                            index > 0 && !disabled ? (
                                <IconButton
                                    size="sm"
                                    variant="ghost"
                                    label={`Remove override file ${index}`}
                                    icon={<Trash2 />}
                                    onClick={() =>
                                        onChange(
                                            list.filter((_, i) => i !== index),
                                            profiles,
                                        )
                                    }
                                />
                            ) : undefined
                        }
                    />
                </Field>
            ))}
            <div className="flex flex-wrap items-end gap-3">
                {!disabled && (
                    <Button
                        size="sm"
                        variant="ghost"
                        icon={<Plus />}
                        disabled={list[list.length - 1] === ''}
                        onClick={() => onChange([...list, ''], profiles)}
                    >
                        Add override file
                    </Button>
                )}
                <Field label="Profiles" hint="Services of other profiles don't run." className="min-w-48 flex-1" error={errors.compose_profiles}>
                    <Input
                        value={profileText}
                        placeholder="none"
                        disabled={disabled}
                        aria-label="Profiles"
                        onChange={(event) => {
                            setProfileText(event.target.value);
                            onChange(list, parseProfiles(event.target.value));
                        }}
                    />
                </Field>
            </div>
        </div>
    );
}

export type RowChoice = 'keep' | 'public' | 'database' | 'site';

const SITE_FRAMEWORKS = [
    { value: 'docker', label: 'Docker (its Dockerfile)' },
    { value: 'laravel', label: 'Laravel' },
    { value: 'node', label: 'Node.js' },
];

/**
 * One row per service: what it is, and where it runs (in the stack, public, a Falak database, its own Falak service).
 * `renderPublic` draws the public settings (create flow) — Settings keeps them in their own section.
 */
export function ServicesTable({
    services,
    choiceOf,
    onChoice,
    keepBinds,
    onKeepBinds,
    renderPublic,
    withPublic = true,
    disabled = false,
    errors = {},
}: {
    services: InspectedService[];
    choiceOf: (service: string) => { row: RowChoice; choice: ComposeServiceChoice };
    onChoice: (service: string, row: RowChoice, choice: ComposeServiceChoice) => void;
    keepBinds: string[];
    onKeepBinds: (keys: string[]) => void;
    renderPublic?: (service: InspectedService) => ReactNode;
    withPublic?: boolean;
    disabled?: boolean;
    errors?: Record<string, string>;
}) {
    return (
        <ul className="border-border divide-border divide-y rounded-md border" aria-label="Compose services">
            {services.map((service) => {
                const { row, choice } = choiceOf(service.name);
                const options: { value: RowChoice; label: string }[] = [
                    { value: 'keep', label: 'In the stack' },
                    ...(withPublic ? [{ value: 'public' as const, label: 'In the stack, public' }] : []),
                    ...(service.database_engine
                        ? [{ value: 'database' as const, label: `Falak ${ENGINE_LABELS[service.database_engine]} database` }]
                        : []),
                    { value: 'site', label: 'Own Falak service' },
                ];
                const missingBinds = service.binds.filter((bind) => !bind.in_repo && bind.source.startsWith('./'));

                return (
                    <li key={service.name} className="grid gap-2 px-3 py-2.5" data-testid={`compose-service-${service.name}`}>
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="text-fg font-mono text-sm font-medium">{service.name}</span>
                            {service.build ? (
                                <Tag icon={<Hammer aria-hidden />} mono>
                                    build {service.build_context ?? '.'}
                                </Tag>
                            ) : (
                                <Tag mono>{service.image}</Tag>
                            )}
                            {service.ports.map((port) => (
                                <Tag key={port} mono tone="faint">
                                    :{port}
                                </Tag>
                            ))}
                            {service.volumes.length > 0 && <Tag tone="faint">{service.volumes.length} volume(s)</Tag>}
                            <div className="ml-auto w-56">
                                <Select<RowChoice>
                                    size="sm"
                                    aria-label={`${service.name} runs`}
                                    value={row}
                                    disabled={disabled}
                                    onValueChange={(value) =>
                                        onChoice(
                                            service.name,
                                            value,
                                            value === 'database'
                                                ? { mode: 'database', engine: service.database_engine ?? undefined }
                                                : value === 'site'
                                                  ? { mode: 'site', site: { name: service.name, framework: 'docker' } }
                                                  : { mode: 'keep' },
                                        )
                                    }
                                    options={options}
                                />
                            </div>
                        </div>
                        {row === 'public' && renderPublic?.(service)}
                        {row === 'database' && (
                            <div className="grid gap-1" data-testid={`compose-database-note-${service.name}`}>
                                <p className="text-fg-muted flex items-center gap-1.5 text-xs">
                                    <Database className="size-3.5" aria-hidden />
                                    {KEY_VALUE_ENGINES.includes(choice.engine ?? '') ? (
                                        <>
                                            Falak creates a {ENGINE_LABELS[choice.engine ?? '']} instance on the stack’s server (password, memory
                                            limit, persistence) and points the stack’s variables that used {service.name} at it; the containers reach
                                            it through the Docker bridge.
                                        </>
                                    ) : (
                                        <>
                                            Falak creates a {ENGINE_LABELS[choice.engine ?? ''] ?? 'database'} database (backups, metrics) and points
                                            the stack’s variables that used {service.name} at it.
                                        </>
                                    )}
                                </p>
                                <p className="text-warning text-xs">
                                    {service.name}’s existing data is not copied: the Falak{' '}
                                    {KEY_VALUE_ENGINES.includes(choice.engine ?? '') ? 'instance' : 'database'} starts empty.
                                </p>
                            </div>
                        )}
                        {row === 'site' && (
                            <div className="grid gap-2 sm:grid-cols-2">
                                <Field label="Service name" error={errors[`compose_services.${service.name}.site.name`]}>
                                    <Input
                                        value={choice.site?.name ?? service.name}
                                        disabled={disabled}
                                        onChange={(event) =>
                                            onChoice(service.name, 'site', { ...choice, site: { ...choice.site, name: event.target.value } })
                                        }
                                    />
                                </Field>
                                <Field label="Runs as" hint={service.build_context ? `From ${service.build_context} of the repository.` : undefined}>
                                    <Select
                                        value={choice.site?.framework ?? 'docker'}
                                        disabled={disabled}
                                        onValueChange={(framework) =>
                                            onChoice(service.name, 'site', { ...choice, site: { ...choice.site, framework } })
                                        }
                                        options={SITE_FRAMEWORKS}
                                    />
                                </Field>
                                {(service.uses?.length ?? 0) > 0 && (
                                    <p className="text-fg-muted text-xs sm:col-span-2">
                                        {(choice.site?.framework ?? 'docker') === 'docker' ? (
                                            <>
                                                It joins the stack’s network on the stack’s servers, so it still reaches {service.uses?.join(', ')} by
                                                name.
                                            </>
                                        ) : (
                                            <span className="text-warning">
                                                {service.name} uses {service.uses?.join(', ')} inside the stack; a native site can only reach public
                                                services — pick Docker, or make them public.
                                            </span>
                                        )}
                                    </p>
                                )}
                            </div>
                        )}
                        {errors[`compose_services.${service.name}.mode`] && (
                            <p className="text-danger text-xs">{errors[`compose_services.${service.name}.mode`]}</p>
                        )}
                        {row !== 'database' &&
                            row !== 'site' &&
                            missingBinds.map((bind) => (
                                <label key={bind.key} className="text-fg-muted flex items-start gap-2 text-xs">
                                    <Checkbox
                                        checked={keepBinds.includes(bind.key)}
                                        disabled={disabled}
                                        onCheckedChange={(checked) =>
                                            onKeepBinds(checked === true ? [...keepBinds, bind.key] : keepBinds.filter((key) => key !== bind.key))
                                        }
                                    />
                                    <span>
                                        <span className="font-mono">{bind.source}</span> isn’t in the repository: Falak mounts a named volume (kept
                                        across deploys). Tick to keep an empty folder per release instead.
                                    </span>
                                </label>
                            ))}
                    </li>
                );
            })}
        </ul>
    );
}

/** Variables the stack reads: editable (create) or listed with where to set them (Settings). */
export function VariablesList({
    variables,
    values,
    onChange,
    note,
}: {
    variables: InspectedVariable[];
    values?: Record<string, string>;
    onChange?: (values: Record<string, string>) => void;
    note?: ReactNode;
}) {
    // The note (e.g. the create request's error) shows even when the preview lists no variables.
    if (variables.length === 0) {
        return (
            <div className="grid gap-2">
                {note}
                <p className="text-fg-muted text-sm">The stack reads no variables.</p>
            </div>
        );
    }

    return (
        <div className="grid gap-2">
            {note}
            <ul className="grid gap-2" aria-label="Stack variables">
                {variables.map((variable) => (
                    <li key={variable.name} className="grid items-center gap-2 sm:grid-cols-[minmax(0,14rem)_minmax(0,1fr)]">
                        <span className="flex min-w-0 items-center gap-1.5">
                            <span className="text-fg truncate font-mono text-xs">{variable.name}</span>
                            {variable.required && <Tag tone="warning">required</Tag>}
                        </span>
                        {onChange ? (
                            <Input
                                mono
                                aria-label={variable.name}
                                value={values?.[variable.name] ?? ''}
                                placeholder={variable.default ?? (variable.required ? 'needs a value' : 'empty')}
                                onChange={(event) => onChange({ ...(values ?? {}), [variable.name]: event.target.value })}
                            />
                        ) : (
                            <span className="text-fg-faint truncate text-xs">
                                {variable.source} · {variable.services.join(', ')}
                                {variable.default !== null ? ` · default ${variable.default}` : ''}
                            </span>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}

/** What Falak changes before the stack runs, with the full diff on demand. */
export function AdjustmentsList({ inspection }: { inspection: Inspection }) {
    const [diff, setDiff] = useState(false);
    const adjustments: Adjustment[] = inspection.adjustments;

    return (
        <div className="grid gap-2">
            {adjustments.length === 0 ? (
                <p className="text-fg-muted text-sm">Falak runs the project as it is (besides publishing public services on loopback ports).</p>
            ) : (
                <ul className="grid gap-1.5" aria-label="Falak adjustments">
                    {adjustments.map((item, index) => (
                        <li key={index} className="flex items-start gap-2 text-xs">
                            <Wand2 className="text-fg-faint mt-0.5 size-3.5 shrink-0" aria-hidden />
                            <span className="text-fg-muted">
                                {item.service && <span className="text-fg font-mono">{item.service}: </span>}
                                {item.detail}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
            {inspection.original !== undefined && inspection.adjusted !== undefined && (
                <div className="grid gap-2">
                    <Button size="sm" variant="ghost" className="w-fit" onClick={() => setDiff(!diff)}>
                        {diff ? 'Hide the diff' : 'Show the diff'}
                    </Button>
                    {diff && <DiffView diff={lineDiff(inspection.original, inspection.adjusted)} header="Your project → what Falak runs" />}
                </div>
            )}
        </div>
    );
}

/** Errors (blocking), policy violations and warnings of an inspection. */
export function InspectionProblems({ inspection, allowPrivileged = false }: { inspection: Inspection; allowPrivileged?: boolean }) {
    return (
        <>
            {inspection.errors.length > 0 && (
                <Callout tone="danger" title="The compose project can’t be used">
                    <ul className="grid gap-0.5">
                        {inspection.errors.map((message) => (
                            <li key={message}>{message}</li>
                        ))}
                    </ul>
                </Callout>
            )}
            {inspection.violations.length > 0 && (
                <Callout
                    tone={allowPrivileged ? 'warning' : 'danger'}
                    title={allowPrivileged ? 'Privileged settings (allowed)' : 'Blocked by the compose policy'}
                >
                    <ul className="grid gap-0.5">
                        {inspection.violations.map((message) => (
                            <li key={message}>{message}</li>
                        ))}
                    </ul>
                </Callout>
            )}
            {inspection.warnings.length > 0 && (
                <ul className="text-fg-muted grid gap-0.5 text-xs" aria-label="Warnings">
                    {inspection.warnings.map((message) => (
                        <li key={message}>{message}</li>
                    ))}
                </ul>
            )}
        </>
    );
}
