<?php

namespace Falak\Edge\Application\Actions;

use Falak\Edge\Application\ComposeServiceDomains;
use Falak\Edge\Application\EdgeChanges;
use Falak\Edge\Contracts\TlsMode;
use Falak\Edge\Domain\Enums\WwwRedirect;
use Falak\Edge\Domain\Models\Domain;
use Falak\Edge\Domain\Models\PreviewDomain;
use Falak\Edge\Events\DomainAdded;
use Falak\Identity\Contracts\AuditLog;
use Falak\Sites\Contracts\Data\SiteData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AddDomain
{
    use ValidatesDomainTls;

    public function __construct(
        private readonly EdgeChanges $changes,
        private readonly AuditLog $audit,
        private readonly ComposeServiceDomains $services,
    ) {}

    /**
     * @param  ?string  $service  public service of a compose site the domain routes to (null: the site / its primary service)
     * @param  bool  $preview  a preview host routed by PreviewDomains (names under the preview domain are reserved for those)
     */
    public function __invoke(SiteData $site, string $name, TlsMode $tls = TlsMode::Auto, WwwRedirect $www = WwwRedirect::None, ?string $certificateId = null, ?string $dnsCredentialId = null, ?string $service = null, bool $preview = false): Domain
    {
        $service = ComposeServiceDomains::normalize($site, $service);
        $name = strtolower(rtrim(trim($name), '.'));

        if (preg_match(Domain::HOSTNAME, $name) !== 1 || strlen($name) > 253) {
            throw ValidationException::withMessages(['name' => 'Enter a valid domain name, e.g. example.com or *.example.com.']);
        }

        if ($site->testDomain !== null && strtolower($site->testDomain) === $name) {
            throw ValidationException::withMessages(['name' => 'This is the site\'s test domain; it is always routed.']);
        }

        if (! $preview && (PreviewDomain::current()?->contains(ltrim($name, '*.')) ?? false)) {
            throw ValidationException::withMessages(['name' => 'Names under the preview domain are reserved for pull request previews.']);
        }

        $this->validateDomainTls($site->organizationId, $site->id, $name, $tls, $www, $certificateId, $dnsCredentialId);

        $managed = (bool) config('controlplane.managed', false);

        // In managed mode a domain requires TXT verification unless it is a Falak-issued preview host
        // ($preview=true) or the site's test domain. The uniqueness check releases unverified claims
        // from other orgs so a verified claim can take their place.
        $this->ensureHostsAvailable($name, $www, null, 'name', $managed);

        $domain = DB::transaction(function () use ($site, $name, $tls, $www, $certificateId, $dnsCredentialId, $service, $managed, $preview) {
            // Primary within the route: the site's own domains, or the service's.
            $primary = ! ComposeServiceDomains::scope(Domain::query()->where('site_id', $site->id), $site, $service)->lockForUpdate()->exists();

            // In managed mode new domains start unverified; the operator must add the TXT record.
            // Self-hosted (managed = false): mark verified immediately (unchanged behaviour).
            $needsVerification = $managed && ! $preview;
            $verifiedAt = $needsVerification ? null : now();
            $verificationToken = $needsVerification ? Str::random(48) : null;

            return Domain::query()->create([
                'organization_id' => $site->organizationId,
                'site_id' => $site->id,
                'compose_service' => $service,
                'name' => $name,
                'is_primary' => $primary,
                'www_redirect' => $www,
                'tls_mode' => $tls,
                'certificate_id' => $tls === TlsMode::Custom ? $certificateId : null,
                'dns_credential_id' => $tls === TlsMode::Dns ? $dnsCredentialId : null,
                'verification_token' => $verificationToken,
                'verified_at' => $verifiedAt,
            ]);
        });

        $this->audit->record('edge.domain_added', 'site', $site->id, array_filter(['domain' => $name, 'tls' => $tls->value, 'service' => $service]), $site->organizationId);

        // Unverified domains are not pushed to the edge yet; DomainAdded fires only once verified.
        if ($domain->isVerified()) {
            DomainAdded::dispatch($domain->id, $site->id, $site->organizationId, $name, $domain->is_primary);
            $this->changes->siteChanged($site->id);
        }
        $this->services->mirror($site);

        return $domain;
    }
}
