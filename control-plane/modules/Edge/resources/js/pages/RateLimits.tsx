import { Button } from '@/components/falak/button';
import { DataTable } from '@/components/falak/data-table';
import { Dialog } from '@/components/falak/dialog';
import { Field } from '@/components/falak/field';
import { Input } from '@/components/falak/input';
import { RelativeTime } from '@/components/falak/relative-time';
import { Select } from '@/components/falak/select';
import { toast } from '@/components/falak/toast';
import NetworkLayout from '@/layouts/network-layout';
import { router, useForm } from '@inertiajs/react';
import { Gauge, Plus, Trash2 } from 'lucide-react';
import { useState, type FormEventHandler } from 'react';
import { describeLimit, WINDOWS } from '../rate-limits';

interface LimitRow {
    id: string;
    site: { id: string; name: string };
    path: string;
    events: number;
    window_s: number;
    created_at: string;
}

interface SiteOption {
    id: string;
    name: string;
    /** Why the site's edge cannot rate-limit (FrankenPHP edge, outdated agent), or null. */
    refusal: string | null;
}

interface Props {
    limits: LimitRow[];
    sites: SiteOption[];
    /** Servers whose edge applied its config without the rate limits (the agent's reason). */
    notApplied?: { server: string; reason: string }[];
    can: { manage: boolean };
}

function AddDialog({ sites, open, onOpenChange }: { sites: SiteOption[]; open: boolean; onOpenChange: (open: boolean) => void }) {
    const form = useForm({ site_id: sites.find((site) => site.refusal === null)?.id ?? '', path: '/login', events: '10', window_s: '60' });
    const refusal = sites.find((site) => site.id === form.data.site_id)?.refusal ?? null;

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, events: Number(data.events), window_s: Number(data.window_s) }));
        form.post('/network/rate-limits', {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(`Rate limit on ${form.data.path} saved`, 'Applied at the edge within a few seconds.');
                onOpenChange(false);
            },
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={onOpenChange}
            title="Rate-limit a path"
            description="Requests per client IP to a path of the site; more get 429 Too Many Requests until the window passes. Other sites are not affected."
        >
            <form onSubmit={submit} className="grid gap-4">
                <Field label="Site" error={form.errors.site_id ?? refusal}>
                    <Select
                        value={form.data.site_id}
                        onValueChange={(value) => form.setData('site_id', value)}
                        options={sites.map((site) => ({ value: site.id, label: site.name }))}
                        placeholder="Pick a site"
                    />
                </Field>
                <Field label="Path" hint="A trailing * matches a prefix, e.g. /api/*." error={form.errors.path}>
                    <Input value={form.data.path} onChange={(event) => form.setData('path', event.target.value)} className="font-mono" />
                </Field>
                <div className="grid grid-cols-2 gap-3">
                    <Field label="Requests" error={form.errors.events}>
                        <Input
                            type="number"
                            min={1}
                            max={100000}
                            value={form.data.events}
                            onChange={(event) => form.setData('events', event.target.value)}
                        />
                    </Field>
                    <Field label="Per" error={form.errors.window_s}>
                        <Select value={form.data.window_s} onValueChange={(value) => form.setData('window_s', value)} options={WINDOWS} />
                    </Field>
                </div>
                <p className="text-fg-muted text-xs">
                    The first rule on a server installs Falak&apos;s Caddy build (Caddy with the rate-limit module) and restarts its edge once.
                </p>
                <div className="flex justify-end gap-2">
                    <Button type="button" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button type="submit" variant="primary" loading={form.processing} disabled={refusal !== null || form.data.site_id === ''}>
                        Save
                    </Button>
                </div>
            </form>
        </Dialog>
    );
}

export default function RateLimits({ limits, sites, notApplied = [], can }: Props) {
    const [adding, setAdding] = useState(false);

    const remove = (limit: LimitRow) =>
        router.delete(`/network/rate-limits/${limit.id}`, {
            preserveScroll: true,
            onSuccess: () => toast.success(`Rate limit on ${limit.path} removed`),
        });

    return (
        <NetworkLayout
            section="rate-limits"
            title="Rate limits"
            description="Requests per client IP on paths of your sites, enforced by the server's edge (Caddy)."
            actions={
                can.manage && (
                    <Button variant="primary" icon={<Plus />} onClick={() => setAdding(true)} disabled={sites.length === 0}>
                        Rate-limit a path
                    </Button>
                )
            }
        >
            {notApplied.map((row) => (
                <p key={row.server} role="alert" className="border-warning/40 bg-warning-soft text-warning rounded-lg border px-4 py-3 text-sm">
                    Rate limits not applied on {row.server}: {row.reason}
                </p>
            ))}
            <DataTable
                label="Rate limits"
                rows={limits}
                rowKey={(limit) => limit.id}
                empty={{
                    icon: <Gauge />,
                    title: 'No rate limits',
                    description: 'Slow down credential stuffing and scrapers: e.g. 10 requests a minute per IP on /login.',
                }}
                columns={[
                    {
                        id: 'site',
                        header: 'Site',
                        sortValue: (limit) => limit.site.name,
                        cell: (limit) => <span className="text-fg font-medium">{limit.site.name}</span>,
                    },
                    { id: 'path', header: 'Path', cell: (limit) => <span className="font-mono text-xs">{limit.path}</span> },
                    { id: 'limit', header: 'Limit', cell: (limit) => describeLimit(limit.events, limit.window_s) },
                    { id: 'created', header: 'Added', hideOnMobile: true, cell: (limit) => <RelativeTime value={limit.created_at} /> },
                ]}
                rowActions={can.manage ? (limit) => [{ label: 'Remove', icon: <Trash2 />, danger: true, onSelect: () => remove(limit) }] : undefined}
            />
            {can.manage && adding && <AddDialog sites={sites} open={adding} onOpenChange={setAdding} />}
        </NetworkLayout>
    );
}
