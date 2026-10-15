<?php

namespace Falak\Edge\Application\Listeners;

use Falak\Edge\Application\Jobs\SyncCloudflareDns;
use Falak\Edge\Contracts\EdgeRoutes;
use Falak\Edge\Domain\Enums\ApplyStatus;
use Falak\Edge\Domain\Enums\InstallStatus;
use Falak\Edge\Domain\Models\CertificateInstall;
use Falak\Edge\Domain\Models\CloudflareTunnel;
use Falak\Edge\Domain\Models\OriginLock;
use Falak\Edge\Domain\Models\ServerState;
use Falak\Edge\Events\CertificateInstallFailed;
use Falak\Edge\Events\CertificateIssued;
use Falak\Edge\Events\EdgeApplied;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Network\Contracts\Firewalls;
use Falak\Sites\Contracts\SiteDirectory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Str;

/**
 * Settles edge.caddy.apply / edge.cert.install commands dispatched by Edge.
 */
final class HandleEdgeCommandOutcome implements ShouldQueue
{
    public function __construct(private readonly EdgeRoutes $routes) {}

    public function handleFinished(CommandFinished $event): void
    {
        match ($event->type) {
            'edge.caddy.apply' => $this->applied($event),
            'edge.cert.install' => $this->certificate($event->commandId, $event->serverId, $event->organizationId, $event->result, null),
            'net.tunnel.apply' => $this->tunnelInstalled($event),
            default => null,
        };
    }

    /** cloudflared is up: the server's names move to the tunnel now (not before, so a failed install cuts nothing). */
    private function tunnelInstalled(CommandFinished $event): void
    {
        $active = (bool) ($event->result['active'] ?? false);
        $updated = CloudflareTunnel::query()->where('command_id', $event->commandId)->update([
            'status' => $active ? CloudflareTunnel::ACTIVE : CloudflareTunnel::ERROR,
            'error' => $active ? null : 'cloudflared is installed but not running (journalctl -u falak-cloudflared).',
        ]);

        if ($updated > 0 && $active) {
            foreach (app(SiteDirectory::class)->forServer($event->serverId) as $site) {
                SyncCloudflareDns::site($site->id);
            }
        }

        // A lock-down that depends on the tunnel follows its state.
        if ($updated > 0 && OriginLock::query()->whereKey(strtolower($event->serverId))->exists()) {
            app(Firewalls::class)->converge($event->serverId);
        }
    }

    public function handleFailed(CommandFailed $event): void
    {
        $reason = $event->error ?: "Command {$event->status}".($event->exitCode !== null ? " (exit code {$event->exitCode})" : '');

        if ($event->type === 'net.tunnel.apply') {
            CloudflareTunnel::query()->where('command_id', $event->commandId)->update(['status' => CloudflareTunnel::ERROR, 'error' => $reason]);
            if (OriginLock::query()->whereKey(strtolower($event->serverId))->exists()) {
                app(Firewalls::class)->converge($event->serverId); // closed falls back to Cloudflare-only
            }

            return;
        }

        if ($event->type === 'edge.caddy.apply') {
            ServerState::query()->where('server_id', $event->serverId)->where('command_id', $event->commandId)
                ->update(['status' => ApplyStatus::Failed, 'error' => Str::limit($reason, 990), 'updated_at' => now()]);
        } elseif ($event->type === 'edge.cert.install') {
            $this->certificate($event->commandId, $event->serverId, $event->organizationId, null, $reason);
        }
    }

    private function applied(CommandFinished $event): void
    {
        $state = ServerState::query()->where('server_id', $event->serverId)->where('command_id', $event->commandId)->first();

        // Superseded by a newer apply (or unknown): the newer command settles the state.
        if ($state === null) {
            return;
        }

        $result = $event->result ?? [];

        $state->forceFill([
            'status' => ApplyStatus::Applied,
            'config_sha256' => is_string($result['config_sha256'] ?? null) ? $result['config_sha256'] : null,
            'routes' => is_int($result['routes'] ?? null) ? $result['routes'] : null,
            'rate_limits_refused' => is_string($result['rate_limits_refused'] ?? null) ? mb_substr($result['rate_limits_refused'], 0, 1000) : null,
            'applied_octane_sites' => $state->octane_sites ?? [],
            'error' => null,
            'applied_at' => now(),
        ])->save();

        EdgeApplied::dispatch(
            $event->serverId,
            $state->organization_id,
            $event->commandId,
            (string) $state->payload_sha256,
            (bool) ($result['changed'] ?? false),
            $state->config_sha256,
            (int) ($state->routes ?? 0),
        );
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private function certificate(string $commandId, string $serverId, string $organizationId, ?array $result, ?string $error): void
    {
        $install = CertificateInstall::query()->with('certificate')->where('server_id', $serverId)->where('command_id', $commandId)->first();

        if ($install === null || $install->certificate->organization_id !== $organizationId) {
            return;
        }

        if ($install->status === InstallStatus::Removing) {
            $install->delete();

            return;
        }

        if ($error !== null) {
            $install->forceFill(['status' => InstallStatus::Failed, 'error' => Str::limit($error, 990)])->save();

            CertificateInstallFailed::dispatch($install->certificate_id, $organizationId, $serverId, $install->certificate->site_id, $install->certificate->domains, Str::limit($error, 990));

            return;
        }

        $install->forceFill(['status' => InstallStatus::Installed, 'error' => null, 'installed_at' => now()])->save();

        CertificateIssued::dispatch(
            $install->certificate_id,
            $organizationId,
            $serverId,
            $install->certificate->domains,
            is_string($result['not_after'] ?? null) ? $result['not_after'] : $install->certificate->not_after?->toIso8601String(),
        );

        // Domains using the certificate become routable on this server.
        $this->routes->schedule($serverId);
    }
}
