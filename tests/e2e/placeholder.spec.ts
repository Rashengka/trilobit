import { expect, test } from '@playwright/test';
import { drawIn, expectClose, modes, resolved, runningOn, stage, themes } from './paint';

/**
 * c-placeholder in a real browser: one status for the whole block and no bar
 * in the tree, a pulse only for whoever has not asked for less motion, and a
 * tile that has the frame and the proportions of the card that will take its
 * place.
 */

const address = ['/_styleguide', 'components', 'placeholder'].join('/');

test('the whole block is one status with one sentence, and no bar is in the tree', async ({ page }) => {
    await page.goto(address);

    await expect(stage(page, 'lines of text')).toMatchAriaSnapshot('- status: Loading the note…');
    await expect(stage(page, 'lines of text').getByRole('status')).toHaveCount(1);

    const card = stage(page, 'in place of a card').locator('.c-placeholder');
    await expect(card).toMatchAriaSnapshot('- status: Loading more products…');
    await expect(card.getByRole('status')).toHaveCount(0);
    await expect(stage(page, 'in place of a card').getByRole('status')).toHaveCount(1);
});

test('it is nothing to reach by the keyboard', async ({ page }) => {
    await page.goto(address);

    for (const placeholder of await page.locator('.c-placeholder').all()) {
        await placeholder.focus();
        await expect(placeholder).not.toBeFocused();
    }
});

test('the bars pulse only for whoever has not asked for less motion', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'no-preference' });
    await page.goto(address);

    const shapes = page.locator('.c-placeholder__shapes');
    await expect(shapes).toHaveCount(2);
    for (const each of await shapes.all()) {
        expect(await runningOn(each)).toBe(1);
    }

    await page.emulateMedia({ reducedMotion: 'reduce' });
    for (const each of await shapes.all()) {
        expect(await runningOn(each)).toBe(0);
        expect(await each.evaluate((node) => getComputedStyle(node).opacity)).toBe('1');
    }
});

for (const theme of themes) {
    for (const mode of modes) {
        const where = `${theme}, ${mode}`;

        test(`in place of a card it has the card's frame and proportions in ${where}`, async ({ page }) => {
            await page.emulateMedia({ reducedMotion: 'reduce' });
            await page.goto(address);
            await drawIn(page, theme, mode);

            const grid = stage(page, 'in place of a card');
            const [card, placeholder] = await Promise.all([
                grid.locator('.c-card').boundingBox(),
                grid.locator('.c-placeholder').boundingBox(),
            ]);
            const [cardMedia, placeholderMedia] = await Promise.all([
                grid.locator('.c-card__media').boundingBox(),
                grid.locator('.c-placeholder__media').boundingBox(),
            ]);
            expect(card).not.toBeNull();
            expect(placeholder?.width, `the placeholder is not as wide as the card in ${where}`).toBeCloseTo(card?.width ?? 0, 0);
            expect(placeholder?.y, `the placeholder is not in the card's row in ${where}`).toBeCloseTo(card?.y ?? 0, 0);
            expect(placeholderMedia?.height, `the picture is not the card's in ${where}`).toBeCloseTo(cardMedia?.height ?? 0, 0);

            const frame = grid.locator('.c-placeholder');
            expectClose(
                await resolved(frame, 'background-color'),
                await resolved(frame, 'background-color', '--color-surface'),
                `the frame in ${where}`,
                1,
            );

            const line = grid.locator('.c-placeholder__line').first();
            expectClose(
                await resolved(line, 'background-color'),
                await resolved(line, 'background-color', '--color-line'),
                `a bar in ${where}`,
                1,
            );
        });
    }
}
