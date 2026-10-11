<?php

namespace Falak\Config\Application\Jobs;

use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Models\ConfigRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs that waited longer than `config_as_code.approval_days` for an approval are cancelled (`expired`).
 */
final class ExpireApprovals implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        ConfigRun::query()->where('status', RunStatus::AwaitingApproval)->where('approval_expires_at', '<', now())
            ->update(['status' => RunStatus::Cancelled->value, 'cancel_reason' => 'expired', 'error' => 'The approval expired.', 'finished_at' => now(), 'updated_at' => now()]);
    }
}
