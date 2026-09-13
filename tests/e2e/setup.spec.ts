import { execFileSync, spawn, type ChildProcess } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { createServer } from 'node:net';

import { expect, test } from '@playwright/test';

/**
 * A fresh installation, set up through the wizard in a real browser and then
 * signed in to (.ai/plans/23-instalace-na-zelene-louce.md).
 *
 * It cannot run on the server playwright.config.ts starts. That server is over
 * this checkout's own database, which is migrated and holds an administrator
 * of the installation - tests/e2e/administration.spec.ts makes one, and a
 * seeded working copy has one - and on such an installation the wizard is not
 * there at all (decision O3). So this file starts a server of its own, over an
 * empty database made for it by tests/e2e/fresh-database.php and dropped once
 * it is done. The server runs the same checkout in the same mode; only the
 * database it is pointed at differs.
 *
 * What only a browser shows is the whole road: that the button installing the
 * tables leads on to the account, that the form it posts carries what the
 * framework's same-site check wants, that the wizard ends on the sign-in page
 * rather than signing anybody in (decision O1), that the password chosen in it
 * is the one that works there, and that the account made both lands in the
 * business with the installation one entry away (decision O2). And, last, that
 * the wizard has gone.
 */

test.describe.configure({ mode: 'serial' });

let server: ChildProcess | undefined;
let schema = '';
let base = '';

function php(...arguments_: string[]): string {
    return execFileSync('php', arguments_, { encoding: 'utf8' }).trim();
}

/** A port nothing on this machine is listening on, asked of the system rather than guessed. */
async function freePort(): Promise<number> {
    return new Promise((resolve, reject) => {
        const probe = createServer();
        probe.once('error', reject);
        probe.listen(0, '127.0.0.1', () => {
            const address = probe.address();
            probe.close(() => {
                if (address === null || typeof address === 'string') {
                    reject(new Error('the system named no port'));
                } else {
                    resolve(address.port);
                }
            });
        });
    });
}

/** Waits until the server started below answers anything at all. */
async function answering(url: string): Promise<void> {
    const deadline = Date.now() + 15_000;
    while (Date.now() < deadline) {
        try {
            await fetch(url, { redirect: 'manual', signal: AbortSignal.timeout(2_000) });

            return;
        } catch {
            await new Promise((resolve) => setTimeout(resolve, 100));
        }
    }

    throw new Error(`the server for the setup spec did not answer at ${url}`);
}

test.beforeAll(async () => {
    // A database per copy of this file that can run at once: one per browser
    // when several are asked for, and one per repetition when a race is being
    // hunted. Sharing one, each copy would drop what another was setting up.
    const info = test.info();
    schema = php('tests/e2e/fresh-database.php', 'make', `${info.project.name}${info.repeatEachIndex}`);

    const port = await freePort();
    base = `http://127.0.0.1:${port}`;
    server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', 'www'], {
        env: { ...process.env, TRILOBIT_ENV: 'dev', TRILOBIT_DB_NAME: schema },
        stdio: 'ignore',
    });

    await answering(`${base}/_setup`);
});

test.afterAll(() => {
    server?.kill();
    if (schema !== '') {
        php('tests/e2e/fresh-database.php', 'drop', schema);
    }
});

test('a fresh installation is set up through the wizard, signed in to, and has no wizard afterwards', async ({ page }) => {
    const email = 'founder@example.com';
    const password = randomBytes(18).toString('base64url');

    await page.goto(`${base}/_setup`);
    await page.getByTestId('setup-install-submit').click();

    await expect(page.getByTestId('setup-administrator')).toBeVisible();
    await expect(page.getByTestId('setup-host')).toContainText('127.0.0.1');

    await page.getByTestId('setup-email').fill(email);
    await page.getByTestId('setup-name').fill('Ada Ammonite');
    await page.getByTestId('setup-password').fill(password);
    await page.getByTestId('setup-password-again').fill(password);
    await page.getByTestId('setup-business').fill('Ammonite Bikes');
    await page.getByLabel('I run the business myself as well').check();
    await page.getByTestId('setup-finish').click();

    // The sign-in page, carrying the flash message that says the installation is ready.
    await expect(page).toHaveURL(/\/admin\/sign-in(\?|$)/);
    await expect(page.getByTestId('admin-menu')).toHaveCount(0);

    await page.getByTestId('sign-in-email').fill(email);
    await page.getByTestId('sign-in-password').fill(password);
    await page.getByTestId('sign-in-submit').click();

    await expect(page).toHaveURL(/\/admin$/);
    await expect(page.getByTestId('admin-headline')).toHaveText('Overview');
    // The installation is one entry away: its section's own entry, Businesses (InstallationMenu).
    await expect(page.getByTestId('admin-menu').locator('a[href^="/admin/installation"]')).toHaveCount(1);

    const afterwards = await page.request.get(`${base}/_setup`);
    expect(afterwards.status()).toBe(404);
});
