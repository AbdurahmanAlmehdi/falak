<?php

use Falak\Fleet\Domain\Models\Command;
use Falak\Identity\Contracts\Role;
use Falak\Servers\Domain\Models\Server;
use Falak\Telemetry\Contracts\Data\SiteTelemetryTarget;
use Falak\Telemetry\Contracts\ServerSites;
use Falak\Telemetry\Contracts\TelemetryConfigurator;

require_once __DIR__.'/../../../Fleet/tests/Support/helpers.php';

beforeEach(function () {
    config(['fleet.ca_path' => sys_get_temp_dir().'/falak-ca-test', 'telemetry.otlp.endpoint' => 'https://otlp.falak.test:4318', 'telemetry.otlp.token' => null]);
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
    app()->instance(ServerSites::class, new class implements ServerSites
    {
        public function forServer(string $serverId): array
        {
            return [
                new SiteTelemetryTarget('01jsqte000000000000000000a', 'stack', runtime: 'compose', logSources: [
                    ['path' => '/var/www/html/storage/logs/*.log', 'kind' => 'app', 'container' => true, 'compose_service' => 'app', 'multiline' => 'laravel'],
                    ['path' => '/var/log/nginx/error.log', 'kind' => 'app', 'container' => true, 'multiline_pattern' => '^\d{4}/'],
                ]),
                new SiteTelemetryTarget('01jsqte000000000000000000b', 'shop', runtime: 'php-fpm', logSources: [
                    ['path' => '/srv/falak/sites/shop/shared/storage/logs/*.log', 'kind' => 'app', 'multiline' => 'laravel'],
                    ['path' => '/var/log/falak/fpm-shop.log', 'kind' => 'app', 'origin' => 'php_fpm'],
                    ['path' => '/var/log/falak/php-fpm-slow/shop.log', 'kind' => 'app', 'origin' => 'php_fpm_slow', 'multiline_pattern' => '^\[\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2}\]'],
                ]),
            ];
        }
    });
});

/**
 * @param  list<string>  $features
 * @return list<array<string, mixed>>
 */
function log_sources_for(string $organizationId, array $features): array
{
    $server = Server::factory()->create(['organization_id' => $organizationId]);
    fleet_enroll($organizationId, $server->id, ['features' => $features]);
    app(TelemetryConfigurator::class)->reconfigure($server->id);
    $payload = json_decode(Command::query()->where('type', 'telemetry.configure')->where('server_id', $server->id)->firstOrFail()->payload, true);
    expect(fleet_schema_errors('commands/telemetry.configure.schema.json', $payload))->toBe([]);

    return $payload['log_sources'];
}

it('sends container sources, start patterns and origins to agents with logs.container_files', function () {
    expect(log_sources_for($this->organization->id, ['telemetry.log_kind', 'logs.container_files']))->toBe([
        ['path' => '/var/www/html/storage/logs/*.log', 'site' => 'stack', 'kind' => 'app', 'multiline' => 'laravel', 'container' => true, 'compose_service' => 'app'],
        ['path' => '/var/log/nginx/error.log', 'site' => 'stack', 'kind' => 'app', 'multiline_pattern' => '^\d{4}/', 'container' => true],
        ['path' => '/srv/falak/sites/shop/shared/storage/logs/*.log', 'site' => 'shop', 'kind' => 'app', 'multiline' => 'laravel'],
        ['path' => '/var/log/falak/fpm-shop.log', 'site' => 'shop', 'kind' => 'app', 'origin' => 'php_fpm'],
        ['path' => '/var/log/falak/php-fpm-slow/shop.log', 'site' => 'shop', 'kind' => 'app', 'multiline_pattern' => '^\[\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2}\]', 'origin' => 'php_fpm_slow'],
    ]);
});

it('never sends a container source to an older agent: it would tail that path on the host', function () {
    expect(log_sources_for($this->organization->id, ['telemetry.log_kind']))->toBe([
        ['path' => '/srv/falak/sites/shop/shared/storage/logs/*.log', 'site' => 'shop', 'kind' => 'app', 'multiline' => 'laravel'],
        ['path' => '/var/log/falak/fpm-shop.log', 'site' => 'shop', 'kind' => 'app'],
        ['path' => '/var/log/falak/php-fpm-slow/shop.log', 'site' => 'shop', 'kind' => 'app'],
    ]);
});
