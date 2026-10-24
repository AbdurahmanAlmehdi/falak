<?php

namespace Falak\Deployments\Application\Orchestration;

use Falak\Builds\Contracts\BuildService;
use Falak\Deployments\Contracts\Data\LiveRelease;
use Falak\Deployments\Contracts\FunctionSources;
use Falak\Deployments\Domain\Enums\StepKind;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\Deployments\Domain\Models\DeploymentStep;
use Falak\Deployments\Domain\Models\Release;
use Falak\Deployments\Domain\Models\SiteSettings;
use Falak\Edge\Contracts\EdgeRoutes;
use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Limits\Contracts\ResourceLimits;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Projects\Contracts\VariableReferences;
use Falak\Sites\Contracts\ComposeServiceExtraction;
use Falak\Sites\Contracts\ComposeSites;
use Falak\Sites\Contracts\Data\SharedPath;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SecretVariables;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Volumes\Contracts\Data\Mount;
use Falak\Volumes\Contracts\ServiceVolumes;
use Falak\Volumes\Contracts\VolumeMounts;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Agent command payloads for deployment steps. ULIDs are uppercased here — the agent boundary —
 * and every deploy.* command carries `context` so lifecycle logs are labelled.
 */
final class StepPayloads
{
    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly BuildService $builds,
        private readonly EdgeRoutes $edge,
        private readonly VariableReferences $references,
        private readonly ComposeSites $compose,
        private readonly FunctionSources $functions,
        private readonly AgentDirectory $agents,
        private readonly ComposeServiceExtraction $extraction,
        private readonly SecretVariables $secrets,
        private readonly VolumeMounts $mounts,
        private readonly ServiceVolumes $volumes,
        private readonly ProjectDirectory $projects,
    ) {}

    /** @var array<string, list<string>> site id => variables the last resolve filled from the secret store */
    private array $storeSecrets = [];

    // ---- Docker Compose (docs/COMPOSE_TEMPLATES.md §1.4) -------------------------------------------------------

    /**
     * @return ?array<string, mixed>
     */
    private function compose(DeploymentStep $step, Deployment $deployment, SiteData $site, string $serverId): ?array
    {
        return match ($step->kind) {
            StepKind::Fetch => $this->composeFiles($site, $this->composeRelease($deployment, $site), (string) $deployment->release_id, $serverId, $deployment),
            StepKind::Hook => $this->composeLeader($site, $this->composeRelease($deployment, $site), (string) $deployment->release_id),
            StepKind::Activate => $this->composeUp($site, $this->composeRelease($deployment, $site), (string) $deployment->release_id, $serverId, $deployment),
            StepKind::Switch => $this->composeUp($site, $this->storedRelease((string) $deployment->target_release_id), (string) $deployment->target_release_id, $serverId, $deployment),
            StepKind::Revert => $this->composeUp($site, $this->storedRelease((string) ($step->meta['release_id'] ?? '')), (string) ($step->meta['release_id'] ?? ''), $serverId, $deployment),
            default => throw new RuntimeException("{$step->kind->value} is not a compose step."),
        };
    }

    /**
     * The rendered compose release, rendered once (at the first step that needs it) and stored on the release.
     *
     * @return array{yaml: string, env: array<string, string>, leader: array<string, list<string>>, source: string, version?: ?int, registry?: bool}
     *
     * @throws RuntimeException
     */
    public function composeRelease(Deployment $deployment, SiteData $site): array
    {
        $release = Release::query()->find($deployment->release_id) ?? throw new RuntimeException('The release no longer exists.');

        if (is_array($release->compose)) {
            return $release->compose;
        }

        $bootstrap = $this->splitSiteOrder($deployment, $site);

        $images = [];
        $version = null;
        $registry = false;
        $assets = [];
        $repoFiles = null;

        if ($deployment->build_id !== null) {
            $build = $this->builds->composeFor($deployment->build_id) ?? throw new RuntimeException('The build produced no compose file.');
            [$yaml, $images, $registry, $assets, $repoFiles] = [$build->content, $build->images, $build->registryAuth !== null, $build->assets ?? [], $build->repoFiles()];
        } else {
            $inline = $this->compose->content($site->id) ?? throw new RuntimeException('The site has no compose file; add one in Settings → Compose.');
            [$yaml, $version] = [$inline->content, $inline->version];
        }

        $rendered = $this->compose->render($site->id, $yaml, $images, (string) $deployment->release_id, $repoFiles);

        $data = [
            'yaml' => $rendered->yaml,
            'env' => [...$this->composeVariables($site), ...array_filter([
                'FALAK_SITE_ID' => self::upper($site->id),
                'FALAK_DEPLOYMENT_ID' => self::upper($deployment->id),
                'FALAK_RELEASE_ID' => self::upper($deployment->release_id),
            ])],
            'leader' => $rendered->leaderCommands,
            'source' => $site->compose?->source->value ?? 'repo',
            'version' => $version,
            'registry' => $registry,
            // Repository files the project mounts (written under repo/; kept with the release for rollbacks).
            'assets' => $assets,
            // A bootstrap release starts only these services (rolling back to it starts the same ones).
            ...($bootstrap !== [] ? ['bootstrap' => $bootstrap] : []),
        ];

        $release->forceFill(['compose' => $data])->save();

        return $data;
    }

    /**
     * Services split out into their own Falak sites that aren't live yet: the stack runs without them, so the order is
     *  1. a stack that never ran starts, in a bootstrap pass, only the services those sites use (their `uses` still in
     *     the stack, plus what compose pulls in through depends_on) — the split-out sites need them to pass their own
     *     health checks — and returns them (DeploySplitSitesFirst then deploys the sites, then the full stack);
     *  2. otherwise (the stack already runs, the sites use nothing in it, or an agent can't start a subset) the
     *     deployment stops here and the sites deploy first.
     * The waiting sites are recorded on the deployment (`awaits_sites`). [] = nothing waits: a full deployment.
     *
     * @return list<string> the bootstrap services
     *
     * @throws RuntimeException
     */
    private function splitSiteOrder(Deployment $deployment, SiteData $site): array
    {
        $services = $site->compose->services ?? [];
        $waiting = [];
        $uses = [];

        foreach ($services as $service => $decision) {
            if (($decision['mode'] ?? null) === 'site' && is_string($decision['site_id'] ?? null) && Release::current($decision['site_id']) === null) {
                $waiting[(string) $service] = strtolower($decision['site_id']);
                array_push($uses, ...array_map('strval', (array) ($decision['uses'] ?? [])));
            }
        }

        if ($waiting === []) {
            return [];
        }

        $names = implode(', ', array_map(fn (string $id, string $service) => $this->sites->find($id)?->name ?? $service, $waiting, array_keys($waiting)));
        $deployment->forceFill(['settings' => [...(array) $deployment->settings, 'awaits_sites' => array_values(array_unique($waiting))]])->save();

        // Only services that still run in the stack (not ones moved to Falak databases or other sites).
        $bootstrap = array_values(array_unique(array_filter($uses, fn (string $service) => ($services[$service]['mode'] ?? 'keep') === 'keep')));
        sort($bootstrap);
        $agents = $this->agents->forServers($site->serverIds());
        $capable = $agents !== [] && array_reduce($site->serverIds(), fn (bool $ok, string $id) => $ok && ($agents[$id] ?? null)?->supports('compose.up.services') === true, true);

        if ($bootstrap === [] || Release::current($site->id) !== null || ! $capable) {
            throw new RuntimeException("The stack runs without {$names}, its own Falak site(s) that aren't live yet. Deploying {$names} first; the stack follows when it's live.");
        }

        $deployment->forceFill(['settings' => [...(array) $deployment->settings, 'bootstrap' => $bootstrap]])->save();

        return $bootstrap;
    }

    /**
     * @return array{yaml: string, env: array<string, string>, leader: array<string, list<string>>, source: string}
     */
    private function storedRelease(string $releaseId): array
    {
        $compose = $releaseId !== '' ? Release::query()->find(strtolower($releaseId))?->compose : null;

        if (! is_array($compose)) {
            throw new RuntimeException('The release has no compose files to return to.');
        }

        return $compose;
    }

    private function composeDirectory(SiteData $site, string $releaseId): string
    {
        return $site->rootPath.'/releases/'.self::upper($releaseId);
    }

    /**
     * docker.compose.pull (FETCH): the release's compose.yaml + .env, images pulled.
     *
     * @param  array{yaml: string, env: array<string, string>}  $release
     * @return array<string, mixed>
     */
    private function composeFiles(SiteData $site, array $release, string $releaseId, string $serverId, Deployment $deployment): array
    {
        $env = $this->composeEnv($release, $serverId);
        $assets = array_values((array) ($release['assets'] ?? []));

        // Repository files under repo/ need an agent that writes them (feature compose.v2).
        if ($assets !== [] && ! ($this->agents->forServers([$serverId])[$serverId] ?? null)?->supports('compose.v2')) {
            throw new RuntimeException('The Falak agent on this server is too old for compose projects that mount repository files; update it first.');
        }

        return array_filter([
            'project' => $site->slug,
            'directory' => $this->composeDirectory($site, $releaseId),
            'files' => [
                ['name' => 'compose.yaml', 'content' => $release['yaml']],
                ['name' => '.env', 'content' => self::composeDotenv($env)],
            ],
            'assets' => $assets === [] ? null : $assets,
            'env' => (object) $env,
            'project_env_file' => '.env',
            // Pull only the organization's own images of the built-in registry (short-lived).
            'registry_auth' => $this->builds->registryAuth($site->organizationId, $release['yaml']),
            'mask' => $this->mask($site, $env) ?: null,
        ], fn ($v) => $v !== null);
    }

    /**
     * docker.compose.up --wait (ACTIVATE, manual rollback, failure rollback).
     *
     * @param  array{yaml: string, env: array<string, string>}  $release
     * @return array<string, mixed>
     */
    private function composeUp(SiteData $site, array $release, string $releaseId, string $serverId, Deployment $deployment): array
    {
        $bootstrap = array_values(array_map('strval', (array) ($release['bootstrap'] ?? [])));

        // The stack's named volumes become (or stay) volumes of this server, attached to the services mounting them.
        $this->volumes->composeDeployed($site->organizationId, $site->id, $serverId, $site->slug, (string) $release['yaml']);

        $files = $this->composeFiles($site, $release, $releaseId, $serverId, $deployment);
        $network = $this->environmentNetwork($site->id);
        $limits = [];

        foreach (self::serviceNames((string) $release['yaml']) as $service) {
            $limits[$service] = $site->composeServiceLimits($service);
        }

        if (($override = self::falakOverride((string) $release['yaml'], $network, $limits)) !== null) {
            // After compose.yaml (files are passed with -f in order): the services start on the environment's network,
            // with their limits.
            array_splice($files['files'], 1, 0, [['name' => 'compose.falak.yaml', 'content' => $override]]);
        }

        return [
            ...$files,
            'pull' => 'missing',
            // A bootstrap pass starts a subset (and waits for its health); it removes nothing.
            'remove_orphans' => $bootstrap === [],
            'wait' => true,
            'wait_timeout_s' => max(1, min(3600, (int) config('deployments.compose.wait_timeout', 300))),
            ...($bootstrap !== [] ? ['services' => $bootstrap] : []),
            // The stack's containers join the environment's network, where its database containers are.
            ...(($network = $this->environmentNetwork($site->id)) !== null ? ['join_networks' => [$network]] : []),
        ];
    }

    /**
     * compose.falak.yaml, generated per deploy (null when there is nothing to add):
     *
     *  - the environment's network as an external network of every service (but those with a network_mode), so
     *    containers start on it and reach the environment's databases by name from their first healthcheck. Compose
     *    merges a service's networks with the file's: a service on its implicit `default` network keeps it;
     *  - each service's resource limits ({@see ResourceLimits::composeService()}: mem_limit / cpus / pids_limit and
     *    their deploy.resources twins, mem_reservation, restart, logging, oom_score_adj), replacing the file's own.
     *
     * @param  array<string, ResourceLimits>  $limits  effective limits by service name
     */
    public static function falakOverride(string $yaml, ?string $network, array $limits = []): ?string
    {
        try {
            $doc = Yaml::parse($yaml);
        } catch (ParseException) {
            return null;
        }

        $services = [];

        foreach ((array) (is_array($doc) ? ($doc['services'] ?? []) : []) as $name => $service) {
            if (! is_array($service)) {
                continue;
            }

            $override = ($limits[(string) $name] ?? null)?->composeService() ?? [];

            if ($network !== null && ! isset($service['network_mode'])) {
                $override['networks'] = [...(empty($service['networks']) ? ['default' => (object) []] : []), $network => (object) []];
            }

            if ($override !== []) {
                $services[(string) $name] = $override;
            }
        }

        if ($services === []) {
            return null;
        }

        $networks = $network !== null && array_filter($services, fn (array $service) => isset($service['networks'])) !== [] ? ['networks' => [$network => ['external' => true]]] : [];

        return Yaml::dump(['services' => $services, ...$networks], 6, 2, Yaml::DUMP_OBJECT_AS_MAP);
    }

    /**
     * @return list<string>
     */
    private static function serviceNames(string $yaml): array
    {
        try {
            $doc = Yaml::parse($yaml);
        } catch (ParseException) {
            return [];
        }

        return array_values(array_map('strval', array_keys(array_filter((array) (is_array($doc) ? ($doc['services'] ?? []) : []), 'is_array'))));
    }

    /**
     * The Docker network of the site's environment (falak-env-<id>) when that environment has databases: their
     * containers are on it, and the site's containers reach them by name there.
     */
    private function environmentNetwork(string $siteId): ?string
    {
        $placed = $this->projects->projectOf(ServiceKind::Site, $siteId);

        if ($placed === null) {
            return null;
        }

        foreach ($this->projects->servicesIn($placed->environmentId) as $service) {
            if ($service->kind === ServiceKind::Database) {
                return 'falak-env-'.strtolower($placed->environmentId);
            }
        }

        return null;
    }

    /**
     * system.exec on the leader: `docker compose run --rm <service> <argv>` for every falak.deploy.leader_command.
     * Arguments are shell-escaped one by one (never re-parsed by a shell).
     *
     * @param  array{leader: array<string, list<string>>}  $release
     * @return ?array<string, mixed>
     */
    private function composeLeader(SiteData $site, array $release, string $releaseId): ?array
    {
        $leader = (array) ($release['leader'] ?? []);

        // A bootstrap pass only starts what split-out sites use: the leader command runs with the full stack.
        if ($leader === [] || ($release['bootstrap'] ?? []) !== []) {
            return null;
        }

        $lines = ['set -e'];

        foreach ($leader as $service => $argv) {
            $command = implode(' ', array_map('escapeshellarg', array_map('strval', (array) $argv)));
            $lines[] = 'echo '.escapeshellarg("==> {$service}: ".implode(' ', (array) $argv));
            $lines[] = 'docker compose -p '.escapeshellarg($site->slug).' --env-file .env -f compose.yaml run --rm -T --no-deps '.escapeshellarg((string) $service).($command !== '' ? ' '.$command : '');
        }

        return [
            'script' => implode("\n", $lines)."\n",
            'cwd' => $this->composeDirectory($site, $releaseId),
        ];
    }

    /**
     * @param  array{env: array<string, string>}  $release
     * @return array<string, string>
     */
    private function composeEnv(array $release, string $serverId): array
    {
        $env = [...array_map('strval', (array) $release['env']), 'FALAK_SERVER_ID' => (string) self::upper($serverId)];

        return array_filter($env, fn ($v, $k) => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $k) === 1, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Project .env for `--env-file`: single-quoted (literal, no interpolation) where possible. The same values
     * are passed as the compose process environment, which wins over the file for interpolation.
     *
     * @param  array<string, string>  $env
     */
    public static function composeDotenv(array $env): string
    {
        $lines = [];

        foreach ($env as $key => $value) {
            $value = (string) $value;
            $lines[] = $key.'='.match (true) {
                preg_match('/^[A-Za-z0-9_.,:\/@+=\-]*$/', $value) === 1 => $value,
                ! str_contains($value, "'") && ! str_contains($value, "\n") => "'{$value}'",
                default => '"'.str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value).'"',
            };
        }

        return $lines === [] ? '' : implode("\n", $lines)."\n";
    }

    public static function upper(?string $ulid): ?string
    {
        return $ulid !== null ? strtoupper($ulid) : null;
    }

    /**
     * @return ?array<string, mixed> null when the step has nothing to run (compose leader step without leader commands)
     *
     * @throws RuntimeException when the payload cannot be built (missing artifact, image, port…)
     */
    public function for(DeploymentStep $step, Deployment $deployment, SiteData $site): ?array
    {
        $serverId = (string) $step->server_id;

        if ($site->runtime === SiteRuntime::Compose) {
            return $this->compose($step, $deployment, $site, $serverId);
        }

        if ($site->runtime === SiteRuntime::Function) {
            return $this->functionRelease($step, $deployment, $site, $serverId);
        }

        return match ($step->kind) {
            StepKind::Fetch => $this->fetch($deployment, $site),
            StepKind::Prepare => $this->prepare($deployment, $site, $serverId),
            StepKind::Hook => $this->hook($step, $deployment, $site, $serverId),
            StepKind::Activate => array_filter([
                ...$this->base($deployment, $site),
                'reload' => $this->reload($site),
                'context' => $this->context($deployment, $site),
            ], fn ($v) => $v !== null),
            StepKind::Switch => $this->rollbackTo($deployment, $site, (string) $deployment->target_release_id),
            StepKind::Revert => $this->rollbackTo($deployment, $site, (string) ($step->meta['release_id'] ?? '')),
            StepKind::Swap => $this->swap($deployment, $site, $serverId, $this->imageFor($deployment, $site), (string) $deployment->release_id),
            StepKind::RevertSwap => $this->swap($deployment, $site, $serverId, [(string) ($step->meta['image'] ?? ''), null], (string) ($deployment->previous_release_id ?? $deployment->release_id)),
            StepKind::Build, StepKind::HealthCheck, StepKind::Restart, StepKind::RevertRestart => throw new RuntimeException("{$step->kind->value} is not an agent command."),
        };
    }

    public function timeout(StepKind $kind, ?string $commandType = null): int
    {
        if ($commandType === 'fn.release.apply') {
            return max(60, min(3600, (int) config('deployments.timeouts.function', 600)));
        }

        if ($commandType === 'docker.compose.up') {
            return max(60, min(3600, (int) config('deployments.timeouts.compose_up', 600) + (int) config('deployments.compose.wait_timeout', 300)));
        }

        $key = match ($kind) {
            StepKind::Fetch => 'fetch',
            StepKind::Prepare => 'prepare',
            StepKind::Hook => 'hook',
            StepKind::Activate => 'activate',
            StepKind::Switch, StepKind::Revert => 'rollback',
            StepKind::Swap, StepKind::RevertSwap => 'swap',
            default => 'restart',
        };

        return max(1, min(3600, (int) config("deployments.timeouts.{$key}", 600)));
    }

    /**
     * @return array<string, mixed>
     */
    private function base(Deployment $deployment, SiteData $site): array
    {
        return [
            'site' => $site->slug,
            'release_id' => self::upper($deployment->release_id),
            'sites_root' => dirname($site->rootPath),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function context(Deployment $deployment, SiteData $site): array
    {
        return array_filter([
            'site_id' => self::upper($site->id),
            'deployment_id' => self::upper($deployment->id),
            'commit' => $deployment->commit,
            'author' => $deployment->commit_author,
            'branch' => $deployment->branch,
            'trigger' => $deployment->trigger->agentValue(),
            'php_binary' => $site->runtime->isPhp() ? ($site->phpVersion ? "php{$site->phpVersion}" : 'php') : null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(Deployment $deployment, SiteData $site): array
    {
        $artifact = $deployment->build_id ? $this->builds->artifactFor($deployment->build_id, (int) config('deployments.artifact_url_ttl', 3600)) : null;

        if ($artifact === null) {
            throw new RuntimeException('The build has no release artifact (was it pruned?).');
        }

        return [
            ...$this->base($deployment, $site),
            'artifact' => $artifact->toPayload(),
            'owner' => ['user' => $site->unixUser],
            'context' => $this->context($deployment, $site),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function prepare(Deployment $deployment, SiteData $site, string $serverId): array
    {
        $env = $this->releaseVariables($site);
        $this->rememberEnvironment($deployment, $env);

        return array_filter([
            ...$this->base($deployment, $site),
            // Shared paths are the site's shared_path volumes.
            'shared_paths' => array_map(fn (SharedPath $path) => ['path' => $path->path, 'type' => $path->type === 'file' ? 'file' : 'dir'], $this->mounts->sharedPaths($site->id)),
            'env_file' => ['content' => $this->dotenv([...$env, ...$this->injected($deployment, $site, $serverId), ...$this->configCache($site)])],
            'owner' => ['user' => $site->unixUser],
            'writable_dirs' => $site->framework->isLaravel() ? ['bootstrap/cache', 'storage'] : [],
            'context' => $this->context($deployment, $site),
            'mask' => $this->mask($site, $env) ?: null,
            'config_cache' => $this->configCache($site) !== [] ? true : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Laravel's config cache holds every resolved secret: it goes to the release's tmpfs directory (the agent links it
     * as .falak-cache; relative paths are resolved against the release), never to bootstrap/cache on disk.
     *
     * @return array<string, string>
     */
    private function configCache(SiteData $site): array
    {
        return $site->framework->isLaravel() && $site->runtime->isPhp() ? ['APP_CONFIG_CACHE' => '.falak-cache/config.php'] : [];
    }

    /**
     * Keep the variables the release's `.env` is written with (first prepare wins; all servers get the same file),
     * so Processes can put the same environment into the release's program env — and a rollback restores it.
     *
     * @param  array<string, string>  $env
     */
    private function rememberEnvironment(Deployment $deployment, array $env): void
    {
        $release = $deployment->release_id !== null ? Release::query()->find($deployment->release_id) : null;

        if ($release !== null && $release->environment === null) {
            $release->forceFill(['environment' => $env])->save();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function hook(DeploymentStep $step, Deployment $deployment, SiteData $site, string $serverId): array
    {
        $env = $this->scriptEnvironment($deployment, $site, $serverId);

        return array_filter([
            ...$this->base($deployment, $site),
            'name' => (string) ($step->meta['name'] ?? 'script'),
            'script' => "set -e\n".(string) ($step->meta['script'] ?? ':'),
            'user' => $site->unixUser,
            'cwd' => (string) ($step->meta['cwd'] ?? 'release'),
            'env' => (object) $env,
            'context' => $this->context($deployment, $site),
            // Every secret of the site: scripts also read the .env, where the agent looks these names up.
            'mask' => $this->mask($site, $env, false) ?: null,
        ], fn ($v) => $v !== null);
    }

    /**
     * FALAK_* variables for deploy script sections (plus exposed site environment and FALAK_VAR_*).
     *
     * @return array<string, string>
     */
    public function scriptEnvironment(Deployment $deployment, SiteData $site, string $serverId): array
    {
        $release = self::upper($deployment->release_id);

        $context = array_filter([
            'FALAK_COMMIT' => $deployment->commit,
            'FALAK_COMMIT_AUTHOR' => $deployment->commit_author,
            'FALAK_AUTHOR' => $deployment->commit_author,
            'FALAK_COMMIT_MESSAGE' => $deployment->commit_message !== null ? (string) strtok($deployment->commit_message, "\n") : null,
            'FALAK_BRANCH' => $deployment->branch,
            'FALAK_RELEASE_ID' => $release,
            'FALAK_RELEASE_DIR' => $release ? "{$site->rootPath}/releases/{$release}" : null,
            'FALAK_DEPLOYMENT_ID' => self::upper($deployment->id),
            'FALAK_TRIGGER' => $deployment->trigger->agentValue(),
            'FALAK_PHP_BINARY' => $site->runtime->isPhp() ? ($site->phpVersion ? "php{$site->phpVersion}" : 'php') : null,
        ], fn ($v) => $v !== null && $v !== '');

        foreach ($deployment->variables ?? [] as $key => $value) {
            $context[$key] = (string) $value;
        }

        $variables = $this->resolved($site, $this->sites->deployVariables($site->id, $serverId, $context));
        $variables['FALAK_SITE_ID'] = (string) self::upper($site->id);
        $variables['FALAK_SERVER_ID'] = (string) self::upper($serverId);

        return array_filter($variables, fn ($v, $k) => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $k) === 1, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * The site's latest environment with `${{ service.KEY }}` references resolved (Projects).
     *
     * @return array<string, string>
     *
     * @throws RuntimeException when a reference cannot be resolved (fails the deployment with the reason)
     */
    public function releaseVariables(SiteData $site): array
    {
        return $this->resolved($site, $this->sites->environment($site->id)?->variables ?? []);
    }

    /**
     * A compose release's variables: the site's, with the stack variables that pointed at services moved out of the
     * stack (Falak databases, own sites) replaced by their rewrites, then resolved like any `${{ }}` reference.
     *
     * @return array<string, string>
     *
     * @throws RuntimeException
     */
    private function composeVariables(SiteData $site): array
    {
        $variables = $this->sites->environment($site->id)?->variables ?? [];

        // The stack's rewritten variables replace their values; each remaining service's rewrites get their own names.
        return $this->resolved($site, [...$variables, ...$this->extraction->rewrites($site->id)->dotenv()]);
    }

    /**
     * @param  array<string, string>  $variables
     * @return array<string, string>
     *
     * @throws RuntimeException
     */
    private function resolved(SiteData $site, array $variables): array
    {
        $result = $this->references->resolveForSite($site->id, $variables);

        if (! $result->ok()) {
            throw new RuntimeException($result->errorSummary());
        }

        // Variables that took a secret store value, also through another service's variable: mask() adds them.
        $this->storeSecrets[$site->id] = $result->secretKeys;

        return $result->variables;
    }

    /**
     * Ids injected into the release environment for the APM packages (uppercase ULIDs, like the
     * labels agents attach to telemetry).
     *
     * @return array<string, string>
     */
    public function injected(Deployment $deployment, SiteData $site, string $serverId): array
    {
        return array_filter([
            'FALAK_SITE_ID' => self::upper($site->id),
            'FALAK_SERVER_ID' => self::upper($serverId),
            'FALAK_DEPLOYMENT_ID' => self::upper($deployment->id),
            'FALAK_RELEASE_ID' => self::upper($deployment->release_id),
        ]);
    }

    /**
     * Names of the secret variables (payload `mask`: the agent masks their values in the command's output). By name, or
     * through a `${{ service.KEY }}` reference to a secret, which only the site's stored variables still show.
     *
     * @param  array<string, string>  $env  the payload's variables
     * @param  bool  $presentOnly  only names $env has (false: every secret of the site, e.g. for scripts reading .env)
     * @return list<string>
     */
    public function mask(SiteData $site, array $env, bool $presentOnly = true): array
    {
        $names = array_unique([
            ...$this->secrets->names($this->sites->environment($site->id)?->variables ?? []),
            ...$this->secrets->names($env),
            ...($this->storeSecrets[$site->id] ?? []),
        ]);
        $names = array_values(array_filter($names, fn (string $name) => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1 && (! $presentOnly || array_key_exists($name, $env))));
        sort($names);

        return $names;
    }

    /**
     * site.env.write for a server whose agent lost a site's secrets (a reboot emptied /run): the live release's `.env`
     * for sites with releases on disk, the secret files of a container site in the secrets mode `files`. Null when the
     * site has nothing to restore there.
     *
     * @return ?array<string, mixed>
     *
     * @throws RuntimeException when the site's variables cannot be resolved (releases made before Falak kept them)
     */
    public function restoreSecrets(SiteData $site, LiveRelease $live, string $secretsMode): ?array
    {
        $release = (string) self::upper($live->releaseId);

        if ($site->runtime === SiteRuntime::Compose) {
            $stored = Release::query()->find($live->releaseId)?->compose;

            return is_array($stored) ? [
                'site' => $site->slug,
                'sites_root' => dirname($site->rootPath),
                'release_id' => $release,
                'compose' => true,
                'env_file' => ['content' => self::composeDotenv($env = $this->composeEnv($stored, $live->serverId))],
                'mask' => $this->mask($site, $env) ?: null,
            ] : null;
        }

        // Releases made before Falak recorded their variables get the site's current ones.
        $env = $live->environment !== [] ? array_map('strval', $live->environment) : $this->releaseVariables($site);

        if ($site->runtime === SiteRuntime::Docker) {
            $files = $secretsMode === SiteSettings::SECRETS_FILES ? $this->secretFiles($site, $env) : [];

            return $files === [] ? null : [
                'site' => $site->slug,
                'release_id' => $release,
                'secret_files' => $files,
                'mask' => array_column($files, 'name'),
            ];
        }

        if ($site->runtime === SiteRuntime::Function) {
            return null;
        }

        $injected = array_filter([
            'FALAK_SITE_ID' => self::upper($site->id),
            'FALAK_SERVER_ID' => self::upper($live->serverId),
            'FALAK_DEPLOYMENT_ID' => self::upper($live->deploymentId),
            'FALAK_RELEASE_ID' => $release,
        ]);
        $laravel = $this->configCache($site) !== [];

        return array_filter([
            'site' => $site->slug,
            'sites_root' => dirname($site->rootPath),
            'release_id' => $release,
            'env_file' => ['content' => $this->dotenv([...$env, ...$injected, ...$this->configCache($site)])],
            'config_cache' => $laravel ? true : null,
            'owner' => ['user' => $site->unixUser],
            // The config cache was on the tmpfs too: rebuild it, then let PHP and the site's programs read the env again.
            'after' => $laravel ? ['script' => ($site->phpVersion ? "php{$site->phpVersion}" : 'php').' artisan config:cache', 'user' => $site->unixUser] : null,
            'reload' => [...($this->reload($site) ?? []), ['kind' => 'site_procs', 'name' => $site->slug]],
            'mask' => $this->mask($site, $env) ?: null,
        ], fn ($v) => $v !== null);
    }

    /**
     * The secret variables of $env as /run/secrets files (the secrets mode `files`).
     *
     * @param  array<string, string>  $env
     * @return list<array{name: string, content: string}>
     */
    private function secretFiles(SiteData $site, array $env): array
    {
        return array_map(fn (string $name) => ['name' => $name, 'content' => (string) $env[$name]], $this->mask($site, $env));
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function dotenv(array $variables): string
    {
        $lines = [];

        foreach ($variables as $key => $value) {
            $value = (string) $value;
            $lines[] = $key.'='.($value === '' || preg_match('/^[A-Za-z0-9_.,:\/@+=\-]*$/', $value) === 1
                ? $value
                : '"'.str_replace(['\\', '"', "\n", '$'], ['\\\\', '\\"', '\\n', '\\$'], $value).'"');
        }

        return $lines === [] ? '' : implode("\n", $lines)."\n";
    }

    /**
     * @return array<string, mixed>
     */
    private function rollbackTo(Deployment $deployment, SiteData $site, string $releaseId): array
    {
        if ($releaseId === '') {
            throw new RuntimeException('No release to roll back to.');
        }

        return array_filter([
            'site' => $site->slug,
            'sites_root' => dirname($site->rootPath),
            'release_id' => self::upper($releaseId),
            'reload' => $this->reload($site),
            'context' => [...$this->context($deployment, $site), 'trigger' => 'rollback'],
        ], fn ($v) => $v !== null);
    }

    /**
     * @return ?list<array{kind: string, name?: string}>
     */
    private function reload(SiteData $site): ?array
    {
        return match ($site->runtime) {
            SiteRuntime::FrankenPhp => [['kind' => 'frankenphp']],
            SiteRuntime::PhpFpm => $site->phpVersion ? [['kind' => 'php_fpm', 'name' => $site->phpVersion]] : null,
            default => null,
        };
    }

    /**
     * @return array{0: string, 1: ?array<string, string>}
     */
    private function imageFor(Deployment $deployment, SiteData $site): array
    {
        if ($deployment->target_release_id !== null) {
            $image = Release::query()->whereKey($deployment->target_release_id)->value('image');

            return [(string) $image, $this->builds->registryAuth($site->organizationId, (string) $image)];
        }

        if ($deployment->build_id !== null) {
            $image = $this->builds->imageFor($deployment->build_id) ?? throw new RuntimeException('The build produced no image.');

            return [$image->ref, $image->registryAuth];
        }

        return [(string) $site->dockerImage, null];
    }

    /**
     * @param  array{0: string, 1: ?array<string, string>}  $image
     * @return array<string, mixed>
     */
    private function swap(Deployment $deployment, SiteData $site, string $serverId, array $image, string $releaseId): array
    {
        [$ref, $auth] = $image;

        if ($ref === '') {
            throw new RuntimeException('No image to run.');
        }

        if ($site->appPort === null) {
            throw new RuntimeException('The site has no app port for its container.');
        }

        $green = $site->appPort + (int) config('deployments.green_port_offset', 1000);

        if ($green > 65535) {
            throw new RuntimeException('The site app port leaves no room for the green container port.');
        }

        $variables = $this->releaseVariables($site);
        // Kept with the release: the secret files are restored from it after a server reboot.
        $this->rememberEnvironment($deployment, $variables);
        // The app listens on its container port; Falak publishes it on the site's loopback host ports (blue/green).
        $listen = $site->listenPort();
        $env = array_filter([...$variables, 'PORT' => (string) $listen, ...$this->injected($deployment, $site, $serverId)], fn ($v, $k) => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $k) === 1, ARRAY_FILTER_USE_BOTH);
        $mask = $this->mask($site, $env);
        $files = [];

        // Secrets mode `files`: secret variables are /run/secrets files, not env (docker inspect shows env).
        if ($deployment->setting('secrets_mode') === SiteSettings::SECRETS_FILES) {
            $files = $this->secretFiles($site, $env);
            $env = array_diff_key($env, array_flip($mask));
        }

        $health = (array) $deployment->setting('health', []);

        return array_filter([
            'site' => $site->slug,
            'image' => $ref,
            'pull' => 'missing',
            'registry_auth' => $auth,
            'container_port' => $listen,
            'ports' => ['blue' => $site->appPort, 'green' => $green],
            'env' => (object) array_map('strval', $env),
            'health' => [
                'path' => (string) ($health['path'] ?? '/'),
                'expect_status' => (int) ($health['status'] ?? 200),
                'timeout_s' => max(1, (int) ($health['timeout_s'] ?? 10) * max(1, (int) ($health['retries'] ?? 3))),
            ],
            // Sites with no domain have no edge route (a split-out compose service only its stack reaches).
            'edge_route_id' => $site->testDomain !== null || $this->edge->domainsFor($site->id) !== [] ? $this->edge->routeId($site->id) : null,
            // A compose service run as its own site keeps reaching the stack's services (and they it) by name; a site of
            // an environment with databases joins its network, where the database containers are.
            'networks' => [
                ...$this->compose->stackNetworks($site->id, $serverId),
                ...(($network = $this->environmentNetwork($site->id)) !== null ? [['name' => $network, 'environment' => true]] : []),
            ] ?: null,
            // Volumes attached to the site on this server: Docker volumes by name, sized ones at their mountpoint.
            'volumes' => array_map(fn (Mount $mount) => $mount->toPayload(), $this->mounts->forSite($site->id, $serverId)) ?: null,
            'labels' => (object) array_filter([
                'falak.site.id' => self::upper($site->id),
                'falak.deployment.id' => self::upper($deployment->id),
                // The release the container runs (a revert runs the previous one): secrets are restored for it after a reboot.
                'falak.release.id' => self::upper($releaseId),
            ]),
            'secret_files' => $files ?: null,
            'mask' => $mask ?: null,
            // Memory, CPUs, processes, restart policy, log caps and OOM preference.
            ...$site->limits->docker(),
        ], fn ($v) => $v !== null);
    }

    // ---- Functions -----------------------------------------------------------------------------------------------

    /**
     * fn.release.apply for the release the step puts live: the deployment's own (its commit is the version hash), the
     * one a rollback targets, or the previous one when a failure is rolled back.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    private function functionRelease(DeploymentStep $step, Deployment $deployment, SiteData $site, string $serverId): array
    {
        $releaseId = (string) match ($step->kind) {
            StepKind::Activate => $deployment->release_id,
            StepKind::Switch => $deployment->target_release_id,
            StepKind::Revert => $step->meta['release_id'] ?? '',
            default => throw new RuntimeException("{$step->kind->value} is not a function step."),
        };
        $hash = $step->kind === StepKind::Activate ? $deployment->commit : Release::query()->whereKey($releaseId)->value('commit');
        $source = $releaseId !== '' && is_string($hash) ? $this->functions->find($site->id, $hash) : null;

        if ($source === null) {
            throw new RuntimeException('The function version of this release no longer exists.');
        }

        // The gateway sets PORT (the runtime listens on a fixed port inside the container).
        $env = array_diff_key([
            ...$this->releaseVariables($site),
            ...$this->injected($deployment, $site, $serverId),
            'FALAK_RELEASE_ID' => self::upper($releaseId),
        ], ['PORT' => true]);
        $env = array_filter($env, fn ($v, $k) => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $k) === 1, ARRAY_FILTER_USE_BOTH);

        $files = [];

        foreach ($source->files as $path => $content) {
            $files[] = ['path' => (string) $path, 'content' => (string) $content];
        }

        return array_filter([
            'site' => $site->slug,
            'release' => strtolower($releaseId),
            'image' => $source->image,
            'pull' => 'missing',
            'registry_auth' => $this->builds->registryAuth($site->organizationId, $source->image),
            'entrypoint' => $source->entrypoint,
            'files' => $files,
            'env' => (object) array_map('strval', $env),
            'mask' => $this->mask($site, $env) ?: null,
            'scaling' => $source->scaling,
            'limits' => $source->limits,
            // The function's current access rules, also for rollbacks (a revoked key never comes back with an old
            // release). Only when restricted: agents before fn.v2 do not know the field (they refuse the release
            // rather than serve it unprotected).
            'access' => $source->access !== [] ? $source->access : null,
            'labels' => (object) array_filter([
                'falak.site.id' => self::upper($site->id),
                'falak.deployment.id' => self::upper($deployment->id),
                'falak.release.id' => self::upper($releaseId),
            ]),
        ], fn ($v) => $v !== null);
    }
}
