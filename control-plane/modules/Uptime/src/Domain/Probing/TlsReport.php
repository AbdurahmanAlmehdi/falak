<?php

namespace Falak\Uptime\Domain\Probing;

use DateTimeImmutable;

/**
 * What a TLS handshake presented, judged by {@see CertificateInspector}.
 */
final readonly class TlsReport
{
    public const EXPIRED = 'expired';

    public const NOT_YET_VALID = 'not_yet_valid';

    public const SELF_SIGNED = 'self_signed';

    public const INTERMEDIATE_MISSING = 'intermediate_missing';

    public const UNTRUSTED = 'untrusted';

    public const HOSTNAME_MISMATCH = 'hostname_mismatch';

    public const NO_CERTIFICATE = 'no_certificate';

    public function __construct(
        /** Null: the chain is fine (expiry soon is judged by the caller). */
        public ?string $problem,
        public ?string $subject,
        public ?string $issuer,
        public ?DateTimeImmutable $validFrom,
        public ?DateTimeImmutable $expiresAt,
        public bool $hostnameMatch,
        public bool $chainTrusted,
        /** Certificates the server presented (leaf included). */
        public int $presented,
        /** @var list<string> */
        public array $names = [],
    ) {}

    /**
     * @param  array<string, mixed>  $tls  {@see toArray()}
     */
    public static function fromArray(array $tls): self
    {
        return new self(
            $tls['problem'] ?? null,
            $tls['subject'] ?? null,
            $tls['issuer'] ?? null,
            isset($tls['valid_from']) ? new DateTimeImmutable($tls['valid_from']) : null,
            isset($tls['expires_at']) ? new DateTimeImmutable($tls['expires_at']) : null,
            (bool) ($tls['hostname_match'] ?? false),
            (bool) ($tls['chain_trusted'] ?? false),
            (int) ($tls['presented'] ?? 0),
            array_values((array) ($tls['names'] ?? [])),
        );
    }

    public function daysLeft(DateTimeImmutable $now): ?int
    {
        return $this->expiresAt === null ? null : (int) floor(($this->expiresAt->getTimestamp() - $now->getTimestamp()) / 86400);
    }

    public static function describe(string $problem): string
    {
        return match ($problem) {
            self::EXPIRED => 'The certificate has expired',
            self::NOT_YET_VALID => 'The certificate is not valid yet',
            self::SELF_SIGNED => 'The certificate is self-signed',
            self::INTERMEDIATE_MISSING => 'The server does not send the intermediate certificate',
            self::UNTRUSTED => 'The certificate chain is not trusted',
            self::HOSTNAME_MISMATCH => 'The certificate does not cover this host name',
            self::NO_CERTIFICATE => 'No certificate was presented',
            'expiring' => 'The certificate expires soon',
            default => $problem,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'problem' => $this->problem,
            'subject' => $this->subject,
            'issuer' => $this->issuer,
            'valid_from' => $this->validFrom?->format(DATE_ATOM),
            'expires_at' => $this->expiresAt?->format(DATE_ATOM),
            'hostname_match' => $this->hostnameMatch,
            'chain_trusted' => $this->chainTrusted,
            'presented' => $this->presented,
            'names' => array_slice($this->names, 0, 20),
        ];
    }
}
