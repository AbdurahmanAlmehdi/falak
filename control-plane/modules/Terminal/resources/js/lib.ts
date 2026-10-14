import { type TerminalSessionData } from './types';

/** Decode base64 (raw PTY bytes) into a byte array for xterm.write(). */
export function base64ToBytes(data: string): Uint8Array {
    const binary = atob(data);
    const bytes = new Uint8Array(binary.length);

    for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
    }

    return bytes;
}

/** Terminal input (a JS string from xterm onData, or a binary string from onBinary) as bytes: UTF-8 / raw. */
export function inputBytes(chunks: { text: string; binary: boolean }[]): Uint8Array {
    const encoder = new TextEncoder();
    const parts: number[] = [];

    for (const chunk of chunks) {
        if (chunk.binary) {
            for (let i = 0; i < chunk.text.length; i++) {
                parts.push(chunk.text.charCodeAt(i) & 0xff);
            }
        } else {
            encoder.encode(chunk.text).forEach((byte) => parts.push(byte));
        }
    }

    return Uint8Array.from(parts);
}

/** Encode terminal input as base64 of its bytes (the POST input path). */
export function encodeInput(chunks: { text: string; binary: boolean }[]): string {
    let binary = '';
    for (const byte of inputBytes(chunks)) {
        binary += String.fromCharCode(byte);
    }

    return btoa(binary);
}

/**
 * What a session runs in, for headers and tables: `root@web-1` for server shells, `shop (container web) on main-1` for
 * consoles (docs/plans/CONSOLE.md).
 */
export function targetLabel(session: Pick<TerminalSessionData, 'target_type' | 'target_name' | 'container' | 'unix_user' | 'server_name'>): string {
    if (session.target_type === 'server') {
        return `${session.unix_user}@${session.server_name}`;
    }

    const service = session.container?.startsWith('compose:') ? session.container.slice('compose:'.length) : null;
    const where = service ? ` (container ${service})` : session.target_type === 'site' && session.container ? ' (container)' : '';

    return `${session.target_name ?? session.target_type}${where} on ${session.server_name}`;
}

export function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

export async function postJson(url: string, body: unknown): Promise<Response> {
    return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body),
    });
}

export function formatBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 ** 2) return `${(bytes / 1024).toFixed(1)} KB`;

    return `${(bytes / 1024 ** 2).toFixed(1)} MB`;
}

export function formatDuration(seconds: number): string {
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = Math.floor(seconds % 60);

    return h > 0 ? `${h}h ${m}m` : m > 0 ? `${m}m ${s}s` : `${s}s`;
}

export const REASON_LABELS: Record<string, string> = {
    exited: 'Shell exited',
    closed: 'Closed',
    idle: 'Closed after inactivity',
    timeout: 'Maximum duration reached',
    failed: 'Failed',
    server_deleted: 'Server deleted',
};

/**
 * xterm.js theme built from the design tokens (resources/css/app.css): canvas background, text, accent cursor and
 * the --ansi-* palette, so terminals follow dark / light mode. Re-read it when the theme changes.
 */
export function terminalTheme(): Record<string, string> {
    if (typeof window === 'undefined') return {};
    const styles = getComputedStyle(document.documentElement);
    const token = (name: string, fallback: string) => styles.getPropertyValue(name).trim() || fallback;
    const ansi = (name: string) => token(`--ansi-${name}`, token('--text', '#eef4f5'));

    return {
        background: token('--bg-canvas', '#0c1215'),
        foreground: token('--text', '#eef4f5'),
        cursor: token('--accent', '#4fd1c5'),
        cursorAccent: token('--bg-canvas', '#0c1215'),
        selectionBackground: token('--selection', 'rgba(79,209,197,.28)'),
        black: ansi('black'),
        red: ansi('red'),
        green: ansi('green'),
        yellow: ansi('yellow'),
        blue: ansi('blue'),
        magenta: ansi('magenta'),
        cyan: ansi('cyan'),
        white: ansi('white'),
        brightBlack: ansi('bright-black'),
        brightRed: ansi('red'),
        brightGreen: ansi('green'),
        brightYellow: ansi('yellow'),
        brightBlue: ansi('blue'),
        brightMagenta: ansi('magenta'),
        brightCyan: ansi('cyan'),
        brightWhite: token('--text', '#eef4f5'),
    };
}

/** Calls `apply` whenever the app theme (the `dark` class on <html>) flips. */
export function onThemeChange(apply: () => void): () => void {
    const observer = new MutationObserver(apply);
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class', 'data-theme', 'style'] });

    return () => observer.disconnect();
}

export const TERMINAL_FONT = "'JetBrains Mono Variable', ui-monospace, SFMono-Regular, Menlo, Consolas, monospace";
