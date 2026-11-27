import { execFileSync } from 'node:child_process';

import { expect, test, type Locator, type Page } from '@playwright/test';

import { addressFor } from './accounts';
import { drawIn, themes } from './paint';

/**
 * Every list of the administration on a phone, 390 pixels wide.
 *
 * A list is a table, and a table with a name, an address and a price in every
 * row is wider than a phone. What it must not do is take the page with it: the
 * page never scrolls sideways, and the table scrolls inside its own frame,
 * which c-table makes a focusable, named region for exactly that. The row of
 * pages and the filter stay inside the window as well.
 *
 * Two things in a row must not fall apart for lack of room either: the name a
 * row is opened by stays a button of one line rather than becoming a tall
 * pill, and an amount stays on the line of its currency.
 *
 * Each list is filtered to what this run wrote, with a name long enough that
 * no seeded or earlier row is needed for the table to be too wide.
 */

/** Trilobit\Core\Console\AccountCommand::PASSWORD_LINE. */
const generatedLine = /^ {2}(\S+)$/m;

/** The business playwright.config.ts creates, and the host the browser arrives at. */
const host = '127.0.0.1';

/**
 * The mark of this run and of this browser: two browsers run this spec side by
 * side, and each writes its own account, page, category and product.
 */
let run = '';

/** Long enough to be wider than a phone's column on any theme. */
let longName = '';

test.describe.configure({ mode: 'serial' });

let email = '';
let generated = '';

test.beforeAll(async ({ browser }) => {
    email = addressFor('e2e-listing-narrow@example.com');
    run = `${Date.now().toString(36)}${test.info().project.name}`;
    longName = `Wolstenholme Abernathy Narrowlist ${run}`;

    const output = execFileSync(
        'php',
        ['bin/trilobit', 'app:account', email, '--tenant', host, '--name', longName],
        { encoding: 'utf8' },
    );
    const match = generatedLine.exec(output);
    if (match === null) {
        throw new Error(`app:account printed nothing to sign in with:\n${output}`);
    }

    generated = match[1];

    const page = await browser.newPage();
    await signIn(page);
    await writePage(page);
    await writeProduct(page);
    await page.close();
});

async function signIn(page: Page): Promise<void> {
    await page.goto('/admin/sign-in');
    await page.getByTestId('sign-in-email').fill(email);
    await page.getByTestId('sign-in-password').fill(generated);
    await page.getByTestId('sign-in-submit').click();
    await page.waitForURL((url) => !url.pathname.endsWith('/sign-in'));
}

/** A page of the site, written the way tests/e2e/listing.spec.ts writes one. */
async function writePage(page: Page): Promise<void> {
    const response = await page.request.post('/admin/cms/pages/add', {
        headers: { 'Sec-Fetch-Site': 'same-origin' },
        form: {
            _do: 'page-submit',
            title: longName,
            category: '',
            segment: `narrowlist-${run}`,
            perex: '',
            content: '',
            seoTitle: '',
            seoDescription: '',
            status: 'draft',
            send: 'Save',
        },
    });
    expect(response.ok(), 'the page was not written').toBe(true);
}

/** A product with a price of five figures, written through its form. */
async function writeProduct(page: Page): Promise<void> {
    await page.goto('/admin/cms/categories/add');
    await page.getByTestId('cms-category-name-input').fill(`Narrow ${run}`);
    await page.getByTestId('cms-category-segment-input').fill(`narrow-${run}`);
    await page.getByTestId('cms-category-save').click();
    await expect(page).toHaveURL(/\/admin\/cms\/categories$/);

    await page.goto('/admin/shop/products/add');
    await page.getByTestId('shop-product-name-input').fill(longName);
    await page.getByRole('combobox', { name: 'Main category' }).click();
    await page.getByRole('option', { name: `Narrow ${run} (/narrow-${run})` }).click();
    await page.getByTestId('shop-product-price-input').fill('27990');
    await page.getByTestId('shop-product-vat-input').fill('21');
    await page.getByTestId('shop-product-save').click();
    await expect(page).toHaveURL(/\/admin\/shop\/products$/);
}

/** How many lines the text of $element is laid out on. */
async function linesOf(element: Locator): Promise<number> {
    return element.evaluate((node) => {
        const range = document.createRange();
        range.selectNodeContents(node);
        const tops = new Set<number>();
        for (const rect of range.getClientRects()) {
            if (rect.width > 0) {
                tops.add(Math.round(rect.top));
            }
        }

        return tops.size;
    });
}

const lists = [
    { name: 'people', address: () => `/admin/people?people-name=${run}`, id: 'people-list', open: 'people-open-' },
    { name: 'products', address: () => `/admin/shop/products?products-name=${run}`, id: 'shop-product-list', open: 'shop-product-open-' },
    { name: 'pages', address: () => `/admin/cms/pages?pages-title=${run}`, id: 'cms-page-list', open: 'cms-page-open-' },
] as const;

for (const theme of themes) {
    for (const list of lists) {
        test(`the list of ${list.name} scrolls inside its frame and not the page, at 390 pixels, in ${theme}`, async ({ page }) => {
            await page.setViewportSize({ width: 390, height: 844 });
            await signIn(page);
            await page.goto(list.address());
            await drawIn(page, theme, 'light');

            const table = page.getByTestId(`${list.id}-table`);
            await expect(table).toContainText(longName);

            // The page itself never scrolls sideways.
            const page_ = await page.evaluate(() => ({
                scrollWidth: document.documentElement.scrollWidth,
                clientWidth: document.documentElement.clientWidth,
            }));
            expect(page_.scrollWidth, 'the page scrolls sideways').toBeLessThanOrEqual(page_.clientWidth);

            // The table does, inside its own named, focusable frame.
            const frame = table.getByRole('region', { name: /.+/ });
            await expect(frame).toHaveAttribute('tabindex', '0');
            const inFrame = await frame.evaluate((node) => ({
                scrollWidth: node.scrollWidth,
                clientWidth: node.clientWidth,
                right: node.getBoundingClientRect().right,
            }));
            expect(inFrame.right).toBeLessThanOrEqual(page_.clientWidth);
            expect(inFrame.scrollWidth, 'the table fits, so this measured nothing').toBeGreaterThan(inFrame.clientWidth);

            // The filter, and whatever the listing draws around its rows, stay
            // in the window.
            for (const part of [page.getByTestId(`${list.id}-filter`), page.getByTestId(list.id)]) {
                const outside = await part.evaluate((node, width) => [node, ...node.querySelectorAll('*')]
                    .filter((element) => element.closest('.c-table__viewport') === null)
                    .filter((element) => element.getBoundingClientRect().right > width + 0.5)
                    .map((element) => `${element.tagName.toLowerCase()}.${element.className}`), page_.clientWidth);
                expect(outside).toEqual([]);
            }

            // The name a row is opened by is a button of one line.
            const name = table.locator(`[data-testid^="${list.open}"]`, { hasText: longName });
            expect(await linesOf(name)).toBe(1);
        });
    }

    test(`an amount stays on the line of its currency, in ${theme}`, async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await signIn(page);
        await page.goto(lists[1].address());
        await drawIn(page, theme, 'light');

        const row = page.getByTestId('shop-product-list-table').locator('tbody tr', { hasText: longName });
        const price = row.locator('[data-testid^="shop-product-price-"]');
        const gross = row.locator('[data-testid^="shop-product-gross-"]');
        await expect(price).toHaveText('27,990.00 CZK');
        expect(await linesOf(price)).toBe(1);
        expect(await linesOf(gross)).toBe(1);
    });
}
