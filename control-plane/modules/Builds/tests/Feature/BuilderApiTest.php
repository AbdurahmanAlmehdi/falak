<?php

use Falak\Builds\Application\Actions\CreateExternalBuilder;
use Falak\Builds\Application\Artifacts\ArtifactStorage;
use Falak\Builds\Application\BuildConfiguration;
use Falak\Builds\Application\BuildProgress;
use Falak\Builds\Application\JobPayload;
use Falak\Builds\Application\Jobs\ExpireBuilds;
use Falak\Builds\Application\Jobs\PruneArtifacts;
use Falak\Builds\Contracts\BuildService;
use Falak\Builds\Contracts\BuildStatus;
use Falak\Builds\Contracts\Data\BuildRequest;
use Falak\Builds\Domain\Models\Build;
use Falak\Builds\Domain\Models\Builder;
use Falak\Builds\Events\BuildCancelled;
use Falak\Builds\Events\BuildFailed;
use Falak\Builds\Events\BuildSucceeded;
use Falak\Builds\Infrastructure\EloquentBuildService;
use Falak\Deployments\Application\Actions\TriggerDeployment;
use Falak\Deployments\Domain\Enums\DeploymentStatus;
use Falak\Deployments\Domain\Enums\Trigger;
use Falak\Deployments\Domain\Models\OutputLine;
use Falak\Identity\Contracts\Role;
use Falak\Servers\Contracts\ServerType;
use Falak\Servers\Events\ServerProvisioned;
use Falak\Sites\Contracts\SiteDirectory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../Deployments/tests/Support/helpers.php';

beforeEach(function () {
    $this->artifacts = sys_get_temp_dir().'/falak-artifacts-'.Str::random(8);
    config([
        'builds.artifacts.driver' => 'local',
        'builds.artifacts.local.root' => $this->artifacts,
        'builds.artifacts.local.url' => 'http://falak.test',
        'builds.local_builder.token' => 'local-builder-token-123',
        'builds.registry.username' => 'falak',
        'builds.registry.password' => 'registry-secret',
    ]);
    app()->forgetInstance(ArtifactStorage::class);
});

afterEach(fn () => File::deleteDirectory($this->artifacts));

/**
 * A deploy world whose BuildService is the real Builds module.
 */
function builds_world(array $site = [], int $servers = 1): DeployWorld
{
    $world = deploy_world(servers: $servers, site: $site);
    app()->instance(BuildService::class, app(EloquentBuildService::class));

    return $world;
}

function request_build(DeployWorld $world, ?string $commit = null): Build
{
    return Build::query()->findOrFail(app(BuildService::class)->request(new BuildRequest($world->site->id, $commit ?? str_repeat('a', 40), 'main'))->id);
}

/**
 * @param  list<array<string, mixed>>  $events
 */
function ndjson(array $events): string
{
    return implode("\n", array_map(fn ($e) => json_encode($e, JSON_UNESCAPED_SLASHES), $events))."\n";
}

function post_events(string $buildId, array $events, string $token = 'local-builder-token-123')
{
    return test()->call('POST', "/api/internal/builds/{$buildId}/events", [], [], [], [
        'HTTP_AUTHORIZATION' => "Bearer {$token}",
        'CONTENT_TYPE' => 'application/x-ndjson',
        'HTTP_ACCEPT' => 'application/json',
    ], ndjson($events));
}

function next_job(string $token = 'local-builder-token-123')
{
    return test()->withToken($token)->getJson('/api/internal/builds/next?wait=0&builder=cp-1');
}

it('authenticates builders by token', function () {
    $this->getJson('/api/internal/builds/next')->assertUnauthorized();
    $this->withToken('wrong')->getJson('/api/internal/builds/next')->assertUnauthorized();
    next_job()->assertNoContent();

    $local = Builder::query()->sole();
    expect($local->kind)->toBe('local')->and($local->organization_id)->toBeNull()->and($local->reported_name)->toBe('cp-1');
});

it('hands out a native job with short-lived clone credentials and a presigned upload URL', function () {
    $world = builds_world();
    $world->site->environmentVersions()->first()->forceFill(['variables' => ['VITE_APP_NAME' => 'Shop', 'APP_KEY' => 'secret']])->save();
    $build = request_build($world);

    $job = next_job()->assertOk()->json();

    expect($job['id'])->toBe($build->id)
        ->and($job['mode'])->toBe('native')
        ->and($job['repo'])->toBe(['url' => 'git@github.com:acme/shop.git', 'ref' => 'main', 'commit' => str_repeat('a', 40), 'deploy_key' => 'PRIVATE'])
        ->and($job['runtime'])->toBe('php')
        ->and($job['env'])->toBe(['VITE_APP_NAME' => 'Shop'])
        ->and($job['timeout_s'])->toBe(1800)
        ->and($job['native']['upload']['url'])->toStartWith('https://falak.test/api/internal/artifacts/')
        ->and($build->refresh()->status)->toBe(BuildStatus::Assigned);

    // Credentials are never persisted.
    expect(json_encode(Build::query()->find($build->id)->getAttributes()))->not->toContain('PRIVATE');
    next_job()->assertNoContent();
});

it('builds from the site root directory (monorepos): the job carries it as subdir', function () {
    $world = builds_world(site: ['root_directory' => 'apps/shop']);
    request_build($world);
    expect(next_job()->json('subdir'))->toBe('apps/shop');

    $plain = builds_world();
    request_build($plain);
    expect(next_job()->json())->not->toHaveKey('subdir');
});

it('passes variables exposed to the deploy script to the build as well (non-prefixed build-time settings)', function () {
    $world = builds_world();
    $world->site->environmentVersions()->first()->forceFill([
        'variables' => ['VITE_APP_NAME' => 'Shop', 'SITE_URL' => 'https://shop.example.com', 'APP_KEY' => 'secret'],
        'exposed' => ['SITE_URL'],
    ])->save();
    request_build($world);

    expect(next_job()->assertOk()->json('env'))->toBe(['VITE_APP_NAME' => 'Shop', 'SITE_URL' => 'https://shop.example.com']);
});

it('names the secret build variables so the builder masks them in the log', function () {
    $world = builds_world();
    $world->site->environmentVersions()->first()->forceFill([
        'variables' => ['NPM_TOKEN' => 'npm-secret', 'SITE_URL' => 'https://shop.example.com', 'APP_KEY' => 'secret'],
        'exposed' => ['NPM_TOKEN', 'SITE_URL'],
    ])->save();
    request_build($world);

    // Only names: the values are in env already. APP_KEY is not exposed to the build.
    expect(next_job()->assertOk()->json('mask'))->toBe(['NPM_TOKEN']);
});

it('hands native jobs the build and install command overrides from FALAK_BUILD_COMMAND / FALAK_INSTALL_COMMAND', function () {
    $world = builds_world();
    $version = $world->site->environmentVersions()->first();
    $plain = app(BuildConfiguration::class)->cacheKey(app(SiteDirectory::class)->find($world->site->id), 'native', str_repeat('a', 40));
    $version->forceFill(['variables' => ['FALAK_BUILD_COMMAND' => 'pnpm exec playwright install chromium && pnpm run build', 'FALAK_INSTALL_COMMAND' => ' ']])->save();
    request_build($world);

    $native = next_job()->assertOk()->json('native');

    expect($native['build_command'])->toBe('pnpm exec playwright install chromium && pnpm run build')
        ->and($native)->not->toHaveKey('install_command')
        ->and($native)->toHaveKey('upload')
        // A different build command is a different artifact.
        ->and(app(BuildConfiguration::class)->cacheKey(app(SiteDirectory::class)->find($world->site->id), 'native', str_repeat('a', 40)))->not->toBe($plain);
});

it('builds a site in another root directory as another artifact, keeping the key of sites without one', function () {
    $world = builds_world();
    $key = fn () => app(BuildConfiguration::class)->cacheKey(app(SiteDirectory::class)->find($world->site->id), 'native', str_repeat('a', 40));
    $plain = $key();

    $world->site->forceFill(['root_directory' => 'apps/api'])->save();
    $api = $key();
    $world->site->forceFill(['root_directory' => 'apps/web'])->save();
    $web = $key();
    $world->site->forceFill(['root_directory' => null])->save();

    expect($api)->not->toBe($plain)
        ->and($web)->not->toBe($api)
        ->and($key())->toBe($plain);
});

it('runs the build lifecycle from builder events and verifies the uploaded artifact', function () {
    Event::fake([BuildSucceeded::class, BuildFailed::class]);
    $world = builds_world();
    $build = request_build($world);
    $job = next_job()->json();
    $tarball = random_bytes(2048);
    $sha = hash('sha256', $tarball);

    $this->call('PUT', $job['native']['upload']['url'], [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], $tarball)->assertCreated()->assertJsonPath('sha256', $sha);

    $at = now()->toIso8601ZuluString();
    post_events($build->id, [
        ['command_id' => $build->id, 'seq' => 0, 'kind' => 'started', 'at' => $at],
        ['command_id' => $build->id, 'seq' => 1, 'kind' => 'output', 'stream' => 'stdout', 'data' => "==> Cloning\n", 'at' => $at],
        ['command_id' => $build->id, 'seq' => 2, 'kind' => 'progress', 'progress' => 0.5, 'at' => $at],
    ])->assertNoContent();
    expect($build->refresh()->status)->toBe(BuildStatus::Running)->and($build->progress)->toBe(0.5);

    // Re-delivered batch (at-least-once) + finish.
    post_events($build->id, [
        ['command_id' => $build->id, 'seq' => 1, 'kind' => 'output', 'stream' => 'stdout', 'data' => "==> Cloning\n", 'at' => $at],
        ['command_id' => $build->id, 'seq' => 3, 'kind' => 'finished', 'exit_code' => 0, 'at' => $at, 'result' => [
            'build_id' => $build->id, 'mode' => 'native', 'commit' => str_repeat('a', 40), 'duration_ms' => 4200,
            'artifact' => ['sha256' => $sha, 'size_bytes' => 2048, 'format' => 'tar.gz'],
        ]],
    ])->assertNoContent();

    $build->refresh();
    expect($build->status)->toBe(BuildStatus::Succeeded)
        ->and($build->artifact_sha256)->toBe($sha)
        ->and($build->duration_ms)->toBe(4200)
        ->and(app(BuildService::class)->output($build->id))->toHaveCount(2);
    Event::assertDispatched(BuildSucceeded::class, fn ($e) => $e->buildId === $build->id);

    // Agents download through a presigned https URL (deploy.fetch).
    $artifact = app(BuildService::class)->artifactFor($build->id, 600);
    expect($artifact->url)->toStartWith('https://falak.test/api/internal/artifacts/')->and($artifact->sha256)->toBe($sha);
    expect($this->get($artifact->url)->assertOk()->streamedContent())->toBe($tarball);
    $this->get(preg_replace('/signature=[^&]+/', 'signature=forged', $artifact->url))->assertForbidden();
});

it('accepts artifacts larger than post_max_size (the upload streams, capped by max_upload_bytes)', function () {
    request_build(builds_world());
    $job = next_job()->json();
    $tarball = random_bytes(1024);

    // A 78 MB static site failed with 413: ValidatePostSize compared Content-Length to post_max_size (64M).
    $this->call('PUT', $job['native']['upload']['url'], [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'CONTENT_LENGTH' => (string) (200 * 1024 ** 2)], $tarball)
        ->assertCreated()->assertJsonPath('size_bytes', 1024);
});

it('still refuses other bodies over post_max_size', function () {
    $this->call('POST', '/login', [], [], [], ['CONTENT_LENGTH' => (string) (200 * 1024 ** 2)], 'x')->assertStatus(413);
    $this->call('POST', '/api/internal/artifacts/x', [], [], [], ['CONTENT_LENGTH' => (string) (200 * 1024 ** 2)], 'x')->assertStatus(413);
});

it('fails a build whose uploaded artifact does not match the reported checksum', function () {
    Event::fake([BuildFailed::class]);
    $world = builds_world();
    $build = request_build($world);
    next_job();

    post_events($build->id, [['command_id' => $build->id, 'seq' => 0, 'kind' => 'finished', 'exit_code' => 0, 'at' => now()->toIso8601ZuluString(),
        'result' => ['artifact' => ['sha256' => str_repeat('f', 64), 'size_bytes' => 1, 'format' => 'tar.gz']]]])->assertNoContent();

    expect($build->refresh()->status)->toBe(BuildStatus::Failed)->and($build->error)->toBe('The artifact was not uploaded.');
    Event::assertDispatched(BuildFailed::class, fn ($e) => $e->toAlert()->type === 'builds.failed');
});

it('maps the builder timeout exit code to timed_out and validates events', function () {
    $world = builds_world();
    $build = request_build($world);
    next_job();

    post_events($build->id, [['seq' => 'x', 'kind' => 'output', 'at' => 'now']])->assertUnprocessable();
    post_events($build->id, [['command_id' => 'other', 'seq' => 1, 'kind' => 'output', 'at' => 'now']])->assertUnprocessable();
    post_events($build->id, [['command_id' => $build->id, 'seq' => 9, 'kind' => 'finished', 'exit_code' => 124, 'error' => 'build timed out', 'at' => now()->toIso8601ZuluString()]])->assertNoContent();

    expect($build->refresh()->status)->toBe(BuildStatus::TimedOut);
});

it('rejects events from a builder the build is not assigned to', function () {
    $world = builds_world();
    $build = request_build($world);
    next_job();
    [, $token] = app(CreateExternalBuilder::class)($world->organization->id, 'ci', ['native']);

    post_events($build->id, [['command_id' => $build->id, 'seq' => 0, 'kind' => 'started', 'at' => now()->toIso8601ZuluString()]], $token)->assertNotFound();
});

it('tells the builder to abort a cancelled build with 410', function () {
    Event::fake([BuildCancelled::class]);
    $world = builds_world();
    $build = request_build($world);
    next_job();

    $this->post("/builds/{$build->id}/cancel")->assertRedirect();

    expect($build->refresh()->status)->toBe(BuildStatus::Cancelled);
    post_events($build->id, [['command_id' => $build->id, 'seq' => 5, 'kind' => 'output', 'data' => 'x', 'at' => now()->toIso8601ZuluString()]])->assertStatus(410);
    Event::assertDispatched(BuildCancelled::class);
});

it('hands docker jobs the registry image and credentials', function () {
    config(['builds.local_builder.modes' => ['native', 'docker']]);
    $world = builds_world(site: ['runtime' => 'docker', 'build_mode' => 'docker', 'framework' => 'docker', 'php_version' => null, 'app_port' => 3000, 'dockerfile' => 'docker/Dockerfile']);
    $build = request_build($world);
    $job = next_job()->json();

    expect($job['docker'])->toBe([
        'image' => "registry.falak.local/falak/{$world->site->slug}:{$build->id}",
        'dockerfile' => 'docker/Dockerfile',
        'build_args' => ['APP_ENV' => 'production'], // exposed to the deploy script by the fixture
        'registry' => ['server' => 'registry.falak.local', 'username' => 'falak', 'password' => 'registry-secret'],
        'push' => true,
    ])->and($job)->not->toHaveKey('native');

    post_events($build->id, [['command_id' => $build->id, 'seq' => 0, 'kind' => 'finished', 'exit_code' => 0, 'at' => now()->toIso8601ZuluString(),
        'result' => ['image' => ['ref' => $job['docker']['image'], 'digest' => 'sha256:'.str_repeat('b', 64)]]]]);

    expect(app(BuildService::class)->imageFor($build->id)->ref)->toBe("registry.falak.local/falak/{$world->site->slug}@sha256:".str_repeat('b', 64));
});

it('hands compose sites a compose build job and stores the built images', function () {
    config(['builds.local_builder.modes' => ['native', 'docker']]);
    $world = builds_world(site: ['runtime' => 'compose', 'build_mode' => 'docker', 'framework' => 'docker', 'php_version' => null, 'compose_source' => 'repo', 'compose_file' => 'deploy/compose.yaml']);
    $build = request_build($world);
    $job = next_job()->json();
    $prefix = "registry.falak.local/falak/{$world->site->slug}";

    expect($job['mode'])->toBe('docker')
        ->and($job)->not->toHaveKey('docker')
        ->and($job['compose'])->toBe([
            'file' => 'deploy/compose.yaml',
            'image_prefix' => $prefix,
            'tag' => $build->id,
            'build_args' => ['APP_ENV' => 'production'], // exposed to the deploy script by the fixture
            'registry' => ['server' => 'registry.falak.local', 'username' => 'falak', 'password' => 'registry-secret'],
        ]);

    post_events($build->id, [['command_id' => $build->id, 'seq' => 0, 'kind' => 'finished', 'exit_code' => 0, 'at' => now()->toIso8601ZuluString(),
        'result' => ['build_id' => $build->id, 'mode' => 'docker', 'duration_ms' => 5, 'compose' => [
            'file' => 'deploy/compose.yaml',
            'content' => "services:\n  app:\n    build: .\n  redis:\n    image: redis:7\n",
            'images' => ['app' => ['ref' => "{$prefix}/app:{$build->id}", 'digest' => 'sha256:'.str_repeat('c', 64), 'pinned' => "{$prefix}/app@sha256:".str_repeat('c', 64)]],
        ]]]])->assertNoContent();

    $compose = app(BuildService::class)->composeFor($build->id);
    expect($build->refresh()->status)->toBe(BuildStatus::Succeeded)
        ->and($compose->file)->toBe('deploy/compose.yaml')
        ->and($compose->images)->toBe(['app' => "{$prefix}/app@sha256:".str_repeat('c', 64)])
        ->and($compose->registryAuth['username'])->toBe('falak')
        ->and(app(BuildService::class)->imageFor($build->id)?->ref)->toBeNull();

    // Identical rebuild requests reuse the compose result.
    $reused = request_build($world, $build->commit);
    expect(app(BuildService::class)->composeFor($reused->id)?->images)->toBe($compose->images);

    $bad = request_build($world, str_repeat('d', 40));
    next_job();
    post_events($bad->id, [['command_id' => $bad->id, 'seq' => 0, 'kind' => 'finished', 'exit_code' => 0, 'at' => now()->toIso8601ZuluString(),
        'result' => ['compose' => ['file' => 'compose.yaml', 'content' => '', 'images' => []]]]]);
    expect($bad->refresh()->status)->toBe(BuildStatus::Failed)->and($bad->error)->toBe('The builder reported no compose file.');

    // Builders that merge projects report the files read and the repository files to ship.
    $merged = request_build($world, str_repeat('e', 40));
    next_job();
    post_events($merged->id, [['command_id' => $merged->id, 'seq' => 0, 'kind' => 'finished', 'exit_code' => 0, 'at' => now()->toIso8601ZuluString(),
        'result' => ['build_id' => $merged->id, 'mode' => 'docker', 'duration_ms' => 5, 'compose' => [
            'file' => 'deploy/compose.yaml', 'files' => ['deploy/compose.yaml'], 'content' => "services:\n  web:\n    image: nginx\n", 'images' => [],
            'assets' => [['path' => 'deploy/nginx.conf', 'content' => base64_encode('x'), 'mode' => 0o644]], 'missing' => ['deploy/data'],
        ]]]])->assertNoContent();
    $result = app(BuildService::class)->composeFor($merged->id);
    expect($result->repoFiles())->toBe(['deploy/nginx.conf'])->and($result->missing)->toBe(['deploy/data'])
        ->and($compose->repoFiles())->toBeNull();

    $escape = request_build($world, str_repeat('f', 40));
    next_job();
    post_events($escape->id, [['command_id' => $escape->id, 'seq' => 0, 'kind' => 'finished', 'exit_code' => 0, 'at' => now()->toIso8601ZuluString(),
        'result' => ['compose' => ['file' => 'compose.yaml', 'files' => ['compose.yaml'], 'content' => "services: {}\n", 'images' => [],
            'assets' => [['path' => '../etc/passwd', 'content' => '']]]]]]);
    expect($escape->refresh()->status)->toBe(BuildStatus::Failed)->and($escape->error)->toContain('invalid repository file');
});

it('only hands organization builders their own builds and respects modes', function () {
    config(['builds.local_builder.token' => null]);
    $world = builds_world();
    [, $mine] = app(CreateExternalBuilder::class)($world->organization->id, 'mine', ['docker']);
    [, $native] = app(CreateExternalBuilder::class)($world->organization->id, 'native', ['native']);
    [, $foreign] = app(CreateExternalBuilder::class)((string) Str::ulid(), 'foreign', ['native', 'docker']);
    request_build($world);

    next_job($foreign)->assertNoContent();
    next_job($mine)->assertNoContent();
    next_job($native)->assertOk();
});

it('reuses an identical successful build instead of rebuilding', function () {
    $world = builds_world();
    $first = request_build($world);
    $key = JobPayload::artifactKey($first);
    $first->forceFill(['status' => BuildStatus::Succeeded, 'artifact_key' => $key, 'artifact_sha256' => str_repeat('a', 64), 'artifact_size' => 1])->save();

    $again = app(BuildService::class)->request(new BuildRequest($world->site->id, str_repeat('A', 40), 'main', 'dep-2'));
    $other = app(BuildService::class)->request(new BuildRequest($world->site->id, str_repeat('b', 40), 'main'));

    expect($again->status)->toBe(BuildStatus::Succeeded)->and($again->reused)->toBeTrue()
        ->and($other->status)->toBe(BuildStatus::Queued);
});

it('requeues builds whose builder died and expires stuck builds', function () {
    $world = builds_world();
    $orphan = request_build($world);
    next_job();
    $orphan->forceFill(['assigned_at' => now()->subMinutes(10)])->save();

    $stale = request_build($world, str_repeat('c', 40));
    $stale->forceFill(['created_at' => now()->subHours(2)])->save();

    (new ExpireBuilds)->handle(app(BuildProgress::class));

    expect($orphan->refresh()->status)->toBe(BuildStatus::Queued)->and($orphan->builder_id)->toBeNull()
        ->and($stale->refresh()->status)->toBe(BuildStatus::Failed);

    next_job();
    $orphan->refresh()->forceFill(['status' => BuildStatus::Running, 'started_at' => now()->subHours(2)])->save();
    (new ExpireBuilds)->handle(app(BuildProgress::class));
    expect($orphan->refresh()->status)->toBe(BuildStatus::TimedOut);
});

it('prunes artifacts beyond the per-site retention', function () {
    config(['builds.artifacts.keep_per_site' => 2]);
    $world = builds_world();
    $builds = [];

    foreach (range(1, 3) as $i) {
        $build = request_build($world, str_repeat((string) $i, 40));
        $key = JobPayload::artifactKey($build);
        @mkdir(dirname("{$this->artifacts}/{$key}"), 0777, true);
        file_put_contents("{$this->artifacts}/{$key}", "artifact {$i}");
        $build->forceFill(['status' => BuildStatus::Succeeded, 'artifact_key' => $key, 'artifact_sha256' => str_repeat('a', 64), 'created_at' => now()->subMinutes(10 - $i)])->save();
        $builds[] = $build;
    }

    (new PruneArtifacts)->handle(app(ArtifactStorage::class));

    expect($builds[0]->refresh()->artifact_pruned_at)->not->toBeNull()
        ->and(is_file("{$this->artifacts}/{$builds[0]->artifact_key}"))->toBeFalse()
        ->and($builds[2]->refresh()->artifact_pruned_at)->toBeNull()
        ->and(is_file("{$this->artifacts}/{$builds[2]->artifact_key}"))->toBeTrue()
        ->and(app(BuildService::class)->artifactFor($builds[0]->id))->toBeNull();
});

it('installs falak-builder on builder servers when they finish provisioning', function () {
    $world = builds_world();
    $server = sites_server($world->organization->id, ['type' => ServerType::Builder, 'name' => 'builder-1']);

    ServerProvisioned::dispatch($server->id, $world->organization->id, 'builder', 'builder-1');

    $builder = Builder::query()->where('server_id', $server->id)->sole();
    $env = $world->agents->dispatched('system.write_file')[0]['payload'];

    expect($builder->kind)->toBe('server')
        ->and($builder->organization_id)->toBe($world->organization->id)
        ->and($env['path'])->toBe('/etc/falak/builder.env')
        ->and($env['mode'])->toBe('0600')
        ->and($env['content'])->toContain('FALAK_BUILDER_TOKEN=kbt_')
        ->and($world->agents->last('system.exec')['payload']['script'])->toContain('systemctl restart falak-builder');

    preg_match('/FALAK_BUILDER_TOKEN=(\S+)/', $env['content'], $m);
    next_job($m[1])->assertNoContent();
});

it('shows builds and builders to members and lets them create external builders', function () {
    $world = builds_world();
    $build = request_build($world);

    $this->get('/builds')->assertOk()->assertInertia(fn ($page) => $page->component('Builds/Index', false)->where('builds.data.0.id', $build->id));
    $this->get("/builds/{$build->id}")->assertOk()->assertInertia(fn ($page) => $page->component('Builds/Show', false)->where('build.status', 'queued'));
    $this->getJson("/builds/{$build->id}/output?after=0")->assertOk()->assertJsonPath('data.build.id', $build->id);

    $this->post('/builds/builders', ['name' => 'ci', 'modes' => ['native']])->assertRedirect()->assertSessionHas('builderToken');
    $this->get('/settings/builders')->assertOk()->assertInertia(fn ($page) => $page->component('Builds/Builders', false)->has('builders', 1));

    actingAsMember(Role::Viewer);
    $this->get("/builds/{$build->id}")->assertNotFound();
});

it('deploys end to end with the real build pipeline', function () {
    $world = builds_world();
    $deployment = app(TriggerDeployment::class)(app(SiteDirectory::class)->find($world->site->id), Trigger::Manual);
    $build = Build::query()->where('deployment_id', $deployment->id)->sole();
    $job = next_job()->json();
    $tarball = 'release bytes';

    $this->call('PUT', $job['native']['upload']['url'], [], [], [], [], $tarball)->assertCreated();
    post_events($build->id, [
        ['command_id' => $build->id, 'seq' => 0, 'kind' => 'started', 'at' => now()->toIso8601ZuluString()],
        ['command_id' => $build->id, 'seq' => 1, 'kind' => 'output', 'stream' => 'stdout', 'data' => "composer install\n", 'at' => now()->toIso8601ZuluString()],
        ['command_id' => $build->id, 'seq' => 2, 'kind' => 'finished', 'exit_code' => 0, 'at' => now()->toIso8601ZuluString(),
            'result' => ['commit' => str_repeat('a', 40), 'artifact' => ['sha256' => hash('sha256', $tarball), 'size_bytes' => strlen($tarball), 'format' => 'tar.gz']]],
    ])->assertNoContent();

    $fetch = $world->agents->dispatched('deploy.hook') ? (deploy_complete($world->agents, 'deploy.hook') ? $world->agents->last('deploy.fetch') : null) : null;
    expect($fetch['payload']['artifact'])->toMatchArray(['sha256' => hash('sha256', $tarball), 'size_bytes' => strlen($tarball), 'format' => 'tar.gz'])
        ->and($fetch['payload']['artifact']['url'])->toStartWith('https://falak.test/api/internal/artifacts/');

    deploy_run_all($world->agents);

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and(OutputLine::query()->where('deployment_id', $deployment->id)->where('phase', 'build')->pluck('data')->all())->toContain("composer install\n");
});

it('does not hand docker jobs to a native-only host builder (the default)', function () {
    $world = builds_world(site: ['runtime' => 'docker', 'build_mode' => 'docker', 'framework' => 'docker', 'php_version' => null, 'app_port' => 3000]);
    request_build($world);

    next_job()->assertNoContent();
});

it('fails the builds of a builder process that restarted, and the deployment with them', function () {
    $world = builds_world();
    $deployment = app(TriggerDeployment::class)(app(SiteDirectory::class)->find($world->site->id), Trigger::Manual);
    $build = Build::query()->where('deployment_id', $deployment->id)->sole();
    $poll = fn (string $run, string $name = 'cp-1') => test()->withToken('local-builder-token-123')->getJson("/api/internal/builds/next?wait=0&builder={$name}&run={$run}");

    $poll('run-aaaaaaaa')->assertOk()->assertJsonPath('id', $build->id);
    post_events($build->id, [['command_id' => $build->id, 'seq' => 0, 'kind' => 'started', 'at' => now()->toIso8601ZuluString()]])->assertNoContent();
    expect($build->refresh())->builder_run_id->toBe('run-aaaaaaaa')->builder_name->toBe('cp-1');

    // Same run polling again (it would not while busy) or another builder name: nothing happens.
    $poll('run-aaaaaaaa')->assertNoContent();
    $poll('run-bbbbbbbb', 'other-builder')->assertNoContent();
    expect($build->refresh()->status)->toBe(BuildStatus::Running);

    // The process restarted: the next poll carries a new run id.
    $poll('run-cccccccc')->assertNoContent();

    expect($build->refresh())->status->toBe(BuildStatus::Failed)->error->toBe('Builder cp-1 restarted during the build.')
        ->and($deployment->refresh()->status)->toBe(DeploymentStatus::Failed);
});

it('fails a running build whose builder stops heartbeating, and answers heartbeats', function () {
    $world = builds_world();
    $build = request_build($world);
    test()->withToken('local-builder-token-123')->getJson('/api/internal/builds/next?wait=0&builder=cp-1&run=run-aaaaaaaa')->assertOk();
    post_events($build->id, [['command_id' => $build->id, 'seq' => 0, 'kind' => 'started', 'at' => now()->toIso8601ZuluString()]])->assertNoContent();

    $this->travel(60)->seconds();
    test()->withToken('local-builder-token-123')->postJson("/api/internal/builds/{$build->id}/heartbeat")->assertNoContent();
    $this->travel(60)->seconds();
    (new ExpireBuilds)->handle(app(BuildProgress::class));
    expect($build->refresh()->status)->toBe(BuildStatus::Running);

    $this->travel(40)->seconds();
    (new ExpireBuilds)->handle(app(BuildProgress::class));
    expect($build->refresh())->status->toBe(BuildStatus::Failed)->error->toBe('The builder stopped responding (no heartbeat for 90s).');

    // The builder learns it on its next heartbeat and aborts.
    test()->withToken('local-builder-token-123')->postJson("/api/internal/builds/{$build->id}/heartbeat")->assertStatus(410);

    // Builders without run ids (older falak-builder) are left to the build timeout.
    $legacy = request_build($world, str_repeat('d', 40));
    next_job();
    $legacy->refresh()->forceFill(['status' => BuildStatus::Running, 'started_at' => now()->subMinutes(5), 'heartbeat_at' => now()->subMinutes(5)])->save();
    (new ExpireBuilds)->handle(app(BuildProgress::class));
    expect($legacy->refresh()->status)->toBe(BuildStatus::Running);
});
