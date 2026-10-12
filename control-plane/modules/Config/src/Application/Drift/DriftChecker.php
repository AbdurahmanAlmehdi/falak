<?php

namespace Falak\Config\Application\Drift;

use Falak\Config\Application\EnvMask;
use Falak\Config\Application\Planning\Planner;
use Falak\Config\Application\ScopeFactory;
use Falak\Config\Contracts\Change;
use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Models\ConfigDrift;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Config\Domain\Models\ConfigSource;
use Falak\Config\Events\ConfigDriftDetected;
use Falak\Projects\Contracts\Data\EnvironmentData;
use Illuminate\Support\Facades\DB;

/**
 * Drift: today's state of an environment against the file last applied to it, through every reconciler's current()
 * and diff() (pure: nothing is written but the drift row). Edits the dashboard refuses can't drift, but the API, agents,
 * side effects of other actions and other modules' paths can. A drift raises `config.drift` once per signature.
 */
final class DriftChecker
{
    public function __construct(
        private readonly Planner $planner,
        private readonly ScopeFactory $scopes,
    ) {}

    public function check(EnvironmentData $environment): ConfigDrift
    {
        $drift = ConfigDrift::query()->firstOrNew(['environment_id' => $environment->id], [
            'organization_id' => $environment->organizationId, 'project_id' => $environment->projectId, 'status' => ConfigDrift::UNKNOWN,
        ]);
        $source = ConfigSource::enabledFor($environment->projectId);
        $busy = ConfigRun::query()->where('environment_id', $environment->id)
            ->whereIn('status', [RunStatus::Planning, RunStatus::Applying])->exists();
        // [decision] The baseline is the resolved file last committed by a phase A (a run whose deployment then failed
        // included): what the environment is configured with, not the last fully applied run.
        $baseline = ConfigRun::query()->where('environment_id', $environment->id)->whereNotNull('phase_a_at')->whereNotNull('resolved')
            ->latest('phase_a_at')->orderByDesc('id')->first();

        if ($source === null || $baseline === null || $busy) {
            // Nothing applied yet, or a run is on its way: no verdict (a run in flight isn't drift).
            $drift->forceFill(['status' => ConfigDrift::UNKNOWN, 'baseline_run_id' => $baseline?->id, 'checked_at' => now()])->save();

            return $drift;
        }

        $scope = $this->scopes->make($environment, $source, $baseline->resolved, $baseline->commit, drift: true);
        $changes = $this->planner->plan($scope, (array) $baseline->resolved)->changes->actionable();
        $masked = EnvMask::changes(array_map(fn (Change $c) => $c->toArray(), $changes));
        usort($masked, fn (array $a, array $b) => strcmp((string) $a['path'], (string) $b['path']));
        $signature = $masked === [] ? null : hash('sha256', (string) json_encode(array_map(fn (array $c) => [$c['op'], $c['path'], $c['before'], $c['after']], $masked)));

        DB::transaction(function () use ($drift, $masked, $signature, $baseline, $environment) {
            $was = $drift->status;
            $drift->forceFill([
                'status' => $signature === null ? ConfigDrift::IN_SYNC : ConfigDrift::DRIFTED,
                'baseline_run_id' => $baseline->id,
                'signature' => $signature,
                'changes' => $masked,
                'since' => $signature === null ? null : ($was === ConfigDrift::DRIFTED && $drift->since !== null ? $drift->since : now()),
                'checked_at' => now(),
                // Back in sync: a later drift alerts again, even with the same signature.
                'alerted_signature' => $signature === null ? null : $drift->alerted_signature,
            ])->save();

            if ($signature !== null && $drift->alerted_signature !== $signature) {
                $drift->forceFill(['alerted_signature' => $signature])->save();
                $paths = array_values(array_unique(array_map(fn (array $c) => (string) $c['path'], $masked)));
                DB::afterCommit(fn () => ConfigDriftDetected::dispatch($environment->organizationId, $environment->projectId, $environment->id, $environment->slug,
                    $signature, $paths, url('/projects/'.$environment->projectId.'/settings').'#config'));
            }
        });

        return $drift;
    }

    /**
     * The API's drift shape.
     *
     * @return array<string, mixed>
     */
    public static function present(ConfigDrift $drift, EnvironmentData $environment): array
    {
        $baseline = $drift->baseline_run_id !== null ? ConfigRun::query()->find($drift->baseline_run_id) : null;

        return [
            'environment' => $environment->slug,
            'status' => $drift->status,
            'checked_at' => $drift->checked_at?->toIso8601String(),
            'since' => $drift->since?->toIso8601String(),
            'baseline_run_id' => $drift->baseline_run_id,
            'baseline_commit' => $baseline?->commit,
            'signature' => $drift->signature,
            'changes' => $drift->status === ConfigDrift::DRIFTED ? array_values((array) $drift->changes) : [],
        ];
    }
}
