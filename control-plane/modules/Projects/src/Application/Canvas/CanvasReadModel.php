<?php

namespace Falak\Projects\Application\Canvas;

use DateTimeInterface;
use Falak\Databases\Contracts\Data\DatabaseConsumer;
use Falak\Databases\Contracts\Data\DatabaseData;
use Falak\Databases\Contracts\DatabaseConnections;
use Falak\Databases\Contracts\DatabaseDirectory;
use Falak\Deployments\Contracts\Data\DeploymentSummary;
use Falak\Deployments\Contracts\DeploymentBadges;
use Falak\Deployments\Contracts\DeploymentDirectory;
use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Limits\Contracts\ServiceHealth;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Projects\Contracts\VariableReferences;
use Falak\Projects\Domain\Models\Environment;
use Falak\Projects\Domain\Models\Group;
use Falak\Projects\Domain\Models\Service;
use Falak\Servers\Contracts\Data\ServerData;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Contracts\ComposeInspector;
use Falak\Sites\Contracts\ComposeSites;
use Falak\Sites\Contracts\Data\ComposeServiceState;
use Falak\Sites\Contracts\Data\ComposeSummary;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\Framework;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\SiteDomains;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Sites\Contracts\TargetStatus;
use Falak\Volumes\Contracts\AttachableType;
use Falak\Volumes\Contracts\Data\VolumeData;
use Falak\Volumes\Contracts\ServiceVolumes;
use Falak\Volumes\Contracts\VolumeKind;
use Illuminate\Support\Carbon;

/**
 * Everything the canvas renders for one environment, in one request (UI_DESIGN §9 `Canvas`):
 * services with live status (deployments, targets, servers), and edges derived from variable references.
 *
 * Groups (§4.3): user groups frame services (`group_id`, positions relative to the group anchor); compose sites carry
 * `compose` — their compose services, drawn as a group of cards with `depends_on` edges. `volumes` are the disks a card
 * shows (Volumes): the volumes attached to a service with used / limit and a link to their page, a compose service's
 * named volumes (by name until the stack's first deploy), a database engine's data directory.
 *
 * @phpstan-import-type ComposeChild from ComposeGroup
 *
 * @phpstan-type CanvasService array{id: string, kind: string, ref_id: string, name: string, icon: string, custom_icon: ?string, position: array{x: int, y: int}, group_id: ?string, status: string, status_label: string, url: ?string, subtitle: ?string, servers: list<array{id: string, name: string, leader: bool, online: bool}>, badges: list<string>, volumes: list<CanvasVolume>, compose: ?array{template: ?string, collapsed: bool, services: list<ComposeChild>}, last_deployment: ?array{id: string, status: string, commit: ?string, message: ?string, finished_at: ?string}}
 * @phpstan-type CanvasVolume array{id: ?string, name: string, detail: ?string, used_bytes: ?int, limit_bytes: ?int, url: ?string}
 * @phpstan-type CanvasEdge array{from: string, to: string, kind: string, problem?: string}
 * @phpstan-type CanvasGroup array{id: string, name: string, icon: ?string, position: array{x: int, y: int}, collapsed: bool}
 */
final class CanvasReadModel
{
    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly SiteDomains $domains,
        private readonly DatabaseDirectory $databases,
        private readonly DeploymentDirectory $deployments,
        private readonly ServerDirectory $servers,
        private readonly AgentDirectory $agents,
        private readonly VariableReferences $references,
        private readonly ComposeSites $compose,
        private readonly ComposeInspector $inspector,
        private readonly DatabaseConnections $connections,
        private readonly ServiceVolumes $volumes,
        private readonly ServiceHealth $health,
        private readonly DeploymentBadges $deploymentBadges,
    ) {}

    /** @var array<string, list<string>> OOM / restart-loop badges by site id, then by database instance id */
    private array $siteBadges = [];

    /** @var array<string, list<string>> "Watching" / "Rolled back" (a live release's watch) by site id */
    private array $releaseBadges = [];

    /** @var array<string, list<string>> */
    private array $instanceBadges = [];

    /**
     * @return array{services: list<CanvasService>, edges: list<CanvasEdge>, groups: list<CanvasGroup>}
     */
    public function for(Environment $environment): array
    {
        /** @var list<Service> $services */
        $services = $environment->services()->get()->all();

        $siteIds = array_values(array_map(fn (Service $s) => $s->ref_id, array_filter($services, fn (Service $s) => $s->kind === ServiceKind::Site)));
        $databaseIds = array_values(array_map(fn (Service $s) => $s->ref_id, array_filter($services, fn (Service $s) => $s->kind === ServiceKind::Database)));

        /** @var array<string, SiteData> $sites */
        $sites = [];

        foreach ($siteIds as $siteId) {
            if ($site = $this->sites->find($siteId)) {
                $sites[$siteId] = $site;
            }
        }

        $databases = $this->databases->findMany($databaseIds);
        $this->siteBadges = $this->health->badgesForSites(array_keys($sites));
        $this->releaseBadges = $this->deploymentBadges->forSites(array_keys($sites));
        $this->instanceBadges = $this->health->badgesForInstances(array_values(array_filter(array_map(fn (DatabaseData $database) => $database->instanceId, array_values($databases)))));
        $deployments = $this->deployments->currentForSites(array_keys($sites));
        $volumes = $this->volumes->forSites(array_keys($sites));
        $domains = $this->domains->primaryDomains(array_keys($sites));

        $serverIds = array_values(array_unique([
            ...array_merge([], ...array_map(fn (SiteData $site) => $site->serverIds(), array_values($sites))),
            ...array_map(fn (DatabaseData $database) => $database->serverId, array_values($databases)),
        ]));
        $servers = $this->servers($serverIds);
        $agents = $this->agents->forServers($serverIds);

        $cards = [];
        $composeEdges = [];

        foreach ($services as $service) {
            $card = match ($service->kind) {
                ServiceKind::Site => isset($sites[$service->ref_id])
                    ? $this->site($service, $sites[$service->ref_id], $deployments[$service->ref_id] ?? null, $domains[$service->ref_id] ?? null, $servers, $agents, $volumes[$service->ref_id] ?? [])
                    : null,
                ServiceKind::Database => isset($databases[$service->ref_id])
                    ? $this->database($service, $databases[$service->ref_id], $servers, $agents)
                    : null,
            };

            if ($card !== null) {
                $composeEdges = [...$composeEdges, ...($card['compose_edges'] ?? [])];
                unset($card['compose_edges']);
                $cards[] = $card;
            }
        }

        $edges = [
            ...array_map(fn (array $edge) => [...$edge, 'kind' => 'reference'], $this->edges($services, $sites)),
            ...array_map(fn (array $edge) => [...$edge, 'kind' => 'depends_on'], $composeEdges),
        ];

        return [
            'services' => $cards,
            'edges' => $edges,
            'groups' => $environment->groups()->get()->map(fn (Group $group) => $group->toCanvas())->values()->all(),
        ];
    }

    /**
     * @param  array<string, ServerData>  $servers
     * @param  array<string, mixed>  $agents
     * @param  list<VolumeData>  $volumes  attached to the site or its compose services
     * @return CanvasService
     */
    private function site(Service $service, SiteData $site, ?DeploymentSummary $deployment, ?string $domain, array $servers, array $agents, array $volumes = []): array
    {
        [$status, $label] = $this->siteStatus($site, $deployment);
        $host = $domain ?? $site->testDomain;
        // "Laravel · PHP 8.4 · 512 MB · 1 CPU" (the limits it runs with).
        $subtitle = implode(' · ', array_filter([
            $site->framework->label(),
            $site->runtime->isPhp() && $site->phpVersion ? "PHP {$site->phpVersion}" : $site->runtime->label(),
            $site->limits->summary(),
        ]));

        $compose = null;

        if ($site->compose !== null) {
            [$states, $summary] = $this->composeState($site);
            $compose = ComposeGroup::for($service, $site, $summary, $states, [$status, $label], self::composeVolumes($site->id, $volumes));
            [$subtitle, $status, $label] = $this->composeCard($summary, $states, $status, $label);
        }

        return [
            ...$this->base($service),
            'icon' => $service->icon ?? self::siteIcon($site),
            'status' => $status,
            'status_label' => $label,
            'url' => $host !== null ? "https://{$host}" : null,
            'subtitle' => $subtitle,
            'servers' => array_map(fn ($target) => $this->server($target->serverId, $target->isLeader(), $servers, $agents), $site->targets),
            // Runtime traits worth seeing on the card (Laravel Octane serves the app behind the edge).
            // OOM kills and restart loops (Limits) of the site, its compose services, workers and daemons; a release
            // being watched after going live, or rolled back by its watch.
            'badges' => [...($site->framework->isLaravel() && $site->runtime->isPhp() && $site->laravel->octane ? ['Octane'] : []), ...($this->siteBadges[$site->id] ?? []), ...($this->releaseBadges[$site->id] ?? [])],
            // Its volumes (a classic site's shared directories, a container's mounts); compose services carry their own.
            'volumes' => $compose === null && $site->runtime !== SiteRuntime::Static ? self::siteVolumes($site->id, $volumes) : [],
            'compose' => $compose !== null ? ['template' => $compose['template'], 'collapsed' => $compose['collapsed'], 'services' => $compose['services']] : null,
            'compose_edges' => $compose['edges'] ?? [],
            'last_deployment' => $deployment !== null ? [
                'id' => $deployment->id,
                'status' => $deployment->status,
                'commit' => $deployment->commit !== null ? substr($deployment->commit, 0, 7) : null,
                'message' => $deployment->message,
                'finished_at' => $deployment->finishedAt?->format(DateTimeInterface::ATOM),
            ] : null,
        ];
    }

    /**
     * @param  array<string, ServerData>  $servers
     * @param  array<string, mixed>  $agents
     * @return CanvasService
     */
    private function database(Service $service, DatabaseData $database, array $servers, array $agents): array
    {
        [$status, $label] = match ($database->status) {
            'active' => ['active', 'Active'],
            'pending' => ['provisioning', 'Creating'],
            'failed' => ['failed', 'Failed'],
            default => ['inactive', 'Deleting'],
        };

        return [
            ...$this->base($service),
            'icon' => $service->icon ?? $database->engine,
            'status' => $status,
            'status_label' => $label,
            'url' => null,
            // "PostgreSQL 17 · 512 MB · app-1".
            'subtitle' => implode(' · ', array_filter([
                trim(self::engineLabel($database->engine).' '.($database->engineVersion ?? '')),
                $database->memoryMb !== null ? "{$database->memoryMb} MB" : null,
                $servers[$database->serverId]->name ?? null,
            ])),
            'servers' => [$this->server($database->serverId, false, $servers, $agents)],
            'badges' => [
                ...(in_array($database->health, ['unhealthy', 'stopped', 'missing'], true) ? [ucfirst((string) $database->health)] : []),
                ...($database->instanceId !== null ? $this->instanceBadges[$database->instanceId] ?? [] : []),
            ],
            // The container's data volume.
            'volumes' => ($volume = $database->volumeId !== null ? $this->volumes->find($database->volumeId) : null) !== null
                ? [self::chip($volume, $volume->name, 'data')]
                : [],
            'compose' => null,
            'last_deployment' => null,
        ];
    }

    /**
     * @param  list<VolumeData>  $volumes
     * @return list<CanvasVolume>
     */
    private static function siteVolumes(string $siteId, array $volumes): array
    {
        $chips = [];

        foreach ($volumes as $volume) {
            foreach ($volume->attachments as $attachment) {
                // A shared .env is one file: no disk on the card.
                if ($attachment->type === AttachableType::Site && $attachment->attachableId === $siteId && ! $volume->sharedFile) {
                    $chips[] = self::chip($volume, $volume->kind === VolumeKind::SharedPath ? $attachment->mountPath : $volume->name, $volume->kind === VolumeKind::SharedPath ? 'shared' : $attachment->mountPath);
                }
            }
        }

        return $chips;
    }

    /**
     * Compose services' volumes, by service.
     *
     * @param  list<VolumeData>  $volumes
     * @return array<string, list<CanvasVolume>>
     */
    private static function composeVolumes(string $siteId, array $volumes): array
    {
        $chips = [];

        foreach ($volumes as $volume) {
            foreach ($volume->attachments as $attachment) {
                if ($attachment->type === AttachableType::ComposeService && $attachment->attachableId === $siteId && $attachment->service !== null) {
                    // A stack on several servers has a volume per server: one chip per name.
                    $chips[$attachment->service][$volume->name] ??= self::chip($volume, $volume->name, $attachment->mountPath);
                }
            }
        }

        return array_map('array_values', $chips);
    }

    /**
     * @return CanvasVolume
     */
    private static function chip(VolumeData $volume, string $name, ?string $detail): array
    {
        return [
            'id' => $volume->id,
            'name' => $name,
            'detail' => $detail,
            'used_bytes' => $volume->usedBytes,
            'limit_bytes' => $volume->sizeLimitBytes,
            'url' => "/volumes/{$volume->id}",
        ];
    }

    /**
     * @return array{id: string, kind: string, ref_id: string, name: string, position: array{x: int, y: int}, group_id: ?string}
     */
    private function base(Service $service): array
    {
        return [
            'id' => $service->id,
            'kind' => $service->kind->value,
            'ref_id' => $service->ref_id,
            'name' => $service->name,
            // Picked on the canvas; `icon` is this, else the one derived from the site / engine.
            'custom_icon' => $service->icon,
            'position' => ['x' => $service->x, 'y' => $service->y],
            'group_id' => $service->group_id,
        ];
    }

    /**
     * Reported container states and the parsed compose file of a compose site.
     *
     * @return array{0: list<ComposeServiceState>, 1: ComposeSummary}
     */
    private function composeState(SiteData $site): array
    {
        return [$this->compose->status($site->id), $this->inspector->parse($this->compose->project($site->id) ?? '')];
    }

    /**
     * Compose sites: "Compose · 3 services"; an active site whose services report unhealthy / exited
     * containers is shown as crashed.
     *
     * @param  list<ComposeServiceState>  $states
     * @return array{0: string, 1: string, 2: string}
     */
    private function composeCard(ComposeSummary $summary, array $states, string $status, string $label): array
    {
        $names = array_values(array_unique([...$summary->serviceNames(), ...array_map(fn ($state) => $state->service, $states)]));

        $count = count($names);
        $subtitle = 'Compose'.($count > 0 ? ' · '.$count.' '.($count === 1 ? 'service' : 'services') : '');

        if ($status === 'active' && $states !== []) {
            $failing = array_values(array_unique(array_map(fn ($state) => $state->service, array_filter($states, fn ($state) => $state->failing()))));

            if ($failing !== []) {
                $healthy = $count - count($failing);

                return [$subtitle, 'crashed', "{$healthy}/{$count} services healthy · ".implode(', ', array_slice($failing, 0, 2)).' down'];
            }
        }

        return [$subtitle, $status, $label];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function siteStatus(SiteData $site, ?DeploymentSummary $deployment): array
    {
        $targets = array_map(fn ($target) => $target->status->value, $site->targets);

        if ($deployment !== null && $deployment->isActive()) {
            return $deployment->status === 'building'
                ? ['building', 'Building']
                : ['deploying', 'Deploying '.($deployment->progress ?? 0).'%'];
        }

        if ($targets === []) {
            return ['inactive', 'No servers'];
        }

        if (in_array(TargetStatus::Failed->value, $targets, true)) {
            return ['failed', 'Server setup failed'];
        }

        if (array_intersect([TargetStatus::Pending->value, TargetStatus::Provisioning->value], $targets) !== []) {
            return ['provisioning', 'Provisioning'];
        }

        return match ($deployment?->status) {
            null => ['inactive', 'Not deployed'],
            'queued' => ['queued', 'Queued'],
            'waiting' => ['queued', 'Waiting for servers'],
            'succeeded' => ['active', 'Active · '.self::ago($deployment->finishedAt ?? $deployment->createdAt)],
            'failed' => ['failed', 'Failed · '.self::ago($deployment->finishedAt ?? $deployment->createdAt)],
            default => ['inactive', 'Cancelled · '.self::ago($deployment->finishedAt ?? $deployment->createdAt)],
        };
    }

    /**
     * @param  array<string, ServerData>  $servers
     * @param  array<string, mixed>  $agents
     * @return array{id: string, name: string, leader: bool, online: bool}
     */
    private function server(string $serverId, bool $leader, array $servers, array $agents): array
    {
        $agent = $agents[$serverId] ?? null;

        return [
            'id' => $serverId,
            'name' => $servers[$serverId]->name ?? 'deleted server',
            'leader' => $leader,
            'online' => $agent !== null && $agent->isOnline(),
        ];
    }

    /**
     * A site whose variables reference another service of the environment points at it. An edge to a database whose
     * host (DB_HOST, DATABASE_URL, REDIS_HOST, REDIS_URL) can't be resolved for the site carries the reason as
     * `problem` (the deploy would fail with it): e.g. a dedicated database server the site shares no private network
     * with, since references never point at a public address.
     *
     * @param  list<Service>  $services
     * @param  array<string, SiteData>  $sites
     * @return list<array{from: string, to: string, problem?: string}>
     */
    private function edges(array $services, array $sites): array
    {
        $byHandle = [];

        foreach ($services as $service) {
            $byHandle[Service::handle($service->name)] = $service;
        }

        $edges = [];

        foreach ($services as $service) {
            if ($service->kind !== ServiceKind::Site || ! isset($sites[$service->ref_id])) {
                continue;
            }

            $variables = $this->sites->environment($service->ref_id)?->variables ?? [];

            $site = $sites[$service->ref_id];
            $consumer = null;

            foreach ($this->references->referencesIn($variables) as $reference) {
                // ${{ secrets.NAME }} is the secret store, not a service.
                if (strtolower(trim($reference['service'])) === VariableReferences::SECRETS) {
                    continue;
                }

                $target = $byHandle[Service::handle($reference['service'])] ?? null;

                if ($target === null || $target->id === $service->id) {
                    continue;
                }

                $key = "{$service->id}>{$target->id}";
                $edges[$key] ??= ['from' => $service->id, 'to' => $target->id];

                if ($target->kind === ServiceKind::Database && ! isset($edges[$key]['problem'])
                    && in_array($reference['key'], [...DatabaseConnections::HOST_KEYS, ...DatabaseConnections::REDIS_HOST_KEYS], true)) {
                    $consumer ??= new DatabaseConsumer($site->name, $site->serverIds(), $site->runtime->usesDocker());

                    if (($reason = $this->connections->unreachable($target->ref_id, $consumer)) !== null) {
                        $edges[$key]['problem'] = "{$reference['variable']}: {$target->name}.{$reference['key']} cannot be used here: {$reason}";
                    }
                }
            }
        }

        return array_values($edges);
    }

    /**
     * @param  list<string>  $serverIds
     * @return array<string, ServerData>
     */
    private function servers(array $serverIds): array
    {
        $servers = [];

        foreach ($serverIds as $serverId) {
            if ($server = $this->servers->find($serverId)) {
                $servers[$serverId] = $server;
            }
        }

        return $servers;
    }

    /** ServiceIcon key of a site: framework logo, runtime for generic Node sites. */
    public static function siteIcon(SiteData $site): string
    {
        if ($site->runtime === SiteRuntime::Compose) {
            return 'compose';
        }

        if ($site->runtime === SiteRuntime::Function) {
            return 'function';
        }

        return match ($site->framework) {
            Framework::Node => match ($site->runtime) {
                SiteRuntime::Bun => 'bun',
                SiteRuntime::Deno => 'deno',
                SiteRuntime::Docker, SiteRuntime::Compose => 'docker',
                default => 'node',
            },
            default => $site->framework->value,
        };
    }

    public static function engineLabel(string $engine): string
    {
        return match ($engine) {
            'postgresql' => 'PostgreSQL',
            'mysql' => 'MySQL',
            'mariadb' => 'MariaDB',
            'redis' => 'Redis',
            'valkey' => 'Valkey',
            default => ucfirst($engine),
        };
    }

    private static function ago(DateTimeInterface $at): string
    {
        return Carbon::instance($at)->diffForHumans(null, true, true).' ago';
    }
}
