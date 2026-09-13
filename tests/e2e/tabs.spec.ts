import { expect, type Locator, type Page, test } from '@playwright/test';

/**
 * c-tabs in a real browser, with the script and without it.
 *
 * Without it the component is every panel one under another, each under its
 * title, and that is the whole of it. The script (assets/tabs.ts) lays the
 * pattern of the ARIA Authoring Practices over that markup: a tab list named
 * after the component, one tab per panel named after the panel's title, one
 * tab in the order of the Tab key at a time, the arrow keys, Home and End
 * moving between them and showing the panel they arrive on.
 *
 * What a snippet redrawn by Naja does to it is measured too, the way back
 * through history included - Naja keeps the server's markup, and the tabs have
 * to be laid over it again and only once.
 *
 * The specimens are the style guide's, invented.
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

/** Which of the tabs of $tabs are selected, by name - the one thing the pattern allows exactly one of. */
async function selected(tabs: Locator): Promise<string[]> {
    return tabs.getByRole('tab', { selected: true }).allTextContents();
}

test.describe('c-tabs without the script', () => {
    test.use({ javaScriptEnabled: false });

    test('draws every panel one under another, each under its title', async ({ page }) => {
        await page.goto('/_styleguide/components/tabs');

        const tabs = page.getByTestId('sample-tabs');
        await expect(tabs.getByRole('tablist')).toHaveCount(0);
        await expect(tabs.getByRole('tab')).toHaveCount(0);

        const titles = tabs.locator('.c-tabs__title');
        await expect(titles).toHaveText(['Cleaning', 'Storing', 'Lending']);
        for (const panel of await tabs.locator('.c-tabs__panel').all()) {
            await expect(panel).toBeVisible();
        }

        // With a level, the titles are headings of the page and on its outline.
        const withHeadings = page.getByTestId('sample-tabs-headings');
        await expect(withHeadings.getByRole('heading', { level: 2 })).toHaveText(['Moults', 'Casts', 'Labels']);
    });
});

test.describe('c-tabs', () => {
    test('is a tab list named after the component, one tab per panel, and shows the first panel', async ({ page }) => {
        await page.goto('/_styleguide/components/tabs');
        const tabs = page.getByTestId('sample-tabs');

        await expect(tabs).toMatchAriaSnapshot(`
            - tablist "Care of the specimen":
              - tab "Cleaning" [selected]
              - tab "Storing"
              - tab "Lending"
            - tabpanel "Cleaning":
              - paragraph: /soft brush/
        `);

        // The title the tab took its name from is not read a second time.
        await expect(tabs.locator('.c-tabs__title').first()).toBeHidden();
        await expect(tabs.getByRole('tabpanel')).toHaveCount(1);
        await expect(tabs.getByRole('tab', { name: 'Cleaning' })).toHaveAttribute('aria-controls', 'sg-tabs-care-cleaning');
    });

    test('moves through its tabs with the arrow keys, Home and End, showing each at once', async ({ page }) => {
        await page.goto('/_styleguide/components/tabs');
        const tabs = page.getByTestId('sample-tabs');
        const tab = (name: string): Locator => tabs.getByRole('tab', { name });

        // One tab in the order of the Tab key, and it is the selected one.
        await expect(tabs.locator('[role="tab"][tabindex="0"]')).toHaveText(['Cleaning']);
        await expect(tabs.locator('[role="tab"][tabindex="-1"]')).toHaveCount(2);

        await tab('Cleaning').focus();

        const steps: [string, string][] = [
            ['ArrowRight', 'Storing'],
            ['ArrowRight', 'Lending'],
            ['ArrowRight', 'Cleaning'],
            ['ArrowLeft', 'Lending'],
            ['Home', 'Cleaning'],
            ['End', 'Lending'],
        ];

        for (const [key, name] of steps) {
            await page.keyboard.press(key);

            await expect(tab(name), `${key} did not arrive on ${name}`).toBeFocused();
            expect(await selected(tabs), `${key} did not show ${name}`).toEqual([name]);
            await expect(tabs.getByRole('tabpanel', { name })).toBeVisible();
            await expect(tabs.getByRole('tabpanel')).toHaveCount(1);
            await expect(tab(name)).toHaveAttribute('tabindex', '0');
        }
    });

    test('lets Tab leave the tab list for the panel it shows', async ({ page }) => {
        await page.goto('/_styleguide/components/tabs');
        const tabs = page.getByTestId('sample-tabs');

        // A panel with nothing in it to focus is focusable itself...
        await tabs.getByRole('tab', { name: 'Lending' }).click();
        await page.keyboard.press('Tab');
        await expect(tabs.getByRole('tabpanel', { name: 'Lending' })).toBeFocused();

        // ...and one with a link in it hands the focus to the link.
        await tabs.getByRole('tab', { name: 'Storing' }).click();
        await page.keyboard.press('Tab');
        await expect(tabs.getByRole('tabpanel', { name: 'Storing' }).getByRole('link')).toBeFocused();
    });

    test('shows the panel an address names, on arriving and when the address changes', async ({ page }) => {
        await page.goto('/_styleguide/components/tabs#sg-tabs-care-lending');
        const tabs = page.getByTestId('sample-tabs');

        expect(await selected(tabs)).toEqual(['Lending']);
        await expect(tabs.getByRole('tabpanel', { name: 'Lending' })).toBeInViewport();

        await page.evaluate(() => {
            location.hash = 'sg-tabs-care-storing';
        });
        await expect(tabs.getByRole('tab', { name: 'Storing' })).toHaveAttribute('aria-selected', 'true');
        await expect(tabs.getByRole('tabpanel', { name: 'Storing' })).toBeVisible();
    });

    /**
     * A snippet redrawn by Naja brings the server's markup, with no tab list
     * in it - and the way back brings the markup Naja kept, which is the
     * server's too. Either way the tabs have to be laid over it once: never
     * twice, never not at all. The tab somebody chose stays chosen, because a
     * redraw is typically a form in that very panel coming back refused.
     */
    test('are laid over a snippet Naja redraws once, keep the tab chosen, and survive history.back()', async ({ page }) => {
        await page.goto('/_styleguide/components/tabs');

        const snippet = page.getByTestId('sg-tabs-snippet');
        const tabs = snippet.getByTestId('sample-tabs-redrawn');
        await expect(tabs.getByRole('tablist')).toHaveCount(1);

        await tabs.getByRole('tab', { name: 'Evening' }).click();
        await tabs.locator('.c-tabs__panel').first().evaluate((node) => node.setAttribute('data-drawn-first', ''));

        await page.getByTestId('sg-tabs-redraw').click();
        await expect(page).toHaveURL(/do=redrawSpecimen/);
        await expect(tabs.locator('[data-drawn-first]')).toHaveCount(0);

        await expect(tabs.getByRole('tablist')).toHaveCount(1);
        expect(await selected(tabs)).toEqual(['Evening']);
        await tabs.getByRole('tab', { name: 'Evening' }).focus();
        await page.keyboard.press('ArrowRight');
        expect(await selected(tabs)).toEqual(['Night']);

        await page.evaluate(() => history.back());
        await expect(page).not.toHaveURL(/do=redrawSpecimen/);

        await expect(tabs.getByRole('tablist')).toHaveCount(1);
        await expect(tabs.getByRole('tab')).toHaveCount(3);
        await tabs.getByRole('tab', { name: 'Morning' }).click();
        expect(await selected(tabs)).toEqual(['Morning']);
        await expect(tabs.getByRole('tabpanel', { name: 'Morning' })).toBeVisible();
    });

    test('marks the chosen tab in the theme\'s accent in both themes and both modes', async ({ page }) => {
        await page.goto('/_styleguide/components/tabs');
        const tabs = page.getByTestId('sample-tabs');
        const chosen = tabs.getByRole('tab', { name: 'Cleaning' });
        const other = tabs.getByRole('tab', { name: 'Storing' });

        const accents = new Set<string>();
        for (const theme of themes) {
            for (const mode of modes) {
                await drawIn(page, theme, mode);

                const accent = await token(chosen, 'border-block-end-color', '--color-accent');
                accents.add(accent);
                await expect(chosen, `${theme}, ${mode}`).toHaveCSS('border-block-end-color', accent);
                await expect(chosen, `${theme}, ${mode}`).toHaveCSS('color', await token(chosen, 'color', '--color-ink'));
                await expect(other, `${theme}, ${mode}`).toHaveCSS('color', await token(other, 'color', '--color-ink-muted'));
                await expect(other, `${theme}, ${mode}`).not.toHaveCSS('border-block-end-color', accent);
            }
        }

        expect(accents.size, 'the accent is the same in every theme and mode, so it is nobody\'s').toBeGreaterThan(1);
    });
});
