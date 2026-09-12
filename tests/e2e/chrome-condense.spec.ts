import { expect, type Page, test } from '@playwright/test';

/**
 * Atrium's banner and navigation, held in view and made smaller once the page
 * has scrolled (.ai/plans/09-chrome-a-sirka-obsahu.md, L3) - measured with
 * getBoundingClientRect() rather than looked at.
 *
 * At the top of the page both bands are drawn at their full size. Once the
 * content has scrolled up under them they stay in view, the banner lower than
 * it was and the entries of the navigation at three quarters of their size with
 * less room around them - so the navigation can still be reached and the
 * content has more of the window.
 *
 * What goes wrong with a band that changes its height while the page scrolls
 * is each a case of its own: a jump to an anchor measured against the old
 * height, the change of height moving the content and the scroll position with
 * it and so undoing itself (blinking), motion for somebody who asked for none,
 * and paper or a small window getting a change meant for a large one.
 *
 * Nothing here reads how the page decides it has scrolled. What is measured is
 * the height of the bands, frame by frame where it matters, so a case passes
 * for what the page does rather than for the attribute it sets.
 */

/** Two windows far apart, both wider than atrium's breakpoint. */
const windows = [
    { width: 1800, height: 900 },
    { width: 960, height: 700 },
] as const;

/** Narrower than atrium's breakpoint, where nothing is held and nothing is made smaller. */
const narrowWindow = { width: 600, height: 800 };

const BANNER = '[data-testid="layout-header"]';
const NAV = '[data-testid="layout-nav"]';
const CONTENT = '[data-testid="layout-content"]';

/** The first entry of the navigation itself, as against an entry under an entry. */
const ENTRY = '[data-testid="layout-nav"] .c-nav__list > .c-nav__item > .c-nav__link';

interface Chrome {
    readonly bannerTop: number;
    readonly bannerBottom: number;
    readonly bannerHeight: number;
    readonly navTop: number;
    readonly navBottom: number;
    readonly navHeight: number;
    readonly entryFont: number;
    readonly entryPadding: number;
    readonly contentTop: number;
    readonly offset: number;
}

async function drawIn(page: Page, theme: string): Promise<void> {
    await page.evaluate((name) => document.documentElement.setAttribute('data-theme', name), theme);
    await steady(page);
}

/**
 * Waits until the bands and the scroll position have not changed for several
 * frames running: a change of size is animated, and a page that blinks never
 * comes to rest at all - which fails here rather than passing on a lucky frame.
 */
async function steady(page: Page): Promise<void> {
    await page.evaluate(
        ([banner, nav]) =>
            new Promise<void>((resolve, reject) => {
                const read = (): string =>
                    [banner, nav].map((selector) => document.querySelector(selector)?.getBoundingClientRect().height ?? 0).join('/') +
                    `/${window.scrollY}`;
                let last = read();
                let same = 0;
                const started = performance.now();
                const tick = (): void => {
                    const now = read();
                    same = now === last ? same + 1 : 0;
                    last = now;
                    if (same >= 6) {
                        resolve();
                    } else if (performance.now() - started > 3000) {
                        reject(new Error(`the chrome never came to rest: ${now}`));
                    } else {
                        requestAnimationFrame(tick);
                    }
                };
                requestAnimationFrame(tick);
            }),
        [BANNER, NAV],
    );
}

/** Makes the page long enough to scroll, with something positioned at the top of the content. */
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
    await steady(page);
}

async function open(page: Page, size: { width: number; height: number }, theme = 'atrium'): Promise<void> {
    await page.setViewportSize(size);
    await page.goto('/_styleguide');
    await drawIn(page, theme);
    await lengthen(page);
}

async function measure(page: Page): Promise<Chrome> {
    return page.evaluate(
        ([banner, nav, content, entry]) => {
            const box = (selector: string): DOMRect => {
                const element = document.querySelector(selector);
                if (element === null) {
                    throw new Error(`nothing matches ${selector}`);
                }

                return element.getBoundingClientRect();
            };
            const link = document.querySelector(entry);
            if (link === null) {
                throw new Error('the navigation has no entry');
            }
            const style = getComputedStyle(link);
            const offset = (document.scrollingElement as HTMLElement).style.getPropertyValue('--layout-chrome-offset');

            return {
                bannerTop: box(banner).top,
                bannerBottom: box(banner).bottom,
                bannerHeight: box(banner).height,
                navTop: box(nav).top,
                navBottom: box(nav).bottom,
                navHeight: box(nav).height,
                entryFont: Number.parseFloat(style.fontSize),
                entryPadding: Number.parseFloat(style.paddingTop),
                contentTop: box(content).top,
                offset: Number.parseFloat(offset === '' ? '0' : offset),
            };
        },
        [BANNER, NAV, CONTENT, ENTRY],
    );
}

/** Scrolls the way a person does, and waits for whatever that set off to finish. */
async function wheel(page: Page, by: number): Promise<void> {
    const start = await page.evaluate(() => window.scrollY);
    await page.mouse.move(10, 10);
    await page.mouse.wheel(0, by);
    await page.waitForFunction((from) => window.scrollY !== from, start);
    await steady(page);
}

/**
 * Where the page is to be scrolled for a band made smaller to stay that way:
 * somewhere a long way down, so that the reading is taken well clear of where
 * the change happens.
 */
const distance = 600;

for (const size of windows) {
    test(`in atrium at ${size.width}px the banner and the navigation stay in view, made smaller`, async ({ page }) => {
        await open(page, size);

        const full = await measure(page);
        expect(full.bannerTop, 'the page did not start at its top').toBeCloseTo(0, 0);
        expect(full.navTop, 'the navigation is not under the banner').toBeCloseTo(full.bannerBottom, 0);

        await wheel(page, distance);
        const small = await measure(page);

        // The content moved, so the readings of the chrome below mean something.
        expect(full.contentTop - small.contentTop, 'the content did not move with the scroll').toBeGreaterThan(distance / 2);

        expect(small.bannerTop, 'the banner scrolled away').toBeCloseTo(0, 0);
        expect(small.navTop, 'the navigation is not held under the banner').toBeCloseTo(small.bannerBottom, 0);
        expect(small.navBottom, 'the navigation scrolled away').toBeGreaterThan(0);

        expect(small.bannerHeight, 'the banner was not made lower').toBeLessThan(full.bannerHeight * 0.75);
        expect(small.navHeight, 'the navigation was not made lower').toBeLessThan(full.navHeight * 0.75);
        expect(small.entryFont / full.entryFont, 'an entry of the navigation is not about three quarters of its size').toBeCloseTo(
            0.75,
            2,
        );
        expect(small.entryPadding, 'the room around an entry was not made markedly smaller').toBeLessThan(full.entryPadding / 2);

        // What a jump keeps clear is what the two bands cover now, not what they covered at the top.
        expect(small.offset, 'the clearance a jump keeps is not the height of the smaller bands').toBeCloseTo(small.navBottom, 0);

        // And held: scrolled further, neither band moves while the content does.
        await wheel(page, 300);
        const further = await measure(page);
        expect(small.contentTop - further.contentTop, 'the content did not move with the scroll').toBeCloseTo(300, 0);
        expect(further.bannerTop).toBeCloseTo(0, 0);
        expect(further.navTop).toBeCloseTo(small.navTop, 0);
        expect(further.bannerHeight).toBeCloseTo(small.bannerHeight, 0);
    });

    test(`in atrium at ${size.width}px a jump to an anchor leaves the heading whole under the smaller bands`, async ({ page }) => {
        await open(page, size);
        await addJumpTarget(page);

        // Made smaller first, so that what is measured is the clearance of the smaller bands.
        await wheel(page, distance);
        expect((await measure(page)).bannerHeight).toBeLessThan((await fullHeightOf(page)) * 0.75);

        await page.getByTestId('chrome-jump').evaluate((link) => (link as HTMLElement).click());
        await page.waitForFunction(() => window.location.hash === '#chrome-jump-target');
        await steady(page);

        const landed = await landing(page);
        expect(landed.scrolled, 'the jump did not scroll, so nothing was measured').toBeGreaterThan(distance);
        expect(landed.navTop, 'the navigation is not held, so there is nothing to stop under').toBeCloseTo(landed.bannerBottom, 0);
        expect(landed.heading, 'the heading ended under the navigation').toBeGreaterThanOrEqual(landed.navBottom - 0.5);
        expect(landed.heading, 'the jump stopped short of where the navigation ends').toBeLessThanOrEqual(landed.navBottom + 1);
    });
}

/**
 * A jump taken from the top of the page starts with the bands at their full
 * size and ends with them smaller. The heading may then sit a little lower than
 * the bands' new edge, but it must never be left under them.
 */
test('in atrium a jump from the top of the page never leaves the heading under the bands', async ({ page }) => {
    await open(page, windows[0]);
    await addJumpTarget(page);

    await page.getByTestId('chrome-jump').click();
    await page.waitForFunction(() => window.location.hash === '#chrome-jump-target' && window.scrollY > 0);
    await steady(page);

    const landed = await landing(page);
    expect(landed.scrolled).toBeGreaterThan(distance);
    expect(landed.heading, 'the heading ended under the navigation').toBeGreaterThanOrEqual(landed.navBottom - 0.5);
    expect(landed.heading, 'the heading is nowhere near the bands').toBeLessThan(landed.navBottom + 200);
});

/**
 * The trap the pattern is known for: the bands get smaller, the content moves
 * up by as much, the scroll position moves with it, the page decides it is at
 * its top again, the bands grow - and back. Scrolled a pixel at a time across
 * the place where the bands change, down and back up, the bands change size
 * once each way and not once more; left resting just past that place, they
 * stay as they are.
 */
test('in atrium the bands change size once each way across the threshold, and do not blink', async ({ page }) => {
    await open(page, windows[0]);

    const threshold = (await measure(page)).contentTop;
    await page.evaluate(
        ([banner, full]) => {
            const record: number[] = [];
            (window as unknown as { chromeHeights: number[] }).chromeHeights = record;
            const element = document.querySelector(banner);
            const tick = (): void => {
                record.push(element?.getBoundingClientRect().height ?? Number.NaN);
                requestAnimationFrame(tick);
            };
            requestAnimationFrame(tick);
            (window as unknown as { chromeFull: number }).chromeFull = full;
        },
        [BANNER, (await measure(page)).bannerHeight] as const,
    );

    const scrollTo = async (y: number): Promise<void> => {
        await page.evaluate((to) => window.scrollTo(0, to), y);
        await page.evaluate(() => new Promise<void>((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve()))));
    };

    // Down across the threshold a pixel at a time, and a little beyond.
    for (let y = threshold - 40; y <= threshold + 40; y += 1) {
        await scrollTo(y);
    }
    await steady(page);
    const flipsDown = await flips(page);

    // Left resting just past it: nothing more happens.
    await page.waitForTimeout(600);
    const flipsResting = await flips(page);

    // Back up across it the same way, still short of the top.
    const from = await page.evaluate(() => window.scrollY);
    for (let y = from; y >= Math.max(1, from - 80); y -= 1) {
        await scrollTo(y);
    }
    await steady(page);
    const flipsBack = await flips(page);

    // And to the top, where the bands are drawn full again.
    await scrollTo(0);
    await steady(page);
    const flipsTop = await flips(page);
    const end = await measure(page);

    expect(flipsDown, 'the bands did not change size on the way down, or changed more than once').toBe(1);
    expect(flipsResting, 'the bands went on changing while the page was left alone').toBe(1);
    expect(flipsBack, 'the bands changed size while the page was scrolled back short of the top').toBeLessThanOrEqual(2);
    expect(flipsTop, 'the bands did not grow back once at the top, or changed more than that').toBe(2);
    expect(end.bannerHeight, 'at the top the banner is not drawn at its full size').toBeCloseTo(
        await page.evaluate(() => (window as unknown as { chromeFull: number }).chromeFull),
        0,
    );
});

/**
 * With reduced motion the bands still get smaller, but they jump from one size
 * to the other with nothing drawn in between. The same recording with motion
 * allowed must see sizes in between, or the recording could not tell the two
 * apart.
 */
for (const reducedMotion of ['reduce', 'no-preference'] as const) {
    test(`in atrium with ${reducedMotion} motion the bands ${reducedMotion === 'reduce' ? 'change size without moving' : 'are animated'}`, async ({
        page,
    }) => {
        await page.emulateMedia({ reducedMotion });
        await open(page, windows[0]);

        const full = (await measure(page)).bannerHeight;
        await page.evaluate((banner) => {
            const record: number[] = [];
            (window as unknown as { chromeHeights: number[] }).chromeHeights = record;
            const element = document.querySelector(banner);
            const tick = (): void => {
                record.push(element?.getBoundingClientRect().height ?? Number.NaN);
                requestAnimationFrame(tick);
            };
            requestAnimationFrame(tick);
        }, BANNER);

        await wheel(page, distance);
        const small = (await measure(page)).bannerHeight;
        expect(small, 'the banner was not made lower').toBeLessThan(full * 0.75);

        const heights = await page.evaluate(() => (window as unknown as { chromeHeights: number[] }).chromeHeights);
        const between = heights.filter((height) => height > small + 0.5 && height < full - 0.5);
        if (reducedMotion === 'reduce') {
            expect(between, 'a size between the two was drawn, so the change was animated').toEqual([]);
        } else {
            expect(between.length, 'no size between the two was drawn, so this recording proves nothing').toBeGreaterThan(0);
        }
    });
}

/** On paper nothing is held and nothing is made smaller. */
test('in atrium nothing is held or made smaller on paper', async ({ page }) => {
    await page.setViewportSize(windows[0]);
    await page.goto('/_styleguide');
    await drawIn(page, 'atrium');
    await page.emulateMedia({ media: 'print' });
    await lengthen(page);

    const before = await measure(page);
    await wheel(page, distance);
    const after = await measure(page);

    expect(before.contentTop - after.contentTop, 'the content did not move with the scroll').toBeCloseTo(distance, 0);
    expect(before.bannerTop - after.bannerTop, 'the banner is held on paper').toBeCloseTo(distance, 0);
    expect(before.navTop - after.navTop, 'the navigation is held on paper').toBeCloseTo(distance, 0);
    expect(after.bannerHeight, 'the banner was made smaller on paper').toBeCloseTo(before.bannerHeight, 0);
    expect(after.entryFont, 'the navigation was made smaller on paper').toBeCloseTo(before.entryFont, 1);
});

/** A window too narrow to give the room away holds nothing, and so has nothing to make smaller. */
test('in atrium a narrow window is neither held nor made smaller', async ({ page }) => {
    await open(page, narrowWindow);

    const before = await measure(page);
    await wheel(page, distance);
    const after = await measure(page);

    expect(before.contentTop - after.contentTop).toBeCloseTo(distance, 0);
    expect(before.bannerTop - after.bannerTop, 'the banner is held on a narrow window').toBeCloseTo(distance, 0);
    expect(after.bannerHeight, 'the banner was made smaller on a narrow window').toBeCloseTo(before.bannerHeight, 0);
    expect(after.entryFont, 'the navigation was made smaller on a narrow window').toBeCloseTo(before.entryFont, 1);
});

/**
 * A page opens with its bands at their size, not growing into it. A browser
 * may draw the top of the page once before the stylesheet arrives, and a
 * transition that applied from the start animated that arrival - the banner
 * grew out of nothing on every page, and whatever a person or a case measured
 * in that moment was moving. Recorded from the very first frame: at most one
 * height before the final one (the page as drawn without its stylesheet),
 * and nothing in between.
 */
test('in atrium a page opens with its bands at their size, not growing into it', async ({ page }) => {
    await page.addInitScript((banner) => {
        const heights: number[] = [];
        (window as unknown as { openingHeights: number[] }).openingHeights = heights;
        const tick = (): void => {
            const element = document.querySelector(banner);
            if (element !== null) {
                heights.push(Math.round(element.getBoundingClientRect().height * 100) / 100);
            }
            requestAnimationFrame(tick);
        };
        requestAnimationFrame(tick);
    }, BANNER);

    await page.setViewportSize(windows[0]);
    await page.goto('/_styleguide');

    // Frames rather than time: a browser running several pages at once draws
    // each of them less often, and a fixed wait then records too little.
    await page.waitForFunction(() => ((window as unknown as { openingHeights?: number[] }).openingHeights?.length ?? 0) >= 30, undefined, {
        timeout: 10_000,
    });

    const heights = await page.evaluate(() => (window as unknown as { openingHeights: number[] }).openingHeights);
    const final = heights[heights.length - 1];
    expect(heights.length, 'nothing was recorded, so this proves nothing').toBeGreaterThan(10);

    const before = [...new Set(heights.filter((height) => height !== final))];
    expect(before.length, `the banner grew into its size over several frames: ${before.join(', ')} -> ${final}`).toBeLessThanOrEqual(1);
});

/** Ledger holds its bands and does not change their size: L3 is atrium's. */
test('in ledger the bands keep their size while the page scrolls', async ({ page }) => {
    await open(page, windows[0], 'ledger');

    const before = await measure(page);
    await wheel(page, distance);
    const after = await measure(page);

    expect(before.contentTop - after.contentTop).toBeCloseTo(distance, 0);
    expect(after.bannerTop, 'ledger let go of its banner').toBeCloseTo(0, 0);
    expect(after.bannerHeight, 'ledger made its banner smaller').toBeCloseTo(before.bannerHeight, 1);
    expect(after.entryFont, 'ledger made its navigation smaller').toBeCloseTo(before.entryFont, 2);
    expect(after.entryPadding, 'ledger took room from around its entries').toBeCloseTo(before.entryPadding, 2);
});

/**
 * The block atrium opens under an entry of the navigation, opened while the
 * navigation is held and smaller: it hangs under the navigation, nothing in the
 * content is drawn over it, and it does not go away by itself.
 */
test('in atrium the block opened in the smaller navigation is neither cut off, covered nor lost', async ({ page }) => {
    await page.setViewportSize({ width: 1400, height: 900 });
    await page.goto('/_styleguide/components/nav');
    await drawIn(page, 'atrium');
    await copyIntoTheNavigation(page, 'sample-subnav-collections');
    await lengthen(page);

    const full = await measure(page);
    await wheel(page, distance);
    const small = await measure(page);
    expect(small.bannerHeight, 'the bands were not made smaller, so this proves nothing').toBeLessThan(full.bannerHeight * 0.75);

    await page.getByTestId('held-sample-subnav-collections').hover();
    await expect(page.getByTestId('held-sample-subnav-collections-toggle')).toHaveAttribute('aria-expanded', 'true');
    await steady(page);

    const inBlock = page.getByTestId('held-sample-subnav-minerals');
    await expect(inBlock).toBeVisible();

    const measured = await inBlock.evaluate((link, nav) => {
        const box = link.getBoundingClientRect();

        return {
            navBottom: document.querySelector(nav)?.getBoundingClientRect().bottom ?? Number.NaN,
            linkTop: box.top,
            onTop: link.contains(document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2)),
        };
    }, NAV);

    expect(measured.linkTop, 'the block does not reach below the navigation, so this proves nothing').toBeGreaterThan(
        measured.navBottom,
    );
    expect(measured.onTop, 'the block is cut off by the navigation or drawn under the content').toBe(true);

    await page.waitForTimeout(500);
    await expect(page.getByTestId('held-sample-subnav-collections-toggle')).toHaveAttribute('aria-expanded', 'true');
    await expect(inBlock).toBeVisible();
    expect((await measure(page)).bannerHeight, 'opening the block made the bands grow back').toBeCloseTo(small.bannerHeight, 0);
});

/** How many times the banner crossed half-way between its two sizes since the recording started. */
async function flips(page: Page): Promise<number> {
    return page.evaluate(() => {
        const { chromeHeights: heights, chromeFull: full } = window as unknown as { chromeHeights: number[]; chromeFull: number };
        const small = Math.min(...heights);
        const middle = (full + small) / 2;
        let count = 0;
        for (let i = 1; i < heights.length; i += 1) {
            if (heights[i - 1] >= middle !== heights[i] >= middle) {
                count += 1;
            }
        }

        return count;
    });
}

async function fullHeightOf(page: Page): Promise<number> {
    return page.evaluate((banner) => {
        const probe = document.querySelector(banner);

        return Number(probe?.getAttribute('data-full-height') ?? Number.POSITIVE_INFINITY);
    }, BANNER);
}

/** A heading far down the page and a link to it at the top. */
async function addJumpTarget(page: Page): Promise<void> {
    await page.evaluate((banner) => {
        const container = document.querySelector('.l-shell__main .l-container');
        const heading = document.createElement('h2');
        heading.id = 'chrome-jump-target';
        heading.textContent = 'Where the jump lands';

        const link = document.createElement('a');
        link.href = '#chrome-jump-target';
        link.textContent = 'Jump';
        link.dataset.testid = 'chrome-jump';

        container?.append(heading);
        container?.prepend(link);
        const after = document.createElement('div');
        after.style.blockSize = '100vh';
        container?.append(after);

        // The banner's height at the top of the page, for the case to compare against.
        const element = document.querySelector(banner);
        element?.setAttribute('data-full-height', String(element.getBoundingClientRect().height));
    }, BANNER);
    await steady(page);
}

async function landing(page: Page): Promise<{ bannerBottom: number; navTop: number; navBottom: number; heading: number; scrolled: number }> {
    return page.evaluate(
        ([banner, nav]) => {
            const box = (selector: string): DOMRect | undefined => document.querySelector(selector)?.getBoundingClientRect();
            const heading = document.getElementById('chrome-jump-target')?.getBoundingClientRect();

            return {
                bannerBottom: box(banner)?.bottom ?? Number.NaN,
                navTop: box(nav)?.top ?? Number.NaN,
                navBottom: box(nav)?.bottom ?? Number.NaN,
                heading: heading?.top ?? Number.NaN,
                scrolled: window.scrollY,
            };
        },
        [BANNER, NAV],
    );
}

/** Puts a copy of a specimen entry into the navigation of the page itself, under names of its own. */
async function copyIntoTheNavigation(page: Page, id: string): Promise<void> {
    await page.evaluate((entry) => {
        const source = document.querySelector(`[data-testid="${entry}"]`)?.parentElement;
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
    }, id);
    await steady(page);
}
