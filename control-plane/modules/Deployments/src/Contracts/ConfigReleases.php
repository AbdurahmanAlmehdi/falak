<?php

namespace Falak\Deployments\Contracts;

use Illuminate\Validation\ValidationException;

/**
 * Deployments for config as code (the Config module): deploy a unit's site at a pushed commit, and keep the unit's
 * resolved config on its releases so a rollback can restore it.
 */
interface ConfigReleases
{
    /**
     * Queue a deployment of the site at $commit of $branch (trigger push; manual when $requestedBy is given).
     *
     * @return string the deployment id
     *
     * @throws ValidationException when the site cannot be deployed
     */
    public function deploy(string $siteId, ?string $branch, ?string $commit, ?string $message = null, ?string $author = null, ?string $requestedBy = null): string;

    /**
     * Save the unit's resolved config on a release (Release.config).
     *
     * @param  array<string, mixed>  $config
     */
    public function store(string $releaseId, array $config): void;

    /**
     * The config a release was made with, or null.
     *
     * @return ?array<string, mixed>
     */
    public function config(string $releaseId): ?array;

    /** The release a deployment created (null before it is planned). */
    public function releaseOf(string $deploymentId): ?string;
}
