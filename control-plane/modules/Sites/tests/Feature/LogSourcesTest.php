<?php

use Falak\Fleet\Events\AgentVersionChanged;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\AuditEntry;
use Falak\Sites\Application\SiteLogSources;
use Falak\Sites\Contracts\SiteFactory;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Models\SiteTarget;
use Falak\Sites\Events\SiteUpdated;
use Falak\Sites\Infrastructure\EloquentServerSites;
use Falak\Telemetry\Contracts\ServerSites;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/../Support/helpers.php';

const LOG_STACK = "services:\n  app:\n    image: ghcr.io/acme/app:1\n  worker:\n    image: ghcr.io/acme/app:1\n";

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
    $this->agents = sites_fake_agents();
    sites_fake_source_control();
    config(['sites.test_domain' => 'falak.test']);
    $this->server = sites_server($this->organization->id, ['name' => 'app-1'], ['8.4'], 'fpm', docker: true);
    $this->stack = Site::query()->findOrFail(app(SiteFactory::class)->create($this->organization->id, $this->user->id, [
        'name' => 'stack', 'runtime' => 'compose', 'server_ids' => [$this->server->id], 'compose_source' => 'inline', 'compose_content' => LOG_STACK,
    ])->site->id);
});

function log_fpm_site(object $test): Site
{
    $test->post('/sites', sites_input([$test->server->id], ['runtime' => 'php-fpm', 'name' => 'shop']))->assertSessionHasNoErrors();
    $site = Site::query()->where('slug', 'shop')->firstOrFail();
    SiteTarget::query()->where('site_id', $site->id)->update(['status' => 'ready']);

    return $site->refresh();
}

it('saves the files inside a compose site\'s containers and re-sends telemetry.configure', function () {
    Event::fake([SiteUpdated::class]);
    $url = "/sites/{$this->stack->id}/log-sources";

    $this->getJson($url)->assertOk()
        ->assertJsonPath('data.containers', true)
        ->assertJsonPath('data.php_fpm', false)
        ->assertJsonPath('data.sources', [])
        ->assertJsonPath('data.compose_services', ['app', 'worker'])
        ->assertJsonPath('data.slowlog_timeout', null)
        ->assertJsonPath('data.can.update', true);

    $sources = [
        ['path' => '/var/www/html/storage/logs/*.log', 'multiline' => 'laravel', 'compose_service' => 'app'],
        ['path' => '/var/log/app/[a-z]*.json', 'format' => 'json', 'multiline' => 'pattern', 'multiline_pattern' => '^\d{4}-\d{2}-\d{2} '],
    ];
    $this->putJson($url, ['sources' => $sources])->assertOk()
        ->assertJsonPath('data.changed', ['log_sources'])
        ->assertJsonPath('data.sources.0', ['path' => '/var/www/html/storage/logs/*.log', 'format' => 'plain', 'multiline' => 'laravel', 'compose_service' => 'app']);

    Event::assertDispatched(SiteUpdated::class, fn (SiteUpdated $e) => $e->changed('log_sources') && $e->serverIds === [$this->server->id]);
    expect(AuditEntry::query()->where('action', 'site.log_sources_updated')->firstOrFail()->context['sources'] ?? null)
        ->toBe(['/var/www/html/storage/logs/*.log', '/var/log/app/[a-z]*.json']);

    // telemetry.configure gets them as container sources of the site.
    $targets = app(ServerSites::class)->forServer($this->server->id);
    expect(collect($targets)->firstWhere('slug', 'stack')->logSources)->toBe([
        ['path' => '/var/www/html/storage/logs/*.log', 'kind' => 'app', 'container' => true, 'compose_service' => 'app', 'format' => 'plain', 'multiline' => 'laravel'],
        ['path' => '/var/log/app/[a-z]*.json', 'kind' => 'app', 'container' => true, 'format' => 'json', 'multiline_pattern' => '^\d{4}-\d{2}-\d{2} '],
    ]);

    // Unchanged: nothing announced; an empty list clears them.
    $this->putJson($url, ['sources' => $sources])->assertOk()->assertJsonPath('data.changed', []);
    Event::assertDispatchedTimes(SiteUpdated::class, 1);
    $this->putJson($url, ['sources' => []])->assertOk();
    expect($this->stack->refresh()->log_sources)->toBeNull();
});

it('validates container log sources', function (array $source, string $field) {
    $this->putJson("/sites/{$this->stack->id}/log-sources", ['sources' => [$source]])
        ->assertUnprocessable()->assertJsonValidationErrors("sources.0.{$field}");
    expect($this->stack->refresh()->log_sources)->toBeNull();
})->with([
    'relative' => [['path' => 'storage/logs/x.log'], 'path'],
    'dot dot' => [['path' => '/app/../../etc/shadow'], 'path'],
    'directory' => [['path' => '/var/log/'], 'path'],
    'root' => [['path' => '/'], 'path'],
    'control character' => [['path' => "/var/log/a\nb.log"], 'path'],
    'backslash' => [['path' => '/var/log/a\\b.log'], 'path'],
    'unclosed bracket' => [['path' => '/var/log/[ab.log'], 'path'],
    'proc' => [['path' => '/proc/kmsg'], 'path'],
    'sys glob' => [['path' => '/sy?/kernel/x'], 'path'],
    'dev' => [['path' => '/dev/stdout'], 'path'],
    'too long' => [['path' => '/'.str_repeat('a', 300)], 'path'],
    'format' => [['path' => '/a.log', 'format' => 'xml'], 'format'],
    'multiline' => [['path' => '/a.log', 'multiline' => 'yes'], 'multiline'],
    'empty pattern' => [['path' => '/a.log', 'multiline' => 'pattern'], 'multiline_pattern'],
    'invalid pattern' => [['path' => '/a.log', 'multiline' => 'pattern', 'multiline_pattern' => '^(unclosed'], 'multiline_pattern'],
    'lookahead (not RE2)' => [['path' => '/a.log', 'multiline' => 'pattern', 'multiline_pattern' => '^(?=\[)'], 'multiline_pattern'],
    'back-reference (not RE2)' => [['path' => '/a.log', 'multiline' => 'pattern', 'multiline_pattern' => '^(a)\1'], 'multiline_pattern'],
    'possessive (not RE2)' => [['path' => '/a.log', 'multiline' => 'pattern', 'multiline_pattern' => '^a++'], 'multiline_pattern'],
    'unknown escape (not RE2)' => [['path' => '/a.log', 'multiline' => 'pattern', 'multiline_pattern' => '^\h'], 'multiline_pattern'],
    'unknown compose service' => [['path' => '/a.log', 'compose_service' => 'db'], 'compose_service'],
]);

it('accepts exactly the patterns of the corpus the agent compiles too', function () {
    // The agent's TestMultilinePatternCorpusCompiles reads the same file: every accepted pattern compiles with RE2.
    $corpus = json_decode((string) file_get_contents(config('fleet.schemas_path').'/testdata/multiline-patterns.json'), true);

    expect($corpus['accepted'])->not->toBeEmpty()->and($corpus['rejected'])->not->toBeEmpty();

    foreach ($corpus['accepted'] as $pattern) {
        expect(SiteLogSources::patternError($pattern))->toBeNull("accepted: {$pattern}");
    }

    foreach ($corpus['rejected'] as $pattern) {
        expect(SiteLogSources::patternError($pattern))->not->toBeNull("rejected: {$pattern}");
    }

    expect(SiteLogSources::patternError(SiteLogSources::FPM_SLOWLOG_PATTERN))->toBeNull();
});

it('limits the sources of a site and refuses duplicates', function () {
    $url = "/sites/{$this->stack->id}/log-sources";
    $many = array_map(fn (int $i) => ['path' => "/var/log/{$i}.log"], range(1, SiteLogSources::MAX_SOURCES + 1));
    $this->putJson($url, ['sources' => $many])->assertUnprocessable()->assertJsonValidationErrors('sources');
    $this->putJson($url, ['sources' => [['path' => '/a.log'], ['path' => '/a.log']]])->assertUnprocessable()->assertJsonValidationErrors('sources.1.path');
    // The same path in two services is two sources.
    $this->putJson($url, ['sources' => [['path' => '/a.log', 'compose_service' => 'app'], ['path' => '/a.log', 'compose_service' => 'worker']]])->assertOk();
});

it('only lets site managers change log sources, also through the API', function () {
    actingAsMember(Role::Viewer, $this->organization);
    $this->getJson("/sites/{$this->stack->id}/log-sources")->assertOk()->assertJsonPath('data.can.update', false);
    $this->putJson("/sites/{$this->stack->id}/log-sources", ['sources' => [['path' => '/a.log']]])->assertForbidden();
    $this->putJson("/api/v1/sites/{$this->stack->id}/log-sources", ['sources' => [['path' => '/a.log']]])->assertForbidden();

    [, $other] = actingAsMember(Role::Admin);
    $this->getJson("/sites/{$this->stack->id}/log-sources")->assertNotFound();
});

it('saves through the API', function () {
    $this->putJson("/api/v1/sites/{$this->stack->id}/log-sources", ['sources' => [['path' => '/a.log']]])->assertOk()->assertJsonPath('data.sources.0.path', '/a.log');
    $this->getJson("/api/v1/sites/{$this->stack->id}/log-sources")->assertOk()->assertJsonPath('data.sources.0.format', 'plain');
});

it('gives PHP-FPM sites their error and slow logs, and re-sends the pool when the threshold changes', function () {
    $site = log_fpm_site($this);
    $url = "/sites/{$site->id}/log-sources";
    $pool = $this->agents->last('runtime.fpm.pool');
    expect($pool['payload']['slowlog_timeout'])->toBe(SiteLogSources::DEFAULT_SLOWLOG_TIMEOUT);

    $this->getJson($url)->assertOk()->assertJsonPath('data.php_fpm', true)->assertJsonPath('data.containers', false)->assertJsonPath('data.slowlog_timeout', 5);
    // No containers to read files in.
    $this->putJson($url, ['sources' => [['path' => '/a.log']]])->assertUnprocessable()->assertJsonValidationErrors('sources');

    $sources = fn () => collect(app(EloquentServerSites::class)->forServer($this->server->id))->firstWhere('slug', 'shop')->logSources;
    expect(array_slice($sources(), -2))->toBe([
        ['path' => '/var/log/falak/fpm-shop.log', 'kind' => 'app', 'origin' => 'php_fpm'],
        ['path' => '/var/log/falak/php-fpm-slow/shop.log', 'kind' => 'app', 'origin' => 'php_fpm_slow', 'multiline_pattern' => SiteLogSources::FPM_SLOWLOG_PATTERN],
    ]);

    $before = count($this->agents->ofType('runtime.fpm.pool'));
    $this->putJson($url, ['slowlog_timeout' => 10])->assertOk()->assertJsonPath('data.slowlog_timeout', 10)->assertJsonPath('data.changed', ['fpm_slowlog_timeout']);
    expect($this->agents->ofType('runtime.fpm.pool'))->toHaveCount($before + 1)
        ->and($this->agents->last('runtime.fpm.pool')['payload']['slowlog_timeout'])->toBe(10);

    // Off: the pool loses its slow log, the site its slow log source.
    $this->putJson($url, ['slowlog_timeout' => 0])->assertOk();
    expect($this->agents->last('runtime.fpm.pool')['payload'])->not->toHaveKey('slowlog_timeout')
        ->and(collect($sources())->pluck('origin')->filter()->values()->all())->toBe(['php_fpm']);

    // Null is off as well: the pool is not re-sent for the same effective threshold.
    $count = count($this->agents->ofType('runtime.fpm.pool'));
    $this->putJson($url, ['slowlog_timeout' => null])->assertOk()->assertJsonPath('data.slowlog_timeout', 0);
    expect($this->agents->ofType('runtime.fpm.pool'))->toHaveCount($count);

    $this->putJson($url, ['slowlog_timeout' => 601])->assertUnprocessable()->assertJsonValidationErrors('slowlog_timeout');
    $this->putJson("/sites/{$this->stack->id}/log-sources", ['slowlog_timeout' => 5])->assertUnprocessable()->assertJsonValidationErrors('slowlog_timeout');
});

it('re-sends the pools whose slow log is on to an agent that understands it', function () {
    $site = log_fpm_site($this);
    expect($site->isolated)->toBeFalse()->and($site->fpm_slowlog_timeout)->toBe(SiteLogSources::DEFAULT_SLOWLOG_TIMEOUT);
    $count = count($this->agents->ofType('runtime.fpm.pool'));

    // An upgrade without the feature re-sends isolated pools only (none here).
    event(new AgentVersionChanged('agent', $this->organization->id, $this->server->id, '0.14.1', '0.14.2', []));
    expect($this->agents->ofType('runtime.fpm.pool'))->toHaveCount($count);

    event(new AgentVersionChanged('agent', $this->organization->id, $this->server->id, '0.14.2', '0.15.0', ['runtime.fpm.slowlog']));
    expect($this->agents->ofType('runtime.fpm.pool'))->toHaveCount($count + 1)
        ->and($this->agents->last('runtime.fpm.pool')['payload']['slowlog_timeout'])->toBe(5);
});

it('leaves the pools of sites that predate the slow log unchanged on the agent upgrade', function () {
    // Sites created before the slow log (the migration leaves them null): two shared-user sites, one isolated.
    foreach (['blog' => false, 'shop' => false, 'iso' => true] as $name => $isolated) {
        $this->post('/sites', sites_input([$this->server->id], ['runtime' => 'php-fpm', 'name' => $name, 'isolated' => $isolated]))->assertSessionHasNoErrors();
    }
    Site::query()->where('runtime', 'php-fpm')->update(['fpm_slowlog_timeout' => null]);
    SiteTarget::query()->update(['status' => 'ready']);
    $count = count($this->agents->ofType('runtime.fpm.pool'));

    event(new AgentVersionChanged('agent', $this->organization->id, $this->server->id, '0.14.2', '0.15.0', ['runtime.fpm.slowlog']));

    // Only the isolated pool is re-sent (as before the slow log, for its open_basedir), and exactly as an older control
    // plane built it: no slow log, so the agent writes the same pool file and reloads nothing.
    $sent = array_slice($this->agents->ofType('runtime.fpm.pool'), $count);
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['payload']['pool'])->toBe('iso')
        ->and($sent[0]['payload'])->not->toHaveKey('slowlog_timeout')
        ->and(collect(app(EloquentServerSites::class)->forServer($this->server->id))->flatMap(fn ($t) => $t->logSources)->pluck('origin')->filter()->unique()->values()->all())
        ->toBe(['php_fpm']);
});
