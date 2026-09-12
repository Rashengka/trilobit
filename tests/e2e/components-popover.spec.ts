import { expect, type Locator, type Page, test } from '@playwright/test';

/**
 * Dropdown, Popover and Tooltip in a real browser: what the keyboard does, what
 * the accessibility tree says, where the browser puts each of them against the
 * element that opened it, and what the stylesheet draws in either theme and
 * either mode.
 *
 * All three are drawn in the top layer by the Popover API. Closing a dropdown
 * or a popover by a click outside or by Escape, and giving the focus back, is the
 * browser's work; what is asked here is that it happens, and that the little
 * script beside it - the arrow keys of a menu, the hover and the focus of a
 * tooltip, the placing where a browser cannot anchor - does what it says.
 *
 * The markup of each is held in tests/Template; the specimens are the style
 * guide's, invented.
 */

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

/**
 * Whether the browser's own accessibility tree reads $locator as expanded.
 *
 * Asked of the browser rather than of Playwright's snapshot, because the state
 * is not in the markup: a button that opens a popover is expanded while the
 * popover is open, and that is computed by the browser from popovertarget, with
 * no attribute anybody writes.
 */
async function expandedInTheTree(page: Page, locator: Locator): Promise<boolean | undefined> {
    const session = await page.context().newCDPSession(page);
    const handle = await locator.elementHandle();
    if (handle === null) {
        throw new Error('nothing to ask about');
    }

    const marker = `expanded-${Math.random().toString(36).slice(2)}`;
    await handle.evaluate((element, name) => element.setAttribute('data-asked', name), marker);
    const { root } = await session.send('DOM.getDocument', { depth: 0 });
    const { nodeId } = await session.send('DOM.querySelector', { nodeId: root.nodeId, selector: `[data-asked="${marker}"]` });
    const { nodes } = await session.send('Accessibility.getPartialAXTree', { nodeId, fetchRelatives: false });
    await handle.evaluate((element) => element.removeAttribute('data-asked'));
    await session.detach();

    const property = nodes[0]?.properties?.find((candidate) => candidate.name === 'expanded');

    return property === undefined ? undefined : Boolean(property.value.value);
}

/** Where the browser put $floating against $anchor, and whether it stayed in the window. */
async function placement(anchor: Locator, floating: Locator) {
    const a = await anchor.boundingBox();
    const f = await floating.boundingBox();
    if (a === null || f === null) {
        throw new Error('the anchor or what it opened is not drawn');
    }

    const viewport = await anchor.page().evaluate(() => ({
        width: document.documentElement.clientWidth,
        height: document.documentElement.clientHeight,
    }));

    return {
        gapBelow: f.y - (a.y + a.height),
        gapAbove: a.y - (f.y + f.height),
        startDelta: Math.abs(f.x - a.x),
        endDelta: Math.abs(f.x + f.width - (a.x + a.width)),
        centreDelta: Math.abs(f.x + f.width / 2 - (a.x + a.width / 2)),
        inside: f.x >= 0 && f.y >= 0 && f.x + f.width <= viewport.width && f.y + f.height <= viewport.height,
    };
}

/**
 * Scrolls $locator to the middle of the window, or to its bottom edge.
 *
 * Where a popover ends up depends on the room around its element, and turning
 * it over when there is none is part of what is measured - so a case about the
 * side it hangs on first puts the element where there is room on both sides,
 * and the case about turning over puts it where there is none below.
 */
async function scrollTo(locator: Locator, where: 'center' | 'end'): Promise<void> {
    await locator.evaluate((element, block) => element.scrollIntoView({ block }), where);

    // A theme makes its held bands smaller once the page has scrolled, over a
    // moment, and the page moves under them while it does. A popover opened in
    // that moment is placed against where its element was, and the browser keeps
    // the side it chose for as long as it still fits - so the element is let
    // come to rest before anything is opened.
    await locator.evaluate(
        (element) =>
            new Promise<void>((resolve) => {
                let last = '';
                let same = 0;
                const tick = (): void => {
                    const now = String(element.getBoundingClientRect().top);
                    same = now === last ? same + 1 : 0;
                    last = now;
                    if (same >= 6) {
                        resolve();
                    } else {
                        requestAnimationFrame(tick);
                    }
                };
                requestAnimationFrame(tick);
            }),
    );
}

/** Whether what is drawn at the middle of $locator belongs to it - nothing is drawn over it. */
async function drawnOnTop(locator: Locator): Promise<boolean> {
    return locator.evaluate((element) => {
        const box = element.getBoundingClientRect();

        return element.contains(document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2));
    });
}

test.describe('c-dropdown', () => {
    test('is a button that opens a menu of its actions', async ({ page }) => {
        await page.goto('/_styleguide/components/dropdown');
        const dropdown = page.getByTestId('sample-dropdown');
        const button = dropdown.getByRole('button', { name: 'Actions' });

        await expect(button).toHaveAttribute('aria-haspopup', 'menu');
        expect(await expandedInTheTree(page, button)).toBe(false);

        await button.click();

        await expect(page.getByRole('menu', { name: 'Actions' })).toMatchAriaSnapshot(`
            - menu "Actions":
              - menuitem "Duplicate"
              - menuitem "Move to a drawer"
              - separator
              - menuitem "Withdraw"
        `);
        expect(await expandedInTheTree(page, button)).toBe(true);
    });

    test('is moved through with the arrow keys, Home and End, and by the first letter', async ({ page }) => {
        await page.goto('/_styleguide/components/dropdown');
        const dropdown = page.getByTestId('sample-dropdown');
        const button = dropdown.getByRole('button', { name: 'Actions' });
        const item = (name: string): Locator => page.getByRole('menuitem', { name });

        await button.focus();
        await page.keyboard.press('Enter');
        await expect(item('Duplicate')).toBeFocused();

        await page.keyboard.press('ArrowDown');
        await expect(item('Move to a drawer')).toBeFocused();

        await page.keyboard.press('End');
        await expect(item('Withdraw')).toBeFocused();

        // Round from the last to the first, and back.
        await page.keyboard.press('ArrowDown');
        await expect(item('Duplicate')).toBeFocused();
        await page.keyboard.press('ArrowUp');
        await expect(item('Withdraw')).toBeFocused();

        await page.keyboard.press('Home');
        await expect(item('Duplicate')).toBeFocused();

        await page.keyboard.press('m');
        await expect(item('Move to a drawer')).toBeFocused();

        // The menu is not a stop of Tab of its own: every entry is left out of it.
        for (const name of ['Duplicate', 'Move to a drawer', 'Withdraw']) {
            await expect(item(name)).toHaveAttribute('tabindex', '-1');
        }
    });

    test('opens from the arrow keys on its button, onto the first entry or the last', async ({ page }) => {
        await page.goto('/_styleguide/components/dropdown');
        const button = page.getByTestId('sample-dropdown').getByRole('button', { name: 'Actions' });

        await button.focus();
        await page.keyboard.press('ArrowDown');
        await expect(page.getByRole('menuitem', { name: 'Duplicate' })).toBeFocused();

        await page.keyboard.press('Escape');
        await expect(page.getByRole('menu', { name: 'Actions' })).toBeHidden();
        await expect(button).toBeFocused();

        await page.keyboard.press('ArrowUp');
        await expect(page.getByRole('menuitem', { name: 'Withdraw' })).toBeFocused();
    });

    test('closes on Escape, on an entry chosen and on Tab, and leaves the focus where it belongs', async ({ page }) => {
        await page.goto('/_styleguide/components/dropdown');
        const dropdown = page.getByTestId('sample-dropdown');
        const button = dropdown.getByRole('button', { name: 'Actions' });
        const menu = page.getByRole('menu', { name: 'Actions' });

        await button.focus();
        await page.keyboard.press('Enter');
        await expect(menu).toBeVisible();
        // The menu takes the focus when the browser reports it open, a moment
        // after the key; every key below waits for that first.
        await expect(page.getByRole('menuitem', { name: 'Duplicate' })).toBeFocused();
        await page.keyboard.press('Escape');
        await expect(menu).toBeHidden();
        await expect(button).toBeFocused();

        // An action chosen closes the menu, and the focus is back on the button.
        await page.keyboard.press('Enter');
        await expect(page.getByRole('menuitem', { name: 'Duplicate' })).toBeFocused();
        await page.keyboard.press('ArrowDown');
        await page.keyboard.press('Enter');
        await expect(menu).toBeHidden();
        await expect(button).toBeFocused();

        // Tab leaves the menu for whatever comes after the dropdown.
        await page.keyboard.press('Enter');
        await expect(page.getByRole('menuitem', { name: 'Duplicate' })).toBeFocused();
        await page.keyboard.press('Tab');
        await expect(menu).toBeHidden();
        const after = await dropdown.evaluate(
            (element) =>
                document.activeElement !== null &&
                document.activeElement !== document.body &&
                !element.contains(document.activeElement) &&
                (element.compareDocumentPosition(document.activeElement) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0,
        );
        expect(after, 'Tab did not take the focus on past the dropdown').toBe(true);

        // Shift+Tab leaves it for the button that opened it.
        await button.focus();
        await page.keyboard.press('Enter');
        await expect(page.getByRole('menuitem', { name: 'Duplicate' })).toBeFocused();
        await page.keyboard.press('Shift+Tab');
        await expect(menu).toBeHidden();
        await expect(button).toBeFocused();
    });

    test('closes on a click outside it', async ({ page }) => {
        await page.goto('/_styleguide/components/dropdown');
        const menu = page.getByRole('menu', { name: 'Actions' });

        await page.getByTestId('sample-dropdown').getByRole('button', { name: 'Actions' }).click();
        await expect(menu).toBeVisible();
        await page.mouse.click(2, 2);
        await expect(menu).toBeHidden();
    });

    test('drops its menu under the button, lined up with its start or with its end', async ({ page }) => {
        await page.goto('/_styleguide/components/dropdown');

        const start = page.getByTestId('sample-dropdown');
        await scrollTo(start, 'center');
        await start.getByRole('button').click();
        const fromStart = await placement(start.getByRole('button'), page.getByRole('menu', { name: 'Actions' }));
        expect(fromStart.gapBelow).toBeGreaterThanOrEqual(0);
        expect(fromStart.gapBelow).toBeLessThan(24);
        expect(fromStart.startDelta).toBeLessThanOrEqual(1);
        expect(fromStart.inside).toBe(true);
        expect(await drawnOnTop(page.getByRole('menu', { name: 'Actions' }))).toBe(true);
        await page.keyboard.press('Escape');

        const end = page.getByTestId('sample-dropdown-end');
        await scrollTo(end, 'center');
        await end.getByRole('button').click();
        const menu = page.getByRole('menu', { name: 'Sort by' });
        const fromEnd = await placement(end.getByRole('button'), menu);
        expect(fromEnd.gapBelow).toBeGreaterThanOrEqual(0);
        expect(fromEnd.endDelta).toBeLessThanOrEqual(1);
        expect(fromEnd.inside).toBe(true);
    });

    test('turns its menu over the button when there is no room under it', async ({ page }) => {
        await page.goto('/_styleguide/components/dropdown');
        const start = page.getByTestId('sample-dropdown');

        await scrollTo(start, 'end');
        await start.getByRole('button').click();
        const placed = await placement(start.getByRole('button'), page.getByRole('menu', { name: 'Actions' }));

        expect(placed.gapAbove).toBeGreaterThanOrEqual(0);
        expect(placed.gapAbove).toBeLessThan(24);
        expect(placed.inside).toBe(true);
    });

    test('offers links as entries that go somewhere', async ({ page }) => {
        await page.goto('/_styleguide/components/dropdown');
        await page.getByTestId('sample-dropdown-links').getByRole('button').click();

        await expect(page.getByRole('menuitem', { name: 'Catalogue' })).toHaveAttribute('href', '#catalogue');
    });

    /**
     * A dropdown Naja draws into the page later is markup nobody set up, which
     * is the case a script that prepared the dropdowns once would miss.
     */
    test('works when it arrived after the page did', async ({ page }) => {
        await page.goto('/_styleguide/components/dropdown');

        await page.getByTestId('sample-dropdown').evaluate((element) => {
            const holder = document.createElement('div');
            holder.innerHTML = element.outerHTML
                .replaceAll('sg-dropdown-actions', 'redrawn-dropdown')
                .replaceAll('sample-dropdown', 'redrawn-sample');
            element.parentElement?.append(holder);
        });

        const button = page.getByTestId('redrawn-sample').getByRole('button');
        await button.focus();
        await page.keyboard.press('Enter');
        // The menu takes the focus when the browser reports it open, a moment
        // after the key; an arrow pressed before that is the button's.
        await expect(page.getByTestId('redrawn-sample').getByRole('menuitem', { name: 'Duplicate' })).toBeFocused();
        await page.keyboard.press('ArrowDown');
        await expect(page.getByTestId('redrawn-sample').getByRole('menuitem', { name: 'Move to a drawer' })).toBeFocused();
    });

    /**
     * A browser that cannot anchor - one from before Chrome 125, Safari 26 or
     * Firefox 147 - is played here by a Chrome told that it cannot, and by a
     * stylesheet that takes the anchored placing away. What is left is the
     * little script, and it has to put the menu where the anchoring would have.
     */
    test('is put under its button by the script where the browser cannot anchor', async ({ page }) => {
        await page.addInitScript(() => {
            const supports = CSS.supports.bind(CSS);
            CSS.supports = ((...args: [string, string?]) =>
                args.join(' ').includes('position-area') ? false : supports(...(args as [string, string]))) as typeof CSS.supports;
        });
        await page.goto('/_styleguide/components/dropdown');
        await page.addStyleTag({ content: '[popover] { position-area: none !important; }' });

        const end = page.getByTestId('sample-dropdown-end');
        await scrollTo(end, 'center');
        await end.getByRole('button').click();
        const placed = await placement(end.getByRole('button'), page.getByRole('menu', { name: 'Sort by' }));

        expect(placed.gapBelow).toBeGreaterThanOrEqual(0);
        expect(placed.gapBelow).toBeLessThan(24);
        expect(placed.endDelta).toBeLessThanOrEqual(1);
        expect(placed.inside).toBe(true);
    });
});

test.describe('c-popover', () => {
    test('opens a dialog named by its title from the button, and closes on Escape', async ({ page }) => {
        await page.goto('/_styleguide/components/popover');
        const button = page.getByTestId('sample-popover').getByRole('button', { name: 'What is a pygidium?' });

        await expect(page.getByRole('dialog')).toHaveCount(0);
        expect(await expandedInTheTree(page, button)).toBe(false);

        await button.focus();
        await page.keyboard.press('Enter');

        await expect(page.getByRole('dialog', { name: 'Pygidium' })).toMatchAriaSnapshot(`
            - dialog "Pygidium":
              - paragraph: Pygidium
              - paragraph: The tail shield of a trilobite, made of segments fused together.
        `);
        expect(await expandedInTheTree(page, button)).toBe(true);

        await page.keyboard.press('Escape');
        await expect(page.getByRole('dialog', { name: 'Pygidium' })).toBeHidden();
        await expect(button).toBeFocused();
    });

    test('closes on a click outside it', async ({ page }) => {
        await page.goto('/_styleguide/components/popover');
        await page.getByTestId('sample-popover').getByRole('button').click();

        await expect(page.getByRole('dialog', { name: 'Pygidium' })).toBeVisible();
        await page.mouse.click(2, 2);
        await expect(page.getByRole('dialog', { name: 'Pygidium' })).toBeHidden();
    });

    test('hangs under its button, or over it when asked to, over everything else', async ({ page }) => {
        await page.goto('/_styleguide/components/popover');

        const below = page.getByTestId('sample-popover');
        await scrollTo(below, 'center');
        await below.getByRole('button').click();
        const dialog = page.getByRole('dialog', { name: 'Pygidium' });
        const underneath = await placement(below.getByRole('button'), dialog);
        expect(underneath.gapBelow).toBeGreaterThanOrEqual(0);
        expect(underneath.gapBelow).toBeLessThan(24);
        expect(underneath.centreDelta).toBeLessThanOrEqual(1);
        expect(underneath.inside).toBe(true);
        expect(await drawnOnTop(dialog)).toBe(true);
        await page.keyboard.press('Escape');

        const above = page.getByTestId('sample-popover-above');
        await scrollTo(above, 'center');
        await above.getByRole('button').click();
        const over = await placement(above.getByRole('button'), page.getByRole('dialog', { name: 'Cephalon' }));
        expect(over.gapAbove).toBeGreaterThanOrEqual(0);
        expect(over.gapAbove).toBeLessThan(24);
        expect(over.inside).toBe(true);
    });
});

test.describe('c-tooltip', () => {
    test('describes its element, and is not what names it', async ({ page }) => {
        await page.goto('/_styleguide/components/tooltip');

        const button = page.getByTestId('sample-tooltip').getByRole('button', { name: 'Duplicate' });
        await expect(button).toHaveAccessibleDescription('Makes a second record with the same fields.');

        const icon = page.getByTestId('sample-tooltip-icon').getByRole('button', { name: 'Remove the photograph' });
        await expect(icon).toHaveAccessibleName('Remove the photograph');
        await expect(icon).toHaveAccessibleDescription('The record keeps its other photographs.');
    });

    test('appears on focus, stays while the focus does, and goes with it', async ({ page }) => {
        await page.goto('/_styleguide/components/tooltip');
        const holder = page.getByTestId('sample-tooltip');
        const tip = holder.getByRole('tooltip');

        await expect(tip).toBeHidden();
        await holder.getByRole('button').focus();
        await expect(tip).toBeVisible();
        await expect(tip).toHaveText('Makes a second record with the same fields.');

        await page.keyboard.press('Tab');
        await expect(tip).toBeHidden();
    });

    test('is put away by Escape without the focus moving, and not brought back until it is asked for again', async ({
        page,
    }) => {
        await page.goto('/_styleguide/components/tooltip');
        const holder = page.getByTestId('sample-tooltip');
        const button = holder.getByRole('button');
        const tip = holder.getByRole('tooltip');

        await button.focus();
        await expect(tip).toBeVisible();

        await page.keyboard.press('Escape');
        await expect(tip).toBeHidden();
        await expect(button).toBeFocused();

        // The pointer passing over it now is not a request to see it again...
        const box = await button.boundingBox();
        if (box === null) {
            throw new Error('the button is not drawn');
        }
        await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
        await expect(tip).toBeHidden();

        // ...coming back to it is.
        await page.keyboard.press('Tab');
        await page.keyboard.press('Shift+Tab');
        await expect(tip).toBeVisible();
    });

    test('appears under the pointer, and stays while the pointer moves onto it', async ({ page }) => {
        await page.goto('/_styleguide/components/tooltip');
        const holder = page.getByTestId('sample-tooltip');
        const button = holder.getByRole('button');
        const tip = holder.getByRole('tooltip');

        await button.hover();
        await expect(tip).toBeVisible();

        // Across the gap and onto the tooltip itself, in steps, the way a hand does.
        const from = await button.boundingBox();
        const to = await tip.boundingBox();
        if (from === null || to === null) {
            throw new Error('the button or the tooltip is not drawn');
        }
        await page.mouse.move(to.x + to.width / 2, to.y + to.height / 2, { steps: 8 });
        await page.waitForTimeout(400);
        await expect(tip, 'the tooltip went away while the pointer was on it').toBeVisible();

        await page.mouse.move(2, 2);
        await expect(tip).toBeHidden();
    });

    test('hangs over its element, or under it when asked to', async ({ page }) => {
        await page.goto('/_styleguide/components/tooltip');

        const above = page.getByTestId('sample-tooltip');
        await above.getByRole('button').focus();
        const over = await placement(above.getByRole('button'), above.getByRole('tooltip'));
        expect(over.gapAbove).toBeGreaterThanOrEqual(0);
        expect(over.gapAbove).toBeLessThan(24);
        expect(over.centreDelta).toBeLessThanOrEqual(1);
        expect(await drawnOnTop(above.getByRole('tooltip'))).toBe(true);

        const below = page.getByTestId('sample-tooltip-below');
        await below.getByRole('link').focus();
        const under = await placement(below.getByRole('link'), below.getByRole('tooltip'));
        expect(under.gapBelow).toBeGreaterThanOrEqual(0);
        expect(under.gapBelow).toBeLessThan(24);
    });
});

test.describe('in either theme and either mode', () => {
    for (const theme of themes) {
        test(`the tooltip is drawn in an ink of its own and changes with the mode, in ${theme}`, async ({ page }) => {
            const drawn: Record<string, string[]> = {};

            for (const mode of modes) {
                await page.goto('/_styleguide/components/tooltip');
                await drawIn(page, theme, mode);
                await page.getByTestId('sample-tooltip').getByRole('button').focus();
                const tip = page.getByTestId('sample-tooltip').getByRole('tooltip');
                await expect(tip).toBeVisible();

                drawn[mode] = [
                    await tip.evaluate((element) => getComputedStyle(element).backgroundColor),
                    await tip.evaluate((element) => getComputedStyle(element).color),
                ];
            }

            expect(drawn.light).not.toEqual(drawn.dark);
            for (const [background, ink] of Object.values(drawn)) {
                expect(background).not.toBe('rgba(0, 0, 0, 0)');
                expect(background).not.toBe(ink);
            }
        });

        test(`the menu and the dialog are drawn and differ between the modes, in ${theme}`, async ({ page }) => {
            const read = async (mode: string) => {
                await page.goto('/_styleguide/components/dropdown');
                await drawIn(page, theme, mode);
                await page.getByTestId('sample-dropdown').getByRole('button').click();
                const menu = await page
                    .getByRole('menu', { name: 'Actions' })
                    .evaluate((element) => getComputedStyle(element).backgroundColor);

                await page.goto('/_styleguide/components/popover');
                await drawIn(page, theme, mode);
                await page.getByTestId('sample-popover').getByRole('button').click();
                const dialog = await page
                    .getByRole('dialog', { name: 'Pygidium' })
                    .evaluate((element) => getComputedStyle(element).backgroundColor);

                return { menu, dialog };
            };

            const light = await read('light');
            const dark = await read('dark');

            for (const value of [light.menu, light.dialog, dark.menu, dark.dialog]) {
                expect(value).not.toBe('rgba(0, 0, 0, 0)');
            }
            expect(light.menu).not.toBe(dark.menu);
            expect(light.dialog).not.toBe(dark.dialog);
        });
    }
});
