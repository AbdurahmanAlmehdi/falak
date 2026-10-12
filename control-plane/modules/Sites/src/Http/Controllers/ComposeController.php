<?php

namespace Falak\Sites\Http\Controllers;

use Falak\Config\Contracts\ManagedResources;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Contracts\OrganizationDirectory;
use Falak\Kernel\Http\Controller;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Application\Actions\UpdateComposeSettings;
use Falak\Sites\Contracts\ComposeSource;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Sites\Contracts\TargetStatus;
use Falak\Sites\Domain\Models\ComposeState;
use Falak\Sites\Domain\Models\ComposeVersion;
use Falak\Sites\Domain\Models\OrganizationSettings;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Models\SiteTarget;
use Falak\Sites\Http\Requests\StoreSiteRequest;
use Falak\Sites\Infrastructure\Compose\EloquentComposeSites;
use Falak\Sites\Infrastructure\Compose\YamlComposeInspector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Docker Compose sites: Settings → Compose (source, versioned inline file, public services) and the Services tab
 * (per-service state from docker.compose.ps, restarts).
 */
final class ComposeController extends Controller
{
    use PresentsSites;

    /** GET /sites/{site}/compose (JSON for Settings → Compose). */
    public function show(Request $request, Site $site, YamlComposeInspector $inspector, OrganizationDirectory $directory): JsonResponse|RedirectResponse
    {
        $this->authorize('view', $site);

        if (! $this->wantsPanelJson($request)) {
            return $this->toPanel($site, 'settings', 'compose');
        }

        $this->ensureCompose($site);
        $current = ComposeVersion::query()->where('site_id', $site->id)->orderByDesc('version')->first();
        $users = [];

        return response()->json(['data' => [
            'source' => ($site->compose_source ?? ComposeSource::Repo)->value,
            'file' => $site->compose_file,
            'files' => $site->composeFiles(),
            'profiles' => array_values((array) $site->compose_profiles),
            'services' => (object) ($site->compose_services ?? []),
            'adjustments' => (object) ($site->compose_adjustments ?? []),
            'repository' => $site->repository,
            'branch' => $site->branch,
            'slug' => $site->slug,
            'version' => $current?->version,
            'content' => $current?->content,
            'summary' => $current !== null ? $inspector->parse($current->content)->toArray() : null,
            'versions' => ComposeVersion::query()->where('site_id', $site->id)->orderByDesc('version')->limit(25)->get(['id', 'site_id', 'version', 'created_by', 'created_at'])
                ->map(function (ComposeVersion $version) use ($directory, &$users) {
                    $by = $version->created_by;

                    if ($by !== null && ! array_key_exists($by, $users)) {
                        $users[$by] = $directory->findUser($by)?->name;
                    }

                    return ['version' => $version->version, 'created_by' => $by !== null ? $users[$by] : null, 'created_at' => $version->created_at->toIso8601String()];
                })->values(),
            'public_services' => array_map(fn ($public) => $public->toArray(), $site->publicServices()),
            'template' => $site->template,
            'policy' => ['allow_privileged' => OrganizationSettings::for($site->organization_id)->allow_privileged_compose],
            // What falak.yaml manages (files, profiles, services): shown read-only.
            'managed' => app(ManagedResources::class)->managed('site', $site->id)->toArray(),
            'can' => ['update' => $request->user()?->can('update', $site) ?? false],
        ]])->header('Cache-Control', 'no-store');
    }

    /** GET /sites/{site}/compose/versions/{version} — one inline version (diff / restore preview). */
    public function version(Site $site, int $version): JsonResponse
    {
        $this->authorize('view', $site);
        $row = ComposeVersion::query()->where('site_id', $site->id)->where('version', $version)->firstOrFail();

        return response()->json(['data' => ['version' => $row->version, 'content' => $row->content]])->header('Cache-Control', 'no-store');
    }

    /** POST /sites/{site}/compose/validate {content} — parse + policy without saving. */
    public function validateContent(Request $request, Site $site, YamlComposeInspector $inspector): JsonResponse
    {
        $this->authorize('view', $site);
        $data = $request->validate(['content' => ['present', 'nullable', 'string', 'max:'.(int) config('sites.compose.max_bytes', 262144)]]);
        $summary = $inspector->parse((string) $data['content']);
        $result = $summary->toArray();

        if (($builds = $summary->buildServices()) !== [] && ($site->compose_source ?? ComposeSource::Repo) === ComposeSource::Inline) {
            $result['errors'][] = 'Inline compose files cannot use `build:` ('.implode(', ', $builds).').';
        }

        $result['allow_privileged'] = OrganizationSettings::for($site->organization_id)->allow_privileged_compose;

        return response()->json(['data' => $result]);
    }

    /** PUT /sites/{site}/compose */
    public function update(Request $request, Site $site, UpdateComposeSettings $update): JsonResponse
    {
        $this->authorize('update', $site);
        $this->ensureCompose($site);

        $data = $request->validate([
            ...StoreSiteRequest::composeRules(),
            'compose_source' => ['required', Rule::enum(ComposeSource::class)],
            'compose_file' => StoreSiteRequest::siteRules()['compose_file'],
            'base_version' => ['nullable', 'integer'],
        ]);

        $this->guardManaged($site, $data);
        $latest = ComposeVersion::query()->where('site_id', $site->id)->max('version');

        if (isset($data['base_version']) && $latest !== null && (int) $data['base_version'] !== (int) $latest) {
            throw ValidationException::withMessages(['compose_content' => "Someone saved version {$latest} while you were editing. Reload to see their changes."]);
        }

        $result = $update($site, $data, $request->user()?->getAuthIdentifier());

        return response()->json(['data' => $result]);
    }

    /**
     * Fields a falak.yaml manages (files, profiles, a public service's port and health path, a service replaced by a
     * database unit) are refused (422) when the form changes them.
     *
     * @param  array<string, mixed>  $data
     */
    private function guardManaged(Site $site, array $data): void
    {
        $managed = app(ManagedResources::class)->managed('site', $site->id);

        if (! $managed->any()) {
            return;
        }

        $managed->guard(['compose_files' => '/files', 'compose_profiles' => '/profiles'], $data, [
            'compose_files' => $site->composeFiles(),
            'compose_profiles' => array_values((array) $site->compose_profiles),
        ]);

        if (($data['compose_source'] ?? null) === ComposeSource::Inline->value && $managed->covers('/files')) {
            $managed->guard(['compose_source' => '/files'], $data, ['compose_source' => ComposeSource::Repo->value]);
        }

        $current = collect(array_filter((array) $site->public_services, 'is_array'))->keyBy('service');
        $wanted = collect(array_key_exists('public_services', $data) ? array_filter((array) $data['public_services'], 'is_array') : $current->all())->keyBy('service');

        foreach ($current->keys()->merge($wanted->keys())->unique() as $service) {
            foreach (['port' => 'port', 'health_check_path' => 'health_path'] as $key => $field) {
                $managed->guard(["public_services.{$service}.{$key}" => "/services/{$service}/{$field}"],
                    ["public_services.{$service}.{$key}" => $wanted->get($service)[$key] ?? null],
                    ["public_services.{$service}.{$key}" => $current->get($service)[$key] ?? null]);
            }
        }

        foreach ((array) ($data['compose_services'] ?? []) as $service => $decision) {
            // The form sends every service's decision: only a changed one is an edit.
            $current = (array) ($site->compose_services[$service] ?? []);
            $mode = (string) ($decision['mode'] ?? 'keep');
            $same = $mode === (string) ($current['mode'] ?? 'keep')
                && ($mode !== 'database' || ! isset($decision['database_id']) || strtolower((string) $decision['database_id']) === strtolower((string) ($current['database_id'] ?? '')));

            if (! $same) {
                $managed->guardWhole("compose_services.{$service}", '/services/'.$service.'/replace_with');
            }
        }
    }

    /** POST /sites/{site}/compose/versions/{version}/restore — the version's content becomes a new version. */
    public function restore(Request $request, Site $site, int $version, UpdateComposeSettings $update): JsonResponse
    {
        $this->authorize('update', $site);
        $row = ComposeVersion::query()->where('site_id', $site->id)->where('version', $version)->firstOrFail();
        // Restoring an inline version switches the stack away from the repository files the file manages.
        $this->guardManaged($site, ['compose_source' => ComposeSource::Inline->value]);

        $result = $update($site, ['compose_source' => ComposeSource::Inline->value, 'compose_content' => $row->content], $request->user()?->getAuthIdentifier());

        return response()->json(['data' => $result]);
    }

    /**
     * GET /sites/{site}/compose/services — last reported state per server and service; refreshes
     * (docker.compose.ps with stats) when older than sites.compose.status_refresh_seconds.
     */
    public function services(Request $request, Site $site, AgentGateway $agents, ServerDirectory $servers): JsonResponse|RedirectResponse
    {
        $this->authorize('view', $site);

        if (! $this->wantsPanelJson($request)) {
            return $this->toPanel($site, 'services');
        }

        $this->ensureCompose($site);
        $site->load('targets');
        $this->refresh($site, $agents, $request->boolean('refresh'));

        $states = ComposeState::query()->where('site_id', $site->id)->get()->keyBy('server_id');
        $public = [];

        foreach ($site->publicServices() as $service) {
            $public[$service->service] = $service->toArray();
        }

        $rows = [];

        foreach ($site->targets as $target) {
            /** @var SiteTarget $target */
            $state = $states->get($target->server_id);
            $server = $servers->find($target->server_id);

            foreach ($state->services ?? [] as $service) {
                $data = EloquentComposeSites::state($state, $service);
                $rows[] = [
                    'service' => $data->service,
                    'server_id' => $target->server_id,
                    'server_name' => $server->name ?? $target->server_id,
                    'container' => $data->containerName,
                    'state' => $data->state,
                    'health' => $data->health,
                    'image' => $data->image,
                    'digest' => $data->imageDigest,
                    'ports' => $data->ports,
                    'restarts' => $data->restarts,
                    'cpu_percent' => $data->cpuPercent,
                    'memory_bytes' => $data->memoryBytes,
                    'memory_limit_bytes' => is_numeric($service['memory_limit_bytes'] ?? null) ? (int) $service['memory_limit_bytes'] : null,
                    'started_at' => $service['started_at'] ?? null,
                    'public' => $public[$data->service] ?? null,
                ];
            }
        }

        usort($rows, fn (array $a, array $b) => [$a['service'], $a['server_name']] <=> [$b['service'], $b['server_name']]);

        return response()->json(['data' => [
            'services' => $rows,
            'servers' => $site->targets->map(fn (SiteTarget $target) => [
                'id' => $target->server_id,
                'name' => $servers->find($target->server_id)->name ?? $target->server_id,
                'reported_at' => $states->get($target->server_id)?->reported_at?->toIso8601String(),
                'refreshing' => $states->get($target->server_id)?->command_id !== null,
            ])->values(),
            'public_services' => array_values($public),
            'can' => ['restart' => $request->user()?->can('update', $site) ?? false],
        ]])->header('Cache-Control', 'no-store');
    }

    /** POST /sites/{site}/compose/restart {service?, server_id?} — docker.compose.restart on the site's servers. */
    public function restart(Request $request, Site $site, AgentGateway $agents, AuditLog $audit): JsonResponse
    {
        $this->authorize('update', $site);
        $this->ensureCompose($site);
        $data = $request->validate([
            'service' => ['nullable', 'string', 'max:63', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/'],
            'server_id' => ['nullable', 'string', 'size:26'],
        ]);
        $site->load('targets');
        $serverIds = isset($data['server_id']) ? array_values(array_intersect([strtolower($data['server_id'])], $site->serverIds())) : $site->serverIds();
        $dispatched = 0;

        foreach ($serverIds as $serverId) {
            try {
                $agents->dispatch($serverId, 'docker.compose.restart', array_filter([
                    'project' => $site->slug,
                    'services' => isset($data['service']) ? [$data['service']] : null,
                ]), 300, "sites.compose.restart:{$site->id}:".Str::ulid());
                $dispatched++;
            } catch (AgentUnavailable) {
                continue;
            }

            ComposeState::query()->where('site_id', $site->id)->where('server_id', $serverId)->update(['requested_at' => null]);
        }

        if ($dispatched === 0) {
            throw ValidationException::withMessages(['server_id' => 'No server agent of the site is connected.']);
        }

        $audit->record('site.compose_restarted', 'site', $site->id, ['service' => $data['service'] ?? null, 'servers' => $serverIds], $site->organization_id);

        return response()->json(['data' => ['servers' => $dispatched]], 202);
    }

    private function refresh(Site $site, AgentGateway $agents, bool $force): void
    {
        $every = max(1, (int) config('sites.compose.status_refresh_seconds', 10));

        foreach ($site->targets as $target) {
            /** @var SiteTarget $target */
            if ($target->status !== TargetStatus::Ready) {
                continue;
            }

            $state = ComposeState::query()->firstOrNew(['site_id' => $site->id, 'server_id' => $target->server_id], ['services' => []]);
            $pending = $state->command_id !== null && $state->requested_at?->gt(now()->subMinute());

            if ($pending || (! $force && $state->requested_at?->gt(now()->subSeconds($every)))) {
                continue;
            }

            try {
                $handle = $agents->dispatch($target->server_id, 'docker.compose.ps', ['project' => $site->slug, 'stats' => true], 60, "sites.compose.ps:{$site->id}:{$target->server_id}:".Str::ulid());
            } catch (Throwable) {
                continue;
            }

            $state->forceFill(['command_id' => $handle->id, 'requested_at' => now()])->save();
        }
    }

    private function ensureCompose(Site $site): void
    {
        abort_unless($site->runtime === SiteRuntime::Compose, 404);
    }
}
