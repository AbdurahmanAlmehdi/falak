<?php

namespace Falak\Config\Application\Actions;

use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Models\ConfigRun;
use Illuminate\Support\Facades\DB;

/**
 * Cancel a run that hasn't started applying (planning or awaiting approval). False when it can't be cancelled.
 */
final class CancelRun
{
    public function __invoke(ConfigRun $run): bool
    {
        return DB::transaction(function () use ($run) {
            $locked = ConfigRun::query()->lockForUpdate()->find($run->id);

            if ($locked === null || ! in_array($locked->status, [RunStatus::Planning, RunStatus::AwaitingApproval], true)) {
                return false;
            }

            $locked->forceFill(['status' => RunStatus::Cancelled, 'cancel_reason' => 'user', 'finished_at' => now()])->save();

            return true;
        });
    }
}
