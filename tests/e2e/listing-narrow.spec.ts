import { execFileSync } from 'node:child_process';

import { expect, test, type Locator, type Page } from '@playwright/test';

import { addressFor } from './accounts';
import { contrast, drawIn, modes, paintedAlong, resolved, themes, type Rgb } from './paint';

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

/** A second product, as long, with no picture: a row of the other height. */
let plainName = '';

test.describe.configure({ mode: 'serial' });

let email = '';
let generated = '';

test.beforeAll(async ({ browser }) => {
    email = addressFor('e2e-listing-narrow@example.com');
    run = `${Date.now().toString(36)}${test.info().project.name}`;
    longName = `Wolstenholme Abernathy Narrowlist ${run}`;
    plainName = `Wolstenholme Abernathy Plainlist ${run}`;

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
    await writeCategory(page);
    await writeProduct(page, longName, true);
    await writeProduct(page, plainName, false);
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

/** The category both products are filed in, so that each has a permalink. */
async function writeCategory(page: Page): Promise<void> {
    await page.goto('/admin/cms/categories/add');
    await page.getByTestId('cms-category-name-input').fill(`Narrow ${run}`);
    await page.getByTestId('cms-category-segment-input').fill(`narrow-${run}`);
    await page.getByTestId('cms-category-save').click();
    await expect(page).toHaveURL(/\/admin\/cms\/categories$/);
}

/** A product with a price of five figures, and a picture when $pictured, written through its forms. */
async function writeProduct(page: Page, name: string, pictured: boolean): Promise<void> {
    await page.goto('/admin/shop/products/add');
    await page.getByTestId('shop-product-name-input').fill(name);
    await page.getByRole('combobox', { name: 'Main category' }).click();
    await page.getByRole('option', { name: `Narrow ${run} (/narrow-${run})` }).click();
    await page.getByTestId('shop-product-price-input').fill('27990');
    await page.getByTestId('shop-product-vat-input').fill('21');
    await page.getByTestId('shop-product-save').click();
    await expect(page).toHaveURL(/\/admin\/shop\/products$/);

    if (!pictured) {
        return;
    }

    await page.goto(lists[1].address());
    await page.getByRole('link', { name }).click();
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

/**
 * Every element of $parts that reaches past the content box of $panel, left or
 * right - with the frame itself among them, and the rows it scrolls left out,
 * because reaching past it is what they are in a frame for.
 */
async function overPanel(panel: Locator, parts: Locator[]): Promise<string[]> {
    const box = await panel.evaluate((node) => {
        const rect = node.getBoundingClientRect();
        const style = getComputedStyle(node);

        return {
            left: rect.left + parseFloat(style.borderLeftWidth) + parseFloat(style.paddingLeft),
            right: rect.right - parseFloat(style.borderRightWidth) - parseFloat(style.paddingRight),
        };
    });
    expect(box.right - box.left, 'the panel has no room to measure against').toBeGreaterThan(0);

    const outside: string[] = [];
    for (const part of parts) {
        outside.push(...await part.evaluate((node, edges) => [node, ...node.querySelectorAll('*')]
            .filter((element) => element.parentElement?.closest('.c-table__viewport') == null)
            .filter((element) => {
                const rect = element.getBoundingClientRect();

                return rect.width > 0 && (rect.right > edges.right + 0.5 || rect.left < edges.left - 0.5);
            })
            .map((element) => {
                const rect = element.getBoundingClientRect();

                return `${element.tagName.toLowerCase()}.${element.className} (${Math.round(rect.left)}-${Math.round(rect.right)} in ${Math.round(edges.left)}-${Math.round(edges.right)})`;
            }), box));
    }

    return outside;
}

/**
 * What in the rows of $table reaches over the top or the bottom of its cell,
 * and which row lies over the next one.
 */
async function spillsOf(table: Locator): Promise<string[]> {
    return table.evaluate((node) => {
        const found: string[] = [];
        const rows = [...node.querySelectorAll('tbody tr')];
        rows.forEach((row, index) => {
            for (const cell of row.querySelectorAll('th, td')) {
                const edge = cell.getBoundingClientRect();
                for (const element of cell.querySelectorAll('*')) {
                    const rect = element.getBoundingClientRect();
                    if (rect.height > 0 && (rect.bottom > edge.bottom + 0.5 || rect.top < edge.top - 0.5)) {
                        found.push(`row ${index + 1}: ${element.tagName.toLowerCase()}.${element.className} spans ${Math.round(rect.top)}-${Math.round(rect.bottom)} in a cell of ${Math.round(edge.top)}-${Math.round(edge.bottom)}`);
                    }
                }
            }
            const next = rows[index + 1];
            if (next !== undefined && row.getBoundingClientRect().bottom > next.getBoundingClientRect().top + 0.5) {
                found.push(`row ${index + 1} lies over row ${index + 2}`);
            }
        });

        return found;
    });
}

function frameOf(table: Locator): Locator {
    return table.getByRole('region', { name: /.+/ });
}

const lists = [
    { name: 'people', address: () => `/admin/people?people-name=${run}`, id: 'people-list', panel: 'people-panel', open: 'people-open-', paged: false },
    { name: 'products', address: () => `/admin/shop/products?products-name=${run}`, id: 'shop-product-list', panel: 'shop-products-panel', open: 'shop-product-open-', paged: false },
    { name: 'pages', address: () => `/admin/cms/pages?pages-title=${run}`, id: 'cms-page-list', panel: 'cms-pages-panel', open: 'cms-page-open-', paged: true },
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

            // The filter, the frame, and whatever the listing draws around its
            // rows - the row of pages above and under them among it - stay
            // inside the panel they are drawn in: within its padding, not
            // merely within the window, which is wider than the panel by the
            // gutter of the page and the panel's own padding.
            const outside = await overPanel(page.getByTestId(list.panel), [
                page.getByTestId(`${list.id}-filter`),
                page.getByTestId(list.id),
            ]);
            expect(outside, 'past the edge of the panel').toEqual([]);

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
            expect(inFrame.scrollWidth, 'the table fits, so this measured nothing').toBeGreaterThan(inFrame.clientWidth);
            expect(inFrame.scrollHeight, `the frame scrolls up and down; below it: ${inFrame.below.join(', ')}`)
                .toBeLessThanOrEqual(inFrame.clientHeight);

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

    // On a desktop the frame is wide, and a badge broken there would be broken
    // only because the table was asked for more than the frame had. So the
    // test first says that it was: the rows, laid out at their full width with
    // nothing broken, are wider than the frame.
    test(`a badge in the list of products is one piece on a desktop as well, in ${theme}`, async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await signIn(page);
        await page.goto(lists[1].address());
        await drawIn(page, theme, 'light');

        const table = page.getByTestId('shop-product-list-table');
        const pressed = await frameOf(table).evaluate((node) => {
            const grid = node.querySelector('table') as HTMLElement;
            grid.style.setProperty('inline-size', 'max-content');
            grid.querySelectorAll<HTMLElement>('*').forEach((element) => element.style.setProperty('white-space', 'nowrap'));
            const wanted = grid.getBoundingClientRect().width;
            grid.removeAttribute('style');
            grid.querySelectorAll<HTMLElement>('*').forEach((element) => element.style.removeProperty('white-space'));

            return { wanted, frame: node.clientWidth };
        });
        expect(pressed.wanted, 'the rows fit the frame unbroken, so nothing pressed a badge').toBeGreaterThan(pressed.frame);

        const badges = table.locator('tbody .c-badge');
        expect(await badges.count()).toBeGreaterThan(0);
        for (const badge of await badges.all()) {
            expect(await linesOf(badge), `the badge "${await badge.textContent()}" breaks`).toBe(1);
        }
    });

    // Every row of products - one with a picture, one without, both with a
    // long name and a long permalink - at the widths of a phone, a tablet, a
    // small and a large desktop: nothing in a cell reaches over the line under
    // its row, no row lies over the next, and in the row with a picture the
    // prices and the badges sit in the middle of its height as the name does.
    for (const width of [390, 768, 1024, 1440]) {
        test(`every row of products keeps its content inside its line and in its middle, at ${width} pixels, in ${theme}`, async ({ page }) => {
            await page.setViewportSize({ width, height: 900 });
            await signIn(page);
            await page.goto(lists[1].address());
            await drawIn(page, theme, 'light');

            const table = page.getByTestId('shop-product-list-table');
            await expect(table.locator('tbody tr')).toHaveCount(2);
            await expect(table.locator('tbody tr', { hasText: plainName })).toHaveCount(1);
            await expect(table.locator('[data-testid^="shop-product-thumb-"]')).toHaveCount(1);

            expect(await spillsOf(table)).toEqual([]);

            // And the measurement sees a spill when there is one: the picture
            // moved down by the height of its cell reaches over the line under
            // its row.
            const thumb = table.locator('[data-testid^="shop-product-thumb-"]');
            await thumb.evaluate((node) => node.setAttribute(
                'style',
                `position: relative; inset-block-start: ${(node.closest('th') as Element).getBoundingClientRect().height}px`,
            ));
            expect(await spillsOf(table), 'a spill made on purpose is not seen').not.toEqual([]);
            await thumb.evaluate((node) => node.removeAttribute('style'));

            const row = table.locator('tbody tr', { hasText: longName });
            const offCentre = await row.evaluate((node) => {
                const line = node.getBoundingClientRect();
                const middle = (line.top + line.bottom) / 2;
                const of = (element: Element | null, what: string): [string, number] => {
                    if (element === null) {
                        throw new Error(`no ${what} in the row`);
                    }
                    const range = document.createRange();
                    range.selectNodeContents(element);
                    const rect = element.matches('td') ? range.getBoundingClientRect() : element.getBoundingClientRect();

                    return [what, Math.abs((rect.top + rect.bottom) / 2 - middle)];
                };

                return Object.fromEntries([
                    of(node.querySelector('[data-testid^="shop-product-open-"]'), 'name'),
                    of(node.querySelector('[data-testid^="shop-product-price-"]'), 'price'),
                    of(node.querySelector('[data-testid^="shop-product-gross-"]'), 'price with tax'),
                    of(node.querySelector('[data-testid^="shop-product-status-"]'), 'state'),
                    of(node.querySelector('[data-testid^="shop-product-address-"]'), 'permalink'),
                ]);
            });
            expect(
                Object.entries(offCentre).filter(([, off]) => off > 1.5).map(([what, off]) => `the ${what}, ${off} pixels`),
                'off the middle of a row with a picture',
            ).toEqual([]);
        });
    }

    for (const mode of modes) {
        // With no pointer and no scrollbar - a phone hides it until a finger
        // moves - the frame itself has to say that there is more to one side:
        // a shade at the edge that has more, drawn by the frame's background
        // (assets/base.css, .c-table__viewport) and moved with the rows. So
        // it is read off the pixels at both edges of the frame, with the rows
        // made invisible so that only the frame is painted there: shaded at
        // the edge there is more beyond, plain at the edge the rows have
        // reached, and plain at both where the table fits.
        test(`the frame shades the edge it can still be scrolled towards, in ${theme}, ${mode}`, async ({ page }) => {
            await page.setViewportSize({ width: 390, height: 844 });
            await signIn(page);
            await page.goto(lists[1].address());
            await drawIn(page, theme, mode);

            const frame = frameOf(page.getByTestId('shop-product-list-table'));
            const inFrame = await measureFrame(frame);
            expect(inFrame.scrollWidth, 'the table fits, so this measured nothing').toBeGreaterThan(inFrame.clientWidth);

            const surface = await resolved(frame, 'background-color', '--color-surface');
            const atStart = await edgesOf(frame, 0);
            expect(contrast(atStart.end, surface), `no shade at the end there is more beyond: ${atStart.end.join(', ')} on ${surface.join(', ')}`)
                .toBeGreaterThanOrEqual(visibleShade);
            expect(contrast(atStart.start, surface), 'a shade at the start the rows are at').toBeLessThan(plain);

            const atEnd = await edgesOf(frame, inFrame.scrollWidth);
            expect(contrast(atEnd.start, surface), `no shade at the start there is more before: ${atEnd.start.join(', ')} on ${surface.join(', ')}`)
                .toBeGreaterThanOrEqual(visibleShade);
            expect(contrast(atEnd.end, surface), 'a shade at the end the rows are at').toBeLessThan(plain);

            // The list of people, filtered to one row on a desktop, fits its frame.
            await page.setViewportSize({ width: 1440, height: 900 });
            await page.goto(lists[0].address());
            await drawIn(page, theme, mode);
            const fitting = frameOf(page.getByTestId('people-list-table'));
            const fits = await measureFrame(fitting);
            expect(fits.scrollWidth, 'the table does not fit, so this measured nothing').toBeLessThanOrEqual(fits.clientWidth);
            const still = await edgesOf(fitting, 0);
            expect(contrast(still.start, surface), 'a shade at the start of a table that fits').toBeLessThan(plain);
            expect(contrast(still.end, surface), 'a shade at the end of a table that fits').toBeLessThan(plain);
        });
    }
}

/**
 * The least contrast a shade has to have against the surface to be seen, and
 * what is no shade at all - the rounding of a painted pixel. The shade is
 * --color-line at the very edge; measured in Chromium on 2026-11-27 it stood at
 * 1.29 to 1.48 against the surface in the four themes and modes, dark no
 * fainter than light.
 */
const visibleShade = 1.2;
const plain = 1.03;

/**
 * The colours painted at the first and the last pixel of $frame, half-way
 * down a row, scrolled to $left - with the rows themselves hidden, so that
 * what is painted there is the frame's background and nothing in a cell.
 */
async function edgesOf(frame: Locator, left: number): Promise<{ start: Rgb; end: Rgb }> {
    await frame.evaluate((node, to) => {
        node.scrollLeft = to;
        (node.querySelector('table') as HTMLElement).style.setProperty('visibility', 'hidden');
    }, left);
    try {
        const along = await paintedAlong(frame, 0.4);

        return { start: along[0] ?? [0, 0, 0], end: along[along.length - 1] ?? [0, 0, 0] };
    } finally {
        await frame.evaluate((node) => (node.querySelector('table') as HTMLElement).style.removeProperty('visibility'));
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

    // The first row has a picture beside its name, as a product's row does.
    const thumb = await specimen.locator('tbody tr').first().locator('th svg').boundingBox();
    const name = await specimen.locator('tbody tr').first().locator('th .c-button').boundingBox();
    if (thumb === null || name === null) {
        throw new Error('the picture or the name of the first row is not drawn');
    }
    expect(name.x, 'the name is not beside its picture').toBeGreaterThanOrEqual(thumb.x + thumb.width);
    expect(name.y).toBeLessThan(thumb.y + thumb.height);
    expect(thumb.y).toBeLessThan(name.y + name.height);

    const page_ = await page.evaluate(() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]);
    expect(page_[0]).toBeLessThanOrEqual(page_[1]);
});
