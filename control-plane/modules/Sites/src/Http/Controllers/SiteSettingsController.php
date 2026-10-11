<?php

namespace Falak\Sites\Http\Controllers;

use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Kernel\Http\Controller;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Application\Actions\CreateSite;
use Falak\Sites\Application\Actions\RetryTarget;
use Falak\Sites\Application\Actions\SetSiteTargets;
use Falak\Sites\Application\Actions\UpdateLaravelSettings;
use Falak\Sites\Application\Actions\UpdateSharedPaths;
use Falak\Sites\Application\Actions\UpdateSite;
use Falak\Sites\Contracts\BuildMode;
use Falak\Sites\Contracts\Data\LaravelSettings;
use Falak\Sites\Contracts\OctaneServer;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Models\SiteTarget;
use Falak\Sites\Http\Requests\StoreSiteRequest;
use Falak\SourceControl\Contracts\Exceptions\SourceControlException;
use Falak\SourceControl\Contracts\SourceControlGateway;
use Falak\Volumes\Contracts\VolumeMounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SiteSettingsController extends Controller
{
    use GuardsManagedFields;
    use PresentsSites;

    public function __construct(private readonly ServerDirectory $servers) {}

    /**
     * JSON for the service panel's Settings tab (Source, Build, Servers, Laravel, Danger sections); a browser visit
     * opens that tab.
     */
    public function show(Request $request, Site $site, AgentDirectory $agents, SourceControlGateway $sourceControl): JsonResponse|RedirectResponse
    {
        $this->authorize('view', $site);

        if (! $this->wantsPanelJson($request)) {
            return $this->toPanel($site, 'settings');
        }

        $site->load('targets');
        $deployKey = null;
        $connection = null;
        $sourceError = null;

        try {
            $deployKey = $site->deploy_key_id ? $sourceControl->deployKey($site->deploy_key_id) : null;
            $connection = $site->source_connection_id ? $sourceControl->connection($site->source_connection_id) : null;
        } catch (SourceControlException $e) {
            $sourceError = $e->getMessage();
        }

        return response()->json(['data' => [
            'site' => [...$this->header($site), 'status' => $this->status($site)],
            'settings' => [
                'name' => $site->name,
                'framework' => $site->framework->value,
                'framework_label' => $site->framework->label(),
                'is_laravel' => $site->framework->isLaravel(),
                'runtime' => $site->runtime->value,
                'build_mode' => $site->build_mode->value,
                'php_version' => $site->php_version,
                'node_version' => $site->node_version,
                'source_connection_id' => $site->source_connection_id,
                'repository' => $site->repository,
                'branch' => $site->branch,
                'root_directory' => $site->root_directory,
                'push_to_deploy' => $site->push_to_deploy,
                'web_directory' => $site->web_directory,
                'app_port' => $site->app_port,
                'container_port' => $site->container_port,
                'docker_image' => $site->docker_image,
                'dockerfile' => $site->dockerfile,
                'compose_file' => $site->compose_file,
                'health_check_path' => $site->health_check_path,
                'test_domain_enabled' => $site->test_domain_enabled,
                'test_domain' => $site->testDomain(),
                'unix_user' => $site->unix_user,
                'isolated' => $site->isolated,
                'root_path' => $site->rootPath(),
                'document_root' => $site->toData()->documentRoot(),
                'laravel' => $site->laravel->toArray(),
                'octane_servers' => array_map(fn (OctaneServer $server) => ['value' => $server->value, 'label' => $server->label()], OctaneServer::for($site->runtime)),
                'shared_paths' => array_map(fn ($path) => $path->toArray(), app(VolumeMounts::class)->sharedPaths($site->id)),
                'created_at' => $site->created_at->toIso8601String(),
            ],
            'source' => [
                'connection' => $connection ? ['id' => $connection->id, 'name' => $connection->name, 'provider' => $connection->provider->value, 'provider_label' => $connection->provider->label(), 'github_app' => $connection->isGitHubApp()] : null,
                'deploy_key' => $deployKey ? ['public_key' => $deployKey->publicKey, 'fingerprint' => $deployKey->fingerprint, 'installed' => $deployKey->installed, 'install_error' => $deployKey->installError] : null,
                'error' => $sourceError,
            ],
            'targets' => $this->targets($site, $this->serversById($this->servers, $site->serverIds())),
            'options' => $this->options($site->organization_id, $this->servers, $agents, $sourceControl),
            'warnings' => array_values((array) $request->session()->pull('sites.warnings', [])),
            // Fields the project's falak.yaml manages (read-only here), or null.
            'config' => $this->managedFields($site)->toArray(),
            'can' => [
                'update' => $request->user()?->can('update', $site) ?? false,
                'delete' => $request->user()?->can('delete', $site) ?? false,
                'run_commands' => $request->user()?->can('runCommands', $site) ?? false,
            ],
        ]]);
    }

    public function update(Request $request, Site $site, UpdateSite $update): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $site);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9 ._-]*$/', Rule::unique('sites_sites')->where('organization_id', $site->organization_id)->ignore($site->id)],
            'runtime' => ['sometimes', Rule::enum(SiteRuntime::class)],
            'build_mode' => ['sometimes', Rule::enum(BuildMode::class)],
            ...StoreSiteRequest::siteRules(),
        ]);

        $input = $data;

        if (array_key_exists('root_directory', $input)) {
            $input['root_directory'] = CreateSite::rootDirectory($input['root_directory']);
        }

        $this->managedFields($site)->guard(self::MANAGED_SETTINGS, $input, [
            'runtime' => $site->runtime, 'build_mode' => $site->build_mode, 'php_version' => $site->php_version, 'node_version' => $site->node_version,
            'root_directory' => $site->root_directory, 'web_directory' => $site->web_directory, 'container_port' => $site->container_port,
            'docker_image' => $site->docker_image, 'dockerfile' => $site->dockerfile, 'health_check_path' => $site->health_check_path,
            'test_domain_enabled' => $site->test_domain_enabled,
        ]);

        $update($site, $data);

        if ($this->wantsPanelJson($request)) {
            return response()->json(['data' => ['warnings' => array_values($update->warnings)]]);
        }

        return back()->with('sites.warnings', $update->warnings);
    }

    public function updateTargets(Request $request, Site $site, SetSiteTargets $set): RedirectResponse
    {
        $this->authorize('update', $site);

        $data = $request->validate([
            'server_ids' => ['required', 'array', 'min:1', 'max:50'],
            'server_ids.*' => ['string', 'size:26'],
            'leader_server_id' => ['required', 'string', 'size:26'],
        ]);

        $site->loadMissing('targets');
        $order = fn (array $ids, ?string $leader) => [$leader, ...collect($ids)->reject(fn ($id) => $id === $leader)->sort()->values()->all()];
        $this->managedFields($site)->guard(['server_ids' => '/servers'],
            ['server_ids' => $order(array_map('strval', $data['server_ids']), $data['leader_server_id'])],
            ['server_ids' => $order($site->serverIds(), $site->leaderTarget()?->server_id)]);

        $set($site, array_values(array_map('strval', $data['server_ids'])), $data['leader_server_id']);

        return back();
    }

    public function retryTarget(Site $site, SiteTarget $target, RetryTarget $retry): RedirectResponse
    {
        $this->authorize('update', $site);
        abort_unless($target->site_id === $site->id, 404);

        $target->setRelation('site', $site);
        $retry($target);

        return back();
    }

    public function sharedPaths(Request $request, Site $site, UpdateSharedPaths $update): RedirectResponse
    {
        $this->authorize('update', $site);

        $data = $request->validate([
            'paths' => ['present', 'array', 'max:50'],
            'paths.*.path' => ['required', 'string', 'max:255'],
            'paths.*.type' => ['required', Rule::in(['directory', 'file'])],
        ]);

        $update($site, array_values($data['paths']), $request->user()?->getAuthIdentifier());

        return back();
    }

    public function laravel(Request $request, Site $site, UpdateLaravelSettings $update): RedirectResponse
    {
        $this->authorize('update', $site);

        $data = $request->validate([
            'scheduler' => ['required', 'boolean'],
            'horizon' => ['required', 'boolean'],
            'octane' => ['required', 'boolean'],
            'maintenance' => ['required', 'boolean'],
            'octane_server' => ['nullable', Rule::enum(OctaneServer::class)],
        ]);

        $this->guardLaravel($site, $data);
        $update($site, LaravelSettings::fromArray($data), $request->user()?->getAuthIdentifier());

        return back();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function guardLaravel(Site $site, array $data): void
    {
        $current = $site->laravel;
        $octane = fn (bool $on, ?string $server) => $on ? ($server ?? 'default') : false;
        $this->managedFields($site)->guard(['scheduler' => '/laravel/scheduler', 'horizon' => '/laravel/horizon', 'octane' => '/laravel/octane'], [
            ...array_intersect_key($data, ['scheduler' => true, 'horizon' => true]),
            ...(array_key_exists('octane', $data) ? ['octane' => $octane((bool) $data['octane'], $data['octane'] ? ($data['octane_server'] ?? $current->octaneServer?->value) : null)] : []),
        ], ['scheduler' => $current->scheduler, 'horizon' => $current->horizon, 'octane' => $octane($current->octane, $current->octaneServer?->value)]);
    }
}
