<?php

namespace Falak\Terminal\Domain\Data;

use Illuminate\Validation\ValidationException;

/**
 * net.tool arguments (docs/plans/network/tools.md), validated as the agent validates them (agent/internal/nettool
 * spec.go): each tool takes only its own fields, hostnames are LDH labels or IPs (never starting with "-"), URLs are
 * http(s) without credentials. The agent checks again; nothing ever reaches a shell.
 */
final class NetToolSpec
{
    public const TOOLS = ['ping', 'traceroute', 'dns', 'http', 'tcp'];

    public const RECORD_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'PTR'];

    /** Fields each tool takes besides timeout_s. */
    private const FIELDS = [
        'ping' => ['target', 'count'],
        'traceroute' => ['target', 'max_hops'],
        'dns' => ['target', 'record_type', 'nameserver'],
        'http' => ['url', 'method', 'connect_to', 'insecure'],
        'tcp' => ['target', 'port', 'count'],
    ];

    private const ALL_FIELDS = ['target', 'url', 'port', 'count', 'max_hops', 'record_type', 'nameserver', 'method', 'connect_to', 'insecure'];

    /**
     * The net.tool payload for $input (without `container`), defaults filled.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function payload(array $input): array
    {
        $tool = $input['tool'] ?? null;

        if (! is_string($tool) || ! in_array($tool, self::TOOLS, true)) {
            self::fail('tool', 'Pick one of: '.implode(', ', self::TOOLS).'.');
        }

        $allowed = self::FIELDS[$tool];

        foreach (self::ALL_FIELDS as $field) {
            if (self::present($input, $field) && ! in_array($field, $allowed, true)) {
                self::fail($field, "{$field} does not apply to {$tool}.");
            }
        }

        $payload = ['tool' => $tool];

        if (in_array('target', $allowed, true)) {
            $target = trim((string) ($input['target'] ?? ''));

            if (! self::validHost($target)) {
                self::fail('target', 'Enter a hostname or an IP address.');
            }
            $payload['target'] = $target;
        }

        switch ($tool) {
            case 'ping':
                $payload['count'] = self::int($input, 'count', 4, 1, 20);
                break;
            case 'traceroute':
                $payload['max_hops'] = self::int($input, 'max_hops', 20, 1, 30);
                break;
            case 'dns':
                $type = strtoupper((string) ($input['record_type'] ?? 'A') ?: 'A');
                if (! in_array($type, self::RECORD_TYPES, true)) {
                    self::fail('record_type', 'Pick one of: '.implode(', ', self::RECORD_TYPES).'.');
                }
                if ($type === 'PTR' && filter_var($payload['target'], FILTER_VALIDATE_IP) === false) {
                    self::fail('target', 'A PTR lookup needs an IP address.');
                }
                $payload['record_type'] = $type;
                if (self::present($input, 'nameserver')) {
                    $payload['nameserver'] = self::ip($input, 'nameserver');
                }
                break;
            case 'http':
                $payload['url'] = self::url((string) ($input['url'] ?? ''));
                $method = strtoupper((string) ($input['method'] ?? 'GET') ?: 'GET');
                if (! in_array($method, ['GET', 'HEAD'], true)) {
                    self::fail('method', 'The method must be GET or HEAD.');
                }
                $payload['method'] = $method;
                if (self::present($input, 'connect_to')) {
                    $payload['connect_to'] = self::ip($input, 'connect_to');
                }
                if (filter_var($input['insecure'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    $payload['insecure'] = true;
                }
                break;
            case 'tcp':
                $payload['port'] = self::int($input, 'port', null, 1, 65535);
                $payload['count'] = self::int($input, 'count', 3, 1, 10);
                break;
        }

        $payload['timeout_s'] = self::int($input, 'timeout_s', 30, 1, 60);

        return $payload;
    }

    /** A hostname (LDH labels, underscores allowed, ≤ 63 each, ≤ 253 in all, optional trailing dot) or an IP. */
    public static function validHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 254) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        $name = rtrim($host, '.');

        if ($name === '' || strlen($name) > 253 || str_ends_with($host, '..')) {
            return false;
        }

        foreach (explode('.', $name) as $label) {
            if (preg_match('/^[A-Za-z0-9_]([A-Za-z0-9_-]{0,61}[A-Za-z0-9_])?$/', $label) !== 1) {
                return false;
            }
        }

        return true;
    }

    private static function url(string $url): string
    {
        if ($url === '' || strlen($url) > 2048) {
            self::fail('url', 'Enter an http:// or https:// URL (at most 2048 characters).');
        }

        if (preg_match('/[\s\x00-\x1f\x7f\\\\]/', $url) === 1) {
            self::fail('url', 'The URL must not contain spaces or control characters.');
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if ($parts === false || ! in_array($scheme, ['http', 'https'], true)) {
            self::fail('url', 'The URL must start with http:// or https://.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            self::fail('url', 'The URL must not contain credentials.');
        }

        $host = trim((string) ($parts['host'] ?? ''), '[]');

        if (! self::validHost($host)) {
            self::fail('url', 'The URL must name a hostname or an IP address.');
        }

        if (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535)) {
            self::fail('url', 'The URL has an invalid port.');
        }

        return $url;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function ip(array $input, string $field): string
    {
        $value = trim((string) $input[$field]);

        if (filter_var($value, FILTER_VALIDATE_IP) === false) {
            self::fail($field, 'Enter an IP address.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function int(array $input, string $field, ?int $default, int $min, int $max): int
    {
        if (! self::present($input, $field)) {
            if ($default === null) {
                self::fail($field, "The {$field} is required.");
            }

            return $default;
        }

        $value = filter_var($input[$field], FILTER_VALIDATE_INT);

        if ($value === false || $value < $min || $value > $max) {
            self::fail($field, "The {$field} must be between {$min} and {$max}.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function present(array $input, string $field): bool
    {
        $value = $input[$field] ?? null;

        if ($field === 'insecure') {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return $value !== null && $value !== '' && $value !== false;
    }

    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
