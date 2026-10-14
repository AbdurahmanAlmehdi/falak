<?php

namespace Falak\Uptime\Tests\Support;

use Falak\Alerting\Contracts\Alerts;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Edge\Contracts\DomainRecords;
use Falak\Uptime\Application\Probing\ConnectOutcome;
use Falak\Uptime\Application\Probing\HostResolver;
use Falak\Uptime\Application\Probing\HttpExchange;
use Falak\Uptime\Application\Probing\Prober;
use Falak\Uptime\Application\Probing\TlsHandshake;
use Falak\Uptime\Application\Probing\Transport;
use Falak\Uptime\Domain\Probing\ProbeResult;
use Falak\Uptime\Domain\Probing\ProbeTarget;

/** Resolves from a map; records every lookup. A list of answers per host is consumed in order (rebinding). */
final class FakeResolver implements HostResolver
{
    /** @var list<string> */
    public array $lookups = [];

    /**
     * @param  array<string, list<string>|list<list<string>>>  $answers
     */
    public function __construct(public array $answers = []) {}

    public function resolve(string $host): array
    {
        $this->lookups[] = $host;
        $answer = $this->answers[$host] ?? [];

        if ($answer !== [] && is_array($answer[0])) {
            $next = array_shift($this->answers[$host]);

            return $next;
        }

        return $answer;
    }
}

/** Answers HTTP by URL, records the address each request was pinned to. */
final class FakeTransport implements Transport
{
    /** @var list<array{url: string, ip: string}> */
    public array $requests = [];

    /** @var list<array{ip: string, port: int, sni: string}> */
    public array $handshakes = [];

    /**
     * @param  array<string, HttpExchange>  $http
     * @param  list<string>  $chain
     */
    public function __construct(
        public array $http = [],
        public ?ConnectOutcome $tcp = null,
        public array $chain = [],
        public ?TlsHandshake $tls = null,
    ) {}

    public function http(string $url, string $ip, int $timeoutSeconds, int $bodyBytes): HttpExchange
    {
        $this->requests[] = ['url' => $url, 'ip' => $ip];

        return $this->http[$url] ?? new HttpExchange(200, null, 'ok', 5, 10, 40, 50);
    }

    public function tcp(string $ip, int $port, int $timeoutSeconds): ConnectOutcome
    {
        return $this->tcp ?? new ConnectOutcome(true, 7);
    }

    public function tls(string $ip, int $port, string $serverName, int $timeoutSeconds): TlsHandshake
    {
        $this->handshakes[] = ['ip' => $ip, 'port' => $port, 'sni' => $serverName];

        return $this->tls ?? new TlsHandshake(true, 5, 12, $this->chain);
    }
}

/** Returns queued results (the last one repeats); records targets. */
final class FakeProber implements Prober
{
    /** @var list<ProbeTarget> */
    public array $targets = [];

    /**
     * @param  list<ProbeResult>  $results
     */
    public function __construct(public array $results = []) {}

    public function queue(ProbeResult ...$results): self
    {
        array_push($this->results, ...$results);

        return $this;
    }

    public function probe(ProbeTarget $target): ProbeResult
    {
        $this->targets[] = $target;

        return count($this->results) > 1 ? array_shift($this->results) : ($this->results[0] ?? new ProbeResult(true, totalMs: 100));
    }
}

final class RecordingAlerts implements Alerts
{
    /** @var list<AlertData> */
    public array $raised = [];

    public function raise(AlertData $alert): void
    {
        $this->raised[] = $alert;
    }

    /**
     * @return list<AlertData>
     */
    public function ofType(string $type): array
    {
        return array_values(array_filter($this->raised, fn (AlertData $alert) => $alert->type === $type));
    }
}

final class FakeDomainRecords implements DomainRecords
{
    /**
     * @param  list<array{site_id: string, name: string}>  $domains
     */
    public function __construct(public array $domains = []) {}

    public function forSites(array $siteIds): array
    {
        return array_values(array_map(
            fn (array $domain) => [...$domain, 'managed' => false, 'status' => null],
            array_filter($this->domains, fn (array $domain) => in_array($domain['site_id'], $siteIds, true)),
        ));
    }
}
