<?php

namespace Falak\SourceControl\Infrastructure;

use Falak\Identity\Contracts\AuditLog;
use Falak\SourceControl\Contracts\Data\CheckoutCredentials;
use Falak\SourceControl\Contracts\Data\CommitData;
use Falak\SourceControl\Contracts\Data\ConnectionData;
use Falak\SourceControl\Contracts\Data\DeployKeyData;
use Falak\SourceControl\Contracts\Data\RepositoryData;
use Falak\SourceControl\Contracts\Data\WebhookData;
use Falak\SourceControl\Contracts\Exceptions\ConnectionNotFound;
use Falak\SourceControl\Contracts\Exceptions\SourceControlException;
use Falak\SourceControl\Contracts\Exceptions\TreeTruncated;
use Falak\SourceControl\Contracts\SourceControlGateway;
use Falak\SourceControl\Domain\Models\Connection;
use Falak\SourceControl\Domain\Models\DeployKey;
use Falak\SourceControl\Domain\Models\Webhook;
use Falak\SourceControl\Infrastructure\GitHubApp\AppCredentials;
use Falak\SourceControl\Infrastructure\GitHubApp\AppManifest;
use Falak\SourceControl\Infrastructure\Providers\CustomGitClient;
use Falak\SourceControl\Infrastructure\Providers\ProviderClient;
use Falak\SourceControl\Infrastructure\Providers\ProviderClients;
use Illuminate\Support\Str;

final class EloquentSourceControlGateway implements SourceControlGateway
{
    public function __construct(
        private readonly ProviderClients $clients,
        private readonly DeployKeyGenerator $keys,
        private readonly AuditLog $audit,
    ) {}

    public function connection(string $connectionId): ?ConnectionData
    {
        return Connection::query()->find($connectionId)?->toData();
    }

    public function connections(string $organizationId): array
    {
        return Connection::query()
            ->where('organization_id', $organizationId)
            ->orderBy('name')
            ->get()
            ->map(fn (Connection $connection) => $connection->toData())
            ->values()
            ->all();
    }

    public function repositories(string $connectionId, ?string $search = null): array
    {
        $connection = $this->find($connectionId);

        return $this->client($connection)->repositories($connection, $search);
    }

    public function repository(string $connectionId, string $repository): ?RepositoryData
    {
        $connection = $this->find($connectionId);

        return $this->client($connection)->repository($connection, $repository);
    }

    public function branches(string $connectionId, string $repository): array
    {
        $connection = $this->find($connectionId);

        return $this->client($connection)->branches($connection, $repository);
    }

    public function file(string $connectionId, string $repository, string $ref, string $path): ?string
    {
        $path = trim($path, '/');

        if ($path === '' || preg_match('#(^|/)\.\.?(/|$)#', $path) === 1 || str_contains($path, "\0")) {
            return null;
        }

        $connection = $this->find($connectionId);

        return $this->client($connection)->file($connection, $repository, $ref, $path, self::MAX_FILE_BYTES);
    }

    public function exists(string $connectionId, string $repository, string $ref, string $path): bool
    {
        $path = trim($path, '/');

        if ($path === '' || preg_match('#(^|/)\.\.?(/|$)#', $path) === 1 || str_contains($path, "\0")) {
            return false;
        }

        $connection = $this->find($connectionId);

        return $this->client($connection)->exists($connection, $repository, $ref, $path);
    }

    public function tree(string $connectionId, string $repository, string $ref, string $glob = '*', bool $complete = false): array
    {
        $connection = $this->find($connectionId);
        $pattern = self::globPattern($glob);
        $byName = ! str_contains($glob, '/');
        $truncated = false;
        $paths = array_values(array_filter(
            $this->client($connection)->tree($connection, $repository, $ref, self::MAX_TREE_PATHS * 5, $truncated),
            fn (string $path) => preg_match($pattern, $byName ? basename($path) : $path) === 1,
        ));
        sort($paths);

        if ($complete && ($truncated || count($paths) > self::MAX_TREE_PATHS)) {
            throw TreeTruncated::at($repository, $ref);
        }

        return array_slice($paths, 0, self::MAX_TREE_PATHS);
    }

    /** `**` matches across directories, `*` within one, `?` one character; everything else is literal. */
    public static function globPattern(string $glob): string
    {
        $regex = '';

        foreach (preg_split('/(\*\*\/?|\*|\?)/', $glob, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            $regex .= match ($part) {
                '**/' => '(?:.*/)?',
                '**' => '.*',
                '*' => '[^/]*',
                '?' => '[^/]',
                default => preg_quote($part, '#'),
            };
        }

        return '#^'.$regex.'$#i';
    }

    public function latestCommit(string $connectionId, string $repository, string $branch): ?CommitData
    {
        $connection = $this->find($connectionId);

        return $this->client($connection)->latestCommit($connection, $repository, $branch);
    }

    public function commit(string $connectionId, string $repository, string $sha): ?CommitData
    {
        $connection = $this->find($connectionId);

        return $this->client($connection)->commit($connection, $repository, $sha);
    }

    public function installDeployKey(string $connectionId, string $repository, string $title): DeployKeyData
    {
        $connection = $this->find($connectionId);
        $generated = $this->keys->generate($title);

        $key = DeployKey::query()->create([
            'organization_id' => $connection->organization_id,
            'connection_id' => $connection->id,
            'repository' => $repository,
            'title' => Str::limit($title, 250, ''),
            'public_key' => $generated['public_key'],
            'private_key' => $generated['private_key'],
            'fingerprint' => $generated['fingerprint'],
        ]);

        // GitHub App connections clone over HTTPS with installation tokens (no administration permission to add keys).
        if ($connection->provider->hasApi() && ! $connection->isApp()) {
            try {
                $providerId = $this->client($connection)->addDeployKey($connection, $repository, $title, $generated['public_key']);
                $key->forceFill(['provider_key_id' => $providerId, 'installed_at' => now()])->save();
            } catch (SourceControlException $e) {
                $key->forceFill(['install_error' => Str::limit($e->getMessage(), 990)])->save();
            }
        }

        $this->audit->record('source_control.deploy_key_created', 'deploy_key', $key->id, [
            'connection_id' => $connection->id,
            'repository' => $repository,
            'fingerprint' => $key->fingerprint,
            'installed' => $key->installed_at !== null,
        ], $connection->organization_id);

        return $key->toData();
    }

    public function deployKey(string $deployKeyId): ?DeployKeyData
    {
        return DeployKey::query()->find($deployKeyId)?->toData();
    }

    public function removeDeployKey(string $deployKeyId): void
    {
        $key = DeployKey::query()->with('connection')->find($deployKeyId);

        if (! $key) {
            return;
        }

        if ($key->provider_key_id) {
            try {
                $this->client($key->connection)->removeDeployKey($key->connection, $key->repository, $key->provider_key_id);
            } catch (SourceControlException) {
                // Best effort: the key may already be gone or the token revoked.
            }
        }

        $key->delete();

        $this->audit->record('source_control.deploy_key_removed', 'deploy_key', $key->id, ['repository' => $key->repository, 'fingerprint' => $key->fingerprint], $key->organization_id);
    }

    public function ensureWebhook(string $connectionId, string $repository): WebhookData
    {
        $connection = $this->find($connectionId);

        $webhook = Webhook::query()->firstOrCreate(
            ['connection_id' => $connection->id, 'repository' => $repository],
            ['organization_id' => $connection->organization_id, 'secret' => Str::random(40), 'installed' => false],
        );

        if ($connection->isApp()) {
            // The app's own webhook delivers pushes for every repository of the installation; the row only marks
            // the repository as one Falak deploys from.
            if (! $webhook->installed) {
                $webhook->forceFill(['installed' => true, 'install_error' => null])->save();
            }

            $data = $webhook->toData();

            return new WebhookData($data->id, $data->connectionId, $data->repository, AppManifest::webhookUrl($connection->github_app_id ?: AppCredentials::ENV), true);
        }

        if ($webhook->installed || ! $connection->provider->hasApi()) {
            return $webhook->toData();
        }

        try {
            $hookId = $this->client($connection)->createWebhook($connection, $repository, $webhook->url(), $webhook->secret);
            $webhook->forceFill(['provider_hook_id' => $hookId, 'installed' => true, 'install_error' => null])->save();
        } catch (SourceControlException $e) {
            $webhook->forceFill(['install_error' => Str::limit($e->getMessage(), 990)])->save();
        }

        $this->audit->record('source_control.webhook_created', 'webhook', $webhook->id, ['connection_id' => $connection->id, 'repository' => $repository, 'installed' => $webhook->installed], $connection->organization_id);

        return $webhook->toData();
    }

    public function removeWebhook(string $connectionId, string $repository): void
    {
        $connection = $this->find($connectionId);
        $webhook = Webhook::query()->where('connection_id', $connection->id)->where('repository', $repository)->first();

        if (! $webhook || $webhook->pinned) {
            return;
        }

        if ($webhook->provider_hook_id && ! $connection->isApp()) {
            try {
                $this->client($connection)->deleteWebhook($connection, $repository, $webhook->provider_hook_id);
            } catch (SourceControlException) {
                // Best effort.
            }
        }

        $webhook->delete();

        $this->audit->record('source_control.webhook_removed', 'webhook', $webhook->id, ['connection_id' => $connection->id, 'repository' => $repository], $connection->organization_id);
    }

    public function pinWebhook(string $connectionId, string $repository, bool $pinned = true): WebhookData
    {
        $data = $pinned ? $this->ensureWebhook($connectionId, $repository) : null;
        $webhook = Webhook::query()->where('connection_id', $connectionId)->where('repository', $repository)->first();
        $webhook?->forceFill(['pinned' => $pinned])->save();

        return $data ?? $webhook?->toData() ?? new WebhookData('', $connectionId, $repository, '', false);
    }

    public function cloneUrl(string $connectionId, string $repository): string
    {
        $connection = $this->find($connectionId);

        return $this->client($connection)->sshUrl($connection, $repository);
    }

    public function checkoutCredentials(string $connectionId, string $repository, ?string $deployKeyId = null): CheckoutCredentials
    {
        $connection = $this->find($connectionId);
        $client = $this->client($connection);

        // App installations clone with a fresh installation token even if an older deploy key is still linked.
        if ($deployKeyId !== null && ! $connection->isApp()) {
            $key = DeployKey::query()->where('connection_id', $connection->id)->find($deployKeyId);

            if (! $key) {
                throw new SourceControlException("Deploy key {$deployKeyId} does not belong to this connection.");
            }

            $url = $client->sshUrl($connection, $key->repository);

            return new CheckoutCredentials(url: $url, sshPrivateKey: $key->private_key, knownHosts: $this->knownHosts($url));
        }

        if (! $connection->provider->hasApi()) {
            throw new SourceControlException('Custom git repositories are cloned with a deploy key.');
        }

        [$username, $password] = $client->httpsCredentials($connection);

        return new CheckoutCredentials(url: $client->httpsUrl($connection, $repository), httpsUsername: $username, httpsPassword: $password);
    }

    public function commentOnPullRequest(string $connectionId, string $repository, int $number, string $body, ?string $commentId = null): string
    {
        $connection = $this->find($connectionId);
        $client = $this->client($connection);

        if ($commentId !== null && ($id = $client->commentOnPullRequest($connection, $repository, $number, $body, $commentId)) !== null) {
            return $id;
        }

        return (string) $client->commentOnPullRequest($connection, $repository, $number, $body);
    }

    public function setCommitStatus(string $connectionId, string $repository, string $sha, string $state, string $context, string $description, ?string $url = null): void
    {
        $connection = $this->find($connectionId);
        $this->client($connection)->setCommitStatus($connection, $repository, $sha, $state, $context, $description, $url);
    }

    public function usersWithAccount(string $organizationId, string $provider, string $accountId): array
    {
        if (trim($accountId) === '') {
            return [];
        }

        return Connection::query()
            ->where('organization_id', $organizationId)
            ->where('provider', $provider)
            ->whereIn('auth_type', ['oauth', 'token', 'basic'])
            ->where('status', 'active')
            ->whereNotNull('created_by')
            ->where('account_id', trim($accountId))
            ->pluck('created_by')
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function knownHosts(string $url): ?string
    {
        $host = CustomGitClient::isUrl($url) && preg_match('#^[^@/]+@([^:]+):#', $url, $m) === 1 ? $m[1] : parse_url($url, PHP_URL_HOST);
        $line = is_string($host) ? (config('source_control.known_hosts')[$host] ?? null) : null;

        return is_string($line) ? $line : null;
    }

    private function find(string $connectionId): Connection
    {
        return Connection::query()->find($connectionId) ?? throw ConnectionNotFound::id($connectionId);
    }

    private function client(Connection $connection): ProviderClient
    {
        return $this->clients->for($connection->provider);
    }
}
