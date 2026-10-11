<?php

namespace Falak\Sites\Tests\Support;

use Falak\SourceControl\Contracts\Data\BranchData;
use Falak\SourceControl\Contracts\Data\CheckoutCredentials;
use Falak\SourceControl\Contracts\Data\CommitData;
use Falak\SourceControl\Contracts\Data\ConnectionData;
use Falak\SourceControl\Contracts\Data\DeployKeyData;
use Falak\SourceControl\Contracts\Data\RepositoryData;
use Falak\SourceControl\Contracts\Data\WebhookData;
use Falak\SourceControl\Contracts\Exceptions\ConnectionNotFound;
use Falak\SourceControl\Contracts\Exceptions\NoApi;
use Falak\SourceControl\Contracts\Exceptions\SourceControlException;
use Falak\SourceControl\Contracts\ProviderType;
use Falak\SourceControl\Contracts\SourceControlGateway;
use Illuminate\Support\Str;

/**
 * In-memory SourceControlGateway implementing only the public contract.
 */
final class FakeSourceControlGateway implements SourceControlGateway
{
    /** @var array<string, ConnectionData> */
    public array $connections = [];

    /** @var array<string, DeployKeyData> */
    public array $keys = [];

    /** @var array<string, WebhookData> keyed by "connection|repo" */
    public array $webhooks = [];

    /** @var list<string> */
    public array $removedKeys = [];

    /** @var list<string> */
    public array $removedWebhooks = [];

    public ?string $failKeysWith = null;

    public function addConnection(string $organizationId, ProviderType $provider = ProviderType::GitHub, string $authType = 'token'): ConnectionData
    {
        $connection = new ConnectionData((string) Str::ulid(), $organizationId, $provider, $provider->label(), $authType, 'acme', null);

        return $this->connections[$connection->id] = $connection;
    }

    public function connection(string $connectionId): ?ConnectionData
    {
        return $this->connections[$connectionId] ?? null;
    }

    public function connections(string $organizationId): array
    {
        return array_values(array_filter($this->connections, fn (ConnectionData $c) => $c->organizationId === $organizationId));
    }

    public function repositories(string $connectionId, ?string $search = null): array
    {
        return [new RepositoryData('acme/shop', 'main', true, 'git@github.com:acme/shop.git', 'https://github.com/acme/shop.git')];
    }

    public function repository(string $connectionId, string $repository): ?RepositoryData
    {
        return $this->repositories($connectionId)[0];
    }

    public function branches(string $connectionId, string $repository): array
    {
        return [new BranchData('main', str_repeat('a', 40))];
    }

    public function latestCommit(string $connectionId, string $repository, string $branch): ?CommitData
    {
        return new CommitData(str_repeat('a', 40), 'Initial commit', 'Ada', 'ada@example.com');
    }

    public function commit(string $connectionId, string $repository, string $sha): ?CommitData
    {
        return new CommitData($sha, 'Commit', 'Ada', 'ada@example.com');
    }

    public function installDeployKey(string $connectionId, string $repository, string $title): DeployKeyData
    {
        $connection = $this->connections[$connectionId] ?? throw ConnectionNotFound::id($connectionId);

        if ($this->failKeysWith !== null) {
            throw new SourceControlException($this->failKeysWith);
        }

        $key = new DeployKeyData((string) Str::ulid(), $connectionId, $repository, 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFake falak', 'SHA256:fake', $connection->provider->hasApi());

        return $this->keys[$key->id] = $key;
    }

    public function deployKey(string $deployKeyId): ?DeployKeyData
    {
        return $this->keys[$deployKeyId] ?? null;
    }

    public function removeDeployKey(string $deployKeyId): void
    {
        $this->removedKeys[] = $deployKeyId;
        unset($this->keys[$deployKeyId]);
    }

    public function ensureWebhook(string $connectionId, string $repository): WebhookData
    {
        return $this->webhooks["{$connectionId}|{$repository}"] ??= new WebhookData((string) Str::ulid(), $connectionId, $repository, 'https://falak.test/api/webhooks/source-control/x', true);
    }

    public function removeWebhook(string $connectionId, string $repository): void
    {
        $this->removedWebhooks[] = "{$connectionId}|{$repository}";
        unset($this->webhooks["{$connectionId}|{$repository}"]);
    }

    /** @var array<string, string> repository files by path (shared by every repository and ref) */
    public array $files = [];

    public function file(string $connectionId, string $repository, string $ref, string $path): ?string
    {
        $connection = $this->connections[$connectionId] ?? throw ConnectionNotFound::id($connectionId);

        if (! $connection->provider->hasApi()) {
            throw NoApi::forConnection($connection->name);
        }

        $this->fileReads[] = ['ref' => $ref, 'path' => trim($path, '/')];

        if (isset($this->filesByRef[$ref]) && array_key_exists(trim($path, '/'), $this->filesByRef[$ref])) {
            return $this->filesByRef[$ref][trim($path, '/')];
        }

        return $this->files[trim($path, '/')] ?? null;
    }

    /** @var array<string, array<string, ?string>> files of one ref (branch or commit) by path, over */
    public array $filesByRef = [];

    /** @var list<array{ref: string, path: string}> every file() read */
    public array $fileReads = [];

    /** @var list<string> paths exists() answers false for (e.g. to simulate an unlisted folder) */
    public array $existsCalls = [];

    public function exists(string $connectionId, string $repository, string $ref, string $path): bool
    {
        $connection = $this->connections[$connectionId] ?? throw ConnectionNotFound::id($connectionId);

        if (! $connection->provider->hasApi()) {
            throw NoApi::forConnection($connection->name);
        }

        $path = trim($path, '/');
        $this->existsCalls[] = $path;

        foreach (array_keys($this->files) as $file) {
            if ($file === $path || str_starts_with($file, $path.'/')) {
                return true;
            }
        }

        return false;
    }

    public function tree(string $connectionId, string $repository, string $ref, string $glob = '*'): array
    {
        $connection = $this->connections[$connectionId] ?? throw ConnectionNotFound::id($connectionId);

        if (! $connection->provider->hasApi()) {
            throw NoApi::forConnection($connection->name);
        }

        $paths = array_values(array_filter(array_keys($this->files), fn (string $path) => fnmatch($glob, str_contains($glob, '/') ? $path : basename($path))));
        sort($paths);

        return $paths;
    }

    public function cloneUrl(string $connectionId, string $repository): string
    {
        return "git@github.com:{$repository}.git";
    }

    public function checkoutCredentials(string $connectionId, string $repository, ?string $deployKeyId = null): CheckoutCredentials
    {
        return new CheckoutCredentials($this->cloneUrl($connectionId, $repository), 'PRIVATE');
    }

    /** @var array<string, array{repository: string, number: int, body: string}> pull request comments by id */
    public array $comments = [];

    /** @var list<array{repository: string, sha: string, state: string, context: string, description: string, url: ?string}> */
    public array $statuses = [];

    /** @var array<string, list<string>> "provider|account id" => user ids */
    public array $accounts = [];

    public function commentOnPullRequest(string $connectionId, string $repository, int $number, string $body, ?string $commentId = null): string
    {
        $id = $commentId !== null && isset($this->comments[$commentId]) ? $commentId : (string) (count($this->comments) + 1);
        $this->comments[$id] = ['repository' => $repository, 'number' => $number, 'body' => $body];

        return $id;
    }

    public function setCommitStatus(string $connectionId, string $repository, string $sha, string $state, string $context, string $description, ?string $url = null): void
    {
        $this->statuses[] = compact('repository', 'sha', 'state', 'context', 'description', 'url');
    }

    /** @var array<string, bool> "connection|repository" => pinned */
    public array $pinned = [];

    public function pinWebhook(string $connectionId, string $repository, bool $pinned = true): WebhookData
    {
        $this->pinned["{$connectionId}|{$repository}"] = $pinned;

        return $pinned ? $this->ensureWebhook($connectionId, $repository) : new WebhookData('', $connectionId, $repository, '', false);
    }

    public function usersWithAccount(string $organizationId, string $provider, string $accountId): array
    {
        return $this->accounts["{$provider}|{$accountId}"] ?? [];
    }
}
