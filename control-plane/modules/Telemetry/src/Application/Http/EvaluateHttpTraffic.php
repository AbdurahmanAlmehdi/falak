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
use Falak\Telemetry\Contracts\LogMetrics;
use Falak\Telemetry\Domain\Models\HttpSettings;
use Falak\Telemetry\Infrastructure\LokiAccessLogCounts;

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
     * @param  array<string, SiteData>  $sites
     */
    private function slowEndpoints(string $org, array $sites, string $selector, CarbonImmutable $now): void
    {
        $queries = HttpQueries::slowRoutes($selector);
        $p95 = $this->bySiteAnd('url_route', $this->instant($queries['p95'], $now));
        $counts = $this->bySiteAnd('url_route', $this->instant($queries['count'], $now));
        $max = (int) config('telemetry.http.max_slow_routes', 25);

        foreach ($sites as $slug => $site) {
            $settings = HttpSettings::for($org, $site->id);
            $slow = [];

            foreach ($p95[$slug] ?? [] as $route => $value) {
                $count = (int) ($counts[$slug][$route] ?? 0);

                if ($count >= $settings->slowMinRequests() && $value >= $settings->slowMs()) {
                    $slow[$route] = ['p95' => $value, 'count' => $count];
                }
            }

            uasort($slow, fn (array $a, array $b) => $b['p95'] <=> $a['p95']);
            $slow = array_slice($slow, 0, $max, true);
            $keep = [];

            foreach ($slow as $route => $row) {
                $route = (string) $route;
                [$issue] = $this->performance->slowEndpoint($org, $site->id, $route, $row['p95'], $settings->slowMs(), $row['count'], $now);
                $key = "http.slow:{$site->id}:".substr(hash('sha256', $route), 0, 16);
                $keep[] = $key;
                $this->conditions->observe($org, $key, true, fn () => new AlertData(
                    $org, self::ALERT_SLOW, Severity::Warning,
                    "{$site->name}: {$route} is slow (p95 ".self::ms($row['p95']).')',
                    "The route's p95 over the last hour is above ".self::ms($settings->slowMs())." ({$row['count']} requests), measured at the edge.",
                    $issue->url, context: ['site_id' => $site->id, 'route' => $route, 'p95_ms' => round($row['p95'], 1)], action: 'Review the issue',
                ));
            }

            $this->performance->recoverSlowEndpoints($org, $site->id, array_map('strval', array_keys($slow)));
            $this->conditions->clearExcept($org, "http.slow:{$site->id}:", $keep);
        }
    }

    /**
     * @param  array<string, SiteData>  $sites
     */
    private function window(string $org, array $sites, string $selector, CarbonImmutable $now): void
    {
        $q = HttpQueries::window($selector);
        $threats = $this->bySiteAnd('falak_threat_rule', $this->instant($q['threats'], $now));
        $clients = [];

        foreach ($this->instant($q['threat_clients'], $now) as $series) {
            $l = $series->labels;
            $clients[$l['service_name'] ?? ''][$l['falak_threat_rule'] ?? ''][$l['client_address'] ?? ''] = (int) $series->last();
        }

        $notFound = $this->bySiteAnd('client_address', $this->instant($q['not_found'], $now));
        $requests = $this->bySite($this->instant($q['requests'], $now));
        $errors = $this->bySite($this->instant($q['errors'], $now));
        $minHits = (int) config('telemetry.http.security_min_hits', 20);
        $storm = (int) config('telemetry.http.storm_404', 100);
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
                        ['window_minutes' => 5, 'hits' => (int) $count, 'clients' => array_slice($top, 0, 5, true)]);
                }
            }

            $stormers = array_filter($notFound[$slug] ?? [], fn (float $n) => $n >= $storm);

            if ($stormers !== []) {
                arsort($stormers);
                $this->security->scanner($org, $site->id, '404_storm', self::RULE_TITLES['404_storm'], (int) array_sum($stormers), $now,
                    ['window_minutes' => 5, 'clients' => array_map('intval', array_slice($stormers, 0, 5, true))]);
            }

            $hits = (int) array_sum($threats[$slug] ?? []);
            $this->conditions->observe($org, "http.scanner:{$site->id}", $hits >= $surge, fn () => new AlertData(
                $org, self::ALERT_SCANNER, Severity::Warning,
                "{$site->name}: {$hits} probe and attack requests in 5 minutes",
                'Scanners are hitting the site (probes for .env, /wp-admin, injections). The HTTP tab lists the clients; block them if they persist.',
                $url, context: [...$context, 'hits' => $hits], action: 'Review threats',
            ));

            $total = (int) ($requests[$slug] ?? 0);
            $failed = (int) ($errors[$slug] ?? 0);
            $this->conditions->observe($org, "http.errors:{$site->id}", $failed >= $errorsMin && $total > 0 && $failed / $total >= $errorsRatio, fn () => new AlertData(
                $org, self::ALERT_ERRORS, Severity::Warning,
                "{$site->name}: ".round($failed * 100 / max(1, $total)).'% of requests fail with 5xx',
                "{$failed} of {$total} requests in the last 5 minutes answered with a server error.",
                $url, context: [...$context, 'errors' => $failed, 'requests' => $total], action: 'Review logs',
            ), forSeconds: (int) config('telemetry.http.errors_hold_seconds', 300));
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
