import { DataTable, type DataTableColumn } from '@/components/falak/data-table';
import { type EmptyStateProps } from '@/components/falak/empty-state';
import { RelativeTime } from '@/components/falak/relative-time';
import { Tag } from '@/components/falak/tag';
import { type SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { Box, Database, Download, Film, Share2, SquareTerminal } from 'lucide-react';
import { formatBytes, formatDuration, REASON_LABELS, targetLabel } from '../lib';
import { type TerminalSessionData } from '../types';
import { SessionStatusBadge } from './session-status';

const target = (session: TerminalSessionData) => (
    <span className="flex min-w-0 items-center gap-2 py-1.5">
        {session.target_type === 'database' ? (
            <Database className="text-fg-faint size-4 shrink-0" aria-hidden />
        ) : session.target_type === 'site' ? (
            <Box className="text-fg-faint size-4 shrink-0" aria-hidden />
        ) : (
            <SquareTerminal className="text-fg-faint size-4 shrink-0" aria-hidden />
        )}
        <span className="truncate font-mono text-xs">{targetLabel(session)}</span>
    </span>
);

/** On a server's own tab: the user of a shell, or the console's target without the server. */
const shortLabel = (session: TerminalSessionData) =>
    session.target_type === 'server' ? session.unix_user : targetLabel(session).replace(` on ${session.server_name}`, '');

/** Live sessions the user owns or can attach to. */
export function LiveSessionsTable({
    sessions,
    showServer = true,
    empty,
}: {
    sessions: TerminalSessionData[];
    showServer?: boolean;
    empty: EmptyStateProps;
}) {
    const columns: DataTableColumn<TerminalSessionData>[] = [
        {
            id: 'session',
            header: 'Session',
            cell: (session) => (
                <Link href={route('terminal.sessions.show', session.id)} className="hover:underline" onClick={(event) => event.stopPropagation()}>
                    {showServer ? target(session) : <span className="font-mono text-xs">{shortLabel(session)}</span>}
                </Link>
            ),
        },
        { id: 'status', header: 'Status', cell: (session) => <SessionStatusBadge status={session.status} /> },
        {
            id: 'owner',
            header: 'Owner',
            hideOnMobile: true,
            cell: (session) => (
                <span className="text-fg-muted flex items-center gap-2 text-xs">
                    {session.owner.name}
                    {session.shared && (
                        <Tag tone="accent" icon={<Share2 />}>
                            shared
                        </Tag>
                    )}
                </span>
            ),
        },
        {
            id: 'started',
            header: 'Started',
            align: 'right',
            sortValue: (session) => session.created_at,
            cell: (session) => <RelativeTime value={session.created_at} className="text-fg-muted text-xs" />,
        },
    ];

    return (
        <DataTable
            label="Live sessions"
            rows={sessions}
            rowKey={(session) => session.id}
            columns={columns}
            onRowClick={(session) => router.visit(route('terminal.sessions.show', session.id))}
            empty={empty}
        />
    );
}

/** Closed sessions with their asciicast recordings. */
export function RecordingsTable({
    recordings,
    showServer = true,
    canReplayAll,
    empty,
}: {
    recordings: TerminalSessionData[];
    showServer?: boolean;
    canReplayAll: boolean;
    empty: EmptyStateProps;
}) {
    const me = usePage<SharedData>().props.auth.user.id;
    const canReplay = (session: TerminalSessionData) => (canReplayAll || session.owner.id === me) && session.recording_bytes > 0;

    const columns: DataTableColumn<TerminalSessionData>[] = [
        {
            id: 'session',
            header: 'Session',
            cell: (session) => {
                const label = showServer ? target(session) : <span className="font-mono text-xs">{shortLabel(session)}</span>;

                return canReplay(session) ? (
                    <Link
                        href={route('terminal.sessions.recording', session.id)}
                        className="hover:underline"
                        onClick={(event) => event.stopPropagation()}
                    >
                        {label}
                    </Link>
                ) : (
                    label
                );
            },
        },
        { id: 'owner', header: 'Owner', hideOnMobile: true, cell: (session) => <span className="text-fg-muted text-xs">{session.owner.name}</span> },
        {
            id: 'ended',
            header: 'Ended',
            hideOnMobile: true,
            cell: (session) => (
                <span className="text-fg-muted text-xs">
                    {session.status === 'failed'
                        ? (session.error ?? 'Failed')
                        : session.close_reason
                          ? (REASON_LABELS[session.close_reason] ?? session.close_reason)
                          : '—'}
                </span>
            ),
        },
        {
            id: 'duration',
            header: 'Duration',
            align: 'right',
            sortValue: (session) => session.duration_s,
            cell: (session) => (session.duration_s !== null ? formatDuration(session.duration_s) : '—'),
        },
        {
            id: 'size',
            header: 'Size',
            align: 'right',
            hideOnMobile: true,
            sortValue: (session) => session.recording_bytes,
            cell: (session) => <span className="text-fg-muted">{formatBytes(session.recording_bytes)}</span>,
        },
        {
            id: 'when',
            header: 'When',
            align: 'right',
            sortValue: (session) => session.created_at,
            cell: (session) => <RelativeTime value={session.created_at} className="text-fg-muted text-xs" />,
        },
    ];

    return (
        <DataTable
            label="Recordings"
            rows={recordings}
            rowKey={(session) => session.id}
            columns={columns}
            defaultSort={{ column: 'when', direction: 'desc' }}
            onRowClick={(session) => canReplay(session) && router.visit(route('terminal.sessions.recording', session.id))}
            empty={empty}
            rowActions={(session) =>
                canReplay(session)
                    ? [
                          { label: 'Replay', icon: <Film />, href: route('terminal.sessions.recording', session.id) },
                          {
                              label: 'Download .cast',
                              icon: <Download />,
                              onSelect: () => window.location.assign(route('terminal.sessions.recording.cast', session.id)),
                          },
                      ]
                    : [{ label: session.recording_bytes > 0 ? 'Recording restricted' : 'No recording', disabled: true }]
            }
        />
    );
}
