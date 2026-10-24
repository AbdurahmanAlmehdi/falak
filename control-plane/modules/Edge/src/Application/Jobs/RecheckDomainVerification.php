<?php

namespace Falak\Edge\Application\Jobs;

use Falak\Edge\Application\EdgeChanges;
use Falak\Edge\Domain\Models\Domain;
use Falak\Edge\Events\DomainAdded;
use Falak\Edge\Infrastructure\Dns\DnsLookupFailed;
use Falak\Edge\Infrastructure\Dns\DnsResolver;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Periodically re-checks TXT ownership for unverified domains and activates them on the edge once
 * the record is in place (managed mode only; a no-op when `controlplane.managed` is false).
 *
 * Unique while queued or running to avoid racing parallel runs.
 */
final class RecheckDomainVerification implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 600;

    public function handle(DnsResolver $dns, EdgeChanges $changes): void
    {
        if (! config('controlplane.managed', false)) {
            return;
        }

        Domain::query()
            ->whereNull('verified_at')
            ->whereNotNull('verification_token')
            ->get()
            ->each(function (Domain $domain) use ($dns, $changes): void {
                $challenge = '_falak-challenge.'.ltrim($domain->name, '*.');

                try {
                    $values = $dns->txt($challenge);
                } catch (DnsLookupFailed) {
                    return; // transient; retry next run
                }

                if (! in_array($domain->verification_token, $values, true)) {
                    return;
                }

                $domain->forceFill(['verified_at' => now()])->save();

                DomainAdded::dispatch($domain->id, $domain->site_id, $domain->organization_id, $domain->name, $domain->is_primary);
                $changes->siteChanged($domain->site_id);
            });
    }
}
