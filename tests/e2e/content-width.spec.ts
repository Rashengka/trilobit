import { expect, type Page, test } from '@playwright/test';

import { choose } from './preferences';

/**
 * How wide the content is, measured rather than looked at.
 *
 * A width is the one property of the design system a picture is useless for: at
 * a narrow window all three modes clamp to the same thing and a screenshot of a
 * broken one is indistinguishable from a screenshot of a working one. So the
 * window is made wider than any of the widths on offer and the container's own
 * box is read out of the browser - and it is read in both themes, because the
 * widths are theme values and a mode that worked in one and not the other would
 * pass a single-theme suite.
 *
 * The second claim here is the ordering: a person's setting is the ordinary
 * case, and a page overrules it only where it has to (see
 * .ai/plans/09-chrome-a-sirka-obsahu.md, L4).
 */

/** Wider than the widest mode of either theme, so that the three cannot coincide. */
const roomToTell = { width: 1900, height: 900 };

const themes = ['atrium', 'ledger'] as const;

const modes = ['content', 'wide', 'full'] as const;

/** The page the style guide keeps for the case a page has to insist; see StyleguideRoutes. */
const insistingPage = '/_styleguide/full-width';

async function containerWidth(page: Page): Promise<number> {
    return page.evaluate(() => {
        const container = document.querySelector('[data-testid="layout-content"] .l-container');
        if (container === null) {
            throw new Error('the content region has no container to measure');
        }

        return container.getBoundingClientRect().width;
    });
}

async function widthOf(page: Page): Promise<string | null> {
    return page.evaluate(() => document.documentElement.getAttribute('data-content-width'));
}

for (const theme of themes) {
    test(`the three modes are three different widths in ${theme}`, async ({ page }) => {
        await page.setViewportSize(roomToTell);
        await page.goto('/_styleguide');
        await page.evaluate((chosen) => document.documentElement.setAttribute('data-theme', chosen), theme);

        const measured: Record<string, number> = {};
        for (const mode of modes) {
            await page.evaluate((chosen) => {
                document.documentElement.setAttribute('data-content-width', chosen);
            }, mode);

            measured[mode] = await containerWidth(page);
        }

        expect(measured.content, `content and wide are the same width in ${theme}`).toBeLessThan(measured.wide);
        expect(measured.wide, `wide and full are the same width in ${theme}`).toBeLessThan(measured.full);

        // Full means the region it sits in, so there is nothing of the page
        // left over beside it.
        const region = await page.evaluate(
            () => document.querySelector('[data-testid="layout-content"]')?.getBoundingClientRect().width ?? 0,
        );
        expect(measured.full).toBeCloseTo(region, 0);
    });
}

test('the width somebody chooses is the one the next page they open is drawn at', async ({ page }) => {
    await page.goto('/_styleguide');
    expect(await widthOf(page)).toBe('content');

    await choose(page, 'content-width', 'full');
    await page.reload();
    expect(await widthOf(page)).toBe('full');

    // And it is a property of the application rather than of the style guide.
    await page.goto('/');
    expect(await widthOf(page)).toBe('full');
});

test('a page that insists on a width overrules the choice, and only that page', async ({ page }) => {
    await page.goto('/_styleguide');
    await choose(page, 'content-width', 'content');

    await page.goto(insistingPage);
    expect(await widthOf(page)).toBe('full');

    await page.goto('/_styleguide');
    expect(await widthOf(page), 'the insisting page changed the setting rather than one rendering').toBe('content');
});

/**
 * The attribute could be right and the page still be drawn at the old width, so
 * the insisting page is measured against an ordinary one - same window, same
 * device, same choice.
 */
test('the page that insists is really drawn wider, not merely labelled so', async ({ page }) => {
    await page.setViewportSize(roomToTell);
    await page.goto('/_styleguide');
    await choose(page, 'content-width', 'content');
    await page.reload();

    const ordinary = await containerWidth(page);

    await page.goto(insistingPage);
    const insisting = await containerWidth(page);

    expect(insisting).toBeGreaterThan(ordinary);
});
