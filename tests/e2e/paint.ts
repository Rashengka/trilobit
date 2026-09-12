import { expect, type Locator, type Page } from '@playwright/test';

/**
 * What the components that wait are measured with: the theme and mode a page
 * is drawn in, the colour a browser resolves a token or a property to, the
 * colour actually painted at a point of an element, and the contrast between
 * two colours.
 *
 * The painted colour is read off a screenshot rather than off the stylesheet,
 * because the fill of a <progress> or a <meter> is a part the browser draws for
 * itself: no computed style reaches it, and a claim about it made anywhere but
 * on the pixels would be a claim about the rule rather than about the bar.
 */

export const themes = ['atrium', 'ledger'] as const;

export const modes = ['light', 'dark'] as const;

export type Rgb = [number, number, number];

export async function drawIn(page: Page, theme: string, mode: string): Promise<void> {
    await page.evaluate(
        ([chosenTheme, chosenMode]) => {
            document.documentElement.setAttribute('data-theme', chosenTheme);
            document.documentElement.setAttribute('data-theme-mode', chosenMode);
        },
        [theme, mode],
    );
}

/** Where the specimen of $variant is drawn on a page of the style guide. */
export function stage(page: Page, variant: string): Locator {
    return page.locator(`[data-styleguide-variant="${variant}"] .sg-specimen__stage`);
}

/** How many animations are running on $element this moment. */
export async function runningOn(element: Locator): Promise<number> {
    return element.evaluate(
        (node) => node.getAnimations().filter((animation) => animation.playState === 'running').length,
    );
}

/**
 * The colour painted at a point of $element, given as fractions of its width
 * and height. The screenshot is decoded on a blank page of the same browser,
 * so that nothing the page under test allows or refuses decides whether it can
 * be read.
 */
export async function paintedAt(element: Locator, x: number, y = 0.5): Promise<Rgb> {
    // In the middle of the window: a screenshot scrolls an element only as far
    // as the edge, and there a theme's held banner is drawn over it.
    await element.evaluate((node) => node.scrollIntoView({ block: 'center' }));
    const shot = await element.screenshot({ animations: 'disabled', scale: 'css' });
    const blank = await element.page().context().newPage();

    try {
        return await blank.evaluate(
            async ([data, atX, atY]) => {
                const image = new Image();
                image.src = `data:image/png;base64,${data}`;
                await image.decode();

                const canvas = document.createElement('canvas');
                canvas.width = image.naturalWidth;
                canvas.height = image.naturalHeight;
                const context = canvas.getContext('2d', { willReadFrequently: true });
                if (context === null) {
                    throw new Error('no canvas to read the screenshot on');
                }

                context.drawImage(image, 0, 0);
                const [r, g, b] = context.getImageData(
                    Math.min(canvas.width - 1, Math.floor(canvas.width * atX)),
                    Math.min(canvas.height - 1, Math.floor(canvas.height * atY)),
                    1,
                    1,
                ).data;

                return [r ?? 0, g ?? 0, b ?? 0] as [number, number, number];
            },
            [shot.toString('base64'), x, y] as const,
        );
    } finally {
        await blank.close();
    }
}

/**
 * What $property of $element resolves to, as sRGB. With $token it is instead
 * what that token resolves to for the same property on a probe beside
 * $element - in the same theme, mode and colour scheme.
 */
export async function resolved(element: Locator, property: string, token?: string): Promise<Rgb> {
    return element.evaluate(
        (node, [prop, customProperty]) => {
            let colour: string;
            if (customProperty === undefined) {
                colour = getComputedStyle(node).getPropertyValue(prop);
            } else {
                const probe = document.createElement('span');
                probe.style.setProperty(prop, `var(${customProperty})`);
                (node.parentElement ?? document.body).append(probe);
                colour = getComputedStyle(probe).getPropertyValue(prop);
                probe.remove();
            }

            const canvas = document.createElement('canvas');
            canvas.width = 1;
            canvas.height = 1;
            const context = canvas.getContext('2d', { willReadFrequently: true });
            if (context === null) {
                throw new Error('no canvas to resolve colours on');
            }

            context.fillStyle = colour;
            context.fillRect(0, 0, 1, 1);
            const [r, g, b] = context.getImageData(0, 0, 1, 1).data;

            return [r ?? 0, g ?? 0, b ?? 0] as [number, number, number];
        },
        [property, token] as const,
    );
}

/** The first background behind $element that is not transparent, as sRGB. */
export async function groundOf(element: Locator): Promise<Rgb> {
    return element.evaluate((node) => {
        let colour = getComputedStyle(document.body).backgroundColor;
        for (let at: Element | null = node.parentElement; at !== null; at = at.parentElement) {
            const own = getComputedStyle(at).backgroundColor;
            if (own !== 'rgba(0, 0, 0, 0)' && own !== 'transparent') {
                colour = own;
                break;
            }
        }

        const canvas = document.createElement('canvas');
        canvas.width = 1;
        canvas.height = 1;
        const context = canvas.getContext('2d', { willReadFrequently: true });
        if (context === null) {
            throw new Error('no canvas to resolve colours on');
        }

        context.fillStyle = colour;
        context.fillRect(0, 0, 1, 1);
        const [r, g, b] = context.getImageData(0, 0, 1, 1).data;

        return [r ?? 0, g ?? 0, b ?? 0] as [number, number, number];
    });
}

/** WCAG's contrast ratio between two sRGB colours. */
export function contrast(one: Rgb, two: Rgb): number {
    const luminance = ([r, g, b]: Rgb): number => {
        const channel = (value: number): number => {
            const c = value / 255;

            return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
    };

    const [lighter, darker] = [luminance(one), luminance(two)].sort((a, b) => b - a) as [number, number];

    return (lighter + 0.05) / (darker + 0.05);
}

/** Two colours the same but for rounding - the painted pixel of a token is not always its exact value. */
export function expectClose(actual: Rgb, expected: Rgb, where: string, tolerance = 3): void {
    const off = actual.map((channel, index) => Math.abs(channel - (expected[index] ?? 0)));
    expect(Math.max(...off), `${where}: painted ${actual.join(', ')}, expected ${expected.join(', ')}`).toBeLessThanOrEqual(
        tolerance,
    );
}
