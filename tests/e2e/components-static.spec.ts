import { expect, type Locator, type Page, test } from '@playwright/test';

/**
 * Breadcrumb, Pagination, Close button, Button group and List group in a real
 * browser: what the keyboard reaches, what the accessibility tree says, and what
 * the stylesheet draws in either theme and either mode.
 *
 * The markup of each is held in tests/Template; what is here is only what a
 * browser has to be asked about - a separator drawn by the stylesheet and not
 * read out, a disabled step the keyboard passes over, a group of radios the
 * arrow keys move through, a notice put away with the focus left somewhere that
 * is still there.
 *
 * The specimens are the style guide's, invented.
 */

const themes = ['atrium', 'ledger'] as const;

const modes = ['light', 'dark'] as const;

function specimen(page: Page, variant: string): Locator {
    return page.locator(`[data-styleguide-variant="${variant}"]`);
}

async function drawIn(page: Page, theme: string, mode: string): Promise<void> {
    await page.evaluate(
        ([chosenTheme, chosenMode]) => {
            document.documentElement.setAttribute('data-theme', chosenTheme);
            document.documentElement.setAttribute('data-theme-mode', chosenMode);
        },
        [theme, mode],
    );
}

/** Whether the focus is inside $locator. */
async function holdsTheFocus(locator: Locator): Promise<boolean> {
    return locator.evaluate((element) => element.contains(document.activeElement));
}

test.describe('c-breadcrumb', () => {
    test('is a navigation of links ending in the page itself, and reads no separator', async ({ page }) => {
        await page.goto('/_styleguide/components/breadcrumb');
        const trail = page.getByTestId('sample-breadcrumb');

        await expect(trail).toMatchAriaSnapshot(`
            - navigation "Breadcrumb":
              - list:
                - listitem:
                  - link "Start"
                - listitem:
                  - link "Collections"
                - listitem: Trilobites
        `);
        // The snapshot lists every link's address as a /url line of its own;
        // what a screen reader reads is everything else.
        const read = (await trail.ariaSnapshot())
            .split('\n')
            .filter((line) => !line.trim().startsWith('- /url:'))
            .join('\n');
        expect(read).not.toContain('/');
        await expect(trail.getByText('Trilobites')).toHaveAttribute('aria-current', 'page');

        // Not read out, and drawn all the same: an empty ::before would pass the
        // line above just as well.
        const separator = await trail
            .locator('li')
            .nth(1)
            .evaluate((item) => getComputedStyle(item, '::before').content);
        expect(separator).not.toBe('none');
        expect(separator).not.toBe('');
    });

    test('lets the keyboard stop on every step up and not on the page itself', async ({ page }) => {
        await page.goto('/_styleguide/components/breadcrumb');
        const trail = page.getByTestId('sample-breadcrumb');

        await trail.getByRole('link', { name: 'Start' }).focus();
        await page.keyboard.press('Tab');
        await expect(trail.getByRole('link', { name: 'Collections' })).toBeFocused();

        await page.keyboard.press('Tab');
        expect(await holdsTheFocus(trail)).toBe(false);
    });
});

test.describe('c-pagination', () => {
    test('names every page, marks the current one and shortens the row', async ({ page }) => {
        await page.goto('/_styleguide/components/pagination');
        const pages = page.getByTestId('sample-pagination');

        await expect(pages).toMatchAriaSnapshot(`
            - navigation "Pages":
              - list:
                - listitem:
                  - link "First"
                - listitem:
                  - link "Previous"
                - listitem:
                  - link "Page 1"
                - listitem: …
                - listitem:
                  - link "Page 4"
                - listitem:
                  - link "Page 5"
                - listitem:
                  - link "Page 6"
                - listitem: …
                - listitem:
                  - link "Page 12"
                - listitem:
                  - link "Next"
                - listitem:
                  - link "Last"
        `);
        await expect(pages.getByRole('link', { exact: true, name: 'Page 5' })).toHaveAttribute('aria-current', 'page');
    });

    test('draws the way back from the first page as disabled, and the keyboard passes over it', async ({ page }) => {
        await page.goto('/_styleguide/components/pagination');
        const pages = page.getByTestId('sample-pagination-first');

        await expect(pages.getByRole('link', { name: 'First' })).toBeDisabled();
        await expect(pages.getByRole('link', { name: 'Previous' })).toBeDisabled();
        await expect(pages.getByRole('link', { name: 'Next' })).toBeEnabled();

        await pages.getByRole('link', { exact: true, name: 'Page 1' }).focus();
        await page.keyboard.press('Shift+Tab');
        expect(await holdsTheFocus(pages)).toBe(false);
    });
});

test.describe('c-close and a dismissible c-notice', () => {
    test('is a button named for what it does', async ({ page }) => {
        await page.goto('/_styleguide/components/close');

        await expect(page.getByTestId('sample-close')).toMatchAriaSnapshot(`- button "Close the panel"`);
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
        test(`is put away ${how}, and the focus goes on to what came after it`, async ({ page }) => {
            await page.goto('/_styleguide/components/notice');
            const notice = page.getByTestId('sample-notice-dismissible');
            const button = notice.getByRole('button', { name: 'Dismiss' });

            await expect(notice).toBeVisible();
            await press(button, page);

            await expect(notice).toBeHidden();
            const focus = await notice.evaluate((element) => ({
                somewhere: document.activeElement !== null && document.activeElement !== document.body,
                after:
                    document.activeElement !== null &&
                    (element.compareDocumentPosition(document.activeElement) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0,
                drawn: document.activeElement instanceof HTMLElement && document.activeElement.checkVisibility(),
            }));
            expect(focus).toEqual({ somewhere: true, after: true, drawn: true });
        });
    }

    /**
     * A notice Naja draws into the page later is new markup nobody set up,
     * which is the case a script that prepared the notices once would miss.
     */
    test('is put away when it arrived after the page did', async ({ page }) => {
        await page.goto('/_styleguide/components/notice');
        const original = page.getByTestId('sample-notice-dismissible');

        await original.evaluate((element) => {
            const copy = element.cloneNode(true) as HTMLElement;
            copy.setAttribute('data-testid', 'redrawn-notice');
            const holder = document.createElement('div');
            holder.innerHTML = copy.outerHTML;
            element.parentElement?.append(holder);
        });

        const redrawn = page.getByTestId('redrawn-notice');
        await redrawn.getByRole('button', { name: 'Dismiss' }).click();

        await expect(redrawn).toBeHidden();
        await expect(original).toBeVisible();
    });

    test('leaves a notice that is not dismissible without a button', async ({ page }) => {
        await page.goto('/_styleguide/components/notice');

        await expect(specimen(page, 'info').getByRole('button', { name: 'Dismiss' })).toHaveCount(0);
    });
});

test.describe('c-button-group', () => {
    test('is a named group of buttons', async ({ page }) => {
        await page.goto('/_styleguide/components/button-group');

        await expect(page.getByTestId('sample-button-group')).toMatchAriaSnapshot(`
            - group "Specimen":
              - button "Catalogue"
              - button "Duplicate"
              - button "Withdraw"
        `);
    });

    test('is a radio group of real radios, moved through with the arrow keys', async ({ page }) => {
        await page.goto('/_styleguide/components/button-group');
        const choices = page.getByTestId('sample-choices');

        await expect(choices).toMatchAriaSnapshot(`
            - radiogroup "Order by":
              - radio "Name"
              - radio "Age" [checked]
              - radio "Size"
        `);

        // Into the group with Tab, which lands on the answer that is on.
        await choices.evaluate((group) => {
            const before = document.createElement('button');
            before.textContent = 'before';
            before.setAttribute('data-testid', 'before-the-choices');
            group.before(before);
        });
        await page.getByTestId('before-the-choices').focus();
        await page.keyboard.press('Tab');
        await expect(choices.getByRole('radio', { name: 'Age' })).toBeFocused();

        await page.keyboard.press('ArrowRight');
        await expect(choices.getByRole('radio', { name: 'Size' })).toBeChecked();
        await expect(choices.getByRole('radio', { name: 'Size' })).toBeFocused();

        // The focus ring is on the answer you see, not on the radio drawn invisible over it.
        const ring = await choices
            .locator('label', { hasText: 'Size' })
            .evaluate((label) => getComputedStyle(label).outlineStyle);
        expect(ring).not.toBe('none');
    });
});

test.describe('c-list-group', () => {
    test('is a list of links with the current one marked and its count read with it', async ({ page }) => {
        await page.goto('/_styleguide/components/list-group');
        const list = page.getByTestId('sample-list-links');

        await expect(list).toMatchAriaSnapshot(`
            - list:
              - listitem:
                - link "Specimens"
              - listitem:
                - link "Excavations"
              - listitem:
                - link "Loans"
        `);
        await expect(list.getByRole('link', { name: 'Excavations' })).toHaveAttribute('aria-current', 'page');
        await expect(page.getByTestId('sample-list-counts')).toMatchAriaSnapshot(`
            - list:
              - listitem:
                - link /Awaiting a label 12/
        `);
    });
});

test.describe('in either theme and either mode', () => {
    for (const theme of themes) {
        for (const mode of modes) {
            test(`the state of each is drawn in ${theme}, ${mode}`, async ({ page }) => {
                const background = (locator: Locator): Promise<string> =>
                    locator.evaluate((element) => getComputedStyle(element).backgroundColor);

                await page.goto('/_styleguide/components/button-group');
                await drawIn(page, theme, mode);
                const choices = page.getByTestId('sample-choices');
                expect(await background(choices.locator('label', { hasText: 'Age' }))).not.toBe(
                    await background(choices.locator('label', { hasText: 'Name' })),
                );

                await page.goto('/_styleguide/components/pagination');
                await drawIn(page, theme, mode);
                const pages = page.getByTestId('sample-pagination');
                expect(await background(pages.getByRole('link', { exact: true, name: 'Page 5' }))).not.toBe(
                    await background(pages.getByRole('link', { exact: true, name: 'Page 4' })),
                );

                await page.goto('/_styleguide/components/list-group');
                await drawIn(page, theme, mode);
                const list = page.getByTestId('sample-list-links');
                expect(await background(list.getByRole('link', { name: 'Excavations' }))).not.toBe(
                    await background(list.getByRole('link', { name: 'Loans' })),
                );
            });
        }
    }
});
