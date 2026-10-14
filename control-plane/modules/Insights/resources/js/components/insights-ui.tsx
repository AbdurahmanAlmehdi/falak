import { Button } from '@/components/falak/button';
import { CodeBlock } from '@/components/falak/code-block';
import { Segmented } from '@/components/falak/segmented';
import { StatusBadge } from '@/components/falak/status';
import { Tag } from '@/components/falak/tag';
import { cn } from '@/lib/utils';
import { formatDistanceToNowStrict } from 'date-fns';
import { ChevronDown, ChevronRight, Database, Gauge, HeartPulse, OctagonAlert, SignalHigh, SignalLow, SignalMedium, Zap } from 'lucide-react';
import { Fragment, useMemo, useState } from 'react';
import { type Frame, type IssueKind, type IssuePriority, type IssueStatus } from '../types';

export function formatMs(ms: number | null | undefined): string {
    if (ms === null || ms === undefined) return '—';
    if (ms >= 1000) return `${(ms / 1000).toFixed(ms >= 10_000 ? 0 : 2)} s`;

    return `${ms.toFixed(ms >= 100 ? 0 : 1)} ms`;
}

/** Compact duration for chart axes ("600ms", "1.2s"). */
export function formatMsShort(ms: number): string {
    if (ms >= 1000) return `${(ms / 1000).toFixed(ms >= 10_000 ? 0 : 1)}s`;

    return `${ms.toFixed(ms >= 10 || ms === 0 ? 0 : 1)}ms`;
}

export function formatCount(value: number): string {
    return new Intl.NumberFormat(undefined, { notation: value >= 10_000 ? 'compact' : 'standard', maximumFractionDigits: 1 }).format(value);
}

export function formatPercent(ratio: number | null | undefined, digits = 2): string {
    if (ratio === null || ratio === undefined || Number.isNaN(ratio)) return '—';

    return `${(ratio * 100).toFixed(ratio === 0 ? 0 : digits)}%`;
}

export function ago(iso: string | null): string {
    return iso ? `${formatDistanceToNowStrict(new Date(iso))} ago` : 'never';
}

const STATUS: Record<IssueStatus, { status: string; label: string }> = {
    open: { status: 'failed', label: 'Open' },
    resolved: { status: 'succeeded', label: 'Resolved' },
    ignored: { status: 'inactive', label: 'Ignored' },
};

export function IssueStatusBadge({ status }: { status: IssueStatus }) {
    return <StatusBadge status={STATUS[status].status} label={STATUS[status].label} />;
}

export const KIND: Record<IssueKind, { label: string; icon: typeof Zap }> = {
    exception: { label: 'Exception', icon: Zap },
    performance: { label: 'Performance', icon: Gauge },
    heartbeat: { label: 'Scheduled task', icon: HeartPulse },
    database: { label: 'Database', icon: Database },
};

export function IssueKindTag({ kind }: { kind: IssueKind }) {
    const { label, icon: Icon } = KIND[kind];

    return <Tag icon={<Icon />}>{label}</Tag>;
}

const PRIORITY: Record<Exclude<IssuePriority, 'none'>, { icon: typeof Zap; label: string }> = {
    low: { icon: SignalLow, label: 'Low' },
    medium: { icon: SignalMedium, label: 'Medium' },
    high: { icon: SignalHigh, label: 'High' },
    urgent: { icon: OctagonAlert, label: 'Urgent' },
};

export function PriorityTag({ priority, showNone = false }: { priority: IssuePriority; showNone?: boolean }) {
    if (priority === 'none') return showNone ? <Tag tone="faint">No priority</Tag> : null;
    const { icon: Icon, label } = PRIORITY[priority];

    return (
        <Tag tone={priority === 'urgent' ? 'danger' : 'neutral'} icon={<Icon />}>
            {label}
        </Tag>
    );
}

export function priorityLabel(priority: IssuePriority): string {
    return priority === 'none' ? 'No priority' : PRIORITY[priority].label;
}

type FrameGroup = { app: true; frame: Frame; index: number } | { app: false; frames: Frame[]; start: number };

/**
 * Parsed stack trace: application frames highlighted, runs of vendor frames collapsed into one expandable row
 * each, plus a raw view.
 */
export function StackTraceView({ frames, raw }: { frames: Frame[]; raw: string | null }) {
    const [view, setView] = useState<'parsed' | 'raw'>(frames.length > 0 ? 'parsed' : 'raw');
    const [open, setOpen] = useState<Set<number>>(new Set());
    const [allVendor, setAllVendor] = useState(false);

    const groups = useMemo(() => {
        const out: FrameGroup[] = [];
        frames.forEach((frame, index) => {
            const last = out[out.length - 1];
            if (frame.in_app) out.push({ app: true, frame, index });
            else if (last && !last.app) last.frames.push(frame);
            else out.push({ app: false, frames: [frame], start: index });
        });

        return out;
    }, [frames]);

    if (frames.length === 0 && !raw) {
        return <p className="text-fg-faint text-sm">No stack trace was reported.</p>;
    }

    const vendorCount = frames.filter((frame) => !frame.in_app).length;
    const toggle = (start: number) =>
        setOpen((current) => {
            const next = new Set(current);
            if (next.has(start)) next.delete(start);
            else next.add(start);

            return next;
        });

    return (
        <div className="grid min-w-0 gap-2">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-fg-muted text-xs">
                    {frames.length} frames · <span className="text-fg">{frames.length - vendorCount} in app</span>
                    {vendorCount > 0 && ` · ${vendorCount} vendor`}
                </p>
                <div className="flex items-center gap-2">
                    {view === 'parsed' && vendorCount > 0 && (
                        <Button size="sm" variant="ghost" onClick={() => setAllVendor((value) => !value)}>
                            {allVendor ? 'Collapse vendor frames' : 'Expand vendor frames'}
                        </Button>
                    )}
                    {raw && frames.length > 0 && (
                        <Segmented
                            label="Stack trace view"
                            value={view}
                            onValueChange={setView}
                            options={[
                                { value: 'parsed', label: 'Parsed' },
                                { value: 'raw', label: 'Raw' },
                            ]}
                        />
                    )}
                </div>
            </div>
            {view === 'raw' && raw ? (
                <CodeBlock code={raw} maxHeight={480} />
            ) : (
                <ol className="border-border bg-canvas divide-border divide-y overflow-hidden rounded-lg border font-mono text-xs">
                    {groups.map((group) => {
                        if (group.app) {
                            return (
                                <li key={group.index} className="bg-surface-1 flex min-w-0 items-baseline gap-3 px-3 py-2">
                                    <span className="text-fg-faint tabular w-6 shrink-0 text-right select-none">{group.index}</span>
                                    <FrameText frame={group.frame} app />
                                </li>
                            );
                        }

                        const expanded = allVendor || open.has(group.start);

                        return (
                            <Fragment key={`v${group.start}`}>
                                <li>
                                    <button
                                        type="button"
                                        onClick={() => toggle(group.start)}
                                        aria-expanded={expanded}
                                        className="text-fg-faint hover:bg-surface-2 hover:text-fg-muted flex w-full items-center gap-3 px-3 py-1.5 text-left transition-colors duration-150"
                                    >
                                        <span className="flex w-6 shrink-0 justify-end">
                                            {expanded ? <ChevronDown className="size-3.5" /> : <ChevronRight className="size-3.5" />}
                                        </span>
                                        {group.frames.length} vendor frame{group.frames.length === 1 ? '' : 's'}
                                    </button>
                                </li>
                                {expanded &&
                                    group.frames.map((frame, offset) => (
                                        <li key={group.start + offset} className="flex min-w-0 items-baseline gap-3 px-3 py-1.5">
                                            <span className="text-fg-faint tabular w-6 shrink-0 text-right select-none">{group.start + offset}</span>
                                            <FrameText frame={frame} />
                                        </li>
                                    ))}
                            </Fragment>
                        );
                    })}
                </ol>
            )}
        </div>
    );
}

function FrameText({ frame, app = false }: { frame: Frame; app?: boolean }) {
    if (!frame.file) {
        return <span className={cn('min-w-0 break-all', app ? 'text-fg' : 'text-fg-faint')}>{frame.raw}</span>;
    }

    return (
        <span className="min-w-0 break-all">
            <span className={cn(app ? 'text-fg font-medium' : 'text-fg-muted')}>{frame.function ?? '(anonymous)'}</span>
            <span className={app ? 'text-fg-muted' : 'text-fg-faint'}>
                {' '}
                {frame.file}
                {frame.line ? <span className={app ? 'text-primary' : undefined}>:{frame.line}</span> : null}
            </span>
        </span>
    );
}
