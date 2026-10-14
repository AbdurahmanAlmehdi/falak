<?php

namespace Falak\Telemetry\Infrastructure\Geo;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * DB-IP Lite databases (country, ASN; CC BY 4.0, https://db-ip.com) in telemetry.geoip.path, downloaded by the control
 * plane once a month, and the lookups of client IPs in them (docs/plans/network/http.md, "Countries and networks").
 * A missing, disabled or corrupt database gives no answers; nothing fails.
 */
final class GeoDatabases
{
    public const NAMES = ['country', 'asn'];

    public const ATTRIBUTION = 'IP geolocation by DB-IP';

    public const ATTRIBUTION_URL = 'https://db-ip.com';

    /** @var array<string, MaxMindReader|false> opened readers (false: unusable) */
    private array $readers = [];

    public function path(string $name): string
    {
        return rtrim((string) config('telemetry.geoip.path'), '/').'/'.$name.'.mmdb';
    }

    /**
     * @return array{enabled: bool, country: bool, asn: bool, updated_at: ?string, attribution: string, attribution_url: string}
     */
    public function status(): array
    {
        $country = $this->reader('country') !== null;
        $file = $this->path('country');

        return [
            'enabled' => (bool) config('telemetry.geoip.enabled', true),
            'country' => $country,
            'asn' => $this->reader('asn') !== null,
            'updated_at' => $country && is_file($file) ? CarbonImmutable::createFromTimestamp((int) filemtime($file))->toIso8601String() : null,
            'attribution' => self::ATTRIBUTION,
            'attribution_url' => self::ATTRIBUTION_URL,
        ];
    }

    /**
     * Country and network of each IP (null fields when unknown).
     *
     * @param  list<string>  $ips
     * @return array<string, array{country: ?string, country_name: ?string, asn: ?int, as_org: ?string}>
     */
    public function lookup(array $ips): array
    {
        $country = $this->reader('country');
        $asn = $this->reader('asn');
        $out = [];

        foreach ($ips as $ip) {
            $c = $a = null;

            try {
                $c = $country?->get($ip);
                $a = $asn?->get($ip);
            } catch (Throwable) {
                // A corrupt record answers nothing for this address.
            }

            $out[$ip] = [
                'country' => is_array($c) && is_string($c['country']['iso_code'] ?? null) ? $c['country']['iso_code'] : null,
                'country_name' => is_array($c) && is_string($c['country']['names']['en'] ?? null) ? $c['country']['names']['en'] : null,
                'asn' => is_array($a) && is_int($a['autonomous_system_number'] ?? null) ? $a['autonomous_system_number'] : null,
                'as_org' => is_array($a) && is_string($a['autonomous_system_organization'] ?? null) ? $a['autonomous_system_organization'] : null,
            ];
        }

        return $out;
    }

    private function reader(string $name): ?MaxMindReader
    {
        if (! (bool) config('telemetry.geoip.enabled', true)) {
            return null;
        }

        if (! array_key_exists($name, $this->readers)) {
            try {
                $this->readers[$name] = is_file($this->path($name)) ? new MaxMindReader($this->path($name)) : false;
            } catch (Throwable $e) {
                Log::warning("GeoIP database {$name} is unusable: {$e->getMessage()}");
                $this->readers[$name] = false;
            }
        }

        return $this->readers[$name] ?: null;
    }

    /** Whether a database is missing or older than telemetry.geoip.max_age_days. */
    public function stale(string $name): bool
    {
        $file = $this->path($name);

        return ! is_file($file) || filemtime($file) < now()->subDays((int) config('telemetry.geoip.max_age_days', 32))->getTimestamp();
    }

    /**
     * Downloads one database (the current month's, else the previous month's), checks it opens and answers, and
     * replaces the old file atomically. A failure keeps the old file.
     *
     * @throws RuntimeException
     */
    public function download(string $name): void
    {
        $template = (string) config("telemetry.geoip.{$name}_url");
        $dir = dirname($this->path($name));

        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create {$dir}.");
        }

        $errors = [];

        foreach ([now(), now()->subMonthNoOverflow()] as $month) {
            $url = strtr($template, ['{Y}' => $month->format('Y'), '{m}' => $month->format('m')]);
            $response = Http::timeout((int) config('telemetry.geoip.timeout', 300))->get($url);

            if (! $response->successful()) {
                $errors[] = "{$url}: HTTP {$response->status()}";

                continue;
            }

            $body = $response->body();
            $data = str_ends_with(parse_url($url, PHP_URL_PATH) ?: '', '.gz') ? @gzdecode($body) : $body;

            if (! is_string($data) || $data === '') {
                $errors[] = "{$url}: not a gzip file";

                continue;
            }

            $tmp = $this->path($name).'.tmp';
            file_put_contents($tmp, $data);

            try {
                new MaxMindReader($tmp);
            } catch (Throwable $e) {
                @unlink($tmp);
                $errors[] = "{$url}: {$e->getMessage()}";

                continue;
            }

            rename($tmp, $this->path($name));
            unset($this->readers[$name]);

            return;
        }

        throw new RuntimeException("GeoIP database {$name} not downloaded: ".implode('; ', $errors));
    }
}
