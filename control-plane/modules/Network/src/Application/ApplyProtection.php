<?php

namespace Falak\Network\Application;

use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Identity\Contracts\AuditLog;
use Falak\Network\Domain\Models\IpBlock;
use Falak\Network\Domain\Models\ProtectionState;
use Falak\Network\Domain\Support\Cidr;
use Falak\Network\Events\IpBlockSuspended;
use Falak\Network\Infrastructure\CanonicalJson;
use Falak\Servers\Contracts\ServerDirectory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Converges a server's protection (docs/plans/network/protection.md): compiles its active blocks, the guard list and
 * its auto-ban settings into `net.protection.apply` and dispatches it when it differs from what is applied, when the
 * last apply failed, or when one has been in flight too long. Only agents with `net.protection` get it; older ones are
 * marked `unsupported` and never get block sets.
 *
 * Before compiling, blocks that now cover a protected address (a server, network or member address added after the
 * block was made) are suspended: no longer sent, marked on the row, audited and alerted.
 */
final class ApplyProtection
{
    public const FEATURE = 'net.protection';

    /** An apply still in flight after this long is sent again. */
    public const STALE_SECONDS = 600;

    /** @var array<string, true> organizations whose blocks were checked against the guards by this instance */
    private array $checked = [];

    public function __construct(
        private readonly AgentGateway $agents,
        private readonly AgentDirectory $directory,
        private readonly ServerDirectory $servers,
        private readonly ProtectionGuards $guards,
        private readonly AuditLog $audit,
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
        $this->suspendProtected($server->organizationId);

        return DB::transaction(function () use ($server, $supported, $force) {
            $state = ProtectionState::query()->whereKey($server->id)->lockForUpdate()->first()
                ?? new ProtectionState(['server_id' => $server->id, 'organization_id' => $server->organizationId]);

            if (! $supported) {
                $state->forceFill(['status' => 'unsupported', 'command_id' => null])->save();

                return $state;
            }

            // Nothing to protect and never sent: no table is created on the host.
            if ($state->revision === 0 && ! $state->auto_ban && empty($state->released)
                && ! IpBlock::query()->active()->forServer($server->organizationId, $server->id)->exists()) {
                $state->forceFill(['status' => 'pending', 'error' => null])->save();

                return $state;
            }

            $payload = $this->compile($state);
            $hash = CanonicalJson::hash($payload);
            $stale = $state->status === 'applying' && $state->updated_at !== null && $state->updated_at->lt(now()->subSeconds(self::STALE_SECONDS));

            if (! $force && (($state->status === 'applied' && $state->applied_hash === $hash) || ($state->status === 'applying' && $state->desired_hash === $hash && ! $stale))) {
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

            // Touched even when only the command changed: the staleness check reads it.
            $state->updated_at = now();
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

    /**
     * Converge every server of the organization when what it protects changed (a server's addresses, a private
     * network, a member's address): the guard list is compared with the last one seen, so frequent events (facts every
     * few minutes) cost a few queries and nothing else.
     */
    public function onGuardsChanged(string $organizationId): void
    {
        $hash = hash('sha256', implode(',', $this->guards->neverBlock($organizationId)));
        $key = "network.protection.guards:{$organizationId}";

        if (Cache::get($key) === $hash) {
            return;
        }

        $this->forBlock($organizationId, null);
        Cache::put($key, $hash, now()->addDay());
    }

    /**
     * Blocks that now cover a protected address are suspended (never sent again), audited and alerted. Auto-bans among
     * them are released on their server too.
     */
    public function suspendProtected(string $organizationId): void
    {
        // Once per organization for this instance (a request, a job): converging every server re-checks nothing.
        if (isset($this->checked[$organizationId])) {
            return;
        }

        $this->checked[$organizationId] = true;
        $blocks = IpBlock::query()->active()->where('organization_id', $organizationId)->get();

        foreach ($blocks as $block) {
            $cidr = Cidr::tryParse($block->cidr);
            $reason = $cidr !== null ? $this->guards->protectedBy($cidr, $organizationId) : 'Not a valid prefix.';

            if ($reason === null) {
                continue;
            }

            DB::transaction(function () use ($block, $reason) {
                $block->forceFill(['suspended_reason' => mb_substr($reason, 0, 500), 'suspended_at' => now()])->save();

                if ($block->source === IpBlock::AUTO && $block->server_id !== null) {
                    $state = ProtectionState::query()->whereKey($block->server_id)->lockForUpdate()->first();
                    $state?->forceFill(['released' => array_values(array_unique([...(array) $state->released, $block->cidr]))])->save();
                }
            });

            $this->audit->record('network.ip_block_suspended', 'ip_block', $block->id, ['cidr' => $block->cidr, 'server_id' => $block->server_id, 'reason' => $reason],
                $organizationId);
            IpBlockSuspended::dispatch($organizationId, $block->id, $block->cidr, $reason);
        }
    }
}
