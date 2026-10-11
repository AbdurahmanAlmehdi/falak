<?php

namespace Falak\Functions\Infrastructure\Config;

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
use Falak\Functions\Application\FunctionStore;
use Falak\Functions\Application\RepositoryCode;
use Falak\Functions\Domain\Models\CloudFunction;
use Falak\Functions\Domain\Models\FunctionVersionCommit;
use Falak\Identity\Contracts\AuditLog;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteDirectory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * falak.yaml's function units (phase A, after Sites created the function's site): runtime, entrypoint, root, scaling,
 * memory, CPUs, timeout, allowed addresses, and the code.
 *
 * **[decision]** A function unit's code is the repository's files under its `root` at the run's commit
 * ({@see RepositoryCode}), not the editor's: when they differ from the newest version, phase A saves them as a new
 * version ("falak.yaml @ abc1234"), and the commit is recorded against the version, so the unit's deployment (of that
 * commit) runs exactly that code. The editor no longer deploys a function whose code the file manages. API keys stay
 * in the dashboard (they are secrets).
 */
final class FunctionsReconciler implements Reconcilable, ValidatesConfig
{
    /** File fields => CloudFunction columns; `scaling.*` and the rest are compared as leaves. */
    private const COLUMNS = [
        '/runtime' => 'runtime', '/entrypoint' => 'entrypoint', '/root' => 'root',
        '/scaling/min_instances' => 'min_instances', '/scaling/max_instances' => 'max_instances', '/scaling/concurrency' => 'concurrency',
        '/scaling/idle_timeout' => 'idle_timeout_s', '/memory' => 'memory_mb', '/cpus' => 'cpus', '/timeout' => 'request_timeout_s',
        '/access/allow' => 'allow_cidrs',
    ];

    private const FIELDS = ['runtime', 'entrypoint', 'root', 'scaling', 'memory', 'cpus', 'timeout', 'access'];

    private const DURATIONS = ['/scaling/idle_timeout', '/timeout'];

    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly FunctionStore $functions,
        private readonly RepositoryCode $code,
        private readonly ManagedResources $managed,
        private readonly AuditLog $audit,
    ) {}

    public function sections(): array
    {
        return array_map(fn (string $field) => "units.*(function).{$field}", self::FIELDS);
    }

    public function phase(): ApplyPhase
    {
        return ApplyPhase::BeforeBuild;
    }

    public function current(ConfigScope $scope): array
    {
        $units = [];

        foreach ($scope->services as $unit => $service) {
            if ($service['kind'] !== 'site' || ($site = $this->sites->find($service['ref_id'])) === null || ! $site->runtime->isFunction()) {
                continue;
            }

            $function = $this->functions->find($site->id);
            $units[(string) $unit] = ['type' => 'function', ...($function !== null ? self::unitOf($function) : [])];
        }

        return $units === [] ? [] : ['units' => $units];
    }

    /**
     * @return array<string, mixed>
     */
    private static function unitOf(CloudFunction $function): array
    {
        return array_filter([
            'runtime' => $function->runtime,
            'entrypoint' => $function->entrypoint,
            'root' => $function->root,
            'scaling' => [
                'min_instances' => $function->min_instances,
                'max_instances' => $function->max_instances,
                'concurrency' => $function->concurrency,
                'idle_timeout' => Units::duration($function->idle_timeout_s),
            ],
            'memory' => Units::size($function->memory_mb),
            'cpus' => $function->cpus,
            'timeout' => Units::duration($function->request_timeout_s),
            'access' => ($function->allow_cidrs ?? []) !== [] ? ['allow' => array_values($function->allow_cidrs)] : null,
        ], fn ($v) => $v !== null);
    }

    public function diff(ConfigScope $scope, array $desired, array $current): ChangeSet
    {
        $changes = [];

        foreach ((array) ($desired['units'] ?? []) as $unit => $body) {
            $unit = (string) $unit;
            $base = '/units/'.Pointer::escape($unit);
            $siteId = ($scope->services[$unit]['kind'] ?? null) === 'site' ? $scope->refId($unit) : null;
            $function = $siteId !== null ? $this->functions->find($siteId) : null;
            $creating = $function === null;
            $have = Diff::leaves((array) ($current['units'][$unit] ?? []));
            $wanted = self::desiredLeaves($body);

            foreach ($wanted as $path => $value) {
                $section = 'units.*(function).'.(Pointer::tokens($path)[0] ?? '');
                array_push($changes, ...Diff::fields($section, $unit, $base, [$path => $value], $have, $creating));
            }

            $code = $this->read($scope, $body);

            if ($code !== null) {
                $head = $function?->head()?->hash;
                $op = $head === null ? ChangeOp::Create : ($head === $code['hash'] ? ChangeOp::Noop : ChangeOp::Update);
                $changes[] = new Change($op, "{$base}/code", $head !== null ? substr($head, 0, 12) : null, substr($code['hash'], 0, 12), 'units.*(function).root', $unit);
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
            $site = $siteId !== null ? $this->sites->find($siteId) : null;

            if ($site === null || ! $site->runtime->isFunction()) {
                throw ValidationException::withMessages(["units.{$unit}" => "The function unit {$unit} has no function site."]);
            }

            $desired = [];

            foreach ($list as $change) {
                $desired[(string) Pointer::relativeToUnit($change->path)] = $change->after;
            }

            $function = $this->settings($site, $desired, $ctx);

            if (array_key_exists('/code', $desired)) {
                $this->code($scope, $ctx, $site, $function);
            }

            $paths = array_keys($desired);
            $this->managed->record($scope, $ctx, $unit, 'site', $site->id, $paths, merge: true);
            $this->managed->forgetPaths('site', $site->id, ['/runtime', '/entrypoint', '/root', '/scaling', '/memory', '/cpus', '/timeout', '/access', '/code'], $paths);
        }
    }

    /**
     * The function row with the file's settings (created for a new unit, without a starter: its code comes next).
     *
     * @param  array<string, mixed>  $desired  pointer relative to the unit => value
     */
    private function settings(SiteData $site, array $desired, ApplyContext $ctx): CloudFunction
    {
        $function = $this->functions->find($site->id);

        if ($function === null) {
            $runtime = (string) ($desired['/runtime'] ?? config('functions.default_runtime', 'bun'));
            $function = CloudFunction::query()->firstOrCreate(['site_id' => $site->id], [
                'organization_id' => $site->organizationId,
                'runtime' => $runtime,
                'entrypoint' => (string) config("functions.runtimes.{$runtime}.entrypoint", 'index.ts'),
                ...array_intersect_key((array) config('functions.defaults'), array_flip(['min_instances', 'max_instances', 'concurrency', 'idle_timeout_s', 'memory_mb', 'cpus', 'request_timeout_s'])),
            ]);
        }

        foreach ($desired as $path => $value) {
            if (! isset(self::COLUMNS[$path])) {
                continue;
            }

            $function->{self::COLUMNS[$path]} = match (true) {
                in_array($path, self::DURATIONS, true) => Units::seconds($value),
                $path === '/memory' => Units::mebibytes($value),
                $path === '/access/allow' => array_values((array) $value) ?: null,
                default => $value,
            };
        }

        // A runtime change without an entrypoint takes the new runtime's.
        if ($function->isDirty('runtime') && ! array_key_exists('/entrypoint', $desired)) {
            $function->entrypoint = (string) config("functions.runtimes.{$function->runtime}.entrypoint", $function->entrypoint);
        }

        if ($function->isDirty()) {
            $changed = array_keys($function->getDirty());
            $function->save();
            $this->audit->record('function.settings.updated', 'site', $site->id, ['changed' => $changed, 'via' => 'falak.yaml'], $site->organizationId, $ctx->actorId);
        }

        return $function;
    }

    /**
     * The repository's code at the run's commit as the newest version (a new one only when it changed), recorded
     * against the commit.
     */
    private function code(ConfigScope $scope, ApplyContext $ctx, SiteData $site, CloudFunction $function): void
    {
        $code = $this->read($scope, ['root' => $function->root ?? '.', 'entrypoint' => $function->entrypoint, 'runtime' => $function->runtime], strict: true);

        DB::transaction(function () use ($scope, $ctx, $site, $function, $code) {
            CloudFunction::query()->whereKey($function->id)->lockForUpdate()->first();
            $head = $function->head();
            $version = $head;

            if ($head === null || $head->hash !== $code['hash']) {
                $commit = $scope->commit !== null ? ' @ '.substr($scope->commit, 0, 7) : '';
                $version = $this->functions->addVersion($function, $code['files'], "falak.yaml{$commit}", $ctx->actorId, null, $head?->id);
                $this->audit->record('function.version.created', 'site', $site->id, ['version' => $version->number, 'hash' => $version->hash, 'via' => 'falak.yaml', 'commit' => $scope->commit], $site->organizationId, $ctx->actorId);
            }

            if ($scope->commit !== null && $version !== null) {
                FunctionVersionCommit::query()->updateOrCreate(['function_id' => $function->id, 'commit' => strtolower($scope->commit)], ['version_id' => $version->id, 'created_at' => now()]);
            }
        });
    }

    /**
     * The unit's code at the run's commit (the environment's branch when the run has none); null when the source
     * can't say (no repository) or the code is not usable ({@see validate()} reports why).
     *
     * @param  array<string, mixed>  $body
     * @return ?array{files: array<string, string>, hash: string}
     *
     * @throws ValidationException with $strict
     */
    private function read(ConfigScope $scope, array $body, bool $strict = false): ?array
    {
        $ref = $scope->commit ?? $scope->branch;

        if ($scope->connectionId === null || $scope->repository === null || $ref === null) {
            if ($strict) {
                throw ValidationException::withMessages(['code' => 'A function unit\'s code comes from the repository: this run has no commit or branch to read it at.']);
            }

            return null;
        }

        $runtime = (string) ($body['runtime'] ?? config('functions.default_runtime', 'bun'));
        $entrypoint = (string) ($body['entrypoint'] ?? config("functions.runtimes.{$runtime}.entrypoint", 'index.ts'));

        try {
            return $this->code->read($scope->connectionId, $scope->repository, $ref, (string) ($body['root'] ?? '.'), $entrypoint);
        } catch (ValidationException $e) {
            if ($strict) {
                throw $e;
            }

            return null;
        }
    }

    /**
     * The file's fields as compared: durations and sizes in their shortest form.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private static function desiredLeaves(array $body): array
    {
        $leaves = Diff::leaves(array_intersect_key($body, array_flip(self::FIELDS)));

        foreach (self::DURATIONS as $path) {
            if (isset($leaves[$path]) && ($seconds = Units::seconds($leaves[$path])) !== null) {
                $leaves[$path] = Units::duration($seconds);
            }
        }

        if (isset($leaves['/memory']) && ($mib = Units::mebibytes($leaves['/memory'])) !== null) {
            $leaves['/memory'] = Units::size($mib);
        }

        return $leaves;
    }

    public function validate(ConfigScope $scope, array $resolved): array
    {
        $issues = [];
        $bounds = (array) config('functions.bounds');

        foreach ((array) ($resolved['units'] ?? []) as $unit => $body) {
            if (($body['type'] ?? null) !== 'function') {
                continue;
            }

            $base = '/units/'.Pointer::escape((string) $unit);
            $runtime = (string) ($body['runtime'] ?? '');

            if (! is_array(config("functions.runtimes.{$runtime}"))) {
                $issues[] = ConfigIssue::error('value.unsupported', "{$base}/runtime", "The {$runtime} runtime is not available; use one of ".implode(', ', array_keys((array) config('functions.runtimes'))).'.');

                continue;
            }

            foreach (['/scaling/idle_timeout' => 'idle_timeout_s', '/timeout' => 'request_timeout_s'] as $path => $column) {
                $value = Diff::at($body, $path);
                [$min, $max] = $bounds[$column] ?? [1, 86400];

                if ($value !== null && (($seconds = Units::seconds($value)) === null || $seconds < $min || $seconds > $max)) {
                    $issues[] = ConfigIssue::error('value.range', $base.$path, 'Must be '.Units::duration((int) $min).' to '.Units::duration((int) $max).'.');
                }
            }

            if (isset($body['memory'])) {
                [$min, $max] = $bounds['memory_mb'] ?? [64, 16384];
                $mib = Units::mebibytes($body['memory']);

                if ($mib === null || $mib < $min || $mib > $max) {
                    $issues[] = ConfigIssue::error('value.range', "{$base}/memory", 'Must be a whole number of MiB, '.Units::size((int) $min).' to '.Units::size((int) $max).'.');
                }
            }

            $min = $body['scaling']['min_instances'] ?? null;
            $max = $body['scaling']['max_instances'] ?? null;

            if ($min !== null && $max !== null && $min > $max) {
                $issues[] = ConfigIssue::error('value.conflict', "{$base}/scaling/min_instances", 'min_instances is above max_instances.');
            }

            if ($scope->connectionId !== null && $scope->repository !== null && ($scope->commit ?? $scope->branch) !== null) {
                try {
                    $runtimeEntry = (string) ($body['entrypoint'] ?? config("functions.runtimes.{$runtime}.entrypoint", 'index.ts'));
                    $this->code->read($scope->connectionId, $scope->repository, (string) ($scope->commit ?? $scope->branch), (string) ($body['root'] ?? '.'), $runtimeEntry);
                } catch (ValidationException $e) {
                    $issues[] = ConfigIssue::error('value.range', isset($body['root']) ? "{$base}/root" : $base, 'The function\'s code: '.collect($e->errors())->flatten()->first());
                }
            }
        }

        return $issues;
    }
}
