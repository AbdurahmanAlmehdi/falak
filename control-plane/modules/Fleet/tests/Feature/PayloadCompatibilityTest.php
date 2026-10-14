<?php

use Falak\Fleet\Application\PayloadCompatibility;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Domain\Models\Command;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    config(['fleet.ca_path' => sys_get_temp_dir().'/falak-ca-test']);
    [, $this->organization] = memberOf();
});

function compat_payload(): array
{
    return [
        'sites' => [
            ['id' => 'shop', 'domains' => ['shop.test'], 'kind' => 'static', 'root' => '/srv/shop', 'access_log' => 'shop'],
            ['id' => 'api', 'domains' => ['api.test'], 'kind' => 'static', 'root' => '/srv/api'],
        ],
    ];
}

it('strips optional fields an agent does not list in its features', function () {
    $serverId = strtolower((string) Str::ulid());
    fleet_enroll($this->organization->id, $serverId);

    $handle = app(AgentGateway::class)->dispatch($serverId, 'edge.caddy.apply', compat_payload());
    $sent = json_decode(Command::query()->findOrFail($handle->id)->payload, true);

    expect($sent['sites'][0])->not->toHaveKey('access_log')
        ->and($sent['sites'][0]['id'])->toBe('shop')
        ->and($sent['sites'][1])->toBe(['id' => 'api', 'domains' => ['api.test'], 'kind' => 'static', 'root' => '/srv/api']);

    $handle = app(AgentGateway::class)->dispatch($serverId, 'telemetry.configure', ['log_sources' => [['path' => '/x/*.log', 'kind' => 'app', 'multiline' => 'laravel']]]);
    expect(json_decode(Command::query()->findOrFail($handle->id)->payload, true)['log_sources'])->toBe([['path' => '/x/*.log']]);

    $handle = app(AgentGateway::class)->dispatch($serverId, 'telemetry.configure', ['sites' => [['slug' => 'shop', 'site_id' => '01JSQTE000000000000000000A', 'unix_user' => 'shop', 'runtime' => 'php-fpm']]]);
    expect(json_decode(Command::query()->findOrFail($handle->id)->payload, true)['sites'])->toBe([['slug' => 'shop', 'site_id' => '01JSQTE000000000000000000A']]);
});

it('keeps them for agents that support the feature', function () {
    $serverId = strtolower((string) Str::ulid());
    fleet_enroll($this->organization->id, $serverId, ['features' => ['edge.access_log', 'telemetry.log_kind', 'telemetry.workloads', 'telemetry.workloads.shared']]);

    $handle = app(AgentGateway::class)->dispatch($serverId, 'edge.caddy.apply', compat_payload());
    expect(json_decode(Command::query()->findOrFail($handle->id)->payload, true)['sites'][0]['access_log'])->toBe('shop');

    $handle = app(AgentGateway::class)->dispatch($serverId, 'telemetry.configure', ['log_sources' => [['path' => '/x/*.log', 'kind' => 'app', 'multiline' => 'laravel']]]);
    expect(json_decode(Command::query()->findOrFail($handle->id)->payload, true)['log_sources'][0])->toBe(['path' => '/x/*.log', 'kind' => 'app', 'multiline' => 'laravel']);

    $handle = app(AgentGateway::class)->dispatch($serverId, 'telemetry.configure', ['sites' => [['slug' => 'shop', 'site_id' => '01JSQTE000000000000000000A', 'unix_user' => 'shop', 'runtime' => 'php-fpm']]]);
    expect(json_decode(Command::query()->findOrFail($handle->id)->payload, true)['sites'][0])->toBe(['slug' => 'shop', 'site_id' => '01JSQTE000000000000000000A', 'unix_user' => 'shop', 'runtime' => 'php-fpm']);
});

it('sends query insights only to agents with database observability', function () {
    $document = fn () => json_decode('{"instance":{"id":"01hzyinst00000000000000001","query_insights":true},"password":"x"}');

    foreach (['db.instance.create', 'db.instance.update'] as $type) {
        expect((array) PayloadCompatibility::adapt($type, $document(), [])->instance)->not->toHaveKey('query_insights')
            ->and(PayloadCompatibility::adapt($type, $document(), ['db.observability'])->instance->query_insights)->toBeTrue();
    }

    $apply = fn () => json_decode('{"instance":{"id":"01hzyinst00000000000000001","query_insights":true},"apply_query_insights":true,"password":"x"}');
    expect((array) PayloadCompatibility::adapt('db.instance.update', $apply(), []))->not->toHaveKey('apply_query_insights')
        ->and(PayloadCompatibility::adapt('db.instance.update', $apply(), ['db.observability'])->apply_query_insights)->toBeTrue();
});
