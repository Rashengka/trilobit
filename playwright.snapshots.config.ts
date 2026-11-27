import { defineConfig } from '@playwright/test';

import base from './playwright.config';

/**
 * The snapshot matrix (bin/snapshots.mjs): pictures of named pages in every
 * theme, mode, width and browser, for somebody to look at rather than for a
 * test to compare.
 *
 * It is the browser suite's configuration with its own directory of specs, so
 * that it runs on the same server, the same browsers and the same image - and
 * so that `npm run e2e` never picks these specs up, because tests/e2e is the
 * only directory that configuration reads.
 *
 * The global setup is its own: it asks the server whose it is, as the suite's
 * does, and then signs in once when the request asks for the administration.
 */
export default defineConfig({
    ...base,
    testDir: 'tests/snapshots',
    testMatch: '*.spec.ts',
    globalSetup: './tests/snapshots/global-setup.ts',
    // A picture is a picture of what the page did, and a retry would only hide
    // that it did it differently the first time.
    retries: 0,
    use: {
        ...base.use,
        trace: 'off',
    },
    // The server's log of every request is the browser suite's to read when a
    // spec fails; here a failure says itself, and the log would bury the
    // summary of what was pictured under hundreds of lines.
    webServer: base.webServer === undefined || Array.isArray(base.webServer)
        ? base.webServer
        : { ...base.webServer, stdout: 'ignore', stderr: 'ignore' },
});
