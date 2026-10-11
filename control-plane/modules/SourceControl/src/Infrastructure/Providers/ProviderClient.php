<?php

namespace Falak\SourceControl\Infrastructure\Providers;

use Falak\SourceControl\Contracts\Data\BranchData;
use Falak\SourceControl\Contracts\Data\CommitData;
use Falak\SourceControl\Contracts\Data\RepositoryData;
use Falak\SourceControl\Contracts\Exceptions\SourceControlException;
use Falak\SourceControl\Domain\Models\Connection;

/**
 * One git provider's API. Every method throws {@see SourceControlException} on API failures.
 */
interface ProviderClient
{
    /** Login / username of the authenticated account (verifies credentials). */
    public function account(Connection $connection): string;

    /** The provider's immutable id of the authenticated user (null: none, e.g. an app installation or custom git). */
    public function accountId(Connection $connection): ?string;

    /**
     * @return list<RepositoryData>
     */
    public function repositories(Connection $connection, ?string $search = null): array;

    public function repository(Connection $connection, string $repository): ?RepositoryData;

    /**
     * @return list<BranchData>
     */
    public function branches(Connection $connection, string $repository): array;

    public function latestCommit(Connection $connection, string $repository, string $branch): ?CommitData;

    public function commit(Connection $connection, string $repository, string $sha): ?CommitData;

    /** Register a read-only deploy key; returns the provider's key id. */
    public function addDeployKey(Connection $connection, string $repository, string $title, string $publicKey): string;

    public function removeDeployKey(Connection $connection, string $repository, string $keyId): void;

    /** Register a push webhook; returns the provider's hook id. */
    public function createWebhook(Connection $connection, string $repository, string $url, string $secret): string;

    public function deleteWebhook(Connection $connection, string $repository, string $hookId): void;

    /**
     * Post a comment on a pull request, or edit the comment $commentId; returns the comment's id. An edit of a comment
     * that is gone returns null (the caller posts a new one).
     */
    public function commentOnPullRequest(Connection $connection, string $repository, int $number, string $body, ?string $commentId = null): ?string;

    /**
     * Set a commit status (GitHub commit status, GitLab pipeline status, Bitbucket build status).
     *
     * @param  'pending'|'success'|'failure'  $state
     */
    public function setCommitStatus(Connection $connection, string $repository, string $sha, string $state, string $context, string $description, ?string $url = null): void;

    /** Content of a file at a ref (null: missing or not a file); throws for files larger than $maxBytes. */
    public function file(Connection $connection, string $repository, string $ref, string $path, int $maxBytes): ?string;

    /** Whether a file or directory exists at a ref. */
    public function exists(Connection $connection, string $repository, string $ref, string $path): bool;

    /**
     * Every file path (blob) at a ref, at most $limit; $truncated says whether the list is not whole (the provider
     * truncated it, or $limit was reached).
     *
     * @return list<string>
     */
    public function tree(Connection $connection, string $repository, string $ref, int $limit, ?bool &$truncated = null): array;

    public function sshUrl(Connection $connection, string $repository): string;

    public function httpsUrl(Connection $connection, string $repository): string;

    /**
     * Basic-auth pair for HTTPS clones.
     *
     * @return array{0: ?string, 1: ?string} [username, password]
     */
    public function httpsCredentials(Connection $connection): array;
}
