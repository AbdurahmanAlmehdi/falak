/**
 * Browser side of the terminal relay (docs/plans/CONSOLE.md): get a ticket, open the WebSocket, reconnect on drops,
 * and give up — fall back to POST input + Reverb output — when the relay can't serve the session:
 *
 * - the ticket call fails, or the socket doesn't open within `openTimeoutMs`;
 * - the relay sends `error`;
 * - no `ready` within `readyTimeoutMs` of the socket opening (the server never attached);
 * - `waiting` after a `ready` (the server detached) for longer than `waitingTimeoutMs`;
 * - the socket drops `maxDrops` times within `dropWindowMs`.
 *
 * The relay is the output's source only while `live(true)`: from the replay (or `ready`) until `waiting` or a drop.
 */

export type RelayMessage =
    { t: 'ready' } | { t: 'waiting' } | { t: 'exit'; reason?: string; code?: number | null } | { t: 'error'; message?: string };

export interface RelayHandlers {
    /** A fresh client ticket URL (throws when the session has no relay any more). */
    ticket: () => Promise<string>;
    /** The replay is coming: clear the screen so a reconnect redraws instead of duplicating. */
    reset: () => void;
    write: (bytes: Uint8Array) => void;
    /** Whether the relay is the output's source (otherwise Reverb output is written). */
    live: (live: boolean) => void;
    state: (state: 'connecting' | 'relay') => void;
    notice: (message: string | null) => void;
    /** The open socket to send keystrokes and resizes on, or null. */
    socket: (socket: WebSocket | null) => void;
    /** The session ended (`exit`) and the relay closed the socket. */
    ended: () => void;
    /** Given up for good; nothing is called after this. */
    fallback: () => void;
}

export interface RelayOptions {
    openTimeoutMs: number;
    readyTimeoutMs: number;
    waitingTimeoutMs: number;
    maxDrops: number;
    dropWindowMs: number;
    createSocket: (url: string) => WebSocket;
}

export const RELAY_DEFAULTS: RelayOptions = {
    openTimeoutMs: 5000,
    readyTimeoutMs: 8000,
    waitingTimeoutMs: 10_000,
    maxDrops: 3,
    dropWindowMs: 60_000,
    createSocket: (url) => new WebSocket(url, 'falak.terminal.v1'),
};

/** Connect; returns a dispose function (closes the socket, no callbacks afterwards). */
export function openRelay(handlers: RelayHandlers, overrides: Partial<RelayOptions> = {}): () => void {
    const options = { ...RELAY_DEFAULTS, ...overrides };
    let active = true;
    let drops: number[] = [];
    let current: WebSocket | null = null;
    let openTimer: ReturnType<typeof setTimeout> | undefined;
    let readyTimer: ReturnType<typeof setTimeout> | undefined;
    let waitingTimer: ReturnType<typeof setTimeout> | undefined;
    let retryTimer: ReturnType<typeof setTimeout> | undefined;

    const clearTimers = () => {
        clearTimeout(openTimer);
        clearTimeout(readyTimer);
        clearTimeout(waitingTimer);
        clearTimeout(retryTimer);
        waitingTimer = undefined;
    };

    const stop = () => {
        active = false;
        clearTimers();
        const socket = current;
        current = null;
        socket?.close();
    };

    const fallback = () => {
        if (!active) return;
        stop();
        handlers.live(false);
        handlers.socket(null);
        handlers.notice(null);
        handlers.fallback();
    };

    const connect = async () => {
        let url: string;

        try {
            url = await handlers.ticket();
        } catch {
            fallback();

            return;
        }

        if (!active) return;

        let ws: WebSocket;
        try {
            ws = options.createSocket(url);
        } catch {
            fallback();

            return;
        }

        current = ws;
        ws.binaryType = 'arraybuffer';
        let opened = false;
        let ended = false;
        let readySeen = false;
        let awaitingReplay = true;

        openTimer = setTimeout(() => !opened && fallback(), options.openTimeoutMs);

        ws.onopen = () => {
            if (current !== ws) return;
            opened = true;
            clearTimeout(openTimer);
            handlers.socket(ws);
            readyTimer = setTimeout(() => !readySeen && fallback(), options.readyTimeoutMs);
        };

        ws.onmessage = (event: MessageEvent<ArrayBuffer | string>) => {
            if (current !== ws) return;

            if (typeof event.data !== 'string') {
                // The first binary frame after connecting is the replay.
                if (awaitingReplay) {
                    awaitingReplay = false;
                    handlers.reset();
                    handlers.live(true);
                }
                handlers.write(new Uint8Array(event.data));

                return;
            }

            let message: RelayMessage;
            try {
                message = JSON.parse(event.data) as RelayMessage;
            } catch {
                return;
            }

            if (message.t === 'ready') {
                readySeen = true;
                awaitingReplay = false;
                clearTimeout(readyTimer);
                clearTimeout(waitingTimer);
                waitingTimer = undefined;
                handlers.live(true);
                handlers.state('relay');
                handlers.notice(null);
            } else if (message.t === 'waiting') {
                handlers.live(false);
                handlers.state('connecting');
                handlers.notice('Waiting for the server to attach…');

                // Before the first `ready` the ready timeout applies; after it, the server must come back soon.
                if (readySeen && waitingTimer === undefined) {
                    waitingTimer = setTimeout(fallback, options.waitingTimeoutMs);
                }
            } else if (message.t === 'exit') {
                ended = true;
                handlers.notice(null);
            } else if (message.t === 'error') {
                fallback();
            }
        };

        ws.onclose = () => {
            if (current !== ws || !active) return;
            current = null;
            clearTimers();
            handlers.socket(null);

            if (!opened) {
                fallback();

                return;
            }

            if (ended) {
                // The relay showed all the output; it stays the source so late Reverb parts aren't written twice.
                active = false;
                handlers.ended();

                return;
            }

            handlers.live(false);
            handlers.state('connecting');
            const now = Date.now();
            drops = [...drops.filter((at) => now - at < options.dropWindowMs), now];

            if (drops.length >= options.maxDrops) {
                fallback();

                return;
            }

            retryTimer = setTimeout(() => void connect(), 500 * drops.length);
        };
    };

    void connect();

    return stop;
}

/**
 * Page through recorded frames until there are no more, unless a newer catch-up started meanwhile (`isStale`): a
 * fallback restarts from the first frame, and an older loop must not keep writing or move the cursor.
 *
 * @returns whether it finished (and was still current)
 */
export async function pageFrames<T extends { more: boolean }>(
    fetchPage: () => Promise<T | null>,
    isStale: () => boolean,
    apply: (page: T) => void,
): Promise<boolean> {
    let more = true;

    while (more) {
        const page = await fetchPage();

        if (page === null || isStale()) {
            return false;
        }

        apply(page);
        more = page.more;
    }

    return !isStale();
}
