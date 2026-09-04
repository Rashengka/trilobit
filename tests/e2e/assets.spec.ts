import { expect, test } from '@playwright/test';

/**
 * The built files are committed under names that never move - app.js, not
 * app-0btNOuvg.js - so what tells a browser that one of them changed is a
 * version in the query string, put there by Trilobit\Core\Asset\
 * VersionedViteMapper out of what bin/build-versions.mjs measured.
 *
 * That claim is made in a browser rather than in PHPUnit because the part
 * worth doubting only exists once something serves the file: a query string on
 * a static path is handled by the web server, not by the application, and a
 * server that took `app.js?v=1a2b3c4d` for a file name would answer 404 while
 * every PHP test still passed.
 */
test('the page loads its bundle and its stylesheet with a version on the URL', async ({ page }) => {
    const served: string[] = [];
    page.on('response', (response) => {
        if (response.url().includes('/build/')) {
            served.push(`${response.status()} ${response.url()}`);
        }
    });

    await page.goto('/');

    const script = page.locator('script[src*="/build/"]').first();
    await expect(script).toHaveAttribute('src', /\/build\/app\.js\?v=[0-9a-f]{8}$/);

    const stylesheet = page.locator('link[rel="stylesheet"][href*="/build/"]').first();
    await expect(stylesheet).toHaveAttribute('href', /\/build\/app\.css\?v=[0-9a-f]{8}$/);

    // Both of them actually arrived. Without this the test would pass just as
    // happily against a server that answers 404 to every versioned URL.
    expect(served.filter((entry) => entry.startsWith('200 '))).toHaveLength(served.length);
    expect(served.length).toBeGreaterThanOrEqual(2);
});
