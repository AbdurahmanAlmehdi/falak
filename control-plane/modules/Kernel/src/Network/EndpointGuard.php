<?php

namespace Falak\Kernel\Network;

/**
 * Keeps control-plane requests to user-supplied endpoints (secret providers, backup object storage, alert
 * webhooks) away from the control plane's own network (SSRF): an endpoint must be https (or a scheme the caller
 * allows) and resolve only to public addresses.
 * Callers that allow private networks (a self-hosted Vault, a MinIO on the LAN) may also reach private, loopback
 * and CGNAT ranges. Link-local and cloud metadata addresses (169.254.169.254 and friends) are refused always,
 * including when embedded in an IPv6 address; numeric hosts other than a dotted quad are refused.
 *
 * Check when an endpoint is saved and before every request; pin the request to the addresses returned by
 * {@see check()} ({@see pin()}), so a DNS answer that changes in between (rebinding) can't redirect it, and don't
 * follow redirects (the target of one is unchecked).
 */
class EndpointGuard
{
    /** Never reachable, whatever the provider allows. */
    private const BLOCKED = [
        '0.0.0.0/8', '169.254.0.0/16', '100.100.100.200/32', '192.0.0.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '::/128', 'fe80::/10', 'fec0::/10', 'fd00:ec2::254/128', 'ff00::/8',
    ];

    /**
     * IPv6 ranges that embed an IPv4 address (checked as that address): IPv4-mapped (::ffff:0:0/96),
     * IPv4-compatible (::/96), NAT64 (64:ff9b::/96) and 6to4 (2002::/16, the address in bytes 2–5).
     *
     * @var array<string, int> range => offset of the IPv4 address
     */
    private const EMBEDDING = ['::ffff:0:0/96' => 12, '::/96' => 12, '64:ff9b::/96' => 12, '2002::/16' => 2];

    /** Reachable only with "allow private network". */
    private const PRIVATE = [
        '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10', '127.0.0.0/8', '198.18.0.0/15',
        '::1/128', 'fc00::/7',
    ];

    /** @var callable(string): list<string> */
    private $resolver;

    /**
     * @param  callable(string): list<string>|null  $resolver  host → IP addresses
     */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? self::resolve(...);
    }

    /**
     * @param  list<string>  $schemes
     * @return list<string> the addresses the host resolves to (all allowed)
     *
     * @throws EndpointRefused when the URL is not https (or one of $schemes), does not resolve, or reaches a refused address
     */
    public function check(string $url, bool $allowPrivate, array $schemes = ['https']): array
    {
        if (! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), $schemes, true)) {
            throw new EndpointRefused('Endpoints must use '.implode(' or ', array_map(fn (string $scheme) => "{$scheme}://", $schemes)).'.');
        }

        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');

        if ($host === '') {
            throw new EndpointRefused('The endpoint has no host.');
        }

        // Numeric hosts other than a dotted quad (2130706433, 0x7f.1, 0177.0.0.1): HTTP clients read them as
        // addresses, DNS does not. Refused rather than guessed.
        if (filter_var($host, FILTER_VALIDATE_IP) === false && preg_match('/^(0x[0-9a-f]*|\d+)(\.(0x[0-9a-f]*|\d+))*\.?$/i', $host) === 1) {
            throw new EndpointRefused("The host {$host} is not a canonical address.");
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolver)($host);

        if ($addresses === []) {
            throw new EndpointRefused("The host {$host} does not resolve.");
        }

        foreach ($addresses as $address) {
            $refusal = $this->addressRefusal($address, $allowPrivate);

            if ($refusal !== null) {
                throw new EndpointRefused("The host {$host} resolves to {$address}, {$refusal}.");
            }
        }

        return array_values($addresses);
    }

    /**
     * Why the URL is refused, or null when it is allowed (the {@see check()} message, without the exception).
     *
     * @param  list<string>  $schemes
     */
    public function refusal(string $url, bool $allowPrivate, array $schemes = ['https']): ?string
    {
        try {
            $this->check($url, $allowPrivate, $schemes);
        } catch (EndpointRefused $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * curl options that connect a request for $url to $address (one {@see check()} returned) and nothing else.
     * Name-agnostic (any host, any port), so a host curl derives differently from the URL (%xx decoded) is pinned too.
     *
     * @return array<int, mixed>
     */
    public static function pin(string $url, string $address): array
    {
        $port = (int) (parse_url($url, PHP_URL_PORT) ?: (strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'http' ? 80 : 443));

        return [CURLOPT_CONNECT_TO => ['::'.(str_contains($address, ':') ? "[{$address}]" : $address).":{$port}"]];
    }

    private function addressRefusal(string $address, bool $allowPrivate): ?string
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return 'a link-local, metadata or reserved address, which is never allowed';
        }

        // An IPv6 address carrying an IPv4 one (in any notation) is checked as that IPv4 address as well.
        $embedded = self::embeddedIpv4($address);

        if ($embedded !== null && ($refusal = $this->addressRefusal($embedded, $allowPrivate)) !== null) {
            return $refusal;
        }

        if (self::within($address, self::BLOCKED)) {
            return 'a link-local, metadata or reserved address, which is never allowed';
        }

        if ($allowPrivate) {
            return null;
        }

        if (self::within($address, self::PRIVATE) || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return 'a private or reserved address (allowed only where private networks are enabled)';
        }

        return null;
    }

    private static function embeddedIpv4(string $address): ?string
    {
        $ip = inet_pton($address);

        // :: and ::1 are IPv4-compatible in form only (unspecified and loopback: covered by the lists).
        if ($ip === false || strlen($ip) !== 16 || in_array($address, ['::', '::1'], true) || $ip === inet_pton('::') || $ip === inet_pton('::1')) {
            return null;
        }

        foreach (self::EMBEDDING as $range => $offset) {
            if (self::within($address, [$range])) {
                return (string) inet_ntop(substr($ip, $offset, 4));
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $ranges
     */
    private static function within(string $address, array $ranges): bool
    {
        $ip = inet_pton($address);

        foreach ($ranges as $range) {
            [$network, $bits] = explode('/', $range);
            $net = inet_pton($network);

            if ($ip === false || $net === false || strlen($ip) !== strlen($net)) {
                continue;
            }

            $bytes = intdiv((int) $bits, 8);
            $rest = (int) $bits % 8;

            if (substr($ip, 0, $bytes) !== substr($net, 0, $bytes)) {
                continue;
            }

            if ($rest === 0 || ((ord($ip[$bytes]) ^ ord($net[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(fn (array $r) => $r['ip'] ?? $r['ipv6'] ?? null, $records)));
    }
}
