<?php

use Falak\ControlPlane\Contracts\Exceptions\HostRequestRefused;
use Falak\ControlPlane\Contracts\HostChannel;
use Illuminate\Support\Carbon;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    config(['app.url' => 'https://panel.falak.test', 'fleet.panel_url' => null]);
    $this->dir = host_channel();
    $this->host = app(HostChannel::class);
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->dir));
    Carbon::setTestNow();
});

it('writes request.json atomically, with nothing else left in the directory', function () {
    Carbon::setTestNow('2026-10-18 12:00:00');

    $id = $this->host->requestUpdate('v0.24.0', 'user-1');

    expect($id)->toMatch('/^[0-9A-Z]{26}$/')
        ->and(scandir($this->dir))->toBe(['.', '..', 'channel.json', 'request.json'])
        ->and(json_decode((string) file_get_contents("{$this->dir}/request.json"), true))->toBe([
            'id' => $id,
            'action' => 'update',
            'version' => 'v0.24.0',
            'requested_by' => 'user-1',
            'requested_at' => '2026-10-18T12:00:00+00:00',
        ])
        ->and($this->host->state())->toMatchArray(['available' => true, 'busy' => true, 'pending' => ['id' => $id, 'action' => 'update', 'requested_at' => '2026-10-18T12:00:00+00:00', 'stale' => false]]);
});

it('writes a local-server request with the install URL', function () {
    $id = $this->host->requestLocalServer('https://panel.falak.test/install/'.str_repeat('a', 48), null);

    expect(json_decode((string) file_get_contents("{$this->dir}/request.json"), true))->toMatchArray(['id' => $id, 'action' => 'local-server', 'install_url' => 'https://panel.falak.test/install/'.str_repeat('a', 48)]);
});

it('refuses invalid input', function (Closure $call) {
    expect(fn () => $call($this->host))->toThrow(HostRequestRefused::class);
    expect(file_exists("{$this->dir}/request.json"))->toBeFalse();
})->with([
    'not a version' => [fn (HostChannel $h) => $h->requestUpdate('latest; rm -rf /', null)],
    'pre-release' => [fn (HostChannel $h) => $h->requestUpdate('v0.24.0-rc1', null)],
    'not an install URL' => [fn (HostChannel $h) => $h->requestLocalServer('https://evil.test/x.sh', null)],
    'another host' => [fn (HostChannel $h) => $h->requestLocalServer('https://evil.test/install/abcdef', null)],
    'look-alike host' => [fn (HostChannel $h) => $h->requestLocalServer('https://panel.falak.test.evil.test/install/abcdef', null)],
    'shell in the token' => [fn (HostChannel $h) => $h->requestLocalServer('https://panel.falak.test/install/abc$(id)', null)],
    'no token' => [fn (HostChannel $h) => $h->requestLocalServer('https://panel.falak.test/install/', null)],
]);

it('refuses a second request while one is pending, until it is stale', function () {
    Carbon::setTestNow('2026-10-18 12:00:00');
    $first = $this->host->requestUpdate('v0.24.0', null);

    expect(fn () => $this->host->requestUpdate('v0.24.0', null))->toThrow(HostRequestRefused::class, 'not picked up');

    Carbon::setTestNow('2026-10-18 12:16:00');
    expect($this->host->state()['pending']['stale'])->toBeTrue()->and($this->host->state()['busy'])->toBeFalse();

    $second = $this->host->requestUpdate('v0.24.0', null);
    expect($second)->not->toBe($first)
        ->and(json_decode((string) file_get_contents("{$this->dir}/request.json"), true)['id'])->toBe($second);
});

it('refuses while the host runs a request, and reads its status and log', function () {
    host_status($this->dir, ['state' => 'running']);
    expect(fn () => $this->host->requestUpdate('v0.24.0', null))->toThrow(HostRequestRefused::class, 'running another request');

    host_status($this->dir, ['state' => 'failed', 'exit_code' => 3, 'finished_at' => now()->toIso8601String()]);
    expect($this->host->state())->toMatchArray(['busy' => false, 'pending' => null])
        ->and($this->host->state()['status'])->toMatchArray(['id' => '01K7A0000000000000000000AA', 'state' => 'failed', 'exit_code' => 3]);

    $this->host->requestUpdate('v0.24.0', null);
    expect($this->host->state()['busy'])->toBeTrue();
});

it('tails the last 64 KiB of a log, from a line start, and only for request ids', function () {
    $id = '01K7A0000000000000000000AA';
    file_put_contents("{$this->dir}/{$id}.log", str_repeat("old line\n", 10000)."last line\n");

    $tail = $this->host->log($id);
    expect(strlen((string) $tail))->toBeLessThanOrEqual(65536)
        ->and($tail)->toStartWith('old line')->toEndWith("last line\n")
        ->and($this->host->log('../status'))->toBeNull()
        ->and($this->host->log('01K7A0000000000000000000ZZ'))->toBeNull();

    file_put_contents("{$this->dir}/{$id}.log", "bad \xff byte\n");
    expect(json_encode($this->host->log($id)))->not->toBeFalse();
});

it('is off without the directory', function () {
    config(['controlplane.host_channel_dir' => $this->dir.'/missing']);
    $host = app(HostChannel::class);

    expect($host->available())->toBeFalse()
        ->and($host->state())->toBe(['mounted' => false, 'available' => false, 'busy' => false, 'pending' => null, 'status' => null])
        ->and(fn () => $host->requestUpdate('v0.24.0', null))->toThrow(HostRequestRefused::class, 'not installed');
});

it('waits for falak-ctl to install its units (channel.json) after an update by an older falak-ctl', function () {
    unlink("{$this->dir}/channel.json");

    expect($this->host->available())->toBeFalse()
        ->and($this->host->state())->toMatchArray(['mounted' => true, 'available' => false])
        ->and(fn () => $this->host->requestUpdate('v0.24.0', null))->toThrow(HostRequestRefused::class, 'Run sudo falak-ctl up once on the control-plane machine');
});

it('reads why the host refused a request', function () {
    host_status($this->dir, ['id' => null, 'state' => 'failed', 'exit_code' => 2, 'error' => 'invalid id']);

    expect($this->host->state()['status'])->toMatchArray(['id' => '', 'state' => 'failed', 'error' => 'invalid id'])
        ->and($this->host->state()['busy'])->toBeFalse();
});
