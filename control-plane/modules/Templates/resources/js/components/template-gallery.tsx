import { EmptyState, Input, PickItem, PickList, Skeleton, Tag } from '@/components/falak';
import { cn } from '@/lib/utils';
import { Boxes, Search, Star } from 'lucide-react';
import { useMemo, useState, type ReactNode } from 'react';
import { type Category, type TemplateSummary } from '../types';
import { TemplateIcon, TemplateIconTile } from './template-icon';

type Filter = 'all' | 'popular' | 'custom' | string;

function matches(template: TemplateSummary, query: string): boolean {
    if (!query) return true;
    const haystack = [
        template.name,
        template.slug,
        template.description,
        template.category_label,
        ...template.tags,
        ...template.services.map((service) => `${service.name} ${service.image}`),
    ]
        .join(' ')
        .toLowerCase();

    return query
        .toLowerCase()
        .split(/\s+/)
        .filter(Boolean)
        .every((word) => haystack.includes(word));
}

function TemplateCard({ template, onPick }: { template: TemplateSummary; onPick: (template: TemplateSummary) => void }) {
    const services = template.services.length;

    return (
        <button
            type="button"
            onClick={() => onPick(template)}
            data-testid={`template-${template.slug}`}
            className={cn(
                'group border-border bg-surface-1 hover:border-border-strong hover:bg-surface-2/40 flex w-full min-w-0 flex-col gap-3 rounded-lg border text-left transition-colors duration-150',
                'focus-visible:outline-primary p-4 focus-visible:outline-2 focus-visible:outline-offset-2',
            )}
        >
            <span className="flex min-w-0 items-start gap-3">
                <TemplateIconTile icon={template.icon} name={template.name} size="md" />
                <span className="grid min-w-0 flex-1 gap-0.5">
                    <span className="flex min-w-0 items-center gap-1.5">
                        <span className="text-fg truncate text-sm font-medium">{template.name}</span>
                        {template.source === 'custom' && <Tag tone="accent">Custom</Tag>}
                    </span>
                    <span className="text-fg-muted line-clamp-2 min-h-8 text-xs">{template.description}</span>
                </span>
            </span>
            <span className="text-fg-faint mt-auto flex items-center gap-1.5 text-xs">
                <span>{template.category_label}</span>
                <span aria-hidden>·</span>
                <span>
                    {services} {services === 1 ? 'service' : 'services'}
                </span>
                {template.popular && <Star className="text-warning ml-auto size-3.5 fill-current" aria-label="Popular" />}
            </span>
        </button>
    );
}

function Grid({ children }: { children: ReactNode }) {
    return <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">{children}</div>;
}

export interface TemplateGalleryProps {
    templates: TemplateSummary[] | null;
    categories: Category[];
    onPick: (template: TemplateSummary) => void;
    /** Create picker layout: one keyboard-driven list instead of the card grid. */
    compact?: boolean;
    autoFocus?: boolean;
    error?: string | null;
}

/** Search + categories + popular (docs/COMPOSE_TEMPLATES.md §3). */
export function TemplateGallery({ templates, categories, onPick, compact = false, autoFocus = false, error }: TemplateGalleryProps) {
    const [query, setQuery] = useState('');
    const [filter, setFilter] = useState<Filter>('all');
    const list = useMemo(() => templates ?? [], [templates]);

    const chips = useMemo(() => {
        const used = new Set(list.map((template) => template.category));

        return [
            { value: 'all', label: 'All' },
            ...(list.some((template) => template.popular) ? [{ value: 'popular', label: 'Popular' }] : []),
            ...categories.filter((category) => used.has(category.value)),
            ...(list.some((template) => template.source === 'custom') ? [{ value: 'custom', label: 'Custom' }] : []),
        ];
    }, [list, categories]);

    const filtered = list.filter(
        (template) =>
            matches(template, query) &&
            (filter === 'all' ||
                (filter === 'popular' && template.popular) ||
                (filter === 'custom' && template.source === 'custom') ||
                template.category === filter),
    );
    const sections = filter === 'all' && !query && list.some((template) => template.popular);
    const chipRow = (
        <div className="-mx-1 flex gap-1 overflow-x-auto px-1 pb-0.5" role="radiogroup" aria-label="Category">
            {chips.map((chip) => (
                <button
                    key={chip.value}
                    type="button"
                    role="radio"
                    aria-checked={filter === chip.value}
                    onClick={() => setFilter(chip.value)}
                    className={cn(
                        'h-7 shrink-0 rounded-full border px-2.5 text-xs font-medium whitespace-nowrap transition-colors duration-150',
                        'focus-visible:outline-primary focus-visible:outline-2 focus-visible:outline-offset-2',
                        filter === chip.value
                            ? 'border-primary/40 bg-primary-soft text-primary-on-soft'
                            : 'border-border text-fg-muted hover:bg-surface-2 hover:text-fg',
                    )}
                >
                    {chip.label}
                </button>
            ))}
        </div>
    );

    // Create picker: one keyboard-driven list (type to filter, ↑/↓, Enter), like every other pick step there.
    if (compact) {
        return (
            <PickList
                label="Templates"
                placeholder="Search templates"
                search={query}
                onSearchChange={setQuery}
                filter={false}
                autoFocus={autoFocus}
                toolbar={<div className="border-border border-b px-3 py-2">{chipRow}</div>}
                empty={
                    error ? (
                        <span className="text-danger">{error}</span>
                    ) : templates === null ? (
                        'Loading templates…'
                    ) : list.length === 0 ? (
                        'No templates yet. Import your own under Settings → Templates.'
                    ) : (
                        'No templates match.'
                    )
                }
            >
                {filtered.map((template) => (
                    <PickItem
                        key={`${template.source}:${template.slug}`}
                        value={`${template.source}:${template.slug}`}
                        data-testid={`template-${template.slug}`}
                        onSelect={() => onPick(template)}
                        icon={<TemplateIcon icon={template.icon} name={template.name} size={16} />}
                        title={
                            <span className="flex min-w-0 items-center gap-1.5">
                                <span className="truncate">{template.name}</span>
                                {template.source === 'custom' && <Tag tone="accent">Custom</Tag>}
                            </span>
                        }
                        description={template.description}
                        trailing={template.popular && <Star className="text-warning size-3.5 shrink-0 fill-current" aria-label="Popular" />}
                    />
                ))}
            </PickList>
        );
    }

    return (
        <div className="grid min-w-0 gap-4">
            <div className="grid gap-2.5">
                <Input
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    placeholder="Search templates"
                    prefix={<Search aria-hidden />}
                    aria-label="Search templates"
                    autoFocus={autoFocus}
                />
                {chipRow}
            </div>

            {error ? (
                <p role="alert" className="bg-danger-soft text-danger rounded-md px-3 py-2 text-xs">
                    {error}
                </p>
            ) : templates === null ? (
                <Grid>
                    {[0, 1, 2, 3, 4, 5].map((i) => (
                        <Skeleton key={i} className="h-28" />
                    ))}
                </Grid>
            ) : filtered.length === 0 ? (
                <EmptyState
                    size="sm"
                    icon={<Boxes />}
                    title={list.length === 0 ? 'No templates yet' : 'No templates match'}
                    description={
                        list.length === 0
                            ? 'Templates are one-click apps backed by Docker Compose. Import your own under Settings → Templates.'
                            : 'Try another search or category.'
                    }
                />
            ) : sections ? (
                <>
                    <section className="grid gap-2.5" aria-label="Popular">
                        <h2 className="text-fg-muted text-xs font-medium">Popular</h2>
                        <Grid>
                            {filtered
                                .filter((template) => template.popular)
                                .map((template) => (
                                    <TemplateCard key={`${template.source}:${template.slug}`} template={template} onPick={onPick} />
                                ))}
                        </Grid>
                    </section>
                    <section className="grid gap-2.5" aria-label="All templates">
                        <h2 className="text-fg-muted text-xs font-medium">All templates</h2>
                        <Grid>
                            {filtered.map((template) => (
                                <TemplateCard key={`${template.source}:${template.slug}`} template={template} onPick={onPick} />
                            ))}
                        </Grid>
                    </section>
                </>
            ) : (
                <Grid>
                    {filtered.map((template) => (
                        <TemplateCard key={`${template.source}:${template.slug}`} template={template} onPick={onPick} />
                    ))}
                </Grid>
            )}
        </div>
    );
}
