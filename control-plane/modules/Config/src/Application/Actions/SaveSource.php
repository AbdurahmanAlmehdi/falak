<?php

namespace Falak\Config\Application\Actions;

use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Config\Domain\Models\ConfigSource;
use Falak\Identity\Contracts\AuditLog;
use Falak\Projects\Contracts\Data\ProjectData;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\SourceControl\Contracts\ProviderType;
use Falak\SourceControl\Contracts\SourceControlGateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Connect a project to its falak.yaml (or change, enable, disable it). Disabling keeps every resource and makes every
 * field editable again; the file is followed again when re-enabled.
 */
final class SaveSource
{
    public const PATH_PATTERN = '/^(?!.*(^|\/)\.\.(\/|$))[A-Za-z0-9._\/-]*falak\.(ya?ml|json)$/';

    public function __construct(
        private readonly SourceControlGateway $gateway,
        private readonly AuditLog $audit,
        private readonly ServerDirectory $servers,
    ) {}

    /**
     * @param  array{connection_id: string, repository: string, path?: ?string, enabled?: bool, allowed_credentials?: ?list<string>, allowed_servers?: ?list<string>}  $data
     */
    public function __invoke(ProjectData $project, array $data, ?string $userId): ConfigSource
    {
        $connection = rescue(fn () => $this->gateway->connection($data['connection_id']), null, false);

        if ($connection === null || $connection->organizationId !== $project->organizationId) {
            throw ValidationException::withMessages(['connection_id' => 'Unknown source control connection.']);
        }

        if ($connection->provider === ProviderType::Custom) {
            // [decision] Plain git servers have no API to read the file at a commit; the builder-side read the plan
            // describes comes later.
            throw ValidationException::withMessages(['connection_id' => 'Config as code needs GitHub, GitLab or Bitbucket: Falak can\'t read files from a plain git server yet.']);
        }

        $path = ltrim((string) ($data['path'] ?? '') ?: (string) config('config_as_code.default_path', 'falak.yaml'), '/');

        if (! preg_match(self::PATH_PATTERN, $path)) {
            throw ValidationException::withMessages(['path' => 'The file must be named falak.yaml, falak.yml or falak.json (in the repository or a folder of it).']);
        }

        $servers = array_key_exists('allowed_servers', $data) ? array_values(array_unique(array_filter(array_map('trim', (array) $data['allowed_servers'])))) : null;

        if ($servers !== null) {
            $known = array_map(fn ($s) => $s->name, $this->servers->forOrganization($project->organizationId));

            if (($unknown = array_values(array_diff($servers, $known))) !== []) {
                throw ValidationException::withMessages(['allowed_servers' => 'The organization has no server named "'.implode('", "', $unknown).'".']);
            }
        }

        $source = ConfigSource::query()->firstOrNew(['project_id' => $project->id], ['organization_id' => $project->organizationId, 'created_by' => $userId]);
        $wasEnabled = $source->exists && $source->enabled;
        $source->fill([
            'source_connection_id' => $connection->id,
            'repository' => trim($data['repository'], '/'),
            'path' => $path,
            'enabled' => (bool) ($data['enabled'] ?? true),
            ...(array_key_exists('separate_approver', $data) ? ['separate_approver' => (bool) $data['separate_approver']] : []),
            ...(array_key_exists('allowed_credentials', $data)
                ? ['allowed_credentials' => array_values(array_unique(array_filter(array_map('trim', (array) $data['allowed_credentials']))))] : []),
            ...($servers !== null ? ['allowed_servers' => $servers] : []),
        ])->save();

        if ($wasEnabled && ! $source->enabled) {
            // Disabling cancels the runs that wait, each under its environment's run lock (none is mid-apply then).
            foreach (ConfigRun::query()->where('project_id', $project->id)->whereIn('status', [RunStatus::Planning, RunStatus::AwaitingApproval])
                ->distinct()->pluck('environment_id') as $environmentId) {
                Cache::lock('config:environment:'.$environmentId, 600)->block(120, fn () => ConfigRun::query()
                    ->where('environment_id', $environmentId)->whereIn('status', [RunStatus::Planning, RunStatus::AwaitingApproval])
                    ->update(['status' => RunStatus::Cancelled->value, 'cancel_reason' => 'source_disabled', 'error' => 'Config as code was disabled for this project.',
                        'finished_at' => now(), 'updated_at' => now()]));
            }
        }

        if ($source->enabled) {
            try {
                // Pushes and pull requests of the repository must reach Falak.
                $this->gateway->pinWebhook($source->source_connection_id, $source->repository);
            } catch (Throwable $e) {
                Log::warning('config: webhook not registered', ['source' => $source->id, 'error' => $e->getMessage()]);
            }
        }

        $this->audit->record($wasEnabled && ! $source->enabled ? 'config.source_disabled' : 'config.source_saved', 'project', $project->id,
            ['repository' => $source->repository, 'path' => $source->path, 'enabled' => $source->enabled, 'allowed_servers' => $source->allowed_servers], $project->organizationId, $userId);

        return $source;
    }
}
