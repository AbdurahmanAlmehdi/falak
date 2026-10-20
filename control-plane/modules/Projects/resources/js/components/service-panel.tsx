import {
    Button,
    IconPicker,
    Menu,
    PanelHeader,
    ServiceIcon,
    serviceIconKey,
    Skeleton,
    SkeletonRows,
    StatusBadge,
    Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
    Tag,
    toast,
    useIconsUrl,
    type MenuAction,
} from '@/components/falak';
import { errorMessage, requestJson } from '@/lib/http';
import { serviceActionsFor, serviceTabsFor, type ServiceAction, type ServicePanelContext } from '@/lib/registry';
import { cn } from '@/lib/utils';
import { type CanvasService } from '@/types';
import { Pencil } from 'lucide-react';
import { Suspense, useEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react';

interface ServicePanelProps {
    base: Omit<ServicePanelContext, 'service' | 'tab' | 'baseUrl'>;
    service: CanvasService | null;
    kind: 'site' | 'database';
    refId: string;
    tab: string | null;
    renameUrl: string | null;
    onRenamed: (name: string) => void;
    /** A new icon was picked (null: back to the derived one). */
    onIconChanged: (icon: string | null) => void;
    onClose: () => void;
}

/** The header icon; with `url` (the user may manage the canvas) a click opens the icon picker. Canvas-only. */
function HeaderIcon({ service, url, onChanged }: { service: CanvasService; url: string | null; onChanged: (icon: string | null) => void }) {
    const iconsUrl = useIconsUrl();
    const icon = <ServiceIcon name={serviceIconKey(service)} size={22} />;
    if (!iconsUrl || !url) return icon;

    const pick = async (next: string | null) => {
        try {
            const body = await requestJson<{ data: { icon: string | null } }>(url, 'PATCH', { icon: next });
            onChanged(body.data.icon);
        } catch (error) {
            toast.error('Could not change the icon', errorMessage(error));
        }
    };

    return (
        <IconPicker value={service.custom_icon} onPick={(next) => void pick(next)} label={`Icon of ${service.name}`}>
            <button
                type="button"
                title="Change icon"
                aria-label={`Change the icon of ${service.name}`}
                className="hover:bg-surface-2 -m-1 rounded-md p-1"
            >
                {icon}
            </button>
        </IconPicker>
    );
}

/** Click-to-edit service name (canvas service name = the handle `${{ name.KEY }}` references use). */
function InlineName({ name, url, onRenamed }: { name: string; url: string | null; onRenamed: (name: string) => void }) {
    const [editing, setEditing] = useState(false);
    const [value, setValue] = useState(name);
    const [saving, setSaving] = useState(false);
    const input = useRef<HTMLInputElement>(null);

    useEffect(() => setValue(name), [name]);
    useEffect(() => {
        if (editing) input.current?.select();
    }, [editing]);

    if (!url || !editing) {
        return (
            <button
                type="button"
                disabled={!url}
                onClick={() => setEditing(true)}
                className={cn('group flex min-w-0 items-center gap-1.5 rounded-sm text-left', url && 'hover:text-fg-muted')}
                aria-label={url ? `Rename ${name}` : undefined}
            >
                <span className="truncate">{name}</span>
                {url && (
                    <Pencil
                        className="text-fg-faint size-3 opacity-0 transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100"
                        aria-hidden
                    />
                )}
            </button>
        );
    }

    const save = async () => {
        const next = value.trim();
        if (!next || next === name) {
            setEditing(false);
            setValue(name);

            return;
        }
        setSaving(true);
        try {
            const body = await requestJson<{ data: { name: string } }>(url, 'PATCH', { name: next });
            onRenamed(body.data.name);
            toast.success('Service renamed', 'Update `${{ … }}` references that used the old name.');
            setEditing(false);
        } catch (error) {
            toast.error('Could not rename', errorMessage(error));
        } finally {
            setSaving(false);
        }
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        event.stopPropagation();
        if (event.key === 'Enter') void save();
        if (event.key === 'Escape') {
            event.preventDefault();
            setValue(name);
            setEditing(false);
        }
    };

    return (
        <input
            ref={input}
            value={value}
            disabled={saving}
            aria-label="Service name"
            onChange={(event) => setValue(event.target.value)}
            onKeyDown={onKeyDown}
            onBlur={() => void save()}
            className="bg-surface-2 border-border-strong text-fg -my-1 h-9 w-64 rounded-md border px-2 text-xl font-semibold tracking-tight outline-none"
        />
    );
}

function HeaderActions({ ctx }: { ctx: ServicePanelContext }) {
    const actions = serviceActionsFor(ctx);
    const primary = actions.find((action) => action.primary);
    const rest = actions.filter((action) => action !== primary);
    const [dialog, setDialog] = useState<ServiceAction | null>(null);
    const [running, setRunning] = useState<string | null>(null);

    const run = async (action: ServiceAction) => {
        if (action.dialog) {
            setDialog(action);

            return;
        }
        setRunning(action.id);
        try {
            await action.perform?.(ctx);
        } catch (error) {
            toast.error(`${action.label} failed`, errorMessage(error));
        } finally {
            setRunning(null);
        }
    };

    const menu: MenuAction[] = rest.flatMap((action) => [
        ...(action.separated ? [{ type: 'separator' as const }] : []),
        {
            label: action.label,
            icon: action.icon ? <action.icon /> : undefined,
            danger: action.danger,
            disabled: running === action.id,
            onSelect: () => void run(action),
        },
    ]);
    const Dialog = dialog?.dialog;

    return (
        <>
            {primary && (
                <Button
                    variant="primary"
                    size="sm"
                    icon={primary.icon ? <primary.icon /> : undefined}
                    loading={running === primary.id}
                    onClick={() => void run(primary)}
                >
                    {primary.label}
                </Button>
            )}
            {menu.length > 0 && <Menu actions={menu} label="Service actions" />}
            {Dialog && (
                <Suspense fallback={null}>
                    <Dialog ctx={ctx} open onOpenChange={(open) => !open && setDialog(null)} />
                </Suspense>
            )}
        </>
    );
}

/**
 * §5 service panel content (a layer of the canvas PanelStack): header (big icon + name with inline rename, status,
 * primary action, `⋯`, ✕), underline tabs with a sliding indicator, and the active tab — each tab loads its own data from
 * the owning module. `[` / `]` switch tabs while this panel is on top.
 */
export function ServicePanel({ base, service, kind, refId, tab, renameUrl, onRenamed, onIconChanged, onClose }: ServicePanelProps) {
    const tabs = useMemo(() => serviceTabsFor(kind, base, service), [kind, base, service]);
    const active = tabs.find((item) => item.id === tab)?.id ?? tabs[0]?.id ?? '';
    const baseUrl = `${base.canvasUrl}/service/${kind}/${refId}`;
    const root = useRef<HTMLDivElement>(null);

    const ctx = useMemo<ServicePanelContext | null>(
        () => (service ? { ...base, service, tab: active, baseUrl, item: active === tab ? base.item : null } : null),
        [base, service, active, baseUrl, tab],
    );

    useEffect(() => {
        if (tabs.length < 2) return;
        const onKeyDown = (event: globalThis.KeyboardEvent) => {
            if (event.key !== '[' && event.key !== ']') return;
            if (root.current?.closest<HTMLElement>('[data-falak-panel]')?.dataset.depth !== '0') return;
            const target = event.target as HTMLElement | null;
            if (target && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))) return;
            const index = tabs.findIndex((item) => item.id === active);
            event.preventDefault();
            base.open(tabs[(index + (event.key === ']' ? 1 : -1) + tabs.length) % tabs.length].id);
        };
        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [tabs, active, base]);

    return (
        <div ref={root} className="flex min-h-0 flex-1 flex-col" data-testid="service-panel">
            <PanelHeader
                icon={service ? <HeaderIcon service={service} url={renameUrl} onChanged={onIconChanged} /> : <ServiceIcon name={kind} size={22} />}
                title={service ? <InlineName name={service.name} url={renameUrl} onRenamed={onRenamed} /> : <Skeleton className="h-6 w-44" />}
                status={
                    service && (
                        <span className="flex items-center gap-1.5">
                            <StatusBadge status={service.status} label={service.status_label} />
                            {service.badges?.map((badge) => (
                                <Tag key={badge}>{badge}</Tag>
                            ))}
                        </span>
                    )
                }
                subtitle={service?.kind === 'database' ? service.subtitle : undefined}
                actions={ctx && <HeaderActions ctx={ctx} />}
                onClose={onClose}
            />
            {tabs.length > 0 && (
                <Tabs value={active} onValueChange={(next) => base.open(next)} className="flex min-h-0 flex-1 flex-col">
                    <TabsList className="gap-5 px-5 sm:gap-7 sm:px-7" aria-label={`${service?.name ?? 'Service'} sections`}>
                        {tabs.map((item) => (
                            <TabsTrigger key={item.id} value={item.id} className="h-10 px-0 text-[0.9rem]">
                                {item.title}
                            </TabsTrigger>
                        ))}
                    </TabsList>
                    {tabs.map((item) => (
                        <TabsContent key={item.id} value={item.id} className="min-h-0 flex-1 overflow-y-auto px-5 py-6 sm:px-7">
                            {item.id === active &&
                                (ctx ? (
                                    <Suspense fallback={<SkeletonRows rows={6} />}>
                                        <item.component key={`${refId}:${item.id}`} ctx={ctx} />
                                    </Suspense>
                                ) : (
                                    <Skeleton className="h-40" />
                                ))}
                        </TabsContent>
                    ))}
                </Tabs>
            )}
        </div>
    );
}
