import { cn } from '@/lib/utils';
import { ArrowDown, ArrowUp, ChevronsUpDown } from 'lucide-react';
import { useMemo, useState, type KeyboardEvent, type ReactNode } from 'react';
import { EmptyState, type EmptyStateProps } from './empty-state';
import { Menu, type MenuAction } from './menu';
import { Skeleton } from './skeleton';

export interface DataTableColumn<T> {
    id: string;
    header: ReactNode;
    cell: (row: T) => ReactNode;
    /** Makes the column sortable. */
    sortValue?: (row: T) => string | number | null | undefined;
    /** `right`: numbers and times (tabular figures, never wrapped). */
    align?: 'left' | 'right';
    /** CSS width (e.g. '30%', '120px'). */
    width?: string;
    /** Hide below the `sm` breakpoint. */
    hideOnMobile?: boolean;
    className?: string;
}

export type SortState = { column: string; direction: 'asc' | 'desc' } | null;

export interface DataTableProps<T> {
    columns: DataTableColumn<T>[];
    rows: T[];
    rowKey: (row: T) => string;
    /** Accessible table name. */
    label: string;
    loading?: boolean;
    empty?: EmptyStateProps;
    rowActions?: (row: T) => MenuAction[];
    onRowClick?: (row: T) => void;
    defaultSort?: SortState;
    stickyHeader?: boolean;
    /** Max height for the scroll container (sticky header needs a bounded height to matter). */
    maxHeight?: number | string;
    className?: string;
}

function compare(a: string | number | null | undefined, b: string | number | null | undefined): number {
    if (a === b) return 0;
    if (a === null || a === undefined) return 1;
    if (b === null || b === undefined) return -1;
    if (typeof a === 'number' && typeof b === 'number') return a - b;

    return String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: 'base' });
}

export function DataTable<T>({
    columns,
    rows,
    rowKey,
    label,
    loading = false,
    empty,
    rowActions,
    onRowClick,
    defaultSort = null,
    stickyHeader = true,
    maxHeight,
    className,
}: DataTableProps<T>) {
    const [sort, setSort] = useState<SortState>(defaultSort);

    const sorted = useMemo(() => {
        const column = sort ? columns.find((item) => item.id === sort.column) : undefined;
        if (!sort || !column?.sortValue) return rows;
        const value = column.sortValue;
        const factor = sort.direction === 'asc' ? 1 : -1;

        return [...rows].sort((a, b) => compare(value(a), value(b)) * factor);
    }, [rows, sort, columns]);

    const toggleSort = (id: string) =>
        setSort((current) => {
            if (current?.column !== id) return { column: id, direction: 'asc' };
            if (current.direction === 'asc') return { column: id, direction: 'desc' };

            return null;
        });

    if (!loading && rows.length === 0 && empty) {
        return <EmptyState {...empty} className={className} />;
    }

    const colCount = columns.length + (rowActions ? 1 : 0);

    return (
        <div
            className={cn('border-border bg-surface-1 overflow-auto rounded-lg border', className)}
            style={maxHeight !== undefined ? { maxHeight } : undefined}
        >
            <table className="w-full border-collapse text-left text-xs" aria-busy={loading || undefined}>
                <caption className="sr-only">{label}</caption>
                <thead className={cn('bg-surface-1', stickyHeader && 'sticky top-0 z-10')}>
                    <tr className="border-border border-b">
                        {columns.map((column) => {
                            const direction = sort?.column === column.id ? sort.direction : null;

                            return (
                                <th
                                    key={column.id}
                                    scope="col"
                                    style={column.width ? { width: column.width } : undefined}
                                    aria-sort={direction === 'asc' ? 'ascending' : direction === 'desc' ? 'descending' : undefined}
                                    className={cn(
                                        'text-fg-faint h-8 px-3 font-medium whitespace-nowrap',
                                        column.align === 'right' && 'text-right',
                                        column.hideOnMobile && 'hidden sm:table-cell',
                                    )}
                                >
                                    {column.sortValue ? (
                                        <button
                                            type="button"
                                            onClick={() => toggleSort(column.id)}
                                            className={cn(
                                                'hover:text-fg inline-flex items-center gap-1 rounded-sm',
                                                column.align === 'right' && 'flex-row-reverse',
                                                direction && 'text-fg',
                                            )}
                                        >
                                            {column.header}
                                            {direction === 'asc' ? (
                                                <ArrowUp className="size-3" aria-hidden />
                                            ) : direction === 'desc' ? (
                                                <ArrowDown className="size-3" aria-hidden />
                                            ) : (
                                                <ChevronsUpDown className="size-3 opacity-50" aria-hidden />
                                            )}
                                        </button>
                                    ) : (
                                        column.header
                                    )}
                                </th>
                            );
                        })}
                        {rowActions && (
                            <th scope="col" className="w-10">
                                <span className="sr-only">Actions</span>
                            </th>
                        )}
                    </tr>
                </thead>
                <tbody>
                    {loading
                        ? Array.from({ length: 5 }, (_, index) => (
                              <tr key={index} className="border-border border-b last:border-0">
                                  <td colSpan={colCount} className="px-3 py-2.5">
                                      <Skeleton className="h-4" style={{ width: `${80 - index * 10}%` }} />
                                  </td>
                              </tr>
                          ))
                        : sorted.map((row) => {
                              const key = rowKey(row);
                              const onKeyDown = onRowClick
                                  ? (event: KeyboardEvent<HTMLTableRowElement>) => {
                                        if (event.target === event.currentTarget && (event.key === 'Enter' || event.key === ' ')) {
                                            event.preventDefault();
                                            onRowClick(row);
                                        }
                                    }
                                  : undefined;

                              return (
                                  <tr
                                      key={key}
                                      onClick={onRowClick ? () => onRowClick(row) : undefined}
                                      onKeyDown={onKeyDown}
                                      tabIndex={onRowClick ? 0 : undefined}
                                      className={cn(
                                          'group border-border hover:bg-surface-2 border-b transition-colors duration-150 last:border-0',
                                          onRowClick && 'cursor-pointer',
                                      )}
                                  >
                                      {columns.map((column) => (
                                          <td
                                              key={column.id}
                                              className={cn(
                                                  'text-fg h-10 px-3 align-middle text-sm',
                                                  column.align === 'right' && 'tabular text-right whitespace-nowrap',
                                                  column.hideOnMobile && 'hidden sm:table-cell',
                                                  column.className,
                                              )}
                                          >
                                              {column.cell(row)}
                                          </td>
                                      ))}
                                      {rowActions && (
                                          <td className="px-1 text-right" onClick={(event) => event.stopPropagation()}>
                                              <Menu actions={rowActions(row)} label="Row actions" />
                                          </td>
                                      )}
                                  </tr>
                              );
                          })}
                </tbody>
            </table>
        </div>
    );
}
