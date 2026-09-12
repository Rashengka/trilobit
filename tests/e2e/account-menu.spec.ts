import { execFileSync } from 'node:child_process';

import { expect, type Page, test } from '@playwright/test';

import { openAccountMenu } from './account-menu';
import { addressFor } from './accounts';
import { choose, themeOf } from './preferences';

/**
 * The menu of whoever is signed in, in the administration's banner, in a real
 * browser.
 *
 * What only a browser can answer is what the menu is made of: a native popover.
 * Closing it by a click outside or by Escape is the browser's work and not a
 * line of this project's script, and the panel is placed by the browser against
 * the button that opened it - so both are asserted here, where they happen, and
 * nowhere else.
 *
 * The account is this file's own, made the way tests/e2e/administration.spec.ts
 * makes its own and for the same reason: a password in a public repository is a
 * disclosure git keeps forever.
 */

/** Trilobit\Core\Console\AccountCommand::PASSWORD_LINE. */
const passwordLine = /^ {2}(\S+)$/m;

const displayName = 'Dora Dalmanites';

test.describe.configure({ mode: 'serial' });

/** This copy's address; see tests/e2e/accounts.ts. */
let email = '';
let generated = '';

test.beforeAll(() => {
    email = addressFor('e2e-account-menu@example.com');

    // The host the business this suite works in answers at; see
    // playwright.config.ts.
    const output = execFileSync(
        'php',
        ['bin/trilobit', 'app:account', email, '--tenant', '127.0.0.1', '--name', displayName],
        { encoding: 'utf8' },
    );

    const match = passwordLine.exec(output);
    if (match === null) {
        throw new Error(`app:account printed nothing to sign in with:\n${output}`);
    }

    generated = match[1];
});

/**
 * Signs in and waits for the page it lands on to have loaded.
 *
 * Loaded and not merely addressed: the URL changes as soon as the navigation
 * commits, and everything below measures where the browser put things - which a
 * page whose stylesheet has not arrived yet answers with the unstyled layout,
 * a header stacked a line per element and a panel centred in the window.
 */
async function signIn(page: Page): Promise<void> {
    await page.goto('/admin/sign-in');
    await page.getByTestId('sign-in-email').fill(email);
    await page.getByTestId('sign-in-password').fill(generated);
    await page.getByTestId('sign-in-submit').click();
    await page.waitForURL(/\/admin$/);
}

async function drawIn(page: Page, theme: string): Promise<void> {
    await page.evaluate((name) => document.documentElement.setAttribute('data-theme', name), theme);
}

test('the menu opens from the banner and closes by a click outside it or by Escape', async ({ page }) => {
    const problems: string[] = [];
    page.on('console', (message) => {
        if (message.type() === 'error') {
            problems.push(message.text());
        }
    });
    page.on('pageerror', (error) => problems.push(error.message));

    await signIn(page);

    const trigger = page.getByTestId('admin-account-menu');
    const panel = page.getByTestId('admin-account-menu-panel');

    await expect(trigger).toBeVisible();
    await expect(trigger).toContainText(displayName);
    await expect(panel).toBeHidden();

    await openAccountMenu(page);
    await expect(page.getByTestId('admin-identity')).toHaveText(displayName);
    await expect(page.getByTestId('admin-identity-email')).toHaveText(email);

    await page.keyboard.press('Escape');
    await expect(panel).toBeHidden();

    await openAccountMenu(page);
    await page.getByTestId('admin-headline').click();
    await expect(panel).toBeHidden();

    // A click inside it is not a click outside it: choosing something in the
    // menu leaves the menu where it was.
    await openAccountMenu(page);
    await choose(page, 'theme-mode', 'light');
    await expect(panel).toBeVisible();

    expect(problems).toEqual([]);
});

/**
 * Under the button that opened it, lined up with its right-hand edge, and
 * inside the window - in both themes, because the banner is a different width
 * in each of them and a panel placed by arithmetic would be right in one.
 */
for (const theme of ['atrium', 'ledger']) {
    test(`the panel hangs under the button that opened it, in ${theme}`, async ({ page }) => {
        await signIn(page);
        await drawIn(page, theme);
        await openAccountMenu(page);

        const placed = await page.evaluate(() => {
            const box = (id: string) => {
                const element = document.querySelector(`[data-testid="${id}"]`);
                if (element === null) {
                    throw new Error(`nothing carries ${id}`);
                }

                return element.getBoundingClientRect();
            };

            const trigger = box('admin-account-menu');
            const panel = box('admin-account-menu-panel');

            return {
                triggerBottom: trigger.bottom,
                triggerRight: trigger.right,
                panelTop: panel.top,
                panelRight: panel.right,
                panelLeft: panel.left,
                viewport: document.documentElement.clientWidth,
            };
        });

        expect(placed.panelTop, 'the panel does not start under the button').toBeGreaterThanOrEqual(placed.triggerBottom);
        expect(placed.panelTop - placed.triggerBottom, 'the panel is nowhere near the button').toBeLessThan(24);
        expect(Math.abs(placed.panelRight - placed.triggerRight), 'the panel is not lined up with the button').toBeLessThanOrEqual(1);
        expect(placed.panelLeft).toBeGreaterThanOrEqual(0);
        expect(placed.panelRight).toBeLessThanOrEqual(placed.viewport);
    });
}

/**
 * In ledger the banner stays in view while the page scrolls under it, and the
 * menu opens out of that held banner. The panel is a popover, drawn in the top
 * layer rather than in any layer the stylesheet names, so nothing held can be
 * drawn over it - which is asserted by asking the browser what is at the
 * middle of the panel, after the page has scrolled.
 */
test('the panel opened from the held banner is drawn over everything, in ledger', async ({ page }) => {
    await signIn(page);
    await drawIn(page, 'ledger');

    await page.evaluate(() => {
        const filler = document.createElement('div');
        filler.style.position = 'relative';
        filler.style.blockSize = '300vh';
        document.querySelector('[data-testid="admin-content"] .l-container')?.prepend(filler);
    });
    await page.mouse.move(10, 10);
    await page.mouse.wheel(0, 600);
    await page.waitForFunction(() => window.scrollY >= 600);

    await openAccountMenu(page);

    const drawn = await page.evaluate(() => {
        const banner = document.querySelector('[data-testid="admin-header"]');
        const trigger = document.querySelector('[data-testid="admin-account-menu"]');
        const panel = document.querySelector('[data-testid="admin-account-menu-panel"]');
        if (banner === null || trigger === null || panel === null) {
            throw new Error('the banner, the button or the panel is missing');
        }

        const box = panel.getBoundingClientRect();

        return {
            scrolled: window.scrollY,
            bannerTop: banner.getBoundingClientRect().top,
            triggerBottom: trigger.getBoundingClientRect().bottom,
            panelTop: box.top,
            onTop: panel.contains(document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2)),
        };
    });

    // Asked first, because a click scrolls whatever it clicks into view: a
    // banner that was not held would be brought back to the top of the page
    // by opening the menu, and would then read as held.
    expect(drawn.scrolled, 'opening the menu scrolled the page back to its top').toBeGreaterThanOrEqual(600);
    expect(drawn.bannerTop, 'the banner scrolled away, so nothing held is under the panel').toBeCloseTo(0, 0);
    expect(drawn.panelTop, 'the panel does not hang under the button').toBeGreaterThanOrEqual(drawn.triggerBottom);
    expect(drawn.onTop, 'something is drawn over the panel').toBe(true);
});

test('a theme and a mode chosen in the menu are on the page and still there after a reload', async ({ page }) => {
    await signIn(page);
    await openAccountMenu(page);

    await choose(page, 'theme', 'ledger');
    await choose(page, 'theme-mode', 'dark');

    expect(await themeOf(page)).toBe('ledger');
    expect(await page.evaluate(() => document.documentElement.getAttribute('data-theme-mode'))).toBe('dark');

    await page.reload();

    expect(await themeOf(page)).toBe('ledger');
    expect(await page.evaluate(() => document.documentElement.getAttribute('data-theme-mode'))).toBe('dark');

    await openAccountMenu(page);
    await expect(page.getByTestId('theme-choice-ledger')).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByTestId('theme-mode-choice-dark')).toHaveAttribute('aria-pressed', 'true');
});

test('the public site is one click away in the menu', async ({ page }) => {
    await signIn(page);
    await openAccountMenu(page);

    await page.getByTestId('admin-public-link').click();

    await expect(page).toHaveURL(/\/$/);
    await expect(page.getByTestId('layout')).toBeVisible();
});

test('signing out from the menu ends the session', async ({ page }) => {
    await signIn(page);
    await openAccountMenu(page);

    const signOut = page.getByTestId('admin-sign-out');
    await expect(signOut.locator('svg.c-icon')).toHaveCount(1);
    await signOut.click();

    await expect(page).toHaveURL(/\/$/);

    await page.goto('/admin');
    await expect(page).toHaveURL(/\/admin\/sign-in$/);
});

/**
 * The band ends with its own padding under the last thing it holds, in both
 * themes. Before the menu, the row of who was signed in sat under the header
 * component rather than inside it, so it had none - and its buttons sat on the
 * banner's bottom rule.
 *
 * What is measured is everything the banner holds except the header component
 * itself, whose box is the padding being asked about: counted in, it would put
 * the lowest edge on the banner's edge whatever the content did.
 */
for (const theme of ['atrium', 'ledger']) {
    test(`nothing in the banner sits on its bottom edge, in ${theme}`, async ({ page }) => {
        await signIn(page);
        await drawIn(page, theme);

        const measured = await page.evaluate(() => {
            const banner = document.querySelector('[data-testid="admin-header"]');
            const header = banner?.querySelector('.c-site-header');
            if (!(banner instanceof HTMLElement) || !(header instanceof HTMLElement)) {
                throw new Error('the administration has no banner with a header in it');
            }

            let lowest = 0;
            for (const element of banner.querySelectorAll('*')) {
                if (element === header) {
                    continue;
                }

                const box = element.getBoundingClientRect();
                if (box.width > 0 && box.height > 0) {
                    lowest = Math.max(lowest, box.bottom);
                }
            }

            const top = banner.getBoundingClientRect().top;

            return {
                space: top + banner.clientTop + banner.clientHeight - lowest,
                padding: Number.parseFloat(getComputedStyle(header).paddingBlockEnd),
            };
        });

        expect(measured.padding, 'the header component has no padding to leave').toBeGreaterThan(0);
        expect(measured.space, 'something in the banner sits closer to its edge than the header pads').toBeGreaterThanOrEqual(
            measured.padding - 0.5,
        );
    });
}
