import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

import { expect, test } from '@playwright/test';

/**
 * Signing in, in a real browser.
 *
 * The suite makes its own account rather than signing in as one somebody put
 * in the repository, because a password in a public repository is a disclosure
 * git keeps forever and one nobody could rotate. `bin/trilobit app:account`
 * generates it and prints it once; that one line is the only thing this file
 * reads out of the output, and its shape is held still by
 * Trilobit\Tests\Integration\Console\AccountCommandTest.
 *
 * It runs against the database this checkout is configured for, and it writes
 * to it: the migrations are brought up to date and one account is created or
 * given a new password. The address is the reserved documentation domain and
 * belongs to this suite alone, so a person's own account is never touched.
 *
 * What is proved here and nowhere else is the part only a browser can answer:
 * that the redirect a visitor gets is one their browser follows to a page that
 * works, that a form posted from that page carries what the framework's
 * same-site check wants, and that the session that comes out of it survives
 * the next navigation.
 */

/** Trilobit\Core\Console\AccountCommand::PASSWORD_LINE. */
const passwordLine = /^ {2}(\S+)$/m;

const email = 'e2e@example.com';
const displayName = 'Alice Ammonite';

interface Manifest {
    readonly modules: readonly { readonly name: string }[];
}

/**
 * The modules this checkout is built for, read from the file the asset build
 * reads. Written down here instead, the expected number of menu entries would
 * be a second place to keep in step with config/modules.neon.
 */
function enabledModules(): string[] {
    const manifest = JSON.parse(readFileSync('var/build/modules.json', 'utf8')) as Manifest;

    return manifest.modules.map((module) => module.name);
}

function trilobit(...arguments_: string[]): string {
    return execFileSync('php', ['bin/trilobit', ...arguments_], { encoding: 'utf8' });
}

/**
 * One worker for this file, in order.
 *
 * The rest of the browser suite runs fully in parallel, and this file cannot:
 * its setup runs the migrations and creates an account, and two workers doing
 * that at the same moment race each other into a duplicate key. Serial mode is
 * what makes the setup run once instead of once per worker.
 */
test.describe.configure({ mode: 'serial' });

let generated = '';

test.beforeAll(() => {
    trilobit('migrations:migrate', '--no-interaction');

    const output = trilobit('app:account', email, '--name', displayName);
    const match = passwordLine.exec(output);
    if (match === null) {
        throw new Error(`app:account printed nothing to sign in with:\n${output}`);
    }

    generated = match[1];
});

test('the administration sends a visitor who is not signed in to the sign-in page', async ({ page }) => {
    const response = await page.goto('/admin');

    expect(response?.status()).toBe(200);
    await expect(page).toHaveURL(/\/admin\/sign-in$/);
    await expect(page.getByTestId('sign-in-form')).toBeVisible();
    await expect(page.getByTestId('admin-menu')).toHaveCount(0);
});

test('signing in opens the administration, and signing out closes it again', async ({ page }) => {
    const consoleErrors: string[] = [];
    page.on('console', (message) => {
        if (message.type() === 'error') {
            consoleErrors.push(message.text());
        }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.goto('/admin/sign-in');
    await page.getByTestId('sign-in-email').fill(email);
    await page.getByTestId('sign-in-password').fill(generated);
    await page.getByTestId('sign-in-submit').click();

    await expect(page).toHaveURL(/\/admin$/);
    await expect(page.getByTestId('admin-headline')).toHaveText('Overview');
    await expect(page.getByTestId('admin-identity')).toHaveText(displayName);
    await expect(page.getByTestId('admin-identity-email')).toHaveText(email);

    // Exactly what the enabled modules contributed, in a real page. The same
    // claim is made for all eight builds in the combination suite; this is the
    // one build a browser can be pointed at.
    const modules = enabledModules();
    await expect(page.getByTestId('admin-menu').locator('.c-nav__link')).toHaveCount(modules.length);
    for (const module of modules) {
        await expect(page.getByTestId(`admin-menu-${module}`)).toBeVisible();
    }

    // The session survives a navigation of its own, which is the half a single
    // redirect after signing in would not have shown.
    await page.goto('/admin');
    await expect(page.getByTestId('admin-headline')).toHaveText('Overview');

    await page.getByTestId('admin-sign-out').click();
    await expect(page).toHaveURL(/\/admin\/sign-in$/);

    await page.goto('/admin');
    await expect(page).toHaveURL(/\/admin\/sign-in$/);

    expect(consoleErrors).toEqual([]);
});

test('a wrong password leaves you on the sign-in page and says so', async ({ page }) => {
    await page.goto('/admin/sign-in');
    await page.getByTestId('sign-in-email').fill(email);
    await page.getByTestId('sign-in-password').fill('not the one that was set');
    await page.getByTestId('sign-in-submit').click();

    await expect(page).toHaveURL(/\/admin\/sign-in$/);
    await expect(page.getByTestId('sign-in-error')).toBeVisible();
    await expect(page.getByTestId('admin-menu')).toHaveCount(0);
});
