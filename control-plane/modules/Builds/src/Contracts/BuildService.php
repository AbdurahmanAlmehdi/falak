<?php

namespace Falak\Builds\Contracts;

use Falak\Builds\Contracts\Data\ArtifactData;
use Falak\Builds\Contracts\Data\BuildData;
use Falak\Builds\Contracts\Data\BuildRequest;
use Falak\Builds\Contracts\Data\ComposeBuildData;
use Falak\Builds\Contracts\Data\ImageData;

/**
 * Builds for other modules (Deployments). A build runs once per site + commit + build configuration
 * on a builder (the control-plane host or a `builder` server); completion is announced with the
 * BuildSucceeded / BuildFailed / BuildCancelled events.
 */
interface BuildService
{
    /**
     * Queue a build. When an identical build (same cache key) already succeeded and its artifact is
     * still retained, that build is returned as-is (status succeeded, `reused` true) and no event fires.
     *
     * @throws \InvalidArgumentException when the site does not exist or cannot be built
     */
    public function request(BuildRequest $request): BuildData;

    public function find(string $buildId): ?BuildData;

    public function status(string $buildId): ?BuildStatus;

    /**
     * deploy.fetch `artifact` for a succeeded native build: a presigned https download URL valid for
     * $ttlSeconds plus sha256 / size / format. Null when the build has no (retained) artifact.
     */
    public function artifactFor(string $buildId, int $ttlSeconds = 3600): ?ArtifactData;

    /** Image + registry credentials of a succeeded docker build (deploy.container.swap). */
    public function imageFor(string $buildId): ?ImageData;

    /** Compose file + built images of a succeeded compose build (compose sites with a repository source). */
    public function composeFor(string $buildId): ?ComposeBuildData;

    /**
     * Short-lived pull credentials (deploy command `registry_auth`) for the built-in registry's repositories named in
     * $images (image refs or a compose file) that belong to the organization's sites; null when there are none.
     *
     * @return ?array{server: string, username: string, password: string}
     */
    public function registryAuth(string $organizationId, string $images): ?array;

    /** Cancel a queued or running build. Returns false when it already finished. */
    public function cancel(string $buildId): bool;

    /**
     * Build log lines after the cursor (seq is a global, monotonic cursor).
     *
     * @return list<array{seq: int, stream: string, data: string, at: string}>
     */
    public function output(string $buildId, int $afterSeq = 0, int $limit = 1000): array;
}
