import { expect, type Locator, type Page, test } from '@playwright/test';

import { drawIn, stage, themes } from './paint';

/**
 * The arrangements of a generated form, measured where they are drawn: the
 * Layout page of the Forms group of the style guide, the same form in each.
 *
 * Two claims, and only a browser can make either. What somebody using a screen
 * reader is told: every control has a name, the refused one says why, the one
 * with a hint says it, and a label taken out of sight still names its control.
 * And where things end up: horizontal puts every label to the left of its
 * control, vertical over it, inline puts the fields beside each other - in both
 * themes, because the second one narrows the content and moves the navigation
 * beside it, which is the kind of change a layout right in one theme only would
 * not survive.
 *
 * tests/Template/FormArrangementsDifferOnlyInTheirWrappingTest holds that the
 * arrangements draw the same things; this holds that they draw them where they
 * say.
 */

const address = ['/_styleguide', 'forms', 'layout'].join('/');

const arrangements = ['inline', 'inline, with the labels out of sight', 'vertical', 'horizontal'] as const;

const inline = ['inline', 'inline, with the labels out of sight'] as const;

/** Every control of the sample form with a label of its own, by what it is to a screen reader. */
const labelled = [
    ['textbox', 'Name of the specimen'],
    ['textbox', "Curator's address"],
    ['combobox', 'Period'],
    ['textbox', 'Notes'],
] as const;

interface Box {
    x: number;
    y: number;
    width: number;
    height: number;
}

function formIn(page: Page, arrangement: string): Locator {
    return stage(page, arrangement).locator('form');
}

async function boxOf(element: Locator): Promise<Box> {
    const box = await element.boundingBox();
    expect(box, 'the element is not drawn').not.toBeNull();

    return box as Box;
}

/** The label pointing at $control, by the id it points at. */
async function labelOf(form: Locator, control: Locator): Promise<Locator> {
    const id = await control.getAttribute('id');
    expect(id, 'the control has no id for a label to point at').not.toBeNull();

    return form.locator(`label[for="${id}"]`);
}

for (const arrangement of arrangements) {
    test(`every control of the ${arrangement} form is named, and told what is said about it`, async ({ page }) => {
        await page.goto(address);
        const form = formIn(page, arrangement);

        for (const [role, name] of labelled) {
            await expect(form.getByRole(role, { name, exact: true })).toHaveCount(1);
        }

        await expect(form.getByRole('checkbox', { name: 'On public display', exact: true })).toHaveCount(1);
        await expect(form.getByRole('radiogroup', { name: 'State of the specimen', exact: true })).toHaveCount(1);
        await expect(form.getByRole('radio', { name: 'Complete', exact: true })).toHaveCount(1);
        await expect(form.getByRole('radio', { name: 'A fragment', exact: true })).toHaveCount(1);
        await expect(form.getByRole('button', { name: 'Save', exact: true })).toHaveCount(1);

        // Refused, and saying why to somebody who cannot see the sentence
        // under it.
        const refused = form.getByRole('combobox', { name: 'Period', exact: true });
        await expect(refused).toHaveAttribute('aria-invalid', 'true');
        await expect(refused).toHaveAccessibleDescription('No drawer in the collection holds that period.');

        await expect(form.getByRole('textbox', { name: "Curator's address", exact: true })).toHaveAccessibleDescription(
            'Where questions about the specimen are sent.',
        );

        // And nothing else is described or refused: a sentence joined to the
        // wrong control is as wrong as one joined to none.
        const plain = form.getByRole('textbox', { name: 'Name of the specimen', exact: true });
        await expect(plain).toHaveAccessibleDescription('');
        await expect(plain).not.toHaveAttribute('aria-invalid');
        await expect(plain).toHaveAttribute('required', '');

        await expect(form.locator('.l-form__errors')).toHaveText('The catalogue could not be saved just now.');
    });
}

test('a label out of sight is still in the page, and still names its control', async ({ page }) => {
    await page.goto(address);
    const seen = formIn(page, 'inline');
    const unseen = formIn(page, 'inline, with the labels out of sight');

    for (const [role, name] of labelled) {
        // In the page, pointing at its control, which is named by it.
        const control = unseen.getByRole(role, { name, exact: true });
        const label = await labelOf(unseen, control);
        await expect(label).toHaveText(name);

        // Out of sight: clipped to nothing, and taking no room - the control
        // starts where its field does.
        expect(await label.evaluate((node) => getComputedStyle(node.parentElement ?? node).clipPath)).toBe('inset(50%)');
        const field = control.locator('xpath=ancestor::div[contains(concat(" ", normalize-space(@class), " "), " c-field ")][1]');
        expect((await boxOf(control)).y, `the label of ${name} still takes room`).toBeCloseTo((await boxOf(field)).y, 0);

        // Seen, it is over its control, in room of its own.
        const shown = seen.getByRole(role, { name, exact: true });
        const over = await boxOf(await labelOf(seen, shown));
        expect(over.y + over.height, `the label of ${name} is not over its control`).toBeLessThanOrEqual(
            (await boxOf(shown)).y,
        );
    }
});

for (const theme of themes) {
    test(`every arrangement lays its fields out the way it is named, in ${theme}`, async ({ page }) => {
        // A window wide enough, and the widest content, for a row that can be
        // one line to be one.
        await page.setViewportSize({ width: 1920, height: 1080 });
        await page.goto(address);
        await drawIn(page, theme, 'light');
        await page.evaluate(() => document.documentElement.setAttribute('data-content-width', 'full'));

        const horizontal = formIn(page, 'horizontal');
        const vertical = formIn(page, 'vertical');
        const columns: number[] = [];

        for (const [role, name] of labelled) {
            // Horizontal: the label to the left of its control, on its line.
            const control = horizontal.getByRole(role, { name, exact: true });
            const beside = await boxOf(await labelOf(horizontal, control));
            const box = await boxOf(control);
            expect(beside.x + beside.width, `the label of ${name} runs into its control`).toBeLessThanOrEqual(box.x);
            expect(beside.y, `the label of ${name} is under its control`).toBeLessThan(box.y + box.height);
            expect(beside.y + beside.height, `the label of ${name} is over its control`).toBeGreaterThan(box.y);
            columns.push(box.x);

            // Vertical: over it, starting where it starts.
            const under = vertical.getByRole(role, { name, exact: true });
            const over = await boxOf(await labelOf(vertical, under));
            const underBox = await boxOf(under);
            expect(over.y + over.height, `the label of ${name} is not over its control`).toBeLessThanOrEqual(underBox.y);
            expect(over.x, `the label of ${name} does not start where its control does`).toBeCloseTo(underBox.x, 0);
        }

        // Horizontal: the controls in one column, and the button starting
        // under them rather than under the labels.
        for (const column of columns) {
            expect(column, 'the controls of the horizontal form are not in one column').toBeCloseTo(columns[0] ?? 0, 0);
        }
        const save = await boxOf(horizontal.getByRole('button', { name: 'Save', exact: true }));
        expect(save.x, 'the button does not start under the controls').toBeCloseTo(columns[0] ?? 0, 0);

        // Inline: every field beside the one before it, their controls
        // starting on one line, and the button under all of them.
        for (const arrangement of inline) {
            const form = formIn(page, arrangement);
            const fields = await Promise.all((await form.locator('.l-form > .c-field').all()).map(boxOf));
            const slots = await Promise.all((await form.locator('.l-form > .c-field > .c-field__control').all()).map(boxOf));
            expect(fields).toHaveLength(6);

            for (let index = 1; index < fields.length; index++) {
                const before = fields[index - 1] as Box;
                const field = fields[index] as Box;
                expect(field.x, `field ${index + 1} of ${arrangement} is not beside the one before it`).toBeGreaterThanOrEqual(
                    before.x + before.width,
                );
                expect((slots[index] as Box).y, `the control of field ${index + 1} of ${arrangement} is not on the line`).toBeCloseTo(
                    (slots[0] as Box).y,
                    0,
                );
            }

            const button = await boxOf(form.getByRole('button', { name: 'Save', exact: true }));
            expect(button.y, `the button of ${arrangement} is not under the fields`).toBeGreaterThanOrEqual(
                Math.max(...fields.map((field) => field.y + field.height)),
            );
        }

        // And in none of them does a hidden input take room: every one is after
        // the arrangement rather than in it - the sample's own, and the one the
        // framework adds to a form a presenter answers - and the form is
        // exactly as tall as the arrangement is.
        for (const arrangement of arrangements) {
            const form = formIn(page, arrangement);
            await expect(form.locator('input[type="hidden"][name="drawer"]')).toHaveCount(1);
            const afterTheArrangement = await form.evaluate((node) => {
                const laidOut = node.querySelector(':scope > .l-form');

                return [...node.querySelectorAll('input[type="hidden"]')].map(
                    (input) =>
                        input.parentElement === node &&
                        laidOut !== null &&
                        (laidOut.compareDocumentPosition(input) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0,
                );
            });
            expect(afterTheArrangement, `a hidden input of ${arrangement} is not after the arrangement`).not.toContain(false);
            expect((await boxOf(form)).height, `something after the ${arrangement} arrangement takes room`).toBeCloseTo(
                (await boxOf(form.locator('.l-form'))).height,
                0,
            );
        }
    });
}
