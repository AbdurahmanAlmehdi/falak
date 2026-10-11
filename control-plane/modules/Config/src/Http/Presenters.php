<?php

namespace Falak\Config\Http;

use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Config\Domain\Models\ConfigSource;
use Falak\Deployments\Contracts\DeploymentDirectory;

/**
 * The API's `ConfigRun` and the dashboard's config source.
 */
final class Presenters
{
    /**
     * @return array<string, mixed>
     */
    public static function run(ConfigRun $run, bool $withFile = false): array
    {
        $issues = (array) ($run->errors ?? []);
        $statuses = [];
        $ids = array_values(array_filter(array_column((array) $run->deploy, 'site_id')));

        if ($ids !== []) {
            foreach (app(DeploymentDirectory::class)->recentForSites($ids, 50) as $summary) {
                $statuses[$summary->id] = $summary->status;
            }
        }

        return array_filter([
            'id' => $run->id,
            'project_id' => $run->project_id,
            'environment' => $run->environment,
            'environment_id' => $run->environment_id,
            'commit' => $run->commit,
            'branch' => $run->branch,
            'trigger' => $run->trigger->value,
            'status' => $run->status->value,
            'plan' => $run->plan,
            'errors' => array_values(array_filter($issues, fn (array $i) => ($i['severity'] ?? 'error') === 'error')),
            'warnings' => array_values(array_filter($issues, fn (array $i) => ($i['severity'] ?? 'error') === 'warning')),
            'error' => $run->error,
            'allow_destroy' => $run->allow_destroy,
            'requested_by' => $run->requested_by,
            'approved_by' => $run->approved_by,
            'approved_at' => $run->approved_at?->toIso8601String(),
            'error_code' => self::errorCode($run, $issues),
            'deployments' => array_map(fn (array $e) => [
                'unit' => $e['unit'],
                'site_id' => $e['site_id'] ?? null,
                'deployment_id' => $e['deployment_id'] ?? null,
                // pending | deploying | released | skipped | failed (Config's view of the unit)
                'status' => $e['status'] ?? 'pending',
                // the deployment's own status (queued, building, deploying, succeeded, failed, …) when there is one
                'deployment_status' => isset($e['deployment_id']) ? ($statuses[$e['deployment_id']] ?? null) : null,
                'error' => $e['error'] ?? null,
            ], (array) $run->deploy),
            'created_at' => $run->created_at->toIso8601String(),
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'url' => url("/projects/{$run->project_id}/settings").'?config_run='.$run->id.'#config',
            'file' => $withFile ? $run->file : null,
        ], fn ($v, $k) => $k !== 'file' || $withFile, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Why a failed run failed: `invalid` (the file has errors; see `errors`), `unreadable` (the file couldn't be read at
     * the commit), `apply_failed` (phase A or B failed; nothing was deployed for phase A), `deployment_failed` (a unit's
     * deployment failed; see `deployments[].status = failed`). Null unless the status is `failed`.
     *
     * @param  list<array<string, mixed>>  $issues
     */
    public static function errorCode(ConfigRun $run, array $issues): ?string
    {
        if ($run->status !== RunStatus::Failed) {
            return null;
        }

        if (collect((array) $run->deploy)->contains(fn (array $e) => in_array($e['status'] ?? null, ['failed', 'cancelled'], true) && ($e['deployment_id'] ?? null) !== null && ! str_starts_with((string) ($e['error'] ?? ''), 'Phase B'))) {
            return 'deployment_failed';
        }

        if (array_filter($issues, fn (array $i) => ($i['severity'] ?? 'error') === 'error') !== []) {
            return 'invalid';
        }

        if ($run->resolved === null && $run->plan === null) {
            return 'unreadable';
        }

        return 'apply_failed';
    }

    /**
     * @return ?array<string, mixed>
     */
    public static function source(?ConfigSource $source): ?array
    {
        if ($source === null) {
            return null;
        }

        return [
            'id' => $source->id,
            'connection_id' => $source->source_connection_id,
            'repository' => $source->repository,
            'path' => $source->path,
            'enabled' => $source->enabled,
            'experimental' => $source->experimental,
            'allowed_credentials' => array_values((array) ($source->allowed_credentials ?? [])),
            'created_at' => $source->created_at?->toIso8601String(),
        ];
    }
}
