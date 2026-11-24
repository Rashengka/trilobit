import { expect, type Page, test } from '@playwright/test';

/**
 * The foundation pages of the style guide, measured in a browser: what the
 * theme files say is computed in tests/Template/ThemesDeclareTheFoundationsTest,
 * and here the same colours are read back as the pixels a browser painted,
 * after its own gamut mapping, along with the movements that must stop for
 * somebody who asked for reduced motion.
 */

const themes = ['atrium', 'ledger'] as const;

const modes = ['light', 'dark'] as const;

const COLOURS = '/_styleguide/foundations/colours';

const MOTION = '/_styleguide/foundations/motion';

/** Every foundation page that draws its tokens in tables of specimens. */
const SPECIMEN_PAGES = ['typography', 'sizes', 'shape', 'motion'] as const;

async function drawIn(page: Page, theme: string, mode: string): Promise<void> {
    await page.evaluate(
        ([chosenTheme, chosenMode]) => {
            document.documentElement.setAttribute('data-theme', chosenTheme);
            document.documentElement.setAttribute('data-theme-mode', chosenMode);
        },
        [theme, mode],
    );
}

test.describe('the tones a page reports with', () => {
    /** WCAG AA, 4.5:1, for the text of every sample of every tone, on the ground it is drawn on. */
    test('are readable on their own grounds in both themes and both modes', async ({ page }) => {
        await page.goto(COLOURS);
        await expect(page.locator('[data-sg-tone-sample]')).toHaveCount(16);

        for (const theme of themes) {
            for (const mode of modes) {
                await drawIn(page, theme, mode);

                const failing = await page.evaluate(() => {
                    const canvas = document.createElement('canvas');
                    canvas.width = 1;
                    canvas.height = 1;
                    const context = canvas.getContext('2d', { willReadFrequently: true });
                    if (context === null) {
                        throw new Error('no canvas to resolve colours on');
                    }

                    const rgb = (colour: string): [number, number, number] => {
                        context.clearRect(0, 0, 1, 1);
                        context.fillStyle = colour;
                        context.fillRect(0, 0, 1, 1);
                        const [r, g, b] = context.getImageData(0, 0, 1, 1).data;

                        return [r ?? 0, g ?? 0, b ?? 0];
                    };

                    const luminance = ([r, g, b]: [number, number, number]): number => {
                        const channel = (value: number): number => {
                            const c = value / 255;

                            return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
                        };

                        return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
                    };

                    const below: string[] = [];
                    for (const sample of document.querySelectorAll('[data-sg-tone-sample]')) {
                        const style = getComputedStyle(sample);
                        const one = luminance(rgb(style.color));
                        const two = luminance(rgb(style.backgroundColor));
                        const ratio = (Math.max(one, two) + 0.05) / (Math.min(one, two) + 0.05);
                        if (ratio < 4.5) {
                            const tone = sample.closest('[data-testid^="tone-"]')?.getAttribute('data-testid') ?? '?';
                            below.push(`${tone} "${sample.textContent?.trim() ?? ''}": ${ratio.toFixed(2)}`);
                        }
                    }

                    return below;
                });

                expect(failing, `${theme}, ${mode}: samples below 4.5:1`).toEqual([]);
            }
        }
    });
});

/** Every page of the Foundations group, the ones that existed before these too. */
const FOUNDATION_PAGES = ['colours', 'typography', 'sizes', 'shape', 'motion', 'chrome', 'content-width', 'full-width'] as const;

test.describe('every foundation page', () => {
    /** Drawn once inside the layout: one page, one main region, one footer, one frame of the guide. */
    test('is drawn once, with one main region and one footer', async ({ page }) => {
        for (const slug of FOUNDATION_PAGES) {
            await page.goto(`/_styleguide/foundations/${slug}`);

            await expect(page.locator('main'), `${slug}: main regions`).toHaveCount(1);
            await expect(page.getByTestId('layout-footer'), `${slug}: footers`).toHaveCount(1);
            await expect(page.locator('.sg-frame'), `${slug}: frames of the guide`).toHaveCount(1);
            await expect(page.locator('h1'), `${slug}: page headings`).toHaveCount(1);
        }
    });
});

test.describe('the tables of specimens', () => {
    /**
     * A table scrolls inside its own frame when it is wider than the room it
     * has, and only then. A specimen - a tile with its deepest shadow, a bar,
     * a dot on its track, a line of text at the largest step - has to fit
     * inside its cell, so that the frame grows to hold it rather than offering
     * a scroll bar over a few pixels of it. Measured in every theme, because
     * each draws its elevation differently.
     *
     * And measured a second time with every letter in the tables set wider:
     * how wide a line of text is depends on the faces the machine has, so a
     * table that fits only with the faces of one machine does not fit. The
     * spacing stands in for a wider face, which no machine is asked to have.
     */
    for (const metrics of ['as the machine draws them', 'with wider letters'] as const) {
        test(`grow to hold their specimens rather than scroll, ${metrics}`, async ({ page }) => {
            for (const slug of SPECIMEN_PAGES) {
                await page.goto(`/_styleguide/foundations/${slug}`);
                if (metrics === 'with wider letters') {
                    await page.addStyleTag({ content: '.c-table__viewport { letter-spacing: 0.3em; }' });
                }

                for (const theme of themes) {
                    await drawIn(page, theme, 'light');

                    const scrolling = await page.evaluate(() =>
                        [...document.querySelectorAll('.c-table__viewport')]
                            .filter((viewport) =>
                                viewport.scrollHeight > viewport.clientHeight
                                || viewport.scrollWidth > viewport.clientWidth)
                            .map((viewport) => `${viewport.getAttribute('aria-label') ?? '?'}: `
                                + `${viewport.scrollWidth}x${viewport.scrollHeight} in ${viewport.clientWidth}x${viewport.clientHeight}`),
                    );

                    expect(scrolling, `${slug}, ${theme}: tables that scroll`).toEqual([]);
                }
            }
        });
    }
});

test.describe('motion', () => {
    test('plays the loops, and moves the dots when the box is ticked', async ({ page }) => {
        await page.emulateMedia({ reducedMotion: 'no-preference' });
        await page.goto(MOTION);

        const loop = page.locator('[data-testid="tokens-loops"] .sg-token__mark').first();
        await expect(loop).toHaveCSS('animation-name', 'u-spin');

        const dot = page.locator('[data-testid="tokens-durations"] .sg-token__mark').first();
        await expect(dot).not.toHaveCSS('transition-duration', '0s');
        const start = await dot.evaluate((element) => element.getBoundingClientRect().left);

        await page.getByTestId('styleguide-motion-toggle').check();

        await expect.poll(async () => dot.evaluate((element) => element.getBoundingClientRect().left)).toBeGreaterThan(start);
    });

    /** Nothing moves, and the dot still gets to the other end: it jumps there. */
    test('moves nothing for somebody who asked for reduced motion', async ({ page }) => {
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await page.goto(MOTION);

        for (const mark of await page.locator('[data-testid="tokens-loops"] .sg-token__mark').all()) {
            await expect(mark).toHaveCSS('animation-name', 'none');
        }

        const dot = page.locator('[data-testid="tokens-curves"] .sg-token__mark').first();
        await expect(dot).toHaveCSS('transition-duration', '0s');
        const start = await dot.evaluate((element) => element.getBoundingClientRect().left);

        await page.getByTestId('styleguide-motion-toggle').check();

        expect(await dot.evaluate((element) => element.getBoundingClientRect().left)).toBeGreaterThan(start);
    });
});
