<?php

namespace Falak\SourceControl\Infrastructure\Webhooks;

use DateTimeImmutable;
use Exception;
use Falak\SourceControl\Contracts\Data\CommitData;
use Falak\SourceControl\Contracts\Data\PullRequestData;
use Falak\SourceControl\Contracts\ProviderType;
use Falak\SourceControl\Infrastructure\Providers\BitbucketClient;
use Illuminate\Http\Request;

/**
 * Signature verification and push / pull request parsing for inbound webhooks, per provider.
 *
 * - GitHub: X-Hub-Signature-256 = "sha256=" . HMAC-SHA256(body, secret)
 * - GitLab: X-Gitlab-Token = secret
 * - Bitbucket Cloud: X-Hub-Signature = "sha256=" . HMAC-SHA256(body, secret)
 * - Custom (Gitea / Forgejo / generic): X-Gitea-Signature / X-Forgejo-Signature (hex HMAC),
 *   X-Hub-Signature-256 ("sha256=" HMAC) or X-Falak-Token = secret
 */
class WebhookPayloads
{
    public function verify(ProviderType $provider, Request $request, string $secret): bool
    {
        $body = $request->getContent();
        $hmac = hash_hmac('sha256', $body, $secret);

        return match ($provider) {
            ProviderType::GitHub => self::equals('sha256='.$hmac, $request->header('X-Hub-Signature-256')),
            ProviderType::GitLab => self::equals($secret, $request->header('X-Gitlab-Token')),
            ProviderType::Bitbucket => self::equals('sha256='.$hmac, $request->header('X-Hub-Signature')),
            ProviderType::Custom => self::equals($hmac, $request->header('X-Gitea-Signature'))
                || self::equals($hmac, $request->header('X-Forgejo-Signature'))
                || self::equals('sha256='.$hmac, $request->header('X-Hub-Signature-256'))
                || self::equals($secret, $request->header('X-Falak-Token')),
        };
    }

    /** Provider "ping" / test deliveries that should be acknowledged but not processed. */
    public function isPing(ProviderType $provider, Request $request): bool
    {
        return match ($provider) {
            ProviderType::GitHub => $request->header('X-GitHub-Event') === 'ping',
            ProviderType::Bitbucket => $request->header('X-Event-Key') === 'diagnostics:ping',
            ProviderType::GitLab => false,
            ProviderType::Custom => in_array($request->header('X-GitHub-Event') ?? $request->header('X-Gitea-Event'), ['ping'], true),
        };
    }

    /**
     * Branch pushes in the delivery (tag pushes, branch deletions and non-push events yield nothing).
     *
     * @return list<ParsedPush>
     */
    public function pushes(ProviderType $provider, Request $request): array
    {
        $payload = (array) $request->json()->all();

        return match ($provider) {
            ProviderType::GitHub => $request->header('X-GitHub-Event') === 'push' ? self::githubLike($payload) : [],
            ProviderType::GitLab => in_array($request->header('X-Gitlab-Event'), ['Push Hook', 'System Hook'], true) && ($payload['object_kind'] ?? 'push') === 'push' ? self::gitlab($payload) : [],
            ProviderType::Bitbucket => $request->header('X-Event-Key') === 'repo:push' ? self::bitbucket($payload) : [],
            ProviderType::Custom => in_array($request->header('X-Gitea-Event') ?? $request->header('X-Forgejo-Event') ?? $request->header('X-GitHub-Event') ?? 'push', ['push'], true) ? self::githubLike($payload) : [],
        };
    }

    /**
     * GitHub, Gitea, Forgejo and generic payloads: {ref, before, after, deleted, head_commit|commits, pusher}.
     *
     * @param  array<string, mixed>  $payload
     * @return list<ParsedPush>
     */
    private static function githubLike(array $payload): array
    {
        $branch = self::branch($payload['ref'] ?? null);
        $after = (string) ($payload['after'] ?? '');

        if ($branch === null || ($payload['deleted'] ?? false) === true || self::isZero($after)) {
            return [];
        }

        $commits = (array) ($payload['commits'] ?? []);
        $head = is_array($payload['head_commit'] ?? null) ? $payload['head_commit'] : (collect($commits)->firstWhere('id', $after) ?? end($commits));
        $head = is_array($head) ? $head : [];

        return [new ParsedPush(
            branch: $branch,
            commit: new CommitData(
                sha: $after !== '' ? $after : (string) ($head['id'] ?? ''),
                message: (string) ($head['message'] ?? ''),
                authorName: $head['author']['name'] ?? null,
                authorEmail: $head['author']['email'] ?? null,
                committedAt: self::date($head['timestamp'] ?? null),
                url: $head['url'] ?? null,
            ),
            pusher: $payload['pusher']['name'] ?? $payload['pusher']['login'] ?? $payload['pusher']['username'] ?? $payload['sender']['login'] ?? null,
            beforeSha: self::isZero((string) ($payload['before'] ?? '')) ? null : ($payload['before'] ?? null),
            // GitHub lists at most 20 commits (2048 with `size`): beyond that the files are unknown.
            changedPaths: count($commits) < 20 && (int) ($payload['size'] ?? count($commits)) <= count($commits) && ! self::isZero((string) ($payload['before'] ?? '')) ? self::changedPaths($commits) : null,
        )];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<ParsedPush>
     */
    private static function gitlab(array $payload): array
    {
        $branch = self::branch($payload['ref'] ?? null);
        $after = (string) ($payload['checkout_sha'] ?? $payload['after'] ?? '');

        if ($branch === null || self::isZero($after) || $after === '') {
            return [];
        }

        $head = collect((array) ($payload['commits'] ?? []))->firstWhere('id', $after) ?? [];

        return [new ParsedPush(
            branch: $branch,
            commit: new CommitData(
                sha: $after,
                message: (string) ($head['message'] ?? $head['title'] ?? ''),
                authorName: $head['author']['name'] ?? null,
                authorEmail: $head['author']['email'] ?? null,
                committedAt: self::date($head['timestamp'] ?? null),
                url: $head['url'] ?? null,
            ),
            pusher: $payload['user_username'] ?? $payload['user_name'] ?? null,
            beforeSha: self::isZero((string) ($payload['before'] ?? '')) ? null : ($payload['before'] ?? null),
            // GitLab lists at most 20 commits; total_commits_count says when some were left out.
            changedPaths: (int) ($payload['total_commits_count'] ?? 0) <= count((array) ($payload['commits'] ?? [])) && ! self::isZero((string) ($payload['before'] ?? ''))
                ? self::changedPaths((array) ($payload['commits'] ?? [])) : null,
        )];
    }

    /**
     * Files the commits of a push added, modified or removed, sorted (`commits[].added|modified|removed`).
     *
     * @param  array<int|string, mixed>  $commits
     * @return list<string>
     */
    private static function changedPaths(array $commits): array
    {
        $paths = [];

        foreach ($commits as $commit) {
            foreach (['added', 'modified', 'removed'] as $kind) {
                foreach ((array) (is_array($commit) ? ($commit[$kind] ?? []) : []) as $path) {
                    if (is_string($path) && $path !== '') {
                        $paths[$path] = true;
                    }
                }
            }
        }

        $paths = array_keys($paths);
        sort($paths);

        return $paths;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<ParsedPush>
     */
    private static function bitbucket(array $payload): array
    {
        $pushes = [];
        $pusher = $payload['actor']['nickname'] ?? $payload['actor']['display_name'] ?? null;

        foreach ((array) ($payload['push']['changes'] ?? []) as $change) {
            $new = $change['new'] ?? null;

            if (! is_array($new) || ($new['type'] ?? null) !== 'branch' || ! is_array($new['target'] ?? null) || ($change['closed'] ?? false) === true) {
                continue;
            }

            $pushes[] = new ParsedPush(
                branch: (string) $new['name'],
                commit: BitbucketClient::toCommit($new['target']),
                pusher: $pusher,
                beforeSha: $change['old']['target']['hash'] ?? null,
            );
        }

        return $pushes;
    }

    /**
     * The pull request event of a delivery (null: not one, or one previews don't act on, like a title edit on GitHub).
     *
     * - GitHub `pull_request` opened / reopened / synchronize / closed, `issue_comment` created on a pull request;
     * - GitLab `Merge Request Hook` open / reopen / update with new commits (`oldrev`) / close / merge, `Note Hook` on
     *   a merge request;
     * - Bitbucket `pullrequest:created|updated|fulfilled|rejected|comment_created`.
     *
     * Custom git servers have no API to comment or report statuses with: their deliveries are ignored.
     */
    public function pullRequestEvent(ProviderType $provider, Request $request): ?ParsedPullRequestEvent
    {
        $payload = (array) $request->json()->all();

        try {
            return match ($provider) {
                ProviderType::GitHub => self::githubPullRequest((string) $request->header('X-GitHub-Event'), $payload),
                ProviderType::GitLab => self::gitlabMergeRequest((string) $request->header('X-Gitlab-Event'), $payload),
                ProviderType::Bitbucket => self::bitbucketPullRequest((string) $request->header('X-Event-Key'), $payload),
                ProviderType::Custom => null,
            };
        } catch (\TypeError) {
            // A malformed payload (a field of the wrong type): not an event.
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function githubPullRequest(string $event, array $payload): ?ParsedPullRequestEvent
    {
        $action = (string) ($payload['action'] ?? '');
        $repository = (string) ($payload['repository']['full_name'] ?? '');

        if ($event === 'issue_comment') {
            $issue = (array) ($payload['issue'] ?? []);

            if ($action !== 'created' || ! isset($issue['pull_request']) || $repository === '' || ! isset($issue['number'])) {
                return null;
            }

            return new ParsedPullRequestEvent(
                kind: ParsedPullRequestEvent::COMMENTED,
                repository: $repository,
                number: (int) $issue['number'],
                commentId: (string) ($payload['comment']['id'] ?? ''),
                commentAuthor: $payload['comment']['user']['login'] ?? null,
                commentBody: (string) ($payload['comment']['body'] ?? ''),
                commentAuthorId: isset($payload['comment']['user']['id']) ? (string) $payload['comment']['user']['id'] : null,
                commentedAt: self::date($payload['comment']['created_at'] ?? null),
            );
        }

        $kind = match ($action) {
            'opened', 'reopened' => ParsedPullRequestEvent::OPENED,
            'synchronize' => ParsedPullRequestEvent::UPDATED,
            'closed' => ParsedPullRequestEvent::CLOSED,
            default => null,
        };
        $pr = (array) ($payload['pull_request'] ?? []);

        if ($event !== 'pull_request' || $kind === null || $repository === '' || ! isset($pr['number'], $pr['head']['sha'])) {
            return null;
        }

        $source = $pr['head']['repo']['full_name'] ?? null;

        return new ParsedPullRequestEvent($kind, $repository, (int) $pr['number'], new PullRequestData(
            repository: $repository,
            number: (int) $pr['number'],
            title: (string) ($pr['title'] ?? ''),
            url: $pr['html_url'] ?? null,
            headBranch: (string) ($pr['head']['ref'] ?? ''),
            headSha: (string) $pr['head']['sha'],
            baseBranch: (string) ($pr['base']['ref'] ?? ''),
            author: $pr['user']['login'] ?? null,
            // A deleted fork has no head repository: still untrusted.
            isFork: $source === null || strcasecmp((string) $source, $repository) !== 0,
            sourceRepository: $source,
        ), merged: (bool) ($pr['merged'] ?? false));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function gitlabMergeRequest(string $event, array $payload): ?ParsedPullRequestEvent
    {
        $repository = (string) ($payload['project']['path_with_namespace'] ?? '');
        $attributes = (array) ($payload['object_attributes'] ?? []);

        if ($event === 'Note Hook') {
            $mr = (array) ($payload['merge_request'] ?? []);

            // Edits of a note are not new comments (an approval can't be edited into an old note).
            if (($attributes['noteable_type'] ?? null) !== 'MergeRequest' || $repository === '' || ! isset($mr['iid'])
                || (isset($attributes['action']) && $attributes['action'] !== 'create')) {
                return null;
            }

            return new ParsedPullRequestEvent(
                kind: ParsedPullRequestEvent::COMMENTED,
                repository: $repository,
                number: (int) $mr['iid'],
                commentId: (string) ($attributes['id'] ?? ''),
                commentAuthor: $payload['user']['username'] ?? null,
                commentBody: (string) ($attributes['note'] ?? ''),
                commentAuthorId: isset($payload['user']['id']) ? (string) $payload['user']['id'] : null,
                commentedAt: self::date($attributes['created_at'] ?? null),
            );
        }

        if ($event !== 'Merge Request Hook' || ($payload['object_kind'] ?? null) !== 'merge_request' || $repository === '' || ! isset($attributes['iid'])) {
            return null;
        }

        $action = (string) ($attributes['action'] ?? '');
        $kind = match (true) {
            in_array($action, ['open', 'reopen'], true) => ParsedPullRequestEvent::OPENED,
            // Updates without new commits (title, labels, assignees) carry no oldrev.
            $action === 'update' && isset($attributes['oldrev']) => ParsedPullRequestEvent::UPDATED,
            in_array($action, ['close', 'merge'], true) => ParsedPullRequestEvent::CLOSED,
            default => null,
        };
        $sha = (string) ($attributes['last_commit']['id'] ?? '');

        if ($kind === null || $sha === '') {
            return null;
        }

        $source = $attributes['source']['path_with_namespace'] ?? null;

        return new ParsedPullRequestEvent($kind, $repository, (int) $attributes['iid'], new PullRequestData(
            repository: $repository,
            number: (int) $attributes['iid'],
            title: (string) ($attributes['title'] ?? ''),
            url: $attributes['url'] ?? null,
            headBranch: (string) ($attributes['source_branch'] ?? ''),
            headSha: $sha,
            baseBranch: (string) ($attributes['target_branch'] ?? ''),
            author: $payload['user']['username'] ?? null,
            isFork: ($attributes['source_project_id'] ?? null) !== ($attributes['target_project_id'] ?? null),
            sourceRepository: $source,
        ), merged: $action === 'merge');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function bitbucketPullRequest(string $event, array $payload): ?ParsedPullRequestEvent
    {
        $pr = (array) ($payload['pullrequest'] ?? []);
        $repository = (string) ($payload['repository']['full_name'] ?? $pr['destination']['repository']['full_name'] ?? '');

        if ($repository === '' || ! isset($pr['id'])) {
            return null;
        }

        if ($event === 'pullrequest:comment_created') {
            return new ParsedPullRequestEvent(
                kind: ParsedPullRequestEvent::COMMENTED,
                repository: $repository,
                number: (int) $pr['id'],
                commentId: (string) ($payload['comment']['id'] ?? ''),
                commentAuthor: $payload['comment']['user']['nickname'] ?? $payload['actor']['nickname'] ?? null,
                commentBody: (string) ($payload['comment']['content']['raw'] ?? ''),
                commentAuthorId: (string) ($payload['comment']['user']['account_id'] ?? $payload['comment']['user']['uuid'] ?? '') ?: null,
                commentedAt: self::date($payload['comment']['created_on'] ?? null),
            );
        }

        $kind = match ($event) {
            'pullrequest:created' => ParsedPullRequestEvent::OPENED,
            'pullrequest:updated' => ParsedPullRequestEvent::UPDATED,
            'pullrequest:fulfilled', 'pullrequest:rejected' => ParsedPullRequestEvent::CLOSED,
            default => null,
        };
        $sha = (string) ($pr['source']['commit']['hash'] ?? '');

        if ($kind === null || $sha === '') {
            return null;
        }

        $source = $pr['source']['repository']['full_name'] ?? null;

        return new ParsedPullRequestEvent($kind, $repository, (int) $pr['id'], new PullRequestData(
            repository: $repository,
            number: (int) $pr['id'],
            title: (string) ($pr['title'] ?? ''),
            url: $pr['links']['html']['href'] ?? null,
            headBranch: (string) ($pr['source']['branch']['name'] ?? ''),
            headSha: $sha,
            baseBranch: (string) ($pr['destination']['branch']['name'] ?? ''),
            author: $pr['author']['nickname'] ?? $pr['author']['display_name'] ?? null,
            isFork: $source === null || strcasecmp((string) $source, $repository) !== 0,
            sourceRepository: $source,
        ), merged: $event === 'pullrequest:fulfilled');
    }

    private static function branch(mixed $ref): ?string
    {
        return is_string($ref) && str_starts_with($ref, 'refs/heads/') ? substr($ref, 11) : null;
    }

    private static function isZero(string $sha): bool
    {
        return $sha !== '' && trim($sha, '0') === '';
    }

    private static function equals(string $expected, ?string $given): bool
    {
        return $given !== null && $given !== '' && hash_equals($expected, $given);
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        try {
            return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
        } catch (Exception) {
            return null;
        }
    }
}
