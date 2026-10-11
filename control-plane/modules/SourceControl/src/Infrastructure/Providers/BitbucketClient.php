<?php

namespace Falak\SourceControl\Infrastructure\Providers;

use Falak\SourceControl\Contracts\Data\BranchData;
use Falak\SourceControl\Contracts\Data\CommitData;
use Falak\SourceControl\Contracts\Data\RepositoryData;
use Falak\SourceControl\Contracts\Exceptions\SourceControlException;
use Falak\SourceControl\Domain\Models\Connection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Bitbucket Cloud REST 2.0 with OAuth tokens or username + app password (basic auth).
 */
class BitbucketClient extends HttpProviderClient
{
    protected function label(): string
    {
        return 'Bitbucket';
    }

    protected function request(Connection $connection): PendingRequest
    {
        $request = Http::baseUrl(rtrim((string) config('source_control.bitbucket.api_url'), '/'));

        return $connection->auth_type === 'basic'
            ? $request->withBasicAuth((string) $connection->credential('username'), (string) $connection->credential('password'))
            : $request->withToken($this->tokens->token($connection));
    }

    public function account(Connection $connection): string
    {
        $user = $this->json($connection, '/user');

        return (string) ($user['username'] ?? $user['nickname'] ?? '');
    }

    public function accountId(Connection $connection): ?string
    {
        $user = $this->json($connection, '/user');

        return (string) ($user['account_id'] ?? $user['uuid'] ?? '') ?: null;
    }

    public function repositories(Connection $connection, ?string $search = null): array
    {
        $query = ['role' => 'member', 'pagelen' => 100, 'sort' => '-updated_on'];

        if ($search !== null && $search !== '') {
            $query['q'] = 'full_name ~ "'.str_replace('"', '', $search).'"';
        }

        $repositories = [];

        foreach ($this->pages($connection, '/repositories', $query) as $repo) {
            $repositories[] = $this->toRepository($repo);
        }

        return $repositories;
    }

    public function repository(Connection $connection, string $repository): ?RepositoryData
    {
        $repo = $this->json($connection, '/repositories/'.$this->path($repository), nullOn404: true);

        return $repo === null ? null : $this->toRepository($repo);
    }

    public function branches(Connection $connection, string $repository): array
    {
        $branches = [];

        foreach ($this->pages($connection, '/repositories/'.$this->path($repository).'/refs/branches', ['pagelen' => 100]) as $branch) {
            $branches[] = new BranchData((string) $branch['name'], $branch['target']['hash'] ?? null);
        }

        return $branches;
    }

    public function latestCommit(Connection $connection, string $repository, string $branch): ?CommitData
    {
        $body = $this->json($connection, '/repositories/'.$this->path($repository).'/refs/branches/'.rawurlencode($branch), nullOn404: true);

        return isset($body['target']) && is_array($body['target']) ? self::toCommit($body['target']) : null;
    }

    public function commit(Connection $connection, string $repository, string $sha): ?CommitData
    {
        $commit = $this->json($connection, '/repositories/'.$this->path($repository).'/commit/'.rawurlencode($sha), nullOn404: true);

        return $commit === null ? null : self::toCommit($commit);
    }

    public function file(Connection $connection, string $repository, string $ref, string $path, int $maxBytes): ?string
    {
        $url = '/repositories/'.$this->path($repository).'/src/'.rawurlencode($ref).'/'.self::encodedPath($path);
        // Metadata first: directories and large files are never downloaded.
        $meta = $this->json($connection, $url, ['format' => 'meta'], nullOn404: true);

        if ($meta === null || ($meta['type'] ?? null) !== 'commit_file') {
            return null;
        }

        if ((int) ($meta['size'] ?? 0) > $maxBytes) {
            throw $this->tooLarge($path, $maxBytes);
        }

        $response = $this->send($connection, 'GET', $url, nullOn404: true);

        if ($response === null) {
            return null;
        }

        $content = $response->body();

        if (strlen($content) > $maxBytes) {
            throw $this->tooLarge($path, $maxBytes);
        }

        return $content;
    }

    public function exists(Connection $connection, string $repository, string $ref, string $path): bool
    {
        return $this->json($connection, '/repositories/'.$this->path($repository).'/src/'.rawurlencode($ref).'/'.self::encodedPath($path), ['format' => 'meta'], nullOn404: true) !== null;
    }

    public function tree(Connection $connection, string $repository, string $ref, int $limit, ?bool &$truncated = null): array
    {
        $truncated = false;
        $paths = [];

        foreach ($this->pages($connection, '/repositories/'.$this->path($repository).'/src/'.rawurlencode($ref).'/', ['max_depth' => 20, 'pagelen' => 100]) as $entry) {
            if (is_array($entry) && ($entry['type'] ?? null) === 'commit_file' && isset($entry['path'])) {
                $paths[] = (string) $entry['path'];

                if (count($paths) >= $limit) {
                    $truncated = true;
                    break;
                }
            }
        }

        return $paths;
    }

    public function addDeployKey(Connection $connection, string $repository, string $title, string $publicKey): string
    {
        $response = $this->send($connection, 'POST', '/repositories/'.$this->path($repository).'/deploy-keys', body: ['key' => $publicKey, 'label' => $title]);

        return (string) $response?->json('id');
    }

    public function removeDeployKey(Connection $connection, string $repository, string $keyId): void
    {
        $this->send($connection, 'DELETE', '/repositories/'.$this->path($repository).'/deploy-keys/'.rawurlencode($keyId), nullOn404: true);
    }

    public function createWebhook(Connection $connection, string $repository, string $url, string $secret): string
    {
        $response = $this->send($connection, 'POST', '/repositories/'.$this->path($repository).'/hooks', body: [
            'description' => 'Falak push-to-deploy and previews',
            'url' => $url,
            'active' => true,
            'secret' => $secret,
            'events' => ['repo:push', 'pullrequest:created', 'pullrequest:updated', 'pullrequest:fulfilled', 'pullrequest:rejected', 'pullrequest:comment_created'],
        ]);

        return (string) $response?->json('uuid');
    }

    public function deleteWebhook(Connection $connection, string $repository, string $hookId): void
    {
        $this->send($connection, 'DELETE', '/repositories/'.$this->path($repository).'/hooks/'.rawurlencode($hookId), nullOn404: true);
    }

    public function commentOnPullRequest(Connection $connection, string $repository, int $number, string $body, ?string $commentId = null): ?string
    {
        $comments = '/repositories/'.$this->path($repository).'/pullrequests/'.$number.'/comments';

        if ($commentId !== null) {
            $response = $this->send($connection, 'PUT', $comments.'/'.rawurlencode($commentId), body: ['content' => ['raw' => $body]], nullOn404: true);

            return $response === null ? null : (string) $response->json('id');
        }

        return (string) $this->send($connection, 'POST', $comments, body: ['content' => ['raw' => $body]])?->json('id');
    }

    public function setCommitStatus(Connection $connection, string $repository, string $sha, string $state, string $context, string $description, ?string $url = null): void
    {
        // Bitbucket requires a URL; the key identifies the status (one per context).
        $this->send($connection, 'POST', '/repositories/'.$this->path($repository).'/commit/'.rawurlencode($sha).'/statuses/build', body: [
            'state' => match ($state) {
                'success' => 'SUCCESSFUL',
                'failure' => 'FAILED',
                default => 'INPROGRESS',
            },
            'key' => substr(preg_replace('/[^A-Za-z0-9_.-]+/', '-', $context) ?? 'falak', 0, 40),
            'name' => $context,
            'description' => mb_substr($description, 0, 255),
            'url' => $url ?? rtrim((string) config('app.url'), '/'),
        ]);
    }

    public function sshUrl(Connection $connection, string $repository): string
    {
        return 'git@'.self::host((string) config('source_control.bitbucket.url')).':'.$repository.'.git';
    }

    public function httpsUrl(Connection $connection, string $repository): string
    {
        return rtrim((string) config('source_control.bitbucket.url'), '/').'/'.$repository.'.git';
    }

    public function httpsCredentials(Connection $connection): array
    {
        return $connection->auth_type === 'basic'
            ? [(string) $connection->credential('username'), (string) $connection->credential('password')]
            : ['x-token-auth', $this->tokens->token($connection)];
    }

    /**
     * Author from Bitbucket's "Name <email>" raw string.
     *
     * @param  array<string, mixed>  $commit
     */
    public static function toCommit(array $commit): CommitData
    {
        $raw = (string) ($commit['author']['raw'] ?? '');
        preg_match('/^(.*?)\s*<([^>]+)>\s*$/', $raw, $m);

        return new CommitData(
            sha: (string) $commit['hash'],
            message: (string) ($commit['message'] ?? ''),
            authorName: ($m[1] ?? '') !== '' ? $m[1] : ($commit['author']['user']['display_name'] ?? ($raw !== '' ? $raw : null)),
            authorEmail: $m[2] ?? null,
            committedAt: self::date($commit['date'] ?? null),
            url: $commit['links']['html']['href'] ?? null,
        );
    }

    /**
     * Iterates `values` across pages following the `next` link.
     *
     * @param  array<string, mixed>  $query
     * @return iterable<array<string, mixed>>
     */
    private function pages(Connection $connection, string $path, array $query): iterable
    {
        $url = $path;

        for ($page = 0; $page < $this->maxPages() && $url !== null; $page++) {
            $body = $this->json($connection, $url, $page === 0 ? $query : []);

            foreach ((array) ($body['values'] ?? []) as $value) {
                yield $value;
            }

            $url = isset($body['next']) && is_string($body['next']) ? $body['next'] : null;
        }
    }

    /**
     * @param  array<string, mixed>  $repo
     */
    private function toRepository(array $repo): RepositoryData
    {
        $clone = collect($repo['links']['clone'] ?? [])->pluck('href', 'name');

        return new RepositoryData(
            fullName: (string) $repo['full_name'],
            defaultBranch: (string) ($repo['mainbranch']['name'] ?? 'main'),
            private: (bool) ($repo['is_private'] ?? true),
            sshUrl: (string) ($clone['ssh'] ?? ''),
            httpsUrl: (string) preg_replace('#//[^@/]+@#', '//', (string) ($clone['https'] ?? '')),
            webUrl: $repo['links']['html']['href'] ?? null,
        );
    }

    private function path(string $repository): string
    {
        if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repository) !== 1) {
            throw SourceControlException::provider('Bitbucket', "Invalid repository name \"{$repository}\" (expected workspace/slug).");
        }

        return $repository;
    }
}
