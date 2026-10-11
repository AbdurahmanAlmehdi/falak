<?php

namespace Falak\Databases\Http\Controllers;

use Carbon\CarbonImmutable;
use Falak\Databases\Application\Actions\ConfigurePitr;
use Falak\Databases\Application\Actions\DecidePitrRestore;
use Falak\Databases\Application\Actions\RestoreToTime;
use Falak\Databases\Application\Actions\TakePitrBase;
use Falak\Databases\Domain\Enums\RestoreStatus;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Domain\Models\Restore;
use Falak\Kernel\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Point-in-time recovery of an instance: its settings, a base backup on demand, a restore to a time (a new read-only
 * instance) and the decision about it (swap, keep, discard). Restoring takes the restore permission (admins); a
 * customer's age identity arrives posted as JSON (never flashed into the session) and is used for that restore only.
 */
final class PitrController extends Controller
{
    use GuardsConfigManaged;
    use PresentsDatabases;

    /**
     * PUT /databases/instances/{instance}/pitr {enabled, storage_provider_id?, encryption_mode?, age_recipient?, window_days?, base_interval_days?}
     */
    public function update(Request $request, DatabaseInstance $instance, ConfigurePitr $configure): RedirectResponse|JsonResponse
    {
        $this->authorize('manage', $instance);

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'storage_provider_id' => ['nullable', 'string', 'max:26'],
            'encryption_mode' => ['nullable', Rule::in(['cp', 'customer'])],
            'age_recipient' => ['nullable', 'string', 'max:80'],
            'window_days' => ['nullable', 'integer', 'min:1', 'max:35'],
            'base_interval_days' => ['nullable', 'integer', 'min:1', 'max:35'],
        ]);
        $this->guardPitr($instance, $data);

        // Less history (PITR off, a shorter window) is what restoring needs: admins only.
        $shrinks = ($instance->pitr_enabled && ! $data['enabled'])
            || (isset($data['window_days']) && (int) $data['window_days'] < $instance->pitr_window_days);

        if ($shrinks) {
            $this->authorize('restore', $instance);
        }

        $configure($instance, $data, $request->user()?->getAuthIdentifier());

        return $request->wantsJson() && $request->header('X-Inertia') === null
            ? response()->json(['data' => $this->presentPitr($instance->refresh())])
            : back();
    }

    /**
     * POST /databases/instances/{instance}/pitr/base — a base backup now.
     */
    public function base(Request $request, DatabaseInstance $instance, TakePitrBase $base): RedirectResponse
    {
        $this->authorize('manage', $instance);

        if (! $instance->pitr_enabled) {
            throw ValidationException::withMessages(['pitr' => 'Turn point-in-time recovery on first.']);
        }

        if ($base($instance, 'manual', $request->user()?->getAuthIdentifier()) === null) {
            throw ValidationException::withMessages(['pitr' => 'A base backup is already running.']);
        }

        return back();
    }

    /**
     * POST /databases/instances/{instance}/pitr/restore {target_time | "latest", identity?} (JSON) — a new read-only
     * instance at the time, or at the latest point (everything shipped). The answer names the restore; the instance page
     * shows it until a decision.
     */
    public function restore(Request $request, DatabaseInstance $instance, RestoreToTime $restore): JsonResponse
    {
        $this->authorize('restore', $instance);

        $data = $request->validate([
            'target_time' => ['required', 'string', 'max:40'],
            // Customer-held keys only: used for this restore, never stored.
            'identity' => ['nullable', 'string', 'max:200'],
        ]);

        try {
            $target = $data['target_time'] === 'latest' ? null : CarbonImmutable::parse($data['target_time'])->utc();
        } catch (Throwable) {
            throw ValidationException::withMessages(['target_time' => 'Give the time as RFC 3339 (2026-10-09T12:30:00Z), or "latest".']);
        }

        $result = $restore($instance, $target, $request->user()?->getAuthIdentifier(), $data['identity'] ?? null);

        return response()->json(['data' => ['id' => $result->id, 'restored_instance_id' => $result->restored_instance_id]], 202);
    }

    /**
     * POST /databases/pitr-restores/{restore}/inspection — the copy's read-only account (JSON), for whoever may restore.
     */
    public function inspection(Restore $restore): JsonResponse
    {
        $this->authorize('restore', $restore);

        if ($restore->type !== Restore::PITR || $restore->status !== RestoreStatus::AwaitingDecision || $restore->inspection_password === null) {
            throw ValidationException::withMessages(['restore' => 'This restore has no copy to inspect.']);
        }

        return response()->json(['data' => ['username' => RestoreToTime::INSPECTION_USER, 'password' => $restore->inspection_password]]);
    }

    /**
     * POST /databases/pitr-restores/{restore}/decision {decision: swap|keep|discard}
     */
    public function decide(Request $request, Restore $restore, DecidePitrRestore $decide): RedirectResponse|JsonResponse
    {
        $this->authorize('restore', $restore);

        $data = $request->validate(['decision' => ['required', Rule::in(DecidePitrRestore::DECISIONS)]]);
        $decide($restore, $data['decision'], $request->user()?->getAuthIdentifier());

        return $request->wantsJson() && $request->header('X-Inertia') === null
            ? response()->json(['data' => ['id' => $restore->id, 'status' => $restore->status->value]])
            : back();
    }
}
