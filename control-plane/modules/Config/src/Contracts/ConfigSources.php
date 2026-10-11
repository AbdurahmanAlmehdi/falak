<?php

namespace Falak\Config\Contracts;

/**
 * Whether a project follows a falak.yaml. Deployments asks it so push-to-deploy leaves those sites to Config.
 */
interface ConfigSources
{
    /** The project has an enabled config source. */
    public function enabledForProject(string $projectId): bool;

    /** The site is a service of a project with an enabled config source. */
    public function managesSite(string $siteId): bool;
}
