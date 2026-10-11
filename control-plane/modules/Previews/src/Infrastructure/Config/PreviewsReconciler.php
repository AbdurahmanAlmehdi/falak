<?php

namespace Falak\Previews\Infrastructure\Config;

use Falak\Config\Contracts\ApplyContext;
use Falak\Config\Contracts\ApplyPhase;
use Falak\Config\Contracts\Change;
use Falak\Config\Contracts\ChangeOp;
use Falak\Config\Contracts\ChangeSet;
use Falak\Config\Contracts\ConfigIssue;
use Falak\Config\Contracts\ConfigScope;
use Falak\Config\Contracts\Diff;
use Falak\Config\Contracts\ManagedResources;
use Falak\Config\Contracts\Reconcilable;
use Falak\Config\Contracts\Units;
use Falak\Config\Contracts\ValidatesConfig;
use Falak\Previews\Application\Actions\SavePreviewSettings;
use Falak\Previews\Domain\Models\PreviewSettings;
use Falak\Projects\Contracts\Data\EnvironmentData;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Servers\Contracts\ServerDirectory;

/**
 * falak.yaml's `previews` (phase A): the project's preview settings ({@see PreviewSettings} is the stored copy of the
 * block). **[decision]** One file, several environments: the block is applied by the run of its `base` environment
 * (the production environment when it names none), and planned as nothing in the others. Fields the file doesn't set
 * keep today's value; a block removed from the file only stops being managed (previews are not turned off).
 *
 * A database unit cloned from production's backups (unsanitized, or with a script the file supplies) puts production
 * data in previews: such a change is risky (`previews.production_data`) and waits for an approval.
 */
final class PreviewsReconciler implements Reconcilable, ValidatesConfig
{
    public const SECTION = 'previews';

    public const RULE = 'previews.production_data';

    public function __construct(
        private readonly ProjectDirectory $projects,
        private readonly ServerDirectory $servers,
        private readonly ManagedResources $managed,
    ) {}

    public function sections(): array
    {
        return [self::SECTION];
    }

    public function phase(): ApplyPhase
    {
        return ApplyPhase::BeforeBuild;
    }

    public function current(ConfigScope $scope): array
    {
        $settings = PreviewSettings::query()->where('project_id', $scope->projectId)->first();

        return $settings === null ? [] : ['previews' => $this->shape($scope, $settings)];
    }

    /**
     * Stored settings in the file's shape.
     *
     * @return array<string, mixed>
     */
    private function shape(ConfigScope $scope, PreviewSettings $settings): array
    {
        $units = [];

        foreach ((array) $settings->services as $unit => $mode) {
            $units[(string) $unit] = (string) $mode;
        }

        foreach ((array) $settings->databases as $unit => $database) {
            if (($units[(string) $unit] ?? PreviewSettings::INCLUDE) !== PreviewSettings::INCLUDE) {
                continue;
            }

            $units[(string) $unit] = array_filter([
                'strategy' => (string) ($database['strategy'] ?? PreviewSettings::EMPTY),
                'source' => isset($database['source_environment_id']) ? $this->slug($scope, (string) $database['source_environment_id']) : null,
                'sanitize' => isset($database['sanitize_kind'], $database['sanitize_script']) && ($database['strategy'] ?? null) === PreviewSettings::CLONE_SANITIZE
                    ? [(string) $database['sanitize_kind'] => (string) $database['sanitize_script']] : null,
            ], fn ($v) => $v !== null);
        }

        return array_filter([
            'enabled' => $settings->enabled,
            'base' => $settings->base_environment_id !== null ? $this->slug($scope, $settings->base_environment_id) : null,
            'units' => $units !== [] ? $units : null,
            'server' => $settings->server_id !== null ? $this->serverName($scope, $settings->server_id) : null,
            'fork_server' => $settings->fork_server_id !== null ? $this->serverName($scope, $settings->fork_server_id) : null,
            'variables' => ($settings->variables ?? []) !== [] ? array_values($settings->variables) : null,
            'domain_pattern' => $settings->domain_pattern,
            'max_concurrent' => $settings->max_concurrent,
            'idle_ttl' => Units::duration($settings->idle_ttl_hours * 3600),
            'access' => $settings->access,
            'acknowledge_shared_database' => $settings->acknowledge_shared_database,
        ], fn ($v) => $v !== null);
    }

    /** The run that applies the block: its base environment's (production's when it names none). */
    private function owns(ConfigScope $scope, ?array $previews): bool
    {
        $base = $previews['base'] ?? null;

        return $base !== null ? $base === $scope->environment : $scope->production;
    }

    public function diff(ConfigScope $scope, array $desired, array $current): ChangeSet
    {
        $want = $desired['previews'] ?? null;
        $have = $current['previews'] ?? null;

        if (! is_array($want)) {
            // Gone from the file: no longer managed, never turned off.
            $settings = PreviewSettings::query()->where('project_id', $scope->projectId)->first();

            return $settings !== null && is_array($have) && $this->owns($scope, $have) && $this->managed->managed('preview_settings', $settings->id)->any()
                ? ChangeSet::of(new Change(ChangeOp::Detach, '/previews', $have, null, self::SECTION))
                : new ChangeSet;
        }

        if (! $this->owns($scope, $want)) {
            return new ChangeSet;
        }

        $changes = [];
        $wanted = self::leaves($want);
        $before = is_array($have) ? self::leaves($have) : [];
        $production = collect($this->projects->environments($scope->projectId))->first(fn (EnvironmentData $e) => $e->isProduction && ! $e->isPreview)?->slug;

        foreach (Diff::fields(self::SECTION, '', '/previews', $wanted, $before, ! is_array($have)) as $change) {
            $change = new Change($change->op, $change->path, $change->before, $change->after, self::SECTION);
            $unit = preg_match('#^/previews/units/([^/]+)#', $change->path, $m) === 1 ? $m[1] : null;
            $database = $unit !== null ? ($want['units'][$unit] ?? null) : null;

            // Production's data in previews needs an approval (the dashboard asks for an acknowledgement).
            if ($change->op !== ChangeOp::Noop && is_array($database) && in_array($database['strategy'] ?? null, [PreviewSettings::CLONE_BACKUP, PreviewSettings::CLONE_SANITIZE], true)
                && ($database['source'] ?? ($database['strategy'] === PreviewSettings::CLONE_SANITIZE ? $production : ($want['base'] ?? $production))) === $production) {
                $change = $change->withRisk(self::RULE, "Previews would hold a copy of production's {$unit}: cloning production data needs an approval.");
            }

            $changes[] = $change;
        }

        return new ChangeSet($changes);
    }

    public function apply(ConfigScope $scope, ChangeSet $changes, ApplyContext $ctx): void
    {
        $settings = PreviewSettings::query()->where('project_id', $scope->projectId)->first();

        foreach ($changes->changes as $change) {
            if ($change->path === '/previews' && $change->op === ChangeOp::Detach) {
                if ($settings !== null) {
                    $this->managed->forget('preview_settings', $settings->id);
                }

                return;
            }
        }

        $previews = [];

        foreach ($changes->changes as $change) {
            self::set($previews, substr($change->path, strlen('/previews')), $change->after);
        }

        $current = $settings !== null ? $this->shape($scope, $settings) : [];
        $input = $this->input($scope, [...$current, ...$previews], $settings);

        if (array_filter($changes->changes, fn (Change $c) => $c->op !== ChangeOp::Noop) !== []) {
            app(SavePreviewSettings::class)($scope->projectId, $input, $ctx->actorId);
            $settings = PreviewSettings::query()->where('project_id', $scope->projectId)->firstOrFail();
        }

        if ($settings !== null) {
            $paths = array_map(fn (Change $c) => $c->path, $changes->changes);
            $this->managed->record($scope, $ctx, null, 'preview_settings', $settings->id, $paths);
        }
    }

    /**
     * SavePreviewSettings' input for the block (merged over today's settings).
     *
     * @param  array<string, mixed>  $previews
     * @return array<string, mixed>
     */
    private function input(ConfigScope $scope, array $previews, ?PreviewSettings $settings): array
    {
        $services = [];
        $databases = [];

        foreach ((array) ($previews['units'] ?? []) as $unit => $mode) {
            if (is_string($mode)) {
                $services[$unit] = $mode;

                continue;
            }

            $services[$unit] = PreviewSettings::INCLUDE;
            $sanitize = (array) ($mode['sanitize'] ?? []);
            $databases[$unit] = array_filter([
                'strategy' => (string) $mode['strategy'],
                'source_environment_id' => isset($mode['source']) ? $this->environmentId($scope, (string) $mode['source']) : null,
                'sanitize_kind' => $sanitize !== [] ? (string) array_key_first($sanitize) : null,
                'sanitize_script' => $sanitize !== [] ? (string) reset($sanitize) : null,
                // An approved plan (production data is risky) is the acknowledgement the dashboard asks for.
                'acknowledge_production' => true,
            ], fn ($v) => $v !== null);
        }

        return [
            'enabled' => (bool) ($previews['enabled'] ?? true),
            'base_environment_id' => isset($previews['base']) ? $this->environmentId($scope, (string) $previews['base']) : ($settings->base_environment_id ?? $scope->environmentId),
            'services' => $services,
            'databases' => $databases,
            'server_id' => isset($previews['server']) ? $this->serverId($scope, (string) $previews['server'], $settings?->server_id) : null,
            'fork_server_id' => isset($previews['fork_server']) ? $this->serverId($scope, (string) $previews['fork_server'], $settings?->fork_server_id) : null,
            'variables' => array_values((array) ($previews['variables'] ?? [])),
            'domain_pattern' => (string) ($previews['domain_pattern'] ?? 'pr-{number}-{service}'),
            'max_concurrent' => (int) ($previews['max_concurrent'] ?? 5),
            'idle_ttl_hours' => intdiv((int) Units::seconds($previews['idle_ttl'] ?? '3d'), 3600),
            'access' => (string) ($previews['access'] ?? 'basic'),
            'acknowledge_shared_database' => (bool) ($previews['acknowledge_shared_database'] ?? false),
        ];
    }

    public function validate(ConfigScope $scope, array $resolved): array
    {
        $previews = $resolved['previews'] ?? null;

        if (! is_array($previews) || ! $this->owns($scope, $previews)) {
            return [];
        }

        $issues = [];
        $settings = PreviewSettings::query()->where('project_id', $scope->projectId)->first();

        if (isset($previews['base']) && $this->environmentId($scope, (string) $previews['base']) === null) {
            $issues[] = ConfigIssue::error('name.environment', '/previews/base', "The project has no environment \"{$previews['base']}\".");
        }

        foreach ((array) ($previews['units'] ?? []) as $unit => $mode) {
            $at = '/previews/units/'.$unit;

            if (! isset($resolved['units'][$unit]) && ! isset($scope->services[$unit])) {
                $issues[] = ConfigIssue::error('name.unit', $at, "No unit named \"{$unit}\".");
            }

            $type = $resolved['units'][$unit]['type'] ?? (($scope->services[$unit]['kind'] ?? null) === 'database' ? 'database' : null);

            if (is_array($mode) && $type !== null && $type !== 'database') {
                $issues[] = ConfigIssue::error('value.conflict', $at, 'A data strategy is for database units only.');
            }

            if (is_array($mode) && isset($mode['source']) && $this->environmentId($scope, (string) $mode['source']) === null) {
                $issues[] = ConfigIssue::error('name.environment', "{$at}/source", "The project has no environment \"{$mode['source']}\".");
            }

            if ($mode === PreviewSettings::SHARE && in_array($type, ['database', 'redis'], true) && ! ($previews['acknowledge_shared_database'] ?? false)) {
                $issues[] = ConfigIssue::error('value.conflict', $at, "Sharing {$unit} means previews read and write the base environment's database: set acknowledge_shared_database.");
            }
        }

        if (isset($previews['server']) && $scope->serverId((string) $previews['server']) === null) {
            $issues[] = ConfigIssue::error('name.server', '/previews/server', "No server named \"{$previews['server']}\" is attached to this project.");
        }

        // The fork server hosts nothing but previews: the one set in the dashboard, or one attached to the project.
        if (isset($previews['fork_server']) && $this->serverId($scope, (string) $previews['fork_server'], $settings?->fork_server_id) === null) {
            $issues[] = ConfigIssue::error('name.server', '/previews/fork_server', "No server named \"{$previews['fork_server']}\" is attached to this project or set as its fork server.");
        }

        if (isset($previews['idle_ttl']) && (($seconds = Units::seconds($previews['idle_ttl'])) === null || $seconds % 3600 !== 0 || $seconds < 3600 || $seconds > 720 * 3600)) {
            $issues[] = ConfigIssue::error('value.range', '/previews/idle_ttl', 'Must be whole hours, 1h to 30d.');
        }

        return $issues;
    }

    /**
     * The block's leaves as compared: `enabled` present ⇒ true, durations in their shortest form.
     *
     * @param  array<string, mixed>  $previews
     * @return array<string, mixed>
     */
    private static function leaves(array $previews): array
    {
        $previews['enabled'] ??= true;

        if (isset($previews['idle_ttl']) && ($seconds = Units::seconds($previews['idle_ttl'])) !== null) {
            $previews['idle_ttl'] = Units::duration($seconds);
        }

        return Diff::leaves($previews);
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private static function set(array &$target, string $pointer, mixed $value): void
    {
        $cur = &$target;
        $tokens = array_values(array_filter(explode('/', $pointer), fn ($t) => $t !== ''));

        foreach ($tokens as $i => $token) {
            $token = str_replace(['~1', '~0'], ['/', '~'], $token);

            if ($i === count($tokens) - 1) {
                $cur[$token] = $value;

                break;
            }

            if (! isset($cur[$token]) || ! is_array($cur[$token])) {
                $cur[$token] = [];
            }

            $cur = &$cur[$token];
        }
    }

    private function slug(ConfigScope $scope, string $environmentId): ?string
    {
        $environment = $this->projects->environment($environmentId);

        return $environment !== null && $environment->projectId === $scope->projectId ? $environment->slug : null;
    }

    private function environmentId(ConfigScope $scope, string $slug): ?string
    {
        if ($slug === $scope->environment) {
            return $scope->environmentId;
        }

        return collect($this->projects->environments($scope->projectId))->first(fn (EnvironmentData $e) => $e->slug === $slug && ! $e->isPreview)?->id;
    }

    private function serverName(ConfigScope $scope, string $serverId): ?string
    {
        return $scope->serverName($serverId) ?? $this->servers->find($serverId)?->name;
    }

    private function serverId(ConfigScope $scope, string $name, ?string $current): ?string
    {
        if (($id = $scope->serverId($name)) !== null) {
            return $id;
        }

        return $current !== null && $this->servers->find($current)?->name === $name ? $current : null;
    }
}
