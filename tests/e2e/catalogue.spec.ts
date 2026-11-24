import { execFileSync } from 'node:child_process';

import { expect, test, type Page } from '@playwright/test';

import { addressFor } from './accounts';

/**
 * The catalogue in the administration, in a real browser
 * (.ai/plans/30-obchod-katalog-t09.md).
 *
 * What only a browser can answer is the part a person does with their hands:
 * the main category picked in the combobox rather than set behind it, a price
 * typed the way people type one - spaces between the thousands, a comma before
 * the hundredths - and both prices coming back in the list, the one kept and
 * the one a customer reads. The server's half - every refusal, and the role
 * that may write the catalogue and not change a price - is
 * tests/Integration/Shop/ProductAdministrationTest.php.
 *
 * The account is made the way the other specs make theirs, under an address of
 * its own. Every name carries a mark of this run, because the database is kept
 * between runs and an address is unique.
 */

/** Trilobit\Core\Console\AccountCommand::PASSWORD_LINE. */
const generatedLine = /^ {2}(\S+)$/m;

/** The business playwright.config.ts creates, and the host the browser arrives at. */
const host = '127.0.0.1';

test.describe.configure({ mode: 'serial' });

let email = '';
let generated = '';

test.beforeAll(() => {
    email = addressFor('e2e-catalogue@example.com');

    const output = execFileSync(
        'php',
        ['bin/trilobit', 'app:account', email, '--tenant', host, '--name', 'Cato Catalogue'],
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

/**
 * A category marked with $run, to file a product in: they are Core's, and
 * arranged where the content of the site is. What the combobox offers for it.
 */
async function category(page: Page, run: string): Promise<string> {
    await page.goto('/admin/cms/categories/add');
    await page.getByTestId('cms-category-name-input').fill(`Gravel ${run}`);
    await page.getByTestId('cms-category-segment-input').fill(`gravel-${run}`);
    await page.getByTestId('cms-category-save').click();
    await expect(page).toHaveURL(/\/admin\/cms\/categories$/);

    return `Gravel ${run} (/gravel-${run})`;
}

/**
 * The main category, picked the way somebody would - opened and chosen from -
 * rather than by setting the select behind it, which would pass whether or
 * not the control in front of it worked.
 */
async function fileUnder(page: Page, offered: string): Promise<void> {
    await page.getByRole('combobox', { name: 'Main category' }).click();
    await page.getByRole('option', { name: offered }).click();
    await expect(page.getByTestId('shop-product-category-input')).toHaveValue(/.+/);
}

test('a product written through the form shows both of its prices in the list, and a new rate changes the one with tax', async ({ page }) => {
    const run = Date.now().toString(36);
    await signIn(page);
    const offered = await category(page, run);

    await page.goto('/admin/shop/products/add');
    await page.getByTestId('shop-product-name-input').fill(`Moraine ${run}`);
    await fileUnder(page, offered);

    await page.getByTestId('shop-product-price-input').fill('12 990,50');
    await page.getByTestId('shop-product-vat-input').fill('21');
    await page.getByTestId('shop-product-save').click();

    await expect(page).toHaveURL(/\/admin\/shop\/products$/);

    // The list, narrowed to this run's product through its own address.
    await page.goto(`/admin/shop/products?products-name=${run}`);
    const list = page.getByTestId('shop-product-list');
    await expect(list).toContainText(`Moraine ${run}`);
    await expect(list).toContainText('12,990.50 CZK');
    await expect(list).toContainText('15,718.51 CZK');
    await expect(list).toContainText(`/gravel-${run}/moraine-${run}`);

    // Opened again, it holds the price as it was read; a new rate changes
    // the price with tax and not the one kept.
    await page.getByRole('link', { name: `Moraine ${run}` }).click();
    await expect(page.getByTestId('shop-product-price-input')).toHaveValue('12990.50');
    await page.getByTestId('shop-product-vat-input').fill('12');
    await page.getByTestId('shop-product-save').click();

    await page.goto(`/admin/shop/products?products-name=${run}`);
    await expect(list).toContainText('12,990.50 CZK');
    await expect(list).toContainText('14,549.36 CZK');
});

test('a price nobody can read is refused beside its field', async ({ page }) => {
    const run = Date.now().toString(36);
    await signIn(page);
    const offered = await category(page, run);

    await page.goto('/admin/shop/products/add');
    await page.getByTestId('shop-product-name-input').fill(`Talus ${run}`);
    await fileUnder(page, offered);
    await page.getByTestId('shop-product-price-input').fill('twelve');
    await page.getByTestId('shop-product-save').click();

    await expect(page.getByTestId('shop-product-price-input')).toHaveAttribute('aria-invalid', 'true');
    await expect(page.getByTestId('shop-product-form')).toContainText("'twelve' is not a price");
});
