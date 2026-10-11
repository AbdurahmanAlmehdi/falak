<?php

namespace Falak\Config\Application\Actions;

use Falak\Config\Application\Runs\RunEngine;
use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Config\Domain\Models\ConfigSource;
use Illuminate\Support\Facades\DB;

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
     * @return self::APPROVED|self::NOT_WAITING|self::BLOCKED|self::PLAN_CHANGED
     */
    public function __invoke(ConfigRun $run, string $userId, ?string $comment = null): string
    {
        if (self::blockedReason($run, $userId) !== null) {
            return self::BLOCKED;
        }

        $claimed = DB::transaction(function () use ($run, $userId, $comment) {
            $locked = ConfigRun::query()->lockForUpdate()->find($run->id);

            if ($locked === null || $locked->status !== RunStatus::AwaitingApproval) {
                return false;
            }

            if ($locked->approval_expires_at !== null && $locked->approval_expires_at->isPast()) {
                $locked->forceFill(['status' => RunStatus::Cancelled, 'cancel_reason' => 'expired', 'error' => 'The approval expired.', 'finished_at' => now()])->save();

                return false;
            }

            $risky = array_values(array_map(fn (array $c) => ['path' => (string) $c['path'], 'rule' => $c['rule'] ?? null],
                array_filter((array) ($locked->plan['changes'] ?? []), fn (array $c) => (bool) ($c['risky'] ?? false))));
            $locked->forceFill(['approved_by' => $userId, 'approved_at' => now(), 'approved_risky' => $risky, 'approval_comment' => $comment])->save();

            return true;
        });

        if (! $claimed) {
            return self::NOT_WAITING;
        }

        $this->engine->apply($run->refresh());

        return $run->refresh()->status === RunStatus::AwaitingApproval ? self::PLAN_CHANGED : self::APPROVED;
    }
}
