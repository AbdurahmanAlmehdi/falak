<?php

namespace Falak\Terminal\Http\Controllers\Api;

use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Terminal\Application\Actions\CloseSession;
use Falak\Terminal\Application\Actions\OpenSession;
use Falak\Terminal\Application\ConsoleTargets;
use Falak\Terminal\Domain\Data\ConsoleTarget;
use Falak\Terminal\Domain\Models\TerminalSession;
use Falak\Terminal\Domain\Policies\TerminalSessionPolicy;
use Falak\Terminal\Http\Controllers\ConsoleController;
use Falak\Terminal\Infrastructure\RelayTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Consoles for `falak console` (docs/plans/CONSOLE.md "Routes"): open a session and get a relay URL to dial, reconnect
 * tickets, close. The CLI only speaks the relay, so every route answers 409 while it is off.
 */
final class ConsoleApiController extends Controller
{
    public const RELAY_OFF = 'The terminal relay is off on this installation.';

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly ConsoleTargets $targets,
        private readonly OpenSession $open,
        private readonly RelayTokens $tokens,
        private readonly TerminalSessionPolicy $policy,
    ) {}

    /** GET /api/v1/sites/{site}/console: {data: {servers: [{id, name, leader}], services, runtime}} */
    public function siteOptions(Request $request, string $site): JsonResponse
    {
        $organizationId = $this->guard($request);

        return response()->json(['data' => $this->targets->siteOptions($this->targets->site($request->user(), $organizationId, $site))]);
    }

    /** POST /api/v1/sites/{site}/console {server_id?, service?, cols?, rows?}: 201 */
    public function site(Request $request, string $site): JsonResponse
    {
        $organizationId = $this->guard($request);
        $data = $request->validate(ConsoleController::siteRules());

        return $this->opened($request, $organizationId, $this->targets->forSite($this->targets->site($request->user(), $organizationId, $site), $data['server_id'] ?? null, $data['service'] ?? null), $data);
    }

    /** POST /api/v1/databases/instances/{instance}/console {cols?, rows?}: 201 */
    public function database(Request $request, string $instance): JsonResponse
    {
        $organizationId = $this->guard($request);
        $data = $request->validate(ConsoleController::sizeRules());

        return $this->opened($request, $organizationId, $this->targets->forDatabase($this->targets->instance($request->user(), $organizationId, $instance)), $data);
    }

    /** POST /api/v1/servers/{server}/console {user?, cols?, rows?}: 201 */
    public function server(Request $request, string $server): JsonResponse
    {
        $organizationId = $this->guard($request);
        $data = $request->validate([
            'user' => ['nullable', 'string', 'regex:/^[a-z_][a-z0-9_-]{0,31}$/'],
            ...ConsoleController::sizeRules(),
        ]);

        return $this->opened($request, $organizationId, $this->targets->forServer($this->targets->server($organizationId, $server), $data['user'] ?? null), $data);
    }

    /** POST /api/v1/sandboxes/{sandbox}/console {cols?, rows?}: 201 (sandboxes.use, not terminal.open) */
    public function sandbox(Request $request, string $sandbox): JsonResponse
    {
        abort_unless(RelayTokens::enabled(), 409, self::RELAY_OFF);
        $organizationId = $this->organization->requireId();
        $data = $request->validate(ConsoleController::sizeRules());

        return $this->opened($request, $organizationId, $this->targets->forSandbox($this->targets->sandbox($request->user(), $organizationId, $sandbox)), $data);
    }

    /** POST /api/v1/terminal/sessions/{session}/ticket: {data: {url, writable}} (reconnect) */
    public function ticket(Request $request, TerminalSession $session): JsonResponse
    {
        abort_unless(RelayTokens::enabled(), 409, self::RELAY_OFF);

        return response()->json(['data' => ConsoleController::issueTicket($request, $session, $this->tokens, $this->policy)]);
    }

    /** DELETE /api/v1/terminal/sessions/{session}: 204 */
    public function destroy(TerminalSession $session, CloseSession $close): Response
    {
        abort_unless(RelayTokens::enabled(), 409, self::RELAY_OFF);
        $this->authorize('close', $session);

        $close($session, 'closed');

        return response()->noContent();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function opened(Request $request, string $organizationId, ConsoleTarget $target, array $data): JsonResponse
    {
        $user = $request->user();
        $session = $this->open->open($organizationId, (string) $user?->getAuthIdentifier(), $target, (int) ($data['cols'] ?? 80), (int) ($data['rows'] ?? 24), requireRelay: true);
        $writable = $user !== null && $this->policy->canType($user, $session);

        return response()->json(['data' => [
            'session_id' => $session->id,
            'url' => $this->tokens->clientUrl($session, (string) $user?->getAuthIdentifier(), $writable),
            'writable' => $writable,
            'server_id' => $session->server_id,
            'server_name' => $session->server_name,
            'target_type' => $session->targetType(),
            'target_name' => $session->target_name,
            'container' => $session->container,
        ]], 201);
    }

    /** The relay is on and the user may open terminals; the organization id. */
    private function guard(Request $request): string
    {
        abort_unless(RelayTokens::enabled(), 409, self::RELAY_OFF);
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'terminal.open');

        return $organizationId;
    }
}
