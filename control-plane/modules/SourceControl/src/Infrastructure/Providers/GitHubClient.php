<?php

namespace Falak\SourceControl\Infrastructure\Providers;

use Falak\SourceControl\Contracts\Data\BranchData;
use Falak\SourceControl\Contracts\Data\CommitData;
use Falak\SourceControl\Contracts\Data\RepositoryData;
use Falak\SourceControl\Contracts\Exceptions\SourceControlException;
use Falak\SourceControl\Domain\Models\Connection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * GitHub REST v3 (github.com or GitHub Enterprise Server via the connection's base URL).
 * Works with OAuth user tokens, personal access tokens and GitHub App installation tokens.
 */
class GitHubClient extends HttpProviderClient
{
    protected function label(): string
    {
        return 'GitHub';
    }

    protected function request(Connection $connection): PendingRequest
    {
        return Http::baseUrl($this->apiUrl($connection))
            ->withToken($this->tokens->token($connection))
            ->accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28']);
    }

    public function account(Connection $connection): string
    {
        if ($connection->isApp()) {
            $body = $this->json($connection, '/installation/repositories', ['per_page' => 1]);

            return (string) ($body['repositories'][0]['owner']['login'] ?? $connection->account ?? '');
        }

        return (string) ($this->json($connection, '/user')['login'] ?? '');
    }

    public function accountId(Connection $connection): ?string
    {
        return $connection->isApp() ? null : ((string) ($this->json($connection, '/user')['id'] ?? '') ?: null);
    }

    public function repositories(Connection $connection, ?string $search = null): array
    {
        if ($connection->isApp()) {
            return array_values(array_filter(
                $this->installationRepositories($connection),
                fn (RepositoryData $repo) => self::matches($repo->fullName, $search),
            ));
        }

        $repositories = [];

        foreach ($this->pages($connection, '/user/repos', ['per_page' => 100, 'sort' => 'updated', 'affiliation' => 'owner,collaborator,organization_member']) as $page) {
            foreach ($page as $repo) {
                if (is_array($repo) && self::matches((string) ($repo['full_name'] ?? ''), $search)) {
                    $repositories[] = $this->toRepository($repo);
                }
            }
        }

        return $repositories;
    }

    /**
     * Every repository the installation was granted (GET /installation/repositories, paginated). Cached for ten
     * minutes so the pickers can search as the user types; the installation webhooks and the setup callback
     * refresh it when access changes on GitHub.
     *
     * @return list<RepositoryData>
     */
    public function installationRepositories(Connection $connection, bool $fresh = false): array
    {
        $key = self::repositoriesCacheKey($connection->id);
        $cached = $fresh ? null : Cache::get($key);

        if (is_array($cached)) {
            return array_map(fn (array $repo) => new RepositoryData(...$repo), $cached);
        }

        $repositories = [];

        foreach ($this->pages($connection, '/installation/repositories', ['per_page' => 100]) as $page) {
            foreach ((array) ($page['repositories'] ?? []) as $repo) {
                if (is_array($repo) && isset($repo['full_name'])) {
                    $repositories[] = $this->toRepository($repo);
                }
            }
        }

        Cache::put($key, array_map(fn (RepositoryData $repo) => get_object_vars($repo), $repositories), 600);
        Cache::forever(self::repositoryCountCacheKey($connection->id), count($repositories));

        return $repositories;
    }

    public static function forgetRepositories(string $connectionId): void
    {
        Cache::forget(self::repositoriesCacheKey($connectionId));
    }

    /** Last known number of repositories an app installation can access (null until first listed). */
    public static function knownRepositoryCount(string $connectionId): ?int
    {
        $count = Cache::get(self::repositoryCountCacheKey($connectionId));

        return is_int($count) ? $count : null;
    }

    private static function repositoriesCacheKey(string $connectionId): string
    {
        return "source-control:github-app:repos:{$connectionId}";
    }

    private static function repositoryCountCacheKey(string $connectionId): string
    {
        return "source-control:github-app:repo-count:{$connectionId}";
    }

    public function repository(Connection $connection, string $repository): ?RepositoryData
    {
        $repo = $this->json($connection, '/repos/'.$this->path($repository), nullOn404: true);

        return $repo === null ? null : $this->toRepository($repo);
    }

    public function branches(Connection $connection, string $repository): array
    {
        $branches = [];

        foreach ($this->pages($connection, '/repos/'.$this->path($repository).'/branches', ['per_page' => 100]) as $page) {
            foreach ($page as $branch) {
                $branches[] = new BranchData((string) $branch['name'], $branch['commit']['sha'] ?? null, (bool) ($branch['protected'] ?? false));
            }
        }

        return $branches;
    }

    public function latestCommit(Connection $connection, string $repository, string $branch): ?CommitData
    {
        return $this->commit($connection, $repository, $branch);
    }

    public function commit(Connection $connection, string $repository, string $sha): ?CommitData
    {
        $commit = $this->json($connection, '/repos/'.$this->path($repository).'/commits/'.rawurlencode($sha), nullOn404: true);

        if ($commit === null) {
            return null;
        }

        return new CommitData(
            sha: (string) $commit['sha'],
            message: (string) ($commit['commit']['message'] ?? ''),
            authorName: $commit['commit']['author']['name'] ?? null,
            authorEmail: $commit['commit']['author']['email'] ?? null,
            committedAt: self::date($commit['commit']['author']['date'] ?? null),
            url: $commit['html_url'] ?? null,
        );
    }

    public function file(Connection $connection, string $repository, string $ref, string $path, int $maxBytes): ?string
    {
        $body = $this->json($connection, '/repos/'.$this->path($repository).'/contents/'.self::encodedPath($path), ['ref' => $ref], nullOn404: true);

        // A directory answers with a list; symlinks and submodules aren't files.
        if ($body === null || array_is_list($body) || ($body['type'] ?? null) !== 'file') {
            return null;
        }

        if ((int) ($body['size'] ?? 0) > $maxBytes) {
            throw $this->tooLarge($path, $maxBytes);
        }

        $content = base64_decode(str_replace("\n", '', (string) ($body['content'] ?? '')), true);

        return $content === false ? null : $content;
    }

    public function exists(Connection $connection, string $repository, string $ref, string $path): bool
    {
        // Files answer with their metadata, directories with a listing.
        return $this->json($connection, '/repos/'.$this->path($repository).'/contents/'.self::encodedPath($path), ['ref' => $ref], nullOn404: true) !== null;
    }

    public function tree(Connection $connection, string $repository, string $ref, int $limit, ?bool &$truncated = null): array
    {
        $truncated = false;
        $body = $this->json($connection, '/repos/'.$this->path($repository).'/git/trees/'.rawurlencode($ref), ['recursive' => 1], nullOn404: true);
        $paths = [];

        foreach ((array) ($body['tree'] ?? []) as $entry) {
            if (is_array($entry) && ($entry['type'] ?? null) === 'blob' && isset($entry['path'])) {
                $paths[] = (string) $entry['path'];

                if (count($paths) >= $limit) {
                    $truncated = true;
                    break;
                }
            }
        }

        // GitHub cuts recursive listings of very large trees (`truncated`).
        $truncated = $truncated || (bool) ($body['truncated'] ?? false);

        return $paths;
    }

    public function addDeployKey(Connection $connection, string $repository, string $title, string $publicKey): string
    {
        $response = $this->send($connection, 'POST', '/repos/'.$this->path($repository).'/keys', body: ['title' => $title, 'key' => $publicKey, 'read_only' => true]);

        return (string) $response?->json('id');
    }

    public function removeDeployKey(Connection $connection, string $repository, string $keyId): void
    {
        $this->send($connection, 'DELETE', '/repos/'.$this->path($repository).'/keys/'.rawurlencode($keyId), nullOn404: true);
    }

    public function createWebhook(Connection $connection, string $repository, string $url, string $secret): string
    {
        $response = $this->send($connection, 'POST', '/repos/'.$this->path($repository).'/hooks', body: [
            'name' => 'web',
            'active' => true,
            'events' => ['push', 'pull_request', 'issue_comment'],
            'config' => ['url' => $url, 'content_type' => 'json', 'secret' => $secret, 'insecure_ssl' => '0'],
        ]);

        return (string) $response?->json('id');
    }

    public function deleteWebhook(Connection $connection, string $repository, string $hookId): void
    {
        $this->send($connection, 'DELETE', '/repos/'.$this->path($repository).'/hooks/'.rawurlencode($hookId), nullOn404: true);
    }

    public function commentOnPullRequest(Connection $connection, string $repository, int $number, string $body, ?string $commentId = null): ?string
    {
        // Pull request comments are issue comments.
        if ($commentId !== null) {
            $response = $this->send($connection, 'PATCH', '/repos/'.$this->path($repository).'/issues/comments/'.rawurlencode($commentId), body: ['body' => $body], nullOn404: true);

            return $response === null ? null : (string) $response->json('id');
        }

        $response = $this->send($connection, 'POST', '/repos/'.$this->path($repository).'/issues/'.$number.'/comments', body: ['body' => $body]);

        return (string) $response?->json('id');
    }

    public function setCommitStatus(Connection $connection, string $repository, string $sha, string $state, string $context, string $description, ?string $url = null): void
    {
        $this->send($connection, 'POST', '/repos/'.$this->path($repository).'/statuses/'.rawurlencode($sha), body: array_filter([
            'state' => $state,
            'context' => $context,
            'description' => mb_substr($description, 0, 140),
            'target_url' => $url,
        ], fn ($v) => $v !== null));
    }

    public function sshUrl(Connection $connection, string $repository): string
    {
        return 'git@'.self::host($this->webUrl($connection)).':'.$repository.'.git';
    }

    public function httpsUrl(Connection $connection, string $repository): string
    {
        return $this->webUrl($connection).'/'.$repository.'.git';
    }

    public function httpsCredentials(Connection $connection): array
    {
        return ['x-access-token', $this->tokens->token($connection)];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return iterable<array<int|string, mixed>>
     */
    private function pages(Connection $connection, string $path, array $query): iterable
    {
        $url = $path;

        for ($page = 0; $page < $this->maxPages() && $url !== null; $page++) {
            $response = $this->send($connection, 'GET', $url, $page === 0 ? $query : []);

            if ($response === null) {
                return;
            }

            yield (array) $response->json();

            $url = self::nextLink($response->header('Link'));
        }
    }

    public static function nextLink(string $header): ?string
    {
        foreach (explode(',', $header) as $part) {
            if (preg_match('/<([^>]+)>;\s*rel="next"/', $part, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $repo
     */
    private function toRepository(array $repo): RepositoryData
    {
        return new RepositoryData(
            fullName: (string) $repo['full_name'],
            defaultBranch: (string) ($repo['default_branch'] ?? 'main'),
            private: (bool) ($repo['private'] ?? true),
            sshUrl: (string) ($repo['ssh_url'] ?? ''),
            httpsUrl: (string) ($repo['clone_url'] ?? ''),
            webUrl: $repo['html_url'] ?? null,
        );
    }

    private function path(string $repository): string
    {
        if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repository) !== 1) {
            throw SourceControlException::provider('GitHub', "Invalid repository name \"{$repository}\" (expected owner/name).");
        }

        return $repository;
    }

    private function apiUrl(Connection $connection): string
    {
        return $connection->base_url
            ? rtrim($connection->base_url, '/').'/api/v3'
            : rtrim((string) config('source_control.github.api_url'), '/');
    }

    private function webUrl(Connection $connection): string
    {
        return rtrim($connection->base_url ?: (string) config('source_control.github.url'), '/');
    }
}
