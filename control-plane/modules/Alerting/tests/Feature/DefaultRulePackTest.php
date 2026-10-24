<?php

namespace Falak\Alerting\Tests\Feature;

use Falak\Alerting\Application\DefaultRulePack;
use Falak\Alerting\Contracts\Alerts;
use Falak\Alerting\Contracts\AlertTypes;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Falak\Alerting\Domain\Enums\AlertOutcome;
use Falak\Alerting\Domain\Models\Alert;
use Falak\Alerting\Domain\Models\Channel;
use Falak\Alerting\Domain\Models\Delivery;
use Falak\Alerting\Domain\Models\Notification;
use Falak\Alerting\Domain\Models\Rule;
use Falak\Alerting\Domain\Models\RulePack;
use Falak\Identity\Contracts\Role;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    alerting_guard();
    Http::preventStrayRequests();
    Http::fake(fn () => Http::response('ok'));
});

function pack_rules(string $organizationId): Collection
{
    return Rule::query()->where('organization_id', $organizationId)->whereNotNull('pack_key')->get();
}

it('gives a new organization one rule per area that reaches warning, in-app only', function () {
    [, $organization] = memberOf();

    $areas = app(DefaultRulePack::class)->areas();
    $rules = pack_rules($organization->id);

    expect($rules)->toHaveCount(count($areas))
        ->and($rules->pluck('pack_key')->sort()->values()->all())->toBe(collect(array_keys($areas))->sort()->values()->all())
        ->and($rules->every(fn (Rule $rule) => $rule->enabled && $rule->min_severity === Severity::Warning && $rule->channels()->doesntExist()))->toBeTrue()
        ->and($areas)->toHaveKeys(['area:servers', 'area:databases', 'area:edge', 'area:secrets', 'area:security', 'area:volumes'])
        ->and($areas['area:databases']['patterns'])->toContain('databases.*', 'pitr.*')
        ->and($rules->firstWhere('pack_key', 'area:servers')->name)->toBe('Servers');
});

it('covers every registered type that reaches warning', function () {
    [, $organization] = memberOf();
    $rules = pack_rules($organization->id);

    foreach (app(AlertTypes::class)->all() as $type) {
        $covered = $rules->contains(fn (Rule $rule) => $rule->matches($type['type'], $type['severity']));
        expect($covered)->toBe($type['severity']->atLeast(Severity::Warning), $type['type']);
    }
});

it('delivers in-app when the organization has no channel', function () {
    [$owner, $organization] = memberOf();

    app(Alerts::class)->raise(new AlertData($organization->id, 'volumes.almost_full', Severity::Warning, 'Volume data is 90% full', url: '/volumes/v1', dedupKey: 'v1'));

    expect(Alert::query()->sole()->outcome)->toBe(AlertOutcome::Delivered)
        ->and(Delivery::query()->count())->toBe(0)
        ->and(Notification::query()->where('user_id', $owner->id)->count())->toBe(1);
});

it('routes the pack to the first channel the organization adds', function () {
    [$user, $organization] = actingAsMember(Role::Admin);

    $this->post('/alerting/channels', ['name' => 'Ops', 'type' => 'slack', 'config' => ['webhook_url' => ALERTING_SLACK_URL]])->assertSessionHasNoErrors();
    $this->post('/alerting/channels', ['name' => 'Webhook', 'type' => 'webhook', 'config' => ['url' => 'https://hooks.example.com/x', 'secret' => 'a-very-long-signing-secret']])->assertSessionHasNoErrors();

    $ops = Channel::query()->where('name', 'Ops')->sole();
    expect($ops->is_default)->toBeTrue()
        ->and(Channel::query()->where('name', 'Webhook')->sole()->is_default)->toBeFalse()
        ->and(pack_rules($organization->id)->every(fn (Rule $rule) => $rule->channels()->pluck('alerting_channels.id')->all() === [$ops->id]))->toBeTrue();

    app(Alerts::class)->raise(new AlertData($organization->id, 'databases.backup_failed', Severity::Critical, 'Backup failed', url: '/databases/x'));

    expect(Delivery::query()->sole()->channel_id)->toBe($ops->id)
        ->and(Notification::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('applies the pack to a new organization with its default channel', function () {
    [, $organization] = memberOf();
    $channel = alerting_channel($organization->id, attributes: ['is_default' => true]);
    RulePack::query()->where('organization_id', $organization->id)->delete();
    Rule::query()->where('organization_id', $organization->id)->delete();

    app(DefaultRulePack::class)->apply($organization->id);

    expect(pack_rules($organization->id)->every(fn (Rule $rule) => $rule->channels()->pluck('alerting_channels.id')->all() === [$channel->id]))->toBeTrue();
});

it('moves the default to another channel and keeps routed rules', function () {
    [, $organization] = actingAsMember(Role::Admin);
    $this->post('/alerting/channels', ['name' => 'Ops', 'type' => 'slack', 'config' => ['webhook_url' => ALERTING_SLACK_URL]]);
    $this->post('/alerting/channels', ['name' => 'Pager', 'type' => 'webhook', 'config' => ['url' => 'https://hooks.example.com/x', 'secret' => 'a-very-long-signing-secret']]);
    $ops = Channel::query()->where('name', 'Ops')->sole();
    $pager = Channel::query()->where('name', 'Pager')->sole();

    $this->post("/alerting/channels/{$pager->id}/default")->assertRedirect('/settings/alert-channels');
    expect($pager->refresh()->is_default)->toBeTrue()->and($ops->refresh()->is_default)->toBeFalse();

    // Deleting the default promotes the oldest channel left and routes the rules left without one to it.
    $this->delete("/alerting/channels/{$pager->id}");
    $this->delete("/alerting/channels/{$ops->id}");
    $this->post('/alerting/channels', ['name' => 'New', 'type' => 'slack', 'config' => ['webhook_url' => ALERTING_SLACK_URL]]);
    $new = Channel::query()->where('name', 'New')->sole();

    expect($new->is_default)->toBeTrue()
        ->and(pack_rules($organization->id)->every(fn (Rule $rule) => $rule->channels()->pluck('alerting_channels.id')->all() === [$new->id]))->toBeTrue();
});

it('applies existing organizations once and never re-creates a deleted rule', function () {
    [, $organization] = memberOf();
    [, $other] = memberOf();
    RulePack::query()->delete();
    Rule::query()->delete();

    $this->artisan('alerting:default-rules')->assertSuccessful();
    $count = pack_rules($organization->id)->count();
    expect($count)->toBeGreaterThan(5)->and(pack_rules($other->id))->toHaveCount($count);

    // Idempotent.
    $this->artisan('alerting:default-rules')->expectsOutput('0 rule(s) created or extended.')->assertSuccessful();
    expect(pack_rules($organization->id))->toHaveCount($count);

    // Deleted and edited rules stay as the user left them.
    pack_rules($organization->id)->firstWhere('pack_key', 'area:servers')->delete();
    $volumes = pack_rules($organization->id)->firstWhere('pack_key', 'area:volumes');
    $volumes->forceFill(['enabled' => false, 'event_types' => ['volumes.drill_failed']])->save();

    app(DefaultRulePack::class)->apply($organization->id);

    expect(pack_rules($organization->id))->toHaveCount($count - 1)
        ->and($volumes->refresh()->enabled)->toBeFalse()
        ->and($volumes->event_types)->toBe(['volumes.drill_failed']);
});

it('adds an area registered later (a new module group) to every organization', function () {
    [, $organization] = memberOf();
    $before = pack_rules($organization->id)->count();

    // Disaster recovery (the Recovery module) is in the pack like every module's group.
    expect(pack_rules($organization->id)->firstWhere('pack_key', 'area:dr')?->event_types)->toBe(['dr.*']);

    app(AlertTypes::class)->register('example.backup_missing', 'A later module\'s alert', 'Example module', Severity::Critical);
    app(AlertTypes::class)->register('servers.extra_check', 'Extra', 'Servers', Severity::Warning);
    app(AlertTypes::class)->register('extra.thing', 'Another prefix in an existing area', 'Servers', Severity::Warning);

    app(DefaultRulePack::class)->apply($organization->id);

    $example = pack_rules($organization->id)->firstWhere('pack_key', 'area:example');
    expect(pack_rules($organization->id))->toHaveCount($before + 1)
        ->and($example->event_types)->toBe(['example.*'])
        ->and(pack_rules($organization->id)->firstWhere('pack_key', 'area:servers')->event_types)->toContain('servers.*', 'extra.*');
});

it('shows the pack grouped by area and a no-channel banner flag on the rules page', function () {
    [, $organization] = actingAsMember(Role::Admin);

    $this->get('/settings/alert-rules')->assertInertia(fn ($page) => $page
        ->component('Alerting/Rules', false)
        ->where('hasChannel', false)
        ->where('packAreas', fn ($areas) => collect($areas)->pluck('key')->contains('area:servers'))
        ->where('rules', fn ($rules) => collect($rules)->whereNotNull('pack_key')->count() === pack_rules($organization->id)->count())
        ->where('alertTypes', fn ($types) => collect($types)->firstWhere('type', 'volumes.almost_full')['fix'] === 'Grow volume'));

    alerting_channel($organization->id);

    $this->get('/settings/alert-rules')->assertInertia(fn ($page) => $page->where('hasChannel', true));
});

it('toggles a pack rule off', function () {
    [, $organization] = actingAsMember(Role::Admin);
    $rule = pack_rules($organization->id)->firstWhere('pack_key', 'area:servers');

    $this->put("/alerting/rules/{$rule->id}", [
        'name' => $rule->name, 'event_types' => $rule->event_types, 'min_severity' => 'warning', 'enabled' => false, 'channel_ids' => [],
    ])->assertSessionHasNoErrors();

    expect($rule->refresh()->enabled)->toBeFalse()->and($rule->pack_key)->toBe('area:servers');
});

it('keeps each organization to its own pack', function () {
    [, $organization] = memberOf();
    [$otherOwner, $other] = memberOf();
    alerting_channel($other->id, attributes: ['is_default' => true]);

    app(Alerts::class)->raise(new AlertData($organization->id, 'databases.backup_failed', Severity::Critical, 'Backup failed', url: '/databases/x'));

    expect(Alert::query()->where('organization_id', $other->id)->count())->toBe(0)
        ->and(Delivery::query()->count())->toBe(0)
        ->and(Notification::query()->where('user_id', $otherOwner->id)->count())->toBe(0);
});

it('keys areas by their main type prefix, so a renamed group keeps its rule', function () {
    expect(DefaultRulePack::key(['databases', 'pitr', 'databases']))->toBe('area:databases')
        ->and(DefaultRulePack::key(['pitr', 'databases']))->toBe('area:databases')
        ->and(DefaultRulePack::key(['dr']))->toBe('area:dr');

    [, $organization] = memberOf();
    $before = pack_rules($organization->id)->count();
    app(AlertTypes::class)->register('servers.renamed_check', 'Check', 'Server health', Severity::Warning);
    foreach (app(AlertTypes::class)->all() as $type) {
        if ($type['group'] === 'Servers') {
            app(AlertTypes::class)->register($type['type'], $type['label'], 'Server health', $type['severity'], $type['fix']);
        }
    }

    app(DefaultRulePack::class)->apply($organization->id);

    expect(pack_rules($organization->id))->toHaveCount($before)
        ->and(collect(app(DefaultRulePack::class)->areas())->get('area:servers')['group'])->toBe('Server health');
});

it('never routes edited pack rules to a new default channel', function () {
    [, $organization] = actingAsMember(Role::Admin);
    $servers = pack_rules($organization->id)->firstWhere('pack_key', 'area:servers');
    $this->put("/alerting/rules/{$servers->id}", [
        'name' => 'Servers', 'event_types' => ['servers.*'], 'min_severity' => 'critical', 'enabled' => true, 'channel_ids' => [],
    ])->assertSessionHasNoErrors();
    expect($servers->refresh()->user_modified)->toBeTrue();

    $this->post('/alerting/channels', ['name' => 'Ops', 'type' => 'slack', 'config' => ['webhook_url' => ALERTING_SLACK_URL]]);

    expect($servers->channels()->count())->toBe(0)
        ->and(pack_rules($organization->id)->where('user_modified', false)->every(fn (Rule $rule) => $rule->channels()->count() === 1))->toBeTrue();
});

it('asks to choose a default when several channels exist and none is the default', function () {
    [, $organization] = actingAsMember(Role::Admin);
    // As the migration leaves an organization that already had two channels.
    alerting_channel($organization->id);
    alerting_channel($organization->id);

    $this->post('/alerting/channels', ['name' => 'Third', 'type' => 'slack', 'config' => ['webhook_url' => ALERTING_SLACK_URL]]);

    expect(Channel::query()->where('organization_id', $organization->id)->where('is_default', true)->count())->toBe(0)
        ->and(pack_rules($organization->id)->every(fn (Rule $rule) => $rule->channels()->count() === 0))->toBeTrue();
    $this->get('/settings/alert-rules')->assertInertia(fn ($page) => $page->where('hasChannel', true)->where('defaultChannel', null));

    $third = Channel::query()->where('name', 'Third')->sole();
    $this->post("/alerting/channels/{$third->id}/default");
    $this->get('/settings/alert-rules')->assertInertia(fn ($page) => $page->where('defaultChannel', 'Third'));
});

it('keeps one default when channels are deleted', function () {
    [, $organization] = actingAsMember(Role::Admin);
    foreach (['A', 'B', 'C'] as $name) {
        $this->post('/alerting/channels', ['name' => $name, 'type' => 'slack', 'config' => ['webhook_url' => ALERTING_SLACK_URL]]);
    }
    $a = Channel::query()->where('name', 'A')->sole();
    expect($a->is_default)->toBeTrue();

    $this->delete("/alerting/channels/{$a->id}");
    $this->delete('/alerting/channels/'.Channel::query()->where('name', 'C')->value('id'));

    expect(Channel::query()->where('organization_id', $organization->id)->where('is_default', true)->pluck('name')->all())->toBe(['B']);
});
