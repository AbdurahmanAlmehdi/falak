import { Button, Callout, ManagedBadge, Section, SkeletonRows, Tag, Tooltip, type ManagedConfig } from '@/components/falak';
import { useJson } from '@/hooks/use-json';
import { type ServiceTabProps } from '@/lib/registry';
import { cn } from '@/lib/utils';
import { RotateCcw } from 'lucide-react';
import { useEffect, useRef, useState, type KeyboardEvent } from 'react';
import { useSave } from './data';

interface Item {
    name: string;
    description: string;
}

/** GET /sites/{site}/deploy-script (JSON). */
interface DeployScriptData {
    script: string;
    defaultScript: string;
    macros: Item[];
    variables: Item[];
    exposedEnvironment: string[];
    can: { update: boolean };
    /** Hooks the project's falak.yaml sets (/deploy/hooks/<section>), or null. */
    config: ManagedConfig | null;
}

/** Deploy script editor with macro / variable hints (inserted at the cursor). ⌘S saves. */
export function DeployScriptSettings({ ctx }: ServiceTabProps) {
    const url = `/sites/${ctx.service.ref_id}/deploy-script`;
    const { data, error, reload } = useJson<DeployScriptData>(url);
    const [script, setScript] = useState(data?.script ?? '');
    const editor = useRef<HTMLTextAreaElement>(null);
    const gutter = useRef<HTMLDivElement>(null);
    const { saving, errors, save } = useSave(reload);

    useEffect(() => setScript(data?.script ?? ''), [data?.script]);

    if (!data) return error ? <Callout tone="danger">{error}</Callout> : <SkeletonRows rows={6} />;

    const dirty = script !== data.script;
    const lines = script.split('\n').length;
    const missing = ['FALAK_FETCH', 'FALAK_ACTIVATE'].filter((macro) => !new RegExp(`\\$\\{?${macro}\\b`).test(script));
    const hooks = (data.config?.paths ?? []).filter((path) => path.startsWith('/deploy/hooks/')).map((path) => path.slice('/deploy/hooks/'.length));
    const submit = () => dirty && void save('PUT', url, { script }, 'Deploy script saved — used by the next deploy');

    const insert = (text: string) => {
        const element = editor.current;
        if (!element || !data.can.update) return;
        const { selectionStart, selectionEnd, value } = element;
        setScript(value.slice(0, selectionStart) + text + value.slice(selectionEnd));
        window.requestAnimationFrame(() => {
            element.focus();
            element.setSelectionRange(selectionStart + text.length, selectionStart + text.length);
        });
    };

    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        event.stopPropagation();
        if (event.key === 'Tab') {
            event.preventDefault();
            insert('    ');
        }
        if ((event.metaKey || event.ctrlKey) && event.key === 's') {
            event.preventDefault();
            submit();
        }
    };

    const hint = (item: Item, macro: boolean) => (
        <Tooltip key={item.name} content={item.description}>
            <button
                type="button"
                disabled={!data.can.update}
                onClick={() => insert(macro ? `$${item.name}\n` : `$${item.name}`)}
                className={cn(
                    'rounded-sm border px-1.5 py-0.5 font-mono text-[11px] transition-colors',
                    macro
                        ? 'border-primary/30 bg-primary-soft text-primary-on-soft hover:border-primary'
                        : 'border-border bg-surface-2 text-fg-muted hover:text-fg',
                )}
            >
                ${item.name}
            </button>
        </Tooltip>
    );

    return (
        <Section
            title="Deploy script"
            description="Bash, run on every server as the site user. Macros expand into the deployment phases."
            aside={
                hooks.length > 0 ? (
                    <ManagedBadge config={data.config} />
                ) : (
                    data.can.update && (
                        <Button
                            size="sm"
                            variant="ghost"
                            icon={<RotateCcw />}
                            disabled={script === data.defaultScript}
                            onClick={() => setScript(data.defaultScript)}
                        >
                            Preset default
                        </Button>
                    )
                )
            }
            footer={
                data.can.update && (
                    <>
                        <span className="text-fg-faint mr-auto text-xs">⌘S to save · applies on the next deploy</span>
                        <Button variant="ghost" disabled={!dirty || saving} onClick={() => setScript(data.script)}>
                            Reset
                        </Button>
                        <Button variant="primary" loading={saving} disabled={!dirty} onClick={submit}>
                            Save
                        </Button>
                    </>
                )
            }
        >
            <div className="grid gap-1.5">
                <span className="text-fg-faint text-[11px] font-medium tracking-wide uppercase">Macros</span>
                <div className="flex flex-wrap gap-1.5">{data.macros.map((item) => hint(item, true))}</div>
            </div>
            <div className="border-border bg-bg flex max-h-[28rem] overflow-hidden rounded-md border font-mono text-xs leading-5">
                <div ref={gutter} aria-hidden className="border-border text-fg-faint overflow-hidden border-r px-2 py-2 text-right select-none">
                    {Array.from({ length: lines }, (_, index) => (
                        <div key={index}>{index + 1}</div>
                    ))}
                </div>
                <textarea
                    ref={editor}
                    value={script}
                    onChange={(event) => setScript(event.target.value)}
                    onKeyDown={onKeyDown}
                    onScroll={(event) => gutter.current && (gutter.current.scrollTop = event.currentTarget.scrollTop)}
                    readOnly={!data.can.update}
                    spellCheck={false}
                    aria-label="Deploy script"
                    rows={Math.min(22, Math.max(8, lines + 1))}
                    className="text-fg min-w-0 flex-1 resize-none bg-transparent px-3 py-2 whitespace-pre outline-none"
                />
            </div>
            {errors.script && <p className="text-danger text-xs">{errors.script}</p>}
            {hooks.length > 0 && (
                <Callout tone="info">
                    {hooks.map((hook) => hook.replace('_', ' ')).join(', ')} {hooks.length === 1 ? 'comes' : 'come'} from{' '}
                    {data.config?.file ?? 'falak.yaml'} (deploy.hooks): edit the file to change {hooks.length === 1 ? 'it' : 'them'}. The other
                    sections stay editable here.
                </Callout>
            )}
            {missing.length > 0 && (
                <Callout tone="warning">
                    The script doesn’t use {missing.map((macro) => `$${macro}`).join(' or ')} — without them the release is never fetched or
                    activated.
                </Callout>
            )}
            <details className="group">
                <summary className="text-fg-muted hover:text-fg cursor-pointer text-xs select-none">
                    Variables available in the script ({data.variables.length + data.exposedEnvironment.length})
                </summary>
                <div className="mt-2 flex flex-wrap gap-1.5">
                    {data.variables.map((item) => hint(item, false))}
                    {data.exposedEnvironment.map((key) => hint({ name: key, description: 'Exposed from Variables' }, false))}
                </div>
                {data.exposedEnvironment.length === 0 && (
                    <p className="text-fg-faint mt-2 text-xs">
                        Expose variables to the script with the <Tag>Deploy script</Tag> switch in the Variables tab.
                    </p>
                )}
            </details>
        </Section>
    );
}
