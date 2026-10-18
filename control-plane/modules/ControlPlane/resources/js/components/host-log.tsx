import { Callout, LogViewer, StatusBadge } from '@/components/falak';
import { requestJson } from '@/lib/http';
import { useEffect, useState } from 'react';
import { currentRequest, type HostStatusResponse } from '../types';

const POLL_MS = 2000;

/**
 * Polls the host channel every 2 s while `active`. While the panel is down (an update restarts it) the status request
 * fails: it then polls /up until the panel answers again and resumes.
 */
export function useHostStatus(active: boolean): { data: HostStatusResponse | null; down: boolean } {
    const [data, setData] = useState<HostStatusResponse | null>(null);
    const [down, setDown] = useState(false);

    useEffect(() => {
        if (!active) return;

        let stopped = false;
        let timer: number | undefined;
        let panelDown = false;

        const tick = async () => {
            try {
                if (panelDown) {
                    const up = await fetch('/up', { credentials: 'same-origin', cache: 'no-store' });
                    if (!up.ok) throw new Error('down');
                    panelDown = false;
                    setDown(false);
                }

                const body = await requestJson<HostStatusResponse>('/settings/falak-updates/status');
                if (!stopped) setData(body);
            } catch {
                panelDown = true;
                if (!stopped) setDown(true);
            }

            if (!stopped) timer = window.setTimeout(() => void tick(), POLL_MS);
        };

        void tick();

        return () => {
            stopped = true;
            window.clearTimeout(timer);
        };
    }, [active]);

    return { data, down };
}

const LABELS = { pending: 'Waiting for the host', running: 'Running', succeeded: 'Succeeded', failed: 'Failed' } as const;
const BADGES = { pending: 'waiting', running: 'running', succeeded: 'succeeded', failed: 'failed' } as const;

/** The followed host request: its state and the last 64 KiB of its log (plain text). */
export function HostLog({ data, down, label }: { data: HostStatusResponse | null; down: boolean; label: string }) {
    const request = data ? currentRequest(data.channel) : null;

    return (
        <div className="grid gap-3">
            {down && (
                <Callout tone="info" title="The panel is restarting">
                    Waiting for it to come back; this page resumes on its own.
                </Callout>
            )}
            {request && (
                <div className="flex items-center gap-2 text-sm">
                    <StatusBadge
                        status={BADGES[request.state]}
                        label={LABELS[request.state]}
                        pulse={request.state === 'pending' || request.state === 'running'}
                    />
                    {data?.channel.status?.exit_code != null && request.state === 'failed' && (
                        <span className="text-fg-muted">exit code {data.channel.status.exit_code}</span>
                    )}
                </div>
            )}
            {request?.state === 'failed' && data?.channel.status?.error && (
                <Callout tone="danger" title="The host refused the request">
                    {data.channel.status.error}
                </Callout>
            )}
            {data?.channel.status?.stale && (
                <Callout tone="warning" title="The last host request looks stuck">
                    It has been running for more than 2 hours. Check it on the host (journalctl -u falak-host.service); a new request can be started.
                </Callout>
            )}
            {data?.channel.pending?.stale && (
                <Callout tone="warning" title="The host has not picked up the last request">
                    It has waited more than 15 minutes. Check falak-host.path on the host (systemctl status falak-host.path); a new request replaces
                    it.
                </Callout>
            )}
            <LogViewer
                lines={(data?.log ?? '').split('\n').filter((line, index, all) => line !== '' || index < all.length - 1)}
                label={label}
                follow
                streaming={request?.state === 'running'}
                height={320}
                emptyText="No output yet."
            />
        </div>
    );
}
