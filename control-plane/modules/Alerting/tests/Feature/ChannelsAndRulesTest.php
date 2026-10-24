<?php

use Falak\Alerting\Domain\Enums\ChannelType;
use Falak\Alerting\Domain\Models\Channel;
use Falak\Alerting\Domain\Models\Rule;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\AuditEntry;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    alerting_guard();
    Http::preventStrayRequests();
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
});

it('creates channels of every type with encrypted config', function (string $type, array $config) {
    $this->post('/alerting/channels', ['name' => "My {$type}", 'type' => $type, 'config' => $config])
        ->assertSessionHasNoErrors()->assertRedirect('/settings/alert-channels');

    $channel = Channel::query()->where('name', "My {$type}")->sole();
    expect($channel->type->value)->toBe($type)
        ->and($channel->organization_id)->toBe($this->organization->id)
        ->and(DB::table('alerting_channels')->value('config'))->not->toContain('SECRET')->not->toContain('example.com')
        ->and($channel->config)->toHaveKeys(array_keys($config));

    expect(AuditEntry::query()->where('action', 'alerting.channel.created')->sole()->context)->toMatchArray(['type' => $type])
        ->and(json_encode(AuditEntry::query()->where('action', 'alerting.channel.created')->sole()->context))->not->toContain('SECRET');
})->with([
    ['email', ['recipients' => ['Ops@Example.com', 'dev@example.com']]],
    ['slack', ['webhook_url' => 'https://hooks.slack.com/services/T0/B0/SECRETxyz']],
    ['discord', ['webhook_url' => 'https://discord.com/api/webhooks/1/SECRETxyz']],
    ['telegram', ['bot_token' => '123456:SECRETSECRETSECRETSECRET', 'chat_id' => '-1001234']],
    ['webhook', ['url' => 'https://hooks.example.com/SECRETxyz', 'secret' => 'a-long-enough-SECRET']],
]);

it('validates channel config per type', function (string $type, array $config, string $error) {
    $this->post('/alerting/channels', ['name' => 'Bad', 'type' => $type, 'config' => $config])->assertSessionHasErrors($error);
    expect(Channel::query()->count())->toBe(0);
})->with([
    ['slack', ['webhook_url' => 'https://evil.example.com/services/x'], 'config.webhook_url'],
    ['slack', ['webhook_url' => 'http://hooks.slack.com/services/x'], 'config.webhook_url'],
    ['discord', ['webhook_url' => 'https://discord.evil.com/api/webhooks/1'], 'config.webhook_url'],
    ['telegram', ['bot_token' => 'nope', 'chat_id' => '-1'], 'config.bot_token'],
    ['email', ['recipients' => ['not-an-email']], 'config.recipients.0'],
    ['webhook', ['url' => 'http://127.0.0.1:9000/hook', 'secret' => 'a-long-enough-secret'], 'config.url'],
    ['webhook', ['url' => 'http://169.254.169.254/latest/meta-data', 'secret' => 'a-long-enough-secret'], 'config.url'],
    ['webhook', ['url' => 'https://hooks.example.com/x', 'secret' => 'short'], 'config.secret'],
    ['pager', ['x' => 'y'], 'type'],
]);

it('refuses webhook hosts that resolve to private addresses, also when allowed on a managed install', function () {
    alerting_guard(['127.0.0.1.nip.io' => ['127.0.0.1'], 'lan.example.com' => ['10.0.0.5']]);
    $webhook = fn (string $url) => $this->post('/alerting/channels', ['name' => 'Hook', 'type' => 'webhook', 'config' => ['url' => $url, 'secret' => 'a-long-enough-secret']]);

    $webhook('http://127.0.0.1.nip.io/hook')->assertSessionHasErrors('config.url');

    config(['alerting.allow_private_webhooks' => true, 'controlplane.managed' => true]);
    $webhook('http://lan.example.com/hook')->assertSessionHasErrors('config.url');

    config(['controlplane.managed' => false]);
    $webhook('http://lan.example.com/hook')->assertSessionHasNoErrors();
});

it('never sends channel secrets to the UI', function () {
    alerting_channel($this->organization->id, ChannelType::Slack);
    alerting_channel($this->organization->id, ChannelType::Telegram);
    alerting_channel($this->organization->id, ChannelType::Webhook);

    $response = $this->get('/settings/alert-channels')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Alerting/Channels', false)
        ->has('channels', 3)
        ->where('can.manage', true));

    $props = json_encode($response->viewData('page')['props'], JSON_UNESCAPED_UNICODE);
    expect($props)->not->toContain('SECRETSECRET')
        ->not->toContain('ABCDEFGHIJKLMNOPQRSTUVWXYZ')
        ->not->toContain('a-very-long-signing-secret')
        ->not->toContain('hooks.example.com/falak')
        ->toContain('https:\\/\\/hooks.slack.com\\/…\\/CRET');
});

it('keeps stored secrets when updating with blank secret fields', function () {
    $channel = alerting_channel($this->organization->id, ChannelType::Webhook);

    $this->put("/alerting/channels/{$channel->id}", ['name' => 'Renamed', 'enabled' => false, 'config' => ['url' => '', 'secret' => '']])
        ->assertSessionHasNoErrors();

    $channel->refresh();
    expect($channel->name)->toBe('Renamed')->and($channel->enabled)->toBeFalse()
        ->and($channel->config)->toBe(['url' => 'https://hooks.example.com/falak', 'secret' => 'a-very-long-signing-secret']);

    $this->put("/alerting/channels/{$channel->id}", ['name' => 'Renamed', 'config' => ['url' => 'https://other.example.com/x', 'secret' => '']])->assertSessionHasNoErrors();
    expect($channel->refresh()->config['url'])->toBe('https://other.example.com/x');
});

it('sends a test message synchronously and reports the result', function () {
    $channel = alerting_channel($this->organization->id, ChannelType::Slack);
    Http::fake(['hooks.slack.com/*' => Http::sequence()->push('ok')->push('channel_not_found', 404)]);

    $this->postJson("/alerting/channels/{$channel->id}/test")->assertOk()->assertJson(['ok' => true, 'error' => null]);
    Http::assertSent(fn (Request $request) => $request['text'] === '[INFO] Falak test alert');
    expect($channel->refresh()->last_sent_at)->not->toBeNull();

    $this->postJson("/alerting/channels/{$channel->id}/test")->assertStatus(422)->assertJson(['ok' => false, 'error' => 'HTTP 404: channel_not_found']);
    expect($channel->refresh()->last_error)->toBe('HTTP 404: channel_not_found');
});

it('hides other organizations channels and rules', function () {
    [, $other] = memberOf();
    $channel = alerting_channel($other->id);
    $rule = alerting_rule($other->id);

    $this->put("/alerting/channels/{$channel->id}", ['name' => 'x', 'config' => []])->assertNotFound();
    $this->delete("/alerting/channels/{$channel->id}")->assertNotFound();
    $this->postJson("/alerting/channels/{$channel->id}/test")->assertNotFound();
    $this->delete("/alerting/rules/{$rule->id}")->assertNotFound();
    $this->get('/settings/alert-channels')->assertInertia(fn ($page) => $page->has('channels', 0));
});

it('lets viewers see but not change alerting', function () {
    [$viewer] = memberOf($this->organization, Role::Viewer);
    $this->actingAs($viewer);
    $channel = alerting_channel($this->organization->id);

    $this->get('/settings/alert-channels')->assertOk()->assertInertia(fn ($page) => $page->where('can.manage', false));
    $this->get('/settings/alert-rules')->assertOk();
    $this->get('/observability/alerts')->assertOk();
    $this->post('/alerting/channels', ['name' => 'x', 'type' => 'slack', 'config' => ['webhook_url' => ALERTING_SLACK_URL]])->assertForbidden();
    $this->postJson("/alerting/channels/{$channel->id}/test")->assertForbidden();
    $this->delete("/alerting/channels/{$channel->id}")->assertForbidden();
    $this->post('/alerting/rules', ['name' => 'r', 'event_types' => ['*'], 'min_severity' => 'info', 'channel_ids' => []])->assertForbidden();
});

it('creates, updates and deletes rules', function () {
    alerting_without_default_pack($this->organization->id);
    $slack = alerting_channel($this->organization->id);
    [, $other] = memberOf();
    $foreign = alerting_channel($other->id);

    $this->post('/alerting/rules', [
        'name' => 'On-call', 'event_types' => ['fleet.*', 'insights.heartbeat_missed'], 'min_severity' => 'warning',
        'channel_ids' => [$foreign->id],
    ])->assertSessionHasErrors('channel_ids.0');

    $this->post('/alerting/rules', [
        'name' => 'On-call', 'event_types' => ['fleet.*', 'insights.heartbeat_missed'], 'min_severity' => 'warning',
        'channel_ids' => [$slack->id], 'rate_limit_per_hour' => 10,
        'quiet_hours' => ['enabled' => true, 'start' => '22:00', 'end' => '07:00', 'timezone' => 'Europe/Berlin', 'days' => [1, 2], 'allow_critical' => true],
    ])->assertSessionHasNoErrors()->assertRedirect('/settings/alert-rules');

    $rule = Rule::query()->whereNull('pack_key')->sole();
    expect($rule->event_types)->toBe(['fleet.*', 'insights.heartbeat_missed'])
        ->and($rule->quiet_hours)->toBe(['start' => '22:00', 'end' => '07:00', 'timezone' => 'Europe/Berlin', 'days' => [1, 2], 'allow_critical' => true])
        ->and($rule->channels()->pluck('alerting_channels.id')->all())->toBe([$slack->id]);

    $this->get('/settings/alert-rules')->assertInertia(fn ($page) => $page
        ->component('Alerting/Rules', false)
        ->has('rules', 1)
        ->where('rules.0.channels.0.id', $slack->id)
        ->where('alertTypes', fn ($types) => collect($types)->pluck('type')->contains('fleet.agent_offline')));

    $this->put("/alerting/rules/{$rule->id}", ['name' => 'On-call', 'event_types' => ['*'], 'min_severity' => 'info', 'channel_ids' => [], 'quiet_hours' => ['enabled' => false]])
        ->assertSessionHasNoErrors();
    expect($rule->refresh()->quiet_hours)->toBeNull()->and($rule->channels()->count())->toBe(0);

    $this->post('/alerting/rules', ['name' => 'Bad', 'event_types' => ['DROP TABLE'], 'min_severity' => 'loud', 'channel_ids' => []])
        ->assertSessionHasErrors(['event_types.0', 'min_severity']);

    $this->delete("/alerting/rules/{$rule->id}")->assertRedirect();
    expect(Rule::query()->whereNull('pack_key')->count())->toBe(0)
        ->and(AuditEntry::query()->where('action', 'like', 'alerting.rule.%')->pluck('action')->sort()->values()->all())->toBe(['alerting.rule.created', 'alerting.rule.deleted', 'alerting.rule.updated']);
});

it('deletes a channel and detaches it from rules', function () {
    $slack = alerting_channel($this->organization->id);
    $rule = alerting_rule($this->organization->id, ['*'], [$slack]);

    $this->delete("/alerting/channels/{$slack->id}")->assertRedirect();

    expect(Channel::query()->count())->toBe(0)->and($rule->channels()->count())->toBe(0);
});

it('lists alert history with deliveries', function () {
    Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
    alerting_rule($this->organization->id, ['*'], [alerting_channel($this->organization->id)]);
    event(alerting_issue_opened($this->organization->id));

    $this->get('/alerting/history?outcome=delivered')->assertRedirect('/observability/alerts?outcome=delivered');
    $this->get('/observability/alerts')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Alerting/History', false)
        ->has('alerts.data', 1)
        ->where('alerts.data.0.outcome', 'delivered')
        ->where('alerts.data.0.deliveries.0.status', 'sent'));

    $this->get('/observability/alerts?outcome=no_route')->assertInertia(fn ($page) => $page->has('alerts.data', 0));
});
