import { Button, Callout, Dialog, Section, Select, StatusBadge, Tag, toast } from '@/components/falak';
import { useJson } from '@/hooks/use-json';
import { errorMessage, requestJson } from '@/lib/http';
import { type ServerSectionProps, type ServiceTabProps } from '@/lib/registry';
import { ArrowRightLeft, RotateCw, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { bytesLabel, type ItemState, type MoveCandidate, type SiteMove, type SiteMovePlan } from '../types';

const ITEM_STATUS: Record<ItemState, string> = {
    pending: 'pending',
    running: 'running',
    succeeded: 'succeeded',
    skipped: 'skipped',
    manual: 'needs-attention',
    failed: 'failed',
};

const ITEM_LABEL: Partial<Record<ItemState, string>> = { manual: 'Your turn' };

const active = (move: SiteMove | null | undefined) => !!move && ['queued', 'running', 'failed'].includes(move.status);

const candidateLabel = (candidate: MoveCandidate) =>
    `${candidate.name}${candidate.ipv4 ? ` (${candidate.ipv4})` : ''}${candidate.status !== 'active' ? ` · ${candidate.status_label}` : ''}`;

/** Site → Settings → Servers: "Move to another server" (the dialog) and the progress of the site's latest move. */
export function SiteMoveSettings({ ctx }: ServiceTabProps) {
    const siteId = ctx.service.ref_id;
    const state = useJson<{ move: SiteMove | null; candidates: MoveCandidate[] }>(`/sites/${siteId}/move`);
    const [open, setOpen] = useState(false);
    const move = state.data?.move ?? null;

    return (
        <Section
            title="Move to another server"
            description="Moves the site, the databases on its server that only it uses and its volumes to another server, then switches its traffic. Until the cutover the old server keeps serving it."
            aside={
                <Button icon={<ArrowRightLeft />} disabled={!state.data || active(move)} onClick={() => setOpen(true)}>
                    Move to another server…
                </Button>
            }
        >
            {move && move.status !== 'cancelled' ? (
                <MoveProgress moveId={move.id} initial={move} onChange={() => (state.reload(), ctx.refresh())} />
            ) : (
                <p className="text-fg-muted text-sm">No move yet.</p>
            )}
            {state.data && (
                <MoveDialog
                    open={open}
                    onOpenChange={setOpen}
                    siteId={siteId}
                    candidates={state.data.candidates}
                    onStarted={() => (setOpen(false), state.reload(), ctx.refresh())}
                />
            )}
        </Section>
    );
}

function MoveDialog({
    open,
    onOpenChange,
    siteId,
    candidates,
    onStarted,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    siteId: string;
    candidates: MoveCandidate[];
    onStarted: () => void;
}) {
    const [target, setTarget] = useState<string | undefined>();
    const [storage, setStorage] = useState<string | undefined>();
    const [plan, setPlan] = useState<SiteMovePlan | null>(null);
    const [planning, setPlanning] = useState(false);
    const [starting, setStarting] = useState(false);

    useEffect(() => {
        if (!open || !target) return;
        setPlanning(true);
        requestJson<{ data: SiteMovePlan }>(`/sites/${siteId}/move/plan`, 'POST', { server_id: target, storage_provider_id: storage ?? null })
            .then((response) => setPlan(response.data))
            .catch((e) => (setPlan(null), toast.error('Could not plan the move', errorMessage(e))))
            .finally(() => setPlanning(false));
    }, [open, siteId, target, storage]);

    const start = async () => {
        setStarting(true);
        try {
            await requestJson(`/sites/${siteId}/move`, 'POST', { server_id: target, storage_provider_id: plan?.storage_provider_id ?? null });
            toast.success('Move started');
            onStarted();
        } catch (e) {
            toast.error('Could not start the move', errorMessage(e));
        } finally {
            setStarting(false);
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={onOpenChange}
            size="lg"
            title="Move to another server"
            description="A dry run first: nothing changes until you start the move."
            footer={
                <>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button variant="primary" loading={starting} disabled={!plan || planning || plan.blocking.length > 0} onClick={start}>
                        Start the move
                    </Button>
                </>
            }
        >
            <div className="grid gap-4">
                <Select
                    aria-label="New server"
                    className="w-full"
                    value={target}
                    onValueChange={setTarget}
                    placeholder="Choose the new server…"
                    options={candidates.map((candidate) => ({ value: candidate.id, label: candidateLabel(candidate) }))}
                />
                {candidates.length === 0 && <p className="text-fg-muted text-sm">No other server can host sites: add one first.</p>}
                {planning && <p className="text-fg-muted text-sm">Planning…</p>}
                {plan && !planning && <PlanView plan={plan} storage={storage ?? plan.storage_provider_id ?? undefined} onStorage={setStorage} />}
            </div>
        </Dialog>
    );
}

function PlanView({ plan, storage, onStorage }: { plan: SiteMovePlan; storage: string | undefined; onStorage: (id: string) => void }) {
    const address = [plan.target?.ipv4, plan.target?.ipv6].filter(Boolean).join(' / ') || plan.target?.name;
    const manual = plan.domains.filter((domain) => domain.kind === 'manual');

    return (
        <div className="grid gap-3 text-sm">
            {plan.blocking.map((problem) => (
                <Callout key={problem} tone="danger">
                    {problem}
                </Callout>
            ))}
            {plan.problems.map((problem) => (
                <Callout key={problem} tone="warning">
                    {problem}
                </Callout>
            ))}

            {manual.length > 0 && (
                <Callout tone={plan.stateful ? 'danger' : 'warning'} title={`Change DNS to ${address}`}>
                    {manual.map((domain) => domain.name).join(', ')}{' '}
                    {plan.stateful
                        ? 'stop working at the cutover until their DNS records point at the new server: change them as soon as the move reaches it.'
                        : 'keep working: the old server serves the site until their DNS records point at the new server (at most 48 h after the cutover).'}
                </Callout>
            )}

            <div className="grid gap-1">
                <span className="text-fg font-medium">Expected downtime</span>
                <span className="text-fg-muted">{plan.downtime}</span>
            </div>

            {plan.capacity && (
                <div className="text-fg-muted">
                    Memory: the site may use {plan.capacity.unlimited ? 'unlimited' : `${plan.capacity.needed_mb} MB`}; {plan.target?.name} has{' '}
                    {plan.capacity.free_mb === null ? 'not reported its memory' : `${plan.capacity.free_mb} MB not promised to other services`}.
                    {plan.capacity.volume_bytes > 0 && ` Volumes to copy: ${bytesLabel(plan.capacity.volume_bytes)}.`}
                </div>
            )}

            <div className="grid gap-1">
                <span className="text-fg font-medium">What moves</span>
                <ul className="grid gap-1">
                    {plan.databases.map((instance) => (
                        <li key={instance.id} className="flex flex-wrap items-center gap-2">
                            <Tag>{instance.engine}</Tag>
                            <span className="text-fg">{instance.name}</span>
                            <span className="text-fg-muted">
                                {instance.move ? 'backed up, recreated on the new server and restored' : `stays: shared (${instance.reason})`}
                            </span>
                        </li>
                    ))}
                    {plan.volumes.map((volume) => (
                        <li key={volume.id} className="flex flex-wrap items-center gap-2">
                            <Tag>{volume.kind} volume</Tag>
                            <span className="text-fg">{volume.name}</span>
                            <span className="text-fg-muted">
                                copied through object storage{volume.used_bytes ? ` (${bytesLabel(volume.used_bytes)})` : ''}
                            </span>
                        </li>
                    ))}
                    {plan.domains.map((domain) => (
                        <li key={domain.name} className="flex flex-wrap items-center gap-2">
                            <Tag>domain</Tag>
                            <span className="text-fg font-mono">{domain.name}</span>
                            <span className="text-fg-muted">
                                {domain.kind === 'managed' && 'its Cloudflare record follows on its own'}
                                {domain.kind === 'generated' && 'replaced by a name for the new IP'}
                                {domain.kind === 'manual' && `point it at ${address}`}
                            </span>
                        </li>
                    ))}
                    {plan.databases.length + plan.volumes.length + plan.domains.length === 0 && (
                        <li className="text-fg-muted">Only the site itself: no databases, volumes or domains to carry.</li>
                    )}
                </ul>
            </div>

            {plan.stateful && plan.storage_providers.length > 0 && (
                <div className="grid gap-1">
                    <span className="text-fg font-medium">Carried through</span>
                    <Select
                        aria-label="Storage provider"
                        className="w-72 max-w-full"
                        value={storage}
                        onValueChange={onStorage}
                        options={plan.storage_providers.map((provider) => ({ value: provider.id, label: provider.name }))}
                    />
                </div>
            )}
        </div>
    );
}

/** A move's steps, live while it runs; retry a failed step, cancel before the cutover. */
function MoveProgress({ moveId, initial, onChange }: { moveId: string; initial: SiteMove; onChange: () => void }) {
    const live = useJson<SiteMove>(`/site-moves/${moveId}`, { interval: ['queued', 'running'].includes(initial.status) ? 5000 : false });
    const move = live.data ?? initial;

    useEffect(() => {
        if (live.data && live.data.status !== initial.status) onChange();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [live.data?.status]);

    const act = async (url: string, label: string) => {
        try {
            const response = await requestJson<{ data: SiteMove }>(url, 'POST');
            live.setData(response.data);
            onChange();
        } catch (e) {
            toast.error(label, errorMessage(e));
        }
    };

    return (
        <div className="grid gap-3">
            <div className="flex flex-wrap items-center gap-2 text-sm">
                <StatusBadge status={move.status} />
                <span className="text-fg">
                    {move.from_server.name} → {move.target_server.name}
                </span>
                {move.cancellable && (
                    <Button
                        size="sm"
                        variant="ghost"
                        className="ml-auto"
                        icon={<X />}
                        onClick={() => act(`/site-moves/${move.id}/cancel`, 'Could not cancel')}
                    >
                        Cancel the move
                    </Button>
                )}
            </div>
            <ol className="grid gap-3">
                {(move.steps ?? []).map((step) => (
                    <li key={step.key} className="grid gap-1.5">
                        <div className="flex items-center gap-2 text-sm">
                            <StatusBadge status={step.status} />
                            <span className="text-fg font-medium">{step.title}</span>
                            {step.status === 'failed' && (
                                <Button
                                    size="sm"
                                    className="ml-auto"
                                    icon={<RotateCw />}
                                    onClick={() => act(`/site-moves/${move.id}/steps/${step.key}/retry`, 'Could not retry')}
                                >
                                    Retry
                                </Button>
                            )}
                        </div>
                        {step.items.length > 0 && step.status !== 'pending' && (
                            <ul className="border-border ml-2 grid gap-1 border-l pl-4 text-sm">
                                {step.items.map((item) => (
                                    <li key={item.id} className="flex flex-wrap items-baseline gap-2">
                                        <StatusBadge status={ITEM_STATUS[item.state]} label={ITEM_LABEL[item.state]} />
                                        <span className="text-fg">{item.label}</span>
                                        {item.message && <span className="text-fg-muted">{item.message}</span>}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </li>
                ))}
            </ol>
        </div>
    );
}

/** Server page: "Move all sites" (one after another) and the moves off this server. */
export function ServerMoveSitesCard({ serverId }: ServerSectionProps) {
    const state = useJson<{ sites: number; moves: SiteMove[]; candidates: MoveCandidate[] }>(`/servers/${serverId}/site-moves`, { interval: 10000 });
    const [open, setOpen] = useState(false);
    const [target, setTarget] = useState<string | undefined>();
    const [starting, setStarting] = useState(false);
    const data = state.data;

    if (!data || (data.sites === 0 && data.moves.length === 0)) return null;
    const busy = data.moves.some((move) => ['queued', 'running'].includes(move.status));

    const start = async () => {
        setStarting(true);
        try {
            await requestJson(`/servers/${serverId}/move-sites`, 'POST', { server_id: target });
            toast.success('Moves queued');
            setOpen(false);
            await state.reload();
        } catch (e) {
            toast.error('Could not move the sites', errorMessage(e));
        } finally {
            setStarting(false);
        }
    };

    return (
        <Section
            title="Move sites to another server"
            description="Moves every site of this server, one after another, with the databases only it uses and its volumes. Each site's settings show its own plan and progress; a failed move holds the queue until it is retried or cancelled."
            aside={
                <Button icon={<ArrowRightLeft />} disabled={data.sites === 0 || busy} onClick={() => setOpen(true)}>
                    Move all sites…
                </Button>
            }
        >
            {data.moves.length === 0 ? (
                <p className="text-fg-muted text-sm">{data.sites === 1 ? '1 site runs here.' : `${data.sites} sites run here.`}</p>
            ) : (
                <ul className="divide-border grid divide-y text-sm">
                    {data.moves.map((move) => (
                        <li key={move.id} className="flex flex-wrap items-center gap-2 py-1.5">
                            <StatusBadge status={move.status} />
                            <span className="text-fg">{move.site.name}</span>
                            <span className="text-fg-muted ml-auto">→ {move.target_server.name}</span>
                        </li>
                    ))}
                </ul>
            )}
            <Dialog
                open={open}
                onOpenChange={setOpen}
                title="Move all sites"
                description="Each site gets the same plan as its own “Move to another server” (databases only it uses and its volumes move with it; DNS records Falak does not manage need changing). Object storage: the organization's first backup storage provider."
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setOpen(false)}>
                            Cancel
                        </Button>
                        <Button variant="primary" loading={starting} disabled={!target} onClick={start}>
                            Move {data.sites} {data.sites === 1 ? 'site' : 'sites'}
                        </Button>
                    </>
                }
            >
                <Select
                    aria-label="New server"
                    className="w-full"
                    value={target}
                    onValueChange={setTarget}
                    placeholder="Choose the new server…"
                    options={data.candidates.map((candidate) => ({ value: candidate.id, label: candidateLabel(candidate) }))}
                />
            </Dialog>
        </Section>
    );
}
