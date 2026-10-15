import { AppShell } from '@/components/falak/app-shell';
import { CopyButton } from '@/components/falak/copy-button';
import { StatusBadge, type StatusTone } from '@/components/falak/status';
import { tabTriggerClasses } from '@/components/falak/tabs';
import { useEchoChannel } from '@/hooks/use-echo-channel';
import { shellContext } from '@/lib/registry';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Server as ServerIcon } from 'lucide-react';
import { useEffect, useMemo, type ReactNode } from 'react';

/**
 * Header data every /servers/{id}/{tab} page receives as the `server` prop
 * (Servers\Contracts\ServerHeaders — Network, Terminal and Recipes pass the same shape).
 */
export interface ServerHeader {
    id: string;
    name: string;
    type: string;
    type_label: string;
    status: 'creating' | 'provisioning' | 'needs_attention' | 'active' | 'error' | 'deleting';
    status_message: string | null;
    provider: string;
    provider_label: string;
    region: string | null;
    ipv4: string | null;
    agent: { status: 'online' | 'offline' | 'revoked'; last_heartbeat_at: string | null } | null;
}

export interface ServerTab {
    id: string;
    label: string;
    /** Path below /servers/{id}; '' is the overview. */
    path: string;
    /** Any one of these permissions shows the tab. */
    permissions?: string[];
}

/** The server page tabs (§3): Overview, Metrics, Processes, Volumes, Firewall, Network, Private network, Security, Terminal, SSH keys, Recipes, PHP, Settings. */
export const SERVER_TABS: ServerTab[] = [
    { id: 'overview', label: 'Overview', path: '' },
    { id: 'metrics', label: 'Metrics', path: 'metrics' },
    { id: 'processes', label: 'Processes', path: 'processes', permissions: ['processes.view'] },
    { id: 'volumes', label: 'Volumes', path: 'volumes', permissions: ['volumes.view'] },
    { id: 'firewall', label: 'Firewall', path: 'firewall', permissions: ['network.view'] },
    { id: 'network', label: 'Network', path: 'network', permissions: ['network.view'] },
    { id: 'private-network', label: 'Private network', path: 'private-network', permissions: ['network.view'] },
    { id: 'security', label: 'Security', path: 'security', permissions: ['security.view'] },
    {
        id: 'terminal',
        label: 'Terminal',
        path: 'terminal',
        permissions: ['terminal.open', 'terminal.attach', 'terminal.control', 'terminal.recordings.view'],
    },
    { id: 'ssh-keys', label: 'SSH keys', path: 'ssh-keys' },
    { id: 'recipes', label: 'Recipes', path: 'recipes', permissions: ['recipes.view'] },
    { id: 'php', label: 'PHP', path: 'php' },
    { id: 'settings', label: 'Settings', path: 'settings' },
];

export interface ServerState {
    /** Key understood by StatusDot / StatusBadge. */
    status: string;
    label: string;
    tone?: StatusTone;
    pulse?: boolean;
}

/**
 * One status for a server, combining the lifecycle status and agent connectivity:
 * creating → "Waiting for agent" (custom) / "Creating"; provisioning; needs_attention (the machine check blocks);
 * active + online → Online; active + offline → Offline; error; deleting.
 */
export function serverState(server: Pick<ServerHeader, 'status' | 'provider' | 'agent'>): ServerState {
    switch (server.status) {
        case 'creating':
            return server.provider === 'custom' && !server.agent
                ? { status: 'waiting', label: 'Waiting for agent', tone: 'info', pulse: true }
                : { status: 'provisioning', label: 'Creating' };
        case 'provisioning':
            return { status: 'provisioning', label: 'Provisioning' };
        case 'needs_attention':
            return { status: 'needs-attention', label: 'Needs attention', tone: 'warning' };
        case 'error':
            return { status: 'failed', label: 'Failed' };
        case 'deleting':
            return { status: 'removed', label: 'Deleting', pulse: true };
        default:
            if (!server.agent || server.agent.status === 'revoked') return { status: 'inactive', label: 'No agent' };

            return server.agent.status === 'online' ? { status: 'online', label: 'Online' } : { status: 'offline', label: 'Offline' };
    }
}

export function ServerStatusBadge({ server, className }: { server: Pick<ServerHeader, 'status' | 'provider' | 'agent'>; className?: string }) {
    const state = serverState(server);

    return <StatusBadge status={state.status} label={state.label} tone={state.tone} pulse={state.pulse} className={className} />;
}

/** Tabs the current user may see. */
export function useServerTabs(): ServerTab[] {
    const { props } = usePage<SharedData>();

    return useMemo(() => {
        const ctx = shellContext(props);

        return SERVER_TABS.filter((tab) => !tab.permissions || tab.permissions.some((permission) => ctx.can(permission)));
    }, [props]);
}

interface ServerLayoutProps {
    server: ServerHeader;
    /** Active tab id (see SERVER_TABS). */
    tab: string;
    /** Primary action (+ `⋯` menu) in the header. */
    actions?: ReactNode;
    /** Props to refresh when the server broadcasts `server.updated` (defaults to `server`). */
    reloadOnly?: string[];
    children: ReactNode;
}

function isTypingTarget(target: EventTarget | null): boolean {
    return target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));
}

/**
 * Shell for all /servers/{id}/{tab} pages: breadcrumbs, live server header and the tab bar (`[` / `]` switch tabs).
 */
export default function ServerLayout({ server, tab, actions, reloadOnly, children }: ServerLayoutProps) {
    const tabs = useServerTabs();
    const base = `/servers/${server.id}`;
    const current = tabs.find((item) => item.id === tab) ?? tabs[0];
    const hrefOf = (item: ServerTab) => (item.path ? `${base}/${item.path}` : base);
    const reloadKey = (reloadOnly ?? ['server']).join(',');

    useEchoChannel(`servers.${server.id}`, ['server.updated'], () => router.reload({ only: reloadKey.split(',') }));

    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if ((event.key !== '[' && event.key !== ']') || event.metaKey || event.ctrlKey || event.altKey || isTypingTarget(event.target)) return;
            if (document.querySelector('[role="dialog"]')) return;
            const index = tabs.findIndex((item) => item.id === current.id);
            const next = tabs[(index + (event.key === ']' ? 1 : -1) + tabs.length) % tabs.length];
            event.preventDefault();
            router.visit(next.path ? `${base}/${next.path}` : base, { preserveScroll: false });
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [tabs, current.id, base]);

    const meta = [server.type_label, server.provider === 'custom' ? 'Custom server' : server.provider_label, server.region].filter(
        Boolean,
    ) as string[];

    return (
        <AppShell
            breadcrumbs={[
                { title: 'Infrastructure', href: '/servers' },
                { title: server.name, href: base },
                ...(current.id === 'overview' ? [] : [{ title: current.label, href: hrefOf(current) }]),
            ]}
        >
            <Head title={current.id === 'overview' ? server.name : `${current.label} · ${server.name}`} />
            <div className="grid gap-5">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex min-w-0 items-start gap-3">
                        <div className="border-border bg-surface-1 text-fg-muted flex size-10 shrink-0 items-center justify-center rounded-lg border">
                            <ServerIcon className="size-5" aria-hidden />
                        </div>
                        <div className="grid min-w-0 gap-1">
                            <div className="flex min-w-0 flex-wrap items-center gap-2">
                                <h1 className="text-fg truncate text-lg font-semibold">{server.name}</h1>
                                <ServerStatusBadge server={server} />
                            </div>
                            <p className="text-fg-muted flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5 text-sm">
                                {meta.map((item, index) => (
                                    <span key={item} className="flex items-center gap-2">
                                        {index > 0 && (
                                            <span className="text-fg-faint" aria-hidden>
                                                ·
                                            </span>
                                        )}
                                        {item}
                                    </span>
                                ))}
                                {server.ipv4 && (
                                    <span className="flex items-center gap-1">
                                        <span className="text-fg-faint" aria-hidden>
                                            ·
                                        </span>
                                        <span className="font-mono text-xs">{server.ipv4}</span>
                                        <CopyButton value={server.ipv4} label="Copy IP address" size="xs" />
                                    </span>
                                )}
                            </p>
                        </div>
                    </div>
                    {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
                </div>

                <nav aria-label="Server sections" className="-mx-4 overflow-x-auto px-4 md:mx-0 md:px-0">
                    <ul className="border-border flex min-w-max items-center gap-1 border-b">
                        {tabs.map((item) => (
                            <li key={item.id}>
                                <Link
                                    href={hrefOf(item)}
                                    prefetch
                                    preserveScroll
                                    aria-current={item.id === current.id ? 'page' : undefined}
                                    className={cn(tabTriggerClasses)}
                                >
                                    {item.label}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </nav>

                <div className="grid min-w-0 gap-6">{children}</div>
            </div>
        </AppShell>
    );
}
