<?php

namespace Falak\SourceControl\Infrastructure\Providers;

use Falak\SourceControl\Contracts\Data\BranchData;
use Falak\SourceControl\Contracts\Data\CommitData;
use Falak\SourceControl\Contracts\Data\RepositoryData;
use Falak\SourceControl\Domain\Models\Connection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * GitLab REST v4 (gitlab.com or self-hosted through the connection's base URL).
 * Works with OAuth tokens and personal / group / project access tokens.
 */
class GitLabClient extends HttpProviderClient
{
    protected function label(): string
    {
        return 'GitLab';
    }

    protected function request(Connection $connection): PendingRequest
    {
        return Http::baseUrl($this->webUrl($connection).'/api/v4')->withToken($this->tokens->token($connection));
    }

    public function account(Connection $connection): string
    {
        return (string) ($this->json($connection, '/user')['username'] ?? '');
    }

    public function accountId(Connection $connection): ?string
    {
        return (string) ($this->json($connection, '/user')['id'] ?? '') ?: null;
    }

    public function repositories(Connection $connection, ?string $search = null): array
    {
        $query = array_filter(['membership' => 'true', 'simple' => 'true', 'order_by' => 'last_activity_at', 'per_page' => 100, 'search' => $search, 'search_namespaces' => $search ? 'true' : null]);

        $repositories = [];

        foreach ($this->pages($connection, '/projects', $query) as $page) {
            foreach ($page as $project) {
                $repositories[] = $this->toRepository($project);
            }
        }

        return $repositories;
    }

    public function repository(Connection $connection, string $repository): ?RepositoryData
    {
        $project = $this->json($connection, '/projects/'.$this->id($repository), nullOn404: true);

        return $project === null ? null : $this->toRepository($project);
    }

    public function branches(Connection $connection, string $repository): array
    {
        $branches = [];

        foreach ($this->pages($connection, '/projects/'.$this->id($repository).'/repository/branches', ['per_page' => 100]) as $page) {
            foreach ($page as $branch) {
                $branches[] = new BranchData((string) $branch['name'], $branch['commit']['id'] ?? null, (bool) ($branch['protected'] ?? false));
            }
        }

        return $branches;
    }

    public function latestCommit(Connection $connection, string $repository, string $branch): ?CommitData
    {
        $body = $this->json($connection, '/projects/'.$this->id($repository).'/repository/branches/'.rawurlencode($branch), nullOn404: true);

        return isset($body['commit']) && is_array($body['commit']) ? $this->toCommit($body['commit']) : null;
    }

    public function commit(Connection $connection, string $repository, string $sha): ?CommitData
    {
        $commit = $this->json($connection, '/projects/'.$this->id($repository).'/repository/commits/'.rawurlencode($sha), nullOn404: true);

        return $commit === null ? null : $this->toCommit($commit);
    }

    public function file(Connection $connection, string $repository, string $ref, string $path, int $maxBytes): ?string
    {
        $url = '/projects/'.$this->id($repository).'/repository/files/'.rawurlencode(trim($path, '/'));
        // HEAD first: the size comes in a header, so large files are never downloaded.
        $head = $this->send($connection, 'HEAD', $url, ['ref' => $ref], nullOn404: true);

        if ($head === null) {
            return null;
        }

        if ((int) $head->header('X-Gitlab-Size') > $maxBytes) {
            throw $this->tooLarge($path, $maxBytes);
        }

        $body = $this->json($connection, $url, ['ref' => $ref], nullOn404: true);

        if ($body === null || ! isset($body['content'])) {
            return null;
        }

        if ((int) ($body['size'] ?? 0) > $maxBytes) {
            throw $this->tooLarge($path, $maxBytes);
        }

        $content = base64_decode((string) $body['content'], true);

        return $content === false ? null : $content;
    }

    public function exists(Connection $connection, string $repository, string $ref, string $path): bool
    {
        $project = '/projects/'.$this->id($repository).'/repository';

        if ($this->send($connection, 'HEAD', $project.'/files/'.rawurlencode(trim($path, '/')), ['ref' => $ref], nullOn404: true) !== null) {
            return true;
        }

        // A directory: its listing is not empty.
        return ($this->json($connection, $project.'/tree', ['ref' => $ref, 'path' => trim($path, '/'), 'per_page' => 1], nullOn404: true) ?? []) !== [];
    }

    public function tree(Connection $connection, string $repository, string $ref, int $limit, ?bool &$truncated = null): array
    {
        $truncated = false;
        $paths = [];

        foreach ($this->pages($connection, '/projects/'.$this->id($repository).'/repository/tree', ['ref' => $ref, 'recursive' => 'true', 'per_page' => 100]) as $page) {
            foreach ($page as $entry) {
                if (is_array($entry) && ($entry['type'] ?? null) === 'blob' && isset($entry['path'])) {
                    $paths[] = (string) $entry['path'];

                    if (count($paths) >= $limit) {
                        $truncated = true;

                        return $paths;
                    }
                }
            }
        }

        return $paths;
    }

    public function addDeployKey(Connection $connection, string $repository, string $title, string $publicKey): string
    {
        $response = $this->send($connection, 'POST', '/projects/'.$this->id($repository).'/deploy_keys', body: ['title' => $title, 'key' => $publicKey, 'can_push' => false]);

        return (string) $response?->json('id');
    }

    public function removeDeployKey(Connection $connection, string $repository, string $keyId): void
    {
        $this->send($connection, 'DELETE', '/projects/'.$this->id($repository).'/deploy_keys/'.rawurlencode($keyId), nullOn404: true);
    }

    public function createWebhook(Connection $connection, string $repository, string $url, string $secret): string
    {
        $response = $this->send($connection, 'POST', '/projects/'.$this->id($repository).'/hooks', body: [
            'url' => $url,
            'token' => $secret,
            'push_events' => true,
            'tag_push_events' => false,
            'merge_requests_events' => true,
            'note_events' => true,
            'enable_ssl_verification' => true,
        ]);

        return (string) $response?->json('id');
    }

    public function deleteWebhook(Connection $connection, string $repository, string $hookId): void
    {
        $this->send($connection, 'DELETE', '/projects/'.$this->id($repository).'/hooks/'.rawurlencode($hookId), nullOn404: true);
    }

    public function commentOnPullRequest(Connection $connection, string $repository, int $number, string $body, ?string $commentId = null): ?string
    {
        $notes = '/projects/'.$this->id($repository).'/merge_requests/'.$number.'/notes';

        if ($commentId !== null) {
            $response = $this->send($connection, 'PUT', $notes.'/'.rawurlencode($commentId), body: ['body' => $body], nullOn404: true);

            return $response === null ? null : (string) $response->json('id');
        }

        return (string) $this->send($connection, 'POST', $notes, body: ['body' => $body])?->json('id');
    }

    public function setCommitStatus(Connection $connection, string $repository, string $sha, string $state, string $context, string $description, ?string $url = null): void
    {
        $this->send($connection, 'POST', '/projects/'.$this->id($repository).'/statuses/'.rawurlencode($sha), body: array_filter([
            'state' => match ($state) {
                'success' => 'success',
                'failure' => 'failed',
                default => 'running',
            },
            'name' => $context,
            'description' => mb_substr($description, 0, 255),
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
        return ['oauth2', $this->tokens->token($connection)];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return iterable<list<array<string, mixed>>>
     */
    private function pages(Connection $connection, string $path, array $query): iterable
    {
        $page = 1;

        for ($fetched = 0; $fetched < $this->maxPages(); $fetched++) {
            $response = $this->send($connection, 'GET', $path, [...$query, 'page' => $page]);

            if ($response === null) {
                return;
            }

            yield (array) $response->json();

            $next = $response->header('X-Next-Page');

            if (! is_numeric($next)) {
                return;
            }

            $page = (int) $next;
        }
    }

    /**
     * @param  array<string, mixed>  $project
     */
    private function toRepository(array $project): RepositoryData
    {
        return new RepositoryData(
            fullName: (string) $project['path_with_namespace'],
            defaultBranch: (string) ($project['default_branch'] ?? 'main'),
            private: ($project['visibility'] ?? 'private') !== 'public',
            sshUrl: (string) ($project['ssh_url_to_repo'] ?? ''),
            httpsUrl: (string) ($project['http_url_to_repo'] ?? ''),
            webUrl: $project['web_url'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $commit
     */
    private function toCommit(array $commit): CommitData
    {
        return new CommitData(
            sha: (string) $commit['id'],
            message: (string) ($commit['message'] ?? $commit['title'] ?? ''),
            authorName: $commit['author_name'] ?? null,
            authorEmail: $commit['author_email'] ?? null,
            committedAt: self::date($commit['committed_date'] ?? $commit['created_at'] ?? null),
            url: $commit['web_url'] ?? null,
        );
    }

    /** Project path as a URL-encoded id ("group/sub/project" → "group%2Fsub%2Fproject"). */
    private function id(string $repository): string
    {
        return rawurlencode(trim($repository, '/'));
    }

    private function webUrl(Connection $connection): string
    {
        return rtrim($connection->base_url ?: (string) config('source_control.gitlab.url'), '/');
    }
}
