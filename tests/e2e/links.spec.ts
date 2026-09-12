import { expect, type Locator, type Page, test } from '@playwright/test';

/**
 * A link inside running text is told apart from the text around it by an
 * underline and nothing else (WCAG 1.4.1): assets/base.css sets color: inherit
 * on every link on purpose (D3), so colour never carries that signal, and the
 * underline is what does.
 *
 * A component that draws its own kind of link - a card, a button, the primary
 * navigation, the way through a listing - already says so on its own class and
 * keeps its own look; only .c-prose, .c-field, .c-notice and plain running text
 * are read this way. The list below is the negative half of that claim: every
 * component whose link must stay exactly as it was.
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

function decorationLine(locator: Locator): Promise<string> {
    return locator.evaluate((element) => getComputedStyle(element).textDecorationLine);
}

test.describe('a link in running text is underlined', () => {
    for (const theme of themes) {
        for (const mode of modes) {
            test(`a link inside .c-prose is underlined in ${theme}, ${mode}`, async ({ page }) => {
                await page.goto('/_styleguide/components/prose');
                await drawIn(page, theme, mode);

                const link = page.getByTestId('sample-prose-link');
                await expect(link).toBeVisible();
                expect(await decorationLine(link)).toBe('underline');
            });

            test(`a link in a plain paragraph outside .c-prose is underlined in ${theme}, ${mode}`, async ({
                page,
            }) => {
                await page.goto('/_styleguide/layout/containers');
                await drawIn(page, theme, mode);

                const link = page.getByTestId('styleguide-content-width-link');
                await expect(link).toBeVisible();
                expect(await decorationLine(link)).toBe('underline');
            });
        }
    }

    test('the underline is thicker while the pointer rests on the link', async ({ page }) => {
        await page.goto('/_styleguide/components/prose');
        await page.addStyleTag({ content: '*, *::before, *::after { transition: none !important; }' });

        const link = page.getByTestId('sample-prose-link');
        const thickness = (): Promise<string> =>
            link.evaluate((element) => getComputedStyle(element).textDecorationThickness);

        const atRest = await thickness();
        await link.hover();
        const onHover = await thickness();

        expect(onHover).not.toBe(atRest);
    });
});

test.describe('a component with its own kind of link keeps its own look', () => {
    const cases: Array<{
        readonly name: string;
        readonly goto: (page: Page) => Promise<unknown>;
        readonly locate: (page: Page) => Locator;
    }> = [
        {
            name: 'c-card__link (and c-signpost, built out of it)',
            goto: (page) => page.goto('/_styleguide/components/card'),
            locate: (page) => page.locator('[data-styleguide-variant="default"] .c-card__link').first(),
        },
        {
            name: 'c-nav__link',
            goto: (page) => page.goto('/_styleguide/components/nav'),
            locate: (page) => page.locator('[data-styleguide-variant="default"] .c-nav__link').first(),
        },
        {
            name: 'c-site-header__brand',
            goto: (page) => page.goto('/_styleguide/components/site-header'),
            locate: (page) => page.locator('[data-styleguide-variant="default"] .c-site-header__brand'),
        },
        {
            name: 'c-button drawn as a link',
            goto: (page) => page.goto('/_styleguide/components/button'),
            locate: (page) => page.locator('[data-styleguide-variant="primary"] .c-button'),
        },
        {
            name: 'c-breadcrumb__link',
            goto: (page) => page.goto('/_styleguide/components/breadcrumb'),
            locate: (page) => page.getByTestId('sample-breadcrumb').locator('.c-breadcrumb__link').first(),
        },
        {
            name: 'c-pagination__link',
            goto: (page) => page.goto('/_styleguide/components/pagination'),
            locate: (page) => page.getByTestId('sample-pagination').locator('.c-pagination__link').first(),
        },
        {
            name: 'c-list-group__entry',
            goto: (page) => page.goto('/_styleguide/components/list-group'),
            locate: (page) => page.getByTestId('sample-list-links').locator('.c-list-group__entry').first(),
        },
    ];

    for (const { name, goto, locate } of cases) {
        for (const theme of themes) {
            for (const mode of modes) {
                test(`${name} carries no underline in ${theme}, ${mode}`, async ({ page }) => {
                    await goto(page);
                    await drawIn(page, theme, mode);

                    const link = locate(page);
                    await expect(link).toBeVisible();
                    expect(await decorationLine(link)).toBe('none');
                });
            }
        }
    }

    test("the icon-only link in a table's actions column carries no underline", async ({ page }) => {
        // Every action in the style guide's own specimens is drawn as
        // c-button, which the case above already covers - this is the one
        // place the application draws the action as a bare link with nothing
        // but an icon in it (src/Cms/Presentation/Admin/templates/Page/default.latte),
        // and there is no page of the guide it is shown on. A cell built by
        // hand and appended to a page already carrying the built stylesheet
        // stands in for it, rather than signing in to the administration for
        // one selector this suite already knows the shape of.
        await page.goto('/_styleguide/components/prose');

        await page.evaluate(() => {
            const cell = document.createElement('div');
            cell.className = 'c-table__actions';
            cell.innerHTML =
                '<a href="#" data-testid="bare-table-action">' +
                '<svg width="16" height="16" aria-hidden="true"><path d="M0 0h16v16H0z" /></svg>' +
                '</a>';
            document.body.append(cell);
        });

        expect(await decorationLine(page.getByTestId('bare-table-action'))).toBe('none');
    });
});
