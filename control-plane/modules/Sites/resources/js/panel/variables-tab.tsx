import {
    Button,
    Callout,
    ChangesBar,
    Dialog,
    EmptyState,
    IconButton,
    Input,
    ManagedBadge,
    Menu,
    RelativeTime,
    Segmented,
    SkeletonRows,
    Switch,
    Tag,
    Textarea,
    Tooltip,
    managedCovers,
    pointer,
    toast,
} from '@/components/falak';
import { useJson } from '@/hooks/use-json';
import { HttpError, errorMessage, requestJson } from '@/lib/http';
import { type ServiceTabProps } from '@/lib/registry';
import { cn } from '@/lib/utils';
import {
    ArrowUpRight,
    Check,
    Eye,
    EyeOff,
    History,
    KeyRound,
    Link2,
    Lock,
    Pencil,
    Plus,
    RotateCcw,
    Trash2,
    TriangleAlert,
    Undo2,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import {
    deployNow,
    dotenvKeys,
    environmentUrl,
    isReferenceOnly,
    lineDiff,
    parseDotenv,
    referencesIn,
    referencesUrl,
    serviceHandle,
    unresolved,
    type EnvironmentState,
    type ReferenceTarget,
    type RevealedEnvironment,
} from './api';
import { ReferencePicker } from './reference-picker';

const KEY = /^[A-Za-z_][A-Za-z0-9_]*$/;
const MASK = '••••••••••';

/** Staged edits of the table view; applied as one new version. */
interface Draft {
    set: Record<string, string>;
    unset: string[];
    exposed: Record<string, boolean>;
}

const EMPTY: Draft = { set: {}, unset: [], exposed: {} };

function draftCount(draft: Draft): number {
    return new Set([...Object.keys(draft.set), ...draft.unset, ...Object.keys(draft.exposed)]).size;
}

/** Value input with the `${{ … }}` picker; inserts at the cursor. */
function ValueEditor({
    value,
    onChange,
    onSubmit,
    onCancel,
    targets,
    selfHandle,
    autoFocus,
    placeholder = 'value or ${{ service.KEY }}',
}: {
    value: string;
    onChange: (value: string) => void;
    onSubmit: () => void;
    onCancel: () => void;
    targets: ReferenceTarget[] | null;
    selfHandle: string;
    autoFocus?: boolean;
    placeholder?: string;
}) {
    const input = useRef<HTMLInputElement>(null);

    const insert = (reference: string) => {
        const element = input.current;
        const start = element?.selectionStart ?? value.length;
        const end = element?.selectionEnd ?? value.length;
        const next = value.slice(0, start) + reference + value.slice(end);
        onChange(next);
        window.requestAnimationFrame(() => {
            element?.focus();
            element?.setSelectionRange(start + reference.length, start + reference.length);
        });
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        event.stopPropagation();
        if (event.key === 'Enter') {
            event.preventDefault();
            onSubmit();
        }
        if (event.key === 'Escape') {
            event.preventDefault();
            onCancel();
        }
    };

    return (
        <div className="flex min-w-0 flex-1 items-center gap-1">
            <Input
                ref={input}
                mono
                value={value}
                autoFocus={autoFocus}
                aria-label="Value"
                placeholder={placeholder}
                spellCheck={false}
                autoComplete="off"
                onChange={(event) => onChange(event.target.value)}
                onKeyDown={onKeyDown}
                className="h-7"
            />
            <ReferencePicker targets={targets} selfHandle={selfHandle} onPick={insert} />
        </div>
    );
}

function Unresolved({ problems }: { problems: string[] }) {
    if (problems.length === 0) return null;

    return (
        <p className="text-warning flex items-start gap-1 text-[11px]">
            <TriangleAlert className="mt-px size-3 shrink-0" aria-hidden />
            <span>{problems.join(' · ')}</span>
        </p>
    );
}

/** §5.1 Variables — table with audited reveal, staged inline edits, raw editor with diff, references, history. */
export function VariablesTab({ ctx }: ServiceTabProps) {
    const siteId = ctx.service.ref_id;
    const { data, error, reload } = useJson<EnvironmentState>(environmentUrl(siteId));
    const refs = useJson<{ services: ReferenceTarget[] }>(referencesUrl(ctx));
    const [mode, setMode] = useState<'table' | 'raw'>('table');
    const [revealed, setRevealed] = useState<RevealedEnvironment | null>(null);
    const [shown, setShown] = useState<Set<string>>(new Set());
    const [revealing, setRevealing] = useState(false);
    const [draft, setDraft] = useState<Draft>(EMPTY);
    const [editing, setEditing] = useState<{ key: string; value: string } | null>(null);
    const [adding, setAdding] = useState<{ key: string; value: string } | null>(null);
    const [addError, setAddError] = useState<string | null>(null);
    const [applying, setApplying] = useState<'save' | 'deploy' | null>(null);
    const [history, setHistory] = useState(false);

    const current = data?.current ?? null;
    const selfHandle = serviceHandle(ctx.service.name);
    const targets = refs.data?.services ?? null;
    const count = draftCount(draft);

    // A newer version (saved elsewhere) invalidates what we revealed.
    useEffect(() => {
        if (revealed && current && revealed.version !== current.version) {
            setRevealed(null);
            setShown(new Set());
        }
    }, [current, revealed]);

    const values = useMemo(() => (revealed ? parseDotenv(revealed.content) : null), [revealed]);

    const reveal = useCallback(async (): Promise<Record<string, string> | null> => {
        if (values) return values;
        setRevealing(true);
        try {
            const body = await requestJson<{ data: RevealedEnvironment }>(`${environmentUrl(siteId)}/reveal`, 'POST', {});
            setRevealed(body.data);

            return parseDotenv(body.data.content);
        } catch (e) {
            toast.error('Could not reveal', errorMessage(e));

            return null;
        } finally {
            setRevealing(false);
        }
    }, [siteId, values]);

    const keys = useMemo(() => {
        const saved = current?.keys ?? [];
        const added = Object.keys(draft.set).filter((key) => !saved.includes(key));

        return [...saved, ...added];
    }, [current, draft.set]);

    const selfKeys = useMemo(() => keys.filter((key) => !draft.unset.includes(key)), [keys, draft.unset]);

    if (!data) return error ? <Callout tone="danger">{error}</Callout> : <SkeletonRows rows={8} />;

    const can = data.can;
    const managedKey = (key: string) => managedCovers(data.config, pointer('env', key));
    const exposeManaged = managedCovers(data.config, '/expose');

    const toggleShown = async (key: string) => {
        if (shown.has(key)) {
            setShown((prev) => new Set([...prev].filter((item) => item !== key)));

            return;
        }
        if (await reveal()) setShown((prev) => new Set([...prev, key]));
    };

    const valueOf = (key: string): string | null => {
        if (key in draft.set) return draft.set[key];
        if (current?.references[key] !== undefined) return current.references[key];

        return values?.[key] ?? null;
    };

    const isExposed = (key: string) => draft.exposed[key] ?? current?.exposed.includes(key) ?? false;

    const stage = (key: string, value: string) => {
        setDraft((prev) => {
            const set = { ...prev.set };
            const unchanged = values?.[key] === value || current?.references[key] === value;
            if (unchanged && current?.keys.includes(key)) delete set[key];
            else set[key] = value;

            return { ...prev, set, unset: prev.unset.filter((item) => item !== key) };
        });
    };

    const startEdit = async (key: string) => {
        const known = valueOf(key);
        if (known !== null) {
            setEditing({ key, value: known });

            return;
        }
        if (!can.reveal) {
            setEditing({ key, value: '' });

            return;
        }
        const revealedValues = await reveal();
        if (revealedValues) setEditing({ key, value: revealedValues[key] ?? '' });
    };

    const remove = (key: string) =>
        setDraft((prev) => {
            if (!current?.keys.includes(key)) {
                const set = { ...prev.set };
                delete set[key];
                const exposed = { ...prev.exposed };
                delete exposed[key];

                return { ...prev, set, exposed };
            }

            return { ...prev, unset: [...new Set([...prev.unset, key])] };
        });

    const undo = (key: string) =>
        setDraft((prev) => {
            const set = { ...prev.set };
            const exposed = { ...prev.exposed };
            delete set[key];
            delete exposed[key];

            return { set, exposed, unset: prev.unset.filter((item) => item !== key) };
        });

    const setExposed = (key: string, on: boolean) =>
        setDraft((prev) => {
            const exposed = { ...prev.exposed };
            if ((current?.exposed.includes(key) ?? false) === on) delete exposed[key];
            else exposed[key] = on;

            return { ...prev, exposed };
        });

    const commitAdd = () => {
        if (!adding) return;
        const key = adding.key.trim();
        if (!KEY.test(key)) {
            setAddError('Use letters, digits and underscores; don’t start with a digit.');

            return;
        }
        if (keys.includes(key) && !draft.unset.includes(key)) {
            setAddError(`${key} already exists — edit its row instead.`);

            return;
        }
        stage(key, adding.value);
        setAdding(null);
        setAddError(null);
    };

    const apply = async (then: 'save' | 'deploy') => {
        setApplying(then);
        try {
            const body = await requestJson<{ data: { version: number } }>(environmentUrl(siteId), 'PATCH', {
                set: draft.set,
                unset: draft.unset,
                exposed: draft.exposed,
                base_version: current?.version ?? null,
            });
            toast.success(`Saved as version ${body.data.version}`, then === 'deploy' ? 'Deploying…' : 'Changes apply on the next deploy.');
            setDraft(EMPTY);
            setRevealed(null);
            setShown(new Set());
            await Promise.all([reload(), refs.reload()]);
            ctx.refresh();
            if (then === 'deploy') await deployNow(ctx);
        } catch (e) {
            toast.error('Could not save variables', e instanceof HttpError ? Object.values(e.errors)[0] || e.message : errorMessage(e));
        } finally {
            setApplying(null);
        }
    };

    const rows = keys.map((key) => {
        const status: 'new' | 'changed' | 'removed' | null = draft.unset.includes(key)
            ? 'removed'
            : !current?.keys.includes(key)
              ? 'new'
              : key in draft.set || key in draft.exposed
                ? 'changed'
                : null;
        const value = valueOf(key);
        const reference = value !== null && isReferenceOnly(value);
        const visible = key in draft.set || reference || shown.has(key);

        return { key, status, value, reference, visible };
    });

    return (
        <div className="grid gap-4" data-testid="variables-tab">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                    <Segmented
                        label="Editor"
                        value={mode}
                        onValueChange={(next) => {
                            if (next === 'raw' && count > 0) {
                                toast.info('Apply or discard your changes first', 'The raw editor edits the saved version.');

                                return;
                            }
                            setMode(next);
                        }}
                        options={[
                            { value: 'table', label: 'Table' },
                            { value: 'raw', label: 'Raw editor' },
                        ]}
                    />
                    <span className="text-fg-faint text-xs">
                        {current ? (
                            <>
                                v{current.version} · {current.keys.length} {current.keys.length === 1 ? 'variable' : 'variables'} · saved{' '}
                                <RelativeTime value={current.created_at} />
                            </>
                        ) : (
                            'No variables yet'
                        )}
                    </span>
                </div>
                <div className="flex items-center gap-1.5">
                    {mode === 'table' && can.reveal && (current?.keys.length ?? 0) > 0 && (
                        <Button
                            size="sm"
                            variant="ghost"
                            icon={shown.size > 0 ? <EyeOff /> : <Eye />}
                            loading={revealing}
                            onClick={async () => {
                                if (shown.size > 0) {
                                    setShown(new Set());

                                    return;
                                }
                                const all = await reveal();
                                if (all) setShown(new Set(Object.keys(all)));
                            }}
                        >
                            {shown.size > 0 ? 'Hide values' : 'Reveal all'}
                        </Button>
                    )}
                    <Button size="sm" variant="ghost" icon={<History />} onClick={() => setHistory(true)}>
                        History
                    </Button>
                    {mode === 'table' && can.update && (
                        <Button
                            size="sm"
                            icon={<Plus />}
                            onClick={() => {
                                setAdding({ key: '', value: '' });
                                setAddError(null);
                            }}
                        >
                            New variable
                        </Button>
                    )}
                </div>
            </div>

            {current && current.reference_errors.length > 0 && (
                <Callout tone="warning" title="Unresolved references — the next deploy fails until they are fixed">
                    <ul className="grid gap-0.5 font-mono text-xs">
                        {current.reference_errors.map((problem) => (
                            <li key={problem}>{problem}</li>
                        ))}
                    </ul>
                </Callout>
            )}

            {mode === 'raw' ? (
                <RawEditor
                    siteId={siteId}
                    state={data}
                    reveal={reveal}
                    revealed={revealed}
                    targets={targets}
                    selfHandle={selfHandle}
                    onSaved={async (then) => {
                        setRevealed(null);
                        setShown(new Set());
                        await Promise.all([reload(), refs.reload()]);
                        ctx.refresh();
                        if (then === 'deploy') await deployNow(ctx);
                    }}
                />
            ) : (
                <>
                    {rows.length === 0 && !adding ? (
                        <EmptyState
                            size="sm"
                            icon={<KeyRound />}
                            title="No variables"
                            description="Variables are written to the release’s .env on every deploy. Reference other services with ${{ service.KEY }}."
                            action={
                                can.update && (
                                    <Button size="sm" variant="primary" icon={<Plus />} onClick={() => setAdding({ key: '', value: '' })}>
                                        New variable
                                    </Button>
                                )
                            }
                        />
                    ) : (
                        <div className="border-border bg-surface-1 overflow-x-auto rounded-lg border">
                            <table className="w-full border-collapse text-left text-xs">
                                <caption className="sr-only">Variables</caption>
                                <thead>
                                    <tr className="border-border text-fg-faint border-b">
                                        <th scope="col" className="h-8 w-[34%] px-3 font-medium">
                                            Key
                                        </th>
                                        <th scope="col" className="h-8 px-3 font-medium">
                                            Value
                                        </th>
                                        <th scope="col" className="h-8 w-28 px-3 text-center font-medium whitespace-nowrap">
                                            <Tooltip content="Export into the deploy script’s shell and the build environment (always in .env)">
                                                <span className="cursor-help underline decoration-dotted underline-offset-2">Deploy script</span>
                                            </Tooltip>
                                        </th>
                                        <th scope="col" className="h-8 w-20 px-2">
                                            <span className="sr-only">Actions</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {adding && (
                                        <tr className="border-border bg-surface-2/50 border-b align-top">
                                            <td className="px-3 py-2">
                                                <Input
                                                    mono
                                                    autoFocus
                                                    aria-label="Key"
                                                    placeholder="KEY"
                                                    value={adding.key}
                                                    aria-invalid={addError ? true : undefined}
                                                    onChange={(event) =>
                                                        setAdding({ ...adding, key: event.target.value.toUpperCase().replace(/[^A-Z0-9_]/g, '_') })
                                                    }
                                                    onKeyDown={(event) => {
                                                        event.stopPropagation();
                                                        if (event.key === 'Enter') commitAdd();
                                                        if (event.key === 'Escape') setAdding(null);
                                                    }}
                                                    className="h-7"
                                                />
                                                {addError && <p className="text-danger mt-1 text-[11px]">{addError}</p>}
                                            </td>
                                            <td className="px-3 py-2">
                                                <ValueEditor
                                                    value={adding.value}
                                                    onChange={(value) => setAdding({ ...adding, value })}
                                                    onSubmit={commitAdd}
                                                    onCancel={() => setAdding(null)}
                                                    targets={targets}
                                                    selfHandle={selfHandle}
                                                />
                                                <div className="mt-1">
                                                    <Unresolved
                                                        problems={unresolved(adding.value, targets, { handle: selfHandle, keys: selfKeys })}
                                                    />
                                                </div>
                                            </td>
                                            <td />
                                            <td className="px-2 py-2">
                                                <div className="flex justify-end gap-0.5">
                                                    <IconButton size="sm" label="Add" icon={<Check />} onClick={commitAdd} />
                                                    <IconButton size="sm" label="Cancel" icon={<X />} onClick={() => setAdding(null)} />
                                                </div>
                                            </td>
                                        </tr>
                                    )}
                                    {rows.map((row) => {
                                        const isEditing = editing?.key === row.key;
                                        const problems =
                                            row.value !== null && row.status !== 'removed'
                                                ? unresolved(isEditing ? editing.value : row.value, targets, { handle: selfHandle, keys: selfKeys })
                                                : [];
                                        const referencing =
                                            row.value !== null ? referencesIn(row.value).length > 0 : current?.referencing.includes(row.key);

                                        return (
                                            <tr
                                                key={row.key}
                                                data-key={row.key}
                                                className={cn(
                                                    'border-border group border-b align-top last:border-b-0',
                                                    row.status === 'removed' && 'opacity-60',
                                                    row.status && row.status !== 'removed' && 'bg-warning-soft/40',
                                                )}
                                            >
                                                <td className="px-3 py-2">
                                                    <div className="flex min-h-7 items-center gap-1.5">
                                                        <span
                                                            className={cn('text-fg truncate font-mono', row.status === 'removed' && 'line-through')}
                                                        >
                                                            {row.key}
                                                        </span>
                                                        {managedKey(row.key) && <ManagedBadge config={data.config} />}
                                                        {row.status && (
                                                            <Tag
                                                                tone={
                                                                    row.status === 'removed' ? 'danger' : row.status === 'new' ? 'success' : 'warning'
                                                                }
                                                            >
                                                                {row.status}
                                                            </Tag>
                                                        )}
                                                    </div>
                                                </td>
                                                <td className="px-3 py-2">
                                                    {isEditing ? (
                                                        <ValueEditor
                                                            autoFocus
                                                            value={editing.value}
                                                            onChange={(value) => setEditing({ key: row.key, value })}
                                                            onSubmit={() => {
                                                                stage(row.key, editing.value);
                                                                setEditing(null);
                                                            }}
                                                            onCancel={() => setEditing(null)}
                                                            targets={targets}
                                                            selfHandle={selfHandle}
                                                            placeholder={can.reveal ? undefined : 'new value (the current one stays hidden)'}
                                                        />
                                                    ) : (
                                                        <div className="flex min-h-7 min-w-0 items-center gap-1.5">
                                                            {referencing && (
                                                                <Tooltip content="References another service">
                                                                    <Link2 className="text-primary size-3.5 shrink-0" aria-label="Reference" />
                                                                </Tooltip>
                                                            )}
                                                            {row.visible && row.value !== null ? (
                                                                <span
                                                                    className={cn(
                                                                        'min-w-0 font-mono break-all whitespace-pre-wrap',
                                                                        row.reference ? 'text-primary' : 'text-fg',
                                                                    )}
                                                                >
                                                                    {row.value === '' ? (
                                                                        <span className="text-fg-faint italic">empty</span>
                                                                    ) : (
                                                                        row.value
                                                                    )}
                                                                </span>
                                                            ) : (
                                                                <span className="text-fg-faint font-mono tracking-widest">{MASK}</span>
                                                            )}
                                                            {row.visible &&
                                                                row.value !== null &&
                                                                referencesIn(row.value)
                                                                    .map((reference) =>
                                                                        targets?.find((target) => target.handle === serviceHandle(reference.service)),
                                                                    )
                                                                    .filter(
                                                                        (target, index, all): target is ReferenceTarget =>
                                                                            Boolean(target) && all.indexOf(target) === index,
                                                                    )
                                                                    .filter((target) => target.ref_id !== ctx.service.ref_id)
                                                                    .map((target) => (
                                                                        <button
                                                                            key={target.id}
                                                                            type="button"
                                                                            onClick={() =>
                                                                                ctx.openService({ kind: target.kind, ref_id: target.ref_id })
                                                                            }
                                                                            className="border-border bg-surface-2 text-fg-muted hover:border-border-strong hover:text-fg ml-1 inline-flex h-5 shrink-0 items-center gap-1 rounded-sm border px-1.5 text-xs transition-colors"
                                                                            aria-label={`Open ${target.name}`}
                                                                            data-testid="reference-target"
                                                                        >
                                                                            {target.name}
                                                                            <ArrowUpRight className="size-3" aria-hidden />
                                                                        </button>
                                                                    ))}
                                                            {can.reveal && !row.reference && !(row.key in draft.set) && row.status !== 'new' && (
                                                                <IconButton
                                                                    size="sm"
                                                                    className="ml-auto opacity-60 group-hover:opacity-100"
                                                                    label={shown.has(row.key) ? `Hide ${row.key}` : `Reveal ${row.key} (audited)`}
                                                                    icon={shown.has(row.key) ? <EyeOff /> : <Eye />}
                                                                    onClick={() => void toggleShown(row.key)}
                                                                />
                                                            )}
                                                        </div>
                                                    )}
                                                    {problems.length > 0 && (
                                                        <div className="mt-1">
                                                            <Unresolved problems={problems} />
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="px-3 py-2 text-center">
                                                    <div className="flex min-h-7 items-center justify-center">
                                                        <Switch
                                                            aria-label={`Expose ${row.key} to the deploy script and build`}
                                                            checked={isExposed(row.key)}
                                                            disabled={!can.update || row.status === 'removed' || exposeManaged}
                                                            onCheckedChange={(on) => setExposed(row.key, on)}
                                                        />
                                                    </div>
                                                </td>
                                                <td className="px-2 py-2">
                                                    <div className="flex min-h-7 items-center justify-end gap-0.5">
                                                        {isEditing ? (
                                                            <>
                                                                <IconButton
                                                                    size="sm"
                                                                    label="Keep change"
                                                                    icon={<Check />}
                                                                    onClick={() => {
                                                                        stage(row.key, editing.value);
                                                                        setEditing(null);
                                                                    }}
                                                                />
                                                                <IconButton size="sm" label="Cancel" icon={<X />} onClick={() => setEditing(null)} />
                                                            </>
                                                        ) : row.status ? (
                                                            <IconButton
                                                                size="sm"
                                                                label={`Undo changes to ${row.key}`}
                                                                icon={<Undo2 />}
                                                                onClick={() => undo(row.key)}
                                                            />
                                                        ) : null}
                                                        {can.update && !isEditing && row.status !== 'removed' && !managedKey(row.key) && (
                                                            <Menu
                                                                label={`${row.key} actions`}
                                                                actions={[
                                                                    {
                                                                        label: 'Edit value',
                                                                        icon: <Pencil />,
                                                                        onSelect: () => void startEdit(row.key),
                                                                    },
                                                                    { type: 'separator' },
                                                                    {
                                                                        label: 'Delete',
                                                                        icon: <Trash2 />,
                                                                        danger: true,
                                                                        onSelect: () => remove(row.key),
                                                                    },
                                                                ]}
                                                            />
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                    {!can.reveal && (current?.keys.length ?? 0) > 0 && (
                        <p className="text-fg-faint flex items-center gap-1.5 text-xs">
                            <Lock className="size-3.5" aria-hidden /> Values stay hidden: revealing needs the “Reveal site environment variables”
                            permission.
                        </p>
                    )}
                    <ChangesBar
                        count={count}
                        message="changes apply on next deploy"
                        applyLabel="Deploy"
                        processing={applying === 'deploy'}
                        onApply={() => void apply('deploy')}
                        onDiscard={() => {
                            setDraft(EMPTY);
                            setEditing(null);
                        }}
                        extra={
                            <Button size="sm" loading={applying === 'save'} disabled={applying !== null} onClick={() => void apply('save')}>
                                Save
                            </Button>
                        }
                    />
                </>
            )}

            <HistoryDialog
                open={history}
                onOpenChange={setHistory}
                state={data}
                siteId={siteId}
                onRestored={async () => {
                    setDraft(EMPTY);
                    await reload();
                    ctx.refresh();
                }}
            />
        </div>
    );
}

/** Raw dotenv editor: reveal (audited) → edit → review the line diff → save as a new version. */
function RawEditor({
    siteId,
    state,
    reveal,
    revealed,
    targets,
    selfHandle,
    onSaved,
}: {
    siteId: string;
    state: EnvironmentState;
    reveal: () => Promise<Record<string, string> | null>;
    revealed: RevealedEnvironment | null;
    targets: ReferenceTarget[] | null;
    selfHandle: string;
    onSaved: (then: 'save' | 'deploy') => Promise<void>;
}) {
    const [content, setContent] = useState<string | null>(null);
    const [reviewing, setReviewing] = useState(false);
    const [saving, setSaving] = useState<'save' | 'deploy' | null>(null);
    const [error, setError] = useState<string | null>(null);
    const can = state.can;

    useEffect(() => {
        if (revealed && content === null) setContent(revealed.content);
    }, [revealed, content]);

    if (!can.reveal) {
        return (
            <EmptyState
                size="sm"
                icon={<Lock />}
                title="The raw editor shows every value"
                description="It needs the “Reveal site environment variables” permission."
            />
        );
    }

    if (!revealed || content === null) {
        return (
            <EmptyState
                size="sm"
                icon={<Eye />}
                title="Reveal to edit as a file"
                description="Values are decrypted for you only; the reveal is recorded in the audit log."
                action={
                    <Button size="sm" variant="primary" icon={<Eye />} onClick={() => void reveal()}>
                        Reveal & edit
                    </Button>
                }
            />
        );
    }

    const dirty = content !== revealed.content;
    const diff = reviewing ? lineDiff(revealed.content, content) : [];
    const problems = Object.entries(parseDotenv(content)).flatMap(([key, value]) =>
        unresolved(value, targets, { handle: selfHandle, keys: dotenvKeys(content) }).map((problem) => `${key}: ${problem}`),
    );

    const save = async (then: 'save' | 'deploy') => {
        setSaving(then);
        setError(null);
        try {
            const keys = dotenvKeys(content);
            await requestJson(environmentUrl(siteId), 'PUT', {
                content,
                exposed: revealed.exposed.filter((key) => keys.includes(key)),
                base_version: revealed.version,
            });
            toast.success(`Saved as version ${revealed.version + 1}`, then === 'deploy' ? 'Deploying…' : 'Changes apply on the next deploy.');
            setReviewing(false);
            setContent(null);
            await onSaved(then);
        } catch (e) {
            setError(e instanceof HttpError ? (e.errors.content ?? e.message) : errorMessage(e));
            setReviewing(false);
        } finally {
            setSaving(null);
        }
    };

    const managedKeys = (state.config?.paths ?? [])
        .filter((path) => path.startsWith('/env/'))
        .map((path) => path.slice(5).replace(/~1/g, '/').replace(/~0/g, '~'));

    return (
        <div className="grid gap-3">
            {managedKeys.length > 0 && (
                <Callout tone="info" title={state.config?.badge}>
                    {managedKeys.join(', ')} {managedKeys.length === 1 ? 'is' : 'are'} set by {state.config?.file ?? 'falak.yaml'}: changing or
                    removing {managedKeys.length === 1 ? 'it' : 'them'} here is refused.
                </Callout>
            )}
            {reviewing ? (
                <div className="border-border bg-surface-1 overflow-hidden rounded-lg border" aria-label="Changes to save">
                    <div className="border-border text-fg-muted flex items-center justify-between border-b px-3 py-2 text-xs">
                        <span>
                            Review changes · <span className="text-success">+{diff.filter((line) => line.type === 'add').length}</span>{' '}
                            <span className="text-danger">−{diff.filter((line) => line.type === 'remove').length}</span>
                        </span>
                        <span className="text-fg-faint">
                            v{revealed.version} → v{revealed.version + 1}
                        </span>
                    </div>
                    <pre className="max-h-[min(520px,calc(100vh-22rem))] overflow-auto py-1 font-mono text-xs leading-5">
                        {diff.map((line, index) => (
                            <div
                                key={index}
                                className={cn(
                                    'flex gap-3 px-3',
                                    line.type === 'add' && 'bg-success-soft text-fg',
                                    line.type === 'remove' && 'bg-danger-soft text-fg-muted line-through decoration-transparent',
                                    line.type === 'same' && 'text-fg-muted',
                                )}
                            >
                                <span className="text-fg-faint w-6 shrink-0 text-right select-none">{line.oldNo ?? ''}</span>
                                <span className="text-fg-faint w-6 shrink-0 text-right select-none">{line.newNo ?? ''}</span>
                                <span className="w-3 shrink-0 select-none">{line.type === 'add' ? '+' : line.type === 'remove' ? '−' : ' '}</span>
                                <span className="break-all whitespace-pre-wrap">{line.text || ' '}</span>
                            </div>
                        ))}
                    </pre>
                </div>
            ) : (
                <Textarea
                    mono
                    value={content}
                    onChange={(event) => setContent(event.target.value)}
                    onKeyDown={(event) => event.stopPropagation()}
                    readOnly={!can.update}
                    spellCheck={false}
                    autoComplete="off"
                    aria-label="Environment file"
                    aria-invalid={error ? true : undefined}
                    rows={Math.min(28, Math.max(10, content.split('\n').length + 2))}
                    className="leading-5"
                />
            )}
            {error && <p className="text-danger text-xs">{error}</p>}
            <Unresolved problems={problems} />
            {can.update && (
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="text-fg-faint text-xs">
                        One <code className="font-mono">KEY=value</code> per line · <code className="font-mono">{'${{ service.KEY }}'}</code>{' '}
                        references · changes apply on next deploy
                    </span>
                    <div className="flex items-center gap-2">
                        {reviewing ? (
                            <>
                                <Button size="sm" variant="ghost" onClick={() => setReviewing(false)} disabled={saving !== null}>
                                    Back to editor
                                </Button>
                                <Button size="sm" loading={saving === 'save'} disabled={saving !== null} onClick={() => void save('save')}>
                                    Save
                                </Button>
                                <Button
                                    size="sm"
                                    variant="primary"
                                    loading={saving === 'deploy'}
                                    disabled={saving !== null}
                                    onClick={() => void save('deploy')}
                                >
                                    Save & deploy
                                </Button>
                            </>
                        ) : (
                            <>
                                <Button size="sm" variant="ghost" icon={<RotateCcw />} disabled={!dirty} onClick={() => setContent(revealed.content)}>
                                    Reset
                                </Button>
                                <Button size="sm" variant="primary" disabled={!dirty} onClick={() => setReviewing(true)}>
                                    Review changes
                                </Button>
                            </>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}

function HistoryDialog({
    open,
    onOpenChange,
    state,
    siteId,
    onRestored,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    state: EnvironmentState;
    siteId: string;
    onRestored: () => Promise<void>;
}) {
    const [restoring, setRestoring] = useState<number | null>(null);
    const [confirming, setConfirming] = useState<number | null>(null);

    const restore = async (version: number) => {
        setRestoring(version);
        try {
            await requestJson(`${environmentUrl(siteId)}/versions/${version}/restore`, 'POST', {});
            toast.success(`Restored version ${version}`, 'Saved as a new version — deploy to apply.');
            setConfirming(null);
            onOpenChange(false);
            await onRestored();
        } catch (e) {
            toast.error('Could not restore', errorMessage(e));
        } finally {
            setRestoring(null);
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={onOpenChange}
            size="lg"
            title="Variable history"
            description="Every save is a version; deployments pin the version they were built with. Restoring saves a copy as the newest version."
        >
            {state.versions.length === 0 ? (
                <p className="text-fg-muted text-sm">No versions yet.</p>
            ) : (
                <ol className="divide-border grid divide-y">
                    {state.versions.map((version) => {
                        const isCurrent = version.version === state.current?.version;

                        return (
                            <li key={version.version} className="flex flex-wrap items-start gap-3 py-2.5">
                                <div className="grid min-w-0 flex-1 gap-1">
                                    <div className="flex items-center gap-2 text-sm">
                                        <span className="text-fg font-medium">v{version.version}</span>
                                        {isCurrent && <Tag tone="success">current</Tag>}
                                        <span className="text-fg-faint text-xs">
                                            <RelativeTime value={version.created_at} />
                                            {version.created_by && ` · ${version.created_by}`}
                                        </span>
                                    </div>
                                    {version.changed_keys.length > 0 && (
                                        <p className="text-fg-muted font-mono text-[11px] break-all">{version.changed_keys.join(', ')}</p>
                                    )}
                                </div>
                                {state.can.update &&
                                    !isCurrent &&
                                    (confirming === version.version ? (
                                        <div className="flex items-center gap-1">
                                            <Button size="sm" variant="ghost" onClick={() => setConfirming(null)}>
                                                Cancel
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="primary"
                                                loading={restoring === version.version}
                                                onClick={() => void restore(version.version)}
                                            >
                                                Restore v{version.version}
                                            </Button>
                                        </div>
                                    ) : (
                                        <Button size="sm" icon={<RotateCcw />} onClick={() => setConfirming(version.version)}>
                                            Restore
                                        </Button>
                                    ))}
                            </li>
                        );
                    })}
                </ol>
            )}
        </Dialog>
    );
}
