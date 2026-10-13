<?php

namespace Falak\Identity\Http\Controllers\Api;

use Falak\Identity\Application\Actions\RevokeApiToken;
use Falak\Identity\Application\CliLogins;
use Falak\Identity\Domain\Models\Organization;
use Falak\Identity\Domain\Models\PersonalAccessToken;
use Falak\Identity\Domain\Models\User;
use Falak\Kernel\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `falak login` in the browser (RFC 8628) and `falak logout`'s server-side revoke. */
final class CliLoginController extends Controller
{
    /** POST /api/v1/cli/login {client_name} → the codes and where to approve them. */
    public function start(Request $request, CliLogins $logins): JsonResponse
    {
        $data = $request->validate(['client_name' => ['nullable', 'string', 'max:100']]);
        $started = $logins->start((string) ($data['client_name'] ?? ''), $request->ip());
        $uri = url('/cli/authorize');

        return response()->json([
            'data' => $started + [
                'verification_uri' => $uri,
                'verification_uri_complete' => $uri.'/'.$started['user_code'],
            ],
        ], 201);
    }

    /** POST /api/v1/cli/login/token {device_code} → 200 {data: {token, user, organization}} or 400 {error}. */
    public function token(Request $request, CliLogins $logins): JsonResponse
    {
        $data = $request->validate(['device_code' => ['required', 'string', 'max:100']]);
        $result = $logins->collect((string) $data['device_code']);
        if (isset($result['error'])) {
            return response()->json(['error' => $result['error'], 'message' => match ($result['error']) {
                'authorization_pending' => 'Waiting for the approval in the browser.',
                'slow_down' => 'Polling too fast.',
                'access_denied' => 'The login was denied in the browser.',
                default => 'The login request expired. Run `falak login` again.',
            }], 400);
        }
        $user = $result['user'];
        $organization = Organization::query()->find($result['organization_id']);

        return response()->json(['data' => [
            'token' => $result['token'],
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'organization' => ['id' => $organization?->id, 'name' => $organization?->name],
        ]]);
    }

    /** DELETE /api/v1/me/token: revoke the token making the request (`falak logout`). */
    public function revoke(Request $request, RevokeApiToken $revoke): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $revoke($user, (int) $token->getKey());
        }

        return response()->json(null, 204);
    }
}
