<?php

namespace Falak\Network\Infrastructure\AgentRequests;

use Carbon\CarbonImmutable;
use Falak\Fleet\Contracts\AgentRequestHandler;
use Falak\Fleet\Contracts\Data\AgentCaller;
use Falak\Identity\Contracts\AuditLog;
use Falak\Insights\Contracts\HostSecurityIssues;
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
    public function __construct(
        private readonly HostSecurityIssues $issues,
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
     * @param  array<int, mixed>  $bans
     */
    private function bans(string $serverId, string $organizationId, array $bans): void
    {
        $release = false;

        foreach (array_slice($bans, 0, 100) as $ban) {
            $cidr = Cidr::tryParse((string) ($ban['cidr'] ?? ''));
            $until = CarbonImmutable::parse((string) $ban['until']);

            if ($cidr === null || $until->isPast() || $until->gt(now()->addDays(31))) {
                continue;
            }

            // The agent guards its bans too; one the control plane would refuse (a server added since) is released.
            if ($this->guards->refusal($cidr, $organizationId, null) !== null || $this->guards->confirmation($cidr) !== null) {
                $state = ProtectionState::query()->find($serverId);
                $state?->forceFill(['released' => array_values(array_unique([...(array) $state->released, (string) $cidr]))])->save();
                $release = true;

                continue;
            }

            $block = IpBlock::query()->where('organization_id', $organizationId)->where('server_id', $serverId)->where('cidr', (string) $cidr)
                ->where('source', IpBlock::AUTO)->first() ?? new IpBlock([
                    'organization_id' => $organizationId, 'server_id' => $serverId, 'cidr' => (string) $cidr, 'family' => $cidr->family(), 'source' => IpBlock::AUTO,
                ]);
            $block->forceFill(['expires_at' => $until, 'reason' => 'SSH brute force: '.(int) ($ban['failures'] ?? 0).' failed logins', 'meta' => ['failures' => (int) ($ban['failures'] ?? 0)]])->save();

            $this->audit->record('network.ip_auto_banned', 'ip_block', $block->id, ['cidr' => (string) $cidr, 'server_id' => $serverId, 'until' => $until->toIso8601String()],
                $organizationId);
        }

        if ($release) {
            ($this->apply)($serverId);
        }
    }

    /**
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

        $rows = IpBlock::query()->active()->forServer($organizationId, $serverId)->whereIn('cidr', array_keys($counters))->get();

        foreach ($rows as $block) {
            [$packets, $bytes] = $counters[$block->cidr];
            $current = DB::table('network_ip_block_hits')->where('block_id', $block->id)->where('server_id', $serverId)->first();
            $changed = $current === null || (int) $current->packets !== $packets;

            DB::table('network_ip_block_hits')->updateOrInsert(['block_id' => $block->id, 'server_id' => $serverId], [
                'packets' => $packets, 'bytes' => $bytes, 'last_hit_at' => $changed ? $at : $current->last_hit_at,
            ]);
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

        $this->issues->record($organizationId, $serverId, 'ssh.bruteforce', "SSH brute force on {$serverName}", $failures, $at,
            ['failures' => $failures, 'log' => (string) $auth['source'], 'sources' => array_slice(array_map(fn (array $s) => [
                'ip' => (string) $s['ip'], 'failures' => (int) $s['failures'], 'users' => array_slice((array) ($s['users'] ?? []), 0, 5),
            ], $sources), 0, 10)]);
    }
}
