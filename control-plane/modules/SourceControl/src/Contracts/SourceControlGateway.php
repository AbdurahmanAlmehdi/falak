<?php

namespace Falak\SourceControl\Contracts;

use Falak\SourceControl\Contracts\Data\BranchData;
use Falak\SourceControl\Contracts\Data\CheckoutCredentials;
use Falak\SourceControl\Contracts\Data\CommitData;
use Falak\SourceControl\Contracts\Data\ConnectionData;
use Falak\SourceControl\Contracts\Data\DeployKeyData;
use Falak\SourceControl\Contracts\Data\RepositoryData;
use Falak\SourceControl\Contracts\Data\WebhookData;
use Falak\SourceControl\Contracts\Exceptions\ConnectionNotFound;
use Falak\SourceControl\Contracts\Exceptions\SourceControlException;

/**
 * Git provider access for other modules (Sites, Builds, Deployments).
 *
 * Every method taking a connection id throws {@see ConnectionNotFound} for unknown ids and
 * {@see SourceControlException} when the provider API fails.
 */
interface SourceControlGateway
{
    public function connection(string $connectionId): ?ConnectionData;

    /**
     * @return list<ConnectionData>
     */
    public function connections(string $organizationId): array;

    /**
     * Repositories the connection can access (empty for custom git).
     *
     * @return list<RepositoryData>
     */
    public function repositories(string $connectionId, ?string $search = null): array;

    public function repository(string $connectionId, string $repository): ?RepositoryData;

    /**
     * @return list<BranchData>
     */
    public function branches(string $connectionId, string $repository): array;

    /** Head commit of a branch (null for custom git or unknown branches). */
    public function latestCommit(string $connectionId, string $repository, string $branch): ?CommitData;

    public function commit(string $connectionId, string $repository, string $sha): ?CommitData;

    /**
     * Generate a fresh ed25519 deploy key for the repository and register it (read-only) at the
     * provider when it has an API. Registration failures are reported on the returned data, not thrown.
     */
    public function installDeployKey(string $connectionId, string $repository, string $title): DeployKeyData;

    public function deployKey(string $deployKeyId): ?DeployKeyData;

    /** Remove a deploy key locally and (best-effort) at the provider. */
    public function removeDeployKey(string $deployKeyId): void;

    /**
     * Ensure a push webhook for the repository exists (created at the provider when possible).
     * Idempotent: returns the existing webhook when one is registered.
     */
    public function ensureWebhook(string $connectionId, string $repository): WebhookData;

    /** Remove the push webhook when no longer needed (best-effort at the provider). A pinned webhook stays. */
    public function removeWebhook(string $connectionId, string $repository): void;

    /**
     * Ensure the repository's webhook and keep it (pinned) while previews need its pull request events, or release it
     * ($pinned false: removable again once nothing deploys on push from it).
     */
    public function pinWebhook(string $connectionId, string $repository, bool $pinned = true): WebhookData;

    /**
     * Post Falak's comment on a pull request, or edit it when $commentId is given (posted anew when it was deleted).
     * Throws {@see Exceptions\NoApi} for git servers without an API.
     *
     * @return string the comment's id
     */
    public function commentOnPullRequest(string $connectionId, string $repository, int $number, string $body, ?string $commentId = null): string;

    /**
     * Report a commit status under $context (GitHub commit status, GitLab pipeline status, Bitbucket build status).
     * Throws {@see Exceptions\NoApi} for git servers without an API.
     *
     * @param  'pending'|'success'|'failure'  $state
     */
    public function setCommitStatus(string $connectionId, string $repository, string $sha, string $state, string $context, string $description, ?string $url = null): void;

    /**
     * Falak users of the organization who connected the provider account with the immutable id $accountId (OAuth and
     * token connections record the account they authenticate as): how a pull request commenter maps to Falak members.
     * Never matched by login or nickname (renamed and reused). App installations prove nothing about a person.
     *
     * @return list<string> user ids
     */
    public function usersWithAccount(string $organizationId, string $provider, string $accountId): array;

    /** Largest file {@see file()} returns. */
    public const MAX_FILE_BYTES = 1048576;

    /** Most paths {@see tree()} returns. */
    public const MAX_TREE_PATHS = 2000;

    /**
     * Content of a file at a ref (branch, tag or commit); null when the path doesn't exist or isn't a file.
     * Throws {@see Exceptions\NoApi} for git servers without an API and {@see SourceControlException} for files
     * larger than {@see MAX_FILE_BYTES}.
     */
    public function file(string $connectionId, string $repository, string $ref, string $path): ?string;

    /**
     * Whether a file or a directory exists at a ref (cheaper than {@see tree()} for large repositories).
     * Throws {@see Exceptions\NoApi} for git servers without an API.
     */
    public function exists(string $connectionId, string $repository, string $ref, string $path): bool;

    /**
     * Paths of the files at a ref matching a glob (`*` within a segment, `**` across segments; matched against the
     * whole path, or against the file name when the glob has no `/`), sorted, at most {@see MAX_TREE_PATHS}.
     * Throws {@see Exceptions\NoApi} for git servers without an API, and with $complete
     * {@see Exceptions\TreeTruncated} when the list is not whole (the provider truncated it or a limit was reached).
     *
     * @return list<string>
     */
    public function tree(string $connectionId, string $repository, string $ref, string $glob = '*', bool $complete = false): array;

    /** SSH clone URL for the repository. */
    public function cloneUrl(string $connectionId, string $repository): string;

    /**
     * Clone URL + credentials for builders. Prefers the deploy key (SSH) when given; otherwise uses the
     * connection's token over HTTPS.
     */
    public function checkoutCredentials(string $connectionId, string $repository, ?string $deployKeyId = null): CheckoutCredentials;
}
