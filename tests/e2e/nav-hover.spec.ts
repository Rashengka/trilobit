import { expect, type Page, test } from '@playwright/test';

/**
 * What the navigation says while the pointer rests on it has to stay readable.
 *
 * A theme paints its navigation on a ground of its own - ledger's is a dark
 * column - and a hover that borrows the page's hover colour instead of the
 * navigation's own can put light text on a light patch. Nothing turns red when
 * that happens: the page renders, the link works, and the words under the
 * pointer are simply gone. So it is measured: the colour of the text against
 * the ground it is actually drawn on, as the browser resolved both, in every
 * theme and every mode, for a link of the navigation and for the button beside
 * an entry with entries under it.
 *
 * The ground is every background from the page down to the element, laid one
 * over another the way they are painted, so a transparent link on a coloured
 * column is measured against the column. The colours are turned into sRGB by a
 * canvas, because the computed style hands them back in whatever notation the
 * theme wrote them in. The threshold is WCAG's 4.5:1 for ordinary text.
 */

const themes = ['atrium', 'ledger'] as const;
const modes = ['light', 'dark'] as const;

/** WCAG 2's minimum for text under 18pt. */
const minimum = 4.5;

/** The specimen entry with entries under it; its button is named after it. */
const parent = 'sample-subnav-collections';

const controls = [
    { what: 'a link of the navigation', testId: 'nav-home' },
    { what: 'the button beside an entry with entries under it', testId: `${parent}-toggle` },
] as const;

async function contrastWhileHovered(page: Page, testId: string): Promise<{ ratio: number; ink: string; ground: string }> {
    const control = page.getByTestId(testId);
    await control.hover();

    return control.evaluate((element) => {
        const canvas = document.createElement('canvas');
        canvas.width = 1;
        canvas.height = 1;
        const context = canvas.getContext('2d', { willReadFrequently: true });
        if (context === null) {
            throw new Error('no canvas to resolve colours with');
        }

        const paint = (colours: readonly string[]): readonly [number, number, number] => {
            context.clearRect(0, 0, 1, 1);
            for (const colour of colours) {
                context.fillStyle = colour;
                context.fillRect(0, 0, 1, 1);
            }

            const [red, green, blue, alpha] = context.getImageData(0, 0, 1, 1).data;
            if (alpha !== 255) {
                throw new Error(`nothing opaque is painted under the element: ${colours.join(' / ')}`);
            }

            return [red, green, blue];
        };

        const layers: string[] = [];
        for (let current: Element | null = element; current !== null; current = current.parentElement) {
            layers.unshift(getComputedStyle(current).backgroundColor);
        }

        const ground = paint(layers);
        const ink = paint([...layers, getComputedStyle(element).color]);

        const luminance = (rgb: readonly [number, number, number]): number => {
            const channel = (value: number): number => {
                const share = value / 255;

                return share <= 0.04045 ? share / 12.92 : ((share + 0.055) / 1.055) ** 2.4;
            };

            return 0.2126 * channel(rgb[0]) + 0.7152 * channel(rgb[1]) + 0.0722 * channel(rgb[2]);
        };

        const [lighter, darker] = [luminance(ink), luminance(ground)].sort((a, b) => b - a);

        return {
            ratio: (lighter + 0.05) / (darker + 0.05),
            ink: `rgb(${ink.join(', ')})`,
            ground: `rgb(${ground.join(', ')})`,
        };
    });
}

for (const theme of themes) {
    for (const mode of modes) {
        test(`in ${theme}, ${mode}, what the pointer rests on in the navigation stays readable`, async ({ page }) => {
            await page.setViewportSize({ width: 1400, height: 900 });
            await page.goto('/_styleguide/components/nav');
            await page.evaluate(
                ([name, scheme]) => {
                    document.documentElement.setAttribute('data-theme', name);
                    document.documentElement.setAttribute('data-theme-mode', scheme);
                },
                [theme, mode] as const,
            );

            // The hover colour is animated, and a reading taken half way along
            // the animation would be a colour nobody chose.
            await page.addStyleTag({ content: '*, *::before, *::after { transition: none !important; }' });

            for (const control of controls) {
                const measured = await contrastWhileHovered(page, control.testId);
                test.info().annotations.push({
                    type: 'contrast',
                    description: `${theme} ${mode} ${control.testId}: ${measured.ratio.toFixed(2)}:1 (${measured.ink} on ${measured.ground})`,
                });

                expect
                    .soft(
                        measured.ratio,
                        `${control.what} is ${measured.ink} on ${measured.ground} while hovered, ${measured.ratio.toFixed(2)}:1`,
                    )
                    .toBeGreaterThanOrEqual(minimum);
            }
        });
    }
}
