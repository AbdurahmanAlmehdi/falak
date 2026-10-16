<?php

namespace Falak\Terminal\Application;

use Falak\Databases\Contracts\Data\DatabaseInstanceData;
use Falak\Databases\Contracts\DatabaseDirectory;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Sandboxes\Contracts\Data\SandboxData;
use Falak\Sandboxes\Contracts\SandboxDirectory;
use Falak\Servers\Contracts\Data\ServerData;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Contracts\ComposeInspector;
use Falak\Sites\Contracts\ComposeSites;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Terminal\Domain\Data\ConsoleTarget;
use Falak\Terminal\Domain\Models\TerminalSession;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

/**
 * Resolves what a console opens into (docs/plans/CONSOLE.md "Opening a console"): sites (native: the site user in its
 * current release with its variables; docker / compose: a container by labels), database containers and servers.
 * Lookups accept ids or names (slugs for sites), always inside the organization; others' resources are 404.
 */
final class ConsoleTargets
{
    public const FUNCTION_REFUSED = 'Functions run on demand; they have no console.';

    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly ComposeSites $compose,
        private readonly ComposeInspector $inspector,
        private readonly DatabaseDirectory $databases,
        private readonly ServerDirectory $servers,
        private readonly OrganizationAccess $access,
        private readonly SandboxDirectory $sandboxes,
    ) {}

    // ---- lookups --------------------------------------------------------------------------------------

    /** A site of the organization the user may see, by id or slug; 404 otherwise. */
    public function site(?Authenticatable $user, string $organizationId, string $ref): SiteData
    {
        $site = $this->sites->find(strtolower($ref));

        if ($site === null || $site->organizationId !== $organizationId) {
            $site = collect($this->sites->forOrganization($organizationId))->first(fn (SiteData $s) => $s->slug === strtolower($ref));
        }

        abort_if($site === null || ! $this->access->can($user, $organizationId, 'sites.view'), 404, 'Site not found.');

        return $site;
    }

    /** A database container of the organization the user may see, by id or name; 404 otherwise. */
    public function instance(?Authenticatable $user, string $organizationId, string $ref): DatabaseInstanceData
    {
        $instance = $this->databases->instance($ref);

        if ($instance === null || $instance->organizationId !== $organizationId) {
            $instance = collect($this->databases->instancesForOrganization($organizationId))->first(fn (DatabaseInstanceData $i) => $i->name === $ref);
        }

        abort_if($instance === null || ! $this->access->can($user, $organizationId, 'databases.view'), 404, 'Database not found.');

        return $instance;
    }

    /**
     * A sandbox of the organization the user may use (sandboxes.use), by id or name; 404 otherwise. Someone else's
     * sandbox needs sandboxes.manage (403).
     */
    public function sandbox(?Authenticatable $user, string $organizationId, string $ref): SandboxData
    {
        $sandbox = $this->sandboxes->find($organizationId, $ref);

        abort_if($sandbox === null || ! $this->access->can($user, $organizationId, SandboxDirectory::USE_PERMISSION), 404, 'Sandbox not found.');
        abort_unless(
            $sandbox->createdById === (string) $user?->getAuthIdentifier() || $this->access->can($user, $organizationId, SandboxDirectory::MANAGE_PERMISSION),
            403,
            'Only its creator (or someone with sandboxes.manage) can open a console in this sandbox.',
        );

        return $sandbox;
    }

    /** A server of the organization, by id or name; 404 otherwise. */
    public function server(string $organizationId, string $ref): ServerData
    {
        $server = $this->servers->find(strtolower($ref));

        if ($server === null || $server->organizationId !== $organizationId) {
            $server = collect($this->servers->forOrganization($organizationId))->first(fn (ServerData $s) => $s->name === $ref);
        }

        abort_if($server === null, 404, 'Server not found.');

        return $server;
    }

    // ---- targets --------------------------------------------------------------------------------------

    public function forServer(ServerData $server, ?string $unixUser = null): ConsoleTarget
    {
        return ConsoleTarget::server($server->id, $server->name, $unixUser ?: (string) config('terminal.default_user', 'root'));
    }

    /**
     * @param  ?string  $serverRef  one of the site's servers (id or name); default: the leader target
     * @param  ?string  $service  compose: the service (required when the stack has several)
     */
    public function forSite(SiteData $site, ?string $serverRef = null, ?string $service = null): ConsoleTarget
    {
        if ($site->runtime->isFunction()) {
            throw ValidationException::withMessages(['site' => self::FUNCTION_REFUSED]);
        }

        $server = $this->siteServer($site, $serverRef);
        $base = ['type' => TerminalSession::TARGET_SITE, 'serverId' => $server->id, 'serverName' => $server->name, 'id' => $site->id, 'name' => $site->name];

        if ($site->runtime === SiteRuntime::Docker) {
            return new ConsoleTarget(...$base, container: ['labels' => ['falak.site' => $site->slug]], containerLabel: "docker:{$site->slug}");
        }

        if ($site->runtime === SiteRuntime::Compose) {
            $service = $this->composeService($site, $service);

            return new ConsoleTarget(...$base, container: ['labels' => [
                'com.docker.compose.project' => $site->slug,
                'com.docker.compose.service' => $service,
            ]], containerLabel: "compose:{$service}");
        }

        return new ConsoleTarget(
            ...$base,
            unixUser: $site->unixUser,
            cwd: $site->currentPath(),
            env: $this->sites->variables($site->id, $server->id),
        );
    }

    public function forDatabase(DatabaseInstanceData $instance): ConsoleTarget
    {
        if (! $instance->isRunning()) {
            throw ValidationException::withMessages(['instance' => 'Consoles can be opened once the database server is running.']);
        }

        return new ConsoleTarget(
            TerminalSession::TARGET_DATABASE,
            $instance->serverId,
            $instance->serverName,
            $instance->id,
            $instance->name,
            container: ['name' => $instance->container],
            containerLabel: "docker:{$instance->container}",
        );
    }

    /** A shell in a running sandbox (`runsc exec`, as root). */
    public function forSandbox(SandboxData $sandbox): ConsoleTarget
    {
        abort_unless($sandbox->isRunning(), 409, 'Consoles can be opened once the sandbox is running.');
        $server = $this->servers->find($sandbox->serverId);

        abort_if($server === null, 404, 'Sandbox not found.');

        return new ConsoleTarget(
            TerminalSession::TARGET_SANDBOX,
            $server->id,
            $server->name,
            $sandbox->id,
            $sandbox->name,
            containerLabel: "sandbox:{$sandbox->name}",
            sandbox: ['id' => $sandbox->id],
        );
    }

    /**
     * What a site console can be opened on (the CLI's and the UI's choices).
     *
     * @return array{servers: list<array{id: string, name: string, leader: bool}>, services: ?list<string>, runtime: string}
     */
    public function siteOptions(SiteData $site): array
    {
        $servers = [];

        foreach ($site->targets as $target) {
            $server = $this->servers->find($target->serverId);

            if ($server !== null) {
                $servers[] = ['id' => $server->id, 'name' => $server->name, 'leader' => $target->isLeader()];
            }
        }

        return [
            'servers' => $servers,
            'services' => $site->runtime === SiteRuntime::Compose ? $this->services($site) : null,
            'runtime' => $site->runtime->value,
        ];
    }

    /**
     * A compose site's services that run in its stack: the project's services minus the ones Falak replaced (databases,
     * own sites); before any project is known, the services servers reported.
     *
     * @return list<string>
     */
    public function services(SiteData $site): array
    {
        $yaml = $this->compose->project($site->id);
        $extracted = $site->compose?->extracted() ?? [];

        if ($yaml !== null) {
            $summary = $this->inspector->parse($yaml);

            if ($summary->valid()) {
                return array_values(array_diff($summary->serviceNames(), $extracted));
            }
        }

        $reported = [];

        foreach ($this->compose->status($site->id) as $state) {
            $reported[$state->service] = true;
        }

        return array_values(array_diff(array_keys($reported), $extracted));
    }

    private function siteServer(SiteData $site, ?string $ref): ServerData
    {
        $serverId = null;

        if ($ref !== null && $ref !== '') {
            foreach ($site->targets as $target) {
                $server = $this->servers->find($target->serverId);

                if ($target->serverId === strtolower($ref) || $server?->name === $ref) {
                    $serverId = $target->serverId;
                }
            }
        } else {
            $serverId = $site->leader()?->serverId ?? ($site->targets[0] ?? null)?->serverId;
        }

        $server = $serverId !== null ? $this->servers->find($serverId) : null;

        if ($server === null) {
            throw ValidationException::withMessages(['server_id' => $site->targets === [] ? 'The site has no servers yet.' : 'Pick one of the site servers.']);
        }

        return $server;
    }

    private function composeService(SiteData $site, ?string $service): string
    {
        $services = $this->services($site);

        if ($service !== null && $service !== '') {
            if ($services !== [] && ! in_array($service, $services, true)) {
                throw ValidationException::withMessages(['service' => 'The stack has no service '.$service.'. Pick one of: '.implode(', ', $services).'.']);
            }

            if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $service) !== 1) {
                throw ValidationException::withMessages(['service' => 'Pick one of the stack\'s services.']);
            }

            return $service;
        }

        if (count($services) === 1) {
            return $services[0];
        }

        throw ValidationException::withMessages(['service' => $services === []
            ? 'Name the service to open a console in (the stack has not been deployed yet).'
            : 'Pick a service: '.implode(', ', $services).'.']);
    }
}
