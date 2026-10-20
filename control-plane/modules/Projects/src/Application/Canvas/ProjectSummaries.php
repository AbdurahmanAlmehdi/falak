<?php

namespace Falak\Projects\Application\Canvas;

use DateTimeInterface;
use Falak\Databases\Contracts\Data\DatabaseData;
use Falak\Databases\Contracts\DatabaseDirectory;
use Falak\Deployments\Contracts\Data\DeploymentSummary;
use Falak\Deployments\Contracts\DeploymentDirectory;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Projects\Domain\Models\Environment;
use Falak\Projects\Domain\Models\Favorite;
use Falak\Projects\Domain\Models\Project;
use Falak\Projects\Domain\Models\Service;
use Falak\Sites\Contracts\ComposeSites;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\TargetStatus;

/**
 * Cards of the Projects dashboard (UI_DESIGN §3.1): what's inside each project (service icons of its production
 * environment), how healthy it is ("4/4 services online"), the latest deployment, whether the user starred it, and when
 * anything last happened (sort by recent activity).
 */
final class ProjectSummaries
{
    /** Service icons shown per card. */
    public const ICONS = 8;

    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly DatabaseDirectory $databases,
        private readonly DeploymentDirectory $deployments,
        private readonly ComposeSites $compose,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function forOrganization(string $organizationId, ?string $userId = null): array
    {
        $projects = Project::query()->where('organization_id', $organizationId)->with('environments')->orderByDesc('is_default')->orderBy('name')->get();
        $services = Service::query()->where('organization_id', $organizationId)->orderBy('created_at')->orderBy('id')->get()->groupBy('project_id');
        $favorites = $userId !== null
            ? Favorite::query()->where('user_id', $userId)->where('organization_id', $organizationId)->pluck('project_id')->all()
            : [];

        $sites = [];

        foreach ($this->sites->forOrganization($organizationId) as $site) {
            $sites[$site->id] = $site;
        }

        $databases = [];

        foreach ($this->databases->forOrganization($organizationId) as $database) {
            $databases[$database->id] = $database;
        }

        $deployments = $this->deployments->currentForSites(array_keys($sites));

        return $projects->map(function (Project $project) use ($services, $sites, $databases, $deployments, $favorites) {
            /** @var list<Service> $projectServices */
            $projectServices = ($services[$project->id] ?? collect())->all();
            $projectDeployments = array_values(array_filter(array_map(
                fn (Service $s) => $s->kind === ServiceKind::Site ? ($deployments[$s->ref_id] ?? null) : null,
                $projectServices,
            )));
            $latest = collect($projectDeployments)->sortByDesc(fn (DeploymentSummary $d) => $d->createdAt->getTimestamp())->first();
            $production = $project->production();
            $productionServices = array_values(array_filter($projectServices, fn (Service $s) => $s->environment_id === $production?->id));
            $cards = array_values(array_filter(array_map(fn (Service $s) => $this->service($s, $sites, $databases, $deployments), $productionServices)));

            $activity = max(array_filter([
                $project->updated_at->getTimestamp(),
                $latest?->createdAt->getTimestamp(),
                ...array_map(fn (Service $s) => $s->created_at->getTimestamp(), $projectServices),
            ]));

            return [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'icon' => $project->icon,
                'is_default' => $project->is_default,
                'favorite' => in_array($project->id, $favorites, true),
                'environments' => $project->environments->map(fn (Environment $e) => [
                    'id' => $e->id,
                    'name' => $e->name,
                    'slug' => $e->slug,
                    'is_production' => $e->is_production,
                ])->values()->all(),
                'services_count' => count($projectServices),
                'services' => array_slice(array_map(fn (array $card) => ['kind' => $card['kind'], 'name' => $card['name'], 'icon' => $card['icon']], $cards), 0, self::ICONS),
                'production' => $production !== null ? [
                    'name' => $production->name,
                    'slug' => $production->slug,
                    'services' => count($cards),
                    'online' => count(array_filter($cards, fn (array $card) => $card['health'] === 'online')),
                    'health' => $this->health($cards),
                ] : null,
                'last_deployment' => $latest !== null ? [
                    'id' => $latest->id,
                    'site_id' => $latest->siteId,
                    'status' => $latest->status,
                    'finished_at' => $latest->finishedAt?->format(DateTimeInterface::ATOM),
                    'created_at' => $latest->createdAt->format(DateTimeInterface::ATOM),
                ] : null,
                'status' => $this->status($projectDeployments),
                'last_activity_at' => date(DateTimeInterface::ATOM, (int) $activity),
                'created_at' => $project->created_at->toIso8601String(),
            ];
        })->values()->all();
    }

    /**
     * @param  array<string, SiteData>  $sites
     * @param  array<string, DatabaseData>  $databases
     * @param  array<string, DeploymentSummary>  $deployments
     * @return ?array{kind: string, name: string, icon: string, health: string}
     */
    private function service(Service $service, array $sites, array $databases, array $deployments): ?array
    {
        if ($service->kind === ServiceKind::Database) {
            $database = $databases[$service->ref_id] ?? null;

            return $database === null ? null : [
                'kind' => 'database',
                'name' => $service->name,
                'icon' => $service->icon ?? $database->engine,
                'health' => match ($database->status) {
                    'active' => 'online',
                    'failed' => 'failing',
                    default => 'pending',
                },
            ];
        }

        $site = $sites[$service->ref_id] ?? null;

        if ($site === null) {
            return null;
        }

        $deployment = $deployments[$site->id] ?? null;
        $targets = array_map(fn ($target) => $target->status, $site->targets);
        $health = match (true) {
            $deployment !== null && ($deployment->isActive() || in_array($deployment->status, ['queued', 'waiting'], true)) => 'pending',
            in_array(TargetStatus::Failed, $targets, true), $deployment?->status === 'failed' => 'failing',
            $deployment?->status === 'succeeded' && $targets !== [] => 'online',
            default => 'offline',
        };

        // Compose sites: a crashed container makes the whole site "failing".
        if ($health === 'online' && $site->compose !== null) {
            foreach ($this->compose->status($site->id) as $state) {
                if ($state->failing()) {
                    $health = 'failing';
                    break;
                }
            }
        }

        // Compose sites made from a template show the template's logo (the dashboard falls back to Docker).
        return ['kind' => 'site', 'name' => $service->name, 'icon' => $service->icon ?? $site->compose?->template['slug'] ?? CanvasReadModel::siteIcon($site), 'health' => $health];
    }

    /**
     * Status dot of the card footer: every service online → online; any failing → failing; deploying → pending.
     *
     * @param  list<array{health: string}>  $cards
     */
    private function health(array $cards): string
    {
        $health = array_map(fn (array $card) => $card['health'], $cards);

        return match (true) {
            $health === [] => 'empty',
            in_array('failing', $health, true) => 'failing',
            in_array('pending', $health, true) => 'pending',
            ! in_array('offline', $health, true) => 'online',
            default => 'partial',
        };
    }

    /**
     * @param  list<DeploymentSummary>  $deployments
     */
    private function status(array $deployments): string
    {
        $statuses = array_map(fn (DeploymentSummary $d) => $d->status, $deployments);

        return match (true) {
            array_intersect(['building', 'deploying'], $statuses) !== [] => 'deploying',
            in_array('failed', $statuses, true) => 'failed',
            in_array('succeeded', $statuses, true) => 'active',
            default => 'inactive',
        };
    }
}
