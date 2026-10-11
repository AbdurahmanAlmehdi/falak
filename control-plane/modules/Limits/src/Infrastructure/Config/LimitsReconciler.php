<?php

namespace Falak\Limits\Infrastructure\Config;

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
use Falak\Limits\Contracts\LimitValidator;
use Falak\Limits\Contracts\ResourceLimits;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\SiteLimits;
use Falak\Sites\Contracts\SiteRuntime;
use Illuminate\Validation\ValidationException;

/**
 * falak.yaml's `units.<u>.limits` for app units (phase A): the site's own ResourceLimits (sites_sites.limits).
 */
final class LimitsReconciler implements Reconcilable, ValidatesConfig
{
    public const SECTION = 'units.*(app).limits';

    /** File pointer (relative to /limits) => ResourceLimits key; sizes are MiB in the columns. */
    public const FIELDS = [
        '/memory' => 'memory_limit',
        '/memory_reservation' => 'memory_reservation',
        '/cpus' => 'cpus',
        '/pids' => 'pids_limit',
        '/restart' => 'restart_policy',
        '/max_restarts' => 'max_restarts',
        '/log/max_size' => 'log_max_size',
        '/log/max_files' => 'log_max_files',
        '/oom' => 'oom',
    ];

    private const SIZES = ['/memory', '/memory_reservation', '/log/max_size'];

    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly SiteLimits $siteLimits,
        private readonly ManagedResources $managed,
        private readonly LimitValidator $validator,
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
            if ($service['kind'] !== 'site' || ($site = $this->sites->find($service['ref_id'])) === null || in_array($site->runtime, [SiteRuntime::Compose, SiteRuntime::Function], true)) {
                continue;
            }

            $limits = self::toFile($site->limits);
            $units[(string) $unit] = ['type' => 'app', ...($limits !== [] ? ['limits' => $limits] : [])];
        }

        return $units === [] ? [] : ['units' => $units];
    }

    /**
     * ResourceLimits in the file's shape.
     *
     * @return array<string, mixed>
     */
    public static function toFile(ResourceLimits $limits): array
    {
        $values = $limits->toArray();
        $out = [];

        foreach (self::FIELDS as $path => $key) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $value = in_array($path, self::SIZES, true) ? Units::size((int) $values[$key]) : $values[$key];
            $tokens = Pointer::tokens($path);

            if (count($tokens) === 2) {
                $out[$tokens[0]][$tokens[1]] = $value;
            } else {
                $out[$tokens[0]] = $value;
            }
        }

        return $out;
    }

    /**
     * The file's limits as ResourceLimits input (sizes in MiB).
     *
     * @param  array<string, mixed>  $limits
     * @return array<string, mixed>
     */
    public static function toInput(array $limits): array
    {
        $out = [];

        foreach (Diff::leaves($limits) as $path => $value) {
            if (isset(self::FIELDS[$path])) {
                $out[self::FIELDS[$path]] = in_array($path, self::SIZES, true) ? Units::mebibytes($value) : $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $limits
     * @return array<string, mixed>
     */
    private static function desiredLeaves(array $limits): array
    {
        $leaves = Diff::leaves($limits);

        foreach (self::SIZES as $path) {
            if (isset($leaves[$path]) && ($mib = Units::mebibytes($leaves[$path])) !== null) {
                $leaves[$path] = Units::size($mib);
            }
        }

        return $leaves;
    }

    public function diff(ConfigScope $scope, array $desired, array $current): ChangeSet
    {
        $changes = [];

        foreach ((array) ($desired['units'] ?? []) as $unit => $body) {
            if (! is_array($body['limits'] ?? null)) {
                continue;
            }

            $unit = (string) $unit;
            $have = Diff::leaves((array) ($current['units'][$unit]['limits'] ?? []));

            foreach (Diff::fields(self::SECTION, $unit, '/units/'.Pointer::escape($unit).'/limits', self::desiredLeaves($body['limits']), $have, ! isset($current['units'][$unit])) as $change) {
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

            $wanted = [];

            foreach ($list as $change) {
                $path = substr((string) Pointer::relativeToUnit($change->path), strlen('/limits'));
                $wanted[self::FIELDS[$path] ?? $path] = in_array($path, self::SIZES, true) ? Units::mebibytes($change->after) : $change->after;
            }

            if (array_filter($list, fn (Change $c) => $c->op !== ChangeOp::Noop) !== []) {
                // Fields the file doesn't set keep today's value.
                $this->siteLimits->set($site->id, [...$site->limits->toArray(), ...$wanted], $ctx->actorId);
            }

            $paths = array_map(fn (Change $c) => (string) Pointer::relativeToUnit($c->path), $list);
            $this->managed->record($scope, $ctx, (string) $unit, 'site', $site->id, $paths, merge: true);
            $this->managed->forgetPaths('site', $site->id, ['/limits'], $paths);
        }
    }

    public function validate(ConfigScope $scope, array $resolved): array
    {
        $issues = [];

        foreach ((array) ($resolved['units'] ?? []) as $unit => $body) {
            if (($body['type'] ?? null) !== 'app' || ! is_array($body['limits'] ?? null)) {
                continue;
            }

            $base = '/units/'.Pointer::escape((string) $unit).'/limits';
            $limits = $body['limits'];
            $bad = false;

            foreach (['/memory' => [32, null, 'at least 32m'], '/memory_reservation' => [32, null, 'at least 32m'], '/log/max_size' => [1, 10240, '1m to 10g']] as $path => [$min, $max, $label]) {
                $value = Diff::at($limits, $path);

                if ($value === null) {
                    continue;
                }

                $mib = Units::mebibytes($value);

                if ($mib === null || $mib < $min || ($max !== null && $mib > $max)) {
                    $issues[] = ConfigIssue::error('value.range', $base.$path, "Must be a whole number of MiB, {$label}.");
                    $bad = true;
                }
            }

            if (! $bad && isset($limits['memory'], $limits['memory_reservation']) && Units::mebibytes($limits['memory_reservation']) > Units::mebibytes($limits['memory'])) {
                $issues[] = ConfigIssue::error('value.conflict', "{$base}/memory_reservation", 'memory_reservation is above memory.');
                $bad = true;
            }

            if ($bad) {
                continue;
            }

            $serverIds = isset($body['servers']) ? array_values(array_filter(array_map(fn ($n) => $scope->serverId((string) $n), (array) $body['servers']))) : $scope->defaultServers;

            if ($serverIds === [] && ($siteId = $scope->refId((string) $unit)) !== null) {
                $serverIds = $this->sites->find($siteId)?->serverIds() ?? [];
            }

            try {
                $this->validator->validate(self::toInput($limits), $serverIds);
            } catch (ValidationException $e) {
                foreach ($e->errors() as $messages) {
                    $issues[] = ConfigIssue::error('limit.server', $base, (string) ($messages[0] ?? 'The limits exceed the unit\'s servers.'));
                }
            }
        }

        return $issues;
    }
}
