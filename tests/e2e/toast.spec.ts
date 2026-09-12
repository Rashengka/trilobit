import { expect, type Locator, type Page, test } from '@playwright/test';

/**
 * c-toast in a real browser, and the flash messages of Nette drawn with it.
 *
 * The markup of a toast is held in tests/Template/ToastTest; what is here is
 * what only a browser can be asked: a message said after a redirect and after
 * a Naja redraw arriving in the corner of the window, announced when it
 * arrives, staying until it is put away, put away with the focus left
 * somewhere that is still there, and drawn over the page in the layer the
 * themes name for it.
 *
 * **What "announced" is measured as.** A screen reader cannot be asked what it
 * read. A live region is read when its content changes after the reader has
 * seen it, and a toast that arrives already holding its sentence - on the page
 * a redirect led to, or in a snippet Naja put in - has not changed since. So
 * assets/toast.ts puts the sentence in again once the toast is on the page, and
 * that is what is measured: a change to the sentence itself, after it arrived.
 * Without the script there is none.
 *
 * The specimens and the messages are the style guide's, invented.
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

/**
 * Records, from before the page's own scripts run, every sentence of a toast
 * whose content changed while it was already on the page.
 *
 * Only a change that takes something away counts - text replaced, or text
 * edited in place. The parser writing a sentence into the page and Naja
 * putting a snippet in only add, so the sentence arriving is not mistaken for
 * the sentence being announced.
 */
async function listenForAnnouncements(page: Page): Promise<void> {
    await page.addInitScript(() => {
        const heard: string[] = [];
        Object.assign(window, { heard });

        new MutationObserver((records) => {
            for (const record of records) {
                const message = record.type === 'characterData' ? record.target.parentElement : record.target;
                const changed = record.type === 'characterData' || record.removedNodes.length > 0;
                if (changed && message instanceof Element && message.matches('.c-toast__message')) {
                    heard.push(message.textContent ?? '');
                }
            }
        }).observe(document, { childList: true, subtree: true, characterData: true });
    });
}

async function heard(page: Page): Promise<string[]> {
    return page.evaluate(() => (window as unknown as { heard: string[] }).heard);
}

/** The toasts the layout holds in the corner of the window. */
function corner(page: Page): Locator {
    return page.getByTestId('toasts');
}

test.describe('a specimen', () => {
    test('is a sentence in a live region with the button that puts it away beside it', async ({ page }) => {
        await page.goto('/_styleguide/components/toast');

        await expect(page.getByTestId('sample-toast-info')).toMatchAriaSnapshot(`
            - status: The specimen was catalogued.
            - button "Dismiss"
        `);
        await expect(page.getByTestId('sample-toast-danger')).toMatchAriaSnapshot(`
            - alert: The drawer is full, so nothing was moved.
            - button "Dismiss"
        `);
    });

    for (const [how, press] of [
        ['from the keyboard', async (button: Locator, page: Page) => {
            await button.focus();
            await page.keyboard.press('Enter');
        }],
        ['with the mouse', async (button: Locator) => {
            await button.click();
        }],
    ] as const) {
        test(`is put away ${how}, and the focus goes somewhere still drawn`, async ({ page }) => {
            await page.goto('/_styleguide/components/toast');
            const toasts = page.getByTestId('sample-toast-several');
            const first = toasts.locator('.c-toast__item').first();

            await press(first.getByRole('button', { name: 'Dismiss' }), page);

            await expect(first).toBeHidden();
            await expect(toasts.locator('.c-toast__item').nth(1)).toBeVisible();
            const focus = await page.evaluate(() => ({
                somewhere: document.activeElement !== null && document.activeElement !== document.body,
                drawn: document.activeElement instanceof HTMLElement && document.activeElement.checkVisibility(),
            }));
            expect(focus).toEqual({ somewhere: true, drawn: true });
        });
    }

    test('stays until it is put away', async ({ page }) => {
        await page.clock.install();
        await page.goto('/_styleguide/components/toast');
        const toast = page.getByTestId('sample-toast-info');

        await page.clock.runFor(10 * 60_000);

        await expect(toast).toBeVisible();
    });
});

test.describe('a flash message', () => {
    test('said before a redirect arrives in the corner of the page the redirect leads to, and is announced', async ({ page }) => {
        await listenForAnnouncements(page);
        await page.goto('/_styleguide/components/toast');
        await expect(corner(page)).toHaveCount(0);

        await page.getByTestId('sg-toast-redirect').click();
        await expect(page).toHaveURL(/_fid=/);

        await expect(corner(page)).toMatchAriaSnapshot(`
            - status: The specimen was catalogued, and the page was drawn again.
            - button "Dismiss"
        `);
        await expect.poll(() => heard(page)).toContain('The specimen was catalogued, and the page was drawn again.');

        // In the corner of the window, not in the flow of the page.
        const place = await corner(page).evaluate((node) => {
            const box = node.getBoundingClientRect();

            return {
                position: getComputedStyle(node).position,
                right: Math.round(window.innerWidth - box.right),
                bottom: Math.round(window.innerHeight - box.bottom),
            };
        });
        expect(place.position).toBe('fixed');
        expect(place.right).toBeGreaterThan(0);
        expect(place.right).toBeLessThan(100);
        expect(place.bottom).toBeGreaterThan(0);
        expect(place.bottom).toBeLessThan(100);
    });

    test('said in answer to Naja arrives in the corner of the same page, and is announced', async ({ page }) => {
        await listenForAnnouncements(page);
        await page.goto('/_styleguide/components/toast');
        await page.evaluate(() => Object.assign(window, { samePage: true }));

        await page.getByTestId('sg-toast-naja').click();
        await expect(page).toHaveURL(/do=sayItInAToast/);

        await expect(corner(page)).toMatchAriaSnapshot(`
            - status: The specimen was catalogued, and only the toasts were drawn again.
            - button "Dismiss"
        `);
        expect(await page.evaluate(() => (window as unknown as { samePage?: boolean }).samePage)).toBe(true);
        await expect.poll(() => heard(page)).toContain('The specimen was catalogued, and only the toasts were drawn again.');

        // A second message replaces the first rather than piling up on it.
        await page.getByTestId('sg-toast-naja').click();
        await expect(corner(page).locator('.c-toast__item')).toHaveCount(1);
    });

    test('is put away with the button beside it', async ({ page }) => {
        await page.goto('/_styleguide/components/toast');
        await page.getByTestId('sg-toast-naja').click();

        const toast = corner(page).locator('.c-toast__item');
        await toast.getByRole('button', { name: 'Dismiss' }).click();

        await expect(toast).toBeHidden();
    });

    test('is drawn over the page in the layer the themes name for it', async ({ page }) => {
        await page.goto('/_styleguide/components/toast');
        await page.getByTestId('sg-toast-naja').click();
        const toasts = corner(page);
        await expect(toasts).toBeVisible();

        const layer = await toasts.evaluate((node) => getComputedStyle(node).zIndex);
        expect(layer).toBe(await token(toasts, 'z-index', '--layout-z-toast'));
        expect(Number(layer)).toBeGreaterThan(Number(await token(toasts, 'z-index', '--layout-z-menu')));
    });
});

test.describe('the motion of arriving', () => {
    test('is a moment for somebody who has not asked for less motion, and none for somebody who has', async ({ page }) => {
        const duration = (): Promise<string> =>
            page
                .getByTestId('sample-toast-info')
                .locator('.c-toast__item')
                .evaluate((node) => getComputedStyle(node).transitionDuration);

        await page.emulateMedia({ reducedMotion: 'no-preference' });
        await page.goto('/_styleguide/components/toast');
        expect(await duration()).not.toMatch(/^0s(, 0s)*$/);

        await page.emulateMedia({ reducedMotion: 'reduce' });
        expect(await duration()).toMatch(/^0s(, 0s)*$/);
    });
});

test('the colours are the theme\'s in both themes and both modes', async ({ page }) => {
    await page.goto('/_styleguide/components/toast');
    const saved = page.getByTestId('sample-toast-info').locator('.c-toast__item');
    const refused = page.getByTestId('sample-toast-danger').locator('.c-toast__item');

    const grounds: string[] = [];
    for (const theme of themes) {
        for (const mode of modes) {
            await drawIn(page, theme, mode);
            const where = `${theme}, ${mode}`;
            const ground = (toast: Locator): Promise<string> => toast.evaluate((node) => getComputedStyle(node).backgroundColor);

            expect(await ground(saved), `a toast in ${where}`).toBe(await token(saved, 'background-color', '--color-surface'));
            expect(await ground(refused), `a refusal in ${where}`).toBe(await token(refused, 'background-color', '--color-danger-surface'));
            grounds.push(await ground(saved));
        }
    }

    expect(new Set(grounds).size, 'a toast is one colour whatever the theme and the mode').toBeGreaterThan(1);
});
