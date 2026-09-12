import { execFileSync } from 'node:child_process';

import { expect, test, type Page } from '@playwright/test';

/**
 * Categories and the page form, in a real browser.
 *
 * What only a browser can answer is the part in between the requests: that
 * the button beside the last part of an address asks the server and fills in
 * what comes back, that leaving the title does the same while the last part is
 * still empty, and that an address a visitor saved before a category was
 * renamed still takes them to the page afterwards - through the redirect the
 * rename left behind, followed by the browser itself.
 *
 * The account is made here the way administration.spec.ts makes its own, and
 * for the same reasons; its address is a different one, so that the two files
 * may run side by side. Every name carries a mark of this run, because the
 * database is kept between runs and an address is unique.
 */

/** Trilobit\Core\Console\AccountCommand::PASSWORD_LINE. */
const generatedLine = /^ {2}(\S+)$/m;

const email = 'e2e-categories@example.com';

/** The business playwright.config.ts creates, and the host the browser arrives at. */
const host = '127.0.0.1';

test.describe.configure({ mode: 'serial' });

let generated = '';

test.beforeAll(() => {
    const output = execFileSync(
        'php',
        ['bin/trilobit', 'app:account', email, '--tenant', host, '--name', 'Cora Categories'],
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

test('a page filed in a category keeps answering at its old address after the category is renamed', async ({ page }) => {
    const run = Date.now().toString(36);
    await signIn(page);

    // A category, its last part suggested by the server from its name.
    await page.goto('/admin/cms/categories/add');
    await page.getByTestId('cms-category-name-input').fill(`Guides ${run}`);
    await page.getByTestId('cms-category-suggest').click();
    await expect(page.getByTestId('cms-category-segment-input')).toHaveValue(`guides-${run}`);
    await page.getByTestId('cms-category-save').click();

    await expect(page).toHaveURL(/\/admin\/cms\/categories$/);
    await expect(page.getByTestId('cms-category-list')).toContainText(`/guides-${run}`);

    // A page in it: the title is written first, and leaving it is enough for
    // the last part to be asked for.
    await page.goto('/admin/cms/pages/add');
    await page.getByTestId('cms-page-title-input').fill(`First ride ${run}`);
    await page.getByTestId('cms-page-title-input').press('Tab');
    await expect(page.getByTestId('cms-page-segment-input')).toHaveValue(`first-ride-${run}`);
    // The category is a combobox, and it is chosen the way somebody would -
    // opened and picked from - rather than by setting the select behind it,
    // which would pass whether or not the control in front of it worked. The
    // list is long or short depending on how many categories earlier runs
    // left in the database, so it is opened by a click, which both answer.
    const category = page.getByRole('combobox', { name: 'Category' });
    await expect(category).toHaveAccessibleDescription(/What the page is filed under/);
    await expect(page.getByTestId('cms-page-category-field').getByRole('combobox')).toHaveCount(1);
    await category.click();
    await page.getByRole('option', { name: `Guides ${run} (/guides-${run})` }).click();
    await expect(page.getByTestId('cms-page-category-input')).toHaveValue(/.+/);
    await page.getByTestId('cms-page-status-input').selectOption('published');
    await page.getByTestId('cms-page-save').click();

    await expect(page).toHaveURL(/\/admin\/cms\/pages$/);

    const before = await page.goto(`/guides-${run}/first-ride-${run}`);
    expect(before?.status(), 'the page did not answer under its category').toBe(200);
    await expect(page.locator('body')).toContainText(`First ride ${run}`);

    // The category renamed, from its own form.
    await page.goto('/admin/cms/categories');
    await page.getByRole('link', { name: `Guides ${run}` }).click();
    await page.getByTestId('cms-category-name-input').fill(`Handbook ${run}`);
    await page.getByTestId('cms-category-segment-input').fill(`handbook-${run}`);
    await page.getByTestId('cms-category-save').click();

    await expect(page).toHaveURL(/\/admin\/cms\/categories$/);
    await expect(page.getByTestId('cms-category-list')).toContainText(`/handbook-${run}`);

    // The address saved before the rename still leads to the page.
    const after = await page.goto(`/guides-${run}/first-ride-${run}`);
    expect(after?.status(), 'the old address of the page no longer leads to it').toBe(200);
    await expect(page).toHaveURL(new RegExp(`/handbook-${run}/first-ride-${run}$`));
    await expect(page.locator('body')).toContainText(`First ride ${run}`);
});

test('a last part with an extension is refused on the form, with the reason', async ({ page }) => {
    const run = Date.now().toString(36);
    await signIn(page);

    await page.goto('/admin/cms/pages/add');
    await page.getByTestId('cms-page-title-input').fill(`Old page ${run}`);
    await page.getByTestId('cms-page-segment-input').fill(`old-page-${run}.html`);
    await page.getByTestId('cms-page-save').click();

    await expect(page.getByTestId('cms-page-error')).toContainText('has a dot in it');
});
