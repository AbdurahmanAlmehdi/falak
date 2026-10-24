<?php

namespace Falak\Builds\Http\Controllers\Internal;

use Falak\Builds\Application\Registry;
use Falak\Builds\Contracts\BuildStatus;
use Falak\Builds\Domain\Models\Build;
use Falak\Kernel\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/internal/registry/token?service=…&scope=repository:<name>:pull,push — the built-in registry's token
 * server (Docker Registry v2 token auth; the registry runs with REGISTRY_AUTH=token and this URL as its realm).
 *
 * Clients (dockerd, docker compose, BuildKit) log in with HTTP basic auth and get a short-lived RS256 JWT whose
 * `access` is the intersection of the requested scopes and what the credential allows:
 *  - the operator credential (FALAK_REGISTRY_USERNAME / _PASSWORD): everything requested (falak-ctl, the prune job);
 *  - a scoped credential from Registry::credentials(): only its repositories (and their sub-repositories) and
 *    actions, until it expires — pull for the repositories of the deploying organization's sites in a deploy
 *    command, pull+push for the site of a build handed to a builder (and only while that build is assigned/running).
 * A valid credential asking for more gets a token without it (the registry then answers 401/denied).
 */
final class RegistryTokenController extends Controller
{
    public function __invoke(Request $request, Registry $registry): JsonResponse
    {
        $grant = $registry->grant((string) $request->getUser(), (string) $request->getPassword());

        if ($grant !== null && $grant['build'] !== null
            && ! Build::query()->whereKey($grant['build'])->whereIn('status', [BuildStatus::Assigned, BuildStatus::Running])->exists()) {
            $grant = null;
        }

        if ($grant === null) {
            return response()->json(['errors' => [['code' => 'UNAUTHORIZED', 'message' => 'authentication required']]], 401)
                ->header('WWW-Authenticate', 'Basic realm="falak-registry"');
        }

        $access = [];

        foreach (self::scopes((string) $request->server('QUERY_STRING')) as $scope) {
            // type:name:actions — the name may hold ':' only in theory (no port in repository names), so split at both ends.
            if (preg_match('/^([a-z]+):(.+):([a-z*,]+)$/', $scope, $m) !== 1) {
                continue;
            }

            $actions = Registry::allowed($grant, $m[1], $m[2], array_values(array_unique(explode(',', $m[3]))));

            if ($actions !== []) {
                $access[] = ['type' => $m[1], 'name' => $m[2], 'actions' => $actions];
            }
        }

        $token = $registry->token((string) $request->getUser(), $access);

        return response()->json([
            'token' => $token,
            'access_token' => $token,
            'expires_in' => Registry::TOKEN_TTL,
            'issued_at' => now()->toIso8601ZuluString(),
        ], 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Every `scope` parameter (clients repeat it, which PHP's query parsing would collapse; one may also list several
     * separated by spaces).
     *
     * @return list<string>
     */
    private static function scopes(string $query): array
    {
        $scopes = [];

        foreach (explode('&', $query) as $pair) {
            [$key, $value] = explode('=', $pair, 2) + [1 => ''];

            if (urldecode($key) === 'scope') {
                array_push($scopes, ...array_filter(explode(' ', urldecode($value))));
            }
        }

        return $scopes;
    }
}
