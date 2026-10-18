<?php

namespace Falak\Sites\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Sites\Application\SiteRules;
use Falak\Sites\Application\SourceControlLinker;
use Falak\Sites\Application\TargetProvisioner;
use Falak\Sites\Contracts\BuildMode;
use Falak\Sites\Contracts\OctaneServer;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Models\SiteTarget;
use Falak\Sites\Events\SiteUpdated;
use Illuminate\Validation\ValidationException;

/**
 * General settings: name, repository, runtime + versions, build mode, directories, ports, health check.
 */
final class UpdateSite
{
    public const FIELDS = [
        'name', 'runtime', 'build_mode', 'php_version', 'node_version', 'source_connection_id', 'repository', 'branch',
        'root_directory', 'push_to_deploy', 'web_directory', 'app_port', 'container_port', 'docker_image', 'dockerfile', 'compose_file', 'health_check_path',
        'test_domain_enabled',
    ];

    /** @var list<string> */
    public array $warnings = [];

    public function __construct(
        private readonly SiteRules $rules,
        private readonly TargetProvisioner $provisioner,
        private readonly SourceControlLinker $sourceControl,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return list<string> changed attributes
     */
    public function __invoke(Site $site, array $data): array
    {
        $site->loadMissing('targets');
        $original = $site->replicate()->setRawAttributes($site->getRawOriginal());

        $runtime = isset($data['runtime']) ? SiteRuntime::from((string) $data['runtime']) : $site->runtime;
        $buildMode = isset($data['build_mode']) ? BuildMode::from((string) $data['build_mode']) : $site->build_mode;
        $phpVersion = $runtime->isPhp() ? (string) ($data['php_version'] ?? $site->php_version ?? '') : null;

        if ($runtime->isPhp() && $phpVersion === '') {
            throw ValidationException::withMessages(['php_version' => 'Pick a PHP version.']);
        }

        if ($runtime->isFunction() !== $site->runtime->isFunction()) {
            throw ValidationException::withMessages(['runtime' => 'A function cannot change its runtime to a site runtime (or back); create a new service.']);
        }

        if ($runtime->isContainer() !== $site->runtime->isContainer()) {
            throw ValidationException::withMessages(['runtime' => 'Switching between container and native runtimes requires a new site.']);
        }

        $this->rules->runtimeAndFramework($site->framework, $runtime, $buildMode);
        $this->rules->targets($site->organization_id, $site->serverIds(), $runtime, $phpVersion, $buildMode, leaderId: $site->leaderTarget()?->server_id);

        if (array_key_exists('source_connection_id', $data)) {
            $this->rules->connection($site->organization_id, $data['source_connection_id']);
        }

        $attributes = array_intersect_key($data, array_flip(self::FIELDS));
        $attributes['runtime'] = $runtime;
        $attributes['build_mode'] = $buildMode;
        $attributes['php_version'] = $phpVersion;

        if (array_key_exists('web_directory', $attributes)) {
            $attributes['web_directory'] = trim((string) $attributes['web_directory'], '/');
        }

        if (array_key_exists('root_directory', $attributes)) {
            $attributes['root_directory'] = CreateSite::rootDirectory($attributes['root_directory']);
        }

        if ($runtime === SiteRuntime::Compose) {
            // Compose sites: the app port is the primary public service's host port (Settings → Compose).
            unset($attributes['app_port']);
            $attributes['container_port'] = null;
        } elseif ($runtime === SiteRuntime::Docker) {
            // Users set the container port only (app_port from older clients meant it); the loopback host port stays Falak's.
            $listen = $attributes['container_port'] ?? $attributes['app_port'] ?? $site->container_port ?? $site->app_port ?? config('sites.default_container_port', 3000);
            $attributes['container_port'] = (int) $listen;
            $attributes['app_port'] = $site->app_port ?? $this->rules->freePort($site->serverIds(), $site->id);
        } elseif ($runtime->proxiesToPort()) {
            $port = isset($attributes['app_port']) ? (int) $attributes['app_port'] : ($site->app_port ?? $this->rules->freePort($site->serverIds(), $site->id));
            $this->rules->portAvailable($port, $site->serverIds(), $site->id);
            $attributes['app_port'] = $port;
        } else {
            $attributes['app_port'] = null;
        }

        if ($runtime !== SiteRuntime::Docker) {
            $attributes['container_port'] = null;
        }

        $site->fill($attributes);

        // Octane follows the runtime: FrankenPHP's Octane server needs the FrankenPHP runtime, and non-PHP runtimes have no Octane.
        if ($site->laravel->octane && $site->isDirty('runtime')) {
            $site->laravel = ! $runtime->isPhp()
                ? $site->laravel->with(octane: false)
                : $site->laravel->with(octaneServer: $site->laravel->octaneServer?->supports($runtime) ? $site->laravel->octaneServer : OctaneServer::defaultFor($runtime));
        }

        $changed = array_keys($site->getDirty());

        if ($changed === []) {
            return [];
        }

        $site->save();

        $this->afterSave($site, $original, $changed);

        $this->audit->record('site.updated', 'site', $site->id, ['changed' => $changed], $site->organization_id);
        SiteUpdated::dispatch($site->id, $site->organization_id, $changed, $site->serverIds());

        return $changed;
    }

    /**
     * @param  list<string>  $changed
     */
    private function afterSave(Site $site, Site $original, array $changed): void
    {
        $repositoryChanged = array_intersect(['source_connection_id', 'repository'], $changed) !== [];

        if ($repositoryChanged) {
            $this->sourceControl->unlink($site->id, $original->source_connection_id, $original->repository, $original->deploy_key_id);
            $site->forceFill(['deploy_key_id' => null])->save();
            $this->warnings = $this->sourceControl->link($site);
        } elseif (in_array('push_to_deploy', $changed, true)) {
            if ($site->push_to_deploy) {
                $this->warnings = $this->sourceControl->syncWebhook($site);
            } elseif ($site->source_connection_id && $site->repository && ! $this->sourceControl->webhookStillNeeded($site->id, $site->source_connection_id, $site->repository)) {
                $this->sourceControl->unlink($site->id, $site->source_connection_id, $site->repository, null);
            }
        }

        // PHP-FPM pools follow the runtime and PHP version.
        $poolChanged = array_intersect(['runtime', 'php_version'], $changed) !== [];

        if (! $poolChanged) {
            return;
        }

        foreach ($site->targets as $target) {
            /** @var SiteTarget $target */
            if ($original->runtime === SiteRuntime::PhpFpm && $original->php_version) {
                $this->provisioner->removePool($site, $target->server_id, $original->php_version);
            }

            $target->setRelation('site', $site);
            $this->provisioner->start($target);
        }
    }
}
