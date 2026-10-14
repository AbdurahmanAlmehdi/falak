<?php

namespace Falak\Uptime\Domain\Probing;

use DateTimeImmutable;

/**
 * Judges the chain a server presented: validity dates, host name (SAN DNS / IP names, one wildcard label; the CN only
 * without SANs), and trust against a CA bundle with the presented intermediates as untrusted helpers. A chain that does
 * not verify while the server sent only its leaf, issued by someone else, lacks its intermediate (browsers may fetch it
 * through AIA and hide the problem; curl, mobile apps and other servers fail).
 */
final class CertificateInspector
{
    /**
     * @param  list<string>  $pems  leaf first
     */
    public function inspect(array $pems, string $host, ?string $caFile, DateTimeImmutable $now): TlsReport
    {
        $leaf = $pems[0] ?? null;
        $parsed = $leaf !== null ? openssl_x509_parse($leaf) : false;

        if ($parsed === false) {
            return new TlsReport(TlsReport::NO_CERTIFICATE, null, null, null, null, false, false, count($pems));
        }

        $validFrom = isset($parsed['validFrom_time_t']) ? (new DateTimeImmutable)->setTimestamp((int) $parsed['validFrom_time_t']) : null;
        $expiresAt = isset($parsed['validTo_time_t']) ? (new DateTimeImmutable)->setTimestamp((int) $parsed['validTo_time_t']) : null;
        $names = self::names($parsed);
        $hostnameMatch = self::matchesHost($names, $host);
        $subject = self::dn($parsed['subject'] ?? []);
        $issuer = self::dn($parsed['issuer'] ?? []);
        $selfIssued = ($parsed['subject'] ?? null) == ($parsed['issuer'] ?? null);
        $trusted = $this->trusted($leaf, array_slice($pems, 1), $caFile);

        $problem = match (true) {
            $expiresAt !== null && $expiresAt <= $now => TlsReport::EXPIRED,
            $validFrom !== null && $validFrom > $now => TlsReport::NOT_YET_VALID,
            ! $trusted && $selfIssued && count($pems) === 1 => TlsReport::SELF_SIGNED,
            ! $trusted && count($pems) === 1 => TlsReport::INTERMEDIATE_MISSING,
            ! $trusted => TlsReport::UNTRUSTED,
            ! $hostnameMatch => TlsReport::HOSTNAME_MISMATCH,
            default => null,
        };

        return new TlsReport($problem, $subject, $issuer, $validFrom, $expiresAt, $hostnameMatch, $trusted, count($pems), $names);
    }

    /**
     * @param  list<string>  $intermediates
     */
    private function trusted(string $leaf, array $intermediates, ?string $caFile): bool
    {
        $caFile = $caFile ?? self::defaultCaFile();

        if ($caFile === null) {
            return false;
        }

        $untrusted = null;

        if ($intermediates !== []) {
            $untrusted = tempnam(sys_get_temp_dir(), 'falak-chain-');

            if ($untrusted === false) {
                return false;
            }

            file_put_contents($untrusted, implode("\n", $intermediates));
        }

        try {
            return @openssl_x509_checkpurpose($leaf, X509_PURPOSE_SSL_SERVER, [$caFile], $untrusted) === true;
        } finally {
            if ($untrusted !== null) {
                @unlink($untrusted);
            }
        }
    }

    public static function defaultCaFile(): ?string
    {
        $locations = openssl_get_cert_locations();
        $candidates = [
            getenv('SSL_CERT_FILE') ?: null,
            $locations['default_cert_file'] ?? null,
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
            '/etc/ssl/cert.pem',
            $locations['ini_cafile'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return list<string> lower-case DNS names and IP addresses the certificate covers
     */
    private static function names(array $parsed): array
    {
        $san = (string) ($parsed['extensions']['subjectAltName'] ?? '');
        $names = [];

        foreach (array_filter(array_map('trim', explode(',', $san))) as $entry) {
            if (str_starts_with($entry, 'DNS:')) {
                $names[] = strtolower(substr($entry, 4));
            } elseif (str_starts_with($entry, 'IP Address:')) {
                $names[] = strtolower(substr($entry, 11));
            }
        }

        if ($names === [] && isset($parsed['subject']['CN'])) {
            $names = array_map('strtolower', (array) $parsed['subject']['CN']);
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  list<string>  $names
     */
    public static function matchesHost(array $names, string $host): bool
    {
        $host = rtrim(strtolower($host), '.');

        foreach ($names as $name) {
            if ($name === $host) {
                return true;
            }

            // "*.example.com" covers exactly one label: a.example.com, not example.com nor a.b.example.com.
            if (str_starts_with($name, '*.') && filter_var($host, FILTER_VALIDATE_IP) === false) {
                $suffix = substr($name, 1);
                $label = substr($host, 0, -strlen($suffix));

                if (str_ends_with($host, $suffix) && $label !== '' && ! str_contains($label, '.')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, string|list<string>>  $dn
     */
    private static function dn(array $dn): ?string
    {
        if ($dn === []) {
            return null;
        }

        $value = $dn['CN'] ?? $dn['O'] ?? reset($dn);
        $value = is_array($value) ? implode(', ', $value) : (string) $value;

        if (isset($dn['O'], $dn['CN']) && ! is_array($dn['O']) && $dn['O'] !== $value) {
            $value .= " ({$dn['O']})";
        }

        return mb_substr($value, 0, 200);
    }
}
