import { expect, type Locator, type Page, test } from '@playwright/test';

import { paintedAlong } from './paint';

/**
 * The native form controls are drawn out of the theme that is on, in both
 * themes and both modes - measured in the browser, not read off the stylesheet.
 *
 * Why it has to be measured: the two themes declare their tokens in two
 * different ways (atrium through Tailwind's @theme, inside a layer; ledger as a
 * plain selector, outside every layer), and an unlayered rule beats any layer.
 * A rule for the controls can therefore be present in assets/base.css, correct
 * on paper and applied in one theme while the other one quietly overrules it.
 * Only the computed style says which rule won.
 *
 * Every reading is compared with the token it should come from, resolved by
 * the same browser on a probe next to the element, so the assertion is "this is
 * the theme's value" rather than a number copied out of a theme file. And the
 * two themes are compared with each other, so that a control stuck on one
 * theme's values - which would equal the probe in that theme only - cannot pass.
 */

const themes = ['atrium', 'ledger'] as const;

const modes = ['light', 'dark'] as const;

const page = (slug: string): string => ['/_styleguide', 'forms', slug].join('/');

/** The controls somebody types into or picks from, on the Controls page. */
const lines = [
    'forms-control-text',
    'forms-control-email',
    'forms-control-password',
    'forms-control-number',
    'forms-control-search',
    'forms-control-date',
    'forms-control-file',
    'forms-control-textarea',
    'forms-control-select',
    'forms-control-select-multiple',
] as const;

async function drawIn(browserPage: Page, theme: string, mode: string): Promise<void> {
    await browserPage.evaluate(
        ([chosenTheme, chosenMode]) => {
            document.documentElement.setAttribute('data-theme', chosenTheme);
            document.documentElement.setAttribute('data-theme-mode', chosenMode);
        },
        [theme, mode],
    );
}

/** One property of the element, as the browser computed it. */
async function computed(element: Locator, property: string): Promise<string> {
    return element.evaluate((node, name) => getComputedStyle(node).getPropertyValue(name), property);
}

/**
 * What the token resolves to for the same property, on a probe placed beside
 * the element - beside it, so that a token in em is resolved against the same
 * font size and a light-dark() pair against the same colour scheme.
 */
async function token(element: Locator, property: string, name: string): Promise<string> {
    return element.evaluate(
        (node, [prop, tokenName]) => {
            const probe = document.createElement('span');
            probe.style.display = 'block';
            probe.style.position = 'absolute';
            probe.style.setProperty(prop, `var(${tokenName})`);
            (node.parentElement ?? document.body).append(probe);
            const value = getComputedStyle(probe).getPropertyValue(prop);
            probe.remove();

            return value;
        },
        [property, name],
    );
}

async function expectFromToken(element: Locator, property: string, name: string, where: string): Promise<void> {
    expect(await computed(element, property), `${property} of ${where} is not ${name}`).toBe(
        await token(element, property, name),
    );
}

async function problemsOn(browserPage: Page): Promise<string[]> {
    const problems: string[] = [];
    browserPage.on('console', (message) => {
        if (message.type() === 'error') {
            problems.push(message.text());
        }
    });
    browserPage.on('pageerror', (error) => problems.push(error.message));

    return problems;
}

for (const theme of themes) {
    for (const mode of modes) {
        const where = `${theme}, ${mode}`;

        test(`every line of text and every list is drawn out of the tokens in ${where}`, async ({ page: browserPage }) => {
            const problems = await problemsOn(browserPage);
            await browserPage.goto(page('controls'));
            await drawIn(browserPage, theme, mode);

            for (const testId of lines) {
                const control = browserPage.getByTestId(testId);
                const label = `${testId} in ${where}`;

                await expectFromToken(control, 'background-color', '--color-surface', label);
                await expectFromToken(control, 'color', '--color-ink', label);
                await expectFromToken(control, 'border-top-color', '--color-line', label);
                await expectFromToken(control, 'border-top-width', '--border-hairline', label);
                await expectFromToken(control, 'border-top-left-radius', '--radius-sm', label);
                await expectFromToken(control, 'padding-top', '--space-xs', label);
                if (testId === 'forms-control-select' && (await browserPage.evaluate(() => CSS.supports('appearance', 'base-select')))) {
                    // Chromium's own inset inside a list of one line is taken
                    // off its padding (assets/base.css), so that its text
                    // starts where a line's does - measured on the pixels by
                    // the test of where the text of a list starts.
                    const space = parseFloat(await token(control, 'padding-inline-start', '--space-sm'));
                    const inset = parseFloat(await token(control, 'padding-inline-start', '--control-list-inset'));
                    expect(
                        parseFloat(await computed(control, 'padding-inline-start')),
                        `padding-inline-start of ${label} is not --space-sm less --control-list-inset`,
                    ).toBeCloseTo(space - inset, 1);
                } else {
                    await expectFromToken(control, 'padding-inline-start', '--space-sm', label);
                }

                // As wide as the place it was put in: how wide that is, is the
                // arrangement's business and not the control's.
                const [own, room] = await control.evaluate((node) => [
                    node.getBoundingClientRect().width,
                    node.parentElement?.getBoundingClientRect().width ?? 0,
                ]);
                expect(own, `${label} does not fill the place it was put in`).toBeCloseTo(room, 0);
            }

            expect(problems).toEqual([]);
        });

        test(`checkboxes and radio buttons take the accent and the size of the text in ${where}`, async ({
            page: browserPage,
        }) => {
            const problems = await problemsOn(browserPage);
            await browserPage.goto(page('checks'));
            await drawIn(browserPage, theme, mode);

            for (const testId of ['forms-checkbox', 'forms-radio']) {
                const choice = browserPage.getByTestId(testId);
                const label = `${testId} in ${where}`;

                await expectFromToken(choice, 'accent-color', '--color-accent', label);
                await expectFromToken(choice, 'width', '--icon-size', label);
                await expectFromToken(choice, 'height', '--icon-size', label);

                // The label it is written inside holds the box and the words
                // on one line, a set distance apart. It is a flex container
                // either way: base.css asks for inline-flex, and a label that
                // is itself an item of a stack or a cluster - as these are -
                // is blockified by the browser into flex, which is the same
                // box laid out the same way inside.
                const around = choice.locator('xpath=..');
                expect(await around.evaluate((node) => node.tagName)).toBe('LABEL');
                expect(['flex', 'inline-flex']).toContain(await computed(around, 'display'));
                expect(await computed(around, 'align-items')).toBe('center');
                await expectFromToken(around, 'column-gap', '--space-xs', `the label of ${label}`);
            }

            expect(problems).toEqual([]);
        });

        test(`a fieldset is framed and its legend named out of the tokens in ${where}`, async ({ page: browserPage }) => {
            const problems = await problemsOn(browserPage);
            await browserPage.goto(page('fieldsets'));
            await drawIn(browserPage, theme, mode);

            const fieldset = browserPage.getByTestId('forms-fieldset');
            await expectFromToken(fieldset, 'border-top-color', '--color-line', `the fieldset in ${where}`);
            await expectFromToken(fieldset, 'border-top-width', '--border-hairline', `the fieldset in ${where}`);
            await expectFromToken(fieldset, 'border-top-left-radius', '--radius-md', `the fieldset in ${where}`);
            await expectFromToken(fieldset, 'padding-inline-start', '--space-md', `the fieldset in ${where}`);

            const legend = fieldset.locator('legend');
            await expectFromToken(legend, 'color', '--color-ink-muted', `the legend in ${where}`);
            await expectFromToken(legend, 'font-size', '--text-sm', `the legend in ${where}`);

            expect(problems).toEqual([]);
        });

        test(`the states of a control are drawn out of the tokens in ${where}`, async ({ page: browserPage }) => {
            const problems = await problemsOn(browserPage);
            await browserPage.goto(page('states'));
            await drawIn(browserPage, theme, mode);

            // A hint, joined to its control.
            const hinted = browserPage.getByTestId('forms-state-hinted');
            const hint = browserPage.getByTestId('forms-state-hinted-field').locator('.c-field__hint');
            expect(await hinted.getAttribute('aria-describedby')).toBe(await hint.getAttribute('id'));
            await expectFromToken(hint, 'color', '--color-ink-muted', `the hint in ${where}`);
            await expectFromToken(hint, 'font-size', '--text-sm', `the hint in ${where}`);

            // Refused by the server: the control says so and so does the
            // sentence under it.
            const refused = browserPage.getByTestId('forms-state-refused');
            const reason = browserPage.getByTestId('forms-state-refused-field').locator('.c-field__error');
            expect(await refused.getAttribute('aria-invalid')).toBe('true');
            expect(await refused.getAttribute('aria-describedby')).toBe(await reason.getAttribute('id'));
            await expectFromToken(refused, 'border-top-color', '--color-danger', `the refused control in ${where}`);
            await expectFromToken(reason, 'color', '--color-danger', `the reason in ${where}`);

            // Disabled: set back into the page, and so is the label of a
            // choice that cannot be made.
            const disabled = browserPage.getByTestId('forms-state-disabled');
            await expectFromToken(disabled, 'background-color', '--color-canvas-sunken', `the disabled control in ${where}`);
            await expectFromToken(disabled, 'color', '--color-ink-muted', `the disabled control in ${where}`);
            const disabledChoice = browserPage.getByTestId('forms-state-disabled-choice').locator('xpath=..');
            await expectFromToken(disabledChoice, 'color', '--color-ink-muted', `the disabled choice in ${where}`);

            // Refused by the browser, and only once somebody has had a go at
            // it: an empty required address is not drawn as a mistake before
            // anyone typed, which is the difference between :user-invalid and
            // :invalid.
            const checked = browserPage.getByTestId('forms-state-checked');
            await expectFromToken(checked, 'border-top-color', '--color-line', `the untouched control in ${where}`);
            await checked.fill('not an address');
            await checked.press('Tab');
            await expectFromToken(checked, 'border-top-color', '--color-danger', `the mistyped control in ${where}`);

            expect(problems).toEqual([]);
        });

        test(`a focused control is marked out of the tokens in ${where}`, async ({ page: browserPage }) => {
            await browserPage.goto(page('controls'));
            await drawIn(browserPage, theme, mode);

            const control = browserPage.getByTestId('forms-control-text');
            await control.focus();

            expect(await control.evaluate((node) => node.matches(':focus-visible'))).toBe(true);
            expect(await computed(control, 'outline-style')).toBe('solid');
            await expectFromToken(control, 'outline-color', '--color-focus', `the focused control in ${where}`);
            await expectFromToken(control, 'outline-width', '--border-marker', `the focused control in ${where}`);
            await expectFromToken(control, 'border-top-color', '--color-accent', `the focused control in ${where}`);
        });
    }
}

/** The controls that hold one line, on the Controls page: every kind of input, and a list of one answer. */
const oneLine = [
    'forms-control-text',
    'forms-control-email',
    'forms-control-password',
    'forms-control-number',
    'forms-control-search',
    'forms-control-date',
    'forms-control-file',
    'forms-control-select',
    'forms-control-alone',
] as const;

async function heightOf(element: Locator): Promise<number> {
    return element.evaluate((node) => node.getBoundingClientRect().height);
}

for (const theme of themes) {
    for (const mode of modes) {
        const where = `${theme}, ${mode}`;

        /*
         * A row of a filter puts a line of text, a list and a combobox side by
         * side, and a native select is drawn by the browser to a height of its
         * own whatever its padding says - so the heights are read off the
         * boxes the browser drew, every kind against a line of text.
         */
        test(`every control of one line is as tall as a line of text in ${where}`, async ({ page: browserPage }) => {
            await browserPage.goto(page('controls'));
            await drawIn(browserPage, theme, mode);

            const text = await heightOf(browserPage.getByTestId('forms-control-text'));
            for (const testId of oneLine) {
                expect(await heightOf(browserPage.getByTestId(testId)), `${testId} in ${where}`).toBeCloseTo(text, 0);
            }

            // Several lines and several answers are not one line, and keep
            // the height of what they hold.
            expect(await heightOf(browserPage.getByTestId('forms-control-textarea'))).toBeGreaterThan(text * 1.5);
            expect(await heightOf(browserPage.getByTestId('forms-control-select-multiple'))).toBeGreaterThan(text * 1.5);

            // A list that says size="1" shows one row, as one without a size
            // does, and is one line; a size of more than one is several rows.
            const sized = await browserPage.getByTestId('forms-control-select').evaluate((node) => {
                const heights: Record<string, number> = {};
                for (const size of ['1', '4']) {
                    const probe = node.cloneNode(true) as HTMLSelectElement;
                    probe.removeAttribute('data-testid');
                    probe.removeAttribute('id');
                    probe.setAttribute('size', size);
                    node.after(probe);
                    heights[size] = probe.getBoundingClientRect().height;
                    probe.remove();
                }

                return heights;
            });
            expect(sized['1'], `a select of size="1" in ${where}`).toBeCloseTo(text, 0);
            expect(sized['4'], `a select of size="4" in ${where}`).toBeGreaterThan(text * 1.5);

            // A combobox is a select drawn by a script, and is measured
            // against a line of text put beside it on its own page.
            await browserPage.goto('/_styleguide/components/combobox');
            await drawIn(browserPage, theme, mode);
            const combobox = browserPage.locator('.c-combobox__control').first();
            await expect(combobox).toBeVisible();
            const beside = await combobox.evaluate((node) => {
                const probe = document.createElement('input');
                probe.type = 'text';
                node.closest('.c-combobox')?.parentElement?.append(probe);
                const height = probe.getBoundingClientRect().height;
                probe.remove();

                return height;
            });
            expect(await heightOf(combobox), `c-combobox in ${where}`).toBeCloseTo(beside, 0);
        });
    }
}

/**
 * The same control in the two themes is not the same box. Every reading above
 * could equal its probe and still be one theme's values in both - a fallback, a
 * value frozen at build time - so here the themes are held against each other.
 */
test('a control reads the theme that is on, not the one that was on first', async ({ page: browserPage }) => {
    await browserPage.goto(page('controls'));
    const control = browserPage.getByTestId('forms-control-text');

    const read = async (theme: string): Promise<Record<string, string>> => {
        await drawIn(browserPage, theme, 'light');

        return {
            border: await computed(control, 'border-top-color'),
            radius: await computed(control, 'border-top-left-radius'),
            padding: await computed(control, 'padding-inline-start'),
            background: await computed(control, 'background-color'),
        };
    };

    const atrium = await read('atrium');
    const ledger = await read('ledger');

    expect(ledger.border).not.toBe(atrium.border);
    expect(ledger.radius).not.toBe(atrium.radius);
    expect(ledger.padding).not.toBe(atrium.padding);
    expect(ledger.background).not.toBe(atrium.background);
});

/**
 * A control looks the same inside c-field and on its own: the arrangement is a
 * layer above the controls, never a reason for one to look different.
 */
test('a control is drawn the same with or without the field around it', async ({ page: browserPage }) => {
    await browserPage.goto(page('controls'));

    const inField = browserPage.getByTestId('forms-control-text');
    const alone = browserPage.getByTestId('forms-control-alone');
    expect(await alone.evaluate((node) => node.closest('.c-field'))).toBeNull();
    expect(await inField.evaluate((node) => node.closest('.c-field') !== null)).toBe(true);

    for (const property of [
        'background-color',
        'border-top-color',
        'border-top-width',
        'border-top-left-radius',
        'padding-top',
        'padding-inline-start',
        'font-size',
        'color',
    ]) {
        expect(await computed(alone, property), property).toBe(await computed(inField, property));
    }
});

/**
 * The column a control's text starts in, in pixels from its edge: the first
 * pixel along the middle of the control, past its border, that is painted
 * apart from its ground. The ground is read in the middle of the control,
 * where neither the text nor a select's arrow is drawn.
 *
 * Read off the pixels because the place in question is the browser's own: a
 * native select adds a padding of its own inside the one the stylesheet gives
 * it, and no computed style reports it.
 */
async function textStartOf(control: Locator): Promise<number> {
    const row = await paintedAlong(control);
    const ground = row[Math.floor(row.length / 2)] ?? [0, 0, 0];
    const border = await control.evaluate((node) => Math.ceil(parseFloat(getComputedStyle(node).borderLeftWidth)));
    for (let at = border + 1; at < row.length / 2; at++) {
        const pixel = row[at] ?? ground;
        if (Math.max(...pixel.map((channel, index) => Math.abs(channel - (ground[index] ?? 0)))) > 64) {
            return at;
        }
    }

    throw new Error('no text drawn in the first half of the control');
}

for (const theme of themes) {
    test(`the text of a list starts where the text of a line does, in ${theme}`, async ({ page: browserPage }) => {
        await browserPage.goto(page('controls'));
        await drawIn(browserPage, theme, 'light');

        // A line and a list of the same width holding the same word, one above
        // the other, made on the page so that nothing else about them differs.
        await browserPage.evaluate(() => {
            const column = document.createElement('div');
            column.style.inlineSize = '20rem';
            column.innerHTML =
                '<input data-testid="text-start-line" value="Wm"><select data-testid="text-start-list"><option>Wm</option></select>';
            document.querySelector('main')?.prepend(column);
        });

        const line = await textStartOf(browserPage.getByTestId('text-start-line'));
        const list = await textStartOf(browserPage.getByTestId('text-start-list'));
        expect(Math.abs(list - line), `a list's text starts at ${list}px, a line's at ${line}px, in ${theme}`).toBeLessThanOrEqual(1);
    });
}
