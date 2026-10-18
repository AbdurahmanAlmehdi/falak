<?php

namespace Falak\ControlPlane\Http\Controllers;

use Falak\ControlPlane\Application\Operators;
use Falak\ControlPlane\Application\ReleaseCheck;
use Falak\ControlPlane\Contracts\Exceptions\HostRequestRefused;
use Falak\ControlPlane\Contracts\HostChannel;
use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Kernel\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Falak updates: the running and the latest release, "Check now", and "Update now" (a host request:
 * falak-ctl update takes a backup, pulls the release, restarts the stack and rolls back on failure).
 */
final class UpdateController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly Operators $operators,
        private readonly ReleaseCheck $releases,
        private readonly HostChannel $host,
    ) {}

    public function show(Request $request): Response
    {
        $this->authorizeOperator($request);

        return Inertia::render('ControlPlane/Updates', [
            'current' => $this->releases->currentVersion(),
            'release' => $this->releases->latest(),
            'update_available' => $this->releases->updateAvailable(),
            'check_enabled' => $this->releases->enabled(),
            'channel' => $this->host->state(),
            'commands' => ['update' => 'sudo falak-ctl update', 'up' => 'sudo falak-ctl up'],
        ]);
    }

    public function check(Request $request): RedirectResponse
    {
        $this->authorizeOperator($request);

        if (! $this->releases->enabled()) {
            throw ValidationException::withMessages(['check' => 'The release check is turned off (FALAK_UPDATE_CHECK=false).']);
        }

        $result = $this->releases->check();

        return $result['error'] !== null
            ? back()->with('error', $result['error'])
            : back()->with('success', $this->releases->updateAvailable() ? "Falak {$result['tag']} is available." : 'Falak is up to date.');
    }

    public function update(Request $request, AuditLog $audit): RedirectResponse
    {
        $organizationId = $this->authorizeOperator($request);
        $latest = $this->releases->latest()['tag'] ?? null;
        $data = $request->validate(['version' => ['required', 'string', 'max:40']]);

        // The version the page showed must still be the latest, and newer than this panel.
        if ($data['version'] !== $latest || ! $this->releases->updateAvailable()) {
            throw ValidationException::withMessages(['version' => 'No newer release to update to; check again.']);
        }

        try {
            $id = $this->host->requestUpdate($latest, (string) $request->user()?->getAuthIdentifier());
        } catch (HostRequestRefused $e) {
            throw ValidationException::withMessages(['version' => $e->getMessage()]);
        }

        $audit->record('controlplane.update_requested', 'control_plane', null, [
            'request_id' => $id,
            'from' => $this->releases->currentVersion(),
            'to' => $latest,
        ], $organizationId);

        return back()->with('success', "Updating to {$latest}: the panel restarts in a few minutes.");
    }

    /**
     * Polled every 2 s by the page: the channel, the current request's log tail and the running version (it changes
     * once the update restarted the panel).
     */
    public function status(Request $request): JsonResponse
    {
        $this->authorizeOperator($request);
        $state = $this->host->state();
        // A request not picked up yet is newer than the last status.
        $id = $state['pending']['id'] ?? $state['status']['id'] ?? null;

        return response()->json([
            'current' => $this->releases->currentVersion(),
            'channel' => $state,
            'log' => $id !== null ? $this->host->log($id) : null,
        ]);
    }

    private function authorizeOperator(Request $request): string
    {
        $organizationId = $this->organization->requireId();

        // Other organizations of a shared install don't see the control plane at all.
        abort_unless($this->operators->isOperatorOrganization($organizationId), 404);
        abort_unless($this->operators->canManage($request->user(), $organizationId), 403);

        return $organizationId;
    }
}
