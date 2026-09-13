import { type CDPSession, expect, type Locator, type Page, test } from '@playwright/test';

/**
 * c-collapse and c-accordion in a real browser.
 *
 * Both are <details> and <summary>, and the claim they rest on is that the
 * browser does the work: it names the summary, gives it the keys, reports it
 * expanded or not, keeps whatever is folded away out of the order of the Tab
 * key, and - for disclosures that share a name - closes one as another opens.
 * None of that is in our code, so none of it can be asserted of our markup;
 * it is asked of the browser. The accessibility tree is read out of Chrome
 * itself (the DevTools protocol), because Playwright's own role engine is a
 * model of the tree and not the tree.
 *
 * What is ours is measured too: that an address naming a folded item opens it
 * (assets/disclosure.ts), that the height is animated only for somebody who
 * has not asked for reduced motion, and that the colours are the theme's.
 */

const themes = ['atrium', 'ledger'] as const;

const modes = ['light', 'dark'] as const;

interface Accessible {
    role: string;
    name: string;
    expanded: boolean | undefined;
    ignored: boolean;
}

/** What Chrome's accessibility tree holds for the element $selector finds first. */
async function accessible(page: Page, selector: string): Promise<Accessible> {
    const session: CDPSession = await page.context().newCDPSession(page);
    try {
        const { root } = await session.send('DOM.getDocument', { depth: 0 });
        const { nodeId } = await session.send('DOM.querySelector', { nodeId: root.nodeId, selector });
        expect(nodeId, `nothing on the page matches ${selector}`).not.toBe(0);

        const { nodes } = await session.send('Accessibility.getPartialAXTree', { nodeId, fetchRelatives: false });
        const node = nodes[0];
        if (node === undefined) {
            throw new Error(`the accessibility tree has no node for ${selector}`);
        }

        const expanded = node.properties?.find((property) => property.name === 'expanded');

        return {
            role: String(node.role?.value ?? ''),
            name: String(node.name?.value ?? ''),
            expanded: expanded === undefined ? undefined : Boolean(expanded.value.value),
            ignored: node.ignored,
        };
    } finally {
        await session.detach();
    }
}

function specimen(page: Page, variant: string): Locator {
    return page.locator(`[data-styleguide-variant="${variant}"] .sg-specimen__stage`);
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

async function expectFromToken(element: Locator, property: string, name: string, where: string): Promise<void> {
    expect(
        await element.evaluate((node, prop) => getComputedStyle(node).getPropertyValue(prop), property),
        `${property} of ${where} is not ${name}`,
    ).toBe(await token(element, property, name));
}

interface Opening {
    closed: number;
    open: number;
    /** Every height drawn on the way, strictly between the two. */
    between: number[];
}

/**
 * The height $details is drawn at in every frame from the moment its summary
 * is clicked until a second later.
 *
 * Measured as drawn rather than asked of getAnimations(), because Chrome runs
 * the transition of ::details-content and does not list it there (checked in
 * Chrome 151: only the turn of the mark is listed) - a test asking that would
 * report no motion while the item visibly unfolds.
 */
async function heightsWhileOpening(details: Locator): Promise<Opening> {
    return details.evaluate(async (node) => {
        const height = (): number => node.getBoundingClientRect().height;
        const frame = (): Promise<void> => new Promise((resolve) => requestAnimationFrame(() => resolve()));

        const closed = height();
        const seen: number[] = [];
        node.querySelector('summary')?.click();

        const start = performance.now();
        while (performance.now() - start < 1000) {
            await frame();
            seen.push(height());
        }

        const open = seen[seen.length - 1] ?? closed;

        return { closed, open, between: seen.filter((drawn) => drawn > closed + 0.5 && drawn < open - 0.5) };
    });
}

test.describe('with the keyboard, and to a screen reader', () => {
    // Folding away is animated for everybody else, and for that moment what is
    // folding is still on the page; this is about where it ends up.
    test.use({ reducedMotion: 'reduce' });

    test('c-collapse is a named, expandable control that opens and closes by key', async ({ page }) => {
        await page.goto('/_styleguide/components/collapse');

        const details = specimen(page, 'default').locator('details.c-collapse');
        const summary = details.locator('summary');
        const inside = details.locator('.c-collapse__body a');

        let tree = await accessible(page, '[data-styleguide-variant="default"] .c-collapse > summary');
        expect(tree.ignored).toBe(false);
        expect(tree.name).toBe('Where the specimen is kept');
        expect(tree.expanded).toBe(false);

        await summary.focus();
        await page.keyboard.press('Enter');
        await expect(details).toHaveAttribute('open', '');
        tree = await accessible(page, '[data-styleguide-variant="default"] .c-collapse > summary');
        expect(tree.expanded).toBe(true);

        // Tabbed on once it is drawn, the way a person tabs on once they see
        // it: a Tab in the same moment as the Enter can reach the browser
        // before the opened content has been laid out, and skip it.
        await expect(inside).toBeVisible();
        await page.keyboard.press('Tab');
        await expect(inside).toBeFocused();

        await page.keyboard.press('Shift+Tab');
        await expect(summary).toBeFocused();
        await page.keyboard.press('Space');
        await expect(details).not.toHaveAttribute('open');
        expect((await accessible(page, '[data-styleguide-variant="default"] .c-collapse > summary')).expanded).toBe(false);

        // Folded away, it is out of reach: the next stop is whatever follows
        // the collapse, and never the link inside it.
        await page.keyboard.press('Tab');
        await expect(inside).not.toBeFocused();
        await expect(inside).toBeHidden();
    });

    test('a title given a level is a heading in the tree, inside the control that opens it', async ({ page, browserName }) => {
        test.skip(browserName !== 'chromium', 'reads Chrome\'s own accessibility tree over the DevTools protocol, which only Chromium speaks');

        await page.goto('/_styleguide/components/collapse');

        const stage = specimen(page, 'with a heading');
        await expect(stage.getByRole('heading', { level: 2, name: 'Where the moults are kept' })).toBeVisible();

        const heading = await accessible(page, '[data-styleguide-variant="with a heading"] .c-collapse__title');
        expect(heading.role).toBe('heading');
        expect(heading.ignored).toBe(false);

        const summary = await accessible(page, '[data-styleguide-variant="with a heading"] .c-collapse > summary');
        expect(summary.name).toBe('Where the moults are kept');
        expect(summary.expanded).toBe(false);
    });

    test('c-accordion opens one item at a time when its items share a name', async ({ page }) => {
        await page.goto('/_styleguide/components/accordion');

        const items = specimen(page, 'one open at a time').locator('.c-accordion > details');
        await expect(items).toHaveCount(3);

        await items.nth(0).locator('summary').focus();
        await page.keyboard.press('Enter');
        await expect(items.nth(0)).toHaveAttribute('open', '');

        // What the open item holds is in reach, once it is drawn (see the test
        // above); the next stop after it is the title of the next item.
        const insideFirst = items.nth(0).locator('.c-collapse__body a');
        await expect(insideFirst).toBeVisible();
        await page.keyboard.press('Tab');
        await expect(insideFirst).toBeFocused();
        await page.keyboard.press('Tab');
        await expect(items.nth(1).locator('summary')).toBeFocused();
        await page.keyboard.press('Enter');

        await expect(items.nth(1)).toHaveAttribute('open', '');
        await expect(items.nth(0)).not.toHaveAttribute('open');
        await expect(items.nth(2)).not.toHaveAttribute('open');
    });

    test('c-accordion keeps every item open that was opened when its items share no name', async ({ page }) => {
        await page.goto('/_styleguide/components/accordion');

        const items = specimen(page, 'any number open').locator('.c-accordion > details');
        await expect(items).toHaveCount(3);

        for (const index of [0, 1, 2]) {
            await items.nth(index).locator('summary').click();
        }

        for (const index of [0, 1, 2]) {
            await expect(items.nth(index)).toHaveAttribute('open', '');
        }
    });
});

test.describe('the address of an item', () => {
    test.use({ reducedMotion: 'reduce' });

    test('opens the item it names on arrival, and brings it into view', async ({ page }) => {
        await page.goto('/_styleguide/components/accordion#sg-accordion-growth');

        const items = specimen(page, 'one open at a time').locator('.c-accordion > details');
        const named = page.locator('#sg-accordion-growth');

        await expect(named).toHaveAttribute('open', '');
        await expect(named.locator('summary')).toBeInViewport();
        await expect(items.nth(0)).not.toHaveAttribute('open');
    });

    test('opens the item a link on the page names, closing the other of its group, and again after it was closed', async ({ page }) => {
        await page.goto('/_styleguide/components/accordion');

        const first = page.locator('#sg-accordion-shield');
        const named = page.locator('#sg-accordion-growth');
        const link = specimen(page, 'one open at a time').getByRole('link', { name: 'Straight to how it grew' });

        await first.locator('summary').click();
        await expect(first).toHaveAttribute('open', '');

        await link.click();
        await expect(page).toHaveURL(/#sg-accordion-growth$/);
        await expect(named).toHaveAttribute('open', '');
        await expect(first).not.toHaveAttribute('open');

        // The address does not change on the second click, so the browser
        // announces no change of it; the item has to open all the same.
        await named.locator('summary').click();
        await expect(named).not.toHaveAttribute('open');
        await link.click();
        await expect(named).toHaveAttribute('open', '');
    });
});

test.describe('the motion of opening', () => {
    test('the height unfolds over a moment for somebody who has not asked for less motion', async ({ page }) => {
        await page.emulateMedia({ reducedMotion: 'no-preference' });
        await page.goto('/_styleguide/components/collapse');

        const opening = await heightsWhileOpening(specimen(page, 'default').locator('details.c-collapse'));

        expect(opening.open, 'the collapse did not open').toBeGreaterThan(opening.closed);
        expect(opening.between.length, 'no height was drawn between closed and open').toBeGreaterThan(0);
    });

    test('and is open at once for somebody who has', async ({ page }) => {
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await page.goto('/_styleguide/components/collapse');

        const opening = await heightsWhileOpening(specimen(page, 'default').locator('details.c-collapse'));

        expect(opening.open, 'the collapse did not open').toBeGreaterThan(opening.closed);
        expect(opening.between, 'a height was drawn between closed and open').toEqual([]);
    });
});

test('the colours are the theme\'s in both themes and both modes', async ({ page }) => {
    await page.goto('/_styleguide/components/accordion');

    const group = specimen(page, 'one open at a time').locator('.c-accordion');
    const first = group.locator('details').first();
    await first.locator('summary').click();
    await expect(first).toHaveAttribute('open', '');

    const title = first.locator('.c-collapse__title');
    const closedTitle = group.locator('details').nth(1).locator('.c-collapse__title');

    const borders: string[] = [];
    for (const theme of themes) {
        for (const mode of modes) {
            await drawIn(page, theme, mode);
            const where = `${theme}, ${mode}`;

            await expectFromToken(group, 'border-top-color', '--color-line', `the frame of the group in ${where}`);
            await expectFromToken(group, 'background-color', '--color-surface', `the group in ${where}`);
            await expectFromToken(closedTitle, 'color', '--color-ink', `a closed item's title in ${where}`);
            await expectFromToken(title, 'color', '--color-accent', `the open item's title in ${where}`);

            borders.push(await group.evaluate((node) => getComputedStyle(node).borderTopColor));
        }
    }

    expect(new Set(borders).size, 'the frame is one colour whatever the theme and the mode').toBeGreaterThan(1);
});
