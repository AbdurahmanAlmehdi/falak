<?php

namespace Falak\Databases\Http\Controllers;

use Falak\Databases\Application\Actions\ApplyInstance;
use Falak\Databases\Application\Actions\CreateInstance;
use Falak\Databases\Application\Actions\InstanceLifecycle;
use Falak\Databases\Application\Actions\UpdateInstance;
use Falak\Databases\Application\Actions\UpgradeInstance;
use Falak\Databases\Application\ConnectionInfo;
use Falak\Databases\Domain\Enums\Engine;
use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Domain\Models\DatabaseUser;
use Falak\Databases\Domain\Models\Restore;
use Falak\Databases\Domain\Models\StorageProvider;
use Falak\Databases\Domain\Policies\DatabasesPolicy;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Servers\Contracts\ServerDirectory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Database containers: the Databases page (and its JSON for the canvas Create picker), one container's page, and its
 * lifecycle (create, limits and settings, restart, upgrade, password, delete).
 */
final class DatabaseInstanceController extends Controller
{
    use GuardsConfigManaged;
    use PresentsDatabases;

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
    ) {}

    /**
     * Databases page; JSON (instances plus the create options) for the canvas Create picker's Database step.
     */
    public function index(Request $request, ServerDirectory $servers): Response|JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, DatabasesPolicy::VIEW);

        $instances = $this->instances($organizationId);

        $options = $this->createOptions($organizationId, $servers);

        if ($request->wantsJson() && $request->header('X-Inertia') === null) {
            return response()->json(['data' => $instances->map(fn (DatabaseInstance $instance) => $this->presentInstance($instance))->values(), 'options' => $options]);
        }

        $recent = Backup::query()->with('storageProvider')->where('organization_id', $organizationId)->latest()->orderByDesc('id')->limit(10)->get();

        return Inertia::render('Databases/Index', [
            'instances' => $instances->map(fn (DatabaseInstance $instance) => $this->presentInstance($instance))->values(),
            'recentBackups' => $recent->map(fn (Backup $backup) => $this->presentBackup($backup))->values(),
            'storageProviders' => StorageProvider::query()->where('organization_id', $organizationId)->count(),
            'options' => $options,
            'can' => [
                'manage' => $this->access->can($request->user(), $organizationId, DatabasesPolicy::MANAGE),
                'manageStorage' => $this->access->can($request->user(), $organizationId, DatabasesPolicy::STORAGE),
            ],
        ]);
    }

    public function store(Request $request, CreateInstance $create, ProjectDirectory $projects): RedirectResponse|JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, DatabasesPolicy::MANAGE);

        $data = $request->validate([
            'engine' => ['required', Rule::enum(Engine::class)],
            'server_id' => ['required', 'string'],
            ...self::createRules(),
        ]);

        $engine = Engine::from($data['engine']);
        // Placed in the default project's production environment (PlaceCreatedServices): it joins that network.
        $data['environment_id'] = $projects->defaultEnvironment($organizationId)?->id;
        $instance = $create($organizationId, $data['server_id'], $engine, $data, $request->user()?->getAuthIdentifier());

        return $request->wantsJson() && $request->header('X-Inertia') === null
            ? response()->json(['data' => $this->presentInstance($instance)], 201)
            : redirect("/databases/instances/{$instance->id}");
    }

    public function show(Request $request, DatabaseInstance $instance, ConnectionInfo $connection): Response
    {
        $this->authorize('view', $instance);
        $user = $request->user();
        $organizationId = $instance->organization_id;

        $instance->load(['databases', 'users.grants.database', 'schedules.databases', 'schedules.storageProvider']);

        $backups = Backup::query()->with('storageProvider')
            ->where('database_instance_id', $instance->id)->where('type', Backup::LOGICAL)
            ->latest()->orderByDesc('id')->limit(50)->get();

        $restores = Restore::query()->with('backup')
            ->where('database_instance_id', $instance->id)->where('type', Restore::BACKUP)
            ->latest()->orderByDesc('id')->limit(20)->get();

        return Inertia::render('Databases/Show', [
            'instance' => $this->presentInstance($instance),
            'connection' => $connection->for($instance),
            'databases' => $instance->databases->map(fn (Database $database) => $this->presentDatabase($database))->values(),
            'users' => $instance->users->map(fn (DatabaseUser $dbUser) => $this->presentUser($dbUser))->values(),
            'schedules' => $instance->schedules->map(fn (BackupSchedule $schedule) => $this->presentSchedule($schedule))->values(),
            'backups' => $backups->map(fn (Backup $backup) => $this->presentBackup($backup))->values(),
            'restores' => $restores->map(fn (Restore $restore) => $this->presentRestore($restore))->values(),
            'pitr' => $this->presentPitr($instance),
            'storageProviders' => $this->providers($organizationId),
            'restoreTargets' => $this->restoreTargets($instance),
            'options' => $this->instanceOptions($instance),
            'can' => $this->abilities($user, $organizationId),
        ]);
    }

    /**
     * PUT /databases/instances/{instance} {memory_mb?, cpus?, settings?, public_access?, require_tls?}
     */
    public function update(Request $request, DatabaseInstance $instance, UpdateInstance $update): RedirectResponse|JsonResponse
    {
        $this->authorize('manage', $instance);

        $data = $request->validate([
            'memory_mb' => ['nullable', 'integer', 'min:16', 'max:262144'],
            'cpus' => ['nullable', 'numeric', 'min:0.1', 'max:256'],
            'settings' => ['nullable', 'array'],
            'settings.*' => ['nullable'],
            'public_access' => ['nullable', 'boolean'],
            'require_tls' => ['nullable', 'boolean'],
            'allowed_sources' => ['nullable', 'array', 'max:64'],
            'allowed_sources.*' => ['string', 'max:18'],
        ]);

        $this->guardInstance($instance, $data);
        $instance = $update($instance, $data, $request->user()?->getAuthIdentifier());

        return $this->respond($request, $instance);
    }

    /**
     * POST /databases/instances/{instance}/network: apply the pending published addresses (the container is recreated
     * on its volume: Docker binds ports at creation).
     */
    public function network(Request $request, DatabaseInstance $instance, ApplyInstance $apply): RedirectResponse|JsonResponse
    {
        $this->authorize('manage', $instance);

        if (! $instance->isRunning() || $instance->pending_published_addresses === null) {
            throw ValidationException::withMessages(['instance' => 'Nothing to apply.']);
        }

        $apply($instance, applyNetwork: true);

        return $this->respond($request, $instance);
    }

    public function restart(Request $request, DatabaseInstance $instance, InstanceLifecycle $lifecycle): RedirectResponse|JsonResponse
    {
        $this->authorize('manage', $instance);
        $lifecycle->restart($instance, $request->user()?->getAuthIdentifier());

        return $this->respond($request, $instance);
    }

    /**
     * POST /databases/instances/{instance}/upgrade {version?}: the same major again (a rebuilt image) or a newer one.
     */
    public function upgrade(Request $request, DatabaseInstance $instance, UpgradeInstance $upgrade): RedirectResponse|JsonResponse
    {
        $this->authorize('manage', $instance);
        $data = $request->validate(['version' => ['nullable', 'string', 'max:16']]);

        // A rebuild of the same major is not a change of the file's `version`.
        if (($data['version'] ?? null) !== null && $data['version'] !== $instance->version) {
            $this->managed('database_instance', $instance->id)->guard(['version' => '/version'], $data, ['version' => $instance->version]);
        }

        $upgrade($instance, $data['version'] ?? null, $request->user()?->getAuthIdentifier());

        return $this->respond($request, $instance->refresh());
    }

    /**
     * POST /databases/instances/{instance}/password {password?}: a new superuser (Redis / Valkey: `default`) password.
     */
    public function password(Request $request, DatabaseInstance $instance, InstanceLifecycle $lifecycle): RedirectResponse|JsonResponse
    {
        $this->authorize('manage', $instance);
        $data = $request->validate(['password' => ['nullable', 'string', 'min:12', 'max:128']]);
        $lifecycle->rotatePassword($instance, $data['password'] ?? null, $request->user()?->getAuthIdentifier());

        return $this->respond($request, $instance);
    }

    /**
     * DELETE /databases/instances/{instance} {confirm, delete_volume?}: the container goes; its data volume stays
     * unless delete_volume.
     */
    public function destroy(Request $request, DatabaseInstance $instance, InstanceLifecycle $lifecycle): RedirectResponse|JsonResponse
    {
        $this->delete($request, $instance, $lifecycle);

        return $request->wantsJson() && $request->header('X-Inertia') === null
            ? response()->json(['data' => $this->presentInstance($instance)])
            : redirect('/databases');
    }

    /**
     * GET /api/v1/databases/instances: the organization's containers (never a password).
     */
    public function indexApi(Request $request): JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, DatabasesPolicy::VIEW);

        return response()->json(['data' => $this->instances($organizationId)->map(fn (DatabaseInstance $instance) => $this->presentInstance($instance))->values()]);
    }

    /**
     * GET /api/v1/databases/instances/{instance}
     */
    public function showApi(DatabaseInstance $instance): JsonResponse
    {
        $this->authorize('view', $instance);

        return response()->json(['data' => $this->presentInstance($instance->loadCount(['databases', 'users']))]);
    }

    /**
     * DELETE /api/v1/databases/instances/{instance} {confirm, delete_volume?}: 202, the agent removes the container
     * (status `deleting` until it reports back).
     */
    public function destroyApi(Request $request, DatabaseInstance $instance, InstanceLifecycle $lifecycle): JsonResponse
    {
        $this->delete($request, $instance, $lifecycle);

        return response()->json(['data' => $this->presentInstance($instance)], 202);
    }

    /** The dashboard's and the API's delete: same permission, typed confirmation and falak.yaml guard. */
    private function delete(Request $request, DatabaseInstance $instance, InstanceLifecycle $lifecycle): void
    {
        $this->authorize('manage', $instance);
        $data = $request->validate([
            'confirm' => ['required', 'string', 'in:'.$instance->name],
            'delete_volume' => ['nullable', 'boolean'],
        ], ['confirm.in' => 'Type the database server name to confirm.']);
        $this->guardManaged('database_instance', $instance->id, 'confirm', 'this database server');

        $lifecycle->delete($instance, (bool) ($data['delete_volume'] ?? false), $request->user()?->getAuthIdentifier());
    }

    /**
     * @return Collection<int, DatabaseInstance>
     */
    private function instances(string $organizationId): Collection
    {
        return DatabaseInstance::query()
            ->where('organization_id', $organizationId)
            ->withCount(['databases', 'users'])
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function createRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:41'],
            'version' => ['nullable', 'string', 'max:16'],
            'memory_mb' => ['nullable', 'integer', 'min:16', 'max:262144'],
            'cpus' => ['nullable', 'numeric', 'min:0.1', 'max:256'],
            'disk_gb' => ['nullable', 'integer', 'min:1', 'max:16384'],
            'settings' => ['nullable', 'array'],
            'settings.*' => ['nullable'],
        ];
    }

    /**
     * Engines with their versions and default memory, and the organization's servers (the canvas and the Databases page
     * create instances from it).
     *
     * @return array<string, mixed>
     */
    private function createOptions(string $organizationId, ServerDirectory $servers): array
    {
        return [
            'engines' => array_map(fn (Engine $engine) => [
                'value' => $engine->value,
                'label' => $engine->label(),
                'kind' => $engine->kind()->value,
                'versions' => $engine->versions(),
                'default_version' => $engine->defaultVersion(),
                'default_memory_mb' => intdiv($engine->defaultMemory(), 1024 ** 2),
                'min_memory_mb' => intdiv($engine->minMemory(), 1024 ** 2),
                'default_disk_gb' => intdiv($engine->defaultDisk(), 1024 ** 3),
            ], Engine::cases()),
            'servers' => array_map(fn ($server) => ['id' => $server->id, 'name' => $server->name], $servers->forOrganization($organizationId, activeOnly: true)),
        ];
    }

    private function respond(Request $request, DatabaseInstance $instance): RedirectResponse|JsonResponse
    {
        return $request->wantsJson() && $request->header('X-Inertia') === null
            ? response()->json(['data' => $this->presentInstance($instance)])
            : back();
    }

    /**
     * @return list<array{id: string, name: string, driver: string, bucket: string}>
     */
    private function providers(string $organizationId): array
    {
        return StorageProvider::query()->where('organization_id', $organizationId)->orderBy('name')->get(['id', 'name', 'driver', 'bucket'])
            ->map(fn (StorageProvider $provider) => ['id' => $provider->id, 'name' => $provider->name, 'driver' => $provider->driver->value, 'bucket' => $provider->bucket])->values()->all();
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(mixed $user, string $organizationId): array
    {
        return [
            'manage' => $this->access->can($user, $organizationId, DatabasesPolicy::MANAGE),
            'reveal' => $this->access->can($user, $organizationId, DatabasesPolicy::REVEAL),
            'restore' => $this->access->can($user, $organizationId, DatabasesPolicy::RESTORE),
            'manageStorage' => $this->access->can($user, $organizationId, DatabasesPolicy::STORAGE),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function instanceOptions(DatabaseInstance $instance): array
    {
        return [
            'privileges' => $instance->engine->privileges(),
            'versions' => array_values(array_filter($instance->engine->versions(), fn (string $version) => version_compare($version, $instance->version, '>='))),
            'drill_servers' => $this->drillServers($instance),
            'default_charset' => $instance->engine->defaultCharset(),
            'default_collation' => $instance->engine->defaultCollation(),
            'evictions' => $instance->engine->isKeyValue() ? UpdateInstance::EVICTIONS : [],
            'persistences' => $instance->engine->isKeyValue() ? UpdateInstance::PERSISTENCES : [],
            'min_memory_mb' => intdiv($instance->engine->minMemory(), 1024 ** 2),
            'upgradable' => $instance->status === InstanceStatus::Active,
        ];
    }
}
