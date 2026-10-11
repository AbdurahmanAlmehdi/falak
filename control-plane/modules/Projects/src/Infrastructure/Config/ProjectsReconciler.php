<?php

namespace Falak\Projects\Infrastructure\Config;

use Falak\Config\Contracts\ApplyContext;
use Falak\Config\Contracts\ApplyPhase;
use Falak\Config\Contracts\Change;
use Falak\Config\Contracts\ChangeOp;
use Falak\Config\Contracts\ChangeSet;
use Falak\Config\Contracts\ConfigIssue;
use Falak\Config\Contracts\ConfigScope;
use Falak\Config\Contracts\ManagedResources;
use Falak\Config\Contracts\Pointer;
use Falak\Config\Contracts\Reconcilable;
use Falak\Config\Contracts\ValidatesConfig;
use Falak\Projects\Contracts\ConfigEnvironments;
use Falak\Projects\Domain\Models\Environment;

/**
 * falak.yaml's `environments.<slug>` (phase A, first): the environment a run applies to. Config creates a declared
 * environment the project doesn't have before its run ({@see ConfigEnvironments::create()}); this reconciler manages
 * its `production` flag (always a risky change: rule `environment.production`). `branch` is read from the file only
 * and `servers` are the units' default servers: neither has a column. An environment is never deleted by the file.
 */
final class ProjectsReconciler implements Reconcilable, ValidatesConfig
{
    public const SECTION = 'environments';

    public function __construct(
        private readonly ConfigEnvironments $environments,
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
        return ['environments' => [$scope->environment => ['production' => $scope->production]]];
    }

    public function diff(ConfigScope $scope, array $desired, array $current): ChangeSet
    {
        $wanted = $desired['environments'][$scope->environment] ?? null;

        if (! is_array($wanted) || ! array_key_exists('production', $wanted)) {
            return new ChangeSet;
        }

        $before = (bool) ($current['environments'][$scope->environment]['production'] ?? $scope->production);
        $after = (bool) $wanted['production'];

        return ChangeSet::of(new Change($before === $after ? ChangeOp::Noop : ChangeOp::Update, self::pointer($scope->environment).'/production', $before, $after, self::SECTION));
    }

    public function apply(ConfigScope $scope, ChangeSet $changes, ApplyContext $ctx): void
    {
        foreach ($changes->changes as $change) {
            if ($change->path !== self::pointer($scope->environment).'/production') {
                continue;
            }

            if ($change->op === ChangeOp::Update) {
                $this->environments->setProduction($scope->environmentId, (bool) $change->after, $ctx->actorId);
            }

            $this->managed->record($scope, $ctx, null, 'environment', $scope->environmentId, ['/production']);
        }
    }

    public function validate(ConfigScope $scope, array $resolved): array
    {
        $issues = [];

        foreach (array_keys((array) ($resolved['environments'] ?? [])) as $slug) {
            if (in_array((string) $slug, Environment::RESERVED_SLUGS, true)) {
                $issues[] = ConfigIssue::error('value.forbidden', self::pointer((string) $slug), "\"{$slug}\" is reserved: it can't be an environment's name.");
            }
        }

        return $issues;
    }

    private static function pointer(string $slug): string
    {
        return '/environments/'.Pointer::escape($slug);
    }
}
