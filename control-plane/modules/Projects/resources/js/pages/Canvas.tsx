import { AppShell, Button, EmptyCanvas, IconButton, PanelStack, SERVICE_CARD, toast, type PanelLayer } from '@/components/falak';
import { errorMessage, requestJson } from '@/lib/http';
import { allServiceLayers, shellContext, type ServicePanelContext } from '@/lib/registry';
import { cn } from '@/lib/utils';
import { type CanvasGroup, type CanvasService, type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { ReactFlowProvider, useReactFlow } from '@xyflow/react';
import { Activity, Grid3x3, Group as GroupIcon, Layers, Maximize, Minus, Plus, Redo2, Settings, Undo2, X } from 'lucide-react';
import { Suspense, useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { ActivityRail } from '../components/activity-rail';
import { CanvasBoard, type ComposeAction, type GroupAction, type LayoutChange } from '../components/canvas-board';
import { absoluteOf, composeGrid } from '../components/canvas-geometry';
import { ConfigDriftBanner } from '../components/config-drift-banner';
import { CreatePicker } from '../components/create-picker';
import { ServicePanel } from '../components/service-panel';
import { EMPTY_STACK, buildStack, parseStack, type ServiceRef, type StackRoute } from '../components/stack-route';
import { useLiveCanvas } from '../components/use-live-canvas';
import { CREATE_SERVICE_EVENT, canvasUrl, type ActivityItem, type CanvasPageProps } from '../types';

function readFlag(key: string, fallback: boolean): boolean {
    try {
        const value = window.localStorage.getItem(key);

        return value === null ? fallback : value === '1';
    } catch {
        return fallback;
    }
}

function writeFlag(key: string, value: boolean) {
    try {
        window.localStorage.setItem(key, value ? '1' : '0');
    } catch {
        // Not remembered.
    }
}

function ToolGroup({ children }: { children: ReactNode }) {
    return <div className="border-border bg-surface-1 shadow-panel flex flex-col items-center gap-0.5 rounded-lg border p-0.5">{children}</div>;
}

/** Left floating vertical toolbar (§4): snap to grid · zoom in / out / fit · undo / redo (layout) · overview map. */
function Toolbar({
    snap,
    onSnap,
    minimap,
    onMinimap,
    canUndo,
    canRedo,
    onUndo,
    onRedo,
}: {
    snap: boolean;
    onSnap: () => void;
    minimap: boolean;
    onMinimap: () => void;
    canUndo: boolean;
    canRedo: boolean;
    onUndo: () => void;
    onRedo: () => void;
}) {
    const flow = useReactFlow();
    const pressed = 'bg-surface-3 text-fg';

    return (
        <div className="absolute bottom-3 left-3 z-10 flex flex-col gap-2" role="toolbar" aria-label="Canvas" aria-orientation="vertical">
            <ToolGroup>
                <IconButton
                    size="sm"
                    label={snap ? 'Snap to grid: on' : 'Snap to grid: off'}
                    icon={<Grid3x3 />}
                    aria-pressed={snap}
                    onClick={onSnap}
                    className={cn(snap && pressed)}
                />
            </ToolGroup>
            <ToolGroup>
                <IconButton size="sm" label="Zoom in" shortcut="+" icon={<Plus />} onClick={() => flow.zoomIn({ duration: 180 })} />
                <IconButton size="sm" label="Zoom out" shortcut="−" icon={<Minus />} onClick={() => flow.zoomOut({ duration: 180 })} />
                <IconButton
                    size="sm"
                    label="Fit to screen"
                    shortcut="⇧1"
                    icon={<Maximize />}
                    onClick={() => void flow.fitView({ duration: 320, padding: 0.25, maxZoom: 1 })}
                />
            </ToolGroup>
            <ToolGroup>
                <IconButton size="sm" label="Undo layout change" shortcut="⌘Z" icon={<Undo2 />} disabled={!canUndo} onClick={onUndo} />
                <IconButton size="sm" label="Redo layout change" shortcut="⇧⌘Z" icon={<Redo2 />} disabled={!canRedo} onClick={onRedo} />
            </ToolGroup>
            <ToolGroup>
                <IconButton
                    size="sm"
                    label={minimap ? 'Hide overview' : 'Show overview'}
                    icon={<Layers />}
                    aria-pressed={minimap}
                    onClick={onMinimap}
                    className={cn(minimap && pressed)}
                />
            </ToolGroup>
        </div>
    );
}

/** Appears while top-level cards are multi-selected (⇧-drag / ⌘-click): put them into a new group. */
function SelectionBar({ count, onGroup, onClear }: { count: number; onGroup: () => void; onClear: () => void }) {
    return (
        <div
            className="border-border bg-surface-1 shadow-float animate-rise-in absolute bottom-4 left-1/2 z-20 flex -translate-x-1/2 items-center gap-1 rounded-xl border py-1 pr-1 pl-3"
            role="region"
            aria-label="Selection"
        >
            <span className="text-fg-muted pr-2 text-sm tabular-nums">
                {count} {count === 1 ? 'service' : 'services'} selected
            </span>
            <Button size="sm" variant="primary" icon={<GroupIcon />} onClick={onGroup}>
                Group
            </Button>
            <IconButton size="sm" label="Clear selection" icon={<X />} onClick={onClear} />
        </div>
    );
}

/** Pan so the card of the open service isn't hidden behind the panel (smooth, only when needed). */
function useRevealBehindPanel(service: CanvasService | null, groups: CanvasGroup[], open: boolean) {
    const flow = useReactFlow();
    const groupMap = useMemo(() => new Map(groups.map((group) => [group.id, group])), [groups]);

    useEffect(() => {
        if (!service || !open || window.innerWidth < 1024) return;
        const timer = window.setTimeout(() => {
            const panel = document.querySelector<HTMLElement>('[data-falak-panel][data-depth]');
            const canvas = document.querySelector<HTMLElement>('[data-testid="project-canvas"]');
            if (!panel || !canvas) return;
            const bounds = canvas.getBoundingClientRect();
            const visibleRight = window.innerWidth - 12 - panel.offsetWidth - 24;
            const at = absoluteOf(service, groupMap);
            const { x, y, zoom } = flow.getViewport();
            const left = bounds.left + at.x * zoom + x;
            const top = bounds.top + at.y * zoom + y;
            const right = left + SERVICE_CARD.width * zoom;
            const bottom = top + SERVICE_CARD.height * zoom;
            const visibleLeft = bounds.left + 72; // clear of the toolbar
            if (right <= visibleRight && left >= visibleLeft && top >= bounds.top + 16 && bottom <= bounds.bottom - 16) return;
            const targetX = visibleLeft + (visibleRight - visibleLeft) / 2 - (SERVICE_CARD.width * zoom) / 2;
            const targetY = bounds.top + bounds.height / 2 - (SERVICE_CARD.height * zoom) / 2;
            void flow.setViewport({ x: x + (targetX - left), y: y + (targetY - top), zoom }, { duration: 420 });
        }, 60);

        return () => window.clearTimeout(timer);
    }, [service?.id, open, flow, groupMap]); // eslint-disable-line react-hooks/exhaustive-deps
}

function CanvasPage({ project, environment, canvas: initial, can }: CanvasPageProps) {
    const { props, url } = usePage<SharedData>();
    const shell = useMemo(() => shellContext(props), [props]);
    const home = canvasUrl(project.id, environment.slug);
    const { canvas, setCanvas, refresh } = useLiveCanvas(`${home}/canvas`, initial);
    const layerDefs = useMemo(() => allServiceLayers(), []);
    const route = useMemo(() => parseStack(url, home, layerDefs), [url, home, layerDefs]);
    const routeRef = useRef<StackRoute>(route);
    routeRef.current = route;
    const [picker, setPicker] = useState<{
        position: { x: number; y: number } | null;
        option?: string | null;
    } | null>(null);
    const [activity, setActivity] = useState(false);
    const [snap, setSnap] = useState(() => readFlag('falak:canvas-snap', true));
    const [minimap, setMinimap] = useState(() => readFlag('falak:canvas-minimap', false));
    const [selection, setSelection] = useState<string[]>([]);
    const [selectionReset, setSelectionReset] = useState(0);
    const [renaming, setRenaming] = useState<string | null>(null);
    const [history, setHistory] = useState<{ undo: LayoutChange[][]; redo: LayoutChange[][] }>({ undo: [], redo: [] });
    const canCreate = can.create_sites || can.create_databases;
    const groups = useMemo(() => canvas.groups ?? [], [canvas.groups]);

    // The panel stack lives in the URL (deep links, back/forward). Client-side visits: no server round trip.
    const navigate = useCallback(
        (next: StackRoute) => {
            routeRef.current = next;
            router.push({ url: buildStack(next, home, layerDefs, window.location.href), preserveState: true, preserveScroll: true });
        },
        [home, layerDefs],
    );

    // Back / forward between states of this canvas: update the stack in place (no remount), so panels animate.
    useEffect(() => {
        const onPopState = (event: PopStateEvent) => {
            const page = (event.state as { page?: unknown } | null)?.page as { component?: string; url?: string } | undefined;
            if (!page || typeof page !== 'object' || page.component !== 'Projects/Canvas' || typeof page.url !== 'string') return;
            const path = new URL(page.url, window.location.origin).pathname;
            if (path !== home && !path.startsWith(`${home}/service/`)) return;
            event.stopImmediatePropagation();
            router.replace({ url: page.url, preserveState: true, preserveScroll: true });
        };
        window.addEventListener('popstate', onPopState, true);

        return () => window.removeEventListener('popstate', onPopState, true);
    }, [home]);

    const find = useCallback(
        (ref: Pick<ServiceRef, 'kind' | 'id'> | null) =>
            ref ? (canvas.services.find((service) => service.kind === ref.kind && service.ref_id === ref.id) ?? null) : null,
        [canvas.services],
    );
    const baseService = find(route.base);
    const peekService = find(route.peek);
    const topService = peekService ?? baseService;

    const openService = useCallback(
        (service: Pick<CanvasService, 'kind' | 'ref_id'>, tab: string | null = null, item: string | null = null) => {
            // A record a stacked layer claims (e.g. a deployment of the Deployments tab) opens that layer on top.
            const claimed = item ? layerDefs.find((layer) => layer.fromTab === tab) : undefined;
            navigate({
                ...EMPTY_STACK,
                base: { kind: service.kind, id: service.ref_id, tab, item: claimed ? null : item },
                layer: claimed && item ? { id: claimed.id, record: item.toLowerCase(), tab: null } : null,
            });
        },
        [navigate, layerDefs],
    );

    // ⌘K → Create (registered by this module) and the empty state open the picker; `detail.option` opens a
    // registered create option directly (⌘K → Deploy template…).
    useEffect(() => {
        const open = (event: Event) =>
            canCreate && setPicker({ position: null, option: (event as CustomEvent<{ option?: string } | null>).detail?.option ?? null });
        window.addEventListener(CREATE_SERVICE_EVENT, open);

        return () => window.removeEventListener(CREATE_SERVICE_EVENT, open);
    }, [canCreate]);

    /** Apply layout changes locally (optimistic) and persist them; `record` puts them on the undo stack. */
    const apply = useCallback(
        async (changes: LayoutChange[], record: 'undo' | 'redo' | null) => {
            setCanvas((current) => {
                let services = current.services;
                let nextGroups = current.groups ?? [];
                for (const change of changes) {
                    if (change.type === 'group') {
                        nextGroups = nextGroups.map((group) => (group.id === change.id ? { ...group, position: change.to } : group));
                    } else if (change.type === 'child') {
                        services = services.map((service) =>
                            service.id === change.id && service.compose
                                ? {
                                      ...service,
                                      compose: {
                                          ...service.compose,
                                          services: service.compose.services.map((child) =>
                                              child.name === change.name ? { ...child, position: change.to } : child,
                                          ),
                                      },
                                  }
                                : service,
                        );
                    } else {
                        services = services.map((service) =>
                            service.id === change.id
                                ? { ...service, position: { x: change.to.x, y: change.to.y }, group_id: change.to.group_id }
                                : service,
                        );
                    }
                }
                // A group left without members disappears (the server drops it too).
                nextGroups = nextGroups.filter((group) => services.some((service) => service.group_id === group.id));

                return { ...current, services, groups: nextGroups };
            });
            if (record) {
                setHistory((current) =>
                    record === 'undo' ? { undo: [...current.undo.slice(-49), changes], redo: [] } : { ...current, redo: [...current.redo, changes] },
                );
            }
            try {
                await Promise.all(
                    changes.map((change) => {
                        if (change.type === 'group') return requestJson(`${home}/groups/${change.id}`, 'PATCH', change.to);
                        if (change.type === 'child')
                            return requestJson(`${home}/services/${change.id}/layout`, 'PATCH', { children: { [change.name]: change.to } });

                        return requestJson(`${home}/services/${change.id}/position`, 'PATCH', {
                            x: change.to.x,
                            y: change.to.y,
                            ...(change.from.group_id !== change.to.group_id ? { group_id: change.to.group_id } : {}),
                        });
                    }),
                );
            } catch (error) {
                toast.error('Could not save the layout', errorMessage(error));
            } finally {
                void refresh();
            }
        },
        [home, setCanvas, refresh],
    );

    const invert = (changes: LayoutChange[]): LayoutChange[] =>
        changes.map((change) => ({ ...change, from: change.to, to: change.from }) as LayoutChange).reverse();

    const undo = useCallback(() => {
        const last = history.undo[history.undo.length - 1];
        if (!last) return;
        setHistory((current) => ({ undo: current.undo.slice(0, -1), redo: current.redo }));
        void apply(invert(last), 'redo');
    }, [history.undo, apply]);

    const redo = useCallback(() => {
        const last = history.redo[history.redo.length - 1];
        if (!last) return;
        setHistory((current) => ({ undo: [...current.undo, last], redo: current.redo.slice(0, -1) }));
        void apply(last, null);
    }, [history.redo, apply]);

    // ⌘Z / ⇧⌘Z outside text fields and panels.
    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (!(event.metaKey || event.ctrlKey) || event.key.toLowerCase() !== 'z') return;
            const target = event.target as HTMLElement | null;
            if (target && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName) || target.closest('[role="dialog"]')))
                return;
            event.preventDefault();
            if (event.shiftKey) redo();
            else undo();
        };
        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [undo, redo]);

    const createGroup = async () => {
        const ids = selection;
        setSelection([]);
        setSelectionReset((value) => value + 1);
        try {
            const body = await requestJson<{ data: CanvasGroup }>(`${home}/groups`, 'POST', { name: 'Group', service_ids: ids });
            await refresh();
            setRenaming(body.data.id);
            setHistory({ undo: [], redo: [] });
        } catch (error) {
            toast.error('Could not group the services', errorMessage(error));
        }
    };

    const onGroup = useCallback(
        async (group: CanvasGroup, action: GroupAction) => {
            if (action === 'rename') {
                setRenaming(group.id);

                return;
            }
            try {
                if (action === 'ungroup') {
                    await requestJson(`${home}/groups/${group.id}`, 'DELETE');
                    setHistory({ undo: [], redo: [] });
                } else {
                    const collapsed = action === 'collapse';
                    setCanvas((current) => ({
                        ...current,
                        groups: (current.groups ?? []).map((item) => (item.id === group.id ? { ...item, collapsed } : item)),
                    }));
                    if (can.manage) await requestJson(`${home}/groups/${group.id}`, 'PATCH', { collapsed });
                }
            } catch (error) {
                toast.error('Could not update the group', errorMessage(error));
            } finally {
                void refresh();
            }
        },
        [home, can.manage, setCanvas, refresh],
    );

    const onRename = useCallback(
        async (group: CanvasGroup, name: string | null) => {
            setRenaming(null);
            const next = name?.trim();
            if (!next || next === group.name) return;
            setCanvas((current) => ({
                ...current,
                groups: (current.groups ?? []).map((item) => (item.id === group.id ? { ...item, name: next } : item)),
            }));
            try {
                await requestJson(`${home}/groups/${group.id}`, 'PATCH', { name: next });
            } catch (error) {
                toast.error('Could not rename the group', errorMessage(error));
                void refresh();
            }
        },
        [home, setCanvas, refresh],
    );

    const onCompose = useCallback(
        async (service: CanvasService, action: ComposeAction) => {
            if (action === 'open') {
                openService(service);

                return;
            }
            const collapsed = action === 'collapse' ? true : action === 'expand' ? false : undefined;
            const children =
                action === 'tidy'
                    ? Object.fromEntries((service.compose?.services ?? []).map((child, index) => [child.name, composeGrid(index)]))
                    : undefined;
            setCanvas((current) => ({
                ...current,
                services: current.services.map((item) =>
                    item.id === service.id && item.compose
                        ? {
                              ...item,
                              compose: {
                                  ...item.compose,
                                  collapsed: collapsed ?? item.compose.collapsed,
                                  services: item.compose.services.map((child) => (children ? { ...child, position: children[child.name] } : child)),
                              },
                          }
                        : item,
                ),
            }));
            if (!can.manage) return;
            try {
                await requestJson(`${home}/services/${service.id}/layout`, 'PATCH', {
                    ...(collapsed !== undefined ? { collapsed } : {}),
                    ...(children ? { children } : {}),
                });
                if (children) setHistory({ undo: [], redo: [] });
            } catch (error) {
                toast.error('Could not update the layout', errorMessage(error));
                void refresh();
            }
        },
        [home, can.manage, openService, setCanvas, refresh],
    );

    /** Close the stack from `index` up (0 = every panel). */
    const dismiss = useCallback(
        (index: number) => {
            const current = routeRef.current;
            if (index <= 0) {
                navigate(EMPTY_STACK);
            } else if (index === 1) {
                navigate(current.peek ? { ...current, peek: null, layer: null, focus: false } : { ...current, layer: null, focus: false });
            } else {
                navigate({ ...current, layer: null, focus: false });
            }
        },
        [navigate],
    );

    /** Context of a service panel in the stack (`which`: the base panel or the peeked one). */
    const contextFor = useCallback(
        (which: 'base' | 'peek', ref: ServiceRef, top: boolean): Omit<ServicePanelContext, 'service' | 'tab' | 'baseUrl'> => ({
            project: { id: project.id, name: project.name },
            environment: { id: environment.id, name: environment.name, slug: environment.slug },
            canvasUrl: home,
            item: ref.item,
            layer: top && routeRef.current.layer ? { id: routeRef.current.layer.id, record: routeRef.current.layer.record } : null,
            open: (tab, item = null) => {
                const current = routeRef.current;
                const claimed = item ? layerDefs.find((layer) => layer.fromTab === tab) : undefined;
                const service = { ...ref, tab, item: claimed ? null : item };
                const layer = claimed && item ? { id: claimed.id, record: item.toLowerCase(), tab: null } : null;
                navigate(which === 'base' ? { ...EMPTY_STACK, base: service, layer } : { ...current, peek: service, layer, focus: false });
            },
            openLayer: (id, record, tab = null) => {
                const current = routeRef.current;
                navigate({
                    ...current,
                    peek: which === 'base' ? null : current.peek,
                    layer: { id, record: record.toLowerCase(), tab },
                    focus: false,
                });
            },
            openService: (target, tab = null) => {
                const current = routeRef.current;
                if (target.kind === ref.kind && target.ref_id === ref.id) return;
                navigate({
                    ...current,
                    base: current.base,
                    peek: { kind: target.kind, id: target.ref_id, tab, item: null },
                    layer: null,
                    focus: false,
                });
            },
            refresh: () => void refresh(),
            close: () => {
                dismiss(which === 'base' ? 0 : 1);
                void refresh();
            },
            can: shell.can,
        }),
        [project.id, project.name, environment.id, environment.name, environment.slug, home, layerDefs, navigate, dismiss, refresh, shell],
    );

    const rename = (service: CanvasService | null) => (name: string) =>
        setCanvas((current) => ({ ...current, services: current.services.map((item) => (item.id === service?.id ? { ...item, name } : item)) }));

    const layers: PanelLayer[] = [];
    const panelFor = (which: 'base' | 'peek', ref: ServiceRef, service: CanvasService | null, index: number) => {
        const base = contextFor(which, ref, index === (route.peek ? 1 : 0));
        layers.push({
            key: `service:${ref.kind}:${ref.id}:${which}`,
            label: service ? `${service.name} service panel` : 'Service panel',
            content: (
                <ServicePanel
                    base={base}
                    service={service}
                    kind={ref.kind}
                    refId={ref.id}
                    tab={ref.tab}
                    renameUrl={can.manage && service ? `${home}/services/${service.id}` : null}
                    onRenamed={rename(service)}
                    onClose={() => dismiss(index)}
                />
            ),
        });

        return base;
    };

    let ownerBase: ReturnType<typeof contextFor> | null = null;
    let ownerRef: ServiceRef | null = null;
    if (route.base) {
        ownerBase = panelFor('base', route.base, baseService, 0);
        ownerRef = route.base;
    }
    if (route.base && route.peek) {
        ownerBase = panelFor('peek', route.peek, peekService, 1);
        ownerRef = route.peek;
    }
    const layerDef = route.layer ? layerDefs.find((layer) => layer.id === route.layer?.id) : undefined;
    if (route.layer && layerDef && ownerBase && ownerRef && topService && (!layerDef.permission || shell.can(layerDef.permission))) {
        const ctx: ServicePanelContext = {
            ...ownerBase,
            service: topService,
            tab: ownerRef.tab ?? '',
            baseUrl: `${home}/service/${ownerRef.kind}/${ownerRef.id}`,
            item: null,
        };
        const Layer = layerDef.component;
        const layer = route.layer;
        layers.push({
            key: `layer:${layer.id}:${layer.record}`,
            label: layerDef.label(ctx, layer.record),
            content: (
                <Suspense fallback={null}>
                    <Layer
                        ctx={ctx}
                        record={layer.record}
                        tab={layer.tab}
                        onTabChange={(tab) => navigate({ ...routeRef.current, layer: { ...layer, tab }, focus: routeRef.current.focus })}
                        close={() => dismiss(layers.length - 1)}
                    />
                </Suspense>
            ),
        });
    }

    useRevealBehindPanel(topService, groups, layers.length > 0);

    return (
        <>
            <Head title={`${project.name} · ${environment.name}`} />
            <div className="relative h-[calc(100svh-3rem)] min-h-0 flex-1 overflow-hidden" data-testid="project-canvas">
                <div className="absolute inset-0">
                    <CanvasBoard
                        services={canvas.services}
                        groups={groups}
                        edges={canvas.edges}
                        selectedId={topService?.id ?? null}
                        editable={can.manage}
                        snap={snap}
                        minimap={minimap}
                        renaming={renaming}
                        viewportKey={`${project.id}:${environment.id}`}
                        onOpen={(service, child) => openService(service, child ? 'services' : null)}
                        onOpenVolume={
                            shell.can('volumes.view')
                                ? (service, volumeId) =>
                                      navigate({
                                          ...EMPTY_STACK,
                                          base: { kind: service.kind, id: service.ref_id, tab: null, item: null },
                                          layer: { id: 'volume', record: volumeId.toLowerCase(), tab: null },
                                      })
                                : undefined
                        }
                        onPaneClick={() => {
                            if (routeRef.current.base) navigate(EMPTY_STACK);
                        }}
                        onLayout={(changes) => void apply(changes, 'undo')}
                        onGroup={(group, action) => void onGroup(group, action)}
                        onRename={(group, name) => void onRename(group, name)}
                        onCompose={(service, action) => void onCompose(service, action)}
                        onSelectionChange={(ids) => setSelection((current) => (current.join() === ids.join() ? current : ids))}
                        selectionReset={selectionReset}
                        onContextMenu={canCreate ? (position) => setPicker({ position }) : undefined}
                    />
                </div>

                <div className="absolute top-3 right-3 z-10 flex items-center gap-2 md:right-4">
                    <IconButton
                        label="Activity"
                        icon={<Activity />}
                        variant="secondary"
                        aria-pressed={activity}
                        className={cn(activity && 'bg-surface-3 text-fg')}
                        onClick={() => setActivity((value) => !value)}
                    />
                    {can.manage && (
                        <IconButton
                            label="Project settings"
                            icon={<Settings />}
                            variant="secondary"
                            onClick={() => router.visit(`/projects/${project.id}/settings`)}
                        />
                    )}
                    {canCreate && (
                        <Button
                            variant="primary"
                            icon={<Plus />}
                            onClick={() => setPicker(picker ? null : { position: null })}
                            aria-expanded={picker !== null}
                        >
                            Create
                        </Button>
                    )}
                </div>

                <div className="absolute top-14 right-3 z-10 md:right-4">
                    <ConfigDriftBanner projectId={project.id} environment={environment.slug} />
                </div>

                {canvas.services.length === 0 && <EmptyCanvas onCreate={canCreate ? () => setPicker({ position: null }) : undefined} />}

                <Toolbar
                    snap={snap}
                    onSnap={() =>
                        setSnap((value) => {
                            writeFlag('falak:canvas-snap', !value);

                            return !value;
                        })
                    }
                    minimap={minimap}
                    onMinimap={() =>
                        setMinimap((value) => {
                            writeFlag('falak:canvas-minimap', !value);

                            return !value;
                        })
                    }
                    canUndo={can.manage && history.undo.length > 0}
                    canRedo={can.manage && history.redo.length > 0}
                    onUndo={undo}
                    onRedo={redo}
                />

                {can.manage && selection.length > 0 && (
                    <SelectionBar
                        count={selection.length}
                        onGroup={() => void createGroup()}
                        onClear={() => {
                            setSelection([]);
                            setSelectionReset((value) => value + 1);
                        }}
                    />
                )}

                {activity && (
                    <ActivityRail
                        url={`${home}/activity`}
                        onClose={() => setActivity(false)}
                        onOpen={(item: ActivityItem) =>
                            openService({ kind: item.kind, ref_id: item.ref_id }, item.deployment_id ? 'deployments' : null, item.deployment_id)
                        }
                    />
                )}

                {picker && (
                    <CreatePicker
                        projectId={project.id}
                        environmentSlug={environment.slug}
                        can={can}
                        position={picker.position}
                        initialOption={picker.option}
                        onClose={() => setPicker(null)}
                        onCreated={(service, deploymentId, tab) => {
                            setPicker(null);
                            setCanvas((current) => ({
                                ...current,
                                services: [...current.services.filter((item) => item.id !== service.id), service],
                            }));
                            openService(service, tab ?? (service.kind === 'site' ? 'deployments' : 'overview'), deploymentId);
                            void refresh();
                        }}
                    />
                )}
            </div>

            <PanelStack layers={layers} onDismiss={dismiss} resizeKey="service" maximized={route.focus} />
        </>
    );
}

/** §4 project canvas with the §5 panel stack (deep links: /projects/{p}/{env}/service/{kind}/{id}/{tab}?logs={deployment}). */
export default function Canvas(props: CanvasPageProps) {
    return (
        <AppShell variant="full">
            <ReactFlowProvider>
                <CanvasPage {...props} />
            </ReactFlowProvider>
        </AppShell>
    );
}
