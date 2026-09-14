import { execFileSync } from 'node:child_process';

import { expect, test, type Page } from '@playwright/test';

import { addressFor } from './accounts';

/**
 * A product's pictures, in a real browser and through a real PHP server
 * (.ai/plans/30-obchod-katalog-t09.md, Q4).
 *
 * What only this can answer is the part PHP does before the application sees
 * anything. A file over `upload_max_filesize` arrives as an upload with an
 * error in it; a request over `post_max_size` arrives with its whole body thrown
 * away - no fields, no file, no signal saying a form was sent - and a page
 * that did nothing about it would simply be drawn again. playwright.config.ts
 * starts the server with 20M and 24M, so a file of 21 MiB is the first kind and
 * one of 25 MiB the second, and both have to come back as a sentence. The
 * server's half of each is tests/Integration/Shop/ProductAdministrationTest.php.
 *
 * The picture is drawn by PHP's own GD for this run, not taken from anywhere,
 * and it has to be seen served: an image the browser could not load is still
 * an <img> in the page.
 */

/** Trilobit\Core\Console\AccountCommand::PASSWORD_LINE. */
const generatedLine = /^ {2}(\S+)$/m;

/** The business playwright.config.ts creates, and the host the browser arrives at. */
const host = '127.0.0.1';

const mebibyte = 1024 * 1024;

test.describe.configure({ mode: 'serial' });

let email = '';
let generated = '';

test.beforeAll(() => {
    // An account for each browser: the browsers run this file side by side,
    // and app:account run again for an address gives it a new password - so a
    // shared address would sign one browser out of the password it just read.
    email = addressFor(`e2e-pictures-${test.info().project.name}@example.com`);

    const output = execFileSync(
        'php',
        ['bin/trilobit', 'app:account', email, '--tenant', host, '--name', 'Pia Pictures'],
        { encoding: 'utf8' },
    );
    const match = generatedLine.exec(output);
    if (match === null) {
        throw new Error(`app:account printed nothing to sign in with:\n${output}`);
    }

    generated = match[1];
});

async function signIn(page: Page): Promise<void> {
    await page.goto('/admin/sign-in');
    await page.getByTestId('sign-in-email').fill(email);
    await page.getByTestId('sign-in-password').fill(generated);
    await page.getByTestId('sign-in-submit').click();
    await page.waitForURL((url) => !url.pathname.endsWith('/sign-in'));
}

/** A JPEG of 64 by 48 pixels, half of it red, drawn by GD for this run. */
function picture(): Buffer {
    return execFileSync('php', [
        '-r',
        '$i = imagecreatetruecolor(64, 48); imagefilledrectangle($i, 0, 0, 31, 47, imagecolorallocate($i, 200, 40, 40)); imagejpeg($i);',
    ]);
}

/** A product filed in a category of its own, both marked with $run, open on its own page. */
async function openNewProduct(page: Page, run: string): Promise<void> {
    await page.goto('/admin/cms/categories/add');
    await page.getByTestId('cms-category-name-input').fill(`Pictured ${run}`);
    await page.getByTestId('cms-category-segment-input').fill(`pictured-${run}`);
    await page.getByTestId('cms-category-save').click();
    await expect(page).toHaveURL(/\/admin\/cms\/categories$/);

    await page.goto('/admin/shop/products/add');
    await page.getByTestId('shop-product-name-input').fill(`Esker ${run}`);
    await page.getByRole('combobox', { name: 'Main category' }).click();
    await page.getByRole('option', { name: `Pictured ${run} (/pictured-${run})` }).click();
    await page.getByTestId('shop-product-price-input').fill('27990');
    await page.getByTestId('shop-product-save').click();
    await expect(page).toHaveURL(/\/admin\/shop\/products$/);

    await page.goto(`/admin/shop/products?products-name=${run}`);
    await page.getByRole('link', { name: `Esker ${run}` }).click();
    await expect(page.getByTestId('shop-product-pictures')).toBeVisible();
}

test('a picture added on the product\'s page is served from its variants, and taking it off leaves none', async ({ page }) => {
    const run = Date.now().toString(36);
    await signIn(page);
    await openNewProduct(page, run);

    await page.getByTestId('shop-product-picture-input').setInputFiles({
        name: 'side.jpg',
        mimeType: 'image/jpeg',
        buffer: picture(),
    });
    await page.getByTestId('shop-product-picture-alt').fill(`From the side ${run}`);
    await page.getByTestId('shop-product-picture-add').click();

    const image = page.getByRole('img', { name: `From the side ${run}` });
    await expect(image).toBeVisible();
    await expect(image).toHaveAttribute('src', /-thumb\.jpg$/);
    // Loaded, not merely drawn: the variant is served from the public
    // directory, while the original never is. Looked at first - the picture
    // is loaded lazily and sits below the form, and Firefox, unlike Chromium,
    // does not fetch a lazy picture that far outside the window.
    await image.scrollIntoViewIfNeeded();
    await expect.poll(() => image.evaluate((element: HTMLImageElement) => element.naturalWidth)).toBe(64);

    await page.getByRole('button', { name: 'Take it off' }).click();
    await expect(page.getByTestId('shop-product-no-pictures')).toBeVisible();
});

test('a file larger than the server takes is refused with a sentence, however much larger it is', async ({ page }) => {
    const run = Date.now().toString(36);
    await signIn(page);
    await openNewProduct(page, run);

    // Over upload_max_filesize and under post_max_size: the file arrives
    // with an error in it, and the form says so beside its field.
    await page.getByTestId('shop-product-picture-input').setInputFiles({
        name: 'too-large.jpg',
        mimeType: 'image/jpeg',
        buffer: Buffer.alloc(21 * mebibyte),
    });
    await page.getByTestId('shop-product-picture-add').click();
    await expect(page.getByTestId('shop-product-pictures-form')).toContainText('larger than this server takes');
    await expect(page.getByTestId('shop-product-picture-input')).toHaveAttribute('aria-invalid', 'true');

    // Over post_max_size: nothing of the request arrives at all, and the page
    // says so at the top rather than being drawn as if nothing was sent.
    await page.getByTestId('shop-product-picture-input').setInputFiles({
        name: 'far-too-large.jpg',
        mimeType: 'image/jpeg',
        buffer: Buffer.alloc(25 * mebibyte),
    });
    await page.getByTestId('shop-product-picture-add').click();
    await expect(page.getByTestId('shop-product-body-dropped')).toContainText('larger than this server takes at once');
    await expect(page.getByTestId('shop-product-no-pictures')).toBeVisible();
});
