import { expect, test } from '@playwright/test';

/**
 * The scenario T06 asks for: the homepage loads, the layout carries a header
 * and a footer, and the browser console stays silent.
 *
 * "Silent" is taken literally - no console error is filtered away here. A
 * filtered-out error is a promise this test cannot keep, and the favicon
 * gap that used to force one has been closed (see www/favicon.ico and the
 * <link> tags in Core's layout) rather than muted.
 */
test('the homepage loads with a header and a footer and no console errors', async ({ page }) => {
    const consoleErrors: string[] = [];
    page.on('console', (message) => {
        if (message.type() === 'error') {
            consoleErrors.push(message.text());
        }
    });

    const pageErrors: string[] = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));

    const failedRequests: string[] = [];
    page.on('requestfailed', (request) => {
        failedRequests.push(`${request.method()} ${request.url()}`);
    });
    page.on('response', (response) => {
        if (response.status() >= 400) {
            failedRequests.push(`${response.status()} ${response.url()}`);
        }
    });

    const response = await page.goto('/');
    expect(response?.status()).toBe(200);

    await expect(page.getByTestId('layout-header')).toBeVisible();
    await expect(page.getByTestId('layout-footer')).toBeVisible();
    await expect(page.getByTestId('homepage-headline')).toBeVisible();

    expect(failedRequests).toEqual([]);
    expect(consoleErrors).toEqual([]);
    expect(pageErrors).toEqual([]);
});
