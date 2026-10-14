<?php

namespace Falak\Network\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Network\Application\ApplyProtection;
use Falak\Network\Application\ProtectionGuards;
use Falak\Network\Domain\Models\IpBlock;
use Falak\Network\Domain\Support\Cidr;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Blocks an address or CIDR on some servers or on every server of the organization (docs/plans/network/protection.md),
 * after the guards: never the requester, the control plane, the organization's servers or networks, reserved ranges;
 * private ranges and broad prefixes only when confirmed. An exact duplicate updates the existing block; a prefix that
 * overlaps another active block in the same reach is refused (nftables interval sets cannot hold both).
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

        if (! $confirm && ($why = $this->guards->confirmation($cidr)) !== null) {
            throw ValidationException::withMessages(['confirm' => "{$why} Confirm to block it anyway."]);
        }

        $key = 'network.protect:'.($actorId ?? $organizationId);
        $perMinute = (int) config('network.protection.changes_per_minute', 30);

        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            throw ValidationException::withMessages(['address' => 'Too many block changes; try again in a minute.']);
        }

        $targets = $serverIds === null ? [null] : array_values(array_unique($serverIds));
        $max = (int) config('network.protection.max_blocks', 5000);

        if (IpBlock::query()->active()->where('organization_id', $organizationId)->count() + count($targets) > $max) {
            throw ValidationException::withMessages(['address' => "The organization has {$max} active blocks already."]);
        }

        $seconds = self::DURATIONS[$duration];
        $expires = $seconds === null ? null : now()->addSeconds($seconds);
        $reason = $reason !== null ? mb_substr(trim($reason), 0, 500) : null;
        $out = [];

        foreach ($targets as $serverId) {
            $this->refuseOverlaps($organizationId, $serverId, $cidr);
            $existing = IpBlock::query()->active()->where('organization_id', $organizationId)->where('cidr', (string) $cidr)
                ->where('server_id', $serverId)->first();

            $block = $existing ?? new IpBlock(['organization_id' => $organizationId, 'server_id' => $serverId, 'cidr' => (string) $cidr, 'family' => $cidr->family()]);
            $block->forceFill(['reason' => $reason ?: $block->reason, 'source' => IpBlock::MANUAL, 'expires_at' => $expires, 'created_by' => $actorId,
                'meta' => [...(array) $block->meta, ...$meta]])->save();
            RateLimiter::hit($key, 60);

            $this->audit->record('network.ip_blocked', 'ip_block', $block->id, [
                'cidr' => (string) $cidr, 'server_id' => $serverId, 'duration' => $duration, 'reason' => $reason, 'confirmed' => $confirm, 'updated' => $existing !== null,
            ], $organizationId, $actorId);

            $this->apply->forBlock($organizationId, $serverId);
            $out[] = $block;
        }

        return $out;
    }

    private function refuseOverlaps(string $organizationId, ?string $serverId, Cidr $cidr): void
    {
        $others = IpBlock::query()->active()->where('organization_id', $organizationId)
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
