import { Button, Callout, RelativeTime, Tag, Textarea } from '@/components/falak';
import { HttpError, requestJson } from '@/lib/http';
import { useState } from 'react';

/** The `approval` block of a config run (docs/API.md, Config as code). */
export interface ConfigApproval {
    required: boolean;
    risky: { op: string; path: string; rule: string | null; reason: string | null }[];
    expires_at: string | null;
    approved_by: string | null;
    approved_at: string | null;
    rejected_by: string | null;
    rejected_at: string | null;
    comment: string | null;
    can_approve: boolean;
    blocked_reason: string | null;
    /** The hash of the risky changes shown: an approval of another plan is refused. */
    plan_hash: string;
}

/**
 * The approval of a run with risky changes: what is risky and why, then approve or reject with an optional comment
 * (config.approve). Approving plans the run again; a plan that gained risky changes waits for another look.
 */
export function RunApproval({
    runId,
    status,
    approval,
    onChange,
}: {
    runId: string;
    status: string;
    approval: ConfigApproval;
    onChange: () => void;
}) {
    const [comment, setComment] = useState('');
    const [busy, setBusy] = useState<'approve' | 'reject' | null>(null);
    const [error, setError] = useState<string | null>(null);
    const waiting = status === 'awaiting_approval';

    const send = async (action: 'approve' | 'reject') => {
        setBusy(action);
        setError(null);
        try {
            await requestJson(`/config/runs/${runId}/${action}`, 'POST', { comment: comment || null, plan_hash: approval.plan_hash });
            setComment('');
        } catch (failure) {
            setError(failure instanceof HttpError ? (Object.values(failure.errors)[0] ?? failure.message) : 'The request failed.');
        } finally {
            setBusy(null);
            onChange();
        }
    };

    return (
        <div className="border-warning/40 grid gap-3 rounded-md border p-3" data-testid="config-run-approval">
            <div className="flex flex-wrap items-center gap-2 text-sm">
                <Tag tone={waiting ? 'warning' : approval.approved_at ? 'success' : 'neutral'}>
                    {waiting ? 'Waiting for an approval' : approval.approved_at ? 'Approved' : approval.rejected_at ? 'Rejected' : 'Approval closed'}
                </Tag>
                {waiting && approval.expires_at && (
                    <span className="text-fg-muted">
                        expires <RelativeTime value={approval.expires_at} />
                    </span>
                )}
                {approval.approved_at && (
                    <span className="text-fg-muted">
                        approved <RelativeTime value={approval.approved_at} />
                    </span>
                )}
                {approval.rejected_at && (
                    <span className="text-fg-muted">
                        rejected <RelativeTime value={approval.rejected_at} />
                    </span>
                )}
            </div>
            <ul className="grid gap-1 text-sm">
                {approval.risky.map((item) => (
                    <li key={`${item.op}:${item.path}:${item.rule}`} className="grid gap-0.5">
                        <span className="font-mono text-xs">
                            <Tag tone="warning" className="mr-1">
                                {item.rule ?? 'risky'}
                            </Tag>
                            {item.op} {item.path}
                        </span>
                        {item.reason && <span className="text-fg-muted">{item.reason}</span>}
                    </li>
                ))}
            </ul>
            {approval.comment && <p className="text-fg-muted text-sm">“{approval.comment}”</p>}
            {waiting &&
                (approval.can_approve ? (
                    <div className="grid gap-2">
                        <Textarea
                            value={comment}
                            onChange={(event) => setComment(event.target.value)}
                            placeholder="Comment (optional)"
                            rows={2}
                            aria-label="Approval comment"
                        />
                        <div className="flex gap-2">
                            <Button variant="primary" loading={busy === 'approve'} disabled={busy !== null} onClick={() => send('approve')}>
                                Approve and apply
                            </Button>
                            <Button variant="danger" loading={busy === 'reject'} disabled={busy !== null} onClick={() => send('reject')}>
                                Reject
                            </Button>
                        </div>
                    </div>
                ) : (
                    approval.blocked_reason && <p className="text-fg-muted text-sm">{approval.blocked_reason}</p>
                ))}
            {error && <Callout tone="danger">{error}</Callout>}
        </div>
    );
}
