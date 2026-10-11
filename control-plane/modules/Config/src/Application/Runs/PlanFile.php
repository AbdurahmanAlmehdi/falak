<?php

namespace Falak\Config\Application\Runs;

use Falak\Config\Application\Planning\Plan;
use Falak\Config\Application\Planning\Planner;
use Falak\Config\Application\ScopeFactory;
use Falak\Config\Application\Validation\FileCheck;
use Falak\Config\Application\Validation\Validation;
use Falak\Config\Domain\Models\ConfigSource;
use Falak\Projects\Contracts\Data\EnvironmentData;

/**
 * Validate and plan a file for an environment without applying anything (the plan endpoint, pull request comments).
 */
final class PlanFile
{
    public function __construct(
        private readonly FileCheck $check,
        private readonly Planner $planner,
        private readonly ScopeFactory $scopes,
    ) {}

    /**
     * @return array{0: Validation, 1: ?Plan}
     */
    public function __invoke(ConfigSource $source, EnvironmentData $environment, string $raw, string $format, ?string $commit = null, ?string $actorId = null): array
    {
        $validation = $this->check->check($raw, $format, $environment->slug,
            fn (array $resolved) => $this->scopes->make($environment, $source, $resolved, $commit, $actorId));

        if (! $validation->valid() || $validation->resolved === null) {
            return [$validation, null];
        }

        $scope = $this->scopes->make($environment, $source, $validation->resolved, $commit, $actorId);

        return [$validation, $this->planner->plan($scope, $validation->resolved)];
    }
}
