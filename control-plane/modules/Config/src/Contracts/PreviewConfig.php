<?php

namespace Falak\Config\Contracts;

use Illuminate\Validation\ValidationException;

/**
 * Previews of projects that follow a falak.yaml (the Previews module): **[decision]** a pull request's preview is built
 * from the pull request head's file (a fork's: the base branch's, the fork never supplies config), resolved for the
 * previews' base environment, so its units' settings, processes, schedules, limits and new units come from the file
 * instead of a copy of the base environment's.
 */
interface PreviewConfig
{
    /**
     * The file a preview is built from, read at $ref and resolved for the base environment; null when the project
     * has no enabled config source (previews work as before).
     *
     * @throws ValidationException the file can't be read or is invalid (key `config`)
     */
    public function prepare(string $projectId, string $baseEnvironmentId, string $ref): ?PreviewFile;

    /**
     * Apply a prepared file to a preview environment, leaving out $without (units the preview shares or omits): units
     * the preview doesn't have are created there (isolated, never deployed on push, on $serverId), and every unit's
     * settings come from the file. Only resources of that preview environment are touched; a fork's preview never
     * gets new units. Recorded as a `pr` run of the preview environment. Phase A only: each unit's phase B (workers,
     * schedules, routes) follows its preview deployment ({@see released()}). An env value referencing a shared site
     * ($shared) is kept only for names in $variables (the previews' copied variables).
     *
     * @param  list<string>  $without
     * @param  list<string>  $shared  sites the preview shares with its base environment
     * @param  list<string>  $variables  variable names previews copy
     * @return array{sites: array<string, string>, warnings: list<string>} the units created: unit => site id
     *
     * @throws ValidationException when applying fails (key `config`)
     */
    public function apply(PreviewFile $file, string $previewEnvironmentId, string $serverId, ?string $branch, string $commit, bool $fork, array $without = [], ?string $actorId = null, array $shared = [], array $variables = []): array;

    /**
     * A preview's site was deployed: the file's phase B for its unit (workers, schedules, routes) goes live with it.
     *
     * @throws \Throwable when applying fails
     */
    public function released(string $previewEnvironmentId, string $siteId): void;

    /** The preview environment is being deleted: what the file managed there is forgotten. */
    public function forget(string $previewEnvironmentId): void;
}
