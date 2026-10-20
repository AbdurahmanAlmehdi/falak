import {
    Button,
    ConfirmDestructive,
    DataTable,
    Dialog,
    Field,
    Input,
    RelativeTime,
    Section,
    Segmented,
    Tag,
    toast,
    type DataTableColumn,
} from '@/components/falak';
import SettingsLayout from '@/layouts/settings/layout';
import { errorMessage, requestJson } from '@/lib/http';
import { router } from '@inertiajs/react';
import { Download, LayoutTemplate, Pencil, Plus, Rocket, Trash2, Upload } from 'lucide-react';
import { useEffect, useState } from 'react';
import { TemplateEditor, problemsOf, type TemplateFiles } from '../components/template-editor';
import { TemplateIconTile } from '../components/template-icon';
import { type Category, type CustomTemplateDetail, type CustomTemplateRow } from '../types';

interface Props {
    templates: CustomTemplateRow[];
    categories: Category[];
    limits: { max_kb: number };
    can: { manage: boolean };
    /** The curated catalog this panel serves: synced from falak-sh/templates, or the copy baked into the image. */
    catalog: { version: string | null; source: 'remote' | 'built-in'; synced_at: string | null };
}

type ImportMode = 'paste' | 'upload' | 'url';

/** Tall dialogs: header, scrolling body, footer always visible. */
const TALL = 'grid-rows-[auto_minmax(0,1fr)_auto]';

const EMPTY: TemplateFiles = { template_yaml: '', compose_yaml: '' };

function reload() {
    router.reload({ only: ['templates'] });
}

function ImportDialog({ open, onOpenChange, maxKb }: { open: boolean; onOpenChange: (open: boolean) => void; maxKb: number }) {
    const [mode, setMode] = useState<ImportMode>('paste');
    const [files, setFiles] = useState<TemplateFiles>(EMPTY);
    const [problems, setProblems] = useState<string[]>([]);
    const [url, setUrl] = useState('');
    const [urlError, setUrlError] = useState<string | null>(null);
    const [fetching, setFetching] = useState(false);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (!open) {
            setMode('paste');
            setFiles(EMPTY);
            setProblems([]);
            setUrl('');
            setUrlError(null);
        }
    }, [open]);

    const readFiles = async (list: FileList | null) => {
        if (!list || list.length === 0) return;
        const next = { ...files };
        for (const file of Array.from(list)) {
            if (file.size > maxKb * 1024) {
                setProblems([`${file.name} is larger than ${maxKb} KB.`]);

                return;
            }
            const text = await file.text();
            if (/^compose\.ya?ml$|docker-compose/i.test(file.name) || (/^\s*services:/m.test(text) && !/^\s*slug:/m.test(text)))
                next.compose_yaml = text;
            else next.template_yaml = text;
        }
        setFiles(next);
        setProblems([]);
        setMode('paste');
    };

    const fetchUrl = async () => {
        setFetching(true);
        setUrlError(null);
        try {
            const response = await requestJson<{ data: { template_yaml: string; compose_yaml: string | null } }>(
                '/settings/templates/fetch',
                'POST',
                { url },
            );
            setFiles({ template_yaml: response.data.template_yaml, compose_yaml: response.data.compose_yaml ?? '' });
            setProblems([]);
            setMode('paste');
        } catch (error) {
            setUrlError(errorMessage(error));
        } finally {
            setFetching(false);
        }
    };

    const save = async () => {
        setSaving(true);
        try {
            const response = await requestJson<{ data: CustomTemplateRow }>('/settings/templates', 'POST', files);
            toast.success(`${response.data.name} imported`);
            onOpenChange(false);
            reload();
        } catch (error) {
            setProblems(await problemsOf(error));
        } finally {
            setSaving(false);
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={onOpenChange}
            size="lg"
            className={TALL}
            title="Import template"
            description="Same format as the catalog: template.yaml + compose.yaml, validated before it is saved."
            footer={
                <>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button variant="primary" onClick={() => void save()} loading={saving} disabled={!files.template_yaml.trim()}>
                        Import
                    </Button>
                </>
            }
        >
            <div className="grid gap-4">
                <Segmented<ImportMode>
                    className="w-fit"
                    label="Import from"
                    value={mode}
                    onValueChange={setMode}
                    options={[
                        { value: 'paste', label: 'Paste' },
                        { value: 'upload', label: 'Upload' },
                        { value: 'url', label: 'URL' },
                    ]}
                />
                {mode === 'upload' && (
                    <Field label="Files" hint={`template.yaml and compose.yaml (or one bundle file), up to ${maxKb} KB each.`}>
                        <label className="border-border hover:bg-surface-2 text-fg-muted flex cursor-pointer flex-col items-center gap-2 rounded-lg border border-dashed px-4 py-8 text-sm">
                            <Upload className="size-5" aria-hidden />
                            Choose YAML files
                            <input
                                type="file"
                                accept=".yaml,.yml,application/yaml,text/yaml"
                                multiple
                                className="sr-only"
                                onChange={(event) => void readFiles(event.target.files)}
                            />
                        </label>
                    </Field>
                )}
                {mode === 'url' && (
                    <Field
                        label="URL"
                        hint="An https URL of a bundle, or of a template.yaml with compose.yaml next to it (e.g. a raw GitHub file). Fetched by Falak; private addresses are refused."
                        error={urlError}
                    >
                        <div className="flex gap-2">
                            <Input
                                value={url}
                                onChange={(event) => setUrl(event.target.value)}
                                placeholder="https://raw.githubusercontent.com/acme/templates/main/app/template.yaml"
                                mono
                                className="min-w-0 flex-1"
                            />
                            <Button onClick={() => void fetchUrl()} loading={fetching} disabled={!url.startsWith('https://')} icon={<Download />}>
                                Fetch
                            </Button>
                        </div>
                    </Field>
                )}
                {mode === 'paste' && <TemplateEditor files={files} onChange={setFiles} problems={problems} onProblems={setProblems} />}
            </div>
        </Dialog>
    );
}

function EditDialog({ row, onOpenChange }: { row: CustomTemplateRow; onOpenChange: (open: boolean) => void }) {
    const [detail, setDetail] = useState<CustomTemplateDetail | null>(null);
    const [files, setFiles] = useState<TemplateFiles>(EMPTY);
    const [problems, setProblems] = useState<string[]>([]);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        requestJson<{ data: CustomTemplateDetail }>(`/settings/templates/${row.id}`)
            .then((response) => {
                setDetail(response.data);
                setFiles({ template_yaml: response.data.template_yaml, compose_yaml: response.data.compose_yaml });
            })
            .catch((error: unknown) => setProblems([errorMessage(error)]));
    }, [row.id]);

    const save = async () => {
        setSaving(true);
        try {
            await requestJson(`/settings/templates/${row.id}`, 'PUT', files);
            toast.success(`${row.name} saved`, 'Sites created from earlier versions keep running as they are.');
            onOpenChange(false);
            reload();
        } catch (error) {
            setProblems(await problemsOf(error));
        } finally {
            setSaving(false);
        }
    };

    return (
        <Dialog
            open
            onOpenChange={onOpenChange}
            size="lg"
            className={TALL}
            title={`Edit ${row.name}`}
            description="Saving creates a new revision. Bump version: when the compose file changes."
            footer={
                <>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button variant="primary" onClick={() => void save()} loading={saving} disabled={!detail}>
                        Save revision
                    </Button>
                </>
            }
        >
            <div className="grid gap-4">
                <TemplateEditor files={files} onChange={setFiles} problems={problems} onProblems={setProblems} templateId={row.id} />
                {detail && detail.revisions.length > 0 && (
                    <div className="grid gap-1.5">
                        <span className="text-fg text-xs font-medium">History</span>
                        <ul className="border-border divide-border divide-y rounded-md border text-xs">
                            {detail.revisions.map((revision) => (
                                <li key={revision.revision} className="flex items-center gap-2 px-2.5 py-1.5">
                                    <span className="text-fg-muted font-mono">rev {revision.revision}</span>
                                    <Tag mono>v{revision.version}</Tag>
                                    <span className="text-fg-faint ml-auto">
                                        <RelativeTime value={revision.created_at} />
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>
        </Dialog>
    );
}

/** Settings → Templates: the organization's own templates (docs/COMPOSE_TEMPLATES.md §3). */
export default function Settings({ templates, limits, can, catalog }: Props) {
    const [importing, setImporting] = useState(false);
    const [editing, setEditing] = useState<CustomTemplateRow | null>(null);
    const [deleting, setDeleting] = useState<CustomTemplateRow | null>(null);
    const [deleteError, setDeleteError] = useState<string | undefined>();

    useEffect(() => {
        if (can.manage && new URLSearchParams(window.location.search).get('import') === '1') setImporting(true);
    }, [can.manage]);

    const columns: DataTableColumn<CustomTemplateRow>[] = [
        {
            id: 'name',
            header: 'Template',
            sortValue: (row) => row.name.toLowerCase(),
            cell: (row) => (
                <span className="flex min-w-0 items-center gap-2.5">
                    <TemplateIconTile icon={row.summary?.icon ?? null} name={row.name} size="sm" />
                    <span className="grid min-w-0">
                        <span className="text-fg truncate font-medium">{row.name}</span>
                        <span className="text-fg-faint truncate text-xs">{row.description}</span>
                    </span>
                </span>
            ),
        },
        {
            id: 'slug',
            header: 'Slug',
            hideOnMobile: true,
            cell: (row) => <span className="text-fg-muted font-mono text-xs whitespace-nowrap">{row.slug}</span>,
        },
        {
            id: 'version',
            header: 'Version',
            cell: (row) => (
                <span className="flex items-center gap-1.5 whitespace-nowrap">
                    <Tag mono>v{row.version}</Tag>
                    <span className="text-fg-faint text-xs">rev {row.revision}</span>
                </span>
            ),
        },
        {
            id: 'services',
            header: 'Services',
            hideOnMobile: true,
            cell: (row) => (
                <span className="text-fg-muted text-xs whitespace-nowrap">
                    {row.summary?.services.map((service) => service.name).join(', ') ?? '—'}
                </span>
            ),
        },
        {
            id: 'updated',
            header: 'Updated',
            align: 'right',
            hideOnMobile: true,
            sortValue: (row) => row.updated_at,
            cell: (row) => (
                <span className="text-fg-faint text-xs whitespace-nowrap">
                    <RelativeTime value={row.updated_at} />
                </span>
            ),
        },
    ];

    return (
        <SettingsLayout
            title="Templates"
            description="Your organization's one-click apps, next to the curated catalog in the Create picker and on /templates."
            wide
            actions={
                can.manage && (
                    <Button variant="primary" icon={<Plus />} onClick={() => setImporting(true)}>
                        Import template
                    </Button>
                )
            }
        >
            <p className="text-fg-muted mb-4 text-sm">
                {catalog.source === 'remote' ? (
                    <>
                        Catalog {catalog.version} · updated <RelativeTime value={catalog.synced_at} />
                    </>
                ) : (
                    `Built-in catalog${catalog.version ? ` ${catalog.version}` : ''}`
                )}
            </p>
            <Section
                title="Organization templates"
                description="Same format as the catalog (template.yaml + compose.yaml). Create one from a running compose service with ⋯ → Save as template."
                bare
            >
                <DataTable
                    label="Organization templates"
                    columns={columns}
                    rows={templates}
                    rowKey={(row) => row.id}
                    defaultSort={{ column: 'name', direction: 'asc' }}
                    rowActions={(row) => [
                        { label: 'Deploy…', icon: <Rocket />, href: '/templates' },
                        ...(can.manage
                            ? [
                                  { label: 'Edit', icon: <Pencil />, onSelect: () => setEditing(row) },
                                  { type: 'separator' as const },
                                  { label: 'Delete', icon: <Trash2 />, danger: true, onSelect: () => setDeleting(row) },
                              ]
                            : []),
                    ]}
                    empty={{
                        icon: <LayoutTemplate />,
                        title: 'No organization templates yet',
                        description:
                            'Import a template (paste, upload or URL) or save a running compose service as one. Teammates can then deploy it in one click.',
                        action: can.manage ? (
                            <Button variant="primary" icon={<Plus />} onClick={() => setImporting(true)}>
                                Import template
                            </Button>
                        ) : undefined,
                    }}
                />
            </Section>

            {can.manage && <ImportDialog open={importing} onOpenChange={setImporting} maxKb={limits.max_kb} />}
            {editing && <EditDialog row={editing} onOpenChange={(open) => !open && setEditing(null)} />}
            {deleting && (
                <ConfirmDestructive
                    open
                    onOpenChange={(open) => {
                        if (!open) {
                            setDeleting(null);
                            setDeleteError(undefined);
                        }
                    }}
                    title={`Delete ${deleting.name}?`}
                    description="Sites already created from it keep running; the template and its history are removed."
                    confirmText={deleting.name}
                    error={deleteError}
                    onConfirm={async (confirm) => {
                        try {
                            await requestJson(`/settings/templates/${deleting.id}`, 'DELETE', { confirm });
                            toast.success(`${deleting.name} deleted`);
                            setDeleting(null);
                            reload();
                        } catch (error) {
                            setDeleteError(errorMessage(error));
                        }
                    }}
                />
            )}
        </SettingsLayout>
    );
}
