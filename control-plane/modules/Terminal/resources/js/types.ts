export type SessionStatus = 'opening' | 'open' | 'closed' | 'failed';

export type CloseReason = 'exited' | 'closed' | 'idle' | 'timeout' | 'failed' | 'server_deleted';

export type TargetType = 'server' | 'site' | 'database';

export interface TerminalSessionData {
    id: string;
    server_id: string;
    server_name: string;
    /** Empty for container consoles. */
    unix_user: string;
    target_type: TargetType;
    /** The site or database instance id (null for server shells). */
    target_id: string | null;
    target_name: string | null;
    /** `docker:<name>` | `compose:<service>`; null for host shells. */
    container: string | null;
    /** The agent was given a relay token: the page talks to the terminal relay over a WebSocket. */
    relayed: boolean;
    status: SessionStatus;
    close_reason: CloseReason | null;
    exit_code: number | null;
    error: string | null;
    cols: number;
    rows: number;
    shared: boolean;
    channel_epoch: number;
    owner: { id: string; name: string };
    recording_bytes: number;
    created_at: string;
    closed_at: string | null;
    duration_s: number | null;
}

export interface SessionUpdate {
    id: string;
    status: SessionStatus;
    close_reason: CloseReason | null;
    exit_code: number | null;
    error: string | null;
    cols: number;
    rows: number;
    shared: boolean;
    channel_epoch: number;
}

export interface OutputPart {
    seq: number;
    part: number;
    parts: number;
    data: string;
}

export interface Participant {
    id: string;
    name: string;
    can_type: boolean;
}
