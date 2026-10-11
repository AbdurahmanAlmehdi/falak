<?php

use Falak\Config\Application\Validation\Resolver;
use Falak\Config\ConfigServiceProvider;
use Falak\Config\Tests\Support\ConfigCases;

// The shared falak.yaml cases (contracts/config/cases); agent/internal/configspec runs the same ones.

$read = function (string $path): array {
    return ConfigCases::parse((string) file_get_contents($path), pathinfo($path, PATHINFO_EXTENSION) === 'json' ? 'json' : 'yaml');
};

test('the schema is the v1 contract', function () {
    $schema = json_decode((string) file_get_contents(ConfigCases::root().'/'.ConfigServiceProvider::SCHEMA), true, 512, JSON_THROW_ON_ERROR);

    expect($schema['$id'])->toBe(ConfigServiceProvider::SCHEMA_ID)
        ->and($schema['$schema'])->toBe('https://json-schema.org/draft/2020-12/schema')
        // The Go side embeds a copy (go:embed can't leave its module).
        ->and(file_get_contents(ConfigCases::root().'/agent/internal/configspec/v1.schema.json'))
        ->toBe(file_get_contents(ConfigCases::root().'/'.ConfigServiceProvider::SCHEMA));
});

test('every object of the schema is closed', function () {
    $open = [];
    $walk = function (mixed $node, string $at) use (&$walk, &$open): void {
        if (! is_array($node)) {
            return;
        }

        $isObject = ($node['type'] ?? null) === 'object' || (is_array($node['type'] ?? null) && in_array('object', $node['type'], true));

        // databaseCommon is only ever $ref'd next to closed objects (databaseUnit, redisUnit); unit dispatches on type.
        if ($isObject && ! array_key_exists('additionalProperties', $node) && ! str_ends_with($at, '/databaseCommon') && ! str_ends_with($at, '/$defs/unit')) {
            $open[] = $at;
        }

        foreach ($node as $key => $child) {
            $walk($child, $at.'/'.$key);
        }
    };
    $walk(json_decode((string) file_get_contents(ConfigCases::root().'/'.ConfigServiceProvider::SCHEMA), true), '');

    expect($open)->toBe([]);
});

test('valid case', function (string $path) use ($read) {
    [$doc, $error] = $read($path);

    expect($error)->toBeNull()
        ->and(ConfigCases::validate($doc))->toBe([]);
})->with(fn () => ConfigCases::inputs('valid'));

test('invalid case', function (string $path) use ($read) {
    $want = ConfigCases::expect($path);
    [$doc, $error] = $read($path);

    if ($want['code'] !== 'schema') {
        expect($error)->not->toBeNull()
            ->and($error['code'])->toBe($want['code']);

        if ($want['pointer'] !== null) {
            expect($error['pointer'])->toBe($want['pointer']);
        }

        return;
    }

    expect($error)->toBeNull();
    $pointers = array_column(ConfigCases::validate($doc), 'pointer');

    expect($pointers)->not->toBe([])
        ->and($pointers)->toContain($want['pointer']);
})->with(fn () => ConfigCases::inputs('invalid'));

test('parse case', function (string $path) use ($read) {
    [$doc, $error] = $read($path);

    expect($error)->toBeNull()
        ->and(ConfigCases::plain($doc))->toEqual(ConfigCases::expect($path));
})->with(fn () => ConfigCases::inputs('parse'));

// The PHP merge and reference checks (Falak\Config\Application\Validation\Resolver) against every resolve case, like
// agent/internal/configspec does in Go: the resolved file, and the issues as sets of {code, pointer, source}.
test('resolve case', function (string $path) use ($read) {
    [$doc, $error] = $read($path);

    expect($error)->toBeNull()
        ->and(ConfigCases::validate($doc))->toBe([]);

    $expect = json_decode((string) file_get_contents((string) preg_replace('/\.(ya?ml|json)$/', '.expect.json', $path)), true, 512, JSON_THROW_ON_ERROR);
    $issues = fn (array $list) => collect($list)->map(fn ($i) => ['code' => $i['code'], 'pointer' => $i['pointer'], 'source' => $i['source']])
        ->sortBy(fn ($i) => implode("\0", $i))->values()->all();

    expect($expect['environments'])->not->toBe([]);

    foreach ($expect['environments'] as $env => $case) {
        [$resolved, $errors, $warnings] = app(Resolver::class)->resolve($doc, $env);

        expect($resolved === null ? null : ConfigCases::plain($resolved))->toEqual($case['resolved'], "{$env}: resolved")
            ->and($issues(array_map(fn ($i) => $i->toArray(), $errors)))->toEqual($issues($case['errors']), "{$env}: errors")
            ->and($issues(array_map(fn ($i) => $i->toArray(), $warnings)))->toEqual($issues($case['warnings']), "{$env}: warnings");

        if ($case['resolved'] !== null) {
            expect(ConfigCases::validate(ConfigCases::document($case['resolved'])))->toBe([], "{$env}: the resolved file must be valid")
                ->and(array_keys($case['resolved']['environments']))->toBe([$env]);
        }
    }
})->with(fn () => ConfigCases::inputs('resolve'));

test('the risky-change rules are well formed', function () {
    $data = json_decode((string) file_get_contents(ConfigCases::root().'/contracts/config/risky-changes.json'), true, 512, JSON_THROW_ON_ERROR);
    $ids = array_column($data['rules'], 'id');

    expect($ids)->toBe(array_values(array_unique($ids)));

    foreach ($data['rules'] as $rule) {
        expect(array_diff(array_keys($rule), ['id', 'paths', 'ops', 'unit_types', 'production_only', 'condition', 'reason']))->toBe([])
            ->and($rule['paths'])->each->toStartWith('/')
            ->and(array_diff($rule['ops'], ['create', 'update', 'delete', 'detach']))->toBe([])
            ->and(array_diff($rule['unit_types'] ?? [], ['app', 'compose', 'function', 'database', 'redis']))->toBe([])
            ->and($rule['condition'] ?? null)->toBeIn([null, 'became_true', 'became_false', 'decrease', 'tightens', 'removes_items', 'has_volumes'])
            ->and($rule['reason'])->not->toBe('');
    }
});
