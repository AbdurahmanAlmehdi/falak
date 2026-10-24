<?php

namespace Falak\Secrets\Http\Controllers;

use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Secrets\Application\Actions\DeleteSecretProvider;
use Falak\Secrets\Application\Actions\SaveSecretProvider;
use Falak\Secrets\Domain\Models\SecretProvider;
use Falak\Secrets\Domain\Policies\SecretPolicy;
use Falak\Secrets\Http\Requests\ProviderRules;
use Falak\Secrets\Infrastructure\ExternalSecretProviders;
use Falak\Secrets\Infrastructure\Providers\ProviderFailure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Secret providers (the organization's external providers for linked secrets) and its JSON
 * endpoints. Credentials go in, never out.
 */
final class ProviderController extends Controller
{
    use PresentsProviders;

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
    ) {}

    /** GET /settings/secrets/providers */
    public function index(Request $request): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, SecretPolicy::VIEW);

        $usage = $this->providerUsage($organizationId);
        $providers = SecretProvider::query()->where('organization_id', $organizationId)->orderBy('name')->get();

        return Inertia::render('Secrets/Providers', [
            'providers' => $providers->map(fn (SecretProvider $provider) => $this->presentProvider($provider, $usage))->values(),
            'types' => $this->providerTypes(),
            'allow_private_network' => (bool) config('secrets.providers.allow_private_network', false) && ! config('controlplane.managed'),
            'can' => ['manage' => $this->access->can($request->user(), $organizationId, SecretPolicy::PROVIDERS_MANAGE)],
        ]);
    }

    public function store(Request $request, SaveSecretProvider $save): JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, SecretPolicy::PROVIDERS_MANAGE);

        $provider = $save($organizationId, null, $request->validate(ProviderRules::create()), $request->user()?->getAuthIdentifier());

        return response()->json(['data' => $this->presentProvider($provider)], 201);
    }

    public function update(Request $request, SecretProvider $provider, SaveSecretProvider $save): JsonResponse
    {
        $this->authorize('manage', $provider);

        $provider = $save($provider->organization_id, $provider, $request->validate(ProviderRules::update()), $request->user()?->getAuthIdentifier());

        return response()->json(['data' => $this->presentProvider($provider)]);
    }

    public function destroy(SecretProvider $provider, DeleteSecretProvider $delete): JsonResponse
    {
        $this->authorize('manage', $provider);

        $delete($provider);

        return response()->json(null, 204);
    }

    /** POST /secrets/providers/{provider}/test: 422 with the reason when it fails. */
    public function test(SecretProvider $provider, ExternalSecretProviders $providers): JsonResponse
    {
        $this->authorize('manage', $provider);

        try {
            $providers->test($provider);
        } catch (ProviderFailure $e) {
            throw ValidationException::withMessages(['provider' => $e->getMessage()]);
        }

        return response()->json(['data' => $this->presentProvider($provider->refresh())]);
    }
}
