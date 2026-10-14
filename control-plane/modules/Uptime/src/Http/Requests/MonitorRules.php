<?php

namespace Falak\Uptime\Http\Requests;

use Closure;
use Falak\Uptime\Application\Probing\GuardedProber;
use Falak\Uptime\Domain\Enums\MonitorType;
use Falak\Uptime\Domain\Probing\AddressGuard;
use Falak\Uptime\Domain\Probing\ExpectedStatus;
use Illuminate\Validation\Rule;

/**
 * Validation of monitor input. Targets are checked against the SSRF guard here for a clear error; the guard runs
 * again on every check against what the host resolves to then.
 */
final class MonitorRules
{
    public function __construct(private readonly AddressGuard $guard) {}

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'expected_status' => ['sometimes', 'nullable', 'string', 'max:64', function (string $attribute, mixed $value, Closure $fail) {
                if (! ExpectedStatus::isValid(is_string($value) ? $value : null)) {
                    $fail('Use status codes (200) or classes (2xx), separated by commas.');
                }
            }],
            'keyword' => ['sometimes', 'nullable', 'string', 'max:200'],
            'keyword_absent' => ['sometimes', 'boolean'],
            'max_redirects' => ['sometimes', 'integer', 'min:0', 'max:'.(int) config('uptime.max_redirects', 5)],
            'timeout_seconds' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('uptime.max_timeout', 20)],
            'interval_seconds' => ['sometimes', 'integer', 'min:'.(int) config('uptime.min_interval', 30), 'max:'.(int) config('uptime.max_interval', 600)],
            'slow_ms' => ['sometimes', 'nullable', 'integer', 'min:100', 'max:60000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function target(?string $type): array
    {
        return [
            'type' => ['required', Rule::enum(MonitorType::class)],
            'name' => ['required', 'string', 'max:120'],
            'site_id' => ['nullable', 'string', 'size:26'],
            'url' => [Rule::requiredIf($type === MonitorType::Http->value), 'nullable', 'string', 'max:2048', function (string $attribute, mixed $value, Closure $fail) use ($type) {
                if ($type !== MonitorType::Http->value || ! is_string($value)) {
                    return;
                }

                $parts = GuardedProber::parts($value);

                if ($parts === null) {
                    $fail('Enter an http:// or https:// URL without credentials, with a plain host name (letters, digits, dashes, dots) or an IP address.');
                } elseif (! $this->allowed($parts[1])) {
                    $fail('This host is private or reserved: checks only reach public addresses.');
                }
            }],
            'host' => [Rule::requiredIf(in_array($type, [MonitorType::Tcp->value, MonitorType::Tls->value], true)), 'nullable', 'string', 'max:253', function (string $attribute, mixed $value, Closure $fail) use ($type) {
                if ($type === MonitorType::Http->value || ! is_string($value)) {
                    return;
                }

                $host = trim(strtolower($value), '[]');

                if (! GuardedProber::validHost($host)) {
                    $fail('Enter a host name or an IP address.');
                } elseif (! $this->allowed($host)) {
                    $fail('This host is private or reserved: checks only reach public addresses.');
                }
            }],
            'port' => [Rule::requiredIf($type === MonitorType::Tcp->value), 'nullable', 'integer', 'min:1', 'max:65535'],
            ...array_diff_key($this->settings(), ['name' => true]),
        ];
    }

    private function allowed(string $host): bool
    {
        $host = trim($host, '[]');

        if (! $this->guard->allowsHost($host)) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_IP) === false || $this->guard->pick([$host]) !== null;
    }
}
