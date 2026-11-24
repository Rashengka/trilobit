import { execFileSync } from 'node:child_process';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

import { expect, test, type Page } from '@playwright/test';

import { addressFor } from './accounts';

/**
 * The people of a business, in a real browser: adding somebody sends them a
 * link, the link sets their password once, and they sign in with it - and the
 * page never offers anybody a way to change their own membership.
 *
 * The invitation is read where the server this suite starts writes mail:
 * var/mail/, one file per message (TRILOBIT_MAIL_TRANSPORT=file in
 * playwright.config.ts, which only a working copy may use). What the spec
 * follows is the link in that message, as the person it went to would.
 *
 * The owner signing in is made with `app:account` like every spec's account,
 * and the person added gets an address no earlier run used, so the business
 * this suite shares with the other specs is left with nobody new in it: the
 * last step takes them away again.
 */

/** Trilobit\Core\Console\AccountCommand::PASSWORD_LINE. */
const passwordLine = /^ {2}(\S+)$/m;

/** The business the browser arrives at; see playwright.config.ts. */
const host = '127.0.0.1';

/** Where the server writes mail; see Trilobit\Core\DI\CoreExtension, core.mailers. */
const mailDirectory = 'var/mail';

/** The path of the link a password is set with; see Trilobit\Core\Routing\PasswordRoutes. */
const linkPath = /\/_password\/[A-Za-z0-9_-]+/;

function trilobit(...arguments_: string[]): string {
    return execFileSync('php', ['bin/trilobit', ...arguments_], { encoding: 'utf8' });
}

function passwordOf(output: string): string {
    const match = passwordLine.exec(output);
    if (match === null) {
        throw new Error(`app:account printed nothing to sign in with:\n${output}`);
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

/** A message as it is written, with quoted-printable's soft line breaks and escapes undone. */
function decoded(message: string): string {
    return message
        .replace(/=\r?\n/g, '')
        .replace(/=([0-9A-F]{2})/g, (_, hex: string) => String.fromCharCode(parseInt(hex, 16)));
}

/** The newest message in var/mail/ that names $address. */
function newestMessageTo(address: string): string {
    let names: string[] = [];
    try {
        names = readdirSync(mailDirectory).filter((name) => name.endsWith('.eml'));
    } catch {
        names = [];
    }

    const messages = names
        .map((name) => {
            const path = join(mailDirectory, name);

            return { at: statSync(path).mtimeMs, text: decoded(readFileSync(path, 'utf8')) };
        })
        .filter((message) => message.text.includes(address))
        .sort((one, other) => other.at - one.at);

    if (messages.length === 0) {
        throw new Error(
            `No message to ${address} in ${mailDirectory}/. The server this suite runs against has to write its `
                + 'mail to files - TRILOBIT_MAIL_TRANSPORT=file, which playwright.config.ts gives the server it '
                + 'starts; a server started by hand and taken over sends its mail wherever its own setting says.',
        );
    }

    return messages[0].text;
}

test.describe.configure({ mode: 'serial' });

let owner = '';
let generated = '';
let added = '';

test.beforeAll(() => {
    owner = addressFor('e2e-people-owner@example.com');
    added = `e2e-added-${Date.now()}-${Math.floor(Math.random() * 1_000_000)}@example.org`;

    trilobit('migrations:migrate', '--no-interaction');
    generated = passwordOf(trilobit('app:account', owner, '--tenant', host, '--name', 'Otto Orthoceras'));
});

test('somebody added is sent a link that sets their password once, and signs in with it', async ({ page, browser }) => {
    await signIn(page, owner, generated);

    await page.goto('/admin/people');
    await expect(page.getByTestId('people-headline')).toHaveText('People');
    await page.getByTestId('people-add').click();

    await page.getByTestId('people-addition-email').fill(added);
    await page.getByTestId('people-addition-name').fill('Ivo Isopod');
    await page.getByTestId('people-addition-role').selectOption({ label: 'Owner' });
    await page.getByTestId('people-addition-submit').click();

    await expect(page.getByTestId('toasts')).toContainText(`The invitation went to ${added}`);
    await expect(page.getByTestId('people-person-state')).toHaveText('Invited');
    await expect(page.getByTestId('people-resend')).toBeVisible();

    const link = linkPath.exec(newestMessageTo(added))?.[0] ?? '';
    expect(link, 'the invitation carries no link to set a password with').not.toBe('');

    // Theirs is another browser: nobody is signed in there, and nothing of
    // the owner's session comes along.
    const theirs = await browser.newContext();
    const their = await theirs.newPage();

    const opened = await their.goto(link);
    expect(opened?.status()).toBe(200);
    expect(opened?.headers()['referrer-policy'], 'the page tells other sites the address it was opened at').toBe('no-referrer');
    await expect(their.getByTestId('password-lead')).toContainText(added);

    const chosen = `several ordinary words ${Date.now()}`;
    await their.getByTestId('password-new').fill(chosen);
    await their.getByTestId('password-again').fill(chosen);
    await their.getByTestId('password-set').click();
    await expect(their).toHaveURL(/\/admin\/sign-in(\?|$)/);

    const again = await their.goto(link);
    expect(again?.status(), 'the link opened a second time').toBe(404);
    await expect(their.getByTestId('password-refused')).toBeVisible();
    await expect(their.getByTestId('password-form')).toHaveCount(0);

    await signIn(their, added, chosen);
    await expect(their).toHaveURL(/\/admin$/);

    await theirs.close();
});

test('nobody is offered a way to change their own membership, and whoever was added can be taken away', async ({ page }) => {
    await signIn(page, owner, generated);

    await page.goto(`/admin/people?people-email=${encodeURIComponent(owner)}`);
    const ownRow = page.getByTestId('people-list-table').locator('tbody tr', { hasText: owner });
    await expect(ownRow).toContainText('(you)');
    await ownRow.getByRole('link').click();

    await expect(page.getByTestId('people-unchangeable')).toContainText('This is you');
    await expect(page.locator('form[data-testid^="people-remove-"]')).toHaveCount(0);

    await page.goto(`/admin/people?people-email=${encodeURIComponent(added)}`);
    await page.getByTestId('people-list-table').locator('tbody tr', { hasText: added }).getByRole('link').click();
    await expect(page.getByTestId('people-person-state')).toHaveText('Active');

    const removal = page.locator('form[data-testid^="people-remove-"] button');
    await expect(removal).toHaveCount(1);
    await expect(removal).toHaveClass(/c-button--danger/);
    await removal.click();

    await expect(page).toHaveURL(/\/admin\/people(\?|$)/);
    await expect(page.getByTestId('toasts')).toContainText('no longer belongs to this business');
});
