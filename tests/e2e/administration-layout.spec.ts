import { execFileSync } from 'node:child_process';

import { expect, test, type Locator, type Page } from '@playwright/test';

import { addressFor } from './accounts';
import { drawIn, modes, themes } from './paint';

/**
 * How the administration is laid out, measured in a real browser: as wide as
 * the shell lets its content run, and the controls of a form one height, in
 * both themes and both modes.
 *
 * The width is the administration's and not the site's. The administration is
 * mostly tables of records, and a table squeezed into a reading column wraps
 * every cell; a public page is mostly reading, and keeps the column its reader
 * chose. So the same window is measured on both sides.
 *
 * The heights are read off the boxes the browser drew rather than off the
 * stylesheet, because the one that was wrong - a native select - is drawn by
 * the browser to a height of its own whatever its padding says.
 */

/** Trilobit\Core\Console\AccountCommand::PASSWORD_LINE. */
const generatedLine = /^ {2}(\S+)$/m;

/** The business playwright.config.ts creates, and the host the browser arrives at. */
const host = '127.0.0.1';

/** Wider than the widest content column of either theme, so that full and the others cannot come out the same. */
const wide = { width: 2200, height: 1000 };

// One account, made once: copies of this file running side by side would each
// make it again and sign one another out.
test.describe.configure({ mode: 'serial' });

let email = '';
let generated = '';

/** The page of a product saved by this run, which draws the panels only a saved product has. */
let productPath = '';

/** The page of this run's own account among the people, which says why nothing about it may be changed. */
let personPath = '';

test.beforeAll(async ({ browser }) => {
    email = addressFor('e2e-admin-layout@example.com');

    const output = execFileSync(
        'php',
        ['bin/trilobit', 'app:account', email, '--tenant', host, '--name', 'Lara Layout'],
        { encoding: 'utf8' },
    );
    const match = generatedLine.exec(output);
    if (match === null) {
        throw new Error(`app:account printed nothing to sign in with:\n${output}`);
    }

    generated = match[1];

    // A product's form offers its categories in a combobox, and has nothing to
    // offer in a business that has none yet - which is how the database of a
    // run that starts with this file is. One is made, marked by this run,
    // because the database is kept between runs and a segment is unique.
    const run = Date.now().toString(36);
    const page = await browser.newPage();
    await signIn(page);
    await page.goto('/admin/cms/categories/add');
    await page.getByTestId('cms-category-name-input').fill(`Strata ${run}`);
    await page.getByTestId('cms-category-segment-input').fill(`strata-${run}`);
    await page.getByTestId('cms-category-save').click();
    await expect(page).toHaveURL(/\/admin\/cms\/categories$/);

    await page.goto('/admin/shop/products/add');
    await page.getByTestId('shop-product-name-input').fill(`Moraine ${run}`);
    await page.getByRole('combobox', { name: 'Main category' }).click();
    await page.getByRole('option', { name: `Strata ${run} (/strata-${run})` }).click();
    await page.getByTestId('shop-product-price-input').fill('12990');
    await page.getByTestId('shop-product-save').click();
    await expect(page).toHaveURL(/\/admin\/shop\/products$/);
    await page.goto(`/admin/shop/products?products-name=${run}`);
    await page.getByRole('link', { name: `Moraine ${run}` }).click();
    await expect(page.getByTestId('shop-product-where')).toBeVisible();
    productPath = new URL(page.url()).pathname;

    await page.goto(`/admin/people?people-email=${encodeURIComponent(email)}`);
    await page.getByTestId('people-list-table').locator('tbody tr', { hasText: email }).getByRole('link').click();
    await expect(page.getByTestId('people-unchangeable')).toBeVisible();
    personPath = new URL(page.url()).pathname;

    await page.close();
});

async function signIn(page: Page): Promise<void> {
    await page.goto('/admin/sign-in');
    await page.getByTestId('sign-in-email').fill(email);
    await page.getByTestId('sign-in-password').fill(generated);
    await page.getByTestId('sign-in-submit').click();
    await page.waitForURL((url) => !url.pathname.endsWith('/sign-in'));
}

/** How wide the content column inside $main is drawn, and how wide $main has room for. */
async function widths(main: Locator): Promise<{ column: number; room: number }> {
    return main.evaluate((node) => {
        const column = node.querySelector(':scope > .l-container');
        if (column === null) {
            throw new Error('the main region holds no content column');
        }

        return { column: column.getBoundingClientRect().width, room: node.clientWidth };
    });
}

/**
 * Every control of one line inside $form that the browser drew, by what it is
 * and how tall it came out. The line typed into inside a combobox is part of
 * the combobox's control and is measured as that; the select behind it is out
 * of sight.
 */
async function heightsIn(form: Locator): Promise<Array<{ control: string; height: number }>> {
    return form.evaluate((node) =>
        [
            ...node.querySelectorAll<HTMLElement>(
                'input:not([type="hidden"], [type="checkbox"], [type="radio"], [type="submit"], [type="button"]), select:not([multiple]), .c-combobox__control',
            ),
        ]
            .filter((element) => element.closest('.c-combobox__control') === null || element.matches('.c-combobox__control'))
            .filter((element) => !element.matches('.ts-hidden-accessible'))
            .map((element) => ({
                control: element.matches('.c-combobox__control')
                    ? 'c-combobox'
                    : `${element.tagName.toLowerCase()}[${element.getAttribute('type') ?? element.getAttribute('name') ?? ''}]`,
                height: element.getBoundingClientRect().height,
            })),
    );
}

function expectOneHeight(heights: Array<{ control: string; height: number }>, where: string): void {
    expect(heights.length, `nothing to measure ${where}`).toBeGreaterThan(1);
    const first = heights[0]?.height ?? 0;
    for (const { control, height } of heights) {
        expect(height, `${control} is not as tall as ${heights[0]?.control} ${where}`).toBeCloseTo(first, 0);
    }
}

for (const theme of themes) {
    test(`the administration runs as wide as the shell lets it, and the site keeps its column, in ${theme}`, async ({ page }) => {
        await page.setViewportSize(wide);
        await signIn(page);

        await page.goto('/admin/shop/products');
        await drawIn(page, theme, 'light');
        const administration = await widths(page.getByTestId('admin-content'));
        expect(
            administration.column,
            `the administration is drawn ${administration.column}px wide in ${administration.room}px of room`,
        ).toBeCloseTo(administration.room, 0);

        // The width is not the reader's to switch here, so the menu does not
        // offer it; the other switches stay.
        await expect(page.getByTestId('content-width-choice-content')).toHaveCount(0);
        await expect(page.getByTestId('theme-mode-choice-dark')).toHaveCount(1);

        await page.goto('/');
        await drawIn(page, theme, 'light');
        const site = await page.locator('main').evaluate((node) => {
            const column = node.querySelector('.l-container');

            return { column: column?.getBoundingClientRect().width ?? 0, room: node.clientWidth };
        });
        expect(site.column, 'the site runs as wide as the administration').toBeLessThan(site.room - 1);
    });

    for (const mode of modes) {
        test(`the filter of a list and the fields of a form are one height in ${theme}, ${mode}`, async ({ page }) => {
            await signIn(page);

            await page.goto('/admin/shop/products');
            await drawIn(page, theme, mode);
            expectOneHeight(await heightsIn(page.getByRole('search')), `in the filter of products, ${theme}, ${mode}`);

            await page.goto('/admin/shop/products/add');
            await drawIn(page, theme, mode);
            await expect(page.locator('.c-combobox__control').first()).toBeVisible();
            expectOneHeight(
                await heightsIn(page.getByTestId('admin-content').locator('form').first()),
                `in the form of a product, ${theme}, ${mode}`,
            );
        });
    }
}

/** A window as wide as the one the critic reads the administration at. */
const desktop = { width: 1440, height: 900 };

/**
 * Every page of the administration that is a form to fill in rather than a
 * list to read: each form to add something, and the two pages of something
 * already there that draw more than their form - a saved product, with its
 * pictures and its addresses, and a person, with the roles they hold.
 */
function formPages(): string[] {
    return [
        '/admin/shop/products/add',
        productPath,
        '/admin/people/add',
        personPath,
        '/admin/cms/categories/add',
        '/admin/cms/pages/add',
        '/admin/cms/menus/add',
    ];
}

/** The theme's reading measure (--layout-measure), in pixels, as the browser resolves it on the page. */
async function measureOf(page: Page): Promise<number> {
    return page.evaluate(() => {
        const probe = document.createElement('div');
        probe.style.inlineSize = 'var(--layout-measure)';
        document.body.append(probe);
        const width = probe.getBoundingClientRect().width;
        probe.remove();

        return width;
    });
}

/**
 * The widest one-line control of a form in the content, outside the filter of
 * a list. A combobox is measured by its control, as heightsIn() measures it.
 */
async function widestFieldIn(page: Page): Promise<{ control: string; width: number }> {
    return page.getByTestId('admin-content').evaluate((node) =>
        [
            ...node.querySelectorAll<HTMLElement>(
                'form input:not([type="hidden"], [type="checkbox"], [type="radio"], [type="submit"], [type="button"]), form select:not([multiple]), form .c-combobox__control',
            ),
        ]
            .filter((element) => element.closest('[role="search"]') === null)
            .filter((element) => element.closest('.c-combobox__control') === null || element.matches('.c-combobox__control'))
            .filter((element) => !element.matches('.ts-hidden-accessible'))
            .map((element) => ({
                control: element.getAttribute('name') ?? element.className,
                width: element.getBoundingClientRect().width,
            }))
            .reduce((widest, field) => (field.width > widest.width ? field : widest), { control: 'nothing', width: 0 }),
    );
}

/** The widest panel or notice drawn in the content - the boxes a form page is made of. */
async function widestBoxIn(page: Page): Promise<{ box: string; width: number }> {
    return page.getByTestId('admin-content').evaluate((node) =>
        [...node.querySelectorAll<HTMLElement>('.c-panel, .c-notice')]
            .map((element) => ({
                box: element.dataset.testid ?? element.className,
                width: element.getBoundingClientRect().width,
            }))
            .reduce((widest, box) => (box.width > widest.width ? box : widest), { box: 'nothing', width: 0 }),
    );
}

/** Where $locator's box starts and ends across the page. */
async function edgesOf(locator: Locator): Promise<{ left: number; right: number }> {
    return locator.evaluate((node) => {
        const { left, right } = node.getBoundingClientRect();

        return { left, right };
    });
}

for (const theme of themes) {
    test(`a form of the administration keeps to the reading measure while a list runs across, in ${theme}`, async ({ page }) => {
        await page.setViewportSize(desktop);
        await signIn(page);

        await page.goto('/admin/shop/products');
        await drawIn(page, theme, 'light');
        const content = page.getByTestId('admin-content');
        const { column } = await widths(content);
        const table = await content.locator('table').first().evaluate((node) => node.getBoundingClientRect().width);
        // The table sits in the listing's panel, so it is the column less the panel's padding and edge.
        expect(table, `the list of products is drawn ${table}px wide in a ${column}px column`).toBeGreaterThan(column * 0.9);

        for (const path of formPages()) {
            await page.goto(path);
            await drawIn(page, theme, 'light');
            const measure = await measureOf(page);

            // A person's own page offers no field to fill in, only the roles
            // they hold; the panels around them keep to the measure all the same.
            const box = await widestBoxIn(page);
            expect(box.width, `no panel to measure on ${path} in ${theme}`).toBeGreaterThan(0);
            expect(
                box.width,
                `${box.box} on ${path} is ${box.width}px wide, past the measure of ${measure}px, in ${theme}`,
            ).toBeLessThanOrEqual(measure + 0.5);

            const widest = await widestFieldIn(page);
            expect(
                widest.width,
                `${widest.control} on ${path} is ${widest.width}px wide, past the measure of ${measure}px, in ${theme}`,
            ).toBeLessThanOrEqual(measure + 0.5);
        }
    });

    test(`a notice over a form starts and ends where the form does, in ${theme}`, async ({ page }) => {
        await page.setViewportSize(desktop);
        await signIn(page);

        // Nothing about one's own membership may be changed, and the page says why over what they hold.
        await page.goto(personPath);
        await drawIn(page, theme, 'light');
        const unchangeable = await edgesOf(page.getByTestId('people-unchangeable'));
        const held = await edgesOf(page.getByTestId('people-held-panel'));
        expect(unchangeable.left, `the notice on a person starts at ${unchangeable.left}px, their roles at ${held.left}px, in ${theme}`).toBeCloseTo(held.left, 0);
        expect(unchangeable.right, `the notice on a person ends at ${unchangeable.right}px, their roles at ${held.right}px, in ${theme}`).toBeCloseTo(held.right, 0);

        // A request over post_max_size (playwright.config.ts) arrives with nothing
        // in it, and the product's page says so over its form.
        await page.goto(productPath);
        await page.getByTestId('shop-product-picture-input').setInputFiles({
            name: 'far-too-large.jpg',
            mimeType: 'image/jpeg',
            buffer: Buffer.alloc(25 * 1024 * 1024),
        });
        await page.getByTestId('shop-product-picture-add').click();
        await expect(page.getByTestId('shop-product-body-dropped')).toBeVisible();
        // Firefox draws the notice before the stylesheet of the page that says it has arrived.
        await page.waitForLoadState('load');
        await drawIn(page, theme, 'light');
        const dropped = await edgesOf(page.getByTestId('shop-product-body-dropped'));
        const product = await edgesOf(page.getByTestId('shop-product-panel'));
        expect(dropped.left, `the notice on a product starts at ${dropped.left}px, its form at ${product.left}px, in ${theme}`).toBeCloseTo(product.left, 0);
        expect(dropped.right, `the notice on a product ends at ${dropped.right}px, its form at ${product.right}px, in ${theme}`).toBeCloseTo(product.right, 0);
    });
}
