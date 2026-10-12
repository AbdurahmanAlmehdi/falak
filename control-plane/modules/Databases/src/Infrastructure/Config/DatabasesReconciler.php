<?php

namespace Falak\Databases\Infrastructure\Config;

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
use Falak\Databases\Application\Actions\ConfigurePitr;
use Falak\Databases\Application\Actions\CreateDatabase;
use Falak\Databases\Application\Actions\CreateInstance;
use Falak\Databases\Application\Actions\DeleteBackupSchedule;
use Falak\Databases\Application\Actions\DeleteDatabase;
use Falak\Databases\Application\Actions\InstanceLifecycle;
use Falak\Databases\Application\Actions\SaveBackupSchedule;
use Falak\Databases\Application\Actions\UpdateInstance;
use Falak\Databases\Application\Actions\UpgradeInstance;
use Falak\Databases\Application\DrillQuery;
use Falak\Databases\Application\Identifiers;
use Falak\Databases\Contracts\BackupStorage;
use Falak\Databases\Domain\Enums\Engine;
use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Domain\Models\StorageProvider;
use Falak\Kernel\Security\BackupKeys;
use Falak\Projects\Contracts\PreviewEnvironments;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Volumes\Contracts\ServiceVolumes;
use Falak\Volumes\Contracts\VolumeKind;
use Illuminate\Validation\ValidationException;

/**
 * falak.yaml's `database` and `redis` units (phase A, before any build): the database container (engine, version,
 * server, limits, data volume size, settings, access), the databases in it, its backup schedule (the one named
 * `falak.yaml`) and point-in-time recovery.
 *
 * A unit is the environment's canvas service of that name (kind database); the file manages the container that
 * service's database lives in. A unit the file adds is created through CreateInstance (as the canvas does) and placed
 * on the canvas under the unit's key, so `${{ <unit>.DATABASE_URL }}` resolves to it. Passwords are Falak's: generated
 * at creation, never read, planned or exported here.
 *
 * Safety: the file never deletes a container, a database, a user or a data volume on its own. A unit removed from the
 * file is detached (no longer managed); with the run's allow_destroy (config.destroy) and only when the file created
 * it, the container is deleted (its data volume kept). Engine changes and moves to another server are risky and are
 * not applied by a run (the Databases page does them).
 */
final class DatabasesReconciler implements Reconcilable, ValidatesConfig
{
    public const SECTION = 'units.*(database|redis)';

    /** The backup schedule a unit's `backups` is ([decision] of the phase 0 contract). */
    public const SCHEDULE = 'falak.yaml';

    public const RESTART_NOTE = 'Restarts the database container to apply it (a few seconds without connections).';

    /** Fields whose change restarts the container (db.instance.update re-renders its config and restarts it). */
    private const RESTARTS = ['/memory', '/cpus', '/require_tls', '/public'];

    public function __construct(
        private readonly ManagedResources $managed,
        private readonly ServerDirectory $servers,
        private readonly ServiceVolumes $volumes,
        private readonly BackupStorage $storage,
        private readonly ProjectDirectory $projects,
        private readonly PreviewEnvironments $placement,
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

        foreach ($this->instances($scope) as $unit => $instance) {
            $units[$unit] = $this->unitOf($instance, $scope);
        }

        return $units === [] ? [] : ['units' => $units];
    }

    /**
     * The environment's database containers by unit key: its canvas services of kind database (one unit per container:
     * the unit the file manages it as, else the first service of it).
     *
     * @return array<string, DatabaseInstance>
     */
    public function instances(ConfigScope $scope): array
    {
        $owned = $this->managed->resources($scope->environmentId, 'database_instance');
        $out = [];
        $seen = [];

        foreach ($scope->services as $unit => $service) {
            if ($service['kind'] !== ServiceKind::Database->value) {
                continue;
            }

            $instance = Database::query()->with('instance')->find($service['ref_id'])?->instance;

            if ($instance === null || in_array($instance->status, [InstanceStatus::Retired, InstanceStatus::Deleting], true)) {
                continue;
            }

            if (isset($seen[$instance->id])) {
                if (($owned[$instance->id] ?? null) !== (string) $unit) {
                    continue;
                }

                unset($out[$seen[$instance->id]]);
            }

            $out[(string) $unit] = $instance;
            $seen[$instance->id] = (string) $unit;
        }

        return $out;
    }

    /**
     * A container in the file's shape. Defaults are left out where the file would leave them out (`public: false`,
     * `backups.enabled: true`, Falak-held keys, `databases` of a schedule covering all); diffs fill them back in.
     *
     * @return array<string, mixed>
     */
    public function unitOf(DatabaseInstance $instance, ConfigScope $scope): array
    {
        $keyValue = $instance->engine->isKeyValue();
        $unit = ['type' => $keyValue ? 'redis' : 'database', 'engine' => $instance->engine->value, 'version' => (string) $instance->version];
        $unit['server'] = $this->serverName($scope, $instance->server_id) ?? $instance->server_name;
        $unit['memory'] = Units::size(intdiv((int) $instance->memory_bytes, 1024 ** 2));

        if ($instance->cpus !== null) {
            $unit['cpus'] = $instance->cpus;
        }

        $disk = $instance->volume_id !== null ? $this->volumes->find($instance->volume_id)?->sizeLimitBytes : null;

        if ($disk !== null && $disk > 0) {
            $unit['disk'] = Units::size(intdiv($disk, 1024 ** 2));
        }

        if (! empty($instance->settings)) {
            $unit['settings'] = $instance->settings;
        }

        if ($instance->public_access) {
            $unit['public'] = true;
        }

        if (! empty($instance->allowed_sources)) {
            $unit['allowed_sources'] = array_values($instance->allowed_sources);
        }

        if ($instance->require_tls) {
            $unit['require_tls'] = true;
        }

        $databases = $this->databases($instance);

        if (! $keyValue) {
            $unit['databases'] = array_keys($databases);
        }

        $schedule = $this->schedule($instance);

        if ($schedule !== null) {
            $backups = ['cron' => $schedule->cron, 'storage' => (string) $schedule->storageProvider?->name];

            if ($schedule->retention_count !== null) {
                $backups['keep'] = $schedule->retention_count;
            }

            if ($schedule->retention_days !== null) {
                $backups['keep_days'] = $schedule->retention_days;
            }

            $names = $schedule->databases->pluck('name')->map(fn ($n) => (string) $n)->all();

            if (! $keyValue && array_diff(array_keys($databases), $names) !== []) {
                $backups['databases'] = $names;
            }

            if ($schedule->encryption_mode === BackupKeys::CUSTOMER) {
                $backups['encryption'] = ['mode' => BackupKeys::CUSTOMER, 'recipient' => (string) $schedule->age_recipient];
            }

            $backups['drill'] = $schedule->drill->value;

            if ($schedule->drill_query !== null) {
                $backups['drill_query'] = $schedule->drill_query;
            }

            if ($schedule->drill_server_id !== null) {
                $backups['drill_server'] = $this->serverName($scope, $schedule->drill_server_id) ?? $schedule->drill_server_id;
            }

            if (! $schedule->enabled) {
                $backups['enabled'] = false;
            }

            $unit['backups'] = $backups;
        }

        if (! $keyValue && $instance->pitr_storage_provider_id !== null) {
            $pitr = $instance->pitr_enabled ? [] : ['enabled' => false];
            $pitr['storage'] = (string) StorageProvider::query()->whereKey($instance->pitr_storage_provider_id)->value('name');
            $pitr['window_days'] = (int) $instance->pitr_window_days;
            $pitr['base_interval_days'] = (int) $instance->pitr_base_interval_days;

            if ($instance->pitr_encryption_mode === BackupKeys::CUSTOMER) {
                $pitr['encryption'] = ['mode' => BackupKeys::CUSTOMER, 'recipient' => (string) $instance->pitr_age_recipient];
            }

            $unit['pitr'] = $pitr;
        }

        return $unit;
    }

    public function diff(ConfigScope $scope, array $desired, array $current): ChangeSet
    {
        $changes = [];
        $instances = $this->instances($scope);

        foreach ((array) ($desired['units'] ?? []) as $unit => $body) {
            $unit = (string) $unit;
            $base = '/units/'.Pointer::escape($unit);
            $instance = $instances[$unit] ?? null;
            $wanted = self::normalize($unit, (array) $body, creating: $instance === null);

            if ($instance === null && $this->orphan($scope, $unit) !== null) {
                // The file manages a container for this unit that no canvas service points to any more: creating
                // another (empty) one would move the apps to it. Stage 4 reports value.conflict; nothing is planned.
                continue;
            }

            if ($instance === null) {
                $change = new Change(ChangeOp::Create, $base, null, $wanted, self::SECTION, $unit);
                // A new unit that is public publishes its port from the start: the database.public rule, on the whole unit.
                $changes[] = ($wanted['public'] ?? false) === true
                    ? $change->withRisk('database.public', 'Making a database public publishes its port to the internet.')
                    : $change;

                continue;
            }

            $today = self::comparable((array) ($current['units'][$unit] ?? []));
            $haveLeaves = Diff::leaves(array_diff_key($today, ['databases' => true, 'backups' => true]));

            foreach (Diff::leaves(array_diff_key($wanted, ['databases' => true, 'backups' => true])) as $path => $value) {
                $before = $haveLeaves[$path] ?? null;

                if ($path === '/allowed_sources' && is_array($value) && is_array($before)) {
                    $before = self::sameSet($value, $before) ? $value : $before;
                }

                $op = Diff::same($value, $before) ? ChangeOp::Noop : ($before === null ? ChangeOp::Create : ChangeOp::Update);
                $change = new Change($op, $base.$path, $before, $value, self::SECTION, $unit);

                if (($op === ChangeOp::Update || $op === ChangeOp::Create) && (in_array($path, self::RESTARTS, true) || str_starts_with($path, '/settings/'))) {
                    $change = $change->withNote(self::RESTART_NOTE);
                }

                $changes[] = $change;
            }

            // Databases: one per name. Names the file drops stay (detached); the file deletes only those it created,
            // with allow_destroy.
            $existing = (array) ($today['databases'] ?? []);

            foreach ((array) ($wanted['databases'] ?? []) as $name) {
                $changes[] = new Change(in_array($name, $existing, true) ? ChangeOp::Noop : ChangeOp::Create, $base.'/databases/'.Pointer::escape((string) $name), null, $name, self::SECTION, $unit);
            }

            foreach ($instance->engine->isKeyValue() ? [] : $this->databases($instance) as $name => $database) {
                if (in_array($name, (array) ($wanted['databases'] ?? []), true) || ! $this->managed->managedIn($scope->environmentId, 'database', $database->id)) {
                    continue;
                }

                // The database the unit's canvas service points to is never deleted: the service (and every
                // `${{ <unit>.… }}` reference) would go with it.
                $op = $scope->allowDestroy && $this->managed->createdByFile('database', $database->id, $scope->environmentId) && ! $this->isServiceDatabase($scope, $unit, $database)
                    ? ChangeOp::Delete : ChangeOp::Detach;
                $changes[] = new Change($op, $base.'/databases/'.Pointer::escape($name), $name, null, self::SECTION, $unit);
            }

            $changes = [...$changes, ...$this->diffBackups($scope, $unit, $base, $instance, $wanted['backups'] ?? null, $today['backups'] ?? null)];

            if (! isset($wanted['pitr']) && $this->managed->managedIn($scope->environmentId, 'database_instance', $instance->id) && $this->managed->managed('database_instance', $instance->id)->under('/pitr') !== []) {
                // PITR is never turned off by removing the block: it stays as it is, no longer managed.
                $changes[] = new Change(ChangeOp::Detach, $base.'/pitr', $today['pitr'] ?? null, null, self::SECTION, $unit);
            }
        }

        foreach ((array) ($current['units'] ?? []) as $unit => $body) {
            $unit = (string) $unit;
            $instance = $instances[$unit] ?? null;

            if (array_key_exists($unit, (array) ($desired['units'] ?? [])) || $instance === null || ! $this->managed->managedIn($scope->environmentId, 'database_instance', $instance->id)) {
                continue;
            }

            // Never deleted on its own: detached, unless the run allows destroying and the file created it.
            $op = $scope->allowDestroy && $this->managed->createdByFile('database_instance', $instance->id, $scope->environmentId) ? ChangeOp::Delete : ChangeOp::Detach;
            $changes[] = new Change($op, '/units/'.Pointer::escape($unit), ['type' => $body['type'] ?? 'database', 'engine' => $body['engine'] ?? null], null, self::SECTION, $unit);
        }

        return new ChangeSet($changes);
    }

    /**
     * @param  ?array<string, mixed>  $wanted
     * @param  ?array<string, mixed>  $today
     * @return list<Change>
     */
    private function diffBackups(ConfigScope $scope, string $unit, string $base, DatabaseInstance $instance, ?array $wanted, ?array $today): array
    {
        $schedule = $this->schedule($instance);

        if ($wanted === null) {
            if ($schedule === null || ! $this->managed->managedIn($scope->environmentId, 'backup_schedule', $schedule->id)) {
                return [];
            }

            $op = $scope->allowDestroy && $this->managed->createdByFile('backup_schedule', $schedule->id, $scope->environmentId) ? ChangeOp::Delete : ChangeOp::Detach;

            return [new Change($op, "{$base}/backups", $today, null, self::SECTION, $unit)];
        }

        if ($schedule === null || $today === null) {
            return [new Change(ChangeOp::Create, "{$base}/backups", null, $wanted, self::SECTION, $unit)];
        }

        $today['databases'] ??= array_keys($this->databases($instance));
        $have = Diff::leaves($today);
        $out = [];

        foreach (Diff::leaves($wanted) as $path => $value) {
            $before = $have[$path] ?? null;

            if ($path === '/databases' && is_array($value) && is_array($before) && self::sameSet($value, $before)) {
                $before = $value;
            }

            $op = Diff::same($value, $before) ? ChangeOp::Noop : ($before === null ? ChangeOp::Create : ChangeOp::Update);
            $out[] = new Change($op, "{$base}/backups{$path}", $before, $value, self::SECTION, $unit);
        }

        return $out;
    }

    public function apply(ConfigScope $scope, ChangeSet $changes, ApplyContext $ctx): void
    {
        $byUnit = [];

        foreach ($changes->changes as $change) {
            if ($change->unit !== null) {
                $byUnit[$change->unit][] = $change;
            }
        }

        $instances = $this->instances($scope);

        foreach ($byUnit as $unit => $list) {
            $unit = (string) $unit;
            $base = '/units/'.Pointer::escape($unit);
            $whole = collect($list)->first(fn (Change $c) => $c->path === $base);

            if ($whole?->op === ChangeOp::Create) {
                $this->create($scope, $ctx, $unit, (array) $whole->after);

                continue;
            }

            $instance = $instances[$unit] ?? null;

            if ($instance === null) {
                continue;
            }

            if ($whole?->op === ChangeOp::Delete && $ctx->allowDestroy && $this->managed->createdByFile('database_instance', $instance->id, $scope->environmentId)) {
                // The container goes; its data volume stays (deleted from the Volumes page once nobody needs it).
                app(InstanceLifecycle::class)->delete($instance, deleteVolume: false, actorId: $ctx->actorId, background: true);
                $this->forget($scope, $instance);

                continue;
            }

            if ($whole?->op === ChangeOp::Delete || $whole?->op === ChangeOp::Detach) {
                $this->detachInstance($scope, $ctx, $unit, $instance);
                $instance->forceFill(['status_message' => "Detached from {$this->fileName()} (unit {$unit}): it keeps running and keeps its data; it is no longer managed by the file."])->save();

                continue;
            }

            $this->update($scope, $ctx, $unit, $instance, $list);
        }
    }

    /**
     * @param  array<string, mixed>  $body  normalized
     */
    private function create(ConfigScope $scope, ApplyContext $ctx, string $unit, array $body): void
    {
        $engine = Engine::from((string) $body['engine']);
        $serverId = isset($body['server']) ? $scope->serverId((string) $body['server']) : ($scope->defaultServers[0] ?? null);

        if ($serverId === null) {
            throw ValidationException::withMessages(["units.{$unit}.server" => "Unit {$unit} has no server: set `server` on it or `servers` on the environment."]);
        }

        // Storage is checked against what the project uses before the container exists (a new production container
        // turns PITR on with the organization's first provider, which must not make that provider usable).
        $backups = isset($body['backups']) ? ['storage_provider_id' => $this->providerId($scope, (string) $body['backups']['storage'])] : [];
        $pitrStorage = isset($body['pitr']['storage']) ? $this->providerId($scope, (string) $body['pitr']['storage']) : null;

        // Default: one database named after the key (not managed by the file until it lists `databases`).
        $databases = $engine->isKeyValue() ? [] : array_values((array) ($body['databases'] ?? [str_replace('-', '_', $unit)]));
        $instance = app(CreateInstance::class)($scope->organizationId, $serverId, $engine, array_filter([
            'name' => $this->instanceName($serverId, $unit, $scope->environment),
            'version' => $body['version'] ?? null,
            'memory_mb' => isset($body['memory']) ? Units::mebibytes($body['memory']) : null,
            'cpus' => $body['cpus'] ?? null,
            'disk_gb' => isset($body['disk']) ? intdiv((int) Units::bytes($body['disk']), 1024 ** 3) : null,
            'environment_id' => $scope->environmentId,
            'settings' => $body['settings'] ?? null,
            'database' => $databases[0] ?? null,
            'public_access' => $body['public'] ?? null,
            'require_tls' => $body['require_tls'] ?? null,
            'allowed_sources' => $body['allowed_sources'] ?? null,
        ], fn ($v) => $v !== null), $ctx->actorId);

        $primary = $instance->databases()->reorder()->orderBy('created_at')->orderBy('id')->firstOrFail();
        [$x, $y] = $this->freeCell($scope->environmentId);
        // On the canvas under the unit's key: `${{ <unit>.DATABASE_URL }}` references resolve to it.
        $this->placement->place($scope->environmentId, ServiceKind::Database, $primary->id, $unit, $x, $y);

        foreach (array_slice($databases, 1) as $name) {
            $this->placeDatabase($scope, $unit, app(CreateDatabase::class)->withOwner($instance, (string) $name, $ctx->actorId));
        }

        $instance->refresh();

        if (isset($body['pitr'])) {
            app(ConfigurePitr::class)($instance, [...$this->pitrData($scope, array_diff_key((array) $body['pitr'], ['storage' => true])), 'storage_provider_id' => $pitrStorage], $ctx->actorId);
        }

        $paths = self::paths($body);
        $this->managed->record($scope, $ctx, $unit, 'database_instance', $instance->id, $paths, createdByFile: true);

        foreach ($instance->databases()->whereIn('name', (array) ($body['databases'] ?? []))->get() as $database) {
            $this->managed->record($scope, $ctx, $unit, 'database', $database->id, ['/databases/'.Pointer::escape($database->name)], createdByFile: true);
        }

        if (isset($body['backups'])) {
            $data = [...$this->scheduleData($scope, $instance, array_diff_key((array) $body['backups'], ['storage' => true]), null), ...$backups];
            $schedule = app(SaveBackupSchedule::class)($instance->refresh(), $data, null, $ctx->actorId);
            $this->managed->record($scope, $ctx, $unit, 'backup_schedule', $schedule->id, self::under($paths, '/backups'), createdByFile: true);
        }
    }

    /**
     * @param  list<Change>  $list
     */
    private function update(ConfigScope $scope, ApplyContext $ctx, string $unit, DatabaseInstance $instance, array $list): void
    {
        $base = '/units/'.Pointer::escape($unit);
        $desired = [];
        $changed = [];

        foreach ($list as $change) {
            $path = substr($change->path, strlen($base));

            if ($change->op === ChangeOp::Create || $change->op === ChangeOp::Update || $change->op === ChangeOp::Noop) {
                $desired[$path] = $change->after;
            }

            if ($change->op !== ChangeOp::Noop) {
                $changed[$path] = $change;
            }
        }

        foreach (['/engine' => 'Changing a database\'s engine replaces it and its data: create a new unit and move the data.', '/server' => 'Moving a database to another server moves its data: do it from the Databases page.'] as $path => $refusal) {
            if (($changed[$path] ?? null)?->op === ChangeOp::Update) {
                throw ValidationException::withMessages(["units.{$unit}".str_replace('/', '.', $path) => "{$unit}: {$refusal}"]);
            }
        }

        // Limits, settings and access: one db.instance.update.
        $update = [];
        $settings = [];

        foreach ($changed as $path => $change) {
            match (true) {
                $path === '/memory' => $update['memory_mb'] = Units::mebibytes($change->after),
                $path === '/cpus' => $update['cpus'] = $change->after,
                $path === '/public' => $update['public_access'] = (bool) $change->after,
                $path === '/require_tls' => $update['require_tls'] = (bool) $change->after,
                $path === '/allowed_sources' => $update['allowed_sources'] = (array) $change->after,
                str_starts_with($path, '/settings/') => $settings[Pointer::unescape(substr($path, 10))] = $change->after,
                default => null,
            };
        }

        if ($settings !== []) {
            $update['settings'] = $settings;
        }

        if ($update !== []) {
            app(UpdateInstance::class)($instance, $update, $ctx->actorId);
        }

        if (isset($changed['/disk'])) {
            $this->volumes->resize((string) $instance->volume_id, (int) Units::bytes($changed['/disk']->after), $ctx->actorId);
        }

        // A version change is the risky database.version rule: it only applies once approved.
        if (($changed['/version'] ?? null)?->op === ChangeOp::Update) {
            app(UpgradeInstance::class)($instance, (string) $changed['/version']->after, $ctx->actorId);
        }

        foreach ($changed as $path => $change) {
            if (! str_starts_with($path, '/databases/')) {
                continue;
            }

            $name = Pointer::unescape(substr($path, 11));

            if ($change->op === ChangeOp::Create) {
                $database = app(CreateDatabase::class)->withOwner($instance, $name, $ctx->actorId);
                $this->placeDatabase($scope, $unit, $database);
                $this->managed->record($scope, $ctx, $unit, 'database', $database->id, [$path], createdByFile: true);
            } elseif (($database = $instance->databases()->where('name', $name)->first()) !== null) {
                if ($change->op === ChangeOp::Delete && $ctx->allowDestroy && $this->managed->createdByFile('database', $database->id, $scope->environmentId) && ! $this->isServiceDatabase($scope, $unit, $database)) {
                    app(DeleteDatabase::class)($database);
                    $this->managed->forget('database', $database->id, $scope->environmentId);
                } else {
                    $this->managed->detach($scope, $ctx, $unit, 'database', $database->id, "Database {$database->name} ({$unit})",
                        route('databases.databases.destroy', ['database' => $database->id], false), deleteBody: ['confirm' => $database->name]);
                }
            }
        }

        foreach ($instance->databases()->get() as $database) {
            $path = '/databases/'.Pointer::escape($database->name);

            if (array_key_exists($path, $desired) && ! $this->managed->managedIn($scope->environmentId, 'database', $database->id)) {
                $this->managed->record($scope, $ctx, $unit, 'database', $database->id, [$path], createdByFile: false);
            }
        }

        $this->applyBackups($scope, $ctx, $unit, $instance, $changed, $desired);

        if (array_filter(array_keys($changed), fn (string $p) => str_starts_with($p, '/pitr/')) !== []) {
            app(ConfigurePitr::class)($instance->refresh(), $this->pitrData($scope, self::nested($desired, '/pitr')), $ctx->actorId);
        }

        $paths = [];

        foreach ($desired as $path => $value) {
            $paths = [...$paths, ...($path === '/backups' ? self::paths(['backups' => $value]) : [$path])];
        }

        $this->managed->record($scope, $ctx, $unit, 'database_instance', $instance->id, $paths);
    }

    /**
     * @param  array<string, Change>  $changed
     * @param  array<string, mixed>  $desired
     */
    private function applyBackups(ConfigScope $scope, ApplyContext $ctx, string $unit, DatabaseInstance $instance, array $changed, array $desired): void
    {
        $schedule = $this->schedule($instance);
        $whole = $changed['/backups'] ?? null;

        if ($whole !== null && ($whole->op === ChangeOp::Delete || $whole->op === ChangeOp::Detach)) {
            if ($schedule !== null) {
                if ($whole->op === ChangeOp::Delete && $ctx->allowDestroy && $this->managed->createdByFile('backup_schedule', $schedule->id, $scope->environmentId)) {
                    // Its backups stay in storage (pruned by retention only while a schedule exists).
                    app(DeleteBackupSchedule::class)($schedule);
                    $this->managed->forget('backup_schedule', $schedule->id, $scope->environmentId);
                } else {
                    $this->managed->detach($scope, $ctx, $unit, 'backup_schedule', $schedule->id, "Backup schedule of {$instance->name} ({$unit})",
                        route('databases.schedules.destroy', ['backupSchedule' => $schedule->id], false));
                }
            }

            return;
        }

        if ($whole?->op === ChangeOp::Create) {
            $schedule = app(SaveBackupSchedule::class)($instance, $this->scheduleData($scope, $instance, (array) $whole->after, null), null, $ctx->actorId);
            $this->managed->record($scope, $ctx, $unit, 'backup_schedule', $schedule->id, self::paths(['backups' => $whole->after]), createdByFile: true);

            return;
        }

        $fields = self::nested($desired, '/backups');

        if ($schedule === null || $fields === []) {
            return;
        }

        if (array_filter(array_keys($changed), fn (string $p) => str_starts_with($p, '/backups/')) !== []) {
            app(SaveBackupSchedule::class)($instance, $this->scheduleData($scope, $instance, $fields, $schedule), $schedule, $ctx->actorId);
        }

        $this->managed->record($scope, $ctx, $unit, 'backup_schedule', $schedule->id, self::paths(['backups' => $fields]));
    }

    /**
     * SaveBackupSchedule's input: the file's fields over the schedule's (the file manages only what it sets).
     *
     * @param  array<string, mixed>  $backups
     * @return array<string, mixed>
     */
    private function scheduleData(ConfigScope $scope, DatabaseInstance $instance, array $backups, ?BackupSchedule $schedule): array
    {
        $databases = $instance->databases()->get();
        $ids = isset($backups['databases'])
            ? $databases->whereIn('name', (array) $backups['databases'])->pluck('id')->all()
            : ($schedule !== null ? $schedule->databases()->pluck('databases_databases.id')->all() : $databases->pluck('id')->all());
        $storage = isset($backups['storage']) ? $this->providerId($scope, (string) $backups['storage']) : $schedule?->storage_provider_id;

        $data = [
            'name' => self::SCHEDULE,
            'storage_provider_id' => (string) $storage,
            'database_ids' => array_values(array_map('strval', $ids)),
            'cron' => (string) ($backups['cron'] ?? $schedule?->cron),
            'retention_count' => array_key_exists('keep', $backups) ? $backups['keep'] : $schedule?->retention_count,
            'retention_days' => array_key_exists('keep_days', $backups) ? $backups['keep_days'] : $schedule?->retention_days,
            'enabled' => (bool) ($backups['enabled'] ?? $schedule?->enabled ?? true),
        ];

        if (isset($backups['encryption']['mode'])) {
            $data['encryption_mode'] = (string) $backups['encryption']['mode'];
            $data['age_recipient'] = $backups['encryption']['recipient'] ?? null;
        }

        if (isset($backups['drill'])) {
            $data['drill'] = (string) $backups['drill'];
        }

        if (array_key_exists('drill_query', $backups)) {
            $data['drill_query'] = $backups['drill_query'];
        }

        if (isset($backups['drill_server'])) {
            $data['drill_server_id'] = $scope->serverId((string) $backups['drill_server'])
                ?? throw ValidationException::withMessages(['backups.drill_server' => "No server named \"{$backups['drill_server']}\" is attached to this project."]);
        }

        return $data;
    }

    /**
     * ConfigurePitr's input (present ⇒ enabled unless `enabled: false`).
     *
     * @param  array<string, mixed>  $pitr
     * @return array<string, mixed>
     */
    private function pitrData(ConfigScope $scope, array $pitr): array
    {
        $data = ['enabled' => (bool) ($pitr['enabled'] ?? true)];

        if (isset($pitr['storage'])) {
            $data['storage_provider_id'] = $this->providerId($scope, (string) $pitr['storage']);
        }

        foreach (['window_days', 'base_interval_days'] as $field) {
            if (isset($pitr[$field])) {
                $data[$field] = (int) $pitr[$field];
            }
        }

        if (isset($pitr['encryption']['mode'])) {
            $data['encryption_mode'] = (string) $pitr['encryption']['mode'];
            $data['age_recipient'] = $pitr['encryption']['recipient'] ?? null;
        }

        return $data;
    }

    /**
     * A storage provider the project may use, by name (names-only: see {@see BackupStorage::namesForProject()}).
     *
     * @throws ValidationException
     */
    private function providerId(ConfigScope $scope, string $name): string
    {
        if (! in_array($name, $this->storage->namesForProject($scope->organizationId, $scope->projectId, $scope->credentials), true)) {
            throw ValidationException::withMessages(['storage' => "The storage provider \"{$name}\" can't be used by this project's file."]);
        }

        return (string) StorageProvider::query()->where('organization_id', $scope->organizationId)->where('name', $name)->value('id');
    }

    public function validate(ConfigScope $scope, array $resolved): array
    {
        $issues = [];
        $current = null;
        $instances = $this->instances($scope);
        $storage = null;
        $services = [];

        foreach ($this->projects->servicesIn($scope->environmentId) as $service) {
            $services[$service->name] = $service->kind;
        }

        foreach ((array) ($resolved['units'] ?? []) as $unit => $body) {
            $type = $body['type'] ?? null;

            if ($type !== 'database' && $type !== 'redis') {
                continue;
            }

            $unit = (string) $unit;
            $base = '/units/'.Pointer::escape($unit);
            $instance = $instances[$unit] ?? null;
            $engine = isset($body['engine']) ? Engine::tryFrom((string) $body['engine']) : ($instance?->engine ?? ($type === 'redis' ? Engine::Redis : null));

            if ($instance === null && ($services[$unit] ?? null) === ServiceKind::Site) {
                $issues[] = ConfigIssue::error('value.conflict', $base, "The service \"{$unit}\" of this environment is a site, not a database: rename the unit.");
            }

            if ($instance === null && ($orphan = $this->orphan($scope, $unit)) !== null) {
                $issues[] = ConfigIssue::error('value.conflict', $base, "The file manages {$unit} as the database server {$orphan->name}, but no service \"{$unit}\" of this environment points to it any more: place one of its databases on the canvas as \"{$unit}\" again (or remove the unit from the file to detach it).");
            }

            if ($engine === null) {
                continue; // the schema reports it
            }

            if ($instance !== null && $instance->engine->isKeyValue() !== $engine->isKeyValue()) {
                $issues[] = ConfigIssue::error('value.conflict', "{$base}/type", "\"{$unit}\" is a {$instance->engine->label()} database: its type can't change.");
            }

            // [decision] What a run never does is a validation error, not a risk an approval could wave through.
            $today = $instance !== null ? ($current ??= (array) ($this->current($scope)['units'] ?? []))[$unit] ?? null : null;

            if ($today !== null && isset($body['engine'], $today['engine']) && $body['engine'] !== $today['engine'] && $instance->engine->isKeyValue() === $engine->isKeyValue()) {
                $issues[] = ConfigIssue::error('value.forbidden', "{$base}/engine", "{$unit} runs {$instance->engine->label()}: a new engine is a new unit. Add one, restore a backup into it from the Databases page, then remove {$unit}.");
            }

            if ($today !== null && isset($body['server'], $today['server']) && $body['server'] !== $today['server']) {
                $issues[] = ConfigIssue::error('value.forbidden', "{$base}/server", "{$unit} runs on {$today['server']}: move it from the Databases page (relocation restores it from its backups); the file can't move a database.");
            }

            if ($today !== null && isset($body['disk'], $today['disk']) && ($want = Units::bytes($body['disk'])) !== null && ($have = Units::bytes($today['disk'])) !== null && $want < $have) {
                $issues[] = ConfigIssue::error('value.forbidden', "{$base}/disk", "{$unit}'s disk is {$today['disk']}: disks only grow. Keep {$today['disk']} or more (a smaller disk is a new unit restored from a backup).");
            }

            if (isset($body['version'])) {
                $version = (string) $body['version'];

                if (! in_array($version, $engine->versions(), true)) {
                    $issues[] = ConfigIssue::error('value.unsupported', "{$base}/version", "{$engine->label()} {$version} is not available; use one of ".implode(', ', $engine->versions()).'.');
                } elseif ($engine->pinnedDigest($version) === null) {
                    $issues[] = ConfigIssue::error('value.unsupported', "{$base}/version", "This Falak release ships no image of {$engine->label()} {$version}.");
                } elseif ($instance !== null && $instance->engine === $engine && version_compare($version, (string) $instance->version, '<')) {
                    $issues[] = ConfigIssue::error('value.unsupported', "{$base}/version", "{$unit} runs {$engine->label()} {$instance->version}: databases are never downgraded (restore a backup into a new unit instead).");
                } elseif ($instance !== null && $instance->engine === $engine && $version !== (string) $instance->version) {
                    // A major upgrade moves the data to a new container: nothing else of the unit changes with it.
                    $others = array_filter($this->diff($scope, ['units' => [$unit => $body]], $this->current($scope))->actionable(),
                        fn (Change $c) => $c->unit === $unit && $c->path !== "{$base}/version");

                    if ($others !== []) {
                        $issues[] = ConfigIssue::error('value.conflict', "{$base}/version", "Upgrade {$unit} to {$engine->label()} {$version} in its own push: this file also changes ".implode(', ', array_map(fn (Change $c) => substr($c->path, strlen($base) + 1), array_values($others))).'.');
                    }
                }
            }

            if (isset($body['memory'])) {
                $mib = Units::mebibytes($body['memory']);
                $bytes = $mib !== null ? $mib * 1024 ** 2 : null;

                if ($bytes === null) {
                    $issues[] = ConfigIssue::error('value.range', "{$base}/memory", 'Memory must be a whole number of MiB.');
                } elseif ($bytes < $engine->minMemory() || $bytes > (int) config('databases.memory.max')) {
                    $issues[] = ConfigIssue::error('value.range', "{$base}/memory", "{$engine->label()} needs between ".Units::size(intdiv($engine->minMemory(), 1024 ** 2)).' and '.Units::size(intdiv((int) config('databases.memory.max'), 1024 ** 2)).' of memory.');
                }
            }

            if (isset($body['disk'])) {
                $bytes = Units::bytes($body['disk']);

                if (! is_int($bytes) || $bytes % (1024 ** 3) !== 0) {
                    $issues[] = ConfigIssue::error('value.range', "{$base}/disk", 'The disk must be a whole number of GiB (e.g. 20g).');
                } elseif ($bytes < (int) config('databases.disk.min') || $bytes > (int) config('databases.disk.max')) {
                    $issues[] = ConfigIssue::error('value.range', "{$base}/disk", 'The disk must be between 1g and 16t.');
                } elseif ($instance !== null && ($instance->volume_id === null || $this->volumes->find($instance->volume_id)?->kind !== VolumeKind::Sized)) {
                    $issues[] = ConfigIssue::error('value.unsupported', "{$base}/disk", "{$unit}'s data is not on a sized volume: its disk has no size to set.");
                }
            }

            foreach ((array) ($body['settings'] ?? []) as $key => $value) {
                try {
                    UpdateInstance::settings($engine, [(string) $key => $value]);
                } catch (ValidationException $e) {
                    $issues[] = ConfigIssue::error('value.unsupported', "{$base}/settings/".Pointer::escape((string) $key), (string) collect($e->errors())->flatten()->first());
                }
            }

            if (isset($body['allowed_sources'])) {
                try {
                    UpdateInstance::sources((array) $body['allowed_sources']);
                } catch (ValidationException $e) {
                    $key = (string) array_key_first($e->errors());
                    $issues[] = ConfigIssue::error('value.range', "{$base}/allowed_sources/".(explode('.', $key)[1] ?? '0'), (string) collect($e->errors())->flatten()->first());
                }
            }

            if (isset($body['server']) && $scope->serverId((string) $body['server']) === null) {
                $issues[] = self::server("{$base}/server", (string) $body['server']);
            } elseif (! isset($body['server']) && $instance === null && $scope->defaultServers === []) {
                $issues[] = ConfigIssue::error('name.server', $base, "Unit {$unit} needs a server: set `server` on it or `servers` on the environment.");
            }

            $names = isset($body['databases']) ? array_map('strval', (array) $body['databases'])
                : ($instance !== null ? array_keys($this->databases($instance)) : [str_replace('-', '_', $unit)]);

            foreach ((array) ($body['databases'] ?? []) as $i => $name) {
                try {
                    Identifiers::assertValid($engine, (string) $name, 'name');
                } catch (ValidationException $e) {
                    $issues[] = ConfigIssue::error('value.unsupported', "{$base}/databases/{$i}", (string) collect($e->errors())->flatten()->first());
                }
            }

            $storage ??= $this->storage->namesForProject($scope->organizationId, $scope->projectId, $scope->credentials);

            if (isset($body['backups'])) {
                $backups = (array) $body['backups'];

                if (isset($backups['storage']) && ! in_array((string) $backups['storage'], $storage, true)) {
                    $issues[] = self::storage("{$base}/backups/storage", (string) $backups['storage']);
                }

                if (isset($backups['drill_server']) && $scope->serverId((string) $backups['drill_server']) === null) {
                    $issues[] = self::server("{$base}/backups/drill_server", (string) $backups['drill_server']);
                }

                foreach ((array) ($backups['databases'] ?? []) as $i => $name) {
                    if (! in_array((string) $name, $names, true)) {
                        $issues[] = ConfigIssue::error('value.conflict', "{$base}/backups/databases/{$i}", "\"{$name}\" is not one of {$unit}'s databases.");
                    }
                }

                if (isset($backups['drill_query'])) {
                    $problem = $engine->isKeyValue() ? 'Redis and Valkey drills check the key count; there is no query.' : DrillQuery::problem((string) $backups['drill_query']);

                    if ($problem !== null) {
                        $issues[] = ConfigIssue::error('value.unsupported', "{$base}/backups/drill_query", $problem);
                    }
                }
            }

            if (isset($body['pitr'])) {
                $pitr = (array) $body['pitr'];

                if ($engine->isKeyValue()) {
                    $issues[] = ConfigIssue::error('value.unsupported', "{$base}/pitr", 'Point-in-time recovery is for PostgreSQL, MySQL and MariaDB.');
                }

                if (isset($pitr['storage']) && ! in_array((string) $pitr['storage'], $storage, true)) {
                    $issues[] = self::storage("{$base}/pitr/storage", (string) $pitr['storage']);
                }

                $window = (int) ($pitr['window_days'] ?? $instance?->pitr_window_days ?? config('databases.pitr.window_days', 7));
                $interval = (int) ($pitr['base_interval_days'] ?? $instance?->pitr_base_interval_days ?? config('databases.pitr.base_interval_days', 7));

                if ($interval > $window) {
                    $issues[] = ConfigIssue::error('value.range', "{$base}/pitr/base_interval_days", "Take a base backup at least once per window (every 1 to {$window} days).");
                }
            }
        }

        return $issues;
    }

    /**
     * The file's unit as compared and applied: sizes in their shortest form, sources normalized, PITR present ⇒ enabled;
     * a redis unit to create gets its default engine.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private static function normalize(string $unit, array $body, bool $creating): array
    {
        if (($body['type'] ?? null) === 'redis' && $creating) {
            $body['engine'] ??= Engine::Redis->value;
        }

        foreach (['memory', 'disk'] as $field) {
            if (isset($body[$field]) && ($mib = Units::mebibytes($body[$field])) !== null) {
                $body[$field] = Units::size($mib);
            }
        }

        if (isset($body['allowed_sources'])) {
            try {
                $body['allowed_sources'] = UpdateInstance::sources((array) $body['allowed_sources']);
            } catch (ValidationException) {
                // stage 4 reports it
            }
        }

        if (isset($body['settings']) && is_array($body['settings'])) {
            ksort($body['settings']);
        }

        if (isset($body['pitr']) && is_array($body['pitr'])) {
            $body['pitr'] = ['enabled' => true, ...$body['pitr']];
        }

        return $body;
    }

    /**
     * Today's unit with the defaults current() leaves out filled back in (so `public: false` in the file is a noop).
     *
     * @param  array<string, mixed>  $today
     * @return array<string, mixed>
     */
    private static function comparable(array $today): array
    {
        $today['public'] ??= false;
        $today['require_tls'] ??= false;

        if (isset($today['backups'])) {
            $today['backups']['enabled'] ??= true;
            $today['backups']['encryption'] ??= ['mode' => BackupKeys::CP];
        }

        if (isset($today['pitr'])) {
            $today['pitr']['enabled'] ??= true;
            $today['pitr']['encryption'] ??= ['mode' => BackupKeys::CP];
        }

        return $today;
    }

    /**
     * The instance's databases by name, oldest first (the first is the canvas service's).
     *
     * @return array<string, Database>
     */
    private function databases(DatabaseInstance $instance): array
    {
        $out = [];

        foreach ($instance->databases()->reorder()->whereNot('status', ResourceStatus::Deleting)->orderBy('created_at')->orderBy('id')->get() as $database) {
            $out[(string) $database->name] = $database;
        }

        return $out;
    }

    /** Whether $database is the one the unit's canvas service points to. */
    private function isServiceDatabase(ConfigScope $scope, string $unit, Database $database): bool
    {
        return ($scope->services[$unit]['ref_id'] ?? null) === $database->id;
    }

    /**
     * A live container the environment's file manages as $unit although no canvas service of that name maps to it (its
     * service was deleted or unlinked), or null.
     */
    private function orphan(ConfigScope $scope, string $unit): ?DatabaseInstance
    {
        foreach ($this->managed->resources($scope->environmentId, 'database_instance') as $instanceId => $owner) {
            if ($owner !== $unit) {
                continue;
            }

            $instance = DatabaseInstance::query()->find($instanceId);

            if ($instance !== null && ! in_array($instance->status, [InstanceStatus::Retired, InstanceStatus::Deleting], true)) {
                return $instance;
            }
        }

        return null;
    }

    /**
     * Put a database a unit adds on the canvas of the unit's environment (`<unit>-<name>`), not the organization's
     * default project where DatabaseCreated would place it.
     */
    private function placeDatabase(ConfigScope $scope, string $unit, Database $database): void
    {
        [$x, $y] = $this->freeCell($scope->environmentId);
        $this->placement->place($scope->environmentId, ServiceKind::Database, $database->id, $unit.'-'.str_replace('_', '-', strtolower($database->name)), $x, $y);
    }

    private function schedule(DatabaseInstance $instance): ?BackupSchedule
    {
        return BackupSchedule::query()->with(['storageProvider', 'databases'])->where('database_instance_id', $instance->id)->where('name', self::SCHEDULE)->first();
    }

    /** Detached with its unit: the container (listed as unmanaged), its databases and its schedule (no longer managed). */
    private function detachInstance(ConfigScope $scope, ApplyContext $ctx, string $unit, DatabaseInstance $instance): void
    {
        $this->forget($scope, $instance);
        $this->managed->detach($scope, $ctx, $unit, 'database_instance', $instance->id, "Database server {$instance->name} ({$unit})",
            route('databases.instances.destroy', ['instance' => $instance->id], false), deleteBody: ['confirm' => $instance->name]);
    }

    /** No longer managed: the container, its databases and its schedule. */
    private function forget(ConfigScope $scope, DatabaseInstance $instance): void
    {
        $this->managed->forget('database_instance', $instance->id, $scope->environmentId);

        foreach ($instance->databases()->get() as $database) {
            $this->managed->forget('database', $database->id, $scope->environmentId);
        }

        if (($schedule = $this->schedule($instance)) !== null) {
            $this->managed->forget('backup_schedule', $schedule->id, $scope->environmentId);
        }
    }

    /**
     * Ownership paths of a unit's fields (relative to the unit): leaves, one per database and the backups' leaves.
     *
     * @param  array<string, mixed>  $body
     * @return list<string>
     */
    private static function paths(array $body): array
    {
        $paths = array_keys(Diff::leaves(array_diff_key($body, ['databases' => true])));

        foreach ((array) ($body['databases'] ?? []) as $name) {
            $paths[] = '/databases/'.Pointer::escape((string) $name);
        }

        return $paths;
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private static function under(array $paths, string $prefix): array
    {
        return array_values(array_filter($paths, fn (string $p) => str_starts_with($p, $prefix.'/')));
    }

    /**
     * Pointer-keyed leaves under a prefix back into a nested map (/backups/encryption/mode → ['encryption' => ['mode' => …]]).
     *
     * @param  array<string, mixed>  $leaves
     * @return array<string, mixed>
     */
    private static function nested(array $leaves, string $prefix): array
    {
        $out = [];

        foreach ($leaves as $path => $value) {
            if (! str_starts_with($path, $prefix.'/')) {
                continue;
            }

            $cur = &$out;

            foreach (Pointer::tokens(substr($path, strlen($prefix))) as $token) {
                if (! isset($cur[$token]) || ! is_array($cur[$token])) {
                    $cur[$token] = [];
                }

                $cur = &$cur[$token];
            }

            $cur = $value;
            unset($cur);
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $a
     * @param  list<mixed>  $b
     */
    private static function sameSet(array $a, array $b): bool
    {
        $a = array_map('strval', $a);
        $b = array_map('strval', $b);
        sort($a);
        sort($b);

        return $a === $b;
    }

    private function serverName(ConfigScope $scope, string $serverId): ?string
    {
        return $scope->serverName($serverId) ?? $this->servers->find($serverId)?->name;
    }

    /** An instance name for a new unit: the key, else `<key>-<environment>` (names are unique per server). */
    private function instanceName(string $serverId, string $unit, string $environment): string
    {
        $taken = fn (string $name) => DatabaseInstance::query()->where('server_id', $serverId)->where('name', $name)->whereNot('status', InstanceStatus::Retired)->exists();
        $clip = fn (string $name) => rtrim(substr($name, 0, 41), '-_');
        $name = $clip($unit);

        if (! $taken($name)) {
            return $name;
        }

        $base = $clip("{$unit}-{$environment}");

        for ($i = 2, $name = $base; $taken($name); $i++) {
            $name = $clip(substr($base, 0, 41 - strlen("-{$i}"))."-{$i}");
        }

        return $name;
    }

    /**
     * A free cell of the canvas grid (Projects' LinkService layout: 4 columns of 300 × 160).
     *
     * @return array{0: int, 1: int}
     */
    private function freeCell(string $environmentId): array
    {
        $occupied = [];

        foreach ($this->projects->servicesIn($environmentId) as $service) {
            $occupied[intdiv($service->x, 300).':'.intdiv($service->y, 160)] = true;
        }

        for ($cell = 0; ; $cell++) {
            [$column, $row] = [$cell % 4, intdiv($cell, 4)];

            if (! isset($occupied["{$column}:{$row}"])) {
                return [$column * 300, $row * 160];
            }
        }
    }

    private function fileName(): string
    {
        return 'falak.yaml';
    }

    private static function server(string $pointer, string $name): ConfigIssue
    {
        return ConfigIssue::error('name.server', $pointer, "No server named \"{$name}\" is attached to this project (a server hosting one of its services).");
    }

    private static function storage(string $pointer, string $name): ConfigIssue
    {
        return ConfigIssue::error('name.storage', $pointer, "The storage provider \"{$name}\" can't be used here: it must exist in the organization and already hold backups of this project, or be allowed on the project's config source by an admin.");
    }
}
