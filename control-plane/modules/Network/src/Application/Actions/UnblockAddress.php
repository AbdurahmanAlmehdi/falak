<?php

namespace Falak\Network\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Network\Application\ApplyProtection;
use Falak\Network\Application\ProtectionRateLimit;
use Falak\Network\Domain\Models\IpBlock;
use Falak\Network\Domain\Models\ProtectionState;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lifts a block. An auto-ban is also released on its server: the agent keeps its own bans and would add it back. A
 * server whose apply fails keeps being retried by the periodic converge until the agent confirms.
 */
final class UnblockAddress
{
    public function __construct(
        private readonly ApplyProtection $apply,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(IpBlock $block, ?string $actorId, bool $rateLimited = true): void
    {
        if ($rateLimited) {
            ProtectionRateLimit::hit($actorId, $block->organization_id, 'block');
        }

        DB::transaction(function () use ($block) {
            if ($block->source === IpBlock::AUTO && $block->server_id !== null) {
                $state = ProtectionState::query()->whereKey($block->server_id)->lockForUpdate()->first()
                    ?? new ProtectionState(['server_id' => $block->server_id, 'organization_id' => $block->organization_id]);
                $state->released = array_values(array_unique([...(array) $state->released, $block->cidr]));
                $state->save();
            }

            $block->delete();
        });

        $this->audit->record('network.ip_unblocked', 'ip_block', $block->id, ['cidr' => $block->cidr, 'server_id' => $block->server_id, 'source' => $block->source],
            $block->organization_id, $actorId);

        $this->apply->forBlock($block->organization_id, $block->server_id);
    }
}
