<?php

namespace Falak\Config\Application\Actions;

use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Models\ConfigRun;
use Illuminate\Support\Facades\DB;

/**
 * Reject a run waiting for an approval: cancelled, nothing applied. False when it isn't waiting.
 */
final class RejectRun
{
    public function __invoke(ConfigRun $run, string $userId, ?string $comment = null): bool
    {
        return DB::transaction(function () use ($run, $userId, $comment) {
            $locked = ConfigRun::query()->lockForUpdate()->find($run->id);

            if ($locked === null || $locked->status !== RunStatus::AwaitingApproval) {
                return false;
            }

            $locked->forceFill([
                'status' => RunStatus::Cancelled, 'cancel_reason' => 'rejected', 'rejected_by' => $userId, 'rejected_at' => now(),
                'approval_comment' => $comment, 'error' => 'Rejected'.($comment ? ": {$comment}" : '.'), 'finished_at' => now(),
            ])->save();

            return true;
        });
    }
}
