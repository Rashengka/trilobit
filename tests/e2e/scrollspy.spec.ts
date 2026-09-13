import { expect, type Locator, type Page, test } from '@playwright/test';

/**
 * c-scrollspy in a real browser: the entry of the section being read carries
 * aria-current="location", and no other entry does.
 *
 * "Being read" is measured against the part of the window the chrome leaves
 * free: a section counts once its top has come up into the upper quarter of
 * it, the reading line being a quarter of the way down from the lower edge of
 * whatever the theme holds over the content (--layout-chrome-offset, which
 * assets/chrome.ts measures). Both themes hold a banner over the content on a
 * wide window, and the cases put a section's top just above and just below
 * that line - where "just above" is below the line a spy blind to the chrome
 * would draw, a quarter of the way down the whole window. That is the case
 * that tells the two apart.
 *
 * Nothing listens for scrolling on the scrollspy's account: an
 * IntersectionObserver is told when a section crosses the line. The page is
 * loaded with every listener for the scroll event counted, and it may have no
 * more of them than a page of the guide with no scrollspy on it. Compared
 * rather than held at nought, because what the shared bundle listens for on
 * every page - c-carousel follows the scrolling of its tracks from the
 * document - is not the scrollspy's, and counting it would fail the case for
 * another component's reason.
 */

const themes = ['atrium', 'ledger'] as const;

const wide = { width: 1400, height: 900 };

/** Every place the specimen's entries lead to, in order. */
const sections = ['Finding the site', 'Lifting the slab', 'Splitting the rock', 'Cleaning the find', 'Writing it up'];

/** How far down the part of the window the chrome leaves free the reading line is (assets/scrollspy.ts). */
const READING = 0.25;

async function drawIn(page: Page, theme: string): Promise<void> {
    await page.evaluate((name) => document.documentElement.setAttribute('data-theme', name), theme);
}

/** How much of the top of the window the chrome covers, as the page measured it. */
async function chromeOffset(page: Page): Promise<number> {
    return page.evaluate(() =>
        Number.parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--layout-chrome-offset')) || 0,
    );
}

/** Where the reading line is, from the top of the window. */
async function readingLine(page: Page): Promise<number> {
    const offset = await chromeOffset(page);

    return offset + (wide.height - offset) * READING;
}

/**
 * Scrolls the page so that the top of the section $name ends up $below pixels
 * under the reading line, and does it again until the chrome has stopped
 * changing size: atrium makes its bands smaller once the page has scrolled,
 * which moves the line after the first scroll.
 */
async function bringTopTo(page: Page, name: string, below: number): Promise<void> {
    const section = page.getByRole('heading', { name, level: 2 }).locator('xpath=ancestor::section[1]');

    for (let attempt = 0; attempt < 5; attempt++) {
        const at = (await readingLine(page)) + below;
        await section.evaluate((element, target) => {
            window.scrollBy(0, element.getBoundingClientRect().top - target);
        }, at);
        await page.waitForTimeout(400);

        const top = await section.evaluate((element) => element.getBoundingClientRect().top);
        if (Math.abs(top - ((await readingLine(page)) + below)) < 2) {
            return;
        }
    }
}

function entry(spy: Locator, name: string): Locator {
    return spy.getByRole('link', { name });
}

async function current(spy: Locator): Promise<string[]> {
    return (await spy.locator('[aria-current="location"]').allTextContents()).map((text) => text.trim());
}

test.describe('c-scrollspy', () => {
    test.use({ viewport: wide });

    test.beforeEach(async ({ page }) => {
        await page.addInitScript(() => {
            const counted = window as unknown as { scrollListeners: number };
            counted.scrollListeners = 0;
            const original = EventTarget.prototype.addEventListener;
            EventTarget.prototype.addEventListener = function (
                this: EventTarget,
                type: string,
                ...rest: [EventListenerOrEventListenerObject | null, (boolean | AddEventListenerOptions)?]
            ): void {
                if (type === 'scroll' || type === 'scrollend') {
                    counted.scrollListeners++;
                }
                original.call(this, type, ...rest);
            };
        });
    });

    test('is a navigation of the sections, and marks none while none has been reached', async ({ page }) => {
        await page.goto('/_styleguide/components/scrollspy');
        const spy = page.getByTestId('sample-scrollspy');

        await expect(spy).toMatchAriaSnapshot(`
            - navigation "Sections of the field notes":
              - list:
                - listitem:
                  - link "Finding the site"
                - listitem:
                  - link "Lifting the slab"
                - listitem:
                  - link "Splitting the rock"
                - listitem:
                  - link "Cleaning the find"
                - listitem:
                  - link "Writing it up"
        `);

        await bringTopTo(page, 'Finding the site', 40);
        await expect.poll(() => current(spy)).toEqual([]);
        await expect(spy.locator('[aria-current]')).toHaveCount(0);
    });

    for (const theme of themes) {
        test(`marks the section whose top has come up to the reading line under the chrome, in ${theme}`, async ({ page }) => {
            await page.goto('/_styleguide/components/scrollspy');
            await drawIn(page, theme);
            await expect.poll(() => chromeOffset(page), 'nothing is held over the content').toBeGreaterThan(40);

            const spy = page.getByTestId('sample-scrollspy');

            // A link followed: the jump stops under the chrome, above the line.
            await entry(spy, 'Splitting the rock').click();
            await expect.poll(() => current(spy)).toEqual(['Splitting the rock']);

            // The next one is in view, and not up to the line yet.
            await bringTopTo(page, 'Cleaning the find', 20);
            await expect.poll(() => current(spy)).toEqual(['Splitting the rock']);

            // Up past the line - and still below a quarter of the whole
            // window, which is where a spy blind to the chrome would draw it.
            await bringTopTo(page, 'Cleaning the find', -20);
            expect(await readingLine(page) - 20, 'the case does not tell the two lines apart').toBeGreaterThan(wide.height * READING);
            await expect.poll(() => current(spy)).toEqual(['Cleaning the find']);
            await expect(entry(spy, 'Cleaning the find')).toHaveAttribute('aria-current', 'location');

            // And back down past the line again.
            await bringTopTo(page, 'Cleaning the find', 20);
            await expect.poll(() => current(spy)).toEqual(['Splitting the rock']);

            await bringTopTo(page, 'Lifting the slab', -20);
            await expect.poll(() => current(spy)).toEqual(['Lifting the slab']);

            const withTheSpy = await page.evaluate(() => (window as unknown as { scrollListeners: number }).scrollListeners);
            await page.goto('/_styleguide/components/badge');
            await expect(page.locator('.c-scrollspy')).toHaveCount(0);
            const without = await page.evaluate(() => (window as unknown as { scrollListeners: number }).scrollListeners);
            expect(withTheSpy, 'the scrollspy listens for scrolling').toBe(without);
        });
    }

    test('draws the entry of the section being read in the theme\'s accent', async ({ page }) => {
        await page.goto('/_styleguide/components/scrollspy');
        const spy = page.getByTestId('sample-scrollspy');

        const colours = new Set<string>();
        for (const theme of themes) {
            // Followed again in each theme: the two lay the sections out
            // differently, so the same scroll position is another section.
            await drawIn(page, theme);
            await entry(spy, 'Lifting the slab').click();
            await expect.poll(() => current(spy)).toEqual(['Lifting the slab']);

            const on = entry(spy, 'Lifting the slab');
            const off = entry(spy, 'Writing it up');
            const [marked, plain] = await Promise.all([
                on.evaluate((node) => getComputedStyle(node).backgroundColor + getComputedStyle(node).color),
                off.evaluate((node) => getComputedStyle(node).backgroundColor + getComputedStyle(node).color),
            ]);
            expect(marked, theme).not.toBe(plain);
            colours.add(marked);
        }

        expect(colours.size).toBe(2);
    });

    /**
     * The specimen is a snippet, and Naja drawing it again brings sections
     * and entries nobody is watching yet - on the way back through history
     * too. Both have to be followed again, and only once.
     */
    test('follows the sections again after Naja redraws them, and after history.back()', async ({ page }) => {
        await page.goto('/_styleguide/components/scrollspy');
        const spy = page.getByTestId('sample-scrollspy');

        await page.getByTestId('sg-scrollspy-redraw').click();
        await expect(page).toHaveURL(/do=redrawSpecimen/);

        await bringTopTo(page, 'Splitting the rock', -20);
        await expect.poll(() => current(spy)).toEqual(['Splitting the rock']);

        await page.evaluate(() => history.back());
        await expect(page).not.toHaveURL(/do=redrawSpecimen/);

        await bringTopTo(page, 'Writing it up', -20);
        await expect.poll(() => current(spy)).toEqual(['Writing it up']);
        await bringTopTo(page, 'Finding the site', -20);
        await expect.poll(() => current(spy)).toEqual(['Finding the site']);

        for (const name of sections) {
            await expect(entry(spy, name)).toHaveCount(1);
        }
    });
});
