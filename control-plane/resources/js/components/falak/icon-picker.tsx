import { cn } from '@/lib/utils';
import * as Popover from '@radix-ui/react-popover';
import { Check, RotateCcw, Search } from 'lucide-react';
import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { ServiceIcon, useIconsUrl } from './service-icon';

/** One row of Cool Icons' /search.json: [slug, name, aliases, categories, defaultVariant, variantCount]. */
type IndexRow = [string, string, string, string, string, number];

const RESULTS = 60;

// Fetched once per page load, when a picker first opens (≈ 95 KB gzipped).
let index: Promise<IndexRow[]> | null = null;
const variantCache = new Map<string, Promise<string[]>>();

function loadIndex(base: string): Promise<IndexRow[]> {
    index ??= fetch(`${base}/search.json`)
        .then((response) => (response.ok ? (response.json() as Promise<IndexRow[]>) : []))
        .then((rows) => [...rows].sort((a, b) => b[5] - a[5]))
        .catch(() => {
            index = null;

            return [];
        });

    return index;
}

/** Variant ids of an icon (first: its default), from /i/<slug>.json; wordmarks are too wide for an icon. */
function loadVariants(base: string, slug: string): Promise<string[]> {
    let variants = variantCache.get(slug);
    if (!variants) {
        variants = fetch(`${base}/i/${slug}.json`)
            .then((response) => (response.ok ? response.json() : { variants: [] }))
            .then((body: { variants?: { id: string; wordmark?: boolean }[] }) =>
                (body.variants ?? []).filter((variant) => !variant.wordmark).map((variant) => variant.id),
            )
            .catch(() => []);
        variantCache.set(slug, variants);
    }

    return variants;
}

/**
 * Best matches first: exact slug / name, then prefix, then anywhere in the name, slug or aliases. Categories only when
 * nothing else matches ("Kubernetes Certified Service Provider" would otherwise bury the Kubernetes logo).
 */
export function searchIcons(rows: IndexRow[], query: string, limit = RESULTS): IndexRow[] {
    const q = query.trim().toLowerCase();
    if (!q) return rows.slice(0, limit);

    const scored: [number, IndexRow][] = [];
    for (const row of rows) {
        const [slug, name, aliases, categories] = row;
        const label = name.toLowerCase();
        const score =
            slug === q || label === q
                ? 0
                : slug.startsWith(q) || label.startsWith(q)
                  ? 1
                  : slug.includes(q) || label.includes(q) || aliases.includes(q)
                    ? 2
                    : categories.toLowerCase().includes(q)
                      ? 3
                      : -1;
        if (score >= 0) scored.push([score, row]);
    }

    const named = scored.filter(([score]) => score < 3);

    // Stable sort: within a score the index order (most variants first) stays.
    return (named.length > 0 ? named : scored)
        .sort((a, b) => a[0] - b[0])
        .slice(0, limit)
        .map(([, row]) => row);
}

export interface IconPickerProps {
    /** The picked icon (slug or slug/variant); null: the derived one is shown. */
    value: string | null | undefined;
    onPick: (icon: string | null) => void;
    /** The trigger (rendered as is). */
    children: ReactNode;
    label: string;
}

/**
 * Icon search over Cool Icons (FALAK_ICONS_URL): pick an icon, then one of its styles; "Default icon" clears the pick.
 * Without the library (FALAK_ICONS_URL empty) it renders just the trigger.
 */
export function IconPicker({ value, onPick, children, label }: IconPickerProps) {
    const base = useIconsUrl();
    const [open, setOpen] = useState(false);
    const [rows, setRows] = useState<IndexRow[] | null>(null);
    const [query, setQuery] = useState('');
    const [variants, setVariants] = useState<string[]>([]);
    const slug = value?.split('/')[0] ?? null;

    useEffect(() => {
        if (open && base && rows === null) void loadIndex(base).then(setRows);
    }, [open, base, rows]);

    useEffect(() => {
        setVariants([]);
        if (!open || !base || !slug) return;
        let current = true;
        void loadVariants(base, slug).then((ids) => current && setVariants(ids));

        return () => {
            current = false;
        };
    }, [open, base, slug]);

    const results = useMemo(() => (rows ? searchIcons(rows, query) : []), [rows, query]);

    if (!base) return <>{children}</>;

    return (
        <Popover.Root open={open} onOpenChange={setOpen}>
            <Popover.Trigger asChild>{children}</Popover.Trigger>
            <Popover.Portal>
                <Popover.Content
                    align="start"
                    sideOffset={6}
                    collisionPadding={12}
                    aria-label={label}
                    onKeyDown={(event) => event.stopPropagation()}
                    className="animate-fade-in border-border bg-surface-1 shadow-panel z-50 w-80 overflow-hidden rounded-lg border"
                >
                    <div className="border-border flex items-center gap-2 border-b px-2.5">
                        <Search className="text-fg-faint size-4" aria-hidden />
                        <input
                            autoFocus
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder="Search icons: docker, postgres, aws…"
                            aria-label="Search icons"
                            className="text-fg placeholder:text-fg-faint h-9 w-full bg-transparent text-sm outline-none"
                        />
                    </div>

                    <div className="grid max-h-64 grid-cols-8 gap-1 overflow-y-auto p-2">
                        {rows === null && <p className="text-fg-faint col-span-8 py-6 text-center text-sm">Loading icons…</p>}
                        {rows !== null && results.length === 0 && (
                            <p className="text-fg-faint col-span-8 py-6 text-center text-sm">No icon matches “{query.trim()}”.</p>
                        )}
                        {results.map(([id, name]) => (
                            <button
                                key={id}
                                type="button"
                                title={name}
                                aria-label={name}
                                aria-pressed={slug === id}
                                onClick={() => onPick(id)}
                                className={cn(
                                    'hover:bg-surface-2 flex aspect-square items-center justify-center rounded-md',
                                    slug === id && 'bg-surface-2 ring-primary ring-1',
                                )}
                            >
                                <ServiceIcon name={id} size={22} />
                            </button>
                        ))}
                    </div>

                    {slug && variants.length > 1 && (
                        <div className="border-border border-t p-2">
                            <p className="text-fg-faint text-2xs px-0.5 pb-1.5 font-medium">Style</p>
                            <div className="flex flex-wrap gap-1">
                                {variants.map((variant, i) => {
                                    // The default variant is stored as the bare slug.
                                    const key = i === 0 ? slug : `${slug}/${variant}`;

                                    return (
                                        <button
                                            key={variant}
                                            type="button"
                                            title={variant}
                                            aria-label={`${variant} style`}
                                            aria-pressed={value === key}
                                            onClick={() => onPick(key)}
                                            className={cn(
                                                'hover:bg-surface-2 relative flex size-9 items-center justify-center rounded-md',
                                                value === key && 'bg-surface-2 ring-primary ring-1',
                                            )}
                                        >
                                            {/* What the card will show: a bare slug may be a bundled logo. */}
                                            <ServiceIcon name={key} size={22} />
                                            {value === key && <Check className="text-primary absolute right-0.5 bottom-0.5 size-2.5" aria-hidden />}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    )}

                    {value && (
                        <div className="border-border border-t p-1">
                            <button
                                type="button"
                                onClick={() => onPick(null)}
                                className="text-fg-muted hover:bg-surface-2 hover:text-fg flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-sm"
                            >
                                <RotateCcw className="size-3.5" aria-hidden />
                                Default icon
                            </button>
                        </div>
                    )}
                </Popover.Content>
            </Popover.Portal>
        </Popover.Root>
    );
}
