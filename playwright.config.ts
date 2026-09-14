import { defineConfig, devices, type Project } from '@playwright/test';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { e2ePort } from './tests/e2e/checkout.mjs';

/**
 * The browser-side suite.
 *
 * It exists here because one claim of the design system cannot be made any
 * other way: that switching a theme repaints the page and moves the navigation
 * on the same document, with no request and no rebuild. Only a real engine, with
 * a real cascade and a real layout pass, can be asked where an element ended up.
 * A screenshot would not do either - it would prove that two pictures differ,
 * not which rule made them differ.
 *
 * The full end-to-end skeleton (a CI job, traces as artefacts, the rest of the
 * scenarios) is a separate piece of work; what is here is the smallest
 * configuration those measurements need.
 */

/**
 * Each checkout's own: TRILOBIT_E2E_PORT from the environment, else from the
 * checkout's .env (where .ai/bin/worktree writes one per worktree), else 18100.
 * Why in that order is on e2ePort() in tests/e2e/checkout.mjs.
 */
const root = fileURLToPath(new URL('.', import.meta.url));
const port = e2ePort(root);

/** $value as one word of the shell webServer.command runs in, whatever the checkout's directory is called. */
const shellWord = (value: string): string => `'${value.replaceAll("'", `'\\''`)}'`;

const baseURL = `http://127.0.0.1:${port}`;

/**
 * On a developer's machine the browser that is already installed is used, so
 * that running the suite needs no download and writes nothing outside the
 * checkout. A build server has no such browser and installs Playwright's own,
 * which is what leaving the channel unset selects.
 *
 * Set to nothing, it asks for Playwright's own build outside CI as well - which
 * is what bin/e2e-docker.mjs does, in an image that has no Google Chrome in it.
 * An empty string handed on as a channel would be a name no browser has.
 */
const requestedChannel = process.env.PLAYWRIGHT_CHANNEL;
const channel = requestedChannel === undefined
    ? (process.env.CI ? undefined : 'chrome')
    : (requestedChannel === '' ? undefined : requestedChannel);

/**
 * Chromium is the browser the application is built for and the one every run
 * uses. Firefox is there to be asked for - `PLAYWRIGHT_BROWSERS=firefox`, or
 * `all` for both - because it draws a design differently enough to be worth a
 * look before a change to the frontend is merged, but not on every run: its
 * build is not on a developer's machine, so it is run in the image
 * bin/e2e-docker.mjs uses, and CI does not install it.
 *
 * An unknown name is refused rather than ignored, so that a typo cannot select
 * no browser and have that reported as a green run.
 */
const browsers: Record<string, Project['use']> = {
    chromium: { ...devices['Desktop Chrome'], channel },
    firefox: { ...devices['Desktop Firefox'] },
};

const requestedBrowsers = (process.env.PLAYWRIGHT_BROWSERS ?? 'chromium').trim();
const browserNames = requestedBrowsers === 'all'
    ? Object.keys(browsers)
    : requestedBrowsers.split(',').map((name) => name.trim()).filter((name) => name !== '');

if (browserNames.length === 0) {
    throw new Error('PLAYWRIGHT_BROWSERS names no browser.');
}

for (const name of browserNames) {
    if (!(name in browsers)) {
        throw new Error(`PLAYWRIGHT_BROWSERS names "${name}"; known are ${Object.keys(browsers).join(', ')} and all.`);
    }
}

export default defineConfig({
    testDir: 'tests/e2e',
    fullyParallel: true,
    forbidOnly: process.env.CI !== undefined,
    reporter: process.env.CI !== undefined ? 'github' : 'list',

    // Asks the server whose it is before any spec runs; see reuseExistingServer.
    globalSetup: './tests/e2e/global-setup.ts',

    use: {
        baseURL,
        trace: 'retain-on-failure',
    },

    projects: browserNames.map((name) => ({ name, use: browsers[name] })),

    webServer: {
        // PHP's own server, because the claims here are about what the browser
        // does with the page and not about how it was served.
        //
        // The installation is made before the server starts rather than in a
        // spec, because Playwright asks for the base URL to answer before it
        // runs any of them: an installation with no tenant answers nothing at
        // all - there is no default tenant on purpose - so a suite that
        // installed itself in a hook never got as far as the hook. Both
        // commands may be run again on an installation that already has them:
        // the migration is a no-op and the tenant only gains the hosts it is
        // missing, which is what lets a developer keep one database between
        // runs.
        //
        // tests/e2e/checkout-identity.php is loaded before every request and
        // answers, on one path of its own, which checkout the server is. By its
        // absolute path: PHP's server resolves a relative one against the
        // document root on every request, not against the directory it was
        // started in, and a prepended file it cannot open is a fatal error on
        // every page - served, of all things, with status 200.
        //
        // The limits on what a form may send are the ones README.md, under
        // "Pictures", asks every server of this application for: a file of up
        // to the media library's 20 MiB, and a request with room for it and
        // the fields beside it. PHP's own come lower, and tests/e2e/pictures.spec.ts
        // sends a file just over each of these to see both refusals said.
        // PHP's own errors go to the server's output and not into the page:
        // PHP reports a body it threw away before the application runs, and a
        // warning printed ahead of the page means no header can be sent after
        // it, so nothing the application says about the body reaches the
        // browser. Measured on the built-in server: display_errors=stderr
        // still prints it into the page; display_errors=0 with log_errors=1
        // keeps it out and in the output. The application's own errors are
        // Tracy's either way.
        command: [
            'php bin/trilobit migrations:migrate --no-interaction',
            `php bin/trilobit app:tenant 'Trilobit E2E' 127.0.0.1`,
            [
                'php',
                `-d ${shellWord(`auto_prepend_file=${join(root, 'tests', 'e2e', 'checkout-identity.php')}`)}`,
                '-d upload_max_filesize=20M',
                '-d post_max_size=24M',
                '-d display_errors=0',
                '-d log_errors=1',
                `-S 127.0.0.1:${port} -t www`,
            ].join(' '),
        ].join(' && '),
        url: baseURL,
        // On a developer's machine a server that already answers is taken over
        // - one left by an interrupted run, or started by hand to watch its log
        // - instead of failing on the port. Playwright takes it without asking
        // whose it is, so tests/e2e/global-setup.ts asks, and stops the run
        // unless it is this checkout's: a server another worktree left on the
        // port would otherwise have this checkout's specs run against its code
        // and its database, green. A build server always starts its own.
        reuseExistingServer: process.env.CI === undefined,
        // Stated rather than taken from .env, so that the style guide is on
        // whatever mode the machine running this happens to be in.
        env: { TRILOBIT_ENV: 'dev' },
        stdout: 'pipe',
        stderr: 'pipe',
    },
});
