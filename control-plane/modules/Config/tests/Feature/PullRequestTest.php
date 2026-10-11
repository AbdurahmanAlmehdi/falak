<?php

use Falak\Config\Domain\Models\ConfigRun;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\SourceControl\Contracts\Data\PullRequestData;
use Falak\SourceControl\Events\PullRequestOpened;
use Falak\SourceControl\Events\PullRequestUpdated;

require_once __DIR__.'/../Support/helpers.php';

function config_pr(ConfigWorld $world, string $sha, bool $fork = false, string $event = 'opened'): void
{
    $pr = new PullRequestData('acme/shop', 7, 'Change config', 'https://github.com/acme/shop/pull/7', 'feature', $sha, 'main', 'ada', $fork, $fork ? 'mallory/shop' : 'acme/shop');
    $class = $event === 'opened' ? PullRequestOpened::class : PullRequestUpdated::class;
    $class::dispatch($world->deploy->organization->id, $world->source->source_connection_id, 'github', $pr);
}

const CONFIG_PR_GOOD = "version: 1\nunits:\n  web: { type: app, runtime: frankenphp, php: \"8.4\" }\nenvironments:\n  production: { branch: main }\n";

it('posts one plan comment and a falak/config status, edited on later updates, and never applies', function () {
    $world = config_world();
    $world->file(CONFIG_PR_GOOD, config_sha('b'));

    config_pr($world, config_sha('b'));

    $comments = $world->deploy->sourceControl->comments;
    expect($comments)->toHaveCount(1)
        ->and(array_values($comments)[0]['number'])->toBe(7)
        ->and(array_values($comments)[0]['body'])->toContain('Falak config plan')->toMatch('/### production/i')
        ->and($world->deploy->sourceControl->statuses[0])->toMatchArray(['sha' => config_sha('b'), 'state' => 'success', 'context' => 'falak/config']);

    // A new head with an invalid file: the same comment is edited, the status fails.
    $world->file("version: 1\nunits:\n  web: { type: app, runtime: nope }\nenvironments:\n  production: { branch: main }\n", config_sha('c'));
    config_pr($world, config_sha('c'), event: 'updated');

    $comments = $world->deploy->sourceControl->comments;
    expect($comments)->toHaveCount(1)
        ->and(array_values($comments)[0]['body'])->toContain('is invalid')->toContain('falak.yaml:3:')
        ->and(end($world->deploy->sourceControl->statuses))->toMatchArray(['sha' => config_sha('c'), 'state' => 'failure'])
        ->and(ConfigRun::query()->count())->toBe(0)
        ->and(Deployment::query()->count())->toBe(0);

    // The same head again (a title edit): nothing new.
    config_pr($world, config_sha('c'), event: 'updated');
    expect($world->deploy->sourceControl->statuses)->toHaveCount(2);
});

it('plans a fork pull request with the base branch file, never its own', function () {
    $world = config_world();
    $world->file(CONFIG_PR_GOOD, 'main');
    $world->file("version: 1\nunits:\n  web: { type: app, runtime: nope }\n", config_sha('f'));

    config_pr($world, config_sha('f'), fork: true);

    expect(array_column($world->deploy->sourceControl->fileReads, 'ref'))->toBe(['main'])
        ->and(array_values($world->deploy->sourceControl->comments)[0]['body'])->toContain('comes from a fork')->not->toContain('is invalid')
        ->and($world->deploy->sourceControl->statuses[0]['state'])->toBe('success');
});

it('ignores pull requests of repositories no enabled source follows', function () {
    $world = config_world();
    $world->source->forceFill(['enabled' => false])->save();
    $world->file(CONFIG_PR_GOOD);

    config_pr($world, config_sha('b'));

    expect($world->deploy->sourceControl->comments)->toBe([]);
});
