import { expect, test } from '@playwright/test';
import { existsSync } from 'node:fs';
import { storageStatePath } from './global-setup';
import { globalAllowedConsole } from './routes';

/**
 * Config as code: UiDemoSeeder makes the Default project follow acme/storefront's falak.yaml, which manages
 * Storefront's deploy strategy and health check path. Settings → Deploy shows those fields read-only with the
 * "Managed by falak.yaml @ 4be09d2" badge; fields the file doesn't set (releases to keep) stay editable. Changes nothing.
 */
test('fields falak.yaml manages are read-only, the others editable', async ({ browser, baseURL }) => {
    const context = await browser.newContext({
        baseURL,
        ignoreHTTPSErrors: true,
        viewport: { width: 1440, height: 900 },
        storageState: existsSync(storageStatePath) ? storageStatePath : undefined,
        reducedMotion: 'reduce',
    });
    const page = await context.newPage();
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(`Uncaught: ${error.message}`));
    page.on('console', (message) => {
        if (
            message.type() === 'error' &&
            !/Failed to load resource/.test(message.text()) &&
            !globalAllowedConsole.some((pattern) => pattern.test(message.text()))
        ) {
            errors.push(message.text());
        }
    });

    await page.goto('/projects', { waitUntil: 'networkidle' });
    const project = await page.getByRole('link', { name: 'Default', exact: true }).getAttribute('href');
    expect(project).toBeTruthy();
    await page.goto(project!, { waitUntil: 'networkidle' });
    await page.getByText('Storefront', { exact: true }).first().click();
    await page.waitForURL(/\/service\/site\//);
    await page.goto(page.url().replace(/\/service\/site\/([^/]+).*$/, '/service/site/$1/settings/deploy'), { waitUntil: 'networkidle' });

    const form = page.locator('form#deploy-strategy');
    await form.scrollIntoViewIfNeeded();
    await expect(form.getByTestId('managed-badge').first()).toContainText('Managed by falak.yaml @ 4be09d2');
    await expect(form.getByRole('combobox').first()).toBeDisabled();
    await expect(form.getByLabel('Releases to keep')).toBeEnabled();

    // Project settings: the Config as code section with the applied run.
    await page.goto(`${project!.replace(/\/[^/]+$/, '')}/settings`, { waitUntil: 'networkidle' });
    await expect(page.locator('#config')).toContainText('Config as code');
    await expect(page.getByTestId('config-runs')).toContainText('4be09d2');

    expect(errors).toEqual([]);
    await context.close();
});
