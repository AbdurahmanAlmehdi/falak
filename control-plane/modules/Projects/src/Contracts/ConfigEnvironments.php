<?php

namespace Falak\Projects\Contracts;

use Falak\Projects\Contracts\Data\EnvironmentData;
use Illuminate\Validation\ValidationException;

/**
 * Environments of config as code (the Config module): a falak.yaml can create the environments it declares and flag
 * them production. It never deletes one (an environment gone from the file is only no longer managed).
 */
interface ConfigEnvironments
{
    /**
     * Create the environment $slug (a key of the file's `environments`) in a project: empty, named after the slug, and
     * not production (**[decision]** the `production` flag is a separate, risky change).
     *
     * @throws ValidationException when the slug is reserved, or taken by another environment (a preview's) or name
     */
    public function create(string $projectId, string $slug, ?string $userId = null): EnvironmentData;

    /**
     * Flag an environment production or not.
     *
     * @throws ValidationException for a preview environment
     */
    public function setProduction(string $environmentId, bool $production, ?string $userId = null): EnvironmentData;
}
