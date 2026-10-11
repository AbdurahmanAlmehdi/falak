<?php

use Falak\Identity\Contracts\Role;
use Falak\SourceControl\Contracts\Exceptions\NoApi;
use Falak\SourceControl\Contracts\Exceptions\SourceControlException;
use Falak\SourceControl\Contracts\Exceptions\TreeTruncated;
use Falak\SourceControl\Contracts\ProviderType;
use Falak\SourceControl\Contracts\SourceControlGateway;
use Falak\SourceControl\Infrastructure\EloquentSourceControlGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [, $this->organization] = memberOf(null, Role::Owner);
    $this->gateway = app(SourceControlGateway::class);
});

it('reads a file from GitHub at a ref', function () {
    Http::fake(['api.github.com/repos/acme/shop/contents/docker/compose.prod.yml*' => Http::response([
        'type' => 'file', 'size' => 24, 'encoding' => 'base64', 'content' => chunk_split(base64_encode("services:\n  web: {}\n"), 8, "\n"),
    ])]);
    $connection = sc_connection($this->organization->id);

    expect($this->gateway->file($connection->id, 'acme/shop', 'main', 'docker/compose.prod.yml'))->toBe("services:\n  web: {}\n");
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'ref=main'));
});

it('answers null for a missing path, a directory or a path leaving the repository', function () {
    Http::fake([
        'api.github.com/repos/acme/shop/contents/missing.yml*' => Http::response(['message' => 'Not Found'], 404),
        'api.github.com/repos/acme/shop/contents/docker*' => Http::response([['type' => 'file', 'path' => 'docker/a.yml']]),
    ]);
    $connection = sc_connection($this->organization->id);

    expect($this->gateway->file($connection->id, 'acme/shop', 'main', 'missing.yml'))->toBeNull()
        ->and($this->gateway->file($connection->id, 'acme/shop', 'main', 'docker'))->toBeNull()
        ->and($this->gateway->file($connection->id, 'acme/shop', 'main', '../etc/passwd'))->toBeNull();
});

it('refuses files larger than the limit', function () {
    Http::fake(['api.github.com/*' => Http::response(['type' => 'file', 'size' => SourceControlGateway::MAX_FILE_BYTES + 1, 'content' => ''])]);
    $connection = sc_connection($this->organization->id);

    $this->gateway->file($connection->id, 'acme/shop', 'main', 'big.yml');
})->throws(SourceControlException::class, 'larger than');

it('lists repository files matching a glob', function () {
    Http::fake(['api.github.com/repos/acme/shop/git/trees/main*' => Http::response(['tree' => [
        ['path' => 'compose.yaml', 'type' => 'blob'],
        ['path' => 'docker', 'type' => 'tree'],
        ['path' => 'docker/docker-compose.prod.yml', 'type' => 'blob'],
        ['path' => 'src/app.ts', 'type' => 'blob'],
    ]])]);
    $connection = sc_connection($this->organization->id);

    expect($this->gateway->tree($connection->id, 'acme/shop', 'main', '*compose*.y*ml'))->toBe(['compose.yaml', 'docker/docker-compose.prod.yml'])
        ->and($this->gateway->tree($connection->id, 'acme/shop', 'main', 'docker/**'))->toBe(['docker/docker-compose.prod.yml']);
});

it('reads files and trees from GitLab', function () {
    Http::fake([
        'gitlab.com/api/v4/projects/acme%2Fshop/repository/files/compose.yaml*' => Http::response(['size' => 9, 'content' => base64_encode('services:')]),
        'gitlab.com/api/v4/projects/acme%2Fshop/repository/tree*' => Http::response([
            ['path' => 'compose.yaml', 'type' => 'blob'], ['path' => 'lib', 'type' => 'tree'],
        ]),
    ]);
    $connection = sc_connection($this->organization->id, ProviderType::GitLab);

    expect($this->gateway->file($connection->id, 'acme/shop', 'main', 'compose.yaml'))->toBe('services:')
        ->and($this->gateway->tree($connection->id, 'acme/shop', 'main'))->toBe(['compose.yaml']);
});

it('checks that files and folders exist, and reads GitLab sizes before downloading', function () {
    Http::fake(function (Request $request) {
        $url = $request->url();

        return match (true) {
            str_contains($url, 'api.github.com/repos/acme/shop/contents/conf.d') => Http::response([['type' => 'file', 'path' => 'conf.d/a.conf']]),
            str_contains($url, 'api.github.com') => Http::response(['message' => 'Not Found'], 404),
            str_contains($url, '/repository/files/big.yaml') => Http::response('', 200, ['X-Gitlab-Size' => (string) (SourceControlGateway::MAX_FILE_BYTES + 1)]),
            str_contains($url, '/repository/files/') => Http::response(['message' => '404 File Not Found'], 404),
            str_contains($url, '/repository/tree') && str_contains($url, 'path=lib') => Http::response([['path' => 'lib/a.ts', 'type' => 'blob']]),
            default => Http::response([]),
        };
    });
    $github = sc_connection($this->organization->id);
    $gitlab = sc_connection($this->organization->id, ProviderType::GitLab);

    expect($this->gateway->exists($github->id, 'acme/shop', 'main', 'conf.d'))->toBeTrue()
        ->and($this->gateway->exists($github->id, 'acme/shop', 'main', 'missing'))->toBeFalse()
        ->and($this->gateway->exists($gitlab->id, 'acme/shop', 'main', 'lib'))->toBeTrue()
        ->and($this->gateway->exists($gitlab->id, 'acme/shop', 'main', 'nothing'))->toBeFalse()
        ->and($this->gateway->exists($gitlab->id, 'acme/shop', 'main', '../x'))->toBeFalse()
        ->and(fn () => $this->gateway->file($gitlab->id, 'acme/shop', 'main', 'big.yaml'))->toThrow(SourceControlException::class, 'larger than');

    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/files/big.yaml') && $r->method() === 'GET');
});

it('reads files and trees from Bitbucket', function () {
    Http::fake(function (Request $request) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/src/main/compose.yaml?format=meta') => Http::response(['type' => 'commit_file', 'size' => 9]),
            str_contains($url, '/src/main/big.yaml?format=meta') => Http::response(['type' => 'commit_file', 'size' => SourceControlGateway::MAX_FILE_BYTES + 1]),
            str_contains($url, '/src/main/lib?format=meta') => Http::response(['type' => 'commit_directory']),
            str_contains($url, 'format=meta') => Http::response(['type' => 'error'], 404),
            str_ends_with($url, '/src/main/compose.yaml') => Http::response('services:', 200, ['Content-Type' => 'text/plain']),
            default => Http::response(['values' => [['path' => 'compose.yaml', 'type' => 'commit_file'], ['path' => 'lib', 'type' => 'commit_directory']]]),
        };
    });
    $connection = sc_connection($this->organization->id, ProviderType::Bitbucket);

    expect($this->gateway->file($connection->id, 'acme/shop', 'main', 'compose.yaml'))->toBe('services:')
        ->and($this->gateway->file($connection->id, 'acme/shop', 'main', 'lib'))->toBeNull()
        ->and($this->gateway->exists($connection->id, 'acme/shop', 'main', 'lib'))->toBeTrue()
        ->and($this->gateway->exists($connection->id, 'acme/shop', 'main', 'nope'))->toBeFalse()
        ->and($this->gateway->tree($connection->id, 'acme/shop', 'main'))->toBe(['compose.yaml'])
        ->and(fn () => $this->gateway->file($connection->id, 'acme/shop', 'main', 'big.yaml'))->toThrow(SourceControlException::class, 'larger than');

    // The large file was never downloaded.
    Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/src/main/big.yaml'));
});

it('reports that plain git servers have no file API', function () {
    $connection = sc_connection($this->organization->id, ProviderType::Custom, 'ssh', []);

    $this->gateway->file($connection->id, 'git@git.example.com:acme/shop.git', 'main', 'compose.yaml');
})->throws(NoApi::class);

it('translates globs', function (string $glob, string $path, bool $match) {
    expect(preg_match(EloquentSourceControlGateway::globPattern($glob), $path) === 1)->toBe($match);
})->with([
    ['*compose*.y*ml', 'docker-compose.yml', true],
    ['*compose*.y*ml', 'compose.yaml', true],
    ['*compose*.y*ml', 'composer.json', false],
    ['docker/**', 'docker/a/b.yml', true],
    ['**/compose.yaml', 'compose.yaml', true],
    ['**/compose.yaml', 'a/b/compose.yaml', true],
    ['*.yml', 'a/b.yml', false],
]);

it('says when a file list asked for whole is not', function () {
    Http::fake(['api.github.com/repos/acme/shop/git/trees/main*' => Http::response(['truncated' => true, 'tree' => [['path' => 'fn/index.ts', 'type' => 'blob']]])]);
    $connection = sc_connection($this->organization->id);

    // Searches keep the partial list; a complete list is refused.
    expect($this->gateway->tree($connection->id, 'acme/shop', 'main', 'fn/**'))->toBe(['fn/index.ts'])
        ->and(fn () => $this->gateway->tree($connection->id, 'acme/shop', 'main', 'fn/**', complete: true))->toThrow(TreeTruncated::class);
});
