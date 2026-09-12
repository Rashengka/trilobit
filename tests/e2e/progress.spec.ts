import { expect, test } from '@playwright/test';
import { contrast, drawIn, expectClose, modes, paintedAt, resolved, runningOn, stage, themes } from './paint';

/**
 * c-progress in a real browser: the bars are in the accessibility tree under
 * their labels and say their values once, their fills are the theme's and can
 * be told from the track, and the bar that does not know how far it has got
 * moves only for whoever has not asked for less motion.
 *
 * Which element each specimen is - <progress> or <meter> - is asked of the
 * markup in tests/Template/ProgressTest; here it is asked of the tree, by role.
 */

const address = ['/_styleguide', 'components', 'progress'].join('/');

test('every bar is in the tree under its label and says its value once', async ({ page }) => {
    await page.goto(address);

    await expect(page.getByRole('progressbar', { name: 'Importing the price list', exact: true })).toBeVisible();
    await expect(page.getByRole('progressbar', { name: 'Waiting for the carrier to answer', exact: true })).toBeVisible();
    await expect(page.getByRole('meter', { name: 'Storage used', exact: true })).toBeVisible();
    await expect(page.getByRole('meter', { name: 'Drawer B2 filled', exact: true })).toBeVisible();

    // Shown beside the bar and said by the bar: once in the tree, not twice.
    for (const [variant, value] of [
        ['under way', '132 of 480 rows'],
        ['a measure', '3.2 of 10 GB'],
        ['a measure past its high bound', '46 of 50 places'],
    ] as const) {
        const tree = await stage(page, variant).ariaSnapshot();
        expect(tree.split(value).length - 1, `${variant} says its value other than once:\n${tree}`).toBe(1);
        await expect(stage(page, variant).locator('.c-progress__value')).toHaveText(value);
        await expect(stage(page, variant).locator('.c-progress__value')).toBeVisible();
    }

    // Not known how far is not nought per cent.
    const waiting = await stage(page, 'not known how far').ariaSnapshot();
    expect(waiting, waiting).not.toMatch(/\d/);
});

test('a bar is nothing to reach by the keyboard', async ({ page }) => {
    await page.goto(address);

    for (const bar of await page.locator('.c-progress__bar').all()) {
        await bar.focus();
        await expect(bar).not.toBeFocused();
    }
});

test('a bar that does not know how far it has got moves only for whoever has not asked for less motion', async ({
    page,
}) => {
    await page.emulateMedia({ reducedMotion: 'no-preference' });
    await page.goto(address);

    const bar = stage(page, 'not known how far').locator('.c-progress__bar');
    expect(await runningOn(bar)).toBe(1);

    await page.emulateMedia({ reducedMotion: 'reduce' });
    expect(await runningOn(bar)).toBe(0);

    // Standing still, it is still busy: the whole track hatched in the fill's ink.
    expect(await bar.evaluate((node) => getComputedStyle(node).backgroundImage)).toContain('repeating-linear-gradient');
    expect(contrast(await paintedAt(bar, 0.3), await paintedAt(bar, 0.31))).toBeGreaterThan(1);

    // The bars that know how far never move.
    for (const variant of ['under way', 'a measure']) {
        await page.emulateMedia({ reducedMotion: 'no-preference' });
        expect(await runningOn(stage(page, variant).locator('.c-progress__bar'))).toBe(0);
    }
});

for (const theme of themes) {
    for (const mode of modes) {
        const where = `${theme}, ${mode}`;

        test(`the fill of every bar is the theme's and stands out from the track in ${where}`, async ({ page }) => {
            await page.emulateMedia({ reducedMotion: 'reduce' });
            await page.goto(address);
            await drawIn(page, theme, mode);

            // 132 of 480 is under a third: a tenth of the way along is fill,
            // nine tenths is track.
            const under = stage(page, 'under way').locator('.c-progress__bar');
            const fill = await paintedAt(under, 0.1);
            const track = await paintedAt(under, 0.9);
            expectClose(fill, await resolved(under, 'color', '--color-accent'), `the fill under way in ${where}`);
            expectClose(track, await resolved(under, 'color', '--color-canvas-sunken'), `the track in ${where}`);
            expect(contrast(fill, track), `the fill against the track in ${where}`).toBeGreaterThanOrEqual(3);

            // Inside its good range, a measure is in the accent too - and its
            // fill is as thick as the bar, top to bottom, as a progress bar's
            // is. Chrome draws a meter's fill half as thick unless told the
            // height of its parts in a length.
            const measure = stage(page, 'a measure').locator('.c-progress__bar');
            const accent = await resolved(measure, 'color', '--color-accent');
            // Inside the hairline at the top and the bottom in both themes.
            for (const y of [0.35, 0.5, 0.65]) {
                expectClose(await paintedAt(under, 0.1, y), accent, `the fill under way, ${y} down, in ${where}`);
                expectClose(await paintedAt(measure, 0.1, y), accent, `a measure in its good range, ${y} down, in ${where}`);
            }

            // Past its high bound, with the optimum at the low end, it is in the
            // ink of a refusal - and still stands out from the track.
            const past = stage(page, 'a measure past its high bound').locator('.c-progress__bar');
            const pastFill = await paintedAt(past, 0.5);
            expectClose(pastFill, await resolved(past, 'color', '--color-danger'), `a measure past its bound in ${where}`);
            expect(contrast(pastFill, await paintedAt(past, 0.97)), `past its bound against the track in ${where}`)
                .toBeGreaterThanOrEqual(3);
        });
    }
}
