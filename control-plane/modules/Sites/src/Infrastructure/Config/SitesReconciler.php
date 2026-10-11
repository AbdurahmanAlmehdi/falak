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
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Application\Actions\SaveEnvironment;
use Falak\Sites\Application\Actions\SetSiteTargets;
use Falak\Sites\Application\Actions\UpdateDeployScript;
use Falak\Sites\Application\Actions\UpdateSite;
use Falak\Sites\Application\SiteRules;
use Falak\Sites\Contracts\BuildMode;
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
 * falak.yaml's app units (phase A): the site itself (created from the file when it doesn't exist), its runtime,
 * versions, directories, build, Laravel processes, servers, env variables and deploy hooks.
 *
 * The file only manages what it sets: an absent field keeps today's value. Env keys merge into today's variables
 * (keys not in the file stay); each hook replaces only its section of today's deploy script.
 */
final class SitesReconciler implements Reconcilable, ValidatesConfig
{
    /**
     * Unit fields this reconciler owns, in phase 1 for app units only. `laravel` belongs to {@see SitesLaravelReconciler}
     * (phase B): its scheduler, Horizon and Octane change running processes, so they go live with the release.
     */
    public const FIELDS = ['type', 'root', 'runtime', 'framework', 'php', 'node', 'web_directory', 'port', 'image', 'health_path', 'test_domain', 'servers', 'build', 'env', 'expose', 'deploy.hooks'];

    /** Shown instead of a stored value that may be a secret. */
    public const REDACTED = '••••';

    public function __construct(
        private readonly ManagedResources $managed,
        private readonly ServerDirectory $servers,
        private readonly SecretVariables $secrets,
    ) {}

    public function sections(): array
    {
        return array_map(fn (string $field) => "units.*(app).{$field}", self::FIELDS);
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

        return $unit;
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
            $op = $scope->allowDestroy && $this->managed->createdByFile('site', $siteId) ? ChangeOp::Delete : ChangeOp::Detach;
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

            if ($whole?->op === ChangeOp::Delete && $ctx->allowDestroy) {
                app(SiteFactory::class)->delete($siteId, actorId: $ctx->actorId);
                $this->managed->forget('site', $siteId);

                continue;
            }

            if ($whole?->op === ChangeOp::Delete) {
                $this->managed->forget('site', $siteId); // never deleted without allow_destroy: detached

                continue;
            }

            if ($whole?->op === ChangeOp::Detach) {
                $this->managed->forget('site', $siteId);

                continue;
            }

            $site = Site::query()->with('targets')->findOrFail($siteId);
            $desired = [];

            foreach ($list as $change) {
                $desired[(string) Pointer::relativeToUnit($change->path)] = $change->after;
            }

            $this->update($site, $scope, $ctx, array_filter($list, fn (Change $c) => $c->op !== ChangeOp::Noop), $desired);
            $paths = array_keys($desired);
            $this->managed->record($scope, $ctx, $unit, 'site', $siteId, $paths, merge: true);
            $this->managed->forgetPaths('site', $siteId, self::prefixes(), $paths);
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function create(ConfigScope $scope, ApplyContext $ctx, string $unit, array $body): void
    {
        $body = self::normalize($body);
        $runtime = SiteRuntime::from((string) $body['runtime']);
        $framework = isset($body['framework']) ? Framework::from((string) $body['framework']) : self::frameworkFor($runtime);
        $serverIds = $this->serverIds($scope, $body);

        if ($serverIds === []) {
            throw ValidationException::withMessages(["units.{$unit}.servers" => "Unit {$unit} has no servers: set `servers` on it or on the environment."]);
        }

        if ($scope->repository !== null && $scope->branch === null) {
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
            'source_connection_id' => $scope->connectionId,
            'repository' => $scope->repository,
            'branch' => $scope->branch,
            'push_to_deploy' => true, // a push deploys it (through Config while the file manages it)
            'variables' => isset($body['env']) ? array_map('strval', $body['env']) : null,
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
    }

    public function validate(ConfigScope $scope, array $resolved): array
    {
        $issues = [];

        foreach ((array) ($resolved['units'] ?? []) as $unit => $body) {
            if (($body['type'] ?? null) !== 'app') {
                continue;
            }

            $base = '/units/'.Pointer::escape((string) $unit);
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
     * File values as stored: env integers and booleans as text, `root` "." kept (the root).
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private static function normalize(array $body): array
    {
        $out = array_intersect_key($body, array_flip(array_filter(self::FIELDS, fn (string $f) => ! str_contains($f, '.'))));

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
        $first = Pointer::tokens($path)[0] ?? '';

        return $first === 'deploy' ? 'units.*(app).deploy.hooks' : "units.*(app).{$first}";
    }

    private static function envKey(string $path): ?string
    {
        $tokens = Pointer::tokens($path);

        return count($tokens) === 2 && $tokens[0] === 'env' ? $tokens[1] : null;
    }

    /**
     * Ownership prefixes this reconciler records under.
     *
     * @return list<string>
     */
    public static function prefixes(): array
    {
        return array_map(fn (string $f) => '/'.str_replace('.', '/', $f), self::FIELDS);
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
            SiteRuntime::Docker => Framework::Docker,
            default => Framework::Node,
        };
    }
}
