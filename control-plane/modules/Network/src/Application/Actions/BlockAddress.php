<?php

namespace Falak\Network\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Network\Application\ApplyProtection;
use Falak\Network\Application\ProtectionGuards;
use Falak\Network\Application\ProtectionRateLimit;
use Falak\Network\Domain\Models\IpBlock;
use Falak\Network\Domain\Models\ProtectionState;
use Falak\Network\Domain\Support\Cidr;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Blocks an address or CIDR on some servers or on every server of the organization (docs/plans/network/protection.md),
 * after the guards: never the requester, the control plane, the organization's servers, networks or members' recent
 * addresses, reserved ranges; private ranges, broad prefixes and peers the server connects to (outbound) only when
 * confirmed. An exact duplicate updates the existing block; a prefix that overlaps another manual block in the same
 * reach is refused (nftables interval sets cannot hold both); auto-bans it covers are lifted in its favour.
 */
final class BlockAddress
{
    public const DURATIONS = ['1h' => 3600, '24h' => 86400, '7d' => 604800, 'permanent' => null];

    public function __construct(
        private readonly ProtectionGuards $guards,
        private readonly ApplyProtection $apply,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  ?list<string>  $serverIds  null = every server of the organization
     * @param  array<string, mixed>  $meta
     * @return list<IpBlock>
     *
     * @throws ValidationException
     */
    public function __invoke(
        string $organizationId,
        ?array $serverIds,
        string $address,
        string $duration,
        ?string $reason,
        bool $confirm,
        ?string $requesterIp,
        ?string $actorId,
        array $meta = [],
        bool $outbound = false,
    ): array {
        try {
            $cidr = Cidr::parse($address);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['address' => $e->getMessage()]);
        }

        if (! array_key_exists($duration, self::DURATIONS)) {
            throw ValidationException::withMessages(['duration' => 'Pick 1h, 24h, 7d or permanent.']);
        }

        if (($refusal = $this->guards->refusal($cidr, $organizationId, $requesterIp)) !== null) {
            throw ValidationException::withMessages(['address' => $refusal]);
        }

        if (! $confirm && ($why = $this->guards->confirmation($cidr, $outbound)) !== null) {
            throw ValidationException::withMessages(['confirm' => "{$why} Confirm to block it anyway."]);
        }

        $targets = $serverIds === null ? [null] : array_values(array_unique($serverIds));
        $max = (int) config('network.protection.max_blocks', 5000);

        if (IpBlock::query()->active()->where('organization_id', $organizationId)->count() + count($targets) > $max) {
            throw ValidationException::withMessages(['address' => "The organization has {$max} active blocks already."]);
        }

        foreach ($targets as $serverId) {
            $this->refuseOverlaps($organizationId, $serverId, $cidr);
        }

        ProtectionRateLimit::hit($actorId, $organizationId);

        $seconds = self::DURATIONS[$duration];
        $expires = $seconds === null ? null : now()->addSeconds($seconds);
        $reason = $reason !== null ? mb_substr(trim($reason), 0, 500) : null;
        $out = [];

        foreach ($targets as $serverId) {
            $lifted = $this->liftAutoBans($organizationId, $serverId, $cidr);
            $existing = IpBlock::query()->active()->where('organization_id', $organizationId)->where('cidr', (string) $cidr)
                ->where('server_id', $serverId)->where('source', IpBlock::MANUAL)->first();

            $block = $existing ?? new IpBlock(['organization_id' => $organizationId, 'server_id' => $serverId, 'cidr' => (string) $cidr, 'family' => $cidr->family()]);
            $block->forceFill(['reason' => $reason ?: $block->reason, 'source' => IpBlock::MANUAL, 'expires_at' => $expires, 'created_by' => $actorId,
                'meta' => [...(array) $block->meta, ...$meta]])->save();

            $this->audit->record('network.ip_blocked', 'ip_block', $block->id, [
                'cidr' => (string) $cidr, 'server_id' => $serverId, 'duration' => $duration, 'reason' => $reason, 'confirmed' => $confirm, 'outbound' => $outbound,
                'updated' => $existing !== null, 'auto_bans_lifted' => $lifted,
            ], $organizationId, $actorId);

            $this->apply->forBlock($organizationId, $serverId);
            $out[] = $block;
        }

        return $out;
    }

    /**
     * A manual block wins over the agent's auto-bans it overlaps in its reach: they are deleted and released on their
     * server (the agent would keep them, and the interval set cannot hold both).
     *
     * @return list<string> the lifted auto-bans' prefixes
     */
    private function liftAutoBans(string $organizationId, ?string $serverId, Cidr $cidr): array
    {
        $bans = IpBlock::query()->current()->where('organization_id', $organizationId)->where('source', IpBlock::AUTO)->where('family', $cidr->family())
            ->when($serverId !== null, fn (Builder $q) => $q->where('server_id', $serverId))->get()
            ->filter(fn (IpBlock $ban) => ($b = Cidr::tryParse($ban->cidr)) !== null && $b->overlaps($cidr));

        foreach ($bans as $ban) {
            DB::transaction(function () use ($ban) {
                if ($ban->server_id !== null) {
                    $state = ProtectionState::query()->whereKey($ban->server_id)->lockForUpdate()->first()
                        ?? new ProtectionState(['server_id' => $ban->server_id, 'organization_id' => $ban->organization_id]);
                    $state->released = array_values(array_unique([...(array) $state->released, $ban->cidr]));
                    $state->save();
                }

                $ban->delete();
            });

            if ($serverId === null && $ban->server_id !== null) {
                ($this->apply)($ban->server_id);
            }
        }

        return $bans->pluck('cidr')->values()->all();
    }

    private function refuseOverlaps(string $organizationId, ?string $serverId, Cidr $cidr): void
    {
        $others = IpBlock::query()->active()->where('organization_id', $organizationId)->where('source', IpBlock::MANUAL)
            ->when($serverId !== null, fn (Builder $q) => $q->where(fn (Builder $q) => $q->whereNull('server_id')->orWhere('server_id', $serverId)))
            ->where('family', $cidr->family())
            ->get();

        foreach ($others as $other) {
            if ($other->cidr === (string) $cidr && $other->server_id === $serverId) {
                continue;
            }

            if (($o = Cidr::tryParse($other->cidr)) !== null && $o->overlaps($cidr)) {
                throw ValidationException::withMessages(['address' => $o->contains($cidr)
                    ? "{$cidr} is already covered by the block {$o}."
                    : "{$cidr} overlaps the block {$o}; unblock it first."]);
            }
        }
    }
}
