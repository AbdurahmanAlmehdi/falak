<?php

namespace Falak\Edge\Application\Actions;

use Falak\Edge\Application\EdgeChanges;
use Falak\Edge\Domain\Models\Domain;
use Falak\Edge\Events\DomainAdded;
use Falak\Edge\Infrastructure\Dns\DnsLookupFailed;
use Falak\Edge\Infrastructure\Dns\DnsResolver;
use Falak\Identity\Contracts\AuditLog;
use Falak\Sites\Contracts\Data\SiteData;
use Illuminate\Validation\ValidationException;

/**
 * Checks TXT record ownership and activates the domain on the edge (managed mode only).
 *
 * The operator must have added  `_falak-challenge.<domain>  TXT  <verification_token>`  before calling
 * this action. Once verified the domain is pushed into the edge config like a freshly added one.
 */
final class VerifyDomain
{
    public function __construct(
        private readonly DnsResolver $dns,
        private readonly EdgeChanges $changes,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @throws ValidationException when the TXT record is missing or does not match the token
     * @throws DnsLookupFailed when the DNS resolver could not answer
     */
    public function __invoke(SiteData $site, Domain $domain): Domain
    {
        if ($domain->isVerified()) {
            return $domain; // already verified — idempotent
        }

        if ($domain->verification_token === null) {
            throw ValidationException::withMessages(['domain' => 'This domain has no pending verification token.']);
        }

        $challenge = '_falak-challenge.'.ltrim($domain->name, '*.');
        $values = $this->dns->txt($challenge);

        if (! in_array($domain->verification_token, $values, true)) {
            throw ValidationException::withMessages(['domain' => "Add a TXT record for {$challenge} with the value shown, then try again."]);
        }

        $domain->forceFill(['verified_at' => now()])->save();

        $this->audit->record('edge.domain_verified', 'site', $site->id, ['domain' => $domain->name], $site->organizationId);

        DomainAdded::dispatch($domain->id, $site->id, $site->organizationId, $domain->name, $domain->is_primary);
        $this->changes->siteChanged($site->id);

        return $domain->refresh();
    }
}
