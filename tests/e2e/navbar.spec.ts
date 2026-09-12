import { expect, type Locator, type Page, test } from '@playwright/test';

/**
 * The Navbar page of the style guide: the banner and the navigation drawn
 * together the way a page draws them - c-site-header in the banner's region,
 * c-nav in the navigation's - with no component of its own.
 *
 * What is measured is what the page shows that no other page does: the two
 * bands together, the smaller bands atrium draws once the content has
 * scrolled under them, and the bands in a space too narrow for one row. That
 * the specimens are not held in view is measured too: a held specimen would
 * stick to the window under the page's own banner and be counted into the
 * clearance every jump to a heading keeps.
 */

const wide = { width: 1400, height: 900 };

function specimen(page: Page, variant: string): Locator {
    return page.locator(`[data-styleguide-variant="${variant}"] .sg-specimen__stage`);
}

async function drawIn(page: Page, theme: string): Promise<void> {
    await page.evaluate((name) => document.documentElement.setAttribute('data-theme', name), theme);
}

/** The font size of the first entry of the navigation in $stage, in pixels. */
async function entrySize(stage: Locator): Promise<number> {
    return stage
        .locator('.c-nav__list > .c-nav__item > .c-nav__link')
        .first()
        .evaluate((link) => Number.parseFloat(getComputedStyle(link).fontSize));
}

test.describe('the Navbar page', () => {
    test.use({ viewport: wide });

    test('draws the banner over the navigation, and leads to the pages of the two components', async ({ page }) => {
        await page.goto('/_styleguide/components/navbar');

        const stage = specimen(page, 'the banner over the navigation');
        await expect(stage.getByRole('link', { name: 'Trilobit' })).toBeVisible();
        await expect(stage.getByRole('navigation', { name: 'Navbar specimen' })).toBeVisible();

        const main = page.getByRole('main');
        await expect(main.getByRole('link', { name: 'c-site-header', exact: true }).first()).toHaveAttribute('href', /\/components\/site-header$/);
        await expect(main.getByRole('link', { name: 'c-nav', exact: true }).first()).toHaveAttribute('href', /\/components\/nav$/);
    });

    test('holds none of its specimens in view, in either theme', async ({ page }) => {
        await page.goto('/_styleguide/components/navbar');

        const bands = await page.locator('.sg-specimen__stage :is(.l-shell__banner, .l-shell__nav)').all();
        expect(bands.length, 'the page draws no bands to ask about').toBeGreaterThan(0);

        for (const theme of ['atrium', 'ledger']) {
            await drawIn(page, theme);

            for (const band of bands) {
                await expect(band, theme).toHaveCSS('position', 'static');
            }
        }
    });

    test('shows the smaller bands atrium draws once the content has scrolled, and ledger\'s unchanged', async ({ page }) => {
        await page.goto('/_styleguide/components/navbar');
        const full = specimen(page, 'the banner over the navigation');
        const smaller = specimen(page, 'made smaller once the content has scrolled');

        await drawIn(page, 'atrium');
        expect(await entrySize(smaller)).toBeLessThan(await entrySize(full));
        expect(await smaller.locator('.c-site-header').evaluate((header) => header.getBoundingClientRect().height)).toBeLessThan(
            await full.locator('.c-site-header').evaluate((header) => header.getBoundingClientRect().height),
        );

        await drawIn(page, 'ledger');
        expect(await entrySize(smaller)).toBe(await entrySize(full));
    });

    test('lets the entries wrap onto more rows in a narrow space', async ({ page }) => {
        await page.goto('/_styleguide/components/navbar');
        await drawIn(page, 'atrium');

        const tops = await specimen(page, 'in a narrow space')
            .locator('.c-nav__list > .c-nav__item')
            .evaluateAll((items) => items.map((item) => Math.round(item.getBoundingClientRect().top)));

        expect(new Set(tops).size, `the entries sit on ${new Set(tops).size} row`).toBeGreaterThan(1);
        expect(
            await specimen(page, 'in a narrow space').evaluate((stage) => stage.scrollWidth <= stage.clientWidth),
            'the narrow bands overflow their space',
        ).toBe(true);
    });
});
