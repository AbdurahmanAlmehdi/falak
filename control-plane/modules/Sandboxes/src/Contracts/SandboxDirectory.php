<?php

namespace Falak\Sandboxes\Contracts;

use Falak\Sandboxes\Contracts\Data\SandboxData;

/**
 * Read-only sandbox lookups for other modules (Terminal opens consoles in them).
 */
interface SandboxDirectory
{
    /** The permission that opens a console in a sandbox (with the other sandbox actions). */
    public const USE_PERMISSION = 'sandboxes.use';

    /** Exec, files and consoles in someone else's sandbox (its creator only needs USE_PERMISSION). */
    public const MANAGE_PERMISSION = 'sandboxes.manage';

    /** The agent feature sandboxes need. */
    public const FEATURE = 'sandbox.v1';

    /** The agent feature snapshots, forks, pause/resume and database copies need (v0.21). */
    public const FEATURE_V2 = 'sandbox.v2';

    /** A sandbox of the organization by id, or by name among those not killed; null otherwise. */
    public function find(string $organizationId, string $ref): ?SandboxData;

    /**
     * About to be used (a console): records the activity (idle pause) and resumes a paused sandbox first
     * (sandbox.resume; aborts with 503/504/409 as an exec would). Returns the sandbox as it is now.
     */
    public function wake(string $sandboxId): SandboxData;

    /**
     * Still in use (an open console): records the activity, so idle pause leaves them alone.
     *
     * @param  list<string>  $sandboxIds
     */
    public function markActive(array $sandboxIds): void;
}
