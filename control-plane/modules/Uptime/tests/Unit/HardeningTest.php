<?php

use Falak\Uptime\Application\Probing\GuardedProber;
use Falak\Uptime\Application\Probing\HttpExchange;
use Falak\Uptime\Domain\Enums\ErrorKind;
use Falak\Uptime\Domain\Enums\MonitorType;
use Falak\Uptime\Domain\Probing\AddressGuard;
use Falak\Uptime\Domain\Probing\CertificateInspector;
use Falak\Uptime\Domain\Probing\ProbeTarget;
use Falak\Uptime\Infrastructure\CurlTransport;
use Falak\Uptime\Tests\Support\FakeResolver;
use Falak\Uptime\Tests\Support\FakeTransport;

require_once __DIR__.'/../Support/Fakes.php';

/*
 * Review fixes: percent-encoded hosts (SSRF), pinning by address whatever host curl derives, the transport's actual
 * peer, extra reserved ranges, the control plane's own addresses, TLS handshake deadlines.
 */

it('rejects hosts that are not plain names or IP literals, at creation and on redirects', function (string $url) {
    expect(GuardedProber::parts($url))->toBeNull();
})->with([
    'http://127%2d0%2d0%2d1.1-1-1-1.sslip.io:8765/secret.txt',
    'http://127%2e0%2e0%2e1.nip.io/',
    'http://%31%32%37.0.0.1/',
    'http://under_score.example.com/',
    'http://exa mple.com/',
    'http://ex%41mple.com/',
]);

it('keeps plain names, IPv4 and bracketed IPv6 literals', function () {
    expect(GuardedProber::parts('https://Shop.Example.com:8443/x'))->toBe(['https', 'shop.example.com', 8443])
        ->and(GuardedProber::parts('http://93.184.216.34/'))->toBe(['http', '93.184.216.34', 80])
        ->and(GuardedProber::parts('https://[2606:4700::1]/'))->toBe(['https', '[2606:4700::1]', 443])
        ->and(GuardedProber::parts('https://xn--bcher-kva.example/'))->toBe(['https', 'xn--bcher-kva.example', 443]);
});

it('never resolves nor requests a percent-encoded host, nor follows a redirect to one', function () {
    $resolver = new FakeResolver(['shop.example.com' => ['93.184.216.34']]);
    $transport = new FakeTransport(['https://shop.example.com/' => new HttpExchange(302, 'http://127%2d0%2d0%2d1.1-1-1-1.sslip.io:8765/secret.txt')]);
    $prober = new GuardedProber($resolver, $transport, new AddressGuard, new CertificateInspector);

    $direct = $prober->probe(new ProbeTarget(MonitorType::Http, url: 'http://127%2d0%2d0%2d1.1-1-1-1.sslip.io:8765/secret.txt'));
    expect($direct->ok)->toBeFalse()->and($direct->errorKind)->toBe(ErrorKind::Http);

    $redirect = $prober->probe(new ProbeTarget(MonitorType::Http, url: 'https://shop.example.com/'));
    expect($redirect->ok)->toBeFalse()->and($redirect->errorKind)->toBe(ErrorKind::Redirects)
        ->and($resolver->lookups)->toBe(['shop.example.com'])
        ->and(array_column($transport->requests, 'url'))->toBe(['https://shop.example.com/']);

    $tcp = $prober->probe(new ProbeTarget(MonitorType::Tcp, host: '127%2d0%2d0%2d1.nip.io', port: 22));
    expect($tcp->errorKind)->toBe(ErrorKind::Blocked);
});

it('treats a connection to any address but the pinned one as blocked', function () {
    $transport = new FakeTransport(['https://shop.example.com/' => new HttpExchange(200, null, 'ok', primaryIp: '127.0.0.1')]);
    $prober = new GuardedProber(new FakeResolver(['shop.example.com' => ['93.184.216.34']]), $transport, new AddressGuard, new CertificateInspector);

    $result = $prober->probe(new ProbeTarget(MonitorType::Http, url: 'https://shop.example.com/'));
    expect($result->ok)->toBeFalse()->and($result->errorKind)->toBe(ErrorKind::Blocked);

    $same = new FakeTransport(['https://shop.example.com/' => new HttpExchange(200, null, 'ok', primaryIp: '93.184.216.34')]);
    expect((new GuardedProber(new FakeResolver(['shop.example.com' => ['93.184.216.34']]), $same, new AddressGuard, new CertificateInspector))
        ->probe(new ProbeTarget(MonitorType::Http, url: 'https://shop.example.com/'))->ok)->toBeTrue();
});

it('refuses SIIT, local-use NAT64 and the Alibaba / Oracle metadata addresses, even with private targets allowed', function (string $ip) {
    expect((new AddressGuard)->allows($ip))->toBeFalse()
        ->and((new AddressGuard(true))->allows($ip))->toBeFalse();
})->with(['::ffff:0:7f00:1', '::ffff:0:808:808', '64:ff9b:1::a00:1', '100.100.100.200', '192.0.0.192']);

it('refuses the control plane\'s own addresses except for site domain monitors', function () {
    $guard = new AddressGuard(false, [], ['93.184.216.99', '2606:4700::99']);

    expect($guard->allows('93.184.216.99'))->toBeFalse()
        ->and($guard->allows('::ffff:93.184.216.99'))->toBeFalse()
        ->and($guard->allows('2606:4700:0::99'))->toBeFalse()
        ->and($guard->allows('93.184.216.99', allowSelf: true))->toBeTrue()
        ->and($guard->allows('93.184.216.98'))->toBeTrue();

    $prober = new GuardedProber(new FakeResolver(['falak.example.com' => ['93.184.216.99']]), new FakeTransport, $guard, new CertificateInspector);
    expect($prober->probe(new ProbeTarget(MonitorType::Http, url: 'https://falak.example.com/'))->errorKind)->toBe(ErrorKind::Blocked)
        ->and($prober->probe(new ProbeTarget(MonitorType::Http, url: 'https://falak.example.com/', allowSelf: true))->ok)->toBeTrue();
});

/**
 * @return array{0: resource, 1: int} a `php -S` server on 127.0.0.1 and its port
 */
function uptime_local_server(): array
{
    $root = sys_get_temp_dir().'/falak-uptime-'.bin2hex(random_bytes(4));
    mkdir($root);
    file_put_contents("{$root}/index.php", '<?php echo "pinned:".$_SERVER["HTTP_HOST"];');
    $port = random_int(20000, 40000);
    // One process (no forked workers that would outlive proc_terminate and hold the test runner's pipes).
    $env = [...getenv(), 'PHP_CLI_SERVER_WORKERS' => '1'];
    $process = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $root], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, null, $env);

    for ($i = 0; $i < 50; $i++) {
        if ($socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1)) {
            fclose($socket);

            break;
        }

        usleep(100_000);
    }

    return [$process, $port];
}

it('pins curl to the checked address whatever host it derives from the URL', function () {
    [$process, $port] = uptime_local_server();

    try {
        $transport = new CurlTransport;

        // A name that does not resolve, and one that resolves elsewhere: curl connects to the pinned address for both.
        $plain = $transport->http("http://pinned.invalid:{$port}/", '127.0.0.1', 5, 1000);
        $public = $transport->http("http://example.com:{$port}/", '127.0.0.1', 5, 1000);
        // The transport refuses an encoded host itself (defence in depth): it never connects.
        $encoded = $transport->http("http://127%2d0%2d0%2d1.pinned.invalid:{$port}/", '127.0.0.1', 5, 1000);

        expect($plain->status)->toBe(200)->and($plain->body)->toBe("pinned:pinned.invalid:{$port}")->and($plain->primaryIp)->toBe('127.0.0.1')
            ->and($public->status)->toBe(200)->and($public->body)->toBe("pinned:example.com:{$port}")->and($public->primaryIp)->toBe('127.0.0.1')
            ->and($encoded->status)->toBeNull()->and($encoded->primaryIp)->toBeNull()->and($encoded->errorKind)->toBe(ErrorKind::Http);
    } finally {
        proc_terminate($process, 9);
        proc_close($process);
    }
});

it('bounds the TLS handshake by the timeout and reports it as a timeout', function () {
    // A listener that never accepts: the TCP connection completes (backlog), the handshake never does.
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $port = (int) substr(strrchr((string) stream_socket_get_name($server, false), ':'), 1);

    $started = hrtime(true);
    $handshake = (new CurlTransport)->tls('127.0.0.1', $port, 'slow.example.com', 1);
    $elapsed = (hrtime(true) - $started) / 1e9;
    fclose($server);

    expect($handshake->ok)->toBeFalse()
        ->and($handshake->errorKind)->toBe(ErrorKind::Timeout)
        ->and($elapsed)->toBeLessThan(2.5);
});
