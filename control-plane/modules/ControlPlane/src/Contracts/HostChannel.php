<?php

namespace Falak\ControlPlane\Contracts;

use Falak\ControlPlane\Contracts\Exceptions\HostRequestRefused;

/**
 * The panel → host channel (falak-ctl's falak-host.path): the panel drops one request.json into the shared directory,
 * the host runs the allow-listed action as root and reports status.json and <id>.log (read-only to the panel). One
 * request at a time.
 */
interface HostChannel
{
    public const UPDATE = 'update';

    public const LOCAL_SERVER = 'local-server';

    /** Whether the host installed the channel: its directory is mounted and falak-ctl wrote channel.json there. */
    public function available(): bool;

    /**
     * `falak-ctl update <version>` (takes a backup first, rolls back on failure; the panel restarts).
     *
     * @return string the request id
     *
     * @throws HostRequestRefused
     */
    public function requestUpdate(string $version, ?string $requestedBy): string;

    /**
     * Install and enroll an agent on the control plane's own machine from this install URL (FALAK_URL/install/<token>).
     *
     * @return string the request id
     *
     * @throws HostRequestRefused
     */
    public function requestLocalServer(string $installUrl, ?string $requestedBy): string;

    /**
     * The channel as the panel sees it: `pending` is request.json not yet picked up (`stale` past the stale window),
     * `status` the host's status.json; `busy` refuses new requests. `mounted` without `available`: the directory is
     * there but falak-ctl has not installed its units yet (sudo falak-ctl up once).
     *
     * @return array{mounted: bool, available: bool, busy: bool, pending: array{id: string, action: string, requested_at: ?string, stale: bool}|null, status: array{id: string, action: string, state: string, started_at: ?string, finished_at: ?string, exit_code: ?int, error: ?string}|null}
     */
    public function state(): array;

    /** The last $bytes of a request's log (null: none yet). */
    public function log(string $id, int $bytes = 65536): ?string;
}
