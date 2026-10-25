<?php

namespace Falak\Sites\Application\Actions;

use Closure;
use Falak\Identity\Contracts\AuditLog;
use Falak\Sites\Application\ComposeSettings;
use Falak\Sites\Application\SiteRules;
use Falak\Sites\Application\SourceControlLinker;
use Falak\Sites\Application\TargetProvisioner;
use Falak\Sites\Contracts\BuildMode;
use Falak\Sites\Contracts\ComposeSource;
use Falak\Sites\Contracts\Data\DomainChoice;
use Falak\Sites\Contracts\Data\SitePlacement;
use Falak\Sites\Contracts\Framework;
use Falak\Sites\Contracts\SiteDomains;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Sites\Contracts\TargetRole;
use Falak\Sites\Contracts\TargetStatus;
use Falak\Sites\Domain\Models\EnvironmentVersion;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Models\SiteTarget;
use Falak\Sites\Domain\Presets\Preset;
use Falak\Sites\Events\SiteCreated;
use Falak\Volumes\Contracts\ServiceVolumes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CreateSite
{
    public const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,62}$/';

    /**
     * Slugs a new site never gets: `falak` and anything starting with `falak-`. A compose site's project is its slug and
     * the control plane's own compose project is `falak` (deploy/compose.yml); its containers, networks and volumes are
     * falak-* / falak_*, and slug + "-" + service could otherwise name one of them. Existing sites keep their slugs.
     */
    public const RESERVED_SLUG_PATTERN = '/^falak(-|$)/';

    /** @var list<string> warnings from the source control provider */
    public array $warnings = [];

    public function __construct(
        private readonly SiteRules $rules,
        private readonly TargetProvisioner $provisioner,
        private readonly SourceControlLinker $sourceControl,
        private readonly AuditLog $audit,
        private readonly ServiceVolumes $volumes,
        private readonly ComposeSettings $composeSettings,
        private readonly SiteDomains $domains,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $serverIds
     * @return array{source: ComposeSource, content: ?string, public_services: list<array{service: string, port: int, domain: ?string, host_port: int}>, project: ?array{files: list<string>, profiles: list<string>, services: array<string, array<string, mixed>>, adjustments: array<string, mixed>, extract: list<array<string, mixed>>}}
     */
    private function composeFields(string $organizationId, array $data, array $serverIds): array
    {
        $content = isset($data['compose_content']) && is_string($data['compose_content']) && trim($data['compose_content']) !== '' ? $data['compose_content'] : null;
        $source = ComposeSettings::source($data['compose_source'] ?? null, $content !== null);
        $summary = null;

        if ($source === ComposeSource::Inline) {
            $summary = $this->composeSettings->validateInline($organizationId, (string) $content);
        } elseif ($content !== null) {
            throw ValidationException::withMessages(['compose_content' => 'Repository sources read the compose file from the repository.']);
        }

        $project = $source === ComposeSource::Repo ? $this->composeSettings->project($data) : null;

        if ($source === ComposeSource::Inline && ($data['compose_services'] ?? []) !== []) {
            // An inline stack's services can run as Falak services too (the file is stored, so extraction reads it).
            $project = ['files' => [], 'profiles' => [], 'services' => [], 'adjustments' => [], 'extract' => $this->composeSettings->project($data)['extract']];
        }

        if (($project['extract'] ?? []) !== []) {
            // A service that runs as a Falak database or its own site is not public in the stack.
            $leaving = array_column($project['extract'], 'service');
            $data['public_services'] = array_values(array_filter((array) ($data['public_services'] ?? []), fn ($p) => ! in_array((string) ($p['service'] ?? ''), $leaving, true)));
        }

        // The new flow (several files / decisions) checks the repository before creating anything.
        if ($project !== null && array_key_exists('compose_files', $data) && isset($data['source_connection_id'], $data['repository'])) {
            $project['yaml'] = $this->composeSettings->verifyRepository((string) $data['source_connection_id'], (string) $data['repository'], (string) ($data['branch'] ?? 'main'),
                $project, array_values((array) ($data['public_services'] ?? [])), (array) ($data['variables'] ?? []), isset($data['root_directory']) ? (string) $data['root_directory'] : null);
        }

        return [
            'source' => $source,
            'content' => $content,
            'public_services' => $this->composeSettings->publicServices(array_values((array) ($data['public_services'] ?? [])), $serverIds, $summary),
            'project' => $project,
        ];
    }

    /** Repository subfolder without surrounding slashes; empty = the repository root (null). */
    public static function rootDirectory(mixed $value): ?string
    {
        $value = trim((string) $value, '/');

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, string>
     */
    private function variables(mixed $variables): array
    {
        $out = [];

        foreach (is_array($variables) ? $variables : [] as $key => $value) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $key) !== 1) {
                throw ValidationException::withMessages(['variables' => "“{$key}” is not a valid variable name."]);
            }

            $out[(string) $key] = (string) $value;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $data  validated StoreSiteRequest input
     * @param  ?SitePlacement  $placement  passed through to SiteCreated for Projects
     * @param  bool  $requireServers  false lets a site start without servers (environment duplicates)
     * @param  ?Closure(Site): void  $configure  runs inside the creation transaction, before any side effect
     */
    public function __invoke(string $organizationId, ?string $userId, array $data, ?SitePlacement $placement = null, bool $requireServers = true, ?Closure $configure = null): Site
    {
        $framework = isset($data['framework']) && $data['framework'] !== null ? Framework::from((string) $data['framework']) : Framework::Docker;
        $preset = Preset::for($framework);
        $runtime = isset($data['runtime']) ? SiteRuntime::from((string) $data['runtime']) : $preset->defaultRuntime();
        $buildMode = isset($data['build_mode']) ? BuildMode::from((string) $data['build_mode']) : $preset->defaultBuildMode($runtime);
        /** @var list<string> $serverIds */
        $serverIds = array_values(array_unique(array_map('strval', (array) ($data['server_ids'] ?? []))));

        $phpVersion = $runtime->isPhp() ? (string) ($data['php_version'] ?? '') : null;
        if ($phpVersion === '') {
            $phpVersion = $this->rules->defaultPhpVersion($organizationId, $serverIds);
        }
        $leaderId = (string) ($data['leader_server_id'] ?? $serverIds[0] ?? '');

        if ($serverIds !== [] && ! in_array($leaderId, $serverIds, true)) {
            throw ValidationException::withMessages(['leader_server_id' => 'The leader must be one of the selected servers.']);
        }

        $this->rules->runtimeAndFramework($framework, $runtime, $buildMode);
        if ($requireServers || $serverIds !== []) {
            $this->rules->targets($organizationId, $serverIds, $runtime, $phpVersion, $buildMode, leaderId: $leaderId);
        }

        $this->rules->connection($organizationId, $data['source_connection_id'] ?? null);

        $appPort = null;
        $containerPort = null;
        $compose = null;
        $slug = $this->slug((string) ($data['slug'] ?? '') ?: (string) $data['name']);
        $domain = null;

        // An explicit domain choice (create forms, API): a generated name for the leader, the test domain (null) or the
        // user's own domain, routed right after the site exists.
        if ($runtime === SiteRuntime::Compose) {
            if (isset($data['domain'])) {
                throw ValidationException::withMessages(['domain' => 'Compose sites take a domain per public service (public_services[].domain).']);
            }

            $data['public_services'] = $this->composeSettings->resolveDomainChoices($organizationId, array_values((array) ($data['public_services'] ?? [])), $slug, $this->leaderFirst($serverIds, $leaderId));
        } elseif (isset($data['domain'])) {
            $domain = $this->domains->resolveChoice($organizationId, DomainChoice::fromInput($data['domain'], 'domain'), $slug, $this->leaderFirst($serverIds, $leaderId), 'domain');
        }

        if ($runtime === SiteRuntime::Compose) {
            $compose = $this->composeFields($organizationId, $data, $serverIds);
            $appPort = $compose['public_services'][0]['host_port'] ?? null;
        } elseif ($runtime === SiteRuntime::Docker) {
            // The container listens on its own port (any value, repeated freely across sites); Caddy reaches it on a
            // loopback host port Falak allocates. app_port from older clients meant the container port.
            $containerPort = (int) ($data['container_port'] ?? $data['app_port'] ?? config('sites.default_container_port', 3000));
            $appPort = $this->rules->freePort($serverIds);
        } elseif ($runtime->proxiesToPort()) {
            $appPort = isset($data['app_port']) ? (int) $data['app_port'] : $this->rules->freePort($serverIds);
            $this->rules->portAvailable($appPort, $serverIds);
        }

        $isolated = (bool) ($data['isolated'] ?? false);

        $variables = $this->variables($data['variables'] ?? null);

        $site = DB::transaction(function () use ($organizationId, $userId, $data, $framework, $preset, $runtime, $buildMode, $phpVersion, $serverIds, $leaderId, $appPort, $containerPort, $slug, $isolated, $configure, $compose, $variables, $domain) {
            $site = Site::query()->create([
                'organization_id' => $organizationId,
                'name' => $data['name'],
                'slug' => $slug,
                'runtime' => $runtime,
                'build_mode' => $buildMode,
                'framework' => $framework,
                'php_version' => $phpVersion,
                'node_version' => $runtime->isPhp() || $runtime->usesDocker() ? ($data['node_version'] ?? null) : ($data['node_version'] ?? (string) config('sites.default_node', '22')),
                'source_connection_id' => $data['source_connection_id'] ?? null,
                'repository' => $data['repository'] ?? null,
                'branch' => $data['branch'] ?? null,
                'root_directory' => self::rootDirectory($data['root_directory'] ?? null),
                'push_to_deploy' => (bool) ($data['push_to_deploy'] ?? false),
                'web_directory' => trim((string) ($data['web_directory'] ?? $preset->webDirectory), '/'),
                'unix_user' => $isolated ? $this->unixUser($slug) : (string) config('sites.unix_user', 'falak'),
                'isolated' => $isolated,
                'app_port' => $appPort,
                'container_port' => $containerPort,
                'docker_image' => $data['docker_image'] ?? null,
                'dockerfile' => $runtime === SiteRuntime::Docker ? ($data['dockerfile'] ?? (isset($data['docker_image']) ? null : 'Dockerfile')) : null,
                'compose_file' => $compose['project']['files'][0] ?? null,
                'compose_files' => ($compose['project']['files'] ?? []) ?: null,
                'compose_profiles' => ($compose['project']['profiles'] ?? []) ?: null,
                'compose_adjustments' => ($compose['project']['adjustments'] ?? []) ?: null,
                'compose_snapshot' => $compose['project']['yaml'] ?? null,
                'compose_source' => $compose['source'] ?? null,
                'public_services' => $compose['public_services'] ?? null,
                'template' => $data['template'] ?? null,
                // Functions get no health checks: probing one would keep it from scaling to zero.
                'health_check_path' => $runtime->isFunction() ? null : ($data['health_check_path'] ?? $preset->healthCheckPath),
                'deploy_script' => $preset->deployScript."\n",
                'laravel' => $framework->isLaravel() ? $preset->laravel : [],
                'test_domain_enabled' => (bool) ($data['test_domain_enabled'] ?? true),
                'created_by' => $userId,
            ]);

            foreach ($serverIds as $serverId) {
                SiteTarget::query()->create([
                    'site_id' => $site->id,
                    'server_id' => $serverId,
                    'role' => $serverId === $leaderId ? TargetRole::Leader : TargetRole::Member,
                    'status' => TargetStatus::Pending,
                ]);
            }

            EnvironmentVersion::query()->create([
                'site_id' => $site->id,
                'version' => 1,
                'variables' => [...$this->initialEnvironment($site, $preset, $domain), ...$variables],
                'exposed' => [],
                'changed_keys' => [],
                'created_by' => $userId,
                'created_at' => now(),
            ]);

            if ($compose !== null && $compose['content'] !== null) {
                $this->composeSettings->saveVersion($site, $compose['content'], $userId);
            }

            // The preset's shared paths (storage, .env …) are volumes of kind shared_path.
            $this->volumes->syncSharedPaths($organizationId, $site->id, $preset->sharedPaths, $userId);

            if ($configure !== null) {
                $configure($site);
            }

            return $site;
        });

        $site->load('targets');
        $this->warnings = $this->sourceControl->link($site);

        foreach ($site->targets as $target) {
            $target->setRelation('site', $site);
            $this->provisioner->start($target);
        }

        if ($domain !== null) {
            try {
                $this->domains->attach($site->id, $domain);
            } catch (ValidationException $e) {
                $this->warnings[] = "The site was created, but {$domain} could not be added: ".collect($e->errors())->flatten()->first();
            }
        }

        $this->audit->record('site.created', 'site', $site->id, [
            'name' => $site->name,
            'runtime' => $runtime->value,
            'framework' => $framework->value,
            'servers' => $serverIds,
            'repository' => $site->repository,
        ], $organizationId);

        SiteCreated::dispatch($site->id, $organizationId, $site->slug, $runtime->value, $serverIds, $placement);

        // Services the user moved out of the stack (Falak databases, own sites) are created once the site is placed.
        if (($compose['project']['extract'] ?? []) !== []) {
            array_push($this->warnings, ...$this->composeSettings->extract($site, $compose['project']['extract'], $compose['project']['yaml'] ?? null));
        }

        return $site->refresh()->load('targets');
    }

    /**
     * @param  list<string>  $serverIds
     * @return list<string>
     */
    private function leaderFirst(array $serverIds, string $leaderId): array
    {
        return $leaderId === '' ? $serverIds : [$leaderId, ...array_values(array_diff($serverIds, [$leaderId]))];
    }

    private function slug(string $source): string
    {
        $base = Str::limit(trim(Str::slug($source), '-'), 50, '') ?: 'site';
        $base = preg_match(self::SLUG_PATTERN, $base) === 1 && preg_match(self::RESERVED_SLUG_PATTERN, $base) !== 1 ? $base : Str::limit('site-'.$base, 50, '');
        $slug = $base;

        for ($i = 2; Site::query()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    /**
     * Linux user for an isolated site: ^[a-z_][a-z0-9_-]{0,31}$, unique among sites. Names starting with "falak" are
     * Falak's own (the shared deploy user, Redis / Valkey instance users falak-redis-* / falak-valkey-*): such slugs get
     * an "s-" prefix. Existing sites keep their users.
     */
    private function unixUser(string $slug): string
    {
        $base = substr(match (true) {
            ctype_digit($slug[0]) => "s{$slug}",
            str_starts_with($slug, 'falak') => "s-{$slug}",
            default => $slug,
        }, 0, 28);
        $user = rtrim($base, '-');

        for ($i = 2; Site::query()->where('unix_user', $user)->exists(); $i++) {
            $user = rtrim($base, '-')."-{$i}";
        }

        return $user;
    }

    /**
     * @return array<string, string>
     */
    private function initialEnvironment(Site $site, Preset $preset, ?string $domain = null): array
    {
        $variables = $preset->environment;

        if (array_key_exists('APP_NAME', $variables)) {
            $variables['APP_NAME'] = $site->name;
        }

        if (array_key_exists('APP_KEY', $variables)) {
            $variables['APP_KEY'] = 'base64:'.base64_encode(random_bytes(32));
        }

        if (array_key_exists('APP_SECRET', $variables)) {
            $variables['APP_SECRET'] = bin2hex(random_bytes(16));
        }

        if (array_key_exists('APP_URL', $variables)) {
            $variables['APP_URL'] = ($host = $domain ?? $site->testDomain()) ? "https://{$host}" : '';
        }

        if ($site->app_port !== null && $site->runtime !== SiteRuntime::Compose) {
            $variables['PORT'] = (string) ($site->container_port ?? $site->app_port);
        }

        return $variables;
    }
}
