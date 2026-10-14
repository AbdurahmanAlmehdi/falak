<?php

namespace Falak\Sites\Domain\Models;

use Falak\Limits\Contracts\ResourceLimits;
use Falak\Sites\Application\SiteLogSources;
use Falak\Sites\Contracts\BuildMode;
use Falak\Sites\Contracts\ComposeSource;
use Falak\Sites\Contracts\Data\ComposeConfig;
use Falak\Sites\Contracts\Data\LaravelSettings;
use Falak\Sites\Contracts\Data\PublicService;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\Framework;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Sites\Contracts\TargetRole;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $slug
 * @property SiteRuntime $runtime
 * @property BuildMode $build_mode
 * @property Framework $framework
 * @property ?string $php_version
 * @property ?string $node_version
 * @property ?string $source_connection_id
 * @property ?string $repository
 * @property ?string $branch
 * @property ?string $deploy_key_id
 * @property bool $push_to_deploy
 * @property string $web_directory
 * @property string $unix_user
 * @property bool $isolated
 * @property ?int $app_port loopback host port Caddy proxies to (Falak-allocated for Docker sites)
 * @property ?int $container_port Docker sites: the port the app listens on inside its container
 * @property ?string $docker_image
 * @property ?string $dockerfile
 * @property ?string $root_directory repository subfolder the app lives in (null = the repository root)
 * @property ?string $compose_file
 * @property ?ComposeSource $compose_source
 * @property ?list<string> $compose_files
 * @property ?list<string> $compose_profiles
 * @property ?array<string, array{mode: string, database_id?: string, site_id?: string}> $compose_services
 * @property ?array{keep_binds?: list<string>} $compose_adjustments
 * @property ?string $compose_snapshot merged repository project last read from git (canvas / read models)
 * @property ?list<array{service: string, port: int, domain?: ?string, host_port?: ?int, health_check_path?: ?string}> $public_services
 * @property ?array{slug: string, version: string, source: string} $template
 * @property ?string $health_check_path
 * @property ?array<string, mixed> $limits resource limits (Limits' ResourceLimits JSON)
 * @property ?array<string, array<string, mixed>> $compose_limits resource limits per compose service
 * @property ?list<array{path: string, format?: 'plain'|'json', multiline?: 'laravel'|'pattern', multiline_pattern?: string, compose_service?: string}> $log_sources files inside the site's containers the agent tails (SiteLogSources)
 * @property ?int $fpm_slowlog_timeout PHP-FPM slow log threshold in seconds (null or 0 = off; new PHP-FPM sites start with SiteLogSources::DEFAULT_SLOWLOG_TIMEOUT)
 * @property string $deploy_script
 * @property LaravelSettings $laravel
 * @property bool $test_domain_enabled
 * @property ?string $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Site extends Model
{
    use HasUlids;

    protected $table = 'sites_sites';

    /** @var list<string> */
    protected $guarded = [];

    protected static function booted(): void
    {
        // New PHP-FPM sites get the slow log; sites that existed before it keep it off (null), so the agent upgrade
        // that brings the slow log rewrites no pool and reloads no PHP-FPM master.
        static::creating(function (Site $site) {
            if ($site->runtime === SiteRuntime::PhpFpm && $site->fpm_slowlog_timeout === null) {
                $site->fpm_slowlog_timeout = SiteLogSources::DEFAULT_SLOWLOG_TIMEOUT;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'runtime' => SiteRuntime::class,
            'build_mode' => BuildMode::class,
            'framework' => Framework::class,
            'push_to_deploy' => 'boolean',
            'isolated' => 'boolean',
            'app_port' => 'integer',
            'container_port' => 'integer',
            'test_domain_enabled' => 'boolean',
            'compose_source' => ComposeSource::class,
            'compose_files' => 'array',
            'compose_profiles' => 'array',
            'compose_services' => 'array',
            'compose_adjustments' => 'array',
            'public_services' => 'array',
            'template' => 'array',
            'limits' => 'array',
            'compose_limits' => 'array',
            'log_sources' => 'array',
            'fpm_slowlog_timeout' => 'integer',
        ];
    }

    /**
     * @return Attribute<LaravelSettings, LaravelSettings|array<string, bool>>
     */
    protected function laravel(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => LaravelSettings::fromArray($value ? (array) json_decode($value, true) : []),
            set: fn (LaravelSettings|array $value) => json_encode($value instanceof LaravelSettings ? $value->toArray() : LaravelSettings::fromArray($value)->toArray(), JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return HasMany<SiteTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(SiteTarget::class)->orderByRaw("case when role = 'leader' then 0 else 1 end")->orderBy('created_at');
    }

    /**
     * @return HasMany<EnvironmentVersion, $this>
     */
    public function environmentVersions(): HasMany
    {
        return $this->hasMany(EnvironmentVersion::class)->orderByDesc('version');
    }

    /**
     * @return HasOne<EnvironmentVersion, $this>
     */
    public function latestEnvironment(): HasOne
    {
        return $this->hasOne(EnvironmentVersion::class)->ofMany('version', 'max');
    }

    /**
     * @return HasMany<SiteCommand, $this>
     */
    public function commands(): HasMany
    {
        return $this->hasMany(SiteCommand::class)->latest()->orderByDesc('id');
    }

    public function rootPath(): string
    {
        return rtrim((string) config('sites.root', '/srv/falak/sites'), '/').'/'.$this->slug;
    }

    public function currentPath(): string
    {
        return $this->rootPath().'/current';
    }

    public function testDomain(): ?string
    {
        $base = config('sites.test_domain');

        return $this->test_domain_enabled && is_string($base) && $base !== '' ? $this->slug.'.'.strtolower(trim($base, '.')) : null;
    }

    /**
     * Public compose services with their test domains: the first gets the site's <slug> test domain,
     * the others <service>-<slug>.
     *
     * @return list<PublicService>
     */
    public function publicServices(): array
    {
        if ($this->runtime !== SiteRuntime::Compose) {
            return [];
        }

        $base = config('sites.test_domain');
        $base = $this->test_domain_enabled && is_string($base) && $base !== '' ? strtolower(trim($base, '.')) : null;
        $out = [];

        foreach (array_values(array_filter((array) $this->public_services, 'is_array')) as $i => $public) {
            $service = (string) ($public['service'] ?? '');
            $label = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($service)), '-');
            $testDomain = $base === null ? null : ($i === 0 ? "{$this->slug}.{$base}" : substr("{$label}-{$this->slug}", 0, 63).".{$base}");

            $out[] = new PublicService(
                service: $service,
                port: (int) ($public['port'] ?? 0),
                domain: isset($public['domain']) && $public['domain'] !== '' ? strtolower((string) $public['domain']) : null,
                hostPort: isset($public['host_port']) ? (int) $public['host_port'] : null,
                testDomain: $testDomain,
                healthCheckPath: isset($public['health_check_path']) && is_string($public['health_check_path']) && str_starts_with($public['health_check_path'], '/') ? $public['health_check_path'] : null,
            );
        }

        return $out;
    }

    public function publicService(string $service): ?PublicService
    {
        foreach ($this->publicServices() as $public) {
            if ($public->service === $service) {
                return $public;
            }
        }

        return null;
    }

    public function composeConfig(): ?ComposeConfig
    {
        if ($this->runtime !== SiteRuntime::Compose) {
            return null;
        }

        $source = $this->compose_source ?? ComposeSource::Repo;
        $version = $source === ComposeSource::Inline ? ComposeVersion::query()->where('site_id', $this->id)->max('version') : null;

        $files = $source === ComposeSource::Repo ? $this->composeFiles() : [];

        return new ComposeConfig(
            source: $source,
            file: $files[0] ?? null,
            publicServices: $this->publicServices(),
            template: is_array($this->template) ? $this->template : null,
            version: $version !== null ? (int) $version : null,
            files: $files,
            profiles: array_values(array_map('strval', (array) $this->compose_profiles)),
            services: array_filter((array) $this->compose_services, fn ($d) => is_array($d) && isset($d['mode'])),
            adjustments: is_array($this->compose_adjustments) ? $this->compose_adjustments : [],
        );
    }

    /**
     * Repo source compose files in -f order (compose_files, else the single compose_file; empty = default name).
     *
     * @return list<string>
     */
    public function composeFiles(): array
    {
        $files = array_values(array_filter(array_map('strval', (array) $this->compose_files), fn (string $f) => $f !== ''));

        return $files !== [] ? $files : (is_string($this->compose_file) && $this->compose_file !== '' ? [$this->compose_file] : []);
    }

    public function leaderTarget(): ?SiteTarget
    {
        return $this->targets->firstWhere('role', TargetRole::Leader);
    }

    /** PHP CLI invocation on the site's servers. */
    public function phpBinary(): string
    {
        if (! $this->runtime->isPhp()) {
            return 'php';
        }

        return $this->php_version ? "php{$this->php_version}" : 'php';
    }

    /**
     * @return list<string>
     */
    public function serverIds(): array
    {
        return $this->targets->pluck('server_id')->map(fn ($id) => (string) $id)->values()->all();
    }

    /**
     * @return list<string>
     */
    public function leaderFirstServerIds(): array
    {
        return $this->targets->sortByDesc(fn (SiteTarget $target) => $target->role === TargetRole::Leader)
            ->pluck('server_id')->map(fn ($id) => (string) $id)->values()->all();
    }

    public function toData(): SiteData
    {
        return new SiteData(
            id: $this->id,
            organizationId: $this->organization_id,
            name: $this->name,
            slug: $this->slug,
            runtime: $this->runtime,
            buildMode: $this->build_mode,
            framework: $this->framework,
            phpVersion: $this->php_version,
            nodeVersion: $this->node_version,
            sourceConnectionId: $this->source_connection_id,
            repository: $this->repository,
            branch: $this->branch,
            deployKeyId: $this->deploy_key_id,
            pushToDeploy: $this->push_to_deploy,
            rootPath: $this->rootPath(),
            webDirectory: $this->web_directory,
            unixUser: $this->unix_user,
            isolated: $this->isolated,
            appPort: $this->app_port,
            dockerImage: $this->docker_image,
            dockerfile: $this->dockerfile,
            composeFile: $this->compose_file,
            healthCheckPath: $this->health_check_path,
            deployScript: $this->deploy_script,
            laravel: $this->laravel,
            testDomain: $this->testDomain(),
            targets: $this->targets->map(fn (SiteTarget $target) => $target->toData())->values()->all(),
            compose: $this->composeConfig(),
            containerPort: $this->container_port,
            rootDirectory: $this->root_directory,
            limits: ResourceLimits::fromArray($this->limits),
            composeLimits: array_map(fn ($limits) => ResourceLimits::fromArray((array) $limits), $this->compose_limits ?? []),
        );
    }
}
