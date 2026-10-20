<?php

use Falak\ControlPlane\Application\Jobs\CheckForRelease;
use Falak\ControlPlane\Application\ReleaseCheck;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    Cache::forget(ReleaseCheck::CACHE_KEY);
    config(['controlplane.version' => 'v0.23.0', 'controlplane.repository' => 'falak-sh/falak', 'controlplane.update_check' => true]);
});

it('compares versions as semver', function (?string $candidate, ?string $current, bool $newer) {
    expect(ReleaseCheck::newer($candidate, $current))->toBe($newer);
})->with([
    ['v0.24.0', 'v0.23.0', true],
    ['v0.23.1', 'v0.23.0', true],
    ['v0.10.0', 'v0.9.0', true],
    ['v1.0.0', 'v0.99.99', true],
    ['v0.23.0', 'v0.23.0', false],
    ['v0.22.9', 'v0.23.0', false],
    ['v0.23.0', 'v0.23.0-rc1', true],
    ['v0.24.0', 'dev', false],
    ['v0.24.0', null, false],
    [null, 'v0.23.0', false],
    ['latest', 'v0.23.0', false],
]);

it('fetches the latest release from GitHub and caches the fields it keeps', function () {
    Http::fake(['api.github.com/*' => Http::response(github_release())]);
    $releases = app(ReleaseCheck::class);

    $result = $releases->check();

    expect($result)->toMatchArray([
        'tag' => 'v0.24.0',
        'url' => 'https://github.com/falak-sh/falak/releases/tag/v0.24.0',
        'published_at' => '2026-10-17T10:00:00Z',
        // Trimmed, kept as text (the UI escapes it).
        'notes' => "## What's new\n<script>alert(1)</script>",
        'error' => null,
    ])
        ->and($releases->latest())->toBe($result)
        ->and($releases->updateAvailable())->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.github.com/repos/falak-sh/falak/releases/latest'
        && str_starts_with($request->header('User-Agent')[0] ?? '', 'falak-control-plane/v0.23.0')
        && ! $request->hasHeader('Authorization'));
});

it('ignores drafts, pre-releases, odd tags and foreign release links', function () {
    expect(ReleaseCheck::parse(github_release(['prerelease' => true])))->toHaveKey('error')->not->toHaveKey('tag')
        ->and(ReleaseCheck::parse(github_release(['draft' => true])))->not->toHaveKey('tag')
        ->and(ReleaseCheck::parse(github_release(['tag_name' => 'v0.24.0-rc1'])))->not->toHaveKey('tag')
        ->and(ReleaseCheck::parse(github_release(['tag_name' => '<b>x</b>'])))->not->toHaveKey('tag')
        ->and(ReleaseCheck::parse(github_release(['html_url' => 'javascript:alert(1)']))['url'])->toBeNull()
        ->and(mb_strlen((string) ReleaseCheck::parse(github_release(['body' => str_repeat('x', 50000)]))['notes']))->toBe(20000);
});

it('keeps the last known release when GitHub fails', function () {
    Http::fake(['api.github.com/*' => Http::sequence()->push(github_release())->push('rate limited', 403)]);
    $releases = app(ReleaseCheck::class);

    $releases->check();
    $failed = $releases->check();

    expect($failed['tag'])->toBe('v0.24.0')->and($failed['error'])->toBe('GitHub answered HTTP 403.')
        ->and($releases->updateAvailable())->toBeTrue();
});

it('does not ask GitHub when the check is turned off', function () {
    Http::fake();
    config(['controlplane.update_check' => false]);

    (new CheckForRelease)->handle(app(ReleaseCheck::class));

    Http::assertNothingSent();
    expect(app(ReleaseCheck::class)->latest())->toBeNull();
});

it('knows its version from FALAK_VERSION or the image\'s VERSION file', function () {
    $file = tempnam(sys_get_temp_dir(), 'falak-version');
    file_put_contents($file, "v0.23.2\n");
    config(['controlplane.version' => null, 'controlplane.version_file' => $file]);

    expect(app(ReleaseCheck::class)->currentVersion())->toBe('v0.23.2');

    config(['controlplane.version' => 'v0.23.3']);
    expect(app(ReleaseCheck::class)->currentVersion())->toBe('v0.23.3');
    @unlink($file);
});
