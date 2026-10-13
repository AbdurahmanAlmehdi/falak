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
     * Queue a deployment of the site at $commit of $branch (trigger push; manual when $requestedBy is given). A
     * deployment of the same commit already on its way is reused, unless its id sorts before $after (a ULID: it was
     * queued before the caller changed the site's config): that one is cancelled and a new one queued.
     *
     * @return string the deployment id
     *
     * @throws ValidationException when the site cannot be deployed
     */
    public function deploy(string $siteId, ?string $branch, ?string $commit, ?string $message = null, ?string $author = null, ?string $requestedBy = null, ?string $after = null): string;

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

    /**
     * How a deployment ended when it already failed or was cancelled (null while it runs, or once it succeeded): a
     * deployment can end while it is being queued (a build refused at once), before the caller knows its id.
     *
     * @return ?array{status: 'failed'|'cancelled', number: int, error: ?string}
     */
    public function ended(string $deploymentId): ?array;

    /** The release a deployment created (null before it is planned). */
    public function releaseOf(string $deploymentId): ?string;
}
