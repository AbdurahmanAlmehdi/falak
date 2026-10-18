<?php

namespace Falak\Volumes\Contracts;

use Falak\Volumes\Contracts\Data\VolumeRecoveryPoint;
use Illuminate\Validation\ValidationException;

/**
 * What a lost server's volumes can come back from, and restoring them on another server (the Recovery module's
 * "This server is gone" wizard and its readiness score). Database data volumes are left out: they come back with
 * their database.
 */
interface VolumeRecovery
{
    /**
     * Docker and sized volumes on a server, with their latest restorable backup.
     *
     * @return list<VolumeRecoveryPoint>
     */
    public function volumesOn(string $serverId): array;

    /**
     * Docker and sized volumes attached to the sites (or their compose services).
     *
     * @param  list<string>  $siteIds
     * @return list<VolumeRecoveryPoint>
     */
    public function forSites(array $siteIds): array;

    /**
     * The volume's latest restorable backup goes into a new volume of the same name on $targetServerId; once it is
     * filled, the old volume's attachments move to it and the sites concerned redeploy. Customer-held backups are
     * refused (they need the customer's identity on the Volumes page).
     *
     * @return string the volume operation id
     *
     * @throws ValidationException
     */
    public function restoreOnto(string $volumeId, string $targetServerId, ?string $actorId = null): string;

    /**
     * @return array{state: string, message: ?string} running | succeeded | failed
     */
    public function progress(string $operationId): array;

    /**
     * Moving a site (Recovery's "Move to another server"): the volume moves to $targetServerId through object storage,
     * its services stopped from the snapshot on. A plain volume is handed over (its services redeploy) and its source
     * deleted right away; a compose stack's volume becomes the copy of the same Docker name there, the stack is left
     * alone (it switches when it deploys there) and the source stays until {@see finishMove()}. A failure redeploys
     * the services where they were.
     *
     * @return string the volume operation id ({@see progress()})
     *
     * @throws ValidationException
     */
    public function move(string $volumeId, string $targetServerId, string $storageProviderId, ?string $actorId = null): string;

    /**
     * After the site runs on the new server: a compose volume's source (on the old server) is detached and deleted;
     * a plain move's source is already gone.
     *
     * @return ?string what was done, for the move's log
     */
    public function finishMove(string $operationId, ?string $actorId = null): ?string;
}
