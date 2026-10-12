<?php

namespace Falak\Sites\Http\Controllers;

use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Contracts\OrganizationDirectory;
use Falak\Kernel\Http\Controller;
use Falak\Projects\Contracts\VariableReferences;
use Falak\Sites\Application\Actions\SaveEnvironment;
use Falak\Sites\Domain\Dotenv;
use Falak\Sites\Domain\Models\EnvironmentVersion;
use Falak\Sites\Domain\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Environment variables: values are only sent to the browser on an explicit, audited reveal.
 */
final class EnvironmentController extends Controller
{
    use GuardsManagedFields;
    use PresentsSites;

    /**
     * JSON for the service panel's Variables tab (keys, exposure, versions, references); a browser visit opens the
     * panel. Values only leave the server on an explicit, audited reveal, except values that are nothing but
     * `${{ service.KEY }}` / `${{ secrets.NAME }}` references (they hold no secret).
     */
    public function show(Request $request, Site $site, OrganizationDirectory $directory, VariableReferences $references): JsonResponse|RedirectResponse
    {
        $this->authorize('view', $site);

        if (! $this->wantsPanelJson($request)) {
            return $this->toPanel($site, 'variables');
        }

        $versions = $site->environmentVersions()->limit(25)->get(['id', 'site_id', 'version', 'exposed', 'changed_keys', 'created_by', 'created_at']);
        $current = EnvironmentVersion::query()->where('site_id', $site->id)->orderByDesc('version')->first();
        $variables = $current !== null ? array_map('strval', $current->variables) : [];
        $users = [];

        $referenceOnly = array_filter(
            $variables,
            fn (string $value) => preg_match(VariableReferences::PATTERN, $value) === 1 && trim((string) preg_replace(VariableReferences::PATTERN, '', $value)) === '',
        );
        // Checked without reading secrets: opening the panel is not a read of the secret store.
        $referenceErrors = $references->referencesIn($variables) !== [] ? $references->check($site->id, $variables) : [];

        return response()->json(['data' => [
            'site' => ['id' => $site->id, 'name' => $site->name],
            'current' => $current ? [
                'version' => $current->version,
                // Names only; values need a reveal.
                'keys' => array_keys($variables),
                'exposed' => array_values(array_intersect($current->exposed, array_keys($variables))),
                'references' => $referenceOnly,
                'referencing' => array_values(array_unique(array_map(fn (array $r) => $r['variable'], $references->referencesIn($variables)))),
                'reference_errors' => $referenceErrors,
                'created_at' => $current->created_at->toIso8601String(),
            ] : null,
            'versions' => $versions->map(function (EnvironmentVersion $version) use ($directory, &$users) {
                $by = $version->created_by;

                if ($by !== null && ! array_key_exists($by, $users)) {
                    $users[$by] = $directory->findUser($by)?->name;
                }

                return [
                    'version' => $version->version,
                    'changed_keys' => $version->changed_keys,
                    'created_by' => $by !== null ? $users[$by] : null,
                    'created_at' => $version->created_at->toIso8601String(),
                ];
            })->values(),
            // Variables the project's falak.yaml sets (/env/<KEY>, /expose), read-only here.
            'config' => $this->managedFields($site)->toArray(),
            'can' => [
                'reveal' => $request->user()?->can('revealEnvironment', $site) ?? false,
                'update' => $request->user()?->can('updateEnvironment', $site) ?? false,
            ],
        ]])->header('Cache-Control', 'no-store');
    }

    /**
     * PATCH /sites/{site}/environment — apply staged row edits (set / unset / exposure) as one new version,
     * without the browser ever holding the other values.
     */
    public function patch(Request $request, Site $site, SaveEnvironment $save): JsonResponse
    {
        $this->authorize('updateEnvironment', $site);

        $data = $request->validate([
            'set' => ['present', 'array', 'max:500'],
            'set.*' => ['nullable', 'string', 'max:65535'],
            'unset' => ['present', 'array', 'max:500'],
            'unset.*' => ['string', 'regex:'.Dotenv::KEY_PATTERN],
            'exposed' => ['present', 'array'],
            'exposed.*' => ['boolean'],
            'base_version' => ['nullable', 'integer'],
        ]);

        foreach (array_keys($data['set']) as $key) {
            if (preg_match(Dotenv::KEY_PATTERN, (string) $key) !== 1) {
                throw ValidationException::withMessages(['set' => "Invalid variable name \"{$key}\": use letters, digits and underscores, not starting with a digit."]);
            }
        }

        foreach (array_keys($data['exposed']) as $key) {
            if (preg_match(Dotenv::KEY_PATTERN, (string) $key) !== 1) {
                throw ValidationException::withMessages(['exposed' => "Invalid variable name \"{$key}\"."]);
            }
        }

        $current = EnvironmentVersion::query()->where('site_id', $site->id)->orderByDesc('version')->first();

        if (isset($data['base_version']) && $current !== null && (int) $data['base_version'] !== $current->version) {
            throw ValidationException::withMessages(['base_version' => "Someone saved version {$current->version} while you were editing. Reload to see their changes."]);
        }

        $variables = $current !== null ? array_map('strval', $current->variables) : [];
        $exposed = $current->exposed ?? [];

        foreach ($data['unset'] as $key) {
            unset($variables[$key]);
        }

        foreach ($data['set'] as $key => $value) {
            $variables[(string) $key] = (string) $value;
        }

        foreach ($data['exposed'] as $key => $on) {
            $exposed = $on ? [...$exposed, (string) $key] : array_values(array_diff($exposed, [(string) $key]));
        }

        $this->guardVariables($site, $current !== null ? array_map('strval', $current->variables) : [], $variables, $current->exposed ?? [], $exposed, 'set');
        $version = $save($site, $variables, $exposed, $request->user()?->getAuthIdentifier());

        return response()->json(['data' => ['version' => $version->version ?? $current?->version]]);
    }

    public function reveal(Request $request, Site $site, AuditLog $audit): JsonResponse
    {
        $this->authorize('revealEnvironment', $site);

        $version = $request->integer('version') ?: null;
        $environment = EnvironmentVersion::query()
            ->where('site_id', $site->id)
            ->when($version, fn ($q, $v) => $q->where('version', $v), fn ($q) => $q->orderByDesc('version'))
            ->firstOrFail()
            ->toData();

        $audit->record('site.environment_revealed', 'site', $site->id, ['version' => $environment->version], $site->organization_id);

        return response()->json([
            'data' => [
                'version' => $environment->version,
                'content' => $environment->toDotenv(),
                'exposed' => $environment->exposedToDeployScript,
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, Site $site, SaveEnvironment $save): RedirectResponse|JsonResponse
    {
        $this->authorize('updateEnvironment', $site);

        $data = $request->validate([
            'content' => ['present', 'nullable', 'string', 'max:65535'],
            'exposed' => ['present', 'array'],
            'exposed.*' => ['string', 'regex:'.Dotenv::KEY_PATTERN],
            'base_version' => ['nullable', 'integer'],
        ]);

        try {
            $variables = Dotenv::parse((string) $data['content']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['content' => $e->getMessage()]);
        }

        $latest = EnvironmentVersion::query()->where('site_id', $site->id)->max('version');

        if (isset($data['base_version']) && $latest !== null && (int) $data['base_version'] !== (int) $latest) {
            throw ValidationException::withMessages(['content' => "Someone saved version {$latest} while you were editing. Reveal again to merge your changes."]);
        }

        $current = EnvironmentVersion::query()->where('site_id', $site->id)->orderByDesc('version')->first();
        $this->guardVariables($site, $current !== null ? array_map('strval', $current->variables) : [], $variables, $current->exposed ?? [], array_values($data['exposed']), 'content');
        $saved = $save($site, $variables, array_values($data['exposed']), $request->user()?->getAuthIdentifier());

        return $this->wantsPanelJson($request) ? response()->json(['data' => ['version' => $saved->version ?? $latest]]) : back();
    }

    public function restore(Request $request, Site $site, int $version, SaveEnvironment $save): RedirectResponse|JsonResponse
    {
        $this->authorize('updateEnvironment', $site);

        $old = EnvironmentVersion::query()->where('site_id', $site->id)->where('version', $version)->firstOrFail();

        $current = EnvironmentVersion::query()->where('site_id', $site->id)->orderByDesc('version')->first();
        $this->guardVariables($site, $current !== null ? array_map('strval', $current->variables) : [], array_map('strval', $old->variables), $current->exposed ?? [], $old->exposed, 'version');
        $saved = $save($site, array_map('strval', $old->variables), $old->exposed, $request->user()?->getAuthIdentifier(), 'site.environment_restored');

        return $this->wantsPanelJson($request) ? response()->json(['data' => ['version' => $saved?->version]]) : back();
    }
}
