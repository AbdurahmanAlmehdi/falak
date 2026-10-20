import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
}

export type OrganizationRole = 'owner' | 'admin' | 'developer' | 'viewer';

export interface OrganizationSummary {
    id: string;
    name: string;
    slug: string;
    personal: boolean;
}

export interface CurrentOrganization extends OrganizationSummary {
    role: OrganizationRole | null;
    permissions: string[];
}

export interface OrganizationProps {
    current: CurrentOrganization | null;
    all: OrganizationSummary[];
}

/** One-shot session flashes shared by HandleInertiaRequests (rendered as toasts). */
export interface FlashMessages {
    success?: string;
    error?: string;
    warning?: string;
    status?: string;
}

/** Shared by the Projects module on every authenticated page (docs/UI_DESIGN.md §9). Absent until it ships. */
export interface FalakEnvironment {
    id: string;
    name: string;
    slug: string;
    is_production: boolean;
    /** A pull request's preview (grouped under "Previews" in the switcher). */
    is_preview?: boolean;
}

export interface FalakProject {
    id: string;
    name: string;
    icon: string | null;
    environments: FalakEnvironment[];
}

export interface FalakShared {
    projects: FalakProject[];
    current: { project_id: string | null; environment_id: string | null };
}

export interface SharedData {
    name: string;
    falak?: FalakShared | null;
    flash?: FlashMessages;
    quote: { message: string; author: string };
    /** Cool Icons base URL (FALAK_ICONS_URL); null: picker off, bundled logos only. */
    icons_url?: string | null;
    auth: Auth;
    organization: OrganizationProps | null;
    /** Guests only: who may sign up (FALAK_REGISTRATION). */
    registration?: 'open' | 'invite' | 'closed' | null;
    [key: string]: unknown;
}

export interface User {
    id: string;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_confirmed_at?: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}

/** Laravel LengthAwarePaginator serialized by Inertia. */
export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: { url: string | null; label: string; active: boolean }[];
}

/** docs/UI_DESIGN.md §9 canvas read model (GET /projects/{p}/{env}/canvas). */
export type ServiceKind = 'site' | 'database';

export type CanvasStatus = 'active' | 'deploying' | 'building' | 'queued' | 'failed' | 'crashed' | 'inactive' | 'provisioning';

export interface CanvasService {
    /** projects_services.id */
    id: string;
    kind: ServiceKind;
    /** Site id / database id (the owning module's ULID). */
    ref_id: string;
    name: string;
    /** ServiceIcon key: the picked icon, else derived from the site / engine. */
    icon: string;
    /** Icon picked on the canvas (Cool Icons slug[/variant]); null: derived. Optional for older payloads / fixtures. */
    custom_icon?: string | null;
    position: { x: number; y: number };
    status: CanvasStatus;
    status_label: string;
    url: string | null;
    subtitle: string | null;
    servers: { id: string; name: string; leader: boolean; online: boolean }[];
    /** Runtime traits shown on the card and panel header (e.g. "Octane"). Optional for older payloads / fixtures. */
    badges?: string[];
    /** User group the card sits in (its position is then relative to the group's anchor). */
    group_id?: string | null;
    /** Disks drawn under the card: attached volumes (with usage, linking to their page), an engine's data dir. */
    volumes?: CanvasVolume[];
    /** Compose sites: drawn as a group of their compose services (§4.3). */
    compose?: { template: string | null; collapsed: boolean; services: ComposeChild[] } | null;
    last_deployment: { id: string; status: string; commit: string | null; message: string | null; finished_at: string | null } | null;
}

/** One service of a compose site on the canvas; position relative to the site card's position. */
export interface ComposeChild {
    name: string;
    icon: string;
    image: string | null;
    status: CanvasStatus;
    status_label: string;
    url: string | null;
    /** The service's volumes (Volumes rows once the stack went live; names from the file before). */
    volumes: CanvasVolume[];
    position: { x: number; y: number };
}

/** A disk on a canvas card: a volume (id + url once it is a Volumes row) with used / limit bytes when known. */
export interface CanvasVolume {
    id: string | null;
    name: string;
    detail: string | null;
    used_bytes: number | null;
    limit_bytes: number | null;
    url: string | null;
}

/** A user-created canvas group: `position` is the anchor its members' positions are relative to. */
export interface CanvasGroup {
    id: string;
    name: string;
    /** Icon picked on the canvas (Cool Icons slug[/variant]). */
    icon?: string | null;
    position: { x: number; y: number };
    collapsed: boolean;
}

/** `reference`: `${{ service.KEY }}` variables; `depends_on`: compose dependencies (ids `serviceId:composeService`). */
export interface CanvasEdge {
    from: string;
    to: string;
    kind?: 'reference' | 'depends_on';
    /** A reference to a database whose host can't be resolved for the site: why (the next deploy fails on it). */
    problem?: string;
}

export interface Canvas {
    services: CanvasService[];
    edges: CanvasEdge[];
    groups?: CanvasGroup[];
}
