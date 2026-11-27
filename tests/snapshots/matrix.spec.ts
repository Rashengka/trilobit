import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { test } from '@playwright/test';

import { clickRefusal, combinations, drawnRefusal, fileName, HEIGHTS, refusal } from './matrix.mjs';
import { readRequest, SESSION_FILE } from './request';

/**
 * One picture per path, theme, mode and width, in whichever browsers the run
 * was given (bin/snapshots.mjs, which also builds the index page).
 *
 * The theme and the mode are chosen the way a device keeps them: one cookie
 * each, `trilobit-theme` and `trilobit-theme-mode`, which the server draws onto
 * <html> as data-theme and data-theme-mode (Trilobit\Core\Preference\Preference).
 * Set as cookies rather than through the switch, because the switch also writes
 * the choice into a signed-in account. The attributes are read back before the
 * picture is taken, so a page drawn in anything other than what was asked for -
 * a theme the build no longer has, which the server drops - is refused rather
 * than filed under the wrong name.
 *
 * Every picture is reported to a file of its own under results/, whether it was
 * taken or not, and the index is built out of those files. A page that answered
 * anything but 200, or redirected - to the sign-in page above all - fails its
 * test and is reported as the reason, never as a picture.
 */

const request = readRequest();

test.describe.configure({ mode: 'parallel' });

/**
 * The classic scrollbar of Linux, which is what a build server - and most
 * visitors on Linux - see and what a layout has to leave room for. Headless,
 * neither browser draws one: Playwright starts Chrome with --hide-scrollbars,
 * and headless Firefox is given a stylesheet of the browser's own rank that
 * sets scrollbar-width: none. So, as in tests/e2e/layers.spec.ts, Chrome is
 * started without that argument, and Firefox with a window - in the display
 * bin/e2e-docker.mjs runs everything under - told to draw the classic
 * scrollbar, fifteen pixels wide as Chrome's is, rather than one laid over
 * the page.
 */
test.use({
    launchOptions: {
        ignoreDefaultArgs: ['--hide-scrollbars'],
        firefoxUserPrefs: {
            'widget.gtk.overlay-scrollbars.enabled': false,
            'widget.non-native-theme.scrollbar.size.override': 15,
        },
    },
    headless: [async ({ headless, browserName }, use) => use(browserName === 'firefox' ? false : headless), { scope: 'worker' }],
});

interface Result {
    readonly path: string;
    readonly theme: string;
    readonly mode: string;
    readonly width: number;
    readonly browser: string;
    readonly file: string;
    readonly error: string | null;
    /**
     * The size the page was laid out at, measured in the page just before the
     * picture: the width the window lends the document beside a classic
     * scrollbar (clientWidth), and the height of the whole document. A picture
     * of what a visitor sees is exactly this size. Null when no picture was
     * taken. tests/snapshots/tool.test.mjs compares it with the PNG.
     */
    readonly layout: { readonly width: number; readonly height: number } | null;
}

/**
 * Pages of the tool's own test, under a prefix the application has no route
 * for, served out of tests/snapshots/fixtures/ rather than by the server:
 * tests/snapshots/tool.test.mjs pictures one whose every edge is a colour of
 * its own, to see that nothing of the layout is lost on the way to the PNG.
 */
const FIXTURES = '/_snapshot-fixtures/';
const fixtureDirectory = fileURLToPath(new URL('./fixtures/', import.meta.url));

function report(result: Result): void {
    const directory = join(request.out, 'results');
    mkdirSync(directory, { recursive: true });
    writeFileSync(join(directory, `${result.file}.json`), `${JSON.stringify(result, null, 2)}\n`);
}

for (const combination of combinations()) {
    const { theme, mode, width } = combination;

    test.describe(`${theme} ${mode} ${width}px`, () => {
        test.use({
            viewport: { width, height: HEIGHTS[width as keyof typeof HEIGHTS] },
            colorScheme: mode as 'light' | 'dark',
            ...(request.signIn ? { storageState: SESSION_FILE } : {}),
        });

        for (const path of request.paths) {
            test(path, async ({ page, context, baseURL }, info) => {
                const browser = info.project.name;
                const file = fileName(path, combination, browser);
                const fail = (error: string): never => {
                    report({ path, theme, mode, width, browser, file, error, layout: null });
                    throw new Error(error);
                };

                await context.route(`**${FIXTURES}*`, async (route) => {
                    const name = new URL(route.request().url()).pathname.slice(FIXTURES.length);
                    if (!/^[a-z0-9-]+\.html$/.test(name)) {
                        return route.fulfill({ status: 404, body: '' });
                    }

                    return route.fulfill({
                        status: 200,
                        contentType: 'text/html; charset=utf-8',
                        body: readFileSync(join(fixtureDirectory, name), 'utf8'),
                    });
                });

                await context.addCookies([
                    { name: 'trilobit-theme', value: theme, url: baseURL ?? '' },
                    { name: 'trilobit-theme-mode', value: mode, url: baseURL ?? '' },
                ]);

                const response = await page.goto(path).catch((error: Error) => fail(`${path} could not be opened: ${error.message}`));
                const arrived = new URL(page.url());
                const problem = refusal(path, {
                    status: response?.status() ?? null,
                    redirected: response?.request().redirectedFrom() != null,
                    arrivedAt: arrived.pathname,
                    origin: arrived.origin,
                }, new URL(baseURL ?? '').origin);
                if (problem !== null) {
                    fail(problem);
                }

                const drawn = await page.evaluate(() => ({
                    theme: document.documentElement.getAttribute('data-theme'),
                    mode: document.documentElement.getAttribute('data-theme-mode'),
                }));
                const misdrawn = drawnRefusal(path, { theme, mode }, drawn);
                if (misdrawn !== null) {
                    fail(misdrawn);
                }

                if (request.click !== null) {
                    const target = page.locator(request.click).first();
                    const before = page.url();
                    await target.click({ timeout: 5_000 }).catch(() => fail(`${path} has nothing to click at ${request.click}.`));
                    // A click that starts a navigation has not always changed the
                    // address by the time it returns; the load it started has to
                    // settle before the address is worth comparing.
                    await page.waitForLoadState('load');
                    const wandered = clickRefusal(path, request.click, before, page.url());
                    if (wandered !== null) {
                        fail(wandered);
                    }
                }

                await page.evaluate(() => document.fonts.ready.then(() => undefined));
                const layout = await page.evaluate(() => ({
                    width: document.documentElement.clientWidth,
                    height: document.documentElement.scrollHeight,
                    scrolls: document.documentElement.clientWidth < window.innerWidth,
                }));
                await page.screenshot({
                    path: join(request.out, file),
                    fullPage: true,
                    animations: 'disabled',
                    caret: 'hide',
                    // Tracy's bar: the server runs in dev, as the browser suite's does,
                    // and the bar is a tool of the developer's, not part of the design.
                    //
                    // And the room of the scrollbar kept. For a whole page, Chromium
                    // captures beyond the window with every scrollbar hidden: the page
                    // is laid out again fifteen pixels wider - and shorter, where
                    // anything wraps - and the picture is cut to the width and height
                    // measured beside the scrollbar. Its right edge was lost and an
                    // empty band was left under the footer. With the gutter held
                    // (scrollbar-gutter: stable) the room stays while the scrollbar is
                    // hidden, the page keeps the layout the visitor has, and the
                    // picture is all of it. Telling the root to scroll is not enough:
                    // a hidden scrollbar takes no room whatever the overflow. Only
                    // where the window showed a scrollbar to begin with - a page that
                    // fits, or one a modal dialog holds still, has none for a visitor
                    // either. Firefox pictures a whole page beside the scrollbar it
                    // drew, and is told the same to no effect, so both browsers give
                    // the same geometry (tests/snapshots/tool.test.mjs measures it).
                    style: [
                        '#tracy-debug { display: none !important; }',
                        ...(layout.scrolls ? [':root { scrollbar-gutter: stable !important; }'] : []),
                    ].join('\n'),
                });

                report({ path, theme, mode, width, browser, file, error: null, layout: { width: layout.width, height: layout.height } });
            });
        }
    });
}
