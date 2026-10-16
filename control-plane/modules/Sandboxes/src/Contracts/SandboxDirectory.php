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

    /** A sandbox of the organization by id, or by name among those not killed; null otherwise. */
    public function find(string $organizationId, string $ref): ?SandboxData;
}
