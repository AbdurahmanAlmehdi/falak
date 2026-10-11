import { Button, Callout } from '@/components/falak';
import { requestJson } from '@/lib/http';
import { useCallback, useEffect, useState } from 'react';

/** Config's drift of an environment (`GET /projects/{p}/settings/config/drift?environment=`). */
export interface ConfigDrift {
    environment: string;
    status: 'in_sync' | 'drifted' | 'unknown';
    checked_at: string | null;
    since: string | null;
    baseline_commit: string | null;
    changes: { op: string; path: string }[];
}

/**
 * A banner on the canvas when the environment no longer matches the falak.yaml last applied to it. Renders nothing
 * for projects without a file, environments in sync, or when the user can't read config.
 */
export function ConfigDriftBanner({ projectId, environment }: { projectId: string; environment: string }) {
    const [drift, setDrift] = useState<ConfigDrift | null>(null);
    const [checking, setChecking] = useState(false);
    const url = `/projects/${projectId}/settings/config/drift`;

    useEffect(() => {
        let alive = true;
        requestJson<{ data: ConfigDrift }>(`${url}?environment=${encodeURIComponent(environment)}`)
            .then((response) => alive && setDrift(response.data))
            .catch(() => alive && setDrift(null));

        return () => {
            alive = false;
        };
    }, [url, environment]);

    const check = useCallback(async () => {
        setChecking(true);
        try {
            setDrift((await requestJson<{ data: ConfigDrift }>(url, 'POST', { environment })).data);
        } catch {
            // the banner stays as it was
        } finally {
            setChecking(false);
        }
    }, [url, environment]);

    if (drift?.status !== 'drifted') return null;

    const count = drift.changes.length;

    return (
        <div className="max-w-md" data-testid="config-drift-banner">
            <Callout
                tone="warning"
                title={`${drift.environment} drifted from falak.yaml`}
                action={
                    <div className="flex gap-2">
                        <Button size="sm" variant="ghost" loading={checking} onClick={check}>
                            Check again
                        </Button>
                        <Button size="sm" asChild>
                            <a href={`/projects/${projectId}/settings#config`}>Review</a>
                        </Button>
                    </div>
                }
            >
                {count} {count === 1 ? 'field differs' : 'fields differ'} from the file
                {drift.baseline_commit ? ` at ${drift.baseline_commit.slice(0, 7)}` : ''}:{' '}
                {drift.changes
                    .slice(0, 3)
                    .map((change) => change.path)
                    .join(', ')}
                {count > 3 ? ', …' : ''}. The next run puts them back.
            </Callout>
        </div>
    );
}
