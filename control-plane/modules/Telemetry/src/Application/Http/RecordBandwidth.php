<?php

namespace Falak\Telemetry\Application\Http;

use Carbon\CarbonImmutable;
use Falak\Alerting\Contracts\Alerts;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Telemetry\Application\Queries\HttpQueries;
use Falak\Telemetry\Contracts\Exceptions\TelemetryQueryFailed;
use Falak\Telemetry\Contracts\Exceptions\TelemetryUnavailable;
use Falak\Telemetry\Contracts\LogMetrics;
use Falak\Telemetry\Contracts\MetricsBackend;
use Falak\Telemetry\Domain\Models\HttpSettings;
use Falak\Telemetry\Infrastructure\LokiAccessLogCounts;
use Illuminate\Support\Facades\DB;

/**
 * Bytes served per site and hour (docs/plans/network/http.md, "Bandwidth per site per month"): the agent's counters
 * when they exist, else the access log; the last two complete hours are (re)computed, so a late sample or a missed run
 * is caught up idempotently. A site's monthly threshold raises one alert per month.
 */
final class RecordBandwidth
{
    public function __construct(
        private readonly MetricsBackend $metrics,
        private readonly LogMetrics $logs,
        private readonly SiteDirectory $sites,
        private readonly Alerts $alerts,
    ) {}

    public function __invoke(string $organizationId, ?CarbonImmutable $now = null): void
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $sites = collect($this->sites->forOrganization($organizationId))->keyBy(fn (SiteData $s) => $s->slug);

        if ($sites->isEmpty()) {
            return;
        }

        foreach ([2, 1] as $hoursAgo) {
            $hour = $now->startOfHour()->subHours($hoursAgo);
            $rows = $this->hour($organizationId, $hour->addHour());

            foreach ($rows as $slug => $row) {
                if (! $sites->has($slug)) {
                    continue;
                }

                DB::table('telemetry_http_bandwidth')->upsert(
                    [['organization_id' => strtolower($organizationId), 'site_slug' => $slug, 'hour' => $hour, ...$row]],
                    ['organization_id', 'site_slug', 'hour'],
                    ['bytes_out', 'bytes_in', 'requests'],
                );
            }
        }

        DB::table('telemetry_http_bandwidth')->where('hour', '<', $now->subDays((int) config('telemetry.http.bandwidth_retention_days', 400)))->delete();
        $this->alert($organizationId, $sites->all(), $now);
    }

    /**
     * Bytes and requests per slug in the hour ending at $end.
     *
     * @return array<string, array{bytes_out: int, bytes_in: int, requests: int}>
     */
    private function hour(string $org, CarbonImmutable $end): array
    {
        $rows = [];

        try {
            $q = HttpQueries::bandwidthHour($org);

            foreach ($this->metrics->query($q['bytes'], $end) as $s) {
                $slug = $s->labels['service_name'] ?? '';
                $rows[$slug] ??= ['bytes_out' => 0, 'bytes_in' => 0, 'requests' => 0];
                $rows[$slug][($s->labels['network_io_direction'] ?? '') === 'receive' ? 'bytes_in' : 'bytes_out'] += (int) round((float) $s->last());
            }

            foreach ($this->metrics->query($q['requests'], $end) as $s) {
                $slug = $s->labels['service_name'] ?? '';
                $rows[$slug] ??= ['bytes_out' => 0, 'bytes_in' => 0, 'requests' => 0];
                $rows[$slug]['requests'] = (int) round((float) $s->last());
            }
        } catch (TelemetryUnavailable|TelemetryQueryFailed) {
            // No metrics backend: the access log alone.
        }

        if ((string) config('telemetry.loki.url') === '') {
            return $rows;
        }

        // Sites without the agent's counters (agents before v0.17): from the access log.
        $q = HttpQueries::bandwidthHourFromLogs(HttpQueries::organizationAccess($org, LokiAccessLogCounts::PROBE_AGENT));
        $fromLogs = [];

        foreach ($q as $field => $logql) {
            foreach ($this->logs->queryRange($logql, $end, $end, 60) as $s) {
                $fromLogs[$s->labels['service_name'] ?? ''][$field] = (int) round((float) $s->last());
            }
        }

        foreach ($fromLogs as $slug => $row) {
            $rows[$slug] ??= ['bytes_out' => $row['bytes_out'] ?? 0, 'bytes_in' => $row['bytes_in'] ?? 0, 'requests' => $row['requests'] ?? 0];
        }

        return $rows;
    }

    /**
     * @param  array<string, SiteData>  $sites
     */
    private function alert(string $org, array $sites, CarbonImmutable $now): void
    {
        $thresholds = HttpSettings::query()->where('organization_id', strtolower($org))->whereNotNull('bandwidth_alert_bytes')->pluck('bandwidth_alert_bytes', 'site_id');

        if ($thresholds->isEmpty()) {
            return;
        }

        $month = DB::table('telemetry_http_bandwidth')
            ->where('organization_id', strtolower($org))
            ->where('hour', '>=', $now->startOfMonth())
            ->groupBy('site_slug')
            ->selectRaw('site_slug, SUM(bytes_out) AS bytes')
            ->pluck('bytes', 'site_slug');

        foreach ($sites as $slug => $site) {
            $limit = (int) ($thresholds[$site->id] ?? 0);
            $bytes = (int) ($month[$slug] ?? 0);

            if ($limit > 0 && $bytes >= $limit) {
                $this->alerts->raise(new AlertData(
                    $org, EvaluateHttpTraffic::ALERT_BANDWIDTH, Severity::Warning,
                    "{$site->name} served ".self::bytes($bytes).' this month',
                    'The site reached its monthly bandwidth threshold of '.self::bytes($limit).' (response bodies served by the edge).',
                    "/sites/{$site->id}", "http.bandwidth:{$site->id}:".$now->format('Ym'),
                    context: ['site_id' => $site->id, 'bytes' => $bytes, 'threshold_bytes' => $limit], action: 'Review traffic',
                ));
            }
        }
    }

    public static function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return round($value, $unit === 0 ? 0 : 1).' '.$units[$unit];
    }

    /**
     * Months of a site, newest first (bytes out / in, requests).
     *
     * @return list<array{month: string, bytes_out: int, bytes_in: int, requests: int}>
     */
    public static function months(string $org, string $slug, int $months = 12): array
    {
        $rows = DB::table('telemetry_http_bandwidth')
            ->where('organization_id', strtolower($org))
            ->where('site_slug', $slug)
            ->where('hour', '>=', CarbonImmutable::now()->utc()->startOfMonth()->subMonthsNoOverflow($months - 1))
            ->get(['hour', 'bytes_out', 'bytes_in', 'requests']);
        $out = [];

        foreach ($rows as $row) {
            $key = CarbonImmutable::parse((string) $row->hour, 'UTC')->format('Y-m');
            $out[$key] ??= ['month' => $key, 'bytes_out' => 0, 'bytes_in' => 0, 'requests' => 0];
            $out[$key]['bytes_out'] += (int) $row->bytes_out;
            $out[$key]['bytes_in'] += (int) $row->bytes_in;
            $out[$key]['requests'] += (int) $row->requests;
        }

        krsort($out);

        return array_values($out);
    }
}
