import { Button, IconButton } from '@/components/falak/button';
import { CopyButton } from '@/components/falak/copy-button';
import { LogViewer, type LogLine } from '@/components/falak/log-viewer';
import { Tag } from '@/components/falak/tag';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { format } from 'date-fns';
import { Activity, History, Pause, Play, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { getJson, queryString } from '../lib';
import { type LogOrigin } from '../log-sources';
import { type LogLineDto } from '../types';

export interface LogFilters {
    server_id?: string;
    site_id?: string;
    service?: string;
    /** Compose sites: one compose service's container logs. */
    compose_service?: string;
    /** app (a site's own output) or access (edge requests); both when unset. */
    kind?: 'app' | 'access';
    /** Where app records come from (output, file, container_file, php_fpm, php_fpm_slow). */
    origin?: LogOrigin;
    level?: string;
    search?: string;
    regex?: boolean;
    trace_id?: string;
    range?: string;
    from?: string;
    to?: string;
}

interface LogsResponse {
    lines: LogLineDto[];
    next_before: string | null;
}

const POLL_MS = 3000;
const MAX_LINES = 5000;

export type LogStreamState = { status: 'idle' | 'loading' | 'ready' } | { status: 'error'; error: unknown };

function key(line: LogLineDto): string {
    return `${line.ts}\u0000${line.line}`;
}

export function levelOf(line: LogLineDto): string | undefined {
    return line.metadata.severity_text ?? line.metadata.detected_level ?? line.labels.detected_level ?? line.labels.level;
}

function viewerLevel(level: string | undefined): LogLine['level'] {
    const value = level?.toLowerCase() ?? '';
    if (value.startsWith('err') || value.startsWith('fatal') || value.startsWith('crit') || value.startsWith('emerg') || value.startsWith('alert'))
        return 'error';
    if (value.startsWith('warn')) return 'warning';
    if (value.startsWith('debug') || value.startsWith('trace')) return 'debug';

    return 'info';
}

/**
 * Loki-backed log stream: loads the newest lines for the filters (oldest first for display), then — while
 * following — polls for newer lines every few seconds; "Load older" pages backwards with the cursor.
 */
export function useLogStream(filters: LogFilters, { enabled = true, follow = true }: { enabled?: boolean; follow?: boolean } = {}) {
    const [lines, setLines] = useState<LogLineDto[]>([]);
    const [state, setState] = useState<LogStreamState>({ status: 'idle' });
    const [olderCursor, setOlderCursor] = useState<string | null>(null);
    const [loadingOlder, setLoadingOlder] = useState(false);
    const [attempt, setAttempt] = useState(0);
    const seen = useRef(new Set<string>());
    const filterKey = JSON.stringify(filters);

    const params = useCallback(
        (extra: Record<string, string | number | boolean | undefined>) =>
            queryString({ ...JSON.parse(filterKey), regex: filters.regex === true, ...extra }),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [filterKey],
    );

    // Initial load whenever the filters change.
    useEffect(() => {
        if (!enabled) return;
        const controller = new AbortController();
        setState({ status: 'loading' });

        getJson<LogsResponse>(`/telemetry/logs/data${params({ limit: 500 })}`, controller.signal)
            .then((body) => {
                const ordered = [...body.lines].reverse();
                seen.current = new Set(ordered.map(key));
                setLines(ordered);
                setOlderCursor(body.next_before);
                setState({ status: 'ready' });
            })
            .catch((error: unknown) => {
                if (!controller.signal.aborted) setState({ status: 'error', error });
            });

        return () => controller.abort();
    }, [enabled, params, attempt]);

    // Live tail.
    const newest = lines.length > 0 ? lines[lines.length - 1].at : null;
    const newestRef = useRef(newest);
    newestRef.current = newest;
    const live = enabled && follow && state.status === 'ready' && !filters.to;

    useEffect(() => {
        if (!live) return;
        let cancelled = false;

        const tick = async () => {
            const from = newestRef.current ?? new Date(Date.now() - 60_000).toISOString();

            try {
                const body = await getJson<LogsResponse>(
                    `/telemetry/logs/data${params({ from, range: undefined, to: undefined, direction: 'forward', limit: 500 })}`,
                );
                if (cancelled) return;
                const fresh = body.lines.filter((line) => !seen.current.has(key(line)));
                if (fresh.length === 0) return;
                fresh.forEach((line) => seen.current.add(key(line)));
                setLines((current) => [...current, ...fresh].slice(-MAX_LINES));
            } catch {
                // Transient poll failures are ignored; the next tick retries.
            }
        };

        const timer = window.setInterval(() => void tick(), POLL_MS);

        return () => {
            cancelled = true;
            window.clearInterval(timer);
        };
    }, [live, params]);

    const loadOlder = useCallback(async () => {
        if (!olderCursor) return;
        setLoadingOlder(true);

        try {
            const body = await getJson<LogsResponse>(`/telemetry/logs/data${params({ before: olderCursor, limit: 500 })}`);
            const older = [...body.lines].reverse().filter((line) => !seen.current.has(key(line)));
            older.forEach((line) => seen.current.add(key(line)));
            setLines((current) => [...older, ...current]);
            setOlderCursor(body.next_before);
        } finally {
            setLoadingOlder(false);
        }
    }, [olderCursor, params]);

    return { lines, state, live, olderCursor, loadingOlder, loadOlder, retry: () => setAttempt((value) => value + 1) };
}

/** Log stream rendered in the Falak LogViewer with follow/pause, load older and a line details drawer (→ trace). */
export function LogStreamView({
    lines,
    live,
    following,
    onFollowChange,
    olderCursor,
    loadingOlder,
    onLoadOlder,
    loading,
    toolbar,
    height = 560,
    serverNames = {},
    emptyText,
}: {
    lines: LogLineDto[];
    live: boolean;
    following: boolean;
    onFollowChange: (follow: boolean) => void;
    olderCursor: string | null;
    loadingOlder: boolean;
    onLoadOlder: () => void;
    loading?: boolean;
    toolbar?: ReactNode;
    height?: number | string;
    serverNames?: Record<string, string>;
    emptyText?: ReactNode;
}) {
    const [selected, setSelected] = useState<number | null>(null);
    const viewerLines = useMemo<LogLine[]>(
        () =>
            lines.map((line) => ({
                text: line.line,
                time: format(new Date(line.at), 'MM-dd HH:mm:ss.SSS'),
                level: viewerLevel(levelOf(line)),
            })),
        [lines],
    );
    const detail = selected !== null ? (lines[selected] ?? null) : null;

    return (
        <div className="grid min-w-0 gap-3">
            <LogViewer
                label="Logs"
                lines={viewerLines}
                follow={following}
                streaming={live && following}
                lineNumbers={false}
                height={height}
                emptyText={loading ? 'Loading logs…' : (emptyText ?? 'No log lines match these filters in this time range.')}
                onLineClick={(_line, index) => setSelected(index === selected ? null : index)}
                toolbar={
                    <>
                        {toolbar}
                        {olderCursor && (
                            <Button size="sm" variant="ghost" icon={<History />} loading={loadingOlder} onClick={onLoadOlder}>
                                Older
                            </Button>
                        )}
                        <Button
                            size="sm"
                            variant={following ? 'ghost' : 'secondary'}
                            icon={following ? <Pause /> : <Play />}
                            onClick={() => onFollowChange(!following)}
                            aria-pressed={following}
                        >
                            {following ? 'Pause' : 'Follow'}
                        </Button>
                    </>
                }
            />
            {detail && (
                <div className="border-border bg-surface-1 grid gap-3 rounded-lg border p-3" aria-label="Log line details">
                    <div className="flex items-start justify-between gap-3">
                        <div className="flex min-w-0 flex-wrap items-center gap-1.5">
                            <Tag
                                tone={
                                    viewerLevel(levelOf(detail)) === 'error'
                                        ? 'danger'
                                        : viewerLevel(levelOf(detail)) === 'warning'
                                          ? 'warning'
                                          : 'neutral'
                                }
                            >
                                {levelOf(detail) ?? 'info'}
                            </Tag>
                            <span className="text-fg-muted tabular font-mono text-xs">{format(new Date(detail.at), 'yyyy-MM-dd HH:mm:ss.SSS')}</span>
                            {detail.labels.service_name && <Tag>{detail.labels.service_name}</Tag>}
                            {detail.labels.falak_server_id && (
                                <Tag>{serverNames[detail.labels.falak_server_id.toLowerCase()] ?? detail.labels.falak_server_id}</Tag>
                            )}
                        </div>
                        <div className="flex items-center gap-1">
                            {detail.trace_id && (
                                <Button asChild size="sm" variant="primary">
                                    <Link href={`/observability/traces/${detail.trace_id}`}>
                                        <Activity /> Open trace
                                    </Link>
                                </Button>
                            )}
                            <CopyButton value={detail.line} label="Copy line" />
                            <IconButton size="sm" label="Close details" icon={<X />} onClick={() => setSelected(null)} />
                        </div>
                    </div>
                    <pre className="bg-canvas text-fg max-h-40 overflow-auto rounded-md p-2 font-mono text-xs break-all whitespace-pre-wrap">
                        {detail.line}
                    </pre>
                    <dl className={cn('grid gap-x-4 gap-y-1 text-xs sm:grid-cols-2')}>
                        {[...Object.entries(detail.labels), ...Object.entries(detail.metadata)].map(([name, value]) => (
                            <div key={name} className="grid min-w-0 grid-cols-[minmax(0,2fr)_minmax(0,3fr)] gap-2">
                                <dt className="text-fg-muted truncate font-mono">{name}</dt>
                                <dd className="text-fg truncate font-mono" title={value}>
                                    {value}
                                </dd>
                            </div>
                        ))}
                    </dl>
                </div>
            )}
        </div>
    );
}
