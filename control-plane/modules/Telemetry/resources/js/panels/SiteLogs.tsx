import { Button } from '@/components/falak/button';
import { Select } from '@/components/falak/select';
import { SkeletonRows } from '@/components/falak/skeleton';
import { FileText } from 'lucide-react';
import { useMemo, useState } from 'react';
import { BackendError, NotConfigured } from '../components/backend-state';
import { LogSourcesDialog } from '../components/log-sources-dialog';
import { LogStreamView, useLogStream, type LogFilters } from '../components/log-stream';
import { useSiteTelemetryContext } from '../components/site-context';
import { sourceFilters, sourceOptions, type SourceChoice } from '../log-sources';

const ANY = '__any__';

export interface SiteLogsProps {
    siteId: string;
    /** Compose sites: show one compose service (Services tab → logs). */
    composeService?: string;
    onClearComposeService?: () => void;
}

/**
 * Service panel → Logs tab (docs/UI_DESIGN.md §5.1): live Loki stream for one site with search, level, source and
 * server filters, follow/pause, and click a line → its trace. Sources (docs/plans/OBSERVABILITY.md phase 2) edits the
 * files inside the site's containers and the PHP-FPM slow log threshold.
 */
export default function SiteLogs({ siteId, composeService, onClearComposeService }: SiteLogsProps) {
    const [context, retryContext] = useSiteTelemetryContext(siteId);
    const [level, setLevel] = useState<string | undefined>();
    const [server, setServer] = useState<string | undefined>();
    const [source, setSource] = useState<SourceChoice>('all');
    const [sourcesOpen, setSourcesOpen] = useState(false);
    const [range, setRange] = useState('1h');
    const [following, setFollowing] = useState(true);
    const configured = context.status === 'ready' && context.data.configured.logs;
    const runtime = context.status === 'ready' ? context.data.site.runtime : undefined;
    const filters = useMemo<LogFilters>(
        // Edge requests are the deployment panel's Network Logs: this tab shows what the app itself logs unless the
        // source filter asks for them.
        () => ({ site_id: siteId, ...sourceFilters(source), level, server_id: server, range, compose_service: composeService }),
        [siteId, source, level, server, range, composeService],
    );
    const stream = useLogStream(filters, { enabled: configured, follow: following });
    const servers = useMemo(() => (context.status === 'ready' ? context.data.servers : []), [context]);
    const serverNames = useMemo(() => Object.fromEntries(servers.map((item) => [item.id.toLowerCase(), item.name])), [servers]);

    if (context.status === 'loading') return <SkeletonRows rows={8} />;
    if (context.status === 'error') return <BackendError size="sm" backend="Loki" error={context.error} onRetry={retryContext} />;
    if (!configured) return <NotConfigured size="sm" backend="Loki" />;
    if (stream.state.status === 'error') return <BackendError size="sm" backend="Loki" error={stream.state.error} onRetry={stream.retry} />;

    return (
        <LogStreamView
            lines={stream.lines}
            live={stream.live}
            following={following}
            onFollowChange={setFollowing}
            olderCursor={stream.olderCursor}
            loadingOlder={stream.loadingOlder}
            onLoadOlder={() => void stream.loadOlder()}
            loading={stream.state.status === 'loading'}
            serverNames={serverNames}
            height="min(640px, calc(100vh - 16rem))"
            emptyText="No log lines from this site in this time range."
            toolbar={
                <>
                    {composeService && (
                        <button
                            type="button"
                            onClick={onClearComposeService}
                            className="border-border bg-surface-2 text-fg-muted hover:text-fg inline-flex h-7 items-center gap-1.5 rounded-md border px-2 font-mono text-xs"
                            title="Show every service"
                            data-testid="compose-service-filter"
                        >
                            service: {composeService} <span aria-hidden>×</span>
                        </button>
                    )}
                    <Select
                        size="sm"
                        className="w-44"
                        aria-label="Source"
                        value={source}
                        onValueChange={setSource}
                        options={sourceOptions(runtime)}
                    />
                    <Select
                        size="sm"
                        className="w-28"
                        aria-label="Level"
                        value={level ?? ANY}
                        onValueChange={(value) => setLevel(value === ANY ? undefined : value)}
                        options={[{ value: ANY, label: 'Any level' }, ...context.data.levels.map((item) => ({ value: item, label: item }))]}
                    />
                    {servers.length > 1 && (
                        <Select
                            size="sm"
                            className="w-28"
                            aria-label="Server"
                            value={server ?? ANY}
                            onValueChange={(value) => setServer(value === ANY ? undefined : value)}
                            options={[{ value: ANY, label: 'All servers' }, ...servers.map((item) => ({ value: item.id, label: item.name }))]}
                        />
                    )}
                    <Select
                        size="sm"
                        className="w-20"
                        aria-label="Time range"
                        value={range}
                        onValueChange={setRange}
                        options={['15m', '1h', '6h', '24h', '7d'].map((value) => ({ value, label: value }))}
                    />
                    <Button size="sm" variant="ghost" onClick={() => setSourcesOpen(true)} data-testid="log-sources-open">
                        <FileText className="size-3.5" /> Sources
                    </Button>
                    <LogSourcesDialog siteId={siteId} open={sourcesOpen} onOpenChange={setSourcesOpen} />
                </>
            }
        />
    );
}
