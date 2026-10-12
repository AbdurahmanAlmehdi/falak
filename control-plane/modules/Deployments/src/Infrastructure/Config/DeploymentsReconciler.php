<?php

namespace Falak\Deployments\Infrastructure\Config;

use Falak\Config\Contracts\ApplyContext;
use Falak\Config\Contracts\ApplyPhase;
use Falak\Config\Contracts\Change;
use Falak\Config\Contracts\ChangeOp;
use Falak\Config\Contracts\ChangeSet;
use Falak\Config\Contracts\ConfigIssue;
use Falak\Config\Contracts\ConfigScope;
use Falak\Config\Contracts\Diff;
use Falak\Config\Contracts\ManagedResources;
use Falak\Config\Contracts\Pointer;
use Falak\Config\Contracts\Reconcilable;
use Falak\Config\Contracts\Units;
use Falak\Config\Contracts\ValidatesConfig;
use Falak\Deployments\Application\Actions\UpdateDeploySettings;
use Falak\Deployments\Application\Actions\UpdateWatchSettings;
use Falak\Deployments\Domain\Enums\Strategy;
use Falak\Deployments\Domain\Models\ReleaseWatch;
use Falak\Deployments\Domain\Models\SiteSettings;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\SiteRuntime;

/**
 * falak.yaml's `units.<u>.deploy` (minus `hooks`, owned by Sites): the deployment settings of app units (strategy,
 * batches, releases kept, secrets mode, health check, watch after go-live) of app and compose units. Phase A.
 */
final class DeploymentsReconciler implements Reconcilable, ValidatesConfig
{
    public const SECTION = 'units.*(app|compose).deploy';

    /** File field => SiteSettings column. Durations are seconds in the columns. */
    private const HEALTH = ['enabled' => 'health_enabled', 'path' => 'health_path', 'status' => 'health_status', 'timeout' => 'health_timeout_s', 'retries' => 'health_retries', 'retry_delay' => 'health_retry_delay_s'];

    private const WATCH = ['enabled' => 'watch_enabled', 'minutes' => 'watch_minutes', 'on_trigger' => 'watch_on_trigger', 'health' => 'watch_health', 'health_failures' => 'watch_health_failures', 'crashes' => 'watch_crashes', 'errors' => 'watch_errors', 'issues' => 'watch_issues'];

    private const DURATIONS = ['/health/timeout', '/health/retry_delay'];

    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly ManagedResources $managed,
    ) {}

    public function sections(): array
    {
        return [self::SECTION];
    }

    public function phase(): ApplyPhase
    {
        return ApplyPhase::BeforeBuild;
    }

    public function current(ConfigScope $scope): array
    {
        $units = [];

        foreach ($scope->services as $unit => $service) {
            if ($service['kind'] !== 'site' || ($site = $this->sites->find($service['ref_id'])) === null || $site->runtime->isFunction()) {
                continue;
            }

            $units[(string) $unit] = ['type' => $site->runtime === SiteRuntime::Compose ? 'compose' : 'app', 'deploy' => self::deployOf($site, SiteSettings::query()->find($site->id))];
        }

        return $units === [] ? [] : ['units' => $units];
    }

    /**
     * A site's deploy settings in the file's shape (the module defaults when it has no settings row yet).
     *
     * @return array<string, mixed>
     */
    public static function deployOf(SiteData $site, ?SiteSettings $settings): array
    {
        $settings ??= self::defaults($site);
        $health = array_filter([
            'enabled' => $settings->health_enabled,
            'path' => $settings->health_path,
            'status' => $settings->health_status,
            'timeout' => Units::duration($settings->health_timeout_s),
            'retries' => $settings->health_retries,
            'retry_delay' => Units::duration($settings->health_retry_delay_s),
        ], fn ($v) => $v !== null);
        $watch = $settings->watch();

        return [
            'strategy' => $settings->effectiveStrategy($site)->value,
            'batch_size' => $settings->batch_size,
            'keep_releases' => $settings->keep_releases,
            'secrets' => $settings->effectiveSecretsMode($site),
            'health' => $health,
            'watch' => [
                'enabled' => $watch['enabled'],
                'minutes' => $watch['minutes'],
                'on_trigger' => $watch['on_trigger'],
                'health' => $watch['health'],
                'health_failures' => $watch['health_failures'],
                'crashes' => $watch['crashes'],
                'errors' => $watch['errors'],
                'issues' => $watch['issues'],
            ],
        ];
    }

    private static function defaults(SiteData $site): SiteSettings
    {
        $defaults = (array) config('deployments.defaults');

        return new SiteSettings([
            'site_id' => $site->id,
            'batch_size' => (int) ($defaults['batch_size'] ?? 1),
            'keep_releases' => (int) ($defaults['keep_releases'] ?? 5),
            'health_enabled' => (bool) ($defaults['health']['enabled'] ?? true),
            'health_status' => (int) ($defaults['health']['status'] ?? 200),
            'health_timeout_s' => (int) ($defaults['health']['timeout_s'] ?? 10),
            'health_retries' => (int) ($defaults['health']['retries'] ?? 3),
            'health_retry_delay_s' => (int) ($defaults['health']['retry_delay_s'] ?? 5),
            'secrets_mode' => SiteSettings::SECRETS_ENV,
            'watch_enabled' => false,
            'watch_minutes' => (int) ($defaults['watch']['minutes'] ?? 5),
            'watch_health' => true,
            'watch_health_failures' => (int) ($defaults['watch']['health_failures'] ?? 3),
            'watch_crashes' => true,
            'watch_errors' => true,
            'watch_issues' => false,
            'watch_on_trigger' => ReleaseWatch::ROLLBACK,
        ]);
    }

    /**
     * The file's deploy block as compared: hooks left out, `watch` present ⇒ enabled, durations in their shortest form.
     *
     * @param  array<string, mixed>  $deploy
     * @return array<string, mixed> pointer relative to /deploy => value
     */
    public static function desiredLeaves(array $deploy): array
    {
        unset($deploy['hooks']);

        if (isset($deploy['watch']) && is_array($deploy['watch'])) {
            $deploy['watch']['enabled'] ??= true;
        }

        $leaves = Diff::leaves($deploy);

        foreach (self::DURATIONS as $path) {
            if (isset($leaves[$path]) && ($seconds = Units::seconds($leaves[$path])) !== null) {
                $leaves[$path] = Units::duration($seconds);
            }
        }

        return $leaves;
    }

    public function diff(ConfigScope $scope, array $desired, array $current): ChangeSet
    {
        $changes = [];

        foreach ((array) ($desired['units'] ?? []) as $unit => $body) {
            if (! is_array($body['deploy'] ?? null)) {
                continue;
            }

            $unit = (string) $unit;
            $have = isset($current['units'][$unit]['deploy']) ? Diff::leaves((array) $current['units'][$unit]['deploy']) : [];
            $creating = ! isset($current['units'][$unit]);

            foreach (Diff::fields(self::SECTION, $unit, '/units/'.Pointer::escape($unit).'/deploy', self::desiredLeaves($body['deploy']), $have, $creating) as $change) {
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
            $siteId = $scope->refId((string) $unit);
            $site = $siteId !== null ? $this->sites->find($siteId) : null;

            if ($site === null) {
                continue;
            }

            $leaves = [];

            foreach ($list as $change) {
                $leaves[substr((string) Pointer::relativeToUnit($change->path), strlen('/deploy'))] = $change->after;
            }

            $actionable = array_filter($list, fn (Change $c) => $c->op !== ChangeOp::Noop);

            if ($actionable !== []) {
                $this->write($site, $leaves, $ctx);
            }

            $paths = array_map(fn (string $p) => '/deploy'.$p, array_keys($leaves));
            $this->managed->record($scope, $ctx, (string) $unit, 'site', $site->id, $paths, merge: true);
            $this->managed->forgetPaths('site', $site->id, ['/deploy/strategy', '/deploy/batch_size', '/deploy/keep_releases', '/deploy/secrets', '/deploy/health', '/deploy/watch'], $paths);
        }
    }

    /**
     * @param  array<string, mixed>  $leaves  pointer relative to /deploy => value
     */
    private function write(SiteData $site, array $leaves, ApplyContext $ctx): void
    {
        $settings = SiteSettings::for($site);
        $deploy = [
            'strategy' => $leaves['/strategy'] ?? $settings->effectiveStrategy($site)->value,
            'batch_size' => $leaves['/batch_size'] ?? $settings->batch_size,
            'keep_releases' => $leaves['/keep_releases'] ?? $settings->keep_releases,
            'secrets_mode' => $leaves['/secrets'] ?? $settings->secrets_mode,
        ];

        foreach (self::HEALTH as $field => $column) {
            $value = array_key_exists("/health/{$field}", $leaves) ? $leaves["/health/{$field}"] : $settings->{$column};
            $deploy[$column] = in_array("/health/{$field}", self::DURATIONS, true) && is_string($value) ? Units::seconds($value) : $value;
        }

        $touchesDeploy = array_filter(array_keys($leaves), fn (string $p) => ! str_starts_with($p, '/watch'));

        if ($touchesDeploy !== []) {
            app(UpdateDeploySettings::class)($site, $deploy, $ctx->actorId);
        }

        $watch = [];

        foreach (self::WATCH as $field => $column) {
            if (array_key_exists("/watch/{$field}", $leaves)) {
                $watch[$field] = $leaves["/watch/{$field}"];
            }
        }

        if ($watch !== []) {
            app(UpdateWatchSettings::class)($site, $watch, $ctx->actorId);
        }
    }

    public function validate(ConfigScope $scope, array $resolved): array
    {
        $issues = [];

        foreach ((array) ($resolved['units'] ?? []) as $unit => $body) {
            if (! in_array($body['type'] ?? null, ['app', 'compose'], true) || ! is_array($body['deploy'] ?? null)) {
                continue;
            }

            $base = '/units/'.Pointer::escape((string) $unit).'/deploy';
            $deploy = $body['deploy'];
            $runtime = $body['type'] === 'compose' ? SiteRuntime::Compose : SiteRuntime::tryFrom((string) ($body['runtime'] ?? ''));

            if ($runtime === null && ($siteId = $scope->refId((string) $unit)) !== null) {
                $runtime = $this->sites->find($siteId)?->runtime;
            }

            if (isset($deploy['strategy']) && $runtime !== null && ($strategy = Strategy::tryFrom((string) $deploy['strategy'])) !== null && ! in_array($strategy, Strategy::for($runtime), true)) {
                $allowed = implode(', ', array_map(fn (Strategy $s) => $s->value, Strategy::for($runtime)));
                $issues[] = ConfigIssue::error('value.unsupported', "{$base}/strategy", "{$strategy->value} is not available for the {$runtime->value} runtime; use {$allowed}.");
            }

            if (($deploy['secrets'] ?? null) === SiteSettings::SECRETS_FILES && $runtime !== null && $runtime !== SiteRuntime::Docker) {
                $issues[] = ConfigIssue::error('value.unsupported', "{$base}/secrets", '`secrets: files` is only for the docker runtime.');
            }

            foreach (['timeout' => [1, 120, '1s to 2m'], 'retry_delay' => [0, 300, '0s to 5m']] as $field => [$min, $max, $label]) {
                if (isset($deploy['health'][$field]) && (($s = Units::seconds($deploy['health'][$field])) === null || $s < $min || $s > $max)) {
                    $issues[] = ConfigIssue::error('value.range', "{$base}/health/{$field}", "health.{$field} must be {$label}.");
                }
            }
        }

        return $issues;
    }
}
