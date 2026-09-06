import { expect, type Page, test } from '@playwright/test';

import { choose } from './preferences';

/**
 * The measured proof behind decision D6: a theme is not a repaint.
 *
 * Everything below is read out of the browser's computed style and its layout,
 * on one document, after nothing but an attribute on <html> changed. No reload,
 * no request, no rebuild - and the check that says so is the marker put on
 * window before the switch and still there afterwards.
 *
 * Two things are asserted and both have to hold. The palette changes, which any
 * theme could manage. And the navigation moves from under the banner to beside
 * the page, which only a theme whose layout is expressed in tokens can - markup
 * with its appearance written into it would keep the navigation exactly where it
 * was and this would fail.
 */

interface Measurement {
    readonly theme: string | null;
    readonly canvas: string;
    readonly accent: string;
    readonly navDirection: string;
    readonly shellAreas: string;
    readonly contentWidth: string;
    readonly nav: { top: number; left: number; right: number; bottom: number; width: number };
    readonly main: { top: number; left: number; right: number; bottom: number; width: number };
    readonly marker: string | undefined;
}

async function measure(page: Page): Promise<Measurement> {
    return page.evaluate(() => {
        const nav = document.querySelector('[data-testid="layout-nav"]');
        const list = document.querySelector('.c-nav__list');
        const main = document.querySelector('[data-testid="layout-content"]');
        if (nav === null || list === null || main === null) {
            throw new Error('the layout is missing the navigation or the content region');
        }

        const box = (element: Element) => {
            const rect = element.getBoundingClientRect();

            return {
                top: rect.top,
                left: rect.left,
                right: rect.right,
                bottom: rect.bottom,
                width: rect.width,
            };
        };

        const root = getComputedStyle(document.documentElement);

        return {
            theme: document.documentElement.getAttribute('data-theme'),
            canvas: getComputedStyle(document.body).backgroundColor,
            accent: root.getPropertyValue('--color-accent').trim(),
            navDirection: getComputedStyle(list).flexDirection,
            shellAreas: getComputedStyle(document.body).gridTemplateAreas,
            contentWidth: root.getPropertyValue('--layout-content-width').trim(),
            nav: box(nav),
            main: box(main),
            marker: (window as unknown as { __trilobitMarker?: string }).__trilobitMarker,
        };
    });
}

test.describe('themes', () => {
    test('switching data-theme repaints the page and moves the navigation, on the same document', async ({
        page,
    }) => {
        await page.goto('/');

        // Anything on window survives a re-layout and does not survive a
        // reload, so finding it afterwards is what rules out "the page was
        // fetched again with the other theme".
        await page.evaluate(() => {
            (window as unknown as { __trilobitMarker?: string }).__trilobitMarker = 'same document';
        });

        const atrium = await measure(page);
        expect(atrium.theme).toBe('atrium');

        await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'ledger'));

        const ledger = await measure(page);
        expect(ledger.theme).toBe('ledger');
        expect(ledger.marker).toBe('same document');

        // The palette.
        expect(ledger.canvas).not.toBe(atrium.canvas);
        expect(ledger.accent).not.toBe(atrium.accent);

        // The layout, as the browser resolved it.
        expect(ledger.shellAreas).not.toBe(atrium.shellAreas);
        expect(ledger.contentWidth).not.toBe(atrium.contentWidth);
        expect(atrium.navDirection).toBe('row');
        expect(ledger.navDirection).toBe('column');

        // Atrium stacks: the navigation ends above the content and starts at
        // the same edge.
        expect(atrium.nav.bottom).toBeLessThanOrEqual(atrium.main.top);
        expect(atrium.nav.left).toBeCloseTo(atrium.main.left, 0);

        // Ledger puts it beside: the navigation ends before the content begins
        // and starts no lower than the banner it now sits next to.
        expect(ledger.nav.right).toBeLessThanOrEqual(ledger.main.left);
        expect(ledger.nav.top).toBeLessThanOrEqual(ledger.main.top);
        expect(ledger.nav.width).toBeLessThan(ledger.main.width);
    });

    test('the style guide switches the theme from its own control', async ({ page }) => {
        const problems: string[] = [];
        page.on('console', (message) => {
            if (message.type() !== 'error') {
                return;
            }

            problems.push(message.text());
        });
        page.on('pageerror', (error) => problems.push(error.message));

        await page.goto('/_styleguide');

        await expect(page.getByTestId('styleguide-headline')).toHaveText('Style guide');
        const before = await measure(page);

        await page.getByTestId('theme-choice-ledger').click();

        const after = await measure(page);
        expect(after.theme).toBe('ledger');
        expect(after.canvas).not.toBe(before.canvas);
        expect(after.nav.right).toBeLessThanOrEqual(after.main.left);

        expect(problems).toEqual([]);
    });

    /**
     * Decision D8, and the half of it a unit test cannot see.
     *
     * That the theme survives a reload is one claim; that the page never
     * appeared in the other one on the way is a different and harder one, and
     * it is the whole reason the server writes the attribute rather than a
     * script putting it there afterwards. A flash of the wrong palette is a
     * fault nobody reports as a fault - it reads as slowness - so it is watched
     * for rather than looked at: an observer installed before any script of the
     * page runs records every change to those attributes, and finding none is
     * the assertion.
     */
    test('a remembered theme is on the page from the first paint, not put there afterwards', async ({ page }) => {
        await page.goto('/_styleguide');
        await choose(page, 'theme', 'ledger');

        await page.addInitScript(() => {
            const changes: string[] = [];
            (window as unknown as { __themeChanges?: string[] }).__themeChanges = changes;

            // The document exists before its element does, so the observation
            // is hung on the document and reaches <html> through the subtree.
            new MutationObserver((records) => {
                for (const record of records) {
                    changes.push(record.attributeName ?? '');
                }
            }).observe(document, {
                attributes: true,
                subtree: true,
                attributeFilter: ['data-theme', 'data-theme-mode'],
            });
        });

        await page.reload();

        expect(await page.evaluate(() => document.documentElement.getAttribute('data-theme'))).toBe('ledger');
        expect(
            await page.evaluate(() => (window as unknown as { __themeChanges?: string[] }).__themeChanges),
            'the page changed its own theme after loading, which is what a flash of the wrong one looks like',
        ).toEqual([]);

        // And it is the same page everywhere, not a property of the style
        // guide: the layout of the public site reads the same choice.
        await page.goto('/');
        expect(await page.evaluate(() => document.documentElement.getAttribute('data-theme'))).toBe('ledger');
    });

    /** The switch shows what is remembered, so a reload does not lie about what is on. */
    test('the control for the remembered choice is the one drawn as pressed', async ({ page }) => {
        await page.goto('/_styleguide');
        await choose(page, 'theme-mode', 'dark');

        await page.reload();

        await expect(page.getByTestId('theme-mode-choice-dark')).toHaveAttribute('aria-pressed', 'true');
        await expect(page.getByTestId('theme-mode-choice-system')).toHaveAttribute('aria-pressed', 'false');
        expect(await page.evaluate(() => document.documentElement.getAttribute('data-theme-mode'))).toBe('dark');
    });

    /**
     * Two choices made faster than a round trip, which is what a person does
     * when they set the theme and then the mode.
     *
     * Nothing is waited for between the clicks on purpose: the page is asked to
     * do what it would do on its own, and both choices have to be there
     * afterwards. It is the browser half of
     * Trilobit\Tests\Integration\Preference\RememberedPreferencesTest's claim
     * about two changes that overlap - one for the cookies, this one for the
     * order the requests really go out in.
     */
    test('two choices made in the same breath are both remembered, one after the other', async ({ page }) => {
        const traffic: string[] = [];
        page.on('request', (request) => {
            if (request.url().endsWith('/_preference')) {
                traffic.push('sent');
            }
        });
        page.on('response', (response) => {
            if (response.url().endsWith('/_preference')) {
                traffic.push('answered');
            }
        });

        await page.goto('/_styleguide');

        // Both clicks in one turn of the page's own event loop. Two awaited
        // clicks would not do: the second waits for the element to be
        // actionable, which is long enough for the first request to have come
        // back, and the thing under test is what happens when it has not.
        await page.evaluate(() => {
            for (const id of ['theme-choice-ledger', 'theme-mode-choice-dark']) {
                document.querySelector<HTMLElement>(`[data-testid="${id}"]`)?.click();
            }
        });

        await page.waitForResponse(
            (response) =>
                response.url().endsWith('/_preference') &&
                response.request().postData()?.includes('theme-mode') === true,
        );

        // One at a time, in the order they were chosen. Overlapping, the second
        // would read the profile the first had not saved yet and save over it -
        // a lost choice that shows up on another device and nowhere else.
        expect(traffic).toEqual(['sent', 'answered', 'sent', 'answered']);

        await page.reload();

        expect(await page.evaluate(() => document.documentElement.getAttribute('data-theme'))).toBe('ledger');
        expect(await page.evaluate(() => document.documentElement.getAttribute('data-theme-mode'))).toBe('dark');
    });

    test('the dark mode is a variant inside a theme, not a theme of its own', async ({ page }) => {
        await page.goto('/_styleguide');

        const light = await page.evaluate(() => {
            document.documentElement.setAttribute('data-theme-mode', 'light');

            return getComputedStyle(document.body).backgroundColor;
        });

        const dark = await page.evaluate(() => {
            document.documentElement.setAttribute('data-theme-mode', 'dark');

            return getComputedStyle(document.body).backgroundColor;
        });

        expect(dark).not.toBe(light);

        // Still the same theme: the mode changed what the tokens resolve to,
        // not which set of tokens is in play.
        expect(await page.evaluate(() => document.documentElement.getAttribute('data-theme'))).toBe('atrium');
    });
});
