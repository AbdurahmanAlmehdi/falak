<?php

namespace Falak\Uptime\Domain\Probing;

/**
 * SSRF guard: which resolved addresses a check may connect to. Refuses loopback, private (RFC 1918, ULA), CGNAT,
 * link-local (incl. the cloud metadata service), multicast, unspecified, reserved and documentation ranges, the
 * IPv4-mapped / NAT64 / 6to4 forms of those, and configured extra ranges. With private targets allowed (an operator
 * setting) only link-local, unspecified and multicast stay refused: the metadata service is never a monitoring target.
 */
final class AddressGuard
{
    /** Always refused. */
    private const ALWAYS = [
        '0.0.0.0/8', '169.254.0.0/16', '224.0.0.0/4', '255.255.255.255/32',
        // Cloud metadata services outside link-local: Alibaba, Oracle.
        '100.100.100.200/32', '192.0.0.192/32',
        '::/128', 'fe80::/10', 'ff00::/8', 'fd00:ec2::254/128',
        // SIIT (IPv4-translated ::ffff:0:a.b.c.d) and local-use NAT64: may translate to any IPv4 address.
        '::ffff:0:0:0/96', '64:ff9b:1::/48',
    ];

    /** Refused unless private targets are allowed. */
    private const PRIVATE = [
        '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
        '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '240.0.0.0/4',
        '::1/128', '100::/64', '2001::/23', '2001:db8::/32', 'fc00::/7', 'fec0::/10',
    ];

    /** Host names refused before resolution (unless private targets are allowed). */
    private const PRIVATE_SUFFIXES = ['localhost', 'local', 'internal', 'localdomain', 'home.arpa', 'lan', 'intranet'];

    /**
     * @param  list<string>  $blockedCidrs  extra refused ranges
     * @param  list<string>  $selfIps  the control plane's own public addresses: refused unless the caller allows them
     *                                 (site domain monitors on a single-host install)
     */
    public function __construct(
        private readonly bool $allowPrivate = false,
        private readonly array $blockedCidrs = [],
        private readonly array $selfIps = [],
    ) {}

    public function allowsHost(string $host): bool
    {
        if ($this->allowPrivate) {
            return true;
        }

        $host = rtrim(strtolower($host), '.');

        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host !== '';
        }

        // Single-label names resolve through search domains on the control plane's network.
        if (! str_contains($host, '.')) {
            return false;
        }

        foreach (self::PRIVATE_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, ".{$suffix}")) {
                return false;
            }
        }

        return true;
    }

    public function allows(string $ip, bool $allowSelf = false): bool
    {
        $packed = @inet_pton(trim($ip, '[]'));

        if ($packed === false) {
            return false;
        }

        if (! $allowSelf) {
            foreach ($this->selfIps as $self) {
                if (@inet_pton(trim($self, '[]')) === $packed) {
                    return false;
                }
            }
        }

        // IPv4 embedded in IPv6 (mapped ::ffff:a.b.c.d, NAT64 64:ff9b::/96, 6to4 2002::/16): judge the IPv4 address.
        if (strlen($packed) === 16) {
            $embedded = self::embeddedIpv4($packed);

            if ($embedded !== null) {
                return $this->allows((string) inet_ntop($embedded), $allowSelf);
            }
        }

        $ranges = [...self::ALWAYS, ...$this->blockedCidrs, ...($this->allowPrivate ? [] : self::PRIVATE)];

        foreach ($ranges as $cidr) {
            if (self::inCidr($packed, $cidr)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $ips
     * @return string|null the first address, when every address is allowed (one refused address refuses the host:
     *                     a round-robin answer must not reach the control plane's network on some attempts)
     */
    public function pick(array $ips, bool $allowSelf = false): ?string
    {
        if ($ips === []) {
            return null;
        }

        foreach ($ips as $ip) {
            if (! $this->allows($ip, $allowSelf)) {
                return null;
            }
        }

        return $ips[0];
    }

    private static function embeddedIpv4(string $packed): ?string
    {
        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return substr($packed, 12);
        }

        if (str_starts_with($packed, "\x00\x64\xff\x9b".str_repeat("\0", 8))) {
            return substr($packed, 12);
        }

        if (str_starts_with($packed, "\x20\x02")) {
            return substr($packed, 2, 4);
        }

        // IPv4-compatible ::a.b.c.d (deprecated, still routed by some stacks); ::1 and :: are judged as IPv6.
        if (str_starts_with($packed, str_repeat("\0", 12)) && substr($packed, 12, 2) !== "\0\0") {
            return substr($packed, 12);
        }

        return null;
    }

    public static function inCidr(string $packed, string $cidr): bool
    {
        [$network, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $net = @inet_pton((string) $network);

        if ($net === false || strlen($net) !== strlen($packed)) {
            return false;
        }

        $bits = $bits === null ? strlen($net) * 8 : max(0, min(strlen($net) * 8, (int) $bits));
        $bytes = intdiv($bits, 8);

        if (substr($packed, 0, $bytes) !== substr($net, 0, $bytes)) {
            return false;
        }

        $rest = $bits % 8;

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }
}
