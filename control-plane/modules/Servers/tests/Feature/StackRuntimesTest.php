<?php

use Falak\Identity\Application\Actions\CreateApiToken;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\AuditEntry;
use Falak\Servers\Contracts\ServerStatus;
use Falak\Servers\Domain\Enums\PhpVersionStatus;
use Falak\Servers\Domain\Models\Server;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    config(['fleet.ca_path' => sys_get_temp_dir().'/falak-ca-test']);
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->token = fn (array $abilities = ['*']) => app(CreateApiToken::class)($this->user, $this->organization->id, 'cli', $abilities)->plainTextToken;

    // A provisioned server of the given type created without PHP or Node.
    $this->bare = function (string $name, string $type): array {
        $this->post('/servers', ['name' => $name, 'type' => $type, 'provider' => 'custom', 'stack' => ['php' => null, 'node' => null]])->assertSessionHasNoErrors();
        $server = Server::query()->where('name', $name)->firstOrFail();
        $agent = servers_enroll_agent($server, ['memory_bytes' => 4 * 1024 ** 3]);
        servers_poll($agent['headers']);
        servers_finish($agent['headers'], $server->refresh()->provision_command_id);
        servers_poll($agent['headers']); // ssh key syncs
        expect($server->refresh()->status)->toBe(ServerStatus::Active)->and($server->stack->phpRuntime)->toBeNull();

        return [$server, $agent];
    };
});

it('adds PHP-FPM and Node to an app server created without them and installs them by re-provisioning', function () {
    [$server, $agent] = ($this->bare)('main-1', 'app');

    $this->withToken(($this->token)())->postJson("/api/v1/servers/{$server->id}/stack", ['php' => ['runtime' => 'fpm', 'version' => '8.4'], 'node' => '22'])
        ->assertStatus(202)
        ->assertJsonPath('data.stack.php', ['runtime' => 'fpm', 'versions' => ['8.4'], 'default' => '8.4'])
        ->assertJsonPath('data.stack.node', '22')
        ->assertJsonPath('data.status', 'provisioning');

    $php = $server->phpVersions()->where('version', '8.4')->firstOrFail();
    expect($php->status)->toBe(PhpVersionStatus::Installing)->and($php->is_default)->toBeTrue()
        ->and(AuditEntry::query()->where('action', 'server.stack_runtimes_added')->exists())->toBeTrue();

    [$envelope] = servers_poll($agent['headers']);
    expect($envelope['type'])->toBe('provision.apply')
        ->and($envelope['payload']['runtimes']['php']['versions'])->toBe(['8.4'])
        ->and($envelope['payload']['runtimes']['php']['fpm'])->toBeTrue()
        ->and($envelope['payload']['runtimes'])->toHaveKey('node')->not->toHaveKey('frankenphp')
        ->and($envelope['payload']['runtimes']['caddy']['enabled'])->toBeTrue();

    servers_finish($agent['headers'], $envelope['id']);
    expect($php->refresh()->status)->toBe(PhpVersionStatus::Installed)
        ->and($server->refresh()->status)->toBe(ServerStatus::Active);

    // Add-only: a runtime the server has is never replaced.
    $this->withToken(($this->token)())->postJson("/api/v1/servers/{$server->id}/stack", ['php' => ['runtime' => 'frankenphp']])
        ->assertStatus(422)->assertJsonValidationErrors(['stack.php']);
    $this->withToken(($this->token)())->postJson("/api/v1/servers/{$server->id}/stack", ['node' => '22'])
        ->assertStatus(422)->assertJsonValidationErrors(['stack.node']);
});

it('gives a builder the PHP CLI (for Composer) and Node, never a web runtime', function () {
    [$server, $agent] = ($this->bare)('builder', 'builder');

    $this->withToken(($this->token)())->postJson("/api/v1/servers/{$server->id}/stack", ['php' => ['runtime' => 'fpm']])
        ->assertStatus(422)->assertJsonValidationErrors(['stack.php.runtime']);
    expect($server->refresh()->stack->phpRuntime)->toBeNull();

    $this->withToken(($this->token)())->postJson("/api/v1/servers/{$server->id}/stack", ['php' => ['runtime' => 'cli'], 'node' => '22'])
        ->assertStatus(202)->assertJsonPath('data.stack.php.runtime', 'cli');

    [$envelope] = servers_poll($agent['headers']);
    expect($envelope['payload']['runtimes']['php']['fpm'])->toBeFalse()
        ->and($envelope['payload']['runtimes'])->toHaveKey('node')->not->toHaveKey('frankenphp')->not->toHaveKey('caddy');
});

it('refuses the PHP CLI runtime on a site server, and callers without update rights', function () {
    [$server] = ($this->bare)('app-2', 'app');

    app('auth')->forgetGuards();
    $this->withToken(($this->token)(['servers.view']))->postJson("/api/v1/servers/{$server->id}/stack", ['node' => '22'])
        ->assertForbidden();
    app('auth')->forgetGuards();

    $this->withToken(($this->token)())->postJson("/api/v1/servers/{$server->id}/stack", ['php' => ['runtime' => 'cli']])
        ->assertStatus(422)->assertJsonValidationErrors(['stack.php.runtime']);
    $this->withToken(($this->token)())->postJson("/api/v1/servers/{$server->id}/stack", [])
        ->assertStatus(422)->assertJsonValidationErrors(['stack']);
    expect($server->refresh()->stack->node)->toBeNull();
});
