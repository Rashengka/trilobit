import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

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
     * How many pixels of the window's width the page did not get: the room a
     * classic scrollbar takes on a page that scrolls. Null when no picture was
     * taken. tests/snapshots/tool.test.mjs reads it.
     */
    readonly scrollbar: number | null;
}

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
                    report({ path, theme, mode, width, browser, file, error, scrollbar: null });
                    throw new Error(error);
                };

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
                await page.screenshot({
                    path: join(request.out, file),
                    fullPage: true,
                    animations: 'disabled',
                    caret: 'hide',
                    // Tracy's bar: the server runs in dev, as the browser suite's does,
                    // and the bar is a tool of the developer's, not part of the design.
                    style: '#tracy-debug { display: none !important; }',
                });

                const scrollbar = await page.evaluate(() => window.innerWidth - document.documentElement.clientWidth);
                report({ path, theme, mode, width, browser, file, error: null, scrollbar });
            });
        }
    });
}
