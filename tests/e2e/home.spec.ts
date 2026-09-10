import { expect, test } from '@playwright/test';

/**
 * The scenario T06 asks for: the homepage loads, the layout carries a header
 * and a footer, and the browser console stays silent.
 *
 * "Silent" is taken literally - no console error is filtered away here. A
 * filtered-out error is a promise this test cannot keep, and the favicon
 * gap that used to force one has been closed (see www/favicon.ico and the
 * <link> tags in Core's layout) rather than muted.
 */
test('the homepage loads with a header and a footer and no console errors', async ({ page }) => {
    const consoleErrors: string[] = [];
    page.on('console', (message) => {
        if (message.type() === 'error') {
            consoleErrors.push(message.text());
        }
    });

    const pageErrors: string[] = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));

    const failedRequests: string[] = [];
    page.on('requestfailed', (request) => {
        failedRequests.push(`${request.method()} ${request.url()}`);
    });
    page.on('response', (response) => {
        if (response.status() >= 400) {
            failedRequests.push(`${response.status()} ${response.url()}`);
        }
    });

    const response = await page.goto('/');
    expect(response?.status()).toBe(200);

    await expect(page.getByTestId('layout-header')).toBeVisible();
    await expect(page.getByTestId('layout-footer')).toBeVisible();
    await expect(page.getByTestId('homepage-headline')).toBeVisible();

    expect(failedRequests).toEqual([]);
    expect(consoleErrors).toEqual([]);
    expect(pageErrors).toEqual([]);
});

/**
 * The sections of the front page are a grid of tiles and not a stack of blocks.
 *
 * It is measured in a browser because that is the only place the mistake it
 * guards against existed. The signpost component asked for a grid and then
 * carried a second class of its own declaring `display: block`; both rules were
 * correct, both files read correctly, and the cascade settled it by source
 * order - so the front page drew its sections full width, one under another,
 * with the grid's gap silently not applying. Nothing but a real layout pass can
 * tell that apart from the arrangement that was meant.
 */
test('the sections of the front page are laid out as a grid of tiles', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/');

    const signpost = page.getByTestId('homepage-signposts');
    await expect(signpost).toBeVisible();

    const drawn = await signpost.evaluate((element) => ({
        display: getComputedStyle(element).display,
        gap: getComputedStyle(element).columnGap,
        tiles: [...element.querySelectorAll('.c-card')].map((tile) => {
            const box = tile.getBoundingClientRect();

            return { top: box.top, left: box.left, right: box.right };
        }),
    }));

    expect(drawn.display).toBe('grid');
    expect(Number.parseFloat(drawn.gap)).toBeGreaterThan(0);
    expect(drawn.tiles.length).toBeGreaterThan(1);

    // Side by side and not touching: the second tile shares the first one's row
    // and begins after it ends, which is the grid and its gap doing their work.
    const [first, second] = drawn.tiles;
    expect(second.top).toBeCloseTo(first.top, 0);
    expect(second.left).toBeGreaterThan(first.right);
});

/**
 * A tile is the size the grid says, not the size the number of tiles says.
 *
 * The front page has several and always looked right; the section with one
 * entry - the installation's own, at /admin/installation - drew a single tile
 * as wide as the page. The rule behind both is one line of assets/base.css, and
 * what was wrong with it was `auto-fit`: it lays out the same tracks as
 * `auto-fill` and then collapses the ones nothing was placed in, so the `1fr`
 * on the remaining track swallows all the room the empty ones gave up. Two
 * tiles came out half a page wide each for the same reason.
 *
 * It is measured here rather than on the page where it was seen because the
 * claim is about the component and not about who may open that section: tiles
 * are taken away from a signpost that has several and the survivor is measured
 * against what it was. Nothing but a real layout pass can answer that, and no
 * count written down here can go stale as the front page grows or shrinks.
 */
test('a signpost tile keeps its size when it is the only one left', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/');

    const signpost = page.getByTestId('homepage-signposts');
    await expect(signpost).toBeVisible();

    const measured = await signpost.evaluate((element) => {
        const width = (): number => element.querySelector('.c-card')?.getBoundingClientRect().width ?? 0;

        const beside = { tiles: element.children.length, width: width() };

        // The tiles the page drew are put back, so that nothing after this
        // reads a page this test emptied.
        const drawn = [...element.children];
        element.replaceChildren(drawn[0]);
        const alone = { tiles: element.children.length, width: width() };
        element.replaceChildren(...drawn);

        return { beside, alone, region: element.getBoundingClientRect().width };
    });

    expect(measured.beside.tiles, 'the front page has one section, so there is nothing to take away').toBeGreaterThan(1);
    expect(measured.alone.tiles).toBe(1);

    // The two halves of the same sentence: the tile did not change, and the
    // thing it did not change into is the one it used to become.
    expect(measured.alone.width).toBeCloseTo(measured.beside.width, 0);
    expect(measured.alone.width).toBeLessThan(measured.region / 2);
});
