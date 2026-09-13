import { execFileSync } from 'node:child_process';

import { expect, test, type Page } from '@playwright/test';

import { addressFor } from './accounts';
import { drawIn, themes } from './paint';

/**
 * The list of pages, filtered and paged, in a real browser (.ai/plans/15).
 *
 * What only a browser can answer is what happens between the requests: that
 * the filter and the row of pages redraw the list in place rather than loading
 * the page, that the address follows every step so the way back goes back -
 * the rows, the form and the address together - that the focus stays where
 * the step was taken from, and that without a script the same form and the
 * same links do the same by loading the page. The server's half, every state
 * an address can ask for, is tests/Integration/Cms/PageListTest.php.
 *
 * The pages are written through the administration's own form, as a person
 * writes them, and every title carries a mark of this run: the database is
 * kept between runs, other specs write pages into the same business, and
 * filtering by the mark is what makes the list this spec's own.
 */

/** Trilobit\Core\Console\AccountCommand::PASSWORD_LINE. */
const generatedLine = /^ {2}(\S+)$/m;

/** The business playwright.config.ts creates, and the host the browser arrives at. */
const host = '127.0.0.1';

/** Trilobit\Core\Presentation\Listing\Listing::PER_PAGE, and three past it. */
const rides = 23;

test.describe.configure({ mode: 'serial' });

let email = '';
let generated = '';
const run = Date.now().toString(36);

test.beforeAll(async ({ browser }) => {
    email = addressFor('e2e-listing@example.com');

    const output = execFileSync(
        'php',
        ['bin/trilobit', 'app:account', email, '--tenant', host, '--name', 'Lena Listing'],
        { encoding: 'utf8' },
    );
    const match = generatedLine.exec(output);
    if (match === null) {
        throw new Error(`app:account printed nothing to sign in with:\n${output}`);
    }

    generated = match[1];

    const page = await browser.newPage();
    await signIn(page);
    for (let n = 1; n <= rides; n++) {
        await write(page, `Ride ${run} ${String(n).padStart(2, '0')}`, `ride-${run}-${n}`);
    }
    await write(page, `Walk ${run}`, `walk-${run}`);
    await page.close();
});

async function signIn(page: Page): Promise<void> {
    await page.goto('/admin/sign-in');
    await page.getByTestId('sign-in-email').fill(email);
    await page.getByTestId('sign-in-password').fill(generated);
    await page.getByTestId('sign-in-submit').click();
    await page.waitForURL((url) => !url.pathname.endsWith('/sign-in'));
}

/**
 * A page written through the form, sent the way the browser sends it -
 * including the header that says it was sent from the site itself, which
 * nette/forms asks for in place of a token.
 */
async function write(page: Page, title: string, segment: string): Promise<void> {
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

function rows(page: Page) {
    return page.getByTestId('cms-page-list-table').locator('tbody tr');
}

function status(page: Page) {
    return page.getByTestId('cms-page-list-status');
}

function titleField(page: Page) {
    return page.getByRole('search').getByLabel('Title');
}

function pageLink(page: Page, label: string) {
    return page.getByTestId('cms-page-list-pagination').getByRole('link', { name: label, exact: true });
}

/** Set on the window; still there afterwards only if the page was never loaded again. */
async function markTheDocument(page: Page): Promise<void> {
    await page.evaluate(() => {
        (window as unknown as { stayed?: boolean }).stayed = true;
    });
}

async function documentStayed(page: Page): Promise<boolean> {
    return page.evaluate(() => (window as unknown as { stayed?: boolean }).stayed === true);
}

for (const theme of themes) {
    test(`the filter and the row of pages redraw the list in place, and the way back goes back, in ${theme}`, async ({ page }) => {
        await signIn(page);
        await page.goto('/admin/cms/pages');
        await drawIn(page, theme, 'light');
        await markTheDocument(page);

        // A filter, sent from the keyboard.
        await titleField(page).fill(`Ride ${run}`);
        await titleField(page).press('Enter');

        await expect(page).toHaveURL(new RegExp(`pages-title=Ride(\\+|%20)${run}`));
        await expect(status(page)).toHaveText(`${rides} pages matching the filters, page 1 of 2.`);
        await expect(rows(page)).toHaveCount(20);
        await expect(titleField(page), 'the focus left the field the filter was sent from').toBeFocused();

        // The second page, with the filter kept.
        await pageLink(page, 'Page 2').click();

        await expect(page).toHaveURL(/pages-page=2/);
        await expect(page).toHaveURL(new RegExp(`pages-title=Ride(\\+|%20)${run}`));
        await expect(status(page)).toHaveText(`${rides} pages matching the filters, page 2 of 2.`);
        await expect(rows(page)).toHaveCount(3);
        await expect(pageLink(page, 'Page 2'), 'the focus left the page that was chosen').toBeFocused();

        // Another filter from the second page starts at the first.
        await titleField(page).fill(`Walk ${run}`);
        await titleField(page).press('Enter');

        await expect(status(page)).toHaveText('1 page matching the filters.');
        await expect(page).not.toHaveURL(/pages-page/);
        await expect(rows(page)).toHaveCount(1);
        await expect(rows(page).first()).toContainText(`Walk ${run}`);

        // Back: the second page of the first filter - the rows, the form and
        // the address together.
        await page.goBack();

        await expect(page).toHaveURL(/pages-page=2/);
        await expect(rows(page)).toHaveCount(3);
        await expect(titleField(page)).toHaveValue(`Ride ${run}`);
        await expect(status(page)).toHaveText(`${rides} pages matching the filters, page 2 of 2.`);

        // Back again: its first page.
        await page.goBack();

        await expect(page).not.toHaveURL(/pages-page/);
        await expect(rows(page)).toHaveCount(20);

        expect(await documentStayed(page), 'a step loaded the page rather than redrawing the list').toBe(true);

        // Nothing of the list runs past the window.
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
    });
}

test('a filter that finds nothing says so, and clearing it brings the list back', async ({ page }) => {
    await signIn(page);
    await page.goto(`/admin/cms/pages?pages-title=Ride+${run}`);
    await markTheDocument(page);

    await titleField(page).fill(`Nowhere ${run}`);
    await titleField(page).press('Enter');

    await expect(page.getByTestId('cms-page-list-no-match')).toHaveText('No page matches the filters.');
    await expect(status(page)).toHaveText('No page matches the filters.');
    await expect(page.getByTestId('cms-page-list-empty')).toHaveCount(0);

    await page.getByTestId('cms-page-list-clear').click();

    await expect(page).not.toHaveURL(/pages-title/);
    await expect(titleField(page)).toHaveValue('');
    await expect(page.getByTestId('cms-page-list-no-match')).toHaveCount(0);
    await expect(rows(page)).not.toHaveCount(0);
    expect(await documentStayed(page)).toBe(true);
});

test('an address asking for what the list does not have is shown the list, and told what was set aside', async ({ page }) => {
    await signIn(page);

    const response = await page.goto(`/admin/cms/pages?pages-title=Ride+${run}&pages-status=bogus&pages-page=99`);

    expect(response?.status()).toBe(200);
    const setAside = page.getByTestId('cms-page-list-set-aside');
    await expect(setAside).toContainText('"bogus" is not one of the choices of State');
    await expect(setAside).toContainText('There is no page 99');
    await expect(rows(page)).toHaveCount(3);
    await expect(pageLink(page, 'Page 2')).toHaveAttribute('aria-current', 'page');

    // The first step away from it is an address without what was set aside.
    await pageLink(page, 'Page 1').click();

    await expect(page).not.toHaveURL(/bogus/);
    await expect(setAside).toHaveCount(0);
});

test.describe('without a script', () => {
    test.use({ javaScriptEnabled: false });

    test('the filter is a form and the pages are links, each loading the list it names', async ({ page }) => {
        await signIn(page);
        await page.goto('/admin/cms/pages');

        await titleField(page).fill(`Ride ${run}`);
        await page.getByRole('search').getByRole('button', { name: 'Show' }).click();

        // The form's own address is answered with a redirect to the list's.
        await expect(page).toHaveURL(new RegExp(`pages-title=Ride(\\+|%20)${run}`));
        await expect(page).not.toHaveURL(/do=/);
        await expect(rows(page)).toHaveCount(20);

        await pageLink(page, 'Page 2').click();

        await expect(page).toHaveURL(/pages-page=2/);
        await expect(rows(page)).toHaveCount(3);

        await titleField(page).fill(`Walk ${run}`);
        await page.getByRole('search').getByRole('button', { name: 'Show' }).click();

        await expect(page).not.toHaveURL(/pages-page/);
        await expect(rows(page)).toHaveCount(1);
    });
});
