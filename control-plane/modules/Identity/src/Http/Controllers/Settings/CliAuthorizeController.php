<?php

namespace Falak\Identity\Http\Controllers\Settings;

use Falak\Identity\Application\CliLogins;
use Falak\Identity\Domain\Models\User;
use Falak\Kernel\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The browser side of `falak login`: confirm the code, pick the organization, approve or deny. */
final class CliAuthorizeController extends Controller
{
    public function show(Request $request, CliLogins $logins, ?string $code = null): Response
    {
        /** @var User $user */
        $user = $request->user();
        $login = $code !== null ? $logins->findPending($code) : null;

        return Inertia::render('Identity/cli/authorize', [
            'code' => $code,
            'answered' => $request->session()->get('cliLoginAnswered'),
            'login' => $login ? [
                'client_name' => $login->client_name,
                'ip_address' => $login->ip_address,
                'requested_at' => $login->created_at->toIso8601String(),
                'expires_at' => $login->expires_at->toIso8601String(),
            ] : null,
            'organizations' => $user->organizations()->orderBy('name')->get(['identity_organizations.id', 'identity_organizations.name'])
                ->map(fn ($o) => ['id' => $o->id, 'name' => $o->name])->values(),
            'currentOrganizationId' => $user->current_organization_id,
        ]);
    }

    /** POST /cli/authorize: the code typed by hand. */
    public function lookup(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);

        return redirect()->route('cli.authorize.show', strtoupper(trim((string) $data['code'])));
    }

    public function approve(Request $request, CliLogins $logins, string $code): RedirectResponse
    {
        $data = $request->validate(['organization_id' => ['required', 'string', 'max:26']]);
        $login = $logins->findPending($code);
        if ($login === null) {
            return back()->withErrors(['code' => 'This login request has expired or was already answered. Run `falak login` again.']);
        }
        /** @var User $user */
        $user = $request->user();
        $logins->approve($login, $user, (string) $data['organization_id']);

        return redirect()->route('cli.authorize')->with('cliLoginAnswered', 'approved');
    }

    public function deny(Request $request, CliLogins $logins, string $code): RedirectResponse
    {
        $login = $logins->findPending($code);
        if ($login !== null) {
            /** @var User $user */
            $user = $request->user();
            $logins->deny($login, $user);
        }

        return redirect()->route('cli.authorize')->with('cliLoginAnswered', 'denied');
    }
}
