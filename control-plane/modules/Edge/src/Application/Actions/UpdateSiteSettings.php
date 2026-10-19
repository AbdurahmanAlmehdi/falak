<?php

namespace Falak\Edge\Application\Actions;

use Falak\Edge\Application\EdgeChanges;
use Falak\Edge\Domain\Models\LoadBalancer;
use Falak\Edge\Domain\Models\SiteSetting;
use Falak\Identity\Contracts\AuditLog;
use Falak\Network\Contracts\Tailnet;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Contracts\Data\SiteData;
use Illuminate\Validation\ValidationException;

final class UpdateSiteSettings
{
    public function __construct(
        private readonly EdgeChanges $changes,
        private readonly AuditLog $audit,
        private readonly Tailnet $tailnet,
        private readonly ServerDirectory $servers,
    ) {}

    /**
     * @param  list<string>  $allowIps
     * @param  list<string>  $denyIps
     * @param  ?bool  $tailnetOnly  null: unchanged
     */
    public function __invoke(SiteData $site, array $allowIps, array $denyIps, ?int $maxBodyBytes, bool $encode, ?bool $tailnetOnly = null): void
    {
        $settings = SiteSetting::for($site->id);
        $tailnetOnly ??= $settings->tailnet_only;

        // Turning it on: every server of the site must be reachable through the tailnet, or nobody reaches it at all.
        if ($tailnetOnly && ! $settings->tailnet_only) {
            // A load-balanced site is reached through its balancer, which enforces the allowlist.
            $serverIds = array_unique([...$site->serverIds(), ...array_filter([LoadBalancer::query()->where('site_id', $site->id)->value('server_id')])]);
            $missing = array_values(array_filter($serverIds, fn (string $id) => ! $this->tailnet->joined($id)));

            if ($missing !== []) {
                $pending = array_values(array_filter($missing, fn (string $id) => $this->tailnet->routesPending($id)));
                $names = fn (array $ids) => implode(', ', array_map(fn (string $id) => $this->servers->find($id)?->name ?? $id, $ids));

                throw ValidationException::withMessages(['tailnet_only' => count($pending) === count($missing)
                    ? 'Approve '.$names($pending)."'s route in the Tailscale admin console first."
                    : 'Put '.$names(array_diff($missing, $pending)).' on the tailnet first (server → Network → Tailscale).']);
            }
        }

        $settings->forceFill([
            'allow_ips' => array_values(array_unique($allowIps)),
            'deny_ips' => array_values(array_unique($denyIps)),
            'max_body_bytes' => $maxBodyBytes ?: null,
            'encode' => $encode,
            'tailnet_only' => $tailnetOnly,
        ])->save();

        $this->audit->record('edge.settings_updated', 'site', $site->id, [
            'allow_ips' => $settings->allow_ips,
            'deny_ips' => $settings->deny_ips,
            'max_body_bytes' => $settings->max_body_bytes,
            'encode' => $encode,
            'tailnet_only' => $tailnetOnly,
        ], $site->organizationId);

        $this->changes->siteChanged($site->id);
    }
}
