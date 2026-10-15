import { Button, Checkbox, Dialog, Field, Input, Section, Select, Skeleton, Tag, toast } from '@/components/falak';
import { HttpError, errorMessage, requestJson } from '@/lib/http';
import { type ServiceTabProps } from '@/lib/registry';
import { HardDrive, Plus, Unlink } from 'lucide-react';
import { useCallback, useEffect, useId, useState } from 'react';
import { UsageBar } from '../components/volume-ui';
import { type SiteVolumes, type Volume } from '../types';

/**
 * Settings → Storage of a site: the volumes it mounts. Container services attach volumes of their servers here
 * (attaching or detaching redeploys); classic sites' shared paths and compose stacks' volumes are listed read-only.
 */
export function VolumesSettings({ ctx }: ServiceTabProps) {
    const siteId = ctx.service.ref_id;
    const [data, setData] = useState<SiteVolumes | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [attaching, setAttaching] = useState(false);
    const [busy, setBusy] = useState<string | null>(null);

    const load = useCallback(async () => {
        try {
            setData((await requestJson<{ data: SiteVolumes }>(`/sites/${siteId}/volumes`)).data);
        } catch (e) {
            setError(errorMessage(e));
        }
    }, [siteId]);

    useEffect(() => void load(), [load]);

    if (!data) {
        return <Section title="Volumes">{error ? <p className="text-danger text-sm">{error}</p> : <Skeleton className="h-10" />}</Section>;
    }

    const isCompose = !!ctx.service.compose;
    const own = data.volumes.flatMap((volume) =>
        volume.attachments.filter((attachment) => attachment.attachable_id === siteId).map((attachment) => ({ volume, attachment })),
    );

    const detach = async (attachmentId: string) => {
        setBusy(attachmentId);
        try {
            await requestJson(`/volumes/attachments/${attachmentId}`, 'DELETE');
            toast.success('Volume detached', 'The service is redeploying.');
            await load();
            ctx.refresh();
        } catch (e) {
            toast.error('Could not detach', errorMessage(e));
        } finally {
            setBusy(null);
        }
    };

    return (
        <Section
            title="Volumes"
            description={
                data.attachable
                    ? 'Persistent data mounted into the container. Attaching or detaching redeploys the service.'
                    : isCompose
                      ? 'The named volumes of the compose file, once the stack is deployed. Change the file to mount others.'
                      : 'This site keeps its data in shared paths (Deploy → Shared paths); each one is a volume.'
            }
            aside={
                data.attachable &&
                data.can.manage && (
                    <Button size="sm" icon={<Plus />} onClick={() => setAttaching(true)} disabled={data.available.length === 0}>
                        Attach volume
                    </Button>
                )
            }
        >
            {own.length === 0 && (
                <p className="text-fg-muted text-sm">
                    {data.attachable && data.available.length === 0
                        ? 'No volumes yet. Create one on the server’s Volumes tab, then attach it here.'
                        : 'No volumes.'}
                </p>
            )}
            {own.map(({ volume, attachment }) => (
                <div key={attachment.id} className="border-border flex flex-wrap items-center gap-3 border-b pb-3 last:border-b-0 last:pb-0">
                    <HardDrive className="text-fg-faint size-4 shrink-0" aria-hidden />
                    <div className="grid min-w-0 flex-1 gap-0.5">
                        <a
                            href={volume.url}
                            className="text-fg truncate font-mono text-xs font-medium hover:underline"
                            onClick={(event) => {
                                // Over this panel; ⌘/Ctrl/⇧-click still opens the volume page.
                                if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;
                                event.preventDefault();
                                ctx.openLayer('volume', volume.id);
                            }}
                        >
                            {volume.name}
                        </a>
                        <span className="text-fg-muted flex items-center gap-1.5 font-mono text-xs">
                            {attachment.service ? `${attachment.service}: ` : ''}
                            {attachment.mount_path}
                            {attachment.read_only && <Tag tone="faint">read-only</Tag>}
                        </span>
                    </div>
                    <UsageBar volume={volume} />
                    {data.can.manage && attachment.detachable && volume.kind !== 'shared_path' && (
                        <Button
                            size="sm"
                            variant="ghost"
                            icon={<Unlink />}
                            loading={busy === attachment.id}
                            onClick={() => void detach(attachment.id)}
                        >
                            Detach
                        </Button>
                    )}
                </div>
            ))}
            <AttachVolumeDialog
                open={attaching}
                onOpenChange={setAttaching}
                siteId={siteId}
                available={data.available}
                onAttached={() => {
                    void load();
                    ctx.refresh();
                }}
            />
        </Section>
    );
}

function AttachVolumeDialog({
    open,
    onOpenChange,
    siteId,
    available,
    onAttached,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    siteId: string;
    available: Volume[];
    onAttached: () => void;
}) {
    const [form, setForm] = useState({ volume_id: '', mount_path: '/data', read_only: false });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const readOnlyId = useId();

    useEffect(() => {
        if (open) {
            setForm({ volume_id: available[0]?.id ?? '', mount_path: '/data', read_only: false });
            setErrors({});
        }
    }, [open, available]);

    const submit = async () => {
        setSaving(true);
        setErrors({});
        try {
            await requestJson(`/volumes/${form.volume_id}/attachments`, 'POST', {
                site_id: siteId,
                mount_path: form.mount_path.trim(),
                read_only: form.read_only,
            });
            toast.success('Volume attached', 'The service is redeploying.');
            onOpenChange(false);
            onAttached();
        } catch (e) {
            if (e instanceof HttpError) setErrors(e.errors);
            else toast.error('Could not attach', errorMessage(e));
        } finally {
            setSaving(false);
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={onOpenChange}
            title="Attach a volume"
            description="The service is redeployed so its container mounts it. Only volumes on the service’s servers are offered."
            footer={
                <>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button variant="primary" loading={saving} disabled={!form.volume_id || !form.mount_path.trim()} onClick={() => void submit()}>
                        Attach and redeploy
                    </Button>
                </>
            }
        >
            <div className="grid gap-4">
                <Field label="Volume" error={errors.volume ?? errors.site_id}>
                    <Select
                        value={form.volume_id}
                        onValueChange={(value) => setForm({ ...form, volume_id: value })}
                        options={available.map((volume) => ({ value: volume.id, label: `${volume.name} · ${volume.server?.name ?? ''}` }))}
                    />
                </Field>
                <Field label="Mount path" required error={errors.mount_path} hint="Absolute path inside the container.">
                    <Input mono value={form.mount_path} onChange={(event) => setForm({ ...form, mount_path: event.target.value })} />
                </Field>
                <label htmlFor={readOnlyId} className="text-fg-muted flex items-center gap-2 text-sm">
                    <Checkbox
                        id={readOnlyId}
                        checked={form.read_only}
                        onCheckedChange={(checked) => setForm({ ...form, read_only: checked === true })}
                    />
                    Read-only
                </label>
            </div>
        </Dialog>
    );
}
