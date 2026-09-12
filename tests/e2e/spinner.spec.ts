import { expect, type Locator, test } from '@playwright/test';
import { contrast, drawIn, expectClose, groundOf, modes, resolved, runningOn, stage, themes } from './paint';

/**
 * c-spinner in a real browser: a status whose words are what the tree holds,
 * a shape that turns only for whoever has not asked for less motion - and,
 * for whoever has, words on the page in place of the turning.
 */

const address = ['/_styleguide', 'components', 'spinner'].join('/');

/** How wide the words are drawn: a visually hidden element is a hairline. */
async function widthOf(element: Locator): Promise<number> {
    return element.evaluate((node) => node.getBoundingClientRect().width);
}

/** How long the custom property is, measured on a probe inside $element, so an em is the element's. */
async function lengthOf(element: Locator, customProperty: string): Promise<number> {
    return element.evaluate((node, name) => {
        const probe = document.createElement('span');
        probe.style.display = 'block';
        probe.style.position = 'absolute';
        probe.style.setProperty('inline-size', `var(${name})`);
        node.append(probe);
        const width = probe.getBoundingClientRect().width;
        probe.remove();

        return width;
    }, customProperty);
}

test('it is a status that says what is being waited for, and the shape is not in the tree', async ({ page }) => {
    await page.goto(address);

    const status = stage(page, 'default').getByRole('status');
    await expect(status).toHaveCount(1);
    await expect(status).toHaveText('Loading the orders…');
    await expect(stage(page, 'default')).toMatchAriaSnapshot('- status: Loading the orders…');

    // Beside a button, the spinner is one more status and the button is untouched.
    await expect(stage(page, 'small').getByRole('status')).toHaveText('Saving the draft…');
    await expect(stage(page, 'small').getByRole('button', { name: 'Save the draft' })).toBeVisible();
});

test('it is nothing to reach by the keyboard', async ({ page }) => {
    await page.goto(address);

    for (const spinner of await page.locator('.c-spinner').all()) {
        await spinner.focus();
        await expect(spinner).not.toBeFocused();
    }
});

test('while it turns its words are read out and not shown', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'no-preference' });
    await page.goto(address);

    const spinner = stage(page, 'default').locator('.c-spinner');
    expect(await runningOn(spinner.locator('.c-spinner__shape'))).toBe(1);
    expect(await widthOf(spinner.locator('.c-spinner__label'))).toBeLessThanOrEqual(1);
    await expect(spinner).toHaveText('Loading the orders…');

    // Unless they are asked for on the page.
    const labelled = stage(page, 'with its words shown').locator('.c-spinner');
    expect(await runningOn(labelled.locator('.c-spinner__shape'))).toBe(1);
    expect(await widthOf(labelled.locator('.c-spinner__label'))).toBeGreaterThan(10);
});

test('for whoever asked for less motion it stands still and shows its words', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.goto(address);

    for (const variant of ['default', 'small', 'with its words shown']) {
        const spinner = stage(page, variant).locator('.c-spinner');
        expect(await runningOn(spinner.locator('.c-spinner__shape')), `${variant} turns`).toBe(0);
        expect(await widthOf(spinner.locator('.c-spinner__label')), `${variant} hides its words`).toBeGreaterThan(10);
        await expect(spinner.locator('.c-spinner__label')).toBeVisible();
    }
});

for (const theme of themes) {
    test(`both sizes are the theme's in ${theme}`, async ({ page }) => {
        // Standing still: a turning square is wider than itself on the screen.
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await page.goto(address);
        await drawIn(page, theme, 'light');

        const regular = stage(page, 'default').locator('.c-spinner');
        expect(await widthOf(regular.locator('.c-spinner__shape'))).toBeCloseTo(await lengthOf(regular, '--spinner-size'), 1);

        const small = stage(page, 'small').locator('.c-spinner');
        expect(await widthOf(small.locator('.c-spinner__shape'))).toBeCloseTo(await lengthOf(small, '--icon-size'), 1);
        expect(await widthOf(small.locator('.c-spinner__shape'))).toBeLessThan(await widthOf(regular.locator('.c-spinner__shape')));
    });

    for (const mode of modes) {
        const where = `${theme}, ${mode}`;

        test(`the ring is drawn out of the tokens and stands out from its ground in ${where}`, async ({ page }) => {
            await page.goto(address);
            await drawIn(page, theme, mode);

            const shape = stage(page, 'default').locator('.c-spinner__shape');
            const arc = await resolved(shape, 'border-top-color');
            expectClose(arc, await resolved(shape, 'color', '--color-accent'), `the turning quarter in ${where}`, 1);
            expectClose(
                await resolved(shape, 'border-right-color'),
                await resolved(shape, 'color', '--color-line'),
                `the rest of the ring in ${where}`,
                1,
            );
            expect(contrast(arc, await groundOf(shape)), `the turning quarter against its ground in ${where}`)
                .toBeGreaterThanOrEqual(3);
        });
    }
}
