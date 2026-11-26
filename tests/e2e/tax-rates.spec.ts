import { execFileSync } from 'node:child_process';

import { expect, test, type Page } from '@playwright/test';

import { addressFor } from './accounts';

/**
 * A business's rates of tax, in a real browser: the way into them through the
 * section's signpost, adding two, changing one, moving the default and
 * archiving and restoring a rate - and that somebody who holds nothing of the
 * money matters is neither offered the section nor let into it.
 *
 * The database is kept between runs and other specs share the business at
 * 127.0.0.1, so every rate this spec adds carries a mark of this run and the
 * list is read filtered by it. A rate is archived rather than deleted, so the
 * rates of earlier runs stay; nothing else reads them.
 *
 * The owner is made with `app:account`, like every spec's account. The person
 * holding only the content is made with tests/e2e/member.php, because no
 * command composes a role of a business.
 */

/** Trilobit\Core\Console\AccountCommand::PASSWORD_LINE, which tests/e2e/member.php prints too. */
const passwordLine = /^ {2}(\S+)$/m;

/**
 * The list of rates, with or without the `_fid` a flash message after a
 * redirect adds to the address.
 */
const listAddress = /\/admin\/finance\/tax-rates(\?_fid=\w+)?$/;

/** The business the browser arrives at; see playwright.config.ts. */
const host = '127.0.0.1';

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

async function signIn(page: Page, address: string, entered: string): Promise<void> {
    await page.goto('/admin/sign-in');
    await page.getByTestId('sign-in-email').fill(address);
    await page.getByTestId('sign-in-password').fill(entered);
    await page.getByTestId('sign-in-submit').click();
    await page.waitForURL((url) => !url.pathname.endsWith('/sign-in'));
}

/** The addresses the administration bar leads to. */
async function menuAddresses(page: Page): Promise<string[]> {
    return page
        .getByTestId('admin-menu')
        .locator('a')
        .evaluateAll((links) => links.map((link) => link.getAttribute('href') ?? ''));
}

test.describe.configure({ mode: 'serial' });

let owner = '';
let ownerGenerated = '';
let editor = '';
let editorGenerated = '';
let run = '';

test.beforeAll(() => {
    owner = addressFor('e2e-tax-rates-owner@example.com');
    editor = addressFor('e2e-tax-rates-editor@example.com');
    run = `${Date.now().toString(36)}${Math.floor(Math.random() * 1_000).toString(36)}`;

    php('bin/trilobit', 'migrations:migrate', '--no-interaction');
    ownerGenerated = passwordOf(php('bin/trilobit', 'app:account', owner, '--tenant', host, '--name', 'Otto Orthoceras'));

    // A role of this copy's own: two copies making one role at the same
    // moment would both insert it.
    const role = editor.slice(0, editor.indexOf('@')).replace(/[^a-z0-9]+/g, '-');
    editorGenerated = passwordOf(php(
        'tests/e2e/member.php',
        host,
        editor,
        'Eddie Echinoid',
        role,
        'app.administration.content:view',
        'app.administration.content:edit',
    ));
});

test('the rates are reached through the section, added, changed, and their default moves', async ({ page }) => {
    await signIn(page, owner, ownerGenerated);

    expect(await menuAddresses(page)).toContain('/admin/finance/tax-rates');

    await page.goto('/admin/finance');
    await expect(page.getByTestId('finance-headline')).toHaveText('Finance');
    await page.getByTestId('finance-signpost').getByRole('link', { name: 'Tax rates' }).click();
    await expect(page).toHaveURL(listAddress);
    await expect(page.getByTestId('tax-rates-headline')).toHaveText('Tax rates');

    for (const [name, percent] of [[`Lower ${run}`, '7.5'], [`Higher ${run}`, '19']]) {
        await page.getByTestId('tax-rates-add').click();
        await page.getByTestId('tax-rate-name').fill(name);
        await page.getByTestId('tax-rate-rate').fill(percent);
        await page.getByTestId('tax-rate-country').fill('cz');
        await page.getByTestId('tax-rate-kind').selectOption({ label: 'Reduced' });
        await page.getByTestId('tax-rate-save').click();

        await expect(page).toHaveURL(listAddress);
        await expect(page.getByTestId('toasts')).toContainText(`${percent} % - ${name}`);
    }

    // A rate that is not one is said beside the field, and nothing is added.
    await page.getByTestId('tax-rates-add').click();
    await page.getByTestId('tax-rate-name').fill(`Odd ${run}`);
    await page.getByTestId('tax-rate-rate').fill('twelve');
    await page.getByTestId('tax-rate-save').click();
    await expect(page.getByTestId('tax-rate-form')).toContainText("'twelve' is not a rate of tax");

    await page.goto(`/admin/finance/tax-rates?rates-name=${run}`);
    const rows = page.getByTestId('tax-rate-list-table').locator('tbody tr');
    await expect(rows).toHaveCount(2);
    await expect(rows.first()).toContainText(`Lower ${run}`);
    await expect(rows.first()).toContainText('7.5 %');
    await expect(rows.first()).toContainText('CZ');

    // Changed on its own page.
    await rows.filter({ hasText: `Lower ${run}` }).getByRole('link').click();
    await expect(page.getByTestId('tax-rate-rate')).toHaveValue('7.5');
    await page.getByTestId('tax-rate-rate').fill('8');
    await page.getByTestId('tax-rate-save').click();
    await expect(page.getByTestId('toasts')).toContainText(`8 % - Lower ${run}`);

    // The default moves to the higher rate, which is then offered no way to
    // be archived; the lower one is archived and restored.
    await page.goto(`/admin/finance/tax-rates?rates-name=${encodeURIComponent(`Higher ${run}`)}`);
    await rows.first().getByRole('link').click();
    await page.getByTestId('tax-rate-default-submit').click();
    await expect(page.getByTestId('tax-rate-state')).toHaveText('Default');
    await expect(page.getByTestId('tax-rate-archive')).toHaveCount(0);
    await expect(page.getByTestId('tax-rate-default-note')).toBeVisible();

    await page.goto(`/admin/finance/tax-rates?rates-name=${encodeURIComponent(`Lower ${run}`)}`);
    await rows.first().getByRole('link').click();
    await page.getByTestId('tax-rate-archive-submit').click();
    await expect(page.getByTestId('tax-rate-state')).toHaveText('Archived');
    await expect(page.getByTestId('tax-rate-form')).toHaveCount(0);

    await page.getByTestId('tax-rate-restore-submit').click();
    await expect(page.getByTestId('tax-rate-state')).toHaveText('Offered');
    await expect(page.getByTestId('tax-rate-form')).toBeVisible();
});

test('somebody holding nothing of the money matters is not offered the rates and is refused them', async ({ page }) => {
    await signIn(page, editor, editorGenerated);

    const addresses = await menuAddresses(page);
    expect(addresses.length, 'the editor was drawn no menu at all').toBeGreaterThan(0);
    expect(addresses.filter((href) => href.startsWith('/admin/finance')), 'the bar drew a way into the money matters').toEqual([]);

    for (const address of ['/admin/finance', '/admin/finance/tax-rates']) {
        const refused = await page.goto(address);
        expect(refused?.status(), `${address} answered somebody holding nothing of it`).toBe(403);
        await expect(page.locator('body')).toContainText('This is not yours to open.');
    }
});
