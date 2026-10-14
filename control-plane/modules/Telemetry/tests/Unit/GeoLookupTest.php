<?php

use Falak\Telemetry\Infrastructure\Geo\GeoDatabases;
use Falak\Telemetry\Infrastructure\Geo\MaxMindReader;
use Falak\Telemetry\Tests\Support\MmdbWriter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/falak-geoip-'.bin2hex(random_bytes(4));
    mkdir($this->dir);
    config(['telemetry.geoip.path' => $this->dir, 'telemetry.geoip.enabled' => true]);
});

afterEach(function () {
    array_map('unlink', glob($this->dir.'/*') ?: []);
    rmdir($this->dir);
});

function geo_country_db(string $path, int $recordSize = 24): void
{
    (new MmdbWriter($recordSize))
        ->add('1.2.3.0/24', ['country' => ['iso_code' => 'AU', 'names' => ['en' => 'Australia', 'de' => 'Australien']], 'continent' => ['code' => 'OC']])
        ->add('8.8.8.0/24', ['country' => ['iso_code' => 'US', 'names' => ['en' => 'United States']]])
        ->add('2a00:1450::/32', ['country' => ['iso_code' => 'IE', 'names' => ['en' => 'Ireland']]])
        ->write($path, 'DBIP-Country-Lite');
}

it('reads records of a MaxMind DB for every record size', function (int $size) {
    geo_country_db($this->dir.'/t.mmdb', $size);
    $reader = new MaxMindReader($this->dir.'/t.mmdb');

    expect($reader->metadata()['database_type'])->toBe('DBIP-Country-Lite')
        ->and($reader->get('1.2.3.4')['country']['iso_code'])->toBe('AU')
        ->and($reader->get('1.2.3.4')['country']['names']['de'])->toBe('Australien')
        ->and($reader->get('8.8.8.8')['country']['names']['en'])->toBe('United States')
        ->and($reader->get('2a00:1450:4001::1')['country']['iso_code'])->toBe('IE')
        ->and($reader->get('9.9.9.9'))->toBeNull()
        ->and($reader->get('not an ip'))->toBeNull();
})->with([24, 28, 32]);

it('refuses files that are not MaxMind DBs', function () {
    file_put_contents($this->dir.'/bad.mmdb', 'garbage');

    expect(fn () => new MaxMindReader($this->dir.'/bad.mmdb'))->toThrow(RuntimeException::class);
});

it('looks up countries and networks, and answers nothing without databases', function () {
    $geo = new GeoDatabases;
    expect($geo->lookup(['1.2.3.4'])['1.2.3.4'])->toBe(['country' => null, 'country_name' => null, 'asn' => null, 'as_org' => null])
        ->and($geo->status()['country'])->toBeFalse();

    geo_country_db($this->dir.'/country.mmdb');
    (new MmdbWriter)->add('1.2.3.0/24', ['autonomous_system_number' => 13335, 'autonomous_system_organization' => 'Cloudflare, Inc.'])
        ->write($this->dir.'/asn.mmdb', 'DBIP-ASN-Lite');

    $geo = new GeoDatabases;
    expect($geo->lookup(['1.2.3.4', '8.8.8.8']))->toBe([
        '1.2.3.4' => ['country' => 'AU', 'country_name' => 'Australia', 'asn' => 13335, 'as_org' => 'Cloudflare, Inc.'],
        '8.8.8.8' => ['country' => 'US', 'country_name' => 'United States', 'asn' => null, 'as_org' => null],
    ])->and($geo->status())->toMatchArray(['country' => true, 'asn' => true, 'attribution' => 'IP geolocation by DB-IP']);

    // Disabled: nothing is looked up.
    config(['telemetry.geoip.enabled' => false]);
    expect((new GeoDatabases)->lookup(['1.2.3.4'])['1.2.3.4']['country'])->toBeNull();

    // A corrupt file answers nothing and fails nothing.
    config(['telemetry.geoip.enabled' => true]);
    file_put_contents($this->dir.'/country.mmdb', 'corrupt');
    expect((new GeoDatabases)->lookup(['1.2.3.4'])['1.2.3.4']['country'])->toBeNull();
});

it('downloads the month\'s database, falls back to the previous month and keeps the old file on failure', function () {
    $this->travelTo('2026-10-03 10:00:00');
    geo_country_db($this->dir.'/src.mmdb');
    $gz = gzencode((string) file_get_contents($this->dir.'/src.mmdb'));
    config(['telemetry.geoip.country_url' => 'https://dl.test/country-{Y}-{m}.mmdb.gz']);

    Http::fake([
        'dl.test/country-2026-10.mmdb.gz' => Http::response('', 404),
        'dl.test/country-2026-09.mmdb.gz' => Http::response($gz),
    ]);

    $geo = new GeoDatabases;
    expect($geo->stale('country'))->toBeTrue();
    $geo->download('country');
    expect($geo->stale('country'))->toBeFalse()
        ->and($geo->lookup(['8.8.8.8'])['8.8.8.8']['country'])->toBe('US');
    Http::assertSent(fn (Request $r) => $r->url() === 'https://dl.test/country-2026-10.mmdb.gz');

    // Both months broken: the old file stays.
    config(['telemetry.geoip.country_url' => 'https://broken.test/country-{Y}-{m}.mmdb.gz']);
    Http::fake(['broken.test/*' => Http::response(gzencode('not a database'))]);
    expect(fn () => (new GeoDatabases)->download('country'))->toThrow(RuntimeException::class, 'not downloaded');
    expect((new GeoDatabases)->lookup(['8.8.8.8'])['8.8.8.8']['country'])->toBe('US')
        ->and(file_exists($this->dir.'/country.mmdb.tmp'))->toBeFalse();
});
