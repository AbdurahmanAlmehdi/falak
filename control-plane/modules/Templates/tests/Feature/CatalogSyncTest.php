<?php

use Falak\Templates\Application\Catalog\Catalog;
use Falak\Templates\Application\Catalog\CatalogLocation;
use Falak\Templates\Application\Catalog\CatalogSync;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Support/helpers.php';

const SYNC_URL = 'https://github.com/falak-sh/templates/releases/latest/download';

/** One tar entry (ustar header + padded body): lets a test build archives PharData itself would never write. */
function sync_tar_entry(string $name, string $body, string $type = '0', string $link = ''): string
{
    $header = str_pad($name, 100, "\0").str_pad('0000644', 8, "\0").str_pad('0000000', 8, "\0").str_pad('0000000', 8, "\0")
        .str_pad(decoct(strlen($body)), 11, '0', STR_PAD_LEFT)."\0".str_pad(decoct(time()), 11, '0', STR_PAD_LEFT)."\0"
        .'        '.$type.str_pad($link, 100, "\0")."ustar\00000".str_repeat("\0", 247);
    $sum = array_sum(array_map('ord', str_split($header)));

    return substr_replace($header, str_pad(decoct($sum), 6, '0', STR_PAD_LEFT)."\0 ", 148, 8).str_pad($body, (int) ceil(strlen($body) / 512) * 512, "\0");
}

/** @param array<string, string> $files path => contents */
function sync_archive(array $files, string $extra = ''): string
{
    $tar = implode('', array_map(fn ($path, $body) => sync_tar_entry($path, $body), array_keys($files), $files));

    return gzencode($tar.$extra.str_repeat("\0", 1024));
}

/** A published catalog: a release with catalog.json + catalog.tar.gz holding the given template slugs. */
function sync_release(string $version, array $slugs = ['hello'], ?string $archive = null): void
{
    $files = ['VERSION' => "{$version}\n"];
    foreach ($slugs as $slug) {
        $files["{$slug}/template.yaml"] = str_replace('slug: hello', "slug: {$slug}", TEMPLATES_FIXTURE_TEMPLATE);
        $files["{$slug}/compose.yaml"] = TEMPLATES_FIXTURE_COMPOSE;
    }
    $archive ??= sync_archive($files);

    sync_fake([
        SYNC_URL.'/catalog.json' => Http::response(['version' => $version, 'sha256' => hash('sha256', $archive), 'generated_at' => '2026-10-20T12:00:00Z']),
        SYNC_URL.'/catalog.tar.gz' => Http::response($archive),
    ]);
}

/** Http::fake stacks (the first match wins): start over, the way a later hour's request would see the remote. */
function sync_fake(array $stubs): void
{
    Http::swap(new Factory);
    Http::fake($stubs);
}

function sync_slugs(): array
{
    return array_map(fn ($t) => $t->slug, app(Catalog::class)->all());
}

beforeEach(function () {
    $this->synced = sys_get_temp_dir().'/falak-synced-'.bin2hex(random_bytes(6));
    $baked = templates_fixture_catalog(['baked' => [str_replace('slug: hello', 'slug: baked', TEMPLATES_FIXTURE_TEMPLATE), TEMPLATES_FIXTURE_COMPOSE]]);
    file_put_contents("{$baked}/VERSION", "2026.10.01.1\n");
    $this->baked = $baked;
    config(['templates.synced_path' => $this->synced, 'templates.remote_url' => SYNC_URL]);
});

it('downloads a new catalog and serves it, also to a catalog that is already loaded', function () {
    expect(sync_slugs())->toBe(['baked']);
    sync_release('2026.10.20.3', ['hello', 'other']);

    expect(app(CatalogSync::class)->sync())->toBe('updated:2026.10.20.3')
        ->and(sync_slugs())->toEqualCanonicalizing(['hello', 'other'])
        ->and(app(CatalogLocation::class)->current())->toMatchArray(['version' => '2026.10.20.3', 'source' => 'remote']);
});

it('reports an unchanged catalog without downloading it again', function () {
    sync_release('2026.10.20.3');
    app(CatalogSync::class)->sync();

    expect(app(CatalogSync::class)->sync())->toBe('unchanged');
    Http::assertSentCount(3); // json + archive, then only json
});

it('keeps the last good catalog when a download is bad', function (string $case) {
    sync_release('2026.10.20.3');
    app(CatalogSync::class)->sync();

    if ($case === 'an archive over the size limit') {
        config(['templates.max_archive_bytes' => 10]);
    }

    if ($case === 'an archive that unpacks too large') {
        config(['templates.max_extracted_bytes' => 4096]);
    }

    match ($case) {
        'a wrong checksum' => sync_fake([
            SYNC_URL.'/catalog.json' => Http::response(['version' => '2026.10.21.1', 'sha256' => str_repeat('a', 64)]),
            SYNC_URL.'/catalog.tar.gz' => Http::response(sync_archive(['VERSION' => 'x', 'evil/template.yaml' => 'x'])),
        ]),
        'no release (404)' => sync_fake([SYNC_URL.'/*' => Http::response('Not Found', 404)]),
        'an HTML page' => sync_fake([SYNC_URL.'/catalog.json' => Http::response('<html>rate limited</html>')]),
        'a version that is not a version' => sync_fake([SYNC_URL.'/catalog.json' => Http::response(['version' => '../../etc', 'sha256' => str_repeat('a', 64)])]),
        'an archive over the size limit' => sync_release('2026.10.21.1'),
        'no connection' => sync_fake([SYNC_URL.'/*' => fn () => throw new ConnectionException('Could not resolve host: github.com')]),
        'an archive that unpacks too large' => sync_release('2026.10.21.1', archive: sync_archive(['VERSION' => '2026.10.21.1', 'big/template.yaml' => str_repeat("\n", 64 * 1024)])),
        'an archive without templates' => sync_release('2026.10.21.1', archive: sync_archive(['VERSION' => '2026.10.21.1', 'README.md' => 'hi'])),
    };

    expect(fn () => app(CatalogSync::class)->sync())->toThrow(RuntimeException::class)
        ->and(sync_slugs())->toBe(['hello'])
        ->and(app(CatalogLocation::class)->current()['version'])->toBe('2026.10.20.3');
})->with(['a wrong checksum', 'no release (404)', 'an HTML page', 'a version that is not a version', 'an archive over the size limit', 'no connection', 'an archive that unpacks too large', 'an archive without templates']);

it('never writes outside its directory, whatever the archive names', function () {
    $outside = dirname($this->synced).'/falak-escape-'.bin2hex(random_bytes(4));
    $archive = sync_archive(['hello/template.yaml' => TEMPLATES_FIXTURE_TEMPLATE, 'hello/compose.yaml' => TEMPLATES_FIXTURE_COMPOSE],
        sync_tar_entry('../'.basename($outside).'/x', 'x').sync_tar_entry($outside.'/y', 'y').sync_tar_entry('hello/icon.svg', '', '2', '/etc/passwd'));
    sync_release('2026.10.20.3', archive: $archive);

    app(CatalogSync::class)->sync();

    expect(file_exists($outside))->toBeFalse()
        ->and(is_link("{$this->synced}/2026.10.20.3/hello/icon.svg"))->toBeFalse();
});

it('prefers a newer baked catalog over an older sync (an upgraded image)', function () {
    sync_release('2026.10.20.3');
    app(CatalogSync::class)->sync();
    file_put_contents("{$this->baked}/VERSION", "2026.11.01.1\n");

    expect(app(CatalogLocation::class)->current())->toMatchArray(['source' => 'built-in', 'version' => '2026.11.01.1'])
        ->and(sync_slugs())->toBe(['baked']);
});

it('drops the synced copy when FALAK_TEMPLATES_URL changes or is cleared', function () {
    sync_release('2026.10.20.3');
    app(CatalogSync::class)->sync();

    config(['templates.remote_url' => 'https://example.com/fork']);
    expect(app(CatalogLocation::class)->current()['source'])->toBe('built-in');

    config(['templates.remote_url' => '']);
    expect(app(CatalogSync::class)->sync())->toBe('disabled')
        ->and(glob("{$this->synced}/*"))->toBe([])
        ->and(sync_slugs())->toBe(['baked']);
});

it('runs as templates:sync, failing loudly on a bad download', function () {
    sync_release('2026.10.20.3');
    $this->artisan('templates:sync')->expectsOutputToContain('updated:2026.10.20.3')->assertExitCode(0);

    sync_fake([SYNC_URL.'/*' => Http::response('nope', 500)]);
    $this->artisan('templates:sync')->assertExitCode(1);
});
