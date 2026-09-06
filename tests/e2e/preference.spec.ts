import { execFileSync } from 'node:child_process';

import { expect, test } from '@playwright/test';

import { choose, themeOf } from './preferences';

/**
 * Decision D8, told as one story in a real browser: a device remembers, a
 * person carries, and the person wins.
 *
 * It is one case rather than five because the rules are about what follows
 * what. Signing in only means something after a device has an opinion, and "the
 * profile wins" only means something after the profile has one to win with -
 * so the order below is the claim, and splitting it would leave each half
 * asserting a state somebody had to set up by hand.
 *
 * What only a browser can answer is that the cookie is really a cookie: that it
 * is kept across a reload and across signing out, that it is sent on the next
 * request, and that a page arrives already wearing it.
 *
 * The account is made by this file, as in tests/e2e/administration.spec.ts and
 * for the same reason: a password in a public repository is a disclosure git
 * keeps forever. The address is its own, so that the two files never touch each
 * other's account.
 */

/** Trilobit\Core\Console\AccountCommand::PASSWORD_LINE. */
const passwordLine = /^ {2}(\S+)$/m;

const email = 'e2e-preference@example.com';

/** The theme config/common.neon starts this build in; anything else is a choice. */
const configured = 'atrium';

const chosen = 'ledger';

test.describe.configure({ mode: 'serial' });

let generated = '';

test.beforeAll(() => {
    const output = execFileSync('php', ['bin/trilobit', 'app:account', email, '--name', 'Bea Brachiopod'], {
        encoding: 'utf8',
    });

    const match = passwordLine.exec(output);
    if (match === null) {
        throw new Error(`app:account printed nothing to sign in with:\n${output}`);
    }

    generated = match[1];
});

test('a device remembers, the profile takes it over, and afterwards the profile wins', async ({ page }) => {
    const problems: string[] = [];
    page.on('console', (message) => {
        if (message.type() === 'error') {
            problems.push(message.text());
        }
    });
    page.on('pageerror', (error) => problems.push(error.message));

    // A visitor who has chosen nothing gets what this build is configured for.
    await page.goto('/_styleguide');
    expect(await themeOf(page)).toBe(configured);

    // Choosing keeps it on the device, across a reload and across pages.
    await choose(page, 'theme', chosen);
    await page.reload();
    expect(await themeOf(page)).toBe(chosen);

    // Signing in with a profile that has no opinion: it takes the device's, so
    // that a choice made before registering is not lost by registering.
    await signIn();
    expect(await themeOf(page)).toBe(chosen);

    // Signing out changes nothing about the way the device looks.
    await page.getByTestId('admin-sign-out').click();
    await expect(page).toHaveURL(/\/admin\/sign-in$/);
    await page.goto('/_styleguide');
    expect(await themeOf(page)).toBe(chosen);

    // The device changes its mind while nobody is signed in.
    await choose(page, 'theme', configured);
    await page.reload();
    expect(await themeOf(page)).toBe(configured);

    // And signing in overrules it, because the profile now has something to
    // say and a device may be borrowed.
    await signIn();
    expect(await themeOf(page)).toBe(chosen);

    await page.goto('/');
    expect(await themeOf(page)).toBe(chosen);

    expect(problems).toEqual([]);

    async function signIn(): Promise<void> {
        await page.goto('/admin/sign-in');
        await page.getByTestId('sign-in-email').fill(email);
        await page.getByTestId('sign-in-password').fill(generated);
        await page.getByTestId('sign-in-submit').click();
        await expect(page).toHaveURL(/\/admin$/);
    }
});

/** The address behind the switch is not a place anybody may post anything to. */
test('a choice this build does not have is refused', async ({ page }) => {
    await page.goto('/_styleguide');

    const refused = await page.evaluate(async () => {
        const response = await fetch('/_preference', {
            method: 'POST',
            credentials: 'same-origin',
            body: new URLSearchParams({ preference: 'theme', value: 'a-theme-nobody-wrote' }),
        });

        return response.status;
    });

    expect(refused).toBe(400);
    expect(await themeOf(page)).toBe(configured);
});
