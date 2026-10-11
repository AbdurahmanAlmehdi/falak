<?php

namespace Falak\Deployments\Http\Controllers;

use Falak\Config\Contracts\ManagedResources;
use Falak\Deployments\Application\Actions\UpdateDeploySettings;
use Falak\Deployments\Application\Actions\UpdateWatchSettings;
use Falak\Deployments\Application\Watch\Migrations;
use Falak\Deployments\Domain\Enums\Strategy;
use Falak\Deployments\Domain\Models\SiteSettings;
use Falak\Deployments\Domain\Policies\DeploymentPermissions;
use Falak\Identity\Contracts\AuditLog;
use Falak\Kernel\Http\Controller;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteDeploySettings;
use Falak\Sites\Contracts\SiteRuntime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class DeploySettingsController extends Controller
{
    use ResolvesSites;

    /** JSON for the Settings tab's Deploy / Source sections; a browser visit opens the Deploy section. */
    public function show(Request $request, string $site): JsonResponse|RedirectResponse
    {
        $data = $this->site($request->user(), $site);

        if (! $this->wantsPanelJson($request)) {
            $panel = app(ProjectDirectory::class)->serviceUrl(ServiceKind::Site, $data->id, 'settings');

            return redirect($panel !== null ? "{$panel}/deploy" : '/projects');
        }

        $settings = SiteSettings::for($data);
        $manage = $this->can($request->user(), $data, DeploymentPermissions::MANAGE);

        return response()->json(['data' => [
            'settings' => [
                'strategy' => $settings->effectiveStrategy($data)->value,
                'batch_size' => $settings->batch_size,
                'keep_releases' => $settings->keep_releases,
                'health_enabled' => $settings->health_enabled,
                'health_path' => $settings->health_path,
                'health_status' => $settings->health_status,
                'health_timeout_s' => $settings->health_timeout_s,
                'health_retries' => $settings->health_retries,
                'health_retry_delay_s' => $settings->health_retry_delay_s,
                'secrets_mode' => $settings->effectiveSecretsMode($data),
            ],
            // Container sites can take their secret variables as files instead of environment variables.
            'secretFiles' => $data->runtime === SiteRuntime::Docker,
            'defaultHealthPath' => $data->healthCheckPath ?: '/',
            'strategies' => array_map(fn (Strategy $s) => ['value' => $s->value, 'label' => $s->label(), 'description' => $s->description()], Strategy::for($data->runtime)),
            'pushToDeploy' => $data->pushToDeploy,
            'hasRepository' => $data->repository !== null,
            'branch' => $data->branch,
            'hookUrl' => $manage && $settings->hook_token ? $this->hookUrl($settings->hook_token) : null,
            'hasHook' => $settings->hook_token_hash !== null,
            'watch' => self::watchResource($data, $settings),
            // Settings the project's falak.yaml manages (/deploy/...), read-only here.
            'config' => app(ManagedResources::class)->managed('site', $data->id)->toArray(),
            'can' => ['manage' => $manage],
        ]]);
    }

    /**
     * The watch after a release goes live: settings plus what the card says about them (the site runs migrations a
     * rollback won't reverse; production services are suggested to turn it on).
     *
     * @return array<string, mixed>
     */
    public static function watchResource(SiteData $site, SiteSettings $settings): array
    {
        $projects = app(ProjectDirectory::class);
        $placed = $projects->projectOf(ServiceKind::Site, $site->id);
        $environment = $placed !== null ? $projects->environment($placed->environmentId) : null;

        return [
            ...$settings->watch(),
            'migrations' => Migrations::forSite($site),
            // A site in no project counts as production (as for resource limits).
            'production' => $environment === null || $environment->isProduction,
        ];
    }

    public function updateWatch(Request $request, string $site, UpdateWatchSettings $update): RedirectResponse
    {
        $data = $this->site($request->user(), $site, DeploymentPermissions::MANAGE);
        $input = $request->validate(UpdateWatchSettings::rules());
        self::guardWatch($data, $input);
        $update($data, $input, (string) $request->user()?->getAuthIdentifier());

        return back();
    }

    public function update(Request $request, string $site, UpdateDeploySettings $update): RedirectResponse
    {
        $data = $this->site($request->user(), $site, DeploymentPermissions::MANAGE);
        $input = $request->validate([
            'strategy' => ['required', Rule::enum(Strategy::class)],
            'batch_size' => ['required', 'integer', 'min:1', 'max:100'],
            'keep_releases' => ['required', 'integer', 'min:1', 'max:50'],
            'health_enabled' => ['required', 'boolean'],
            'health_path' => ['nullable', 'string', 'max:255', 'regex:/^\/[^\s]*$/'],
            'health_status' => ['required', 'integer', 'min:100', 'max:599'],
            'health_timeout_s' => ['required', 'integer', 'min:1', 'max:120'],
            'health_retries' => ['required', 'integer', 'min:1', 'max:30'],
            'health_retry_delay_s' => ['required', 'integer', 'min:0', 'max:300'],
            'secrets_mode' => ['sometimes', Rule::in([SiteSettings::SECRETS_ENV, SiteSettings::SECRETS_FILES])],
        ]);

        $settings = SiteSettings::for($data);
        app(ManagedResources::class)->managed('site', $data->id)->guard([
            'strategy' => '/deploy/strategy', 'batch_size' => '/deploy/batch_size', 'keep_releases' => '/deploy/keep_releases', 'secrets_mode' => '/deploy/secrets',
            'health_enabled' => '/deploy/health/enabled', 'health_path' => '/deploy/health/path', 'health_status' => '/deploy/health/status',
            'health_timeout_s' => '/deploy/health/timeout', 'health_retries' => '/deploy/health/retries', 'health_retry_delay_s' => '/deploy/health/retry_delay',
        ], $input, [
            'strategy' => $settings->effectiveStrategy($data)->value, 'batch_size' => $settings->batch_size, 'keep_releases' => $settings->keep_releases,
            'secrets_mode' => $settings->secrets_mode, 'health_enabled' => $settings->health_enabled, 'health_path' => $settings->health_path,
            'health_status' => $settings->health_status, 'health_timeout_s' => $settings->health_timeout_s, 'health_retries' => $settings->health_retries,
            'health_retry_delay_s' => $settings->health_retry_delay_s,
        ]);

        $update($data, $input, (string) $request->user()?->getAuthIdentifier());

        return back();
    }

    /**
     * The watch settings the project's falak.yaml manages can't change here (422).
     *
     * @param  array<string, mixed>  $input
     */
    public static function guardWatch(SiteData $site, array $input): void
    {
        $watch = SiteSettings::for($site)->watch();
        $fields = [];

        foreach (['enabled', 'minutes', 'on_trigger', 'health', 'health_failures', 'crashes', 'errors', 'issues'] as $field) {
            $fields[$field] = "/deploy/watch/{$field}";
        }

        app(ManagedResources::class)->managed('site', $site->id)->guard($fields, $input, $watch);
    }

    public function pushToDeploy(Request $request, string $site, SiteDeploySettings $sites): RedirectResponse
    {
        $data = $this->site($request->user(), $site, DeploymentPermissions::MANAGE);
        $input = $request->validate(['enabled' => ['required', 'boolean']]);
        $warnings = $sites->setPushToDeploy($data->id, (bool) $input['enabled'], (string) $request->user()?->getAuthIdentifier());

        return back()->with('warnings', $warnings);
    }

    public function rotateHook(Request $request, string $site, AuditLog $audit): RedirectResponse
    {
        $data = $this->site($request->user(), $site, DeploymentPermissions::MANAGE);
        SiteSettings::for($data)->rotateHookToken();
        $audit->record('deployments.hook_rotated', 'site', $data->id, [], $data->organizationId);

        return back();
    }

    public function disableHook(Request $request, string $site, AuditLog $audit): RedirectResponse
    {
        $data = $this->site($request->user(), $site, DeploymentPermissions::MANAGE);
        SiteSettings::for($data)->forceFill(['hook_token' => null, 'hook_token_hash' => null])->save();
        $audit->record('deployments.hook_disabled', 'site', $data->id, [], $data->organizationId);

        return back();
    }

    private function hookUrl(string $token): string
    {
        return rtrim((string) (config('fleet.panel_url') ?: config('app.url')), '/')."/api/deploy/{$token}";
    }
}
