<?php

namespace Falak\Functions\Infrastructure\Config;

use DateTimeZone;
use Falak\Config\Contracts\ApplyContext;
use Falak\Config\Contracts\ApplyPhase;
use Falak\Config\Contracts\Change;
use Falak\Config\Contracts\ChangeOp;
use Falak\Config\Contracts\ChangeSet;
use Falak\Config\Contracts\ConfigIssue;
use Falak\Config\Contracts\ConfigScope;
use Falak\Config\Contracts\Diff;
use Falak\Config\Contracts\ItemKey;
use Falak\Config\Contracts\ManagedResources;
use Falak\Config\Contracts\Pointer;
use Falak\Config\Contracts\Reconcilable;
use Falak\Config\Contracts\Units;
use Falak\Config\Contracts\ValidatesConfig;
use Falak\Functions\Application\Actions\SaveSchedule;
use Falak\Functions\Application\FunctionStore;
use Falak\Functions\Domain\Models\FunctionSchedule;
use Falak\Processes\Contracts\CronExpressions;
use Falak\Sites\Contracts\SiteDirectory;
use RuntimeException;

/**
 * falak.yaml's `units.<u>.schedules` of function units (phase B: they run the code of the release that went live).
 * Identity: the schedule's name (the key). A schedule the file created and removed is deleted; one it adopted is only
 * no longer managed.
 */
final class FunctionSchedulesReconciler implements Reconcilable, ValidatesConfig
{
    public const SECTION = 'units.*(function).schedules';

    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly FunctionStore $functions,
        private readonly ManagedResources $managed,
    ) {}

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
            if ($service['kind'] !== 'site' || ($site = $this->sites->find($service['ref_id'])) === null || ! $site->runtime->isFunction()) {
                continue;
            }

            $schedules = [];

            foreach ($this->schedules($site->id) as $name => $schedule) {
                $schedules[$name] = self::shape($schedule);
            }

            $units[(string) $unit] = ['type' => 'function', ...($schedules !== [] ? ['schedules' => $schedules] : [])];
        }

        return $units === [] ? [] : ['units' => $units];
    }

    /**
     * @return array{cron: string, timezone: string, overlap: string, timeout: string, enabled: bool}
     */
    private static function shape(FunctionSchedule $schedule): array
    {
        return [
            'cron' => $schedule->expression,
            'timezone' => $schedule->timezone,
            'overlap' => $schedule->overlap,
            'timeout' => Units::duration($schedule->timeout_s),
            'enabled' => $schedule->enabled,
        ];
    }

    public function diff(ConfigScope $scope, array $desired, array $current): ChangeSet
    {
        $changes = [];
        $units = array_unique([...array_keys((array) ($desired['units'] ?? [])), ...array_keys((array) ($current['units'] ?? []))]);

        foreach ($units as $unit) {
            $unit = (string) $unit;
            $base = '/units/'.Pointer::escape($unit).'/schedules/';
            $want = (array) ($desired['units'][$unit]['schedules'] ?? []);
            $have = (array) ($current['units'][$unit]['schedules'] ?? []);
            $siteId = $scope->refId($unit);
            $models = $siteId !== null ? $this->schedules($siteId) : [];

            foreach ($want as $name => $schedule) {
                $name = (string) $name;
                $schedule = self::normalize((array) $schedule);

                if (! isset($have[$name])) {
                    $changes[] = new Change(ChangeOp::Create, $base.Pointer::escape($name), null, $schedule, self::SECTION, $unit);

                    continue;
                }

                array_push($changes, ...Diff::fields(self::SECTION, $unit, $base.Pointer::escape($name), Diff::leaves($schedule), Diff::leaves((array) $have[$name])));
            }

            foreach ($have as $name => $schedule) {
                $model = $models[(string) $name] ?? null;

                if (array_key_exists($name, $want) || $model === null || ! $this->managed->managed('function_schedule', $model->id)->any()) {
                    continue;
                }

                $op = $this->deletable($scope, $model->id, $unit) ? ChangeOp::Delete : ChangeOp::Detach;
                $changes[] = new Change($op, $base.Pointer::escape((string) $name), $schedule, null, self::SECTION, $unit);
            }
        }

        return new ChangeSet($changes);
    }

    public function apply(ConfigScope $scope, ChangeSet $changes, ApplyContext $ctx): void
    {
        $groups = [];

        foreach ($changes->changes as $change) {
            $tokens = Pointer::tokens($change->path);

            if (count($tokens) >= 4 && $tokens[2] === 'schedules') {
                $groups[$tokens[1]][$tokens[3]][] = $change;
            }
        }

        foreach ($groups as $unit => $schedules) {
            $unit = (string) $unit;
            $siteId = $scope->refId($unit);
            $site = $siteId !== null ? $this->sites->find($siteId) : null;
            $function = $site !== null ? $this->functions->find($site->id) : null;

            if ($site === null || $function === null) {
                if (collect($schedules)->flatten()->every(fn (Change $c) => in_array($c->op, [ChangeOp::Delete, ChangeOp::Detach], true))) {
                    continue; // the function is gone: so are its schedules
                }

                throw new RuntimeException("The function unit \"{$unit}\" has no function to schedule.");
            }

            foreach ($schedules as $name => $list) {
                $name = (string) $name;
                $model = $this->schedules($site->id)[$name] ?? null;
                $first = $list[0];
                $whole = count(Pointer::tokens($first->path)) === 4;

                if ($whole && in_array($first->op, [ChangeOp::Delete, ChangeOp::Detach], true)) {
                    if ($model !== null) {
                        if ($first->op === ChangeOp::Delete && $this->deletable($scope, $model->id, $unit)) {
                            app(SaveSchedule::class)->delete($site, $model);
                            $this->managed->forget('function_schedule', $model->id, $scope->environmentId);
                        } else {
                            $this->managed->detach($scope, $ctx, $unit, 'function_schedule', $model->id, "Function schedule {$name} ({$unit})",
                                route('functions.schedules.destroy', ['site' => $site->id, 'schedule' => $model->id], false), $site->id);
                        }
                    }

                    continue;
                }

                $wanted = $whole ? (array) $first->after : [];

                foreach ($list as $change) {
                    $tokens = Pointer::tokens($change->path);

                    if (count($tokens) === 5) {
                        $wanted[$tokens[4]] = $change->after;
                    }
                }

                $created = $model === null;

                if ($created || array_filter($list, fn (Change $c) => $c->op !== ChangeOp::Noop) !== []) {
                    $model = app(SaveSchedule::class)($site, $function, $model, array_filter([
                        // An adopted schedule keeps its dashboard name ("Nightly report" behind `Nightly-report`).
                        'name' => $model?->name ?? $name,
                        'expression' => $wanted['cron'] ?? $model?->expression,
                        'timezone' => $wanted['timezone'] ?? null,
                        'overlap' => $wanted['overlap'] ?? null,
                        'timeout_s' => isset($wanted['timeout']) ? Units::seconds($wanted['timeout']) : null,
                        'enabled' => $wanted['enabled'] ?? null,
                    ], fn ($v) => $v !== null));
                }

                $this->managed->record($scope, $ctx, $unit, 'function_schedule', $model->id, ['/schedules/'.Pointer::escape($name)], $created ? true : null);
            }
        }
    }

    public function validate(ConfigScope $scope, array $resolved): array
    {
        $issues = [];

        foreach ((array) ($resolved['units'] ?? []) as $unit => $body) {
            if (($body['type'] ?? null) !== 'function') {
                continue;
            }

            foreach ((array) ($body['schedules'] ?? []) as $name => $schedule) {
                $base = '/units/'.Pointer::escape((string) $unit).'/schedules/'.Pointer::escape((string) $name);

                if (! CronExpressions::valid((string) ($schedule['cron'] ?? ''))) {
                    $issues[] = ConfigIssue::error('value.unsupported', "{$base}/cron", CronExpressions::HINT);
                }

                if (isset($schedule['timezone']) && ! in_array($schedule['timezone'], DateTimeZone::listIdentifiers(), true)) {
                    $issues[] = ConfigIssue::error('value.unsupported', "{$base}/timezone", "\"{$schedule['timezone']}\" is not an IANA time zone.");
                }

                if (isset($schedule['timeout']) && (($seconds = Units::seconds($schedule['timeout'])) === null || $seconds < 1 || $seconds > 86400)) {
                    $issues[] = ConfigIssue::error('value.range', "{$base}/timeout", 'Must be 1s to 1d.');
                }
            }
        }

        return $issues;
    }

    /**
     * The file's schedule as compared (whitespace of the expression collapsed, durations in their shortest form).
     *
     * @param  array<string, mixed>  $schedule
     * @return array<string, mixed>
     */
    private static function normalize(array $schedule): array
    {
        if (isset($schedule['cron'])) {
            $schedule['cron'] = (string) preg_replace('/\s+/', ' ', trim((string) $schedule['cron']));
        }

        if (isset($schedule['timeout']) && ($seconds = Units::seconds($schedule['timeout'])) !== null) {
            $schedule['timeout'] = Units::duration($seconds);
        }

        return $schedule;
    }

    /**
     * @return array<string, FunctionSchedule>
     */
    private function schedules(string $siteId): array
    {
        $function = $this->functions->find($siteId);

        return $function === null ? [] : ItemKey::map(FunctionSchedule::query()->where('function_id', $function->id)->orderBy('name')->get(), fn (FunctionSchedule $s) => (string) $s->name);
    }

    /** Created by this environment's file, and its unit is still the file's (else detached with its function). */
    private function deletable(ConfigScope $scope, string $id, string $unit): bool
    {
        $siteId = $scope->refId($unit);

        return $this->managed->createdByFile('function_schedule', $id, $scope->environmentId)
            && $siteId !== null && $this->managed->managedIn($scope->environmentId, 'site', $siteId);
    }
}
