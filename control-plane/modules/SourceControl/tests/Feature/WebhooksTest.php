<?php

use Falak\Identity\Contracts\Role;
use Falak\SourceControl\Contracts\ProviderType;
use Falak\SourceControl\Domain\Models\Push;
use Falak\SourceControl\Events\PushReceived;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [, $this->organization] = memberOf(null, Role::Owner);
    Event::fake([PushReceived::class]);
});

describe('GitHub', function () {
    beforeEach(function () {
        $this->webhook = sc_webhook(sc_connection($this->organization->id));
    });

    it('accepts signed pushes and announces them', function () {
        $payload = sc_github_push();

        sc_post_webhook($this->webhook, $payload, ['X-GitHub-Event' => 'push', 'X-Hub-Signature-256' => 'sha256='.sc_sign($payload)])
            ->assertStatus(202)->assertJson(['received' => 1]);

        Event::assertDispatched(PushReceived::class, fn (PushReceived $e) => $e->organizationId === $this->organization->id
            && $e->provider === 'github'
            && $e->repository === 'acme/shop'
            && $e->branch === 'main'
            && $e->commit->sha === str_repeat('b', 40)
            && $e->commit->title() === 'Fix checkout'
            && $e->commit->authorName === 'Ada'
            && $e->pusher === 'ada'
            && $e->beforeSha === str_repeat('a', 40));

        $push = Push::query()->sole();
        expect($push->branch)->toBe('main')
            ->and($push->author_email)->toBe('ada@example.com')
            ->and($this->webhook->refresh()->last_delivery_at)->not->toBeNull();
    });

    it('announces the files a push changed, or null when the payload can\'t say', function (array $overrides, ?array $expected) {
        $payload = sc_github_push(overrides: $overrides);

        sc_post_webhook($this->webhook, $payload, ['X-GitHub-Event' => 'push', 'X-Hub-Signature-256' => 'sha256='.sc_sign($payload)])->assertStatus(202);

        Event::assertDispatched(PushReceived::class, fn (PushReceived $e) => $e->changedPaths === $expected);
    })->with([
        'listed' => [['commits' => [['id' => 'c1', 'added' => ['b.txt'], 'modified' => ['apps/web/a.php'], 'removed' => []], ['id' => str_repeat('b', 40), 'added' => [], 'modified' => ['b.txt'], 'removed' => ['old']]]], ['apps/web/a.php', 'b.txt', 'old']],
        'force push' => [['forced' => true, 'commits' => [['id' => 'c1', 'modified' => ['a']]]], null],
        'no commits' => [['commits' => []], null],
        'twenty commits' => [['commits' => array_map(fn ($i) => ['id' => "c{$i}", 'modified' => ['a']], range(1, 20))], null],
        'truncated' => [['size' => 30, 'commits' => [['id' => 'c1', 'modified' => ['a']]]], null],
    ]);

    it('rejects invalid and missing signatures', function (array $headers) {
        sc_post_webhook($this->webhook, sc_github_push(), ['X-GitHub-Event' => 'push', ...$headers])->assertUnauthorized();

        Event::assertNotDispatched(PushReceived::class);
        expect(Push::query()->count())->toBe(0);
    })->with([
        'missing' => [[]],
        'wrong secret' => [['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', 'x', 'nope')]],
        'sha1 only' => [['X-Hub-Signature' => 'sha1=abc']],
    ]);

    it('answers pings', function () {
        $payload = ['zen' => 'Keep it logically awesome.', 'hook_id' => 1];

        sc_post_webhook($this->webhook, $payload, ['X-GitHub-Event' => 'ping', 'X-Hub-Signature-256' => 'sha256='.sc_sign($payload)])
            ->assertOk()->assertJson(['ok' => true]);

        Event::assertNotDispatched(PushReceived::class);
    });

    it('ignores tags, branch deletions and other events', function (array $payload, string $event) {
        sc_post_webhook($this->webhook, $payload, ['X-GitHub-Event' => $event, 'X-Hub-Signature-256' => 'sha256='.sc_sign($payload)])
            ->assertStatus(202)->assertJson(['received' => 0]);

        Event::assertNotDispatched(PushReceived::class);
    })->with([
        'tag' => fn () => [sc_github_push('refs/tags/v1.0.0'), 'push'],
        'deletion' => fn () => [sc_github_push(overrides: ['deleted' => true, 'after' => str_repeat('0', 40), 'head_commit' => null]), 'push'],
        'pull request' => fn () => [['action' => 'opened'], 'pull_request'],
    ]);
});

it('verifies GitLab tokens', function () {
    $webhook = sc_webhook(sc_connection($this->organization->id, ProviderType::GitLab, 'token', ['token' => 'x']), 'acme/platform/api');
    $payload = [
        'object_kind' => 'push',
        'ref' => 'refs/heads/develop',
        'before' => str_repeat('0', 40),
        'after' => 'c0ffee',
        'checkout_sha' => 'c0ffee',
        'user_username' => 'ada',
        'commits' => [['id' => 'older', 'message' => 'old'], ['id' => 'c0ffee', 'message' => "Add API\n", 'timestamp' => '2026-09-26T10:00:00Z', 'url' => 'https://gitlab.com/c', 'author' => ['name' => 'Ada', 'email' => 'ada@example.com']]],
    ];

    sc_post_webhook($webhook, $payload, ['X-Gitlab-Event' => 'Push Hook', 'X-Gitlab-Token' => 'wrong'])->assertUnauthorized();
    sc_post_webhook($webhook, $payload, ['X-Gitlab-Event' => 'Push Hook'])->assertUnauthorized();
    sc_post_webhook($webhook, $payload, ['X-Gitlab-Event' => 'Push Hook', 'X-Gitlab-Token' => 'topsecret'])->assertStatus(202);

    Event::assertDispatched(PushReceived::class, fn (PushReceived $e) => $e->branch === 'develop' && $e->commit->sha === 'c0ffee' && $e->commit->title() === 'Add API' && $e->pusher === 'ada' && $e->beforeSha === null && $e->repository === 'acme/platform/api');

    // Branch deletion: checkout_sha null, after all zeros.
    sc_post_webhook($webhook, [...$payload, 'after' => str_repeat('0', 40), 'checkout_sha' => null, 'commits' => []], ['X-Gitlab-Event' => 'Push Hook', 'X-Gitlab-Token' => 'topsecret'])->assertJson(['received' => 0]);
    // Tag push hook.
    sc_post_webhook($webhook, [...$payload, 'object_kind' => 'tag_push', 'ref' => 'refs/tags/v1'], ['X-Gitlab-Event' => 'Tag Push Hook', 'X-Gitlab-Token' => 'topsecret'])->assertJson(['received' => 0]);

    Event::assertDispatchedTimes(PushReceived::class, 1);
});

it('verifies Bitbucket signatures and handles several branch changes', function () {
    $webhook = sc_webhook(sc_connection($this->organization->id, ProviderType::Bitbucket, 'basic', ['username' => 'a', 'password' => 'b']));
    $target = fn (string $hash) => ['hash' => $hash, 'message' => "Msg {$hash}\n", 'date' => '2026-09-26T10:00:00+00:00', 'author' => ['raw' => 'Ada <ada@example.com>'], 'links' => ['html' => ['href' => "https://bitbucket.org/acme/shop/commits/{$hash}"]]];
    $payload = [
        'actor' => ['nickname' => 'ada'],
        'push' => ['changes' => [
            ['new' => ['type' => 'branch', 'name' => 'main', 'target' => $target('aaa')], 'old' => ['type' => 'branch', 'name' => 'main', 'target' => ['hash' => 'zzz']], 'closed' => false],
            ['new' => ['type' => 'tag', 'name' => 'v1', 'target' => $target('bbb')], 'old' => null],
            ['new' => null, 'old' => ['type' => 'branch', 'name' => 'gone', 'target' => ['hash' => 'ccc']], 'closed' => true],
            ['new' => ['type' => 'branch', 'name' => 'feature', 'target' => $target('ddd')], 'old' => null],
        ]],
    ];

    sc_post_webhook($webhook, $payload, ['X-Event-Key' => 'repo:push', 'X-Hub-Signature' => 'sha256=deadbeef'])->assertUnauthorized();
    sc_post_webhook($webhook, $payload, ['X-Event-Key' => 'repo:push'])->assertUnauthorized();
    sc_post_webhook($webhook, $payload, ['X-Event-Key' => 'repo:push', 'X-Hub-Signature' => 'sha256='.sc_sign($payload)])->assertStatus(202)->assertJson(['received' => 2]);

    Event::assertDispatched(PushReceived::class, fn (PushReceived $e) => $e->branch === 'main' && $e->commit->sha === 'aaa' && $e->commit->authorEmail === 'ada@example.com' && $e->beforeSha === 'zzz' && $e->pusher === 'ada');
    Event::assertDispatched(PushReceived::class, fn (PushReceived $e) => $e->branch === 'feature' && $e->beforeSha === null);
    Event::assertDispatchedTimes(PushReceived::class, 2);
});

it('verifies custom (Gitea / Forgejo / generic) webhooks', function (string $style) {
    $webhook = sc_webhook(sc_connection($this->organization->id, ProviderType::Custom, 'none', []), 'git@git.acme.test:acme/shop.git');
    $payload = sc_github_push('refs/heads/main');
    $headers = match ($style) {
        'gitea' => ['X-Gitea-Event' => 'push', 'X-Gitea-Signature' => sc_sign($payload)],
        'forgejo' => ['X-Forgejo-Event' => 'push', 'X-Forgejo-Signature' => sc_sign($payload)],
        'github' => ['X-Hub-Signature-256' => 'sha256='.sc_sign($payload)],
        'token' => ['X-Falak-Token' => 'topsecret'],
    };

    sc_post_webhook($webhook, $payload, $headers)->assertStatus(202)->assertJson(['received' => 1]);

    Event::assertDispatched(PushReceived::class, fn (PushReceived $e) => $e->provider === 'custom' && $e->repository === 'git@git.acme.test:acme/shop.git');
})->with(['gitea', 'forgejo', 'github', 'token']);

it('rejects unsigned custom webhooks', function () {
    $webhook = sc_webhook(sc_connection($this->organization->id, ProviderType::Custom, 'none', []));

    sc_post_webhook($webhook, sc_github_push(), ['X-Gitea-Signature' => 'nope', 'X-Falak-Token' => 'wrong'])->assertUnauthorized();
});

it('returns 404 for unknown webhooks', function () {
    $this->postJson('/api/webhooks/source-control/01JUNKNOWN0000000000000000', [])->assertNotFound();
});

it('rate limits deliveries per webhook', function () {
    config(['source_control.webhook_rate_limit' => 2]);
    $webhook = sc_webhook(sc_connection($this->organization->id));

    sc_post_webhook($webhook, [], [])->assertUnauthorized();
    sc_post_webhook($webhook, [], [])->assertUnauthorized();
    sc_post_webhook($webhook, [], [])->assertStatus(429);
});
