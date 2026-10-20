<?php

namespace Falak\SourceControl\Http\Controllers;

use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\SourceControl\Application\Actions\CreateConnection;
use Falak\SourceControl\Application\Actions\DeleteConnection;
use Falak\SourceControl\Contracts\Data\BranchData;
use Falak\SourceControl\Contracts\Data\RepositoryData;
use Falak\SourceControl\Contracts\Exceptions\SourceControlException;
use Falak\SourceControl\Contracts\ProviderType;
use Falak\SourceControl\Contracts\SourceControlGateway;
use Falak\SourceControl\Domain\Models\Connection;
use Falak\SourceControl\Domain\Models\GitHubApp;
use Falak\SourceControl\Domain\Models\Push;
use Falak\SourceControl\Http\Requests\StoreConnectionRequest;
use Falak\SourceControl\Infrastructure\GitHubApp\AppCredentials;
use Falak\SourceControl\Infrastructure\GitHubApp\AppManifest;
use Falak\SourceControl\Infrastructure\GitHubApp\GitHubAppResolver;
use Falak\SourceControl\Infrastructure\Providers\GitHubClient;
use Falak\SourceControl\Infrastructure\Providers\OAuthProviders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class ConnectionController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
    ) {}

    public function index(Request $request, OAuthProviders $oauth, GitHubAppResolver $apps): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'source_control.view');

        $connections = Connection::query()
            ->where('organization_id', $organizationId)
            ->withCount(['deployKeys', 'webhooks'])
            ->orderBy('name')
            ->get();

        return Inertia::render('SourceControl/Index', [
            'connections' => $connections->map(fn (Connection $connection) => [
                'id' => $connection->id,
                'name' => $connection->name,
                'provider' => $connection->provider->value,
                'provider_label' => $connection->provider->label(),
                'auth_type' => $connection->auth_type,
                'status' => $connection->status,
                'account' => $connection->account,
                'base_url' => $connection->base_url,
                'deploy_keys_count' => (int) $connection->getAttribute('deploy_keys_count'),
                'webhooks_count' => (int) $connection->getAttribute('webhooks_count'),
                'created_at' => $connection->created_at->toIso8601String(),
            ])->values(),
            'pushes' => Push::query()
                ->where('organization_id', $organizationId)
                ->latest('received_at')
                ->limit(20)
                ->get()
                ->map(fn (Push $push) => [
                    'id' => $push->id,
                    'connection_id' => $push->connection_id,
                    'repository' => $push->repository,
                    'branch' => $push->branch,
                    'sha' => $push->sha,
                    'message' => strtok($push->message, "\n") ?: '',
                    'author' => $push->author_name,
                    'pusher' => $push->pusher,
                    'url' => $push->url,
                    'received_at' => $push->received_at->toIso8601String(),
                ])->values(),
            'providers' => collect(ProviderType::cases())->map(fn (ProviderType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'has_api' => $type->hasApi(),
                'oauth' => $oauth->configured($type),
            ])->values(),
            'githubApp' => $this->githubApp($apps, $organizationId, $connections),
            'canManage' => $this->access->can($request->user(), $organizationId, 'source_control.manage'),
        ]);
    }

    /**
     * The GitHub App card: the apps new installations go through (the operator's env app, or this organization's
     * registered apps, one per GitHub owner, oldest first) and the installations in this organization.
     *
     * @param  Collection<int, Connection>  $connections
     * @return array<string, mixed>
     */
    private function githubApp(GitHubAppResolver $apps, string $organizationId, Collection $connections): array
    {
        $github = rtrim((string) config('source_control.github.url'), '/');
        $env = $apps->env();

        $list = $env !== null ? [[
            'id' => AppCredentials::ENV,
            'source' => 'env',
            'name' => $env->slug ?: 'GitHub App '.$env->appId,
            'slug' => $env->slug,
            'owner' => null,
            'owner_type' => null,
            'html_url' => $env->slug ? "{$github}/apps/{$env->slug}" : null,
            'settings_url' => null,
            'installable' => $env->installable(),
            'install_url' => route('source-control.github-app'),
            'webhook_url' => AppManifest::webhookUrl('env'),
            'last_delivery_at' => null,
            'created_at' => null,
        ]] : $apps->apps($organizationId)->map(fn (GitHubApp $registered) => [
            'id' => $registered->id,
            'source' => 'registered',
            'name' => $registered->name,
            'slug' => $registered->slug,
            'owner' => $registered->owner_login,
            'owner_type' => $registered->owner_type,
            'html_url' => $registered->html_url ?: "{$github}/apps/{$registered->slug}",
            'settings_url' => $registered->owner_type === 'Organization' && $registered->owner_login
                ? "{$github}/organizations/{$registered->owner_login}/settings/apps/{$registered->slug}"
                : "{$github}/settings/apps/{$registered->slug}",
            'installable' => true,
            'install_url' => route('source-control.github-app', ['app' => $registered->id]),
            'webhook_url' => AppManifest::webhookUrl($registered->id),
            'last_delivery_at' => $registered->last_delivery_at?->toIso8601String(),
            'created_at' => $registered->created_at->toIso8601String(),
        ])->values()->all();

        return [
            'apps' => $list,
            'permissions' => AppManifest::PERMISSIONS,
            'events' => AppManifest::EVENTS,
            'installations' => $connections->filter(fn (Connection $connection) => $connection->isApp())->map(fn (Connection $connection) => [
                'id' => $connection->id,
                'app_id' => $connection->github_app_id ?: AppCredentials::ENV,
                'name' => $connection->name,
                'account' => $connection->account,
                'target_type' => $connection->credential('target_type'),
                'status' => $connection->status,
                'installation_id' => $connection->installationId(),
                'repositories_count' => GitHubClient::knownRepositoryCount($connection->id),
                'manage_url' => $connection->credential('target_type') === 'Organization' && $connection->account
                    ? "{$github}/organizations/{$connection->account}/settings/installations/{$connection->installationId()}"
                    : "{$github}/settings/installations/{$connection->installationId()}",
                'created_at' => $connection->created_at->toIso8601String(),
            ])->values(),
        ];
    }

    public function store(StoreConnectionRequest $request, CreateConnection $create): RedirectResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'source_control.manage');

        $create(
            $organizationId,
            $request->user()?->getAuthIdentifier(),
            $request->provider(),
            $request->validated('auth_type'),
            $request->credentials(),
            $request->validated('name'),
            $request->validated('base_url'),
        );

        return to_route('source-control.index')->with('success', 'Connected.');
    }

    public function destroy(Request $request, Connection $connection, DeleteConnection $delete): RedirectResponse
    {
        $this->authorize('delete', $connection);

        $request->validate(['name' => ['required', 'string', Rule::in([$connection->name])]], ['name.in' => 'Type the connection name to confirm.']);

        $delete($connection);

        return to_route('source-control.index');
    }

    public function repositories(Request $request, Connection $connection, SourceControlGateway $gateway): JsonResponse
    {
        $this->authorize('view', $connection);
        $search = $request->validate(['search' => ['nullable', 'string', 'max:100']])['search'] ?? null;

        try {
            $repositories = $gateway->repositories($connection->id, $search);
        } catch (SourceControlException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => array_map(fn (RepositoryData $repo) => [
            'full_name' => $repo->fullName,
            'default_branch' => $repo->defaultBranch,
            'private' => $repo->private,
            'ssh_url' => $repo->sshUrl,
            'https_url' => $repo->httpsUrl,
            'web_url' => $repo->webUrl,
        ], $repositories)]);
    }

    public function branches(Request $request, Connection $connection, SourceControlGateway $gateway): JsonResponse
    {
        $this->authorize('view', $connection);
        $repository = $request->validate(['repository' => ['required', 'string', 'max:500']])['repository'];

        try {
            $branches = $gateway->branches($connection->id, $repository);
        } catch (SourceControlException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => array_map(fn (BranchData $branch) => [
            'name' => $branch->name,
            'sha' => $branch->sha,
            'protected' => $branch->protected,
        ], $branches)]);
    }
}
