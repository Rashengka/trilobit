import { expect, type Locator, type Page, test } from '@playwright/test';

/**
 * The code the style guide shows: every specimen of a component with its HTML
 * and its Latte under it, highlighted, and a button that copies it.
 *
 * All of it happens in the browser - the highlighter and the button are the
 * style guide's own bundle - so it is measured in one: which tokens the
 * highlighter found, what the clipboard holds afterwards, and what colour the
 * theme gave each token against the ground it sits on.
 */

const themes = ['atrium', 'ledger'] as const;

const modes = ['light', 'dark'] as const;

/** Every language the code page shows a block of, by the name its class gives the highlighter. */
const languages = ['markup', 'css', 'javascript', 'typescript', 'php', 'latte', 'json', 'bash', 'sql', 'neon'] as const;

const BADGE = '/_styleguide/components/badge';

const CODE = '/_styleguide/content/code';

async function drawIn(page: Page, theme: string, mode: string): Promise<void> {
    await page.evaluate(
        ([chosenTheme, chosenMode]) => {
            document.documentElement.setAttribute('data-theme', chosenTheme);
            document.documentElement.setAttribute('data-theme-mode', chosenMode);
        },
        [theme, mode],
    );
}

function specimen(page: Page, variant: string): Locator {
    return page.locator(`[data-styleguide-variant="${variant}"]`);
}

test.describe('the code under a specimen', () => {
    /** The two are c-tabs: a tab list named after the specimen, the HTML shown first. */
    test('shows the HTML and the Latte of the specimen, each highlighted, one at a time', async ({ page }) => {
        await page.goto(BADGE);

        const source = specimen(page, 'plain').locator('.sg-source');
        const tabs = source.getByRole('tablist', { name: 'The code of plain' });
        const html = source.locator('.c-tabs__panel', { has: page.locator('code.language-markup') });
        const latte = source.locator('.c-tabs__panel', { has: page.locator('code.language-latte') });

        await expect(tabs.getByRole('tab', { name: 'HTML' })).toHaveAttribute('aria-selected', 'true');
        await expect(html).toHaveAttribute('role', 'tabpanel');
        await expect(html.locator('code .token.tag').first()).toBeVisible();
        await expect(html.locator('code')).toContainText('c-badge');
        await expect(latte).toBeHidden();

        await tabs.getByRole('tab', { name: 'Latte' }).click();

        await expect(latte).toBeVisible();
        await expect(html).toBeHidden();
        await expect(latte.locator('code .token.latte-tag').first()).toHaveText('include');
        await expect(latte.locator('code')).toHaveText("{include badge, label: 'Draft'}");
    });

    test('shows both, one under the other and each under its name, where the script does not run', async ({ browser }) => {
        const context = await browser.newContext({ baseURL: test.info().project.use.baseURL, javaScriptEnabled: false });
        const page = await context.newPage();
        await page.goto(BADGE);

        const source = specimen(page, 'plain').locator('.sg-source');
        await expect(source.getByRole('tab')).toHaveCount(0);
        await expect(source.locator('.c-tabs__title')).toHaveText(['HTML', 'Latte']);
        await expect(source.locator('code.language-markup')).toBeVisible();
        await expect(source.locator('code.language-latte')).toBeVisible();

        await context.close();
    });

    test('copies the code it sits on, says so, and is reached by keyboard', async ({ page, context }) => {
        await context.grantPermissions(['clipboard-read', 'clipboard-write']);
        await page.goto(BADGE);

        const frame = specimen(page, 'plain').locator('[data-sg-code]').first();
        const button = frame.getByRole('button', { name: 'Copy HTML' });

        await expect(button).toBeVisible();

        await button.focus();
        await page.keyboard.press('Enter');

        await expect(frame.getByRole('status')).toHaveText('Copied to the clipboard.');
        expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(
            (await frame.locator('code').textContent()) ?? 'the code is empty',
        );
    });
});

test.describe('the highlighter', () => {
    test('colours a block in every language it is given, and code inline', async ({ page }) => {
        await page.goto(CODE);

        for (const language of languages) {
            await expect(
                page.locator(`[data-testid="styleguide-highlight-${language}"] code .token`).first(),
                `${language} was not highlighted`,
            ).toBeAttached();
        }

        await expect(page.locator('[data-testid="styleguide-highlight-inline"] .token').first()).toBeAttached();
        await expect(page.locator('[data-testid="styleguide-highlight-latte"] .token.latte-tag').first()).toBeAttached();
    });

    /**
     * What a Naja redraw does to a page: new markup arrives in place of the
     * old, some of it already highlighted and some not. The new has to be
     * highlighted, and the old must come out the same as it went in rather
     * than wrapped a second time.
     */
    test('reaches code that arrives after the page loaded, and highlighting twice changes nothing', async ({ page }) => {
        await page.goto(CODE);

        const highlighted = page.locator('[data-testid="styleguide-highlight-php"] code');
        await expect(highlighted.locator('.token').first()).toBeAttached();
        const before = await highlighted.innerHTML();

        await page.evaluate((outer) => {
            const snippet = document.createElement('div');
            snippet.id = 'redrawn';
            snippet.innerHTML = outer
                + '<pre><code class="language-sql" data-testid="redrawn-sql">SELECT name FROM specimen;</code></pre>';
            document.querySelector('main')?.append(snippet);
        }, await page.locator('[data-testid="styleguide-highlight-php"]').evaluate((element) => element.outerHTML));

        await expect(page.locator('[data-testid="redrawn-sql"] .token.keyword').first()).toHaveText('SELECT');
        expect(await page.locator('#redrawn [data-testid="styleguide-highlight-php"] code').innerHTML()).toBe(before);
    });
});

test.describe('the colours of highlighted code', () => {
    /**
     * WCAG AA, 4.5:1, for every token the highlighter drew, against the ground
     * behind it, in both themes and both modes. The colours are resolved by
     * the browser and read back as pixels, so what is measured is what is
     * painted, whatever notation the theme wrote it in.
     */
    test('are readable on the ground of the code in both themes and both modes', async ({ page }) => {
        await page.goto(CODE);
        await expect(page.locator('[data-testid="styleguide-highlight-neon"] .token').first()).toBeAttached();

        const lowest: Record<string, number> = {};

        for (const theme of themes) {
            for (const mode of modes) {
                await drawIn(page, theme, mode);

                const found = await page.evaluate(() => {
                    const canvas = document.createElement('canvas');
                    canvas.width = 1;
                    canvas.height = 1;
                    const context = canvas.getContext('2d', { willReadFrequently: true });
                    if (context === null) {
                        throw new Error('no canvas to resolve colours on');
                    }

                    const rgb = (colour: string): [number, number, number] => {
                        context.clearRect(0, 0, 1, 1);
                        context.fillStyle = colour;
                        context.fillRect(0, 0, 1, 1);
                        const [r, g, b] = context.getImageData(0, 0, 1, 1).data;

                        return [r ?? 0, g ?? 0, b ?? 0];
                    };

                    const luminance = ([r, g, b]: [number, number, number]): number => {
                        const channel = (value: number): number => {
                            const c = value / 255;

                            return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
                        };

                        return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
                    };

                    const ground = (element: Element): string => {
                        for (let at: Element | null = element; at !== null; at = at.parentElement) {
                            const colour = getComputedStyle(at).backgroundColor;
                            if (colour !== 'rgba(0, 0, 0, 0)' && colour !== 'transparent') {
                                return colour;
                            }
                        }

                        return getComputedStyle(document.body).backgroundColor;
                    };

                    const readings: { kind: string; ratio: number }[] = [];
                    for (const span of document.querySelectorAll('[data-testid^="styleguide-highlight-"] .token')) {
                        if ((span.textContent ?? '').trim() === '' || span.querySelector('.token') !== null) {
                            continue;
                        }

                        const one = luminance(rgb(getComputedStyle(span).color));
                        const two = luminance(rgb(ground(span)));
                        readings.push({
                            kind: span.className,
                            ratio: (Math.max(one, two) + 0.05) / (Math.min(one, two) + 0.05),
                        });
                    }

                    return readings;
                });

                expect(found.length, 'no token was measured').toBeGreaterThan(0);

                const failing = found.filter((reading) => reading.ratio < 4.5);
                expect(failing, `${theme}, ${mode}: tokens below 4.5:1`).toEqual([]);

                const byKind = new Map<string, number>();
                for (const reading of found) {
                    byKind.set(reading.kind, Math.min(byKind.get(reading.kind) ?? Infinity, reading.ratio));
                }
                for (const [kind, ratio] of byKind) {
                    lowest[`${theme} ${mode} ${kind}`] = Math.round(ratio * 100) / 100;
                }
            }
        }

        test.info().annotations.push({ type: 'lowest contrast by kind of token', description: JSON.stringify(lowest) });
    });

    /** Colours a theme cannot change would pass the measurement above and still be a vendor's. */
    test('are the theme\'s: a keyword is not the same colour in the two themes', async ({ page }) => {
        await page.goto(CODE);
        const keyword = page.locator('[data-testid="styleguide-highlight-php"] .token.keyword').first();
        await expect(keyword).toBeAttached();

        const colours: string[] = [];
        for (const theme of themes) {
            await drawIn(page, theme, 'light');
            colours.push(await keyword.evaluate((element) => getComputedStyle(element).color));
        }

        expect(colours[0]).not.toBe(colours[1]);
    });
});

test.describe('the style guide\'s own bundle', () => {
    test('is loaded, versioned, by the pages of the style guide and by no other page', async ({ page }) => {
        await page.goto(BADGE);
        await expect(page.locator('script[src*="/build/styleguide.js"]')).toHaveAttribute(
            'src',
            /\/build\/styleguide\.js\?v=[0-9a-f]{8}$/,
        );

        await page.goto('/');
        await expect(page.locator('script[src*="/build/styleguide.js"]')).toHaveCount(0);
        await expect(page.locator('script[src*="/build/app.js"]')).toHaveCount(1);
    });
});
