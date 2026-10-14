import { Avatar } from '@/components/falak/avatar';
import { Button } from '@/components/falak/button';
import { Checkbox } from '@/components/falak/checkbox';
import { DataTable, type DataTableColumn } from '@/components/falak/data-table';
import { Input } from '@/components/falak/input';
import { Pagination } from '@/components/falak/pagination';
import { RelativeTime } from '@/components/falak/relative-time';
import { Select } from '@/components/falak/select';
import { Sparkline } from '@/components/falak/sparkline';
import { Tag } from '@/components/falak/tag';
import { Tooltip } from '@/components/falak/tooltip';
import ObservabilityLayout from '@/layouts/observability-layout';
import { cn } from '@/lib/utils';
import { type Paginated } from '@/types';
import { Link, router } from '@inertiajs/react';
import { Bug, CheckCircle2, EyeOff, RotateCcw, Search, X } from 'lucide-react';
import { useEffect, useState, type FormEventHandler } from 'react';
import { formatCount, KIND, priorityLabel, PriorityTag } from '../components/insights-ui';
import { type IssuePriority, type IssueStatus, type IssueSummary, type Member } from '../types';

interface Filters {
    status: string;
    kind?: string;
    priority?: string;
    site?: string;
    assignee?: string;
    search?: string;
    sort?: string;
}

type IssueRow = IssueSummary & { sparkline: number[] | null };

interface Props {
    issues: Paginated<IssueRow>;
    filters: Filters;
    counts: Record<IssueStatus | 'all', number>;
    members: Member[];
    sites: Record<string, string>;
    priorities: IssuePriority[];
    can: { manage: boolean };
}

const ALL = '__all__';
const STATUS_TABS: { value: IssueStatus | 'all'; label: string }[] = [
    { value: 'open', label: 'Open' },
    { value: 'resolved', label: 'Resolved' },
    { value: 'ignored', label: 'Ignored' },
    { value: 'all', label: 'All' },
];

export default function Issues({ issues, filters, counts, members, sites, priorities, can }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [pending, setPending] = useState<IssueStatus | null>(null);

    // Selection only spans the visible page.
    useEffect(() => setSelected(new Set()), [issues.current_page, filters]);

    const apply = (next: Partial<Filters>) => {
        const query = { ...filters, search, ...next };
        const cleaned = Object.fromEntries(
            Object.entries(query).filter(([key, value]) => value && value !== ALL && !(key === 'status' && value === 'open')),
        );
        router.get('/observability/issues', cleaned, { preserveState: true, preserveScroll: true, replace: true });
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        apply({ search });
    };

    const bulk = (status: IssueStatus) => {
        setPending(status);
        router.put(
            route('insights.issues.bulk-status'),
            { ids: [...selected], status },
            { preserveScroll: true, onFinish: () => setPending(null), onSuccess: () => setSelected(new Set()) },
        );
    };

    const rows = issues.data;
    const allSelected = rows.length > 0 && rows.every((row) => selected.has(row.id));
    const someSelected = rows.some((row) => selected.has(row.id));
    const toggle = (id: string) =>
        setSelected((current) => {
            const next = new Set(current);
            if (next.has(id)) next.delete(id);
            else next.add(id);

            return next;
        });
    const hasFilters = Boolean(filters.kind || filters.priority || filters.site || filters.assignee || filters.search);

    const columns: DataTableColumn<IssueRow>[] = [
        ...(can.manage
            ? [
                  {
                      id: 'select',
                      width: '36px',
                      header: (
                          <Checkbox
                              aria-label="Select all issues on this page"
                              checked={allSelected ? true : someSelected ? 'indeterminate' : false}
                              onCheckedChange={() => setSelected(allSelected ? new Set() : new Set(rows.map((row) => row.id)))}
                          />
                      ),
                      cell: (row: IssueRow) => (
                          <span onClick={(event) => event.stopPropagation()} className="flex">
                              <Checkbox aria-label={`Select ${row.title}`} checked={selected.has(row.id)} onCheckedChange={() => toggle(row.id)} />
                          </span>
                      ),
                  } satisfies DataTableColumn<IssueRow>,
              ]
            : []),
        {
            id: 'issue',
            header: 'Issue',
            cell: (row) => {
                const Icon = KIND[row.kind].icon;

                return (
                    <span className="flex min-w-0 items-start gap-2.5 py-1.5">
                        <Icon
                            className={cn('mt-0.5 size-4 shrink-0', row.status === 'open' && row.handled === false ? 'text-danger' : 'text-fg-faint')}
                            aria-label={KIND[row.kind].label}
                        />
                        <span className="grid min-w-0 gap-0.5">
                            <Link
                                href={`/observability/issues/${row.id}`}
                                onClick={(event) => event.stopPropagation()}
                                className={cn('truncate text-sm hover:underline', row.status === 'open' ? 'text-fg font-medium' : 'text-fg-muted')}
                            >
                                {row.title}
                            </Link>
                            <span className="text-fg-faint flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5 text-xs">
                                {row.culprit && <span className="max-w-full truncate font-mono">{row.culprit}</span>}
                                {row.site_name && <span>{row.site_name}</span>}
                                <span>
                                    first seen <RelativeTime value={row.first_seen_at} />
                                </span>
                                {row.status !== 'open' && <Tag tone="faint">{row.status}</Tag>}
                                {row.handled === false && row.status === 'open' && <Tag tone="danger">Unhandled</Tag>}
                                <PriorityTag priority={row.priority} />
                            </span>
                        </span>
                    </span>
                );
            },
        },
        {
            id: 'trend',
            header: '24h',
            width: '112px',
            hideOnMobile: true,
            cell: (row) =>
                row.sparkline ? (
                    <Sparkline
                        values={row.sparkline}
                        label="Occurrences per hour, last 24 hours"
                        tone={row.status !== 'open' ? 'muted' : row.handled === false ? 'danger' : 'accent'}
                    />
                ) : (
                    <span className="text-fg-faint text-xs">—</span>
                ),
        },
        { id: 'events', header: 'Events', align: 'right', width: '80px', cell: (row) => formatCount(row.occurrences) },
        {
            id: 'users',
            header: 'Users',
            align: 'right',
            width: '72px',
            hideOnMobile: true,
            cell: (row) => (row.affected_users > 0 ? formatCount(row.affected_users) : <span className="text-fg-faint">—</span>),
        },
        {
            id: 'assignee',
            header: <span className="sr-only">Assignee</span>,
            width: '44px',
            hideOnMobile: true,
            cell: (row) =>
                row.assignee ? (
                    <Tooltip content={`Assigned to ${row.assignee.name}`}>
                        <span className="flex">
                            <Avatar name={row.assignee.name} size="sm" />
                        </span>
                    </Tooltip>
                ) : null,
        },
        {
            id: 'seen',
            header: 'Last seen',
            align: 'right',
            width: '132px',
            cell: (row) => <RelativeTime value={row.last_seen_at} className="text-fg-muted text-xs whitespace-nowrap" />,
        },
    ];

    return (
        <ObservabilityLayout tab="issues">
            <div className="grid gap-3">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div role="tablist" aria-label="Status" className="border-border bg-surface-1 inline-flex items-center rounded-md border p-0.5">
                        {STATUS_TABS.map((tab) => {
                            const active = filters.status === tab.value;

                            return (
                                <button
                                    key={tab.value}
                                    type="button"
                                    role="tab"
                                    aria-selected={active}
                                    onClick={() => apply({ status: tab.value })}
                                    className={cn(
                                        'flex h-7 items-center gap-1.5 rounded-sm px-2.5 text-sm font-medium transition-colors duration-150',
                                        active ? 'bg-surface-3 text-fg' : 'text-fg-muted hover:text-fg',
                                    )}
                                >
                                    {tab.label}
                                    <span className="text-fg-faint tabular text-xs">{formatCount(counts[tab.value] ?? 0)}</span>
                                </button>
                            );
                        })}
                    </div>
                    <form onSubmit={submit} className="w-full sm:w-72">
                        <Input
                            type="search"
                            prefix={<Search />}
                            placeholder="Search title or culprit"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            aria-label="Search issues"
                        />
                    </form>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <Select
                        size="sm"
                        className="w-36"
                        aria-label="Kind"
                        value={filters.kind ?? ALL}
                        onValueChange={(kind) => apply({ kind })}
                        options={[
                            { value: ALL, label: 'All kinds' },
                            { value: 'exception', label: 'Exceptions' },
                            { value: 'performance', label: 'Performance' },
                            { value: 'heartbeat', label: 'Scheduled tasks' },
                            { value: 'database', label: 'Databases' },
                            { value: 'security', label: 'Security' },
                            { value: 'uptime', label: 'Uptime' },
                        ]}
                    />
                    <Select
                        size="sm"
                        className="w-36"
                        aria-label="Priority"
                        value={filters.priority ?? ALL}
                        onValueChange={(priority) => apply({ priority })}
                        options={[
                            { value: ALL, label: 'Any priority' },
                            ...priorities.map((priority) => ({ value: priority, label: priorityLabel(priority) })),
                        ]}
                    />
                    <Select
                        size="sm"
                        className="w-40"
                        aria-label="Assignee"
                        value={filters.assignee ?? ALL}
                        onValueChange={(assignee) => apply({ assignee })}
                        options={[
                            { value: ALL, label: 'Anyone' },
                            { value: 'me', label: 'Assigned to me' },
                            { value: 'none', label: 'Unassigned' },
                            ...members.map((member) => ({ value: member.id, label: member.name })),
                        ]}
                    />
                    {Object.keys(sites).length > 0 && (
                        <Select
                            size="sm"
                            className="w-36"
                            aria-label="Site"
                            value={filters.site ?? ALL}
                            onValueChange={(site) => apply({ site })}
                            options={[{ value: ALL, label: 'All sites' }, ...Object.entries(sites).map(([id, name]) => ({ value: id, label: name }))]}
                        />
                    )}
                    <Select
                        size="sm"
                        className="w-36"
                        aria-label="Sort"
                        value={filters.sort ?? 'last_seen'}
                        onValueChange={(sort) => apply({ sort })}
                        options={[
                            { value: 'last_seen', label: 'Last seen' },
                            { value: 'first_seen', label: 'First seen' },
                            { value: 'occurrences', label: 'Most events' },
                            { value: 'users', label: 'Most users' },
                        ]}
                    />
                    {hasFilters && (
                        <Button
                            size="sm"
                            variant="ghost"
                            icon={<X />}
                            onClick={() => {
                                setSearch('');
                                router.get('/observability/issues', filters.status === 'open' ? {} : { status: filters.status }, {
                                    preserveState: true,
                                    replace: true,
                                });
                            }}
                        >
                            Clear filters
                        </Button>
                    )}
                </div>
            </div>

            {selected.size > 0 && (
                <div
                    className="border-border-strong bg-surface-2 sticky top-14 z-20 flex flex-wrap items-center gap-2 rounded-lg border px-3 py-2"
                    role="region"
                    aria-label="Bulk actions"
                >
                    <span className="text-fg text-sm font-medium">{selected.size} selected</span>
                    <div className="ml-auto flex flex-wrap items-center gap-2">
                        {filters.status !== 'resolved' && (
                            <Button
                                size="sm"
                                variant="primary"
                                icon={<CheckCircle2 />}
                                loading={pending === 'resolved'}
                                onClick={() => bulk('resolved')}
                            >
                                Resolve
                            </Button>
                        )}
                        {filters.status !== 'ignored' && (
                            <Button size="sm" icon={<EyeOff />} loading={pending === 'ignored'} onClick={() => bulk('ignored')}>
                                Ignore
                            </Button>
                        )}
                        {filters.status !== 'open' && (
                            <Button size="sm" icon={<RotateCcw />} loading={pending === 'open'} onClick={() => bulk('open')}>
                                Reopen
                            </Button>
                        )}
                        <Button size="sm" variant="ghost" onClick={() => setSelected(new Set())}>
                            Cancel
                        </Button>
                    </div>
                </div>
            )}

            <DataTable
                label="Issues"
                columns={columns}
                rows={rows}
                rowKey={(row) => row.id}
                onRowClick={(row) => router.visit(`/observability/issues/${row.id}`)}
                empty={
                    hasFilters || filters.status !== 'open'
                        ? { icon: <Search />, title: 'No issues match', description: 'Try another status or clear the filters.' }
                        : {
                              icon: <Bug />,
                              title: 'No open issues',
                              description:
                                  'Unhandled exceptions, slow endpoints that break a threshold and missed scheduled tasks open issues here. Nothing needs attention right now.',
                          }
                }
            />
            <Pagination page={issues} noun="issues" />
        </ObservabilityLayout>
    );
}
