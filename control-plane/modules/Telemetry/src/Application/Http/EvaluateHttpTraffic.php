<?php

namespace Falak\Telemetry\Application\Http;

use Carbon\CarbonImmutable;
use Falak\Alerting\Contracts\AlertConditions;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Falak\Insights\Contracts\PerformanceFindings;
use Falak\Insights\Contracts\SecurityFindings;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Telemetry\Application\Queries\HttpQueries;
use Falak\Telemetry\Contracts\Data\MetricSeries;
use Falak\Telemetry\Contracts\Exceptions\TelemetryQueryFailed;
use Falak\Telemetry\Contracts\Exceptions\TelemetryUnavailable;
use Falak\Telemetry\Contracts\LogMetrics;
use Falak\Telemetry\Domain\Models\HttpSettings;
use Falak\Telemetry\Infrastructure\LokiAccessLogCounts;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Decisions from one organization's edge access logs, every 5 minutes (docs/plans/network/http.md): slow endpoints →
 * performance Issues (resolved when no longer slow), probes and 404 storms → security Issues, and the HTTP alerts
 * (errors spike, slow endpoint, scanner surge). Loki errors propagate: nothing is resolved on a failed read.
 */
final class EvaluateHttpTraffic
{
    public const ALERT_ERRORS = 'http.errors_spike';

    public const ALERT_SLOW = 'http.slow_endpoint';

    public const ALERT_SCANNER = 'http.scanner_surge';

    public const ALERT_BANDWIDTH = 'http.bandwidth';

    /** Client IPs kept in a security Issue's sample (the latest window only: each occurrence replaces it). */
    public const SAMPLE_CLIENTS = 5;

    /** Titles of the security Issues per threat rule. */
    public const RULE_TITLES = [
        'env' => 'Scanner probing for .env files',
        'git' => 'Scanner probing for version control folders (/.git)',
        'wordpress' => 'WordPress probes (/wp-login.php, /wp-admin, /xmlrpc.php)',
        'phpmyadmin' => 'Database admin probes (/phpmyadmin, /adminer)',
        'phpunit' => 'PHPUnit remote code execution probes (/vendor/phpunit)',
        'cgi' => 'CGI probes (/cgi-bin)',
        'config' => 'Probes for configuration and backup files',
        'traversal' => 'Path traversal attempts (../)',
        'sqli' => 'SQL injection attempts',
        'xss' => 'Cross-site scripting attempts',
        'method' => 'Unusual HTTP methods (TRACE, PROPFIND, …)',
        '404_storm' => '404 storm from single clients',
    ];

    public function __construct(
        private readonly LogMetrics $logs,
        private readonly SiteDirectory $sites,
        private readonly PerformanceFindings $performance,
        private readonly SecurityFindings $security,
        private readonly AlertConditions $conditions,
    ) {}

    public function __invoke(string $organizationId, ?CarbonImmutable $now = null): void
    {
        $now = ($now ?? CarbonImmutable::now())->utc()->startOfMinute();
        /** @var array<string, SiteData> $sites slug => site */
        $sites = collect($this->sites->forOrganization($organizationId))->keyBy(fn (SiteData $s) => $s->slug)->all();

        if ($sites === []) {
            return;
        }

        $selector = HttpQueries::organizationAccess($organizationId, LokiAccessLogCounts::PROBE_AGENT);
        $this->slowEndpoints($organizationId, $sites, $selector, $now);
        $this->window($organizationId, $sites, $selector, $now);
    }

    /**
     * Per site and route, from the last hour: slow (p95 at the site's threshold, with at least its minimum of requests)
     * records an occurrence; recovered (the minimum of requests, p95 under recover_ratio of the threshold) resolves the
     * Issue; anything else (a quiet route, a p95 between the two bounds, an answer Loki cut) leaves the state as it is,
     * so an Issue never flaps because its route had a quiet hour.
     *
     * @param  array<string, SiteData>  $sites
     */
    private function slowEndpoints(string $org, array $sites, string $selector, CarbonImmutable $now): void
    {
        $ratio = (float) config('telemetry.http.recover_ratio', 0.8);
        $limit = (int) config('telemetry.http.max_slow_series', 1000);
        $settings = [];

        foreach ($sites as $slug => $site) {
            $settings[$slug] = HttpSettings::for($org, $site->id);
        }

        $minP95 = min(array_map(fn (HttpSettings $s) => $s->slowMs() * $ratio, $settings));
        $minCount = min(array_map(fn (HttpSettings $s) => $s->slowMinRequests(), $settings));
        $queries = HttpQueries::slowRoutes($selector, $minP95, $minCount, $limit);
        $p95Series = $this->attempt($queries['p95'], $now);
        $countSeries = $this->attempt($queries['count'], $now);

        if ($p95Series === null || $countSeries === null) {
            return; // nothing is decided on a failed read
        }

        // A cut p95 answer: a counted route missing from it may be slow, so it is not taken as recovered.
        $p95Complete = count($p95Series) < $limit;
        $p95 = $this->bySiteAnd('url_route', $p95Series);
        $counts = $this->bySiteAnd('url_route', $countSeries);
        $max = (int) config('telemetry.http.max_slow_routes', 25);

        foreach ($sites as $slug => $site) {
            $threshold = $settings[$slug]->slowMs();
            $slow = $recovered = [];

            foreach ($counts[$slug] ?? [] as $route => $count) {
                $route = (string) $route;

                if ($count < $settings[$slug]->slowMinRequests()) {
                    continue; // quiet: keep the state
                }

                $value = $p95[$slug][$route] ?? null;

                if ($value !== null && $value >= $threshold) {
                    $slow[$route] = ['p95' => $value, 'count' => (int) $count];
                } elseif ($value !== null ? $value < $threshold * $ratio : $p95Complete) {
                    $recovered[] = $route;
                }
            }

            uasort($slow, fn (array $a, array $b) => $b['p95'] <=> $a['p95']);

            foreach (array_slice($slow, 0, $max, true) as $route => $row) {
                $route = (string) $route;
                [$issue] = $this->performance->slowEndpoint($org, $site->id, $route, $row['p95'], $threshold, $row['count'], $now);
                $this->conditions->observe($org, self::slowKey($site->id, $route), true, fn () => new AlertData(
                    $org, self::ALERT_SLOW, Severity::Warning,
                    "{$site->name}: {$route} is slow (p95 ".self::ms($row['p95']).')',
                    "The route's p95 over the last hour is above ".self::ms($threshold)." ({$row['count']} requests), measured at the edge.",
                    $issue->url, context: ['site_id' => $site->id, 'route' => $route, 'p95_ms' => round($row['p95'], 1)], action: 'Review the issue',
                ));
            }

            if ($recovered !== []) {
                $this->performance->recoverSlowEndpoints($org, $site->id, $recovered);

                foreach ($recovered as $route) {
                    $this->conditions->observe($org, self::slowKey($site->id, $route), false, fn () => throw new LogicException('never raised'));
                }
            }
        }
    }

    private static function slowKey(string $siteId, string $route): string
    {
        return "http.slow:{$siteId}:".substr(hash('sha256', $route), 0, 16);
    }

    /**
     * The 5-minute window. Each query stands alone: one Loki refuses leaves the decisions of the others standing, and
     * the decisions it feeds are skipped (an alert condition is then neither raised nor cleared).
     *
     * @param  array<string, SiteData>  $sites
     */
    private function window(string $org, array $sites, string $selector, CarbonImmutable $now): void
    {
        $storm = (int) config('telemetry.http.storm_404', 100);
        $q = HttpQueries::window($selector, $storm);
        $threatSeries = $this->attempt($q['threats'], $now);
        $threats = $threatSeries !== null ? $this->bySiteAnd('falak_threat_rule', $threatSeries) : null;
        $clients = [];

        foreach ($this->attempt($q['threat_clients'], $now) ?? [] as $series) {
            $l = $series->labels;
            $clients[$l['service_name'] ?? ''][$l['falak_threat_rule'] ?? ''][$l['client_address'] ?? ''] = (int) $series->last();
        }

        $notFoundSeries = $this->attempt($q['not_found'], $now);
        $notFound = $notFoundSeries !== null ? $this->bySiteAnd('client_address', $notFoundSeries) : [];
        $requestSeries = $this->attempt($q['requests'], $now);
        $errorSeries = $this->attempt($q['errors'], $now);
        $requests = $requestSeries !== null ? $this->bySite($requestSeries) : null;
        $errors = $errorSeries !== null ? $this->bySite($errorSeries) : null;
        $minHits = (int) config('telemetry.http.security_min_hits', 20);
        $surge = (int) config('telemetry.http.scanner_surge', 300);
        $errorsMin = (int) config('telemetry.http.errors_min', 20);
        $errorsRatio = (float) config('telemetry.http.errors_ratio', 0.10);

        foreach ($sites as $slug => $site) {
            $url = "/sites/{$site->id}";
            $context = ['site_id' => $site->id];

            foreach ($threats[$slug] ?? [] as $rule => $count) {
                $rule = (string) $rule;

                if ($count >= $minHits && isset(self::RULE_TITLES[$rule])) {
                    $top = $clients[$slug][$rule] ?? [];
                    arsort($top);
                    $this->security->scanner($org, $site->id, $rule, self::RULE_TITLES[$rule], (int) $count, $now,
                        ['window_minutes' => 5, 'hits' => (int) $count, 'clients' => array_slice($top, 0, self::SAMPLE_CLIENTS, true)]);
                }
            }

            $stormers = array_filter($notFound[$slug] ?? [], fn (float $n) => $n >= $storm);

            if ($stormers !== []) {
                arsort($stormers);
                $this->security->scanner($org, $site->id, '404_storm', self::RULE_TITLES['404_storm'], (int) array_sum($stormers), $now,
                    ['window_minutes' => 5, 'clients' => array_map('intval', array_slice($stormers, 0, self::SAMPLE_CLIENTS, true))]);
            }

            $hits = (int) array_sum($threats[$slug] ?? []);
            $this->conditions->observe($org, "http.scanner:{$site->id}", $threats === null ? null : $hits >= $surge, fn () => new AlertData(
                $org, self::ALERT_SCANNER, Severity::Warning,
                "{$site->name}: {$hits} probe and attack requests in 5 minutes",
                'Scanners are hitting the site (probes for .env, /wp-admin, injections). The HTTP tab lists the clients; block them if they persist.',
                $url, context: [...$context, 'hits' => $hits], action: 'Review threats',
            ));

            $total = (int) ($requests[$slug] ?? 0);
            $failed = (int) ($errors[$slug] ?? 0);
            $spike = $requests === null || $errors === null ? null : $failed >= $errorsMin && $total > 0 && $failed / $total >= $errorsRatio;
            $this->conditions->observe($org, "http.errors:{$site->id}", $spike, fn () => new AlertData(
                $org, self::ALERT_ERRORS, Severity::Warning,
                "{$site->name}: ".round($failed * 100 / max(1, $total)).'% of requests fail with 5xx',
                "{$failed} of {$total} requests in the last 5 minutes answered with a server error.",
                $url, context: [...$context, 'errors' => $failed, 'requests' => $total], action: 'Review logs',
            ), forSeconds: (int) config('telemetry.http.errors_hold_seconds', 300));
        }
    }

    /**
     * One instant query, or null when Loki refused it (logged).
     *
     * @return list<MetricSeries>|null
     */
    private function attempt(string $logql, CarbonImmutable $at): ?array
    {
        try {
            return $this->instant($logql, $at);
        } catch (TelemetryQueryFailed|TelemetryUnavailable $e) {
            Log::warning("HTTP analytics query failed: {$e->getMessage()}", ['query' => mb_substr($logql, 0, 300)]);

            return null;
        }
    }

    /**
     * @return list<MetricSeries>
     */
    private function instant(string $logql, CarbonImmutable $at): array
    {
        return $this->logs->queryRange($logql, $at, $at, 60);
    }

    /**
     * @param  list<MetricSeries>  $series
     * @return array<string, float>
     */
    private function bySite(array $series): array
    {
        $out = [];

        foreach ($series as $s) {
            $out[$s->labels['service_name'] ?? ''] = ($out[$s->labels['service_name'] ?? ''] ?? 0) + (float) $s->last();
        }

        return $out;
    }

    /**
     * @param  list<MetricSeries>  $series
     * @return array<string, array<string, float>>
     */
    private function bySiteAnd(string $label, array $series): array
    {
        $out = [];

        foreach ($series as $s) {
            $value = $s->last();

            if ($value !== null && isset($s->labels['service_name'], $s->labels[$label])) {
                $out[$s->labels['service_name']][$s->labels[$label]] = (float) $value;
            }
        }

        return $out;
    }

    private static function ms(float|int $ms): string
    {
        return $ms >= 1000 ? round($ms / 1000, 2).' s' : round($ms).' ms';
    }
}
