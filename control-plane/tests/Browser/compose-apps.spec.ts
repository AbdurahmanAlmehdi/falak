import { expect, test, type Page } from '@playwright/test';
import { storageStatePath } from './global-setup';

/**
 * Compose apps from a repository (docs/plans/COMPOSE_APPS.md): the user picks "Docker Compose app", points at the
 * compose files and decides per service. The git provider and the repository reads are stubbed (the demo
 * connection points at an invalid host); the create request is captured instead of creating anything.
 */
test.use({ storageState: storageStatePath });

const INSPECTION = {
    no_api: false,
    files: ['docker/compose.yml', 'docker/compose.prod.yml'],
    services: [
        {
            name: 'web',
            image: 'nginx:1.28-alpine',
            build: false,
            build_context: null,
            ports: [80],
            published_ports: ['8080:80'],
            volumes: [],
            binds: [{ source: './docker/nginx.conf', in_repo: true, key: 'web:./docker/nginx.conf' }],
            env_files: [],
            healthcheck: false,
            depends_on: ['app'],
            variables: [],
            database_engine: null,
            mode: 'keep',
        },
        {
            name: 'app',
            image: null,
            build: true,
            build_context: './docker/app',
            ports: [9000],
            published_ports: [],
            volumes: [],
            binds: [{ source: './docker/storage', in_repo: false, key: 'app:./docker/storage' }],
            env_files: [{ path: 'docker/.env', in_repo: false }],
            healthcheck: true,
            depends_on: ['db'],
            variables: ['APP_KEY', 'LOG_LEVEL'],
            database_engine: null,
            mode: 'keep',
        },
        {
            name: 'db',
            image: 'postgres:17.2',
            build: false,
            build_context: null,
            ports: [5432],
            published_ports: [],
            volumes: ['pg'],
            binds: [],
            env_files: [],
            healthcheck: false,
            depends_on: [],
            variables: [],
            database_engine: 'postgresql',
            mode: 'keep',
        },
        {
            name: 'cache',
            image: 'redis:7.4-alpine',
            build: false,
            build_context: null,
            ports: [6379],
            published_ports: [],
            volumes: [],
            binds: [],
            env_files: [],
            healthcheck: false,
            depends_on: [],
            variables: [],
            database_engine: 'redis',
            mode: 'keep',
        },
    ],
    variables: [
        { name: 'APP_KEY', default: null, required: true, services: ['app'], source: 'compose' },
        { name: 'LOG_LEVEL', default: 'info', required: false, services: ['app'], source: 'compose' },
    ],
    adjustments: [
        { kind: 'container_name', service: 'web', detail: 'container_name removed: Falak names containers per environment and release.' },
        {
            kind: 'bind_to_volume',
            service: 'app',
            detail: './docker/storage is not in the repository: mounted as the named volume app-docker-storage (kept across deploys).',
            key: 'app:./docker/storage',
        },
        { kind: 'repo_files', service: null, detail: '1 repository path(s) the stack mounts or reads are shipped with each release.' },
    ],
    missing: ['docker/.env', 'docker/storage'],
    violations: [],
    errors: [],
    warnings: [],
    original: 'services:\n  web:\n    container_name: shop-web\n    image: nginx:1.28-alpine\n',
    adjusted: 'services:\n  web:\n    image: nginx:1.28-alpine\n    restart: unless-stopped\n',
};

async function stubRepository(page: Page): Promise<{ inspections: Record<string, unknown>[] }> {
    const inspections: Record<string, unknown>[] = [];
    await page.route('**/source-control/connections/*/repositories*', (route) =>
        route.fulfill({ json: { data: [{ full_name: 'acme/shop', default_branch: 'main', private: true }] } }),
    );
    await page.route('**/source-control/connections/*/branches*', (route) => route.fulfill({ json: { data: [{ name: 'main' }] } }));
    await page.route('**/sites/compose/candidates', (route) =>
        route.fulfill({ json: { data: { no_api: false, files: ['docker/compose.prod.yml', 'docker/compose.yml'] } } }),
    );
    await page.route('**/sites/compose/inspect', async (route) => {
        inspections.push(route.request().postDataJSON() as Record<string, unknown>);
        await route.fulfill({ json: { data: INSPECTION } });
    });

    return { inspections };
}

test('creates a Docker Compose app from a repository: files, services, variables, adjustments', async ({ page }) => {
    const errors: string[] = [];
    page.on('console', (message) => message.type() === 'error' && !/Failed to load resource/.test(message.text()) && errors.push(message.text()));
    const { inspections } = await stubRepository(page);
    let created: Record<string, unknown> | null = null;
    await page.route('**/projects/*/*/services', async (route) => {
        if (route.request().method() !== 'POST') return route.fallback();
        created = route.request().postDataJSON() as Record<string, unknown>;
        await route.fulfill({ status: 422, json: { message: 'stopped by the test', errors: { name: ['stopped by the test'] } } });
    });

    await page.goto('/projects', { waitUntil: 'networkidle' });
    await page.getByRole('link', { name: 'Default', exact: true }).click();
    await page.waitForURL(/\/projects\/[0-9a-z]{26}\/production$/i);
    await page.getByRole('button', { name: 'Create', exact: true }).click();
    await page.getByRole('option', { name: /Git repository/ }).click();
    const connection = page.getByRole('combobox', { name: 'Connection' });
    if (!(await connection.textContent())?.includes('GitHub')) {
        await connection.click();
        await page.getByRole('option', { name: /Acme on GitHub/ }).click();
    }
    await page.getByRole('option', { name: /acme\/shop/ }).click();

    // Nothing is detected: the user says it's a compose app.
    await expect(page.getByRole('combobox', { name: 'Preset' })).toBeVisible();
    await page.getByRole('radio', { name: 'Docker Compose app' }).click();
    await expect(page.getByRole('combobox', { name: 'Preset' })).toHaveCount(0);
    const form = page.getByTestId('compose-project-form');
    await expect(form).toBeVisible();

    // The first compose file of the repository is suggested; the user adds an override file.
    await expect(form.getByLabel('Compose file', { exact: true })).toHaveValue('docker/compose.prod.yml');
    await form.getByLabel('Compose file', { exact: true }).fill('docker/compose.yml');
    await form.getByRole('button', { name: 'Add override file' }).click();
    await form.getByLabel('Override file 1', { exact: true }).fill('docker/compose.prod.yml');
    await expect(form.getByTestId('compose-service-web')).toBeVisible();

    // web public, db replaced by a Falak database, the storage folder kept as a folder.
    await form.getByRole('combobox', { name: 'web runs' }).click();
    await page.getByRole('option', { name: 'In the stack, public' }).click();
    await expect(form.getByRole('combobox', { name: 'web port' })).toBeVisible();
    await form.getByRole('combobox', { name: 'db runs' }).click();
    await page.getByRole('option', { name: 'Falak PostgreSQL database' }).click();
    await expect(form.getByText(/Falak creates a PostgreSQL database/)).toBeVisible();
    await expect(form.getByTestId('compose-database-note-db')).toContainText('db’s existing data is not copied');
    // The official redis image can become a Falak Redis instance (phase 4 of docs/plans/REDIS.md).
    await form.getByRole('combobox', { name: 'cache runs' }).click();
    await page.getByRole('option', { name: 'Falak Redis database' }).click();
    await expect(form.getByText(/Falak creates a Redis instance on the stack’s server/)).toBeVisible();
    await expect(form.getByTestId('compose-database-note-cache')).toContainText('the Falak instance starts empty');
    await form.getByTestId('compose-service-app').getByRole('checkbox').check();

    // The required variable blocks Deploy until it has a value.
    const deploy = page.getByRole('button', { name: 'Deploy', exact: true });
    await expect(deploy).toBeDisabled();
    await form.getByLabel('APP_KEY').fill('base64:abc');
    await expect(deploy).toBeEnabled();

    await form.getByRole('button', { name: 'Show the diff' }).click();
    await expect(form.getByText('Your project → what Falak runs')).toBeVisible();

    await deploy.click();
    await expect.poll(() => created).not.toBeNull();
    const body = created as unknown as Record<string, unknown>;
    expect(body).toMatchObject({
        kind: 'site',
        runtime: 'compose',
        compose_source: 'repo',
        repository: 'acme/shop',
        branch: 'main',
        compose_files: ['docker/compose.yml', 'docker/compose.prod.yml'],
        compose_services: { db: { mode: 'database', engine: 'postgresql' }, cache: { mode: 'database', engine: 'redis' } },
        compose_adjustments: { keep_binds: ['app:./docker/storage'] },
        variables: { APP_KEY: 'base64:abc' },
    });
    expect(body.public_services).toEqual([{ service: 'web', port: 80, domain: { type: 'generated' } }]);
    expect(body).not.toHaveProperty('framework');
    expect(inspections.at(-1)).toMatchObject({ compose_files: ['docker/compose.yml', 'docker/compose.prod.yml'] });
    await expect(page.getByText('stopped by the test')).toBeVisible();

    expect(errors).toEqual([]);
});
