<?php

namespace Falak\Functions\Http\Controllers;

use Falak\Config\Contracts\ManagedResources;
use Falak\Deployments\Contracts\DeploymentDirectory;
use Falak\Functions\Application\Actions\DeployCode;
use Falak\Functions\Application\Actions\DeployVersion;
use Falak\Functions\Application\Actions\ManageAccess;
use Falak\Functions\Application\Actions\UpdateSettings;
use Falak\Functions\Application\Code;
use Falak\Functions\Application\FunctionStore;
use Falak\Functions\Application\LiveStatus;
use Falak\Functions\Application\StaleVersion;
use Falak\Functions\Domain\Models\CloudFunction;
use Falak\Functions\Domain\Models\FunctionApiKey;
use Falak\Functions\Domain\Models\FunctionDraft;
use Falak\Functions\Domain\Models\FunctionVersion;
use Falak\Functions\FunctionsServiceProvider as Permissions;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\SiteDomains;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The function panel's Code, Versions and Scaling tabs (JSON).
 */
final class FunctionController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly SiteDirectory $sites,
        private readonly FunctionStore $functions,
    ) {}

    public function show(Request $request, string $site, DeploymentDirectory $deployments): JsonResponse
    {
        [$data, $function] = $this->resolve($request->user(), $site, Permissions::VIEW);
        $head = $function->head();
        $liveHash = $deployments->liveCommit($data->id);
        $live = $liveHash !== null ? FunctionVersion::query()->where('function_id', $function->id)->where('hash', $liveHash)->orderByDesc('number')->first() : null;
        $draft = $request->user() ? FunctionDraft::query()->where('function_id', $function->id)->where('user_id', $request->user()->getAuthIdentifier())->first() : null;
        $runtime = (array) config("functions.runtimes.{$function->runtime}", []);

        return response()->json(['data' => [
            'site' => ['id' => $data->id, 'name' => $data->name, 'slug' => $data->slug],
            'runtime' => ['key' => $function->runtime, 'label' => $runtime['label'] ?? $function->runtime, 'language' => $runtime['language'] ?? 'typescript', 'family' => $runtime['family'] ?? 'ts'],
            'entrypoint' => $function->entrypoint,
            'head' => $head ? [...$head->summary(), 'files' => $head->files] : null,
            'live' => $live?->summary(),
            'draft' => $draft ? ['files' => $draft->files, 'base_version_id' => $draft->base_version_id, 'updated_at' => $draft->updated_at->toIso8601String()] : null,
            'settings' => $this->settings($function),
            'bounds' => config('functions.bounds'),
            'limits' => ['max_bytes' => (int) config('functions.max_bytes'), 'max_files' => (int) config('functions.max_files')],
            'can' => [
                'edit' => $this->access->can($request->user(), $data->organizationId, Permissions::EDIT),
                'deploy' => $this->access->can($request->user(), $data->organizationId, Permissions::DEPLOY)
                    && $this->access->can($request->user(), $data->organizationId, 'deployments.create'),
            ],
        ]])->header('Cache-Control', 'no-store');
    }

    /** Instances, requests in flight and cold starts on the leader server (polled by the Code tab). */
    public function status(Request $request, string $site, LiveStatus $live): JsonResponse
    {
        [$data] = $this->resolve($request->user(), $site, Permissions::VIEW);

        return response()->json(['data' => $live->for($data->id, $data->slug)])->header('Cache-Control', 'no-store');
    }

    /**
     * The Code tab's test panel: an HTTP request to the function's own URL (its primary domain), so it goes through
     * the same path as real traffic (TLS, gateway, cold start, access rules). Only that host is ever requested.
     */
    public function invoke(Request $request, string $site, SiteDomains $domains, HttpClient $http): JsonResponse
    {
        [$data] = $this->resolve($request->user(), $site, Permissions::EDIT);
        $input = $request->validate([
            'method' => ['required', 'in:GET,POST,PUT,PATCH,DELETE,HEAD,OPTIONS'],
            'path' => ['required', 'string', 'max:2000', 'regex:/^\/[^\s]*$/'],
            'headers' => ['nullable', 'array', 'max:30'],
            'headers.*' => ['nullable', 'string', 'max:4096'],
            'body' => ['nullable', 'string', 'max:262144'],
        ]);
        $domain = $domains->primaryDomains([$data->id])[$data->id] ?? null;
        abort_if($domain === null, 422, 'The function has no domain yet: add one in Settings → Networking.');

        $headers = [];

        foreach ((array) ($input['headers'] ?? []) as $name => $value) {
            if (preg_match('/^[A-Za-z0-9-]{1,64}$/', (string) $name) === 1 && ! in_array(strtolower((string) $name), ['host', 'content-length', 'connection', 'transfer-encoding'], true)) {
                $headers[(string) $name] = (string) $value;
            }
        }

        $started = microtime(true);

        try {
            $response = $http->withHeaders($headers)->withOptions(['allow_redirects' => false, 'http_errors' => false])->timeout(35)
                ->send($input['method'], "https://{$domain}{$input['path']}", isset($input['body']) && $input['body'] !== '' ? ['body' => $input['body']] : []);
        } catch (\Throwable $e) {
            return response()->json(['data' => ['error' => $e->getMessage(), 'duration_ms' => (int) round((microtime(true) - $started) * 1000)]]);
        }

        $body = $response->body();

        return response()->json(['data' => [
            'url' => "https://{$domain}{$input['path']}",
            'status' => $response->status(),
            'headers' => array_map(fn ($values) => implode(', ', (array) $values), $response->headers()),
            'body' => mb_strcut($body, 0, 262144),
            'truncated' => strlen($body) > 262144,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]]);
    }

    /** Settings → Access: keys (never their value) and the IP allowlist. */
    public function access(Request $request, string $site): JsonResponse
    {
        [$data, $function] = $this->resolve($request->user(), $site, Permissions::VIEW);

        return response()->json(['data' => [
            'keys' => FunctionApiKey::query()->where('function_id', $function->id)->orderBy('created_at')->get()->map(fn (FunctionApiKey $k) => $k->present())->values(),
            'allow_cidrs' => array_values((array) ($function->allow_cidrs ?? [])),
            'can' => ['manage' => $this->access->can($request->user(), $data->organizationId, Permissions::DEPLOY)],
        ]]);
    }

    /** The key is returned once; only its hash is kept. */
    public function createKey(Request $request, string $site, ManageAccess $manage): JsonResponse
    {
        [$data, $function] = $this->resolve($request->user(), $site, Permissions::DEPLOY);
        $this->authorizeDeploy($request->user(), $data);
        $input = $request->validate(['name' => ['required', 'string', 'max:64']]);
        $result = $manage->createKey($data, $function, $input['name'], $request->user()?->getAuthIdentifier());

        return response()->json(['data' => [...$result['model']->present(), 'key' => $result['key'], 'deployment_id' => $result['deployment_id']]], 201);
    }

    public function revokeKey(Request $request, string $site, string $key, ManageAccess $manage): JsonResponse
    {
        [$data, $function] = $this->resolve($request->user(), $site, Permissions::DEPLOY);
        $this->authorizeDeploy($request->user(), $data);
        $model = FunctionApiKey::query()->where('function_id', $function->id)->findOrFail(strtolower($key));

        return response()->json(['data' => ['deployment_id' => $manage->revokeKey($data, $model, $request->user()?->getAuthIdentifier())]]);
    }

    public function updateAllowlist(Request $request, string $site, ManageAccess $manage): JsonResponse
    {
        [$data, $function] = $this->resolve($request->user(), $site, Permissions::DEPLOY);
        $this->authorizeDeploy($request->user(), $data);
        $input = $request->validate(['allow_cidrs' => ['present', 'array', 'max:'.ManageAccess::MAX_CIDRS], 'allow_cidrs.*' => ['nullable', 'string', 'max:64']]);
        app(ManagedResources::class)->managed('site', $data->id)->guard(['allow_cidrs' => '/access/allow'],
            ['allow_cidrs' => array_values(array_filter((array) $input['allow_cidrs']))], ['allow_cidrs' => array_values((array) ($function->allow_cidrs ?? []))]);
        $deploymentId = $manage->setAllowlist($data, $function, array_values((array) $input['allow_cidrs']), $request->user()?->getAuthIdentifier());

        return response()->json(['data' => ['allow_cidrs' => array_values((array) ($function->refresh()->allow_cidrs ?? [])), 'deployment_id' => $deploymentId]]);
    }

    /** Autosave of the editor (per user). */
    public function saveDraft(Request $request, string $site): JsonResponse
    {
        [, $function] = $this->resolve($request->user(), $site, Permissions::EDIT);
        abort_if((int) $request->header('Content-Length') > Code::maxRequestBytes(), 413, 'The code is too large.');
        $input = $request->validate(['files' => ['required', 'array', 'max:'.(int) config('functions.max_files')], 'base_version_id' => ['nullable', 'string', 'size:26']]);
        $files = Code::files($input['files'], $function->entrypoint);

        $draft = FunctionDraft::query()->updateOrCreate(
            ['function_id' => $function->id, 'user_id' => (string) $request->user()?->getAuthIdentifier()],
            ['files' => $files, 'base_version_id' => isset($input['base_version_id']) ? strtolower($input['base_version_id']) : null],
        );

        return response()->json(['data' => ['updated_at' => $draft->updated_at->toIso8601String()]]);
    }

    public function discardDraft(Request $request, string $site): JsonResponse
    {
        [, $function] = $this->resolve($request->user(), $site, Permissions::EDIT);
        FunctionDraft::query()->where('function_id', $function->id)->where('user_id', (string) $request->user()?->getAuthIdentifier())->delete();

        return response()->json(['data' => null]);
    }

    public function deploy(Request $request, string $site, DeployCode $deploy): JsonResponse
    {
        [$data, $function] = $this->resolve($request->user(), $site, Permissions::EDIT);
        $this->authorizeDeploy($request->user(), $data);
        abort_if((int) $request->header('Content-Length') > Code::maxRequestBytes(), 413, 'The code is too large.');
        $input = $request->validate([
            'files' => ['required', 'array', 'max:'.(int) config('functions.max_files')],
            'message' => ['nullable', 'string', 'max:500'],
            'base_version_id' => ['nullable', 'string', 'size:26'],
            'force' => ['sometimes', 'boolean'],
        ]);

        try {
            $result = $deploy(
                $data,
                $function,
                $input['files'],
                $input['message'] ?? null,
                isset($input['base_version_id']) ? strtolower($input['base_version_id']) : null,
                $request->user()?->getAuthIdentifier(),
                $request->user()?->name ?? null,
                force: (bool) ($input['force'] ?? false),
            );
        } catch (StaleVersion $e) {
            return response()->json(['message' => $e->getMessage(), 'head' => [...$e->head->summary(), 'files' => $e->head->files]], 409);
        }

        return response()->json([
            'data' => ['version' => $result['version']->summary(), 'created' => $result['created'], 'deployment_id' => $result['deployment_id']],
            'warnings' => $result['warnings'],
        ], $result['created'] ? 201 : 200);
    }

    public function versions(Request $request, string $site): JsonResponse
    {
        [, $function] = $this->resolve($request->user(), $site, Permissions::VIEW);
        $versions = FunctionVersion::query()->where('function_id', $function->id)->orderByDesc('number')->limit(200)
            ->get(['id', 'function_id', 'number', 'hash', 'size', 'message', 'author_name', 'created_at']);

        return response()->json(['data' => $versions->map(fn (FunctionVersion $version) => $version->summary())->values()]);
    }

    public function version(Request $request, string $site, int $number): JsonResponse
    {
        [, $function] = $this->resolve($request->user(), $site, Permissions::VIEW);
        $version = FunctionVersion::query()->where('function_id', $function->id)->where('number', $number)->firstOrFail();

        return response()->json(['data' => $version->detail()]);
    }

    public function deployVersion(Request $request, string $site, int $number, DeployVersion $deploy): JsonResponse
    {
        [$data, $function] = $this->resolve($request->user(), $site, Permissions::DEPLOY);
        $this->authorizeDeploy($request->user(), $data);
        $version = FunctionVersion::query()->where('function_id', $function->id)->where('number', $number)->firstOrFail();
        $isHead = $function->head()?->id === $version->id;

        return response()->json(['data' => [
            'deployment_id' => $deploy($data, $version, $request->user()?->getAuthIdentifier(), $request->user()?->name ?? null, rollback: ! $isHead),
        ]], 201);
    }

    public function updateSettings(Request $request, string $site, UpdateSettings $update): JsonResponse
    {
        [$data, $function] = $this->resolve($request->user(), $site, Permissions::DEPLOY);
        $this->authorizeDeploy($request->user(), $data);
        $input = $request->validate(UpdateSettings::rules());
        // Settings a falak.yaml sets are its (422 when the form changes them).
        app(ManagedResources::class)->managed('site', $data->id)->guard([
            'min_instances' => '/scaling/min_instances', 'max_instances' => '/scaling/max_instances', 'concurrency' => '/scaling/concurrency',
            'idle_timeout_s' => '/scaling/idle_timeout', 'memory_mb' => '/memory', 'cpus' => '/cpus', 'request_timeout_s' => '/timeout',
        ], $input, $function->only(UpdateSettings::FIELDS));
        $deploymentId = $update($data, $function, $input, $request->user()?->getAuthIdentifier());

        return response()->json(['data' => ['settings' => $this->settings($function->refresh()), 'deployment_id' => $deploymentId]]);
    }

    /**
     * @return array{0: SiteData, 1: CloudFunction}
     */
    private function resolve(?Authenticatable $user, string $siteId, string $permission): array
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($user, $organizationId, Permissions::VIEW);
        $site = $this->sites->find(strtolower($siteId));
        abort_if($site === null || $site->organizationId !== $organizationId || ! $site->runtime->isFunction(), 404, 'Function not found.');
        $this->access->authorize($user, $organizationId, $permission);

        return [$site, $this->functions->ensure($site)];
    }

    /** Deploying a function starts a deployment: it needs Deployments' permission too. */
    private function authorizeDeploy(?Authenticatable $user, SiteData $site): void
    {
        $this->access->authorize($user, $site->organizationId, Permissions::DEPLOY);
        $this->access->authorize($user, $site->organizationId, 'deployments.create');
    }

    /**
     * @return array<string, int|float>
     */
    private function settings(CloudFunction $function): array
    {
        return array_intersect_key($function->only(UpdateSettings::FIELDS), array_flip(UpdateSettings::FIELDS));
    }
}
