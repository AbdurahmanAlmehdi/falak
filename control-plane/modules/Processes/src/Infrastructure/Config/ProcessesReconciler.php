<?php

namespace Falak\Processes\Infrastructure\Config;

use DateTimeZone;
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
use Falak\Processes\Application\Actions\DeleteProcess;
use Falak\Processes\Application\Actions\SaveDaemon;
use Falak\Processes\Application\Actions\SaveSchedule;
use Falak\Processes\Application\Actions\SaveWorker;
use Falak\Processes\Domain\Models\Daemon;
use Falak\Processes\Domain\Models\Schedule;
use Falak\Processes\Domain\Models\Worker;
use Falak\Processes\Http\Requests\ProcessRules;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\SiteRuntime;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * falak.yaml's `workers`, `daemons` and `schedules` of app units (phase B: they go live with the unit's release).
 * Identity is the key: a daemon's or schedule's `name`, a worker's `name` (workers made in the dashboard have none
 * and read as `worker-<n>`; a file that names one adopts it).
 */
final class ProcessesReconciler implements Reconcilable, ValidatesConfig
{
    public const WORKERS = 'units.*(app).workers';

    public const DAEMONS = 'units.*(app).daemons';

    public const SCHEDULES = 'units.*(app).schedules';

    /** section key => [section, resource type, model] */
    private const KINDS = [
        'workers' => [self::WORKERS, 'worker', Worker::class],
        'daemons' => [self::DAEMONS, 'daemon', Daemon::class],
        'schedules' => [self::SCHEDULES, 'schedule', Schedule::class],
    ];

    /** @var array<string, array<string, array<string, array<string, array<string, string>>>>> env values the file wants, by environment, site, section and key */
    private array $env = [];

    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly ManagedResources $managed,
    ) {}

    public function sections(): array
    {
        return [self::WORKERS, self::DAEMONS, self::SCHEDULES];
    }

    public function phase(): ApplyPhase
    {
        return ApplyPhase::WithRelease;
    }

    public function current(ConfigScope $scope): array
    {
        $units = [];

        foreach ($this->appSites($scope) as $unit => $site) {
            $body = ['type' => 'app'];

            foreach (array_keys(self::KINDS) as $section) {
                $items = [];

                foreach ($this->items($site->id, $section) as $key => $model) {
                    $items[$key] = match (true) {
                        $model instanceof Worker => ProcessShapes::worker($model, $scope),
                        $model instanceof Daemon => ProcessShapes::daemon($model, $scope),
                        default => ProcessShapes::schedule($model),
                    };
                }

                if ($items !== []) {
                    $body[$section] = $items;
                }
            }

            $units[$unit] = $body;
        }

        return $units === [] ? [] : ['units' => $units];
    }

    public function diff(ConfigScope $scope, array $desired, array $current): ChangeSet
    {
        $this->rememberEnv($scope, $desired);
        $changes = [];
        $units = array_unique([...array_keys((array) ($desired['units'] ?? [])), ...array_keys((array) ($current['units'] ?? []))]);

        foreach ($units as $unit) {
            $unit = (string) $unit;
            $siteId = $scope->refId($unit);

            foreach (self::KINDS as $section => [$pattern, $type]) {
                $kind = rtrim($section, 's');
                $want = (array) ($desired['units'][$unit][$section] ?? []);
                $have = (array) ($current['units'][$unit][$section] ?? []);
                $models = $siteId !== null ? $this->items($siteId, $section) : [];
                $base = '/units/'.Pointer::escape($unit).'/'.$section.'/';

                foreach ($want as $key => $item) {
                    $key = (string) $key;
                    $item = ProcessShapes::normalize($kind, (array) $item);
                    $path = $base.Pointer::escape($key);

                    if (! array_key_exists($key, $have)) {
                        $changes[] = new Change(ChangeOp::Create, $path, null, ProcessShapes::masked($item), $pattern, $unit);

                        continue;
                    }

                    $before = $have[$key];

                    foreach ($item as $field => $value) {
                        $old = $before[$field] ?? null;

                        if ($field === 'env' || $field === 'limits') {
                            $old = is_array($old) ? $old : [];
                            ksort($old);
                        }

                        $same = Diff::same($value, $old);
                        $op = $same ? ChangeOp::Noop : ($old === null || $old === [] ? ChangeOp::Create : ChangeOp::Update);
                        [$b, $a] = $field === 'env' ? [array_map(fn () => '••••', $old), array_map(fn () => '••••', (array) $value)] : [$old, $value];
                        $changes[] = new Change($op, $path.'/'.Pointer::escape((string) $field), $b === [] ? null : $b, $a, $pattern, $unit);
                    }
                }

                foreach ($have as $key => $item) {
                    $key = (string) $key;

                    if (array_key_exists($key, $want) || ! isset($models[$key]) || ! $this->managed->managed($type, $models[$key]->getKey())->any()) {
                        continue;
                    }

                    $op = $this->managed->createdByFile($type, $models[$key]->getKey()) ? ChangeOp::Delete : ChangeOp::Detach;
                    $changes[] = new Change($op, $base.Pointer::escape($key), ProcessShapes::masked((array) $item), null, $pattern, $unit);
                }
            }
        }

        return new ChangeSet($changes);
    }

    public function apply(ConfigScope $scope, ChangeSet $changes, ApplyContext $ctx): void
    {
        $byItem = [];

        foreach ($changes->changes as $change) {
            $tokens = Pointer::tokens($change->path);

            if (count($tokens) < 4 || $tokens[0] !== 'units' || ! isset(self::KINDS[$tokens[2]])) {
                continue;
            }

            $byItem[$tokens[1]."\0".$tokens[2]."\0".$tokens[3]][] = $change;
        }

        foreach ($byItem as $id => $itemChanges) {
            [$unit, $section, $key] = explode("\0", $id);
            [, $type] = self::KINDS[$section];
            $siteId = $scope->refId($unit);
            $site = $siteId !== null ? $this->sites->find($siteId) : null;
            $model = $siteId !== null ? ($this->items($siteId, $section)[$key] ?? null) : null;
            $whole = $itemChanges[0]->path === '/units/'.Pointer::escape($unit).'/'.$section.'/'.Pointer::escape($key);

            if ($whole && in_array($itemChanges[0]->op, [ChangeOp::Delete, ChangeOp::Detach], true)) {
                if ($model !== null) {
                    if ($itemChanges[0]->op === ChangeOp::Delete && $site !== null) {
                        app(DeleteProcess::class)($site, $model);
                    }

                    $this->managed->forget($type, (string) $model->getKey());
                }

                continue;
            }

            if ($site === null) {
                throw new RuntimeException("The unit \"{$unit}\" has no site to run its {$section} on.");
            }

            $created = $model === null;
            $desired = $this->desiredItem($itemChanges);
            $changed = $created || array_filter($itemChanges, fn (Change $c) => $c->op !== ChangeOp::Noop) !== [];

            if ($changed) {
                $model = $this->save($scope, $site, $section, $key, $model, $desired, $ctx);
            }

            $this->managed->record($scope, $ctx, $unit, $type, (string) $model->getKey(), ['/'.$section.'/'.Pointer::escape($key)], $created ? true : null);
        }
    }

    public function validate(ConfigScope $scope, array $resolved): array
    {
        $issues = [];

        foreach ((array) ($resolved['units'] ?? []) as $unit => $body) {
            if (! is_array($body) || ($body['type'] ?? null) !== 'app') {
                continue;
            }

            $unitServers = (array) ($body['servers'] ?? $resolved['environments'][$scope->environment]['servers'] ?? []);

            foreach (['workers', 'daemons', 'schedules'] as $section) {
                foreach ((array) ($body[$section] ?? []) as $key => $item) {
                    $base = '/units/'.Pointer::escape((string) $unit).'/'.$section.'/'.Pointer::escape((string) $key);
                    $item = (array) $item;

                    foreach (self::bounds($section) as $field => [$min, $max]) {
                        if (! array_key_exists($field, $item)) {
                            continue;
                        }

                        $value = $field === 'memory' ? Units::mebibytes($item[$field]) : Units::seconds($item[$field]);

                        if ($value === null || $value < $min || $value > $max) {
                            $issues[] = ConfigIssue::error('value.range', "{$base}/{$field}", $field === 'memory'
                                ? 'Worker memory is a whole number of MiB from 32m to 64g.'
                                : 'Must be from '.Units::duration($min).' to '.Units::duration($max).'.');
                        }
                    }

                    if (isset($item['env']) && is_array($item['env'])) {
                        if (count($item['env']) > 50) {
                            $issues[] = ConfigIssue::error('value.range', "{$base}/env", 'At most 50 variables per process.');
                        }

                        foreach ($item['env'] as $k => $v) {
                            if (strlen((string) (is_bool($v) ? ($v ? 'true' : 'false') : $v)) > 4096) {
                                $issues[] = ConfigIssue::error('value.range', "{$base}/env/".Pointer::escape((string) $k), 'Values are at most 4096 characters.');
                            }
                        }
                    }

                    foreach ((array) ($item['servers'] ?? []) as $i => $server) {
                        if (! in_array($server, $unitServers, true)) {
                            $issues[] = ConfigIssue::error('name.server', "{$base}/servers/{$i}", "\"{$server}\" is not one of the unit's servers.");
                        }
                    }

                    if ($section === 'schedules') {
                        if (isset($item['cron']) && ! ProcessRules::validExpression((string) $item['cron'])) {
                            $issues[] = ConfigIssue::error('value.unsupported', "{$base}/cron", 'Not a valid cron expression (5 fields, @hourly … @yearly, or @every <duration>).');
                        }

                        if (isset($item['timezone']) && ! in_array($item['timezone'], DateTimeZone::listIdentifiers(), true)) {
                            $issues[] = ConfigIssue::error('value.unsupported', "{$base}/timezone", "\"{$item['timezone']}\" is not an IANA time zone.");
                        }
                    }
                }
            }
        }

        return $issues;
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    private static function bounds(string $section): array
    {
        return match ($section) {
            'workers' => ['timeout' => [0, 86400], 'sleep' => [0, 3600], 'backoff' => [0, 86400], 'max_time' => [0, 604800], 'memory' => [32, 65536]],
            'daemons' => ['stop_timeout' => [1, 3600]],
            default => ['timeout' => [1, 86400]],
        };
    }

    /**
     * The item the file wants, rebuilt from its changes (a create holds the whole item; updates and noops one field
     * each). Env values are masked in changes, so they are read from the run's resolved file instead.
     *
     * @param  list<Change>  $changes
     * @return array<string, mixed>
     */
    private function desiredItem(array $changes): array
    {
        $item = [];

        foreach ($changes as $change) {
            $tokens = Pointer::tokens($change->path);

            if (count($tokens) === 4) {
                $item = (array) $change->after;
            } elseif (count($tokens) === 5) {
                $item[$tokens[4]] = $change->after;
            }
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $desired
     */
    private function save(ConfigScope $scope, SiteData $site, string $section, string $key, ?Model $model, array $desired, ApplyContext $ctx): Model
    {
        $env = $this->env[$scope->environmentId][$site->id][$section][$key] ?? null;

        if ($env === null && array_key_exists('env', $desired)) {
            // Plans carry env keys only; apply() runs right after diff() of the same plan (phase B re-plans).
            throw new RuntimeException("The env of {$section}.{$key} is not known: plan the unit again before applying it.");
        }
        $serverIds = isset($desired['servers']) ? array_values(array_map(fn ($name) => $scope->serverId((string) $name) ?? (string) $name, (array) $desired['servers'])) : null;
        $envRows = fn (?array $current) => array_map(fn ($k, $v) => ['key' => (string) $k, 'value' => (string) $v], array_keys($env ?? $current ?? []), array_values($env ?? $current ?? []));

        if ($section === 'workers') {
            /** @var ?Worker $model */
            $model ??= new Worker(['organization_id' => $site->organizationId, 'site_id' => $site->id, 'created_by' => $ctx->actorId, 'name' => $key]);
            $model->name = $key;
            $data = [
                'connection' => $desired['connection'] ?? $model->connection,
                'queue' => isset($desired['queue']) ? implode(',', (array) $desired['queue']) : $model->queue,
                'command' => $desired['command'] ?? $model->command,
                'processes' => $desired['processes'] ?? $model->processes ?? 1,
                'timeout' => isset($desired['timeout']) ? Units::seconds($desired['timeout']) : ($model->timeout ?? 60),
                'sleep' => isset($desired['sleep']) ? Units::seconds($desired['sleep']) : ($model->sleep ?? 3),
                'tries' => $desired['tries'] ?? $model->tries ?? 1,
                'backoff' => isset($desired['backoff']) ? Units::seconds($desired['backoff']) : $model->backoff,
                'max_jobs' => $desired['max_jobs'] ?? $model->max_jobs,
                'max_time' => isset($desired['max_time']) ? Units::seconds($desired['max_time']) : $model->max_time,
                'memory' => isset($desired['memory']) ? Units::mebibytes($desired['memory']) : ($model->memory ?? 128),
                'env' => $envRows($model->exists ? $model->env : null),
                'server_ids' => $serverIds ?? $model->server_ids,
                ...(isset($desired['limits']) ? ['limits' => ProcessShapes::limitsFromFile((array) $desired['limits'])] : []),
            ];

            return app(SaveWorker::class)($site, $model, $data, $ctx->actorId);
        }

        if ($section === 'daemons') {
            /** @var ?Daemon $model */
            $model ??= new Daemon(['organization_id' => $site->organizationId, 'site_id' => $site->id, 'created_by' => $ctx->actorId]);
            $data = [
                'name' => $key,
                'command' => $desired['command'] ?? $model->command,
                'directory' => $desired['directory'] ?? $model->directory,
                'user' => $desired['user'] ?? $model->user,
                'instances' => $desired['instances'] ?? $model->instances ?? 1,
                'restart' => $desired['restart'] ?? $model->restart ?? 'always',
                'stop_signal' => $desired['stop_signal'] ?? $model->stop_signal ?? 'TERM',
                'stop_timeout' => isset($desired['stop_timeout']) ? Units::seconds($desired['stop_timeout']) : ($model->stop_timeout ?? 30),
                'env' => $envRows($model->exists ? $model->env : null),
                'server_ids' => $serverIds ?? $model->server_ids,
                ...(isset($desired['limits']) ? ['limits' => ProcessShapes::limitsFromFile((array) $desired['limits'])] : []),
            ];

            return app(SaveDaemon::class)($site, $model, $data, $ctx->actorId);
        }

        /** @var ?Schedule $model */
        $data = [
            'name' => $key,
            'command' => $desired['command'] ?? $model?->command,
            'expression' => $desired['cron'] ?? $model?->expression,
            'timezone' => $desired['timezone'] ?? $model?->timezone,
            'user' => $desired['user'] ?? $model?->user,
            'overlap' => $desired['overlap'] ?? $model?->overlap,
            'timeout' => isset($desired['timeout']) ? Units::seconds($desired['timeout']) : ($model?->timeout ?? 3600),
            'heartbeat' => $desired['heartbeat'] ?? $model?->heartbeat ?? true,
            'enabled' => $desired['enabled'] ?? $model?->enabled ?? true,
            'all_servers' => $desired['all_servers'] ?? $model?->all_servers ?? false,
        ];

        return app(SaveSchedule::class)($site, $model, $data, $ctx->actorId);
    }

    /**
     * Keep the env values of the file's items for apply (changes only carry masked env).
     *
     * @param  array<string, mixed>  $desired  the pruned desired document
     */
    private function rememberEnv(ConfigScope $scope, array $desired): void
    {
        foreach ((array) ($desired['units'] ?? []) as $unit => $body) {
            $siteId = $scope->refId((string) $unit);

            if ($siteId === null) {
                continue;
            }

            foreach (['workers', 'daemons'] as $section) {
                foreach ((array) ($body[$section] ?? []) as $key => $item) {
                    if (isset($item['env']) && is_array($item['env'])) {
                        $this->env[$scope->environmentId][$siteId][$section][(string) $key] = ProcessShapes::normalize('worker', ['env' => $item['env']])['env'];
                    }
                }
            }
        }
    }

    /**
     * The site services of the environment that are app units (not compose stacks or functions).
     *
     * @return array<string, SiteData>
     */
    private function appSites(ConfigScope $scope): array
    {
        $out = [];

        foreach ($scope->services as $unit => $service) {
            if ($service['kind'] !== 'site') {
                continue;
            }

            $site = $this->sites->find($service['ref_id']);

            if ($site !== null && ! in_array($site->runtime, [SiteRuntime::Compose, SiteRuntime::Function], true)) {
                $out[(string) $unit] = $site;
            }
        }

        return $out;
    }

    /**
     * A site's workers, daemons or schedules by key (unnamed workers: `worker-<n>` in creation order).
     *
     * @return array<string, Model>
     */
    private function items(string $siteId, string $section): array
    {
        $out = [];

        if ($section === 'workers') {
            $n = 0;

            foreach (Worker::query()->where('site_id', $siteId)->orderBy('created_at')->orderBy('id')->get() as $worker) {
                $key = $worker->name;

                if ($key === null || $key === '') {
                    do {
                        $key = 'worker-'.(++$n);
                    } while (Worker::query()->where('site_id', $siteId)->where('name', $key)->exists());
                }

                $out[$key] = $worker;
            }

            return $out;
        }

        $model = $section === 'daemons' ? Daemon::class : Schedule::class;

        foreach ($model::query()->where('site_id', $siteId)->orderBy('name')->get() as $row) {
            $out[(string) $row->name] = $row;
        }

        return $out;
    }
}
