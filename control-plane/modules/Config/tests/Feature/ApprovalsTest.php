<?php

use Falak\Config\Application\Actions\ApproveRun;
use Falak\Config\Application\Jobs\ExpireApprovals;
use Falak\Config\Application\Runs\ApprovalNotice;
use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Enums\RunTrigger;
use Falak\Config\Domain\Models\ConfigPullRequest;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Config\Events\ConfigRunAwaitingApproval;
use Falak\Config\Events\ConfigRunFailed;
use Falak\Deployments\Application\Actions\TriggerDeployment;
use Falak\Deployments\Domain\Enums\Trigger;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\Deployments\Domain\Models\Release;
use Falak\Deployments\Domain\Models\SiteSettings;
use Falak\Edge\Domain\Models\Domain;
use Falak\Identity\Application\Actions\CreateApiToken;
use Falak\Identity\Contracts\Role;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\SourceControl\Contracts\Data\CommitData;
use Falak\SourceControl\Events\PushReceived;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/../Support/helpers.php';

function config_risky_file(string $strategy = 'in-place', string $extra = ''): string
{
    return "version: 1\nunits:\n  web:\n    type: app\n    runtime: frankenphp\n    php: \"8.4\"\n    deploy: { strategy: {$strategy} }\n{$extra}environments:\n  production: { branch: main }\n";
}

function config_token(ConfigWorld $world, $user = null, array $abilities = ['*']): string
{
    return app(CreateApiToken::class)($user ?? $world->deploy->user, $world->deploy->organization->id, 'cli', $abilities)->plainTextToken;
}

it('a risky push waits for an approval, alerts once and tells the merged pull request', function () {
    Event::fake([ConfigRunAwaitingApproval::class]);
    $world = config_world();
    ConfigPullRequest::query()->create(['organization_id' => $world->deploy->organization->id, 'project_id' => $world->environment->project_id,
        'number' => 7, 'comment_id' => '1', 'head_sha' => config_sha('f'), 'body' => '#### Falak config plan']);
    $world->deploy->sourceControl->comments['1'] = ['repository' => 'acme/shop', 'number' => 7, 'body' => '#### Falak config plan'];
    $world->file(config_risky_file());

    PushReceived::dispatch($world->deploy->organization->id, $world->source->source_connection_id, 'github', 'acme/shop', 'main',
        new CommitData(config_sha('b'), 'Merge pull request #7 from acme/feature', 'Ada', 'ada@example.com'), 'ada', null, null);
    $run = ConfigRun::query()->sole();

    expect($run->status)->toBe(RunStatus::AwaitingApproval)
        ->and($run->approval_expires_at?->diffInDays(now()->addDays(7), true))->toBeLessThan(1)
        ->and(SiteSettings::query()->find($world->deploy->site->id)?->strategy)->toBeNull()
        ->and($world->deploy->sourceControl->comments['1']['body'])->toContain('Waiting for an approval')->toContain($run->id);
    Event::assertDispatchedTimes(ConfigRunAwaitingApproval::class, 1);
});

it('approving applies after planning again; the run shows who approved and why', function () {
    $world = config_world(actingAs: false);
    $world->file(config_risky_file());
    $world->push(config_sha('b'));
    $run = ConfigRun::query()->sole();

    $this->withToken(config_token($world))->getJson("/api/v1/config/runs/{$run->id}")->assertOk()
        ->assertJsonPath('data.approval.required', true)
        ->assertJsonPath('data.approval.risky.0.rule', 'production.strategy')
        ->assertJsonPath('data.approval.can_approve', true)
        ->assertJsonPath('data.cancel_reason', null);

    $this->postJson("/api/v1/config/runs/{$run->id}/approve", ['plan_hash' => config_plan_hash($run->id), 'comment' => 'Planned maintenance'])->assertOk()
        ->assertJsonPath('data.status', 'applying')
        ->assertJsonPath('data.approval.approved_by', $world->deploy->user->id)
        ->assertJsonPath('data.approval.comment', 'Planned maintenance')
        ->assertJsonPath('data.approval.can_approve', false);

    $world->settle();
    expect($run->refresh()->status)->toBe(RunStatus::Applied)
        ->and(SiteSettings::query()->find($world->deploy->site->id)?->strategy?->value)->toBe('in-place');

    $this->postJson("/api/v1/config/runs/{$run->id}/approve", ['plan_hash' => config_plan_hash($run->id)])->assertStatus(409)->assertJsonPath('message', 'This run is not awaiting approval.');
});

it('only config.approve approves; the requester may not when the project separates approvers', function () {
    $world = config_world(actingAs: false);
    $world->file(config_risky_file());
    [$developer] = memberOf($world->deploy->organization, Role::Developer);
    [$admin] = memberOf($world->deploy->organization, Role::Admin);

    $run = $this->withToken(config_token($world, $developer))->postJson("/api/v1/projects/{$world->environment->project_id}/config/runs",
        ['file' => config_risky_file(), 'environment' => 'production'])->assertCreated()->assertJsonPath('data.status', 'awaiting_approval')->json('data.id');
    $this->postJson("/api/v1/config/runs/{$run}/approve", ['plan_hash' => config_plan_hash($run)])->assertForbidden();

    $world->source->forceFill(['separate_approver' => true])->save();
    app('auth')->forgetGuards();
    $own = $this->withToken(config_token($world, $admin))->postJson("/api/v1/projects/{$world->environment->project_id}/config/runs",
        ['file' => config_risky_file(), 'environment' => 'production'])->json('data.id');
    $this->getJson("/api/v1/config/runs/{$own}")->assertJsonPath('data.approval.blocked_reason', 'You requested this run: another approver must approve it.');
    $this->postJson("/api/v1/config/runs/{$own}/approve", ['plan_hash' => config_plan_hash($own)])->assertForbidden()->assertJsonPath('message', 'You requested this run: another approver must approve it.');

    app('auth')->forgetGuards();
    $this->withToken(config_token($world))->postJson("/api/v1/config/runs/{$own}/approve", ['plan_hash' => config_plan_hash($own)])->assertOk()->assertJsonPath('data.status', 'applying');
    expect(ConfigRun::query()->find($run)->status)->toBe(RunStatus::Cancelled); // superseded by the approved one
});

it('rejecting cancels the run; an approval expires after the configured days', function () {
    $world = config_world(actingAs: false);
    $world->file(config_risky_file());
    $world->push(config_sha('b'));
    $run = ConfigRun::query()->sole();

    $this->withToken(config_token($world))->postJson("/api/v1/config/runs/{$run->id}/reject", ['comment' => 'Not now'])->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.cancel_reason', 'rejected')
        ->assertJsonPath('data.approval.rejected_by', $world->deploy->user->id)
        ->assertJsonPath('data.approval.comment', 'Not now');
    $this->postJson("/api/v1/config/runs/{$run->id}/reject")->assertStatus(409);

    config(['config_as_code.approval_days' => 2]);
    $world->push(config_sha('c'));
    $waiting = ConfigRun::query()->where('commit', config_sha('c'))->sole();
    $this->travel(3)->days();
    (new ExpireApprovals)->handle();

    expect($waiting->refresh()->status)->toBe(RunStatus::Cancelled)->and($waiting->cancel_reason)->toBe('expired');
    $this->postJson("/api/v1/config/runs/{$waiting->id}/approve", ['plan_hash' => config_plan_hash($waiting->id)])->assertStatus(409);
});

it('an approval covers only the risky changes the approver saw', function () {
    $world = config_world(actingAs: false);
    $world->file(config_risky_file());
    $world->push(config_sha('b'));
    $run = ConfigRun::query()->sole();
    // The approver saw a plan whose risky change was another one.
    $run->forceFill(['plan' => [...$run->plan, 'changes' => array_map(fn (array $c) => $c['risky'] ? [...$c, 'path' => '/units/web/servers'] : $c, $run->plan['changes'])]])->save();

    $this->withToken(config_token($world))->postJson("/api/v1/config/runs/{$run->id}/approve", ['plan_hash' => config_plan_hash($run->id)])->assertStatus(409)
        ->assertJsonPath('message', 'The plan changed since it was shown; review it again.')
        ->assertJsonPath('data.status', 'awaiting_approval')
        ->assertJsonPath('data.approval.approved_by', null)
        ->assertJsonPath('data.approval.risky.0.path', '/units/web/deploy/strategy');

    expect(SiteSettings::query()->find($world->deploy->site->id)?->strategy)->toBeNull();
    $this->postJson("/api/v1/config/runs/{$run->id}/approve", ['plan_hash' => config_plan_hash($run->id)])->assertOk();
});

it('a failed run raises one alert', function () {
    Event::fake([ConfigRunFailed::class]);
    $world = config_world();
    $world->file("version: 1\nunits:\n  web: { type: app, runtime: nope }\nenvironments:\n  production: { branch: main }\n");

    $world->push(config_sha('b'));

    Event::assertDispatchedTimes(ConfigRunFailed::class, 1);
    Event::assertDispatched(ConfigRunFailed::class, fn (ConfigRunFailed $e) => $e->toAlert()->type === 'config.run.failed');
});

it('a rollback never applies a risky phase B change: removing a production domain is skipped', function () {
    $world = config_world();
    $site = $world->deploy->site;
    $file = fn (array $domains) => "version: 1\nunits:\n  web:\n    type: app\n    runtime: frankenphp\n    php: \"8.4\"\n    domains: [".implode(', ', $domains)."]\nenvironments:\n  production: { branch: main }\n";

    $world->file($file(['shop.example.com']));
    $world->push(config_sha('b'));
    $world->settle();
    $first = Release::current($site->id);

    $world->file($file(['shop.example.com', 'new.example.com']));
    $world->push(config_sha('c'));
    $world->settle();
    expect(Domain::query()->where('site_id', $site->id)->pluck('name')->sort()->values()->all())->toBe(['new.example.com', 'shop.example.com']);

    app(TriggerDeployment::class)(app(SiteDirectory::class)->find($site->id), Trigger::Rollback, releaseId: $first->id);
    $world->settle();

    $rollback = ConfigRun::query()->where('trigger', RunTrigger::Rollback)->sole();
    expect(Domain::query()->where('site_id', $site->id)->pluck('name')->all())->toContain('new.example.com')
        ->and(collect($rollback->errors)->pluck('code')->all())->toContain('rollback.risky_skipped')
        ->and(collect($rollback->errors)->pluck('pointer')->all())->toContain('/units/web/domains/new.example.com');
});

it('plans an environment the file declares but the project lacks, as a preview', function () {
    $world = config_world(actingAs: false);
    $file = "version: 1\nunits:\n  web: { type: app, runtime: frankenphp, php: \"8.4\" }\nenvironments:\n  production: { branch: main }\n  qa: { branch: qa }\n";

    $response = $this->withToken(config_token($world))->postJson("/api/v1/projects/{$world->environment->project_id}/config/plan", ['file' => $file, 'environment' => 'qa'])
        ->assertOk()
        ->assertJsonPath('data.environment_exists', false)
        ->assertJsonPath('data.preview_only', true)
        ->assertJsonPath('data.plan.changes.0.op', 'create')
        ->assertJsonPath('data.plan.changes.0.path', '/environments/qa');

    expect(collect($response->json('data.plan.changes'))->where('op', 'create')->pluck('path')->all())->toContain('/units/web')
        ->and(collect($response->json('data.warnings'))->pluck('code')->all())->toContain('environment.created_on_push');

    $this->postJson("/api/v1/projects/{$world->environment->project_id}/config/plan", ['file' => $file, 'environment' => 'nope'])->assertStatus(422);
    $this->postJson("/api/v1/projects/{$world->environment->project_id}/config/plan", ['file' => $file, 'environment' => 'production'])
        ->assertOk()->assertJsonPath('data.environment_exists', true)->assertJsonPath('data.preview_only', false);
});

it('only admins turn on "approver must differ from the requester"', function () {
    $world = config_world();
    $project = $world->environment->project_id;
    $body = ['connection_id' => $world->source->source_connection_id, 'repository' => 'acme/shop', 'enabled' => true, 'separate_approver' => true];

    [$developer] = memberOf($world->deploy->organization, Role::Developer);
    $this->actingAs($developer)->putJson("/projects/{$project}/settings/config", $body)->assertForbidden();

    $this->actingAs($world->deploy->user)->putJson("/projects/{$project}/settings/config", $body)->assertOk()->assertJsonPath('data.separate_approver', true);
});

it('approving needs the plan hash shown; another hash approves nothing', function () {
    $world = config_world(actingAs: false);
    $world->file(config_risky_file());
    $world->push(config_sha('b'));
    $run = ConfigRun::query()->sole();

    $this->withToken(config_token($world))->getJson("/api/v1/config/runs/{$run->id}")->assertJsonPath('data.approval.plan_hash', config_plan_hash($run));
    $this->postJson("/api/v1/config/runs/{$run->id}/approve")->assertStatus(422)->assertJsonValidationErrors(['plan_hash']);
    $this->postJson("/api/v1/config/runs/{$run->id}/approve", ['plan_hash' => str_repeat('0', 64)])->assertStatus(409)
        ->assertJsonPath('message', 'The plan changed since it was shown; review it again.')
        ->assertJsonPath('data.approval.approved_by', null);

    expect($run->refresh()->status)->toBe(RunStatus::AwaitingApproval)->and($run->approved_at)->toBeNull();
});

it('one run waits per environment; an older run is never approvable', function () {
    $world = config_world();
    $world->file(config_risky_file());
    $world->push(config_sha('b'));
    $older = ConfigRun::query()->sole();

    $world->file(config_risky_file('rolling'));
    $world->push(config_sha('c'));
    $newer = ConfigRun::query()->where('commit', config_sha('c'))->sole();

    expect($older->refresh()->status)->toBe(RunStatus::Cancelled)
        ->and($older->cancel_reason)->toBe('superseded')
        ->and($newer->status)->toBe(RunStatus::AwaitingApproval);

    // Even if an older one were still waiting, a newer run of the environment makes it unapprovable.
    $older->forceFill(['status' => RunStatus::AwaitingApproval, 'cancel_reason' => null])->save();
    expect(config_approve($older, $world->deploy->user->id))->toBe(ApproveRun::NOT_WAITING)
        ->and($older->refresh()->cancel_reason)->toBe('superseded');
});

it('an approval is claimed once: a second approval of the same run does nothing', function () {
    $world = config_world();
    $world->file(config_risky_file());
    $world->push(config_sha('b'));
    $run = ConfigRun::query()->sole();
    $hash = config_plan_hash($run);
    $approve = app(ApproveRun::class);
    $stale = ConfigRun::query()->find($run->id); // a second request that read the run before the first claimed it

    expect($approve($run, $world->deploy->user->id, $hash))->toBe('approved')
        ->and($approve($stale, $world->deploy->user->id, $hash))->toBe('not_waiting')
        // One deployment of the approved config (push to deploy's, queued before phase A, was replaced).
        ->and(Deployment::query()->where('status', '!=', 'cancelled')->count())->toBe(1);
});

it('a run sent back to waiting alerts again', function () {
    Event::fake([ConfigRunAwaitingApproval::class]);
    $world = config_world();
    $world->file(config_risky_file());
    $world->push(config_sha('b'));
    $run = ConfigRun::query()->sole();
    $run->forceFill(['plan' => [...$run->plan, 'changes' => array_map(fn (array $c) => $c['risky'] ? [...$c, 'path' => '/units/web/servers'] : $c, $run->plan['changes'])]])->save();

    expect(config_approve($run, $world->deploy->user->id))->toBe('plan_changed');
    Event::assertDispatchedTimes(ConfigRunAwaitingApproval::class, 2);
});

it('the approval notice finds the pull request only by the merge subject or its head sha', function () {
    $world = config_world();
    foreach ([7, 8, 9] as $n) {
        ConfigPullRequest::query()->create(['organization_id' => $world->deploy->organization->id, 'project_id' => $world->environment->project_id,
            'number' => $n, 'comment_id' => (string) $n, 'head_sha' => config_sha((string) $n), 'body' => "plan {$n}"]);
        $world->deploy->sourceControl->comments[(string) $n] = ['repository' => 'acme/shop', 'number' => $n, 'body' => "plan {$n}"];
    }
    $run = ConfigRun::query()->create(['organization_id' => $world->deploy->organization->id, 'project_id' => $world->environment->project_id,
        'environment_id' => $world->environment->id, 'environment' => 'production', 'commit' => config_sha('9'), 'trigger' => RunTrigger::Push,
        'status' => RunStatus::AwaitingApproval, 'file' => '', 'plan' => ['changes' => []]]);

    app(ApprovalNotice::class)->post($run, "Fix checkout (#8)\n\nSee #7 for context");

    expect($world->deploy->sourceControl->comments['7']['body'])->toBe('plan 7')
        ->and($world->deploy->sourceControl->comments['8']['body'])->toContain('Waiting for an approval')
        ->and($world->deploy->sourceControl->comments['9']['body'])->toContain('Waiting for an approval');
});

it('disabling the source cancels the runs waiting for an approval', function () {
    $world = config_world();
    $world->file(config_risky_file());
    $world->push(config_sha('b'));
    $run = ConfigRun::query()->sole();

    $this->putJson("/projects/{$world->environment->project_id}/settings/config", ['connection_id' => $world->source->source_connection_id, 'repository' => 'acme/shop', 'enabled' => false])
        ->assertOk();

    expect($run->refresh()->status)->toBe(RunStatus::Cancelled)->and($run->cancel_reason)->toBe('source_disabled');
});
