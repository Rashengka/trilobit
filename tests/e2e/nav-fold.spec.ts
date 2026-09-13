import { expect, type Page, test } from '@playwright/test';
import { choose, themeOf } from './preferences';

/**
 * c-nav folded behind a Menu button, and the branch the current page is on
 * (.ai/plans/10-menu-submenu-a-rozcestniky.md, M1, decided 2026-09-13).
 *
 * Ledger on a narrow window stacks the navigation under the banner, and an
 * entry unfolded there made the row as tall as everything under it. So there
 * the whole navigation is folded behind a button, and opens as a column the
 * entries unfold in. Nowhere else is the button drawn - not in atrium at any
 * width, not in ledger beside the page - and without the script it is not
 * drawn at all: the navigation is then simply there, whole.
 *
 * The entry the current page is under unfolds by itself in ledger, where
 * nothing is covered by it. In atrium it is only marked: unfolding there is a
 * block over the page, and a page that opened with its content covered would
 * be a page nobody can read until they find out how to shut it.
 *
 * A theme is chosen the way a person chooses one, and the page is then loaded
 * in it, because what happens on arriving is part of what is measured. The
 * specimens are the style guide's, invented.
 */

const path = '/_styleguide/components/nav';

/** Narrower than ledger's breakpoint (52rem), and wider than it. */
const narrow = { width: 600, height: 900 };
const wide = { width: 1400, height: 900 };

/** The specimen with the current page two levels under an entry, from the top down. */
const current = {
    top: 'sample-current-collections',
    middle: 'sample-current-fossils',
    page: 'sample-current-trilobites',
    aside: 'sample-current-minerals',
} as const;

const toggleOf = (id: string): string => `${id}-toggle`;

/** The navigation of the page itself, as the layout names it. */
const menuButton = 'layout-nav-menu';
const firstEntry = 'nav-home';

async function loadIn(page: Page, theme: string, size: { width: number; height: number }): Promise<void> {
    await page.setViewportSize(wide);
    await page.goto('/_styleguide');
    if ((await themeOf(page)) !== theme) {
        await choose(page, 'theme', theme);
    }

    await page.setViewportSize(size);
    await page.goto(path);
    expect(await themeOf(page), 'the page did not arrive in the theme that was chosen').toBe(theme);
}

/** Two frames, so that whatever the page does after a change of layout has been done. */
async function settled(page: Page): Promise<void> {
    await page.evaluate(
        () => new Promise<void>((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve()))),
    );
}

async function isOpen(page: Page, id: string, open: boolean): Promise<void> {
    await expect(page.getByTestId(toggleOf(id))).toHaveAttribute('aria-expanded', String(open));
}

async function box(page: Page, id: string): Promise<{ x: number; y: number; width: number; height: number }> {
    const found = await page.getByTestId(id).boundingBox();
    if (found === null) {
        throw new Error(`${id} is not drawn`);
    }

    return found;
}

test.describe('in ledger on a narrow window', () => {
    test('the navigation is folded behind a Menu button, and nothing of it is in the tree but the button', async ({
        page,
    }) => {
        await loadIn(page, 'ledger', narrow);

        await expect(page.getByTestId(menuButton)).toBeVisible();
        await expect(page.getByTestId(menuButton)).toHaveAttribute('aria-expanded', 'false');
        await expect(page.getByTestId(firstEntry)).toBeHidden();
        await expect(page.getByRole('navigation', { name: 'Primary' })).toMatchAriaSnapshot(`
            - navigation "Primary":
              - /children: equal
              - button "Menu"
        `);
    });

    test('the Menu button opens and closes it from the keyboard, and the entries follow it in the tab order', async ({
        page,
    }) => {
        await loadIn(page, 'ledger', narrow);
        const button = page.getByTestId(menuButton);

        await button.focus();
        await page.keyboard.press('Enter');
        await expect(button).toHaveAttribute('aria-expanded', 'true');
        await expect(page.getByTestId(firstEntry)).toBeVisible();
        await expect(page.getByRole('navigation', { name: 'Primary' })).toMatchAriaSnapshot(`
            - navigation "Primary":
              - button "Menu" [expanded]
              - list:
                - listitem:
                  - link "Home"
        `);

        await page.keyboard.press('Tab');
        await expect(page.getByTestId(firstEntry), 'Tab from the button does not go into the menu').toBeFocused();
        await page.keyboard.press('Shift+Tab');
        await expect(button).toBeFocused();

        await page.keyboard.press('Space');
        await expect(button).toHaveAttribute('aria-expanded', 'false');
        await expect(page.getByTestId(firstEntry)).toBeHidden();
        await expect(button, 'the button lost the focus it was used with').toBeFocused();
    });

    /**
     * The menu opens as a column, and an entry with entries under it unfolds
     * inside it, under the entry and across the menu - which is what the row
     * could not do. Shown on a copy of the specimen's entry put into the
     * navigation of the page, so that it is measured in the chrome it lives in.
     */
    test('the menu opens as a column, and an entry unfolds inside it, under itself', async ({ page }) => {
        await loadIn(page, 'ledger', narrow);
        await page.evaluate(() => {
            const source = document.querySelector('[data-testid="sample-subnav-collections"]')?.parentElement;
            const list = document.querySelector('[data-testid="layout-nav"] .c-nav__list');
            const copy = source?.cloneNode(true);
            if (!(copy instanceof HTMLElement) || list === null) {
                throw new Error('the specimen entry or the navigation of the page is missing');
            }

            for (const element of copy.querySelectorAll<HTMLElement>('[id]')) {
                element.id = `held-${element.id}`;
            }
            for (const element of copy.querySelectorAll<HTMLElement>('[aria-controls]')) {
                element.setAttribute('aria-controls', `held-${element.getAttribute('aria-controls')}`);
            }
            for (const element of copy.querySelectorAll<HTMLElement>('[data-testid]')) {
                element.dataset.testid = `held-${element.dataset.testid}`;
            }

            list.append(copy);
        });

        await page.getByTestId(menuButton).click();
        await page.getByTestId('held-sample-subnav-collections-toggle').click();
        await isOpen(page, 'held-sample-subnav-collections', true);
        await settled(page);

        const home = await box(page, firstEntry);
        const parent = await box(page, 'held-sample-subnav-collections');
        const child = await box(page, 'held-sample-subnav-fossils');
        const list = await page.locator('[data-testid="layout-nav"] .c-nav__list').boundingBox();
        const entry = await page.getByTestId('held-sample-subnav-collections').locator('xpath=..').boundingBox();
        if (list === null || entry === null) {
            throw new Error('the menu or the entry in it is not drawn');
        }

        expect(parent.x, 'the entries of the menu are not one under another').toBeCloseTo(home.x, 0);
        expect(parent.y, 'the entries of the menu are not one under another').toBeGreaterThan(home.y + home.height - 0.5);
        expect(child.y, 'the entries under the entry do not start under it').toBeGreaterThanOrEqual(parent.y + parent.height - 0.5);
        expect(child.x, 'the entries under the entry are not set in from it').toBeGreaterThan(parent.x);
        expect(entry.width, 'the entry does not run across the menu, so what unfolds in it is squeezed').toBeGreaterThan(
            list.width * 0.8,
        );
    });

    test('the fold is the narrow window\'s alone: widening shows the navigation and takes the button away', async ({
        page,
    }) => {
        await loadIn(page, 'ledger', narrow);
        await expect(page.getByTestId(firstEntry)).toBeHidden();

        await page.setViewportSize(wide);
        await expect(page.getByTestId(menuButton)).toBeHidden();
        await expect(page.getByTestId(firstEntry)).toBeVisible();

        await page.setViewportSize(narrow);
        await expect(page.getByTestId(menuButton)).toBeVisible();
        await expect(page.getByTestId(firstEntry)).toBeHidden();
    });

    /** Where the script does not run there is nobody to open the menu, so it is not folded. */
    test('without the script the whole navigation is shown and no button is drawn', async ({ browser, page }) => {
        await loadIn(page, 'ledger', narrow);

        const bare = await browser.newContext({ javaScriptEnabled: false, viewport: narrow });
        try {
            await bare.addCookies(await page.context().cookies());
            const unscripted = await bare.newPage();
            await unscripted.goto(new URL(path, page.url()).href);

            await expect(unscripted.locator('html')).toHaveAttribute('data-theme', 'ledger');
            await expect(unscripted.getByTestId(menuButton)).toBeHidden();
            await expect(unscripted.getByTestId(firstEntry)).toBeVisible();
        } finally {
            await bare.close();
        }
    });
});

for (const [theme, size, where] of [
    ['atrium', narrow, 'on a narrow window'],
    ['atrium', wide, 'on a wide window'],
    ['ledger', wide, 'on a wide window'],
] as const) {
    test(`in ${theme} ${where} there is no Menu button, and the entries are there to be reached`, async ({ page }) => {
        await loadIn(page, theme, size);

        await expect(page.getByTestId(menuButton)).toBeHidden();
        await expect(page.getByRole('navigation', { name: 'Primary' }).getByRole('button', { name: 'Menu' })).toHaveCount(0);
        await expect(page.getByTestId(firstEntry)).toBeVisible();
    });
}

test.describe('the branch the current page is on', () => {
    test('in ledger it unfolds by itself, down to the page, and nothing beside it does', async ({ page }) => {
        await loadIn(page, 'ledger', wide);

        await isOpen(page, current.top, true);
        await isOpen(page, current.middle, true);
        await expect(page.getByTestId(current.page)).toBeVisible();
        await isOpen(page, current.aside, false);

        await expect(page.getByTestId(current.top)).toHaveAttribute('aria-current', 'true');
        await expect(page.getByTestId(current.middle)).toHaveAttribute('aria-current', 'true');
        await expect(page.getByTestId(current.page)).toHaveAttribute('aria-current', 'page');
        await expect(page.getByTestId(current.aside)).not.toHaveAttribute('aria-current');

        // Unfolded for the reader, and still theirs to fold.
        await page.getByTestId(toggleOf(current.top)).click();
        await isOpen(page, current.top, false);
        await expect(page.getByTestId(current.page)).toBeHidden();
    });

    test('in atrium it is only marked, and no block opens over the page', async ({ page }) => {
        await loadIn(page, 'atrium', wide);

        await isOpen(page, current.top, false);
        await expect(page.getByTestId(current.middle)).toBeHidden();
        await expect(page.getByTestId(current.top)).toHaveAttribute('aria-current', 'true');

        // Marked the way the current page is marked, and unlike an entry that is neither.
        const marker = (id: string): Promise<string> =>
            page.getByTestId(id).evaluate((link) => getComputedStyle(link).borderBlockEndColor);
        expect(await marker(current.top)).toBe(await marker('sample-subnav-overview'));
        expect(await marker(current.top)).not.toBe(await marker('sample-subnav-loans'));

        // Opened by hand, the block shows the page where it is.
        await page.getByTestId(current.top).hover();
        await isOpen(page, current.top, true);
        await expect(page.getByTestId(current.page)).toBeVisible();
    });

    test('a switch of theme follows: atrium folds what ledger unfolded, and ledger unfolds it again', async ({ page }) => {
        await loadIn(page, 'ledger', wide);
        await isOpen(page, current.top, true);

        await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'atrium'));
        await isOpen(page, current.top, false);
        await isOpen(page, current.middle, false);
        await expect(page.getByTestId(current.middle)).toBeHidden();

        await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'ledger'));
        await isOpen(page, current.top, true);
        await isOpen(page, current.middle, true);
        await expect(page.getByTestId(current.page)).toBeVisible();
    });

    test('in ledger on a narrow window the folded menu opens on the branch already unfolded', async ({ page }) => {
        await loadIn(page, 'ledger', narrow);

        const button = page.getByTestId('sample-current-menu');
        await expect(button).toHaveAttribute('aria-expanded', 'false');
        await expect(page.getByTestId(current.page)).toBeHidden();

        await button.click();
        await expect(page.getByTestId(current.page)).toBeVisible();
    });

    /**
     * A snippet Naja redraws brings the server's markup, folded, and so does
     * the way back, which is the markup Naja kept. Either way the branch is
     * unfolded again, and the buttons in it still answer.
     */
    test('in ledger it unfolds again in a snippet Naja redraws, and after history.back()', async ({ page }) => {
        await loadIn(page, 'ledger', wide);
        const snippet = page.getByTestId('sg-nav-snippet');
        await isOpen(page, current.top, true);
        await snippet.locator('nav').evaluate((nav) => nav.setAttribute('data-drawn-first', ''));

        await page.getByTestId('sg-nav-redraw').click();
        await expect(page).toHaveURL(/do=redrawSpecimen/);
        await expect(snippet.locator('[data-drawn-first]'), 'the snippet was not redrawn, so this proves nothing').toHaveCount(0);

        await isOpen(page, current.top, true);
        await isOpen(page, current.middle, true);
        await page.getByTestId(toggleOf(current.top)).click();
        await isOpen(page, current.top, false);

        await page.evaluate(() => history.back());
        await expect(page).not.toHaveURL(/do=redrawSpecimen/);
        await isOpen(page, current.top, true);
        await expect(page.getByTestId(current.page)).toBeVisible();
    });
});

/** The style guide shows the fold at any width, by setting the theme's two tokens on the specimen. */
test('the specimen folded behind a Menu button opens from its button, in both themes', async ({ page }) => {
    for (const theme of ['atrium', 'ledger'] as const) {
        await loadIn(page, theme, wide);
        const button = page.getByTestId('sample-folded-menu');
        const links = page.getByTestId('sample-folded').getByRole('link');

        await expect(button, theme).toHaveAttribute('aria-expanded', 'false');
        await expect(links, theme).toHaveCount(0);

        await button.click();
        await expect(button, theme).toHaveAttribute('aria-expanded', 'true');
        await expect(links.first(), theme).toBeVisible();
    }
});
