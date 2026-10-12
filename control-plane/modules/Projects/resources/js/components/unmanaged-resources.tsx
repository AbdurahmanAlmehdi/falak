import { Button, ConfirmDestructive, RelativeTime, Tag } from '@/components/falak';
import { HttpError, requestJson } from '@/lib/http';
import { Trash2 } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

/** What a config run detached (Config's `GET /projects/{p}/settings/config/unmanaged`). */
interface UnmanagedResource {
    id: string;
    environment: string | null;
    unit: string | null;
    resource_type: string;
    resource_id: string;
    label: string;
    reason: 'removed_from_file' | 'environment_released';
    run_id: string | null;
    detached_at: string;
    delete_url: string | null;
    delete_body: Record<string, string> | null;
}

const REASONS: Record<UnmanagedResource['reason'], string> = {
    removed_from_file: 'removed from the file',
    environment_released: 'environment released',
};

/**
 * Resources falak.yaml no longer manages but kept (gone from the file, or released with their environment). Deleting
 * one goes through its own module (its permissions and confirmation); adopting one means adding it back to the file.
 */
export function UnmanagedResources({ projectId, canDismiss }: { projectId: string; canDismiss: boolean }) {
    const [items, setItems] = useState<UnmanagedResource[] | null>(null);
    const [deleting, setDeleting] = useState<UnmanagedResource | null>(null);
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);

    const load = useCallback(async () => {
        const response = await requestJson<{ data: UnmanagedResource[] }>(`/projects/${projectId}/settings/config/unmanaged`);
        setItems(response.data);
    }, [projectId]);

    useEffect(() => {
        void load().catch(() => setItems([]));
    }, [load]);

    const confirmText = (item: UnmanagedResource) => Object.values(item.delete_body ?? {})[0] ?? item.label;

    const remove = async () => {
        if (!deleting?.delete_url) return;
        setProcessing(true);
        setError(undefined);
        try {
            await requestJson(deleting.delete_url, 'DELETE', deleting.delete_body ?? undefined);
            if (canDismiss) {
                await requestJson(`/config/unmanaged/${deleting.id}`, 'DELETE').catch(() => undefined);
            }
            setDeleting(null);
            await load();
        } catch (failure) {
            setError(failure instanceof HttpError ? (Object.values(failure.errors)[0] ?? failure.message) : 'Deleting failed.');
        } finally {
            setProcessing(false);
        }
    };

    if (items === null || items.length === 0) return null;

    const environments = Array.from(new Set(items.map((item) => item.environment ?? '')));

    return (
        <div className="grid gap-2" data-testid="config-unmanaged">
            <h3 className="text-fg text-sm font-medium">Unmanaged resources</h3>
            <p className="text-fg-muted text-sm">
                The file no longer manages these; they are kept as they are. Adopt one into falak.yaml by adding its unit or key back to the file, or
                delete it here (with its own permissions).
            </p>
            {environments.map((environment) => (
                <div key={environment} className="grid gap-1">
                    <span className="text-fg-muted text-xs font-medium">{environment || 'unknown environment'}</span>
                    <ul className="divide-border border-border divide-y rounded-md border">
                        {items
                            .filter((item) => (item.environment ?? '') === environment)
                            .map((item) => (
                                <li key={item.id} className="flex flex-wrap items-center gap-2 px-3 py-2 text-sm">
                                    <span className="text-fg font-medium">{item.label}</span>
                                    {item.unit && <Tag mono>{item.unit}</Tag>}
                                    <span className="text-fg-muted">{REASONS[item.reason]}</span>
                                    <span className="text-fg-faint">
                                        <RelativeTime value={item.detached_at} />
                                    </span>
                                    {item.delete_url && (
                                        <Button
                                            className="ml-auto"
                                            size="sm"
                                            variant="ghost"
                                            icon={<Trash2 />}
                                            onClick={() => {
                                                setError(undefined);
                                                setDeleting(item);
                                            }}
                                        >
                                            Delete
                                        </Button>
                                    )}
                                </li>
                            ))}
                    </ul>
                </div>
            ))}
            <ConfirmDestructive
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title={`Delete ${deleting?.label ?? 'resource'}?`}
                description="It is deleted the way its own page deletes it. The file no longer manages it, so nothing brings it back."
                confirmText={deleting ? confirmText(deleting) : ''}
                onConfirm={remove}
                processing={processing}
                error={error}
            />
        </div>
    );
}
