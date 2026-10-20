<?php

use Falak\Templates\Application\Catalog\Catalog;
use Falak\Templates\Application\Catalog\CatalogRules;
use Falak\Templates\Application\Catalog\TemplateParser;
use Falak\Templates\Application\Catalog\TemplateValidator;
use Falak\Templates\Application\Compose\ComposeDocument;
use Falak\Templates\Infrastructure\FilesystemCatalog;

/*
 * The curated catalog (templates/ at the repository root, docs/COMPOSE_TEMPLATES.md §2.1): every template must
 * pass the same validation as an import, plus the catalog's own quality bar.
 */

const CATALOG_V1 = [
    '9router', 'adminer', 'anythingllm', 'appsmith', 'bookstack', 'changedetection', 'cliproxyapi', 'code-server',
    'dify', 'directus', 'erpnext', 'excalidraw', 'flowise', 'forgejo', 'ghost', 'gitea', 'grafana', 'hermes-agent',
    'langflow', 'langfuse', 'librechat', 'listmonk', 'litellm', 'mailpit', 'matomo', 'meilisearch', 'metabase', 'minio',
    'n8n', 'nextcloud', 'nocodb', 'ntfy', 'odoo', 'ollama', 'omniroute', 'openclaw', 'paperless-ngx', 'plausible',
    'qdrant', 'redis-stack', 'searxng', 'umami', 'uptime-kuma', 'vaultwarden', 'wikijs', 'wordpress',
];

function catalog_files(): array
{
    return (new FilesystemCatalog(cache()->store(), new TemplateParser, base_path('../templates')))->files();
}

it('ships catalog v1', function () {
    expect(array_keys(catalog_files()))->toBe(CATALOG_V1)
        ->and(array_map(fn ($t) => $t->slug, app(Catalog::class)->all()))->toEqualCanonicalizing(CATALOG_V1);
});

it('validates every catalog template', function (string $slug) {
    $files = catalog_files()[$slug];
    $template = (new TemplateParser)->parse($files['template'], $files['compose']);

    expect($template->slug)->toBe($slug)
        ->and(app(TemplateValidator::class)->problems($template))->toBe([]);

    expect(app(CatalogRules::class)->problems($template, $files['icon']))->toBe([]);
})->with(CATALOG_V1);

it('checks MinIO\'s health on its liveness endpoint (the S3 API answers 403 on / without credentials)', function () {
    $files = catalog_files()['minio'];
    $template = (new TemplateParser)->parse($files['template'], $files['compose']);

    expect($template->public[0])->toBe(['service' => 'minio', 'port' => 9000, 'health_check_path' => '/minio/health/live'])
        ->and($template->public[1])->not->toHaveKey('health_check_path');
});

it('runs ERPNext\'s setup jobs once and keeps them up idle (an exited container reads as down)', function () {
    $services = ComposeDocument::parse(catalog_files()['erpnext']['compose'])->services();

    expect($services['create-site']['depends_on']['configurator']['condition'])->toBe('service_healthy')
        ->and($services['frontend']['depends_on']['create-site']['condition'])->toBe('service_healthy')
        ->and($services['configurator']['command'][0])->toStartWith('rm -f /tmp/configured')->toContain('touch /tmp/configured', 'exec sleep infinity')
        ->and($services['create-site']['command'][0])->toStartWith('rm -f /tmp/site-ready')
        // Migrated only once installed; an install cut short is dropped and run again.
        ->toContain('if [ -f sites/frontend/.falak-installed ]', 'set-admin-password "$$ADMIN_PASSWORD"', 'bench drop-site frontend --force --no-backup', 'touch sites/frontend/.falak-installed', 'touch /tmp/site-ready', 'exec sleep infinity')
        ->and($services['db']['command'])->toContain('--character-set-server=utf8mb4', '--collation-server=utf8mb4_unicode_ci');
});

it('runs Odoo threaded so /websocket is served on its public port', function () {
    $services = ComposeDocument::parse(catalog_files()['odoo']['compose'])->services();

    expect($services['odoo']['command'][0])->toContain('workers = 0', 'proxy_mode = True', 'admin_passwd = %s')
        // The database password is in the config file, not on odoo's argv.
        ->toContain('db_password = %s', 'exec odoo -c /tmp/odoo.conf')->not->toContain('/entrypoint.sh');
});

it('keeps Dify\'s code sandbox off the environment network, behind the SSRF proxy', function () {
    $services = ComposeDocument::parse(catalog_files()['dify']['compose'])->services();

    expect($services['sandbox']['labels']['falak.environment_network'])->toBe('none')
        ->and($services['sandbox']['networks'])->toBe(['ssrf_proxy_network']);
});
