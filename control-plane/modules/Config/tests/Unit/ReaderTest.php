<?php

use Falak\Config\Application\Reading\ConfigReader;
use Falak\Config\Application\Validation\FileCheck;
use Falak\Config\Tests\Support\ConfigCases;

// The module's reader (src) follows the same rules as the shared cases.

test('the reader reads every shared case like the contract', function (string $kind, string $path) {
    $format = pathinfo($path, PATHINFO_EXTENSION) === 'json' ? 'json' : 'yaml';
    $file = app(ConfigReader::class)->read((string) file_get_contents($path), $format);
    [$doc, $error] = ConfigCases::parse((string) file_get_contents($path), $format);

    expect($file->error?->code)->toBe($error['code'] ?? null);

    if ($error === null) {
        expect(ConfigReader::plain($file->document))->toEqual(ConfigCases::plain($doc));
    }
})->with(function () {
    foreach (['valid', 'invalid', 'parse', 'resolve'] as $kind) {
        foreach (ConfigCases::inputs($kind) as $name => $path) {
            yield "{$kind}/{$name}" => [$kind, $path];
        }
    }
});

$yaml = <<<'YAML'
    $schema: https://falak.sh/schema/v1.json   # the schema
    version: 1
    units:
      web:
        type: app
        runtime: frankenphp
        php: "8.4"
        deploy:
          strategy: rollin
          health: { path: /up, timeout: 10s }
        domains:
          - shop.example.com
          - name: "www.shop.example.com"
            www: none
        workers:
          queue: { command: php artisan queue:work, processes: 2 }
        hooks_typo: true
        env:
          NOTE: |
            a block
            scalar
          AFTER: ok
    environments:
      production: { branch: main, servers: [web-1] }
    YAML;

test('yaml positions: block maps, flow maps, sequences, block scalars', function () use ($yaml) {
    $positions = app(ConfigReader::class)->read($yaml)->positions;

    expect($positions->find('/version'))->toBe(['line' => 2, 'column' => 10])
        ->and($positions->find('/units/web/deploy/strategy'))->toBe(['line' => 9, 'column' => 17])
        ->and($positions->find('/units/web/deploy/health/timeout'))->toBe(['line' => 10, 'column' => 37])
        ->and($positions->find('/units/web/domains/0'))->toBe(['line' => 12, 'column' => 9])
        ->and($positions->find('/units/web/domains/1/name'))->toBe(['line' => 13, 'column' => 15])
        ->and($positions->find('/units/web/domains/1/www'))->toBe(['line' => 14, 'column' => 14])
        ->and($positions->find('/units/web/workers/queue/processes'))->toBe(['line' => 16, 'column' => 60])
        ->and($positions->find('/units/web/hooks_typo', true))->toBe(['line' => 17, 'column' => 5])
        ->and($positions->find('/units/web/env/AFTER'))->toBe(['line' => 22, 'column' => 14])
        ->and($positions->find('/environments/production/servers/0'))->toBe(['line' => 24, 'column' => 41])
        ->and($positions->find('/units/nope'))->toBeNull();
});

test('json positions', function () {
    $json = "{\n  \"version\": 1,\n  \"units\": {\n    \"web\": {\"type\": \"app\", \"runtime\": \"node\"}\n  }\n}\n";
    $positions = app(ConfigReader::class)->read($json, 'json')->positions;

    expect($positions->find('/version'))->toBe(['line' => 2, 'column' => 14])
        ->and($positions->find('/units/web/runtime'))->toBe(['line' => 4, 'column' => 39])
        ->and($positions->find('/units/web/runtime', true))->toBe(['line' => 4, 'column' => 28]);
});

test('schema issues carry the line and column of the value, unknown keys of the key', function () use ($yaml) {
    $validation = app(FileCheck::class)->check($yaml);
    $strategy = collect($validation->errors)->firstWhere('pointer', '/units/web/deploy/strategy');

    expect($validation->valid())->toBeFalse()
        ->and($strategy?->code)->toBe('schema')
        ->and([$strategy?->line, $strategy?->column])->toBe([9, 17]);

    $unknown = app(FileCheck::class)->check(str_replace('strategy: rollin', 'strategy: rolling', $yaml));
    $web = collect($unknown->errors)->firstWhere('pointer', '/units/web');

    expect($web)->not->toBeNull()
        ->and([$web->line, $web->column])->toBe([17, 5])
        ->and($web->message)->toContain('hooks_typo');
});

test('resolve issues point at their source in the file', function () {
    $validation = app(FileCheck::class)->check(<<<'YAML'
        version: 1
        units:
          web:
            type: app
            runtime: node
        environments:
          production:
            branch: main
            units:
              web:
                deploy:
                  hooks:
                    after_activate: echo ${{ secrets.X }}
        YAML, 'yaml', 'production');

    expect($validation->errors)->toHaveCount(1)
        ->and($validation->errors[0]->code)->toBe('reference.not_allowed')
        ->and($validation->errors[0]->pointer)->toBe('/units/web/deploy/hooks/after_activate')
        ->and($validation->errors[0]->source)->toBe('/environments/production/units/web/deploy/hooks/after_activate')
        ->and([$validation->errors[0]->line, $validation->errors[0]->column])->toBe([13, 29]);
});

test('parse errors: duplicate keys and dates', function () {
    $dup = app(ConfigReader::class)->read("version: 1\nversion: 2\n");
    $date = app(ConfigReader::class)->read("version: 1\nunits:\n  web: { type: app, runtime: node, env: { DAY: 2026-10-11 } }\n");

    expect($dup->error?->code)->toBe('parse.duplicate_key')
        ->and($dup->error?->line)->toBe(2)
        ->and($date->error?->code)->toBe('parse.timestamp')
        ->and($date->error?->pointer)->toBe('/units/web/env/DAY')
        ->and($date->error?->line)->toBe(3);
});
