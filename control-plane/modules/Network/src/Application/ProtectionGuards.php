<?php

namespace Falak\Network\Application;

use Falak\Network\Domain\Models\PrivateNetwork;
use Falak\Network\Domain\Models\PrivateNetworkMember;
use Falak\Network\Domain\Support\Cidr;
use Falak\Servers\Contracts\Data\ServerData;
use Falak\Servers\Contracts\ServerDirectory;
use Illuminate\Support\Facades\Cache;

/**
 * What a block may never cover (docs/plans/network/protection.md): reserved ranges, the requester's own address, the
 * control plane, the organization's servers and its private networks. Private ranges and broad prefixes need an
 * explicit confirmation.
 */
final class ProtectionGuards
{
    /** Never blocked: loopback, "this network", link-local, multicast, broadcast. */
    private const RESERVED = ['0.0.0.0/8', '127.0.0.0/8', '169.254.0.0/16', '224.0.0.0/4', '255.255.255.255', '::', '::1', 'fe80::/10', 'ff00::/8'];

    /** Blocked only when confirmed: Docker networks, load balancers and peers live there. */
    private const PRIVATE = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10', 'fc00::/7'];

    public function __construct(private readonly ServerDirectory $servers) {}

    /**
     * Why the block is refused outright, or null.
     */
    public function refusal(Cidr $cidr, string $organizationId, ?string $requesterIp): ?string
    {
        $min = $cidr->isV4() ? 8 : 16;

        if ($cidr->bits < $min) {
            return "{$cidr} is too broad to block (at least /{$min}).";
        }

        foreach (self::RESERVED as $reserved) {
            if ($cidr->overlaps(Cidr::parse($reserved))) {
                return "{$cidr} overlaps the reserved range {$reserved}.";
            }
        }

        if (($own = Cidr::tryParse($requesterIp)) !== null && $cidr->contains($own)) {
            return "{$cidr} contains your own address ({$own}): you would lock yourself out.";
        }

        foreach ($this->controlPlane() as $address) {
            if ($cidr->overlaps($address)) {
                return "{$cidr} contains the control plane ({$address}).";
            }
        }

        foreach ($this->organization($organizationId) as [$protected, $what]) {
            if ($cidr->overlaps($protected)) {
                return "{$cidr} overlaps {$what} ({$protected}).";
            }
        }

        return null;
    }

    /**
     * Why the block needs confirming (private range, broad prefix), or null.
     */
    public function confirmation(Cidr $cidr): ?string
    {
        foreach (self::PRIVATE as $private) {
            if ($cidr->overlaps(Cidr::parse($private))) {
                return "{$cidr} is in the private range {$private} (Docker networks, load balancers and private peers use it).";
            }
        }

        if ($cidr->bits < ($cidr->isV4() ? 16 : 48)) {
            return "{$cidr} covers {$this->size($cidr)} addresses.";
        }

        return null;
    }

    /**
     * The organization's protected addresses as sent to the agent (`never_block`): its servers, private networks and
     * the control plane.
     *
     * @return list<string>
     */
    public function neverBlock(string $organizationId): array
    {
        $out = array_map(fn (Cidr $cidr) => (string) $cidr, $this->controlPlane());

        foreach ($this->organization($organizationId) as [$cidr]) {
            $out[] = (string) $cidr;
        }

        $out = array_values(array_unique($out));
        sort($out);

        return array_slice($out, 0, 1000);
    }

    /**
     * @return list<array{0: Cidr, 1: string}>
     */
    private function organization(string $organizationId): array
    {
        $out = [];

        foreach ($this->servers->forOrganization($organizationId) as $server) {
            /** @var ServerData $server */
            foreach ([$server->ipv4, $server->ipv6, $server->privateIpv4] as $address) {
                if (($cidr = Cidr::tryParse($address)) !== null) {
                    $out[] = [$cidr, "the server {$server->name}"];
                }
            }
        }

        foreach (PrivateNetwork::query()->where('organization_id', $organizationId)->get() as $network) {
            if (($cidr = Cidr::tryParse($network->cidr)) !== null) {
                $out[] = [$cidr, "the private network {$network->name}"];
            }
        }

        foreach (PrivateNetworkMember::query()->where('organization_id', $organizationId)->pluck('address') as $address) {
            if (($cidr = Cidr::tryParse((string) $address)) !== null) {
                $out[] = [$cidr, 'a WireGuard peer'];
            }
        }

        return $out;
    }

    /**
     * The control plane's addresses: configured (FALAK_CONTROL_PLANE_IPS) plus the resolved panel and agent API hosts
     * (cached for 10 minutes).
     *
     * @return list<Cidr>
     */
    public function controlPlane(): array
    {
        $addresses = (array) config('network.protection.control_plane_addresses', []);
        $hosts = array_filter(array_map(fn ($url) => is_string($url) ? parse_url($url, PHP_URL_HOST) : null, [config('app.url'), config('fleet.panel_url'), config('fleet.api_url')]));

        foreach (array_unique($hosts) as $host) {
            $host = trim((string) $host, '[]');
            $addresses = [...$addresses, ...(filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : Cache::remember("network.protection.resolve:{$host}", 600, fn () => self::resolve($host)))];
        }

        $out = [];

        foreach ($addresses as $address) {
            if (($cidr = Cidr::tryParse(trim((string) $address))) !== null) {
                $out[(string) $cidr] = $cidr;
            }
        }

        return array_values($out);
    }

    /**
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        if ($host === '' || $host === 'localhost' || ! str_contains($host, '.')) {
            return [];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(fn (array $r) => $r['ip'] ?? $r['ipv6'] ?? null, $records)));
    }

    private function size(Cidr $cidr): string
    {
        $bits = $cidr->maxBits() - $cidr->bits;

        return $bits > 40 ? "2^{$bits}" : number_format(2 ** $bits);
    }
}
