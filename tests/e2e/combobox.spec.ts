import { expect, type Locator, type Page, test } from '@playwright/test';

/**
 * c-combobox in a real browser: the control Tom Select draws in front of a
 * select, on the style guide's page of it.
 *
 * Everything here is about what the library does not do on its own, or does
 * in a way this application decided against (assets/combobox.ts): no list
 * opened by tabbing in, every choice there to be found and not the first fifty,
 * the sentences under a field describing the control, the keys of a list, a
 * live count of what matches, a short list that takes the focus itself - and a
 * control that survives Naja replacing the select under it, including the one
 * history.back() brings back out of Naja's cache.
 *
 * The claims about the accessibility tree are asked of the tree - by role, by
 * name, by description - and not of the markup, because the markup is the
 * library's and the tree is what somebody with a screen reader gets.
 */

const address = ['/_styleguide', 'components', 'combobox'].join('/');

const themes = ['atrium', 'ledger'] as const;

const modes = ['light', 'dark'] as const;

async function drawIn(page: Page, theme: string, mode: string): Promise<void> {
    await page.evaluate(
        ([chosenTheme, chosenMode]) => {
            document.documentElement.setAttribute('data-theme', chosenTheme);
            document.documentElement.setAttribute('data-theme-mode', chosenMode);
        },
        [theme, mode],
    );
}

async function computed(element: Locator, property: string): Promise<string> {
    return element.evaluate((node, name) => getComputedStyle(node).getPropertyValue(name), property);
}

/** What $name resolves to for $property on a probe beside $element; see forms.spec.ts. */
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

function problemsOn(page: Page): string[] {
    const problems: string[] = [];
    page.on('console', (message) => {
        if (message.type() === 'error') {
            problems.push(message.text());
        }
    });
    page.on('pageerror', (error) => problems.push(error.message));

    return problems;
}

/** The control drawn in front of the select with $testId, which the library puts right after it. */
function drawnFor(page: Page, testId: string): Locator {
    return page.locator(`[data-testid="${testId}"] + .c-combobox`);
}

function specimen(page: Page, variant: string): Locator {
    return page.locator(`[data-styleguide-variant="${variant}"]`);
}

test('a long list is not opened by tabbing into it, and is searched by typing', async ({ page }) => {
    const problems = problemsOn(page);
    await page.goto(address);

    const genus = page.getByRole('combobox', { name: 'Genus', exact: true });
    await genus.focus();
    await expect(genus).toBeFocused();
    await expect(genus).toHaveAttribute('aria-expanded', 'false');
    await expect(page.getByRole('listbox', { name: 'Genus', exact: true })).toBeHidden();

    await genus.pressSequentially('trim');
    const list = page.getByRole('listbox', { name: 'Genus', exact: true });
    await expect(genus).toHaveAttribute('aria-expanded', 'true');
    await expect(list.getByRole('option')).toHaveCount(1);
    await expect(list.getByRole('option', { name: 'Trimerus' })).toBeVisible();

    // The answer chosen before steps out of the way of what is typed.
    await expect(drawnFor(page, 'sg-combobox-long').locator('.c-combobox__item')).toBeHidden();

    await genus.press('Enter');
    await expect(page.getByTestId('sg-combobox-long')).toHaveValue('trimerus');
    await expect(genus).toHaveAttribute('aria-expanded', 'false');
    await expect(drawnFor(page, 'sg-combobox-long').locator('.c-combobox__item')).toHaveText('Trimerus');

    expect(problems).toEqual([]);
});

/**
 * Not opened by tabbing in is half of a decision; the other half is that a
 * click does open it. The library opens on a click only by opening on focus,
 * so turning the one off turned the other off with it - and a second click on
 * an open list takes the focus away from the control altogether, where a
 * native select keeps it.
 */
test('a click opens the list, and a second click closes it and keeps the focus', async ({ page }) => {
    await page.goto(address);

    for (const [name, testId] of [['Genus', 'sg-combobox-long'], ['Period', 'sg-combobox-short']] as const) {
        const control = page.getByRole('combobox', { name, exact: true });
        const box = drawnFor(page, testId).locator('.c-combobox__control');

        await box.click();
        await expect(control, `a click did not open ${name}`).toHaveAttribute('aria-expanded', 'true');
        await expect(page.getByRole('listbox', { name, exact: true })).toBeVisible();

        await box.click();
        await expect(control, `a second click did not close ${name}`).toHaveAttribute('aria-expanded', 'false');
        await expect(control, `a second click took the focus away from ${name}`).toBeFocused();
    }
});

/**
 * The library moves the focus a moment after the click, and until then it
 * swallows every key: typing straight after a click lost the letters, and the
 * list stayed unfiltered with nothing to say why.
 */
test('what is typed straight after a click is not lost', async ({ page }) => {
    await page.goto(address);

    await drawnFor(page, 'sg-combobox-long').locator('.c-combobox__control').click();
    await page.keyboard.type('trim');

    const genus = page.getByRole('combobox', { name: 'Genus', exact: true });
    await expect(genus).toHaveValue('trim');
    await expect(page.getByRole('listbox', { name: 'Genus', exact: true }).getByRole('option')).toHaveCount(1);
});

test('every choice of a long list is there to be found, not only the first fifty', async ({ page }) => {
    await page.goto(address);

    const written = await page.getByTestId('sg-combobox-long').locator('option').count();
    expect(written, 'the specimen has to be longer than the library would show by default').toBeGreaterThan(50);

    const genus = page.getByRole('combobox', { name: 'Genus', exact: true });
    await genus.focus();
    await genus.press('ArrowDown');

    await expect(page.getByRole('listbox', { name: 'Genus', exact: true }).getByRole('option')).toHaveCount(written);
});

test('how many choices match what is typed is said out loud', async ({ page }) => {
    await page.goto(address);

    const genus = page.getByRole('combobox', { name: 'Genus', exact: true });
    const status = specimen(page, 'searching a long list').getByRole('status');
    await expect(status).toHaveText('');

    await genus.pressSequentially('phac');
    await expect(status).toHaveText('1 choice matches.');

    await genus.pressSequentially('zzz');
    await expect(status).toHaveText('No choice matches.');

    // The list is filtered a moment after the typing stops, and the count is
    // said then; so the count is waited for first, and the list read after.
    await genus.fill('');
    await genus.pressSequentially('ops');
    await expect(status).toHaveText(/^\d+ choices match\.$/);
    const said = Number.parseInt((await status.textContent()) ?? '', 10);
    const shown = await page.getByRole('listbox', { name: 'Genus', exact: true }).getByRole('option').count();
    expect(shown).toBeGreaterThan(1);
    expect(said, 'the count said is not the number of choices shown').toBe(shown);

    await genus.press('Escape');
    await expect(status).toHaveText('');
});

test('Home, End, Page Up and Page Down move through the open list', async ({ page }) => {
    await page.goto(address);

    const genus = page.getByRole('combobox', { name: 'Genus', exact: true });
    const options = page.getByRole('listbox', { name: 'Genus', exact: true }).getByRole('option');
    const active = async (): Promise<number> => {
        const id = await genus.getAttribute('aria-activedescendant');

        return options.evaluateAll((nodes, wanted) => nodes.findIndex((node) => node.id === wanted), id);
    };

    await genus.focus();
    await genus.press('ArrowDown');
    await expect(genus).toHaveAttribute('aria-expanded', 'true');

    await genus.press('End');
    expect(await active()).toBe((await options.count()) - 1);

    await genus.press('Home');
    expect(await active()).toBe(0);

    await genus.press('PageDown');
    const page1 = await active();
    expect(page1, 'Page Down moved by less than a page').toBeGreaterThan(1);

    await genus.press('PageDown');
    expect(await active()).toBeGreaterThan(page1);

    await genus.press('PageUp');
    expect(await active()).toBe(page1);
});

test('a short list takes the focus itself and answers the keys of a select', async ({ page }) => {
    const problems = problemsOn(page);
    await page.goto(address);

    const period = page.getByRole('combobox', { name: 'Period' });
    await expect(drawnFor(page, 'sg-combobox-short').locator('input')).toHaveCount(0);

    await period.focus();
    await expect(period).toBeFocused();
    await expect(period).toHaveAttribute('aria-expanded', 'false');

    await period.press(' ');
    await expect(period).toHaveAttribute('aria-expanded', 'true');

    await period.press('End');
    await period.press(' ');
    await expect(page.getByTestId('sg-combobox-short')).toHaveValue('devonian');
    await expect(period).toHaveAttribute('aria-expanded', 'false');

    expect(problems).toEqual([]);
});

test('the sentences under a field describe the control, and a refusal is said', async ({ page }) => {
    await page.goto(address);

    const drawer = page.getByRole('combobox', { name: 'Drawer' });
    await expect(drawer).toHaveAccessibleDescription(
        'Drawer B1 is full; pick another one. Counted from the left of the cabinet.',
    );
    await expect(drawer).toHaveAttribute('aria-invalid', 'true');
});

test('the select behind a control is not a second list in the accessibility tree', async ({ page }) => {
    await page.goto(address);

    await expect(page.getByTestId('sg-combobox-long')).toHaveAttribute('aria-hidden', 'true');
    await expect(specimen(page, 'searching a long list').getByRole('combobox')).toHaveCount(1);
    await expect(specimen(page, 'a short list, without a line to search it by').getByRole('combobox')).toHaveCount(1);
});

test('a disabled list cannot be opened or reached', async ({ page }) => {
    await page.goto(address);

    const cabinet = page.getByRole('combobox', { name: 'Cabinet' });
    await expect(cabinet).toHaveAttribute('tabindex', '-1');
    await drawnFor(page, 'sg-combobox-disabled').locator('.c-combobox__control').click({ force: true });
    await expect(cabinet).toHaveAttribute('aria-expanded', 'false');
    await expect(page.getByTestId('sg-combobox-disabled')).toHaveValue('a2');
});

/**
 * Naja replaces the select and the control has to follow it - and it is the
 * way back that is the trap. Naja keeps the markup of every snippet as it
 * starts, and history.back() puts that markup back. Drawn before Naja started,
 * the markup kept would already carry a control: the way back would bring a
 * second control beside the new one, or - with a guard written on the DOM
 * rather than on the library's handle - a dead one that does not open. So the
 * test counts the controls and opens the one that is there.
 *
 * The label is outside the snippet on purpose, which is the shape a list
 * refilled from the server will have: the control has to find its name again
 * each time.
 */
test('a control survives Naja redrawing its select, and history.back() bringing the old one back', async ({ page }) => {
    const problems = problemsOn(page);
    await page.goto(address);

    const snippet = page.getByTestId('sg-combobox-snippet');
    const name = 'Genus, drawn again by the server';
    const control = page.getByRole('combobox', { name });
    await expect(snippet.locator('.c-combobox')).toHaveCount(1);
    await page.getByTestId('sg-combobox-redrawn').evaluate((node) => node.setAttribute('data-drawn-first', ''));

    await page.getByTestId('sg-combobox-redraw').click();
    await expect(page).toHaveURL(/do=redrawSpecimen/);
    await expect(page.getByTestId('sg-combobox-redrawn')).not.toHaveAttribute('data-drawn-first');

    await expect(snippet.locator('.c-combobox')).toHaveCount(1);
    await expect(control).toHaveAccessibleDescription(/asks the server for this field again/);
    await control.click();
    await expect(page.getByRole('listbox', { name })).toBeVisible();
    await control.press('Escape');

    await page.evaluate(() => history.back());
    await expect(page).not.toHaveURL(/do=redrawSpecimen/);

    await expect(snippet.locator('.c-combobox')).toHaveCount(1);
    await expect(control).toHaveCount(1);
    await control.click();
    await expect(page.getByRole('listbox', { name })).toBeVisible();
    await expect(page.getByRole('option', { name: 'Calymene', exact: true })).toBeVisible();

    expect(problems).toEqual([]);
});

for (const theme of themes) {
    for (const mode of modes) {
        const where = `${theme}, ${mode}`;

        test(`the control and its list are drawn out of the tokens in ${where}`, async ({ page }) => {
            const problems = problemsOn(page);
            await page.goto(address);
            await drawIn(page, theme, mode);

            const control = drawnFor(page, 'sg-combobox-long').locator('.c-combobox__control');
            const label = `the control in ${where}`;
            await expectFromToken(control, 'background-color', '--color-surface', label);
            await expectFromToken(control, 'color', '--color-ink', label);
            await expectFromToken(control, 'border-top-color', '--color-line', label);
            await expectFromToken(control, 'border-top-width', '--border-hairline', label);
            await expectFromToken(control, 'border-top-left-radius', '--radius-sm', label);
            await expectFromToken(control, 'padding-top', '--space-xs', label);
            await expectFromToken(control, 'padding-inline-start', '--space-sm', label);

            // As wide as the place it was put in, the way a native control is.
            const [own, room] = await control.evaluate((node) => [
                node.getBoundingClientRect().width,
                node.closest('.c-field__control')?.getBoundingClientRect().width ?? 0,
            ]);
            expect(own, `${label} does not fill the place it was put in`).toBeCloseTo(room, 0);

            // Focused from the keyboard: the ring is the control's, not the
            // line's inside it.
            const genus = page.getByRole('combobox', { name: 'Genus', exact: true });
            await genus.focus();
            expect(await computed(control, 'outline-style')).toBe('solid');
            await expectFromToken(control, 'outline-color', '--color-focus', `the focused control in ${where}`);
            await expectFromToken(control, 'outline-width', '--border-marker', `the focused control in ${where}`);
            await expectFromToken(control, 'border-top-color', '--color-accent', `the focused control in ${where}`);
            expect(await computed(genus, 'outline-style')).toBe('none');

            await genus.press('ArrowDown');
            const dropdown = drawnFor(page, 'sg-combobox-long').locator('.c-combobox__dropdown');
            await expect(dropdown).toBeVisible();
            await expectFromToken(dropdown, 'background-color', '--color-surface', `the list in ${where}`);
            await expectFromToken(dropdown, 'border-top-color', '--color-line', `the list in ${where}`);
            const active = dropdown.locator('.c-combobox__option.active');
            await expectFromToken(active, 'background-color', '--color-surface-hover', `the active choice in ${where}`);
            await expectFromToken(
                dropdown.locator('.c-combobox__list'),
                'max-height',
                '--layout-combobox-list-size',
                `the list in ${where}`,
            );

            // Above what stays in view, in the layer the navigation's blocks
            // are drawn in.
            await expectFromToken(dropdown, 'z-index', '--layout-z-menu', `the list in ${where}`);
            await genus.press('Escape');

            const refused = drawnFor(page, 'sg-combobox-refused').locator('.c-combobox__control');
            await expectFromToken(refused, 'border-top-color', '--color-danger', `the refused control in ${where}`);

            const disabled = drawnFor(page, 'sg-combobox-disabled').locator('.c-combobox__control');
            await expectFromToken(disabled, 'background-color', '--color-canvas-sunken', `the disabled control in ${where}`);
            await expectFromToken(disabled, 'color', '--color-ink-muted', `the disabled control in ${where}`);

            expect(problems).toEqual([]);
        });
    }
}
