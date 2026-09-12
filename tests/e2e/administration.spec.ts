import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

import { expect, test } from '@playwright/test';

import { openAccountMenu } from './account-menu';

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

/**
 * The other kind of account, and the reason there is a second one at all.
 *
 * `app:account` without `--tenant` makes the administrator of the installation:
 * somebody who belongs to no business, holds no role in one, and is therefore
 * refused on every page of a business's administration. What that person meets
 * instead is the claim only a browser can make - that signing in lands them
 * somewhere they may be, and that nothing they are shown leads anywhere they
 * are not.
 */
const installationEmail = 'e2e-installation@example.com';
const installationName = 'Cora Crinoid';

/**
 * The host the business this suite administers answers at, which is the one
 * `playwright.config.ts` creates and the one the browser arrives at.
 *
 * `app:account` takes a host rather than an identifier and needs one: without
 * it the account made would administer the installation instead, which belongs
 * to no business and therefore holds nothing in this one. Naming it here is
 * what makes the account this suite signs in as an administrator of the site it
 * then opens.
 */
const host = '127.0.0.1';

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

/**
 * Which module a menu entry leads into, read off its address.
 *
 * One shape now: a section of a module's administration, `/admin/cms/pages`,
 * where the backoffice has one root and the module is the first segment under
 * it (R10). It used to have to answer for a second - a module's own public page,
 * `/shop` - because a module with nothing to administer put that on the bar
 * instead; nothing does now, and the arm that read it is kept because an entry
 * that ever led out of the administration again would come back as an empty
 * answer here rather than as a module name that happens to fit.
 */
function moduleOfHref(href: string): string {
    const segments = href.split('/').filter((segment) => segment !== '');

    return (segments[0] === 'admin' ? segments[1] : segments[0]) ?? '';
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
let generatedForTheInstallation = '';

/** The one line of `app:account` output there is anything to read. */
function passwordOf(output: string): string {
    const match = passwordLine.exec(output);
    if (match === null) {
        throw new Error(`app:account printed nothing to sign in with:\n${output}`);
    }

    return match[1];
}

/** Signs in through the form, the way somebody arriving at the address does. */
async function signIn(page: import('@playwright/test').Page, address: string, entered: string): Promise<void> {
    await page.goto('/admin/sign-in');
    await page.getByTestId('sign-in-email').fill(address);
    await page.getByTestId('sign-in-password').fill(entered);
    await page.getByTestId('sign-in-submit').click();
}

/** Every address the administration bar leads to, in the order it draws them. */
async function menuAddresses(page: import('@playwright/test').Page): Promise<string[]> {
    return page
        .getByTestId('admin-menu')
        .locator('.c-nav__link')
        .evaluateAll((elements) => elements.map((element) => element.getAttribute('href') ?? ''));
}

test.beforeAll(() => {
    trilobit('migrations:migrate', '--no-interaction');

    generated = passwordOf(trilobit('app:account', email, '--tenant', host, '--name', displayName));
    generatedForTheInstallation = passwordOf(
        trilobit('app:account', installationEmail, '--name', installationName),
    );
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

    // Who is signed in is in their menu, which is closed until somebody opens it.
    await openAccountMenu(page);
    await expect(page.getByTestId('admin-identity')).toHaveText(displayName);
    await expect(page.getByTestId('admin-identity-email')).toHaveText(email);

    // The way back and then the sections, in a real page. The same claim is
    // made for all eight builds in the combination suite; this is the one build
    // a browser can be pointed at.
    //
    // What is counted is which modules are represented, not how many entries
    // each one contributed: a module with an administration worth the name has
    // several sections, and one entry apiece would make the rule impossible to
    // state without rewriting it every time a module grows a page. Nor is the
    // set of them the set of enabled modules any more - a module contributes an
    // entry when it has an administration page to contribute one for, and two
    // of the three have none yet - so what is asserted of each is that it leads
    // into a module this build was made with.
    const links = page.getByTestId('admin-menu').locator('.c-nav__link');
    const drawn = await links.evaluateAll((elements) =>
        elements.map((element) => ({
            href: element.getAttribute('href') ?? '',
            label: element.textContent?.trim() ?? '',
        })),
    );

    expect(drawn.length).toBeGreaterThan(0);
    for (const entry of drawn) {
        expect(entry.href, 'a menu entry drawn without an address').not.toBe('');
        expect(entry.label, 'a menu entry with nothing to call it').not.toBe('');
    }

    // The first entry is the way back to where this person's administration
    // begins, which for somebody administering a business is the overview. The
    // mark in the banner leads to the same address, and it is the same answer
    // behind both rather than two that agree today.
    expect(drawn[0].href, 'the bar does not begin with the way back').toBe('/admin');
    await expect(page.getByTestId('admin-home-link')).toHaveAttribute('href', drawn[0].href);

    const sections = drawn.slice(1);
    expect(sections.length, 'the bar held the way back and nothing else').toBeGreaterThan(0);

    const represented = [...new Set(sections.map((entry) => moduleOfHref(entry.href)))].sort();
    for (const module of represented) {
        expect(enabledModules(), 'the bar leads into a module this build was not made with').toContain(module);
    }

    // The session survives a navigation of its own, which is the half a single
    // redirect after signing in would not have shown.
    await page.goto('/admin');
    await expect(page.getByTestId('admin-headline')).toHaveText('Overview');

    // Signing out is the application's own act and not the administration's:
    // the address carries no admin/ and it leaves nobody inside a section they
    // are no longer signed in to.
    await openAccountMenu(page);
    await page.getByTestId('admin-sign-out').click();
    await expect(page).toHaveURL(/\/$/);

    await page.goto('/admin');
    await expect(page).toHaveURL(/\/admin\/sign-in$/);

    expect(consoleErrors).toEqual([]);
});

/**
 * The other scope, in a browser: the administrator of the installation.
 *
 * This is the case the whole slice exists for, and it is one page-load away
 * from looking broken. The account holds nothing in any business, so every
 * page of a business's administration refuses it - and being refused on the
 * page you are sent to after signing in is a working application behaving like
 * a broken one. So what is asserted is the whole way in: where the form leaves
 * them, that the section it leaves them in draws what it is for, and that
 * nothing they are shown leads anywhere they would be turned away from.
 */
test('the administrator of the installation signs in to their own section', async ({ page }) => {
    await signIn(page, installationEmail, generatedForTheInstallation);

    await expect(page).toHaveURL(/\/admin\/installation$/);
    await expect(page.getByTestId('installation-headline')).toHaveText('Installation');
    await openAccountMenu(page);
    await expect(page.getByTestId('admin-identity')).toHaveText(installationName);

    // The way into the section is the signpost, and it is built from the same
    // rows the bar is - so following it is also a check that the two agree.
    await page.getByTestId('admin-signpost-businesses').click();

    await expect(page).toHaveURL(/\/admin\/installation\/businesses$/);
    await expect(page.getByTestId('business-list')).toContainText('Trilobit E2E');
});

test('the administrator of the installation is shown no way into a business, and has none', async ({ page }) => {
    await signIn(page, installationEmail, generatedForTheInstallation);

    const addresses = await menuAddresses(page);
    expect(addresses.length, 'the installation administrator was drawn no menu at all').toBeGreaterThan(0);

    const intoABusiness = addresses.filter(
        (href) => href.startsWith('/admin') && !href.startsWith('/admin/installation'),
    );
    expect(intoABusiness, 'the bar drew a way into a business administration').toEqual([]);

    // The mark in the banner is a destination like any other, and for this
    // person it is their own section rather than the overview of a business.
    // So is the first entry of the bar, which is where the way back was looked
    // for and where it was not.
    await expect(page.getByTestId('admin-home-link')).toHaveAttribute('href', '/admin/installation');
    expect(addresses[0], 'the bar does not begin with the way back').toBe('/admin/installation');

    // Typing /admin - the one address of the administration anybody knows -
    // takes them to the administration they have rather than telling them it is
    // not theirs to open. It is the address the administration begins at and
    // the only one that answers this way; see
    // Trilobit\Core\Presentation\Admin\AdminPresenter::isWhereTheAdministrationBegins().
    const entered = await page.goto('/admin');
    expect(entered?.status(), 'the address the administration begins at did not answer').toBe(200);
    await expect(page).toHaveURL(/\/admin\/installation$/);

    // And a page of a business's administration is still refused, which is the
    // half that keeps the line above from being "every refusal is a redirect".
    //
    // Both halves are asserted because either alone would be satisfied by the
    // wrong thing: a 403 is the status of any refusal, and the sentence is the
    // one this gate refuses with. The body carries it because this server runs
    // in debug mode - config/common.neon leaves the framework's exceptions
    // uncaught - and what is fixed either way is the status.
    const refused = await page.goto('/admin/cms/pages');
    expect(refused?.status(), 'a section of a business answered somebody with nothing in one').toBe(403);
    await expect(page.locator('body')).toContainText('This is not yours to open.');
});

test('somebody administering a business is shown no way into the installation', async ({ page }) => {
    await signIn(page, email, generated);

    const addresses = await menuAddresses(page);
    expect(addresses.length).toBeGreaterThan(0);
    expect(
        addresses.filter((href) => href.startsWith('/admin/installation')),
        'the bar drew a way into the installation section',
    ).toEqual([]);

    // The same shape as the refusal above, and refused for the opposite
    // reason: this account holds everything in one business and nothing over
    // the installation the business is one of.
    const refused = await page.goto('/admin/installation');
    expect(refused?.status(), 'the installation section answered somebody who administers a business').toBe(403);
    await expect(page.locator('body')).toContainText('This is not yours to open.');
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
