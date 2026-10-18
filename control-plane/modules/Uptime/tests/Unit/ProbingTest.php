<?php

use Falak\Uptime\Application\Probing\GuardedProber;
use Falak\Uptime\Application\Probing\HttpExchange;
use Falak\Uptime\Domain\Enums\ErrorKind;
use Falak\Uptime\Domain\Enums\MonitorStatus;
use Falak\Uptime\Domain\Enums\MonitorType;
use Falak\Uptime\Domain\MonitorState;
use Falak\Uptime\Domain\Probing\AddressGuard;
use Falak\Uptime\Domain\Probing\CertificateInspector;
use Falak\Uptime\Domain\Probing\ExpectedStatus;
use Falak\Uptime\Domain\Probing\ProbeTarget;
use Falak\Uptime\Domain\Probing\TlsReport;
use Falak\Uptime\Tests\Support\FakeResolver;
use Falak\Uptime\Tests\Support\FakeTransport;
use Falak\Uptime\Tests\Support\Pki;

require_once __DIR__.'/../Support/Fakes.php';
require_once __DIR__.'/../Support/Pki.php';

function uptime_prober(FakeResolver $resolver, FakeTransport $transport, bool $allowPrivate = false): GuardedProber
{
    return new GuardedProber($resolver, $transport, new AddressGuard($allowPrivate), new CertificateInspector);
}

it('refuses private, loopback, link-local, metadata and reserved addresses, mapped forms included', function (string $ip) {
    expect((new AddressGuard)->allows($ip))->toBeFalse();
})->with([
    '127.0.0.1', '10.1.2.3', '172.16.0.1', '172.31.255.255', '192.168.1.1', '100.64.0.1', '169.254.169.254', '0.0.0.0',
    '224.0.0.1', '198.18.0.1', '::1', '::', 'fe80::1', 'fd00::1', 'fc00::1', 'fd00:ec2::254', '::ffff:127.0.0.1',
    '::ffff:169.254.169.254', '64:ff9b::a00:1', '2002:7f00:1::', '::10.0.0.1', 'not-an-ip',
]);

it('allows public addresses', function (string $ip) {
    expect((new AddressGuard)->allows($ip))->toBeTrue();
})->with(['93.184.216.34', '1.1.1.1', '172.32.0.1', '2606:4700:4700::1111', '::ffff:8.8.8.8']);

it('keeps metadata and link-local refused when private targets are allowed, and honours extra ranges', function () {
    $guard = new AddressGuard(true, ['203.0.114.0/24']);

    expect($guard->allows('10.0.0.5'))->toBeTrue()
        ->and($guard->allows('127.0.0.1'))->toBeTrue()
        ->and($guard->allows('169.254.169.254'))->toBeFalse()
        ->and($guard->allows('203.0.114.9'))->toBeFalse()
        ->and($guard->allowsHost('db.internal'))->toBeTrue()
        ->and((new AddressGuard)->allowsHost('db.internal'))->toBeFalse()
        ->and((new AddressGuard)->allowsHost('localhost'))->toBeFalse()
        ->and((new AddressGuard)->allowsHost('intranet'))->toBeFalse()
        ->and((new AddressGuard)->allowsHost('shop.example.com'))->toBeTrue();
});

it('refuses a host when any of its addresses is private', function () {
    expect((new AddressGuard)->pick(['93.184.216.34', '10.0.0.1']))->toBeNull()
        ->and((new AddressGuard)->pick(['93.184.216.34', '2606:4700::1']))->toBe('93.184.216.34')
        ->and((new AddressGuard)->pick([]))->toBeNull();
});

it('parses expected statuses', function () {
    $expected = ExpectedStatus::parse('200, 3xx,404');

    expect($expected->matches(200))->toBeTrue()
        ->and($expected->matches(301))->toBeTrue()
        ->and($expected->matches(404))->toBeTrue()
        ->and($expected->matches(201))->toBeFalse()
        ->and($expected->toString())->toBe('200,3xx,404')
        ->and(ExpectedStatus::parse(null)->toString())->toBe('2xx,3xx')
        ->and(ExpectedStatus::isValid('2xx,600'))->toBeFalse()
        ->and(ExpectedStatus::isValid('abc'))->toBeFalse();
});

it('moves through up, down and recovery', function () {
    $state = new MonitorState(MonitorStatus::Pending, false, 0, 0);

    [$state, $t] = $state->next(true, false, 2, 2);
    expect($state->status)->toBe(MonitorStatus::Up)->and($state->armed)->toBeTrue()->and($t)->toBe(MonitorState::ARMED);

    [$state, $t] = $state->next(false, false, 2, 2);
    expect($state->status)->toBe(MonitorStatus::Up)->and($t)->toBeNull()->and($state->failures)->toBe(1);

    // A success in between resets the streak.
    [$state] = $state->next(true, false, 2, 2);
    [$state] = $state->next(false, false, 2, 2);
    [$state, $t] = $state->next(false, false, 2, 2);
    expect($state->status)->toBe(MonitorStatus::Down)->and($t)->toBe(MonitorState::OPENED);

    [$state, $t] = $state->next(false, false, 2, 2);
    expect($t)->toBeNull()->and($state->status)->toBe(MonitorStatus::Down);

    [$state, $t] = $state->next(true, false, 2, 2);
    expect($t)->toBeNull()->and($state->status)->toBe(MonitorStatus::Down);
    [$state, $t] = $state->next(false, false, 2, 2);
    [$state] = $state->next(true, false, 2, 2);
    [$state, $t] = $state->next(true, false, 2, 2);
    expect($state->status)->toBe(MonitorStatus::Up)->and($t)->toBe(MonitorState::CLOSED);
});

it('keeps an unarmed automatic monitor pending, but lets a user monitor go down', function () {
    $auto = new MonitorState(MonitorStatus::Pending, false, 0, 0);
    [$auto] = $auto->next(false, true, 2, 2);
    [$auto, $t] = $auto->next(false, true, 2, 2);
    expect($auto->status)->toBe(MonitorStatus::Pending)->and($t)->toBeNull();

    $user = new MonitorState(MonitorStatus::Pending, false, 0, 0);
    [$user] = $user->next(false, false, 2, 2);
    [$user, $t] = $user->next(false, false, 2, 2);
    expect($user->status)->toBe(MonitorStatus::Down)->and($t)->toBe(MonitorState::OPENED);
});

it('pins the connection to the address it checked (DNS rebinding)', function () {
    // The second lookup would answer a private address: it never happens, the transport gets the checked IP.
    $resolver = new FakeResolver(['shop.example.com' => [['93.184.216.34'], ['127.0.0.1']]]);
    $transport = new FakeTransport;

    $result = uptime_prober($resolver, $transport)->probe(new ProbeTarget(MonitorType::Http, url: 'https://shop.example.com/'));

    expect($result->ok)->toBeTrue()
        ->and($result->ip)->toBe('93.184.216.34')
        ->and($resolver->lookups)->toBe(['shop.example.com'])
        ->and($transport->requests)->toBe([['url' => 'https://shop.example.com/', 'ip' => '93.184.216.34']]);
});

it('refuses targets that resolve to private addresses and never connects', function () {
    $transport = new FakeTransport;
    $prober = uptime_prober(new FakeResolver(['evil.example.com' => ['169.254.169.254'], 'mixed.example.com' => ['93.184.216.34', '10.0.0.8']]), $transport);

    foreach (['https://evil.example.com/', 'https://mixed.example.com/', 'http://127.0.0.1:8080/', 'http://[::1]/', 'http://localhost/', 'http://metadata.internal/'] as $url) {
        $result = $prober->probe(new ProbeTarget(MonitorType::Http, url: $url));
        expect($result->ok)->toBeFalse()->and($result->errorKind)->toBe(ErrorKind::Blocked);
    }

    expect($transport->requests)->toBe([]);
});

it('re-checks every redirect hop', function () {
    $transport = new FakeTransport([
        'https://shop.example.com/' => new HttpExchange(301, 'https://www.example.com/start'),
        'https://www.example.com/start' => new HttpExchange(302, '/home'),
        'https://www.example.com/home' => new HttpExchange(200, null, 'Welcome'),
        'https://bad.example.com/' => new HttpExchange(302, 'http://internal.example.net/'),
    ]);
    $prober = uptime_prober(new FakeResolver([
        'shop.example.com' => ['93.184.216.34'],
        'www.example.com' => ['93.184.216.35'],
        'bad.example.com' => ['93.184.216.36'],
        'internal.example.net' => ['192.168.0.10'],
    ]), $transport);

    $ok = $prober->probe(new ProbeTarget(MonitorType::Http, url: 'https://shop.example.com/', keyword: 'welcome'));
    expect($ok->ok)->toBeTrue()->and($ok->redirects)->toBe(2)->and($ok->ip)->toBe('93.184.216.35');

    $blocked = $prober->probe(new ProbeTarget(MonitorType::Http, url: 'https://bad.example.com/'));
    expect($blocked->ok)->toBeFalse()->and($blocked->errorKind)->toBe(ErrorKind::Blocked)
        ->and(array_column($transport->requests, 'url'))->not->toContain('http://internal.example.net/');

    $limited = $prober->probe(new ProbeTarget(MonitorType::Http, url: 'https://shop.example.com/', maxRedirects: 1));
    expect($limited->errorKind)->toBe(ErrorKind::Redirects);

    // No redirects followed: the 301 itself is the answer (3xx accepted by default).
    expect($prober->probe(new ProbeTarget(MonitorType::Http, url: 'https://shop.example.com/', maxRedirects: 0))->statusCode)->toBe(301);
});

it('checks status and keyword', function () {
    $transport = new FakeTransport([
        'https://a.example.com/' => new HttpExchange(503, null, 'Maintenance'),
        'https://b.example.com/' => new HttpExchange(200, null, 'Fatal error: something'),
    ]);
    $prober = uptime_prober(new FakeResolver(['a.example.com' => ['93.184.216.34'], 'b.example.com' => ['93.184.216.34']]), $transport);

    $status = $prober->probe(new ProbeTarget(MonitorType::Http, url: 'https://a.example.com/'));
    expect($status->errorKind)->toBe(ErrorKind::Status)->and($status->statusCode)->toBe(503);
    expect($prober->probe(new ProbeTarget(MonitorType::Http, url: 'https://a.example.com/', expectedStatus: '503'))->ok)->toBeTrue();

    expect($prober->probe(new ProbeTarget(MonitorType::Http, url: 'https://b.example.com/', keyword: 'Fatal error', keywordAbsent: true))->errorKind)->toBe(ErrorKind::Keyword)
        ->and($prober->probe(new ProbeTarget(MonitorType::Http, url: 'https://b.example.com/', keyword: 'Welcome'))->errorKind)->toBe(ErrorKind::Keyword)
        ->and($prober->probe(new ProbeTarget(MonitorType::Http, url: 'https://b.example.com/', keyword: 'something'))->ok)->toBeTrue();
});

it('reports DNS failures and transport errors', function () {
    $transport = new FakeTransport(['https://slow.example.com/' => new HttpExchange(errorKind: ErrorKind::Timeout, error: 'Operation timed out')]);
    $prober = uptime_prober(new FakeResolver(['slow.example.com' => ['93.184.216.34']]), $transport);

    expect($prober->probe(new ProbeTarget(MonitorType::Http, url: 'https://gone.example.com/'))->errorKind)->toBe(ErrorKind::Dns)
        ->and($prober->probe(new ProbeTarget(MonitorType::Http, url: 'https://slow.example.com/'))->errorKind)->toBe(ErrorKind::Timeout)
        ->and($prober->probe(new ProbeTarget(MonitorType::Http, url: 'ftp://x.example.com/'))->errorKind)->toBe(ErrorKind::Http)
        ->and($prober->probe(new ProbeTarget(MonitorType::Http, url: 'https://user:pw@x.example.com/'))->errorKind)->toBe(ErrorKind::Http);
});

it('probes tcp ports and tls handshakes through the guard', function () {
    $pki = new Pki;
    $transport = new FakeTransport(chain: [$pki->leaf(['mail.example.com'])]);
    $prober = new GuardedProber(new FakeResolver(['mail.example.com' => ['93.184.216.34'], 'db.example.com' => ['10.0.0.4']]), $transport, new AddressGuard, new CertificateInspector, $pki->caFile);

    expect($prober->probe(new ProbeTarget(MonitorType::Tcp, host: 'mail.example.com', port: 25))->ok)->toBeTrue()
        ->and($prober->probe(new ProbeTarget(MonitorType::Tcp, host: 'db.example.com', port: 5432))->errorKind)->toBe(ErrorKind::Blocked);

    $tls = $prober->probe(new ProbeTarget(MonitorType::Tls, host: 'mail.example.com', port: 465));
    expect($tls->ok)->toBeTrue()
        ->and($tls->tls?->problem)->toBe(TlsReport::INTERMEDIATE_MISSING)
        ->and($transport->handshakes[0])->toBe(['ip' => '93.184.216.34', 'port' => 465, 'sni' => 'mail.example.com']);
});

it('judges certificate chains', function () {
    $pki = new Pki;
    $inspector = new CertificateInspector;
    $leaf = $pki->leaf(['shop.example.com', '*.example.org']);
    // Certificates are issued before $now: one made after it can read as not yet valid when a second ticks over.
    $selfSigned = $pki->leaf(['x.example.com'], 90, true);
    $now = new DateTimeImmutable;
    $chain = [$leaf, $pki->intermediatePem()];

    $ok = $inspector->inspect($chain, 'shop.example.com', $pki->caFile, $now);
    expect($ok->problem)->toBeNull()->and($ok->chainTrusted)->toBeTrue()->and($ok->issuer)->toBe('Falak Test Intermediate')
        ->and($ok->daysLeft($now))->toBeGreaterThanOrEqual(89);

    expect($inspector->inspect($chain, 'a.example.org', $pki->caFile, $now)->problem)->toBeNull()
        ->and($inspector->inspect($chain, 'a.b.example.org', $pki->caFile, $now)->problem)->toBe(TlsReport::HOSTNAME_MISMATCH)
        ->and($inspector->inspect($chain, 'other.example.com', $pki->caFile, $now)->problem)->toBe(TlsReport::HOSTNAME_MISMATCH)
        ->and($inspector->inspect([$leaf], 'shop.example.com', $pki->caFile, $now)->problem)->toBe(TlsReport::INTERMEDIATE_MISSING)
        ->and($inspector->inspect([$selfSigned], 'x.example.com', $pki->caFile, $now)->problem)->toBe(TlsReport::SELF_SIGNED)
        ->and($inspector->inspect($chain, 'shop.example.com', $pki->caFile, $now->modify('+400 days'))->problem)->toBe(TlsReport::EXPIRED)
        ->and($inspector->inspect([], 'shop.example.com', $pki->caFile, $now)->problem)->toBe(TlsReport::NO_CERTIFICATE);

    // Another CA's bundle: presented in full but not trusted.
    expect($inspector->inspect($chain, 'shop.example.com', (new Pki)->caFile, $now)->problem)->toBe(TlsReport::UNTRUSTED);
});

it('resolves redirect targets', function () {
    expect(GuardedProber::follow('https://a.example.com/x/y?q=1', '/z'))->toBe('https://a.example.com/z')
        ->and(GuardedProber::follow('https://a.example.com/x/y', 'z'))->toBe('https://a.example.com/x/z')
        ->and(GuardedProber::follow('https://a.example.com:8443/', 'b'))->toBe('https://a.example.com:8443/b')
        ->and(GuardedProber::follow('https://a.example.com/', '//b.example.com/c'))->toBe('https://b.example.com/c')
        ->and(GuardedProber::follow('https://a.example.com/', 'http://b.example.com/'))->toBe('http://b.example.com/');
});
