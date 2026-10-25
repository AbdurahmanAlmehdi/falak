<?php

namespace Falak\Sites\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Sites\Application\ComposeSettings;
use Falak\Sites\Contracts\ComposeSource;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Sites\Domain\Models\ComposeVersion;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Events\ComposeServicesUnpublished;
use Falak\Sites\Events\SiteUpdated;
use Falak\Sites\Infrastructure\Compose\YamlComposeInspector;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Settings → Compose: source (repo path / inline content, versioned), public services. Changes apply on the
 * next deploy; routing (public services) is re-applied by Edge on SiteUpdated.
 */
final class UpdateComposeSettings
{
    public function __construct(
        private readonly ComposeSettings $settings,
        private readonly YamlComposeInspector $inspector,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  compose_source, compose_file(s), compose_profiles, compose_services, compose_adjustments, compose_content, public_services
     * @return array{changed: list<string>, version: ?int, warnings: list<string>}
     */
    public function __invoke(Site $site, array $data, ?string $userId): array
    {
        if ($site->runtime !== SiteRuntime::Compose) {
            throw ValidationException::withMessages(['compose_source' => 'Only Docker Compose sites have compose settings.']);
        }

        $site->loadMissing('targets');
        $content = array_key_exists('compose_content', $data) && is_string($data['compose_content']) ? $data['compose_content'] : null;
        $source = isset($data['compose_source']) ? ComposeSource::from((string) $data['compose_source']) : ($site->compose_source ?? ComposeSource::Repo);
        $summary = null;

        if ($source === ComposeSource::Inline) {
            $current = ComposeVersion::query()->where('site_id', $site->id)->orderByDesc('version')->first();
            $content ??= $current?->content;

            if ($content === null) {
                throw ValidationException::withMessages(['compose_content' => 'Paste a compose file.']);
            }

            $summary = $this->settings->validateInline($site->organization_id, $content);
        } elseif ($content !== null && trim($content) !== '') {
            throw ValidationException::withMessages(['compose_content' => 'Repository sources read the compose file from the repository.']);
        }

        $public = array_key_exists('public_services', $data)
            ? $this->settings->publicServices($this->settings->resolveDomainChoices($site->organization_id, array_values((array) $data['public_services']), $site->slug, $site->leaderFirstServerIds(), $site->id), $site->serverIds(), $summary, $site)
            : ($summary !== null ? $this->settings->publicServices(array_values(array_map(fn ($p) => (array) $p, (array) $site->public_services)), $site->serverIds(), $summary, $site) : (array) $site->public_services);

        $project = $source === ComposeSource::Repo ? $this->settings->project($data, $site) : null;

        if ($project !== null && array_key_exists('compose_files', $data) && $site->source_connection_id !== null && $site->repository !== null) {
            $project['yaml'] = $this->settings->verifyRepository((string) $site->source_connection_id, (string) $site->repository, (string) ($site->branch ?: 'main'),
                $project, array_values(array_map(fn ($p) => (array) $p, $public)), (array) $site->latestEnvironment?->variables, $site->root_directory, $site->id);
        }

        // A service that leaves the stack can't stay public.
        $leaving = [...array_keys($project['services'] ?? []), ...array_column($project['extract'] ?? [], 'service')];
        $public = array_values(array_filter($public, fn ($p) => ! in_array((string) ((array) $p)['service'], $leaving, true)));
        $unpublished = array_values(array_diff(
            array_map(fn ($p) => (string) ($p['service'] ?? ''), array_filter((array) $site->public_services, 'is_array')),
            array_map(fn ($p) => (string) ((array) $p)['service'], $public),
        ));

        $version = null;

        $changed = DB::transaction(function () use ($site, $source, $content, $public, $userId, $project, &$version) {
            $before = ComposeVersion::query()->where('site_id', $site->id)->max('version');

            if ($source === ComposeSource::Inline && $content !== null) {
                $version = $this->settings->saveVersion($site, $content, $userId);
            }

            $site->fill([
                'compose_source' => $source,
                'compose_file' => $project['files'][0] ?? null,
                'compose_files' => ($project['files'] ?? []) ?: null,
                'compose_profiles' => ($project['profiles'] ?? []) ?: null,
                'compose_services' => ($project['services'] ?? []) ?: null,
                'compose_adjustments' => ($project['adjustments'] ?? []) ?: null,
                // The repository project as read now (unchanged when Falak can't read the repository).
                ...(isset($project['yaml']) ? ['compose_snapshot' => $project['yaml']] : []),
                'public_services' => $public === [] ? null : $public,
                'app_port' => $public[0]['host_port'] ?? null,
            ]);
            $changed = array_keys($site->getDirty());
            $site->save();

            if ($version !== null && $version !== (int) $before) {
                $changed[] = 'compose_content';
            }

            return $changed;
        });

        $warnings = ($project['extract'] ?? []) !== [] ? $this->settings->extract($site, $project['extract'], $project['yaml'] ?? null) : [];

        if (($project['extract'] ?? []) !== []) {
            $changed[] = 'compose_services';
        }

        if ($changed !== []) {
            $this->audit->record('site.compose_updated', 'site', $site->id, ['changed' => $changed, 'version' => $version], $site->organization_id);
            SiteUpdated::dispatch($site->id, $site->organization_id, $changed, $site->serverIds());
        }

        if ($unpublished !== []) {
            ComposeServicesUnpublished::dispatch($site->id, $site->organization_id, $unpublished);
        }

        return ['changed' => $changed, 'version' => $version, 'warnings' => $warnings];
    }
}
