<?php

use Falak\Config\Application\Actions\ApproveRun;
use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Enums\RunTrigger;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Config\Http\Presenters;
use Falak\Deployments\Application\Actions\TriggerDeployment;
use Falak\Deployments\Application\Orchestration\Orchestrator;
use Falak\Deployments\Domain\Enums\Trigger;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\Deployments\Domain\Models\Release;
use Falak\Identity\Application\Actions\CreateApiToken;
use Falak\Identity\Contracts\Role;
use Falak\Projects\Domain\Models\Service;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Domain\Models\Site;
use Falak\SourceControl\Contracts\Data\PullRequestData;
use Falak\SourceControl\Events\PullRequestOpened;

require_once __DIR__.'/../Support/helpers.php';

function config_safety_file(string $feature = 'secret-value', string $extra = ''): string
{
    return "version: 1\nunits:\n  web:\n    type: app\n    runtime: frankenphp\n    php: \"8.4\"\n    env: { FEATURE: \"{$feature}\" }\n{$extra}environments:\n  production: { branch: main }\n";
}

it('never stores, returns or comments env values for those who may not read them', function () {
    $world = config_world(actingAs: false);
    $world->file(config_safety_file());

    $world->push(config_sha('b'));
    $change = collect(ConfigRun::query()->sole()->plan['changes'])->firstWhere('path', '/units/web/env/FEATURE');
    expect($change['after'])->toBe('••••');

    $project = $world->environment->project_id;
    $token = fn (array $abilities) => app(CreateApiToken::class)($world->deploy->user, $world->deploy->organization->id, 'cli', $abilities)->plainTextToken;
    $body = ['file' => config_safety_file('other-secret'), 'environment' => 'production'];

    $masked = $this->withToken($token(['config.view']))->postJson("/api/v1/projects/{$project}/config/plan", $body)->assertOk();
    expect(json_encode($masked->json()))->not->toContain('other-secret')
        ->and($masked->json('data.resolved.units.web.env.FEATURE'))->toBe('••••');

    $export = $this->withToken($token(['config.view']))->getJson("/api/v1/projects/{$project}/config/export?environment=production")->assertOk();
    expect($export->json('data.file'))->not->toContain('secret-value');

    app('auth')->forgetGuards();
    $visible = $this->withToken($token(['config.view', 'sites.env.view']))->postJson("/api/v1/projects/{$project}/config/plan", $body)->assertOk();
    expect($visible->json('data.resolved.units.web.env.FEATURE'))->toBe('other-secret');

    // Pull request comments show the key and that it changed, never the value (forks included).
    $world->file(config_safety_file('pr-secret'), config_sha('f'));
    PullRequestOpened::dispatch($world->deploy->organization->id, $world->source->source_connection_id, 'github',
        new PullRequestData('acme/shop', 3, 'x', null, 'feature', config_sha('f'), 'main', 'ada', false, 'acme/shop'));
    $comment = array_values($world->deploy->sourceControl->comments)[0]['body'];
    expect($comment)->not->toContain('pr-secret')->not->toContain('secret-value')->toContain('/units/web/env/FEATURE');
});

it('a newer run supersedes an older one still applying; the release is paired with its own run', function () {
    $world = config_world();
    $site = $world->deploy->site;
    $world->file(config_safety_file('one'));
    $world->push(config_sha('b'));
    $first = ConfigRun::query()->sole();

    $world->file(config_safety_file('two'));
    $world->push(config_sha('c'));
    $first->refresh();
    $second = ConfigRun::query()->where('commit', config_sha('c'))->sole();

    expect($first->status)->toBe(RunStatus::Cancelled)
        ->and($first->error)->toContain('Superseded')
        ->and($first->deploy[0]['status'])->toBe('superseded')
        ->and($second->status)->toBe(RunStatus::Applying);

    $world->settle();

    expect($second->refresh()->status)->toBe(RunStatus::Applied)
        ->and(Release::current($site->id)?->config['env'])->toBe(['FEATURE' => 'two']);
});

it('a cancelled deployment settles its run instead of leaving it applying', function () {
    $world = config_world();
    $world->file(config_safety_file());
    $world->push(config_sha('b'));
    $run = ConfigRun::query()->sole();

    app(Orchestrator::class)->cancel($run->deploy[0]['deployment_id']);
    $world->settle();

    expect($run->refresh()->status)->toBe(RunStatus::Failed)
        ->and($run->deploy[0]['status'])->toBeIn(['cancelled', 'failed'])
        ->and(Presenters::run($run)['error_code'])->toBe('deployment_failed');
});

it('approving re-plans: a superseded run is refused, a disabled source cancels the run', function () {
    $world = config_world(experimental: false);
    $risky = config_safety_file('x', "    deploy: { strategy: in-place }\n");
    $world->file($risky);
    $world->push(config_sha('b'));
    $waiting = ConfigRun::query()->sole();
    expect($waiting->status)->toBe(RunStatus::AwaitingApproval);

    $world->file(config_safety_file('y'));
    $world->push(config_sha('c'));
    $world->settle();

    expect(app(ApproveRun::class)($waiting->refresh(), $world->deploy->user->id))->toBe(ApproveRun::NOT_WAITING)
        ->and($waiting->status)->toBe(RunStatus::Cancelled);

    $world->file($risky);
    $world->push(config_sha('d'));
    $next = ConfigRun::query()->where('commit', config_sha('d'))->sole();
    $world->source->forceFill(['enabled' => false])->save();

    expect(app(ApproveRun::class)($next, $world->deploy->user->id))->toBe(ApproveRun::APPROVED)
        ->and($next->refresh()->status)->toBe(RunStatus::Cancelled)
        ->and(Deployment::query()->where('commit', config_sha('d'))->count())->toBe(0);
});

it('rolling back to a release made before the file says the config was not restored', function () {
    $world = config_world();
    $site = $world->deploy->site;
    app(TriggerDeployment::class)(app(SiteDirectory::class)->find($site->id), Trigger::Manual);
    $world->settle();
    $before = Release::current($site->id);

    $world->file(config_safety_file());
    $world->push(config_sha('b'));
    $world->settle();

    app(TriggerDeployment::class)(app(SiteDirectory::class)->find($site->id), Trigger::Rollback, releaseId: $before->id);
    $world->settle();

    $rollback = ConfigRun::query()->where('trigger', RunTrigger::Rollback)->sole();
    expect($rollback->status)->toBe(RunStatus::Applied)
        ->and($rollback->errors[0])->toMatchArray(['code' => 'rollback.no_config', 'severity' => 'warning']);
});

it('Apply now deploys the head of the branch the environment is bound to; only admins set the allowed credentials', function () {
    $world = config_world();
    $world->file(config_safety_file());
    $project = $world->environment->project_id;

    $this->postJson("/projects/{$project}/settings/config/apply", ['environment' => 'production'])->assertStatus(201)
        ->assertJsonPath('data.trigger', 'manual')
        ->assertJsonPath('data.branch', 'main')
        ->assertJsonPath('data.commit', config_sha('a'));

    $connection = $world->source->source_connection_id;
    $this->putJson("/projects/{$project}/settings/config", ['connection_id' => $connection, 'repository' => 'acme/shop', 'enabled' => true, 'allowed_credentials' => ['cf-acme']])
        ->assertOk()->assertJsonPath('data.allowed_credentials', ['cf-acme']);

    [$developer] = memberOf($world->deploy->organization, Role::Developer);
    $this->actingAs($developer)->putJson("/projects/{$project}/settings/config", ['connection_id' => $connection, 'repository' => 'acme/shop', 'enabled' => true, 'allowed_credentials' => ['other']])
        ->assertForbidden();
});

it('maps units to the sites the file manages by id: a renamed service is no delete and create', function () {
    $world = config_world();
    $world->file(config_safety_file());
    $world->push(config_sha('b'));
    $world->settle();

    Service::query()->where('ref_id', $world->deploy->site->id)->update(['name' => 'Shop web']);
    $world->file(config_safety_file('changed'));
    $world->push(config_sha('c'));
    $run = ConfigRun::query()->where('commit', config_sha('c'))->sole();

    expect(collect($run->plan['changes'])->whereIn('op', ['create', 'delete', 'detach'])->all())->toBe([])
        ->and($run->deploy[0]['site_id'])->toBe($world->deploy->site->id)
        ->and(Site::query()->count())->toBe(1);
});
