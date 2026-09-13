import { type CDPSession, expect, type Locator, type Page, test } from '@playwright/test';

/**
 * c-carousel in a real browser.
 *
 * The strip is CSS scroll snapping, so turning it by hand and by key is the
 * browser's and is asked of the browser - with the script switched off, to be
 * sure it is not ours. What the script adds is measured with it on: the ways
 * back and on, the indicators, and that it never turns by itself (WCAG 2.2.2
 * asks for a pause only of something that moves on its own; this has nothing
 * to pause).
 *
 * The accessibility tree is read out of Chrome itself (the DevTools protocol),
 * because an aria snapshot says nothing of aria-roledescription, which is the
 * whole of how a screen reader tells a carousel from any other region.
 *
 * The specimen is the style guide's, invented.
 */

const themes = ['atrium', 'ledger'] as const;

const modes = ['light', 'dark'] as const;

interface Accessible {
    role: string;
    name: string;
    roledescription: string | undefined;
}

/** What Chrome's accessibility tree holds for every element $selector finds, in document order. */
async function accessible(page: Page, selector: string): Promise<Accessible[]> {
    const session: CDPSession = await page.context().newCDPSession(page);
    try {
        const { root } = await session.send('DOM.getDocument', { depth: 0 });
        const { nodeIds } = await session.send('DOM.querySelectorAll', { nodeId: root.nodeId, selector });
        expect(nodeIds.length, `nothing on the page matches ${selector}`).toBeGreaterThan(0);

        const found: Accessible[] = [];
        for (const nodeId of nodeIds) {
            const { nodes } = await session.send('Accessibility.getPartialAXTree', { nodeId, fetchRelatives: false });
            const node = nodes[0];
            if (node === undefined) {
                throw new Error(`the accessibility tree has no node for ${selector}`);
            }

            const description = node.properties?.find((property) => property.name === 'roledescription');
            found.push({
                role: String(node.role?.value ?? ''),
                name: String(node.name?.value ?? ''),
                roledescription: description === undefined ? undefined : String(description.value.value),
            });
        }

        return found;
    } finally {
        await session.detach();
    }
}

async function drawIn(page: Page, theme: string, mode: string): Promise<void> {
    await page.evaluate(
        ([chosenTheme, chosenMode]) => {
            document.documentElement.setAttribute('data-theme', chosenTheme);
            document.documentElement.setAttribute('data-theme-mode', chosenMode);
        },
        [theme, mode],
    );
}

/** The number of the slide the strip of $carousel has in view, counted from 1: the one whose start is nearest the strip's. */
async function shown(carousel: Locator): Promise<number> {
    return carousel.evaluate((node) => {
        const track = node.querySelector('.c-carousel__track');
        if (track === null) {
            return 0;
        }

        const edge = track.getBoundingClientRect().left;
        const distances = [...track.querySelectorAll('.c-carousel__slide')].map((slide) =>
            Math.abs(slide.getBoundingClientRect().left - edge),
        );

        return distances.indexOf(Math.min(...distances)) + 1;
    });
}

/** Which indicator says it is the one shown, counted from 1, or 0 for none. */
async function indicated(carousel: Locator): Promise<number> {
    return carousel.evaluate((node) => {
        const indicators = [...node.querySelectorAll('.c-carousel__indicator')];

        return indicators.findIndex((indicator) => indicator.getAttribute('aria-current') === 'true') + 1;
    });
}

async function expectShown(carousel: Locator, slide: number): Promise<void> {
    await expect.poll(() => shown(carousel), { message: `slide ${slide} is not the one in view` }).toBe(slide);
    await expect.poll(() => indicated(carousel), { message: `the indicator of slide ${slide} is not marked` }).toBe(slide);
}

function sample(page: Page): Locator {
    return page.getByTestId('sample-carousel');
}

test('is a region described as a carousel, and every slide a group named for its place', async ({ page, browserName }) => {
    test.skip(browserName !== 'chromium', 'reads Chrome\'s own accessibility tree over the DevTools protocol, which only Chromium speaks');

    await page.goto('/_styleguide/components/carousel');

    expect(await accessible(page, '[data-testid="sample-carousel"]')).toEqual([
        { role: 'region', name: 'Specimens', roledescription: 'carousel' },
    ]);
    expect(await accessible(page, '[data-testid="sample-carousel"] .c-carousel__slide')).toEqual([
        { role: 'group', name: '1 of 3', roledescription: 'slide' },
        { role: 'group', name: '2 of 3', roledescription: 'slide' },
        { role: 'group', name: '3 of 3', roledescription: 'slide' },
    ]);
});

test.describe('with no script at all', () => {
    test.use({ javaScriptEnabled: false, reducedMotion: 'reduce' });

    test('turns a slide at a time from the keyboard, and offers no button that would do nothing', async ({ page }) => {
        await page.goto('/_styleguide/components/carousel');
        const carousel = sample(page);
        const track = carousel.locator('.c-carousel__track');
        const slides = carousel.locator('.c-carousel__slide');

        await expect(carousel.getByRole('button', { name: 'Next slide' })).toBeHidden();
        await expect(carousel.getByRole('button', { name: 'Slide 2' })).toBeHidden();

        // Where a slide in view stands, asked of the layout rather than of a
        // script, which is exactly what this page does not have: where the
        // first one stands before the strip is turned.
        const inView = (await slides.nth(0).boundingBox())?.x ?? Number.NaN;

        await track.focus();
        await page.keyboard.press('ArrowRight');

        await expect
            .poll(async () => Math.round(((await slides.nth(1).boundingBox())?.x ?? Number.NaN) - inView))
            .toBe(0);
    });
});

test.describe('with the script', () => {
    test.use({ reducedMotion: 'reduce' });

    test('goes on and back a slide at a time, round from the last to the first', async ({ page }) => {
        await page.goto('/_styleguide/components/carousel');
        const carousel = sample(page);
        const next = carousel.getByRole('button', { name: 'Next slide' });
        const previous = carousel.getByRole('button', { name: 'Previous slide' });

        await expectShown(carousel, 1);

        await next.focus();
        await page.keyboard.press('Enter');
        await expectShown(carousel, 2);

        await next.click();
        await expectShown(carousel, 3);
        await next.click();
        await expectShown(carousel, 1);

        await previous.click();
        await expectShown(carousel, 3);
    });

    test('goes straight to the slide an indicator names', async ({ page }) => {
        await page.goto('/_styleguide/components/carousel');
        const carousel = sample(page);

        await carousel.getByRole('button', { name: 'Slide 3' }).click();
        await expectShown(carousel, 3);
        await expect(carousel.getByRole('button', { name: 'Slide 3' })).toHaveAttribute('aria-current', 'true');
        await expect(carousel.getByRole('button', { name: 'Slide 1' })).not.toHaveAttribute('aria-current');
    });

    test('marks the slide that was turned to by hand', async ({ page }) => {
        await page.goto('/_styleguide/components/carousel');
        const carousel = sample(page);

        await carousel.locator('.c-carousel__track').focus();
        await page.keyboard.press('ArrowRight');
        await expectShown(carousel, 2);
    });

    test('never turns by itself', async ({ page }) => {
        await page.clock.install();
        await page.goto('/_styleguide/components/carousel');
        const carousel = sample(page);

        await page.clock.runFor(120_000);

        expect(await shown(carousel)).toBe(1);
        expect(await indicated(carousel)).toBe(1);
    });

    /**
     * A carousel Naja draws into the page later is markup nobody set up,
     * which is the case a script that prepared the carousels once would miss.
     */
    test('turns a carousel that arrived after the page did, and only that one', async ({ page }) => {
        await page.goto('/_styleguide/components/carousel');
        const original = sample(page);

        await original.evaluate((element) => {
            const holder = document.createElement('div');
            holder.innerHTML = element.outerHTML.replace('data-testid="sample-carousel"', 'data-testid="redrawn-carousel"');
            element.parentElement?.append(holder);
        });

        const redrawn = page.getByTestId('redrawn-carousel');
        await redrawn.getByRole('button', { name: 'Next slide' }).click();

        await expectShown(redrawn, 2);
        await expectShown(original, 1);
    });
});

test.describe('the motion of turning', () => {
    test('glides for somebody who has not asked for less motion, and jumps for somebody who has', async ({ page }) => {
        const behaviour = (): Promise<string> =>
            sample(page)
                .locator('.c-carousel__track')
                .evaluate((track) => getComputedStyle(track).scrollBehavior);

        await page.emulateMedia({ reducedMotion: 'no-preference' });
        await page.goto('/_styleguide/components/carousel');
        expect(await behaviour()).toBe('smooth');

        await page.emulateMedia({ reducedMotion: 'reduce' });
        expect(await behaviour()).toBe('auto');
    });
});

test('marks the slide shown and the strip in focus in both themes and both modes', async ({ page }) => {
    await page.goto('/_styleguide/components/carousel');
    const carousel = sample(page);
    const dot = (name: string): Promise<string> =>
        carousel
            .getByRole('button', { name })
            .evaluate((button) => getComputedStyle(button, '::before').backgroundColor);

    await carousel.locator('.c-carousel__track').focus();

    for (const theme of themes) {
        for (const mode of modes) {
            await drawIn(page, theme, mode);
            const where = `${theme}, ${mode}`;

            expect(await dot('Slide 1'), `the indicator shown is drawn like the others in ${where}`).not.toBe(await dot('Slide 2'));
            expect(
                await carousel.locator('.c-carousel__track').evaluate((track) => getComputedStyle(track).outlineStyle),
                `the strip in focus has no ring in ${where}`,
            ).not.toBe('none');
        }
    }
});
