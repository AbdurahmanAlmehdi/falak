<?php

namespace Falak\Network\Application;

use Falak\Edge\Contracts\CloudflareRanges;
use Falak\Identity\Contracts\OrganizationDirectory;
use Falak\Network\Contracts\BlockableAddresses;
use Falak\Network\Domain\Models\PrivateNetwork;
use Falak\Network\Domain\Models\PrivateNetworkMember;
use Falak\Network\Domain\Support\Cidr;
use Falak\Servers\Contracts\Data\ServerData;
use Falak\Servers\Contracts\ServerDirectory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * What a block may never cover (docs/plans/network/protection.md): reserved ranges, the requester's own address, the
 * control plane, the organization's servers, its private networks and the addresses its members used the panel from
 * in the last 30 days. Private ranges, broad prefixes and peers the server connects to need an explicit confirmation.
 */
final class ProtectionGuards implements BlockableAddresses
{
    /** Never blocked: loopback, "this network", link-local, multicast, broadcast. */
    private const RESERVED = ['0.0.0.0/8', '127.0.0.0/8', '169.254.0.0/16', '224.0.0.0/4', '255.255.255.255', '::', '::1', 'fe80::/10', 'ff00::/8'];

    /** Blocked only when confirmed: Docker networks, load balancers and peers live there. */
    private const PRIVATE = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10', 'fc00::/7'];

    /** never_block's size (the agent and the schema accept 5,000). */
    public const NEVER_BLOCK_MAX = 5000;

    private const MEMBER = 'an address a member used the panel from';

    /** @var array<string, list<array{0: Cidr, 1: string}>> per organization, for one request / job */
    private array $memo = [];

    /** @var array<string, list<Cidr>> the constant ranges above (and Cloudflare's), parsed once per process */
    private static array $parsed = [];

    public function __construct(
        private readonly ServerDirectory $servers,
        private readonly OrganizationDirectory $organizations,
    ) {}

    /**
     * Why the block is refused outright, or null.
     */
    public function refusal(Cidr $cidr, string $organizationId, ?string $requesterIp): ?string
    {
        $min = $cidr->isV4() ? 8 : 16;

        if ($cidr->bits < $min) {
            return "{$cidr} is too broad to block (at least /{$min}).";
        }

        foreach (self::parsed('reserved', self::RESERVED) as $reserved) {
            if ($cidr->overlaps($reserved)) {
                return "{$cidr} overlaps the reserved range {$reserved}.";
            }
        }

        if (($own = Cidr::fromAddress($requesterIp)) !== null && $cidr->contains($own)) {
            return "{$cidr} contains your own address ({$own}): you would lock yourself out.";
        }

        if (($reason = $this->protectedBy($cidr, $organizationId)) !== null) {
            return $reason;
        }

        // New blocks only: an existing block over Cloudflare is not suspended (protectedBy() decides that).
        foreach (self::parsed('cloudflare', CloudflareRanges::RANGES) as $range) {
            if ($cidr->overlaps($range)) {
                return "{$cidr} overlaps Cloudflare's proxy range {$range}: every site proxied through Cloudflare would stop loading.";
            }
        }

        return null;
    }

    /**
     * Why a prefix overlaps something that must stay reachable (control plane, servers, networks, members), or null.
     * Also decides whether an existing block must be suspended.
     */
    public function protectedBy(Cidr $cidr, string $organizationId): ?string
    {
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

    public function unblockable(array $addresses, string $organizationId, ?string $requesterIp = null, bool $namesMembers = false): array
    {
        if ($addresses === []) {
            return [];
        }

        $out = [];
        $own = Cidr::fromAddress($requesterIp);
        $controlPlane = $this->controlPlane();
        $organization = $this->organization($organizationId);

        foreach (array_unique($addresses) as $address) {
            if (($cidr = Cidr::fromAddress($address)) !== null && ($label = $this->label($cidr, $organization, $own, $controlPlane, $namesMembers)) !== null) {
                $out[$address] = $label;
            }
        }

        return $out;
    }

    /**
     * The same checks as refusal() and confirmation(), as a row label.
     *
     * @param  list<array{0: Cidr, 1: string}>  $organization
     * @param  list<Cidr>  $controlPlane
     */
    private function label(Cidr $address, array $organization, ?Cidr $own, array $controlPlane, bool $namesMembers): ?string
    {
        $in = fn (array $ranges) => array_any($ranges, fn (Cidr $range) => $address->overlaps($range));

        return match (true) {
            $in(self::parsed('reserved', self::RESERVED)) => 'Reserved',
            $own !== null && $address->overlaps($own) => 'Your address',
            $in($controlPlane) => 'Control plane',
            default => $this->organizationLabel($address, $organization, $namesMembers)
                ?? ($in(self::parsed('cloudflare', CloudflareRanges::RANGES)) ? 'Cloudflare' : ($in(self::parsed('private', self::PRIVATE)) ? 'Private range' : null)),
        };
    }

    /**
     * @param  list<array{0: Cidr, 1: string}>  $organization
     */
    private function organizationLabel(Cidr $address, array $organization, bool $namesMembers): ?string
    {
        foreach ($organization as [$protected, $what]) {
            if ($address->overlaps($protected)) {
                return match (true) {
                    str_starts_with($what, 'the server ') => 'Your server',
                    // Who used the panel from where is for network.manage only.
                    $what === self::MEMBER => $namesMembers ? 'Team member' : 'Protected',
                    default => 'Private network',
                };
            }
        }

        return null;
    }

    /**
     * Why the block needs confirming (private range, broad prefix, a peer the server connects to), or null.
     */
    public function confirmation(Cidr $cidr, bool $outbound = false): ?string
    {
        if ($outbound) {
            return "{$cidr} is a peer your server connects to (an API, a database, a mirror): blocking it also cuts those connections.";
        }

        foreach (self::parsed('private', self::PRIVATE) as $private) {
            if ($cidr->overlaps($private)) {
                return "{$cidr} is in the private range {$private} (Docker networks, load balancers and private peers use it).";
            }
        }

        if ($cidr->bits < ($cidr->isV4() ? 16 : 48)) {
            return "{$cidr} covers {$this->size($cidr)} addresses.";
        }

        return null;
    }

    /**
     * The organization's protected addresses as sent to the agent (`never_block`): its servers, private networks,
     * members' recent addresses and the control plane.
     *
     * @return list<string>
     */
    public function neverBlock(string $organizationId): array
    {
        [$core, $members] = $this->protectedLists($organizationId);
        $max = self::max();

        // The control plane, the servers and the private networks first (an organization with more than the limit is
        // refused before anything is sent: overflow()); members' recent addresses fill the rest, most recent first.
        return [...array_slice($core, 0, $max), ...array_slice($members, 0, max(0, $max - count($core)))];
    }

    /**
     * Why the organization's protected addresses (control plane, servers, private networks) do not fit in what the agent
     * accepts, or null. Protection is not sent while they do not: a cut list would leave a server blockable.
     */
    public function overflow(string $organizationId): ?string
    {
        [$core] = $this->protectedLists($organizationId);
        $max = self::max();

        return count($core) > $max
            ? 'The organization has '.count($core)." protected addresses (control plane, servers, private networks); the agent accepts {$max}. Protection is not applied."
            : null;
    }

    /**
     * @param  list<string>  $ranges
     * @return list<Cidr>
     */
    private static function parsed(string $key, array $ranges): array
    {
        return self::$parsed[$key] ??= array_map(fn (string $range) => Cidr::parse($range), $ranges);
    }

    public static function max(): int
    {
        return min(self::NEVER_BLOCK_MAX, max(1, (int) config('network.protection.never_block_max', self::NEVER_BLOCK_MAX)));
    }

    /**
     * @return array{0: list<string>, 1: list<string>} the core list (sorted) and members' addresses
     */
    private function protectedLists(string $organizationId): array
    {
        $core = array_map(fn (Cidr $cidr) => (string) $cidr, $this->controlPlane());
        $members = [];

        foreach ($this->organization($organizationId) as [$cidr, $what]) {
            if ($what === self::MEMBER) {
                $members[] = (string) $cidr;
            } else {
                $core[] = (string) $cidr;
            }
        }

        $core = array_values(array_unique($core));
        sort($core);

        return [$core, array_values(array_diff(array_unique($members), $core))];
    }

    /**
     * @return list<array{0: Cidr, 1: string}>
     */
    private function organization(string $organizationId): array
    {
        if (isset($this->memo[$organizationId])) {
            return $this->memo[$organizationId];
        }

        $out = [];

        foreach ($this->servers->forOrganization($organizationId) as $server) {
            /** @var ServerData $server */
            foreach ([$server->ipv4, $server->ipv6, $server->privateIpv4] as $address) {
                if (($cidr = Cidr::fromAddress($address)) !== null) {
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
            if (($cidr = Cidr::fromAddress((string) $address)) !== null) {
                $out[] = [$cidr, 'a WireGuard peer'];
            }
        }

        $days = (int) config('network.protection.member_address_days', 30);

        foreach ($this->organizations->recentMemberAddresses($organizationId, now()->subDays($days), 500) as $address) {
            if (($cidr = Cidr::fromAddress($address)) !== null) {
                $out[] = [$cidr, self::MEMBER];
            }
        }

        return $this->memo[$organizationId] = $out;
    }

    /**
     * The control plane's addresses: configured (FALAK_CONTROL_PLANE_IPS) plus the resolved panel and agent API hosts
     * (cached for 10 minutes). Configured entries that are not addresses are logged and ignored.
     *
     * @return list<Cidr>
     */
    public function controlPlane(): array
    {
        $out = [];

        foreach ((array) config('network.protection.control_plane_addresses', []) as $address) {
            $cidr = Cidr::fromAddress(trim((string) $address)) ?? Cidr::tryParse(trim((string) $address));

            if ($cidr === null) {
                Log::warning('falak.network: ignoring an invalid FALAK_CONTROL_PLANE_IPS entry', ['entry' => mb_substr((string) $address, 0, 64)]);

                continue;
            }

            $out[(string) $cidr] = $cidr;
        }

        $hosts = array_filter(array_map(fn ($url) => is_string($url) ? parse_url($url, PHP_URL_HOST) : null, [config('app.url'), config('fleet.panel_url'), config('fleet.api_url')]));

        foreach (array_unique($hosts) as $host) {
            $host = trim((string) $host, '[]');
            $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : Cache::remember("network.protection.resolve:{$host}", 600, fn () => self::resolve($host));

            foreach ($addresses as $address) {
                if (($cidr = Cidr::fromAddress((string) $address)) !== null) {
                    $out[(string) $cidr] = $cidr;
                }
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
