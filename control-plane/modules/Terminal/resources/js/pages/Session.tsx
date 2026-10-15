import { AppShell } from '@/components/falak/app-shell';
import { Avatar } from '@/components/falak/avatar';
import { Button } from '@/components/falak/button';
import { Tag } from '@/components/falak/tag';
import { toast } from '@/components/falak/toast';
import { Tooltip } from '@/components/falak/tooltip';
import { echo } from '@/lib/echo';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { FitAddon } from '@xterm/addon-fit';
import { Terminal } from '@xterm/xterm';
import '@xterm/xterm/css/xterm.css';
import { Eye, Film, Power, Share2, Zap } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { SessionStatusBadge } from '../components/session-status';
import { base64ToBytes, encodeInput, inputBytes, onThemeChange, postJson, REASON_LABELS, targetLabel, TERMINAL_FONT, terminalTheme } from '../lib';
import { openRelay, pageFrames } from '../relay';
import { type OutputPart, type Participant, type SessionUpdate, type TerminalSessionData } from '../types';

interface Props {
    session: TerminalSessionData;
    isOwner: boolean;
    idleTimeout: number;
    inputMaxBytes: number;
    can: { type: boolean; close: boolean; share: boolean; replay: boolean };
}

interface FramesResponse {
    frames: { id: number; seq: number | null; data: string }[];
    last_id: number;
    more: boolean;
    status: TerminalSessionData['status'];
}

/** Keystrokes typed within this window are sent together. */
const INPUT_BATCH_MS = 8;
/** Flush early once this many characters are pending (UTF-8 ≤ 3 bytes/char keeps batches well under the server limit). */
const INPUT_BATCH_CHARS = 4096;

const isLive = (status: TerminalSessionData['status']) => status === 'opening' || status === 'open';

/** The relay takes at most 64 KiB of input per message. */
const RELAY_INPUT_CHUNK = 32 * 1024;

/** How the page talks to the session: the relay WebSocket (or waiting for it), or POST input + Reverb output. */
type Transport = 'relay' | 'connecting' | 'reverb';

export default function Session({ session: initial, isOwner, can }: Props) {
    const [session, setSession] = useState<TerminalSessionData>(initial);
    const [participants, setParticipants] = useState<Participant[]>([]);
    const [connected, setConnected] = useState(false);
    const [inputError, setInputError] = useState<string | null>(null);
    const [transport, setTransport] = useState<Transport>(initial.relayed && isLive(initial.status) ? 'connecting' : 'reverb');
    const [relayNotice, setRelayNotice] = useState<string | null>(null);
    const container = useRef<HTMLDivElement>(null);
    const terminal = useRef<Terminal | null>(null);
    const live = isLive(session.status);
    const canType = can.type && live;
    const canTypeRef = useRef(canType);
    canTypeRef.current = canType;
    const statusRef = useRef(session.status);
    statusRef.current = session.status;

    const label = targetLabel(session);
    const title = session.target_type === 'server' ? label : `Console · ${label}`;
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Infrastructure', href: '/servers' },
        { title: session.server_name, href: `/servers/${session.server_id}` },
        { title: 'Terminal', href: `/servers/${session.server_id}/terminal` },
        { title: label, href: route('terminal.sessions.show', session.id) },
    ];

    // Relay (docs/plans/CONSOLE.md): `relayActive` until the page falls back to POST + Reverb for good; `relayLive`
    // while the relay is ready (the server attached) and so the output's source — Reverb parts and catch-up frames are
    // then only tracked, not written. Before `ready` and while the relay waits for the server, Reverb output is written:
    // the replay on connect resets the screen, and falling back redraws it from the recording.
    const relayActive = useRef(initial.relayed && isLive(initial.status));
    const relayLive = useRef(false);
    const socket = useRef<WebSocket | null>(null);
    // A fallback restarts the catch-up from the first frame; loops of an older generation stop.
    const catchUpGeneration = useRef(0);

    // ---- output: catch-up through /frames, then live parts over the presence channel ----------------
    const lastSeq = useRef(-1);
    const lastPart = useRef(Number.MAX_SAFE_INTEGER);
    const caughtUp = useRef(false);
    const buffered = useRef<OutputPart[]>([]);
    const cursor = useRef(0);

    // Output (or the relay's `ready`) means the shell is open: the `open` broadcast may have gone out before this page
    // joined the channel, and the PTY is synced to the fitted size only once the page sees `open`.
    const markOpen = useCallback(() => setSession((current) => (current.status === 'opening' ? { ...current, status: 'open' } : current)), []);

    const writePart = useCallback(
        (part: OutputPart) => {
            if (part.seq < lastSeq.current || (part.seq === lastSeq.current && part.part <= lastPart.current)) {
                return;
            }

            lastSeq.current = part.seq;
            lastPart.current = part.parts - 1 === part.part ? Number.MAX_SAFE_INTEGER : part.part;
            markOpen();

            if (!relayLive.current) {
                terminal.current?.write(base64ToBytes(part.data));
            }
        },
        [markOpen],
    );

    const catchUp = useCallback(async () => {
        const generation = catchUpGeneration.current;
        const finished = await pageFrames<FramesResponse>(
            async () => {
                const response = await fetch(`${route('terminal.sessions.frames', initial.id)}?after=${cursor.current}`, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });

                return response.ok ? ((await response.json()) as FramesResponse) : null;
            },
            () => generation !== catchUpGeneration.current,
            (body) => {
                body.frames.forEach((frame) => writePart({ seq: frame.seq ?? lastSeq.current + 1, part: 0, parts: 1, data: frame.data }));
                cursor.current = body.last_id;

                if (body.status === 'open') markOpen();
                if (!body.more && !isLive(body.status)) {
                    setSession((current) => (isLive(current.status) ? { ...current, status: body.status } : current));
                }
            },
        );

        if (finished) {
            caughtUp.current = true;
            buffered.current.splice(0).forEach(writePart);
        }
    }, [initial.id, writePart, markOpen]);

    // ---- terminal setup -----------------------------------------------------------------------------
    const sendResize = useRef<(cols: number, rows: number) => void>(() => undefined);
    const queueInput = useRef<(text: string, binary: boolean) => void>(() => undefined);

    useEffect(() => {
        if (!container.current) return;

        const term = new Terminal({
            cursorBlink: true,
            fontFamily: TERMINAL_FONT,
            fontSize: 13,
            lineHeight: 1.2,
            scrollback: 5000,
            theme: terminalTheme(),
            disableStdin: !canTypeRef.current,
        });
        const stopTheme = onThemeChange(() => {
            term.options.theme = terminalTheme();
        });
        const fit = new FitAddon();
        term.loadAddon(fit);
        term.open(container.current);
        terminal.current = term;

        try {
            fit.fit();
        } catch {
            // container not measurable yet
        }

        const data = term.onData((text) => canTypeRef.current && queueInput.current(text, false));
        const binary = term.onBinary((text) => canTypeRef.current && queueInput.current(text, true));
        const resized = term.onResize(({ cols, rows }) => sendResize.current(cols, rows));

        let fitTimer: number | undefined;
        const observer = new ResizeObserver(() => {
            window.clearTimeout(fitTimer);
            fitTimer = window.setTimeout(() => {
                try {
                    fit.fit();
                } catch {
                    // detached
                }
            }, 50);
        });
        observer.observe(container.current);

        if (canTypeRef.current) {
            term.focus();
        }

        return () => {
            stopTheme();
            window.clearTimeout(fitTimer);
            observer.disconnect();
            data.dispose();
            binary.dispose();
            resized.dispose();
            term.dispose();
            terminal.current = null;
        };
    }, []);

    useEffect(() => {
        if (terminal.current) {
            terminal.current.options.disableStdin = !canType;
            terminal.current.options.cursorBlink = canType;
        }
    }, [canType]);

    // ---- live channel ---------------------------------------------------------------------------------
    useEffect(() => {
        const client = echo();
        const name = `terminal.sessions.${initial.id}.${session.channel_epoch}`;

        if (!client) {
            void catchUp();

            return;
        }

        const channel = client.join(name);
        channel
            .here((users: Participant[]) => {
                setParticipants(users);
                setConnected(true);
                void catchUp();
            })
            .joining((user: Participant) => setParticipants((current) => [...current.filter((u) => u.id !== user.id), user]))
            .leaving((user: Participant) => setParticipants((current) => current.filter((u) => u.id !== user.id)))
            .listen('.terminal.output', (part: OutputPart) => (caughtUp.current ? writePart(part) : buffered.current.push(part)))
            .listen('.terminal.session.updated', (update: SessionUpdate) => setSession((current) => ({ ...current, ...update })));

        return () => {
            client.leave(name);
        };
    }, [initial.id, session.channel_epoch, catchUp, writePart]);

    // Without Reverb, poll for new frames while the session is live.
    useEffect(() => {
        if (connected || !live) return;

        const timer = window.setInterval(() => void catchUp(), 1000);

        return () => window.clearInterval(timer);
    }, [connected, live, catchUp]);

    // ---- relay: ticket → WebSocket (binary frames both ways), with fallback to POST + Reverb ----------------
    useEffect(() => {
        if (!relayActive.current) return;

        return openRelay({
            ticket: async () => {
                const response = await postJson(route('terminal.sessions.ticket', initial.id), {});

                if (!response.ok) throw new Error(`HTTP ${response.status}`);

                return ((await response.json()) as { url: string }).url;
            },
            reset: () => terminal.current?.reset(),
            write: (bytes) => {
                markOpen();
                terminal.current?.write(bytes);
            },
            live: (live) => {
                relayLive.current = live;
                if (live) markOpen();
            },
            state: setTransport,
            notice: setRelayNotice,
            socket: (ws) => {
                socket.current = ws;
            },
            // The relay showed all the output; the final status comes from Reverb or the frames endpoint.
            ended: () => void catchUp(),
            fallback: () => {
                relayActive.current = false;
                setTransport('reverb');
                // Redraw from the recording; live parts wait for this catch-up, older loops stop.
                terminal.current?.reset();
                lastSeq.current = -1;
                lastPart.current = Number.MAX_SAFE_INTEGER;
                cursor.current = 0;
                catchUpGeneration.current += 1;
                caughtUp.current = false;
                buffered.current = [];
                void catchUp();
            },
        });
    }, [initial.id, catchUp, markOpen]);

    /** Send on the relay socket when it's open (false: use the POST path). */
    const relaySend = (data: string | Uint8Array): boolean => {
        const ws = socket.current;

        if (!ws || ws.readyState !== WebSocket.OPEN) return false;
        ws.send(data);

        return true;
    };

    // ---- input: batched, one request in flight, ordered by seq ------------------------------------------
    const pending = useRef<{ text: string; binary: boolean }[]>([]);
    const pendingChars = useRef(0);
    const inFlight = useRef(false);
    const seq = useRef(0);
    // Per-page input stream: seq restarts at 0 on reload, so keys must not collide with earlier pages.
    const stream = useRef(crypto.randomUUID());
    const flushTimer = useRef<number | undefined>(undefined);

    const flush = useCallback(async () => {
        window.clearTimeout(flushTimer.current);
        flushTimer.current = undefined;

        if (inFlight.current || pending.current.length === 0) return;

        const batch = pending.current.splice(0);
        pendingChars.current = 0;
        inFlight.current = true;
        const body = { data: encodeInput(batch), seq: seq.current++, stream: stream.current };

        try {
            for (let attempt = 0; attempt < 3; attempt++) {
                let response: Response;

                try {
                    response = await postJson(route('terminal.sessions.input', initial.id), body);
                } catch {
                    // Network hiccup: retry with the same seq (the server dedupes on it).
                    await new Promise((resolve) => window.setTimeout(resolve, 200 * (attempt + 1)));
                    continue;
                }

                if (response.status === 429) {
                    await new Promise((resolve) => window.setTimeout(resolve, 250 * (attempt + 1)));
                    continue;
                }

                setInputError(
                    response.ok ? null : response.status === 409 ? 'The session is closed.' : `Input was rejected (HTTP ${response.status}).`,
                );
                break;
            }
        } finally {
            inFlight.current = false;

            if (pending.current.length > 0) {
                void flush();
            }
        }
    }, [initial.id]);

    queueInput.current = (text: string, binary: boolean) => {
        // Relay: keystrokes go out at once as binary frames (in order, over one socket).
        if (socket.current?.readyState === WebSocket.OPEN && pending.current.length === 0 && !inFlight.current) {
            const bytes = inputBytes([{ text, binary }]);

            for (let offset = 0; offset < bytes.length; offset += RELAY_INPUT_CHUNK) {
                relaySend(bytes.subarray(offset, offset + RELAY_INPUT_CHUNK));
            }

            return;
        }

        // Split big pastes so each request stays under the server's batch limit.
        for (let offset = 0; offset < text.length; offset += INPUT_BATCH_CHARS) {
            const slice = text.slice(offset, offset + INPUT_BATCH_CHARS);
            pending.current.push({ text: slice, binary });
            pendingChars.current += slice.length;

            if (pendingChars.current >= INPUT_BATCH_CHARS) {
                void flush();
            }
        }

        if (flushTimer.current === undefined) {
            flushTimer.current = window.setTimeout(() => void flush(), INPUT_BATCH_MS);
        }
    };

    const resizeTimer = useRef<number | undefined>(undefined);
    sendResize.current = (cols: number, rows: number) => {
        // While opening there is no PTY to resize yet (the recorded size would then match and skip the sync at open).
        if (!canTypeRef.current || statusRef.current === 'opening') return;

        // The relay resizes the PTY at once; the POST below still records the size (recording, other viewers).
        relaySend(JSON.stringify({ t: 'resize', cols, rows }));
        window.clearTimeout(resizeTimer.current);
        resizeTimer.current = window.setTimeout(() => {
            void postJson(route('terminal.sessions.resize', initial.id), { cols, rows });
        }, 250);
    };

    // Once the PTY is open, sync it to the fitted size.
    const syncedOpen = useRef(false);
    useEffect(() => {
        if (session.status === 'open' && !syncedOpen.current && terminal.current) {
            syncedOpen.current = true;
            const { cols, rows } = terminal.current;

            if (cols !== session.cols || rows !== session.rows) {
                sendResize.current(cols, rows);
            }
        }
    }, [session.status, session.cols, session.rows]);

    const [busy, setBusy] = useState<'share' | 'close' | null>(null);
    const toggleShare = () =>
        router.patch(
            route('terminal.sessions.share', session.id),
            { shared: !session.shared },
            {
                preserveScroll: true,
                onStart: () => setBusy('share'),
                onFinish: () => setBusy(null),
                onSuccess: () =>
                    toast.success(
                        session.shared ? 'Stopped sharing' : 'Session shared',
                        session.shared ? undefined : 'Teammates with terminal access can watch.',
                    ),
            },
        );
    const close = () =>
        router.delete(route('terminal.sessions.destroy', session.id), {
            preserveScroll: true,
            onStart: () => setBusy('close'),
            onFinish: () => setBusy(null),
        });

    return (
        <AppShell breadcrumbs={breadcrumbs}>
            <Head
                title={
                    session.target_type === 'server' ? `Terminal · ${session.server_name}` : `Console · ${session.target_name ?? session.server_name}`
                }
            />
            <div className="flex flex-col gap-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex min-w-0 flex-wrap items-center gap-2.5">
                        <h1 className="text-fg truncate font-mono text-base font-semibold">{title}</h1>
                        <SessionStatusBadge status={session.status} />
                        {live && transport === 'relay' && (
                            <Tooltip content="Keystrokes and output go straight through the terminal relay.">
                                <Tag tone="accent" icon={<Zap />}>
                                    Relay
                                </Tag>
                            </Tooltip>
                        )}
                        {session.shared && (
                            <Tag tone="accent" icon={<Share2 />}>
                                Shared
                            </Tag>
                        )}
                        {!can.type && live && <Tag icon={<Eye />}>Watching</Tag>}
                        {!isOwner && <span className="text-fg-muted text-sm">Owner: {session.owner.name}</span>}
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {participants.length > 0 && (
                            <div className="flex -space-x-1.5" aria-label={`Connected: ${participants.map((p) => p.name).join(', ')}`}>
                                {participants.slice(0, 5).map((participant) => (
                                    <Tooltip key={participant.id} content={`${participant.name}${participant.can_type ? '' : ' (watching)'}`}>
                                        <span className="ring-bg rounded-full ring-2">
                                            <Avatar name={participant.name} size="sm" />
                                        </span>
                                    </Tooltip>
                                ))}
                            </div>
                        )}
                        {can.share && live && (
                            <Button icon={<Share2 />} onClick={toggleShare} loading={busy === 'share'}>
                                {session.shared ? 'Stop sharing' : 'Share'}
                            </Button>
                        )}
                        {can.replay && !live && (
                            <Button asChild>
                                <Link href={route('terminal.sessions.recording', session.id)}>
                                    <Film aria-hidden /> Replay
                                </Link>
                            </Button>
                        )}
                        {can.close && live && (
                            <Button variant="danger" icon={<Power />} onClick={close} loading={busy === 'close'}>
                                Close
                            </Button>
                        )}
                    </div>
                </div>

                {!live && (
                    <div
                        role={session.status === 'failed' ? 'alert' : 'status'}
                        className={
                            session.status === 'failed'
                                ? 'border-danger/40 bg-danger-soft rounded-lg border px-4 py-3'
                                : 'border-border bg-surface-1 rounded-lg border px-4 py-3'
                        }
                    >
                        <p className={session.status === 'failed' ? 'text-danger text-sm font-medium' : 'text-fg text-sm font-medium'}>
                            {session.close_reason ? (REASON_LABELS[session.close_reason] ?? session.close_reason) : 'Session ended'}
                        </p>
                        <p className="text-fg-muted text-sm">
                            {session.error ?? (session.exit_code !== null ? `Exit code ${session.exit_code}.` : 'The session is no longer running.')}
                        </p>
                    </div>
                )}
                {inputError && live && <p className="text-danger text-sm">{inputError}</p>}
                {relayNotice && live && (
                    <p className="text-fg-muted text-sm" aria-live="polite">
                        {relayNotice}
                    </p>
                )}
                {session.status === 'opening' && (
                    <p className="text-fg-muted flex items-center gap-2 text-sm" aria-live="polite">
                        <span className="animate-pulse-dot bg-warning text-warning size-1.5 rounded-full" aria-hidden />
                        Waiting for the agent to open the shell…
                    </p>
                )}
                {session.shared && isOwner && live && (
                    <p className="text-fg-faint text-xs">
                        Members with terminal access can watch this session; only you and administrators can type.
                    </p>
                )}

                <div className="border-border bg-canvas overflow-hidden rounded-lg border p-2">
                    <div ref={container} className="h-[70vh] min-h-80 w-full" data-testid="terminal" />
                </div>
            </div>
        </AppShell>
    );
}
