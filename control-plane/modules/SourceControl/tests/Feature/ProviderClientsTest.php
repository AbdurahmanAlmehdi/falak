<?php

use Falak\Identity\Contracts\Role;
use Falak\Kernel\Network\EndpointGuard;
use Falak\SourceControl\Contracts\Exceptions\SourceControlException;
use Falak\SourceControl\Contracts\ProviderType;
use Falak\SourceControl\Infrastructure\Providers\BitbucketClient;
use Falak\SourceControl\Infrastructure\Providers\CustomGitClient;
use Falak\SourceControl\Infrastructure\Providers\GitHubClient;
use Falak\SourceControl\Infrastructure\Providers\GitLabClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use phpseclib3\Crypt\RSA;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [, $this->organization] = memberOf(null, Role::Owner);
});

function gh_repo(string $name): array
{
    return ['full_name' => $name, 'default_branch' => 'main', 'private' => true, 'ssh_url' => "git@github.com:{$name}.git", 'clone_url' => "https://github.com/{$name}.git", 'html_url' => "https://github.com/{$name}"];
}

describe('GitHub', function () {
    it('lists repositories across pages and filters by search', function () {
        Http::fake([
            'api.github.com/user/repos?page=2' => Http::response([gh_repo('acme/api')]),
            'api.github.com/user/repos*' => Http::response([gh_repo('acme/shop'), gh_repo('acme/blog')], 200, ['Link' => '<https://api.github.com/user/repos?page=2>; rel="next"']),
        ]);

        $connection = sc_connection($this->organization->id);
        $client = app(GitHubClient::class);

        expect(array_map(fn ($r) => $r->fullName, $client->repositories($connection)))->toBe(['acme/shop', 'acme/blog', 'acme/api'])
            ->and(array_map(fn ($r) => $r->fullName, $client->repositories($connection, 'SHO')))->toBe(['acme/shop']);

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer tok_123') && $request->hasHeader('X-GitHub-Api-Version'));
    });

    it('reads branches, commits and the latest commit', function () {
        Http::fake([
            'api.github.com/repos/acme/shop/branches*' => Http::response([['name' => 'main', 'commit' => ['sha' => 'abc'], 'protected' => true], ['name' => 'dev', 'commit' => ['sha' => 'def'], 'protected' => false]]),
            'api.github.com/repos/acme/shop/commits/main' => Http::response(['sha' => str_repeat('c', 40), 'html_url' => 'https://github.com/x', 'commit' => ['message' => "Ship it\n\nbody", 'author' => ['name' => 'Ada', 'email' => 'ada@example.com', 'date' => '2026-09-01T12:00:00Z']]]),
            'api.github.com/repos/acme/shop/commits/nope' => Http::response(['message' => 'No commit found'], 404),
        ]);

        $connection = sc_connection($this->organization->id);
        $client = app(GitHubClient::class);

        $branches = $client->branches($connection, 'acme/shop');
        $commit = $client->latestCommit($connection, 'acme/shop', 'main');

        expect($branches)->toHaveCount(2)
            ->and($branches[0]->protected)->toBeTrue()
            ->and($commit->sha)->toBe(str_repeat('c', 40))
            ->and($commit->title())->toBe('Ship it')
            ->and($commit->authorEmail)->toBe('ada@example.com')
            ->and($commit->committedAt?->format('Y-m-d'))->toBe('2026-09-01')
            ->and($client->commit($connection, 'acme/shop', 'nope'))->toBeNull();
    });

    it('adds read-only deploy keys and push webhooks', function () {
        Http::fake([
            'api.github.com/repos/acme/shop/keys' => Http::response(['id' => 77], 201),
            'api.github.com/repos/acme/shop/hooks' => Http::response(['id' => 88], 201),
        ]);

        $connection = sc_connection($this->organization->id);
        $client = app(GitHubClient::class);

        expect($client->addDeployKey($connection, 'acme/shop', 'falak', 'ssh-ed25519 AAAA'))->toBe('77')
            ->and($client->createWebhook($connection, 'acme/shop', 'https://falak.test/hook', 's3cret'))->toBe('88');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/keys') && $r['read_only'] === true && $r['key'] === 'ssh-ed25519 AAAA');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/hooks') && $r['events'] === ['push', 'pull_request', 'issue_comment'] && $r['config']['secret'] === 's3cret' && $r['config']['content_type'] === 'json');
    });

    it('maps authentication failures to SourceControlException', function () {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Bad credentials'], 401)]);

        app(GitHubClient::class)->repositories(sc_connection($this->organization->id));
    })->throws(SourceControlException::class, 'Authentication failed');

    it('rejects malformed repository names before calling the API', function () {
        Http::fake();

        app(GitHubClient::class)->branches(sc_connection($this->organization->id), '../../etc');
    })->throws(SourceControlException::class, 'Invalid repository name');

    it('uses GitHub Enterprise base URLs', function () {
        Http::fake(['ghe.acme.test/api/v3/user' => Http::response(['login' => 'ada'])]);

        $connection = sc_connection($this->organization->id, attributes: ['base_url' => 'https://ghe.acme.test']);
        $client = app(GitHubClient::class);

        expect($client->account($connection))->toBe('ada')
            ->and($client->sshUrl($connection, 'acme/shop'))->toBe('git@ghe.acme.test:acme/shop.git')
            ->and($client->httpsUrl($connection, 'acme/shop'))->toBe('https://ghe.acme.test/acme/shop.git');
    });

    it('keeps GitHub Enterprise base URLs off private addresses on a managed install', function () {
        Http::fake(['*' => Http::response(['login' => 'ada'])]);
        app()->instance(EndpointGuard::class, new EndpointGuard(fn (string $host) => $host === 'ghe.acme.test' ? ['10.0.0.5'] : ['93.184.216.34']));
        $connection = sc_connection($this->organization->id, attributes: ['base_url' => 'https://ghe.acme.test']);

        // Self-hosted: a GitHub Enterprise on the LAN works.
        expect(app(GitHubClient::class)->account($connection))->toBe('ada');

        config(['controlplane.managed' => true]);
        expect(fn () => app(GitHubClient::class)->account($connection))->toThrow(SourceControlException::class, '10.0.0.5');
        Http::assertSentCount(1);

        $connection->forceFill(['base_url' => 'https://ghe.public.test'])->save();
        expect(app(GitHubClient::class)->account($connection->fresh()))->toBe('ada');
    });

    it('lists installation repositories with GitHub App tokens', function () {
        config(['source_control.github.app.id' => '1', 'source_control.github.app.private_key' => RSA::createKey(2048)->toString('PKCS1')]);
        Http::fake([
            'api.github.com/app/installations/42/access_tokens' => Http::response(['token' => 'ghs_inst', 'expires_at' => now()->addHour()->toIso8601ZuluString()], 201),
            'api.github.com/installation/repositories*' => Http::response(['total_count' => 1, 'repositories' => [gh_repo('acme/shop')]]),
        ]);

        $connection = sc_connection($this->organization->id, ProviderType::GitHub, 'app', ['installation_id' => '42']);
        $client = app(GitHubClient::class);

        expect($client->repositories($connection)[0]->fullName)->toBe('acme/shop')
            ->and($client->httpsCredentials($connection))->toBe(['x-access-token', 'ghs_inst']);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/installation/repositories') && $r->hasHeader('Authorization', 'Bearer ghs_inst'));
    });
});

describe('GitLab', function () {
    it('pages through projects on a self-hosted instance', function () {
        $project = fn (string $path) => ['path_with_namespace' => $path, 'default_branch' => 'main', 'visibility' => 'private', 'ssh_url_to_repo' => "git@git.acme.test:{$path}.git", 'http_url_to_repo' => "https://git.acme.test/{$path}.git", 'web_url' => "https://git.acme.test/{$path}"];

        Http::fake(function (Request $request) use ($project) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['page'] ?? '1') === '1'
                ? Http::response([$project('acme/shop')], 200, ['X-Next-Page' => '2'])
                : Http::response([$project('acme/platform/api')], 200, ['X-Next-Page' => '']);
        });

        $connection = sc_connection($this->organization->id, ProviderType::GitLab, 'token', ['token' => 'glpat-x'], ['base_url' => 'https://git.acme.test']);
        $repos = app(GitLabClient::class)->repositories($connection);

        expect(array_map(fn ($r) => $r->fullName, $repos))->toBe(['acme/shop', 'acme/platform/api'])
            ->and(app(GitLabClient::class)->sshUrl($connection, 'acme/platform/api'))->toBe('git@git.acme.test:acme/platform/api.git');

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://git.acme.test/api/v4/projects') && $r->hasHeader('Authorization', 'Bearer glpat-x'));
    });

    it('url-encodes nested project paths and reads branches and commits', function () {
        Http::fake([
            'gitlab.com/api/v4/projects/acme%2Fplatform%2Fapi/repository/branches/main' => Http::response(['name' => 'main', 'commit' => ['id' => 'abc123', 'message' => 'Hello', 'author_name' => 'Ada', 'author_email' => 'ada@example.com', 'committed_date' => '2026-09-01T00:00:00Z', 'web_url' => 'https://gitlab.com/c']]),
            'gitlab.com/api/v4/projects/acme%2Fplatform%2Fapi/repository/branches*' => Http::response([['name' => 'main', 'commit' => ['id' => 'abc123'], 'protected' => true]]),
            'gitlab.com/api/v4/projects/acme%2Fplatform%2Fapi/deploy_keys' => Http::response(['id' => 5], 201),
            'gitlab.com/api/v4/projects/acme%2Fplatform%2Fapi/hooks' => Http::response(['id' => 6], 201),
        ]);

        $connection = sc_connection($this->organization->id, ProviderType::GitLab, 'token', ['token' => 'glpat-x']);
        $client = app(GitLabClient::class);

        expect($client->latestCommit($connection, 'acme/platform/api', 'main')->sha)->toBe('abc123')
            ->and($client->branches($connection, 'acme/platform/api')[0]->protected)->toBeTrue()
            ->and($client->addDeployKey($connection, 'acme/platform/api', 'falak', 'ssh-ed25519 AAAA'))->toBe('5')
            ->and($client->createWebhook($connection, 'acme/platform/api', 'https://falak.test/h', 'tok'))->toBe('6');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/deploy_keys') && $r['can_push'] === false);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/hooks') && $r['token'] === 'tok' && $r['push_events'] === true);
    });

    it('refreshes expired OAuth tokens before calling the API', function () {
        config(['source_control.gitlab.client_id' => 'cid', 'source_control.gitlab.client_secret' => 'csecret']);
        Http::fake([
            'gitlab.com/oauth/token' => Http::response(['access_token' => 'fresh', 'refresh_token' => 'r2', 'expires_in' => 7200]),
            'gitlab.com/api/v4/user' => Http::response(['username' => 'ada']),
        ]);

        $connection = sc_connection($this->organization->id, ProviderType::GitLab, 'oauth', ['access_token' => 'stale', 'refresh_token' => 'r1', 'expires_at' => time() - 10]);

        expect(app(GitLabClient::class)->account($connection))->toBe('ada')
            ->and($connection->refresh()->credential('access_token'))->toBe('fresh')
            ->and($connection->credential('refresh_token'))->toBe('r2');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/oauth/token') && $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'r1');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/user') && $r->hasHeader('Authorization', 'Bearer fresh'));
    });

    it('reports permission errors', function () {
        Http::fake(['gitlab.com/*' => Http::response(['message' => '403 Forbidden'], 403)]);

        app(GitLabClient::class)->addDeployKey(sc_connection($this->organization->id, ProviderType::GitLab, 'token', ['token' => 'x']), 'acme/shop', 'k', 'ssh-ed25519 A');
    })->throws(SourceControlException::class, 'Permission denied');
});

describe('Bitbucket', function () {
    it('pages with next links using app-password basic auth', function () {
        $repo = fn (string $name) => ['full_name' => $name, 'is_private' => true, 'mainbranch' => ['name' => 'master'], 'links' => ['clone' => [['name' => 'https', 'href' => "https://ada@bitbucket.org/{$name}.git"], ['name' => 'ssh', 'href' => "git@bitbucket.org:{$name}.git"]], 'html' => ['href' => "https://bitbucket.org/{$name}"]]];

        Http::fake([
            'api.bitbucket.org/2.0/repositories?page=2' => Http::response(['values' => [$repo('acme/api')]]),
            'api.bitbucket.org/2.0/repositories*' => Http::response(['values' => [$repo('acme/shop')], 'next' => 'https://api.bitbucket.org/2.0/repositories?page=2']),
        ]);

        $connection = sc_connection($this->organization->id, ProviderType::Bitbucket, 'basic', ['username' => 'ada', 'password' => 'app-pass']);
        $repos = app(BitbucketClient::class)->repositories($connection);

        expect(array_map(fn ($r) => $r->fullName, $repos))->toBe(['acme/shop', 'acme/api'])
            ->and($repos[0]->defaultBranch)->toBe('master')
            ->and($repos[0]->httpsUrl)->toBe('https://bitbucket.org/acme/shop.git')
            ->and($repos[0]->sshUrl)->toBe('git@bitbucket.org:acme/shop.git');

        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Basic '.base64_encode('ada:app-pass')));
    });

    it('parses commit authors and registers keys and hooks', function () {
        Http::fake([
            'api.bitbucket.org/2.0/repositories/acme/shop/refs/branches/main' => Http::response(['name' => 'main', 'target' => ['hash' => 'f00', 'message' => 'Fix', 'date' => '2026-09-01T00:00:00+00:00', 'author' => ['raw' => 'Ada Lovelace <ada@example.com>'], 'links' => ['html' => ['href' => 'https://bitbucket.org/c']]]]),
            'api.bitbucket.org/2.0/repositories/acme/shop/deploy-keys' => Http::response(['id' => 12]),
            'api.bitbucket.org/2.0/repositories/acme/shop/hooks' => Http::response(['uuid' => '{abc}'], 201),
        ]);

        $connection = sc_connection($this->organization->id, ProviderType::Bitbucket, 'oauth', ['access_token' => 'bb']);
        $client = app(BitbucketClient::class);
        $commit = $client->latestCommit($connection, 'acme/shop', 'main');

        expect($commit->authorName)->toBe('Ada Lovelace')
            ->and($commit->authorEmail)->toBe('ada@example.com')
            ->and($client->addDeployKey($connection, 'acme/shop', 'falak', 'ssh-ed25519 A'))->toBe('12')
            ->and($client->createWebhook($connection, 'acme/shop', 'https://falak.test/h', 's'))->toBe('{abc}')
            ->and($client->httpsCredentials($connection))->toBe(['x-token-auth', 'bb']);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/hooks') && $r['events'][0] === 'repo:push' && in_array('pullrequest:created', $r['events'], true) && $r['secret'] === 's');
    });

    it('maps authentication failures', function () {
        Http::fake(['api.bitbucket.org/*' => Http::response(['type' => 'error', 'error' => ['message' => 'Unauthorized']], 401)]);

        app(BitbucketClient::class)->account(sc_connection($this->organization->id, ProviderType::Bitbucket, 'basic', ['username' => 'a', 'password' => 'b']));
    })->throws(SourceControlException::class, 'Authentication failed');
});

describe('Custom git', function () {
    it('has no API but resolves clone URLs', function () {
        Http::fake();
        $connection = sc_connection($this->organization->id, ProviderType::Custom, 'none', [], ['base_url' => 'ssh://git@git.acme.test']);
        $client = app(CustomGitClient::class);

        expect($client->repositories($connection))->toBe([])
            ->and($client->latestCommit($connection, 'x', 'main'))->toBeNull()
            ->and($client->sshUrl($connection, 'git@git.acme.test:acme/shop.git'))->toBe('git@git.acme.test:acme/shop.git')
            ->and($client->sshUrl($connection, 'acme/shop.git'))->toBe('ssh://git@git.acme.test/acme/shop.git');

        Http::assertNothingSent();
    });
});
