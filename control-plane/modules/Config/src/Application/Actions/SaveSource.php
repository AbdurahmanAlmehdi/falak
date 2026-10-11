<?php

namespace Falak\Config\Application\Actions;

use Falak\Config\Domain\Models\ConfigSource;
use Falak\Identity\Contracts\AuditLog;
use Falak\Projects\Contracts\Data\ProjectData;
use Falak\SourceControl\Contracts\ProviderType;
use Falak\SourceControl\Contracts\SourceControlGateway;
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
    ) {}

    /**
     * @param  array{connection_id: string, repository: string, path?: ?string, enabled?: bool, allowed_credentials?: ?list<string>}  $data
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

        $source = ConfigSource::query()->firstOrNew(['project_id' => $project->id], ['organization_id' => $project->organizationId, 'created_by' => $userId]);
        $wasEnabled = $source->exists && $source->enabled;
        $source->fill([
            'source_connection_id' => $connection->id,
            'repository' => trim($data['repository'], '/'),
            'path' => $path,
            'enabled' => (bool) ($data['enabled'] ?? true),
            ...(array_key_exists('allowed_credentials', $data)
                ? ['allowed_credentials' => array_values(array_unique(array_filter(array_map('trim', (array) $data['allowed_credentials']))))] : []),
        ])->save();

        if ($source->enabled) {
            try {
                // Pushes and pull requests of the repository must reach Falak.
                $this->gateway->pinWebhook($source->source_connection_id, $source->repository);
            } catch (Throwable $e) {
                Log::warning('config: webhook not registered', ['source' => $source->id, 'error' => $e->getMessage()]);
            }
        }

        $this->audit->record($wasEnabled && ! $source->enabled ? 'config.source_disabled' : 'config.source_saved', 'project', $project->id,
            ['repository' => $source->repository, 'path' => $source->path, 'enabled' => $source->enabled], $project->organizationId, $userId);

        return $source;
    }
}
