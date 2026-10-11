<?php

namespace Falak\Volumes\Infrastructure\Config;

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
use Falak\Databases\Contracts\BackupStorage;
use Falak\Deployments\Contracts\DeploymentDirectory;
use Falak\Kernel\Security\BackupKeys;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Volumes\Application\Actions\AttachVolume;
use Falak\Volumes\Application\Actions\CreateVolume;
use Falak\Volumes\Application\Actions\DeleteVolume;
use Falak\Volumes\Application\Actions\DetachVolume;
use Falak\Volumes\Application\Actions\ResizeVolume;
use Falak\Volumes\Application\Actions\SaveBackupSchedule;
use Falak\Volumes\Application\Actions\UpdateVolume;
use Falak\Volumes\Contracts\AttachableType;
use Falak\Volumes\Contracts\VolumeKind;
use Falak\Volumes\Domain\Enums\Consistency;
use Falak\Volumes\Domain\Enums\VolumeStatus;
use Falak\Volumes\Domain\Models\Attachment;
use Falak\Volumes\Domain\Models\BackupSchedule;
use Falak\Volumes\Domain\Models\Volume;
use Illuminate\Validation\ValidationException;

/**
 * falak.yaml's `units.<app>.volumes` (phase A, after Sites so a unit the file adds has its site): docker and sized
 * volumes mounted into the unit's container, their protection and their backup schedule.
 *
 * A volume of a unit is a docker or sized volume attached to the unit's site; its key is the one the file manages it
 * under (config_ownership), else its name. The file never deletes a volume on its own: a volume removed from the file
 * is unmounted from the unit and kept with its data (detached); with the run's allow_destroy and only when the file
 * created it, it is deleted (never while protected). Volumes only grow; moving one to another server is done from the
 * Volumes page.
 *
 * [decision] Compose units' volumes are declared by their compose file (SyncComposeVolumes attaches them when the stack
 * goes live): `volumes` of compose units stays unsupported (a warning) until compose stacks can mount Falak volumes.
 */
final class VolumesReconciler implements Reconcilable, ValidatesConfig
{
    public const SECTION = 'units.*(app).volumes';

    public function __construct(
        private readonly ManagedResources $managed,
        private readonly SiteDirectory $sites,
        private readonly ServerDirectory $servers,
        private readonly BackupStorage $storage,
        private readonly DeploymentDirectory $deployments,
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

        foreach ($this->sitesOf($scope) as $unit => $site) {
            $type = match ($site->runtime) {
                SiteRuntime::Compose => 'compose',
                SiteRuntime::Function => 'function',
                default => 'app',
            };
            $volumes = [];

            foreach ($this->volumesOf($site->id) as $key => [$volume, $attachment]) {
                $volumes[$key] = $this->shape($scope, $volume, $attachment);
            }

            if ($volumes !== []) {
                $units[$unit] = ['type' => $type, 'volumes' => $volumes];
            }
        }

        return $units === [] ? [] : ['units' => $units];
    }

    /**
     * Docker and sized volumes mounted into a site's container, by key (the file's key when it manages one, else the
     * volume's name). Compose stacks' own volumes, host paths and shared paths are not the file's.
     *
     * @return array<string, array{0: Volume, 1: Attachment}>
     */
    public function volumesOf(string $siteId): array
    {
        $out = [];
        $attachments = Attachment::query()->with('volume.schedules')->where('attachable_type', AttachableType::Site)->where('attachable_id', strtolower($siteId))
            ->orderBy('created_at')->orderBy('id')->get();

        foreach ($attachments as $attachment) {
            $volume = $attachment->volume;

            if ($volume === null || ! in_array($volume->kind, [VolumeKind::Docker, VolumeKind::Sized], true) || $volume->composeKey() !== null || $volume->status === VolumeStatus::Deleting) {
                continue;
            }

            $key = $this->keyOf($volume) ?? $volume->name;

            if (! isset($out[$key])) {
                $out[$key] = [$volume, $attachment];
            }
        }

        return $out;
    }

    /**
     * A volume in the file's shape (defaults left out: docker, read-write, unprotected, enabled, consistency none,
     * Falak-held keys).
     *
     * @return array<string, mixed>
     */
    private function shape(ConfigScope $scope, Volume $volume, Attachment $attachment): array
    {
        $out = [];

        if ($volume->kind === VolumeKind::Sized) {
            $out['kind'] = 'sized';
            $out['size'] = Units::size(intdiv((int) $volume->size_limit_bytes, 1024 ** 2));
        }

        $out['mount'] = $attachment->mount_path;

        if ($attachment->read_only) {
            $out['read_only'] = true;
        }

        if ($volume->protected) {
            $out['protected'] = true;
        }

        $out['server'] = $this->serverName($scope, (string) $volume->server_id) ?? (string) $volume->server_id;
        $schedule = $this->schedule($volume);

        if ($schedule !== null) {
            $backups = ['cron' => $schedule->cron, 'storage' => (string) ($schedule->storage_provider_id !== null ? $this->storage->find($volume->organization_id, $schedule->storage_provider_id)?->name : '')];

            if ($schedule->retention_count !== null) {
                $backups['keep'] = $schedule->retention_count;
            }

            if ($schedule->retention_days !== null) {
                $backups['keep_days'] = $schedule->retention_days;
            }

            if ($schedule->consistency !== Consistency::None) {
                $backups['consistency'] = $schedule->consistency->value;
            }

            if ($schedule->encryption_mode === BackupKeys::CUSTOMER) {
                $backups['encryption'] = ['mode' => BackupKeys::CUSTOMER, 'recipient' => (string) $schedule->age_recipient];
            }

            $backups['drill'] = $schedule->drill->value;

            if ($schedule->drill_server_id !== null) {
                $backups['drill_server'] = $this->serverName($scope, $schedule->drill_server_id) ?? $schedule->drill_server_id;
            }

            if (! $schedule->enabled) {
                $backups['enabled'] = false;
            }

            $out['backups'] = $backups;
        }

        return $out;
    }

    public function diff(ConfigScope $scope, array $desired, array $current): ChangeSet
    {
        $changes = [];

        foreach ((array) ($desired['units'] ?? []) as $unit => $body) {
            $unit = (string) $unit;
            $have = (array) ($current['units'][$unit]['volumes'] ?? []);

            foreach ((array) ($body['volumes'] ?? []) as $key => $volume) {
                $key = (string) $key;
                $base = '/units/'.Pointer::escape($unit).'/volumes/'.Pointer::escape($key);
                $wanted = self::normalize((array) $volume);

                if (! isset($have[$key])) {
                    $changes[] = new Change(ChangeOp::Create, $base, null, $wanted, self::SECTION, $unit);

                    continue;
                }

                $today = self::comparable((array) $have[$key]);

                foreach (Diff::leaves(array_diff_key($wanted, ['backups' => true])) as $path => $value) {
                    $before = Diff::leaves(array_diff_key($today, ['backups' => true]))[$path] ?? null;
                    $op = Diff::same($value, $before) ? ChangeOp::Noop : ($before === null ? ChangeOp::Create : ChangeOp::Update);
                    $changes[] = new Change($op, $base.$path, $before, $value, self::SECTION, $unit);
                }

                $changes = [...$changes, ...$this->diffBackups($scope, $unit, $key, $base, $wanted['backups'] ?? null, $today)];
            }

            foreach ($have as $key => $volume) {
                if (! array_key_exists($key, (array) ($body['volumes'] ?? []))) {
                    $changes = [...$changes, ...$this->removed($scope, $unit, (string) $key, (array) $volume)];
                }
            }
        }

        foreach ((array) ($current['units'] ?? []) as $unit => $body) {
            if (array_key_exists($unit, (array) ($desired['units'] ?? []))) {
                continue;
            }

            foreach ((array) ($body['volumes'] ?? []) as $key => $volume) {
                $changes = [...$changes, ...$this->removed($scope, (string) $unit, (string) $key, (array) $volume)];
            }
        }

        // Volumes a run unmounted to delete them: deleted once their unit runs without them.
        foreach ($this->pendingDeletes($scope) as [$unit, $key, $volume]) {
            if (! array_key_exists($key, (array) ($desired['units'][$unit]['volumes'] ?? [])) && $scope->allowDestroy && $this->unmountedEverywhere($scope, $unit, $volume)) {
                $changes[] = new Change(ChangeOp::Delete, '/units/'.Pointer::escape($unit).'/volumes/'.Pointer::escape($key), ['unmounted' => true], null, self::SECTION, $unit);
            }
        }

        return new ChangeSet($changes);
    }

    /**
     * A volume the file no longer lists: detached (unmounted, kept with its data) when the file managed it; deleted only
     * with allow_destroy and when the file created it.
     *
     * @param  array<string, mixed>  $before
     * @return list<Change>
     */
    private function removed(ConfigScope $scope, string $unit, string $key, array $before): array
    {
        $volume = $this->find($scope, $unit, $key);

        if ($volume === null || ! $this->managed->managedIn($scope->environmentId, 'volume', $volume->id)) {
            return [];
        }

        $op = $scope->allowDestroy && $this->managed->createdByFile('volume', $volume->id, $scope->environmentId) ? ChangeOp::Delete : ChangeOp::Detach;

        return [new Change($op, '/units/'.Pointer::escape($unit).'/volumes/'.Pointer::escape($key), $before, null, self::SECTION, $unit)];
    }

    /**
     * @param  ?array<string, mixed>  $wanted
     * @param  array<string, mixed>  $today
     * @return list<Change>
     */
    private function diffBackups(ConfigScope $scope, string $unit, string $key, string $base, ?array $wanted, array $today): array
    {
        $volume = $this->find($scope, $unit, $key);
        $schedule = $volume !== null ? $this->schedule($volume) : null;

        if ($wanted === null) {
            if ($schedule === null || ! $this->managed->managedIn($scope->environmentId, 'backup_schedule', $schedule->id)) {
                return [];
            }

            $op = $scope->allowDestroy && $this->managed->createdByFile('backup_schedule', $schedule->id, $scope->environmentId) ? ChangeOp::Delete : ChangeOp::Detach;

            return [new Change($op, "{$base}/backups", $today['backups'] ?? null, null, self::SECTION, $unit)];
        }

        if (! isset($today['backups'])) {
            return [new Change(ChangeOp::Create, "{$base}/backups", null, $wanted, self::SECTION, $unit)];
        }

        $have = Diff::leaves((array) $today['backups']);
        $out = [];

        foreach (Diff::leaves($wanted) as $path => $value) {
            $before = $have[$path] ?? null;

            // A drill on the volume's own server is stored as "no drill server".
            if ($path === '/drill_server' && $before === null && $value === ($today['server'] ?? null)) {
                $before = $value;
            }

            $op = Diff::same($value, $before) ? ChangeOp::Noop : ($before === null ? ChangeOp::Create : ChangeOp::Update);
            $out[] = new Change($op, "{$base}/backups{$path}", $before, $value, self::SECTION, $unit);
        }

        return $out;
    }

    public function apply(ConfigScope $scope, ChangeSet $changes, ApplyContext $ctx): void
    {
        $byVolume = [];

        foreach ($changes->changes as $change) {
            $tokens = Pointer::tokens($change->path);

            if (count($tokens) >= 4 && $tokens[0] === 'units' && $tokens[2] === 'volumes') {
                $byVolume[$tokens[1]][$tokens[3]][] = $change;
            }
        }

        foreach ($byVolume as $unit => $volumes) {
            foreach ($volumes as $key => $list) {
                $this->applyVolume($scope, $ctx, (string) $unit, (string) $key, $list);
            }
        }
    }

    /**
     * @param  list<Change>  $list
     */
    private function applyVolume(ConfigScope $scope, ApplyContext $ctx, string $unit, string $key, array $list): void
    {
        $base = '/units/'.Pointer::escape($unit).'/volumes/'.Pointer::escape($key);
        $prefix = '/volumes/'.Pointer::escape($key);
        $whole = collect($list)->first(fn (Change $c) => $c->path === $base);

        if ($whole?->op === ChangeOp::Create) {
            $this->create($scope, $ctx, $unit, $key, (array) $whole->after);

            return;
        }

        $volume = $this->find($scope, $unit, $key);

        if ($volume === null) {
            return;
        }

        if ($whole?->op === ChangeOp::Delete || $whole?->op === ChangeOp::Detach) {
            $this->detach($scope, $ctx, $unit, $volume, destroy: $whole->op === ChangeOp::Delete && $ctx->allowDestroy && $this->managed->createdByFile('volume', $volume->id, $scope->environmentId));

            return;
        }

        $desired = [];
        $changed = [];

        foreach ($list as $change) {
            $path = substr($change->path, strlen($base));

            if ($change->op !== ChangeOp::Delete && $change->op !== ChangeOp::Detach) {
                $desired[$path] = $change->after;
            }

            if ($change->op !== ChangeOp::Noop) {
                $changed[$path] = $change;
            }
        }

        foreach (['/kind' => 'A volume\'s kind can\'t change: use a new volume key.', '/server' => 'Moving a volume to another server moves its data: do it from the Volumes page.'] as $path => $refusal) {
            if (($changed[$path] ?? null)?->op === ChangeOp::Update) {
                throw ValidationException::withMessages(["units.{$unit}.volumes.{$key}" => "{$unit}/{$key}: {$refusal}"]);
            }
        }

        if (isset($changed['/size'])) {
            // Volumes only grow: ResizeVolume refuses anything smaller (and the risky volume.shrink rule holds it first).
            app(ResizeVolume::class)($volume, (int) Units::bytes($changed['/size']->after), $ctx->actorId);
        }

        if (isset($changed['/protected'])) {
            app(UpdateVolume::class)($volume, protected: (bool) $changed['/protected']->after);
        }

        $siteId = $scope->refId($unit);

        if ($siteId !== null && (isset($changed['/mount']) || isset($changed['/read_only']))) {
            $attachment = $volume->attachments()->where('attachable_type', AttachableType::Site)->where('attachable_id', $siteId)->first();
            $mount = (string) ($desired['/mount'] ?? $attachment?->mount_path);
            $readOnly = (bool) ($desired['/read_only'] ?? $attachment?->read_only ?? false);

            // The unit deploys with the plan (its volume changed): no redeploy of its own.
            if ($attachment !== null) {
                app(DetachVolume::class)($attachment, $ctx->actorId, redeploy: false);
            }

            app(AttachVolume::class)($volume->refresh(), $siteId, $mount, $readOnly, $ctx->actorId, redeploy: false);
        }

        $this->applyBackups($scope, $ctx, $unit, $prefix, $volume, $changed, $desired);

        $paths = [];

        foreach ($desired as $path => $value) {
            $paths = [...$paths, ...($path === '/backups' ? self::paths($prefix.'/backups', (array) $value) : [$prefix.$path])];
        }

        $this->managed->record($scope, $ctx, $unit, 'volume', $volume->id, $paths);
    }

    /**
     * @param  array<string, mixed>  $body  normalized
     */
    private function create(ConfigScope $scope, ApplyContext $ctx, string $unit, string $key, array $body): void
    {
        $siteId = $scope->refId($unit);
        $site = $siteId !== null ? $this->sites->find($siteId) : null;

        if ($site === null) {
            throw ValidationException::withMessages(["units.{$unit}.volumes.{$key}" => "Unit {$unit} has no site to mount {$key} into."]);
        }

        $serverId = isset($body['server']) ? $scope->serverId((string) $body['server']) : ($site->leader()?->serverId ?? $scope->defaultServers[0] ?? null);

        if ($serverId === null) {
            throw ValidationException::withMessages(["units.{$unit}.volumes.{$key}.server" => "{$unit}/{$key}: no server for the volume."]);
        }

        $prefix = '/volumes/'.Pointer::escape($key);
        $pending = $this->find($scope, $unit, $key);

        if ($pending !== null && $pending->composeKey() === null) {
            // The file lists again a volume a run unmounted to delete it: it is mounted again, never replaced by an
            // empty one.
            $pending->forceFill(['options' => array_diff_key((array) $pending->options, ['config_delete' => true]) ?: null, 'status_message' => null])->save();
            app(AttachVolume::class)($pending, $site->id, (string) $body['mount'], (bool) ($body['read_only'] ?? false), $ctx->actorId, redeploy: false);
            $this->managed->record($scope, $ctx, $unit, 'volume', $pending->id, self::paths($prefix, $body), merge: true);

            return;
        }

        $kind = VolumeKind::from((string) ($body['kind'] ?? VolumeKind::Docker->value));
        $volume = app(CreateVolume::class)(
            $scope->organizationId,
            $serverId,
            $this->volumeName($serverId, $unit, $key, $scope->environment),
            $kind,
            $kind === VolumeKind::Sized ? (int) Units::bytes($body['size'] ?? null) : null,
            protected: (bool) ($body['protected'] ?? false),
            actorId: $ctx->actorId,
        );

        // The new unit (or the unit with a new volume) deploys with this run: no redeploy of its own.
        app(AttachVolume::class)($volume, $site->id, (string) $body['mount'], (bool) ($body['read_only'] ?? false), $ctx->actorId, redeploy: false);
        $this->managed->record($scope, $ctx, $unit, 'volume', $volume->id, self::paths($prefix, $body), createdByFile: true);

        if (isset($body['backups'])) {
            $schedule = $this->saveSchedule($scope, $ctx, $volume->refresh(), (array) $body['backups'], null);
            $this->managed->record($scope, $ctx, $unit, 'backup_schedule', $schedule->id, self::paths($prefix.'/backups', (array) $body['backups']), createdByFile: true);
        }
    }

    /**
     * @param  array<string, Change>  $changed
     * @param  array<string, mixed>  $desired
     */
    private function applyBackups(ConfigScope $scope, ApplyContext $ctx, string $unit, string $prefix, Volume $volume, array $changed, array $desired): void
    {
        $schedule = $this->schedule($volume);
        $whole = $changed['/backups'] ?? null;

        if ($whole !== null && ($whole->op === ChangeOp::Delete || $whole->op === ChangeOp::Detach)) {
            if ($schedule !== null) {
                if ($whole->op === ChangeOp::Delete && $ctx->allowDestroy && $this->managed->createdByFile('backup_schedule', $schedule->id, $scope->environmentId)) {
                    $schedule->delete(); // its backups stay in storage
                    $this->managed->forget('backup_schedule', $schedule->id, $scope->environmentId);
                } else {
                    $this->managed->detach($scope, $ctx, $unit, 'backup_schedule', $schedule->id, "Backup schedule of volume {$volume->name} ({$unit})",
                        route('volumes.schedules.destroy', ['schedule' => $schedule->id], false));
                }
            }

            return;
        }

        if ($whole?->op === ChangeOp::Create) {
            $schedule = $this->saveSchedule($scope, $ctx, $volume, (array) $whole->after, null);
            $this->managed->record($scope, $ctx, $unit, 'backup_schedule', $schedule->id, self::paths($prefix.'/backups', (array) $whole->after), createdByFile: true);

            return;
        }

        $fields = self::nested($desired, '/backups');

        if ($schedule === null || $fields === []) {
            return;
        }

        if (array_filter(array_keys($changed), fn (string $p) => str_starts_with($p, '/backups/')) !== []) {
            $schedule = $this->saveSchedule($scope, $ctx, $volume, $fields, $schedule);
        }

        $this->managed->record($scope, $ctx, $unit, 'backup_schedule', $schedule->id, self::paths($prefix.'/backups', $fields));
    }

    /**
     * SaveBackupSchedule with the file's fields over the schedule's (the file manages only what it sets).
     *
     * @param  array<string, mixed>  $backups
     */
    private function saveSchedule(ConfigScope $scope, ApplyContext $ctx, Volume $volume, array $backups, ?BackupSchedule $schedule): BackupSchedule
    {
        $storage = isset($backups['storage']) ? $this->providerId($scope, (string) $backups['storage']) : (string) $schedule?->storage_provider_id;
        $drillServer = null;

        if (isset($backups['drill_server'])) {
            $drillServer = $scope->serverId((string) $backups['drill_server'])
                ?? throw ValidationException::withMessages(['backups.drill_server' => "No server named \"{$backups['drill_server']}\" is attached to this project."]);
        }

        return app(SaveBackupSchedule::class)(
            $volume,
            $storage,
            (string) ($backups['cron'] ?? $schedule?->cron),
            array_key_exists('keep', $backups) ? $backups['keep'] : $schedule?->retention_count,
            array_key_exists('keep_days', $backups) ? $backups['keep_days'] : $schedule?->retention_days,
            isset($backups['consistency']) ? Consistency::from((string) $backups['consistency']) : ($schedule->consistency ?? Consistency::None),
            (bool) ($backups['enabled'] ?? $schedule?->enabled ?? true),
            $schedule,
            $ctx->actorId,
            isset($backups['encryption']['mode']) ? (string) $backups['encryption']['mode'] : null,
            isset($backups['encryption']['mode']) ? ($backups['encryption']['recipient'] ?? '') : null,
            isset($backups['drill']) ? (string) $backups['drill'] : null,
            $drillServer,
        );
    }

    /**
     * Unmount a volume the file no longer lists and stop managing it; its data stays (the unit deploys without it).
     * $destroy (allow_destroy, created by the file): it is deleted, but never while a running release may still mount
     * it: a mounted volume is unmounted and marked, and a later run with allow_destroy deletes it once the unit runs
     * without it (DeleteVolume refuses protected volumes).
     */
    private function detach(ConfigScope $scope, ApplyContext $ctx, string $unit, Volume $volume, bool $destroy): void
    {
        $siteId = $scope->refId($unit);
        $unmounted = false;

        foreach ($volume->attachments()->where('attachable_type', AttachableType::Site)->get() as $attachment) {
            /** @var Attachment $attachment */
            if ($siteId === null || $attachment->attachable_id === $siteId) {
                app(DetachVolume::class)($attachment, $ctx->actorId, redeploy: false);
                $unmounted = true;
            }
        }

        if ($destroy && ($unmounted || ! $this->unmountedEverywhere($scope, $unit, $volume))) {
            if (app(DeleteVolume::class)->refusal($volume) !== null) {
                throw ValidationException::withMessages(["units.{$unit}.volumes" => (string) app(DeleteVolume::class)->refusal($volume)]);
            }

            $volume->forceFill([
                'options' => [...(array) $volume->options, 'config_delete' => ['unit' => $unit, 'environment_id' => $scope->environmentId, 'unmounted_at' => now()->toIso8601String()]],
                'status_message' => "Unmounted from falak.yaml (unit {$unit}): a later run with allow_destroy deletes it once {$unit} runs without it.",
            ])->save();

            return; // still managed: the deletion is pending
        }

        foreach ($volume->schedules()->get() as $schedule) {
            $this->managed->forget('backup_schedule', $schedule->id, $scope->environmentId);
        }

        if ($destroy) {
            app(DeleteVolume::class)($volume->refresh(), $ctx->actorId);
            $this->managed->forget('volume', $volume->id, $scope->environmentId);
        } else {
            $volume->forceFill(['status_message' => "Detached from falak.yaml (unit {$unit}): its data is kept; it is no longer managed by the file."])->save();
            $this->managed->detach($scope, $ctx, $unit, 'volume', $volume->id, "Volume {$volume->name} ({$unit})",
                route('volumes.destroy', ['volume' => $volume->id], false), deleteBody: ['confirm' => $volume->name]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function providerId(ConfigScope $scope, string $name): string
    {
        if (! in_array($name, $this->storage->namesForProject($scope->organizationId, $scope->projectId, $scope->credentials), true)) {
            throw ValidationException::withMessages(['storage' => "The storage provider \"{$name}\" can't be used by this project's file."]);
        }

        foreach ($this->storage->providers($scope->organizationId) as $provider) {
            if ($provider->name === $name) {
                return $provider->id;
            }
        }

        throw ValidationException::withMessages(['storage' => "No storage provider named \"{$name}\"."]);
    }

    public function validate(ConfigScope $scope, array $resolved): array
    {
        $issues = [];
        $storage = null;
        $current = $this->current($scope)['units'] ?? [];

        foreach ((array) ($resolved['units'] ?? []) as $unit => $body) {
            if (($body['type'] ?? null) !== 'app' || ! isset($body['volumes'])) {
                continue;
            }

            $unit = (string) $unit;
            $base = '/units/'.Pointer::escape($unit).'/volumes';
            $runtime = isset($body['runtime']) ? SiteRuntime::tryFrom((string) $body['runtime']) : null;

            if ($runtime !== SiteRuntime::Docker) {
                $issues[] = ConfigIssue::error('value.unsupported', $base, 'Volumes are mounted into containers: only units with the docker runtime have them (classic sites keep data in shared paths).');

                continue;
            }

            $unitServers = isset($body['servers']) ? array_map('strval', (array) $body['servers'])
                : array_values(array_filter(array_map(fn (string $id) => $scope->serverName($id), $scope->defaultServers)));
            $mounts = [];

            foreach ((array) $body['volumes'] as $key => $volume) {
                $key = (string) $key;
                $pointer = $base.'/'.Pointer::escape($key);
                $volume = (array) $volume;
                $kind = (string) ($volume['kind'] ?? 'docker');
                $today = $current[$unit]['volumes'][$key] ?? null;

                if (isset($volume['service'])) {
                    $issues[] = ConfigIssue::error('value.unsupported', "{$pointer}/service", '`service` is for compose units only.');
                }

                if (isset($volume['size']) && $kind !== 'sized') {
                    $issues[] = ConfigIssue::error('value.unsupported', "{$pointer}/size", 'Only sized volumes have a size (kind: sized).');
                } elseif (isset($volume['size'])) {
                    $mib = Units::mebibytes($volume['size']);
                    $bytes = $mib !== null ? $mib * 1024 ** 2 : null;

                    if ($bytes === null) {
                        $issues[] = ConfigIssue::error('value.range', "{$pointer}/size", 'The size must be a whole number of MiB.');
                    } elseif ($bytes < (int) config('volumes.min_size_bytes') || $bytes > (int) config('volumes.max_size_bytes')) {
                        $issues[] = ConfigIssue::error('value.range', "{$pointer}/size", 'A sized volume holds between 16m and 16t.');
                    }
                }

                if ($today !== null && ($today['kind'] ?? 'docker') !== $kind) {
                    $issues[] = ConfigIssue::error('value.conflict', "{$pointer}/kind", "{$key} is a ".($today['kind'] ?? 'docker').' volume: its kind can\'t change (use a new key).');
                }

                if (isset($volume['mount'])) {
                    try {
                        $mount = AttachVolume::mountPath((string) $volume['mount']);

                        if (isset($mounts[$mount])) {
                            $issues[] = ConfigIssue::error('value.conflict', "{$pointer}/mount", "{$mounts[$mount]} is already mounted at {$mount}.");
                        }

                        $mounts[$mount] = $key;
                    } catch (ValidationException $e) {
                        $issues[] = ConfigIssue::error('value.unsupported', "{$pointer}/mount", (string) collect($e->errors())->flatten()->first());
                    }
                }

                if (isset($volume['server'])) {
                    if ($scope->serverId((string) $volume['server']) === null) {
                        $issues[] = self::server("{$pointer}/server", (string) $volume['server']);
                    } elseif ($unitServers !== [] && ! in_array((string) $volume['server'], $unitServers, true)) {
                        $issues[] = ConfigIssue::error('value.conflict', "{$pointer}/server", "{$unit} doesn't run on {$volume['server']}: a volume is mounted on one of its unit's servers.");
                    }
                }

                if (isset($volume['backups'])) {
                    $backups = (array) $volume['backups'];
                    $storage ??= $this->storage->namesForProject($scope->organizationId, $scope->projectId, $scope->credentials);

                    if (isset($backups['storage']) && ! in_array((string) $backups['storage'], $storage, true)) {
                        $issues[] = ConfigIssue::error('name.storage', "{$pointer}/backups/storage", "The storage provider \"{$backups['storage']}\" can't be used here: it must exist in the organization and already hold backups of this project, or be allowed on the project's config source by an admin.");
                    }

                    if (isset($backups['drill_server']) && $scope->serverId((string) $backups['drill_server']) === null) {
                        $issues[] = self::server("{$pointer}/backups/drill_server", (string) $backups['drill_server']);
                    }
                }
            }
        }

        return $issues;
    }

    /**
     * The file's volume as compared and applied: sizes in their shortest form, the mount path normalized.
     *
     * @param  array<string, mixed>  $volume
     * @return array<string, mixed>
     */
    private static function normalize(array $volume): array
    {
        if (isset($volume['size']) && ($mib = Units::mebibytes($volume['size'])) !== null) {
            $volume['size'] = Units::size($mib);
        }

        if (isset($volume['mount'])) {
            try {
                $volume['mount'] = AttachVolume::mountPath((string) $volume['mount']);
            } catch (ValidationException) {
                // stage 4 reports it
            }
        }

        return $volume;
    }

    /**
     * Today's volume with the defaults current() leaves out filled back in.
     *
     * @param  array<string, mixed>  $today
     * @return array<string, mixed>
     */
    private static function comparable(array $today): array
    {
        $today['kind'] ??= VolumeKind::Docker->value;
        $today['read_only'] ??= false;
        $today['protected'] ??= false;

        if (isset($today['backups'])) {
            $today['backups']['enabled'] ??= true;
            $today['backups']['consistency'] ??= Consistency::None->value;
            $today['backups']['encryption'] ??= ['mode' => BackupKeys::CP];
        }

        return $today;
    }

    /**
     * App units' sites by unit key (the environment's canvas services of kind site).
     *
     * @return array<string, SiteData>
     */
    private function sitesOf(ConfigScope $scope): array
    {
        $out = [];

        foreach ($scope->services as $unit => $service) {
            if ($service['kind'] === 'site' && ($site = $this->sites->find($service['ref_id'])) !== null) {
                $out[(string) $unit] = $site;
            }
        }

        return $out;
    }

    /**
     * Volumes of the environment a run unmounted to delete them (still managed, marked config_delete).
     *
     * @return list<array{0: string, 1: string, 2: Volume}> unit, key, volume
     */
    private function pendingDeletes(ConfigScope $scope): array
    {
        $out = [];

        foreach ($this->managed->resources($scope->environmentId, 'volume') as $volumeId => $unit) {
            $volume = Volume::query()->find($volumeId);

            if ($unit !== null && $volume !== null && isset($volume->options['config_delete']) && ($key = $this->keyOf($volume)) !== null && $volume->status !== VolumeStatus::Deleting) {
                $out[] = [(string) $unit, $key, $volume];
            }
        }

        return $out;
    }

    /**
     * No running release may mount the volume any more: it has no attachment, and its unit's site is gone or its last
     * deployment, started after the volume was unmounted, succeeded.
     */
    private function unmountedEverywhere(ConfigScope $scope, string $unit, Volume $volume): bool
    {
        if ($volume->attachments()->exists()) {
            return false;
        }

        $siteId = $scope->refId($unit);

        if ($siteId === null || $this->sites->find($siteId) === null) {
            return true;
        }

        $since = $volume->options['config_delete']['unmounted_at'] ?? null;
        $deployment = $this->deployments->currentForSites([$siteId])[$siteId] ?? null;

        return $since !== null && $deployment !== null && $deployment->status === 'succeeded' && $deployment->createdAt->getTimestamp() >= strtotime((string) $since);
    }

    /** A unit's volume by key: mounted into its site, else the one the file manages under that key. */
    private function find(ConfigScope $scope, string $unit, string $key): ?Volume
    {
        $siteId = $scope->refId($unit);

        if ($siteId !== null && ($found = $this->volumesOf($siteId)[$key] ?? null) !== null) {
            return $found[0];
        }

        foreach ($this->managed->resources($scope->environmentId, 'volume') as $volumeId => $owner) {
            if ($owner === $unit && ($volume = Volume::query()->find($volumeId)) !== null && $this->keyOf($volume) === $key) {
                return $volume;
            }
        }

        return null;
    }

    /** The key the file manages a volume under (from its ownership paths: /volumes/<key>/…), or null. */
    private function keyOf(Volume $volume): ?string
    {
        foreach ($this->managed->managed('volume', $volume->id)->paths as $path) {
            $tokens = Pointer::tokens($path);

            if (($tokens[0] ?? null) === 'volumes' && isset($tokens[1])) {
                return $tokens[1];
            }
        }

        return null;
    }

    /** [decision] The file's schedule of a volume is its oldest one; other schedules stay the dashboard's. */
    private function schedule(Volume $volume): ?BackupSchedule
    {
        return BackupSchedule::query()->where('volume_id', $volume->id)->orderBy('created_at')->orderBy('id')->first();
    }

    /**
     * Ownership paths: the leaves of a volume (or of its backups) under $prefix.
     *
     * @param  array<string, mixed>  $value
     * @return list<string>
     */
    private static function paths(string $prefix, array $value): array
    {
        return array_map(fn (string $p) => $prefix.$p, array_keys(Diff::leaves($value)));
    }

    /**
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

    /** A volume name for a new key: the key, else `<unit>-<key>`, then `-<environment>` (names are unique per server). */
    private function volumeName(string $serverId, string $unit, string $key, string $environment): string
    {
        $taken = fn (string $name) => Volume::query()->where('server_id', $serverId)->where('name', $name)->exists();
        $clip = fn (string $name) => rtrim(substr($name, 0, 63), '-_.');

        foreach ([$key, "{$unit}-{$key}", "{$unit}-{$key}-{$environment}"] as $candidate) {
            if (! $taken($clip($candidate))) {
                return $clip($candidate);
            }
        }

        $base = $clip("{$unit}-{$key}-{$environment}");

        for ($i = 2; ; $i++) {
            $name = substr($base, 0, 63 - strlen("-{$i}"))."-{$i}";

            if (! $taken($name)) {
                return $name;
            }
        }
    }

    private function serverName(ConfigScope $scope, string $serverId): ?string
    {
        return $scope->serverName($serverId) ?? $this->servers->find($serverId)?->name;
    }

    private static function server(string $pointer, string $name): ConfigIssue
    {
        return ConfigIssue::error('name.server', $pointer, "No server named \"{$name}\" is attached to this project (a server hosting one of its services).");
    }
}
