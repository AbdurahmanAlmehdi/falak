<?php

namespace Falak\Terminal\Http\Controllers;

use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Terminal\Application\Actions\OpenSession;
use Falak\Terminal\Application\ConsoleTargets;
use Falak\Terminal\Domain\Models\TerminalSession;
use Falak\Terminal\Domain\Policies\TerminalSessionPolicy;
use Falak\Terminal\Infrastructure\RelayTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Consoles from the dashboard (docs/plans/CONSOLE.md): open one for a site or a database container (then the session
 * page), the choices a site offers, and relay tickets for the session page.
 */
final class ConsoleController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly ConsoleTargets $targets,
    ) {}

    /** GET sites/{site}/console: {data: {servers, services, runtime}} for the Console dialog. */
    public function siteOptions(Request $request, string $site): JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'terminal.open');

        return response()->json(['data' => $this->targets->siteOptions($this->targets->site($request->user(), $organizationId, $site))]);
    }

    /** POST sites/{site}/console {server_id?, service?} */
    public function site(Request $request, string $site, OpenSession $open): RedirectResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'terminal.open');
        $data = $request->validate(self::siteRules());

        $target = $this->targets->forSite($this->targets->site($request->user(), $organizationId, $site), $data['server_id'] ?? null, $data['service'] ?? null);
        $session = $open->open($organizationId, (string) $request->user()?->getAuthIdentifier(), $target, (int) ($data['cols'] ?? 80), (int) ($data['rows'] ?? 24));

        return to_route('terminal.sessions.show', $session);
    }

    /** POST databases/instances/{instance}/console */
    public function database(Request $request, string $instance, OpenSession $open): RedirectResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'terminal.open');
        $data = $request->validate(self::sizeRules());

        $target = $this->targets->forDatabase($this->targets->instance($request->user(), $organizationId, $instance));
        $session = $open->open($organizationId, (string) $request->user()?->getAuthIdentifier(), $target, (int) ($data['cols'] ?? 80), (int) ($data['rows'] ?? 24));

        return to_route('terminal.sessions.show', $session);
    }

    /** POST terminal/sessions/{session}/ticket: {url, writable} for a relayed, live session the user may view. */
    public function ticket(Request $request, TerminalSession $session, RelayTokens $tokens, TerminalSessionPolicy $policy): JsonResponse
    {
        return response()->json(self::issueTicket($request, $session, $tokens, $policy));
    }

    /**
     * @return array{url: string, writable: bool}
     */
    public static function issueTicket(Request $request, TerminalSession $session, RelayTokens $tokens, TerminalSessionPolicy $policy): array
    {
        Gate::forUser($request->user())->authorize('view', $session);
        abort_unless($session->isRelayed() && $session->isLive() && RelayTokens::enabled(), 404, 'This session has no relay.');

        $user = $request->user();
        $writable = $user !== null && $policy->canType($user, $session);

        return ['url' => $tokens->clientUrl($session, (string) $user?->getAuthIdentifier(), $writable), 'writable' => $writable];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function siteRules(): array
    {
        return [
            'server_id' => ['nullable', 'string', 'max:255'],
            'service' => ['nullable', 'string', 'max:255'],
            ...self::sizeRules(),
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function sizeRules(): array
    {
        return [
            'cols' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'rows' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }
}
