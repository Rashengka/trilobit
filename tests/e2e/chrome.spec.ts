import { expect, type Page, test } from '@playwright/test';

/**
 * What stays in view while the page scrolls, measured rather than looked at.
 *
 * A theme may hold the banner and the navigation in view, each by a token of
 * its own (.ai/plans/09-chrome-a-sirka-obsahu.md, L1): ledger holds both,
 * atrium holds neither. What is asserted here is where the browser put them
 * after the document scrolled, in both themes and at two windows far apart -
 * the side column is a different share of the window at each, and a rule that
 * only worked at one of them would pass a suite that looked at one.
 *
 * Every case also measures the content moving. "The banner did not move" reads
 * the same whether it was held or whether the page never scrolled at all, and
 * only the second measurement tells the two apart.
 *
 * The four places fixed chrome goes wrong are each a case of their own: a jump
 * to an anchor ending under the banner, focus from the keyboard doing the
 * same, paper repeating the chrome on every sheet, and a small window losing
 * the space the fixing was meant to give.
 */

const themes = ['atrium', 'ledger'] as const;

/** Both wider than ledger's breakpoint, so ledger draws its side column at each, and far apart. */
const windows = [
    { width: 1800, height: 900 },
    { width: 960, height: 700 },
] as const;

/** Narrower than ledger's breakpoint, where nothing is held. */
const narrowWindow = { width: 600, height: 800 };

/** How far every case scrolls: more than the banner is tall, less than the lengthened page. */
const distance = 600;

interface Placement {
    readonly banner: number;
    readonly nav: number;
    readonly main: number;
    readonly scrolled: number;
}

async function drawIn(page: Page, theme: string): Promise<void> {
    await page.evaluate((name) => document.documentElement.setAttribute('data-theme', name), theme);
    await settled(page);
}

/** Two frames, so that whatever the page does after a change of layout has been done. */
async function settled(page: Page): Promise<void> {
    await page.evaluate(
        () => new Promise<void>((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve()))),
    );
}

/**
 * Makes the page long enough to scroll, with something positioned at the top
 * of the content.
 *
 * Positioned on purpose: an element with a position of its own is painted in
 * the same pass as the held banner, in the order of the document, so without a
 * layer for the chrome it is what would be drawn over the banner once it
 * scrolls under it. A static block would be painted under it whatever the
 * stylesheet said, and could not tell a layer from none.
 */
async function lengthen(page: Page): Promise<void> {
    await page.evaluate(() => {
        const container = document.querySelector('.l-shell__main .l-container');
        if (container === null) {
            throw new Error('the content region has no container to lengthen');
        }

        const filler = document.createElement('div');
        filler.dataset.testid = 'chrome-filler';
        filler.style.position = 'relative';
        filler.style.blockSize = '300vh';
        container.prepend(filler);
    });
}

async function scrollDown(page: Page, by: number): Promise<void> {
    const start = await page.evaluate(() => window.scrollY);
    await page.mouse.move(10, 10);
    await page.mouse.wheel(0, by);
    await page.waitForFunction((target) => window.scrollY >= target, start + by);
    await settled(page);
}

async function placement(page: Page): Promise<Placement> {
    return page.evaluate(() => {
        const top = (selector: string): number => {
            const element = document.querySelector(selector);
            if (element === null) {
                throw new Error(`nothing matches ${selector}`);
            }

            return element.getBoundingClientRect().top;
        };

        return {
            banner: top('[data-testid="layout-header"]'),
            nav: top('[data-testid="layout-nav"]'),
            main: top('[data-testid="layout-content"]'),
            scrolled: window.scrollY,
        };
    });
}

async function scrollAndMeasure(page: Page): Promise<{ before: Placement; after: Placement }> {
    const before = await placement(page);
    await scrollDown(page, distance);
    const after = await placement(page);

    // The content moved by exactly the distance, which is what makes the two
    // readings of the chrome below mean anything.
    expect(after.scrolled - before.scrolled).toBeCloseTo(distance, 0);
    expect(before.main - after.main, 'the content did not move with the scroll').toBeCloseTo(distance, 0);

    return { before, after };
}

for (const theme of themes) {
    for (const size of windows) {
        const held = theme === 'ledger';

        test(`in ${theme} at ${size.width}px the banner and the navigation ${held ? 'stay' : 'scroll away'}`, async ({
            page,
        }) => {
            await page.setViewportSize(size);
            await page.goto('/_styleguide');
            await drawIn(page, theme);
            await lengthen(page);

            const { before, after } = await scrollAndMeasure(page);

            const moved = { banner: before.banner - after.banner, nav: before.nav - after.nav };
            if (held) {
                expect(moved.banner, 'the banner scrolled away').toBeCloseTo(0, 0);
                expect(moved.nav, 'the navigation scrolled away').toBeCloseTo(0, 0);
                expect(after.banner).toBeCloseTo(0, 0);
                expect(after.nav).toBeCloseTo(0, 0);
            } else {
                expect(moved.banner, 'the banner stayed where it was').toBeCloseTo(distance, 0);
                expect(moved.nav, 'the navigation stayed where it was').toBeCloseTo(distance, 0);
            }
        });
    }
}

/**
 * Held is only half of it: the banner also has to be drawn over what scrolls
 * under it. The filler is positioned, so it is what the point in the middle of
 * the banner would hit if the chrome had no layer above the content.
 */
for (const size of windows) {
    test(`in ledger at ${size.width}px what scrolls under the banner is drawn under it`, async ({ page }) => {
        await page.setViewportSize(size);
        await page.goto('/_styleguide');
        await drawIn(page, 'ledger');
        await lengthen(page);
        await scrollAndMeasure(page);

        const hit = await page.evaluate(() => {
            const banner = document.querySelector('[data-testid="layout-header"]');
            const filler = document.querySelector('[data-testid="chrome-filler"]');
            if (banner === null || filler === null) {
                throw new Error('the banner or the filler is missing');
            }

            const box = banner.getBoundingClientRect();
            const x = box.left + box.width / 2;
            const y = box.top + box.height / 2;
            const under = filler.getBoundingClientRect();

            return {
                fillerIsThere: under.top <= y && under.bottom >= y && under.left <= x && under.right >= x,
                banner: banner.contains(document.elementFromPoint(x, y)),
            };
        });

        expect(hit.fillerIsThere, 'nothing positioned is under the banner, so this proves nothing').toBe(true);
        expect(hit.banner, 'the content that scrolled under the banner is drawn over it').toBe(true);
    });
}

/**
 * The first of the four: a jump to an anchor stops under the banner, not
 * behind it. The heading lands with its top on the banner's bottom edge,
 * because what the jump keeps clear is exactly how much of the window the held
 * banner covers - one number, measured, and not a second one written down.
 */
for (const size of windows) {
    test(`in ledger at ${size.width}px a jump to an anchor leaves the heading whole under the banner`, async ({
        page,
    }) => {
        await page.setViewportSize(size);
        await page.goto('/_styleguide');
        await drawIn(page, 'ledger');
        await lengthen(page);

        await page.evaluate(() => {
            const container = document.querySelector('.l-shell__main .l-container');
            const heading = document.createElement('h2');
            heading.id = 'chrome-jump-target';
            heading.textContent = 'Where the jump lands';

            const link = document.createElement('a');
            link.href = '#chrome-jump-target';
            link.textContent = 'Jump';
            link.dataset.testid = 'chrome-jump';

            // After the filler, so that the page has to scroll to reach it.
            container?.append(heading);
            container?.prepend(link);
            // And room after it, so that it can be scrolled to the top.
            const after = document.createElement('div');
            after.style.blockSize = '100vh';
            container?.append(after);
        });
        await settled(page);

        await page.getByTestId('chrome-jump').click();
        await page.waitForFunction(() => window.location.hash === '#chrome-jump-target' && window.scrollY > 0);
        await settled(page);

        const landed = await page.evaluate(() => {
            const banner = document.querySelector('[data-testid="layout-header"]')?.getBoundingClientRect();
            const heading = document.getElementById('chrome-jump-target')?.getBoundingClientRect();
            if (banner === undefined || heading === undefined) {
                throw new Error('the banner or the heading is missing');
            }

            return { bannerTop: banner.top, bannerBottom: banner.bottom, heading: heading.top, scrolled: window.scrollY };
        });

        expect(landed.scrolled, 'the jump did not scroll, so nothing was measured').toBeGreaterThan(distance);
        expect(landed.bannerTop, 'the banner is not held, so there is nothing to stop under').toBeCloseTo(0, 0);
        expect(landed.heading, 'the heading ended under the banner').toBeGreaterThanOrEqual(landed.bannerBottom - 0.5);
        expect(landed.heading, 'the jump stopped short of where the banner ends').toBeLessThanOrEqual(
            landed.bannerBottom + 1,
        );
    });
}

/**
 * The second: focus moved by the keyboard onto something the banner covers
 * brings it out from under the banner, the same way the jump does - because
 * it asks the same scroller the same question.
 */
test('in ledger a link focused from the keyboard is not left under the banner', async ({ page }) => {
    await page.setViewportSize(windows[0]);
    await page.goto('/_styleguide');
    await drawIn(page, 'ledger');
    await lengthen(page);

    await page.evaluate(() => {
        const filler = document.querySelector('[data-testid="chrome-filler"]');
        const make = (id: string): HTMLAnchorElement => {
            const link = document.createElement('a');
            link.href = '#';
            link.textContent = id;
            link.dataset.testid = id;

            return link;
        };

        // Beside each other and inside the filler, so the second one can be
        // put under the banner with plenty of page left to scroll either way.
        const spacer = document.createElement('div');
        spacer.style.blockSize = '100vh';
        filler?.append(spacer, make('chrome-focus-from'), make('chrome-focus-to'));
    });

    // The link to be focused sits just under the top edge of the window,
    // which is behind the held banner.
    await page.evaluate(() => {
        const to = document.querySelector('[data-testid="chrome-focus-to"]');
        if (to === null) {
            throw new Error('the link to focus is missing');
        }

        window.scrollTo(0, window.scrollY + to.getBoundingClientRect().top - 2);
    });
    await settled(page);

    const hidden = await page.evaluate(() => {
        const banner = document.querySelector('[data-testid="layout-header"]')?.getBoundingClientRect();
        const to = document.querySelector('[data-testid="chrome-focus-to"]')?.getBoundingClientRect();

        return { banner: banner?.bottom ?? 0, to: to?.top ?? 0, bannerTop: banner?.top ?? -1 };
    });
    expect(hidden.bannerTop, 'the banner is not held, so nothing covers the link').toBeCloseTo(0, 0);
    expect(hidden.to, 'the link was not put under the banner, so this proves nothing').toBeLessThan(hidden.banner);

    await page.getByTestId('chrome-focus-from').evaluate((element) => (element as HTMLElement).focus({ preventScroll: true }));
    await page.keyboard.press('Tab');
    await expect(page.getByTestId('chrome-focus-to')).toBeFocused();
    await settled(page);

    const shown = await page.evaluate(() => {
        const banner = document.querySelector('[data-testid="layout-header"]')?.getBoundingClientRect();
        const to = document.querySelector('[data-testid="chrome-focus-to"]')?.getBoundingClientRect();

        return { banner: banner?.bottom ?? 0, to: to?.top ?? 0, bannerTop: banner?.top ?? -1 };
    });
    expect(shown.bannerTop).toBeCloseTo(0, 0);
    expect(shown.to, 'the focused link is still under the banner').toBeGreaterThanOrEqual(shown.banner - 0.5);
});

/**
 * And the other side of the same rule: what keeps a jump clear of the banner
 * must not apply to the banner itself, or to the navigation held beside the
 * content. Both are in view already, so moving focus onto a link in either of
 * them has nothing to scroll into view - and a clearance kept on the whole
 * scroller rather than on the content made the browser scroll the page by
 * most of a window each time somebody tabbed into the banner or down the menu.
 */
for (const [where, link] of [
    ['the banner', '[data-testid="layout-home-link"]'],
    ['the navigation', '[data-testid="layout-nav"] a'],
] as const) {
    test(`in ledger tabbing onto a link in ${where} leaves the page where it was`, async ({ page }) => {
        await page.setViewportSize(windows[0]);
        await page.goto('/_styleguide');
        await drawIn(page, 'ledger');
        await lengthen(page);
        await scrollAndMeasure(page);

        // From whatever comes before the link; the first link of the page has
        // nothing before it, and is what a Tab from nothing focused reaches.
        const before = await page.evaluate((selector) => {
            const target = document.querySelector(selector);
            if (target === null) {
                throw new Error(`nothing matches ${selector}`);
            }

            const focusable = [...document.querySelectorAll('a[href], button')];
            const previous = focusable[focusable.indexOf(target) - 1];
            if (previous instanceof HTMLElement) {
                previous.focus({ preventScroll: true });
            } else if (document.activeElement instanceof HTMLElement) {
                document.activeElement.blur();
            }

            return window.scrollY;
        }, link);

        await page.keyboard.press('Tab');
        await expect(page.locator(link).first()).toBeFocused();
        await settled(page);

        expect(await page.evaluate(() => window.scrollY), `tabbing into ${where} scrolled the page`).toBe(before);
    });
}

/**
 * The third: on paper nothing is held. Emulating print keeps the window, so
 * the document still scrolls, and a band still held would stay put while the
 * content moved - the same measurement as on screen, with the opposite answer.
 * The navigation must also not be cut down to one window's height, which on a
 * sheet of paper would be a menu clipped half way down.
 */
test('in ledger nothing is held on paper', async ({ page }) => {
    await page.setViewportSize(windows[0]);
    await page.goto('/_styleguide');
    await drawIn(page, 'ledger');
    await page.emulateMedia({ media: 'print' });
    await lengthen(page);
    await settled(page);

    const { before, after } = await scrollAndMeasure(page);
    expect(before.banner - after.banner, 'the banner is held on paper').toBeCloseTo(distance, 0);
    expect(before.nav - after.nav, 'the navigation is held on paper').toBeCloseTo(distance, 0);

    const clipped = await page.getByTestId('layout-nav').evaluate((nav) => {
        for (let element: Element | null = nav; element !== null && element !== document.body; element = element.parentElement) {
            const style = getComputedStyle(element);
            if (style.maxBlockSize !== 'none' || style.overflowY !== 'visible') {
                return `${element.className}: max-block-size ${style.maxBlockSize}, overflow-y ${style.overflowY}`;
            }
        }

        return null;
    });
    expect(clipped, 'something between the navigation and the page clips it on paper').toBeNull();
});

/**
 * The fourth: a window too narrow for ledger's side column is too small to
 * give a band of it away for good, so neither theme holds anything there.
 */
for (const theme of themes) {
    test(`in ${theme} a narrow window holds nothing`, async ({ page }) => {
        await page.setViewportSize(narrowWindow);
        await page.goto('/_styleguide');
        await drawIn(page, theme);
        await lengthen(page);

        const { before, after } = await scrollAndMeasure(page);
        expect(before.banner - after.banner, 'the banner is held on a narrow window').toBeCloseTo(distance, 0);
        expect(before.nav - after.nav, 'the navigation is held on a narrow window').toBeCloseTo(distance, 0);
    });
}
