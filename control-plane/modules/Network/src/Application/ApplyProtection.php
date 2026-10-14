<?php

namespace Falak\Network\Application;

use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Network\Domain\Models\IpBlock;
use Falak\Network\Domain\Models\ProtectionState;
use Falak\Network\Infrastructure\CanonicalJson;
use Falak\Servers\Contracts\ServerDirectory;
use Illuminate\Support\Facades\DB;

/**
 * Converges a server's protection (docs/plans/network/protection.md): compiles its active blocks, the guard list and
 * its auto-ban settings into `net.protection.apply` and dispatches it when it differs from what is applied. Only
 * agents with `net.protection` get it; older ones are marked `unsupported` and never get block sets.
 */
final class ApplyProtection
{
    public const FEATURE = 'net.protection';

    public function __construct(
        private readonly AgentGateway $agents,
        private readonly AgentDirectory $directory,
        private readonly ServerDirectory $servers,
        private readonly ProtectionGuards $guards,
    ) {}

    public function supported(string $serverId): bool
    {
        return $this->directory->forServer($serverId)?->supports(self::FEATURE) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function compile(ProtectionState $state): array
    {
        $blocks = IpBlock::query()->active()->forServer($state->organization_id, $state->server_id)
            ->orderBy('cidr')->orderBy('id')->limit(10000)->get();
        $seen = [];
        $out = [];

        foreach ($blocks as $block) {
            // One element per prefix: the latest expiry (permanent wins).
            $expires = $block->expires_at?->utc()->toIso8601ZuluString();

            if (array_key_exists($block->cidr, $seen)) {
                $prev = $seen[$block->cidr];
                $expires = $prev === null || $expires === null ? null : max($prev, $expires);
            }

            $seen[$block->cidr] = $expires;
        }

        foreach ($seen as $cidr => $expires) {
            $out[] = ['cidr' => (string) $cidr, 'expires_at' => $expires];
        }

        return [
            'blocks' => $out,
            'release' => array_values(array_slice((array) $state->released, 0, 10000)),
            'never_block' => $this->guards->neverBlock($state->organization_id),
            'auto_ban' => $state->auto_ban
                ? ['enabled' => true, 'failures' => $state->failures, 'window_s' => $state->window_s, 'ban_s' => $state->ban_s]
                : ['enabled' => false],
        ];
    }

    public function __invoke(string $serverId, bool $force = false): ?ProtectionState
    {
        $server = $this->servers->find($serverId);

        if ($server === null) {
            return null;
        }

        $supported = $this->supported($server->id);

        return DB::transaction(function () use ($server, $supported, $force) {
            $state = ProtectionState::query()->whereKey($server->id)->lockForUpdate()->first()
                ?? new ProtectionState(['server_id' => $server->id, 'organization_id' => $server->organizationId]);

            if (! $supported) {
                $state->forceFill(['status' => 'unsupported', 'command_id' => null])->save();

                return $state;
            }

            // Nothing to protect and never applied: no table is created on the host.
            if ($state->applied_hash === null && ! $state->auto_ban && empty($state->released) && ! IpBlock::query()->active()->forServer($server->organizationId, $server->id)->exists()) {
                $state->forceFill(['status' => 'pending', 'error' => null])->save();

                return $state;
            }

            $payload = $this->compile($state);
            $hash = CanonicalJson::hash($payload);

            if (! $force && (($state->status === 'applied' && $state->applied_hash === $hash) || ($state->status === 'applying' && $state->desired_hash === $hash))) {
                return $state;
            }

            $state->desired_hash = $hash;

            if (! $server->isActive()) {
                $state->forceFill(['status' => 'pending', 'error' => null])->save();

                return $state;
            }

            $state->revision++;

            try {
                $handle = $this->agents->dispatch($server->id, 'net.protection.apply', $payload, (int) config('network.command_timeout', 120),
                    "net.protection:{$server->id}:{$state->revision}");
                $state->forceFill(['status' => 'applying', 'command_id' => $handle->id, 'error' => null]);
            } catch (AgentUnavailable) {
                $state->forceFill(['status' => 'failed', 'command_id' => null, 'error' => 'The server agent is not connected.']);
            }

            $state->save();

            return $state;
        });
    }

    /**
     * Converge every server a block applies to (all of the organization's for an organization-wide one).
     */
    public function forBlock(string $organizationId, ?string $serverId): void
    {
        $ids = $serverId !== null ? [$serverId] : array_map(fn ($s) => $s->id, $this->servers->forOrganization($organizationId));

        foreach ($ids as $id) {
            ($this)($id);
        }
    }
}
