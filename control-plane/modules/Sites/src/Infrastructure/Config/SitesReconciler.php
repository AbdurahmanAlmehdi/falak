<?php

namespace Falak\Sites\Infrastructure\Config;

use Falak\Config\Contracts\ApplyContext;
use Falak\Config\Contracts\ApplyPhase;
use Falak\Config\Contracts\Change;
use Falak\Config\Contracts\ChangeOp;
use Falak\Config\Contracts\ChangeSet;
use Falak\Config\Contracts\ConfigIssue;
use Falak\Config\Contracts\ConfigScope;
use Falak\Config\Contracts\Diff;
use Falak\Config\Contracts\ManagedResources;
use Falak\Config\Contracts\Pointer;
use Falak\Config\Contracts\Reconcilable;
use Falak\Config\Contracts\ValidatesConfig;
use Falak\Databases\Contracts\DatabaseDirectory;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Application\Actions\SaveEnvironment;
use Falak\Sites\Application\Actions\SetSiteTargets;
use Falak\Sites\Application\Actions\UpdateComposeSettings;
use Falak\Sites\Application\Actions\UpdateDeployScript;
use Falak\Sites\Application\Actions\UpdateSite;
use Falak\Sites\Application\SiteRules;
use Falak\Sites\Contracts\BuildMode;
use Falak\Sites\Contracts\ComposeSource;
use Falak\Sites\Contracts\Data\ComposeConfig;
use Falak\Sites\Contracts\Data\SitePlacement;
use Falak\Sites\Contracts\DeployScriptSections;
use Falak\Sites\Contracts\Framework;
use Falak\Sites\Contracts\OctaneServer;
use Falak\Sites\Contracts\SecretVariables;
use Falak\Sites\Contracts\SiteFactory;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Sites\Domain\Models\EnvironmentVersion;
use Falak\Sites\Domain\Models\Site;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * falak.yaml's app, compose and function units (phase A): the site itself (created from the file when it doesn't
 * exist), its runtime, versions, directories, build, servers, env variables and deploy hooks; a compose unit's files,
 * profiles and services (public port and health path, or replaced by a database unit). A function unit's code and
 * settings belong to Functions.
 *
 * The file only manages what it sets: an absent field keeps today's value. Env keys merge into today's variables
 * (keys not in the file stay); each hook replaces only its section of today's deploy script. **[decision]** Compose
 * bind adjustments (`keep_binds`, host folders mounted into containers) are not in the file: the schema refuses them
 * and the file never changes them (an admin's choice in the dashboard).
 */
final class SitesReconciler implements Reconcilable, ValidatesConfig
{
    /**
     * Unit fields this reconciler owns, in phase 1 for app units only. `laravel` belongs to {@see SitesLaravelReconciler}
     * (phase B): its scheduler, Horizon and Octane change running processes, so they go live with the release.
     */
    public const FIELDS = ['type', 'root', 'runtime', 'framework', 'php', 'node', 'web_directory', 'port', 'image', 'health_path', 'test_domain', 'servers', 'build', 'env', 'expose', 'deploy.hooks'];

    /** Fields of compose units only. */
    public const COMPOSE_FIELDS = ['files', 'profiles', 'services.*.port', 'services.*.health_path', 'services.*.replace_with'];

    /** The unit types owning a field (others: app units only). A function's root is its code (Functions). */
    private const TYPES = [
        'type' => 'app|compose|function', 'servers' => 'app|compose|function', 'env' => 'app|compose|function',
        'root' => 'app|compose', 'test_domain' => 'app|compose', 'expose' => 'app|compose', 'deploy.hooks' => 'app|compose',
        'files' => 'compose', 'profiles' => 'compose', 'services.*.port' => 'compose', 'services.*.health_path' => 'compose', 'services.*.replace_with' => 'compose',
    ];

    /** Shown instead of a stored value that may be a secret. */
    public const REDACTED = '••••';

    public function __construct(
        private readonly ManagedResources $managed,
        private readonly ServerDirectory $servers,
        private readonly SecretVariables $secrets,
        private readonly DatabaseDirectory $databases,
    ) {}

    public function sections(): array
    {
        return array_map(fn (string $field) => self::section($field), [...self::FIELDS, ...self::COMPOSE_FIELDS]);
    }

    private static function section(string $field): string
    {
        return 'units.*('.(self::TYPES[$field] ?? 'app').").{$field}";
    }

    public function phase(): ApplyPhase
    {
        return ApplyPhase::BeforeBuild;
    }

    public function current(ConfigScope $scope): array
    {
        $units = [];

        foreach ($scope->services as $unit => $service) {
            if ($service['kind'] !== 'site' || ($site = Site::query()->with('targets')->find($service['ref_id'])) === null) {
                continue;
            }

            $units[(string) $unit] = $this->unitOf($site, $scope, redact: true);
        }

        return $units === [] ? [] : ['units' => $units];
    }

    /**
     * A site in the file's shape (defaults the file would leave out are kept where cheap: diffs compare field by field).
     *
     * @return array<string, mixed>
     */
    public function unitOf(Site $site, ConfigScope $scope, bool $redact = false): array
    {
        $type = match ($site->runtime) {
            SiteRuntime::Compose => 'compose',
            SiteRuntime::Function => 'function',
            default => 'app',
        };
        $unit = ['type' => $type];

        if ($site->root_directory !== null && $site->root_directory !== '') {
            $unit['root'] = $site->root_directory;
        }

        $unit['runtime'] = $site->runtime->value;
        $unit['framework'] = $site->framework->value;

        if ($site->php_version !== null) {
            $unit['php'] = $site->php_version;
        }

        if ($site->node_version !== null) {
            $unit['node'] = $site->node_version;
        }

        $unit['web_directory'] = (string) $site->web_directory;

        if ($site->runtime === SiteRuntime::Docker && $site->container_port !== null) {
            $unit['port'] = $site->container_port;
        }

        if ($site->docker_image !== null) {
            $unit['image'] = $site->docker_image;
        }

        if ($site->health_check_path !== null) {
            $unit['health_path'] = $site->health_check_path;
        }

        $unit['test_domain'] = (bool) $site->test_domain_enabled;
        $servers = [];

        foreach ($site->targets as $target) {
            $servers[] = $scope->serverName($target->server_id) ?? $this->servers->find($target->server_id)?->name ?? $target->server_id;
        }

        if ($servers !== []) {
            $unit['servers'] = $servers;
        }

        $unit['build'] = array_filter(['mode' => $site->build_mode->value, 'dockerfile' => $site->dockerfile], fn ($v) => $v !== null);

        if ($site->framework->isLaravel()) {
            $laravel = $site->laravel;
            $unit['laravel'] = ['scheduler' => $laravel->scheduler, 'horizon' => $laravel->horizon, 'octane' => $laravel->octane ? ($laravel->octaneServer?->value ?? OctaneServer::defaultFor($site->runtime)->value) : false];
        }

        $environment = EnvironmentVersion::query()->where('site_id', $site->id)->orderByDesc('version')->first();
        $variables = $environment !== null ? array_map('strval', $environment->variables) : [];

        if ($variables !== []) {
            $secret = $redact ? array_flip($this->secrets->names($variables)) : [];
            $unit['env'] = [];

            foreach ($variables as $key => $value) {
                // Export never writes a secret value: secret-looking literals are left out (the file doesn't manage
                // them, so export → plan stays empty); move them to the secret store to reference them.
                if (! isset($secret[$key]) || str_contains($value, '${{')) {
                    $unit['env'][(string) $key] = $value;
                }
            }

            if ($unit['env'] === []) {
                unset($unit['env']);
            }
        }

        if ($environment !== null && $environment->exposed !== []) {
            $unit['expose'] = array_values($environment->exposed);
        }

        try {
            $hooks = array_filter(array_map(DeployScriptSections::meaningful(...), DeployScriptSections::split((string) $site->deploy_script)), fn (string $s) => $s !== '');
        } catch (InvalidArgumentException) {
            $hooks = [];
        }

        if ($hooks !== [] && ! $site->runtime->usesDocker()) {
            $unit['deploy'] = ['hooks' => $hooks];
        }

        if ($site->runtime === SiteRuntime::Compose) {
            $unit = [...$unit, ...$this->composeOf($site, $scope)];
        }

        return $unit;
    }

    /**
     * A compose site's files, profiles and services in the file's shape: public services (in their order) with their
     * port and health path, services replaced by a database with the unit of that database. A service split into its
     * own site is the dashboard's (not in the file).
     *
     * @return array<string, mixed>
     */
    private function composeOf(Site $site, ConfigScope $scope): array
    {
        $out = [];

        if (($site->compose_source ?? ComposeSource::Repo) === ComposeSource::Repo && $site->composeFiles() !== []) {
            $out['files'] = $site->composeFiles();
        }

        if (($profiles = array_values(array_map('strval', (array) $site->compose_profiles))) !== []) {
            $out['profiles'] = $profiles;
        }

        foreach (array_filter((array) $site->public_services, 'is_array') as $public) {
            $out['services'][(string) $public['service']] = array_filter(['port' => (int) $public['port'], 'health_path' => $public['health_check_path'] ?? null], fn ($v) => $v !== null);
        }

        foreach ((array) $site->compose_services as $service => $decision) {
            if (is_array($decision) && ($decision['mode'] ?? null) === ComposeConfig::MODE_DATABASE && ($unit = $scope->unitOf(strtolower((string) ($decision['database_id'] ?? '')))) !== null) {
                $out['services'][(string) $service]['replace_with'] = $unit;
            }
        }

        return $out;
    }

    public function diff(ConfigScope $scope, array $desired, array $current): ChangeSet
    {
        $changes = [];
        $first = $this->sections()[0];

        foreach ((array) ($desired['units'] ?? []) as $unit => $body) {
            $unit = (string) $unit;
            $base = '/units/'.Pointer::escape($unit);
            $siteId = ($scope->services[$unit]['kind'] ?? null) === 'site' ? $scope->refId($unit) : null;

            if ($siteId === null) {
                $changes[] = new Change(ChangeOp::Create, $base, null, $body, $first, $unit);

                continue;
            }

            $today = (array) ($current['units'][$unit] ?? []);
            $secretKeys = [];

            if (isset($body['env'])) {
                // current() redacts secret-looking values; compare the file with what is stored.
                $variables = array_map('strval', (array) (EnvironmentVersion::query()->where('site_id', $siteId)->orderByDesc('version')->first()?->variables ?? []));
                $today['env'] = $variables;
                $secretKeys = array_flip($this->secrets->names($variables));
            }

            $wanted = self::normalize($body);
            $have = self::comparable($today, $wanted);

            foreach (Diff::leaves($wanted) as $path => $value) {
                $before = $have[$path] ?? null;
                $op = Diff::same($value, $before) ? ChangeOp::Noop : ($before === null ? ChangeOp::Create : ChangeOp::Update);
                $key = self::envKey($path);

                if ($key !== null && $before !== null && (isset($secretKeys[$key]) || str_contains((string) $value, '${{ secrets.'))) {
                    $before = self::REDACTED; // never a secret value in a plan
                }

                $changes[] = new Change($op, $base.$path, $before, $value, self::sectionOf($path), $unit);
            }
        }

        foreach ((array) ($current['units'] ?? []) as $unit => $body) {
            $unit = (string) $unit;

            if (array_key_exists($unit, (array) ($desired['units'] ?? [])) || ($siteId = $scope->refId($unit)) === null || ! $this->managed->managed('site', $siteId)->any()) {
                continue;
            }

            // Deleting a site needs the run's allow_destroy (config.destroy); otherwise the site is only detached.
            $op = $scope->allowDestroy && $this->managed->createdByFile('site', $siteId, $scope->environmentId) ? ChangeOp::Delete : ChangeOp::Detach;
            $changes[] = new Change($op, '/units/'.Pointer::escape($unit), ['type' => $body['type'] ?? 'app', 'runtime' => $body['runtime'] ?? null], null, $first, $unit);
        }

        return new ChangeSet($changes);
    }

    public function apply(ConfigScope $scope, ChangeSet $changes, ApplyContext $ctx): void
    {
        $byUnit = [];

        foreach ($changes->changes as $change) {
            if ($change->unit !== null) {
                $byUnit[$change->unit][] = $change;
            }
        }

        foreach ($byUnit as $unit => $list) {
            $unit = (string) $unit;
            $whole = collect($list)->first(fn (Change $c) => $c->path === '/units/'.Pointer::escape($unit));

            if ($whole?->op === ChangeOp::Create) {
                $this->create($scope, $ctx, $unit, (array) $whole->after);

                continue;
            }

            $siteId = $scope->refId($unit);

            if ($siteId === null) {
                continue;
            }

            if ($whole?->op === ChangeOp::Delete && $ctx->allowDestroy && $this->managed->createdByFile('site', $siteId, $scope->environmentId)) {
                app(SiteFactory::class)->delete($siteId, actorId: $ctx->actorId);
                $this->managed->forget('site', $siteId, $scope->environmentId);

                continue;
            }

            if ($whole?->op === ChangeOp::Delete || $whole?->op === ChangeOp::Detach) {
                // Never deleted without allow_destroy and a site the file created: detached, listed as unmanaged.
                $name = (string) (Site::query()->whereKey($siteId)->value('name') ?? $unit);
                $this->managed->detach($scope, $ctx, $unit, 'site', $siteId, "Site {$name}", route('sites.destroy', ['site' => $siteId], false), $siteId, ['name' => $name]);

                continue;
            }

            $site = Site::query()->with('targets')->findOrFail($siteId);
            $desired = [];

            foreach ($list as $change) {
                $desired[(string) Pointer::relativeToUnit($change->path)] = $change->after;
            }

            $this->update($site, $scope, $ctx, array_filter($list, fn (Change $c) => $c->op !== ChangeOp::Noop), $desired);
            $this->followBranch($site, $scope);
            $paths = array_keys($desired);
            $this->managed->record($scope, $ctx, $unit, 'site', $siteId, $paths, merge: true);
            $this->managed->forgetPaths('site', $siteId, [...self::prefixes(self::typeOf($site->runtime)), ...$this->servicePaths($siteId)], $paths);
        }
    }

    /**
     * **[decision]** A unit deploying the source's repository follows its environment's `branch`: an environment the
     * file moves to another branch takes its sites along (push to deploy and the dashboard see the new branch).
     */
    private function followBranch(Site $site, ConfigScope $scope): void
    {
        if ($scope->preview || $scope->branch === null || $scope->repository === null || $site->branch === $scope->branch
            || strcasecmp((string) $site->repository, $scope->repository) !== 0 || $site->source_connection_id !== $scope->connectionId) {
            return;
        }

        app(UpdateSite::class)($site->refresh(), ['branch' => $scope->branch]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function create(ConfigScope $scope, ApplyContext $ctx, string $unit, array $body): void
    {
        $body = self::normalize($body);
        $type = (string) ($body['type'] ?? 'app');
        $runtime = match ($type) {
            'compose' => SiteRuntime::Compose,
            'function' => SiteRuntime::Function,
            default => SiteRuntime::from((string) $body['runtime']),
        };
        $framework = isset($body['framework']) ? Framework::from((string) $body['framework']) : self::frameworkFor($runtime);
        $serverIds = $this->serverIds($scope, $body);

        if ($serverIds === []) {
            throw ValidationException::withMessages(["units.{$unit}.servers" => "Unit {$unit} has no servers: set `servers` on it or on the environment."]);
        }

        // [decision] A function's code comes from the repository through Functions (a version per code change): its
        // site deploys versions, not the repository.
        $fromRepository = $runtime !== SiteRuntime::Function;

        if ($fromRepository && $scope->repository !== null && $scope->branch === null) {
            throw ValidationException::withMessages(["units.{$unit}" => "Unit {$unit} can't be created: the environment {$scope->environment} has no `branch` to deploy."]);
        }

        $data = array_filter([
            'name' => $this->siteName($scope, $unit),
            'framework' => $framework->value,
            'runtime' => $runtime->value,
            'build_mode' => $body['build']['mode'] ?? null,
            'php_version' => $body['php'] ?? null,
            'node_version' => $body['node'] ?? null,
            'root_directory' => ($body['root'] ?? '.') === '.' ? null : $body['root'],
            'web_directory' => $body['web_directory'] ?? null,
            'container_port' => $body['port'] ?? null,
            'docker_image' => $body['image'] ?? null,
            'dockerfile' => $body['build']['dockerfile'] ?? null,
            'health_check_path' => $body['health_path'] ?? null,
            'test_domain_enabled' => $body['test_domain'] ?? null,
            'server_ids' => $serverIds,
            'leader_server_id' => $serverIds[0],
            'source_connection_id' => $fromRepository ? $scope->connectionId : null,
            'repository' => $fromRepository ? $scope->repository : null,
            'branch' => $fromRepository ? $scope->branch : null,
            // A push deploys it (through Config while the file manages it); a preview's units are deployed by Previews,
            // under their own Linux user, like the preview's copies.
            'push_to_deploy' => ! $scope->preview,
            'isolated' => $scope->preview ? true : null,
            'variables' => isset($body['env']) ? array_map('strval', $body['env']) : null,
            ...($runtime === SiteRuntime::Compose ? $this->composeInput($scope, $body) : []),
        ], fn ($v) => $v !== null);

        $created = app(SiteFactory::class)->create($scope->organizationId, $ctx->actorId, $data, new SitePlacement($scope->projectId, $scope->environmentId, null, null, $unit));
        $site = Site::query()->with('targets')->findOrFail($created->site->id);

        $rest = array_intersect_key(Diff::leaves($body), array_flip(array_filter(array_keys(Diff::leaves($body)), fn (string $p) => str_starts_with($p, '/deploy/hooks/') || $p === '/expose')));

        if ($rest !== []) {
            $this->update($site, $scope, $ctx, [], $rest);
        }

        $this->managed->record($scope, $ctx, $unit, 'site', $site->id, array_keys(Diff::leaves($body)), createdByFile: true, merge: true);
    }

    /**
     * Apply the fields of a unit to its site through Sites' actions.
     *
     * @param  array<int, Change>  $changes  the actionable changes (empty: apply $desired as given)
     * @param  array<string, mixed>  $desired  pointer relative to the unit => value
     */
    private function update(Site $site, ConfigScope $scope, ApplyContext $ctx, array $changes, array $desired): void
    {
        $paths = $changes === [] ? array_keys($desired) : array_map(fn (Change $c) => (string) Pointer::relativeToUnit($c->path), $changes);
        $value = fn (string $path) => $desired[$path] ?? null;
        $attributes = [];
        $map = [
            '/runtime' => 'runtime', '/php' => 'php_version', '/node' => 'node_version', '/web_directory' => 'web_directory', '/port' => 'container_port',
            '/image' => 'docker_image', '/health_path' => 'health_check_path', '/test_domain' => 'test_domain_enabled', '/build/mode' => 'build_mode',
            '/build/dockerfile' => 'dockerfile', '/root' => 'root_directory',
        ];
        $env = [];
        $hooks = [];

        foreach ($paths as $path) {
            if (isset($map[$path])) {
                $attributes[$map[$path]] = $path === '/root' && $value($path) === '.' ? null : $value($path);
            } elseif ($path === '/framework') {
                $framework = Framework::from((string) $value($path));
                app(SiteRules::class)->runtimeAndFramework($framework, isset($attributes['runtime']) ? SiteRuntime::from((string) $attributes['runtime']) : $site->runtime, $site->build_mode);
                $site->forceFill(['framework' => $framework])->save();
            } elseif (($key = self::envKey($path)) !== null) {
                $env[$key] = (string) $value($path);
            } elseif (str_starts_with($path, '/deploy/hooks/')) {
                $hooks[substr($path, 14)] = (string) $value($path);
            }
        }

        if ($attributes !== []) {
            app(UpdateSite::class)($site, $attributes);
            $site->refresh();
        }

        if (in_array('/servers', $paths, true)) {
            $ids = $this->serverIds($scope, ['servers' => $value('/servers')]);
            app(SetSiteTargets::class)($site, $ids, $ids[0]);
            $site->refresh();
        }

        if ($hooks !== []) {
            $sections = DeployScriptSections::split((string) $site->deploy_script);

            foreach ($hooks as $section => $text) {
                $sections[$section] = $text;
            }

            app(UpdateDeployScript::class)($site, DeployScriptSections::compose($sections));
        }

        if ($env !== [] || in_array('/expose', $paths, true)) {
            $current = EnvironmentVersion::query()->where('site_id', $site->id)->orderByDesc('version')->first();
            $variables = [...($current !== null ? array_map('strval', $current->variables) : []), ...$env];
            $exposed = in_array('/expose', $paths, true) ? (array) $value('/expose') : ($current->exposed ?? []);
            app(SaveEnvironment::class)($site, $variables, $exposed, $ctx->actorId, 'site.environment_updated');
        }

        $compose = array_filter($paths, fn (string $p) => in_array(Pointer::tokens($p)[0] ?? '', ['files', 'profiles', 'services'], true));

        if ($compose !== [] && $site->runtime === SiteRuntime::Compose) {
            app(UpdateComposeSettings::class)($site->refresh(), $this->composeUpdate($site, $scope, $desired, $compose), $ctx->actorId);
        }
    }

    /**
     * Compose settings of a new compose unit (SiteFactory's input): its files (else the default compose file), profiles,
     * public services in the file's order and the services a database unit replaces.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function composeInput(ConfigScope $scope, array $body): array
    {
        $public = [];
        $replaced = [];

        foreach ((array) ($body['services'] ?? []) as $service => $settings) {
            if (isset($settings['port'])) {
                $public[] = array_filter(['service' => (string) $service, 'port' => (int) $settings['port'], 'domain' => null, 'health_check_path' => $settings['health_path'] ?? null], fn ($v) => $v !== null);
            }

            if (isset($settings['replace_with'])) {
                $replaced[(string) $service] = $this->replacement($scope, (string) $settings['replace_with']);
            }
        }

        return array_filter([
            'compose_source' => ComposeSource::Repo->value,
            // Always present (empty: the default compose file), so CreateSite reads and checks the repository's project.
            'compose_files' => array_values((array) ($body['files'] ?? [])),
            'compose_profiles' => isset($body['profiles']) ? array_values($body['profiles']) : null,
            'public_services' => $public,
            'compose_services' => $replaced ?: null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Settings → Compose input for the compose fields that changed: public services keep their order and domains
     * (new ones are added last), only the fields the file sets change.
     *
     * @param  array<string, mixed>  $desired  pointer relative to the unit => value
     * @param  array<int, string>  $paths  the compose paths that changed
     * @return array<string, mixed>
     */
    private function composeUpdate(Site $site, ConfigScope $scope, array $desired, array $paths): array
    {
        $data = [];

        if (array_filter($paths, fn (string $p) => $p === '/files') !== []) {
            $data['compose_source'] = ComposeSource::Repo->value;
            $data['compose_files'] = array_values((array) $desired['/files']);
        }

        if (in_array('/profiles', $paths, true)) {
            $data['compose_profiles'] = array_values((array) $desired['/profiles']);
        }

        $public = array_values(array_filter((array) $site->public_services, 'is_array'));
        $published = false;

        foreach ($paths as $path) {
            $tokens = Pointer::tokens($path);

            if (count($tokens) !== 3 || $tokens[0] !== 'services') {
                continue;
            }

            [, $service, $field] = $tokens;

            if ($field === 'replace_with') {
                $data['compose_services'][$service] = $this->replacement($scope, (string) $desired[$path]);

                continue;
            }

            $published = true;
            $index = collect($public)->search(fn (array $p) => (string) $p['service'] === $service);

            if ($index === false) {
                $public[] = ['service' => $service, 'port' => (int) ($desired['/services/'.Pointer::escape($service).'/port'] ?? 0), 'domain' => null];
                $index = count($public) - 1;
            }

            if ($field === 'port') {
                $public[$index]['port'] = (int) $desired[$path];
            } else {
                $public[$index]['health_check_path'] = (string) $desired[$path];
            }
        }

        if ($published) {
            $data['public_services'] = array_map(fn (array $p) => array_intersect_key($p, array_flip(['service', 'port', 'domain', 'health_check_path'])), $public);
        }

        return $data;
    }

    /**
     * A compose service replaced by a database unit: the instance behind the unit (created in phase A before Sites).
     *
     * @return array{mode: string, database_id: string, engine: string}
     */
    private function replacement(ConfigScope $scope, string $unit): array
    {
        $databaseId = ($scope->services[$unit]['kind'] ?? null) === 'database' ? $scope->refId($unit) : null;
        $database = $databaseId !== null ? $this->databases->find($databaseId) : null;

        if ($database === null) {
            throw ValidationException::withMessages(["units.{$unit}" => "The database unit {$unit} doesn't exist in this environment yet."]);
        }

        return ['mode' => ComposeConfig::MODE_DATABASE, 'database_id' => $database->id, 'engine' => $database->engine];
    }

    /**
     * Ownership paths of the compose services' fields this reconciler records (limits are Limits').
     *
     * @return list<string>
     */
    private function servicePaths(string $siteId): array
    {
        return array_values(array_filter($this->managed->managed('site', $siteId)->paths, fn (string $p) => preg_match('#^/services/[^/]+/(port|health_path|replace_with)$#', $p) === 1));
    }

    public function validate(ConfigScope $scope, array $resolved): array
    {
        $issues = [];

        foreach ((array) ($resolved['units'] ?? []) as $unit => $body) {
            $base = '/units/'.Pointer::escape((string) $unit);
            $type = (string) ($body['type'] ?? '');
            $siteId = ($scope->services[(string) $unit]['kind'] ?? null) === 'site' ? $scope->refId((string) $unit) : null;
            $site = $siteId !== null && in_array($type, ['app', 'compose', 'function'], true) ? Site::query()->find($siteId) : null;

            if ($site !== null && self::typeOf($site->runtime) !== $type) {
                $issues[] = ConfigIssue::error('value.conflict', "{$base}/type", "The unit {$unit} is a".(self::typeOf($site->runtime) === 'app' ? 'n app' : ' '.self::typeOf($site->runtime))." unit: its type can't change (add a unit with another key instead).");

                continue;
            }

            if ($type === 'compose') {
                array_push($issues, ...$this->validateCompose($scope, $resolved, (string) $unit, $body, $site));

                continue;
            }

            if ($type !== 'app') {
                continue;
            }

            $runtime = SiteRuntime::tryFrom((string) ($body['runtime'] ?? ''));

            if (isset($body['php']) && ! in_array($body['php'], (array) config('sites.php_versions'), true)) {
                $issues[] = ConfigIssue::error('value.unsupported', "{$base}/php", "PHP {$body['php']} is not available; use one of ".implode(', ', (array) config('sites.php_versions')).'.');
            }

            if (isset($body['node']) && ! in_array($body['node'], (array) config('sites.node_versions'), true)) {
                $issues[] = ConfigIssue::error('value.unsupported', "{$base}/node", "Node.js {$body['node']} is not available; use one of ".implode(', ', (array) config('sites.node_versions')).'.');
            }

            if ($runtime !== null && $runtime->isPhp() && ! isset($body['php']) && $scope->refId((string) $unit) === null) {
                $issues[] = ConfigIssue::error('value.unsupported', $base, 'A PHP unit needs `php` (its PHP version).');
            }

            if (isset($body['laravel']) && $runtime !== null && ! $runtime->isPhp()) {
                $issues[] = ConfigIssue::error('value.unsupported', "{$base}/laravel", '`laravel` is only for PHP runtimes (frankenphp, php-fpm).');
            }

            foreach (['port', 'image'] as $field) {
                if (isset($body[$field]) && $runtime !== SiteRuntime::Docker) {
                    $issues[] = ConfigIssue::error('value.unsupported', "{$base}/{$field}", "`{$field}` is only for the docker runtime.");
                }
            }

            if (isset($body['framework'], $body['runtime']) && $runtime !== null && ($framework = Framework::tryFrom((string) $body['framework'])) !== null
                && $framework->isPhp() !== $runtime->isPhp() && $framework !== Framework::Static && $runtime !== SiteRuntime::Docker) {
                $issues[] = ConfigIssue::error('value.conflict', "{$base}/runtime", "{$framework->label()} units can't use the {$runtime->label()} runtime.");
            }

            if (isset($body['build']['mode']) && $runtime !== null && ($mode = BuildMode::tryFrom((string) $body['build']['mode'])) !== null && ! in_array($mode, $runtime->buildModes(), true)) {
                $issues[] = ConfigIssue::error('value.unsupported', "{$base}/build/mode", "{$runtime->label()} units can't use {$mode->label()} builds.");
            }

            if (isset($body['deploy']['hooks']) && $runtime !== null && $runtime->usesDocker()) {
                $issues[] = ConfigIssue::error('value.unsupported', "{$base}/deploy/hooks", 'Deploy hooks run on host runtimes only (not docker).');
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $resolved
     * @param  array<string, mixed>  $body
     * @return list<ConfigIssue>
     */
    private function validateCompose(ConfigScope $scope, array $resolved, string $unit, array $body, ?Site $site): array
    {
        $issues = [];
        $base = '/units/'.Pointer::escape($unit);
        $public = array_column(array_filter((array) $site?->public_services, 'is_array'), 'service');

        if (isset($body['deploy']['hooks'])) {
            $issues[] = ConfigIssue::error('value.unsupported', "{$base}/deploy/hooks", 'Deploy hooks run on host runtimes only (not compose).');
        }

        foreach ((array) ($body['services'] ?? []) as $service => $settings) {
            $at = "{$base}/services/".Pointer::escape((string) $service);
            $isPublic = isset($settings['port']) || in_array((string) $service, $public, true);

            foreach (['health_path', 'domains', 'redirects', 'headers'] as $field) {
                if (isset($settings[$field]) && ! $isPublic) {
                    $issues[] = ConfigIssue::error('value.conflict', "{$at}/{$field}", "`{$field}` needs the service to be public: set its `port`.");
                }
            }

            if (! isset($settings['replace_with'])) {
                continue;
            }

            $target = (string) $settings['replace_with'];
            $isDatabase = in_array($resolved['units'][$target]['type'] ?? null, ['database', 'redis'], true) || ($scope->services[$target]['kind'] ?? null) === 'database';
            $decision = (array) ($site?->compose_services[(string) $service] ?? []);

            if (! $isDatabase) {
                $issues[] = ConfigIssue::error('name.unit', "{$at}/replace_with", "No database or redis unit named \"{$target}\".");
            } elseif ($isPublic) {
                $issues[] = ConfigIssue::error('value.conflict', "{$at}/replace_with", 'A service replaced by a database is not public: remove its `port`.');
            } elseif (($decision['mode'] ?? null) === ComposeConfig::MODE_SITE) {
                $issues[] = ConfigIssue::error('value.conflict', "{$at}/replace_with", "{$service} runs as its own site (a dashboard choice): put it back in the stack there first.");
            } elseif (($decision['mode'] ?? null) === ComposeConfig::MODE_DATABASE && ($current = $scope->unitOf(strtolower((string) ($decision['database_id'] ?? '')))) !== $target) {
                $issues[] = ConfigIssue::error('value.conflict', "{$at}/replace_with", "{$service} is already replaced by ".($current !== null ? "the unit {$current}" : 'another database').': put it back in the stack in the dashboard first.');
            }
        }

        return $issues;
    }

    private static function typeOf(SiteRuntime $runtime): string
    {
        return match ($runtime) {
            SiteRuntime::Compose => 'compose',
            SiteRuntime::Function => 'function',
            default => 'app',
        };
    }

    /**
     * File values as stored: env integers and booleans as text, `root` "." kept (the root).
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private static function normalize(array $body): array
    {
        $out = array_intersect_key($body, array_flip([...array_filter(self::FIELDS, fn (string $f) => ! str_contains($f, '.')), 'files', 'profiles', 'services']));

        if (isset($out['services']) && is_array($out['services'])) {
            $out['services'] = array_filter(array_map(fn ($s) => array_intersect_key((array) $s, array_flip(['port', 'health_path', 'replace_with'])), $out['services']));
        }

        if (isset($out['env']) && is_array($out['env'])) {
            foreach ($out['env'] as $key => $value) {
                $out['env'][$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            }
        }

        if (isset($body['deploy']['hooks']) && is_array($body['deploy']['hooks'])) {
            $out['deploy'] = ['hooks' => $body['deploy']['hooks']];
        }

        return $out;
    }

    /**
     * Today's leaves, comparable with the file's: root absent means "."; hooks compared without blank/comment-only text.
     *
     * @param  array<string, mixed>  $today
     * @param  array<string, mixed>  $wanted
     * @return array<string, mixed>
     */
    private static function comparable(array $today, array $wanted): array
    {
        $today['root'] ??= '.';
        $leaves = Diff::leaves($today);

        foreach (Diff::leaves($wanted) as $path => $value) {
            if (str_starts_with($path, '/deploy/hooks/') && is_string($value)) {
                $leaves[$path] ??= null;

                if ($leaves[$path] !== null && DeployScriptSections::meaningful((string) $leaves[$path]) === DeployScriptSections::meaningful($value)) {
                    $leaves[$path] = $value;
                }
            }

            if ($path === '/servers' && is_array($value) && is_array($leaves[$path] ?? null)) {
                // Same leader and the same set: equal (members have no order).
                $have = $leaves[$path];
                $same = ($have[0] ?? null) === ($value[0] ?? null) && array_diff($have, $value) === [] && array_diff($value, $have) === [];
                $leaves[$path] = $same ? $value : $have;
            }
        }

        return $leaves;
    }

    private static function sectionOf(string $path): string
    {
        $tokens = Pointer::tokens($path);
        $first = $tokens[0] ?? '';

        return self::section(match ($first) {
            'deploy' => 'deploy.hooks',
            'services' => 'services.*.'.($tokens[2] ?? 'port'),
            default => $first,
        });
    }

    private static function envKey(string $path): ?string
    {
        $tokens = Pointer::tokens($path);

        return count($tokens) === 2 && $tokens[0] === 'env' ? $tokens[1] : null;
    }

    /**
     * Ownership prefixes this reconciler records under for a unit type (compose services' fields aside).
     *
     * @return list<string>
     */
    public static function prefixes(string $type = 'app'): array
    {
        $fields = array_filter([...self::FIELDS, 'files', 'profiles'], fn (string $f) => in_array($type, explode('|', self::TYPES[$f] ?? 'app'), true));

        return array_values(array_map(fn (string $f) => '/'.str_replace('.', '/', $f), $fields));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<string>
     */
    private function serverIds(ConfigScope $scope, array $body): array
    {
        if (isset($body['servers']) && is_array($body['servers'])) {
            return array_values(array_filter(array_map(fn ($name) => $scope->serverId((string) $name), $body['servers'])));
        }

        return $scope->defaultServers;
    }

    private function siteName(ConfigScope $scope, string $unit): string
    {
        $taken = fn (string $name) => Site::query()->where('organization_id', $scope->organizationId)->where('name', $name)->exists();

        if (! $taken($unit)) {
            return $unit;
        }

        $name = "{$unit}-{$scope->environment}";

        for ($i = 2; $taken($name); $i++) {
            $name = "{$unit}-{$scope->environment}-{$i}";
        }

        return $name;
    }

    private static function frameworkFor(SiteRuntime $runtime): Framework
    {
        return match ($runtime) {
            SiteRuntime::FrankenPhp, SiteRuntime::PhpFpm => Framework::Php,
            SiteRuntime::Static => Framework::Static,
            SiteRuntime::Docker, SiteRuntime::Compose, SiteRuntime::Function => Framework::Docker,
            default => Framework::Node,
        };
    }
}
