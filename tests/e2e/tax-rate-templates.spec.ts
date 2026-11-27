import { execFileSync } from 'node:child_process';

import { expect, test, type Page } from '@playwright/test';

import { addressFor } from './accounts';

/**
 * Rates of tax taken from a template of a country's rates, in a real browser:
 * from the list to the countries, from a country to its rates, two of them
 * taken and offered no more - and that somebody who may see the rates and not
 * add one is offered no way to the templates and is refused their page.
 *
 * A rate of a template is taken once per business, and the database is kept
 * between runs, so each copy of this spec makes a business of its own, at a
 * host of its own under `localhost` - which a browser resolves to this machine
 * without asking anybody - and takes its rates there for the first time. The
 * businesses of earlier runs stay; nothing else reads them.
 *
 * Both people are made with tests/e2e/member.php: neither is the business's
 * owner, and no command composes a role of a business.
 */

/** Trilobit\Core\Console\AccountCommand::PASSWORD_LINE, which tests/e2e/member.php prints too. */
const passwordLine = /^ {2}(\S+)$/m;

/** The list of rates, with or without the `_fid` a flash message after a redirect adds to the address. */
const listAddress = /\/admin\/finance\/tax-rates(\?_fid=\w+)?$/;

function php(...arguments_: string[]): string {
    return execFileSync('php', arguments_, { encoding: 'utf8' });
}

function passwordOf(output: string): string {
    const match = passwordLine.exec(output);
    if (match === null) {
        throw new Error(`nothing to sign in with was printed:\n${output}`);
    }

    return match[1];
}

async function signIn(page: Page, origin: string, address: string, entered: string): Promise<void> {
    await page.goto(`${origin}/admin/sign-in`);
    await page.getByTestId('sign-in-email').fill(address);
    await page.getByTestId('sign-in-password').fill(entered);
    await page.getByTestId('sign-in-submit').click();
    await page.waitForURL((url) => !url.pathname.endsWith('/sign-in'));
}

test.describe.configure({ mode: 'serial' });

let host = '';
let keeper = '';
let keeperGenerated = '';
let viewer = '';
let viewerGenerated = '';

test.beforeAll(() => {
    const run = `${Date.now().toString(36)}${Math.floor(Math.random() * 1_000).toString(36)}`;
    host = `rates-${run}.localhost`;
    keeper = addressFor('e2e-tax-templates-keeper@example.com');
    viewer = addressFor('e2e-tax-templates-viewer@example.com');

    php('bin/trilobit', 'migrations:migrate', '--no-interaction');
    php('bin/trilobit', 'app:tenant', `Rates ${run}`, host);

    keeperGenerated = passwordOf(php(
        'tests/e2e/member.php',
        host,
        keeper,
        'Kay Keeper',
        'keeper',
        'app.administration.finance.tax_rate:view',
        'app.administration.finance.tax_rate:add',
    ));
    viewerGenerated = passwordOf(php(
        'tests/e2e/member.php',
        host,
        viewer,
        'Vera Volute',
        'viewer',
        'app.administration.finance.tax_rate:view',
    ));
});

/** The base address, at this copy's business rather than the one the suite arrives at. */
function originOf(baseURL: string | undefined): string {
    const base = new URL(baseURL ?? '');
    base.hostname = host;

    return base.origin;
}

test('rates of a country are taken from its template and offered no more', async ({ page, baseURL }) => {
    const origin = originOf(baseURL);
    await signIn(page, origin, keeper, keeperGenerated);

    await page.goto(`${origin}/admin/finance/tax-rates`);
    await page.getByTestId('tax-rates-from-template').click();
    await expect(page.getByTestId('tax-rate-templates-headline')).toHaveText('Tax rates from a template');
    await expect(page.getByTestId('tax-rate-templates').getByRole('link')).toHaveCount(6);

    await page.getByTestId('tax-rate-template-cz').click();
    await expect(page.getByTestId('tax-rate-templates-headline')).toHaveText('Tax rates of CZ');
    await expect(page.getByTestId('tax-rate-template-verified')).toContainText('27 November 2026');
    const boxes = page.getByTestId('tax-rate-template-form').getByRole('checkbox');
    await expect(boxes).toHaveCount(3);

    // Every script of the page started, so that nothing it does on starting
    // lands between a box being ticked and the next.
    await page.waitForLoadState('load');
    for (const name of [/Standard rate/, /Zero rate/]) {
        const box = page.getByRole('checkbox', { name });
        await box.check();
        await expect(box).toBeChecked();
    }
    await page.getByTestId('tax-rate-template-submit').click();

    await expect(page).toHaveURL(listAddress);
    await expect(page.getByTestId('toasts')).toContainText('21 % - Standard rate');
    const rows = page.getByTestId('tax-rate-list-table').locator('tbody tr');
    await expect(rows).toHaveCount(2);
    await expect(rows.filter({ hasText: 'Standard rate' })).toContainText('Default');
    await expect(rows.filter({ hasText: 'Zero rate (books and the like)' })).toContainText('CZ');

    // Taken once: the country offers what is left, and says what is not.
    await page.goto(`${origin}/admin/finance/tax-rates/templates/cz`);
    await expect(boxes).toHaveCount(1);
    await expect(page.getByRole('checkbox', { name: /Reduced rate/ })).toBeVisible();
    await expect(page.getByTestId('tax-rate-template-adopted')).toContainText('Standard rate');
    await expect(page.getByTestId('tax-rate-template-adopted')).toContainText('Zero rate');

    // Nothing chosen is refused beside the form.
    await page.getByTestId('tax-rate-template-submit').click();
    await expect(page.getByTestId('tax-rate-template-form')).toContainText('Choose at least one rate');
    await expect(boxes).toHaveCount(1);
});

test('somebody who may see the rates and not add one is not offered the templates and is refused them', async ({ page, baseURL }) => {
    const origin = originOf(baseURL);
    await signIn(page, origin, viewer, viewerGenerated);

    await page.goto(`${origin}/admin/finance/tax-rates`);
    await expect(page.getByTestId('tax-rates-headline')).toHaveText('Tax rates');
    await expect(page.getByTestId('tax-rates-from-template')).toHaveCount(0);

    for (const address of ['/admin/finance/tax-rates/templates', '/admin/finance/tax-rates/templates/cz']) {
        const refused = await page.goto(`${origin}${address}`);
        expect(refused?.status(), `${address} answered somebody who may not add a rate`).toBe(403);
    }
});
