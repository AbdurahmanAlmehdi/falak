import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { openRelay, pageFrames, type RelayHandlers } from './relay';

class FakeSocket {
    static all: FakeSocket[] = [];
    binaryType = 'blob';
    closed = false;
    onopen: (() => void) | null = null;
    onmessage: ((event: MessageEvent<ArrayBuffer | string>) => void) | null = null;
    onclose: (() => void) | null = null;

    constructor(public url: string) {
        FakeSocket.all.push(this);
    }

    open() {
        this.onopen?.();
    }

    text(message: object) {
        this.onmessage?.({ data: JSON.stringify(message) } as MessageEvent<string>);
    }

    binary(bytes: number[]) {
        this.onmessage?.({ data: Uint8Array.from(bytes).buffer } as MessageEvent<ArrayBuffer>);
    }

    close() {
        if (this.closed) return;
        this.closed = true;
        this.onclose?.();
    }
}

const last = () => FakeSocket.all[FakeSocket.all.length - 1];

function setup(ticket: () => Promise<string> = async () => 'wss://relay/sessions/s?ticket=t') {
    const calls: string[] = [];
    const handlers: RelayHandlers = {
        ticket,
        reset: () => calls.push('reset'),
        write: (bytes) => calls.push(`write:${Array.from(bytes).join(',')}`),
        live: (live) => calls.push(`live:${live}`),
        state: (state) => calls.push(`state:${state}`),
        notice: () => undefined,
        socket: (socket) => calls.push(socket ? 'socket' : 'no-socket'),
        ended: () => calls.push('ended'),
        fallback: () => calls.push('fallback'),
    };
    const dispose = openRelay(handlers, { createSocket: (url) => new FakeSocket(url) as unknown as WebSocket });

    return { calls, dispose };
}

/** Let the ticket promise resolve. */
const flush = async () => {
    await Promise.resolve();
    await Promise.resolve();
};

beforeEach(() => {
    vi.useFakeTimers();
    FakeSocket.all = [];
});

afterEach(() => {
    vi.useRealTimers();
});

describe('openRelay', () => {
    it('resets on the replay, goes live on ready and writes output', async () => {
        const { calls } = setup();
        await flush();
        last().open();
        last().binary([1, 2]);
        last().text({ t: 'ready' });
        last().binary([3]);

        expect(calls).toEqual(['socket', 'reset', 'live:true', 'write:1,2', 'live:true', 'state:relay', 'write:3']);
    });

    it('falls back when the ticket call fails', async () => {
        const { calls } = setup(async () => {
            throw new Error('HTTP 404');
        });
        await flush();

        expect(calls).toContain('fallback');
        expect(FakeSocket.all).toHaveLength(0);
    });

    it('falls back when the socket does not open in 5 s', async () => {
        const { calls } = setup();
        await flush();
        vi.advanceTimersByTime(5000);

        expect(calls).toContain('fallback');
        expect(last().closed).toBe(true);
    });

    it('falls back on an error frame', async () => {
        const { calls } = setup();
        await flush();
        last().open();
        last().text({ t: 'error', message: 'The server did not attach to this session.' });

        expect(calls[calls.length - 1]).toBe('fallback');
        expect(last().closed).toBe(true);
    });

    it('falls back when the server never attaches (no ready within 8 s), even while waiting', async () => {
        const { calls } = setup();
        await flush();
        last().open();
        last().text({ t: 'waiting' });
        vi.advanceTimersByTime(7999);
        expect(calls).not.toContain('fallback');

        vi.advanceTimersByTime(1);
        expect(calls).toContain('fallback');
    });

    it('stops being live while waiting after ready, resumes on ready, and falls back after 10 s of waiting', async () => {
        const { calls } = setup();
        await flush();
        last().open();
        last().text({ t: 'ready' });
        vi.advanceTimersByTime(20_000); // ready: the ready timeout no longer applies

        last().text({ t: 'waiting' });
        expect(calls[calls.length - 2]).toBe('live:false');
        vi.advanceTimersByTime(9000);
        last().text({ t: 'ready' }); // the server came back
        vi.advanceTimersByTime(20_000);
        expect(calls).not.toContain('fallback');

        last().text({ t: 'waiting' });
        last().text({ t: 'waiting' }); // repeated waiting doesn't restart the clock
        vi.advanceTimersByTime(10_000);
        expect(calls).toContain('fallback');
    });

    it('reconnects with a new ticket on drops and falls back after 3 drops in a minute', async () => {
        let tickets = 0;
        const { calls } = setup(async () => `wss://relay/s?ticket=${++tickets}`);

        for (let drop = 1; drop <= 3; drop++) {
            await flush();
            last().open();
            last().text({ t: 'ready' });
            last().close();
            expect(calls.includes('fallback')).toBe(drop === 3);
            vi.advanceTimersByTime(500 * drop);
        }

        expect(tickets).toBe(3);
    });

    it('ends without falling back after exit', async () => {
        const { calls } = setup();
        await flush();
        last().open();
        last().text({ t: 'ready' });
        last().text({ t: 'exit', reason: 'exited', code: 0 });
        last().close();
        vi.advanceTimersByTime(60_000);

        expect(calls).toContain('ended');
        expect(calls).not.toContain('fallback');
        expect(FakeSocket.all).toHaveLength(1);
    });

    it('stays quiet after dispose', async () => {
        const { calls, dispose } = setup();
        await flush();
        last().open();
        dispose();
        vi.advanceTimersByTime(60_000);

        expect(calls).toEqual(['socket']);
        expect(last().closed).toBe(true);
    });
});

describe('pageFrames', () => {
    it('pages until done', async () => {
        const pages = [
            { more: true, n: 1 },
            { more: false, n: 2 },
        ];
        const applied: number[] = [];

        expect(
            await pageFrames(
                async () => pages.shift() ?? null,
                () => false,
                (page) => applied.push(page.n),
            ),
        ).toBe(true);
        expect(applied).toEqual([1, 2]);
    });

    it('stops a loop that a newer catch-up superseded', async () => {
        let generation = 0;
        const mine = generation;
        const applied: number[] = [];
        const pages = [
            { more: true, n: 1 },
            { more: true, n: 2 },
            { more: false, n: 3 },
        ];

        const done = await pageFrames(
            async () => {
                const page = pages.shift() ?? null;
                if (page?.n === 2) generation++; // a fallback restarted the catch-up meanwhile

                return page;
            },
            () => mine !== generation,
            (page) => applied.push(page.n),
        );

        expect(done).toBe(false);
        expect(applied).toEqual([1]);
    });
});
