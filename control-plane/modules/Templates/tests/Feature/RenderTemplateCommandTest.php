<?php

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

require_once __DIR__.'/../Support/helpers.php';

const RENDER_TEMPLATE = <<<'YAML'
name: Whoami
slug: whoami
version: 1.0.0
description: Echoes the request back.
category: dev-tools
icon: traefik
docs: https://example.com/docs
min_memory_mb: 64
public:
  - service: web
    port: 80
inputs:
  - key: SECRET
    type: secret
    generate: secret(16)
YAML;

const RENDER_COMPOSE = <<<'YAML'
services:
  web:
    image: traefik/whoami:v1.11.0
    expose: ["80"]
    restart: unless-stopped
    environment:
      SECRET: ${SECRET}
      URL: ${{ falak.url(web) }}
    healthcheck:
      test: ["CMD", "/whoami", "--help"]
YAML;

/** A template directory outside the catalog, as a template author has it. */
function render_dir(string $template = RENDER_TEMPLATE, string $compose = RENDER_COMPOSE): string
{
    $dir = sys_get_temp_dir().'/falak-render-'.bin2hex(random_bytes(6));
    mkdir($dir);
    file_put_contents("{$dir}/template.yaml", $template);
    file_put_contents("{$dir}/compose.yaml", $compose);

    return $dir;
}

/** @return array{0: int, 1: string} exit code, stdout */
function render_run(array $args): array
{
    $output = new BufferedOutput;
    $code = Artisan::call('templates:render', $args, $output);

    return [$code, $output->fetch()];
}

it('renders a template directory the way the panel would deploy it', function () {
    $dir = render_dir();

    [$code, $stdout] = render_run(['template' => $dir, '--env-file' => "{$dir}/.env"]);

    expect($code)->toBe(0)
        ->and($stdout)->toContain('https://whoami.falak.test')
        ->and(file_get_contents("{$dir}/.env"))->toMatch("/^SECRET='[A-Za-z0-9]{16,}'$/m");
});

it('reports catalog rule problems and exits 1', function () {
    $dir = render_dir(compose: str_replace("\n    healthcheck:\n      test: [\"CMD\", \"/whoami\", \"--help\"]", '', RENDER_COMPOSE));

    $this->artisan('templates:render', ['template' => $dir])
        ->expectsOutputToContain('whoami: services.web needs a healthcheck')
        ->assertExitCode(1);
});

it('says so when a directory has no template.yaml', function () {
    $dir = sys_get_temp_dir().'/falak-render-empty-'.bin2hex(random_bytes(6));
    mkdir($dir);

    $this->artisan('templates:render', ['template' => $dir])->assertExitCode(1);
});

it('writes the public services for a boot test', function () {
    $dir = render_dir();

    $this->artisan('templates:render', ['template' => $dir, '--public-file' => "{$dir}/public.json"])->assertExitCode(0);

    expect(json_decode((string) file_get_contents("{$dir}/public.json"), true))->toBe([['service' => 'web', 'port' => 80, 'path' => '/']]);
});
