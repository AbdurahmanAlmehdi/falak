import { useEffect, useState } from 'react';
import { getJson } from '../lib';

/** GET /telemetry/sites/{siteId} (Falak\Telemetry\Http\Controllers\SiteTelemetryController::context). */
export interface SiteTelemetryContext {
    site: { id: string; name: string; runtime?: string };
    servers: { id: string; name: string }[];
    levels: string[];
    ranges: string[];
    configured: { logs: boolean; traces: boolean; metrics: boolean };
    links: { logs: string; traces: string; grafana: string | null };
}

export type Loadable<T> = { status: 'loading' } | { status: 'ready'; data: T } | { status: 'error'; error: unknown };

export function useSiteTelemetryContext(siteId: string): [Loadable<SiteTelemetryContext>, () => void] {
    const [state, setState] = useState<Loadable<SiteTelemetryContext>>({ status: 'loading' });
    const [attempt, setAttempt] = useState(0);

    useEffect(() => {
        const controller = new AbortController();
        setState({ status: 'loading' });
        getJson<SiteTelemetryContext>(`/telemetry/sites/${encodeURIComponent(siteId)}`, controller.signal)
            .then((data) => setState({ status: 'ready', data }))
            .catch((error: unknown) => {
                if (!controller.signal.aborted) setState({ status: 'error', error });
            });

        return () => controller.abort();
    }, [siteId, attempt]);

    return [state, () => setAttempt((value) => value + 1)];
}
