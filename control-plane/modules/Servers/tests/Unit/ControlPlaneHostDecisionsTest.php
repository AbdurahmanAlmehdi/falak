<?php

use Falak\Fleet\Infrastructure\ProtocolSchemas;
use Falak\Servers\Application\MachineChecks;
use Falak\Servers\Contracts\ServerType;
use Falak\Servers\Domain\MachineCheck\Decision;
use Falak\Servers\Domain\MachineCheck\Wanted;
use Falak\Servers\Domain\Models\Server;
use Falak\Servers\Domain\Stack\Stack;
use Falak\Servers\Infrastructure\ProvisioningPlanBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../Support/machine_reports.php';

uses(RefreshDatabase::class);

/** The control plane's machine: its edge container publishes 80/443, Docker from Docker's repository runs it. */
function cp_report(): array
{
    $report = mc_docker_ce(mc_report(['hostname' => 'falak-cp']));
    $report['containers'][] = ['name' => 'falak-edge-1', 'image' => 'ghcr.io/falak-sh/falak-edge:v0.23.0', 'ports' => [['host_port' => 80, 'proto' => 'tcp'], ['host_port' => 443, 'proto' => 'tcp']]];
    $report = mc_listen($report, 80, 'docker-proxy', 'docker.service', container: true);

    return mc_listen($report, 443, 'docker-proxy', 'docker.service', container: true);
}

function cp_wanted(bool $localEdge = true, bool $controlPlaneHost = true): Wanted
{
    $stack = new Stack('frankenphp', ['8.4'], '8.4', null);

    return new Wanted(stack: $stack, servesHttp: true, customServer: true, hostname: 'this-machine', sshPort: 2222, swapMb: 2048,
        phpVersions: ['8.4'], basePackages: [], controlPlaneHost: $controlPlaneHost, localEdge: $localEdge);
}

it('skips the 80/443 check on the control plane\'s machine once the agent runs its local edge', function () {
    $edge = mc_decide(cp_report(), cp_wanted())->for('edge');

    expect($edge->decision)->toBe(Decision::Install)
        ->and($edge->reason)->toContain('8080 and 8443')
        ->and($edge->notes)->toBe([]);

    // The same machine as a normal server: the edge container's ports block.
    expect(mc_decide(cp_report(), cp_wanted(controlPlaneHost: false))->for('edge')->decision)->toBe(Decision::Block);
});

it('blocks the edge until falak-ctl local-server enable set up the local edge', function () {
    $edge = mc_decide(cp_report(), cp_wanted(localEdge: false))->for('edge');

    expect($edge->decision)->toBe(Decision::Block)
        ->and($edge->hint())->toBe('Run sudo falak-ctl local-server enable on the host, then re-check.');
});

it('still blocks a foreign process on the local edge ports', function () {
    $edge = mc_decide(mc_listen(cp_report(), 8443, 'nginx', 'nginx.service'), cp_wanted())->for('edge');

    expect($edge->decision)->toBe(Decision::Block)->and($edge->reason)->toContain('Port 8443 is in use by nginx');
});

it('never replaces Docker on the control plane\'s machine', function () {
    $report = cp_report();
    $report['docker']['server_version'] = '20.10.0';

    expect(mc_decide($report, cp_wanted(controlPlaneHost: false))->for('docker')->decision)->toBe(Decision::Install);

    $docker = mc_decide($report, cp_wanted())->for('docker');
    expect($docker->decision)->toBe(Decision::Block)
        ->and($docker->reason)->toContain('would replace Docker')
        ->and($docker->install)->toBe([]);

    // An adoptable Docker is adopted as anywhere else.
    expect(mc_decide(cp_report(), cp_wanted())->for('docker')->decision)->toBe(Decision::Adopt);
});

it('keeps SSH, swap and the hostname of the control plane\'s machine', function () {
    // Password login on and port 22 (Falak would move it to 2222): both kept.
    $check = mc_decide(cp_report(), cp_wanted());

    foreach (['ssh', 'swap', 'hostname'] as $component) {
        expect($check->for($component)->decision)->toBe(Decision::Adopt)
            ->and($check->for($component)->reason)->toBe('Kept (control-plane machine).')
            ->and($check->for($component)->notes)->toBe([]);
    }

    $normal = mc_decide(cp_report(), cp_wanted(controlPlaneHost: false));
    expect($normal->for('ssh')->decision)->toBe(Decision::Install)
        ->and($normal->for('swap')->decision)->toBe(Decision::Install);
});

it('leaves SSH, swap and the hostname out of the control plane machine\'s plan', function () {
    $server = Server::factory()->type(ServerType::App)->create(['name' => 'This machine', 'provider' => 'custom', 'memory_bytes' => 2 * 1024 ** 3, 'control_plane_host' => true])->refresh();
    $check = app(MachineChecks::class)->decide($server, cp_report());
    $plan = app(ProvisioningPlanBuilder::class)->build($server, $check);

    expect($plan)->not->toHaveKeys(['ssh', 'swap_mb', 'hostname'])
        ->and($plan['docker'])->toBe(['live_restore' => true])
        ->and(app(ProtocolSchemas::class)->validateCommand('provision.apply', ProtocolSchemas::toJson($plan)))->toBe([])
        // No local_edge fact yet: the edge blocks.
        ->and($check->for('edge')->decision)->toBe(Decision::Block);
});
