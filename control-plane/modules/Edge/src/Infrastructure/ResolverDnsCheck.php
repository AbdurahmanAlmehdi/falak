<?php

namespace Falak\Edge\Infrastructure;

use DateTimeImmutable;
use Falak\Edge\Application\DnsInstructions;
use Falak\Edge\Application\DnsTargets;
use Falak\Edge\Application\GeneratedDomains;
use Falak\Edge\Contracts\CloudflareRanges;
use Falak\Edge\Contracts\Data\DnsCheckResult;
use Falak\Edge\Contracts\Data\DnsTarget;
use Falak\Edge\Contracts\DnsCheck;
use Falak\Edge\Contracts\DnsStatus;
use Falak\Edge\Domain\Models\CloudflareZone;
use Falak\Edge\Domain\Models\Domain;
use Falak\Edge\Infrastructure\Dns\DnsLookupFailed;
use Falak\Edge\Infrastructure\Dns\DnsResolver;
use Falak\Edge\Infrastructure\Dns\TlsProbe;

final class ResolverDnsCheck implements DnsCheck
{
    public function __construct(
        private readonly DnsResolver $resolver,
        private readonly TlsProbe $tls,
        private readonly DnsTargets $targets,
        private readonly GeneratedDomains $generated,
    ) {}

    public function check(string $organizationId, string $name, array $serverIds, ?string $siteId = null, ?string $label = null, bool $probeTls = false): DnsCheckResult
    {
        $name = strtolower(rtrim(trim($name), '.'));
        $targets = $this->targets->for($organizationId, $serverIds, $siteId);
        $suffix = $this->generated->suffix($organizationId);
        $ipv4 = $targets[0]->ipv4 ?? null;
        $generatedName = $label !== null && $suffix !== null && $ipv4 !== null ? GeneratedDomains::name($label, $ipv4, $suffix) : null;
        $managedZone = CloudflareZone::forHost($organizationId, $name)?->name;
        $instructions = DnsInstructions::for($name, $targets, $generatedName !== $name ? $generatedName : null, $managedZone);
        $result = fn (DnsStatus $status, string $message, array $addresses = [], array $cnames = [], array $matched = [], ?array $certificate = null) => new DnsCheckResult(
            $name, $status, $message, $addresses, $cnames, $targets, $matched, $instructions, $certificate, new DateTimeImmutable,
        );

        if (preg_match(Domain::HOSTNAME, $name) !== 1 || str_starts_with($name, '*.') || strlen($name) > 253) {
            return $result(DnsStatus::Error, 'Enter a domain name like app.example.com.');
        }

        try {
            $answer = $this->resolver->resolve($name);
        } catch (DnsLookupFailed $e) {
            return $result(DnsStatus::Error, "Could not look up {$name} right now. {$e->getMessage()}");
        }

        $addresses = array_map(self::canonical(...), $answer->addresses());

        if ($addresses === []) {
            return $result(DnsStatus::Missing, $answer->cnames !== []
                ? 'Not found yet: the CNAME to '.$answer->cnames[count($answer->cnames) - 1].' does not resolve (DNS can take a few minutes).'
                : 'Not found yet (DNS can take a few minutes).', [], $answer->cnames);
        }

        $proxied = array_values(array_filter($addresses, CloudflareRanges::contains(...)));

        if ($proxied !== [] && $managedZone !== null) {
            return $result(DnsStatus::Ok, "Proxied by Cloudflare (orange cloud), with DNS managed by Falak in {$managedZone}.", $addresses, $answer->cnames);
        }

        if ($proxied !== []) {
            return $result(DnsStatus::Proxied, 'Proxied by Cloudflare (orange cloud): Let\'s Encrypt cannot reach the server over HTTP-01. Set the record to “DNS only”, or use DNS-01 TLS.', $addresses, $answer->cnames);
        }

        $expected = [];

        foreach ($targets as $target) {
            foreach ($target->addresses() as $address) {
                $expected[self::canonical($address)] = $target;
            }
        }

        if ($expected === []) {
            return $result(DnsStatus::Error, 'Resolves to '.self::list($addresses).', but the server has no public IP address yet to compare with.', $addresses, $answer->cnames);
        }

        $matched = [];

        foreach ($addresses as $address) {
            if (isset($expected[$address])) {
                $matched[$expected[$address]->serverId] = $expected[$address];
            }
        }

        $unexpected = array_values(array_filter($addresses, fn (string $address) => ! isset($expected[$address])));
        $matched = array_values($matched);

        if ($unexpected !== []) {
            $want = self::list(array_keys($expected));

            return $result(DnsStatus::Mismatch, $matched === []
                ? 'Resolves to '.self::list($addresses)." — expected {$want}."
                : 'Also resolves to '.self::list($unexpected)." — remove those records (expected {$want}).", $addresses, $answer->cnames, $matched);
        }

        $pointsTo = implode(', ', array_map(fn (DnsTarget $target) => "{$target->name} (".implode(', ', array_values(array_filter($addresses, fn (string $address) => isset($expected[$address]) && $expected[$address] === $target))).')', $matched));
        $certificate = $probeTls ? $this->tls->probe($name, $addresses[0]) : null;

        return $result(DnsStatus::Ok, "Points to {$pointsTo}", $addresses, $answer->cnames, $matched, $certificate);
    }

    private static function canonical(string $address): string
    {
        $packed = @inet_pton($address);

        return $packed === false ? strtolower($address) : (string) inet_ntop($packed);
    }

    /**
     * @param  list<string>  $addresses
     */
    private static function list(array $addresses): string
    {
        return implode(', ', $addresses);
    }
}
