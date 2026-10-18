import { type SharedData } from '@/types';

/** The `falakUpdate` shared prop: only the install's operators (owners / admins of its operator organization) get it. */
export interface FalakUpdateShared {
    current: string | null;
    latest: string | null;
    update_available: boolean;
    settings_url: string;
}

export function falakUpdateOf(props: SharedData): FalakUpdateShared | null {
    return (props.falakUpdate as FalakUpdateShared | null | undefined) ?? null;
}

/** The cached GitHub release (ReleaseCheck). `notes` is plain text: render it as text, never as HTML. */
export interface Release {
    tag: string | null;
    url: string | null;
    published_at: string | null;
    notes: string | null;
    checked_at: string;
    error: string | null;
}

export type HostAction = 'update' | 'local-server';

/** HostChannel::state(): request.json not picked up yet (`pending`) and the host's status.json (`status`). */
export interface HostChannelState {
    /** The directory is mounted; `available` also needs falak-ctl's channel.json (sudo falak-ctl up once). */
    mounted: boolean;
    available: boolean;
    busy: boolean;
    pending: { id: string; action: HostAction; requested_at: string | null; stale: boolean } | null;
    status: {
        id: string;
        action: HostAction;
        state: 'running' | 'succeeded' | 'failed';
        started_at: string | null;
        finished_at: string | null;
        exit_code: number | null;
        /** Why the host refused the request. */
        error: string | null;
        /** Still "running" after 2 h: the host most likely died mid-run; it no longer blocks new requests. */
        stale: boolean;
    } | null;
}

/** GET /settings/falak-updates/status. */
export interface HostStatusResponse {
    current: string | null;
    channel: HostChannelState;
    log: string | null;
}

/** The request the page follows: a pending one is newer than the last status. */
export function currentRequest(
    channel: HostChannelState,
): { id: string; action: HostAction; state: 'pending' | 'running' | 'succeeded' | 'failed' } | null {
    if (channel.pending && channel.pending.id !== channel.status?.id) {
        return { id: channel.pending.id, action: channel.pending.action, state: 'pending' };
    }

    return channel.status ? { id: channel.status.id, action: channel.status.action, state: channel.status.state } : null;
}
