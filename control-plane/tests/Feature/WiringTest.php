<?php

/*
| Cross-module wiring (docs/INTEGRATION-NOTES.md "Bindings to wire"): real contract bindings,
| Alertable events routed by Alerting, flash messages shared to Inertia and the ULID case rule.
*/

use Falak\Alerting\Contracts\Alertable;
use Falak\Alerting\Contracts\Alerts;
use Falak\Alerting\Contracts\AlertTypes;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Domain\Enums\ChannelType;
use Falak\Alerting\Domain\Models\Alert;
use Falak\Alerting\Domain\Models\Channel;
use Falak\Alerting\Domain\Models\Rule;
use Falak\Databases\Events\BackupFailed;
use Falak\Databases\Events\BackupSucceeded;
use Falak\Databases\Events\RestoreFinished;
use Falak\Deployments\Contracts\Data\LiveRelease;
use Falak\Deployments\Contracts\LiveReleases;
use Falak\Deployments\Events\ReleaseActivated;
use Falak\Edge\Contracts\EdgeRoutes;
use Falak\Edge\Events\CertificateInstallFailed;
use Falak\Edge\Events\CertificateIssued;
use Falak\Fleet\Events\AgentRevoked;
use Falak\Fleet\Events\AgentVersionChanged;
use Falak\Identity\Contracts\Role;
use Falak\Insights\Contracts\SiteNameResolver;
use Falak\Network\Events\FirewallApplied;
use Falak\Network\Events\FirewallApplyFailed;
use Falak\Processes\Contracts\ProcessControl;
use Falak\Processes\Contracts\ScheduleDirectory;
use Falak\Processes\Events\ProgramCrashLooping;
use Falak\Servers\Contracts\ServerType;
use Falak\Servers\Domain\Models\Server;
use Falak\Sites\Contracts\BuildMode;
use Falak\Sites\Contracts\Data\LaravelSettings;
use Falak\Sites\Contracts\Data\SharedPath;
use Falak\Sites\Contracts\Framework;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Sites\Contracts\TargetRole;
use Falak\Sites\Contracts\TargetStatus;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Models\SiteTarget;
use Falak\Sites\Events\SiteDeleted;
use Falak\Sites\Infrastructure\EloquentServerSites;
use Falak\Sites\Infrastructure\EloquentSiteNameResolver;
use Falak\Telemetry\Contracts\ServerSites;
use Falak\Telemetry\Contracts\TelemetryConfigurator;
use Falak\Telemetry\Domain\Models\TelemetrySettings;
use Falak\Volumes\Contracts\ServiceVolumes;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/FakeAgentGateway.php';

function wiring_site(string $organizationId, Server $server, string $slug = 'shop'): Site
{
    $site = Site::query()->create([
        'organization_id' => $organizationId, 'name' => ucfirst($slug), 'slug' => $slug, 'runtime' => SiteRuntime::FrankenPhp, 'build_mode' => BuildMode::Native,
        'framework' => Framework::Laravel, 'php_version' => '8.4', 'unix_user' => 'falak', 'deploy_script' => '', 'laravel' => new LaravelSettings,
    ]);
    SiteTarget::query()->create(['site_id' => $site->id, 'server_id' => $server->id, 'role' => TargetRole::Leader, 'status' => TargetStatus::Ready]);

    return $site;
}

it('binds the real Sites implementations of Insights and Telemetry contracts', function () {
    expect(app(SiteNameResolver::class))->toBeInstanceOf(EloquentSiteNameResolver::class)
        ->and(app(ServerSites::class))->toBeInstanceOf(EloquentServerSites::class)
        ->and(app(ProcessControl::class))->toBeInstanceOf(ProcessControl::class)
        ->and(app(ScheduleDirectory::class))->toBeInstanceOf(ScheduleDirectory::class);

    [, $organization] = memberOf();
    $server = Server::factory()->create(['organization_id' => $organization->id, 'type' => ServerType::Web]);
    $site = wiring_site($organization->id, $server);

    expect(app(SiteNameResolver::class)->names([$site->id, strtoupper($site->id), '01J00000000000000000000000']))->toBe([
        $site->id => 'Shop',
        strtoupper($site->id) => 'Shop',
        '01J00000000000000000000000' => '01J00000000000000000000000',
    ]);

    $targets = app(ServerSites::class)->forServer($server->id);
    expect($targets)->toHaveCount(1)->and($targets[0]->slug)->toBe('shop')->and($targets[0]->siteId)->toBe($site->id)
        // A non-isolated site runs as the shared user: sent too, the agent attributes that user's processes per site.
        ->and($targets[0]->unixUser)->toBe('falak')->and($targets[0]->isolated)->toBeFalse()->and($targets[0]->runtime)->toBe('frankenphp');

    $site->forceFill(['unix_user' => 'shop', 'isolated' => true])->save();
    expect(app(ServerSites::class)->forServer($server->id)[0])->unixUser->toBe('shop')->isolated->toBeTrue();

    // Docker sites are measured through their containers.
    $site->forceFill(['runtime' => SiteRuntime::Docker, 'build_mode' => BuildMode::Docker])->save();
    expect(app(ServerSites::class)->forServer($server->id)[0]->unixUser)->toBeNull();
});

it('sends the site list upper-case in telemetry.configure and resends it when sites change', function () {
    $agents = FakeAgentGateway::install();
    [, $organization] = memberOf();
    TelemetrySettings::query()->create(['organization_id' => $organization->id, 'otlp_endpoint' => 'https://otlp.example.com']);
    $server = Server::factory()->create(['organization_id' => $organization->id, 'type' => ServerType::Web]);
    $site = wiring_site($organization->id, $server);

    app(TelemetryConfigurator::class)->reconfigure($server->id);
    expect($agents->last('telemetry.configure')['payload']['sites'])->toBe([['slug' => 'shop', 'site_id' => strtoupper($site->id), 'unix_user' => 'falak', 'runtime' => 'frankenphp']]);

    SiteDeleted::dispatch($site->id, $organization->id, 'shop', [$server->id]);
    expect($agents->dispatched('telemetry.configure'))->toHaveCount(2);
});

it('tails PHP sites\' shared log directories and labels records with the live release', function () {
    $agents = FakeAgentGateway::install();
    [, $organization] = memberOf();
    TelemetrySettings::query()->create(['organization_id' => $organization->id, 'otlp_endpoint' => 'https://otlp.example.com']);
    $server = Server::factory()->create(['organization_id' => $organization->id, 'type' => ServerType::Web]);
    $site = wiring_site($organization->id, $server);
    app(ServiceVolumes::class)->syncSharedPaths($organization->id, $site->id, [new SharedPath('storage'), new SharedPath('.env', 'file')]);
    $symfony = wiring_site($organization->id, $server, 'symfony');
    $symfony->forceFill(['framework' => Framework::Symfony])->save();
    app(ServiceVolumes::class)->syncSharedPaths($organization->id, $symfony->id, [new SharedPath('var/log')]);
    $node = wiring_site($organization->id, $server, 'node');
    $node->forceFill(['framework' => Framework::Node, 'runtime' => SiteRuntime::Node])->save();
    app(ServiceVolumes::class)->syncSharedPaths($organization->id, $node->id, [new SharedPath('storage')]);
    app()->instance(LiveReleases::class, new class($site->id, $server->id) implements LiveReleases
    {
        public function __construct(private string $siteId, private string $serverId) {}

        public function onServer(string $serverId): array
        {
            return [$this->siteId => new LiveRelease($this->siteId, $this->serverId, '01jre00000000000000000000a', '01jdep0000000000000000000a', [])];
        }
    });

    app(TelemetryConfigurator::class)->reconfigure($server->id);
    $payload = $agents->last('telemetry.configure')['payload'];

    expect($payload['log_sources'])->toBe([
        ['path' => '/srv/falak/sites/shop/shared/storage/logs/*.log', 'site' => 'shop', 'kind' => 'app', 'multiline' => 'laravel'],
        ['path' => '/srv/falak/sites/symfony/shared/var/log/*.log', 'site' => 'symfony', 'kind' => 'app'],
    ])->and($payload['sites'])->toContain(['slug' => 'shop', 'site_id' => strtoupper($site->id), 'deployment_id' => '01JDEP0000000000000000000A', 'release_id' => '01JRE00000000000000000000A', 'unix_user' => 'falak', 'runtime' => 'frankenphp'])
        ->and($payload['sites'])->toContain(['slug' => 'node', 'site_id' => strtoupper($node->id), 'unix_user' => 'falak', 'runtime' => 'node']);

    // An activation re-sends the configuration (new deployment / release ids).
    ReleaseActivated::dispatch('01jre00000000000000000000b', $organization->id, $site->id, '01jdep0000000000000000000b', null, null, [$server->id]);
    expect($agents->dispatched('telemetry.configure'))->toHaveCount(2);
});

it('re-sends edge and telemetry state after an agent upgrade', function () {
    $agents = FakeAgentGateway::install();
    [, $organization] = memberOf();
    $server = Server::factory()->create(['organization_id' => $organization->id, 'type' => ServerType::Web]);
    wiring_site($organization->id, $server);
    app(EdgeRoutes::class)->apply($server->id);
    $edge = count($agents->dispatched('edge.caddy.apply'));

    AgentVersionChanged::dispatch('01jagent000000000000000000', $organization->id, $server->id, '1.0.0', '1.1.0', ['edge.access_log']);

    // The compiled edge payload did not change, but the new agent understands more of it.
    expect($agents->dispatched('edge.caddy.apply'))->toHaveCount($edge + 1)
        ->and($agents->dispatched('telemetry.configure'))->toHaveCount(1);
});

it('routes every Alertable module event through Alerting with a registered type', function () {
    $raised = new class implements Alerts
    {
        /** @var list<AlertData> */
        public array $alerts = [];

        public function raise(AlertData $alert): void
        {
            $this->alerts[] = $alert;
        }
    };
    app()->instance(Alerts::class, $raised);

    $org = '01j00000000000000000000org';
    $server = '01j0000000000000000000srv1';
    $events = [
        new BackupFailed('b1', $org, $server, 'db-1', 'app', 'upload failed', 's1', 'schedule'),
        new BackupSucceeded('b2', $org, $server, 'db-1', 'app', 10, str_repeat('a', 64), 5, 's1', 'schedule'),
        new RestoreFinished('r1', $org, 'b1', $server, 'app', false, 'pg_restore failed'),
        new RestoreFinished('r2', $org, 'b1', $server, 'app', true, null),
        new AgentRevoked('a1', $org, $server, 'host replaced'),
        new FirewallApplyFailed($server, $org, 'c1', 'nft: syntax error'),
        new FirewallApplied($server, $org, 'c2', null),
        new CertificateInstallFailed('cert1', $org, $server, 'site1', ['shop.test'], 'bad key'),
        new CertificateIssued('cert1', $org, $server, ['shop.test'], null),
        new ProgramCrashLooping($org, $server, 'web-1', 'site1', 'shop.horizon', 'Horizon', 'fatal', 9, 1, '/sites/site1/queues'),
    ];

    foreach ($events as $event) {
        expect($event)->toBeInstanceOf(Alertable::class);
        event($event);
    }

    $types = array_map(fn (AlertData $alert) => $alert->type, $raised->alerts);
    expect($types)->toBe([
        'databases.backup_failed', 'databases.backup_recovered', 'databases.restore_failed', 'databases.restore_succeeded', 'fleet.agent_revoked',
        'network.firewall_failed', 'network.firewall_recovered', 'edge.certificate_failed', 'edge.certificate_installed', 'processes.crash_loop',
    ])->and(array_keys(app(AlertTypes::class)->all()))->toContain(...$types);

    // Recoveries clear the failure's dedup key.
    expect($raised->alerts[1]->resolves)->toBeTrue()->and($raised->alerts[1]->dedupKey)->toBe($raised->alerts[0]->dedupKey)
        ->and($raised->alerts[6]->dedupKey)->toBe($raised->alerts[5]->dedupKey)
        ->and($raised->alerts[8]->dedupKey)->toBe($raised->alerts[7]->dedupKey);
});

it('delivers an Alertable event to a matching rule end to end', function () {
    Http::fake(fn () => Http::response('ok', 200));
    [, $organization] = memberOf();
    $channel = Channel::query()->create([
        'organization_id' => $organization->id, 'type' => ChannelType::Slack, 'name' => 'ops',
        'config' => ['webhook_url' => 'https://hooks.slack.com/services/T000/B000/SECRETSECRETSECRET'], 'enabled' => true,
    ]);
    $rule = Rule::query()->create(['organization_id' => $organization->id, 'name' => 'Backups', 'event_types' => ['databases.*'], 'min_severity' => 'info', 'enabled' => true]);
    $rule->channels()->attach($channel->id);

    event(new BackupFailed('b1', $organization->id, '01j0000000000000000000srv1', 'db-1', 'app', 'upload failed', null, 'manual'));

    expect(Alert::query()->where('type', 'databases.backup_failed')->exists())->toBeTrue();
    Http::assertSent(fn ($request) => str_contains($request->url(), 'hooks.slack.com'));
});

it('shares flash messages with Inertia so the Telemetry settings notice shows', function () {
    actingAsMember(Role::Owner);

    $this->from('/settings/observability')->put('/telemetry/settings', ['otlp_endpoint' => 'https://otlp.example.com'])->assertRedirect('/settings/observability');

    $this->get('/settings/observability')->assertInertia(fn (Assert $page) => $page->where('flash.status', fn ($status) => str_starts_with((string) $status, 'Telemetry settings saved')));

    // Consumed after one request.
    $this->get('/settings/observability')->assertInertia(fn (Assert $page) => $page->where('flash', []));
});

it('shares success, warning and error flashes', function () {
    actingAsMember(Role::Owner);

    Route::middleware(['web', 'auth'])->get('/_flash-test', fn () => back()->with('success', 'Saved.')->with('warning', 'Careful.')->with('error', 'Nope.'));
    $this->get('/_flash-test');

    $this->get('/settings/profile')->assertInertia(fn (Assert $page) => $page->where('flash', ['success' => 'Saved.', 'error' => 'Nope.', 'warning' => 'Careful.']));
});

it('sends ids upper-case at the agent boundary and accepts either case back', function () {
    [, $organization] = memberOf();
    $server = Server::factory()->create(['organization_id' => $organization->id, 'type' => ServerType::Web]);
    $site = wiring_site($organization->id, $server);

    $vars = app(SiteDirectory::class)->deployVariables($site->id, $server->id, ['FALAK_DEPLOYMENT_ID' => strtolower('01J9ZQ3M4B5C6D7E8F9G0H1J2K'), 'FALAK_COMMIT' => 'abcdef']);

    expect($vars['FALAK_SITE_ID'])->toBe(strtoupper($site->id))
        ->and($vars['FALAK_SERVER_ID'])->toBe(strtoupper($server->id))
        ->and($vars['FALAK_DEPLOYMENT_ID'])->toBe('01J9ZQ3M4B5C6D7E8F9G0H1J2K')
        ->and($vars['FALAK_COMMIT'])->toBe('abcdef');

    // Stored lowercase; lookups from agent-provided (upper-case) ids resolve.
    expect($site->id)->toBe(strtolower($site->id))
        ->and(app(ScheduleDirectory::class)->forServer(strtoupper($server->id)))->toBe([])
        ->and(app(ProcessControl::class)->restartForSite(strtoupper($site->id)))->toBe([]);
});
