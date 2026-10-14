<?php

use Falak\Fleet\Domain\Models\Command;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\AuditEntry;
use Falak\Servers\Contracts\ServerStatus;
use Falak\Servers\Domain\Models\Server;
use Falak\Servers\Events\ServerProvisioned;
use Falak\Telemetry\Application\Jobs\DispatchPendingTelemetry;
use Falak\Telemetry\Contracts\Data\SiteTelemetryTarget;
use Falak\Telemetry\Contracts\RequestIdLinking;
use Falak\Telemetry\Contracts\ServerSites;
use Falak\Telemetry\Contracts\TelemetryConfigurator;
use Falak\Telemetry\Domain\Models\PendingConfiguration;
use Falak\Telemetry\Domain\Models\TelemetrySettings;
use Falak\Telemetry\Events\RequestIdLinkingChanged;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/../../../Fleet/tests/Support/helpers.php';

beforeEach(function () {
    config(['fleet.ca_path' => sys_get_temp_dir().'/falak-ca-test', 'telemetry.otlp.endpoint' => 'https://otlp.falak.test:4318', 'telemetry.otlp.token' => null]);
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
});

function telemetry_server(string $organizationId, ServerStatus $status = ServerStatus::Active): Server
{
    return Server::factory()->status($status)->create(['organization_id' => $organizationId]);
}

/**
 * @return list<Command>
 */
function telemetry_commands(?string $serverId = null): array
{
    return Command::query()->where('type', 'telemetry.configure')->when($serverId, fn ($q) => $q->where('server_id', $serverId))->get()->all();
}

function telemetry_payload(Command $command): array
{
    return json_decode($command->payload, true);
}

it('dispatches a schema-valid telemetry.configure after an agent enrolls', function () {
    $server = telemetry_server($this->organization->id);
    fleet_enroll($this->organization->id, $server->id);

    // Deferred: nothing is queued at enrollment time.
    expect(telemetry_commands())->toBe([])
        ->and(PendingConfiguration::query()->find($server->id))->not->toBeNull();

    (new DispatchPendingTelemetry)->handle(app(TelemetryConfigurator::class));
    expect(telemetry_commands())->toBe([]);

    $this->travel(31)->seconds();
    (new DispatchPendingTelemetry)->handle(app(TelemetryConfigurator::class));

    $commands = telemetry_commands($server->id);
    expect($commands)->toHaveCount(1)
        ->and(PendingConfiguration::query()->find($server->id))->toBeNull()
        ->and($commands[0]->idempotency_key)->toStartWith("telemetry.configure:{$server->id}:");

    $payload = telemetry_payload($commands[0]);
    expect(fleet_schema_errors('commands/telemetry.configure.schema.json', $payload))->toBe([])
        ->and($payload)->toBe([
            'endpoint' => 'https://otlp.falak.test:4318',
            'resource' => ['org_id' => strtoupper($this->organization->id), 'server_id' => strtoupper($server->id), 'environment' => 'production'],
            'sampling' => ['traces_ratio' => 1.0],
            'metrics' => ['enabled' => true, 'interval_s' => 15],
            'insights' => ['enabled' => true],
        ]);
});

it('leaves the endpoint out when none is configured (an install without the observability stack)', function () {
    config(['telemetry.otlp.endpoint' => '']);
    $server = telemetry_server($this->organization->id);
    fleet_enroll($this->organization->id, $server->id);

    ServerProvisioned::dispatch($server->id, $this->organization->id, 'web', $server->name);

    $payload = telemetry_payload(telemetry_commands($server->id)[0]);
    expect($payload)->not->toHaveKey('endpoint')
        ->and(fleet_schema_errors('commands/telemetry.configure.schema.json', $payload))->toBe([]);
});

it('configures immediately once a server is provisioned', function () {
    $server = telemetry_server($this->organization->id);
    fleet_enroll($this->organization->id, $server->id);

    ServerProvisioned::dispatch($server->id, $this->organization->id, 'web', $server->name);

    expect(telemetry_commands($server->id))->toHaveCount(1)
        ->and(PendingConfiguration::query()->count())->toBe(0);
});

it('includes organization overrides, the bearer token and sites from the ServerSites binding', function () {
    $server = telemetry_server($this->organization->id);
    // An agent with workload metrics gets each site's unix user and runtime.
    fleet_enroll($this->organization->id, $server->id, ['features' => ['telemetry.workloads', 'telemetry.workloads.shared']]);
    TelemetrySettings::query()->create([
        'organization_id' => $this->organization->id,
        'otlp_endpoint' => 'https://collector.example.com',
        'otlp_token' => 'tok-123',
        'environment' => 'staging',
        'traces_ratio' => 0.25,
        'metrics_interval_s' => 30,
    ]);
    app()->instance(ServerSites::class, new class implements ServerSites
    {
        public function forServer(string $serverId): array
        {
            return [new SiteTelemetryTarget('01jsqte000000000000000000a', 'shop-example-com', 'production', '01jdep0000000000000000000a', null, [
                ['path' => '/srv/falak/sites/shop-example-com/shared/storage/logs/*.log', 'format' => 'json'],
            ], 'shop', 'php-fpm', isolated: true)];
        }
    });

    app(TelemetryConfigurator::class)->reconfigure($server->id);

    $payload = telemetry_payload(telemetry_commands($server->id)[0]);
    expect(fleet_schema_errors('commands/telemetry.configure.schema.json', $payload))->toBe([])
        ->and($payload['endpoint'])->toBe('https://collector.example.com')
        ->and($payload['headers'])->toBe(['Authorization' => 'Bearer tok-123'])
        ->and($payload['resource']['environment'])->toBe('staging')
        ->and($payload['sampling'])->toBe(['traces_ratio' => 0.25])
        ->and($payload['metrics']['interval_s'])->toBe(30)
        ->and($payload['sites'])->toBe([['slug' => 'shop-example-com', 'site_id' => '01JSQTE000000000000000000A', 'environment' => 'production', 'deployment_id' => '01JDEP0000000000000000000A', 'unix_user' => 'shop', 'isolated' => true, 'runtime' => 'php-fpm']])
        ->and($payload['log_sources'])->toBe([['path' => '/srv/falak/sites/shop-example-com/shared/storage/logs/*.log', 'site' => 'shop-example-com', 'format' => 'json']]);
});

it('sends a non-isolated site\'s shared user only to agents that attribute its processes per site', function () {
    app()->instance(ServerSites::class, new class implements ServerSites
    {
        public function forServer(string $serverId): array
        {
            return [
                new SiteTelemetryTarget('01jsqte000000000000000000a', 'iso', unixUser: 'iso', runtime: 'php-fpm', isolated: true),
                new SiteTelemetryTarget('01jsqte000000000000000000b', 'blog', unixUser: 'falak', runtime: 'php-fpm'),
                new SiteTelemetryTarget('01jsqte000000000000000000c', 'dock', runtime: 'docker'),
            ];
        }
    });
    $sites = function (array $features): array {
        $server = telemetry_server($this->organization->id);
        fleet_enroll($this->organization->id, $server->id, ['features' => $features]);
        app(TelemetryConfigurator::class)->reconfigure($server->id);
        $payload = telemetry_payload(telemetry_commands($server->id)[0]);
        expect(fleet_schema_errors('commands/telemetry.configure.schema.json', $payload))->toBe([]);

        return $payload['sites'];
    };

    expect($sites(['telemetry.workloads', 'telemetry.workloads.shared']))->toBe([
        ['slug' => 'iso', 'site_id' => '01JSQTE000000000000000000A', 'unix_user' => 'iso', 'isolated' => true, 'runtime' => 'php-fpm'],
        ['slug' => 'blog', 'site_id' => '01JSQTE000000000000000000B', 'unix_user' => 'falak', 'runtime' => 'php-fpm'],
        ['slug' => 'dock', 'site_id' => '01JSQTE000000000000000000C', 'runtime' => 'docker'],
    ])
        // v0.14.0 agents measure a site by every process of its user: exactly what that control plane sent them, the
        // user of isolated sites only (and no `isolated`, which they would reject).
        ->and($sites(['telemetry.workloads']))->toBe([
            ['slug' => 'iso', 'site_id' => '01JSQTE000000000000000000A', 'unix_user' => 'iso', 'runtime' => 'php-fpm'],
            ['slug' => 'blog', 'site_id' => '01JSQTE000000000000000000B', 'runtime' => 'php-fpm'],
            ['slug' => 'dock', 'site_id' => '01JSQTE000000000000000000C', 'runtime' => 'docker'],
        ])
        ->and($sites([]))->toBe([
            ['slug' => 'iso', 'site_id' => '01JSQTE000000000000000000A'],
            ['slug' => 'blog', 'site_id' => '01JSQTE000000000000000000B'],
            ['slug' => 'dock', 'site_id' => '01JSQTE000000000000000000C'],
        ]);
});

it('skips servers without an agent and unknown servers', function () {
    $server = telemetry_server($this->organization->id);

    expect(app(TelemetryConfigurator::class)->reconfigure($server->id))->toBeNull()
        ->and(app(TelemetryConfigurator::class)->reconfigure('01jxxx0000000000000000000a'))->toBeNull()
        ->and(telemetry_commands())->toBe([]);
});

it('updates settings, audits without secrets and reconfigures every active server of the organization', function () {
    $a = telemetry_server($this->organization->id);
    $b = telemetry_server($this->organization->id);
    $inactive = telemetry_server($this->organization->id, ServerStatus::Provisioning);
    [, $other] = memberOf();
    $foreign = telemetry_server($other->id);
    foreach ([[$this->organization->id, $a], [$this->organization->id, $b], [$this->organization->id, $inactive], [$other->id, $foreign]] as [$org, $server]) {
        fleet_enroll($org, $server->id);
    }

    $this->put('/telemetry/settings', [
        'otlp_endpoint' => 'https://collector.example.com',
        'otlp_token' => 'super-secret',
        'environment' => 'staging',
        'traces_ratio' => 0.5,
        'metrics_interval_s' => 20,
    ])->assertSessionHasNoErrors()->assertRedirect();

    $settings = TelemetrySettings::query()->findOrFail($this->organization->id);
    expect($settings->otlp_token)->toBe('super-secret')
        ->and($settings->getRawOriginal('otlp_token'))->not->toContain('super-secret')
        ->and(collect(telemetry_commands())->pluck('server_id')->sort()->values()->all())->toBe(collect([$a->id, $b->id])->sort()->values()->all());

    $audit = AuditEntry::query()->where('action', 'telemetry.settings.updated')->firstOrFail();
    expect(json_encode($audit->context))->not->toContain('super-secret')
        ->and($audit->context['bearer_changed'])->toBeTrue();

    // Blank token keeps the stored one; clearing removes it.
    $this->put('/telemetry/settings', ['otlp_token' => ''])->assertSessionHasNoErrors();
    expect(TelemetrySettings::query()->findOrFail($this->organization->id)->otlp_token)->toBe('super-secret');

    $this->put('/telemetry/settings', ['clear_otlp_token' => true])->assertSessionHasNoErrors();
    expect(TelemetrySettings::query()->findOrFail($this->organization->id)->otlp_token)->toBeNull();
});

it('turns request ID linking on per organization, off by default, and tells the edges', function () {
    Event::fake([RequestIdLinkingChanged::class]);
    expect(app(RequestIdLinking::class)->enabled($this->organization->id))->toBeFalse();

    // A save without the field keeps it; only a change is announced.
    $this->put('/telemetry/settings', ['environment' => 'staging'])->assertSessionHasNoErrors();
    Event::assertNotDispatched(RequestIdLinkingChanged::class);

    $this->put('/telemetry/settings', ['request_id_linking' => true])->assertSessionHasNoErrors();
    expect(app(RequestIdLinking::class)->enabled($this->organization->id))->toBeTrue();
    Event::assertDispatched(RequestIdLinkingChanged::class, fn ($e) => $e->organizationId === $this->organization->id && $e->enabled);

    $this->put('/telemetry/settings', ['environment' => 'prod'])->assertSessionHasNoErrors();
    expect(app(RequestIdLinking::class)->enabled($this->organization->id))->toBeTrue();
    Event::assertDispatchedTimes(RequestIdLinkingChanged::class, 1);

    $this->get('/settings/observability')->assertOk()->assertInertia(fn ($page) => $page->where('settings.request_id_linking', true));
});

it('validates settings and requires telemetry.manage', function () {
    $this->put('/telemetry/settings', ['otlp_endpoint' => 'ftp://x', 'traces_ratio' => 2, 'metrics_interval_s' => 1])
        ->assertSessionHasErrors(['otlp_endpoint', 'traces_ratio', 'metrics_interval_s']);

    actingAsMember(Role::Developer, $this->organization);
    $this->put('/telemetry/settings', ['environment' => 'x'])->assertForbidden();
});

it('never sends the token to the settings page', function () {
    TelemetrySettings::query()->create(['organization_id' => $this->organization->id, 'otlp_token' => 'super-secret']);

    $this->get('/settings/observability')->assertOk()
        ->assertDontSee('super-secret')
        ->assertInertia(fn ($page) => $page->component('Telemetry/Settings', false)
            ->where('settings.otlp_token_set', true)
            ->where('can.manage', true)
            ->where('backends.grafana.configured', false));
});
