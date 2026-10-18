<?php

namespace Falak\ControlPlane;

use Falak\ControlPlane\Application\Jobs\CheckForRelease;
use Falak\ControlPlane\Application\Operators;
use Falak\ControlPlane\Application\ReleaseCheck;
use Falak\ControlPlane\Contracts\HostChannel;
use Falak\ControlPlane\Infrastructure\FileHostChannel;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\PermissionRegistry;
use Falak\Identity\Contracts\Role;
use Falak\Kernel\Support\ModuleServiceProvider;
use Falak\Kernel\Support\SharedProps;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;

/**
 * Instance-operator features of the control plane itself: the release check and "Update Falak", and the host channel
 * (request.json → falak-ctl on the host) that also enrolls the control plane's own machine as a server. Shown only to
 * the operator organization's owners and admins.
 */
class ControlPlaneServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/controlplane.php', 'controlplane');
        // Config read at resolve time: tests point it at a temporary directory. The panel URL is FALAK_URL, as Fleet
        // builds install URLs (fleet.panel_url, else app.url).
        $this->app->bind(HostChannel::class, fn () => new FileHostChannel(
            (string) config('controlplane.host_channel_dir'),
            (int) config('controlplane.stale_after_minutes', 15),
            (string) (config('fleet.panel_url') ?: config('app.url')),
        ));
    }

    protected function bootModule(): void
    {
        $this->app->make(PermissionRegistry::class)->register(Operators::PERMISSION, [Role::Admin], 'Update Falak and add the control plane\'s own machine as a server (operator organization only)', 'controlplane');

        // The top-bar "Update available" item: null for everyone who doesn't operate the install.
        $this->app->make(SharedProps::class)->register('falakUpdate', function (Request $request) {
            if (! $this->app->make(Operators::class)->canManage($request->user(), $this->app->make(CurrentOrganization::class)->id())) {
                return null;
            }

            $releases = $this->app->make(ReleaseCheck::class);

            return [
                'current' => $releases->currentVersion(),
                'latest' => $releases->latest()['tag'] ?? null,
                'update_available' => $releases->updateAvailable(),
                'settings_url' => '/settings/falak-updates',
            ];
        });

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->job(new CheckForRelease)->everySixHours()->name('controlplane:release-check')->withoutOverlapping();
        });
    }
}
