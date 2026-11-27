import { execFileSync } from 'node:child_process';
import { rmSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

import { chromium, firefox, type FullConfig } from '@playwright/test';

import { assertServerIsThisCheckout } from '../e2e/checkout.mjs';
import { readRequest, SESSION_FILE } from './request';

/**
 * The address the snapshot matrix signs in with. The reserved documentation
 * domain, and an address of its own, so that running the tool never gives a
 * new password to an account a spec of the browser suite - or a person - uses.
 */
const ADDRESS = 'snapshots@example.com';

/** Trilobit\Core\Console\AccountCommand::PASSWORD_LINE. */
const generatedLine = /^ {2}(\S+)$/m;

/**
 * Runs once before any picture is taken.
 *
 * First the question the browser suite asks (tests/e2e/global-setup.ts): is the
 * server on the port this checkout's? Then, when the request asks for the
 * administration, one account made the way the suite makes its own - with
 * `bin/trilobit app:account`, whose generated password is read out of the
 * output into this process and nowhere else - and one sign-in through the
 * real form. What is kept of it is the browser's state (the session cookie),
 * in var/tmp, which bin/e2e-docker.mjs mounts as a tmpfs: it is gone when the
 * container is. The teardown removes it anyway, for a run outside the image.
 *
 * Signing in once rather than once per picture is what lets the pictures be
 * taken in parallel: `app:account` run again gives the account a new password,
 * and the workers would sign each other out.
 */
export default async function globalSetup(config: FullConfig): Promise<() => void> {
    const url = config.webServer?.url;
    if (url === undefined) {
        throw new Error('playwright.config.ts names no webServer.url to ask who the server is.');
    }

    const root = fileURLToPath(new URL('../..', import.meta.url));
    await assertServerIsThisCheckout(url, root);

    rmSync(SESSION_FILE, { force: true });
    const request = readRequest();
    if (!request.signIn) {
        return () => undefined;
    }

    const host = new URL(url).hostname;
    const output = execFileSync('php', ['bin/trilobit', 'app:account', ADDRESS, '--tenant', host, '--name', 'Sam Snapshot'], {
        encoding: 'utf8',
    });
    const generated = generatedLine.exec(output)?.[1];
    if (generated === undefined) {
        // Not the output itself: it is the one place the password would be.
        throw new Error('app:account printed nothing to sign in with; run it by hand to see why.');
    }

    const project = config.projects[0];
    const browserType = (project?.use.browserName ?? project?.use.defaultBrowserType) === 'firefox' ? firefox : chromium;
    const browser = await browserType.launch({ channel: project?.use.channel });
    try {
        const page = await browser.newPage({ baseURL: url });
        await page.goto('/admin/sign-in');
        await page.getByTestId('sign-in-email').fill(ADDRESS);
        await page.getByTestId('sign-in-password').fill(generated);
        await page.getByTestId('sign-in-submit').click();
        await page.waitForURL((address) => !address.pathname.endsWith('/sign-in'), { timeout: 15_000 }).catch(() => {
            throw new Error(`Signing in as ${ADDRESS} did not leave the sign-in page.`);
        });
        await page.context().storageState({ path: SESSION_FILE });
    } finally {
        await browser.close();
    }

    return () => rmSync(SESSION_FILE, { force: true });
}
