<?php

namespace Falak\Config\Application\Previews;

use Falak\Config\Application\EnvMask;
use Falak\Config\Application\Planning\Planner;
use Falak\Config\Application\Runs\Applier;
use Falak\Config\Application\Runs\FileFetcher;
use Falak\Config\Application\Runs\ImplicitEnvironment;
use Falak\Config\Application\ScopeFactory;
use Falak\Config\Application\Validation\FileCheck;
use Falak\Config\Contracts\ApplyContext;
use Falak\Config\Contracts\ApplyPhase;
use Falak\Config\Contracts\ConfigScope;
use Falak\Config\Contracts\PreviewConfig;
use Falak\Config\Contracts\PreviewFile;
use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Enums\RunTrigger;
use Falak\Config\Domain\Models\ConfigOwnership;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Config\Domain\Models\ConfigSource;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\VariableReferences;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class FilePreviews implements PreviewConfig
{
    /** Unit types a preview never takes from the file: Previews creates its databases per data strategy. */
    private const DATA_TYPES = ['database', 'redis'];

    /** Unit fields a preview never takes: its hosts, its server, data volumes. */
    private const LEFT_OUT = ['domains', 'servers', 'volumes'];

    public function __construct(
        private readonly FileFetcher $files,
        private readonly FileCheck $check,
        private readonly ScopeFactory $scopes,
        private readonly ImplicitEnvironment $implicit,
        private readonly ProjectDirectory $projects,
        private readonly Planner $planner,
        private readonly Applier $applier,
    ) {}

    public function prepare(string $projectId, string $baseEnvironmentId, string $ref): ?PreviewFile
    {
        $source = ConfigSource::enabledFor($projectId);
        $base = $this->projects->environment($baseEnvironmentId);

        if ($source === null || $base === null) {
            return null;
        }

        [$raw, $problem] = $this->files->fetch($source, $ref);

        if ($raw === null) {
            throw ValidationException::withMessages(['config' => (string) $problem]);
        }

        $format = $source->format();
        $validation = $this->check->check($raw, $format, $base->slug,
            fn (array $resolved) => $this->scopes->make($base, $source, $resolved, $ref), $this->implicit->forCheck($source, $base));

        if (! $validation->valid() || $validation->resolved === null) {
            $first = $validation->errors[0] ?? null;

            throw ValidationException::withMessages(['config' => $validation->summary($source->path).($first !== null ? " {$first->pointer}: {$first->message}" : '')]);
        }

        $resolved = $validation->resolved;
        $omitted = [];

        foreach ((array) ($resolved['previews']['units'] ?? []) as $unit => $mode) {
            if ($mode === 'omit') {
                $omitted[] = (string) $unit;
            }
        }

        foreach ((array) ($resolved['units'] ?? []) as $unit => $body) {
            if (in_array($body['type'] ?? null, self::DATA_TYPES, true) || in_array((string) $unit, $omitted, true)) {
                unset($resolved['units'][$unit]);

                continue;
            }

            $resolved['units'][$unit] = array_diff_key($body, array_flip(self::LEFT_OUT));

            foreach ((array) ($body['services'] ?? []) as $service => $settings) {
                // A compose service's hosts are the preview's; its database is the preview's copy.
                $resolved['units'][$unit]['services'][$service] = array_diff_key((array) $settings, ['domains' => true, 'replace_with' => true]);
            }
        }

        unset($resolved['previews']);
        $resolved['environments'] = [];

        return new PreviewFile($resolved, $omitted, $ref, $raw, $format);
    }

    public function apply(PreviewFile $file, string $previewEnvironmentId, string $serverId, ?string $branch, string $commit, bool $fork, array $without = [], ?string $actorId = null, array $shared = [], array $variables = []): array
    {
        $environment = $this->projects->environment($previewEnvironmentId);
        $source = $environment !== null ? ConfigSource::enabledFor($environment->projectId) : null;

        if ($environment === null || ! $environment->isPreview || $source === null) {
            throw ValidationException::withMessages(['config' => 'The preview environment is gone.']);
        }

        $base = $this->scopes->make($environment, $source, null, $commit, $actorId);
        $resolved = $file->resolved;

        foreach ((array) ($resolved['units'] ?? []) as $unit => $body) {
            // A fork's preview runs only copies of the base environment's services: the file adds none.
            if (in_array((string) $unit, $without, true) || ($fork && ! isset($base->services[$unit]))) {
                unset($resolved['units'][$unit]);
            }
        }

        [$resolved, $warnings] = self::withoutSharedValues($resolved, $shared, $variables);
        $resolved['environments'] = [$environment->slug => []];
        $scope = new ConfigScope($base->organizationId, $base->projectId, $base->environmentId, $base->environment, false, $commit, $base->services,
            $base->servers, [$serverId], $source->source_connection_id, $source->repository, $branch, $actorId, false, $base->credentials, preview: true);

        $run = ConfigRun::query()->create([
            'organization_id' => $source->organization_id,
            'project_id' => $source->project_id,
            'environment_id' => $environment->id,
            'environment' => $environment->slug,
            'commit' => preg_match('/^[0-9a-f]{7,64}$/i', $commit) === 1 ? $commit : null,
            'branch' => $branch,
            'trigger' => RunTrigger::PullRequest,
            'status' => RunStatus::Applying,
            'file' => $file->raw,
            'format' => $file->format,
            'resolved' => $resolved,
            'requested_by' => $actorId,
            'started_at' => now(),
        ]);

        try {
            $plan = $this->planner->plan($scope, $resolved);
            $run->forceFill(['plan' => EnvMask::plan([...$plan->toArray(), 'deploy' => []])])->save();

            // [decision] A preview is disposable: risky rules (production-only, data) don't apply. Phase A runs now;
            // phase B (workers, schedules, routes) when each unit's preview deployment succeeded ({@see released()}).
            $after = DB::transaction(fn () => $this->applier->phaseA($scope, $plan->changes, new ApplyContext($run->id, ApplyPhase::BeforeBuild, $commit, $actorId)));
        } catch (Throwable $e) {
            $message = $e instanceof ValidationException ? implode(' ', collect($e->errors())->flatten()->all()) : $e->getMessage();
            $run->forceFill(['status' => RunStatus::Failed, 'error' => $message, 'finished_at' => now()])->save();

            throw ValidationException::withMessages(['config' => "falak.yaml couldn't be applied to the preview: {$message}"]);
        }

        $run->forceFill(['status' => RunStatus::Applied, 'finished_at' => now()])->save();
        $created = [];

        foreach ($after->services as $unit => $service) {
            if ($service['kind'] === 'site' && ! isset($base->services[$unit])) {
                $created[(string) $unit] = $service['ref_id'];
            }
        }

        return ['sites' => $created, 'warnings' => $warnings];
    }

    public function released(string $previewEnvironmentId, string $siteId): void
    {
        $environment = $this->projects->environment($previewEnvironmentId);
        $source = $environment !== null ? ConfigSource::enabledFor($environment->projectId) : null;
        $run = $environment !== null && $environment->isPreview
            ? ConfigRun::query()->where('environment_id', $environment->id)->where('trigger', RunTrigger::PullRequest)->where('status', RunStatus::Applied)
                ->whereNotNull('resolved')->latest('finished_at')->orderByDesc('id')->first()
            : null;

        if ($environment === null || $source === null || $run === null) {
            return;
        }

        $base = $this->scopes->make($environment, $source, null, $run->commit, $run->requested_by);
        $unit = $base->unitOf(strtolower($siteId));

        if ($unit === null || ! isset($run->resolved['units'][$unit])) {
            return;
        }

        $scope = new ConfigScope($base->organizationId, $base->projectId, $base->environmentId, $base->environment, false, $run->commit, $base->services,
            $base->servers, [], $source->source_connection_id, $source->repository, $run->branch, $run->requested_by, false, $base->credentials, preview: true);

        DB::transaction(fn () => $this->applier->phaseB($scope, $run->resolved, new ApplyContext($run->id, ApplyPhase::WithRelease, $run->commit, $run->requested_by, false, $unit), $unit));
    }

    /**
     * **[decision]** A unit's env referencing a service the preview shares with its base environment (`${{ api.KEY }}`)
     * would read that service's plain values: such a reference is kept only for the names the previews copy
     * (`previews.variables`), else the variable is left out of the preview (with a warning).
     *
     * @param  array<string, mixed>  $resolved
     * @param  list<string>  $shared  services the preview shares (sites: their values are the base's)
     * @param  list<string>  $variables
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private static function withoutSharedValues(array $resolved, array $shared, array $variables): array
    {
        $warnings = [];

        if ($shared === []) {
            return [$resolved, $warnings];
        }

        foreach ((array) ($resolved['units'] ?? []) as $unit => $body) {
            foreach ((array) ($body['env'] ?? []) as $key => $value) {
                if (! is_string($value) || preg_match_all(VariableReferences::PATTERN, $value, $matches, PREG_SET_ORDER) === 0) {
                    continue;
                }

                foreach ($matches as $m) {
                    if (in_array(trim($m[1]), $shared, true) && ! in_array($m[2], $variables, true)) {
                        unset($resolved['units'][$unit]['env'][$key]);
                        $warnings[] = "{$unit}: {$key} is left out of the preview: it reads {$m[2]} of {$m[1]}, which the preview shares, and previews copy only the variables they list.";

                        break;
                    }
                }
            }
        }

        return [$resolved, $warnings];
    }

    public function forget(string $previewEnvironmentId): void
    {
        if ($this->projects->environment($previewEnvironmentId)?->isPreview ?? false) {
            ConfigOwnership::query()->where('environment_id', strtolower($previewEnvironmentId))->delete();
        }
    }
}
