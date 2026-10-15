<?php

namespace Falak\Network\Infrastructure\AgentRequests;

use Carbon\CarbonImmutable;
use Falak\Fleet\Contracts\AgentRequestHandler;
use Falak\Fleet\Contracts\Data\AgentCaller;
use Falak\Identity\Contracts\AuditLog;
use Falak\Insights\Contracts\SecurityIssues;
use Falak\Network\Application\ApplyProtection;
use Falak\Network\Application\ProtectionGuards;
use Falak\Network\Domain\Models\FirewallState;
use Falak\Network\Domain\Models\IpBlock;
use Falak\Network\Domain\Models\ProtectionState;
use Falak\Network\Domain\Support\Cidr;
use Falak\Servers\Contracts\ServerDirectory;
use Illuminate\Support\Facades\DB;

/**
 * net.protection.observed (docs/plans/network/protection.md): the server's sshd failures (→ a `security` Issue), the
 * agent's auto-bans (→ blocks, audited as the system), the firewall rule counters and the hits per block. Only the
 * caller's own server is touched.
 */
final class ProtectionObserved implements AgentRequestHandler
{
    /** SecurityIssues source of SSH brute force (key: "sshd"). */
    public const ISSUE_SOURCE = 'protection.ssh_bruteforce';

    public function __construct(
        private readonly SecurityIssues $issues,
        private readonly ServerDirectory $servers,
        private readonly AuditLog $audit,
        private readonly ProtectionGuards $guards,
        private readonly ApplyProtection $apply,
    ) {}

    public function handle(AgentCaller $caller, array $body): array
    {
        $server = $this->servers->find($caller->serverId);

        if ($server === null || $server->organizationId !== $caller->organizationId) {
            return ['accepted' => false];
        }

        $at = CarbonImmutable::parse((string) $body['at'])->min(now());

        if (isset($body['rules'])) {
            $counters = [];

            foreach ((array) $body['rules'] as $rule) {
                $counters[(string) $rule['id']] = ['packets' => (int) $rule['packets'], 'bytes' => (int) $rule['bytes']];
            }

            FirewallState::query()->whereKey($server->id)->where('organization_id', $server->organizationId)
                ->update(['counters' => json_encode($counters), 'counters_at' => $at]);
        }

        if (isset($body['blocked'])) {
            ProtectionState::query()->whereKey($server->id)->update(['blocked_packets' => (int) $body['blocked']['packets'], 'observed_at' => $at]);
        }

        $this->bans($server->id, $server->organizationId, (array) ($body['bans'] ?? []));
        $this->hits($server->id, $server->organizationId, (array) ($body['blocks'] ?? []), $at);
        $this->bruteForce($server->id, $server->name, $server->organizationId, $body['auth'] ?? null, $at);

        return ['accepted' => true];
    }

    /**
     * Every report carries all of the agent's active auto-bans: they are upserted (audited when new), so a report that
     * never arrived loses nothing. Bans a user lifted (released, waiting for the agent) are not recreated.
     *
     * @param  array<int, mixed>  $bans
     */
    private function bans(string $serverId, string $organizationId, array $bans): void
    {
        $release = false;
        $state = ProtectionState::query()->find($serverId);
        $released = array_flip((array) $state?->released);
        $existing = IpBlock::query()->current()->where('organization_id', $organizationId)->where('server_id', $serverId)->where('source', IpBlock::AUTO)
            ->get()->keyBy('cidr');

        foreach (array_slice($bans, 0, 100) as $ban) {
            $cidr = Cidr::tryParse((string) ($ban['cidr'] ?? ''));
            $until = CarbonImmutable::parse((string) $ban['until']);

            if ($cidr === null || $until->isPast() || $until->gt(now()->addDays(31)) || isset($released[(string) $cidr])) {
                continue;
            }

            // The agent guards its bans too; one the control plane would refuse (a server added since) is released.
            if ($this->guards->refusal($cidr, $organizationId, null) !== null || $this->guards->confirmation($cidr) !== null) {
                $state ??= new ProtectionState(['server_id' => $serverId, 'organization_id' => $organizationId]);
                $state->forceFill(['released' => array_values(array_unique([...(array) $state->released, (string) $cidr]))])->save();
                $released[(string) $cidr] = true;
                $release = true;

                continue;
            }

            $block = $existing[(string) $cidr] ?? null;
            $failures = (int) ($ban['failures'] ?? 0);

            if ($block !== null && $block->suspended_at === null && $block->expires_at !== null && abs($block->expires_at->diffInSeconds($until)) < 60) {
                continue;
            }

            $new = $block === null;
            $block ??= new IpBlock(['organization_id' => $organizationId, 'server_id' => $serverId, 'cidr' => (string) $cidr, 'family' => $cidr->family(), 'source' => IpBlock::AUTO]);
            $block->forceFill(['expires_at' => $until, 'reason' => "SSH brute force: {$failures} failed logins", 'meta' => ['failures' => $failures]])->save();

            if ($new) {
                $this->audit->record('network.ip_auto_banned', 'ip_block', $block->id, ['cidr' => (string) $cidr, 'server_id' => $serverId, 'until' => $until->toIso8601String()],
                    $organizationId);
            }
        }

        if ($release) {
            ($this->apply)($serverId);
        }
    }

    /**
     * Hits per block, in one read and one upsert.
     *
     * @param  array<int, mixed>  $blocks
     */
    private function hits(string $serverId, string $organizationId, array $blocks, CarbonImmutable $at): void
    {
        $counters = [];

        foreach ($blocks as $item) {
            if (($cidr = Cidr::tryParse((string) ($item['cidr'] ?? ''))) !== null) {
                $counters[(string) $cidr] = [(int) $item['packets'], (int) $item['bytes']];
            }
        }

        if ($counters === []) {
            return;
        }

        $rows = IpBlock::query()->active()->forServer($organizationId, $serverId)->whereIn('cidr', array_keys($counters))->get(['id', 'cidr']);
        $current = DB::table('network_ip_block_hits')->where('server_id', $serverId)->whereIn('block_id', $rows->pluck('id'))->get()->keyBy('block_id');
        $upserts = [];

        foreach ($rows as $block) {
            [$packets, $bytes] = $counters[$block->cidr];
            $prev = $current[$block->id] ?? null;
            $changed = $prev === null || (int) $prev->packets !== $packets;
            $upserts[] = ['block_id' => $block->id, 'server_id' => $serverId, 'packets' => $packets, 'bytes' => $bytes,
                'last_hit_at' => $changed ? $at : $prev->last_hit_at];
        }

        if ($upserts !== []) {
            DB::table('network_ip_block_hits')->upsert($upserts, ['block_id', 'server_id'], ['packets', 'bytes', 'last_hit_at']);
        }
    }

    private function bruteForce(string $serverId, string $serverName, string $organizationId, mixed $auth, CarbonImmutable $at): void
    {
        if (! is_array($auth)) {
            return;
        }

        $sources = array_values(array_filter((array) ($auth['sources'] ?? []), 'is_array'));
        $failures = array_sum(array_map(fn (array $s) => (int) $s['failures'], $sources));

        if ($failures < (int) config('network.protection.ssh_issue_min_failures', 20)) {
            return;
        }

        // One Issue per server through the shared security contract (kind security, docs/plans/network/connections.md):
        // source protection.ssh_bruteforce, key the service.
        $this->issues->record($organizationId, $serverId, self::ISSUE_SOURCE, 'sshd', "SSH brute force on {$serverName}", 'sshd', $at, $failures,
            ['failures' => $failures, 'log' => (string) $auth['source'], 'sources' => array_slice(array_map(fn (array $s) => [
                'ip' => (string) $s['ip'], 'failures' => (int) $s['failures'], 'users' => array_slice((array) ($s['users'] ?? []), 0, 5),
            ], $sources), 0, 10)]);
    }
}
