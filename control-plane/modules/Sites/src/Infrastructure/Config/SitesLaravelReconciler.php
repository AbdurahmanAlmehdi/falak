<?php

namespace Falak\Sites\Infrastructure\Config;

use Falak\Config\Contracts\ApplyContext;
use Falak\Config\Contracts\ApplyPhase;
use Falak\Config\Contracts\Change;
use Falak\Config\Contracts\ChangeOp;
use Falak\Config\Contracts\ChangeSet;
use Falak\Config\Contracts\ConfigScope;
use Falak\Config\Contracts\Diff;
use Falak\Config\Contracts\ManagedResources;
use Falak\Config\Contracts\Pointer;
use Falak\Config\Contracts\Reconcilable;
use Falak\Sites\Application\Actions\UpdateLaravelSettings;
use Falak\Sites\Contracts\Data\LaravelSettings;
use Falak\Sites\Contracts\OctaneServer;
use Falak\Sites\Domain\Models\Site;

/**
 * Config as code, phase B: a unit's `laravel` (scheduler, Horizon, Octane). They start, stop or restart running
 * processes, so they change with the unit's release (after it went live), never before the build.
 */
final class SitesLaravelReconciler implements Reconcilable
{
    public const SECTION = 'units.*(app).laravel';

    public function __construct(private readonly ManagedResources $managed) {}

    public function sections(): array
    {
        return [self::SECTION];
    }

    public function phase(): ApplyPhase
    {
        return ApplyPhase::WithRelease;
    }

    public function current(ConfigScope $scope): array
    {
        $units = [];

        foreach ($scope->services as $unit => $service) {
            if ($service['kind'] !== 'site' || ($site = Site::query()->find($service['ref_id'])) === null || ! $site->framework->isLaravel()) {
                continue;
            }

            $laravel = $site->laravel;
            $units[(string) $unit] = ['type' => 'app', 'laravel' => [
                'scheduler' => $laravel->scheduler,
                'horizon' => $laravel->horizon,
                'octane' => $laravel->octane ? ($laravel->octaneServer?->value ?? OctaneServer::defaultFor($site->runtime)->value) : false,
            ]];
        }

        return $units === [] ? [] : ['units' => $units];
    }

    public function diff(ConfigScope $scope, array $desired, array $current): ChangeSet
    {
        $changes = [];

        foreach ((array) ($desired['units'] ?? []) as $unit => $body) {
            if (! isset($body['laravel']) || ! is_array($body['laravel'])) {
                continue;
            }

            $unit = (string) $unit;
            $base = '/units/'.Pointer::escape($unit).'/laravel';
            $today = (array) ($current['units'][$unit]['laravel'] ?? []);
            $creating = $scope->refId($unit) === null;

            foreach (Diff::fields(self::SECTION, $unit, $base, Diff::leaves($body['laravel']), Diff::leaves($today), $creating) as $change) {
                $changes[] = $change;
            }
        }

        return new ChangeSet($changes);
    }

    public function apply(ConfigScope $scope, ChangeSet $changes, ApplyContext $ctx): void
    {
        $byUnit = [];

        foreach ($changes->changes as $change) {
            if ($change->unit !== null) {
                $byUnit[$change->unit][] = $change;
            }
        }

        foreach ($byUnit as $unit => $list) {
            $unit = (string) $unit;
            $siteId = $scope->refId($unit);
            $site = $siteId !== null ? Site::query()->find($siteId) : null;

            if ($site === null) {
                continue;
            }

            $wanted = [];

            foreach ($list as $change) {
                $wanted[substr((string) Pointer::relativeToUnit($change->path), strlen('/laravel/'))] = $change->after;
            }

            if (array_filter($list, fn (Change $c) => $c->op !== ChangeOp::Noop) !== []) {
                $settings = $site->laravel->toArray();

                if (array_key_exists('octane', $wanted)) {
                    $settings['octane'] = $wanted['octane'] !== false;
                    $settings['octane_server'] = $wanted['octane'] !== false && is_string($wanted['octane']) ? $wanted['octane'] : null;
                }

                foreach (['scheduler', 'horizon'] as $flag) {
                    if (array_key_exists($flag, $wanted)) {
                        $settings[$flag] = (bool) $wanted[$flag];
                    }
                }

                app(UpdateLaravelSettings::class)($site, LaravelSettings::fromArray($settings), $ctx->actorId);
            }

            $paths = array_map(fn (string $k) => '/laravel/'.$k, array_keys($wanted));
            $this->managed->record($scope, $ctx, $unit, 'site', $site->id, $paths, merge: true);
            $this->managed->forgetPaths('site', $site->id, ['/laravel'], $paths);
        }
    }
}
