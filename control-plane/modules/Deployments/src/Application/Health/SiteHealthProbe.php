<?php

namespace Falak\Deployments\Application\Health;

use Falak\Edge\Contracts\Data\DomainData;
use Falak\Edge\Contracts\EdgeRoutes;
use Falak\Edge\Contracts\TlsMode;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\SiteRuntime;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * One HTTP health check of a site on one server from the control plane: GET https://<primary domain><path>
 * resolved to the server's address (so the edge's certificate and routing are exercised), or
 * http://<ip><path> when the site has no domain. Used by deployments' health check steps and by the
 * watch window after a release goes live.
 *
 * When no HTTP answer comes back through the primary domain (TLS handshake or connection error), the
 * site's other domains are tried, then plain HTTP: a server of a multi-server site may only hold a
 * certificate for the name that points at it (per-server DNS), and load-balancer backends serve plain
 * HTTP. When none of them answers on the server's public address, they are tried again on its private
 * address: a provider firewall may keep the web ports closed to the internet (a server behind a Cloudflare
 * Tunnel) while the control plane reaches the server over the private network (not in managed mode). The
 * first HTTP answer decides; an application error (e.g. 500) is never skipped over.
 */
final class SiteHealthProbe
{
    public const NO_ADDRESS = 'The server has no IP address to check.';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly SiteDirectory $sites,
        private readonly ServerDirectory $servers,
        private readonly EdgeRoutes $edge,
    ) {}

    /**
     * @param  array<string, mixed>  $health  the deployment's health settings (path, status, timeout_s)
     * @return array{0: bool, 1: string} healthy, what was checked
     */
    public function probe(string $siteId, string $serverId, array $health): array
    {
        $path = (string) ($health['path'] ?? '/');
        $expect = (int) ($health['status'] ?? 200);
        $timeout = max(1, (int) ($health['timeout_s'] ?? 10));

        $server = $this->servers->find($serverId);
        // Address => how messages name it; the public address first.
        $addresses = [];

        if ($server?->ipv4 !== null) {
            $addresses[$server->ipv4] = $server->ipv4;
        }

        // Managed (multi-tenant): a custom server's private address is what its agent reports, so it is no fallback
        // there; the control plane would probe an address a tenant picked (its own network) and report what answered.
        if ($server?->privateIpv4 !== null && ($addresses === [] || ! config('controlplane.managed'))) {
            $addresses[$server->privateIpv4] ??= "{$server->privateIpv4} (private)";
        }

        if ($addresses === []) {
            return [false, self::NO_ADDRESS];
        }

        $site = $this->sites->find($siteId);
        $checks = [];

        // A Docker site without a domain has no edge route (a compose service split out into its own site that only its
        // stack reaches): the swap already checked the container on the server.
        if ($site?->runtime === SiteRuntime::Docker && $site->testDomain === null && $this->edge->domainsFor($site->id) === []) {
            return [true, 'No domain to check through the edge; the container passed its health check on the server.'];
        }

        if ($site?->compose !== null) {
            // Compose: every public service through the edge; the primary answers the configured check.
            $publicServices = $site->compose->publicServices;

            if ($publicServices === []) {
                return [true, 'No public services to check; every container passed `docker compose up --wait`.'];
            }

            foreach ($publicServices as $i => $public) {
                if ($i === 0) {
                    $host = $this->candidates($siteId);
                    // Container health is verified by `up --wait`; through the edge a redirect (e.g. to a login page)
                    // also proves the route works unless a specific status is configured.
                    $checks[] = $expect === 200
                        ? [$host, $public->healthCheckPath ?? $path, fn (int $status) => $status >= 200 && $status < 400, 'expected 2xx/3xx', $public->service]
                        : [$host, $public->healthCheckPath ?? $path, fn (int $status) => $status === $expect, "expected {$expect}", $public->service];

                    continue;
                }

                // The service's own domains (edge_domains rows for it, primary first), then its test domain.
                $host = $this->serviceCandidates($siteId, $public->service, $public->testDomain, $public->domain);

                if ($host === []) {
                    continue; // not routed
                }

                // A configured path must answer 2xx/3xx; without one any answer below 500 proves the route works.
                $checks[] = $public->healthCheckPath !== null
                    ? [$host, $public->healthCheckPath, fn (int $status) => $status >= 200 && $status < 400, 'expected 2xx/3xx', $public->service]
                    : [$host, '/', fn (int $status) => $status < 500, 'expected < 500', $public->service];
            }
        } else {
            $checks[] = [$this->candidates($siteId), $path, fn (int $status) => $status === $expect, "expected {$expect}", null];
        }

        $healthy = true;
        $messages = [];

        foreach ($checks as [$candidates, $checkPath, $accepts, $expectation, $service]) {
            [$ok, $message] = $this->checkAny($addresses, $candidates, $checkPath, $timeout, $accepts, $expectation);
            $healthy = $healthy && $ok;
            $messages[] = ($service !== null && count($checks) > 1 ? "[{$service}] " : '').$message;
        }

        return [$healthy, implode('; ', $messages)];
    }

    /**
     * Tries the candidates in order on each address in turn until one gets an HTTP answer (see the class docblock).
     *
     * @param  array<string, string>  $addresses  server address => how messages name it
     * @param  list<array{0: ?string, 1: TlsMode}>  $candidates  host (null = the server IP) and its TLS mode
     * @param  callable(int): bool  $accepts
     * @return array{0: bool, 1: string}
     */
    private function checkAny(array $addresses, array $candidates, string $path, int $timeout, callable $accepts, string $expectation): array
    {
        $failures = [];

        foreach ($addresses as $ip => $via) {
            foreach ($candidates as [$host, $tls]) {
                [$ok, $message, $answered] = $this->check((string) $ip, $via, $host, $tls, $path, $timeout, $accepts, $expectation);

                if ($answered) {
                    return [$ok, $failures === [] ? $message : $message.' — after: '.implode('; ', $failures)];
                }

                $failures[] = $message;
            }
        }

        return [false, implode('; ', $failures)];
    }

    /**
     * @param  callable(int): bool  $accepts
     * @return array{0: bool, 1: string, 2: bool} healthy, message, whether an HTTP answer came back
     */
    private function check(string $ip, string $via, ?string $host, TlsMode $tls, string $path, int $timeout, callable $accepts, string $expectation): array
    {
        $https = $host !== null && $tls !== TlsMode::Off;
        $url = $host === null ? "http://{$ip}{$path}" : ($https ? 'https' : 'http')."://{$host}{$path}";
        $options = $host === null ? [] : [
            'curl' => [CURLOPT_RESOLVE => ["{$host}:".($https ? 443 : 80).":{$ip}"]],
            // Liveness through the edge, not certificate validation: only publicly trusted certificates
            // (ACME) are verifiable from here; internal-CA and uploaded ones may chain to a private root.
            'verify' => $tls->publiclyTrusted(),
        ];

        $started = microtime(true);

        try {
            $response = $this->http->timeout($timeout)->connectTimeout(min(5, $timeout))->withoutRedirecting()
                ->withHeaders(['User-Agent' => 'Falak-HealthCheck/1'])
                ->withOptions($options)
                ->get($url);
            $status = $response->status();

            return [$accepts($status), sprintf('GET %s via %s → %d in %d ms (%s)', $url, $via, $status, (int) ((microtime(true) - $started) * 1000), $expectation), true];
        } catch (Throwable $e) {
            return [false, sprintf('GET %s via %s failed: %s', $url, $via, $e->getMessage()), false];
        }
    }

    /**
     * What to check a compose site's non-primary public service through: its domains (primary first), then its
     * test domain. $legacyDomain (kept in `public_services` but not a domain row yet) when it has no rows.
     *
     * @return list<array{0: ?string, 1: TlsMode}>
     */
    private function serviceCandidates(string $siteId, string $service, ?string $testDomain, ?string $legacyDomain): array
    {
        try {
            $domains = array_values(array_filter($this->edge->domainsFor($siteId, $service), fn (DomainData $d) => ! $d->isWildcard()));
        } catch (Throwable) {
            $domains = [];
        }

        usort($domains, fn (DomainData $a, DomainData $b) => (int) $b->primary <=> (int) $a->primary);
        $candidates = array_map(fn (DomainData $d) => [$d->name, $d->tls], $domains);

        if ($candidates === [] && $legacyDomain !== null) {
            $candidates[] = [$legacyDomain, TlsMode::Auto];
        }

        if ($testDomain !== null) {
            $candidates[] = [$testDomain, $this->edge->testDomainTls()];
        }

        return $candidates;
    }

    /**
     * What to check the site through, in order: its domains (primary first), then the test domain, then plain
     * HTTP on the primary name when it was tried over HTTPS. `[[null, Off]]` (the server IP) without any name.
     *
     * @return list<array{0: ?string, 1: TlsMode}>
     */
    private function candidates(string $siteId): array
    {
        try {
            $domains = array_values(array_filter($this->edge->domainsFor($siteId), fn (DomainData $d) => ! $d->isWildcard()));
        } catch (Throwable) {
            $domains = [];
        }

        usort($domains, fn (DomainData $a, DomainData $b) => (int) $b->primary <=> (int) $a->primary);

        $candidates = array_map(fn (DomainData $d) => [$d->name, $d->tls], $domains);
        $test = $this->sites->find($siteId)?->testDomain;

        if ($test !== null) {
            $candidates[] = [$test, $this->edge->testDomainTls()];
        }

        if ($candidates === []) {
            return [[null, TlsMode::Off]];
        }

        if ($candidates[0][1] !== TlsMode::Off) {
            $candidates[] = [$candidates[0][0], TlsMode::Off];
        }

        return $candidates;
    }
}
