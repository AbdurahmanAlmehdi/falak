<?php

namespace Falak\Config\Application\Actions;

use Falak\Config\Application\Runs\RunEngine;
use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Models\ConfigRun;
use Illuminate\Support\Facades\DB;

/**
 * Approve a run waiting for an approval: it applies. (Until phase 4 sources are experimental and risky runs are
 * refused rather than waiting, so this answers false for every run of an experimental source.)
 */
final class ApproveRun
{
    public function __construct(private readonly RunEngine $engine) {}

    public function __invoke(ConfigRun $run, string $userId): bool
    {
        $claimed = DB::transaction(function () use ($run, $userId) {
            $locked = ConfigRun::query()->lockForUpdate()->find($run->id);

            if ($locked === null || $locked->status !== RunStatus::AwaitingApproval) {
                return false;
            }

            $locked->forceFill(['approved_by' => $userId, 'approved_at' => now()])->save();

            return true;
        });

        if ($claimed) {
            $this->engine->apply($run->refresh());
        }

        return $claimed;
    }
}
