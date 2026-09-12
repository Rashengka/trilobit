import { expect, type Locator, type Page, test } from '@playwright/test';
import { contrast, drawIn, expectClose, groundOf, modes, resolved, stage, themes } from './paint';

/**
 * The variants c-button, c-badge and c-card gained for the uses the shop, the
 * CRM and the CMS have for them: a button that is only an icon and is still
 * reached and named, a button and a badge in the ink of a refusal that can be
 * read in every theme and mode, a smaller button for a row of a table, and a
 * card whose footer sits at its foot and stays out of its link.
 */

const address = (component: string): string => ['/_styleguide', 'components', component].join('/');

/** Tab from the top of the page until $target has the focus, the way somebody with a keyboard gets there. */
async function tabTo(page: Page, target: Locator): Promise<void> {
    for (let press = 0; press < 400; press++) {
        if (await target.evaluate((node) => node === document.activeElement)) {
            return;
        }

        await page.keyboard.press('Tab');
    }

    throw new Error('the keyboard never reached it');
}

test('a button that is only an icon is reached by the keyboard under its label', async ({ page }) => {
    await page.goto(address('button'));

    const control = stage(page, 'with only an icon').getByRole('link', { name: 'Sign out', exact: true });
    await tabTo(page, control);

    await expect(control).toBeFocused();
    await expect(control).toHaveAccessibleName('Sign out');
    await expect(control.locator('svg.c-icon')).toBeVisible();

    // As much room beside the icon as above it.
    const box = await control.boundingBox();
    expect(Math.abs((box?.width ?? 0) - (box?.height ?? 1)), 'the button that is only an icon is not square').toBeLessThanOrEqual(1);

    // The focus ring is the one every control has.
    expect(await control.evaluate((node) => getComputedStyle(node).outlineStyle)).not.toBe('none');
});

test('a small button is smaller than the button of the page, in either variant', async ({ page }) => {
    await page.goto(address('button'));

    const quiet = await stage(page, 'quiet').locator('.c-button').boundingBox();
    const small = await stage(page, 'small').getByRole('link', { name: 'Open' }).boundingBox();
    const danger = await stage(page, 'danger').locator('.c-button').boundingBox();
    const smallDanger = await stage(page, 'small').getByRole('button', { name: 'Remove' }).boundingBox();

    expect(small?.height).toBeLessThan(quiet?.height ?? 0);
    expect(smallDanger?.height).toBeLessThan(danger?.height ?? 0);
    expect(smallDanger?.height).toBeCloseTo(small?.height ?? 0, 0);
});

test('a card\'s footer sits at its foot, lines up along the row, and is not part of its link', async ({ page }) => {
    await page.goto(address('card'));

    const cards = stage(page, 'with a footer').locator('.c-card');
    await expect(cards).toHaveCount(2);

    const footers: { top: number }[] = [];
    for (const card of await cards.all()) {
        await expect(card.getByRole('link')).toHaveCount(1);
        const title = (await card.locator('.c-card__title').textContent())?.trim() ?? '';
        await expect(card.getByRole('link')).toHaveAccessibleName(title);

        const [tile, footer] = await Promise.all([card.boundingBox(), card.locator('.c-card__footer').boundingBox()]);
        expect((footer?.y ?? 0) + (footer?.height ?? 0), 'the footer is not at the foot of the tile').toBeCloseTo(
            (tile?.y ?? 0) + (tile?.height ?? 0) - 1,
            0,
        );
        footers.push({ top: footer?.y ?? 0 });
    }

    expect(footers[0]?.top, 'the footers of a row do not line up').toBeCloseTo(footers[1]?.top ?? -1, 0);
});

test('a notice with a title is one region holding the title and the sentence, and no heading', async ({ page }) => {
    await page.goto(address('notice'));

    const notice = stage(page, 'with a title');
    const region = notice.getByRole('alert');
    await expect(region).toHaveCount(1);
    await expect(region).toContainText('The page was not published');
    await expect(region).toContainText('Its address is taken by another page.');
    await expect(notice.getByRole('heading')).toHaveCount(0);

    const weightOf = async (selector: string): Promise<number> =>
        Number(await notice.locator(selector).evaluate((node) => getComputedStyle(node).fontWeight));
    expect(await weightOf('.c-notice__title'), 'the title does not stand out from the sentence').toBeGreaterThan(
        await weightOf('.c-notice__message'),
    );
});

for (const theme of themes) {
    for (const mode of modes) {
        const where = `${theme}, ${mode}`;

        test(`the button and the badge in the ink of a refusal can be read in ${where}`, async ({ page }) => {
            await page.goto(address('button'));
            await drawIn(page, theme, mode);

            const button = stage(page, 'danger').locator('.c-button');
            const ink = await resolved(button, 'color');
            expectClose(ink, await resolved(button, 'color', '--color-danger'), `the danger button's ink in ${where}`, 1);
            expect(contrast(ink, await groundOf(button)), `the danger button's label in ${where}`).toBeGreaterThanOrEqual(4.5);
            expect(contrast(await resolved(button, 'border-top-color'), await groundOf(button)), `its edge in ${where}`)
                .toBeGreaterThanOrEqual(3);

            // Measured once the button's own transition has run its course.
            await button.hover();
            const wash = await resolved(button, 'color', '--color-danger-surface');
            await expect
                .poll(async () => resolved(button, 'background-color'), { message: `under the pointer in ${where}` })
                .toEqual(wash);
            const hovered = await resolved(button, 'background-color');
            expect(contrast(await resolved(button, 'color'), hovered), `its label under the pointer in ${where}`)
                .toBeGreaterThanOrEqual(4.5);

            await page.goto(address('badge'));
            await drawIn(page, theme, mode);

            const badge = stage(page, 'danger').locator('.c-badge');
            const ground = await resolved(badge, 'background-color');
            expectClose(ground, await resolved(badge, 'color', '--color-danger-surface'), `the danger badge in ${where}`, 1);
            expect(contrast(await resolved(badge, 'color'), ground), `the danger badge's label in ${where}`)
                .toBeGreaterThanOrEqual(4.5);
        });
    }
}
