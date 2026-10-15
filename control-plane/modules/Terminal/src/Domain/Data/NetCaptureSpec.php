<?php

namespace Falak\Terminal\Domain\Data;

use Illuminate\Validation\ValidationException;

/**
 * net.capture arguments (docs/plans/network/capture.md), validated as the agent validates them (agent/internal/nettool
 * capture_spec.go): an interface kind (host captures only), a duration, a size cap, headers-only or full packets, and a
 * structured filter (an IP or CIDR, a port, a protocol, a direction). There is no free-form BPF: the agent builds the
 * expression from these fields.
 */
final class NetCaptureSpec
{
    public const INTERFACES = ['default', 'wireguard', 'docker'];

    public const PROTOCOLS = ['tcp', 'udp', 'icmp'];

    public const DIRECTIONS = ['in', 'out', 'both'];

    public const HEADERS_SNAPLEN = 128;

    public const FULL_SNAPLEN = 262144;

    public const MAX_DURATION = 60;

    public const MAX_MB = 100;

    /**
     * The net.capture payload for $input (without capture_id and container), defaults filled.
     *
     * @param  array<string, mixed>  $input  interface?, duration_s?, max_mb?, full_packets?, filter?{host?, port?, protocol?, direction?}
     * @return array{interface?: string, filter?: array<string, int|string>, snaplen: int, duration_s: int, max_bytes: int}
     *
     * @throws ValidationException
     */
    public static function payload(array $input, bool $inContainer): array
    {
        $payload = [];
        $interface = $input['interface'] ?? null;

        if ($inContainer) {
            if ($interface !== null && $interface !== '') {
                self::fail('interface', 'A container captures on all of its interfaces; leave the interface empty.');
            }
        } else {
            $interface = $interface === null || $interface === '' ? 'default' : $interface;

            if (! is_string($interface) || ! in_array($interface, self::INTERFACES, true)) {
                self::fail('interface', 'Pick one of: '.implode(', ', self::INTERFACES).'.');
            }

            $payload['interface'] = $interface;
        }

        $filter = $input['filter'] ?? [];

        if (! is_array($filter) || array_diff(array_keys($filter), ['host', 'port', 'protocol', 'direction']) !== []) {
            self::fail('filter', 'The filter takes host, port, protocol and direction.');
        }

        $clean = [];
        $host = trim((string) ($filter['host'] ?? ''));

        if ($host !== '') {
            if (! self::validHostFilter($host)) {
                self::fail('filter.host', 'Enter an IP address or a CIDR (names are not resolved).');
            }

            $clean['host'] = $host;
        }

        $protocol = $filter['protocol'] ?? null;

        if ($protocol !== null && $protocol !== '') {
            if (! in_array($protocol, self::PROTOCOLS, true)) {
                self::fail('filter.protocol', 'Pick one of: '.implode(', ', self::PROTOCOLS).'.');
            }

            $clean['protocol'] = $protocol;
        }

        if (($filter['port'] ?? null) !== null && $filter['port'] !== '') {
            $port = self::integer($filter['port'], 1, 65535, 'filter.port', 'The port must be 1–65535.');

            if (($clean['protocol'] ?? null) === 'icmp') {
                self::fail('filter.port', 'ICMP has no ports.');
            }

            $clean['port'] = $port;
        }

        $direction = $filter['direction'] ?? null;

        if ($direction !== null && $direction !== '') {
            if (! in_array($direction, self::DIRECTIONS, true)) {
                self::fail('filter.direction', 'Pick one of: '.implode(', ', self::DIRECTIONS).'.');
            }

            $clean['direction'] = $direction;
        }

        if ($clean !== []) {
            $payload['filter'] = $clean;
        }

        $payload['snaplen'] = filter_var($input['full_packets'] ?? false, FILTER_VALIDATE_BOOLEAN) ? self::FULL_SNAPLEN : self::HEADERS_SNAPLEN;
        $payload['duration_s'] = self::integer($input['duration_s'] ?? 30, 1, self::MAX_DURATION, 'duration_s', 'The duration must be 1–'.self::MAX_DURATION.' seconds.');
        $payload['max_bytes'] = self::integer($input['max_mb'] ?? 20, 1, self::MAX_MB, 'max_mb', 'The size cap must be 1–'.self::MAX_MB.' MiB.') * 1024 * 1024;

        return $payload;
    }

    /** An IP address or a CIDR (IPv4 /0–32, IPv6 /0–128); never a name, never anything tcpdump could read as an option. */
    public static function validHostFilter(string $host): bool
    {
        [$ip, $bits] = array_pad(explode('/', $host, 2), 2, null);

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if ($bits === null) {
            return true;
        }

        $max = str_contains($ip, ':') ? 128 : 32;

        return ctype_digit($bits) && strlen($bits) <= 3 && (int) $bits <= $max;
    }

    private static function integer(mixed $value, int $min, int $max, string $field, string $message): int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < $min || $value > $max) {
            self::fail($field, $message);
        }

        return $value;
    }

    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
