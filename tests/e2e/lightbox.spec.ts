import { expect, type Locator, type Page, test } from '@playwright/test';

/**
 * c-lightbox in a real browser.
 *
 * Opening a picture and putting it away are the browser's own dialog, so they
 * are asked of the browser: that it is a modal over the whole window, that
 * Escape closes it, and that the focus comes back to the small picture it was
 * opened from. What the script adds is measured with it on - going from one
 * picture to another by the buttons, by the arrow keys and by Home and End,
 * round the set - and that the focus then comes back to the small picture of
 * the one looked at last. With no script the ways between pictures are not
 * offered, because they would do nothing.
 *
 * The specimens are the style guide's, invented.
 */

const themes = ['atrium', 'ledger'] as const;

const modes = ['light', 'dark'] as const;

const page_ = '/_styleguide/components/lightbox';

function sample(page: Page): Locator {
    return page.getByTestId('sg-lightbox');
}

/** The dialog of the picture $key of the default specimen. */
function dialogOf(page: Page, key: string): Locator {
    return page.getByTestId(`sg-lightbox-dialog-sample-${key}`);
}

/** The key of the picture whose dialog is open as a modal, or '' for none. */
async function open(page: Page): Promise<string> {
    return page.evaluate(() => {
        const dialog = document.querySelector('[data-testid="sg-lightbox"] dialog:modal');

        return dialog === null ? '' : (dialog.id.split('-sample-')[1] ?? '?');
    });
}

/** The data-testid of whatever has the focus, or its tag where it has none. */
async function focused(page: Page): Promise<string> {
    return page.evaluate(() => document.activeElement?.getAttribute('data-testid') ?? document.activeElement?.localName ?? '');
}

test('a small picture opens it over the whole window, and Escape gives the focus back to it', async ({ page }) => {
    await page.goto(page_);
    const opener = sample(page).getByRole('button', { name: 'Enlarge: Standing up' });

    await opener.click();
    await expect.poll(() => open(page)).toBe('upright');

    const dialog = dialogOf(page, 'upright');
    await expect(dialog).toBeVisible();
    // Named by what is written over it and where it is among the others.
    await expect(dialog).toHaveAccessibleName('Standing up 2 of 3');
    const box = await dialog.boundingBox();
    const window = page.viewportSize();
    expect(box?.width).toBe(window?.width);
    expect(box?.height).toBe(window?.height);
    // The page behind is held still while it is open.
    expect(await page.evaluate(() => getComputedStyle(document.documentElement).overflow)).toBe('hidden');

    // The picture fits the room between the header and the buttons, in its own shape.
    const image = dialog.locator('.c-lightbox__image');
    await expect.poll(() => image.evaluate((element: HTMLImageElement) => element.complete && element.naturalWidth > 0)).toBe(true);
    const shown = await image.boundingBox();
    expect(shown?.height ?? 0).toBeLessThanOrEqual(window?.height ?? 0);
    expect(shown?.width ?? 0).toBeLessThanOrEqual(window?.width ?? 0);

    await page.keyboard.press('Escape');
    await expect.poll(() => open(page)).toBe('');
    await expect.poll(() => focused(page)).toBe('sg-lightbox-open-sample-upright');
});

test('the buttons under a picture go on and back round the set, and pressing again goes on again', async ({ page }) => {
    await page.goto(page_);
    await sample(page).getByRole('button', { name: 'Enlarge: Laid flat' }).click();
    await expect.poll(() => open(page)).toBe('flat');

    await dialogOf(page, 'flat').getByRole('button', { name: 'Next picture' }).click();
    await expect.poll(() => open(page)).toBe('upright');

    // The focus is on the same button of the picture now open, so Enter goes on again.
    await page.keyboard.press('Enter');
    await expect.poll(() => open(page)).toBe('square');
    await page.keyboard.press('Enter');
    await expect.poll(() => open(page), { message: 'on from the last is not the first' }).toBe('flat');

    await dialogOf(page, 'flat').getByRole('button', { name: 'Previous picture' }).click();
    await expect.poll(() => open(page), { message: 'back from the first is not the last' }).toBe('square');

    await dialogOf(page, 'square').getByRole('button', { name: 'Close' }).click();
    await expect.poll(() => open(page)).toBe('');
    await expect.poll(() => focused(page), { message: 'the focus is not on the picture looked at last' }).toBe('sg-lightbox-open-sample-square');
});

test('the arrow keys, Home and End go between the pictures of the set', async ({ page }) => {
    await page.goto(page_);
    await sample(page).getByRole('button', { name: 'Enlarge: Laid flat' }).click();
    await expect.poll(() => open(page)).toBe('flat');

    await page.keyboard.press('ArrowRight');
    await expect.poll(() => open(page)).toBe('upright');
    await page.keyboard.press('ArrowLeft');
    await expect.poll(() => open(page)).toBe('flat');
    await page.keyboard.press('End');
    await expect.poll(() => open(page)).toBe('square');
    // A picture saying nothing of what it shows is named by its place.
    await expect(dialogOf(page, 'square')).toHaveAccessibleName('Picture 3 of 3');
    await page.keyboard.press('Home');
    await expect.poll(() => open(page)).toBe('flat');
    await page.keyboard.press('ArrowLeft');
    await expect.poll(() => open(page)).toBe('square');

    await page.keyboard.press('Escape');
    await expect.poll(() => focused(page)).toBe('sg-lightbox-open-sample-square');
});

test('the way to the picture at full size leads to the picture', async ({ page }) => {
    await page.goto(page_);
    await sample(page).getByRole('button', { name: 'Enlarge: Laid flat' }).click();

    const full = dialogOf(page, 'flat').getByRole('link', { name: 'Open full size' });
    await expect(full).toBeVisible();
    expect(await full.getAttribute('href')).toBe(await dialogOf(page, 'flat').locator('.c-lightbox__image').getAttribute('src'));
});

test('a single picture is not offered a way to another', async ({ page }) => {
    await page.goto(page_);
    await page.getByTestId('sg-lightbox-single').getByRole('button', { name: 'Enlarge: Laid flat' }).click();

    const dialog = page.getByTestId('sg-lightbox-single-dialog-sample-flat');
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('button', { name: 'Next picture' })).toHaveCount(0);

    await page.keyboard.press('ArrowRight');
    await expect(dialog).toBeVisible();
});

test.describe('with no script at all', () => {
    test.use({ javaScriptEnabled: false });

    test('opens and closes a picture where the browser answers the buttons itself, and offers no way to another', async ({ page }) => {
        await page.goto(page_);
        const commands = await page.evaluate(() => 'commandForElement' in HTMLButtonElement.prototype);
        test.skip(!commands, 'this browser has no Invoker Commands, so without a script nothing opens a dialog; assets/dialog.ts answers them');

        await sample(page).getByRole('button', { name: 'Enlarge: Laid flat' }).click();
        const dialog = dialogOf(page, 'flat');
        await expect(dialog).toBeVisible();
        await expect(dialog.getByRole('button', { name: 'Next picture' })).toBeHidden();

        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
    });
});

for (const theme of themes) {
    for (const mode of modes) {
        test(`in ${theme}, ${mode}: the header and the buttons stay in the window and the picture takes the rest`, async ({ page }) => {
            await page.goto(page_);
            await page.evaluate(
                ([chosenTheme, chosenMode]) => {
                    document.documentElement.setAttribute('data-theme', chosenTheme);
                    document.documentElement.setAttribute('data-theme-mode', chosenMode);
                },
                [theme, mode],
            );

            await sample(page).getByRole('button', { name: 'Enlarge: Laid flat' }).click();
            const dialog = dialogOf(page, 'flat');
            const window = page.viewportSize();
            const header = await dialog.locator('.c-lightbox__header').boundingBox();
            const controls = await dialog.locator('.c-lightbox__controls').boundingBox();
            const stage = await dialog.locator('.c-lightbox__stage').boundingBox();

            expect(header?.y).toBe(0);
            expect((controls?.y ?? 0) + (controls?.height ?? 0)).toBeCloseTo(window?.height ?? 0, 0);
            expect(stage?.y ?? 0).toBeCloseTo((header?.y ?? 0) + (header?.height ?? 0), 0);
            expect((stage?.y ?? 0) + (stage?.height ?? 0)).toBeCloseTo(controls?.y ?? 0, 0);

            // Drawn in the theme's surface and ink, not the browser's own dialog colours.
            const [drawn, surface] = await dialog.evaluate((element) => {
                const probe = document.createElement('div');
                probe.style.backgroundColor = 'var(--color-surface)';
                probe.style.color = 'var(--color-ink)';
                document.body.append(probe);
                const expected = getComputedStyle(probe);
                const pair = [
                    `${getComputedStyle(element).backgroundColor} ${getComputedStyle(element).color}`,
                    `${expected.backgroundColor} ${expected.color}`,
                ];
                probe.remove();

                return pair;
            });
            expect(drawn).toBe(surface);
        });
    }
}

/**
 * On a phone the caption wraps and the cross stays where a cross is looked
 * for, at the top of the window at its end; the way to the full size stays on
 * the same line as the cross, beside it.
 */
test('on a phone a long caption wraps, and the cross stays at the top at the end', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(page_);
    const long = page.getByTestId('sg-lightbox-long');
    await long.locator('.c-lightbox__opener').first().click();

    const dialog = long.locator('dialog:modal');
    await expect(dialog).toBeVisible();
    const title = await dialog.locator('.c-lightbox__heading').boundingBox();
    const close = await dialog.locator('.c-close').boundingBox();
    const full = await dialog.locator('.c-lightbox__full').boundingBox();
    const header = await dialog.locator('.c-lightbox__header').boundingBox();

    expect((close?.x ?? 0) + (close?.width ?? 0), 'the cross is not at the end').toBeGreaterThan(390 - 40);
    expect(close?.y ?? 99, 'the cross is not at the top').toBeLessThan((header?.y ?? 0) + 24);
    expect(title?.height ?? 0, 'the caption did not wrap').toBeGreaterThan(40);
    expect((title?.x ?? 0) + (title?.width ?? 0), 'the caption runs under the cross').toBeLessThanOrEqual(full?.x ?? 0);
    expect(Math.abs((full?.y ?? 0) - (close?.y ?? 0)), 'the way to the full size is not beside the cross').toBeLessThan(16);
});

test('a set of more pictures than a row holds runs on in rows', async ({ page }) => {
    await page.goto(page_);
    const tops = await page.getByTestId('sg-lightbox-many').locator('.c-lightbox__opener').evaluateAll((openers) =>
        [...new Set(openers.map((opener) => Math.round(opener.getBoundingClientRect().top)))],
    );

    expect(tops.length, 'the set stayed on one row').toBeGreaterThan(1);
});

test('the way to the full size and the cross stand on one line, centred on each other', async ({ page }) => {
    await page.goto(page_);
    await sample(page).getByRole('button', { name: 'Enlarge: Laid flat' }).click();

    const dialog = dialogOf(page, 'flat');
    const full = await dialog.locator('.c-lightbox__full').boundingBox();
    const close = await dialog.locator('.c-close').boundingBox();
    const middle = (box: { y: number; height: number } | null): number => (box?.y ?? 0) + (box?.height ?? 0) / 2;

    expect(Math.abs(middle(full) - middle(close))).toBeLessThanOrEqual(1);
});

test('every picture of a set is a card of its own, apart from the next', async ({ page }) => {
    await page.goto(page_);
    const items = page.getByTestId('sg-lightbox-captioned').locator('.c-lightbox__item');

    await expect(items).toHaveCount(3);
    for (const item of await items.all()) {
        expect(await item.evaluate((node) => getComputedStyle(node).borderTopWidth), 'a picture is not drawn as a card').not.toBe('0px');
    }

    const [first, second] = [await items.nth(0).boundingBox(), await items.nth(1).boundingBox()];
    const between = (second?.x ?? 0) - ((first?.x ?? 0) + (first?.width ?? 0));
    const inside = await items.nth(0).evaluate((node) => parseFloat(getComputedStyle(node).rowGap) || 0);
    expect(between, 'the room between two pictures is no more than the room inside one').toBeGreaterThan(inside);
});
