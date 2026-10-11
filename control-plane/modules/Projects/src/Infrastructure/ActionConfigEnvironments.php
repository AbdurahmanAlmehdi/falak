<?php

namespace Falak\Projects\Infrastructure;

use Falak\Identity\Contracts\AuditLog;
use Falak\Projects\Application\Actions\CreateEnvironment;
use Falak\Projects\Contracts\ConfigEnvironments;
use Falak\Projects\Contracts\Data\EnvironmentData;
use Falak\Projects\Domain\Models\Environment;
use Falak\Projects\Domain\Models\Project;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ActionConfigEnvironments implements ConfigEnvironments
{
    public function __construct(private readonly AuditLog $audit) {}

    public function create(string $projectId, string $slug, ?string $userId = null): EnvironmentData
    {
        $project = Project::query()->find(strtolower($projectId)) ?? throw ValidationException::withMessages(['project' => 'Unknown project.']);

        if (in_array($slug, Environment::RESERVED_SLUGS, true) || Str::slug($slug) !== $slug) {
            throw ValidationException::withMessages(['environment' => "\"{$slug}\" can't be an environment's name (it is reserved)."]);
        }

        $taken = Environment::query()->where('project_id', $project->id)
            ->where(fn ($q) => $q->where('slug', $slug)->orWhereRaw('lower(name) = ?', [$slug]))->first();

        if ($taken !== null) {
            throw ValidationException::withMessages(['environment' => $taken->is_preview
                ? "\"{$slug}\" is the name of a pull request's preview environment: pick another name."
                : "The environment \"{$taken->name}\" already uses the name \"{$slug}\"."]);
        }

        $environment = app(CreateEnvironment::class)($project, $slug, $userId);

        if ($environment->slug !== $slug) {
            // Never a run against an environment the file doesn't name.
            throw ValidationException::withMessages(['environment' => "The environment was created as \"{$environment->slug}\", not \"{$slug}\"."]);
        }

        return $environment->toData();
    }

    public function setProduction(string $environmentId, bool $production, ?string $userId = null): EnvironmentData
    {
        $environment = Environment::query()->findOrFail(strtolower($environmentId));

        if ($environment->is_preview) {
            throw ValidationException::withMessages(['environment' => 'A preview environment is never production.']);
        }

        if ($environment->is_production !== $production) {
            $environment->forceFill(['is_production' => $production])->save();
            $this->audit->record('project.environment_production', 'project', $environment->project_id, ['environment' => $environment->slug, 'production' => $production], $environment->organization_id, $userId);
        }

        return $environment->toData();
    }
}
