<?php

namespace Falak\Network\Application\Jobs;

use Falak\Network\Application\ApplyProtection;
use Falak\Network\Domain\Models\IpBlock;
use Falak\Network\Domain\Models\ProtectionState;
use Falak\Servers\Contracts\ServerDirectory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Every ten minutes (docs/plans/network/protection.md): converges the protection of every server that has some (a
 * state row, or a block that reaches it). What is converged is re-sent; what failed (an unblock the agent never
 * confirmed, an agent that was offline) is retried until it is applied; suspended blocks are found.
 */
final class ConvergeProtection implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(ApplyProtection $apply, ServerDirectory $servers): void
    {
        $ids = ProtectionState::query()->where('status', '!=', 'unsupported')->pluck('server_id')->all();
        $organizations = IpBlock::query()->active()->distinct()->pluck('organization_id')->all();

        foreach ($organizations as $organizationId) {
            $apply->suspendProtected($organizationId);

            foreach ($servers->forOrganization($organizationId) as $server) {
                $ids[] = $server->id;
            }
        }

        foreach (array_unique($ids) as $serverId) {
            $apply($serverId);
        }
    }
}
