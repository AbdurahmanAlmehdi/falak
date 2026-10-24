<?php

namespace Falak\Edge\Infrastructure\Dns;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;

/**
 * DNS-over-HTTPS JSON API (Cloudflare `https://cloudflare-dns.com/dns-query`, Google `https://dns.google/resolve`).
 * Queried fresh on every check, so a record the user just added shows up without waiting for a local cache.
 */
final class DohResolver implements DnsResolver
{
    private const TYPE_A = 1;

    private const TYPE_CNAME = 5;

    private const TYPE_AAAA = 28;

    private const TYPE_TXT = 16;

    public function __construct(
        private readonly Http $http,
        private readonly string $url,
        private readonly int $timeoutSeconds = 3,
    ) {}

    public function resolve(string $name): DnsAnswer
    {
        $cnames = [];
        $addresses = [self::TYPE_A => [], self::TYPE_AAAA => []];

        foreach ([self::TYPE_A => 'A', self::TYPE_AAAA => 'AAAA'] as $code => $type) {
            try {
                $response = $this->http->timeout($this->timeoutSeconds)->connectTimeout($this->timeoutSeconds)
                    ->withHeaders(['Accept' => 'application/dns-json'])
                    ->get($this->url, ['name' => $name, 'type' => $type]);
            } catch (ConnectionException $e) {
                throw new DnsLookupFailed("The DNS resolver did not answer: {$e->getMessage()}", previous: $e);
            }

            $body = $response->json();

            if (! $response->successful() || ! is_array($body) || ! isset($body['Status'])) {
                throw new DnsLookupFailed("The DNS resolver answered HTTP {$response->status()}.");
            }

            // 0 NOERROR, 3 NXDOMAIN (not found: an empty answer); anything else (SERVFAIL, REFUSED) is a failure.
            if (! in_array((int) $body['Status'], [0, 3], true)) {
                throw new DnsLookupFailed("The DNS lookup failed (rcode {$body['Status']}).");
            }

            foreach ((array) ($body['Answer'] ?? []) as $record) {
                $data = rtrim(strtolower((string) ($record['data'] ?? '')), '.');

                $recordType = (int) ($record['type'] ?? 0);

                if ($recordType === self::TYPE_CNAME && ! in_array($data, $cnames, true)) {
                    $cnames[] = $data;
                } elseif ($recordType === $code) {
                    $addresses[$code][] = $data;
                }
            }
        }

        return new DnsAnswer($cnames, array_values(array_unique($addresses[self::TYPE_A])), array_values(array_unique($addresses[self::TYPE_AAAA])));
    }

    public function txt(string $name): array
    {
        try {
            $response = $this->http->timeout($this->timeoutSeconds)->connectTimeout($this->timeoutSeconds)
                ->withHeaders(['Accept' => 'application/dns-json'])
                ->get($this->url, ['name' => $name, 'type' => 'TXT']);
        } catch (ConnectionException $e) {
            throw new DnsLookupFailed("The DNS resolver did not answer: {$e->getMessage()}", previous: $e);
        }

        $body = $response->json();

        if (! $response->successful() || ! is_array($body) || ! isset($body['Status'])) {
            throw new DnsLookupFailed("The DNS resolver answered HTTP {$response->status()}.");
        }

        if (! in_array((int) $body['Status'], [0, 3], true)) {
            throw new DnsLookupFailed("The DNS lookup failed (rcode {$body['Status']}).");
        }

        $values = [];
        foreach ((array) ($body['Answer'] ?? []) as $record) {
            if ((int) ($record['type'] ?? 0) === self::TYPE_TXT) {
                // DoH encodes TXT data as a quoted string: strip surrounding quotes.
                $values[] = trim((string) ($record['data'] ?? ''), '"');
            }
        }

        return $values;
    }
}
