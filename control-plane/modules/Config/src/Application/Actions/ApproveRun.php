<?php

namespace Falak\Config\Application\Actions;

use Falak\Config\Application\Runs\RiskSet;
use Falak\Config\Application\Runs\RunEngine;
use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Config\Domain\Models\ConfigSource;

/**
 * Approve a run waiting for an approval. The approval covers the risky changes of the plan the approver saw; the run
 * plans again and applies, unless the fresh plan has risky changes beyond them (then it waits again).
 */
final class ApproveRun
{
    public const APPROVED = 'approved';

    public const NOT_WAITING = 'not_waiting';

    public const BLOCKED = 'blocked';

    public const PLAN_CHANGED = 'plan_changed';

    public function __construct(private readonly RunEngine $engine) {}

    /** Why $userId can't approve the run (null: they can, given config.approve). */
    public static function blockedReason(ConfigRun $run, ?string $userId): ?string
    {
        $source = ConfigSource::query()->where('project_id', $run->project_id)->first();

        if ($source?->separate_approver && $run->requested_by !== null && $run->requested_by === $userId) {
            return 'You requested this run: another approver must approve it.';
        }

        return null;
    }

    /**
     * Approve the plan the approver was shown ($planHash, the run's `approval.plan_hash`). The claim is one conditional
     * update (awaiting_approval → planning), so two approvals, an expiry or a newer run can't both win.
     *
     * @return self::APPROVED|self::NOT_WAITING|self::BLOCKED|self::PLAN_CHANGED
     */
    public function __invoke(ConfigRun $run, string $userId, string $planHash, ?string $comment = null): string
    {
        if (self::blockedReason($run, $userId) !== null) {
            return self::BLOCKED;
        }

        $run->refresh();

        if ($run->status !== RunStatus::AwaitingApproval) {
            return self::NOT_WAITING;
        }

        if ($run->approval_expires_at !== null && $run->approval_expires_at->isPast()) {
            ConfigRun::query()->whereKey($run->id)->where('status', RunStatus::AwaitingApproval)->update([
                'status' => RunStatus::Cancelled->value, 'cancel_reason' => 'expired', 'error' => 'The approval expired.', 'finished_at' => now(), 'updated_at' => now(),
            ]);

            return self::NOT_WAITING;
        }

        // [decision] Nothing older than the newest run of the environment is approvable.
        if (ConfigRun::query()->where('environment_id', $run->environment_id)->where('id', '>', $run->id)->exists()) {
            ConfigRun::query()->whereKey($run->id)->where('status', RunStatus::AwaitingApproval)->update([
                'status' => RunStatus::Cancelled->value, 'cancel_reason' => 'superseded', 'error' => 'Superseded by a newer run.', 'finished_at' => now(), 'updated_at' => now(),
            ]);

            return self::NOT_WAITING;
        }

        $changes = (array) ($run->plan['changes'] ?? []);

        if (! hash_equals(RiskSet::hash($changes), $planHash)) {
            return self::PLAN_CHANGED;
        }

        $claimed = ConfigRun::query()->whereKey($run->id)->where('status', RunStatus::AwaitingApproval)->where('updated_at', $run->getRawOriginal('updated_at'))->update([
            'status' => RunStatus::Planning->value,
            'approved_by' => $userId,
            'approved_at' => now(),
            'approved_risky' => json_encode(RiskSet::of($changes)),
            'approval_comment' => $comment,
            'updated_at' => now(),
        ]);

        if ($claimed !== 1) {
            return self::NOT_WAITING;
        }

        $this->engine->apply($run->refresh());

        return $run->refresh()->status === RunStatus::AwaitingApproval ? self::PLAN_CHANGED : self::APPROVED;
    }
}
