<?php

use Falak\Deployments\Contracts\DeploymentTrigger;
use Falak\Deployments\Domain\Enums\DeploymentStatus;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\Deployments\Domain\Models\Release;
use Falak\Fleet\Domain\Models\Agent;
use Falak\Functions\Application\Code;
use Falak\Functions\Application\FunctionStore;
use Falak\Functions\Domain\Models\CloudFunction;
use Falak\Functions\Domain\Models\FunctionApiKey;
use Falak\Functions\Domain\Models\FunctionDraft;
use Falak\Functions\Domain\Models\FunctionSchedule;
use Falak\Functions\Domain\Models\FunctionVersion;
use Falak\Identity\Contracts\Role;
use Falak\Processes\Contracts\ScheduleDirectory;
use Falak\Processes\Infrastructure\StateCompiler;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\SiteDomains;
use Falak\Sites\Contracts\SiteFactory;
use Falak\Sites\Domain\Models\EnvironmentVersion;
use Falak\Sites\Domain\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../../Deployments/tests/Support/helpers.php';
require_once __DIR__.'/../../../Projects/tests/Support/helpers.php';

const FN_V2 = "import { Hono } from 'hono'\nconst app = new Hono()\napp.get('/', (c) => c.text('v2'))\nexport default app\n";

/**
 * A function site on one ready server (agent with the fn.v1 feature) and its function row (Hello starter as v1).
 */
function fn_world(Role $role = Role::Owner): array
{
    $world = deploy_world(site: [
        'name' => 'Hooks', 'runtime' => 'function', 'build_mode' => 'docker', 'framework' => 'docker', 'php_version' => null,
        'repository' => null, 'source_connection_id' => null, 'branch' => null, 'deploy_script' => '', 'health_check_path' => null,
        'laravel' => [],
    ], role: $role);
    fn_agent($world->servers[0]->id, $world->organization->id);
    $function = app(FunctionStore::class)->ensure(app(SiteDirectory::class)->find($world->site->id));

    return [$world, $function];
}

function fn_agent(string $serverId, string $organizationId, array $features = ['fn.v1']): void
{
    Agent::factory()->create(['server_id' => $serverId, 'organization_id' => $organizationId, 'facts' => ['features' => $features, 'memory_bytes' => 4 * 1024 ** 3]]);
}

function fn_url(Site $site, string $path = ''): string
{
    return "/sites/{$site->id}/function".$path;
}

it('creates a function from the canvas and deploys its starter through the gateway', function () {
    $world = deploy_world(site: ['runtime' => 'static', 'framework' => 'static', 'php_version' => null]);
    fn_agent($world->servers[0]->id, $world->organization->id);
    $environment = projects_default_env($world->organization);

    $response = $this->postJson("/projects/{$environment->project_id}/{$environment->slug}/functions", [
        'name' => 'Webhooks',
        'server_id' => $world->servers[0]->id,
        'starter' => 'webhook',
    ])->assertCreated();

    $site = Site::query()->findOrFail($response->json('data.site_id'));
    $function = CloudFunction::query()->where('site_id', $site->id)->firstOrFail();
    $version = $function->head();
    $apply = $world->agents->last('fn.release.apply');

    expect($site->runtime->value)->toBe('function')
        ->and($site->app_port)->toBeNull()
        ->and($site->health_check_path)->toBeNull()
        // no domain chosen: the organization's default (a generated name here)
        ->and(app(SiteDomains::class)->primaryDomains([$site->id])[$site->id] ?? null)->toBe("{$site->slug}.203-0-113-1.sslip.io")
        ->and($version->number)->toBe(1)
        ->and($version->files['index.ts'])->toContain('X-Hub-Signature-256')
        ->and($response->json('data.deployment_id'))->not->toBeNull()
        ->and($apply['handle']->serverId)->toBe($world->servers[0]->id)
        ->and($apply['payload']['site'])->toBe($site->slug)
        ->and($apply['payload']['entrypoint'])->toBe('index.ts')
        ->and($apply['payload']['files'])->toBe([['path' => 'index.ts', 'content' => $version->files['index.ts']]])
        ->and($apply['payload']['image'])->toContain('/falak-fn-bun:')
        ->and($apply['payload']['scaling'])->toBe(['min_instances' => 0, 'max_instances' => 5, 'concurrency' => 50, 'idle_timeout_s' => 300])
        ->and($apply['payload']['limits']['memory_bytes'])->toBe(256 * 1024 * 1024)
        ->and($apply['payload']['env'])->not->toHaveKey('PORT')
        ->and($apply['payload']['env']['FALAK_SITE_ID'])->toBe(strtoupper($site->id));

    $world->agents->succeed($apply['handle'], ['release' => $apply['payload']['release'], 'installed' => true, 'boot_ms' => 180]);
    $deployment = Deployment::query()->findOrFail($response->json('data.deployment_id'));

    expect($deployment->status)->toBe(DeploymentStatus::Succeeded)
        ->and($deployment->commit)->toBe($version->hash)
        ->and($deployment->commit_message)->toBe('v1: Created from the Signed webhook receiver starter')
        ->and(Release::query()->find($deployment->release_id)->commit)->toBe($version->hash)
        // functions have no health check step and no deploy.prune (the agent prunes releases itself)
        ->and(deploy_types($world->agents))->not->toContain('deploy.prune');
});

it('creates a draft function from the canvas: nothing deployed, routed in the gateway or scheduled until Deploy', function () {
    $world = deploy_world(site: ['runtime' => 'static', 'framework' => 'static', 'php_version' => null]);
    fn_agent($world->servers[0]->id, $world->organization->id);
    $environment = projects_default_env($world->organization);
    $jobsOf = fn (Site $site) => array_keys(array_filter(app(StateCompiler::class)->compile($world->servers[0]->id)->jobMeta, fn (array $meta) => $meta['site_id'] === $site->id));

    $response = $this->postJson("/projects/{$environment->project_id}/{$environment->slug}/functions", [
        'name' => 'Cron', 'server_id' => $world->servers[0]->id, 'starter' => 'scheduled', 'deploy' => false,
    ])->assertCreated()->assertJsonPath('data.deployment_id', null)->assertJsonPath('warnings', []);

    $site = Site::query()->findOrFail($response->json('data.site_id'));
    $function = CloudFunction::query()->where('site_id', $site->id)->firstOrFail();

    expect(Deployment::query()->where('site_id', $site->id)->count())->toBe(0)
        ->and($world->agents->dispatched('fn.release.apply'))->toBe([])
        // the starter's schedule is saved, but no cron job runs it before a release is live
        ->and(FunctionSchedule::query()->where('function_id', $function->id)->count())->toBe(1)
        ->and($jobsOf($site))->toBe([])
        ->and($this->getJson(fn_url($site))->assertOk()->json('data.live'))->toBeNull();

    // Deploy from the Code tab: the starter (v1) unchanged.
    $head = $function->head();
    $deploymentId = $this->postJson(fn_url($site, '/deploy'), ['files' => $head->files, 'base_version_id' => $head->id])
        ->assertOk()->assertJsonPath('data.created', false)->json('data.deployment_id');

    expect($deploymentId)->not->toBeNull()
        ->and($world->agents->last('fn.release.apply')['payload']['site'])->toBe($site->slug);

    deploy_run_all($world->agents);

    expect(Deployment::query()->findOrFail($deploymentId)->status)->toBe(DeploymentStatus::Succeeded)
        ->and($jobsOf($site))->toHaveCount(1);
});

it('refuses servers whose agent has no function gateway', function () {
    $world = deploy_world(site: ['runtime' => 'static', 'framework' => 'static', 'php_version' => null]);
    fn_agent($world->servers[0]->id, $world->organization->id, features: []);
    $environment = projects_default_env($world->organization);

    $this->postJson("/projects/{$environment->project_id}/{$environment->slug}/functions", ['name' => 'Old', 'server_id' => $world->servers[0]->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['server_ids' => 'too old for functions']);
});

it('versions every deploy, detects stale editors and skips unchanged code', function () {
    [$world, $function] = fn_world();
    $v1 = $function->head();

    $this->getJson(fn_url($world->site))->assertOk()
        ->assertJsonPath('data.head.number', 1)
        ->assertJsonPath('data.runtime.label', 'Bun')
        ->assertJsonPath('data.can.deploy', true);

    // Draft autosave, then deploy removes it.
    $this->putJson(fn_url($world->site, '/draft'), ['files' => ['index.ts' => FN_V2], 'base_version_id' => $v1->id])->assertOk();
    expect(FunctionDraft::query()->count())->toBe(1);

    $deployed = $this->postJson(fn_url($world->site, '/deploy'), ['files' => ['index.ts' => FN_V2], 'message' => 'Say v2', 'base_version_id' => $v1->id])
        ->assertCreated()
        ->assertJsonPath('data.version.number', 2)
        ->assertJsonPath('data.version.author', $world->user->name);

    expect(FunctionDraft::query()->count())->toBe(0)
        ->and(Deployment::query()->find($deployed->json('data.deployment_id'))->commit_message)->toBe('v2: Say v2')
        ->and($world->agents->last('fn.release.apply')['payload']['files'][0]['content'])->toBe(FN_V2);

    // A teammate still editing from v1 gets the conflict with the newer code.
    $conflict = $this->postJson(fn_url($world->site, '/deploy'), ['files' => ['index.ts' => 'export default { fetch: () => new Response("mine") }'], 'base_version_id' => $v1->id])
        ->assertStatus(409)
        ->assertJsonPath('head.number', 2);
    expect($conflict->json('head.files')['index.ts'])->toBe(FN_V2);

    // Same code as the newest version: no new version.
    $this->postJson(fn_url($world->site, '/deploy'), ['files' => ['index.ts' => FN_V2], 'base_version_id' => $deployed->json('data.version.id')])
        ->assertOk()
        ->assertJsonPath('data.created', false);

    expect(FunctionVersion::query()->where('function_id', $function->id)->count())->toBe(2)
        ->and($this->getJson(fn_url($world->site, '/versions'))->json('data.*.number'))->toBe([2, 1]);
});

it('validates the code', function (array $files, string $error) {
    [$world, $function] = fn_world();

    $this->postJson(fn_url($world->site, '/deploy'), ['files' => $files, 'base_version_id' => $function->head()->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['files' => $error]);
})->with([
    'no entrypoint' => [['main.ts' => 'x'], 'entrypoint index.ts is missing'],
    'escaping path' => [['index.ts' => 'x', '../etc/passwd' => 'x'], 'not a valid file path'],
    'dot file' => [['index.ts' => 'x', '.env' => 'x'], 'not a valid file path'],
    'dependency folder' => [['index.ts' => 'x', 'node_modules/hono/index.js' => 'x'], 'created on the server'],
    'file and folder' => [['index.ts' => 'x', 'lib' => 'x', 'lib/db.ts' => 'x'], 'lib is both a file and a folder'],
    'case only' => [['index.ts' => 'x', 'lib/DB.ts' => 'x', 'lib/db.ts' => 'x'], 'differ only in case'],
    'too deep' => [['index.ts' => 'x', 'a/b/c/d/e/f/g/h/i.ts' => 'x'], 'nested too deep'],
    'digit file and folder' => [['index.ts' => 'x', '1' => 'x', '1/b.ts' => 'x'], '1 is both a file and a folder'],
]);

it('refuses oversized code before looking at each file', function () {
    [$world, $function] = fn_world();
    $many = array_fill_keys(array_map(fn ($i) => "f{$i}.ts", range(1, 40000)), 'x');

    $started = microtime(true);
    $this->putJson(fn_url($world->site, '/draft'), ['files' => $many])->assertUnprocessable()->assertJsonValidationErrors(['files']);
    expect(microtime(true) - $started)->toBeLessThan(1.0);

    expect(fn () => Code::files(array_fill_keys(array_map(fn ($i) => "f{$i}.ts", range(1, 40000)), 'x'), 'index.ts'))
        ->toThrow(ValidationException::class, 'at most 50 files');
    expect(fn () => Code::files(['index.ts' => str_repeat('x', 1024 * 1024)], 'index.ts'))
        ->toThrow(ValidationException::class, 'larger than');

    $this->call('PUT', fn_url($world->site, '/draft'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'CONTENT_LENGTH' => (string) (Code::maxRequestBytes() + 1)], '{"files":{}}')
        ->assertStatus(413);
});

it('deploys several files in folders and shows what changed per file', function () {
    [$world, $function] = fn_world();
    Agent::query()->where('server_id', $world->servers[0]->id)->update(['facts' => ['features' => ['fn.v1', 'fn.v2', 'fn.v3']]]);
    $files = ['index.ts' => "import { users } from './routes/users'\nexport default { fetch: () => Response.json(users) }\n", 'routes/users.ts' => "export const users = []\n"];

    $this->postJson(fn_url($world->site, '/deploy'), ['files' => $files, 'base_version_id' => $function->head()->id])->assertCreated();
    $apply = $world->agents->last('fn.release.apply')['payload'];

    expect(array_column($apply['files'], 'path'))->toBe(['index.ts', 'routes/users.ts']);
    $version = $this->getJson(fn_url($world->site, '/versions/2'))->assertOk();

    expect($version->json('data.files')['routes/users.ts'])->toBe("export const users = []\n");
    $version->assertJsonPath('data.changes', [['path' => 'index.ts', 'status' => 'modified'], ['path' => 'routes/users.ts', 'status' => 'added']]);
});

it('keeps several files off agents that cannot write file trees', function () {
    [$world, $function] = fn_world(); // agent reports fn.v1 only
    $files = ['index.ts' => FN_V2, 'lib/util.ts' => 'export const x = 1'];

    $response = $this->postJson(fn_url($world->site, '/deploy'), ['files' => $files, 'base_version_id' => $function->head()->id])->assertCreated();

    expect($response->json('data.deployment_id'))->toBeNull()
        ->and($response->json('warnings.0'))->toContain('too old for functions with several files')
        ->and($world->agents->dispatched('fn.release.apply'))->toBeEmpty();
    $this->postJson(fn_url($world->site, '/versions/2/deploy'))->assertUnprocessable()->assertJsonValidationErrors(['version' => 'update it first']);
});

it('keeps several files off a server whose agent is gone', function () {
    [$world, $function] = fn_world();
    Agent::query()->where('server_id', $world->servers[0]->id)->delete();
    $files = ['index.ts' => FN_V2, 'lib/util.ts' => 'export const x = 1'];

    $response = $this->postJson(fn_url($world->site, '/deploy'), ['files' => $files, 'base_version_id' => $function->head()->id])->assertCreated();

    expect($response->json('data.deployment_id'))->toBeNull()
        ->and($response->json('warnings.0'))->toContain('no connected Falak agent')
        ->and($world->agents->dispatched('fn.release.apply'))->toBeEmpty();
});

it('rolls back to an earlier version with its exact code', function () {
    [$world, $function] = fn_world();
    $v1 = $function->head();
    $this->postJson(fn_url($world->site, '/deploy'), ['files' => ['index.ts' => FN_V2], 'base_version_id' => $v1->id])->assertCreated();
    deploy_run_all($world->agents);

    $rollback = $this->postJson(fn_url($world->site, '/versions/1/deploy'))->assertCreated();
    $apply = $world->agents->last('fn.release.apply')['payload'];
    deploy_run_all($world->agents);
    $deployment = Deployment::query()->find($rollback->json('data.deployment_id'));

    expect($apply['files'][0]['content'])->toBe($v1->files['index.ts'])
        ->and($deployment->status)->toBe(DeploymentStatus::Succeeded)
        ->and($deployment->commit)->toBe($v1->hash)
        ->and($deployment->commit_message)->toStartWith('Roll back to v1')
        ->and(Release::current($world->site->id)->commit)->toBe($v1->hash)
        ->and($this->getJson(fn_url($world->site))->json('data.live.number'))->toBe(1);
});

it('redeploys the live code when scaling changes', function () {
    [$world, $function] = fn_world();
    app(DeploymentTrigger::class)->deploy($world->site->id, null, $function->head()->hash);
    deploy_run_all($world->agents);

    $this->putJson(fn_url($world->site, '/settings'), ['min_instances' => 3, 'max_instances' => 2])
        ->assertUnprocessable()->assertJsonValidationErrors('max_instances');
    $this->putJson(fn_url($world->site, '/settings'), ['max_instances' => 99])
        ->assertUnprocessable()->assertJsonValidationErrors('max_instances');

    $response = $this->putJson(fn_url($world->site, '/settings'), ['min_instances' => 1, 'max_instances' => 10, 'memory_mb' => 512, 'idle_timeout_s' => 60])->assertOk();

    expect($response->json('data.deployment_id'))->not->toBeNull()
        ->and($world->agents->last('fn.release.apply')['payload']['scaling'])->toBe(['min_instances' => 1, 'max_instances' => 10, 'concurrency' => 50, 'idle_timeout_s' => 60])
        ->and($world->agents->last('fn.release.apply')['payload']['limits']['memory_bytes'])->toBe(512 * 1024 * 1024);
});

it('lets viewers read the code but not change or deploy it', function () {
    [$world, $function] = fn_world();
    actingAsMember(Role::Viewer, $world->organization);

    $this->getJson(fn_url($world->site))->assertOk()->assertJsonPath('data.can.edit', false)->assertJsonPath('data.can.deploy', false);
    $this->putJson(fn_url($world->site, '/draft'), ['files' => ['index.ts' => 'x']])->assertForbidden();
    $this->postJson(fn_url($world->site, '/deploy'), ['files' => ['index.ts' => 'x']])->assertForbidden();
    $this->postJson(fn_url($world->site, '/versions/1/deploy'))->assertForbidden();
});

it('removes the function from its servers and forgets its code when the site is deleted', function () {
    [$world, $function] = fn_world();

    app(SiteFactory::class)->delete($world->site->id);

    expect($world->agents->last('fn.release.remove')['payload'])->toBe(['site' => $world->site->slug])
        ->and(CloudFunction::query()->count())->toBe(0)
        ->and(FunctionVersion::query()->count())->toBe(0);
});

it('hashes code independent of file order', function () {
    expect(Code::hash(Code::files(['b.ts' => '2', 'index.ts' => '1'], 'index.ts'), 'index.ts'))
        ->toBe(Code::hash(Code::files(['index.ts' => '1', 'b.ts' => '2'], 'index.ts'), 'index.ts'));
});

it('reports live instances from the gateway without waiting for the agent', function () {
    [$world] = fn_world();

    // First poll: asks the leader's gateway, nothing known yet.
    $this->getJson(fn_url($world->site, '/status'))->assertOk()->assertJsonPath('data.status', null);
    $asked = $world->agents->last('fn.status');
    expect($asked['payload'])->toBe(['site' => $world->site->slug])
        ->and($asked['handle']->serverId)->toBe($world->servers[0]->id);

    $world->agents->succeed($asked['handle'], ['functions' => [
        ['site' => 'other', 'release' => 'x', 'running' => 9, 'starting' => 0, 'in_flight' => 0, 'cold_starts' => 0, 'requests' => 0],
        ['site' => $world->site->slug, 'release' => 'r1', 'running' => 2, 'starting' => 0, 'in_flight' => 1, 'cold_starts' => 4, 'requests' => 120],
    ]]);

    $this->getJson(fn_url($world->site, '/status'))->assertOk()
        ->assertJsonPath('data.status.running', 2)
        ->assertJsonPath('data.status.cold_starts', 4);
    // Fresh answer: no new command within a few seconds.
    expect(count($world->agents->dispatched('fn.status')))->toBe(1);
});

function fn_deployed(): array
{
    [$world, $function] = fn_world();
    app(DeploymentTrigger::class)->deploy($world->site->id, null, $function->head()->hash);
    deploy_run_all($world->agents);

    return [$world, $function];
}

it('turns schedules into cron jobs of the leader that run the function through the gateway', function () {
    [$world] = fn_deployed();

    $this->postJson(fn_url($world->site, '/schedules'), ['name' => 'Bad', 'expression' => '61 * * * *'])
        ->assertUnprocessable()->assertJsonValidationErrors('expression');

    $created = $this->postJson(fn_url($world->site, '/schedules'), ['name' => "Nightly 'cleanup'", 'expression' => '0  3 * * *', 'timezone' => 'Europe/Berlin', 'timeout_s' => 120])
        ->assertCreated()
        ->assertJsonPath('data.expression', '0 3 * * *')
        ->json('data');
    $this->postJson(fn_url($world->site, '/schedules'), ['name' => 'Off', 'expression' => '@daily', 'enabled' => false])->assertCreated();

    $jobs = app(StateCompiler::class)->compile($world->servers[0]->id)->jobs;
    expect($jobs)->toHaveCount(1);
    $job = $jobs[0];

    expect($job['name'])->toBe($created['job'])
        ->and($job['name'])->toBe("{$world->site->slug}.function-{$created['key']}")
        ->and($job['schedule'])->toBe('0 3 * * *')
        ->and($job['timezone'])->toBe('Europe/Berlin')
        ->and($job['user'])->toBe('root')
        ->and($job['overlap'])->toBe('skip')
        ->and($job['timeout_s'])->toBe(150)
        ->and($job['command'])->toBe("/usr/local/bin/falak-agent fn-run --site '{$world->site->slug}' --schedule '{$created['key']}' --name 'Nightly '\''cleanup'\''' --cron '0 3 * * *' --timeout 120")
        // Insights attributes the heartbeats to the function.
        ->and(app(ScheduleDirectory::class)->forServer($world->servers[0]->id))->toBeArray();

    // Disable → gone from the server.
    $this->putJson(fn_url($world->site, "/schedules/{$created['id']}"), ['name' => 'Nightly', 'expression' => '0 3 * * *', 'enabled' => false])->assertOk();
    expect(app(StateCompiler::class)->compile($world->servers[0]->id)->jobs)->toBe([]);
});

it('runs a schedule now and streams its output', function () {
    [$world, $function] = fn_deployed();
    $schedule = $this->postJson(fn_url($world->site, '/schedules'), ['name' => 'Report', 'expression' => '@hourly'])->json('data');

    $run = $this->postJson(fn_url($world->site, "/schedules/{$schedule['id']}/run"))->assertStatus(202)->json('data.run_id');
    $dispatched = $world->agents->last('fn.run');

    expect($dispatched['payload'])->toBe(['site' => $world->site->slug, 'schedule' => $schedule['key'], 'name' => 'Report', 'cron' => '@hourly', 'timeout_s' => 300])
        ->and($dispatched['handle']->id)->toBe($run);

    $this->getJson(fn_url($world->site, "/runs/{$run}"))->assertOk()->assertJsonPath('data.finished', false);
    $world->agents->succeed($dispatched['handle'], ['exit_code' => 0, 'duration_ms' => 812]);
    $this->getJson(fn_url($world->site, "/runs/{$run}"))->assertOk()
        ->assertJsonPath('data.finished', true)
        ->assertJsonPath('data.exit_code', 0)
        ->assertJsonPath('data.duration_ms', 812);

    // Another function's (or a made-up) run id is not readable here.
    $this->getJson(fn_url($world->site, '/runs/01M3XXXXXXXXXXXXXXXXXXXXXX'))->assertNotFound();
});

it('lets viewers see schedules but not change or run them', function () {
    [$world] = fn_deployed();
    $schedule = $this->postJson(fn_url($world->site, '/schedules'), ['name' => 'Report', 'expression' => '@hourly'])->json('data');
    actingAsMember(Role::Viewer, $world->organization);

    $this->getJson(fn_url($world->site, '/schedules'))->assertOk()->assertJsonPath('data.can.manage', false)->assertJsonCount(1, 'data.schedules');
    $this->postJson(fn_url($world->site, '/schedules'), ['name' => 'x', 'expression' => '@daily'])->assertForbidden();
    $this->postJson(fn_url($world->site, "/schedules/{$schedule['id']}/run"))->assertForbidden();
});

it('offers every runtime and starters for each language', function () {
    actingAsMember(Role::Owner);
    $data = $this->getJson('/functions/starters')->assertOk()->json('data');

    expect(array_column($data['runtimes'], 'key'))->toBe(['bun', 'node', 'deno', 'python', 'go'])
        ->and(count($data['starters']))->toBeGreaterThanOrEqual(10);

    foreach ($data['starters'] as $starter) {
        expect($starter['families'])->toBe(['ts', 'python', 'go']);
    }
});

it('creates a Python function with the starter’s variables and schedule', function () {
    $world = deploy_world(site: ['runtime' => 'static', 'framework' => 'static', 'php_version' => null]);
    fn_agent($world->servers[0]->id, $world->organization->id);
    $environment = projects_default_env($world->organization);

    $response = $this->postJson("/projects/{$environment->project_id}/{$environment->slug}/functions", [
        'name' => 'Pinger', 'server_id' => $world->servers[0]->id, 'starter' => 'uptime-monitor', 'runtime' => 'python',
    ])->assertCreated();

    $site = Site::query()->findOrFail($response->json('data.site_id'));
    $function = CloudFunction::query()->where('site_id', $site->id)->firstOrFail();
    $apply = $world->agents->last('fn.release.apply')['payload'];
    $variables = EnvironmentVersion::query()->where('site_id', $site->id)->orderByDesc('version')->first()->variables;

    expect($function->runtime)->toBe('python')
        ->and($function->entrypoint)->toBe('main.py')
        ->and(array_keys($function->head()->files))->toBe(['main.py'])
        ->and($function->head()->files['main.py'])->toContain('async def scheduled(event: dict)')
        ->and($apply['image'])->toContain('/falak-fn-python:')
        ->and($apply['entrypoint'])->toBe('main.py')
        ->and($variables)->toMatchArray(['URLS' => '', 'ALERT_WEBHOOK_URL' => ''])
        ->and(FunctionSchedule::query()->where('function_id', $function->id)->pluck('expression')->all())->toBe(['*/5 * * * *']);
});

it('creates a Go function from a starter', function () {
    $world = deploy_world(site: ['runtime' => 'static', 'framework' => 'static', 'php_version' => null]);
    fn_agent($world->servers[0]->id, $world->organization->id);
    $environment = projects_default_env($world->organization);

    $response = $this->postJson("/projects/{$environment->project_id}/{$environment->slug}/functions", [
        'name' => 'Notes', 'server_id' => $world->servers[0]->id, 'starter' => 'postgres-api', 'runtime' => 'go',
    ])->assertCreated();

    $function = CloudFunction::query()->where('site_id', $response->json('data.site_id'))->firstOrFail();
    $apply = $world->agents->last('fn.release.apply')['payload'];

    expect($function->runtime)->toBe('go')
        ->and($function->entrypoint)->toBe('main.go')
        ->and($function->head()->files['main.go'])->toContain('github.com/jackc/pgx/v5/pgxpool')
        ->and($apply['image'])->toContain('/falak-fn-go:')
        ->and($apply['entrypoint'])->toBe('main.go');
    $this->getJson(fn_url(Site::query()->findOrFail($response->json('data.site_id'))))->assertJsonPath('data.runtime.language', 'go');
});

it('generates the secrets a starter needs and rejects unknown runtimes', function () {
    $world = deploy_world(site: ['runtime' => 'static', 'framework' => 'static', 'php_version' => null]);
    fn_agent($world->servers[0]->id, $world->organization->id);
    $environment = projects_default_env($world->organization);
    $url = "/projects/{$environment->project_id}/{$environment->slug}/functions";

    $this->postJson($url, ['name' => 'Nope', 'server_id' => $world->servers[0]->id, 'runtime' => 'cobol'])->assertUnprocessable()->assertJsonValidationErrors('runtime');

    $site = $this->postJson($url, ['name' => 'Bot', 'server_id' => $world->servers[0]->id, 'starter' => 'telegram-bot', 'runtime' => 'deno'])->assertCreated()->json('data.site_id');
    $variables = EnvironmentVersion::query()->where('site_id', $site)->orderByDesc('version')->first()->variables;

    expect($variables['TELEGRAM_BOT_TOKEN'])->toBe('')
        ->and($variables['TELEGRAM_WEBHOOK_SECRET'])->toMatch('/^[0-9a-f]{48}$/')
        ->and(CloudFunction::query()->where('site_id', $site)->value('entrypoint'))->toBe('index.ts');
});

it('protects a function with API keys and an IP allowlist enforced by the gateway', function () {
    [$world] = fn_deployed();
    Agent::query()->where('server_id', $world->servers[0]->id)->update(['facts' => ['features' => ['fn.v1', 'fn.v2']]]);

    $created = $this->postJson(fn_url($world->site, '/access/keys'), ['name' => 'CI'])->assertCreated()->json('data');
    $apply = $world->agents->last('fn.release.apply')['payload'];

    expect($created['key'])->toStartWith('kfn_')
        ->and($created['deployment_id'])->not->toBeNull()
        ->and($apply['access'])->toBe(['api_key_hashes' => [hash('sha256', $created['key'])]])
        // shown once: the list has the prefix only
        ->and($this->getJson(fn_url($world->site, '/access'))->json('data.keys.0'))->not->toHaveKey('key')
        ->and($this->getJson(fn_url($world->site, '/access'))->json('data.keys.0.prefix'))->toBe(substr($created['key'], 0, 12));
    deploy_run_all($world->agents);

    $this->putJson(fn_url($world->site, '/access/allowlist'), ['allow_cidrs' => ['10.0.0.300']])->assertUnprocessable()->assertJsonValidationErrors('allow_cidrs');
    $this->putJson(fn_url($world->site, '/access/allowlist'), ['allow_cidrs' => ['203.0.113.7', '2001:DB8::/32', '']])->assertOk()
        ->assertJsonPath('data.allow_cidrs', ['203.0.113.7/32', '2001:db8::/32']);
    expect($world->agents->last('fn.release.apply')['payload']['access']['allow_cidrs'])->toBe(['203.0.113.7/32', '2001:db8::/32']);
    deploy_run_all($world->agents);

    // Revoking the key and clearing the list makes it public again: no `access` (older agents accept the release).
    $this->deleteJson(fn_url($world->site, "/access/keys/{$created['id']}"))->assertOk();
    deploy_run_all($world->agents);
    $this->putJson(fn_url($world->site, '/access/allowlist'), ['allow_cidrs' => []])->assertOk();
    expect($world->agents->last('fn.release.apply')['payload'])->not->toHaveKey('access');
});

it('refuses access rules on servers whose gateway cannot enforce them', function () {
    [$world] = fn_deployed(); // agent reports fn.v1 only

    $this->postJson(fn_url($world->site, '/access/keys'), ['name' => 'CI'])->assertUnprocessable()->assertJsonValidationErrors(['access' => 'too old']);
    $this->putJson(fn_url($world->site, '/access/allowlist'), ['allow_cidrs' => ['10.0.0.0/8']])->assertUnprocessable();
    expect(FunctionApiKey::query()->count())->toBe(0);
});

it('sends test requests to the function’s own URL only', function () {
    [$world] = fn_deployed();
    app(SiteDomains::class)->attach($world->site->id, 'hooks.example.com');
    deploy_http(['https://hooks.example.com' => 201]); // deploy_world's HTTP fake answers "status <code>"

    $this->postJson(fn_url($world->site, '/invoke'), ['method' => 'POST', 'path' => '/notes?x=1', 'headers' => ['X-Falak-Key' => 'k', 'Host' => 'evil.example'], 'body' => '{"a":1}'])
        ->assertOk()
        ->assertJsonPath('data.status', 201)
        ->assertJsonPath('data.url', 'https://hooks.example.com/notes?x=1')
        ->assertJsonPath('data.body', 'status 201');

    Http::assertSent(fn ($request) => $request->url() === 'https://hooks.example.com/notes?x=1' && $request->hasHeader('X-Falak-Key', 'k') && $request->header('Host') !== ['evil.example'] && $request->body() === '{"a":1}');

    // Absolute URLs or other hosts cannot be requested.
    $this->postJson(fn_url($world->site, '/invoke'), ['method' => 'GET', 'path' => 'https://internal.example/'])->assertUnprocessable();
});

it('names the function secrets in fn.release.apply so the agent masks them', function () {
    [$world, $function] = fn_world();
    $this->postJson(fn_url($world->site, '/deploy'), ['files' => ['index.ts' => FN_V2], 'base_version_id' => $function->head()->id])->assertCreated();
    $apply = $world->agents->last('fn.release.apply')['payload'];

    expect($apply['env']['APP_KEY'])->toBe('base64:secret')
        ->and($apply['mask'])->toBe(['APP_KEY']);
});
