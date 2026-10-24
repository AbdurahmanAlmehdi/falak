<?php

namespace Falak\Edge\Domain\Models;

use Falak\Edge\Contracts\Data\DomainData;
use Falak\Edge\Contracts\TlsMode;
use Falak\Edge\Domain\Enums\WwwRedirect;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $site_id
 * @property ?string $compose_service public service of a compose site (null: the site, i.e. its primary service)
 * @property string $name
 * @property bool $is_primary
 * @property WwwRedirect $www_redirect
 * @property TlsMode $tls_mode
 * @property ?string $certificate_id
 * @property ?string $dns_credential_id
 * @property ?string $verification_token random token placed in _falak-challenge.<domain> TXT record (managed mode)
 * @property ?Carbon $verified_at set once ownership is confirmed; null = unverified
 * @property ?bool $cloudflare_proxied null = the Cloudflare zone's default
 * @property ?string $cloudflare_cache null = standard | everything | bypass
 * @property ?array{path: ?string, requests: int, period: int, action: string, timeout: int} $cloudflare_rate_limit Cloudflare rate limit rule (null: none)
 * @property ?Certificate $certificate
 * @property ?DnsCredential $dnsCredential
 */
class Domain extends Model
{
    use HasUlids;

    public const HOSTNAME = '/^(\*\.)?([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/';

    protected $table = 'edge_domains';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'www_redirect' => WwwRedirect::class,
            'tls_mode' => TlsMode::class,
            'verified_at' => 'datetime',
            'cloudflare_proxied' => 'boolean',
            'cloudflare_rate_limit' => 'array',
        ];
    }

    /** Whether domain ownership has been confirmed (always true on self-hosted installs). */
    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * @return BelongsTo<Certificate, $this>
     */
    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class);
    }

    /**
     * @return BelongsTo<DnsCredential, $this>
     */
    public function dnsCredential(): BelongsTo
    {
        return $this->belongsTo(DnsCredential::class);
    }

    public function isWildcard(): bool
    {
        return str_starts_with($this->name, '*.');
    }

    /** Whether a www ↔ apex redirect can be configured (not a wildcard, not already a www host). */
    public function supportsWwwRedirect(): bool
    {
        return ! $this->isWildcard() && ! str_starts_with($this->name, 'www.');
    }

    /** Host actually served (www.<name> when redirecting to www). */
    public function servedHost(): string
    {
        return $this->www_redirect === WwwRedirect::ToWww ? 'www.'.$this->name : $this->name;
    }

    /** Host that redirects to the served one, if any. */
    public function redirectHost(): ?string
    {
        return match ($this->www_redirect) {
            WwwRedirect::ToWww => $this->name,
            WwwRedirect::ToApex => 'www.'.$this->name,
            WwwRedirect::None => null,
        };
    }

    /**
     * @return list<string>
     */
    public function hosts(): array
    {
        return array_values(array_filter([$this->servedHost(), $this->redirectHost()]));
    }

    public function toData(): DomainData
    {
        return new DomainData(
            id: $this->id,
            siteId: $this->site_id,
            name: $this->name,
            primary: $this->is_primary,
            wwwRedirect: $this->www_redirect->value,
            tls: $this->tls_mode,
            certificateId: $this->certificate_id,
            service: $this->compose_service,
        );
    }
}
