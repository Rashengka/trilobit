import { expect, type Page, test } from '@playwright/test';

/**
 * Entries under an entry of c-nav, in a real browser: one tree of data, drawn
 * two ways (.ai/plans/10-menu-submenu-a-rozcestniky.md, M1).
 *
 * Ledger unfolds the entries in place, inside the entry they belong to, as deep
 * as the tree goes. Atrium opens a block over the page, two levels deep, when
 * the pointer rests on the entry - and just as well from the keyboard and from
 * a finger, which have no hover to rest with.
 *
 * What every case holds on to is the requirement a submenu most often breaks:
 * the entry that has entries under it still leads to its own page, whatever
 * opened, and on every kind of input.
 *
 * A block over the page has to know when to go away, and every way it does is
 * a case of its own here - the mouse leaving, Escape, a click outside and focus
 * leaving - each opened in a way that keeps the other three from being the
 * reason it closed. Otherwise a case would pass for the wrong way out.
 *
 * The specimens are the style guide's, invented; the real navigation is shown a
 * copy of one of them where a case is about the chrome the navigation sits in.
 */

const path = '/_styleguide/components/nav';
const themes = ['atrium', 'ledger'] as const;

/** The testids of the specimen's entries, from the top of the tree down. */
const entry = {
    parent: 'sample-subnav-collections',
    child: 'sample-subnav-fossils',
    grandchild: 'sample-subnav-trilobites',
    deepest: 'sample-subnav-cambrian',
    after: 'sample-subnav-loans',
} as const;

const toggleOf = (id: string): string => `${id}-toggle`;

async function openIn(page: Page, theme: string): Promise<void> {
    await page.setViewportSize({ width: 1400, height: 900 });
    await page.goto(path);
    await page.evaluate((name) => document.documentElement.setAttribute('data-theme', name), theme);
    await settled(page);
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

/** Somewhere on the page that is neither the navigation nor anything it opens. */
function elsewhere(page: Page) {
    return page.getByTestId('styleguide-headline');
}

/**
 * Puts a copy of the specimen's entry into the navigation of the page itself,
 * under names of its own, so that what is measured is the entry inside the
 * chrome it will live in - held in ledger, a row under the banner in atrium.
 */
async function copyIntoTheNavigation(page: Page): Promise<void> {
    await page.evaluate((id) => {
        const source = document.querySelector(`[data-testid="${id}"]`)?.parentElement;
        const list = document.querySelector('[data-testid="layout-nav"] .c-nav__list');
        if (source === null || source === undefined || list === null) {
            throw new Error('the specimen entry or the navigation of the page is missing');
        }

        const copy = source.cloneNode(true);
        if (!(copy instanceof HTMLElement)) {
            throw new Error('the entry did not copy');
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
    }, entry.parent);
    await settled(page);
}

for (const theme of themes) {
    test(`in ${theme} the entry with entries under it follows its own link when clicked`, async ({ page }) => {
        const problems: string[] = [];
        page.on('pageerror', (error) => problems.push(error.message));
        page.on('console', (message) => {
            if (message.type() === 'error') {
                problems.push(message.text());
            }
        });

        await openIn(page, theme);
        await page.getByTestId(entry.parent).click();

        await expect(page).toHaveURL(/#collections$/);
        expect(problems).toEqual([]);
    });

    test(`in ${theme} the button beside the entry opens and closes it from the keyboard`, async ({ page }) => {
        await openIn(page, theme);
        const button = page.getByTestId(toggleOf(entry.parent));

        await button.focus();
        await page.keyboard.press('Enter');
        await isOpen(page, entry.parent, true);
        await expect(page.getByTestId(entry.child)).toBeVisible();

        await page.keyboard.press('Space');
        await isOpen(page, entry.parent, false);
        await expect(page.getByTestId(entry.child)).toBeHidden();

        await expect(button, 'the button lost the focus it was used with').toBeFocused();
        await expect(page, 'opening followed the link after all').not.toHaveURL(/#/);
    });
}

test.describe('on a touch screen', () => {
    test.use({ hasTouch: true });

    for (const theme of themes) {
        test(`in ${theme} a tap on the button opens the entry and a tap on the entry follows its link`, async ({
            page,
        }) => {
            await openIn(page, theme);

            await page.getByTestId(toggleOf(entry.parent)).tap();
            await isOpen(page, entry.parent, true);
            await expect(page.getByTestId(entry.child)).toBeVisible();
            await expect(page, 'the tap on the button followed the link').not.toHaveURL(/#/);

            await page.getByTestId(toggleOf(entry.parent)).tap();
            await isOpen(page, entry.parent, false);

            await page.getByTestId(entry.parent).tap();
            await expect(page).toHaveURL(/#collections$/);
        });
    }

    test('in atrium a tap outside the open block closes it', async ({ page }) => {
        await openIn(page, 'atrium');

        await page.getByTestId(toggleOf(entry.parent)).tap();
        await isOpen(page, entry.parent, true);

        await elsewhere(page).tap();
        await isOpen(page, entry.parent, false);
    });
});

test('in ledger the entries unfold inside their entry, as deep as the tree goes', async ({ page }) => {
    await openIn(page, 'ledger');

    // Resting on it opens nothing in ledger: the tree is folded by hand.
    await page.getByTestId(entry.parent).hover();
    await page.waitForTimeout(400);
    await isOpen(page, entry.parent, false);

    await page.getByTestId(toggleOf(entry.parent)).click();
    await isOpen(page, entry.parent, true);

    const box = async (id: string) => {
        const found = await page.getByTestId(id).boundingBox();
        if (found === null) {
            throw new Error(`${id} is not drawn`);
        }

        return found;
    };

    const parent = await box(entry.parent);
    const child = await box(entry.child);
    const list = await page.locator(`#${entry.parent}-children`).boundingBox();
    const holder = await page.getByTestId(entry.parent).locator('xpath=..').boundingBox();
    const after = await box(entry.after);
    if (list === null || holder === null) {
        throw new Error('the unfolded list or its entry is not drawn');
    }

    expect(list.y, 'the entries under the entry do not start under it').toBeGreaterThanOrEqual(parent.y + parent.height - 0.5);
    expect(list.x, 'the entries under the entry stick out of it on the left').toBeGreaterThanOrEqual(holder.x - 0.5);
    expect(list.x + list.width, 'the entries under the entry stick out of it on the right').toBeLessThanOrEqual(
        holder.x + holder.width + 0.5,
    );
    expect(child.x, 'the entries under the entry are not set in from it').toBeGreaterThan(parent.x);
    expect(after.y, 'the next entry was not moved down: this is a block over the page, not unfolding').toBeGreaterThanOrEqual(
        list.y + list.height - 0.5,
    );

    // And further down, each level set in from the one above it.
    await page.getByTestId(toggleOf(entry.child)).click();
    await page.getByTestId(toggleOf(entry.grandchild)).click();
    await expect(page.getByTestId(entry.deepest)).toBeVisible();
    expect((await box(entry.grandchild)).x).toBeGreaterThan(child.x);
    expect((await box(entry.deepest)).x).toBeGreaterThan((await box(entry.grandchild)).x);

    // A click folds it back, with everything under it.
    await page.getByTestId(toggleOf(entry.parent)).click();
    await isOpen(page, entry.parent, false);
    await expect(page.getByTestId(entry.child)).toBeHidden();
    await expect(page.getByTestId(entry.deepest)).toBeHidden();
});

/**
 * Ledger holds its navigation in view (.ai/plans/09-chrome-a-sirka-obsahu.md,
 * L1). An entry unfolded inside the held navigation must neither let go of it
 * nor change how far a jump to a heading stops under the banner, because that
 * clearance is measured off what is held - and the navigation, beside the
 * content, covers none of it.
 */
test('in ledger an entry unfolded in the held navigation keeps it held and keeps the jump clearance', async ({
    page,
}) => {
    await page.setViewportSize({ width: 1800, height: 900 });
    await page.goto(path);
    await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'ledger'));
    await copyIntoTheNavigation(page);
    await page.evaluate(() => {
        const filler = document.createElement('div');
        filler.style.position = 'relative';
        filler.style.blockSize = '300vh';
        document.querySelector('.l-shell__main .l-container')?.append(filler);
    });
    await settled(page);

    const clearance = (): Promise<string> =>
        page.evaluate(() => (document.scrollingElement as HTMLElement).style.getPropertyValue('--layout-chrome-offset'));

    const before = await clearance();
    expect(Number.parseFloat(before), 'nothing is held, so there is no clearance to keep').toBeGreaterThan(0);

    await page.getByTestId(`held-${toggleOf(entry.parent)}`).click();
    await isOpen(page, `held-${entry.parent}`, true);
    await settled(page);
    expect(await clearance(), 'unfolding the entry changed how far a jump stops under the banner').toBe(before);

    const top = (): Promise<{ nav: number; child: number; scrolled: number }> =>
        page.evaluate(() => ({
            nav: document.querySelector('[data-testid="layout-nav"]')?.getBoundingClientRect().top ?? Number.NaN,
            child: document.querySelector('[data-testid="held-sample-subnav-fossils"]')?.getBoundingClientRect().top ?? Number.NaN,
            scrolled: window.scrollY,
        }));

    const still = await top();
    await page.mouse.move(1200, 500);
    await page.mouse.wheel(0, 600);
    await page.waitForFunction((from) => window.scrollY >= from + 600, still.scrolled);
    await settled(page);
    const scrolled = await top();

    expect(scrolled.scrolled - still.scrolled, 'the page did not scroll, so nothing was measured').toBeGreaterThanOrEqual(600);
    expect(scrolled.nav, 'the navigation let go when an entry in it was unfolded').toBeCloseTo(0, 0);
    expect(scrolled.child - still.child, 'the unfolded entries scrolled away with the page').toBeCloseTo(0, 0);
    await isOpen(page, `held-${entry.parent}`, true);
    expect(await clearance()).toBe(before);
});

test('in atrium resting on the entry opens a block over the page, two levels deep', async ({ page }) => {
    await openIn(page, 'atrium');

    const specimen = page.locator('[data-styleguide-variant="with entries nested under an entry"] .sg-specimen__label');

    // How far below the entry the next thing on the page is. A distance and not
    // a position, because resting on the entry scrolls it into view first, and
    // everything moves with that - the block pushing the page down is what
    // would change the distance.
    const below = async (): Promise<number> => {
        const label = await specimen.boundingBox();
        const parent = await page.getByTestId(entry.parent).boundingBox();

        return (label?.y ?? Number.NaN) - (parent?.y ?? Number.NaN);
    };
    const distanceBefore = await below();

    await page.getByTestId(entry.parent).hover();
    await isOpen(page, entry.parent, true);

    // Two levels: the entries under it, and theirs - and nothing deeper.
    await expect(page.getByTestId(entry.child)).toBeVisible();
    await expect(page.getByTestId(entry.grandchild)).toBeVisible();
    await expect(page.getByTestId(entry.deepest)).toBeHidden();
    await expect(page.getByTestId(toggleOf(entry.child)), 'a button inside the block opens nothing there').toBeHidden();

    // Over the page, not in it: what comes after has not moved, and the block
    // is what is drawn where it lies.
    expect(await below(), 'the block pushed the page down rather than lying over it').toBeCloseTo(distanceBefore, 0);
    const drawn = await page.getByTestId(entry.child).evaluate((link) => {
        const box = link.getBoundingClientRect();

        return link.contains(document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2));
    });
    expect(drawn, 'something is drawn over the block').toBe(true);

    // The mouse opened it; a click of the same mouse on its button does not
    // shut what the pointer is resting on.
    await page.getByTestId(toggleOf(entry.parent)).click();
    await isOpen(page, entry.parent, true);
});

test.describe('in atrium the open block goes away', () => {
    test('when the mouse leaves it, and not on the way from the entry down into it', async ({ page }) => {
        await openIn(page, 'atrium');

        await page.getByTestId(entry.parent).hover();
        await isOpen(page, entry.parent, true);

        const into = await page.getByTestId(entry.grandchild).boundingBox();
        if (into === null) {
            throw new Error('the block has nothing to move into');
        }

        // In steps, so that the pointer crosses whatever lies between the
        // entry and the block rather than jumping over it.
        await page.mouse.move(into.x + into.width / 2, into.y + into.height / 2, { steps: 12 });
        await page.waitForTimeout(500);
        await isOpen(page, entry.parent, true);

        await elsewhere(page).hover();
        await isOpen(page, entry.parent, false);
    });

    test('on Escape, and the focus goes back to its button', async ({ page }) => {
        await openIn(page, 'atrium');
        const button = page.getByTestId(toggleOf(entry.parent));

        await button.focus();
        await page.keyboard.press('Enter');
        await isOpen(page, entry.parent, true);

        await page.keyboard.press('Tab');
        await expect(page.getByTestId(entry.child), 'Tab from the button does not go into the block').toBeFocused();

        await page.keyboard.press('Escape');
        await isOpen(page, entry.parent, false);
        await expect(button, 'the focus was left on something that is no longer drawn').toBeFocused();
    });

    /**
     * Opened without moving the focus to it, so that focus leaving cannot be
     * the reason it closes; the click is the only thing that happened.
     */
    test('on a click outside it', async ({ page }) => {
        await openIn(page, 'atrium');

        await page.getByTestId(toggleOf(entry.parent)).evaluate((button) => (button as HTMLElement).click());
        await isOpen(page, entry.parent, true);
        expect(
            await page.getByTestId(entry.parent).evaluate((link) => link.parentElement?.contains(document.activeElement)),
            'the focus is inside the entry, so this could close for the wrong reason',
        ).toBe(false);

        await elsewhere(page).click();
        await isOpen(page, entry.parent, false);
    });

    /** No pointer at all: only the keyboard, walking out of the block. */
    test('when the focus leaves it, and not while it moves inside it', async ({ page }) => {
        await openIn(page, 'atrium');
        const button = page.getByTestId(toggleOf(entry.parent));

        await button.focus();
        await page.keyboard.press('Enter');
        await isOpen(page, entry.parent, true);

        // Back to the entry's own link is still inside the entry.
        await page.keyboard.press('Shift+Tab');
        await expect(page.getByTestId(entry.parent)).toBeFocused();
        await isOpen(page, entry.parent, true);

        let steps = 0;
        while (
            await page.getByTestId(entry.parent).evaluate((link) => link.parentElement?.contains(document.activeElement) === true)
        ) {
            await page.keyboard.press('Tab');
            steps += 1;
            expect(steps, 'Tab never left the entry').toBeLessThan(20);
        }

        await expect(page.getByTestId(entry.after), 'Tab out of the block did not land on the next entry').toBeFocused();
        await isOpen(page, entry.parent, false);
    });
});

/**
 * In the navigation of the page, which is where the block will live. The band
 * the navigation is drawn in must not cut the block off, and nothing in the
 * content may be drawn over it.
 */
test('in atrium the block opened in the navigation of the page is neither cut off nor covered', async ({ page }) => {
    await openIn(page, 'atrium');
    await copyIntoTheNavigation(page);

    await page.getByTestId(`held-${entry.parent}`).hover();
    await isOpen(page, `held-${entry.parent}`, true);

    const measured = await page.evaluate(() => {
        const nav = document.querySelector('[data-testid="layout-nav"]');
        const link = document.querySelector('[data-testid="held-sample-subnav-minerals"]');
        if (nav === null || link === null) {
            throw new Error('the navigation or the entry in its block is missing');
        }

        const box = link.getBoundingClientRect();

        return {
            navBottom: nav.getBoundingClientRect().bottom,
            linkTop: box.top,
            onTop: link.contains(document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2)),
        };
    });

    expect(measured.linkTop, 'the block does not reach below the navigation, so this proves nothing').toBeGreaterThan(
        measured.navBottom,
    );
    expect(measured.onTop, 'the block is cut off by the navigation or drawn under the content').toBe(true);
});
