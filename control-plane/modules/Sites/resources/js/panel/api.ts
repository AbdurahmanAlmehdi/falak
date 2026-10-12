import { toast, type ManagedConfig } from '@/components/falak';
import { errorMessage, requestJson, type HttpMethod } from '@/lib/http';
import { serviceActionsFor, type ServicePanelContext } from '@/lib/registry';

/** GET /sites/{site}/environment (JSON) — the Variables tab. */
export interface EnvironmentState {
    site: { id: string; name: string };
    current: {
        version: number;
        keys: string[];
        exposed: string[];
        /** Values that are nothing but `${{ service.KEY }}` references (no secret, shown as-is). */
        references: Record<string, string>;
        /** Keys whose value contains a reference. */
        referencing: string[];
        /** Unresolved references / cycles of the saved version. */
        reference_errors: string[];
        created_at: string;
    } | null;
    versions: { version: number; changed_keys: string[]; created_by: string | null; created_at: string }[];
    can: { reveal: boolean; update: boolean };
    /** Variables the project's falak.yaml sets (/env/<KEY>, /expose): read-only here. Null when none. */
    config?: ManagedConfig | null;
}

/** POST /sites/{site}/environment/reveal (audited). */
export interface RevealedEnvironment {
    version: number;
    content: string;
    exposed: string[];
}

/** GET /projects/{p}/{env}/variables — what `${{ service.KEY }}` can point at in this environment. */
export interface ReferenceTarget {
    id: string;
    kind: 'site' | 'database';
    ref_id: string;
    name: string;
    handle: string;
    keys: string[];
}

export const environmentUrl = (siteId: string) => `/sites/${siteId}/environment`;

export const referencesUrl = (ctx: ServicePanelContext) => `/projects/${ctx.project.id}/${ctx.environment.slug}/variables`;

/** Run a mutation with a toast; answers false on failure (field errors are thrown to the caller when `rethrow`). */
export async function mutate(method: HttpMethod, url: string, body: unknown, success: string, failure = 'Something went wrong'): Promise<boolean> {
    try {
        await requestJson(url, method, body);
        toast.success(success);

        return true;
    } catch (error) {
        toast.error(failure, errorMessage(error));

        return false;
    }
}

/** The panel's primary Deploy action (registered by Deployments), so Sites never imports Deployments code. */
export async function deployNow(ctx: ServicePanelContext): Promise<void> {
    const action = serviceActionsFor(ctx).find((item) => item.id === 'deployments.deploy');
    if (action?.perform) await action.perform(ctx);
    else toast.info('Saved', 'Deploy from the Deployments tab to apply the changes.');
}

// ─── `${{ service.KEY }}` references (docs/UI_DESIGN.md §5.3) ────────────────────────────────────────────────────

export const REFERENCE = /\$\{\{\s*([A-Za-z0-9][A-Za-z0-9 _.-]*?)\.([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/g;

/** Same normalisation as Projects' Service::handle(): "Shop API", "shop-api" and "shop_api" are one service. */
export function serviceHandle(name: string): string {
    return name
        .trim()
        .replace(/[_.]/g, '-')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

/** How a reference to `target.KEY` is written. */
export function referenceFor(target: Pick<ReferenceTarget, 'name' | 'handle'>, key: string): string {
    const name = /^[A-Za-z0-9][A-Za-z0-9_-]*$/.test(target.name) ? target.name : target.handle;

    return `\${{ ${name}.${key} }}`;
}

export function referencesIn(value: string): { service: string; key: string; text: string }[] {
    return [...value.matchAll(REFERENCE)].map((match) => ({ service: match[1].trim(), key: match[2], text: match[0] }));
}

export function isReferenceOnly(value: string): boolean {
    return referencesIn(value).length > 0 && value.replace(REFERENCE, '').trim() === '';
}

/**
 * Unresolvable references in a value, checked against the services of the environment. `self` is this site's own
 * keys (a site may reference itself); null targets = not loaded yet (nothing reported).
 */
export function unresolved(value: string, targets: ReferenceTarget[] | null, self: { handle: string; keys: string[] }): string[] {
    if (!targets) return [];

    return referencesIn(value).flatMap((reference) => {
        const handle = serviceHandle(reference.service);
        const target = targets.find((item) => item.handle === handle);
        if (!target) return [`No service named “${reference.service}” in this environment`];
        const keys = target.handle === self.handle ? self.keys : target.keys;

        return keys.includes(reference.key) ? [] : [`${target.name} has no variable ${reference.key}`];
    });
}

// ─── dotenv helpers for the raw editor ───────────────────────────────────────────────────────────────────────────

const KEY_LINE = /^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$/;

/** Keys in file order (the server parser is authoritative; this drives the diff summary and exposure toggles). */
export function dotenvKeys(content: string): string[] {
    return [
        ...new Set(
            content
                .split(/\r\n|\r|\n/)
                .map((line) => line.match(KEY_LINE)?.[1])
                .filter((key): key is string => Boolean(key)),
        ),
    ];
}

/** Parse the revealed dotenv into values (quotes and escapes of the server writer). */
export function parseDotenv(content: string): Record<string, string> {
    const values: Record<string, string> = {};
    content.split(/\r\n|\r|\n/).forEach((line) => {
        const match = line.match(KEY_LINE);
        if (!match) return;
        const raw = match[2].trim();
        const quote = raw[0];
        if ((quote === '"' || quote === "'") && raw.endsWith(quote) && raw.length > 1) {
            const inner = raw.slice(1, -1);
            values[match[1]] = quote === "'" ? inner : inner.replace(/\\(n|"|\\|\$)/g, (_, char: string) => (char === 'n' ? '\n' : char));
        } else {
            values[match[1]] = raw.replace(/\s+#.*$/, '');
        }
    });

    return values;
}

export type DiffLine = { type: 'same' | 'add' | 'remove'; text: string; oldNo: number | null; newNo: number | null };

/** Line diff (LCS) of two small files — the raw editor's "review changes" step. */
export function lineDiff(before: string, after: string): DiffLine[] {
    const a = before.replace(/\n$/, '').split('\n');
    const b = after.replace(/\n$/, '').split('\n');
    const table: number[][] = Array.from({ length: a.length + 1 }, () => new Array<number>(b.length + 1).fill(0));
    for (let i = a.length - 1; i >= 0; i--) {
        for (let j = b.length - 1; j >= 0; j--) {
            table[i][j] = a[i] === b[j] ? table[i + 1][j + 1] + 1 : Math.max(table[i + 1][j], table[i][j + 1]);
        }
    }
    const out: DiffLine[] = [];
    let i = 0;
    let j = 0;
    while (i < a.length || j < b.length) {
        if (i < a.length && j < b.length && a[i] === b[j]) {
            out.push({ type: 'same', text: a[i], oldNo: i + 1, newNo: j + 1 });
            i++;
            j++;
        } else if (j < b.length && (i >= a.length || table[i][j + 1] >= table[i + 1][j])) {
            out.push({ type: 'add', text: b[j], oldNo: null, newNo: j + 1 });
            j++;
        } else {
            out.push({ type: 'remove', text: a[i], oldNo: i + 1, newNo: null });
            i++;
        }
    }

    return out;
}
