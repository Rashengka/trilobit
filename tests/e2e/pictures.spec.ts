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
 *
 * Saying again what a picture shows and moving it are forms of their own, and
 * the order is changed from the keyboard here, because that is the way it
 * has to work for everybody; the server's half is in the same suite as above.
 * The picture opened over the page is c-lightbox, whose own behaviour is
 * tests/e2e/lightbox.spec.ts; what is asked here is that the product's page
 * opens its pictures in the largest variant there is, and that the way to
 * that variant on its own is served.
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
    email = addressFor('e2e-pictures@example.com');

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

    // The small picture is a button opening it larger, named for that.
    await expect(page.getByRole('button', { name: `Enlarge: From the side ${run}` })).toBeVisible();
    const image = page.getByTestId('shop-product-gallery').locator('.c-lightbox__thumb');
    await expect(image).toBeVisible();
    await expect(image).toHaveAttribute('alt', `From the side ${run}`);
    await expect(image).toHaveAttribute('src', /-thumb\.jpg$/);
    // Loaded, not merely drawn: the variant is served from the public
    // directory, while the original never is. Looked at first - the picture
    // is loaded lazily and sits below the form, and Firefox, unlike Chromium,
    // does not fetch a lazy picture that far outside the window.
    await image.scrollIntoViewIfNeeded();
    // The small picture is drawn as wide as its cell, so the browser reports
    // the width the file has at the density it is drawn at; that it arrived at
    // all, from the variant, is what is asked.
    await expect.poll(() => image.evaluate((element: HTMLImageElement) => element.complete && element.naturalWidth > 0)).toBe(true);
    expect(await image.evaluate((element: HTMLImageElement) => element.currentSrc)).toMatch(/-thumb\.jpg$/);

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

/** Adds the picture drawn by picture() under the name $name, saying $alt of it. */
async function addPicture(page: Page, name: string, alt: string): Promise<void> {
    await page.getByTestId('shop-product-picture-input').setInputFiles({ name, mimeType: 'image/jpeg', buffer: picture() });
    await page.getByTestId('shop-product-picture-alt').fill(alt);
    await page.getByTestId('shop-product-picture-add').click();
    await expect(page.getByRole('button', { name: `Enlarge: ${alt}` })).toBeVisible();
}

/** Does $act, which sends a form, and waits for the page the server answers with to have loaded. */
async function sending(page: Page, act: () => Promise<void>): Promise<void> {
    const loaded = page.waitForEvent('load');
    await act();
    await loaded;
}

/** What the small pictures of the product are named, in the order the page draws them. */
async function order(page: Page): Promise<string[]> {
    return page.getByTestId('shop-product-gallery').locator('.c-lightbox__opener').evaluateAll((openers) =>
        openers.map((opener) => opener.getAttribute('aria-label') ?? ''),
    );
}

test('what a picture shows is said again, the order is changed from the keyboard, and the first is the one the list shows', async ({ page }) => {
    const run = Date.now().toString(36);
    await signIn(page);
    await openNewProduct(page, run);
    await addPicture(page, 'side.jpg', `Side ${run}`);
    await addPicture(page, 'back.jpg', `Back ${run}`);
    await addPicture(page, 'front.jpg', `Front ${run}`);
    const items = page.getByTestId('shop-product-gallery').locator('.c-lightbox__item');

    await items.nth(1).getByLabel('What it shows').fill(`Rear ${run}`);
    await sending(page, () => items.nth(1).getByRole('button', { name: 'Save' }).click());
    await expect.poll(() => order(page)).toEqual([`Enlarge: Side ${run}`, `Enlarge: Rear ${run}`, `Enlarge: Front ${run}`]);

    // Nowhere earlier for the first, nowhere later for the last.
    await expect(items.nth(0).getByRole('button', { name: 'Move earlier' })).toBeDisabled();
    await expect(items.nth(2).getByRole('button', { name: 'Move later' })).toBeDisabled();

    // From the keyboard: the button is reached and pressed, with no pointer and no script of its own.
    await items.nth(2).getByRole('button', { name: 'Move earlier' }).focus();
    await sending(page, () => page.keyboard.press('Enter'));
    await expect.poll(() => order(page)).toEqual([`Enlarge: Side ${run}`, `Enlarge: Front ${run}`, `Enlarge: Rear ${run}`]);
    // The page comes back at the picture that was moved, not at its top.
    await expect(page).toHaveURL(/#shop-picture-\d+-item$/);

    await items.nth(1).getByRole('button', { name: 'Move earlier' }).focus();
    await sending(page, () => page.keyboard.press('Enter'));
    await expect.poll(() => order(page)).toEqual([`Enlarge: Front ${run}`, `Enlarge: Side ${run}`, `Enlarge: Rear ${run}`]);
    await expect(items.nth(0).getByTestId('shop-product-picture-main')).toBeVisible();

    const first = await items.nth(0).locator('.c-lightbox__thumb').getAttribute('src');
    await page.goto(`/admin/shop/products?products-name=${run}`);
    const thumb = page.locator('[data-testid^="shop-product-thumb-"]');
    await expect(thumb).toHaveCount(1);
    expect(await thumb.getAttribute('src')).toBe(first);
});

test('a picture opens over the page in its largest variant, and the way to it on its own is served', async ({ page }) => {
    const run = Date.now().toString(36);
    await signIn(page);
    await openNewProduct(page, run);
    await addPicture(page, 'side.jpg', `Side ${run}`);

    await page.getByRole('button', { name: `Enlarge: Side ${run}` }).click();
    const dialog = page.getByRole('dialog', { name: `Side ${run} 1 of 1` });
    await expect(dialog).toBeVisible();

    const large = dialog.locator('.c-lightbox__image');
    await expect(large).toHaveAttribute('src', /-large\.jpg$/);
    await expect.poll(() => large.evaluate((element: HTMLImageElement) => element.complete && element.naturalWidth > 0)).toBe(true);

    const full = dialog.getByRole('link', { name: 'Open full size' });
    const served = await page.request.get((await full.getAttribute('href')) ?? '');
    expect(served.status()).toBe(200);
    expect(served.headers()['content-type']).toContain('image/jpeg');

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(page.getByRole('button', { name: `Enlarge: Side ${run}` })).toBeFocused();
});
