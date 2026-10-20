<?php

use Falak\Templates\Application\Catalog\TemplateParser;
use Falak\Templates\Domain\Category;
use Falak\Templates\Domain\InputType;
use Falak\Templates\Domain\InvalidTemplate;

require_once __DIR__.'/../Support/helpers.php';

function parse_errors(string $template, ?string $compose = 'services: {web: {image: "nginx:1"}}'): array
{
    try {
        (new TemplateParser)->parse($template, $compose);
    } catch (InvalidTemplate $e) {
        return $e->errors;
    }

    return [];
}

it('parses the template schema', function () {
    $template = (new TemplateParser)->parse(TEMPLATES_FIXTURE_TEMPLATE, TEMPLATES_FIXTURE_COMPOSE);

    expect($template->slug)->toBe('hello')
        ->and($template->name)->toBe('Hello Stack')
        ->and($template->version)->toBe('1.2.0')
        ->and($template->category)->toBe(Category::DevTools)
        ->and($template->stateful)->toBeTrue()
        ->and($template->popular)->toBeTrue()
        ->and($template->minMemoryMb)->toBe(256)
        ->and($template->public)->toBe([['service' => 'web', 'port' => 8080], ['service' => 'admin', 'port' => 9000]])
        ->and($template->inputKeys())->toBe(['APP_SECRET', 'DB_PASSWORD', 'ADMIN_EMAIL', 'TIMEZONE', 'SIGNUPS', 'WORKERS', 'PUBLIC_URL', 'DATABASE_URL']);

    $secret = $template->input('APP_SECRET');
    expect($secret->type)->toBe(InputType::Secret)
        ->and((string) $secret->generate)->toBe('secret(32)')
        ->and($secret->required)->toBeFalse()
        ->and($template->input('ADMIN_EMAIL')->required)->toBeTrue()
        ->and($template->input('ADMIN_EMAIL')->label)->toBe('Admin email')
        ->and($template->input('DB_PASSWORD')->label)->toBe('Db password')
        ->and($template->input('SIGNUPS')->default)->toBe('false')
        ->and($template->input('WORKERS')->default)->toBe('2')
        ->and($template->input('TIMEZONE')->options)->toBe(['UTC', 'Europe/Berlin']);
});

it('reads a bundle with the compose file under compose:', function () {
    $bundle = TEMPLATES_FIXTURE_TEMPLATE."\ncompose:\n  services:\n    web:\n      image: nginx:1.29\n";
    $template = (new TemplateParser)->parse($bundle);

    expect($template->composeYaml)->toContain('nginx:1.29')
        ->and($template->templateYaml)->not->toContain('compose:');

    $literal = "name: A\nslug: a\nversion: 1.0.0\ndescription: d\ncategory: ai\npublic: [{service: web, port: 80}]\ncompose: |\n  services:\n    web:\n      image: nginx:1.29\n";
    expect((new TemplateParser)->parse($literal)->composeYaml)->toBe("services:\n  web:\n    image: nginx:1.29\n");
});

it('rejects schema violations with located messages', function (string $yaml, string $expected) {
    expect(implode("\n", parse_errors($yaml)))->toContain($expected);
})->with([
    'missing name' => ["slug: a\nversion: 1.0.0\ndescription: d\ncategory: ai\npublic: [{service: web, port: 80}]", 'name is required'],
    'bad slug' => ["name: A\nslug: Not_OK\nversion: 1.0.0\ndescription: d\ncategory: ai\npublic: [{service: web, port: 80}]", 'slug use lowercase'],
    'bad version' => ["name: A\nslug: a\nversion: v1\ndescription: d\ncategory: ai\npublic: [{service: web, port: 80}]", 'version must be a semantic version'],
    'unknown category' => ["name: A\nslug: a\nversion: 1.0.0\ndescription: d\ncategory: games\npublic: [{service: web, port: 80}]", 'category must be one of'],
    'unknown key' => ["name: A\nslug: a\nversion: 1.0.0\ndescription: d\ncategory: ai\nport: 80\npublic: [{service: web, port: 80}]", 'port unknown key'],
    'no public' => ["name: A\nslug: a\nversion: 1.0.0\ndescription: d\ncategory: ai", 'public list at least one'],
    'bad health check path' => ["name: A\nslug: a\nversion: 1.0.0\ndescription: d\ncategory: ai\npublic: [{service: web, port: 80, health_check_path: health}]", 'health_check_path must be a path starting with /'],
    'bad port' => ["name: A\nslug: a\nversion: 1.0.0\ndescription: d\ncategory: ai\npublic: [{service: web, port: 99999}]", 'port must be a port number'],
    'bad key' => ["name: A\nslug: a\nversion: 1.0.0\ndescription: d\ncategory: ai\npublic: [{service: web, port: 80}]\ninputs: [{key: lower}]", 'must be an environment variable name'],
    'reserved key' => ["name: A\nslug: a\nversion: 1.0.0\ndescription: d\ncategory: ai\npublic: [{service: web, port: 80}]\ninputs: [{key: FALAK_X}]", 'reserved'],
    'duplicate key' => ["name: A\nslug: a\nversion: 1.0.0\ndescription: d\ncategory: ai\npublic: [{service: web, port: 80}]\ninputs: [{key: A}, {key: A}]", 'duplicate key'],
    'bad type' => ["name: A\nslug: a\nversion: 1.0.0\ndescription: d\ncategory: ai\npublic: [{service: web, port: 80}]\ninputs: [{key: A, type: color}]", 'type must be one of'],
    'bad generator' => ["name: A\nslug: a\nversion: 1.0.0\ndescription: d\ncategory: ai\npublic: [{service: web, port: 80}]\ninputs: [{key: A, type: secret, generate: random(9)}]", 'must be secret(n)'],
    'short generator' => ["name: A\nslug: a\nversion: 1.0.0\ndescription: d\ncategory: ai\npublic: [{service: web, port: 80}]\ninputs: [{key: A, type: secret, generate: secret(4)}]", 'length must be between'],
    'generate a number' => ["name: A\nslug: a\nversion: 1.0.0\ndescription: d\ncategory: ai\npublic: [{service: web, port: 80}]\ninputs: [{key: A, type: number, generate: uuid}]", 'only string and secret'],
    'select without options' => ["name: A\nslug: a\nversion: 1.0.0\ndescription: d\ncategory: ai\npublic: [{service: web, port: 80}]\ninputs: [{key: A, type: select}]", 'need a list of options'],
    'select default' => ["name: A\nslug: a\nversion: 1.0.0\ndescription: d\ncategory: ai\npublic: [{service: web, port: 80}]\ninputs: [{key: A, type: select, options: [x], default: y}]", 'must be one of the options'],
    'literal secret default' => ["name: A\nslug: a\nversion: 1.0.0\ndescription: d\ncategory: ai\npublic: [{service: web, port: 80}]\ninputs: [{key: A, type: secret, default: hunter2}]", 'secrets cannot have a literal default'],
    'http docs' => ["name: A\nslug: a\nversion: 1.0.0\ndescription: d\ncategory: ai\ndocs: http://x.test\npublic: [{service: web, port: 80}]", 'docs must be an https URL'],
    'bad icon' => ["name: A\nslug: a\nversion: 1.0.0\ndescription: d\ncategory: ai\nicon: ../x.svg\npublic: [{service: web, port: 80}]", 'icon must be'],
    'not yaml' => ['name: [unclosed', 'template.yaml:'],
    'both composes' => ["name: A\nslug: a\nversion: 1.0.0\ndescription: d\ncategory: ai\npublic: [{service: web, port: 80}]\ncompose: {services: {}}", 'either inline or as compose.yaml'],
]);

it('reads a public service\'s health check path', function () {
    $template = (new TemplateParser)->parse("name: A\nslug: a\nversion: 1.0.0\ndescription: d\ncategory: storage\npublic:\n  - {service: s3, port: 9000, health_check_path: /minio/health/live}\n  - {service: web, port: 80}", "services:\n  s3:\n    image: x:1\n  web:\n    image: y:1\n");

    expect($template->public)->toBe([['service' => 's3', 'port' => 9000, 'health_check_path' => '/minio/health/live'], ['service' => 'web', 'port' => 80]]);
});

it('reads min_falak, a plain semantic version', function () {
    $template = (new TemplateParser)->parse(TEMPLATES_FIXTURE_TEMPLATE."\nmin_falak: 1.2.0", TEMPLATES_FIXTURE_COMPOSE);

    expect($template->minFalak)->toBe('1.2.0')
        ->and((new TemplateParser)->parse(TEMPLATES_FIXTURE_TEMPLATE, TEMPLATES_FIXTURE_COMPOSE)->minFalak)->toBeNull()
        ->and(parse_errors(TEMPLATES_FIXTURE_TEMPLATE."\nmin_falak: 1.2", TEMPLATES_FIXTURE_COMPOSE))->toContain('template.yaml: min_falak must be a version like 1.2.0');
});

it('takes a Cool Icons key or ./icon.svg as the icon', function () {
    foreach (['n8n', 'php-fpm', 'docker/svgl-dark', './icon.svg'] as $icon) {
        expect(parse_errors(str_replace('icon: docker', "icon: {$icon}", TEMPLATES_FIXTURE_TEMPLATE), TEMPLATES_FIXTURE_COMPOSE))->toBe([], $icon);
    }

    foreach (['Docker', '../x', 'https://x.test/y.svg', 'a/b/c'] as $icon) {
        expect(parse_errors(str_replace('icon: docker', "icon: '{$icon}'", TEMPLATES_FIXTURE_TEMPLATE), TEMPLATES_FIXTURE_COMPOSE))
            ->toContain('template.yaml: icon must be a Cool Icons key (e.g. n8n, docker/svgl-dark) or ./icon.svg');
    }
});
