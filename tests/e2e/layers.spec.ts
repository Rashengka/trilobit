import { expect, type Locator, type Page, test } from '@playwright/test';

/**
 * c-modal and c-offcanvas in a real browser.
 *
 * Both are a native dialog shown as a modal, so most of what they do is the
 * browser's: the focus taken in and given back, the page behind made inert,
 * Escape. Those are exactly the claims that can only be made here - the markup
 * the browser needs for them is held in tests/Template - and they are asked of
 * the page as somebody uses it: by the keyboard, by the accessibility tree,
 * and by where the focus is afterwards.
 *
 * What is this application's own is measured too: the buttons answered in a
 * browser that has no Invoker Commands (assets/dialog.ts), a dialog Naja
 * redraws while it is open, the page under it held still by the stylesheet
 * alone, the edges an offcanvas is drawn against in both directions of
 * writing, and the tokens both are drawn out of in either theme and mode.
 *
 * The specimens are the style guide's, invented.
 */

const modalPage = ['/_styleguide', 'components', 'modal'].join('/');

const offcanvasPage = ['/_styleguide', 'components', 'offcanvas'].join('/');

const themes = ['atrium', 'ledger'] as const;

const modes = ['light', 'dark'] as const;

/**
 * Playwright starts a headless Chrome with --hide-scrollbars, and under it no
 * scrollbar takes room whatever the page asks for - so the cases below about
 * a scrollbar that takes room (see withScrollbar) could not have one. Left
 * out for this file only: the other suites are measured the way they always
 * were.
 */
test.use({ launchOptions: { ignoreDefaultArgs: ['--hide-scrollbars'] } });

async function drawIn(page: Page, theme: string, mode: string): Promise<void> {
    await page.evaluate(
        ([chosenTheme, chosenMode]) => {
            document.documentElement.setAttribute('data-theme', chosenTheme);
            document.documentElement.setAttribute('data-theme-mode', chosenMode);
        },
        [theme, mode],
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

/** Whether $dialog is open as a modal, which is what makes the page behind it inert. */
async function isModal(dialog: Locator): Promise<boolean> {
    return dialog.evaluate((element) => element.matches(':modal'));
}

/** Where the focus is, said as the test id of what holds it, so a failure names the place. */
async function focusedTestId(page: Page): Promise<string | null> {
    return page.evaluate(() => document.activeElement?.getAttribute('data-testid') ?? document.activeElement?.tagName ?? null);
}

/** Whether the focus is inside $dialog. */
async function holdsTheFocus(dialog: Locator): Promise<boolean> {
    return dialog.evaluate((element) => element.contains(document.activeElement));
}

async function rootOverflow(page: Page): Promise<string> {
    return page.evaluate(() => getComputedStyle(document.documentElement).overflowY);
}

/** What $name resolves to for $property, on a probe inside $element so the probe inherits what it would. */
async function token(element: Locator, property: string, name: string): Promise<string> {
    return element.evaluate(
        (node, [prop, tokenName]) => {
            const probe = document.createElement('span');
            probe.style.setProperty(prop, `var(${tokenName})`);
            node.append(probe);
            const value = getComputedStyle(probe).getPropertyValue(prop);
            probe.remove();

            return value;
        },
        [property, name],
    );
}

/**
 * A browser without Invoker Commands, made out of this one: the properties a
 * page detects them by are taken away, and the command a button sends is
 * cancelled before the browser acts on it. What still opens and closes the
 * dialog is then only the document's own listener.
 */
async function withoutInvokerCommands(page: Page): Promise<void> {
    await page.addInitScript(() => {
        const prototype = HTMLButtonElement.prototype as unknown as Record<string, unknown>;
        delete prototype.commandForElement;
        delete prototype.command;
        document.addEventListener('command', (event) => event.preventDefault(), true);
    });
}

/**
 * The two kinds of scrollbar a page is looked at with. Chrome on a Mac draws
 * one over the page that takes no room; on Windows and Linux - and so in CI -
 * it takes room at the edge of the window, and so does anything left keeping
 * that room once the scrollbar has gone. That difference passed a laptop and
 * failed CI, so the second kind is forced here rather than left to whichever
 * machine runs the suite: a style for ::-webkit-scrollbar makes Chrome draw a
 * scrollbar that takes room on any system, once it has not been told to hide
 * every scrollbar (the test.use at the top of this file).
 */
const scrollbars = ['as the browser draws it', 'taking room'] as const;

type Scrollbar = (typeof scrollbars)[number];

async function withScrollbar(page: Page, scrollbar: Scrollbar): Promise<void> {
    if (scrollbar !== 'taking room') {
        return;
    }

    await page.addInitScript(() => {
        document.addEventListener('DOMContentLoaded', () => {
            const style = document.createElement('style');
            style.textContent = '::-webkit-scrollbar { width: 15px; height: 15px; } ::-webkit-scrollbar-thumb { background: grey; }';
            document.head.append(style);
        });
    });
}

/**
 * How much of the width of the window the page's scrollbar takes. Asked
 * before anything is opened, so that a case about a scrollbar taking room is
 * known to have had one - a style that stopped working would otherwise pass
 * it as the kind that takes none.
 */
async function expectTheScrollbar(page: Page, scrollbar: Scrollbar): Promise<void> {
    if (scrollbar === 'taking room') {
        expect(await page.evaluate(() => window.innerWidth - document.documentElement.clientWidth), 'the scrollbar takes no room').toBe(15);
    }
}

test.describe('c-modal', () => {
    test('opens from the keyboard as a dialog named by its heading, and takes the focus in', async ({ page }) => {
        const problems = problemsOn(page);
        await page.goto(modalPage);

        const opener = page.getByTestId('sg-modal-open');
        const dialog = page.getByRole('dialog', { name: 'Lend this specimen' });
        await expect(dialog).toBeHidden();

        await opener.focus();
        await page.keyboard.press('Enter');

        await expect(dialog).toBeVisible();
        expect(await isModal(dialog)).toBe(true);
        expect(await holdsTheFocus(dialog), `the focus is on ${await focusedTestId(page)}`).toBe(true);
        await expect(dialog).toMatchAriaSnapshot(`
            - dialog "Lend this specimen":
              - heading "Lend this specimen" [level=2]
              - button "Close"
              - paragraph
              - button "Not now"
              - button "Lend it"
        `);

        expect(problems).toEqual([]);
    });

    /**
     * The browser centres a modal with automatic margins, and the reset takes
     * every margin away - that one too. Without its own the dialog was drawn
     * in the top corner of the window, and a click meant for the page beside
     * it landed on the dialog.
     */
    for (const scrollbar of scrollbars) {
        test(`is drawn in the middle of the window, with a scrollbar ${scrollbar}`, async ({ page }) => {
            await withScrollbar(page, scrollbar);
            await page.emulateMedia({ reducedMotion: 'reduce' });
            await page.goto(modalPage);
            await expectTheScrollbar(page, scrollbar);
            const dialog = page.getByTestId('sg-modal');

            await page.getByTestId('sg-modal-open').click();
            await expect(dialog).toBeVisible();

            const off = await dialog.evaluate((element) => {
                const rect = element.getBoundingClientRect();

                return {
                    inline: Math.abs(rect.left + rect.width / 2 - window.innerWidth / 2),
                    block: Math.abs(rect.top + rect.height / 2 - window.innerHeight / 2),
                };
            });

            expect(off.inline, 'it is not in the middle across the window').toBeLessThanOrEqual(1);
            expect(off.block, 'it is not in the middle down the window').toBeLessThanOrEqual(1);
        });

        /**
         * The whole window is dimmed, the place the scrollbar had included:
         * a gutter kept for it once it has gone is room nothing fixed is
         * given, and it stayed a strip of the page drawn undimmed beside the
         * backdrop. What is under a point on the backdrop is the dialog.
         */
        test(`lays its backdrop over the whole window, with a scrollbar ${scrollbar}`, async ({ page }) => {
            await withScrollbar(page, scrollbar);
            await page.emulateMedia({ reducedMotion: 'reduce' });
            await page.goto(modalPage);
            await expectTheScrollbar(page, scrollbar);
            const dialog = page.getByTestId('sg-modal');

            await page.getByTestId('sg-modal-open').click();
            await expect(dialog).toBeVisible();

            const under = await page.evaluate(() =>
                [
                    [1, 1],
                    [window.innerWidth - 2, window.innerHeight / 2],
                    [window.innerWidth - 2, window.innerHeight - 2],
                ].map(([x, y]) => {
                    const found = document.elementFromPoint(x ?? 0, y ?? 0);

                    return found?.getAttribute('data-testid') ?? found?.tagName ?? 'nothing';
                }),
            );

            expect(under, 'a corner or the right edge of the window is not under the backdrop').toEqual([
                'sg-modal',
                'sg-modal',
                'sg-modal',
            ]);
        });
    }

    test('Escape closes it and gives the focus back to the button that opened it', async ({ page }) => {
        await page.goto(modalPage);
        const opener = page.getByTestId('sg-modal-open');
        const dialog = page.getByTestId('sg-modal');

        await opener.focus();
        await page.keyboard.press('Enter');
        await expect(dialog).toBeVisible();

        await page.keyboard.press('Escape');

        await expect(dialog).toBeHidden();
        await expect(opener).toBeFocused();
    });

    test('the cross closes it and gives the focus back to the button that opened it', async ({ page }) => {
        await page.goto(modalPage);
        const opener = page.getByTestId('sg-modal-open');
        const dialog = page.getByTestId('sg-modal');

        await opener.click();
        await dialog.getByRole('button', { name: 'Close' }).click();

        await expect(dialog).toBeHidden();
        await expect(opener).toBeFocused();
    });

    /**
     * The page behind a modal is inert, which is the browser's and is what
     * keeps the keyboard in: Tab goes round the dialog and out to the
     * browser's own controls, and never to anything on the page behind it.
     */
    test('Tab goes round the dialog and never reaches the page behind it', async ({ page }) => {
        await page.goto(modalPage);
        const dialog = page.getByTestId('sg-modal');

        await page.getByTestId('sg-modal-open').focus();
        await page.keyboard.press('Enter');
        await expect(dialog).toBeVisible();

        const visited = new Set<string>();
        for (let step = 0; step < 10; step++) {
            await page.keyboard.press('Tab');
            const where = await dialog.evaluate((element) => {
                const focused = document.activeElement;
                if (focused === null || focused === document.body) {
                    return 'nowhere on the page';
                }

                return element.contains(focused) ? focused.textContent?.trim() || focused.getAttribute('aria-label') || focused.tagName : `outside: ${focused.outerHTML.slice(0, 80)}`;
            });

            expect(where, `step ${step} took the focus behind the dialog`).not.toMatch(/^outside/);
            visited.add(where);
        }

        expect([...visited]).toEqual(expect.arrayContaining(['Close', 'Not now', 'Lend it']));
    });

    test('a click beside it leaves it open', async ({ page }) => {
        await page.goto(modalPage);
        const dialog = page.getByTestId('sg-modal');

        await page.getByTestId('sg-modal-open').click();
        await expect(dialog).toBeVisible();
        await page.mouse.click(4, 4);

        await expect(dialog).toBeVisible();
    });

    /**
     * Held by the stylesheet alone: nothing counts the dialogs that are open,
     * so there is no count to be left wrong by a dialog Naja takes away open.
     */
    test('the page under it does not scroll while it is open, and does again once it is closed', async ({ page }) => {
        // Atrium makes its banner smaller once the content has scrolled under
        // it, and the browser keeps the reader's place by moving the page by
        // the difference. That is the page's own layout and not somebody
        // scrolling, so it is let finish - at once, with no motion - before
        // anything is measured.
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await page.setViewportSize({ width: 1024, height: 500 });
        await page.goto(modalPage);
        const dialog = page.getByTestId('sg-modal');
        const opener = page.getByTestId('sg-modal-open');
        expect(await rootOverflow(page)).not.toBe('hidden');

        await opener.scrollIntoViewIfNeeded();
        await page.evaluate(
            () =>
                new Promise<void>((resolve) => {
                    let last = -1;
                    let still = 0;
                    const frame = (): void => {
                        still = window.scrollY === last ? still + 1 : 0;
                        last = window.scrollY;
                        if (still >= 5) {
                            resolve();
                        } else {
                            requestAnimationFrame(frame);
                        }
                    };
                    requestAnimationFrame(frame);
                }),
        );

        await opener.focus();
        await page.keyboard.press('Enter');
        await expect(dialog).toBeVisible();
        expect(await rootOverflow(page)).toBe('hidden');

        const before = await page.evaluate(() => window.scrollY);
        await page.mouse.move(8, 400);
        await page.mouse.wheel(0, 600);
        // Nothing to wait for: a page that would scroll has scrolled by the next frame.
        await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));
        expect(await page.evaluate(() => window.scrollY)).toBe(before);

        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
        expect(await rootOverflow(page)).not.toBe('hidden');
    });

    /**
     * Nothing keeps a gutter for the scrollbar the lock takes away. Where the
     * scrollbar takes room, a gutter is room the window lends nothing fixed:
     * in Chrome on Linux the backdrop, the modal and the panels were drawn
     * short of it, which the cases with a scrollbar taking room measure there.
     * A scrollbar Chrome is made to draw by a style keeps no gutter even when
     * asked to, so a machine drawing scrollbars over the page can only ask
     * about the declaration - and this does, on every machine.
     */
    test('keeps no gutter for the scrollbar it takes away', async ({ page }) => {
        await page.goto(modalPage);
        const dialog = page.getByTestId('sg-modal');

        await page.getByTestId('sg-modal-open').click();
        await expect(dialog).toBeVisible();
        expect(await rootOverflow(page)).toBe('hidden');

        expect(await page.evaluate(() => getComputedStyle(document.documentElement).scrollbarGutter)).toBe('auto');
    });

    test('as a form, the button pressed closes it and is left as its answer', async ({ page }) => {
        await page.goto(modalPage);
        const opener = page.getByTestId('sg-modal-form-open');
        const dialog = page.getByRole('dialog', { name: 'Who is borrowing it?' });
        // A closed dialog is out of the accessibility tree, so its answer is read by its test id.
        const answer = (): Promise<string> =>
            page.getByTestId('sg-modal-form').evaluate((element) => (element as HTMLDialogElement).returnValue);

        await opener.click();
        await expect(dialog).toBeVisible();
        await dialog.getByRole('textbox', { name: 'Borrower' }).fill('Ada Example');
        await dialog.getByRole('button', { name: 'Lend it' }).click();

        await expect(dialog).toBeHidden();
        expect(await answer()).toBe('lend');
        await expect(opener).toBeFocused();

        // Enter in the field sends the one submit button, which is Lend it.
        await opener.click();
        await dialog.getByRole('textbox', { name: 'Borrower' }).press('Enter');
        await expect(dialog).toBeHidden();
        expect(await answer()).toBe('lend');

        await opener.click();
        await dialog.getByRole('button', { name: 'Not now' }).click();
        await expect(dialog).toBeHidden();
        expect(await answer()).toBe('cancel');
    });

    test('a body longer than the window scrolls inside it, with its heading and its buttons in view', async ({ page }) => {
        await page.setViewportSize({ width: 1024, height: 480 });
        await page.goto(modalPage);
        const dialog = page.getByRole('dialog', { name: 'Conditions of loan' });

        await page.getByTestId('sg-modal-long-open').click();
        await expect(dialog).toBeVisible();

        const body = dialog.locator('.c-modal__body');
        expect(await body.evaluate((element) => element.scrollHeight > element.clientHeight)).toBe(true);
        await expect(dialog.getByRole('heading', { name: 'Conditions of loan' })).toBeInViewport({ ratio: 1 });
        await expect(dialog.getByRole('button', { name: 'Understood' })).toBeInViewport({ ratio: 1 });
    });

    test('a browser without Invoker Commands opens and closes it through the document', async ({ page }) => {
        await withoutInvokerCommands(page);
        const problems = problemsOn(page);
        await page.goto(modalPage);
        expect(await page.evaluate(() => 'commandForElement' in HTMLButtonElement.prototype)).toBe(false);

        const opener = page.getByTestId('sg-modal-open');
        const dialog = page.getByTestId('sg-modal');

        await opener.focus();
        await page.keyboard.press('Enter');
        await expect(dialog).toBeVisible();
        expect(await isModal(dialog)).toBe(true);
        expect(await holdsTheFocus(dialog)).toBe(true);

        await dialog.getByRole('button', { name: 'Close' }).press('Enter');
        await expect(dialog).toBeHidden();
        await expect(opener).toBeFocused();

        // A mouse does not put the focus on a button in every browser, and the
        // focus is given back to whatever had it when the dialog was opened.
        await page.locator('body').click({ position: { x: 1, y: 1 } });
        await opener.click();
        await expect(dialog).toBeVisible();
        await dialog.getByRole('button', { name: 'Not now' }).click();
        await expect(dialog).toBeHidden();
        await expect(opener).toBeFocused();

        expect(problems).toEqual([]);
    });

    /**
     * Naja replaces the dialog with the server's markup while it is open:
     * the dialog that comes back is closed, because the server never draws
     * one open, and the one that was open is gone from the page with the
     * focus in it. The decision (assets/dialog.ts) is that what somebody had
     * open stays open - a dialog can only be redrawn by something inside it,
     * since the page behind it is inert - with the focus where it was and
     * still to be given back to the button that opened it.
     *
     * The way back is the other half. Naja keeps the server's markup, so
     * history.back() brings back the dialog closed - and the page returns to
     * how it was, so it stays closed, and the focus goes to its button rather
     * than to the top of the page.
     */
    test('an open dialog stays open when Naja redraws it, and closes when history.back() brings the old one', async ({ page }) => {
        const problems = problemsOn(page);
        await page.goto(modalPage);

        const opener = page.getByTestId('sg-modal-redrawn-open');
        const dialog = page.getByTestId('sg-modal-redrawn');

        await opener.focus();
        await page.keyboard.press('Enter');
        await expect(dialog).toBeVisible();
        await dialog.evaluate((element) => element.setAttribute('data-drawn-first', ''));

        const redraw = page.getByTestId('sg-modal-redraw');
        await redraw.focus();
        await page.keyboard.press('Enter');
        await expect(page).toHaveURL(/do=redrawSpecimen/);
        await expect(dialog).not.toHaveAttribute('data-drawn-first');

        await expect(dialog).toBeVisible();
        expect(await isModal(dialog), 'the redrawn dialog is open but not as a modal').toBe(true);
        await expect(redraw).toBeFocused();
        expect(await rootOverflow(page)).toBe('hidden');

        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
        await expect(opener).toBeFocused();

        await opener.press('Enter');
        await expect(dialog).toBeVisible();
        await page.evaluate(() => history.back());
        await expect(page).not.toHaveURL(/do=redrawSpecimen/);

        await expect(dialog).toBeHidden();
        await expect(page.locator('dialog[open]')).toHaveCount(0);
        await expect(opener).toBeFocused();
        expect(await rootOverflow(page)).not.toBe('hidden');

        await opener.press('Enter');
        await expect(dialog).toBeVisible();
        expect(await isModal(dialog)).toBe(true);

        expect(problems).toEqual([]);
    });
});

test.describe('c-offcanvas', () => {
    const edges = [
        { edge: 'start', title: 'Filters' },
        { edge: 'end', title: 'Basket' },
        { edge: 'top', title: 'Notice of closure' },
        { edge: 'bottom', title: 'Recently viewed' },
    ] as const;

    /** The side of the window each edge is drawn against, left to right and right to left. */
    const sides: Record<(typeof edges)[number]['edge'], { ltr: string; rtl: string }> = {
        start: { ltr: 'left', rtl: 'right' },
        end: { ltr: 'right', rtl: 'left' },
        top: { ltr: 'top', rtl: 'top' },
        bottom: { ltr: 'bottom', rtl: 'bottom' },
    };

    for (const { edge, title } of edges) {
        for (const direction of ['ltr', 'rtl'] as const) {
            for (const scrollbar of scrollbars) {
                const name = `at the ${edge} is drawn against the ${sides[edge][direction]} of the window, ${direction}, with a scrollbar ${scrollbar}`;

                test(name, async ({ page }) => {
                    await withScrollbar(page, scrollbar);
                    // Measured where it comes to rest, not somewhere along the way in.
                    await page.emulateMedia({ reducedMotion: 'reduce' });
                    await page.goto(offcanvasPage);
                    await expectTheScrollbar(page, scrollbar);
                    await page.evaluate((dir) => document.documentElement.setAttribute('dir', dir), direction);

                    const dialog = page.getByRole('dialog', { name: title });
                    await page.getByTestId(`sg-offcanvas-${edge}-open`).click();
                    await expect(dialog).toBeVisible();
                    expect(await isModal(dialog)).toBe(true);

                    // Against the window somebody sees, not against whatever
                    // is left of it once room has been kept for a scrollbar.
                    const box = await dialog.evaluate((element) => {
                        const rect = element.getBoundingClientRect();
                        const width = window.innerWidth;
                        const height = window.innerHeight;

                        return {
                            left: Math.round(rect.left),
                            right: Math.round(width - rect.right),
                            top: Math.round(rect.top),
                            bottom: Math.round(height - rect.bottom),
                            wide: Math.round(rect.width) >= width,
                            tall: Math.round(rect.height) >= height,
                        };
                    });

                    const side = sides[edge][direction] as 'left' | 'right' | 'top' | 'bottom';
                    expect(box[side], `it is not against the ${side}`).toBe(0);
                    const opposite = { left: 'right', right: 'left', top: 'bottom', bottom: 'top' }[side] as keyof typeof box;
                    expect(box[opposite], `it reaches the ${opposite} as well`).toBeGreaterThan(0);
                    if (side === 'left' || side === 'right') {
                        expect(box.tall, 'a panel at the side is not as tall as the window').toBe(true);
                    } else {
                        expect(box.wide, 'a panel at the top or bottom is not as wide as the window').toBe(true);
                    }
                });
            }
        }
    }

    /**
     * Where the start and the end slide in from, read off the closed panel,
     * which is where the way in starts. The direction is the page's dir
     * attribute, not its language: the build turns :dir(rtl) into a list of
     * languages, and a page marked right to left in a language not on that
     * list slid its panel in from the wrong side while it came to rest on the
     * right one.
     */
    test('the start and the end slide in from the side they are drawn against, in either direction', async ({ page }) => {
        await page.goto(offcanvasPage);
        const from = (edge: string): Promise<string> =>
            page.getByTestId(`sg-offcanvas-${edge}`).evaluate((element) => getComputedStyle(element).translate);

        expect(await from('start')).toBe('-100%');
        expect(await from('end')).toBe('100%');

        await page.evaluate(() => document.documentElement.setAttribute('dir', 'rtl'));
        expect(await page.evaluate(() => document.documentElement.lang)).toBe('en');

        expect(await from('start'), 'the start slides in from the left, right to left').toBe('100%');
        expect(await from('end'), 'the end slides in from the right, right to left').toBe('-100%');
    });

    test('opens as a dialog named by its heading, closes on Escape and gives the focus back', async ({ page }) => {
        await page.goto(offcanvasPage);
        const opener = page.getByTestId('sg-offcanvas-end-open');
        const dialog = page.getByRole('dialog', { name: 'Basket' });

        await opener.focus();
        await page.keyboard.press('Enter');
        await expect(dialog).toBeVisible();
        expect(await holdsTheFocus(dialog)).toBe(true);
        await expect(dialog).toMatchAriaSnapshot(`
            - dialog "Basket":
              - heading "Basket" [level=2]
              - button "Close"
        `);

        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
        await expect(opener).toBeFocused();
    });

    test('a click on the page beside it puts it away and gives the focus back', async ({ page }) => {
        await page.goto(offcanvasPage);
        const opener = page.getByTestId('sg-offcanvas-end-open');
        const dialog = page.getByTestId('sg-offcanvas-end');

        await opener.focus();
        await page.keyboard.press('Enter');
        await expect(dialog).toBeVisible();
        expect(await rootOverflow(page)).toBe('hidden');

        await page.mouse.click(4, 300);

        await expect(dialog).toBeHidden();
        await expect(opener).toBeFocused();
        expect(await rootOverflow(page)).not.toBe('hidden');
    });

    test('a browser without Invoker Commands opens and closes it through the document', async ({ page }) => {
        await withoutInvokerCommands(page);
        await page.goto(offcanvasPage);
        const opener = page.getByTestId('sg-offcanvas-start-open');
        const dialog = page.getByTestId('sg-offcanvas-start');

        await opener.focus();
        await page.keyboard.press('Enter');
        await expect(dialog).toBeVisible();
        expect(await isModal(dialog)).toBe(true);

        await dialog.getByRole('button', { name: 'Close' }).press('Enter');
        await expect(dialog).toBeHidden();
        await expect(opener).toBeFocused();
    });
});

test.describe('motion', () => {
    for (const [component, address, opener, dialog] of [
        ['c-modal', modalPage, 'sg-modal-open', 'sg-modal'],
        ['c-offcanvas', offcanvasPage, 'sg-offcanvas-end-open', 'sg-offcanvas-end'],
    ] as const) {
        test(`${component} is drawn in over a moment, and at once for somebody who asked for reduced motion`, async ({ page }) => {
            await page.goto(address);
            const shown = page.getByTestId(dialog);
            const duration = (): Promise<string> =>
                shown.evaluate((element) => getComputedStyle(element).transitionDuration);

            await page.getByTestId(opener).click();
            await expect(shown).toBeVisible();
            expect((await duration()).split(', ').some((part) => part !== '0s'), 'nothing is drawn in over a moment').toBe(true);
            await page.keyboard.press('Escape');
            await expect(shown).toBeHidden();

            await page.emulateMedia({ reducedMotion: 'reduce' });
            await page.getByTestId(opener).click();
            await expect(shown).toBeVisible();
            expect((await duration()).split(', ').every((part) => part === '0s'), 'something still moves').toBe(true);
        });
    }
});

for (const theme of themes) {
    for (const mode of modes) {
        const where = `${theme}, ${mode}`;

        for (const [component, address, opener, dialog] of [
            ['c-modal', modalPage, 'sg-modal-open', 'sg-modal'],
            ['c-offcanvas', offcanvasPage, 'sg-offcanvas-end-open', 'sg-offcanvas-end'],
        ] as const) {
            test(`${component} and what is behind it are drawn out of the tokens in ${where}`, async ({ page }) => {
                await page.emulateMedia({ reducedMotion: 'reduce' });
                await page.goto(address);
                await drawIn(page, theme, mode);

                const shown = page.getByTestId(dialog);
                await page.getByTestId(opener).click();
                await expect(shown).toBeVisible();

                const drawn = await shown.evaluate((element) => {
                    const style = getComputedStyle(element);

                    return {
                        background: style.backgroundColor,
                        ink: style.color,
                        backdrop: getComputedStyle(element, '::backdrop').backgroundColor,
                    };
                });

                expect(drawn.background, `the ground of ${component}`).toBe(await token(shown, 'background-color', '--color-surface'));
                expect(drawn.ink, `the ink of ${component}`).toBe(await token(shown, 'color', '--color-ink'));
                expect(drawn.backdrop, `what is laid over the page behind ${component}`).toBe(
                    await token(shown, 'background-color', '--color-backdrop'),
                );
                // A backdrop the page shows through, and not a wall in front of it.
                expect(drawn.backdrop).toMatch(/\/ 0?\.\d+\)$|, 0?\.\d+\)$/);
            });
        }
    }
}
