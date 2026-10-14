<?php

namespace Falak\Telemetry\Application\Queries;

use Falak\Telemetry\Contracts\PromQl;
use InvalidArgumentException;

/**
 * Builds LogQL from structured filters. The organization matcher is always present, so users can
 * never read another organization's streams; raw LogQL is intentionally not accepted.
 */
final class LogQueryBuilder
{
    /**
     * Where a site's records come from (the Logs tab's source filter, docs/plans/OBSERVABILITY.md phase 2), as
     * structured metadata filters: no Loki index label is added, so stream cardinality does not change. Output is what
     * containers, supervised programs and cron write to stdout / stderr; files are the host log files (records of agents
     * before falak.log.origin have none, hence the empty alternative); the others are falak.log.origin values.
     */
    public const ORIGINS = [
        'output' => ' | log_iostream=~"stdout|stderr"',
        'file' => ' | log_file_path!="" | falak_log_origin=~"file|"',
        'container_file' => ' | falak_log_origin="container_file"',
        'php_fpm' => ' | falak_log_origin="php_fpm"',
        'php_fpm_slow' => ' | falak_log_origin="php_fpm_slow"',
    ];

    /**
     * @param  array{server_id?: ?string, site_id?: ?string, service?: ?string, kind?: ?string, compose_service?: ?string, origin?: ?string, level?: ?string, search?: ?string, regex?: bool, trace_id?: ?string}  $filters
     */
    public static function build(string $organizationId, array $filters): string
    {
        $matchers = [PromQl::label('falak_org_id', strtoupper($organizationId))];

        foreach (['server_id' => 'falak_server_id', 'site_id' => 'falak_site_id', 'service' => 'service_name'] as $filter => $label) {
            if (($value = $filters[$filter] ?? null) !== null && $value !== '') {
                $matchers[] = PromQl::label($label, $filter === 'service' ? (string) $value : strtoupper((string) $value));
            }
        }

        // kind: "app" (the site's own output) or "access" (edge HTTP access log), see observability/README.md. App is
        // "not access", so records of agents that predate the label still match.
        $kind = $filters['kind'] ?? null;
        $origin = $filters['origin'] ?? null;

        // An origin narrows the app records: the edge's access records are log files too (log_file_path set), and must
        // never pass for the site's own.
        if ($origin !== null && $origin !== '') {
            if ($kind === 'access') {
                throw new InvalidArgumentException('An origin applies to app records only.');
            }

            $kind = 'app';
        }

        if ($kind !== null && $kind !== '') {
            $matchers[] = $kind === 'access' ? PromQl::label('falak_log_kind', 'access') : 'falak_log_kind!='.PromQl::quote('access');
        }

        $query = '{'.implode(', ', $matchers).'}';

        // Compose sites: container logs carry the compose service as structured metadata (falak.compose.service).
        if (($composeService = $filters['compose_service'] ?? null) !== null && $composeService !== '') {
            $query .= ' | falak_compose_service='.PromQl::quote((string) $composeService);
        }

        if ($origin !== null && $origin !== '') {
            $query .= self::ORIGINS[$origin] ?? throw new InvalidArgumentException('Unknown log origin.');
        }

        if (($search = $filters['search'] ?? null) !== null && $search !== '') {
            if (! empty($filters['regex'])) {
                if (@preg_match('~'.str_replace('~', '\~', $search).'~', '') === false) {
                    throw new InvalidArgumentException('The search pattern is not a valid regular expression.');
                }

                $query .= ' |~ '.PromQl::quote($search);
            } else {
                $query .= ' |= '.PromQl::quote($search);
            }
        }

        if (($traceId = $filters['trace_id'] ?? null) !== null && $traceId !== '') {
            $query .= ' | trace_id='.PromQl::quote(strtolower($traceId));
        }

        if (($level = $filters['level'] ?? null) !== null && $level !== '') {
            $pattern = '(?i)'.preg_quote(strtolower($level), '/').'.*';
            $query .= ' | severity_text=~'.PromQl::quote($pattern).' or detected_level=~'.PromQl::quote($pattern);
        }

        return $query;
    }

    /**
     * A site's edge access log. Selected by slug (`service_name`), not site id: load balancers route sites that are
     * not deployed on them, and their agents only know the slug of such a site.
     *
     * @param  array{server_id?: ?string, deployment_id?: ?string, release_id?: ?string, method?: ?string, status?: int|string|null, path?: ?string, client_ip?: ?string}  $filters
     */
    public static function access(string $organizationId, string $siteSlug, array $filters): string
    {
        $matchers = [
            PromQl::label('falak_org_id', strtoupper($organizationId)),
            PromQl::label('service_name', $siteSlug),
            PromQl::label('falak_log_kind', 'access'),
        ];

        if (($server = $filters['server_id'] ?? null) !== null && $server !== '') {
            $matchers[] = PromQl::label('falak_server_id', strtoupper((string) $server));
        }

        $query = '{'.implode(', ', $matchers).'}';

        if (($path = $filters['path'] ?? null) !== null && $path !== '') {
            $query .= ' |= '.PromQl::quote((string) $path);
        }

        if (($deployment = $filters['deployment_id'] ?? null) !== null && $deployment !== '') {
            $query .= ' | falak_deployment_id='.PromQl::quote(strtoupper((string) $deployment));
        }

        if (($release = $filters['release_id'] ?? null) !== null && $release !== '') {
            $query .= ' | falak_release_id='.PromQl::quote(strtoupper((string) $release));
        }

        if (($method = $filters['method'] ?? null) !== null && $method !== '') {
            $query .= ' | http_request_method='.PromQl::quote(strtoupper((string) $method));
        }

        $status = $filters['status'] ?? null;

        if (is_int($status) || (is_string($status) && preg_match('/^[1-5]\d\d$/', $status) === 1)) {
            $query .= ' | http_response_status_code='.PromQl::quote((string) $status);
        } elseif (is_string($status) && preg_match('/^([1-5])xx$/i', $status, $m) === 1) {
            $query .= ' | http_response_status_code=~'.PromQl::quote($m[1].'..');
        } elseif ($status !== null && $status !== '') {
            throw new InvalidArgumentException('status must be a code (e.g. 404) or a class (e.g. 5xx).');
        }

        if (($ip = $filters['client_ip'] ?? null) !== null && $ip !== '') {
            $query .= ' | client_address='.PromQl::quote((string) $ip);
        }

        return $query;
    }
}
