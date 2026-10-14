import { Callout, Input, LogViewer, Segmented, SkeletonRows, type LogLine } from '@/components/falak';
import { useJson } from '@/hooks/use-json';
import { type ServiceTabProps } from '@/lib/registry';
import { useEffect, useMemo, useState } from 'react';
import { formatMs, type DatabaseLogLine } from '../observability';
import { useDatabasePanel } from './api';

const KINDS = [
    { value: 'all', label: 'All' },
    { value: 'slow', label: 'Slow queries' },
    { value: 'engine', label: 'Engine' },
] as const;
type Kind = (typeof KINDS)[number]['value'];
const RANGES = ['1h', '6h', '24h', '7d'] as const;
type Range = (typeof RANGES)[number];

function level(line: DatabaseLogLine): LogLine['level'] {
    const severity = (line.severity ?? '').toLowerCase();
    if (line.kind === 'slow' || severity.startsWith('warn')) return 'warning';
    if (/error|fatal|panic|critical/.test(severity) || /\b(ERROR|FATAL|PANIC)\b/.test(line.line)) return 'error';

    return 'info';
}

/**
 * Database panel → Logs (docs/plans/OBSERVABILITY.md phase 3): the engine's own output and the slow queries the agent
 * read (normalized: no literals), newest last. Refreshes every 15s.
 */
export function DatabaseLogsTab({ ctx }: ServiceTabProps) {
    const panel = useDatabasePanel(ctx);
    const [kind, setKind] = useState<Kind>('all');
    const [range, setRange] = useState<Range>('1h');
    const [search, setSearch] = useState('');
    const [query, setQuery] = useState('');
    const instanceId = panel.data?.instance.id;

    // Searches once typing pauses.
    useEffect(() => {
        const timer = window.setTimeout(() => setQuery(search.trim()), 400);

        return () => window.clearTimeout(timer);
    }, [search]);

    const params = new URLSearchParams({ kind, range, ...(query !== '' ? { search: query } : {}) });
    const { data, error, loading } = useJson<{ lines: DatabaseLogLine[] }>(
        instanceId ? `/databases/instances/${instanceId}/logs/data?${params}` : null,
        {
            interval: 15_000,
            unwrap: false,
        },
    );

    const lines = useMemo<LogLine[]>(
        () =>
            [...(data?.lines ?? [])].reverse().map((line) => ({
                text:
                    line.kind === 'slow' && line.duration_ms !== null
                        ? `[slow ${formatMs(line.duration_ms)}${line.database ? ` · ${line.database}` : ''}] ${line.line}`
                        : line.line,
                time: line.at,
                level: level(line),
            })),
        [data],
    );

    if (!panel.data) return panel.error ? <p className="text-danger text-sm">{panel.error}</p> : <SkeletonRows rows={6} />;

    return (
        <div className="grid min-w-0 gap-3">
            <div className="flex flex-wrap items-center gap-2">
                <Segmented label="Records" value={kind} onValueChange={setKind} options={[...KINDS]} />
                <Segmented label="Time range" value={range} onValueChange={setRange} options={RANGES.map((value) => ({ value, label: value }))} />
                <Input
                    aria-label="Search"
                    placeholder="Search"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    className="h-8 w-56"
                />
            </div>
            {error && data === null ? (
                <Callout tone="danger" title="Logs are not available">
                    {error}
                </Callout>
            ) : (
                <LogViewer
                    lines={lines}
                    label="Database logs"
                    filename={`${panel.data.instance.name}.log`}
                    height={520}
                    streaming={loading}
                    emptyText={
                        kind === 'slow'
                            ? `No query slower than the threshold in this range (Settings → slow query threshold).`
                            : 'No log records in this range.'
                    }
                />
            )}
            <p className="text-fg-faint text-xs">
                Slow queries are stored without their values (WHERE id = ?). PostgreSQL also prints them to its own log with values, as before.
            </p>
        </div>
    );
}
