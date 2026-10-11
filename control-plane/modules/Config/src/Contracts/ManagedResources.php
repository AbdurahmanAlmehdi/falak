<?php

namespace Falak\Config\Contracts;

/**
 * The `config_ownership` table for other modules: reconcilers record what they apply; controllers ask what a file
 * manages to show it read-only and to refuse edits (422) of managed fields.
 *
 * Resource types: site, database_instance, volume, worker, daemon, schedule, domain, redirect, header, mount,
 * function_schedule, backup_schedule, preview_settings, environment. Paths are JSON Pointers relative to the unit.
 */
interface ManagedResources
{
    /**
     * Record that the file manages $paths of a resource (called by {@see Reconcilable::apply()}). Paths of the resource
     * not listed are forgotten (no longer managed) unless $merge.
     *
     * @param  list<string>  $paths
     */
    public function record(ConfigScope $scope, ApplyContext $ctx, ?string $unit, string $resourceType, string $resourceId, array $paths, ?bool $createdByFile = null, bool $merge = false): void;

    /**
     * Forget the paths of a resource under $prefixes (e.g. ['/deploy'] for the paths one reconciler records) except
     * those in $keep: a reconciler sharing a resource with others drops its own stale paths only.
     *
     * @param  list<string>  $prefixes
     * @param  list<string>  $keep
     */
    public function forgetPaths(string $resourceType, string $resourceId, array $prefixes, array $keep = []): void;

    /**
     * The resource is no longer managed (gone from the file, deleted or detached). With $environmentId only that
     * environment's rows go (a run never forgets what another environment's file manages).
     */
    public function forget(string $resourceType, string $resourceId, ?string $environmentId = null): void;

    /**
     * The file created the resource (so removing it from the file deletes it). With $environmentId only that
     * environment's rows count: removal decisions of a run are its environment's.
     */
    public function createdByFile(string $resourceType, string $resourceId, ?string $environmentId = null): bool;

    /**
     * A run detached the resource: it is gone from the environment's file but kept (not created by the file, no
     * `allow_destroy`, or data). Forgets the environment's ownership rows of it and lists it as an unmanaged resource
     * of the environment (Project settings → Config as code) until it is deleted, dismissed or managed again.
     *
     * @param  string  $label  what the dashboard shows ("Worker queue (web)", "Domain shop.example.com")
     * @param  ?string  $deleteUrl  the module's own dashboard delete route for it (its permissions apply), if any
     * @param  ?string  $siteId  the site it belongs to (deleting the site drops it from the list)
     * @param  array<string, mixed>  $deleteBody  what the delete route needs in its body (a site's typed name)
     */
    public function detach(ConfigScope $scope, ApplyContext $ctx, ?string $unit, string $resourceType, string $resourceId, string $label, ?string $deleteUrl = null, ?string $siteId = null, array $deleteBody = []): void;

    /** The environment's file manages the resource (any path). */
    public function managedIn(string $environmentId, string $resourceType, string $resourceId): bool;

    /** The resource was replaced by another one (a database container's major upgrade): its rows move to it. */
    public function move(string $resourceType, string $fromId, string $toId): void;

    /** What the file manages of a resource (empty when nothing, or when the project's config source is disabled). */
    public function managed(string $resourceType, string $resourceId): ManagedFields;

    /**
     * Resources of a type the file manages in an environment, by id (with their unit).
     *
     * @return array<string, ?string> resource id => unit
     */
    public function resources(string $environmentId, string $resourceType): array;
}
