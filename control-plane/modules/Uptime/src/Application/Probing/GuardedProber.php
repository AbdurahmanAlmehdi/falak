<?php

namespace Falak\Uptime\Application\Probing;

use Carbon\CarbonImmutable;
use Falak\Uptime\Domain\Enums\ErrorKind;
use Falak\Uptime\Domain\Enums\MonitorType;
use Falak\Uptime\Domain\Probing\AddressGuard;
use Falak\Uptime\Domain\Probing\CertificateInspector;
use Falak\Uptime\Domain\Probing\ExpectedStatus;
use Falak\Uptime\Domain\Probing\ProbeResult;
use Falak\Uptime\Domain\Probing\ProbeTarget;
use Falak\Uptime\Domain\Probing\TlsReport;
use InvalidArgumentException;

/**
 * Probes through the SSRF guard: every host (the target and each redirect hop) is resolved once here, every address
 * it resolves to must pass {@see AddressGuard}, and the transport connects to that pinned address only, so a DNS
 * answer that changes in between (rebinding) cannot reach another address.
 */
final class GuardedProber implements Prober
{
    public function __construct(
        private readonly HostResolver $resolver,
        private readonly Transport $transport,
        private readonly AddressGuard $guard,
        private readonly CertificateInspector $inspector,
        private readonly ?string $caFile = null,
    ) {}

    public function probe(ProbeTarget $target): ProbeResult
    {
        return match ($target->type) {
            MonitorType::Http => $this->http($target),
            MonitorType::Tcp => $this->tcp($target),
            MonitorType::Tls => $this->tls($target),
        };
    }

    private function http(ProbeTarget $target): ProbeResult
    {
        try {
            $expected = ExpectedStatus::parse($target->expectedStatus);
        } catch (InvalidArgumentException $e) {
            return ProbeResult::failure(ErrorKind::Http, $e->getMessage());
        }

        $url = (string) $target->url;
        $redirects = 0;
        $elapsed = 0;
        $deadline = hrtime(true) + $target->timeoutSeconds * 1_000_000_000;

        while (true) {
            // The timeout bounds the whole attempt, every redirect hop included.
            $remaining = (int) ceil(($deadline - hrtime(true)) / 1e9);

            if ($remaining <= 0) {
                return ProbeResult::failure(ErrorKind::Timeout, "No final answer within {$target->timeoutSeconds} s", ['redirects' => $redirects, 'totalMs' => $elapsed]);
            }

            $parts = self::parts($url);

            if ($parts === null) {
                return ProbeResult::failure($redirects > 0 ? ErrorKind::Redirects : ErrorKind::Http, "Invalid URL: {$url}", ['redirects' => $redirects]);
            }

            [$scheme, $host, $port] = $parts;
            $resolved = $this->resolve($host);

            if ($resolved instanceof ProbeResult) {
                return $redirects > 0 ? ProbeResult::failure($resolved->errorKind ?? ErrorKind::Redirects, "Redirect to {$host}: {$resolved->error}", ['redirects' => $redirects]) : $resolved;
            }

            [$ip, $dnsMs] = $resolved;
            $exchange = $this->transport->http($url, $ip, $remaining, $target->bodyBytes);
            $elapsed += $dnsMs + ($exchange->totalMs ?? 0);
            $timings = [
                'ip' => $ip,
                'dnsMs' => $dnsMs,
                'connectMs' => $exchange->connectMs,
                'tlsMs' => $exchange->tlsMs,
                'ttfbMs' => $exchange->ttfbMs,
                'totalMs' => $elapsed,
                'redirects' => $redirects,
            ];

            if ($exchange->failed()) {
                return ProbeResult::failure($exchange->errorKind ?? ErrorKind::Http, (string) $exchange->error, $timings);
            }

            $status = (int) $exchange->status;

            if ($status >= 300 && $status < 400 && $exchange->location !== null && $exchange->location !== '' && $target->maxRedirects > 0) {
                if ($redirects >= $target->maxRedirects) {
                    return ProbeResult::failure(ErrorKind::Redirects, "More than {$target->maxRedirects} redirects", ['statusCode' => $status, ...$timings]);
                }

                $url = self::follow($url, $exchange->location);
                $redirects++;

                continue;
            }

            if (! $expected->matches($status)) {
                return ProbeResult::failure(ErrorKind::Status, "HTTP {$status} (expected {$expected->toString()})", ['statusCode' => $status, ...$timings]);
            }

            if ($target->keyword !== null && $target->keyword !== '') {
                $found = stripos($exchange->body, $target->keyword) !== false;

                if ($found === $target->keywordAbsent) {
                    $message = $target->keywordAbsent
                        ? "\"{$target->keyword}\" found in the response"
                        : "\"{$target->keyword}\" not found in the first ".number_format($target->bodyBytes).' bytes';

                    return ProbeResult::failure(ErrorKind::Keyword, $message, ['statusCode' => $status, ...$timings]);
                }
            }

            $result = new ProbeResult(true, null, null, $status, ...$timings);

            return $target->inspectTls && $scheme === 'https' ? $result->withTls($this->inspect($ip, $port, $host, $target->timeoutSeconds)) : $result;
        }
    }

    private function tcp(ProbeTarget $target): ProbeResult
    {
        $resolved = $this->resolve((string) $target->host);

        if ($resolved instanceof ProbeResult) {
            return $resolved;
        }

        [$ip, $dnsMs] = $resolved;
        $outcome = $this->transport->tcp($ip, (int) $target->port, $target->timeoutSeconds);
        $timings = ['ip' => $ip, 'dnsMs' => $dnsMs, 'connectMs' => $outcome->connectMs, 'totalMs' => $dnsMs + ($outcome->connectMs ?? 0)];

        return $outcome->ok
            ? new ProbeResult(true, ...$timings)
            : ProbeResult::failure($outcome->errorKind ?? ErrorKind::Connect, (string) $outcome->error, $timings);
    }

    private function tls(ProbeTarget $target): ProbeResult
    {
        $host = (string) $target->host;
        $resolved = $this->resolve($host);

        if ($resolved instanceof ProbeResult) {
            return $resolved;
        }

        [$ip, $dnsMs] = $resolved;
        $handshake = $this->transport->tls($ip, $target->port ?? 443, self::bare($host), $target->timeoutSeconds);
        $timings = [
            'ip' => $ip,
            'dnsMs' => $dnsMs,
            'connectMs' => $handshake->connectMs,
            'tlsMs' => $handshake->handshakeMs,
            'totalMs' => $dnsMs + ($handshake->connectMs ?? 0) + ($handshake->handshakeMs ?? 0),
        ];

        if (! $handshake->ok) {
            return ProbeResult::failure($handshake->errorKind ?? ErrorKind::Tls, (string) $handshake->error, $timings);
        }

        // The handshake works: the chain's problems are reported (alert, issue), the port is up.
        return (new ProbeResult(true, ...$timings))->withTls($this->inspector->inspect($handshake->chain, self::bare($host), $this->caFile, CarbonImmutable::now()->toDateTimeImmutable()));
    }

    private function inspect(string $ip, int $port, string $host, int $timeout): ?TlsReport
    {
        $handshake = $this->transport->tls($ip, $port, self::bare($host), $timeout);

        return $handshake->ok ? $this->inspector->inspect($handshake->chain, self::bare($host), $this->caFile, CarbonImmutable::now()->toDateTimeImmutable()) : null;
    }

    /**
     * @return array{0: string, 1: int}|ProbeResult the pinned address and the lookup time, or why the host is refused
     */
    private function resolve(string $host): array|ProbeResult
    {
        $host = self::bare($host);

        if ($host === '' || ! $this->guard->allowsHost($host)) {
            return ProbeResult::failure(ErrorKind::Blocked, "{$host} is not a public host");
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ips = [$host];
            $dnsMs = 0;
        } else {
            $started = hrtime(true);
            $ips = $this->resolver->resolve($host);
            $dnsMs = (int) round((hrtime(true) - $started) / 1e6);

            if ($ips === []) {
                return ProbeResult::failure(ErrorKind::Dns, "{$host} does not resolve", ['dnsMs' => $dnsMs]);
            }
        }

        $ip = $this->guard->pick($ips);

        if ($ip === null) {
            return ProbeResult::failure(ErrorKind::Blocked, "{$host} resolves to a private or reserved address", ['dnsMs' => $dnsMs]);
        }

        return [$ip, $dnsMs];
    }

    /**
     * @return array{0: string, 1: string, 2: int}|null scheme, host, port
     */
    public static function parts(string $url): ?array
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return [$scheme, strtolower($parts['host']), (int) $port];
    }

    /** A redirect target, absolute or relative to $base. */
    public static function follow(string $base, string $location): string
    {
        $location = trim($location);

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location)) {
            return $location;
        }

        $parts = parse_url($base) ?: [];
        $scheme = $parts['scheme'] ?? 'https';

        if (str_starts_with($location, '//')) {
            return "{$scheme}:{$location}";
        }

        $origin = $scheme.'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $path = $parts['path'] ?? '/';
        $directory = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $origin.($directory === '' ? '/' : $directory).$location;
    }

    private static function bare(string $host): string
    {
        return trim(strtolower($host), '[]');
    }
}
