import { execFileSync } from 'node:child_process';

import { expect, test, type Locator, type Page } from '@playwright/test';

import { addressFor } from './accounts';
import { drawIn, modes, themes } from './paint';

/**
 * Every list of the administration on a phone, 390 pixels wide, and the list
 * of products on a desktop.
 *
 * A list is a table, and a table with a name, an address and a price in every
 * row is wider than a phone. What it must not do is take the page with it: the
 * page never scrolls sideways, and the table scrolls inside its own frame,
 * which c-table makes a focusable, named region for exactly that - sideways
 * only, never up and down inside a frame that is as tall as the rows. The row
 * of pages above and under the rows and the filter stay inside the window as
 * well.
 *
 * Nothing in a row falls apart for lack of room either: the name a row is
 * opened by stays a button of one line rather than becoming a tall pill, its
 * picture stays beside it, a badge stays one piece, an amount stays on the line
 * of its currency, and a row keeps its content in the middle of its height.
 *
 * Each list is filtered to what this run wrote, with a name long enough that
 * no seeded or earlier row is needed for the table to be too wide. The pages
 * are one more than a page of the list holds, so the row of pages is drawn.
 */

/** Trilobit\Core\Console\AccountCommand::PASSWORD_LINE. */
const generatedLine = /^ {2}(\S+)$/m;

/** The business playwright.config.ts creates, and the host the browser arrives at. */
const host = '127.0.0.1';

/** Trilobit\Core\Presentation\Listing\Listing::PER_PAGE. */
const perPage = 20;

/**
 * The mark of this run and of this browser: two browsers run this spec side by
 * side, and each writes its own account, pages, category and product.
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
    await writePage(page, longName, `narrowlist-${run}`);
    // After the long one in the order of titles, so that it is on the first page.
    for (let n = 1; n <= perPage; n++) {
        const number = String(n).padStart(2, '0');
        await writePage(page, `Zinc ${run} ${number}`, `zinc-${run}-${number}`);
    }
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
async function writePage(page: Page, title: string, segment: string): Promise<void> {
    const response = await page.request.post('/admin/cms/pages/add', {
        headers: { 'Sec-Fetch-Site': 'same-origin' },
        form: {
            _do: 'page-submit',
            title,
            category: '',
            segment,
            perex: '',
            content: '',
            seoTitle: '',
            seoDescription: '',
            status: 'draft',
            send: 'Save',
        },
    });
    expect(response.ok(), `the page ${title} was not written`).toBe(true);
}

/** A JPEG of 64 by 48 pixels, drawn by GD for this run, as tests/e2e/pictures.spec.ts draws one. */
function picture(): Buffer {
    return execFileSync('php', [
        '-r',
        '$i = imagecreatetruecolor(64, 48); imagefilledrectangle($i, 0, 0, 31, 47, imagecolorallocate($i, 40, 120, 200)); imagejpeg($i);',
    ]);
}

/** A product with a price of five figures and a picture, written through its forms. */
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

    await page.goto(lists[1].address());
    await page.getByRole('link', { name: longName }).click();
    await page.getByTestId('shop-product-picture-input').setInputFiles({
        name: 'front.jpg',
        mimeType: 'image/jpeg',
        buffer: picture(),
    });
    await page.getByTestId('shop-product-picture-alt').fill(`From the front ${run}`);
    await page.getByTestId('shop-product-picture-add').click();
    await expect(page.getByRole('img', { name: `From the front ${run}` })).toBeVisible();
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

/** The frame a table scrolls in, measured, with whatever reaches below it. */
async function measureFrame(frame: Locator) {
    return frame.evaluate((node) => {
        const bottom = node.getBoundingClientRect().top + node.clientHeight;

        return {
            scrollWidth: node.scrollWidth,
            clientWidth: node.clientWidth,
            scrollHeight: node.scrollHeight,
            clientHeight: node.clientHeight,
            right: node.getBoundingClientRect().right,
            below: [...node.querySelectorAll('*')]
                .filter((element) => element.getBoundingClientRect().bottom > bottom + 0.5)
                .map((element) => `${element.tagName.toLowerCase()}.${element.className}`),
        };
    });
}

function frameOf(table: Locator): Locator {
    return table.getByRole('region', { name: /.+/ });
}

const lists = [
    { name: 'people', address: () => `/admin/people?people-name=${run}`, id: 'people-list', open: 'people-open-', paged: false },
    { name: 'products', address: () => `/admin/shop/products?products-name=${run}`, id: 'shop-product-list', open: 'shop-product-open-', paged: false },
    { name: 'pages', address: () => `/admin/cms/pages?pages-title=${run}`, id: 'cms-page-list', open: 'cms-page-open-', paged: true },
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
            if (list.paged) {
                await expect(page.getByTestId(`${list.id}-pagination-above`)).toBeVisible();
                await expect(page.getByTestId(`${list.id}-pagination`)).toBeVisible();
            }

            // The page itself never scrolls sideways.
            const page_ = await page.evaluate(() => ({
                scrollWidth: document.documentElement.scrollWidth,
                clientWidth: document.documentElement.clientWidth,
            }));
            expect(page_.scrollWidth, 'the page scrolls sideways').toBeLessThanOrEqual(page_.clientWidth);

            // The table does, inside its own named, focusable frame - and only
            // sideways.
            const frame = frameOf(table);
            await expect(frame).toHaveAttribute('tabindex', '0');
            const inFrame = await measureFrame(frame);
            expect(inFrame.right).toBeLessThanOrEqual(page_.clientWidth);
            expect(inFrame.scrollWidth, 'the table fits, so this measured nothing').toBeGreaterThan(inFrame.clientWidth);
            expect(inFrame.scrollHeight, `the frame scrolls up and down; below it: ${inFrame.below.join(', ')}`)
                .toBeLessThanOrEqual(inFrame.clientHeight);

            // The filter, and whatever the listing draws around its rows - the
            // row of pages above and under them among it - stay in the window.
            for (const part of [page.getByTestId(`${list.id}-filter`), page.getByTestId(list.id)]) {
                const outside = await part.evaluate((node, width) => [node, ...node.querySelectorAll('*')]
                    .filter((element) => element.closest('.c-table__viewport') === null)
                    .filter((element) => element.getBoundingClientRect().right > width + 0.5)
                    .map((element) => `${element.tagName.toLowerCase()}.${element.className}`), page_.clientWidth);
                expect(outside).toEqual([]);
            }

            // The name a row is opened by is a button of one line, in the
            // middle of the height of its row.
            const name = table.locator(`[data-testid^="${list.open}"]`, { hasText: longName });
            expect(await linesOf(name)).toBe(1);
            const offCentre = await name.evaluate((node) => {
                const button = node.getBoundingClientRect();
                const cell = (node.closest('th, td') as Element).getBoundingClientRect();

                return Math.abs((button.top + button.bottom) / 2 - (cell.top + cell.bottom) / 2);
            });
            expect(offCentre, 'the name sits off the middle of its row').toBeLessThanOrEqual(1.5);

            // A badge is one piece, however little room its column has.
            const badges = table.locator('tbody .c-badge');
            expect(await badges.count()).toBeGreaterThan(0);
            for (const badge of await badges.all()) {
                expect(await linesOf(badge), `the badge "${await badge.textContent()}" breaks`).toBe(1);
            }
        });
    }

    test(`an amount stays on the line of its currency, and a picture beside its name, in ${theme}`, async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await signIn(page);
        await page.goto(lists[1].address());
        await drawIn(page, theme, 'light');

        const table = page.getByTestId('shop-product-list-table');
        const inFrame = await measureFrame(frameOf(table));
        expect(inFrame.scrollWidth, 'the table fits, so this measured nothing').toBeGreaterThan(inFrame.clientWidth);

        const row = table.locator('tbody tr', { hasText: longName });
        const price = row.locator('[data-testid^="shop-product-price-"]');
        const gross = row.locator('[data-testid^="shop-product-gross-"]');
        await expect(price).toHaveText('27,990.00 CZK');
        expect(await linesOf(price)).toBe(1);
        expect(await linesOf(gross)).toBe(1);

        const thumb = await row.locator('[data-testid^="shop-product-thumb-"]').boundingBox();
        const name = await row.locator('[data-testid^="shop-product-open-"]').boundingBox();
        if (thumb === null || name === null) {
            throw new Error('the picture or the name of the product is not drawn');
        }
        expect(name.x, 'the name is not beside its picture').toBeGreaterThanOrEqual(thumb.x + thumb.width);
        expect(name.y).toBeLessThan(thumb.y + thumb.height);
        expect(thumb.y).toBeLessThan(name.y + name.height);
    });

    test(`a badge in the list of products is one piece on a desktop as well, in ${theme}`, async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await signIn(page);
        await page.goto(lists[1].address());
        await drawIn(page, theme, 'light');

        const badges = page.getByTestId('shop-product-list-table').locator('tbody .c-badge');
        expect(await badges.count()).toBeGreaterThan(0);
        for (const badge of await badges.all()) {
            expect(await linesOf(badge), `the badge "${await badge.textContent()}" breaks`).toBe(1);
        }
    });

    for (const mode of modes) {
        // With no pointer and no scrollbar - a phone hides it until a finger
        // moves - the frame itself has to say that there is more to one side:
        // a shade at the edge that has more, drawn by the frame's background
        // (assets/base.css, .c-table__viewport) and moved with the rows.
        test(`the frame shades the edge it can still be scrolled towards, in ${theme}, ${mode}`, async ({ page }) => {
            await page.setViewportSize({ width: 390, height: 844 });
            await signIn(page);
            await page.goto(lists[1].address());
            await drawIn(page, theme, mode);

            const frame = frameOf(page.getByTestId('shop-product-list-table'));
            const background = await frame.evaluate((node) => {
                const style = getComputedStyle(node);

                return { attachment: style.backgroundAttachment, image: style.backgroundImage };
            });
            expect(background.attachment.replaceAll(' ', '')).toBe('local,local,scroll,scroll');
            expect(background.image.match(/gradient\(/g)?.length ?? 0).toBe(4);
        });
    }
}

test('the style guide shows a table that does not fit, scrolling in its frame and not the page', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/_styleguide/components/table');

    const specimen = page.locator('[data-styleguide-variant="wider than its frame, a row opened by its name, with amounts"] .sg-specimen__stage');
    const frame = specimen.getByRole('region', { name: /.+/ });
    const inFrame = await measureFrame(frame);
    expect(inFrame.scrollWidth, 'the specimen fits, so it shows nothing').toBeGreaterThan(inFrame.clientWidth);
    expect(inFrame.scrollHeight).toBeLessThanOrEqual(inFrame.clientHeight);
    expect(await linesOf(specimen.locator('.c-button').first())).toBe(1);

    const page_ = await page.evaluate(() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]);
    expect(page_[0]).toBeLessThanOrEqual(page_[1]);
});
