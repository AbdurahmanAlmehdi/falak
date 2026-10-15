import { Menu, SERVICE_CARD, ServiceCard, ServiceIcon, StatusDot, serviceIconKey, type MenuAction } from '@/components/falak';
import { cn } from '@/lib/utils';
import { type CanvasEdge, type CanvasGroup, type CanvasService, type ComposeChild } from '@/types';
import {
    Background,
    BackgroundVariant,
    BaseEdge,
    EdgeLabelRenderer,
    Handle,
    MarkerType,
    MiniMap,
    Position,
    ReactFlow,
    useNodesState,
    useReactFlow,
    type Edge,
    type EdgeProps,
    type Node,
    type NodeProps,
    type OnNodeDrag,
    type Viewport,
} from '@xyflow/react';
import '@xyflow/react/dist/base.css';
import { ChevronDown, ChevronRight, Group as GroupIcon, LayoutGrid, PanelRightOpen, Pencil, Ungroup } from 'lucide-react';
import {
    createContext,
    memo,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useRef,
    useState,
    type KeyboardEvent,
    type MouseEvent as ReactMouseEvent,
} from 'react';
import { absoluteOf, cardHeight, composeMembers, contains, frame, type Box, type Point } from './canvas-geometry';
import { CanvasSpace } from './canvas-space';
import { roundedPath, routeEdge, type Rect } from './edge-routing';

const SIDES = [
    ['l', Position.Left],
    ['r', Position.Right],
    ['t', Position.Top],
    ['b', Position.Bottom],
] as const;

/** Invisible connection points on every side (edges are routed by edge-routing.ts, not by these). */
function Handles() {
    return (
        <>
            {SIDES.map(([id, position]) => (
                <Handle key={`t-${id}`} id={`t-${id}`} type="target" position={position} className="falak-handle" isConnectable={false} />
            ))}
            {SIDES.map(([id, position]) => (
                <Handle key={`s-${id}`} id={`s-${id}`} type="source" position={position} className="falak-handle" isConnectable={false} />
            ))}
        </>
    );
}

/** A position change on the canvas; `from` makes it undoable. Group members are relative to their group anchor. */
export type LayoutChange =
    | { type: 'service'; id: string; from: Point & { group_id: string | null }; to: Point & { group_id: string | null } }
    | { type: 'group'; id: string; from: Point; to: Point }
    | { type: 'child'; id: string; name: string; from: Point; to: Point };

export type GroupAction = 'rename' | 'collapse' | 'expand' | 'ungroup';
export type ComposeAction = 'open' | 'collapse' | 'expand' | 'tidy';

interface BoardActions {
    editable: boolean;
    selectedId: string | null;
    dropTarget: string | null;
    renaming: string | null;
    onOpen: (service: CanvasService, child?: string) => void;
    onOpenVolume?: (service: CanvasService, volumeId: string) => void;
    onGroup: (group: CanvasGroup, action: GroupAction) => void;
    onRename: (group: CanvasGroup, name: string | null) => void;
    onCompose: (service: CanvasService, action: ComposeAction) => void;
}

const BoardContext = createContext<BoardActions | null>(null);

function useBoard(): BoardActions {
    const value = useContext(BoardContext);
    if (!value) throw new Error('Canvas node outside CanvasBoard');

    return value;
}

type ServiceNode = Node<{ service: CanvasService }, 'service'>;
type ChildNode = Node<{ service: CanvasService; child: ComposeChild }, 'child'>;
type GroupNode = Node<{ group: CanvasGroup; members: CanvasService[]; box: Box }, 'frame'>;
type ComposeNode = Node<{ service: CanvasService; box: Box }, 'compose'>;
type BoardNode = ServiceNode | ChildNode | GroupNode | ComposeNode;

const ServiceNodeView = memo(function ServiceNodeView({ data, selected }: NodeProps<ServiceNode>) {
    const board = useBoard();

    return (
        <>
            <Handles />
            <ServiceCard
                service={data.service}
                selected={board.selectedId === data.service.id}
                marked={selected}
                className="cursor-pointer"
                onOpenVolume={board.onOpenVolume && ((volumeId) => board.onOpenVolume?.(data.service, volumeId))}
            />
        </>
    );
});

const ChildNodeView = memo(function ChildNodeView({ data }: NodeProps<ChildNode>) {
    const { child, service } = data;
    const board = useBoard();

    return (
        <>
            <Handles />
            <ServiceCard
                className="cursor-pointer"
                service={{
                    kind: 'site',
                    name: child.name,
                    icon: child.icon,
                    status: child.status,
                    status_label: child.status_label,
                    url: child.url,
                    subtitle: child.image,
                    servers: [],
                    badges: [],
                    volumes: child.volumes,
                }}
                data-compose={service.id}
                onOpenVolume={board.onOpenVolume && ((volumeId) => board.onOpenVolume?.(service, volumeId))}
            />
        </>
    );
});

/** Online / total of a set of statuses, for group headers ("3/4 online"). */
function health(statuses: string[]): { online: number; total: number; worst: string } {
    const online = statuses.filter((status) => status === 'active').length;
    const worst = statuses.find((status) => ['failed', 'crashed'].includes(status)) ?? statuses.find((status) => status !== 'active') ?? 'active';

    return { online, total: statuses.length, worst };
}

function GroupFrame({
    label,
    icon,
    name,
    statuses,
    icons,
    collapsed,
    selected,
    dropTarget,
    menu,
    onHeaderClick,
    renaming,
    onRename,
}: {
    label: string;
    icon: React.ReactNode;
    name: string;
    statuses: string[];
    icons: string[];
    collapsed: boolean;
    selected: boolean;
    dropTarget: boolean;
    menu: MenuAction[];
    onHeaderClick?: () => void;
    renaming?: boolean;
    onRename?: (name: string | null) => void;
}) {
    const summary = health(statuses);

    return (
        <div
            className={cn(
                'bg-group size-full rounded-xl border backdrop-blur-[1px] transition-[border-color,box-shadow] duration-200',
                dropTarget
                    ? 'border-primary shadow-[0_0_0_3px_var(--accent-soft)]'
                    : selected
                      ? 'border-primary/70'
                      : 'border-group-border hover:border-border-strong',
            )}
            data-label={label}
        >
            <Handles />
            <div className="flex h-[38px] items-center gap-2 pr-1.5 pl-3">
                <span className="flex size-5 shrink-0 items-center justify-center">{icon}</span>
                {renaming && onRename ? (
                    <input
                        autoFocus
                        defaultValue={name}
                        aria-label="Group name"
                        className="nodrag bg-surface-1 border-border-strong text-fg h-6 min-w-0 flex-1 rounded-md border px-1.5 text-xs font-semibold outline-none"
                        onFocus={(event) => event.currentTarget.select()}
                        onKeyDown={(event) => {
                            event.stopPropagation();
                            if (event.key === 'Enter') onRename(event.currentTarget.value);
                            if (event.key === 'Escape') onRename(null);
                        }}
                        onBlur={(event) => onRename(event.currentTarget.value)}
                    />
                ) : (
                    <button
                        type="button"
                        onClick={onHeaderClick}
                        className={cn(
                            'text-fg min-w-0 truncate text-left text-xs font-semibold',
                            onHeaderClick ? 'hover:underline' : 'cursor-default',
                        )}
                        tabIndex={-1}
                    >
                        {name}
                    </button>
                )}
                <span className="text-fg-faint text-2xs ml-auto flex shrink-0 items-center gap-1.5 tabular-nums">
                    <StatusDot status={summary.worst} size="sm" />
                    {summary.online}/{summary.total} online
                </span>
                <span className="nodrag">
                    <Menu actions={menu} label={`${name} group actions`} />
                </span>
            </div>
            {collapsed && (
                <div className="animate-fade-in flex items-center gap-1.5 px-3 pt-1">
                    {icons.slice(0, 7).map((key, index) => (
                        <span
                            key={`${key}-${index}`}
                            className="border-border bg-surface-1 flex size-8 items-center justify-center rounded-lg border"
                        >
                            <ServiceIcon name={key} size={15} />
                        </span>
                    ))}
                    {icons.length > 7 && <span className="text-fg-faint text-xs">+{icons.length - 7}</span>}
                </div>
            )}
        </div>
    );
}

const GroupNodeView = memo(function GroupNodeView({ data }: NodeProps<GroupNode>) {
    const board = useBoard();
    const { group, members } = data;
    const menu: MenuAction[] = board.editable
        ? [
              { label: 'Rename', icon: <Pencil />, onSelect: () => board.onGroup(group, 'rename') },
              group.collapsed
                  ? { label: 'Expand', icon: <ChevronDown />, onSelect: () => board.onGroup(group, 'expand') }
                  : { label: 'Collapse', icon: <ChevronRight />, onSelect: () => board.onGroup(group, 'collapse') },
              { type: 'separator' },
              { label: 'Ungroup', icon: <Ungroup />, onSelect: () => board.onGroup(group, 'ungroup') },
          ]
        : [
              group.collapsed
                  ? { label: 'Expand', icon: <ChevronDown />, onSelect: () => board.onGroup(group, 'expand') }
                  : { label: 'Collapse', icon: <ChevronRight />, onSelect: () => board.onGroup(group, 'collapse') },
          ];

    return (
        <GroupFrame
            label={`${group.name} group`}
            icon={<GroupIcon className="text-fg-muted size-4" aria-hidden />}
            name={group.name}
            statuses={members.map((member) => member.status)}
            icons={members.map((member) => member.icon || member.kind)}
            collapsed={group.collapsed}
            selected={members.some((member) => member.id === board.selectedId)}
            dropTarget={board.dropTarget === group.id}
            menu={menu}
            renaming={board.renaming === group.id}
            onRename={(name) => board.onRename(group, name)}
        />
    );
});

const ComposeNodeView = memo(function ComposeNodeView({ data }: NodeProps<ComposeNode>) {
    const board = useBoard();
    const { service } = data;
    const children = service.compose?.services ?? [];
    const collapsed = service.compose?.collapsed ?? false;
    const icon = serviceIconKey(service);
    const menu: MenuAction[] = [
        { label: 'Open service', icon: <PanelRightOpen />, onSelect: () => board.onCompose(service, 'open') },
        collapsed
            ? { label: 'Expand', icon: <ChevronDown />, onSelect: () => board.onCompose(service, 'expand') }
            : { label: 'Collapse', icon: <ChevronRight />, onSelect: () => board.onCompose(service, 'collapse') },
        ...(board.editable ? [{ label: 'Tidy up layout', icon: <LayoutGrid />, onSelect: () => board.onCompose(service, 'tidy') }] : []),
    ];

    return (
        <GroupFrame
            label={`${service.name} compose group`}
            icon={<ServiceIcon name={icon} size={16} />}
            name={service.name}
            statuses={children.map((child) => child.status)}
            icons={children.map((child) => child.icon)}
            collapsed={collapsed}
            selected={board.selectedId === service.id}
            dropTarget={false}
            menu={menu}
            onHeaderClick={() => board.onOpen(service)}
        />
    );
});

// Not `group`: xyflow ships default styles for that node type.
const nodeTypes = { service: ServiceNodeView, child: ChildNodeView, frame: GroupNodeView, compose: ComposeNodeView };

type RoutedEdge = Edge<{ points: Point[]; problem?: string }, 'routed'>;

/** The middle of a route (by length), where an edge's warning sits. */
function routeMiddle(points: Point[]): Point {
    const lengths = points.slice(1).map((point, i) => Math.abs(point.x - points[i].x) + Math.abs(point.y - points[i].y));
    let left = lengths.reduce((sum, length) => sum + length, 0) / 2;
    for (let i = 0; i < lengths.length; i++) {
        if (left <= lengths[i] && lengths[i] > 0) {
            const t = left / lengths[i];
            return { x: points[i].x + (points[i + 1].x - points[i].x) * t, y: points[i].y + (points[i + 1].y - points[i].y) * t };
        }
        left -= lengths[i];
    }
    return points[0];
}

/**
 * A derived edge drawn along its precomputed orthogonal route, dashed, with an arrowhead at the referenced service. A
 * reference that won't resolve (e.g. no private network to a dedicated database server) is drawn in amber with a
 * warning mark that explains it.
 */
const RoutedEdgeView = memo(function RoutedEdgeView({ id, data, sourceX, sourceY, targetX, targetY, markerEnd }: EdgeProps<RoutedEdge>) {
    const points = data?.points ?? [
        { x: sourceX, y: sourceY },
        { x: targetX, y: targetY },
    ];
    const problem = data?.problem;
    const middle = problem ? routeMiddle(points) : null;

    return (
        <>
            <BaseEdge id={id} path={roundedPath(points)} markerEnd={markerEnd} />
            {problem && middle && (
                <EdgeLabelRenderer>
                    <span
                        role="img"
                        aria-label={`Unresolved reference: ${problem}`}
                        title={`${problem}\n\nThe next deploy fails until this is fixed (see the site's Variables).`}
                        className="falak-edge-problem nodrag nopan"
                        style={{ transform: `translate(-50%, -50%) translate(${middle.x}px, ${middle.y}px)` }}
                    >
                        !
                    </span>
                </EdgeLabelRenderer>
            )}
        </>
    );
});

const edgeTypes = { routed: RoutedEdgeView };

/** Follow the app theme (the `dark` class on <html>), so xyflow's own `light`/`dark` class never fights it. */
function useDocumentTheme(): 'dark' | 'light' {
    const read = () => (document.documentElement.classList.contains('dark') ? 'dark' : 'light');
    const [theme, setTheme] = useState<'dark' | 'light'>(read);

    useEffect(() => {
        const observer = new MutationObserver(() => setTheme(read()));
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

        return () => observer.disconnect();
    }, []);

    return theme;
}

export interface CanvasBoardProps {
    services: CanvasService[];
    groups: CanvasGroup[];
    edges: CanvasEdge[];
    /** Canvas service whose panel is open. */
    selectedId: string | null;
    editable: boolean;
    snap: boolean;
    minimap: boolean;
    /** Group being renamed inline (a new group starts in this state). */
    renaming: string | null;
    /** Where the viewport is remembered (per environment) across visits. */
    viewportKey: string;
    onOpen: (service: CanvasService, child?: string) => void;
    /** A volume chip of a card: the volume opens over the service's panel. */
    onOpenVolume?: (service: CanvasService, volumeId: string) => void;
    onPaneClick: () => void;
    onLayout: (changes: LayoutChange[]) => void;
    onGroup: (group: CanvasGroup, action: GroupAction) => void;
    onRename: (group: CanvasGroup, name: string | null) => void;
    onCompose: (service: CanvasService, action: ComposeAction) => void;
    /** Top-level services picked with ⇧-drag / ⌘-click (to group them). */
    onSelectionChange: (ids: string[]) => void;
    /** Bump to clear the multi-selection. */
    selectionReset: number;
    /** Right-click on empty canvas, with the flow coordinates of the click. */
    onContextMenu?: (flow: { x: number; y: number }) => void;
}

interface Layout {
    nodes: BoardNode[];
    rects: Map<string, Rect>;
    /** Node that stands in for a hidden (collapsed) member in edges. */
    alias: Map<string, string>;
    groupBoxes: Map<string, Box>;
    composeBoxes: Map<string, Box>;
}

/** Nodes (parents before children) and absolute rectangles for edge routing, from the canvas read model. */
function layout(services: CanvasService[], groups: CanvasGroup[], editable: boolean, frozen: Map<string, Box>, leaving: Set<string>): Layout {
    const groupMap = new Map(groups.map((group) => [group.id, group]));
    const nodes: BoardNode[] = [];
    const rects = new Map<string, Rect>();
    const alias = new Map<string, string>();
    const groupBoxes = new Map<string, Box>();
    const composeBoxes = new Map<string, Box>();

    for (const group of groups) {
        const members = services.filter((service) => service.group_id === group.id);
        const absolute = members.map((service) => ({ ...absoluteOf(service, groupMap), height: cardHeight(service), service }));
        const box = frozen.get(`group:${group.id}`) ?? frame(absolute, group.position, group.collapsed && !leaving.has(group.id));
        const hideMembers = group.collapsed && !leaving.has(group.id);
        groupBoxes.set(group.id, box);
        rects.set(`group:${group.id}`, box);
        nodes.push({
            id: `group:${group.id}`,
            type: 'frame',
            position: { x: box.x, y: box.y },
            width: box.width,
            height: box.height,
            data: { group, members, box },
            draggable: editable,
            selectable: false,
            zIndex: 0,
            ariaLabel: `${group.name} group`,
        });
        for (const member of absolute) {
            nodes.push({
                id: member.service.id,
                type: 'service',
                parentId: `group:${group.id}`,
                position: { x: member.x - box.x, y: member.y - box.y },
                data: { service: member.service },
                hidden: hideMembers,
                className: cn(
                    leaving.has(group.id) && group.collapsed && 'falak-leaving',
                    !group.collapsed && leaving.has(group.id) && 'falak-enter',
                ),
                draggable: editable,
                selectable: false,
                zIndex: 1,
                ariaLabel: `${member.service.name}: ${member.service.status_label}`,
            });
            if (group.collapsed) alias.set(member.service.id, `group:${group.id}`);
            else rects.set(member.service.id, { x: member.x, y: member.y, width: SERVICE_CARD.width, height: member.height });
        }
    }

    for (const service of services) {
        if (service.group_id && groupMap.has(service.group_id)) continue;

        if (service.compose) {
            const members = composeMembers(service);
            const collapsed = service.compose.collapsed;
            const box = frozen.get(service.id) ?? frame(members, service.position, collapsed && !leaving.has(service.id));
            composeBoxes.set(service.id, box);
            rects.set(service.id, box);
            nodes.push({
                id: service.id,
                type: 'compose',
                position: { x: box.x, y: box.y },
                width: box.width,
                height: box.height,
                data: { service, box },
                draggable: editable,
                selectable: false,
                zIndex: 0,
                ariaLabel: `${service.name}: ${service.status_label}`,
            });
            for (const member of members) {
                const id = `${service.id}:${member.child.name}`;
                nodes.push({
                    id,
                    type: 'child',
                    parentId: service.id,
                    position: { x: member.x - box.x, y: member.y - box.y },
                    data: { service, child: member.child },
                    hidden: collapsed && !leaving.has(service.id),
                    className: cn(leaving.has(service.id) && collapsed && 'falak-leaving', leaving.has(service.id) && !collapsed && 'falak-enter'),
                    draggable: editable,
                    selectable: false,
                    zIndex: 1,
                    ariaLabel: `${member.child.name} (${service.name}): ${member.child.status_label}`,
                });
                if (collapsed) alias.set(id, service.id);
                else rects.set(id, { x: member.x, y: member.y, width: SERVICE_CARD.width, height: member.height });
            }
            continue;
        }

        nodes.push({
            id: service.id,
            type: 'service',
            position: service.position,
            data: { service },
            draggable: editable,
            selectable: editable,
            zIndex: 1,
            ariaLabel: `${service.name}: ${service.status_label}`,
        });
        rects.set(service.id, { ...service.position, width: SERVICE_CARD.width, height: cardHeight(service) });
    }

    return { nodes, rects, alias, groupBoxes, composeBoxes };
}

function readViewport(key: string): Viewport | null {
    try {
        const value = JSON.parse(window.sessionStorage.getItem(`falak:viewport:${key}`) ?? 'null');

        return value && typeof value.x === 'number' && typeof value.zoom === 'number' ? value : null;
    } catch {
        return null;
    }
}

/**
 * The project canvas (§4): dotted grid, drag to pan, ⌘/Ctrl + scroll or +/− to zoom; service cards (with volume
 * strips), user groups and compose groups (frames that wrap their members, draggable as a unit, collapsible), and
 * dashed reference / depends_on edges with arrowheads. Card, group and compose-service positions persist per
 * environment (onLayout); dropping a card on a group moves it in, dragging it out of the frame moves it out.
 */
export function CanvasBoard({
    services,
    groups,
    edges,
    selectedId,
    editable,
    snap,
    minimap,
    renaming,
    viewportKey,
    onOpen,
    onOpenVolume,
    onPaneClick,
    onLayout,
    onGroup,
    onRename,
    onCompose,
    onSelectionChange,
    selectionReset,
    onContextMenu,
}: CanvasBoardProps) {
    const flow = useReactFlow();
    const [frozen, setFrozen] = useState<Map<string, Box>>(new Map());
    const [dropTarget, setDropTarget] = useState<string | null>(null);
    const [leaving, setLeaving] = useState<Set<string>>(new Set());
    const colorMode = useDocumentTheme();
    const initialViewport = useRef(readViewport(viewportKey));

    // Animate members out (collapse) / in (expand) when a group's collapsed state flips.
    const collapsedState = useMemo(
        () => [
            ...groups.map((group) => [group.id, group.collapsed] as const),
            ...services.filter((s) => s.compose).map((s) => [s.id, s.compose!.collapsed] as const),
        ],
        [groups, services],
    );
    const previous = useRef(new Map(collapsedState));
    useEffect(() => {
        const flipped = collapsedState
            .filter(([id, collapsed]) => previous.current.has(id) && previous.current.get(id) !== collapsed)
            .map(([id]) => id);
        previous.current = new Map(collapsedState);
        if (flipped.length === 0) return;
        setLeaving((current) => new Set([...current, ...flipped]));
        const timer = window.setTimeout(() => setLeaving((current) => new Set([...current].filter((id) => !flipped.includes(id)))), 240);

        return () => window.clearTimeout(timer);
    }, [collapsedState]);

    const computed = useMemo(() => layout(services, groups, editable, frozen, leaving), [services, groups, editable, frozen, leaving]);
    const [nodes, setNodes, onNodesChange] = useNodesState<BoardNode>([]);

    // Server data wins, except for nodes being dragged right now (and the current multi-selection).
    const resetSeen = useRef(selectionReset);
    useEffect(() => {
        setNodes((current) => {
            const live = new Map(current.map((node) => [node.id, node]));

            return computed.nodes.map((node) => {
                const existing = live.get(node.id);
                if (existing?.dragging) return { ...node, position: existing.position, dragging: true } as BoardNode;

                // Cards that just moved into a group leave the multi-selection.
                return { ...node, selected: Boolean(existing?.selected) && !node.parentId && selectionReset === resetSeen.current } as BoardNode;
            });
        });
        resetSeen.current = selectionReset;
    }, [computed, setNodes, selectionReset]);

    const endpoint = (id: string) => computed.alias.get(id) ?? id;
    const flowEdges = useMemo<RoutedEdge[]>(() => {
        const seen = new Set<string>();
        // Edges merged into one (collapsed groups) keep any problem.
        const problems = new Map<string, string>();
        for (const edge of edges) {
            if (edge.problem) problems.set(`${endpoint(edge.from)}>${endpoint(edge.to)}`, edge.problem);
        }

        return edges.flatMap((edge) => {
            const from = endpoint(edge.from);
            const to = endpoint(edge.to);
            const key = `${from}>${to}`;
            if (from === to || seen.has(key)) return [];
            seen.add(key);
            const source = computed.rects.get(from);
            const target = computed.rects.get(to);
            if (!source || !target) return [];
            // Route around cards (not around group frames, which edges may cross).
            const obstacles = [...computed.rects.entries()]
                .filter(([id]) => id !== from && id !== to && !id.startsWith('group:') && !computed.composeBoxes.has(id))
                .map(([, rect]) => rect);
            const active =
                selectedId !== null &&
                (edge.from === selectedId || edge.to === selectedId || from.startsWith(selectedId) || to.startsWith(selectedId));
            const problem = problems.get(key);

            return [
                {
                    id: key,
                    type: 'routed' as const,
                    source: from,
                    target: to,
                    sourceHandle: 's-r',
                    targetHandle: 't-l',
                    data: { points: routeEdge(source, target, obstacles), problem },
                    className: cn('falak-edge', active && 'falak-edge-active', problem && 'falak-edge-problem-path'),
                    markerEnd: {
                        type: MarkerType.ArrowClosed,
                        width: 14,
                        height: 14,
                        color: problem ? 'var(--warning)' : active ? 'var(--accent)' : 'var(--text-faint)',
                    },
                    focusable: false,
                    selectable: false,
                    zIndex: 2,
                },
            ];
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [edges, computed, selectedId]);

    // Keyboard zoom (+ / −) and fit (shift+1), outside text fields and panels.
    useEffect(() => {
        const onKeyDown = (event: globalThis.KeyboardEvent) => {
            const target = event.target as HTMLElement | null;
            if (target && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))) return;
            if (target?.closest('[role="dialog"]')) return;
            if (event.metaKey || event.ctrlKey || event.altKey) return;
            if (event.key === '+' || event.key === '=') flow.zoomIn({ duration: 180 });
            else if (event.key === '-' || event.key === '_') flow.zoomOut({ duration: 180 });
            else if (event.key === '!') void flow.fitView({ duration: 320, padding: 0.3, maxZoom: 1 });
        };
        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [flow]);

    const serviceOf = (id: string) => services.find((service) => service.id === id);

    /** Absolute top-left of a node from its parent's (possibly frozen) box. */
    const absolute = (node: BoardNode): Point => {
        if (!node.parentId) return node.position;
        const parent = computed.rects.get(node.parentId);

        return parent ? { x: parent.x + node.position.x, y: parent.y + node.position.y } : node.position;
    };

    const groupUnder = (point: Point): CanvasGroup | undefined =>
        groups.find((group) => !group.collapsed && contains(computed.groupBoxes.get(group.id)!, point));

    const center = (at: Point): Point => ({ x: at.x + SERVICE_CARD.width / 2, y: at.y + SERVICE_CARD.height / 2 });

    const onDragStart: OnNodeDrag<BoardNode> = (_event, _node, dragged) => {
        // Keep frames still while their members move (they re-fit on drop).
        const next = new Map(frozen);
        for (const node of dragged) {
            if (node.parentId) {
                const box = computed.rects.get(node.parentId);
                if (box) next.set(node.parentId, { x: box.x, y: box.y, width: box.width, height: box.height });
            }
        }
        if (next.size !== frozen.size) setFrozen(next);
    };

    const onDrag: OnNodeDrag<BoardNode> = (_event, node) => {
        if (node.type !== 'service') return;
        const at = absolute(node);
        const target = groupUnder(center(at));
        const own = node.parentId?.slice('group:'.length) ?? null;
        setDropTarget(target && target.id !== own ? target.id : null);
    };

    const onDragStop: OnNodeDrag<BoardNode> = (_event, _node, dragged) => {
        const changes: LayoutChange[] = [];
        const round = (point: Point) => ({ x: Math.round(point.x), y: Math.round(point.y) });

        for (const node of dragged) {
            if (node.type === 'frame') {
                const group = node.data.group;
                const box = computed.groupBoxes.get(group.id)!;
                const to = round({ x: group.position.x + node.position.x - box.x, y: group.position.y + node.position.y - box.y });
                if (to.x !== group.position.x || to.y !== group.position.y) changes.push({ type: 'group', id: group.id, from: group.position, to });
            } else if (node.type === 'compose') {
                const service = node.data.service;
                const box = computed.composeBoxes.get(service.id)!;
                const to = round({ x: service.position.x + node.position.x - box.x, y: service.position.y + node.position.y - box.y });
                if (to.x !== service.position.x || to.y !== service.position.y) {
                    changes.push({ type: 'service', id: service.id, from: { ...service.position, group_id: null }, to: { ...to, group_id: null } });
                }
            } else if (node.type === 'child') {
                const { service, child } = node.data;
                const at = round(absolute(node));
                const to = { x: at.x - service.position.x, y: at.y - service.position.y };
                if (to.x !== child.position.x || to.y !== child.position.y)
                    changes.push({ type: 'child', id: service.id, name: child.name, from: child.position, to });
            } else if (node.type === 'service') {
                const service = serviceOf(node.id);
                if (!service) continue;
                const at = round(absolute(node));
                const own = service.group_id ?? null;
                const ownBox = own ? (frozen.get(`group:${own}`) ?? computed.groupBoxes.get(own)) : undefined;
                const middle = center(at);
                let group: CanvasGroup | undefined = groups.find((candidate) => candidate.id === own);
                if (own && ownBox && !contains(ownBox, middle)) group = undefined; // dragged out of its frame
                const under = groupUnder(middle);
                if (under && under.id !== own) group = under; // dropped onto another group
                const to = group ? { x: at.x - group.position.x, y: at.y - group.position.y, group_id: group.id } : { ...at, group_id: null };
                if (to.x !== service.position.x || to.y !== service.position.y || to.group_id !== own) {
                    changes.push({ type: 'service', id: service.id, from: { ...service.position, group_id: own }, to });
                }
            }
        }

        setDropTarget(null);
        setFrozen(new Map());
        if (changes.length > 0) onLayout(changes);
    };

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        const element = (event.target as HTMLElement).closest<HTMLElement>('.react-flow__node');
        const id = element?.dataset.id;
        if (!id) return;
        const node = nodes.find((candidate) => candidate.id === id);
        if (!node) return;
        event.preventDefault();
        if (node.type === 'service' || node.type === 'compose') onOpen(node.data.service);
        else if (node.type === 'child') onOpen(node.data.service, node.data.child.name);
    };

    // xyflow re-runs its selection listener whenever this handler changes identity: keep it stable.
    const selectionHandler = useRef(onSelectionChange);
    selectionHandler.current = onSelectionChange;
    const selectionChanged = useCallback(
        ({ nodes: picked }: { nodes: BoardNode[] }) =>
            selectionHandler.current(picked.filter((node) => node.type === 'service' && !node.parentId).map((node) => node.id)),
        [],
    );

    const board = useMemo<BoardActions>(
        () => ({ editable, selectedId, dropTarget, renaming, onOpen, onOpenVolume, onGroup, onRename, onCompose }),
        [editable, selectedId, dropTarget, renaming, onOpen, onOpenVolume, onGroup, onRename, onCompose],
    );

    return (
        <BoardContext.Provider value={board}>
            <ReactFlow<BoardNode, RoutedEdge>
                className="falak-canvas"
                colorMode={colorMode}
                nodes={nodes}
                edges={flowEdges}
                nodeTypes={nodeTypes}
                edgeTypes={edgeTypes}
                onNodesChange={onNodesChange}
                onNodeClick={(event, node) => {
                    if (event.metaKey || event.ctrlKey || event.shiftKey) return;
                    if (node.type === 'service') onOpen(node.data.service);
                    else if (node.type === 'child') onOpen(node.data.service, node.data.child.name);
                }}
                onNodeDragStart={onDragStart}
                onNodeDrag={onDrag}
                onNodeDragStop={onDragStop}
                onSelectionChange={selectionChanged}
                onPaneClick={onPaneClick}
                onPaneContextMenu={(event: ReactMouseEvent | MouseEvent) => {
                    if (!onContextMenu) return;
                    event.preventDefault();
                    onContextMenu(flow.screenToFlowPosition({ x: event.clientX, y: event.clientY }));
                }}
                onMoveEnd={(_event, viewport) => {
                    try {
                        window.sessionStorage.setItem(`falak:viewport:${viewportKey}`, JSON.stringify(viewport));
                    } catch {
                        // Not remembered.
                    }
                }}
                onKeyDown={onKeyDown}
                nodesConnectable={false}
                elementsSelectable={editable}
                selectionKeyCode="Shift"
                multiSelectionKeyCode={['Meta', 'Control']}
                selectNodesOnDrag={false}
                snapToGrid={snap}
                snapGrid={[20, 20]}
                zoomOnScroll={false}
                panOnScroll
                zoomActivationKeyCode={['Meta', 'Control']}
                minZoom={0.25}
                maxZoom={1.75}
                defaultViewport={initialViewport.current ?? undefined}
                fitView={!initialViewport.current}
                fitViewOptions={{ padding: 0.25, maxZoom: 1 }}
                proOptions={{ hideAttribution: true }}
                deleteKeyCode={null}
            >
                <CanvasSpace />
                <Background variant={BackgroundVariant.Dots} gap={20} size={1} />
                {minimap && (
                    <MiniMap
                        pannable
                        zoomable
                        position="bottom-left"
                        style={{ left: 64, bottom: 4, width: 180, height: 120 }}
                        nodeColor={(node) => (node.type === 'frame' || node.type === 'compose' ? 'transparent' : 'var(--surface-3)')}
                        nodeStrokeColor={(node) => (node.type === 'frame' || node.type === 'compose' ? 'var(--border-strong)' : 'transparent')}
                        nodeBorderRadius={6}
                        maskColor="var(--group-bg)"
                        ariaLabel="Canvas overview"
                    />
                )}
            </ReactFlow>
        </BoardContext.Provider>
    );
}
